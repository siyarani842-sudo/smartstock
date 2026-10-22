<?php
/* =========================================================
   AI PREDICTIONS - RUN (POST only)
   Syncs inventory, recalculates the forecast and stores a
   snapshot in prediction_runs.
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/predictions/index.php');
}

sync_all_inventory();
save_prediction_run();

$latest = db_one("SELECT * FROM prediction_runs ORDER BY id DESC LIMIT 1");

flash(
    'AI prediction completed: ' . (int) $latest['recommended_orders'] . ' reorder(s) recommended, '
    . (int) $latest['potential_stockouts'] . ' potential stockout(s).'
);

redirect('modules/predictions/index.php');
