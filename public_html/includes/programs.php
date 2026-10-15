<?php
/**
 * Programmes & welfare schemes module (M2).
 *
 * Programmes live in `programs`; each one has a detail page in `pages` (template "program",
 * usually /programs/<slug>/, settings {"program":"<slug>","focus":"<group>"}) whose content is
 * built from page sections. Welfare schemes live in `schemes` and are published only with an
 * official source link and a "source checked on" date.
 *
 * Section types defined here: programs_grid, schemes_list.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

// ---------------------------------------------------------------------
// Programme options (tones, icons, focus groups)
// ---------------------------------------------------------------------

/** Card colours available for programmes. */
function program_tones(): array
{
    return ['green' => 'Green', 'blue' => 'Blue', 'gold' => 'Gold', 'orange' => 'Orange', 'teal' => 'Teal'];
}

/** Icons offered in the admin icon picker (names from icon()). */
function program_icons(): array
{
    return ['book', 'graduation', 'award', 'monitor', 'smartphone', 'heart', 'activity', 'users', 'user', 'star', 'zap',
        'home', 'map-pin', 'leaf', 'sun', 'globe', 'tool', 'briefcase', 'trending-up', 'package', 'gift', 'life-buoy',
        'umbrella', 'shield', 'megaphone', 'flag', 'target', 'layers'];
}

/** Focus groups used to group / filter programmes on the overview page. */
function program_focus_groups(): array
{
    return [
        'learning'     => 'Learning & education',
        'health'       => 'Health & nutrition',
        'empowerment'  => 'Women & youth',
        'livelihoods'  => 'Skills & livelihoods',
        'environment'  => 'Environment & resilience',
        'community'    => 'Community support',
    ];
}

/** Focus group of a programme: page settings "focus", else a sensible default for known programmes. */
function program_focus(array $p): string
{
    $groups = program_focus_groups();
    $focus = (string) (json_field($p['page_settings'] ?? null)['focus'] ?? '');
    if (isset($groups[$focus])) {
        return $focus;
    }
    $defaults = [
        'education-literacy' => 'learning', 'digital-learning' => 'learning', 'scholarship-educational-support' => 'learning',
        'healthcare' => 'health', 'food-distribution' => 'health',
        'women-empowerment' => 'empowerment', 'youth-empowerment' => 'empowerment',
        'skill-development' => 'livelihoods', 'rural-development' => 'livelihoods',
        'environment-protection' => 'environment', 'disaster-relief' => 'environment',
    ];
    return $defaults[$p['slug'] ?? ''] ?? 'community';
}

function program_tone(array $p): string
{
    $tone = (string) ($p['tone'] ?? 'green');
    return isset(program_tones()[$tone]) ? $tone : 'green';
}

/** Key points (JSON array of short strings). */
function program_key_points(array $p): array
{
    return array_values(array_filter(array_map(function ($v) {
        return is_string($v) ? trim($v) : '';
    }, json_field($p['key_points'] ?? null)), 'strlen'));
}

/**
 * Illustration path for a programme: uploaded cover image, else assets/images/programs/<slug>.svg,
 * else the SVG named after the first word of the slug (education-literacy => education.svg), else general.svg.
 */
function program_art(array $p, bool $allowCover = true): string
{
    if ($allowCover && !empty($p['cover_image']) && strpos((string) $p['cover_image'], '..') === false && is_file(ROOT_PATH . '/' . $p['cover_image'])) {
        return (string) $p['cover_image'];
    }
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($p['slug'] ?? '')));
    foreach ([$slug, strtok($slug, '-')] as $name) {
        if ($name && is_file(ROOT_PATH . '/assets/images/programs/' . $name . '.svg')) {
            return 'assets/images/programs/' . $name . '.svg';
        }
    }
    return 'assets/images/programs/general.svg';
}

// ---------------------------------------------------------------------
// Queries
// ---------------------------------------------------------------------

