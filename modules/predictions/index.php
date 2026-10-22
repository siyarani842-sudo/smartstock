<?php
/* =========================================================
   AI PREDICTIONS
   Demand forecast + reorder recommendations, calculated live
   from sales history. "Run Prediction" saves a snapshot.
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

$pageTitle    = 'AI Predictions';
$pageSubtitle = 'Predictive analytics for future demand and inventory';
$activePage   = 'predictions';
$useCharts    = true;

$horizon  = (int) get('horizon', (int) setting('forecast_horizon', 6));
$horizon  = in_array($horizon, [3, 6, 12], true) ? $horizon : 6;

$forecast = demand_forecast(6, $horizon);
$products = product_forecasts();
$summary  = prediction_summary($forecast, $products);

$recommended = array_values(array_filter($products, fn($p) => $p['needs_reorder']));
$overstocked = array_values(array_filter($products, fn($p) => $p['overstock_units'] > 0));
usort($overstocked, fn($a, $b) => $b['overstock_value'] <=> $a['overstock_value']);

$runs = db_all("SELECT * FROM prediction_runs ORDER BY run_at DESC LIMIT 10");

$chartData = ['prediction' => [
    'labels'   => $forecast['labels'],
    'actual'   => $forecast['actual'],
    'forecast' => $forecast['forecast'],
]];

include ROOT_PATH . 'includes/header.php';
?>

<div class="section-header">
    <div>
        <span class="section-label purple-label">ARTIFICIAL INTELLIGENCE</span>
        <h2>AI Predictions</h2>
        <p>Predictive analytics for future demand and inventory.</p>
    </div>
    <form method="post" action="run.php" class="inline-form" data-loading="Running...">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-primary" id="runPredictionBtn">
            <i class="fa-solid fa-rotate"></i> Run Prediction
        </button>
    </form>
</div>

<div class="ai-banner">
    <div class="ai-banner-icon"><i class="fa-solid fa-brain"></i></div>
    <div class="ai-banner-content">
        <div><span class="ai-live"><span></span> MODEL ACTIVE</span></div>
        <h3>
            <?= $summary['accuracy'] !== null
                ? 'AI model is performing at ' . $summary['accuracy'] . '% accuracy'
                : 'AI model is learning - more sales history is needed for an accuracy score' ?>
        </h3>
        <p>
            Predictions use your last 6 months of sales (linear trend blended with a 3-month average),
            current stock, reserved units, incoming purchase orders and supplier lead times.
        </p>
    </div>
    <div class="accuracy">
        <strong><?= $summary['accuracy'] !== null ? $summary['accuracy'] . '%' : 'N/A' ?></strong>
        <span>Accuracy</span>
    </div>
</div>

<div class="prediction-stats">
    <div class="card prediction-stat">
        <span>Expected Demand Change</span>
        <strong><?= $summary['growth'] !== null ? ($summary['growth'] >= 0 ? '+' : '') . $summary['growth'] . '%' : 'N/A' ?></strong>
        <small>Next month vs last month</small>
    </div>
    <div class="card prediction-stat">
        <span>Potential Stockouts</span>
        <strong><?= num($summary['stockouts']) ?></strong>
        <small>Within next 14 days</small>
    </div>
    <div class="card prediction-stat">
        <span>Recommended Orders</span>
        <strong><?= num($summary['recommended_orders']) ?></strong>
        <small>Est. cost <?= money_short($summary['reorder_cost']) ?></small>
    </div>
    <div class="card prediction-stat">
        <span>Estimated Savings</span>
        <strong><?= money_short($summary['savings']) ?></strong>
        <small>Yearly holding cost of overstock</small>
    </div>
</div>

<div class="card chart-card">
    <div class="card-header">
        <div>
            <h3>Demand Forecast</h3>
            <p>Units sold per month (actual) and AI-predicted demand for the next <?= $horizon ?> months
                · <?= num($forecast['total_future']) ?> units forecast</p>
        </div>
        <form method="get">
            <select name="horizon" data-autosubmit>
                <option value="3" <?= selected($horizon, 3) ?>>Next 3 months</option>
                <option value="6" <?= selected($horizon, 6) ?>>Next 6 months</option>
                <option value="12" <?= selected($horizon, 12) ?>>Next 12 months</option>
            </select>
        </form>
    </div>
    <div class="chart-wrapper large-chart"><canvas id="predictionChart"></canvas></div>
</div>

<div class="card recommendations-card">
    <div class="card-header">
        <div>
            <h3>Recommended Actions</h3>
            <p>Prioritised by urgency: days until stock-out vs supplier lead time.</p>
        </div>
    </div>

    <?php if (!$recommended): ?>
        <div class="empty-block"><i class="fa-solid fa-circle-check green-text"></i> All products are sufficiently stocked. No reorders needed.</div>
    <?php endif; ?>

    <?php foreach (array_slice($recommended, 0, 10) as $p): ?>
        <div class="recommendation">
            <div class="recommendation-left">
                <span class="priority <?= $p['priority'] === 'high' ? 'high' : 'medium' ?>">
                    <?= strtoupper($p['priority']) ?> PRIORITY
                </span>
                <h4>Reorder <?= e($p['name']) ?> <small class="muted">(<?= e($p['sku']) ?>)</small></h4>
                <p>
                    <?php if ($p['available'] <= 0): ?>
                        Out of stock.
                    <?php elseif ($p['days_left'] !== null): ?>
                        Expected stock-out in <?= num($p['days_left']) ?> days
                        (lead time <?= (int) $p['lead_time'] ?> days).
                    <?php else: ?>
                        Below reorder level (<?= num($p['available']) ?> available, reorder at <?= num($p['reorder_point']) ?>).
                    <?php endif; ?>
                    Selling ~<?= num($p['daily'], 1) ?>/day.
                    <?= $p['incoming'] > 0 ? num($p['incoming']) . ' already on order.' : '' ?>
                    Recommended quantity: <strong><?= num($p['recommended_qty']) ?> units</strong>
                    (≈ <?= money($p['order_cost'], 0) ?><?= $p['supplier_name'] ? ' from ' . e($p['supplier_name']) : '' ?>).
                </p>
            </div>
            <a class="btn btn-secondary"
                href="../orders/add.php?type=purchase&product_id=<?= (int) $p['id'] ?>&qty=<?= (int) $p['recommended_qty'] ?>">
                Create Order
            </a>
        </div>
    <?php endforeach; ?>

    <?php if (count($recommended) > 10): ?>
        <div class="empty-block">+ <?= count($recommended) - 10 ?> more products need reordering. See the full list in Reports → AI Forecast Report.</div>
    <?php endif; ?>
</div>

<div class="card table-card mt-20" id="overstock">
    <div class="card-header">
        <div>
            <h3>Overstocked Products</h3>
            <p>More than ~4 months of stock at the current sales rate - capital tied up in inventory.</p>
        </div>
    </div>
    <div class="table-responsive mt-20">
        <table class="data-table">
            <thead><tr><th>Product</th><th>Available</th><th>Daily Sales</th><th>Excess Units</th><th>Excess Value</th></tr></thead>
            <tbody>
                <?php if (!$overstocked): ?>
                    <tr><td colspan="5" class="empty-state">No overstocked products.</td></tr>
                <?php endif; ?>
                <?php foreach (array_slice($overstocked, 0, 8) as $p): ?>
                    <tr>
                        <td><a href="../products/view.php?id=<?= (int) $p['id'] ?>"><strong><?= e($p['name']) ?></strong></a></td>
                        <td><?= num($p['available']) ?></td>
                        <td><?= num($p['daily'], 1) ?></td>
                        <td><?= num($p['overstock_units']) ?></td>
                        <td><?= money($p['overstock_value']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card table-card mt-20">
    <div class="card-header">
        <div>
            <h3>Prediction History</h3>
            <p>Snapshots saved with “Run Prediction”.</p>
        </div>
    </div>
    <div class="table-responsive mt-20">
        <table class="data-table">
            <thead>
                <tr><th>Run At</th><th>Horizon</th><th>Accuracy</th><th>Demand Change</th><th>Stockouts</th><th>Reorders</th><th>Savings</th><th>Notes</th><th></th></tr>
            </thead>
            <tbody>
                <?php if (!$runs): ?>
                    <tr><td colspan="9" class="empty-state">No prediction runs yet. Click “Run Prediction”.</td></tr>
                <?php endif; ?>
                <?php foreach ($runs as $r): ?>
                    <tr>
                        <td class="nowrap"><?= fmt_date($r['run_at'], 'M j, Y H:i') ?></td>
                        <td><?= (int) $r['horizon_months'] ?> months</td>
                        <td><?= e($r['accuracy']) ?>%</td>
                        <td><?= $r['expected_growth'] >= 0 ? '+' : '' ?><?= e($r['expected_growth']) ?>%</td>
                        <td><?= num($r['potential_stockouts']) ?></td>
                        <td><?= num($r['recommended_orders']) ?></td>
                        <td><?= money_short($r['estimated_savings']) ?></td>
                        <td class="muted"><?= e($r['notes']) ?></td>
                        <td><div class="row-actions"><?= delete_button('modules/predictions/delete.php', (int) $r['id'], 'this prediction run') ?></div></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
