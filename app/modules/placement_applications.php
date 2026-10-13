<?php
/** Placement: student applications to drives (status + current round). Rounds are recorded via /api/placement/applications/{id}/rounds. */
require_once APP_ROOT . '/app/services/placement.php';

$valid = PLACEMENT_VALID_OFFER_SQL;
$cgpa = placement_cgpa_sql('s');

return [
    'table' => 'placement_applications',
    'title' => 'Applications',
    'singular' => 'Application',
    'permission' => 'placement',
    'icon' => 'file-user',
    'description' => 'Drive applications, interview rounds and selection status.',
    'select' => "t.*, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, s.photo AS student_photo, s.current_semester,
                 CONCAT(s.student_uid, ' · ', p.short_name) AS student_sub, p.short_name AS program_short,
                 d.title AS drive_title, d.job_role, d.drive_date, d.status AS drive_status, d.rounds, d.company_id, d.package_min, d.package_max, d.location AS drive_location, d.job_type,
                 c.name AS company_name, c.logo AS company_logo,
                 CONCAT(c.name, ' — ', d.job_role) AS drive_label, $cgpa AS cgpa,
                 (SELECT COUNT(*) FROM placement_interviews i WHERE i.application_id = t.id) AS rounds_count,
                 (SELECT COUNT(*) FROM placement_interviews i WHERE i.application_id = t.id AND i.result = 'cleared') AS rounds_cleared,
                 (SELECT o.status FROM placement_offers o WHERE o.application_id = t.id ORDER BY o.id DESC LIMIT 1) AS offer_status",
    'joins' => 'JOIN students s ON s.id = t.student_id JOIN programs p ON p.id = s.program_id JOIN placement_drives d ON d.id = t.drive_id JOIN companies c ON c.id = d.company_id',
    'search' => ['s.first_name', 's.last_name', 's.student_uid', 's.roll_no', 'c.name', 'd.title', 'd.job_role', "CONCAT(s.first_name, ' ', s.last_name)"],
    'search_placeholder' => 'Search student, ID, company or role…',
    'order' => 't.applied_at DESC, t.id DESC',
    'columns' => [
        ['key' => 'student_name', 'label' => 'Student', 'format' => 'person', 'image' => 'student_photo', 'sub' => 'student_sub', 'sortable' => 's.first_name'],
        ['key' => 'company_name', 'label' => 'Drive', 'format' => 'title', 'sub' => 'job_role', 'link' => '/placement/drives/{drive_id}', 'sortable' => 'c.name'],
        ['key' => 'cgpa', 'label' => 'CGPA', 'format' => 'text', 'align' => 'center', 'sortable' => 'cgpa'],
        ['key' => 'current_round', 'label' => 'Current round', 'format' => 'text', 'sortable' => true],
        ['key' => 'rounds_cleared', 'label' => 'Rounds cleared', 'format' => 'number', 'align' => 'center', 'sortable' => 'rounds_cleared', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
        ['key' => 'offer_status', 'label' => 'Offer', 'format' => 'badge', 'hidden' => true],
        ['key' => 'drive_date', 'label' => 'Drive date', 'format' => 'date', 'sortable' => 'd.drive_date', 'hidden' => true],
        ['key' => 'applied_at', 'label' => 'Applied', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'remarks', 'label' => 'Remarks', 'format' => 'truncate', 'truncate' => 40, 'hidden' => true],
    ],
    'export_columns' => [
        ['key' => 'student_name', 'label' => 'Student'], ['key' => 'student_uid', 'label' => 'Student ID'], ['key' => 'program_short', 'label' => 'Program'],
        ['key' => 'cgpa', 'label' => 'CGPA'], ['key' => 'company_name', 'label' => 'Company'], ['key' => 'drive_title', 'label' => 'Drive'], ['key' => 'job_role', 'label' => 'Role'],
        ['key' => 'current_round', 'label' => 'Current round'], ['key' => 'rounds_cleared', 'label' => 'Rounds cleared', 'format' => 'number'], ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
        ['key' => 'offer_status', 'label' => 'Offer', 'format' => 'badge'], ['key' => 'applied_at', 'label' => 'Applied on', 'format' => 'date'], ['key' => 'remarks', 'label' => 'Remarks'],
    ],
    'filters' => [
        ['key' => 'drive_id', 'label' => 'Drive', 'source' => 'placement_drives'],
        ['key' => 'company_id', 'label' => 'Company', 'source' => 'companies', 'column' => 'd.company_id'],
        ['key' => 'status', 'label' => 'Status', 'options' => placement_app_statuses()],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs', 'column' => 's.program_id'],
        ['key' => 'session', 'label' => 'Session', 'source' => 'sessions',
            'sql' => 'EXISTS (SELECT 1 FROM academic_sessions ss WHERE ss.id = ? AND COALESCE(d.drive_date, d.registration_deadline, DATE(d.created_at)) BETWEEN ss.start_date AND ss.end_date)'],
        ['key' => 'stage', 'label' => 'Stage', 'options' => ['in_process' => 'In process', 'closed' => 'Closed', 'with_offer' => 'Has offer', 'selected_no_offer' => 'Selected, offer pending'],
            'sql_map' => [
                'in_process' => "t.status IN ('applied', 'shortlisted', 'aptitude', 'technical', 'hr', 'on_hold')",
                'closed' => "t.status IN ('selected', 'rejected', 'withdrawn')",
                'with_offer' => "EXISTS (SELECT 1 FROM placement_offers o WHERE o.application_id = t.id AND o.status IN $valid)",
                'selected_no_offer' => "t.status = 'selected' AND NOT EXISTS (SELECT 1 FROM placement_offers o WHERE o.application_id = t.id OR (o.drive_id = t.drive_id AND o.student_id = t.student_id))",
            ]],
        ['key' => 'applied_at', 'label' => 'Applied', 'type' => 'daterange'],
    ],
    'fields' => [
        ['name' => 'drive_id', 'label' => 'Drive', 'type' => 'combobox', 'source' => 'placement_drives', 'required' => true, 'readonly_on_edit' => true, 'col' => 12, 'placeholder' => 'Search company or role…'],
        ['name' => 'student_id', 'label' => 'Student', 'type' => 'combobox', 'source' => 'students', 'required' => true, 'readonly_on_edit' => true, 'col' => 12, 'placeholder' => 'Search student by name or ID…'],
        ['name' => 'skip_eligibility', 'label' => 'Override eligibility criteria', 'type' => 'toggle', 'create_only' => true, 'col' => 12, 'form' => true,
            'help' => 'Register the student even if the CGPA / backlog / batch criteria are not met (e.g. company approved an exception).'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'applied', 'options' => placement_app_statuses()],
        ['name' => 'current_round', 'label' => 'Current round', 'type' => 'text', 'maxlength' => 60, 'placeholder' => 'e.g. Technical Interview'],
        ['name' => 'resume_path', 'label' => 'Resume', 'type' => 'file', 'category' => 'document', 'folder' => 'placement-resumes', 'private' => true, 'col' => 12],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'text', 'maxlength' => 255, 'col' => 12],
    ],
    'scopes' => ['drive_id' => 't.drive_id', 'student_id' => 't.student_id'],
    'bulk' => ['delete' => true],
    'import' => false,
    'export' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            if ($id !== null) {
                return [];
            }
            $e = [];
            if (!empty($data['drive_id']) && !empty($data['student_id'])) {
                if ((int) db_value('SELECT COUNT(*) FROM placement_applications WHERE drive_id = ? AND student_id = ?', [(int) $data['drive_id'], (int) $data['student_id']])) {
                    $e['student_id'] = 'This student has already applied for this drive.';
                } else {
                    $drive = placement_drive_row((int) $data['drive_id']);
                    if ($drive && in_array($drive['status'], ['completed', 'cancelled'], true)) {
                        $e['drive_id'] = 'Registrations are closed — this drive is ' . $drive['status'] . '.';
                    } elseif ($drive && ($reason = placement_ineligibility_reason($drive, (int) $data['student_id']))) {
                        $override = in_array($input['skip_eligibility'] ?? false, [true, 1, '1', 'true', 'on'], true);
                        if (!$override || str_starts_with($reason, 'Only active')) {
                            $e['student_id'] = 'Not eligible: ' . $reason;
                        }
                    }
                }
            }
            return $e;
        },
        'before_save' => function (array $data, ?int $id): array {
            unset($data['skip_eligibility']);
            if ($id === null) {
                $data['applied_at'] = date('Y-m-d H:i:s');
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            if ((int) db_value('SELECT COUNT(*) FROM placement_offers WHERE application_id = ? AND status IN ' . PLACEMENT_VALID_OFFER_SQL, [$id])) {
                return 'This application has an active offer. Revoke or delete the offer first.';
            }
            return null;
        },
        'describe' => function (array $row): string {
            $r = db_row("SELECT TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student, c.name AS company FROM students s, placement_drives d JOIN companies c ON c.id = d.company_id
                         WHERE s.id = ? AND d.id = ?", [(int) ($row['student_id'] ?? 0), (int) ($row['drive_id'] ?? 0)]);
            return $r ? $r['student'] . ' → ' . $r['company'] : '#' . ($row['id'] ?? '');
        },
    ],
];
