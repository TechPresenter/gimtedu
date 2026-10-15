<?php
/**
 * Awareness calendar (/awareness-calendar/?month=YYYY-MM): month grid (table semantics) on larger
 * screens, list view on phones, with published events and awareness days of the month.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
if (route_param() !== '') {
    not_found();
}

// ---------------------------------------------------------------------
// Month and navigation bounds (finite: first listed month .. 12 months ahead / last listed month)
// ---------------------------------------------------------------------
$currentMonth = date('Y-m');
$bounds = db_row("SELECT MIN(start_datetime) AS mn, MAX(start_datetime) AS mx FROM events WHERE status IN ('published','cancelled')");
$minMonth = min($currentMonth, $bounds['mn'] ? date('Y-m', strtotime($bounds['mn'])) : $currentMonth);
$maxMonth = max(date('Y-m', strtotime('first day of +12 months')), $bounds['mx'] ? date('Y-m', strtotime($bounds['mx'])) : $currentMonth);
$month = $currentMonth;
$requested = (string) ($_GET['month'] ?? '');
if (preg_match('/^(\d{4})-(\d{2})$/', $requested, $m) && checkdate((int) $m[2], 1, (int) $m[1])) {
    $month = max($minMonth, min($maxMonth, $requested));
}
$monthStart = strtotime($month . '-01 00:00:00');
$daysInMonth = (int) date('t', $monthStart);
$monthEnd = strtotime($month . '-' . $daysInMonth . ' 23:59:59');
$prevMonth = date('Y-m', strtotime('-1 month', $monthStart));
$nextMonth = date('Y-m', strtotime('+1 month', $monthStart));
$monthLabel = date('F Y', $monthStart);

$events = db_rows("SELECT * FROM events WHERE status IN ('published','cancelled') AND start_datetime <= ? AND COALESCE(end_datetime, start_datetime) >= ?
                   ORDER BY (event_type = 'awareness_day') DESC, start_datetime, id",
    [date('Y-m-d H:i:s', $monthEnd), date('Y-m-d H:i:s', $monthStart)]);

// Events per day of this month (multi-day events appear on each day)
$byDay = [];
foreach ($events as $ev) {
    $from = max($monthStart, strtotime(date('Y-m-d', strtotime($ev['start_datetime']))));
    $to = min($monthEnd, event_end_ts($ev));
    for ($d = $from, $guard = 0; $d <= $to && $guard < 31; $d = strtotime('+1 day', $d), $guard++) {
        $byDay[(int) date('j', $d)][] = $ev;
    }
}
ksort($byDay);
$typesPresent = array_unique(array_column($events, 'event_type'));
$involvePage = get_page('awareness-campaign') ? 'awareness-campaign' : 'contact';
$monthUrl = function (string $ym) use ($page, $currentMonth): string {
    return page_url($page['slug'], $ym === $currentMonth ? [] : ['month' => $ym]) . '#calendar';
};

page_header($page, ['noindex' => $month !== $currentMonth, 'meta_title' => $month !== $currentMonth ? $page['title'] . ' - ' . $monthLabel : ($page['meta_title'] ?: null)]);
render_sections($page, function () use ($page, $month, $currentMonth, $minMonth, $maxMonth, $monthStart, $daysInMonth, $prevMonth, $nextMonth, $monthLabel, $events, $byDay, $typesPresent, $involvePage, $monthUrl) {
    $todayDay = date('Y-m') === $month ? (int) date('j') : 0;
    echo '<section class="section calendar-section" id="calendar"><div class="container">';

    // Toolbar: previous / month / next / jump
    echo '<div class="cal-toolbar">';
    echo '<div class="cal-nav">';
    echo $prevMonth >= $minMonth
        ? '<a class="btn btn-ghost btn-sm cal-prev" href="' . e($monthUrl($prevMonth)) . '" rel="nofollow">' . icon('chevron-left') . '<span>' . e(date('M Y', strtotime($prevMonth . '-01'))) . '</span></a>'
        : '<span class="btn btn-ghost btn-sm cal-prev is-disabled" aria-disabled="true">' . icon('chevron-left') . '<span>' . e(date('M Y', strtotime($prevMonth . '-01'))) . '</span></span>';
    echo '<h2 class="cal-title" aria-live="polite">' . e($monthLabel) . '</h2>';
    echo $nextMonth <= $maxMonth
        ? '<a class="btn btn-ghost btn-sm cal-next" href="' . e($monthUrl($nextMonth)) . '" rel="nofollow"><span>' . e(date('M Y', strtotime($nextMonth . '-01'))) . '</span>' . icon('chevron-right') . '</a>'
        : '<span class="btn btn-ghost btn-sm cal-next is-disabled" aria-disabled="true"><span>' . e(date('M Y', strtotime($nextMonth . '-01'))) . '</span>' . icon('chevron-right') . '</span>';
    echo '</div>';
    echo '<form class="cal-jump" method="get" action="' . e(page_url($page['slug'])) . '#calendar"><label for="cal-month">Go to</label><select id="cal-month" name="month">';
    for ($t = strtotime($minMonth . '-01'), $guard = 0; date('Y-m', $t) <= $maxMonth && $guard < 120; $t = strtotime('+1 month', $t), $guard++) {
        $ym = date('Y-m', $t);
        echo '<option value="' . e($ym) . '"' . ($ym === $month ? ' selected' : '') . '>' . e(date('F Y', $t)) . '</option>';
    }
    echo '</select><button class="btn btn-primary btn-sm" type="submit">Show</button>';
    if ($month !== $currentMonth) {
        echo '<a class="btn btn-ghost btn-sm" href="' . e($monthUrl($currentMonth)) . '" rel="nofollow">This month</a>';
    }
    echo '</form></div>';

    // Legend
    echo '<ul class="cal-legend" aria-label="Legend">';
    foreach (EVENT_TYPES as $key => $label) {
        if ($key === 'awareness_day' || in_array($key, $typesPresent, true)) {
            echo '<li><span class="cal-swatch t-' . e($key) . '" aria-hidden="true"></span>' . e($key === 'awareness_day' ? 'Awareness day' : 'Our ' . mb_strtolower($label) . 's') . '</li>';
        }
    }
    echo '<li><span class="cal-swatch t-today" aria-hidden="true"></span>Today</li></ul>';

    // Month grid (table semantics; hidden on small screens where the list view is used)
    $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    echo '<div class="cal-grid-wrap"><table class="cal-table"><caption class="sr-only">Events and awareness days in ' . e($monthLabel) . '</caption><thead><tr>';
    foreach ($weekdays as $wd) {
        echo '<th scope="col"><abbr title="' . $wd . '">' . substr($wd, 0, 3) . '</abbr></th>';
    }
    echo '</tr></thead><tbody><tr>';
    $lead = (int) date('w', $monthStart);
    for ($i = 0; $i < $lead; $i++) {
        echo '<td class="is-out"></td>';
    }
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $col = ($lead + $day - 1) % 7;
        if ($col === 0 && $day > 1) {
            echo '</tr><tr>';
        }
        $items = $byDay[$day] ?? [];
        $cls = trim(($day === $todayDay ? 'is-today ' : '') . ($items ? 'has-items' : '') . ($col === 0 ? ' is-sun' : ''));
        echo '<td' . ($cls !== '' ? ' class="' . $cls . '"' : '') . '><span class="cal-num">' . $day . ($day === $todayDay ? '<span class="sr-only"> (today)</span>' : '') . '</span>';
        if ($items) {
            echo '<ul class="cal-items">';
            foreach (array_slice($items, 0, 3) as $ev) {
                echo '<li><a class="cal-chip t-' . e($ev['event_type']) . ($ev['status'] === 'cancelled' ? ' is-cancelled' : '') . '" href="' . e(event_url($ev['slug'])) . '" title="' . e($ev['title']) . '">'
                    . e(event_short_title($ev)) . ($ev['status'] === 'cancelled' ? ' (cancelled)' : '') . '</a></li>';
            }
            if (count($items) > 3) {
                echo '<li class="cal-more">+' . (count($items) - 3) . ' more</li>';
            }
            echo '</ul>';
        }
        echo '</td>';
    }
    $trail = (7 - ($lead + $daysInMonth) % 7) % 7;
    for ($i = 0; $i < $trail; $i++) {
        echo '<td class="is-out"></td>';
    }
    echo '</tr></tbody></table></div>';

    // List view (all screens): details of this month's items
    echo '<div class="cal-month-list"><h2 class="h3">' . ($events ? 'In ' . e($monthLabel) : 'Nothing listed for ' . e($monthLabel)) . '</h2>';
    if ($events) {
        echo '<ol class="cal-list">';
        foreach ($byDay as $day => $items) {
            $ts = strtotime($month . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT));
            echo '<li class="cal-list-day' . ($day === $todayDay ? ' is-today' : '') . '">' . content_date_badge(date('Y-m-d', $ts), false)
                . '<div class="cal-list-items"><p class="cal-list-date">' . e(date('l, d F Y', $ts)) . ($day === $todayDay ? ' · <strong>Today</strong>' : '') . '</p>';
            foreach ($items as $ev) {
                $aw = $ev['event_type'] === 'awareness_day';
                echo '<article class="cal-entry t-' . e($ev['event_type']) . '">'
                    . '<span class="ev-chip ev-type-' . e($ev['event_type']) . '">' . icon(EVENT_TYPE_ICONS[$ev['event_type']] ?? 'calendar') . e(event_type_label($ev['event_type'])) . '</span>'
                    . ($ev['status'] === 'cancelled' ? ' <span class="badge badge-red">Cancelled</span>' : '')
                    . '<h3><a href="' . e(event_url($ev['slug'])) . '">' . e(event_short_title($ev)) . '</a></h3>'
                    . '<p>' . e(excerpt((string) $ev['summary'], 220)) . '</p>'
                    . (!$aw ? '<p class="cal-entry-meta"><span>' . icon('clock') . e(event_when($ev)) . '</span><span>' . icon(event_is_online($ev) ? 'monitor' : 'map-pin') . e($ev['location']) . '</span></p>' : '')
                    . '<p class="cal-entry-links"><a class="link-arrow" href="' . e(event_url($ev['slug'])) . '">' . ($aw ? 'How to take part' : 'Event details') . icon('arrow-right') . '<span class="sr-only">: ' . e($ev['title']) . '</span></a>'
                    . ($aw ? '<a class="link-arrow" href="' . e(page_url($involvePage)) . '">Organise an awareness activity with us' . icon('arrow-right') . '</a>' : '')
                    . '</p></article>';
            }
            echo '</div></li>';
        }
        echo '</ol>';
    } else {
        $next = awareness_days(4, date('Y-m-d H:i:s', max(time(), $monthStart)));
        if ($next) {
            echo '<p class="muted">No awareness days or events are listed for this month. Coming up next:</p><ul class="aw-list aw-list-compact">';
            foreach ($next as $i => $ev) {
                echo awareness_day_html($ev, $i);
            }
            echo '</ul>';
            $nextMonthWithItems = date('Y-m', strtotime($next[0]['start_datetime']));
            if ($nextMonthWithItems <= $maxMonth) {
                echo '<p><a class="btn btn-outline btn-sm" href="' . e($monthUrl($nextMonthWithItems)) . '" rel="nofollow">' . icon('calendar') . 'Go to ' . e(date('F Y', strtotime($nextMonthWithItems . '-01'))) . '</a></p>';
            }
        } else {
            echo empty_state('Awareness days and events will be added to this calendar soon.', 'See our events', '/events-page/');
        }
    }
    echo '</div>';

    echo '<div class="aw-cta mt-4"><span class="icon-circle gold">' . icon('megaphone') . '</span><div><h3>Organise an awareness activity with us</h3>'
        . '<p>Plan a talk, rally, drive or workshop in your school, college, office or neighbourhood around an awareness day. Our team and volunteers can help you prepare.</p></div>'
        . '<a class="btn btn-primary" href="' . e(page_url($involvePage)) . '">' . ($involvePage === 'contact' ? 'Contact us' : 'Get started') . icon('arrow-right') . '</a></div>';
    echo '</div></section>';
}, 'before');
page_footer();
