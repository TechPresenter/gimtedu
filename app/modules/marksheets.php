<?php
/**
 * Issued marksheets (one per published result). Generated from published results via api/routes/marksheets.php
 * (unique number from next_number('marksheet')); printed by admin/print/marksheet.php. Revoke / re-issue via the API.
 */
require_once APP_ROOT . '/app/services/exams.php';

$semesters = [];
for ($i = 1; $i <= 10; $i++) {
    $semesters[$i] = 'Semester ' . $i;
}

return [
    'table' => 'marksheets',
    'title' => 'Marksheets',
    'singular' => 'Marksheet',
    'permission' => 'results',
    'icon' => 'file-badge',
    'description' => 'Marksheets issued for published results, with QR verification codes.',
    'readonly' => true,
    'select' => "t.id, t.marksheet_no, t.result_id, t.student_id, t.issued_at, t.printed_count, t.last_printed_at, t.status, t.revoked_reason, t.revoked_at,
                 TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, s.roll_no, s.photo,
                 r.exam_id, r.semester_no, r.percentage, r.sgpa, r.cgpa, r.result_status, ex.name AS exam_name, p.short_name AS program_short, ss.name AS session_name,
                 CONCAT(COALESCE(p.short_name, ''), IFNULL(CONCAT(' · Sem ', r.semester_no), '')) AS cohort, iu.name AS issued_by_name",
    'joins' => 'JOIN results r ON r.id = t.result_id JOIN students s ON s.id = t.student_id JOIN exams ex ON ex.id = r.exam_id
                LEFT JOIN programs p ON p.id = r.program_id LEFT JOIN academic_sessions ss ON ss.id = r.academic_session_id LEFT JOIN users iu ON iu.id = t.issued_by',
    'search' => ['t.marksheet_no', 's.first_name', 's.last_name', 's.student_uid', 's.roll_no', "CONCAT(s.first_name, ' ', s.last_name)"],
    'search_placeholder' => 'Search marksheet no, student, roll no…',
    'order' => 't.issued_at DESC, t.id DESC',
    'columns' => [
        ['key' => 'marksheet_no', 'label' => 'Marksheet no', 'format' => 'code', 'sortable' => true],
        ['key' => 'student_name', 'label' => 'Student', 'format' => 'person', 'image' => 'photo', 'sub' => 'student_uid', 'sortable' => 's.first_name'],
        ['key' => 'exam_name', 'label' => 'Exam', 'format' => 'text', 'sub' => 'session_name', 'sortable' => 'ex.start_date'],
        ['key' => 'cohort', 'label' => 'Program / Sem', 'format' => 'text', 'sortable' => 'p.sort_order, r.semester_no', 'hidden' => true],
        ['key' => 'sgpa', 'label' => 'SGPA', 'format' => 'number', 'align' => 'center', 'sortable' => 'r.sgpa'],
        ['key' => 'cgpa', 'label' => 'CGPA', 'format' => 'number', 'align' => 'center', 'sortable' => 'r.cgpa'],
        ['key' => 'result_status', 'label' => 'Result', 'format' => 'badge', 'sortable' => 'r.result_status',
            'colors' => ['PASS' => 'green', 'BACKLOG' => 'amber', 'FAIL' => 'red', 'ABSENT' => 'slate', 'WITHHELD' => 'purple']],
        ['key' => 'issued_at', 'label' => 'Issued', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'printed_count', 'label' => 'Prints', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'marksheet_no', 'label' => 'Marksheet no'], ['key' => 'student_uid', 'label' => 'Student ID'], ['key' => 'roll_no', 'label' => 'Roll no'], ['key' => 'student_name', 'label' => 'Student'],
        ['key' => 'exam_name', 'label' => 'Exam'], ['key' => 'session_name', 'label' => 'Session'], ['key' => 'cohort', 'label' => 'Program / Semester'],
        ['key' => 'percentage', 'label' => 'Percentage', 'format' => 'number'], ['key' => 'sgpa', 'label' => 'SGPA', 'format' => 'number'], ['key' => 'cgpa', 'label' => 'CGPA', 'format' => 'number'],
        ['key' => 'result_status', 'label' => 'Result'], ['key' => 'issued_at', 'label' => 'Issued on', 'format' => 'date'], ['key' => 'issued_by_name', 'label' => 'Issued by'],
        ['key' => 'printed_count', 'label' => 'Prints', 'format' => 'number'], ['key' => 'status', 'label' => 'Status'], ['key' => 'revoked_reason', 'label' => 'Revoked reason'],
    ],
    'filters' => [
        ['key' => 'academic_session_id', 'label' => 'Session', 'source' => 'sessions', 'column' => 'r.academic_session_id'],
        ['key' => 'exam_id', 'label' => 'Exam', 'column' => 'r.exam_id', 'depends' => ['academic_session_id' => 'academic_session_id', 'program_id' => 'program_id'],
            'source' => ['table' => 'exams e', 'joins' => 'JOIN exam_types et ON et.id = e.exam_type_id', 'value' => 'e.id', 'label' => 'e.name', 'where' => "e.status = 'published'",
                'order' => 'e.end_date DESC, e.id DESC', 'search' => ['e.name'], 'async' => true,
                'filters' => ['academic_session_id' => 'e.academic_session_id', 'program_id' => 'e.program_id']]],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs', 'column' => 'r.program_id'],
        ['key' => 'semester_no', 'label' => 'Semester', 'options' => $semesters, 'column' => 'r.semester_no'],
        ['key' => 'result_status', 'label' => 'Result', 'options' => exam_result_statuses(), 'column' => 'r.result_status'],
        ['key' => 'status', 'label' => 'Status', 'options' => ['issued' => 'Issued', 'revoked' => 'Revoked']],
        ['key' => 'printed', 'label' => 'Printed', 'sql_map' => ['yes' => 't.printed_count > 0', 'no' => 't.printed_count = 0'], 'options' => ['yes' => 'Printed', 'no' => 'Not printed yet']],
        ['key' => 'issued_at', 'label' => 'Issued', 'type' => 'daterange', 'column' => 't.issued_at'],
    ],
    'scopes' => ['exam_id' => 'r.exam_id', 'student_id' => 't.student_id'],
    'fields' => [],
    'bulk' => ['delete' => false],
    'export' => true,
    'hooks' => [
        'transform_row' => function (array $r): array {
            foreach (['percentage', 'sgpa', 'cgpa'] as $k) {
                $r[$k] = $r[$k] === null ? null : (float) $r[$k];
            }
            $r['printed_count'] = (int) $r['printed_count'];
            return $r;
        },
        'summary' => function (string $from, array $args): array {
            $r = db_row("SELECT COUNT(*) AS total, COALESCE(SUM(t.status = 'issued'), 0) AS issued, COALESCE(SUM(t.status = 'revoked'), 0) AS revoked,
                                COALESCE(SUM(t.printed_count > 0), 0) AS printed" . $from, $args);
            return array_map('intval', $r);
        },
        'describe' => fn (array $r) => 'marksheet ' . ($r['marksheet_no'] ?? '#' . ($r['id'] ?? '')),
    ],
];
