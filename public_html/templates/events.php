<?php
/**
 * Events list (/events/ - canonical; /events-page/ 301s here): upcoming / past tabs, type and category filters, pagination.
 * Awareness days are listed only when that filter is chosen (they have their own calendar).
 * /events/<slug>/ (and /ics/, /join/) is the event page: handled by event_detail.php.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
if (route_param() !== '') {
    require __DIR__ . '/event_detail.php';
    return;
}
$when = ($_GET['when'] ?? '') === 'past' ? 'past' : 'upcoming';
$type = get_str('type');
if (!isset(EVENT_TYPES[$type])) {
    $type = '';
}
$category = get_str('category') !== '' ? program_category_by_slug(get_str('category')) : null;
$now = date('Y-m-d H:i:s');
$where = "status IN ('published','cancelled') AND COALESCE(end_datetime, start_datetime) " . ($when === 'past' ? '<' : '>=') . ' ?';
$params = [$now];
if ($type !== '') {
    $where .= ' AND event_type = ?';
    $params[] = $type;
} else {
    $where .= " AND event_type <> 'awareness_day'";
}
if ($category) {
    $where .= ' AND category_id = ?';
    $params[] = (int) $category['id'];
}
$total = (int) db_value("SELECT COUNT(*) FROM events WHERE $where", $params);
$p = paginate($total, 9);
$events = db_rows("SELECT e.*, (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status IN (" . programhub_seat_status_sql() . ")) AS registered
                   FROM events e WHERE $where ORDER BY start_datetime " . ($when === 'past' ? 'DESC' : 'ASC') . ", id
                   LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
if (function_exists('seo_image_alt_prime')) {
    seo_image_alt_prime(array_column($events, 'image'));   // one media query for the card images
}
$typeCounts = [];
foreach (db_rows("SELECT event_type, COUNT(*) AS n FROM events WHERE status IN ('published','cancelled') AND COALESCE(end_datetime, start_datetime) " . ($when === 'past' ? '<' : '>=') . ' ? GROUP BY event_type', [$now]) as $r) {
    $typeCounts[$r['event_type']] = (int) $r['n'];
}

$metaTitle = null;
if ($type !== '' || $when === 'past' || $category) {
    $metaTitle = ($when === 'past' ? 'Past ' : 'Upcoming ') . ($type !== '' ? mb_strtolower(event_type_plural($type)) : 'events') . ($category ? ': ' . $category['title'] : '');
}
page_header($page, ['noindex' => $p['page'] > 1 || $type !== '' || $category, 'meta_title' => $metaTitle ?? ($page['meta_title'] ?: null),
    'canonical' => abs_page_url($page['slug'], array_filter(['when' => $when === 'past' ? 'past' : '', 'type' => $type, 'category' => $category['slug'] ?? '']))]);
render_sections($page, function () use ($page, $events, $p, $when, $type, $typeCounts, $category) {
    $base = function (array $q) use ($page, $category): string {
        return page_url($page['slug'], array_filter($q + ['category' => $category['slug'] ?? ''])) . '#events';
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
        if (!$n && $type !== $key && !in_array($key, ['event', 'workshop', 'seminar', 'awareness_day'], true)) {
            continue;   // only types that have events (plus the main ones) get a filter button
        }
        echo '<a class="filter-btn' . ($type === $key ? ' is-active' : '') . '" href="' . e($base(['when' => $when === 'past' ? 'past' : '', 'type' => $key])) . '"' . ($type === $key ? ' aria-current="page"' : '') . '>'
            . e(event_type_plural($key)) . ($n ? ' <span class="count">' . $n . '</span>' : '') . '</a>';
    }
    echo '</nav>';
    $areas = program_categories_all();
    if ($areas) {
        // programme area: an explicit "Show" button (no submit on change, so keyboard users are not moved away)
        echo '<form class="ph-area-form" method="get" action="' . e(page_url($page['slug'])) . '#events">'
            . ($when === 'past' ? '<input type="hidden" name="when" value="past">' : '') . ($type !== '' ? '<input type="hidden" name="type" value="' . e($type) . '">' : '')
            . '<label for="ev-area">Programme area</label><select id="ev-area" name="category"><option value="">All areas</option>';
        foreach ($areas as $a) {
            echo '<option value="' . e($a['slug']) . '"' . ($category && $category['slug'] === $a['slug'] ? ' selected' : '') . '>' . e($a['title']) . '</option>';
        }
        echo '</select><button class="btn btn-outline btn-sm" type="submit">Show</button></form>';
    }
    echo '</div>';
    if ($category) {
        echo '<p class="ph-filter-note">' . icon('filter') . 'Showing ' . e($category['title']) . ' only. <a href="' . e(page_url($page['slug'], array_filter(['when' => $when === 'past' ? 'past' : '', 'type' => $type]))) . '#events">Show all categories</a> · <a href="' . e(program_category_url($category)) . '">About this programme</a></p>';
    }

    if (!$events) {
        if ($when === 'upcoming') {
            echo '<div class="empty-state">' . icon('calendar') . '<p>No ' . ($type !== '' ? e(mb_strtolower(event_type_plural($type))) : 'events') . ' are scheduled right now. New workshops, sessions and events are announced here — register your interest on a programme page or get our newsletter.</p>'
                . '<div class="btn-row center"><a class="btn btn-outline" href="' . e(page_url('awareness-calendar')) . '">' . icon('flag') . 'Awareness calendar</a>'
                . '<a class="btn btn-ghost" href="' . e(page_url('newsletter')) . '">' . icon('mail') . 'Get updates</a></div></div>';
        } else {
            echo empty_state('Past events will be listed here after they have taken place.', 'See upcoming events', '/events/');
        }
    } else {
        echo '<div class="card-grid">';
        foreach ($events as $i => $ev) {
            echo $ev['event_type'] === 'awareness_day' ? event_card($ev, $i) : programhub_event_card($ev, $i);
        }
        echo '</div>' . pagination_links($p);
    }
    echo '</div></section>';
}, 'before');
page_footer();
