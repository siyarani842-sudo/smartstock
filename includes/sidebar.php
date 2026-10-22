<?php
/* =========================================================
   SIDEBAR
   Highlights the item whose key matches $activePage.
========================================================= */

$navMenu = [
    'MAIN' => [
        'dashboard' => ['label' => 'Dashboard', 'icon' => 'fa-table-cells-large', 'href' => 'index.php'],
        'products'  => ['label' => 'Products',  'icon' => 'fa-box',               'href' => 'modules/products/index.php'],
        'inventory' => ['label' => 'Inventory', 'icon' => 'fa-warehouse',         'href' => 'modules/inventory/index.php'],
        'orders'    => ['label' => 'Orders',    'icon' => 'fa-cart-shopping',     'href' => 'modules/orders/index.php'],
    ],
    'MASTER DATA' => [
        'categories' => ['label' => 'Categories', 'icon' => 'fa-tags',          'href' => 'modules/categories/index.php'],
        'warehouses' => ['label' => 'Warehouses', 'icon' => 'fa-building',      'href' => 'modules/warehouses/index.php'],
        'suppliers'  => ['label' => 'Suppliers',  'icon' => 'fa-truck-fast',    'href' => 'modules/suppliers/index.php'],
    ],
    'ANALYTICS' => [
        'predictions' => ['label' => 'AI Predictions', 'icon' => 'fa-brain',        'href' => 'modules/predictions/index.php', 'pill' => 'AI'],
        'reports'     => ['label' => 'Reports',        'icon' => 'fa-chart-column', 'href' => 'modules/reports/index.php'],
    ],
    'SYSTEM' => [
        'settings' => ['label' => 'Settings', 'icon' => 'fa-gear', 'href' => 'modules/settings/index.php'],
    ],
];

$navAdminName = setting('admin_name', 'Admin User');
$navAdminRole = setting('admin_role', 'Administrator');
?>
<!-- =========================================================
     SIDEBAR
========================================================== -->
<aside class="sidebar" id="sidebar">

    <a href="<?= e(url('index.php')) ?>" class="sidebar-logo">
        <div class="logo-mark">
            <i class="fa-solid fa-boxes-stacked"></i>
        </div>
        <div class="logo-text">
            <h2>SmartStock<span>AI</span></h2>
            <p>Supply Chain Intelligence</p>
        </div>
    </a>

    <div class="sidebar-scroll">
        <nav class="sidebar-nav">

            <?php foreach ($navMenu as $navGroup => $navItems): ?>
                <div class="nav-group">
                    <div class="nav-label"><?= e($navGroup) ?></div>

                    <?php foreach ($navItems as $navKey => $navItem): ?>
                        <a href="<?= e(url($navItem['href'])) ?>"
                            class="nav-link<?= $activePage === $navKey ? ' active' : '' ?>">
                            <i class="fa-solid <?= e($navItem['icon']) ?>"></i>
                            <span><?= e($navItem['label']) ?></span>
                            <?php if (!empty($navItem['pill'])): ?>
                                <span class="ai-pill"><?= e($navItem['pill']) ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

        </nav>
    </div>

    <!-- SIDEBAR BOTTOM -->
    <div class="sidebar-bottom">

        <div class="ai-status">
            <span class="online-dot"></span>
            <div>
                <strong>AI Engine Online</strong>
                <small>
                    <?php
                    $navLastRun = db_value("SELECT MAX(run_at) FROM prediction_runs", [], null);
                    echo $navLastRun ? 'Updated ' . e(fmt_date($navLastRun, 'M j, H:i')) : 'No prediction run yet';
                    ?>
                </small>
            </div>
        </div>

        <div class="sidebar-user">
            <div class="user-avatar"><?= e(initials($navAdminName)) ?></div>
            <div class="user-details">
                <strong><?= e($navAdminName) ?></strong>
                <span><?= e($navAdminRole) ?></span>
            </div>
            <a class="user-menu" href="<?= e(url('modules/settings/index.php')) ?>" title="Settings">
                <i class="fa-solid fa-ellipsis"></i>
            </a>
        </div>

    </div>

</aside>
