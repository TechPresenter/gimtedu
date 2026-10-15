<?php
/**
 * Bootstrap + shared helper functions.
 * Every public page starts with:  require_once __DIR__ . '/functions.php';
 */

define('PWF_APP', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail.php';

// ---------------------------------------------------------------------
// Environment
// ---------------------------------------------------------------------
date_default_timezone_set(TIMEZONE);
mb_internal_encoding('UTF-8');

if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}

if (PHP_SAPI !== 'cli' && !defined('PWF_NO_SESSION') && session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' https://checkout.razorpay.com https://*.razorpay.com; "
        . "style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self'; "
        . "connect-src 'self' https://*.razorpay.com; "
        . "frame-src 'self' https://*.razorpay.com https://www.google.com https://maps.google.com https://www.youtube-nocookie.com https://www.youtube.com; "
        . "form-action 'self' https://*.razorpay.com; base-uri 'self'; object-src 'none'; frame-ancestors 'self'");
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

// ---------------------------------------------------------------------
// Programmes (fixed list used for menus, cards and campaign categories)
// ---------------------------------------------------------------------
const PROGRAMS = [
    'education' => [
        'title' => 'Education', 'file' => 'education.php', 'icon' => 'book', 'tone' => 'blue',
        'summary' => 'Building strong futures through learning and school support.',
        'points' => ['Literacy drives and community learning circles', 'Study material distribution', 'School collaboration & support'],
    ],
    'healthcare' => [
        'title' => 'Health', 'file' => 'healthcare.php', 'icon' => 'activity', 'tone' => 'green',
        'summary' => 'Accessible care and awareness for every family.',
        'points' => ['Health camps and screenings', 'Awareness & prevention campaigns', 'Referral & essential health services'],
    ],
    'women-empowerment' => [
        'title' => 'Women Empowerment', 'file' => 'women-empowerment.php', 'icon' => 'users', 'tone' => 'orange',
        'summary' => 'Strengthening livelihoods and leadership among women.',
        'points' => ['Skill development programs', 'Self-Help Group (SHG) formation & support', 'Rights & financial literacy awareness'],
    ],
    'youth-empowerment' => [
        'title' => 'Youth Empowerment', 'file' => 'youth-empowerment.php', 'icon' => 'star', 'tone' => 'gold',
        'summary' => 'Helping young people discover their potential.',
        'points' => ['Career guidance and mentoring', 'Leadership, life & digital skills', 'Community service opportunities'],
    ],
    'rural-development' => [
        'title' => 'Rural Development', 'file' => 'rural-development.php', 'icon' => 'home', 'tone' => 'teal',
        'summary' => 'Strengthening villages and rural communities.',
        'points' => ['Community awareness drives', 'Livelihood & skill-building activities', 'Awareness of welfare schemes'],
    ],
    'environment' => [
        'title' => 'Environment Protection', 'file' => 'environment.php', 'icon' => 'leaf', 'tone' => 'green',
        'summary' => 'Promoting a cleaner, greener and sustainable lifestyle.',
        'points' => ['Tree plantation drives', 'Waste management initiatives', 'Sustainable living practices'],
    ],
    'skill-development' => [
        'title' => 'Skill Development', 'file' => 'skill-development.php', 'icon' => 'tool', 'tone' => 'blue',
        'summary' => 'Training for jobs and enterprise opportunities.',
        'points' => ['Vocational & digital training', 'Career guidance & placement support', 'Entrepreneurship & livelihood opportunities'],
    ],
    'disaster-relief' => [
        'title' => 'Disaster Relief', 'file' => 'disaster-relief.php', 'icon' => 'umbrella', 'tone' => 'orange',
        'summary' => 'Standing with communities in times of crisis.',
        'points' => ['Food & essential supplies', 'Hygiene & health support', 'Relief coordination with volunteers'],
    ],
];

const GALLERY_CATEGORIES = [
    'education' => 'Education',
    'job-placement' => 'Job Placement',
    'women-empowerment' => 'Women Empowerment',
    'health' => 'Health',
    'rural-development' => 'Rural Development',
    'waste-management' => 'Waste Management',
    'youth-empowerment' => 'Youth Empowerment',
    'food-donation' => 'Food Donation',
    'disaster-relief' => 'Disaster Relief',
    'events' => 'Events',
    'general' => 'General',
];

const BLOG_CATEGORIES = [
    'blog' => 'Blog & Articles',
    'news' => 'News',
    'press' => 'Media / Press Release',
    'success_story' => 'Success Story',
    'resource' => 'Digital Resource',
];

