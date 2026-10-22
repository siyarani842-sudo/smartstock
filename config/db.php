<?php
/* =========================================================
   SMARTSTOCK AI - DATABASE CONFIGURATION
   Change the values below to match your local server.
========================================================= */

$host     = "localhost";
$port     = 3306;
$username = "root";
$password = "";
$database = "smartstock";

/*
 * BASE_URL is the web path to the project folder (with trailing slash).
 * It is detected automatically. If links/CSS break on your server,
 * set it manually, e.g.  define('BASE_URL', '/smartstock/');
 */
if (!defined('BASE_URL')) {
    $projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    $docRoot     = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
    $base        = '/';
    if ($docRoot !== '' && stripos($projectRoot, $docRoot) === 0) {
        $base = '/' . trim(substr($projectRoot, strlen($docRoot)), '/') . '/';
        $base = str_replace('//', '/', $base);
    }
    define('BASE_URL', $base);
}

/* Absolute filesystem path to the project root */
define('ROOT_PATH', str_replace('\\', '/', realpath(__DIR__ . '/..')) . '/');

/* Throw exceptions on SQL errors so problems are never silent */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = mysqli_connect($host, $username, $password, $database, $port);
    mysqli_set_charset($conn, 'utf8mb4');
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    die("<h3 style='font-family:sans-serif'>Database connection failed</h3>"
        . "<p style='font-family:sans-serif'>" . htmlspecialchars($e->getMessage()) . "</p>"
        . "<p style='font-family:sans-serif'>Check <code>config/db.php</code> and make sure you imported "
        . "<code>database/DATABASE.md</code> queries.</p>");
}
