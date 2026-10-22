<?php
/* =========================================================
   CATEGORIES - ADD / EDIT FORM
   Used by add.php ($mode = 'add') and edit.php ($mode = 'edit')
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

$mode   = $mode ?? 'add';
$isEdit = $mode === 'edit';
$id     = (int) get('id', 0);

$data = ['name' => '', 'description' => '', 'status' => 'active'];

if ($isEdit) {
    $existing = db_one("SELECT * FROM categories WHERE id = ?", [$id]);
    if (!$existing) {
        flash('Category not found.', 'error');
        redirect('modules/categories/index.php');
    }
    $data = array_merge($data, $existing);
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $data['name']        = post('name');
    $data['description'] = post('description');
    $data['status']      = post('status') === 'inactive' ? 'inactive' : 'active';

    if ($data['name'] === '') {
        $errors['name'] = 'Category name is required.';
    } elseif (mb_strlen($data['name']) > 100) {
        $errors['name'] = 'Maximum 100 characters.';
    } elseif (db_value("SELECT COUNT(*) FROM categories WHERE name = ? AND id <> ?", [$data['name'], $id])) {
        $errors['name'] = 'A category with this name already exists.';
    }

    if (!$errors) {
        if ($isEdit) {
            db_query(
                "UPDATE categories SET name = ?, description = ?, status = ? WHERE id = ?",
                [$data['name'], $data['description'] ?: null, $data['status'], $id]
            );
            flash('Category updated successfully.');
        } else {
            db_query(
                "INSERT INTO categories (name, description, status) VALUES (?, ?, ?)",
                [$data['name'], $data['description'] ?: null, $data['status']]
            );
            flash('Category added successfully.');
        }
        redirect('modules/categories/index.php');
    }
}

$pageTitle    = $isEdit ? 'Edit Category' : 'Add Category';
$pageSubtitle = $isEdit ? 'Update category details' : 'Create a new product category';
$activePage   = 'categories';

include ROOT_PATH . 'includes/header.php';
?>

<div class="section-header">
    <div>
        <span class="section-label">CATALOG</span>
        <h2><?= e($pageTitle) ?></h2>
        <p><?= $isEdit ? 'Editing “' . e($data['name']) . '”' : 'Fill in the details below.' ?></p>
    </div>
    <a href="index.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to list</a>
</div>

<div class="card form-card">
    <form method="post" novalidate>
        <?= csrf_field() ?>

        <div class="form-grid">

            <div class="form-group full <?= isset($errors['name']) ? 'has-error' : '' ?>">
                <label>Category Name <span class="req">*</span></label>
                <input type="text" name="name" maxlength="100" value="<?= e($data['name']) ?>" placeholder="e.g. Electronics" required>
                <?= field_error($errors, 'name') ?>
            </div>

            <div class="form-group full">
                <label>Description</label>
                <textarea name="description" maxlength="255" placeholder="Short description (optional)"><?= e($data['description']) ?></textarea>
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
                <?= $isEdit ? 'Save Changes' : 'Add Category' ?>
            </button>
        </div>
    </form>
</div>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