/** SELECT for programmes with their detail page (linked by page_id, or by the slug programs/<slug>). */
function program_select_sql(): string
{
    return "SELECT p.*, COALESCE(pg.slug, pg2.slug) AS page_slug, COALESCE(pg.status, pg2.status) AS page_status,
                   COALESCE(pg.settings, pg2.settings) AS page_settings, COALESCE(pg.id, pg2.id) AS detail_page_id
            FROM programs p
            LEFT JOIN pages pg ON pg.id = p.page_id
            LEFT JOIN pages pg2 ON p.page_id IS NULL AND pg2.slug = CONCAT('programs/', p.slug)";
}

/**
 * All programmes in display order. Public lists ($publishedOnly) contain only published programmes
 * whose detail page is published too, so no card ever links to a missing page.
 */
function programs_all(bool $publishedOnly = true): array
{
    static $cache = [];
    $key = $publishedOnly ? 'pub' : 'all';
    if (!isset($cache[$key])) {
        $where = $publishedOnly ? " WHERE p.status = 'published' AND COALESCE(pg.status, pg2.status) = 'published'" : '';
        $cache[$key] = db_rows(program_select_sql() . $where . ' ORDER BY p.sort_order, p.title');
    }
    return $cache[$key];
}

function program_by_slug(string $slug, bool $publishedOnly = true): ?array
{
    return db_row(program_select_sql() . ' WHERE p.slug = ?' . ($publishedOnly ? " AND p.status = 'published'" : '') . ' LIMIT 1', [$slug]);
}

/** The programme shown on a page: linked by page_id, else the page's settings {"program":"<slug>"}. */
function program_for_page(int $pageId): ?array
{
    $row = db_row(program_select_sql() . ' WHERE p.page_id = ? LIMIT 1', [$pageId]);
    if ($row) {
        return $row;
    }
    $page = db_row('SELECT settings FROM pages WHERE id = ?', [$pageId]);
    $slug = (string) (json_field($page['settings'] ?? null)['program'] ?? '');
    return $slug !== '' ? program_by_slug($slug, false) : null;
}

/** [id => title] of every programme (for selects in other admin modules). */
function program_options(bool $publishedOnly = false): array
{
    $out = [];
    foreach (db_rows('SELECT id, title FROM programs' . ($publishedOnly ? " WHERE status = 'published'" : '') . ' ORDER BY sort_order, title') as $r) {
        $out[(int) $r['id']] = $r['title'];
    }
    return $out;
}

// ---------------------------------------------------------------------
// Cards
// ---------------------------------------------------------------------

/**
 * Premium programme card. $opts: index (stagger), points (max key points, default 3), filter (bool: add data-category for filter chips)
 */
function program_card(array $p, array $opts = []): string
{
    $url = program_url($p);
    $tone = program_tone($p);
    $art = program_art($p);
    $isPhoto = strpos($art, 'uploads/') === 0;
    $i = (int) ($opts['index'] ?? 0);
    $points = array_slice(program_key_points($p), 0, (int) ($opts['points'] ?? 3));
    $html = '<article class="program-card tone-' . $tone . ' reveal tilt" style="--d:' . ($i % 4) . '"'
        . (!empty($opts['filter']) ? ' data-category="' . e(program_focus($p)) . '"' : '') . '>'
        . '<div class="program-card-media' . ($isPhoto ? ' is-photo' : '') . '"><img src="' . e(image_url($art)) . '" alt="" loading="lazy" width="480" height="360"></div>'
        . '<div class="program-card-body"><span class="program-card-icon">' . icon((string) ($p['icon'] ?: 'heart')) . '</span><h3><a class="program-card-link" href="' . e($url) . '">' . e($p['title']) . '</a></h3>'
        . '<p>' . e($p['summary']) . '</p>';
    if ($points) {
        $html .= '<ul class="dot-list">';
        foreach ($points as $pt) {
            $html .= '<li>' . e($pt) . '</li>';
        }
        $html .= '</ul>';
    }
    return $html . '<span class="link-arrow" aria-hidden="true">Explore programme ' . icon('arrow-right') . '</span></div></article>';
}

