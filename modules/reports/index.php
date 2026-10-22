<?php
/* =========================================================
   REPORTS & ANALYTICS
   Every "Generate Report" button downloads a CSV (opens in Excel).
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

$pageTitle    = 'Reports & Analytics';
$pageSubtitle = 'Generate insights from your supply chain data';
$activePage   = 'reports';

$overview = business_overview();

/* Monthly purchases vs sales (last 6 months incl. current) */
$monthly = db_all(
    "SELECT DATE_FORMAT(order_date, '%Y-%m') AS ym,
            SUM(CASE WHEN order_type = 'purchase' THEN total_value ELSE 0 END) AS purchases,
            SUM(CASE WHEN order_type = 'sale' THEN total_value ELSE 0 END) AS sales,
            SUM(order_type = 'purchase') AS po_count,
            SUM(order_type = 'sale') AS so_count
       FROM orders
      WHERE status <> 'Cancelled' AND order_date >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH, '%Y-%m-01')
      GROUP BY DATE_FORMAT(order_date, '%Y-%m')
      ORDER BY ym DESC"
);

/* Top selling products (last 90 days) */
$topProducts = db_all(
    "SELECT p.id, p.name, p.sku, -SUM(m.quantity) AS units, -SUM(m.quantity) * p.price AS revenue
       FROM stock_movements m JOIN products p ON p.id = m.product_id
      WHERE m.movement_type = 'sale' AND m.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
      GROUP BY p.id, p.name, p.sku, p.price
      ORDER BY units DESC
      LIMIT 5"
);

$reports = [
    ['type' => 'inventory', 'icon' => 'fa-boxes-stacked', 'color' => 'blue',   'title' => 'Inventory Report',      'text' => 'Complete inventory and stock valuation by warehouse.'],
    ['type' => 'forecast',  'icon' => 'fa-brain',         'color' => 'purple', 'title' => 'AI Forecast Report',    'text' => 'Demand rates, days of stock left and reorder recommendations.'],
    ['type' => 'suppliers', 'icon' => 'fa-truck',         'color' => 'green',  'title' => 'Supplier Report',       'text' => 'Supplier performance, on-time delivery and spend.'],
    ['type' => 'sales',     'icon' => 'fa-chart-line',    'color' => 'orange', 'title' => 'Sales & Demand Report', 'text' => 'Units sold and revenue per product per month.'],
    ['type' => 'orders',    'icon' => 'fa-cart-shopping', 'color' => 'blue',   'title' => 'Orders Report',         'text' => 'Every purchase and sales order with status and value.'],
    ['type' => 'movements', 'icon' => 'fa-list',          'color' => 'purple', 'title' => 'Stock Ledger Report',   'text' => 'All stock movements (in, out and adjustments).'],
];

include ROOT_PATH . 'includes/header.php';
?>

<div class="section-header">
    <div>
        <span class="section-label">ANALYTICS</span>
        <h2>Reports &amp; Analytics</h2>
        <p>Generate insights from your supply chain data. Reports download as CSV (opens in Excel).</p>
    </div>
    <a class="btn btn-primary" id="downloadReport" href="export.php?type=summary">
        <i class="fa-solid fa-download"></i> Download Report
    </a>
</div>

<div class="report-grid">
    <?php foreach ($reports as $r): ?>
        <div class="card report-card">
            <div class="report-icon <?= $r['color'] ?>"><i class="fa-solid <?= $r['icon'] ?>"></i></div>
            <h3><?= e($r['title']) ?></h3>
            <p><?= e($r['text']) ?></p>
            <a class="link-btn" href="export.php?type=<?= $r['type'] ?>">Generate Report →</a>
        </div>
    <?php endforeach; ?>
</div>

<div class="card report-overview">
    <div class="card-header">
        <div>
            <h3>Business Overview</h3>
            <p>Key performance indicators calculated from your data.</p>
        </div>
    </div>
    <div class="overview-grid">
        <div>
            <span>Inventory Turnover (12 mo)</span>
            <strong><?= $overview['turnover'] !== null ? $overview['turnover'] . 'x' : 'N/A' ?></strong>
        </div>
        <div>
            <span>Order Fulfillment</span>
            <strong><?= $overview['fulfillment'] !== null ? $overview['fulfillment'] . '%' : 'N/A' ?></strong>
        </div>
        <div>
            <span>Supplier Reliability</span>
            <strong><?= $overview['reliability'] !== null ? $overview['reliability'] . '%' : 'N/A' ?></strong>
        </div>
        <div>
            <span>Forecast Accuracy</span>
            <strong><?= $overview['accuracy'] !== null ? $overview['accuracy'] . '%' : 'N/A' ?></strong>
        </div>
    </div>
</div>

<div class="dashboard-grid lower-grid mt-20">

    <div class="card table-card">
        <div class="card-header">
            <div>
                <h3>Monthly Purchases vs Sales</h3>
                <p>Order value per month (cancelled excluded)</p>
            </div>
        </div>
        <div class="table-responsive mt-20">
            <table class="data-table" style="min-width:0">
                <thead><tr><th>Month</th><th>Purchases</th><th>Sales</th><th>Orders</th></tr></thead>
                <tbody>
                    <?php if (!$monthly): ?>
                        <tr><td colspan="4" class="empty-state">No orders in the last 6 months.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($monthly as $m): ?>
                        <tr>
                            <td><strong><?= date('M Y', strtotime($m['ym'] . '-01')) ?></strong></td>
                            <td><?= money($m['purchases'], 0) ?></td>
                            <td><?= money($m['sales'], 0) ?></td>
                            <td><?= (int) $m['po_count'] ?> PO · <?= (int) $m['so_count'] ?> SO</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card table-card">
        <div class="card-header">
            <div>
                <h3>Top Selling Products</h3>
                <p>Last 90 days</p>
            </div>
        </div>
        <div class="table-responsive mt-20">
            <table class="data-table" style="min-width:0">
                <thead><tr><th>Product</th><th>Units Sold</th><th>Revenue</th></tr></thead>
                <tbody>
                    <?php if (!$topProducts): ?>
                        <tr><td colspan="3" class="empty-state">No sales in the last 90 days.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($topProducts as $p): ?>
                        <tr>
                            <td><a href="../products/view.php?id=<?= (int) $p['id'] ?>"><strong><?= e($p['name']) ?></strong></a><br><small class="muted"><?= e($p['sku']) ?></small></td>
                            <td><?= num($p['units']) ?></td>
                            <td><?= money($p['revenue'], 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
