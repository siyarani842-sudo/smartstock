<?php
/* =========================================================
   GLOBAL SEARCH (topbar search box)
========================================================= */
require_once __DIR__ . '/includes/bootstrap.php';

$q = get('q');

$pageTitle    = 'Search';
$pageSubtitle = $q !== '' ? 'Results for “' . $q . '”' : 'Search across the whole system';
$activePage   = '';

$results = ['products' => [], 'orders' => [], 'suppliers' => [], 'categories' => [], 'warehouses' => []];

if ($q !== '') {
    $like = "%$q%";

    $results['products'] = db_all(
        "SELECT id, name, sku, subtitle FROM products WHERE name LIKE ? OR sku LIKE ? OR subtitle LIKE ? ORDER BY name LIMIT 10",
        [$like, $like, $like]
    );
    $results['orders'] = db_all(
        "SELECT o.id, o.order_no, o.order_type, o.status, o.order_date, o.customer_name, s.name AS supplier_name
           FROM orders o LEFT JOIN suppliers s ON s.id = o.supplier_id
          WHERE o.order_no LIKE ? OR o.customer_name LIKE ? OR s.name LIKE ?
          ORDER BY o.order_date DESC LIMIT 10",
        [$like, $like, $like]
    );
    $results['suppliers'] = db_all(
        "SELECT id, name, supply_type, email FROM suppliers WHERE name LIKE ? OR supply_type LIKE ? OR email LIKE ? OR contact_person LIKE ? LIMIT 10",
        [$like, $like, $like, $like]
    );
    $results['categories'] = db_all("SELECT id, name, description FROM categories WHERE name LIKE ? LIMIT 10", [$like]);
    $results['warehouses'] = db_all("SELECT id, name, code, location FROM warehouses WHERE name LIKE ? OR code LIKE ? OR location LIKE ? LIMIT 10", [$like, $like, $like]);

    /* Single exact hit on SKU or order number -> go straight there */
    $exactProduct = db_value("SELECT id FROM products WHERE sku = ?", [$q], null);
    if ($exactProduct) {
        redirect('modules/products/view.php?id=' . $exactProduct);
    }
    $exactOrder = db_value("SELECT id FROM orders WHERE order_no = ? OR order_no = ?", [$q, ltrim($q, '#')], null);
    if ($exactOrder) {
        redirect('modules/orders/view.php?id=' . $exactOrder);
    }
}

$totalHits = array_sum(array_map('count', $results));

include __DIR__ . '/includes/header.php';
?>

<div class="section-header">
    <div>
        <span class="section-label">SEARCH</span>
        <h2><?= $q !== '' ? num($totalHits) . ' result(s) for “' . e($q) . '”' : 'Search' ?></h2>
        <p>Products, orders, suppliers, categories and warehouses.</p>
    </div>
</div>

<?php if ($q === ''): ?>
    <div class="card empty-block">Type something in the search box at the top. Tip: press <strong>/</strong> to focus it.</div>
<?php elseif (!$totalHits): ?>
    <div class="card empty-block">Nothing matched “<?= e($q) ?>”.</div>
<?php endif; ?>

<?php if ($results['products']): ?>
    <div class="card table-card search-group">
        <h3>Products</h3>
        <table class="data-table" style="min-width:0">
            <tbody>
                <?php foreach ($results['products'] as $r): ?>
                    <tr>
                        <td><a href="<?= e(url('modules/products/view.php?id=' . $r['id'])) ?>"><strong><?= e($r['name']) ?></strong></a></td>
                        <td><?= e($r['sku']) ?></td>
                        <td class="muted"><?= e($r['subtitle']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($results['orders']): ?>
    <div class="card table-card search-group">
        <h3>Orders</h3>
        <table class="data-table" style="min-width:0">
            <tbody>
                <?php foreach ($results['orders'] as $r): ?>
                    <tr>
                        <td><a href="<?= e(url('modules/orders/view.php?id=' . $r['id'])) ?>"><strong>#<?= e($r['order_no']) ?></strong></a></td>
                        <td><?= badge(ucfirst($r['order_type']), $r['order_type']) ?></td>
                        <td><?= e($r['supplier_name'] ?: ($r['customer_name'] ?: 'Walk-in customer')) ?></td>
                        <td><?= fmt_date($r['order_date']) ?></td>
                        <td><?= order_status_badge($r['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($results['suppliers']): ?>
    <div class="card table-card search-group">
        <h3>Suppliers</h3>
        <table class="data-table" style="min-width:0">
            <tbody>
                <?php foreach ($results['suppliers'] as $r): ?>
                    <tr>
                        <td><a href="<?= e(url('modules/suppliers/edit.php?id=' . $r['id'])) ?>"><strong><?= e($r['name']) ?></strong></a></td>
                        <td><?= e($r['supply_type']) ?></td>
                        <td class="muted"><?= e($r['email']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($results['categories']): ?>
    <div class="card table-card search-group">
        <h3>Categories</h3>
        <table class="data-table" style="min-width:0">
            <tbody>
                <?php foreach ($results['categories'] as $r): ?>
                    <tr>
                        <td><a href="<?= e(url('modules/products/index.php?category=' . $r['id'])) ?>"><strong><?= e($r['name']) ?></strong></a></td>
                        <td class="muted"><?= e($r['description']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($results['warehouses']): ?>
    <div class="card table-card search-group">
        <h3>Warehouses</h3>
        <table class="data-table" style="min-width:0">
            <tbody>
                <?php foreach ($results['warehouses'] as $r): ?>
                    <tr>
                        <td><a href="<?= e(url('modules/inventory/index.php?warehouse=' . $r['id'])) ?>"><strong><?= e($r['name']) ?></strong></a></td>
                        <td><?= e($r['code']) ?></td>
                        <td class="muted"><?= e($r['location']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
