<?php
/**
 * Notice Board & Events (unit "notices_events") — shared data access + render helpers for the public pages
 * notices.php (/notices), notice.php (/notices/{slug}), events.php (/events) and event.php (/events/{slug}).
 *
 * Data (no invented content — everything comes from the communication module tables):
 *   notices              show_on_website = 1, status published (current) or expired/archived (archive), publish_date
 *                        reached, public audiences only (never faculty / staff internal notices).
 *   events               show_on_website = 1, status published / completed / cancelled (drafts never),
 *   event_registrations  live seat counts (status <> cancelled) against events.max_participants,
 *   galleries            a past event's photos (gallery slug = event slug, or the gallery dated on the event day).
 *
 * The file only declares functions (safe to include before includes/config.php — notice.php / event.php resolve the
 * record first so an unknown slug can hand over to the site-wide 404.php). All helpers are prefixed ne_.
 */

/* =====================================================================================================================
 * Basics
 * ===================================================================================================================*/

/** Run a query-ish closure; log and return $default on failure (pages must render even when a table is missing). */
function ne_try(callable $fn, $default = null)
{
    try {
        return $fn();
    } catch (Throwable $e) {
        if (function_exists('log_system')) {
            log_system('error', 'notices_events: ' . $e->getMessage());
        }
        return $default;
    }
}

function ne_now(): string
{
    return date('Y-m-d H:i:s');
}

/** Trimmed scalar query-string value; '' when missing or not a scalar (e.g. ?q[]=x — never "Array" + a PHP warning). */
function ne_get(string $key): string
{
    $v = $_GET[$key] ?? '';
    return is_scalar($v) ? trim((string) $v) : '';
}

/**
 * Escaped text with the search words wrapped in <mark>. Matching runs on the raw text and every piece is escaped
 * separately, so a search such as "amp" or "39" can never split an HTML entity (&amp; / &#039;) of the output.
 */
function ne_highlight(string $text, array $words): string
{
    $words = array_values(array_filter(array_map('strval', $words), fn ($w) => mb_strlen($w) >= 2));
    if ($words === []) {
        return e($text);
    }
    usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a)); // longest first: "exam" before "ex"
    $parts = preg_split('/(' . implode('|', array_map(fn ($w) => preg_quote($w, '/'), $words)) . ')/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return e($text);
    }
    $out = '';
    foreach ($parts as $i => $part) {
        // px-0: the global mark style pads 2px each side, which split highlighted word parts ("Exam ination")
        $out .= $i % 2 ? '<mark class="px-0">' . e($part) . '</mark>' : e($part);
    }
    return $out;
}

/**
 * SQL expression with the visible text of a rich-text column (tags stripped, &amp; decoded) for keyword search —
 * a LIKE on the raw HTML would match markup ("strong", "li", "href"). REGEXP_REPLACE: MySQL 8+ / MariaDB 10.0.5+.
 */
function ne_text_sql(string $column): string
{
    return "REPLACE(REGEXP_REPLACE(COALESCE($column, ''), '<[^>]*>', ' '), '&amp;', '&')";
}

/** /path?query with empty params (and page 1) removed. */
function ne_query_url(string $path, array $params = [], string $hash = ''): string
{
    $params = array_filter($params, fn ($v) => $v !== null && $v !== '' && $v !== false);
    if (isset($params['page']) && (int) $params['page'] <= 1) {
        unset($params['page']);
    }
    return base_url(ltrim($path, '/')) . ($params ? '?' . http_build_query($params) : '') . $hash;
}

/** Same as ne_query_url() but absolute (canonical / JSON-LD / share links). */
function ne_abs_url(string $path, array $params = []): string
{
    $params = array_filter($params, fn ($v) => $v !== null && $v !== '' && $v !== false);
    if (isset($params['page']) && (int) $params['page'] <= 1) {
        unset($params['page']);
    }
    return absolute_url(ltrim($path, '/')) . ($params ? '?' . http_build_query($params) : '');
}

/** Absolute URL for an image path / site URL (JSON-LD, OG). */
function ne_abs_image(?string $path, string $fallback = 'assets/images/site/news-campus.jpg'): string
{
    $path = trim((string) $path) ?: $fallback;
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return absolute_url(ltrim($path, '/'));
}

/** Plain-text excerpt of (rich) text. */
function ne_excerpt(?string $html, int $limit = 180): string
{
    $text = str_replace(['</p>', '<br>', '<br/>', '<br />', '</li>', '</h2>', '</h3>', '</div>'], ' ', (string) $html);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1), " ,.;:-") . '…' : $text;
}

/** Rich text for output: sanitised HTML, or escaped paragraphs when the field holds plain text. */
function ne_rich(?string $text): string
{
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }
    if (!preg_match('/<[a-z][^>]*>/i', $text)) {
        $out = '';
        foreach (preg_split('/\n\s*\n/', $text) as $para) {
            $out .= '<p>' . nl2br(e(trim($para))) . '</p>';
        }
        return $out;
    }
    $html = sanitize_html($text);
    // the page title is the only <h1>: headings typed as H1 in the editor become H2
    $html = (string) preg_replace('#<(/?)h1\b#i', '<$1h2', $html);
    // external links open safely in a new tab
    return (string) preg_replace_callback('/<a\s([^>]*href="https?:\/\/[^"]+"[^>]*)>/i', function ($m) {
        $attrs = $m[1];
        if (str_contains($attrs, (string) ($_SERVER['HTTP_HOST'] ?? '#none#'))) {
            return $m[0];
        }
        $attrs = preg_replace('/\s(target|rel)="[^"]*"/i', '', $attrs);
        return '<a ' . trim($attrs) . ' target="_blank" rel="noopener">';
    }, $html);
}

/** ISO 8601 (Asia/Kolkata) for a DB datetime. */
function ne_iso(?string $dt): ?string
{
    if (!$dt || str_starts_with($dt, '0000')) {
        return null;
    }
    try {
        return (new DateTime($dt, new DateTimeZone(date_default_timezone_get())))->format('c');
    } catch (Throwable $e) {
        return null;
    }
}

/** Lucide "pin" glyph (not in the shared icon set). */
function ne_pin_icon(string $class = 'h-3.5 w-3.5'): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" class="' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . '<path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/></svg>';
}

