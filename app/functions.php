<?php
/**
 * Shared helper functions (admin + website).
 * Sections: URLs, output escaping, requests/responses, CSRF, flash, settings, formatting,
 * sequences, validation, HTML sanitising, logging, notifications, academic session.
 */

/* ------------------------------------------------------------------
 * URLs & paths
 * ------------------------------------------------------------------ */

/** Path of the application relative to the web server document root, e.g. "" or "/gimt". */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $configured = app_config('app')['url'] ?? '';
    if ($configured !== '') {
        $base = rtrim((string) parse_url($configured, PHP_URL_PATH), '/');
        return $base;
    }
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $appRoot = realpath(APP_ROOT) ?: APP_ROOT;
    if ($docRoot !== '' && str_starts_with($appRoot, $docRoot)) {
        $base = rtrim(str_replace('\\', '/', substr($appRoot, strlen($docRoot))), '/');
    } else {
        $base = '';
    }
    return $base;
}

/** Root-relative URL for a path inside the application: base_url('admin/students.php'). */
function base_url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

/** Absolute URL including scheme and host (for emails, sitemaps, QR codes, canonical tags). */
function absolute_url(string $path = ''): string
{
    $configured = rtrim(app_config('app')['url'] ?? '', '/');
    if ($configured !== '') {
        $origin = preg_replace('#^(https?://[^/]+).*$#', '$1', $configured);
    } else {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $origin = ($https ? 'https' : 'http') . '://' . $host;
    }
    return $origin . base_url($path);
}

function admin_url(string $path = ''): string
{
    return base_url('admin/' . ltrim($path, '/'));
}

