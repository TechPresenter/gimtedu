<?php
/**
 * News / Blog + Gallery (unit news_gallery) — data + render helpers shared by blog.php (/blog), post.php (/blog/{slug})
 * and gallery.php (/gallery, /gallery/{slug}). Prefix: ng_.
 *
 * Data sources (read-only except the per-session view counter):
 *   blog_posts + blog_categories + users (author name / designation / avatar only — never email or username),
 *   galleries + gallery_items + media, settings, seo_settings (rendered by includes/header.php via site_header seo refs).
 * Visibility (website): a post is public when status = 'published' and published_at is empty or in the past, or when
 * status = 'scheduled' and its publish time has passed (the admin promotes those with cms_blog_publish_due()); drafts
 * and future posts are 404. Categories must be active. Galleries: status = 'published'.
 */

/* ============================================================================ generic */

/** Run a DB read; on failure log once and return $default (pages must render even when a table is missing). */
function ng_try(callable $fn, $default = null)
{
    try {
        return $fn();
    } catch (Throwable $e) {
        static $logged = false;
        if (!$logged) {
            $logged = true;
            log_system('error', 'news_gallery: ' . $e->getMessage());
        }
        return $default;
    }
}

/** Absolute URL for a stored image path ("assets/…", "assets/uploads/…") or an external URL. */
function ng_abs(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    return preg_match('#^https?://#i', $path) ? $path : absolute_url(ltrim($path, '/'));
}

/**
 * Intrinsic [width, height] of a stored image: the media library row when it has dimensions, else the local file
 * (bundled assets/… or assets/uploads/…). Null for external URLs / unreadable files. Cached per request.
 */
function ng_image_size(?string $path): ?array
{
    static $cache = [];
    $path = trim((string) $path);
    if ($path === '' || preg_match('#^https?://#i', $path) || str_contains($path, '..')) {
        return null;
    }
    if (array_key_exists($path, $cache)) {
        return $cache[$path];
    }
    $row = ng_try(fn () => db_row('SELECT width, height FROM media WHERE path = ? AND width > 0 AND height > 0 LIMIT 1', [$path]), null);
    if ($row) {
        return $cache[$path] = [(int) $row['width'], (int) $row['height']];
    }
    $file = APP_ROOT . '/' . ltrim($path, '/');
    $info = is_file($file) ? @getimagesize($file) : false;
    return $cache[$path] = ($info && $info[0] > 0 && $info[1] > 0 ? [(int) $info[0], (int) $info[1]] : null);
}

/** Escape a LIKE pattern fragment. */
function ng_like(string $s): string
{
    return addcslashes($s, '%_\\');
}

/** BreadcrumbList JSON-LD from [[name, path|null], …] (null = current page). */
function ng_breadcrumb_ld(array $crumbs, string $current): array
{
    $items = [];
    foreach (array_values($crumbs) as $i => [$name, $path]) {
        $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $path === null ? $current : absolute_url(ltrim((string) $path, '/'))];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

/** Does the admin SEO record already print JSON-LD of one of $types (header.php renders seo_settings.schema_json)? */
function ng_seo_schema_has(array $types, string $entityType, ?int $entityId, ?string $route = null): bool
{
    $row = seo_lookup($entityType, $entityId, $route);
    if (empty($row['schema_json'])) {
        return false;
    }
    foreach ($types as $t) {
        if (preg_match('/"@type"\s*:\s*(\[[^\]]*)?"' . preg_quote($t, '/') . '"/', (string) $row['schema_json'])) {
            return true;
        }
    }
    return false;
}

/**
 * site_header() + extra tags injected just before </head> (rel prev/next, article:* Open Graph meta). header.php has no
 * hook for arbitrary head tags, so its output is buffered once and the tags are inserted.
 */
function ng_site_header(array $page, string $headExtra = ''): void
{
    if (trim($headExtra) === '') {
        site_header($page);
        return;
    }
    ob_start();
    site_header($page);
    $html = (string) ob_get_clean();
    $pos = stripos($html, '</head>');
    echo $pos === false ? $html . $headExtra : substr($html, 0, $pos) . '  ' . $headExtra . "\n" . substr($html, $pos);
}

/** Small round initials avatar or a photo. */
function ng_avatar(?string $photo, string $name, string $class = 'h-11 w-11 text-sm'): string
{
    if ($photo) {
        return '<img src="' . e(upload_url($photo)) . '" alt="" class="' . e($class) . ' shrink-0 rounded-full object-cover" loading="lazy" decoding="async" width="64" height="64">';
    }
    return '<span class="' . e($class) . ' inline-flex shrink-0 items-center justify-center rounded-full bg-brand-900 font-display font-bold text-white" aria-hidden="true">' . e(site_initials($name) ?: 'G') . '</span>';
}

/** Is an internal path served right now (page file or published CMS page)? Used before linking to optional pages. */
function ng_route_ok(string $path): bool
{
    static $cache = [];
    $path = '/' . trim($path, '/');
    if (isset($cache[$path])) {
        return $cache[$path];
    }
    $slug = trim($path, '/');
    $ok = $slug === '' || (preg_match('/^[a-z0-9-]+$/', $slug) && is_file(APP_ROOT . '/' . $slug . '.php'));
    if (!$ok && preg_match('/^[a-z0-9-]+$/', $slug) && is_file(APP_ROOT . '/page.php')) {
        $ok = (bool) ng_try(fn () => db_value("SELECT 1 FROM pages WHERE slug = ? AND status = 'published' AND (published_at IS NULL OR published_at <= ?)", [$slug, date('Y-m-d H:i:s')]), false);
    }
    return $cache[$path] = $ok;
}

/* ============================================================================ blog: URLs + filters */

/** Query string for the blog listing with only meaningful params, in a stable order (canonical-friendly). */
function ng_blog_query(array $params): string
{
    $q = [];
    foreach (['category', 'tag', 'q', 'sort', 'page'] as $k) {
        $v = $params[$k] ?? null;
        if ($v === null || $v === '' || ($k === 'page' && (int) $v <= 1) || ($k === 'sort' && $v !== 'popular')) {
            continue;
        }
        $q[$k] = (string) $v;
    }
    return $q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '';
}

function ng_blog_url(array $params = []): string
{
    return site_url('blog') . ng_blog_query($params);
}

function ng_blog_abs(array $params = []): string
{
    return absolute_url('blog') . ng_blog_query($params);
}

function ng_post_url(string $slug): string
{
    return site_url('blog/' . $slug);
}

/** Normalised tag filter value (lower case, ≤ 60 chars, letters/digits/space/.-&+#). */
function ng_clean_tag($v): string
{
    $v = mb_strtolower(trim((string) (is_string($v) ? $v : '')));
    $v = (string) preg_replace('/[^\p{L}\p{N} .&+#\-]/u', '', $v);
    return mb_substr(trim((string) preg_replace('/\s+/', ' ', $v)), 0, 60);
}

/** Normalised search query (≤ 100 chars, single spaces). */
function ng_clean_query($v): string
{
    $v = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) (is_string($v) ? $v : ''))));
    return mb_substr($v, 0, 100);
}

/** Tags of a post (CSV column) → unique display strings. */
function ng_tags(?string $csv): array
{
    $out = [];
    foreach (explode(',', (string) $csv) as $t) {
        $t = trim($t);
        if ($t !== '' && !isset($out[mb_strtolower($t)])) {
            $out[mb_strtolower($t)] = $t;
        }
    }
    return array_values($out);
}

/** [sql, params] for "post is public now" on alias $a. */
function ng_visible_where(string $a = 'p'): array
{
    $now = date('Y-m-d H:i:s');
    return ["(($a.status = 'published' AND ($a.published_at IS NULL OR $a.published_at <= ?)) OR ($a.status = 'scheduled' AND $a.published_at IS NOT NULL AND $a.published_at <= ?))", [$now, $now]];
}

/** Columns for listings/cards (no full content — word count is computed in SQL from spaces, like ng_reading_minutes()). */
function ng_post_columns(): string
{
    return "p.id, p.title, p.slug, p.thumbnail, p.excerpt, p.tags, p.type, p.is_featured, p.views, p.category_id, p.author_id,
        COALESCE(p.published_at, p.created_at) AS pub_date, COALESCE(p.updated_at, p.published_at, p.created_at) AS mod_date,
        (CHAR_LENGTH(COALESCE(p.content, '')) - CHAR_LENGTH(REPLACE(COALESCE(p.content, ''), ' ', ''))) + 1 AS words,
        c.name AS category_name, c.slug AS category_slug,
        u.name AS author_name, u.username AS author_username, u.designation AS author_designation, u.avatar AS author_avatar";
}

