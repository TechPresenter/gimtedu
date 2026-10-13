<?php
/** Placement: offers issued to students (CTC, joining date, offer letter, acceptance status). */
require_once APP_ROOT . '/app/services/placement.php';

$valid = PLACEMENT_VALID_OFFER_SQL;

return [
    'table' => 'placement_offers',
    'title' => 'Offers',
    'singular' => 'Offer',
    'permission' => 'placement',
    'icon' => 'badge-check',
    'description' => 'Job offers, CTC, joining dates and offer letters.',
    'select' => "t.*, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, s.photo AS student_photo,
                 CONCAT(s.student_uid, ' · ', p.short_name) AS student_sub, p.short_name AS program_short, dp.code AS department_code, dp.name AS department_name,
                 c.name AS company_name, c.logo AS company_logo, d.title AS drive_title, d.job_type",
    'joins' => 'JOIN students s ON s.id = t.student_id JOIN programs p ON p.id = s.program_id LEFT JOIN departments dp ON dp.id = s.department_id
                JOIN companies c ON c.id = t.company_id LEFT JOIN placement_drives d ON d.id = t.drive_id',
    'search' => ['s.first_name', 's.last_name', 's.student_uid', 'c.name', 't.job_role', 't.location', "CONCAT(s.first_name, ' ', s.last_name)"],
    'search_placeholder' => 'Search student, company, role or location…',
    'order' => 't.offer_date DESC, t.id DESC',
    'columns' => [
        ['key' => 'student_name', 'label' => 'Student', 'format' => 'person', 'image' => 'student_photo', 'sub' => 'student_sub', 'sortable' => 's.first_name'],
        ['key' => 'company_name', 'label' => 'Company & role', 'format' => 'title', 'sub' => 'job_role', 'sortable' => 'c.name'],
        ['key' => 'package_lpa', 'label' => 'CTC', 'format' => 'number', 'suffix' => ' LPA', 'align' => 'right', 'sortable' => true],
        ['key' => 'offer_date', 'label' => 'Offer date', 'format' => 'date', 'sortable' => true],
        ['key' => 'joining_date', 'label' => 'Joining', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'location', 'label' => 'Location', 'format' => 'text', 'hidden' => true],
        ['key' => 'department_code', 'label' => 'Dept', 'format' => 'text', 'hidden' => true, 'sortable' => 'dp.code'],
        ['key' => 'offer_letter', 'label' => 'Letter', 'format' => 'text', 'align' => 'center'],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'student_name', 'label' => 'Student'], ['key' => 'student_uid', 'label' => 'Student ID'], ['key' => 'program_short', 'label' => 'Program'],
        ['key' => 'department_name', 'label' => 'Department'], ['key' => 'company_name', 'label' => 'Company'], ['key' => 'job_role', 'label' => 'Role'],
        ['key' => 'package_lpa', 'label' => 'CTC (LPA)', 'format' => 'number'], ['key' => 'offer_date', 'label' => 'Offer date', 'format' => 'date'],
        ['key' => 'joining_date', 'label' => 'Joining date', 'format' => 'date'], ['key' => 'location', 'label' => 'Location'], ['key' => 'drive_title', 'label' => 'Drive'],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge'], ['key' => 'responded_at', 'label' => 'Responded on', 'format' => 'date'], ['key' => 'remarks', 'label' => 'Remarks'],
    ],
    'filters' => [
        ['key' => 'session', 'label' => 'Session', 'source' => 'sessions', 'sql' => 'EXISTS (SELECT 1 FROM academic_sessions ss WHERE ss.id = ? AND t.offer_date BETWEEN ss.start_date AND ss.end_date)'],
        ['key' => 'status', 'label' => 'Status', 'options' => placement_offer_statuses()],
        ['key' => 'company_id', 'label' => 'Company', 'source' => 'companies'],
        ['key' => 'department_id', 'label' => 'Department', 'source' => 'departments', 'column' => 's.department_id'],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs', 'column' => 's.program_id'],
        ['key' => 'package', 'label' => 'CTC band', 'options' => ['lt5' => 'Below 5 LPA', '5to8' => '5 – 8 LPA', '8to12' => '8 – 12 LPA', 'gt12' => '12 LPA and above'],
            'sql_map' => ['lt5' => 't.package_lpa < 5', '5to8' => 't.package_lpa >= 5 AND t.package_lpa < 8', '8to12' => 't.package_lpa >= 8 AND t.package_lpa < 12', 'gt12' => 't.package_lpa >= 12']],
        ['key' => 'letter', 'label' => 'Offer letter', 'options' => ['uploaded' => 'Uploaded', 'missing' => 'Missing'],
            'sql_map' => ['uploaded' => 't.offer_letter IS NOT NULL', 'missing' => 't.offer_letter IS NULL']],
        ['key' => 'offer_date', 'label' => 'Offer date', 'type' => 'daterange'],
    ],
    'fields' => [
        ['name' => 's1', 'type' => 'section', 'label' => 'Student & company'],
        ['name' => 'student_id', 'label' => 'Student', 'type' => 'combobox', 'source' => 'all_students', 'required' => true, 'readonly_on_edit' => true, 'col' => 12, 'placeholder' => 'Search student by name or ID…'],
        ['name' => 'company_id', 'label' => 'Company', 'type' => 'combobox', 'source' => 'companies', 'required' => true, 'col' => 6, 'placeholder' => 'Search company…'],
        ['name' => 'drive_id', 'label' => 'Drive', 'type' => 'combobox', 'source' => 'placement_drives', 'depends' => ['company_id' => 'company_id'], 'col' => 6,
            'placeholder' => 'Off-campus / no drive', 'help' => 'Optional — links the offer to the drive and the student\'s application.'],
        ['name' => 's2', 'type' => 'section', 'label' => 'Offer'],
        ['name' => 'job_role', 'label' => 'Job role', 'type' => 'text', 'required' => true, 'maxlength' => 150, 'col' => 8, 'placeholder' => 'e.g. Associate Software Engineer'],
        ['name' => 'package_lpa', 'label' => 'CTC (LPA)', 'type' => 'decimal', 'required' => true, 'step' => 0.01, 'min' => 0.5, 'max' => 999, 'col' => 4, 'suffix' => 'LPA'],
        ['name' => 'offer_date', 'label' => 'Offer date', 'type' => 'date', 'required' => true, 'col' => 4, 'default' => date('Y-m-d')],
        ['name' => 'joining_date', 'label' => 'Joining date', 'type' => 'date', 'col' => 4],
        ['name' => 'location', 'label' => 'Location', 'type' => 'text', 'maxlength' => 150, 'col' => 4, 'placeholder' => 'e.g. Bengaluru'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'offered', 'col' => 6, 'options' => placement_offer_statuses()],
        ['name' => 'responded_at', 'label' => 'Accepted / declined on', 'type' => 'date', 'col' => 6, 'help' => 'Filled automatically when the status changes.'],
        ['name' => 'offer_letter', 'label' => 'Offer letter', 'type' => 'file', 'category' => 'document', 'folder' => 'placement-offers', 'private' => true, 'col' => 12, 'help' => 'PDF or Word, kept private.'],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'text', 'maxlength' => 255, 'col' => 12],
    ],
    'scopes' => ['drive_id' => 't.drive_id', 'student_id' => 't.student_id', 'company_id' => 't.company_id', 'application_id' => 't.application_id'],
    'bulk' => ['delete' => true],
    'import' => false,
    'export' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $e = [];
            $offer = $data['offer_date'] ?? ($old['offer_date'] ?? null);
            $join = array_key_exists('joining_date', $data) ? $data['joining_date'] : ($old['joining_date'] ?? null);
            if ($offer && $join && $join < $offer) {
                $e['joining_date'] = 'Joining date cannot be before the offer date.';
            }
            if ($offer && $offer > date('Y-m-d', strtotime('+30 days'))) {
                $e['offer_date'] = 'Offer date cannot be more than 30 days in the future.';
            }
            $company = (int) ($data['company_id'] ?? ($old['company_id'] ?? 0));
            $drive = array_key_exists('drive_id', $data) ? $data['drive_id'] : ($old['drive_id'] ?? null);
            $student = (int) ($data['student_id'] ?? ($old['student_id'] ?? 0));
            if ($drive) {
                $d = db_row('SELECT company_id FROM placement_drives WHERE id = ?', [(int) $drive]);
                if (!$d) {
                    $e['drive_id'] = 'Selected drive is invalid.';
                } elseif ((int) $d['company_id'] !== $company) {
                    $e['drive_id'] = 'This drive belongs to a different company.';
                } elseif ($student && (int) db_value('SELECT COUNT(*) FROM placement_offers WHERE drive_id = ? AND student_id = ?' . ($id ? ' AND id <> ?' : ''), $id ? [(int) $drive, $student, $id] : [(int) $drive, $student])) {
                    $e['student_id'] = 'This student already has an offer from this drive.';
                }
            }
            if ($id && isset($data['status']) && $old && $data['status'] !== $old['status'] && !in_array($data['status'], placement_offer_transitions()[$old['status']] ?? [], true)) {
                $e['status'] = sprintf('An offer that is %s cannot be changed to %s.', $old['status'], $data['status']);
            }
            return $e;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            $drive = array_key_exists('drive_id', $data) ? $data['drive_id'] : ($old['drive_id'] ?? null);
            $student = (int) ($data['student_id'] ?? ($old['student_id'] ?? 0));
            if (array_key_exists('drive_id', $data)) {
                $data['application_id'] = $drive ? (db_value('SELECT id FROM placement_applications WHERE drive_id = ? AND student_id = ?', [(int) $drive, $student]) ?: null) : null;
            }
            $status = $data['status'] ?? ($old['status'] ?? 'offered');
            if (in_array($status, ['accepted', 'declined'], true) && (!$old || $old['status'] !== $status) && empty($data['responded_at'])) {
                $data['responded_at'] = date('Y-m-d');
            }
            if ($status === 'offered' && $old && $old['status'] !== 'offered') {
                $data['responded_at'] = null;
            }
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old): void {
            $o = db_row("SELECT o.*, c.name AS company_name, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name FROM placement_offers o
                         JOIN companies c ON c.id = o.company_id JOIN students s ON s.id = o.student_id WHERE o.id = ?", [$id]);
            if (!$o) {
                return;
            }
            if ($o['application_id'] && in_array($o['status'], ['offered', 'accepted', 'joined'], true)) {
                db_update('placement_applications', ['status' => 'selected'], 'id = ?', [(int) $o['application_id']]);
            }
            if ($old === null) {
                notify('perm:placement', 'placement', 'New offer recorded', $o['student_name'] . ' received an offer from ' . $o['company_name'] . ' (' . rtrim(rtrim(number_format((float) $o['package_lpa'], 2), '0'), '.') . ' LPA).', 'placement/offers', 'badge-check');
            }
        },
        'after_bulk' => function (array $ids, string $field, $value): void {
            if (in_array($value, ['accepted', 'declined'], true)) {
                db_exec('UPDATE placement_offers SET responded_at = COALESCE(responded_at, CURDATE()) WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
            }
            if (in_array($value, ['offered', 'accepted', 'joined'], true)) {
                db_exec("UPDATE placement_applications a JOIN placement_offers o ON o.application_id = a.id SET a.status = 'selected' WHERE o.id IN (" . implode(',', array_map('intval', $ids)) . ')');
            }
        },
        'describe' => function (array $row): string {
            $r = db_row("SELECT TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student, c.name AS company FROM students s, companies c WHERE s.id = ? AND c.id = ?",
                [(int) ($row['student_id'] ?? 0), (int) ($row['company_id'] ?? 0)]);
            return $r ? $r['student'] . ' — ' . $r['company'] . ' (' . rtrim(rtrim(number_format((float) ($row['package_lpa'] ?? 0), 2), '0'), '.') . ' LPA)' : '#' . ($row['id'] ?? '');
        },
    ],
];
