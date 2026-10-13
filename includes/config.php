<?php
/**
 * Public website configuration/bootstrap + helpers. Every public page starts with:
 *
 *   require __DIR__ . '/includes/config.php';
 *   site_header(['title' => 'About GIMT', 'description' => '...', 'active' => 'about', 'seo' => ['route', '/about']]);
 *   ... page content ...
 *   site_footer();
 *
 * Data comes from the CMS tables (menus, homepage_sections, banners, pages, seo_settings ...) and settings.
 * Interactivity: React + TypeScript islands and vanilla TS enhancements built from frontend/site (see island(),
 * site_assets_head()/site_assets_footer() below, includes/components.php and docs/WEBSITE.md).
 */
require_once __DIR__ . '/../app/init.php';
require_once __DIR__ . '/errors.php';   // branded 500 page + logging for public pages (production)
require_once __DIR__ . '/../app/auth.php';     // starts the session (CSRF for public forms); no login required
require_once __DIR__ . '/components.php';      // reusable website render helpers (program_card, feature_tile, cta_band …)

/* ------------------------------------------------------------------ Maintenance mode */
if (setting('maintenance_mode', '0') === '1' && !is_logged_in() && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'verify-certificate.php') {
    http_response_code(503);
    header('Retry-After: 3600');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Maintenance</title>'
        . '<div style="font-family:system-ui;max-width:560px;margin:15vh auto;padding:24px;text-align:center;color:#0B2A5B">'
        . '<img src="' . e(logo_url()) . '" alt="GIMT" style="height:64px"><h1>We will be back shortly</h1><p style="color:#475569">Our website is undergoing scheduled maintenance. Please check back soon.</p></div>';
    exit;
}

/** URL for a public route: site_url('programs/mba') -> /programs/mba */
function site_url(string $path = ''): string
{
    return base_url(ltrim($path, '/'));
}

/** Resolve a CMS link (relative "/about", "about", absolute URL or "#anchor"). */
function cms_link(?string $url): string
{
    $url = trim((string) $url);
    if ($url === '') {
        return '#';
    }
    if (preg_match('#^(https?:|mailto:|tel:|\#)#i', $url)) {
        return $url;
    }
    return site_url($url);
}

/** Image URL with fallback to bundled campus imagery. */
function site_image(?string $path, string $fallback = 'assets/images/site/campus-building.jpg'): string
{
    if ($path) {
        return str_starts_with($path, 'assets/') ? asset($path) : upload_url($path);
    }
    return asset($fallback);
}

/* ------------------------------------------------------------------ CMS data */

