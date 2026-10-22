<?php
/* =========================================================
   ORDERS - ADD / EDIT FORM
   After saving, sync_order() updates inventory automatically:
     purchase + Delivered            -> stock IN
     sale + In Transit / Delivered   -> stock OUT
   The whole save runs in a transaction, so if a sale does not
   have enough stock nothing is saved and an error is shown.
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

$mode     = $mode ?? 'add';
$isEdit   = $mode === 'edit';
$id       = (int) get('id', 0);
$statuses = ['Pending', 'In Transit', 'Delivered', 'Cancelled'];

$type = get('type') === 'sale' ? 'sale' : 'purchase';

$data = [
    'order_no'       => '',
    'order_type'     => $type,
    'supplier_id'    => '',
    'customer_name'  => '',
    'warehouse_id'   => '',
    'status'         => 'Pending',
    'order_date'     => date('Y-m-d'),
    'expected_date'  => '',
    'delivered_date' => '',
    'notes'          => '',
];
$items = [];

if ($isEdit) {
    $existing = db_one("SELECT * FROM orders WHERE id = ?", [$id]);
    if (!$existing) {
        flash('Order not found.', 'error');
        redirect('modules/orders/index.php');
    }
    $data  = array_merge($data, $existing);
    $items = db_all("SELECT product_id, quantity, unit_price FROM order_items WHERE order_id = ? ORDER BY id", [$id]);
} else {
    $data['order_no'] = next_order_no($type);

    /* Pre-fill from links like add.php?type=purchase&product_id=5&qty=150 */
    $preProduct = (int) get('product_id', 0);
    if ($preProduct) {
        $p = db_one("SELECT id, supplier_id, cost_price, price FROM products WHERE id = ?", [$preProduct]);
        if ($p) {
            $items[] = [
                'product_id' => $p['id'],
                'quantity'   => max(1, (int) get('qty', 1)),
                'unit_price' => $type === 'sale' ? $p['price'] : $p['cost_price'],
            ];
            if ($type === 'purchase' && $p['supplier_id']) {
                $data['supplier_id'] = $p['supplier_id'];
            }
        }
    }
    $firstWarehouse = db_value("SELECT id FROM warehouses WHERE status = 'active' ORDER BY id LIMIT 1", [], '');
    $data['warehouse_id'] = $firstWarehouse;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach (['order_no', 'supplier_id', 'customer_name', 'warehouse_id', 'status',
              'order_date', 'expected_date', 'delivered_date', 'notes'] as $f) {
        $data[$f] = post($f);
    }
    $data['order_type'] = post('order_type') === 'sale' ? 'sale' : 'purchase';
    $data['order_no']   = strtoupper($data['order_no']);

    /* ---- line items ---- */
    $items = [];
    $productIds = (array) ($_POST['product_id'] ?? []);
    $quantities = (array) ($_POST['quantity'] ?? []);
    $prices     = (array) ($_POST['unit_price'] ?? []);

    foreach ($productIds as $i => $pid) {
        $pid   = trim((string) $pid);
        $qty   = trim((string) ($quantities[$i] ?? ''));
        $price = trim((string) ($prices[$i] ?? ''));

        if ($pid === '' && $qty === '') {
            continue; // empty row
        }
        $items[] = ['product_id' => $pid, 'quantity' => $qty, 'unit_price' => $price];

        if (!ctype_digit($pid) || !db_value("SELECT COUNT(*) FROM products WHERE id = ?", [(int) $pid])) {
            $errors['items'] = 'Every line needs a valid product.';
        } elseif (!ctype_digit($qty) || (int) $qty < 1) {
            $errors['items'] = 'Quantity must be a whole number of at least 1.';
        } elseif (!is_numeric($price) || $price < 0) {
            $errors['items'] = 'Unit price must be 0 or more.';
        }
    }
    if (!$items) {
        $errors['items'] = 'Add at least one product to the order.';
    }

    /* ---- header fields ---- */
    if ($data['order_no'] === '') {
        $errors['order_no'] = 'Order number is required.';
    } elseif (!preg_match('/^[A-Z0-9\-]{3,30}$/', $data['order_no'])) {
        $errors['order_no'] = 'Use 3-30 letters, numbers or dashes.';
    } elseif (db_value("SELECT COUNT(*) FROM orders WHERE order_no = ? AND id <> ?", [$data['order_no'], $id])) {
        $errors['order_no'] = 'This order number already exists.';
    }

    if ($data['order_type'] === 'purchase') {
        if (!ctype_digit((string) $data['supplier_id']) || !db_value("SELECT COUNT(*) FROM suppliers WHERE id = ?", [(int) $data['supplier_id']])) {
            $errors['supplier_id'] = 'Choose the supplier for this purchase order.';
        }
        $data['customer_name'] = '';
    } else {
        $data['supplier_id'] = '';
    }

    if (!ctype_digit((string) $data['warehouse_id']) || !db_value("SELECT COUNT(*) FROM warehouses WHERE id = ?", [(int) $data['warehouse_id']])) {
        $errors['warehouse_id'] = 'Choose a warehouse.';
    }

    if (!in_array($data['status'], $statuses, true)) {
        $errors['status'] = 'Choose a valid status.';
    }

    foreach (['order_date', 'expected_date', 'delivered_date'] as $f) {
        if ($data[$f] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data[$f])) {
            $errors[$f] = 'Enter a valid date.';
        }
    }
    if ($data['order_date'] === '') {
        $errors['order_date'] = 'Order date is required.';
    }

    /* Delivered date is only meaningful for delivered orders */
    if ($data['status'] === 'Delivered' && $data['delivered_date'] === '') {
        $data['delivered_date'] = date('Y-m-d');
    }
    if ($data['status'] !== 'Delivered') {
        $data['delivered_date'] = '';
    }

    /* ---- save ---- */
    if (!$errors) {
        $values = [
            $data['order_no'], $data['order_type'],
            $data['supplier_id'] !== '' ? (int) $data['supplier_id'] : null,
            $data['customer_name'] ?: null,
            (int) $data['warehouse_id'], $data['status'], $data['order_date'],
            $data['expected_date'] ?: null, $data['delivered_date'] ?: null, $data['notes'] ?: null,
        ];

        try {
            db_begin();

            if ($isEdit) {
                $values[] = $id;
                db_query(
                    "UPDATE orders SET order_no = ?, order_type = ?, supplier_id = ?, customer_name = ?, warehouse_id = ?,
                            status = ?, order_date = ?, expected_date = ?, delivered_date = ?, notes = ?
                      WHERE id = ?",
                    $values
                );
                db_query("DELETE FROM order_items WHERE order_id = ?", [$id]);
            } else {
                db_query(
                    "INSERT INTO orders (order_no, order_type, supplier_id, customer_name, warehouse_id,
                                         status, order_date, expected_date, delivered_date, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    $values
                );
                $id = db_insert_id();
            }

            $total = 0;
            foreach ($items as $item) {
                $line   = round((int) $item['quantity'] * (float) $item['unit_price'], 2);
                $total += $line;
                db_query(
                    "INSERT INTO order_items (order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)",
                    [$id, (int) $item['product_id'], (int) $item['quantity'], (float) $item['unit_price'], $line]
                );
            }
            db_query("UPDATE orders SET total_value = ? WHERE id = ?", [$total, $id]);

            /* Automatic inventory sync */
            $action = sync_order($id);

            db_commit();

            $stockMsg = [
                'applied'   => ' Inventory updated.',
                'updated'   => ' Inventory re-synced with the new quantities.',
                'reverted'  => ' Stock effect reversed.',
                'unchanged' => '',
            ][$action];

            flash('Order ' . $data['order_no'] . ($isEdit ? ' updated.' : ' created.') . $stockMsg);
            redirect('modules/orders/view.php?id=' . $id);

        } catch (RuntimeException $ex) {
            db_rollback();
            if (!$isEdit) {
                $id = 0;
            }
            $errors['stock'] = $ex->getMessage();
        }
    }
}

