<?php
/**
 * Student attendance sessions (one row per class period). Sessions are created and edited on the
 * "Mark attendance" screen (POST /api/attendance/sheet); this module lists, filters, exports, locks and deletes them.
 */
require_once APP_ROOT . '/app/services/attendance.php';

$late = att_late_counts() ? 1 : 0;

return [
    'table' => 'attendance',
    'title' => 'Attendance Sessions',
    'singular' => 'Attendance session',
    'permission' => 'attendance',
    'icon' => 'clipboard-check',
    'description' => 'Every class period for which student attendance was marked.',
    'where' => "t.type = 'student'",
    'select' => "t.*, " . att_class_label_sql() . " AS class_label, sb.code AS subject_code, sb.name AS subject_name, ts.name AS slot_name, ts.start_time,
                 u.name AS taken_by_name, TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS faculty_name, f.photo AS faculty_photo,
                 t.total_count AS total, t.present_count AS present, t.absent_count AS absent, t.late_count AS late, t.leave_count AS on_leave",
    'joins' => "LEFT JOIN sections sc ON sc.id = t.section_id LEFT JOIN programs p ON p.id = sc.program_id
                LEFT JOIN subjects sb ON sb.id = t.subject_id LEFT JOIN time_slots ts ON ts.id = t.time_slot_id
                LEFT JOIN users u ON u.id = t.taken_by LEFT JOIN faculty f ON f.id = t.faculty_id",
    'search' => ['sb.name', 'sb.code', 'p.short_name', 'f.first_name', 'f.last_name', 't.remarks'],
    'search_placeholder' => 'Search subject, program or faculty…',
    'order' => 't.attendance_date DESC, ts.sort_order ASC, t.id DESC',
    'columns' => [
        ['key' => 'attendance_date', 'label' => 'Date', 'format' => 'date', 'sub' => 'slot_name', 'sortable' => true],
        ['key' => 'class_label', 'label' => 'Class', 'format' => 'title', 'sortable' => 'p.short_name, sc.semester_no, sc.name'],
        ['key' => 'subject_name', 'label' => 'Subject', 'format' => 'text', 'sub' => 'subject_code', 'sortable' => 'sb.code'],
        ['key' => 'faculty_name', 'label' => 'Faculty', 'format' => 'person', 'image' => 'faculty_photo', 'sortable' => 'f.first_name'],
        ['key' => 'present', 'label' => 'Present', 'format' => 'number', 'align' => 'center', 'sortable' => 't.present_count'],
        ['key' => 'absent', 'label' => 'Absent', 'format' => 'number', 'align' => 'center', 'sortable' => 't.absent_count'],
        ['key' => 'late', 'label' => 'Late', 'format' => 'number', 'align' => 'center', 'hidden' => true],
        ['key' => 'on_leave', 'label' => 'Leave', 'format' => 'number', 'align' => 'center', 'hidden' => true],
        ['key' => 'percent', 'label' => 'Attendance', 'format' => 'percent', 'align' => 'right', 'sortable' => '(t.present_count + ' . $late . ' * t.late_count) / NULLIF(t.total_count, 0)'],
        ['key' => 'method', 'label' => 'Method', 'format' => 'badge', 'colors' => ['manual' => 'navy', 'biometric' => 'cyan', 'qr' => 'purple', 'import' => 'slate'], 'hidden' => true],
        ['key' => 'taken_by_name', 'label' => 'Marked by', 'format' => 'text', 'hidden' => true],
        ['key' => 'is_locked', 'label' => 'Locked', 'format' => 'boolean', 'align' => 'center', 'sortable' => true],
        ['key' => 'updated_at', 'label' => 'Last updated', 'format' => 'datetime', 'hidden' => true, 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'attendance_date', 'label' => 'Date', 'type' => 'daterange'],
        ['key' => 'section_id', 'label' => 'Section', 'source' => 'sections', 'column' => 't.section_id'],
        ['key' => 'program_id', 'label' => 'Program', 'source' => ['table' => 'programs p', 'value' => 'p.id', 'label' => 'p.short_name', 'where' => "p.status = 'active'", 'order' => 'p.sort_order, p.short_name', 'search' => ['p.short_name', 'p.name']], 'column' => 't.program_id'],
        ['key' => 'subject_id', 'label' => 'Subject', 'type' => 'select', 'source' => ['table' => 'subjects sb', 'value' => 'sb.id', 'label' => "CONCAT(sb.code, ' — ', sb.name)",
            'where' => "sb.status = 'active'", 'order' => 'sb.code', 'search' => ['sb.code', 'sb.name'], 'async' => true], 'column' => 't.subject_id'],
        ['key' => 'faculty_id', 'label' => 'Faculty', 'source' => 'faculty', 'column' => 't.faculty_id'],
        ['key' => 'method', 'label' => 'Method', 'options' => ['manual' => 'Manual', 'biometric' => 'Biometric', 'qr' => 'QR code']],
        ['key' => 'is_locked', 'label' => 'Locked', 'type' => 'boolean'],
    ],
    'fields' => [
        ['name' => 'faculty_id', 'label' => 'Conducted by', 'type' => 'combobox', 'source' => 'faculty', 'placeholder' => 'Search faculty…'],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 255],
        ['name' => 'is_locked', 'label' => 'Locked (prevents further changes)', 'type' => 'toggle', 'help' => 'Requires the Attendance approve permission.'],
    ],
    'scopes' => ['section_id' => 't.section_id', 'subject_id' => 't.subject_id', 'faculty_id' => 't.faculty_id'],
    'bulk' => ['delete' => true],
    'export' => true,
    'import' => false,
    'form' => ['size' => 'md'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            if ($id === null) {
                return ['faculty_id' => 'Attendance sessions are created from the Mark Attendance screen.'];
            }
            if (array_key_exists('is_locked', $data) && (int) $data['is_locked'] !== (int) ($old['is_locked'] ?? 0) && !can('attendance', 'approve')) {
                return ['is_locked' => 'Only attendance approvers can lock or unlock a session.'];
            }
            if (!empty($old['is_locked']) && !can('attendance', 'approve')) {
                return ['remarks' => 'This session is locked. Ask an attendance approver to unlock it.'];
            }
            if (($old['type'] ?? '') === 'student' && !att_can_mark_class((int) $old['section_id'], (int) $old['subject_id'])) {
                return ['remarks' => 'You can only update attendance for classes assigned to you.'];
            }
            return [];
        },
        'before_delete' => function (int $id, array $row): ?string {
            $GLOBALS['__att_deleted_students'][$id] = ($row['type'] ?? '') === 'student'
                ? array_map('intval', db_column("SELECT person_id FROM attendance_records WHERE attendance_id = ? AND person_type = 'student'", [$id])) : [];
            if (!empty($row['is_locked']) && !can('attendance', 'approve')) {
                return 'The session on ' . format_date($row['attendance_date']) . ' is locked and cannot be deleted.';
            }
            if (($row['type'] ?? '') === 'student' && !att_can_mark_class((int) $row['section_id'], (int) $row['subject_id'])) {
                return 'You can only delete attendance for classes assigned to you.';
            }
            return null;
        },
        'after_delete' => function (int $id, array $row): void {
            att_refresh_summaries($GLOBALS['__att_deleted_students'][$id] ?? []);
            unset($GLOBALS['__att_deleted_students'][$id]);
        },
        'describe' => function (array $row): string {
            $label = db_value('SELECT ' . att_class_label_sql() . ' FROM sections sc JOIN programs p ON p.id = sc.program_id WHERE sc.id = ?', [(int) ($row['section_id'] ?? 0)]);
            $sub = db_value('SELECT code FROM subjects WHERE id = ?', [(int) ($row['subject_id'] ?? 0)]);
            return trim(($label ?: ucfirst((string) ($row['type'] ?? ''))) . ' ' . ($sub ?: '')) . ' on ' . format_date($row['attendance_date'] ?? null);
        },
        'transform_row' => function (array $row) use ($late): array {
            $total = (int) ($row['total'] ?? 0);
            $row['percent'] = $total ? round(100 * ((int) $row['present'] + $late * (int) $row['late']) / $total, 1) : null;
            $row['present_label'] = ((int) $row['present'] + (int) $row['late']) . '/' . $total;
            return $row;
        },
        'summary' => function (string $from, array $args) use ($late): array {
            $row = db_row("SELECT COUNT(*) AS sessions, SUM(t.is_locked) AS locked, COUNT(DISTINCT t.section_id) AS sections, SUM(t.total_count) AS marks,
                           SUM(t.present_count) AS present, SUM(t.absent_count) AS absent, SUM(t.late_count) AS late $from", $args);
            $marks = (int) ($row['marks'] ?? 0);
            return [
                'sessions' => (int) $row['sessions'], 'sections' => (int) $row['sections'], 'locked' => (int) $row['locked'], 'absent' => (int) $row['absent'],
                'percent' => $marks ? round(100 * ((int) $row['present'] + $late * (int) $row['late']) / $marks, 1) : null,
            ];
        },
    ],
];
