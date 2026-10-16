<?php
/**
 * SEO core (WP-1): metadata of the current request, robots and canonical rules, the <head> SEO block (title,
 * description, robots, canonical, Open Graph, Twitter, verification tags) and one connected JSON-LD @graph.
 *
 * Hook points in lead files (WP-0, each guarded by function_exists() so the site works without this file):
 *   includes/cms.php   page_header():   $GLOBALS['seo'] = seo_finalize($GLOBALS['seo'], $page, $opts);
 *                      seo_schemas():   return [seo_graph_document()];
 *                      faq_schema():    return seo_faq_schema();
 *                      seo_full_title(): return seo_title($GLOBALS['seo'] ?? []);
 *   header.php         seo_head_tags('meta') replaces the title...verification tags, seo_head_tags('graph') the JSON-LD
 *   includes/core.php  paginate(): $GLOBALS['seo_pagination'] = $result;  pagination_links(): page 1 without ?page=
 *
 * Data: table seo_meta (migration 800) holds per-entity overrides that the entity tables lack (SEO-DESIGN 4.1).
 * Rules: SEO-DESIGN 4.2-4.5 and 4.8 with the CRITIC corrections: P1-1 robots matrix (noindex, follow for list
 * variants; nofollow only on private/token pages), P1-2 awareness-day cross-edition canonical, C6 FAQ markup on one
 * page per Q&A, C13 home meta title printed verbatim, P3-2 404 without canonical and BreadcrumbList.
 * Never emitted: SearchAction, AggregateRating, Review, HowTo, Course, JobPosting, nonprofitStatus, meeting links,
 * e-mail addresses or phone numbers other than the organisation's own (from settings).
 * Other SEO packages are only called behind function_exists(): WP-3 seo_og_image(), seo_og_default(),
 * seo_static_og(), seo_static_og_alt(), seo_image_alt(), seo_image_info(); WP-5 seo_verification_tags();
 * WP-6 seo_eeat_content_dates() (WP-6 adds its Person / partial WebPage nodes through seo_graph_add()).
 * PHP 7.4 compatible.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** Entity types with SEO data (seo_meta.entity_type). Programme and category pages are `page` rows. */
const SEO_ENTITY_TYPES = ['page', 'post', 'story', 'event', 'campaign', 'scheme'];
/** Templates that only open from a private link or for a signed-in member: noindex, nofollow (+ X-Robots-Tag). */
const SEO_PRIVATE_TEMPLATES = ['member', 'donation_success', 'donation_failed', 'donation_receipt'];
/** Sign-in pages: noindex, follow (crawlable so the noindex is seen; their links may be followed). */
const SEO_AUTH_TEMPLATES = ['login', 'register', 'forgot_password', 'reset_password', 'verify_email', 'logout'];
/** List templates whose main list uses paginate() / ?page=N. */
const SEO_PAGINATED_TEMPLATES = ['blog', 'stories', 'events', 'campaigns', 'schemes', 'gallery'];
/** Query parameters that mark a private (token) view. */
const SEO_PRIVATE_PARAMS = ['token', 'unsubscribe', 'unsubscribed'];
/** Tracking parameters dropped from canonicals (utm_* too). */
const SEO_TRACKING_PARAMS = ['gclid', 'fbclid', 'msclkid', 'dclid', 'gbraid', 'wbraid', 'yclid', 'twclid', 'igshid', 'mc_cid', 'mc_eid', '_ga', '_gl', 'ref_src'];
/** schema.org WebPage family (merged into the generated #webpage node). */
const SEO_WEBPAGE_TYPES = ['WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'ItemPage', 'FAQPage', 'ProfilePage', 'ImageGallery',
    'MediaGallery', 'VideoGallery', 'SearchResultsPage', 'CheckoutPage', 'QAPage', 'MedicalWebPage'];
const SEO_ARTICLE_TYPES = ['Article', 'BlogPosting', 'NewsArticle', 'Report'];
const SEO_EVENT_TYPES = ['Event', 'EducationEvent', 'SocialEvent', 'MusicEvent', 'BusinessEvent', 'ExhibitionEvent', 'Festival', 'SportsEvent', 'ChildrensEvent', 'TheaterEvent'];
/** Types that are never printed (no real data on this site, or invented-review risk). */
const SEO_FORBIDDEN_TYPES = ['SearchAction', 'AggregateRating', 'Review', 'EmployerAggregateRating', 'HowTo', 'Course', 'JobPosting', 'ClaimReview'];
const SEO_FORBIDDEN_KEYS = ['aggregateRating', 'review', 'reviews', 'nonprofitStatus'];
/** Meeting / video-call hosts: such links never appear in public markup. */
const SEO_MEETING_HOSTS = '~(zoom\.us|zoom\.com|meet\.google\.com|hangouts\.google\.com|teams\.microsoft\.com|teams\.live\.com|webex\.com|gotomeeting\.com|gotowebinar\.com|whereby\.com|meet\.jit\.si|bluejeans\.com|skype\.com|chime\.aws|discord\.gg)~i';
/** Indian states and union territories (addressRegion when an address is parsed from free text). */
const SEO_IN_REGIONS = ['Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh',
    'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab',
    'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal', 'Andaman and Nicobar Islands',
    'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Jammu and Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry'];

// =====================================================================
// Small helpers
// =====================================================================

/** Per-request cache (seo_core_reset() clears it). */
function &seo_core_cache(): array
{
    static $cache = [];
    return $cache;
}

/** Clear the per-request caches and extra graph nodes (CLI tests; a second page_header() in one request). */
function seo_core_reset(): void
{
    $cache = &seo_core_cache();
    $cache = [];
    $GLOBALS['seo_graph_extra'] = [];
}

/** Plain text: tags removed (block ends become spaces), entities decoded, whitespace collapsed. */
function seo_plain($value): string
{
    $html = preg_replace('~<(br|/p|/li|/h[1-6]|/div|/td|/th)\b[^>]*>~i', ' ', (string) $value);
    $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

/** Text cut at a sentence end (after at least 70 characters) or a word, to fit $max characters. */
function seo_cut_sentence(string $text, int $max = 155): string
{
    $text = seo_plain($text);
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max);
    $best = 0;
    if (preg_match_all('/[.!?\x{0964}](?=\s|$)/u', $cut, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $hit) {
            $pos = mb_strlen(substr($cut, 0, $hit[1])) + 1;
            if ($pos >= 70) {
                $best = $pos;
            }
        }
    }
    if ($best > 0) {
        return rtrim(mb_substr($cut, 0, $best));
    }
    $space = mb_strrpos(mb_substr($cut, 0, $max - 1), ' ');
    return rtrim(mb_substr($cut, 0, ($space !== false && $space > 40) ? $space : $max - 1), " ,;:-\u{2013}") . '…';
}

/** Absolute URL of a site path ('about/', '/about/', the url() form with the base path) or an absolute URL as is. */
function seo_abs(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    if (strpos($url, '//') === 0) {
        return (parse_url(BASE_URL, PHP_URL_SCHEME) ?: 'https') . ':' . $url;
    }
    $base = base_path();
    if ($base !== '' && ($url === $base || strpos($url, $base . '/') === 0)) {
        $url = substr($url, strlen($base));
    }
    return abs_url(ltrim($url, '/'));
}

/** Path of a URL relative to the site root, lower-case, without slashes at the ends ('' = home). */
function seo_site_path(string $url): string
{
    $path = (string) parse_url(seo_abs($url), PHP_URL_PATH);
    $base = base_path();
    if ($base !== '' && ($path === $base || strpos($path, $base . '/') === 0)) {
        $path = substr($path, strlen($base));
    }
    return strtolower(trim(rawurldecode($path), '/'));
}

/** Do two URLs point to the same page of this site (path only; query and fragment ignored)? */
function seo_same_path(string $a, string $b): bool
{
    if ($a === '' || $b === '' || !seo_is_own_url(seo_abs($a)) || !seo_is_own_url(seo_abs($b))) {
        return false;
    }
    return seo_site_path($a) === seo_site_path($b);
}

/** Is this absolute URL on the BASE_URL host? */
function seo_is_own_url(string $url): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return $host !== '' && $host === strtolower((string) parse_url(BASE_URL, PHP_URL_HOST));
}

/** Is this a tracking parameter (utm_*, gclid, fbclid ...)? */
function seo_is_tracking_param($key): bool
{
    $key = strtolower((string) $key);
    return strpos($key, 'utm_') === 0 || in_array($key, SEO_TRACKING_PARAMS, true);
}

/**
 * Canonical form of a URL: absolute; on this site the BASE_URL scheme/host, a lower-case path with a trailing slash,
 * no fragment, no tracking parameters and no ?page=1. Another domain is returned as entered (admin override).
 */
function seo_normalise_url(string $url): string
{
    $url = trim((string) preg_replace('/#.*$/s', '', trim($url)));
    if ($url === '' || preg_match('/[\s<>"\\\\]/', $url) || (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $url) && !preg_match('#^https?://#i', $url))) {
        return '';
    }
    $abs = seo_abs($url);
    $parts = parse_url($abs);
    if (!$parts || empty($parts['host']) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
        return '';
    }
    if (!seo_is_own_url($abs)) {
        return $abs;
    }
    $own = parse_url(BASE_URL);
    $query = [];
    if (isset($parts['query'])) {
        parse_str($parts['query'], $q);
        foreach ($q as $k => $v) {
            if (is_string($v) && !seo_is_tracking_param($k)) {
                $query[$k] = $v;
            }
        }
    }
    if (isset($query['page']) && (int) $query['page'] <= 1) {
        unset($query['page']);
    }
    $path = strtolower((string) ($parts['path'] ?? '/'));
    if ($path === '') {
        $path = '/';
    }
    if (substr($path, -1) !== '/' && !preg_match('/\.[a-z0-9]{2,5}$/', $path)) {
        $path .= '/';
    }
    return ($own['scheme'] ?? 'https') . '://' . $own['host'] . (isset($own['port']) ? ':' . $own['port'] : '') . $path
        . ($query ? '?' . http_build_query($query) : '');
}

/** Local file (relative to the web root) of an image URL or path, or '' when it is not a file of this site. */
function seo_local_path(string $src): string
{
    $src = trim($src);
    if ($src === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $src)) {
        $baseUrl = rtrim(BASE_URL, '/');
        if (stripos($src, $baseUrl . '/') !== 0) {
            return '';
        }
        $src = substr($src, strlen($baseUrl));
    } else {
        $base = base_path();
        if ($base !== '' && strpos($src, $base . '/') === 0) {
            $src = substr($src, strlen($base));
        }
    }
    $src = ltrim((string) parse_url($src, PHP_URL_PATH), '/');
    if ($src === '' || strpos($src, '..') !== false || !preg_match('#^[A-Za-z0-9_\-/.]+$#', $src) || !is_file(ROOT_PATH . '/' . $src)) {
        return '';
    }
    return $src;
}

/** JPEG, PNG, WebP or GIF (social networks do not render SVG)? */
function seo_is_raster(string $path): bool
{
    return (bool) preg_match('/\.(jpe?g|png|webp|gif)$/i', $path);
}

/** [width, height, mime] of a local image ([0, 0, ''] when unknown). Uses WP-3's cached info when available. */
function seo_image_size(string $rel): array
{
    if ($rel === '') {
        return [0, 0, ''];
    }
    if (function_exists('seo_image_info')) {
        $i = seo_image_info($rel);
        if (!empty($i['w'])) {
            return [(int) $i['w'], (int) $i['h'], (string) ($i['mime'] ?? '')];
        }
    }
    $s = @getimagesize(ROOT_PATH . '/' . $rel);
    return $s ? [(int) $s[0], (int) $s[1], (string) ($s['mime'] ?? '')] : [0, 0, ''];
}

/** All text of a decoded JSON value (section items), without URLs, icons and other non-prose keys. */
function seo_strings_of($data): string
{
    if (is_string($data)) {
        return $data;
    }
    if (!is_array($data)) {
        return '';
    }
    $out = '';
    foreach ($data as $k => $v) {
        if (is_string($k) && in_array($k, ['icon', 'url', 'link', 'image', 'tone', 'anchor', 'style', 'video', 'button1_url', 'button2_url'], true)) {
            continue;
        }
        $out .= ' ' . seo_strings_of($v);
    }
    return $out;
}

/** Number of words (letters/digits runs, any script). */
function seo_word_total(string $text): int
{
    return (int) preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\p{M}\'\x{2019}\-]*/u', seo_plain($text));
}

/** Did an earlier header() call send an X-Robots-Tag with nofollow (private views)? Always false on the CLI. */
function seo_headers_nofollow(): bool
{
    foreach (headers_list() as $h) {
        if (stripos($h, 'x-robots-tag:') === 0 && stripos($h, 'nofollow') !== false) {
            return true;
        }
    }
    return false;
}