function campaign_categories(): array
{
    $list = ['general' => 'General Fund'];
    foreach (PROGRAMS as $key => $program) {
        $list[$key] = $program['title'];
    }
    return $list;
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

function log_activity(string $action, string $details = '', ?int $adminId = null, ?int $userId = null): void
{
    try {
        // Occasionally remove old rate-limit entries so the table stays small
        if (random_int(1, 200) === 1) {
            db_query("DELETE FROM activity_logs WHERE action LIKE 'form\\_%' AND created_at < NOW() - INTERVAL 30 DAY");
        }
        db_insert('activity_logs', [
            'admin_id'   => $adminId,
            'user_id'    => $userId,
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
    db_query('INSERT INTO website_settings (setting_key, setting_value) VALUES (?, ?)
              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $value]);
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

/** A published content page from website_pages. */
function get_page(string $slug): ?array
{
    return db_row("SELECT * FROM website_pages WHERE slug = ? AND status = 'published'", [$slug]);
}

/** Load a page or show 404. */
function require_page(string $slug): array
{
    $page = get_page($slug);
    if (!$page) {
        not_found();
    }
    return $page;
}

function not_found(): void
{
    http_response_code(404);
    $pageTitle = 'Page not found';
    require ROOT_PATH . '/header.php';
    echo '<section class="section"><div class="container narrow text-center">'
        . '<h1>Page not found</h1><p class="lead">Sorry, the page you are looking for does not exist or is no longer available.</p>'
        . '<p><a class="btn btn-primary" href="' . e(url('index.php')) . '">Go to homepage</a></p></div></section>';
    require ROOT_PATH . '/footer.php';
    exit;
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
// Navigation menu (editable in Admin > Menus)
// ---------------------------------------------------------------------
function default_menu(): array
{
    $programs = [['label' => 'Our Work', 'url' => 'our-work.php']];
    foreach (PROGRAMS as $p) {
        $programs[] = ['label' => $p['title'], 'url' => $p['file']];
    }
    return [
        ['label' => 'Home', 'url' => 'index.php'],
        ['label' => 'About', 'url' => 'about.php', 'children' => [
            ['label' => 'About Us', 'url' => 'about.php'],
            ['label' => 'Mission & Vision', 'url' => 'about.php#mission'],
            ['label' => 'Our Team', 'url' => 'our-team.php'],
            ['label' => 'Impact Reports', 'url' => 'impact-reports.php'],
            ['label' => 'Financial Transparency', 'url' => 'financial-transparency.php'],
            ['label' => 'FAQ', 'url' => 'faq.php'],
        ]],
        ['label' => 'Programs', 'url' => 'our-work.php', 'children' => $programs],
        ['label' => 'Get Involved', 'url' => 'volunteer.php', 'children' => [
            ['label' => 'Campaigns', 'url' => 'campaigns.php'],
            ['label' => 'Volunteer With Us', 'url' => 'volunteer.php'],
            ['label' => 'Volunteer Teacher', 'url' => 'volunteer-teacher.php'],
            ['label' => 'Work With Us', 'url' => 'volunteer-staff.php'],
            ['label' => 'Membership', 'url' => 'membership.php'],
            ['label' => 'Scholarship', 'url' => 'scholarship.php'],
            ['label' => 'Request Help', 'url' => 'request-help.php'],
            ['label' => 'Application Status', 'url' => 'application-status.php'],
        ]],
        ['label' => 'Events', 'url' => 'events.php'],
        ['label' => 'Media', 'url' => 'gallery.php', 'children' => [
            ['label' => 'Gallery', 'url' => 'gallery.php'],
            ['label' => 'Blog & News', 'url' => 'blog.php'],
            ['label' => 'Success Stories', 'url' => 'success-stories.php'],
        ]],
        ['label' => 'Contact', 'url' => 'contact.php'],
    ];
}

function main_menu(): array
{
    $json = setting('main_menu');
    if ($json !== '') {
        $menu = json_decode($json, true);
        if (is_array($menu) && $menu) {
            return $menu;
        }
    }
    return default_menu();
}

/** Only allow safe menu link targets. */
function safe_link(string $href): string
{
    $href = trim($href);
    if (preg_match('#^https?://#i', $href) || preg_match('#^(mailto|tel):#i', $href)) {
        return $href;
    }
    if (preg_match('#^[a-z0-9][a-z0-9\-_/]*\.php([?\#][^\s<>"]*)?$#i', $href) || preg_match('#^\#[\w\-]+$#', $href)) {
        return url($href);
    }
    return url('index.php');
}

function current_page(): string
{
    return basename(parse_url($_SERVER['REQUEST_URI'] ?? 'index.php', PHP_URL_PATH) ?: 'index.php') ?: 'index.php';
}

function menu_is_active(array $item): bool
{
    $current = current_page();
    $file = basename(strtok($item['url'] ?? '', '#?'));
    if ($file === $current) {
        return true;
    }
    foreach ($item['children'] ?? [] as $child) {
        if (basename(strtok($child['url'] ?? '', '#?')) === $current) {
            return true;
        }
    }
    return false;
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
// Inline SVG icons (no external icon library)
// ---------------------------------------------------------------------
function icon(string $name, string $class = ''): string
{
    static $icons = [
        'heart' => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
        'book' => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        'activity' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'map-pin' => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'mail' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'menu' => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'x' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'chevron-down' => '<polyline points="6 9 12 15 18 9"/>',
        'chevron-right' => '<polyline points="9 18 15 12 9 6"/>',
        'chevron-left' => '<polyline points="15 18 9 12 15 6"/>',
        'chevrons-right' => '<polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/>',
        'arrow-right' => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
        'arrow-up' => '<line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/>',
        'check' => '<polyline points="20 6 9 17 4 12"/>',
        'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'alert-circle' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'help-circle' => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'printer' => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'lock' => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
        'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'award' => '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',
        'gift' => '<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
        'edit' => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        'trash' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'eye' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'home' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'sliders' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'bar-chart' => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
        'list' => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        'inbox' => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'target' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
        'umbrella' => '<path d="M23 12a11.05 11.05 0 0 0-22 0zm-5 7a3 3 0 0 1-6 0v-7"/>',
        'leaf' => '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>',
        'graduation' => '<path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>',
        'tool' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'star' => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        'trending-up' => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
        'refresh' => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
        'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
        'send' => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'rupee' => '<path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 8.5 8"/><path d="M6 13h3"/><path d="M9 13c6.667 0 6.667-10 0-10"/>',
        'package' => '<line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
        'message' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
        'facebook' => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
        'youtube' => '<path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"/><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02"/>',
        'twitter' => '<path d="M4 4l11.733 16h4.267l-11.733 -16z"/><path d="M4 20l6.768 -6.768m2.46 -2.46l6.772 -6.772"/>',
        'linkedin' => '<path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/>',
        'instagram' => '<rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/>',
        'whatsapp' => '<path d="M3 21l1.65 -3.8a9 9 0 1 1 3.4 2.9l-5.05 .9"/><path d="M9 10a.5 .5 0 0 0 1 0v-1a.5 .5 0 0 0 -1 0v1a5 5 0 0 0 5 5h1a.5 .5 0 0 0 0 -1h-1a.5 .5 0 0 0 0 1"/>',
    ];
    $paths = $icons[$name] ?? $icons['info'];
    return '<svg class="icon' . ($class ? ' ' . e($class) : '') . '" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
}

// ---------------------------------------------------------------------
// Shared page fragments
// ---------------------------------------------------------------------
function page_banner(string $title, string $subtitle = '', array $crumbs = []): void
{
    echo '<section class="page-banner"><div class="container">';
    echo '<nav class="breadcrumb" aria-label="Breadcrumb"><a href="' . e(url('index.php')) . '">Home</a>';
    foreach ($crumbs as $label => $href) {
        echo '<span aria-hidden="true">/</span>' . ($href ? '<a href="' . e(url($href)) . '">' . e($label) . '</a>' : '<span>' . e($label) . '</span>');
    }
    echo '<span aria-hidden="true">/</span><span aria-current="page">' . e($title) . '</span></nav>';
    echo '<h1>' . e($title) . '</h1>';
    if ($subtitle !== '') {
        echo '<p>' . e($subtitle) . '</p>';
    }
    echo '</div></section>';
}

function empty_state(string $message, string $actionLabel = '', string $actionUrl = ''): string
{
    $html = '<div class="empty-state">' . icon('inbox') . '<p>' . e($message) . '</p>';
    if ($actionLabel !== '') {
        $html .= '<a class="btn btn-outline" href="' . e(url($actionUrl)) . '">' . e($actionLabel) . '</a>';
    }
    return $html . '</div>';
}

// ---------------------------------------------------------------------
// Campaigns
// ---------------------------------------------------------------------

/** Base query: campaigns with amount raised from VERIFIED donations only. */
function campaign_sql(): string
{
    return "SELECT c.*, COALESCE(d.raised, 0) AS raised, COALESCE(d.donor_count, 0) AS donor_count
            FROM campaigns c
            LEFT JOIN (
                SELECT campaign_id, SUM(amount) AS raised, COUNT(*) AS donor_count
                FROM donations WHERE status = 'success' AND campaign_id IS NOT NULL
                GROUP BY campaign_id
            ) d ON d.campaign_id = c.id";
}

function campaign_percent(array $c): int
{
    if ((float) $c['goal_amount'] <= 0) {
        return 0;
    }
    return (int) min(100, floor(((float) $c['raised'] / (float) $c['goal_amount']) * 100));
}

function campaign_image(array $c): string
{
    $fallback = is_file(ROOT_PATH . '/assets/images/programs/' . $c['category'] . '.svg')
        ? 'assets/images/programs/' . $c['category'] . '.svg'
        : 'assets/images/programs/general.svg';
    return image_url($c['image'] ?? null, $fallback);
}

function campaign_days_left(array $c): ?int
{
    if (empty($c['end_date'])) {
        return null;
    }
    $diff = (int) floor((strtotime($c['end_date'] . ' 23:59:59') - time()) / 86400);
    return max(0, $diff);
}

function campaign_card(array $c): string
{
    $pct = campaign_percent($c);
    $days = campaign_days_left($c);
    $categories = campaign_categories();
    $link = url('campaign-details.php?slug=' . urlencode($c['slug']));
    $html = '<article class="campaign-card">';
    $html .= '<a class="campaign-media" href="' . e($link) . '"><img src="' . e(campaign_image($c)) . '" alt="' . e($c['title']) . '" loading="lazy">';
    if ($c['is_urgent']) {
        $html .= '<span class="tag tag-urgent">Urgent</span>';
    }
    $html .= '<span class="tag tag-cat">' . e($categories[$c['category']] ?? humanize($c['category'])) . '</span></a>';
    $html .= '<div class="campaign-body"><h3><a href="' . e($link) . '">' . e($c['title']) . '</a></h3>';
    $html .= '<p>' . e(excerpt($c['short_description'], 130)) . '</p>';
    $html .= '<div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $pct . '" aria-label="Funding progress">'
        . '<span style="width:' . $pct . '%"></span></div>';
    $html .= '<div class="campaign-stats"><div><strong>' . e(money($c['raised'])) . '</strong><small>raised</small></div>';
    if ((float) $c['goal_amount'] > 0) {
        $html .= '<div class="text-right"><strong>' . e(money($c['goal_amount'])) . '</strong><small>goal</small></div>';
    }
    $html .= '</div><div class="campaign-meta"><span>' . icon('users') . (int) $c['donor_count'] . ' ' . ((int) $c['donor_count'] === 1 ? 'donation' : 'donations') . '</span>';
    if ($c['status'] !== 'active') {
        $html .= '<span>' . icon('check-circle') . humanize($c['status']) . '</span>';
    } elseif ($days !== null) {
        $html .= '<span>' . icon('clock') . ($days === 0 ? 'Last day' : $days . ' days left') . '</span>';
    }
    $html .= '</div><div class="campaign-actions">';
    if ($c['status'] === 'active') {
        $html .= '<a class="btn btn-donate btn-sm" href="' . e(url('donate.php?campaign=' . (int) $c['id'])) . '">' . icon('heart') . 'Donate</a>';
    }
    $html .= '<a class="btn btn-outline btn-sm" href="' . e($link) . '">Details</a></div></div></article>';
    return $html;
}

// ---------------------------------------------------------------------
// Cards for events and blog posts
// ---------------------------------------------------------------------
function event_card(array $ev): string
{
    $link = url('event-details.php?slug=' . urlencode($ev['slug']));
    $ts = strtotime($ev['start_datetime']);
    return '<article class="event-card">'
        . '<a class="event-media" href="' . e($link) . '"><img src="' . e(image_url($ev['image'], 'assets/images/programs/general.svg')) . '" alt="' . e($ev['title']) . '" loading="lazy">'
        . '<span class="event-date"><strong>' . date('d', $ts) . '</strong>' . date('M', $ts) . '</span></a>'
        . '<div class="event-body"><h3><a href="' . e($link) . '">' . e($ev['title']) . '</a></h3>'
        . '<ul class="meta-list"><li>' . icon('clock') . e(date('D, d M Y · h:i A', $ts)) . '</li>'
        . '<li>' . icon('map-pin') . e($ev['location']) . '</li></ul>'
        . '<p>' . e(excerpt($ev['summary'], 120)) . '</p>'
        . '<a class="link-arrow" href="' . e($link) . '">View details ' . icon('arrow-right') . '</a></div></article>';
}

function blog_card(array $post): string
{
    $link = url('blog-details.php?slug=' . urlencode($post['slug']));
    return '<article class="blog-card">'
        . '<a class="blog-media" href="' . e($link) . '"><img src="' . e(image_url($post['image'], 'assets/images/programs/general.svg')) . '" alt="' . e($post['title']) . '" loading="lazy"></a>'
        . '<div class="blog-body"><span class="eyebrow">' . e(BLOG_CATEGORIES[$post['category']] ?? 'Blog') . ' · ' . e(format_date($post['published_at'])) . '</span>'
        . '<h3><a href="' . e($link) . '">' . e($post['title']) . '</a></h3>'
        . '<p>' . e(excerpt($post['excerpt'], 140)) . '</p>'
        . '<a class="link-arrow" href="' . e($link) . '">Read more ' . icon('arrow-right') . '</a></div></article>';
}

/** Body of a programme page (education.php, healthcare.php, ...). */
function render_program_body(string $key): void
{
    $program = PROGRAMS[$key];
    $page = get_page($key);
    $campaigns = db_rows(campaign_sql() . " WHERE c.status = 'active' AND c.category = ? ORDER BY c.is_urgent DESC, c.created_at DESC LIMIT 3", [$key]);
    $gallery = db_rows("SELECT * FROM gallery WHERE status = 'published' AND category IN (?, ?) ORDER BY sort_order, id DESC LIMIT 6",
        [$key, str_replace('healthcare', 'health', $key)]);
    ?>
    <section class="section">
        <div class="container program-layout">
            <div class="program-main">
                <div class="prose"><?= $page ? $page['content'] : '' ?></div>
            </div>
            <aside class="program-aside">
                <div class="aside-card program-art tone-<?= e($program['tone']) ?>">
                    <img src="<?= e(image_url($page['banner_image'] ?? null, 'assets/images/programs/' . $key . '.svg')) ?>" alt="<?= e($program['title']) ?>">
                </div>
                <div class="aside-card">
                    <h3>Key activities</h3>
                    <ul class="check-list">
                        <?php foreach ($program['points'] as $point): ?>
                            <li><?= icon('check') ?><?= e($point) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="aside-card aside-cta">
                    <h3>Support <?= e($program['title']) ?></h3>
                    <p>Your contribution helps us reach more people through this programme.</p>
                    <a class="btn btn-donate btn-block" href="<?= e(url('donate.php?program=' . $key)) ?>"><?= icon('heart') ?>Donate Now</a>
                    <a class="btn btn-outline btn-block" href="<?= e(url('volunteer.php')) ?>">Volunteer With Us</a>
                </div>
                <div class="aside-card">
                    <h3>Other programmes</h3>
                    <ul class="link-list">
                        <?php foreach (PROGRAMS as $k => $p): if ($k === $key) { continue; } ?>
                            <li><a href="<?= e(url($p['file'])) ?>"><?= icon('chevron-right') ?><?= e($p['title']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </aside>
        </div>
    </section>
    <?php if ($campaigns): ?>
    <section class="section section-tint">
        <div class="container">
            <div class="section-head"><span class="pill">Active campaigns</span><h2>Support <?= e($program['title']) ?> campaigns</h2></div>
            <div class="grid grid-3"><?php foreach ($campaigns as $c) { echo campaign_card($c); } ?></div>
        </div>
    </section>
    <?php endif; ?>
    <?php if ($gallery): ?>
    <section class="section">
        <div class="container">
            <div class="section-head"><span class="pill">Gallery</span><h2>Moments from the field</h2></div>
            <div class="gallery-grid">
                <?php foreach ($gallery as $g): ?>
                    <a class="gallery-item" href="<?= e(url($g['image_path'])) ?>" data-lightbox data-caption="<?= e($g['title']) ?>">
                        <img src="<?= e(url($g['image_path'])) ?>" alt="<?= e($g['title']) ?>" loading="lazy"><span><?= e($g['title']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif;
}

/** Sidebar navigation for the member area. */
function render_member_nav(array $user): void
{
    $items = [
        'member-dashboard.php' => ['grid', 'Dashboard'],
        'my-donations.php' => ['heart', 'My Donations'],
        'my-applications.php' => ['file-text', 'My Applications'],
        'my-certificates.php' => ['award', 'My Certificates'],
        'member-profile.php' => ['user', 'Profile & Password'],
    ];
    $current = current_page();
    echo '<aside class="member-nav"><div class="member-user"><span class="avatar">' . e(mb_strtoupper(mb_substr($user['name'], 0, 1))) . '</span>'
        . '<div><strong>' . e($user['name']) . '</strong><small>' . e($user['email']) . '</small></div></div><ul>';
    foreach ($items as $file => [$ic, $label]) {
        echo '<li><a class="' . ($current === $file ? 'active' : '') . '" href="' . e(url($file)) . '">' . icon($ic) . e($label) . '</a></li>';
    }
    echo '<li><a href="' . e(url('logout.php?token=' . csrf_token())) . '">' . icon('log-out') . 'Logout</a></li></ul></aside>';
}

/** Content page body (about, policies, etc.). */
function render_content_page(array $page): void
{
    echo '<section class="section"><div class="container narrow"><article class="prose">' . $page['content'] . '</article>';
    echo '<p class="muted small page-updated">Last updated: ' . e(format_date($page['updated_at'] ?: $page['created_at'])) . '</p></div></section>';
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

// ---------------------------------------------------------------------
// Payments (Razorpay) - all verification happens on the server
// ---------------------------------------------------------------------
function razorpay_configured(): bool
{
    return RAZORPAY_KEY_ID !== '' && RAZORPAY_KEY_SECRET !== '';
}

function payment_test_mode(): bool
{
    return strpos(RAZORPAY_KEY_ID, 'rzp_test_') === 0;
}

/** Call the Razorpay REST API. Returns [httpStatus, decodedBody]. */
function razorpay_request(string $method, string $endpoint, array $data = []): array
{
    $ch = curl_init(rtrim(RAZORPAY_API_BASE, '/') . '/' . ltrim($endpoint, '/'));
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($data);
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) {
        error_log('Razorpay request failed: ' . curl_error($ch));
        curl_close($ch);
        return [0, null];
    }
    curl_close($ch);
    return [$status, json_decode((string) $body, true)];
}

/** Paise as integer (Razorpay uses the smallest currency unit). */
function to_paise($amount): int
{
    return (int) round(((float) $amount) * 100);
}

function financial_year(?string $date = null): string
{
    $ts = $date ? strtotime($date) : time();
    $y = (int) date('Y', $ts);
    $start = (int) date('n', $ts) >= 4 ? $y : $y - 1;
    return $start . '-' . substr((string) ($start + 1), -2);
}

/**
 * Mark a donation as successful after the payment was verified with the
 * gateway. Safe to call more than once (webhook + checkout callback).
 * Returns true if the donation is (now) successful.
 */
function mark_donation_success(int $donationId, array $payment, string $via, ?int $adminId = null): bool
{
    $pdo = db();
    $newlyConfirmed = false;
    $pdo->beginTransaction();
    try {
        $donation = db_row('SELECT * FROM donations WHERE id = ? FOR UPDATE', [$donationId]);
        if (!$donation) {
            $pdo->rollBack();
            return false;
        }
        if ($donation['status'] === 'refunded') {
            $pdo->rollBack();
            return false;
        }
        if ($donation['status'] !== 'success') {
            db_update('donations', [
                'status'             => 'success',
                'gateway_payment_id' => $payment['id'] ?? $donation['gateway_payment_id'],
                'gateway_signature'  => $payment['signature'] ?? $donation['gateway_signature'],
                'gateway_method'     => isset($payment['method']) ? mb_substr((string) $payment['method'], 0, 40) : $donation['gateway_method'],
                'failure_reason'     => null,
                'paid_at'            => date('Y-m-d H:i:s', isset($payment['created_at']) ? (int) $payment['created_at'] : time()),
                'verified_via'       => $via,
                'verified_by'        => $adminId,
            ], 'id = :id', ['id' => $donationId]);
            $newlyConfirmed = true;
        }
        issue_receipt($donationId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('mark_donation_success failed: ' . $e->getMessage());
        throw $e;
    }

    if ($newlyConfirmed) {
        log_activity('donation_confirmed', 'Donation #' . $donationId . ' confirmed via ' . $via, $adminId);
        send_receipt_email($donationId);
    }
    return true;
}

/** Create the receipt record for a successful donation (only once). */
function issue_receipt(int $donationId): ?array
{
    $donation = db_row("SELECT * FROM donations WHERE id = ? AND status = 'success'", [$donationId]);
    if (!$donation) {
        return null;
    }
    $existing = db_row('SELECT * FROM donation_receipts WHERE donation_id = ?', [$donationId]);
    if ($existing) {
        return $existing;
    }
    $fy = financial_year($donation['paid_at']);
    $id = db_insert('donation_receipts', [
        'donation_id'    => $donationId,
        'receipt_no'     => 'TMP-' . $donationId,
        'financial_year' => $fy,
        'status'         => 'issued',
        'issued_at'      => date('Y-m-d H:i:s'),
    ]);
    $receiptNo = 'PWF/' . $fy . '/' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    db_update('donation_receipts', ['receipt_no' => $receiptNo], 'id = :id', ['id' => $id]);
    return db_row('SELECT * FROM donation_receipts WHERE id = ?', [$id]);
}

function receipt_link(array $donation): string
{
    return abs_url('donation-receipt.php?ref=' . urlencode($donation['donation_ref']) . '&token=' . urlencode($donation['access_token']));
}

function send_receipt_email(int $donationId): void
{
    $d = db_row("SELECT d.*, r.receipt_no, c.title AS campaign_title FROM donations d
                 JOIN donation_receipts r ON r.donation_id = d.id
                 LEFT JOIN campaigns c ON c.id = d.campaign_id
                 WHERE d.id = ? AND d.status = 'success'", [$donationId]);
    if (!$d) {
        return;
    }
    $body = '<p>Dear ' . e($d['donor_name']) . ',</p>'
        . '<p>Thank you for your generous donation of <strong>' . e(money($d['amount'], true)) . '</strong>'
        . ($d['campaign_title'] ? ' towards <strong>' . e($d['campaign_title']) . '</strong>' : '') . ' to ' . e(site_name()) . '.</p>'
        . '<p>Your payment has been verified. Receipt number: <strong>' . e($d['receipt_no']) . '</strong><br>Donation reference: ' . e($d['donation_ref']) . '</p>'
        . '<p><a class="btn" href="' . e(receipt_link($d)) . '">View / download your receipt</a></p>'
        . '<p>Together, let us build a better tomorrow.</p>';
    if (send_mail($d['donor_email'], 'Donation receipt ' . $d['receipt_no'] . ' - ' . site_name(), $body)) {
        db_update('donation_receipts', ['emailed_at' => date('Y-m-d H:i:s')], 'donation_id = :id', ['id' => $donationId]);
    }
}

/** Ask Razorpay whether an order has a captured payment (used by admin re-check). */
function razorpay_find_captured_payment(array $donation): ?array
{
    if (!$donation['gateway_order_id'] || !razorpay_configured()) {
        return null;
    }
    [$status, $body] = razorpay_request('GET', 'orders/' . rawurlencode($donation['gateway_order_id']) . '/payments');
    if ($status !== 200 || empty($body['items'])) {
        return null;
    }
    foreach ($body['items'] as $payment) {
        if (($payment['status'] ?? '') === 'captured'
            && (int) ($payment['amount'] ?? 0) === to_paise($donation['amount'])
            && ($payment['currency'] ?? '') === $donation['currency']) {
            return $payment;
        }
    }
    return null;
}

/** Donor name for public display: first name and last initial (e.g. "Ravi K."). */
function public_donor_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    if (count($parts) < 2) {
        return $parts[0] ?? 'Donor';
    }
    return $parts[0] . ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.';
}

/** Live totals for public counters - verified data only. */
function impact_counters(): array
{
    return [
        'raised'     => (float) db_value("SELECT COALESCE(SUM(amount), 0) FROM donations WHERE status = 'success'"),
        'donations'  => (int) db_value("SELECT COUNT(*) FROM donations WHERE status = 'success'"),
        'volunteers' => (int) db_value("SELECT COUNT(*) FROM volunteers WHERE status = 'approved'")
                      + (int) db_value("SELECT COUNT(*) FROM teacher_applications WHERE status = 'approved'"),
        'events'     => (int) db_value("SELECT COUNT(*) FROM events WHERE status = 'published' AND start_datetime < NOW()"),
        'campaigns'  => (int) db_value("SELECT COUNT(*) FROM campaigns WHERE status IN ('active','completed')"),
    ];
}

/** Registration details entered by the admin (only non-empty values). */
function ngo_details(): array
{
    $fields = [
        'legal_name' => 'Legal name',
        'registration_type' => 'Registered as',
        'registration_number' => 'Registration number',
        'registration_date' => 'Registration date',
        'pan_number' => 'PAN',
        'reg_12a_number' => '12A registration',
        'reg_80g_number' => '80G registration',
        'csr1_number' => 'CSR-1 registration',
        'darpan_id' => 'NITI Aayog Darpan ID',
        'contact_address' => 'Registered office',
        'contact_email' => 'Email',
    ];
    $details = [];
    foreach ($fields as $key => $label) {
        if (setting($key) !== '') {
            $details[$label] = setting($key);
        }
    }
    return $details;
}

/** Public PDF documents uploaded in Admin > Pages (reports, certificates). */
function public_documents(?string $category = null): array
{
    $docs = json_decode(setting('public_documents', '[]'), true) ?: [];
    $docs = array_filter($docs, function ($d) use ($category) {
        return is_array($d) && !empty($d['path']) && is_file(ROOT_PATH . '/' . $d['path'])
            && ($category === null || ($d['category'] ?? '') === $category);
    });
    usort($docs, function ($a, $b) {
        return strcmp($b['date'] ?? '', $a['date'] ?? '');
    });
    return $docs;
}

const DOCUMENT_CATEGORIES = [
    'impact' => 'Impact / Annual Report',
    'financial' => 'Financial Statement',
    'registration' => 'Registration Certificate',
];

function document_list(array $docs): string
{
    $html = '<ul class="doc-list">';
    foreach ($docs as $d) {
        $html .= '<li><a href="' . e(url($d['path'])) . '" target="_blank" rel="noopener">'
            . '<span class="icon-circle">' . icon('file-text') . '</span><span><strong>' . e($d['title']) . '</strong><br>'
            . '<small class="muted">' . e(DOCUMENT_CATEGORIES[$d['category']] ?? 'Document') . (!empty($d['date']) ? ' · ' . e(format_date($d['date'])) : '') . ' · PDF</small></span></a></li>';
    }
    return $html . '</ul>';
}

/** India state list for address forms. */
function indian_states(): array
{
    return ['Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chandigarh',
        'Chhattisgarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Goa', 'Gujarat', 'Haryana',
        'Himachal Pradesh', 'Jammu and Kashmir', 'Jharkhand', 'Karnataka', 'Kerala', 'Ladakh', 'Lakshadweep',
        'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Puducherry',
        'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal'];
}

function state_options(string $selected = ''): string
{
    $html = '<option value="">Select state</option>';
    foreach (indian_states() as $s) {
        $html .= '<option value="' . e($s) . '"' . ($s === $selected ? ' selected' : '') . '>' . e($s) . '</option>';
    }
    return $html;
}

/** Shared lists for forms. */
const GENDERS = ['female' => 'Female', 'male' => 'Male', 'other' => 'Other', 'prefer_not' => 'Prefer not to say'];

const VOLUNTEER_INTERESTS = [
    'Education', 'Health', 'Women Empowerment', 'Youth Empowerment', 'Rural Development', 'Environment Protection',
    'Skill Development', 'Disaster Relief', 'Events & Campaigns', 'Fundraising', 'Social Media & Design', 'Photography & Documentation',
];

const HELP_CATEGORIES = [
    'education' => 'Education support', 'health' => 'Health / medical support', 'women' => 'Women empowerment / SHG support',
    'livelihood' => 'Skill training or livelihood', 'food' => 'Food / ration support', 'disaster' => 'Disaster relief', 'other' => 'Other',
];

const AVAILABILITY_OPTIONS = ['Weekends', 'Weekdays', 'Flexible', 'A few hours a month', 'Full-time (internship)', 'Online / remote only'];

/**
 * Personal detail fields shared by application forms.
 * $req: list of field names that are required.
 */
function render_personal_fields(?array $user, array $req = ['name', 'email', 'phone', 'city'], array $skip = []): void
{
    $field = function (string $name, string $label, string $input) use ($req): void {
        $star = in_array($name, $req, true) ? '<span class="req">*</span>' : '';
        echo '<div class="form-group"><label for="' . $name . '">' . e($label) . $star . '</label>' . $input . field_error($name) . '</div>';
    };
    $r = function (string $name) use ($req): string {
        return in_array($name, $req, true) ? ' required' : '';
    };

    if (!in_array('name', $skip, true)) {
        $field('name', 'Full name', '<input id="name" type="text" name="name" maxlength="120" autocomplete="name"' . $r('name') . ' value="' . e(old('name', $user['name'] ?? '')) . '">');
    }
    $field('email', 'Email', '<input id="email" type="email" name="email" maxlength="190" autocomplete="email"' . $r('email') . ' value="' . e(old('email', $user['email'] ?? '')) . '">');
    $field('phone', 'Mobile number', '<input id="phone" type="tel" name="phone" maxlength="15" autocomplete="tel"' . $r('phone') . ' value="' . e(old('phone', $user['phone'] ?? '')) . '">');
    if (!in_array('date_of_birth', $skip, true)) {
        $field('date_of_birth', 'Date of birth', '<input id="date_of_birth" type="date" name="date_of_birth" max="' . date('Y-m-d') . '"' . $r('date_of_birth') . ' value="' . e(old('date_of_birth')) . '">');
    }
    if (!in_array('gender', $skip, true)) {
        $options = '<option value="">Select</option>';
        foreach (GENDERS as $k => $v) {
            $options .= '<option value="' . $k . '"' . old_selected('gender', $k) . '>' . e($v) . '</option>';
        }
        $field('gender', 'Gender', '<select id="gender" name="gender"' . $r('gender') . '>' . $options . '</select>');
    }
    echo '<div class="form-group full"><label for="address">Address' . (in_array('address', $req, true) ? '<span class="req">*</span>' : '') . '</label>'
        . '<input id="address" type="text" name="address" maxlength="255" autocomplete="street-address"' . $r('address') . ' value="' . e(old('address', $user['address'] ?? '')) . '">' . field_error('address') . '</div>';
    $field('city', 'City / Town', '<input id="city" type="text" name="city" maxlength="80" autocomplete="address-level2"' . $r('city') . ' value="' . e(old('city', $user['city'] ?? '')) . '">');
    $field('state', 'State', '<select id="state" name="state"' . $r('state') . '>' . state_options((string) old('state', $user['state'] ?? '')) . '</select>');
    $field('pincode', 'PIN code', '<input id="pincode" type="text" name="pincode" inputmode="numeric" maxlength="6" data-rule="pincode" autocomplete="postal-code"' . $r('pincode') . ' value="' . e(old('pincode', $user['pincode'] ?? '')) . '">');
}

/** Validation rules matching render_personal_fields(). */
function personal_rules(array $req = ['name', 'email', 'phone', 'city']): array
{
    $rules = [
        'name' => 'min:2|max:120', 'email' => 'email', 'phone' => 'phone', 'date_of_birth' => 'date|past',
        'gender' => 'in:' . implode(',', array_keys(GENDERS)), 'address' => 'min:5|max:255', 'city' => 'min:2|max:80',
        'state' => 'in:' . implode(',', indian_states()), 'pincode' => 'pincode',
    ];
    foreach ($req as $f) {
        $rules[$f] = 'required|' . $rules[$f];
    }
    return $rules;
}

/** Option list helper. */
function options_html(array $options, string $selected, bool $useKeys = true, string $placeholder = 'Select'): string
{
    $html = $placeholder !== '' ? '<option value="">' . e($placeholder) . '</option>' : '';
    foreach ($options as $key => $label) {
        $value = $useKeys ? (string) $key : (string) $label;
        $html .= '<option value="' . e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html;
}

/** Checkbox "chips" for multi-select options. */
function chips_html(string $name, array $options, array $selected, bool $groupRequired = false): string
{
    $html = '<div class="check-group">';
    foreach ($options as $opt) {
        $html .= '<label class="check-chip"><input type="checkbox" name="' . e($name) . '[]" value="' . e($opt) . '"'
            . (in_array($opt, $selected, true) ? ' checked' : '') . ($groupRequired ? ' data-group-required="1"' : '') . '><span>' . e($opt) . '</span></label>';
    }
    return $html . '</div>';
}

/** Open staff positions configured by the admin (one per line: Title | Description). */
function staff_positions(): array
{
    $positions = [];
    foreach (preg_split('/\r?\n/', setting('staff_positions')) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        [$title, $desc] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
        $positions[] = ['title' => mb_substr($title, 0, 120), 'description' => $desc];
    }
    return $positions;
}
