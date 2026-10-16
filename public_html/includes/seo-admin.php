<?php
/**
 * SEO admin module (SEO package WP-4), used by admin/seo.php.
 *
 * - URL inventory of every public page and entity (pages, articles, stories, events, campaigns, schemes)
 * - page-level checks (lengths, duplicates, headings, image descriptions, focus keyword, thin content, links,
 *   indexing, structured data). Counts of real issues only: there is no "SEO score".
 * - per-entity SEO editor: entity columns where the entity has them, everything else in `seo_meta` (SEO core table)
 * - CSV export / import with preview (default: fill only empty fields), one audit row per changed field
 * - link scanner (stored content and menus, resolved against pages, entity slugs and redirects - no HTTP requests),
 *   orphan report, links through redirects, redirect chain / loop report, manual external link check in small batches
 * - awareness-day editions (which edition is canonical, manual override), sitemap and robots.txt previews,
 *   owner trust checklist (data presence only), integration settings, image descriptions
 * - field-level change log `seo_changes` (old -> new)
 *
 * Everything degrades gracefully: without the `seo_meta` table the fields stored there are read-only, without this
 * module's tables the audit falls back to the activity log. Functions of the other SEO packages (seo.php,
 * seo-sitemap.php, seo-images.php, seo-tags.php) are only called behind function_exists().
 * Keyword checks are advisory: nothing in this file rewrites titles, content or slugs automatically.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

const SEO_ADMIN_TITLE_MIN = 30;
const SEO_ADMIN_TITLE_MAX_DEFAULT = 60;
const SEO_ADMIN_DESC_MIN = 70;
const SEO_ADMIN_DESC_MAX = 160;
const SEO_ADMIN_THIN_WORDS = 300;
const SEO_ADMIN_EXT_BATCH = 20;      // external links checked per click
const SEO_ADMIN_EXT_TIMEOUT = 5;     // seconds per request
const SEO_ADMIN_EXT_BUDGET = 20;     // seconds per click (the rest waits for the next click)
const SEO_ADMIN_CSV_MAX_ROWS = 2000;
const SEO_ADMIN_CSV_MAX_BYTES = 2097152;
const SEO_ADMIN_TYPES = ['page' => 'Page', 'post' => 'Article', 'story' => 'Story', 'event' => 'Event', 'campaign' => 'Campaign', 'scheme' => 'Scheme'];
/** Page templates that are not public landing pages (private, token or container pages). */
const SEO_ADMIN_PRIVATE_TEMPLATES = ['donation_success', 'donation_failed', 'donation_receipt', 'not_found', 'member', 'logout',
    'reset_password', 'verify_email', 'campaign_detail', 'event_detail'];
