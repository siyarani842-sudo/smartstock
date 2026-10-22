<?php
/* =========================================================
   INVENTORY - ADD / ADJUST STOCK
   For stock that does not come from an order: opening stock,
   stock counts, damage, returns, etc. Every adjustment is
   recorded in the stock ledger.
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

$data = [
    'product_id'    => get('product_id'),
    'warehouse_id'  => get('warehouse_id'),
    'mode'          => 'add',
    'quantity'      => '',
    'note'          => '',
    'reorder_level' => '',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach (array_keys($data) as $f) {
        $data[$f] = post($f);
    }

    $product = ctype_digit((string) $data['product_id'])
        ? db_one("SELECT * FROM products WHERE id = ?", [(int) $data['product_id']]) : null;
    $warehouse = ctype_digit((string) $data['warehouse_id'])
        ? db_one("SELECT * FROM warehouses WHERE id = ?", [(int) $data['warehouse_id']]) : null;

    if (!$product) {
        $errors['product_id'] = 'Choose a product.';
    }
    if (!$warehouse) {
        $errors['warehouse_id'] = 'Choose a warehouse.';
    }
    if (!in_array($data['mode'], ['add', 'remove', 'set'], true)) {
        $errors['mode'] = 'Choose an adjustment type.';
    }
    if (!ctype_digit((string) $data['quantity'])) {
        $errors['quantity'] = 'Enter a whole number.';
    }
    if ($data['reorder_level'] !== '' && !ctype_digit((string) $data['reorder_level'])) {
        $errors['reorder_level'] = 'Reorder level must be a whole number.';
    }

    if (!$errors) {
        $current = inventory_qty((int) $product['id'], (int) $warehouse['id']);
        $qty     = (int) $data['quantity'];

        if ($data['mode'] === 'add') {
            $delta = $qty;
        } elseif ($data['mode'] === 'remove') {
            $delta = -$qty;
        } else {
            $delta = $qty - $current; // set exact quantity
        }

        if ($current + $delta < 0) {
            $errors['quantity'] = "Only {$current} units are in this warehouse.";
        }
    }

    if (!$errors) {
        db_begin();

        if ($delta !== 0) {
            $isFirst = !db_value(
                "SELECT COUNT(*) FROM stock_movements WHERE product_id = ? AND warehouse_id = ?",
                [(int) $product['id'], (int) $warehouse['id']]
            );
            $note = $data['note'] ?: ($data['mode'] === 'set' ? "Stock count: set to {$qty}" : 'Manual adjustment');
            add_stock_movement(
                (int) $product['id'],
                (int) $warehouse['id'],
                $delta,
                $isFirst && $delta > 0 ? 'opening' : 'adjustment',
                $note
            );
        } elseif (!db_value("SELECT COUNT(*) FROM inventory WHERE product_id = ? AND warehouse_id = ?", [(int) $product['id'], (int) $warehouse['id']])) {
            // create an empty stock record so the product shows in this warehouse
            db_query(
                "INSERT INTO inventory (product_id, warehouse_id, quantity, last_synced_at) VALUES (?, ?, 0, NOW())",
                [(int) $product['id'], (int) $warehouse['id']]
            );
        }

        if ($data['reorder_level'] !== '') {
            db_query("UPDATE products SET reorder_level = ? WHERE id = ?", [(int) $data['reorder_level'], (int) $product['id']]);
        }

        db_commit();

        $newQty = inventory_qty((int) $product['id'], (int) $warehouse['id']);
        flash("Stock for \"{$product['name']}\" in {$warehouse['name']} is now {$newQty}"
            . ($delta ? ' (' . ($delta > 0 ? '+' : '') . $delta . ').' : ' (no change).'));
        redirect('modules/inventory/index.php');
    }
}

$products   = db_all("SELECT id, name, sku, reorder_level FROM products WHERE status = 'active' OR id = ? ORDER BY name", [(int) $data['product_id']]);
$warehouses = db_all("SELECT id, name FROM warehouses WHERE status = 'active' OR id = ? ORDER BY name", [(int) $data['warehouse_id']]);

/* Current stock table for the selected product */
$stockRows = [];
if (ctype_digit((string) $data['product_id'])) {
    $stockRows = db_all(
        "SELECT w.name, i.quantity FROM inventory i JOIN warehouses w ON w.id = i.warehouse_id
          WHERE i.product_id = ? ORDER BY w.name",
        [(int) $data['product_id']]
    );
}

