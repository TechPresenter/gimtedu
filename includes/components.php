<?php
/**
 * Website render helpers (public pages). Required by includes/config.php.
 *
 * Two families:
 *  1. Plain server-rendered building blocks that return HTML strings:
 *       reveal_attr(), section_open()/section_close(), button_link(), program_card(), feature_tile(), stat_card(),
 *       cta_band(), overlay_card(), check_list(), glass_chip()
 *  2. React island wrappers — each renders a meaningful SEO/no-JS fallback (same layout as the component, so no layout
 *     shift) and the island placeholder: hero_slider(), program_explorer(), carousel(), testimonial_slider(),
 *     logo_marquee(), stat_counter(), typing_text(), faq_accordion(), content_tabs(), video_lightbox(), gallery_grid(),
 *     enquiry_form(), skeleton_list(), auto_scroll_cards(), progress_steps()
 *
 * All text arguments are plain text (escaped here). Arguments documented as "HTML" must already be safe.
 * Reference + live examples: docs/WEBSITE.md and /site-kit.php (dev only).
 *
 * Tailwind keeps component classes only when it sees them literally; the helpers build some names dynamically:
 *   btn-primary btn-accent btn-navy btn-secondary btn-outline btn-outline-light btn-white btn-ghost btn-sm btn-lg
 *   tint-blue tint-green tint-cyan tint-amber tint-violet tint-rose tint-navy pattern-dots-light pattern-grid-light
 */

/* ============================================================================ Building blocks */

/**
 * Scroll-reveal attributes for any element: `<div<?= reveal_attr('zoom-in', 150) ?>>`.
 * Effects: fade-up (default) | fade-in | fade-down | zoom-in | slide-left | slide-right | blur-in.
 */
function reveal_attr(string $effect = 'fade-up', int $delay = 0): string
{
    return ' data-reveal="' . e($effect) . '"' . ($delay > 0 ? ' data-reveal-delay="' . $delay . '"' : '');
}

/**
 * Open a page section (+ container). Close it with section_close().
 *
 *   echo section_open(['id' => 'programs', 'bg' => 'light', 'pattern' => true]);
 *   echo section_heading('Explore Our Programs', 'Choose from …', 'Academics');
 *   …
 *   echo section_close();
 *
 * @param array $o id, bg (white|light|navy|gradient|brand-soft), pattern (dots|grid|true), padding (normal|tight|none),
 *                 class (extra classes), container (bool, default true), label (aria-label), reveal (bool: fade the
 *                 section content in)
 */
function section_open(array $o = []): string
{
    $bg = [
        'white' => 'bg-white',
        'light' => 'section-light',
        'navy' => 'section-navy',
        'gradient' => 'section-navy bg-animated-gradient',
        'brand-soft' => 'bg-gradient-to-b from-brand-50/70 to-white',
    ][$o['bg'] ?? 'white'] ?? 'bg-white';
    $dark = in_array($o['bg'] ?? '', ['navy', 'gradient'], true);
    $pattern = $o['pattern'] ?? false;
    $patternLayer = $pattern ? '<div class="pattern-layer ' . ($pattern === 'grid' ? 'pattern-grid' : 'pattern-dots') . ($dark ? '-light' : '') . ' pattern-fade animate-pattern-drift" aria-hidden="true"></div>' : '';
    $pad = ['normal' => 'section', 'tight' => 'section-tight', 'none' => ''][$o['padding'] ?? 'normal'] ?? 'section';
    $cls = trim("relative isolate $pad $bg " . ($o['class'] ?? ''));
    $html = '<section class="' . e($cls) . '"' . (!empty($o['id']) ? ' id="' . e($o['id']) . '"' : '') . (!empty($o['label']) ? ' aria-label="' . e($o['label']) . '"' : '') . '>' . $patternLayer;
    if ($o['container'] ?? true) {
        $html .= '<div class="container-site"' . (!empty($o['reveal']) ? reveal_attr() : '') . '>';
    }
    $GLOBALS['__section_container'][] = $o['container'] ?? true;
    return $html;
}

function section_close(): string
{
    $container = array_pop($GLOBALS['__section_container']);
    return ($container ?? true ? '</div>' : '') . '</section>';
}

/**
 * Button-styled link.
 *   button_link('Apply Now', '/apply', 'accent', ['size' => 'lg', 'magnetic' => true, 'shine' => true])
 * Variants: primary | accent | navy | secondary | outline | outline-light | white | ghost.
 * @param array $o icon (trailing lucide name, default 'arrow-right' for accent/primary/navy, '' for none),
 *                 icon_left, size (sm|lg), magnetic (bool), shine (bool), class, target, attrs (array)
 */
function button_link(string $label, string $url, string $variant = 'primary', array $o = []): string
{
    $defaultIcon = in_array($variant, ['accent', 'primary', 'navy'], true) ? 'arrow-right' : '';
    $iconR = $o['icon'] ?? $defaultIcon;
    $cls = 'btn btn-' . $variant . (!empty($o['size']) ? ' btn-' . $o['size'] : '') . (!empty($o['shine']) ? ' btn-shine' : '') . (!empty($o['class']) ? ' ' . $o['class'] : '');
    $attrs = '';
    foreach (($o['attrs'] ?? []) as $k => $v) {
        $attrs .= ' ' . e($k) . '="' . e($v) . '"';
    }
    if (!empty($o['magnetic'])) {
        $attrs .= ' data-magnetic="' . (is_numeric($o['magnetic']) ? (float) $o['magnetic'] : 0.25) . '"';
    }
    if (!empty($o['target'])) {
        $attrs .= ' target="' . e($o['target']) . '"' . ($o['target'] === '_blank' ? ' rel="noopener"' : '');
    }
    return '<a href="' . e(cms_link($url)) . '" class="' . e($cls) . '"' . $attrs . '>'
        . (!empty($o['icon_left']) ? icon($o['icon_left'], 'h-4 w-4') : '') . e($label) . ($iconR ? icon($iconR, 'h-4 w-4') : '') . '</a>';
}

/** Program level code -> label. */
function program_level_label(?string $level): string
{
    return ['UG' => 'Undergraduate', 'PG' => 'Postgraduate', 'PhD' => 'Doctoral', 'Diploma' => 'Diploma', 'Certificate' => 'Certificate'][$level ?? ''] ?? (string) $level;
}

/** Space-separated filter tokens for a program (level + category), used by data-filter / ProgramExplorer. */
function program_filter_tokens(array $p): string
{
    $tokens = [strtolower((string) ($p['level'] ?? ''))];
    $cat = slugify((string) ($p['category'] ?? ''));
    if ($cat !== '') {
        $tokens[] = $cat;
    }
    if ($cat === 'computer-applications') {
        $tokens[] = 'technology';
    }
    return implode(' ', array_unique(array_filter($tokens)));
}

/** Acronym shown after a program name, e.g. "(BBA)" — only for plain acronyms not already part of the name. */
function program_title_suffix(array $p): string
{
    $short = (string) ($p['short_name'] ?? '');
    $name = (string) ($p['name'] ?? '');
    return preg_match('/^[A-Z][A-Za-z.]{1,9}$/', $short) && !str_starts_with($name, $short) && !str_contains($name, '(') ? $short : '';
}