function ng_post_from(): string
{
    return "blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id AND c.status = 'active' LEFT JOIN users u ON u.id = p.author_id";
}

/** [where, params] for listing filters: category_id, tag, q (every term must match the title, excerpt, tags, category
 * or article text), exclude (ids). */
function ng_post_filters(array $f): array
{
    [$where, $args] = ng_visible_where();
    $w = [$where];
    if (!empty($f['category_id'])) {
        $w[] = 'p.category_id = ?';
        $args[] = (int) $f['category_id'];
    }
    if (($f['tag'] ?? '') !== '') {
        $w[] = "CONCAT(',', REPLACE(REPLACE(LOWER(COALESCE(p.tags, '')), ', ', ','), ' ,', ','), ',') LIKE ?";
        $args[] = '%,' . ng_like(mb_strtolower((string) $f['tag'])) . ',%';
    }
    if (($f['q'] ?? '') !== '') {
        $terms = array_slice(array_values(array_filter(preg_split('/\s+/u', (string) $f['q']) ?: [], fn ($t) => mb_strlen($t) >= 2)), 0, 6) ?: [(string) $f['q']];
        foreach ($terms as $t) {
            $like = '%' . ng_like($t) . '%';
            $w[] = '(p.title LIKE ? OR p.excerpt LIKE ? OR p.tags LIKE ? OR c.name LIKE ? OR p.content LIKE ?)';
            array_push($args, $like, $like, $like, $like, $like);
        }
    }
    if (!empty($f['exclude'])) {
        $ids = array_values(array_map('intval', (array) $f['exclude']));
        $w[] = 'p.id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        array_push($args, ...$ids);
    }
    return [implode(' AND ', $w), $args];
}

function ng_count_posts(array $f = []): int
{
    [$w, $a] = ng_post_filters($f);
    return (int) ng_try(fn () => db_value('SELECT COUNT(*) FROM ' . ng_post_from() . " WHERE $w", $a), 0);
}

