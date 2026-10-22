<?php
/* =========================================================
   INVENTORY - STOCK STATUS
   One row per product per warehouse. Quantities come from the
   stock ledger and are synced automatically from orders.
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

$pageTitle    = 'Inventory';
$pageSubtitle = 'Monitor stock levels and warehouse availability';
$activePage   = 'inventory';

/* ---- Automatic sync (Settings → Automatic Inventory Sync) ----
   Re-checks every order at most once every 10 minutes. */
if (setting('auto_sync', '1') === '1') {
    $last = setting('last_inventory_sync');
    if (!$last || strtotime($last) < time() - 600) {
        $sync = sync_all_inventory();
        if ($sync['errors']) {
            flash(count($sync['errors']) . ' order(s) could not be synced: ' . $sync['errors'][0], 'warning');
        }
    }
}

$search    = get('search');
$warehouse = get('warehouse');
$status    = get('status');

$half = "GREATEST(1, FLOOR(t.reorder_level / 2))";
$statusFilters = [
    'healthy'   => "t.quantity > t.reorder_level",
    'low'       => "t.quantity > $half AND t.quantity <= t.reorder_level",
    'critical'  => "t.quantity > 0 AND t.quantity <= $half",
    'out'       => "t.quantity <= 0",
    'attention' => "t.quantity <= t.reorder_level",
];

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = "(t.name LIKE ? OR t.sku LIKE ?)";
    array_push($params, "%$search%", "%$search%");
}
if (ctype_digit($warehouse)) {
    $where[]  = "t.warehouse_id = ?";
    $params[] = (int) $warehouse;
}
if (isset($statusFilters[$status])) {
    $where[] = $statusFilters[$status];
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$baseSql = "
    SELECT i.id AS inv_id, p.id AS product_id, p.name, p.sku, p.image, p.unit, p.reorder_level, p.cost_price,
           w.id AS warehouse_id, w.name AS warehouse_name, i.quantity, i.last_synced_at,
           COALESCE(r.qty, 0) AS reserved, COALESCE(sd.sold, 0) AS sold30
      FROM inventory i
      JOIN products p ON p.id = i.product_id AND p.status = 'active'
      JOIN warehouses w ON w.id = i.warehouse_id
      LEFT JOIN (SELECT oi.product_id, o.warehouse_id, SUM(oi.quantity) AS qty
                   FROM order_items oi JOIN orders o ON o.id = oi.order_id
                  WHERE o.order_type = 'sale' AND o.status = 'Pending'
                  GROUP BY oi.product_id, o.warehouse_id) r
             ON r.product_id = i.product_id AND r.warehouse_id = i.warehouse_id
      LEFT JOIN (SELECT product_id, warehouse_id, -SUM(quantity) AS sold
                   FROM stock_movements
                  WHERE movement_type = 'sale' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                  GROUP BY product_id, warehouse_id) sd
             ON sd.product_id = i.product_id AND sd.warehouse_id = i.warehouse_id
    UNION ALL
    SELECT NULL, p.id, p.name, p.sku, p.image, p.unit, p.reorder_level, p.cost_price,
           NULL, NULL, 0, NULL, 0, 0
      FROM products p
     WHERE p.status = 'active'
       AND NOT EXISTS (SELECT 1 FROM inventory i WHERE i.product_id = p.id)";

$total = (int) db_value("SELECT COUNT(*) FROM ($baseSql) t $whereSql", $params);
$pg    = paginate($total, 12);

$rows = db_all(
    "SELECT * FROM ($baseSql) t $whereSql
      ORDER BY (t.quantity <= t.reorder_level) DESC, t.quantity / GREATEST(t.reorder_level, 1), t.name
      LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
    $params
);

/* ---- KPI cards (product level: total stock across warehouses) ---- */
$kpi = db_one(
    "SELECT COALESCE(SUM(t.stock), 0) AS units,
            COALESCE(SUM(t.stock * t.cost_price), 0) AS value,
            SUM(t.stock > GREATEST(1, FLOOR(t.reorder_level / 2)) AND t.stock <= t.reorder_level) AS low,
            SUM(t.stock > 0 AND t.stock <= GREATEST(1, FLOOR(t.reorder_level / 2))) AS critical,
            SUM(t.stock <= 0) AS out_of_stock
       FROM (SELECT p.id, p.reorder_level, p.cost_price, COALESCE(SUM(i.quantity), 0) AS stock
               FROM products p LEFT JOIN inventory i ON i.product_id = p.id
              WHERE p.status = 'active'
              GROUP BY p.id, p.reorder_level, p.cost_price) t"
);
$reservedAll = (int) db_value(
    "SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
      WHERE o.order_type = 'sale' AND o.status = 'Pending'"
);
$lastMonthUnits = (int) db_value(
    "SELECT COALESCE(SUM(quantity), 0) FROM stock_movements WHERE created_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
);
$unitChange = $lastMonthUnits > 0 ? round(($kpi['units'] - $lastMonthUnits) / $lastMonthUnits * 100, 1) : null;

$attention = (int) $kpi['low'] + (int) $kpi['critical'] + (int) $kpi['out_of_stock'];
$urgent    = (int) $kpi['critical'] + (int) $kpi['out_of_stock'];

$warehouses = db_all("SELECT id, name FROM warehouses ORDER BY name");
$lastSync   = setting('last_inventory_sync');

include ROOT_PATH . 'includes/header.php';
?>

<div class="section-header">
    <div>
        <span class="section-label">WAREHOUSE</span>
        <h2>Inventory Management</h2>
        <p>
            Stock is synced automatically from orders.
            <?= $lastSync ? 'Last full sync: ' . e(fmt_date($lastSync, 'M j, Y H:i')) : 'Not synced yet.' ?>
        </p>
    </div>
    <div class="header-actions">
        <a href="movements.php" class="btn btn-secondary"><i class="fa-solid fa-list"></i> Stock Ledger</a>
        <a href="adjust.php" class="btn btn-secondary"><i class="fa-solid fa-plus"></i> Add / Adjust Stock</a>
        <form method="post" action="sync.php" class="inline-form" data-loading="Syncing...">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary" id="syncInventoryBtn">
                <i class="fa-solid fa-arrows-rotate"></i> Sync Inventory
            </button>
        </form>
    </div>
</div>

<?php if ($attention > 0): ?>
    <div class="alert-banner">
        <div class="alert-symbol"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div class="alert-content">
            <strong><?= num($attention) ?> product<?= $attention > 1 ? 's' : '' ?> need attention</strong>
            <p><?= num($urgent) ?> product<?= $urgent !== 1 ? 's are' : ' is' ?> critically low or out of stock and should be reordered immediately.</p>
        </div>
        <a href="../predictions/index.php" class="alert-action">
            View AI Recommendations <i class="fa-solid fa-arrow-right"></i>
        </a>
    </div>
<?php endif; ?>

<div class="inventory-stat-grid">
    <div class="card inventory-stat">
        <span>Total Stock Units</span>
        <strong><?= num($kpi['units']) ?></strong>
        <?php if ($unitChange !== null): ?>
            <small class="<?= $unitChange >= 0 ? 'green-text' : 'red-text' ?>">
                <i class="fa-solid fa-arrow-<?= $unitChange >= 0 ? 'up' : 'down' ?>"></i> <?= abs($unitChange) ?>% vs 30 days ago
            </small>
        <?php else: ?>
            <small>Value <?= money_short($kpi['value']) ?></small>
        <?php endif; ?>
    </div>
    <div class="card inventory-stat">
        <span>Reserved Units</span>
        <strong><?= num($reservedAll) ?></strong>
        <small><?= $kpi['units'] > 0 ? round($reservedAll / $kpi['units'] * 100, 1) : 0 ?>% of inventory (pending sales)</small>
    </div>
    <div class="card inventory-stat">
        <span>Low Stock</span>
        <strong class="orange-text"><?= num($kpi['low'] + $kpi['critical']) ?></strong>
        <small><a class="link-btn" href="?status=attention">Needs attention</a></small>
    </div>
    <div class="card inventory-stat">
        <span>Out of Stock</span>
        <strong class="red-text"><?= num($kpi['out_of_stock']) ?></strong>
        <small><a class="link-btn" href="?status=out">Immediate action</a></small>
    </div>
</div>

<div class="card table-card">

    <div class="card-header">
        <div>
            <h3>Inventory Status</h3>
            <p>Current stock levels by product and warehouse</p>
        </div>
    </div>

    <form class="table-toolbar" method="get">
        <div class="table-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search product or SKU...">
        </div>
        <select name="warehouse" data-autosubmit>
            <option value="">All Warehouses</option>
            <?php foreach ($warehouses as $w): ?>
                <option value="<?= (int) $w['id'] ?>" <?= selected($warehouse, $w['id']) ?>><?= e($w['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" data-autosubmit>
            <option value="">All Stock Levels</option>
            <option value="attention" <?= selected($status, 'attention') ?>>Needs Attention</option>
            <option value="healthy" <?= selected($status, 'healthy') ?>>Healthy</option>
            <option value="low" <?= selected($status, 'low') ?>>Low Stock</option>
            <option value="critical" <?= selected($status, 'critical') ?>>Critical</option>
            <option value="out" <?= selected($status, 'out') ?>>Out of Stock</option>
        </select>
        <button class="btn btn-secondary" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
        <?php if ($search . $warehouse . $status !== ''): ?>
            <a href="index.php" class="link-btn">Clear</a>
        <?php endif; ?>
    </form>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Warehouse</th>
                    <th>Current Stock</th>
                    <th>Reserved</th>
                    <th>Available</th>
                    <th>Reorder Level</th>
                    <th>Days Remaining</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="9" class="empty-state"><i class="fa-solid fa-warehouse"></i>No inventory records found.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($rows as $r):
                    $qty       = (int) $r['quantity'];
                    $available = max(0, $qty - (int) $r['reserved']);
                    $daily     = $r['sold30'] / 30;
                    $days      = $daily > 0 ? floor($available / $daily) : null;
                    $st        = stock_status($qty, (int) $r['reorder_level']);
                    $img       = upload_url('products', $r['image']);
                ?>
                    <tr>
                        <td>
                            <div class="product-cell">
                                <div class="product-thumb <?= color_class((int) $r['product_id']) ?>">
                                    <?php if ($img): ?><img src="<?= e($img) ?>" alt=""><?php else: ?><i class="fa-solid fa-box"></i><?php endif; ?>
                                </div>
                                <div>
                                    <strong><a href="../products/view.php?id=<?= (int) $r['product_id'] ?>"><?= e($r['name']) ?></a></strong>
                                    <span><?= e($r['sku']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td><?= $r['warehouse_name'] ? e($r['warehouse_name']) : '<span class="muted">Not stocked</span>' ?></td>
                        <td><strong><?= num($qty) ?></strong> <small class="muted"><?= e($r['unit']) ?></small></td>
                        <td><?= num($r['reserved']) ?></td>
                        <td><?= num($available) ?></td>
                        <td><?= num($r['reorder_level']) ?></td>
                        <td>
                            <?php if ($available <= 0): ?>
                                <span class="red-text">Out of stock</span>
                            <?php elseif ($days === null): ?>
                                <span class="muted">No recent sales</span>
                            <?php else: ?>
                                <span class="<?= $days <= 7 ? 'red-text' : ($days <= 21 ? 'orange-text' : '') ?>"><?= num($days) ?> day<?= $days == 1 ? '' : 's' ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= badge($st['label'], $st['class']) ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="icon-btn" title="Adjust stock"
                                    href="adjust.php?product_id=<?= (int) $r['product_id'] ?><?= $r['warehouse_id'] ? '&warehouse_id=' . (int) $r['warehouse_id'] : '' ?>">
                                    <i class="fa-solid fa-scale-balanced"></i>
                                </a>
                                <a class="icon-btn" title="Reorder" href="../orders/add.php?type=purchase&product_id=<?= (int) $r['product_id'] ?>&qty=<?= max(1, (int) $r['reorder_level'] * 2 - $qty) ?>">
                                    <i class="fa-solid fa-cart-plus"></i>
                                </a>
                                <a class="icon-btn" title="Movements" href="movements.php?product=<?= (int) $r['product_id'] ?><?= $r['warehouse_id'] ? '&warehouse=' . (int) $r['warehouse_id'] : '' ?>">
                                    <i class="fa-solid fa-clock-rotate-left"></i>
                                </a>
                                <?php if ($r['inv_id'] && $qty === 0): ?>
                                    <?= delete_button('modules/inventory/delete.php', (int) $r['inv_id'], 'this empty stock record') ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= pagination_links($pg) ?>
</div>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