/** Lucide icon for a program category. */
function program_icon(array $p): string
{
    return [
        'management' => 'briefcase-business', 'technology' => 'cpu', 'computer-applications' => 'laptop', 'commerce' => 'chart-column',
        'science' => 'flask-conical', 'diploma' => 'award', 'certificate' => 'badge-check',
    ][slugify((string) ($p['category'] ?? ''))] ?? 'graduation-cap';
}

/**
 * Program card (photo, ribbon, title, duration/level/fee, summary, View Details + Apply Now) as in the mockups.
 * $p is a `programs` row (name, short_name, slug, level, category, duration_label, fee_per_year, fee_label, overview,
 * image, is_popular). $o: reveal (effect name or false), delay (ms), ribbon (override text), heading (h2|h3).
 */
function program_card(array $p, array $o = []): string
{
    $url = site_url('programs/' . ($p['slug'] ?? ''));
    $apply = site_url('apply?program=' . rawurlencode((string) ($p['slug'] ?? '')));
    $ribbon = $o['ribbon'] ?? (!empty($p['is_popular']) ? 'Most Popular' : '');
    $h = $o['heading'] ?? 'h3';
    $fee = isset($p['fee_per_year']) && (float) $p['fee_per_year'] > 0 ? money($p['fee_per_year']) . ' ' . ($p['fee_label'] ?? '/ Year') : '';
    $reveal = ($o['reveal'] ?? false) ? reveal_attr((string) $o['reveal'], (int) ($o['delay'] ?? 0)) : '';
    $html = '<article class="program-card card-lift group"' . $reveal . '>';
    $html .= '<a href="' . e($url) . '" class="program-card-media img-zoom block" tabindex="-1" aria-hidden="true">'
        . '<img src="' . e(site_image($p['image'] ?? null)) . '" alt="" loading="lazy" decoding="async" width="640" height="400">'
        . ($ribbon !== '' ? '<span class="program-ribbon">' . icon('sparkles', 'h-3 w-3') . e($ribbon) . '</span>' : '')
        . '<span class="program-level">' . e(program_level_label($p['level'] ?? '')) . '</span></a>';
    $html .= '<div class="program-card-body">';
    $html .= '<' . $h . ' class="program-card-title"><a href="' . e($url) . '" class="hover:text-brand-700">' . e($p['name'] ?? '') . (program_title_suffix($p) !== '' ? ' <span class="whitespace-nowrap">(' . e(program_title_suffix($p)) . ')</span>' : '') . '</a></' . $h . '>';
    $html .= '<ul class="program-meta">';
    if (!empty($p['duration_label'])) {
        $html .= '<li>' . icon('clock', '') . e($p['duration_label']) . '</li>';
    }
    $html .= '<li>' . icon('graduation-cap', '') . e(program_level_label($p['level'] ?? '')) . (!empty($p['category']) ? ' · ' . e($p['category']) : '') . '</li>';
    if ($fee !== '') {
        $html .= '<li>' . icon('wallet', '') . e($fee) . '</li>';
    }
    $html .= '</ul>';
    if (!empty($p['overview'])) {
        $html .= '<p class="program-card-text">' . e(str_limit(strip_tags((string) $p['overview']), 140)) . '</p>';
    }
    $html .= '<div class="program-card-actions">'
        . '<a href="' . e($url) . '" class="btn btn-outline">View Details<span class="sr-only"> about ' . e($p['name'] ?? '') . '</span></a>'
        . '<a href="' . e($apply) . '" class="btn btn-accent">Apply Now' . icon('arrow-right', 'h-4 w-4') . '</a>'
        . '</div></div></article>';
    return $html;
}

/**
 * Icon feature tile ("Why Choose GIMT" cards).
 * @param array $o tint (blue|green|cyan|amber|violet|rose|navy, default navy), variant (card|compact|plain), url,
 *                 reveal (effect or false), delay (ms), heading (h3|h4)
 */
function feature_tile(string $icon, string $title, string $text = '', array $o = []): string
{
    $variant = $o['variant'] ?? 'card';
    $tint = 'tint-' . ($o['tint'] ?? 'navy');
    $h = $o['heading'] ?? 'h3';
    $cls = 'feature-tile group card-lift' . ($variant === 'compact' ? ' feature-tile-compact' : '') . ($variant === 'plain' ? ' feature-tile-plain' : '');
    $reveal = ($o['reveal'] ?? false) ? reveal_attr((string) $o['reveal'], (int) ($o['delay'] ?? 0)) : '';
    $inner = '<span class="feature-tile-icon ' . $tint . '">' . icon($icon, $variant === 'compact' ? 'h-6 w-6' : 'h-7 w-7') . '</span>'
        . '<span class="min-w-0"><' . $h . ' class="feature-tile-title">' . (!empty($o['url']) ? '<a href="' . e(cms_link($o['url'])) . '" class="stretched-link">' . e($title) . '</a>' : e($title)) . '</' . $h . '>'
        . ($text !== '' ? '<p class="feature-tile-text">' . e($text) . '</p>' : '') . '</span>';
    return '<div class="' . $cls . '"' . $reveal . '>' . $inner . '</div>';
}

/**
 * Stat card with an animated counter (vanilla data-count; server renders the final value).
 *   stat_card(1000, 'Students', 'users', ['suffix' => '+'])
 * @param array $o prefix, suffix, decimals, tint, dark (bool), reveal, delay
 */
function stat_card($value, string $label, string $icon = '', array $o = []): string
{
    $num = (float) $value;
    $dec = (int) ($o['decimals'] ?? (str_contains((string) $value, '.') ? strlen(substr(strrchr((string) $value, '.'), 1)) : 0));
    $shown = ($o['prefix'] ?? '') . number_format($num, $dec) . ($o['suffix'] ?? '');
    $reveal = ($o['reveal'] ?? false) ? reveal_attr((string) $o['reveal'], (int) ($o['delay'] ?? 0)) : '';
    return '<div class="stat-card' . (!empty($o['dark']) ? ' stat-card-dark' : '') . '"' . $reveal . '>'
        . ($icon !== '' ? '<span class="stat-card-icon tint-' . e($o['tint'] ?? 'navy') . '">' . icon($icon, 'h-7 w-7') . '</span>' : '')
        . '<span><span class="stat-card-value" data-count="' . e((string) $num) . '"' . (isset($o['prefix']) ? ' data-prefix="' . e($o['prefix']) . '"' : '') . (isset($o['suffix']) ? ' data-suffix="' . e($o['suffix']) . '"' : '') . ($dec ? ' data-decimals="' . $dec . '"' : '') . '>' . e($shown) . '</span>'
        . '<span class="stat-card-label">' . e($label) . '</span></span></div>';
}

/**
 * Navy call-to-action band with animated pattern, image and floating graduation cap.
 *   echo cta_band(['title' => 'Ready to Build a Brighter Future?', 'text' => '…',
 *                  'primary' => ['label' => 'Apply for Admission', 'url' => '/apply'],
 *                  'secondary' => ['label' => 'Download Brochure', 'url' => '/programs#brochure', 'icon' => 'download']]);
 * @param array $o title, text, primary, secondary, image (path), eyebrow, compact (bool)
 */
