<?php
/* =========================================================
   ANALYTICS HELPERS
   Shared calculations for dashboard, suppliers, predictions
   and reports. Everything is computed from live data.
========================================================= */


/* ---------------------------------------------------------
   SUPPLIERS
--------------------------------------------------------- */

/**
 * Performance figures per supplier, keyed by supplier id.
 * on_time_rate = % of delivered purchase orders that arrived on or before expected_date.
 */
function supplier_performance(): array
{
    $rows = db_all(
        "SELECT s.id,
                COUNT(o.id) AS total_orders,
                SUM(o.status = 'Delivered') AS delivered,
                SUM(o.status IN ('Pending','In Transit')) AS open_orders,
                SUM(o.status = 'Delivered' AND o.expected_date IS NOT NULL) AS measurable,
                SUM(o.status = 'Delivered' AND o.expected_date IS NOT NULL
                    AND o.delivered_date IS NOT NULL AND o.delivered_date <= o.expected_date) AS on_time,
                COALESCE(SUM(CASE WHEN o.status <> 'Cancelled' THEN o.total_value END), 0) AS total_spend,
                MAX(o.order_date) AS last_order
           FROM suppliers s
           LEFT JOIN orders o ON o.supplier_id = s.id AND o.order_type = 'purchase'
          GROUP BY s.id"
    );

    $out = [];
    foreach ($rows as $r) {
        $r['on_time_rate'] = $r['measurable'] > 0 ? round($r['on_time'] / $r['measurable'] * 100, 1) : null;
        $out[(int) $r['id']] = $r;
    }
    return $out;
}

/** Label + badge class from a 0-5 rating */
function supplier_grade(float $rating): array
{
    if ($rating >= 4.7) {
        return ['Excellent', 'excellent'];
    }
    if ($rating >= 4.3) {
        return ['Good', 'healthy'];
    }
    if ($rating >= 3.5) {
        return ['Average', 'warning'];
    }
    return ['Poor', 'critical'];
}


/* ---------------------------------------------------------
   STOCK HISTORY
--------------------------------------------------------- */

/** Total stock units at the end of each of the last $months months (incl. current) */
function monthly_stock_levels(int $months = 7): array
{
    $labels = $data = [];

    for ($i = $months - 1; $i >= 0; $i--) {
        $monthStart = date('Y-m-01', strtotime("-$i month", strtotime(date('Y-m-01'))));
        $end        = $i === 0 ? date('Y-m-d H:i:s') : date('Y-m-t 23:59:59', strtotime($monthStart));

        $labels[] = date('M', strtotime($monthStart));
        $data[]   = (int) db_value("SELECT COALESCE(SUM(quantity), 0) FROM stock_movements WHERE created_at <= ?", [$end]);
    }

    return ['labels' => $labels, 'data' => $data];
}

/** Product counts per stock-health bucket (active products, all warehouses) */
function stock_health_breakdown(): array
{
    $row = db_one(
        "SELECT SUM(t.stock > t.reorder_level) AS healthy,
                SUM(t.stock > GREATEST(1, FLOOR(t.reorder_level / 2)) AND t.stock <= t.reorder_level) AS low,
                SUM(t.stock > 0 AND t.stock <= GREATEST(1, FLOOR(t.reorder_level / 2))) AS critical,
                SUM(t.stock <= 0) AS out_of_stock,
                COUNT(*) AS total
           FROM (SELECT p.id, p.reorder_level, COALESCE(SUM(i.quantity), 0) AS stock
                   FROM products p LEFT JOIN inventory i ON i.product_id = p.id
                  WHERE p.status = 'active'
                  GROUP BY p.id, p.reorder_level) t"
    );

    return array_map('intval', $row ?: ['healthy' => 0, 'low' => 0, 'critical' => 0, 'out_of_stock' => 0, 'total' => 0]);
}

