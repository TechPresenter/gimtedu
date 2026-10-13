<?php
/**
 * Academic website pages (unit "site_academic") — shared data access + render helpers for
 * about.php, programs.php, program.php, academics.php, faculty.php and campus.php.
 *
 * Every query is read-only, uses prepared statements and runs through sa_try(), so a page still renders (with sensible
 * defaults) when a table is empty, missing or not seeded yet. Faculty data is limited to public-safe columns: name,
 * designation, department, qualification, specialization, experience, bio, research interests, publications, photo
 * and LinkedIn — never salary, phone, personal email, bank details or address.
 *
 * Tailwind classes used dynamically below (kept literal for the scanner):
 *   from-brand-700 to-brand-900 from-accent-600 to-brand-800 from-cyan-600 from-violet-600 from-amber-500
 *   badge-blue badge-green badge-amber badge-purple badge-red badge-cyan badge-slate
 */

/* ============================================================================ Basics */

/** Run a DB read and fall back to $default (logged) when it fails. */
function sa_try(callable $fn, $default = null)
{
    try {
        return $fn();
    } catch (Throwable $e) {
        error_log('[site_academic] ' . $e->getMessage());
        return $default;
    }
}

/** Round a live count to a marketing-friendly "1,200+" style value: [number, suffix]. */
function sa_round(int $n): array
{
    if ($n >= 1000) {
        return [intdiv($n, 100) * 100, '+'];
    }
    if ($n >= 100) {
        return [intdiv($n, 50) * 50, '+'];
    }
    if ($n >= 20) {
        return [intdiv($n, 5) * 5, '+'];
    }
    return [$n, ''];
}

/** "1,200+" text for a live count. */
function sa_round_label(int $n): string
{
    [$v, $s] = sa_round($n);
    return number_format($v) . $s;
}

/** Current academic session row (public pages ignore the admin's session switcher). */
function sa_session(): ?array
{
    static $row = false;
    if ($row === false) {
        $row = sa_try(fn () => db_row('SELECT * FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1')
            ?: db_row('SELECT * FROM academic_sessions WHERE start_date <= CURDATE() AND end_date >= CURDATE() ORDER BY start_date DESC LIMIT 1'));
        $row = $row ?: null;
    }
    return $row;
}

/** WhatsApp chat link with a prefilled message, or null when no number is configured. */
function sa_whatsapp_url(string $text = 'Hello GIMT, I would like to know more about your programs.'): ?string
{
    $n = preg_replace('/\D/', '', (string) setting('whatsapp_number', ''));
    return $n !== '' ? 'https://wa.me/' . $n . '?text=' . rawurlencode($text) : null;
}

/** BreadcrumbList JSON-LD: [['Home', '/'], ['Programs', '/programs'], ['MBA', null]] (null = current URL). */
function sa_breadcrumb_ld(array $crumbs): array
{
    $items = [];
    $here = ltrim(substr((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), strlen(base_path())), '/');
    foreach (array_values($crumbs) as $i => [$name, $url]) {
        $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => absolute_url($url === null ? $here : ltrim((string) $url, '/'))];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

/**
 * True when the admin SEO record of this page (seo_settings, rendered by the header) already supplies JSON-LD of $type,
 * so the page does not print a second block of the same type.
 */
function sa_seo_schema_has(string $type, ?string $entityType, ?int $entityId, ?string $route): bool
{
    $row = seo_lookup($entityType, $entityId, $route);
    return !empty($row['schema_json']) && preg_match('/"@type"\s*:\s*"' . preg_quote($type, '/') . '"/', (string) $row['schema_json']) === 1;
}

/** Absolute URL for an image path/URL returned by site_image(). */
function sa_abs_image(string $url): string
{
    return preg_match('#^https?://#', $url) ? $url : absolute_url(ltrim(substr($url, strlen(base_path())), '/'));
}

/** Link to a public page that may not exist yet (another unit's page) — falls back to $fallback. */
function sa_page_link(string $script, string $url, string $fallback): string
{
    return is_file(APP_ROOT . '/' . $script) ? $url : $fallback;
}

/* ============================================================================ CMS pages */

/** Published CMS page by slug with decoded builder blocks, or null. */
function sa_cms_page(string $slug): ?array
{
    static $cache = [];
    if (!array_key_exists($slug, $cache)) {
        $row = sa_try(fn () => db_row("SELECT * FROM pages WHERE slug = ? AND status = 'published' AND (published_at IS NULL OR published_at <= NOW())", [$slug]));
        if ($row) {
            $blocks = json_decode((string) ($row['blocks'] ?? ''), true);
            $row['blocks'] = is_array($blocks) ? array_values(array_filter($blocks, 'is_array')) : [];
        }
        $cache[$slug] = $row ?: null;
    }
    return $cache[$slug];
}

/** Normalised builder blocks [{type, data}] (accepts {type, data:{…}} and flat {type, …fields}). */
function sa_page_blocks(?array $page, array $types = []): array
{
    $out = [];
    foreach ($page['blocks'] ?? [] as $b) {
        $type = strtolower((string) ($b['type'] ?? ''));
        $data = isset($b['data']) && is_array($b['data']) ? $b['data'] : $b;
        if ($type !== '' && (!$types || in_array($type, $types, true))) {
            $out[] = ['type' => $type, 'data' => $data];
        }
    }
    return $out;
}

/** Rich-text body of a CMS page (page content + text blocks), sanitised. */
function sa_page_html(?array $page): string
{
    if (!$page) {
        return '';
    }
    $html = trim((string) ($page['content'] ?? ''));
    foreach (sa_page_blocks($page, ['text', 'richtext', 'rich_text', 'content']) as $b) {
        $d = $b['data'];
        $part = (string) ($d['html'] ?? $d['content'] ?? $d['body'] ?? $d['text'] ?? '');
        if ($part !== '') {
            $heading = trim((string) ($d['heading'] ?? $d['title'] ?? ''));
            $html .= ($heading !== '' ? '<h3>' . e($heading) . '</h3>' : '') . (str_contains($part, '<') ? $part : '<p>' . nl2br(e($part)) . '</p>');
        }
    }
    return $html !== '' ? sanitize_html($html) : '';
}

/** People cards from a CMS page's blocks (team / leadership / cards blocks whose items carry a name). */
function sa_page_people(?array $page): array
{
    $people = [];
    foreach (sa_page_blocks($page) as $b) {
        foreach ((array) ($b['data']['items'] ?? $b['data']['members'] ?? $b['data']['cards'] ?? []) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $name = trim((string) ($it['name'] ?? $it['title'] ?? ''));
            $role = trim((string) ($it['designation'] ?? $it['role'] ?? $it['subtitle'] ?? $it['position'] ?? ''));
            if ($name === '' || $role === '') {
                continue;
            }
            $people[] = ['name' => $name, 'role' => $role, 'bio' => trim((string) ($it['bio'] ?? $it['text'] ?? $it['description'] ?? '')),
                'photo' => (string) ($it['photo'] ?? $it['image'] ?? '')];
        }
    }
    return $people;
}

/* ============================================================================ Institute content (settings + defaults) */

/** Vision / mission / values (settings vision, mission, core_values override the defaults). */
function sa_vision_mission(): array
{
    return [
        ['id' => 'vision', 'icon' => 'eye', 'tint' => 'green', 'deco' => 'target', 'title' => 'Our Vision',
         'text' => (string) setting('vision', 'To be a globally recognized institution for excellence in education and innovation, creating leaders who make a positive impact on society.')],
        ['id' => 'mission', 'icon' => 'target', 'tint' => 'blue', 'deco' => 'flag', 'title' => 'Our Mission',
         'text' => (string) setting('mission', 'To empower students with knowledge, skills and values through industry-relevant education, research, and holistic development.')],
        ['id' => 'values', 'icon' => 'heart', 'tint' => 'violet', 'deco' => 'handshake', 'title' => 'Our Values',
         'text' => (string) setting('core_values', 'Integrity, Innovation, Excellence, Inclusivity and Social Responsibility are at the core of everything we do at GIMT.')],
    ];
}

/** Leadership cards: CMS page "leadership" blocks → setting leadership_json → defaults (mockup). */
function sa_leaders(): array
{
    $people = sa_page_people(sa_cms_page('leadership')) ?: sa_page_people(sa_cms_page('about'));
    if (!$people) {
        $json = json_decode((string) setting('leadership_json', ''), true);
        if (is_array($json)) {
            foreach ($json as $it) {
                if (!empty($it['name']) && !empty($it['role'])) {
                    $people[] = ['name' => (string) $it['name'], 'role' => (string) $it['role'], 'bio' => (string) ($it['bio'] ?? ''), 'photo' => (string) ($it['photo'] ?? '')];
                }
            }
        }
    }
    if (!$people) {
        $people = [
            ['name' => 'Dr. R.K. Sharma', 'role' => 'Chairman', 'bio' => 'Visionary leader with 25+ years in education and research.', 'photo' => 'assets/images/site/leader-1.jpg'],
            ['name' => 'Prof. Neha Verma', 'role' => 'Director', 'bio' => 'Focused on academic excellence and student development.', 'photo' => 'assets/images/site/leader-2.jpg'],
            ['name' => 'Dr. Amit Kumar', 'role' => 'Academic Dean', 'bio' => 'Expert in curriculum design and industry collaboration.', 'photo' => 'assets/images/site/leader-3.jpg'],
        ];
    }
    foreach ($people as $i => &$p) {
        $p['image'] = site_image($p['photo'] ?: null, 'assets/images/site/leader-' . (($i % 3) + 1) . '.jpg');
    }
    unset($p);
    return $people;
}

/** Approvals & affiliations (setting approvals_json = [{title, subtitle, icon}] overrides). Generic emblems only. */
function sa_approvals(): array
{
    $json = json_decode((string) setting('approvals_json', ''), true);
    if (is_array($json) && $json) {
        return array_values(array_filter(array_map(fn ($a) => is_array($a) && !empty($a['title']) ? ['title' => (string) $a['title'], 'subtitle' => (string) ($a['subtitle'] ?? ''), 'icon' => (string) ($a['icon'] ?? 'badge-check')] : null, $json)));
    }
    return [
        ['title' => 'AICTE', 'subtitle' => 'Approved', 'icon' => 'cog'],
        ['title' => 'UGC', 'subtitle' => 'Recognized', 'icon' => 'landmark'],
        ['title' => 'NAAC', 'subtitle' => 'Accredited', 'icon' => 'award'],
        ['title' => 'Affiliated to', 'subtitle' => 'State University', 'icon' => 'university'],
        ['title' => 'ISO 9001:2015', 'subtitle' => 'Certified', 'icon' => 'shield-check'],
    ];
}

/** Generic emblem (concentric rings + icon) for an approval / affiliation. */
function sa_emblem(array $a, int $i = 0): string
{
    $tones = ['text-amber-600 bg-amber-50 ring-amber-200', 'text-rose-600 bg-rose-50 ring-rose-200', 'text-red-600 bg-red-50 ring-red-200', 'text-slate-600 bg-slate-50 ring-slate-200', 'text-blue-700 bg-blue-50 ring-blue-200'];
    $tone = $tones[$i % count($tones)];
    return '<li class="group flex flex-col items-center px-2 text-center">'
        . '<span class="relative inline-flex h-20 w-20 items-center justify-center rounded-full ring-4 ring-offset-2 transition duration-500 group-hover:-translate-y-1 ' . $tone . '">'
        . '<span class="absolute inset-1.5 rounded-full border border-dashed border-current opacity-40 spin-slow" aria-hidden="true"></span>'
        . icon($a['icon'], 'relative h-8 w-8') . '</span>'
        . '<span class="mt-3 font-display text-sm font-extrabold uppercase tracking-wide text-brand-900">' . e($a['title']) . '</span>'
        . '<span class="text-xs font-medium text-slate-500">' . e($a['subtitle']) . '</span></li>';
}

/** Small centred icon tile (About "Why GIMT" / Programs "Why Study at GIMT?"). */
function sa_icon_tile(string $icon, string $label, string $tint = 'blue', string $text = ''): string
{
    return '<div class="group flex h-full flex-col items-center justify-center rounded-2xl border border-slate-200/80 bg-white px-3 py-5 text-center shadow-sm transition duration-300 hover:-translate-y-1 hover:border-brand-200 hover:shadow-card">'
        . '<span class="inline-flex h-12 w-12 items-center justify-center rounded-xl transition duration-500 group-hover:-rotate-6 group-hover:scale-110 tint-' . e($tint) . '">' . icon($icon, 'h-6 w-6') . '</span>'
        . '<h3 class="mt-3 text-[13.5px] font-semibold leading-snug text-brand-900">' . e($label) . '</h3>'
        . ($text !== '' ? '<p class="mt-1 text-xs leading-relaxed text-slate-500">' . e($text) . '</p>' : '') . '</div>';
}

/* ============================================================================ Live counts */

/** Institute-wide live counts (cached per request). */
function sa_counts(): array
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $int = fn (string $sql, array $args = []) => (int) sa_try(fn () => db_value($sql, $args), 0);
    $c = [
        'students' => $int("SELECT COUNT(*) FROM students WHERE status = 'active'"),
        'faculty' => $int("SELECT COUNT(*) FROM faculty WHERE status IN ('active','on_leave')"),
        'programs' => count(sa_programs()),
        'departments' => $int("SELECT COUNT(*) FROM departments WHERE status = 'active'"),
        'phd' => $int("SELECT COUNT(*) FROM faculty WHERE status IN ('active','on_leave') AND (qualification LIKE 'Ph.D%' OR qualification LIKE 'PhD%')"),
        'publications' => $int("SELECT COALESCE(SUM(publications_count), 0) FROM faculty WHERE status IN ('active','on_leave')"),
        'experience' => (float) sa_try(fn () => db_value("SELECT AVG(experience_years) FROM faculty WHERE status IN ('active','on_leave') AND experience_years IS NOT NULL"), 0),
        'recruiters' => $int("SELECT COUNT(*) FROM companies WHERE status = 'active' AND is_recruiter = 1"),
        'alumni' => $int('SELECT COUNT(*) FROM alumni'),
        'classrooms' => $int("SELECT COUNT(*) FROM classrooms WHERE status = 'active' AND type = 'classroom'"),
        'labs' => $int("SELECT COUNT(*) FROM classrooms WHERE status = 'active' AND type = 'lab'"),
        'satisfaction' => (float) setting('student_satisfaction', 95),
        'established' => (int) setting('established_year', 2008),
    ];
    return $c;
}

