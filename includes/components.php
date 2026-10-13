<?php
/**
 * Website render helpers (public pages) — "Design v3" (deep navy + orange-red + warm greys, Plus Jakarta Sans / Inter;
 * docs/DESIGN.md). Required by
 * includes/config.php.
 *
 * Three families:
 *  1. Plain server-rendered building blocks that return HTML strings:
 *       reveal_attr(), section_open()/section_close(), button_link(), program_card(), dept_chip(), feature_tile(),
 *       stat_card(), cta_band(), overlay_card(), check_list(), glass_chip()
 *  2. Design v3 decorations + floating widgets: scribble_text()/scribble() (hand-drawn ellipse around a word),
 *       geo_shapes() (floating 2D shapes), blob() (morphing SVG blob), doodle() (original SVGs: swirl, rings, arrows …),
 *       emblem() (generic approval emblems), sticker(), ribbon_label(), pastel_card(), icon_circle(), approvals_card(),
 *       approval_card(), announcement_strip() (ticker), admission_bar(), mobile_cta_bar(), whatsapp_button(),
 *       enquiry_tab() + enquiry_modal(), back_to_top()
 *  3. React island wrappers — each renders a meaningful SEO/no-JS fallback (same layout as the component, so no layout
 *     shift) and the island placeholder: hero_slider(), program_explorer(), carousel(), testimonial_slider(),
 *     logo_marquee(), stat_counter(), typing_text(), faq_accordion(), content_tabs(), video_lightbox(), gallery_grid(),
 *     enquiry_form(), skeleton_list(), auto_scroll_cards(), progress_steps(), countdown()
 *
 * All text arguments are plain text (escaped here). Arguments documented as "HTML" must already be safe.
 * Reference + live examples: docs/WEBSITE.md and /site-kit.php (dev only).
 *
 * Tailwind keeps component classes only when it sees them literally; the helpers build some names dynamically:
 *   btn-primary btn-accent btn-orange btn-ink btn-navy btn-secondary btn-soft btn-outline btn-outline-light btn-white
 *   btn-ghost btn-sm btn-lg tint-blue tint-green tint-cyan tint-amber tint-violet tint-rose tint-navy tint-ink
 *   tint-orange tint-sky tint-aqua tint-sun tint-lavender tint-blush tint-pistachio tint-sand tint-cream
 *   pattern-dots-light pattern-grid-light dept-0 dept-1 dept-2 dept-3 dept-4 dept-5 pastel-lavender pastel-sky
 *   pastel-aqua pastel-cream pastel-sand pastel-blush pastel-pistachio pastel-sun sticker-sun sticker-sky sticker-blush
 *   sticker-lavender sticker-pistachio sticker-white stat-cards stat-rings stat-bars stat-inline stat-divided
 *   enquiry-light enquiry-dark enquiry-glass enquiry-lead enquiry-plain scribble-light scribble-ink
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
 *   echo section_heading('Explore Our Programs', 'Choose from …', 'Academics', 'left', '', ['highlight' => 'Programs']);
 *   …
 *   echo section_close();
 *
 * @param array $o id, bg (white|light|surface|cream|sand|ink|navy|gradient|brand-soft|paper),
 *                 pattern (dots|grid|true), padding (normal|tight|none), class (extra classes), container (bool,
 *                 default true), label (aria-label), reveal (bool: fade the section content in)
 *                 (navy = ink band with a subtle grid since Design v2; gradient = animated ink/ember gradient)
 */
