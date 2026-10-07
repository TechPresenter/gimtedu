<?php
/**
 * Timetable periods as a list (export, bulk publish, quick edits). The weekly grid editor lives at /timetable and
 * uses api/routes/timetable.php; both paths share the same validation and conflict detection (app/services/timetable.php).
 */
require_once APP_ROOT . '/app/services/timetable.php';

$days = [];
foreach (tt_days() as $d) {
    $days[$d['no']] = $d['name'];
}

return [
    'table' => 'timetables',
    'title' => 'Timetable Periods',
    'singular' => 'Period',
    'permission' => 'timetable',
    'icon' => 'calendar-days',
    'description' => 'Every scheduled class period of the session.',
    'select' => "t.*, CONCAT(p.short_name, ' · Sem ', sc.semester_no, ' · Sec ', sc.name) AS section_label, sb.code AS subject_code, sb.name AS subject_name,
                 TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS faculty_name, f.photo AS faculty_photo, f.designation AS faculty_designation,
                 r.code AS room_code, ts.name AS slot_name, ts.start_time, ts.end_time,
                 CONCAT(ELT(t.day_of_week, 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'), ' · ', ts.name) AS when_label,
                 CONCAT(TIME_FORMAT(ts.start_time, '%h:%i %p'), ' - ', TIME_FORMAT(ts.end_time, '%h:%i %p')) AS time_range",
    'joins' => 'JOIN sections sc ON sc.id = t.section_id JOIN programs p ON p.id = sc.program_id JOIN subjects sb ON sb.id = t.subject_id
                JOIN time_slots ts ON ts.id = t.time_slot_id LEFT JOIN faculty f ON f.id = t.faculty_id LEFT JOIN classrooms r ON r.id = t.classroom_id',
    'search' => ['sb.code', 'sb.name', 'f.first_name', 'f.last_name', 'r.code', 'p.short_name'],
    'order' => 't.day_of_week ASC, ts.sort_order ASC, p.sort_order ASC, sc.semester_no ASC, sc.name ASC',
    'per_page' => 50,
    'columns' => [
        ['key' => 'when_label', 'label' => 'Day / Period', 'format' => 'title', 'sub' => 'time_range', 'sortable' => 't.day_of_week, ts.sort_order'],
        ['key' => 'section_label', 'label' => 'Class', 'format' => 'text', 'sortable' => "CONCAT(p.short_name, LPAD(sc.semester_no, 2, '0'), sc.name)"],
        ['key' => 'subject_name', 'label' => 'Subject', 'format' => 'title', 'sub' => 'subject_code', 'sortable' => 'sb.code'],
        ['key' => 'faculty_name', 'label' => 'Faculty', 'format' => 'person', 'image' => 'faculty_photo', 'sub' => 'faculty_designation', 'sortable' => 'f.first_name'],
        ['key' => 'room_code', 'label' => 'Room', 'format' => 'code', 'sortable' => 'r.code'],
        ['key' => 'type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true, 'colors' => ['lecture' => 'blue', 'lab' => 'purple', 'tutorial' => 'cyan', 'seminar' => 'amber']],
        ['key' => 'notes', 'label' => 'Notes', 'format' => 'truncate', 'truncate' => 40, 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'when_label', 'label' => 'Day / Period'], ['key' => 'time_range', 'label' => 'Time'], ['key' => 'section_label', 'label' => 'Class'],
        ['key' => 'subject_code', 'label' => 'Subject Code'], ['key' => 'subject_name', 'label' => 'Subject'], ['key' => 'faculty_name', 'label' => 'Faculty'],
        ['key' => 'room_code', 'label' => 'Room'], ['key' => 'type', 'label' => 'Type', 'format' => 'badge'], ['key' => 'notes', 'label' => 'Notes'],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
    ],
    'filters' => [
        ['key' => 'academic_session_id', 'label' => 'Session', 'source' => 'sessions', 'default' => current_session_id()],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs'],
        ['key' => 'section_id', 'label' => 'Section', 'source' => 'sections', 'depends' => ['program_id' => 'program_id']],
        ['key' => 'day_of_week', 'label' => 'Day', 'options' => $days],
        ['key' => 'faculty_id', 'label' => 'Faculty', 'source' => 'faculty'],
        ['key' => 'classroom_id', 'label' => 'Room', 'source' => 'classrooms'],
        ['key' => 'status', 'label' => 'Status', 'options' => ['published' => 'Published', 'draft' => 'Draft']],
    ],
    'scopes' => ['section_id' => 't.section_id', 'faculty_id' => 't.faculty_id', 'classroom_id' => 't.classroom_id', 'academic_session_id' => 't.academic_session_id'],
    'fields' => [
        ['name' => 'academic_session_id', 'label' => 'Session', 'type' => 'select', 'required' => true, 'source' => 'sessions', 'default' => current_session_id(), 'col' => 4],
        ['name' => 'section_id', 'label' => 'Class / section', 'type' => 'combobox', 'required' => true, 'source' => 'sections', 'depends' => ['academic_session_id' => 'academic_session_id'], 'col' => 8],
        ['name' => 'day_of_week', 'label' => 'Day', 'type' => 'select', 'required' => true, 'options' => $days, 'col' => 4],
        ['name' => 'time_slot_id', 'label' => 'Time slot', 'type' => 'select', 'required' => true, 'col' => 8,
            'source' => ['table' => 'time_slots ts', 'value' => 'ts.id', 'label' => "CONCAT(ts.name, ' (', TIME_FORMAT(ts.start_time, '%h:%i %p'), ' - ', TIME_FORMAT(ts.end_time, '%h:%i %p'), ')')",
                'where' => "ts.status = 'active' AND ts.is_break = 0", 'order' => 'ts.sort_order', 'search' => ['ts.name']]],
        ['name' => 'subject_id', 'label' => 'Subject', 'type' => 'select', 'required' => true, 'depends' => ['section_id' => 'section_id'], 'col' => 12,
            'source' => ['table' => 'subjects sb JOIN sections sx ON sx.program_id = sb.program_id AND sx.semester_no = sb.semester_no', 'value' => 'sb.id',
                'label' => "CONCAT(sb.code, ' — ', sb.name)", 'sub' => 'sb.type', 'where' => "sb.status = 'active'", 'order' => 'sb.code', 'search' => ['sb.code', 'sb.name'],
                'filters' => ['section_id' => 'sx.id']]],
        ['name' => 'faculty_id', 'label' => 'Faculty', 'type' => 'combobox', 'source' => 'faculty', 'col' => 6],
        ['name' => 'classroom_id', 'label' => 'Room', 'type' => 'combobox', 'source' => 'classrooms', 'col' => 6],
        ['name' => 'type', 'label' => 'Period type', 'type' => 'select', 'required' => true, 'default' => 'lecture', 'options' => TT_TYPES, 'col' => 4],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'draft', 'options' => ['draft' => 'Draft', 'published' => 'Published'], 'col' => 4],
        ['name' => 'override_faculty', 'label' => 'Override assignment', 'type' => 'toggle', 'form' => true, 'import' => false, 'col' => 4, 'placeholder' => 'Allow unassigned faculty'],
        ['name' => 'notes', 'label' => 'Notes', 'type' => 'text', 'maxlength' => 255, 'col' => 12, 'placeholder' => 'e.g. Guest lecture, combined class'],
    ],
    'bulk' => ['delete' => true, 'status' => ['published', 'draft']],
    'import' => false,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            [, $errors] = tt_validate_entry($data + ['override_faculty' => !empty($input['override_faculty'])], $id, $old);
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            unset($data['override_faculty']);
            $sec = tt_section((int) ($data['section_id'] ?? ($old['section_id'] ?? 0)));
            if ($sec) {
                $data['program_id'] = (int) $sec['program_id'];
                $data['semester_no'] = (int) $sec['semester_no'];
            }
            return $data;
        },
        'describe' => function (array $r): string {
            $e = !empty($r['id']) ? tt_entry((int) $r['id']) : null;
            return $e ? '"' . $e['section_label'] . ' · ' . $e['day_name'] . ' ' . $e['slot_name'] . ' · ' . $e['subject_code'] . '"' : '#' . ($r['id'] ?? '');
        },
    ],
];
