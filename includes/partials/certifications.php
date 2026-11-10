<?php
/**
 * Certificate courses: compact course cards, the grouped course explorer and certificate-specific copy shared by
 * /certifications, /programs and the course page. Groups come from site_cert_groups() (programs.category of
 * level-Certificate programs, seed 62_certificate_courses).
 */
require_once __DIR__ . '/site_academic.php';

/** Active module (subject) counts per program id. */
function cert_module_counts(): array
{
    static $rows = null;
    return $rows ??= sa_try(fn () => array_map('intval', db_pairs("SELECT program_id, COUNT(*) FROM subjects WHERE status = 'active' GROUP BY program_id")), []);
}

/** Group meta of a certificate program (falls back to a plain "Certificate" group). */
function cert_group_meta(array $p): array
{
    $key = site_cert_group_key($p);
    return ($key !== '' ? site_cert_groups()[$key] : ['label' => 'Certificate Courses', 'icon' => 'badge-check', 'hue' => 'cert', 'blurb' => '']) + ['key' => $key];
}

/** Certificate courses per group key, in site_cert_groups() order (empty groups dropped; ungrouped under 'other'). */
function cert_by_group(array $programs): array
{
    $out = array_fill_keys(array_keys(site_cert_groups()), []);
    foreach ($programs as $p) {
        $out[site_cert_group_key($p) ?: 'other'][] = $p;
    }
    return array_filter($out);
}

/** "3 Months · 6 Modules" style facts for a certificate course. */
function cert_facts(array $p): array
{
    $modules = cert_module_counts()[(int) $p['id']] ?? 0;
    return array_values(array_filter([
        ['clock', (string) ($p['duration_label'] ?: '')],
        $modules ? ['layers', $modules . ' Module' . ($modules === 1 ? '' : 's')] : null,
        ['wallet', sa_program_fee($p) ?: 'Fee on request'],
    ], fn ($f) => $f && $f[1] !== ''));
}

/**
 * Certificate course card: the course thumbnail (16:9, shown whole, nothing on top of it — when the course has one),
 * group label with icon + group colour, title, two-line summary, duration · modules · fee, Apply Now + Details.
 * $o: heading (h2|h3), summary (bool, default true), image (bool, default true).
 */
function cert_card(array $p, array $o = []): string
{
    $g = cert_group_meta($p);
    $url = site_url('programs/' . $p['slug']);
    $h = $o['heading'] ?? 'h3';
    $thumb = ($o['image'] ?? true) ? trim((string) ($p['image'] ?? '')) : '';
    $html = '<article class="cert-card hue-' . e($g['hue']) . ($thumb !== '' ? ' cert-card-has-media' : '') . '">'
        . ($thumb !== '' ? '<a href="' . e($url) . '" class="cert-card-media" tabindex="-1" aria-hidden="true"><img src="' . e(site_image($thumb)) . '" alt="" class="course-thumb" loading="lazy" decoding="async" width="640" height="360"></a>' : '')
        . '<div class="cert-card-head"><span class="cert-card-icon">' . icon($g['icon'], 'h-5 w-5') . '</span><span class="cert-card-group">' . e($g['label']) . '</span></div>'
        . '<' . $h . ' class="cert-card-title"><a href="' . e($url) . '">' . e($p['name']) . '</a></' . $h . '>';
    if (($o['summary'] ?? true) && trim((string) $p['overview']) !== '') {
        $html .= '<p class="cert-card-text">' . e(str_limit(strip_tags((string) $p['overview']), 140)) . '</p>';
    }
    $html .= '<ul class="cert-card-meta">';
    foreach (cert_facts($p) as [$ic, $text]) {
        $html .= '<li>' . icon($ic, 'h-3.5 w-3.5') . e($text) . '</li>';
    }
    $html .= '</ul><div class="cert-card-actions">'
        . '<a href="' . e(site_url('apply?program=' . rawurlencode((string) $p['slug']))) . '" class="btn btn-accent btn-sm">Apply Now<span class="sr-only"> for ' . e($p['name']) . '</span></a>'
        . '<a href="' . e($url) . '" class="link-arrow text-[13.5px]">Details' . icon('arrow-right', 'h-4 w-4') . '<span class="sr-only"> about ' . e($p['name']) . '</span></a>'
        . '</div></article>';
    return $html;
}

