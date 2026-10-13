<?php
/**
 * Placement + Alumni public pages (unit "public_career") — data access and render helpers for placement.php and
 * alumni.php, plus the alumni self-registration handler behind POST /api/public/alumni-register (api/routes/public.php).
 *
 * Rules kept here:
 *   - every query is read-only (except pc_submit_alumni_registration), prepared, LIMITed and wrapped in pc_try() so the
 *     pages still render with friendly empty states when a table is empty or missing;
 *   - numbers are computed from the placement / alumni tables — nothing is invented; a stat without data is hidden;
 *   - no student / alumni PII beyond what the brief allows: offers show first name + last initial, program, company and
 *     CTC only; alumni stories are the ones the alumni office published to the website; alumni counts are aggregates of
 *     verified profiles; job posters' e-mail addresses are never printed.
 * Placement % follows the admin dashboard definition (app/services/placement.php → placement_dashboard()): students with
 * a valid offer (offered / accepted / joined) ÷ students eligible for at least one campus drive of the season (+ placed
 * students). That computation is heavy, so its public subset is cached per season in storage/cache (fingerprinted on
 * the offer / drive / application tables, 6 h TTL).
 *
 * Tailwind classes used dynamically below (kept literal for the scanner):
 *   badge-blue badge-green badge-amber badge-purple badge-cyan badge-orange badge-slate badge-navy
 *   tint-navy tint-orange tint-sky tint-aqua tint-lavender tint-blush tint-pistachio tint-sun
 */

/* ============================================================================ Basics */

/** Run a DB read and fall back to $default (logged) when it fails. */
function pc_try(callable $fn, $default = null)
{
    try {
        return $fn();
    } catch (Throwable $e) {
        error_log('[public_career] ' . $e->getMessage());
        return $default;
    }
}

/** SQL list of offer statuses that count as a valid offer (same as the admin placement dashboard). */
function pc_valid_offers_sql(): string
{
    return "('offered', 'accepted', 'joined')";
}