/** URL for a static asset with cache-busting version parameter. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = APP_ROOT . '/' . $path;
    $v = is_file($file) ? filemtime($file) : APP_VERSION;
    return base_url($path) . '?v=' . $v;
}

/** Public URL for an uploaded file path stored in the database (e.g. "assets/uploads/students/2026/10/abc.jpg"). */
function upload_url(?string $path, string $fallback = ''): string
{
    if (!$path) {
        return $fallback;
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return base_url($path);
}

function current_url(): string
{
    return $_SERVER['REQUEST_URI'] ?? '/';
}

function current_script(): string
{
    return basename($_SERVER['SCRIPT_NAME'] ?? '');
}

function redirect(string $url, int $code = 302): void
{
    header('Location: ' . $url, true, $code);
    exit;
}

/** Only allow redirects to local paths (prevents open redirects). */
function safe_redirect_target(?string $target, string $fallback): string
{
    if (!$target || !str_starts_with($target, '/') || str_starts_with($target, '//') || str_contains($target, "\n") || str_contains($target, '\\')) {
        return $fallback;
    }
    return $target;
}

/* ------------------------------------------------------------------
 * Output escaping
 * ------------------------------------------------------------------ */

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** JSON for embedding inside <script> tags safely. */
function js_json($value): string
{
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

/* ------------------------------------------------------------------
 * Requests & responses
 * ------------------------------------------------------------------ */

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_ajax(): bool
{
    return defined('API_REQUEST') || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

/** Read a trimmed scalar input from POST then GET. Arrays are returned as-is. */
function input(string $key, $default = null)
{
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    if (is_string($value)) {
        $value = trim($value);
    }
    return $value;
}

function input_int(string $key, int $default = 0): int
{
    $v = input($key);
    return is_numeric($v) ? (int) $v : $default;
}

function json_response(array $data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function json_ok(array $data = [], string $message = ''): void
{
    json_response(array_merge(['ok' => true, 'message' => $message], $data));
}

/** @param array $errors field => message map for inline form errors */
function json_error(string $message, int $status = 422, array $errors = []): void
{
    json_response(['ok' => false, 'message' => $message, 'errors' => (object) $errors], $status);
}

/* ------------------------------------------------------------------
 * CSRF protection (synchronizer token stored in the session)
 * ------------------------------------------------------------------ */

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $known = $_SESSION['_csrf'] ?? '';
    return is_string($sent) && $known !== '' && hash_equals($known, $sent);
}

/** Abort the request when the CSRF token is missing or invalid. */
function csrf_verify(): void
{
    if (csrf_valid()) {
        return;
    }
    if (is_ajax()) {
        json_error('Your session has expired. Please refresh the page and try again.', 419);
    }
    http_response_code(419);
    flash('error', 'Your session has expired. Please try again.');
    redirect($_SERVER['HTTP_REFERER'] ?? admin_url(''));
}

/* ------------------------------------------------------------------
 * Flash messages
 * ------------------------------------------------------------------ */

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

/* ------------------------------------------------------------------
 * Settings (key/value store in `settings` table)
 * ------------------------------------------------------------------ */

function settings_all(bool $refresh = false): array
{
    static $settings = null;
    if ($settings === null || $refresh) {
        try {
            $settings = db_pairs('SELECT `key`, `value` FROM settings');
        } catch (Throwable $e) {
            $settings = [];
        }
    }
    return $settings;
}

function setting(string $key, $default = null)
{
    $all = settings_all();
    return array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '' ? $all[$key] : $default;
}

function save_setting(string $key, $value, string $group = 'general'): void
{
    if (is_array($value)) {
        $value = json_encode($value);
    }
    db_exec('INSERT INTO settings (`group`, `key`, `value`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `group` = VALUES(`group`)', [$group, $key, $value]);
    settings_all(true);
}

function institute_name(): string
{
    return setting('institute_name', 'Global Institute of Management & Technology');
}

function logo_url(bool $white = false): string
{
    $custom = setting($white ? 'logo_white' : 'logo');
    if ($custom) {
        return upload_url($custom);
    }
    return asset($white ? 'assets/images/logo-white.svg' : 'assets/images/logo.svg');
}

/* ------------------------------------------------------------------
 * Formatting
 * ------------------------------------------------------------------ */

function format_date($date, ?string $format = null): string
{
    if (!$date || str_starts_with((string) $date, '0000')) {
        return '—';
    }
    $ts = is_numeric($date) ? (int) $date : strtotime((string) $date);
    return $ts ? date($format ?? setting('date_format', 'd M Y'), $ts) : '—';
}

function format_datetime($date): string
{
    return format_date($date, setting('date_format', 'd M Y') . ', h:i A');
}

function format_time($time): string
{
    return $time ? date('h:i A', strtotime((string) $time)) : '—';
}

function time_ago($date): string
{
    if (!$date) {
        return '—';
    }
    $diff = time() - strtotime((string) $date);
    if ($diff < 60) {
        return 'just now';
    }
    $units = [31536000 => 'year', 2592000 => 'month', 604800 => 'week', 86400 => 'day', 3600 => 'hour', 60 => 'min'];
    foreach ($units as $secs => $label) {
        if ($diff >= $secs) {
            $n = (int) floor($diff / $secs);
            return $n . ' ' . $label . ($n > 1 ? 's' : '') . ' ago';
        }
    }
    return 'just now';
}

/** Indian number grouping: 1234567.5 -> 12,34,567.50 */
function number_in($number, int $decimals = 0): string
{
    $number = (float) $number;
    $negative = $number < 0;
    $number = abs($number);
    $parts = explode('.', number_format($number, $decimals, '.', ''));
    $int = $parts[0];
    $last3 = substr($int, -3);
    $rest = substr($int, 0, -3);
    if ($rest !== '' && $rest !== false) {
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $int = $rest . ',' . $last3;
    }
    return ($negative ? '-' : '') . $int . (isset($parts[1]) ? '.' . $parts[1] : '');
}

function money($amount, int $decimals = 0): string
{
    return setting('currency_symbol', '₹') . number_in($amount, $decimals);
}

/** Compact Indian currency: 2840000 -> ₹28.4 L, 25000000 -> ₹2.5 Cr */
function money_short($amount): string
{
    $amount = (float) $amount;
    $sym = setting('currency_symbol', '₹');
    if (abs($amount) >= 10000000) {
        return $sym . rtrim(rtrim(number_format($amount / 10000000, 2), '0'), '.') . ' Cr';
    }
    if (abs($amount) >= 100000) {
        return $sym . rtrim(rtrim(number_format($amount / 100000, 1), '0'), '.') . ' L';
    }
    if (abs($amount) >= 1000) {
        return $sym . rtrim(rtrim(number_format($amount / 1000, 1), '0'), '.') . 'K';
    }
    return $sym . number_in($amount);
}

function percent($value, int $decimals = 0): string
{
    return number_format((float) $value, $decimals) . '%';
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-') ?: 'item';
}

function str_limit(?string $text, int $limit = 80): string
{
    $text = trim(strip_tags((string) $text));
    return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)) . '…' : $text;
}

function initials(?string $name): string
{
    $parts = preg_split('/\s+/', trim((string) $name));
    $i = '';
    foreach ($parts as $p) {
        if ($p !== '') {
            $i .= mb_strtoupper(mb_substr($p, 0, 1));
        }
        if (mb_strlen($i) >= 2) {
            break;
        }
    }
    return $i ?: '?';
}

function full_name(array $row, string $first = 'first_name', string $last = 'last_name'): string
{
    return trim(($row[$first] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row[$last] ?? ''));
}

function human_filesize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $size = (float) $bytes;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return round($size, $i ? 1 : 0) . ' ' . $units[$i];
}

