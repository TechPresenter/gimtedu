<?php
/** Placement: pre-placement trainings, workshops, mock tests & mock interviews (participants via /api/placement/trainings/{id}/participants). */
require_once APP_ROOT . '/app/services/placement.php';

return [
    'table' => 'placement_trainings',
    'title' => 'Trainings & Mock Tests',
    'singular' => 'Training',
    'permission' => 'placement',
    'icon' => 'presentation',
    'description' => 'Aptitude & soft-skill trainings, workshops, mock tests and mock interviews.',
    'select' => "t.*, p.short_name AS program_short,
                 CASE WHEN p.id IS NULL THEN 'All programs' WHEN t.semester_no IS NULL THEN p.short_name ELSE CONCAT(p.short_name, ' · Sem ', t.semester_no) END AS audience,
                 (SELECT SUM(tp.attended) FROM placement_training_participants tp WHERE tp.training_id = t.id) AS attended_count,
                 (SELECT ROUND(AVG(tp.score), 1) FROM placement_training_participants tp WHERE tp.training_id = t.id) AS avg_score,
                 CASE WHEN t.end_date IS NULL OR t.end_date = t.start_date THEN NULL ELSE DATEDIFF(t.end_date, t.start_date) + 1 END AS days",
    'joins' => 'LEFT JOIN programs p ON p.id = t.program_id',
    'search' => ['t.title', 't.trainer', 't.venue', 'p.short_name'],
    'search_placeholder' => 'Search title, trainer or venue…',
    'order' => 't.start_date DESC, t.id DESC',
    'columns' => [
        ['key' => 'title', 'label' => 'Session', 'format' => 'title', 'sub' => 'trainer', 'link' => 'view', 'sortable' => true],
        ['key' => 'type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true,
            'colors' => ['training' => 'blue', 'mock_test' => 'purple', 'mock_interview' => 'cyan', 'workshop' => 'amber', 'seminar' => 'slate', 'certification' => 'green']],
        ['key' => 'mode', 'label' => 'Mode', 'format' => 'badge', 'hidden' => true, 'colors' => ['offline' => 'navy', 'online' => 'cyan', 'hybrid' => 'purple']],
        ['key' => 'audience', 'label' => 'Audience', 'format' => 'text', 'sortable' => 'audience', 'hidden' => true],
        ['key' => 'start_date', 'label' => 'Starts', 'format' => 'date', 'sortable' => true],
        ['key' => 'end_date', 'label' => 'Ends', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'duration_hours', 'label' => 'Hours', 'format' => 'number', 'align' => 'center', 'hidden' => true],
        ['key' => 'venue', 'label' => 'Venue', 'format' => 'text', 'hidden' => true],
        ['key' => 'participants_count', 'label' => 'Participants', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'attended_count', 'label' => 'Attended', 'format' => 'number', 'align' => 'center', 'sortable' => 'attended_count', 'hidden' => true],
        ['key' => 'avg_score', 'label' => 'Avg score', 'format' => 'text', 'align' => 'center', 'sortable' => 'avg_score'],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'title', 'label' => 'Title'], ['key' => 'type', 'label' => 'Type', 'format' => 'badge'], ['key' => 'mode', 'label' => 'Mode', 'format' => 'badge'],
        ['key' => 'trainer', 'label' => 'Trainer'], ['key' => 'audience', 'label' => 'Audience'], ['key' => 'start_date', 'label' => 'Start', 'format' => 'date'],
        ['key' => 'end_date', 'label' => 'End', 'format' => 'date'], ['key' => 'duration_hours', 'label' => 'Hours', 'format' => 'number'], ['key' => 'venue', 'label' => 'Venue'],
        ['key' => 'max_marks', 'label' => 'Max marks', 'format' => 'number'], ['key' => 'participants_count', 'label' => 'Participants', 'format' => 'number'],
        ['key' => 'attended_count', 'label' => 'Attended', 'format' => 'number'], ['key' => 'avg_score', 'label' => 'Average score'], ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
    ],
    'filters' => [
        ['key' => 'type', 'label' => 'Type', 'options' => placement_training_types()],
        ['key' => 'status', 'label' => 'Status', 'options' => ['scheduled' => 'Scheduled', 'ongoing' => 'Ongoing', 'completed' => 'Completed', 'cancelled' => 'Cancelled']],
        ['key' => 'mode', 'label' => 'Mode', 'options' => ['offline' => 'Offline', 'online' => 'Online', 'hybrid' => 'Hybrid']],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs'],
        ['key' => 'when', 'label' => 'When', 'options' => ['upcoming' => 'Upcoming', 'past' => 'Past', 'this_month' => 'This month'],
            'sql_map' => ['upcoming' => 't.start_date >= CURDATE()', 'past' => 'COALESCE(t.end_date, t.start_date) < CURDATE()',
                'this_month' => "t.start_date BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())"]],
        ['key' => 'start_date', 'label' => 'Dates', 'type' => 'daterange'],
    ],
    'fields' => [
        ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'maxlength' => 190, 'col' => 8, 'placeholder' => 'e.g. Quantitative Aptitude Bootcamp'],
        ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'default' => 'training', 'col' => 4, 'options' => placement_training_types()],
        ['name' => 'trainer', 'label' => 'Trainer / agency', 'type' => 'text', 'maxlength' => 150, 'col' => 8],
        ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'required' => true, 'default' => 'offline', 'col' => 4, 'options' => ['offline' => 'Offline (on campus)', 'online' => 'Online', 'hybrid' => 'Hybrid']],
        ['name' => 'program_id', 'label' => 'Program', 'type' => 'select', 'source' => 'programs', 'col' => 8, 'placeholder' => 'All programs'],
        ['name' => 'semester_no', 'label' => 'Semester', 'type' => 'number', 'min' => 1, 'max' => 12, 'col' => 4],
        ['name' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'required' => true, 'col' => 4],
        ['name' => 'end_date', 'label' => 'End date', 'type' => 'date', 'col' => 4],
        ['name' => 'duration_hours', 'label' => 'Duration (hours)', 'type' => 'decimal', 'step' => 0.5, 'min' => 0.5, 'max' => 2000, 'col' => 4],
        ['name' => 'venue', 'label' => 'Venue / link', 'type' => 'text', 'maxlength' => 150, 'col' => 8],
        ['name' => 'max_marks', 'label' => 'Maximum marks', 'type' => 'decimal', 'step' => 0.5, 'min' => 1, 'max' => 1000, 'col' => 4, 'help' => 'For mock tests / assessments.'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'scheduled', 'col' => 6,
            'options' => ['scheduled' => 'Scheduled', 'ongoing' => 'Ongoing', 'completed' => 'Completed', 'cancelled' => 'Cancelled']],
        ['name' => 'description', 'label' => 'Description / syllabus', 'type' => 'textarea', 'rows' => 3],
    ],
    'bulk' => ['delete' => true, 'status' => ['scheduled', 'ongoing', 'completed', 'cancelled']],
    'import' => true,
    'export' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $e = [];
            $start = $data['start_date'] ?? ($old['start_date'] ?? null);
            $end = array_key_exists('end_date', $data) ? $data['end_date'] : ($old['end_date'] ?? null);
            if ($start && $end && $end < $start) {
                $e['end_date'] = 'End date cannot be before the start date.';
            }
            $status = $data['status'] ?? ($old['status'] ?? 'scheduled');
            if ($status === 'completed' && $start && $start > date('Y-m-d')) {
                $e['status'] = 'A session that has not started yet cannot be marked completed.';
            }
            if ($id && array_key_exists('max_marks', $data) && $data['max_marks'] !== null) {
                $top = db_value('SELECT MAX(score) FROM placement_training_participants WHERE training_id = ?', [$id]);
                if ($top !== null && (float) $top > (float) $data['max_marks']) {
                    $e['max_marks'] = 'Some participants already scored ' . (float) $top . '. Maximum marks cannot be lower than that.';
                }
            }
            return $e;
        },
        'before_save' => function (array $data, ?int $id): array {
            if ($id === null) {
                $data['participants_count'] = 0;
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $scored = (int) db_value('SELECT COUNT(*) FROM placement_training_participants WHERE training_id = ? AND (attended = 1 OR score IS NOT NULL)', [$id]);
            return $scored ? '"' . $row['title'] . '" has attendance / scores for ' . $scored . ' student(s). Mark it cancelled instead of deleting it.' : null;
        },
    ],
];
