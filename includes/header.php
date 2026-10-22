<?php
/* =========================================================
   HEADER (layout start)
   Before including this file a page may set:
     $pageTitle     - title shown in the topbar & browser tab
     $pageSubtitle  - small text under the title
     $activePage    - sidebar item to highlight
     $useCharts     - true to load Chart.js
========================================================= */

$pageTitle    = $pageTitle ?? 'Dashboard';
$pageSubtitle = $pageSubtitle ?? '';
$activePage   = $activePage ?? 'dashboard';
$useCharts    = $useCharts ?? false;
$appName      = setting('company_name', 'SmartStock AI');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= e($pageTitle) ?> | <?= e($appName) ?></title>

    <!-- Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <?php if ($useCharts): ?>
        <!-- Chart.js -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <?php endif; ?>

    <!-- App CSS -->
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>

<body>

    <div class="app">

        <?php include __DIR__ . '/sidebar.php'; ?>

        <!-- MOBILE OVERLAY -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- =========================================================
             MAIN
        ========================================================== -->
        <main class="main">

            <?php include __DIR__ . '/topbar.php'; ?>

            <!-- CONTENT -->
            <div class="content">
                <section class="page active">
