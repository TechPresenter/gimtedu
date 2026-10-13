<?php
/** Placement: interview rounds of an application (embedded under an application; edits re-sync the application status). */
require_once APP_ROOT . '/app/services/placement.php';

return [
    'table' => 'placement_interviews',
    'title' => 'Interview Rounds',
    'singular' => 'Interview Round',
    'permission' => 'placement',
    'icon' => 'messages-square',
    'description' => 'Round-wise interview schedule, results, scores and feedback.',
    'select' => "t.*, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, s.photo AS student_photo, c.name AS company_name, d.job_role, a.drive_id",
    'joins' => 'JOIN placement_applications a ON a.id = t.application_id JOIN students s ON s.id = a.student_id JOIN placement_drives d ON d.id = a.drive_id JOIN companies c ON c.id = d.company_id',
    'search' => ['t.round_name', 's.first_name', 's.last_name', 's.student_uid', 'c.name', 't.panel'],
    'order' => 'COALESCE(t.scheduled_at, t.created_at) DESC, t.id DESC',
    'columns' => [
        ['key' => 'round_name', 'label' => 'Round', 'format' => 'title', 'sub' => 'company_name', 'sortable' => true],
        ['key' => 'student_name', 'label' => 'Student', 'format' => 'person', 'image' => 'student_photo', 'sub' => 'student_uid', 'sortable' => 's.first_name'],
        ['key' => 'scheduled_at', 'label' => 'Date & time', 'format' => 'datetime', 'sortable' => true],
        ['key' => 'mode', 'label' => 'Mode', 'format' => 'badge', 'hidden' => true, 'colors' => ['in_person' => 'navy', 'online' => 'cyan', 'telephonic' => 'slate']],
        ['key' => 'panel', 'label' => 'Panel', 'format' => 'truncate', 'truncate' => 30, 'hidden' => true],
        ['key' => 'score', 'label' => 'Score', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'result', 'label' => 'Result', 'format' => 'badge', 'sortable' => true],
        ['key' => 'feedback', 'label' => 'Feedback', 'format' => 'truncate', 'truncate' => 32],
    ],
    'filters' => [
        ['key' => 'result', 'label' => 'Result', 'options' => ['pending' => 'Pending', 'cleared' => 'Cleared', 'failed' => 'Not cleared', 'absent' => 'Absent']],
        ['key' => 'drive_id', 'label' => 'Drive', 'source' => 'placement_drives', 'column' => 'a.drive_id'],
        ['key' => 'scheduled_at', 'label' => 'Date', 'type' => 'daterange'],
    ],
    'fields' => [
        ['name' => 'application_id', 'label' => 'Application', 'type' => 'number', 'create_only' => true, 'hidden' => true, 'col' => 12],
        ['name' => 'round_name', 'label' => 'Round', 'type' => 'text', 'required' => true, 'maxlength' => 60, 'placeholder' => 'e.g. Technical Interview'],
        ['name' => 'scheduled_at', 'label' => 'Date & time', 'type' => 'datetime', 'required' => true],
        ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['in_person' => 'In person', 'online' => 'Online', 'telephonic' => 'Telephonic']],
        ['name' => 'result', 'label' => 'Result', 'type' => 'select', 'required' => true, 'default' => 'pending', 'options' => ['pending' => 'Pending', 'cleared' => 'Cleared', 'failed' => 'Not cleared', 'absent' => 'Absent']],
        ['name' => 'score', 'label' => 'Score (out of 100)', 'type' => 'decimal', 'step' => 0.01, 'min' => 0, 'max' => 100],
        ['name' => 'panel', 'label' => 'Panel / interviewer', 'type' => 'text', 'maxlength' => 255],
        ['name' => 'feedback', 'label' => 'Feedback', 'type' => 'textarea', 'rows' => 3, 'maxlength' => 500],
    ],
    'scopes' => ['application_id' => 't.application_id'],
    'bulk' => ['delete' => true],
    'import' => false,
    'export' => true,
    'form' => ['size' => 'md'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old = null): array {
            if ($id === null && (empty($data['application_id']) || !db_value('SELECT COUNT(*) FROM placement_applications WHERE id = ?', [(int) $data['application_id']]))) {
                return ['application_id' => 'Open an application to add interview rounds to it.'];
            }
            $appId = (int) ($data['application_id'] ?? ($old['application_id'] ?? 0));
            $changesResult = $id === null || (isset($data['result']) && $old && $data['result'] !== $old['result']);
            if ($appId && $changesResult && (int) db_value('SELECT COUNT(*) FROM placement_offers o JOIN placement_applications a ON a.id = ?
                    WHERE (o.application_id = a.id OR (o.drive_id = a.drive_id AND o.student_id = a.student_id)) AND o.status IN ' . PLACEMENT_VALID_OFFER_SQL, [$appId])) {
                return ['result' => 'The student already holds an offer from this drive. Revoke the offer before changing round results.'];
            }
            return [];
        },
        'after_save' => function (int $id, array $data, ?array $old): void {
            $appId = (int) ($data['application_id'] ?? ($old['application_id'] ?? 0));
            if ($appId) {
                placement_resync_application($appId);
            }
        },
        'after_delete' => function (int $id, array $row): void {
            placement_resync_application((int) $row['application_id']);
        },
        'describe' => fn (array $row) => '"' . ($row['round_name'] ?? '') . '" (' . ($row['result'] ?? '') . ') of application #' . ($row['application_id'] ?? ''),
    ],
];