// =====================================================================
// seo_meta: per-entity overrides (SEO-DESIGN 4.1)
// =====================================================================

/** The seo_meta row of an entity ([] when there is none or the table does not exist yet). One cached query. */
function seo_meta_row(string $type, int $id): array
{
    if ($id <= 0 || !in_array($type, SEO_ENTITY_TYPES, true)) {
        return [];
    }
    $cache = &seo_core_cache();
    $key = 'meta:' . $type . ':' . $id;
    if (!array_key_exists($key, $cache)) {
        try {
            $cache[$key] = db_row('SELECT * FROM seo_meta WHERE entity_type = ? AND entity_id = ?', [$type, $id]) ?: [];
        } catch (Throwable $e) {
            $cache[$key] = [];   // table not created yet
        }
    }
    return $cache[$key];
}

/** Does the seo_meta table exist? */
function seo_meta_table_ready(): bool
{
    $cache = &seo_core_cache();
    if (!isset($cache['meta_table'])) {
        try {
            db_value('SELECT 1 FROM seo_meta LIMIT 1');
            $cache['meta_table'] = true;
        } catch (Throwable $e) {
            $cache['meta_table'] = false;
        }
    }
    return $cache['meta_table'];
}

/** A JSON column of a seo_meta row decoded to an array (schema_fields, sources). */
function seo_meta_json(array $meta, string $field): array
{
    $data = json_decode((string) ($meta[$field] ?? ''), true);
    return is_array($data) ? $data : [];
}

/** A tri-state flag of a seo_meta row: null = inherit, 0 or 1. */
function seo_meta_flag(array $meta, string $field): ?int
{
    if (!isset($meta[$field]) || $meta[$field] === '') {
        return null;
    }
    return (int) $meta[$field] === 1 ? 1 : 0;
}

/**
 * The seo_meta fields an entity type may use. Values that have their own column on the entity table are not stored
 * twice: meta title / description only for stories and schemes; for pages canonical (pages.canonical_url), social image
 * (pages.og_image) and sitemap flag (pages.show_in_sitemap) stay on the page row. For pages robots_index = 1 only
 * keeps a list page indexable when the empty-list rule would noindex it (pages.noindex stays the noindex switch).
 */
function seo_meta_fields(string $type): array
{
    if (!in_array($type, SEO_ENTITY_TYPES, true)) {
        return [];
    }
    $all = ['meta_title', 'meta_description', 'canonical_url', 'robots_index', 'robots_follow', 'og_title', 'og_description', 'og_image',
        'og_image_alt', 'focus_keyword', 'secondary_keywords', 'schema_type', 'schema_fields', 'sitemap_include', 'sitemap_priority',
        'author_member_id', 'reviewer_member_id', 'reviewed_at', 'sources'];
    $skip = [];
    if (!in_array($type, ['story', 'scheme'], true)) {
        $skip = ['meta_title', 'meta_description'];
    }
    if ($type === 'page') {
        $skip = array_merge($skip, ['canonical_url', 'og_image', 'sitemap_include']);
    }
    return array_values(array_diff($all, $skip));
}

/** Allowed schema types per entity (admin select; SEO-DESIGN 4.5). */
function seo_schema_types(string $type): array
{
    $map = [
        'page' => ['WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'FAQPage'],
        'post' => ['BlogPosting', 'NewsArticle', 'Article'],
        'story' => ['Article', 'BlogPosting'],
        'event' => ['Event', 'EducationEvent', 'SocialEvent', 'MusicEvent'],
        'campaign' => ['WebPage'],
        'scheme' => ['WebPage'],
    ];
    return $map[$type] ?? [];
}

/**
 * Validate seo_meta input for one entity. Returns [$clean, $errors]: $clean maps field => normalised value (null =
 * clear / inherit); $errors maps field => message. Empty strings clear a field. No free JSON: schema_fields and
 * sources accept only their whitelisted shapes.
 */
function seo_meta_validate(string $type, array $fields): array
{
    $allowed = seo_meta_fields($type);
    if (!$allowed) {
        return [[], ['entity_type' => 'Unknown entity type.']];
    }
    $clean = [];
    $errors = [];
    $text = function ($v, int $max, string $field) use (&$errors): ?string {
        $v = trim((string) preg_replace('/\s+/u', ' ', (string) $v));
        if ($v !== strip_tags($v)) {
            $errors[$field] = 'HTML is not allowed.';
            return null;
        }
        if (mb_strlen($v) > $max) {
            $errors[$field] = 'At most ' . $max . ' characters.';
            return null;
        }
        return $v;
    };
    $flag = function ($v, string $field) use (&$errors): ?int {
        if ($v === 0 || $v === 1 || $v === '0' || $v === '1' || $v === false || $v === true) {
            return (int) $v;
        }
        $errors[$field] = 'Must be empty (inherit), 0 or 1.';
        return null;
    };
    foreach ($fields as $field => $value) {
        $field = (string) $field;
        if (!in_array($field, $allowed, true)) {
            $errors[$field] = in_array($field, ['meta_title', 'meta_description', 'canonical_url', 'og_image', 'sitemap_include'], true)
                ? 'This value is stored in the ' . $type . '\'s own field, not in seo_meta.' : 'Unknown field.';
            continue;
        }
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === null || $value === '' || $value === []) {
            $clean[$field] = null;
            continue;
        }
        switch ($field) {
            case 'meta_title':
            case 'og_title':
                $clean[$field] = $text($value, 200, $field);
                break;
            case 'meta_description':
                $clean[$field] = $text($value, 320, $field);
                break;
            case 'og_description':
                $clean[$field] = $text($value, 300, $field);
                break;
            case 'og_image_alt':
                $clean[$field] = $text($value, 255, $field);
                break;
            case 'focus_keyword':
                $clean[$field] = $text($value, 120, $field);
                break;
            case 'secondary_keywords':
                $list = [];
                foreach (preg_split('/\s*[,\n]\s*/u', (string) $value) as $kw) {
                    $kw = trim((string) preg_replace('/\s+/u', ' ', $kw));
                    if ($kw !== '' && !isset($list[mb_strtolower($kw)])) {
                        $list[mb_strtolower($kw)] = $kw;
                    }
                }
                if (count($list) > 10) {
                    $errors[$field] = 'At most 10 secondary keywords.';
                } elseif (max(array_map('mb_strlen', $list) ?: [0]) > 120) {
                    $errors[$field] = 'Each keyword: at most 120 characters.';
                } else {
                    $clean[$field] = $text(implode(', ', $list), 500, $field);
                }
                break;
            case 'canonical_url':
                $v = (string) $value;
                if (mb_strlen($v) > 255 || !(preg_match('#^/[a-z0-9\-_/]*(\?[a-z0-9_=&%\-]*)?$#i', $v) || filter_var($v, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $v))) {
                    $errors[$field] = 'Use a site path such as /about/ or a full http(s) address.';
                } else {
                    $clean[$field] = $v;
                }
                break;
            case 'robots_index':
            case 'robots_follow':
            case 'sitemap_include':
                $clean[$field] = $flag($value, $field);
                break;
            case 'og_image':
                $v = ltrim((string) $value, '/');
                if (!preg_match('#^uploads/[A-Za-z0-9_\-/.]+\.(jpe?g|png|webp|gif)$#i', $v) || strpos($v, '..') !== false || !is_file(ROOT_PATH . '/' . $v)) {
                    $errors[$field] = 'Choose an uploaded JPG, PNG, WebP or GIF image (SVG is not shown by social networks).';
                } else {
                    $clean[$field] = $v;
                }
                break;
            case 'schema_type':
                if (!in_array((string) $value, seo_schema_types($type), true)) {
                    $errors[$field] = 'Allowed: ' . implode(', ', seo_schema_types($type)) . '.';
                } else {
                    $clean[$field] = (string) $value;
                }
                break;
            case 'schema_fields':
                $data = is_array($value) ? $value : json_decode((string) $value, true);
                $out = [];
                if (!is_array($data)) {
                    $errors[$field] = 'Invalid data.';
                    break;
                }
                foreach ($data as $k => $v) {
                    if ($type === 'page' && $k === 'faq_markup') {
                        $out['faq_markup'] = (int) (bool) $v;
                    } elseif ($type === 'page' && $k === 'about_name') {
                        $t = $text($v, 120, $field);
                        if ($t !== null && $t !== '') {
                            $out['about_name'] = $t;
                        }
                    } else {
                        $errors[$field] = 'Unknown schema field "' . mb_substr((string) $k, 0, 40) . '".';
                    }
                }
                $clean[$field] = $out ? json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
                break;
            case 'sitemap_priority':
                if (!is_numeric($value) || (float) $value < 0 || (float) $value > 1) {
                    $errors[$field] = 'A number from 0.0 to 1.0.';
                } else {
                    $clean[$field] = number_format(round((float) $value, 1), 1, '.', '');
                }
                break;
            case 'author_member_id':
            case 'reviewer_member_id':
                if (!preg_match('/^[1-9]\d{0,9}$/', (string) $value)) {
                    $errors[$field] = 'Choose a team member.';
                } else {
                    $clean[$field] = (int) $value;
                }
                break;
            case 'reviewed_at':
                $v = (string) $value;
                $d = DateTime::createFromFormat('!Y-m-d', $v);
                if (!$d || $d->format('Y-m-d') !== $v || $d->getTimestamp() > time() + 86400) {
                    $errors[$field] = 'A date (YYYY-MM-DD), not in the future.';
                } else {
                    $clean[$field] = $v;
                }
                break;
            case 'sources':
                $data = is_array($value) ? $value : json_decode((string) $value, true);
                if (!is_array($data)) {
                    $errors[$field] = 'Invalid list of sources.';
                    break;
                }
                $out = [];
                foreach (array_values($data) as $s) {
                    $url = trim((string) (is_array($s) ? ($s['url'] ?? '') : $s));
                    $title = trim((string) (is_array($s) ? ($s['title'] ?? '') : ''));
                    if ($url === '') {
                        continue;
                    }
                    if (!filter_var($url, FILTER_VALIDATE_URL) || stripos($url, 'https://') !== 0 || mb_strlen($url) > 500) {
                        $errors[$field] = 'Each source needs an https:// address.';
                        break;
                    }
                    $title = $text($title !== '' ? $title : (string) parse_url($url, PHP_URL_HOST), 200, $field);
                    $out[] = ['title' => (string) $title, 'url' => $url];
                }
                if (count($out) > 10) {
                    $errors[$field] = 'At most 10 sources.';
                }
                $clean[$field] = $out ? json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
                break;
        }
    }
    foreach (array_keys($errors) as $f) {
        unset($clean[$f]);
    }
    return [$clean, $errors];
}

/**
 * Save seo_meta fields of one entity (insert or update; only fields passed are touched). Validates first and throws
 * InvalidArgumentException with the messages when something is wrong (call seo_meta_validate() to show them per field).
 * Returns the changed fields for the audit log: [field => ['old' => ?string, 'new' => ?string]] ([] = nothing changed).
 */
function seo_meta_save(string $type, int $id, array $fields, ?int $by = null): array
{
    if ($id <= 0) {
        throw new InvalidArgumentException('Invalid entity id.');
    }
    [$clean, $errors] = seo_meta_validate($type, $fields);
    if ($errors) {
        $msg = [];
        foreach ($errors as $f => $m) {
            $msg[] = $f . ': ' . $m;
        }
        throw new InvalidArgumentException(implode(' ', $msg));
    }
    if (!seo_meta_table_ready()) {
        throw new RuntimeException('The seo_meta table does not exist yet (migration 800 has not run).');
    }
    $cache = &seo_core_cache();
    unset($cache['meta:' . $type . ':' . $id]);
    $old = seo_meta_row($type, $id);
    $changes = [];
    foreach ($clean as $f => $v) {
        $o = isset($old[$f]) ? (string) $old[$f] : null;
        if ($f === 'sitemap_priority' && $o !== null) {
            $o = number_format((float) $o, 1, '.', '');
        }
        $n = $v === null ? null : (string) $v;
        if ($o !== $n) {
            $changes[$f] = ['old' => $o, 'new' => $n];
        }
    }
    if (!$changes) {
        return [];
    }
    $cols = array_keys($changes);
    $sql = 'INSERT INTO seo_meta (entity_type, entity_id, updated_by, `' . implode('`, `', $cols) . '`) VALUES (?, ?, ?' . str_repeat(', ?', count($cols)) . ')'
        . ' ON DUPLICATE KEY UPDATE updated_by = VALUES(updated_by), ' . implode(', ', array_map(function ($c) {
            return '`' . $c . '` = VALUES(`' . $c . '`)';
        }, $cols));
    $params = [$type, $id, $by];
    foreach ($cols as $c) {
        $params[] = $changes[$c]['new'];
    }
    db_query($sql, $params);
    unset($cache['meta:' . $type . ':' . $id], $cache['graph']);
    return $changes;
}

// =====================================================================
// The entity being rendered
// =====================================================================

