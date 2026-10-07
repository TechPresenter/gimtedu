<?php
/**
 * Reusable server-rendered UI components. All return HTML strings (echo them).
 * Visual language: white cards, rounded-2xl, soft shadow, navy brand, accent colours per context.
 */

/** Tailwind colour tokens used by KPI cards, badges and icons. Keep class names literal for the CSS build. */
function ui_color(string $color): array
{
    $map = [
        'blue'   => ['bg' => 'bg-blue-50 dark:bg-blue-500/10', 'icon' => 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300', 'text' => 'text-blue-700 dark:text-blue-300', 'bar' => 'bg-blue-600', 'badge' => 'badge-blue'],
        'navy'   => ['bg' => 'bg-brand-50 dark:bg-brand-500/10', 'icon' => 'bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200', 'text' => 'text-brand-800 dark:text-brand-200', 'bar' => 'bg-brand-700', 'badge' => 'badge-blue'],
        'green'  => ['bg' => 'bg-emerald-50 dark:bg-emerald-500/10', 'icon' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300', 'text' => 'text-emerald-700 dark:text-emerald-300', 'bar' => 'bg-emerald-500', 'badge' => 'badge-green'],
        'orange' => ['bg' => 'bg-orange-50 dark:bg-orange-500/10', 'icon' => 'bg-orange-100 text-orange-600 dark:bg-orange-500/20 dark:text-orange-300', 'text' => 'text-orange-600 dark:text-orange-300', 'bar' => 'bg-orange-500', 'badge' => 'badge-amber'],
        'amber'  => ['bg' => 'bg-amber-50 dark:bg-amber-500/10', 'icon' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300', 'text' => 'text-amber-700 dark:text-amber-300', 'bar' => 'bg-amber-500', 'badge' => 'badge-amber'],
        'purple' => ['bg' => 'bg-violet-50 dark:bg-violet-500/10', 'icon' => 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300', 'text' => 'text-violet-700 dark:text-violet-300', 'bar' => 'bg-violet-500', 'badge' => 'badge-purple'],
        'pink'   => ['bg' => 'bg-rose-50 dark:bg-rose-500/10', 'icon' => 'bg-rose-100 text-rose-600 dark:bg-rose-500/20 dark:text-rose-300', 'text' => 'text-rose-600 dark:text-rose-300', 'bar' => 'bg-rose-500', 'badge' => 'badge-red'],
        'red'    => ['bg' => 'bg-red-50 dark:bg-red-500/10', 'icon' => 'bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-300', 'text' => 'text-red-600 dark:text-red-300', 'bar' => 'bg-red-500', 'badge' => 'badge-red'],
        'cyan'   => ['bg' => 'bg-cyan-50 dark:bg-cyan-500/10', 'icon' => 'bg-cyan-100 text-cyan-700 dark:bg-cyan-500/20 dark:text-cyan-300', 'text' => 'text-cyan-700 dark:text-cyan-300', 'bar' => 'bg-cyan-500', 'badge' => 'badge-cyan'],
        'slate'  => ['bg' => 'bg-slate-50 dark:bg-slate-800/60', 'icon' => 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300', 'text' => 'text-slate-600 dark:text-slate-300', 'bar' => 'bg-slate-400', 'badge' => 'badge-slate'],
    ];
    return $map[$color] ?? $map['blue'];
}

/**
 * KPI / stat card.
 *   kpi_card('Total Students', number_in(1248), 'graduation-cap', 'blue', ['value' => '12%', 'dir' => 'up', 'label' => 'from last session'], 'students.php')
 */
function kpi_card(string $label, string $value, string $icon, string $color = 'blue', ?array $trend = null, ?string $url = null): string
{
    $c = ui_color($color);
    $trendHtml = '';
    if ($trend) {
        $dir = $trend['dir'] ?? 'up';
        $tc = $dir === 'up' ? 'text-emerald-600 dark:text-emerald-400' : ($dir === 'down' ? 'text-red-600 dark:text-red-400' : 'text-slate-500');
        $ti = $dir === 'up' ? 'arrow-up-right' : ($dir === 'down' ? 'arrow-down-right' : 'arrow-right');
        $trendHtml = '<p class="mt-2 flex items-center gap-1 text-xs"><span class="inline-flex items-center gap-0.5 font-semibold ' . $tc . '">' . icon($ti, 'w-3.5 h-3.5') . e($trend['value'] ?? '') . '</span><span class="text-slate-500 dark:text-slate-400 truncate">' . e($trend['label'] ?? '') . '</span></p>';
    }
    $tag = $url ? 'a href="' . e($url) . '"' : 'div';
    $close = $url ? 'a' : 'div';
    return '<' . $tag . ' class="kpi-card ' . $c['bg'] . '">'
        . '<span class="kpi-icon ' . $c['icon'] . '">' . icon($icon, 'w-5 h-5') . '</span>'
        . '<p class="mt-3 text-[13px] font-medium text-slate-600 dark:text-slate-300">' . e($label) . '</p>'
        . '<p class="mt-0.5 text-2xl font-bold tracking-tight text-slate-900 dark:text-white font-display">' . e($value) . '</p>'
        . $trendHtml . '</' . $close . '>';
}

/** Compact horizontal stat (icon left). Good for module dashboards. */
function stat_tile(string $label, string $value, string $icon, string $color = 'blue', string $sub = ''): string
{
    $c = ui_color($color);
    return '<div class="card p-4 flex items-center gap-4">'
        . '<span class="kpi-icon shrink-0 ' . $c['icon'] . '">' . icon($icon, 'w-5 h-5') . '</span>'
        . '<div class="min-w-0"><p class="text-xs font-medium text-slate-500 dark:text-slate-400 truncate">' . e($label) . '</p>'
        . '<p class="text-xl font-bold text-slate-900 dark:text-white font-display leading-tight">' . e($value) . '</p>'
        . ($sub !== '' ? '<p class="text-xs text-slate-500 dark:text-slate-400 truncate">' . e($sub) . '</p>' : '') . '</div></div>';
}

/** Badge with explicit colour: badge('Paid', 'green'). */
function badge(string $text, string $color = 'slate', string $extra = ''): string
{
    $cls = ui_color($color)['badge'];
    return '<span class="badge ' . $cls . ' ' . e($extra) . '">' . e($text) . '</span>';
}

/** Badge coloured automatically from common status words. */
function status_badge(?string $status): string
{
    $status = (string) $status;
    if ($status === '') {
        return '<span class="text-slate-400">—</span>';
    }
    $s = strtolower($status);
    $colors = [
        'green'  => ['active', 'paid', 'present', 'approved', 'published', 'success', 'confirmed', 'completed', 'pass', 'issued', 'verified', 'available', 'selected', 'accepted', 'joined', 'resolved', 'subscribed', 'sent', 'open', 'converted', 'returned', 'placed', 'online', 'yes', 'cleared', 'enabled', 'promoted'],
        'amber'  => ['pending', 'partial', 'late', 'draft', 'scheduled', 'in_progress', 'on_leave', 'leave', 'review', 'shortlisted', 'interested', 'contacted', 'upcoming', 'marks_entry', 'submitted', 'on_hold', 'maintenance', 'queued', 'reserved', 'half_day', 'processing', 'withheld', 'offered', 'medium'],
        'red'    => ['inactive', 'overdue', 'absent', 'rejected', 'failed', 'fail', 'cancelled', 'revoked', 'blocked', 'locked', 'suspended', 'dropped', 'lost', 'damaged', 'not_interested', 'declined', 'urgent', 'high', 'unpaid', 'backlog', 'expired', 'closed_lost', 'malpractice', 'no'],
        'blue'   => ['new', 'ongoing', 'applied', 'issued_book', 'application', 'enquiry', 'graduated', 'alumni', 'read', 'replied', 'aptitude', 'technical', 'hr', 'full', 'occupied', 'low'],
        'purple' => ['document_verification', 'entrance_interview', 'approval', 'fee_payment', 'archived', 'withdrawn', 'vacated', 'refunded', 'waived', 'unsubscribed'],
    ];
    $color = 'slate';
    foreach ($colors as $c => $words) {
        if (in_array($s, $words, true)) {
            $color = $c;
            break;
        }
    }
    $label = in_array($s, ['pass', 'fail', 'backlog', 'absent', 'withheld'], true) && strtoupper($status) === $status ? $status : label_from_key($status);
    return badge($label, $color);
}

/** Page header with title, description, breadcrumbs (from $GLOBALS['breadcrumbs']) and right-side actions. */
function page_header(string $title, string $description = '', string $actions = ''): string
{
    $crumbs = $GLOBALS['breadcrumbs'] ?? [];
    $bc = '';
    if ($crumbs) {
        $bc = '<nav aria-label="Breadcrumb" class="mb-1.5"><ol class="flex flex-wrap items-center gap-1 text-xs text-slate-500 dark:text-slate-400">'
            . '<li><a href="' . e(admin_url('')) . '" class="hover:text-brand-700 dark:hover:text-white inline-flex items-center">' . icon('house', 'w-3.5 h-3.5') . '<span class="sr-only">Dashboard</span></a></li>';
        foreach ($crumbs as $crumb) {
            [$label, $url] = array_pad((array) $crumb, 2, null);
            $bc .= '<li class="text-slate-300 dark:text-slate-600">' . icon('chevron-right', 'w-3 h-3') . '</li>';
            $bc .= $url ? '<li><a class="hover:text-brand-700 dark:hover:text-white" href="' . e(str_starts_with($url, '/') ? $url : admin_url($url)) . '">' . e($label) . '</a></li>'
                : '<li class="text-slate-700 dark:text-slate-200 font-medium" aria-current="page">' . e($label) . '</li>';
        }
        $bc .= '</ol></nav>';
    }
    return '<div class="page-header">'
        . '<div class="min-w-0">' . $bc . '<h1 class="page-title">' . e($title) . '</h1>'
        . ($description !== '' ? '<p class="page-subtitle">' . e($description) . '</p>' : '') . '</div>'
        . ($actions !== '' ? '<div class="flex flex-wrap items-center gap-2 shrink-0">' . $actions . '</div>' : '')
        . '</div>';
}

/** Card header row: title + optional subtitle + right side html. */
function card_header(string $title, string $subtitle = '', string $right = ''): string
{
    return '<div class="card-header"><div class="min-w-0"><h2 class="card-title">' . e($title) . '</h2>'
        . ($subtitle !== '' ? '<p class="card-subtitle">' . e($subtitle) . '</p>' : '') . '</div>'
        . ($right !== '' ? '<div class="flex items-center gap-2 shrink-0">' . $right . '</div>' : '') . '</div>';
}

/** Friendly empty state with optional call-to-action html. */
function empty_state(string $icon, string $title, string $text = '', string $cta = ''): string
{
    return '<div class="empty-state">'
        . '<span class="empty-state-icon">' . icon($icon, 'w-7 h-7') . '</span>'
        . '<h3 class="mt-4 text-base font-semibold text-slate-900 dark:text-white">' . e($title) . '</h3>'
        . ($text !== '' ? '<p class="mt-1 text-sm text-slate-500 dark:text-slate-400 max-w-md mx-auto">' . $text . '</p>' : '')
        . ($cta !== '' ? '<div class="mt-5 flex justify-center gap-2">' . $cta . '</div>' : '')
        . '</div>';
}

/** Avatar image or initials bubble. */
function avatar(?string $name, ?string $photo = null, string $size = 'w-9 h-9', string $text = 'text-xs'): string
{
    if ($photo) {
        return '<img src="' . e(upload_url($photo)) . '" alt="' . e($name) . '" class="' . e($size) . ' rounded-full object-cover ring-2 ring-white dark:ring-slate-800 shrink-0" loading="lazy">';
    }
    $palette = ['bg-blue-100 text-blue-700', 'bg-emerald-100 text-emerald-700', 'bg-violet-100 text-violet-700', 'bg-amber-100 text-amber-700', 'bg-rose-100 text-rose-700', 'bg-cyan-100 text-cyan-700'];
    $cls = $palette[abs(crc32((string) $name)) % count($palette)];
    return '<span class="' . e($size) . ' ' . $cls . ' ' . e($text) . ' rounded-full inline-flex items-center justify-center font-semibold shrink-0" aria-hidden="true">' . e(initials($name)) . '</span>';
}

/** Name + subtitle with avatar (common table cell). */
function person_cell(?string $name, ?string $sub = null, ?string $photo = null, ?string $url = null): string
{
    $n = $url ? '<a href="' . e($url) . '" class="font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">' . e($name) . '</a>'
        : '<span class="font-semibold text-slate-900 dark:text-white">' . e($name) . '</span>';
    return '<div class="flex items-center gap-3 min-w-0">' . avatar($name, $photo) . '<div class="min-w-0 leading-tight">' . $n
        . ($sub ? '<div class="text-xs text-slate-500 dark:text-slate-400 truncate">' . e($sub) . '</div>' : '') . '</div></div>';
}

function progress_bar(float $percent, string $color = 'blue', string $height = 'h-2'): string
{
    $p = max(0, min(100, $percent));
    return '<div class="w-full ' . $height . ' rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden" role="progressbar" aria-valuenow="' . round($p) . '" aria-valuemin="0" aria-valuemax="100">'
        . '<div class="' . $height . ' rounded-full ' . ui_color($color)['bar'] . '" style="width:' . $p . '%"></div></div>';
}

/**
 * Server-side tab navigation (links). $tabs = ['overview' => 'Overview', 'fees' => ['Fees', 'indian-rupee']]
 */
function tabs_nav(array $tabs, string $active, string $param = 'tab', array $keepParams = []): string
{
    $html = '<nav class="tabs" aria-label="Tabs">';
    foreach ($tabs as $key => $def) {
        [$label, $ic] = is_array($def) ? array_pad($def, 2, null) : [$def, null];
        $q = array_intersect_key($_GET, array_flip($keepParams));
        $q[$param] = $key;
        $isActive = $key === $active;
        $html .= '<a href="?' . e(http_build_query($q)) . '" class="tab ' . ($isActive ? 'tab-active' : '') . '"' . ($isActive ? ' aria-current="page"' : '') . '>'
            . ($ic ? icon($ic, 'w-4 h-4') : '') . e($label) . '</a>';
    }
    return $html . '</nav>';
}

/** Modal shell. Open with data-modal-open="id" or GIMT.modal.open('id'). Sizes: sm, md, lg, xl, 2xl */
function modal(string $id, string $title, string $body, string $footer = '', string $size = 'md'): string
{
    $sizes = ['sm' => 'max-w-md', 'md' => 'max-w-lg', 'lg' => 'max-w-2xl', 'xl' => 'max-w-4xl', '2xl' => 'max-w-6xl'];
    return '<div class="modal" id="' . e($id) . '" role="dialog" aria-modal="true" aria-labelledby="' . e($id) . '-title" hidden>'
        . '<div class="modal-backdrop" data-modal-close></div>'
        . '<div class="modal-panel ' . ($sizes[$size] ?? $sizes['md']) . '">'
        . '<div class="modal-header"><h2 id="' . e($id) . '-title" class="text-base font-semibold text-slate-900 dark:text-white">' . e($title) . '</h2>'
        . '<button type="button" class="btn-icon" data-modal-close aria-label="Close">' . icon('x', 'w-5 h-5') . '</button></div>'
        . '<div class="modal-body">' . $body . '</div>'
        . ($footer !== '' ? '<div class="modal-footer">' . $footer . '</div>' : '')
        . '</div></div>';
}

/** <option> list from [value => label]. */
function select_options(array $options, $selected = null, ?string $placeholder = null): string
{
    $html = $placeholder !== null ? '<option value="">' . e($placeholder) . '</option>' : '';
    foreach ($options as $value => $label) {
        $sel = (is_array($selected) ? in_array((string) $value, array_map('strval', $selected), true) : (string) $selected === (string) $value) ? ' selected' : '';
        $html .= '<option value="' . e($value) . '"' . $sel . '>' . e($label) . '</option>';
    }
    return $html;
}

/** Description list row used on profile/detail pages. */
function detail_row(string $label, $value, bool $raw = false): string
{
    $v = $raw ? (string) $value : ($value === null || $value === '' ? '<span class="text-slate-400">—</span>' : e($value));
    return '<div class="py-2.5 grid grid-cols-3 gap-3 border-b border-slate-100 dark:border-slate-800 last:border-0">'
        . '<dt class="text-sm text-slate-500 dark:text-slate-400">' . e($label) . '</dt>'
        . '<dd class="col-span-2 text-sm font-medium text-slate-800 dark:text-slate-100 break-words">' . $v . '</dd></div>';
}

/** Activity timeline from rows having description/created_at/user_name. */
function activity_timeline(array $rows, string $emptyText = 'No activity yet.'): string
{
    if (!$rows) {
        return '<p class="text-sm text-slate-500 py-6 text-center">' . e($emptyText) . '</p>';
    }
    $icons = ['create' => ['plus', 'green'], 'update' => ['pencil', 'blue'], 'delete' => ['trash-2', 'red'], 'login' => ['log-in', 'cyan'],
        'logout' => ['log-out', 'slate'], 'export' => ['download', 'purple'], 'import' => ['upload', 'purple'], 'approve' => ['check', 'green'],
        'publish' => ['send', 'green'], 'payment' => ['indian-rupee', 'green']];
    $html = '<ol class="relative space-y-4">';
    foreach ($rows as $r) {
        [$ic, $col] = $icons[$r['action'] ?? ''] ?? ['activity', 'slate'];
        $c = ui_color($col);
        $html .= '<li class="flex gap-3"><span class="mt-0.5 w-8 h-8 rounded-full inline-flex items-center justify-center shrink-0 ' . $c['icon'] . '">' . icon($ic, 'w-4 h-4') . '</span>'
            . '<div class="min-w-0"><p class="text-sm text-slate-800 dark:text-slate-100">' . e($r['description'] ?? '') . '</p>'
            . '<p class="text-xs text-slate-500 dark:text-slate-400">' . e($r['user_name'] ?? 'System') . ' · ' . e(time_ago($r['created_at'] ?? null)) . '</p></div></li>';
    }
    return $html . '</ol>';
}

/** Standalone A4 print page shell with institute letterhead. Use print_layout_start(...) ... print_layout_end(). */
function print_layout_start(string $title, bool $letterhead = true, string $orientation = 'portrait'): void
{
    $name = institute_name();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . ' - ' . e($name) . '</title>'
        . '<link rel="icon" href="' . e(asset('assets/images/favicon.svg')) . '">'
        . '<link rel="stylesheet" href="' . e(asset('assets/css/style.css')) . '">'
        . '<style>@page{size:A4 ' . $orientation . ';margin:12mm}body{background:#eef2f7}@media print{body{background:#fff}.no-print{display:none!important}.print-sheet{box-shadow:none!important;margin:0!important;width:auto!important;min-height:0!important;padding:0!important}}</style>'
        . '</head><body class="font-sans text-slate-800 antialiased">'
        . '<div class="no-print sticky top-0 z-10 bg-white/90 backdrop-blur border-b border-slate-200 px-4 py-2 flex items-center justify-between">'
        . '<span class="text-sm font-semibold text-slate-700">' . e($title) . '</span><div class="flex gap-2">'
        . '<button type="button" onclick="history.length>1?history.back():window.close()" class="btn btn-secondary btn-sm">' . icon('arrow-left', 'w-4 h-4') . 'Back</button>'
        . '<button type="button" onclick="window.print()" class="btn btn-primary btn-sm">' . icon('printer', 'w-4 h-4') . 'Print / Save PDF</button></div></div>'
        . '<div class="print-sheet bg-white mx-auto my-6 shadow-xl ' . ($orientation === 'landscape' ? 'w-[297mm] min-h-[210mm]' : 'w-[210mm] min-h-[297mm]') . ' p-[12mm]">';
    if ($letterhead) {
        echo print_letterhead();
    }
}

function print_letterhead(): string
{
    $addr = setting('address', 'Plot No. 123, Knowledge Park, Greater Noida, Uttar Pradesh 201310, India');
    return '<header class="flex items-center gap-5 pb-4 border-b-2 border-brand-800">'
        . '<img src="' . e(logo_url()) . '" alt="GIMT" class="h-16 w-auto">'
        . '<div class="flex-1 text-right"><p class="font-display text-lg font-extrabold text-brand-900 uppercase leading-tight">' . e(institute_name()) . '</p>'
        . '<p class="text-[11px] text-slate-600">' . e($addr) . '</p>'
        . '<p class="text-[11px] text-slate-600">' . e(setting('phone', '+91 9955446477')) . ' · ' . e(setting('email', 'info@gimt.ac.in')) . ' · ' . e(setting('website', 'www.gimt.ac.in')) . '</p></div></header>';
}

function print_layout_end(bool $autoPrint = false): void
{
    echo '</div>';
    echo '<script src="' . e(asset('assets/vendor/qrcode.js')) . '"></script>';
    echo '<script>document.querySelectorAll("[data-qr]").forEach(function(el){try{var q=qrcode(0,"M");q.addData(el.getAttribute("data-qr"));q.make();el.innerHTML=q.createSvgTag({cellSize:3,margin:0,scalable:true});}catch(e){}});'
        . ($autoPrint ? 'window.addEventListener("load",function(){setTimeout(function(){window.print()},400)});' : '') . '</script>';
    echo '</body></html>';
}