/** Average student attendance (%) for the current session from attendance summaries, or null. */
function sa_attendance_rate(): ?float
{
    $s = sa_session();
    if (!$s) {
        return null;
    }
    $v = sa_try(fn () => db_value('SELECT 100 * SUM(present + late) / NULLIF(SUM(held), 0) FROM attendance_summaries WHERE academic_session_id = ?', [(int) $s['id']]));
    return $v !== null && $v !== false ? round((float) $v, 1) : null;
}

/* ============================================================================ Programs */

/** Active website programs with department name/code (featured first). */
function sa_programs(): array
{
    static $rows = null;
    if ($rows === null) {
        $rows = sa_try(fn () => db_all("SELECT p.*, d.name AS department_name, d.code AS department_code FROM programs p
                LEFT JOIN departments d ON d.id = p.department_id
                WHERE p.status = 'active' AND p.show_on_website = 1 ORDER BY p.is_featured DESC, p.sort_order, p.name"), []);
    }
    return $rows;
}

/** Programs page filter categories — keys double as ?category= values. */
function sa_program_categories(): array
{
    return [
        ['key' => 'all', 'label' => 'All Programs', 'icon' => 'layout-grid'],
        ['key' => 'undergraduate', 'label' => 'Undergraduate', 'icon' => 'graduation-cap'],
        ['key' => 'postgraduate', 'label' => 'Postgraduate', 'icon' => 'school'],
        ['key' => 'management', 'label' => 'Management', 'icon' => 'chart-column'],
        ['key' => 'technology', 'label' => 'Technology', 'icon' => 'cpu'],
        ['key' => 'commerce', 'label' => 'Commerce', 'icon' => 'building-2'],
        ['key' => 'diploma', 'label' => 'Diploma', 'icon' => 'file-text'],
        ['key' => 'certificate', 'label' => 'Certificate', 'icon' => 'badge-check'],
    ];
}

/** Normalise a ?category= value (accepts ug/pg aliases) to a category key, or 'all'. */
function sa_category_key(?string $raw): string
{
    $raw = strtolower(trim((string) $raw));
    $alias = ['ug' => 'undergraduate', 'pg' => 'postgraduate', 'phd' => 'postgraduate', 'computer-applications' => 'technology', 'it' => 'technology'];
    $raw = $alias[$raw] ?? $raw;
    return in_array($raw, array_column(sa_program_categories(), 'key'), true) ? $raw : 'all';
}

/** Space-separated filter tokens for a program: level + category + department discipline. */
function sa_program_tokens(array $p): string
{
    $t = ['UG' => ['undergraduate'], 'PG' => ['postgraduate'], 'PhD' => ['postgraduate'], 'Diploma' => ['diploma'], 'Certificate' => ['certificate']][$p['level'] ?? ''] ?? [];
    $cat = slugify((string) ($p['category'] ?? ''));
    if (in_array($cat, ['management', 'technology', 'commerce', 'diploma', 'certificate'], true)) {
        $t[] = $cat;
    }
    if (in_array($cat, ['computer-applications', 'science'], true)) {
        $t[] = 'technology';
    }
    $t[] = ['MGT' => 'management', 'CSE' => 'technology', 'DCA' => 'technology', 'SCI' => 'technology', 'COM' => 'commerce'][strtoupper((string) ($p['department_code'] ?? ''))] ?? '';
    return implode(' ', array_values(array_unique(array_filter($t))));
}

/** Fee text for a program ("₹1,20,000 / Year") or ''. */
function sa_program_fee(array $p): string
{
    return (float) ($p['fee_per_year'] ?? 0) > 0 ? money($p['fee_per_year']) . ' ' . trim((string) ($p['fee_label'] ?: '/ Year')) : '';
}

/** Lines of a multi-line text column. */
function sa_lines(?string $text): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $text)), fn ($l) => $l !== ''));
}

/** Brochure path (for cms_link()/button_link()): uploaded program PDF, otherwise the printable brochure view. */
function sa_brochure_path(?array $p = null): string
{
    if ($p && !empty($p['brochure'])) {
        return (string) $p['brochure'];
    }
    return 'programs?brochure=1' . ($p ? '&program=' . rawurlencode((string) $p['slug']) : '');
}

/** Brochure URL for plain hrefs. */
function sa_brochure_url(?array $p = null): string
{
    return cms_link(sa_brochure_path($p));
}

/** Program options for enquiry selects. */
function sa_program_options(): array
{
    return array_map(fn ($p) => ['value' => (string) $p['id'], 'label' => $p['name'] . (program_title_suffix($p) !== '' ? ' (' . program_title_suffix($p) . ')' : '')], sa_programs());
}

/**
 * program_card() without the "Diploma · Diploma" / "Certificate · Certificate" repetition (category equal to the level
 * label is dropped from the meta line). Use it for every program card on these pages.
 */
function sa_program_card(array $p, array $o = []): string
{
    if (strcasecmp(trim((string) ($p['category'] ?? '')), program_level_label($p['level'] ?? '')) === 0) {
        $p['category'] = '';
    }
    return program_card($p, $o);
}

/**
 * Programs page explorer: ProgramExplorer island (tiles layout + search) with a server-rendered fallback of the same
 * footprint: the category tiles are real links (?category=key#find) and the cards outside the initial category are
 * hidden (still in the HTML, crawlable), so the page filters correctly without JavaScript too.
 * Category keys come from sa_program_categories(); syncParam keeps ?category= in the URL.
 */
