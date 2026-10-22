<?php
/* =========================================================
   DASHBOARD
   Every number on this page is calculated from the database.
========================================================= */
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle    = 'Dashboard';
$pageSubtitle = 'Overview of your inventory and supply chain';
$activePage   = 'dashboard';
$useCharts    = true;

/* ---- Greeting ---- */
$hour      = (int) date('G');
$greeting  = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', trim(setting('admin_name', 'Admin')))[0];

/* ---- KPI: products ---- */
$totalProducts   = (int) db_value("SELECT COUNT(*) FROM products WHERE status = 'active'");
$productsMonthAgo = (int) db_value(
    "SELECT COUNT(*) FROM products WHERE status = 'active' AND created_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
);
$productTrend = $productsMonthAgo > 0 ? round(($totalProducts - $productsMonthAgo) / $productsMonthAgo * 100, 1) : null;

/* ---- KPI: inventory value (now vs 30 days ago, at current cost) ---- */
$inventoryValue = (float) db_value(
    "SELECT COALESCE(SUM(i.quantity * p.cost_price), 0) FROM inventory i JOIN products p ON p.id = i.product_id"
);
$valueMonthAgo = (float) db_value(
    "SELECT COALESCE(SUM(m.quantity * p.cost_price), 0)
       FROM stock_movements m JOIN products p ON p.id = m.product_id
      WHERE m.created_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
);
$valueTrend = $valueMonthAgo > 0 ? round(($inventoryValue - $valueMonthAgo) / $valueMonthAgo * 100, 1) : null;

/* ---- KPI: stock health ---- */
$health   = stock_health_breakdown();
$lowStock = $health['low'] + $health['critical'] + $health['out_of_stock'];

/* ---- Forecast & recommendations ---- */
$forecast = demand_forecast();
$products = product_forecasts();
$summary  = prediction_summary($forecast, $products);

/* ---- Charts ---- */
$stockLevels = monthly_stock_levels(7);
$healthTotal = max(1, $health['total']);
$healthPct   = [
    'healthy'  => round($health['healthy'] / $healthTotal * 100),
    'low'      => round($health['low'] / $healthTotal * 100),
    'critical' => round(($health['critical'] + $health['out_of_stock']) / $healthTotal * 100),
];

$chartData = [
    'inventory'   => $stockLevels,
    'stockHealth' => [
        'labels' => ['Healthy', 'Low Stock', 'Critical', 'Out of Stock'],
        'data'   => [$health['healthy'], $health['low'], $health['critical'], $health['out_of_stock']],
    ],
];

/* ---- AI insights (generated from data) ---- */
$insights = [];

$urgent = array_values(array_filter($products, fn($p) => $p['priority'] === 'high'));
if ($urgent) {
    $p = $urgent[0];
    $insights[] = [
        'icon' => 'fa-arrow-trend-down', 'color' => 'red', 'title' => 'Restock Recommended',
        'text' => $p['available'] <= 0
            ? "{$p['name']} is out of stock. Recommended order: {$p['recommended_qty']} units."
            : "{$p['name']} is expected to reach critical stock in {$p['days_left']} days.",
        'link' => 'modules/orders/add.php?type=purchase&product_id=' . $p['id'] . '&qty=' . max(1, $p['recommended_qty']),
        'cta'  => 'Create order →',
    ];
}

/* category with the biggest demand change: last 30 days vs previous 30 days */
$catTrend = db_one(
    "SELECT t.name, t.recent, t.previous
       FROM (SELECT c.name,
                    COALESCE(-SUM(CASE WHEN m.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN m.quantity END), 0) AS recent,
                    COALESCE(-SUM(CASE WHEN m.created_at <  DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN m.quantity END), 0) AS previous
               FROM stock_movements m
               JOIN products p ON p.id = m.product_id
               JOIN categories c ON c.id = p.category_id
              WHERE m.movement_type = 'sale' AND m.created_at >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
              GROUP BY c.id, c.name) t
      WHERE t.previous > 0
      ORDER BY (t.recent - t.previous) / t.previous DESC
      LIMIT 1"
);
if ($catTrend) {
    $change = round(($catTrend['recent'] - $catTrend['previous']) / $catTrend['previous'] * 100, 1);
    $insights[] = [
        'icon' => 'fa-chart-line', 'color' => 'blue',
        'title' => $change >= 0 ? 'Demand Increase Detected' : 'Demand Slowing Down',
        'text' => "{$catTrend['name']} demand " . ($change >= 0 ? 'increased' : 'changed') . " by {$change}% in the last 30 days.",
        'link' => 'modules/predictions/index.php', 'cta' => 'See prediction →',
    ];
} elseif ($forecast['growth'] !== null) {
    $insights[] = [
        'icon' => 'fa-chart-line', 'color' => 'blue', 'title' => 'Demand Forecast',
        'text' => 'Overall demand is expected to change by ' . $forecast['growth'] . '% next month.',
        'link' => 'modules/predictions/index.php', 'cta' => 'See prediction →',
    ];
}