/**
 * Course explorer for /certifications: ProgramExplorer island (group tiles + search, ?group= / ?q= kept in the URL)
 * over cert_card()s, with a same-footprint fallback whose tiles are real links (filters without JavaScript too).
 */
function cert_explorer(array $courses, string $initial = 'all'): string
{
    $categories = [['key' => 'all', 'label' => 'All Courses', 'icon' => 'layout-grid']];
    foreach (cert_by_group($courses) as $key => $rows) {
        if ($key !== 'other') {
            $categories[] = ['key' => $key, 'label' => site_cert_groups()[$key]['label'], 'icon' => site_cert_groups()[$key]['icon']];
        }
    }
    $props = ['categories' => $categories, 'layout' => 'tiles', 'search' => true, 'syncParam' => 'group', 'searchParam' => 'q', 'initial' => $initial,
        'tileClass' => 'grid grid-cols-2 gap-3 sm:grid-cols-3', 'gridClass' => 'sm:grid-cols-2 2xl:grid-cols-3', 'searchPlaceholder' => 'Search courses, e.g. Excel or Spoken English',
        'emptyText' => 'No courses match your search — try another group or keyword.', 'itemLabel' => ['one' => 'course', 'many' => 'courses']];
    $tiles = '';
    foreach ($categories as $c) {
        $on = $c['key'] === $initial;
        $href = site_url('certifications' . ($c['key'] === 'all' ? '' : '?group=' . rawurlencode($c['key']))) . '#courses';
        $tiles .= '<a href="' . e($href) . '" class="filter-tile' . ($on ? ' is-active' : '') . '"' . ($on ? ' aria-current="true"' : '') . '>'
            . '<span class="filter-tile-icon">' . icon($c['icon'], 'h-5 w-5') . '</span><span class="text-sm font-semibold">' . e($c['label']) . '</span></a>';
    }
    $cards = '';
    foreach ($courses as $p) {
        $key = site_cert_group_key($p);
        $match = $initial === 'all' || $initial === $key;
        $search = trim($p['name'] . ' ' . $p['short_name'] . ' ' . ($p['category'] ?? '') . ' certificate course');
        $cards .= '<div class="h-full" data-key="' . e($p['slug']) . '" data-category="' . e($key) . '" data-search="' . e($search) . '"' . ($match ? '' : ' data-off') . '>' . cert_card($p) . '</div>';
    }
    $fallback = '<div class="program-explorer"><div class="mb-8 flex flex-col gap-4"><div class="' . e($props['tileClass']) . '" role="toolbar" aria-label="Filter courses by group">' . $tiles . '</div>'
        . '<div class="flex items-center gap-3"><label class="search-field">' . icon('search', 'h-4 w-4 shrink-0 text-slate-400')
        . '<span class="sr-only">Search courses</span><input type="search" placeholder="' . e($props['searchPlaceholder']) . '" class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0" disabled></label></div></div>'
        . '<div class="grid gap-6 ' . e($props['gridClass']) . ' [&>[data-off]]:hidden" data-slot="items">' . $cards . '</div></div>';
    return island('ProgramExplorer', $props, $fallback, ['eager' => true]);
}

/** Group cards linking to /certifications?group=… (title, blurb, course count) — used on /programs. */
function cert_group_cards(array $courses): string
{
    $html = '';
    foreach (cert_by_group($courses) as $key => $rows) {
        if ($key === 'other') {
            continue;
        }
        $g = site_cert_groups()[$key];
        $n = count($rows);
        $html .= '<a href="' . e(site_url('certifications?group=' . $key) . '#courses') . '" class="cert-group-card hue-' . e($g['hue']) . '">'
            . '<span class="cert-card-icon">' . icon($g['icon'], 'h-5 w-5') . '</span>'
            . '<span class="min-w-0 flex-1"><span class="block font-display text-[15px] font-bold leading-snug text-brand-900">' . e($g['label']) . '</span>'
            . '<span class="mt-0.5 block text-[13px] leading-snug text-slate-500">' . e($g['blurb']) . '</span></span>'
            . '<span class="cert-group-count">' . $n . ' course' . ($n === 1 ? '' : 's') . icon('arrow-right', 'h-3.5 w-3.5') . '</span></a>';
    }
    return $html;
}
