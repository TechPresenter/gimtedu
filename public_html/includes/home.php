<?php
/**
 * Home page & insights module (M7).
 *
 * Public section types (Admin > Pages > Sections):
 *   hero_slider      full-width hero carousel (uses the [data-slider] script in script.js)
 *   impact_counters  LIVE figures from verified records only (no typed-in numbers)
 *   trust_strip      icon + title + text trust markers
 *   marquee_cta      slim band: message, optional moving list of focus areas, button
 *
 * Shared helpers for the admin insight pages (dashboard, analytics, marketing, reports, security centre,
 * audit log, backups): date series for charts, traffic-source SQL, health checks, database backup
 * and data-retention clean-up.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

// =====================================================================
// HOME PAGE: live verified counters
// =====================================================================

/**
 * Live totals for the public counters. Every number is calculated from verified records:
 *  - raised / donations: donations with status = 'success' (verified with the payment gateway or recorded offline by staff)
 *  - volunteers: volunteer & teacher applications marked "active" by a coordinator, plus users holding the
 *    coordinator-assigned Volunteer Teacher role (de-duplicated by email). Self-selected roles are not counted.
 *  - events: published events that have already taken place (awareness days excluded)
 *  - campaigns: campaigns that are running or completed
 */
function home_impact_counters(bool $refresh = false): array
{
    static $counters = null;
    if ($counters !== null && !$refresh) {
        return $counters;
    }
    $counters = ['raised' => 0.0, 'donations' => 0, 'volunteers' => 0, 'events' => 0, 'campaigns' => 0];
    try {
        $row = db_row("SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS n FROM donations WHERE status = 'success'");
        $counters['raised'] = (float) ($row['total'] ?? 0);
        $counters['donations'] = (int) ($row['n'] ?? 0);
        $counters['volunteers'] = (int) db_value("SELECT COUNT(*) FROM (
                SELECT LOWER(email) AS e FROM volunteer_applications WHERE status = 'active'
                UNION SELECT LOWER(email) FROM teacher_applications WHERE status = 'active'
                UNION SELECT LOWER(u.email) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                      WHERE r.slug = 'volunteer_teacher' AND u.status = 'active'
            ) people");
        $counters['events'] = (int) db_value("SELECT COUNT(*) FROM events WHERE status = 'published' AND event_type <> 'awareness_day'
                                               AND COALESCE(end_datetime, start_datetime) < NOW()");
        $counters['campaigns'] = (int) db_value("SELECT COUNT(*) FROM campaigns WHERE status IN ('active','completed')");
    } catch (Throwable $e) {
        error_log('Impact counters failed: ' . $e->getMessage());
    }
    return $counters;
}

/** Escape text and wrap Devanagari runs in <span class="hindi" lang="hi"> (correct font, no letter-spacing). */
function home_mixed_text(string $text): string
{
    $html = e($text);
    return (string) preg_replace('/([\x{0900}-\x{097F}][\x{0900}-\x{097F}\s\x{200C}\x{200D}]*[\x{0900}-\x{097F}]|[\x{0900}-\x{097F}])/u', '<span class="hindi" lang="hi">$1</span>', $html);
}

/**
 * Hero title. "मिलकर बनाये बेहतर कल – Together, ..." becomes a gold Hindi line above the English headline.
 */
function home_hero_title(string $title): string
{
    $parts = preg_split('/\s+[–—-]\s+/u', trim($title), 2);
    if (count($parts) === 2 && preg_match('/^[\x{0900}-\x{097F}\s\x{200C}\x{200D}]+$/u', $parts[0])) {
        return '<span class="hero-hindi hindi" lang="hi">' . e($parts[0]) . '</span><span class="sr-only"> – </span><span class="hero-title-en">' . home_mixed_text($parts[1]) . '</span>';
    }
    return home_mixed_text($title);
}

/** Default slide used when the home page has no hero section (fresh install or removed by an editor). */
function home_default_slides(): array
{
    return [[
        'eyebrow' => site_name(),
        'title' => trim(setting('site_tagline', 'मिलकर बनाये बेहतर कल') . ' – ' . setting('site_tagline_en', 'Together, let us build a better tomorrow'), ' –'),
        'text' => setting('site_description'),
        'image' => 'assets/images/hero/community.svg',
        'button1_label' => 'Donate Now', 'button1_url' => '/donate/',
        'button2_label' => 'Become a Volunteer', 'button2_url' => '/become-a-volunteer/',
    ]];
}

/** Decorative animated background (SVG shapes). Moves with the scroll (data-parallax) unless motion is reduced. */
function home_hero_shapes(): string
{
    return '<div class="home-hero-bg" aria-hidden="true">'
        . '<div class="home-hero-shapes" data-parallax="0.12">'
        . '<svg class="hero-shape hero-shape-ring" viewBox="0 0 200 200" width="200" height="200" focusable="false"><circle cx="100" cy="100" r="80" fill="none" stroke="currentColor" stroke-width="1.5" stroke-dasharray="4 10"/><circle cx="100" cy="100" r="56" fill="none" stroke="currentColor" stroke-width="1" opacity=".6"/></svg>'
        . '<svg class="hero-shape hero-shape-blob" viewBox="0 0 200 200" width="200" height="200" focusable="false"><path fill="currentColor" d="M44.7,-58.3C57.1,-49.4,66,-35.4,70.6,-19.8C75.2,-4.2,75.5,13,68.7,26.8C61.9,40.6,48,51,33,58.6C18,66.2,1.9,71,-15.2,69.7C-32.3,68.4,-50.4,61,-60.8,47.6C-71.2,34.2,-73.9,14.8,-70.5,-2.6C-67.1,-20,-57.6,-35.4,-44.6,-44.3C-31.6,-53.2,-15.8,-55.6,0.4,-56.1C16.6,-56.6,32.3,-67.2,44.7,-58.3Z" transform="translate(100 100)"/></svg>'
        . '<svg class="hero-shape hero-shape-dots" viewBox="0 0 120 120" width="120" height="120" focusable="false"><g fill="currentColor">'
        . implode('', array_map(function ($i) {
            return '<circle cx="' . (10 + ($i % 6) * 20) . '" cy="' . (10 + intdiv($i, 6) * 20) . '" r="2"/>';
        }, range(0, 35)))
        . '</g></svg>'
        . '<svg class="hero-shape hero-shape-leaf" viewBox="0 0 24 24" width="64" height="64" focusable="false"><path fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/></svg>'
        . '<svg class="hero-shape hero-shape-heart" viewBox="0 0 24 24" width="44" height="44" focusable="false"><path fill="currentColor" d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>'
        . '<span class="hero-shape hero-orb hero-orb-gold"></span><span class="hero-shape hero-orb hero-orb-blue"></span>'
        . '</div><span class="home-hero-grid"></span></div>';
}

/** Glass card with the foundation's own slogan (from Admin > Settings). */
function home_slogan_card(): string
{
    $parts = array_values(array_filter(array_map('trim', preg_split('/\s*[•·|]\s*/u', setting('brand_slogan')) ?: [])));
    if (!$parts) {
        return '';
    }
    $icons = ['users', 'heart', 'trending-up', 'star', 'leaf'];
    $html = '<div class="home-glass-card">';
    if (setting('brand_slogan_hi') !== '') {
        $html .= '<p class="glass-hindi hindi" lang="hi">' . e(setting('brand_slogan_hi')) . '</p>';
    }
    $html .= '<ul>';
    foreach (array_slice($parts, 0, 4) as $i => $part) {
        $html .= '<li><span class="glass-icon">' . icon($icons[$i % count($icons)]) . '</span>' . e($part) . '</li>';
    }
    return $html . '</ul></div>';
}

/** Fallback right-side illustration (used when a slide has no image or its file is missing). */
const HOME_HERO_FALLBACK_IMAGE = 'assets/images/hero/community.svg';

/**
 * Right-side image of a hero slide. Photos (or image_style "photo") fill a fixed 4:3 rounded frame
 * (3:2 on phones) with object-fit: cover, so the box is reserved before the file loads (no layout shift);
 * SVG illustrations (or image_style "illustration") are shown whole, as before. The first slide loads
 * eagerly with high priority, later slides lazily. Uploaded photos get srcset (640/1080 px copies).
 */
function home_hero_figure(array $sl, bool $first): string
{
    $image = trim((string) ($sl['image'] ?? ''));
    $usable = $image !== '' && strpos($image, '..') === false && preg_match('#^(uploads|assets/images)/#', $image)
        && is_file(ROOT_PATH . '/' . $image);
    $alt = $usable ? trim((string) ($sl['image_alt'] ?? '')) : '';
    if (!$usable) {
        $image = HOME_HERO_FALLBACK_IMAGE;
    }
    $isSvg = (bool) preg_match('/\.svg$/i', $image);
    $style = (string) ($sl['image_style'] ?? '');
    $isPhoto = $usable && ($style === 'photo' || ($style !== 'illustration' && !$isSvg));
    [$w, $h] = [480, 360];
    if (!$isSvg && ($size = @getimagesize(ROOT_PATH . '/' . $image))) {
        [$w, $h] = [(int) $size[0], (int) $size[1]];
    }
    $attrs = ' width="' . $w . '" height="' . $h . '"'
        . ($first ? ' loading="eager" fetchpriority="high"' : ' loading="lazy"') . ' decoding="async"';
    if ($isPhoto) {
        $positions = ['top' => 'center top', 'bottom' => 'center bottom', 'left' => 'left center', 'right' => 'right center'];
        $pos = $positions[(string) ($sl['image_position'] ?? '')] ?? '';
        $attrs .= image_srcset_attr($image, '(max-width: 767px) calc(100vw - 32px), (max-width: 991px) 460px, 520px')
            . ($pos !== '' ? ' style="object-position:' . $pos . '"' : '');
    }
    return '<div class="home-hero-figure' . ($isPhoto ? ' is-photo' : '') . '">'
        . '<img src="' . e(url($image)) . '" alt="' . e($alt) . '"' . $attrs . '></div>';
}

/** Full hero markup. The first slide's title is the page's single <h1> on the home page. */
function home_hero_html(array $slides, array $set, array $page): string
{
    $count = count($slides);
    $useH1 = ($page['template'] ?? '') === 'home' && empty($GLOBALS['home_h1_done']);
    $card = empty($set['hide_card']) ? home_slogan_card() : '';
    $html = '<div class="hero home-hero' . ($count > 1 ? ' has-slides' : '') . '"' . ($count > 1 ? ' data-slider' : '')
        . ' aria-roledescription="carousel" aria-label="' . e(($set['label'] ?? '') ?: 'Highlights from our work') . '">'
        . home_hero_shapes() . '<div class="container hero-track">';
    foreach ($slides as $i => $sl) {
        $tag = ($i === 0 && $useH1) ? 'h1' : 'h2';
        $buttons = [];
        foreach ([1, 2] as $n) {
            $label = trim((string) ($sl['button' . $n . '_label'] ?? ''));
            $href = trim((string) ($sl['button' . $n . '_url'] ?? ''));
            if ($label !== '' && $href !== '' && $href !== '#') {
                $isDonate = stripos($href, 'donate') !== false;
                $buttons[] = ['label' => $label, 'url' => $href, 'style' => $n === 1 ? 'donate' : 'light', 'size' => 'lg',
                    'icon' => $isDonate ? 'heart' : ($n === 1 ? 'arrow-right' : '')];
            }
        }
        $html .= '<div class="hero-slide' . ($i === 0 ? ' is-active' : '') . '" role="group" aria-roledescription="slide" aria-label="' . ($i + 1) . ' of ' . $count . '"'
            . ($i === 0 ? '' : ' aria-hidden="true"') . '>'
            . '<div class="hero-content">'
            . (!empty($sl['eyebrow']) ? '<span class="hero-eyebrow"><span class="eyebrow-dot" aria-hidden="true"></span>' . home_mixed_text((string) $sl['eyebrow']) . '</span>' : '')
            . '<' . $tag . ' class="hero-title">' . home_hero_title((string) $sl['title']) . '</' . $tag . '>'
            . (!empty($sl['text']) ? '<p class="hero-text">' . home_mixed_text((string) $sl['text']) . '</p>' : '')
            . ($buttons ? ui_buttons($buttons, 'hero-actions') : '')
            . '</div>'
            . '<div class="hero-art">' . home_hero_figure($sl, $i === 0) . $card . '</div>'
            . '</div>';
    }
    $html .= '</div>';
    if ($count > 1) {
        $html .= '<button class="hero-arrow hero-prev" type="button" aria-label="Previous slide">' . icon('chevron-left') . '</button>'
            . '<button class="hero-arrow hero-next" type="button" aria-label="Next slide">' . icon('chevron-right') . '</button>'
            . '<div class="hero-dots"></div>';
    }
    if ($useH1) {
        $GLOBALS['home_h1_done'] = true;
    }
    return $html . '</div>';
}

// =====================================================================
// HOME PAGE: section types
// =====================================================================

function section_schema_hero_slider(): array
{
    return [
        'label' => 'Hero slider (home page)',
        'fields' => [],
        'items_title' => 'Slides (text on the left, image on the right)',
        'item_label' => 'Slide',
        'items' => [
            'eyebrow' => ['text', 'Small heading above the title'],
            'title' => ['text', 'Title (use "हिंदी – English" to show a Hindi line above the English title)'],
            'text' => ['textarea', 'Text'],
            'image' => ['image', 'Right-side image (photo or illustration)',
                'Shown on the right of the slide on computers and below the text on phones. Recommended: a landscape photo of 1200 × 900 px (4:3), JPG, PNG or WebP, up to ' . (int) MAX_UPLOAD_MB . ' MB - it is cropped to fill a rounded frame. SVG illustrations are shown whole. Publish photos of identifiable people only with their consent (a parent\'s or guardian\'s for children). Remove the image to show the default illustration.'],
            'image_alt' => ['text', 'Image description (alt text)', 'What the photo shows, for people using screen readers - e.g. "Volunteers teaching children at a community learning circle". Leave empty for decorative illustrations.'],
            'image_style' => ['select', 'Image style', ['' => 'Auto - photos fill the frame, SVG illustrations fit', 'photo' => 'Photo - fill the rounded frame (cropped)', 'illustration' => 'Illustration - show the whole image, no frame'],
                'Choose "Illustration" for a PNG drawing with a transparent background.'],
            'image_position' => ['select', 'Photo focus (the part kept when the photo is cropped)', ['' => 'Centre', 'top' => 'Top (e.g. faces near the top)', 'bottom' => 'Bottom', 'left' => 'Left', 'right' => 'Right']],
            'button1_label' => ['text', 'Main button text'],
            'button1_url' => ['url', 'Main button link'],
            'button2_label' => ['text', 'Second button text'],
            'button2_url' => ['url', 'Second button link'],
        ],
        'settings' => [
            'label' => ['text', 'Slider name for screen readers (e.g. Highlights from our work)'],
            'hide_card' => ['checkbox', 'Hide the slogan card on the illustration'],
        ],
        'help' => 'Full-width banner at the top of the page. Each slide has text on the left and a right-side image: upload a photo, choose one from the Media Library or remove it to show the default illustration. The first slide\'s title is the page\'s main heading. Slides change every few seconds (paused on hover, never for visitors who prefer reduced motion). Publish identifiable photos of people only with their consent.',
    ];
}

function section_hero_slider(array $s, array $items, array $set, array $page): string
{
    $slides = array_values(array_filter($items, function ($i) {
        return is_array($i) && trim((string) ($i['title'] ?? '')) !== '';
    }));
    return $slides ? home_hero_html($slides, $set, $page) : '';
}

/**
 * Admin shortcut data for the home page hero slider (dashboard card, page editor callout):
 * ['href' => 'page-edit.php?…' (relative to admin/), 'exists', 'hidden', 'slides', 'photos', 'thumbs' => [[url, isPhoto, alt]]].
 * Null when there is no home page.
 */
function home_hero_admin_summary(): ?array
{
    try {
        $homeId = (int) db_value("SELECT id FROM pages WHERE slug = 'home' LIMIT 1");
        $row = $homeId ? db_row("SELECT id, items, status FROM page_sections WHERE page_id = ? AND section_type = 'hero_slider'
                                 ORDER BY (status = 'visible') DESC, sort_order, id LIMIT 1", [$homeId]) : null;
    } catch (Throwable $e) {
        return null;
    }
    if (!$homeId) {
        return null;
    }
    $out = ['page_id' => $homeId, 'exists' => (bool) $row, 'hidden' => $row && $row['status'] !== 'visible', 'slides' => 0, 'photos' => 0, 'thumbs' => [],
        'href' => 'page-edit.php?id=' . $homeId . '&tab=sections' . ($row ? '&open=' . (int) $row['id'] . '#section-' . (int) $row['id'] : '')];
    foreach ($row ? json_field($row['items']) : [] as $sl) {
        if (!is_array($sl) || trim((string) ($sl['title'] ?? '')) === '') {
            continue;
        }
        $out['slides']++;
        $img = trim((string) ($sl['image'] ?? ''));
        $usable = $img !== '' && strpos($img, '..') === false && preg_match('#^(uploads|assets/images)/#', $img) && is_file(ROOT_PATH . '/' . $img);
        $img = $usable ? $img : HOME_HERO_FALLBACK_IMAGE;
        $style = (string) ($sl['image_style'] ?? '');
        $isPhoto = $usable && ($style === 'photo' || ($style !== 'illustration' && !preg_match('/\.svg$/i', $img)));
        $out['photos'] += $isPhoto ? 1 : 0;
        $small = function_exists('image_variant_path') ? image_variant_path($img, 640) : '';
        $out['thumbs'][] = [url($small !== '' && is_file(ROOT_PATH . '/' . $small) ? $small : $img), $isPhoto];
    }
    return $out;
}

function section_schema_impact_counters(): array
{
    return [
        'label' => 'Live impact counters (verified records)',
        'fields' => ['pill', 'title', 'intro'],
        'items' => null,
        'settings' => [
            'hide_raised' => ['checkbox', 'Hide: amount received through verified donations'],
            'hide_donations' => ['checkbox', 'Hide: number of verified donations'],
            'hide_volunteers' => ['checkbox', 'Hide: active volunteers & volunteer teachers'],
            'hide_events' => ['checkbox', 'Hide: events held'],
            'hide_campaigns' => ['checkbox', 'Hide: campaigns run'],
            'note' => ['text', 'Note under the figures (default: Live figures from verified records)'],
        ],
        'help' => 'Numbers are calculated automatically from the database and cannot be typed in: only successful (verified) donations, applications marked active, published past events and active/completed campaigns are counted. A figure that is zero is hidden; if every figure is zero the whole section is hidden.',
    ];
}

function section_impact_counters(array $s, array $items, array $set, array $page): string
{
    $c = home_impact_counters();
    $defs = [
        'raised' => ['rupee', 'Received through verified donations'],
        'donations' => ['heart', 'Verified donations'],
        'volunteers' => ['users', 'Active volunteers & volunteer teachers'],
        'events' => ['calendar', 'Events held'],
        'campaigns' => ['flag', 'Campaigns run'],
    ];
    $cards = [];
    $hasMoney = false;
    foreach ($defs as $key => [$ic, $label]) {
        if (!empty($set['hide_' . $key]) || $c[$key] <= 0) {
            continue;
        }
        $isMoney = $key === 'raised';
        $value = $isMoney ? (int) round($c[$key]) : (int) $c[$key];
        $text = $isMoney ? money($c[$key]) : number_format($value);
        $hasMoney = $hasMoney || $isMoney;
        $cards[] = '<div class="stat impact-stat reveal tilt' . ($isMoney ? ' is-money' : '') . '" style="--d:' . count($cards) . '">'
            . '<span class="stat-icon">' . icon($ic) . '</span>'
            . '<strong data-count="' . $value . '"' . ($isMoney ? ' data-prefix="₹"' : '') . ' data-format="' . e($text) . '">' . e($text) . '</strong>'
            . '<span>' . e($label) . '</span></div>';
    }
    if (!$cards) {
        return '';
    }
    $note = trim((string) ($set['note'] ?? '')) ?: 'Live figures from verified records';
    $html = '<div class="impact-grid impact-cols-' . count($cards) . ($hasMoney ? ' has-money' : '') . '">' . implode('', $cards) . '</div>'
        . '<p class="impact-note">' . icon('shield') . '<span>' . e($note) . '</span>';
    $transparency = get_page('financial-transparency');
    if ($transparency) {
        $html .= '<a href="' . e(page_url('financial-transparency')) . '">How we report donations ' . icon('arrow-right') . '</a>';
    }
    return $html . '</p>';
}

function section_schema_trust_strip(): array
{
    return [
        'label' => 'Trust strip (icon, title, text)',
        'fields' => ['pill', 'title', 'intro'],
        'items' => [
            'icon' => ['icon', 'Icon'],
            'title' => ['text', 'Title'],
            'text' => ['textarea', 'Short text'],
            'link' => ['url', 'Link (optional)'],
            'link_label' => ['text', 'Link text'],
        ],
        'settings' => ['style' => ['select', 'Style', ['' => 'Cards', 'band' => 'Compact band']]],
        'help' => 'Short, factual trust markers (e.g. secure payments, receipts, published policies, data protection). Only describe what the foundation really does.',
    ];
}

function section_trust_strip(array $s, array $items, array $set, array $page): string
{
    $items = array_values(array_filter($items, function ($i) {
        return is_array($i) && trim((string) ($i['title'] ?? '')) !== '';
    }));
    if (!$items) {
        return '';
    }
    $html = '<div class="trust-strip' . (($set['style'] ?? '') === 'band' ? ' trust-band' : '') . '" style="--cols:' . min(4, count($items)) . '">';
    foreach ($items as $i => $it) {
        $html .= '<div class="trust-item reveal" style="--d:' . ($i % 4) . '"><span class="trust-icon">' . icon((string) ($it['icon'] ?? 'shield')) . '</span>'
            . '<div><h3>' . e($it['title']) . '</h3>' . (!empty($it['text']) ? '<p>' . e($it['text']) . '</p>' : '')
            . (!empty($it['link']) ? '<a class="link-arrow" href="' . e(safe_link((string) $it['link'])) . '">' . e(($it['link_label'] ?? '') ?: 'Learn more') . ' ' . icon('arrow-right') . '</a>' : '')
            . '</div></div>';
    }
    return $html . '</div>';
}

function section_schema_marquee_cta(): array
{
    return [
        'label' => 'Slim message band with button',
        'fields' => [],
        'items' => 'strings',
        'settings' => [
            'message' => ['text', 'Message'],
            'icon' => ['icon', 'Icon'],
            'tone' => ['select', 'Colour', ['gold' => 'Gold', 'green' => 'Green', 'white' => 'White']],
            'buttons' => buttons_setting(),
        ],
        'help' => 'Items (optional) are shown as a slowly moving list, e.g. the foundation\'s areas of work. Visitors can pause it, and it does not move for people who prefer reduced motion.',
    ];
}

function section_marquee_cta(array $s, array $items, array $set, array $page): string
{
    $message = trim((string) ($set['message'] ?? '')) ?: trim((string) ($s['intro'] ?? ''));
    $words = array_values(array_filter(array_map(function ($i) {
        return trim(is_array($i) ? (string) ($i['text'] ?? $i['title'] ?? '') : (string) $i);
    }, $items)));
    $buttons = array_values(array_filter((array) ($set['buttons'] ?? []), function ($b) {
        return is_array($b) && trim((string) ($b['label'] ?? '')) !== '' && trim((string) ($b['url'] ?? '')) !== '';
    }));
    if ($message === '' && !$words) {
        return '';
    }
    $tone = in_array($set['tone'] ?? '', ['gold', 'green', 'white'], true) ? $set['tone'] : 'gold';
    $html = '<div class="marquee-cta tone-' . $tone . '"><div class="container marquee-inner">';
    if ($message !== '') {
        $html .= '<p class="marquee-msg"><span class="marquee-icon">' . icon((string) (($set['icon'] ?? '') ?: 'heart')) . '</span><span>' . home_mixed_text($message) . '</span></p>';
    }
    if ($words) {
        $id = 'mq-' . (int) ($s['id'] ?? 0);
        $list = '';
        foreach ($words as $w) {
            $list .= '<li>' . home_mixed_text($w) . '</li>';
        }
        $html .= '<div class="marquee">'
            . '<input type="checkbox" class="marquee-pause" id="' . e($id) . '">'
            . '<label for="' . e($id) . '" class="marquee-toggle" title="Pause / play"><span class="sr-only">Pause the moving list</span>'
            . '<svg class="icon mq-pause" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><rect x="6" y="5" width="4" height="14" rx="1" fill="currentColor"/><rect x="14" y="5" width="4" height="14" rx="1" fill="currentColor"/></svg>'
            . '<svg class="icon mq-play" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M8 5v14l11-7z" fill="currentColor"/></svg></label>'
            . '<div class="marquee-viewport"><div class="marquee-track"><ul class="marquee-list">' . $list . '</ul>'
            . '<ul class="marquee-list" aria-hidden="true">' . $list . '</ul></div></div></div>';
    }
    if ($buttons) {
        foreach ($buttons as &$b) {
            $b['size'] = $b['size'] ?? 'sm';
            $b['style'] = $b['style'] ?? ($tone === 'gold' ? 'primary' : 'donate');
        }
        unset($b);
        $html .= ui_buttons($buttons, 'marquee-actions');
    }
    return $html . '</div></div>';
}

// =====================================================================
// ADMIN INSIGHTS: shared helpers (used only by admin pages)
// =====================================================================

/** SQL bounds for an admin_date_range() array: ['Y-m-d 00:00:00', 'Y-m-d 23:59:59']. */
function insights_bounds(array $range): array
{
    return [$range['from'] . ' 00:00:00', $range['to'] . ' 23:59:59'];
}

/** Chart grouping for a range: 'day' for up to 31 days, otherwise 'month'. */
function insights_group(array $range): string
{
    $days = (int) round((strtotime($range['to']) - strtotime($range['from'])) / 86400) + 1;
    return $days <= 31 ? 'day' : 'month';
}

/** SQL expression that groups a (hard-coded) datetime column by day or month. */
function insights_group_sql(string $column, string $mode): string
{
    return $mode === 'day' ? "DATE_FORMAT($column, '%Y-%m-%d')" : "DATE_FORMAT($column, '%Y-%m')";
}

/**
 * Turn [periodKey => value] into a complete chart series [label => value] with zero periods filled in.
 * Monthly series start at the first month with data (so "All time" does not draw decades of empty bars)
 * and switch to years when longer than three years. Returns [] when every value is zero.
 */
function insights_series(array $pairs, array $range, string $mode): array
{
    $out = [];
    if ($mode === 'day') {
        $end = strtotime($range['to']);
        for ($t = strtotime($range['from']); $t <= $end; $t = strtotime('+1 day', $t)) {
            $out[date('d M', $t)] = (float) ($pairs[date('Y-m-d', $t)] ?? 0);
        }
        return array_filter($out) ? $out : [];
    }
    if (!$pairs || !array_filter($pairs)) {
        return [];
    }
    $start = strtotime(date('Y-m-01', strtotime($range['from'])));
    $first = strtotime(min(array_keys(array_filter($pairs))) . '-01');
    if ($first > $start) {
        $start = $first;
    }
    $end = strtotime(date('Y-m-01', strtotime($range['to'])));
    for ($t = $start; $t <= $end; $t = strtotime('+1 month', $t)) {
        $out[date('M y', $t)] = (float) ($pairs[date('Y-m', $t)] ?? 0);
    }
    if (count($out) > 36) {
        $out = [];
        foreach ($pairs as $k => $v) {
            $y = substr((string) $k, 0, 4);
            $out[$y] = ($out[$y] ?? 0) + (float) $v;
        }
        ksort($out);
    }
    return $out;
}

/** Bar chart (admin_bar_chart) with compact labels when there are many bars. */
function insights_chart(array $data, bool $money = false, string $label = ''): string
{
    if (!function_exists('admin_bar_chart')) {
        return '';
    }
    $chart = admin_bar_chart($data, $money);
    if ($label !== '') {
        $chart = str_replace('aria-label="Bar chart"', 'aria-label="' . e($label) . '"', $chart);
    }
    $dense = count($data) > 14;
    // a wide chart scrolls: make the scroll area keyboard-reachable and add a one-line text summary of real values
    $html = '<div class="insights-chart' . ($dense ? ' chart-dense' : '') . '"' . ($dense ? ' tabindex="0" role="region" aria-label="' . e(($label !== '' ? $label : 'Chart') . ' (scrollable)') . '"' : '') . '>' . $chart . '</div>';
    if ($dense && $data) {
        $fmt = function ($v) use ($money): string {
            return $money ? money($v) : number_format((float) $v);
        };
        $total = array_sum(array_map('floatval', $data));
        if ($total <= 0) {
            $html .= '<p class="chart-summary small muted">No activity in this period.</p>';
        } else {
            $peakLabel = array_search(max(array_map('floatval', $data)), array_map('floatval', $data), true);
            $lastLabel = array_key_last($data);
            $html .= '<p class="chart-summary small muted">Total ' . e($fmt($total)) . ' · Highest: ' . e($fmt($data[$peakLabel])) . ' on ' . e((string) $peakLabel)
                . ' · Latest (' . e((string) $lastLabel) . '): ' . e($fmt($data[$lastLabel])) . '</p>';
        }
    }
    return $html;
}

/** Percentage text. */
function insights_pct(float $part, float $whole, int $decimals = 1): string
{
    return $whole > 0 ? number_format($part / $whole * 100, $decimals) . '%' : '–';
}

/** Traffic source of a page view / conversion: UTM source, else referring site, else (direct). */
function insights_source_sql(string $alias = ''): string
{
    $p = $alias !== '' ? $alias . '.' : '';
    return "COALESCE(NULLIF({$p}utm_source, ''), NULLIF({$p}referrer_host, ''), '(direct)')";
}

/** Traffic medium: UTM medium, else "referral" for other websites, else (none). */
function insights_medium_sql(string $alias = ''): string
{
    $p = $alias !== '' ? $alias . '.' : '';
    return "COALESCE(NULLIF({$p}utm_medium, ''), IF(COALESCE({$p}referrer_host, '') = '', '(none)', 'referral'))";
}

/** Conversion types recorded by track_conversion(), with readable labels. */
function insights_conversion_labels(): array
{
    return [
        'donation_started' => 'Donation checkout started', 'donation_success' => 'Verified donation',
        'volunteer_application' => 'Volunteer application', 'teacher_application' => 'Teacher application',
        'staff_application' => 'Staff application', 'internship_application' => 'Internship application',
        'membership_application' => 'Membership application', 'scholarship_application' => 'Scholarship application',
        'help_request' => 'Help request', 'registration' => 'Account registration', 'newsletter_signup' => 'Newsletter sign-up',
        'event_registration' => 'Event registration', 'contact_enquiry' => 'Contact enquiry', 'fundraiser_proposal' => 'Fundraiser proposal',
    ];
}

/** Mask anything that looks like a secret (long hex tokens, "password=...") before showing log details. */
function insights_scrub(?string $text): string
{
    $text = (string) $text;
    $text = (string) preg_replace('/\b[a-f0-9]{32,}\b/i', '[hidden]', $text);
    $text = (string) preg_replace('/\b(password|passwd|secret|token|otp|api[_-]?key|signature)(\s*[:=]\s*)\S+/i', '$1$2[hidden]', $text);
    return $text;
}

/** [bytes, files] in a folder (recursively), ignoring index.html / .htaccess placeholders. */
function insights_dir_size(string $dir): array
{
    $bytes = 0;
    $files = 0;
    if (!is_dir($dir)) {
        return [0, 0];
    }
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && !in_array($f->getFilename(), ['index.html', '.htaccess'], true)) {
                $bytes += $f->getSize();
                $files++;
            }
        }
    } catch (Throwable $e) {
        error_log('Folder size failed: ' . $e->getMessage());
    }
    return [$bytes, $files];
}