/** Units sold per month for the last $months COMPLETE months: ['Y-m' => units] */
function monthly_demand(int $months = 6): array
{
    $start = date('Y-m-01', strtotime('-' . $months . ' month', strtotime(date('Y-m-01'))));
    $end   = date('Y-m-01');

    $rows = db_all(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, -SUM(quantity) AS units
           FROM stock_movements
          WHERE movement_type = 'sale' AND created_at >= ? AND created_at < ?
          GROUP BY DATE_FORMAT(created_at, '%Y-%m')",
        [$start, $end]
    );
    $byMonth = array_column($rows, 'units', 'ym');

    $out = [];
    for ($i = $months; $i >= 1; $i--) {
        $ym       = date('Y-m', strtotime("-$i month", strtotime(date('Y-m-01'))));
        $out[$ym] = (int) ($byMonth[$ym] ?? 0);
    }
    return $out;
}


/* ---------------------------------------------------------
   DEMAND FORECAST (linear trend + moving average)
--------------------------------------------------------- */

/** Least-squares line through $values. Returns [intercept, slope]. */
function linear_fit(array $values): array
{
    $n = count($values);
    if ($n < 2) {
        return [$n ? (float) reset($values) : 0.0, 0.0];
    }
    $sumX = $sumY = $sumXY = $sumXX = 0;
    foreach (array_values($values) as $x => $y) {
        $sumX  += $x;
        $sumY  += $y;
        $sumXY += $x * $y;
        $sumXX += $x * $x;
    }
    $den   = $n * $sumXX - $sumX * $sumX;
    $slope = $den ? ($n * $sumXY - $sumX * $sumY) / $den : 0;
    return [($sumY - $slope * $sumX) / $n, $slope];
}

/**
 * Forecast monthly demand.
 * Returns labels, actual (history + nulls), forecast (nulls + future),
 * accuracy (% or null), growth (% next month vs last month).
 */
function demand_forecast(int $historyMonths = 6, ?int $horizon = null): array
{
    $horizon = $horizon ?? max(1, min(12, (int) setting('forecast_horizon', 6)));
    $history = monthly_demand($historyMonths);
    $values  = array_values($history);
    $n       = count($values);

    [$a, $b] = linear_fit($values);
    $ma3     = $n ? array_sum(array_slice($values, -3)) / min(3, $n) : 0;

    /* 70% trend, 30% recent average - smooths out noisy months */
    $predict = fn($x) => max(0, round(0.7 * ($a + $b * $x) + 0.3 * $ma3));

    $labels = $actual = $forecast = [];
    foreach ($history as $ym => $units) {
        $labels[]   = date('M y', strtotime($ym . '-01'));
        $actual[]   = $units;
        $forecast[] = null;
    }
    /* connect the lines at the last actual point */
    if ($n) {
        $forecast[$n - 1] = $values[$n - 1];
    }

    $future = [];
    for ($k = 0; $k < $horizon; $k++) {
        $labels[]   = date('M y', strtotime("+$k month", strtotime(date('Y-m-01'))));
        $actual[]   = null;
        $value      = $predict($n + $k);
        $forecast[] = $value;
        $future[]   = $value;
    }

    /* Accuracy = 100 - mean absolute % error of the model on past months */
    $errors = [];
    foreach ($values as $x => $y) {
        if ($y > 0) {
            $errors[] = abs($y - $predict($x)) / $y;
        }
    }
    $accuracy = count($errors) >= 3 ? max(0, min(100, round(100 - array_sum($errors) / count($errors) * 100, 1))) : null;

    $last   = $n ? $values[$n - 1] : 0;
    $growth = $last > 0 && $future ? round(($future[0] - $last) / $last * 100, 1) : null;

    return [
        'labels'       => $labels,
        'actual'       => $actual,
        'forecast'     => $forecast,
        'future'       => $future,
        'history'      => $history,
        'accuracy'     => $accuracy,
        'growth'       => $growth,
        'total_future' => array_sum($future),
    ];
}


/* ---------------------------------------------------------
   PER-PRODUCT REORDER RECOMMENDATIONS
--------------------------------------------------------- */

/**
 * For every active product: demand rate, days until stock-out and a
 * recommended purchase quantity. Sorted by urgency.
 */
