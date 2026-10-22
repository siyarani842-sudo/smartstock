<?php
/* =========================================================
   HELPER FUNCTIONS
========================================================= */


/* ---------------------------------------------------------
   DATABASE HELPERS (prepared statements - SQL injection safe)
--------------------------------------------------------- */

/**
 * Run a query. Use ? placeholders and pass values in $params.
 * Returns mysqli_result for SELECT queries, true otherwise.
 */
function db_query(string $sql, array $params = [])
{
    global $conn;

    if (empty($params)) {
        return mysqli_query($conn, $sql);
    }

    $stmt  = mysqli_prepare($conn, $sql);
    $types = '';
    $values = [];

    foreach ($params as $p) {
        if (is_int($p) || is_bool($p)) {
            $types .= 'i';
            $values[] = (int) $p;
        } elseif (is_float($p)) {
            $types .= 'd';
            $values[] = $p;
        } else {
            $types .= 's';
            $values[] = $p; // null is bound as SQL NULL
        }
    }

    mysqli_stmt_bind_param($stmt, $types, ...$values);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    return $result === false ? true : $result;
}

/** Fetch all rows as associative arrays */
function db_all(string $sql, array $params = []): array
{
    $result = db_query($sql, $params);
    return $result instanceof mysqli_result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
}

/** Fetch a single row (or null) */
function db_one(string $sql, array $params = []): ?array
{
    $result = db_query($sql, $params);
    if (!$result instanceof mysqli_result) {
        return null;
    }
    $row = mysqli_fetch_assoc($result);
    return $row ?: null;
}

/** Fetch a single value (first column of first row) */
function db_value(string $sql, array $params = [], $default = 0)
{
    $row = db_one($sql, $params);
    if (!$row) {
        return $default;
    }
    $value = reset($row);
    return $value === null ? $default : $value;
}

/** Last inserted auto-increment id */
function db_insert_id(): int
{
    global $conn;
    return (int) mysqli_insert_id($conn);
}

function db_begin(): void
{
    global $conn;
    mysqli_begin_transaction($conn);
}

function db_commit(): void
{
    global $conn;
    mysqli_commit($conn);
}

function db_rollback(): void
{
    global $conn;
    mysqli_rollback($conn);
}


/* ---------------------------------------------------------
   OUTPUT & URL HELPERS
--------------------------------------------------------- */

/** Escape output for HTML */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Build a URL relative to the project root */
function url(string $path = ''): string
{
    return BASE_URL . ltrim($path, '/');
}

/** URL of an uploaded image (or null if none) */
function upload_url(?string $folder, ?string $file): ?string
{
    if (!$file) {
        return null;
    }
    if (!is_file(ROOT_PATH . 'uploads/' . $folder . '/' . $file)) {
        return null;
    }
    return url('uploads/' . $folder . '/' . rawurlencode($file));
}

function redirect(string $path): void
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

/** Format money using the currency symbol from settings */
function money($amount, int $decimals = 2): string
{
    return setting('currency_symbol', '$') . number_format((float) $amount, $decimals);
}

/** Short money e.g. $12.8K */
function money_short($amount): string
{
    $amount = (float) $amount;
    $symbol = setting('currency_symbol', '$');
    if (abs($amount) >= 1000000) {
        return $symbol . round($amount / 1000000, 1) . 'M';
    }
    if (abs($amount) >= 1000) {
        return $symbol . round($amount / 1000, 1) . 'K';
    }
    return $symbol . number_format($amount, 0);
}

function num($value, int $decimals = 0): string
{
    return number_format((float) $value, $decimals);
}

function fmt_date(?string $date, string $format = 'M j, Y'): string
{
    if (!$date || $date === '0000-00-00') {
        return '—';
    }
    return date($format, strtotime($date));
}

/** Initials for avatars, e.g. "TechSource Ltd." => "TL" */
function initials(string $name): string
{
    $words = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $name)));
    $out = '';
    foreach (array_slice($words, 0, 2) as $w) {
        $out .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    return $out ?: '?';
}

/** Rotating colour class used for icons/avatars */
function color_class(int $id): string
{
    $colors = ['blue', 'purple', 'orange', 'green'];
    return $colors[$id % count($colors)];
}

/** Read a value from $_POST trimmed */
function post(string $key, $default = '')
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $value;
}

/** Read a value from $_GET trimmed */
function get(string $key, $default = '')
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $value;
}

/** Keep current query string but override some keys */
function query_with(array $overrides): string
{
    $query = array_merge($_GET, $overrides);
    $query = array_filter($query, fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($query);
}


/* ---------------------------------------------------------
   FLASH MESSAGES (shown as toast after redirect)
--------------------------------------------------------- */

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}


/* ---------------------------------------------------------
   CSRF PROTECTION
--------------------------------------------------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        die('Your session has expired. Please go back, refresh the page and try again.');
    }
}

/** A small POST form button used for deleting records */
function delete_button(string $action, int $id, string $label = 'this record'): string
{
    return '<form method="post" action="' . e(url($action)) . '" class="inline-form" '
        . 'data-confirm="Are you sure you want to delete ' . e($label) . '? This cannot be undone.">'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="icon-btn danger" title="Delete"><i class="fa-solid fa-trash"></i></button>'
        . '</form>';
}


