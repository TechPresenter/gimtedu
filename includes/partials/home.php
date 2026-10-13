<?php
/**
 * Homepage (/) — data layer + section renderers (unit: homepage). Reference: docs/HOMEPAGE.md.
 *
 * The CMS (Website CMS › Homepage, table homepage_sections, docs/CMS.md §1) decides which sections render and in which
 * order; every renderer reads its section's title / subtitle / content and only falls back to cms_default_sections()
 * for missing fields. Numbers are live (cms_stat_values()) or typed by an editor — a stat whose live source has no
 * data is hidden, never invented. Internal links are checked against the routes that exist right now (hm_url_ok()),
 * so a button never points at a page that is not built yet.
 *
 *   index.php:  $sections = hm_sections();
 *               site_header([... 'schema' => hm_schema($sections), 'preload' => hm_preload($sections)]);
 *               foreach ($sections as $key => $s) echo hm_render($key, $s, $prev); $prev = $key;
 *               site_footer(['cta' => !isset($sections['cta'])]);
 *
 * Islands used here: CourseFinder, ScholarshipAssistant, StudentLifeSlider, CampusExplorer, AcademicCalendar,
 * SmartEnquiry (frontend/site/islands, this unit) + the shared ProgressSteps, LogoMarquee, TestimonialSlider,
 * Accordion, VideoLightbox, Countdown (includes/components.php wrappers).
 */

require_once APP_ROOT . '/app/services/cms.php';
require_once APP_ROOT . '/app/services/public_forms.php';
require_once __DIR__ . '/campus_map.php';

/* =====================================================================================================================
 * Generic helpers
 * ===================================================================================================================*/

/** Run a DB read; any failure (missing table/column) returns $fallback so the page still renders. */
function hm_try(callable $fn, $fallback = null)
{
    try {
        $v = $fn();
        return $v === null || $v === false ? $fallback : $v;
    } catch (Throwable $e) {
        return $fallback;
    }
}

/** Is a published CMS page served at /{slug} (by page.php)? */
function hm_page_published(string $slug): bool
{
    static $slugs = null;
    $slugs ??= hm_try(fn () => db_column("SELECT slug FROM pages WHERE status = 'published' AND (published_at IS NULL OR published_at <= NOW())"), []);
    return in_array($slug, $slugs, true);
}

/** Does an internal path resolve to a page that exists right now (mirrors tools/router.php / .htaccess)? */
function hm_route_exists(string $path): bool
{
    static $cache = [];
    if (isset($cache[$path])) {
        return $cache[$path];
    }
    $root = APP_ROOT;
    $pretty = ['programs' => 'program', 'blog' => 'post', 'events' => 'event', 'notices' => 'notice', 'gallery' => 'gallery', 'page' => 'page'];
    $ok = false;
    if ($path === '/') {
        $ok = true;
    } elseif (preg_match('#^/(programs|blog|events|notices|gallery|page)/([a-z0-9-]+)$#', $path, $m)) {
        $ok = is_file($root . '/' . $pretty[$m[1]] . '.php');
    } elseif (preg_match('#^/verify-certificate(/.*)?$#', $path)) {
        $ok = is_file($root . '/verify-certificate.php');
    } elseif (preg_match('#^/([a-z0-9-]+)$#', $path, $m)) {
        $ok = (is_file($root . '/' . $m[1] . '.php') && !in_array($m[1], ['index', 'site-kit'], true))
            || (is_file($root . '/page.php') && hm_page_published($m[1]));
    } elseif (!str_contains($path, '..') && is_file($root . $path)) {
        $ok = true;
    }
    return $cache[$path] = $ok;
}

/** Can this URL be linked? External / mailto: / tel: / #anchor are trusted; internal paths must resolve. */
function hm_url_ok(?string $url): bool
{
    $url = trim((string) $url);
    if ($url === '' || $url === '#') {
        return false;
    }
    if (preg_match('#^(https?:|mailto:|tel:|\#)#i', $url)) {
        return true;
    }
    $path = (string) parse_url($url, PHP_URL_PATH);
    $base = rtrim(base_path(), '/');
    if ($base !== '' && str_starts_with($path, $base . '/')) {
        $path = substr($path, strlen($base));
    }
    return hm_route_exists('/' . trim($path, '/'));
}

/**
 * CMS link {label, url} → ['label', 'url' (raw, for button_link), 'href' (final)] when it has a label and the target
 * exists; otherwise the fallback (same shape, also checked) or null.
 */
function hm_link($link, ?array $fallback = null): ?array
{
    foreach ([$link, $fallback] as $l) {
        if (is_array($l) && trim((string) ($l['label'] ?? '')) !== '' && hm_url_ok($l['url'] ?? '')) {
            return ['label' => trim((string) $l['label']), 'url' => trim((string) $l['url']), 'href' => cms_link(trim((string) $l['url']))];
        }
    }
    return null;
}

/** Pill button for a checked link ('' when null). Variants: apply | explore | brochure | callback | secondary | outline-light | white. */
function hm_button(?array $link, string $variant, array $o = []): string
{
    return $link ? button_link($link['label'], $link['url'], $variant, $o) : '';
}

/** Section anchor id: content.anchor, else a stable default per key. */
function hm_anchor(string $key, array $content = []): string
{
    $a = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($content['anchor'] ?? '')));
    return $a !== '' ? $a : ([
        'hero' => 'top', 'stats' => 'at-a-glance', 'features' => 'highlights', 'programs' => 'programs', 'why' => 'why-gimt',
        'academic_excellence' => 'about', 'admission_process' => 'admissions', 'campus_life' => 'campus-life', 'placements' => 'placements',
        'recruiters' => 'recruiters', 'testimonials' => 'stories', 'events_news' => 'news', 'faqs' => 'faqs', 'cta' => 'enquiry',
    ][$key] ?? $key);
}

/** "Nisha Kumar" → "Nisha K." (offer cards never show surnames). */
function hm_short_name(string $first, string $last): string
{
    $first = trim($first);
    $l = trim($last);
    return $first . ($l !== '' ? ' ' . mb_strtoupper(mb_substr($l, 0, 1)) . '.' : '');
}

/** Plain text of CMS rich text / descriptions, limited. */
function hm_text(?string $html, int $limit = 160): string
{
    return str_limit(html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $limit);
}

/** Small accent line + eyebrow used inside sub-blocks. */
function hm_kicker(string $text, bool $dark = false): string
{
    return '<p class="eyebrow' . ($dark ? ' !text-accent-300' : '') . '"><span class="accent-line !w-7"' . reveal_attr('fade-in') . ' aria-hidden="true"></span>' . e($text) . '</p>';
}

/* =====================================================================================================================
 * Sections (CMS order + toggles)
 * ===================================================================================================================*/

/**
 * Enabled homepage sections in CMS sort order: [key => ['key', 'title', 'subtitle', 'content']]. Content is merged
 * over cms_default_sections() so a field the editor never filled still has a sensible value; with an empty / missing
 * homepage_sections table the full default homepage renders.
 */
function hm_sections(): array
{
    static $out = null;
    if ($out !== null) {
        return $out;
    }
    $defaults = cms_default_sections();
    $order = hm_try(fn () => db_column('SELECT section_key FROM homepage_sections ORDER BY sort_order, id'), []);
    $out = [];
    if (!$order) {
        foreach ($defaults as $key => $d) {
            $out[$key] = ['key' => $key, 'title' => (string) $d['title'], 'subtitle' => (string) ($d['subtitle'] ?? ''), 'content' => $d['content']];
        }
        return $out;
    }
    foreach ($order as $key) {
        $row = site_section((string) $key);
        if (!$row || !isset($defaults[$key])) {
            continue; // disabled, or a key without a renderer
        }
        $d = $defaults[$key];
        $content = is_array($row['content'] ?? null) ? $row['content'] : [];
        $out[$key] = [
            'key' => $key,
            'title' => trim((string) ($row['title'] ?? '')) !== '' ? (string) $row['title'] : (string) $d['title'],
            'subtitle' => trim((string) ($row['subtitle'] ?? '')),
            'content' => array_replace($d['content'], $content),
        ];
    }
    return $out;
}

/** Is a section enabled on this page? */
function hm_has(string $key): bool
{
    return isset(hm_sections()[$key]);
}

/** Is the SmartEnquiry form on this page (CTA section with show_enquiry_form)? */
function hm_enquiry_on_page(): bool
{
    $s = hm_sections()['cta'] ?? null;
    return $s && !empty($s['content']['show_enquiry_form']);
}

/** Where "Talk to a counsellor" / "Request a Callback" go: the on-page SmartEnquiry, else the admissions enquiry form. */
function hm_counsellor_url(): string
{
    return hm_enquiry_on_page() ? '#enquiry' : site_url('admissions') . '#enquiry';
}

/** Render one section (empty string when it has nothing to show). */
function hm_render(string $key, array $s, ?string $prev = null): string
{
    $fn = 'hm_render_' . $key;
    if (!function_exists($fn)) {
        return '';
    }
    try {
        return $fn($s, $prev);
    } catch (Throwable $e) {
        error_log('[home] section ' . $key . ' failed: ' . $e->getMessage());
        return '';
    }
}

/* =====================================================================================================================
 * Live numbers
 * ===================================================================================================================*/

/** Live value of a stat source or null (no data). Satisfaction only counts when the setting is actually set. */
function hm_stat_source(string $source): ?float
{
    if ($source === 'satisfaction') {
        $v = setting('student_satisfaction');
        return $v !== null && is_numeric($v) && (float) $v > 0 ? (float) $v : null;
    }
    $v = cms_stat_value($source, null);
    return $v === null || !is_numeric($v) ? null : (float) $v;
}

/**
 * Resolve a CMS stat item to what is shown, or null to hide it: live number when `source` is set (hidden when the
 * source has no data — typed placeholder values are never shown for a live stat), else the typed value. Live counts
 * shown with a "+" are rounded down to a clean figure (1,248 → 1,200+) so the "+" stays true.
 */
function hm_stat(array $it): ?array
{
    $label = trim((string) ($it['label'] ?? ''));
    if ($label === '') {
        return null;
    }
    $source = trim((string) ($it['source'] ?? ''));
    $suffix = (string) ($it['suffix'] ?? '');
    if ($source !== '') {
        $v = hm_stat_source($source);
        if ($v === null || $v <= 0) {
            return null;
        }
        if (str_contains($suffix, '+')) {
            if ($v >= 100) {
                $mag = 10 ** (strlen((string) (int) $v) - 2);
                $v = floor($v / $mag) * $mag;
            } elseif ($v >= 20) {
                $v = floor($v / 5) * 5;
            }
        }
    } else {
        $raw = preg_replace('/[^0-9.]/', '', (string) ($it['value'] ?? ''));
        if ($raw === '' || !is_numeric($raw) || (float) $raw <= 0) {
            return null;
        }
        $v = (float) $raw;
    }
    $decimals = floor($v) != $v ? 1 : 0;
    // " LPA" / "LPA" → non-breaking space before a word suffix (also survives the counter re-render)
    $suffix = (string) preg_replace('/^\s*(?=\p{L})/u', "\u{00A0}", (string) preg_replace('/^\s+/u', '', $suffix));
    return ['value' => $v, 'decimals' => $decimals, 'prefix' => (string) ($it['prefix'] ?? ''), 'suffix' => $suffix, 'label' => $label,
        'icon' => (string) ($it['icon'] ?? ''), 'source' => $source, 'display' => (string) ($it['prefix'] ?? '') . number_format($v, $decimals) . $suffix];
}

/** Count-up number span (vanilla data-count; the final value is server-rendered). */
function hm_count(array $st, string $class): string
{
    return '<span class="' . e($class) . '" data-count="' . e((string) $st['value']) . '"'
        . ($st['prefix'] !== '' ? ' data-prefix="' . e($st['prefix']) . '"' : '') . ($st['suffix'] !== '' ? ' data-suffix="' . e($st['suffix']) . '"' : '')
        . ($st['decimals'] ? ' data-decimals="' . (int) $st['decimals'] . '"' : '') . '>' . e($st['prefix'] . number_format($st['value'], $st['decimals']) . $st['suffix']) . '</span>';
}

/* =====================================================================================================================
 * Data
 * ===================================================================================================================*/

/** Session that admissions are open for (else the current one): ['id', 'name', 'start_date', 'end_date', 'admissions_open']. */
function hm_session(): ?array
{
    static $s = false;
    if ($s === false) {
        $s = hm_try(fn () => db_row('SELECT * FROM academic_sessions WHERE admissions_open = 1 ORDER BY is_current DESC, start_date LIMIT 1'), null)
            ?: hm_try(fn () => db_row('SELECT * FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1'), null);
    }
    return $s ?: null;
}

/** Current academic session (calendar). */
function hm_current_session(): ?array
{
    static $s = false;
    if ($s === false) {
        $s = hm_try(fn () => db_row('SELECT * FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1'), null);
    }
    return $s ?: null;
}

/** Eligibility buckets of a program's eligibility text (Course Finder filter). */
function hm_eligibility_buckets(string $text): array
{
    $t = mb_strtolower(strip_tags($text));
    $b = [];
    if (preg_match('/10\s*\+\s*2|class\s*(xii|12)|12th|senior secondary|higher secondary/u', $t)) {
        $anyStream = (bool) preg_match('/any stream|any discipline/u', $t);
        if (!$anyStream && preg_match('/\b(pcm|physics|chemistry|mathematics|maths|science stream)\b/u', $t)) {
            $b[] = '12-science';
        } elseif (!$anyStream && preg_match('/\bcommerce\b/u', $t)) {
            $b[] = '12-commerce';
        } else {
            $b[] = '12-any';
        }
    }
    if (preg_match('/bachelor|graduat|degree in any/u', $t)) {
        $b[] = 'graduate';
    }
    if (preg_match('/working professional|work experience/u', $t)) {
        $b[] = 'professional';
    }
    return $b ?: ['other'];
}

function hm_eligibility_labels(): array
{
    return ['12-any' => 'After 10+2 (any stream)', '12-science' => 'After 10+2 (science)', '12-commerce' => 'After 10+2 (commerce)',
        'graduate' => 'After graduation', 'professional' => 'For working professionals', 'other' => 'Other'];
}

/** Stream (department) key for URLs: department code lowercased. */
function hm_stream_key(array $p): string
{
    $code = strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) ($p['department_code'] ?? '')));
    return $code !== '' ? $code : (!empty($p['department_id']) ? 'd' . (int) $p['department_id'] : 'other');
}

/** Duration key: "0.5", "1", "3" (years). */
function hm_duration_key(array $p): string
{
    $y = (float) ($p['duration_years'] ?? 0);
    return $y > 0 ? rtrim(rtrim(number_format($y, 1, '.', ''), '0'), '.') : 'other';
}

/** Website programs enriched for the Course Finder (keeps site_programs() order). */
function hm_programs(): array
{
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }
    $rows = [];
    foreach (site_programs() as $p) {
        $p['stream'] = hm_stream_key($p);
        $p['level_key'] = strtolower((string) $p['level']);
        $p['duration_key'] = hm_duration_key($p);
        $p['elig'] = hm_eligibility_buckets((string) ($p['eligibility'] ?? ''));
        $p['cat_tokens'] = program_filter_tokens($p);
        $rows[] = $p;
    }
    return $rows;
}