function label_from_key(string $key): string
{
    return ucwords(str_replace(['_', '-'], ' ', $key));
}

/* ------------------------------------------------------------------
 * Sequences (atomic, gap-free numbering for receipts, applications...)
 * ------------------------------------------------------------------ */

/**
 * Next number for a named sequence, formatted with a pattern.
 * Pattern tokens: {Y} year, {y} 2-digit year, {m} month, {session} current academic session (2026-27), {n:5} zero padded number.
 *   next_number('receipt', 'RCPT/{session}/{n:6}')  -> RCPT/2026-27/000124
 */
function next_number(string $name, string $pattern = '{n:5}'): string
{
    db_exec('INSERT INTO sequences (name, current_value) VALUES (?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE current_value = LAST_INSERT_ID(current_value + 1)', [$name]);
    $n = (int) db()->lastInsertId();
    $session = current_session()['name'] ?? date('Y');
    return preg_replace_callback('/\{(Y|y|m|session|n(?::(\d+))?)\}/', function ($m) use ($n, $session) {
        switch ($m[1]) {
            case 'Y': return date('Y');
            case 'y': return date('y');
            case 'm': return date('m');
            case 'session': return $session;
            default: return str_pad((string) $n, (int) ($m[2] ?? 1), '0', STR_PAD_LEFT);
        }
    }, $pattern);
}

/* ------------------------------------------------------------------
 * Validation
 * ------------------------------------------------------------------ */

/**
 * Validate an associative array against rules.
 *   $rules = ['email' => 'required|email|max:150|unique:students,email', 'dob' => 'date', 'fee' => 'numeric|min:0']
 * Supported rules: required, email, numeric, integer, min:n, max:n (length for strings / value for numerics),
 *   date, time, in:a,b,c, regex:/../, phone, url, unique:table,column[,ignoreId], exists:table,column, confirmed, alpha_dash
 * @return array field => first error message
 */
