<?php
/**
 * SEO package WP-2: XML sitemaps, robots.txt, request normalisation, X-Robots-Tag, slug-change 301s,
 * 410 Gone and optional IndexNow.
 *
 * Sitemaps (SEO-DESIGN 4.6, CRITIC P1-4):
 *   /sitemap.xml                  sitemap index - lists only the child sitemaps that have at least one URL
 *   /sitemap-pages.xml            content pages (everything that is not in one of the sets below)
 *   /sitemap-programmes.xml       /programs/, the programme pages and the published Programs & Events category pages
 *   /sitemap-events.xml           /events/, /awareness-calendar/ and public events (canonical event_abs_url())
 *   /sitemap-posts.xml            /blog/, /media/ and published articles
 *   /sitemap-stories.xml          the stories list and consented, published stories
 *   /sitemap-campaigns.xml        /campaigns/ and public campaigns
 *   /sitemap-schemes.xml          /schemes/ and published schemes
 *   /sitemap-images.xml           page URL + <image:image><image:loc> for raster images shown on that page
 *   (a set with more than SEO_SITEMAP_MAX_URLS URLs continues in /sitemap-<set>-2.xml, -3 ...)
 *
 *   A URL is never listed when it is noindex (page flag, seo_meta.robots_index = 0, the WP-1 empty-list rule),
 *   has a canonical pointing to another URL (pages.canonical_url / seo_meta.canonical_url, or a non-canonical
 *   awareness-day edition reported by WP-1's seo_awareness_canonical()), is a draft / scheduled / future /
 *   cancelled / archived item, an unconsented story or photo, a private, token or account template, or a hidden
 *   category page. seo_meta.sitemap_include = 0 removes an entity; seo_meta.sitemap_priority prints <priority>.
 *   lastmod is the newest real change time of the entity (and of the items a list page shows) - never the
 *   request time. All URLs come from the site's URL helpers (page_url(), event_abs_url(), post_url() ...).
 *   Team, testimonial, speaker, partner and avatar photos never appear in the image sitemap.
 *
 * robots.txt: crawl everything except /admin/, /dashboard/, /api/ and the private upload folders. CSS, JS and
 *   images are never blocked. Site-wide noindex mode keeps crawling allowed (search engines must be able to read
 *   the noindex) and sends X-Robots-Tag on every routed response instead of "Disallow: /".
 *
 * Integration (lead, WP-0): see seo_sitemap_route(), seo_normalise_request(), seo_normalise_redirect_response(),
 * seo_x_robots(); editors (WP-7e): seo_slug_changed(), seo_slug_claim(), seo_indexnow_ping().
 * Cross-package calls (WP-1: seo_awareness_canonical(), seo_list_is_empty()) only behind function_exists().
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** IndexNow endpoint (shared by Bing, Yandex, Seznam, Naver ...). Override in pwf-config.php for a local mock only. */
defined('INDEXNOW_ENDPOINT') || define('INDEXNOW_ENDPOINT', 'https://api.indexnow.org/indexnow');

/** Child sitemaps in index order => label (for the admin preview). */
const SEO_SITEMAP_CHILDREN = [
    'pages' => 'Pages', 'programmes' => 'Programmes & categories', 'events' => 'Events', 'posts' => 'Articles',
    'stories' => 'Success stories', 'campaigns' => 'Campaigns', 'schemes' => 'Welfare schemes', 'images' => 'Images',
];
/** URLs per child sitemap file (the protocol allows 50,000 URLs / 50 MB; a margin keeps big image sets small). */
const SEO_SITEMAP_MAX_URLS = 40000;
/** Images per page URL in the image sitemap (Google's limit is 1,000). */
const SEO_SITEMAP_MAX_IMAGES = 1000;
/** Page templates that are never listed: private / token / account pages, the 404 page, detail templates whose base address only redirects. */
const SEO_SITEMAP_SKIP_TEMPLATES = [
    'not_found', 'donation_success', 'donation_failed', 'donation_receipt', 'member', 'login', 'register',
    'forgot_password', 'reset_password', 'verify_email', 'logout', 'event_detail', 'campaign_detail',
];
/** Which child sitemap a page belongs to, by template (every other template: pages). */
const SEO_SITEMAP_TEMPLATE_SETS = [
    'programs' => 'programmes', 'program' => 'programmes', 'program_category' => 'programmes',
    'events' => 'events', 'calendar' => 'events', 'blog' => 'posts', 'stories' => 'stories',
    'campaigns' => 'campaigns', 'schemes' => 'schemes',
];
/** Feeds (child item timestamps) that a list template shows, for the lastmod of list pages. */
const SEO_SITEMAP_TEMPLATE_FEEDS = [
    'programs' => ['programs', 'categories'], 'events' => ['events'], 'calendar' => ['awareness'], 'stories' => ['stories'],
    'campaigns' => ['campaigns'], 'schemes' => ['schemes'],
];
/** Feeds that a CMS section type shows (home page, landing pages). */
const SEO_SITEMAP_SECTION_FEEDS = [
    'events_list' => ['events'], 'program_events' => ['events'], 'program_upcoming_strip' => ['events'], 'awareness_days' => ['awareness'],
    'posts_list' => ['posts'], 'stories_list' => ['stories'], 'campaigns_grid' => ['campaigns'], 'programs_grid' => ['programs'],
    'program_categories_grid' => ['categories'], 'gallery_strip' => ['gallery:image'], 'schemes_list' => ['schemes'],
];
/** Section types whose items carry an "image" shown on the page (hero slides, image cards). Never team, testimonials or logos. */
const SEO_SITEMAP_ITEM_IMAGE_SECTIONS = ['hero_slider', 'image_cards'];
/** Public upload folders whose raster images may appear in the image sitemap. */
const SEO_SITEMAP_IMAGE_FOLDERS = ['blog', 'campaigns', 'events', 'gallery', 'media', 'pages', 'programs', 'schemes', 'stories'];
/** robots.txt Disallow lines (relative to the site's base path). Login and register stay crawlable so their noindex is seen. */
const SEO_ROBOTS_DISALLOW = ['/admin/', '/dashboard/', '/api/', '/uploads/documents/', '/uploads/certificates/'];
/** First path segments a slug-change redirect may never use as its source. */
const SEO_SLUG_RESERVED = ['admin', 'assets', 'uploads', 'includes', 'templates', 'api', 'sitemap.xml', 'robots.txt', 'index.php'];

// =====================================================================
// URL helpers
// =====================================================================

/** Absolute URL of a site path returned by the URL helpers (page_url(), post_url(), event_url() ... include the base path). */
function seo_sitemap_abs(string $sitePath): string
{
    $base = base_path();
    if ($base !== '' && strpos($sitePath, $base . '/') === 0) {
        $sitePath = substr($sitePath, strlen($base));
    }
    return rtrim(BASE_URL, '/') . '/' . ltrim($sitePath, '/');
}

/** scheme://host[:port] of BASE_URL (lower case). */
function seo_sitemap_origin(): string
{
    $p = parse_url(BASE_URL);
    $scheme = strtolower((string) ($p['scheme'] ?? 'https'));
    $port = isset($p['port']) ? (int) $p['port'] : 0;
    $default = $scheme === 'https' ? 443 : 80;
    return $scheme . '://' . strtolower((string) ($p['host'] ?? '')) . ($port && $port !== $default ? ':' . $port : '');
}

/**
 * Comparable form of a URL or site path: absolute, lower-case scheme and host, default port dropped, path with a
 * trailing slash (unless it ends in a file name), query kept, fragment dropped. '' when it is not a usable URL.
 */