/** Table of each entity type. */
function seo_entity_tables(): array
{
    return ['page' => 'pages', 'post' => 'blog_posts', 'story' => 'success_stories', 'event' => 'events', 'campaign' => 'campaigns', 'scheme' => 'schemes'];
}

/** An entity row by id or slug (cached per request; null when missing). */
function seo_entity_row(string $type, int $id = 0, string $slug = ''): ?array
{
    $tables = seo_entity_tables();
    if (!isset($tables[$type]) || ($id <= 0 && $slug === '')) {
        return null;
    }
    $cache = &seo_core_cache();
    $key = 'row:' . $type . ':' . ($id > 0 ? $id : 's:' . $slug);
    if (!array_key_exists($key, $cache)) {
        try {
            $row = $id > 0
                ? db_row('SELECT * FROM `' . $tables[$type] . '` WHERE id = ?', [$id])
                : db_row('SELECT * FROM `' . $tables[$type] . '` WHERE slug = ?', [$slug]);
        } catch (Throwable $e) {
            $row = null;
        }
        $cache[$key] = $row ?: null;
    }
    return $cache[$key];
}

/** May visitors see this entity (same rules as its template)? Staff previews of anything else are private. */
function seo_entity_public(string $type, array $row): bool
{
    $status = (string) ($row['status'] ?? '');
    $now = time();
    switch ($type) {
        case 'post':
            return $status === 'published' && !empty($row['published_at']) && strtotime((string) $row['published_at']) <= $now;
        case 'story':
            return $status === 'published' && (int) ($row['consent_confirmed'] ?? 0) === 1
                && (empty($row['published_at']) || strtotime((string) $row['published_at']) <= $now);
        case 'event':
            return in_array($status, ['published', 'cancelled'], true);
        case 'campaign':
            return function_exists('campaign_is_public') ? campaign_is_public($row) : in_array($status, ['active', 'completed', 'closed'], true);
        case 'scheme':
            return $status === 'published';
        default:
            return $status === '' || $status === 'published';
    }
}

/** Public URL (absolute) of an entity row. */
function seo_entity_url(string $type, array $row): string
{
    $slug = (string) ($row['slug'] ?? '');
    switch ($type) {
        case 'post':
            return abs_url('blog/' . $slug . '/');
        case 'story':
            return abs_url('success-stories/' . $slug . '/');
        case 'event':
            return function_exists('event_abs_url') ? event_abs_url($row) : abs_url('events/' . $slug . '/');
        case 'campaign':
            return abs_url('campaign/' . $slug . '/');
        case 'scheme':
            return abs_url('schemes/' . $slug . '/');
        default:
            return abs_page_url($slug === '' ? 'home' : $slug);
    }
}

/**
 * Which entity is this request about? $opts['seo_entity'] (['type' => ..., 'id' => ...]) wins; otherwise detail
 * templates are detected from template + route (/blog/<slug>/ = post, /success-stories/<slug>/ = story,
 * /events|hackathons/<slug>/ = event, /campaign/<slug>/ = campaign, /schemes/<slug>/ = scheme), else the page row.
 * Returns ['type', 'id', 'row', 'public'] or null.
 */
function seo_current_entity(array $page, array $opts = []): ?array
{
    if (!empty($opts['seo_entity']) && is_array($opts['seo_entity'])) {
        $type = (string) ($opts['seo_entity']['type'] ?? ($opts['seo_entity'][0] ?? ''));
        $id = (int) ($opts['seo_entity']['id'] ?? ($opts['seo_entity'][1] ?? 0));
        if (in_array($type, SEO_ENTITY_TYPES, true) && $id > 0) {
            $row = ($type === 'page' && (int) ($page['id'] ?? 0) === $id) ? $page : seo_entity_row($type, $id);
            if ($row) {
                return ['type' => $type, 'id' => (int) $row['id'], 'row' => $row, 'public' => seo_entity_public($type, $row)];
            }
        }
    }
    $map = ['blog' => 'post', 'stories' => 'story', 'event_detail' => 'event', 'events' => 'event', 'campaign_detail' => 'campaign', 'schemes' => 'scheme'];
    $template = (string) ($page['template'] ?? '');
    $segments = function_exists('route_segments') ? route_segments() : [];
    $slug = (string) ($segments[0] ?? '');
    if (isset($map[$template]) && $slug !== '' && preg_match('/^[a-z0-9][a-z0-9\-]*$/', $slug)) {
        $row = seo_entity_row($map[$template], 0, $slug);
        if ($row) {
            return ['type' => $map[$template], 'id' => (int) $row['id'], 'row' => $row, 'public' => seo_entity_public($map[$template], $row)];
        }
    }
    if (!empty($page['id'])) {
        return ['type' => 'page', 'id' => (int) $page['id'], 'row' => $page, 'public' => seo_entity_public('page', $page)];
    }
    return null;
}

/** Published / modified dates (ISO 8601) of an entity. Posts and stories use WP-6's substantive-update date when present. */
function seo_entity_dates(string $type, array $row): array
{
    $iso = function ($d): ?string {
        $t = $d ? strtotime((string) $d) : false;
        return $t ? date('c', $t) : null;
    };
    if (in_array($type, ['post', 'story'], true)) {
        $published = ($row['published_at'] ?? '') ?: ($row['created_at'] ?? null);
        $updated = $row['updated_at'] ?? null;
        if (function_exists('seo_eeat_content_dates') && !empty($row['id'])) {
            $d = seo_eeat_content_dates($type, (int) $row['id'], $row);
            $published = ($d['published'] ?? '') ?: $published;
            $updated = $d['updated'] ?? null;
        }
        $pub = $iso($published);
        $mod = $iso($updated);
        if ($pub && (!$mod || strtotime($mod) < strtotime($pub))) {
            $mod = $pub;
        }
        return ['published' => $pub, 'modified' => $mod];
    }
    return ['published' => null, 'modified' => $iso(($row['updated_at'] ?? '') ?: ($row['created_at'] ?? null))];
}

// =====================================================================
// Lists: filters, pagination, empty lists
// =====================================================================

/**
 * Query parameters of list templates: [param => [rule, noindex-when-present]]. Rules: 'slug', 'search', 'month' or a
 * list of allowed values. Kept parameters stay in the canonical ("self with parameters"); noindex ones make the view
 * noindex, follow (CRITIC P1-1). Valid schemes categories and completed campaigns stay indexable (real landing pages).
 */
function seo_list_query_rules(string $template): array
{
    $rules = [
        'blog' => ['category' => ['slug', true], 'q' => ['search', true]],
        'events' => ['when' => [['past'], true], 'type' => ['slug', true], 'category' => ['slug', true]],
        'calendar' => ['month' => ['month', false]],
        'campaigns' => ['status' => [['completed'], false], 'category' => ['slug', true], 'q' => ['search', true]],
        'schemes' => ['category' => ['slug', false], 'q' => ['search', true]],
    ];
    return $rules[$template] ?? [];
}

/** The request's query parameters that can change content (string values; tracking parameters removed). */
function seo_query_params(): array
{
    $out = [];
    foreach ($_GET as $k => $v) {
        if (is_string($v) && !seo_is_tracking_param($k)) {
            $out[(string) $k] = $v;
        }
    }
    return $out;
}

/** ['keep' => params kept in the canonical, 'filters' => params that make the view noindex] for a list template. */
function seo_list_query(string $template, array $query): array
{
    $keep = [];
    $filters = [];
    foreach (seo_list_query_rules($template) as $key => $spec) {
        [$rule, $noindex] = $spec;
        if (!isset($query[$key])) {
            continue;
        }
        $v = trim((string) $query[$key]);
        if (is_array($rule)) {
            $ok = in_array($v, $rule, true);
        } elseif ($rule === 'search') {
            $v = mb_substr($v, 0, 100);
            $ok = $v !== '';
        } elseif ($rule === 'month') {
            $ok = (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v) && $v !== date('Y-m');
        } else {
            $ok = (bool) preg_match('/^[a-z0-9][a-z0-9_\-]{0,59}$/i', $v);
        }
        if (!$ok) {
            continue;
        }
        $keep[$key] = $v;
        if ($noindex) {
            $filters[$key] = $v;
        }
    }
    return ['keep' => $keep, 'filters' => $filters];
}

/**
 * Pagination of the current list: ['page', 'pages', 'requested', 'beyond', 'paginated']. Uses $GLOBALS['seo_pagination']
 * (set by paginate(), WP-0 hook in core.php); without it only known list templates read ?page=N and "beyond the last
 * page" cannot be detected.
 */
function seo_pagination_state(string $template): array
{
    $raw = $_GET['page'] ?? null;
    $requested = is_string($raw) && preg_match('/^\d{1,6}$/', $raw) ? max(1, (int) $raw) : 1;
    $p = $GLOBALS['seo_pagination'] ?? null;
    $known = is_array($p) && isset($p['pages']);
    if (!$known && !in_array($template, SEO_PAGINATED_TEMPLATES, true)) {
        return ['page' => 1, 'pages' => null, 'requested' => $requested, 'beyond' => false, 'paginated' => false];
    }
    $pages = $known ? max(1, (int) $p['pages']) : null;
    $beyond = $pages !== null && $requested > $pages;
    return ['page' => $beyond ? 1 : $requested, 'pages' => $pages, 'requested' => $requested, 'beyond' => $beyond, 'paginated' => true];
}

/** " – Page N" for list pages 2..N (not added twice). */
function seo_page_suffix(string $title, int $n): string
{
    if ($n < 2 || preg_match('/\bpage\s*' . $n . '\s*$/i', $title)) {
        return $title;
    }
    return $title . ' – Page ' . $n;
}

