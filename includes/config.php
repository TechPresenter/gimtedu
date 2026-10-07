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
 */
require_once __DIR__ . '/../app/init.php';
require_once __DIR__ . '/../app/auth.php';     // starts the session (CSRF for public forms); no login required

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

/* ------------------------------------------------------------------ Layout */

/**
 * Output <head>, top bar and header.
 * $page keys: title, description, active (nav key or URL path), image (OG), seo => [type, id] or ['route', '/path'],
 *             canonical, robots, schema (array JSON-LD), body_class, transparent_header (bool)
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

/** Inner page hero banner with breadcrumbs (About, Programs, Contact ... style). */
function page_hero(string $title, string $subtitle = '', array $breadcrumbs = [], string $image = 'assets/images/site/campus-building.jpg', string $text = '', array $features = []): void
{
    $img = site_image($image);
    echo '<section class="relative isolate overflow-hidden bg-brand-900">';
    echo '<img src="' . e($img) . '" alt="" class="absolute inset-0 -z-10 h-full w-full object-cover" fetchpriority="high">';
    echo '<div class="absolute inset-0 -z-10 bg-hero-overlay"></div>';
    echo '<div class="container-site py-14 sm:py-20">';
    if ($breadcrumbs) {
        echo '<nav aria-label="Breadcrumb" class="mb-4"><ol class="flex flex-wrap items-center gap-1.5 text-sm text-white/70">';
        echo '<li><a href="' . e(site_url('')) . '" class="hover:text-white">Home</a></li>';
        foreach ($breadcrumbs as $label => $url) {
            echo '<li aria-hidden="true">' . icon('chevron-right', 'h-3.5 w-3.5') . '</li>';
            echo is_string($label) ? '<li><a class="hover:text-white" href="' . e(cms_link($url)) . '">' . e($label) . '</a></li>' : '<li class="font-medium text-white" aria-current="page">' . e($url) . '</li>';
        }
        echo '</ol></nav>';
    }
    echo '<h1 class="max-w-3xl font-display text-4xl font-extrabold leading-tight tracking-tight text-white sm:text-5xl">' . e($title) . '</h1>';
    if ($subtitle !== '') {
        echo '<p class="mt-2 font-display text-xl font-bold text-accent-400 sm:text-2xl">' . e($subtitle) . '</p>';
    }
    if ($text !== '') {
        echo '<p class="mt-4 max-w-2xl text-base leading-relaxed text-white/85 sm:text-lg">' . e($text) . '</p>';
    }
    if ($features) {
        echo '<ul class="mt-8 flex flex-wrap gap-x-8 gap-y-4">';
        foreach ($features as $f) {
            echo '<li class="flex items-center gap-2.5 text-sm font-medium text-white"><span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-white/10 text-accent-400 ring-1 ring-white/20">'
                . icon($f['icon'] ?? 'check', 'h-5 w-5') . '</span>' . e($f['label'] ?? '') . '</li>';
        }
        echo '</ul>';
    }
    echo '</div></section>';
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
