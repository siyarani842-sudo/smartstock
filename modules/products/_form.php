<?php
/* =========================================================
   PRODUCTS - ADD / EDIT FORM (with image upload)
   Images are stored in /uploads/products/
   On ADD, "Opening Stock" is posted to the chosen warehouse
   as an 'opening' stock movement.
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

$mode   = $mode ?? 'add';
$isEdit = $mode === 'edit';
$id     = (int) get('id', 0);

$data = [
    'name' => '', 'sku' => '', 'category_id' => '', 'supplier_id' => '', 'subtitle' => '',
    'description' => '', 'unit' => 'pcs', 'cost_price' => '', 'price' => '',
    'reorder_level' => 10, 'status' => 'active', 'image' => null,
    'opening_stock' => 0, 'warehouse_id' => '',
];

if ($isEdit) {
    $existing = db_one("SELECT * FROM products WHERE id = ?", [$id]);
    if (!$existing) {
        flash('Product not found.', 'error');
        redirect('modules/products/index.php');
    }
    $data = array_merge($data, $existing);
}

$categories = db_all("SELECT id, name FROM categories WHERE status = 'active' OR id = ? ORDER BY name", [(int) $data['category_id']]);
$suppliers  = db_all("SELECT id, name FROM suppliers WHERE status = 'active' OR id = ? ORDER BY name", [(int) $data['supplier_id']]);
$warehouses = db_all("SELECT id, name FROM warehouses WHERE status = 'active' ORDER BY name");

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach (['name', 'sku', 'category_id', 'supplier_id', 'subtitle', 'description', 'unit',
              'cost_price', 'price', 'reorder_level', 'opening_stock', 'warehouse_id'] as $f) {
        $data[$f] = post($f);
    }
    $data['sku']    = strtoupper($data['sku']);
    $data['unit']   = $data['unit'] ?: 'pcs';
    $data['status'] = post('status') === 'inactive' ? 'inactive' : 'active';

    /* ---- validation ---- */
    if ($data['name'] === '') {
        $errors['name'] = 'Product name is required.';
    }

    if ($data['sku'] === '') {
        $errors['sku'] = 'SKU is required.';
    } elseif (!preg_match('/^[A-Z0-9\-_.]{2,50}$/', $data['sku'])) {
        $errors['sku'] = 'Use 2-50 letters, numbers, dash, dot or underscore.';
    } elseif (db_value("SELECT COUNT(*) FROM products WHERE sku = ? AND id <> ?", [$data['sku'], $id])) {
        $errors['sku'] = 'This SKU is already used by another product.';
    }

    if ($data['category_id'] !== '' && !db_value("SELECT COUNT(*) FROM categories WHERE id = ?", [(int) $data['category_id']])) {
        $errors['category_id'] = 'Choose a valid category.';
    }
    if ($data['supplier_id'] !== '' && !db_value("SELECT COUNT(*) FROM suppliers WHERE id = ?", [(int) $data['supplier_id']])) {
        $errors['supplier_id'] = 'Choose a valid supplier.';
    }

    if ($data['cost_price'] === '' || !is_numeric($data['cost_price']) || $data['cost_price'] < 0) {
        $errors['cost_price'] = 'Enter a valid cost price.';
    }
    if ($data['price'] === '' || !is_numeric($data['price']) || $data['price'] < 0) {
        $errors['price'] = 'Enter a valid selling price.';
    }
    if (!ctype_digit((string) $data['reorder_level'])) {
        $errors['reorder_level'] = 'Reorder level must be a whole number.';
    }

    if (!$isEdit) {
        if ($data['opening_stock'] === '') {
            $data['opening_stock'] = 0;
        }
        if (!ctype_digit((string) $data['opening_stock'])) {
            $errors['opening_stock'] = 'Opening stock must be a whole number.';
        } elseif ((int) $data['opening_stock'] > 0 && !ctype_digit((string) $data['warehouse_id'])) {
            $errors['warehouse_id'] = 'Choose the warehouse that holds the opening stock.';
        }
    }

    /* ---- image ---- */
    $newImage = null;
    if (!$errors) {
        try {
            $newImage = upload_image('image', 'products');
        } catch (RuntimeException $ex) {
            $errors['image'] = $ex->getMessage();
        }
    }

    /* ---- save ---- */
    if (!$errors) {
        $image = $data['image'];

        if ($newImage) {
            if ($isEdit) {
                delete_image('products', $image);
            }
            $image = $newImage;
        } elseif ($isEdit && post('remove_image') === '1') {
            delete_image('products', $image);
            $image = null;
        }

        $values = [
            $data['name'], $data['sku'],
            $data['category_id'] !== '' ? (int) $data['category_id'] : null,
            $data['supplier_id'] !== '' ? (int) $data['supplier_id'] : null,
            $data['subtitle'] ?: null, $data['description'] ?: null, $data['unit'],
            (float) $data['cost_price'], (float) $data['price'], (int) $data['reorder_level'],
            $image, $data['status'],
        ];

        if ($isEdit) {
            $values[] = $id;
            db_query(
                "UPDATE products SET name = ?, sku = ?, category_id = ?, supplier_id = ?, subtitle = ?, description = ?,
                        unit = ?, cost_price = ?, price = ?, reorder_level = ?, image = ?, status = ?
                  WHERE id = ?",
                $values
            );
            flash('Product updated successfully.');
        } else {
            db_begin();
            db_query(
                "INSERT INTO products (name, sku, category_id, supplier_id, subtitle, description,
                                       unit, cost_price, price, reorder_level, image, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                $values
            );
            $id = db_insert_id();

            if ((int) $data['opening_stock'] > 0) {
                add_stock_movement($id, (int) $data['warehouse_id'], (int) $data['opening_stock'], 'opening', 'Opening stock');
            }
            db_commit();
            flash('Product added successfully.');
        }
        redirect('modules/products/view.php?id=' . $id);
    }
}