function insights_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $value = (float) $bytes;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) $bytes : number_format($value, 1)) . ' ' . $units[$i];
}

/** Administrators who have not turned on two-step login. */
function insights_admins_without_2fa(): array
{
    return db_rows("SELECT u.id, u.name, u.email FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                    WHERE r.slug = 'administrator' AND u.status = 'active' AND u.totp_enabled = 0 ORDER BY u.name");
}

/** Set-up checklist for administrators on the dashboard. */
function insights_setup_checklist(): array
{
    $logo = setting('logo_path') !== '' || is_file(ROOT_PATH . '/assets/images/logo.png');
    return [
        ['done' => $logo && setting('contact_email') !== '' && setting('contact_phone') !== '', 'label' => 'Logo and contact details',
            'hint' => 'Add the office phone number and email (and upload a new logo if needed).', 'url' => 'settings.php'],
        ['done' => setting('registration_number') !== '' && setting('pan_number') !== '', 'label' => 'Registration details',
            'hint' => 'Enter the verified registration number and PAN (12A/80G/CSR-1 when available). Blank values are never shown publicly.', 'url' => 'settings.php'],
        ['done' => payment_setup_complete(), 'label' => 'Online payments (Razorpay / Cashfree)',
            'hint' => 'Enter the gateway keys (and the Razorpay webhook secret) in Payment Gateways and test the connection.', 'url' => 'payment-gateways.php'],
        ['done' => MAIL_ENABLED && SMTP_HOST !== '', 'label' => 'Email delivery (SMTP)',
            'hint' => MAIL_ENABLED
                ? 'Set SMTP_HOST, SMTP_USER and SMTP_PASS in pwf-config.php so receipts and replies are delivered reliably.'
                : 'Email sending is switched off (MAIL_ENABLED = false in pwf-config.php): no verification links, receipts or replies are delivered. Remove that line, then set SMTP_HOST, SMTP_USER and SMTP_PASS.',
            'url' => 'security.php'],
        ['done' => !insights_admins_without_2fa(), 'label' => 'Two-step login for administrators',
            'hint' => 'Every administrator should turn on two-step login (My Profile).', 'url' => 'profile.php#two-step'],
        ['done' => !is_file(ROOT_PATH . '/install.php'), 'label' => 'Installer removed',
            'hint' => 'Delete install.php from the server after installation.', 'url' => 'security.php'],
    ];
}

