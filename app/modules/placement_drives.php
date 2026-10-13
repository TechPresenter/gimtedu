<?php
/** Placement: campus / pool / virtual recruitment drives with eligibility criteria. */
require_once APP_ROOT . '/app/services/placement.php';

$valid = PLACEMENT_VALID_OFFER_SQL;

return [
    'table' => 'placement_drives',
    'title' => 'Placement Drives',
    'singular' => 'Drive',
    'permission' => 'placement',
    'icon' => 'briefcase-business',
    'description' => 'Recruitment drives, job roles, CTC, eligibility and rounds.',
    'select' => "t.*, c.name AS company_name, c.logo AS company_logo, c.industry AS company_industry,
                 CASE WHEN t.package_min IS NULL AND t.package_max IS NULL THEN NULL
                      WHEN t.package_min IS NULL OR t.package_max IS NULL OR t.package_min = t.package_max THEN CONCAT(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM COALESCE(t.package_max, t.package_min))), ' LPA')
                      ELSE CONCAT(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM t.package_min)), '–', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM t.package_max)), ' LPA') END AS ctc_label,
                 (SELECT COUNT(*) FROM placement_applications a WHERE a.drive_id = t.id) AS applicants_count,
                 (SELECT COUNT(*) FROM placement_applications a WHERE a.drive_id = t.id AND a.status = 'selected') AS selected_count,
                 (SELECT COUNT(*) FROM placement_offers o WHERE o.drive_id = t.id AND o.status IN $valid) AS offers_count",
    'joins' => 'JOIN companies c ON c.id = t.company_id',
    'search' => ['t.title', 't.job_role', 'c.name', 't.location', 't.venue'],
    'search_placeholder' => 'Search drive, company, role or location…',
    'order' => 'COALESCE(t.drive_date, t.registration_deadline) DESC, t.id DESC',
    'columns' => [
        ['key' => 'title', 'label' => 'Drive & company', 'format' => 'title', 'sub' => 'job_role', 'link' => '/placement/drives/{id}', 'sortable' => true],
        ['key' => 'company_name', 'label' => 'Company', 'format' => 'person', 'image' => 'company_logo', 'sub' => 'company_industry', 'sortable' => 'c.name', 'hidden' => true],
        ['key' => 'job_type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true, 'hidden' => true, 'colors' => ['full_time' => 'navy', 'internship' => 'cyan', 'internship_ppo' => 'purple', 'contract' => 'slate']],
        ['key' => 'ctc_label', 'label' => 'CTC', 'format' => 'text', 'sortable' => 't.package_max'],
        ['key' => 'drive_date', 'label' => 'Drive date', 'format' => 'date', 'sortable' => true],
        ['key' => 'registration_deadline', 'label' => 'Reg. deadline', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'batch_year', 'label' => 'Batch', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'min_cgpa', 'label' => 'Min CGPA', 'format' => 'text', 'align' => 'center', 'hidden' => true],
        ['key' => 'location', 'label' => 'Location', 'format' => 'text', 'hidden' => true],
        ['key' => 'mode', 'label' => 'Mode', 'format' => 'badge', 'hidden' => true, 'colors' => ['on_campus' => 'navy', 'off_campus' => 'blue', 'virtual' => 'cyan', 'pool' => 'purple']],
        ['key' => 'applicants_count', 'label' => 'Applicants', 'format' => 'number', 'align' => 'center', 'sortable' => 'applicants_count'],
        ['key' => 'selected_count', 'label' => 'Selected', 'format' => 'number', 'align' => 'center', 'sortable' => 'selected_count', 'hidden' => true],
        ['key' => 'offers_count', 'label' => 'Offers', 'format' => 'number', 'align' => 'center', 'sortable' => 'offers_count', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true, 'colors' => ['upcoming' => 'amber', 'open' => 'green', 'ongoing' => 'blue', 'completed' => 'navy', 'cancelled' => 'red']],
    ],
    'export_columns' => [
        ['key' => 'title', 'label' => 'Drive'], ['key' => 'company_name', 'label' => 'Company'], ['key' => 'job_role', 'label' => 'Job role(s)'],
        ['key' => 'job_type', 'label' => 'Type', 'format' => 'badge'], ['key' => 'openings', 'label' => 'Openings', 'format' => 'number'], ['key' => 'ctc_label', 'label' => 'CTC'],
        ['key' => 'location', 'label' => 'Location'], ['key' => 'mode', 'label' => 'Mode', 'format' => 'badge'], ['key' => 'batch_year', 'label' => 'Batch'],
        ['key' => 'min_cgpa', 'label' => 'Min CGPA'], ['key' => 'max_backlogs', 'label' => 'Max backlogs'], ['key' => 'registration_deadline', 'label' => 'Registration deadline', 'format' => 'date'],
        ['key' => 'drive_date', 'label' => 'Drive date', 'format' => 'date'], ['key' => 'rounds', 'label' => 'Rounds'], ['key' => 'applicants_count', 'label' => 'Applicants', 'format' => 'number'],
        ['key' => 'selected_count', 'label' => 'Selected', 'format' => 'number'], ['key' => 'offers_count', 'label' => 'Offers', 'format' => 'number'], ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
    ],
    'filters' => [
        ['key' => 'session', 'label' => 'Session', 'source' => 'sessions',
            'sql' => 'EXISTS (SELECT 1 FROM academic_sessions ss WHERE ss.id = ? AND COALESCE(t.drive_date, t.registration_deadline, DATE(t.created_at)) BETWEEN ss.start_date AND ss.end_date)'],
        ['key' => 'phase', 'label' => 'Phase', 'options' => ['active' => 'Upcoming & ongoing', 'completed' => 'Completed', 'cancelled' => 'Cancelled'],
            'sql_map' => ['active' => "t.status IN ('upcoming', 'open', 'ongoing')", 'completed' => "t.status = 'completed'", 'cancelled' => "t.status = 'cancelled'"]],
        ['key' => 'status', 'label' => 'Status', 'options' => placement_drive_statuses()],
        ['key' => 'company_id', 'label' => 'Company', 'source' => 'companies'],
        ['key' => 'job_type', 'label' => 'Type', 'options' => placement_job_types()],
        ['key' => 'program', 'label' => 'Program', 'source' => 'programs',
            'sql' => '(t.eligible_programs IS NULL OR JSON_LENGTH(t.eligible_programs) = 0 OR JSON_CONTAINS(t.eligible_programs, CAST(? AS CHAR)))'],
        ['key' => 'mode', 'label' => 'Mode', 'options' => ['on_campus' => 'On campus', 'off_campus' => 'Off campus', 'virtual' => 'Virtual', 'pool' => 'Pool campus']],
        ['key' => 'drive_date', 'label' => 'Drive date', 'type' => 'daterange'],
    ],
    'fields' => [
        ['name' => 's1', 'type' => 'section', 'label' => 'Drive'],
        ['name' => 'company_id', 'label' => 'Company', 'type' => 'combobox', 'source' => 'companies', 'required' => true, 'col' => 6, 'placeholder' => 'Search company…'],
        ['name' => 'title', 'label' => 'Drive title', 'type' => 'text', 'required' => true, 'col' => 6, 'maxlength' => 190, 'placeholder' => 'e.g. Campus Hiring 2027 — Digital'],
        ['name' => 'job_role', 'label' => 'Job role(s)', 'type' => 'text', 'required' => true, 'col' => 8, 'maxlength' => 150, 'placeholder' => 'e.g. Systems Engineer, Data Analyst', 'help' => 'Separate multiple roles with commas.'],
        ['name' => 'job_type', 'label' => 'Job type', 'type' => 'select', 'required' => true, 'default' => 'full_time', 'col' => 4, 'options' => placement_job_types()],
        ['name' => 'openings', 'label' => 'Openings', 'type' => 'number', 'min' => 1, 'max' => 5000, 'col' => 3],
        ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'required' => true, 'default' => 'on_campus', 'col' => 3, 'options' => ['on_campus' => 'On campus', 'off_campus' => 'Off campus', 'virtual' => 'Virtual', 'pool' => 'Pool campus']],
        ['name' => 'location', 'label' => 'Job location', 'type' => 'text', 'col' => 6, 'maxlength' => 150, 'placeholder' => 'e.g. Noida / Bengaluru'],
        ['name' => 'venue', 'label' => 'Venue / link', 'type' => 'text', 'col' => 12, 'maxlength' => 190, 'placeholder' => 'e.g. Seminar Hall, Block A — or the virtual meeting link'],
        ['name' => 'description', 'label' => 'Job description', 'type' => 'textarea', 'rows' => 3],
        ['name' => 's2', 'type' => 'section', 'label' => 'Compensation', 'help' => 'Annual CTC in lakhs per annum (LPA). Stipend is the monthly amount for internships.'],
        ['name' => 'package_min', 'label' => 'CTC from (LPA)', 'type' => 'decimal', 'step' => 0.01, 'min' => 0, 'max' => 999, 'col' => 4, 'suffix' => 'LPA'],
        ['name' => 'package_max', 'label' => 'CTC up to (LPA)', 'type' => 'decimal', 'step' => 0.01, 'min' => 0, 'max' => 999, 'col' => 4, 'suffix' => 'LPA'],
        ['name' => 'stipend', 'label' => 'Stipend / month', 'type' => 'money', 'min' => 0, 'max' => 1000000, 'col' => 4],
        ['name' => 's3', 'type' => 'section', 'label' => 'Eligibility', 'help' => 'Eligible students are computed from the program, passing-out batch and the latest published results.'],
        ['name' => 'eligible_programs', 'label' => 'Eligible programs', 'type' => 'multiselect', 'source' => 'programs', 'col' => 12, 'help' => 'Leave empty to open the drive to all programs.'],
        ['name' => 'batch_year', 'label' => 'Passing-out batch', 'type' => 'number', 'min' => 2000, 'max' => 2100, 'col' => 3, 'placeholder' => date('n') >= 7 ? (string) (date('Y') + 1) : date('Y')],
        ['name' => 'min_cgpa', 'label' => 'Minimum CGPA', 'type' => 'decimal', 'step' => 0.01, 'min' => 0, 'max' => 10, 'col' => 3],
        ['name' => 'max_backlogs', 'label' => 'Max active backlogs', 'type' => 'number', 'min' => 0, 'max' => 20, 'col' => 3],
        ['name' => 'min_percentage', 'label' => 'Min previous %', 'type' => 'decimal', 'step' => 0.01, 'min' => 0, 'max' => 100, 'col' => 3, 'help' => '10+2 / graduation %'],
        ['name' => 'eligibility_notes', 'label' => 'Other eligibility notes', 'type' => 'textarea', 'rows' => 2],
        ['name' => 's4', 'type' => 'section', 'label' => 'Schedule & selection process'],
        ['name' => 'registration_deadline', 'label' => 'Registration deadline', 'type' => 'date', 'col' => 4],
        ['name' => 'drive_date', 'label' => 'Drive date', 'type' => 'date', 'col' => 4],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'upcoming', 'col' => 4, 'options' => placement_drive_statuses()],
        ['name' => 'rounds', 'label' => 'Selection rounds (in order)', 'type' => 'text', 'col' => 12, 'maxlength' => 255, 'placeholder' => 'Aptitude Test, Technical Interview, HR Interview',
            'help' => 'Comma separated. Used to move applicants from round to round.'],
    ],
    'scopes' => ['company_id' => 't.company_id'],
    'bulk' => ['delete' => true, 'status' => ['upcoming', 'open', 'ongoing', 'completed', 'cancelled']],
    'import' => false,
    'export' => true,
    'form' => ['size' => 'xl'],
    'view' => ['type' => 'page', 'url' => '/placement/drives/{id}'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $e = [];
            $min = array_key_exists('package_min', $data) ? $data['package_min'] : ($old['package_min'] ?? null);
            $max = array_key_exists('package_max', $data) ? $data['package_max'] : ($old['package_max'] ?? null);
            if ($min !== null && $max !== null && (float) $max < (float) $min) {
                $e['package_max'] = 'Maximum CTC cannot be lower than the minimum.';
            }
            $reg = array_key_exists('registration_deadline', $data) ? $data['registration_deadline'] : ($old['registration_deadline'] ?? null);
            $date = array_key_exists('drive_date', $data) ? $data['drive_date'] : ($old['drive_date'] ?? null);
            if ($reg && $date && $reg > $date) {
                $e['registration_deadline'] = 'The registration deadline must be on or before the drive date.';
            }
            if (!empty($data['eligible_programs'])) {
                $ids = array_values(array_filter(array_map('intval', (array) json_decode((string) $data['eligible_programs'], true))));
                if ($ids && (int) db_value('SELECT COUNT(*) FROM programs WHERE id IN (' . implode(',', $ids) . ')') !== count(array_unique($ids))) {
                    $e['eligible_programs'] = 'One or more selected programs are invalid.';
                }
            }
            $status = $data['status'] ?? ($old['status'] ?? 'upcoming');
            if ($status === 'completed' && !$date) {
                $e['drive_date'] = 'Set the drive date before marking the drive completed.';
            }
            return $e;
        },
        'before_save' => function (array $data): array {
            if (array_key_exists('eligible_programs', $data)) {
                $ids = $data['eligible_programs'] ? array_values(array_unique(array_filter(array_map('intval', (array) json_decode((string) $data['eligible_programs'], true))))) : [];
                $data['eligible_programs'] = $ids ? json_encode($ids) : null;
            }
            if (array_key_exists('rounds', $data)) {
                $data['rounds'] = implode(', ', placement_drive_rounds($data['rounds'])) ?: null;
            }
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old): void {
            $d = placement_drive_row($id);
            if (!$d) {
                return;
            }
            if ($old === null) {
                notify('perm:placement', 'placement', 'New placement drive', $d['company_name'] . ' — ' . $d['title'] . ($d['drive_date'] ? ' on ' . format_date($d['drive_date']) : ''), 'placement/drives/' . $id, 'briefcase-business');
            } elseif (isset($data['status']) && $data['status'] !== $old['status'] && in_array($data['status'], ['open', 'cancelled'], true)) {
                notify('perm:placement', 'placement', $data['status'] === 'open' ? 'Drive registrations open' : 'Drive cancelled', $d['company_name'] . ' — ' . $d['title'], 'placement/drives/' . $id, $data['status'] === 'open' ? 'megaphone' : 'calendar-x');
            }
        },
        'before_delete' => function (int $id, array $row): ?string {
            $apps = (int) db_value('SELECT COUNT(*) FROM placement_applications WHERE drive_id = ?', [$id]);
            $offers = (int) db_value('SELECT COUNT(*) FROM placement_offers WHERE drive_id = ?', [$id]);
            if ($apps || $offers) {
                return '"' . $row['title'] . '" has ' . implode(' and ', array_filter([$apps ? "$apps application(s)" : null, $offers ? "$offers offer(s)" : null]))
                    . '. Mark the drive cancelled instead of deleting it.';
            }
            return null;
        },
        'describe' => function (array $row): string {
            $company = isset($row['company_id']) ? db_value('SELECT name FROM companies WHERE id = ?', [(int) $row['company_id']]) : null;
            return '"' . ($company ? $company . ' — ' : '') . ($row['title'] ?? '') . '"';
        },
    ],
];
