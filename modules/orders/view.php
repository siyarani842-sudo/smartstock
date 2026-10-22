<?php
/* =========================================================
   ORDERS - DETAIL VIEW + quick status buttons
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

$id = (int) get('id', 0);

$order = db_one(
    "SELECT o.*, s.name AS supplier_name, s.email AS supplier_email, s.phone AS supplier_phone,
            w.name AS warehouse_name
       FROM orders o
       LEFT JOIN suppliers s ON s.id = o.supplier_id
       JOIN warehouses w ON w.id = o.warehouse_id
      WHERE o.id = ?",
    [$id]
);

if (!$order) {
    flash('Order not found.', 'error');
    redirect('modules/orders/index.php');
}

$items = db_all(
    "SELECT oi.*, p.name, p.sku, p.unit, p.image,
            COALESCE(i.quantity, 0) AS warehouse_stock
       FROM order_items oi
       JOIN products p ON p.id = oi.product_id
       LEFT JOIN inventory i ON i.product_id = oi.product_id AND i.warehouse_id = ?
      WHERE oi.order_id = ?
      ORDER BY oi.id",
    [(int) $order['warehouse_id'], $id]
);

$movements = db_all(
    "SELECT m.*, p.name FROM stock_movements m JOIN products p ON p.id = m.product_id
      WHERE m.order_id = ? ORDER BY m.id",
    [$id]
);

$isPurchase = $order['order_type'] === 'purchase';
$units      = array_sum(array_column($items, 'quantity'));

/* Which quick status buttons to show */
$nextStatuses = array_diff(['Pending', 'In Transit', 'Delivered', 'Cancelled'], [$order['status']]);

$pageTitle    = 'Order #' . $order['order_no'];
$pageSubtitle = ($isPurchase ? 'Purchase order' : 'Sales order') . ' details';
$activePage   = 'orders';

include ROOT_PATH . 'includes/header.php';
?>

<div class="section-header">
    <div>
        <span class="section-label"><?= $isPurchase ? 'PURCHASE ORDER' : 'SALES ORDER' ?></span>
        <h2>#<?= e($order['order_no']) ?> <?= order_status_badge($order['status']) ?></h2>
        <p>
            <?= $isPurchase ? 'From ' . e($order['supplier_name'] ?: '—') : 'To ' . e($order['customer_name'] ?: 'Walk-in customer') ?>
            · <?= e($order['warehouse_name']) ?>
        </p>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-primary"><i class="fa-solid fa-pen"></i> Edit</a>
    </div>
</div>

<div class="alert-banner" style="margin-bottom:20px">
    <div class="alert-symbol" style="<?= $order['stock_applied'] ? 'background:var(--green-light);color:var(--green)' : '' ?>">
        <i class="fa-solid fa-<?= $order['stock_applied'] ? 'check' : 'clock' ?>"></i>
    </div>
    <div class="alert-content">
        <?php if ($order['stock_applied']): ?>
            <strong>Inventory synced</strong>
            <p><?= num($units) ?> units were <?= $isPurchase ? 'added to' : 'removed from' ?> <?= e($order['warehouse_name']) ?>.</p>
        <?php elseif ($order['status'] === 'Cancelled'): ?>
            <strong>Cancelled - no stock effect</strong>
            <p>This order does not change inventory.</p>
        <?php elseif ($isPurchase): ?>
            <strong>Waiting for delivery</strong>
            <p>Stock will be added automatically when this order is marked Delivered.</p>
        <?php else: ?>
            <strong><?= num($units) ?> units reserved</strong>
            <p>Stock will be deducted automatically when this order is marked In Transit or Delivered.</p>
        <?php endif; ?>
    </div>
    <div class="header-actions">
        <?php foreach ($nextStatuses as $s): ?>
            <form method="post" action="status.php" class="inline-form"
                <?= $s === 'Cancelled' ? 'data-confirm="Cancel this order? Any stock it changed will be reversed."' : '' ?>>
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="status" value="<?= e($s) ?>">
                <button class="btn btn-sm <?= $s === 'Delivered' ? 'btn-primary' : 'btn-secondary' ?>" type="submit">
                    Mark <?= e($s) ?>
                </button>
            </form>
        <?php endforeach; ?>
    </div>
