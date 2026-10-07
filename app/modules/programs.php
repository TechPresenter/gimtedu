<?php
/** Programs / degrees (BBA, MBA, B.Tech CSE ...). Also drive the public website (/programs/{slug}). */
require_once APP_ROOT . '/app/services/academics.php';

$levels = ['UG' => 'Undergraduate (UG)', 'PG' => 'Postgraduate (PG)', 'Diploma' => 'Diploma', 'Certificate' => 'Certificate', 'PhD' => 'Doctoral (Ph.D.)'];
$categories = ['Management' => 'Management', 'Technology' => 'Technology', 'Commerce' => 'Commerce', 'Science' => 'Science', 'Computer Applications' => 'Computer Applications',
    'Diploma' => 'Diploma', 'Certificate' => 'Certificate', 'Professional' => 'Professional'];

return [
    'table' => 'programs',
    'title' => 'Programs',
    'singular' => 'Program',
    'permission' => 'academics',
    'icon' => 'graduation-cap',
    'description' => 'Degree, diploma and certificate programs offered by the institute.',
    'select' => "t.*, d.name AS department_name, d.code AS department_code,
                 CONCAT(t.code, ' · ', COALESCE(t.duration_label, CONCAT(t.duration_years, ' Years')), ' · ', t.total_semesters, ' Sem') AS program_meta,
                 (SELECT COUNT(*) FROM students s WHERE s.program_id = t.id AND s.status = 'active') AS students_count,
                 (SELECT COUNT(*) FROM subjects sb WHERE sb.program_id = t.id AND sb.status = 'active') AS subjects_count,
                 (SELECT COUNT(*) FROM courses c WHERE c.program_id = t.id) AS courses_count",
    'joins' => 'JOIN departments d ON d.id = t.department_id',
    'search' => ['t.name', 't.short_name', 't.code', 't.slug', 't.category'],
    'order' => 't.sort_order ASC, t.name ASC',
    'columns' => [
        ['key' => 'name', 'label' => 'Program', 'format' => 'title', 'sub' => 'program_meta', 'sortable' => true, 'link' => 'view'],
        ['key' => 'image', 'label' => 'Image', 'format' => 'image', 'hidden' => true],
        ['key' => 'department_code', 'label' => 'Dept', 'format' => 'code', 'sortable' => 'd.code'],
        ['key' => 'department_name', 'label' => 'Department', 'format' => 'text', 'sortable' => 'd.name', 'hidden' => true],
        ['key' => 'level', 'label' => 'Level', 'format' => 'badge', 'sortable' => true, 'colors' => ['UG' => 'blue', 'PG' => 'purple', 'Diploma' => 'cyan', 'Certificate' => 'amber', 'PhD' => 'navy']],
        ['key' => 'intake_capacity', 'label' => 'Intake', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'students_count', 'label' => 'Students', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'subjects_count', 'label' => 'Subjects', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'courses_count', 'label' => 'Specializations', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'fee_per_year', 'label' => 'Fee / Year', 'format' => 'money', 'sortable' => true],
        ['key' => 'slug', 'label' => 'Website slug', 'format' => 'code', 'hidden' => true],
        ['key' => 'is_featured', 'label' => 'Featured', 'format' => 'boolean', 'sortable' => true],
        ['key' => 'sort_order', 'label' => 'Order', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'department_id', 'label' => 'Department', 'source' => 'departments'],
        ['key' => 'level', 'label' => 'Level', 'options' => array_combine(array_keys($levels), array_keys($levels))],
        ['key' => 'category', 'label' => 'Category', 'options' => $categories],
        ['key' => 'is_featured', 'label' => 'Featured', 'type' => 'boolean'],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'scopes' => ['department_id' => 't.department_id'],
    'fields' => [
        ['type' => 'section', 'label' => 'Program details'],
        ['name' => 'name', 'label' => 'Program name', 'type' => 'text', 'required' => true, 'col' => 8, 'maxlength' => 190, 'placeholder' => 'Bachelor of Business Administration'],
        ['name' => 'short_name', 'label' => 'Short name', 'type' => 'text', 'required' => true, 'col' => 4, 'maxlength' => 40, 'placeholder' => 'BBA'],
        ['name' => 'code', 'label' => 'Program code', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 4, 'maxlength' => 20, 'rules' => 'alpha_dash', 'placeholder' => 'BBA'],
        ['name' => 'department_id', 'label' => 'Department', 'type' => 'select', 'required' => true, 'source' => 'departments', 'col' => 8],
        ['name' => 'level', 'label' => 'Level', 'type' => 'select', 'required' => true, 'default' => 'UG', 'options' => $levels, 'col' => 4],
        ['name' => 'degree', 'label' => 'Degree awarded', 'type' => 'text', 'col' => 4, 'maxlength' => 80, 'placeholder' => "Bachelor's Degree"],
        ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'options' => $categories, 'col' => 4],
        ['type' => 'section', 'label' => 'Duration, intake & fee'],
        ['name' => 'duration_years', 'label' => 'Duration (years)', 'type' => 'decimal', 'required' => true, 'default' => 3, 'min' => 0.1, 'max' => 6, 'step' => 0.5, 'col' => 4],
        ['name' => 'duration_label', 'label' => 'Duration label', 'type' => 'text', 'col' => 4, 'maxlength' => 40, 'placeholder' => '3 Years', 'help' => 'Shown on the website, e.g. "6 Months".'],
        ['name' => 'total_semesters', 'label' => 'Total semesters', 'type' => 'number', 'required' => true, 'default' => 6, 'min' => 1, 'max' => 12, 'col' => 4],
        ['name' => 'total_credits', 'label' => 'Total credits', 'type' => 'number', 'min' => 0, 'max' => 400, 'col' => 4],
        ['name' => 'intake_capacity', 'label' => 'Annual intake (seats)', 'type' => 'number', 'min' => 1, 'max' => 5000, 'col' => 4],
        ['name' => 'fee_per_year', 'label' => 'Fee per year', 'type' => 'money', 'min' => 0, 'col' => 4],
        ['name' => 'fee_label', 'label' => 'Fee label', 'type' => 'text', 'col' => 4, 'maxlength' => 40, 'placeholder' => '/ Year'],
        ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 9999, 'col' => 4],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'col' => 4],
        ['type' => 'section', 'label' => 'Website content', 'help' => 'Displayed on the public program page /programs/{slug}.'],
        ['name' => 'slug', 'label' => 'URL slug', 'type' => 'slug', 'slug_from' => 'name', 'unique' => true, 'col' => 6, 'maxlength' => 190, 'prefix' => '/programs/', 'help' => 'Leave blank to generate from the name.'],
        ['name' => 'show_on_website', 'label' => 'Show on website', 'type' => 'toggle', 'default' => true, 'col' => 2],
        ['name' => 'is_featured', 'label' => 'Featured', 'type' => 'toggle', 'default' => false, 'col' => 2],
        ['name' => 'is_popular', 'label' => '"Most popular"', 'type' => 'toggle', 'default' => false, 'col' => 2],
        ['name' => 'overview', 'label' => 'Description / overview', 'type' => 'textarea', 'rows' => 3, 'maxlength' => 5000],
        ['name' => 'eligibility', 'label' => 'Eligibility', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 2000],
        ['name' => 'highlights', 'label' => 'Highlights', 'type' => 'textarea', 'rows' => 3, 'col' => 6, 'help' => 'One highlight per line.'],
        ['name' => 'career_prospects', 'label' => 'Career prospects', 'type' => 'textarea', 'rows' => 3, 'col' => 6, 'help' => 'One role per line.'],
        ['name' => 'image', 'label' => 'Program image', 'type' => 'image', 'folder' => 'programs', 'col' => 6],
        ['name' => 'brochure', 'label' => 'Brochure (PDF)', 'type' => 'file', 'category' => 'document', 'folder' => 'brochures', 'col' => 6],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'import' => true,
    'form' => ['size' => 'xl'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $years = (float) ($data['duration_years'] ?? ($old['duration_years'] ?? 0));
            $sems = (int) ($data['total_semesters'] ?? ($old['total_semesters'] ?? 0));
            if ($years && $sems && $sems > (int) ceil($years * 2) + 1) {
                $errors['total_semesters'] = sprintf('%d semesters is too many for a %s-year program.', $sems, rtrim(rtrim(number_format($years, 1), '0'), '.'));
            }
            if ($id && $old && $sems && $sems < (int) $old['total_semesters']) {
                $max = (int) db_value('SELECT GREATEST(COALESCE((SELECT MAX(semester_no) FROM subjects WHERE program_id = ?), 0), COALESCE((SELECT MAX(semester_no) FROM sections WHERE program_id = ?), 0))', [$id, $id]);
                if ($max > $sems) {
                    $errors['total_semesters'] = "Subjects or sections exist up to semester $max. Remove them before reducing the semesters.";
                }
            }
            $short = $data['short_name'] ?? null;
            if ($short) {
                $dup = db_value('SELECT name FROM programs WHERE short_name = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$short, $id] : [$short]);
                if ($dup) {
                    $errors['short_name'] = "Short name is already used by $dup.";
                }
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id): array {
            if (!empty($data['code'])) {
                $data['code'] = strtoupper($data['code']);
            }
            if (array_key_exists('slug', $data) && !$data['slug'] && !empty($data['name'])) {
                $data['slug'] = slugify($data['name']);
            }
            if (!empty($data['slug'])) {
                $base = $data['slug'];
                $n = 2;
                while (db_value('SELECT COUNT(*) FROM programs WHERE slug = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$data['slug'], $id] : [$data['slug']])) {
                    $data['slug'] = $base . '-' . $n++;
                }
            }
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old): void {
            $sems = (int) ($data['total_semesters'] ?? ($old['total_semesters'] ?? 0));
            if ($sems) {
                acad_sync_semesters($id, $sems);
                db_exec('DELETE FROM semesters WHERE program_id = ? AND number > ?', [$id, $sems]);
            }
        },
        'before_delete' => function (int $id, array $row): ?string {
            $usage = acad_usage($id, [['students', 'program_id', 'students'], ['admissions', 'program_id', 'admissions'], ['sections', 'program_id', 'sections'],
                ['subjects', 'program_id', 'subjects'], ['fee_structures', 'program_id', 'fee structures'], ['exams', 'program_id', 'exams']]);
            return $usage ? $row['name'] . ' has ' . implode(', ', $usage) . ' and cannot be deleted. Mark it inactive instead.' : null;
        },
    ],
];
