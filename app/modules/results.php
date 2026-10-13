<?php
/**
 * Student results (one row per student per exam). Results are computed by the exam workflow (verify / publish)
 * - this module only lists, filters and exports them. Withhold / release: api/routes/results.php.
 */
require_once APP_ROOT . '/app/services/exams.php';

$semesters = [];
for ($i = 1; $i <= 10; $i++) {
    $semesters[$i] = 'Semester ' . $i;
}

return [
    'table' => 'results',
    'title' => 'Results',
    'singular' => 'Result',
    'permission' => 'results',
    'icon' => 'trophy',
    'description' => 'Computed results with totals, SGPA, CGPA, rank and result status.',
    'readonly' => true,
    'select' => "t.*, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, s.roll_no, s.photo, sc.name AS section_name,
                 ex.name AS exam_name, et.name AS type_name, et.is_final, p.short_name AS program_short, ss.name AS session_name,
                 m.marksheet_no, m.id AS marksheet_id, m.status AS marksheet_status,
                 CONCAT(COALESCE(s.roll_no, ''), IF(s.roll_no IS NULL, '', ' · '), s.student_uid) AS student_ref",
    'joins' => 'JOIN students s ON s.id = t.student_id JOIN exams ex ON ex.id = t.exam_id JOIN exam_types et ON et.id = ex.exam_type_id
                LEFT JOIN sections sc ON sc.id = s.section_id LEFT JOIN programs p ON p.id = t.program_id LEFT JOIN academic_sessions ss ON ss.id = t.academic_session_id
                LEFT JOIN marksheets m ON m.result_id = t.id',
    'search' => ['s.first_name', 's.last_name', 's.student_uid', 's.roll_no', "CONCAT(s.first_name, ' ', s.last_name)"],
    'search_placeholder' => 'Search student name, roll no, ID…',
    'order' => "(t.class_rank IS NULL) ASC, t.class_rank ASC, t.percentage DESC, s.roll_no ASC",
    'per_page' => 25,
    'columns' => [
        ['key' => 'class_rank', 'label' => 'Rank', 'format' => 'number', 'align' => 'center', 'sortable' => '(t.class_rank IS NULL), t.class_rank'],
        ['key' => 'student_name', 'label' => 'Student', 'format' => 'person', 'image' => 'photo', 'sub' => 'student_ref', 'sortable' => 's.first_name'],
        ['key' => 'section_name', 'label' => 'Section', 'format' => 'text', 'sortable' => 'sc.name', 'hidden' => true],
        ['key' => 'exam_name', 'label' => 'Exam', 'format' => 'text', 'sub' => 'session_name', 'sortable' => 'ex.start_date', 'hidden' => true],
        ['key' => 'total_marks', 'label' => 'Total', 'format' => 'number', 'align' => 'right', 'sortable' => true],
        ['key' => 'percentage', 'label' => '%', 'format' => 'percent', 'align' => 'right', 'sortable' => true],
        ['key' => 'sgpa', 'label' => 'SGPA', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'cgpa', 'label' => 'CGPA', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'credits_earned', 'label' => 'Credits', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'backlog_count', 'label' => 'Backlogs', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'division', 'label' => 'Division', 'format' => 'text', 'sortable' => true, 'hidden' => true],
        ['key' => 'result_status', 'label' => 'Result', 'format' => 'badge', 'sortable' => true,
            'colors' => ['PASS' => 'green', 'BACKLOG' => 'amber', 'FAIL' => 'red', 'ABSENT' => 'slate', 'WITHHELD' => 'purple']],
        ['key' => 'is_published', 'label' => 'Published', 'format' => 'boolean', 'sortable' => true, 'hidden' => true],
        ['key' => 'marksheet_no', 'label' => 'Marksheet', 'format' => 'code', 'sortable' => 'm.marksheet_no', 'hidden' => true],
    ],
    'export_columns' => [
        ['key' => 'class_rank', 'label' => 'Rank', 'format' => 'number'], ['key' => 'student_uid', 'label' => 'Student ID'], ['key' => 'roll_no', 'label' => 'Roll no'],
        ['key' => 'student_name', 'label' => 'Student'], ['key' => 'section_name', 'label' => 'Section'], ['key' => 'exam_name', 'label' => 'Exam'], ['key' => 'session_name', 'label' => 'Session'],
        ['key' => 'program_short', 'label' => 'Program'], ['key' => 'semester_no', 'label' => 'Semester', 'format' => 'number'],
        ['key' => 'total_marks', 'label' => 'Total', 'format' => 'number'], ['key' => 'max_marks', 'label' => 'Max marks', 'format' => 'number'], ['key' => 'percentage', 'label' => 'Percentage', 'format' => 'number'],
        ['key' => 'credits_registered', 'label' => 'Credits registered', 'format' => 'number'], ['key' => 'credits_earned', 'label' => 'Credits earned', 'format' => 'number'],
        ['key' => 'sgpa', 'label' => 'SGPA', 'format' => 'number'], ['key' => 'cgpa', 'label' => 'CGPA', 'format' => 'number'], ['key' => 'backlog_count', 'label' => 'Backlogs', 'format' => 'number'],
        ['key' => 'division', 'label' => 'Division'], ['key' => 'result_status', 'label' => 'Result'], ['key' => 'withheld_reason', 'label' => 'Withheld reason'],
        ['key' => 'marksheet_no', 'label' => 'Marksheet no'],
    ],
    'filters' => [
        ['key' => 'result_status', 'label' => 'Result', 'type' => 'multiselect', 'options' => exam_result_statuses()],
        ['key' => 'section_id', 'label' => 'Section', 'column' => 's.section_id', 'depends' => ['program_id' => 'program_id', 'semester_no' => 'semester_no'],
            'source' => ['table' => 'sections sc2', 'joins' => 'JOIN programs p2 ON p2.id = sc2.program_id', 'value' => 'sc2.id',
                'label' => "CONCAT(p2.short_name, ' · Sem ', sc2.semester_no, ' · Section ', sc2.name)", 'where' => "sc2.status = 'active'", 'order' => 'p2.sort_order, sc2.semester_no, sc2.name',
                'search' => ['sc2.name', 'p2.short_name'], 'filters' => ['program_id' => 'sc2.program_id', 'semester_no' => 'sc2.semester_no']]],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs', 'column' => 't.program_id'],
        ['key' => 'semester_no', 'label' => 'Semester', 'options' => $semesters, 'column' => 't.semester_no'],
        ['key' => 'academic_session_id', 'label' => 'Session', 'source' => 'sessions', 'column' => 't.academic_session_id'],
        ['key' => 'is_published', 'label' => 'Publication', 'options' => ['1' => 'Published', '0' => 'Not published'], 'column' => 't.is_published'],
        ['key' => 'marksheet', 'label' => 'Marksheet', 'sql_map' => ['issued' => "m.status = 'issued'", 'revoked' => "m.status = 'revoked'", 'none' => 'm.id IS NULL'],
            'options' => ['issued' => 'Issued', 'revoked' => 'Revoked', 'none' => 'Not generated']],
    ],
    'scopes' => ['exam_id' => 't.exam_id', 'student_id' => 't.student_id'],
    'fields' => [],
    'bulk' => ['delete' => false],
    'export' => true,
    'hooks' => [
        'transform_row' => function (array $r): array {
            foreach (['percentage', 'sgpa', 'cgpa', 'total_marks', 'max_marks'] as $k) {
                $r[$k] = $r[$k] === null ? null : (float) $r[$k];
            }
            $r['class_rank'] = $r['class_rank'] === null ? null : (int) $r['class_rank'];
            $r['is_published'] = (bool) $r['is_published'];
            return $r;
        },
        'summary' => function (string $from, array $args): array {
            $r = db_row("SELECT COUNT(*) AS total, COALESCE(SUM(t.result_status = 'PASS'), 0) AS pass, COALESCE(SUM(t.result_status = 'BACKLOG'), 0) AS backlog,
                                COALESCE(SUM(t.result_status = 'FAIL'), 0) AS fail, COALESCE(SUM(t.result_status = 'ABSENT'), 0) AS absent, COALESCE(SUM(t.result_status = 'WITHHELD'), 0) AS withheld,
                                AVG(t.percentage) AS avg_percent, AVG(t.sgpa) AS avg_sgpa" . $from, $args);
            return ['total' => (int) $r['total'], 'pass' => (int) $r['pass'], 'backlog' => (int) $r['backlog'], 'fail' => (int) $r['fail'], 'absent' => (int) $r['absent'],
                'withheld' => (int) $r['withheld'], 'avg_percent' => $r['avg_percent'] !== null ? round((float) $r['avg_percent'], 1) : null,
                'avg_sgpa' => $r['avg_sgpa'] !== null ? round((float) $r['avg_sgpa'], 2) : null];
        },
        'describe' => function (array $r): string {
            $s = db_row("SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) AS n FROM students WHERE id = ?", [(int) ($r['student_id'] ?? 0)]);
            return 'result of ' . ($s['n'] ?? '#' . ($r['student_id'] ?? ''));
        },
    ],
];
