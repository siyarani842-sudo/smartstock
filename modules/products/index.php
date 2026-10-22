<?php
/* =========================================================
   PRODUCTS - LIST
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

$pageTitle    = 'Products';
$pageSubtitle = 'Manage your complete product catalog';
$activePage   = 'products';

$search   = get('search');
$category = get('category');
$stock    = get('stock');
$status   = get('status');

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = "(t.name LIKE ? OR t.sku LIKE ? OR t.subtitle LIKE ?)";
    array_push($params, "%$search%", "%$search%", "%$search%");
}
if ($category === 'none') {
    $where[] = "t.category_id IS NULL";
} elseif (ctype_digit($category)) {
    $where[]  = "t.category_id = ?";
    $params[] = (int) $category;
}
if (in_array($status, ['active', 'inactive'], true)) {
    $where[]  = "t.status = ?";
    $params[] = $status;
}

$half = "GREATEST(1, FLOOR(t.reorder_level / 2))";
$stockFilters = [
    'healthy'  => "t.stock > t.reorder_level",
    'low'      => "t.stock > $half AND t.stock <= t.reorder_level",
    'critical' => "t.stock > 0 AND t.stock <= $half",
    'out'      => "t.stock <= 0",
];
if (isset($stockFilters[$stock])) {
    $where[] = $stockFilters[$stock];
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$baseSql = "SELECT p.*, c.name AS category_name, s.name AS supplier_name,
                   COALESCE(inv.qty, 0) AS stock
              FROM products p
              LEFT JOIN categories c ON c.id = p.category_id
              LEFT JOIN suppliers s ON s.id = p.supplier_id
              LEFT JOIN (SELECT product_id, SUM(quantity) AS qty FROM inventory GROUP BY product_id) inv
                     ON inv.product_id = p.id";

$total = (int) db_value("SELECT COUNT(*) FROM ($baseSql) t $whereSql", $params);
$pg    = paginate($total, 10);

$products = db_all(
    "SELECT * FROM ($baseSql) t $whereSql ORDER BY t.name LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
    $params
);

$categories = db_all("SELECT id, name FROM categories ORDER BY name");

$stats = db_one(
    "SELECT COUNT(*) AS total,
            SUM(status = 'active') AS active,
            SUM(created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS new_this_month,
            SUM(created_at >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
                AND created_at < DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS new_last_month
       FROM products"
);
$categoryCount = (int) db_value("SELECT COUNT(*) FROM categories WHERE status = 'active'");
$activePct     = $stats['total'] ? round($stats['active'] / $stats['total'] * 100, 1) : 0;
$growth        = $stats['new_last_month'] ? round(($stats['new_this_month'] - $stats['new_last_month']) / $stats['new_last_month'] * 100, 1) : null;

include ROOT_PATH . 'includes/header.php';
?>

<div class="section-header">
    <div>
        <span class="section-label">CATALOG</span>
        <h2>Products</h2>
        <p>Manage your complete product catalog.</p>
    </div>
    <a href="add.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Product</a>
</div>

<div class="mini-stats">
    <div class="mini-stat">
        <span>Total Products</span>
        <strong><?= num($stats['total']) ?></strong>
        <small>In catalog</small>
    </div>
    <div class="mini-stat">
        <span>Active Products</span>
        <strong><?= num($stats['active']) ?></strong>
        <small><?= $activePct ?>% of catalog</small>
    </div>
    <div class="mini-stat">
        <span>Categories</span>
        <strong><?= num($categoryCount) ?></strong>
        <small>Active categories</small>
    </div>
    <div class="mini-stat">
        <span>New This Month</span>
        <strong><?= num($stats['new_this_month']) ?></strong>
        <?php if ($growth !== null): ?>
            <small class="<?= $growth >= 0 ? 'green-text' : 'red-text' ?>"><?= $growth >= 0 ? '+' : '' ?><?= $growth ?>% vs last month</small>
        <?php else: ?>
            <small>Added this month</small>
        <?php endif; ?>
    </div>
</div>

<div class="card table-card">

    <form class="table-toolbar" method="get">
        <div class="table-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search name, SKU...">
        </div>

        <select name="category" data-autosubmit>
            <option value="">All Categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= selected($category, $c['id']) ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
            <option value="none" <?= selected($category, 'none') ?>>Uncategorised</option>
        </select>

        <select name="stock" data-autosubmit>
            <option value="">All Stock Levels</option>
            <option value="healthy" <?= selected($stock, 'healthy') ?>>Healthy</option>
            <option value="low" <?= selected($stock, 'low') ?>>Low Stock</option>
            <option value="critical" <?= selected($stock, 'critical') ?>>Critical</option>
            <option value="out" <?= selected($stock, 'out') ?>>Out of Stock</option>
        </select>

        <select name="status" data-autosubmit>
            <option value="">Active &amp; Inactive</option>
            <option value="active" <?= selected($status, 'active') ?>>Active</option>
            <option value="inactive" <?= selected($status, 'inactive') ?>>Inactive</option>
        </select>

        <button class="btn btn-secondary" type="submit"><i class="fa-solid fa-sliders"></i> Filter</button>
        <?php if ($search . $category . $stock . $status !== ''): ?>
            <a href="index.php" class="link-btn">Clear</a>
        <?php endif; ?>
    </form>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Category</th>
                    <th>Supplier</th>
                    <th>Stock</th>
                    <th>Price</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$products): ?>
                    <tr>
                        <td colspan="8" class="empty-state"><i class="fa-solid fa-box-open"></i>No products found.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($products as $p):
                    $st  = stock_status((int) $p['stock'], (int) $p['reorder_level']);
                    $img = upload_url('products', $p['image']);
                ?>
                    <tr>
                        <td>
                            <div class="product-cell">
                                <div class="product-thumb <?= color_class((int) $p['id']) ?>">
                                    <?php if ($img): ?>
                                        <img src="<?= e($img) ?>" alt="<?= e($p['name']) ?>">
                                    <?php else: ?>
                                        <i class="fa-solid fa-box"></i>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <strong><a href="view.php?id=<?= (int) $p['id'] ?>"><?= e($p['name']) ?></a></strong>
                                    <span><?= e($p['subtitle'] ?: ($p['category_name'] ?: '—')) ?></span>
                                </div>
                            </div>
                        </td>
                        <td><?= e($p['sku']) ?></td>
                        <td><?= e($p['category_name'] ?: 'Uncategorised') ?></td>
                        <td><?= e($p['supplier_name'] ?: '—') ?></td>
                        <td><strong><?= num($p['stock']) ?></strong> <small class="muted"><?= e($p['unit']) ?></small></td>
                        <td><?= money($p['price']) ?></td>
                        <td>
                            <?= $p['status'] === 'inactive' ? badge('Inactive', 'inactive') : badge($st['label'], $st['class']) ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="icon-btn" href="view.php?id=<?= (int) $p['id'] ?>" title="View"><i class="fa-solid fa-eye"></i></a>
                                <a class="icon-btn" href="edit.php?id=<?= (int) $p['id'] ?>" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <?= delete_button('modules/products/delete.php', (int) $p['id'], 'product "' . $p['name'] . '"') ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= pagination_links($pg) ?>
</div>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
