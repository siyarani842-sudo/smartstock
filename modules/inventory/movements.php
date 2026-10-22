<?php
/* =========================================================
   INVENTORY - STOCK LEDGER (all stock movements)
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

$pageTitle    = 'Stock Ledger';
$pageSubtitle = 'Every stock change, from orders and manual adjustments';
$activePage   = 'inventory';

$product   = get('product');
$warehouse = get('warehouse');
$type      = get('type');
$from      = get('from');
$to        = get('to');

$where  = [];
$params = [];

if (ctype_digit($product)) {
    $where[]  = "m.product_id = ?";
    $params[] = (int) $product;
}
if (ctype_digit($warehouse)) {
    $where[]  = "m.warehouse_id = ?";
    $params[] = (int) $warehouse;
}
if (in_array($type, ['opening', 'purchase', 'sale', 'adjustment'], true)) {
    $where[]  = "m.movement_type = ?";
    $params[] = $type;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[]  = "m.created_at >= ?";
    $params[] = $from . ' 00:00:00';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[]  = "m.created_at <= ?";
    $params[] = $to . ' 23:59:59';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_value("SELECT COUNT(*) FROM stock_movements m $whereSql", $params);
$pg    = paginate($total, 20);

$movements = db_all(
    "SELECT m.*, p.name AS product_name, p.sku, w.name AS warehouse_name, o.order_no
       FROM stock_movements m
       JOIN products p ON p.id = m.product_id
       JOIN warehouses w ON w.id = m.warehouse_id
       LEFT JOIN orders o ON o.id = m.order_id
     $whereSql
      ORDER BY m.created_at DESC, m.id DESC
      LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
    $params
);

$totals = db_one(
    "SELECT COALESCE(SUM(CASE WHEN m.quantity > 0 THEN m.quantity END), 0) AS stock_in,
            COALESCE(-SUM(CASE WHEN m.quantity < 0 THEN m.quantity END), 0) AS stock_out
       FROM stock_movements m $whereSql",
    $params
);

$products   = db_all("SELECT id, name FROM products ORDER BY name");
$warehouses = db_all("SELECT id, name FROM warehouses ORDER BY name");

include ROOT_PATH . 'includes/header.php';
?>

<div class="section-header">
    <div>
        <span class="section-label">WAREHOUSE</span>
        <h2>Stock Ledger</h2>
        <p>Inventory totals are always the sum of these movements.</p>
    </div>
    <a href="index.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to inventory</a>
</div>

<div class="mini-stats">
    <div class="mini-stat">
        <span>Movements</span>
        <strong><?= num($total) ?></strong>
        <small>Matching filters</small>
    </div>
    <div class="mini-stat">
        <span>Stock In</span>
        <strong class="green-text">+<?= num($totals['stock_in']) ?></strong>
        <small>Units received / added</small>
    </div>
    <div class="mini-stat">
        <span>Stock Out</span>
        <strong class="red-text">−<?= num($totals['stock_out']) ?></strong>
        <small>Units sold / removed</small>
    </div>
    <div class="mini-stat">
        <span>Net Change</span>
        <strong><?= num($totals['stock_in'] - $totals['stock_out']) ?></strong>
        <small>Units</small>
    </div>
</div>

<div class="card table-card">
    <form class="table-toolbar" method="get">
        <select name="product" data-autosubmit>
            <option value="">All Products</option>
            <?php foreach ($products as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= selected($product, $p['id']) ?>><?= e($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="warehouse" data-autosubmit>
            <option value="">All Warehouses</option>
            <?php foreach ($warehouses as $w): ?>
                <option value="<?= (int) $w['id'] ?>" <?= selected($warehouse, $w['id']) ?>><?= e($w['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="type" data-autosubmit>
            <option value="">All Types</option>
            <?php foreach (['opening', 'purchase', 'sale', 'adjustment'] as $t): ?>
                <option value="<?= $t ?>" <?= selected($type, $t) ?>><?= ucfirst($t) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= e($from) ?>" class="date-input">
        <input type="date" name="to" value="<?= e($to) ?>" class="date-input">
        <button class="btn btn-secondary" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
        <?php if ($product . $warehouse . $type . $from . $to !== ''): ?>
            <a href="movements.php" class="link-btn">Clear</a>
        <?php endif; ?>
    </form>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr><th>Date</th><th>Product</th><th>Warehouse</th><th>Type</th><th>Quantity</th><th>Order</th><th>Note</th></tr>
            </thead>
            <tbody>
                <?php if (!$movements): ?>
                    <tr><td colspan="7" class="empty-state"><i class="fa-solid fa-list"></i>No movements found.</td></tr>
                <?php endif; ?>
                <?php foreach ($movements as $m): ?>
                    <tr>
                        <td class="nowrap"><?= fmt_date($m['created_at'], 'M j, Y H:i') ?></td>
                        <td><a href="../products/view.php?id=<?= (int) $m['product_id'] ?>"><strong><?= e($m['product_name']) ?></strong></a><br><small class="muted"><?= e($m['sku']) ?></small></td>
                        <td><?= e($m['warehouse_name']) ?></td>
                        <td><?= e(ucfirst($m['movement_type'])) ?></td>
                        <td class="<?= $m['quantity'] >= 0 ? 'qty-in' : 'qty-out' ?>"><?= $m['quantity'] > 0 ? '+' : '' ?><?= num($m['quantity']) ?></td>
                        <td>
                            <?php if ($m['order_no']): ?>
                                <a class="link-btn" href="../orders/view.php?id=<?= (int) $m['order_id'] ?>"><?= e($m['order_no']) ?></a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?= e($m['note'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= pagination_links($pg) ?>
</div>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