if ($summary['savings'] > 0) {
    $insights[] = [
        'icon' => 'fa-coins', 'color' => 'green', 'title' => 'Cost Saving Opportunity',
        'text' => 'Reducing overstocked items (' . money_short($summary['overstock_value']) . ' of slow stock) could save about '
            . money_short($summary['savings']) . ' per year in holding costs.',
        'link' => 'modules/predictions/index.php#overstock', 'cta' => 'Review items →',
    ];
} else {
    $openPOs = (int) db_value("SELECT COUNT(*) FROM orders WHERE order_type = 'purchase' AND status IN ('Pending','In Transit')");
    $insights[] = [
        'icon' => 'fa-truck-fast', 'color' => 'green', 'title' => 'Open Purchase Orders',
        'text' => "{$openPOs} purchase order(s) are pending or in transit.",
        'link' => 'modules/orders/index.php?type=purchase', 'cta' => 'Review orders →',
    ];
}

$aiAlertsOn = setting('ai_alerts', '1') === '1';
if (!$aiAlertsOn) {
    $insights = [];
}

/* ---- Recent orders ---- */
$recentOrders = db_all(
    "SELECT o.id, o.order_no, o.order_type, o.status, o.customer_name, o.total_value, s.name AS supplier_name
       FROM orders o LEFT JOIN suppliers s ON s.id = o.supplier_id
      ORDER BY o.order_date DESC, o.id DESC
      LIMIT 5"
);

include __DIR__ . '/includes/header.php';
?>

<div class="welcome-card">
    <div class="welcome-content">
        <span class="eyebrow"><i class="fa-solid fa-wand-magic-sparkles"></i> AI-POWERED INVENTORY INTELLIGENCE</span>
        <h2><?= e($greeting) ?>, <?= e($firstName) ?> 👋</h2>
        <p>Here's what's happening with your inventory today, <?= date('l, M j') ?>.</p>
    </div>
    <a class="btn btn-primary" href="<?= e(url('modules/predictions/index.php')) ?>">
        <i class="fa-solid fa-wand-magic-sparkles"></i> View AI Insights
    </a>
</div>

<!-- KPI -->
<div class="kpi-grid">

    <div class="kpi-card">
        <div class="kpi-header">
            <div class="kpi-icon blue"><i class="fa-solid fa-boxes-stacked"></i></div>
            <?php if ($productTrend !== null): ?>
                <span class="trend <?= $productTrend >= 0 ? 'positive' : 'negative' ?>">
                    <i class="fa-solid fa-arrow-<?= $productTrend >= 0 ? 'up' : 'down' ?>"></i> <?= abs($productTrend) ?>%
                </span>
            <?php endif; ?>
        </div>
        <span class="kpi-label">Total Products</span>
        <strong class="kpi-value"><?= num($totalProducts) ?></strong>
        <small>Compared with 30 days ago</small>
    </div>

    <div class="kpi-card">
        <div class="kpi-header">
            <div class="kpi-icon green"><i class="fa-solid fa-wallet"></i></div>
            <?php if ($valueTrend !== null): ?>
                <span class="trend <?= $valueTrend >= 0 ? 'positive' : 'negative' ?>">
                    <i class="fa-solid fa-arrow-<?= $valueTrend >= 0 ? 'up' : 'down' ?>"></i> <?= abs($valueTrend) ?>%
                </span>
            <?php endif; ?>
        </div>
        <span class="kpi-label">Inventory Value</span>
        <strong class="kpi-value"><?= money($inventoryValue, 0) ?></strong>
        <small>Current stock valuation (cost)</small>
    </div>

    <div class="kpi-card">
        <div class="kpi-header">
            <div class="kpi-icon orange"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <span class="trend <?= $lowStock ? 'negative' : 'positive' ?>"><?= num($health['out_of_stock']) ?> out of stock</span>
        </div>
        <span class="kpi-label">Low Stock Items</span>
        <strong class="kpi-value"><?= num($lowStock) ?></strong>
        <small><a class="link-btn" href="<?= e(url('modules/inventory/index.php?status=attention')) ?>">Requires attention</a></small>
    </div>

    <div class="kpi-card">
        <div class="kpi-header">
            <div class="kpi-icon purple"><i class="fa-solid fa-brain"></i></div>
            <?php if ($summary['accuracy'] !== null): ?>
                <span class="trend <?= $summary['accuracy'] >= 85 ? 'positive' : 'negative' ?>">
                    <?= $summary['accuracy'] >= 90 ? 'Excellent' : ($summary['accuracy'] >= 75 ? 'Good' : 'Learning') ?>
                </span>
            <?php endif; ?>
        </div>
        <span class="kpi-label">Prediction Accuracy</span>
        <strong class="kpi-value"><?= $summary['accuracy'] !== null ? $summary['accuracy'] . '%' : 'N/A' ?></strong>
        <small><?= $summary['accuracy'] !== null ? 'Model fit on past sales' : 'Needs 3+ months of sales' ?></small>
    </div>

