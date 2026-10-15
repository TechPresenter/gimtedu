<?php
/**
 * Core helpers: output, URLs, settings, logging, pagination, sanitising, private files.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

// ---------------------------------------------------------------------
// Output helpers
// ---------------------------------------------------------------------

/** Escape for HTML output. */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escape and keep line breaks. */
function e_nl($value): string
{
    return nl2br(e($value), false);
}

/** Path of the site relative to the domain root, e.g. '' or '/ngo'. */
function base_path(): string
{
    static $path = null;
    if ($path === null) {
        $path = rtrim((string) parse_url(BASE_URL, PHP_URL_PATH), '/');
    }
    return $path;
}

/** Root-relative URL for an internal path: url('donate.php') => /donate.php */
function url(string $path = ''): string
{
    if (preg_match('#^(https?:)?//#i', $path) || strpos($path, 'mailto:') === 0 || strpos($path, 'tel:') === 0) {
        return $path;
    }
    return base_path() . '/' . ltrim($path, '/');
}

/** Absolute URL (for emails, payment gateway and sitemaps). */
function abs_url(string $path = ''): string
{
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

/** URL to a static asset with a cache-busting version. */
function asset(string $path): string
{
    $file = ROOT_PATH . '/' . ltrim($path, '/');
    $v = is_file($file) ? filemtime($file) : 1;
    return url($path) . '?v=' . $v;
}

/** Image URL with fallback. */
function image_url(?string $path, string $fallback = 'assets/images/programs/general.svg'): string
{
    if ($path && strpos($path, '..') === false && is_file(ROOT_PATH . '/' . $path)) {
        return url($path);
    }
    return url($fallback);
}

function redirect(string $path): void
{
    header('Location: ' . (preg_match('#^https?://#i', $path) ? $path : url($path)));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function render_flash(): string
{
    $html = '';
    foreach ($_SESSION['_flash'] ?? [] as $f) {
        $type = in_array($f['type'], ['success', 'error', 'info', 'warning'], true) ? $f['type'] : 'info';
        $icon = ['success' => 'check-circle', 'error' => 'alert-circle', 'info' => 'info', 'warning' => 'alert-circle'][$type];
        $html .= '<div class="alert alert-' . $type . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">'
            . icon($icon) . '<div>' . e($f['message']) . '</div>'
            . '<button type="button" class="alert-close" aria-label="Dismiss">&times;</button></div>';
    }
    unset($_SESSION['_flash']);
    return $html ? '<div class="flash-wrap container">' . $html . '</div>' : '';
}

function format_date(?string $date, string $format = 'd M Y'): string
{
    if (!$date || strpos($date, '0000-00-00') === 0) {
        return '-';
    }
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : '-';
}

/** Indian currency format: 1234567.5 => ₹12,34,568 */
function money($amount, bool $decimals = false): string
{
    $amount = round((float) $amount, 2);
    $negative = $amount < 0;
    $amount = abs($amount);
    $whole = (int) floor($amount);
    $paise = (int) round(($amount - $whole) * 100);
    if ($paise === 100) {
        $whole++;
        $paise = 0;
    }
    if (!$decimals && $paise >= 50) {
        $whole++;
    }
    $digits = (string) $whole;
    if (strlen($digits) > 3) {
        $last3 = substr($digits, -3);
        $rest = substr($digits, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $digits = $rest . ',' . $last3;
    }
    return ($negative ? '-' : '') . '₹' . $digits . ($decimals ? '.' . str_pad((string) $paise, 2, '0', STR_PAD_LEFT) : '');
}

/** Whole number in words using the Indian numbering system (crore, lakh). */
function number_in_words(int $n): string
{
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    if ($n === 0) {
        return 'Zero';
    }
    if ($n < 20) {
        return $ones[$n];
    }
    if ($n < 100) {
        return trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
    }
    foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$div, $name]) {
        if ($n >= $div) {
            $rest = $n % $div;
            return number_in_words(intdiv($n, $div)) . ' ' . $name . ($rest ? ' ' . number_in_words($rest) : '');
        }
    }
    return '';
}

function amount_in_words($amount): string
{
    $amount = round((float) $amount, 2);
    $rupees = (int) floor($amount);
    $paise = (int) round(($amount - $rupees) * 100);
    $text = 'Rupees ' . number_in_words($rupees);
    if ($paise > 0) {
        $text .= ' and ' . number_in_words($paise) . ' Paise';
    }
    return $text . ' Only';
}

function excerpt(?string $text, int $length = 160): string
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)));
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $length - 1)) . '…';
}

function humanize(?string $value): string
{
    return ucwords(str_replace(['_', '-'], ' ', (string) $value));
}