function validate(array $data, array $rules, array $labels = []): array
{
    $errors = [];
    foreach ($rules as $field => $ruleString) {
        $rulesList = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
        $value = $data[$field] ?? null;
        $label = $labels[$field] ?? label_from_key($field);
        $isEmpty = $value === null || $value === '' || (is_array($value) && !$value);
        $isNumericRule = in_array('numeric', $rulesList, true) || in_array('integer', $rulesList, true);
        foreach ($rulesList as $rule) {
            [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
            if ($name === 'required') {
                if ($isEmpty) {
                    $errors[$field] = "$label is required.";
                    break;
                }
                continue;
            }
            if ($isEmpty) {
                continue;
            }
            $error = null;
            switch ($name) {
                case 'email':
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $error = "Enter a valid email address.";
                    }
                    break;
                case 'numeric':
                    if (!is_numeric($value)) {
                        $error = "$label must be a number.";
                    }
                    break;
                case 'integer':
                    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                        $error = "$label must be a whole number.";
                    }
                    break;
                case 'min':
                    if ($isNumericRule ? (float) $value < (float) $param : mb_strlen((string) $value) < (int) $param) {
                        $error = $isNumericRule ? "$label must be at least $param." : "$label must be at least $param characters.";
                    }
                    break;
                case 'max':
                    if ($isNumericRule ? (float) $value > (float) $param : mb_strlen((string) $value) > (int) $param) {
                        $error = $isNumericRule ? "$label may not be greater than $param." : "$label may not be longer than $param characters.";
                    }
                    break;
                case 'date':
                    $d = DateTime::createFromFormat('Y-m-d', (string) $value);
                    $dt = DateTime::createFromFormat('Y-m-d\TH:i', (string) $value) ?: DateTime::createFromFormat('Y-m-d H:i:s', (string) $value) ?: DateTime::createFromFormat('Y-m-d H:i', (string) $value);
                    if (!($d && $d->format('Y-m-d') === $value) && !$dt) {
                        $error = "$label must be a valid date.";
                    }
                    break;
                case 'time':
                    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $value)) {
                        $error = "$label must be a valid time.";
                    }
                    break;
                case 'in':
                    if (!in_array((string) $value, explode(',', (string) $param), true)) {
                        $error = "Select a valid $label.";
                    }
                    break;
                case 'regex':
                    if (!preg_match($param, (string) $value)) {
                        $error = "$label format is invalid.";
                    }
                    break;
                case 'phone':
                    if (!preg_match('/^\+?[0-9\s\-()]{7,20}$/', (string) $value)) {
                        $error = "Enter a valid phone number.";
                    }
                    break;
                case 'url':
                    if (!filter_var($value, FILTER_VALIDATE_URL) && !str_starts_with((string) $value, '/') && !str_starts_with((string) $value, '#')) {
                        $error = "Enter a valid URL.";
                    }
                    break;
                case 'alpha_dash':
                    if (!preg_match('/^[A-Za-z0-9_\-]+$/', (string) $value)) {
                        $error = "$label may only contain letters, numbers, dashes and underscores.";
                    }
                    break;
                case 'confirmed':
                    if (($data[$field . '_confirmation'] ?? null) !== $value) {
                        $error = "$label confirmation does not match.";
                    }
                    break;
                case 'unique':
                    $p = explode(',', (string) $param);
                    $sql = 'SELECT COUNT(*) FROM ' . db_quote_ident($p[0]) . ' WHERE ' . db_quote_ident($p[1] ?? $field) . ' = ?';
                    $args = [$value];
                    if (!empty($p[2])) {
                        $sql .= ' AND id <> ?';
                        $args[] = (int) $p[2];
                    }
                    if ((int) db_value($sql, $args) > 0) {
                        $error = "This $label already exists.";
                    }
                    break;
                case 'exists':
                    $p = explode(',', (string) $param);
                    if (!(int) db_value('SELECT COUNT(*) FROM ' . db_quote_ident($p[0]) . ' WHERE ' . db_quote_ident($p[1] ?? 'id') . ' = ?', [$value])) {
                        $error = "Selected $label is invalid.";
                    }
                    break;
            }
            if ($error) {
                $errors[$field] = $error;
                break;
            }
        }
    }
    return $errors;
}

/** Password strength against the security settings. Returns an error message or null. */
function password_policy_error(string $password): ?string
{
    $min = (int) setting('password_min_length', 8);
    if (mb_strlen($password) < $min) {
        return "Password must be at least $min characters.";
    }
    if (setting('password_require_uppercase', '1') === '1' && !preg_match('/[A-Z]/', $password)) {
        return 'Password must contain an uppercase letter.';
    }
    if (setting('password_require_number', '1') === '1' && !preg_match('/\d/', $password)) {
        return 'Password must contain a number.';
    }
    if (setting('password_require_special', '1') === '1' && !preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must contain a special character.';
    }
    return null;
}

