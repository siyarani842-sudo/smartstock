<?php
/* =========================================================
   SUPPLIERS - DELETE (POST only)
   Blocked if purchase orders reference the supplier.
   Products keep existing and just lose their preferred supplier.
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/suppliers/index.php');
}

$id       = (int) post('id');
$supplier = db_one("SELECT * FROM suppliers WHERE id = ?", [$id]);

if (!$supplier) {
    flash('Supplier not found.', 'error');
    redirect('modules/suppliers/index.php');
}

$orders = (int) db_value("SELECT COUNT(*) FROM orders WHERE supplier_id = ?", [$id]);

if ($orders > 0) {
    flash("Cannot delete \"{$supplier['name']}\": {$orders} purchase order(s) belong to this supplier. Mark it Inactive instead.", 'error');
} else {
    db_query("DELETE FROM suppliers WHERE id = ?", [$id]);
    flash("Supplier \"{$supplier['name']}\" deleted.");
}

redirect('modules/suppliers/index.php');