/** Compact programme tile (icon, title, summary). */
function program_tile(array $p, int $i = 0): string
{
    return '<a class="tile program-tile tone-' . program_tone($p) . ' reveal tilt" style="--d:' . ($i % 4) . '" href="' . e(program_url($p)) . '">'
        . '<span class="icon-circle">' . icon((string) ($p['icon'] ?: 'heart')) . '</span>'
        . '<span><strong>' . e($p['title']) . '</strong><small>' . e(excerpt($p['summary'], 90)) . '</small></span>'
        . icon('chevron-right', 'tile-arrow') . '</a>';
}

// ---------------------------------------------------------------------
// Section type: programs_grid
// ---------------------------------------------------------------------
function section_schema_programs_grid(): array
{
    return [
        'label'    => 'Programmes grid',
        'fields'   => ['pill', 'title', 'intro'],
        'settings' => [
            'limit'     => ['number', 'Maximum number of programmes (0 = all)'],
            'home_only' => ['checkbox', 'Only programmes marked "Show on home page"'],
            'style'     => ['select', 'Style', ['cards' => 'Cards with illustration', 'compact' => 'Compact list']],
        ],
        'help'     => 'Lists published programmes from Admin > Programmes in their sort order.',
    ];
}

function section_programs_grid(array $s, array $items, array $set, array $page): string
{
    $all = programs_all();
    if (($page['template'] ?? '') === 'program' && !empty($page['id'])) {
        $all = array_values(array_filter($all, function ($p) use ($page) {
            return (int) $p['detail_page_id'] !== (int) $page['id'];
        }));
    }
    $list = !empty($set['home_only']) ? array_values(array_filter($all, function ($p) {
        return (int) $p['show_on_home'] === 1;
    })) : $all;
    $limit = max(0, (int) ($set['limit'] ?? 0));
    $more = $limit > 0 && count($list) > $limit;
    if ($limit > 0) {
        $list = array_slice($list, 0, $limit);
    }
    if (!$list) {
        return '';
    }
    if (($set['style'] ?? 'cards') === 'compact') {
        $html = '<div class="tile-grid program-tiles">';
        foreach ($list as $i => $p) {
            $html .= program_tile($p, $i);
        }
        $html .= '</div>';
    } else {
        $html = '<div class="program-grid">';
        foreach ($list as $i => $p) {
            $html .= program_card($p, ['index' => $i]);
        }
        $html .= '</div>';
    }
    if (($more || !empty($set['home_only'])) && ($page['slug'] ?? '') !== 'programs' && get_page('programs')) {
        $html .= '<div class="btn-row center mt-3"><a class="btn btn-outline" href="' . e(page_url('programs')) . '">View all programmes ' . icon('arrow-right') . '</a></div>';
    }
    return $html;
}

// ---------------------------------------------------------------------
// Welfare schemes
// ---------------------------------------------------------------------

function scheme_categories(): array
{
    return [
        'education' => 'Education', 'health' => 'Health', 'women' => 'Women & children', 'livelihood' => 'Livelihood & employment',
        'housing' => 'Housing', 'social_security' => 'Social security & pensions', 'agriculture' => 'Agriculture & farmers',
        'disability' => 'Persons with disabilities', 'senior_citizens' => 'Senior citizens', 'general' => 'General',
    ];
}

function scheme_category_label(?string $key): string
{
    return scheme_categories()[(string) $key] ?? humanize($key);
}

function scheme_category_icon(?string $key): string
{
    $map = ['education' => 'graduation', 'health' => 'activity', 'women' => 'users', 'livelihood' => 'briefcase', 'housing' => 'home',
        'social_security' => 'shield', 'agriculture' => 'leaf', 'disability' => 'heart', 'senior_citizens' => 'user', 'general' => 'file-text'];
    return $map[(string) $key] ?? 'file-text';
}

/** Is a published scheme's source check older than a year (shown as a note to staff)? */
function scheme_is_stale(array $s): bool
{
    return !empty($s['source_checked_on']) && strtotime($s['source_checked_on']) < strtotime('-12 months');
}