/**
 * Security health checks for the Security Centre.
 * Each: ['status' => ok|warn|bad, 'title' => ..., 'detail' => ..., 'fix' => ...]
 */
function security_health_checks(): array
{
    $checks = [];
    $add = function (string $status, string $title, string $detail, string $fix = '') use (&$checks) {
        $checks[] = compact('status', 'title', 'detail', 'fix');
    };
    $httpsBase = stripos(BASE_URL, 'https://') === 0;
    $add($httpsBase ? 'ok' : 'bad', 'Website address uses HTTPS', 'BASE_URL is ' . BASE_URL,
        $httpsBase ? '' : 'Enable the free SSL certificate in hPanel and set BASE_URL to https://… in pwf-config.php.');
    $add(is_https() ? 'ok' : 'warn', 'This request was made over HTTPS', is_https() ? 'Connection is encrypted.' : 'You are viewing the admin panel without HTTPS.',
        is_https() ? '' : 'Force HTTPS in hPanel (Security > SSL > Force HTTPS) so passwords are never sent unencrypted.');
    $add(APP_ENV === 'production' ? 'ok' : 'warn', 'Production mode', 'APP_ENV is "' . APP_ENV . '".',
        APP_ENV === 'production' ? '' : 'Set APP_ENV to production in pwf-config.php on the live website.');
    $display = in_array(strtolower((string) ini_get('display_errors')), ['1', 'on', 'true', 'stdout', 'stderr'], true);
    $add($display ? 'bad' : 'ok', 'Error messages hidden from visitors', $display ? 'display_errors is on: technical errors may be shown publicly.' : 'display_errors is off.',
        $display ? 'Set APP_ENV to production (this switches display_errors off).' : '');
    $private = is_file(dirname(ROOT_PATH) . '/pwf-config.php');
    $add($private ? 'ok' : 'warn', 'Private configuration file outside public_html', $private ? 'pwf-config.php was found one folder above the website.' : 'No pwf-config.php found above public_html.',
        $private ? '' : 'Create ../pwf-config.php (outside public_html) and move the database password and SMTP secrets into it (payment keys can be entered encrypted in Payment Gateways).');
    $add(INSTALL_KEY === '' ? 'ok' : 'warn', 'Installer key cleared', INSTALL_KEY === '' ? 'INSTALL_KEY is empty.' : 'INSTALL_KEY is still set.',
        INSTALL_KEY === '' ? '' : 'After installation, set INSTALL_KEY to an empty string in pwf-config.php.');
    $installer = is_file(ROOT_PATH . '/install.php');
    $add($installer ? 'warn' : 'ok', 'Installer removed', $installer ? 'install.php is still on the server.' : 'install.php has been deleted.',
        $installer ? 'Delete public_html/install.php using the hPanel File Manager.' : '');
    $lock = is_file(ROOT_PATH . '/install.lock');
    $add($lock ? 'ok' : 'warn', 'Installation locked', $lock ? 'install.lock is present.' : 'install.lock was not found.',
        $lock ? '' : 'Complete the installer once (it creates install.lock), or create an empty file named install.lock in public_html.');
    foreach (['uploads/.htaccess' => 'Uploads cannot run scripts', 'uploads/documents/.htaccess' => 'Private documents are not publicly reachable'] as $file => $title) {
        $ok = is_file(ROOT_PATH . '/' . $file);
        $add($ok ? 'ok' : 'bad', $title, $ok ? $file . ' is present.' : $file . ' is missing.', $ok ? '' : 'Upload ' . $file . ' again from the original website package.');
    }
    foreach (payment_health_checks() as [$payStatus, $payTitle, $payDetail, $payFix]) {   // includes/gateways.php
        $add($payStatus, $payTitle, $payDetail, $payFix);
    }
    if (!MAIL_ENABLED) {
        $add('bad', 'Email sending switched on', 'MAIL_ENABLED is false in pwf-config.php, so no email is sent: verification links, password resets, receipts, replies and notifications are not delivered, and new members cannot verify their accounts.',
            'Remove define(\'MAIL_ENABLED\', false) from pwf-config.php (or set it to true). Until then, untick "Require email verification" in Settings or mark new accounts verified in Users.');
    } else {
        $add(SMTP_HOST !== '' ? 'ok' : 'warn', 'Email delivery (SMTP)', SMTP_HOST !== '' ? 'SMTP server ' . SMTP_HOST . ' is configured.' : 'Emails are sent with PHP mail(), which often lands in spam.',
            SMTP_HOST !== '' ? '' : 'Create a mailbox in hPanel and set SMTP_HOST, SMTP_USER and SMTP_PASS in pwf-config.php.');
    }
    $no2fa = insights_admins_without_2fa();
    $add($no2fa ? 'bad' : 'ok', 'Two-step login for administrators', $no2fa ? 'Without two-step login: ' . implode(', ', array_column($no2fa, 'name')) . '.' : 'Every administrator uses two-step login.',
        $no2fa ? 'Each administrator should open My Profile > Two-step login and scan the key with an authenticator app.' : '');
    $mustChange = (int) db_value("SELECT COUNT(*) FROM users WHERE must_change_password = 1 AND status = 'active'");
    $add($mustChange ? 'warn' : 'ok', 'Temporary passwords', $mustChange ? $mustChange . ' account(s) must still choose a new password.' : 'No account is waiting for a password change.',
        $mustChange ? 'Remind these users to sign in and set a new password.' : '');
    $secureCookie = (bool) (session_get_cookie_params()['secure'] ?? false);
    $add($secureCookie ? 'ok' : 'warn', 'Secure session cookie', $secureCookie ? 'The session cookie is only sent over HTTPS.' : 'The session cookie is not marked Secure (the site was opened over HTTP).',
        $secureCookie ? '' : 'Open the website and admin panel only over HTTPS; the Secure flag is then set automatically.');
    return $checks;
}