function cta_band(array $o = []): string
{
    $img = site_image($o['image'] ?? 'assets/images/site/graduation.jpg');
    $html = '<section class="cta-band section-navy pattern-dots-light">';
    $html .= '<img src="' . e($img) . '" alt="" class="cta-band-media" loading="lazy" decoding="async"><div class="cta-band-bg"></div>';
    $html .= '<span class="cta-band-deco -right-6 -top-6 float-rotate" aria-hidden="true">' . icon('graduation-cap', 'h-44 w-44') . '</span>';
    $html .= '<div class="blob -bottom-24 left-1/3 -z-[5] h-64 w-64 bg-accent-500/25" aria-hidden="true"></div>';
    $html .= '<div class="container-site flex flex-col items-start justify-between gap-6 ' . (!empty($o['compact']) ? 'py-10' : 'py-12 sm:py-14') . ' md:flex-row md:items-center"' . reveal_attr('fade-up') . '>';
    $html .= '<div class="max-w-2xl">' . (!empty($o['eyebrow']) ? '<p class="eyebrow mb-2">' . e($o['eyebrow']) . '</p>' : '')
        . '<h2 class="font-display text-2xl font-extrabold text-white sm:text-3xl">' . e($o['title'] ?? 'Ready to Build a Brighter Future?') . '</h2>'
        . (($o['text'] ?? '') !== '' ? '<p class="mt-2 text-white/75">' . e($o['text']) . '</p>' : '') . '</div>';
    $html .= '<div class="flex flex-wrap gap-3">';
    if (!empty($o['primary'])) {
        $html .= button_link($o['primary']['label'], $o['primary']['url'], 'accent', ['size' => 'lg', 'shine' => true, 'magnetic' => true, 'icon' => $o['primary']['icon'] ?? 'arrow-right']);
    }
    if (!empty($o['secondary'])) {
        $html .= button_link($o['secondary']['label'], $o['secondary']['url'], 'outline-light', ['size' => 'lg', 'icon' => '', 'icon_left' => $o['secondary']['icon'] ?? null]);
    }
    return $html . '</div></div></section>';
}

/** Image card with navy overlay, title, text and link (Campus Life / Placements / News & Events). */
function overlay_card(string $title, string $text, string $url, string $image, string $linkLabel = 'Explore More', array $o = []): string
{
    $reveal = ($o['reveal'] ?? false) ? reveal_attr((string) $o['reveal'], (int) ($o['delay'] ?? 0)) : '';
    return '<article class="overlay-card group"' . $reveal . '><img src="' . e(site_image($image)) . '" alt="" loading="lazy" decoding="async">'
        . '<h3 class="overlay-card-title">' . e($title) . '</h3><p class="overlay-card-text">' . e($text) . '</p>'
        . '<a href="' . e(cms_link($url)) . '" class="overlay-card-link stretched-link">' . e($linkLabel) . icon('arrow-right', 'h-4 w-4') . '</a></article>';
}

/** Bulleted list with green check icons. */
function check_list(array $items, string $class = ''): string
{
    $html = '<ul class="check-list ' . e($class) . '">';
    foreach ($items as $item) {
        $html .= '<li>' . icon('circle-check', '') . '<span>' . e($item) . '</span></li>';
    }
    return $html . '</ul>';
}

/** Small glassmorphism chip (icon + label) for use over images. $variant: dark | light | white. */
function glass_chip(string $icon, string $label, string $variant = 'dark', string $class = ''): string
{
    $cls = ['dark' => 'glass-dark', 'light' => 'glass-light', 'white' => 'glass text-brand-900'][$variant] ?? 'glass-dark';
    return '<span class="inline-flex items-center gap-2.5 rounded-xl px-3.5 py-2.5 text-[13px] font-semibold ' . $cls . ' ' . e($class) . '">'
        . '<span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-white/15 text-accent-400 ring-1 ring-white/20">' . icon($icon, 'h-4 w-4') . '</span>' . e($label) . '</span>';
}

/* ============================================================================ Island wrappers (fallback + island) */

/**
 * Homepage hero slider (island HeroSlider, mounted eagerly). Fallback = the first slide, same layout.
 *   echo hero_slider([
 *     'slides' => [['image' => site_image(...), 'eyebrow' => 'Shaping Future Leaders', 'title' => 'Shape Your Future.',
 *                   'highlight' => 'Build Your Legacy.', 'text' => '…', 'cta' => ['label' => 'Apply for Admission',
 *                   'url' => '/apply'], 'cta2' => ['label' => 'Explore Programs', 'url' => '/programs']], …],
 *     'chips' => [['icon' => 'briefcase-business', 'label' => 'Industry Focused'], …],
 *     'stats' => [['value' => 10, 'suffix' => '+', 'label' => 'Programs', 'icon' => 'graduation-cap'], …],
 *     'video' => ['url' => 'https://www.youtube.com/watch?v=…', 'label' => 'Watch Our Campus Tour'],
 *     'tagline' => 'Admissions Open for 2026-27', 'taglineWords' => ['Learn', 'Grow', 'Innovate', 'Succeed'],
 *     'interval' => 6500, 'theme' => 'light']);
 * Image/CTA URLs must be final URLs (use site_image()/site_url()/cms_link()).
 */
