<?php
/* =========================================================
   SETTINGS
   Stored as key/value rows in the `settings` table.
========================================================= */
require_once __DIR__ . '/../../includes/bootstrap.php';

$pageTitle    = 'Settings';
$pageSubtitle = 'Configure your inventory dashboard';
$activePage   = 'settings';

$textFields = [
    'company_name'    => ['label' => 'Company Name', 'max' => 100, 'required' => true],
    'admin_name'      => ['label' => 'Your Name', 'max' => 100, 'required' => true],
    'admin_role'      => ['label' => 'Your Role', 'max' => 100, 'required' => false],
    'currency_symbol' => ['label' => 'Currency Symbol', 'max' => 5, 'required' => true],
    'report_email'    => ['label' => 'Report Email', 'max' => 150, 'required' => false],
];
$numberFields = [
    'forecast_horizon'  => ['label' => 'Forecast Horizon (months)', 'min' => 1, 'max' => 12],
    'safety_stock_days' => ['label' => 'Safety Stock (days)', 'min' => 0, 'max' => 90],
    'coverage_days'     => ['label' => 'Reorder Coverage (days)', 'min' => 7, 'max' => 180],
];
$toggles = ['low_stock_alerts', 'ai_alerts', 'auto_sync', 'email_reports'];

$errors = [];
$tab    = get('tab', 'general');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $tab    = post('tab', 'general');
    $values = [];

    foreach ($textFields as $key => $f) {
        $values[$key] = post($key);
        if ($f['required'] && $values[$key] === '') {
            $errors[$key] = $f['label'] . ' is required.';
        } elseif (mb_strlen($values[$key]) > $f['max']) {
            $errors[$key] = 'Maximum ' . $f['max'] . ' characters.';
        }
    }
    if ($values['report_email'] !== '' && !filter_var($values['report_email'], FILTER_VALIDATE_EMAIL)) {
        $errors['report_email'] = 'Enter a valid email address.';
    }

    foreach ($numberFields as $key => $f) {
        $values[$key] = post($key);
        if (!ctype_digit($values[$key]) || $values[$key] < $f['min'] || $values[$key] > $f['max']) {
            $errors[$key] = "Enter a whole number between {$f['min']} and {$f['max']}.";
        }
    }

    foreach ($toggles as $key) {
        $values[$key] = post($key) === '1' ? '1' : '0';
    }

    if ($values['email_reports'] === '1' && $values['report_email'] === '') {
        $errors['report_email'] = 'Enter an email address to receive reports.';
    }

    if (!$errors) {
        foreach ($values as $key => $value) {
            save_setting($key, (string) $value);
        }
        flash('Settings saved successfully.');
        redirect('modules/settings/index.php?tab=' . urlencode($tab));
    }
}

/* Current values (posted values win when there are errors) */
$val = function (string $key, $default = '') use ($errors) {
    if ($errors && isset($_POST[$key])) {
        return post($key);
    }
    return setting($key, $default);
};
$on = fn($key) => $errors ? post($key) === '1' : setting($key, '0') === '1';

$fieldClass = fn($f) => isset($errors[$f]) ? 'has-error' : '';

$dbStats = [
    'Products'        => db_value("SELECT COUNT(*) FROM products"),
    'Categories'      => db_value("SELECT COUNT(*) FROM categories"),
    'Warehouses'      => db_value("SELECT COUNT(*) FROM warehouses"),
    'Suppliers'       => db_value("SELECT COUNT(*) FROM suppliers"),
    'Orders'          => db_value("SELECT COUNT(*) FROM orders"),
    'Stock movements' => db_value("SELECT COUNT(*) FROM stock_movements"),
];

$tabs = [
    'general'       => ['icon' => 'fa-sliders', 'label' => 'General'],
    'notifications' => ['icon' => 'fa-bell',    'label' => 'Notifications'],
    'ai'            => ['icon' => 'fa-brain',   'label' => 'AI Settings'],
    'inventory'     => ['icon' => 'fa-warehouse', 'label' => 'Inventory Sync'],
];
if (!isset($tabs[$tab])) {
    $tab = 'general';
}

include ROOT_PATH . 'includes/header.php';
?>

<div class="section-header">
    <div>
        <span class="section-label">SYSTEM</span>
        <h2>Settings</h2>
        <p>Configure your inventory dashboard.</p>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert-box error"><strong>Settings not saved.</strong> Please fix the highlighted fields.</div>
<?php endif; ?>