/** Number of public items a list page shows (null = not a list template or unknown). Cached per page. */
function seo_list_item_count(array $page): ?int
{
    $id = (int) ($page['id'] ?? 0);
    $cache = &seo_core_cache();
    $key = 'listcount:' . $id;
    if ($id > 0 && array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $template = (string) ($page['template'] ?? '');
    $count = null;
    try {
        switch ($template) {
            case 'blog':
                $cats = array_values(array_filter((array) page_setting($page, 'categories', ['blog', 'news']), function ($c) {
                    return is_string($c) && preg_match('/^[a-z]+$/', $c);
                })) ?: ['blog', 'news'];
                $visible = function_exists('post_visible_sql') ? post_visible_sql() : "status = 'published' AND published_at IS NOT NULL AND published_at <= NOW()";
                $count = (int) db_value('SELECT COUNT(*) FROM blog_posts WHERE ' . $visible . ' AND category IN (' . implode(',', array_fill(0, count($cats), '?')) . ')', $cats);
                break;
            case 'stories':
                $visible = function_exists('story_visible_sql') ? story_visible_sql() : "status = 'published' AND consent_confirmed = 1 AND (published_at IS NULL OR published_at <= NOW())";
                $count = (int) db_value('SELECT COUNT(*) FROM success_stories WHERE ' . $visible);
                break;
            case 'gallery':
                $type = page_setting($page, 'media_type', 'image') === 'video' ? 'video' : 'image';
                $count = (int) db_value("SELECT COUNT(*) FROM gallery_items WHERE media_type = ? AND status = 'published' AND consent_confirmed = 1", [$type]);
                break;
            case 'team':
                $groups = page_setting($page, 'groups');
                if (!is_array($groups) || !$groups) {
                    $group = (string) page_setting($page, 'group', 'team');
                    $groups = $group === 'management' ? ['management', 'advisor'] : ($group === 'team' ? ['team', 'volunteer'] : [$group]);
                }
                $groups = array_values(array_filter(array_map('strval', $groups))) ?: ['team'];
                $count = (int) db_value("SELECT COUNT(*) FROM team_members WHERE status = 'published' AND member_group IN (" . implode(',', array_fill(0, count($groups), '?')) . ')', $groups);
                break;
            case 'documents':
                if (function_exists('resources_by_category')) {
                    $cats = array_values(array_filter(array_merge([(string) page_setting($page, 'category', '')], (array) page_setting($page, 'also', []))));
                    $count = $cats ? count(resources_by_category($cats)) : 0;
                }
                break;
            case 'campaigns':
                $count = (int) db_value("SELECT COUNT(*) FROM campaigns WHERE status IN ('active','completed','closed')");
                break;
            case 'schemes':
                $count = (int) db_value("SELECT COUNT(*) FROM schemes WHERE status = 'published'");
                break;
            case 'events':
                $count = (int) db_value("SELECT COUNT(*) FROM events WHERE status IN ('published','cancelled') AND event_type <> 'awareness_day'");
                break;
        }
    } catch (Throwable $e) {
        $count = null;
    }
    if ($id > 0) {
        $cache[$key] = $count;
    }
    return $count;
}

/** Words in a page's visible sections (titles, intros, bodies and item texts; not the banner, menus or footer). */
function seo_page_words(array $page): int
{
    if (empty($page['id'])) {
        return 0;
    }
    $text = '';
    foreach (page_sections((int) $page['id']) as $s) {
        if (in_array($s['section_type'], ['module', 'subnav'], true)) {
            continue;
        }
        $text .= ' ' . ($s['pill'] ?? '') . ' ' . ($s['title'] ?? '') . ' ' . ($s['intro'] ?? '') . ' ' . ($s['body'] ?? '')
            . ' ' . seo_strings_of(json_field($s['items'] ?? null));
    }
    return seo_word_total($text);
}

/**
 * Empty list page (SEO-DESIGN 4.4): a list template with 0 public items and fewer than 300 words in its sections.
 * Such pages get noindex, follow while setting seo_noindex_empty_lists = 1 (default; reversible) and leave the sitemap.
 */
function seo_list_is_empty(array $page): bool
{
    $count = seo_list_item_count($page);
    if ($count === null || $count > 0) {
        return false;
    }
    return seo_page_words($page) < 300;
}

/** Description from the page's own content: the first intro/text of a text section, cut at a sentence (155). */
function seo_description_fallback(array $page): string
{
    if (!empty($page['id'])) {
        foreach (page_sections((int) $page['id']) as $s) {
            if (!in_array($s['section_type'], ['richtext', 'split', 'features', 'steps', 'checklist', 'highlights', 'callout', 'cta'], true)) {
                continue;
            }
            $candidates = [(string) ($s['intro'] ?? '')];
            if (in_array($s['section_type'], ['richtext', 'split'], true)) {
                $candidates[] = (string) ($s['body'] ?? '');
            }
            foreach ($candidates as $c) {
                $t = seo_plain($c);
                if (mb_strlen($t) >= 50) {
                    return seo_cut_sentence($t, 155);
                }
            }
        }
    }
    $sub = seo_plain($page['banner_subtitle'] ?? '');
    return mb_strlen($sub) >= 50 ? seo_cut_sentence($sub, 155) : '';
}

// =====================================================================
// Awareness days: cross-edition canonical (CRITIC P1-2)
// =====================================================================

/** Group key of an awareness-day slug: the slug without its trailing year (world-food-day-2026 -> world-food-day). */
function seo_awareness_group_key(string $slug): string
{
    return (string) preg_replace('/-(?:19|20)\d{2}$/', '', strtolower(trim($slug)));
}

/** Cross-edition canonical on (setting seo_awareness_cross_canonical, default 1)? */
function seo_awareness_enabled(): bool
{
    return setting('seo_awareness_cross_canonical', '1') === '1';
}

/** Admin overrides: [group key => edition slug] (setting seo_awareness_pins, JSON). */
function seo_awareness_pins(): array
{
    $data = json_decode(setting('seo_awareness_pins'), true);
    return is_array($data) ? array_filter($data, 'is_string') : [];
}

/**
 * Published awareness days grouped by observance: [key => ['key', 'editions' => [rows + is_past], 'canonical' =>
 * ['id', 'slug', 'title', 'start_datetime', 'end_datetime', 'is_past', 'url', 'pinned']]]. Canonical edition = the
 * admin's pin, else the next upcoming (or current) edition, else the most recent one. Cached per request.
 */
function seo_awareness_groups(bool $refresh = false): array
{
    $cache = &seo_core_cache();
    if (!$refresh && isset($cache['aw_groups'])) {
        return $cache['aw_groups'];
    }
    try {
        $rows = db_rows("SELECT id, slug, title, event_type, start_datetime, end_datetime, status FROM events
                         WHERE event_type = 'awareness_day' AND status = 'published' ORDER BY start_datetime, id");
    } catch (Throwable $e) {
        $rows = [];
    }
    $now = time();
    $groups = [];
    foreach ($rows as $r) {
        $key = seo_awareness_group_key((string) $r['slug']);
        $r['is_past'] = strtotime((string) (($r['end_datetime'] ?? '') ?: $r['start_datetime'])) < $now;
        $groups[$key]['key'] = $key;
        $groups[$key]['editions'][] = $r;
    }
    $pins = seo_awareness_pins();
    foreach ($groups as $key => $g) {
        $canon = null;
        foreach ($g['editions'] as $ed) {
            if (!$ed['is_past']) {
                $canon = $ed;
                break;
            }
        }
        if ($canon === null) {
            $canon = $g['editions'][count($g['editions']) - 1];
        }
        $pinned = false;
        if (isset($pins[$key])) {
            foreach ($g['editions'] as $ed) {
                if ($ed['slug'] === $pins[$key]) {
                    $canon = $ed;
                    $pinned = true;
                    break;
                }
            }
        }
        $groups[$key]['canonical'] = [
            'id' => (int) $canon['id'], 'slug' => (string) $canon['slug'], 'title' => (string) $canon['title'],
            'start_datetime' => (string) $canon['start_datetime'], 'end_datetime' => $canon['end_datetime'],
            'is_past' => (bool) $canon['is_past'], 'url' => seo_entity_url('event', $canon), 'pinned' => $pinned,
        ];
    }
    return $cache['aw_groups'] = $groups;
}

/**
 * Canonical edition of an awareness day (event row or slug). Returns the edition array of seo_awareness_groups() plus
 * 'is_self', 'group' and 'editions' (count), or null when the event is not a published awareness day or the feature is
 * off. WP-2: leave a row out of sitemaps when the result is not null and 'is_self' is false.
 */
function seo_awareness_canonical($event): ?array
{
    if (!seo_awareness_enabled()) {
        return null;
    }
    if (is_array($event)) {
        if (isset($event['event_type']) && $event['event_type'] !== 'awareness_day') {
            return null;
        }
        $slug = (string) ($event['slug'] ?? '');
    } else {
        $slug = (string) $event;
    }
    if ($slug === '') {
        return null;
    }
    $groups = seo_awareness_groups();
    $key = seo_awareness_group_key($slug);
    if (!isset($groups[$key]) || !in_array($slug, array_column($groups[$key]['editions'], 'slug'), true)) {
        return null;
    }
    $c = $groups[$key]['canonical'];
    $c['is_self'] = $c['slug'] === $slug;
    $c['group'] = $key;
    $c['editions'] = count($groups[$key]['editions']);
    return $c;
}

/**
 * Visible link to the canonical edition for event_detail.php (WP-7c): '' on the canonical edition itself.
 * Example: "This year's observance: World Food Day 2026 (16 Oct 2026)". Reuses the .notice style.
 */
function seo_awareness_notice(array $event, string $label = "This year's observance"): string
{
    $c = seo_awareness_canonical($event);
    if (!$c || $c['is_self']) {
        return '';
    }
    $href = function_exists('event_url') ? event_url(['slug' => $c['slug'], 'event_type' => 'awareness_day']) : $c['url'];
    $date = function_exists('format_date') ? format_date($c['start_datetime'], 'd M Y') : date('d M Y', (int) strtotime($c['start_datetime']));
    return '<div class="notice seo-awareness-current" role="note">' . (function_exists('icon') ? icon('calendar') : '')
        . '<div><strong>' . e($label) . ':</strong> <a href="' . e($href) . '">' . e($c['title']) . '</a> (' . e($date) . ')</div></div>';
}

/** Admin override (WP-4): make $slug the canonical edition of its group, or clear the pin with null. False if invalid. */
function seo_awareness_pin(string $groupKey, ?string $slug): bool
{
    $groupKey = seo_awareness_group_key($groupKey);
    $pins = seo_awareness_pins();
    if ($slug === null || $slug === '') {
        unset($pins[$groupKey]);
    } else {
        $groups = seo_awareness_groups(true);
        if (!isset($groups[$groupKey]) || !in_array($slug, array_column($groups[$groupKey]['editions'], 'slug'), true)) {
            return false;
        }
        $pins[$groupKey] = $slug;
    }
    save_setting('seo_awareness_pins', $pins ? (string) json_encode($pins, JSON_UNESCAPED_SLASHES) : '');
    seo_awareness_groups(true);
    return true;
}

// =====================================================================
// Rules: robots, canonical, title, page type
// =====================================================================

/** Extra robots directives for indexable pages (setting seo_robots_extra; only known directives are kept). */
function seo_robots_extra(): string
{
    $raw = setting('seo_robots_extra', 'max-snippet:-1, max-image-preview:large, max-video-preview:-1');
    $ok = [];
    foreach (preg_split('/\s*,\s*/', strtolower(trim($raw))) as $d) {
        if (preg_match('/^(max-snippet:-?\d{1,5}|max-video-preview:-?\d{1,5}|max-image-preview:(none|standard|large)|notranslate|noimageindex)$/', $d)) {
            $ok[$d] = $d;
        }
    }
    return implode(', ', $ok);
}

/**
 * Meta robots value from the resolved flags: ['is_404', 'private', 'site_noindex', 'noindex', 'nofollow'].
 * 404: noindex, follow. Private / token / preview pages and site noindex mode: noindex, nofollow. Otherwise
 * index|noindex + follow (nofollow only when an admin set robots_follow = 0) + extras on indexable pages.
 */
function seo_robots(array $ctx): string
{
    if (!empty($ctx['is_404'])) {
        return 'noindex, follow';
    }
    if (!empty($ctx['private']) || !empty($ctx['site_noindex'])) {
        return 'noindex, nofollow';
    }
    $noindex = !empty($ctx['noindex']);
    $value = ($noindex ? 'noindex' : 'index') . ', ' . (!empty($ctx['nofollow']) ? 'nofollow' : 'follow');
    if (!$noindex) {
        $extra = seo_robots_extra();
        if ($extra !== '') {
            $value .= ', ' . $extra;
        }
    }
    return $value;
}

/**
 * Canonical URL from the resolved context: ['self', 'base', 'keep', 'explicit', 'page_canonical', 'override',
 * 'awareness', 'beyond', 'is_404']. Returns [url, source]; url '' on 404 (no canonical is printed there).
 */
function seo_canonical_resolve(array $ctx): array
{
    if (!empty($ctx['is_404'])) {
        return ['', 'none'];
    }
    $self = (string) ($ctx['self'] ?? '');
    $base = (string) ($ctx['base'] ?? $self);
    $canonical = $self;
    $source = 'self';
    $explicit = trim((string) ($ctx['explicit'] ?? ''));
    $pageCanonical = trim((string) ($ctx['page_canonical'] ?? ''));
    if ($explicit !== '' && !seo_same_path($explicit, $base)) {
        $canonical = seo_normalise_url($explicit);
        $source = 'template';
    } elseif ($explicit === '' && $pageCanonical !== '' && !seo_same_path($pageCanonical, $base)) {
        $canonical = seo_normalise_url($pageCanonical);
        $source = 'page';
    }
    if (trim((string) ($ctx['override'] ?? '')) !== '') {
        $canonical = seo_normalise_url((string) $ctx['override']);
        $source = 'seo_meta';
    } elseif (!empty($ctx['awareness'])) {
        $canonical = (string) $ctx['awareness'];
        $source = 'awareness';
    }
    if (!empty($ctx['beyond'])) {
        $keep = (array) ($ctx['keep'] ?? []);
        $canonical = $base . ($keep ? '?' . http_build_query($keep) : '');
        $source = 'pagination';
    }
    if ($canonical === '') {
        $canonical = $self;
        $source = 'self';
    }
    return [$canonical, $source];
}

/** Canonical URL (see seo_canonical_resolve()). */
function seo_canonical(array $ctx): string
{
    return seo_canonical_resolve($ctx)[0];
}

/**
 * Full <title>. Home: the home meta title verbatim when set (CRITIC C13), else "{title or site} - {tagline}".
 * Other pages: the title plus setting seo_title_suffix when the total fits setting seo_title_max (60) and the title
 * does not already contain the site name. Admin-entered titles are never shortened.
 */
function seo_title(array $seo): string
{
    if (!empty($seo['full_title'])) {
        return (string) $seo['full_title'];
    }
    $site = site_name();
    $title = trim((string) ($seo['title'] ?? ''));
    if (!empty($seo['is_home'])) {
        if (trim((string) ($seo['home_meta_title'] ?? '')) !== '') {
            return trim((string) $seo['home_meta_title']);
        }
        return (($title !== '' && $title !== 'Home') ? $title : $site) . ' - ' . setting('site_tagline_en', 'Together, let us build a better tomorrow');
    }
    if ($title === '') {
        return $site;
    }
    $suffix = setting('seo_title_suffix', ' | ' . $site);
    $max = (int) setting('seo_title_max', '60');
    if ($max < 30 || $max > 120) {
        $max = 60;
    }
    if (stripos($title, $site) !== false || trim($suffix) === '') {
        return $title;
    }
    return mb_strlen($title . $suffix) <= $max ? $title . $suffix : $title;
}

/**
 * schema.org type of the page's #webpage node: admin choice (seo_meta.schema_type of a page) > AboutPage (about,
 * mission, NGO details, achievements, team pages) > ContactPage (contact template) > ImageGallery (photo gallery) >
 * CollectionPage (list templates, video gallery) > WebPage. Detail entities are WebPage; there is no ProfilePage
 * because the site has no individual profile pages.
 */
function seo_webpage_type(array $page, ?array $entity = null): string
{
    $etype = $entity['type'] ?? 'page';
    if ($etype !== 'page') {
        return 'WebPage';
    }
    $meta = $entity ? seo_meta_row('page', (int) $entity['id']) : [];
    $chosen = (string) ($meta['schema_type'] ?? '');
    if (in_array($chosen, ['WebPage', 'AboutPage', 'ContactPage', 'CollectionPage'], true)) {
        return $chosen;
    }
    $template = (string) ($page['template'] ?? 'default');
    $slug = (string) ($page['slug'] ?? '');
    if (in_array($slug, ['about', 'our-mission-vision', 'ngo-details', 'achievements'], true) || in_array($template, ['ngo_details', 'team'], true)) {
        return 'AboutPage';
    }
    if ($template === 'contact') {
        return 'ContactPage';
    }
    if ($template === 'gallery') {
        return page_setting($page, 'media_type', 'image') === 'video' ? 'CollectionPage' : 'ImageGallery';
    }
    if (in_array($template, ['programs', 'program_category', 'blog', 'stories', 'events', 'calendar', 'campaigns', 'schemes', 'documents'], true)) {
        return 'CollectionPage';
    }
    return 'WebPage';
}

// =====================================================================
// Request resolution (called once from page_header())
// =====================================================================

/**
 * Resolve every SEO value of the current request. $seo is page_header()'s $GLOBALS['seo'] (keys title, description,
 * canonical, og_image, og_type, schema, noindex, is_home, crumbs, title_plain are kept; the visible breadcrumb still
 * reads 'crumbs'). Adds: full_title, robots, noindex, nofollow, private, is_404, canonical (normalised), self_url,
 * canonical_source, description (+ source), entity, entity_row, meta, template, page, pagination, list, webpage_type,
 * dates, primary_image, og, empty_list, x_robots. Sends X-Robots-Tag: noindex, nofollow on private views.
 *
 * Precedence: template $opts → seo_meta override → entity column → template default → setting default. Exceptions:
 * the seo_meta values of an entity (canonical, robots, social title/description/image; story/scheme meta title and
 * description) override the template's derived values, and noindex forced for previews, drafts, tokens and searches
 * always wins - seo_meta can never make a private page indexable.
 */
function seo_finalize(array $seo, array $page, array $opts = []): array
{
    $cache = &seo_core_cache();
    unset($cache['graph']);
    $template = (string) ($page['template'] ?? 'default');
    $is404 = $template === 'not_found' || (int) http_response_code() === 404;
    $entity = seo_current_entity($page, $opts);
    $etype = (string) ($entity['type'] ?? '');
    $row = (array) ($entity['row'] ?? []);
    $meta = $entity ? seo_meta_row($etype, (int) $entity['id']) : [];
    $isList = $etype === 'page' && !$is404;
    $query = seo_query_params();
    $list = $isList ? seo_list_query($template, $query) : ['keep' => [], 'filters' => []];
    if ($isList && isset($opts['canonical_query']) && is_array($opts['canonical_query'])) {
        // the template states which list parameters it actually applied (e.g. a clamped calendar month)
        $list = seo_list_query($template, array_filter($opts['canonical_query'], 'is_string'));
    } elseif ($template === 'calendar') {
        // calendar.php clamps ?month= to the months it can show and noindexes every month but the current one; until it
        // passes canonical_query (WP-7c), use the month it displayed (its top-level $month) and drop it for the current month
        unset($list['keep']['month']);
        $shown = $GLOBALS['month'] ?? null;
        if (!empty($opts['noindex']) && is_string($shown) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $shown) && $shown !== date('Y-m')) {
            $list['keep']['month'] = $shown;
        }
    }
    $pg = $isList ? seo_pagination_state($template) : ['page' => 1, 'pages' => null, 'requested' => 1, 'beyond' => false, 'paginated' => false];
    $known = array_merge(array_keys(seo_list_query_rules($template)), ['page', 'preview']);
    $otherParams = array_values(array_diff(array_keys($query), $known));

    // ---- the address of this document (self) ----
    $path = function_exists('route_path') ? route_path() : '';
    $base = abs_url($path === '' ? '' : $path . '/');
    $selfQuery = $list['keep'];
    if ($pg['paginated'] && !$pg['beyond'] && $pg['page'] >= 2) {
        $selfQuery['page'] = (string) $pg['page'];
    }
    $self = $base . ($selfQuery ? '?' . http_build_query($selfQuery) : '');

    // ---- private views: previews, member area, donation status, token links ----
    $segments = function_exists('route_segments') ? route_segments() : [];
    $private = !empty($opts['private'])
        || ($page['status'] ?? 'published') !== 'published'
        || in_array($template, SEO_PRIVATE_TEMPLATES, true)
        || ($entity && empty($entity['public']))
        || ($etype === 'event' && in_array((string) ($segments[1] ?? ''), ['join', 'confirmation', 'file'], true))
        || (bool) array_intersect(array_map('strtolower', array_keys($query)), SEO_PRIVATE_PARAMS)
        || seo_headers_nofollow();
    $siteNoindex = setting('seo_noindex_site') === '1';

    // ---- noindex: template flag (a pure ?page=N or tracking-tag variant of a list is not a reason), page row,
    //      filters / search, beyond the last page, sign-in pages, admin choice, empty list ----
    $tplNoindex = !empty($opts['noindex']);
    if ($tplNoindex && !$private && $etype === 'page') {
        $onlyPagination = $pg['paginated'] && $pg['page'] >= 2 && !$pg['beyond'] && array_keys($query) === ['page'];
        $onlyTracking = $_GET && count(array_filter(array_keys($_GET), 'seo_is_tracking_param')) === count($_GET);
        if ($onlyPagination || $onlyTracking) {
            $tplNoindex = false;
        }
    }
    $metaIndex = seo_meta_flag($meta, 'robots_index');
    $metaFollow = seo_meta_flag($meta, 'robots_follow');
    $emptyList = false;
    if ($isList && !$private && !$list['keep'] && !$pg['beyond'] && $pg['page'] < 2 && $metaIndex !== 1
        && setting('seo_noindex_empty_lists', '1') === '1') {
        $emptyList = seo_list_is_empty($page);
    }
    $noindex = $tplNoindex || !empty($page['noindex']) || (bool) $list['filters'] || $pg['beyond']
        || in_array($template, SEO_AUTH_TEMPLATES, true) || $metaIndex === 0 || $emptyList;
    $robots = seo_robots(['is_404' => $is404, 'private' => $private, 'site_noindex' => $siteNoindex, 'noindex' => $noindex, 'nofollow' => $metaFollow === 0]);

    // ---- canonical ----
    $awareness = '';
    if ($etype === 'event' && ($row['event_type'] ?? '') === 'awareness_day' && !$private) {
        $aw = seo_awareness_canonical($row);
        if ($aw && !$aw['is_self']) {
            $awareness = $aw['url'];
        }
    }
    [$canonical, $canonicalSource] = seo_canonical_resolve([
        'is_404' => $is404, 'self' => $self, 'base' => $base, 'keep' => $list['keep'],
        'explicit' => is_string($opts['canonical'] ?? null) ? $opts['canonical'] : '',
        'page_canonical' => $etype === 'page' ? (string) ($page['canonical_url'] ?? '') : '',
        'override' => ($etype !== 'page' && $etype !== '') ? (string) ($meta['canonical_url'] ?? '') : '',
        'awareness' => $awareness, 'beyond' => $pg['beyond'],
    ]);

    // ---- title ----
    $isHome = !empty($seo['is_home']);
    $title = trim((string) ($seo['title'] ?? ''));
    if (in_array($etype, ['story', 'scheme'], true) && trim((string) ($meta['meta_title'] ?? '')) !== '') {
        $title = trim((string) $meta['meta_title']);
    }
    if ($title === '') {
        $title = trim((string) ($page['title'] ?? '')) ?: site_name();
    }
    if ($isList && $pg['page'] >= 2 && !$pg['beyond']) {
        $title = seo_page_suffix($title, (int) $pg['page']);
    }
    $seo['title'] = $title;
    $seo['home_meta_title'] = $isHome ? trim((string) ($page['meta_title'] ?? '')) : '';
    $seo['full_title'] = '';
    $seo['full_title'] = seo_title($seo);

    // ---- description ----
    $desc = (string) ($seo['description'] ?? '');
    if (isset($opts['meta_description']) && trim((string) $opts['meta_description']) !== '') {
        $descSource = 'template';
    } elseif (trim((string) ($page['meta_description'] ?? '')) !== '') {
        $descSource = 'page';
    } else {
        $descSource = 'default';
    }
    if (in_array($etype, ['story', 'scheme'], true) && trim((string) ($meta['meta_description'] ?? '')) !== '') {
        $desc = (string) $meta['meta_description'];
        $descSource = 'seo_meta';
    } elseif ($descSource === 'default' && $isList) {
        $fallback = seo_description_fallback($page);
        if ($fallback !== '') {
            $desc = $fallback;
            $descSource = 'sections';
        }
    }
    $desc = seo_plain($desc);
    if ($desc === '') {
        $desc = seo_plain(setting('seo_default_description'));
        $descSource = 'default';
    }
    $desc = excerpt($desc, 300);

    // ---- dates, main image ----
    $dates = $entity ? seo_entity_dates($etype, $row) : ['published' => null, 'modified' => null];
    $primary = null;
    if (!$is404) {
        $src = ($etype !== 'page' && $etype !== '') ? (string) ($row['image'] ?? '') : '';
        if ($src === '') {
            $src = (string) ($seo['og_image'] ?? '');
        }
        $local = seo_local_path($src);
        if ($local !== '' && seo_is_raster($local)) {
            [$w, $h] = seo_image_size($local);
            if ($w > 0) {
                $alt = function_exists('seo_image_alt') ? seo_image_alt($local) : '';
                $primary = ['path' => $local, 'url' => abs_url($local), 'width' => $w, 'height' => $h,
                    'alt' => $alt !== '' ? $alt : seo_plain($seo['title_plain'] ?? $title)];
            }
        }
    }

    $seo['_final'] = true;
    $seo['robots'] = $robots;
    $seo['noindex'] = strpos($robots, 'noindex') === 0;
    $seo['nofollow'] = strpos($robots, 'nofollow') !== false;
    $seo['private'] = $private;
    $seo['site_noindex'] = $siteNoindex;
    $seo['is_404'] = $is404;
    $seo['canonical'] = $is404 ? $self : $canonical;
    $seo['canonical_source'] = $canonicalSource;
    $seo['self_url'] = $self;
    $seo['description'] = $desc;
    $seo['description_source'] = $descSource;
    $seo['entity'] = $entity ? ['type' => $etype, 'id' => (int) $entity['id'], 'public' => !empty($entity['public'])] : null;
    $seo['entity_row'] = $row;
    $seo['meta'] = $meta;
    $seo['template'] = $template;
    $seo['page'] = $page;
    $seo['pagination'] = $pg;
    $seo['list'] = $list + ['other' => $otherParams];
    $seo['empty_list'] = $emptyList;
    $seo['webpage_type'] = seo_webpage_type($page, $entity);
    $seo['dates'] = $dates;
    $seo['primary_image'] = $primary;
    $seo['og'] = seo_og_build($seo, !$list['keep'] && $pg['page'] < 2);
    $seo['x_robots'] = (($private || $siteNoindex) && !$is404) ? 'noindex, nofollow' : '';
    if ($seo['x_robots'] !== '' && !headers_sent()) {
        header('X-Robots-Tag: ' . $seo['x_robots']);
    }
    return $seo;
}

/** The finalised SEO state of this request (finalises $GLOBALS['seo'] lazily when page_header() has no hook). */
function seo_state(): array
{
    if (empty($GLOBALS['seo']['_final'])) {
        $raw = $GLOBALS['seo'] ?? ['title' => site_name(), 'description' => setting('seo_default_description'), 'canonical' => abs_url(''),
            'og_image' => '', 'og_type' => 'website', 'noindex' => false, 'crumbs' => [], 'schema' => []];
        $page = function_exists('current_page_row') ? (current_page_row() ?: []) : [];
        $opts = [];
        if (!empty($raw['noindex'])) {
            $opts['noindex'] = true;
        }
        if (!empty($raw['canonical'])) {
            $opts['canonical'] = (string) $raw['canonical'];
        }
        $GLOBALS['seo'] = seo_finalize($raw, $page, $opts);
    }
    return $GLOBALS['seo'];
}

// =====================================================================
// Social tags (SEO-DESIGN 4.8)
// =====================================================================

/** One social image candidate: ['path', 'url', 'width', 'height', 'mime', 'alt']. */
function seo_og_image_entry(string $rel, string $alt, int $w = 0, int $h = 0): array
{
    [$sw, $sh, $mime] = seo_image_size($rel);
    if ($w <= 0 || $h <= 0) {
        $w = $sw;
        $h = $sh;
    }
    return ['path' => $rel, 'url' => abs_url($rel), 'width' => $w, 'height' => $h, 'mime' => $mime, 'alt' => $alt];
}

/**
 * Social image: seo_meta og_image (entities) → page / entity image (raster; WP-3 makes a 1200x630 crop) → WP-3 static
 * card of the page (or of an event's category) → seo_default_og_image → og-default.jpg → logo. SVG never.
 * Alt: seo_meta og_image_alt → media library alt (WP-3) → static card alt → default card alt (setting) → ''.
 */
function seo_og_pick_image(array $seo, bool $baseView): array
{
    $etype = (string) ($seo['entity']['type'] ?? '');
    $row = (array) ($seo['entity_row'] ?? []);
    $page = (array) ($seo['page'] ?? []);
    $meta = (array) ($seo['meta'] ?? []);
    $metaAlt = trim((string) ($meta['og_image_alt'] ?? ''));
    $candidates = [];
    if ($baseView && $etype !== 'page' && trim((string) ($meta['og_image'] ?? '')) !== '') {
        $candidates[] = (string) $meta['og_image'];
    }
    if (trim((string) ($seo['og_image'] ?? '')) !== '') {
        $candidates[] = (string) $seo['og_image'];
    }
    foreach ($candidates as $src) {
        if (preg_match('/\.svg(\?|#|$)/i', $src)) {
            continue;
        }
        $local = seo_local_path($src);
        if ($local === '') {
            if (preg_match('#^https?://[^\s"<>]+$#i', $src) && !seo_is_own_url($src)) {
                return ['path' => '', 'url' => $src, 'width' => 0, 'height' => 0, 'mime' => '', 'alt' => $metaAlt];   // external image URL (as today)
            }
            continue;
        }
        if (!seo_is_raster($local)) {
            continue;
        }
        $alt = $metaAlt !== '' ? $metaAlt : (function_exists('seo_image_alt') ? seo_image_alt($local) : '');
        if (function_exists('seo_og_image') && strpos($local, 'uploads/') === 0) {
            $r = seo_og_image($local, false);
            $rel = (string) ($r[0] ?? ($r['path'] ?? ''));
            if ($rel !== '' && is_file(ROOT_PATH . '/' . $rel)) {
                return seo_og_image_entry($rel, $alt !== '' ? $alt : (string) ($r['alt'] ?? ''), (int) ($r[1] ?? 0), (int) ($r[2] ?? 0));
            }
            continue;   // too small for a social card: static or default card instead
        }
        return seo_og_image_entry($local, $alt);
    }
    if (function_exists('seo_static_og')) {
        $slugs = [];
        if ($etype === 'event' && !empty($row['category_id'])) {
            try {
                $catSlug = (string) db_value('SELECT p.slug FROM program_categories c JOIN pages p ON p.id = c.page_id WHERE c.id = ?', [(int) $row['category_id']]);
            } catch (Throwable $e) {
                $catSlug = '';
            }
            if ($catSlug !== '') {
                $slugs[] = $catSlug;
            }
        }
        $slugs[] = (string) ($page['slug'] ?? '');
        foreach ($slugs as $s) {
            $rel = $s !== '' ? seo_static_og($s) : null;
            if ($rel) {
                $alt = $metaAlt !== '' ? $metaAlt : (function_exists('seo_static_og_alt') ? seo_static_og_alt($s) : '');
                return seo_og_image_entry($rel, $alt);
            }
        }
    }
    if (function_exists('seo_og_default')) {
        $d = seo_og_default();
        if (!empty($d[0]) && is_file(ROOT_PATH . '/' . $d[0])) {
            return seo_og_image_entry((string) $d[0], (string) ($d['alt'] ?? ''), (int) ($d[1] ?? 0), (int) ($d[2] ?? 0));
        }
    }
    $settingAlt = trim(setting('seo_default_og_image_alt'));
    $default = seo_local_path(setting('seo_default_og_image'));
    if ($default !== '' && seo_is_raster($default)) {
        return seo_og_image_entry($default, $settingAlt !== '' ? $settingAlt : (function_exists('seo_image_alt') ? seo_image_alt($default) : ''));
    }
    if (is_file(ROOT_PATH . '/assets/images/og-default.jpg')) {
        return seo_og_image_entry('assets/images/og-default.jpg', $settingAlt !== '' ? $settingAlt
            : 'Prerit Welfare Foundation logo with the tagline Empowering Communities, Spreading Hope, Creating Change');
    }
    return seo_og_image_entry('assets/images/logo.png', site_name() . ' logo');
}

/** Open Graph / Twitter values of the request (stored in $seo['og'] by seo_finalize()). */
function seo_og_build(array $seo, bool $baseView): array
{
    $etype = (string) ($seo['entity']['type'] ?? '');
    $row = (array) ($seo['entity_row'] ?? []);
    $meta = (array) ($seo['meta'] ?? []);
    $type = in_array($etype, ['post', 'story'], true) || ($seo['og_type'] ?? '') === 'article' ? 'article' : 'website';
    $title = !empty($seo['is_home']) ? (string) $seo['full_title'] : (string) $seo['title'];
    if ($baseView && trim((string) ($meta['og_title'] ?? '')) !== '') {
        $title = trim((string) $meta['og_title']);
    }
    $desc = $baseView && trim((string) ($meta['og_description'] ?? '')) !== '' ? seo_plain($meta['og_description']) : excerpt((string) $seo['description'], 200);
    $img = seo_og_pick_image($seo, $baseView);
    if ($img['alt'] === '') {
        $img['alt'] = $title;
    }
    $article = [];
    if (in_array($etype, ['post', 'story'], true)) {
        $article['published_time'] = $seo['dates']['published'] ?? null;
        $article['modified_time'] = $seo['dates']['modified'] ?? null;
        $article['section'] = $etype === 'post'
            ? ((defined('BLOG_CATEGORIES') ? (BLOG_CATEGORIES[$row['category'] ?? ''] ?? '') : '') ?: 'Articles') : 'Success stories';
    }
    return [
        'type' => $type, 'title' => $title, 'description' => $desc, 'url' => empty($seo['is_404']) ? (string) $seo['canonical'] : '',
        'image' => $img['url'], 'image_path' => $img['path'], 'width' => (int) $img['width'], 'height' => (int) $img['height'],
        'mime' => (string) $img['mime'], 'alt' => (string) $img['alt'],
        'large' => !$img['width'] || ($img['width'] >= 600 && $img['width'] !== $img['height']),
        'article' => array_filter($article),
    ];
}

// =====================================================================
// <head> output
// =====================================================================

/**
 * The SEO part of <head>. $part: 'meta' (title, description, robots, canonical, Open Graph, Twitter, verification),
 * 'graph' (the JSON-LD @graph) or 'all'. Every value is escaped; JSON goes through json_ld() (JSON_HEX_TAG|AMP).
 * The theme-color, icons, preloads and stylesheets stay in header.php.
 */
function seo_head_tags(string $part = 'all'): string
{
    $seo = seo_state();
    $out = '';
    if ($part === 'all' || $part === 'meta') {
        $out .= seo_head_meta($seo);
    }
    if ($part === 'all' || $part === 'graph') {
        $out .= '    ' . json_ld(seo_graph_document()) . "\n";
        $GLOBALS['seo_graph_printed'] = true;
    }
    return $out;
}

/** Title ... verification tags (see seo_head_tags()). */
function seo_head_meta(array $seo): string
{
    $og = (array) ($seo['og'] ?? []);
    $l = [];
    $l[] = '<title>' . e($seo['full_title'] ?? seo_title($seo)) . '</title>';
    if (($seo['description'] ?? '') !== '') {
        $l[] = '<meta name="description" content="' . e($seo['description']) . '">';
    }
    $l[] = '<meta name="robots" content="' . e($seo['robots'] ?? 'index, follow') . '">';
    if (empty($seo['is_404']) && ($seo['canonical'] ?? '') !== '') {
        $l[] = '<link rel="canonical" href="' . e($seo['canonical']) . '">';
    }
    $l[] = '<meta property="og:type" content="' . e($og['type'] ?? 'website') . '">';
    $l[] = '<meta property="og:site_name" content="' . e(site_name()) . '">';
    $l[] = '<meta property="og:locale" content="en_IN">';
    $l[] = '<meta property="og:title" content="' . e($og['title'] ?? '') . '">';
    $l[] = '<meta property="og:description" content="' . e($og['description'] ?? '') . '">';
    if (($og['url'] ?? '') !== '') {
        $l[] = '<meta property="og:url" content="' . e($og['url']) . '">';
    }
    $l[] = '<meta property="og:image" content="' . e($og['image'] ?? '') . '">';
    if (($og['mime'] ?? '') !== '') {
        $l[] = '<meta property="og:image:type" content="' . e($og['mime']) . '">';
    }
    if (!empty($og['width']) && !empty($og['height'])) {
        $l[] = '<meta property="og:image:width" content="' . (int) $og['width'] . '">';
        $l[] = '<meta property="og:image:height" content="' . (int) $og['height'] . '">';
    }
    $l[] = '<meta property="og:image:alt" content="' . e($og['alt'] ?? '') . '">';
    foreach ((array) ($og['article'] ?? []) as $k => $v) {
        $l[] = '<meta property="article:' . e($k) . '" content="' . e($v) . '">';
    }
    $l[] = '<meta name="twitter:card" content="' . (!empty($og['large']) ? 'summary_large_image' : 'summary') . '">';
    $handle = ltrim(trim(setting('twitter_handle')), '@');
    if ($handle !== '' && preg_match('/^\w{1,15}$/', $handle)) {
        $l[] = '<meta name="twitter:site" content="@' . e($handle) . '">';
    }
    $l[] = '<meta name="twitter:title" content="' . e($og['title'] ?? '') . '">';
    $l[] = '<meta name="twitter:description" content="' . e($og['description'] ?? '') . '">';
    $l[] = '<meta name="twitter:image" content="' . e($og['image'] ?? '') . '">';
    $l[] = '<meta name="twitter:image:alt" content="' . e($og['alt'] ?? '') . '">';
    if (function_exists('seo_verification_tags')) {
        foreach (preg_split('/\R/', trim(seo_verification_tags())) as $tag) {
            if (trim($tag) !== '') {
                $l[] = trim($tag);
            }
        }
    } elseif (setting('google_site_verification') !== '') {
        $l[] = '<meta name="google-site-verification" content="' . e(setting('google_site_verification')) . '">';
    }
    return '    ' . implode("\n    ", $l) . "\n";
}

// =====================================================================
// Schema graph (SEO-DESIGN 4.5)
// =====================================================================

/** Add a JSON-LD node to this page's graph (WP-3/WP-6/templates). Call before page_header(): the graph is printed in <head>. */
function seo_graph_add(array $node): void
{
    if (!$node) {
        return;
    }
    $GLOBALS['seo_graph_extra'][] = $node;
    $cache = &seo_core_cache();
    unset($cache['graph']);
}

/** The JSON-LD document: {"@context": "https://schema.org", "@graph": [...]}. */
function seo_graph_document(): array
{
    return ['@context' => 'https://schema.org', '@graph' => seo_graph()];
}

/**
 * All nodes of this page: NGO #organization, #logo, WebSite #website, then (not on 404) the page's WebPage
 * {self}#webpage, BreadcrumbList #breadcrumb (not on home), ImageObject #primaryimage (raster images only), and the
 * template / seo_graph_add() nodes merged in (seo_merge_template_schema()). @id references always resolve in the graph.
 */
function seo_graph(): array
{
    $cache = &seo_core_cache();
    if (isset($cache['graph'])) {
        return $cache['graph'];
    }
    $seo = seo_state();
    $graph = [];
    foreach ([seo_org_node(), seo_logo_node(), seo_website_node()] as $n) {
        $graph[$n['@id']] = $n;
    }
    if (empty($seo['is_404'])) {
        $self = (string) $seo['self_url'];
        if (!empty($seo['primary_image'])) {
            $img = seo_image_node((string) $seo['primary_image']['path'], $self . '#primaryimage', (string) $seo['primary_image']['alt']);
            if ($img) {
                $graph[$img['@id']] = $img;
            }
        }
        $wp = seo_webpage_node($seo);
        $graph[$wp['@id']] = $wp;
        if (empty($seo['is_home'])) {
            $bc = seo_breadcrumb_node($seo);
            $graph[$bc['@id']] = $bc;
        }
        $graph = seo_merge_template_schema(array_merge((array) ($seo['schema'] ?? []), (array) ($GLOBALS['seo_graph_extra'] ?? [])), $graph, $seo);
    }
    // drop references to nodes that are not in the graph (e.g. a breadcrumb that was not built)
    $ids = array_keys($graph);
    foreach ($graph as $id => $node) {
        foreach (['breadcrumb', 'primaryImageOfPage', 'image', 'mainEntity', 'about', 'isPartOf'] as $k) {
            if (isset($node[$k]) && is_array($node[$k]) && count($node[$k]) === 1 && isset($node[$k]['@id'])
                && strpos((string) $node[$k]['@id'], rtrim(BASE_URL, '/')) === 0 && !in_array($node[$k]['@id'], $ids, true)) {
                unset($graph[$id][$k]);
            }
        }
    }
    return $cache['graph'] = array_values($graph);
}

/** The organisation (NGO) from organization_schema(): verified settings only, logo → #logo; founding date / area served only when set. */
function seo_org_node(): array
{
    $id = abs_url('#organization');
    $org = function_exists('organization_schema') ? organization_schema() : ['@type' => 'NGO', 'name' => site_name(), 'url' => abs_url('')];
    unset($org['@context'], $org['nonprofitStatus'], $org['aggregateRating'], $org['review']);
    $org['@id'] = $id;
    $org['logo'] = ['@id' => abs_url('#logo')];
    $org['image'] = ['@id' => abs_url('#logo')];
    $founding = trim(setting('seo_org_founding_date'));
    if (preg_match('/^\d{4}(-(0[1-9]|1[0-2])(-(0[1-9]|[12]\d|3[01]))?)?$/', $founding)) {
        $org['foundingDate'] = $founding;
    }
    $area = trim(setting('seo_org_area_served'));
    if ($area !== '') {
        $parts = array_values(array_filter(array_map('trim', preg_split('/[|;\n]/', $area))));
        if ($parts) {
            $org['areaServed'] = count($parts) === 1 ? $parts[0] : $parts;
        }
    }
    return $org;
}

/** ImageObject #logo: the uploaded logo (setting logo_path) or the built-in logo, with its size. */
function seo_logo_node(): array
{
    $src = function_exists('site_logo') ? site_logo() : url('assets/images/logo.png');
    $abs = seo_abs($src);
    $node = ['@type' => 'ImageObject', '@id' => abs_url('#logo'), 'url' => $abs, 'contentUrl' => $abs, 'caption' => site_name()];
    $local = seo_local_path($src);
    if ($local !== '') {
        [$w, $h] = seo_image_size($local);
        if ($w > 0 && $h > 0) {
            $node['width'] = $w;
            $node['height'] = $h;
        }
    }
    return $node;
}

/** WebSite #website (no SearchAction: the site has no site-wide search). */
function seo_website_node(): array
{
    return ['@type' => 'WebSite', '@id' => abs_url('#website'), 'url' => abs_url(''), 'name' => site_name(), 'inLanguage' => 'en-IN',
        'publisher' => ['@id' => abs_url('#organization')]];
}

/** WebPage (or subtype) {self}#webpage of the current page. */
function seo_webpage_node(array $seo): array
{
    $self = (string) $seo['self_url'];
    $name = trim((string) ($seo['title'] ?? ''));
    if (!empty($seo['is_home'])) {
        $name = trim((string) ($seo['home_meta_title'] ?? '')) ?: site_name();
    }
    $node = ['@type' => (string) ($seo['webpage_type'] ?? 'WebPage'), '@id' => $self . '#webpage', 'url' => $self, 'name' => $name !== '' ? $name : site_name(),
        'isPartOf' => ['@id' => abs_url('#website')], 'inLanguage' => 'en-IN'];
    if (($seo['description'] ?? '') !== '') {
        $node['description'] = (string) $seo['description'];
    }
    if (empty($seo['is_home'])) {
        $node['breadcrumb'] = ['@id' => $self . '#breadcrumb'];
    }
    if (!empty($seo['primary_image'])) {
        $node['primaryImageOfPage'] = ['@id' => $self . '#primaryimage'];
        $node['image'] = ['@id' => $self . '#primaryimage'];
    }
    if (!empty($seo['dates']['published'])) {
        $node['datePublished'] = $seo['dates']['published'];
    }
    if (!empty($seo['dates']['modified'])) {
        $node['dateModified'] = $seo['dates']['modified'];
    }
    if (!empty($seo['is_home']) || $node['@type'] === 'AboutPage') {
        $node['about'] = ['@id' => abs_url('#organization')];
    }
    $fields = seo_meta_json((array) ($seo['meta'] ?? []), 'schema_fields');
    if (!empty($fields['about_name']) && !isset($node['about'])) {
        $node['about'] = ['@type' => 'Thing', 'name' => (string) $fields['about_name']];
    }
    return $node;
}

/** BreadcrumbList {self}#breadcrumb: Home, the page's crumbs (as shown on the page), the page itself. */
function seo_breadcrumb_node(array $seo): array
{
    $self = (string) $seo['self_url'];
    $items = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => abs_url('')]];
    foreach ((array) ($seo['crumbs'] ?? []) as $c) {
        if (!is_array($c) || count($c) < 2) {
            continue;
        }
        $c = array_values($c);
        $label = seo_plain($c[0]);
        $href = trim((string) $c[1]);
        if ($label === '' || $href === '' || $href[0] === '#') {
            continue;
        }
        $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1, 'name' => $label, 'item' => seo_abs($href)];
    }
    $current = seo_plain($seo['title_plain'] ?? ($seo['title'] ?? ''));
    $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1, 'name' => $current !== '' ? $current : site_name(), 'item' => $self];
    return ['@type' => 'BreadcrumbList', '@id' => $self . '#breadcrumb', 'itemListElement' => $items];
}