/* Lists for dropdowns */
$suppliers  = db_all("SELECT id, name FROM suppliers WHERE status = 'active' OR id = ? ORDER BY name", [(int) $data['supplier_id']]);
$warehouses = db_all("SELECT id, name FROM warehouses WHERE status = 'active' OR id = ? ORDER BY name", [(int) $data['warehouse_id']]);

$itemIds  = array_filter(array_map(fn($i) => (int) $i['product_id'], $items));
$idList   = $itemIds ? implode(',', $itemIds) : '0';
$products = db_all(
    "SELECT p.id, p.name, p.sku, p.cost_price, p.price, COALESCE(SUM(i.quantity), 0) AS stock
       FROM products p
       LEFT JOIN inventory i ON i.product_id = p.id
      WHERE p.status = 'active' OR p.id IN ($idList)
      GROUP BY p.id, p.name, p.sku, p.cost_price, p.price
      ORDER BY p.name"
);

if (!$items) {
    $items[] = ['product_id' => '', 'quantity' => 1, 'unit_price' => ''];
}

$pageTitle    = $isEdit ? 'Edit Order' : ($data['order_type'] === 'sale' ? 'New Sales Order' : 'Create Purchase Order');
$pageSubtitle = 'Inventory is updated automatically from the order status';
$activePage   = 'orders';