// =====================================================================
// ADMIN: database backup (streamed .sql)
// =====================================================================

/** Quote a MySQL identifier taken from SHOW TABLES / SHOW COLUMNS. */
function backup_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

/**
 * Stream a complete database dump: DROP + CREATE TABLE and batched multi-row INSERTs, values escaped with PDO::quote.
 * $structureOnly: tables whose rows are skipped (their structure is still included).
 */
function backup_stream_sql(array $structureOnly = [], string $by = ''): void
{
    @set_time_limit(0);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $pdo = db();
    $tables = [];
    foreach (db_rows("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
        $tables[] = (string) array_values($row)[0];
    }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="pwf-backup-' . date('Y-m-d-His') . '.sql"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');

    echo "-- " . site_name() . " - database backup\n"
        . "-- Generated: " . date('Y-m-d H:i:s') . ' (' . TIMEZONE . ')' . ($by !== '' ? ' by ' . str_replace(["\r", "\n"], ' ', $by) : '') . "\n"
        . "-- Tables: " . count($tables) . ($structureOnly ? ' | rows not included for: ' . implode(', ', $structureOnly) : '') . "\n"
        . "-- Restore: create an EMPTY database and import this file (phpMyAdmin > Import).\n"
        . "-- This file contains personal data and password hashes: store it securely and never share it.\n\n"
        . "SET NAMES utf8mb4;\nSET time_zone = '+05:30';\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

    foreach ($tables as $table) {
        $q = backup_ident($table);
        $create = db_row('SHOW CREATE TABLE ' . $q);
        echo "-- --------------------------------------------------------\n-- Table " . $q . "\n"
            . 'DROP TABLE IF EXISTS ' . $q . ";\n" . ($create['Create Table'] ?? '') . ";\n\n";
        if (in_array($table, $structureOnly, true)) {
            continue;
        }
        $columns = array_column(db_rows('SHOW COLUMNS FROM ' . $q), 'Field');
        $keys = array_column(db_rows("SHOW KEYS FROM " . $q . " WHERE Key_name = 'PRIMARY'"), 'Column_name');
        $order = $keys ? ' ORDER BY ' . implode(', ', array_map('backup_ident', $keys)) : '';
        $colList = implode(', ', array_map('backup_ident', $columns));
        $batch = 500;
        for ($offset = 0; ; $offset += $batch) {
            $rows = $pdo->query('SELECT ' . $colList . ' FROM ' . $q . $order . ' LIMIT ' . $batch . ' OFFSET ' . $offset)->fetchAll(PDO::FETCH_NUM);
            if (!$rows) {
                break;
            }
            $values = [];
            foreach ($rows as $r) {
                $values[] = '(' . implode(', ', array_map(function ($v) use ($pdo) {
                    return $v === null ? 'NULL' : $pdo->quote((string) $v);
                }, $r)) . ')';
            }
            echo 'INSERT INTO ' . $q . ' (' . $colList . ") VALUES\n" . implode(",\n", $values) . ";\n";
            flush();
            if (count($rows) < $batch) {
                break;
            }
        }
        echo "\n";
    }
    echo "SET UNIQUE_CHECKS = 1;\nSET FOREIGN_KEY_CHECKS = 1;\n-- End of backup\n";
    exit;
}

// =====================================================================
// ADMIN: data retention
// =====================================================================

/** Retention periods (days) from settings, with safe minimums. */
function retention_days(): array
{
    $get = function (string $key, int $default, int $min): int {
        $v = (int) setting($key, (string) $default);
        return max($min, $v > 0 ? $v : $default);
    };
    return [
        'logs' => $get('retention_logs_days', 365, 90),
        'applications' => $get('retention_rejected_applications_days', 730, 180),
        'enquiries' => $get('retention_enquiries_days', 730, 180),
        'analytics' => $get('analytics_retention_days', 400, 30),
    ];
}

/**
 * Personal fields cleared when a rejected application is anonymised.
 * [table => [type for application_history, name column, SET clause, file columns]]
 */
function retention_application_tables(): array
{
    $common = "email = CONCAT('removed-', id, '@anonymised.invalid'), phone = '', date_of_birth = NULL, gender = NULL, address = NULL, pincode = NULL,
               admin_notes = NULL, info_request = NULL, ip_address = NULL, user_id = NULL";
    $marker = "'Removed (data retention)'";
    return [
        'volunteer_applications' => ['volunteer', 'name', "name = $marker, $common, occupation = NULL, education = NULL, skills = NULL, experience = NULL, motivation = ''", []],
        'teacher_applications' => ['teacher', 'name', "name = $marker, $common, motivation = '', resume_path = NULL", ['resume_path']],
        'staff_applications' => ['staff', 'name', "name = $marker, $common, current_organization = NULL, cover_letter = '', resume_path = NULL", ['resume_path']],
        'internship_applications' => ['internship', 'name', "name = $marker, $common, skills = NULL, motivation = '', resume_path = NULL", ['resume_path']],
        'scholarships' => ['scholarship', 'student_name', "student_name = $marker, guardian_name = $marker, email = CONCAT('removed-', id, '@anonymised.invalid'), phone = '',
            date_of_birth = NULL, gender = NULL, address = '', pincode = NULL, last_exam_percentage = NULL, annual_family_income = 0, reason = '',
            document_path = NULL, admin_notes = NULL, ip_address = NULL, user_id = NULL", ['document_path']],
        'beneficiaries' => ['beneficiary', 'name', "name = $marker, email = NULL, phone = '', gender = NULL, age = NULL, address = '', pincode = NULL,
            family_members = NULL, annual_income = NULL, description = '', document_path = NULL, admin_notes = NULL, ip_address = NULL, user_id = NULL", ['document_path']],
    ];
}

/** What a clean-up would do now: [key => ['label', 'detail', 'count']]. Donations and receipts are never touched. */
function retention_preview(): array
{
    $d = retention_days();
    $apps = 0;
    foreach (retention_application_tables() as $table => [$type, $nameCol]) {
        $apps += (int) db_value("SELECT COUNT(*) FROM `$table` WHERE status = 'rejected' AND `$nameCol` <> 'Removed (data retention)'
                                 AND COALESCE(reviewed_at, updated_at, created_at) < NOW() - INTERVAL ? DAY", [$d['applications']]);
    }
    return [
        'logs' => ['label' => 'Audit & activity log entries', 'detail' => 'Older than ' . $d['logs'] . ' days - deleted',
            'count' => (int) db_value('SELECT COUNT(*) FROM activity_logs WHERE created_at < NOW() - INTERVAL ? DAY', [$d['logs']])],
        'applications' => ['label' => 'Rejected applications & requests', 'detail' => 'Decided more than ' . $d['applications'] . ' days ago - personal details anonymised, uploaded documents deleted (status kept for statistics)',
            'count' => $apps],
        'enquiries' => ['label' => 'Resolved / closed enquiries', 'detail' => 'Older than ' . $d['enquiries'] . ' days - deleted',
            'count' => (int) db_value("SELECT COUNT(*) FROM contact_enquiries WHERE status IN ('resolved','closed') AND COALESCE(updated_at, created_at) < NOW() - INTERVAL ? DAY", [$d['enquiries']])],
        'feedback' => ['label' => 'Resolved / closed feedback', 'detail' => 'Older than ' . $d['enquiries'] . ' days - deleted',
            'count' => (int) db_value("SELECT COUNT(*) FROM feedback WHERE status IN ('actioned','closed') AND COALESCE(updated_at, created_at) < NOW() - INTERVAL ? DAY", [$d['enquiries']])],
        'newsletter' => ['label' => 'Unsubscribed newsletter records', 'detail' => 'More than 30 days after unsubscribing - name and IP address removed (the email address and the opt-out are kept so we never email it again)',
            'count' => (int) db_value("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'unsubscribed' AND (name IS NOT NULL OR ip_address IS NOT NULL)
                                       AND COALESCE(updated_at, created_at) < NOW() - INTERVAL 30 DAY")],
        'analytics' => ['label' => 'Website analytics page views', 'detail' => 'Older than ' . $d['analytics'] . ' days - deleted',
            'count' => (int) db_value('SELECT COUNT(*) FROM page_views WHERE created_at < NOW() - INTERVAL ? DAY', [$d['analytics']])],
        'tokens' => ['label' => 'Expired sign-in tokens', 'detail' => 'Expired remember-me and email verification tokens, password reset links older than 30 days - deleted',
            'count' => (int) db_value('SELECT COUNT(*) FROM remember_tokens WHERE expires_at < NOW()')
                + (int) db_value('SELECT COUNT(*) FROM email_verifications WHERE expires_at < NOW()')
                + (int) db_value('SELECT COUNT(*) FROM password_resets WHERE created_at < NOW() - INTERVAL 30 DAY')],
    ];
}

/**
 * Run the clean-up once a day automatically (from an admin page load) - the privacy policy promises it, so it must not
 * depend on someone remembering to press the button. Returns the run result, or null when it was not due.
 */
function retention_auto_run(): ?array
{
    $last = setting('retention_last_run');
    if ($last !== '' && strtotime($last) > time() - 86400) {
        return null;
    }
    try {
        $done = retention_run();
    } catch (Throwable $e) {
        error_log('Automatic retention clean-up failed: ' . $e->getMessage());
        return null;
    }
    save_setting('retention_last_run', date('Y-m-d H:i:s'));
    if (function_exists('admin_log')) {
        $parts = [];
        foreach ($done as $k => $n) {
            if ($n) {
                $parts[] = $k . ': ' . $n;
            }
        }
        admin_log('retention_cleanup', 'Automatic data clean-up' . ($parts ? ' - ' . implode(', ', $parts) : ' - nothing to remove'));
    }
    return $done;
}

/** Run the clean-up. Returns [key => affected rows]. */
function retention_run(): array
{
    $d = retention_days();
    $done = [];
    $done['logs'] = db_query('DELETE FROM activity_logs WHERE created_at < NOW() - INTERVAL ? DAY', [$d['logs']])->rowCount();
    $done['applications'] = 0;
    foreach (retention_application_tables() as $table => [$type, $nameCol, $set, $files]) {
        $rows = db_rows("SELECT id" . ($files ? ', ' . implode(', ', $files) : '') . " FROM `$table` WHERE status = 'rejected' AND `$nameCol` <> 'Removed (data retention)'
                         AND COALESCE(reviewed_at, updated_at, created_at) < NOW() - INTERVAL ? DAY", [$d['applications']]);
        foreach ($rows as $r) {
            foreach ($files as $col) {
                if (!empty($r[$col])) {
                    delete_upload((string) $r[$col]);
                }
            }
            db_query("UPDATE `$table` SET $set WHERE id = ?", [$r['id']]);
            db_query('UPDATE application_history SET note = NULL WHERE application_type = ? AND application_id = ?', [$type, $r['id']]);
            $done['applications']++;
        }
    }
    $done['enquiries'] = db_query("DELETE FROM contact_enquiries WHERE status IN ('resolved','closed') AND COALESCE(updated_at, created_at) < NOW() - INTERVAL ? DAY", [$d['enquiries']])->rowCount();
    $done['feedback'] = db_query("DELETE FROM feedback WHERE status IN ('actioned','closed') AND COALESCE(updated_at, created_at) < NOW() - INTERVAL ? DAY", [$d['enquiries']])->rowCount();
    $done['newsletter'] = db_query("UPDATE newsletter_subscribers SET name = NULL, ip_address = NULL WHERE status = 'unsubscribed' AND (name IS NOT NULL OR ip_address IS NOT NULL)
                                    AND COALESCE(updated_at, created_at) < NOW() - INTERVAL 30 DAY")->rowCount();
    $done['analytics'] = db_query('DELETE FROM page_views WHERE created_at < NOW() - INTERVAL ? DAY', [$d['analytics']])->rowCount();
    $done['tokens'] = db_query('DELETE FROM remember_tokens WHERE expires_at < NOW()')->rowCount()
        + db_query('DELETE FROM email_verifications WHERE expires_at < NOW()')->rowCount()
        + db_query('DELETE FROM password_resets WHERE created_at < NOW() - INTERVAL 30 DAY')->rowCount();
    return $done;
}

// =====================================================================
// Design / UI migrations (500-599): run once automatically (includes/migrations.php)
// =====================================================================

/** Quick-links strip under the header on phones & tablets: on by default; an admin's choice is never overwritten. */
function migration_501_mobile_nav_strip(): void
{
    add_setting_if_missing('mobile_nav_strip', '1');
}
