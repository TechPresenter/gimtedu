<?php
/**
 * Events list (/events-page/): upcoming / past tabs, type filter, pagination.
 * Awareness days are listed only when that filter is chosen (they have their own calendar).
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
if (route_param() !== '') {
    not_found();
}
$when = ($_GET['when'] ?? '') === 'past' ? 'past' : 'upcoming';
$type = get_str('type');
if (!isset(EVENT_TYPES[$type])) {
    $type = '';
}
$now = date('Y-m-d H:i:s');
$where = "status IN ('published','cancelled') AND COALESCE(end_datetime, start_datetime) " . ($when === 'past' ? '<' : '>=') . ' ?';
$params = [$now];
if ($type !== '') {
    $where .= ' AND event_type = ?';
    $params[] = $type;
} else {
    $where .= " AND event_type <> 'awareness_day'";
}
$total = (int) db_value("SELECT COUNT(*) FROM events WHERE $where", $params);
$p = paginate($total, 9);
$events = db_rows("SELECT * FROM events WHERE $where ORDER BY start_datetime " . ($when === 'past' ? 'DESC' : 'ASC') . ", id
                   LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$typeCounts = [];
foreach (db_rows("SELECT event_type, COUNT(*) AS n FROM events WHERE status IN ('published','cancelled') AND COALESCE(end_datetime, start_datetime) " . ($when === 'past' ? '<' : '>=') . ' ? GROUP BY event_type', [$now]) as $r) {
    $typeCounts[$r['event_type']] = (int) $r['n'];
}

$metaTitle = null;
if ($type !== '' || $when === 'past') {
    $typeWords = ['' => 'events', 'event' => 'community events', 'drive' => 'drives', 'camp' => 'camps', 'webinar' => 'webinars', 'awareness_day' => 'awareness days'];
    $metaTitle = ($when === 'past' ? 'Past ' : 'Upcoming ') . $typeWords[$type];
}
page_header($page, ['noindex' => $p['page'] > 1 || $type !== '', 'meta_title' => $metaTitle ?? ($page['meta_title'] ?: null)]);
render_sections($page, function () use ($page, $events, $p, $when, $type, $typeCounts) {
    $base = function (array $q) use ($page): string {
        return page_url($page['slug'], array_filter($q)) . '#events';
    };
    echo '<section class="section events-section" id="events"><div class="container">';
    echo '<h2 class="sr-only">' . ($when === 'past' ? 'Past events' : 'Upcoming events') . '</h2>';
    echo '<div class="list-toolbar">';
    echo '<nav class="seg-tabs" aria-label="Upcoming or past events">'
        . '<a href="' . e($base(['type' => $type])) . '"' . ($when === 'upcoming' ? ' class="is-active" aria-current="page"' : '') . '>' . icon('calendar') . 'Upcoming</a>'
        . '<a href="' . e($base(['when' => 'past', 'type' => $type])) . '"' . ($when === 'past' ? ' class="is-active" aria-current="page"' : '') . '>' . icon('refresh') . 'Past events</a>'
        . '<a href="' . e(page_url('awareness-calendar')) . '">' . icon('flag') . 'Awareness calendar</a></nav>';
    echo '<nav class="filter-bar list-filter" aria-label="Event type">';
    echo '<a class="filter-btn' . ($type === '' ? ' is-active' : '') . '" href="' . e($base(['when' => $when === 'past' ? 'past' : ''])) . '"' . ($type === '' ? ' aria-current="page"' : '') . '>All types</a>';
    foreach (EVENT_TYPES as $key => $label) {
        $n = $typeCounts[$key] ?? 0;
        echo '<a class="filter-btn' . ($type === $key ? ' is-active' : '') . '" href="' . e($base(['when' => $when === 'past' ? 'past' : '', 'type' => $key])) . '"' . ($type === $key ? ' aria-current="page"' : '') . '>'
            . e($key === 'awareness_day' ? 'Awareness days' : $label . 's') . ($n ? ' <span class="count">' . $n . '</span>' : '') . '</a>';
    }
    echo '</nav></div>';

    if (!$events) {
        if ($when === 'upcoming') {
            echo '<div class="empty-state">' . icon('calendar') . '<p>No ' . ($type !== '' ? e(mb_strtolower($type === 'awareness_day' ? 'awareness days' : EVENT_TYPES[$type] . 's')) : 'events') . ' are scheduled right now. New camps, drives and programmes are announced here and in our newsletter.</p>'
                . '<div class="btn-row center"><a class="btn btn-outline" href="' . e(page_url('awareness-calendar')) . '">' . icon('flag') . 'Awareness calendar</a>'
                . '<a class="btn btn-ghost" href="' . e(page_url('newsletter')) . '">' . icon('mail') . 'Get updates</a></div></div>';
        } else {
            echo empty_state('Past events will be listed here after they have taken place.', 'See upcoming events', '/events-page/');
        }
    } else {
        echo '<div class="card-grid">';
        foreach ($events as $i => $ev) {
            echo event_card($ev, $i);
        }
        echo '</div>' . pagination_links($p);
    }
    echo '</div></section>';
}, 'before');
page_footer();