include ROOT_PATH . 'includes/header.php';

$fieldClass = fn($f) => isset($errors[$f]) ? 'has-error' : '';

/** One <tr> of the items editor */
function order_item_row(array $products, array $item = []): string
{
    ob_start(); ?>
    <tr>
        <td style="width:45%">
            <select name="product_id[]" class="item-product">
                <option value="">— Select product —</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"
                        data-cost="<?= e($p['cost_price']) ?>"
                        data-price="<?= e($p['price']) ?>"
                        <?= selected($item['product_id'] ?? '', $p['id']) ?>>
                        <?= e($p['name']) ?> (<?= e($p['sku']) ?>) — stock <?= num($p['stock']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td style="width:14%"><input type="number" name="quantity[]" class="item-qty" min="1" step="1" value="<?= e($item['quantity'] ?? 1) ?>"></td>
        <td style="width:18%"><input type="number" name="unit_price[]" class="item-price" min="0" step="0.01" value="<?= e($item['unit_price'] ?? '') ?>" data-auto="<?= ($item['unit_price'] ?? '') === '' ? '1' : '0' ?>"></td>
        <td style="width:16%"><span class="line-total">0.00</span></td>
        <td style="width:7%"><button type="button" class="icon-btn danger remove-item" title="Remove"><i class="fa-solid fa-xmark"></i></button></td>
    </tr>
    <?php return ob_get_clean();
}
?>

<div class="section-header">
    <div>
        <span class="section-label">ORDERS</span>
        <h2><?= e($pageTitle) ?></h2>
        <p><?= $isEdit ? 'Editing order #' . e($data['order_no']) : 'Fill in the order details and add products.' ?></p>
    </div>
    <a href="index.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to orders</a>
</div>

<?php if (isset($errors['stock'])): ?>
    <div class="alert-box error"><strong>Order not saved.</strong> <?= e($errors['stock']) ?></div>
<?php elseif ($errors): ?>
    <div class="alert-box error"><strong>Please fix the highlighted fields.</strong></div>
<?php endif; ?>

<div class="alert-box info">
    <i class="fa-solid fa-arrows-rotate"></i>
    <strong>Automatic inventory sync:</strong>
    purchase orders add stock when marked <em>Delivered</em>; sales orders remove stock when
    <em>In Transit</em> or <em>Delivered</em>. Pending sales are shown as reserved stock.
</div>

<div class="card form-card" style="max-width:1100px">
    <form method="post" novalidate>
        <?= csrf_field() ?>

        <div class="form-grid">

            <div class="form-section-title">Order Details</div>

            <div class="form-group">
                <label>Order Type <span class="req">*</span></label>
                <select name="order_type" id="order_type">
                    <option value="purchase" <?= selected($data['order_type'], 'purchase') ?>>Purchase order (stock in)</option>
                    <option value="sale" <?= selected($data['order_type'], 'sale') ?>>Sales order (stock out)</option>
                </select>
            </div>

            <div class="form-group <?= $fieldClass('order_no') ?>">
                <label>Order Number <span class="req">*</span></label>
                <input type="text" name="order_no" id="order_no" maxlength="30" value="<?= e($data['order_no']) ?>"
                    <?php if (!$isEdit): ?>data-purchase="<?= e(next_order_no('purchase')) ?>" data-sale="<?= e(next_order_no('sale')) ?>"<?php endif; ?>>
                <?= field_error($errors, 'order_no') ?>
            </div>

            <div class="form-group <?= $fieldClass('supplier_id') ?>" data-show-for="purchase">
                <label>Supplier <span class="req">*</span></label>
                <select name="supplier_id">
                    <option value="">— Select supplier —</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= selected($data['supplier_id'], $s['id']) ?>><?= e($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'supplier_id') ?>
            </div>

            <div class="form-group" data-show-for="sale">
                <label>Customer Name</label>
                <input type="text" name="customer_name" maxlength="150" value="<?= e($data['customer_name']) ?>" placeholder="Leave empty for walk-in customer">
            </div>

            <div class="form-group <?= $fieldClass('warehouse_id') ?>">
                <label>Warehouse <span class="req">*</span></label>
                <select name="warehouse_id">
                    <option value="">— Select warehouse —</option>
                    <?php foreach ($warehouses as $w): ?>
                        <option value="<?= (int) $w['id'] ?>" <?= selected($data['warehouse_id'], $w['id']) ?>><?= e($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="hint">Stock is received into / shipped from this warehouse.</span>
                <?= field_error($errors, 'warehouse_id') ?>
            </div>

            <div class="form-group <?= $fieldClass('status') ?>">
                <label>Status <span class="req">*</span></label>
                <select name="status">
                    <?php foreach ($statuses as $s): ?>
                        <option value="<?= e($s) ?>" <?= selected($data['status'], $s) ?>><?= e($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'status') ?>
            </div>

            <div class="form-group <?= $fieldClass('order_date') ?>">
                <label>Order Date <span class="req">*</span></label>
                <input type="date" name="order_date" value="<?= e($data['order_date']) ?>">
                <?= field_error($errors, 'order_date') ?>
            </div>

            <div class="form-group <?= $fieldClass('expected_date') ?>">
                <label>Expected Delivery</label>
                <input type="date" name="expected_date" value="<?= e($data['expected_date']) ?>">
                <?= field_error($errors, 'expected_date') ?>
            </div>

            <div class="form-group <?= $fieldClass('delivered_date') ?>">
                <label>Delivered On</label>
                <input type="date" name="delivered_date" value="<?= e($data['delivered_date']) ?>">
                <span class="hint">Filled automatically with today when status is Delivered.</span>
                <?= field_error($errors, 'delivered_date') ?>
            </div>

            <div class="form-section-title">Products</div>

            <table class="items-table" id="itemsTable">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Unit Price</th>
                        <th>Line Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?= order_item_row($products, $item) ?>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <template id="itemRowTemplate"><?= order_item_row($products) ?></template>

            <div class="items-footer">
                <div>
                    <button type="button" class="btn btn-secondary btn-sm" id="addItemBtn"><i class="fa-solid fa-plus"></i> Add product</button>
                    <?= isset($errors['items']) ? '<br>' . field_error($errors, 'items') : '' ?>
                </div>
                <div class="order-total">Total: <span id="orderTotal"><?= money(0) ?></span></div>
            </div>

            <div class="form-group full">
                <label>Notes</label>
                <textarea name="notes" placeholder="Optional notes"><?= e($data['notes']) ?></textarea>
            </div>

        </div>

        <div class="modal-actions">
            <a href="index.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-<?= $isEdit ? 'floppy-disk' : 'plus' ?>"></i>
                <?= $isEdit ? 'Save Changes' : 'Create Order' ?>
            </button>
        </div>
    </form>
</div>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