function hero_slider(array $props): string
{
    $slides = array_values($props['slides'] ?? []);
    if (!$slides) {
        return '';
    }
    foreach ($slides as &$s) {
        foreach (['cta', 'cta2'] as $k) {
            if (!empty($s[$k]['url'])) {
                $s[$k]['url'] = cms_link($s[$k]['url']);
            }
        }
    }
    unset($s);
    $props['slides'] = $slides;
    $first = $slides[0];
    $dark = ($props['theme'] ?? 'light') === 'dark';
    $stats = $props['stats'] ?? [];
    $html = '<section class="hero-slider ' . ($dark ? 'hero-dark' : 'hero-light') . ($stats ? ' has-stats' : '') . '" aria-label="' . e($props['ariaLabel'] ?? 'Featured highlights') . '">';
    $html .= '<div class="hero-media" aria-hidden="true"><div class="hero-media-item is-active"><picture>'
        . (!empty($first['mobileImage']) ? '<source media="(max-width: 767px)" srcset="' . e($first['mobileImage']) . '">' : '')
        . '<img src="' . e($first['image']) . '" alt="" class="hero-img animate-ken-burns" fetchpriority="high" decoding="sync"></picture></div>'
        . '<div class="hero-overlay"></div><div class="hero-pattern pattern-dots animate-pattern-drift"></div></div>';
    $html .= '<div class="container-site hero-inner"><div class="hero-copy"><div class="hero-copy-item is-active">';
    if (!empty($first['eyebrow'])) {
        $html .= '<p class="hero-eyebrow"><span class="hero-eyebrow-dot"></span>' . e($first['eyebrow']) . '</p>';
    }
    $html .= '<h1 class="hero-title">' . e($first['title']) . (!empty($first['highlight']) ? '<span class="hero-highlight">' . e($first['highlight']) . '</span>' : '') . '</h1>';
    if (!empty($first['text'])) {
        $html .= '<p class="hero-text">' . e($first['text']) . '</p>';
    }
    if (!empty($first['cta']) || !empty($first['cta2'])) {
        $html .= '<div class="hero-actions">'
            . (!empty($first['cta']) ? '<a href="' . e($first['cta']['url']) . '" class="btn btn-accent btn-lg btn-shine">' . e($first['cta']['label']) . icon('arrow-right', 'h-4 w-4') . '</a>' : '')
            . (!empty($first['cta2']) ? '<a href="' . e($first['cta2']['url']) . '" class="btn btn-lg ' . ($dark ? 'btn-outline-light' : 'btn-outline') . '">' . e($first['cta2']['label']) . '</a>' : '')
            . '</div>';
    }
    $html .= '</div>';
    if (!empty($props['tagline']) || !empty($props['taglineWords'])) {
        $html .= '<div class="hero-tagline">'
            . (!empty($props['tagline']) ? '<p class="hero-tagline-main">' . icon('graduation-cap', 'h-6 w-6 text-accent-600') . e($props['tagline']) . '</p>' : '')
            . (!empty($props['taglineWords']) ? '<p class="hero-tagline-words">' . implode('<span class="hero-tagline-sep" aria-hidden="true">•</span>', array_map('e', $props['taglineWords'])) . '</p>' : '')
            . '</div>';
    }
    $html .= '</div>';
    if (!empty($props['chips'])) {
        $html .= '<ul class="hero-chips" aria-label="Highlights">';
        foreach (array_values($props['chips']) as $i => $c) {
            $html .= '<li class="hero-chip glass-dark" style="--i:' . $i . '"><span class="hero-chip-icon">' . icon($c['icon'] ?? 'sparkles', 'h-4 w-4') . '</span>' . e($c['label'] ?? '') . '</li>';
        }
        $html .= '</ul>';
    }
    if (!empty($props['video']['url'])) {
        $v = $props['video'];
        $html .= '<a href="' . e($v['url']) . '" data-lightbox class="hero-video" data-caption="' . e($v['label'] ?? '') . '"><span class="play-pulse">' . icon('play', 'h-5 w-5 translate-x-px') . '</span>'
            . '<span class="text-left"><span class="block text-sm font-semibold">' . e($v['label'] ?? 'Watch video') . '</span>' . (!empty($v['sublabel']) ? '<span class="block text-xs opacity-75">' . e($v['sublabel']) . '</span>' : '') . '</span></a>';
    }
    if (count($slides) > 1) {
        // same footprint as the interactive controls
        $html .= '<div class="hero-controls" aria-hidden="true"><span class="hero-arrow">' . icon('chevron-left', 'h-5 w-5') . '</span><span class="hero-bullets">';
        foreach ($slides as $i => $_) {
            $html .= '<span class="hero-bullet' . ($i === 0 ? ' is-active' : '') . '"></span>';
        }
        $html .= '</span><span class="hero-arrow">' . icon('chevron-right', 'h-5 w-5') . '</span>' . (($props['interval'] ?? 6500) > 0 ? '<span class="hero-arrow">' . icon('pause', 'h-4 w-4') . '</span>' : '') . '</div>';
    }
    $html .= '</div>';
    if ($stats) {
        $html .= '<div class="container-site hero-stats-wrap"><ul class="hero-stats" aria-label="GIMT at a glance">';
        foreach ($stats as $s) {
            $html .= '<li class="hero-stat">' . (!empty($s['icon']) ? '<span class="hero-stat-icon">' . icon($s['icon'], 'h-6 w-6') . '</span>' : '')
                . '<p><span class="hero-stat-value">' . e(($s['prefix'] ?? '') . number_format((float) $s['value']) . ($s['suffix'] ?? '')) . '</span><span class="hero-stat-label">' . e($s['label']) . '</span></p></li>';
        }
        $html .= '</ul></div>';
    }
    $html .= '</section>';
    return island('HeroSlider', $props, $html, ['eager' => true]);
}

/** Default ProgramExplorer categories (keys match program_filter_tokens()). */
function program_categories(): array
{
    return [
        ['key' => 'all', 'label' => 'All Programs', 'icon' => 'layout-grid'],
        ['key' => 'ug', 'label' => 'Undergraduate', 'icon' => 'graduation-cap'],
        ['key' => 'pg', 'label' => 'Postgraduate', 'icon' => 'school'],
        ['key' => 'management', 'label' => 'Management', 'icon' => 'briefcase-business'],
        ['key' => 'technology', 'label' => 'Technology', 'icon' => 'cpu'],
        ['key' => 'commerce', 'label' => 'Commerce', 'icon' => 'chart-column'],
        ['key' => 'diploma', 'label' => 'Diploma', 'icon' => 'award'],
        ['key' => 'certificate', 'label' => 'Certificate', 'icon' => 'badge-check'],
    ];
}

/**
 * Program explorer (island ProgramExplorer): category pills/tiles + search + animated filtering of program cards.
 * Fallback: the same pills wired to the vanilla data-filter behaviour + all program cards (fully crawlable).
 *   echo program_explorer(db_all("SELECT * FROM programs WHERE status='active' AND show_on_website=1 ORDER BY sort_order"),
 *        ['layout' => 'pills', 'viewAll' => ['label' => 'View All Programs', 'url' => site_url('programs')]]);
 * @param array $props categories (default program_categories()), layout (pills|tiles), search (bool), syncParam,
 *                     gridClass, viewAll, emptyText, initial
 */
function program_explorer(array $programs, array $props = []): string
{
    static $n = 0;
    $group = 'programs-' . (++$n);
    $props['categories'] = $props['categories'] ?? program_categories();
    if (!empty($props['viewAll']['url'])) {
        $props['viewAll']['url'] = cms_link($props['viewAll']['url']);
    }
    $layout = $props['layout'] ?? 'pills';
    $grid = $props['gridClass'] ?? 'sm:grid-cols-2 lg:grid-cols-3';
    $pills = '';
    foreach ($props['categories'] as $i => $c) {
        $pills .= $layout === 'tiles'
            ? '<button type="button" class="filter-tile' . ($i === 0 ? ' is-active' : '') . '" data-filter="' . e($c['key']) . '" data-filter-group="' . $group . '" aria-pressed="' . ($i === 0 ? 'true' : 'false') . '">' . (!empty($c['icon']) ? '<span class="filter-tile-icon">' . icon($c['icon'], 'h-5 w-5') . '</span>' : '') . '<span class="text-sm font-semibold">' . e($c['label']) . '</span></button>'
            : '<button type="button" class="chip' . ($i === 0 ? ' chip-active' : '') . '" data-filter="' . e($c['key']) . '" data-filter-group="' . $group . '" aria-pressed="' . ($i === 0 ? 'true' : 'false') . '">' . (!empty($c['icon']) ? icon($c['icon'], 'h-4 w-4') : '') . e($c['label']) . '</button>';
    }
    $cards = '';
    foreach ($programs as $p) {
        $tokens = program_filter_tokens($p);
        $cards .= '<div class="h-full" data-key="' . e($p['slug'] ?? $p['id'] ?? '') . '" data-category="' . e($tokens) . '" data-search="' . e(trim(($p['name'] ?? '') . ' ' . ($p['short_name'] ?? '') . ' ' . ($p['category'] ?? '') . ' ' . program_level_label($p['level'] ?? ''))) . '" data-filter-item="all ' . e($tokens) . '" data-filter-group="' . $group . '">' . program_card($p) . '</div>';
    }
    $fallback = '<div class="program-explorer"><div class="mb-8 flex flex-col gap-4' . ($layout === 'pills' ? ' lg:flex-row lg:items-center lg:justify-between' : '') . '">'
        . '<div class="' . ($layout === 'tiles' ? 'grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8' : 'pill-row scrollbar-none') . '" role="toolbar" aria-label="Filter programs by category">' . $pills . '</div>'
        . (!empty($props['viewAll']) ? '<div class="flex items-center gap-3"><a href="' . e($props['viewAll']['url']) . '" class="btn btn-outline btn-sm hidden shrink-0 sm:inline-flex">' . e($props['viewAll']['label']) . icon('arrow-right', 'h-4 w-4') . '</a></div>' : '')
        . '</div><div class="grid gap-6 ' . e($grid) . '" data-slot="items">' . $cards . '</div></div>';
    return island('ProgramExplorer', $props, $fallback);
}

