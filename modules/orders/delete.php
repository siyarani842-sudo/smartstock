<?php
/* =========================================================
   ORDERS - DELETE (POST only)
   The order's stock effect is reversed first, so inventory
   stays correct. Blocked if reversing would make stock negative.
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/orders/index.php');
}

$id    = (int) post('id');
$order = db_one("SELECT * FROM orders WHERE id = ?", [$id]);

if (!$order) {
    flash('Order not found.', 'error');
    redirect('modules/orders/index.php');
}

try {
    db_begin();
    release_order_stock($id);                       // reverse stock (if any)
    db_query("DELETE FROM orders WHERE id = ?", [$id]); // items & movements cascade
    db_commit();

    flash("Order {$order['order_no']} deleted" . ($order['stock_applied'] ? ' and its stock effect reversed.' : '.'));
    redirect('modules/orders/index.php');

} catch (RuntimeException $e) {
    db_rollback();
    flash($e->getMessage(), 'error');
    redirect('modules/orders/view.php?id=' . $id);
}