/** ImageObject for a local raster image ([] when the file is not usable). */
function seo_image_node(string $path, string $id, string $alt = ''): array
{
    $local = seo_local_path($path);
    if ($local === '' || !seo_is_raster($local)) {
        return [];
    }
    [$w, $h] = seo_image_size($local);
    $abs = abs_url($local);
    $node = ['@type' => 'ImageObject', '@id' => $id, 'url' => $abs, 'contentUrl' => $abs];
    if ($w > 0 && $h > 0) {
        $node['width'] = $w;
        $node['height'] = $h;
    }
    if (trim($alt) !== '') {
        $node['caption'] = seo_plain($alt);
    }
    return $node;
}

/** Template nodes, flattened (a node, a list of nodes or {"@graph": [...]}). */
function seo_flatten_nodes(array $nodes): array
{
    if (isset($nodes['@type']) || isset($nodes['@graph'])) {
        $nodes = [$nodes];
    }
    $out = [];
    foreach ($nodes as $n) {
        if (!is_array($n)) {
            continue;
        }
        if (isset($n['@graph']) && is_array($n['@graph'])) {
            foreach (seo_flatten_nodes($n['@graph']) as $g) {
                $out[] = $g;
            }
            continue;
        }
        $out[] = $n;
    }
    return $out;
}