function seo_sitemap_normal_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
        return '';
    }
    if (strpos($url, '//') === 0) {
        $url = (string) parse_url(BASE_URL, PHP_URL_SCHEME) . ':' . $url;
    } elseif (!preg_match('#^https?://#i', $url)) {
        if ($url[0] !== '/') {
            return '';
        }
        $url = seo_sitemap_origin() . $url;
    }
    $p = parse_url($url);
    if (!$p || empty($p['host'])) {
        return '';
    }
    $scheme = strtolower((string) $p['scheme']);
    $port = isset($p['port']) ? (int) $p['port'] : 0;
    $path = (string) ($p['path'] ?? '/');
    if ($path === '') {
        $path = '/';
    }
    if (substr($path, -1) !== '/' && !preg_match('#/[^/]+\.[A-Za-z0-9]{2,5}$#', $path)) {
        $path .= '/';
    }
    return $scheme . '://' . strtolower((string) $p['host']) . ($port && $port !== ($scheme === 'https' ? 443 : 80) ? ':' . $port : '')
        . $path . (isset($p['query']) && $p['query'] !== '' ? '?' . $p['query'] : '');
}

/** Do two URLs (absolute or site paths) name the same address? Used for "canonical points elsewhere". */
function seo_sitemap_same_url(string $a, string $b): bool
{
    $na = seo_sitemap_normal_url($a);
    return $na !== '' && $na === seo_sitemap_normal_url($b);
}

