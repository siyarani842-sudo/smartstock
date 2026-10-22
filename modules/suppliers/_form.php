<?php
/* =========================================================
   SUPPLIERS - ADD / EDIT FORM
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

$mode   = $mode ?? 'add';
$isEdit = $mode === 'edit';
$id     = (int) get('id', 0);

$data = [
    'name' => '', 'supply_type' => '', 'contact_person' => '', 'email' => '', 'phone' => '',
    'address' => '', 'lead_time_days' => 7, 'rating' => '4.0', 'quality_score' => '90', 'status' => 'active',
];

if ($isEdit) {
    $existing = db_one("SELECT * FROM suppliers WHERE id = ?", [$id]);
    if (!$existing) {
        flash('Supplier not found.', 'error');
        redirect('modules/suppliers/index.php');
    }
    $data = array_merge($data, $existing);
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach (['name', 'supply_type', 'contact_person', 'email', 'phone', 'address', 'lead_time_days', 'rating', 'quality_score'] as $f) {
        $data[$f] = post($f);
    }
    $data['status'] = post('status') === 'inactive' ? 'inactive' : 'active';

    if ($data['name'] === '') {
        $errors['name'] = 'Supplier name is required.';
    } elseif (db_value("SELECT COUNT(*) FROM suppliers WHERE name = ? AND id <> ?", [$data['name'], $id])) {
        $errors['name'] = 'A supplier with this name already exists.';
    }

    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }

    if (!ctype_digit((string) $data['lead_time_days']) || (int) $data['lead_time_days'] > 365) {
        $errors['lead_time_days'] = 'Lead time must be 0-365 days.';
    }

    if (!is_numeric($data['rating']) || $data['rating'] < 0 || $data['rating'] > 5) {
        $errors['rating'] = 'Rating must be between 0 and 5.';
    }

    if (!is_numeric($data['quality_score']) || $data['quality_score'] < 0 || $data['quality_score'] > 100) {
        $errors['quality_score'] = 'Quality score must be between 0 and 100.';
    }

    if (!$errors) {
        $values = [
            $data['name'], $data['supply_type'] ?: null, $data['contact_person'] ?: null,
            $data['email'] ?: null, $data['phone'] ?: null, $data['address'] ?: null,
            (int) $data['lead_time_days'], (float) $data['rating'], (float) $data['quality_score'], $data['status'],
        ];

        if ($isEdit) {
            $values[] = $id;
            db_query(
                "UPDATE suppliers SET name = ?, supply_type = ?, contact_person = ?, email = ?, phone = ?, address = ?,
                        lead_time_days = ?, rating = ?, quality_score = ?, status = ?
                  WHERE id = ?",
                $values
            );
            flash('Supplier updated successfully.');
        } else {
            db_query(
                "INSERT INTO suppliers (name, supply_type, contact_person, email, phone, address,
                                        lead_time_days, rating, quality_score, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                $values
            );
            flash('Supplier added successfully.');
        }
        redirect('modules/suppliers/index.php');
    }
}

$pageTitle    = $isEdit ? 'Edit Supplier' : 'Add Supplier';
$pageSubtitle = $isEdit ? 'Update supplier details' : 'Register a new supplier';
$activePage   = 'suppliers';

include ROOT_PATH . 'includes/header.php';

$fieldClass = fn($f) => isset($errors[$f]) ? 'has-error' : '';
?>

<div class="section-header">
    <div>
        <span class="section-label">PARTNERS</span>
        <h2><?= e($pageTitle) ?></h2>
        <p><?= $isEdit ? 'Editing “' . e($data['name']) . '”' : 'Fill in the details below.' ?></p>
    </div>
    <a href="index.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to list</a>
</div>

<div class="card form-card">
    <form method="post" novalidate>
        <?= csrf_field() ?>

        <div class="form-grid">

            <div class="form-section-title">Company</div>

            <div class="form-group <?= $fieldClass('name') ?>">
                <label>Supplier Name <span class="req">*</span></label>
                <input type="text" name="name" maxlength="150" value="<?= e($data['name']) ?>" placeholder="e.g. TechSource Ltd.">
                <?= field_error($errors, 'name') ?>
            </div>

            <div class="form-group">
                <label>Supply Type</label>
                <input type="text" name="supply_type" maxlength="100" value="<?= e($data['supply_type']) ?>" placeholder="e.g. Electronics Supplier">
            </div>

            <div class="form-group">
                <label>Contact Person</label>
                <input type="text" name="contact_person" maxlength="100" value="<?= e($data['contact_person']) ?>">
            </div>

            <div class="form-group <?= $fieldClass('email') ?>">
                <label>Email</label>
                <input type="email" name="email" maxlength="150" value="<?= e($data['email']) ?>">
                <?= field_error($errors, 'email') ?>
            </div>

            <div class="form-group">
                <label>Phone</label>
                <input type="text" name="phone" maxlength="30" value="<?= e($data['phone']) ?>">
            </div>

            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="active" <?= selected($data['status'], 'active') ?>>Active</option>
                    <option value="inactive" <?= selected($data['status'], 'inactive') ?>>Inactive</option>
                </select>
            </div>

            <div class="form-group full">
                <label>Address</label>
                <input type="text" name="address" maxlength="255" value="<?= e($data['address']) ?>">
            </div>

            <div class="form-section-title">Performance</div>

            <div class="form-group <?= $fieldClass('lead_time_days') ?>">
                <label>Lead Time (days)</label>
                <input type="number" name="lead_time_days" min="0" max="365" value="<?= e($data['lead_time_days']) ?>">
                <span class="hint">Used by AI predictions to time reorders.</span>
                <?= field_error($errors, 'lead_time_days') ?>
            </div>

            <div class="form-group <?= $fieldClass('rating') ?>">
                <label>Rating (0 - 5)</label>
                <input type="number" name="rating" min="0" max="5" step="0.1" value="<?= e($data['rating']) ?>">
                <?= field_error($errors, 'rating') ?>
            </div>

            <div class="form-group <?= $fieldClass('quality_score') ?>">
                <label>Quality Score (%)</label>
                <input type="number" name="quality_score" min="0" max="100" step="0.1" value="<?= e($data['quality_score']) ?>">
                <?= field_error($errors, 'quality_score') ?>
            </div>

        </div>

        <div class="modal-actions">
            <a href="index.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-<?= $isEdit ? 'floppy-disk' : 'plus' ?>"></i>
                <?= $isEdit ? 'Save Changes' : 'Add Supplier' ?>
            </button>
        </div>
    </form>
</div>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