/** Scheme card for lists. */
function scheme_card(array $s, int $i = 0): string
{
    $url = scheme_url($s['slug']);
    $html = '<article class="scheme-card reveal" style="--d:' . ($i % 3) . '">'
        . '<div class="scheme-card-top"><span class="scheme-cat">' . icon(scheme_category_icon($s['category'])) . e(scheme_category_label($s['category'])) . '</span>'
        . (!empty($s['help_available']) ? '<span class="scheme-help-tag">' . icon('users') . 'Help available</span>' : '') . '</div>'
        . '<h3><a class="scheme-card-link" href="' . e($url) . '">' . e($s['title']) . '</a></h3>'
        . (!empty($s['authority']) ? '<p class="scheme-authority">' . icon('shield') . e($s['authority']) . '</p>' : '')
        . '<p>' . e(excerpt($s['summary'], 150)) . '</p>'
        . '<div class="scheme-card-foot">'
        . (!empty($s['source_checked_on']) ? '<small>' . icon('check-circle') . 'Checked ' . e(format_date($s['source_checked_on'])) . '</small>' : '<small></small>')
        . '<span class="link-arrow" aria-hidden="true">Details ' . icon('arrow-right') . '</span></div></article>';
    return $html;
}

/**
 * Plain text with line breaks -> safe HTML. Blank lines separate paragraphs; lines starting with
 * "-", "*" or "•" become a bullet list, "1." / "1)" a numbered list. With $listLines every line of a
 * multi-line block becomes a list item (for eligibility, documents).
 */
function scheme_text_html(?string $text, bool $listLines = false): string
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $text));
    if ($text === '') {
        return '';
    }
    $html = '';
    foreach (preg_split('/\n\s*\n/', $text) as $block) {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), 'strlen'));
        if (!$lines) {
            continue;
        }
        $bullets = count(array_filter($lines, function ($l) { return (bool) preg_match('/^[\-\*•]\s*/u', $l); }));
        $numbers = count(array_filter($lines, function ($l) { return (bool) preg_match('/^\d{1,2}[\.\)]\s+/', $l); }));
        if ($numbers === count($lines) && count($lines) > 1) {
            $html .= '<ol>';
            foreach ($lines as $l) {
                $html .= '<li>' . e(preg_replace('/^\d{1,2}[\.\)]\s+/', '', $l)) . '</li>';
            }
            $html .= '</ol>';
        } elseif ($bullets === count($lines) || ($listLines && count($lines) > 1)) {
            $html .= '<ul>';
            foreach ($lines as $l) {
                $html .= '<li>' . e(preg_replace('/^[\-\*•]\s*/u', '', $l)) . '</li>';
            }
            $html .= '</ul>';
        } else {
            $html .= '<p>' . e_nl(implode("\n", $lines)) . '</p>';
        }
    }
    return $html;
}

/** Published scheme by slug (drafts for staff with schemes.manage when $preview). */
function scheme_by_slug(string $slug, bool $preview = false): ?array
{
    $row = db_row('SELECT * FROM schemes WHERE slug = ?', [$slug]);
    if (!$row) {
        return null;
    }
    if ($row['status'] !== 'published' && !($preview && user_can('schemes.manage'))) {
        return null;
    }
    return $row;
}

/**
 * Full scheme directory (search, category filter, pagination, honest empty state) for the /schemes/ page.
 */