/**
 * Generic carousel (island Carousel) over server-rendered cards.
 *   echo carousel(array_map(fn ($p) => program_card($p), $programs), ['perView' => ['base' => 1.1, 'sm' => 2, 'lg' => 3], 'autoplay' => 6000, 'ariaLabel' => 'Featured programs']);
 * @param string[] $slides HTML of each slide (one root element each)
 * @param array    $props  ariaLabel, perView {base,sm,md,lg,xl}, gap (px), autoplay (ms), loop, arrows, dots
 */
function carousel(array $slides, array $props = []): string
{
    $pv = ($props['perView'] ?? []) + ['base' => 1.15, 'sm' => 2, 'lg' => 3];
    $style = '--gap:' . (int) ($props['gap'] ?? 24) . 'px;--pv-base:' . (float) $pv['base'] . ';--pv-sm:' . (float) ($pv['sm'] ?? $pv['base']) . ';--pv-md:' . (float) ($pv['md'] ?? $pv['sm'] ?? $pv['base'])
        . ';--pv-lg:' . (float) ($pv['lg'] ?? $pv['md'] ?? $pv['sm'] ?? $pv['base']) . ';--pv-xl:' . (float) ($pv['xl'] ?? $pv['lg'] ?? $pv['base']);
    $fallback = '<div class="carousel" role="region" aria-label="' . e($props['ariaLabel'] ?? 'Carousel') . '"><div class="carousel-track scrollbar-none" style="' . $style . '" data-slot="slides" tabindex="0">' . implode('', $slides) . '</div>'
        . ((($props['arrows'] ?? true) || ($props['dots'] ?? true)) && count($slides) > 1 ? '<div class="carousel-controls" aria-hidden="true"><span class="h-11"></span></div>' : '') . '</div>';
    return island('Carousel', $props, $fallback);
}

/**
 * Testimonial slider (island TestimonialSlider). Fallback: all quotes in the DOM (first visible) for SEO.
 * @param array $items [['name', 'quote', 'role', 'company', 'program', 'photo' (URL), 'rating' 1-5], …]
 * @param array $props autoplay (ms, default 7000), theme (light|dark), ariaLabel
 */
function testimonial_slider(array $items, array $props = []): string
{
    if (!$items) {
        return '';
    }
    $props['items'] = array_values($items);
    $dark = ($props['theme'] ?? 'light') === 'dark';
    $html = '<div class="testimonials' . ($dark ? ' testimonials-dark' : '') . '"><div class="testimonial-stage">';
    foreach ($props['items'] as $i => $t) {
        $who = trim(implode(' · ', array_filter([$t['role'] ?? '', $t['company'] ?? '']))) ?: ($t['program'] ?? '');
        $html .= '<figure class="testimonial-card' . ($i === 0 ? ' is-active' : '') . '"' . ($i ? ' aria-hidden="true"' : '') . '>'
            . icon('quote', 'testimonial-quote-icon')
            . (!empty($t['rating']) ? '<p class="flex gap-0.5" aria-label="Rated ' . (int) $t['rating'] . ' out of 5">' . str_repeat(icon('star', 'h-4 w-4 fill-amber-400 text-amber-400'), max(0, min(5, (int) $t['rating']))) . '</p>' : '')
            . '<blockquote class="testimonial-text">“' . e($t['quote']) . '”</blockquote><figcaption class="mt-6 flex items-center gap-4">'
            . (!empty($t['photo']) ? '<img src="' . e($t['photo']) . '" alt="" loading="lazy" class="h-14 w-14 shrink-0 rounded-full object-cover ring-2 ring-white">' : '<span class="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-brand-600 to-accent-600 font-bold text-white">' . e(initials($t['name'])) . '</span>')
            . '<span><span class="testimonial-name">' . e($t['name']) . '</span><span class="testimonial-role">' . e($who) . '</span></span></figcaption></figure>';
    }
    $html .= '</div>' . (count($items) > 1 ? '<div class="testimonial-nav" aria-hidden="true"><span class="h-11"></span></div>' : '') . '</div>';
    return island('TestimonialSlider', $props, $html);
}

/**
 * Recruiter / partner logo marquee (island LogoMarquee). Fallback is a working CSS-only marquee.
 * @param array $items [['name', 'logo' (URL, optional), 'url', 'industry'], …]
 * @param array $props rows (1|2), speed (px/s), grayscale (bool), ariaLabel
 */
function logo_marquee(array $items, array $props = []): string
{
    if (!$items) {
        return '';
    }
    $props['items'] = array_values($items);
    $rows = $props['rows'] ?? (count($items) >= 8 ? 2 : 1);
    $groups = $rows === 2 ? array_chunk($props['items'], (int) ceil(count($items) / 2)) : [$props['items']];
    $tints = ['bg-brand-50 text-brand-800', 'bg-accent-50 text-accent-700', 'bg-cyan-50 text-cyan-700', 'bg-amber-50 text-amber-700', 'bg-violet-50 text-violet-700', 'bg-rose-50 text-rose-700'];
    $tile = function (array $it, int $i, bool $hidden) use ($tints): string {
        $inner = !empty($it['logo'])
            ? '<img src="' . e($it['logo']) . '" alt="' . ($hidden ? '' : e($it['name'])) . '" loading="lazy" class="h-9 w-auto max-w-[140px] object-contain">'
            : '<span class="inline-flex h-9 w-9 items-center justify-center rounded-lg font-display text-sm font-extrabold ' . $tints[$i % count($tints)] . '" aria-hidden="true">' . e(strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $it['name']), 0, 2))) . '</span><span class="font-display text-lg font-extrabold tracking-tight text-slate-700">' . e($it['name']) . '</span>';
        return '<li><span class="logo-tile">' . $inner . '</span></li>';
    };
    $html = '<div class="logo-marquee' . (($props['grayscale'] ?? true) ? ' logo-grayscale' : '') . '" role="region" aria-label="' . e($props['ariaLabel'] ?? 'Our recruiters') . '">';
    foreach ($groups as $g => $list) {
        $a = '';
        $b = '';
        foreach ($list as $i => $it) {
            $a .= $tile($it, $i, false);
            $b .= $tile($it, $i, true);
        }
        $html .= '<div class="marquee marquee-fade' . ($g === 1 ? ' marquee-reverse' : '') . '"><div class="marquee-track"><ul class="marquee-group">' . $a . '</ul><ul class="marquee-group" aria-hidden="true">' . $b . '</ul></div></div>';
    }
    return island('LogoMarquee', $props, $html . '</div>');
}

