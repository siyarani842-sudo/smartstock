<?php
/* =========================================================
   ORDERS - LIST (purchase + sale orders)
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

$pageTitle    = 'Orders';
$pageSubtitle = 'Purchase orders add stock, sales orders remove stock - automatically';
$activePage   = 'orders';

$search   = get('search');
$type     = get('type');
$status   = get('status');
$supplier = get('supplier');
$from     = get('from');
$to       = get('to');

$statuses = ['Pending', 'In Transit', 'Delivered', 'Cancelled'];

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = "(o.order_no LIKE ? OR s.name LIKE ? OR o.customer_name LIKE ? OR o.notes LIKE ?)";
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
if (in_array($type, ['purchase', 'sale'], true)) {
    $where[]  = "o.order_type = ?";
    $params[] = $type;
}
if (in_array($status, $statuses, true)) {
    $where[]  = "o.status = ?";
    $params[] = $status;
}
if (ctype_digit($supplier)) {
    $where[]  = "o.supplier_id = ?";
    $params[] = (int) $supplier;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[]  = "o.order_date >= ?";
    $params[] = $from;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[]  = "o.order_date <= ?";
    $params[] = $to;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$fromSql = "FROM orders o
            LEFT JOIN suppliers s ON s.id = o.supplier_id
            JOIN warehouses w ON w.id = o.warehouse_id";

$total = (int) db_value("SELECT COUNT(*) $fromSql $whereSql", $params);
$pg    = paginate($total, 10);

$orders = db_all(
    "SELECT o.*, s.name AS supplier_name, w.name AS warehouse_name,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS line_count,
            (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.order_id = o.id) AS units
     $fromSql
     $whereSql
     ORDER BY o.order_date DESC, o.id DESC
     LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
    $params
);

$stats = db_one(
    "SELECT COUNT(*) AS total,
            SUM(status = 'Pending') AS pending,
            SUM(status = 'In Transit') AS transit,
            SUM(status = 'Delivered') AS delivered,
            SUM(status = 'Cancelled') AS cancelled
       FROM orders"
);
$completedPct = ($stats['total'] - $stats['cancelled']) > 0
    ? round($stats['delivered'] / ($stats['total'] - $stats['cancelled']) * 100, 1) : 0;

$suppliers = db_all("SELECT id, name FROM suppliers ORDER BY name");

include ROOT_PATH . 'includes/header.php';
?>

<div class="section-header">
    <div>
        <span class="section-label">PROCUREMENT &amp; SALES</span>
        <h2>Orders</h2>
        <p>Stock updates automatically when an order is delivered (purchase) or shipped (sale).</p>
    </div>
    <div class="header-actions">
        <a href="add.php?type=sale" class="btn btn-secondary"><i class="fa-solid fa-cart-arrow-down"></i> New Sales Order</a>
        <a href="add.php?type=purchase" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Create Purchase Order</a>
    </div>
</div>

<div class="mini-stats">
    <div class="mini-stat">
        <span>Total Orders</span>
        <strong><?= num($stats['total']) ?></strong>
        <small>Purchase &amp; sales orders</small>
    </div>
    <div class="mini-stat">
        <span>Pending</span>
        <strong><?= num($stats['pending']) ?></strong>
        <small class="orange-text">Needs review</small>
    </div>
    <div class="mini-stat">
        <span>In Transit</span>
        <strong><?= num($stats['transit']) ?></strong>
        <small>On the way</small>
    </div>
    <div class="mini-stat">
        <span>Delivered</span>
        <strong><?= num($stats['delivered']) ?></strong>
        <small class="green-text"><?= $completedPct ?>% completed</small>
    </div>
</div>

<div class="card table-card">

    <form class="table-toolbar" method="get">
        <div class="table-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search orders...">
        </div>

        <select name="type" data-autosubmit>
            <option value="">All Types</option>
            <option value="purchase" <?= selected($type, 'purchase') ?>>Purchase</option>
            <option value="sale" <?= selected($type, 'sale') ?>>Sale</option>
        </select>

        <select name="status" data-autosubmit>
            <option value="">All Status</option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?= e($s) ?>" <?= selected($status, $s) ?>><?= e($s) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="supplier" data-autosubmit>
            <option value="">All Suppliers</option>
            <?php foreach ($suppliers as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= selected($supplier, $s['id']) ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <input type="date" name="from" value="<?= e($from) ?>" class="date-input" title="From date">
        <input type="date" name="to" value="<?= e($to) ?>" class="date-input" title="To date">

        <button class="btn btn-secondary" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
        <?php if ($search . $type . $status . $supplier . $from . $to !== ''): ?>
            <a href="index.php" class="link-btn">Clear</a>
        <?php endif; ?>
    </form>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Type</th>
                    <th>Supplier / Customer</th>
                    <th>Warehouse</th>
                    <th>Products</th>
                    <th>Value</th>
                    <th>Order Date</th>
                    <th>Status</th>
                    <th>Stock</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$orders): ?>
                    <tr>
                        <td colspan="10" class="empty-state"><i class="fa-solid fa-cart-shopping"></i>No orders found.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><a href="view.php?id=<?= (int) $o['id'] ?>"><strong>#<?= e($o['order_no']) ?></strong></a></td>
                        <td><?= badge(ucfirst($o['order_type']), $o['order_type']) ?></td>
                        <td><?= e($o['order_type'] === 'purchase' ? ($o['supplier_name'] ?: '—') : ($o['customer_name'] ?: 'Walk-in customer')) ?></td>
                        <td><?= e($o['warehouse_name']) ?></td>
                        <td><?= num($o['line_count']) ?> products <small class="muted">(<?= num($o['units']) ?> units)</small></td>
                        <td><?= money($o['total_value']) ?></td>
                        <td class="nowrap"><?= fmt_date($o['order_date']) ?></td>
                        <td><?= order_status_badge($o['status']) ?></td>
                        <td>
                            <?php if ($o['stock_applied']): ?>
                                <span class="<?= $o['order_type'] === 'purchase' ? 'qty-in' : 'qty-out' ?>" title="Stock updated">
                                    <i class="fa-solid fa-<?= $o['order_type'] === 'purchase' ? 'arrow-down' : 'arrow-up' ?>"></i>
                                    <?= $o['order_type'] === 'purchase' ? '+' : '−' ?><?= num($o['units']) ?>
                                </span>
                            <?php elseif ($o['order_type'] === 'sale' && $o['status'] === 'Pending'): ?>
                                <span class="muted" title="Reserved stock">Reserved</span>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="icon-btn" href="view.php?id=<?= (int) $o['id'] ?>" title="View"><i class="fa-solid fa-eye"></i></a>
                                <a class="icon-btn" href="edit.php?id=<?= (int) $o['id'] ?>" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <?= delete_button('modules/orders/delete.php', (int) $o['id'], 'order ' . $o['order_no']) ?>
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