function status_badge(?string $status): string
{
    $map = [
        'success' => 'green', 'approved' => 'green', 'published' => 'green', 'active' => 'green', 'resolved' => 'green',
        'assisted' => 'green', 'disbursed' => 'green', 'issued' => 'green', 'subscribed' => 'green', 'valid' => 'green',
        'registered' => 'green', 'attended' => 'green', 'completed' => 'blue', 'selected' => 'green',
        'pending' => 'gold', 'new' => 'gold', 'draft' => 'gray', 'under_review' => 'blue', 'in_progress' => 'blue',
        'shortlisted' => 'blue', 'on_hold' => 'gray', 'hidden' => 'gray', 'closed' => 'gray', 'inactive' => 'gray',
        'unsubscribed' => 'gray', 'failed' => 'red', 'rejected' => 'red', 'refunded' => 'red', 'blocked' => 'red',
        'cancelled' => 'red', 'revoked' => 'red', 'suspended' => 'red',
    ];
    $tone = $map[$status] ?? 'gray';
    return '<span class="badge badge-' . $tone . '">' . e(humanize($status)) . '</span>';
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // Behind a trusted proxy/CDN (e.g. Cloudflare) use the header it sets, see config.php
    if (TRUSTED_PROXY_HEADER !== '' && !empty($_SERVER[TRUSTED_PROXY_HEADER])) {
        $forwarded = trim(explode(',', (string) $_SERVER[TRUSTED_PROXY_HEADER])[0]);
        if (filter_var($forwarded, FILTER_VALIDATE_IP)) {
            $ip = $forwarded;
        }
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function user_agent(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

function slugify(string $text): string
{
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/u', '-', $text);
    $text = trim((string) $text, '-');
    return $text !== '' ? mb_substr($text, 0, 180) : 'item-' . bin2hex(random_bytes(3));
}

/** A slug that is unique in the given table (table name is hard-coded by callers). */
function unique_slug(string $table, string $text, int $ignoreId = 0): string
{
    $base = slugify($text);
    $slug = $base;
    $i = 2;
    while ((int) db_value("SELECT COUNT(*) FROM `$table` WHERE slug = ? AND id <> ?", [$slug, $ignoreId]) > 0) {
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

/** Human readable reference like PWF-VOL-2026-00012 */
function reference_no(string $type, int $id): string
{
    return 'PWF-' . $type . '-' . date('Y') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}

function log_activity(string $action, string $details = '', ?int $userId = null, bool $isStaff = false): void
{
    try {
        // Occasionally remove old rate-limit entries so the table stays small
        if (random_int(1, 200) === 1) {
            db_query("DELETE FROM activity_logs WHERE action LIKE 'form\\_%' AND created_at < NOW() - INTERVAL 30 DAY");
        }
        db_insert('activity_logs', [
            'user_id'    => $userId,
            'is_staff'   => $isStaff ? 1 : 0,
            'action'     => mb_substr($action, 0, 80),
            'details'    => mb_substr($details, 0, 500),
            'ip_address' => client_ip(),
            'user_agent' => user_agent(),
        ]);
    } catch (Throwable $e) {
        error_log('Activity log failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// Settings & pages
// ---------------------------------------------------------------------
function settings_all(bool $refresh = false): array
{
    static $settings = null;
    if ($settings === null || $refresh) {
        $settings = [];
        foreach (db_rows('SELECT setting_key, setting_value FROM website_settings') as $row) {
            $settings[$row['setting_key']] = (string) $row['setting_value'];
        }
    }
    return $settings;
}

function setting(string $key, string $default = ''): string
{
    $all = settings_all();
    return isset($all[$key]) && $all[$key] !== '' ? $all[$key] : $default;
}

function save_setting(string $key, string $value): void
{
    db_query('INSERT INTO website_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
        [$key, $value, function_exists('current_user_id') ? current_user_id() : null]);
    settings_all(true);
}

/** JSON setting decoded to an array. */
function setting_json(string $key): array
{
    $data = json_decode(setting($key, '[]'), true);
    return is_array($data) ? $data : [];
}

function site_name(): string
{
    return setting('site_name', 'Prerit Welfare Foundation');
}

function site_logo(): string
{
    $custom = setting('logo_path');
    return $custom ? image_url($custom, 'assets/images/logo.png') : url('assets/images/logo.png');
}

/** Uploaded horizontal (wide) logo, or '' to use the built-in emblem + wordmark lockup. */
function site_logo_horizontal(): string
{
    $custom = setting('logo_horizontal_path');
    return ($custom && is_file(ROOT_PATH . '/' . $custom)) ? url($custom) : '';
}

/** Social links that have been configured in settings. */
function social_links(): array
{
    $links = [];
    foreach (['facebook' => 'Facebook', 'youtube' => 'YouTube', 'twitter' => 'X (Twitter)', 'linkedin' => 'LinkedIn', 'instagram' => 'Instagram'] as $key => $label) {
        $href = setting('social_' . $key);
        if ($href && preg_match('#^https?://#i', $href)) {
            $links[$key] = ['label' => $label, 'url' => $href];
        }
    }
    return $links;
}

// ---------------------------------------------------------------------
// Pagination
// ---------------------------------------------------------------------
function paginate(int $total, int $perPage = 20): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
    return ['total' => $total, 'per_page' => $perPage, 'page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage];
}

function pagination_links(array $p): string
{
    if ($p['pages'] <= 1) {
        return '';
    }
    $query = $_GET;
    $link = function (int $page) use ($query): string {
        $query['page'] = $page;
        return '?' . http_build_query($query);
    };
    $html = '<nav class="pagination" aria-label="Pagination">';
    if ($p['page'] > 1) {
        $html .= '<a href="' . e($link($p['page'] - 1)) . '" rel="prev">&laquo; Prev</a>';
    }
    $start = max(1, $p['page'] - 2);
    $end = min($p['pages'], $p['page'] + 2);
    if ($start > 1) {
        $html .= '<a href="' . e($link(1)) . '">1</a>' . ($start > 2 ? '<span class="dots">…</span>' : '');
    }
    for ($i = $start; $i <= $end; $i++) {
        $html .= $i === $p['page']
            ? '<span class="current" aria-current="page">' . $i . '</span>'
            : '<a href="' . e($link($i)) . '">' . $i . '</a>';
    }
    if ($end < $p['pages']) {
        $html .= ($end < $p['pages'] - 1 ? '<span class="dots">…</span>' : '') . '<a href="' . e($link($p['pages'])) . '">' . $p['pages'] . '</a>';
    }
    if ($p['page'] < $p['pages']) {
        $html .= '<a href="' . e($link($p['page'] + 1)) . '" rel="next">Next &raquo;</a>';
    }
    return $html . '</nav>';
}

// ---------------------------------------------------------------------
// HTML sanitizer for rich text written in the admin panel
// ---------------------------------------------------------------------
function clean_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    $allowed = [
        'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 'small' => [],
        'h2' => ['id'], 'h3' => ['id'], 'h4' => ['id'], 'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [],
        'a' => ['href', 'title', 'target'], 'img' => ['src', 'alt', 'width', 'height'], 'hr' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
        'figure' => [], 'figcaption' => [], 'span' => [], 'div' => [],
    ];
    $drop = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'textarea', 'button', 'select',
        'svg', 'math', 'link', 'meta', 'base', 'frame', 'frameset', 'applet', 'template', 'noscript'];

    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = $doc->getElementsByTagName('div')->item(0);
    if (!$root) {
        return '';
    }

    $safeUrl = function (string $value): bool {
        $value = trim(preg_replace('/[\x00-\x20]+/', '', $value));
        if ($value === '') {
            return false;
        }
        if (preg_match('#^(https?:|mailto:|tel:|\#|/)#i', $value)) {
            return true;
        }
        // relative link without a scheme
        return !preg_match('#^[a-z][a-z0-9+.\-]*:#i', $value);
    };

    $walk = function (DOMNode $node) use (&$walk, $allowed, $drop, $safeUrl): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if (in_array($tag, $drop, true)) {
                $node->removeChild($child);
                continue;
            }
            if (!isset($allowed[$tag])) {
                $walk($child);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->nodeName);
                if (!in_array($name, $allowed[$tag], true)) {
                    $child->removeAttribute($attr->nodeName);
                    continue;
                }
                if (in_array($name, ['href', 'src'], true) && !$safeUrl($attr->nodeValue)) {
                    $child->removeAttribute($attr->nodeName);
                }
                if ($name === 'target' && $attr->nodeValue !== '_blank') {
                    $child->removeAttribute('target');
                }
            }
            if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
                $child->setAttribute('rel', 'noopener noreferrer');
            }
            $walk($child);
        }
    };
    $walk($root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return trim($out);
}


// ---------------------------------------------------------------------
// Private file download (documents, certificates). Caller must have
// already checked that the visitor is allowed to see the file.
// ---------------------------------------------------------------------
function send_private_file(?string $path, string $downloadName): void
{
    if (!$path || !preg_match('#^uploads/(documents|certificates)/[A-Za-z0-9._\-/]+$#', $path) || strpos($path, '..') !== false) {
        not_found();
    }
    $full = ROOT_PATH . '/' . $path;
    if (!is_file($full)) {
        not_found();
    }
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    $safeName = preg_replace('/[^A-Za-z0-9._\-]/', '_', $downloadName) . '.' . $ext;
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($full));
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($full);
    exit;
}
