<?php
/** Events (Communication). Registrations: app/modules/event_registrations.php, actions: api/routes/events.php */
require_once APP_ROOT . '/app/services/communication.php';

$period = "(CASE WHEN t.start_datetime > NOW() THEN 'upcoming' WHEN COALESCE(t.end_datetime, t.start_datetime) < NOW() THEN 'past' ELSE 'ongoing' END)";

return [
    'table' => 'events',
    'title' => 'Events',
    'singular' => 'Event',
    'permission' => 'events',
    'icon' => 'calendar-range',
    'description' => 'Campus events, seminars, workshops and webinars with online registration.',
    'select' => "t.*, $period AS period,
                 (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = t.id AND r.status <> 'cancelled') AS registrations_count,
                 (SELECT COUNT(*) FROM event_registrations r2 WHERE r2.event_id = t.id AND r2.status = 'attended') AS attended_count",
    'search' => ['t.title', 't.venue', 't.organizer', 't.slug'],
    'order' => 't.start_datetime DESC',
    'search_placeholder' => 'Search events by title, venue or organiser…',
    'scopes' => ['period' => $period, 'category' => 't.category', 'status' => 't.status'],
    'columns' => [
        ['key' => 'title', 'label' => 'Event', 'format' => 'title', 'sub' => 'venue', 'sortable' => true, 'link' => 'view'],
        ['key' => 'category', 'label' => 'Category', 'format' => 'badge', 'sortable' => true],
        ['key' => 'start_datetime', 'label' => 'Starts', 'format' => 'datetime', 'sortable' => true],
        ['key' => 'end_datetime', 'label' => 'Ends', 'format' => 'datetime', 'sortable' => true, 'hidden' => true],
        ['key' => 'organizer', 'label' => 'Organiser', 'format' => 'text', 'hidden' => true],
        ['key' => 'registrations_count', 'label' => 'Registrations', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'attended_count', 'label' => 'Checked in', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'max_participants', 'label' => 'Capacity', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'show_on_website', 'label' => 'Website', 'format' => 'boolean', 'hidden' => true],
        ['key' => 'is_featured', 'label' => 'Featured', 'format' => 'boolean', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'title', 'label' => 'Event'], ['key' => 'category', 'label' => 'Category', 'format' => 'badge'], ['key' => 'venue', 'label' => 'Venue'],
        ['key' => 'start_datetime', 'label' => 'Starts', 'format' => 'datetime'], ['key' => 'end_datetime', 'label' => 'Ends', 'format' => 'datetime'],
        ['key' => 'organizer', 'label' => 'Organiser'], ['key' => 'registrations_count', 'label' => 'Registrations', 'format' => 'number'],
        ['key' => 'attended_count', 'label' => 'Checked in', 'format' => 'number'], ['key' => 'max_participants', 'label' => 'Capacity', 'format' => 'number'],
        ['key' => 'registration_enabled', 'label' => 'Registration', 'format' => 'boolean'], ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
    ],
    'filters' => [
        ['key' => 'category', 'label' => 'Category', 'options' => comm_event_categories()],
        ['key' => 'status', 'label' => 'Status', 'options' => ['draft' => 'Draft', 'published' => 'Published', 'completed' => 'Completed', 'cancelled' => 'Cancelled']],
        ['key' => 'registration', 'label' => 'Registration', 'sql_map' => [
            'enabled' => 't.registration_enabled = 1',
            'open' => "t.registration_enabled = 1 AND t.status = 'published' AND COALESCE(t.registration_deadline, t.end_datetime, t.start_datetime) >= NOW()",
            'disabled' => 't.registration_enabled = 0',
        ], 'options' => ['enabled' => 'Registration enabled', 'open' => 'Open now', 'disabled' => 'No registration']],
        ['key' => 'show_on_website', 'label' => 'On website', 'type' => 'boolean'],
        ['key' => 'start_datetime', 'label' => 'Date', 'type' => 'daterange'],
    ],
    'fields' => [
        ['type' => 'section', 'label' => 'Event details'],
        ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'col' => 12, 'maxlength' => 255, 'placeholder' => 'e.g. Innovate 2026 — Annual Tech Fest'],
        ['name' => 'slug', 'label' => 'URL slug', 'type' => 'slug', 'slug_from' => 'title', 'col' => 6, 'maxlength' => 190, 'help' => 'Public page: /events/{slug}'],
        ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'required' => true, 'default' => 'academic', 'col' => 3, 'options' => comm_event_categories()],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'published', 'col' => 3,
            'options' => ['draft' => 'Draft', 'published' => 'Published', 'completed' => 'Completed', 'cancelled' => 'Cancelled']],
        ['name' => 'excerpt', 'label' => 'Short summary', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 500, 'col' => 12, 'help' => 'Shown on event cards (max 500 characters).'],
        ['name' => 'description', 'label' => 'Description', 'type' => 'richtext', 'col' => 12],
        ['name' => 'image', 'label' => 'Banner image', 'type' => 'image', 'folder' => 'events', 'col' => 12],
        ['type' => 'section', 'label' => 'When & where'],
        ['name' => 'start_datetime', 'label' => 'Starts', 'type' => 'datetime', 'required' => true, 'col' => 6],
        ['name' => 'end_datetime', 'label' => 'Ends', 'type' => 'datetime', 'col' => 6],
        ['name' => 'venue', 'label' => 'Venue', 'type' => 'text', 'maxlength' => 190, 'col' => 6, 'placeholder' => 'Main Auditorium'],
        ['name' => 'organizer', 'label' => 'Organiser', 'type' => 'text', 'maxlength' => 150, 'col' => 6, 'placeholder' => 'Department of Management Studies'],
        ['name' => 'contact_email', 'label' => 'Contact e-mail', 'type' => 'email', 'col' => 6],
        ['name' => 'registration_link', 'label' => 'External registration link', 'type' => 'url', 'col' => 6, 'help' => 'Optional — use instead of on-site registration.'],
        ['type' => 'section', 'label' => 'Registration & visibility'],
        ['name' => 'registration_enabled', 'label' => 'Accept registrations', 'type' => 'toggle', 'col' => 4, 'default' => true],
        ['name' => 'max_participants', 'label' => 'Capacity', 'type' => 'number', 'min' => 1, 'max' => 100000, 'col' => 4, 'help' => 'Leave empty for unlimited seats.'],
        ['name' => 'registration_deadline', 'label' => 'Registration deadline', 'type' => 'datetime', 'col' => 4],
        ['name' => 'show_on_website', 'label' => 'Show on website', 'type' => 'toggle', 'col' => 4, 'default' => true],
        ['name' => 'is_featured', 'label' => 'Featured event', 'type' => 'toggle', 'col' => 4, 'default' => false],
    ],
    'bulk' => ['delete' => true, 'status' => ['draft', 'published', 'completed', 'cancelled']],
    'form' => ['size' => 'xl'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $pick = fn (string $k) => array_key_exists($k, $data) ? $data[$k] : ($old[$k] ?? null);
            $start = $pick('start_datetime');
            $end = $pick('end_datetime');
            $deadline = $pick('registration_deadline');
            if ($start && $end && strtotime((string) $end) < strtotime((string) $start)) {
                $errors['end_datetime'] = 'The event cannot end before it starts.';
            }
            if ($deadline && strtotime((string) $deadline) > strtotime((string) ($end ?: $start))) {
                $errors['registration_deadline'] = 'Registration must close before the event ends.';
            }
            $cap = $pick('max_participants');
            if ($id && $cap) {
                $taken = (int) db_value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status <> 'cancelled'", [$id]);
                if ($taken > (int) $cap) {
                    $errors['max_participants'] = "There are already $taken registrations — capacity cannot be lower than that.";
                }
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            if ($id === null || array_key_exists('slug', $data)) {
                $slug = ($data['slug'] ?? null) ?: ($old['slug'] ?? null) ?: (string) ($data['title'] ?? $old['title'] ?? 'event');
                $data['slug'] = comm_unique_slug('events', $slug, $id);
            }
            if (isset($data['description'])) {
                $data['description'] = comm_restore_placeholders((string) $data['description']);
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $n = (int) db_value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status <> 'cancelled'", [$id]);
            if ($n) {
                return '"' . str_limit((string) $row['title'], 60) . '" has ' . $n . ' registration' . ($n === 1 ? '' : 's') . '. Mark it cancelled or completed instead of deleting it.';
            }
            return null;
        },
        'after_delete' => function (int $id) {
            comm_queue_cancel(['related_type' => 'event', 'related_id' => $id]);
        },
        'transform_row' => function (array $r): array {
            $r['category_label'] = comm_event_categories()[$r['category']] ?? label_from_key((string) $r['category']);
            return $r;
        },
        'describe' => fn (array $r) => '"' . str_limit((string) ($r['title'] ?? ''), 80) . '"',
    ],
];