$pageTitle    = 'Add / Adjust Stock';
$pageSubtitle = 'Record opening stock, stock counts, damages or returns';
$activePage   = 'inventory';

include ROOT_PATH . 'includes/header.php';

$fieldClass = fn($f) => isset($errors[$f]) ? 'has-error' : '';
?>

<div class="section-header">
    <div>
        <span class="section-label">WAREHOUSE</span>
        <h2>Add / Adjust Stock</h2>
        <p>Use this for stock that does not come from an order. Orders update stock automatically.</p>
    </div>
    <a href="index.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to inventory</a>
</div>

<div class="card form-card">
    <form method="post" novalidate>
        <?= csrf_field() ?>

        <div class="form-grid">

            <div class="form-group <?= $fieldClass('product_id') ?>">
                <label>Product <span class="req">*</span></label>
                <select name="product_id" onchange="location.href='?product_id=' + this.value + '&warehouse_id=' + this.form.warehouse_id.value">
                    <option value="">— Select product —</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= selected($data['product_id'], $p['id']) ?>><?= e($p['name']) ?> (<?= e($p['sku']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'product_id') ?>
            </div>

            <div class="form-group <?= $fieldClass('warehouse_id') ?>">
                <label>Warehouse <span class="req">*</span></label>
                <select name="warehouse_id">
                    <option value="">— Select warehouse —</option>
                    <?php foreach ($warehouses as $w): ?>
                        <option value="<?= (int) $w['id'] ?>" <?= selected($data['warehouse_id'], $w['id']) ?>><?= e($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'warehouse_id') ?>
            </div>

            <?php if ($stockRows): ?>
                <div class="form-group full">
                    <div class="alert-box info" style="margin:0">
                        <strong>Current stock:</strong>
                        <?= implode(' · ', array_map(fn($r) => e($r['name']) . ': <strong>' . num($r['quantity']) . '</strong>', $stockRows)) ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="form-group <?= $fieldClass('mode') ?>">
                <label>Adjustment Type <span class="req">*</span></label>
                <select name="mode">
                    <option value="add" <?= selected($data['mode'], 'add') ?>>Add stock (+)</option>
                    <option value="remove" <?= selected($data['mode'], 'remove') ?>>Remove stock (−) e.g. damaged</option>
                    <option value="set" <?= selected($data['mode'], 'set') ?>>Set exact quantity (stock count)</option>
                </select>
                <?= field_error($errors, 'mode') ?>
            </div>

            <div class="form-group <?= $fieldClass('quantity') ?>">
                <label>Quantity <span class="req">*</span></label>
                <input type="number" name="quantity" min="0" step="1" value="<?= e($data['quantity']) ?>">
                <?= field_error($errors, 'quantity') ?>
            </div>

            <div class="form-group <?= $fieldClass('reorder_level') ?>">
                <label>New Reorder Level (optional)</label>
                <input type="number" name="reorder_level" min="0" step="1" value="<?= e($data['reorder_level']) ?>" placeholder="Leave empty to keep current">
                <?= field_error($errors, 'reorder_level') ?>
            </div>

            <div class="form-group">
                <label>Reason / Note</label>
                <input type="text" name="note" maxlength="255" value="<?= e($data['note']) ?>" placeholder="e.g. Monthly stock count">
            </div>

        </div>

        <div class="modal-actions">
            <a href="index.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Adjustment</button>
        </div>
    </form>
</div>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
