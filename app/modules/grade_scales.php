<?php
/** Grading scale (percentage bands -> letter grade + grade point). Used for every subject grade, SGPA and CGPA. */
require_once APP_ROOT . '/app/services/exams.php';

return [
    'table' => 'grade_scales',
    'title' => 'Grading Scale',
    'singular' => 'Grade',
    'permission' => 'results',
    'icon' => 'award',
    'description' => 'Percentage bands, letter grades and grade points used for results, SGPA and CGPA.',
    'select' => "t.*, (SELECT COUNT(*) FROM exam_marks m WHERE m.grade = t.grade) AS used_count",
    'search' => ['t.grade', 't.description'],
    'search_placeholder' => 'Search grades…',
    'order' => 't.min_percent DESC, t.grade_point DESC',
    'per_page' => 50,
    'columns' => [
        ['key' => 'grade', 'label' => 'Grade', 'format' => 'title', 'sub' => 'description', 'sortable' => true, 'link' => 'view'],
        ['key' => 'min_percent', 'label' => 'From', 'format' => 'percent', 'align' => 'right', 'sortable' => true],
        ['key' => 'max_percent', 'label' => 'To', 'format' => 'percent', 'align' => 'right', 'sortable' => true],
        ['key' => 'grade_point', 'label' => 'Grade point', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'is_pass', 'label' => 'Pass grade', 'format' => 'boolean', 'sortable' => true],
        ['key' => 'used_count', 'label' => 'Awarded', 'format' => 'number', 'align' => 'center', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'is_pass', 'label' => 'Result', 'type' => 'select', 'options' => ['1' => 'Pass grades', '0' => 'Fail grades']],
    ],
    'fields' => [
        ['name' => 'grade', 'label' => 'Grade', 'type' => 'text', 'required' => true, 'unique' => true, 'maxlength' => 5, 'col' => 4, 'placeholder' => 'A+'],
        ['name' => 'description', 'label' => 'Description', 'type' => 'text', 'maxlength' => 60, 'col' => 8, 'placeholder' => 'Excellent'],
        ['name' => 'min_percent', 'label' => 'Minimum %', 'type' => 'decimal', 'required' => true, 'min' => 0, 'max' => 100, 'step' => 0.01, 'suffix' => '%', 'col' => 4],
        ['name' => 'max_percent', 'label' => 'Maximum %', 'type' => 'decimal', 'required' => true, 'min' => 0, 'max' => 100, 'step' => 0.01, 'suffix' => '%', 'col' => 4],
        ['name' => 'grade_point', 'label' => 'Grade point', 'type' => 'decimal', 'required' => true, 'min' => 0, 'max' => 10, 'step' => 0.01, 'col' => 4],
        ['name' => 'is_pass', 'label' => 'Pass grade', 'type' => 'toggle', 'default' => true, 'col' => 6, 'placeholder' => 'Students with this grade pass the subject'],
        ['name' => 'sort_order', 'label' => 'Display order', 'type' => 'number', 'min' => 0, 'max' => 99, 'col' => 6, 'help' => 'Blank = ordered automatically by percentage.'],
    ],
    'bulk' => ['delete' => true],
    'export' => true,
    'import' => false,
    // The grading system is institute policy: only result managers (exam controller / admins) may change it.
    'permissions' => ['create' => ['results', 'manage'], 'edit' => ['results', 'manage'], 'delete' => ['results', 'manage']],
    'form' => ['size' => 'md'],
    'hooks' => [
        'validate' => function (array $d, ?int $id, ?array $old): array {
            $errors = [];
            $get = fn ($k) => array_key_exists($k, $d) ? $d[$k] : ($old[$k] ?? null);
            $min = $get('min_percent');
            $max = $get('max_percent');
            if ($min !== null && $max !== null && (float) $max < (float) $min) {
                $errors['max_percent'] = 'The maximum must be greater than or equal to the minimum.';
                return $errors;
            }
            $grade = strtoupper(trim((string) $get('grade')));
            if ($grade === 'AB') {
                $errors['grade'] = '"AB" is reserved for absent students.';
            }
            if ($min === null || $max === null) {
                return $errors;
            }
            $others = db_all('SELECT id, grade, min_percent, max_percent, grade_point, is_pass FROM grade_scales WHERE id <> ?', [(int) $id]);
            foreach ($others as $o) {
                if ((float) $min <= (float) $o['max_percent'] && (float) $max >= (float) $o['min_percent']) {
                    $errors[(float) $min <= (float) $o['max_percent'] && (float) $min >= (float) $o['min_percent'] ? 'min_percent' : 'max_percent']
                        = sprintf('Overlaps grade %s (%s%% – %s%%). Adjust the range so bands do not overlap.', $o['grade'], rtrim(rtrim($o['min_percent'], '0'), '.'), rtrim(rtrim($o['max_percent'], '0'), '.'));
                    break;
                }
            }
            $gp = $get('grade_point');
            $pass = (int) $get('is_pass');
            if ($gp !== null && !$errors) {
                foreach ($others as $o) {
                    // Higher bands must never get fewer grade points than lower bands
                    if ((float) $o['min_percent'] > (float) $max && (float) $o['grade_point'] < (float) $gp) {
                        $errors['grade_point'] = "Grade {$o['grade']} covers higher percentages but has fewer grade points ({$o['grade_point']}).";
                        break;
                    }
                    if ((float) $o['max_percent'] < (float) $min && (float) $o['grade_point'] > (float) $gp) {
                        $errors['grade_point'] = "Grade {$o['grade']} covers lower percentages but has more grade points ({$o['grade_point']}).";
                        break;
                    }
                }
                if (!$pass && (float) $gp > 0 && !isset($errors['grade_point'])) {
                    $errors['grade_point'] = 'A failing grade should carry 0 grade points.';
                }
            }
            return $errors;
        },
        'before_save' => function (array $d, ?int $id, ?array $old): array {
            if (isset($d['grade'])) {
                $d['grade'] = strtoupper(trim((string) $d['grade']));
            }
            if (array_key_exists('sort_order', $d) && $d['sort_order'] === null) {
                $d['sort_order'] = 0;
            }
            return $d;
        },
        'after_save' => function (): void {
            grade_scale(true);
            // Re-number display order from the highest band down
            $i = 0;
            foreach (db_column('SELECT id FROM grade_scales ORDER BY min_percent DESC, grade_point DESC') as $gid) {
                db_exec('UPDATE grade_scales SET sort_order = ? WHERE id = ?', [$i++, (int) $gid]);
            }
        },
        'before_delete' => function (int $id, array $row): ?string {
            if ((int) db_value('SELECT COUNT(*) FROM grade_scales') <= 2) {
                return 'The grading scale needs at least one pass grade and one fail grade.';
            }
            if ((int) $row['is_pass'] === 0 && !(int) db_value('SELECT COUNT(*) FROM grade_scales WHERE is_pass = 0 AND id <> ?', [$id])) {
                return 'Grade ' . $row['grade'] . ' is the only fail grade and cannot be deleted.';
            }
            $n = (int) db_value("SELECT COUNT(*) FROM exam_marks m JOIN exams e ON e.id = m.exam_id WHERE m.grade = ? AND e.status = 'published'", [$row['grade']]);
            return $n ? "Grade {$row['grade']} appears on $n published subject result" . ($n === 1 ? '' : 's') . '. Edit the band instead of deleting it.' : null;
        },
        'describe' => fn (array $r) => 'grade "' . ($r['grade'] ?? '') . '"',
    ],
];
