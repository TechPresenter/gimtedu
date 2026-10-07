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
 * Output <head>, top bar and header.
 * $page keys: title, description, active (nav key or URL path), image (OG), seo => [type, id] or ['route', '/path'],
 *             canonical, robots, schema (array JSON-LD), body_class, transparent_header (bool),
 *             cursor (bool, custom cursor ring — default setting site_cursor = 1), motion (false = no entrance
 *             animations on this page), loader (false = never show the first-visit loading screen), preload (array of
 *             image URLs to <link rel=preload as=image>, e.g. the LCP hero image)
 */
function site_header(array $page = []): void
{
    $GLOBALS['__site_page'] = $page;
    include __DIR__ . '/header.php';
}

function site_footer(array $opts = []): void
{
    $GLOBALS['__site_footer'] = $opts;
    include __DIR__ . '/footer.php';
}

/**
 * Inner page hero banner with breadcrumbs (About, Programs, Contact ... style).
 *
 *   page_hero('Academic Programs', 'Future-Ready Programs for a Brighter Tomorrow', ['Programs'],
 *       'assets/images/site/students-laptop.jpg', 'Explore our wide range of …',
 *       [['icon' => 'briefcase-business', 'label' => 'Industry Relevant Curriculum'], …],
 *       ['script' => 'Learn Grow Innovate Succeed', 'actions' => button_link('Apply Now', '/apply', 'accent')]);
 *
 * $breadcrumbs: ['Label' => '/url', 'Current page'] (string keys = links, numeric = current page).
 * $features:    [['icon' => lucide name, 'label' => text], …] shown as glass chips.
 * $opts (all optional): parallax (bool, default true — image drifts on scroll, desktop only), pattern (bool,
 *       default true — animated dot pattern), glass (bool, default true — glass feature chips), eyebrow (string),
 *       script (string — decorative handwritten-style words on the right; use spaces between words), actions
 *       (HTML buttons), size ('md'|'lg'), align ('left'|'center'), id (section id), image_alt (string).
 */