/* ---------------------------------------------------------
   SETTINGS
--------------------------------------------------------- */

function setting(string $key, $default = null)
{
    static $cache = null;

    if ($cache === null) {
        $cache = [];
        try {
            foreach (db_all("SELECT setting_key, setting_value FROM settings") as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (mysqli_sql_exception $e) {
            // settings table may not exist yet
        }
    }

    if (func_num_args() === 1 && $key === '__reset__') {
        $cache = null;
        return null;
    }

    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function save_setting(string $key, string $value): void
{
    db_query(
        "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        [$key, $value]
    );
}


/* ---------------------------------------------------------
   IMAGE UPLOADS
--------------------------------------------------------- */

/**
 * Upload an image from $_FILES[$field] into uploads/$folder.
 * Returns the stored filename, null when no file was chosen.
 * Throws RuntimeException with a friendly message on error.
 */
function upload_image(string $field, string $folder): ?string
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$field];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed (error code ' . $file['error'] . ').');
    }

    if ($file['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('Image must be smaller than 2 MB.');
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG, WEBP or GIF images are allowed.');
    }

    $dir = ROOT_PATH . 'uploads/' . $folder . '/';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $name = $folder . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];

    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
        throw new RuntimeException('Could not save the uploaded image. Check folder permissions.');
    }

    return $name;
}

function delete_image(?string $folder, ?string $file): void
{
    if (!$file) {
        return;
    }
    $path = ROOT_PATH . 'uploads/' . $folder . '/' . basename($file);
    if (is_file($path)) {
        @unlink($path);
    }
}


/* ---------------------------------------------------------
   PAGINATION
--------------------------------------------------------- */

function paginate(int $total, int $perPage = 10): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min($pages, max(1, (int) get('page', 1)));

    return [
        'total'   => $total,
        'page'    => $page,
        'pages'   => $pages,
        'perPage' => $perPage,
        'offset'  => ($page - 1) * $perPage,
    ];
}

function pagination_links(array $p): string
{
    if ($p['pages'] <= 1) {
        return '<div class="pagination-bar"><span>Showing ' . $p['total'] . ' record(s)</span></div>';
    }

    $from = $p['offset'] + 1;
    $to   = min($p['total'], $p['offset'] + $p['perPage']);

    $html = '<div class="pagination-bar"><span>Showing ' . $from . '–' . $to . ' of ' . $p['total'] . '</span><div class="pager">';

    if ($p['page'] > 1) {
        $html .= '<a href="' . e(query_with(['page' => $p['page'] - 1])) . '"><i class="fa-solid fa-chevron-left"></i></a>';
    }

    for ($i = max(1, $p['page'] - 2); $i <= min($p['pages'], $p['page'] + 2); $i++) {
        $active = $i === $p['page'] ? ' class="active"' : '';
        $html .= '<a' . $active . ' href="' . e(query_with(['page' => $i])) . '">' . $i . '</a>';
    }

    if ($p['page'] < $p['pages']) {
        $html .= '<a href="' . e(query_with(['page' => $p['page'] + 1])) . '"><i class="fa-solid fa-chevron-right"></i></a>';
    }

    return $html . '</div></div>';
}


/* ---------------------------------------------------------
   STATUS BADGES
--------------------------------------------------------- */

/** Stock status based on quantity and reorder level */
function stock_status(int $qty, int $reorderLevel): array
{
    if ($qty <= 0) {
        return ['label' => 'Out of Stock', 'class' => 'critical', 'key' => 'out'];
    }
    if ($qty <= max(1, (int) floor($reorderLevel / 2))) {
        return ['label' => 'Critical', 'class' => 'critical', 'key' => 'critical'];
    }
    if ($qty <= $reorderLevel) {
        return ['label' => 'Low Stock', 'class' => 'low', 'key' => 'low'];
    }
    return ['label' => 'Healthy', 'class' => 'healthy', 'key' => 'healthy'];
}

function badge(string $label, string $class): string
{
    return '<span class="status ' . e($class) . '">' . e($label) . '</span>';
}

function order_status_badge(string $status): string
{
    $map = [
        'Pending'    => 'pending',
        'In Transit' => 'transit',
        'Delivered'  => 'delivered',
        'Cancelled'  => 'cancelled',
    ];
    return badge($status, $map[$status] ?? 'pending');
}

function active_badge(string $status): string
{
    return $status === 'active' ? badge('Active', 'healthy') : badge('Inactive', 'cancelled');
}


/* ---------------------------------------------------------
   FORM VALIDATION ERRORS
--------------------------------------------------------- */

function field_error(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<small class="field-error">' . e($errors[$field]) . '</small>' : '';
}

function selected($a, $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

function checked($value): string
{
    return $value ? ' checked' : '';
}
