<?php
/* =========================================================
   ORDERS - QUICK STATUS CHANGE (POST only)
   Changing status re-syncs inventory automatically.
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/orders/index.php');
}

$id     = (int) post('id');
$status = post('status');
$order  = db_one("SELECT * FROM orders WHERE id = ?", [$id]);

if (!$order || !in_array($status, ['Pending', 'In Transit', 'Delivered', 'Cancelled'], true)) {
    flash('Invalid order or status.', 'error');
    redirect('modules/orders/index.php');
}

try {
    db_begin();

    $deliveredDate = $status === 'Delivered' ? ($order['delivered_date'] ?: date('Y-m-d')) : null;

    db_query(
        "UPDATE orders SET status = ?, delivered_date = ? WHERE id = ?",
        [$status, $deliveredDate, $id]
    );

    $action = sync_order($id);
    db_commit();

    $msg = "Order {$order['order_no']} marked {$status}.";
    if ($action === 'applied') {
        $msg .= $order['order_type'] === 'purchase' ? ' Stock added to inventory.' : ' Stock deducted from inventory.';
    } elseif ($action === 'reverted') {
        $msg .= ' Its stock effect was reversed.';
    }
    flash($msg);

} catch (RuntimeException $e) {
    db_rollback();
    flash($e->getMessage(), 'error');
}

redirect('modules/orders/view.php?id=' . $id);
