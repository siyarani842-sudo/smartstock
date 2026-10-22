<?php
/* =========================================================
   INVENTORY - DELETE AN EMPTY STOCK RECORD (POST only)
   Only records with quantity 0 can be removed; stock itself
   is changed through orders or adjustments.
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/inventory/index.php');
}

$id  = (int) post('id');
$row = db_one(
    "SELECT i.*, p.name AS product, w.name AS warehouse
       FROM inventory i JOIN products p ON p.id = i.product_id JOIN warehouses w ON w.id = i.warehouse_id
      WHERE i.id = ?",
    [$id]
);

if (!$row) {
    flash('Stock record not found.', 'error');
} elseif ((int) $row['quantity'] !== 0) {
    flash("Cannot remove: \"{$row['product']}\" still has {$row['quantity']} units in {$row['warehouse']}. Adjust it to 0 first.", 'error');
} else {
    db_query("DELETE FROM inventory WHERE id = ?", [$id]);
    flash("Removed \"{$row['product']}\" from {$row['warehouse']}.");
}

redirect('modules/inventory/index.php');
