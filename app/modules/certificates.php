<?php
/**
 * Issued certificates (Certificates unit). Listing, filters, export and internal remarks go through the generic CRUD
 * engine; issuing, approval, revocation and reissue are workflow endpoints in api/routes/certificates.php because a
 * certificate's number, verification hash and content snapshot are generated on issue.
 */
require_once APP_ROOT . '/app/services/certificates.php';

$statusLabels = cert_status_labels();

return [
    'table' => 'certificates',
    'title' => 'Certificates',
    'singular' => 'Certificate',
    'permission' => 'certificates',
    'icon' => 'badge-check',
    'description' => 'Bonafide, transfer, character, course completion, internship, migration and provisional certificates.',
    'select' => "t.id, t.certificate_no, t.type, t.template_id, t.student_id, t.academic_session_id, t.title, t.purpose, t.issue_date, t.valid_until, t.status,
                 t.revoked_reason, t.remarks, t.issued_by, t.approved_by, t.approved_at, t.revoked_at, t.reissued_from_id, t.verified_count, t.printed_count,
                 t.last_printed_at, t.last_verified_at, t.created_at, t.updated_at,
                 TRIM(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name)) AS student_name, s.student_uid, s.photo AS student_photo, s.status AS student_status,
                 s.current_semester, p.short_name AS program_short, p.name AS program_name, sc.name AS section_name, ct.name AS template_name,
                 iu.name AS issued_by_name, au.name AS approved_by_name, ss.name AS session_name,
                 CASE WHEN t.status = 'issued' AND t.valid_until IS NOT NULL AND t.valid_until < CURDATE() THEN 'expired' ELSE t.status END AS display_status",
    'joins' => 'JOIN students s ON s.id = t.student_id JOIN programs p ON p.id = s.program_id LEFT JOIN sections sc ON sc.id = s.section_id
                LEFT JOIN certificate_templates ct ON ct.id = t.template_id LEFT JOIN users iu ON iu.id = t.issued_by LEFT JOIN users au ON au.id = t.approved_by
                LEFT JOIN academic_sessions ss ON ss.id = t.academic_session_id',
    'search' => ['t.certificate_no', 's.first_name', 's.last_name', 's.student_uid', 's.roll_no', "CONCAT(s.first_name, ' ', s.last_name)", 't.purpose'],
    'search_placeholder' => 'Search number, student, ID or purpose…',
    'order' => 't.issue_date DESC, t.id DESC',
    'default_sort' => ['key' => 'issue_date', 'dir' => 'desc'],
    'columns' => [
        ['key' => 'certificate_no', 'label' => 'Certificate No.', 'format' => 'code', 'sortable' => true, 'link' => 'view'],
        ['key' => 'type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true],
        ['key' => 'student_name', 'label' => 'Student', 'format' => 'person', 'image' => 'student_photo', 'sub' => 'student_uid', 'sortable' => 'student_name'],
        ['key' => 'program_short', 'label' => 'Program', 'format' => 'text', 'sortable' => 'p.short_name', 'hidden' => true],
        ['key' => 'purpose', 'label' => 'Purpose', 'format' => 'truncate', 'hidden' => true],
        ['key' => 'issue_date', 'label' => 'Issued on', 'format' => 'date', 'sortable' => true],
        ['key' => 'valid_until', 'label' => 'Valid until', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'display_status', 'label' => 'Status', 'format' => 'badge', 'sortable' => 'display_status',
            'colors' => ['issued' => 'green', 'pending' => 'amber', 'revoked' => 'red', 'rejected' => 'slate', 'expired' => 'purple']],
        ['key' => 'issued_by_name', 'label' => 'Issued by', 'format' => 'text', 'sortable' => 'iu.name'],
        ['key' => 'verified_count', 'label' => 'Verifications', 'format' => 'number', 'sortable' => true, 'hidden' => true],
        ['key' => 'printed_count', 'label' => 'Prints', 'format' => 'number', 'sortable' => true, 'hidden' => true],
        ['key' => 'template_name', 'label' => 'Template', 'format' => 'text', 'hidden' => true],
        ['key' => 'session_name', 'label' => 'Session', 'format' => 'text', 'hidden' => true],
        ['key' => 'revoked_reason', 'label' => 'Revocation / rejection reason', 'format' => 'truncate', 'hidden' => true],
        // Workflow actions (view / print / approve / revoke …) rendered by the SPA; 'actions' columns are never exported.
        ['key' => 'row_actions', 'label' => 'Actions', 'format' => 'actions', 'align' => 'right'],
    ],
    'filters' => [
        ['key' => 'type', 'label' => 'Type', 'options' => cert_type_options()],
        ['key' => 'status', 'label' => 'Status', 'options' => $statusLabels, 'sql_map' => [
            'issued' => "t.status = 'issued' AND (t.valid_until IS NULL OR t.valid_until >= CURDATE())",
            'pending' => "t.status = 'pending'",
            'revoked' => "t.status = 'revoked'",
            'rejected' => "t.status = 'rejected'",
            'expired' => "t.status = 'issued' AND t.valid_until < CURDATE()",
        ]],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs', 'column' => 's.program_id'],
        ['key' => 'section_id', 'label' => 'Section', 'source' => 'sections', 'column' => 's.section_id', 'depends' => ['program_id' => 'program_id']],
        ['key' => 'issue_date', 'label' => 'Issued between', 'type' => 'daterange', 'column' => 't.issue_date'],
        ['key' => 'academic_session_id', 'label' => 'Session', 'source' => 'sessions', 'column' => 't.academic_session_id'],
        ['key' => 'issued_by', 'label' => 'Issued by', 'source' => 'users', 'column' => 't.issued_by'],
    ],
    // 'state' = effective status (issued certificates past valid_until are "expired") — used by the status tabs
    'scopes' => ['student_id' => 't.student_id', 'status' => 't.status', 'type' => 't.type',
        'state' => "(CASE WHEN t.status = 'issued' AND t.valid_until IS NOT NULL AND t.valid_until < CURDATE() THEN 'expired' ELSE t.status END)"],
    'fields' => [
        ['name' => 'remarks', 'label' => 'Internal remarks', 'type' => 'textarea', 'maxlength' => 255, 'rows' => 3, 'col' => 12,
            'help' => 'Visible to staff only — never printed on the certificate or shown on the verification page.'],
    ],
    // Export with the same wording as the screen (Valid / Expired / Pending approval, "Transfer (TC)" …)
    'export_columns' => [
        ['key' => 'certificate_no', 'label' => 'Certificate No.'],
        ['key' => 'type_label', 'label' => 'Type'],
        ['key' => 'student_name', 'label' => 'Student', 'format' => 'person', 'sub' => 'student_uid'],
        ['key' => 'program_short', 'label' => 'Program'],
        ['key' => 'section_name', 'label' => 'Section'],
        ['key' => 'purpose', 'label' => 'Purpose'],
        ['key' => 'issue_date', 'label' => 'Issued on', 'format' => 'date'],
        ['key' => 'valid_until', 'label' => 'Valid until', 'format' => 'date'],
        ['key' => 'status_label', 'label' => 'Status'],
        ['key' => 'issued_by_name', 'label' => 'Issued by'],
        ['key' => 'approved_by_name', 'label' => 'Approved by'],
        ['key' => 'session_name', 'label' => 'Session'],
        ['key' => 'template_name', 'label' => 'Template'],
        ['key' => 'verified_count', 'label' => 'Verifications', 'format' => 'number'],
        ['key' => 'printed_count', 'label' => 'Prints', 'format' => 'number'],
        ['key' => 'revoked_reason', 'label' => 'Revocation / rejection reason'],
    ],
    'hidden_columns' => ['verification_hash', 'content_html', 'data'],
    // Issued certificates are revoked, never deleted; a pending / rejected request is deleted one at a time from its row.
    'bulk' => ['delete' => false],
    'export' => true,
    'import' => false,
    'form' => ['size' => 'md'],
    'hooks' => [
        'validate' => function (array $data, ?int $id): array {
            if ($id === null) {
                throw new CrudException('Certificates are created with the Issue certificate workflow (POST /api/certificates/issue), which assigns the number, QR verification hash and content.');
            }
            return [];
        },
        'before_delete' => function (int $id, array $row): ?string {
            if (in_array($row['status'], ['issued', 'revoked'], true)) {
                return $row['certificate_no'] . ' has been issued and cannot be deleted — revoke it instead so the verification page reports it correctly.';
            }
            if (db_value('SELECT COUNT(*) FROM certificates WHERE reissued_from_id = ?', [$id])) {
                return $row['certificate_no'] . ' is referenced by a reissued certificate and cannot be deleted.';
            }
            return null;
        },
        'transform_row' => function (array $row): array {
            $row['type_label'] = cert_types()[$row['type']]['short'] ?? label_from_key((string) $row['type']);
            $row['status_label'] = cert_status_labels()[$row['display_status'] ?? $row['status']] ?? label_from_key((string) $row['status']);
            foreach (['verified_count', 'printed_count', 'student_id', 'template_id'] as $k) {
                if (isset($row[$k])) {
                    $row[$k] = (int) $row[$k];
                }
            }
            return $row;
        },
        'summary' => function (string $from, array $args): array {
            $counts = db_pairs("SELECT CASE WHEN t.status = 'issued' AND t.valid_until IS NOT NULL AND t.valid_until < CURDATE() THEN 'expired' ELSE t.status END AS st, COUNT(*)" . $from . ' GROUP BY st', $args);
            return ['statuses' => array_map('intval', $counts)];
        },
        'describe' => fn (array $row): string => ($row['certificate_no'] ?? '#' . ($row['id'] ?? '')) . ' (' . cert_type_label((string) ($row['type'] ?? '')) . ')',
    ],
];
