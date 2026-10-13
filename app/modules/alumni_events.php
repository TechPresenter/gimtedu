<?php
/** Alumni events (brief §23): reunions, homecoming, webinars, chapter meets — with registrations. */
require_once APP_ROOT . '/app/services/alumni.php';

return [
    'table' => 'alumni_events',
    'title' => 'Alumni Events',
    'singular' => 'Alumni event',
    'permission' => 'alumni',
    'icon' => 'calendar-heart',
    'description' => 'Reunions, homecoming, webinars and chapter meets for the alumni network.',
    'select' => "t.*,
                 (SELECT COUNT(*) FROM alumni_event_registrations r WHERE r.event_id = t.id AND r.status <> 'cancelled') AS registrations_count,
                 (SELECT COUNT(*) FROM alumni_event_registrations r WHERE r.event_id = t.id AND r.status = 'attended') AS attended_count",
    'search' => ['t.title', 't.venue', 't.organiser', 't.description'],
    'search_placeholder' => 'Search events, venue, organiser…',
    'order' => "(t.status = 'upcoming' AND t.event_date >= NOW()) DESC, CASE WHEN t.event_date >= NOW() THEN t.event_date END ASC, t.event_date DESC",
    'columns' => [
        ['key' => 'title', 'label' => 'Event', 'format' => 'title', 'sub' => 'venue', 'link' => 'view', 'sortable' => true],
        ['key' => 'type', 'label' => 'Type', 'format' => 'badge', 'hidden' => true, 'sortable' => true,
            'colors' => ['reunion' => 'blue', 'homecoming' => 'navy', 'webinar' => 'cyan', 'chapter_meet' => 'purple', 'networking' => 'green', 'guest_lecture' => 'amber', 'mentorship' => 'green', 'other' => 'slate']],
        ['key' => 'event_date', 'label' => 'Date & time', 'format' => 'datetime', 'sortable' => true],
        ['key' => 'mode', 'label' => 'Mode', 'format' => 'badge', 'hidden' => true, 'sortable' => true, 'colors' => ['in_person' => 'navy', 'online' => 'cyan', 'hybrid' => 'purple']],
        ['key' => 'registrations_count', 'label' => 'Registered', 'format' => 'number', 'align' => 'center', 'sortable' => 'registrations_count'],
        ['key' => 'capacity', 'label' => 'Capacity', 'format' => 'number', 'align' => 'center', 'hidden' => true, 'sortable' => true],
        ['key' => 'attendees_count', 'label' => 'Attended', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'organiser', 'label' => 'Organiser', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'when', 'label' => 'When', 'sql_map' => ['upcoming' => "t.event_date >= NOW() AND t.status <> 'cancelled'", 'past' => 't.event_date < NOW()'],
            'options' => ['upcoming' => 'Upcoming', 'past' => 'Past']],
        ['key' => 'type', 'label' => 'Type', 'options' => alumni_event_types()],
        ['key' => 'mode', 'label' => 'Mode', 'options' => alumni_event_modes()],
        ['key' => 'status', 'label' => 'Status', 'options' => ['upcoming' => 'Upcoming', 'completed' => 'Completed', 'cancelled' => 'Cancelled']],
        ['key' => 'event_date', 'label' => 'Date', 'type' => 'daterange'],
    ],
    'fields' => [
        ['name' => 'title', 'label' => 'Event title', 'type' => 'text', 'required' => true, 'col' => 8, 'maxlength' => 190, 'placeholder' => 'e.g. Homecoming 2026 — Silver Jubilee Reunion'],
        ['name' => 'type', 'label' => 'Event type', 'type' => 'select', 'required' => true, 'default' => 'reunion', 'col' => 4, 'options' => alumni_event_types()],
        ['name' => 'event_date', 'label' => 'Starts', 'type' => 'datetime', 'required' => true, 'col' => 6],
        ['name' => 'end_date', 'label' => 'Ends', 'type' => 'datetime', 'col' => 6],
        ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'required' => true, 'default' => 'in_person', 'col' => 4, 'options' => alumni_event_modes()],
        ['name' => 'venue', 'label' => 'Venue / platform', 'type' => 'text', 'col' => 8, 'maxlength' => 190, 'placeholder' => 'e.g. GIMT Main Auditorium or Zoom'],
        ['name' => 'capacity', 'label' => 'Capacity', 'type' => 'number', 'col' => 4, 'min' => 1, 'max' => 100000, 'help' => 'Leave empty for no limit.'],
        ['name' => 'organiser', 'label' => 'Organiser / host', 'type' => 'text', 'col' => 4, 'maxlength' => 150, 'placeholder' => 'e.g. Alumni Relations Cell'],
        ['name' => 'registration_link', 'label' => 'Registration / joining link', 'type' => 'url', 'col' => 4],
        ['name' => 'description', 'label' => 'Description & agenda', 'type' => 'richtext'],
        ['name' => 'image', 'label' => 'Banner image', 'type' => 'image', 'col' => 6, 'folder' => 'alumni-events'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'upcoming', 'col' => 3,
            'options' => ['upcoming' => 'Upcoming', 'completed' => 'Completed', 'cancelled' => 'Cancelled']],
        ['name' => 'attendees_count', 'label' => 'Attendees', 'type' => 'number', 'col' => 3, 'min' => 0, 'default' => 0, 'edit_only' => true, 'help' => 'Updated from attendance marked on registrations.'],
    ],
    'bulk' => ['delete' => true, 'status' => ['upcoming', 'completed', 'cancelled']],
    'export' => true,
    'import' => false,
    'form' => ['size' => 'xl'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $start = $data['event_date'] ?? ($old['event_date'] ?? null);
            $end = array_key_exists('end_date', $data) ? $data['end_date'] : ($old['end_date'] ?? null);
            $status = $data['status'] ?? ($old['status'] ?? 'upcoming');
            if ($start && $end && strtotime($end) < strtotime($start)) {
                $errors['end_date'] = 'The event cannot end before it starts.';
            }
            if ($start && $status === 'upcoming' && strtotime($start) < strtotime('-1 day') && (isset($data['event_date']) || isset($data['status']))) {
                $errors['event_date'] = 'This date is in the past. Mark past events as completed or cancelled.';
            }
            if ($start && $status === 'completed' && strtotime($start) > time()) {
                $errors['status'] = 'An event can be marked completed only after it has started.';
            }
            if ($id && !empty($data['capacity'])) {
                $registered = alumni_event_counts($id)['registered'];
                if ($registered > (int) $data['capacity']) {
                    $errors['capacity'] = "Capacity cannot be less than the $registered alumni already registered.";
                }
            }
            if (in_array($data['mode'] ?? null, ['online', 'hybrid'], true) && empty($data['registration_link']) && empty($old['registration_link']) && $status === 'upcoming') {
                $errors['registration_link'] = 'Add the joining / registration link for online and hybrid events.';
            }
            return $errors;
        },
        'after_save' => function (int $id, array $data, ?array $old): void {
            if (($data['status'] ?? null) === 'completed' && ($old['status'] ?? null) !== 'completed') {
                alumni_event_sync_attendees($id);
            }
        },
        'describe' => fn (array $row) => '"' . ($row['title'] ?? '') . '"' . (!empty($row['event_date']) ? ' on ' . format_date($row['event_date']) : ''),
    ],
];
