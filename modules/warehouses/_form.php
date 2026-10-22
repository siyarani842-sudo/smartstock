<?php
/* =========================================================
   WAREHOUSES - ADD / EDIT FORM
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

$mode   = $mode ?? 'add';
$isEdit = $mode === 'edit';
$id     = (int) get('id', 0);

$data = [
    'name' => '', 'code' => '', 'location' => '', 'manager' => '',
    'phone' => '', 'capacity' => 0, 'status' => 'active',
];

if ($isEdit) {
    $existing = db_one("SELECT * FROM warehouses WHERE id = ?", [$id]);
    if (!$existing) {
        flash('Warehouse not found.', 'error');
        redirect('modules/warehouses/index.php');
    }
    $data = array_merge($data, $existing);
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach (['name', 'code', 'location', 'manager', 'phone'] as $f) {
        $data[$f] = post($f);
    }
    $data['code']     = strtoupper($data['code']);
    $data['capacity'] = post('capacity', '0');
    $data['status']   = post('status') === 'inactive' ? 'inactive' : 'active';

    if ($data['name'] === '') {
        $errors['name'] = 'Warehouse name is required.';
    } elseif (db_value("SELECT COUNT(*) FROM warehouses WHERE name = ? AND id <> ?", [$data['name'], $id])) {
        $errors['name'] = 'This warehouse name already exists.';
    }

    if ($data['code'] === '') {
        $errors['code'] = 'Code is required.';
    } elseif (!preg_match('/^[A-Z0-9\-]{2,20}$/', $data['code'])) {
        $errors['code'] = 'Use 2-20 letters, numbers or dashes.';
    } elseif (db_value("SELECT COUNT(*) FROM warehouses WHERE code = ? AND id <> ?", [$data['code'], $id])) {
        $errors['code'] = 'This code is already used.';
    }

    if (!ctype_digit((string) $data['capacity'])) {
        $errors['capacity'] = 'Capacity must be a whole number (0 = no limit).';
    }

    if (!$errors) {
        $values = [
            $data['name'], $data['code'], $data['location'] ?: null, $data['manager'] ?: null,
            $data['phone'] ?: null, (int) $data['capacity'], $data['status'],
        ];

        if ($isEdit) {
            $values[] = $id;
            db_query(
                "UPDATE warehouses SET name = ?, code = ?, location = ?, manager = ?, phone = ?, capacity = ?, status = ?
                  WHERE id = ?",
                $values
            );
            flash('Warehouse updated successfully.');
        } else {
            db_query(
                "INSERT INTO warehouses (name, code, location, manager, phone, capacity, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                $values
            );
            flash('Warehouse added successfully.');
        }
        redirect('modules/warehouses/index.php');
    }
}

$pageTitle    = $isEdit ? 'Edit Warehouse' : 'Add Warehouse';
$pageSubtitle = $isEdit ? 'Update warehouse details' : 'Create a new storage location';
$activePage   = 'warehouses';

include ROOT_PATH . 'includes/header.php';
?>

<div class="section-header">
    <div>
        <span class="section-label">LOCATIONS</span>
        <h2><?= e($pageTitle) ?></h2>
        <p><?= $isEdit ? 'Editing “' . e($data['name']) . '”' : 'Fill in the details below.' ?></p>
    </div>
    <a href="index.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to list</a>
</div>

<div class="card form-card">
    <form method="post" novalidate>
        <?= csrf_field() ?>

        <div class="form-grid">

            <div class="form-group <?= isset($errors['name']) ? 'has-error' : '' ?>">
                <label>Warehouse Name <span class="req">*</span></label>
                <input type="text" name="name" maxlength="100" value="<?= e($data['name']) ?>" placeholder="e.g. Warehouse A">
                <?= field_error($errors, 'name') ?>
            </div>

            <div class="form-group <?= isset($errors['code']) ? 'has-error' : '' ?>">
                <label>Code <span class="req">*</span></label>
                <input type="text" name="code" maxlength="20" value="<?= e($data['code']) ?>" placeholder="e.g. WH-A">
                <?= field_error($errors, 'code') ?>
            </div>

            <div class="form-group full">
                <label>Location / Address</label>
                <input type="text" name="location" maxlength="255" value="<?= e($data['location']) ?>" placeholder="City, address">
            </div>

            <div class="form-group">
                <label>Manager</label>
                <input type="text" name="manager" maxlength="100" value="<?= e($data['manager']) ?>">
            </div>

            <div class="form-group">
                <label>Phone</label>
                <input type="text" name="phone" maxlength="30" value="<?= e($data['phone']) ?>">
            </div>

            <div class="form-group <?= isset($errors['capacity']) ? 'has-error' : '' ?>">
                <label>Capacity (units)</label>
                <input type="number" name="capacity" min="0" step="1" value="<?= e($data['capacity']) ?>">
                <span class="hint">0 = no limit</span>
                <?= field_error($errors, 'capacity') ?>
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
                <?= $isEdit ? 'Save Changes' : 'Add Warehouse' ?>
            </button>
        </div>
    </form>
</div>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
