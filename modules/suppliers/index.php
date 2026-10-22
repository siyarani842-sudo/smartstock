<?php
/* =========================================================
   SUPPLIERS - LIST (cards + performance chart)
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

$pageTitle    = 'Suppliers';
$pageSubtitle = 'Monitor supplier performance and reliability';
$activePage   = 'suppliers';
$useCharts    = true;

$search = get('search');
$status = get('status');

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = "(s.name LIKE ? OR s.supply_type LIKE ? OR s.contact_person LIKE ? OR s.email LIKE ?)";
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
if (in_array($status, ['active', 'inactive'], true)) {
    $where[]  = "s.status = ?";
    $params[] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_value("SELECT COUNT(*) FROM suppliers s $whereSql", $params);
$pg    = paginate($total, 9);

$suppliers = db_all(
    "SELECT s.*, (SELECT COUNT(*) FROM products p WHERE p.supplier_id = s.id) AS product_count
       FROM suppliers s
       $whereSql
      ORDER BY s.rating DESC, s.name
      LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
    $params
);

$perf = supplier_performance();

/* KPI row */
$allSuppliers = db_all("SELECT id, name, rating, quality_score, status FROM suppliers ORDER BY rating DESC");
$activeCount  = count(array_filter($allSuppliers, fn($s) => $s['status'] === 'active'));
$avgRating    = $allSuppliers ? round(array_sum(array_column($allSuppliers, 'rating')) / count($allSuppliers), 1) : 0;
$measurable   = array_sum(array_column($perf, 'measurable'));
$onTimeAll    = $measurable ? round(array_sum(array_column($perf, 'on_time')) / $measurable * 100, 1) : 0;
$totalSpend   = array_sum(array_column($perf, 'total_spend'));

/* Chart: top 8 active suppliers */
$chartLabels = $chartOnTime = $chartQuality = [];
foreach (array_slice(array_filter($allSuppliers, fn($s) => $s['status'] === 'active'), 0, 8) as $s) {
    $chartLabels[]  = $s['name'];
    $chartOnTime[]  = (float) ($perf[$s['id']]['on_time_rate'] ?? 0);
    $chartQuality[] = (float) $s['quality_score'];
}
$chartData = ['supplier' => ['labels' => $chartLabels, 'onTime' => $chartOnTime, 'quality' => $chartQuality]];

include ROOT_PATH . 'includes/header.php';
?>

<div class="section-header">
    <div>
        <span class="section-label">PARTNERS</span>
        <h2>Suppliers</h2>
        <p>Monitor supplier performance and reliability.</p>
    </div>
    <a href="add.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Supplier</a>
</div>

<div class="mini-stats">
    <div class="mini-stat">
        <span>Total Suppliers</span>
        <strong><?= num(count($allSuppliers)) ?></strong>
        <small><?= num($activeCount) ?> active</small>
    </div>
    <div class="mini-stat">
        <span>Average Rating</span>
        <strong><?= $avgRating ?> <i class="fa-solid fa-star" style="color:#fdb022;font-size:14px"></i></strong>
        <small>Out of 5.0</small>
    </div>
    <div class="mini-stat">
        <span>On-time Delivery</span>
        <strong><?= $onTimeAll ?>%</strong>
        <small class="<?= $onTimeAll >= 90 ? 'green-text' : 'orange-text' ?>">Delivered purchase orders</small>
    </div>
    <div class="mini-stat">
        <span>Total Purchases</span>
        <strong><?= money_short($totalSpend) ?></strong>
        <small>All purchase orders</small>
    </div>
</div>

<div class="card table-card mb-20">
    <form class="table-toolbar" method="get">
        <div class="table-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search suppliers...">
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
</div>

<?php if (!$suppliers): ?>
    <div class="card empty-block"><i class="fa-solid fa-truck-fast"></i> No suppliers found.</div>
<?php endif; ?>

<div class="supplier-grid">
    <?php foreach ($suppliers as $s):
        $p = $perf[$s['id']] ?? [];
        [$gradeLabel, $gradeClass] = supplier_grade((float) $s['rating']);
        if ($s['status'] === 'inactive') {
            [$gradeLabel, $gradeClass] = ['Inactive', 'cancelled'];
        }
    ?>
        <div class="card supplier-card">
            <div class="supplier-top">
                <div class="supplier-avatar <?= color_class((int) $s['id']) ?>"><?= e(initials($s['name'])) ?></div>
                <?= badge($gradeLabel, $gradeClass) ?>
            </div>

            <h3><?= e($s['name']) ?></h3>
            <p><?= e($s['supply_type'] ?: 'Supplier') ?></p>

            <div class="supplier-rating">
                <i class="fa-solid fa-star"></i>
                <strong><?= e(number_format((float) $s['rating'], 1)) ?></strong>
                <span>Supplier rating</span>
            </div>

            <div class="supplier-metrics">
                <div>
                    <span>On-time delivery</span>
                    <strong><?= isset($p['on_time_rate']) ? $p['on_time_rate'] . '%' : 'N/A' ?></strong>
                </div>
                <div>
                    <span>Quality score</span>
                    <strong><?= e(rtrim(rtrim($s['quality_score'], '0'), '.')) ?>%</strong>
                </div>
                <div>
                    <span>Orders</span>
                    <strong><?= num($p['total_orders'] ?? 0) ?></strong>
                </div>
                <div>
                    <span>Lead time</span>
                    <strong><?= (int) $s['lead_time_days'] ?> days</strong>
                </div>
            </div>

            <div class="supplier-contact">
                <?php if ($s['contact_person']): ?><span><i class="fa-regular fa-user"></i> <?= e($s['contact_person']) ?></span><?php endif; ?>
                <?php if ($s['email']): ?><span><i class="fa-regular fa-envelope"></i> <?= e($s['email']) ?></span><?php endif; ?>
                <?php if ($s['phone']): ?><span><i class="fa-solid fa-phone"></i> <?= e($s['phone']) ?></span><?php endif; ?>
                <span><i class="fa-solid fa-box"></i> <?= num($s['product_count']) ?> products supplied</span>
            </div>

            <div class="supplier-actions">
                <a class="icon-btn" href="../orders/index.php?supplier=<?= (int) $s['id'] ?>" title="View orders"><i class="fa-solid fa-cart-shopping"></i></a>
                <a class="icon-btn" href="edit.php?id=<?= (int) $s['id'] ?>" title="Edit"><i class="fa-solid fa-pen"></i></a>
                <?= delete_button('modules/suppliers/delete.php', (int) $s['id'], 'supplier "' . $s['name'] . '"') ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card mt-20"><?= pagination_links($pg) ?></div>

<div class="card supplier-performance mt-20">
    <div class="card-header">
        <div>
            <h3>Supplier Performance</h3>
            <p>On-time delivery (from purchase orders) vs quality score.</p>
        </div>
    </div>
    <?php if ($chartLabels): ?>
        <div class="chart-wrapper"><canvas id="supplierChart"></canvas></div>
    <?php else: ?>
        <div class="empty-block">Add suppliers and purchase orders to see performance.</div>
    <?php endif; ?>
</div>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
