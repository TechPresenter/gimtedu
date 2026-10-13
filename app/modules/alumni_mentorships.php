<?php
/** Alumni mentorships (brief §23 "mentors"): alumni mentor ↔ current student mentee. */
require_once APP_ROOT . '/app/services/alumni.php';

return [
    'table' => 'alumni_mentorships',
    'title' => 'Mentorships',
    'singular' => 'Mentorship',
    'permission' => 'alumni',
    'icon' => 'handshake',
    'description' => 'Alumni mentors paired with current students.',
    'select' => "t.*, a.name AS mentor_name, a.photo AS mentor_photo, a.batch_year AS mentor_batch,
                 TRIM(CONCAT_WS(' · ', NULLIF(a.designation, ''), NULLIF(a.company, ''))) AS mentor_sub,
                 TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.photo AS student_photo, s.student_uid,
                 CONCAT(COALESCE(p.short_name, ''), ' · Sem ', s.current_semester, ' · ', s.student_uid) AS student_sub, p.short_name AS program_short",
    'joins' => 'JOIN alumni a ON a.id = t.alumni_id JOIN students s ON s.id = t.student_id LEFT JOIN programs p ON p.id = s.program_id',
    'search' => ['a.name', 'a.company', 's.first_name', 's.last_name', 's.student_uid', 't.area', "CONCAT(s.first_name, ' ', s.last_name)"],
    'search_placeholder' => 'Search mentor, student, area…',
    'order' => "t.status = 'active' DESC, t.start_date DESC, t.id DESC",
    'columns' => [
        ['key' => 'mentor_name', 'label' => 'Mentor (alumni)', 'format' => 'person', 'image' => 'mentor_photo', 'sub' => 'mentor_sub', 'link' => '/alumni/{alumni_id}', 'sortable' => 'a.name'],
        ['key' => 'student_name', 'label' => 'Mentee (student)', 'format' => 'person', 'image' => 'student_photo', 'sub' => 'student_sub', 'sortable' => 's.first_name'],
        ['key' => 'area', 'label' => 'Focus area', 'sortable' => true],
        ['key' => 'start_date', 'label' => 'Started', 'format' => 'date', 'sortable' => true],
        ['key' => 'end_date', 'label' => 'Ended', 'format' => 'date', 'hidden' => true, 'sortable' => true],
        ['key' => 'sessions_count', 'label' => 'Sessions', 'format' => 'number', 'align' => 'center', 'hidden' => true, 'sortable' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
        ['key' => 'notes', 'label' => 'Notes', 'format' => 'truncate', 'hidden' => true],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'completed' => 'Completed', 'cancelled' => 'Cancelled']],
        ['key' => 'area', 'label' => 'Focus area', 'options' => alumni_mentorship_areas()],
        ['key' => 'program_id', 'label' => 'Student program', 'source' => 'programs', 'column' => 's.program_id'],
        ['key' => 'alumni_id', 'label' => 'Mentor', 'source' => alumni_lookup_source(true)],
    ],
    'fields' => [
        ['name' => 'alumni_id', 'label' => 'Mentor (alumni)', 'type' => 'combobox', 'required' => true, 'source' => alumni_lookup_source(), 'col' => 6, 'placeholder' => 'Search alumni…',
            'help' => 'Alumni are added to the mentor pool automatically.'],
        ['name' => 'student_id', 'label' => 'Mentee (student)', 'type' => 'combobox', 'required' => true, 'source' => 'students', 'col' => 6, 'placeholder' => 'Search active students…'],
        ['name' => 'area', 'label' => 'Focus area', 'type' => 'select', 'required' => true, 'options' => alumni_mentorship_areas(), 'col' => 6],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'col' => 6,
            'options' => ['active' => 'Active', 'completed' => 'Completed', 'cancelled' => 'Cancelled']],
        ['name' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'required' => true, 'col' => 4, 'default' => date('Y-m-d')],
        ['name' => 'end_date', 'label' => 'End date', 'type' => 'date', 'col' => 4],
        ['name' => 'sessions_count', 'label' => 'Sessions held', 'type' => 'number', 'col' => 4, 'min' => 0, 'max' => 500, 'default' => 0],
        ['name' => 'notes', 'label' => 'Notes / goals', 'type' => 'textarea', 'rows' => 3, 'maxlength' => 500],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'completed', 'cancelled']],
    'export' => true,
    'import' => false,
    'form' => ['size' => 'lg'],
    'scopes' => ['alumni_id' => 't.alumni_id', 'student_id' => 't.student_id'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $alumniId = (int) ($data['alumni_id'] ?? ($old['alumni_id'] ?? 0));
            $studentId = (int) ($data['student_id'] ?? ($old['student_id'] ?? 0));
            $status = $data['status'] ?? ($old['status'] ?? 'active');
            $start = $data['start_date'] ?? ($old['start_date'] ?? null);
            $end = array_key_exists('end_date', $data) ? $data['end_date'] : ($old['end_date'] ?? null);
            if ($start && $end && $end < $start) {
                $errors['end_date'] = 'End date cannot be before the start date.';
            }
            if ($start && $start > date('Y-m-d', strtotime('+90 days'))) {
                $errors['start_date'] = 'Start date can be at most 90 days ahead.';
            }
            if ($alumniId && isset($data['alumni_id'])) {
                $a = db_row('SELECT status FROM alumni WHERE id = ?', [$alumniId]);
                if ($a && $a['status'] !== 'active') {
                    $errors['alumni_id'] = 'This alumni profile is inactive.';
                }
            }
            if ($alumniId && $studentId && $status === 'active') {
                $dup = db_value("SELECT id FROM alumni_mentorships WHERE alumni_id = ? AND student_id = ? AND status = 'active' AND id <> ?", [$alumniId, $studentId, (int) ($id ?? 0)]);
                if ($dup) {
                    $errors['student_id'] = 'This student already has an active mentorship with the selected mentor.';
                }
                $load = (int) db_value("SELECT COUNT(*) FROM alumni_mentorships WHERE alumni_id = ? AND status = 'active' AND id <> ?", [$alumniId, (int) ($id ?? 0)]);
                if ($load >= 6) {
                    $errors['alumni_id'] = 'This mentor already guides 6 students. Pick another mentor or complete an existing mentorship.';
                }
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            $status = $data['status'] ?? ($old['status'] ?? 'active');
            $end = array_key_exists('end_date', $data) ? $data['end_date'] : ($old['end_date'] ?? null);
            if (in_array($status, ['completed', 'cancelled'], true) && !$end) {
                $data['end_date'] = date('Y-m-d');
            }
            return $data;
        },
        'after_save' => function (int $id, array $data): void {
            if (!empty($data['alumni_id'])) {
                db_exec('UPDATE alumni SET is_mentor = 1 WHERE id = ? AND is_mentor = 0', [(int) $data['alumni_id']]);
            }
        },
        'describe' => function (array $row): string {
            $m = db_row("SELECT a.name AS mentor, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student FROM alumni a, students s WHERE a.id = ? AND s.id = ?", [(int) ($row['alumni_id'] ?? 0), (int) ($row['student_id'] ?? 0)]);
            return $m ? $m['mentor'] . ' → ' . $m['student'] . ($row['area'] ? ' (' . $row['area'] . ')' : '') : '#' . ($row['id'] ?? '');
        },
    ],
];