$pageTitle    = $isEdit ? 'Edit Product' : 'Add Product';
$pageSubtitle = $isEdit ? 'Update product details' : 'Add a product to your catalog';
$activePage   = 'products';

include ROOT_PATH . 'includes/header.php';

$fieldClass = fn($f) => isset($errors[$f]) ? 'has-error' : '';
$imageUrl   = upload_url('products', $data['image']);
?>

<div class="section-header">
    <div>
        <span class="section-label">CATALOG</span>
        <h2><?= e($pageTitle) ?></h2>
        <p><?= $isEdit ? 'Editing “' . e($data['name']) . '”' : 'Fill in the details below.' ?></p>
    </div>
    <a href="index.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to list</a>
</div>

<?php if ($errors): ?>
    <div class="alert-box error"><strong>Please fix the highlighted fields.</strong></div>
<?php endif; ?>

<div class="card form-card">
    <form method="post" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>

        <div class="form-grid">

            <div class="form-section-title">Basic Information</div>

            <div class="form-group full <?= $fieldClass('name') ?>">
                <label>Product Name <span class="req">*</span></label>
                <input type="text" name="name" maxlength="150" value="<?= e($data['name']) ?>" placeholder="e.g. Wireless Headphones">
                <?= field_error($errors, 'name') ?>
            </div>

            <div class="form-group <?= $fieldClass('sku') ?>">
                <label>SKU <span class="req">*</span></label>
                <input type="text" name="sku" maxlength="50" value="<?= e($data['sku']) ?>" placeholder="e.g. WH-1001">
                <?= field_error($errors, 'sku') ?>
            </div>

            <div class="form-group">
                <label>Short Tagline</label>
                <input type="text" name="subtitle" maxlength="150" value="<?= e($data['subtitle']) ?>" placeholder="e.g. Premium Audio">
            </div>

            <div class="form-group <?= $fieldClass('category_id') ?>">
                <label>Category</label>
                <select name="category_id">
                    <option value="">— Uncategorised —</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= selected($data['category_id'], $c['id']) ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'category_id') ?>
            </div>

            <div class="form-group <?= $fieldClass('supplier_id') ?>">
                <label>Preferred Supplier</label>
                <select name="supplier_id">
                    <option value="">— None —</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= selected($data['supplier_id'], $s['id']) ?>><?= e($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'supplier_id') ?>
            </div>

            <div class="form-group full">
                <label>Description</label>
                <textarea name="description" placeholder="Product description (optional)"><?= e($data['description']) ?></textarea>
            </div>

            <div class="form-section-title">Pricing &amp; Stock Rules</div>

            <div class="form-group <?= $fieldClass('cost_price') ?>">
                <label>Cost Price <span class="req">*</span></label>
                <input type="number" name="cost_price" min="0" step="0.01" value="<?= e($data['cost_price']) ?>" placeholder="0.00">
                <span class="hint">What you pay the supplier (used for stock value).</span>
                <?= field_error($errors, 'cost_price') ?>
            </div>

            <div class="form-group <?= $fieldClass('price') ?>">
                <label>Selling Price <span class="req">*</span></label>
                <input type="number" name="price" min="0" step="0.01" value="<?= e($data['price']) ?>" placeholder="0.00">
                <?= field_error($errors, 'price') ?>
            </div>

            <div class="form-group <?= $fieldClass('reorder_level') ?>">
                <label>Reorder Level</label>
                <input type="number" name="reorder_level" min="0" step="1" value="<?= e($data['reorder_level']) ?>">
                <span class="hint">At or below this quantity the product shows as Low Stock.</span>
                <?= field_error($errors, 'reorder_level') ?>
            </div>

            <div class="form-group">
                <label>Unit</label>
                <input type="text" name="unit" maxlength="20" value="<?= e($data['unit']) ?>" placeholder="pcs, box, kg...">
            </div>

            <?php if (!$isEdit): ?>
                <div class="form-group <?= $fieldClass('opening_stock') ?>">
                    <label>Opening Stock</label>
                    <input type="number" name="opening_stock" min="0" step="1" value="<?= e($data['opening_stock']) ?>">
                    <?= field_error($errors, 'opening_stock') ?>
                </div>

                <div class="form-group <?= $fieldClass('warehouse_id') ?>">
                    <label>Warehouse for Opening Stock</label>
                    <select name="warehouse_id">
                        <option value="">— Select —</option>
                        <?php foreach ($warehouses as $w): ?>
                            <option value="<?= (int) $w['id'] ?>" <?= selected($data['warehouse_id'], $w['id']) ?>><?= e($w['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error($errors, 'warehouse_id') ?>
                </div>
            <?php else: ?>
                <div class="form-group full">
                    <div class="alert-box info" style="margin:0">
                        Stock is changed through <strong>Orders</strong> (automatic) or
                        <a class="link-btn" href="../inventory/adjust.php?product_id=<?= $id ?>">Inventory → Adjust Stock</a>.
                    </div>
                </div>
            <?php endif; ?>

            <div class="form-section-title">Image &amp; Status</div>

            <div class="form-group <?= $fieldClass('image') ?>">
                <label>Product Image</label>
                <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" data-preview="imagePreview">
                <span class="hint">JPG, PNG, WEBP or GIF · max 2 MB</span>
                <?= field_error($errors, 'image') ?>

                <div class="image-preview" <?= $imageUrl ? '' : 'hidden' ?>>
                    <img id="imagePreview" src="<?= e($imageUrl ?? '') ?>" alt="Preview">
                    <?php if ($isEdit && $imageUrl): ?>
                        <label><input type="checkbox" name="remove_image" value="1"> Remove current image</label>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="active" <?= selected($data['status'], 'active') ?>>Active</option>
                    <option value="inactive" <?= selected($data['status'], 'inactive') ?>>Inactive</option>
                </select>
            </div>

        </div>

        <div class="modal-actions">
            <a href="index.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-<?= $isEdit ? 'floppy-disk' : 'plus' ?>"></i>
                <?= $isEdit ? 'Save Changes' : 'Add Product' ?>
            </button>
        </div>
    </form>
</div>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