function schemes_directory(): string
{
    $cats = scheme_categories();
    $q = trim(mb_substr(clean_string($_GET['q'] ?? ''), 0, 80));
    $cat = (string) ($_GET['category'] ?? '');
    $cat = isset($cats[$cat]) ? $cat : '';
    $counts = [];
    foreach (db_rows("SELECT category, COUNT(*) AS n FROM schemes WHERE status = 'published' GROUP BY category") as $r) {
        $counts[$r['category']] = (int) $r['n'];
    }
    $totalPublished = array_sum($counts);
    $helpUrl = page_url('request-help');
    if ($totalPublished === 0) {
        return '<div class="scheme-empty reveal">' . icon('file-text') . '<h3>Scheme information is being verified</h3>'
            . '<p>Verified scheme information will be published here soon. Meanwhile, our volunteers can guide you – Request help.</p>'
            . '<div class="btn-row center"><a class="btn btn-primary" href="' . e($helpUrl) . '">' . icon('life-buoy') . 'Request help</a>'
            . '<a class="btn btn-outline" href="' . e(page_url('contact')) . '">Contact us</a></div></div>';
    }

    $where = "status = 'published'";
    $params = [];
    if ($cat !== '') {
        $where .= ' AND category = ?';
        $params[] = $cat;
    }
    if ($q !== '') {
        $where .= ' AND (title LIKE ? OR summary LIKE ? OR authority LIKE ? OR eligibility LIKE ? OR benefits LIKE ?)';
        array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
    }
    $total = (int) db_value("SELECT COUNT(*) FROM schemes WHERE $where", $params);
    $pg = paginate($total, 9);
    $rows = db_rows("SELECT * FROM schemes WHERE $where ORDER BY sort_order, title LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);

    $action = page_url('schemes');
    $html = '<form class="scheme-search" method="get" action="' . e($action) . '" role="search">'
        . '<div class="form-group"><label for="scheme-q">Search schemes</label><input type="search" id="scheme-q" name="q" value="' . e($q) . '" maxlength="80" placeholder="Scheme name, benefit or department"></div>'
        . '<div class="form-group"><label for="scheme-cat">Category</label><select id="scheme-cat" name="category"><option value="">All categories</option>';
    foreach ($cats as $k => $label) {
        if (!empty($counts[$k]) || $cat === $k) {
            $html .= '<option value="' . e($k) . '"' . ($cat === $k ? ' selected' : '') . '>' . e($label) . '</option>';
        }
    }
    $html .= '</select></div><div class="scheme-search-actions"><button class="btn btn-primary" type="submit">' . icon('search') . 'Search</button>'
        . (($q !== '' || $cat !== '') ? '<a class="btn btn-ghost" href="' . e($action) . '">Clear</a>' : '') . '</div></form>';

    if (count($counts) > 1) {
        $html .= '<nav class="scheme-chips" aria-label="Scheme categories"><a class="filter-btn' . ($cat === '' ? ' is-active' : '') . '" href="' . e($action . ($q !== '' ? '?' . http_build_query(['q' => $q]) : '')) . '"' . ($cat === '' ? ' aria-current="page"' : '') . '>All</a>';
        foreach ($cats as $k => $label) {
            if (!empty($counts[$k])) {
                $html .= '<a class="filter-btn' . ($cat === $k ? ' is-active' : '') . '" href="' . e($action . '?' . http_build_query(array_filter(['category' => $k, 'q' => $q]))) . '"'
                    . ($cat === $k ? ' aria-current="page"' : '') . '>' . e($label) . ' <span class="chip-count">' . $counts[$k] . '</span></a>';
            }
        }
        $html .= '</nav>';
    }

    if (!$rows) {
        return $html . empty_state('No schemes match your search. Try another word or category, or ask our volunteers for guidance.', 'Request help', '/request-help/');
    }
    $html .= '<p class="scheme-result-count muted small" aria-live="polite">' . $total . ' ' . ($total === 1 ? 'scheme' : 'schemes')
        . ($cat !== '' ? ' in ' . e($cats[$cat]) : '') . ($q !== '' ? ' matching “' . e($q) . '”' : '') . '</p><div class="scheme-grid">';
    foreach ($rows as $i => $s) {
        $html .= scheme_card($s, $i);
    }
    return $html . '</div>' . pagination_links($pg);
}

// ---------------------------------------------------------------------
// Section type: schemes_list
// ---------------------------------------------------------------------
function section_schema_schemes_list(): array
{
    return [
        'label'    => 'Welfare schemes list',
        'fields'   => ['pill', 'title', 'intro'],
        'settings' => [
            'category' => ['select', 'Category', ['' => 'All categories'] + scheme_categories()],
            'limit'    => ['number', 'Maximum number of schemes (default 6)'],
        ],
        'help'     => 'Lists published schemes (Admin > Welfare Schemes). Hidden when none are published. On the Welfare Schemes page it shows the full searchable directory.',
    ];
}

function section_schemes_list(array $s, array $items, array $set, array $page): string
{
    if (($page['template'] ?? '') === 'schemes' && route_param() === '' && (int) ($GLOBALS['route']['page']['id'] ?? 0) === (int) ($page['id'] ?? -1)) {
        return schemes_directory();
    }
    $cat = (string) ($set['category'] ?? '');
    $limit = (int) ($set['limit'] ?? 0) > 0 ? min(24, (int) $set['limit']) : 6;
    $params = [];
    $where = "status = 'published'";
    if ($cat !== '' && isset(scheme_categories()[$cat])) {
        $where .= ' AND category = ?';
        $params[] = $cat;
    }
    $rows = db_rows("SELECT * FROM schemes WHERE $where ORDER BY sort_order, title LIMIT " . $limit, $params);
    if (!$rows) {
        return '';
    }
    $html = '<div class="scheme-grid">';
    foreach ($rows as $i => $row) {
        $html .= scheme_card($row, $i);
    }
    $html .= '</div>';
    if (get_page('schemes')) {
        $html .= '<div class="btn-row center mt-3"><a class="btn btn-outline" href="' . e(page_url('schemes', $cat !== '' ? ['category' => $cat] : [])) . '">All welfare schemes ' . icon('arrow-right') . '</a></div>';
    }
    return $html;
}

// ---------------------------------------------------------------------
// Programme pages: related content and starter sections
// ---------------------------------------------------------------------

/** Does the page have a visible section of $type whose settings[$key] equals $value? */
function program_page_has_section(array $page, string $type, string $key, string $value): bool
{
    foreach (page_sections((int) ($page['id'] ?? 0)) as $s) {
        if ($s['section_type'] === $type && (string) (json_field($s['settings'])[$key] ?? '') === $value) {
            return true;
        }
    }
    return false;
}

/**
 * Published content linked to a programme: active campaigns, upcoming events, consented success stories,
 * blog posts and gallery photos (category = programme slug). Items already shown by a campaigns_grid /
 * gallery_strip section on the same page are skipped.
 */
function program_related(array $program, array $page): array
{
    $id = (int) $program['id'];
    $slug = (string) $program['slug'];
    $out = ['campaigns' => [], 'events' => [], 'stories' => [], 'posts' => [], 'gallery' => []];
    $grab = function (string $key, string $sql, array $params) use (&$out): void {
        try {
            $out[$key] = db_rows($sql, $params);
        } catch (Throwable $e) {
            error_log('Programme related ' . $key . ': ' . $e->getMessage());
        }
    };

    $skipCategory = program_page_has_section($page, 'campaigns_grid', 'category', $slug);
    $own = "SELECT c.*, COALESCE(d.raised, 0) AS raised, COALESCE(d.donor_count, 0) AS donor_count FROM campaigns c
            LEFT JOIN (SELECT campaign_id, SUM(amount) AS raised, COUNT(*) AS donor_count FROM donations
                       WHERE status = 'success' AND campaign_id IS NOT NULL GROUP BY campaign_id) d ON d.campaign_id = c.id";
    $cWhere = " WHERE c.program_id = ? AND c.status = 'active'" . ($skipCategory ? ' AND c.category <> ?' : '') . ' ORDER BY c.is_featured DESC, c.created_at DESC LIMIT 3';
    $cParams = $skipCategory ? [$id, $slug] : [$id];
    if (function_exists('campaign_sql')) {
        try {
            $out['campaigns'] = db_rows(campaign_sql() . $cWhere, $cParams);
        } catch (Throwable $e) {
            $grab('campaigns', $own . $cWhere, $cParams);
        }
    } else {
        $grab('campaigns', $own . $cWhere, $cParams);
    }
    $grab('events', "SELECT * FROM events WHERE program_id = ? AND status = 'published' AND COALESCE(end_datetime, start_datetime) >= NOW() ORDER BY start_datetime LIMIT 3", [$id]);
    $grab('stories', "SELECT * FROM success_stories WHERE program_id = ? AND status = 'published' AND consent_confirmed = 1 AND (published_at IS NULL OR published_at <= NOW()) ORDER BY published_at DESC, id DESC LIMIT 3", [$id]);
    $grab('posts', "SELECT * FROM blog_posts WHERE program_id = ? AND status = 'published' AND published_at IS NOT NULL AND published_at <= NOW() ORDER BY published_at DESC LIMIT 3", [$id]);
    if (!program_page_has_section($page, 'gallery_strip', 'category', $slug)) {
        $grab('gallery', "SELECT * FROM gallery_items WHERE category = ? AND status = 'published' AND media_type = 'image' AND image_path IS NOT NULL ORDER BY sort_order, id DESC LIMIT 8", [$slug]);
        $out['gallery'] = array_values(array_filter($out['gallery'], function ($g) {
            return is_file(ROOT_PATH . '/' . $g['image_path']) && strpos($g['image_path'], '..') === false;
        }));
    }
    return $out;
}

/**
 * Card for a related item. Uses the owning module's card function when it exists (campaign_card, event_card,
 * story_card, blog_card), otherwise a simple built-in card.
 */
function program_related_card(string $type, array $row): string
{
    $fn = ['campaign' => 'campaign_card', 'event' => 'event_card', 'story' => 'story_card', 'post' => 'blog_card'][$type] ?? '';
    if ($fn !== '' && function_exists($fn)) {
        try {
            $html = (string) $fn($row);
            if ($html !== '') {
                return $html;
            }
        } catch (Throwable $e) {
            error_log('Programme related card ' . $fn . ': ' . $e->getMessage());
        }
    }
    switch ($type) {
        case 'campaign':
            $url = campaign_url($row['slug']);
            $text = $row['short_description'] ?? '';
            $meta = !empty($row['end_date']) ? icon('calendar') . 'Until ' . e(format_date($row['end_date'])) : '';
            $img = $row['image'] ?? null;
            break;
        case 'event':
            $url = event_url($row['slug']);
            $text = $row['summary'] ?? '';
            $meta = icon('calendar') . e(format_date($row['start_datetime'], 'D, d M Y')) . (!empty($row['location']) ? ' · ' . e($row['location']) : '');
            $img = $row['image'] ?? null;
            break;
        case 'story':
            $url = story_url($row['slug']);
            $text = $row['excerpt'] ?? '';
            $meta = !empty($row['location']) ? icon('map-pin') . e($row['location']) : '';
            $img = $row['image'] ?? null;
            break;
        default:
            $url = post_url($row['slug']);
            $text = $row['excerpt'] ?? '';
            $meta = icon('calendar') . e(format_date($row['published_at']));
            $img = $row['image'] ?? null;
    }
    return '<article class="related-card reveal"><a class="related-media" href="' . e($url) . '" tabindex="-1" aria-hidden="true"><img src="' . e(image_url($img)) . '" alt="" loading="lazy"></a>'
        . '<div class="related-body">' . ($meta !== '' ? '<p class="related-meta">' . $meta . '</p>' : '')
        . '<h4><a href="' . e($url) . '">' . e($row['title']) . '</a></h4><p>' . e(excerpt($text, 120)) . '</p></div></article>';
}

/**
 * Starter sections for a programme page created in the admin (descriptive only - no figures).
 * Returns rows for page_sections (without page_id).
 */
function program_starter_sections(array $program): array
{
    $title = (string) $program['title'];
    $slug = (string) $program['slug'];
    $points = program_key_points($program);
    $icons = ['check-circle', 'users', 'heart', 'star', 'target', 'layers'];
    $what = [];
    foreach (array_slice($points, 0, 6) as $i => $pt) {
        $what[] = ['icon' => $icons[$i % count($icons)], 'title' => $pt, 'text' => ''];
    }
    $rows = [
        ['section_type' => 'subnav', 'anchor' => null, 'title' => null, 'pill' => null, 'intro' => null, 'body' => null, 'image' => null,
            'items' => '[]', 'settings' => json_encode(['bare' => true]), 'background' => 'white'],
        ['section_type' => 'split', 'anchor' => 'overview', 'pill' => 'Overview', 'title' => 'About ' . $title, 'intro' => (string) $program['summary'],
            'body' => '<p>Describe what this programme does, who it is for and why it matters. Avoid statistics unless they come from verified records.</p>',
            'image' => program_art($program, false), 'items' => '[]',
            'settings' => json_encode(['image_alt' => '', 'buttons' => [['label' => 'Support this programme', 'url' => '/donate/', 'style' => 'donate', 'icon' => 'heart']]]), 'background' => 'white'],
    ];
    if ($what) {
        $rows[] = ['section_type' => 'features', 'anchor' => 'what-we-do', 'pill' => $title, 'title' => 'What we do', 'intro' => null, 'body' => null, 'image' => null,
            'items' => json_encode($what, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'settings' => json_encode(['style' => 'soft']), 'background' => 'tint'];
    }
    $rows[] = ['section_type' => 'steps', 'anchor' => 'approach', 'pill' => 'How we work', 'title' => 'Our approach', 'intro' => 'Every activity follows a simple, accountable process.', 'body' => null, 'image' => null,
        'items' => json_encode([
            ['icon' => 'search', 'title' => 'Understand the need', 'text' => 'We listen to families and community members to understand what support is needed.'],
            ['icon' => 'users', 'title' => 'Plan together', 'text' => 'Activities are planned with the community and our volunteers.'],
            ['icon' => 'heart', 'title' => 'Deliver with care', 'text' => 'Trained volunteers carry out the activities with dignity and respect.'],
            ['icon' => 'check-circle', 'title' => 'Review and report', 'text' => 'We review what worked and share verified updates.'],
        ]), 'settings' => json_encode(['align' => 'center']), 'background' => 'white'];
    $rows[] = ['section_type' => 'module', 'anchor' => 'at-a-glance', 'pill' => null, 'title' => 'At a glance', 'intro' => null, 'body' => null, 'image' => null,
        'items' => '[]', 'settings' => '{}', 'background' => 'white'];
    $rows[] = ['section_type' => 'features', 'anchor' => 'get-involved', 'pill' => 'Get involved', 'title' => 'How you can help', 'intro' => 'Choose the way of supporting this programme that suits you.', 'body' => null, 'image' => null,
        'items' => json_encode([
            ['icon' => 'heart', 'title' => 'Donate', 'text' => 'Support the programme online. You receive a receipt for every verified donation.', 'link' => '/donate/', 'link_label' => 'Donate now'],
            ['icon' => 'users', 'title' => 'Volunteer', 'text' => 'Give your time and skills to activities in your area.', 'link' => '/become-a-volunteer/', 'link_label' => 'Apply to volunteer'],
            ['icon' => 'share', 'title' => 'Spread awareness', 'text' => 'Tell people who may need this support about the programme.', 'link' => '/request-help/', 'link_label' => 'How to request help'],
            ['icon' => 'briefcase', 'title' => 'Partner with us', 'text' => 'Organisations and institutions can write to us to discuss collaboration.', 'link' => '/contact/', 'link_label' => 'Contact us'],
        ]), 'settings' => json_encode(['cols' => '4', 'align' => 'center']), 'background' => 'tint'];
    $rows[] = ['section_type' => 'campaigns_grid', 'anchor' => null, 'pill' => 'Campaigns', 'title' => 'Support an active campaign', 'intro' => 'Amounts shown include verified donations only.', 'body' => null, 'image' => null,
        'items' => '[]', 'settings' => json_encode(['status' => 'active', 'category' => $slug, 'limit' => 3]), 'background' => 'white'];
    $rows[] = ['section_type' => 'cta', 'anchor' => null, 'pill' => null, 'title' => 'Support ' . $title, 'intro' => 'Together, let us build a better tomorrow for the communities we serve.', 'body' => null, 'image' => null,
        'items' => '[]', 'settings' => json_encode(['hindi_tagline' => true, 'buttons' => [
            ['label' => 'Donate Now', 'url' => '/donate/', 'style' => 'donate', 'icon' => 'heart', 'size' => 'lg'],
            ['label' => 'Become a Volunteer', 'url' => '/become-a-volunteer/', 'style' => 'light', 'size' => 'lg'],
        ]]), 'background' => 'white'];
    return $rows;
}
