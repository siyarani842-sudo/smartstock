<?php
/* =========================================================
   INVENTORY SYNC ENGINE
   ---------------------------------------------------------
   Every stock change is written to `stock_movements` (ledger)
   and the running total is kept in `inventory`.

   Orders change stock automatically:
     PURCHASE order  -> stock IN  when status = Delivered
     SALE order      -> stock OUT when status = In Transit or Delivered
     Pending sale    -> counted as "reserved" stock
     Cancelled       -> no stock effect

   Whenever an order is created, edited, has its status changed
   or is deleted, sync_order() posts, updates or reverses its
   movements (only the difference hits inventory), so
   inventory always matches the orders.
   sync_all_inventory() re-checks every order and rebuilds the
   inventory table from the ledger (the "Sync Inventory" button).
========================================================= */


/** Does this order (in its current status) affect stock? */
function order_should_apply(array $order): bool
{
    if ($order['order_type'] === 'purchase') {
        return $order['status'] === 'Delivered';
    }
    return in_array($order['status'], ['In Transit', 'Delivered'], true);
}

/** Date used for the stock movement of an order */
function order_effective_date(array $order): string
{
    if ($order['order_type'] === 'purchase') {
        $date = $order['delivered_date'] ?: date('Y-m-d');
    } else {
        $date = $order['delivered_date'] ?: $order['order_date'];
    }
    return $date . ' 12:00:00';
}

/** Current quantity of a product in a warehouse */
function inventory_qty(int $productId, int $warehouseId): int
{
    return (int) db_value(
        "SELECT quantity FROM inventory WHERE product_id = ? AND warehouse_id = ?",
        [$productId, $warehouseId]
    );
}

/** Units reserved by pending sale orders */
function reserved_qty(int $productId, ?int $warehouseId = null): int
{
    $sql = "SELECT COALESCE(SUM(oi.quantity), 0)
              FROM order_items oi
              JOIN orders o ON o.id = oi.order_id
             WHERE o.order_type = 'sale' AND o.status = 'Pending' AND oi.product_id = ?";
    $params = [$productId];

    if ($warehouseId) {
        $sql .= " AND o.warehouse_id = ?";
        $params[] = $warehouseId;
    }

    return (int) db_value($sql, $params);
}

/**
 * Write a stock movement and update the inventory total.
 * $qty is signed (+ in, - out).
 */
function add_stock_movement(
    int $productId,
    int $warehouseId,
    int $qty,
    string $type,
    string $note = '',
    ?int $orderId = null,
    ?string $date = null
): void {
    if ($qty === 0) {
        return;
    }

    db_query(
        "INSERT INTO stock_movements (product_id, warehouse_id, order_id, movement_type, quantity, note, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        [$productId, $warehouseId, $orderId, $type, $qty, $note, $date ?: date('Y-m-d H:i:s')]
    );

    db_query(
        "INSERT INTO inventory (product_id, warehouse_id, quantity, last_synced_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), last_synced_at = NOW()",
        [$productId, $warehouseId, $qty]
    );
}

/**
 * Stock movements an order SHOULD have right now, keyed "productId|warehouseId".
 * Empty when the order's status does not affect stock.
 */
function order_desired_movements(array $order): array
{
    if (!order_should_apply($order)) {
        return [];
    }

    $isSale = $order['order_type'] === 'sale';
    $rows   = db_all(
        "SELECT oi.product_id, SUM(oi.quantity) AS qty
           FROM order_items oi
          WHERE oi.order_id = ?
          GROUP BY oi.product_id",
        [(int) $order['id']]
    );

    $moves = [];
    foreach ($rows as $r) {
        $key = (int) $r['product_id'] . '|' . (int) $order['warehouse_id'];
        $moves[$key] = $isSale ? -(int) $r['qty'] : (int) $r['qty'];
    }
    return $moves;
}

/** Stock movements an order currently HAS in the ledger, keyed "productId|warehouseId" */
function order_current_movements(int $orderId): array
{
    $rows = db_all(
        "SELECT product_id, warehouse_id, SUM(quantity) AS qty, MIN(created_at) AS posted_at
           FROM stock_movements
          WHERE order_id = ?
          GROUP BY product_id, warehouse_id",
        [$orderId]
    );

    $moves = [];
    foreach ($rows as $r) {
        $moves[(int) $r['product_id'] . '|' . (int) $r['warehouse_id']] = [
            'qty'  => (int) $r['qty'],
            'date' => $r['posted_at'],
        ];
    }
    return $moves;
}