function product_forecasts(): array
{
    $safetyDays   = max(0, (int) setting('safety_stock_days', 7));
    $coverageDays = max(1, (int) setting('coverage_days', 30));

    $rows = db_all(
        "SELECT p.id, p.name, p.sku, p.cost_price, p.price, p.reorder_level, p.supplier_id,
                s.name AS supplier_name, COALESCE(s.lead_time_days, 7) AS lead_time,
                c.name AS category_name,
                COALESCE(st.stock, 0) AS stock,
                COALESCE(rs.qty, 0) AS reserved,
                COALESCE(inc.qty, 0) AS incoming,
                COALESCE(s30.sold, 0) AS sold30,
                COALESCE(s90.sold, 0) AS sold90
           FROM products p
           LEFT JOIN suppliers s ON s.id = p.supplier_id
           LEFT JOIN categories c ON c.id = p.category_id
           LEFT JOIN (SELECT product_id, SUM(quantity) AS stock FROM inventory GROUP BY product_id) st
                  ON st.product_id = p.id
           LEFT JOIN (SELECT oi.product_id, SUM(oi.quantity) AS qty FROM order_items oi JOIN orders o ON o.id = oi.order_id
                       WHERE o.order_type = 'sale' AND o.status = 'Pending' GROUP BY oi.product_id) rs
                  ON rs.product_id = p.id
           LEFT JOIN (SELECT oi.product_id, SUM(oi.quantity) AS qty FROM order_items oi JOIN orders o ON o.id = oi.order_id
                       WHERE o.order_type = 'purchase' AND o.status IN ('Pending','In Transit') GROUP BY oi.product_id) inc
                  ON inc.product_id = p.id
           LEFT JOIN (SELECT product_id, -SUM(quantity) AS sold FROM stock_movements
                       WHERE movement_type = 'sale' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                       GROUP BY product_id) s30 ON s30.product_id = p.id
           LEFT JOIN (SELECT product_id, -SUM(quantity) AS sold FROM stock_movements
                       WHERE movement_type = 'sale' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                       GROUP BY product_id) s90 ON s90.product_id = p.id
          WHERE p.status = 'active'"
    );

    $out = [];
    foreach ($rows as $r) {
        $daily     = 0.6 * ($r['sold30'] / 30) + 0.4 * ($r['sold90'] / 90);
        $available = max(0, (int) $r['stock'] - (int) $r['reserved']);
        $lead      = (int) $r['lead_time'];
        $safety    = $daily * $safetyDays;
        $reorderAt = max((int) $r['reorder_level'], (int) ceil($daily * $lead + $safety));
        $daysLeft  = $daily > 0 ? $available / $daily : null;

        $target    = $daily * ($lead + $coverageDays) + $safety;
        $target    = max($target, (int) $r['reorder_level'] * 2);
        $recommend = (int) max(0, ceil($target - $available - (int) $r['incoming']));

        $needs = ($available + (int) $r['incoming']) <= $reorderAt && $recommend > 0;

        if ($available <= 0 || ($daysLeft !== null && $daysLeft <= $lead)) {
            $priority = 'high';
        } elseif ($needs) {
            $priority = 'medium';
        } else {
            $priority = 'low';
        }

        /* overstock: more than 120 days of stock (or stock with no sales at all) */
        $overstockUnits = 0;
        if ($daily > 0 && $available > $daily * 120) {
            $overstockUnits = $available - (int) ceil($daily * 120);
        } elseif ($daily == 0 && $available > (int) $r['reorder_level'] * 3) {
            $overstockUnits = $available - (int) $r['reorder_level'] * 3;
        }

        $r += [
            'daily'           => round($daily, 2),
            'available'       => $available,
            'days_left'       => $daysLeft === null ? null : (int) floor($daysLeft),
            'reorder_point'   => $reorderAt,
            'recommended_qty' => $needs ? $recommend : 0,
            'needs_reorder'   => $needs,
            'priority'        => $priority,
            'overstock_units' => $overstockUnits,
            'overstock_value' => round($overstockUnits * (float) $r['cost_price'], 2),
            'order_cost'      => round(($needs ? $recommend : 0) * (float) $r['cost_price'], 2),
        ];
        $out[] = $r;
    }

    $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
    usort($out, function ($x, $y) use ($rank) {
        return [$rank[$x['priority']], $x['days_left'] ?? PHP_INT_MAX] <=> [$rank[$y['priority']], $y['days_left'] ?? PHP_INT_MAX];
    });

    return $out;
}

