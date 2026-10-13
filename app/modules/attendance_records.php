<?php
/**
 * Individual attendance entries (students, faculty, staff) - for corrections, audit and per-person history.
 * Records are created by the marking screens / device punches; this module lists, edits (status, times,
 * remarks), exports and deletes them.
 */
require_once APP_ROOT . '/app/services/attendance.php';

return [
    'table' => 'attendance_records',
    'title' => 'Attendance Records',
    'singular' => 'Attendance record',
    'permission' => 'attendance',
    'icon' => 'list-checks',
    'description' => 'Individual attendance entries for students, faculty and staff.',
    'select' => "t.*, a.attendance_date, a.type AS sheet_type, a.section_id, a.subject_id, a.is_locked, a.method,
                 sb.code AS subject_code, sb.name AS subject_name, ts.name AS slot_name,
                 CASE t.person_type WHEN 'student' THEN TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) WHEN 'faculty' THEN TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name))
                      ELSE TRIM(CONCAT_WS(' ', st.first_name, st.last_name)) END AS person_name,
                 COALESCE(s.student_uid, f.employee_id, st.employee_id) AS person_code, COALESCE(s.photo, f.photo, st.photo) AS person_photo,
                 COALESCE(CONCAT(p.short_name, ' · Sem ', sc.semester_no, ' · Sec ', sc.name), CONCAT(UPPER(LEFT(t.person_type, 1)), SUBSTRING(t.person_type, 2), ' daily')) AS class_label",
    'joins' => "JOIN attendance a ON a.id = t.attendance_id
                LEFT JOIN students s ON t.person_type = 'student' AND s.id = t.person_id
                LEFT JOIN faculty f ON t.person_type = 'faculty' AND f.id = t.person_id
                LEFT JOIN staff st ON t.person_type = 'staff' AND st.id = t.person_id
                LEFT JOIN subjects sb ON sb.id = a.subject_id LEFT JOIN time_slots ts ON ts.id = a.time_slot_id
                LEFT JOIN sections sc ON sc.id = a.section_id LEFT JOIN programs p ON p.id = sc.program_id",
    'search' => ['s.first_name', 's.last_name', 's.student_uid', 's.roll_no', 'f.first_name', 'f.last_name', 'f.employee_id', 'st.first_name', 'st.last_name', 'st.employee_id', 'sb.code'],
    'search_placeholder' => 'Search name, ID or subject code…',
    // Newest first, driven by the attendance date index (sorting the whole join by other columns would be slow)
    'order' => 'a.attendance_date DESC, a.id DESC',
    'columns' => [
        ['key' => 'person_name', 'label' => 'Person', 'format' => 'person', 'image' => 'person_photo', 'sub' => 'person_code'],
        ['key' => 'attendance_date', 'label' => 'Date', 'format' => 'date', 'sub' => 'slot_name'],
        ['key' => 'class_label', 'label' => 'Class', 'format' => 'text'],
        ['key' => 'subject_name', 'label' => 'Subject', 'format' => 'text', 'sub' => 'subject_code'],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
        ['key' => 'in_time', 'label' => 'In', 'format' => 'time'],
        ['key' => 'out_time', 'label' => 'Out', 'format' => 'time'],
        ['key' => 'remarks', 'label' => 'Remarks', 'format' => 'truncate'],
        ['key' => 'person_type', 'label' => 'Type', 'format' => 'badge', 'hidden' => true, 'colors' => ['student' => 'blue', 'faculty' => 'purple', 'staff' => 'cyan']],
        ['key' => 'method', 'label' => 'Method', 'format' => 'badge', 'hidden' => true],
        ['key' => 'updated_at', 'label' => 'Updated', 'format' => 'datetime', 'hidden' => true],
    ],
    'filters' => [
        ['key' => 'person_type', 'label' => 'Type', 'options' => ['student' => 'Students', 'faculty' => 'Faculty', 'staff' => 'Staff']],
        ['key' => 'status', 'label' => 'Status', 'options' => att_employee_statuses()],
        ['key' => 'attendance_date', 'label' => 'Date', 'type' => 'daterange', 'column' => 'a.attendance_date'],
        ['key' => 'section_id', 'label' => 'Section', 'source' => 'sections', 'column' => 'a.section_id'],
    ],
    'fields' => [
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => att_employee_statuses(), 'col' => 12],
        ['name' => 'in_time', 'label' => 'In time', 'type' => 'time'],
        ['name' => 'out_time', 'label' => 'Out time', 'type' => 'time'],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'text', 'maxlength' => 255, 'col' => 12],
    ],
    'scopes' => ['person_id' => 't.person_id', 'person_type' => 't.person_type', 'attendance_id' => 't.attendance_id'],
    'bulk' => ['delete' => true],
    'export' => true,
    'import' => false,
    'form' => ['size' => 'sm'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            if ($id === null) {
                return ['status' => 'Attendance records are created from the marking screens.'];
            }
            $errors = [];
            $sheet = db_row('SELECT type, is_locked, section_id, subject_id FROM attendance WHERE id = ?', [(int) $old['attendance_id']]);
            if ($sheet && $sheet['is_locked'] && !can('attendance', 'approve')) {
                $errors['status'] = 'This attendance sheet is locked. Ask an attendance approver to unlock it.';
            }
            if ($sheet && $sheet['type'] === 'student' && !att_can_mark_class((int) $sheet['section_id'], (int) $sheet['subject_id'])) {
                $errors['status'] = 'You can only correct attendance for classes assigned to you.';
            }
            if (($data['status'] ?? null) === 'half_day' && ($old['person_type'] ?? '') === 'student') {
                $errors['status'] = 'Half day applies to faculty and staff only.';
            }
            $in = $data['in_time'] ?? $old['in_time'] ?? null;
            $out = $data['out_time'] ?? $old['out_time'] ?? null;
            if ($in && $out && strtotime($out) <= strtotime($in)) {
                $errors['out_time'] = 'Out time must be after in time.';
            }
            return $errors;
        },
        'before_save' => function (array $data): array {
            if (in_array($data['status'] ?? '', ['absent', 'leave'], true)) {
                $data['in_time'] = null;
                $data['out_time'] = null;
            }
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old): void {
            if ($old) {
                att_after_records_changed([(int) $old['attendance_id']], $old['person_type'] === 'student' ? [(int) $old['person_id']] : []);
            }
        },
        'after_delete' => function (int $id, array $row): void {
            att_after_records_changed([(int) $row['attendance_id']], $row['person_type'] === 'student' ? [(int) $row['person_id']] : []);
        },
        'before_delete' => function (int $id, array $row): ?string {
            $sheet = db_row('SELECT type, is_locked, section_id, subject_id FROM attendance WHERE id = ?', [(int) $row['attendance_id']]);
            if ($sheet && $sheet['is_locked'] && !can('attendance', 'approve')) {
                return 'The attendance sheet for this record is locked and cannot be changed.';
            }
            if ($sheet && $sheet['type'] === 'student' && !att_can_mark_class((int) $sheet['section_id'], (int) $sheet['subject_id'])) {
                return 'You can only delete attendance for classes assigned to you.';
            }
            return null;
        },
        'describe' => function (array $row): string {
            $t = $row['person_type'] ?? 'student';
            $table = ['student' => 'students', 'faculty' => 'faculty', 'staff' => 'staff'][$t] ?? 'students';
            $name = db_value("SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) FROM $table WHERE id = ?", [(int) ($row['person_id'] ?? 0)]);
            $date = db_value('SELECT attendance_date FROM attendance WHERE id = ?', [(int) ($row['attendance_id'] ?? 0)]);
            return ($name ?: '#' . ($row['person_id'] ?? '')) . ' on ' . format_date($date) . ' (' . ($row['status'] ?? '') . ')';
        },
    ],
];
