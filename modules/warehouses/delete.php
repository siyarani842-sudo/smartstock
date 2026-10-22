<?php
/* =========================================================
   WAREHOUSES - DELETE (POST only)
   Blocked when the warehouse still holds stock or has orders,
   so no inventory is ever lost silently.
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/warehouses/index.php');
}

$id        = (int) post('id');
$warehouse = db_one("SELECT * FROM warehouses WHERE id = ?", [$id]);

if (!$warehouse) {
    flash('Warehouse not found.', 'error');
    redirect('modules/warehouses/index.php');
}

$units  = (int) db_value("SELECT COALESCE(SUM(quantity), 0) FROM inventory WHERE warehouse_id = ?", [$id]);
$orders = (int) db_value("SELECT COUNT(*) FROM orders WHERE warehouse_id = ?", [$id]);

if ($units > 0) {
    flash("Cannot delete \"{$warehouse['name']}\": it still holds {$units} units. Move or adjust the stock first.", 'error');
} elseif ($orders > 0) {
    flash("Cannot delete \"{$warehouse['name']}\": {$orders} order(s) use it. Mark it Inactive instead.", 'error');
} else {
    db_query("DELETE FROM warehouses WHERE id = ?", [$id]);
    flash("Warehouse \"{$warehouse['name']}\" deleted.");
}

redirect('modules/warehouses/index.php');
