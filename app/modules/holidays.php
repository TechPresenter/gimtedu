<?php
/** Holidays, vacations, exam breaks and events (Attendance). Holidays & vacations block student attendance marking. */
require_once APP_ROOT . '/app/services/attendance.php';

$types = ['holiday' => 'Holiday', 'vacation' => 'Vacation', 'exam_break' => 'Exam break', 'event' => 'Institute event'];

return [
    'table' => 'holidays',
    'title' => 'Holidays',
    'singular' => 'Holiday',
    'permission' => 'attendance',
    'icon' => 'calendar-x',
    'description' => 'Institute holiday calendar. Attendance cannot be marked on holidays and vacations; exam breaks and events show a warning.',
    'select' => "t.*, s.name AS session_name, DATEDIFF(COALESCE(t.end_date, t.holiday_date), t.holiday_date) + 1 AS days,
                 DATE_FORMAT(t.holiday_date, '%W') AS day_name,
                 CASE WHEN COALESCE(t.end_date, t.holiday_date) < CURDATE() THEN 'completed' WHEN t.holiday_date <= CURDATE() THEN 'ongoing' ELSE 'upcoming' END AS timing",
    'joins' => 'LEFT JOIN academic_sessions s ON s.id = t.academic_session_id',
    'search' => ['t.title', 't.description'],
    'search_placeholder' => 'Search holidays…',
    'order' => 't.holiday_date ASC, t.id ASC',
    'default_sort' => ['key' => 'holiday_date', 'dir' => 'asc'],
    'columns' => [
        ['key' => 'title', 'label' => 'Holiday', 'format' => 'title', 'sub' => 'description', 'sortable' => true],
        ['key' => 'holiday_date', 'label' => 'From', 'format' => 'date', 'sub' => 'day_name', 'sortable' => true],
        ['key' => 'end_date', 'label' => 'To', 'format' => 'date', 'sortable' => true],
        ['key' => 'days', 'label' => 'Days', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true, 'colors' => ['holiday' => 'red', 'vacation' => 'purple', 'exam_break' => 'amber', 'event' => 'blue']],
        ['key' => 'timing', 'label' => 'Status', 'format' => 'badge', 'colors' => ['completed' => 'slate', 'ongoing' => 'green', 'upcoming' => 'blue']],
        ['key' => 'session_name', 'label' => 'Session', 'format' => 'text', 'hidden' => true],
    ],
    'filters' => [
        ['key' => 'type', 'label' => 'Type', 'options' => $types],
        ['key' => 'academic_session_id', 'label' => 'Session', 'source' => 'sessions'],
        ['key' => 'timing', 'label' => 'When', 'sql_map' => [
            'upcoming' => 't.holiday_date > CURDATE()', 'ongoing' => 't.holiday_date <= CURDATE() AND COALESCE(t.end_date, t.holiday_date) >= CURDATE()',
            'completed' => 'COALESCE(t.end_date, t.holiday_date) < CURDATE()',
        ], 'options' => ['upcoming' => 'Upcoming', 'ongoing' => 'Ongoing', 'completed' => 'Completed']],
        ['key' => 'holiday_date', 'label' => 'Date', 'type' => 'daterange'],
    ],
    'fields' => [
        ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'col' => 8, 'maxlength' => 150, 'placeholder' => 'e.g. Diwali Break'],
        ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'default' => 'holiday', 'col' => 4, 'options' => $types],
        ['name' => 'holiday_date', 'label' => 'From date', 'type' => 'date', 'required' => true],
        ['name' => 'end_date', 'label' => 'To date', 'type' => 'date', 'help' => 'Leave empty for a single-day holiday.'],
        ['name' => 'academic_session_id', 'label' => 'Academic session', 'type' => 'select', 'source' => 'sessions', 'default' => current_session_id(), 'help' => 'Detected from the date when left empty.'],
        ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 255, 'placeholder' => 'Shown in the attendance calendar and on the marking screen'],
    ],
    'bulk' => ['delete' => true],
    'import' => true,
    'export' => true,
    'form' => ['size' => 'md'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $from = $data['holiday_date'] ?? ($old['holiday_date'] ?? null);
            $to = array_key_exists('end_date', $data) ? $data['end_date'] : ($old['end_date'] ?? null);
            if ($from && $to && $to < $from) {
                $errors['end_date'] = 'To date must be on or after the from date.';
            } elseif ($from && $to && (strtotime($to) - strtotime($from)) / 86400 > 120) {
                $errors['end_date'] = 'A holiday period cannot be longer than 120 days.';
            }
            $title = $data['title'] ?? ($old['title'] ?? null);
            if ($from && $title && db_value('SELECT COUNT(*) FROM holidays WHERE holiday_date = ? AND title = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$from, $title, $id] : [$from, $title])) {
                $errors['title'] = 'This holiday is already in the calendar for that date.';
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            if (array_key_exists('end_date', $data) && $data['end_date'] && $data['end_date'] === ($data['holiday_date'] ?? $old['holiday_date'] ?? null)) {
                $data['end_date'] = null;
            }
            $from = $data['holiday_date'] ?? ($old['holiday_date'] ?? null);
            if ($from && empty($data['academic_session_id']) && empty($old['academic_session_id'])) {
                $data['academic_session_id'] = db_value('SELECT id FROM academic_sessions WHERE ? BETWEEN start_date AND end_date ORDER BY id DESC LIMIT 1', [$from]) ?: current_session_id();
            }
            return $data;
        },
        'describe' => fn (array $row): string => '"' . ($row['title'] ?? '') . '" (' . format_date($row['holiday_date'] ?? null) . (!empty($row['end_date']) ? ' - ' . format_date($row['end_date']) : '') . ')',
        'summary' => function (string $from, array $args): array {
            $row = db_row("SELECT COUNT(*) AS total, SUM(t.type = 'holiday') AS holidays, SUM(t.type = 'vacation') AS vacations, SUM(t.type = 'exam_break') AS exam_breaks,
                           SUM(t.type = 'event') AS events, SUM(t.holiday_date > CURDATE()) AS upcoming,
                           SUM(CASE WHEN t.type IN ('holiday','vacation') THEN DATEDIFF(COALESCE(t.end_date, t.holiday_date), t.holiday_date) + 1 ELSE 0 END) AS closed_days $from", $args);
            $next = db_row("SELECT title, holiday_date, type FROM holidays WHERE holiday_date >= CURDATE() AND type IN ('holiday','vacation') ORDER BY holiday_date LIMIT 1");
            return array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $row ?: []) + ['next' => $next];
        },
    ],
];