/** First @type of a node ('' when none). */
function seo_node_type(array $node): string
{
    $t = $node['@type'] ?? '';
    if (is_array($t)) {
        $t = reset($t);
    }
    return is_string($t) ? $t : '';
}

/**
 * Clean a template node (recursive): no @context below the top, forbidden types and keys removed, inline copies of the
 * organisation replaced by {"@id": "#organization"}, meeting links / mailto links / e-mail and phone properties removed.
 * Returns null when the node must be dropped.
 */
function seo_clean_node($value, string $orgId, int $depth = 0)
{
    if ($depth > 12) {
        return null;
    }
    if (is_string($value)) {
        if (preg_match(SEO_MEETING_HOSTS, $value) || preg_match('/^(mailto:)?[^\s@<>]+@[^\s@<>]+\.[a-z]{2,}$/i', trim($value)) || stripos(trim($value), 'tel:') === 0) {
            return null;
        }
        return $value;
    }
    if (!is_array($value)) {
        return $value;
    }
    if ($value === []) {
        return [];
    }
    if (array_keys($value) === range(0, count($value) - 1)) {
        $out = [];
        foreach ($value as $item) {
            $c = seo_clean_node($item, $orgId, $depth + 1);
            if ($c !== null && $c !== []) {
                $out[] = $c;
            }
        }
        return $out;
    }
    unset($value['@context']);
    $types = array_filter((array) ($value['@type'] ?? []), 'is_string');
    if (array_intersect($types, SEO_FORBIDDEN_TYPES)) {
        return null;
    }
    if ($depth > 0 && (($value['@id'] ?? '') === $orgId
            || (array_intersect($types, ['NGO', 'Organization']) && rtrim((string) ($value['url'] ?? ''), '/') === rtrim(abs_url(''), '/')))) {
        return ['@id' => $orgId];
    }
    foreach (array_merge(SEO_FORBIDDEN_KEYS, ['email', 'telephone', 'faxNumber']) as $k) {
        unset($value[$k]);
    }
    foreach ($value as $k => $item) {
        $c = seo_clean_node($item, $orgId, $depth + 1);
        if ($c === null || $c === []) {
            unset($value[$k]);
        } else {
            $value[$k] = $c;
        }
    }
    return $value;
}

