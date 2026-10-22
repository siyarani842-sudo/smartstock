<?php
/* =========================================================
   REPORTS - CSV EXPORT
   export.php?type=inventory|forecast|suppliers|sales|orders|movements|summary
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

$type = get('type', 'summary');

/** Stream rows as a CSV download */
function send_csv(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '-' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows symbols correctly
    fputcsv($out, $header);
    foreach ($rows as $row) {
        fputcsv($out, array_values($row));
    }
    fclose($out);
    exit;
}

switch ($type) {

    case 'inventory':
        $rows = db_all(
            "SELECT p.sku, p.name AS product, COALESCE(c.name, 'Uncategorised') AS category, w.name AS warehouse, i.quantity, p.reorder_level,
                    p.cost_price, ROUND(i.quantity * p.cost_price, 2), p.price, ROUND(i.quantity * p.price, 2), i.last_synced_at
               FROM inventory i
               JOIN products p ON p.id = i.product_id
               JOIN warehouses w ON w.id = i.warehouse_id
               LEFT JOIN categories c ON c.id = p.category_id
              ORDER BY p.name, w.name"
        );
        send_csv('inventory-report',
            ['SKU', 'Product', 'Category', 'Warehouse', 'Quantity', 'Reorder Level', 'Cost Price', 'Stock Value (Cost)', 'Selling Price', 'Stock Value (Retail)', 'Last Synced'],
            $rows);

    case 'forecast':
        $rows = array_map(fn($p) => [
            $p['sku'], $p['name'], $p['category_name'] ?: 'Uncategorised', $p['stock'], $p['reserved'], $p['incoming'],
            $p['daily'], $p['days_left'] ?? 'No sales', $p['lead_time'], $p['reorder_point'],
            ucfirst($p['priority']), $p['recommended_qty'], $p['order_cost'], $p['supplier_name'] ?: '',
        ], product_forecasts());
        send_csv('ai-forecast-report',
            ['SKU', 'Product', 'Category', 'Stock', 'Reserved', 'Incoming', 'Avg Daily Sales', 'Days of Stock Left', 'Lead Time (days)', 'Reorder Point', 'Priority', 'Recommended Qty', 'Est. Order Cost', 'Supplier'],
            $rows);

    case 'suppliers':
        $perf = supplier_performance();
        $rows = [];
        foreach (db_all("SELECT * FROM suppliers ORDER BY name") as $s) {
            $p = $perf[$s['id']] ?? [];
            $rows[] = [
                $s['name'], $s['supply_type'], $s['contact_person'], $s['email'], $s['phone'], $s['status'],
                $s['rating'], $s['quality_score'], $p['on_time_rate'] ?? 'N/A', $p['total_orders'] ?? 0,
                $p['delivered'] ?? 0, $p['open_orders'] ?? 0, $p['total_spend'] ?? 0, $s['lead_time_days'], $p['last_order'] ?? '',
            ];
        }
        send_csv('supplier-report',
            ['Supplier', 'Type', 'Contact', 'Email', 'Phone', 'Status', 'Rating', 'Quality %', 'On-time %', 'Orders', 'Delivered', 'Open', 'Total Spend', 'Lead Time', 'Last Order'],
            $rows);

    case 'sales':
        $rows = db_all(
            "SELECT DATE_FORMAT(m.created_at, '%Y-%m') AS month, p.sku, p.name AS product, COALESCE(c.name, 'Uncategorised') AS category,
                    -SUM(m.quantity) AS units, ROUND(-SUM(m.quantity) * p.price, 2) AS revenue
               FROM stock_movements m
               JOIN products p ON p.id = m.product_id
               LEFT JOIN categories c ON c.id = p.category_id
              WHERE m.movement_type = 'sale'
              GROUP BY DATE_FORMAT(m.created_at, '%Y-%m'), p.id, p.sku, p.name, c.name, p.price
              ORDER BY month DESC, units DESC"
        );
        send_csv('sales-demand-report', ['Month', 'SKU', 'Product', 'Category', 'Units Sold', 'Revenue (list price)'], $rows);

    case 'orders':
        $rows = db_all(
            "SELECT o.order_no, o.order_type, COALESCE(s.name, o.customer_name, 'Walk-in customer') AS party, w.name AS warehouse, o.status,
                    o.order_date, o.expected_date, o.delivered_date,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS line_count,
                    (SELECT SUM(quantity) FROM order_items oi WHERE oi.order_id = o.id) AS units,
                    o.total_value, IF(o.stock_applied = 1, 'Yes', 'No')
               FROM orders o
               LEFT JOIN suppliers s ON s.id = o.supplier_id
               JOIN warehouses w ON w.id = o.warehouse_id
              ORDER BY o.order_date DESC, o.id DESC"
        );
        send_csv('orders-report',
            ['Order No', 'Type', 'Supplier / Customer', 'Warehouse', 'Status', 'Order Date', 'Expected', 'Delivered', 'Products', 'Units', 'Value', 'Stock Applied'],
            $rows);

    case 'movements':
        $rows = db_all(
            "SELECT m.created_at, p.sku, p.name AS product, w.name AS warehouse, m.movement_type, m.quantity, COALESCE(o.order_no, ''), m.note
               FROM stock_movements m
               JOIN products p ON p.id = m.product_id
               JOIN warehouses w ON w.id = m.warehouse_id
               LEFT JOIN orders o ON o.id = m.order_id
              ORDER BY m.created_at DESC, m.id DESC"
        );
        send_csv('stock-ledger-report', ['Date', 'SKU', 'Product', 'Warehouse', 'Type', 'Quantity', 'Order', 'Note'], $rows);

    default: // summary
        $o       = business_overview();
        $health  = stock_health_breakdown();
        $summary = prediction_summary();
        $rows = [
            ['Report generated', date('Y-m-d H:i')],
            ['Company', setting('company_name', 'SmartStock AI')],
            ['Active products', $health['total']],
            ['Healthy products', $health['healthy']],
            ['Low stock products', $health['low']],
            ['Critical products', $health['critical']],
            ['Out of stock products', $health['out_of_stock']],
            ['Total stock units', db_value("SELECT COALESCE(SUM(quantity), 0) FROM inventory")],
            ['Inventory value (cost)', round($o['inventory_value'], 2)],
            ['Cost of goods sold (12 months)', round($o['cogs'], 2)],
            ['Inventory turnover', $o['turnover'] ?? 'N/A'],
            ['Order fulfillment %', $o['fulfillment'] ?? 'N/A'],
            ['Supplier reliability %', $o['reliability'] ?? 'N/A'],
            ['Forecast accuracy %', $o['accuracy'] ?? 'N/A'],
            ['Expected demand change next month %', $summary['growth'] ?? 'N/A'],
            ['Potential stockouts (14 days)', $summary['stockouts']],
            ['Recommended reorders', $summary['recommended_orders']],
            ['Estimated reorder cost', $summary['reorder_cost']],
            ['Overstock value', $summary['overstock_value']],
            ['Pending orders', db_value("SELECT COUNT(*) FROM orders WHERE status = 'Pending'")],
            ['Orders in transit', db_value("SELECT COUNT(*) FROM orders WHERE status = 'In Transit'")],
        ];
        send_csv('business-summary', ['Metric', 'Value'], $rows);
}