/**
 * Bring ONE order's stock effect in line with its current
 * status, items and warehouse. Only the difference is applied
 * to inventory, so editing an old delivered order never
 * double-counts stock.
 *
 * Throws RuntimeException (before changing anything) when the
 * change would make any warehouse stock negative.
 *
 * Returns 'applied', 'reverted', 'updated' or 'unchanged'.
 * Call it inside a transaction (db_begin / db_commit).
 */
function sync_order(int $orderId): string
{
    $order = db_one("SELECT * FROM orders WHERE id = ?", [$orderId]);
    if (!$order) {
        return 'unchanged';
    }

    $desired = order_desired_movements($order);
    $current = order_current_movements($orderId);
    $date    = order_effective_date($order);

    /* Nothing to do? (same quantities and same posting date) */
    $same = count($desired) === count($current);
    if ($same) {
        foreach ($desired as $key => $qty) {
            if (!isset($current[$key]) || $current[$key]['qty'] !== $qty || $current[$key]['date'] !== $date) {
                $same = false;
                break;
            }
        }
    }

    if ($same) {
        $flag = $desired ? 1 : 0;
        if ((int) $order['stock_applied'] !== $flag) {
            db_query("UPDATE orders SET stock_applied = ? WHERE id = ?", [$flag, $orderId]);
        }
        return 'unchanged';
    }

    /* Net change per product / warehouse */
    $delta = [];
    foreach ($desired as $key => $qty) {
        $delta[$key] = $qty - ($current[$key]['qty'] ?? 0);
    }
    foreach ($current as $key => $c) {
        if (!isset($desired[$key])) {
            $delta[$key] = -$c['qty'];
        }
    }

    /* Validate: no warehouse may go below zero */
    foreach ($delta as $key => $change) {
        if ($change >= 0) {
            continue;
        }
        [$productId, $warehouseId] = array_map('intval', explode('|', $key));
        $available = inventory_qty($productId, $warehouseId);

        if ($available + $change < 0) {
            $names = db_one(
                "SELECT p.name AS product, w.name AS warehouse
                   FROM products p, warehouses w WHERE p.id = ? AND w.id = ?",
                [$productId, $warehouseId]
            );
            $product   = $names['product'] ?? 'product';
            $warehouse = $names['warehouse'] ?? 'warehouse';

            if ($order['order_type'] === 'sale') {
                throw new RuntimeException(
                    "Not enough stock for \"{$product}\" in {$warehouse}: available {$available}, "
                    . "this order needs " . (-$change) . "."
                );
            }
            throw new RuntimeException(
                "Cannot change {$order['order_no']}: stock of \"{$product}\" in {$warehouse} would become "
                . ($available + $change) . " because those units were already sold."
            );
        }
    }

    /* Rewrite the order's ledger rows */
    db_query("DELETE FROM stock_movements WHERE order_id = ?", [$orderId]);

    $isSale = $order['order_type'] === 'sale';
    foreach ($desired as $key => $qty) {
        [$productId, $warehouseId] = array_map('intval', explode('|', $key));
        db_query(
            "INSERT INTO stock_movements (product_id, warehouse_id, order_id, movement_type, quantity, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$productId, $warehouseId, $orderId, $isSale ? 'sale' : 'purchase', $qty,
             ($isSale ? 'Sold via ' : 'Received via ') . $order['order_no'], $date]
        );
    }

    /* Apply only the difference to inventory */
    foreach ($delta as $key => $change) {
        if ($change === 0) {
            continue;
        }
        [$productId, $warehouseId] = array_map('intval', explode('|', $key));
        db_query(
            "INSERT INTO inventory (product_id, warehouse_id, quantity, last_synced_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), last_synced_at = NOW()",
            [$productId, $warehouseId, $change]
        );
    }

    db_query("UPDATE orders SET stock_applied = ? WHERE id = ?", [$desired ? 1 : 0, $orderId]);

    if (!$current) {
        return 'applied';
    }
    return $desired ? 'updated' : 'reverted';
}

