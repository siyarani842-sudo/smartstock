<?php
/* =========================================================
   CATEGORIES - DELETE (POST only)
   Products in this category are kept and become "Uncategorised".
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/categories/index.php');
}

$id       = (int) post('id');
$category = db_one("SELECT * FROM categories WHERE id = ?", [$id]);

if (!$category) {
    flash('Category not found.', 'error');
} else {
    $count = (int) db_value("SELECT COUNT(*) FROM products WHERE category_id = ?", [$id]);
    db_query("DELETE FROM categories WHERE id = ?", [$id]);
    flash('Category "' . $category['name'] . '" deleted.' . ($count ? " $count product(s) are now uncategorised." : ''));
}

redirect('modules/categories/index.php');