</div>

<div class="card mb-20">
    <div class="detail-grid">
        <div><span>Order Date</span><strong><?= fmt_date($order['order_date']) ?></strong></div>
        <div><span>Expected Delivery</span><strong><?= fmt_date($order['expected_date']) ?></strong></div>
        <div><span>Delivered On</span><strong><?= fmt_date($order['delivered_date']) ?></strong></div>
        <div><span>Total Value</span><strong><?= money($order['total_value']) ?></strong></div>
        <?php if ($isPurchase): ?>
            <div><span>Supplier Email</span><strong><?= e($order['supplier_email'] ?: '—') ?></strong></div>
            <div><span>Supplier Phone</span><strong><?= e($order['supplier_phone'] ?: '—') ?></strong></div>
        <?php endif; ?>
        <div><span>Created</span><strong><?= fmt_date($order['created_at'], 'M j, Y H:i') ?></strong></div>
        <div><span>Last Updated</span><strong><?= fmt_date($order['updated_at'], 'M j, Y H:i') ?></strong></div>
    </div>
    <?php if ($order['notes']): ?>
        <div style="padding:0 20px 20px" class="muted"><strong>Notes:</strong> <?= nl2br(e($order['notes'])) ?></div>
    <?php endif; ?>
</div>

<div class="card table-card mb-20">
    <div class="card-header">
        <div>
            <h3>Order Items</h3>
            <p><?= count($items) ?> products · <?= num($units) ?> units</p>
        </div>
    </div>
    <div class="table-responsive mt-20">
        <table class="data-table">
            <thead>
                <tr><th>Product</th><th>SKU</th><th>Quantity</th><th>Unit Price</th><th>Line Total</th><th>Stock in <?= e($order['warehouse_name']) ?></th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it):
                    $img = upload_url('products', $it['image']); ?>
                    <tr>
                        <td>
                            <div class="product-cell">
                                <div class="product-thumb <?= color_class((int) $it['product_id']) ?>">
                                    <?php if ($img): ?><img src="<?= e($img) ?>" alt=""><?php else: ?><i class="fa-solid fa-box"></i><?php endif; ?>
                                </div>
                                <div><strong><a href="../products/view.php?id=<?= (int) $it['product_id'] ?>"><?= e($it['name']) ?></a></strong></div>
                            </div>
                        </td>
                        <td><?= e($it['sku']) ?></td>
                        <td><?= num($it['quantity']) ?> <small class="muted"><?= e($it['unit']) ?></small></td>
                        <td><?= money($it['unit_price']) ?></td>
                        <td><strong><?= money($it['line_total']) ?></strong></td>
                        <td><?= num($it['warehouse_stock']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td colspan="4" class="text-right"><strong>Total</strong></td>
                    <td colspan="2"><strong><?= money($order['total_value']) ?></strong></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card table-card">
    <div class="card-header">
        <div>
            <h3>Stock Movements from this Order</h3>
            <p>Created automatically by the inventory sync.</p>
        </div>
    </div>
    <div class="table-responsive mt-20">
        <table class="data-table">
            <thead><tr><th>Date</th><th>Product</th><th>Type</th><th>Quantity</th><th>Note</th></tr></thead>
            <tbody>
                <?php if (!$movements): ?>
                    <tr><td colspan="5" class="empty-state">No stock movements - this order has not changed inventory.</td></tr>
                <?php endif; ?>
                <?php foreach ($movements as $m): ?>
                    <tr>
                        <td><?= fmt_date($m['created_at'], 'M j, Y H:i') ?></td>
                        <td><?= e($m['name']) ?></td>
                        <td><?= e(ucfirst($m['movement_type'])) ?></td>
                        <td class="<?= $m['quantity'] >= 0 ? 'qty-in' : 'qty-out' ?>"><?= $m['quantity'] > 0 ? '+' : '' ?><?= num($m['quantity']) ?></td>
                        <td><?= e($m['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="mt-20 text-right">
    <form method="post" action="delete.php" class="inline-form" data-confirm="Delete order <?= e($order['order_no']) ?>? Any stock it changed will be reversed.">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i> Delete Order</button>
    </form>
</div>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