/** BreadcrumbList JSON-LD. $items = [['Home', ''], ['Notice Board', 'notices'], ['Title', null]] (null = current page). */
function ne_breadcrumb_ld(array $items): array
{
    $list = [];
    foreach (array_values($items) as $i => [$name, $path]) {
        $list[] = array_filter(['@type' => 'ListItem', 'position' => $i + 1, 'name' => (string) $name,
            'item' => $path === null ? null : absolute_url(ltrim((string) $path, '/'))], fn ($v) => $v !== null);
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
}

/** Publisher / organiser organisation for JSON-LD. */
function ne_org_ld(): array
{
    return ['@type' => 'CollegeOrUniversity', 'name' => institute_name(), 'url' => absolute_url(''),
        'logo' => ['@type' => 'ImageObject', 'url' => absolute_url('assets/images/logo.svg')]];
}

/**
 * site_header() + extra <link> tags in <head> (rel prev/next for paginated listings, RSS alternate). The shared header
 * has no slot for them yet, so its output is buffered and the links are inserted before </head>.
 * @param array $links [['rel' => 'prev', 'href' => absolute url, 'type' => ?, 'title' => ?], …] (empty hrefs skipped)
 */
function ne_site_header(array $page, array $links = []): void
{
    $tags = '';
    foreach ($links as $l) {
        if (empty($l['href']) || empty($l['rel'])) {
            continue;
        }
        $tags .= '<link rel="' . e($l['rel']) . '" href="' . e($l['href']) . '"' . (!empty($l['type']) ? ' type="' . e($l['type']) . '"' : '')
            . (!empty($l['title']) ? ' title="' . e($l['title']) . '"' : '') . ">\n";
    }
    if ($tags === '') {
        site_header($page);
        return;
    }
    ob_start();
    try {
        site_header($page);
    } finally {
        $html = (string) ob_get_clean();
    }
    $pos = stripos($html, '</head>');
    echo $pos === false ? $html : substr_replace($html, $tags, $pos, 0);
}

/** Is a local image big enough to fill the page-hero photo frame without visible upscaling? (remote = assume yes) */
function ne_image_is_large(?string $path, int $minWidth = 760, int $minHeight = 440): bool
{
    $path = trim((string) $path);
    if ($path === '' || preg_match('#^https?://#i', $path)) {
        return $path !== '';
    }
    $abs = APP_ROOT . '/' . ltrim($path, '/');
    if (str_contains($path, '..') || !is_file($abs)) {
        return false;
    }
    $size = @getimagesize($abs);
    return $size ? ($size[0] >= $minWidth && $size[1] >= $minHeight) : true;
}

/** Does the website have this public route yet? (avoid dead links to pages other units are still building) */
function ne_route_exists(string $route): bool
{
    $file = APP_ROOT . '/' . trim($route, '/') . '.php';
    return is_file($file);
}

/* =====================================================================================================================
 * Notices — data
 * ===================================================================================================================*/

/**
 * Notice categories: label, icon, badge tint. Order = the brief's notice-board order; any other value stored in
 * notices.category still renders (label from the key).
 */
function ne_notice_category_meta(): array
{
    return [
        'admission' => ['Admission', 'graduation-cap', 'orange'],
        'examination' => ['Examination', 'clipboard-list', 'navy'],
        'result' => ['Result', 'award', 'green'],
        'results' => ['Result', 'award', 'green'],
        'holiday' => ['Holiday', 'calendar-days', 'sun'],
        'event' => ['Event', 'party-popper', 'lavender'],
        'scholarship' => ['Scholarship', 'badge-indian-rupee', 'pistachio'],
        'deadline' => ['Deadline', 'alarm-clock', 'rose'],
        'deadlines' => ['Deadline', 'alarm-clock', 'rose'],
        'general' => ['General', 'megaphone', 'sky'],
        'academic' => ['Academic', 'book-open', 'sky'],
        'placement' => ['Placement', 'briefcase-business', 'aqua'],
        'fee' => ['Fees', 'wallet', 'sand'],
        'circular' => ['Circular', 'scroll-text', 'navy'],
        'urgent' => ['Urgent', 'triangle-alert', 'rose'],
    ];
}

/** ['key', 'label', 'icon', 'tint'] for a notice category value. */
function ne_notice_category(string $key): array
{
    $meta = ne_notice_category_meta()[$key] ?? [label_from_key($key ?: 'general'), 'megaphone', 'sky'];
    return ['key' => $key, 'label' => $meta[0], 'icon' => $meta[1], 'tint' => $meta[2]];
}

/** Audiences that may appear on the public website (internal faculty / staff notices never do). */
function ne_public_audiences(): array
{
    // 'program' = legacy spelling of 'course' (schema comment) — treated the same
    return ['all', 'students', 'parents', 'alumni', 'department', 'course', 'program', 'semester'];
}

/** "For: …" label for a notice row (joined department / program names). */
function ne_audience_label(array $n): string
{
    $prog = trim((string) ($n['program_short'] ?? '')) ?: trim((string) ($n['program_name'] ?? ''));
    switch ((string) ($n['audience'] ?? 'all')) {
        case 'students':
            return 'All students';
        case 'parents':
            return 'Parents' . ($prog !== '' ? ' · ' . $prog : '');
        case 'alumni':
            return 'Alumni';
        case 'department':
            return trim((string) ($n['department_name'] ?? '')) !== '' ? 'Students · ' . $n['department_name'] : 'Department students';
        case 'course':
        case 'program':
            return $prog !== '' ? $prog . ' students' : 'Program students';
        case 'semester':
            return ($prog !== '' ? $prog : 'All programs') . ' · Semester ' . (int) ($n['semester_no'] ?? 0);
        default:
            return 'Everyone';
    }
}

/** SELECT … FROM for public notice rows (adds is_archived + joined names). $now is bound twice: pass [$now, $now]. */
function ne_notice_from(): string
{
    return "FROM notices n LEFT JOIN departments d ON d.id = n.department_id LEFT JOIN programs p ON p.id = n.program_id";
}

function ne_notice_columns(): string
{
    return "n.id, n.title, n.slug, n.category, n.audience, n.description, n.attachment, n.publish_date, n.expiry_date, n.is_pinned,
            n.status, n.semester_no, n.created_at, n.updated_at, d.name AS department_name, p.name AS program_name, p.short_name AS program_short,
            (CASE WHEN n.status IN ('expired', 'archived') OR (n.expiry_date IS NOT NULL AND n.expiry_date < ?) THEN 1 ELSE 0 END) AS is_archived";
}

/**
 * WHERE clause + args for public notices.
 * @param string|null $scope 'current' | 'archive' | null (both — detail pages)
 */
function ne_notice_where(?string $scope, string $now): array
{
    $aud = ne_public_audiences();
    $sql = "n.show_on_website = 1 AND n.status IN ('published', 'expired', 'archived') AND n.audience IN (" . implode(',', array_fill(0, count($aud), '?')) . ")
            AND (n.publish_date IS NULL OR n.publish_date <= ?)";
    $args = array_merge($aud, [$now]);
    if ($scope === 'current') {
        $sql .= " AND n.status = 'published' AND (n.expiry_date IS NULL OR n.expiry_date >= ?)";
        $args[] = $now;
    } elseif ($scope === 'archive') {
        $sql .= " AND (n.status IN ('expired', 'archived') OR (n.expiry_date IS NOT NULL AND n.expiry_date < ?))";
        $args[] = $now;
    }
    return [$sql, $args];
}

/** Published-on date of a notice (publish_date, else created_at). */
function ne_notice_date(array $n): string
{
    return (string) (($n['publish_date'] ?? '') ?: ($n['created_at'] ?? ''));
}

function ne_notice_url(array $n): string
{
    return base_url('notices/' . rawurlencode((string) $n['slug']));
}

/** "New" = published within setting notice_new_days (default 7) days. */
function ne_is_new(?string $date): bool
{
    $ts = $date ? strtotime($date) : false;
    $days = max(1, (int) setting('notice_new_days', 7));
    return $ts && $ts <= time() && $ts >= time() - $days * 86400;
}

/** Latest live notices (sidebar / RSS / related). */
function ne_latest_notices(int $limit = 6, array $exclude = [], ?string $category = null): array
{
    $now = ne_now();
    [$where, $args] = ne_notice_where('current', $now);
    if ($exclude) {
        $where .= ' AND n.id NOT IN (' . implode(',', array_fill(0, count($exclude), '?')) . ')';
        array_push($args, ...array_map('intval', $exclude));
    }
    if ($category !== null) {
        $where .= ' AND n.category = ?';
        $args[] = $category;
    }
    $limit = max(1, min(50, $limit));
    return ne_try(fn () => db_all('SELECT ' . ne_notice_columns() . ' ' . ne_notice_from() . " WHERE $where
        ORDER BY COALESCE(n.publish_date, n.created_at) DESC, n.id DESC LIMIT $limit", array_merge([$now], $args)), []);
}

/**
 * Attachment of a notice: local files must exist (no dead download links); private storage is never exposed.
 * Returns ['url', 'name' (download name), 'ext', 'type', 'size', 'icon'] or null.
 */
function ne_attachment(?string $path, string $title = 'notice'): ?array
{
    $path = trim(str_replace('\\', '/', (string) $path));
    if ($path === '') {
        return null;
    }
    $size = null;
    if (preg_match('#^https?://#i', $path)) {
        $url = $path;
    } else {
        $rel = ltrim($path, '/');
        if (str_contains($rel, '..') || !str_starts_with($rel, 'assets/')) {
            return null;
        }
        $abs = APP_ROOT . '/' . $rel;
        if (!is_file($abs)) {
            return null;
        }
        $url = base_url($rel);
        $size = (int) @filesize($abs);
    }
    $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    $types = [
        'pdf' => ['PDF document', 'file-text'], 'doc' => ['Word document', 'file-text'], 'docx' => ['Word document', 'file-text'],
        'xls' => ['Excel sheet', 'file-spreadsheet'], 'xlsx' => ['Excel sheet', 'file-spreadsheet'], 'csv' => ['CSV file', 'file-spreadsheet'],
        'ppt' => ['PowerPoint', 'presentation'], 'pptx' => ['PowerPoint', 'presentation'],
        'jpg' => ['Image', 'file-image'], 'jpeg' => ['Image', 'file-image'], 'png' => ['Image', 'file-image'], 'webp' => ['Image', 'file-image'],
        'zip' => ['ZIP archive', 'file-archive'],
    ];
    [$type, $icon] = $types[$ext] ?? [($ext !== '' ? strtoupper($ext) . ' file' : 'File'), 'file-down'];
    $base = mb_substr(slugify($title), 0, 80);
    return ['url' => $url, 'name' => $base . ($ext !== '' ? '.' . $ext : ''), 'ext' => $ext, 'short' => $ext !== '' ? strtoupper($ext) : 'FILE',
        'type' => $type, 'size' => $size ? human_filesize($size) : null, 'bytes' => $size, 'icon' => $icon];
}

/** Attachment download card (detail page + detailed list view). */
function ne_attachment_card(array $att, string $title, bool $compact = false): string
{
    $meta = $att['type'] . ($att['size'] ? ' · ' . $att['size'] : '');
    return '<div class="flex flex-col gap-3 rounded-2xl border border-warm-2 bg-warm-50 p-4 sm:flex-row sm:items-center' . ($compact ? '' : ' sm:p-5') . '">'
        . '<span class="relative inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white text-accent-700 shadow-sm ring-1 ring-warm-2">' . icon($att['icon'], 'h-6 w-6')
        . '<span class="absolute -bottom-1.5 left-1/2 -translate-x-1/2 rounded bg-accent-600 px-1 text-[9px] font-bold leading-4 text-white">' . e($att['short']) . '</span></span>'
        . '<span class="min-w-0 flex-1"><span class="block text-sm font-semibold text-brand-900 [overflow-wrap:anywhere]">' . e($title) . '</span>'
        . '<span class="block text-xs text-slate-500">' . e($meta) . '</span></span>'
        . '<span class="flex shrink-0 flex-wrap gap-2">'
        . '<a href="' . e($att['url']) . '" class="btn btn-accent btn-sm" download="' . e($att['name']) . '">' . icon('download', 'h-4 w-4') . 'Download<span class="sr-only"> ' . e($att['type']) . ': ' . e($title) . '</span></a>'
        . ($att['ext'] === 'pdf' || in_array($att['ext'], ['jpg', 'jpeg', 'png', 'webp'], true)
            ? '<a href="' . e($att['url']) . '" class="btn btn-secondary btn-sm" target="_blank" rel="noopener">' . icon('external-link', 'h-4 w-4') . 'View<span class="sr-only"> ' . e($title) . ' (opens in a new tab)</span></a>' : '')
        . '</span></div>';
}

/* =====================================================================================================================
 * Notices — render
 * ===================================================================================================================*/

/** Small category badge. */
function ne_category_badge(array $cat, string $extra = ''): string
{
    return '<span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold tint-' . e($cat['tint']) . ($extra !== '' ? ' ' . $extra : '') . '">'
        . icon($cat['icon'], 'h-3.5 w-3.5') . e($cat['label']) . '</span>';
}

/** Status badges of a notice (pinned / new / archived). */
function ne_notice_badges(array $n): string
{
    $archived = !empty($n['is_archived']);
    $html = '';
    if (!$archived && !empty($n['is_pinned'])) {
        $html .= '<span class="inline-flex items-center gap-1 rounded-full bg-brand-900 px-2.5 py-0.5 text-xs font-semibold text-white">' . ne_pin_icon() . 'Pinned</span>';
    }
    if (!$archived && ne_is_new(ne_notice_date($n))) {
        $html .= '<span class="inline-flex items-center rounded-full bg-accent-600 px-2.5 py-0.5 text-xs font-bold uppercase tracking-wide text-white">New</span>';
    }
    if ($archived) {
        $html .= '<span class="badge badge-slate">' . icon('archive', 'h-3.5 w-3.5') . 'Archived</span>';
    }
    return $html;
}

/**
 * Notice board item (works in every view mode of view_mode_open('notices', ['class' => 'group/vm'])):
 * date tile · category / pinned / new / archived badges · title · excerpt · meta (published, valid till, audience,
 * attachment) · detailed: attachment card.
 * @param array $o heading (h2|h3), highlight (search words to <mark>)
 */
function ne_notice_item(array $n, array $o = []): string
{
    $cat = ne_notice_category((string) $n['category']);
    $url = ne_notice_url($n);
    $pub = ne_notice_date($n);
    $pubTs = strtotime($pub) ?: time();
    $archived = !empty($n['is_archived']);
    $pinned = !$archived && !empty($n['is_pinned']);
    $att = ne_attachment($n['attachment'] ?? null, (string) $n['title']);
    $h = in_array($o['heading'] ?? 'h3', ['h2', 'h3'], true) ? ($o['heading'] ?? 'h3') : 'h3';
    $expiry = !empty($n['expiry_date']) ? strtotime((string) $n['expiry_date']) : null;
    $mark = fn (string $text): string => ne_highlight($text, (array) ($o['highlight'] ?? []));

    $cls = 'view-item !grid-cols-1' . ($pinned ? ' !border-accent-200 bg-gradient-to-br from-accent-50/80 via-white to-white' : '') . ($archived ? ' opacity-95' : '');
    $tile = $pinned ? 'bg-brand-900 text-white' : 'bg-warm text-brand-900';
    $html = '<article class="' . $cls . '">';
    $html .= '<div class="vm-body"><div class="flex w-full items-start gap-4">';
    $html .= '<time datetime="' . e(date('Y-m-d', $pubTs)) . '" class="flex h-12 w-12 shrink-0 flex-col sm:h-[3.75rem] sm:w-[3.75rem] items-center justify-center rounded-2xl ' . $tile . ' group-data-[view=compact]/vm:hidden">'
        . '<span class="font-display text-lg font-extrabold leading-none sm:text-xl">' . date('d', $pubTs) . '</span>'
        . '<span class="mt-1 text-[10.5px] font-bold uppercase tracking-wider ' . ($pinned ? 'text-accent-300' : 'text-accent-700') . '">' . date('M', $pubTs) . '</span>'
        . '<span class="sr-only"> ' . date('Y', $pubTs) . '</span></time>';
    $html .= '<div class="min-w-0 flex-1">';
    $html .= '<p class="flex flex-wrap items-center gap-1.5">' . ne_category_badge($cat) . ne_notice_badges($n)
        . '<span class="hidden text-xs font-medium text-slate-500 group-data-[view=compact]/vm:inline">' . e(date('d M Y', $pubTs)) . '</span></p>';
    $html .= '<' . $h . ' class="mt-2 font-display text-[17px] font-bold leading-snug text-brand-900 group-data-[view=compact]/vm:mt-1 group-data-[view=compact]/vm:text-[15px]">'
        . '<a href="' . e($url) . '" class="underline-offset-4 transition-colors hover:text-accent-700 hover:underline focus-visible:underline">' . $mark((string) $n['title']) . '</a></' . $h . '>';
    $excerpt = ne_excerpt($n['description'] ?? '', 200);
    // detailed view: a longer, unclamped summary replaces the 2-line excerpt
    $long = ne_excerpt($n['description'] ?? '', 520);
    $hasLong = $long !== '' && $long !== $excerpt;
    if ($excerpt !== '') {
        $html .= '<p class="vm-text mt-1.5 line-clamp-2 text-sm leading-relaxed text-slate-600' . ($hasLong ? ' group-data-[view=detailed]/vm:hidden' : '') . '">' . $mark($excerpt) . '</p>';
    }
    if ($hasLong) {
        $html .= '<p class="vm-extra mt-1.5 text-sm leading-relaxed text-slate-600">' . $mark($long) . '</p>';
    }
    $html .= '<ul class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[13px] text-slate-500 group-data-[view=compact]/vm:mt-1.5">';
    $html .= '<li class="inline-flex items-center gap-1.5 group-data-[view=compact]/vm:hidden">' . icon('calendar', 'h-3.5 w-3.5') . 'Published ' . e(date('d M Y', $pubTs)) . '</li>';
    if ($expiry) {
        $html .= '<li class="inline-flex items-center gap-1.5">' . icon($archived ? 'history' : 'calendar-clock', 'h-3.5 w-3.5') . ($archived ? 'Expired ' : 'Valid till ') . e(date('d M Y', $expiry)) . '</li>';
    }
    $html .= '<li class="inline-flex items-center gap-1.5">' . icon('users', 'h-3.5 w-3.5') . e(ne_audience_label($n)) . '</li>';
    if ($att) {
        $html .= '<li><a href="' . e($att['url']) . '" download="' . e($att['name']) . '" class="relative z-[1] inline-flex items-center gap-1.5 font-semibold text-accent-700 hover:text-brand-900">' . icon('paperclip', 'h-3.5 w-3.5')
            . e($att['short']) . ($att['size'] ? ' · ' . e($att['size']) : '') . '<span class="sr-only"> — download the attachment of ' . e((string) $n['title']) . '</span></a></li>';
    }
    $html .= '</ul>';
    if ($att) {
        $html .= '<div class="vm-extra mt-4">' . ne_attachment_card($att, 'Attachment: ' . $n['title'], true) . '</div>';
    }
    $html .= '<a href="' . e($url) . '" class="link-arrow mt-3 text-sm group-data-[view=compact]/vm:hidden">Read notice' . icon('arrow-right', 'h-4 w-4') . '<span class="sr-only">: ' . e((string) $n['title']) . '</span></a>';
    $html .= '</div></div></div></article>';
    return $html;
}

/** Compact "latest updates" list item (sidebar widget, related notices). */
function ne_notice_line(array $n): string
{
    $cat = ne_notice_category((string) $n['category']);
    $pub = ne_notice_date($n);
    return '<li class="group relative flex gap-3 py-3.5">'
        . '<span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl tint-' . e($cat['tint']) . '">' . icon($cat['icon'], 'h-4 w-4') . '</span>'
        . '<span class="min-w-0"><a href="' . e(ne_notice_url($n)) . '" class="stretched-link line-clamp-2 text-sm font-semibold leading-snug text-brand-900 group-hover:text-accent-700">' . e((string) $n['title']) . '</a>'
        . '<span class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-slate-500"><time datetime="' . e(date('Y-m-d', strtotime($pub) ?: time())) . '">' . e(time_ago($pub)) . '</time>'
        . '<span aria-hidden="true">·</span>' . e($cat['label'])
        . (ne_is_new($pub) && empty($n['is_archived']) ? '<span class="rounded-full bg-accent-600 px-1.5 text-[10px] font-bold uppercase leading-4 text-white">New</span>' : '') . '</span></span></li>';
}

/* =====================================================================================================================
 * Events — data
 * ===================================================================================================================*/

/** Event categories (communication module values): label + icon. */
function ne_event_category_meta(): array
{
    return [
        'academic' => ['Academic', 'graduation-cap'], 'cultural' => ['Cultural', 'party-popper'], 'sports' => ['Sports', 'trophy'],
        'seminar' => ['Seminar', 'presentation'], 'workshop' => ['Workshop', 'wrench'], 'webinar' => ['Webinar', 'video'],
        'conference' => ['Conference', 'users'], 'placement' => ['Placement', 'briefcase-business'], 'admission' => ['Admission', 'school'],
        'fdp' => ['Faculty Development', 'notebook-pen'], 'alumni' => ['Alumni', 'users-round'], 'other' => ['Community', 'heart'],
    ];
}

function ne_event_category(string $key): array
{
    $m = ne_event_category_meta()[$key] ?? [label_from_key($key ?: 'other'), 'calendar-days'];
    return ['key' => $key, 'label' => $m[0], 'icon' => $m[1]];
}

function ne_event_url(array $e, string $hash = ''): string
{
    return base_url('events/' . rawurlencode((string) $e['slug'])) . $hash;
}

/** SELECT list for events incl. the live registration count. */
function ne_event_columns(): string
{
    return "e.id, e.title, e.slug, e.category, e.excerpt, e.description, e.venue, e.start_datetime, e.end_datetime, e.image, e.organizer,
            e.contact_email, e.registration_link, e.registration_enabled, e.registration_deadline, e.max_participants, e.is_featured,
            e.status, e.created_at, e.updated_at,
            (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status <> 'cancelled') AS registered";
}

/**
 * WHERE for public events. $tab: 'upcoming' (not yet ended, incl. cancelled so visitors learn about it) | 'past'
 * (ended or completed) | null (any public event — detail pages).
 */
function ne_event_where(?string $tab, string $now): array
{
    $sql = "e.show_on_website = 1 AND e.status IN ('published', 'completed', 'cancelled')";
    $args = [];
    if ($tab === 'upcoming') {
        $sql .= " AND e.status IN ('published', 'cancelled') AND COALESCE(e.end_datetime, e.start_datetime) >= ?";
        $args[] = $now;
    } elseif ($tab === 'past') {
        $sql .= " AND (e.status = 'completed' OR (e.status = 'published' AND COALESCE(e.end_datetime, e.start_datetime) < ?))";
        $args[] = $now;
    }
    return [$sql, $args];
}

/** Upcoming public events (sidebar widgets, related events). */
function ne_upcoming_events(int $limit = 3, array $exclude = [], ?string $category = null): array
{
    [$where, $args] = ne_event_where('upcoming', ne_now());
    $where .= " AND e.status = 'published'";
    if ($exclude) {
        $where .= ' AND e.id NOT IN (' . implode(',', array_fill(0, count($exclude), '?')) . ')';
        array_push($args, ...array_map('intval', $exclude));
    }
    if ($category !== null) {
        $where .= ' AND e.category = ?';
        $args[] = $category;
    }
    $limit = max(1, min(24, $limit));
    return ne_try(fn () => db_all('SELECT ' . ne_event_columns() . " FROM events e WHERE $where ORDER BY e.start_datetime, e.id LIMIT $limit", $args), []);
}

/**
 * Phase + registration state of an event.
 * phase: upcoming | ongoing | past | cancelled.  reg: open | full | closed | external | none | ended | cancelled.
 * Respects registration_enabled, registration_deadline, max_participants (live count) — like the registration API.
 */
function ne_event_state(array $e, ?int $now = null): array
{
    $now ??= time();
    $start = strtotime((string) $e['start_datetime']) ?: $now;
    $end = !empty($e['end_datetime']) ? (strtotime((string) $e['end_datetime']) ?: $start) : $start;
    $end = max($end, $start);
    $status = (string) ($e['status'] ?? 'published');
    $phase = $status === 'cancelled' ? 'cancelled' : (($status === 'completed' || $end < $now) ? 'past' : ($start <= $now ? 'ongoing' : 'upcoming'));
    $cap = (int) ($e['max_participants'] ?? 0) ?: null;
    $taken = (int) ($e['registered'] ?? 0);
    $left = $cap ? max(0, $cap - $taken) : null;
    $deadline = !empty($e['registration_deadline']) ? (strtotime((string) $e['registration_deadline']) ?: null) : null;
    $external = trim((string) ($e['registration_link'] ?? ''));
    $external = preg_match('#^https?://#i', $external) ? $external : '';
    if ($phase === 'cancelled') {
        $reg = 'cancelled';
    } elseif ($phase === 'past') {
        $reg = 'ended';
    } elseif (!empty($e['registration_enabled'])) {
        $reg = $deadline && $deadline < $now ? 'closed' : ($cap && $left <= 0 ? 'full' : 'open');
    } elseif ($external !== '') {
        $reg = $deadline && $deadline < $now ? 'closed' : 'external';
    } else {
        $reg = 'none';
    }
    $few = $reg === 'open' && $cap && $left <= max(5, (int) ceil($cap * 0.1));
    $badges = [
        'cancelled' => ['Cancelled', 'badge-red', 'ban'],
        'ended' => ['Completed', 'badge-slate', 'circle-check'],
        'open' => $few ? ['Only ' . $left . ' seat' . ($left === 1 ? '' : 's') . ' left', 'badge-amber', 'ticket'] : ['Registration open', 'badge-green', 'ticket'],
        'full' => ['Fully booked', 'badge-red', 'users'],
        'closed' => ['Registration closed', 'badge-slate', 'lock'],
        'external' => ['Registration open', 'badge-blue', 'external-link'],
        'none' => null,
    ];
    return ['phase' => $phase, 'reg' => $reg, 'start' => $start, 'end' => $end, 'cap' => $cap, 'taken' => $taken, 'left' => $left,
        'deadline' => $deadline, 'external' => $external, 'few' => $few, 'badge' => $badges[$reg] ?? null];
}

/**
 * Human date/time strings for an event.
 * date: "Fri, 16 Oct 2026" | "24–25 Oct 2026" | "30 Oct – 2 Nov 2026"; time: "10:00 AM – 3:00 PM" (+ " · 2 days").
 */
function ne_event_when(array $e): array
{
    $s = strtotime((string) $e['start_datetime']) ?: time();
    $en = !empty($e['end_datetime']) ? (strtotime((string) $e['end_datetime']) ?: $s) : null;
    $sameDay = !$en || date('Y-m-d', $s) === date('Y-m-d', $en);
    if ($sameDay) {
        $date = date('D, d M Y', $s);
    } elseif (date('Y-m', $s) === date('Y-m', $en)) {
        $date = date('j', $s) . '–' . date('j M Y', $en);
    } elseif (date('Y', $s) === date('Y', $en)) {
        $date = date('j M', $s) . ' – ' . date('j M Y', $en);
    } else {
        $date = date('j M Y', $s) . ' – ' . date('j M Y', $en);
    }
    $days = $sameDay ? 1 : (int) round((strtotime(date('Y-m-d', $en)) - strtotime(date('Y-m-d', $s))) / 86400) + 1;
    $time = date('g:i A', $s) . ($en && $en > $s ? ' – ' . date('g:i A', $en) : '') . ($days > 1 ? ' · ' . $days . ' days' : '');
    return ['date' => $date, 'time' => $time, 'days' => $days, 'day' => date('d', $s), 'day_end' => $sameDay ? null : date('d', $en),
        'month' => date('M', $s), 'year' => date('Y', $s), 'iso' => date('Y-m-d', $s)];
}

/** Online (no physical venue) heuristic. */
function ne_event_online(?string $venue): bool
{
    return (bool) preg_match('/\b(online|zoom|youtube|webinar|google meet|ms teams|microsoft teams|virtual|live stream)\b/i', (string) $venue);
}

/**
 * Map link for a venue: on-campus rooms → directions to the campus (settings latitude/longitude or address); venues
 * naming another place (contains the city or another locality after a comma) → a Google Maps search for that venue.
 * null for online events. Returns ['url', 'label'].
 */
function ne_event_map(?string $venue): ?array
{
    $venue = trim((string) $venue);
    if (ne_event_online($venue)) {
        return null;
    }
    $city = trim((string) setting('city', 'Greater Noida'));
    $offCampus = $venue !== '' && (($city !== '' && stripos($venue, $city) !== false) || preg_match('/\b(expo|mart|hotel|stadium|centre for|center for|bhawan|bhavan|delhi|noida|gurugram|gurgaon|ghaziabad)\b/i', $venue));
    if ($offCampus) {
        return ['url' => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($venue), 'label' => 'View venue on Google Maps'];
    }
    $lat = trim((string) setting('latitude', ''));
    $lng = trim((string) setting('longitude', ''));
    $dest = $lat !== '' && $lng !== '' ? $lat . ',' . $lng : institute_name() . ', ' . (string) setting('address', $city);
    return ['url' => 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($dest), 'label' => 'Directions to the ' . setting('institute_short_name', 'GIMT') . ' campus'];
}

/**
 * Gallery linked to an event, with its items. There is no FK between galleries and events, so the link is the slug:
 * a published gallery whose slug equals the event slug (or starts with it, e.g. "{slug}-photos") AND whose event_date
 * (when set) falls within the event's dates (+30 days for late uploads) — so a same-named gallery of an earlier
 * edition (e.g. last year's sports meet) is never shown as this event's photos. Date-only matches are not used:
 * another activity on the same day would show the wrong photos.
 */
function ne_event_gallery(array $e): ?array
{
    $slug = (string) $e['slug'];
    $from = substr((string) $e['start_datetime'], 0, 10);
    $to = date('Y-m-d', strtotime(substr((string) ($e['end_datetime'] ?: $e['start_datetime']), 0, 10) . ' +30 days') ?: time());
    $g = ne_try(fn () => db_row("SELECT id, title, slug, description, cover_image FROM galleries
        WHERE status = 'published' AND (slug = ? OR slug LIKE ?) AND (event_date IS NULL OR event_date BETWEEN ? AND ?)
        ORDER BY (slug = ?) DESC, id LIMIT 1", [$slug, addcslashes($slug, '%_\\') . '-%', $from, $to, $slug]));
    if (!$g) {
        return null;
    }
    $rows = ne_try(fn () => db_all('SELECT gi.image, gi.caption, m.path, m.alt_text FROM gallery_items gi LEFT JOIN media m ON m.id = gi.media_id
        WHERE gi.gallery_id = ? AND (gi.image IS NOT NULL OR m.path IS NOT NULL) ORDER BY gi.sort_order, gi.id LIMIT 24', [(int) $g['id']]), []);
    $items = [];
    foreach ($rows as $r) {
        $path = (string) ($r['image'] ?: $r['path']);
        if ($path === '' || (!preg_match('#^https?://#', $path) && !is_file(APP_ROOT . '/' . ltrim($path, '/')))) {
            continue;
        }
        $src = site_image($path);
        $items[] = ['src' => $src, 'thumb' => $src, 'alt' => (string) ($r['alt_text'] ?: $r['caption'] ?: $g['title']), 'caption' => (string) ($r['caption'] ?? '')];
    }
    return $items ? $g + ['items' => $items] : null;
}

/* ---------------------------------------------------------------------------------------------------- calendar */

/** UTC iCalendar stamp. */
function ne_ics_time(int $ts): string
{
    return gmdate('Ymd\THis\Z', $ts);
}

function ne_ics_escape(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ["\\\\", '\;', '\,', '\n', '\n', ''], $s);
}

/** Fold an iCalendar content line at 75 octets (RFC 5545 §3.1), UTF-8 safe. */
function ne_ics_fold(string $line): string
{
    $out = '';
    $cur = '';
    foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        if (strlen($cur . $ch) > ($out === '' ? 75 : 74)) {
            $out .= ($out === '' ? '' : "\r\n ") . $cur;
            $cur = '';
        }
        $cur .= $ch;
    }
    return $out . ($out === '' ? '' : "\r\n ") . $cur;
}

/** Location text for calendars / JSON-LD. */
function ne_event_location_text(array $e): string
{
    $venue = trim((string) $e['venue']);
    if (ne_event_online($venue)) {
        return $venue;
    }
    $map = ne_event_map($venue);
    $campus = institute_name() . ', ' . (string) setting('address', setting('city', 'Greater Noida'));
    return $map && str_starts_with($map['label'], 'Directions') ? trim($venue . ($venue !== '' ? ', ' : '') . $campus) : $venue;
}

/** .ics (VCALENDAR with one VEVENT) for an event. */
function ne_event_ics(array $e): string
{
    $st = ne_event_state($e);
    $end = $st['end'] > $st['start'] ? $st['end'] : $st['start'] + 3600;
    $url = absolute_url('events/' . $e['slug']);
    $host = (string) (parse_url(absolute_url(''), PHP_URL_HOST) ?: 'gimt.ac.in');
    $desc = ne_excerpt(($e['excerpt'] ?? '') ?: ($e['description'] ?? ''), 600) . "\n\nDetails & registration: " . $url;
    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//' . ne_ics_escape((string) setting('institute_short_name', 'GIMT')) . '//Events//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:event-' . (int) $e['id'] . '@' . $host,
        'DTSTAMP:' . ne_ics_time(time()),
        'DTSTART:' . ne_ics_time($st['start']),
        'DTEND:' . ne_ics_time($end),
        'SUMMARY:' . ne_ics_escape((string) $e['title']),
        'DESCRIPTION:' . ne_ics_escape($desc),
        'LOCATION:' . ne_ics_escape(ne_event_location_text($e)),
        'URL:' . $url,
        'STATUS:' . ($st['phase'] === 'cancelled' ? 'CANCELLED' : 'CONFIRMED'),
        'CATEGORIES:' . ne_ics_escape(ne_event_category((string) $e['category'])['label']),
    ];
    if (filter_var((string) ($e['contact_email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        $lines[] = 'ORGANIZER;CN=' . ne_ics_escape(trim((string) $e['organizer']) ?: institute_name()) . ':mailto:' . $e['contact_email'];
    }
    if (!empty($e['updated_at'])) {
        $lines[] = 'LAST-MODIFIED:' . ne_ics_time(strtotime((string) $e['updated_at']) ?: time());
    }
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", array_map('ne_ics_fold', $lines)) . "\r\n";
}

/** Google Calendar "add event" link. */
function ne_gcal_url(array $e): string
{
    $st = ne_event_state($e);
    $end = $st['end'] > $st['start'] ? $st['end'] : $st['start'] + 3600;
    return 'https://calendar.google.com/calendar/render?' . http_build_query([
        'action' => 'TEMPLATE', 'text' => (string) $e['title'], 'dates' => ne_ics_time($st['start']) . '/' . ne_ics_time($end),
        'details' => ne_excerpt(($e['excerpt'] ?? '') ?: ($e['description'] ?? ''), 400) . "\n\n" . absolute_url('events/' . $e['slug']),
        'location' => ne_event_location_text($e), 'ctz' => date_default_timezone_get(),
    ]);
}

/* =====================================================================================================================
 * Events — render
 * ===================================================================================================================*/

function ne_status_badge(?array $badge, string $extra = ''): string
{
    if (!$badge) {
        return '';
    }
    return '<span class="badge ' . e($badge[1]) . ($extra !== '' ? ' ' . $extra : '') . '">' . icon($badge[2], 'h-3.5 w-3.5') . e($badge[0]) . '</span>';
}

/** Navy date tile (day / month), ranges as "24–25". */
function ne_date_tile(array $when, string $class = ''): string
{
    $day = $when['day'] . ($when['day_end'] && $when['days'] <= 31 ? '<span class="text-sm font-bold">–' . e($when['day_end']) . '</span>' : '');
    return '<span class="date-tile' . ($class !== '' ? ' ' . $class : '') . '" aria-hidden="true"><span class="date-tile-day">' . $day . '</span><span class="date-tile-mon">' . e($when['month']) . '</span></span>';
}

/**
 * Event card for view_mode_open('events', ['class' => 'group/vm']): photo with date tile + category chip, status
 * badges, title, date · time, venue, excerpt (hidden in compact), detailed: organiser, deadline, seats bar; actions.
 * @param array $o heading (h2|h3)
 */
function ne_event_card(array $e, array $o = []): string
{
    $st = ne_event_state($e);
    $when = ne_event_when($e);
    $cat = ne_event_category((string) $e['category']);
    $url = ne_event_url($e);
    $h = in_array($o['heading'] ?? 'h3', ['h2', 'h3'], true) ? ($o['heading'] ?? 'h3') : 'h3';
    $img = site_image($e['image'] ?? null, 'assets/images/site/news-campus.jpg');
    $html = '<article class="view-item group flex flex-col' . ($st['phase'] === 'cancelled' ? ' opacity-90' : '') . '">';
    $html .= '<a href="' . e($url) . '" class="vm-media img-zoom" tabindex="-1" aria-hidden="true">'
        . '<img src="' . e($img) . '" alt="" loading="lazy" decoding="async" width="640" height="400"' . ($st['phase'] === 'cancelled' ? ' class="grayscale"' : '') . '>'
        . '<span class="absolute left-3 top-3">' . ne_date_tile($when) . '</span>'
        . '<span class="absolute right-3 top-3 inline-flex items-center gap-1 rounded-full bg-white/95 px-2.5 py-1 text-xs font-semibold text-brand-900 shadow-sm">' . icon($cat['icon'], 'h-3.5 w-3.5 text-accent-700') . e($cat['label']) . '</span>'
        . '</a>';
    $html .= '<div class="vm-body flex-1">';
    $badges = ($st['phase'] === 'ongoing' ? '<span class="badge badge-orange"><span class="h-1.5 w-1.5 rounded-full bg-accent-600" aria-hidden="true"></span>Happening now</span>' : '')
        . ne_status_badge($st['badge']);
    $html .= '<p class="flex flex-wrap items-center gap-1.5">' . '<span class="hidden items-center gap-1 text-xs font-semibold text-brand-900 group-data-[view=compact]/vm:inline-flex">' . icon($cat['icon'], 'h-3.5 w-3.5 text-accent-700') . e($cat['label']) . '</span>' . $badges . '</p>';
    $html .= '<' . $h . ' class="font-display text-[17px] font-bold leading-snug text-brand-900"><a href="' . e($url) . '" class="transition-colors hover:text-accent-700">' . e((string) $e['title']) . '</a></' . $h . '>';
    $html .= '<ul class="flex flex-wrap gap-x-4 gap-y-1 text-[13px] text-slate-600">'
        . '<li class="inline-flex items-center gap-1.5">' . icon('calendar-days', 'h-3.5 w-3.5 text-accent-700') . '<time datetime="' . e(ne_iso($e['start_datetime']) ?? '') . '">' . e($when['date']) . '</time></li>'
        . '<li class="inline-flex items-center gap-1.5">' . icon('clock', 'h-3.5 w-3.5 text-accent-700') . e($when['time']) . '</li>'
        . (trim((string) $e['venue']) !== '' ? '<li class="inline-flex min-w-0 items-center gap-1.5">' . icon(ne_event_online($e['venue']) ? 'video' : 'map-pin', 'h-3.5 w-3.5 shrink-0 text-accent-700') . '<span class="truncate">' . e((string) $e['venue']) . '</span></li>' : '')
        . '</ul>';
    $excerpt = ne_excerpt(($e['excerpt'] ?? '') ?: ($e['description'] ?? ''), 150);
    if ($excerpt !== '') {
        $html .= '<p class="vm-text mt-1 line-clamp-2 text-sm leading-relaxed text-slate-600">' . e($excerpt) . '</p>';
    }
    // detailed view: organiser, deadline, seats
    $extra = '';
    if (trim((string) $e['organizer']) !== '') {
        $extra .= '<p class="flex items-center gap-1.5 text-[13px] text-slate-600">' . icon('building-2', 'h-3.5 w-3.5 text-slate-400') . 'Organised by ' . e((string) $e['organizer']) . '</p>';
    }
    if ($st['deadline'] && in_array($st['reg'], ['open', 'external'], true)) {
        $extra .= '<p class="flex items-center gap-1.5 text-[13px] text-slate-600">' . icon('alarm-clock', 'h-3.5 w-3.5 text-slate-400') . 'Register by ' . e(date('d M Y, g:i A', $st['deadline'])) . '</p>';
    }
    if ($st['cap'] && in_array($st['phase'], ['upcoming', 'ongoing'], true) && !empty($e['registration_enabled'])) {
        $pct = min(1, $st['taken'] / max(1, $st['cap']));
        $extra .= '<div><p class="flex justify-between text-xs font-medium text-slate-500"><span>' . number_format($st['taken']) . ' registered</span><span>' . number_format($st['cap']) . ' seats</span></p>'
            . '<span class="progress-track mt-1.5" role="img" aria-label="' . e(number_format($st['taken']) . ' of ' . number_format($st['cap']) . ' seats taken') . '"><span class="progress-fill" style="--progress:' . round($pct, 3) . '"></span></span></div>';
    }
    if ($extra !== '') {
        $html .= '<div class="vm-extra mt-2 space-y-2 border-t border-warm-2 pt-3">' . $extra . '</div>';
    }
    // compact rows: the title is the link (a wrapped "Event details" line made the rows ragged)
    $html .= '<div class="mt-auto flex flex-wrap items-center gap-x-4 gap-y-2 pt-3 group-data-[view=compact]/vm:hidden">';
    if ($st['reg'] === 'open') {
        $html .= '<a href="' . e(ne_event_url($e, '#register')) . '" class="btn btn-accent btn-sm group-data-[view=compact]/vm:hidden">Register<span class="sr-only"> for ' . e((string) $e['title']) . '</span>' . icon('arrow-right', 'h-4 w-4') . '</a>';
    }
    $html .= '<a href="' . e($url) . '" class="link-arrow text-sm">' . ($st['phase'] === 'past' ? 'Event recap' : 'Event details') . icon('arrow-right', 'h-4 w-4') . '<span class="sr-only">: ' . e((string) $e['title']) . '</span></a></div>';
    $html .= '</div></article>';
    return $html;
}

/** Small event row (sidebar / widgets): date tile + title + time · venue. */
function ne_event_line(array $e): string
{
    $when = ne_event_when($e);
    return '<li class="group relative flex gap-3 py-3">' . ne_date_tile($when, '!h-12 !w-12 !rounded-xl [&_.date-tile-day]:!text-base')
        . '<span class="min-w-0"><a href="' . e(ne_event_url($e)) . '" class="stretched-link line-clamp-2 text-sm font-semibold leading-snug text-brand-900 group-hover:text-accent-700">' . e((string) $e['title']) . '</a>'
        . '<span class="mt-0.5 block truncate text-xs text-slate-500"><time datetime="' . e(ne_iso($e['start_datetime']) ?? '') . '">' . e($when['date']) . '</time> · ' . e(date('g:i A', strtotime((string) $e['start_datetime']))) . '</span></span></li>';
}

/* =====================================================================================================================
 * Shared UI
 * ===================================================================================================================*/

/**
 * Server-side pagination (links keep every filter; rel prev/next; aria-current; ellipses).
 * @param array    $pg  paginate() result
 * @param callable $url fn(int $page): string
 */
function ne_pagination(array $pg, callable $url, string $label = 'Pagination'): string
{
    if (($pg['pages'] ?? 1) <= 1) {
        return '';
    }
    $page = (int) $pg['page'];
    $pages = (int) $pg['pages'];
    $btn = 'inline-flex h-10 min-w-[2.5rem] items-center justify-center gap-1.5 rounded-full border px-3 text-sm font-semibold transition';
    $idle = $btn . ' border-slate-200 bg-white text-brand-900 hover:border-brand-900';
    $off = $btn . ' cursor-not-allowed border-slate-100 bg-white text-slate-400';
    $html = '<nav class="mt-10 flex flex-col items-center gap-3" aria-label="' . e($label) . '"><ul class="flex flex-wrap items-center justify-center gap-1.5">';
    $html .= '<li>' . ($page > 1 ? '<a href="' . e($url($page - 1)) . '" rel="prev" class="' . $idle . '">' . icon('chevron-left', 'h-4 w-4') . '<span class="hidden sm:inline">Previous</span><span class="sr-only sm:hidden">Previous page</span></a>'
        : '<span class="' . $off . '" aria-disabled="true">' . icon('chevron-left', 'h-4 w-4') . '<span class="hidden sm:inline">Previous</span></span>') . '</li>';
    $set = array_unique(array_filter([1, 2, $page - 1, $page, $page + 1, $pages - 1, $pages], fn ($p) => $p >= 1 && $p <= $pages));
    sort($set);
    $prev = 0;
    foreach ($set as $p) {
        if ($p - $prev > 1) {
            $html .= '<li class="px-1 text-slate-400" aria-hidden="true">…</li>';
        }
        $html .= '<li>' . ($p === $page
            ? '<span class="' . $btn . ' border-brand-900 bg-brand-900 text-white" aria-current="page"><span class="sr-only">Page </span>' . $p . '</span>'
            : '<a href="' . e($url($p)) . '" class="' . $idle . '"' . ($p === $page - 1 ? ' rel="prev"' : ($p === $page + 1 ? ' rel="next"' : '')) . '><span class="sr-only">Page </span>' . $p . '</a>') . '</li>';
        $prev = $p;
    }
    $html .= '<li>' . ($page < $pages ? '<a href="' . e($url($page + 1)) . '" rel="next" class="' . $idle . '"><span class="hidden sm:inline">Next</span><span class="sr-only sm:hidden">Next page</span>' . icon('chevron-right', 'h-4 w-4') . '</a>'
        : '<span class="' . $off . '" aria-disabled="true"><span class="hidden sm:inline">Next</span>' . icon('chevron-right', 'h-4 w-4') . '</span>') . '</li>';
    return $html . '</ul><p class="text-xs text-slate-500">Showing ' . number_format((int) $pg['from']) . '–' . number_format((int) $pg['to']) . ' of ' . number_format((int) $pg['total']) . '</p></nav>';
}

/**
 * Share row: copy link + native share (shown only with JS), WhatsApp, LinkedIn, X, Facebook, email.
 */
function ne_share(string $url, string $title, string $label = 'Share'): string
{
    $wa = 'https://wa.me/?text=' . rawurlencode($title . ' — ' . $url);
    $mail = 'mailto:?subject=' . rawurlencode($title) . '&body=' . rawurlencode($title . "\n\n" . $url);
    $links = [
        ['WhatsApp', $wa, 'whatsapp-brand', 'hover:bg-[#15800E] hover:text-white hover:border-[#15800E]'],
        ['LinkedIn', 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode($url), 'linkedin', 'hover:bg-[#0A66C2] hover:text-white hover:border-[#0A66C2]'],
        ['X', 'https://twitter.com/intent/tweet?text=' . rawurlencode($title) . '&url=' . rawurlencode($url), 'x-twitter', 'hover:bg-slate-900 hover:text-white hover:border-slate-900'],
        ['Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($url), 'facebook', 'hover:bg-[#1877F2] hover:text-white hover:border-[#1877F2]'],
    ];
    $round = 'inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white text-brand-900 transition duration-200';
    $html = '<div class="flex flex-wrap items-center gap-2" role="group" aria-label="' . e($label) . '">';
    $html .= '<span class="mr-1 text-sm font-semibold text-brand-900">' . e($label) . '</span>';
    $html .= '<button type="button" class="btn btn-secondary btn-sm hidden" data-ne-copy="' . e($url) . '">' . icon('link', 'h-4 w-4') . '<span data-ne-label>Copy link</span></button>';
    $html .= '<button type="button" class="btn btn-secondary btn-sm hidden" data-ne-share="' . e($url) . '" data-title="' . e($title) . '">' . icon('share-2', 'h-4 w-4') . 'Share…</button>';
    foreach ($links as [$name, $href, $ic, $hover]) {
        $html .= '<a href="' . e($href) . '" target="_blank" rel="noopener" class="' . $round . ' ' . $hover . '" title="Share on ' . e($name) . '">' . icon($ic, 'h-4 w-4') . '<span class="sr-only">Share on ' . e($name) . ' (opens in a new tab)</span></a>';
    }
    $html .= '<a href="' . e($mail) . '" class="' . $round . ' hover:border-brand-900 hover:bg-brand-900 hover:text-white" title="Share by email">' . icon('mail', 'h-4 w-4') . '<span class="sr-only">Share by email</span></a>';
    return $html . '<span class="sr-only" aria-live="polite" data-ne-live></span></div>';
}

/** Tiny progressive-enhancement script for copy-link / native share / print buttons (printed once). */
function ne_script(): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    return <<<'HTML'
<script>(function(d,w){function show(b){b.classList.remove('hidden')}
function say(t){d.querySelectorAll('[data-ne-live]').forEach(function(l){l.textContent=t})}
d.querySelectorAll('[data-ne-copy]').forEach(function(b){show(b);b.addEventListener('click',function(){var u=b.getAttribute('data-ne-copy'),l=b.querySelector('[data-ne-label]'),o=l?l.textContent:'';
function ok(){if(l){l.textContent='Link copied';w.setTimeout(function(){l.textContent=o},2200)}say('Link copied to clipboard')}
function fb(){var t=d.createElement('textarea');t.value=u;t.setAttribute('readonly','');t.style.position='fixed';t.style.opacity='0';d.body.appendChild(t);t.select();var s=false;try{s=d.execCommand('copy')}catch(e){}d.body.removeChild(t);if(s){ok()}else{w.prompt('Copy this link:',u)}}
if(navigator.clipboard&&w.isSecureContext){navigator.clipboard.writeText(u).then(ok,fb)}else{fb()}})});
if(navigator.share){d.querySelectorAll('[data-ne-share]').forEach(function(b){show(b);b.addEventListener('click',function(){navigator.share({title:b.getAttribute('data-title')||d.title,url:b.getAttribute('data-ne-share')}).catch(function(){})})})}
d.querySelectorAll('[data-ne-print]').forEach(function(b){show(b);b.addEventListener('click',function(){w.print()})})})(document,window);</script>
HTML;
}

/** Empty state card. $actions = HTML buttons. */
function ne_empty(string $icon, string $title, string $text = '', string $actions = ''): string
{
    return '<div class="empty-state rounded-[22px] border border-dashed border-warm-line bg-white" role="status">'
        . '<span class="empty-state-icon">' . icon($icon, 'h-6 w-6') . '</span>'
        . '<p class="mt-4 font-display text-lg font-bold text-brand-900">' . e($title) . '</p>'
        . ($text !== '' ? '<p class="mt-1.5 max-w-md text-sm leading-relaxed text-slate-600">' . e($text) . '</p>' : '')
        . ($actions !== '' ? '<div class="mt-5 flex flex-wrap justify-center gap-2.5">' . $actions . '</div>' : '') . '</div>';
}

/** Sidebar card shell. */
function ne_side_card(string $title, string $icon, string $body, string $footer = ''): string
{
    return '<section class="rounded-[22px] border border-warm-2 bg-white p-5 sm:p-6" aria-label="' . e($title) . '">'
        . '<h2 class="flex items-center gap-2.5 font-display text-base font-bold text-brand-900"><span class="inline-flex h-8 w-8 items-center justify-center rounded-lg tint-orange">' . icon($icon, 'h-4 w-4') . '</span>' . e($title) . '</h2>'
        . $body . ($footer !== '' ? '<div class="mt-2 border-t border-warm-2 pt-4">' . $footer . '</div>' : '') . '</section>';
}

/** Help / contact sidebar card with the CTA system (Request a Callback = soft, Apply = primary). */
function ne_help_card(string $title = 'Have a question?', string $text = 'Our team will help you with admissions, examinations and campus updates.'): string
{
    $phone = (string) setting('phone', '+91 9955446477');
    $email = (string) setting('email', 'info@gimt.ac.in');
    $modal = setting('site_enquiry_tab', '1') === '1' ? ' data-modal-open="#enquiry-modal" aria-haspopup="dialog"' : '';
    return '<section class="relative overflow-hidden rounded-[22px] bg-brand-900 p-6 text-white/80" aria-label="' . e($title) . '">'
        . '<span class="pointer-events-none absolute -right-10 -top-10 h-32 w-32 rounded-full border-[14px] border-white/5" aria-hidden="true"></span>'
        . '<h2 class="font-display text-lg font-bold text-white">' . e($title) . '</h2><p class="mt-1.5 text-sm leading-relaxed">' . e($text) . '</p>'
        . '<ul class="mt-4 space-y-2 text-sm">'
        . '<li><a href="' . e(site_tel($phone)) . '" class="inline-flex items-center gap-2 font-semibold text-white hover:text-accent-300">' . icon('phone', 'h-4 w-4 text-accent-300') . e($phone) . '</a></li>'
        . '<li><a href="mailto:' . e($email) . '" class="inline-flex items-center gap-2 font-semibold text-white [overflow-wrap:anywhere] hover:text-accent-300">' . icon('mail', 'h-4 w-4 text-accent-300') . e($email) . '</a></li></ul>'
        . '<div class="mt-5 flex flex-wrap gap-2.5"><a href="' . e(site_url('contact?type=admission#message')) . '" class="btn btn-callback btn-sm"' . $modal . '>' . icon('phone', 'h-4 w-4') . 'Request a Callback</a>'
        . '<a href="' . e(cms_link((string) setting('header_cta_url', '/apply'))) . '" class="btn btn-apply btn-sm">Apply for Admission' . icon('arrow-right', 'h-4 w-4') . '</a></div></section>';
}

/**
 * Not-found response for /notices/{slug} and /events/{slug}: hands over to the site-wide 404.php when it exists
 * (call BEFORE includes/config.php is loaded — 404.php bootstraps the site itself), otherwise renders a 404 in the
 * site look with the latest items. Always exits.
 */
function ne_not_found(string $kind): void
{
    http_response_code(404);
    if (is_file(APP_ROOT . '/404.php') && !function_exists('site_header')) {
        require APP_ROOT . '/404.php';
        exit;
    }
    if (!function_exists('site_header')) {
        require APP_ROOT . '/includes/config.php';
    }
    $isEvent = $kind === 'event';
    site_header(['title' => ($isEvent ? 'Event' : 'Notice') . ' Not Found', 'robots' => 'noindex,follow', 'active' => $isEvent ? 'events' : 'notices',
        'description' => 'The ' . ($isEvent ? 'event' : 'notice') . ' you are looking for could not be found.', 'canonical' => absolute_url($isEvent ? 'events' : 'notices')]);
    $crumbs = $isEvent ? ['Events' => '/events', 'Not Found'] : ['Notice Board' => '/notices', 'Not Found'];
    page_hero(($isEvent ? 'Event' : 'Notice') . ' not found', 'This link may be outdated', $crumbs, '',
        'The ' . ($isEvent ? 'event' : 'notice') . ' may have been removed, renamed or is no longer published. Browse the latest ' . ($isEvent ? 'events' : 'notices') . ' below.', [],
        ['highlight' => false, 'actions' => button_link($isEvent ? 'All Events' : 'Notice Board', $isEvent ? '/events' : '/notices', 'explore') . button_link('Go to Home', '/', 'brochure', ['icon' => ''])]);
    echo section_open(['bg' => 'light']);
    if ($isEvent) {
        $rows = ne_upcoming_events(6);
        echo section_heading('Upcoming Events', '', 'What’s next');
        echo $rows ? view_mode_open('events-404', ['class' => 'group/vm', 'default' => 'grid']) . implode('', array_map('ne_event_card', $rows)) . view_mode_close()
            : ne_empty('calendar-days', 'No upcoming events right now', 'New events are announced on this page regularly.', button_link('Past events', '/events?tab=past', 'brochure', ['icon' => '']));
    } else {
        $rows = ne_latest_notices(6);
        echo section_heading('Latest Notices', '', 'Notice Board');
        echo $rows ? view_mode_open('notices-404', ['class' => 'group/vm', 'default' => 'list']) . implode('', array_map('ne_notice_item', $rows)) . view_mode_close()
            : ne_empty('megaphone', 'No current notices', 'Please check back soon.', '');
    }
    echo section_close();
    site_footer(['cta' => false]);
    exit;
}