/**
 * Animated statistics (island StatCounter): count-up numbers with cards / progress rings / bars.
 * @param array $items [['value' => 95, 'suffix' => '%', 'label' => 'Placement Rate', 'icon' => 'trending-up', 'progress' => 95, 'caption' => '…'], …]
 * @param array $props variant (cards|rings|bars|inline), theme (light|dark), gridClass
 */
function stat_counter(array $items, array $props = []): string
{
    $props['items'] = array_values($items);
    $variant = $props['variant'] ?? 'cards';
    $grid = $props['gridClass'] ?? ($variant === 'bars' ? 'sm:grid-cols-2' : 'grid-cols-2 lg:grid-cols-4');
    $tints = ['tint-blue', 'tint-green', 'tint-cyan', 'tint-amber', 'tint-violet', 'tint-rose'];
    $html = '<ul class="stat-group grid gap-5 ' . e($grid) . (($props['theme'] ?? '') === 'dark' ? ' stat-dark' : '') . ' is-on">';
    foreach ($props['items'] as $i => $s) {
        $val = e(($s['prefix'] ?? '') . number_format((float) $s['value'], (int) ($s['decimals'] ?? 0)) . ($s['suffix'] ?? ''));
        $html .= '<li class="stat-item stat-' . e($variant) . '">';
        if ($variant === 'rings') {
            $pct = max(0, min(100, (float) ($s['progress'] ?? (str_contains($s['suffix'] ?? '', '%') ? $s['value'] : 100))));
            $html .= '<span class="stat-ring"><svg viewBox="0 0 120 120" aria-hidden="true"><circle cx="60" cy="60" r="52" class="stat-ring-track"/><circle cx="60" cy="60" r="52" pathLength="100" class="stat-ring-fill" style="stroke-dashoffset:' . (100 - $pct) . '"/></svg><span class="stat-ring-value">' . $val . '</span></span><span class="stat-label">' . e($s['label']) . '</span>';
        } elseif ($variant === 'bars') {
            $pct = max(0, min(100, (float) ($s['progress'] ?? 100)));
            $html .= '<span class="flex items-baseline justify-between gap-3"><span class="stat-label">' . e($s['label']) . '</span><span class="stat-value text-2xl">' . $val . '</span></span><span class="progress-track mt-3"><span class="progress-fill" style="--progress:' . ($pct / 100) . '"></span></span>';
        } else {
            $html .= ($variant === 'cards' && !empty($s['icon']) ? '<span class="stat-icon ' . $tints[$i % 6] . '">' . icon($s['icon'], 'h-6 w-6') . '</span>' : '')
                . '<span><span class="stat-value">' . $val . '</span><span class="stat-label">' . e($s['label']) . '</span></span>';
        }
        $html .= (!empty($s['caption']) && $variant !== 'cards' && $variant !== 'inline' ? '<span class="stat-caption">' . e($s['caption']) . '</span>' : '') . '</li>';
    }
    return island('StatCounter', $props, $html . '</ul>');
}

/**
 * Rotating typed phrases (island TypingText, inline). Fallback = the first phrase.
 *   <h2>Build a career in <?= typing_text(['Management', 'Technology', 'Commerce']) ?></h2>
 * @param array $props prefix, suffix, typeSpeed, deleteSpeed, pause, loop, dots (bool), textClass
 */
function typing_text(array $phrases, array $props = []): string
{
    $props['phrases'] = array_values($phrases);
    $text = $props['textClass'] ?? 'text-accent-600';
    return island('TypingText', $props, e($props['prefix'] ?? '') . '<span class="' . e($text) . '">' . e($phrases[0] ?? '') . '</span>' . e($props['suffix'] ?? ''), ['tag' => 'span']);
}

/**
 * FAQ accordion (island Accordion). Fallback = native <details> enhanced by the vanilla accordion.
 * @param array $items [['q' => 'Question', 'a' => "Answer\n\nSecond paragraph", 'category' => 'Admissions'], …]
 * @param array $props mode (single|multi), defaultOpen (index, -1 none), search (bool), categories (bool), columns (1|2), theme
 */
function faq_accordion(array $items, array $props = []): string
{
    $props['items'] = array_values(array_map(fn ($i) => ['q' => (string) ($i['q'] ?? $i['question'] ?? ''), 'a' => (string) ($i['a'] ?? $i['answer'] ?? '')] + (isset($i['category']) ? ['category' => (string) $i['category']] : []), $items));
    $cols = (int) ($props['columns'] ?? 1);
    $open = (int) ($props['defaultOpen'] ?? 0);
    $list = $props['items'];
    $groups = $cols === 2 ? array_chunk($list, (int) ceil(count($list) / 2), true) : [$list];
    $html = '<div class="faq"><div class="grid gap-4' . ($cols === 2 ? ' lg:grid-cols-2' : '') . '" data-accordion="' . (($props['mode'] ?? 'single') === 'multi' ? 'multi' : 'single') . '">';
    foreach ($groups as $g) {
        $html .= '<div class="space-y-3">';
        foreach ($g as $idx => $it) {
            $html .= '<details class="acc-item"' . ($idx === $open ? ' open' : '') . '><summary>' . e($it['q']) . '</summary><div>';
            foreach (preg_split('/\n{2,}/', $it['a']) as $para) {
                $html .= '<p class="mb-3 last:mb-0">' . nl2br(e($para)) . '</p>';
            }
            $html .= '</div></details>';
        }
        $html .= '</div>';
    }
    return island('Accordion', $props, $html . '</div></div>');
}

/**
 * Accessible tabs (island Tabs) over server-rendered panels.
 *   echo content_tabs([['key' => 'ug', 'label' => 'Undergraduate', 'icon' => 'graduation-cap'], …], [$ugHtml, $pgHtml]);
 * @param array    $tabs   [['key', 'label', 'icon'], …]
 * @param string[] $panels HTML per tab (same order)
 * @param array    $props  variant (pills|underline), initial, syncHash (bool), center (bool)
 */
function content_tabs(array $tabs, array $panels, array $props = []): string
{
    $props['tabs'] = array_values($tabs);
    $variant = $props['variant'] ?? 'pills';
    $html = '<div class="tabs-island tabs-' . e($variant) . '"><div class="tabs-scroll scrollbar-none' . (!empty($props['center']) ? ' justify-center' : '') . '"><div class="tabs-list" role="tablist">';
    foreach ($props['tabs'] as $i => $t) {
        $html .= '<span class="tabs-tab' . ($i === 0 ? ' is-active' : '') . '"' . ($i === 0 && $variant === 'pills' ? ' style="background:#0B2A5B;color:#fff"' : '') . '>' . (!empty($t['icon']) ? icon($t['icon'], 'h-4 w-4') : '') . e($t['label']) . '</span>';
    }
    $html .= '</div></div><div data-slot="panels">';
    foreach (array_values($panels) as $i => $p) {
        $html .= '<div' . ($i ? ' class="tab-fallback-hidden"' : '') . '>' . $p . '</div>';
    }
    return island('Tabs', $props, $html . '</div></div>');
}