/* ------------------------------------------------------------------
 * HTML sanitising for rich text (CMS content, notices, templates)
 * ------------------------------------------------------------------ */

function sanitize_html(?string $html): string
{
    $html = (string) $html;
    if (trim($html) === '') {
        return '';
    }
    $allowedTags = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'a', 'img',
        'blockquote', 'pre', 'code', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'hr', 'span', 'div', 'figure', 'figcaption',
        'small', 'sub', 'sup', 'iframe', 'section', 'article', 'mark', 'caption', 'colgroup', 'col'];
    $allowedAttrs = ['href', 'src', 'alt', 'title', 'class', 'target', 'rel', 'width', 'height', 'colspan', 'rowspan', 'style', 'loading', 'allowfullscreen', 'frameborder', 'allow'];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $doc->getElementById('__root');
    if (!$root) {
        return e(strip_tags($html));
    }
    $walk = function (DOMNode $node) use (&$walk, $allowedTags, $allowedAttrs) {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $child = $node->childNodes->item($i);
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'meta', 'link', 'base', 'svg', 'math'], true)) {
                    $node->removeChild($child);
                    continue;
                }
                if (!in_array($tag, $allowedTags, true)) {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                for ($a = $child->attributes->length - 1; $a >= 0; $a--) {
                    $attr = $child->attributes->item($a);
                    $name = strtolower($attr->name);
                    $val = trim($attr->value);
                    if (!in_array($name, $allowedAttrs, true) || str_starts_with($name, 'on')) {
                        $child->removeAttribute($attr->name);
                        continue;
                    }
                    if (in_array($name, ['href', 'src'], true) && preg_match('/^\s*(javascript|vbscript|data):/i', $val) && !preg_match('/^data:image\/(png|jpe?g|gif|webp);/i', $val)) {
                        $child->removeAttribute($attr->name);
                    }
                    if ($name === 'style' && preg_match('/expression|javascript|url\s*\(/i', $val)) {
                        $child->removeAttribute($attr->name);
                    }
                }
                if ($tag === 'iframe') {
                    $src = $child->getAttribute('src');
                    if (!preg_match('#^https://(www\.)?(youtube\.com|youtube-nocookie\.com|player\.vimeo\.com|www\.google\.com/maps)/#i', $src)) {
                        $node->removeChild($child);
                        continue;
                    }
                }
                if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
                $walk($child);
            } elseif ($child instanceof DOMComment) {
                $node->removeChild($child);
            }
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return $out;
}

/* ------------------------------------------------------------------
 * Client info
 * ------------------------------------------------------------------ */

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (setting('trust_proxy_headers', '0') === '1' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function user_agent(): string
{
    return mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'cli', 0, 255);
}

/** Short "Chrome on Windows" description of a user agent string. */
function browser_label(?string $ua): string
{
    $ua = (string) $ua;
    $browser = 'Unknown';
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari', 'curl' => 'curl', 'python' => 'Script'] as $k => $v) {
        if (stripos($ua, $k) !== false) {
            $browser = $v;
            break;
        }
    }
    $os = '';
    foreach (['Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iPadOS', 'Mac OS' => 'macOS', 'Linux' => 'Linux'] as $k => $v) {
        if (stripos($ua, $k) !== false) {
            $os = $v;
            break;
        }
    }
    return $browser . ($os ? ' on ' . $os : '');
}

/* ------------------------------------------------------------------
 * Logging, audit trail and notifications
 * ------------------------------------------------------------------ */

/**
 * Write an audit-trail entry.
 *   log_activity('create', 'students', 42, 'Added student Anjali Sharma (GIMT26BBA001)');
 * Actions: create, update, delete, view, export, import, approve, publish, login, logout, ...
 */