/** Published CMS route page (placement / alumni) — hero copy, banner and intro text — or null. */
function pc_cms_page(string $slug): ?array
{
    static $cache = [];
    if (!array_key_exists($slug, $cache)) {
        $cache[$slug] = pc_try(fn () => db_row("SELECT id, slug, title, subtitle, excerpt, banner_image, content FROM pages
                                                 WHERE slug = ? AND status = 'published' AND (published_at IS NULL OR published_at <= NOW())", [$slug])) ?: null;
    }
    return $cache[$slug];
}

/** BreadcrumbList JSON-LD: [['Home', ''], ['Placements', 'placement']] (paths relative to the site root). */
function pc_breadcrumb_ld(array $crumbs): array
{
    $items = [];
    foreach (array_values($crumbs) as $i => [$name, $path]) {
        $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => absolute_url(ltrim((string) $path, '/'))];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

/** 8.5 → "8.5", 9.00 → "9", 25.75 → "25.75" */
function pc_num($v, int $decimals = 2): string
{
    $s = number_format((float) $v, $decimals, '.', ',');
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

/** "₹25.75 LPA" */
function pc_lpa($v): string
{
    return '₹' . pc_num($v) . ' LPA';
}

/** Number of decimals needed to show a value without trailing zeros (for data-count). */
function pc_decimals($v): int
{
    $s = pc_num($v);
    return str_contains($s, '.') ? strlen(substr(strrchr($s, '.'), 1)) : 0;
}

/** First name + last initial ("Kabir S.") — the only student identity shown publicly. */
function pc_short_name(?string $first, ?string $last): string
{
    $first = trim((string) $first);
    $last = trim((string) $last);
    return trim($first . ($last !== '' ? ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.' : ''));
}

/** "Department of Management Studies" → "Management Studies" */
function pc_dept_label(string $name): string
{
    return trim((string) preg_replace('/^Department of\s+/i', '', $name));
}

/**
 * Big stat number with the vanilla count-up (server renders the final value — readable without JS).
 * $o: prefix, suffix, unit, decimals, note, dark (bool), size (font-size classes of the number; literal Tailwind classes)
 */
function pc_stat($value, string $label, array $o = []): string
{
    $dec = (int) ($o['decimals'] ?? pc_decimals($value));
    $shown = ($o['prefix'] ?? '') . number_format((float) $value, $dec) . ($o['suffix'] ?? '');
    $dark = !empty($o['dark']);
    $unit = (string) ($o['unit'] ?? '');
    $size = (string) ($o['size'] ?? 'text-[clamp(1.75rem,1.3rem+1.4vw,2.5rem)]');
    return '<div class="min-w-0">'
        . '<span class="block whitespace-nowrap font-display ' . $size . ' font-extrabold leading-none tracking-tight ' . ($dark ? 'text-white' : 'text-brand-900') . '">'
        . '<span data-count="' . e((string) (float) $value) . '"' . ($dec ? ' data-decimals="' . $dec . '"' : '')
        . (isset($o['prefix']) ? ' data-prefix="' . e($o['prefix']) . '"' : '') . (isset($o['suffix']) ? ' data-suffix="' . e($o['suffix']) . '"' : '') . '>' . e($shown) . '</span>'
        . ($unit !== '' ? '<span class="ml-1 text-[0.5em] font-bold tracking-normal ' . ($dark ? 'text-accent-300' : 'text-accent-700') . '">' . e($unit) . '</span>' : '')
        . '</span>'
        . '<span class="mt-2 block text-[13.5px] font-semibold leading-snug ' . ($dark ? 'text-white/80' : 'text-slate-600') . '">' . e($label) . '</span>'
        . (!empty($o['note']) ? '<span class="mt-0.5 block text-xs ' . ($dark ? 'text-white/55' : 'text-slate-500') . '">' . e($o['note']) . '</span>' : '')
        . '</div>';
}

/** Plain text from stored rich text / entity-encoded text (for e() output and JSON-LD). */
function pc_plain(?string $html, int $limit = 0): string
{
    $html = (string) preg_replace('#<br\s*/?>|</(p|div|li|h[1-6]|blockquote|tr)>#i', ' ', (string) $html);
    $t = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return $limit > 0 ? str_limit($t, $limit) : $t;
}

/**
 * Hero photo: the CMS banner unless it is a bundled image too small for the hero frame (< 640 px wide, it would look
 * blurry) — then $fallback. Returns [main, inset] (the small banner becomes the inset photo).
 */
function pc_hero_images(?string $banner, string $fallback): array
{
    $banner = trim((string) $banner);
    if ($banner === '') {
        return [$fallback, null];
    }
    $file = APP_ROOT . '/' . ltrim($banner, '/');
    if (!preg_match('#^https?://#i', $banner) && is_file($file) && ($size = @getimagesize($file)) && $size[0] < 640) {
        return [$fallback, $banner];
    }
    return [$banner, null];
}

/** Local image path that exists (bundled or uploaded) or an absolute URL — else null. */
function pc_image_ok(?string $path): ?string
{
    $path = trim((string) $path);
    if ($path === '') {
        return null;
    }
    return preg_match('#^https?://#i', $path) || is_file(APP_ROOT . '/' . ltrim($path, '/')) ? $path : null;
}

/** Live count as a "1,200+" style label (rounded down so it never overstates). */
function pc_round_label(int $n): string
{
    if ($n >= 1000) {
        return number_format(intdiv($n, 100) * 100) . '+';
    }
    if ($n >= 100) {
        return number_format(intdiv($n, 50) * 50) . '+';
    }
    return (string) $n;
}

/** Friendly empty state card. */
function pc_empty(string $icon, string $title, string $text, string $actions = ''): string
{
    return '<div class="empty-state rounded-3xl border border-dashed border-slate-300 bg-white">'
        . '<span class="empty-state-icon">' . icon($icon, 'h-7 w-7') . '</span>'
        . '<p class="mt-4 font-display text-lg font-bold text-brand-900">' . e($title) . '</p>'
        . '<p class="mt-1 max-w-md text-sm text-slate-600">' . e($text) . '</p>'
        . ($actions !== '' ? '<div class="mt-5 flex flex-wrap justify-center gap-3">' . $actions . '</div>' : '') . '</div>';
}

/** "On this page" chip row. $items: [anchor => label] */
function pc_page_nav(array $items, string $label = 'On this page'): string
{
    if (!$items) {
        return '';
    }
    $h = '<nav class="container-site mt-8" aria-label="' . e($label) . '"><ul class="pill-row scrollbar-none justify-start lg:justify-center">';
    foreach ($items as $id => $text) {
        $h .= '<li class="shrink-0"><a href="#' . e($id) . '" class="chip">' . e($text) . '</a></li>';
    }
    return $h . '</ul></nav>';
}

/**
 * Company tile: the official logo stored by the institute (companies.logo, alt = company name) or a text wordmark
 * (initials chip) when NULL, then the name and industry · offers. With a logo the visible name is a caption only
 * (aria-hidden) so screen readers hear the name once, from the alt text.
 */
function pc_company_tile(array $c, int $i = 0): string
{
    $tints = ['tint-sky', 'tint-lavender', 'tint-aqua', 'tint-blush', 'tint-pistachio', 'tint-sun'];
    $name = (string) $c['name'];
    $hires = (int) ($c['hires'] ?? 0);
    $logo = !empty($c['logo']) ? site_image((string) $c['logo']) : '';
    $mark = $logo !== ''
        ? '<img src="' . e($logo) . '" alt="' . e($name) . '" loading="lazy" decoding="async" width="150" height="32" class="h-7 w-auto max-w-full object-contain grayscale transition duration-300 group-hover:grayscale-0 sm:h-8 sm:max-w-[150px]">'
        : '<span class="inline-flex h-9 w-9 items-center justify-center rounded-xl font-display text-[13px] font-extrabold sm:h-10 sm:w-10 sm:text-sm ' . $tints[$i % 6] . '" aria-hidden="true">'
          . e(mb_strtoupper(mb_substr((string) preg_replace('/[^\p{L}\p{N}]/u', '', $name), 0, 2))) . '</span>';
    $meta = (trim((string) ($c['industry'] ?? '')) !== '' ? (string) $c['industry'] : 'Recruiter') . ($hires ? ' · ' . $hires . ' offer' . ($hires === 1 ? '' : 's') : '');
    return '<li class="group flex h-full min-w-0 flex-col items-center justify-center gap-1.5 rounded-2xl border border-warm-2 bg-white px-2 py-3.5 text-center transition duration-300 hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-soft sm:gap-2 sm:px-3 sm:py-5">'
        . '<span class="flex h-9 w-full items-center justify-center sm:h-10">' . $mark . '</span>'
        . '<span class="block w-full break-words text-[12px] font-bold leading-snug text-brand-900 sm:text-[13.5px]"' . ($logo !== '' ? ' aria-hidden="true"' : '') . '>' . e($name) . '</span>'
        . '<span class="block text-[10.5px] font-medium leading-snug text-slate-500 sm:text-[11.5px]">' . e($meta) . '</span></li>';
}

/* ============================================================================ Placement data */

/** Academic sessions that have valid placement offers (newest first): id, name, start_date, end_date, offers, completed. */
function pc_placement_seasons(): array
{
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }
    $rows = pc_try(fn () => db_all("SELECT s.id, s.name, s.start_date, s.end_date, COUNT(o.id) AS offers
        FROM academic_sessions s JOIN placement_offers o ON o.offer_date BETWEEN s.start_date AND s.end_date AND o.status IN " . pc_valid_offers_sql() . "
        GROUP BY s.id, s.name, s.start_date, s.end_date ORDER BY s.start_date DESC LIMIT 8"), []);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['offers'] = (int) $r['offers'];
        $r['completed'] = (string) $r['end_date'] < date('Y-m-d');
    }
    unset($r);
    return $rows;
}

/** The season shown by default: the latest completed season with offers, else the latest season with offers. */
function pc_default_season(array $seasons): ?array
{
    foreach ($seasons as $s) {
        if ($s['completed']) {
            return $s;
        }
    }
    return $seasons[0] ?? null;
}

/**
 * Public placement statistics of a season (cached): placed, eligible, percent (null when no eligible pool), offers,
 * highest, average, median, hiring (companies with offers), visited (companies with drives), drives, departments[],
 * distribution[]. Empty array when nothing can be computed.
 */
function pc_placement_stats(array $season): array
{
    $sid = (int) $season['id'];
    $fp = pc_try(fn () => db_row("SELECT
            (SELECT CONCAT(COUNT(*), '/', COALESCE(MAX(COALESCE(updated_at, created_at)), '')) FROM placement_offers) AS o,
            (SELECT CONCAT(COUNT(*), '/', COALESCE(MAX(COALESCE(updated_at, created_at)), '')) FROM placement_drives) AS d,
            (SELECT CONCAT(COUNT(*), '/', COALESCE(MAX(COALESCE(updated_at, applied_at)), '')) FROM placement_applications) AS a"), []);
    $fp = md5(json_encode($fp) . '|' . $season['start_date'] . '|' . $season['end_date']);
    $file = STORAGE_PATH . '/cache/public-placement-s' . $sid . '.json';
    if (is_file($file)) {
        $c = json_decode((string) @file_get_contents($file), true);
        if (is_array($c) && ($c['fp'] ?? '') === $fp && (int) ($c['at'] ?? 0) > time() - 21600 && is_array($c['data'] ?? null)) {
            return $c['data'];
        }
    }
    $data = pc_try(function () use ($sid, $season) {
        require_once APP_ROOT . '/app/services/placement.php';
        $d = placement_dashboard($sid);
        $k = $d['kpis'];
        $hiring = (int) db_value('SELECT COUNT(DISTINCT company_id) FROM placement_offers WHERE status IN ' . pc_valid_offers_sql() . ' AND offer_date BETWEEN ? AND ?',
            [$season['start_date'], $season['end_date']]);
        return [
            'placed' => (int) $k['placed'], 'eligible' => (int) $k['eligible'],
            'percent' => (int) $k['eligible'] > 0 ? (float) $k['placement_percent'] : null,
            'offers' => (int) $k['offers'], 'highest' => $k['highest'] !== null ? (float) $k['highest'] : null,
            'average' => $k['average'] !== null ? (float) $k['average'] : null, 'median' => $k['median'] !== null ? (float) $k['median'] : null,
            'hiring' => $hiring, 'visited' => (int) $k['companies_visited'], 'drives' => (int) $k['drives'],
            'departments' => array_values(array_map(fn ($r) => [
                'code' => (string) $r['label'], 'name' => pc_dept_label((string) $r['name']), 'eligible' => (int) $r['eligible'], 'placed' => (int) $r['placed'],
                'percent' => (float) $r['percent'], 'average' => $r['average'] !== null ? (float) $r['average'] : null, 'highest' => $r['highest'] !== null ? (float) $r['highest'] : null,
            ], $d['departments'])),
            'distribution' => array_values(array_map(fn ($b) => ['label' => (string) $b['label'], 'count' => (int) $b['count']], $d['distribution'])),
        ];
    }, []);
    if ($data) {
        if (!is_dir(STORAGE_PATH . '/cache')) {
            @mkdir(STORAGE_PATH . '/cache', 0775, true);
        }
        @file_put_contents($file, json_encode(['fp' => $fp, 'at' => time(), 'data' => $data]), LOCK_EX);
    }
    return $data;
}

/** Latest valid offers of a season: who (first name + initial), program, company, ctc. */
function pc_recent_offers(array $season, int $limit = 8): array
{
    $rows = pc_try(fn () => db_all("SELECT o.package_lpa, s.first_name, s.last_name, p.short_name AS program, c.name AS company
        FROM placement_offers o JOIN students s ON s.id = o.student_id JOIN companies c ON c.id = o.company_id LEFT JOIN programs p ON p.id = s.program_id
        WHERE o.status IN " . pc_valid_offers_sql() . " AND o.package_lpa > 0 AND o.offer_date BETWEEN ? AND ?
        ORDER BY o.offer_date DESC, o.id DESC LIMIT " . max(1, min(24, $limit)), [$season['start_date'], $season['end_date']]), []);
    return array_map(fn ($r) => ['who' => pc_short_name($r['first_name'], $r['last_name']), 'program' => (string) ($r['program'] ?? ''),
        'company' => (string) $r['company'], 'ctc' => (float) $r['package_lpa']], $rows);
}

/** Website recruiters (companies.is_recruiter) ranked by offers made to GIMT students: ['items' => [...], 'total' => n, 'all_time_hiring' => n]. */
function pc_recruiters(int $limit = 30): array
{
    $items = pc_try(fn () => db_all("SELECT c.id, c.name, c.logo, c.industry, COUNT(o.id) AS hires
        FROM companies c LEFT JOIN placement_offers o ON o.company_id = c.id AND o.status IN " . pc_valid_offers_sql() . "
        WHERE c.status = 'active' AND c.is_recruiter = 1
        GROUP BY c.id, c.name, c.logo, c.industry, c.sort_order ORDER BY hires DESC, c.sort_order, c.name LIMIT " . max(1, min(60, $limit))), []);
    return [
        'items' => $items,
        'total' => (int) pc_try(fn () => db_value("SELECT COUNT(*) FROM companies WHERE status = 'active' AND is_recruiter = 1"), 0),
        'all_time_hiring' => (int) pc_try(fn () => db_value("SELECT COUNT(DISTINCT o.company_id) FROM placement_offers o JOIN companies c ON c.id = o.company_id
            WHERE c.status = 'active' AND c.is_recruiter = 1 AND o.status IN " . pc_valid_offers_sql()), 0),
    ];
}

function pc_training_types(): array
{
    return ['training' => ['Training', 'presentation', 'badge-blue', 'training sessions'], 'mock_test' => ['Mock test', 'clipboard-check', 'badge-orange', 'mock tests'],
        'mock_interview' => ['Mock interview', 'messages-square', 'badge-purple', 'mock interviews'], 'workshop' => ['Workshop', 'wrench', 'badge-cyan', 'workshops'],
        'seminar' => ['Seminar', 'megaphone', 'badge-green', 'seminars'], 'certification' => ['Certification', 'award', 'badge-amber', 'certification programmes']];
}

/**
 * Training & mock-test highlights for the current academic session: session, counts per type of completed sessions,
 * hours, attendances, students trained, mock-test average %, upcoming[] and recent[] sessions (public columns only).
 */
function pc_training_overview(): array
{
    $ses = pc_try(fn () => db_row('SELECT id, name, start_date, end_date FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1')
        ?: db_row('SELECT id, name, start_date, end_date FROM academic_sessions WHERE CURDATE() BETWEEN start_date AND end_date ORDER BY start_date DESC LIMIT 1'));
    $out = ['session' => $ses ?: null, 'by_type' => [], 'completed' => 0, 'hours' => 0.0, 'attendances' => 0, 'students' => 0, 'mock_avg' => null, 'upcoming' => [], 'recent' => []];
    $cols = 'id, title, type, mode, trainer, start_date, end_date, duration_hours, venue, participants_count, status';
    if ($ses) {
        $rows = pc_try(fn () => db_all("SELECT type, COUNT(*) AS n, COALESCE(SUM(duration_hours), 0) AS hours FROM placement_trainings
            WHERE status = 'completed' AND start_date BETWEEN ? AND ? GROUP BY type", [$ses['start_date'], $ses['end_date']]), []);
        foreach ($rows as $r) {
            $out['by_type'][(string) $r['type']] = (int) $r['n'];
            $out['completed'] += (int) $r['n'];
            $out['hours'] += (float) $r['hours'];
        }
        $p = pc_try(fn () => db_row("SELECT COALESCE(SUM(tp.attended = 1), 0) AS attended, COUNT(DISTINCT CASE WHEN tp.attended = 1 THEN tp.student_id END) AS students,
                ROUND(AVG(CASE WHEN t.type = 'mock_test' AND tp.score IS NOT NULL AND t.max_marks > 0 THEN tp.score / t.max_marks * 100 END), 1) AS mock_avg
            FROM placement_training_participants tp JOIN placement_trainings t ON t.id = tp.training_id
            WHERE t.status = 'completed' AND t.start_date BETWEEN ? AND ?", [$ses['start_date'], $ses['end_date']]), null);
        if ($p) {
            $out['attendances'] = (int) $p['attended'];
            $out['students'] = (int) $p['students'];
            $out['mock_avg'] = $p['mock_avg'] !== null ? (float) $p['mock_avg'] : null;
        }
    }
    $out['upcoming'] = pc_try(fn () => db_all("SELECT $cols FROM placement_trainings WHERE status IN ('scheduled', 'ongoing') AND COALESCE(end_date, start_date) >= CURDATE()
        ORDER BY start_date, id LIMIT 4"), []);
    $out['recent'] = pc_try(fn () => db_all("SELECT $cols FROM placement_trainings WHERE status = 'completed' ORDER BY start_date DESC, id DESC LIMIT 4"), []);
    return $out;
}

/** Training session card. */
function pc_training_card(array $t): string
{
    $types = pc_training_types();
    [$label, $ic, $badge] = $types[$t['type']] ?? [label_from_key((string) $t['type']), 'presentation', 'badge-slate', ''];
    $start = strtotime((string) $t['start_date']);
    $end = !empty($t['end_date']) && $t['end_date'] !== $t['start_date'] ? strtotime((string) $t['end_date']) : null;
    $when = date('d M Y', $start) . ($end ? ' – ' . date('d M Y', $end) : '');
    $meta = array_filter([
        $t['duration_hours'] ? pc_num($t['duration_hours'], 1) . ' hrs' : '',
        ['offline' => 'On campus', 'online' => 'Online', 'hybrid' => 'Hybrid'][$t['mode'] ?? ''] ?? '',
        $t['status'] === 'completed' && (int) $t['participants_count'] ? (int) $t['participants_count'] . ' participants' : '',
    ]);
    return '<article class="flex h-full flex-col rounded-[20px] border border-warm-2 bg-white p-5 transition duration-300 hover:-translate-y-0.5 hover:shadow-soft">'
        . '<div class="flex items-center justify-between gap-3"><span class="badge ' . $badge . '">' . icon($ic, 'h-3.5 w-3.5') . e($label) . '</span>'
        . ($t['status'] === 'ongoing' ? '<span class="badge badge-green">In progress</span>' : '') . '</div>'
        . '<h4 class="mt-3 font-display text-[16px] font-bold leading-snug text-brand-900">' . e($t['title']) . '</h4>'
        . (!empty($t['trainer']) ? '<p class="mt-1 text-[13px] text-slate-600">' . e(str_limit((string) $t['trainer'], 90)) . '</p>' : '')
        . '<p class="mt-auto flex flex-wrap items-center gap-x-3 gap-y-1 pt-4 text-[12.5px] font-medium text-slate-500"><span class="inline-flex items-center gap-1.5">' . icon('calendar-days', 'h-3.5 w-3.5 text-accent-700')
        . '<time datetime="' . e(date('Y-m-d', $start)) . '">' . e($when) . '</time></span>'
        . ($meta ? '<span>' . e(implode(' · ', $meta)) . '</span>' : '') . '</p></article>';
}

/**
 * Success stories for the placement / alumni pages as testimonial_slider() items: published alumni success stories
 * (alumni office, featured first) topped up with active student / alumni testimonials.
 */
function pc_success_stories(int $limit = 8, bool $withTestimonials = true): array
{
    require_once APP_ROOT . '/app/services/alumni.php';
    $items = [];
    foreach (pc_try(fn () => alumni_published_stories($limit), []) as $s) {
        $items[] = array_filter(['name' => (string) $s['name'], 'quote' => pc_plain((string) $s['quote'], 240), 'role' => (string) $s['role'], 'company' => (string) $s['company'],
            'program' => (string) $s['program'], 'photo' => $s['photo'] ?: null,
            'meta' => trim(implode(' • ', array_filter([trim($s['role'] . ($s['role'] !== '' && $s['company'] !== '' ? ' at ' : '') . $s['company']), (string) $s['program']]))),
            'title' => pc_plain((string) $s['title'])], fn ($v) => $v !== null && $v !== '');
    }
    if ($withTestimonials && count($items) < $limit) {
        $rows = pc_try(fn () => db_all("SELECT t.name, t.designation, t.company, t.photo, t.content, t.batch, p.short_name AS program
            FROM testimonials t LEFT JOIN programs p ON p.id = t.program_id
            WHERE t.status = 'active' AND t.type IN ('alumni', 'student') ORDER BY t.is_featured DESC, (t.type = 'alumni') DESC, t.sort_order, t.id DESC LIMIT " . ($limit - count($items))), []);
        foreach ($rows as $t) {
            $role = trim((string) $t['designation']);
            $company = trim((string) $t['company']);
            $items[] = array_filter(['name' => (string) $t['name'], 'quote' => pc_plain((string) $t['content'], 240), 'role' => $role, 'company' => $company,
                'program' => trim((string) $t['program'] . ($t['batch'] ? ', Batch of ' . $t['batch'] : ''), ', '), 'photo' => $t['photo'] ? site_image((string) $t['photo']) : null,
                'meta' => trim(implode(' • ', array_filter([trim($role . ($role !== '' && $company !== '' ? ' at ' : '') . $company), trim((string) $t['program'] . ($t['batch'] ? ', Batch of ' . $t['batch'] : ''), ', ')]))),
            ], fn ($v) => $v !== null && $v !== '');
        }
    }
    return array_values(array_filter($items, fn ($i) => ($i['name'] ?? '') !== '' && ($i['quote'] ?? '') !== ''));
}

/** Compact alumni story card (title, quote, avatar, name, role · program). $s = pc_success_stories() item. */
function pc_story_card(array $s): string
{
    $avatar = !empty($s['photo'])
        ? '<img src="' . e($s['photo']) . '" alt="" loading="lazy" decoding="async" width="40" height="40" class="h-10 w-10 shrink-0 rounded-full object-cover">'
        : '<span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-50 text-[13px] font-bold text-brand-800" aria-hidden="true">' . e(site_initials((string) $s['name'])) . '</span>';
    $role = trim((string) ($s['role'] ?? ''));
    $company = trim((string) ($s['company'] ?? ''));
    $career = trim($role . ($role !== '' && $company !== '' ? ' at ' : '') . $company);
    $program = trim((string) ($s['program'] ?? ''));
    return '<li class="flex h-full flex-col rounded-[22px] border border-warm-2 bg-white p-6 transition duration-300 hover:-translate-y-0.5 hover:shadow-soft">'
        . (!empty($s['title']) ? '<h3 class="font-display text-[16px] font-bold leading-snug text-brand-900">' . e($s['title']) . '</h3>' : '')
        . '<p class="mt-2 text-[14px] leading-relaxed text-slate-600">“' . e(str_limit((string) $s['quote'], 170)) . '”</p>'
        . '<div class="mt-auto flex items-start gap-3 pt-5">' . $avatar
        . '<p class="min-w-0 text-[13px] leading-snug"><span class="block font-semibold text-brand-900">' . e($s['name']) . '</span>'
        . ($career !== '' ? '<span class="mt-0.5 block text-slate-600">' . e($career) . '</span>' : '')
        . ($program !== '' ? '<span class="block text-[12px] text-slate-500">' . e($program) . '</span>' : '')
        . '</p></div></li>';
}

/* ============================================================================ Alumni data */

/** Aggregate alumni network numbers (verified, active profiles only — self-registrations count once verified). */
function pc_alumni_stats(): array
{
    $r = pc_try(fn () => db_row("SELECT COUNT(*) AS total, COUNT(DISTINCT NULLIF(TRIM(company), '')) AS companies, COUNT(DISTINCT NULLIF(TRIM(city), '')) AS cities,
            COUNT(DISTINCT NULLIF(TRIM(country), '')) AS countries, COUNT(DISTINCT batch_year) AS batches, MIN(batch_year) AS first_batch
        FROM alumni WHERE status = 'active' AND is_verified = 1"), []) ?: [];
    return [
        'total' => (int) ($r['total'] ?? 0), 'companies' => (int) ($r['companies'] ?? 0), 'cities' => (int) ($r['cities'] ?? 0),
        'countries' => (int) ($r['countries'] ?? 0), 'batches' => (int) ($r['batches'] ?? 0), 'first_batch' => $r['first_batch'] ?? null,
        'mentors' => (int) pc_try(fn () => db_value("SELECT COUNT(*) FROM alumni WHERE status = 'active' AND is_verified = 1 AND is_mentor = 1"), 0),
    ];
}

/** Where verified alumni work and live: companies, industries, countries, cities (aggregate counts). */
function pc_alumni_footprint(): array
{
    $base = "FROM alumni WHERE status = 'active' AND is_verified = 1";
    return [
        'companies' => pc_try(fn () => db_all("SELECT TRIM(company) AS label, COUNT(*) AS n $base AND company IS NOT NULL AND TRIM(company) <> '' GROUP BY TRIM(company) ORDER BY n DESC, label LIMIT 18"), []),
        'industries' => pc_try(fn () => db_all("SELECT TRIM(industry) AS label, COUNT(*) AS n $base AND industry IS NOT NULL AND TRIM(industry) <> '' GROUP BY TRIM(industry) ORDER BY n DESC, label LIMIT 8"), []),
        'countries' => pc_try(fn () => db_all("SELECT TRIM(country) AS label, COUNT(*) AS n $base AND country IS NOT NULL AND TRIM(country) <> '' GROUP BY TRIM(country) ORDER BY n DESC, label LIMIT 12"), []),
        'cities' => pc_try(fn () => db_all("SELECT TRIM(city) AS label, COUNT(*) AS n $base AND city IS NOT NULL AND TRIM(city) <> '' GROUP BY TRIM(city) ORDER BY n DESC, label LIMIT 10"), []),
    ];
}

/** Upcoming alumni events (status upcoming, not yet started). */
function pc_alumni_events(int $limit = 6): array
{
    return pc_try(fn () => db_all("SELECT id, title, type, description, event_date, end_date, venue, mode, image, registration_link, capacity, organiser
        FROM alumni_events WHERE status = 'upcoming' AND event_date >= NOW() ORDER BY event_date, id LIMIT " . max(1, min(24, $limit))), []);
}

/** Recently held alumni events (for "recent gatherings"). */
function pc_alumni_past_events(int $limit = 3): array
{
    return pc_try(fn () => db_all("SELECT id, title, type, event_date, venue, mode, attendees_count FROM alumni_events
        WHERE status = 'completed' AND event_date < NOW() ORDER BY event_date DESC, id DESC LIMIT " . max(1, min(12, $limit))), []);
}

/** Open, approved alumni job posts that have not expired: ['items' => [...], 'total' => n]. */
function pc_alumni_jobs(int $limit = 6): array
{
    $where = "status = 'open' AND (last_date IS NULL OR last_date >= CURDATE())";
    return [
        'items' => pc_try(fn () => db_all("SELECT id, company, title, location, job_type, experience, salary_range, skills, apply_link, last_date, created_at
            FROM alumni_jobs WHERE $where ORDER BY COALESCE(approved_at, created_at) DESC, id DESC LIMIT " . max(1, min(24, $limit))), []),
        'total' => (int) pc_try(fn () => db_value("SELECT COUNT(*) FROM alumni_jobs WHERE $where"), 0),
    ];
}

/** Alumni job card (title, company, type / experience / salary, skills, location, deadline, apply link). */
function pc_job_card(array $j, array $jobTypes): string
{
    $apply = !empty($j['apply_link']) && preg_match('#^https?://#i', (string) $j['apply_link']) ? (string) $j['apply_link'] : '';
    $mark = mb_strtoupper(mb_substr((string) preg_replace('/[^\p{L}\p{N}]/u', '', (string) $j['company']), 0, 2));
    $h = '<li class="flex h-full flex-col rounded-[20px] border border-warm-2 bg-white p-5 transition duration-300 hover:-translate-y-0.5 hover:shadow-soft">'
        . '<div class="flex items-start gap-3"><span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 font-display text-sm font-extrabold text-brand-800" aria-hidden="true">' . e($mark) . '</span>'
        . '<div class="min-w-0"><h3 class="font-display text-[16px] font-bold leading-snug text-brand-900">' . e($j['title']) . '</h3>'
        . '<p class="text-[13.5px] font-medium text-slate-600">' . e($j['company']) . '</p></div></div>'
        . '<p class="mt-3 flex flex-wrap gap-1.5"><span class="badge badge-blue">' . e($jobTypes[$j['job_type']] ?? label_from_key((string) $j['job_type'])) . '</span>'
        . (!empty($j['experience']) ? '<span class="badge badge-slate">' . e($j['experience']) . '</span>' : '')
        . (!empty($j['salary_range']) ? '<span class="badge badge-green">' . e($j['salary_range']) . '</span>' : '') . '</p>'
        . (!empty($j['skills']) ? '<p class="mt-3 line-clamp-2 text-[13px] text-slate-600"><span class="font-semibold text-brand-900">Skills:</span> ' . e($j['skills']) . '</p>' : '')
        . '<div class="mt-auto flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-t border-slate-100 pt-4 text-[12.5px] text-slate-500">'
        . '<span class="inline-flex items-center gap-1.5">' . icon('map-pin', 'h-3.5 w-3.5') . e($j['location'] ?: 'Location flexible') . '</span>'
        . (!empty($j['last_date']) ? '<span>Apply by <time datetime="' . e($j['last_date']) . '">' . e(format_date($j['last_date'], 'd M Y')) . '</time></span>' : '')
        . '</div><div class="mt-3">';
    $h .= $apply !== ''
        ? '<a href="' . e($apply) . '" target="_blank" rel="noopener nofollow" class="link-arrow text-[14px]">View &amp; apply' . icon('external-link', 'h-4 w-4')
          . '<span class="sr-only"> for ' . e($j['title']) . ' at ' . e($j['company']) . ' (opens in a new tab)</span></a>'
        : '<a href="' . e(site_url('contact?type=placement')) . '#message" class="link-arrow text-[14px]">Ask the placement cell' . icon('arrow-right', 'h-4 w-4')
          . '<span class="sr-only"> about ' . e($j['title']) . ' at ' . e($j['company']) . '</span></a>';
    return $h . '</div></li>';
}

/** Mentorship programme numbers: mentors, active / completed pairings, sessions held, top focus areas. */
function pc_mentorship(): array
{
    $r = pc_try(fn () => db_row("SELECT COALESCE(SUM(status = 'active'), 0) AS active, COALESCE(SUM(status = 'completed'), 0) AS completed,
            COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN sessions_count END), 0) AS sessions FROM alumni_mentorships"), []) ?: [];
    return [
        'active' => (int) ($r['active'] ?? 0), 'completed' => (int) ($r['completed'] ?? 0), 'sessions' => (int) ($r['sessions'] ?? 0),
        'areas' => pc_try(fn () => db_column("SELECT area FROM alumni_mentorships WHERE status <> 'cancelled' AND area IS NOT NULL AND area <> ''
            GROUP BY area ORDER BY COUNT(*) DESC, area LIMIT 8"), []),
    ];
}

/** Give-back overview (no amounts): donors, contributions, causes supported, recent campaigns. */
function pc_giving(): array
{
    $r = pc_try(fn () => db_row("SELECT COUNT(DISTINCT alumni_id) AS donors, COUNT(*) AS contributions FROM alumni_donations WHERE status = 'received'"), []) ?: [];
    return [
        'donors' => (int) ($r['donors'] ?? 0), 'contributions' => (int) ($r['contributions'] ?? 0),
        'causes' => pc_try(fn () => db_all("SELECT purpose AS label, COUNT(DISTINCT alumni_id) AS donors FROM alumni_donations
            WHERE status = 'received' AND purpose IS NOT NULL AND purpose <> '' GROUP BY purpose ORDER BY donors DESC, purpose LIMIT 6"), []),
        'campaigns' => pc_try(fn () => db_column("SELECT campaign FROM alumni_donations WHERE status IN ('received', 'pledged') AND campaign IS NOT NULL AND campaign <> ''
            AND donation_date >= ? GROUP BY campaign ORDER BY MAX(donation_date) DESC LIMIT 4", [date('Y-m-d', strtotime('-18 months'))]), []),
    ];
}

/** Programs offered in the alumni registration form (every program — alumni may come from discontinued ones). */
function pc_alumni_program_options(): array
{
    $rows = pc_try(fn () => db_all('SELECT id, name, short_name FROM programs ORDER BY sort_order, name LIMIT 200'), []);
    return array_map(fn ($p) => ['value' => (string) $p['id'], 'label' => $p['name'] . ($p['short_name'] && stripos($p['name'], (string) $p['short_name']) === false ? ' (' . $p['short_name'] . ')' : '')], $rows);
}

/** Earliest / latest accepted batch (passing-out) years for the registration form. */
function pc_alumni_year_range(): array
{
    $min = (int) setting('established_year', 0);
    return [$min >= 1950 && $min <= (int) date('Y') ? $min : 1990, (int) date('Y')];
}

/* ============================================================================ Alumni registration (POST /api/public/alumni-register) */

/**
 * Validate and store a "Join the alumni network" registration. Called through pf_api() (CSRF, honeypot, per-IP rate
 * limit, JSON / no-JS responses — api/routes/public.php). The alumni table can hold an unverified profile, so a new
 * registration becomes an alumni row with is_verified = 0 and show_on_website = 0 (the alumni coordinator verifies it in
 * Admin › Alumni; it is not counted on the website until then). A registration whose email (or phone + batch) matches
 * an existing profile never overwrites it — the coordinator is notified with the submitted details instead. The reply is
 * identical in both cases (no account enumeration). Throws PublicFormException with field errors (422).
 */
function pc_submit_alumni_registration(array $in): array
{
    require_once APP_ROOT . '/app/services/alumni.php';
    $statuses = alumni_employment_statuses();
    $data = [
        'name' => pf_text($in['name'] ?? null), 'email' => strtolower((string) pf_text($in['email'] ?? null)) ?: null, 'phone' => pf_text($in['phone'] ?? null),
        'batch_year' => pf_text($in['batch_year'] ?? null), 'program_id' => pf_text($in['program_id'] ?? null),
        'employment_status' => pf_text($in['employment_status'] ?? null) ?? 'employed',
        'company' => pf_text($in['company'] ?? null), 'designation' => pf_text($in['designation'] ?? null),
        'city' => pf_text($in['city'] ?? null), 'country' => pf_text($in['country'] ?? null) ?? 'India',
        'linkedin_url' => pf_text($in['linkedin_url'] ?? null),
    ];
    [$minYear, $maxYear] = pc_alumni_year_range();
    $labels = ['name' => 'Full Name', 'email' => 'Email Address', 'batch_year' => 'Batch (passing-out year)', 'program_id' => 'Program',
        'employment_status' => 'Current status', 'company' => 'Company / Organisation', 'designation' => 'Designation', 'city' => 'City', 'country' => 'Country',
        'linkedin_url' => 'LinkedIn profile'];
    $errors = validate($data, [
        'name' => 'required|min:2|max:150', 'email' => 'required|email|max:190',
        'batch_year' => 'required|integer|min:' . $minYear . '|max:' . $maxYear, 'program_id' => 'required|integer',
        'employment_status' => 'required|in:' . implode(',', array_keys($statuses)),
        'company' => 'max:190', 'designation' => 'max:150', 'city' => 'required|min:2|max:80', 'country' => 'min:2|max:80', 'linkedin_url' => 'max:255',
    ], $labels);
    pf_check_name($errors, 'name', $data['name'], 'Full Name');
    pf_check_phone($errors, 'phone', $data['phone'], true, 'Phone Number');
    foreach (['city' => 'City', 'country' => 'Country'] as $f => $l) {
        if ($data[$f] !== null && !isset($errors[$f]) && !preg_match("/^\\p{L}[\\p{L}\\p{M} .'\\-]*$/u", $data[$f])) {
            $errors[$f] = $l . ' may only contain letters, spaces, dots and hyphens.';
        }
    }
    $program = null;
    if (!isset($errors['program_id'])) {
        $program = db_row('SELECT id, name, short_name, department_id FROM programs WHERE id = ?', [(int) $data['program_id']]);
        if (!$program) {
            $errors['program_id'] = 'Select a valid program.';
        }
    }
    if (in_array($data['employment_status'], ['employed', 'entrepreneur', 'self_employed'], true) && $data['company'] === null && !isset($errors['company'])) {
        $errors['company'] = $data['employment_status'] === 'employed' ? 'Company / Organisation is required when you are employed.' : 'Enter your company or business name.';
    }
    if ($data['employment_status'] === 'employed' && $data['designation'] === null && !isset($errors['designation'])) {
        $errors['designation'] = 'Designation is required when you are employed.';
    }
    if ($data['linkedin_url'] !== null && !isset($errors['linkedin_url'])
        && !preg_match('#^https://([a-z]{2,3}\.)?linkedin\.com/(in|pub)/[A-Za-z0-9\-_%.]+/?(\?.*)?$#i', $data['linkedin_url'])) {
        $errors['linkedin_url'] = 'Enter your LinkedIn profile link, e.g. https://www.linkedin.com/in/your-name';
    }
    if (!pf_truthy($in['consent'] ?? '')) {
        $errors['consent'] = 'Please confirm the details and agree to be contacted by the alumni office.';
    }
    if ($errors) {
        throw new PublicFormException(PF_FIX_FIELDS, $errors);
    }

    $mentor = pf_truthy($in['mentor'] ?? '');
    $batch = (int) $data['batch_year'];
    $programLabel = (string) ($program['short_name'] ?: $program['name']);
    $statusLabel = $statuses[$data['employment_status']];
    $career = trim(implode(', ', array_filter([$data['designation'], $data['company']]))) ?: $statusLabel;
    $first = pf_first_name($data['name']);
    $reply = 'Thank you, ' . $first . '! Your alumni registration has been received. Our alumni coordinator will verify your details and welcome you to the network'
        . ($mentor ? ' — and get in touch about mentoring.' : '.');

    $phoneKey = admission_phone_key((string) $data['phone']);
    $findExisting = fn () => db_row('SELECT id, name, is_verified FROM alumni WHERE email = ? LIMIT 1', [$data['email']])
        ?: ($phoneKey !== '' ? db_row('SELECT id, name, is_verified FROM alumni WHERE ' . admission_phone_sql('phone') . ' = ? AND batch_year = ? LIMIT 1', [$phoneKey, $batch]) : null);
    $updateRequest = function (array $existing) use ($data, $batch, $programLabel, $career, $mentor, $reply): array {
        $summary = sprintf('%s (Batch %d · %s) re-registered on the website: %s · %s, %s%s.', $data['name'], $batch, $programLabel, $career, $data['city'], $data['country'],
            $mentor ? ' · wants to mentor students' : '');
        notify('perm:alumni', 'alumni', 'Alumni profile update request', $summary . ' Review and update the profile if needed.', 'admin/alumni/' . (int) $existing['id'], 'user-pen');
        log_activity('update', 'alumni', (int) $existing['id'], 'Website alumni registration for an existing profile — ' . $summary);
        return ['id' => null, 'data' => ['received' => true], 'message' => $reply];
    };
    if ($existing = $findExisting()) {
        return $updateRequest($existing);
    }

    try {
        $id = db_insert('alumni', [
            'name' => mb_substr($data['name'], 0, 150), 'email' => $data['email'], 'phone' => mb_substr((string) $data['phone'], 0, 20),
            'batch_year' => $batch, 'program_id' => (int) $program['id'], 'department_id' => $program['department_id'] ? (int) $program['department_id'] : null,
            'company' => $data['company'] ? mb_substr($data['company'], 0, 190) : null, 'designation' => $data['designation'] ? mb_substr($data['designation'], 0, 150) : null,
            'city' => mb_substr($data['city'], 0, 80), 'country' => mb_substr($data['country'], 0, 80), 'linkedin_url' => $data['linkedin_url'],
            'employment_status' => $data['employment_status'], 'is_mentor' => 0, 'is_verified' => 0, 'show_on_website' => 0, 'status' => 'active',
        ]);
    } catch (PDOException $e) {
        // A parallel double-submit hit the unique e-mail key between the lookup and the insert: treat it as the existing profile.
        if ($e->getCode() === '23000' && ($existing = $findExisting())) {
            return $updateRequest($existing);
        }
        throw $e;
    }
    $summary = sprintf('%s (Batch %d · %s) — %s · %s, %s%s', $data['name'], $batch, $programLabel, $career, $data['city'], $data['country'],
        $mentor ? ' · wants to mentor students' : '');
    notify('perm:alumni', 'alumni', 'New alumni registration', $summary . '. Verify the profile to add it to the network.', 'admin/alumni/' . $id, 'user-plus');
    log_activity('create', 'alumni', $id, 'Website alumni registration (unverified): ' . $summary);
    pf_send_ack('alumni_registration', $data['email'], ['name' => $data['name'], 'batch' => (string) $batch, 'program' => (string) $program['name']],
        ['related_type' => 'alumni', 'related_id' => $id]);
    return ['id' => null, 'data' => ['received' => true], 'message' => $reply];
}