/** Departments with website programs + HOD (public fields only). */
function hm_departments(): array
{
    static $out = null;
    if ($out !== null) {
        return $out;
    }
    $out = [];
    $rows = hm_try(fn () => db_all("SELECT d.id, d.name, d.code, d.description, f.title AS hod_title, f.first_name AS hod_first, f.last_name AS hod_last
        FROM departments d LEFT JOIN faculty f ON f.id = d.hod_faculty_id AND f.status = 'active' AND f.show_on_website = 1
        WHERE d.status = 'active' ORDER BY d.id"), []);
    $byId = array_column($rows, null, 'id');
    foreach (site_program_departments() as $d) {
        $r = $byId[$d['id']] ?? [];
        $first = null;
        foreach (hm_programs() as $p) {
            if ((int) $p['department_id'] === (int) $d['id']) {
                $first = $p;
                break;
            }
        }
        $out[] = $d + [
            'code' => (string) ($r['code'] ?? ''),
            'stream' => $first ? $first['stream'] : 'd' . $d['id'],
            'icon' => $first ? program_icon($first) : 'graduation-cap',
            'hod' => !empty($r['hod_first']) ? trim(($r['hod_title'] ? $r['hod_title'] . ' ' : '') . $r['hod_first'] . ' ' . $r['hod_last']) : '',
            'description' => (string) ($r['description'] ?? ''),
        ];
    }
    return $out;
}

/** Faculty highlights: HODs and senior faculty first, spread across departments. Public fields only. */
function hm_faculty(int $limit = 8): array
{
    $rows = hm_try(fn () => db_all("SELECT f.id, f.title, f.first_name, f.last_name, f.photo, f.designation, f.qualification, f.specialization, d.name AS department, d.code AS dept_code,
            (d.hod_faculty_id = f.id) AS is_hod
        FROM faculty f LEFT JOIN departments d ON d.id = f.department_id
        WHERE f.status = 'active' AND f.show_on_website = 1
        ORDER BY is_hod DESC, FIELD(f.designation, 'Professor & Head', 'Dean', 'Professor', 'Associate Professor', 'Assistant Professor', 'Lecturer') ASC, f.experience_years DESC, f.id
        LIMIT 60"), []);
    // round-robin by department so the strip shows the breadth of the faculty
    $byDept = [];
    foreach ($rows as $r) {
        $byDept[(string) $r['dept_code']][] = $r;
    }
    $out = [];
    while (count($out) < $limit && $byDept) {
        foreach ($byDept as $k => &$list) {
            if (!$list) {
                unset($byDept[$k]);
                continue;
            }
            $out[] = array_shift($list);
            if (count($out) >= $limit) {
                break;
            }
        }
        unset($list);
    }
    return $out;
}

/** Research & innovation content from the CMS "research" page (focus-area cards + support text) and research news. */
function hm_research(): array
{
    $page = hm_try(fn () => db_row("SELECT * FROM pages WHERE slug = 'research' AND status = 'published' AND (published_at IS NULL OR published_at <= NOW())"), null);
    $out = ['title' => 'Research & Innovation', 'highlight' => '', 'intro' => '', 'areas' => [], 'support' => [], 'url' => null, 'posts' => []];
    if ($page) {
        $out['url'] = hm_url_ok(cms_page_url($page)) ? cms_page_url($page) : null;
        foreach (cms_page_blocks($page) as $b) {
            $d = $b['data'] ?? [];
            if ($b['type'] === 'hero') {
                $out['title'] = trim((string) ($d['title'] ?? '')) ?: $out['title'];
                $out['highlight'] = trim((string) ($d['highlight'] ?? ''));
                $out['intro'] = trim((string) ($d['text'] ?? ''));
            } elseif ($b['type'] === 'cards' && !$out['areas']) {
                foreach (array_slice((array) ($d['items'] ?? []), 0, 6) as $it) {
                    if (trim((string) ($it['title'] ?? '')) !== '') {
                        $out['areas'][] = ['icon' => (string) ($it['icon'] ?? 'lightbulb') ?: 'lightbulb', 'title' => (string) $it['title'], 'text' => hm_text($it['text'] ?? '', 140)];
                    }
                }
                if ($out['intro'] === '') {
                    $out['intro'] = trim((string) ($d['subheading'] ?? ''));
                }
            } elseif ($b['type'] === 'text' && !$out['support']) {
                // "<p>intro</p><h3>Title</h3><p>text</p>…" → [title, text] pairs (the block heading names the first one)
                $html = (string) ($d['html'] ?? '');
                $parts = preg_split('#<h[2-4][^>]*>(.*?)</h[2-4]>#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
                $title = trim((string) ($d['heading'] ?? ''));
                for ($i = 0; $i < count($parts); $i++) {
                    if ($i % 2 === 1) {
                        $title = hm_text($parts[$i], 80);
                        continue;
                    }
                    $text = hm_text($parts[$i], 320);
                    if ($text !== '' && $title !== '') {
                        $out['support'][] = ['title' => $title, 'text' => $text];
                    }
                }
                $out['support'] = array_slice($out['support'], 0, 3);
            }
        }
    }
    $posts = hm_try(fn () => db_all("SELECT p.title, p.slug, p.thumbnail, p.published_at FROM blog_posts p JOIN blog_categories c ON c.id = p.category_id
        WHERE p.status = 'published' AND p.published_at <= NOW() AND (c.slug LIKE 'research%' OR c.name LIKE 'Research%') ORDER BY p.published_at DESC LIMIT 2"), []);
    foreach ($posts as $p) {
        $out['posts'][] = ['title' => (string) $p['title'], 'date' => (string) $p['published_at'], 'image' => site_image($p['thumbnail'] ?: null, 'assets/images/site/students-laptop.jpg'),
            'url' => hm_url_ok('/blog/' . $p['slug']) ? site_url('blog/' . $p['slug']) : null];
    }
    return $out;
}

/** Verified recognitions: setting rankings_list (JSON [{label, title, text, icon}]) + approvals (site_approvals()). */
function hm_recognitions(): array
{
    $out = [];
    $json = json_decode((string) setting('rankings_list', ''), true);
    if (is_array($json)) {
        foreach ($json as $i => $r) {
            $title = trim((string) ($r['title'] ?? $r['label'] ?? ''));
            if ($title !== '') {
                $out[] = ['label' => trim((string) ($r['label'] ?? 'Ranking')), 'title' => $title, 'text' => trim((string) ($r['text'] ?? '')), 'icon' => (string) ($r['icon'] ?? 'medal')];
            }
        }
    }
    foreach (site_approvals() as $a) {
        $out[] = $a;
    }
    return $out;
}

/**
 * Scholarship schemes for the Scholarship Assistant: active rows of `scholarships` with eligibility rules parsed from
 * category + criteria text (minPct/maxPct, incomeMax, specials, categories). Unparseable schemes keep rules = [] and
 * are listed as "ask the admissions office".
 */
function hm_scholarships(): array
{
    static $out = null;
    if ($out !== null) {
        return $out;
    }
    $out = [];
    foreach (hm_try(fn () => db_all("SELECT id, name, code, type, value, category, criteria, max_amount FROM scholarships WHERE status = 'active' ORDER BY value DESC, name"), []) as $s) {
        $text = mb_strtolower((string) $s['criteria'] . ' ' . (string) $s['name']);
        $criteria = mb_strtolower((string) $s['criteria']);
        $rules = [];
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:–|—|-|to)\s*(\d+(?:\.\d+)?)\s*%/u', $criteria, $m)) {
            $rules['minPct'] = (float) $m[1];
            $rules['maxPct'] = (float) $m[2];
        } elseif (preg_match('/(\d+(?:\.\d+)?)\s*%\s*(?:or|and|&)\s*(?:above|more|higher)/u', $criteria, $m) || preg_match('/(?:at least|minimum(?: of)?|above)\s*(\d+(?:\.\d+)?)\s*%/u', $criteria, $m)) {
            $rules['minPct'] = (float) $m[1];
        }
        if (preg_match('/income[^.]*?(?:below|under|less than|up to|upto|not exceeding|within)\s*(?:rs\.?|₹|inr)?\s*([\d.,]+)\s*(lakh|lac|l\b|crore|cr\b)?/u', $text, $m)) {
            $n = (float) str_replace(',', '', $m[1]);
            $unit = $m[2] ?? '';
            $rules['incomeMax'] = (int) round(str_starts_with($unit, 'l') ? $n * 100000 : (str_starts_with($unit, 'c') ? $n * 10000000 : $n));
        }
        $specials = [];
        if ($s['category'] === 'sports' || preg_match('/\bsports?\b/u', $text)) {
            $specials[] = 'sports';
        }
        if (preg_match('/single girl/u', $text)) {
            $specials[] = 'single_girl';
        }
        if (preg_match('/armed forces|defen[cs]e|paramilitary|capf|ex-servicem/u', $text)) {
            $specials[] = 'defence';
        }
        if ($s['category'] === 'sibling' || preg_match('/\bsibling\b/u', $text)) {
            $specials[] = 'sibling';
        }
        if ($s['category'] === 'staff_ward' || preg_match('/staff ward|employees? of gimt|gimt employees?/u', $text)) {
            $specials[] = 'staff_ward';
        }
        if ($specials) {
            $rules['specials'] = array_values(array_unique($specials));
        }
        if (preg_match_all('/\b(sc|st|obc)\b/u', $criteria, $mm)) {
            $rules['categories'] = array_values(array_unique(array_map('strtoupper', $mm[1])));
        }
        $value = (float) $s['value'];
        $out[] = [
            'id' => (int) $s['id'], 'name' => (string) $s['name'], 'type' => $s['type'] === 'fixed' ? 'fixed' : 'percentage', 'value' => $value,
            'max' => $s['max_amount'] !== null && (float) $s['max_amount'] > 0 ? (float) $s['max_amount'] : null,
            'category' => (string) $s['category'], 'criteria' => trim((string) $s['criteria']), 'rules' => (object) $rules,
            'benefit' => $s['type'] === 'fixed' ? money($value) . ' fee support' : rtrim(rtrim(number_format($value, 2), '0'), '.') . '% of tuition fee',
        ];
    }
    return $out;
}

/**
 * Photos (+ videos) for the Student Life slider: published gallery items — student-centred albums first (fests, sports,
 * convocation, then campus), interleaved album by album, no portraits / program artwork, only photos tall enough for a
 * portrait card — topped up with bundled campus photography.
 */
function hm_student_life(int $limit = 12): array
{
    $rows = hm_try(fn () => db_all("SELECT gi.id, gi.image, gi.video_url, gi.caption, g.id AS album_id, g.title AS album, g.category, m.path, m.alt_text, m.folder, m.height
        FROM gallery_items gi JOIN galleries g ON g.id = gi.gallery_id LEFT JOIN media m ON m.id = gi.media_id
        WHERE g.status = 'published' AND (m.folder IS NULL OR m.folder NOT IN ('people', 'programs', 'documents'))
        ORDER BY g.sort_order, g.id, gi.sort_order, gi.id LIMIT 120"), []);
    $priority = ['cultural' => 1, 'sports' => 2, 'convocation' => 3, 'events' => 4, 'campus' => 5, 'placements' => 6, 'labs' => 7];
    $albums = [];
    foreach ($rows as $r) {
        if ($r['height'] !== null && (int) $r['height'] < 300 && trim((string) $r['video_url']) === '') {
            continue; // wide banner strips look blurry in a portrait card
        }
        $albums[(int) $r['album_id']]['rank'] = $priority[(string) $r['category']] ?? 9;
        $albums[(int) $r['album_id']]['items'][] = $r;
    }
    uasort($albums, fn ($a, $b) => $a['rank'] <=> $b['rank']);
    $queue = [];
    while ($albums) {
        foreach ($albums as $k => &$al) {
            if (!$al['items']) {
                unset($albums[$k]);
                continue;
            }
            $queue[] = array_shift($al['items']);
        }
        unset($al);
    }
    $out = [];
    $seen = [];
    foreach ($queue as $r) {
        $path = (string) ($r['image'] ?: $r['path']);
        $video = trim((string) $r['video_url']);
        $key = $path ?: $video;
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $caption = trim((string) $r['caption']);
        if ($caption === '' || preg_match('/\b(image|photo|picture|banner)\s+used\b|home ?page/i', $caption)) {
            $caption = trim((string) $r['alt_text']) ?: (string) $r['album'];
        }
        $out[] = ['src' => $path !== '' ? site_image($path) : site_image('assets/images/site/students-atrium.jpg'), 'alt' => trim((string) $r['alt_text']) ?: $caption,
            'caption' => rtrim($caption, '.'), 'tag' => (string) $r['album'], 'video' => $video !== '' && cms_valid_video($video) ? $video : null];
        if (count($out) >= $limit) {
            break;
        }
    }
    // top up with bundled campus photography (not already used)
    $bundled = [
        ['students-laptop.jpg', 'Collaborative learning on campus', 'Students working together on a laptop on campus', 'Campus life'],
        ['students-lawn.jpg', 'Group study on the central lawn', 'Students studying together on the campus lawn', 'Campus life'],
        ['students-atrium.jpg', 'The atrium between classes', 'Students walking through the campus atrium between classes', 'Campus life'],
        ['graduation.jpg', 'Convocation day celebrations', 'Graduates throwing their caps at the convocation ceremony', 'Convocation'],
        ['news-campus.jpg', 'Campus events all year round', 'Students gathered at a campus event', 'Events'],
        ['campus-library.jpg', 'The central library', 'Reading hall of the central library and learning centre', 'Library'],
    ];
    foreach ($bundled as [$file, $caption, $alt, $tag]) {
        if (count($out) >= min($limit, 12)) {
            break;
        }
        $path = 'assets/images/site/' . $file;
        if (!isset($seen[$path])) {
            $seen[$path] = true;
            $out[] = ['src' => site_image($path), 'alt' => $alt, 'caption' => $caption, 'tag' => $tag, 'video' => null];
        }
    }
    return $out;
}

/** Latest accepted offers (one per company, best package first) — first name + initial only. */
function hm_offers(int $limit = 6): array
{
    $rows = hm_try(fn () => db_all("SELECT o.package_lpa, o.job_role, o.offer_date, s.first_name, s.last_name, p.short_name AS program, c.name AS company
        FROM placement_offers o JOIN students s ON s.id = o.student_id JOIN companies c ON c.id = o.company_id LEFT JOIN programs p ON p.id = s.program_id
        WHERE o.status IN ('accepted', 'joined') AND o.package_lpa > 0 AND (o.offer_date IS NULL OR o.offer_date >= ?)
        ORDER BY o.package_lpa DESC, o.offer_date DESC LIMIT 120", [date('Y-m-d', strtotime('-18 months'))]), []);
    $out = [];
    $companies = [];
    foreach ($rows as $r) {
        if (isset($companies[$r['company']])) {
            continue;
        }
        $companies[$r['company']] = true;
        $out[] = ['name' => hm_short_name((string) $r['first_name'], (string) $r['last_name']), 'program' => (string) ($r['program'] ?? ''), 'company' => (string) $r['company'],
            'role' => (string) ($r['job_role'] ?? ''), 'package' => (float) $r['package_lpa'], 'date' => (string) ($r['offer_date'] ?? '')];
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/** Published alumni success stories (name only when the alumnus is listed on the website). */
function hm_stories(int $limit = 3): array
{
    $rows = hm_try(fn () => db_all("SELECT s.title, s.company, s.designation, s.excerpt, s.image, a.name, a.show_on_website AS alumni_public, a.batch_year
        FROM alumni_success_stories s LEFT JOIN alumni a ON a.id = s.alumni_id
        WHERE s.status = 'published' AND s.show_on_website = 1 AND (s.published_at IS NULL OR s.published_at <= NOW())
        ORDER BY s.is_featured DESC, s.sort_order, s.published_at DESC LIMIT ?", [$limit]), []);
    return array_map(fn ($r) => [
        'title' => (string) $r['title'], 'excerpt' => hm_text($r['excerpt'] ?? '', 150), 'image' => site_image($r['image'] ?: null, 'assets/images/site/graduation.jpg'),
        'name' => !empty($r['alumni_public']) ? (string) $r['name'] : '', 'role' => trim((string) $r['designation'] . ((string) $r['company'] !== '' ? ', ' . $r['company'] : '')),
        'batch' => $r['batch_year'] ? 'Batch of ' . (int) $r['batch_year'] : '',
    ], $rows);
}

/** Upcoming website events. */
function hm_events(int $limit = 3): array
{
    return hm_try(fn () => db_all("SELECT id, title, slug, category, excerpt, venue, start_datetime, end_datetime, image, registration_enabled, registration_link, registration_deadline
        FROM events WHERE show_on_website = 1 AND status = 'published' AND COALESCE(end_datetime, start_datetime) >= NOW()
        ORDER BY start_datetime LIMIT " . max(1, min(12, $limit))), []);
}

/** Latest published news / blog posts. */
function hm_news(int $limit = 3): array
{
    return hm_try(fn () => db_all("SELECT p.title, p.slug, p.thumbnail, p.excerpt, p.published_at, p.type, c.name AS category
        FROM blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id
        WHERE p.status = 'published' AND p.published_at IS NOT NULL AND p.published_at <= NOW() ORDER BY p.published_at DESC LIMIT " . max(1, min(12, $limit))), []);
}

/** Live notices for the website notice board. */
function hm_notices(int $limit = 5): array
{
    return hm_try(fn () => db_all("SELECT title, slug, category, publish_date, is_pinned FROM notices
        WHERE status = 'published' AND show_on_website = 1 AND (publish_date IS NULL OR publish_date <= NOW()) AND (expiry_date IS NULL OR expiry_date >= NOW())
        ORDER BY is_pinned DESC, publish_date DESC LIMIT " . max(1, min(10, $limit))), []);
}

/**
 * Academic calendar items for the current session: holidays table (holidays, vacations, exam breaks, campus events),
 * examination windows (exams, merged into an overlapping exam break), website events, the session start / end and the
 * admissions deadline. Item: {id, title, start, end, type: academic|exam|holiday|event|admission, meta, url}.
 */
function hm_calendar(): array
{
    $session = hm_current_session();
    if (!$session) {
        return [];
    }
    [$from, $to, $sid] = [(string) $session['start_date'], (string) $session['end_date'], (int) $session['id']];
    $items = [];
    $kinds = ['holiday' => 'holiday', 'vacation' => 'holiday', 'exam_break' => 'exam', 'event' => 'event'];
    foreach (hm_try(fn () => db_all('SELECT id, title, holiday_date, end_date, type, description FROM holidays
            WHERE academic_session_id = ? OR (academic_session_id IS NULL AND holiday_date BETWEEN ? AND ?) ORDER BY holiday_date', [$sid, $from, $to]), []) as $h) {
        $items[] = ['id' => 'h' . $h['id'], 'title' => (string) $h['title'], 'start' => (string) $h['holiday_date'], 'end' => $h['end_date'] && $h['end_date'] !== $h['holiday_date'] ? (string) $h['end_date'] : null,
            'type' => $kinds[$h['type']] ?? 'holiday', 'meta' => $h['type'] === 'vacation' ? 'Vacation' : ((string) ($h['description'] ?? '') ?: ''), 'url' => null];
    }
    $exams = hm_try(fn () => db_all("SELECT et.id, et.name AS type_name, MIN(e.start_date) AS start_date, MAX(COALESCE(e.end_date, e.start_date)) AS end_date, COUNT(*) AS n
        FROM exams e JOIN exam_types et ON et.id = e.exam_type_id
        WHERE e.academic_session_id = ? AND e.start_date IS NOT NULL AND e.status NOT IN ('draft', 'cancelled')
        GROUP BY et.id, et.name ORDER BY MIN(e.start_date)", [$sid]), []);
    foreach ($exams as $x) {
        $label = (int) $x['n'] . ' program schedule' . ((int) $x['n'] === 1 ? '' : 's');
        $merged = false;
        foreach ($items as &$it) {
            if ($it['type'] === 'exam' && $it['start'] <= $x['end_date'] && ($it['end'] ?: $it['start']) >= $x['start_date']) {
                $it['meta'] = trim($it['meta'] . ' · ' . $label, ' ·');
                $merged = true;
                break;
            }
        }
        unset($it);
        if (!$merged) {
            $items[] = ['id' => 'x' . $x['id'], 'title' => preg_replace('/(Examination)$/', '$1s', (string) $x['type_name']), 'start' => (string) $x['start_date'],
                'end' => $x['end_date'] !== $x['start_date'] ? (string) $x['end_date'] : null, 'type' => 'exam', 'meta' => $label, 'url' => null];
        }
    }
    foreach (hm_try(fn () => db_all("SELECT id, title, slug, category, venue, start_datetime, end_datetime FROM events
            WHERE status IN ('published', 'completed') AND show_on_website = 1 AND start_datetime BETWEEN ? AND ? ORDER BY start_datetime", [$from . ' 00:00:00', $to . ' 23:59:59']), []) as $ev) {
        $start = substr((string) $ev['start_datetime'], 0, 10);
        $end = $ev['end_datetime'] ? substr((string) $ev['end_datetime'], 0, 10) : null;
        $items[] = ['id' => 'e' . $ev['id'], 'title' => (string) $ev['title'], 'start' => $start, 'end' => $end && $end !== $start ? $end : null,
            'type' => $ev['category'] === 'admission' ? 'admission' : 'event',
            'meta' => trim(date('g:i A', strtotime((string) $ev['start_datetime'])) . ((string) $ev['venue'] !== '' ? ' · ' . $ev['venue'] : '')),
            'url' => hm_url_ok('/events/' . $ev['slug']) ? site_url('events/' . $ev['slug']) : null];
    }
    $items[] = ['id' => 'ss', 'title' => 'Academic session ' . $session['name'] . ' begins', 'start' => $from, 'end' => null, 'type' => 'academic', 'meta' => '', 'url' => null];
    $items[] = ['id' => 'se', 'title' => 'Academic session ' . $session['name'] . ' ends', 'start' => $to, 'end' => null, 'type' => 'academic', 'meta' => '', 'url' => null];
    if ($deadline = site_admissions_deadline()) {
        $items[] = ['id' => 'ad', 'title' => 'Last date to apply', 'start' => date('Y-m-d', $deadline), 'end' => null, 'type' => 'admission',
            'meta' => 'Admissions ' . (hm_session()['name'] ?? ''), 'url' => site_url('apply')];
    }
    usort($items, fn ($a, $b) => strcmp($a['start'], $b['start']) ?: strcmp($a['title'], $b['title']));
    return $items;
}

/** FAQs per the CMS section filters. */
function hm_faqs(array $c): array
{
    $where = ["status = 'active'"];
    $args = [];
    if (!empty($c['home_only'])) {
        $where[] = 'show_on_home = 1';
    }
    $cats = array_values(array_intersect(array_map('strval', (array) ($c['categories'] ?? [])), array_keys(cms_faq_categories())));
    if ($cats) {
        $where[] = 'category IN (' . implode(',', array_fill(0, count($cats), '?')) . ')';
        $args = $cats;
    }
    $limit = max(3, min(20, (int) ($c['limit'] ?? 8)));
    $rows = hm_try(fn () => db_all('SELECT question, answer, category FROM faqs WHERE ' . implode(' AND ', $where) . ' ORDER BY sort_order, id LIMIT ' . $limit, $args), []);
    if (!$rows && !empty($c['home_only'])) {
        $rows = hm_try(fn () => db_all("SELECT question, answer, category FROM faqs WHERE status = 'active' ORDER BY show_on_home DESC, sort_order, id LIMIT " . $limit), []);
    }
    $labels = cms_faq_categories();
    return array_map(fn ($r) => ['q' => (string) $r['question'], 'a' => trim(html_entity_decode(strip_tags((string) $r['answer']), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        'category' => $labels[$r['category']] ?? ucfirst((string) $r['category'])], $rows);
}

/* =====================================================================================================================
 * SEO
 * ===================================================================================================================*/

/** Image URLs to preload (the hero photo is the LCP element). */
function hm_preload(array $sections): array
{
    $hero = $sections['hero']['content'] ?? null;
    return $hero ? [site_image($hero['image'] ?? null, 'assets/images/site/hero-campus-students.jpg')] : [];
}

/** JSON-LD for the homepage: ItemList of programs, upcoming Events, FAQPage (CollegeOrUniversity is in the header). */
function hm_schema(array $sections): array
{
    $out = [];
    $org = ['@type' => 'CollegeOrUniversity', 'name' => institute_name(), 'url' => absolute_url('')];
    $abs = fn (string $u) => preg_match('#^https?://#', $u) ? $u : absolute_url(ltrim(preg_replace('#^' . preg_quote(base_path(), '#') . '#', '', parse_url($u, PHP_URL_PATH) ?: ''), '/'));
    if (isset($sections['programs']) && ($programs = hm_programs())) {
        $list = [];
        foreach ($programs as $i => $p) {
            $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'item' => array_filter([
                '@type' => 'Course', 'name' => (string) $p['name'], 'url' => absolute_url('programs/' . $p['slug']),
                'description' => hm_text($p['overview'] ?? '', 220) ?: null, 'provider' => $org,
                'educationalCredentialAwarded' => (string) ($p['degree'] ?? '') ?: null,
            ])];
        }
        $out[] = ['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => 'Programs at ' . setting('institute_short_name', 'GIMT'), 'itemListElement' => $list];
    }
    if (isset($sections['events_news']) && !empty($sections['events_news']['content']['show_events'])) {
        foreach (hm_events((int) ($sections['events_news']['content']['events_limit'] ?? 3)) as $ev) {
            $online = (bool) preg_match('/online|zoom|youtube|webinar|meet/i', (string) $ev['venue']);
            $tz = (new DateTimeZone('Asia/Kolkata'));
            $start = (new DateTime((string) $ev['start_datetime'], $tz))->format('c');
            $end = $ev['end_datetime'] ? (new DateTime((string) $ev['end_datetime'], $tz))->format('c') : null;
            $out[] = array_filter([
                '@context' => 'https://schema.org', '@type' => 'Event', 'name' => (string) $ev['title'], 'startDate' => $start, 'endDate' => $end,
                'eventStatus' => 'https://schema.org/EventScheduled',
                'eventAttendanceMode' => $online ? 'https://schema.org/OnlineEventAttendanceMode' : 'https://schema.org/OfflineEventAttendanceMode',
                'location' => $online ? ['@type' => 'VirtualLocation', 'url' => absolute_url('')] : ['@type' => 'Place', 'name' => trim((string) $ev['venue']) ?: institute_name(),
                    'address' => ['@type' => 'PostalAddress', 'streetAddress' => setting('address'), 'addressLocality' => setting('city', 'Greater Noida'), 'addressRegion' => setting('state', 'Uttar Pradesh'), 'addressCountry' => 'IN']],
                'image' => $ev['image'] ? $abs(site_image($ev['image'])) : null, 'description' => hm_text($ev['excerpt'] ?? '', 240) ?: null,
                'organizer' => $org, 'url' => hm_url_ok('/events/' . $ev['slug']) ? absolute_url('events/' . $ev['slug']) : null,
            ]);
        }
    }
    if (isset($sections['faqs']) && ($faqs = hm_faqs($sections['faqs']['content']))) {
        $out[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($f) => ['@type' => 'Question', 'name' => $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]], $faqs)];
    }
    return $out;
}

/* =====================================================================================================================
 * Renderers
 * ===================================================================================================================*/

/* ------------------------------------------------------------------------------------------------ 1. Hero */

function hm_render_hero(array $s): string
{
    $c = $s['content'];
    $title = trim($s['title']);
    $highlight = trim((string) ($c['highlight'] ?? ''));
    $eyebrow = trim((string) ($c['eyebrow'] ?? ''));
    $lead = $s['subtitle'];
    $session = hm_session();
    $primary = hm_link($c['primary_cta'] ?? null, ['label' => 'Apply for Admission', 'url' => '/apply']);
    $secondary = hm_link($c['secondary_cta'] ?? null, ['label' => 'Explore Courses', 'url' => hm_has('programs') ? '#programs' : '/programs']);
    $mainPath = (string) ($c['image'] ?? '') ?: 'assets/images/site/hero-campus-students.jpg';
    $main = site_image($mainPath);
    $alt = trim((string) ($c['image_alt'] ?? '')) ?: 'Students on the GIMT campus';
    $others = array_values(array_filter(['assets/images/site/students-atrium.jpg', 'assets/images/site/campus-building.jpg', 'assets/images/site/students-lawn.jpg'], fn ($p) => $p !== $mainPath));
    $chips = array_slice(array_values(array_filter((array) ($c['chips'] ?? []), fn ($ch) => trim((string) ($ch['label'] ?? '')) !== '')), 0, 1);
    $words = array_slice(array_values(array_filter(array_map('trim', (array) ($c['tagline_words'] ?? [])))), 0, 6);
    $video = trim((string) ($c['video_url'] ?? ''));
    $video = $video !== '' && cms_valid_video($video) ? $video : '';

    // admission notification badge: CMS badge, else the admissions setting while a session is open for admissions
    $badgeText = trim((string) ($c['badge'] ?? ''));
    if ($badgeText === '' && $session && (int) $session['admissions_open'] === 1) {
        $badgeText = (string) setting('admissions_open_text', 'Admissions Open for ' . $session['name']);
    }
    $deadline = site_admissions_deadline();
    $badgeUrl = hm_url_ok('/admissions') ? site_url('admissions') : null;

    // trust indicators: verified approvals / affiliations (settings) + year of establishment
    $trust = array_slice(site_approvals(), 0, 3);
    $est = (int) setting('established_year', 0);

    // spotlight: live "home hero" banners whose CTA resolves (the layered hero stays the default; banners rotate here)
    $spot = [];
    if (!empty($c['use_banners'])) {
        foreach (site_banners('home_hero') as $b) {
            $link = hm_link(['label' => $b['cta_text'] ?? '', 'url' => $b['cta_url'] ?? ''], ['label' => $b['cta2_text'] ?? '', 'url' => $b['cta2_url'] ?? '']);
            if (!$link || mb_strtolower(trim((string) $b['title'])) === mb_strtolower($title)) {
                continue;
            }
            $spot[] = ['eyebrow' => (string) ($b['eyebrow'] ?? ''), 'title' => trim((string) $b['title']), 'image' => site_image($b['mobile_image'] ?: $b['desktop_image']),
                'link' => $link];
            if (count($spot) >= 4) {
                break;
            }
        }
    }

    $rise = '[animation:hm-rise_.95s_cubic-bezier(.2,.8,.2,1)_both]';
    $rise2 = '[animation:hm-rise_.95s_cubic-bezier(.2,.8,.2,1)_.14s_both]';
    $h = '<section id="top" class="relative isolate overflow-hidden bg-[#FAF8F5]" aria-labelledby="hm-hero-title">';
    // mask keyframes for the heading (CSS-only so the LCP text never waits for JS); reduced motion → instant via style.css
    $h .= '<style>@keyframes hm-rise{from{-webkit-transform:translate3d(0,108%,0);transform:translate3d(0,108%,0)}to{-webkit-transform:none;transform:none}}@-webkit-keyframes hm-rise{from{-webkit-transform:translate3d(0,108%,0)}to{-webkit-transform:none}}</style>';
    $h .= '<div class="page-hero-paper" aria-hidden="true"></div>';
    $h .= '<div class="pointer-events-none absolute -left-40 top-1/3 -z-10 h-[420px] w-[420px] rounded-full bg-accent-200/40 blur-3xl" aria-hidden="true"></div>';
    $h .= '<div class="container-site relative grid grid-cols-1 items-center gap-x-10 gap-y-16 pb-28 pt-8 sm:pb-32 sm:pt-12 lg:grid-cols-12 lg:pb-36 lg:pt-14 xl:gap-x-16">';

    // ---- copy
    $h .= '<div class="relative z-[1] lg:col-span-7">';
    if ($eyebrow !== '') {
        $h .= '<p class="inline-flex items-center gap-2.5 rounded-full border border-warm-2 bg-white py-1.5 pl-1.5 pr-4 text-[12px] font-bold uppercase tracking-[0.14em] text-brand-900 shadow-sm animate-fade-up">'
            . '<span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-brand-900 text-white">' . icon('sparkles', 'h-3.5 w-3.5') . '</span>' . e($eyebrow) . '</p>';
    }
    $h .= '<h1 id="hm-hero-title" class="mt-6 font-display text-[clamp(2.35rem,0.85rem+3.9vw,4.25rem)] font-extrabold leading-[1.04] tracking-[-0.035em] text-brand-900">'
        . '<span class="block overflow-hidden pb-[0.1em] -mb-[0.1em]"><span class="block ' . $rise . '">' . e($title) . '</span></span>'
        . ($highlight !== '' ? '<span class="block overflow-hidden pb-[0.12em] -mb-[0.12em]"><span class="block bg-gradient-to-r from-accent-600 via-accent-500 to-[#F37A52] bg-clip-text text-transparent [-webkit-text-fill-color:transparent] ' . $rise2 . '">' . e($highlight) . '</span></span>' : '')
        . '</h1>';
    if ($lead !== '') {
        $h .= '<p class="mt-6 max-w-xl text-[17px] leading-relaxed text-slate-600 sm:text-lg animate-fade-up delay-2">' . e($lead) . '</p>';
    }
    if ($badgeText !== '') {
        $inner = '<span class="relative inline-flex h-2.5 w-2.5 shrink-0"><span class="absolute inset-0 animate-ping rounded-full bg-accent-500/70"></span><span class="relative h-2.5 w-2.5 rounded-full bg-accent-600"></span></span>'
            . '<span class="min-w-0"><span class="block truncate">' . e($badgeText) . '</span>'
            . ($deadline ? '<span class="block text-[12px] font-medium text-slate-500">Last date to apply: ' . e(date('j M Y', $deadline)) . '</span>' : '') . '</span>'
            . ($badgeUrl ? icon('arrow-right', 'h-4 w-4 shrink-0 text-accent-600 transition-transform group-hover:translate-x-1') : '');
        $cls = 'group mt-7 inline-flex max-w-full items-center gap-3 rounded-2xl border border-accent-200 bg-white/90 px-4 py-2.5 text-[14px] font-semibold text-brand-900 shadow-sm transition hover:border-accent-300 hover:shadow-soft animate-fade-up delay-2';
        $h .= $badgeUrl ? '<a href="' . e($badgeUrl) . '" class="' . $cls . '">' . $inner . '</a>' : '<p class="' . $cls . '">' . $inner . '</p>';
    }
    $h .= '<div class="mt-8 flex flex-wrap items-center gap-3 animate-fade-up delay-3">'
        . hm_button($primary, 'apply', ['size' => 'lg', 'shine' => true, 'magnetic' => true])
        . hm_button($secondary, 'explore', ['size' => 'lg', 'icon' => $secondary && str_starts_with($secondary['url'], '#') ? 'arrow-down-right' : 'arrow-right'])
        . ($video !== '' ? video_lightbox(['url' => $video, 'label' => trim((string) ($c['video_label'] ?? '')) ?: 'Watch Our Campus Tour', 'variant' => 'button', 'title' => 'GIMT campus tour']) : '')
        . '</div>';
    if ($words) {
        $h .= '<p class="mt-6 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-[12.5px] font-bold uppercase tracking-[0.18em] text-slate-500 animate-fade-up delay-4">'
            . implode('<span class="h-1 w-1 rounded-full bg-accent-500" aria-hidden="true"></span>', array_map('e', $words)) . '</p>';
    }
    if ($trust || $est > 1900) {
        $h .= '<ul class="mt-9 flex flex-wrap items-center gap-x-6 gap-y-3 border-t border-warm-2 pt-6 animate-fade-up delay-4" aria-label="Recognitions">';
        foreach ($trust as $t) {
            $h .= '<li class="inline-flex items-center gap-2.5 text-[13.5px] font-semibold leading-tight text-brand-900"><span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white p-1.5 text-accent-600 shadow-sm ring-1 ring-warm-2">'
                . emblem((string) ($t['icon'] ?? 'seal'), 'h-full w-full') . '</span><span class="max-w-[12rem]">' . e($t['title']) . '</span></li>';
        }
        if ($est > 1900) {
            $h .= '<li class="inline-flex items-center gap-2.5 text-[13.5px] font-semibold text-brand-900"><span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white text-accent-600 shadow-sm ring-1 ring-warm-2">'
                . icon('landmark', 'h-4 w-4') . '</span>Since ' . $est . '</li>';
        }
        $h .= '</ul>';
    }
    $h .= '</div>';

    // ---- layered visual
    $h .= '<div class="relative z-0 mx-auto w-full max-w-[560px] lg:col-span-5 lg:max-w-none">';
    $h .= '<div class="relative aspect-[10/9.4]">';
    $h .= '<span class="animate-blob pointer-events-none absolute -right-[4%] top-[6%] -z-10 h-[80%] w-[80%] bg-gradient-to-br from-accent-200/80 via-[#FDEDE7] to-brand-100/80" aria-hidden="true"></span>';
    $h .= '<span class="pointer-events-none absolute -left-[3%] -top-[2%] -z-10 aspect-square w-[34%] rounded-full border-[1.5px] border-transparent [background:linear-gradient(#FAF8F5,#FAF8F5)_padding-box,linear-gradient(135deg,#F05A28,rgba(240,90,40,0)_55%,#0B2A5B)_border-box]" aria-hidden="true"></span>';
    $h .= '<div class="clip-in absolute right-0 top-0 h-[86%] w-[84%] overflow-hidden rounded-[120px_28px_28px_28px] bg-warm-2 shadow-[0_44px_80px_-40px_rgba(7,26,59,.6)] sm:rounded-[180px_32px_32px_32px]">'
        . '<img src="' . e($main) . '" alt="' . e($alt) . '" class="absolute inset-0 h-full w-full object-cover object-[50%_50%]" fetchpriority="high" decoding="async" width="1485" height="575">'
        . '<span class="absolute inset-0 bg-[linear-gradient(200deg,rgba(240,90,40,.18)_0%,rgba(7,26,59,0)_38%,rgba(7,26,59,.5)_100%)]" aria-hidden="true"></span></div>';
    if (isset($others[0])) {
        $h .= '<div class="absolute bottom-[1%] left-0 z-[2] aspect-square w-[38%] overflow-hidden rounded-full border-[6px] border-white bg-warm-2 shadow-[0_26px_50px_-24px_rgba(7,26,59,.6)] animate-zoom-in delay-3">'
            . '<img src="' . e(site_image($others[0])) . '" alt="" class="h-full w-full object-cover" decoding="async" width="722" height="595"></div>';
    }
    if (isset($others[1])) {
        $h .= '<div class="float-delayed absolute left-0 top-[9%] z-[2] hidden aspect-[4/3] w-[32%] overflow-hidden rounded-[22px] border-4 border-white bg-warm-2 shadow-[0_22px_44px_-22px_rgba(7,26,59,.55)] sm:block">'
            . '<img src="' . e(site_image($others[1])) . '" alt="" class="h-full w-full object-cover" decoding="async" width="1260" height="580"><span class="absolute inset-0 bg-gradient-to-br from-accent-500/30 via-transparent to-brand-900/40" aria-hidden="true"></span></div>';
    }
    // floating geometric shapes (decorative)
    $h .= '<span class="float-slow pointer-events-none absolute -right-[2%] top-[44%] z-[3] h-12 w-12 rounded-full border-[9px] border-brand-900 sm:h-14 sm:w-14" aria-hidden="true"></span>'
        . '<span class="float-rotate pointer-events-none absolute bottom-[3%] right-[20%] z-[1] h-12 w-12 rounded-tl-full bg-accent-500 sm:h-14 sm:w-14" aria-hidden="true"></span>'
        . '<span class="pointer-events-none absolute -top-[4%] left-[38%] -z-10 h-20 w-28 opacity-40 [background-image:radial-gradient(#0B2A5B_1.5px,transparent_1.7px)] [background-size:13px_13px]" aria-hidden="true"></span>'
        . '<span class="spin-slow pointer-events-none absolute bottom-[34%] left-[-4%] z-[3] text-accent-600" aria-hidden="true"><svg viewBox="0 0 24 24" class="h-6 w-6" fill="currentColor"><path d="M10 2h4v8h8v4h-8v8h-4v-8H2v-4h8z"/></svg></span>';
    foreach ($chips as $i => $ch) {
        $pos = $i === 0 ? 'right-[5%] top-[7%]' : 'left-[30%] top-[50%] hidden xl:flex';
        $h .= '<span class="glass absolute z-[4] flex items-center gap-2 rounded-full py-1.5 pl-1.5 pr-3.5 text-[12.5px] font-bold text-brand-900 animate-fade-up ' . $pos . '" style="animation-delay:' . (500 + $i * 160) . 'ms">'
            . '<span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-accent-600 text-white">' . icon((string) ($ch['icon'] ?? 'sparkles') ?: 'sparkles', 'h-3.5 w-3.5') . '</span>' . e($ch['label']) . '</span>';
    }
    $h .= '</div>';
    if ($spot) {
        $h .= '<div class="glass-panel relative z-[5] mx-auto mt-8 w-full max-w-sm rounded-[22px] p-2.5 sm:absolute sm:bottom-[-9%] sm:right-[-3%] sm:mt-0 sm:w-[min(330px,76%)] animate-fade-up delay-4" data-slider role="region" aria-roledescription="carousel" aria-label="Spotlight">'
            . '<div class="flex snap-x snap-mandatory overflow-x-auto scrollbar-none" data-slider-track tabindex="0" aria-label="Spotlight items — use the arrow keys">';
        foreach ($spot as $i => $sp) {
            $h .= '<a href="' . e($sp['link']['href']) . '" class="group flex w-full shrink-0 snap-start items-center gap-3 rounded-[16px] p-1.5 pr-2 transition hover:bg-white/70" aria-label="' . e(($sp['eyebrow'] !== '' ? $sp['eyebrow'] . ': ' : '') . $sp['title'] . ' — ' . $sp['link']['label']) . '">'
                . '<img src="' . e($sp['image']) . '" alt="" class="h-14 w-14 shrink-0 rounded-xl object-cover" loading="lazy" decoding="async" width="56" height="56">'
                . '<span class="min-w-0"><span class="block truncate text-[11px] font-bold uppercase tracking-[0.12em] text-accent-700">' . e($sp['eyebrow'] ?: 'Spotlight') . '</span>'
                . '<span class="mt-0.5 block truncate font-display text-[14.5px] font-bold text-brand-900">' . e($sp['title']) . '</span>'
                . '<span class="mt-0.5 inline-flex items-center gap-1 text-[12.5px] font-semibold text-slate-600 group-hover:text-accent-700">' . e($sp['link']['label']) . icon('arrow-right', 'h-3.5 w-3.5') . '</span></span></a>';
        }
        $h .= '</div>';
        if (count($spot) > 1) {
            $h .= '<div class="flex items-center justify-between px-1.5 pb-0.5 pt-1"><span class="text-[11px] font-bold uppercase tracking-[0.14em] text-slate-500">' . count($spot) . ' highlights</span><span class="flex gap-1.5">'
                . '<button type="button" data-slider-prev class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-white text-brand-900 shadow-sm ring-1 ring-warm-2 transition hover:bg-brand-900 hover:text-white disabled:opacity-40" aria-label="Previous highlight">' . icon('chevron-left', 'h-4 w-4') . '</button>'
                . '<button type="button" data-slider-next class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-white text-brand-900 shadow-sm ring-1 ring-warm-2 transition hover:bg-brand-900 hover:text-white disabled:opacity-40" aria-label="Next highlight">' . icon('chevron-right', 'h-4 w-4') . '</button></span></div>';
        }
        $h .= '</div>';
    }
    $h .= '</div></div></section>';
    return $h;
}

/* ------------------------------------------------------------------------------------------------ 2. Stats */

function hm_render_stats(array $s, ?string $prev = null): string
{
    $c = $s['content'];
    $items = array_values(array_filter(array_map('hm_stat', (array) ($c['items'] ?? []))));
    if (!$items) {
        return '';
    }
    $variant = in_array($c['variant'] ?? 'overlap', ['overlap', 'band', 'cards'], true) ? $c['variant'] : 'overlap';
    $id = hm_anchor('stats', $c);
    $title = trim($s['title']);
    $n = count($items);
    $cols = [1 => 'grid-cols-1', 2 => 'grid-cols-2', 3 => 'grid-cols-3', 4 => 'grid-cols-2 lg:grid-cols-4', 5 => 'grid-cols-2 lg:grid-cols-5', 6 => 'grid-cols-2 lg:grid-cols-3'][$n] ?? 'grid-cols-2 lg:grid-cols-4';
    if ($variant === 'band') {
        $h = '<section id="' . e($id) . '" class="relative isolate overflow-hidden bg-brand-950 py-14 text-white sm:py-16"><div class="pattern-layer pattern-grid-light pattern-fade" aria-hidden="true"></div><div class="container-site">'
            . ($title !== '' ? '<h2 class="mb-10 text-center font-display text-2xl font-extrabold text-white">' . e($title) . '</h2>' : '<h2 class="sr-only">At a glance</h2>')
            . '<ul class="grid gap-y-10 ' . $cols . ' lg:divide-x lg:divide-white/15" data-stagger="90">';
        foreach ($items as $st) {
            $h .= '<li class="px-4 text-center">' . hm_count($st, 'block font-display text-[clamp(2.2rem,1.4rem+2.4vw,3.4rem)] font-extrabold leading-none tracking-tight text-white')
                . '<span class="mt-2 block text-[14px] font-medium text-white/70">' . e($st['label']) . '</span></li>';
        }
        return $h . '</ul></div></section>';
    }
    $overlap = $variant === 'overlap' && $prev === 'hero';
    $h = '<section id="' . e($id) . '" class="relative z-10 ' . ($overlap ? '-mt-16 sm:-mt-20 lg:-mt-24' : 'pt-12 sm:pt-16') . '"><div class="container-site">'
        . '<h2 class="sr-only">' . e($title !== '' ? $title : 'At a glance') . '</h2>';
    if ($variant === 'cards') {
        $h .= '<ul class="grid gap-4 ' . $cols . '" data-stagger="80">';
        foreach ($items as $i => $st) {
            $h .= '<li class="rounded-[22px] border border-warm-2 bg-white p-6 shadow-card">' . ($st['icon'] !== '' ? '<span class="icon-circle tint-' . ['sky', 'orange', 'lavender', 'aqua', 'sand', 'blush'][$i % 6] . '">' . icon($st['icon'], 'h-6 w-6') . '</span>' : '')
                . hm_count($st, 'mt-4 block font-display text-[2.4rem] font-extrabold leading-none tracking-tight text-brand-900') . '<span class="mt-2 block text-[14px] font-medium text-slate-600">' . e($st['label']) . '</span></li>';
        }
        return $h . '</ul></div></section>';
    }
    $h .= '<ul class="grid gap-px overflow-hidden rounded-[28px] border border-warm-2 bg-warm-2 shadow-[0_34px_70px_-38px_rgba(7,26,59,.5)] ' . $cols . '" data-stagger="90">';
    foreach ($items as $i => $st) {
        $h .= '<li class="flex items-center gap-4 bg-white px-3 py-5 sm:px-7 sm:py-8">'
            . ($st['icon'] !== '' ? '<span class="hidden h-12 w-12 shrink-0 items-center justify-center rounded-2xl sm:inline-flex ' . ($i % 2 ? 'bg-brand-50 text-brand-900' : 'bg-accent-50 text-accent-600') . '">' . icon($st['icon'], 'h-6 w-6') . '</span>' : '')
            . '<span class="min-w-0">' . hm_count($st, 'block font-display text-[clamp(1.35rem,0.85rem+2vw,2.6rem)] font-extrabold leading-none tracking-tight text-brand-900')
            . '<span class="mt-2 block text-[12.5px] font-medium text-slate-600 sm:text-[14.5px]">' . e($st['label']) . '</span></span></li>';
    }
    return $h . '</ul></div></section>';
}

/* ------------------------------------------------------------------------------------------------ 3. Feature strip */

function hm_render_features(array $s, ?string $prev = null): string
{
    $items = array_values(array_filter((array) ($s['content']['items'] ?? []), fn ($it) => trim((string) ($it['title'] ?? '')) !== ''));
    if (!$items) {
        return '';
    }
    $n = count($items);
    $grid = $n >= 6 ? 'sm:grid-cols-3 xl:grid-cols-6' : ($n === 5 ? 'sm:grid-cols-3 lg:grid-cols-5' : ($n === 4 ? 'lg:grid-cols-4' : 'sm:grid-cols-3'));
    $h = '<section id="' . e(hm_anchor('features', $s['content'])) . '" class="' . (in_array($prev, ['stats', 'hero'], true) ? 'pb-6 pt-14 sm:pt-16' : 'py-14 sm:py-16') . '"><div class="container-site">'
        . '<h2 class="sr-only">' . e($s['title'] ?: 'Highlights') . '</h2>'
        . ($s['subtitle'] !== '' ? '<p class="mb-8 max-w-2xl text-slate-600">' . e($s['subtitle']) . '</p>' : '')
        . '<ul class="grid grid-cols-2 gap-x-6 gap-y-9 ' . $grid . '" data-stagger="70">';
    foreach ($items as $i => $it) {
        $h .= '<li class="group flex flex-col items-start gap-3 xl:border-l xl:border-warm-2 xl:pl-6 xl:first:border-l-0 xl:first:pl-0">'
            . '<span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-900 ring-1 ring-brand-100 transition duration-300 group-hover:-translate-y-0.5 group-hover:bg-brand-900 group-hover:text-white">' . icon((string) ($it['icon'] ?? 'sparkles') ?: 'sparkles', 'h-6 w-6') . '</span>'
            . '<h3 class="font-display text-[15.5px] font-bold leading-snug text-brand-900">' . e($it['title']) . '</h3>'
            . (trim((string) ($it['text'] ?? '')) !== '' ? '<p class="text-[13.5px] leading-relaxed text-slate-500">' . e($it['text']) . '</p>' : '') . '</li>';
    }
    return $h . '</ul></div></section>';
}

/* ------------------------------------------------------------------------------------------------ 4. Programs → Course Finder */

/** Course Finder card (server HTML: fallback + island slot). */
function hm_program_card(array $p, bool $lazy = true): string
{
    $url = site_url('programs/' . $p['slug']);
    $apply = site_url('apply?program=' . rawurlencode((string) $p['slug']));
    $name = (string) $p['name'];
    $suffix = program_title_suffix($p);
    $fee = (float) ($p['fee_per_year'] ?? 0);
    $feeUnit = str_contains(strtolower((string) ($p['fee_label'] ?? '')), 'course') ? '/course' : '/yr';
    $elig = trim(strip_tags((string) ($p['eligibility'] ?? '')));
    $ribbon = !empty($p['is_popular']) ? 'Most Popular' : (!empty($p['is_featured']) ? 'Featured' : '');
    $h = '<article class="group relative flex h-full flex-col overflow-hidden rounded-[22px] border border-warm-2 bg-white transition duration-300 hover:-translate-y-1 hover:border-[#E2DAD0] hover:shadow-[0_26px_50px_-30px_rgba(7,26,59,.45)]">';
    $h .= '<a href="' . e($url) . '" class="relative block aspect-[16/10] overflow-hidden bg-warm" tabindex="-1" aria-hidden="true">'
        . '<img src="' . e(site_image($p['image'] ?? null)) . '" alt="" class="h-full w-full object-cover transition duration-700 group-hover:scale-[1.05]"' . ($lazy ? ' loading="lazy"' : '') . ' decoding="async" width="640" height="400">'
        . '<span class="absolute inset-0 bg-gradient-to-t from-brand-950/60 via-brand-950/0 to-brand-950/10"></span>'
        . '<span class="absolute inset-x-3 top-3 flex items-start justify-between gap-2">' . ($ribbon !== '' ? '<span class="rounded-full bg-sun px-2.5 py-1 text-[11px] font-extrabold uppercase tracking-wide text-brand-900">' . e($ribbon) . '</span>' : '<span></span>')
        . '<span class="rounded-full bg-white/90 px-2.5 py-1 text-[11.5px] font-bold text-brand-900">' . e(program_level_label($p['level'] ?? '')) . '</span></span>'
        . (($p['short_name'] ?? '') !== '' ? '<span class="absolute bottom-3 left-3.5 font-display text-[15px] font-extrabold tracking-tight text-white drop-shadow">' . e($p['short_name']) . '</span>' : '')
        . '</a>';
    $h .= '<div class="flex flex-1 flex-col p-5">';
    if (($p['department_label'] ?? '') !== '') {
        $h .= '<span class="dept-chip dept-' . (int) ($p['dept_index'] ?? 0) . ' w-fit">' . e($p['department_label']) . '</span>';
    }
    $h .= '<h3 class="mt-3 font-display text-[17px] font-extrabold leading-snug tracking-tight text-brand-900"><a href="' . e($url) . '" class="transition-colors hover:text-accent-700">' . e($name)
        . ($suffix !== '' ? ' <span class="whitespace-nowrap">(' . e($suffix) . ')</span>' : '') . '</a></h3>';
    $h .= '<dl class="mt-4 grid grid-cols-3 divide-x divide-warm-2 rounded-2xl bg-[#FAF8F5] py-2.5 text-center ring-1 ring-inset ring-warm-2">'
        . '<div class="px-1.5"><dt class="text-[10.5px] font-bold uppercase tracking-[0.1em] text-slate-500">Duration</dt><dd class="mt-0.5 text-[13.5px] font-bold text-brand-900">' . e((string) ($p['duration_label'] ?: (float) $p['duration_years'] . ' Years')) . '</dd></div>'
        . '<div class="px-1.5"><dt class="text-[10.5px] font-bold uppercase tracking-[0.1em] text-slate-500">Fee</dt><dd class="mt-0.5 text-[13.5px] font-bold text-brand-900">' . ($fee > 0 ? e(money($fee)) . '<span class="text-[11px] font-semibold text-slate-500">' . $feeUnit . '</span>' : '—') . '</dd></div>'
        . '<div class="px-1.5"><dt class="text-[10.5px] font-bold uppercase tracking-[0.1em] text-slate-500">Seats</dt><dd class="mt-0.5 text-[13.5px] font-bold text-brand-900">' . ((int) ($p['intake_capacity'] ?? 0) > 0 ? (int) $p['intake_capacity'] : '—') . '</dd></div></dl>';
    if ($elig !== '') {
        $h .= '<p class="mt-3.5 flex items-start gap-2 text-[13px] leading-relaxed text-slate-600">' . icon('graduation-cap', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') . '<span class="line-clamp-2"><span class="font-semibold text-brand-900">Eligibility:</span> ' . e(str_limit($elig, 150)) . '</span></p>';
    }
    $h .= '<div class="mt-auto flex items-center justify-between gap-3 pt-5">'
        . '<a href="' . e($apply) . '" class="btn btn-apply btn-sm">Apply<span class="sr-only"> for ' . e($name) . '</span>' . icon('arrow-right', 'h-4 w-4') . '</a>'
        . '<a href="' . e($url) . '" class="link-arrow text-[13.5px]">View details<span class="sr-only"> of ' . e($name) . '</span>' . icon('arrow-right', 'h-4 w-4') . '</a></div>';
    return $h . '</div></article>';
}

/** Course Finder filter options from the live programs. */
function hm_finder_options(array $programs): array
{
    $streams = [];
    foreach (hm_departments() as $d) {
        $streams[$d['stream']] = ['value' => $d['stream'], 'label' => $d['label'], 'count' => 0];
    }
    $levels = [];
    $durations = [];
    $elig = [];
    $eligLabels = hm_eligibility_labels();
    foreach ($programs as $p) {
        $streams[$p['stream']] ??= ['value' => $p['stream'], 'label' => $p['department_label'] ?: 'Other', 'count' => 0];
        $streams[$p['stream']]['count']++;
        $levels[$p['level_key']] ??= ['value' => $p['level_key'], 'label' => program_level_label($p['level']), 'count' => 0];
        $levels[$p['level_key']]['count']++;
        $durations[$p['duration_key']] ??= ['value' => $p['duration_key'], 'label' => (string) ($p['duration_label'] ?: $p['duration_key'] . ' years'), 'count' => 0, 'sort' => (float) $p['duration_years']];
        $durations[$p['duration_key']]['count']++;
        foreach ($p['elig'] as $b) {
            $elig[$b] ??= ['value' => $b, 'label' => $eligLabels[$b] ?? ucfirst($b), 'count' => 0];
            $elig[$b]['count']++;
        }
    }
    $levelOrder = ['ug' => 1, 'pg' => 2, 'phd' => 3, 'diploma' => 4, 'certificate' => 5];
    uasort($levels, fn ($a, $b) => ($levelOrder[$a['value']] ?? 9) <=> ($levelOrder[$b['value']] ?? 9));
    uasort($durations, fn ($a, $b) => $a['sort'] <=> $b['sort']);
    $eligOrder = array_flip(array_keys($eligLabels));
    uksort($elig, fn ($a, $b) => ($eligOrder[$a] ?? 99) <=> ($eligOrder[$b] ?? 99));
    $strip = fn (array $list) => array_values(array_map(fn ($o) => ['value' => $o['value'], 'label' => $o['label'], 'count' => $o['count']], array_filter($list, fn ($o) => $o['count'] > 0)));
    return ['streams' => $strip($streams), 'levels' => $strip($levels), 'durations' => $strip($durations), 'eligibility' => $strip($elig)];
}

/** Current Course Finder filters from the query string (shared by the no-JS fallback and the island's initial state). */
function hm_finder_state(array $opts): array
{
    $pick = function (string $key, array $list) {
        $v = strtolower(trim((string) ($_GET[$key] ?? '')));
        return in_array($v, array_column($list, 'value'), true) ? $v : '';
    };
    $cat = strtolower(trim((string) ($_GET['cat'] ?? '')));
    return [
        'q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60),
        'stream' => $pick('stream', $opts['streams']), 'level' => $pick('level', $opts['levels']),
        'duration' => $pick('duration', $opts['durations']), 'elig' => $pick('elig', $opts['eligibility']),
        'cat' => preg_match('/^[a-z-]{2,30}$/', $cat) ? $cat : '',
    ];
}

function hm_render_programs(array $s): string
{
    $c = $s['content'];
    $programs = hm_programs();
    $id = hm_anchor('programs', $c);
    $viewAll = hm_link($c['view_all'] ?? null, ['label' => 'View All Programs', 'url' => '/programs']);
    $h = '<section id="' . e($id) . '" class="section relative isolate overflow-hidden bg-[#FAF8F5]">'
        . '<div class="pointer-events-none absolute -right-24 top-10 -z-10 h-80 w-80 rounded-full bg-accent-100/60 blur-3xl" aria-hidden="true"></div><div class="container-site">';
    $h .= section_heading($s['title'], $s['subtitle'], (string) ($c['eyebrow'] ?? ''), 'left', $viewAll ? button_link($viewAll['label'], $viewAll['url'], 'brochure', ['icon' => 'arrow-right']) : '');
    if (!$programs) {
        $h .= '<div class="empty-state rounded-[22px] border border-dashed border-warm-line bg-white"><span class="empty-state-icon">' . icon('graduation-cap', 'h-6 w-6') . '</span>'
            . '<p class="mt-3 font-semibold text-brand-900">Program details for the new session will be published shortly.</p>'
            . '<a href="' . e(hm_counsellor_url()) . '" class="btn btn-callback btn-sm mt-4">Talk to a counsellor</a></div>';
        return $h . '</div></section>';
    }

    // CMS mode decides which programs lead (and how many show before "Show all"); the finder always searches all of them
    $mode = in_array($c['mode'] ?? 'all', ['all', 'featured', 'selected'], true) ? $c['mode'] : 'all';
    $ids = array_map('intval', (array) ($c['program_ids'] ?? []));
    foreach ($programs as $i => &$p) {
        $p['rank'] = $mode === 'selected' && in_array((int) $p['id'], $ids, true) ? array_search((int) $p['id'], $ids, true)
            : ($mode === 'featured' && !empty($p['is_featured']) ? 100 + $i : 1000 + $i);
    }
    unset($p);
    usort($programs, fn ($a, $b) => $a['rank'] <=> $b['rank']);
    $limit = max(3, min(24, (int) ($c['limit'] ?? 6)));
    $opts = hm_finder_options($programs);
    $state = hm_finder_state($opts);

    // quick picks = the CMS category pills, mapped onto finder filters
    $catLabels = cms_program_category_options();
    $quick = [];
    foreach ((array) ($c['categories'] ?? []) as $key) {
        $key = (string) $key;
        if (!isset($catLabels[$key])) {
            continue;
        }
        if ($key === 'all') {
            $quick[] = ['key' => 'all', 'label' => $catLabels[$key]];
        } elseif (in_array($key, ['ug', 'pg', 'diploma', 'certificate'], true)) {
            if (in_array($key, array_column($opts['levels'], 'value'), true)) {
                $quick[] = ['key' => $key, 'label' => $catLabels[$key], 'level' => $key];
            }
        } elseif (array_filter($programs, fn ($p) => in_array($key, explode(' ', $p['cat_tokens']), true))) {
            $quick[] = ['key' => $key, 'label' => $catLabels[$key], 'cat' => $key];
        }
    }
    if (count($quick) <= 1) {
        $quick = [];
    }

    // server-side filtering for the no-JS fallback (same rules as the island)
    $matches = function (array $p) use ($state): bool {
        if ($state['stream'] !== '' && $p['stream'] !== $state['stream']) {
            return false;
        }
        if ($state['level'] !== '' && $p['level_key'] !== $state['level']) {
            return false;
        }
        if ($state['duration'] !== '' && $p['duration_key'] !== $state['duration']) {
            return false;
        }
        if ($state['elig'] !== '' && !in_array($state['elig'], $p['elig'], true)) {
            return false;
        }
        if ($state['cat'] !== '' && !in_array($state['cat'], explode(' ', $p['cat_tokens']), true)) {
            return false;
        }
        if ($state['q'] !== '') {
            $hay = mb_strtolower($p['name'] . ' ' . $p['short_name'] . ' ' . $p['category'] . ' ' . $p['department_label'] . ' ' . program_level_label($p['level']));
            foreach (preg_split('/\s+/', mb_strtolower($state['q'])) as $w) {
                if ($w !== '' && !str_contains($hay, $w)) {
                    return false;
                }
            }
        }
        return true;
    };
    $filtered = array_filter($state) !== [];
    $visible = array_values(array_filter($programs, $matches));
    $shown = $filtered ? $visible : array_slice($visible, 0, $limit);

    $select = function (string $name, string $label, array $list, string $value): string {
        $o = '<option value="">' . e($label) . ': All</option>';
        foreach ($list as $it) {
            $o .= '<option value="' . e($it['value']) . '"' . ($it['value'] === $value ? ' selected' : '') . '>' . e($it['label']) . '</option>';
        }
        return '<label class="relative block min-w-0"><span class="sr-only">' . e($label) . '</span><select name="' . e($name) . '" class="h-12 w-full cursor-pointer appearance-none truncate rounded-full border border-warm-2 bg-white pl-4 pr-8 text-[13.5px] font-semibold sm:text-[14px] text-brand-900 shadow-sm transition hover:border-[#E2DAD0] focus:border-brand-400 focus:outline-none focus:ring-4 focus:ring-accent-500/10">' . $o . '</select>'
            . icon('chevron-down', 'pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400') . '</label>';
    };
    $fb = '<div class="course-finder">';
    $fb .= '<form method="get" action="' . e(site_url('')) . '#' . e($id) . '" class="grid grid-cols-2 gap-2.5 rounded-[26px] border border-warm-2 bg-white/80 p-2.5 shadow-[0_18px_40px_-30px_rgba(7,26,59,.35)] sm:gap-3 sm:p-3 lg:grid-cols-[minmax(0,1.5fr)_repeat(4,minmax(0,1fr))_auto]" role="search" aria-label="Find a course">'
        . '<label class="relative col-span-2 block min-w-0 lg:col-span-1"><span class="sr-only">Search programs</span>' . icon('search', 'pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400')
        . '<input type="search" name="q" value="' . e($state['q']) . '" placeholder="Search a course, e.g. MBA" class="h-12 w-full rounded-full border border-warm-2 bg-white pl-11 pr-4 text-[14px] text-brand-900 shadow-sm placeholder:text-slate-400 focus:border-brand-400 focus:outline-none focus:ring-4 focus:ring-accent-500/10"></label>'
        . $select('stream', 'Stream', $opts['streams'], $state['stream']) . $select('level', 'Degree', $opts['levels'], $state['level'])
        . $select('duration', 'Duration', $opts['durations'], $state['duration']) . $select('elig', 'Eligibility', $opts['eligibility'], $state['elig'])
        . '<button type="submit" class="btn btn-explore col-span-2 h-12 lg:col-span-1">' . icon('search', 'h-4 w-4') . 'Find</button></form>';
    if ($quick) {
        $fb .= '<div class="pill-row scrollbar-none mt-5" role="group" aria-label="Quick picks">';
        foreach ($quick as $q) {
            $active = $q['key'] === 'all' ? !$filtered : (isset($q['level']) ? $state['level'] === $q['level'] && $state['cat'] === '' : $state['cat'] === ($q['cat'] ?? ''));
            $href = site_url('') . ($q['key'] === 'all' ? '' : '?' . (isset($q['level']) ? 'level=' . $q['level'] : 'cat=' . $q['cat'])) . '#' . $id;
            $fb .= '<a href="' . e($href) . '" class="chip !py-2 !text-[14px]' . ($active ? ' chip-active' : '') . '"' . ($active ? ' aria-current="true"' : '') . '>' . e($q['label']) . '</a>';
        }
        $fb .= '</div>';
    }
    $fb .= '<p class="mt-6 text-[14px] font-semibold text-slate-600" role="status"><span class="font-display text-lg font-extrabold text-brand-900">' . count($visible) . '</span> ' . (count($visible) === 1 ? 'program' : 'programs') . ' found</p>';
    $fb .= '<div class="mt-4 -mx-4 flex snap-x snap-mandatory scroll-px-4 gap-4 overflow-x-auto px-4 pb-3 scrollbar-none sm:mx-0 sm:grid sm:overflow-visible sm:px-0 sm:pb-0 sm:grid-cols-2 sm:gap-6 lg:grid-cols-3" data-slot="items">';
    foreach ($programs as $p) {
        $isShown = in_array($p, $shown, true);
        $fb .= '<div class="h-full w-[85%] shrink-0 snap-start sm:w-auto' . ($isShown ? '' : ' hidden') . '" data-key="' . e($p['slug']) . '" data-stream="' . e($p['stream']) . '" data-level="' . e($p['level_key']) . '" data-duration="' . e($p['duration_key']) . '"'
            . ' data-elig="' . e(implode(' ', $p['elig'])) . '" data-cat="' . e($p['cat_tokens']) . '" data-name="' . e($p['name']) . '"'
            . ' data-search="' . e(trim($p['name'] . ' ' . $p['short_name'] . ' ' . $p['category'] . ' ' . $p['department_label'] . ' ' . program_level_label($p['level']))) . '">'
            . hm_program_card($p) . '</div>';
    }
    $fb .= '</div>';
    if (!$visible) {
        $fb .= '<div class="empty-state mt-4 rounded-[22px] border border-dashed border-warm-line bg-white"><span class="empty-state-icon">' . icon('search', 'h-6 w-6') . '</span>'
            . '<p class="mt-3 font-display text-lg font-bold text-brand-900">No program matches these filters</p><p class="mt-1 text-sm text-slate-500">Try fewer filters, or let a counsellor suggest the right course for you.</p>'
            . '<div class="mt-5 flex flex-wrap justify-center gap-3"><a href="' . e(site_url('') . '#' . $id) . '" class="btn btn-secondary btn-sm">Clear filters</a><a href="' . e(hm_counsellor_url()) . '" class="btn btn-callback btn-sm">' . icon('phone', 'h-4 w-4') . 'Talk to a counsellor</a></div></div>';
    } elseif (!$filtered && count($visible) > $limit) {
        $fb .= '<div class="mt-10 flex justify-center"><a href="' . e(site_url('programs')) . '" class="btn btn-brochure">Show all ' . count($visible) . ' programs' . icon('arrow-right', 'h-4 w-4') . '</a></div>';
    }
    $fb .= '</div>';

    $props = [
        'streams' => $opts['streams'], 'levels' => $opts['levels'], 'durations' => $opts['durations'], 'eligibility' => $opts['eligibility'],
        'quick' => $quick, 'initial' => $state, 'limit' => $limit, 'total' => count($programs), 'anchor' => $id,
        'counsellorUrl' => hm_counsellor_url(), 'allUrl' => hm_url_ok('/programs') ? site_url('programs') : null,
    ];
    $h .= island('CourseFinder', $props, $fb, ['class' => 'block']);

    // departments
    $depts = hm_departments();
    if ($depts) {
        $academics = hm_url_ok('/academics') ? site_url('academics') . '#departments' : null;
        $h .= '<div class="mt-16 border-t border-warm-2 pt-12"><div class="mb-7 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div>' . hm_kicker('Departments')
            . '<h3 class="mt-3 font-display text-2xl font-extrabold tracking-tight text-brand-900 sm:text-[1.75rem]">Explore by department</h3></div>'
            . ($academics ? '<a href="' . e($academics) . '" class="link-arrow">All departments' . icon('arrow-right', 'h-4 w-4') . '</a>' : '') . '</div>';
        $h .= '<ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-stagger="70">';
        foreach ($depts as $d) {
            $h .= '<li class="group relative flex items-start gap-4 rounded-[20px] border border-warm-2 bg-white p-5 transition duration-300 hover:-translate-y-0.5 hover:border-[#E2DAD0] hover:shadow-[0_20px_40px_-28px_rgba(7,26,59,.45)]">'
                . '<span class="dept-' . (int) $d['index'] . ' inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border text-brand-900">' . icon($d['icon'], 'h-5 w-5') . '</span>'
                . '<div class="min-w-0 flex-1"><h4 class="font-display text-[15.5px] font-bold leading-snug text-brand-900">'
                . ($academics ? '<a href="' . e($academics) . '" class="stretched-link focus:outline-none">' . e($d['label']) . '</a>' : e($d['label'])) . '</h4>'
                . '<p class="mt-1 text-[13px] text-slate-500">' . (int) $d['count'] . ' ' . ($d['count'] === 1 ? 'program' : 'programs') . ($d['hod'] !== '' ? ' · Head: ' . e($d['hod']) : '') . '</p>'
                . '<a href="' . e(site_url('') . '?stream=' . rawurlencode($d['stream']) . '#' . $id) . '" data-finder-stream="' . e($d['stream']) . '" class="relative z-[2] mt-3 inline-flex items-center gap-1.5 text-[13px] font-semibold text-accent-700 hover:text-brand-900">View programs<span class="sr-only"> in ' . e($d['label']) . '</span>' . icon('arrow-right', 'h-3.5 w-3.5') . '</a></div></li>';
        }
        $h .= '</ul></div>';
    }
    return $h . '</div></section>';
}

/* ------------------------------------------------------------------------------------------------ 5. Why GIMT */

function hm_render_why(array $s): string
{
    $c = $s['content'];
    $items = array_values(array_filter((array) ($c['items'] ?? []), fn ($it) => trim((string) ($it['title'] ?? '')) !== ''));
    if (!$items) {
        return '';
    }
    $tints = ['lavender', 'sky', 'aqua', 'sand', 'blush', 'pistachio'];
    $tintMap = ['lavender' => 'lavender', 'sky' => 'sky', 'aqua' => 'aqua', 'cream' => 'sand', 'sand' => 'sand', 'orange' => 'orange', 'ink' => 'navy'];
    $image = trim((string) ($c['image'] ?? ''));
    $h = '<section id="' . e(hm_anchor('why', $c)) . '" class="section relative isolate overflow-hidden bg-white"><div class="container-site grid grid-cols-1 gap-12 lg:grid-cols-12 lg:gap-14">';
    $h .= '<div class="lg:col-span-4"><div class="lg:sticky lg:top-32">' . section_heading($s['title'], $s['subtitle'], (string) ($c['eyebrow'] ?? ''), 'left', '', ['class' => '!mb-0']);
    if ($image !== '') {
        $h .= '<figure class="mt-8 overflow-hidden rounded-[26px] bg-warm-2" data-reveal="clip"><img src="' . e(site_image($image)) . '" alt="" class="aspect-[4/3] h-full w-full object-cover" loading="lazy" decoding="async"></figure>';
    } else {
        $h .= '<div class="mt-8 hidden rounded-[26px] bg-brand-950 p-7 text-white lg:block">'
            . '<p class="font-display text-lg font-bold leading-snug">Not sure which program fits you?</p><p class="mt-2 text-[14.5px] text-white/70">Our admission counsellors help you compare courses, fees and scholarships — free of charge.</p>'
            . '<a href="' . e(hm_counsellor_url()) . '" class="btn btn-apply btn-sm mt-5">Request a callback' . icon('arrow-right', 'h-4 w-4') . '</a></div>';
    }
    $h .= '</div></div>';
    $h .= '<ul class="grid gap-4 sm:grid-cols-2 lg:col-span-8" data-stagger="70">';
    foreach ($items as $i => $it) {
        $tint = $tintMap[(string) ($it['tint'] ?? '')] ?? $tints[$i % count($tints)];
        $h .= '<li class="h-full"><div class="group relative flex h-full gap-4 overflow-hidden rounded-[24px] border border-warm-2 bg-[#FAF8F5] p-5 transition-[background-color,border-color,box-shadow] duration-300 hover:border-[#E2DAD0] hover:bg-white hover:shadow-[0_26px_50px_-32px_rgba(7,26,59,.45)] sm:block sm:p-7" data-tilt="4">'
            . '<span class="pointer-events-none absolute right-6 top-5 font-display text-[13px] font-extrabold tracking-[0.12em] text-[#D9CFC2]" aria-hidden="true">' . sprintf('%02d', $i + 1) . '</span>'
            . '<span class="icon-circle tint-' . e($tint) . ' transition-transform duration-300 group-hover:-translate-y-0.5">' . icon((string) ($it['icon'] ?? 'sparkles') ?: 'sparkles', 'h-6 w-6') . '</span>'
            . '<span class="block min-w-0 pr-6 sm:pr-0"><h3 class="font-display text-[17px] font-bold leading-snug text-brand-900 sm:mt-5 sm:text-[17.5px]">' . e($it['title']) . '</h3>'
            . (trim((string) ($it['text'] ?? '')) !== '' ? '<p class="mt-1.5 text-[14px] leading-relaxed text-slate-600 sm:mt-2 sm:text-[14.5px]">' . e($it['text']) . '</p>' : '') . '</span>'
            . '<span class="absolute inset-x-6 bottom-0 h-[3px] origin-left scale-x-0 rounded-full bg-gradient-to-r from-accent-500 to-brand-900 transition-transform duration-500 group-hover:scale-x-100" aria-hidden="true"></span></div></li>';
    }
    return $h . '</ul></div></section>';
}

/* ------------------------------------------------------------------------------------------------ 6. Academic excellence */

function hm_render_academic_excellence(array $s): string
{
    $c = $s['content'];
    $id = hm_anchor('academic_excellence', $c);
    $img = trim((string) ($c['image'] ?? '')) ?: 'assets/images/site/campus-main.jpg';
    $bullets = array_values(array_filter(array_map('trim', (array) ($c['bullets'] ?? []))));
    $cards = array_slice(array_values(array_filter((array) ($c['cards'] ?? []), fn ($cd) => trim((string) ($cd['title'] ?? '')) !== '')), 0, 3);
    $cta = hm_link($c['cta'] ?? null, ['label' => 'Discover GIMT', 'url' => '/about']);
    $video = trim((string) ($c['video_url'] ?? ''));
    $video = $video !== '' && cms_valid_video($video) ? $video : '';

    // ---- About GIMT
    $h = '<section id="' . e($id) . '" class="section relative isolate overflow-hidden bg-white"><div class="container-site grid grid-cols-1 items-center gap-14 lg:grid-cols-12 lg:gap-16">';
    $h .= '<div class="relative lg:col-span-6"><div class="relative">'
        . '<figure class="relative overflow-hidden rounded-[30px_30px_30px_120px] bg-warm-2 shadow-[0_40px_80px_-46px_rgba(7,26,59,.6)]" data-reveal="clip">'
        . '<img src="' . e(site_image($img)) . '" alt="' . e(trim((string) ($c['image_alt'] ?? '')) ?: 'The GIMT campus') . '" class="aspect-[16/11] h-full w-full object-cover" loading="lazy" decoding="async" width="672" height="462">'
        . '<span class="absolute inset-0 bg-gradient-to-t from-brand-950/45 via-transparent to-transparent" aria-hidden="true"></span>'
        . ($video !== '' ? '<span class="absolute left-5 top-5">' . video_lightbox(['url' => $video, 'label' => trim((string) ($c['video_label'] ?? '')) ?: 'Virtual Campus Tour', 'variant' => 'chip', 'title' => 'GIMT virtual campus tour']) . '</span>' : '')
        . '</figure>'
        . '<span class="float-slow pointer-events-none absolute -right-4 -top-5 h-16 w-16 rounded-[20px] bg-accent-500/90 [clip-path:polygon(0_0,100%_0,100%_100%)]" aria-hidden="true"></span>'
        . '<span class="pointer-events-none absolute -bottom-6 -left-6 -z-10 h-36 w-36 opacity-50 [background-image:radial-gradient(#0B2A5B_1.5px,transparent_1.7px)] [background-size:14px_14px]" aria-hidden="true"></span></div>';
    if ($cards) {
        $h .= '<div class="relative z-[2] -mt-16 grid gap-4 px-3 sm:-mt-20 sm:grid-cols-2 sm:px-6" data-stagger="110">';
        foreach ($cards as $i => $cd) {
            $dark = $i === 0;
            $h .= '<div class="rounded-[22px] p-5 shadow-[0_24px_50px_-30px_rgba(7,26,59,.55)] ' . ($dark ? 'bg-brand-900 text-white' : 'border border-warm-2 bg-white') . '">'
                . '<span class="inline-flex h-10 w-10 items-center justify-center rounded-xl ' . ($dark ? 'bg-white/10 text-accent-300' : 'bg-accent-50 text-accent-600') . '">' . icon((string) ($cd['icon'] ?? 'target') ?: 'target', 'h-5 w-5') . '</span>'
                . '<h3 class="mt-3 font-display text-[16.5px] font-bold ' . ($dark ? 'text-white' : 'text-brand-900') . '">' . e($cd['title']) . '</h3>'
                . (trim((string) ($cd['text'] ?? '')) !== '' ? '<p class="mt-1.5 text-[13.5px] leading-relaxed ' . ($dark ? 'text-white/75' : 'text-slate-600') . '">' . e($cd['text']) . '</p>' : '') . '</div>';
        }
        $h .= '</div>';
    }
    $h .= '</div>';
    $h .= '<div class="lg:col-span-6">' . section_heading($s['title'], $s['subtitle'], (string) ($c['eyebrow'] ?? ''), 'left', '', ['class' => '!mb-6'])
        . (trim((string) ($c['text'] ?? '')) !== '' ? '<div class="space-y-4 text-[15.5px] leading-relaxed text-slate-600"' . reveal_attr() . '>' . cms_paragraphs((string) $c['text']) . '</div>' : '')
        . ($bullets ? '<ul class="mt-7 grid gap-3 sm:grid-cols-2" data-stagger="70">' . implode('', array_map(fn ($b) => '<li class="flex items-start gap-3 rounded-2xl border border-warm-2 bg-[#FAF8F5] px-4 py-3 text-[14.5px] font-semibold text-brand-900">'
            . '<span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-accent-600 text-white">' . icon('check', 'h-3 w-3') . '</span>' . e($b) . '</li>', $bullets)) . '</ul>' : '')
        . ($cta ? '<div class="mt-8">' . hm_button($cta, 'explore', ['icon' => 'arrow-right']) . '</div>' : '') . '</div>';
    $h .= '</div></section>';

    $h .= hm_render_research();
    $h .= hm_render_faculty();
    $h .= hm_render_recognitions();
    return $h;
}

/** Research & innovation showcase (navy band) — only verified CMS content; qualitative fallback without numbers. */
function hm_render_research(): string
{
    $r = hm_research();
    $h = '<section id="research" class="section relative isolate overflow-hidden bg-brand-950 text-white/80">'
        . '<div class="pointer-events-none absolute inset-0 -z-10 [background-image:radial-gradient(55%_70%_at_0%_0%,rgba(240,90,40,.22),transparent_60%),radial-gradient(50%_70%_at_100%_100%,rgba(31,74,148,.6),transparent_65%)]" aria-hidden="true"></div>'
        . '<div class="pattern-layer pattern-grid-light pattern-fade" aria-hidden="true"></div><div class="container-site">';
    $h .= '<div class="grid grid-cols-1 gap-10 lg:grid-cols-12 lg:items-end"><div class="lg:col-span-7">' . hm_kicker($r['highlight'] !== '' ? $r['title'] : 'Research & Innovation', true)
        . '<h2 class="mt-4 font-display text-[clamp(1.9rem,1.2rem+2.6vw,2.85rem)] font-extrabold leading-[1.1] tracking-display text-white">'
        . ($r['highlight'] !== '' ? e(rtrim($r['highlight'], '.')) . '<span class="text-accent-400">.</span>' : e($r['title'])) . '</h2>'
        . '<p class="mt-4 max-w-2xl text-base leading-relaxed text-white/75 sm:text-[17px]">' . e($r['intro'] !== '' ? $r['intro'] : 'Faculty and students at GIMT take on projects with industry and the community — from analytics to applied technology — and turn classroom ideas into prototypes.') . '</p></div>';
    $h .= '<div class="flex flex-wrap gap-3 lg:col-span-5 lg:justify-end">'
        . ($r['url'] ? button_link('Explore research', $r['url'], 'white', ['icon' => 'arrow-right']) : '')
        . (hm_url_ok('/faculty') ? button_link('Meet our faculty', '/faculty', 'outline-light', ['icon' => '']) : '') . '</div></div>';
    if ($r['areas']) {
        $h .= '<ul class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-stagger="80">';
        foreach ($r['areas'] as $a) {
            $h .= '<li class="group flex gap-4 rounded-[22px] border border-white/10 bg-white/[.04] p-5 transition duration-300 hover:-translate-y-0.5 hover:border-white/25 hover:bg-white/[.07] sm:block sm:p-6">'
                . '<span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-accent-600/20 text-accent-300 ring-1 ring-inset ring-accent-400/30">' . icon($a['icon'], 'h-5 w-5') . '</span>'
                . '<span class="block min-w-0"><h3 class="font-display text-[16.5px] font-bold text-white sm:mt-4">' . e($a['title']) . '</h3>'
                . ($a['text'] !== '' ? '<p class="mt-1.5 text-[14px] leading-relaxed text-white/65">' . e($a['text']) . '</p>' : '') . '</span></li>';
        }
        $h .= '</ul>';
    }
    if ($r['support'] || $r['posts']) {
        $h .= '<div class="mt-12 grid grid-cols-1 gap-6 lg:grid-cols-12">';
        if ($r['support']) {
            $h .= '<div class="rounded-[26px] border border-white/10 bg-white/[.04] p-6 sm:p-8 ' . ($r['posts'] ? 'lg:col-span-7' : 'lg:col-span-12') . '"><h3 class="font-display text-lg font-bold text-white">How we support innovation</h3><ol class="mt-5 space-y-5">';
            foreach ($r['support'] as $i => $sp) {
                $h .= '<li class="flex gap-4"><span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent-600 font-display text-sm font-extrabold text-white">' . ($i + 1) . '</span>'
                    . '<div><p class="font-semibold text-white">' . e($sp['title']) . '</p><p class="mt-1 line-clamp-3 text-[14px] leading-relaxed text-white/65">' . e($sp['text']) . '</p></div></li>';
            }
            $h .= '</ol></div>';
        }
        if ($r['posts']) {
            $h .= '<div class="grid gap-4 ' . ($r['support'] ? 'lg:col-span-5' : 'sm:grid-cols-2 lg:col-span-12') . '"><h3 class="sr-only">Latest from research</h3>';
            foreach ($r['posts'] as $p) {
                $tag = $p['url'] ? 'a href="' . e($p['url']) . '"' : 'div';
                $h .= '<' . $tag . ' class="group flex items-center gap-4 rounded-[22px] border border-white/10 bg-white/[.04] p-3 pr-5 transition hover:border-white/25 hover:bg-white/[.07]">'
                    . '<img src="' . e($p['image']) . '" alt="" class="h-20 w-24 shrink-0 rounded-2xl object-cover" loading="lazy" decoding="async" width="96" height="80">'
                    . '<span class="min-w-0"><span class="block text-[11.5px] font-bold uppercase tracking-[0.12em] text-accent-300">Research news · ' . e(date('j M Y', strtotime($p['date']))) . '</span>'
                    . '<span class="mt-1 block font-display text-[15px] font-bold leading-snug text-white">' . e($p['title']) . '</span></span></' . ($p['url'] ? 'a' : 'div') . '>';
            }
            $h .= '</div>';
        }
        $h .= '</div>';
    }
    return $h . '</div></section>';
}

/** Faculty highlights (public fields only). */
function hm_render_faculty(): string
{
    $faculty = hm_faculty(8);
    if (!$faculty) {
        return '';
    }
    $dir = hm_url_ok('/faculty');
    $tints = ['bg-brand-50 text-brand-900', 'bg-accent-50 text-accent-700', 'bg-[#E9F7F6] text-brand-900', 'bg-[#F3E9DC] text-brand-900'];
    $h = '<section id="faculty" class="section relative isolate overflow-hidden bg-[#F5F2EE]"><div class="container-site">'
        . section_heading('Learn From Mentors Who Care', 'Experienced academics and practitioners who teach, guide projects and open doors to industry.', 'Our Faculty', 'left',
            $dir ? button_link('Meet our faculty', '/faculty', 'brochure', ['icon' => 'arrow-right']) : '', ['highlight' => 'Mentors']);
    $h .= '<ul class="-mx-4 flex snap-x snap-mandatory scroll-px-4 gap-4 overflow-x-auto px-4 pb-3 scrollbar-none sm:mx-0 sm:grid sm:overflow-visible sm:px-0 sm:pb-0 sm:grid-cols-2 lg:grid-cols-4" data-stagger="70" aria-label="Faculty highlights">';
    foreach ($faculty as $i => $f) {
        $name = trim(($f['title'] ? $f['title'] . ' ' : '') . $f['first_name'] . ' ' . $f['last_name']);
        $url = $dir ? site_url('faculty') . '?q=' . rawurlencode(trim($f['first_name'] . ' ' . $f['last_name'])) . '#directory' : null;
        $avatar = $f['photo'] ? '<img src="' . e(site_image($f['photo'])) . '" alt="" class="h-16 w-16 rounded-2xl object-cover" loading="lazy" decoding="async" width="64" height="64">'
            : '<span class="inline-flex h-16 w-16 items-center justify-center rounded-2xl font-display text-lg font-extrabold ' . $tints[$i % 4] . '" aria-hidden="true">' . e(initials($f['first_name'] . ' ' . $f['last_name'])) . '</span>';
        $h .= '<li class="group relative flex w-[78%] shrink-0 snap-start flex-col rounded-[22px] border border-warm-2 bg-white p-5 transition duration-300 hover:-translate-y-0.5 sm:h-full sm:w-auto hover:shadow-[0_22px_44px_-30px_rgba(7,26,59,.5)]">'
            . '<div class="flex items-center gap-4">' . $avatar . (!empty($f['is_hod']) ? '<span class="rounded-full bg-sun px-2.5 py-1 text-[10.5px] font-extrabold uppercase tracking-wide text-brand-900">Head of Dept.</span>' : '') . '</div>'
            . '<h3 class="mt-4 font-display text-[16px] font-bold leading-snug text-brand-900">' . ($url ? '<a href="' . e($url) . '" class="stretched-link hover:text-accent-700 focus:outline-none">' . e($name) . '</a>' : e($name)) . '</h3>'
            . '<p class="mt-0.5 text-[13.5px] font-semibold text-accent-700">' . e((string) $f['designation']) . '</p>'
            . '<p class="mt-1 text-[13px] text-slate-500">' . e(site_department_label((string) $f['department'])) . '</p>'
            . (trim((string) $f['qualification']) !== '' ? '<p class="mt-auto pt-4"><span class="block border-t border-warm-2 pt-3 text-[12.5px] leading-relaxed text-slate-600"><span class="line-clamp-2">' . e((string) $f['qualification']) . '</span></span></p>' : '')
            . '</li>';
    }
    return $h . '</ul></div></section>';
}

/** Rankings & accreditation (verified, settings-driven; omitted when nothing is configured). */
function hm_render_recognitions(): string
{
    $list = hm_recognitions();
    if (!$list) {
        return '';
    }
    $about = hm_url_ok('/about') ? site_url('about') . '#approvals' : null;
    $h = '<section id="recognitions" class="section-tight relative bg-white"><div class="container-site">'
        . '<div class="grid grid-cols-1 items-center gap-10 rounded-[30px] border border-warm-2 bg-[#FAF8F5] p-6 sm:p-10 lg:grid-cols-12">'
        . '<div class="lg:col-span-4">' . hm_kicker('Recognitions') . '<h2 class="mt-3 font-display text-[1.75rem] font-extrabold leading-tight tracking-tight text-brand-900">Approved, affiliated &amp; trusted</h2>'
        . '<p class="mt-3 text-[15px] leading-relaxed text-slate-600">Our programs are offered with the approvals and affiliation listed here.</p>'
        . ($about ? '<a href="' . e($about) . '" class="link-arrow mt-5">Approvals &amp; affiliations' . icon('arrow-right', 'h-4 w-4') . '</a>' : '') . '</div>';
    $h .= '<ul class="grid gap-x-5 gap-y-9 pt-4 sm:grid-cols-2 lg:col-span-8 ' . (count($list) >= 3 ? 'xl:grid-cols-3' : '') . '" data-stagger="90">';
    foreach ($list as $a) {
        $h .= '<li class="relative flex h-full flex-col items-center rounded-[22px] border border-warm-2 bg-white px-5 pb-6 pt-9 text-center shadow-[0_18px_40px_-34px_rgba(7,26,59,.5)]">'
            . ribbon_label((string) ($a['label'] ?? $a['title']))
            . '<span class="inline-flex h-20 w-20 items-center justify-center rounded-full bg-[#FAF8F5] p-3.5 text-accent-600 ring-1 ring-warm-2">' . emblem((string) ($a['icon'] ?? 'seal'), 'h-full w-full') . '</span>'
            . '<p class="mt-4 font-display text-[16px] font-bold leading-snug text-brand-900">' . e($a['title']) . '</p>'
            . (($a['text'] ?? '') !== '' && $a['text'] !== $a['title'] ? '<p class="mt-1.5 text-[13.5px] leading-relaxed text-slate-600">' . e($a['text']) . '</p>' : '') . '</li>';
    }
    return $h . '</ul></div></div></section>';
}

/* ------------------------------------------------------------------------------------------------ 7. Admission journey + scholarships */

function hm_render_admission_process(array $s): string
{
    $c = $s['content'];
    $steps = array_values(array_filter((array) ($c['steps'] ?? []), fn ($st) => trim((string) ($st['title'] ?? '')) !== ''));
    $cta = hm_link($c['cta'] ?? null, ['label' => 'Start Your Application', 'url' => '/apply']);
    $session = hm_session();
    $h = '<section id="' . e(hm_anchor('admission_process', $c)) . '" class="section relative isolate overflow-hidden bg-[#F5F2EE]">'
        . '<div class="pointer-events-none absolute -left-32 bottom-0 -z-10 h-96 w-96 rounded-full bg-white/70 blur-3xl" aria-hidden="true"></div><div class="container-site">';
    $h .= section_heading($s['title'], $s['subtitle'], (string) ($c['eyebrow'] ?? ''), 'left', $cta ? button_link($cta['label'], $cta['url'], 'apply', ['shine' => true]) : '', ['highlight' => 'Journey']);
    if ($steps) {
        $h .= '<div class="rounded-[30px] border border-warm-2 bg-white p-6 shadow-[0_24px_60px_-44px_rgba(7,26,59,.5)] sm:p-10">'
            . progress_steps(array_map(fn ($st) => ['title' => (string) $st['title'], 'text' => (string) ($st['text'] ?? ''), 'icon' => (string) ($st['icon'] ?? '')], $steps), ['orientation' => 'auto'])
            . '</div>';
    }

    // ---- key dates · fees · documents
    $programs = hm_programs();
    $perCourse = fn ($p) => str_contains(strtolower((string) ($p['fee_label'] ?? '')), 'course');
    $fees = array_values(array_filter(array_map(fn ($p) => $perCourse($p) ? 0 : (float) $p['fee_per_year'], $programs)));
    $courseFees = array_values(array_filter(array_map(fn ($p) => $perCourse($p) ? (float) $p['fee_per_year'] : 0, $programs)));
    $deadline = site_admissions_deadline();
    $panel = 'flex h-full flex-col rounded-[24px] border border-warm-2 bg-white p-6';
    $title = fn (string $icon, string $t) => '<div class="flex items-center gap-3"><span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-accent-50 text-accent-600">' . icon($icon, 'h-5 w-5') . '</span><h3 class="font-display text-[16.5px] font-bold text-brand-900">' . e($t) . '</h3></div>';
    $h .= '<div class="mt-8 grid gap-4 md:grid-cols-3" data-stagger="80">';
    // key dates
    $h .= '<div class="' . $panel . '">' . $title('calendar-check', 'Key dates') . '<dl class="mt-4 space-y-3 text-[14px]">';
    if ($session) {
        $h .= '<div class="flex items-start justify-between gap-3"><dt class="text-slate-500">Admission session</dt><dd class="text-right font-semibold text-brand-900">' . e($session['name']) . '</dd></div>'
            . '<div class="flex items-start justify-between gap-3"><dt class="text-slate-500">Applications</dt><dd class="text-right font-semibold ' . ((int) $session['admissions_open'] === 1 ? 'text-emerald-700' : 'text-slate-700') . '">' . ((int) $session['admissions_open'] === 1 ? 'Open now' : 'Opening soon') . '</dd></div>'
            . '<div class="flex items-start justify-between gap-3"><dt class="text-slate-500">Academic year</dt><dd class="text-right font-semibold text-brand-900">' . e(date('M Y', strtotime((string) $session['start_date'])) . ' – ' . date('M Y', strtotime((string) $session['end_date']))) . '</dd></div>';
    }
    if ($deadline) {
        $h .= '<div class="flex items-start justify-between gap-3"><dt class="text-slate-500">Last date to apply</dt><dd class="text-right font-semibold text-accent-700">' . e(date('j M Y', $deadline)) . '</dd></div>';
    }
    $h .= '</dl>' . ($deadline ? '<p class="mt-4 rounded-xl bg-sun-light px-3 py-2 text-[13px] font-semibold text-brand-900">' . countdown($deadline, ['label' => 'Closes in']) . '</p>' : '')
        . (hm_url_ok('/admissions') ? '<a href="' . e(site_url('admissions')) . '#dates" class="link-arrow mt-auto pt-5 text-[14px]">All admission dates' . icon('arrow-right', 'h-4 w-4') . '</a>' : '') . '</div>';
    // fees
    $h .= '<div class="' . $panel . '">' . $title('wallet', 'Fee overview')
        . ($fees ? '<p class="mt-4 text-[14px] text-slate-500">Annual tuition fee</p><p class="mt-1 font-display text-[1.6rem] font-extrabold leading-tight tracking-tight text-brand-900">' . e(money(min($fees))) . ' <span class="text-base font-bold text-slate-400">–</span> ' . e(money(max($fees))) . '</p>'
            . ($courseFees ? '<p class="mt-2 text-[13.5px] text-slate-600">Certificate programs from <strong class="font-semibold text-brand-900">' . e(money(min($courseFees))) . '</strong> per course.</p>' : '')
            . '<p class="mt-2 text-[13.5px] leading-relaxed text-slate-600">Scholarships and semester-wise payment can lower what you pay upfront.</p>' : '<p class="mt-4 text-[14px] text-slate-600">Program-wise fees are published on the admissions page.</p>')
        . (hm_url_ok('/admissions') ? '<a href="' . e(site_url('admissions')) . '#fees" class="link-arrow mt-auto pt-5 text-[14px]">Program-wise fees' . icon('arrow-right', 'h-4 w-4') . '</a>' : '') . '</div>';
    // documents
    $docs = hm_try(fn () => pf_document_specs(), []);
    $h .= '<div class="' . $panel . '">' . $title('file-check', 'Documents checklist') . '<ul class="mt-4 space-y-2 text-[14px]">';
    foreach ($docs as $d) {
        if ($d['when'] === 'optional') {
            continue;
        }
        $h .= '<li class="flex items-start gap-2.5 text-slate-700">' . icon('circle-check', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') . '<span>' . e($d['label'])
            . ($d['when'] === 'pg' ? ' <span class="text-[12px] text-slate-500">(PG programs)</span>' : ($d['when'] === 'category' ? ' <span class="text-[12px] text-slate-500">(if applicable)</span>' : '')) . '</span></li>';
    }
    $h .= '</ul>' . (hm_url_ok('/admissions') ? '<a href="' . e(site_url('admissions')) . '#documents" class="link-arrow mt-auto pt-5 text-[14px]">Document guidelines' . icon('arrow-right', 'h-4 w-4') . '</a>' : '') . '</div>';
    $h .= '</div>';

    $h .= hm_render_scholarship_assistant();
    return $h . '</div></section>';
}

/** Scholarship Assistant island (+ SEO/no-JS list of every active scheme). */
function hm_render_scholarship_assistant(): string
{
    $schemes = hm_scholarships();
    if (!$schemes) {
        return '';
    }
    $programs = hm_programs();
    $levels = [];
    foreach (site_program_levels() as $k => $label) {
        $levels[] = ['value' => strtolower($k), 'label' => $label];
    }
    // percentage bands from the merit thresholds
    $cuts = [];
    foreach ($schemes as $sc) {
        $r = (array) $sc['rules'];
        if (isset($r['minPct'])) {
            $cuts[] = (float) $r['minPct'];
        }
    }
    $cuts = array_values(array_unique($cuts));
    rsort($cuts);
    $pct = [];
    foreach ($cuts as $i => $cut) {
        $upper = $i > 0 ? $cuts[$i - 1] : null;
        $pct[] = ['value' => (string) $cut, 'label' => $upper === null ? rtrim(rtrim(number_format($cut, 1), '0'), '.') . '% or above' : rtrim(rtrim(number_format($cut, 1), '0'), '.') . '% – ' . rtrim(rtrim(number_format($upper - 0.1, 1), '0'), '.') . '%'];
    }
    if ($pct) {
        $pct[] = ['value' => '0', 'label' => 'Below ' . rtrim(rtrim(number_format(min($cuts), 1), '0'), '.') . '%'];
    }
    // income bands from the income limits
    $limits = [];
    foreach ($schemes as $sc) {
        $r = (array) $sc['rules'];
        if (isset($r['incomeMax'])) {
            $limits[] = (int) $r['incomeMax'];
        }
    }
    $limits = array_values(array_unique($limits));
    sort($limits);
    $lakh = fn (int $v) => '₹' . rtrim(rtrim(number_format($v / 100000, 2), '0'), '.') . ' lakh';
    $income = [];
    foreach ($limits as $i => $lim) {
        $income[] = ['value' => 'lt' . $lim, 'label' => ($i === 0 ? 'Below ' : $lakh($limits[$i - 1]) . ' – ') . $lakh($lim), 'max' => $lim];
    }
    if ($income) {
        $income[] = ['value' => 'gte' . end($limits), 'label' => $lakh((int) end($limits)) . ' or more', 'max' => null];
        $income[] = ['value' => 'na', 'label' => 'Prefer not to say', 'max' => null];
    }
    $specialLabels = ['sports' => 'State / national level sportsperson', 'single_girl' => 'Single girl child', 'defence' => 'Ward of defence / CAPF personnel',
        'sibling' => 'My sibling studies at GIMT', 'staff_ward' => 'Child of a GIMT employee'];
    $specials = [];
    $cats = [];
    foreach ($schemes as $sc) {
        foreach (((array) $sc['rules'])['specials'] ?? [] as $sp) {
            $specials[$sp] = ['value' => $sp, 'label' => $specialLabels[$sp] ?? ucfirst(str_replace('_', ' ', $sp))];
        }
        foreach (((array) $sc['rules'])['categories'] ?? [] as $ct) {
            $cats[$ct] = $ct;
        }
    }
    $props = [
        'schemes' => $schemes,
        'levels' => $levels,
        'programs' => array_map(fn ($p) => ['value' => $p['slug'], 'label' => $p['name'] . (program_title_suffix($p) !== '' ? ' (' . program_title_suffix($p) . ')' : ''), 'level' => $p['level_key'],
            'fee' => (float) $p['fee_per_year'], 'per' => str_contains(strtolower((string) $p['fee_label']), 'course') ? 'course' : 'year'], $programs),
        'percentBands' => $pct, 'incomeBands' => $income, 'specials' => array_values($specials),
        'categories' => $cats ? array_values(array_merge(['General'], array_keys($cats))) : [],
        'currency' => (string) setting('currency_symbol', '₹'),
        'callbackUrl' => hm_counsellor_url(), 'applyUrl' => site_url('apply'),
        'detailsUrl' => hm_url_ok('/admissions') ? site_url('admissions') . '#scholarships' : null,
        'maxPercent' => site_max_scholarship(),
    ];
    // fallback: intro + every active scheme (SEO / no-JS), same two-column card as the island
    $fb = '<div class="grid grid-cols-1 overflow-hidden rounded-[30px] border border-warm-2 bg-white shadow-[0_24px_60px_-44px_rgba(7,26,59,.5)] lg:grid-cols-12">'
        . '<div class="relative isolate overflow-hidden bg-brand-900 p-7 text-white sm:p-9 lg:col-span-5">'
        . '<p class="eyebrow !text-accent-300">Scholarship Assistant</p><h3 class="mt-3 font-display text-[1.7rem] font-extrabold leading-tight text-white">Find the scholarships you may qualify for</h3>'
        . '<p class="mt-3 text-[15px] leading-relaxed text-white/75">Answer four quick questions — your course, last exam score, family income and any special category — to see matching schemes.</p>'
        . ($props['maxPercent'] ? '<p class="mt-6 font-display text-[2.6rem] font-extrabold leading-none text-white">' . (int) $props['maxPercent'] . '%</p><p class="text-[14px] text-white/70">highest merit scholarship on tuition</p>' : '')
        . '<p class="mt-6 text-[12.5px] leading-relaxed text-white/60">Indicative only — eligibility and the final concession are confirmed by the admissions office after document verification.</p></div>'
        . '<div class="min-h-[460px] p-6 sm:p-8 lg:col-span-7"><h4 class="font-display text-lg font-bold text-brand-900">Active scholarship schemes</h4><ul class="mt-4 grid gap-x-6 sm:grid-cols-2">';
    foreach ($schemes as $sc) {
        $fb .= '<li class="border-b border-warm-2 py-2.5" title="' . e($sc['criteria']) . '"><p class="text-[14px] font-semibold leading-snug text-brand-900">' . e($sc['name']) . '</p>'
            . '<p class="mt-0.5 text-[12.5px] font-bold text-accent-700">' . e($sc['benefit']) . ($sc['max'] ? '<span class="font-semibold text-slate-500"> · up to ' . e(money($sc['max'])) . '</span>' : '') . '</p></li>';
    }
    $fb .= '</ul></div></div>';
    return '<div id="scholarships" class="mt-14 scroll-mt-28">' . island('ScholarshipAssistant', $props, $fb, ['class' => 'block']) . '</div>';
}

/* ------------------------------------------------------------------------------------------------ 8. Campus life */

function hm_render_campus_life(array $s): string
{
    $c = $s['content'];
    $h = '<section id="' . e(hm_anchor('campus_life', $c)) . '" class="section relative isolate overflow-hidden bg-white"><div class="container-site">';
    $gallery = hm_url_ok('/campus') ? button_link('Explore campus life', '/campus', 'brochure', ['icon' => 'arrow-right']) : '';
    $h .= section_heading($s['title'], $s['subtitle'], (string) ($c['eyebrow'] ?? ''), 'left', $gallery, ['highlight' => 'Life']);

    // Student Life slider (draggable)
    $slides = hm_student_life(12);
    if ($slides) {
        $fb = '<div class="student-life"><div class="flex snap-x snap-mandatory gap-4 overflow-x-auto pb-2 scrollbar-none sm:gap-5" tabindex="0" role="region" aria-label="Student life photos">';
        foreach ($slides as $i => $sl) {
            $wide = $i % 3 === 0;
            $fb .= '<figure class="group relative shrink-0 snap-start overflow-hidden rounded-[26px] bg-warm-2 ' . ($wide ? 'w-[82%] sm:w-[440px]' : 'w-[64%] sm:w-[300px]') . '">'
                . '<img src="' . e($sl['src']) . '" alt="' . e($sl['alt']) . '" class="h-[340px] w-full object-cover transition duration-700 group-hover:scale-[1.04] sm:h-[400px]" loading="lazy" decoding="async" draggable="false">'
                . '<span class="pointer-events-none absolute inset-0 bg-gradient-to-t from-brand-950/80 via-brand-950/5 to-transparent" aria-hidden="true"></span>'
                . ($sl['tag'] !== '' ? '<span class="absolute left-4 top-4 rounded-full bg-white/90 px-3 py-1 text-[11.5px] font-bold text-brand-900">' . e($sl['tag']) . '</span>' : '')
                . ($sl['video'] ? '<span class="absolute right-4 top-4 inline-flex h-11 w-11 items-center justify-center rounded-full bg-accent-600 text-white shadow-lg">' . icon('play', 'h-4 w-4 translate-x-px') . '</span>' : '')
                . '<figcaption class="absolute inset-x-5 bottom-5 font-display text-[16px] font-bold leading-snug text-white">' . e($sl['caption']) . '</figcaption></figure>';
        }
        $fb .= '</div><div class="mt-5 flex h-11 items-center justify-between gap-4"><p class="font-display text-[15px] font-extrabold text-brand-900">01<span class="font-semibold text-slate-400"> / ' . sprintf('%02d', count($slides)) . '</span></p>'
            . '<p class="text-[13px] text-slate-500">Swipe to explore</p></div></div>';
        $h .= '<div class="mt-2">' . island('StudentLifeSlider', ['items' => $slides, 'ariaLabel' => 'Student life at GIMT', 'interval' => 5500], $fb, ['class' => 'block']) . '</div>';
    }

    // Campus Explorer (illustrated map)
    $map = hm_campus_map();
    $h .= hm_render_campus_explorer($map);

    // CMS overlay cards (Campus Life / Placements / News & Events)
    $cards = [];
    foreach ((array) ($c['cards'] ?? []) as $cd) {
        $title = trim((string) ($cd['title'] ?? ''));
        $url = trim((string) ($cd['url'] ?? ''));
        if ($title === '' || trim((string) ($cd['image'] ?? '')) === '') {
            continue;
        }
        if (!hm_url_ok($url)) {
            // page not built yet → point at the matching section of this page, else skip the card
            $url = preg_match('/placement/i', $url . ' ' . $title) && hm_has('placements') ? '#' . hm_anchor('placements', hm_sections()['placements']['content'])
                : (preg_match('/blog|news|event/i', $url . ' ' . $title) && hm_has('events_news') ? '#' . hm_anchor('events_news', hm_sections()['events_news']['content']) : '');
            if ($url === '') {
                continue;
            }
        }
        $cards[] = overlay_card($title, (string) ($cd['text'] ?? ''), $url, (string) $cd['image'], trim((string) ($cd['link_label'] ?? '')) ?: 'Explore');
    }
    if ($cards) {
        $h .= '<div class="mt-16 grid gap-5 md:grid-cols-' . min(3, count($cards)) . '" data-stagger="100">' . implode('', $cards) . '</div>';
    }
    return $h . '</div></section>';
}

/** Campus Explorer island + fallback (static map with numbered pins, first zone details, list of every zone). */
function hm_render_campus_explorer(array $map): string
{
    $zones = $map['zones'];
    if (!$zones) {
        return '';
    }
    $first = $zones[0];
    $pin = fn (array $z, bool $active) => '<span class="absolute z-[2] -translate-x-1/2 -translate-y-1/2" style="left:' . round($z['x'] / $map['width'] * 100, 2) . '%;top:' . round($z['y'] / $map['height'] * 100, 2) . '%">'
        . '<span class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-white font-display text-[13px] font-extrabold shadow-[0_8px_18px_-6px_rgba(7,26,59,.6)] ' . ($active ? 'bg-accent-600 text-white' : 'bg-brand-900 text-white') . '">' . (int) $z['n'] . '</span></span>';
    $fb = '<div class="campus-explorer grid grid-cols-1 gap-5 lg:grid-cols-12">';
    $fb .= '<div class="min-w-0 lg:col-span-8"><div class="relative overflow-hidden rounded-[28px] border border-warm-2 bg-[#F4EFE8]">'
        . '<img src="' . e($map['image']) . '" alt="' . e($map['alt']) . '" class="block h-auto w-full" width="' . (int) $map['width'] . '" height="' . (int) $map['height'] . '" loading="lazy" decoding="async">';
    foreach ($zones as $i => $z) {
        $fb .= $pin($z, $i === 0);
    }
    $fb .= '</div></div>';
    $fb .= '<div class="min-w-0 lg:col-span-4"><div class="flex h-full flex-col overflow-hidden rounded-[28px] border border-warm-2 bg-white">'
        . ($first['image'] ? '<img src="' . e($first['image']) . '" alt="' . e($first['image_alt']) . '" class="aspect-[16/9] w-full object-cover" loading="lazy" decoding="async">' : '')
        . '<div class="flex flex-1 flex-col p-6"><p class="text-[12px] font-bold uppercase tracking-[0.12em] text-accent-700">' . (int) $first['n'] . ' · ' . e($map['categories'][$first['category']] ?? '') . '</p>'
        . '<h4 class="mt-1 font-display text-xl font-extrabold text-brand-900">' . e($first['name']) . '</h4><p class="mt-2 text-[14.5px] leading-relaxed text-slate-600">' . e($first['description']) . '</p>'
        . '<ul class="mt-4 space-y-1.5">' . implode('', array_map(fn ($f) => '<li class="flex items-start gap-2 text-[14px] text-slate-700">' . icon('circle-check', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') . e($f) . '</li>', $first['facts'])) . '</ul></div></div></div>';
    $fb .= '<ol class="grid grid-cols-2 gap-2 lg:col-span-12 lg:grid-cols-5" aria-label="Campus places">';
    foreach ($zones as $z) {
        $fb .= '<li class="flex items-start gap-2.5 rounded-2xl border border-warm-2 bg-white p-2.5 sm:gap-3 sm:p-3"><span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-900 font-display text-[12px] font-extrabold text-white">' . (int) $z['n'] . '</span>'
            . '<span class="min-w-0"><span class="block text-[13px] font-bold leading-snug text-brand-900 sm:text-[14px]">' . e($z['name']) . '</span><span class="mt-0.5 hidden text-[12.5px] leading-snug text-slate-500 sm:block">' . e($z['summary']) . '</span></span></li>';
    }
    $fb .= '</ol></div>';
    $props = ['map' => ['src' => $map['image'], 'width' => $map['width'], 'height' => $map['height'], 'alt' => $map['alt']], 'categories' => $map['categories'],
        'zones' => array_map(fn ($z) => ['id' => $z['id'], 'n' => $z['n'], 'name' => $z['name'], 'category' => $z['category'], 'icon' => $z['icon'],
            'x' => round($z['x'] / $map['width'] * 100, 2), 'y' => round($z['y'] / $map['height'] * 100, 2), 'summary' => $z['summary'], 'description' => $z['description'],
            'facts' => $z['facts'], 'image' => $z['image'], 'imageAlt' => $z['image_alt'], 'link' => $z['link'] && hm_url_ok($z['link']['url']) ? $z['link'] : null], $zones)];
    return '<div id="campus-explorer" class="mt-16 scroll-mt-28"><div class="mb-7 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div>' . hm_kicker('Campus Explorer')
        . '<h3 class="mt-3 font-display text-2xl font-extrabold tracking-tight text-brand-900 sm:text-[1.9rem]">Take a walk around the campus</h3>'
        . '<p class="mt-2 max-w-2xl text-[15px] text-slate-600">Select a numbered place on the map to see what happens there.</p></div></div>'
        . island('CampusExplorer', $props, $fb, ['class' => 'block']) . '</div>';
}

/* ------------------------------------------------------------------------------------------------ 9. Placements */

function hm_render_placements(array $s): string
{
    $c = $s['content'];
    $stats = array_values(array_filter(array_map('hm_stat', (array) ($c['stats'] ?? []))));
    $offers = !empty($c['show_top_offers']) ? hm_offers(max(3, min(12, (int) ($c['offers_limit'] ?? 6)))) : [];
    $stories = hm_stories(3);
    $cta = hm_link($c['cta'] ?? null);
    $text = trim((string) ($c['text'] ?? ''));
    $img = trim((string) ($c['image'] ?? ''));
    if (!$stats && !$offers && !$stories && $text === '') {
        return '';
    }
    $h = '<section id="' . e(hm_anchor('placements', $c)) . '" class="section relative isolate overflow-hidden bg-brand-950 text-white/80">'
        . '<div class="pointer-events-none absolute inset-0 -z-10 [background-image:radial-gradient(60%_70%_at_100%_0%,rgba(240,90,40,.2),transparent_60%),radial-gradient(50%_60%_at_0%_100%,rgba(31,74,148,.55),transparent_65%)]" aria-hidden="true"></div>'
        . '<div class="pattern-layer pattern-dots-light pattern-fade" aria-hidden="true"></div><div class="container-site">';
    $h .= '<div class="grid grid-cols-1 gap-8 lg:grid-cols-12 lg:items-end"><div class="lg:col-span-8">' . (trim((string) ($c['eyebrow'] ?? '')) !== '' ? hm_kicker((string) $c['eyebrow'], true) : '')
        . '<h2 class="mt-4 font-display text-[clamp(1.9rem,1.2rem+2.6vw,2.85rem)] font-extrabold leading-[1.1] tracking-display text-white">' . e($s['title']) . '</h2>'
        . ($s['subtitle'] !== '' ? '<p class="mt-4 max-w-2xl text-base leading-relaxed text-white/75 sm:text-[17px]">' . e($s['subtitle']) . '</p>' : '') . '</div>'
        . ($cta ? '<div class="lg:col-span-4 lg:text-right">' . hm_button($cta, 'white', ['icon' => 'arrow-right']) . '</div>' : '') . '</div>';
    if ($stats) {
        $h .= '<ul class="mt-12 grid gap-y-8 border-y border-white/10 py-8 ' . (count($stats) >= 4 ? 'grid-cols-2 lg:grid-cols-4' : 'grid-cols-' . count($stats)) . ' lg:divide-x lg:divide-white/10" data-stagger="90">';
        foreach ($stats as $st) {
            $h .= '<li class="px-2 text-center lg:px-6">' . hm_count($st, 'block font-display text-[clamp(2.1rem,1.3rem+2.4vw,3.3rem)] font-extrabold leading-none tracking-tight text-white')
                . '<span class="mt-2 block text-[13.5px] font-medium text-white/65">' . e($st['label']) . '</span></li>';
        }
        $h .= '</ul>';
    }
    if ($offers || $text !== '' || $img !== '') {
        $h .= '<div class="mt-12 grid grid-cols-1 gap-8 lg:grid-cols-12">';
        if ($text !== '' || $img !== '') {
            $h .= '<div class="lg:col-span-4">' . ($img !== '' ? '<figure class="relative mx-auto max-w-md overflow-hidden rounded-[28px] bg-brand-900 lg:max-w-none" data-reveal="clip"><img src="' . e(site_image($img)) . '" alt="' . e(trim((string) ($c['image_alt'] ?? ''))) . '" class="aspect-[16/11] h-full w-full object-cover" loading="lazy" decoding="async" width="420" height="289">'
                . '<span class="absolute inset-0 bg-gradient-to-t from-brand-950/70 to-transparent" aria-hidden="true"></span></figure>' : '')
                . ($text !== '' ? '<div class="mt-6 space-y-3 text-[15.5px] leading-relaxed text-white/75">' . cms_paragraphs($text) . '</div>' : '') . '</div>';
        }
        if ($offers) {
            $h .= '<div class="' . ($text !== '' || $img !== '' ? 'lg:col-span-8' : 'lg:col-span-12') . '"><div class="flex items-center justify-between gap-3"><h3 class="font-display text-lg font-bold text-white">Recent offers</h3>'
                . '<span class="text-[12.5px] text-white/50">Accepted offers · first name only</span></div><ul class="mt-4 grid gap-3 sm:grid-cols-2" data-stagger="70">';
            foreach ($offers as $o) {
                $h .= '<li class="flex items-center gap-4 rounded-[20px] border border-white/10 bg-white/[.05] p-4 transition hover:border-white/25">'
                    . '<span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-white font-display text-[15px] font-extrabold text-brand-900" aria-hidden="true">' . e(mb_strtoupper(mb_substr(preg_replace('/[^\p{L}]/u', '', $o['company']), 0, 2))) . '</span>'
                    . '<span class="min-w-0 flex-1"><span class="block truncate font-semibold text-white">' . e($o['company']) . '</span><span class="block truncate text-[13px] text-white/60">' . e($o['name'] . ($o['program'] !== '' ? ' · ' . $o['program'] : '')) . '</span></span>'
                    . '<span class="shrink-0 text-right"><span class="block font-display text-[17px] font-extrabold text-accent-300">₹' . e(rtrim(rtrim(number_format($o['package'], 2), '0'), '.')) . '</span><span class="block text-[11px] font-semibold uppercase tracking-wider text-white/50">LPA</span></span></li>';
            }
            $h .= '</ul></div>';
        }
        $h .= '</div>';
    }
    if ($stories) {
        $alumni = hm_url_ok('/alumni');
        $h .= '<div class="mt-14"><div class="flex items-end justify-between gap-4"><h3 class="font-display text-xl font-bold text-white">Placed-student stories</h3>'
            . ($alumni ? '<a href="' . e(site_url('alumni')) . '" class="inline-flex items-center gap-1.5 text-[14px] font-semibold text-accent-300 hover:text-white">All alumni stories' . icon('arrow-right', 'h-4 w-4') . '</a>' : '') . '</div>'
            . '<ul class="mt-5 grid gap-5 md:grid-cols-3" data-stagger="90">';
        foreach ($stories as $st) {
            $h .= '<li class="group overflow-hidden rounded-[24px] border border-white/10 bg-white/[.04]"><div class="relative aspect-[16/10] overflow-hidden"><img src="' . e($st['image']) . '" alt="" class="h-full w-full object-cover transition duration-700 group-hover:scale-[1.04]" loading="lazy" decoding="async">'
                . '<span class="absolute inset-0 bg-gradient-to-t from-brand-950/80 to-transparent" aria-hidden="true"></span>'
                . '<p class="absolute inset-x-4 bottom-3 text-[12.5px] font-semibold text-white/85">' . e(trim($st['name'] . ($st['name'] !== '' && $st['role'] !== '' ? ' · ' : '') . $st['role'])) . '</p></div>'
                . '<div class="p-5"><h4 class="font-display text-[16px] font-bold leading-snug text-white">' . e($st['title']) . '</h4>'
                . ($st['excerpt'] !== '' ? '<p class="mt-2 text-[14px] leading-relaxed text-white/65">“' . e($st['excerpt']) . '”</p>' : '') . '</div></li>';
        }
        $h .= '</ul></div>';
    }
    return $h . '</div></section>';
}

/* ------------------------------------------------------------------------------------------------ 10. Recruiters */

function hm_render_recruiters(array $s): string
{
    $c = $s['content'];
    $limit = max(6, min(48, (int) ($c['limit'] ?? 24)));
    $items = [];
    if (($c['source'] ?? 'companies') === 'manual') {
        foreach ((array) ($c['items'] ?? []) as $it) {
            if (trim((string) ($it['name'] ?? '')) !== '') {
                $items[] = array_filter(['name' => (string) $it['name'], 'logo' => !empty($it['logo']) ? site_image($it['logo']) : null,
                    'url' => !empty($it['url']) && preg_match('#^https?://#', (string) $it['url']) ? (string) $it['url'] : null, 'industry' => (string) ($it['industry'] ?? '')]);
            }
        }
    } else {
        foreach (hm_try(fn () => db_all("SELECT name, logo, industry FROM companies WHERE status = 'active' AND is_recruiter = 1 ORDER BY sort_order, name LIMIT " . $limit), []) as $co) {
            $items[] = array_filter(['name' => (string) $co['name'], 'logo' => $co['logo'] ? site_image($co['logo']) : null, 'industry' => (string) ($co['industry'] ?? '')]);
        }
    }
    $items = array_slice($items, 0, $limit);
    if (!$items) {
        return '';
    }
    $cta = hm_link($c['cta'] ?? null);
    $h = '<section id="' . e(hm_anchor('recruiters', $c)) . '" class="section-tight relative bg-white"><div class="container-site">'
        . section_heading($s['title'], $s['subtitle'], (string) ($c['eyebrow'] ?? ''), 'left', $cta ? button_link($cta['label'], $cta['url'], 'callback', ['icon' => 'arrow-right']) : '')
        . logo_marquee($items, ['rows' => (int) ($c['rows'] ?? 2) === 1 ? 1 : (count($items) >= 8 ? 2 : 1), 'ariaLabel' => 'Companies that recruit GIMT students', 'grayscale' => true]);
    return $h . '</div></section>';
}

/* ------------------------------------------------------------------------------------------------ 11. Testimonials */

function hm_render_testimonials(array $s): string
{
    $c = $s['content'];
    $types = array_values(array_intersect(array_map('strval', (array) ($c['types'] ?? [])), array_keys(cms_testimonial_types())));
    $limit = max(3, min(20, (int) ($c['limit'] ?? 8)));
    $args = $types;
    $rows = hm_try(fn () => db_all("SELECT t.name, t.designation, t.company, t.photo, t.content, t.rating, t.batch, t.type, p.short_name AS program
        FROM testimonials t LEFT JOIN programs p ON p.id = t.program_id
        WHERE t.status = 'active'" . ($types ? ' AND t.type IN (' . implode(',', array_fill(0, count($types), '?')) . ')' : '') . '
        ORDER BY t.is_featured DESC, t.sort_order, t.id LIMIT ' . $limit, $args), []);
    if (!$rows) {
        return '';
    }
    $items = array_map(fn ($t) => array_filter([
        'name' => (string) $t['name'], 'quote' => hm_text($t['content'], 420), 'role' => (string) ($t['designation'] ?? ''), 'company' => (string) ($t['company'] ?? ''),
        'program' => trim((string) ($t['program'] ?? '') . ($t['batch'] ? ' · ' . $t['batch'] : '')), 'photo' => $t['photo'] ? site_image($t['photo']) : null,
        'rating' => $t['rating'] ? (int) $t['rating'] : null,
    ]), $rows);
    $cta = hm_link($c['cta'] ?? null);
    return '<section id="' . e(hm_anchor('testimonials', $c)) . '" class="section relative isolate overflow-hidden bg-[#F5F2EE]"><div class="container-site">'
        . section_heading($s['title'], $s['subtitle'], (string) ($c['eyebrow'] ?? ''), 'left', $cta ? button_link($cta['label'], $cta['url'], 'brochure', ['icon' => 'arrow-right']) : '', ['highlight' => 'Success'])
        . testimonial_slider(array_values($items), ['autoplay' => max(0, min(20000, (int) ($c['autoplay'] ?? 7000))), 'ariaLabel' => 'Student and alumni stories'])
        . '</div></section>';
}

/* ------------------------------------------------------------------------------------------------ 12. Events, news & calendar */

function hm_render_events_news(array $s): string
{
    $c = $s['content'];
    $news = !empty($c['show_news']) ? hm_news(max(1, min(6, (int) ($c['news_limit'] ?? 3)))) : [];
    $events = !empty($c['show_events']) ? hm_events(max(1, min(6, (int) ($c['events_limit'] ?? 3)))) : [];
    $notices = !empty($c['show_notices']) ? hm_notices(max(1, min(10, (int) ($c['notices_limit'] ?? 5)))) : [];
    $calendar = hm_calendar();
    if (!$news && !$events && !$notices && !$calendar) {
        return '';
    }
    $allEvents = hm_link($c['view_all_events'] ?? null);
    $allNews = hm_link($c['view_all_news'] ?? null);
    $h = '<section id="' . e(hm_anchor('events_news', $c)) . '" class="section relative isolate overflow-hidden bg-white"><div class="container-site">';
    $h .= section_heading($s['title'], $s['subtitle'], (string) ($c['eyebrow'] ?? ''), 'left', '', ['highlight' => 'News']);
    $h .= '<div class="grid grid-cols-1 gap-8 lg:grid-cols-12">';
    if ($news) {
        $h .= '<div class="' . ($events ? 'lg:col-span-7' : 'lg:col-span-12') . '"><div class="mb-5 flex items-center justify-between gap-3"><h3 class="font-display text-xl font-extrabold text-brand-900">Latest news</h3>'
            . ($allNews ? '<a href="' . e($allNews['href']) . '" class="link-arrow text-[14px]">' . e($allNews['label']) . icon('arrow-right', 'h-4 w-4') . '</a>' : '') . '</div>';
        foreach ($news as $i => $n) {
            $url = hm_url_ok('/blog/' . $n['slug']) ? site_url('blog/' . $n['slug']) : null;
            $title = $url ? '<a href="' . e($url) . '" class="stretched-link hover:text-accent-700 focus:outline-none">' . e($n['title']) . '</a>' : e($n['title']);
            $meta = '<span class="text-[12px] font-bold uppercase tracking-[0.12em] text-accent-700">' . e((string) ($n['category'] ?: ucfirst((string) $n['type']))) . '</span><span class="text-[12.5px] text-slate-500">' . e(date('j M Y', strtotime((string) $n['published_at']))) . '</span>';
            if ($i === 0) {
                $h .= '<article class="group relative grid overflow-hidden rounded-[26px] border border-warm-2 bg-white transition hover:shadow-[0_26px_50px_-34px_rgba(7,26,59,.5)] sm:grid-cols-2"' . reveal_attr() . '>'
                    . '<div class="relative aspect-[16/10] overflow-hidden bg-warm-2 sm:aspect-auto"><img src="' . e(site_image($n['thumbnail'] ?: null, 'assets/images/site/news-campus.jpg')) . '" alt="" class="h-full w-full object-cover transition duration-700 group-hover:scale-[1.04]" loading="lazy" decoding="async"></div>'
                    . '<div class="flex flex-col p-6"><div class="flex flex-wrap items-center gap-3">' . $meta . '</div><h4 class="mt-3 font-display text-[19px] font-extrabold leading-snug text-brand-900">' . $title . '</h4>'
                    . (trim((string) $n['excerpt']) !== '' ? '<p class="mt-2 line-clamp-3 text-[14.5px] leading-relaxed text-slate-600">' . e(hm_text($n['excerpt'], 200)) . '</p>' : '') . '</div></article>';
                $h .= count($news) > 1 ? '<ul class="mt-4 grid gap-3 sm:grid-cols-2" data-stagger="80">' : '';
            } else {
                $h .= '<li class="group relative flex gap-4 rounded-[22px] border border-warm-2 bg-white p-3 pr-4 transition hover:shadow-[0_20px_40px_-30px_rgba(7,26,59,.5)]">'
                    . '<img src="' . e(site_image($n['thumbnail'] ?: null, 'assets/images/site/news-campus.jpg')) . '" alt="" class="h-20 w-20 shrink-0 rounded-2xl object-cover" loading="lazy" decoding="async" width="80" height="80">'
                    . '<div class="min-w-0"><div class="flex flex-wrap items-center gap-2">' . $meta . '</div><h4 class="mt-1 line-clamp-2 font-display text-[15px] font-bold leading-snug text-brand-900">' . $title . '</h4></div></li>';
            }
        }
        $h .= count($news) > 1 ? '</ul>' : '';
        $h .= '</div>';
    }
    if ($events) {
        $h .= '<div class="' . ($news ? 'lg:col-span-5' : 'lg:col-span-12') . '"><div class="mb-5 flex items-center justify-between gap-3"><h3 class="font-display text-xl font-extrabold text-brand-900">Upcoming events</h3>'
            . ($allEvents ? '<a href="' . e($allEvents['href']) . '" class="link-arrow text-[14px]">' . e($allEvents['label']) . icon('arrow-right', 'h-4 w-4') . '</a>' : '') . '</div><ul class="space-y-3" data-stagger="80">';
        foreach ($events as $ev) {
            $ts = strtotime((string) $ev['start_datetime']);
            $end = $ev['end_datetime'] ? strtotime((string) $ev['end_datetime']) : null;
            $url = hm_url_ok('/events/' . $ev['slug']) ? site_url('events/' . $ev['slug']) : null;
            $regOpen = !empty($ev['registration_enabled']) && (!$ev['registration_deadline'] || strtotime((string) $ev['registration_deadline']) > time());
            $reg = null;
            if ($regOpen) {
                $reg = !empty($ev['registration_link']) && preg_match('#^https?://#', (string) $ev['registration_link']) ? (string) $ev['registration_link'] : ($url ? $url . '#register' : null);
            }
            $when = date('D, j M', $ts) . ($end && date('Y-m-d', $end) !== date('Y-m-d', $ts) ? ' – ' . date('j M', $end) : '') . ' · ' . date('g:i A', $ts);
            $h .= '<li class="group relative flex items-start gap-4 rounded-[22px] border border-warm-2 bg-[#FAF8F5] p-4 transition hover:border-[#E2DAD0] hover:bg-white">'
                . '<span class="flex h-16 w-16 shrink-0 flex-col items-center justify-center rounded-2xl bg-brand-900 text-white"><span class="font-display text-[22px] font-extrabold leading-none">' . date('d', $ts) . '</span><span class="mt-1 text-[11px] font-bold uppercase tracking-wider text-accent-300">' . date('M', $ts) . '</span></span>'
                . '<div class="min-w-0 flex-1"><p class="text-[12px] font-bold uppercase tracking-[0.12em] text-accent-700">' . e(ucfirst((string) $ev['category'])) . '</p>'
                . '<h4 class="mt-0.5 font-display text-[15.5px] font-bold leading-snug text-brand-900">' . ($url ? '<a href="' . e($url) . '" class="hover:text-accent-700">' . e($ev['title']) . '</a>' : e($ev['title'])) . '</h4>'
                . '<p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12.5px] text-slate-500"><span class="inline-flex items-center gap-1">' . icon('clock', 'h-3.5 w-3.5') . e($when) . '</span>'
                . ((string) $ev['venue'] !== '' ? '<span class="inline-flex items-center gap-1">' . icon('map-pin', 'h-3.5 w-3.5') . e((string) $ev['venue']) . '</span>' : '') . '</p>'
                . ($reg ? '<a href="' . e($reg) . '" class="btn btn-callback btn-sm mt-3"' . (str_starts_with($reg, 'http') && !str_starts_with($reg, absolute_url('')) ? ' target="_blank" rel="noopener"' : '') . '>Register<span class="sr-only"> for ' . e($ev['title']) . '</span>' . icon('arrow-right', 'h-3.5 w-3.5') . '</a>' : '')
                . '</div></li>';
        }
        $h .= '</ul></div>';
    }
    $h .= '</div>';
    if ($calendar) {
        $h .= hm_render_calendar($calendar);
    }
    if ($notices) {
        $board = hm_url_ok('/notices');
        $h .= '<div class="mt-10 rounded-[26px] border border-warm-2 bg-[#FAF8F5] p-5 sm:p-7"><div class="flex items-center justify-between gap-3"><h3 class="inline-flex items-center gap-2.5 font-display text-lg font-extrabold text-brand-900">'
            . '<span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-accent-600 text-white">' . icon('megaphone', 'h-4 w-4') . '</span>Notice board</h3>'
            . ($board ? '<a href="' . e(site_url('notices')) . '" class="link-arrow text-[14px]">All notices' . icon('arrow-right', 'h-4 w-4') . '</a>' : '') . '</div><ul class="mt-4 divide-y divide-warm-2">';
        foreach ($notices as $nt) {
            $url = hm_url_ok('/notices/' . $nt['slug']) ? site_url('notices/' . $nt['slug']) : null;
            $h .= '<li class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:gap-4"><span class="w-24 shrink-0 text-[12.5px] font-semibold text-slate-500">' . ($nt['publish_date'] ? e(date('j M Y', strtotime((string) $nt['publish_date']))) : '') . '</span>'
                . '<span class="min-w-0 flex-1 text-[14.5px] font-semibold text-brand-900">' . (!empty($nt['is_pinned']) ? '<span class="mr-2 inline-flex rounded-full bg-sun px-2 py-0.5 align-middle text-[10.5px] font-extrabold uppercase text-brand-900">Important</span>' : '')
                . ($url ? '<a href="' . e($url) . '" class="hover:text-accent-700">' . e($nt['title']) . '</a>' : e($nt['title'])) . '</span>'
                . '<span class="shrink-0 text-[12px] font-bold uppercase tracking-[0.1em] text-accent-700">' . e(ucfirst((string) $nt['category'])) . '</span></li>';
        }
        $h .= '</ul></div>';
    }
    return $h . '</div></section>';
}

/** Academic Calendar island + server-rendered month grid / agenda fallback. */
function hm_render_calendar(array $items): string
{
    $session = hm_current_session();
    $today = date('Y-m-d');
    $month = date('Y-m');
    $first = strtotime($month . '-01');
    $types = ['academic' => 'Academic', 'exam' => 'Exams', 'holiday' => 'Holidays', 'event' => 'Events', 'admission' => 'Admissions'];
    $dot = ['academic' => 'bg-brand-600', 'exam' => 'bg-violet-500', 'holiday' => 'bg-rose-500', 'event' => 'bg-emerald-500', 'admission' => 'bg-accent-500'];
    $inMonth = array_values(array_filter($items, fn ($it) => $it['start'] <= date('Y-m-t', $first) && ($it['end'] ?: $it['start']) >= date('Y-m-01', $first)));
    $counts = ['all' => count($items)];
    foreach ($types as $k => $_) {
        $counts[$k] = count(array_filter($items, fn ($it) => $it['type'] === $k));
    }
    // fallback: month grid + agenda of this month (same layout as the island)
    $fb = '<div class="academic-calendar overflow-hidden rounded-[30px] border border-warm-2 bg-white shadow-[0_24px_60px_-46px_rgba(7,26,59,.5)]">';
    $fb .= '<div class="flex flex-col gap-4 border-b border-warm-2 p-5 sm:p-6 lg:flex-row lg:items-center lg:justify-between"><div class="flex items-center gap-3">'
        . '<span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-brand-900 text-white">' . icon('calendar-days', 'h-5 w-5') . '</span>'
        . '<div><h3 class="font-display text-xl font-extrabold text-brand-900">Academic calendar</h3><p class="text-[13px] text-slate-500">Session ' . e($session['name'] ?? '') . '</p></div></div>'
        . '<div class="pill-row scrollbar-none" role="group" aria-label="Filter calendar"><span class="chip chip-active !px-3.5 !py-1.5 !text-[13px]">All<span class="chip-count is-active">' . $counts['all'] . '</span></span>';
    foreach ($types as $k => $label) {
        if ($counts[$k]) {
            $fb .= '<span class="chip !px-3.5 !py-1.5 !text-[13px]"><span class="h-2 w-2 rounded-full ' . $dot[$k] . '" aria-hidden="true"></span>' . e($label) . '<span class="chip-count">' . $counts[$k] . '</span></span>';
        }
    }
    $fb .= '</div></div><div class="grid grid-cols-1 lg:grid-cols-12"><div class="border-b border-warm-2 p-5 sm:p-6 lg:col-span-7 lg:border-b-0 lg:border-r">'
        . '<div class="flex items-center justify-between"><p class="font-display text-lg font-extrabold text-brand-900">' . e(date('F Y', $first)) . '</p></div>'
        . '<div class="mt-4 grid grid-cols-7 text-center text-[11.5px] font-bold uppercase tracking-wider text-slate-400">';
    foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d) {
        $fb .= '<span class="py-1">' . $d . '</span>';
    }
    $fb .= '</div><div class="mt-1 grid grid-cols-7 gap-1">';
    $lead = (int) date('N', $first) - 1;
    for ($i = 0; $i < $lead; $i++) {
        $fb .= '<span class="h-11 sm:h-14"></span>';
    }
    $days = (int) date('t', $first);
    for ($d = 1; $d <= $days; $d++) {
        $date = date('Y-m-', $first) . sprintf('%02d', $d);
        $on = array_filter($inMonth, fn ($it) => $it['start'] <= $date && ($it['end'] ?: $it['start']) >= $date);
        $fb .= '<span class="relative flex h-11 flex-col items-center justify-center rounded-xl text-[14px] font-semibold sm:h-14 ' . ($date === $today ? 'bg-accent-600 text-white' : ($on ? 'bg-[#FAF8F5] text-brand-900' : 'text-slate-600')) . '">' . $d;
        if ($on) {
            $fb .= '<span class="absolute bottom-1.5 flex gap-0.5">';
            foreach (array_slice(array_unique(array_column($on, 'type')), 0, 3) as $t) {
                $fb .= '<span class="h-1.5 w-1.5 rounded-full ' . ($date === $today ? 'bg-white' : $dot[$t]) . '"></span>';
            }
            $fb .= '</span>';
        }
        $fb .= '</span>';
    }
    $fb .= '</div></div><div class="p-5 sm:p-6 lg:col-span-5"><p class="font-display text-[15px] font-extrabold text-brand-900">This month</p><ul class="mt-3 space-y-2.5">';
    foreach (array_slice($inMonth, 0, 7) as $it) {
        $fb .= '<li class="flex items-start gap-3 rounded-2xl border border-warm-2 p-3"><span class="w-11 shrink-0 text-center"><span class="block font-display text-lg font-extrabold leading-none text-brand-900">' . date('d', strtotime($it['start'])) . '</span>'
            . '<span class="text-[10.5px] font-bold uppercase text-slate-400">' . date('M', strtotime($it['start'])) . '</span></span><span class="min-w-0"><span class="block text-[14px] font-semibold leading-snug text-brand-900">' . e($it['title']) . '</span>'
            . '<span class="mt-0.5 inline-flex items-center gap-1.5 text-[12px] text-slate-500"><span class="h-1.5 w-1.5 rounded-full ' . $dot[$it['type']] . '"></span>' . e($types[$it['type']]) . ($it['end'] ? ' · until ' . e(date('j M', strtotime($it['end']))) : '') . '</span></span></li>';
    }
    if (!$inMonth) {
        $fb .= '<li class="rounded-2xl border border-dashed border-warm-line p-4 text-center text-[14px] text-slate-500">Nothing scheduled this month.</li>';
    }
    $fb .= '</ul></div></div></div>';
    $props = ['session' => ['name' => (string) ($session['name'] ?? ''), 'start' => (string) ($session['start_date'] ?? ''), 'end' => (string) ($session['end_date'] ?? '')],
        'today' => $today, 'items' => $items, 'types' => $types,
        'fullUrl' => hm_url_ok('/academics') ? site_url('academics') . '#calendar' : null];
    return '<div id="calendar" class="mt-14 scroll-mt-28">' . island('AcademicCalendar', $props, $fb, ['class' => 'block']) . '</div>';
}

/* ------------------------------------------------------------------------------------------------ 13. FAQs */

function hm_render_faqs(array $s): string
{
    $c = $s['content'];
    $faqs = hm_faqs($c);
    if (!$faqs) {
        return '';
    }
    $viewAll = hm_link($c['view_all'] ?? null);
    $contact = hm_url_ok('/contact') ? site_url('contact') . '#message' : null;
    $h = '<section id="' . e(hm_anchor('faqs', $c)) . '" class="section relative isolate overflow-hidden bg-[#FAF8F5]"><div class="container-site">'
        . section_heading($s['title'], $s['subtitle'], (string) ($c['eyebrow'] ?? ''), 'left', $viewAll ? button_link($viewAll['label'], $viewAll['url'], 'brochure', ['icon' => 'arrow-right']) : '', ['highlight' => 'Questions'])
        . faq_accordion($faqs, ['columns' => (int) ($c['columns'] ?? 2) === 1 ? 1 : 2, 'search' => count($faqs) > 4, 'searchPlaceholder' => 'Search questions…', 'defaultOpen' => 0, 'mode' => 'single']);
    $h .= '<div class="mt-10 flex flex-col items-start gap-4 rounded-[24px] border border-warm-2 bg-white p-6 sm:flex-row sm:items-center sm:justify-between">'
        . '<div class="flex items-center gap-4"><span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-accent-50 text-accent-600">' . icon('message-circle', 'h-6 w-6') . '</span>'
        . '<div><p class="font-display text-[17px] font-bold text-brand-900">Still have a question?</p><p class="text-[14px] text-slate-600">Our admission counsellors reply within one working day.</p></div></div>'
        . '<div class="flex flex-wrap gap-3"><a href="' . e(hm_counsellor_url()) . '" class="btn btn-callback">' . icon('phone', 'h-4 w-4') . 'Request a Callback</a>'
        . ($contact ? '<a href="' . e($contact) . '" class="btn btn-secondary">Write to us</a>' : '') . '</div></div>';
    return $h . '</div></section>';
}

/* ------------------------------------------------------------------------------------------------ 14. CTA + Smart Enquiry */

function hm_render_cta(array $s): string
{
    $c = $s['content'];
    $primary = hm_link($c['primary'] ?? null, ['label' => 'Apply for Admission', 'url' => '/apply']);
    $secondary = hm_link($c['secondary'] ?? null);
    $form = !empty($c['show_enquiry_form']);
    $theme = in_array($c['theme'] ?? 'dark', ['dark', 'orange', 'light'], true) ? $c['theme'] : 'dark';
    $dark = $theme !== 'light';
    $bg = ['dark' => 'bg-brand-950 text-white/80', 'orange' => 'bg-accent-700 text-white/85', 'light' => 'bg-[#F5F2EE] text-slate-600'][$theme];
    $phone = (string) setting('phone', '');
    $email = (string) (setting('admission_email') ?: setting('email', ''));
    $wa = site_whatsapp_url();
    $quick = [];
    if ($phone !== '') {
        $quick[] = ['phone', 'Call admissions', $phone, site_tel($phone), false];
    }
    if ($wa) {
        $quick[] = ['whatsapp-brand', 'WhatsApp', 'Chat with a counsellor', $wa, true];
    }
    if ($email !== '') {
        $quick[] = ['mail', 'Email', $email, 'mailto:' . $email, false];
    }
    if (hm_url_ok('/contact')) {
        $quick[] = ['map-pin', 'Visit campus', (string) setting('city', 'Greater Noida'), site_url('contact') . '#locations', false];
    }
    $h = '<section id="' . e(hm_anchor('cta', $c)) . '" class="relative isolate overflow-hidden py-16 sm:py-20 lg:py-24 ' . $bg . '">';
    if ($dark) {
        $h .= '<div class="pointer-events-none absolute inset-0 -z-10 [background-image:radial-gradient(55%_80%_at_0%_0%,rgba(240,90,40,.28),transparent_60%),radial-gradient(60%_70%_at_100%_100%,rgba(31,74,148,.55),transparent_65%)]" aria-hidden="true"></div>'
            . '<div class="pattern-layer pattern-grid-light pattern-fade" aria-hidden="true"></div>';
    }
    if (!empty($c['image']) && !$form) {
        $h .= '<img src="' . e(site_image((string) $c['image'])) . '" alt="" class="absolute inset-y-0 right-0 -z-10 hidden h-full w-[46%] object-cover opacity-60 [mask-image:linear-gradient(90deg,transparent,#000_45%)] [-webkit-mask-image:linear-gradient(90deg,transparent,#000_45%)] md:block" loading="lazy" decoding="async">';
    }
    $h .= '<div class="container-site grid grid-cols-1 items-center gap-12 lg:grid-cols-12 lg:gap-14">';
    $h .= '<div class="' . ($form ? 'lg:col-span-6' : 'lg:col-span-7') . '">' . (trim((string) ($c['eyebrow'] ?? '')) !== '' ? hm_kicker((string) $c['eyebrow'], $dark) : '')
        . '<h2 class="mt-4 font-display text-[clamp(2rem,1.25rem+2.8vw,3.1rem)] font-extrabold leading-[1.08] tracking-display ' . ($dark ? 'text-white' : 'text-brand-900') . '">' . e($s['title']) . '</h2>'
        . ($s['subtitle'] !== '' ? '<p class="mt-4 max-w-xl text-base leading-relaxed sm:text-[17px] ' . ($dark ? 'text-white/75' : 'text-slate-600') . '">' . e($s['subtitle']) . '</p>' : '')
        . '<div class="mt-8 flex flex-wrap gap-3">' . hm_button($primary, $theme === 'orange' ? 'white' : 'apply', ['size' => 'lg', 'shine' => true, 'magnetic' => true])
        . ($secondary ? button_link($secondary['label'], $secondary['url'], $dark ? 'outline-light' : 'brochure', ['size' => 'lg', 'icon' => '', 'icon_left' => preg_match('/brochure|download/i', $secondary['label']) ? 'download' : null]) : '') . '</div>';
    if ($quick) {
        $h .= '<ul class="mt-10 grid gap-3 sm:grid-cols-2" aria-label="Contact admissions">';
        foreach ($quick as [$ic, $label, $value, $href, $blank]) {
            $h .= '<li><a href="' . e($href) . '"' . ($blank ? ' target="_blank" rel="noopener"' : '') . ' class="group flex items-center gap-3 rounded-2xl p-3 transition ' . ($dark ? 'bg-white/[.06] ring-1 ring-inset ring-white/10 hover:bg-white/[.1]' : 'bg-white ring-1 ring-inset ring-warm-2 hover:shadow-soft') . '">'
                . '<span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ' . ($dark ? 'bg-white/10 text-accent-300' : 'bg-accent-50 text-accent-600') . '">' . icon($ic, 'h-[18px] w-[18px]') . '</span>'
                . '<span class="min-w-0"><span class="block text-[12px] font-semibold uppercase tracking-[0.1em] ' . ($dark ? 'text-white/55' : 'text-slate-500') . '">' . e($label) . ($blank ? '<span class="sr-only"> (opens in a new tab)</span>' : '') . '</span>'
                . '<span class="block truncate text-[14.5px] font-semibold ' . ($dark ? 'text-white' : 'text-brand-900') . '">' . e($value) . '</span></span></a></li>';
        }
        $h .= '</ul>';
    }
    $h .= '</div>';
    if ($form) {
        $h .= '<div class="lg:col-span-6">' . hm_render_smart_enquiry() . '</div>';
    } elseif (!empty($c['image'])) {
        $h .= '<div class="lg:col-span-5 md:hidden"><img src="' . e(site_image((string) $c['image'])) . '" alt="" class="aspect-[16/10] w-full rounded-[26px] object-cover" loading="lazy" decoding="async"></div>';
    }
    return $h . '</div></section>';
}

/** Smart Enquiry island (3-step lead form → POST /api/public/enquiry) + single-step no-JS fallback form. */
function hm_render_smart_enquiry(): string
{
    $programs = hm_programs();
    $levels = [];
    foreach (site_program_levels() as $k => $label) {
        $levels[] = ['value' => strtolower($k), 'label' => $label];
    }
    $privacy = hm_url_ok('/privacy-policy') ? site_url('privacy-policy') : null;
    $consent = 'I agree to be contacted by GIMT about admissions by phone, SMS, WhatsApp or email.';
    $times = [['value' => 'morning', 'label' => 'Morning', 'hint' => '9 AM – 12 PM'], ['value' => 'afternoon', 'label' => 'Afternoon', 'hint' => '12 – 3 PM'],
        ['value' => 'evening', 'label' => 'Evening', 'hint' => '3 – 6 PM'], ['value' => 'anytime', 'label' => 'Anytime', 'hint' => 'Office hours']];
    $props = [
        'endpoint' => base_url('api/public/enquiry'),
        'levels' => $levels,
        'programs' => array_map(fn ($p) => ['value' => $p['slug'], 'label' => $p['name'] . (program_title_suffix($p) !== '' ? ' (' . program_title_suffix($p) . ')' : ''), 'level' => $p['level_key']], $programs),
        'times' => $times,
        'officeHours' => (string) setting('office_hours', ''),
        'consent' => $consent, 'privacyUrl' => $privacy,
        'hidden' => ['source' => 'home', 'form' => 'smart-enquiry'] + site_utm_params(),
        'applyUrl' => site_url('apply'), 'brochureUrl' => hm_url_ok('/programs') ? site_url('programs') . '?brochure=1' : null,
        'phone' => (string) setting('phone', ''),
    ];
    $field = 'h-12 w-full rounded-xl border border-warm-2 bg-white px-4 text-[14.5px] text-brand-900 placeholder:text-slate-400 focus:border-brand-400 focus:outline-none focus:ring-4 focus:ring-accent-500/10';
    $opts = '<option value="">Not sure yet</option>';
    foreach ($programs as $p) {
        $opts .= '<option value="' . e($p['slug']) . '">' . e($p['name']) . '</option>';
    }
    $fb = '<div class="smart-enquiry rounded-[30px] bg-white p-6 text-slate-600 shadow-[0_40px_80px_-40px_rgba(0,0,0,.55)] sm:p-8">'
        . '<p class="text-[12px] font-bold uppercase tracking-[0.14em] text-accent-700">Free counselling</p><h3 class="mt-1 font-display text-[1.45rem] font-extrabold leading-tight text-brand-900">Get a call from an admission counsellor</h3>'
        . '<form action="' . e($props['endpoint']) . '" method="post" class="mt-6 grid gap-3.5 sm:grid-cols-2">' . csrf_field()
        . '<input type="hidden" name="source" value="home"><input type="hidden" name="form" value="smart-enquiry"><input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">'
        . '<label class="sm:col-span-2"><span class="form-label">Program of interest</span><select name="program" class="' . $field . '">' . $opts . '</select></label>'
        . '<label><span class="form-label">Full name <span class="form-required">*</span></span><input name="name" required maxlength="150" autocomplete="name" class="' . $field . '"></label>'
        . '<label><span class="form-label">Mobile number <span class="form-required">*</span></span><input name="phone" type="tel" required inputmode="tel" autocomplete="tel" placeholder="+91" class="' . $field . '"></label>'
        . '<label><span class="form-label">Email</span><input name="email" type="email" autocomplete="email" class="' . $field . '"></label>'
        . '<label><span class="form-label">City</span><input name="city" maxlength="80" autocomplete="address-level2" class="' . $field . '"></label>'
        . '<label class="flex items-start gap-2.5 text-[13px] sm:col-span-2"><input type="checkbox" name="consent" value="1" required class="form-check mt-0.5">' . e($consent) . '</label>'
        . '<button type="submit" class="btn btn-apply sm:col-span-2">Request a Callback' . icon('arrow-right', 'h-4 w-4') . '</button></form></div>';
    return island('SmartEnquiry', $props, $fb, ['class' => 'block']);
}
