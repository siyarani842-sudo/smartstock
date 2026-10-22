<?php
/* =========================================================
   PRODUCTS - DELETE (POST only)
   A product that appears in any order cannot be deleted
   (order history must stay intact) - mark it Inactive instead.
   Deleting removes its inventory, stock movements and image.
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/products/index.php');
}

$id      = (int) post('id');
$product = db_one("SELECT * FROM products WHERE id = ?", [$id]);

if (!$product) {
    flash('Product not found.', 'error');
    redirect('modules/products/index.php');
}

$orderCount = (int) db_value("SELECT COUNT(DISTINCT order_id) FROM order_items WHERE product_id = ?", [$id]);

if ($orderCount > 0) {
    flash("Cannot delete \"{$product['name']}\": it is used in {$orderCount} order(s). Set its status to Inactive instead.", 'error');
    redirect('modules/products/index.php');
}

db_begin();
db_query("DELETE FROM stock_movements WHERE product_id = ?", [$id]);
db_query("DELETE FROM inventory WHERE product_id = ?", [$id]);
db_query("DELETE FROM products WHERE id = ?", [$id]);
db_commit();

delete_image('products', $product['image']);

flash("Product \"{$product['name']}\" deleted.");
redirect('modules/products/index.php');