<form method="post" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="tab" id="activeTab" value="<?= e($tab) ?>">

    <div class="settings-layout">

        <div class="settings-menu">
            <?php foreach ($tabs as $key => $t): ?>
                <button type="button" class="settings-tab<?= $tab === $key ? ' active' : '' ?>" data-tab="<?= $key ?>"
                    onclick="document.getElementById('activeTab').value='<?= $key ?>'">
                    <i class="fa-solid <?= $t['icon'] ?>"></i> <?= $t['label'] ?>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="card settings-card">

            <!-- GENERAL -->
            <div data-tab-panel="general" <?= $tab !== 'general' ? 'hidden' : '' ?>>
                <div class="settings-title">
                    <h3>General</h3>
                    <p>Company and profile details shown across the app.</p>
                </div>
                <div class="form-grid">
                    <?php foreach (['company_name', 'admin_name', 'admin_role', 'currency_symbol'] as $key): ?>
                        <div class="form-group <?= $fieldClass($key) ?>">
                            <label><?= e($textFields[$key]['label']) ?> <?= $textFields[$key]['required'] ? '<span class="req">*</span>' : '' ?></label>
                            <input type="text" name="<?= $key ?>" maxlength="<?= $textFields[$key]['max'] ?>" value="<?= e($val($key)) ?>">
                            <?= field_error($errors, $key) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- NOTIFICATIONS -->
            <div data-tab-panel="notifications" <?= $tab !== 'notifications' ? 'hidden' : '' ?>>
                <div class="settings-title">
                    <h3>Notifications</h3>
                    <p>Choose which alerts you want to see.</p>
                </div>

                <div class="setting-row">
                    <div>
                        <h3>Low Stock Notifications</h3>
                        <p>Show the bell counter when products reach their reorder level.</p>
                    </div>
                    <label class="switch"><input type="checkbox" name="low_stock_alerts" value="1" <?= checked($on('low_stock_alerts')) ?>><span></span></label>
                </div>

                <div class="setting-row">
                    <div>
                        <h3>AI Prediction Alerts</h3>
                        <p>Show AI insights about important predictions on the dashboard.</p>
                    </div>
                    <label class="switch"><input type="checkbox" name="ai_alerts" value="1" <?= checked($on('ai_alerts')) ?>><span></span></label>
                </div>

                <div class="setting-row">
                    <div>
                        <h3>Email Reports</h3>
                        <p>Receive weekly inventory reports by email (requires mail to be configured on the server).</p>
                    </div>
                    <label class="switch"><input type="checkbox" name="email_reports" value="1" <?= checked($on('email_reports')) ?>><span></span></label>
                </div>

                <div class="form-grid mt-20">
                    <div class="form-group full <?= $fieldClass('report_email') ?>">
                        <label>Report Email</label>
                        <input type="email" name="report_email" maxlength="150" value="<?= e($val('report_email')) ?>" placeholder="you@company.com">
                        <?= field_error($errors, 'report_email') ?>
                    </div>
                </div>
            </div>

            <!-- AI SETTINGS -->
            <div data-tab-panel="ai" <?= $tab !== 'ai' ? 'hidden' : '' ?>>
                <div class="settings-title">
                    <h3>AI Settings</h3>
                    <p>Tune how the demand forecast and reorder recommendations are calculated.</p>
                </div>
                <div class="form-grid">
                    <?php foreach ($numberFields as $key => $f): ?>
                        <div class="form-group <?= $fieldClass($key) ?>">
                            <label><?= e($f['label']) ?></label>
                            <input type="number" name="<?= $key ?>" min="<?= $f['min'] ?>" max="<?= $f['max'] ?>" value="<?= e($val($key)) ?>">
                            <?= field_error($errors, $key) ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-group full">
                        <span class="hint">
                            <strong>Safety stock</strong> = extra days of demand kept as a buffer.
                            <strong>Reorder coverage</strong> = how many days of demand each recommended order should cover (on top of supplier lead time).
                        </span>
                    </div>
                </div>
            </div>

            <!-- INVENTORY SYNC -->
            <div data-tab-panel="inventory" <?= $tab !== 'inventory' ? 'hidden' : '' ?>>
                <div class="settings-title">
                    <h3>Inventory Sync</h3>
                    <p>Orders always update stock instantly. The automatic sync also re-checks every order periodically.</p>
                </div>

                <div class="setting-row">
                    <div>
                        <h3>Automatic Inventory Sync</h3>
                        <p>Re-check all orders and rebuild stock totals when the Inventory page is opened (at most every 10 minutes).</p>
                    </div>
                    <label class="switch"><input type="checkbox" name="auto_sync" value="1" <?= checked($on('auto_sync')) ?>><span></span></label>
                </div>

                <div class="setting-row">
                    <div>
                        <h3>Last full sync</h3>
                        <p><?= setting('last_inventory_sync') ? e(fmt_date(setting('last_inventory_sync'), 'M j, Y H:i:s')) : 'Never' ?></p>
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm" form="syncNowForm"><i class="fa-solid fa-arrows-rotate"></i> Sync now</button>
                </div>

                <div class="detail-grid" style="padding:15px 0 0">
                    <?php foreach ($dbStats as $label => $count): ?>
                        <div><span><?= e($label) ?></span><strong><?= num($count) ?></strong></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="setting-actions">
                <a href="index.php?tab=<?= e($tab) ?>" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
            </div>

        </div>
    </div>
</form>

<form method="post" action="../inventory/sync.php" id="syncNowForm" data-loading="Syncing...">
    <?= csrf_field() ?>
</form>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
