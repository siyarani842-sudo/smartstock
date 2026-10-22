<?php
/* =========================================================
   INVENTORY - FULL SYNC (POST only)
   Re-checks every order against its status and rebuilds the
   inventory totals from the stock ledger.
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/inventory/index.php');
}

$result = sync_all_inventory();

$changes = $result['applied'] + $result['reverted'] + $result['updated'];

flash(
    "Inventory synchronized. Checked {$result['checked']} orders"
    . ($changes ? ": {$result['applied']} applied, {$result['updated']} updated, {$result['reverted']} reversed." : ', everything was already up to date.')
);

foreach (array_slice($result['errors'], 0, 3) as $error) {
    flash($error, 'warning');
}

redirect('modules/inventory/index.php');