/**
 * Video play trigger + modal (island VideoLightbox). Fallback: a link that the vanilla lightbox opens.
 * @param array $props url (YouTube/Vimeo/MP4), title, label, sublabel, poster (URL), variant (thumb|button|chip), aspect, className
 */
function video_lightbox(array $props): string
{
    $variant = $props['variant'] ?? 'thumb';
    $label = $props['label'] ?? 'Watch video';
    $sub = !empty($props['sublabel']) ? '<span class="block text-xs opacity-80">' . e($props['sublabel']) . '</span>' : '';
    if ($variant === 'thumb') {
        $inner = (!empty($props['poster']) ? '<img src="' . e($props['poster']) . '" alt="" loading="lazy" class="h-full w-full object-cover">' : '')
            . '<span class="video-thumb-overlay"></span><span class="video-thumb-center"><span class="play-pulse play-pulse-lg">' . icon('play', 'h-7 w-7 translate-x-0.5') . '</span></span>'
            . '<span class="video-thumb-label glass-dark"><span class="block text-sm font-semibold">' . e($label) . '</span>' . $sub . '</span>';
        $cls = 'video-thumb group img-zoom ' . ($props['aspect'] ?? 'aspect-video') . ' ' . ($props['className'] ?? '');
    } else {
        $inner = '<span class="play-pulse' . ($variant === 'chip' ? ' play-pulse-sm' : '') . '">' . icon('play', $variant === 'chip' ? 'h-3.5 w-3.5' : 'h-5 w-5') . '</span><span class="text-left"><span class="block text-sm font-semibold">' . e($label) . '</span>' . $sub . '</span>';
        $cls = ($variant === 'chip' ? 'video-chip glass' : 'video-button') . ' ' . ($props['className'] ?? '');
    }
    $fallback = '<a href="' . e($props['url'] ?? '#') . '" data-lightbox data-caption="' . e($props['title'] ?? $label) . '" class="' . e(trim($cls)) . '">' . $inner . '</a>';
    return island('VideoLightbox', $props, $fallback, ['class' => $variant === 'thumb' ? 'block' : 'inline-block']);
}

/**
 * Photo gallery with lightbox (island GalleryLightbox). Fallback: thumbnail grid opening the vanilla lightbox.
 * @param array $items [['src' => URL, 'thumb' => URL, 'alt' => '…', 'caption' => '…', 'category' => '…'], …]
 * @param array $props columns (2|3|4), layout (grid|masonry|bento), filter (bool), ariaLabel
 */
function gallery_grid(array $items, array $props = []): string
{
    static $n = 0;
    $props['items'] = array_values($items);
    $group = 'gallery-' . (++$n);
    $cols = (int) ($props['columns'] ?? 3);
    $colCls = [2 => 'sm:grid-cols-2', 3 => 'sm:grid-cols-2 lg:grid-cols-3', 4 => 'sm:grid-cols-3 lg:grid-cols-4'][$cols] ?? 'sm:grid-cols-2 lg:grid-cols-3';
    $bento = ($props['layout'] ?? 'grid') === 'bento';
    $html = '<ul class="grid grid-cols-2 gap-3 sm:gap-4 ' . $colCls . ($bento ? ' gallery-bento' : '') . '">';
    foreach ($props['items'] as $it) {
        $html .= '<li><a href="' . e($it['src']) . '" data-lightbox="' . $group . '" data-caption="' . e($it['caption'] ?? $it['alt'] ?? '') . '" class="gallery-tile img-zoom group aspect-[4/3]"><img src="' . e($it['thumb'] ?? $it['src']) . '" alt="' . e($it['alt'] ?? '') . '" loading="lazy" class="h-full w-full object-cover"></a></li>';
    }
    return island('GalleryLightbox', $props, $html . '</ul>');
}

/**
 * AJAX enquiry / counselling / contact form (island EnquiryForm). Fallback: an equivalent <form data-ajax-form> that
 * the vanilla form handler submits (and that posts normally without JS, with the _csrf field).
 *   echo enquiry_form(['endpoint' => base_url('api/public/enquiry'), 'title' => 'Need Help Choosing a Program?',
 *     'theme' => 'dark', 'compact' => true, 'submitLabel' => 'Get Free Counselling', 'hidden' => ['source' => 'programs-page'],
 *     'fields' => [['name' => 'name', 'label' => 'Full Name', 'required' => true, 'maxLength' => 150],
 *                  ['name' => 'phone', 'label' => 'Phone Number', 'type' => 'tel', 'required' => true],
 *                  ['name' => 'email', 'label' => 'Email Address', 'type' => 'email', 'required' => true],
 *                  ['name' => 'program', 'label' => 'Program Interest', 'type' => 'select', 'options' => ['MBA', 'BBA']]]]);
 * Field keys: name, label, type (text|email|tel|number|date|select|textarea|checkbox), required, placeholder, options
 * (strings or {value,label}), col (full|half), minLength, maxLength, min, max, pattern (+patternMessage), rows, help.
 */
