<?php
/** Subjects of an exam with their maximum and pass marks (embedded on the exam page with scope exam_id). */
require_once APP_ROOT . '/app/services/exams.php';

return [
    'table' => 'exam_subjects',
    'title' => 'Exam Subjects',
    'singular' => 'Exam Subject',
    'permission' => 'examination',
    'icon' => 'book-open',
    'description' => 'Papers of the exam with internal, external and practical maximum marks.',
    'select' => "t.*, sb.code AS subject_code, sb.name AS subject_name, sb.type AS subject_type, sb.credits, (t.max_internal + t.max_external + t.max_practical) AS max_total,
                 sch.exam_date, sch.start_time,
                 (SELECT COUNT(*) FROM exam_marks m WHERE m.exam_id = t.exam_id AND m.subject_id = t.subject_id) AS marks_count,
                 (SELECT COUNT(*) FROM exam_students x WHERE x.exam_id = t.exam_id AND x.is_eligible = 1) AS students_count",
    'joins' => 'JOIN subjects sb ON sb.id = t.subject_id LEFT JOIN exam_schedules sch ON sch.exam_id = t.exam_id AND sch.subject_id = t.subject_id',
    'search' => ['sb.name', 'sb.code'],
    'search_placeholder' => 'Search subjects…',
    'order' => "FIELD(sb.type, 'theory', 'elective', 'lab', 'practical', 'project'), sb.code",
    'per_page' => 50,
    'columns' => [
        ['key' => 'subject_name', 'label' => 'Subject', 'format' => 'title', 'sub' => 'subject_code', 'sortable' => 'sb.name', 'link' => 'view'],
        ['key' => 'subject_type', 'label' => 'Type', 'format' => 'badge', 'sortable' => 'sb.type', 'colors' => ['theory' => 'blue', 'lab' => 'purple', 'project' => 'cyan', 'practical' => 'purple'], 'hidden' => true],
        ['key' => 'credits', 'label' => 'Credits', 'format' => 'number', 'align' => 'center', 'sortable' => 'sb.credits'],
        ['key' => 'max_internal', 'label' => 'Internal', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'max_external', 'label' => 'External', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'max_practical', 'label' => 'Practical', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'max_total', 'label' => 'Max', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'pass_marks', 'label' => 'Pass', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'exam_date', 'label' => 'Paper on', 'format' => 'date', 'sortable' => 'sch.exam_date', 'hidden' => true],
        ['key' => 'marks_count', 'label' => 'Marks entered', 'format' => 'number', 'align' => 'center'],
    ],
    'filters' => [
        ['key' => 'subject_type', 'label' => 'Type', 'column' => 'sb.type', 'options' => ['theory' => 'Theory', 'lab' => 'Lab', 'practical' => 'Practical', 'project' => 'Project']],
    ],
    'scopes' => ['exam_id' => 't.exam_id'],
    'fields' => [
        ['name' => 'exam_id', 'type' => 'hidden', 'form' => true],
        ['name' => 'subject_id', 'label' => 'Subject', 'type' => 'combobox', 'required' => true, 'readonly_on_edit' => true, 'col' => 12, 'depends' => ['exam_id' => 'exam_id'],
            'source' => ['table' => 'subjects sb', 'joins' => "JOIN exams ex ON (ex.program_id IS NULL OR ex.program_id = sb.program_id) JOIN exam_types et ON et.id = ex.exam_type_id",
                'value' => 'sb.id', 'label' => "CONCAT(sb.code, ' — ', sb.name)", 'sub' => "CONCAT('Sem ', sb.semester_no, ' · ', sb.type, ' · ', sb.credits, ' credits')",
                'where' => "sb.status = 'active' AND (ex.semester_no IS NULL OR ex.semester_no = sb.semester_no OR et.code = 'SUPP')", 'order' => 'sb.semester_no, sb.code',
                'search' => ['sb.name', 'sb.code'], 'filters' => ['exam_id' => 'ex.id']]],
        ['name' => 'max_internal', 'label' => 'Internal max', 'type' => 'number', 'min' => 0, 'max' => 500, 'col' => 3, 'help' => 'Blank = subject default'],
        ['name' => 'max_external', 'label' => 'External max', 'type' => 'number', 'min' => 0, 'max' => 500, 'col' => 3],
        ['name' => 'max_practical', 'label' => 'Practical max', 'type' => 'number', 'min' => 0, 'max' => 500, 'col' => 3],
        ['name' => 'pass_marks', 'label' => 'Pass marks', 'type' => 'number', 'min' => 0, 'max' => 1500, 'col' => 3],
    ],
    'bulk' => ['delete' => true],
    'export' => true,
    'form' => ['size' => 'md'],
    'hooks' => [
        'validate' => function (array $d, ?int $id, ?array $old): array {
            $errors = [];
            $examId = (int) ($d['exam_id'] ?? $old['exam_id'] ?? 0);
            $e = $examId ? exam_find($examId) : null;
            if (!$e) {
                return ['subject_id' => 'Open an exam to add its subjects.'];
            }
            if (exam_is_locked($e) || $e['status'] === 'cancelled') {
                return ['subject_id' => $e['status'] === 'cancelled' ? 'This exam is cancelled.' : 'Marks of this exam are locked. Unlock the marks to change its subjects.'];
            }
            $subjectId = (int) ($d['subject_id'] ?? $old['subject_id'] ?? 0);
            if (!$id && db_value('SELECT 1 FROM exam_subjects WHERE exam_id = ? AND subject_id = ?', [$examId, $subjectId])) {
                $errors['subject_id'] = 'This subject is already part of the exam.';
            }
            $subject = db_row('SELECT * FROM subjects WHERE id = ?', [$subjectId]);
            if (!$id && $subject && $e['program_id'] && (int) $subject['program_id'] !== (int) $e['program_id']) {
                $errors['subject_id'] = 'Choose a subject of ' . $e['program_short'] . '.';
            }
            if ($subject && !$errors) {
                $def = exam_subject_defaults($subject, $e);
                $val = fn ($k) => array_key_exists($k, $d) && $d[$k] !== null ? (int) $d[$k] : ($old[$k] ?? $def[$k]);
                $total = $val('max_internal') + $val('max_external') + $val('max_practical');
                if ($total <= 0) {
                    $errors['max_external'] = 'At least one component needs maximum marks.';
                } elseif ($val('pass_marks') > $total) {
                    $errors['pass_marks'] = "Pass marks cannot exceed the maximum ($total).";
                }
                if ($id) {
                    $hi = db_row('SELECT MAX(internal_marks) AS i, MAX(external_marks) AS x, MAX(practical_marks) AS p FROM exam_marks WHERE exam_id = ? AND subject_id = ?', [$examId, $subjectId]);
                    foreach (['max_internal' => 'i', 'max_external' => 'x', 'max_practical' => 'p'] as $k => $c) {
                        if ($hi && $hi[$c] !== null && $val($k) < (float) $hi[$c]) {
                            $errors[$k] = 'Marks up to ' . rtrim(rtrim((string) $hi[$c], '0'), '.') . ' are already entered - the maximum cannot be lower.';
                        }
                    }
                }
            }
            return $errors;
        },
        'before_save' => function (array $d, ?int $id, ?array $old): array {
            $examId = (int) ($d['exam_id'] ?? $old['exam_id'] ?? 0);
            $subject = db_row('SELECT * FROM subjects WHERE id = ?', [(int) ($d['subject_id'] ?? $old['subject_id'] ?? 0)]);
            $e = exam_find($examId);
            if ($subject && $e) {
                foreach (exam_subject_defaults($subject, $e) as $k => $v) {
                    if (!$id && (!array_key_exists($k, $d) || $d[$k] === null)) {
                        $d[$k] = $v;
                    } elseif ($id && array_key_exists($k, $d) && $d[$k] === null) {
                        $d[$k] = $old[$k];
                    }
                }
            }
            return $d;
        },
        'after_save' => function (int $id, array $d, ?array $old): void {
            if ($old === null) {
                $examId = (int) ($d['exam_id'] ?? 0);
                $e = db_row('SELECT status FROM exams WHERE id = ?', [$examId]);
                if ($e && $e['status'] === 'scheduled') {
                    db_update('exams', ['status' => 'draft'], 'id = ?', [$examId]); // a new paper still needs a date
                }
            }
        },
        'before_delete' => function (int $id, array $row): ?string {
            $e = exam_find((int) $row['exam_id']);
            if ($e && (exam_is_locked($e) || $e['status'] === 'cancelled')) {
                return 'Marks of this exam are locked - subjects cannot be removed.';
            }
            $n = (int) db_value('SELECT COUNT(*) FROM exam_marks WHERE exam_id = ? AND subject_id = ?', [$row['exam_id'], $row['subject_id']]);
            return $n ? "Marks are already entered for $n student" . ($n === 1 ? '' : 's') . ' in this subject. Clear the marks before removing it.' : null;
        },
        'after_delete' => function (int $id, array $row): void {
            db_exec('DELETE FROM exam_schedules WHERE exam_id = ? AND subject_id = ?', [$row['exam_id'], $row['subject_id']]);
            exam_refresh_schedule_status((int) $row['exam_id']);
        },
        'describe' => function (array $r): string {
            $s = db_row('SELECT code, name FROM subjects WHERE id = ?', [(int) ($r['subject_id'] ?? 0)]);
            $e = db_value('SELECT name FROM exams WHERE id = ?', [(int) ($r['exam_id'] ?? 0)]);
            return ($s ? $s['code'] . ' ' . $s['name'] : '#' . ($r['id'] ?? '')) . ($e ? ' in "' . $e . '"' : '');
        },
    ],
];
