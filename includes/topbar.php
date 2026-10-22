<?php
/* =========================================================
   TOPBAR
   Shows page title, global search and live notifications.
========================================================= */

$alertCount = count_stock_alerts();
?>
<header class="topbar">

    <div class="topbar-left">

        <button class="mobile-menu" id="menuBtn" type="button">
            <i class="fa-solid fa-bars"></i>
        </button>

        <div class="page-heading">
            <h1 id="pageTitle"><?= e($pageTitle) ?></h1>
            <p id="pageSubtitle"><?= e($pageSubtitle) ?></p>
        </div>

    </div>

    <div class="topbar-right">

        <form class="global-search" method="get" action="<?= e(url('search.php')) ?>">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="globalSearch" name="q"
                value="<?= e($_GET['q'] ?? '') ?>"
                placeholder="Search products, orders, suppliers...">
            <span class="search-shortcut">/</span>
        </form>

        <a class="topbar-icon" id="notificationBtn"
            href="<?= e(url('modules/inventory/index.php?status=attention')) ?>"
            title="<?= $alertCount ?> stock alert(s)">
            <i class="fa-regular fa-bell"></i>
            <?php if ($alertCount > 0): ?>
                <span class="notification-count"><?= $alertCount > 99 ? '99+' : $alertCount ?></span>
            <?php endif; ?>
        </a>

        <a class="topbar-icon" href="<?= e(url('modules/reports/index.php')) ?>" title="Reports">
            <i class="fa-regular fa-circle-question"></i>
        </a>

        <div class="top-user">
            <div class="top-avatar"><?= e(initials(setting('admin_name', 'Admin User'))) ?></div>
            <div>
                <strong><?= e(setting('admin_name', 'Admin User')) ?></strong>
                <span><?= e(setting('admin_role', 'Administrator')) ?></span>
            </div>
        </div>

    </div>

</header>
