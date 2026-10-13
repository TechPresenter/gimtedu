<?php
/**
 * Footer visitor counter: "Unique Visitors" + "Total Visits" (+ visitors today), and the page-view beacon.
 * Rendered by includes/footer.php via: require_once __DIR__ . '/partials/visitor_counter.php'; echo visitor_counter();
 * Data: app/services/visitors.php (cached 60 s). Hidden entirely when the setting visitor_counter_enabled is off.
 */
require_once __DIR__ . '/../../app/services/visitors.php';

function visitor_counter(array $o = []): string
{
    if (setting('visitor_counter_enabled', '1') !== '1') {
        return '';
    }
    $t = visitors_totals();
    $stat = function (string $icon, int $value, string $label) {
        return '<li class="inline-flex items-center gap-2 rounded-full border border-[#E2DAD0] bg-white px-3 py-1.5 text-xs text-[#4A5568] shadow-sm">'
            . '<span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-[#FDEDE7] text-[#C93C14]" aria-hidden="true">' . icon($icon, 'h-3.5 w-3.5') . '</span>'
            . '<span class="font-semibold tabular-nums text-[#0B2A5B]" data-count="' . $value . '">' . e(number_format($value)) . '</span>'
            . '<span>' . e($label) . '</span></li>';
    };
    $html = '<div class="visitor-counter no-print" aria-label="Website visitors">'
        . '<ul class="flex flex-wrap items-center justify-center gap-2 sm:justify-start">'
        . $stat('users', $t['unique'], 'Unique Visitors')
        . $stat('eye', $t['visits'], 'Total Visits');
    if (($o['today'] ?? true) && $t['today'] > 0) {
        $html .= '<li class="inline-flex items-center gap-1.5 px-1 text-xs text-[#4A5568]">'
            . '<span class="relative inline-flex h-2 w-2" aria-hidden="true"><span class="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-60 motion-safe:animate-ping"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span></span>'
            . '<span class="tabular-nums">' . e(number_format($t['today'])) . '</span> today</li>';
    }
    $html .= '</ul></div>';
    $html .= '<script src="' . e(asset('assets/js/visit.js')) . '" data-endpoint="' . e(base_url('api/visit')) . '" defer></script>';
    return $html;
}