/** Does a WebPage-family template node describe this page (no url/@id, or same path as self or canonical)? */
function seo_node_is_this_page(array $node, string $self, string $canonical): bool
{
    $id = is_string($node['@id'] ?? null) ? (string) preg_replace('/#.*$/', '', $node['@id']) : '';
    $url = is_string($node['url'] ?? null) ? (string) $node['url'] : '';
    if ($id === '' && $url === '') {
        return true;
    }
    foreach ([$id, $url] as $u) {
        if ($u !== '' && (seo_same_path($u, $self) || ($canonical !== '' && seo_same_path($u, $canonical)))) {
            return true;
        }
    }
    return false;
}

/** @id fragment for a node type: #article (Article family), #event (Event family), else the lower-case type. */
function seo_node_fragment(string $type): string
{
    if (in_array($type, SEO_ARTICLE_TYPES, true)) {
        return 'article';
    }
    if (in_array($type, SEO_EVENT_TYPES, true)) {
        return 'event';
    }
    $f = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $type));
    return $f !== '' ? $f : 'thing';
}

/** Add keys of $b that $a lacks. */
function seo_merge_missing(array $a, array $b): array
{
    foreach ($b as $k => $v) {
        if (!array_key_exists($k, $a)) {
            $a[$k] = $v;
        }
    }
    return $a;
}

/**
 * Merge template nodes ($opts['schema'] arrays, seo_graph_add() nodes) into the generated graph (keyed by @id):
 *  - a WebPage-family node of this page is merged into {self}#webpage: the generated name, url, description, isPartOf,
 *    breadcrumb, primary image and language stay; the template's other properties win (about, dateModified,
 *    lastReviewed, reviewedBy, mainEntity, potentialAction ...); a more specific template type replaces WebPage
 *    unless an admin chose the type;
 *  - every other node gets an @id ({self}#<type>) when it has none and is merged by @id (no duplicates);
 *    Article / Event / Service nodes get mainEntityOfPage → #webpage and become the page's mainEntity; ItemList too;
 *  - posts become BlogPosting / NewsArticle (or the admin's type), with articleSection and wordCount;
 *  - Event offers are dropped unless registration is open now (CRITIC G-SD2); a PIN code in a free-text venue address
 *    becomes postalCode / addressLocality / addressRegion;
 *  - see seo_clean_node() for what is always removed.
 */
function seo_merge_template_schema(array $nodes, array $graph = [], array $seo = []): array
{
    if (!$seo) {
        $seo = seo_state();
    }
    $self = (string) ($seo['self_url'] ?? abs_url(''));
    $canonical = (string) ($seo['canonical'] ?? $self);
    $wpId = $self . '#webpage';
    $orgId = abs_url('#organization');
    foreach (seo_flatten_nodes($nodes) as $node) {
        $node = seo_clean_node($node, $orgId);
        if (!is_array($node) || !$node) {
            continue;
        }
        $type = seo_node_type($node);
        $id = is_string($node['@id'] ?? null) ? (string) $node['@id'] : '';
        if (in_array($type, SEO_WEBPAGE_TYPES, true) && isset($graph[$wpId]) && ($id === $wpId || seo_node_is_this_page($node, $self, $canonical))) {
            $graph[$wpId] = seo_merge_webpage($graph[$wpId], $node, $seo);
            continue;
        }
        if ($id === $orgId) {
            $graph[$orgId] = seo_merge_missing($graph[$orgId] ?? [], $node);
            continue;
        }
        if ($id === '') {
            $id = $self . '#' . seo_node_fragment($type);
            for ($i = 2; isset($graph[$id]); $i++) {
                $id = $self . '#' . seo_node_fragment($type) . '-' . $i;
            }
        }
        $node['@id'] = $id;
        $node = seo_adapt_entity_node($node, seo_node_type($node), $seo, $wpId);
        $type = seo_node_type($node);
        $graph[$id] = isset($graph[$id]) ? seo_merge_missing($graph[$id], $node) : $node;
        if (isset($graph[$wpId]) && !isset($graph[$wpId]['mainEntity'])
            && (in_array($type, array_merge(SEO_ARTICLE_TYPES, SEO_EVENT_TYPES, ['Service', 'ItemList']), true))) {
            $graph[$wpId]['mainEntity'] = ['@id' => $id];
        }
    }
    return $graph;
}