function enquiry_form(array $props): string
{
    $theme = $props['theme'] ?? 'light';
    $compact = !empty($props['compact']);
    $html = '<div class="enquiry enquiry-' . e($theme) . ($compact ? ' enquiry-compact' : '') . '">';
    if (!empty($props['title']) || !empty($props['icon'])) {
        $html .= '<div class="mb-5' . ($theme === 'dark' ? ' text-center' : '') . '">' . (!empty($props['icon']) ? '<span class="enquiry-icon">' . icon($props['icon'], 'h-7 w-7') . '</span>' : '')
            . (!empty($props['title']) ? '<h3 class="enquiry-title">' . e($props['title']) . '</h3>' : '') . (!empty($props['subtitle']) ? '<p class="enquiry-subtitle">' . e($props['subtitle']) . '</p>' : '') . '</div>';
    }
    $html .= '<form action="' . e($props['endpoint'] ?? '') . '" method="post" data-ajax-form data-success="' . e($props['successMessage'] ?? 'Thank you! We will contact you shortly.') . '" class="grid grid-cols-2 gap-3.5">' . csrf_field()
        . '<input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">';
    foreach (($props['hidden'] ?? []) as $k => $v) {
        $html .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    foreach (($props['fields'] ?? []) as $f) {
        $id = 'ef-' . substr(md5(json_encode($props['fields']) . $f['name']), 0, 6) . '-' . e($f['name']);
        $req = !empty($f['required']);
        $label = '<label for="' . $id . '" class="form-label' . ($compact ? ' sr-only' : '') . '">' . e($f['label']) . ($req ? '<span class="form-required"> *</span>' : '') . '</label>';
        $ph = $f['placeholder'] ?? ($compact ? $f['label'] . ($req ? ' *' : '') : '');
        $common = 'id="' . $id . '" name="' . e($f['name']) . '"' . ($req ? ' required' : '') . (!empty($f['maxLength']) ? ' maxlength="' . (int) $f['maxLength'] . '"' : '');
        $type = $f['type'] ?? 'text';
        $html .= '<div class="' . (($f['col'] ?? 'full') === 'half' ? 'col-span-2 sm:col-span-1' : 'col-span-2') . '">';
        if ($type === 'select') {
            $opts = '<option value="">' . e($f['placeholder'] ?? 'Select ' . strtolower($f['label'])) . '</option>';
            foreach (($f['options'] ?? []) as $o) {
                $o = is_array($o) ? $o : ['value' => $o, 'label' => $o];
                $opts .= '<option value="' . e($o['value']) . '">' . e($o['label']) . '</option>';
            }
            $html .= $label . '<select ' . $common . ' class="form-input">' . $opts . '</select>';
        } elseif ($type === 'textarea') {
            $html .= $label . '<textarea ' . $common . ' rows="' . (int) ($f['rows'] ?? 4) . '" class="form-input" placeholder="' . e($ph) . '"></textarea>';
        } elseif ($type === 'checkbox') {
            $html .= '<div class="flex items-start gap-2.5"><input ' . $common . ' type="checkbox" value="1" class="form-check mt-0.5"><label for="' . $id . '" class="text-sm">' . e($f['label']) . '</label></div>';
        } else {
            $html .= $label . '<input ' . $common . ' type="' . e($type) . '" class="form-input" placeholder="' . e($ph) . '">';
        }
        $html .= '<p class="form-error" data-error-for="' . e($f['name']) . '"></p></div>';
    }
    if (!empty($props['consent'])) {
        $html .= '<div class="col-span-2 flex items-start gap-2.5"><input id="ef-consent" name="consent" type="checkbox" value="1" required class="form-check mt-0.5"><label for="ef-consent" class="text-sm">' . e($props['consent']) . '</label></div>';
    }
    $html .= '<div class="col-span-2 pt-1"><button type="submit" class="btn btn-accent btn-shine w-full">' . e($props['submitLabel'] ?? 'Submit Enquiry') . icon('arrow-right', 'h-4 w-4') . '</button><p class="form-message" data-form-message></p></div></form></div>';
    return island('EnquiryForm', $props, $html);
}

/**
 * Skeleton placeholders that resolve into cards fetched from a JSON endpoint (island SkeletonList).
 * @param array $props endpoint (GET URL returning api_ok(items[])), count, variant (card|list|event), gridClass,
 *                     emptyText, viewAll {label,url}
 */
function skeleton_list(array $props): string
{
    $count = (int) ($props['count'] ?? 3);
    $variant = $props['variant'] ?? 'card';
    $one = $variant === 'card'
        ? '<div class="overflow-hidden rounded-2xl border border-slate-100 bg-white" aria-hidden="true"><span class="skeleton block aspect-[16/10]"></span><span class="block space-y-3 p-5"><span class="skeleton block h-3 w-1/3 rounded"></span><span class="skeleton block h-5 w-11/12 rounded"></span><span class="skeleton block h-3 w-full rounded"></span><span class="skeleton block h-3 w-2/3 rounded"></span></span></div>'
        : '<div class="flex gap-4 rounded-2xl border border-slate-100 bg-white p-4" aria-hidden="true"><span class="skeleton h-16 w-16 shrink-0 rounded-xl"></span><span class="flex-1 space-y-2.5 py-1"><span class="skeleton block h-3 w-1/4 rounded"></span><span class="skeleton block h-4 w-4/5 rounded"></span><span class="skeleton block h-3 w-1/2 rounded"></span></span></div>';
    $grid = $variant === 'card' ? 'grid gap-6 ' . ($props['gridClass'] ?? 'sm:grid-cols-2 lg:grid-cols-3') : 'grid gap-3';
    $fallback = '<div class="' . e($grid) . '" role="status" aria-busy="true"><span class="sr-only">Loading…</span>' . str_repeat($one, max(1, $count)) . '</div>'
        . (!empty($props['viewAll']) ? '<noscript><a href="' . e(cms_link($props['viewAll']['url'])) . '" class="link-arrow mt-6">' . e($props['viewAll']['label']) . '</a></noscript>' : '');
    if (!empty($props['viewAll']['url'])) {
        $props['viewAll']['url'] = cms_link($props['viewAll']['url']);
    }
    return island('SkeletonList', $props, $fallback);
}

/**
 * Auto-scrolling list of cards (island AutoScrollCards) — events, notices, news tickers.
 * @param string[] $items HTML of each card
 * @param array    $props direction (vertical|horizontal), speed (px/s), height (px, vertical), ariaLabel
 */
function auto_scroll_cards(array $items, array $props = []): string
{
    $vertical = ($props['direction'] ?? 'vertical') === 'vertical';
    $fallback = '<div class="autoscroll ' . ($vertical ? 'autoscroll-y' : 'autoscroll-x') . ' is-static" style="' . ($vertical ? 'height:' . (int) ($props['height'] ?? 420) . 'px' : '') . '" role="region" aria-label="' . e($props['ariaLabel'] ?? 'Latest updates') . '">'
        . '<div class="autoscroll-track" data-slot="items">' . implode('', $items) . '</div></div>';
    return island('AutoScrollCards', $props, $fallback);
}

/**
 * Animated journey / timeline (island ProgressSteps), e.g. the 5-step admission process.
 * @param array $steps [['title' => 'Explore Programs', 'text' => '…', 'icon' => 'search'], …]
 * @param array $props orientation (auto|horizontal|vertical), cta {label,url}, theme (light|dark)
 */
function progress_steps(array $steps, array $props = []): string
{
    $props['steps'] = array_values($steps);
    if (!empty($props['cta']['url'])) {
        $props['cta']['url'] = cms_link($props['cta']['url']);
    }
    $orientation = $props['orientation'] ?? 'auto';
    $html = '<div class="steps ' . ($orientation === 'horizontal' ? 'steps-h' : ($orientation === 'vertical' ? 'steps-v' : 'steps-auto')) . (($props['theme'] ?? '') === 'dark' ? ' steps-dark' : '') . '"><div class="steps-track" style="--n:' . count($steps) . '"><span class="steps-line" aria-hidden="true"><span class="steps-line-fill" style="transform:scale(1)"></span></span><ol class="steps-list">';
    foreach ($props['steps'] as $i => $s) {
        $html .= '<li class="step is-reached"><span class="step-icon">' . (!empty($s['icon']) ? icon($s['icon'], 'h-6 w-6') : '<span class="font-display text-lg font-extrabold">' . ($i + 1) . '</span>') . '</span>'
            . '<span class="step-body"><span class="step-num">' . sprintf('%02d', $i + 1) . '</span><span class="step-title">' . e($s['title']) . '</span>' . (!empty($s['text']) ? '<span class="step-text">' . e($s['text']) . '</span>' : '') . '</span></li>';
    }
    $html .= '</ol></div>' . (!empty($props['cta']) ? '<a href="' . e($props['cta']['url']) . '" class="btn btn-accent btn-shine mt-8">' . e($props['cta']['label']) . icon('arrow-right', 'h-4 w-4') . '</a>' : '') . '</div>';
    return island('ProgressSteps', $props, $html);
}
