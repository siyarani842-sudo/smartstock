<?php
/* =========================================================
   CATEGORIES - LIST
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

$pageTitle    = 'Categories';
$pageSubtitle = 'Organise your products into categories';
$activePage   = 'categories';

$search = get('search');
$status = get('status');

$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = "(c.name LIKE ? OR c.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if (in_array($status, ['active', 'inactive'], true)) {
    $where[]  = "c.status = ?";
    $params[] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_value("SELECT COUNT(*) FROM categories c $whereSql", $params);
$pg    = paginate($total, 10);

$categories = db_all(
    "SELECT c.*,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count,
            (SELECT COALESCE(SUM(i.quantity * p.cost_price), 0)
               FROM products p JOIN inventory i ON i.product_id = p.id
              WHERE p.category_id = c.id) AS stock_value
       FROM categories c
       $whereSql
      ORDER BY c.name
      LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
    $params
);

$stats = db_one(
    "SELECT COUNT(*) AS total,
            SUM(status = 'active') AS active,
            (SELECT COUNT(*) FROM products WHERE category_id IS NULL) AS uncategorised
       FROM categories"
);

include ROOT_PATH . 'includes/header.php';
?>

<div class="section-header">
    <div>
        <span class="section-label">CATALOG</span>
        <h2>Categories</h2>
        <p>Group products so they are easier to filter and report on.</p>
    </div>
    <a href="add.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Category</a>
</div>

<div class="mini-stats">
    <div class="mini-stat">
        <span>Total Categories</span>
        <strong><?= num($stats['total']) ?></strong>
        <small>In your catalog</small>
    </div>
    <div class="mini-stat">
        <span>Active</span>
        <strong><?= num($stats['active']) ?></strong>
        <small class="green-text">Visible in product forms</small>
    </div>
    <div class="mini-stat">
        <span>Inactive</span>
        <strong><?= num($stats['total'] - $stats['active']) ?></strong>
        <small>Hidden from new products</small>
    </div>
    <div class="mini-stat">
        <span>Uncategorised Products</span>
        <strong><?= num($stats['uncategorised']) ?></strong>
        <small class="<?= $stats['uncategorised'] ? 'orange-text' : '' ?>">Need a category</small>
    </div>
</div>

<div class="card table-card">

    <form class="table-toolbar" method="get">
        <div class="table-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search categories...">
        </div>
        <select name="status" data-autosubmit>
            <option value="">All Status</option>
            <option value="active" <?= selected($status, 'active') ?>>Active</option>
            <option value="inactive" <?= selected($status, 'inactive') ?>>Inactive</option>
        </select>
        <button class="btn btn-secondary" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
        <?php if ($search !== '' || $status !== ''): ?>
            <a href="index.php" class="link-btn">Clear</a>
        <?php endif; ?>
    </form>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Products</th>
                    <th>Stock Value</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$categories): ?>
                    <tr>
                        <td colspan="6" class="empty-state"><i class="fa-solid fa-tags"></i>No categories found.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($categories as $c): ?>
                    <tr>
                        <td>
                            <div class="product-cell">
                                <div class="product-thumb <?= color_class((int) $c['id']) ?>">
                                    <i class="fa-solid fa-tag"></i>
                                </div>
                                <div>
                                    <strong><?= e($c['name']) ?></strong>
                                    <span><?= e($c['description'] ?: 'No description') ?></span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <a class="link-btn" href="../products/index.php?category=<?= (int) $c['id'] ?>">
                                <?= num($c['product_count']) ?> products
                            </a>
                        </td>
                        <td><?= money($c['stock_value']) ?></td>
                        <td><?= active_badge($c['status']) ?></td>
                        <td><?= fmt_date($c['created_at']) ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="icon-btn" href="edit.php?id=<?= (int) $c['id'] ?>" title="Edit">
                                    <i class="fa-solid fa-pen"></i>
                                </a>
                                <?= delete_button('modules/categories/delete.php', (int) $c['id'], 'category "' . $c['name'] . '"') ?>
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
