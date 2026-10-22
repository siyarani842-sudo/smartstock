<?php
/* =========================================================
   AI PREDICTIONS - DELETE A SAVED RUN (POST only)
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/predictions/index.php');
}

$id = (int) post('id');

if (db_value("SELECT COUNT(*) FROM prediction_runs WHERE id = ?", [$id])) {
    db_query("DELETE FROM prediction_runs WHERE id = ?", [$id]);
    flash('Prediction run deleted.');
} else {
    flash('Prediction run not found.', 'error');
}

redirect('modules/predictions/index.php');