function section_open(array $o = []): string
{
    $bgKey = $o['bg'] ?? 'white';
    $bg = [
        'white' => 'bg-white',
        'light' => 'section-light',
        'surface' => 'section-surface',
        'cream' => 'section-cream',
        'sand' => 'section-sand',
        'paper' => 'bg-grid-paper',
        'ink' => 'section-ink bg-grid-ink',
        'navy' => 'section-navy bg-grid-ink',
        'gradient' => 'section-navy bg-animated-gradient',
        'brand-soft' => 'bg-gradient-to-b from-brand-50 to-white',
    ][$bgKey] ?? 'bg-white';
    $dark = in_array($bgKey, ['navy', 'gradient', 'ink'], true);
    $pattern = $o['pattern'] ?? false;
    $patternLayer = $pattern ? '<div class="pattern-layer ' . ($pattern === 'grid' ? 'pattern-grid' : 'pattern-dots') . ($dark ? '-light' : '') . ' pattern-fade animate-pattern-drift" aria-hidden="true"></div>' : '';
    $pad = ['normal' => 'section', 'tight' => 'section-tight', 'none' => ''][$o['padding'] ?? 'normal'] ?? 'section';
    $cls = trim("relative isolate $pad $bg " . ($dark ? 'on-dark ' : '') . ($o['class'] ?? ''));
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
 * Pill button link — Design v3 CTA button system (use these consistently site-wide):
 *   button_link('Apply for Admission', '/apply', 'apply')              primary filled orange-red (#D03E16)
 *   button_link('Explore Courses', '/programs', 'explore')             secondary navy filled
 *   button_link('Download Brochure', '/programs?brochure=1', 'brochure', ['icon_left' => 'download'])   navy outline
 *   button_link('Request a Callback', '#', 'callback', ['attrs' => ['data-modal-open' => '#enquiry-modal']])  soft tint
 * Aliases: accent | orange = apply · primary | navy | ink = explore · outline = brochure · soft = callback; plus
 *          secondary (white, hairline) · outline-light (white outline on navy) · white · ghost.
 * @param array $o icon (trailing lucide name, default 'arrow-right' for apply/accent/orange/explore/primary/ink/navy, '' for none),
 *                 icon_left, size (sm|lg), magnetic (bool|float), shine (bool, light streak — primary CTAs), attention
 *                 (bool, idle glow pulse — the single main hero CTA only), class, target, attrs (array)
 * Every .btn gets the colour-overlay sweep on hover / focus / touch (see "CTA buttons" in assets/css/src/style.css).
 */
function button_link(string $label, string $url, string $variant = 'primary', array $o = []): string
{
    $defaultIcon = in_array($variant, ['apply', 'accent', 'orange', 'explore', 'primary', 'ink', 'navy'], true) ? 'arrow-right' : '';
    $iconR = $o['icon'] ?? $defaultIcon;
    $cls = 'btn btn-' . $variant . (!empty($o['size']) ? ' btn-' . $o['size'] : '') . (!empty($o['shine']) ? ' btn-shine' : '') . (!empty($o['attention']) ? ' btn-attention' : '') . (!empty($o['class']) ? ' ' . $o['class'] : '');
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
 * Department chip for a program row (department from site_programs(), else the category), coloured with the
 * department hue from the colour map (site_hues() / dept_hue()).
 */
function dept_chip(array $p, string $class = ''): string
{
    $label = (string) ($p['department_label'] ?? '') ?: (string) ($p['category'] ?? '') ?: program_level_label($p['level'] ?? '');
    if ($label === '') {
        return '';
    }
    return '<span class="dept-chip ' . dept_hue($p) . ($class !== '' ? ' ' . e($class) : '') . '" title="' . e($label) . '">' . e($label) . '</span>';
}

/**
 * Program card (Design v2): light-grey rounded card, rounded photo with a yellow "Most Popular"/"Trending" tag (only
 * for programs flagged is_popular / is_featured in the DB) and a level pill, pastel department chip overlapping the
 * photo, title, meta (duration · level · fee — no ratings), actions and a black short-name tag in the bottom-right
 * corner. $p is a `programs` row (ideally from site_programs(), which adds the department).
 * $o: reveal (effect or false), delay (ms), ribbon (override tag text; '' = none), heading (h2|h3), variant
 *     ('default' | 'landing' = centred Apply Now pill, eligibility line), summary (bool, default true), actions (bool,
 *     default true), apply_url, details_label.
 */
function program_card(array $p, array $o = []): string
{
    static $depts = null;
    if (!isset($p['department_label']) && isset($p['id'])) {
        $depts ??= array_column(site_programs(), null, 'id');
        if (isset($depts[$p['id']])) {
            $p += ['department_label' => $depts[$p['id']]['department_label'], 'dept_index' => $depts[$p['id']]['dept_index']];
        }
    }
    $variant = $o['variant'] ?? 'default';
    $url = site_url('programs/' . ($p['slug'] ?? ''));
    $apply = $o['apply_url'] ?? site_url('apply?program=' . rawurlencode((string) ($p['slug'] ?? '')));
    $ribbon = $o['ribbon'] ?? (!empty($p['is_popular']) ? 'Most Popular' : (!empty($p['is_featured']) && $variant === 'landing' ? 'Trending' : ''));
    $h = $o['heading'] ?? 'h3';
    $fee = isset($p['fee_per_year']) && (float) $p['fee_per_year'] > 0 ? money($p['fee_per_year']) . ' ' . ($p['fee_label'] ?? '/ Year') : '';
    $reveal = ($o['reveal'] ?? false) ? reveal_attr((string) $o['reveal'], (int) ($o['delay'] ?? 0)) : '';
    $short = (string) ($p['short_name'] ?? '');
    $html = '<article class="program-card group ' . dept_hue($p) . ($variant === 'landing' ? ' program-card-landing' : '') . '" data-tilt="4"' . $reveal . '>';
    $html .= '<a href="' . e($url) . '" class="program-card-media img-zoom" tabindex="-1" aria-hidden="true">'
        . '<img src="' . e(site_image($p['image'] ?? null)) . '" alt="" loading="lazy" decoding="async" width="640" height="400">'
        . '<span class="program-tags">' . ($ribbon !== '' ? '<span class="program-ribbon">' . e($ribbon) . '</span>' : '')
        . '<span class="program-level">' . e(program_level_label($p['level'] ?? '')) . '</span></span></a>';
    $html .= dept_chip($p, 'program-chip');
    $html .= '<div class="program-card-body">';
    $html .= '<' . $h . ' class="program-card-title"><a href="' . e($url) . '" class="hover:text-accent-700">' . e($p['name'] ?? '') . (program_title_suffix($p) !== '' ? ' <span class="whitespace-nowrap">(' . e(program_title_suffix($p)) . ')</span>' : '') . '</a></' . $h . '>';
    $html .= '<ul class="program-meta">';
    if (!empty($p['duration_label'])) {
        $html .= '<li>' . icon('clock', '') . e($p['duration_label']) . ' · ' . e(program_level_label($p['level'] ?? '')) . '</li>';
    } else {
        $html .= '<li>' . icon('graduation-cap', '') . e(program_level_label($p['level'] ?? '')) . '</li>';
    }
    if ($fee !== '') {
        $html .= '<li>' . icon('wallet', '') . e($fee) . '</li>';
    }
    if ($variant === 'landing' && !empty($p['eligibility'])) {
        $html .= '<li class="!items-start">' . icon('graduation-cap', 'mt-0.5') . '<span class="line-clamp-2">' . e(str_limit(strip_tags((string) $p['eligibility']), 90)) . '</span></li>';
    }
    $html .= '</ul>';
    if (($o['summary'] ?? $variant === 'default') && !empty($p['overview'])) {
        $html .= '<p class="program-card-text">' . e(str_limit(strip_tags((string) $p['overview']), 130)) . '</p>';
    }
    if ($o['actions'] ?? true) {
        $html .= '<div class="program-card-actions">'
            . ($variant === 'landing'
                ? '<a href="' . e($apply) . '" class="btn btn-accent">Apply Now<span class="sr-only"> for ' . e($p['name'] ?? '') . '</span></a>'
                : '<a href="' . e($apply) . '" class="btn btn-accent btn-sm">Apply Now<span class="sr-only"> for ' . e($p['name'] ?? '') . '</span></a>'
                  . '<a href="' . e($url) . '" class="link-arrow text-[13.5px]">' . e($o['details_label'] ?? 'Details') . icon('arrow-right', 'h-4 w-4') . '<span class="sr-only"> about ' . e($p['name'] ?? '') . '</span></a>')
            . '</div>';
    } else {
        $html .= '<div class="h-6"></div>';
    }
    $html .= '</div>' . ($short !== '' ? '<span class="program-tag" aria-hidden="true">' . e($short) . '</span>' : '') . '</article>';
    return $html;
}

/**
 * Icon feature tile ("Why Choose GIMT" cards): white card with a gradient border on hover, tinted icon tile, bold title.
 * @param array $o tint (navy|orange — default navy; subtle accents sky|aqua|sun|lavender|blush|pistachio|sand|warm;
 *                 legacy blue|green|cyan|amber|violet|rose map onto them), variant (card|compact|plain|warm), url, reveal
 *                 (effect or false), delay (ms), heading (h3|h4)
 */
function feature_tile(string $icon, string $title, string $text = '', array $o = []): string
{
    $variant = $o['variant'] ?? 'card';
    $tint = 'tint-' . ($o['tint'] ?? 'navy');
    $h = $o['heading'] ?? 'h3';
    $cls = 'feature-tile group card-lift' . ($variant === 'compact' ? ' feature-tile-compact' : '') . ($variant === 'plain' ? ' feature-tile-plain' : '') . (in_array($variant, ['white', 'warm'], true) ? ' feature-tile-warm' : '');
    $reveal = ($o['reveal'] ?? false) ? reveal_attr((string) $o['reveal'], (int) ($o['delay'] ?? 0)) : '';
    $inner = '<span class="feature-tile-icon ' . $tint . '">' . icon($icon, $variant === 'compact' ? 'h-6 w-6' : 'h-6 w-6') . '</span>'
        . '<span class="min-w-0"><' . $h . ' class="feature-tile-title">' . (!empty($o['url']) ? '<a href="' . e(cms_link($o['url'])) . '" class="stretched-link">' . e($title) . '</a>' : e($title)) . '</' . $h . '>'
        . ($text !== '' ? '<p class="feature-tile-text">' . e($text) . '</p>' : '') . '</span>';
    return '<div class="' . $cls . '"' . $reveal . '>' . $inner . '</div>';
}

/**
 * Stat card with an animated counter (vanilla data-count; server renders the final value). Bold Jakarta number.
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
        . ($icon !== '' ? '<span class="stat-card-icon tint-' . e($o['tint'] ?? 'navy') . '">' . icon($icon, 'h-6 w-6') . '</span>' : '')
        . '<span><span class="stat-card-value" data-count="' . e((string) $num) . '"' . (isset($o['prefix']) ? ' data-prefix="' . e($o['prefix']) . '"' : '') . (isset($o['suffix']) ? ' data-suffix="' . e($o['suffix']) . '"' : '') . ($dec ? ' data-decimals="' . $dec . '"' : '') . '>' . e($shown) . '</span>'
        . '<span class="stat-card-label">' . e($label) . '</span></span></div>';
}

/**
 * Call-to-action band: navy mesh rounded card with a grid, geometric shapes, a morphing blob and a photo fading in from
 * the right, sitting on the warm-grey footer backdrop (the footer renders it by default).
 *   echo cta_band(['title' => 'Ready to Build a Brighter Future?', 'text' => '…', 'highlight' => 'Future',
 *                  'primary' => ['label' => 'Apply for Admission', 'url' => '/apply'],
 *                  'secondary' => ['label' => 'Download Brochure', 'url' => '/programs?brochure=1', 'icon' => 'download']]);
 * @param array $o title, text, primary, secondary, image (path), eyebrow, compact (bool), highlight (word to circle),
 *                 shell (bool, default true — cream backdrop wrapper; false = just the card, e.g. inside a section)
 */
function cta_band(array $o = []): string
{
    $img = site_image($o['image'] ?? 'assets/images/site/graduation.jpg');
    $title = (string) ($o['title'] ?? 'Ready to Build a Brighter Future?');
    $card = '<div class="cta-band-card"' . reveal_attr('fade-up') . '>';
    $card .= '<img src="' . e($img) . '" alt="" class="cta-band-media" loading="lazy" decoding="async"><div class="cta-band-bg"></div>';
    $card .= geo_shapes('band', 'hidden md:block') . blob('-left-24 -bottom-32 h-[420px] w-[420px]', 'flame');
    $card .= '<div class="relative flex flex-col items-start gap-7 px-6 ' . (!empty($o['compact']) ? 'py-9' : 'py-10 sm:px-12 sm:py-14') . ' md:max-w-[62%]">';
    $card .= '<div>' . (!empty($o['eyebrow']) ? '<p class="eyebrow mb-3 !text-accent-300">' . e($o['eyebrow']) . '</p>' : '')
        . '<h2 class="cta-band-title" data-text-reveal>' . scribble_text($title, !empty($o['highlight']) ? (string) $o['highlight'] : false, 'orange') . '</h2>'
        . (($o['text'] ?? '') !== '' ? '<p class="mt-3 max-w-xl text-white/75">' . e($o['text']) . '</p>' : '') . '</div>';
    $card .= '<div class="flex flex-wrap gap-3">';
    if (!empty($o['primary'])) {
        $card .= button_link($o['primary']['label'], $o['primary']['url'], 'apply', ['size' => 'lg', 'shine' => true, 'magnetic' => true, 'icon' => $o['primary']['icon'] ?? 'arrow-right']);
    }
    if (!empty($o['secondary'])) {
        $card .= button_link($o['secondary']['label'], $o['secondary']['url'], 'outline-light', ['size' => 'lg', 'icon' => '', 'icon_left' => $o['secondary']['icon'] ?? null]);
    }
    $card .= '</div></div></div>';
    if (($o['shell'] ?? true) === false) {
        return '<div class="cta-band">' . $card . '</div>';
    }
    return '<section class="cta-band cta-band-shell" aria-label="' . e($title) . '"><div class="container-site">' . $card . '</div></section>';
}

/** Image card with ink fade, bold Jakarta title, text and a white pill link (Campus Life / Placements / News & Events). */
function overlay_card(string $title, string $text, string $url, string $image, string $linkLabel = 'Explore More', array $o = []): string
{
    $reveal = ($o['reveal'] ?? false) ? reveal_attr((string) $o['reveal'], (int) ($o['delay'] ?? 0)) : '';
    return '<article class="overlay-card group"' . $reveal . '><img src="' . e(site_image($image)) . '" alt="" loading="lazy" decoding="async">'
        . '<h3 class="overlay-card-title">' . e($title) . '</h3><p class="overlay-card-text">' . e($text) . '</p>'
        . '<a href="' . e(cms_link($url)) . '" class="overlay-card-link stretched-link">' . e($linkLabel) . icon('arrow-right', 'h-4 w-4') . '</a></article>';
}

/** Bulleted list with orange check icons. */
function check_list(array $items, string $class = ''): string
{
    $html = '<ul class="check-list ' . e($class) . '">';
    foreach ($items as $item) {
        $html .= '<li>' . icon('circle-check', '') . '<span>' . e($item) . '</span></li>';
    }
    return $html . '</ul>';
}

/** Small chip (icon + label) for use over images or on light heroes. $variant: dark | light | white. */
function glass_chip(string $icon, string $label, string $variant = 'dark', string $class = ''): string
{
    $cls = ['dark' => 'glass-dark', 'light' => 'glass-light', 'white' => 'bg-white text-brand-900 shadow-soft'][$variant] ?? 'glass-dark';
    return '<span class="inline-flex items-center gap-2.5 rounded-full py-1.5 pl-1.5 pr-4 text-[13px] font-semibold ' . $cls . ' ' . e($class) . '">'
        . '<span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-accent-600 text-white">' . icon($icon, 'h-4 w-4') . '</span>' . e($label) . '</span>';
}

/* ============================================================================ Design v2 decorations */

/**
 * Hand-drawn orange-red ellipse around a word (inline span + SVG; draws in when scrolled into view, static for reduced
 * motion / no JS). $tone: orange (default) | light (lighter orange, for navy bands) | ink (navy).
 *   <h2 class="section-title">Top <?= scribble('Programs') ?></h2>
 */
function scribble(string $word, string $tone = 'orange'): string
{
    $tone = in_array($tone, ['orange', 'light', 'ink'], true) ? $tone : 'orange';
    return '<span class="scribble' . ($tone !== 'orange' ? ' scribble-' . $tone : '') . '" data-draw>' . e($word)
        . '<svg class="scribble-svg" viewBox="0 0 200 70" preserveAspectRatio="none" aria-hidden="true" focusable="false"><path pathLength="100" d="M176 10C134 1 52 3 22 19C1 31 5 53 39 62C83 73 161 67 187 49C205 36 196 16 158 9C138 5 116 4 97 6"/></svg></span>';
}

/**
 * Escape $text and circle one word/phrase of it with scribble(). $highlight: string (case-insensitive match; first
 * occurrence), null = the last word of 3+ letters, false = no scribble.
 */
function scribble_text(string $text, $highlight = null, string $tone = 'orange'): string
{
    if ($highlight === false || $highlight === '') {
        return e($text);
    }
    if ($highlight === null) {
        if (!preg_match('/([\p{L}][\p{L}\'’&.-]{2,})[^\p{L}]*$/u', $text, $m)) {
            return e($text);
        }
        $highlight = $m[1];
        $pos = mb_strrpos($text, $highlight);
    } else {
        $pos = mb_stripos($text, (string) $highlight);
    }
    if ($pos === false) {
        return e($text);
    }
    $len = mb_strlen((string) $highlight);
    return e(mb_substr($text, 0, $pos)) . scribble(mb_substr($text, $pos, $len), $tone) . e(mb_substr($text, $pos + $len));
}

/**
 * Original Design v2 doodle, inlined from assets/images/site/doodles/{name}.svg (uses currentColor — set a text
 * colour and a size on the wrapper). Names: sparkle, sparkle-outline, star-outline, arrow-loop, arrow-curve-down,
 * burst, squiggle, swirl, rings, scribble-ellipse, emblem-* (see emblem()).
 *   <span class="doodle twinkle h-8 w-8 text-accent-600"><?= doodle('sparkle') ?></span>
 */
function doodle(string $name, string $class = ''): string
{
    static $cache = [];
    if (!preg_match('/^[a-z0-9-]+$/', $name)) {
        return '';
    }
    if (!array_key_exists($name, $cache)) {
        $file = APP_ROOT . '/assets/images/site/doodles/' . $name . '.svg';
        $cache[$name] = is_file($file) ? trim((string) file_get_contents($file)) : '';
    }
    $svg = $cache[$name];
    if ($svg === '' || $class === '') {
        return $svg;
    }
    return preg_replace('/^<svg /', '<svg class="' . e($class) . '" ', $svg, 1);
}

/** Generic approval / accreditation emblem (original artwork, not a real logo): seal | laurel | shield | pillars | globe | book | medal. */
function emblem(string $name, string $class = ''): string
{
    $name = in_array($name, ['seal', 'laurel', 'shield', 'pillars', 'globe', 'book', 'medal'], true) ? $name : 'seal';
    return doodle('emblem-' . $name, $class);
}

/**
 * Rotated sticker tag ("Learn · Grow · Succeed", "Thriving Mindset").
 * @param array $o color (sun|sky|blush|lavender|pistachio|white, default sun), rotate (deg, default -10), wiggle (bool,
 *                 gentle sway), size (sm|md), class, icon (lucide name before the text)
 */
function sticker(string $text, array $o = []): string
{
    $color = in_array($o['color'] ?? 'sun', ['sun', 'sky', 'blush', 'lavender', 'pistachio', 'white'], true) ? ($o['color'] ?? 'sun') : 'sun';
    $rot = (float) ($o['rotate'] ?? -10);
    $size = ($o['size'] ?? 'md') === 'sm' ? ' !px-3 !py-1.5 !text-[11.5px]' : '';
    return '<span class="sticker sticker-' . $color . (!empty($o['wiggle']) ? ' wiggle' : '') . $size . (!empty($o['class']) ? ' ' . e($o['class']) : '') . '" style="--r:' . $rot . 'deg" aria-hidden="true">'
        . (!empty($o['icon']) ? icon($o['icon'], 'h-3.5 w-3.5') : '') . e($text) . '</span>';
}

/** Red ribbon tab for the top edge of a card (the card needs position: relative). */
function ribbon_label(string $text): string
{
    return '<span class="ribbon-label"><span>' . e($text) . '</span></span>';
}

/**
 * Pastel card with a white pill button (Design v2 "institution" cards) — optional photo bottom-right.
 * @param array $o color (lavender|sky|aqua|cream|sand|blush|pistachio|sun), button ['label', 'url'], image (path),
 *                 ribbon (text), class, reveal, delay, heading (h3)
 */
function pastel_card(string $title, string $text = '', array $o = []): string
{
    $color = in_array($o['color'] ?? 'lavender', ['lavender', 'sky', 'aqua', 'cream', 'sand', 'blush', 'pistachio', 'sun'], true) ? ($o['color'] ?? 'lavender') : 'lavender';
    $h = $o['heading'] ?? 'h3';
    $reveal = ($o['reveal'] ?? false) ? reveal_attr((string) $o['reveal'], (int) ($o['delay'] ?? 0)) : '';
    $html = '<article class="pastel-card pastel-' . $color . ' card-lift' . (!empty($o['class']) ? ' ' . e($o['class']) : '') . '"' . $reveal . '>';
    if (!empty($o['ribbon'])) {
        $html .= '<span class="absolute right-6 top-0">' . str_replace('ribbon-label', 'ribbon-label !left-auto !right-0 !translate-x-0', ribbon_label((string) $o['ribbon'])) . '</span>';
    }
    if (!empty($o['image'])) {
        $html .= '<img src="' . e(site_image($o['image'])) . '" alt="" class="pastel-card-img" loading="lazy" decoding="async">';
    }
    $html .= '<' . $h . ' class="pastel-card-title relative">' . e($title) . '</' . $h . '>';
    if ($text !== '') {
        $html .= '<p class="pastel-card-text relative">' . e($text) . '</p>';
    }
    if (!empty($o['button'])) {
        $html .= '<a href="' . e(cms_link($o['button']['url'] ?? '#')) . '" class="btn btn-white btn-sm relative">' . e($o['button']['label'] ?? 'Learn more') . icon('arrow-right', 'h-4 w-4') . '</a>';
    }
    return $html . '</article>';
}

/** Coloured icon circle (pastel background + ink icon). $tint: sky|aqua|sun|lavender|blush|pistachio|sand|cream|ink|orange. */
function icon_circle(string $icon, string $tint = 'sky', string $class = ''): string
{
    return '<span class="icon-circle tint-' . e($tint) . ($class !== '' ? ' ' . e($class) : '') . '">' . icon($icon, 'h-6 w-6') . '</span>';
}

/**
 * White approvals / affiliations card with generic emblems (data: site_approvals()). Returns '' when empty.
 * @param array|null $items [['title', 'icon'], …]
 */
function approvals_card(?array $items = null, string $class = ''): string
{
    $items ??= site_approvals();
    if (!$items) {
        return '';
    }
    $html = '<div class="approvals-card' . ($class !== '' ? ' ' . e($class) : '') . '" role="list" aria-label="Approvals and affiliations">';
    foreach ($items as $a) {
        $html .= '<span class="approval-item" role="listitem"><span class="approval-emblem">' . emblem((string) ($a['icon'] ?? 'seal')) . '</span><span class="max-w-[9.5rem]">' . e($a['title']) . '</span></span>';
    }
    return $html . '</div>';
}

/** Approval card for carousels: red ribbon label, white emblem tile, caption. $a = ['label', 'title', 'text', 'icon']. */
function approval_card(array $a): string
{
    return '<article class="approval-card">' . ribbon_label((string) ($a['label'] ?? $a['title'])) . '<span class="approval-card-tile">' . emblem((string) ($a['icon'] ?? 'seal')) . '</span>'
        . '<p class="approval-card-text">' . e(($a['text'] ?? '') !== '' ? $a['text'] : $a['title']) . '</p></article>';
}

/**
 * Thin scrolling announcement strip (orange text on a light bar). Items: site_announcement_items() by default.
 * @param array $o links (HTML shown on the right from lg, e.g. portal logins), speed (px/s, default 40)
 */
function announcement_strip(?array $items = null, array $o = []): string
{
    $items ??= site_announcement_items();
    if (!$items) {
        return '';
    }
    $one = '';
    foreach ($items as $it) {
        $text = e($it['text']);
        $one .= '<span class="announce-item">' . (!empty($it['url']) ? '<a href="' . e($it['url']) . '">' . $text . '</a>' : $text) . '</span>';
    }
    // repeat short lists so the loop is always wider than the viewport
    $reps = max(1, (int) ceil(6 / count($items)));
    $group = str_repeat($one, $reps);
    // the ticker has its own clipped + faded area that ends before the pause button and the (lg+) static links
    return '<div class="announce-strip" role="region" aria-label="Announcements" data-marquee-host><div class="announce-ticker"><div class="marquee" data-marquee="' . (int) ($o['speed'] ?? 40) . '"><div class="marquee-track"><div class="marquee-group">' . $group . '</div></div></div></div>'
        . '<button type="button" class="announce-toggle" data-marquee-toggle aria-pressed="false" aria-label="Pause announcements">' . icon('pause', 'h-3 w-3') . '</button>'
        . (!empty($o['links']) ? '<div class="announce-links">' . $o['links'] . '</div>' : '') . '</div>';
}

/**
 * Dismissible admission notification bar (orange-red) — e.g. "Admissions Open 2026-27 · Last date 30 Nov" + Apply Now.
 * Dismissal is remembered per message in localStorage (core/widgets.ts [data-dismiss]). Returns '' without text.
 * @param array $o text (default setting admission_bar_text, else admissions_open_text + real deadline), cta
 *                 ['label', 'url'], key (storage key, default derived from the text)
 */
function admission_bar(array $o = []): string
{
    $text = trim((string) ($o['text'] ?? setting('admission_bar_text', '')));
    if ($text === '') {
        $deadline = site_admissions_deadline();
        $text = (string) setting('admissions_open_text', 'Admissions Open for 2026-27') . ($deadline ? ' · Last date to apply: ' . date('d M Y', $deadline) : '');
    }
    if ($text === '') {
        return '';
    }
    $cta = $o['cta'] ?? ['label' => setting('header_cta_text', 'Apply Now'), 'url' => setting('header_cta_url', '/apply')];
    $key = 'gimt.bar.' . substr(md5($text), 0, 8);
    return '<div class="admission-bar" role="region" aria-label="Admission notice" data-dismiss="' . e($key) . '">'
        . '<span>' . e($text) . '</span>'
        . (!empty($cta['url']) ? '<a class="admission-bar-cta" href="' . e(cms_link($cta['url'])) . '">' . e($cta['label'] ?? 'Apply Now') . icon('arrow-right', 'h-3.5 w-3.5') . '</a>' : '')
        . '<button type="button" class="admission-bar-close" data-dismiss-close aria-label="Dismiss admission notice">' . icon('x', 'h-4 w-4') . '</button></div>';
}

/**
 * Floating 2D geometric shapes (rings, dots grid, squares, plus, half-circle) for hero / band compositions — absolute
 * positioned, drifting slowly (static for reduced motion). $preset: hero | band | card. Parent must be relative.
 */
function geo_shapes(string $preset = 'hero', string $class = ''): string
{
    $sets = [
        'hero' => [
            ['geo-ring', 'left-[46%] top-10 h-24 w-24 hidden lg:block', '0s', '0deg'],
            ['geo-dots', 'right-[3%] bottom-12 h-24 w-28 hidden md:block', '1s', '0deg'],
            ['geo-square', 'left-[4%] bottom-16 h-4 w-4 hidden sm:block', '2s', '18deg'],
            ['geo-plus', 'right-[44%] bottom-10 h-5 w-5 hidden lg:block', '.5s', '0deg'],
        ],
        'band' => [
            ['geo-ring', 'right-[38%] -top-8 h-28 w-28', '0s', '0deg'],
            ['geo-dots', 'left-6 bottom-6 h-16 w-24', '1s', '0deg'],
            ['geo-square', 'right-[46%] bottom-8 h-3.5 w-3.5', '2s', '24deg'],
            ['geo-plus', 'left-[44%] top-8 h-4 w-4', '.6s', '0deg'],
        ],
        'card' => [
            ['geo-ring-flame', '-right-6 -top-6 h-20 w-20', '0s', '0deg'],
            ['geo-dots', 'right-6 bottom-6 h-12 w-16', '1.2s', '0deg'],
        ],
    ];
    $html = '';
    foreach ($sets[$preset] ?? $sets['hero'] as [$type, $pos, $delay, $rot]) {
        $html .= '<span class="geo ' . $type . ' ' . $pos . ($class !== '' ? ' ' . e($class) : '') . '" style="--d:' . $delay . ';--rot:' . $rot . '" aria-hidden="true"></span>';
    }
    return $html;
}

/**
 * Slow morphing SVG blob (SMIL path morph, 22s loop; paused in static mode / reduced motion by core/motion.ts).
 * @param string $class positioning/size classes (e.g. 'right-0 top-0 h-[420px] w-[420px]')
 * @param string $tone  flame (orange-red → transparent) | navy | warm
 */
function blob(string $class = '', string $tone = 'flame'): string
{
    static $n = 0;
    $id = 'blob-g-' . (++$n);
    $stops = [
        'flame' => ['#F05A28', '.20', '#F05A28', '0'],
        'navy' => ['#1F4A94', '.22', '#0B2A5B', '0'],
        'warm' => ['#EDE8E2', '1', '#F5F2EE', '.6'],
    ][$tone] ?? ['#F05A28', '.2', '#F05A28', '0'];
    $a = 'M100,20C140,20 180,55 180,100C180,145 145,180 100,180C55,180 20,145 20,100C20,55 60,20 100,20Z';
    $b = 'M110,14C156,24 186,64 176,110C166,160 120,186 84,176C40,166 14,130 24,86C34,40 68,6 110,14Z';
    $c = 'M94,26C136,10 176,50 182,96C188,142 150,178 104,182C58,186 20,150 18,104C14,58 54,40 94,26Z';
    return '<svg class="blob-svg ' . e($class) . '" viewBox="0 0 200 200" aria-hidden="true" focusable="false" data-blob>'
        . '<defs><radialGradient id="' . $id . '" cx="35%" cy="30%" r="75%"><stop offset="0" stop-color="' . $stops[0] . '" stop-opacity="' . $stops[1] . '"/><stop offset="1" stop-color="' . $stops[2] . '" stop-opacity="' . $stops[3] . '"/></radialGradient></defs>'
        . '<path fill="url(#' . $id . ')" d="' . $a . '"><animate attributeName="d" dur="22s" repeatCount="indefinite" calcMode="spline" keyTimes="0;.33;.66;1" keySplines=".45 0 .55 1;.45 0 .55 1;.45 0 .55 1" values="' . $a . ';' . $b . ';' . $c . ';' . $a . '"/></path></svg>';
}

/**
 * Floating round WhatsApp button (alternative to the vertical "Chat" tab). '' when no whatsapp_number is set.
 */
function whatsapp_button(string $text = 'Hello GIMT, I would like to know more about admissions.'): string
{
    $url = site_whatsapp_url($text);
    return $url ? '<a href="' . e($url) . '" target="_blank" rel="noopener" class="wa-fab no-print" aria-label="Chat with us on WhatsApp (opens in a new tab)">' . icon('whatsapp-brand', '') . '</a>' : '';
}

/**
 * Inline WhatsApp CTA button (`.btn-whatsapp`: soft green tint, official glyph in a #25D366 circle, darker-green colour
 * overlay on hover/focus). '' when no whatsapp_number is set.
 *   echo whatsapp_cta('Chat with us on WhatsApp', 'Hello GIMT, I would like to know about the MBA program.', ['size' => 'lg']);
 * @param array $o size (sm|lg), class
 */
function whatsapp_cta(string $label = 'Chat with us on WhatsApp', string $text = 'Hello GIMT, I would like to know more about admissions.', array $o = []): string
{
    $url = site_whatsapp_url($text);
    if (!$url) {
        return '';
    }
    $size = in_array($o['size'] ?? '', ['sm', 'lg'], true) ? ' btn-' . $o['size'] : '';
    return '<a href="' . e($url) . '" target="_blank" rel="noopener" class="btn btn-whatsapp' . $size . (!empty($o['class']) ? ' ' . e($o['class']) : '') . '" aria-label="' . e($label) . ' (opens in a new tab)">'
        . '<span class="btn-wa-icon" aria-hidden="true">' . icon('whatsapp-brand', '') . '</span>' . e($label) . '</a>';
}

/**
 * Sticky admission enquiry CTA (desktop vertical side tab on the left edge) that opens the enquiry modal rendered by
 * enquiry_modal(). Rendered by site_footer() unless 'enquiry_tab' => false.
 */
function enquiry_tab(string $label = 'Enquire Now', string $modalId = 'enquiry-modal'): string
{
    return '<button type="button" class="enquiry-tab no-print" data-modal-open="#' . e($modalId) . '" aria-haspopup="dialog"><span class="enquiry-tab-icon">' . icon('message-circle', 'h-4 w-4') . '</span><span class="enquiry-tab-text">' . e($label) . '</span></button>';
}

/**
 * Accessible enquiry modal (vanilla data-modal) containing an EnquiryForm — opened by enquiry_tab() or any
 * [data-modal-open="#enquiry-modal"] trigger, e.g. "Request a Callback" buttons. Program options from site_programs().
 * @param array $o id, title, subtitle, source (hidden source value), theme (lead|light), program (preselected slug)
 */
function enquiry_modal(array $o = []): string
{
    $id = (string) ($o['id'] ?? 'enquiry-modal');
    $options = array_map(fn ($p) => ['value' => (string) $p['id'], 'label' => $p['name']], site_programs());
    $theme = $o['theme'] ?? 'lead';
    $form = enquiry_form([
        'endpoint' => base_url('api/public/enquiry'), 'theme' => $theme, 'compact' => true, 'captureUtm' => true,
        'title' => $o['title'] ?? 'Request a Callback', 'titleHighlight' => $o['highlight'] ?? 'Callback',
        'subtitle' => $o['subtitle'] ?? 'Our admission counsellor will call you within one working day.',
        'submitLabel' => $o['submit'] ?? 'Request Callback', 'hidden' => ['source' => (string) ($o['source'] ?? 'website'), 'form' => 'callback'],
        'consent' => 'I agree to be contacted by GIMT about admissions by phone, SMS, email or WhatsApp.',
        'fields' => [
            ['name' => 'name', 'label' => 'Full Name', 'required' => true, 'maxLength' => 150, 'autocomplete' => 'name'],
            ['name' => 'phone', 'label' => 'Mobile Number', 'type' => 'tel', 'required' => true, 'prefix' => '+91', 'pattern' => '^[6-9][0-9]{9}$', 'patternMessage' => 'Enter a valid 10-digit mobile number.', 'autocomplete' => 'tel-national', 'maxLength' => 10],
            ['name' => 'email', 'label' => 'Email Address', 'type' => 'email', 'autocomplete' => 'email'],
            ['name' => 'program_id', 'label' => 'Program of interest', 'type' => 'select', 'options' => $options, 'placeholder' => 'Select program', 'value' => (string) ($o['program'] ?? '')],
        ],
    ]);
    return '<div id="' . e($id) . '" class="modal hidden" role="dialog" aria-modal="true" aria-label="' . e($o['title'] ?? 'Request a Callback') . '" data-modal>'
        . '<div class="modal-scrim" data-modal-close></div><div class="modal-card modal-card-flush"><button type="button" class="modal-x" data-modal-close aria-label="Close">' . icon('x', 'h-5 w-5') . '</button>' . $form . '</div></div>';
}

/** Back-to-top button with a scroll-progress ring (rendered by site_footer()). */
function back_to_top(): string
{
    return '<button type="button" data-back-to-top class="back-to-top no-print" aria-label="Back to top" aria-hidden="true" tabindex="-1">'
        . '<svg class="btt-ring-svg" viewBox="0 0 60 60" aria-hidden="true"><circle class="btt-track" cx="30" cy="30" r="27" pathLength="100"/><circle class="btt-ring" cx="30" cy="30" r="27" pathLength="100"/></svg>'
        . icon('arrow-up', 'btt-arrow h-5 w-5') . '</button>';
}

/**
 * Mobile admission bar: persistent compact Call · Apply Now for phones/tablets (hidden from lg). Rendered by
 * site_footer() unless 'mobile_cta' => false.
 * @param array $o call (phone), apply ['label', 'url'], whatsapp (bool, default true: WhatsApp button when whatsapp_number is set)
 */
function mobile_cta_bar(array $o = []): string
{
    $phone = (string) ($o['call'] ?? setting('phone', '+91 9955446477'));
    $apply = $o['apply'] ?? ['label' => setting('header_cta_text', 'Apply Now'), 'url' => setting('header_cta_url', '/apply')];
    $wa = ($o['whatsapp'] ?? true) ? site_whatsapp_url() : null;
    return '<div class="mobile-cta no-print' . ($wa ? ' has-wa' : '') . '" role="region" aria-label="Quick actions">'
        . '<a href="' . e(site_tel($phone)) . '" class="btn btn-brochure" data-no-ripple>' . icon('phone', 'h-4 w-4') . 'Call</a>'
        . ($wa ? '<a href="' . e($wa) . '" target="_blank" rel="noopener" class="wa-action" aria-label="Chat with us on WhatsApp (opens in a new tab)">' . icon('whatsapp-brand', '') . '</a>' : '')
        . '<a href="' . e(cms_link($apply['url'] ?? '/apply')) . '" class="btn btn-apply">' . e($apply['label'] ?? 'Apply Now') . icon('arrow-right', 'h-4 w-4') . '</a></div>';
}

/* ============================================================================ Island wrappers (fallback + island) */

/**
 * Homepage hero slider (island HeroSlider, mounted eagerly). Design v3: grid-paper (light) or ink grid (dark)
 * background, big bold Plus Jakarta headline with a hand-drawn ellipse around `scribble` (a word of the title), rounded photo
 * frame with doodles + sticker on the right, white pill chips, bold stat numbers with thin dividers. Fallback = first slide.
 *   echo hero_slider([
 *     'slides' => [['image' => site_image(...), 'eyebrow' => 'Shaping Future Leaders', 'title' => 'Shape Your Future.',
 *                   'scribble' => 'Future', 'highlight' => 'Build Your Legacy.', 'text' => '…',
 *                   'cta' => ['label' => 'Apply for Admission', 'url' => '/apply'],
 *                   'cta2' => ['label' => 'Explore Programs', 'url' => '/programs']], …],
 *     'chips' => [['icon' => 'briefcase-business', 'label' => 'Industry Focused'], …],
 *     'stats' => [['value' => 10, 'suffix' => '+', 'label' => 'Programs'], …],
 *     'video' => ['url' => 'https://www.youtube.com/watch?v=…', 'label' => 'Watch Our Campus Tour'],
 *     'tagline' => 'Admissions Open for 2026-27', 'taglineWords' => ['Learn', 'Grow', 'Innovate', 'Succeed'],
 *     'sticker' => 'Onwards & Upwards', 'interval' => 6500, 'theme' => 'light']);
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
    if (!isset($props['sticker']) && !empty($props['taglineWords'])) {
        $props['sticker'] = implode(' · ', array_slice($props['taglineWords'], 0, 3));
    }
    $first = $slides[0];
    $dark = ($props['theme'] ?? 'light') === 'dark';
    $stats = $props['stats'] ?? [];
    $html = '<section class="hero-slider ' . ($dark ? 'hero-dark' : 'hero-light') . ($stats ? ' has-stats' : '') . '" aria-label="' . e($props['ariaLabel'] ?? 'Featured highlights') . '">';
    $html .= '<div class="hero-bg" aria-hidden="true"></div><div class="contents" data-slot="backdrop"><span class="hero-swirl" aria-hidden="true">' . doodle('swirl', 'h-auto w-full') . '</span></div>';
    $html .= '<div class="container-site hero-inner"><div><div class="hero-copy"><div class="hero-copy-item is-active">';
    if (!empty($first['eyebrow'])) {
        $html .= '<p class="hero-eyebrow"><span class="hero-eyebrow-dot"></span>' . e($first['eyebrow']) . '</p>';
    }
    $html .= '<h1 class="hero-title">' . scribble_text($first['title'], !empty($first['scribble']) ? $first['scribble'] : false, $dark ? 'light' : 'orange')
        . (!empty($first['highlight']) ? '<span class="hero-highlight">' . e($first['highlight']) . '</span>' : '') . '</h1>';
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
    if (count($slides) > 1) {
        // same footprint as the interactive controls
        $html .= '<div class="hero-controls" aria-hidden="true"><span class="hero-arrow">' . icon('chevron-left', 'h-5 w-5') . '</span><span class="hero-bullets">';
        foreach ($slides as $i => $_) {
            $html .= '<span class="hero-bullet' . ($i === 0 ? ' is-active' : '') . '"></span>';
        }
        $html .= '</span><span class="hero-arrow">' . icon('chevron-right', 'h-5 w-5') . '</span>' . (($props['interval'] ?? 6500) > 0 ? '<span class="hero-arrow">' . icon('pause', 'h-4 w-4') . '</span>' : '') . '</div>';
    }
    $html .= '</div>';
    // visual: rounded photo frame + doodles + chips + video
    $html .= '<div class="hero-visual"><div class="hero-frame"><div class="hero-media-item is-active"><picture>'
        . (!empty($first['mobileImage']) ? '<source media="(max-width: 767px)" srcset="' . e($first['mobileImage']) . '">' : '')
        . '<img src="' . e($first['image']) . '" alt="' . e($first['alt'] ?? '') . '" class="hero-img" fetchpriority="high" decoding="sync"></picture></div>';
    if (!empty($props['video']['url'])) {
        $v = $props['video'];
        $html .= '<a href="' . e($v['url']) . '" data-lightbox class="hero-video" data-caption="' . e($v['label'] ?? '') . '"><span class="play-pulse">' . icon('play', 'h-5 w-5 translate-x-px') . '</span>'
            . '<span class="text-left"><span class="block text-sm font-semibold">' . e($v['label'] ?? 'Watch video') . '</span>' . (!empty($v['sublabel']) ? '<span class="block text-xs opacity-75">' . e($v['sublabel']) . '</span>' : '') . '</span></a>';
    }
    $html .= '</div><div class="contents" data-slot="doodles">' . hero_doodles($props['sticker'] ?? '', $dark) . '</div>';
    if (!empty($props['chips'])) {
        $html .= '<ul class="hero-chips" aria-label="Highlights">';
        foreach (array_values($props['chips']) as $i => $c) {
            $html .= '<li class="hero-chip" style="--i:' . $i . '"><span class="hero-chip-icon">' . icon($c['icon'] ?? 'sparkles', 'h-4 w-4') . '</span>' . e($c['label'] ?? '') . '</li>';
        }
        $html .= '</ul>';
    }
    $html .= '</div></div>';
    if ($stats) {
        $html .= '<div class="container-site hero-stats-wrap"><ul class="hero-stats" aria-label="GIMT at a glance">';
        foreach ($stats as $s) {
            $html .= '<li class="hero-stat"><p><span class="hero-stat-value">' . e(($s['prefix'] ?? '') . number_format((float) $s['value']) . ($s['suffix'] ?? '')) . '</span><span class="hero-stat-label">' . e($s['label']) . '</span></p></li>';
        }
        $html .= '</ul></div>';
    }
    $html .= '</section>';
    return island('HeroSlider', $props, $html, ['eager' => true]);
}

/** Layered decoration around the hero photo frame (shared by the HeroSlider fallback; the island re-uses this markup via
 * its 'doodles' slot): gradient ring, morphing blob, geometric shapes and an optional sticker. */
function hero_doodles(string $sticker = '', bool $dark = false): string
{
    return '<span class="page-hero-ring hero-doodle" aria-hidden="true"></span>'
        . blob('hero-doodle -left-16 -top-12 h-[115%] w-[115%]', $dark ? 'navy' : 'flame')
        . '<span class="geo geo-dots -bottom-6 -right-6 h-24 w-24" style="--d:1s" aria-hidden="true"></span>'
        . '<span class="geo geo-square -left-3 top-[22%] h-4 w-4" style="--d:2s;--rot:18deg" aria-hidden="true"></span>'
        . '<span class="geo geo-plus -right-4 top-[46%] h-5 w-5" style="--d:.6s" aria-hidden="true"></span>'
        . ($sticker !== '' ? '<span class="hero-doodle -left-4 bottom-[18%] z-[2] sm:-left-8">' . sticker($sticker, ['color' => 'white', 'rotate' => -8]) . '</span>' : '');
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
 * Program explorer (island ProgramExplorer): black-active pill tabs (or icon tiles) + search + animated filtering of
 * program cards. Fallback: the same pills wired to the vanilla data-filter behaviour + all program cards.
 *   echo program_explorer(site_programs(), ['layout' => 'pills', 'viewAll' => ['label' => 'View All Programs', 'url' => site_url('programs')]]);
 * @param array $props categories (default program_categories()), layout (pills|tiles), search (bool), syncParam,
 *                     gridClass, viewAll, emptyText, initial, card (program_card() options)
 */
function program_explorer(array $programs, array $props = []): string
{
    static $n = 0;
    $group = 'programs-' . (++$n);
    $props['categories'] = $props['categories'] ?? program_categories();
    if (!empty($props['viewAll']['url'])) {
        $props['viewAll']['url'] = cms_link($props['viewAll']['url']);
    }
    $cardOpts = (array) ($props['card'] ?? []);
    unset($props['card']);
    $layout = $props['layout'] ?? 'pills';
    $grid = $props['gridClass'] ?? 'sm:grid-cols-2 lg:grid-cols-3';
    $pills = '';
    foreach ($props['categories'] as $i => $c) {
        $pills .= $layout === 'tiles'
            ? '<button type="button" class="filter-tile' . ($i === 0 ? ' is-active' : '') . '" data-filter="' . e($c['key']) . '" data-filter-group="' . $group . '" aria-pressed="' . ($i === 0 ? 'true' : 'false') . '">' . (!empty($c['icon']) ? '<span class="filter-tile-icon">' . icon($c['icon'], 'h-5 w-5') . '</span>' : '') . '<span class="text-sm font-semibold">' . e($c['label']) . '</span></button>'
            : '<button type="button" class="chip' . ($i === 0 ? ' chip-active' : '') . '" data-filter="' . e($c['key']) . '" data-filter-group="' . $group . '" aria-pressed="' . ($i === 0 ? 'true' : 'false') . '">' . e($c['label']) . '</button>';
    }
    $cards = '';
    foreach ($programs as $p) {
        $tokens = program_filter_tokens($p);
        $cards .= '<div class="h-full" data-key="' . e($p['slug'] ?? $p['id'] ?? '') . '" data-category="' . e($tokens) . '" data-search="' . e(trim(($p['name'] ?? '') . ' ' . ($p['short_name'] ?? '') . ' ' . ($p['category'] ?? '') . ' ' . program_level_label($p['level'] ?? ''))) . '" data-filter-item="all ' . e($tokens) . '" data-filter-group="' . $group . '">' . program_card($p, $cardOpts) . '</div>';
    }
    $fallback = '<div class="program-explorer"><div class="mb-8 flex flex-col gap-4' . ($layout === 'pills' ? ' lg:flex-row lg:items-center lg:justify-between' : '') . '">'
        . '<div class="' . ($layout === 'tiles' ? 'grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8' : 'pill-row scrollbar-none') . '" role="toolbar" aria-label="Filter programs by category">' . $pills . '</div>'
        . (!empty($props['viewAll']) ? '<div class="flex items-center gap-3"><a href="' . e($props['viewAll']['url']) . '" class="btn btn-outline btn-sm hidden shrink-0 sm:inline-flex">' . e($props['viewAll']['label']) . icon('arrow-right', 'h-4 w-4') . '</a></div>' : '')
        . '</div><div class="grid gap-6 ' . e($grid) . '" data-slot="items">' . $cards . '</div></div>';
    return island('ProgramExplorer', $props, $fallback);
}

/**
 * Generic carousel (island Carousel) over server-rendered cards — round white arrow buttons centred below.
 *   echo carousel(array_map(fn ($p) => program_card($p), $programs), ['perView' => ['base' => 1.1, 'sm' => 2, 'lg' => 3], 'autoplay' => 6000, 'ariaLabel' => 'Featured programs']);
 * @param string[] $slides HTML of each slide (one root element each)
 * @param array    $props  ariaLabel, perView {base,sm,md,lg,xl}, gap (px), autoplay (ms), loop, arrows (default true),
 *                         dots (default false), theme ('light'|'dark' arrow style)
 */
function carousel(array $slides, array $props = []): string
{
    $pv = ($props['perView'] ?? []) + ['base' => 1.15, 'sm' => 2, 'lg' => 3];
    $style = '--gap:' . (int) ($props['gap'] ?? 24) . 'px;--pv-base:' . (float) $pv['base'] . ';--pv-sm:' . (float) ($pv['sm'] ?? $pv['base']) . ';--pv-md:' . (float) ($pv['md'] ?? $pv['sm'] ?? $pv['base'])
        . ';--pv-lg:' . (float) ($pv['lg'] ?? $pv['md'] ?? $pv['sm'] ?? $pv['base']) . ';--pv-xl:' . (float) ($pv['xl'] ?? $pv['lg'] ?? $pv['base']);
    $controls = (($props['arrows'] ?? true) || ($props['dots'] ?? false)) && count($slides) > 1;
    $fallback = '<div class="carousel' . (($props['theme'] ?? '') === 'dark' ? ' on-dark' : '') . '" role="region" aria-label="' . e($props['ariaLabel'] ?? 'Carousel') . '"><div class="carousel-track scrollbar-none" style="' . $style . '" data-slot="slides" tabindex="0">' . implode('', $slides) . '</div>'
        . ($controls ? '<div class="carousel-controls" aria-hidden="true"><span class="carousel-arrow">' . icon('arrow-left', 'h-5 w-5') . '</span><span class="carousel-arrow">' . icon('arrow-right', 'h-5 w-5') . '</span></div>' : '') . '</div>';
    return island('Carousel', $props, $fallback);
}

/** Initials for an avatar placeholder. */
function site_initials(string $name): string
{
    $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
    return strtoupper(mb_substr($parts[0] ?? '', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));
}

/**
 * Testimonial / student-story cards (island TestimonialSlider): photo cards with quote, bold name and program meta,
 * scroll-snap track with round arrows centred below. Fallback: the same cards in a native scroll track.
 * @param array $items [['name', 'quote', 'role', 'company', 'program', 'photo' (URL), 'meta', 'quoteMarks' (bool, default true —
 *                      false for third-person bios)], …]
 * @param array $props autoplay (ms, default 7000), theme (light|dark), ariaLabel, perView {base, sm, lg, xl}
 */
function testimonial_slider(array $items, array $props = []): string
{
    if (!$items) {
        return '';
    }
    $props['items'] = array_values($items);
    $dark = ($props['theme'] ?? 'light') === 'dark';
    $pv = ($props['perView'] ?? []) + ['base' => 1.1, 'sm' => 2, 'lg' => 3, 'xl' => 4];
    $style = '--gap:20px;--pv-base:' . (float) $pv['base'] . ';--pv-sm:' . (float) $pv['sm'] . ';--pv-md:' . (float) $pv['sm'] . ';--pv-lg:' . (float) $pv['lg'] . ';--pv-xl:' . (float) $pv['xl'];
    $pastels = ['#CCC1FF', '#A9D8FF', '#BEFFFE', '#F2DFB4', '#F7BFD8', '#E3EDA0'];
    $html = '<div class="testimonials carousel' . ($dark ? ' testimonials-dark' : '') . '" role="region" aria-label="' . e($props['ariaLabel'] ?? 'Student and alumni stories') . '"><div class="carousel-track scrollbar-none" style="' . $style . '" tabindex="0">';
    foreach ($props['items'] as $i => $t) {
        $meta = $t['meta'] ?? implode(' • ', array_filter([$t['program'] ?? '', $t['company'] ?? '']));
        $html .= '<figure class="carousel-slide tcard"><div class="tcard-photo">'
            . (!empty($t['photo']) ? '<img src="' . e($t['photo']) . '" alt="" loading="lazy" decoding="async">' : '<span class="tcard-initials" style="background:' . $pastels[$i % 6] . '">' . e(site_initials((string) $t['name'])) . '</span>')
            . '</div><blockquote class="tcard-quote">' . (($t['quoteMarks'] ?? true) ? '“' . e($t['quote']) . '”' : e($t['quote'])) . '</blockquote><figcaption><span class="tcard-name block">' . e($t['name']) . '</span>'
            . ($meta !== '' ? '<span class="tcard-meta block">' . e($meta) . '</span>' : '') . '</figcaption></figure>';
    }
    $html .= '</div>' . (count($items) > 1 ? '<div class="carousel-controls" aria-hidden="true"><span class="carousel-arrow">' . icon('arrow-left', 'h-5 w-5') . '</span><span class="carousel-arrow">' . icon('arrow-right', 'h-5 w-5') . '</span></div>' : '') . '</div>';
    return island('TestimonialSlider', $props, $html);
}

/**
 * Recruiter / partner logo marquee (island LogoMarquee) in a light card strip. Fallback is a working CSS marquee.
 * @param array $items [['name', 'logo' (URL, optional), 'url', 'industry'], …]
 * @param array $props rows (1|2), speed (px/s), grayscale (bool), ariaLabel, label (heading inside the card, e.g.
 *                     "Leading Recruiters")
 */
function logo_marquee(array $items, array $props = []): string
{
    if (!$items) {
        return '';
    }
    $props['items'] = array_values($items);
    $rows = $props['rows'] ?? (count($items) >= 8 ? 2 : 1);
    $groups = $rows === 2 ? array_chunk($props['items'], (int) ceil(count($items) / 2)) : [$props['items']];
    $tints = ['tint-lavender', 'tint-sky', 'tint-aqua', 'tint-sand', 'tint-blush', 'tint-pistachio'];
    $tile = function (array $it, int $i, bool $hidden) use ($tints): string {
        $inner = !empty($it['logo'])
            ? '<img src="' . e($it['logo']) . '" alt="' . ($hidden ? '' : e($it['name'])) . '" loading="lazy" decoding="async" class="logo-img">'
            : '<span class="logo-mark ' . $tints[$i % count($tints)] . '" aria-hidden="true">' . e(strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $it['name']), 0, 2))) . '</span><span class="text-[17px] font-bold tracking-tight text-brand-900">' . e($it['name']) . '</span>';
        return '<li><span class="logo-tile">' . $inner . '</span></li>';
    };
    $html = '<div class="logo-marquee' . (($props['grayscale'] ?? true) ? ' logo-grayscale' : '') . '" role="region" aria-label="' . e($props['ariaLabel'] ?? 'Our recruiters') . '">'
        . (!empty($props['label']) ? '<p class="logo-marquee-label">' . e($props['label']) . '</p>' : '');
    foreach ($groups as $g => $list) {
        $a = '';
        $b = '';
        foreach ($list as $i => $it) {
            $a .= $tile($it, $i, false);
            $b .= $tile($it, $i, true);
        }
        $html .= '<div class="marquee marquee-fade' . ($g === 1 ? ' marquee-reverse' : '') . '" style="--marquee-gap:.75rem"><div class="marquee-track"><ul class="marquee-group">' . $a . '</ul><ul class="marquee-group" aria-hidden="true">' . $b . '</ul></div></div>';
    }
    return island('LogoMarquee', $props, $html . '</div>');
}

/**
 * Animated statistics (island StatCounter): bold Plus Jakarta count-up numbers as cards / progress rings / bars / inline / divided
 * (thin dividers between items — use on dark ink bands with theme dark).
 * @param array $items [['value' => 95, 'suffix' => '%', 'label' => 'Placement Rate', 'icon' => 'trending-up', 'progress' => 95, 'caption' => '…'], …]
 * @param array $props variant (cards|rings|bars|inline|divided), theme (light|dark), gridClass
 */
function stat_counter(array $items, array $props = []): string
{
    $props['items'] = array_values($items);
    $variant = $props['variant'] ?? 'cards';
    $grid = $props['gridClass'] ?? ($variant === 'bars' ? 'sm:grid-cols-2' : 'grid-cols-2 lg:grid-cols-4');
    $tints = ['tint-navy', 'tint-orange', 'tint-navy', 'tint-orange', 'tint-navy', 'tint-orange'];
    $html = '<ul class="stat-group grid gap-x-5 gap-y-8 ' . e($grid) . (($props['theme'] ?? '') === 'dark' ? ' stat-dark' : '') . ' is-on">';
    foreach ($props['items'] as $i => $s) {
        $val = e(($s['prefix'] ?? '') . number_format((float) $s['value'], (int) ($s['decimals'] ?? 0)) . ($s['suffix'] ?? ''));
        $html .= '<li class="stat-item stat-' . e($variant) . '">';
        if ($variant === 'rings') {
            $pct = max(0, min(100, (float) ($s['progress'] ?? (str_contains($s['suffix'] ?? '', '%') ? $s['value'] : 100))));
            $html .= '<span class="stat-ring"><svg viewBox="0 0 120 120" aria-hidden="true"><circle cx="60" cy="60" r="52" class="stat-ring-track"/><circle cx="60" cy="60" r="52" pathLength="100" class="stat-ring-fill" style="stroke-dashoffset:' . (100 - $pct) . '"/></svg><span class="stat-ring-value">' . $val . '</span></span><span class="stat-label">' . e($s['label']) . '</span>';
        } elseif ($variant === 'bars') {
            $pct = max(0, min(100, (float) ($s['progress'] ?? 100)));
            $html .= '<span class="flex items-baseline justify-between gap-3"><span class="stat-label !mt-0">' . e($s['label']) . '</span><span class="stat-value">' . $val . '</span></span><span class="progress-track mt-3"><span class="progress-fill" style="--progress:' . ($pct / 100) . '"></span></span>';
        } else {
            $html .= ($variant === 'cards' && !empty($s['icon']) ? '<span class="stat-icon ' . $tints[$i % 6] . '">' . icon($s['icon'], 'h-6 w-6') . '</span>' : '')
                . '<span><span class="stat-value">' . $val . '</span><span class="stat-label">' . e($s['label']) . '</span></span>';
        }
        $html .= (!empty($s['caption']) && $variant !== 'cards' ? '<span class="stat-caption">' . e($s['caption']) . '</span>' : '') . '</li>';
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
 * FAQ accordion (island Accordion): grey rounded rows, bold questions, round caret buttons. Fallback = native
 * <details> enhanced by the vanilla accordion.
 * @param array $items [['q' => 'Question', 'a' => "Answer\n\nSecond paragraph", 'category' => 'Admissions'], …]
 * @param array $props mode (single|multi), defaultOpen (index, -1 none), search (bool), categories (bool), columns (1|2), theme
 */
function faq_accordion(array $items, array $props = []): string
{
    $props['items'] = array_values(array_map(fn ($i) => ['q' => (string) ($i['q'] ?? $i['question'] ?? ''), 'a' => (string) ($i['a'] ?? $i['answer'] ?? '')] + (isset($i['category']) ? ['category' => (string) $i['category']] : []), $items));
    $cols = (int) ($props['columns'] ?? 1);
    $open = (int) ($props['defaultOpen'] ?? 0);
    $list = $props['items'];
    $groups = $cols === 2 ? array_chunk($list, max(1, (int) ceil(count($list) / 2)), true) : [$list];
    $html = '<div class="faq"><div class="grid gap-3' . ($cols === 2 ? ' lg:grid-cols-2' : '') . '" data-accordion="' . (($props['mode'] ?? 'single') === 'multi' ? 'multi' : 'single') . '">';
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
 * Accessible tabs (island Tabs) over server-rendered panels — outlined pills, black active pill.
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
        $html .= '<span class="tabs-tab' . ($i === 0 ? ' is-active' : '') . '"' . ($i === 0 && $variant === 'pills' ? ' style="background:#151419;color:#fff;border-color:#151419"' : '') . '>' . (!empty($t['icon']) ? icon($t['icon'], 'h-4 w-4') : '') . e($t['label']) . '</span>';
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

/** UTM / click-id parameters of the current request (sanitised) for lead forms. */
function site_utm_params(): array
{
    $out = [];
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'] as $k) {
        $v = trim((string) ($_GET[$k] ?? ''));
        if ($v !== '') {
            $out[$k] = mb_substr((string) preg_replace('/[^\p{L}\p{N}\s._~:+\/|-]/u', '', $v), 0, 100);
        }
    }
    return $out;
}

/**
 * AJAX enquiry / counselling / contact form (island EnquiryForm). Fallback: an equivalent <form data-ajax-form> that
 * the vanilla form handler submits (and that posts normally without JS, with the _csrf field).
 *   echo enquiry_form(['endpoint' => base_url('api/public/enquiry'), 'title' => 'Need Help Choosing a Program?',
 *     'theme' => 'dark', 'compact' => true, 'submitLabel' => 'Get Free Counselling', 'hidden' => ['source' => 'programs-page'],
 *     'fields' => [['name' => 'name', 'label' => 'Full Name', 'required' => true, 'maxLength' => 150],
 *                  ['name' => 'phone', 'label' => 'Phone Number', 'type' => 'tel', 'required' => true, 'prefix' => '+91'],
 *                  ['name' => 'email', 'label' => 'Email Address', 'type' => 'email', 'required' => true],
 *                  ['name' => 'program', 'label' => 'Program Interest', 'type' => 'select', 'options' => ['MBA', 'BBA']]]]);
 * Field keys: name, label, type (text|email|tel|number|date|select|textarea|checkbox), required, placeholder, options
 * (strings or {value,label}), col (full|half), minLength, maxLength, min, max, pattern (+patternMessage), rows, help,
 * prefix (e.g. '+91' shown before a tel input), value.
 * Props: theme (light|dark|glass|lead|plain), compact, title, titleHighlight (part of the title in orange — lead card),
 * subtitle, icon, tags ([{icon, label}] small feature tags under the title — lead card), submitLabel, successTitle,
 * successMessage, hidden, consent, captureUtm (bool: add utm_* / gclid from the URL as hidden fields),
 * footer (HTML strip attached below the card, e.g. the yellow deadline/seats strip — server-rendered, outside the island),
 * id (anchor id on the wrapper).
 */
function enquiry_form(array $props): string
{
    $theme = $props['theme'] ?? 'light';
    $compact = !empty($props['compact']);
    if (!empty($props['captureUtm'])) {
        $props['hidden'] = ($props['hidden'] ?? []) + site_utm_params();
    }
    $footer = (string) ($props['footer'] ?? '');
    $wrapId = (string) ($props['id'] ?? '');
    unset($props['footer'], $props['id']);
    $title = (string) ($props['title'] ?? '');
    $titleHtml = e($title);
    if ($title !== '' && !empty($props['titleHighlight']) && ($pos = mb_stripos($title, (string) $props['titleHighlight'])) !== false) {
        $len = mb_strlen((string) $props['titleHighlight']);
        $titleHtml = e(mb_substr($title, 0, $pos)) . '<em>' . e(mb_substr($title, $pos, $len)) . '</em>' . e(mb_substr($title, $pos + $len));
    }
    $html = '<div class="enquiry enquiry-' . e($theme) . ($compact ? ' enquiry-compact' : '') . '">';
    if ($title !== '' || !empty($props['icon'])) {
        $html .= '<div class="mb-5' . (in_array($theme, ['dark', 'lead'], true) ? ' text-center' : '') . '">' . (!empty($props['icon']) ? '<span class="enquiry-icon">' . icon($props['icon'], 'h-7 w-7') . '</span>' : '')
            . ($title !== '' ? '<h3 class="enquiry-title">' . $titleHtml . '</h3>' : '');
        if (!empty($props['tags'])) {
            $html .= '<p class="enquiry-tags">';
            foreach ($props['tags'] as $t) {
                $html .= '<span>' . icon($t['icon'] ?? 'check', '') . e($t['label'] ?? '') . '</span>';
            }
            $html .= '</p>';
        }
        $html .= (!empty($props['subtitle']) ? '<p class="enquiry-subtitle">' . e($props['subtitle']) . '</p>' : '') . '</div>';
    }
    $html .= '<form action="' . e($props['endpoint'] ?? '') . '" method="post" data-ajax-form data-success="' . e($props['successMessage'] ?? 'Thank you! We will contact you shortly.') . '" class="grid grid-cols-2 gap-3.5">' . csrf_field()
        . '<input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">';
    foreach (($props['hidden'] ?? []) as $k => $v) {
        $html .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    foreach (($props['fields'] ?? []) as $f) {
        $id = 'ef-' . substr(md5(json_encode($props['fields']) . ($props['title'] ?? '') . $theme . $f['name']), 0, 6) . '-' . e($f['name']);
        $req = !empty($f['required']);
        $label = '<label for="' . $id . '" class="form-label' . ($compact ? ' sr-only' : '') . '">' . e($f['label']) . ($req ? '<span class="form-required"> *</span>' : '') . '</label>';
        $ph = $f['placeholder'] ?? ($compact ? $f['label'] . ($req ? ' *' : '') : '');
        $common = 'id="' . $id . '" name="' . e($f['name']) . '"' . ($req ? ' required' : '') . (!empty($f['maxLength']) ? ' maxlength="' . (int) $f['maxLength'] . '"' : '') . (!empty($f['pattern']) ? ' pattern="' . e($f['pattern']) . '"' : '');
        $type = $f['type'] ?? 'text';
        $html .= '<div class="' . (($f['col'] ?? 'full') === 'half' ? 'col-span-2 sm:col-span-1' : 'col-span-2') . '">';
        if ($type === 'select') {
            $opts = '<option value="">' . e($f['placeholder'] ?? 'Select ' . strtolower($f['label'])) . '</option>';
            foreach (($f['options'] ?? []) as $o) {
                $o = is_array($o) ? $o : ['value' => $o, 'label' => $o];
                $opts .= '<option value="' . e($o['value']) . '"' . (isset($f['value']) && (string) $f['value'] === (string) $o['value'] ? ' selected' : '') . '>' . e($o['label']) . '</option>';
            }
            $html .= $label . '<select ' . $common . ' class="form-input">' . $opts . '</select>';
        } elseif ($type === 'textarea') {
            $html .= $label . '<textarea ' . $common . ' rows="' . (int) ($f['rows'] ?? 4) . '" class="form-input" placeholder="' . e($ph) . '"></textarea>';
        } elseif ($type === 'checkbox') {
            $html .= '<div class="flex items-start gap-2.5"><input ' . $common . ' type="checkbox" value="1" class="form-check mt-0.5"><label for="' . $id . '" class="text-sm">' . e($f['label']) . '</label></div>';
        } else {
            $input = '<input ' . $common . ' type="' . e($type) . '" class="form-input" placeholder="' . e($ph) . '"' . ($type === 'tel' ? ' inputmode="tel"' : '') . (isset($f['value']) ? ' value="' . e($f['value']) . '"' : '') . '>';
            $html .= $label . (!empty($f['prefix']) ? '<div class="input-prefix"><span aria-hidden="true">' . e($f['prefix']) . '</span>' . $input . '</div>' : $input);
        }
        $html .= '<p class="form-error" data-error-for="' . e($f['name']) . '"></p></div>';
    }
    if (!empty($props['consent'])) {
        $cid = 'ef-consent-' . substr(md5(($props['title'] ?? '') . $theme . json_encode($props['fields'] ?? [])), 0, 6);
        $html .= '<div class="col-span-2 flex items-start gap-2.5"><input id="' . $cid . '" name="consent" type="checkbox" value="1" required class="form-check mt-0.5"><label for="' . $cid . '" class="enquiry-consent text-sm">' . e($props['consent']) . '</label></div>';
    }
    $html .= '<div class="col-span-2 pt-1"><button type="submit" class="btn btn-accent btn-shine w-full">' . e($props['submitLabel'] ?? 'Submit Enquiry') . icon('arrow-right', 'h-4 w-4') . '</button><p class="form-message" data-form-message></p></div></form></div>';
    $out = island('EnquiryForm', $props, $html);
    if ($footer !== '' || $wrapId !== '') {
        $out = '<div class="enquiry-stack' . ($footer !== '' ? ' has-footer' : '') . '"' . ($wrapId !== '' ? ' id="' . e($wrapId) . '"' : '') . '>' . $out . ($footer !== '' ? '<div class="enquiry-strip">' . $footer . '</div>' : '') . '</div>';
    }
    return $out;
}

/**
 * Countdown to a real deadline (island Countdown). Returns '' when there is no future deadline (no fake urgency).
 *   echo countdown(site_admissions_deadline(), ['label' => 'Application Closes in']);
 * @param int|null $deadline unix timestamp (default site_admissions_deadline())
 * @param array    $o label (text before the time), variant ('inline' | 'boxes'), seconds (bool, default false)
 */
function countdown(?int $deadline = null, array $o = []): string
{
    $deadline ??= site_admissions_deadline();
    if (!$deadline || $deadline <= time()) {
        return '';
    }
    $diff = $deadline - time();
    $d = intdiv($diff, 86400);
    $h = intdiv($diff % 86400, 3600);
    $m = intdiv($diff % 3600, 60);
    $variant = ($o['variant'] ?? 'inline') === 'boxes' ? 'boxes' : 'inline';
    $props = ['deadline' => date('c', $deadline), 'label' => (string) ($o['label'] ?? ''), 'variant' => $variant, 'seconds' => !empty($o['seconds'])];
    $label = $props['label'] !== '' ? '<span class="font-normal">' . e($props['label']) . '</span> ' : '';
    $fallback = $variant === 'boxes'
        ? '<span class="countdown countdown-boxes">' . $label . '<span class="countdown-box"><b>' . $d . '</b><small>Days</small></span><span class="countdown-box"><b>' . $h . '</b><small>Hours</small></span><span class="countdown-box"><b>' . $m . '</b><small>Mins</small></span></span>'
        : '<span class="countdown">' . $label . '<time datetime="' . e($props['deadline']) . '">' . $d . 'd ' . $h . 'h ' . $m . 'm</time></span>';
    return island('Countdown', $props, $fallback, ['tag' => 'span', 'class' => 'inline-flex']);
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
        ? '<div class="overflow-hidden rounded-[18px] border border-slate-200 bg-white" aria-hidden="true"><span class="skeleton block aspect-[16/10]"></span><span class="block space-y-3 p-5"><span class="skeleton block h-3 w-1/3 rounded"></span><span class="skeleton block h-5 w-11/12 rounded"></span><span class="skeleton block h-3 w-full rounded"></span><span class="skeleton block h-3 w-2/3 rounded"></span></span></div>'
        : '<div class="flex gap-4 rounded-[18px] border border-slate-200 bg-white p-4" aria-hidden="true"><span class="skeleton h-16 w-16 shrink-0 rounded-2xl"></span><span class="flex-1 space-y-2.5 py-1"><span class="skeleton block h-3 w-1/4 rounded"></span><span class="skeleton block h-4 w-4/5 rounded"></span><span class="skeleton block h-3 w-1/2 rounded"></span></span></div>';
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
        $html .= '<li class="step is-reached"><span class="step-icon">' . (!empty($s['icon']) ? icon($s['icon'], 'h-6 w-6') : '<span class="font-display text-lg font-medium">' . ($i + 1) . '</span>') . '</span>'
            . '<span class="step-body"><span class="step-num">STEP ' . sprintf('%02d', $i + 1) . '</span><span class="step-title">' . e($s['title']) . '</span>' . (!empty($s['text']) ? '<span class="step-text">' . e($s['text']) . '</span>' : '') . '</span></li>';
    }
    $html .= '</ol></div>' . (!empty($props['cta']) ? '<a href="' . e($props['cta']['url']) . '" class="btn btn-accent btn-shine mt-8">' . e($props['cta']['label']) . icon('arrow-right', 'h-4 w-4') . '</a>' : '') . '</div>';
    return island('ProgressSteps', $props, $html);
}

/* ============================================================================ Listing view modes */

/** Default view mode for a listing key: setting view_mode_{key} → setting view_mode_default → $fallback. */
function view_mode_default(string $key, string $fallback = 'grid'): string
{
    $v = (string) setting('view_mode_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($key)), '') ?: (string) setting('view_mode_default', '');
    return in_array($v, ['grid', 'list', 'compact', 'detailed'], true) ? $v : $fallback;
}

/**
 * Accessible segmented control (radiogroup with icons) switching a listing between Grid / List / Compact / Detailed.
 * Pair it with view_mode_open($key) … view_mode_close() around the items (the same $key). Persists per key in
 * localStorage (core/viewmode.ts); SSR default from settings (view_mode_default()).
 *   echo view_mode_toggle('programs');   echo view_mode_open('programs');  foreach … '<article class="view-item">…' ;  echo view_mode_close();
 */
function view_mode_toggle(string $key, array $modes = ['grid', 'list', 'compact', 'detailed'], ?string $default = null, string $class = ''): string
{
    $default ??= view_mode_default($key);
    $meta = ['grid' => ['layout-grid', 'Grid view'], 'list' => ['list', 'List view'], 'compact' => ['list-checks', 'Compact view'], 'detailed' => ['panels-top-left', 'Detailed view']];
    $html = '<div class="view-toggle' . ($class !== '' ? ' ' . e($class) : '') . '" role="radiogroup" aria-label="View mode" data-view-toggle="' . e($key) . '" data-view-default="' . e($default) . '">';
    foreach ($modes as $m) {
        if (!isset($meta[$m])) {
            continue;
        }
        $on = $m === $default;
        $html .= '<button type="button" role="radio" class="view-toggle-btn' . ($on ? ' is-active' : '') . '" data-view-mode="' . $m . '" aria-checked="' . ($on ? 'true' : 'false') . '" tabindex="' . ($on ? '0' : '-1') . '" title="' . e($meta[$m][1]) . '">'
            . icon($meta[$m][0], 'h-4 w-4') . '<span class="sr-only sm:not-sr-only sm:ml-1.5">' . e(ucfirst($m)) . '</span></button>';
    }
    return $html . '</div>';
}

/**
 * Open a view-mode container: `.view-host` with data-view + an inline bootstrap that applies the visitor's saved mode
 * before the items are parsed (no layout shift). Items: `.view-item` with optional parts `.vm-media` (image),
 * `.vm-body`, `.vm-text` (hidden in compact) and `.vm-extra` (only in detailed).
 * @param array $o default (mode), class, id, tag (div|ul)
 */
function view_mode_open(string $key, array $o = []): string
{
    $mode = $o['default'] ?? view_mode_default($key);
    $tag = in_array($o['tag'] ?? 'div', ['div', 'ul', 'ol', 'section'], true) ? ($o['tag'] ?? 'div') : 'div';
    $GLOBALS['__view_mode_tags'][] = $tag;
    $js = '(function(e){try{var v=localStorage.getItem(' . js_json('gimt.view.' . $key) . ');if(/^(grid|list|compact|detailed)$/.test(v||""))e.setAttribute("data-view",v)}catch(x){}})(document.currentScript.parentNode)';
    return '<' . $tag . ' class="view-host' . (!empty($o['class']) ? ' ' . e($o['class']) : '') . '"' . (!empty($o['id']) ? ' id="' . e($o['id']) . '"' : '')
        . ' data-view="' . e($mode) . '" data-view-key="' . e($key) . '"><script>' . $js . '</script>';
}

function view_mode_close(): string
{
    $tag = array_pop($GLOBALS['__view_mode_tags']) ?? 'div';
    return '</' . $tag . '>';
}