/** Remove an order's stock effect (used before deleting an order) */
function release_order_stock(int $orderId): void
{
    db_query("UPDATE orders SET status = 'Cancelled' WHERE id = ?", [$orderId]);
    sync_order($orderId);
}

/** Rebuild inventory.quantity from the stock_movements ledger */
function recalculate_inventory(): int
{
    // Update rows that exist
    db_query(
        "UPDATE inventory i
           LEFT JOIN (SELECT product_id, warehouse_id, SUM(quantity) AS qty
                        FROM stock_movements GROUP BY product_id, warehouse_id) s
                  ON s.product_id = i.product_id AND s.warehouse_id = i.warehouse_id
            SET i.quantity = COALESCE(s.qty, 0), i.last_synced_at = NOW()"
    );

    // Insert rows that are missing
    db_query(
        "INSERT INTO inventory (product_id, warehouse_id, quantity, last_synced_at)
         SELECT s.product_id, s.warehouse_id, s.qty, NOW()
           FROM (SELECT product_id, warehouse_id, SUM(quantity) AS qty
                   FROM stock_movements GROUP BY product_id, warehouse_id) s
           LEFT JOIN inventory i ON i.product_id = s.product_id AND i.warehouse_id = s.warehouse_id
          WHERE i.id IS NULL AND s.qty <> 0"
    );

    return (int) db_value("SELECT COUNT(*) FROM inventory");
}

/**
 * Full synchronisation: check every order, post/reverse
 * movements where needed, then rebuild inventory totals.
 */
function sync_all_inventory(): array
{
    $result = ['applied' => 0, 'reverted' => 0, 'updated' => 0, 'checked' => 0, 'errors' => []];

    // Start from a clean total so stock checks use correct numbers
    recalculate_inventory();

    // Purchases first (oldest first) so stock exists before sales are applied
    $orders = db_all(
        "SELECT id, order_no FROM orders
          ORDER BY (order_type = 'sale'), COALESCE(delivered_date, order_date), id"
    );

    foreach ($orders as $o) {
        $result['checked']++;
        try {
            db_begin();
            $action = sync_order((int) $o['id']);
            db_commit();
            if ($action === 'applied') {
                $result['applied']++;
            } elseif ($action === 'reverted') {
                $result['reverted']++;
            } elseif ($action === 'updated') {
                $result['updated']++;
            }
        } catch (RuntimeException $e) {
            db_rollback();
            $result['errors'][] = $e->getMessage();
        }
    }

    recalculate_inventory();
    save_setting('last_inventory_sync', date('Y-m-d H:i:s'));

    return $result;
}

/** Number of active products at or below reorder level (topbar bell) */
function count_stock_alerts(): int
{
    static $count = null;

    if ($count !== null) {
        return $count;
    }

    if (setting('low_stock_alerts', '1') !== '1') {
        return $count = 0;
    }

    try {
        $count = (int) db_value(
            "SELECT COUNT(*) FROM (
                SELECT p.id
                  FROM products p
                  LEFT JOIN inventory i ON i.product_id = p.id
                 WHERE p.status = 'active'
                 GROUP BY p.id, p.reorder_level
                HAVING COALESCE(SUM(i.quantity), 0) <= p.reorder_level
             ) t"
        );
    } catch (mysqli_sql_exception $e) {
        $count = 0;
    }

    return $count;
}

/** Next order number e.g. PO-10483 / SO-20015 */
function next_order_no(string $type): string
{
    $prefix = $type === 'sale' ? 'SO-' : 'PO-';
    $start  = $type === 'sale' ? 20000 : 10000;

    $max = (int) db_value(
        "SELECT MAX(CAST(SUBSTRING(order_no, 4) AS UNSIGNED)) FROM orders WHERE order_no LIKE ?",
        [$prefix . '%'],
        0
    );

    return $prefix . (max($start, $max) + 1);
}

/** Average units sold per day for a product over the last N days */
function avg_daily_sales(int $productId, int $days = 30): float
{
    $sold = (int) db_value(
        "SELECT COALESCE(-SUM(quantity), 0) FROM stock_movements
          WHERE product_id = ? AND movement_type = 'sale'
            AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)",
        [$productId, $days]
    );
    return $sold / max(1, $days);
}