/** Summary numbers used by Predictions, Dashboard and Run Prediction */
function prediction_summary(?array $forecast = null, ?array $products = null): array
{
    $forecast = $forecast ?? demand_forecast();
    $products = $products ?? product_forecasts();

    $stockouts = count(array_filter($products, fn($p) => $p['days_left'] !== null ? $p['days_left'] <= 14 : $p['available'] <= 0));
    $reorders  = array_filter($products, fn($p) => $p['needs_reorder']);
    $overstock = array_sum(array_column($products, 'overstock_value'));

    return [
        'accuracy'           => $forecast['accuracy'],
        'growth'             => $forecast['growth'],
        'stockouts'          => $stockouts,
        'recommended_orders' => count($reorders),
        'reorder_cost'       => array_sum(array_column($reorders, 'order_cost')),
        /* yearly carrying cost (~25%) of overstocked units = possible saving */
        'savings'            => round($overstock * 0.25, 2),
        'overstock_value'    => $overstock,
    ];
}

/** Save a snapshot of the current prediction (Run Prediction button) */
function save_prediction_run(): int
{
    $forecast = demand_forecast();
    $products = product_forecasts();
    $summary  = prediction_summary($forecast, $products);

    $top = array_slice(array_values(array_filter($products, fn($p) => $p['needs_reorder'])), 0, 20);
    $json = json_encode([
        'labels'          => $forecast['labels'],
        'actual'          => $forecast['actual'],
        'forecast'        => $forecast['forecast'],
        'recommendations' => array_map(fn($p) => [
            'product_id' => (int) $p['id'], 'name' => $p['name'], 'qty' => $p['recommended_qty'],
            'priority' => $p['priority'], 'days_left' => $p['days_left'],
        ], $top),
    ]);

    db_query(
        "INSERT INTO prediction_runs (run_at, horizon_months, accuracy, expected_growth, potential_stockouts,
                                      recommended_orders, estimated_savings, forecast_json, notes)
         VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            count($forecast['future']), (float) ($summary['accuracy'] ?? 0), (float) ($summary['growth'] ?? 0),
            $summary['stockouts'], $summary['recommended_orders'], (float) $summary['savings'], $json,
            'Forecast of ' . num($forecast['total_future']) . ' units over ' . count($forecast['future']) . ' months',
        ]
    );

    return db_insert_id();
}


/* ---------------------------------------------------------
   BUSINESS KPIs (Reports)
--------------------------------------------------------- */

function business_overview(): array
{
    $inventoryValue = (float) db_value(
        "SELECT COALESCE(SUM(i.quantity * p.cost_price), 0) FROM inventory i JOIN products p ON p.id = i.product_id"
    );

    /* cost of goods sold in the last 12 months */
    $cogs = (float) db_value(
        "SELECT COALESCE(-SUM(m.quantity * p.cost_price), 0)
           FROM stock_movements m JOIN products p ON p.id = m.product_id
          WHERE m.movement_type = 'sale' AND m.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)"
    );

    $sales = db_one(
        "SELECT SUM(status <> 'Cancelled') AS valid, SUM(status = 'Delivered') AS delivered
           FROM orders WHERE order_type = 'sale'"
    );

    $perf       = supplier_performance();
    $measurable = array_sum(array_column($perf, 'measurable'));

    return [
        'inventory_value' => $inventoryValue,
        'turnover'        => $inventoryValue > 0 ? round($cogs / $inventoryValue, 1) : null,
        'fulfillment'     => $sales && $sales['valid'] > 0 ? round($sales['delivered'] / $sales['valid'] * 100, 1) : null,
        'reliability'     => $measurable ? round(array_sum(array_column($perf, 'on_time')) / $measurable * 100, 1) : null,
        'accuracy'        => demand_forecast()['accuracy'],
        'cogs'            => $cogs,
    ];
}
