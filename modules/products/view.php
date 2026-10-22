<?php
/* =========================================================
   PRODUCTS - DETAIL VIEW
   Stock per warehouse, recent stock movements and orders.
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

$id = (int) get('id', 0);

$product = db_one(
    "SELECT p.*, c.name AS category_name, s.name AS supplier_name, s.lead_time_days
       FROM products p
       LEFT JOIN categories c ON c.id = p.category_id
       LEFT JOIN suppliers s ON s.id = p.supplier_id
      WHERE p.id = ?",
    [$id]
);

if (!$product) {
    flash('Product not found.', 'error');
    redirect('modules/products/index.php');
}

$stockRows = db_all(
    "SELECT w.id, w.name, w.code, COALESCE(i.quantity, 0) AS quantity, i.last_synced_at
       FROM inventory i JOIN warehouses w ON w.id = i.warehouse_id
      WHERE i.product_id = ?
      ORDER BY w.name",
    [$id]
);

$totalStock = array_sum(array_column($stockRows, 'quantity'));
$reserved   = reserved_qty($id);
$avgDaily   = avg_daily_sales($id, 30);
$daysLeft   = $avgDaily > 0 ? floor(max(0, $totalStock - $reserved) / $avgDaily) : null;
$status     = stock_status($totalStock, (int) $product['reorder_level']);

$movements = db_all(
    "SELECT m.*, w.name AS warehouse_name, o.order_no
       FROM stock_movements m
       JOIN warehouses w ON w.id = m.warehouse_id
       LEFT JOIN orders o ON o.id = m.order_id
      WHERE m.product_id = ?
      ORDER BY m.created_at DESC, m.id DESC
      LIMIT 15",
    [$id]
);

$orders = db_all(
    "SELECT o.id, o.order_no, o.order_type, o.status, o.order_date, oi.quantity, oi.unit_price
       FROM order_items oi JOIN orders o ON o.id = oi.order_id
      WHERE oi.product_id = ?
      ORDER BY o.order_date DESC, o.id DESC
      LIMIT 10",
    [$id]
);

$pageTitle    = $product['name'];
$pageSubtitle = 'Product details and stock history';
$activePage   = 'products';

include ROOT_PATH . 'includes/header.php';

$img = upload_url('products', $product['image']);
?>

<div class="section-header">
    <div class="product-cell">
        <div class="product-thumb <?= color_class($id) ?>" style="width:64px;height:64px;font-size:22px">
            <?php if ($img): ?><img src="<?= e($img) ?>" alt=""><?php else: ?><i class="fa-solid fa-box"></i><?php endif; ?>
        </div>
        <div>
            <span class="section-label"><?= e($product['sku']) ?></span>
            <h2><?= e($product['name']) ?></h2>
            <p><?= e($product['subtitle'] ?: ($product['category_name'] ?: 'Uncategorised')) ?></p>
        </div>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <a href="../inventory/adjust.php?product_id=<?= $id ?>" class="btn btn-secondary"><i class="fa-solid fa-scale-balanced"></i> Adjust Stock</a>
        <a href="../orders/add.php?type=purchase&product_id=<?= $id ?>" class="btn btn-secondary"><i class="fa-solid fa-cart-plus"></i> Reorder</a>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-primary"><i class="fa-solid fa-pen"></i> Edit</a>
    </div>
</div>

<div class="inventory-stat-grid">
    <div class="card inventory-stat">
        <span>Total Stock</span>
        <strong><?= num($totalStock) ?> <small class="muted"><?= e($product['unit']) ?></small></strong>
        <small><?= badge($status['label'], $status['class']) ?></small>
    </div>
    <div class="card inventory-stat">
        <span>Reserved (pending sales)</span>
        <strong><?= num($reserved) ?></strong>
        <small>Available: <?= num(max(0, $totalStock - $reserved)) ?></small>
    </div>
    <div class="card inventory-stat">
        <span>Avg. Daily Sales (30d)</span>
        <strong><?= num($avgDaily, 1) ?></strong>
        <small><?= $daysLeft === null ? 'No recent sales' : $daysLeft . ' days of stock left' ?></small>
    </div>
    <div class="card inventory-stat">
        <span>Stock Value (cost)</span>
        <strong><?= money($totalStock * $product['cost_price']) ?></strong>
        <small>Retail: <?= money($totalStock * $product['price']) ?></small>
    </div>
</div>

<div class="card mb-20">
    <div class="card-header">
        <div>
            <h3>Product Information</h3>
            <p><?= e($product['description'] ?: 'No description.') ?></p>
        </div>
        <?= $product['status'] === 'active' ? badge('Active', 'healthy') : badge('Inactive', 'inactive') ?>
    </div>
    <div class="detail-grid">
        <div><span>Category</span><strong><?= e($product['category_name'] ?: 'Uncategorised') ?></strong></div>
        <div><span>Preferred Supplier</span><strong><?= e($product['supplier_name'] ?: '—') ?></strong></div>
        <div><span>Cost Price</span><strong><?= money($product['cost_price']) ?></strong></div>
        <div><span>Selling Price</span><strong><?= money($product['price']) ?></strong></div>
        <div><span>Margin</span><strong><?= $product['price'] > 0 ? round(($product['price'] - $product['cost_price']) / $product['price'] * 100, 1) . '%' : '—' ?></strong></div>
        <div><span>Reorder Level</span><strong><?= num($product['reorder_level']) ?></strong></div>
        <div><span>Supplier Lead Time</span><strong><?= $product['lead_time_days'] !== null ? (int) $product['lead_time_days'] . ' days' : '—' ?></strong></div>
        <div><span>Added</span><strong><?= fmt_date($product['created_at']) ?></strong></div>
    </div>
</div>

<div class="dashboard-grid lower-grid">

    <div class="card table-card">
        <div class="card-header">
            <div>
                <h3>Stock by Warehouse</h3>
                <p>Synced automatically from orders and adjustments.</p>
            </div>
        </div>
        <div class="table-responsive mt-20">
            <table class="data-table" style="min-width:0">
                <thead><tr><th>Warehouse</th><th>Quantity</th><th>Last Sync</th></tr></thead>
                <tbody>
                    <?php if (!$stockRows): ?>
                        <tr><td colspan="3" class="empty-state">No stock recorded yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($stockRows as $r): ?>
                        <tr>
                            <td><strong><?= e($r['name']) ?></strong> <small class="muted"><?= e($r['code']) ?></small></td>
                            <td><?= num($r['quantity']) ?></td>
                            <td><?= fmt_date($r['last_synced_at'], 'M j, H:i') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card table-card">
        <div class="card-header">
            <div>
                <h3>Recent Orders</h3>
                <p>Latest orders containing this product.</p>
            </div>
        </div>
        <div class="table-responsive mt-20">
            <table class="data-table" style="min-width:0">
                <thead><tr><th>Order</th><th>Type</th><th>Qty</th><th>Status</th></tr></thead>
                <tbody>
                    <?php if (!$orders): ?>
                        <tr><td colspan="4" class="empty-state">No orders yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($orders as $o): ?>
                        <tr>
                            <td><a class="link-btn" href="../orders/view.php?id=<?= (int) $o['id'] ?>"><?= e($o['order_no']) ?></a><br><small class="muted"><?= fmt_date($o['order_date']) ?></small></td>
                            <td><?= badge(ucfirst($o['order_type']), $o['order_type']) ?></td>
                            <td><?= num($o['quantity']) ?></td>
                            <td><?= order_status_badge($o['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<div class="card table-card">
    <div class="card-header">
        <div>
            <h3>Stock Movements</h3>
            <p>Last 15 changes to this product's stock.</p>
        </div>
        <a class="link-btn" href="../inventory/movements.php?product=<?= $id ?>">View all</a>
    </div>
    <div class="table-responsive mt-20">
        <table class="data-table">
            <thead><tr><th>Date</th><th>Type</th><th>Warehouse</th><th>Quantity</th><th>Reference</th><th>Note</th></tr></thead>
            <tbody>
                <?php if (!$movements): ?>
                    <tr><td colspan="6" class="empty-state">No stock movements yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($movements as $m): ?>
                    <tr>
                        <td><?= fmt_date($m['created_at'], 'M j, Y H:i') ?></td>
                        <td><?= e(ucfirst($m['movement_type'])) ?></td>
                        <td><?= e($m['warehouse_name']) ?></td>
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
</div>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