/** Menu tree for a location (header, footer_quick, footer_academics, footer_support, legal, topbar). */
function site_menu(string $location): array
{
    static $cache = [];
    if (isset($cache[$location])) {
        return $cache[$location];
    }
    $items = [];
    try {
        $rows = db_all("SELECT mi.* FROM menu_items mi JOIN menus m ON m.id = mi.menu_id
                        WHERE m.location = ? AND m.status = 'active' AND mi.status = 'active' ORDER BY mi.sort_order, mi.id", [$location]);
        $byParent = [];
        foreach ($rows as $r) {
            $byParent[(int) $r['parent_id']][] = $r;
        }
        $build = function ($parentId) use (&$build, $byParent) {
            $out = [];
            foreach ($byParent[$parentId] ?? [] as $r) {
                $r['children'] = $build((int) $r['id']);
                $out[] = $r;
            }
            return $out;
        };
        $items = $build(0);
    } catch (Throwable $e) {
        $items = [];
    }
    return $cache[$location] = $items;
}

/** Enabled home page section by key (content JSON decoded) or null. */
function site_section(string $key): ?array
{
    static $all = null;
    if ($all === null) {
        $all = [];
        try {
            foreach (db_all('SELECT * FROM homepage_sections ORDER BY sort_order') as $r) {
                $r['content'] = $r['content'] ? (json_decode($r['content'], true) ?: []) : [];
                $all[$r['section_key']] = $r;
            }
        } catch (Throwable $e) {
        }
    }
    $s = $all[$key] ?? null;
    return $s && (int) $s['is_enabled'] === 1 ? $s : null;
}

function site_banners(string $placement = 'home_hero'): array
{
    try {
        return db_all("SELECT * FROM banners WHERE placement = ? AND status = 'active' AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW()) ORDER BY sort_order, id", [$placement]);
    } catch (Throwable $e) {
        return [];
    }
}

function site_announcements(string $type = 'ticker'): array
{
    try {
        return db_all("SELECT * FROM announcements WHERE type = ? AND status = 'active' AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW()) ORDER BY sort_order, id DESC", [$type]);
    } catch (Throwable $e) {
        return [];
    }
}

/** SEO row for an entity (page/program/post/event/notice) or a route ("/contact"). */
function seo_lookup(?string $type, ?int $id, ?string $route): ?array
{
    try {
        if ($type && $type !== 'route' && $id) {
            return db_row('SELECT * FROM seo_settings WHERE entity_type = ? AND entity_id = ?', [$type, $id]);
        }
        if ($route) {
            return db_row("SELECT * FROM seo_settings WHERE entity_type = 'route' AND route = ?", [$route]);
        }
    } catch (Throwable $e) {
    }
    return null;
}

/** Social profile links from settings. */
function social_links(): array
{
    $map = ['facebook' => 'facebook_url', 'instagram' => 'instagram_url', 'linkedin' => 'linkedin_url', 'youtube' => 'youtube_url', 'x-twitter' => 'x_url'];
    $out = [];
    foreach ($map as $icon => $key) {
        if ($url = setting($key)) {
            $out[$icon] = $url;
        }
    }
    return $out;
}

/* ------------------------------------------------------------------ Design v2 data helpers (DB first, graceful fallbacks) */

/**
 * Active website programs with their department (cached per request), ordered like the admin sort order.
 * Each row = programs.* + department_name, department_code, department_label (name without "Department of"),
 * dept_index (0-5, stable per department → pastel colour .dept-N).
 */
function site_programs(): array
{
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }
    $rows = [];
    try {
        $rows = db_all("SELECT p.*, d.name AS department_name, d.code AS department_code FROM programs p LEFT JOIN departments d ON d.id = p.department_id
                        WHERE p.status = 'active' AND p.show_on_website = 1 ORDER BY p.sort_order, p.name");
    } catch (Throwable $e) {
        $rows = [];
    }
    $deptIds = array_values(array_unique(array_map(fn ($p) => (int) ($p['department_id'] ?? 0), $rows)));
    foreach ($rows as &$p) {
        $p['department_label'] = site_department_label((string) ($p['department_name'] ?? ''));
        $p['dept_index'] = max(0, (int) array_search((int) ($p['department_id'] ?? 0), $deptIds, true)) % 6;
    }
    unset($p);
    return $rows;
}

/** "Department of Management Studies" → "Management Studies"; "School of X" stays. */
function site_department_label(string $name): string
{
    return trim((string) preg_replace('/^Department of\s+/i', '', $name));
}

/** Departments that have website programs: [['id', 'name', 'label', 'index', 'count'], …] in program order. */
function site_program_departments(): array
{
    $out = [];
    foreach (site_programs() as $p) {
        $id = (int) ($p['department_id'] ?? 0);
        if (!$id) {
            continue;
        }
        $out[$id] ??= ['id' => $id, 'name' => (string) $p['department_name'], 'label' => $p['department_label'], 'index' => $p['dept_index'], 'count' => 0];
        $out[$id]['count']++;
    }
    return array_values($out);
}

/** Program levels present on the website (key => label) in a fixed order. */
function site_program_levels(): array
{
    $present = array_unique(array_map(fn ($p) => (string) ($p['level'] ?? ''), site_programs()));
    $map = ['UG' => 'Undergraduate', 'PG' => 'Postgraduate', 'PhD' => 'Doctoral', 'Diploma' => 'Diploma', 'Certificate' => 'Certificate'];
    return array_filter($map, fn ($k) => in_array($k, $present, true), ARRAY_FILTER_USE_KEY);
}

/** Specialisations (courses) per program id: [program_id => [course rows]] — empty when the table is unavailable. */
function site_program_courses(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    try {
        foreach (db_all("SELECT id, program_id, name FROM courses WHERE status = 'active' ORDER BY program_id, name") as $c) {
            $map[(int) $c['program_id']][] = $c;
        }
    } catch (Throwable $e) {
    }
    return $map;
}

/** tel: href for a display phone number. */
function site_tel(string $phone): string
{
    return 'tel:' . preg_replace('/[^0-9+]/', '', $phone);
}

/** wa.me link for the configured WhatsApp number (setting whatsapp_number) or null. */
function site_whatsapp_url(string $text = 'Hello GIMT, I would like to know more about admissions.'): ?string
{
    $n = preg_replace('/\D/', '', (string) setting('whatsapp_number', ''));
    return $n !== '' ? 'https://wa.me/' . $n . '?text=' . rawurlencode($text) : null;
}

/**
 * Approvals / affiliations shown as generic emblems (no real logos). Source: setting `approvals_list` (JSON array of
 * {label, title, icon}) → else the `affiliation` setting split on "•", "|" or "," → else empty.
 * icon = emblem name (seal|laurel|shield|pillars|globe|book|medal) rendered by emblem().
 */
function site_approvals(): array
{
    static $list = null;
    if ($list !== null) {
        return $list;
    }
    $list = [];
    $json = json_decode((string) setting('approvals_list', ''), true);
    if (is_array($json)) {
        foreach ($json as $i => $a) {
            $title = trim((string) ($a['title'] ?? $a['label'] ?? ''));
            if ($title !== '') {
                $list[] = ['label' => trim((string) ($a['label'] ?? $title)), 'title' => $title, 'text' => trim((string) ($a['text'] ?? '')), 'icon' => (string) ($a['icon'] ?? ['seal', 'pillars', 'shield', 'laurel', 'globe', 'book'][$i % 6])];
            }
        }
    }
    if (!$list) {
        $parts = preg_split('/\s*[•|,·]\s*|\s+\?\s+/u', (string) setting('affiliation', '')) ?: [];
        foreach (array_values(array_filter(array_map('trim', $parts))) as $i => $title) {
            $list[] = ['label' => $title, 'title' => $title, 'text' => '', 'icon' => ['pillars', 'seal', 'shield', 'laurel', 'globe', 'book'][$i % 6]];
        }
    }
    return $list;
}

/**
 * Real application deadline: setting `admissions_deadline` (Y-m-d or Y-m-d H:i), else the admissions page's
 * `admission_last_date` (end of that day). Returns a timestamp or null when unset/invalid/past.
 */
function site_admissions_deadline(): ?int
{
    foreach (['admissions_deadline', 'admission_last_date'] as $key) {
        $v = trim((string) setting($key, ''));
        if ($v === '' || !($ts = strtotime($v))) {
            continue;
        }
        if (!preg_match('/\d{1,2}:\d{2}/', $v)) {
            $ts = strtotime(date('Y-m-d 23:59:59', $ts));
        }
        return $ts > time() ? $ts : null;
    }
    return null;
}

/**
 * Real seats-filled percentage for the current admission session: confirmed admissions (or converted to students) vs
 * the summed intake capacity of active website programs. null when it cannot be computed (no session/intake data),
 * or when setting landing_show_seats = 0.
 */
function site_seats_filled(): ?int
{
    static $pct = false;
    if ($pct !== false) {
        return $pct;
    }
    $pct = null;
    if (setting('landing_show_seats', '1') !== '1') {
        return $pct;
    }
    try {
        $session = db_row("SELECT id FROM academic_sessions WHERE admissions_open = 1 ORDER BY is_current DESC, start_date DESC LIMIT 1")
            ?: db_row('SELECT id FROM academic_sessions WHERE is_current = 1 LIMIT 1');
        $intake = (int) db_value("SELECT COALESCE(SUM(intake_capacity), 0) FROM programs WHERE status = 'active' AND show_on_website = 1");
        if ($session && $intake > 0) {
            $filled = (int) db_value("SELECT COUNT(*) FROM admissions WHERE academic_session_id = ? AND (stage = 'confirmed' OR student_id IS NOT NULL)", [(int) $session['id']]);
            $pct = (int) max(0, min(100, round($filled * 100 / $intake)));
        }
    } catch (Throwable $e) {
        $pct = null;
    }
    return $pct;
}

/** Highest active merit/sports/general scholarship percentage (for "Scholarships up to X%") or null. */
function site_max_scholarship(): ?int
{
    try {
        $v = db_value("SELECT MAX(value) FROM scholarships WHERE status = 'active' AND type = 'percentage' AND category IN ('merit','sports','scholarship')");
        return $v !== null && (float) $v > 0 ? (int) round((float) $v) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Announcement strip items (plain text + optional link): announcements of type "topbar" (falls back to "ticker"),
 * else setting announcement_strip_text ("A | B | C"), else the admissions-open line.
 * @return array<int, array{text: string, url: ?string}>
 */
function site_announcement_items(?string $override = null): array
{
    $items = [];
    if ($override !== null && trim($override) !== '') {
        foreach (preg_split('/\s*\|\s*/', trim($override)) as $t) {
            if ($t !== '') {
                $items[] = ['text' => $t, 'url' => null];
            }
        }
        return $items;
    }
    foreach (site_announcements('topbar') ?: site_announcements('ticker') as $a) {
        $items[] = ['text' => (string) $a['title'], 'url' => !empty($a['link']) ? cms_link($a['link']) : null];
    }
    if (!$items) {
        foreach (preg_split('/\s*\|\s*/', (string) setting('announcement_strip_text', '')) as $t) {
            if (trim($t) !== '') {
                $items[] = ['text' => trim($t), 'url' => null];
            }
        }
    }
    if (!$items) {
        $items[] = ['text' => (string) setting('admissions_open_text', 'Admissions Open for 2026-27'), 'url' => site_url('admissions')];
    }
    return array_slice($items, 0, 8);
}

/* ------------------------------------------------------------------ Website bundle: React islands + TS enhancements */

/**
 * Parsed Vite manifest of the website bundle (assets/site/.vite/manifest.json, written by
 * `cd frontend && npm run build:site`), or null when it has not been built.
 */
function site_manifest(): ?array
{
    static $manifest = false;
    if ($manifest === false) {
        $file = APP_ROOT . '/assets/site/.vite/manifest.json';
        $manifest = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: null) : null;
    }
    return $manifest;
}

/**
 * Origin of the website Vite dev server (`npm run dev:site`) when config app.site_dev_server is set, the app runs
 * in dev mode and the server answers; otherwise null (pages then use the built manifest). Checked once per request.
 */
function site_dev_server(): ?string
{
    static $origin = false;
    if ($origin !== false) {
        return $origin;
    }
    $origin = null;
    $url = rtrim((string) (app_config('app')['site_dev_server'] ?? ''), '/');
    if ($url !== '' && is_dev()) {
        $p = parse_url($url);
        $fp = @fsockopen($p['host'] ?? '127.0.0.1', (int) ($p['port'] ?? 80), $errno, $errstr, 0.2);
        if ($fp) {
            fclose($fp);
            $origin = $url;
        }
    }
    return $origin;
}

/** Collect a manifest chunk's static imports (recursively) — used for modulepreload hints. */
function site_manifest_imports(array $manifest, string $key, array &$seen = []): array
{
    $files = [];
    foreach ($manifest[$key]['imports'] ?? [] as $imp) {
        if (isset($seen[$imp]) || !isset($manifest[$imp])) {
            continue;
        }
        $seen[$imp] = true;
        $files[] = $manifest[$imp]['file'];
        array_push($files, ...site_manifest_imports($manifest, $imp, $seen));
    }
    return $files;
}

/**
 * Tags for <head>: an inline bootstrap script (adds the `js` class so scroll-reveal states apply before first
 * paint, decides whether the first-visit loader shows, and un-hides content if the bundle never boots) plus either
 * the Vite dev client (with the React refresh preamble) or modulepreload/CSS links from the production manifest.
 */
function site_assets_head(): string
{
    $out = '<script>(function(d,w){var h=d.documentElement;h.classList.add("js");try{if(w.sessionStorage.getItem("gimt.visited")||w.matchMedia("(prefers-reduced-motion: reduce)").matches||navigator.webdriver){h.classList.add("loader-skip")}}catch(e){h.classList.add("loader-skip")}'
        . 'w.setTimeout(function(){if(!w.__gimtSite){h.classList.add("no-motion");h.classList.add("loader-skip")}},4000)})(document,window);</script>' . "\n";
    if ($dev = site_dev_server()) {
        return $out
            . '  <script type="module">import RefreshRuntime from "' . e($dev) . '/@react-refresh";RefreshRuntime.injectIntoGlobalHook(window);window.$RefreshReg$=()=>{};window.$RefreshSig$=()=>(t)=>t;window.__vite_plugin_react_preamble_installed__=true;</script>' . "\n"
            . '  <script type="module" src="' . e($dev) . '/@vite/client"></script>' . "\n";
    }
    $m = site_manifest();
    $entry = $m['site/main.tsx'] ?? null;
    if (!$entry) {
        return $out;
    }
    $base = base_url('assets/site/');
    $out .= '  <link rel="modulepreload" href="' . e($base . $entry['file']) . '">' . "\n";
    foreach (site_manifest_imports($m, 'site/main.tsx') as $file) {
        $out .= '  <link rel="modulepreload" href="' . e($base . $file) . '">' . "\n";
    }
    foreach ($entry['css'] ?? [] as $css) {
        $out .= '  <link rel="stylesheet" href="' . e($base . $css) . '">' . "\n";
    }
    return $out;
}

/**
 * Scripts before </body>: modulepreload hints for the chunks of eager (above-the-fold) islands rendered on this page,
 * then the entry module. Falls back to the dependency-free assets/js/main.js when the bundle is not built.
 */
function site_assets_footer(): string
{
    if ($dev = site_dev_server()) {
        return '<script type="module" src="' . e($dev) . '/site/main.tsx"></script>' . "\n";
    }
    $m = site_manifest();
    $entry = $m['site/main.tsx'] ?? null;
    if (!$entry) {
        return '<script src="' . e(asset('assets/js/main.js')) . '" defer></script>' . "\n";
    }
    $base = base_url('assets/site/');
    $out = '';
    $eager = array_keys(array_filter($GLOBALS['__site_islands'] ?? []));
    if ($eager) {
        $seen = [];
        $files = [];
        foreach (array_merge(['site/runtime/mount.tsx'], array_map(fn ($n) => 'site/islands/' . $n . '.tsx', $eager)) as $key) {
            if (isset($m[$key]) && !isset($seen[$key])) {
                $seen[$key] = true;
                $files[] = $m[$key]['file'];
                array_push($files, ...site_manifest_imports($m, $key, $seen));
            }
        }
        foreach (array_unique($files) as $file) {
            if ($file !== $entry['file']) {
                $out .= '<link rel="modulepreload" href="' . e($base . $file) . '">' . "\n";
            }
        }
    }
    return $out . '<script type="module" src="' . e($base . $entry['file']) . '"></script>' . "\n";
}

/**
 * Render a React island placeholder. The fallback HTML is what search engines, no-JS visitors and the first paint
 * see; the island component replaces it (same dimensions) once its chunk has loaded — immediately for eager islands,
 * otherwise when it comes within 200px of the viewport.
 *
 *   echo island('TestimonialSlider', ['items' => $items], $fallbackHtml);
 *   echo island('HeroSlider', $props, $fallback, ['eager' => true, 'class' => 'block']);
 *   echo island('TypingText', ['phrases' => [...]], 'Management', ['tag' => 'span']);
 *
 * @param string $name     Registered island name (frontend/site/islands/index.ts)
 * @param array  $props    JSON-serialisable props (strings are escaped by React on render)
 * @param string $fallback Server-rendered HTML (already escaped!). Children of `[data-slot="x"]` elements become
 *                         the island's `slots.x` (e.g. program cards for ProgramExplorer).
 * @param array  $attrs    tag (div|span|section…), eager (bool: mount immediately + preload chunks), class, id,
 *                         any other HTML attribute (true = boolean attribute, false/null = omitted)
 */
function island(string $name, array $props = [], string $fallback = '', array $attrs = []): string
{
    $tag = preg_match('/^[a-z][a-z0-9-]*$/', (string) ($attrs['tag'] ?? '')) ? $attrs['tag'] : 'div';
    $eager = !empty($attrs['eager']);
    unset($attrs['tag'], $attrs['eager']);
    $GLOBALS['__site_islands'][$name] = ($GLOBALS['__site_islands'][$name] ?? false) || $eager;
    $json = json_encode($props, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $html = '<' . $tag . ' data-island="' . e($name) . '"' . ($props ? ' data-props="' . e($json ?: '{}') . '"' : '') . ($eager ? ' data-eager' : '');
    foreach ($attrs as $k => $v) {
        if ($v === null || $v === false || !preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:.-]*$/', (string) $k)) {
            continue;
        }
        $html .= $v === true ? ' ' . $k : ' ' . $k . '="' . e($v) . '"';
    }
    return $html . '>' . $fallback . '</' . $tag . '>';
}


/* ------------------------------------------------------------------ Layout */

/**
 * Output <head>, announcement strip and the floating header (Design v2).
 * $page keys: title, description, active (nav key or URL path), image (OG), seo => [type, id] or ['route', '/path'],
 *             canonical, robots, schema (array JSON-LD), body_class, transparent_header (bool),
 *             cursor (bool, custom cursor ring — default setting site_cursor = 1), motion (false = no entrance
 *             animations on this page), loader (false = never show the first-visit loading screen), preload (array of
 *             image URLs — or ['href' => url, 'media' => '(min-width: 1280px)'] — to <link rel=preload as=image>,
 *             e.g. the LCP hero image),
 *             header ('full' default | 'minimal' = logo · phone · Apply Now, for campaign landing pages),
 *             header_cta (['label', 'url'] overrides the Apply Now button), announce (bool — thin announcement
 *             marquee above the header; default setting show_topbar = 1 — or a "A | B | C" string of items),
 *             chat (bool — floating WhatsApp "Chat" tab + round call button; default on when whatsapp_number is set)
 */
function site_header(array $page = []): void
{
    $GLOBALS['__site_page'] = $page;
    include __DIR__ . '/header.php';
}

/**
 * Close <main>, render the CTA band + footer + floating helpers and the bundle scripts.
 * $opts: cta (bool, default true), cta_title, cta_text, cta_primary [label,url], cta_secondary [label,url,icon],
 *        cta_image, cta_highlight (word of cta_title to circle), footer ('full' default | 'minimal' = copyright bar for
 *        campaign pages), directory (bool, default true — expandable "All Programs" directory in the footer card),
 *        mobile_cta (bool | ['call' => phone, 'apply' => ['label', 'url']] — sticky Call · Apply bar on phones;
 *        default setting site_mobile_cta = 1, off on /apply)
 */
function site_footer(array $opts = []): void
{
    $GLOBALS['__site_footer'] = $opts;
    include __DIR__ . '/footer.php';
}

/**
 * Inner page hero with breadcrumbs (About, Programs, Contact … style). Design v3 variants:
 *   light (default, setting page_hero_variant): warm mesh + faint navy grid, bold Plus Jakarta headline revealed line by
 *         line (text mask) with a hand-drawn orange-red ellipse around one word, layered photo composition on the right
 *         (arch-cut main photo with a clip-path reveal, inset secondary photo, gradient ring, glass badge, morphing blob,
 *         floating geometric shapes);
 *   dark:  navy mesh band with a grid, same composition in white;
 *   photo: legacy full-bleed photo with a navy overlay (glass chips).
 *
 *   page_hero('Academic Programs', 'Future-Ready Programs for a Brighter Tomorrow', ['Programs'],
 *       'assets/images/site/students-laptop.jpg', 'Explore our wide range of …',
 *       [['icon' => 'briefcase-business', 'label' => 'Industry Relevant Curriculum'], …],
 *       ['script' => 'Learn Grow Innovate Succeed', 'actions' => button_link('Apply Now', '/apply', 'accent'),
 *        'highlight' => 'Programs']);
 *
 * $breadcrumbs: ['Label' => '/url', 'Current page'] (string keys = links, numeric = current page).
 * $features:    [['icon' => lucide name, 'label' => text], …] shown as pill chips.
 * $image:       '' = no photo (text only).
 * $opts (all optional): variant (light|dark|photo), highlight (word/phrase of $title to circle; default the last word
 *       of 3+ letters; false = none), parallax (bool, default true — photo drifts inside its frame, desktop only),
 *       pattern (bool, default true), glass (bool, photo variant chips), eyebrow (string), eyebrow_icon (lucide name),
 *       script (string — sticker tag on the photo, e.g. "Learn Grow Succeed"), actions (HTML buttons), size
 *       ('md'|'lg'), align ('left'|'center'), id (section id), image_alt (string), image2 (inset secondary photo path;
 *       default a different campus photo; false = none), badge (['icon', 'text'] glass badge on the photo; default the
 *       admissions_open_text setting; false = none).
 */
function page_hero(string $title, string $subtitle = '', array $breadcrumbs = [], string $image = 'assets/images/site/campus-building.jpg', string $text = '', array $features = [], array $opts = []): void
{
    $variant = $opts['variant'] ?? (string) setting('page_hero_variant', 'light');
    $variant = in_array($variant, ['light', 'dark', 'photo'], true) ? $variant : 'light';
    $center = ($opts['align'] ?? 'left') === 'center';
    $idAttr = !empty($opts['id']) ? ' id="' . e($opts['id']) . '"' : '';
    $highlight = array_key_exists('highlight', $opts) ? $opts['highlight'] : null;
    $crumbs = function () use ($breadcrumbs, $center): string {
        if (!$breadcrumbs) {
            return '';
        }
        $h = '<nav aria-label="Breadcrumb" class="animate-fade-down"><ol class="page-hero-crumbs' . ($center ? ' justify-center' : '') . '">';
        $h .= '<li><a href="' . e(site_url('')) . '" class="underline-grow">Home</a></li>';
        foreach ($breadcrumbs as $label => $url) {
            $h .= '<li aria-hidden="true">' . icon('chevron-right', 'h-3.5 w-3.5 opacity-60') . '</li>';
            $h .= is_string($label) ? '<li><a class="underline-grow" href="' . e(cms_link($url)) . '">' . e($label) . '</a></li>' : '<li class="font-medium" aria-current="page">' . e($url) . '</li>';
        }
        return $h . '</ol></nav>';
    };
    $eyebrow = !empty($opts['eyebrow'])
        ? '<p class="page-hero-eyebrow animate-fade-up"><span class="page-hero-eyebrow-icon">' . icon($opts['eyebrow_icon'] ?? 'sparkles', 'h-4 w-4') . '</span>' . e($opts['eyebrow']) . '</p>'
        : '';
    $chips = function (string $chipClass) use ($features, $center): string {
        if (!$features) {
            return '';
        }
        $h = '<ul class="mt-8 flex flex-wrap gap-2.5' . ($center ? ' justify-center' : '') . '">';
        foreach (array_values($features) as $i => $f) {
            $h .= '<li class="page-hero-chip ' . $chipClass . ' animate-fade-up" style="animation-delay:' . (350 + $i * 90) . 'ms"><span class="page-hero-chip-icon">' . icon($f['icon'] ?? 'check', 'h-4 w-4') . '</span>' . e($f['label'] ?? '') . '</li>';
        }
        return $h . '</ul>';
    };

    if ($variant === 'photo') {
        $img = site_image($image !== '' ? $image : 'assets/images/site/campus-building.jpg');
        $parallax = $opts['parallax'] ?? true;
        $pad = ($opts['size'] ?? 'md') === 'lg' ? 'py-20 sm:py-28 lg:py-32' : 'py-14 sm:py-20';
        echo '<section class="page-hero page-hero-photo"' . $idAttr . '>';
        echo '<div class="page-hero-media"><img src="' . e($img) . '" alt="' . e($opts['image_alt'] ?? '') . '" class="' . ($parallax ? 'parallax-media' : '') . '"'
            . ($parallax ? ' data-parallax="0.12"' : '') . ' fetchpriority="high" decoding="async"></div>';
        echo '<div class="absolute inset-0 -z-[1] bg-hero-overlay"></div>';
        if ($opts['pattern'] ?? true) {
            echo '<div class="pointer-events-none absolute inset-y-0 left-0 -z-[1] w-2/3 pattern-grid-light pattern-fade opacity-70" aria-hidden="true"></div>';
        }
        echo '<div class="container-site relative ' . $pad . ($center ? ' text-center' : '') . '">' . $crumbs() . $eyebrow;
        echo '<h1 class="page-hero-title max-w-3xl animate-fade-up' . ($center ? ' mx-auto' : '') . '">' . scribble_text($title, $highlight, 'light') . '</h1>';
        if ($subtitle !== '') {
            echo '<p class="page-hero-subtitle animate-fade-up delay-1">' . e($subtitle) . '</p>';
        }
        if ($text !== '') {
            echo '<p class="page-hero-text animate-fade-up delay-2' . ($center ? ' mx-auto' : '') . '">' . e($text) . '</p>';
        }
        if (!empty($opts['actions'])) {
            echo '<div class="mt-8 flex flex-wrap gap-3 animate-fade-up delay-3' . ($center ? ' justify-center' : '') . '">' . $opts['actions'] . '</div>';
        }
        echo $chips(($opts['glass'] ?? true) ? 'glass-light' : '') . '</div>';
        if (!empty($opts['script'])) {
            echo '<p class="page-hero-script float-slow" aria-hidden="true">' . implode('<br>', array_map('e', preg_split('/\s+/', trim($opts['script'])))) . '</p>';
        }
        echo '</section>';
        return;
    }

    $dark = $variant === 'dark';
    $img = $image !== '' ? site_image($image) : null;
    $split = $img && !$center;
    $parallax = $opts['parallax'] ?? true;
    $pad = ($opts['size'] ?? 'md') === 'lg' ? 'py-12 sm:py-16 lg:py-24' : 'py-10 sm:py-12 lg:py-16';
    $backdrop = preg_match('/^(lavender|sky|aqua|sand|cream|blush|pistachio|sun)$/', (string) ($opts['backdrop'] ?? '')) ? $opts['backdrop'] : 'sky';
    $ink = $dark ? 'text-white/80' : 'text-brand-900';
    echo '<section class="page-hero page-hero-' . $variant . '"' . $idAttr . '>';
    if (!$dark && ($opts['pattern'] ?? true)) {
        echo '<div class="page-hero-paper" aria-hidden="true"></div>';
    }
    echo geo_shapes('hero');
    echo '<div class="container-site relative grid items-center gap-10 ' . $pad . ($split ? ' lg:grid-cols-12 lg:gap-12' : '') . '">';
    echo '<div class="' . ($split ? 'lg:col-span-7' : ($center ? 'mx-auto max-w-3xl text-center' : 'max-w-3xl')) . '">' . $crumbs() . $eyebrow;
    echo '<h1 class="page-hero-title' . ($center ? ' mx-auto' : '') . '" data-text-reveal>' . scribble_text($title, $highlight, $dark ? 'light' : 'orange') . '</h1>';
    if ($subtitle !== '') {
        echo '<p class="page-hero-subtitle animate-fade-up delay-1">' . e($subtitle) . '</p>';
    }
    if ($text !== '') {
        echo '<p class="page-hero-text animate-fade-up delay-2' . ($center ? ' mx-auto' : '') . '">' . e($text) . '</p>';
    }
    if (!empty($opts['actions'])) {
        echo '<div class="mt-8 flex flex-wrap gap-3 animate-fade-up delay-3' . ($center ? ' justify-center' : '') . '">' . $opts['actions'] . '</div>';
    }
    echo $chips($dark ? 'glass-light' : '') . '</div>';
    if ($split) {
        $pool = ['assets/images/site/students-lawn.jpg', 'assets/images/site/facility-library.jpg', 'assets/images/site/campus-main.jpg', 'assets/images/site/facility-labs.jpg'];
        $inset = $opts['image2'] ?? null;
        if ($inset === null) {
            foreach ($pool as $cand) {
                if (!str_contains($image, basename($cand))) {
                    $inset = $cand;
                    break;
                }
            }
        }
        $badge = $opts['badge'] ?? ['icon' => 'calendar-check', 'text' => (string) setting('admissions_open_text', 'Admissions Open for 2026-27')];
        echo '<div class="page-hero-visual lg:col-span-5 lg:pl-6">';
        echo '<span class="page-hero-ring" aria-hidden="true"></span>';
        echo blob('-left-16 -top-14 h-[125%] w-[125%]', $dark ? 'navy' : 'flame');
        echo '<div class="page-hero-frame clip-in"><img src="' . e($img) . '" alt="' . e($opts['image_alt'] ?? '') . '"' . ($parallax ? ' class="parallax-media" data-parallax="0.06"' : '') . ' fetchpriority="high" decoding="async" width="800" height="672"></div>';
        if ($inset) {
            echo '<div class="page-hero-inset"' . reveal_attr('zoom-in', 300) . '><img src="' . e(site_image($inset)) . '" alt="" loading="lazy" decoding="async" width="300" height="300"></div>';
        }
        if ($badge && !empty($badge['text'])) {
            echo '<div class="page-hero-badge glass-panel"' . reveal_attr('fade-up', 450) . '><span class="page-hero-badge-icon">' . icon($badge['icon'] ?? 'sparkles', 'h-5 w-5') . '</span><span>' . e($badge['text']) . '</span></div>';
        }
        echo '<span class="geo geo-dots -bottom-8 right-6 h-20 w-24" style="--d:1s" aria-hidden="true"></span>';
        echo '<span class="geo geo-square -right-2 top-[62%] h-3.5 w-3.5" style="--d:2s;--rot:20deg" aria-hidden="true"></span>';
        if (!empty($opts['script'])) {
            echo '<span class="absolute -top-4 left-6 z-[3]">' . sticker(implode(' · ', preg_split('/\s+/', trim($opts['script']))), ['color' => 'white', 'rotate' => -6, 'size' => 'sm']) . '</span>';
        }
        echo '</div>';
    }
    echo '</div></section>';
}

/**
 * Section heading block (bold Plus Jakarta title; optional hand-drawn ellipse around a word, sticker tag, action link).
 *   echo section_heading('Top Programs', 'Choose from …', 'Academics', 'left', button_link('View all', '/programs', 'outline'),
 *                        ['highlight' => 'Programs', 'sticker' => ['text' => 'Future ready', 'color' => 'blush']]);
 * @param array $o highlight (word/phrase of $title to circle), sticker (string | ['text', 'color', 'rotate']),
 *                 tag (h2|h1|h3, default h2), class (extra wrapper classes), lead_class, scribble ('orange'|'light'|'ink'),
 *                 reveal (bool, default true — text-mask line reveal of the title)
 */
function section_heading(string $title, string $lead = '', string $eyebrow = '', string $align = 'left', string $action = '', array $o = []): string
{
    $center = $align === 'center';
    $tag = in_array($o['tag'] ?? 'h2', ['h1', 'h2', 'h3'], true) ? ($o['tag'] ?? 'h2') : 'h2';
    $sticker = '';
    if (!empty($o['sticker'])) {
        $st = is_array($o['sticker']) ? $o['sticker'] : ['text' => (string) $o['sticker']];
        $sticker = ' <span class="ml-2 inline-block align-middle">' . sticker((string) ($st['text'] ?? ''), ['color' => $st['color'] ?? 'blush', 'rotate' => $st['rotate'] ?? -12, 'size' => 'sm']) . '</span>';
    }
    $titleHtml = !empty($o['highlight']) ? scribble_text($title, (string) $o['highlight'], $o['scribble'] ?? 'orange') : e($title);
    return '<div class="mb-10 flex flex-col gap-5 ' . ($center ? 'items-center text-center' : 'sm:flex-row sm:items-end sm:justify-between') . (!empty($o['class']) ? ' ' . e($o['class']) : '') . '">'
        . '<div class="' . ($center ? 'mx-auto max-w-3xl' : 'max-w-3xl') . '">'
        . ($eyebrow !== '' ? '<p class="eyebrow mb-3">' . e($eyebrow) . '</p>' : '')
        . '<' . $tag . ' class="section-title"' . (($o['reveal'] ?? true) ? ' data-text-reveal' : '') . '>' . $titleHtml . $sticker . '</' . $tag . '>'
        . ($lead !== '' ? '<p class="section-lead' . ($center ? ' mx-auto' : '') . (!empty($o['lead_class']) ? ' ' . e($o['lead_class']) : '') . '">' . e($lead) . '</p>' : '')
        . '</div>' . ($action !== '' && !$center ? '<div class="shrink-0">' . $action . '</div>' : '') . '</div>';
}

/* ------------------------------------------------------------------ Colour map: program levels, departments, tags */

/**
 * Single source of the website's accent colour coding (Design v3: navy + orange-red base, these hues as accents only).
 * Keys map to CSS classes `.hue-{key}` (assets/css/src/style.css: --hue strong colour ≥ 4.5:1 on white and with white
 * text, --tint pastel background, --ink dark text ≥ 7:1 on the tint) and to frontend/site/lib/hues.ts for islands.
 *   levels:      UG = ug (sky) · PG/PhD = pg (violet) · Diploma = diploma (teal) · Certificate = cert (amber)
 *   departments: by departments.code — MGT = mgt (orange-red) · CSE = tech (blue) · DCA = ca (purple) · COM = com
 *                (emerald) · SCI = sci (cyan) · PSD = skill (rose); unknown codes fall back to the program category, then other (indigo)
 *   tags:        popular (yellow) · featured (orange-red tint)
 */
function site_hues(): array
{
    return [
        'levels' => ['UG' => 'ug', 'PG' => 'pg', 'PhD' => 'pg', 'Diploma' => 'diploma', 'Certificate' => 'cert'],
        'departments' => ['MGT' => 'mgt', 'CSE' => 'tech', 'DCA' => 'ca', 'COM' => 'com', 'SCI' => 'sci', 'PSD' => 'skill'],
        'categories' => ['management' => 'mgt', 'technology' => 'tech', 'computer-applications' => 'ca', 'commerce' => 'com', 'science' => 'sci', 'diploma' => 'skill', 'certificate' => 'skill'],
    ];
}

/** CSS hue class for a program level code ('UG' → 'hue-ug'). */
function level_hue(?string $level): string
{
    return 'hue-' . (site_hues()['levels'][(string) $level] ?? 'other');
}

/** CSS hue class for a program's department (by department code, then category). */
function dept_hue(array $p): string
{
    $h = site_hues();
    $code = strtoupper((string) ($p['department_code'] ?? ''));
    if ($code === '' && isset($p['department_id'])) {
        foreach (site_programs() as $sp) {
            if ((int) $sp['department_id'] === (int) $p['department_id']) {
                $code = strtoupper((string) $sp['department_code']);
                break;
            }
        }
    }
    return 'hue-' . ($h['departments'][$code] ?? $h['categories'][slugify((string) ($p['category'] ?? ''))] ?? 'other');
}

/** Real tags of a program from DB flags (omitted when there is no data): [['key' => 'popular', 'label' => 'Most Popular'], …]. */
function program_tags(array $p): array
{
    $tags = [];
    if (!empty($p['is_popular'])) {
        $tags[] = ['key' => 'popular', 'label' => 'Most Popular'];
    }
    if (!empty($p['is_featured'])) {
        $tags[] = ['key' => 'featured', 'label' => 'Featured'];
    }
    return $tags;
}