/** XML-escaped text. */
function seo_sitemap_xml(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// =====================================================================
// Data sources (each query guarded: a missing table or column never breaks the sitemap)
// =====================================================================

/** Rows of a query, or [] when it fails (logged). */
function seo_sitemap_rows(string $sql, array $params = []): array
{
    try {
        return db_rows($sql, $params);
    } catch (Throwable $e) {
        error_log('Sitemap query failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Newest of the given timestamps (DB datetime strings or Unix times) as a Unix time, or null.
 * Empty, invalid and future values are ignored, so lastmod is never a guess and never the request time.
 */
function seo_sitemap_lastmod(...$values): ?int
{
    $best = null;
    $limit = time() + 300;
    foreach ($values as $v) {
        if (is_array($v)) {
            $v = $v ? seo_sitemap_lastmod(...array_values($v)) : null;
        }
        if ($v === null || $v === '' || $v === false) {
            continue;
        }
        $ts = is_int($v) ? $v : strtotime((string) $v);
        if (!$ts || $ts < 946684800 || $ts > $limit) {   // before 2000 = zero dates; future = scheduled or clock skew
            continue;
        }
        $best = $best === null ? $ts : max($best, $ts);
    }
    return $best;
}

/**
 * seo_meta rows (WP-1 table) as [entity_type][entity_id] => [sitemap_include, sitemap_priority, robots_index, canonical_url].
 * One guarded query per request; [] when the table does not exist yet.
 */
function seo_sitemap_meta_map(bool $refresh = false): array
{
    static $map = null;
    if ($map !== null && !$refresh) {
        return $map;
    }
    $map = [];
    try {
        if (!function_exists('table_exists') || !table_exists('seo_meta')) {
            return $map;
        }
        $cols = ['entity_type', 'entity_id'];
        foreach (['sitemap_include', 'sitemap_priority', 'robots_index', 'canonical_url'] as $c) {
            if (column_exists('seo_meta', $c)) {
                $cols[] = $c;
            }
        }
        if (count($cols) === 2) {
            return $map;
        }
        foreach (db_rows('SELECT ' . implode(', ', $cols) . ' FROM seo_meta') as $r) {
            $map[(string) $r['entity_type']][(int) $r['entity_id']] = $r;
        }
    } catch (Throwable $e) {
        error_log('Sitemap: seo_meta not readable: ' . $e->getMessage());
        $map = [];
    }
    return $map;
}

/** seo_meta row of one entity ([] when none). */
function seo_sitemap_meta(string $type, int $id): array
{
    $map = seo_sitemap_meta_map();
    return $map[$type][$id] ?? [];
}

/** May this entity be listed at $loc? (seo_meta: robots_index 0, sitemap_include 0 or a canonical to another URL exclude it.) */
function seo_sitemap_meta_allows(string $type, int $id, string $loc): bool
{
    $m = seo_sitemap_meta($type, $id);
    if (!$m) {
        return true;
    }
    if (isset($m['robots_index']) && $m['robots_index'] !== null && (string) $m['robots_index'] === '0') {
        return false;
    }
    if (isset($m['sitemap_include']) && $m['sitemap_include'] !== null && (string) $m['sitemap_include'] === '0') {
        return false;
    }
    $canonical = trim((string) ($m['canonical_url'] ?? ''));
    return $canonical === '' || seo_sitemap_same_url($canonical, $loc);
}

/** <priority> value set by an admin in seo_meta ('0.0'-'1.0'), or null (no tag: Google ignores priority anyway). */
function seo_sitemap_meta_priority(string $type, int $id): ?string
{
    $m = seo_sitemap_meta($type, $id);
    if (!isset($m['sitemap_priority']) || $m['sitemap_priority'] === null || $m['sitemap_priority'] === '') {
        return null;
    }
    $p = (float) $m['sitemap_priority'];
    return $p >= 0 && $p <= 1 ? number_format($p, 1, '.', '') : null;
}

/**
 * Newest change time of the items each list shows (one query per feed, cached per request):
 * posts:<category>, stories, campaigns, schemes, events, awareness, events:cat:<id>, programs, categories, gallery:<media>.
 */
function seo_sitemap_feeds(bool $refresh = false): array
{
    static $feeds = null;
    if ($feeds !== null && !$refresh) {
        return $feeds;
    }
    $feeds = [];
    $postWhere = function_exists('post_visible_sql') ? post_visible_sql() : "status = 'published' AND published_at IS NOT NULL AND published_at <= NOW()";
    foreach (seo_sitemap_rows("SELECT category, MAX(GREATEST(COALESCE(updated_at, created_at), COALESCE(published_at, created_at))) AS ts
                               FROM blog_posts WHERE $postWhere GROUP BY category") as $r) {
        $feeds['posts:' . $r['category']] = $r['ts'];
    }
    $storyWhere = function_exists('story_visible_sql') ? story_visible_sql() : "status = 'published' AND consent_confirmed = 1 AND (published_at IS NULL OR published_at <= NOW())";
    $one = function (string $key, string $sql) use (&$feeds) {
        $rows = seo_sitemap_rows($sql);
        $feeds[$key] = $rows ? $rows[0]['ts'] : null;
    };
    $one('stories', "SELECT MAX(GREATEST(COALESCE(updated_at, created_at), COALESCE(published_at, created_at))) AS ts FROM success_stories WHERE $storyWhere");
    $public = defined('CAMPAIGN_PUBLIC_STATUSES') ? CAMPAIGN_PUBLIC_STATUSES : ['active', 'completed', 'closed'];
    $one('campaigns', "SELECT MAX(COALESCE(updated_at, created_at)) AS ts FROM campaigns WHERE status IN ('" . implode("','", array_map('addslashes', $public)) . "')");
    $one('schemes', "SELECT MAX(COALESCE(updated_at, created_at)) AS ts FROM schemes WHERE status = 'published'");
    $one('events', "SELECT MAX(COALESCE(updated_at, created_at)) AS ts FROM events WHERE status = 'published' AND event_type <> 'awareness_day'");
    $one('awareness', "SELECT MAX(COALESCE(updated_at, created_at)) AS ts FROM events WHERE status = 'published' AND event_type = 'awareness_day'");
    $one('programs', "SELECT MAX(COALESCE(updated_at, created_at)) AS ts FROM programs WHERE status = 'published'");
    $one('categories', "SELECT MAX(COALESCE(updated_at, created_at)) AS ts FROM program_categories WHERE status = 'published'");
    foreach (seo_sitemap_rows("SELECT category_id, MAX(COALESCE(updated_at, created_at)) AS ts FROM events
                               WHERE status = 'published' AND category_id IS NOT NULL GROUP BY category_id") as $r) {
        $feeds['events:cat:' . (int) $r['category_id']] = $r['ts'];
    }
    foreach (seo_sitemap_rows("SELECT media_type, MAX(created_at) AS ts FROM gallery_items WHERE status = 'published' AND consent_confirmed = 1 GROUP BY media_type") as $r) {
        $feeds['gallery:' . $r['media_type']] = $r['ts'];
    }
    return $feeds;
}

/** Feed timestamps for a list of feed keys ("posts" expands to every post category). */
function seo_sitemap_feed_values(array $keys): array
{
    $feeds = seo_sitemap_feeds();
    $out = [];
    foreach ($keys as $k) {
        if ($k === 'posts') {
            foreach ($feeds as $fk => $ts) {
                if (strpos($fk, 'posts:') === 0) {
                    $out[] = $ts;
                }
            }
            continue;
        }
        if (isset($feeds[$k])) {
            $out[] = $feeds[$k];
        }
    }
    return $out;
}

/** Visible sections of published pages, grouped by page id (one query). */
function seo_sitemap_page_sections(): array
{
    static $byPage = null;
    if ($byPage === null) {
        $byPage = [];
        foreach (seo_sitemap_rows("SELECT s.page_id, s.section_type, s.image, s.items, s.settings FROM page_sections s
                                   JOIN pages p ON p.id = s.page_id WHERE s.status = 'visible' AND p.status = 'published'
                                   ORDER BY s.sort_order, s.id") as $s) {
            $byPage[(int) $s['page_id']][] = $s;
        }
    }
    return $byPage;
}

// =====================================================================
// Images
// =====================================================================

/**
 * Absolute URL of an image for the image sitemap, or '' when it must not be listed: not a raster file
 * (SVG, PDF), not in a public content folder (team, testimonials, speakers, partners, avatars, documents,
 * certificates are refused), missing on disk, or an unsafe path.
 */
function seo_sitemap_image_url(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '' || strpos($path, '..') !== false || !preg_match('#^uploads/([a-z0-9_\-]+)/[A-Za-z0-9_\-/.]+\.(jpe?g|png|webp|gif)$#i', $path, $m)) {
        return '';
    }
    if (!in_array(strtolower($m[1]), SEO_SITEMAP_IMAGE_FOLDERS, true) || !is_file(ROOT_PATH . '/' . $path)) {
        return '';
    }
    return rtrim(BASE_URL, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
}

/** Unique, listable image URLs from a list of paths (max SEO_SITEMAP_MAX_IMAGES). */
function seo_sitemap_image_list(array $paths): array
{
    $out = [];
    foreach ($paths as $p) {
        $u = seo_sitemap_image_url(is_string($p) ? $p : '');
        if ($u !== '' && !isset($out[$u])) {
            $out[$u] = true;
            if (count($out) >= SEO_SITEMAP_MAX_IMAGES) {
                break;
            }
        }
    }
    return array_keys($out);
}

// =====================================================================
// Collect the URL sets
// =====================================================================

/**
 * Is this awareness-day edition the canonical one of its observance?
 * With WP-1 (seo_awareness_canonical() exists): canonical = WP-1's answer (CRITIC P1-2: next upcoming edition, else
 * the most recent; other editions carry rel=canonical to it and are left out of every sitemap).
 * Without WP-1: the previous rule - past awareness days are not listed.
 */
function seo_sitemap_awareness_is_canonical(array $ev, string $loc): bool
{
    $upcoming = function () use ($ev): bool {
        $end = strtotime((string) ($ev['end_datetime'] ?: $ev['start_datetime']));
        return $end === false || $end >= time();
    };
    if (!function_exists('seo_awareness_canonical')) {
        return $upcoming();
    }
    try {
        $arg = $ev;
        $fn = new ReflectionFunction('seo_awareness_canonical');
        $params = $fn->getParameters();
        if ($params && $params[0]->hasType()) {
            $t = $params[0]->getType();
            $tn = $t instanceof ReflectionNamedType ? $t->getName() : '';
            if ($tn === 'int') {
                $arg = (int) $ev['id'];
            } elseif ($tn === 'string') {
                $arg = (string) $ev['slug'];
            }
        }
        $c = seo_awareness_canonical($arg);
    } catch (Throwable $e) {
        error_log('Sitemap: seo_awareness_canonical() failed: ' . $e->getMessage());
        return $upcoming();   // fall back to the previous rule
    }
    // accepted answers: null/''/true = this edition is canonical; false = not; a URL or site path; an event row or ['url' => ...]; an event id
    if ($c === null || $c === '' || $c === true) {
        return true;
    }
    if ($c === false) {
        return false;
    }
    if (is_int($c) || (is_string($c) && ctype_digit($c))) {
        return (int) $c === (int) $ev['id'];
    }
    if (is_array($c)) {
        if (isset($c['is_canonical'])) {
            return (bool) $c['is_canonical'];
        }
        if (!empty($c['url']) || !empty($c['canonical'])) {
            $c = (string) ($c['url'] ?? $c['canonical']);
        } elseif (isset($c['id'])) {
            return (int) $c['id'] === (int) $ev['id'];
        } elseif (isset($c['slug'])) {
            return (string) $c['slug'] === (string) $ev['slug'];
        } else {
            return true;
        }
    }
    return seo_sitemap_same_url((string) $c, $loc);
}

/** Entry array. */
function seo_sitemap_entry(string $loc, ?int $lastmod, string $type, int $id, string $title, array $images = []): array
{
    return [
        'loc' => $loc, 'lastmod' => $lastmod, 'priority' => $id > 0 ? seo_sitemap_meta_priority($type, $id) : null,
        'type' => $type, 'id' => $id, 'title' => $title, 'images' => $images,
    ];
}

/** Published, listable `pages` rows into their sets. */
function seo_sitemap_collect_pages(array &$sets): void
{
    $hidden = function_exists('program_category_hidden_page_ids') ? program_category_hidden_page_ids() : [];
    $sections = seo_sitemap_page_sections();
    $feeds = seo_sitemap_feeds();
    // programme pages: the linked programme must be published too (the template answers 404 otherwise)
    $programByPage = [];
    if (function_exists('programs_all')) {
        try {
            foreach (programs_all(false) as $pr) {
                if (!empty($pr['detail_page_id'])) {
                    $programByPage[(int) $pr['detail_page_id']] = $pr;
                }
            }
        } catch (Throwable $e) {
            error_log('Sitemap: programmes not readable: ' . $e->getMessage());
        }
    }
    $categoryByPage = [];
    if (function_exists('program_categories_all')) {
        foreach (program_categories_all(false) as $c) {
            if (!empty($c['page_id'])) {
                $categoryByPage[(int) $c['page_id']] = $c;
            }
        }
    }
    $galleryImages = null;

    $rows = seo_sitemap_rows("SELECT * FROM pages WHERE status = 'published' AND show_in_sitemap = 1 AND noindex = 0 ORDER BY sort_order, title");
    foreach ($rows as $p) {
        $id = (int) $p['id'];
        $template = (string) $p['template'];
        if (in_array($template, SEO_SITEMAP_SKIP_TEMPLATES, true) || in_array($id, $hidden, true)) {
            continue;
        }
        if ($template === 'program' && isset($programByPage[$id]) && $programByPage[$id]['status'] !== 'published') {
            continue;
        }
        $loc = abs_page_url((string) $p['slug']);
        $canonical = trim((string) $p['canonical_url']);
        if ($canonical !== '' && !seo_sitemap_same_url($canonical, $loc)) {
            continue;   // the page names another URL as canonical
        }
        if (!seo_sitemap_meta_allows('page', $id, $loc)) {
            continue;
        }
        if (function_exists('seo_list_is_empty') && setting('seo_noindex_empty_lists', '1') !== '0') {
            try {
                if (seo_list_is_empty($p)) {
                    continue;   // WP-1: an empty list page is noindex, follow
                }
            } catch (Throwable $e) {
                error_log('Sitemap: seo_list_is_empty() failed: ' . $e->getMessage());
            }
        }

        // lastmod: the page itself + the items its list / sections show
        $times = [$p['updated_at'], $p['created_at']];
        $feedKeys = SEO_SITEMAP_TEMPLATE_FEEDS[$template] ?? [];
        $settings = json_field($p['settings'] ?? null);
        if ($template === 'blog') {
            foreach ((array) ($settings['categories'] ?? ['blog', 'news']) as $cat) {
                $feedKeys[] = 'posts:' . preg_replace('/[^a-z]/', '', (string) $cat);
            }
        } elseif ($template === 'gallery') {
            $feedKeys[] = 'gallery:' . (($settings['media_type'] ?? 'image') === 'video' ? 'video' : 'image');
        }
        $images = [(string) $p['banner_image']];
        foreach ($sections[$id] ?? [] as $s) {
            foreach (SEO_SITEMAP_SECTION_FEEDS[$s['section_type']] ?? [] as $fk) {
                $feedKeys[] = $fk;
            }
            $images[] = (string) $s['image'];
            if (in_array($s['section_type'], SEO_SITEMAP_ITEM_IMAGE_SECTIONS, true)) {
                foreach (json_field($s['items'] ?? null) as $item) {
                    if (is_array($item) && isset($item['image']) && is_string($item['image'])) {
                        $images[] = $item['image'];
                    }
                }
            }
        }
        if ($template === 'program' && isset($programByPage[$id])) {
            $times[] = $programByPage[$id]['updated_at'] ?? null;
            $cover = (string) ($programByPage[$id]['cover_image'] ?? '');
            if ($cover !== '' && function_exists('program_art') && program_art($programByPage[$id]) === $cover) {
                $images[] = $cover;
            }
        }
        if ($template === 'program_category' && isset($categoryByPage[$id])) {
            $cat = $categoryByPage[$id];
            $times[] = $cat['updated_at'] ?? null;
            $times[] = $feeds['events:cat:' . (int) $cat['id']] ?? null;
            $images[] = (string) ($cat['image'] ?? '');
        }
        if ($template === 'gallery' && ($settings['media_type'] ?? 'image') !== 'video') {
            if ($galleryImages === null) {   // the first page of /gallery/ shows 60 photos (templates/gallery.php)
                $galleryImages = array_column(seo_sitemap_rows("SELECT image_path FROM gallery_items WHERE media_type = 'image' AND status = 'published'
                                                                AND consent_confirmed = 1 ORDER BY sort_order, id DESC LIMIT 60"), 'image_path');
            }
            $images = array_merge($images, $galleryImages);
        }
        $times = array_merge($times, seo_sitemap_feed_values(array_unique($feedKeys)));
        $set = SEO_SITEMAP_TEMPLATE_SETS[$template] ?? 'pages';
        $sets[$set][] = seo_sitemap_entry($loc, seo_sitemap_lastmod($times), 'page', $id, (string) $p['title'], seo_sitemap_image_list($images));
    }
}

/** Public events (canonical address from event_abs_url()). */
function seo_sitemap_collect_events(array &$sets): void
{
    if (!function_exists('event_abs_url')) {
        return;
    }
    $pastAllowed = setting('seo_sitemap_past_events', '1') !== '0';
    $gallery = [];
    foreach (seo_sitemap_rows("SELECT event_id, image_path FROM gallery_items WHERE event_id IS NOT NULL AND status = 'published' AND consent_confirmed = 1
                               ORDER BY sort_order, id DESC") as $g) {
        if (count($gallery[(int) $g['event_id']] ?? []) < 24) {   // the event page shows up to 24 consented photos
            $gallery[(int) $g['event_id']][] = $g['image_path'];
        }
    }
    // cancelled, draft, scheduled and archived events are never listed
    $rows = seo_sitemap_rows("SELECT id, slug, title, event_type, image, start_datetime, end_datetime, publish_at, updated_at, created_at
                              FROM events WHERE status = 'published' ORDER BY start_datetime DESC, id DESC");
    foreach ($rows as $ev) {
        $id = (int) $ev['id'];
        if (!empty($ev['publish_at']) && strtotime((string) $ev['publish_at']) > time()) {
            continue;   // not yet public
        }
        $loc = event_abs_url($ev);
        $end = strtotime((string) ($ev['end_datetime'] ?: $ev['start_datetime']));
        $isPast = $end !== false && $end < time();
        if ($ev['event_type'] === 'awareness_day') {
            if (!seo_sitemap_awareness_is_canonical($ev, $loc)) {
                continue;
            }
        } elseif ($isPast && !$pastAllowed) {
            continue;
        }
        if (!seo_sitemap_meta_allows('event', $id, $loc)) {
            continue;
        }
        $images = array_merge([(string) $ev['image']], $gallery[$id] ?? []);
        $sets['events'][] = seo_sitemap_entry($loc, seo_sitemap_lastmod($ev['updated_at'], $ev['created_at'], $ev['publish_at']), 'event', $id,
            (string) $ev['title'], seo_sitemap_image_list($images));
    }
}

/** Articles, stories, campaigns and schemes (same visibility rules as their templates). */
function seo_sitemap_collect_entities(array &$sets): void
{
    $postWhere = function_exists('post_visible_sql') ? post_visible_sql() : "status = 'published' AND published_at IS NOT NULL AND published_at <= NOW()";
    foreach (seo_sitemap_rows("SELECT id, slug, title, image, published_at, updated_at, created_at FROM blog_posts WHERE $postWhere ORDER BY published_at DESC, id DESC") as $r) {
        $loc = seo_sitemap_abs(post_url((string) $r['slug']));
        if (seo_sitemap_meta_allows('post', (int) $r['id'], $loc)) {
            $sets['posts'][] = seo_sitemap_entry($loc, seo_sitemap_lastmod($r['updated_at'], $r['published_at'], $r['created_at']), 'post', (int) $r['id'],
                (string) $r['title'], seo_sitemap_image_list([(string) $r['image']]));
        }
    }
    $storyWhere = function_exists('story_visible_sql') ? story_visible_sql() : "status = 'published' AND consent_confirmed = 1 AND (published_at IS NULL OR published_at <= NOW())";
    foreach (seo_sitemap_rows("SELECT id, slug, title, image, published_at, updated_at, created_at FROM success_stories WHERE $storyWhere ORDER BY published_at DESC, id DESC") as $r) {
        $loc = seo_sitemap_abs(story_url((string) $r['slug']));
        if (seo_sitemap_meta_allows('story', (int) $r['id'], $loc)) {
            $sets['stories'][] = seo_sitemap_entry($loc, seo_sitemap_lastmod($r['updated_at'], $r['published_at'], $r['created_at']), 'story', (int) $r['id'],
                (string) $r['title'], seo_sitemap_image_list([(string) $r['image']]));
        }
    }
    $public = defined('CAMPAIGN_PUBLIC_STATUSES') ? CAMPAIGN_PUBLIC_STATUSES : ['active', 'completed', 'closed'];
    $marks = implode(',', array_fill(0, count($public), '?'));
    foreach (seo_sitemap_rows("SELECT id, slug, title, image, updated_at, created_at FROM campaigns WHERE status IN ($marks) ORDER BY created_at DESC, id DESC", array_values($public)) as $r) {
        $loc = seo_sitemap_abs(campaign_url((string) $r['slug']));
        if (seo_sitemap_meta_allows('campaign', (int) $r['id'], $loc)) {
            $sets['campaigns'][] = seo_sitemap_entry($loc, seo_sitemap_lastmod($r['updated_at'], $r['created_at']), 'campaign', (int) $r['id'],
                (string) $r['title'], seo_sitemap_image_list([(string) $r['image']]));
        }
    }
    foreach (seo_sitemap_rows("SELECT id, slug, title, image, updated_at, created_at FROM schemes WHERE status = 'published' ORDER BY sort_order, title") as $r) {
        $loc = seo_sitemap_abs(scheme_url((string) $r['slug']));
        if (seo_sitemap_meta_allows('scheme', (int) $r['id'], $loc)) {
            $sets['schemes'][] = seo_sitemap_entry($loc, seo_sitemap_lastmod($r['updated_at'], $r['created_at']), 'scheme', (int) $r['id'],
                (string) $r['title'], seo_sitemap_image_list([(string) $r['image']]));
        }
    }
}

/**
 * Every listable URL, grouped by child sitemap: ['pages' => [entry, ...], 'programmes' => [...], ..., 'images' => [...]].
 * Entry: loc (absolute), lastmod (Unix time or null), priority (string or null), type, id, title, images (absolute URLs).
 * Site-wide noindex mode: every set is empty. Cached per request ($refresh after changes in the same request).
 */
function seo_sitemap_sets(bool $refresh = false): array
{
    static $sets = null;
    if ($sets !== null && !$refresh) {
        return $sets;
    }
    if ($refresh) {
        seo_sitemap_meta_map(true);
        seo_sitemap_feeds(true);
    }
    $sets = array_fill_keys(array_keys(SEO_SITEMAP_CHILDREN), []);
    if (setting('seo_noindex_site') === '1') {
        return $sets;
    }
    seo_sitemap_collect_pages($sets);
    seo_sitemap_collect_events($sets);
    seo_sitemap_collect_entities($sets);
    // one URL appears once (first set wins); the image sitemap repeats the pages that show listable images
    $seen = [];
    foreach ($sets as $name => $entries) {
        if ($name === 'images') {
            continue;
        }
        $keep = [];
        foreach ($entries as $e) {
            $key = seo_sitemap_normal_url($e['loc']);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $keep[] = $e;
        }
        $sets[$name] = $keep;
    }
    if (setting('seo_sitemap_images', '1') !== '0') {
        foreach ($sets as $name => $entries) {
            if ($name === 'images') {
                continue;
            }
            foreach ($entries as $e) {
                if ($e['images']) {
                    $sets['images'][] = $e;
                }
            }
        }
    }
    return $sets;
}

/** Entries of one child sitemap (see seo_sitemap_sets()); [] for an unknown name. */
function seo_sitemap_entries(string $set): array
{
    return seo_sitemap_sets()[$set] ?? [];
}

/** Image sitemap entries: [['loc' => page URL, 'lastmod' => ..., 'images' => [absolute image URLs]], ...]. */
function seo_sitemap_image_entries(): array
{
    return seo_sitemap_entries('images');
}

/**
 * Child sitemap files that have at least one URL, in index order:
 * [['name' => 'pages' | 'events-2', 'set' => 'pages', 'loc' => absolute URL, 'lastmod' => ?int, 'count' => n, 'entries' => [...]], ...].
 * Also used by the admin preview (WP-4: names, counts, newest lastmod).
 */
function seo_sitemap_children(): array
{
    $out = [];
    foreach (seo_sitemap_sets() as $set => $entries) {
        if (!$entries) {
            continue;
        }
        foreach (array_chunk($entries, SEO_SITEMAP_MAX_URLS) as $i => $chunk) {
            $name = $set . ($i > 0 ? '-' . ($i + 1) : '');
            $out[$name] = [
                'name' => $name, 'set' => $set, 'label' => SEO_SITEMAP_CHILDREN[$set] ?? $set,
                'loc' => abs_url('sitemap-' . $name . '.xml'), 'lastmod' => seo_sitemap_lastmod(array_column($chunk, 'lastmod')),
                'count' => count($chunk), 'entries' => $chunk,
            ];
        }
    }
    return $out;
}

// =====================================================================
// XML output
// =====================================================================

/** W3C datetime for lastmod (with the site's time-zone offset). */
function seo_sitemap_w3c(?int $ts): string
{
    return $ts ? date('c', $ts) : '';
}

/** The sitemap index XML (only non-empty children). */
function seo_sitemap_index_xml(): string
{
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach (seo_sitemap_children() as $c) {
        $x .= '  <sitemap><loc>' . seo_sitemap_xml($c['loc']) . '</loc>' . ($c['lastmod'] ? '<lastmod>' . seo_sitemap_w3c($c['lastmod']) . '</lastmod>' : '') . "</sitemap>\n";
    }
    return $x . "</sitemapindex>\n";
}

/** XML of one child sitemap, or null when it does not exist or is empty. */
function seo_sitemap_child_xml(string $name): ?string
{
    $children = seo_sitemap_children();
    if (!isset($children[$name])) {
        return null;
    }
    $isImages = $children[$name]['set'] === 'images';
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
        . ($isImages ? ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"' : '') . ">\n";
    foreach ($children[$name]['entries'] as $e) {
        $x .= '  <url><loc>' . seo_sitemap_xml($e['loc']) . '</loc>'
            . ($e['lastmod'] ? '<lastmod>' . seo_sitemap_w3c($e['lastmod']) . '</lastmod>' : '')
            . (!$isImages && $e['priority'] !== null ? '<priority>' . $e['priority'] . '</priority>' : '');
        if ($isImages) {
            foreach ($e['images'] as $img) {
                $x .= '<image:image><image:loc>' . seo_sitemap_xml($img) . '</image:loc></image:image>';
            }
        }
        $x .= "</url>\n";
    }
    return $x . "</urlset>\n";
}

/**
 * Send a small machine-readable response (sitemap, robots.txt, IndexNow key) and stop.
 * Public caching for an hour, an ETag (304 on a match), no session cookie, no body for HEAD.
 * No X-Robots-Tag on sitemaps: they must stay readable by crawlers.
 */
function seo_sitemap_send(string $body, string $contentType, int $status = 200): void
{
    $etag = '"' . substr(sha1($body), 0, 32) . '"';
    if (!headers_sent()) {
        header_remove('Set-Cookie');   // no session for crawlers; a public cache must never store a session cookie
        header_remove('Pragma');
        header_remove('Expires');
        http_response_code($status);
        header('Content-Type: ' . $contentType);
        if ($status === 200) {
            header('Cache-Control: public, max-age=3600');
            header('ETag: ' . $etag);
            foreach (explode(',', (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) as $tag) {
                $tag = preg_replace('/-(gzip|br|deflate)"$/', '"', trim(preg_replace('#^W/#', '', trim($tag))));   // Apache mod_deflate adds -gzip
                if ($tag === $etag) {
                    http_response_code(304);
                    exit;
                }
            }
        } else {
            header('Cache-Control: no-cache');
            header('X-Robots-Tag: noindex');
        }
    }
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
        echo $body;
    }
    exit;
}

/**
 * Answer /sitemap.xml ($name = '') or /sitemap-<name>.xml and stop. GET and HEAD only.
 * An empty or unknown child answers 404 (it is not listed in the index either).
 */
function seo_sitemap_response(string $name): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        if (!headers_sent()) {
            header('Allow: GET, HEAD');
        }
        seo_sitemap_send("Method not allowed\n", 'text/plain; charset=utf-8', 405);
    }
    $name = strtolower($name);
    if ($name === '') {
        seo_sitemap_send(seo_sitemap_index_xml(), 'application/xml; charset=utf-8');
    }
    $xml = preg_match('/^[a-z]+(-[0-9]{1,4})?$/', $name) ? seo_sitemap_child_xml($name) : null;
    if ($xml === null) {
        seo_sitemap_send("Not found\n", 'text/plain; charset=utf-8', 404);
    }
    seo_sitemap_send($xml, 'application/xml; charset=utf-8');
}

// =====================================================================
// robots.txt
// =====================================================================

/** robots.txt text. Never "Disallow: /" (site-wide noindex mode keeps crawling allowed so the noindex can be read). */
function seo_robots_txt(): string
{
    $base = base_path();
    $noindexSite = setting('seo_noindex_site') === '1';
    $lines = [];
    if ($noindexSite) {
        $lines[] = '# Site-wide noindex mode: pages stay crawlable and send "noindex" (meta robots and X-Robots-Tag).';
    }
    $lines[] = 'User-agent: *';
    foreach (SEO_ROBOTS_DISALLOW as $p) {
        $lines[] = 'Disallow: ' . $base . $p;
    }
    // admin-entered extra lines (Settings > SEO & analytics), control characters removed
    $extra = trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', str_replace("\r", '', setting('robots_txt_extra'))));
    if ($extra !== '') {
        $lines[] = $extra;
    }
    if (!$noindexSite) {
        $lines[] = '';
        $lines[] = 'Sitemap: ' . abs_url('sitemap.xml');
    }
    return implode("\n", $lines) . "\n";
}

/** Answer /robots.txt and stop. */
function seo_robots_response(): void
{
    seo_sitemap_send(seo_robots_txt(), 'text/plain; charset=utf-8');
}

/**
 * index.php hook (one line, before the old "sitemap.xml" block): answers /sitemap.xml, /sitemap-<set>.xml, /robots.txt and
 * the IndexNow key file, then stops; returns for every other path. A trailing slash or a repeated slash on these files
 * gets a 301 to the exact address first.
 */
function seo_sitemap_route(string $path): void
{
    if (!preg_match('#^(?:sitemap(?:-([a-z]+(?:-[0-9]{1,4})?))?\.xml|robots\.txt|[A-Za-z0-9\-]{8,128}\.txt)$#', $path, $m)) {
        return;
    }
    $isKeyFile = $path !== 'robots.txt' && substr($path, -4) === '.txt';
    if ($isKeyFile && !(seo_indexnow_enabled() && $path === seo_indexnow_key() . '.txt')) {
        return;   // not ours: the normal 404 handling answers
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET' || $method === 'HEAD') {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $rawPath = substr($uri, 0, strcspn($uri, '?#'));
        $want = base_path() . '/' . $path;
        if ($rawPath !== $want) {
            seo_normalise_redirect_to($want);   // /sitemap.xml/ -> /sitemap.xml (query dropped: these files take none)
        }
    }
    if ($path === 'robots.txt') {
        seo_robots_response();
    }
    if ($isKeyFile) {
        seo_indexnow_key_response($path);
    }
    seo_sitemap_response((string) ($m[1] ?? ''));
}

// =====================================================================
// Request normalisation, X-Robots-Tag, redirects and 410
// =====================================================================

/** Send "X-Robots-Tag: noindex" (+ nofollow) unless output has started. For .ics, JSON, private downloads and site-noindex mode. */
function seo_x_robots(bool $nofollow = false): void
{
    if (!headers_sent()) {
        header('X-Robots-Tag: noindex' . ($nofollow ? ', nofollow' : ''));
    }
}

/**
 * 301/302 to a same-site path or an absolute URL and stop. Refuses (returns without redirecting) anything that could
 * inject a header or leave the site through a protocol-relative address: control characters, spaces, backslashes, "//x".
 */
function seo_normalise_redirect_to(string $location, int $code = 301): void
{
    if ($location === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $location)) {
        return;
    }
    if ($location[0] === '/') {
        if (isset($location[1]) && ($location[1] === '/' || $location[1] === '\\')) {
            return;
        }
    } elseif (!preg_match('#^https?://[^/?\#]+#i', $location)) {
        return;
    }
    if (headers_sent()) {
        return;
    }
    header('Location: ' . $location, true, in_array($code, [301, 302, 307, 308], true) ? $code : 301);
    exit;
}

/** [path, query] of a request URI without trusting parse_url() ("//about/" is a host to parse_url()). */
function seo_normalise_split_uri(string $uri): array
{
    $uri = substr($uri, 0, strcspn($uri, '#'));
    $q = strpos($uri, '?');
    return $q === false ? [$uri, ''] : [substr($uri, 0, $q), substr($uri, $q + 1)];
}

/** Detected and configured origin, for the admin Setup tab before the canonical-host redirect is switched on. */
function seo_normalise_detected_origin(): array
{
    $scheme = (function_exists('is_https') && is_https()) ? 'https' : 'http';
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    if (!preg_match('/^[a-z0-9.\-]+(:[0-9]{1,5})?$/', $host)) {
        $host = '';
    }
    $host = preg_replace('/:' . ($scheme === 'https' ? '443' : '80') . '$/', '', $host);
    $detected = $host !== '' ? $scheme . '://' . $host : '';
    return ['detected' => $detected, 'configured' => seo_sitemap_origin(), 'match' => $detected === '' || $detected === seo_sitemap_origin()];
}

/**
 * index.php hook (before find_redirect() / "Canonical form"). GET and HEAD only; POST and other methods are never touched.
 *  - site-wide noindex mode: X-Robots-Tag: noindex, nofollow on every routed response (any method)
 *  - repeated slashes -> 301 to the single-slash address ("//about/", "/about//", "//sitemap.xml"), query kept
 *  - "/sitemap.xml/" -> 301 "/sitemap.xml" (also robots.txt and the child sitemaps)
 *  - optional canonical host (setting seo_canonical_host_redirect = 1, default off): other host or scheme -> 301 to BASE_URL
 *  - HEAD gets the same lower-case / trailing-slash 301 that index.php gives GET (only when no redirect row matches,
 *    exactly like the GET order), so HEAD and GET answer alike
 * The Location is always built from the request path (validated characters) on this site or from BASE_URL - never from
 * the Host header - so it cannot become an open redirect or carry an injected header.
 */
function seo_normalise_request(string $path, string $rawPath = '', string $method = 'GET'): void
{
    try {
        $noindexSite = setting('seo_noindex_site') === '1';
    } catch (Throwable $e) {
        return;   // database not installed yet
    }
    if ($noindexSite) {
        seo_x_robots(true);
    }
    $method = strtoupper($method);
    if ($method !== 'GET' && $method !== 'HEAD') {
        return;
    }
    [$uriPath, $qs] = seo_normalise_split_uri((string) ($_SERVER['REQUEST_URI'] ?? ($rawPath !== '' ? $rawPath : '/')));
    if ($uriPath === '' || $uriPath[0] !== '/' || !preg_match('#^[A-Za-z0-9\-._~%!$&\'()*+,;=:@/]+$#', $uriPath)) {
        return;   // unusual characters: leave it to the normal routing (404)
    }
    if (!preg_match('/^[\x21-\x7E]*$/', $qs)) {
        $qs = '';
    }
    $suffix = $qs !== '' ? '?' . $qs : '';

    $target = preg_replace('#/{2,}#', '/', $uriPath);
    $target = preg_replace('#/(sitemap(?:-[a-z]+(?:-[0-9]{1,4})?)?\.xml|robots\.txt)/+$#', '/$1', $target);
    $origin = '';
    if (setting('seo_canonical_host_redirect') === '1' && PHP_SAPI !== 'cli') {
        $o = seo_normalise_detected_origin();
        if ($o['detected'] !== '' && !$o['match']) {
            $origin = $o['configured'];
        }
    }
    if ($target !== $uriPath || $origin !== '') {
        seo_normalise_redirect_to($origin . $target . $suffix);
    }

    // HEAD: same canonical form as GET (index.php applies it to GET only, after the redirects table)
    if ($method === 'HEAD' && $path !== '' && strtolower($path) !== 'home'
        && (substr($uriPath, -1) !== '/' || $path !== strtolower($path)) && !preg_match('/\.[a-z0-9]{2,5}$/i', $path)
        && preg_match('#^[a-z0-9][a-z0-9\-_/]*$#i', $path)) {
        try {
            $hasRedirect = (bool) db_value('SELECT COUNT(*) FROM redirects WHERE source_path = ? OR source_path = ?', [$path, $path . '/']);
        } catch (Throwable $e) {
            $hasRedirect = false;
        }
        if (!$hasRedirect && ($path === strtolower($path) || resolve_route(strtolower($path)))) {
            seo_normalise_redirect_to(url('/' . strtolower($path) . '/') . $suffix);
        }
    }
}

/**
 * index.php hook for a matched `redirects` row: 410 Gone for status_code 410, else the usual 301/302
 * (same target and query handling as index.php). Returns without output only if the target is unusable.
 */
function seo_normalise_redirect_response(array $redirect, string $query = ''): void
{
    $code = (int) ($redirect['status_code'] ?? 301);
    if ($code === 410) {
        seo_normalise_gone();
    }
    $targetUrl = trim((string) ($redirect['target_url'] ?? ''));
    if ($targetUrl === '') {
        return;
    }
    $target = preg_match('#^https?://#i', $targetUrl) ? $targetUrl : url($targetUrl);
    if ($query !== '' && strpos($target, '?') === false && preg_match('/^[\x21-\x7E]*$/', $query)) {
        $target .= '?' . $query;
    }
    seo_normalise_redirect_to($target, $code === 302 ? 302 : 301);
}

/** 410 Gone page for a deliberately removed address (redirects row with status_code 410). Stops. */
function seo_normalise_gone(): void
{
    http_response_code(410);
    seo_x_robots();
    $page = get_page('not-found', true) ?: ['id' => 0, 'slug' => 'not-found', 'template' => 'not_found', 'title' => 'Page removed',
        'banner_subtitle' => '', 'meta_title' => null, 'meta_description' => null, 'noindex' => 1, 'settings' => null, 'status' => 'published'];
    $GLOBALS['route'] = ['page' => $page, 'param' => ''];
    page_header($page, ['banner' => false, 'noindex' => true, 'title' => 'This page has been removed', 'meta_title' => 'Page removed']);
    echo '<section class="section notfound"><div class="container narrow text-center">'
        . '<h1>This page has been removed</h1>'
        . '<p class="lead">The page you are looking for was removed on purpose and is no longer available.</p>'
        . '<div class="btn-row center"><a class="btn btn-primary" href="' . e(page_url('home')) . '">' . icon('home') . 'Home page</a>'
        . '<a class="btn btn-outline" href="' . e(page_url('sitemap')) . '">' . icon('list') . 'All pages</a></div>'
        . '</div></section>';
    page_footer();
    exit;
}

// =====================================================================
// Slug changes: one-hop 301s without chains or loops
// =====================================================================

/**
 * Site-relative path ("blog/my-post", '' = home) of a site path, absolute URL on this host or redirect target,
 * without query or fragment. Null for another host, a protocol-relative URL or unsafe characters.
 */
function seo_slug_rel(string $url): ?string
{
    $url = trim($url);
    if ($url === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $url) || strpos($url, '//') === 0) {
        return null;
    }
    if (preg_match('#^https?://#i', $url)) {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || $host !== strtolower((string) parse_url(BASE_URL, PHP_URL_HOST))) {
            return null;
        }
        $url = (string) parse_url($url, PHP_URL_PATH);
    } elseif ($url[0] !== '/') {
        $url = '/' . $url;   // redirect sources are stored without the leading slash
    }
    $url = rawurldecode(substr($url, 0, strcspn($url, '?#')));
    $base = base_path();
    if ($base !== '' && ($url === $base || strpos($url, $base . '/') === 0)) {
        $url = substr($url, strlen($base));
    }
    $url = trim($url, '/');
    if ($url !== '' && (!preg_match('#^[A-Za-z0-9][A-Za-z0-9\-_./]*$#', $url) || strpos($url, '..') !== false || strpos($url, '//') !== false)) {
        return null;
    }
    return $url;
}

/** [relative path or null, "?query#fragment" suffix] of a stored redirect target or menu URL. */
function seo_slug_split_target(string $target): array
{
    $target = trim($target);
    $cut = strcspn($target, '?#');
    return [seo_slug_rel(substr($target, 0, $cut)), substr($target, $cut)];
}

/** LIKE pattern part with % and _ escaped. */
function seo_slug_like(string $s): string
{
    return addcslashes($s, '%_\\');
}

/**
 * Remove redirects whose source is $path, so a new item published at that address is reachable
 * (call when an entity is created at, or moved to, an address an old redirect still captures). Returns rows removed.
 */
function seo_slug_claim(string $path): int
{
    $rel = seo_slug_rel($path);
    if ($rel === null || $rel === '') {
        return 0;
    }
    try {
        return db_query('DELETE FROM redirects WHERE source_path IN (?, ?)', [$rel, $rel . '/'])->rowCount();
    } catch (Throwable $e) {
        error_log('seo_slug_claim: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Call after an entity's public address changed (post, story, event, campaign, scheme, page, programme, category).
 * $oldPath / $newPath: site paths from the URL helpers (post_url($oldSlug), event_url($oldRow) ...) or absolute URLs.
 *   1. the new address is released from any old redirect (seo_slug_claim())
 *   2. old -> new 301 (one hop; an existing row for the old address is updated)
 *   3. older redirects that pointed to the old address now point straight to the new one (no chains)
 *   4. menu links to the old address are updated (as admin/page-edit.php does for pages)
 *   5. the redirects table is checked for loops and chains (reported, never deleted)
 * Returns ['ok', 'source', 'target', 'retargeted', 'menus', 'released', 'loops', 'chains', 'message'].
 */
function seo_slug_changed(string $type, string $oldPath, string $newPath): array
{
    $type = preg_replace('/[^a-z_]/', '', strtolower($type)) ?: 'item';
    $result = ['ok' => false, 'source' => '', 'target' => '', 'retargeted' => 0, 'menus' => 0, 'released' => 0, 'loops' => [], 'chains' => [], 'message' => ''];
    $old = seo_slug_rel($oldPath);
    $new = seo_slug_rel($newPath);
    if ($old === null || $new === null) {
        $result['message'] = 'Not an address of this website - no redirect was created.';
        return $result;
    }
    if ($old === $new || $old === '') {
        $result['ok'] = true;
        $result['message'] = 'The address did not change.';
        return $result;
    }
    if (in_array(strtolower(explode('/', $old)[0]), SEO_SLUG_RESERVED, true)) {
        $result['message'] = 'The old address is reserved by the website - no redirect was created.';
        return $result;
    }
    $target = $new === '' ? '/' : '/' . $new . '/';
    $result['source'] = $old;
    $result['target'] = $target;
    try {
        if (db_value('SELECT COUNT(*) FROM pages WHERE slug = ?', [$old])) {
            $result['message'] = 'A page still uses the old address - no redirect was created.';
            return $result;
        }
        $result['released'] = seo_slug_claim($new);
        db_query('INSERT INTO redirects (source_path, target_url, status_code) VALUES (?, ?, 301)
                  ON DUPLICATE KEY UPDATE target_url = VALUES(target_url), status_code = 301', [$old, $target]);
        db_query('UPDATE redirects SET target_url = ?, status_code = 301 WHERE source_path = ?', [$target, $old . '/']);
        foreach (db_rows('SELECT id, source_path, target_url FROM redirects WHERE target_url LIKE ?', ['%' . seo_slug_like($old) . '%']) as $r) {
            [$rel, $rest] = seo_slug_split_target((string) $r['target_url']);
            if ($rel === null || strcasecmp($rel, $old) !== 0) {
                continue;
            }
            if (strcasecmp(trim((string) $r['source_path'], '/'), $new) === 0) {
                continue;   // cannot happen after the claim above; never create a self-redirect
            }
            db_query('UPDATE redirects SET target_url = ? WHERE id = ?', [$target . $rest, $r['id']]);
            $result['retargeted']++;
        }
        foreach (db_rows('SELECT id, url FROM menu_items WHERE url LIKE ?', ['%' . seo_slug_like($old) . '%']) as $mi) {
            [$rel, $rest] = seo_slug_split_target((string) $mi['url']);
            if ($rel !== null && strcasecmp($rel, $old) === 0 && !preg_match('#^https?://#i', (string) $mi['url'])) {
                db_query('UPDATE menu_items SET url = ? WHERE id = ?', [$target . $rest, $mi['id']]);
                $result['menus']++;
            }
        }
        $report = seo_slug_redirect_report();
        $result['loops'] = $report['loops'];
        $result['chains'] = $report['chains'];
    } catch (Throwable $e) {
        error_log('seo_slug_changed: ' . $e->getMessage());
        $result['message'] = 'The redirect could not be saved. Please add it in Redirects.';
        return $result;
    }
    $result['ok'] = true;
    $result['message'] = 'The old address /' . $old . '/ now redirects (301) to ' . $target
        . ($result['menus'] ? ' and ' . $result['menus'] . ' menu link(s) were updated' : '') . '.'
        . ($result['loops'] ? ' Warning: the redirects list contains a loop - check Redirects.' : '');
    if (function_exists('admin_log')) {
        admin_log('redirect_added', 'Automatic 301 redirect /' . $old . '/ -> ' . $target . ' after a ' . $type . ' address change'
            . ($result['retargeted'] ? ' (' . $result['retargeted'] . ' older redirect(s) re-pointed)' : ''));
    }
    return $result;
}

/**
 * Chains and loops in the redirects table (internal targets only; 410 rows end a path).
 * ['chains' => [['id', 'source', 'hops' => [paths], 'final' => path]], 'loops' => [['id', 'source', 'hops' => [paths]]]].
 */
function seo_slug_redirect_report(): array
{
    $map = [];
    foreach (seo_sitemap_rows('SELECT id, source_path, target_url, status_code FROM redirects') as $r) {
        $src = strtolower(trim((string) $r['source_path'], '/'));
        $to = (int) $r['status_code'] === 410 ? null : seo_slug_split_target((string) $r['target_url'])[0];
        $map[$src] = ['id' => (int) $r['id'], 'to' => $to === null ? null : strtolower($to)];
    }
    $chains = [];
    $loops = [];
    foreach ($map as $src => $r) {
        $hops = [];
        $seen = [$src => true];
        $cur = $r['to'];
        $loop = false;
        while ($cur !== null && isset($map[$cur]) && count($hops) < 20) {
            $hops[] = $cur;
            if (isset($seen[$cur])) {
                $loop = true;
                break;
            }
            $seen[$cur] = true;
            $cur = $map[$cur]['to'];
        }
        if ($loop || ($r['to'] !== null && $r['to'] === $src)) {
            $loops[] = ['id' => $r['id'], 'source' => $src, 'hops' => $hops ?: [$src]];
        } elseif ($hops) {
            $chains[] = ['id' => $r['id'], 'source' => $src, 'hops' => array_merge([$r['to']], array_slice($hops, 1)), 'final' => $cur];
        }
    }
    return ['chains' => $chains, 'loops' => $loops];
}

/**
 * Point every chained redirect straight at its final internal address (loops are left alone and reported).
 * For an admin "fix chains" button (WP-4). Returns the number of rows updated.
 */
function seo_slug_flatten_chains(): int
{
    $n = 0;
    foreach (seo_slug_redirect_report()['chains'] as $c) {
        if ($c['final'] === null) {
            continue;
        }
        try {
            $n += db_query('UPDATE redirects SET target_url = ? WHERE id = ?', [$c['final'] === '' ? '/' : '/' . $c['final'] . '/', $c['id']])->rowCount();
        } catch (Throwable $e) {
            error_log('seo_slug_flatten_chains: ' . $e->getMessage());
        }
    }
    return $n;
}

// =====================================================================
// IndexNow (optional, off by default)
// =====================================================================

/** The IndexNow key (public by design: it is served as /<key>.txt), or '' when not set / not valid. */
function seo_indexnow_key(): string
{
    $k = setting('indexnow_key');
    return preg_match('/^[A-Za-z0-9\-]{8,128}$/', $k) ? $k : '';
}

/** IndexNow is on, has a key, and the site is not in noindex mode. */
function seo_indexnow_enabled(): bool
{
    return setting('indexnow_enabled') === '1' && seo_indexnow_key() !== '' && setting('seo_noindex_site') !== '1';
}

/** Serve /<key>.txt (the key as plain text) when IndexNow is on and the path matches; otherwise return. */
function seo_indexnow_key_response(string $path): void
{
    if (!seo_indexnow_enabled() || $path !== seo_indexnow_key() . '.txt') {
        return;
    }
    seo_sitemap_send(seo_indexnow_key(), 'text/plain; charset=utf-8');
}

/**
 * Tell IndexNow-enabled search engines (Bing and others; Google does not use IndexNow) that these URLs changed.
 * Off unless setting indexnow_enabled = 1. Only absolute URLs or site paths on this site's host are sent (max 10,000).
 * A development address (localhost, .test, .local) is never announced to a public endpoint. Returns true on HTTP 200/202.
 * Call it after publishing or updating a public item - never for drafts, previews or private pages.
 */
function seo_indexnow_ping(array $urls): bool
{
    if (!seo_indexnow_enabled() || !function_exists('curl_init')) {
        return false;
    }
    $host = strtolower((string) parse_url(BASE_URL, PHP_URL_HOST));
    $list = [];
    foreach ($urls as $u) {
        $u = trim((string) $u);
        if ($u === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $u)) {
            continue;
        }
        if ($u[0] === '/' && strpos($u, '//') !== 0) {
            $u = seo_sitemap_abs($u);
        }
        if (!preg_match('#^https?://#i', $u) || strtolower((string) parse_url($u, PHP_URL_HOST)) !== $host) {
            continue;
        }
        $list[$u] = true;
        if (count($list) >= 10000) {
            break;
        }
    }
    if (!$list || $host === '') {
        return false;
    }
    $endpointHost = strtolower((string) parse_url(INDEXNOW_ENDPOINT, PHP_URL_HOST));
    $devHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || preg_match('/\.(local|test|localhost|invalid)$/', $host);
    if ($devHost && !in_array($endpointHost, ['localhost', '127.0.0.1'], true)) {
        return false;
    }
    $key = seo_indexnow_key();
    $payload = json_encode(['host' => $host, 'key' => $key, 'keyLocation' => abs_url($key . '.txt'), 'urlList' => array_keys($list)], JSON_UNESCAPED_SLASHES);
    $ch = curl_init(INDEXNOW_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOPROXY => 'localhost,127.0.0.1',
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $ok = $code === 200 || $code === 202;
    if (!$ok) {
        error_log('IndexNow ping not accepted: HTTP ' . $code . ($err !== '' ? ' (' . $err . ')' : '') . ' for ' . count($list) . ' URL(s)');
    }
    return $ok;
}

// =====================================================================
// Migrations 810-819 (idempotent, never overwrite admin-entered values)
// =====================================================================

/** WP-2 settings: canonical-host redirect (off), image sitemap (on), past events in the sitemap (on), IndexNow (off). */
function migration_810_seo_sitemap_settings(): void
{
    add_setting_if_missing('seo_canonical_host_redirect', '0');
    add_setting_if_missing('seo_sitemap_images', '1');
    add_setting_if_missing('seo_sitemap_past_events', '1');
    add_setting_if_missing('indexnow_enabled', '0');
}

/** IndexNow key: 32 random hex characters, created once. Public by design (served as /<key>.txt only while IndexNow is on). */
function migration_811_seo_indexnow_key(): void
{
    add_setting_if_missing('indexnow_key', bin2hex(random_bytes(16)));
}