function sa_program_explorer(array $programs, array $props = []): string
{
    $props += ['categories' => sa_program_categories(), 'layout' => 'tiles', 'search' => true, 'syncParam' => 'category',
        'gridClass' => 'sm:grid-cols-2 xl:grid-cols-3', 'searchPlaceholder' => 'Search programs, e.g. MBA',
        'emptyText' => 'No programs match your selection — try another category or search term.'];
    $initial = $props['initial'] ?? 'all';
    $tiles = '';
    foreach ($props['categories'] as $c) {
        $on = $c['key'] === $initial;
        $href = site_url('programs' . ($c['key'] === 'all' ? '' : '?category=' . rawurlencode($c['key']))) . '#find';
        $tiles .= '<a href="' . e($href) . '" class="filter-tile' . ($on ? ' is-active' : '') . '"' . ($on ? ' aria-current="true"' : '') . '>'
            . '<span class="filter-tile-icon">' . icon($c['icon'], 'h-5 w-5') . '</span><span class="text-sm font-semibold">' . e($c['label']) . '</span></a>';
    }
    $cards = '';
    foreach ($programs as $p) {
        $tokens = sa_program_tokens($p);
        $match = $initial === 'all' || in_array($initial, explode(' ', $tokens), true);
        $search = trim($p['name'] . ' ' . $p['short_name'] . ' ' . ($p['category'] ?? '') . ' ' . program_level_label($p['level'] ?? '') . ' ' . ($p['department_name'] ?? ''));
        // data-off (not a class: slot children are handed to the island as outerHTML) is hidden by the fallback grid only
        $cards .= '<div class="h-full" data-key="' . e($p['slug']) . '" data-category="' . e($tokens) . '" data-search="' . e($search) . '"' . ($match ? '' : ' data-off') . '>' . sa_program_card($p) . '</div>';
    }
    $searchField = $props['search']
        ? '<div class="flex items-center gap-3"><label class="search-field">' . icon('search', 'h-4 w-4 shrink-0 text-slate-400')
          . '<span class="sr-only">Search programs</span><input type="search" placeholder="' . e($props['searchPlaceholder']) . '" class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0" disabled></label></div>'
        : '';
    $fallback = '<div class="program-explorer"><div class="mb-8 flex flex-col gap-4"><div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8" role="toolbar" aria-label="Filter programs by category">' . $tiles . '</div>' . $searchField . '</div>'
        . '<div class="grid gap-6 ' . e($props['gridClass']) . ' [&>[data-off]]:hidden" data-slot="items">' . $cards . '</div></div>';
    return island('ProgramExplorer', $props, $fallback, ['eager' => true]);
}

/** Navy counselling form (EnquiryForm island → POST /api/public/enquiry). */
function sa_counselling_form(array $o = []): string
{
    $fields = [
        ['name' => 'name', 'label' => 'Full Name', 'required' => true, 'maxLength' => 150, 'autocomplete' => 'name'],
        ['name' => 'phone', 'label' => 'Phone Number', 'type' => 'tel', 'required' => true, 'maxLength' => 20, 'autocomplete' => 'tel'],
        ['name' => 'email', 'label' => 'Email Address', 'type' => 'email', 'required' => true, 'maxLength' => 190, 'autocomplete' => 'email'],
    ];
    if (empty($o['program_id'])) {
        $fields[] = ['name' => 'program_id', 'label' => 'Program Interest', 'type' => 'select', 'placeholder' => 'Select Program Interest', 'options' => sa_program_options()];
    }
    $hidden = ['source' => (string) ($o['source'] ?? 'website')];
    if (!empty($o['program_id'])) {
        $hidden['program_id'] = (string) $o['program_id'];
    }
    return enquiry_form([
        'endpoint' => base_url('api/public/enquiry'), 'theme' => 'dark', 'compact' => true, 'icon' => 'graduation-cap',
        'title' => $o['title'] ?? 'Need Help Choosing a Program?', 'subtitle' => $o['subtitle'] ?? 'Get free guidance from our admission counsellors.',
        'submitLabel' => $o['submit'] ?? 'Get Free Counselling', 'hidden' => $hidden, 'fields' => $fields,
        'successTitle' => 'Thank you!', 'successMessage' => 'Our admission counsellor will call you within one working day.',
    ]);
}

/** WhatsApp + brochure cards under the counselling form. */
function sa_sidebar_cards(?array $program = null): string
{
    $html = '';
    $label = $program ? $program['short_name'] : 'our programs';
    if ($wa = sa_whatsapp_url('Hello GIMT, I would like to know more about ' . $label . '.')) {
        $html .= '<a href="' . e($wa) . '" target="_blank" rel="noopener" class="group flex items-center gap-3.5 rounded-2xl border border-emerald-100 bg-emerald-50/70 p-4 transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-card">'
            . '<span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#25D366] text-white shadow-sm">' . icon('whatsapp-brand', 'h-6 w-6') . '</span>'
            . '<span class="min-w-0 flex-1"><span class="block font-display text-[15px] font-bold text-brand-900">Chat on WhatsApp</span><span class="block text-xs text-slate-600">Quick answers to your questions</span></span>'
            . icon('chevron-right', 'h-4 w-4 text-slate-400 transition group-hover:translate-x-1') . '<span class="sr-only">(opens in a new tab)</span></a>';
    }
    $html .= '<div id="brochure" class="scroll-mt-28 rounded-2xl border border-brand-100 bg-gradient-to-br from-brand-50 to-white p-5 text-center">'
        . '<div class="flex items-center justify-center gap-3 text-left"><span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white text-brand-700 shadow-sm ring-1 ring-brand-100">' . icon('file-text', 'h-6 w-6') . '</span>'
        . '<span><span class="block font-display text-[15px] font-bold text-brand-900">' . ($program ? e($program['short_name']) . ' Brochure' : 'Program Brochure') . '</span><span class="block text-xs text-slate-600">Download our detailed ' . ($program ? 'program' : 'programs') . ' brochure</span></span></div>'
        . '<a href="' . e(sa_brochure_url($program)) . '" target="_blank" rel="noopener" class="btn btn-outline btn-sm mt-4 w-full">' . icon('download', 'h-4 w-4') . 'Download Brochure<span class="sr-only"> (opens in a new tab)</span></a></div>';
    return $html;
}