/** Templates whose own sections carry the content (thin-content check). Lists and forms are judged by other checks. */
const SEO_ADMIN_CONTENT_TEMPLATES = ['home', 'default', 'policy', 'program', 'program_category', 'ngo_details', 'team'];
/** Allowed structured-data types per entity (SEO-DESIGN 4.5; typed fields only, never free JSON). */
const SEO_ADMIN_SCHEMA_TYPES = [
    'page' => ['WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'FAQPage'],
    'post' => ['BlogPosting', 'NewsArticle', 'Article'],
    'story' => ['Article', 'BlogPosting'],
    'event' => ['Event', 'EducationEvent', 'SocialEvent', 'MusicEvent'],
    'campaign' => ['WebPage'],
    'scheme' => ['WebPage'],
];
/** Fields that the CSV import/export handles (author, reviewer and sources are edited in the editor only). */
const SEO_ADMIN_CSV_FIELDS = ['meta_title', 'meta_description', 'focus_keyword', 'secondary_keywords', 'canonical_url', 'robots_index',
    'robots_follow', 'og_title', 'og_description', 'og_image', 'og_image_alt', 'schema_type', 'sitemap_include', 'sitemap_priority'];

// =====================================================================
// Infrastructure
// =====================================================================

/** Does a table exist (cached per request; false when the database cannot tell)? */
function seo_admin_table_ready(string $table, bool $refresh = false): bool
{
    static $cache = [];
    if ($refresh || !array_key_exists($table, $cache)) {
        try {
            $cache[$table] = table_exists($table);
        } catch (Throwable $e) {
            $cache[$table] = false;
        }
    }
    return $cache[$table];
}

/** Column names of a table (cached; [] when it is missing). */
function seo_admin_columns(string $table): array
{
    static $cache = [];
    if (!isset($cache[$table])) {
        try {
            $cache[$table] = array_column(db_rows('SELECT column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?', [$table]), 'c');
        } catch (Throwable $e) {
            $cache[$table] = [];
        }
    }
    return $cache[$table];
}

/** Comma-separated list of those $wanted columns that exist (column names are hard-coded by the callers). */
function seo_admin_select(string $table, array $wanted): string
{
    $have = seo_admin_columns($table);
    $cols = array_values(array_filter($wanted, function ($c) use ($have) {
        return in_array($c, $have, true);
    }));
    return $cols ? '`' . implode('`, `', $cols) . '`' : 'id';
}

/** Is the SEO core (WP-1) installed, so that seo_meta values are used on the public pages? */
function seo_admin_core_installed(): bool
{
    return function_exists('seo_finalize') && seo_admin_table_ready('seo_meta');
}

/**
 * Stop unless the signed-in staff member may use the SEO module: permission seo.manage, or pages.manage while the
 * seo.manage permission does not exist yet (before migration 832 / WP-1's 802 have run).
 */
function seo_admin_require_access(): void
{
    $defined = false;
    try {
        $defined = (int) db_value("SELECT COUNT(*) FROM permissions WHERE perm_key = 'seo.manage'") > 0;
    } catch (Throwable $e) {
        $defined = false;
    }
    require_permission($defined ? 'seo.manage' : 'pages.manage');
}

function seo_admin_user_id(): ?int
{
    if (function_exists('admin_id')) {
        return admin_id();
    }
    return function_exists('current_user_id') ? current_user_id() : null;
}

function seo_admin_is_administrator(): bool
{
    $id = seo_admin_user_id();
    return $id !== null && function_exists('is_administrator') && is_administrator($id);
}

/** Lower-case text with punctuation removed and single spaces (for keyword and duplicate comparisons). */
function seo_admin_norm(string $s): string
{
    $s = mb_strtolower(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $s = (string) preg_replace('/[^\p{L}\p{N}\p{M}]+/u', ' ', $s);
    return trim((string) preg_replace('/\s+/u', ' ', $s));
}

/** Does $hay contain the phrase $needle as whole words (case and punctuation insensitive)? */
function seo_admin_contains(string $hay, string $needle): bool
{
    $n = seo_admin_norm($needle);
    return $n !== '' && strpos(' ' . seo_admin_norm($hay) . ' ', ' ' . $n . ' ') !== false;
}

/** Plain text of HTML (entities decoded, whitespace collapsed). */
function seo_admin_plain(string $html): string
{
    $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
    $html = (string) preg_replace('#<(br|/p|/li|/h[1-6]|/div|/td|/tr)\b[^>]*>#i', ' ', $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

/** Count words (letters/numbers runs, any script). */
function seo_word_count(string $text): int
{
    return (int) preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\p{M}\'’\-]*/u', $text);
}

/** Absolute URL from a root-relative site URL (as returned by page_url(), event_url(), ...). */
function seo_admin_abs(string $rel): string
{
    if (preg_match('#^https?://#i', $rel)) {
        return $rel;
    }
    $base = base_path();
    if ($base !== '' && strpos($rel, $base) === 0) {
        $rel = substr($rel, strlen($base));
    }
    return rtrim(BASE_URL, '/') . '/' . ltrim($rel, '/');
}

/** Normalised absolute URL for comparisons (lower-case scheme and host, path with trailing slash, no query/fragment). */
function seo_admin_norm_url(string $url): string
{
    $p = parse_url(trim($url));
    if (!$p || empty($p['host'])) {
        return trim($url);
    }
    $path = (string) ($p['path'] ?? '/');
    if ($path === '' || (substr($path, -1) !== '/' && !preg_match('/\.[a-z0-9]{2,5}$/i', $path))) {
        $path .= '/';
    }
    return strtolower(($p['scheme'] ?? 'https') . '://' . $p['host']) . (isset($p['port']) ? ':' . $p['port'] : '') . $path;
}

/** Host and port of a URL ("example.org", "127.0.0.1:8088"); default ports are left out. */
function seo_admin_host_key(string $url): string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $port = (int) parse_url($url, PHP_URL_PORT);
    return $host . ($port && !in_array($port, [80, 443], true) ? ':' . $port : '');
}

/** Is an absolute URL on this website (same host and port as BASE_URL; http and https both count)? */
function seo_admin_same_site(string $url): bool
{
    $key = seo_admin_host_key($url);
    return $key !== '' && $key === seo_admin_host_key(BASE_URL);
}

/** Path (no slashes at the ends) of a site URL, or null when it is on another host. */
function seo_admin_site_path(string $href): ?string
{
    $href = trim($href);
    if (preg_match('#^//#', $href)) {
        $href = 'https:' . $href;
    }
    if (preg_match('#^https?://#i', $href)) {
        if (!seo_admin_same_site($href)) {
            return null;
        }
    }
    $path = rawurldecode((string) parse_url($href, PHP_URL_PATH));
    $base = trim(base_path(), '/');
    $path = trim($path, '/');
    if ($base !== '' && ($path === $base || strpos($path, $base . '/') === 0)) {
        $path = trim(substr($path, strlen($base)), '/');
    }
    return $path;
}

/** Short text for tables (escaped later by the caller). */
function seo_admin_short(?string $text, int $len = 90): string
{
    $text = trim((string) $text);
    return mb_strlen($text) > $len ? mb_substr($text, 0, $len - 1) . '…' : $text;
}

/** Limit for titles before the site-name suffix (setting seo_title_max, default 60). */
function seo_admin_title_max(): int
{
    $v = (int) setting('seo_title_max', (string) SEO_ADMIN_TITLE_MAX_DEFAULT);
    return $v >= 30 && $v <= 120 ? $v : SEO_ADMIN_TITLE_MAX_DEFAULT;
}

// =====================================================================
// Field definitions, storage, validation, saving, audit
// =====================================================================

/** Editable SEO fields: key => [label, kind, max length]. */
function seo_admin_field_defs(): array
{
    static $defs = null;
    if ($defs !== null) {
        return $defs;
    }
    return $defs = [
        'meta_title' => ['Search title', 'text', 200],
        'meta_description' => ['Search description', 'textarea', 300],
        'focus_keyword' => ['Focus keyword', 'text', 120],
        'secondary_keywords' => ['Secondary keywords', 'keywords', 500],
        'canonical_url' => ['Canonical URL', 'canonical', 255],
        'robots_index' => ['Search engines', 'tri', 0],
        'robots_follow' => ['Links on this page', 'tri', 0],
        'og_title' => ['Social title', 'text', 200],
        'og_description' => ['Social description', 'textarea', 300],
        'og_image' => ['Social image', 'image', 255],
        'og_image_alt' => ['Social image description', 'text', 255],
        'schema_type' => ['Structured data type', 'schema', 40],
        'faq_markup' => ['FAQ structured data on this page', 'flag', 0],
        'about_name' => ['Main topic (about)', 'text', 120],
        'sitemap_include' => ['Sitemap', 'tri', 0],
        'sitemap_priority' => ['Sitemap priority', 'priority', 0],
        'author_member_id' => ['Author', 'member', 0],
        'reviewer_member_id' => ['Reviewed by', 'member', 0],
        'reviewed_at' => ['Reviewed on', 'date', 0],
        'sources' => ['Sources', 'sources', 0],
    ];
}

/** Entity table of a type. */
function seo_admin_entity_table(string $type): string
{
    $map = ['page' => 'pages', 'post' => 'blog_posts', 'story' => 'success_stories', 'event' => 'events', 'campaign' => 'campaigns', 'scheme' => 'schemes'];
    return $map[$type] ?? '';
}

/**
 * Where a field is stored for a type: ['entity', column] (the entity's own column - no duplicate source of truth),
 * ['meta', column] (seo_meta), ['schema', key] (seo_meta.schema_fields JSON) or null (not applicable).
 */
function seo_admin_storage(string $type, string $field): ?array
{
    static $cache = [];
    if (array_key_exists($type . '|' . $field, $cache)) {
        return $cache[$type . '|' . $field];
    }
    return $cache[$type . '|' . $field] = seo_admin_storage_lookup($type, $field);
}

function seo_admin_storage_lookup(string $type, string $field): ?array
{
    if (!isset(SEO_ADMIN_TYPES[$type]) || !isset(seo_admin_field_defs()[$field])) {
        return null;
    }
    $own = [
        'page' => ['meta_title' => 'meta_title', 'meta_description' => 'meta_description', 'canonical_url' => 'canonical_url',
            'robots_index' => 'noindex', 'og_image' => 'og_image', 'sitemap_include' => 'show_in_sitemap'],
        'post' => ['meta_title' => 'meta_title', 'meta_description' => 'meta_description'],
        'event' => ['meta_title' => 'meta_title', 'meta_description' => 'meta_description'],
        'campaign' => ['meta_title' => 'meta_title', 'meta_description' => 'meta_description'],
    ];
    if (isset($own[$type][$field]) && in_array($own[$type][$field], seo_admin_columns(seo_admin_entity_table($type)), true)) {
        return ['entity', $own[$type][$field]];
    }
    if (in_array($field, ['faq_markup', 'about_name'], true)) {
        return $type === 'page' ? ['schema', $field] : null;
    }
    return ['meta', $field];
}

/** Can this field be saved right now (its column / table exists)? */
function seo_admin_field_available(string $type, string $field): bool
{
    $st = seo_admin_storage($type, $field);
    if (!$st) {
        return false;
    }
    if ($st[0] === 'entity') {
        return true;
    }
    $cols = seo_admin_table_ready('seo_meta') ? seo_admin_columns('seo_meta') : [];
    return in_array($st[0] === 'schema' ? 'schema_fields' : $st[1], $cols, true);
}

/** The entity row (any status), or null. */
function seo_admin_entity(string $type, int $id): ?array
{
    $table = seo_admin_entity_table($type);
    if ($table === '' || $id <= 0) {
        return null;
    }
    try {
        return db_row('SELECT * FROM `' . $table . '` WHERE id = ?', [$id]);
    } catch (Throwable $e) {
        return null;
    }
}

/** The seo_meta row of an entity ([] when there is none or the table is missing). */
function seo_admin_meta_row(string $type, int $id): array
{
    if (!seo_admin_table_ready('seo_meta')) {
        return [];
    }
    try {
        return db_row('SELECT * FROM seo_meta WHERE entity_type = ? AND entity_id = ?', [$type, $id]) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** All seo_meta rows keyed "type:id" (cached per request). */
function seo_admin_all_meta(bool $refresh = false): array
{
    static $all = null;
    if ($all !== null && !$refresh) {
        return $all;
    }
    $all = [];
    if (seo_admin_table_ready('seo_meta')) {
        try {
            foreach (db_rows('SELECT * FROM seo_meta') as $m) {
                $all[$m['entity_type'] . ':' . (int) $m['entity_id']] = $m;
            }
        } catch (Throwable $e) {
            $all = [];
        }
    }
    return $all;
}

/**
 * Current stored values of every applicable field as strings ('' = not set). Tri-state fields: '' default,
 * '1' yes, '0' no. For pages, robots_index / sitemap_include mirror pages.noindex / pages.show_in_sitemap.
 */
function seo_admin_values(string $type, array $row, array $meta): array
{
    $vals = [];
    $schema = json_field($meta['schema_fields'] ?? null);
    foreach (seo_admin_field_defs() as $f => $def) {
        $st = seo_admin_storage($type, $f);
        if (!$st) {
            continue;
        }
        [$where, $col] = $st;
        if ($where === 'entity') {
            if ($f === 'robots_index' && $type === 'page') {
                $v = (int) ($row['noindex'] ?? 0) === 1 ? '0' : '1';
            } elseif ($f === 'sitemap_include' && $type === 'page') {
                $v = (int) ($row['show_in_sitemap'] ?? 1) === 1 ? '1' : '0';
            } else {
                $v = $row[$col] ?? null;
            }
        } elseif ($where === 'schema') {
            $v = $schema[$col] ?? null;
            if ($f === 'faq_markup') {
                $v = !empty($v) ? '1' : '';
            }
        } else {
            $v = $meta[$col] ?? null;
        }
        $v = $v === null ? '' : trim((string) $v);
        if ($f === 'sitemap_priority' && $v !== '') {
            $v = number_format((float) $v, 1, '.', '');
        }
        if (in_array($f, ['robots_index', 'robots_follow', 'sitemap_include'], true) && $v !== '') {
            $v = (int) $v ? '1' : '0';
        }
        if (in_array($f, ['author_member_id', 'reviewer_member_id'], true) && $v !== '') {
            $v = (string) (int) $v;
        }
        $vals[$f] = $v;
    }
    return $vals;
}

/** Sources JSON -> editor lines "Title | https://…". */
function seo_admin_sources_lines(string $json): string
{
    $lines = [];
    foreach (json_field($json) as $s) {
        if (is_array($s) && !empty($s['url'])) {
            $lines[] = trim((string) ($s['title'] ?? '')) . ' | ' . $s['url'];
        }
    }
    return implode("\n", $lines);
}

/** Published team members [id => "Name, Designation"] for the author / reviewer pickers. */
function seo_admin_team_options(): array
{
    static $opts = null;
    if ($opts === null) {
        $opts = [];
        try {
            foreach (db_rows("SELECT id, name, designation FROM team_members WHERE status = 'published' ORDER BY sort_order, name") as $m) {
                $opts[(int) $m['id']] = $m['name'] . ($m['designation'] ? ', ' . $m['designation'] : '');
            }
        } catch (Throwable $e) {
            $opts = [];
        }
    }
    return $opts;
}

/**
 * Validate a canonical URL: a site path (/about/) or an absolute http(s) URL. Returns [absolute URL|'' , error, warning].
 * Another website is allowed for administrators only, with a warning.
 */
function seo_admin_clean_canonical(string $v, bool $isAdmin): array
{
    $v = trim($v);
    if ($v === '') {
        return ['', null, null];
    }
    if (strpos($v, '#') !== false) {
        return [null, 'Canonical URL: leave out the #part.', null];
    }
    if ($v[0] === '/' && substr($v, 0, 2) !== '//') {
        $err = null;
        $l = site_clean_link($v, $err);
        if ($err || $l === '' || $l[0] !== '/') {
            return [null, 'Canonical URL: enter a page address such as /about/ or a full https:// address.', null];
        }
        $v = rtrim(BASE_URL, '/') . $l;
    }
    if (!preg_match('#^https?://[^\s<>"\'\\\\]+$#i', $v) || !filter_var($v, FILTER_VALIDATE_URL)) {
        return [null, 'Canonical URL: enter a page address such as /about/ or a full https:// address.', null];
    }
    if (mb_strlen($v) > 255) {
        return [null, 'Canonical URL may not be longer than 255 characters.', null];
    }
    $host = seo_admin_host_key($v);
    if (!seo_admin_same_site($v)) {
        if (!$isAdmin) {
            return [null, 'Canonical URL: only administrators can point the canonical address to another website.', null];
        }
        return [$v, null, 'The canonical address points to another website (' . $host . '). Search engines may then show that site instead of this page.'];
    }
    return [$v, null, null];
}

/**
 * Validate posted values for one entity. $input: field => raw value (only the fields being changed).
 * Returns [clean values, errors (field => message), warnings (list)].
 */
function seo_admin_validate(string $type, array $input, bool $isAdmin): array
{
    $defs = seo_admin_field_defs();
    $clean = $errors = $warnings = [];
    foreach ($input as $f => $raw) {
        if (!isset($defs[$f]) || !seo_admin_storage($type, $f)) {
            continue;
        }
        [$label, $kind, $max] = $defs[$f];
        $v = is_scalar($raw) ? clean_string((string) $raw, in_array($kind, ['textarea', 'sources'], true)) : '';
        $st = seo_admin_storage($type, $f);
        switch ($kind) {
            case 'text':
            case 'textarea':
                $v = trim((string) preg_replace('/\s+/u', ' ', $v));
                if ($v !== strip_tags($v)) {
                    $errors[$f] = $label . ': HTML is not allowed - write plain text.';
                } elseif (mb_strlen($v) > $max) {
                    $errors[$f] = $label . ' may not be longer than ' . $max . ' characters.';
                }
                break;
            case 'keywords':
                if ($v !== strip_tags($v)) {
                    $errors[$f] = $label . ': HTML is not allowed - write plain text.';
                    break;
                }
                $parts = [];
                foreach (explode(',', $v) as $k) {
                    $k = trim((string) preg_replace('/\s+/u', ' ', $k));
                    if ($k !== '' && !in_array(mb_strtolower($k), array_map('mb_strtolower', $parts), true)) {
                        $parts[] = $k;
                    }
                }
                if (count($parts) > 10) {
                    $errors[$f] = 'Enter at most 10 secondary keywords, separated by commas.';
                }
                foreach ($parts as $k) {
                    if (mb_strlen($k) > 120) {
                        $errors[$f] = 'Each secondary keyword may be at most 120 characters.';
                    }
                }
                $v = implode(', ', $parts);
                if (!isset($errors[$f]) && mb_strlen($v) > $max) {
                    $errors[$f] = $label . ' may not be longer than ' . $max . ' characters in total.';
                }
                break;
            case 'canonical':
                [$c, $err, $warn] = seo_admin_clean_canonical($v, $isAdmin);
                if ($err) {
                    $errors[$f] = $err;
                } else {
                    $v = (string) $c;
                    if ($warn) {
                        $warnings[] = $warn;
                    }
                }
                break;
            case 'tri':
                $map = ['' => '', '1' => '1', '0' => '0', 'index' => '1', 'noindex' => '0', 'follow' => '1', 'nofollow' => '0',
                    'include' => '1', 'exclude' => '0', 'yes' => '1', 'no' => '0', 'default' => ''];
                $key = strtolower($v);
                if (!array_key_exists($key, $map)) {
                    $errors[$f] = $label . ': choose a valid option.';
                    break;
                }
                $v = $map[$key];
                if ($v === '' && $st[0] === 'entity') {
                    continue 2;   // pages have no "default" for these two: leave unchanged
                }
                break;
            case 'image':
                if ($v !== '') {
                    if (preg_match('/\.svg$/i', $v)) {
                        $errors[$f] = 'Social image: SVG images are not shown by social networks. Choose a JPG or PNG (1200 × 630 px).';
                    } elseif (!site_valid_image_path($v) || ($st[0] === 'meta' && strpos($v, 'uploads/') !== 0)) {
                        $errors[$f] = 'Social image: "' . seo_admin_short($v, 60) . '" was not found. Choose it from the media library or upload it.';
                    } else {
                        $info = @getimagesize(ROOT_PATH . '/' . $v);
                        if ($info && (int) $info[0] < 600) {
                            $warnings[] = 'The social image is only ' . (int) $info[0] . ' px wide: large previews need at least 1200 × 630 px.';
                        }
                    }
                }
                break;
            case 'schema':
                if ($v !== '' && !in_array($v, SEO_ADMIN_SCHEMA_TYPES[$type] ?? [], true)) {
                    $errors[$f] = $label . ': choose one of ' . implode(', ', SEO_ADMIN_SCHEMA_TYPES[$type] ?? []) . '.';
                }
                break;
            case 'flag':
                $v = in_array(strtolower($v), ['1', 'yes', 'on', 'true'], true) ? '1' : '';
                break;
            case 'priority':
                if ($v !== '') {
                    if (!is_numeric($v) || (float) $v < 0 || (float) $v > 1) {
                        $errors[$f] = $label . ' must be between 0.0 and 1.0.';
                        break;
                    }
                    $v = number_format(round((float) $v, 1), 1, '.', '');
                }
                break;
            case 'member':
                if ($v !== '') {
                    if (!preg_match('/^\d+$/', $v) || !isset(seo_admin_team_options()[(int) $v])) {
                        $errors[$f] = $label . ': choose a published team member.';
                        break;
                    }
                    $v = (string) (int) $v;
                }
                break;
            case 'date':
                if ($v !== '') {
                    $dt = DateTime::createFromFormat('Y-m-d', $v);
                    if (!$dt || $dt->format('Y-m-d') !== $v || $v > date('Y-m-d')) {
                        $errors[$f] = $label . ' must be a valid date, not in the future.';
                    }
                }
                break;
            case 'sources':
                $list = [];
                foreach (preg_split('/\n/', $v) ?: [] as $n => $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $parts = array_map('trim', explode('|', $line));
                    $url = (string) array_pop($parts);
                    $title = mb_substr(trim(implode(' | ', $parts)), 0, 200);
                    if (!preg_match('#^https://[^\s<>"\'\\\\]+$#i', $url) || !filter_var($url, FILTER_VALIDATE_URL) || mb_strlen($url) > 500) {
                        $errors[$f] = 'Sources, line ' . ($n + 1) . ': write "Title | https://…" (secure https:// addresses only).';
                        break;
                    }
                    $list[] = ['title' => $title !== '' ? $title : (string) parse_url($url, PHP_URL_HOST), 'url' => $url];
                }
                if (count($list) > 10) {
                    $errors[$f] = 'Enter at most 10 sources.';
                }
                $v = $list ? (string) json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
                break;
        }
        if (!isset($errors[$f])) {
            $clean[$f] = $v;
        }
    }
    return [$clean, $errors, $warnings];
}

/** Insert or update the seo_meta row of an entity (only existing columns are written). */
function seo_admin_meta_write(string $type, int $id, array $data): void
{
    $cols = seo_admin_columns('seo_meta');
    $data = array_intersect_key($data, array_flip($cols));
    if (!$data) {
        return;
    }
    if (in_array('updated_by', $cols, true)) {
        $data['updated_by'] = seo_admin_user_id();
    }
    $existing = db_value('SELECT id FROM seo_meta WHERE entity_type = ? AND entity_id = ?', [$type, $id]);
    if ($existing) {
        db_update('seo_meta', $data, 'id = :id', ['id' => (int) $existing]);
    } else {
        db_insert('seo_meta', ['entity_type' => $type, 'entity_id' => $id] + $data);
    }
}

/**
 * Save validated values for one entity. Entity columns are written where the entity has them, everything else goes
 * to seo_meta (fields whose storage is missing are skipped). One seo_changes row per changed field.
 * Returns [field => [old, new]] of the changes made.
 */
function seo_admin_save(string $type, int $id, array $clean): array
{
    $row = seo_admin_entity($type, $id);
    if (!$row) {
        return [];
    }
    $meta = seo_admin_meta_row($type, $id);
    $current = seo_admin_values($type, $row, $meta);
    $entityData = $metaData = $schemaSet = $changes = [];
    foreach ($clean as $f => $new) {
        if (!array_key_exists($f, $current) || !seo_admin_field_available($type, $f)) {
            continue;
        }
        $new = (string) $new;
        $old = (string) $current[$f];
        if ($old === $new) {
            continue;
        }
        [$where, $col] = seo_admin_storage($type, $f);
        if ($where === 'entity') {
            if ($type === 'page' && $f === 'robots_index') {
                $entityData['noindex'] = $new === '0' ? 1 : 0;
            } elseif ($type === 'page' && $f === 'sitemap_include') {
                $entityData['show_in_sitemap'] = $new === '0' ? 0 : 1;
            } else {
                $entityData[$col] = $new === '' ? null : $new;
            }
        } elseif ($where === 'schema') {
            $schemaSet[$col] = $new;
        } else {
            $metaData[$col] = $new === '' ? null : $new;
        }
        $changes[$f] = [$old, $new];
    }
    if (!$changes) {
        return [];
    }
    if ($entityData) {
        $table = seo_admin_entity_table($type);
        if (in_array('updated_by', seo_admin_columns($table), true)) {
            $entityData['updated_by'] = seo_admin_user_id();
        }
        db_update($table, $entityData, 'id = :id', ['id' => $id]);
    }
    if ($schemaSet) {
        $sf = json_field($meta['schema_fields'] ?? null);
        foreach ($schemaSet as $k => $v) {
            if ($v === '') {
                unset($sf[$k]);
            } else {
                $sf[$k] = $k === 'faq_markup' ? 1 : $v;
            }
        }
        $metaData['schema_fields'] = $sf ? (string) json_encode($sf, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    }
    if ($metaData) {
        seo_admin_meta_write($type, $id, $metaData);
        seo_admin_all_meta(true);
    }
    seo_log_changes($type, $id, $changes);
    return $changes;
}

function seo_admin_log_value($v): ?string
{
    if ($v === null || (string) $v === '') {
        return null;
    }
    return mb_substr((string) $v, 0, 5000);
}

/**
 * Field-level audit: one seo_changes row per changed field ($changes = [field => [old, new]]).
 * Without the seo_changes table (migration 830 not run yet) a summary goes to the activity log instead.
 * Old rows are purged after retention_logs_days (randomly, 1 in 200 calls).
 */
function seo_log_changes(string $type, int $id, array $changes): void
{
    if (!$changes) {
        return;
    }
    $by = seo_admin_user_id();
    if (seo_admin_table_ready('seo_changes')) {
        try {
            foreach ($changes as $field => $pair) {
                $pair = array_values((array) $pair) + [null, null];
                db_insert('seo_changes', [
                    'entity_type' => mb_substr($type, 0, 30), 'entity_id' => max(0, $id), 'field' => mb_substr((string) $field, 0, 40),
                    'old_value' => seo_admin_log_value($pair[0]), 'new_value' => seo_admin_log_value($pair[1]), 'changed_by' => $by,
                ]);
            }
            if (mt_rand(1, 200) === 1) {
                $days = max(30, (int) setting('retention_logs_days', '365'));
                db_query('DELETE FROM seo_changes WHERE created_at < (NOW() - INTERVAL ' . $days . ' DAY)');
            }
            return;
        } catch (Throwable $e) {
            error_log('SEO change log failed: ' . $e->getMessage());
        }
    }
    if (function_exists('admin_log')) {
        $parts = [];
        foreach ($changes as $field => $pair) {
            $pair = array_values((array) $pair) + [null, null];
            $parts[] = $field . ': "' . seo_admin_short((string) $pair[0], 60) . '" -> "' . seo_admin_short((string) $pair[1], 60) . '"';
        }
        admin_log('seo_changed', $type . ' #' . $id . ' ' . implode('; ', $parts));
    }
}

/** SEO editor address for an entity (used by WP-7e "SEO settings" buttons in the module editors). */
function seo_entity_edit_url(string $type, int $id): string
{
    return admin_url('seo.php?tab=edit&type=' . rawurlencode($type) . '&id=' . $id);
}

/** The module's own editor for an entity (content, images, status). */
function seo_admin_module_url(string $type, int $id): string
{
    $map = ['page' => 'page-edit.php?id=%d', 'post' => 'blog-edit.php?id=%d', 'story' => 'story-edit.php?id=%d',
        'event' => 'event-edit.php?id=%d', 'campaign' => 'campaign-edit.php?id=%d', 'scheme' => 'scheme-edit.php?id=%d'];
    return isset($map[$type]) ? admin_url(sprintf($map[$type], $id)) : admin_url('dashboard.php');
}

// =====================================================================
// URL inventory
// =====================================================================

/** Visible sections of published pages, grouped by page id (one query). */
function seo_admin_page_sections(): array
{
    static $by = null;
    if ($by !== null) {
        return $by;
    }
    $by = [];
    foreach (db_rows("SELECT s.id, s.page_id, s.section_type, s.title, s.intro, s.body, s.image, s.items, s.settings
                      FROM page_sections s JOIN pages p ON p.id = s.page_id
                      WHERE s.status = 'visible' AND p.status = 'published' ORDER BY s.page_id, s.sort_order, s.id") as $s) {
        $by[(int) $s['page_id']][] = $s;
    }
    return $by;
}

/** String leaves of a decoded JSON structure, skipping keys that hold links, icons, images or styling. */
function seo_admin_json_text($data, int $depth = 0): array
{
    $out = [];
    if ($depth > 5 || !is_array($data)) {
        return $out;
    }
    foreach ($data as $k => $v) {
        if (is_string($k) && preg_match('/(url|link|href|icon|image|img|tone|style|anchor|size|cols|class|new_tab|fallback)/i', $k)) {
            continue;
        }
        if (is_array($v)) {
            $out = array_merge($out, seo_admin_json_text($v, $depth + 1));
        } elseif (is_string($v) && trim($v) !== '' && !preg_match('#^(/|https?://)#i', trim($v))) {
            $out[] = $v;
        }
    }
    return $out;
}

/** Image findings in HTML: [missing alt count, generic alt count]. */
function seo_admin_html_images(string $html, string $title): array
{
    $missing = $generic = 0;
    if ($html === '' || stripos($html, '<img') === false) {
        return [0, 0];
    }
    preg_match_all('/<img\b[^>]*>/i', $html, $m);
    foreach ($m[0] as $tag) {
        $alt = preg_match('/\balt\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $am) ? trim(html_entity_decode(($am[2] ?? '') !== '' ? $am[2] : ($am[3] ?? ''), ENT_QUOTES, 'UTF-8')) : '';
        $src = preg_match('/\bsrc\s*=\s*["\']([^"\']+)/i', $tag, $sm) ? $sm[1] : '';
        if ($alt === '') {
            $missing++;
        } elseif (seo_admin_alt_is_generic($alt, $title, $src)) {
            $generic++;
        }
    }
    return [$missing, $generic];
}

/** An alt text that only repeats the page title or the file name. */
function seo_admin_alt_is_generic(string $alt, string $title, string $src): bool
{
    $a = seo_admin_norm($alt);
    if ($a === '') {
        return false;
    }
    $file = seo_admin_norm((string) preg_replace('/\.[a-z0-9]+$/i', '', basename((string) parse_url($src, PHP_URL_PATH))));
    return $a === seo_admin_norm($title) || ($file !== '' && $a === $file) || in_array($a, ['image', 'photo', 'picture', 'img', 'banner'], true);
}

/** Heading problems inside rich text: [has h1, skipped/empty headings]. */
function seo_admin_html_headings(string $html): array
{
    if ($html === '' || !preg_match_all('#<h([1-6])\b[^>]*>(.*?)</h\1>#is', $html, $m, PREG_SET_ORDER)) {
        return [false, 0];
    }
    $h1 = false;
    $problems = 0;
    $prev = 2;   // section titles are H2
    foreach ($m as $h) {
        $level = (int) $h[1];
        if ($level === 1) {
            $h1 = true;
        }
        if (seo_admin_plain($h[2]) === '') {
            $problems++;
        } elseif ($level > $prev + 1) {
            $problems++;
        }
        $prev = $level;
    }
    return [$h1, $problems];
}

/** Effective description fallback of a page when it has none of its own: [text, source]. */
function seo_admin_page_fallback(array $p): array
{
    if (function_exists('seo_description_fallback')) {
        try {
            $fb = trim((string) seo_description_fallback($p));
            if ($fb !== '') {
                return [$fb, $fb === trim(setting('seo_default_description')) ? 'default' : 'fallback'];
            }
        } catch (Throwable $e) {
            // signature differs: use the current behaviour below
        }
    }
    if (($p['template'] ?? '') === 'program') {
        $s = (string) db_value("SELECT summary FROM programs WHERE page_id = ? AND status = 'published' LIMIT 1", [(int) $p['id']]);
        if (trim($s) !== '') {
            return [trim($s), 'fallback'];
        }
    }
    if (($p['template'] ?? '') === 'program_category') {
        try {
            $s = (string) db_value('SELECT card_text FROM program_categories WHERE page_id = ? LIMIT 1', [(int) $p['id']]);
            if (trim($s) !== '') {
                return [trim($s), 'fallback'];
            }
        } catch (Throwable $e) {
            // W2 table missing
        }
    }
    $d = trim(setting('seo_default_description'));
    return [$d, $d !== '' ? 'default' : 'none'];
}

/**
 * Base inventory rows of every public URL (cached): key "type:id" => item. Each item holds what the site prints
 * (title, description, canonical, robots rules of the SEO core), the stored values and data used by the checks.
 */
function seo_admin_items(bool $refresh = false): array
{
    static $items = null;
    if ($items !== null && !$refresh) {
        return $items;
    }
    $items = [];
    $meta = seo_admin_all_meta($refresh);
    $hidden = function_exists('program_category_hidden_page_ids') ? program_category_hidden_page_ids() : [];
    $sections = seo_admin_page_sections();

    $cols = seo_admin_select('pages', ['id', 'parent_id', 'slug', 'template', 'title', 'banner_image', 'meta_title', 'meta_description',
        'canonical_url', 'og_image', 'settings', 'status', 'show_in_sitemap', 'noindex', 'updated_at', 'created_at']);
    foreach (db_rows("SELECT $cols FROM pages WHERE status = 'published' ORDER BY sort_order, title") as $p) {
        if (in_array($p['template'], SEO_ADMIN_PRIVATE_TEMPLATES, true) || in_array((int) $p['id'], $hidden, true)) {
            continue;
        }
        $texts = $html = [];
        $imgMissing = $imgGeneric = 0;
        $h1 = (string) $p['title'];
        foreach ($sections[(int) $p['id']] ?? [] as $s) {
            $set = json_field($s['settings']);
            $itemsJson = json_field($s['items']);
            if ($p['template'] === 'home' && $s['section_type'] === 'hero_slider' && $h1 === (string) $p['title']) {
                $first = $itemsJson[0] ?? [];
                if (is_array($first) && trim((string) ($first['title'] ?? '')) !== '') {
                    $h1 = trim((string) $first['title']);
                }
            }
            $texts[] = (string) $s['title'];
            $texts[] = (string) $s['intro'];
            if ((string) $s['body'] !== '') {
                $texts[] = (string) $s['body'];
                $html[] = (string) $s['body'];
                [$a, $b] = seo_admin_html_images((string) $s['body'], (string) $p['title']);
                $imgMissing += $a;
                $imgGeneric += $b;
            }
            foreach (seo_admin_json_text($itemsJson) as $t) {
                $texts[] = $t;
            }
            if (!empty($s['image']) && in_array($s['section_type'], ['richtext', 'split', 'image'], true) && !preg_match('/\.svg$/i', (string) $s['image'])) {
                $alt = trim((string) ($set['image_alt'] ?? ''));
                if ($alt === '' && !($s['section_type'] === 'image' && trim((string) $s['title']) !== '')) {
                    $imgMissing++;
                } elseif ($alt !== '' && seo_admin_alt_is_generic($alt, (string) $p['title'], (string) $s['image'])) {
                    $imgGeneric++;
                }
            }
        }
        [$fb, $fbSource] = trim((string) $p['meta_description']) === '' ? seo_admin_page_fallback($p) : ['', ''];
        $og = (string) ($p['og_image'] ?? '');
        $items['page:' . (int) $p['id']] = seo_admin_make_item('page', $p, $meta['page:' . (int) $p['id']] ?? [], [
            'path' => page_url((string) $p['slug']),
            'template' => (string) $p['template'],
            'h1' => $h1,
            'default_title' => (string) $p['title'],
            'own_title' => (string) $p['meta_title'],
            'own_desc' => (string) $p['meta_description'],
            'fallback_desc' => $fb, 'fallback_source' => $fbSource,
            'own_canonical' => (string) ($p['canonical_url'] ?? ''),
            'flag_noindex' => (int) $p['noindex'] === 1,
            'og_explicit' => $og,
            'image' => $og !== '' ? $og : (string) ($p['banner_image'] ?? ''),
            'texts' => $texts, 'html' => $html, 'img_missing' => $imgMissing, 'img_generic' => $imgGeneric,
            'updated' => (string) ($p['updated_at'] ?: $p['created_at']),
        ]);
    }

    $add = function (string $type, string $sql, callable $build) use (&$items, $meta) {
        try {
            foreach (db_rows($sql) as $r) {
                $extra = $build($r);
                if ($extra) {
                    $items[$type . ':' . (int) $r['id']] = seo_admin_make_item($type, $r, $meta[$type . ':' . (int) $r['id']] ?? [], $extra);
                }
            }
        } catch (Throwable $e) {
            error_log('SEO inventory (' . $type . '): ' . $e->getMessage());
        }
    };
    $bodyExtra = function (array $r, array $htmlCols, array $textCols, string $title) {
        $html = $texts = [];
        $miss = $gen = 0;
        foreach ($textCols as $c) {
            $texts[] = (string) ($r[$c] ?? '');
        }
        foreach ($htmlCols as $c) {
            if (!empty($r[$c])) {
                $html[] = (string) $r[$c];
                [$a, $b] = seo_admin_html_images((string) $r[$c], $title);
                $miss += $a;
                $gen += $b;
            }
        }
        return ['texts' => array_merge($texts, $html), 'html' => $html, 'img_missing' => $miss, 'img_generic' => $gen];
    };

    $c = seo_admin_select('blog_posts', ['id', 'slug', 'title', 'category', 'excerpt', 'content', 'image', 'meta_title', 'meta_description', 'status', 'published_at', 'updated_at', 'created_at']);
    $add('post', "SELECT $c FROM blog_posts WHERE status = 'published' AND published_at IS NOT NULL AND published_at <= NOW() ORDER BY published_at DESC",
        function (array $r) use ($bodyExtra) {
            return ['path' => post_url($r['slug']), 'template' => 'post', 'h1' => (string) $r['title'], 'default_title' => (string) $r['title'],
                'own_title' => (string) ($r['meta_title'] ?? ''), 'own_desc' => (string) ($r['meta_description'] ?? ''),
                'fallback_desc' => excerpt((string) ($r['excerpt'] ?: $r['content']), 160), 'fallback_source' => 'fallback',
                'image' => (string) ($r['image'] ?? ''), 'updated' => (string) ($r['updated_at'] ?: $r['created_at'])]
                + $bodyExtra($r, ['content'], ['excerpt'], (string) $r['title']);
        });

    $c = seo_admin_select('success_stories', ['id', 'slug', 'title', 'excerpt', 'content', 'image', 'status', 'consent_confirmed', 'published_at', 'updated_at', 'created_at']);
    $add('story', "SELECT $c FROM success_stories WHERE status = 'published' AND consent_confirmed = 1 AND (published_at IS NULL OR published_at <= NOW()) ORDER BY published_at DESC",
        function (array $r) use ($bodyExtra) {
            return ['path' => story_url($r['slug']), 'template' => 'story', 'h1' => (string) $r['title'], 'default_title' => (string) $r['title'],
                'own_title' => '', 'own_desc' => '', 'fallback_desc' => excerpt((string) ($r['excerpt'] ?: $r['content']), 160), 'fallback_source' => 'fallback',
                'image' => (string) ($r['image'] ?? ''), 'updated' => (string) ($r['updated_at'] ?: $r['created_at'])]
                + $bodyExtra($r, ['content'], ['excerpt'], (string) $r['title']);
        });

    $c = seo_admin_select('events', ['id', 'slug', 'title', 'event_type', 'summary', 'description', 'agenda', 'guidelines', 'eligibility', 'image',
        'mode', 'venue_address', 'location', 'start_datetime', 'end_datetime', 'status', 'meta_title', 'meta_description', 'updated_at', 'created_at']);
    $add('event', "SELECT $c FROM events WHERE status IN ('published', 'cancelled') ORDER BY start_datetime DESC",
        function (array $r) use ($bodyExtra) {
            $awareness = ($r['event_type'] ?? '') === 'awareness_day';
            $title = (string) $r['title'];
            $default = $awareness || empty($r['start_datetime']) ? $title : $title . ' - ' . date('j M Y', (int) strtotime((string) $r['start_datetime']));
            return ['path' => event_url($r), 'template' => $awareness ? 'awareness_day' : 'event', 'h1' => $title, 'default_title' => $default,
                'own_title' => (string) ($r['meta_title'] ?? ''), 'own_desc' => (string) ($r['meta_description'] ?? ''),
                'fallback_desc' => trim((string) ($r['summary'] ?? '')) !== '' ? trim((string) $r['summary']) : excerpt((string) ($r['description'] ?? ''), 160),
                'fallback_source' => 'fallback', 'image' => (string) ($r['image'] ?? ''), 'updated' => (string) ($r['updated_at'] ?: $r['created_at']),
                'event' => ['awareness' => $awareness, 'mode' => (string) ($r['mode'] ?? ''), 'venue' => trim((string) ($r['venue_address'] ?? '') . ' ' . (string) ($r['location'] ?? '')),
                    'start' => (string) ($r['start_datetime'] ?? ''), 'end' => (string) ($r['end_datetime'] ?? ''), 'status' => (string) $r['status']]]
                + $bodyExtra($r, ['description', 'agenda', 'guidelines', 'eligibility'], ['summary'], $title);
        });

    $statuses = defined('CAMPAIGN_PUBLIC_STATUSES') ? CAMPAIGN_PUBLIC_STATUSES : ['active', 'completed', 'closed'];
    $c = seo_admin_select('campaigns', ['id', 'slug', 'title', 'short_description', 'description', 'image', 'status', 'meta_title', 'meta_description', 'updated_at', 'created_at']);
    $add('campaign', "SELECT $c FROM campaigns WHERE status IN ('" . implode("','", array_map(function ($s) { return preg_replace('/[^a-z_]/', '', $s); }, $statuses)) . "') ORDER BY created_at DESC",
        function (array $r) use ($bodyExtra) {
            return ['path' => campaign_url($r['slug']), 'template' => 'campaign', 'h1' => (string) $r['title'], 'default_title' => (string) $r['title'],
                'own_title' => (string) ($r['meta_title'] ?? ''), 'own_desc' => (string) ($r['meta_description'] ?? ''),
                'fallback_desc' => trim((string) ($r['short_description'] ?? '')), 'fallback_source' => 'fallback',
                'image' => (string) ($r['image'] ?? ''), 'updated' => (string) ($r['updated_at'] ?: $r['created_at'])]
                + $bodyExtra($r, ['description'], ['short_description'], (string) $r['title']);
        });

    $c = seo_admin_select('schemes', ['id', 'slug', 'title', 'summary', 'eligibility', 'benefits', 'how_to_apply', 'documents_needed', 'image', 'status', 'updated_at', 'created_at']);
    $add('scheme', "SELECT $c FROM schemes WHERE status = 'published' ORDER BY sort_order, title",
        function (array $r) use ($bodyExtra) {
            return ['path' => scheme_url($r['slug']), 'template' => 'scheme', 'h1' => (string) $r['title'],
                'default_title' => $r['title'] . ': eligibility, benefits & how to apply',
                'own_title' => '', 'own_desc' => '', 'fallback_desc' => trim((string) ($r['summary'] ?? '')), 'fallback_source' => 'fallback',
                'image' => (string) ($r['image'] ?? ''), 'updated' => (string) ($r['updated_at'] ?: $r['created_at'])]
                + $bodyExtra($r, ['eligibility', 'benefits', 'how_to_apply', 'documents_needed'], ['summary'], (string) $r['title']);
        });

    // awareness-day editions: canonical edition per group (rule of the SEO core, with the admin override)
    $groups = seo_admin_awareness_groups();
    foreach ($groups as $g) {
        if (empty($g['effective_id'])) {
            continue;
        }
        foreach ($g['editions'] as $ed) {
            $k = 'event:' . (int) $ed['id'];
            if (isset($items[$k]) && (int) $ed['id'] !== (int) $g['effective_id'] && $items[$k]['meta_canonical'] === '' && isset($items['event:' . (int) $g['effective_id']])) {
                $items[$k]['canonical'] = $items['event:' . (int) $g['effective_id']]['url'];
                $items[$k]['canonical_reason'] = 'awareness';
            }
        }
    }
    foreach ($items as $k => $it) {
        $items[$k]['canonical_self'] = seo_admin_norm_url($items[$k]['canonical']) === seo_admin_norm_url($it['url']);
        $items[$k]['indexable'] = $it['index'] && $items[$k]['canonical_self'];
    }
    return $items;
}

/** Build one inventory item. $x: type-specific data (path, titles, texts, html, image counts ...). */
function seo_admin_make_item(string $type, array $row, array $meta, array $x): array
{
    $id = (int) $row['id'];
    $url = seo_admin_abs((string) $x['path']);
    $values = seo_admin_values($type, $row, $meta);
    $ownTitle = trim((string) ($x['own_title'] ?? ''));
    $ownDesc = trim((string) ($x['own_desc'] ?? ''));
    if ($type === 'story' || $type === 'scheme') {
        $ownTitle = trim((string) ($meta['meta_title'] ?? ''));
        $ownDesc = trim((string) ($meta['meta_description'] ?? ''));
    }
    $seoTitle = $ownTitle !== '' ? $ownTitle : trim((string) $x['default_title']);
    $fbDesc = trim((string) ($x['fallback_desc'] ?? ''));
    $fbSource = $fbDesc === '' ? 'none' : (string) ($x['fallback_source'] ?? 'fallback');
    if ($fbDesc === '' && $type !== 'page') {
        $fbDesc = trim(setting('seo_default_description'));
        $fbSource = $fbDesc !== '' ? 'default' : 'none';
    }
    if ($type === 'page' && $ownDesc !== '' && $fbDesc === '') {
        [$fbDesc, $fbSource] = seo_admin_page_fallback($row);
    }
    [$desc, $descSource] = $ownDesc !== '' ? [$ownDesc, 'own'] : [$fbDesc, $fbSource];
    $siteNoindex = setting('seo_noindex_site') === '1';
    $index = $type === 'page' ? empty($x['flag_noindex']) : ($values['robots_index'] ?? '') !== '0';
    $follow = ($values['robots_follow'] ?? '') !== '0';
    $noindexReason = '';
    if (!$index) {
        $noindexReason = 'This URL is set to noindex.';
    }
    $emptyList = false;
    if ($type === 'page' && function_exists('seo_list_is_empty') && in_array((string) ($x['template'] ?? ''), ['blog', 'stories', 'gallery', 'team', 'documents', 'events', 'campaigns', 'schemes', 'calendar'], true)) {
        try {
            $emptyList = seo_list_is_empty($row);
        } catch (Throwable $e) {
            $emptyList = false;
        }
        if ($emptyList && $index && setting('seo_noindex_empty_lists', '1') === '1') {
            $index = false;
            $noindexReason = 'List page without public items yet: hidden (noindex, follow) until items are published. Setting "Hide list pages that have no public items".';
        }
    }
    if ($siteNoindex) {
        $index = false;
        $noindexReason = 'The whole website is hidden from search engines (Settings).';
    }
    $metaCanonical = $type === 'page' ? trim((string) ($x['own_canonical'] ?? '')) : trim((string) ($meta['canonical_url'] ?? ''));
    $canonical = $metaCanonical !== '' ? (preg_match('#^https?://#i', $metaCanonical) ? $metaCanonical : seo_admin_abs($metaCanonical)) : $url;
    $ogExplicit = $type === 'page' ? (string) ($x['og_explicit'] ?? '') : trim((string) ($meta['og_image'] ?? ''));
    $image = $ogExplicit !== '' ? $ogExplicit : (string) ($x['image'] ?? '');
    $secondary = array_values(array_filter(array_map('trim', explode(',', (string) ($values['secondary_keywords'] ?? ''))), 'strlen'));
    return [
        'key' => $type . ':' . $id, 'type' => $type, 'id' => $id, 'row' => $row,
        'template' => (string) ($x['template'] ?? $type), 'title' => (string) ($row['title'] ?? ''),
        'slug' => (string) ($row['slug'] ?? ''), 'path' => substr((string) $x['path'], strlen(base_path())) ?: '/', 'url' => $url,
        'values' => $values, 'own_title' => $ownTitle, 'own_desc' => $ownDesc,
        'seo_title' => $seoTitle, 'default_title' => trim((string) $x['default_title']), 'fallback_description' => $fbDesc, 'full_title' => seo_admin_full_title($type, (string) ($x['template'] ?? ''), $seoTitle, $ownTitle !== ''),
        'description' => $desc, 'desc_source' => $descSource,
        'h1' => trim((string) ($x['h1'] ?? '')),
        'canonical' => $canonical, 'meta_canonical' => $metaCanonical, 'canonical_reason' => $metaCanonical !== '' ? 'custom' : 'self',
        'index' => $index, 'follow' => $follow, 'noindex_reason' => $noindexReason, 'empty_list' => $emptyList,
        'robots' => ($index ? 'index' : 'noindex') . ', ' . ($follow ? 'follow' : 'nofollow'),
        'focus' => trim((string) ($values['focus_keyword'] ?? '')), 'secondary' => $secondary,
        'og_explicit' => $ogExplicit, 'image' => $image,
        'texts' => $x['texts'] ?? [], 'html' => $x['html'] ?? [],
        'img_missing' => (int) ($x['img_missing'] ?? 0), 'img_generic' => (int) ($x['img_generic'] ?? 0),
        'event' => $x['event'] ?? null, 'updated' => (string) ($x['updated'] ?? ''),
        'edit_url' => seo_entity_edit_url($type, $id), 'module_url' => seo_admin_module_url($type, $id),
    ];
}

/** The <title> the site prints for a title (suffix rule of the SEO core when installed, else today's rule). */
function seo_admin_full_title(string $type, string $template, string $title, bool $own): string
{
    $site = site_name();
    if ($template === 'home') {
        if ($own && function_exists('seo_finalize')) {
            return $title;   // SEO core prints a home meta title verbatim (CRITIC C13)
        }
        return ($title !== '' && $title !== 'Home' ? $title : $site) . ' - ' . setting('site_tagline_en', 'Together, let us build a better tomorrow');
    }
    if ($title === '' || stripos($title, $site) !== false) {
        return $title;
    }
    $suffix = setting('seo_title_suffix', ' | ' . $site);
    $limit = function_exists('seo_finalize') ? seo_admin_title_max() : 70;
    return mb_strlen($title . $suffix) <= $limit ? $title . $suffix : $title;
}

/** Unique text of an item for word counts (sections and body, without banner and footer). */
function seo_text_of(array $item): string
{
    $parts = [];
    foreach ($item['texts'] ?? [] as $t) {
        $t = seo_admin_plain((string) $t);
        if ($t !== '') {
            $parts[] = $t;
        }
    }
    return implode(' ', $parts);
}

/** Absolute URLs listed in the XML sitemap(s) (SEO sitemap module when installed, else the current sitemap). */
function seo_admin_sitemap_locs(): array
{
    static $locs = null;
    if ($locs !== null) {
        return $locs;
    }
    $locs = [];
    foreach (seo_admin_sitemap_children() as $child) {
        if ($child['images']) {
            continue;
        }
        foreach ($child['entries'] as $e) {
            $locs[seo_admin_norm_url((string) $e['loc'])] = true;
        }
    }
    if (!$locs) {
        try {
            foreach (sitemap_entries() as $e) {
                $locs[seo_admin_norm_url((string) $e['loc'])] = true;
            }
        } catch (Throwable $e) {
            $locs = [];
        }
    }
    return $locs;
}

/** Definitions of the checks: code => [topic, level (error|warn|info), label]. */
function seo_admin_check_defs(): array
{
    return [
        'title_missing' => ['Titles', 'error', 'Search title missing'],
        'title_short' => ['Titles', 'warn', 'Title under ' . SEO_ADMIN_TITLE_MIN . ' characters (with the site name)'],
        'title_long' => ['Titles', 'warn', 'Title over ' . seo_admin_title_max() . ' characters (before the site name)'],
        'title_duplicate' => ['Titles', 'warn', 'Same title as another URL'],
        'desc_missing' => ['Descriptions', 'error', 'No description at all'],
        'desc_default' => ['Descriptions', 'warn', 'Uses the site-wide default description'],
        'desc_short' => ['Descriptions', 'info', 'Description under ' . SEO_ADMIN_DESC_MIN . ' characters'],
        'desc_long' => ['Descriptions', 'warn', 'Description over ' . SEO_ADMIN_DESC_MAX . ' characters'],
        'desc_duplicate' => ['Descriptions', 'warn', 'Same description as another URL'],
        'h1_missing' => ['Headings', 'error', 'Main heading (H1) missing'],
        'h1_in_content' => ['Headings', 'warn', 'Extra H1 inside the content'],
        'heading_skip' => ['Headings', 'info', 'Skipped or empty headings in the content'],
        'kw_none' => ['Keywords', 'info', 'No focus keyword set'],
        'kw_title' => ['Keywords', 'info', 'Focus keyword not in the title'],
        'kw_h1' => ['Keywords', 'info', 'Focus keyword not in the H1'],
        'kw_desc' => ['Keywords', 'info', 'Focus keyword not in the description'],
        'kw_url' => ['Keywords', 'info', 'Focus keyword words not in the URL'],
        'kw_intro' => ['Keywords', 'info', 'Focus keyword not in the first 100 words'],
        'kw_cannibal' => ['Keywords', 'warn', 'Same focus keyword on several URLs'],
        'kw_hindi' => ['Keywords', 'info', 'Hindi keyword on a page without Hindi text'],
        'kw_secondary' => ['Keywords', 'info', 'Secondary keywords not found in the text'],
        'thin' => ['Content', 'warn', 'Little text (under ' . SEO_ADMIN_THIN_WORDS . ' words)'],
        'empty_list' => ['Content', 'warn', 'List page without public items'],
        'img_alt' => ['Images', 'warn', 'Images without a description (alt)'],
        'img_alt_generic' => ['Images', 'info', 'Image descriptions that only repeat the title or file name'],
        'og_default' => ['Images', 'info', 'Shared with the default social image'],
        'og_bad' => ['Images', 'warn', 'Social image missing, SVG or too small'],
        'link_broken' => ['Links', 'error', 'Broken internal links'],
        'link_draft' => ['Links', 'warn', 'Links to unpublished content'],
        'link_redirect' => ['Links', 'info', 'Links that go through a redirect'],
        'link_external' => ['Links', 'warn', 'External links with errors (manual check)'],
        'orphan' => ['Links', 'warn', 'No internal link points here (orphan)'],
        'noindex' => ['Indexing', 'info', 'Hidden from search engines (noindex)'],
        'noindex_sitemap' => ['Indexing', 'error', 'Noindex but listed in the sitemap'],
        'canonical_other' => ['Indexing', 'info', 'Canonical points to another URL'],
        'canonical_bad' => ['Indexing', 'error', 'Canonical target missing, unpublished or noindex'],
        'canonical_offsite' => ['Indexing', 'warn', 'Canonical on another website'],
        'canonical_sitemap' => ['Indexing', 'warn', 'Canonicalised URL listed in the sitemap'],
        'schema_event' => ['Structured data', 'warn', 'Event without image or address'],
        'schema_article' => ['Structured data', 'warn', 'Article without image'],
    ];
}

/**
 * Cross-URL context for the checks (duplicates, keywords, links, orphans, sitemap) and every item's issues.
 * Cached per request; $refresh rebuilds it after a save.
 */
function seo_admin_context(bool $refresh = false): array
{
    static $ctx = null;
    if ($ctx !== null && !$refresh) {
        return $ctx;
    }
    $items = seo_admin_items($refresh);
    $scan = seo_scan_internal_links($refresh);
    $titles = $descs = $focus = [];
    foreach ($items as $k => $it) {
        if (!$it['indexable']) {
            continue;
        }
        if ($it['seo_title'] !== '') {
            $titles[seo_admin_norm($it['seo_title'])][] = $k;
        }
        if ($it['description'] !== '' && $it['desc_source'] !== 'default') {
            $descs[seo_admin_norm($it['description'])][] = $k;
        }
        if ($it['focus'] !== '') {
            $focus[seo_admin_norm($it['focus'])][] = $k;
        }
    }
    $ctx = [
        'items' => $items, 'scan' => $scan, 'titles' => $titles, 'descs' => $descs, 'focus' => $focus,
        'sitemap' => seo_admin_sitemap_locs(), 'external' => seo_admin_external_results(),
        'orphans' => seo_admin_orphan_keys($items, $scan),
    ];
    foreach ($ctx['items'] as $k => $it) {
        $ctx['items'][$k]['in_sitemap'] = isset($ctx['sitemap'][seo_admin_norm_url($it['url'])]);
        $ctx['items'][$k]['words'] = seo_word_count(seo_text_of($it));
    }
    foreach ($ctx['items'] as $k => $it) {
        $ctx['items'][$k]['issues'] = seo_admin_compute_checks($it, $ctx);
    }
    return $ctx;
}

/** Checks for one inventory item (advisory; uses the cached cross-URL context). Returns [code => detail]. */
function seo_checks_for(array $item): array
{
    $ctx = seo_admin_context();
    if (!isset($item['in_sitemap'])) {
        $item = $ctx['items'][$item['key']] ?? $item;
    }
    return seo_admin_compute_checks($item, $ctx);
}

/** The checks catalogue (SEO-DESIGN 4.9). Returns [code => detail text]. */
function seo_admin_compute_checks(array $it, array $ctx): array
{
    $out = [];
    $k = $it['key'];
    $content = $it['indexable'];
    $tlen = mb_strlen($it['seo_title']);
    $dlen = mb_strlen($it['description']);
    if ($content) {
        if ($it['seo_title'] === '') {
            $out['title_missing'] = 'No title.';
        } elseif (mb_strlen($it['full_title']) < SEO_ADMIN_TITLE_MIN) {
            $out['title_short'] = mb_strlen($it['full_title']) . ' characters including the site name.';
        } elseif ($tlen > seo_admin_title_max()) {
            $out['title_long'] = $tlen . ' characters (limit ' . seo_admin_title_max() . ').';
        }
        $dup = $ctx['titles'][seo_admin_norm($it['seo_title'])] ?? [];
        if ($it['seo_title'] !== '' && count($dup) > 1) {
            $out['title_duplicate'] = 'Also used by ' . (count($dup) - 1) . ' other URL(s).';
        }
        if ($it['desc_source'] === 'none') {
            $out['desc_missing'] = 'No description and no site default.';
        } elseif ($it['desc_source'] === 'default') {
            $out['desc_default'] = 'Falls back to the default description from Settings.';
        }
        if ($it['desc_source'] !== 'none' && $it['desc_source'] !== 'default') {
            if ($dlen < SEO_ADMIN_DESC_MIN) {
                $out['desc_short'] = $dlen . ' characters.';
            } elseif ($dlen > SEO_ADMIN_DESC_MAX) {
                $out['desc_long'] = $dlen . ' characters.';
            }
            $dup = $ctx['descs'][seo_admin_norm($it['description'])] ?? [];
            if (count($dup) > 1) {
                $out['desc_duplicate'] = 'Also used by ' . (count($dup) - 1) . ' other URL(s).';
            }
        }
    }
    if ($it['h1'] === '') {
        $out['h1_missing'] = 'The page has no title for its main heading.';
    }
    $h1Extra = $skips = 0;
    foreach ($it['html'] as $h) {
        [$hasH1, $p] = seo_admin_html_headings((string) $h);
        $h1Extra += $hasH1 ? 1 : 0;
        $skips += $p;
    }
    if ($h1Extra) {
        $out['h1_in_content'] = $h1Extra . ' text block(s) contain an H1; use H2/H3 inside the content.';
    }
    if ($skips) {
        $out['heading_skip'] = $skips . ' heading(s).';
    }
    if ($content) {
        $text = seo_text_of($it);
        if ($it['focus'] === '') {
            $out['kw_none'] = 'Add a focus keyword in the SEO editor to get keyword hints.';
        } else {
            $kw = $it['focus'];
            if (!seo_admin_contains($it['seo_title'], $kw)) {
                $out['kw_title'] = '"' . $kw . '"';
            }
            if (!seo_admin_contains($it['h1'], $kw)) {
                $out['kw_h1'] = '"' . $kw . '"';
            }
            if (!seo_admin_contains($it['description'], $kw)) {
                $out['kw_desc'] = '"' . $kw . '"';
            }
            if (!seo_admin_kw_in_url($kw, $it['path'])) {
                $out['kw_url'] = 'Advice only: addresses are never changed automatically.';
            }
            $first = implode(' ', array_slice(preg_split('/\s+/u', $text) ?: [], 0, 100));
            if (!seo_admin_contains($first, $kw)) {
                $out['kw_intro'] = '"' . $kw . '"';
            }
            $same = $ctx['focus'][seo_admin_norm($kw)] ?? [];
            if (count($same) > 1) {
                $out['kw_cannibal'] = 'Also the focus keyword of ' . (count($same) - 1) . ' other URL(s).';
            }
            if (preg_match('/\p{Devanagari}/u', $kw) && !preg_match('/\p{Devanagari}/u', $text . ' ' . $it['seo_title'])) {
                $out['kw_hindi'] = 'Use Hindi keywords only on pages with real Hindi text.';
            }
        }
        $missing = [];
        foreach ($it['secondary'] as $s) {
            if (!seo_admin_contains($text . ' ' . $it['seo_title'] . ' ' . $it['description'], $s)) {
                $missing[] = $s;
            }
        }
        if ($missing) {
            $out['kw_secondary'] = implode(', ', array_slice($missing, 0, 5)) . (count($missing) > 5 ? ' …' : '');
        }
        $thinApplies = $it['type'] !== 'page' || in_array($it['template'], SEO_ADMIN_CONTENT_TEMPLATES, true);
        $words = isset($it['words']) ? (int) $it['words'] : seo_word_count($text);
        if ($thinApplies && $words < SEO_ADMIN_THIN_WORDS) {
            $out['thin'] = $words . ' words in the page\'s own text.';
        }
    }
    if (!empty($it['empty_list'])) {
        $out['empty_list'] = 'The list has no public items yet: publish real items (or add more text) to make it worth indexing.';
    }
    if ($it['img_missing']) {
        $out['img_alt'] = $it['img_missing'] . ' image(s).';
    }
    if ($it['img_generic']) {
        $out['img_alt_generic'] = $it['img_generic'] . ' image(s).';
    }
    $img = (string) $it['image'];
    if ($it['og_explicit'] !== '') {
        $info = (!preg_match('/\.svg$/i', $it['og_explicit']) && is_file(ROOT_PATH . '/' . $it['og_explicit'])) ? @getimagesize(ROOT_PATH . '/' . $it['og_explicit']) : false;
        if (!$info || (int) $info[0] < 600) {
            $out['og_bad'] = preg_match('/\.svg$/i', $it['og_explicit']) ? 'The chosen social image is an SVG.' : (!$info ? 'The chosen social image file is missing.' : 'Only ' . (int) $info[0] . ' px wide.');
        }
    } elseif ($img === '' || preg_match('/\.svg$/i', $img)) {
        if ($content) {
            $out['og_default'] = 'No page photo: shares use the default card.';
        }
    } elseif (!is_file(ROOT_PATH . '/' . $img)) {
        $out['og_bad'] = 'The page image file is missing.';
    }
    $links = $ctx['scan']['by_source'][$k] ?? null;
    if ($links) {
        if ($links['broken']) {
            $out['link_broken'] = $links['broken'] . ' link(s).';
        }
        if ($links['draft']) {
            $out['link_draft'] = $links['draft'] . ' link(s).';
        }
        if ($links['redirect']) {
            $out['link_redirect'] = $links['redirect'] . ' link(s).';
        }
    }
    $extBad = 0;
    foreach ($ctx['scan']['external_by_source'][$k] ?? [] as $u) {
        $r = $ctx['external'][$u] ?? null;
        if ($r && seo_admin_external_is_error($r)) {
            $extBad++;
        }
    }
    if ($extBad) {
        $out['link_external'] = $extBad . ' link(s).';
    }
    if ($content && isset($ctx['orphans'][$k])) {
        $out['orphan'] = 'Only the sitemaps link here.';
    }
    if (!$it['index']) {
        $out['noindex'] = $it['noindex_reason'];
        if (!empty($it['in_sitemap'])) {
            $out['noindex_sitemap'] = 'Remove it from the sitemap or allow indexing.';
        }
    }
    if (!$it['canonical_self']) {
        $out['canonical_other'] = $it['canonical'] . ($it['canonical_reason'] === 'awareness' ? ' (current edition of this observance)' : '');
        if (!seo_admin_same_site($it['canonical'])) {
            $out['canonical_offsite'] = seo_admin_host_key($it['canonical']);
        } else {
            $target = seo_admin_resolve_path((string) seo_admin_site_path($it['canonical']));
            $targetItem = $target['entity'] ? ($ctx['items'][$target['entity']] ?? null) : null;
            if ($target['final_status'] !== 'ok' || ($targetItem && !$targetItem['index'])) {
                $out['canonical_bad'] = $target['final_status'] !== 'ok' ? 'Target: ' . $target['final_status'] . '.' : 'Target is noindex.';
            }
        }
        if (!empty($it['in_sitemap'])) {
            $out['canonical_sitemap'] = 'List only the canonical URL in the sitemap.';
        }
    }
    if ($it['type'] === 'event' && empty($it['event']['awareness'])) {
        $miss = [];
        if ($img === '' || preg_match('/\.svg$/i', $img)) {
            $miss[] = 'photo';
        }
        if (in_array($it['event']['mode'] ?? '', ['in_person', 'hybrid', ''], true) && trim((string) ($it['event']['venue'] ?? '')) === '') {
            $miss[] = 'venue address';
        }
        if ($miss) {
            $out['schema_event'] = 'Missing: ' . implode(', ', $miss) . '.';
        }
    }
    if (in_array($it['type'], ['post', 'story'], true) && ($img === '' || preg_match('/\.svg$/i', $img))) {
        $out['schema_article'] = 'Add a photo to the article.';
    }
    return $out;
}

/** Do the main words of a keyword appear in the URL path (advice only)? */
function seo_admin_kw_in_url(string $kw, string $path): bool
{
    $stop = ['a', 'an', 'and', 'the', 'for', 'of', 'in', 'to', 'with', 'on', 'at', 'by', 'or', 'ngo', 'india'];
    $tokens = preg_split('/[^a-z0-9]+/', strtolower($path)) ?: [];
    $words = array_filter(explode(' ', seo_admin_norm($kw)), function ($w) use ($stop) {
        return mb_strlen($w) >= 3 && !in_array($w, $stop, true);
    });
    if (!$words) {
        return true;
    }
    foreach ($words as $w) {
        $found = false;
        foreach ($tokens as $t) {
            if ($t !== '' && ($t === $w || (mb_strlen($w) >= 5 && strpos($t, mb_substr($w, 0, 5)) === 0))) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return false;
        }
    }
    return true;
}

/**
 * Inventory with checks, filtered. $filters: type, issue (check code), q (title/URL search), index ('yes'|'no'),
 * sitemap ('yes'|'no'), keyword ('yes'|'no'), level ('error'|'warn'|'info'). Returns the matching items.
 */
function seo_url_inventory(array $filters = []): array
{
    $ctx = seo_admin_context();
    $defs = seo_admin_check_defs();
    $out = [];
    $q = mb_strtolower(trim((string) ($filters['q'] ?? '')));
    foreach ($ctx['items'] as $it) {
        if (!empty($filters['type']) && $it['type'] !== $filters['type']) {
            continue;
        }
        if (!empty($filters['issue']) && !isset($it['issues'][$filters['issue']])) {
            continue;
        }
        if (!empty($filters['level'])) {
            $has = false;
            foreach (array_keys($it['issues']) as $code) {
                if (($defs[$code][1] ?? '') === $filters['level']) {
                    $has = true;
                }
            }
            if (!$has) {
                continue;
            }
        }
        if ($q !== '' && strpos(mb_strtolower($it['title'] . ' ' . $it['seo_title'] . ' ' . $it['path'] . ' ' . $it['focus']), $q) === false) {
            continue;
        }
        if (($filters['index'] ?? '') === 'yes' && !$it['indexable']) {
            continue;
        }
        if (($filters['index'] ?? '') === 'no' && $it['indexable']) {
            continue;
        }
        if (($filters['sitemap'] ?? '') === 'yes' && empty($it['in_sitemap'])) {
            continue;
        }
        if (($filters['sitemap'] ?? '') === 'no' && !empty($it['in_sitemap'])) {
            continue;
        }
        if (($filters['keyword'] ?? '') === 'yes' && $it['focus'] === '') {
            continue;
        }
        if (($filters['keyword'] ?? '') === 'no' && $it['focus'] !== '') {
            continue;
        }
        $out[$it['key']] = $it;
    }
    return $out;
}

/** Number of URLs per check code, plus totals. */
function seo_issue_counts(): array
{
    $ctx = seo_admin_context();
    $counts = array_fill_keys(array_keys(seo_admin_check_defs()), 0);
    $tot = ['urls' => 0, 'indexable' => 0, 'noindex' => 0, 'canonicalised' => 0, 'in_sitemap' => 0, 'with_keyword' => 0, 'by_type' => []];
    foreach ($ctx['items'] as $it) {
        $tot['urls']++;
        $tot['by_type'][$it['type']] = ($tot['by_type'][$it['type']] ?? 0) + 1;
        $tot['indexable'] += $it['indexable'] ? 1 : 0;
        $tot['noindex'] += $it['index'] ? 0 : 1;
        $tot['canonicalised'] += ($it['index'] && !$it['canonical_self']) ? 1 : 0;
        $tot['in_sitemap'] += !empty($it['in_sitemap']) ? 1 : 0;
        $tot['with_keyword'] += $it['focus'] !== '' ? 1 : 0;
        foreach (array_keys($it['issues']) as $code) {
            if (isset($counts[$code])) {
                $counts[$code]++;
            }
        }
    }
    return ['checks' => $counts, 'totals' => $tot];
}

// =====================================================================
// Links: scanner, resolution, orphans, external check, redirects
// =====================================================================

/** Lookup maps for link resolution (one query per table, cached). */
function seo_admin_maps(bool $refresh = false): array
{
    static $maps = null;
    if ($maps !== null && !$refresh) {
        return $maps;
    }
    $maps = ['pages' => [], 'redirects' => [], 'post' => [], 'story' => [], 'event' => [], 'campaign' => [], 'scheme' => []];
    foreach (db_rows('SELECT id, slug, template, status FROM pages') as $p) {
        $maps['pages'][strtolower($p['slug'])] = $p;
    }
    foreach (db_rows('SELECT id, source_path, target_url, status_code, hits FROM redirects ORDER BY id') as $r) {
        $src = strtolower(trim((string) $r['source_path'], '/'));
        if ($src !== '' && !isset($maps['redirects'][$src])) {
            $maps['redirects'][$src] = $r;
        }
    }
    $load = function (string $key, string $sql) use (&$maps) {
        try {
            foreach (db_rows($sql) as $r) {
                $maps[$key][strtolower($r['slug'])] = $r;
            }
        } catch (Throwable $e) {
            // table not used yet
        }
    };
    $load('post', 'SELECT id, slug, status, published_at FROM blog_posts');
    $load('story', 'SELECT id, slug, status, consent_confirmed, published_at FROM success_stories');
    $load('event', 'SELECT id, slug, status, event_type FROM events');
    $load('campaign', 'SELECT id, slug, status FROM campaigns');
    $load('scheme', 'SELECT id, slug, status FROM schemes');
    return $maps;
}

/**
 * Status of a site path WITHOUT the redirects table: ['status' => ok|draft|missing|private|redirect, 'entity' => 'type:id'|null,
 * 'target' => path when the site itself answers with a 301 (old event addresses, /home/, list containers)].
 */
function seo_admin_route_status(string $path): array
{
    $maps = seo_admin_maps();
    $raw = trim($path, '/');
    $p = strtolower($raw);
    $res = function (string $status, ?string $entity = null, ?string $target = null) {
        return ['status' => $status, 'entity' => $entity, 'target' => $target];
    };
    if ($p === '' || $p === 'index.php') {
        $home = $maps['pages']['home'] ?? null;
        return $p === '' ? $res('ok', $home ? 'page:' . (int) $home['id'] : null) : $res('redirect', null, '');
    }
    if ($p === 'home') {
        return $res('redirect', null, '');
    }
    if (in_array($p, ['sitemap.xml', 'robots.txt'], true) || preg_match('/^sitemap-[a-z]+\.xml$/', $p)) {
        return $res('ok');
    }
    if (preg_match('#^(uploads|assets)/#i', $raw)) {
        if (preg_match('#^uploads/(documents|certificates)/#i', $raw)) {
            return $res('private');
        }
        return $res(strpos($raw, '..') === false && is_file(ROOT_PATH . '/' . $raw) ? 'ok' : 'missing');
    }
    if (preg_match('#^(admin|api|dashboard)(/|$)#', $p)) {
        return $res('private');
    }
    if (isset($maps['pages'][$p])) {
        $pg = $maps['pages'][$p];
        if ($pg['status'] !== 'published') {
            return $res('draft', 'page:' . (int) $pg['id']);
        }
        if ($pg['template'] === 'campaign_detail') {
            return $res('redirect', null, 'campaigns');
        }
        if ($pg['template'] === 'event_detail') {
            return $res('redirect', null, $p === 'hackathons' && function_exists('programhub_hackathons_url') ? (string) seo_admin_site_path(programhub_hackathons_url()) : 'events');
        }
        if (in_array($pg['template'], ['member', 'logout'], true)) {
            return $res('private', 'page:' . (int) $pg['id']);
        }
        return $res('ok', 'page:' . (int) $pg['id']);
    }
    $parts = explode('/', $p);
    $segs = [];
    while (count($parts) > 1) {
        array_unshift($segs, array_pop($parts));
        $base = implode('/', $parts);
        $pg = $maps['pages'][$base] ?? null;
        if (!$pg || !in_array($pg['template'], PARAM_TEMPLATES, true)) {
            continue;
        }
        if ($pg['status'] !== 'published') {
            return $res('draft', 'page:' . (int) $pg['id']);
        }
        $slug = $segs[0];
        switch ($pg['template']) {
            case 'blog':
                $post = $maps['post'][$slug] ?? null;
                if (count($segs) !== 1 || !$post) {
                    return $res('missing');
                }
                if ($base !== 'blog') {
                    return $res('redirect', null, 'blog/' . $slug);
                }
                $visible = $post['status'] === 'published' && $post['published_at'] && strtotime((string) $post['published_at']) <= time();
                return $res($visible ? 'ok' : 'draft', 'post:' . (int) $post['id']);
            case 'stories':
                $st = $maps['story'][$slug] ?? null;
                if (count($segs) !== 1 || !$st) {
                    return $res('missing');
                }
                $visible = $st['status'] === 'published' && (int) $st['consent_confirmed'] === 1 && (!$st['published_at'] || strtotime((string) $st['published_at']) <= time());
                return $res($visible ? 'ok' : 'draft', 'story:' . (int) $st['id']);
            case 'events':
            case 'event_detail':
                $ev = $maps['event'][$slug] ?? null;
                $sub = implode('/', array_slice($segs, 1));
                $subOk = in_array($sub, ['', 'ics', 'join', 'confirmation'], true) || preg_match('#^files/[1-9][0-9]{0,9}$#', $sub);
                if (!$ev || !$subOk) {
                    return $res('missing');
                }
                if (!in_array($ev['status'], ['published', 'cancelled'], true)) {
                    return $res('draft', 'event:' . (int) $ev['id']);
                }
                $canon = trim((string) seo_admin_site_path(event_url($ev)), '/');
                if ($canon !== $base . '/' . $slug) {
                    return $res('redirect', null, $canon . ($sub !== '' ? '/' . $sub : ''));
                }
                return $res('ok', $sub === '' ? 'event:' . (int) $ev['id'] : null);
            case 'campaign_detail':
                $c = $maps['campaign'][$slug] ?? null;
                if (count($segs) !== 1 || !$c) {
                    return $res('missing');
                }
                $public = function_exists('campaign_is_public') ? campaign_is_public($c) : in_array($c['status'], ['active', 'completed', 'closed'], true);
                return $res($public ? 'ok' : 'draft', 'campaign:' . (int) $c['id']);
            case 'schemes':
                $s = $maps['scheme'][$slug] ?? null;
                if (count($segs) !== 1 || !$s) {
                    return $res('missing');
                }
                return $res($s['status'] === 'published' ? 'ok' : 'draft', 'scheme:' . (int) $s['id']);
            case 'member':
                return $res('private');
            default:
                return $res('missing');
        }
    }
    return $res('missing');
}

/**
 * Resolve a site path the way index.php does (redirects table first, then routing), following redirects.
 * Returns ['status' => ok|draft|missing|private|redirect, 'final_status', 'final' (path or external URL), 'hops',
 * 'loop' (bool), 'entity' (final entity key or null), 'chain' => [paths]].
 */
function seo_admin_resolve_path(string $path): array
{
    static $cache = [];
    $start = strtolower(trim($path, '/'));
    if (preg_match('#^(uploads|assets)/#i', trim($path, '/'))) {
        $start = trim($path, '/');   // file paths are case-sensitive
    }
    if (isset($cache[$start])) {
        return $cache[$start];
    }
    $maps = seo_admin_maps();
    $cur = $start;
    $hops = 0;
    $seen = [];
    $chain = [$start];
    $out = ['status' => 'missing', 'final_status' => 'missing', 'final' => $start, 'hops' => 0, 'loop' => false, 'entity' => null, 'chain' => []];
    while (true) {
        if (isset($seen[$cur]) || $hops > 10) {
            $out = ['status' => 'redirect', 'final_status' => 'loop', 'final' => $cur, 'hops' => $hops, 'loop' => true, 'entity' => null];
            break;
        }
        $seen[$cur] = true;
        $r = $cur !== '' ? ($maps['redirects'][$cur] ?? null) : null;
        if ($r) {
            $hops++;
            $target = trim((string) $r['target_url']);
            $tp = seo_admin_site_path($target);
            if ($tp === null) {
                $out = ['status' => 'redirect', 'final_status' => 'external', 'final' => $target, 'hops' => $hops, 'loop' => false, 'entity' => null];
                break;
            }
            $cur = preg_match('#^(uploads|assets)/#i', $tp) ? $tp : strtolower($tp);
            $chain[] = $cur;
            continue;
        }
        $rs = seo_admin_route_status($cur);
        if ($rs['status'] === 'redirect') {
            $hops++;
            $cur = strtolower(trim((string) $rs['target'], '/'));
            $chain[] = $cur;
            continue;
        }
        $out = ['status' => $hops ? 'redirect' : $rs['status'], 'final_status' => $rs['status'], 'final' => $cur, 'hops' => $hops, 'loop' => false, 'entity' => $rs['entity']];
        break;
    }
    $out['chain'] = $chain;
    $cache[$start] = $out;
    return $out;
}

/** Classify one link found in content: kind anchor|contact|external|internal|other (+ resolution for internal ones). */
function seo_admin_classify_link(string $href): array
{
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '' || $href[0] === '#') {
        return ['kind' => 'anchor'];
    }
    if (preg_match('#^(mailto|tel|sms|whatsapp):#i', $href)) {
        return ['kind' => 'contact'];
    }
    if (preg_match('#^javascript:#i', $href)) {
        return ['kind' => 'internal', 'status' => 'missing', 'final_status' => 'missing', 'final' => '', 'hops' => 0, 'loop' => false, 'entity' => null, 'chain' => [], 'path' => ''];
    }
    if (preg_match('#^//#', $href)) {
        $href = 'https:' . $href;
    }
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $href) && !preg_match('#^https?://#i', $href)) {
        return ['kind' => 'other'];
    }
    $path = seo_admin_site_path(preg_match('#^https?://#i', $href) ? $href : '/' . ltrim($href, '/'));
    if ($path === null) {
        return ['kind' => 'external', 'url' => mb_substr($href, 0, 500)];
    }
    return ['kind' => 'internal', 'path' => $path] + seo_admin_resolve_path($path);
}

/** Hrefs in HTML. */
function seo_admin_html_links(string $html): array
{
    if ($html === '' || stripos($html, 'href') === false) {
        return [];
    }
    preg_match_all('/<a\b[^>]*?\bhref\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $html, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $a) {
        $out[] = ($a[2] ?? '') !== '' ? $a[2] : ($a[3] ?? '');
    }
    return $out;
}

/** Link values in decoded JSON (keys ending in url / link / href). */
function seo_admin_json_links($data, int $depth = 0): array
{
    $out = [];
    if ($depth > 5 || !is_array($data)) {
        return $out;
    }
    foreach ($data as $k => $v) {
        if (is_array($v)) {
            $out = array_merge($out, seo_admin_json_links($v, $depth + 1));
        } elseif (is_string($v) && is_string($k) && preg_match('/(^|_)(url|link|href)$/i', $k) && trim($v) !== '') {
            $out[] = trim($v);
        }
    }
    return $out;
}

/**
 * Scan links in stored content (page sections and banner buttons, article / story / event / campaign / scheme bodies,
 * visible menu items, header and announcement links) and resolve internal ones against pages, entity slugs and
 * redirects. No HTTP requests are made. Cached per request.
 * Returns ['links' => [...], 'by_source' => [key => counts], 'inbound' => [entity key => [source labels]],
 *          'external' => [url => [source keys]], 'external_by_source' => [key => [urls]]].
 */
function seo_scan_internal_links(bool $refresh = false): array
{
    static $scan = null;
    if ($scan !== null && !$refresh) {
        return $scan;
    }
    if ($refresh) {
        seo_admin_maps(true);
    }
    $scan = ['links' => [], 'by_source' => [], 'inbound' => [], 'external' => [], 'external_by_source' => []];
    $record = function (string $key, string $label, string $where, string $edit, string $href) use (&$scan) {
        $c = seo_admin_classify_link($href);
        if (!in_array($c['kind'], ['internal', 'external'], true)) {
            return;
        }
        if (!isset($scan['by_source'][$key])) {
            $scan['by_source'][$key] = ['broken' => 0, 'draft' => 0, 'redirect' => 0, 'ok' => 0, 'external' => 0];
        }
        if ($c['kind'] === 'external') {
            $scan['external'][$c['url']][$key] = $label;
            $scan['external_by_source'][$key][$c['url']] = $c['url'];
            $scan['by_source'][$key]['external']++;
            return;
        }
        $final = $c['final_status'];
        $bucket = in_array($final, ['missing', 'loop'], true) ? 'broken' : ($final === 'draft' ? 'draft' : ($c['hops'] ? 'redirect' : 'ok'));
        $scan['by_source'][$key][$bucket]++;
        if ($c['hops'] && $bucket !== 'redirect') {
            $scan['by_source'][$key]['redirect']++;   // broken or draft at the end of a redirect: counted in both lists
        }
        if ($c['entity'] && $final === 'ok' && $c['entity'] !== $key) {
            $scan['inbound'][$c['entity']][$key] = $label;
        }
        $scan['links'][] = ['source' => $key, 'label' => $label, 'where' => $where, 'edit' => $edit, 'href' => mb_substr($href, 0, 500),
            'status' => $bucket, 'hops' => $c['hops'], 'final' => $c['final'], 'final_status' => $final, 'loop' => $c['loop']];
    };

    // pages: sections + banner buttons (published pages only: drafts are not public)
    $pages = [];
    foreach (db_rows("SELECT id, slug, title, banner_buttons, template FROM pages WHERE status = 'published'") as $p) {
        $pages[(int) $p['id']] = $p;
        $label = 'Page: ' . $p['title'];
        foreach (seo_admin_json_links(json_field($p['banner_buttons'])) as $href) {
            $record('page:' . (int) $p['id'], $label, 'Banner buttons', admin_url('page-edit.php?id=' . (int) $p['id']), $href);
        }
    }
    foreach (seo_admin_page_sections() as $pid => $list) {
        if (!isset($pages[$pid])) {
            continue;
        }
        $label = 'Page: ' . $pages[$pid]['title'];
        foreach ($list as $s) {
            $where = 'Section "' . ($s['title'] !== '' ? seo_admin_short((string) $s['title'], 50) : humanize((string) $s['section_type'])) . '"';
            $edit = admin_url('page-edit.php?id=' . $pid . '&tab=sections&open=' . (int) $s['id'] . '#section-' . (int) $s['id']);
            $hrefs = array_merge(seo_admin_html_links((string) $s['body']), seo_admin_json_links(json_field($s['items'])), seo_admin_json_links(json_field($s['settings'])));
            foreach ($hrefs as $href) {
                $record('page:' . $pid, $label, $where, $edit, $href);
            }
        }
    }
    // entity bodies (public entities only)
    $bodies = [
        ['post', "SELECT id, title, content FROM blog_posts WHERE status = 'published'", ['content'], 'Article'],
        ['story', "SELECT id, title, content FROM success_stories WHERE status = 'published' AND consent_confirmed = 1", ['content'], 'Story'],
        ['event', "SELECT " . seo_admin_select('events', ['id', 'title', 'description', 'agenda', 'guidelines', 'eligibility']) . " FROM events WHERE status IN ('published', 'cancelled')", ['description', 'agenda', 'guidelines', 'eligibility'], 'Event'],
        ['campaign', "SELECT id, title, description FROM campaigns WHERE status IN ('active', 'completed', 'closed')", ['description'], 'Campaign'],
        ['scheme', "SELECT " . seo_admin_select('schemes', ['id', 'title', 'eligibility', 'benefits', 'how_to_apply', 'documents_needed', 'official_url']) . " FROM schemes WHERE status = 'published'", ['eligibility', 'benefits', 'how_to_apply', 'documents_needed'], 'Scheme'],
    ];
    foreach ($bodies as [$type, $sql, $cols, $label]) {
        try {
            foreach (db_rows($sql) as $r) {
                foreach ($cols as $col) {
                    foreach (seo_admin_html_links((string) ($r[$col] ?? '')) as $href) {
                        $record($type . ':' . (int) $r['id'], $label . ': ' . $r['title'], humanize($col), seo_admin_module_url($type, (int) $r['id']), $href);
                    }
                }
                if ($type === 'scheme' && !empty($r['official_url'])) {
                    $record('scheme:' . (int) $r['id'], $label . ': ' . $r['title'], 'Official source', seo_admin_module_url('scheme', (int) $r['id']), (string) $r['official_url']);
                }
            }
        } catch (Throwable $e) {
            error_log('SEO link scan (' . $type . '): ' . $e->getMessage());
        }
    }
    // menus (visible items)
    try {
        foreach (db_rows("SELECT mi.id, mi.label, mi.url, m.menu_key, m.name FROM menu_items mi JOIN menus m ON m.id = mi.menu_id WHERE mi.status = 'visible' ORDER BY m.id, mi.sort_order") as $mi) {
            $record('menu:' . $mi['menu_key'], 'Menu: ' . $mi['name'], 'Item "' . $mi['label'] . '"',
                admin_url('menus.php?menu=' . rawurlencode((string) $mi['menu_key']) . '&edit=' . (int) $mi['id'] . '#item-form'), (string) $mi['url']);
        }
    } catch (Throwable $e) {
        error_log('SEO link scan (menus): ' . $e->getMessage());
    }
    // site-wide links in settings
    $record('setting:header', 'Header button', 'Settings > Header & footer', admin_url('settings.php?tab=header'), setting('header_cta_url'));
    if (setting('announcement_enabled') === '1') {
        $record('setting:announcement', 'Announcement bar', 'Widgets & alerts', admin_url('widgets.php'), setting('announcement_link_url'));
    }
    return $scan;
}

/** Item keys of indexable URLs that no stored link or automatic list points to (home excluded). */
function seo_admin_orphan_keys(array $items, array $scan): array
{
    $maps = seo_admin_maps();
    $pub = function (string $slug) use ($maps) {
        return isset($maps['pages'][$slug]) && $maps['pages'][$slug]['status'] === 'published';
    };
    $implicit = [];
    foreach ($items as $k => $it) {
        switch ($it['type']) {
            case 'post':
                $implicit[$k] = $pub('blog') || $pub('media');
                break;
            case 'story':
                $implicit[$k] = $pub('success-stories');
                break;
            case 'event':
                $implicit[$k] = !empty($it['event']['awareness']) ? ($pub('awareness-calendar') || $pub('events')) : $pub('events');
                break;
            case 'campaign':
                $implicit[$k] = $pub('campaigns');
                break;
            case 'scheme':
                $implicit[$k] = $pub('schemes');
                break;
        }
    }
    // programme and category pages are listed automatically on /programs/ (and the programmes menu)
    try {
        foreach (db_rows("SELECT page_id FROM programs WHERE status = 'published' AND page_id IS NOT NULL") as $r) {
            $implicit['page:' . (int) $r['page_id']] = $pub('programs') || $pub('home');
        }
    } catch (Throwable $e) {
        // table missing
    }
    try {
        foreach (db_rows("SELECT page_id FROM program_categories WHERE status = 'published' AND page_id IS NOT NULL") as $r) {
            $implicit['page:' . (int) $r['page_id']] = true;
        }
    } catch (Throwable $e) {
        // W2 table missing
    }
    $orphans = [];
    foreach ($items as $k => $it) {
        if (!$it['indexable'] || $it['path'] === '/' || !empty($implicit[$k]) || !empty($scan['inbound'][$k])) {
            continue;
        }
        $orphans[$k] = true;
    }
    return $orphans;
}

/** Orphan items (indexable URLs that nothing links to except the sitemaps). */
function seo_orphans(): array
{
    $ctx = seo_admin_context();
    return array_intersect_key($ctx['items'], $ctx['orphans']);
}

/** Stored external check results keyed by URL. */
function seo_admin_external_results(): array
{
    if (!seo_admin_table_ready('seo_link_checks')) {
        return [];
    }
    $out = [];
    try {
        foreach (db_rows('SELECT url, status_code, final_url, error, checked_at FROM seo_link_checks WHERE is_internal = 0 AND checked_at IS NOT NULL') as $r) {
            $out[$r['url']] = $r;
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

function seo_admin_external_is_error(array $r): bool
{
    $code = (int) ($r['status_code'] ?? 0);
    if ($r['error'] !== null && $r['error'] !== '' && strpos((string) $r['error'], 'not checked') === 0) {
        return false;
    }
    return $code === 0 ? ($r['error'] ?? '') !== '' : $code >= 400 && !in_array($code, [401, 403, 429], true);
}

/** Is a host allowed for the external check (public address)? Returns an error text or null; $ip gets the address to pin. */
function seo_admin_url_allowed(string $url, ?string &$ip = null): ?string
{
    $ip = null;
    $p = parse_url($url);
    if (!$p || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true) || empty($p['host'])) {
        return 'not checked: not an http(s) address';
    }
    if (isset($p['user']) || isset($p['pass'])) {
        return 'not checked: address contains a user name';
    }
    if (defined('SEO_LINK_CHECK_ALLOW_PRIVATE') && SEO_LINK_CHECK_ALLOW_PRIVATE) {
        return null;   // local testing only (pwf-config.php)
    }
    $host = strtolower(trim((string) $p['host'], '[]'));
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
    if (!$ips) {
        return 'host name not found';
    }
    foreach ($ips as $a) {
        if (!filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return 'not checked: private or local network address';
        }
    }
    $ip = $ips[0];
    return null;
}

/** One HTTP request without following redirects: [status, Location, error]. */
function seo_admin_http_once(string $url, ?string $ip, bool $head): array
{
    $ch = curl_init($url);
    $location = '';
    $bytes = 0;
    $p = parse_url($url);
    $port = (int) ($p['port'] ?? (strtolower((string) $p['scheme']) === 'https' ? 443 : 80));
    $opts = [
        CURLOPT_NOBODY => $head, CURLOPT_HEADER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT => SEO_ADMIN_EXT_TIMEOUT, CURLOPT_CONNECTTIMEOUT => SEO_ADMIN_EXT_TIMEOUT,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PWF-LinkCheck/1.0; +' . rtrim(BASE_URL, '/') . '/)',
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$location) {
            if (preg_match('/^Location:\s*(.+)$/i', trim($line), $m)) {
                $location = trim($m[1]);
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($c, $data) use (&$bytes) {
            $bytes += strlen($data);
            return $bytes > 65536 ? 0 : strlen($data);   // the status is known: stop downloading
        },
    ];
    if ($ip) {
        $opts[CURLOPT_RESOLVE] = [strtolower((string) $p['host']) . ':' . $port . ':' . $ip];
    }
    if (!$head) {
        $opts[CURLOPT_HTTPGET] = true;
        $opts[CURLOPT_RANGE] = '0-4095';
    }
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    $error = $errno && !($errno === 23 && $code > 0) ? ($errno === 28 ? 'timed out after ' . SEO_ADMIN_EXT_TIMEOUT . ' s' : mb_substr(curl_error($ch), 0, 200)) : null;
    curl_close($ch);
    return [$code, $location, $error];
}

/**
 * Check one external URL: HEAD, falling back to GET; redirects followed by hand (max 5), every hop re-validated
 * (public addresses only, pinned IP). Returns ['status' => int|null, 'final' => url, 'error' => string|null, 'hops' => n].
 */
function seo_admin_http_check(string $url): array
{
    if (!function_exists('curl_init')) {
        return ['status' => null, 'final' => $url, 'error' => 'not checked: cURL is not available on this server', 'hops' => 0];
    }
    $current = $url;
    $hops = 0;
    while (true) {
        $ip = null;
        $err = seo_admin_url_allowed($current, $ip);
        if ($err !== null) {
            return ['status' => null, 'final' => $current, 'error' => $err, 'hops' => $hops];
        }
        [$code, $loc, $error] = seo_admin_http_once($current, $ip, true);
        if (($error === null && in_array($code, [400, 403, 404, 405, 500, 501], true)) || ($error !== null && strpos($error, 'timed out') === false)) {
            [$code2, $loc2, $error2] = seo_admin_http_once($current, $ip, false);
            if ($error2 === null || $code2 > 0) {
                [$code, $loc, $error] = [$code2, $loc2, $error2];
            }
        }
        if ($error === null && $code >= 300 && $code < 400 && $loc !== '') {
            $hops++;
            if ($hops > 5) {
                return ['status' => $code, 'final' => $current, 'error' => 'more than 5 redirects', 'hops' => $hops];
            }
            $current = seo_admin_join_url($current, $loc);
            continue;
        }
        return ['status' => $code > 0 ? $code : null, 'final' => $current, 'error' => $error, 'hops' => $hops];
    }
}

/** Resolve a Location header against the current URL. */
function seo_admin_join_url(string $base, string $loc): string
{
    if (preg_match('#^https?://#i', $loc)) {
        return $loc;
    }
    $p = parse_url($base);
    $origin = strtolower((string) $p['scheme']) . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (substr($loc, 0, 2) === '//') {
        return strtolower((string) $p['scheme']) . ':' . $loc;
    }
    if ($loc !== '' && $loc[0] === '/') {
        return $origin . $loc;
    }
    $dir = preg_replace('#/[^/]*$#', '/', (string) ($p['path'] ?? '/'));
    return $origin . $dir . $loc;
}

/**
 * Manual external link check: registers the external links found in content in seo_link_checks and checks up to
 * $batch of them (never checked first, then the oldest), within SEO_ADMIN_EXT_BUDGET seconds.
 * Returns ['checked' => n, 'remaining' => n never checked, 'total' => n, 'error' => string|null].
 */
function seo_check_external_links(int $batch = SEO_ADMIN_EXT_BATCH): array
{
    if (!seo_admin_table_ready('seo_link_checks')) {
        return ['checked' => 0, 'remaining' => 0, 'total' => 0, 'error' => 'The link check table is not installed yet (database update 831).'];
    }
    $batch = max(1, min(50, $batch));
    $scan = seo_scan_internal_links();
    $hashes = [];
    foreach ($scan['external'] as $url => $sources) {
        $hash = sha1($url);
        $hashes[] = $hash;
        $found = (string) json_encode(array_map(function ($k) {
            return explode(':', $k, 2);
        }, array_keys($sources)), JSON_UNESCAPED_SLASHES);
        db_query('INSERT INTO seo_link_checks (url, url_hash, is_internal, found_on) VALUES (?, ?, 0, ?) ON DUPLICATE KEY UPDATE found_on = ?',
            [mb_substr($url, 0, 500), $hash, $found, $found]);
    }
    if (!$hashes) {
        return ['checked' => 0, 'remaining' => 0, 'total' => 0, 'error' => null];
    }
    $in = implode(',', array_fill(0, count($hashes), '?'));
    $rows = db_rows("SELECT id, url FROM seo_link_checks WHERE is_internal = 0 AND url_hash IN ($in) ORDER BY checked_at IS NOT NULL, checked_at, id LIMIT " . $batch, $hashes);
    @set_time_limit(SEO_ADMIN_EXT_BUDGET + 30);
    $startedAt = microtime(true);
    $checked = 0;
    foreach ($rows as $r) {
        if (microtime(true) - $startedAt > SEO_ADMIN_EXT_BUDGET) {
            break;
        }
        $res = seo_admin_http_check((string) $r['url']);
        db_update('seo_link_checks', [
            'status_code' => $res['status'], 'final_url' => $res['final'] !== $r['url'] ? mb_substr((string) $res['final'], 0, 500) : null,
            'error' => $res['error'] !== null ? mb_substr((string) $res['error'], 0, 255) : null, 'checked_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => (int) $r['id']]);
        $checked++;
    }
    $remaining = (int) db_value("SELECT COUNT(*) FROM seo_link_checks WHERE is_internal = 0 AND checked_at IS NULL AND url_hash IN ($in)", $hashes);
    return ['checked' => $checked, 'remaining' => $remaining, 'total' => count($hashes), 'error' => null];
}

/**
 * Redirects report: every row of the redirects table with its chain (hops), final target and status, loops, and
 * redirects that hide a live page or entity (index.php answers redirects before routing).
 */
function seo_admin_redirect_report(): array
{
    $maps = seo_admin_maps();
    $out = [];
    foreach (db_rows('SELECT id, source_path, target_url, status_code, hits, created_at FROM redirects ORDER BY id DESC') as $r) {
        $src = strtolower(trim((string) $r['source_path'], '/'));
        $res = seo_admin_resolve_path($src);
        $own = seo_admin_route_status($src);
        $out[] = $r + [
            'hops' => $res['hops'], 'final' => $res['final'], 'final_status' => $res['final_status'], 'loop' => $res['loop'],
            'chain' => $res['chain'] ?? [], 'shadows' => in_array($own['status'], ['ok', 'draft'], true) && $own['entity'] ? $own['entity'] : null,
            'problem' => $res['loop'] ? 'loop' : ($res['hops'] > 1 ? 'chain' : (in_array($res['final_status'], ['missing', 'draft'], true) ? 'target_' . $res['final_status'] : '')),
        ];
    }
    return $out;
}

// =====================================================================
// Awareness days: editions and the canonical edition (CRITIC P1-2, G-A2)
// =====================================================================

/**
 * Staff overrides: [group key => edition slug]. Same storage as the SEO core (seo.php: seo_awareness_pins(),
 * setting seo_awareness_pins, JSON), so the canonical tags and the sitemap follow the choice made here.
 */
function seo_admin_awareness_overrides(): array
{
    if (function_exists('seo_awareness_pins')) {
        return seo_awareness_pins();
    }
    $data = json_decode(setting('seo_awareness_pins'), true);
    return is_array($data) ? array_filter($data, 'is_string') : [];
}

/** Is the cross-edition canonical switched on (setting seo_awareness_cross_canonical, default on)? */
function seo_admin_awareness_enabled(): bool
{
    return function_exists('seo_awareness_enabled') ? seo_awareness_enabled() : setting('seo_awareness_cross_canonical', '1') === '1';
}

/** Group key of an awareness-day slug (the slug without its year). */
function seo_admin_awareness_key(string $slug): string
{
    return (string) preg_replace('/-(19|20)\d{2}$/', '', strtolower($slug));
}

/**
 * Awareness-day groups (cached): key => ['key', 'title', 'editions' => [...], 'auto_id', 'override_id', 'effective_id',
 * 'same_description' => bool]. Automatic rule: the next upcoming published edition, else the most recent one.
 */
function seo_admin_awareness_groups(bool $refresh = false): array
{
    static $groups = null;
    if ($groups !== null && !$refresh) {
        return $groups;
    }
    $groups = [];
    try {
        $rows = db_rows("SELECT " . seo_admin_select('events', ['id', 'slug', 'title', 'start_datetime', 'end_datetime', 'status', 'meta_description', 'summary', 'event_type'])
            . " FROM events WHERE event_type = 'awareness_day' ORDER BY start_datetime");
    } catch (Throwable $e) {
        return $groups;
    }
    $overrides = seo_admin_awareness_overrides();
    $now = time();
    foreach ($rows as $r) {
        $key = seo_admin_awareness_key((string) $r['slug']);
        if (!isset($groups[$key])) {
            $groups[$key] = ['key' => $key, 'title' => trim((string) preg_replace('/\s*(19|20)\d{2}\s*$/', '', (string) $r['title'])), 'editions' => []];
        }
        $r['url'] = seo_admin_abs(event_url($r + ['event_type' => 'awareness_day']));
        $r['upcoming'] = strtotime((string) ($r['end_datetime'] ?: $r['start_datetime'])) >= $now;
        $groups[$key]['editions'][] = $r;
    }
    foreach ($groups as $key => $g) {
        $auto = null;
        $published = array_values(array_filter($g['editions'], function ($e) {
            return $e['status'] === 'published';
        }));
        foreach ($published as $e) {
            if ($e['upcoming']) {
                $auto = (int) $e['id'];
                break;
            }
        }
        if ($auto === null && $published) {
            $auto = (int) end($published)['id'];
        }
        $ov = null;
        $pinSlug = $overrides[$key] ?? '';
        foreach ($published as $e) {
            if ($pinSlug !== '' && $e['slug'] === $pinSlug) {
                $ov = (int) $e['id'];
            }
        }
        $valid = $ov !== null;
        $descs = array_unique(array_map(function ($e) {
            return seo_admin_norm((string) ($e['meta_description'] ?: $e['summary']));
        }, $g['editions']));
        $groups[$key]['auto_id'] = $auto;
        $groups[$key]['override_id'] = $valid ? $ov : null;
        $groups[$key]['stale_override'] = $pinSlug !== '' && !$valid;
        $groups[$key]['effective_id'] = count($g['editions']) > 1 && seo_admin_awareness_enabled() ? ($valid ? $ov : $auto) : null;
        $groups[$key]['same_description'] = count($g['editions']) > 1 && count($descs) === 1;
    }
    return $groups;
}

/** Save (or clear with 0) the canonical-edition override of a group. Returns [ok, message]. */
function seo_admin_awareness_save(string $key, int $eventId): array
{
    $groups = seo_admin_awareness_groups();
    if (!isset($groups[$key])) {
        return [false, 'Unknown observance.'];
    }
    $g = $groups[$key];
    $ids = array_map('intval', array_column(array_filter($g['editions'], function ($e) {
        return $e['status'] === 'published';
    }), 'id'));
    if ($eventId && !in_array($eventId, $ids, true)) {
        return [false, 'Choose a published edition of this observance.'];
    }
    $slugOf = function (int $id) use ($g): string {
        foreach ($g['editions'] as $e) {
            if ((int) $e['id'] === $id) {
                return (string) $e['slug'];
            }
        }
        return '';
    };
    $newSlug = $eventId ? $slugOf($eventId) : '';
    $all = seo_admin_awareness_overrides();
    $oldSlug = (string) ($all[$key] ?? '');
    if ($oldSlug === $newSlug) {
        return [true, 'Nothing changed.'];
    }
    if (function_exists('seo_awareness_pin')) {
        if (!seo_awareness_pin($key, $newSlug !== '' ? $newSlug : null)) {
            return [false, 'Choose a published edition of this observance.'];
        }
    } else {
        if ($newSlug !== '') {
            $all[$key] = $newSlug;
        } else {
            unset($all[$key]);
        }
        save_setting('seo_awareness_pins', $all ? (string) json_encode($all, JSON_UNESCAPED_SLASHES) : '');
    }
    $slug = function (string $s): string {
        return $s !== '' ? $s : 'automatic';
    };
    seo_log_changes('awareness_group', min(array_map('intval', array_column($g['editions'], 'id'))), ['canonical_edition' => [$slug($oldSlug), $slug($newSlug)]]);
    seo_admin_awareness_groups(true);
    return [true, $newSlug !== '' ? 'Main edition set to ' . $newSlug . '.' : 'This observance uses the automatic rule again.'];
}

// =====================================================================
// Sitemap / robots previews, trust checklist
// =====================================================================

/**
 * Child sitemaps from the SEO sitemap module (WP-2) when installed: [['name', 'url', 'count', 'lastmod', 'images', 'entries']].
 * [] when the module is missing (the single current sitemap.xml is used then).
 */
function seo_admin_sitemap_children(): array
{
    static $children = null;
    if ($children !== null) {
        return $children;
    }
    $children = [];
    if (!function_exists('seo_sitemap_sets') || !function_exists('seo_sitemap_entries')) {
        return $children;
    }
    try {
        foreach ((array) seo_sitemap_sets() as $k => $v) {
            $name = is_string($v) ? $v : (is_string($k) ? $k : (is_array($v) ? (string) ($v['name'] ?? '') : ''));
            if ($name === '' || !preg_match('/^[a-z0-9\-]+$/', $name)) {
                continue;
            }
            if (is_array($v) && isset($v['entries']) && is_array($v['entries'])) {
                $entries = $v['entries'];
            } elseif (is_array($v) && isset($v[0]) && is_array($v[0]) && isset($v[0]['loc'])) {
                $entries = $v;
            } else {
                $entries = (array) seo_sitemap_entries($name);
            }
            $entries = array_values(array_filter($entries, function ($e) {
                return is_array($e) && !empty($e['loc']);
            }));
            $last = 0;
            foreach ($entries as $e) {
                $lm = $e['lastmod'] ?? null;
                $ts = is_numeric($lm) ? (int) $lm : (is_string($lm) && $lm !== '' ? (int) strtotime($lm) : 0);
                $last = max($last, $ts);
            }
            $last = $last ? date('Y-m-d H:i:s', $last) : '';
            $children[] = ['name' => $name, 'url' => abs_url('sitemap-' . $name . '.xml'), 'count' => count($entries), 'lastmod' => $last,
                'images' => strpos($name, 'image') !== false, 'entries' => $entries];
        }
    } catch (Throwable $e) {
        error_log('SEO sitemap preview: ' . $e->getMessage());
        $children = [];
    }
    return $children;
}

/** robots.txt as the SEO sitemap module builds it, or null when the module is not installed. */
function seo_admin_robots_preview(): ?string
{
    if (!function_exists('seo_robots_txt')) {
        return null;
    }
    try {
        return (string) seo_robots_txt();
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Owner trust checklist - data presence only, no score: [['label', 'ok' (bool), 'detail', 'url', 'link_label'], ...].
 */
function seo_admin_trust_checklist(): array
{
    $count = function (string $sql, array $params = []): int {
        try {
            return (int) db_value($sql, $params);
        } catch (Throwable $e) {
            return 0;
        }
    };
    $mgmt = $count("SELECT COUNT(*) FROM team_members WHERE status = 'published' AND member_group = 'management'");
    $team = $count("SELECT COUNT(*) FROM team_members WHERE status = 'published' AND member_group <> 'management'");
    $docs = $count("SELECT COUNT(*) FROM resources WHERE status = 'published'");
    $certs = $count("SELECT COUNT(*) FROM resources WHERE status = 'published' AND category = 'certification'");
    $social = count(social_links());
    $regs = [];
    foreach (['legal_name' => 'legal name', 'cin_number' => 'CIN', 'ngo_id' => 'NGO ID', 'registration_number' => 'registration number', 'pan_number' => 'PAN', 'msme_udyam_number' => 'Udyam'] as $k => $l) {
        if (setting($k) !== '') {
            $regs[] = $l;
        }
    }
    $settingsUrl = function (string $tab) {
        return admin_url('settings.php?tab=' . $tab);
    };
    $pagePublished = function (string $slug) use ($count): bool {
        return $count("SELECT COUNT(*) FROM pages WHERE slug = ? AND status = 'published'", [$slug]) > 0;
    };
    $bing = setting('bing_site_verification');
    $gtm = setting('gtm_container_id');
    return [
        ['Management body members published', $mgmt > 0, $mgmt ? $mgmt . ' published' : 'None yet - add real people only, with their consent for photos.', admin_url('team.php'), 'Team'],
        ['Team members published', $team > 0, $team ? $team . ' published' : 'None yet.', admin_url('team.php'), 'Team'],
        ['Public phone number', setting('contact_phone') !== '', setting('contact_phone') !== '' ? 'Set' : 'Not set', $settingsUrl('contact'), 'Settings > Contact'],
        ['Social profiles', $social > 0, $social ? $social . ' linked' : 'No official profile linked', $settingsUrl('social'), 'Settings > Social links'],
        ['Office address (registered office)', setting('contact_address') !== '', setting('contact_address') !== '' ? 'Set - check it matches the registered office in MCA records' : 'Not set', $settingsUrl('contact'), 'Settings > Contact'],
        ['Corporate office address', setting('corporate_office_address') !== '', setting('corporate_office_address') !== '' ? 'Set' : 'Not set', admin_url('letters.php'), 'Letters settings'],
        ['Contact and NGO details pages published', $pagePublished('contact') && $pagePublished('ngo-details'), ($pagePublished('contact') ? '/contact/ published' : '/contact/ not published') . ', ' . ($pagePublished('ngo-details') ? '/ngo-details/ published' : '/ngo-details/ not published'), admin_url('pages.php'), 'Pages'],
        ['Registration facts', count($regs) > 0, $regs ? 'Filled: ' . implode(', ', $regs) : 'None filled in', $settingsUrl('ngo'), 'Settings > NGO registration'],
        ['Documents published', $docs > 0, $docs ? $docs . ' published' : 'None yet', admin_url('resources.php'), 'Documents'],
        ['Certificates published', $certs > 0, $certs ? $certs . ' published' : 'None yet', admin_url('resources.php'), 'Documents'],
        ['Google Search Console verification', setting('google_site_verification') !== '', setting('google_site_verification') !== '' ? 'Meta tag code set' : 'No meta tag code (fine if the domain was verified through DNS)', admin_url('seo.php?tab=integrations'), 'Integrations'],
        ['Bing Webmaster Tools verification', $bing !== '', $bing !== '' ? 'Code set' : 'No code (fine if the site was imported from Search Console)', admin_url('seo.php?tab=integrations'), 'Integrations'],
        ['Google Analytics 4 / Tag Manager', setting('ga_measurement_id') !== '' || $gtm !== '', trim((setting('ga_measurement_id') !== '' ? 'GA4 ID set. ' : '') . ($gtm !== '' ? 'GTM ID set.' : '')) ?: 'Not set (optional - first-party statistics work without it)', admin_url('seo.php?tab=integrations'), 'Integrations'],
        ['Logo uploaded', setting('logo_path') !== '', setting('logo_path') !== '' ? 'Uploaded' : 'The built-in logo is used', $settingsUrl('general'), 'Settings > General'],
        ['Default social sharing image', setting('seo_default_og_image') !== '', setting('seo_default_og_image') !== '' ? 'Uploaded' : 'The built-in card is used', admin_url('seo.php?tab=integrations'), 'Integrations'],
        ['Privacy policy published', $pagePublished('privacy-policy'), $pagePublished('privacy-policy') ? 'Published' : 'Not published', admin_url('pages.php'), 'Pages'],
    ];
}

// =====================================================================
// Integration settings (Search Console, Bing, GA4, GTM and SEO options of the other packages)
// =====================================================================

/**
 * Setting groups: group => [title, help, [key => [type, label, help, options]]]. A field is shown only when its
 * setting exists (created by the package that uses it) or it is a long-standing core setting.
 */
function seo_admin_setting_groups(): array
{
    return [
        'verification' => ['Search engine verification', 'Verify ownership once. A domain property verified through DNS (at the domain host) needs no code here.', [
            'google_site_verification' => ['gsv', 'Google Search Console verification code', 'Paste the content value of the HTML tag, or the whole <meta …> tag.'],
            'bing_site_verification' => ['code', 'Bing Webmaster Tools code (msvalidate.01)', 'Paste the content value of the msvalidate.01 tag, or the whole tag. Not needed when the site is imported from Google Search Console.', ['regex' => '/^[A-Za-z0-9]{16,64}$/']],
            'yandex_verification' => ['code', 'Yandex verification code (optional)', 'Only if you use Yandex Webmaster.', ['regex' => '/^[A-Za-z0-9]{8,64}$/']],
            'pinterest_verification' => ['code', 'Pinterest domain verification (optional)', 'Only if you claim the website on Pinterest.', ['regex' => '/^[A-Za-z0-9]{16,64}$/']],
        ]],
        'analytics' => ['Google Analytics & Tag Manager', 'Google tags load only after a visitor chooses "Allow analytics". Use either GA4 or Tag Manager, never both (that counts every visit twice).', [
            'analytics_tag_mode' => ['select', 'Tag mode', '', ['options' => ['gtag' => 'Google Analytics 4 (gtag.js)', 'gtm' => 'Google Tag Manager']]],
            'ga_measurement_id' => ['ga', 'Google Analytics 4 measurement ID', 'Format G-XXXXXXXXXX.'],
            'gtm_container_id' => ['gtm', 'Google Tag Manager container ID', 'Format GTM-XXXXXXX. Custom HTML tags and Custom JavaScript variables do not run under this website\'s security policy; GA4 and template-based tags work.'],
            'dl_include_donation_value' => ['checkbox', 'Send the amount of completed donations to Google Analytics'],
            'ga_respect_gpc' => ['checkbox', 'Treat the browser\'s Global Privacy Control signal as "No" to analytics'],
        ]],
        'search' => ['Search appearance', '', [
            'seo_title_max' => ['int', 'Title length limit (characters)', 'The site name is added after a title only when it fits.', ['min' => 40, 'max' => 80]],
            'seo_default_og_image' => ['image', 'Default social sharing image', '1200 × 630 px JPG or PNG.'],
            'seo_default_og_image_alt' => ['text', 'Description of the default social image', 'What the image shows, for people using screen readers.', ['max' => 255]],
            'seo_robots_extra' => ['robotsmeta', 'Extra robots values for indexable pages', 'Advanced. Default: max-snippet:-1, max-image-preview:large, max-video-preview:-1'],
            'seo_noindex_empty_lists' => ['checkbox', 'Hide list pages that have no public items yet from search engines (noindex, follow)'],
            'seo_faq_schema_scope' => ['select', 'FAQ structured data', 'Google asks for each question to be marked up once on the whole site.', ['options' => [
                'faq_page' => 'FAQ page and pages switched on in the SEO editor (recommended)', 'all' => 'Every page with questions (not recommended)', 'none' => 'Nowhere']]],
            'seo_awareness_cross_canonical' => ['checkbox', 'Awareness days: point the other editions of an observance to its main edition (canonical)'],
        ]],
        'eeat' => ['Authors & reviews', 'Shown only with real team members entered in Team; nothing is generated.', [
            'seo_author_box' => ['checkbox', 'Show an author box on articles and stories'],
            'seo_show_reviewed' => ['checkbox', 'Show "Reviewed by … on …" when a reviewer and date are set'],
        ]],
        'organisation' => ['Organisation facts for search engines', 'Enter only verified facts. Leave blank when unsure: nothing is shown for empty fields.', [
            'seo_org_founding_date' => ['date', 'Founding / incorporation date', 'As on the certificate of incorporation.'],
            'seo_org_area_served' => ['text', 'Area served', 'e.g. Delhi NCR. Only where the foundation actually works.', ['max' => 200]],
        ]],
        'crawling' => ['Crawling & sitemaps', '', [
            'seo_canonical_host_redirect' => ['hostredirect', 'Send visitors on other host names or http:// to the main address (301)', ''],
            'seo_sitemap_images' => ['checkbox', 'List page photos in the image sitemap'],
            'seo_sitemap_past_events' => ['checkbox', 'Keep past events in the sitemap'],
            'indexnow_enabled' => ['checkbox', 'Tell Bing (IndexNow) when content is published'],
            'robots_txt_extra' => ['robots', 'Extra robots.txt lines', 'Advanced. Lines such as "Disallow: /private-page/". Leave empty normally.'],
        ]],
        'images' => ['Images', '', [
            'seo_webp_enabled' => ['checkbox', 'Create smaller WebP copies of uploaded photos'],
            'seo_og_generate' => ['checkbox', 'Create 1200 × 630 social images from page photos'],
            'seo_og_title_cards' => ['checkbox', 'Create branded title cards for pages without a photo'],
        ]],
    ];
}

/** Long-standing settings that are always shown (they exist on every installation). */
const SEO_ADMIN_CORE_SETTINGS = ['google_site_verification', 'ga_measurement_id', 'seo_default_og_image', 'robots_txt_extra'];

/** Fields of a group that are installed (setting exists or core); labels and help from the tags package when present. */
function seo_admin_group_fields(array $fields): array
{
    $all = settings_all();
    $wp5 = function_exists('seo_tags_settings_schema') ? seo_tags_settings_schema() : [];
    $out = [];
    foreach ($fields as $k => $def) {
        if (!in_array($k, SEO_ADMIN_CORE_SETTINGS, true) && !array_key_exists($k, $all)) {
            continue;
        }
        if (isset($wp5[$k])) {
            $def[1] = (string) ($wp5[$k]['label'] ?? $def[1]);
            $def[2] = (string) ($wp5[$k]['help'] ?? ($def[2] ?? ''));
            if (($wp5[$k]['type'] ?? '') === 'select' && !empty($wp5[$k]['options'])) {
                $def[0] = 'select';
                $def[3] = ['options' => $wp5[$k]['options']];
            }
        }
        $out[$k] = $def;
    }
    return $out;
}

/** Does the request host and scheme match BASE_URL (so a host redirect cannot loop)? [match, detected text]. */
function seo_admin_host_check(): array
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $scheme = is_https() ? 'https' : 'http';
    $base = parse_url(BASE_URL);
    $want = strtolower((string) ($base['host'] ?? '')) . (isset($base['port']) ? ':' . $base['port'] : '');
    return [$host === $want && $scheme === strtolower((string) ($base['scheme'] ?? '')), $scheme . '://' . $host];
}

/** Validate and save posted settings of the given fields. Returns [changed keys, errors]. Logs old -> new values. */
function seo_admin_save_settings(array $fields, array $post): array
{
    $errors = $values = [];
    $std = [];
    $wp5 = function_exists('seo_tags_settings_schema') && function_exists('seo_tags_clean_setting') ? seo_tags_settings_schema() : [];
    foreach ($fields as $key => $def) {
        $type = $def[0];
        $raw = is_scalar($post[$key] ?? null) ? clean_string((string) $post[$key], $type === 'robots') : '';
        if (isset($wp5[$key])) {
            // the tags package validates its own settings (SETTINGS.md contract)
            [$clean, $err] = seo_tags_clean_setting($key, $raw);
            if ($clean === null) {
                $errors[$key] = (string) $err;
            } else {
                $values[$key] = (string) $clean;
            }
            continue;
        }
        switch ($type) {
            case 'code':
                if (preg_match('/content\s*=\s*["\']([^"\']*)["\']/i', $raw, $m)) {
                    $raw = trim($m[1]);
                }
                if ($raw !== '' && !preg_match((string) ($def[3]['regex'] ?? '/^[A-Za-z0-9]{8,64}$/'), $raw)) {
                    $errors[$key] = $def[1] . ': paste the code exactly as shown by the service (letters and numbers only).';
                } else {
                    $values[$key] = $raw;
                }
                break;
            case 'gtm':
                $raw = strtoupper(trim($raw));
                if ($raw !== '' && !preg_match('/^GTM-[A-Z0-9]{4,12}$/', $raw)) {
                    $errors[$key] = $def[1] . ': a container ID looks like GTM-ABC1234.';
                } else {
                    $values[$key] = $raw;
                }
                break;
            case 'robotsmeta':
                $tokens = array_values(array_filter(array_map('trim', explode(',', strtolower($raw))), 'strlen'));
                foreach ($tokens as $t) {
                    if (!preg_match('/^(max-snippet:-?\d{1,4}|max-image-preview:(none|standard|large)|max-video-preview:-?\d{1,4}|notranslate|noimageindex)$/', $t)) {
                        $errors[$key] = $def[1] . ': "' . $t . '" is not allowed. Use values such as max-snippet:-1, max-image-preview:large.';
                    }
                }
                if (!isset($errors[$key])) {
                    $values[$key] = implode(', ', $tokens);
                }
                break;
            case 'hostredirect':
                $v = $raw === '1' ? '1' : '0';
                [$match, $detected] = seo_admin_host_check();
                if ($v === '1' && !$match && setting($key) !== '1') {
                    $errors[$key] = 'The redirect can only be switched on while you use the main address (' . rtrim(BASE_URL, '/') . '); this page was opened as ' . $detected . '.';
                } else {
                    $values[$key] = $v;
                }
                break;
            default:
                $std[$key] = $def;
        }
    }
    foreach ($std as $key => $def) {
        $parseDef = $def;
        if ($def[0] === 'date') {
            $parseDef = ['date', $def[1], $def[2] ?? '', $def[3] ?? []];
        }
        [$v, $err] = site_setting_parse($key, $parseDef, $post, 'branding');
        if ($err) {
            $errors[$key] = $err;
        } else {
            $values[$key] = (string) $v;
        }
    }
    if (isset($values['seo_org_founding_date']) && $values['seo_org_founding_date'] > date('Y-m-d')) {
        $errors['seo_org_founding_date'] = 'The founding date cannot be in the future.';
    }
    if ($errors) {
        return [[], $errors];
    }
    $changed = [];
    foreach ($values as $key => $v) {
        $old = setting($key);
        if ($old !== $v || !array_key_exists($key, settings_all())) {
            save_setting($key, $v);
            $changed[$key] = [$old, $v];
        }
    }
    if ($changed) {
        seo_log_changes('setting', 0, $changed);
    }
    return [array_keys($changed), []];
}

// =====================================================================
// Images used in content (alt text from the media library)
// =====================================================================

/** Public raster images used on the site: path => ['path', 'media' (row|null), 'alt', 'uses' => [label => admin url], 'webp' => bool]. */
function seo_admin_images(): array
{
    $uses = [];
    $add = function (?string $path, string $label, string $url) use (&$uses) {
        $path = trim((string) $path);
        if ($path === '' || strpos($path, 'uploads/') !== 0 || preg_match('/\.svg$/i', $path) || strpos($path, '..') !== false
            || preg_match('#^uploads/(documents|certificates)/#', $path)) {
            return;
        }
        $uses[$path][$label] = $url;
    };
    $q = function (string $sql) {
        try {
            return db_rows($sql);
        } catch (Throwable $e) {
            return [];
        }
    };
    foreach ($q("SELECT id, title, banner_image, og_image FROM pages WHERE status = 'published'") as $p) {
        $add($p['banner_image'], 'Page: ' . $p['title'], admin_url('page-edit.php?id=' . (int) $p['id']));
        $add($p['og_image'], 'Page (social image): ' . $p['title'], admin_url('page-edit.php?id=' . (int) $p['id']));
    }
    foreach (seo_admin_page_sections() as $pid => $list) {
        foreach ($list as $s) {
            $edit = admin_url('page-edit.php?id=' . (int) $pid . '&tab=sections&open=' . (int) $s['id'] . '#section-' . (int) $s['id']);
            $add($s['image'], 'Section #' . (int) $s['id'] . ($s['title'] !== '' ? ' "' . seo_admin_short((string) $s['title'], 40) . '"' : ''), $edit);
            if (preg_match_all('/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)/i', (string) $s['body'], $m)) {
                foreach ($m[1] as $src) {
                    $add(seo_admin_site_path($src), 'Section #' . (int) $s['id'] . ' text', $edit);
                }
            }
        }
    }
    $entity = [
        ['post', "SELECT id, title, image FROM blog_posts WHERE status = 'published'", 'Article'],
        ['story', "SELECT id, title, image FROM success_stories WHERE status = 'published' AND consent_confirmed = 1", 'Story'],
        ['event', "SELECT id, title, image FROM events WHERE status IN ('published', 'cancelled')", 'Event'],
        ['campaign', "SELECT id, title, image FROM campaigns WHERE status IN ('active', 'completed', 'closed')", 'Campaign'],
        ['scheme', "SELECT id, title, image FROM schemes WHERE status = 'published'", 'Scheme'],
    ];
    foreach ($entity as [$type, $sql, $label]) {
        foreach ($q($sql) as $r) {
            $add($r['image'], $label . ': ' . $r['title'], seo_admin_module_url($type, (int) $r['id']));
        }
    }
    foreach ($q("SELECT id, title, cover_image FROM programs WHERE status = 'published'") as $r) {
        $add($r['cover_image'], 'Programme: ' . $r['title'], admin_url('program-edit.php?id=' . (int) $r['id']));
    }
    foreach ($q("SELECT id, title, image_path FROM gallery_items WHERE status = 'published' AND media_type = 'image' AND consent_confirmed = 1") as $r) {
        $add($r['image_path'], 'Gallery: ' . $r['title'], admin_url('gallery.php'));
    }
    $media = [];
    if ($uses) {
        $paths = array_keys($uses);
        foreach (array_chunk($paths, 200) as $chunk) {
            foreach ($q('SELECT id, path, alt_text, width, height FROM media WHERE path IN (' . implode(',', array_map(function ($p) {
                return db()->quote($p);
            }, $chunk)) . ')') as $m) {
                $media[$m['path']] = $m;
            }
        }
    }
    $out = [];
    foreach ($uses as $path => $u) {
        $m = $media[$path] ?? null;
        $out[$path] = ['path' => $path, 'media' => $m, 'alt' => trim((string) ($m['alt_text'] ?? '')), 'uses' => $u,
            'exists' => is_file(ROOT_PATH . '/' . $path), 'webp' => is_file(ROOT_PATH . '/' . preg_replace('/\.(jpe?g|png)$/i', '.webp', $path))];
    }
    uasort($out, function ($a, $b) {
        return [$a['alt'] !== '', $a['path']] <=> [$b['alt'] !== '', $b['path']];
    });
    return $out;
}

/** Save the media-library description of an image (adds the media row when the file is not registered). [ok, message]. */
function seo_admin_save_alt(string $path, string $alt): array
{
    $path = trim($path);
    $alt = mb_substr(trim((string) preg_replace('/\s+/u', ' ', clean_string($alt))), 0, 255);
    if (strpos($path, 'uploads/') !== 0 || preg_match('/\.svg$/i', $path) || !site_valid_image_path($path)) {
        return [false, 'Image not found.'];
    }
    $m = db_row('SELECT id, alt_text FROM media WHERE path = ?', [$path]);
    if ($m) {
        if (trim((string) $m['alt_text']) === $alt) {
            return [true, 'Nothing changed.'];
        }
        db_update('media', ['alt_text' => $alt !== '' ? $alt : null], 'id = :id', ['id' => (int) $m['id']]);
        seo_log_changes('media', (int) $m['id'], ['alt_text' => [(string) $m['alt_text'], $alt]]);
        return [true, 'Description saved.'];
    }
    $full = ROOT_PATH . '/' . $path;
    $info = @getimagesize($full);
    $id = db_insert('media', ['path' => $path, 'original_name' => mb_substr(basename($path), 0, 200), 'mime_type' => $info['mime'] ?? null,
        'file_size' => is_file($full) ? filesize($full) : null, 'width' => $info[0] ?? null, 'height' => $info[1] ?? null,
        'alt_text' => $alt !== '' ? $alt : null, 'uploaded_by' => seo_admin_user_id()]);
    seo_log_changes('media', $id, ['alt_text' => [null, $alt]]);
    return [true, 'Description saved (the image was added to the media library).'];
}

// =====================================================================
// seo_meta housekeeping
// =====================================================================

/** seo_meta rows whose page / entity no longer exists: [['id', 'entity_type', 'entity_id']]. */
function seo_admin_orphan_meta(): array
{
    if (!seo_admin_table_ready('seo_meta')) {
        return [];
    }
    $out = [];
    foreach (SEO_ADMIN_TYPES as $type => $label) {
        $table = seo_admin_entity_table($type);
        if (!seo_admin_table_ready($table)) {
            continue;
        }
        try {
            foreach (db_rows("SELECT m.id, m.entity_type, m.entity_id FROM seo_meta m LEFT JOIN `$table` t ON t.id = m.entity_id WHERE m.entity_type = ? AND t.id IS NULL", [$type]) as $r) {
                $out[] = $r;
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    return $out;
}

/** Delete seo_meta rows of deleted pages / entities. Returns the number removed. */
function seo_admin_prune_meta(): int
{
    $n = 0;
    foreach (seo_admin_orphan_meta() as $r) {
        $n += db_query('DELETE FROM seo_meta WHERE id = ?', [(int) $r['id']])->rowCount();
    }
    seo_admin_all_meta(true);
    return $n;
}

// =====================================================================
// CSV export / import
// =====================================================================

/** CSV header (type, id and url identify the row; title is for reference only). */
function seo_admin_csv_header(): array
{
    return array_merge(['type', 'id', 'url', 'title'], SEO_ADMIN_CSV_FIELDS);
}

/** Stored value -> CSV text (tri-state fields as words). */
function seo_admin_csv_out(string $field, string $v): string
{
    if ($v === '') {
        return '';
    }
    $words = ['robots_index' => ['1' => 'index', '0' => 'noindex'], 'robots_follow' => ['1' => 'follow', '0' => 'nofollow'],
        'sitemap_include' => ['1' => 'include', '0' => 'exclude']];
    return $words[$field][$v] ?? $v;
}

/** Rows for the CSV export (stored values, not fallbacks, so an unchanged re-import changes nothing). */
function seo_admin_csv_rows(): array
{
    $rows = [];
    foreach (seo_admin_items() as $it) {
        $row = [$it['type'], $it['id'], $it['url'], $it['title']];
        foreach (SEO_ADMIN_CSV_FIELDS as $f) {
            $row[] = array_key_exists($f, $it['values']) ? seo_admin_csv_out($f, (string) $it['values'][$f]) : '';
        }
        $rows[] = $row;
    }
    return $rows;
}

/** Undo the spreadsheet formula guard of send_csv() (a leading apostrophe before = + - @). */
function seo_admin_csv_unescape(string $v): string
{
    return preg_match("/^'[=+\-@]/", $v) ? substr($v, 1) : $v;
}

/**
 * Read an uploaded CSV and work out the changes. Default mode fills only empty fields; $overwrite replaces values.
 * Empty cells never clear a value. Returns ['changes' => [...], 'skipped' => [...], 'rows' => n, 'error' => string|null,
 * 'ignored_columns' => [...]].
 */
function seo_admin_csv_preview(string $file, bool $overwrite, bool $isAdmin): array
{
    $out = ['changes' => [], 'skipped' => [], 'rows' => 0, 'error' => null, 'ignored_columns' => [], 'mode' => $overwrite ? 'overwrite' : 'fill'];
    $fh = @fopen($file, 'rb');
    if (!$fh) {
        $out['error'] = 'The file could not be read.';
        return $out;
    }
    $header = fgetcsv($fh);
    if (!$header) {
        fclose($fh);
        $out['error'] = 'The file is empty.';
        return $out;
    }
    $header[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
    $cols = array_map(function ($h) {
        return strtolower(trim((string) $h));
    }, $header);
    $idx = [];
    foreach ($cols as $i => $c) {
        if (!isset($idx[$c])) {
            $idx[$c] = $i;
        }
    }
    if (!(isset($idx['type'], $idx['id']) || isset($idx['url']))) {
        fclose($fh);
        $out['error'] = 'The first row must name the columns, including "type" and "id" (or "url"). Download the template or an export to see the format.';
        return $out;
    }
    $editable = array_values(array_intersect(SEO_ADMIN_CSV_FIELDS, $cols));
    if (!$editable) {
        fclose($fh);
        $out['error'] = 'No editable column found. Columns that can be imported: ' . implode(', ', SEO_ADMIN_CSV_FIELDS) . '.';
        return $out;
    }
    $out['ignored_columns'] = array_values(array_diff($cols, seo_admin_csv_header(), ['']));
    $items = seo_admin_items();
    $byUrl = [];
    foreach ($items as $k => $it) {
        $byUrl[seo_admin_norm_url($it['url'])] = $k;
    }
    $line = 1;
    while (($r = fgetcsv($fh)) !== false) {
        $line++;
        if (count($r) === 1 && trim((string) $r[0]) === '') {
            continue;
        }
        if ($out['rows'] >= SEO_ADMIN_CSV_MAX_ROWS) {
            $out['skipped'][] = [$line, '', '', 'Only the first ' . SEO_ADMIN_CSV_MAX_ROWS . ' rows are read.'];
            break;
        }
        $out['rows']++;
        $type = isset($idx['type']) ? strtolower(trim((string) ($r[$idx['type']] ?? ''))) : '';
        $id = isset($idx['id']) ? (int) trim((string) ($r[$idx['id']] ?? '')) : 0;
        $key = $type . ':' . $id;
        if ((!$type || !$id) && isset($idx['url'])) {
            $u = trim((string) ($r[$idx['url']] ?? ''));
            $key = $byUrl[seo_admin_norm_url(preg_match('#^https?://#i', $u) ? $u : seo_admin_abs($u))] ?? '';
        }
        if (!isset($items[$key])) {
            $out['skipped'][] = [$line, $key !== ':0' ? $key : '', '', 'Not found among the public URLs.'];
            continue;
        }
        $it = $items[$key];
        $input = [];
        foreach ($editable as $f) {
            $v = seo_admin_csv_unescape(trim((string) ($r[$idx[$f]] ?? '')));
            if ($v !== '') {
                $input[$f] = $v;
            }
        }
        if (!$input) {
            continue;
        }
        [$clean, $errors] = seo_admin_validate($it['type'], $input, $isAdmin);
        foreach ($errors as $f => $msg) {
            $out['skipped'][] = [$line, $key, $f, $msg];
        }
        foreach ($clean as $f => $new) {
            if (!array_key_exists($f, $it['values'])) {
                continue;
            }
            if (!seo_admin_field_available($it['type'], $f)) {
                $out['skipped'][] = [$line, $key, $f, 'Not stored yet: needs the SEO core update (seo_meta table).'];
                continue;
            }
            $old = (string) $it['values'][$f];
            if ($old === (string) $new) {
                continue;
            }
            if (!$overwrite && $old !== '') {
                $out['skipped'][] = [$line, $key, $f, 'Already filled (fill-empty mode).'];
                continue;
            }
            if (count($out['changes']) >= 5000) {
                $out['skipped'][] = [$line, $key, $f, 'More than 5000 changes: import the file in parts.'];
                break 2;
            }
            $out['changes'][] = ['key' => $key, 'type' => $it['type'], 'id' => $it['id'], 'title' => $it['title'], 'path' => $it['path'],
                'field' => $f, 'old' => $old, 'new' => (string) $new, 'line' => $line];
        }
    }
    fclose($fh);
    return $out;
}

/**
 * Apply a previewed import. Each value is applied only if the field still has the value seen in the preview
 * (and, in fill-empty mode, is still empty). Returns ['applied' => n fields, 'entities' => n, 'skipped' => n].
 */
function seo_admin_csv_apply(array $preview, bool $isAdmin): array
{
    $by = [];
    foreach ($preview['changes'] ?? [] as $c) {
        $by[$c['key']][] = $c;
    }
    $applied = $entities = $skipped = 0;
    foreach ($by as $key => $list) {
        [$type, $id] = explode(':', $key, 2);
        $id = (int) $id;
        $row = seo_admin_entity($type, $id);
        if (!$row) {
            $skipped += count($list);
            continue;
        }
        $current = seo_admin_values($type, $row, seo_admin_meta_row($type, $id));
        $input = [];
        foreach ($list as $c) {
            $now = (string) ($current[$c['field']] ?? '');
            if ($now !== (string) $c['old'] || (($preview['mode'] ?? 'fill') === 'fill' && $now !== '')) {
                $skipped++;
                continue;
            }
            $input[$c['field']] = $c['new'];
        }
        if (!$input) {
            continue;
        }
        [$clean, $errors] = seo_admin_validate($type, $input, $isAdmin);
        $skipped += count($errors);
        $changes = seo_admin_save($type, $id, $clean);
        if ($changes) {
            $applied += count($changes);
            $entities++;
        }
    }
    return ['applied' => $applied, 'entities' => $entities, 'skipped' => $skipped];
}

// =====================================================================
// Audit log
// =====================================================================

/** Filtered seo_changes rows with the staff name. $f: type, field, user, from, to. Returns [rows, total]. */
function seo_admin_changes(array $f, int $limit = 50, int $offset = 0): array
{
    if (!seo_admin_table_ready('seo_changes')) {
        return [[], 0];
    }
    $where = ['1=1'];
    $params = [];
    if (!empty($f['type'])) {
        $where[] = 'c.entity_type = ?';
        $params[] = (string) $f['type'];
    }
    if (!empty($f['id'])) {
        $where[] = 'c.entity_id = ?';
        $params[] = (int) $f['id'];
    }
    if (!empty($f['field'])) {
        $where[] = 'c.field = ?';
        $params[] = (string) $f['field'];
    }
    if (!empty($f['user'])) {
        $where[] = 'c.changed_by = ?';
        $params[] = (int) $f['user'];
    }
    if (!empty($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['from'])) {
        $where[] = 'c.created_at >= ?';
        $params[] = $f['from'] . ' 00:00:00';
    }
    if (!empty($f['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['to'])) {
        $where[] = 'c.created_at <= ?';
        $params[] = $f['to'] . ' 23:59:59';
    }
    $sqlWhere = implode(' AND ', $where);
    $total = (int) db_value("SELECT COUNT(*) FROM seo_changes c WHERE $sqlWhere", $params);
    $rows = db_rows("SELECT c.*, u.name AS user_name FROM seo_changes c LEFT JOIN users u ON u.id = c.changed_by WHERE $sqlWhere
                     ORDER BY c.id DESC LIMIT " . max(1, min(5000, $limit)) . ' OFFSET ' . max(0, $offset), $params);
    return [$rows, $total];
}

// =====================================================================
// Migrations (830-839)
// =====================================================================

/** Field-level SEO change log (old -> new). */
function migration_830_seo_changes(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS seo_changes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        entity_type VARCHAR(30) NOT NULL,
        entity_id INT UNSIGNED NOT NULL,
        field VARCHAR(40) NOT NULL,
        old_value TEXT DEFAULT NULL,
        new_value TEXT DEFAULT NULL,
        changed_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_seo_changes_entity (entity_type, entity_id, created_at),
        KEY idx_seo_changes_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

/** Results of the manual external link check. */
function migration_831_seo_link_checks(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS seo_link_checks (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        url VARCHAR(500) NOT NULL,
        url_hash CHAR(40) NOT NULL,
        is_internal TINYINT(1) NOT NULL DEFAULT 1,
        status_code SMALLINT DEFAULT NULL,
        final_url VARCHAR(500) DEFAULT NULL,
        error VARCHAR(255) DEFAULT NULL,
        found_on TEXT DEFAULT NULL,
        checked_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_link_hash (url_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

/**
 * Permission seo.manage for administrators and content editors (the roles that already manage pages). The SEO core's
 * migration 802 adds the same permission; whichever runs first creates it (INSERT IGNORE), the other changes nothing.
 */
function migration_832_seo_admin_permission(): void
{
    add_permission_if_missing('seo.manage', 'Manage SEO: titles, descriptions, keywords, social images, structured data', 'Website', 17, ['content_editor']);
}