</div>

<!-- CHART AREA -->
<div class="dashboard-grid">

    <div class="card chart-card large">
        <div class="card-header">
            <div>
                <h3>Inventory Overview</h3>
                <p>Total stock units over the last 7 months</p>
            </div>
            <a class="link-btn" href="<?= e(url('modules/inventory/movements.php')) ?>">Stock ledger</a>
        </div>
        <div class="chart-wrapper"><canvas id="inventoryChart"></canvas></div>
    </div>

    <div class="card stock-health-card">
        <div class="card-header">
            <div>
                <h3>Stock Health</h3>
                <p>Current inventory condition</p>
            </div>
            <a class="more-btn" href="<?= e(url('modules/inventory/index.php')) ?>"><i class="fa-solid fa-ellipsis"></i></a>
        </div>

        <div class="donut-wrapper">
            <canvas id="stockHealthChart"></canvas>
            <div class="donut-center">
                <strong><?= num($health['total']) ?></strong>
                <span>Products</span>
            </div>
        </div>

        <div class="health-list">
            <div class="health-item">
                <span><i class="health-dot healthy"></i> Healthy</span>
                <strong><?= $healthPct['healthy'] ?>%</strong>
            </div>
            <div class="health-item">
                <span><i class="health-dot warning"></i> Low Stock</span>
                <strong><?= $healthPct['low'] ?>%</strong>
            </div>
            <div class="health-item">
                <span><i class="health-dot critical"></i> Critical / Out</span>
                <strong><?= $healthPct['critical'] ?>%</strong>
            </div>
        </div>
    </div>

</div>

<!-- LOWER GRID -->
<div class="dashboard-grid lower-grid">

    <div class="card">
        <div class="card-header">
            <div>
                <h3><i class="fa-solid fa-wand-magic-sparkles title-icon"></i> AI Insights</h3>
                <p>Smart recommendations from your data</p>
            </div>
            <a class="link-btn" href="<?= e(url('modules/predictions/index.php')) ?>">View all</a>
        </div>

        <div class="insight-list">
            <?php if (!$aiAlertsOn): ?>
                <div class="empty-block">AI alerts are turned off in <a class="link-btn" href="<?= e(url('modules/settings/index.php?tab=notifications')) ?>">Settings</a>.</div>
            <?php elseif (!$insights): ?>
                <div class="empty-block">Add products and orders to get AI insights.</div>
            <?php endif; ?>
            <?php foreach ($insights as $in): ?>
                <div class="insight-item">
                    <div class="insight-icon <?= e($in['color']) ?>"><i class="fa-solid <?= e($in['icon']) ?>"></i></div>
                    <div class="insight-content">
                        <strong><?= e($in['title']) ?></strong>
                        <p><?= e($in['text']) ?></p>
                        <a href="<?= e(url($in['link'])) ?>"><button type="button"><?= e($in['cta']) ?></button></a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h3>Recent Orders</h3>
                <p>Latest purchase &amp; sales orders</p>
            </div>
            <a class="link-btn" href="<?= e(url('modules/orders/index.php')) ?>">View all</a>
        </div>

        <div class="recent-orders">
            <?php if (!$recentOrders): ?>
                <div class="empty-block">No orders yet.</div>
            <?php endif; ?>
            <?php foreach ($recentOrders as $o):
                $map = ['Delivered' => 'delivered', 'Pending' => 'pending', 'In Transit' => 'transit', 'Cancelled' => 'cancelled']; ?>
                <a class="order-item" href="<?= e(url('modules/orders/view.php?id=' . $o['id'])) ?>">
                    <div class="order-icon <?= color_class((int) $o['id']) ?>">
                        <i class="fa-solid fa-<?= $o['order_type'] === 'purchase' ? 'box' : 'cart-shopping' ?>"></i>
                    </div>
                    <div class="order-info">
                        <strong>#<?= e($o['order_no']) ?> · <?= money_short($o['total_value']) ?></strong>
                        <span><?= e($o['order_type'] === 'purchase' ? ($o['supplier_name'] ?: '—') : ($o['customer_name'] ?: 'Walk-in customer')) ?></span>
                    </div>
                    <span class="status <?= $map[$o['status']] ?? 'pending' ?>"><?= e($o['status']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