/** Fee structures of a program for the current session: [['label', 'total', 'items' => [[head, amount, optional]]]]. */
function sa_fee_structures(array $p): array
{
    $s = sa_session();
    if (!$s) {
        return [];
    }
    $rows = sa_try(fn () => db_all("SELECT id, name, year_no, semester_no, total_amount, installments_allowed, due_date FROM fee_structures
            WHERE program_id = ? AND academic_session_id = ? AND status = 'active' ORDER BY COALESCE(year_no, 0), COALESCE(semester_no, 0), id", [(int) $p['id'], (int) $s['id']]), []);
    if (!$rows) {
        return [];
    }
    $items = [];
    foreach (sa_try(fn () => db_all('SELECT fsi.fee_structure_id, fh.name, fsi.amount, fsi.is_optional FROM fee_structure_items fsi
            JOIN fee_heads fh ON fh.id = fsi.fee_head_id WHERE fsi.fee_structure_id IN (' . implode(',', array_fill(0, count($rows), '?')) . ')
            ORDER BY fh.sort_order, fh.name', array_map(fn ($r) => (int) $r['id'], $rows)), []) as $it) {
        $items[(int) $it['fee_structure_id']][] = $it;
    }
    return array_map(fn ($r) => $r + ['items' => $items[(int) $r['id']] ?? []], $rows);
}

/**
 * Program detail hero: navy campus backdrop (high-res) with the program photo framed on the right and floating glass
 * chips. Same building blocks as page_hero() (page-hero, overlay, dot pattern, breadcrumbs).
 * $o: title, eyebrow, subtitle, text, actions (HTML), crumbs (page_hero format), chips [[icon, label]], image_alt
 */
function sa_program_hero(array $p, array $o = []): void
{
    $bg = site_image('assets/images/site/campus-building.jpg');
    $img = site_image($p['image'] ?? null, 'assets/images/site/campus-building.jpg');
    echo '<section class="page-hero">';
    echo '<div class="page-hero-media"><img src="' . e($bg) . '" alt="" class="parallax-media" data-parallax="0.12" fetchpriority="high" decoding="async"></div>';
    echo '<div class="absolute inset-0 -z-[1] bg-hero-overlay"></div>';
    echo '<div class="pointer-events-none absolute inset-y-0 left-0 -z-[1] w-2/3 pattern-dots-light pattern-fade animate-pattern-drift opacity-60" aria-hidden="true"></div>';
    echo '<div class="blob -left-24 -top-24 -z-[1] h-72 w-72 bg-accent-500/20" aria-hidden="true"></div>';
    echo '<div class="container-site relative grid items-center gap-10 pb-20 pt-12 sm:pb-24 sm:pt-16 lg:grid-cols-12">';
    echo '<div class="min-w-0 lg:col-span-7">';
    echo '<nav aria-label="Breadcrumb" class="mb-5 animate-fade-down"><ol class="flex flex-wrap items-center gap-1.5 text-sm text-white/70"><li><a href="' . e(site_url('')) . '" class="underline-grow hover:text-white">Home</a></li>';
    foreach ($o['crumbs'] ?? [] as $label => $url) {
        echo '<li aria-hidden="true">' . icon('chevron-right', 'h-3.5 w-3.5') . '</li>';
        echo is_string($label) ? '<li><a class="underline-grow hover:text-white" href="' . e(cms_link($url)) . '">' . e($label) . '</a></li>' : '<li class="font-medium text-white" aria-current="page">' . e($url) . '</li>';
    }
    echo '</ol></nav>';
    if (!empty($o['eyebrow'])) {
        echo '<p class="eyebrow mb-3 !text-accent-400 animate-fade-up">' . e($o['eyebrow']) . '</p>';
    }
    echo '<h1 class="max-w-3xl font-display text-4xl font-extrabold leading-tight tracking-tight text-white sm:text-5xl animate-fade-up">' . e($o['title'] ?? $p['name']) . '</h1>';
    if (!empty($o['subtitle'])) {
        echo '<p class="mt-2 font-display text-xl font-bold text-accent-400 sm:text-2xl animate-fade-up delay-1">' . e($o['subtitle']) . '</p>';
    }
    if (!empty($o['text'])) {
        echo '<p class="mt-4 max-w-2xl text-base leading-relaxed text-white/85 sm:text-lg animate-fade-up delay-2">' . e($o['text']) . '</p>';
    }
    if (!empty($o['actions'])) {
        echo '<div class="mt-7 flex flex-wrap gap-3 animate-fade-up delay-3">' . $o['actions'] . '</div>';
    }
    echo '</div>';
    echo '<div class="relative mx-auto w-full max-w-lg lg:col-span-5 animate-zoom-in delay-2">';
    echo '<div class="img-zoom overflow-hidden rounded-3xl shadow-pop ring-4 ring-white/15"><img src="' . e($img) . '" alt="' . e($o['image_alt'] ?? $p['name']) . '" width="684" height="342" class="aspect-[2/1] w-full object-cover" fetchpriority="high" decoding="async"></div>';
    $chips = $o['chips'] ?? [];
    if (!empty($chips[0])) {
        echo '<span class="float-slow absolute -bottom-5 left-4">' . glass_chip($chips[0][0], $chips[0][1], 'white', 'shadow-soft') . '</span>';
    }
    if (!empty($chips[1])) {
        echo '<span class="float-delayed absolute -top-5 right-4 hidden sm:block">' . glass_chip($chips[1][0], $chips[1][1], 'white', 'shadow-soft') . '</span>';
    }
    echo '</div></div></section>';
}

/**
 * Fee structure summary for the current session (from sa_fee_structures()):
 * ['rows' => [[label, year, items, total, installments]], 'first_year' => float, 'total' => float].
 */
function sa_fee_summary(array $structures): array
{
    $rows = [];
    $firstYear = 0.0;
    $total = 0.0;
    $minYear = min(array_map(fn ($s) => (int) ($s['year_no'] ?: 1), $structures));
    foreach ($structures as $s) {
        $label = $s['semester_no'] ? 'Semester ' . (int) $s['semester_no'] : ($s['year_no'] ? 'Year ' . (int) $s['year_no'] : (string) $s['name']);
        $rows[] = ['label' => $label, 'year' => $s['year_no'] ? 'Year ' . (int) $s['year_no'] : '', 'items' => $s['items'], 'total' => (float) $s['total_amount'], 'installments' => (int) $s['installments_allowed']];
        $total += (float) $s['total_amount'];
        if ((int) ($s['year_no'] ?: 1) === $minYear) {
            $firstYear += (float) $s['total_amount'];
        }
    }
    return ['rows' => $rows, 'first_year' => $firstYear, 'total' => $total];
}

/** Semester-wise subjects of a program: [semester_no => rows]. */
function sa_curriculum(int $programId): array
{
    $out = [];
    foreach (sa_try(fn () => db_all("SELECT semester_no, code, name, type, is_elective, credits FROM subjects WHERE program_id = ? AND status = 'active' ORDER BY semester_no, is_elective, code", [$programId]), []) as $s) {
        $out[(int) $s['semester_no']][] = $s;
    }
    ksort($out);
    return $out;
}

/** Subject type badge. */
function sa_subject_type(array $s): string
{
    if (!empty($s['is_elective'])) {
        return '<span class="badge badge-purple">Elective</span>';
    }
    return ['theory' => '<span class="badge badge-blue">Theory</span>', 'practical' => '<span class="badge badge-green">Practical</span>', 'lab' => '<span class="badge badge-cyan">Lab</span>',
        'project' => '<span class="badge badge-amber">Project</span>', 'elective' => '<span class="badge badge-purple">Elective</span>'][$s['type']] ?? '<span class="badge badge-slate">' . e(ucfirst((string) $s['type'])) . '</span>';
}

/* ============================================================================ Departments & faculty */

/** Active departments with HOD (public fields), program + faculty counts and website programs. */
function sa_departments(): array
{
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }
    $rows = sa_try(fn () => db_all("SELECT d.id, d.name, d.code, d.established_year, d.description, d.image,
            f.id AS hod_id, f.title AS hod_title, f.first_name AS hod_first, f.last_name AS hod_last, f.designation AS hod_designation,
            f.qualification AS hod_qualification, f.photo AS hod_photo,
            (SELECT COUNT(*) FROM faculty x WHERE x.department_id = d.id AND x.status IN ('active','on_leave') AND x.show_on_website = 1) AS faculty_count
            FROM departments d LEFT JOIN faculty f ON f.id = d.hod_faculty_id AND f.show_on_website = 1 AND f.status IN ('active','on_leave')
            WHERE d.status = 'active' ORDER BY COALESCE(d.established_year, 9999), d.name"), []);
    $byDept = [];
    foreach (sa_programs() as $p) {
        $byDept[(int) $p['department_id']][] = $p;
    }
    foreach ($rows as &$d) {
        $d['programs'] = $byDept[(int) $d['id']] ?? [];
    }
    unset($d);
    return $rows;
}

/** Fallback photo for a department card. */
function sa_department_image(array $d): string
{
    $map = ['MGT' => 'program-mba.jpg', 'CSE' => 'program-btech.jpg', 'DCA' => 'program-dca.jpg', 'COM' => 'program-bcom.jpg', 'SCI' => 'facility-labs.jpg', 'PSD' => 'program-digital-marketing.jpg'];
    return site_image($d['image'] ?? null, 'assets/images/site/' . ($map[strtoupper((string) $d['code'])] ?? 'campus-academic-block.jpg'));
}

/** Icon for a department. */
function sa_department_icon(array $d): string
{
    return ['MGT' => 'briefcase-business', 'CSE' => 'cpu', 'DCA' => 'laptop', 'COM' => 'chart-column', 'SCI' => 'flask-conical', 'PSD' => 'rocket'][strtoupper((string) $d['code'])] ?? 'school';
}

/**
 * Public faculty list (safe columns only), ordered by seniority.
 * @param array $o department_id (int), limit (int), order ('rank'|'publications')
 */
function sa_faculty(array $o = []): array
{
    $where = ["f.status IN ('active','on_leave')", 'f.show_on_website = 1'];
    $args = [];
    if (!empty($o['department_id'])) {
        $where[] = 'f.department_id = ?';
        $args[] = (int) $o['department_id'];
    }
    $rank = "CASE WHEN f.designation LIKE '%Director%' OR f.designation LIKE '%Dean%' THEN 0 WHEN f.designation LIKE '%Head%' THEN 1
              WHEN f.designation LIKE 'Professor%' THEN 2 WHEN f.designation LIKE 'Associate%' THEN 3 WHEN f.designation LIKE 'Assistant%' THEN 4 ELSE 5 END";
    $order = ($o['order'] ?? 'rank') === 'publications' ? "$rank > 2, COALESCE(f.publications_count, 0) DESC, f.first_name" : "$rank, f.first_name, f.last_name";
    $limit = max(1, min(500, (int) ($o['limit'] ?? 500)));
    return sa_try(fn () => db_all("SELECT f.id, f.title, f.first_name, f.last_name, f.photo, f.designation, f.department_id, f.qualification, f.specialization,
            f.experience_years, f.bio, f.research_interests, f.publications_count, f.linkedin_url, d.name AS department_name, d.code AS department_code
            FROM faculty f LEFT JOIN departments d ON d.id = f.department_id
            WHERE " . implode(' AND ', $where) . " ORDER BY $order LIMIT $limit", $args), []);
}

/** Display name with title: "Dr. Mansi Yadav". */
function sa_person_name(array $f, string $prefix = ''): string
{
    return trim(implode(' ', array_filter([$f[$prefix . 'title'] ?? '', $f[$prefix . 'first_name'] ?? $f[$prefix . 'first'] ?? '', $f[$prefix . 'last_name'] ?? $f[$prefix . 'last'] ?? ''])));
}

/** Avatar: uploaded photo or gradient initials. */
function sa_avatar(?string $photo, string $name, int $seed = 0, string $class = 'h-16 w-16 text-lg'): string
{
    if ($photo) {
        return '<img src="' . e(upload_url($photo)) . '" alt="Photo of ' . e($name) . '" loading="lazy" decoding="async" class="' . e($class) . ' shrink-0 rounded-full object-cover ring-4 ring-white">';
    }
    $grad = ['from-brand-700 to-brand-900', 'from-accent-600 to-brand-800', 'from-cyan-600 to-brand-800', 'from-violet-600 to-brand-800', 'from-amber-500 to-brand-800'][$seed % 5];
    $clean = trim(preg_replace('/^(Dr\.|Prof\.|Mr\.|Ms\.|Mrs\.)\s*/i', '', $name));
    return '<span class="' . e($class) . ' inline-flex shrink-0 items-center justify-center rounded-full bg-gradient-to-br ' . $grad . ' font-display font-extrabold text-white ring-4 ring-white" aria-hidden="true">' . e(initials($clean)) . '</span>';
}

/** Faculty card (directory / highlights). $o: modal (bool, adds the profile button + template), reveal. */
function sa_faculty_card(array $f, array $o = []): string
{
    $name = sa_person_name($f);
    $exp = (float) ($f['experience_years'] ?? 0);
    $html = '<article class="group relative flex h-full flex-col rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm transition duration-300 hover:-translate-y-1 hover:border-brand-200 hover:shadow-card"' . (!empty($o['reveal']) ? reveal_attr('fade-up', (int) ($o['delay'] ?? 0)) : '') . '>'
        . '<div class="flex items-start gap-4">' . sa_avatar($f['photo'] ?? null, $name, (int) $f['id'], 'h-16 w-16 text-lg shadow-soft')
        . '<div class="min-w-0"><h3 class="font-display text-base font-bold leading-snug text-brand-900">' . e($name) . '</h3>'
        . '<p class="mt-0.5 text-sm font-medium text-accent-800">' . e($f['designation']) . '</p>'
        . (!empty($f['department_name']) ? '<p class="mt-1 text-xs text-slate-500">' . e($f['department_name']) . '</p>' : '') . '</div></div>'
        . '<dl class="mt-4 space-y-2 text-[13px] text-slate-600">';
    if (!empty($f['qualification'])) {
        $html .= '<div class="flex gap-2"><dt class="sr-only">Qualification</dt>' . icon('graduation-cap', 'mt-0.5 h-4 w-4 shrink-0 text-brand-500') . '<dd>' . e($f['qualification']) . '</dd></div>';
    }
    if (!empty($f['specialization'])) {
        $html .= '<div class="flex gap-2"><dt class="sr-only">Specialization</dt>' . icon('target', 'mt-0.5 h-4 w-4 shrink-0 text-brand-500') . '<dd>' . e($f['specialization']) . '</dd></div>';
    }
    if ($exp > 0) {
        $html .= '<div class="flex gap-2"><dt class="sr-only">Experience</dt>' . icon('briefcase', 'mt-0.5 h-4 w-4 shrink-0 text-brand-500') . '<dd>' . e(rtrim(rtrim(number_format($exp, 1), '0'), '.')) . '+ years experience</dd></div>';
    }
    $html .= '</dl>';
    if (!empty($o['modal'])) {
        $html .= '<div class="mt-auto pt-5"><button type="button" class="btn btn-outline btn-sm w-full" data-modal-open="#faculty-profile" data-faculty-open="' . (int) $f['id'] . '">View Profile<span class="sr-only"> of ' . e($name) . '</span>' . icon('arrow-right', 'h-4 w-4') . '</button></div>'
            . '<template id="faculty-tpl-' . (int) $f['id'] . '">' . sa_faculty_profile($f) . '</template>';
    }
    return $html . '</article>';
}

/** Profile body shown in the faculty modal. */
function sa_faculty_profile(array $f): string
{
    $name = sa_person_name($f);
    $html = '<div class="flex flex-col items-center gap-4 text-center sm:flex-row sm:items-center sm:text-left">' . sa_avatar($f['photo'] ?? null, $name, (int) $f['id'], 'h-24 w-24 text-2xl shadow-soft')
        . '<div><h2 id="faculty-profile-title" class="font-display text-2xl font-extrabold text-brand-900">' . e($name) . '</h2>'
        . '<p class="mt-1 font-semibold text-accent-800">' . e($f['designation']) . '</p>'
        . (!empty($f['department_name']) ? '<p class="text-sm text-slate-500">' . e($f['department_name']) . '</p>' : '') . '</div></div>';
    $facts = [];
    if (!empty($f['qualification'])) {
        $facts[] = ['graduation-cap', 'Qualification', $f['qualification']];
    }
    if (!empty($f['specialization'])) {
        $facts[] = ['target', 'Specialization', $f['specialization']];
    }
    if ((float) ($f['experience_years'] ?? 0) > 0) {
        $facts[] = ['briefcase', 'Experience', rtrim(rtrim(number_format((float) $f['experience_years'], 1), '0'), '.') . '+ years'];
    }
    if ((int) ($f['publications_count'] ?? 0) > 0) {
        $facts[] = ['book-open', 'Publications', (int) $f['publications_count'] . ' research papers & articles'];
    }
    if ($facts) {
        $html .= '<dl class="mt-6 grid gap-3 sm:grid-cols-2">';
        foreach ($facts as [$ic, $label, $val]) {
            $html .= '<div class="flex gap-3 rounded-xl bg-slate-50 p-3 text-left"><span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-brand-700 shadow-sm">' . icon($ic, 'h-4 w-4') . '</span>'
                . '<div><dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">' . e($label) . '</dt><dd class="text-sm font-medium text-slate-700">' . e($val) . '</dd></div></div>';
        }
        $html .= '</dl>';
    }
    if (!empty($f['bio'])) {
        $html .= '<div class="mt-6 text-left"><h3 class="font-sans text-xs font-bold uppercase tracking-wider text-slate-400">About</h3><p class="mt-2 text-sm leading-relaxed text-slate-600">' . nl2br(e(str_limit($f['bio'], 900))) . '</p></div>';
    }
    if (!empty($f['research_interests'])) {
        $html .= '<div class="mt-5 text-left"><h3 class="font-sans text-xs font-bold uppercase tracking-wider text-slate-400">Research interests</h3><ul class="mt-2 flex flex-wrap gap-2">';
        foreach (array_filter(array_map('trim', explode(',', $f['research_interests']))) as $r) {
            $html .= '<li class="rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-800">' . e(ucfirst($r)) . '</li>';
        }
        $html .= '</ul></div>';
    }
    if (!empty($f['linkedin_url']) && preg_match('#^https://([a-z]+\.)?linkedin\.com/#i', $f['linkedin_url'])) {
        $html .= '<a href="' . e($f['linkedin_url']) . '" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm mt-6">' . icon('linkedin', 'h-4 w-4') . 'LinkedIn profile<span class="sr-only"> (opens in a new tab)</span></a>';
    }
    return $html;
}

/** Research areas aggregated from faculty research interests: [label => count], most common first. */
function sa_research_areas(int $limit = 12): array
{
    $counts = [];
    $labels = [];
    foreach (sa_try(fn () => db_column("SELECT research_interests FROM faculty WHERE status IN ('active','on_leave') AND show_on_website = 1 AND research_interests IS NOT NULL AND research_interests <> ''"), []) as $txt) {
        foreach (array_filter(array_map('trim', explode(',', (string) $txt))) as $r) {
            $k = strtolower($r);
            $labels[$k] ??= ucfirst($r);
            $counts[$k] = ($counts[$k] ?? 0) + 1;
        }
    }
    arsort($counts);
    $out = [];
    foreach (array_slice($counts, 0, $limit, true) as $k => $n) {
        $out[$labels[$k]] = $n;
    }
    return $out;
}

/* ============================================================================ Campus data */

/** Recruiter wordmarks: companies table (is_recruiter) → defaults. No logo images (trademarks). */
function sa_recruiters(int $limit = 16): array
{
    // Only companies the institute has marked as recruiters (verified content) — never an invented fallback list.
    $rows = sa_try(fn () => db_all("SELECT name, industry, logo, website FROM companies WHERE status = 'active' AND is_recruiter = 1 ORDER BY (logo IS NULL OR logo = ''), sort_order, name LIMIT " . max(1, $limit)), []);
    return array_map(fn ($r) => array_filter([
        'name' => $r['name'],
        'industry' => (string) ($r['industry'] ?? ''),
        'logo' => !empty($r['logo']) ? site_image($r['logo']) : null,
    ], fn ($v) => $v !== null), $rows);
}

/**
 * Active testimonials from the CMS (optionally for one program), or [] — quotes attributed to named people are never
 * invented, so the testimonial section is simply omitted until the CMS has entries.
 */
function sa_testimonials(int $limit = 6, ?int $programId = null): array
{
    $sql = "SELECT t.*, p.short_name AS program_short FROM testimonials t LEFT JOIN programs p ON p.id = t.program_id WHERE t.status = 'active'" . ($programId ? ' AND t.program_id = ?' : '')
        . " ORDER BY (t.type = 'alumni') DESC, t.sort_order, t.id DESC LIMIT " . max(1, $limit);
    $rows = sa_try(fn () => db_all($sql, $programId ? [$programId] : []), []);
    return array_map(fn ($t) => array_filter(['name' => $t['name'], 'quote' => trim(strip_tags((string) $t['content'])), 'role' => (string) ($t['designation'] ?? ''),
        'company' => (string) ($t['company'] ?? ''), 'program' => (string) ($t['program_short'] ?? ''), 'photo' => $t['photo'] ? upload_url($t['photo']) : null,
        'rating' => (int) $t['rating']], fn ($v) => $v !== null && $v !== ''), $rows);
}

/** Bundled campus photography (always available). */
function sa_bundled_photos(): array
{
    $p = fn ($file, $alt, $caption, $cat) => ['src' => asset('assets/images/site/' . $file), 'alt' => $alt, 'caption' => $caption, 'category' => $cat];
    return [
        $p('campus-main.jpg', 'GIMT main campus building in Knowledge Park, Greater Noida', 'Main Campus', 'Campus'),
        $p('facility-classroom.jpg', 'Smart classroom with interactive displays', 'Smart Classrooms', 'Facilities'),
        $p('facility-labs.jpg', 'Students working in the computer laboratory', 'Advanced Laboratories', 'Facilities'),
        $p('campus-library.jpg', 'Library and learning centre reading hall', 'Library & Learning Centre', 'Facilities'),
        $p('students-atrium.jpg', 'Students walking through the campus atrium', 'Student Atrium', 'Student Life'),
        $p('facility-sports.jpg', 'Outdoor sports courts on campus', 'Sports & Recreation', 'Student Life'),
        $p('campus-hostel.jpg', 'On-campus hostel building', 'Hostel Facility', 'Hostel'),
        $p('facility-green-campus.jpg', 'Tree-lined green campus lawns', 'Green & Safe Campus', 'Campus'),
        $p('students-lawn.jpg', 'Students studying together on the campus lawn', 'Campus Lawns', 'Student Life'),
        $p('campus-academic-block.jpg', 'Academic block exterior', 'Academic Block', 'Campus'),
        $p('graduation.jpg', 'Graduates celebrating at convocation', 'Convocation', 'Events'),
        $p('news-campus.jpg', 'Students at a campus event', 'Campus Events', 'Events'),
    ];
}

/** Gallery photos: CMS gallery items first, topped up with bundled photography. */
function sa_gallery(int $limit = 12): array
{
    $rows = sa_try(fn () => db_all("SELECT COALESCE(NULLIF(gi.image, ''), m.path) AS image, gi.caption, g.title, g.category FROM gallery_items gi
            JOIN galleries g ON g.id = gi.gallery_id LEFT JOIN media m ON m.id = gi.media_id
            WHERE g.status = 'published' AND (NULLIF(gi.image, '') IS NOT NULL OR m.path IS NOT NULL)
            ORDER BY g.sort_order, g.id DESC, gi.sort_order LIMIT " . max(1, $limit)), []);
    $items = array_map(fn ($r) => ['src' => site_image($r['image']), 'alt' => (string) ($r['caption'] ?: $r['title']), 'caption' => (string) ($r['caption'] ?: $r['title']),
        'category' => ucwords(str_replace(['_', '-'], ' ', (string) ($r['category'] ?: 'Campus')))], $rows);
    foreach (sa_bundled_photos() as $b) {
        if (count($items) >= $limit) {
            break;
        }
        $items[] = $b;
    }
    return $items;
}

/** Gallery island with an always-visible caption fallback (captions also shown on the island tiles). */
function sa_gallery_grid(array $items, array $props = []): string
{
    static $n = 0;
    $group = 'sa-gallery-' . (++$n);
    $props['items'] = array_values($items);
    $cols = (int) ($props['columns'] ?? 3);
    $colCls = [2 => 'sm:grid-cols-2', 3 => 'sm:grid-cols-2 lg:grid-cols-3', 4 => 'sm:grid-cols-3 lg:grid-cols-4'][$cols] ?? 'sm:grid-cols-2 lg:grid-cols-3';
    $bento = ($props['layout'] ?? 'grid') === 'bento';
    $html = '<div class="gallery"><ul class="grid grid-cols-2 gap-3 sm:gap-4 ' . $colCls . ($bento ? ' gallery-bento' : '') . '">';
    foreach ($props['items'] as $it) {
        $html .= '<li><a href="' . e($it['src']) . '" data-lightbox="' . $group . '" data-caption="' . e($it['caption'] ?? $it['alt']) . '" class="gallery-tile img-zoom group aspect-[4/3]">'
            . '<img src="' . e($it['thumb'] ?? $it['src']) . '" alt="' . e($it['alt']) . '" loading="lazy" decoding="async" class="h-full w-full object-cover">'
            . '<span class="gallery-tile-overlay" aria-hidden="true">' . icon('search', 'h-5 w-5') . (!empty($it['caption']) ? '<span class="gallery-tile-caption">' . e($it['caption']) . '</span>' : '') . '</span></a></li>';
    }
    $html .= '</ul></div>';
    // captions always visible (mockup): overlay shown, zoom badge only on hover/focus
    $wrap = 'block [&_.gallery-tile-overlay]:opacity-100 [&_.gallery-tile-overlay]:[background:linear-gradient(180deg,rgba(21,20,25,0)_45%,rgba(21,20,25,.8))] [&_.gallery-tile-overlay>svg]:opacity-0 [&_.gallery-tile:hover_.gallery-tile-overlay>svg]:opacity-100 [&_.gallery-tile:focus-visible_.gallery-tile-overlay>svg]:opacity-100 [&_.gallery-tile-caption]:text-white';
    return island('GalleryLightbox', $props, $html, ['class' => $wrap . (!empty($props['class']) ? ' ' . $props['class'] : '')]);
}

/**
 * Campus events for the website: upcoming published events, then upcoming calendar events (holidays of type "event"),
 * topped up with events held earlier this session (flagged past). Normalised rows.
 */
function sa_events(int $limit = 6): array
{
    $limit = max(1, $limit);
    $hasEventPage = is_file(APP_ROOT . '/event.php');
    $fromEvent = fn (array $r, bool $past) => ['date' => substr($r['start_datetime'], 0, 10), 'time' => substr($r['start_datetime'], 11, 5), 'end' => $r['end_datetime'] ? substr($r['end_datetime'], 0, 10) : null,
        'title' => $r['title'], 'meta' => (string) ($r['venue'] ?: 'GIMT Campus'), 'category' => ucfirst((string) $r['category']), 'past' => $past,
        'url' => $hasEventPage ? site_url('events/' . $r['slug']) : site_url('academics#calendar'), 'image' => $r['image'] ? site_image($r['image']) : null, 'text' => (string) ($r['excerpt'] ?? '')];
    $fromHoliday = fn (array $h, bool $past) => ['date' => $h['holiday_date'], 'time' => '', 'end' => $h['end_date'], 'title' => $h['title'], 'meta' => 'GIMT Campus',
        'category' => 'Campus Event', 'past' => $past, 'url' => site_url('academics#calendar'), 'image' => null, 'text' => ''];
    $out = array_map(fn ($r) => $fromEvent($r, false), sa_try(fn () => db_all("SELECT title, slug, category, venue, start_datetime, end_datetime, image, excerpt FROM events
            WHERE status = 'published' AND show_on_website = 1 AND start_datetime >= CURDATE() ORDER BY start_datetime LIMIT $limit"), []));
    if (count($out) < $limit) {
        foreach (sa_try(fn () => db_all("SELECT title, holiday_date, end_date, description FROM holidays WHERE type = 'event' AND holiday_date >= CURDATE() ORDER BY holiday_date LIMIT " . ($limit - count($out))), []) as $h) {
            $out[] = $fromHoliday($h, false);
        }
    }
    if (count($out) < $limit) {
        $since = sa_session()['start_date'] ?? date('Y-m-d', strtotime('-6 months'));
        $past = array_merge(
            array_map(fn ($r) => $fromEvent($r, true), sa_try(fn () => db_all("SELECT title, slug, category, venue, start_datetime, end_datetime, image, excerpt FROM events
                WHERE status IN ('published','completed') AND show_on_website = 1 AND start_datetime < CURDATE() AND start_datetime >= ? ORDER BY start_datetime DESC LIMIT $limit", [$since]), [])),
            array_map(fn ($h) => $fromHoliday($h, true), sa_try(fn () => db_all("SELECT title, holiday_date, end_date, description FROM holidays WHERE type = 'event' AND holiday_date < CURDATE() AND holiday_date >= ? ORDER BY holiday_date DESC LIMIT $limit", [$since]), []))
        );
        usort($past, fn ($a, $b) => strcmp($b['date'], $a['date']));
        $out = array_merge($out, array_slice($past, 0, $limit - count($out)));
    }
    return $out;
}

/** Event card with date tile. */
function sa_event_card(array $ev): string
{
    $ts = strtotime($ev['date']);
    $range = $ev['end'] && $ev['end'] !== $ev['date'] ? date('d M', $ts) . ' – ' . date('d M Y', strtotime($ev['end'])) : date('l, d M Y', $ts);
    return '<article class="group relative flex h-full gap-4 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm transition duration-300 hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-card">'
        . '<span class="date-tile"><span class="date-tile-day">' . date('d', $ts) . '</span><span class="date-tile-mon">' . date('M', $ts) . '</span></span>'
        . '<span class="min-w-0"><span class="flex flex-wrap gap-1.5"><span class="badge badge-blue">' . e($ev['category']) . '</span>' . (!empty($ev['past']) ? '<span class="badge badge-slate">Held</span>' : '<span class="badge badge-green">Upcoming</span>') . '</span>'
        . '<a href="' . e($ev['url']) . '" class="stretched-link mt-1.5 block font-semibold leading-snug text-brand-900 group-hover:text-brand-700">' . e($ev['title']) . '</a>'
        . '<span class="mt-1 flex items-center gap-1 text-xs text-slate-500">' . icon('calendar-days', 'h-3.5 w-3.5') . e($range) . ($ev['time'] && $ev['time'] !== '00:00' ? ' · ' . e(format_time($ev['time'])) : '') . '</span>'
        . '<span class="mt-0.5 flex items-center gap-1 text-xs text-slate-500">' . icon('map-pin', 'h-3.5 w-3.5') . e(str_limit($ev['meta'], 60)) . '</span></span></article>';
}

/** Hostel summary from the hostel module, or null when no hostel is set up. */
function sa_hostel_summary(): ?array
{
    $hostels = sa_try(fn () => db_all("SELECT * FROM hostels WHERE status = 'active' ORDER BY name"), []);
    if (!$hostels) {
        return null;
    }
    $rooms = (int) sa_try(fn () => db_value("SELECT COUNT(*) FROM hostel_rooms r JOIN hostels h ON h.id = r.hostel_id WHERE h.status = 'active' AND r.status <> 'inactive'"), 0);
    $beds = (int) sa_try(fn () => db_value("SELECT COALESCE(SUM(r.capacity), 0) FROM hostel_rooms r JOIN hostels h ON h.id = r.hostel_id WHERE h.status = 'active' AND r.status <> 'inactive'"), 0);
    $amen = [];
    $labels = ['wifi' => 'Wi-Fi', 'ro_water' => 'RO drinking water', 'hot_water' => 'Hot water', 'power_backup' => 'Power backup', 'cctv' => 'CCTV security',
        'ac' => 'Air-conditioned rooms', 'gym' => 'Gym', 'mess' => 'Mess', 'laundry' => 'Laundry', 'housekeeping' => 'Housekeeping', 'common_room' => 'Common room', 'reading_room' => 'Reading room'];
    foreach ($hostels as $h) {
        $raw = (string) $h['amenities'];
        $list = json_decode($raw, true);
        $list = is_array($list) ? $list : preg_split('/[,;\n]/', $raw);
        foreach (array_filter(array_map(fn ($v) => trim((string) $v), $list)) as $a) {
            $key = strtolower(str_replace([' ', '-'], '_', $a));
            $amen[$key] = $labels[$key] ?? ucfirst(str_replace('_', ' ', $a));
        }
    }
    $fees = array_filter(array_map(fn ($h) => (float) $h['annual_fee'], $hostels));
    return ['hostels' => $hostels, 'count' => count($hostels), 'rooms' => $rooms, 'beds' => $beds, 'amenities' => array_slice(array_values($amen), 0, 8),
        'boys' => count(array_filter($hostels, fn ($h) => $h['type'] === 'boys')), 'girls' => count(array_filter($hostels, fn ($h) => $h['type'] === 'girls')),
        'fee_from' => $fees ? min($fees) : null];
}

/** Transport summary from the transport module, or null when no route is set up. */
function sa_transport_summary(): ?array
{
    $routes = sa_try(fn () => db_all("SELECT id, name, start_point, end_point, morning_departure FROM transport_routes WHERE status = 'active' ORDER BY name"), []);
    if (!$routes) {
        return null;
    }
    $vehicles = (int) sa_try(fn () => db_value("SELECT COUNT(*) FROM vehicles WHERE status = 'active'"), 0);
    $stops = (int) sa_try(fn () => db_value("SELECT COUNT(*) FROM transport_stops s JOIN transport_routes r ON r.id = s.route_id WHERE r.status = 'active'"), 0);
    $areas = [];
    foreach ($routes as $r) {
        foreach ([$r['start_point'], $r['end_point']] as $pt) {
            $pt = trim((string) $pt);
            if ($pt !== '' && stripos($pt, 'GIMT') === false && stripos($pt, 'campus') === false) {
                $areas[strtolower($pt)] = $pt;
            }
        }
    }
    $t = sa_try(fn () => db_row("SELECT MIN(morning_departure) AS first_pickup, MAX(evening_departure) AS last_drop, MIN(annual_fare) AS fare_min, MAX(annual_fare) AS fare_max FROM transport_routes WHERE status = 'active'")) ?: [];
    return ['routes' => count($routes), 'vehicles' => $vehicles, 'stops' => $stops, 'areas' => array_slice(array_values($areas), 0, 10),
        'first_pickup' => $t['first_pickup'] ?? null, 'last_drop' => $t['last_drop'] ?? null, 'fare_min' => isset($t['fare_min']) ? (float) $t['fare_min'] : null, 'fare_max' => isset($t['fare_max']) ? (float) $t['fare_max'] : null];
}

/**
 * Library summary from the library module, or null: titles, copies, journals, ebooks, categories (count),
 * top_categories (names, most books first) and the usual student borrowing rule (books, days).
 */
function sa_library_summary(): ?array
{
    $row = sa_try(fn () => db_row("SELECT COUNT(*) AS titles, COALESCE(SUM(total_copies), 0) AS copies,
            COALESCE(SUM(type IN ('journal','magazine')), 0) AS journals, COALESCE(SUM(type = 'ebook'), 0) AS ebooks FROM books WHERE status = 'active'"));
    if (!$row || (int) $row['titles'] === 0) {
        return null;
    }
    $out = array_map('intval', $row);
    $cats = sa_try(fn () => db_all("SELECT c.name, COUNT(b.id) AS n FROM book_categories c JOIN books b ON b.category_id = c.id AND b.status = 'active'
            WHERE c.status = 'active' GROUP BY c.id, c.name ORDER BY n DESC, c.name"), []);
    $out['categories'] = count($cats);
    $out['top_categories'] = array_slice(array_column($cats, 'name'), 0, 6);
    $rule = sa_try(fn () => db_row("SELECT max_books, loan_days FROM library_members WHERE member_type = 'student' AND status = 'active'
            GROUP BY max_books, loan_days ORDER BY COUNT(*) DESC LIMIT 1"));
    $out['student_books'] = $rule ? (int) $rule['max_books'] : null;
    $out['student_days'] = $rule ? (int) $rule['loan_days'] : null;
    return $out;
}

/** Grade scale rows (grade, range, points) for the assessment card, or []. */
function sa_grade_scale(): array
{
    return sa_try(fn () => db_all('SELECT grade, min_percent, max_percent, grade_point, description, is_pass FROM grade_scales ORDER BY sort_order, min_percent DESC'), []);
}

/** Labs, seminar halls and auditorium from the classrooms table. */
function sa_learning_spaces(): array
{
    return sa_try(fn () => db_all("SELECT name, type, capacity, building, facilities FROM classrooms WHERE status = 'active' AND type IN ('lab','seminar_hall','auditorium')
            ORDER BY FIELD(type, 'lab', 'seminar_hall', 'auditorium'), name"), []);
}

/** Total classroom seats. */
function sa_classroom_seats(): int
{
    return (int) sa_try(fn () => db_value("SELECT COALESCE(SUM(capacity), 0) FROM classrooms WHERE status = 'active' AND type = 'classroom'"), 0);
}

/** FAQs from the CMS faqs table for categories (q/a), or []. */
function sa_faqs(array $categories, int $limit = 6): array
{
    if (!$categories) {
        return [];
    }
    $rows = sa_try(fn () => db_all("SELECT question, answer, category FROM faqs WHERE status = 'active' AND category IN (" . implode(',', array_fill(0, count($categories), '?')) . ')
            ORDER BY sort_order, id LIMIT ' . max(1, $limit), $categories), []);
    return array_map(fn ($r) => ['q' => $r['question'], 'a' => trim(strip_tags((string) $r['answer'])), 'category' => ucfirst((string) $r['category'])], $rows);
}

/* ============================================================================ Academic calendar */

/** "29 program schedules" / "1 program schedule". */
function sa_schedules_label(int $n): string
{
    return $n . ' program schedule' . ($n === 1 ? '' : 's');
}

/**
 * Academic calendar entries for a session: holidays/vacations/exam breaks/events (holidays table), exams (exams table,
 * scheduled+) and published events. Each: date, end, title, kind (holiday|vacation|exam|event), meta.
 */
function sa_calendar(?array $session): array
{
    if (!$session) {
        return [];
    }
    [$from, $to, $sid] = [$session['start_date'], $session['end_date'], (int) $session['id']];
    $out = [];
    $kinds = ['holiday' => 'holiday', 'vacation' => 'vacation', 'exam_break' => 'exam', 'event' => 'event'];
    foreach (sa_try(fn () => db_all('SELECT title, holiday_date, end_date, type, description FROM holidays WHERE (academic_session_id = ? OR (academic_session_id IS NULL AND holiday_date BETWEEN ? AND ?)) ORDER BY holiday_date', [$sid, $from, $to]), []) as $h) {
        $out[] = ['date' => $h['holiday_date'], 'end' => $h['end_date'], 'title' => $h['title'], 'kind' => $kinds[$h['type']] ?? 'holiday', 'meta' => (string) ($h['description'] ?? '')];
    }
    // exams of the session, aggregated per exam type (one entry per examination window, not per program)
    $exams = sa_try(fn () => db_all("SELECT et.name AS type_name, MIN(e.start_date) AS start_date, MAX(COALESCE(e.end_date, e.start_date)) AS end_date, COUNT(*) AS n
            FROM exams e JOIN exam_types et ON et.id = e.exam_type_id
            WHERE e.academic_session_id = ? AND e.start_date IS NOT NULL AND e.status NOT IN ('draft','cancelled')
            GROUP BY et.id, et.name ORDER BY MIN(e.start_date)", [$sid]), []);
    foreach ($exams as $x) {
        $merged = false;
        foreach ($out as &$en) {
            if ($en['kind'] === 'exam' && $en['date'] <= $x['end_date'] && ($en['end'] ?: $en['date']) >= $x['start_date']) {
                $en['meta'] = trim($en['meta'] . ' · ' . sa_schedules_label((int) $x['n']), ' ·');
                $merged = true;
                break;
            }
        }
        unset($en);
        if (!$merged) {
            $out[] = ['date' => $x['start_date'], 'end' => $x['end_date'] !== $x['start_date'] ? $x['end_date'] : null, 'title' => preg_replace('/(Examination)$/', '$1s', (string) $x['type_name']),
                'kind' => 'exam', 'meta' => sa_schedules_label((int) $x['n'])];
        }
    }
    foreach (sa_try(fn () => db_all("SELECT title, start_datetime, end_datetime, venue FROM events WHERE status IN ('published','completed') AND show_on_website = 1 AND start_datetime BETWEEN ? AND ? ORDER BY start_datetime", [$from . ' 00:00:00', $to . ' 23:59:59']), []) as $ev) {
        $out[] = ['date' => substr($ev['start_datetime'], 0, 10), 'end' => $ev['end_datetime'] ? substr($ev['end_datetime'], 0, 10) : null, 'title' => $ev['title'], 'kind' => 'event', 'meta' => (string) ($ev['venue'] ?? '')];
    }
    usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));
    return $out;
}

/** Calendar timeline grouped by month (HTML). */
function sa_calendar_list(array $entries, string $empty = 'No entries in this period.'): string
{
    if (!$entries) {
        return '<div class="empty-state rounded-2xl border border-dashed border-slate-200 bg-white"><span class="empty-state-icon">' . icon('calendar-days', 'h-6 w-6') . '</span><p class="mt-3 font-semibold text-brand-900">' . e($empty) . '</p></div>';
    }
    $badge = ['holiday' => ['badge-red', 'Holiday'], 'vacation' => ['badge-amber', 'Vacation'], 'exam' => ['badge-purple', 'Examination'], 'event' => ['badge-green', 'Event']];
    $today = date('Y-m-d');
    $months = [];
    foreach ($entries as $en) {
        $months[date('F Y', strtotime($en['date']))][] = $en;
    }
    $html = '<div class="grid gap-6 md:grid-cols-2">';
    foreach ($months as $month => $list) {
        $html .= '<section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm"><h3 class="mb-3 flex items-center gap-2 font-display text-sm font-bold uppercase tracking-wider text-brand-900">' . icon('calendar-days', 'h-4 w-4 text-accent-600') . e($month) . '</h3><ul class="divide-y divide-slate-100">';
        foreach ($list as $en) {
            $ts = strtotime($en['date']);
            $past = ($en['end'] ?: $en['date']) < $today;
            [$cls, $label] = $badge[$en['kind']] ?? $badge['event'];
            $range = $en['end'] && $en['end'] !== $en['date'] ? date('d M', $ts) . ' – ' . date('d M', strtotime($en['end'])) : date('D, d M', $ts);
            $html .= '<li class="flex items-start gap-3 py-3' . ($past ? ' opacity-60' : '') . '"><span class="w-12 shrink-0 text-center"><span class="block font-display text-xl font-extrabold leading-none text-brand-900">' . date('d', $ts) . '</span><span class="text-[11px] font-semibold uppercase text-slate-400">' . date('D', $ts) . '</span></span>'
                . '<span class="min-w-0 flex-1"><span class="block text-sm font-semibold leading-snug text-slate-800">' . e($en['title']) . '</span><span class="mt-0.5 block text-xs text-slate-500">' . e($range) . ($en['meta'] !== '' ? ' · ' . e(str_limit($en['meta'], 120)) : '') . '</span></span>'
                . '<span class="badge ' . $cls . ' shrink-0">' . e($label) . '</span></li>';
        }
        $html .= '</ul></section>';
    }
    return $html . '</div>';
}

/* ============================================================================ Printable brochure */

/** Standalone printable brochure (all programs, or one with its curriculum). Outputs the full document. */
function sa_render_brochure(array $programs, ?array $single = null): void
{
    $name = institute_name();
    $session = sa_session();
    $counts = sa_counts();
    $title = $single ? $single['name'] . ' — Program Brochure' : 'Program Brochure ' . ($session['name'] ?? '');
    header('X-Robots-Tag: noindex');
    $back = $single ? site_url('programs/' . $single['slug']) : site_url('programs');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,follow">'
        . '<title>' . e($title) . ' | ' . e($name) . '</title><link rel="icon" type="image/svg+xml" href="' . e(asset('assets/images/favicon.svg')) . '">'
        . '<link rel="stylesheet" href="' . e(asset('assets/css/style.css')) . '">'
        . '<style>@page{size:A4;margin:12mm}body{background:#f4f4f4}@media print{body{background:#fff}.no-print{display:none!important}.sheet{box-shadow:none!important;margin:0!important;padding:0!important;max-width:none!important}.page-break{break-before:page}}</style></head>'
        . '<body class="font-sans text-slate-800 antialiased">';
    echo '<div class="no-print sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-slate-200 bg-white/90 px-4 py-2.5 backdrop-blur">'
        . '<a href="' . e($back) . '" class="btn btn-secondary btn-sm">' . icon('arrow-left', 'h-4 w-4') . 'Back</a>'
        . '<span class="hidden truncate text-sm font-semibold text-slate-700 sm:block">' . e($title) . '</span>'
        . '<button type="button" onclick="window.print()" class="btn btn-primary btn-sm">' . icon('printer', 'h-4 w-4') . 'Print / Save as PDF</button></div>';
    echo '<main class="sheet mx-auto my-6 w-full max-w-[210mm] bg-white p-6 shadow-xl sm:p-[12mm]">';
    echo '<header class="flex flex-col gap-4 border-b-2 border-brand-800 pb-5 sm:flex-row sm:items-center"><img src="' . e(logo_url()) . '" alt="GIMT logo" class="h-16 w-auto self-start">'
        . '<div class="sm:flex-1 sm:text-right"><p class="font-display text-lg font-extrabold uppercase leading-tight text-brand-900">' . e($name) . '</p>'
        . '<p class="text-xs text-slate-600">' . e(setting('address', 'Plot No. 123, Knowledge Park, Greater Noida, Uttar Pradesh 201310, India')) . '</p>'
        . '<p class="text-xs text-slate-600">' . e(setting('phone', '+91 9955446477')) . ' · ' . e(setting('admission_email', setting('email', 'info@gimt.ac.in'))) . ' · ' . e(setting('website', 'www.gimt.ac.in')) . '</p></div></header>';
    if ($single) {
        $p = $single;
        $curriculum = sa_curriculum((int) $p['id']);
        echo '<section class="mt-6"><p class="text-xs font-bold uppercase tracking-[0.18em] text-accent-800">' . e(program_level_label($p['level'])) . ' Program · Admissions ' . e($session['name'] ?? '') . '</p>'
            . '<h1 class="mt-1 font-display text-3xl font-extrabold text-brand-900">' . e($p['name']) . '</h1>'
            . '<p class="mt-3 text-sm leading-relaxed text-slate-700">' . e((string) $p['overview']) . '</p></section>';
        echo '<dl class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">';
        foreach ([['Duration', $p['duration_label'] ?: $p['duration_years'] . ' Years'], ['Semesters', (string) $p['total_semesters']], ['Intake', $p['intake_capacity'] ? $p['intake_capacity'] . ' seats' : '—'], ['Fee', sa_program_fee($p) ?: '—']] as [$k, $v]) {
            echo '<div class="rounded-xl border border-slate-200 p-3"><dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">' . e($k) . '</dt><dd class="mt-0.5 text-sm font-bold text-brand-900">' . e($v) . '</dd></div>';
        }
        echo '</dl>';
        if (!empty($p['eligibility'])) {
            echo '<section class="mt-5 rounded-xl bg-brand-50/60 p-4"><h2 class="text-sm font-bold text-brand-900">Eligibility</h2><p class="mt-1 text-sm text-slate-700">' . e($p['eligibility']) . '</p></section>';
        }
        $hl = sa_lines($p['highlights']);
        $cp = sa_lines($p['career_prospects']);
        echo '<div class="mt-5 grid gap-5 sm:grid-cols-2">';
        if ($hl) {
            echo '<section><h2 class="text-sm font-bold uppercase tracking-wider text-brand-900">Program Highlights</h2>' . check_list($hl, 'mt-2 text-sm') . '</section>';
        }
        if ($cp) {
            echo '<section><h2 class="text-sm font-bold uppercase tracking-wider text-brand-900">Career Opportunities</h2>' . check_list($cp, 'mt-2 text-sm') . '</section>';
        }
        echo '</div>';
        if ($curriculum) {
            echo '<section class="mt-6"><h2 class="font-display text-lg font-extrabold text-brand-900">Curriculum</h2><div class="mt-3 grid gap-4 sm:grid-cols-2">';
            foreach ($curriculum as $sem => $subjects) {
                echo '<div class="break-inside-avoid rounded-xl border border-slate-200"><p class="border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold uppercase tracking-wider text-brand-900">Semester ' . (int) $sem . ' · ' . e(rtrim(rtrim(number_format(array_sum(array_column($subjects, 'credits')), 1), '0'), '.')) . ' credits</p><ul class="divide-y divide-slate-100 text-[12.5px]">';
                foreach ($subjects as $s) {
                    echo '<li class="flex justify-between gap-3 px-3 py-1.5"><span><span class="font-mono text-[11px] text-slate-400">' . e($s['code']) . '</span> ' . e($s['name']) . '</span><span class="shrink-0 font-semibold text-slate-600">' . e(rtrim(rtrim((string) $s['credits'], '0'), '.')) . '</span></li>';
                }
                echo '</ul></div>';
            }
            echo '</div></section>';
        }
    } else {
        echo '<section class="mt-6"><p class="text-xs font-bold uppercase tracking-[0.18em] text-accent-800">' . e(setting('admissions_open_text', 'Admissions Open for ' . ($session['name'] ?? ''))) . '</p>'
            . '<h1 class="mt-1 font-display text-3xl font-extrabold text-brand-900">Academic Programs ' . e($session['name'] ?? '') . '</h1>'
            . '<p class="mt-3 text-sm leading-relaxed text-slate-700">' . e(setting('footer_about', 'Global Institute of Management & Technology (GIMT) offers industry-focused undergraduate, postgraduate, diploma and certificate programs in management, technology, commerce and computer applications.')) . '</p>'
            . '<ul class="mt-4 grid grid-cols-2 gap-3 text-center sm:grid-cols-4">';
        foreach ([[sa_round_label($counts['students']), 'Students'], [sa_round_label($counts['faculty']), 'Faculty Members'], [(string) $counts['programs'], 'Programs'], [rtrim(rtrim(number_format($counts['satisfaction'], 1), '0'), '.') . '%', 'Student Satisfaction']] as [$v, $l]) {
            echo '<li class="rounded-xl border border-slate-200 p-3"><span class="block font-display text-xl font-extrabold text-brand-900">' . e($v) . '</span><span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">' . e($l) . '</span></li>';
        }
        echo '</ul></section>';
        $groups = [];
        foreach ($programs as $p) {
            $groups[$p['level'] === 'UG' ? 'Undergraduate Programs' : (in_array($p['level'], ['PG', 'PhD'], true) ? 'Postgraduate Programs' : 'Diploma & Certificate Programs')][] = $p;
        }
        foreach (['Undergraduate Programs', 'Postgraduate Programs', 'Diploma & Certificate Programs'] as $g) {
            if (empty($groups[$g])) {
                continue;
            }
            echo '<section class="mt-8"><h2 class="border-l-4 border-accent-600 pl-3 font-display text-xl font-extrabold text-brand-900">' . e($g) . '</h2><div class="mt-4 grid gap-4">';
            foreach ($groups[$g] as $p) {
                $hl = array_slice(sa_lines($p['highlights']), 0, 3);
                echo '<article class="break-inside-avoid rounded-xl border border-slate-200 p-4"><div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">'
                    . '<h3 class="font-display text-base font-bold text-brand-900">' . e($p['name']) . (program_title_suffix($p) !== '' ? ' (' . e(program_title_suffix($p)) . ')' : '') . '</h3>'
                    . '<p class="shrink-0 text-sm font-bold text-accent-800">' . e(sa_program_fee($p)) . '</p></div>'
                    . '<p class="mt-1 text-xs font-semibold text-slate-500">' . e(implode(' · ', array_filter([$p['duration_label'], $p['total_semesters'] . ' Semester' . ((int) $p['total_semesters'] === 1 ? '' : 's'), $p['intake_capacity'] ? $p['intake_capacity'] . ' seats' : '', $p['department_name'] ?? '']))) . '</p>'
                    . '<p class="mt-2 text-[13px] text-slate-700">' . e((string) $p['overview']) . '</p>'
                    . (!empty($p['eligibility']) ? '<p class="mt-2 text-[12.5px] text-slate-600"><strong class="text-brand-900">Eligibility:</strong> ' . e($p['eligibility']) . '</p>' : '')
                    . ($hl ? '<p class="mt-1 text-[12.5px] text-slate-600"><strong class="text-brand-900">Highlights:</strong> ' . e(implode(' · ', $hl)) . '</p>' : '') . '</article>';
            }
            echo '</div></section>';
        }
    }
    echo '<footer class="mt-8 border-t border-slate-200 pt-4 text-xs text-slate-500"><p><strong class="text-brand-900">Admissions helpline:</strong> ' . e(setting('phone', '+91 9955446477')) . ' · ' . e(setting('admission_email', 'admissions@gimt.ac.in'))
        . ' · Apply online: ' . e(absolute_url('apply')) . '</p><p class="mt-1">Fees shown are per the ' . e($session['name'] ?? 'current') . ' session and exclude hostel, transport and examination charges unless stated. Generated ' . e(date('d M Y')) . '.</p></footer>';
    echo '</main></body></html>';
}