function page_hero(string $title, string $subtitle = '', array $breadcrumbs = [], string $image = 'assets/images/site/campus-building.jpg', string $text = '', array $features = [], array $opts = []): void
{
    $img = site_image($image);
    $parallax = $opts['parallax'] ?? true;
    $pattern = $opts['pattern'] ?? true;
    $glass = $opts['glass'] ?? true;
    $center = ($opts['align'] ?? 'left') === 'center';
    $pad = ($opts['size'] ?? 'md') === 'lg' ? 'py-20 sm:py-28 lg:py-32' : 'py-14 sm:py-20';
    echo '<section class="page-hero"' . (!empty($opts['id']) ? ' id="' . e($opts['id']) . '"' : '') . '>';
    echo '<div class="page-hero-media"><img src="' . e($img) . '" alt="' . e($opts['image_alt'] ?? '') . '" class="' . ($parallax ? 'parallax-media' : '') . '"'
        . ($parallax ? ' data-parallax="0.12"' : '') . ' fetchpriority="high" decoding="async"></div>';
    echo '<div class="absolute inset-0 -z-[1] bg-hero-overlay"></div>';
    if ($pattern) {
        echo '<div class="pointer-events-none absolute inset-y-0 left-0 -z-[1] w-2/3 pattern-dots-light pattern-fade animate-pattern-drift opacity-60" aria-hidden="true"></div>';
        echo '<div class="blob -left-24 -top-24 -z-[1] h-72 w-72 bg-accent-500/20" aria-hidden="true"></div>';
    }
    echo '<div class="container-site relative ' . $pad . ($center ? ' text-center' : '') . '">';
    if ($breadcrumbs) {
        echo '<nav aria-label="Breadcrumb" class="mb-5 animate-fade-down"><ol class="flex flex-wrap items-center gap-1.5 text-sm text-white/70' . ($center ? ' justify-center' : '') . '">';
        echo '<li><a href="' . e(site_url('')) . '" class="underline-grow hover:text-white">Home</a></li>';
        foreach ($breadcrumbs as $label => $url) {
            echo '<li aria-hidden="true">' . icon('chevron-right', 'h-3.5 w-3.5') . '</li>';
            echo is_string($label) ? '<li><a class="underline-grow hover:text-white" href="' . e(cms_link($url)) . '">' . e($label) . '</a></li>' : '<li class="font-medium text-white" aria-current="page">' . e($url) . '</li>';
        }
        echo '</ol></nav>';
    }
    if (!empty($opts['eyebrow'])) {
        echo '<p class="eyebrow mb-3 !text-accent-400 animate-fade-up">' . e($opts['eyebrow']) . '</p>';
    }
    echo '<h1 class="' . ($center ? 'mx-auto ' : '') . 'max-w-3xl font-display text-4xl font-extrabold leading-tight tracking-tight text-white sm:text-5xl lg:text-[3.4rem] animate-fade-up">' . e($title) . '</h1>';
    if ($subtitle !== '') {
        echo '<p class="mt-2 font-display text-xl font-bold text-accent-400 sm:text-2xl animate-fade-up delay-1">' . e($subtitle) . '</p>';
    }
    if ($text !== '') {
        echo '<p class="mt-4 ' . ($center ? 'mx-auto ' : '') . 'max-w-2xl text-base leading-relaxed text-white/85 sm:text-lg animate-fade-up delay-2">' . e($text) . '</p>';
    }
    if (!empty($opts['actions'])) {
        echo '<div class="mt-7 flex flex-wrap gap-3 animate-fade-up delay-3' . ($center ? ' justify-center' : '') . '">' . $opts['actions'] . '</div>';
    }
    if ($features) {
        echo '<ul class="mt-8 flex flex-wrap gap-3' . ($glass ? '' : ' gap-x-8 gap-y-4') . ($center ? ' justify-center' : '') . '">';
        foreach (array_values($features) as $i => $f) {
            $style = ' style="animation-delay:' . (350 + $i * 90) . 'ms"';
            echo $glass
                ? '<li class="page-hero-chip glass-light animate-fade-up"' . $style . '><span class="page-hero-chip-icon">' . icon($f['icon'] ?? 'check', 'h-5 w-5') . '</span>' . e($f['label'] ?? '') . '</li>'
                : '<li class="flex items-center gap-2.5 text-sm font-medium text-white animate-fade-up"' . $style . '><span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-white/10 text-accent-400 ring-1 ring-white/20">' . icon($f['icon'] ?? 'check', 'h-5 w-5') . '</span>' . e($f['label'] ?? '') . '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';
    if (!empty($opts['script'])) {
        echo '<p class="page-hero-script float-slow" aria-hidden="true">' . implode('<br>', array_map('e', preg_split('/\s+/', trim($opts['script'])))) . '</p>';
    }
    echo '</section>';
}

/** Section heading block. */
function section_heading(string $title, string $lead = '', string $eyebrow = '', string $align = 'left', string $action = ''): string
{
    $center = $align === 'center';
    return '<div class="mb-10 flex flex-col gap-4 ' . ($center ? 'items-center text-center' : 'sm:flex-row sm:items-end sm:justify-between') . '">'
        . '<div class="' . ($center ? 'mx-auto max-w-2xl' : 'max-w-2xl') . '">'
        . ($eyebrow !== '' ? '<p class="eyebrow mb-2">' . e($eyebrow) . '</p>' : '')
        . '<h2 class="section-title">' . e($title) . '</h2>'
        . ($lead !== '' ? '<p class="section-lead ' . ($center ? 'mx-auto' : '') . '">' . e($lead) . '</p>' : '')
        . '</div>' . ($action !== '' && !$center ? '<div class="shrink-0">' . $action . '</div>' : '') . '</div>';
}
