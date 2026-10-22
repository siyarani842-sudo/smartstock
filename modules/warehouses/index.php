<?php
/* =========================================================
   WAREHOUSES - LIST
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

$pageTitle    = 'Warehouses';
$pageSubtitle = 'Storage locations for your inventory';
$activePage   = 'warehouses';

$search = get('search');
$status = get('status');

$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = "(w.name LIKE ? OR w.code LIKE ? OR w.location LIKE ? OR w.manager LIKE ?)";
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
if (in_array($status, ['active', 'inactive'], true)) {
    $where[]  = "w.status = ?";
    $params[] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_value("SELECT COUNT(*) FROM warehouses w $whereSql", $params);
$pg    = paginate($total, 10);

$warehouses = db_all(
    "SELECT w.*,
            COALESCE(s.units, 0) AS units,
            COALESCE(s.products, 0) AS products,
            COALESCE(s.value, 0) AS stock_value
       FROM warehouses w
       LEFT JOIN (
            SELECT i.warehouse_id,
                   SUM(i.quantity) AS units,
                   SUM(i.quantity > 0) AS products,
                   SUM(i.quantity * p.cost_price) AS value
              FROM inventory i JOIN products p ON p.id = i.product_id
             GROUP BY i.warehouse_id
       ) s ON s.warehouse_id = w.id
       $whereSql
      ORDER BY w.name
      LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
    $params
);

$stats = db_one(
    "SELECT COUNT(*) AS total,
            SUM(status = 'active') AS active,
            SUM(capacity) AS capacity,
            (SELECT COALESCE(SUM(quantity), 0) FROM inventory) AS units
       FROM warehouses"
);
$utilisation = $stats['capacity'] > 0 ? round($stats['units'] / $stats['capacity'] * 100, 1) : 0;

include ROOT_PATH . 'includes/header.php';
?>

<div class="section-header">
    <div>
        <span class="section-label">LOCATIONS</span>
        <h2>Warehouses</h2>
        <p>Manage where your stock is stored.</p>
    </div>
    <a href="add.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Warehouse</a>
</div>

<div class="mini-stats">
    <div class="mini-stat">
        <span>Total Warehouses</span>
        <strong><?= num($stats['total']) ?></strong>
        <small><?= num($stats['active']) ?> active</small>
    </div>
    <div class="mini-stat">
        <span>Units Stored</span>
        <strong><?= num($stats['units']) ?></strong>
        <small>Across all locations</small>
    </div>
    <div class="mini-stat">
        <span>Total Capacity</span>
        <strong><?= num($stats['capacity']) ?></strong>
        <small>Units</small>
    </div>
    <div class="mini-stat">
        <span>Utilisation</span>
        <strong><?= $utilisation ?>%</strong>
        <small class="<?= $utilisation > 90 ? 'red-text' : 'green-text' ?>"><?= $utilisation > 90 ? 'Nearly full' : 'Space available' ?></small>
    </div>
</div>

<div class="card table-card">

    <form class="table-toolbar" method="get">
        <div class="table-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search warehouses...">
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
                    <th>Warehouse</th>
                    <th>Location</th>
                    <th>Manager</th>
                    <th>Products</th>
                    <th>Units</th>
                    <th>Utilisation</th>
                    <th>Stock Value</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$warehouses): ?>
                    <tr>
                        <td colspan="9" class="empty-state"><i class="fa-solid fa-building"></i>No warehouses found.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($warehouses as $w):
                    $used = $w['capacity'] > 0 ? round($w['units'] / $w['capacity'] * 100) : null;
                ?>
                    <tr>
                        <td>
                            <div class="product-cell">
                                <div class="product-thumb <?= color_class((int) $w['id']) ?>">
                                    <i class="fa-solid fa-warehouse"></i>
                                </div>
                                <div>
                                    <strong><?= e($w['name']) ?></strong>
                                    <span><?= e($w['code']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td><?= e($w['location'] ?: '—') ?></td>
                        <td>
                            <?= e($w['manager'] ?: '—') ?>
                            <?php if ($w['phone']): ?><br><small class="muted"><?= e($w['phone']) ?></small><?php endif; ?>
                        </td>
                        <td><?= num($w['products']) ?></td>
                        <td>
                            <a class="link-btn" href="../inventory/index.php?warehouse=<?= (int) $w['id'] ?>"><?= num($w['units']) ?></a>
                        </td>
                        <td>
                            <?php if ($used === null): ?>
                                <span class="muted">No limit</span>
                            <?php else: ?>
                                <?= badge($used . '%', $used > 90 ? 'critical' : ($used > 75 ? 'low' : 'healthy')) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= money($w['stock_value']) ?></td>
                        <td><?= active_badge($w['status']) ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="icon-btn" href="edit.php?id=<?= (int) $w['id'] ?>" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <?= delete_button('modules/warehouses/delete.php', (int) $w['id'], 'warehouse "' . $w['name'] . '"') ?>
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
