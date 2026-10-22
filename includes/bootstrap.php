<?php
/* =========================================================
   BOOTSTRAP
   Every page includes this file first. It starts the session,
   connects to the database and loads all helper functions.
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/analytics.php';

/* Validate CSRF token for every POST request */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}