function log_activity(string $action, string $module, $recordId = null, string $description = '', string $status = 'success', array $meta = []): void
{
    try {
        db_insert('activity_logs', [
            'user_id'     => function_exists('user_id') ? user_id() : null,
            'action'      => mb_substr($action, 0, 50),
            'module'      => mb_substr($module, 0, 60),
            'record_id'   => $recordId !== null ? (string) $recordId : null,
            'description' => mb_substr($description, 0, 500),
            'status'      => $status,
            'ip_address'  => PHP_SAPI === 'cli' ? '127.0.0.1' : client_ip(),
            'user_agent'  => PHP_SAPI === 'cli' ? 'cli' : user_agent(),
            'meta'        => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        error_log('[GIMT] activity log failed: ' . $e->getMessage());
    }
}

function log_system(string $level, string $message, array $context = []): void
{
    try {
        db_insert('system_logs', ['level' => $level, 'message' => mb_substr($message, 0, 1000), 'context' => $context ? json_encode($context) : null]);
    } catch (Throwable $e) {
        error_log('[GIMT] ' . $level . ': ' . $message);
    }
}

/**
 * Create in-app notifications.
 *   notify('perm:admissions', 'admission', 'New application', 'Anjali Sharma applied for BBA', 'admin/admissions.php?id=5');
 *   notify([1, 4], 'system', 'Backup completed', '...');
 *   notify('all', ...)   // every active admin user
 * $target: user id, array of user ids, 'all', or 'perm:<module>' (users who can view that module).
 */
function notify($target, string $type, string $title, string $message = '', ?string $url = null, string $icon = 'bell'): int
{
    try {
        if ($target === 'all') {
            $userIds = db_column("SELECT id FROM users WHERE status = 'active'");
        } elseif (is_string($target) && str_starts_with($target, 'perm:')) {
            $module = substr($target, 5);
            $userIds = db_column(
                "SELECT DISTINCT u.id FROM users u
                 JOIN user_roles ur ON ur.user_id = u.id
                 JOIN roles r ON r.id = ur.role_id
                 LEFT JOIN role_permissions rp ON rp.role_id = r.id
                 LEFT JOIN permissions p ON p.id = rp.permission_id
                 WHERE u.status = 'active' AND (r.is_super = 1 OR (p.module = ? AND p.action = 'view'))",
                [$module]
            );
        } else {
            $userIds = array_map('intval', (array) $target);
        }
        foreach ($userIds as $uid) {
            db_insert('notifications', [
                'user_id' => (int) $uid, 'type' => $type, 'title' => mb_substr($title, 0, 190),
                'message' => mb_substr($message, 0, 500), 'url' => $url, 'icon' => $icon,
            ]);
        }
        return count($userIds);
    } catch (Throwable $e) {
        error_log('[GIMT] notify failed: ' . $e->getMessage());
        return 0;
    }
}

/* ------------------------------------------------------------------
 * Academic session
 * ------------------------------------------------------------------ */

function current_session(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $id = $_SESSION['academic_session_id'] ?? null;
    $row = null;
    try {
        if ($id) {
            $row = db_row('SELECT * FROM academic_sessions WHERE id = ?', [(int) $id]);
        }
        if (!$row) {
            $row = db_row('SELECT * FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1')
                ?: db_row('SELECT * FROM academic_sessions ORDER BY start_date DESC LIMIT 1');
        }
    } catch (Throwable $e) {
        $row = null;
    }
    $cache = $row ?: ['id' => null, 'name' => date('Y') . '-' . substr((string) (date('Y') + 1), 2)];
    return $cache;
}

function current_session_id(): ?int
{
    $id = current_session()['id'] ?? null;
    return $id ? (int) $id : null;
}

/* ------------------------------------------------------------------
 * Pagination helper for custom (non-CRUD-engine) listings
 * ------------------------------------------------------------------ */

function paginate(int $total, int $page, int $perPage = 20): array
{
    $perPage = max(1, min(200, $perPage));
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    return ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages, 'offset' => ($page - 1) * $perPage,
        'from' => $total ? ($page - 1) * $perPage + 1 : 0, 'to' => min($total, $page * $perPage)];
}