/** Posts for a listing. $sort: latest | popular. */
function ng_posts(array $f, int $limit, int $offset = 0, string $sort = 'latest'): array
{
    [$w, $a] = ng_post_filters($f);
    $order = $sort === 'popular' ? 'p.views DESC, pub_date DESC, p.id DESC' : 'pub_date DESC, p.id DESC';
    return ng_try(fn () => db_all('SELECT ' . ng_post_columns() . ' FROM ' . ng_post_from() . " WHERE $w ORDER BY $order LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset), $a), []);
}

/** Latest featured posts (is_featured = 1). */
function ng_featured(int $limit = 4): array
{
    [$w, $a] = ng_visible_where();
    return ng_try(fn () => db_all('SELECT ' . ng_post_columns() . ' FROM ' . ng_post_from() . " WHERE $w AND p.is_featured = 1 ORDER BY pub_date DESC, p.id DESC LIMIT " . max(1, $limit), $a), []);
}

/** Active categories with their number of public posts, in admin order. */
function ng_categories(): array
{
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }
    [$w, $a] = ng_visible_where();
    $rows = ng_try(fn () => db_all("SELECT c.id, c.name, c.slug, c.description, COUNT(p.id) AS posts
        FROM blog_categories c LEFT JOIN blog_posts p ON p.category_id = c.id AND $w
        WHERE c.status = 'active' GROUP BY c.id, c.name, c.slug, c.description, c.sort_order ORDER BY c.sort_order, c.name", $a), null);
    if ($rows === null) { // before schema 37 (no sort_order)
        $rows = ng_try(fn () => db_all("SELECT c.id, c.name, c.slug, c.description, COUNT(p.id) AS posts
            FROM blog_categories c LEFT JOIN blog_posts p ON p.category_id = c.id AND $w
            WHERE c.status = 'active' GROUP BY c.id, c.name, c.slug, c.description ORDER BY c.name", $a), []);
    }
    return $rows;
}

/** Most used tags across public posts: [['tag', 'key', 'count'], …]. */
function ng_popular_tags(int $limit = 14): array
{
    [$w, $a] = ng_visible_where();
    $rows = ng_try(fn () => db_column("SELECT p.tags FROM blog_posts p WHERE $w AND p.tags IS NOT NULL AND p.tags <> '' ORDER BY p.published_at DESC LIMIT 400", $a), []);
    $count = [];
    $label = [];
    foreach ($rows as $csv) {
        foreach (ng_tags($csv) as $t) {
            $k = mb_strtolower($t);
            $count[$k] = ($count[$k] ?? 0) + 1;
            $label[$k] ??= $t;
        }
    }
    uksort($count, fn ($x, $y) => [$count[$y], $x] <=> [$count[$x], $y]);
    $out = [];
    foreach (array_slice($count, 0, $limit, true) as $k => $n) {
        $out[] = ['tag' => $label[$k], 'key' => $k, 'count' => $n];
    }
    return $out;
}

/** Totals for hero chips: posts, latest publish date (optionally within one category). */
function ng_blog_totals(?int $categoryId = null): array
{
    [$w, $a] = ng_visible_where();
    if ($categoryId) {
        $w .= ' AND p.category_id = ?';
        $a[] = $categoryId;
    }
    $row = ng_try(fn () => db_row("SELECT COUNT(*) AS posts, MAX(COALESCE(p.published_at, p.created_at)) AS latest FROM blog_posts p WHERE $w", $a), null);
    return ['posts' => (int) ($row['posts'] ?? 0), 'latest' => $row['latest'] ?? null];
}

/** Reading time in minutes from a word count (200 wpm, min 1). */
function ng_reading_minutes(int $words): int
{
    return max(1, (int) ceil($words / 200));
}

/** Post type → [label, icon]. */
function ng_type_meta(?string $type): array
{
    return [
        'news' => ['News', 'newspaper'], 'blog' => ['Blog', 'pen-line'], 'press' => ['Press Release', 'megaphone'], 'achievement' => ['Achievement', 'trophy'],
    ][$type ?? ''] ?? ['Article', 'file-text'];
}

/**
 * Display author of a post: the user's name + designation; system/admin accounts (or no author) are shown as the
 * institute. Returns ['name', 'role', 'avatar', 'org' (bool)].
 */
function ng_author(array $p): array
{
    $name = trim((string) ($p['author_name'] ?? ''));
    $system = $name === '' || ($p['author_username'] ?? '') === 'admin' || strcasecmp($name, 'Administrator') === 0;
    if ($system) {
        return ['name' => (string) setting('institute_short_name', 'GIMT'), 'role' => institute_name(), 'avatar' => null, 'org' => true];
    }
    $role = trim((string) ($p['author_designation'] ?? ''));
    return ['name' => $name, 'role' => strcasecmp($role, 'Super Admin') === 0 ? '' : $role, 'avatar' => $p['author_avatar'] ?: null, 'org' => false];
}

/** Excerpt for cards/meta: the excerpt column, else the start of the content. */
function ng_excerpt(array $p, int $limit = 160): string
{
    $x = trim((string) ($p['excerpt'] ?? ''));
    return str_limit($x !== '' ? $x : (string) ($p['content'] ?? ''), $limit);
}

/** <time> element. */
function ng_time(?string $date, string $format = 'd M Y'): string
{
    $ts = $date ? strtotime($date) : false;
    return $ts ? '<time datetime="' . e(date('Y-m-d', $ts)) . '">' . e(date($format, $ts)) . '</time>' : '';
}

/* ============================================================================ blog: render */

/** Category pill (small, orange tint) linking to the category listing. */
function ng_category_tag(array $p, string $class = ''): string
{
    if (empty($p['category_slug'])) {
        return '';
    }
    return '<a href="' . e(ng_blog_url(['category' => $p['category_slug']])) . '" class="relative z-[2] inline-flex items-center rounded-full bg-accent-50 px-2.5 py-1 text-[11.5px] font-bold uppercase tracking-[0.08em] text-accent-800 ring-1 ring-inset ring-accent-100 transition hover:bg-accent-100' . ($class !== '' ? ' ' . e($class) : '') . '">' . e($p['category_name']) . '</a>';
}

/**
 * Post card for listings (view-mode aware: .view-item / .vm-media / .vm-body / .vm-text / .vm-extra).
 * $o: heading (h2|h3), lazy (bool, default true), show_extra (bool, default true)
 */
function ng_post_card(array $p, array $o = []): string
{
    $url = ng_post_url((string) $p['slug']);
    $h = $o['heading'] ?? 'h3';
    $author = ng_author($p);
    [$typeLabel, $typeIcon] = ng_type_meta($p['type'] ?? null);
    $mins = ng_reading_minutes((int) ($p['words'] ?? 0));
    $lazy = ($o['lazy'] ?? true) ? ' loading="lazy"' : ' fetchpriority="high"';
    $html = '<article class="view-item group flex flex-col">';
    $html .= '<a href="' . e($url) . '" class="vm-media img-zoom" tabindex="-1" aria-hidden="true">'
        . '<img src="' . e(site_image($p['thumbnail'] ?? null, 'assets/images/site/news-campus.jpg')) . '" alt=""' . $lazy . ' decoding="async" width="640" height="400">'
        . '<span class="absolute left-3 top-3 inline-flex items-center gap-1.5 rounded-full bg-white/95 px-2.5 py-1 text-[11.5px] font-bold text-brand-900 shadow-sm">' . icon($typeIcon, 'h-3.5 w-3.5 text-accent-700') . e($typeLabel) . '</span></a>';
    $html .= '<div class="vm-body flex-1">';
    $html .= '<div class="flex flex-wrap items-center gap-2">' . ng_category_tag($p) . '</div>';
    $html .= '<' . $h . ' class="font-display text-[1.08rem] font-bold leading-snug text-brand-900"><a href="' . e($url) . '" class="stretched-link transition-colors group-hover:text-accent-800">' . e($p['title']) . '</a></' . $h . '>';
    $excerpt = ng_excerpt($p, 150);
    if ($excerpt !== '') {
        $html .= '<p class="vm-text text-sm leading-relaxed text-slate-600">' . e($excerpt) . '</p>';
    }
    $html .= '<p class="mt-auto flex flex-wrap items-center gap-x-3 gap-y-1 pt-1 text-[12.5px] text-slate-500">'
        . '<span class="inline-flex items-center gap-1">' . icon('calendar-days', 'h-3.5 w-3.5') . ng_time($p['pub_date'] ?? null) . '</span>'
        . '<span class="inline-flex items-center gap-1">' . icon('clock', 'h-3.5 w-3.5') . $mins . ' min read</span>'
        . '<span class="inline-flex items-center gap-1">' . icon('user', 'h-3.5 w-3.5') . '<span class="sr-only">By </span>' . e($author['name']) . '</span></p>';
    if ($o['show_extra'] ?? true) {
        $tags = array_slice(ng_tags($p['tags'] ?? ''), 0, 4);
        $html .= '<div class="vm-extra border-t border-warm-2 pt-3"><div class="flex flex-wrap items-center gap-1.5">';
        foreach ($tags as $t) {
            $html .= '<a href="' . e(ng_blog_url(['tag' => mb_strtolower($t)])) . '" class="relative z-[2] rounded-full bg-warm px-2.5 py-1 text-xs font-medium text-slate-600 transition hover:bg-brand-900 hover:text-white">#' . e($t) . '</a>';
        }
        $html .= (int) ($p['views'] ?? 0) > 0 ? '<span class="ml-auto inline-flex items-center gap-1 text-xs text-slate-500">' . icon('eye', 'h-3.5 w-3.5') . e(number_format((int) $p['views'])) . ' reads</span>' : '';
        $html .= '</div></div>';
    }
    return $html . '</div></article>';
}

/** Compact post row (thumbnail + title + date) for sidebars and "editor's picks". */
function ng_post_row(array $p, string $class = ''): string
{
    return '<li class="group relative flex items-start gap-3.5' . ($class !== '' ? ' ' . e($class) : '') . '">'
        . '<span class="img-zoom block h-16 w-20 shrink-0 overflow-hidden rounded-xl bg-warm"><img src="' . e(site_image($p['thumbnail'] ?? null, 'assets/images/site/news-campus.jpg')) . '" alt="" loading="lazy" decoding="async" width="160" height="128" class="h-full w-full object-cover"></span>'
        . '<span class="min-w-0"><a href="' . e(ng_post_url((string) $p['slug'])) . '" class="stretched-link line-clamp-2 text-[14.5px] font-semibold leading-snug text-brand-900 transition-colors group-hover:text-accent-800">' . e($p['title']) . '</a>'
        . '<span class="mt-1 block text-xs text-slate-500">' . ng_time($p['pub_date'] ?? null) . (!empty($p['category_name']) ? ' · ' . e($p['category_name']) : '') . '</span></span></li>';
}

/** Big featured story card (image with navy fade, white text). */
function ng_featured_card(array $p): string
{
    $url = ng_post_url((string) $p['slug']);
    $author = ng_author($p);
    $img = site_image($p['thumbnail'] ?? null, 'assets/images/site/news-campus.jpg');
    return '<article class="group relative isolate flex min-h-[380px] items-end overflow-hidden rounded-[28px] bg-brand-900 sm:min-h-[440px] lg:h-full lg:min-h-[480px]">'
        . '<img src="' . e($img) . '" alt="" class="absolute inset-0 -z-[2] h-full w-full object-cover transition duration-700 ease-out group-hover:scale-[1.04]" fetchpriority="high" decoding="async" width="1200" height="750">'
        . '<span class="absolute inset-0 -z-[1] bg-gradient-to-t from-navy-deep via-navy-deep/75 to-navy-deep/5" aria-hidden="true"></span>'
        . '<div class="relative w-full max-w-3xl p-6 sm:p-9">'
        . '<p class="flex flex-wrap items-center gap-2"><span class="inline-flex items-center gap-1.5 rounded-full bg-accent-600 px-3 py-1 text-[11.5px] font-bold uppercase tracking-[0.1em] text-white">' . icon('sparkles', 'h-3.5 w-3.5') . 'Featured</span>'
        . (!empty($p['category_name']) ? '<a href="' . e(ng_blog_url(['category' => $p['category_slug']])) . '" class="relative z-[2] rounded-full bg-white/15 px-3 py-1 text-[11.5px] font-bold uppercase tracking-[0.1em] text-white ring-1 ring-inset ring-white/25 transition hover:bg-white hover:text-brand-900">' . e($p['category_name']) . '</a>' : '') . '</p>'
        . '<h3 class="mt-4 font-display text-[1.6rem] font-extrabold leading-tight text-white sm:text-[2.1rem]"><a href="' . e($url) . '" class="stretched-link">' . e($p['title']) . '</a></h3>'
        . (ng_excerpt($p, 200) !== '' ? '<p class="mt-3 line-clamp-3 max-w-2xl text-[15px] leading-relaxed text-white/80">' . e(ng_excerpt($p, 200)) . '</p>' : '')
        . '<p class="mt-5 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[13px] text-white/75">'
        . '<span class="inline-flex items-center gap-1.5">' . icon('user', 'h-4 w-4') . e($author['name']) . '</span>'
        . '<span class="inline-flex items-center gap-1.5">' . icon('calendar-days', 'h-4 w-4') . ng_time($p['pub_date'] ?? null) . '</span>'
        . '<span class="inline-flex items-center gap-1.5">' . icon('clock', 'h-4 w-4') . ng_reading_minutes((int) ($p['words'] ?? 0)) . ' min read</span></p>'
        . '<span class="mt-6 inline-flex items-center gap-2 rounded-full bg-white px-5 py-2.5 text-sm font-semibold text-brand-900 transition group-hover:gap-3" aria-hidden="true">Read the story' . icon('arrow-right', 'h-4 w-4') . '</span>'
        . '</div></article>';
}

/**
 * Numbered pagination (prev / pages with ellipsis / next) with rel="prev|next". $url(int $page): string.
 */
function ng_pagination(array $pg, callable $url, string $label = 'Pagination', string $noun = 'articles'): string
{
    if ($pg['pages'] <= 1) {
        return '';
    }
    $cur = (int) $pg['page'];
    $last = (int) $pg['pages'];
    $nums = array_unique(array_filter([1, $cur - 1, $cur, $cur + 1, $last], fn ($n) => $n >= 1 && $n <= $last));
    sort($nums);
    $btn = 'inline-flex h-11 min-w-[2.75rem] items-center justify-center gap-1.5 rounded-full border px-4 text-sm font-semibold transition';
    $idle = ' border-warm-2 bg-white text-brand-900 hover:-translate-y-0.5 hover:border-brand-900';
    $html = '<nav class="mt-12 flex flex-wrap items-center justify-between gap-3" aria-label="' . e($label) . '">';
    $html .= $cur > 1
        ? '<a href="' . e($url($cur - 1)) . '" rel="prev" class="' . $btn . $idle . '">' . icon('arrow-left', 'h-4 w-4') . 'Previous</a>'
        : '<span class="' . $btn . ' cursor-not-allowed border-warm-2 bg-white/60 text-slate-400" aria-disabled="true">' . icon('arrow-left', 'h-4 w-4') . 'Previous</span>';
    $html .= '<ol class="hidden items-center gap-1.5 sm:flex">';
    $prev = 0;
    foreach ($nums as $n) {
        if ($n - $prev > 1) {
            $html .= '<li class="px-1 text-slate-400" aria-hidden="true">…</li>';
        }
        $html .= $n === $cur
            ? '<li><span class="' . $btn . ' !px-0 border-brand-900 bg-brand-900 text-white" aria-current="page"><span class="sr-only">Page </span>' . $n . '</span></li>'
            : '<li><a href="' . e($url($n)) . '" class="' . $btn . ' !px-0' . $idle . '"' . ($n === $cur - 1 ? ' rel="prev"' : ($n === $cur + 1 ? ' rel="next"' : '')) . '><span class="sr-only">Page </span>' . $n . '</a></li>';
        $prev = $n;
    }
    $html .= '</ol><p class="text-sm font-medium text-slate-600 sm:hidden">Page ' . $cur . ' of ' . $last . '</p>';
    $html .= $cur < $last
        ? '<a href="' . e($url($cur + 1)) . '" rel="next" class="' . $btn . $idle . '">Next' . icon('arrow-right', 'h-4 w-4') . '</a>'
        : '<span class="' . $btn . ' cursor-not-allowed border-warm-2 bg-white/60 text-slate-400" aria-disabled="true">Next' . icon('arrow-right', 'h-4 w-4') . '</span>';
    return $html . '</nav><p class="mt-3 text-center text-xs text-slate-500 sm:text-right">Showing ' . (int) $pg['from'] . '–' . (int) $pg['to'] . ' of ' . (int) $pg['total'] . ' ' . e($noun) . '</p>';
}

/** <link rel=prev/next> tags for <head>. */
function ng_rel_links(array $pg, callable $absUrl): string
{
    $h = '';
    if ($pg['page'] > 1) {
        $h .= '<link rel="prev" href="' . e($absUrl($pg['page'] - 1)) . '">';
    }
    if ($pg['page'] < $pg['pages']) {
        $h .= '<link rel="next" href="' . e($absUrl($pg['page'] + 1)) . '">';
    }
    return $h;
}

/** GET search form for the news listing (keeps the category). */
function ng_search_form(string $q = '', ?string $category = null, string $id = 'blog-search'): string
{
    return '<form action="' . e(site_url('blog')) . '" method="get" role="search" class="flex w-full max-w-xl items-center gap-2 rounded-full border border-warm-2 bg-white p-1.5 pl-5 shadow-sm transition focus-within:border-brand-900 focus-within:ring-2 focus-within:ring-accent-500/40">'
        . '<label for="' . e($id) . '" class="sr-only">Search news and articles</label>' . icon('search', 'h-5 w-5 shrink-0 text-slate-400')
        . '<input id="' . e($id) . '" name="q" type="search" value="' . e($q) . '" maxlength="100" placeholder="Search news &amp; articles…" class="min-w-0 flex-1 border-0 bg-transparent py-2 text-[15px] text-brand-900 placeholder:text-slate-400 focus:outline-none focus:ring-0" autocomplete="off">'
        . ($category ? '<input type="hidden" name="category" value="' . e($category) . '">' : '')
        . '<button type="submit" class="btn btn-explore btn-sm shrink-0">Search</button></form>';
}

/**
 * Newsletter signup (POST /api/public/newsletter via the vanilla data-ajax-form handler; plain POST without JS gets the
 * API's standalone result page). $variant: card (narrow sidebar: stacked on lg) | band (wide: visible label, one row).
 */
function ng_newsletter_form(string $source, string $variant = 'card'): string
{
    static $n = 0;
    $id = 'ng-newsletter-' . (++$n);
    $band = $variant === 'band';
    $privacy = ng_route_ok('/privacy-policy') ? ' Read our <a href="' . e(site_url('privacy-policy')) . '" class="underline underline-offset-2 hover:text-brand-900">privacy policy</a>.' : '';
    return '<form action="' . e(base_url('api/public/newsletter')) . '" method="post" data-ajax-form data-success="Thank you for subscribing! You will now receive admission, campus and event updates." class="' . ($band ? 'w-full' : 'mt-4') . '">'
        . csrf_field() . '<input type="hidden" name="source" value="' . e($source) . '">'
        . '<input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">'
        . '<label for="' . $id . '" class="' . ($band ? 'form-label' : 'sr-only') . '">Email address</label>'
        . '<div class="flex flex-col gap-2 sm:flex-row' . ($band ? '' : ' lg:flex-col xl:flex-row') . '">'
        . '<input id="' . $id . '" name="email" type="email" required maxlength="190" autocomplete="email" placeholder="you@example.com" class="form-input min-w-0 flex-1 !rounded-full">'
        . '<button type="submit" class="btn ' . ($band ? 'btn-apply' : 'btn-explore') . ' shrink-0">Subscribe' . icon('arrow-right', 'h-4 w-4') . '</button></div>'
        . '<p class="form-error" data-error-for="email"></p><p class="form-message" data-form-message></p>'
        . '<p class="mt-2 text-xs text-slate-500">Admission updates, events and campus stories. No spam.' . $privacy . '</p></form>';
}

/** Admissions card (CTA system: Apply = primary, Explore = navy, Brochure = outline, Callback = soft). */
function ng_admission_card(): string
{
    $deadline = site_admissions_deadline();
    return '<div class="relative overflow-hidden rounded-[22px] bg-brand-900 bg-mesh-navy p-6 text-white">'
        . '<p class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-bold uppercase tracking-[0.12em] text-accent-300 ring-1 ring-inset ring-white/15">' . icon('calendar-check', 'h-3.5 w-3.5') . e((string) setting('admissions_open_text', 'Admissions Open for 2026-27')) . '</p>'
        . '<h2 class="mt-4 font-display text-xl font-extrabold leading-snug text-white">Begin your journey at GIMT</h2>'
        . '<p class="mt-2 text-sm leading-relaxed text-white/75">Industry-focused UG, PG, diploma and certificate programs' . ($deadline ? ' — apply by ' . e(date('d M Y', $deadline)) : '') . '.</p>'
        . '<div class="mt-5 grid gap-2.5">'
        . button_link('Apply for Admission', '/apply', 'apply', ['class' => 'w-full'])
        . button_link('Explore Courses', '/programs', 'white', ['class' => 'w-full', 'icon' => 'arrow-right'])
        . '<div class="grid grid-cols-2 gap-2.5">'
        . button_link('Brochure', '/programs?brochure=1', 'outline-light', ['size' => 'sm', 'icon' => '', 'icon_left' => 'download', 'attrs' => ['aria-label' => 'Download Brochure']])
        . button_link('Callback', '/contact?type=admission#message', 'callback', ['size' => 'sm', 'icon_left' => 'phone', 'attrs' => ['data-modal-open' => '#enquiry-modal', 'aria-label' => 'Request a Callback']])
        . '</div></div></div>';
}

/* ============================================================================ blog: article */

/**
 * Prepare article HTML: re-sanitise, demote stray <h1>s, give h2/h3 stable ids (table of contents + deep links), lazy
 * images, safe external links. Returns [html, toc [['level', 'id', 'text']], words].
 */
function ng_article_html(?string $raw): array
{
    $html = sanitize_html((string) $raw);
    $html = (string) preg_replace(['#<h1(\s|>)#i', '#</h1>#i'], ['<h2$1', '</h2>'], $html);
    $toc = [];
    // ids already used by the page chrome / post.php sections must not be reused by headings
    $used = array_fill_keys(['main', 'related', 'article-title', 'author-name', 'share-title', 'newsletter-title', 'toc-mobile', 'toc-desktop',
        'site-header', 'mobile-menu', 'site-search', 'enquiry-modal', 'site-loader', 'top', 'content'], true);
    $html = (string) preg_replace_callback('#<(h[23])((?:\s[^>]*)?)>(.*?)</\1>#is', function ($m) use (&$toc, &$used) {
        $text = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '') {
            return $m[0];
        }
        $base = mb_substr(slugify($text), 0, 60);
        $id = $base;
        for ($i = 2; isset($used[$id]); $i++) {
            $id = $base . '-' . $i;
        }
        $used[$id] = true;
        $toc[] = ['level' => (int) substr($m[1], 1), 'id' => $id, 'text' => $text];
        return '<' . $m[1] . preg_replace('/\sid\s*=\s*("[^"]*"|\'[^\']*\'|\S+)/i', '', $m[2]) . ' id="' . e($id) . '">' . $m[3] . '</' . $m[1] . '>';
    }, $html);
    $html = (string) preg_replace('#<img(?![^>]*\sloading=)#i', '<img loading="lazy" decoding="async"', $html);
    $html = (string) preg_replace('#<a\s(?=[^>]*target\s*=\s*["\']?_blank)(?![^>]*\srel=)#i', '<a rel="noopener" ', $html);
    $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);
    return [$html, $toc, $words];
}

/** Share links (open in new tabs) + JS-only copy / native share buttons (revealed by ng_share_script()). */
function ng_share_buttons(string $absUrl, string $title, string $class = ''): string
{
    $u = rawurlencode($absUrl);
    $t = rawurlencode($title);
    $links = [
        ['Facebook', 'facebook', 'https://www.facebook.com/sharer/sharer.php?u=' . $u],
        ['X', 'x-twitter', 'https://twitter.com/intent/tweet?url=' . $u . '&text=' . $t],
        ['LinkedIn', 'linkedin', 'https://www.linkedin.com/sharing/share-offsite/?url=' . $u],
        ['WhatsApp', 'whatsapp-brand', 'https://wa.me/?text=' . rawurlencode($title . ' ' . $absUrl)],
    ];
    $btn = 'inline-flex h-10 w-10 items-center justify-center rounded-full border border-warm-2 bg-white text-brand-900 transition hover:-translate-y-0.5 hover:border-brand-900 hover:bg-brand-900 hover:text-white';
    $html = '<ul class="flex flex-wrap items-center gap-2' . ($class !== '' ? ' ' . e($class) : '') . '">';
    foreach ($links as [$name, $ic, $href]) {
        $html .= '<li><a href="' . e($href) . '" target="_blank" rel="noopener noreferrer" class="' . $btn . '" title="Share on ' . e($name) . '">' . icon($ic, 'h-[18px] w-[18px]') . '<span class="sr-only">Share on ' . e($name) . ' (opens in a new tab)</span></a></li>';
    }
    $html .= '<li><a href="mailto:?subject=' . $t . '&amp;body=' . e(rawurlencode($title . "\n\n" . $absUrl)) . '" class="' . $btn . '" title="Share by email">' . icon('mail', 'h-[18px] w-[18px]') . '<span class="sr-only">Share by email</span></a></li>';
    $html .= '<li><button type="button" class="' . $btn . ' hidden" data-ng-copy="' . e($absUrl) . '" title="Copy link">' . icon('link', 'h-[18px] w-[18px]') . '<span class="sr-only" data-ng-copy-label>Copy link</span></button></li>';
    $html .= '<li><button type="button" class="' . $btn . ' hidden" data-ng-share="' . e($absUrl) . '" data-title="' . e($title) . '" title="More sharing options">' . icon('share-2', 'h-[18px] w-[18px]') . '<span class="sr-only">More sharing options</span></button></li>';
    return $html . '</ul>';
}

/** Inline enhancement for the copy-link / native share buttons (they stay hidden without JS). */
function ng_share_script(): string
{
    return '<p class="sr-only" aria-live="polite" data-ng-live></p><script>(function(d,n){var live=d.querySelector("[data-ng-live]");'
        . 'd.querySelectorAll("[data-ng-copy]").forEach(function(b){b.classList.remove("hidden");b.addEventListener("click",function(){var u=b.getAttribute("data-ng-copy"),ok=function(){if(live){live.textContent="Link copied to clipboard"}b.setAttribute("title","Link copied");b.classList.add("!bg-brand-900","!text-white");setTimeout(function(){b.setAttribute("title","Copy link");b.classList.remove("!bg-brand-900","!text-white");if(live){live.textContent=""}},2200)};'
        . 'if(n.clipboard&&n.clipboard.writeText){n.clipboard.writeText(u).then(ok,function(){window.prompt("Copy this link",u)})}else{window.prompt("Copy this link",u)}})});'
        . 'if(n.share){d.querySelectorAll("[data-ng-share]").forEach(function(b){b.classList.remove("hidden");b.addEventListener("click",function(){n.share({title:b.getAttribute("data-title")||d.title,url:b.getAttribute("data-ng-share")}).catch(function(){})})})}})(document,navigator);</script>';
}

/** Table of contents list. */
function ng_toc(array $toc, string $id): string
{
    $html = '<ol class="mt-3 space-y-1 border-l-2 border-warm-2 text-[14px]" id="' . e($id) . '">';
    foreach ($toc as $t) {
        $html .= '<li><a href="#' . e($t['id']) . '" class="-ml-0.5 block border-l-2 border-transparent py-1 ' . ($t['level'] === 3 ? 'pl-7 text-[13.5px]' : 'pl-4') . ' text-slate-600 transition hover:border-accent-600 hover:text-brand-900">' . e($t['text']) . '</a></li>';
    }
    return $html . '</ol>';
}

/** Public post by slug (null when missing, draft or scheduled in the future). */
function ng_post_by_slug(string $slug): ?array
{
    if (!preg_match('/^[a-z0-9-]{1,190}$/', $slug)) {
        return null;
    }
    [$w, $a] = ng_visible_where();
    return ng_try(fn () => db_row('SELECT ' . ng_post_columns() . ', p.content FROM ' . ng_post_from() . " WHERE p.slug = ? AND $w LIMIT 1", array_merge([$slug], $a)), null);
}

/**
 * Editor preview: any post by slug (drafts / future posts included) for signed-in staff who can view the blog module.
 * Visitors never reach this (null).
 */
function ng_post_preview(string $slug): ?array
{
    if (!preg_match('/^[a-z0-9-]{1,190}$/', $slug) || !is_logged_in() || !can('blog', 'view')) {
        return null;
    }
    return ng_try(fn () => db_row('SELECT ' . ng_post_columns() . ', p.content, p.status FROM ' . ng_post_from() . ' WHERE p.slug = ? LIMIT 1', [$slug]), null);
}

/** Alt text stored in the media library for an image path (thumbnail), or null. */
function ng_media_alt(?string $path): ?string
{
    if (!$path) {
        return null;
    }
    $alt = ng_try(fn () => db_value('SELECT alt_text FROM media WHERE path = ? AND alt_text IS NOT NULL AND alt_text <> \'\' LIMIT 1', [$path]), null);
    return $alt ? (string) $alt : null;
}

/** Older / newer neighbour of a post by publish date. $dir: older | newer. */
function ng_adjacent(array $post, string $dir): ?array
{
    [$w, $a] = ng_visible_where();
    $older = $dir === 'older';
    $date = (string) $post['pub_date'];
    $sql = 'SELECT p.id, p.title, p.slug, p.thumbnail, COALESCE(p.published_at, p.created_at) AS pub_date FROM blog_posts p WHERE ' . $w
        . ' AND p.id <> ? AND (COALESCE(p.published_at, p.created_at) ' . ($older ? '<' : '>') . ' ? OR (COALESCE(p.published_at, p.created_at) = ? AND p.id ' . ($older ? '<' : '>') . ' ?))'
        . ' ORDER BY pub_date ' . ($older ? 'DESC' : 'ASC') . ', p.id ' . ($older ? 'DESC' : 'ASC') . ' LIMIT 1';
    return ng_try(fn () => db_row($sql, array_merge($a, [(int) $post['id'], $date, $date, (int) $post['id']])), null);
}

/** Related posts: same category first, then shared tags, then most recent. */
function ng_related(array $post, int $limit = 3): array
{
    [$w, $a] = ng_visible_where();
    $rows = ng_try(fn () => db_all('SELECT ' . ng_post_columns() . ' FROM ' . ng_post_from() . " WHERE $w AND p.id <> ? ORDER BY pub_date DESC LIMIT 60", array_merge($a, [(int) $post['id']])), []);
    $mine = array_map('mb_strtolower', ng_tags($post['tags'] ?? ''));
    foreach ($rows as $i => &$r) {
        $shared = count(array_intersect($mine, array_map('mb_strtolower', ng_tags($r['tags'] ?? ''))));
        $r['_score'] = ((int) $r['category_id'] === (int) $post['category_id'] && $post['category_id'] ? 3 : 0) + $shared * 2 + (60 - $i) / 1000;
    }
    unset($r);
    usort($rows, fn ($x, $y) => $y['_score'] <=> $x['_score']);
    return array_slice($rows, 0, $limit);
}

/** Count a page view once per session (bots/headless browsers skipped; updated_at is kept unchanged). */
function ng_count_view(int $postId): void
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse|facebookexternalhit|curl|wget|python|java\//i', $ua)) {
        return;
    }
    $seen = (array) ($_SESSION['ng_viewed'] ?? []);
    if (in_array($postId, $seen, true)) {
        return;
    }
    ng_try(fn () => db_exec('UPDATE blog_posts SET views = views + 1, updated_at = updated_at WHERE id = ?', [$postId]));
    $seen[] = $postId;
    $_SESSION['ng_viewed'] = array_slice($seen, -200);
}

/* ============================================================================ gallery */

/** Category key → label. */
function ng_album_category_label(?string $key): string
{
    $key = (string) $key;
    return [
        'campus' => 'Campus', 'labs' => 'Labs & Facilities', 'events' => 'Events', 'sports' => 'Sports', 'cultural' => 'Cultural',
        'convocation' => 'Convocation', 'placements' => 'Placements', 'academics' => 'Academics', 'hostel' => 'Hostel',
    ][$key] ?? ucwords(str_replace(['_', '-'], ' ', $key));
}

function ng_gallery_url(?string $slug = null, array $params = []): string
{
    $q = [];
    foreach (['category', 'page'] as $k) {
        if (!empty($params[$k]) && !($k === 'page' && (int) $params[$k] <= 1)) {
            $q[$k] = (string) $params[$k];
        }
    }
    return site_url('gallery' . ($slug ? '/' . $slug : '')) . ($q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '');
}

function ng_gallery_abs(?string $slug = null, array $params = []): string
{
    return absolute_url(ltrim(substr(ng_gallery_url($slug, $params), strlen(base_path())), '/'));
}

/** SQL for counting an album's photos / videos (correlated, alias g). */
function ng_album_count_sql(): string
{
    return "(SELECT COUNT(*) FROM gallery_items gi LEFT JOIN media m ON m.id = gi.media_id WHERE gi.gallery_id = g.id AND (gi.video_url IS NULL OR gi.video_url = '') AND COALESCE(m.type, 'image') <> 'video' AND (NULLIF(gi.image, '') IS NOT NULL OR m.path IS NOT NULL)) AS photo_count,
        (SELECT COUNT(*) FROM gallery_items gi LEFT JOIN media m ON m.id = gi.media_id WHERE gi.gallery_id = g.id AND ((gi.video_url IS NOT NULL AND gi.video_url <> '') OR m.type = 'video')) AS video_count";
}

/** Published album categories with counts: [['key', 'label', 'count']]. */
function ng_album_categories(): array
{
    $rows = ng_try(fn () => db_all("SELECT g.category, COUNT(*) AS n, MIN(g.sort_order) AS so FROM galleries g WHERE g.status = 'published' AND g.category IS NOT NULL AND g.category <> '' GROUP BY g.category ORDER BY so, g.category"), []);
    return array_map(fn ($r) => ['key' => (string) $r['category'], 'label' => ng_album_category_label($r['category']), 'count' => (int) $r['n']], $rows);
}

function ng_album_total(?string $category = null): int
{
    return (int) ng_try(fn () => db_value("SELECT COUNT(*) FROM galleries g WHERE g.status = 'published'" . ($category ? ' AND g.category = ?' : ''), $category ? [$category] : []), 0);
}

/** Published albums (+ photo/video counts + up to 3 preview photos as `previews`). */
function ng_albums(?string $category, int $limit, int $offset = 0, array $exclude = []): array
{
    $w = ["g.status = 'published'"];
    $args = [];
    if ($category) {
        $w[] = 'g.category = ?';
        $args[] = $category;
    }
    if ($exclude) {
        $w[] = 'g.id NOT IN (' . implode(',', array_fill(0, count($exclude), '?')) . ')';
        array_push($args, ...array_map('intval', $exclude));
    }
    $rows = ng_try(fn () => db_all('SELECT g.id, g.title, g.slug, g.category, g.description, g.cover_image, g.event_date, g.sort_order, g.created_at, ' . ng_album_count_sql()
        . ' FROM galleries g WHERE ' . implode(' AND ', $w) . ' ORDER BY g.sort_order, g.event_date DESC, g.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset), $args), []);
    return ng_attach_previews($rows);
}

/** Adds `previews` (first 3 photos: src, width, height) and `cover` (cover_image or first photo) to album rows. */
function ng_attach_previews(array $albums): array
{
    if (!$albums) {
        return [];
    }
    $ids = array_map(fn ($a) => (int) $a['id'], $albums);
    $rows = ng_try(fn () => db_all("SELECT gi.gallery_id, COALESCE(NULLIF(gi.image, ''), m.path) AS path, m.width, m.height FROM gallery_items gi LEFT JOIN media m ON m.id = gi.media_id
        WHERE gi.gallery_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND (gi.video_url IS NULL OR gi.video_url = '') AND COALESCE(m.type, 'image') <> 'video'
          AND (NULLIF(gi.image, '') IS NOT NULL OR m.path IS NOT NULL) ORDER BY gi.gallery_id, gi.sort_order, gi.id LIMIT 800", $ids), []);
    $by = [];
    $paths = [];
    foreach ($rows as $r) {
        $paths[(int) $r['gallery_id']][(string) $r['path']] = true;
        if (count($by[(int) $r['gallery_id']] ?? []) < 4) {
            $by[(int) $r['gallery_id']][] = $r;
        }
    }
    foreach ($albums as &$a) {
        $prev = $by[(int) $a['id']] ?? [];
        $cover = trim((string) ($a['cover_image'] ?? '')) ?: ($prev[0]['path'] ?? '');
        $a['cover'] = $cover;
        $a['previews'] = array_values(array_filter($prev, fn ($p) => $p['path'] !== $cover));
        // an admin-set cover that is not one of the album photos does not count towards the "+N more" badge
        $a['cover_in_items'] = $cover !== '' && isset($paths[(int) $a['id']][$cover]);
    }
    unset($a);
    return $albums;
}

/** Published album by slug. */
function ng_album_by_slug(string $slug): ?array
{
    if (!preg_match('/^[a-z0-9-]{1,190}$/', $slug)) {
        return null;
    }
    $rows = ng_try(fn () => db_all("SELECT g.*, " . ng_album_count_sql() . " FROM galleries g WHERE g.slug = ? AND g.status = 'published' LIMIT 1", [$slug]), []);
    return $rows ? ng_attach_previews($rows)[0] : null;
}

/**
 * Items of an album split into photos [src, thumb, alt, caption, width, height, path] and videos [url, poster, caption,
 * external]. Video = gallery_items.video_url or a media row of type video; its image (if any) is the poster.
 */
function ng_album_items(array $album): array
{
    $rows = ng_try(fn () => db_all("SELECT gi.id, gi.image, gi.video_url, gi.caption, m.path AS media_path, m.type AS media_type, m.width, m.height, m.alt_text, m.title AS media_title, m.caption AS media_caption
        FROM gallery_items gi LEFT JOIN media m ON m.id = gi.media_id WHERE gi.gallery_id = ? ORDER BY gi.sort_order, gi.id LIMIT 500", [(int) $album['id']]), []);
    $photos = [];
    $videos = [];
    $n = 0;
    foreach ($rows as $r) {
        $caption = trim((string) ($r['caption'] ?: ($r['media_caption'] ?: $r['media_title'])));
        $video = trim((string) $r['video_url']) ?: ($r['media_type'] === 'video' ? upload_url((string) $r['media_path']) : '');
        if ($video !== '') {
            $poster = trim((string) $r['image']) ?: ($r['media_type'] !== 'video' && $r['media_path'] ? (string) $r['media_path'] : '');
            $videos[] = ['url' => $video, 'poster' => $poster !== '' ? site_image($poster) : null, 'caption' => $caption !== '' ? $caption : $album['title'] . ' — video ' . (count($videos) + 1),
                'external' => (bool) preg_match('#^https?://#i', $video) && !str_starts_with($video, absolute_url(''))];
            continue;
        }
        $path = trim((string) $r['image']) ?: (string) $r['media_path'];
        if ($path === '') {
            continue;
        }
        $n++;
        $alt = trim((string) ($r['alt_text'] ?? '')) ?: ($caption !== '' ? $caption : $album['title'] . ' — photo ' . $n);
        $w = (int) ($r['width'] ?? 0);
        $h = (int) ($r['height'] ?? 0);
        if ((!$w || !$h || $r['media_path'] !== $path) && $n <= 80) { // gi.image without a media row: read the file header
            [$w, $h] = ng_image_size($path) ?? [0, 0];
        }
        $photos[] = ['src' => site_image($path), 'alt' => $alt, 'caption' => $caption, 'width' => $w ?: null, 'height' => $h ?: null, 'path' => $path];
    }
    return [$photos, $videos];
}

/** Album card with a 3-photo mosaic, count badge, category, date, description. */
function ng_album_card(array $a, string $heading = 'h3'): string
{
    $url = ng_gallery_url((string) $a['slug']);
    $photos = (int) $a['photo_count'];
    $videos = (int) $a['video_count'];
    $thumbs = array_slice($a['previews'] ?? [], 0, 2);
    $more = max(0, $photos - (!empty($a['cover_in_items']) ? 1 : 0) - count($thumbs));
    $cover = $a['cover'] !== '' ? site_image($a['cover']) : asset('assets/images/site/campus-building.jpg');
    $html = '<article class="group relative flex h-full flex-col rounded-[22px] border border-warm-2 bg-white p-3 transition duration-300 hover:-translate-y-1 hover:shadow-[0_24px_48px_-28px_rgba(7,26,59,.45)]">';
    $html .= '<div class="relative grid aspect-[4/3] grid-cols-3 grid-rows-2 gap-1.5 overflow-hidden rounded-[16px] bg-warm" aria-hidden="true">';
    $html .= '<span class="img-zoom ' . ($thumbs ? 'col-span-2' : 'col-span-3') . ' row-span-2 block overflow-hidden"><img src="' . e($cover) . '" alt="" loading="lazy" decoding="async" width="640" height="480" class="h-full w-full object-cover"></span>';
    foreach ($thumbs as $i => $t) {
        $html .= '<span class="img-zoom relative block overflow-hidden' . (count($thumbs) === 1 ? ' row-span-2' : '') . '"><img src="' . e(site_image($t['path'])) . '" alt="" loading="lazy" decoding="async" width="320" height="240" class="h-full w-full object-cover">'
            . ($i === count($thumbs) - 1 && $more > 0 ? '<span class="absolute inset-0 flex items-center justify-center bg-navy-deep/60 font-display text-lg font-extrabold text-white">+' . $more . '</span>' : '') . '</span>';
    }
    $html .= '<span class="absolute left-3 top-3 inline-flex items-center gap-1.5 rounded-full bg-white/95 px-3 py-1 text-xs font-bold text-brand-900 shadow-sm">' . icon('images', 'h-3.5 w-3.5 text-accent-700') . $photos . ' photo' . ($photos === 1 ? '' : 's') . ($videos ? ' · ' . $videos . ' video' . ($videos === 1 ? '' : 's') : '') . '</span>';
    $html .= '</div><div class="flex flex-1 flex-col px-2 pb-2 pt-4">';
    $html .= '<p class="flex flex-wrap items-center gap-x-2.5 gap-y-1 text-xs font-semibold text-slate-500">'
        . (!empty($a['category']) ? '<span class="rounded-full bg-accent-50 px-2.5 py-0.5 font-bold uppercase tracking-[0.08em] text-accent-800 ring-1 ring-inset ring-accent-100">' . e(ng_album_category_label($a['category'])) . '</span>' : '')
        . (!empty($a['event_date']) ? '<span class="inline-flex items-center gap-1">' . icon('calendar-days', 'h-3.5 w-3.5') . ng_time($a['event_date']) . '</span>' : '') . '</p>';
    $html .= '<' . $heading . ' class="mt-2.5 font-display text-lg font-bold leading-snug text-brand-900"><a href="' . e($url) . '" class="stretched-link transition-colors group-hover:text-accent-800">' . e($a['title']) . '</a></' . $heading . '>';
    if (trim((string) $a['description']) !== '') {
        $html .= '<p class="mt-1.5 line-clamp-2 text-sm leading-relaxed text-slate-600">' . e($a['description']) . '</p>';
    }
    $html .= '<span class="link-arrow mt-auto pt-4 text-sm" aria-hidden="true">View album' . icon('arrow-right', 'h-4 w-4') . '</span>';
    return $html . '</div></article>';
}

/**
 * Photo grid → GalleryLightbox island (arrows, ←/→ keys, swipe, zoom, thumbnails). The fallback (no JS / before the
 * island mounts) has the same footprint: tiles with real dimensions + always-visible captions, opened by the vanilla
 * lightbox (same group = keyboard + swipe gallery).
 */
function ng_photo_grid(array $photos, array $props = []): string
{
    static $n = 0;
    $group = 'ng-album-' . (++$n);
    $props['items'] = array_map(fn ($p) => array_filter(['src' => $p['src'], 'alt' => $p['alt'], 'caption' => $p['caption'] !== '' ? $p['caption'] : null], fn ($v) => $v !== null), array_values($photos));
    $cols = (int) ($props['columns'] ?? 3);
    $colCls = [2 => 'sm:grid-cols-2', 3 => 'sm:grid-cols-2 lg:grid-cols-3', 4 => 'sm:grid-cols-3 lg:grid-cols-4'][$cols] ?? 'sm:grid-cols-2 lg:grid-cols-3';
    $bento = ($props['layout'] ?? 'grid') === 'bento';
    $html = '<div class="gallery"><ul class="grid grid-cols-2 gap-3 sm:gap-4 ' . $colCls . ($bento ? ' gallery-bento' : '') . '">';
    foreach ($photos as $p) {
        $dims = $p['width'] && $p['height'] ? ' width="' . (int) $p['width'] . '" height="' . (int) $p['height'] . '"' : ' width="640" height="480"';
        $html .= '<li><a href="' . e($p['src']) . '" data-lightbox="' . $group . '" data-caption="' . e($p['caption'] !== '' ? $p['caption'] : $p['alt']) . '" class="gallery-tile img-zoom group aspect-[4/3]">'
            . '<img src="' . e($p['src']) . '" alt="' . e($p['alt']) . '" loading="lazy" decoding="async"' . $dims . ' class="h-full w-full object-cover">'
            . '<span class="gallery-tile-overlay" aria-hidden="true">' . icon('search', 'h-5 w-5') . ($p['caption'] !== '' ? '<span class="gallery-tile-caption">' . e($p['caption']) . '</span>' : '') . '</span></a></li>';
    }
    $html .= '</ul></div>';
    // captions always visible (as on the campus pages): overlay shown, zoom icon only on hover/focus
    $wrap = 'block [&_.gallery-tile-overlay]:justify-end [&_.gallery-tile-overlay]:opacity-100 [&_.gallery-tile-overlay]:[background:linear-gradient(180deg,rgba(7,26,59,0)_50%,rgba(7,26,59,.82))] [&_.gallery-tile-overlay>svg]:hidden [&_.gallery-tile-caption]:text-left [&_.gallery-tile-caption]:text-[13px] [&_.gallery-tile-caption]:font-semibold [&_.gallery-tile-caption]:text-white [&_.gallery-tile-caption]:self-start';
    $wrap .= !empty($props['class']) ? ' ' . $props['class'] : ''; // layout classes from ng_photo_layout()
    unset($props['class']);
    return island('GalleryLightbox', $props, $html, ['class' => $wrap]);
}

/**
 * Grid layout for an album's photos with every row filled: [layout, columns, wrapper classes].
 *   - bento (first photo spans 2×2, 4 columns) only for 5, 9, 13 … photos whose first photo is large enough to be shown
 *     that big without blurring (≥ 1000 × 700 px);
 *   - otherwise three photos per row on large screens (6-column track): when the count is not a multiple of three, the
 *     last two (or four) photos become wider tiles, two per row; below lg two per row and an odd last photo spans the row;
 *   - a single photo is shown once, centred.
 * The classes target the markup of both the server fallback and the GalleryLightbox island (.gallery > ul > li).
 */
function ng_photo_layout(array $photos): array
{
    $n = count($photos);
    if ($n <= 1) {
        return ['grid', 2, '[&_.gallery>ul]:mx-auto [&_.gallery>ul]:max-w-3xl [&_.gallery>ul]:grid-cols-1'];
    }
    $first = $photos[0];
    if ($n >= 5 && ($n + 3) % 4 === 0 && (int) ($first['width'] ?? 0) >= 1000 && (int) ($first['height'] ?? 0) >= 700) {
        return ['bento', 4, ''];
    }
    $cls = ['lg:[&_.gallery>ul]:grid-cols-6 lg:[&_.gallery>ul>li]:col-span-2'];
    if ($n % 2 === 1) {
        $cls[] = 'max-lg:[&_.gallery>ul>li:last-child]:col-span-2 max-lg:[&_.gallery>ul>li:last-child_.gallery-tile]:aspect-[2/1]';
    }
    if ($n % 3 === 2) {
        $cls[] = 'lg:[&_.gallery>ul>li:nth-last-child(-n+2)]:col-span-3 lg:[&_.gallery>ul>li:nth-last-child(-n+2)_.gallery-tile]:aspect-[2/1]';
    } elseif ($n % 3 === 1) {
        $cls[] = 'lg:[&_.gallery>ul>li:nth-last-child(-n+4)]:col-span-3 lg:[&_.gallery>ul>li:nth-last-child(-n+4)_.gallery-tile]:aspect-[2/1]';
    }
    return ['grid', 3, implode(' ', $cls)];
}

/** Video tiles (vanilla lightbox: YouTube/Vimeo/MP4 in a modal; other hosts open in a new tab). */
function ng_video_grid(array $videos, string $fallbackPoster): string
{
    static $n = 0;
    $group = 'ng-videos-' . (++$n);
    $html = '<ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">';
    foreach ($videos as $v) {
        $playable = (bool) preg_match('#(youtube\.com|youtu\.be|vimeo\.com|\.(mp4|webm|ogg)(\?|$))#i', $v['url']);
        $html .= '<li><a href="' . e($v['url']) . '"' . ($playable ? ' data-lightbox="' . $group . '"' : '') . ($v['external'] ? ' target="_blank" rel="noopener"' : '') . ' data-caption="' . e($v['caption']) . '" class="group relative block aspect-video overflow-hidden rounded-[18px] bg-brand-900">'
            . '<img src="' . e($v['poster'] ?? $fallbackPoster) . '" alt="" loading="lazy" decoding="async" width="640" height="360" class="h-full w-full object-cover opacity-70 transition duration-700 group-hover:scale-105' . ($v['poster'] ? '' : ' blur-[2px]') . '">'
            . '<span class="absolute inset-0 bg-gradient-to-t from-navy-deep/90 via-navy-deep/30 to-transparent" aria-hidden="true"></span>'
            . '<span class="absolute inset-0 flex items-center justify-center" aria-hidden="true"><span class="play-pulse">' . icon('play', 'h-5 w-5 translate-x-px') . '</span></span>'
            . '<span class="absolute inset-x-4 bottom-3 text-left text-sm font-semibold text-white">' . e($v['caption']) . '<span class="sr-only"> — play video' . ($v['external'] && !$playable ? ' (opens in a new tab)' : '') . '</span></span></a></li>';
    }
    return $html . '</ul>';
}

/** Gallery-wide totals for hero chips. */
function ng_gallery_totals(): array
{
    $row = ng_try(fn () => db_row("SELECT COUNT(*) AS albums,
            COALESCE(SUM((SELECT COUNT(*) FROM gallery_items gi LEFT JOIN media m ON m.id = gi.media_id WHERE gi.gallery_id = g.id AND (gi.video_url IS NULL OR gi.video_url = '') AND COALESCE(m.type, 'image') <> 'video' AND (NULLIF(gi.image, '') IS NOT NULL OR m.path IS NOT NULL))), 0) AS photos,
            COALESCE(SUM((SELECT COUNT(*) FROM gallery_items gi LEFT JOIN media m ON m.id = gi.media_id WHERE gi.gallery_id = g.id AND ((gi.video_url IS NOT NULL AND gi.video_url <> '') OR m.type = 'video'))), 0) AS videos
        FROM galleries g WHERE g.status = 'published'"), null);
    return ['albums' => (int) ($row['albums'] ?? 0), 'photos' => (int) ($row['photos'] ?? 0), 'videos' => (int) ($row['videos'] ?? 0)];
}

/* ============================================================================ not found */

/**
 * 404 for unknown / unpublished posts, categories, pages and albums. Delegates to the site-wide 404.php when it exists
 * and can be included from a page that already loaded includes/config.php (require_once); otherwise renders the same
 * site chrome with helpful next steps (search, latest articles / albums). Always exits.
 * $kind: post | category | page | album
 */
function ng_not_found(string $kind = 'post'): void
{
    http_response_code(404);
    $file = APP_ROOT . '/404.php';
    if (is_file($file) && preg_match('/(require|include)_once[^;]*includes\/config\.php/', (string) @file_get_contents($file))) {
        (static function (string $__f) {
            require $__f;
        })($file);
        exit;
    }
    $album = $kind === 'album';
    $titles = ['post' => 'Article Not Found', 'category' => 'Category Not Found', 'page' => 'Page Not Found', 'album' => 'Album Not Found'];
    $title = $titles[$kind] ?? 'Page Not Found';
    site_header(['title' => $title, 'description' => $album ? 'The photo album you are looking for could not be found. Browse all GIMT photo albums.' : 'The article you are looking for could not be found. Browse the latest news and articles from GIMT.',
        'active' => $album ? 'gallery' : 'blog', 'robots' => 'noindex,follow', 'canonical' => absolute_url($album ? 'gallery' : 'blog'), 'loader' => false]);
    page_hero($title, $album ? 'This album may have been renamed or unpublished' : 'This page may have moved, been renamed or is not published yet', $album ? ['Gallery' => '/gallery', 'Not Found'] : ['News & Blog' => '/blog', 'Not Found'], '',
        $album ? 'Explore our other photo albums below, or head back to the gallery.' : 'Search our news and articles, or pick one of the latest stories below.', [],
        ['highlight' => false, 'eyebrow' => 'Error 404', 'eyebrow_icon' => 'search',
         'actions' => $album ? button_link('Browse All Albums', '/gallery', 'explore') . button_link('Go to Homepage', '/', 'brochure', ['icon' => ''])
                             : ng_search_form('', null, 'nf-search') . '<div class="flex flex-wrap gap-3">' . button_link('All News & Articles', '/blog', 'explore') . button_link('Go to Homepage', '/', 'brochure', ['icon' => '']) . '</div>']);
    echo section_open(['bg' => 'light']);
    if ($album) {
        $list = ng_albums(null, 6);
        echo section_heading('Photo Albums', 'Moments from campus life, events and celebrations at GIMT.', 'Gallery', 'left', $list ? button_link('View All Albums', '/gallery', 'brochure') : '');
        echo $list ? '<div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">' . implode('', array_map(fn ($a) => ng_album_card($a), $list)) . '</div>'
            : '<div class="empty-state rounded-[22px] border border-dashed border-warm-line bg-white"><span class="empty-state-icon">' . icon('images', 'h-6 w-6') . '</span><p class="mt-3 font-semibold text-brand-900">Photo albums are being prepared.</p><a href="' . e(site_url('campus')) . '" class="btn btn-secondary btn-sm mt-4">Explore the campus</a></div>';
    } else {
        $list = ng_posts([], 3);
        echo section_heading('Latest News & Articles', 'Recent stories from the GIMT campus.', 'Keep reading', 'left', $list ? button_link('All Articles', '/blog', 'brochure') : '');
        echo $list ? '<div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">' . implode('', array_map(fn ($p) => ng_post_card($p, ['show_extra' => false]), $list)) . '</div>'
            : '<div class="empty-state rounded-[22px] border border-dashed border-warm-line bg-white"><span class="empty-state-icon">' . icon('newspaper', 'h-6 w-6') . '</span><p class="mt-3 font-semibold text-brand-900">News will be published here soon.</p><a href="' . e(site_url('programs')) . '" class="btn btn-secondary btn-sm mt-4">Explore Courses</a></div>';
    }
    echo section_close();
    site_footer(['cta_title' => 'Looking for Admissions Information?', 'cta_text' => 'Explore our programs or talk to an admission counsellor.',
        'cta_primary' => ['label' => 'Apply for Admission', 'url' => '/apply'], 'cta_secondary' => ['label' => 'Explore Courses', 'url' => '/programs', 'icon' => 'graduation-cap']]);
    exit;
}
