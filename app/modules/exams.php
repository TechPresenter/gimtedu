<?php
/** Examinations (one exam = one exam type for a program/semester in a session). Workflow actions live in api/routes/exams.php. */
require_once APP_ROOT . '/app/services/exams.php';

$phases = exam_phase_sql('t');
$display = exam_display_status_sql('t');
$semesters = [];
for ($i = 1; $i <= 10; $i++) {
    $semesters[$i] = 'Semester ' . $i;
}

return [
    'table' => 'exams',
    'title' => 'Examinations',
    'singular' => 'Exam',
    'permission' => 'examination',
    'icon' => 'clipboard-list',
    'description' => 'Exams for each program and semester with their workflow status.',
    'select' => "t.*, $display AS display_status, et.name AS type_name, et.code AS type_code, et.is_final, p.short_name AS program_short, ss.name AS session_name,
                 CONCAT(COALESCE(p.short_name, 'All programs'), IFNULL(CONCAT(' · Sem ', t.semester_no), '')) AS cohort,
                 (SELECT COUNT(*) FROM exam_subjects es WHERE es.exam_id = t.id) AS subjects_count,
                 (SELECT COUNT(*) FROM exam_schedules sc WHERE sc.exam_id = t.id) AS papers_count,
                 (SELECT COUNT(*) FROM exam_students x WHERE x.exam_id = t.id AND x.is_eligible = 1) AS students_count,
                 (SELECT COUNT(*) FROM exam_marks m WHERE m.exam_id = t.id) AS marks_count,
                 (SELECT COUNT(*) FROM results r WHERE r.exam_id = t.id AND r.result_status = 'PASS') AS pass_count,
                 (SELECT COUNT(*) FROM results r WHERE r.exam_id = t.id AND r.result_status NOT IN ('ABSENT','WITHHELD')) AS appeared_count",
    'joins' => 'JOIN exam_types et ON et.id = t.exam_type_id LEFT JOIN programs p ON p.id = t.program_id LEFT JOIN academic_sessions ss ON ss.id = t.academic_session_id',
    'search' => ['t.name', 'et.name', 'p.short_name', 'p.name'],
    'search_placeholder' => 'Search exams, programs…',
    'order' => 't.start_date DESC, p.sort_order ASC, t.semester_no ASC, t.id DESC',
    'columns' => [
        ['key' => 'name', 'label' => 'Exam', 'format' => 'title', 'sub' => 'type_name', 'sortable' => true, 'link' => 'view'],
        ['key' => 'cohort', 'label' => 'Program / Semester', 'format' => 'text', 'sortable' => 'p.sort_order, t.semester_no', 'hidden' => true],
        ['key' => 'session_name', 'label' => 'Session', 'format' => 'text', 'sortable' => 'ss.start_date', 'hidden' => true],
        ['key' => 'start_date', 'label' => 'Dates', 'format' => 'date', 'sortable' => true],
        ['key' => 'end_date', 'label' => 'Ends', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'result_date', 'label' => 'Result date', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'subjects_count', 'label' => 'Subjects', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'students_count', 'label' => 'Students', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'marks_percent', 'label' => 'Marks', 'format' => 'percent', 'align' => 'right'],
        ['key' => 'pass_percent', 'label' => 'Pass %', 'format' => 'percent', 'align' => 'right', 'hidden' => true],
        ['key' => 'display_status', 'label' => 'Status', 'format' => 'badge', 'sortable' => $display],
    ],
    'export_columns' => [
        ['key' => 'name', 'label' => 'Exam'], ['key' => 'type_name', 'label' => 'Type'], ['key' => 'cohort', 'label' => 'Program / Semester'], ['key' => 'session_name', 'label' => 'Session'],
        ['key' => 'start_date', 'label' => 'Start date', 'format' => 'date'], ['key' => 'end_date', 'label' => 'End date', 'format' => 'date'], ['key' => 'result_date', 'label' => 'Result date', 'format' => 'date'],
        ['key' => 'subjects_count', 'label' => 'Subjects', 'format' => 'number'], ['key' => 'students_count', 'label' => 'Eligible students', 'format' => 'number'],
        ['key' => 'marks_percent', 'label' => 'Marks entered %', 'format' => 'number'], ['key' => 'pass_percent', 'label' => 'Pass %', 'format' => 'number'],
        ['key' => 'display_status', 'label' => 'Status', 'format' => 'badge'],
    ],
    'filters' => [
        ['key' => 'academic_session_id', 'label' => 'Session', 'source' => 'sessions', 'default' => current_session_id()],
        ['key' => 'phase', 'label' => 'Phase', 'sql_map' => $phases, 'options' => ['upcoming' => 'Upcoming', 'ongoing' => 'Ongoing', 'completed' => 'Completed', 'results_pending' => 'Results pending', 'published' => 'Results published']],
        ['key' => 'exam_type_id', 'label' => 'Type', 'source' => 'exam_types'],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs'],
        ['key' => 'semester_no', 'label' => 'Semester', 'options' => $semesters],
        ['key' => 'status', 'label' => 'Status', 'sql' => "$display = ?", 'options' => exam_statuses()],
    ],
    'scopes' => ['program_id' => 't.program_id', 'academic_session_id' => 't.academic_session_id'],
    'fields' => [
        ['type' => 'section', 'label' => 'Exam'],
        ['name' => 'exam_type_id', 'label' => 'Exam type', 'type' => 'select', 'required' => true, 'source' => 'exam_types', 'col' => 6],
        ['name' => 'academic_session_id', 'label' => 'Academic session', 'type' => 'select', 'required' => true, 'source' => 'sessions', 'default' => current_session_id(), 'col' => 6],
        ['name' => 'program_id', 'label' => 'Program', 'type' => 'combobox', 'required' => true, 'source' => 'programs', 'col' => 8],
        ['name' => 'semester_no', 'label' => 'Semester', 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 12, 'col' => 4],
        ['name' => 'name', 'label' => 'Exam name', 'type' => 'text', 'maxlength' => 190, 'col' => 12, 'placeholder' => 'Leave blank to name it automatically, e.g. "BBA Sem 3 — End Semester Examination (Dec 2026)"'],
        ['type' => 'section', 'label' => 'Dates & eligibility'],
        ['name' => 'start_date', 'label' => 'Starts on', 'type' => 'date', 'required' => true, 'col' => 4],
        ['name' => 'end_date', 'label' => 'Ends on', 'type' => 'date', 'required' => true, 'col' => 4],
        ['name' => 'result_date', 'label' => 'Expected result date', 'type' => 'date', 'col' => 4],
        ['name' => 'min_attendance_percent', 'label' => 'Minimum attendance', 'type' => 'decimal', 'min' => 0, 'max' => 100, 'step' => 0.01, 'suffix' => '%', 'col' => 6,
            'help' => 'Blank = institute default (' . rtrim(rtrim((string) setting('min_attendance_percent', 75), '0'), '.') . '%). 0 = no attendance requirement.'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => exam_statuses(), 'edit_only' => true, 'col' => 6,
            'help' => 'Verification and publishing are done from the exam workflow.'],
        ['name' => 'add_subjects', 'label' => 'Curriculum subjects', 'type' => 'toggle', 'default' => true, 'create_only' => true, 'col' => 6,
            'placeholder' => 'Add all subjects of this semester'],
        ['name' => 'instructions', 'label' => 'Instructions for candidates', 'type' => 'textarea', 'rows' => 3, 'col' => 12, 'placeholder' => 'Printed on admit cards, e.g. reporting time, items allowed…'],
    ],
    'bulk' => ['delete' => true],
    'form' => ['size' => 'lg'],
    'hooks' => [
        'transform_row' => function (array $r): array {
            $expected = (int) $r['subjects_count'] * (int) $r['students_count'];
            $r['marks_percent'] = $expected ? round(min(100, 100 * (int) $r['marks_count'] / $expected), 1) : null;
            $r['pass_percent'] = (int) $r['appeared_count'] ? round(100 * (int) $r['pass_count'] / (int) $r['appeared_count'], 1) : null;
            return $r;
        },
        'validate' => function (array $d, ?int $id, ?array $old): array {
            $errors = [];
            $get = fn ($k) => array_key_exists($k, $d) ? $d[$k] : ($old[$k] ?? null);
            $start = $get('start_date');
            $end = $get('end_date');
            if ($start && $end && $end < $start) {
                $errors['end_date'] = 'The exam cannot end before it starts.';
            }
            if ($get('result_date') && $end && $get('result_date') < $end) {
                $errors['result_date'] = 'Results cannot be declared before the exam ends.';
            }
            $program = $get('program_id') ? db_row('SELECT short_name, total_semesters FROM programs WHERE id = ?', [(int) $get('program_id')]) : null;
            if ($program && $get('semester_no') && (int) $get('semester_no') > (int) $program['total_semesters']) {
                $errors['semester_no'] = $program['short_name'] . ' has only ' . $program['total_semesters'] . ' semesters.';
            }
            if (!$errors && $get('exam_type_id') && $get('program_id') && $get('semester_no') && $get('status') !== 'cancelled') {
                $dup = db_row("SELECT e.name FROM exams e WHERE e.exam_type_id = ? AND e.academic_session_id = ? AND e.program_id = ? AND e.semester_no = ? AND e.status <> 'cancelled' AND e.id <> ?
                                 AND (e.exam_type_id NOT IN (SELECT id FROM exam_types WHERE code IN ('IA','SUPP','PRAC')) OR e.start_date = ?)",
                    [(int) $get('exam_type_id'), (int) $get('academic_session_id'), (int) $get('program_id'), (int) $get('semester_no'), (int) $id, $start]);
                if ($dup) {
                    $errors['exam_type_id'] = 'This exam already exists: "' . $dup['name'] . '".';
                }
            }
            if ($old) {
                $locked = in_array($old['status'], ['verified', 'published'], true);
                $newStatus = $d['status'] ?? $old['status'];
                if ($newStatus !== $old['status']) {
                    if (in_array($newStatus, ['verified', 'published'], true)) {
                        $errors['status'] = 'Use "Verify marks" / "Publish results" on the exam page to move to this status.';
                    } elseif ($locked) {
                        $errors['status'] = $old['status'] === 'published' ? 'Unpublish the results from the exam page first.' : 'Unlock the marks from the exam page first.';
                    } elseif ($newStatus === 'cancelled' && db_value('SELECT 1 FROM exam_marks WHERE exam_id = ? LIMIT 1', [$id])) {
                        $errors['status'] = 'Marks are already entered for this exam - it cannot be cancelled.';
                    }
                }
                foreach (['program_id' => 'Program', 'semester_no' => 'Semester', 'academic_session_id' => 'Session'] as $k => $label) {
                    if (array_key_exists($k, $d) && (string) $d[$k] !== (string) $old[$k]) {
                        if ($locked) {
                            $errors[$k] = "$label cannot be changed after marks are verified.";
                        } elseif (db_value('SELECT 1 FROM exam_students WHERE exam_id = ? LIMIT 1', [$id]) || db_value('SELECT 1 FROM exam_subjects WHERE exam_id = ? LIMIT 1', [$id])) {
                            $errors[$k] = "$label cannot be changed once subjects or students are added. Create a new exam instead.";
                        }
                    }
                }
            }
            return $errors;
        },
        'before_save' => function (array $d, ?int $id, ?array $old): array {
            $name = trim((string) ($d['name'] ?? ($old['name'] ?? '')));
            if ($name === '') {
                $type = db_value('SELECT name FROM exam_types WHERE id = ?', [(int) ($d['exam_type_id'] ?? $old['exam_type_id'] ?? 0)]);
                $prog = db_value('SELECT short_name FROM programs WHERE id = ?', [(int) ($d['program_id'] ?? $old['program_id'] ?? 0)]);
                $sem = $d['semester_no'] ?? $old['semester_no'] ?? null;
                $start = $d['start_date'] ?? $old['start_date'] ?? null;
                $d['name'] = trim(($prog ?: 'All programs') . ($sem ? " Sem $sem" : '') . ' — ' . ($type ?: 'Examination') . ($start ? ' (' . date('M Y', strtotime($start)) . ')' : ''));
            }
            if ($id === null) {
                $d['status'] = 'draft';
            }
            return $d;
        },
        'after_save' => function (int $id, array $d, ?array $old, array $input): void {
            if ($old === null && in_array($input['add_subjects'] ?? true, [true, 1, '1', 'true', 'on'], true)) {
                $e = exam_find($id);
                if ($e) {
                    exam_sync_subjects($e);
                }
            }
            if ($old && isset($d['start_date']) && $d['start_date'] !== $old['start_date']) {
                exam_assign_seats($id);
            }
        },
        'before_delete' => function (int $id, array $row): ?string {
            if (db_value('SELECT 1 FROM results WHERE exam_id = ? LIMIT 1', [$id]) || db_value('SELECT 1 FROM exam_marks WHERE exam_id = ? LIMIT 1', [$id])) {
                return '"' . $row['name'] . '" already has marks or results and cannot be deleted - they are part of the academic record of the students.';
            }
            return null;
        },
        'describe' => fn (array $r) => '"' . ($r['name'] ?? '#' . ($r['id'] ?? '')) . '"',
    ],
];