/** Merge a template WebPage-family node into the generated #webpage (see seo_merge_template_schema()). */
function seo_merge_webpage(array $gen, array $tpl, array $seo): array
{
    $keep = ['@id', 'url', 'name', 'description', 'isPartOf', 'breadcrumb', 'primaryImageOfPage', 'inLanguage', 'image'];
    foreach ($tpl as $k => $v) {
        if ($k === '@type' || ($k === '@id' || $k === 'url') || (in_array($k, $keep, true) && isset($gen[$k]))) {
            continue;
        }
        $gen[$k] = $v;
    }
    $tplType = seo_node_type($tpl);
    $adminType = ($seo['entity']['type'] ?? '') === 'page' && in_array((string) ($seo['meta']['schema_type'] ?? ''), ['AboutPage', 'ContactPage', 'CollectionPage', 'WebPage'], true);
    if (!$adminType && $tplType !== '' && !in_array($tplType, ['WebPage', 'FAQPage'], true)) {
        $gen['@type'] = $tplType;
    }
    return $gen;
}

/** Entity-specific fixes of a template node (Article / Event / Service): see seo_merge_template_schema(). */
function seo_adapt_entity_node(array $node, string $type, array $seo, string $wpId): array
{
    $etype = (string) ($seo['entity']['type'] ?? '');
    $row = (array) ($seo['entity_row'] ?? []);
    $meta = (array) ($seo['meta'] ?? []);
    $self = (string) ($seo['self_url'] ?? '');
    if (in_array($type, array_merge(SEO_ARTICLE_TYPES, SEO_EVENT_TYPES, ['Service']), true)) {
        $node['mainEntityOfPage'] = ['@id' => $wpId];
    }
    if (in_array($type, SEO_ARTICLE_TYPES, true) && in_array($etype, ['post', 'story'], true)) {
        $chosen = (string) ($meta['schema_type'] ?? '');
        if (in_array($chosen, seo_schema_types($etype), true)) {
            $node['@type'] = $chosen;
        } elseif ($etype === 'post') {
            $node['@type'] = ['blog' => 'BlogPosting', 'news' => 'NewsArticle'][$row['category'] ?? ''] ?? 'Article';
        }
        if (!isset($node['articleSection'])) {
            $node['articleSection'] = $etype === 'post'
                ? ((defined('BLOG_CATEGORIES') ? (BLOG_CATEGORIES[$row['category'] ?? ''] ?? '') : '') ?: 'Articles') : 'Success stories';
        }
        if (!isset($node['wordCount']) && trim((string) ($row['content'] ?? '')) !== '') {
            $node['wordCount'] = seo_word_total((string) $row['content']);
        }
        if (!empty($seo['primary_image'])) {
            $node['image'] = ['@id' => $self . '#primaryimage'];
        }
        if (!isset($node['about']) && !empty($row['program_id']) && function_exists('content_program') && function_exists('program_url')) {
            $prog = content_program((int) $row['program_id']);
            if ($prog) {
                $node['about'] = ['@type' => 'Thing', 'name' => (string) $prog['title'], 'url' => seo_abs(program_url($prog))];
            }
        }
        if (!isset($node['inLanguage'])) {
            $node['inLanguage'] = 'en-IN';
        }
    }
    if (in_array($type, SEO_EVENT_TYPES, true) && $etype === 'event') {
        $chosen = (string) ($meta['schema_type'] ?? '');
        if (in_array($chosen, seo_schema_types('event'), true)) {
            $node['@type'] = $chosen;
        }
        if (isset($node['offers']) && function_exists('event_state') && $row) {
            $state = event_state($row);
            if (empty($state['can_register'])) {
                unset($node['offers']);
            }
        }
        if (isset($node['location'])) {
            $node['location'] = seo_fix_event_location($node['location']);
        }
    }
    return $node;
}

/** Place addresses written as free text: PIN code → postalCode, locality and Indian state split out (rest = street). */
function seo_fix_event_location($location)
{
    if (!is_array($location)) {
        return $location;
    }
    if (array_keys($location) === range(0, count($location) - 1)) {
        return array_map('seo_fix_event_location', $location);
    }
    if (($location['@type'] ?? '') === 'Place' && is_array($location['address'] ?? null) && empty($location['address']['postalCode'])
        && !empty($location['address']['streetAddress'])) {
        $location['address'] = array_merge($location['address'], seo_parse_address((string) $location['address']['streetAddress']));
    }
    return $location;
}

/** Structured parts of an Indian address written as one line; [] unless it contains a 6-digit PIN code. */
function seo_parse_address(string $text): array
{
    $text = trim((string) preg_replace('/\s+/', ' ', $text));
    if (!preg_match('/\b(\d{3}\s?\d{3})\b/', $text, $m)) {
        return [];
    }
    $out = ['postalCode' => str_replace(' ', '', $m[1])];
    $rest = str_replace($m[0], '', $text);
    $parts = array_values(array_filter(array_map(function ($p) {
        return trim($p, " -\t");
    }, explode(',', $rest)), function ($p) {
        return $p !== '' && !preg_match('/^india$/i', $p);
    }));
    if ($parts) {
        $last = $parts[count($parts) - 1];
        foreach (SEO_IN_REGIONS as $region) {
            if (strcasecmp($last, $region) === 0) {
                $out['addressRegion'] = $region;
                array_pop($parts);
                break;
            }
        }
    }
    if ($parts) {
        $out['addressLocality'] = array_pop($parts);
    }
    $out['streetAddress'] = $parts ? implode(', ', $parts) : ($out['addressLocality'] ?? $text);
    $out['addressCountry'] = 'IN';
    return $out;
}

// =====================================================================
// FAQ markup: one page per Q&A (CRITIC C6)
// =====================================================================

/**
 * May this page print FAQPage markup? Setting seo_faq_schema_scope: 'faq_page' (default) = only the FAQ template and
 * pages whose seo_meta has schema_fields.faq_markup = 1 (or schema_type FAQPage); 'all' = every page with Q&A sections
 * (old behaviour, not recommended: the same question is marked up on many pages); 'none' = never.
 */
function seo_faq_allowed(array $seo): bool
{
    if (!empty($seo['is_404']) || !empty($seo['noindex'])) {
        return false;
    }
    $scope = setting('seo_faq_schema_scope', 'faq_page');
    if ($scope === 'none') {
        return false;
    }
    if ($scope === 'all' || ($seo['template'] ?? '') === 'faq') {
        return true;
    }
    if (($seo['entity']['type'] ?? '') !== 'page') {
        return false;
    }
    $fields = seo_meta_json((array) ($seo['meta'] ?? []), 'schema_fields');
    return !empty($fields['faq_markup']) || ($seo['meta']['schema_type'] ?? '') === 'FAQPage';
}

/**
 * FAQPage JSON-LD of the Q&A rendered on this page ($GLOBALS['seo_faqs'], filled by FAQ sections), or null.
 * Printed in the footer (faq_schema() hook) with @id {self}#faq and isPartOf the page's #webpage.
 */
function seo_faq_schema(): ?array
{
    $seo = seo_state();
    if (!seo_faq_allowed($seo)) {
        return null;
    }
    $items = [];
    $seen = [];
    foreach ((array) ($GLOBALS['seo_faqs'] ?? []) as $f) {
        $q = seo_plain($f['q'] ?? '');
        $a = trim((string) ($f['a'] ?? ''));
        if ($q === '' || seo_plain($a) === '' || isset($seen[mb_strtolower($q)])) {
            continue;
        }
        $seen[mb_strtolower($q)] = true;
        $items[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
        if (count($items) >= 30) {
            break;
        }
    }
    if (!$items) {
        return null;
    }
    $self = (string) $seo['self_url'];
    $node = seo_clean_node(['@type' => 'FAQPage', '@id' => $self . '#faq', 'url' => $self, 'inLanguage' => 'en-IN',
        'isPartOf' => ['@id' => $self . '#webpage'], 'mainEntity' => $items], abs_url('#organization'));
    return $node ? ['@context' => 'https://schema.org'] + $node : null;
}

// =====================================================================
// For other packages
// =====================================================================

/**
 * For sitemaps (WP-2) and the admin inventory (WP-4): false when the SEO rules keep this entity out of the index or
 * point its canonical elsewhere: seo_meta robots_index = 0, a seo_meta canonical to another URL, a non-canonical
 * awareness-day edition; pages also: noindex, sign-in / private templates, a canonical_url to another page, the
 * empty-list rule. Publication status (draft, scheduled, consent) is the caller's job.
 */
function seo_entity_indexable(string $type, array $row): bool
{
    $id = (int) ($row['id'] ?? 0);
    $meta = $id > 0 ? seo_meta_row($type, $id) : [];
    if (seo_meta_flag($meta, 'robots_index') === 0) {
        return false;
    }
    if ($type === 'page') {
        $template = (string) ($row['template'] ?? '');
        if (!empty($row['noindex']) || in_array($template, array_merge(SEO_AUTH_TEMPLATES, SEO_PRIVATE_TEMPLATES, ['not_found']), true)) {
            return false;
        }
        $own = trim((string) ($row['canonical_url'] ?? ''));
        if ($own !== '' && !seo_same_path($own, seo_entity_url('page', $row))) {
            return false;
        }
        if (setting('seo_noindex_empty_lists', '1') === '1' && seo_meta_flag($meta, 'robots_index') !== 1 && seo_list_is_empty($row)) {
            return false;
        }
        return true;
    }
    $override = trim((string) ($meta['canonical_url'] ?? ''));
    if ($override !== '' && seo_normalise_url($override) !== seo_normalise_url(seo_entity_url($type, $row))) {
        return false;
    }
    if ($type === 'event' && ($row['event_type'] ?? '') === 'awareness_day') {
        $c = seo_awareness_canonical($row);
        if ($c && !$c['is_self']) {
            return false;
        }
    }
    return true;
}

// =====================================================================
// Migrations 800-809 (WP-1)
// =====================================================================

/** Per-entity SEO overrides (SEO-DESIGN 4.1). No foreign keys: rows point at several tables. */
function migration_800_seo_meta(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS seo_meta (
        id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
        entity_type         VARCHAR(30)  NOT NULL,
        entity_id           INT UNSIGNED NOT NULL,
        meta_title          VARCHAR(200) DEFAULT NULL,
        meta_description    VARCHAR(320) DEFAULT NULL,
        canonical_url       VARCHAR(255) DEFAULT NULL,
        robots_index        TINYINT(1)   DEFAULT NULL,
        robots_follow       TINYINT(1)   DEFAULT NULL,
        og_title            VARCHAR(200) DEFAULT NULL,
        og_description      VARCHAR(300) DEFAULT NULL,
        og_image            VARCHAR(255) DEFAULT NULL,
        og_image_alt        VARCHAR(255) DEFAULT NULL,
        focus_keyword       VARCHAR(120) DEFAULT NULL,
        secondary_keywords  VARCHAR(500) DEFAULT NULL,
        schema_type         VARCHAR(40)  DEFAULT NULL,
        schema_fields       TEXT         DEFAULT NULL,
        sitemap_include     TINYINT(1)   DEFAULT NULL,
        sitemap_priority    DECIMAL(2,1) DEFAULT NULL,
        author_member_id    INT UNSIGNED DEFAULT NULL,
        reviewer_member_id  INT UNSIGNED DEFAULT NULL,
        reviewed_at         DATE         DEFAULT NULL,
        sources             TEXT         DEFAULT NULL,
        updated_by          INT UNSIGNED DEFAULT NULL,
        created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_seo_meta_entity (entity_type, entity_id),
        KEY idx_seo_meta_focus (focus_keyword)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** WP-1 settings (added only when missing; admin values are never overwritten). */
function migration_801_seo_core_settings(): void
{
    foreach ([
        'seo_title_max' => '60',
        'seo_robots_extra' => 'max-snippet:-1, max-image-preview:large, max-video-preview:-1',
        'seo_noindex_empty_lists' => '1',
        'seo_faq_schema_scope' => 'faq_page',
        'seo_default_og_image_alt' => '',
        'seo_org_founding_date' => '',
        'seo_org_area_served' => '',
        'seo_awareness_cross_canonical' => '1',
        'seo_awareness_pins' => '',
    ] as $key => $value) {
        add_setting_if_missing($key, $value);
    }
}

/** Permission for the SEO module (administrator always; content editors already hold pages.manage). */
function migration_802_seo_permission(): void
{
    add_permission_if_missing('seo.manage', 'Manage SEO: titles, descriptions, keywords, social images, structured data', 'Website', 17, ['content_editor']);
}
