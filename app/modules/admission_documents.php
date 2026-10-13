<?php
/** Documents uploaded with admission applications (private storage). Verification also via /api/admissions/documents/{id}/verify. */
require_once APP_ROOT . '/app/services/admissions.php';

return [
    'table' => 'admission_documents',
    'title' => 'Admission Documents',
    'singular' => 'Document',
    'permission' => 'admissions',
    'icon' => 'file-check',
    'description' => 'Documents submitted by applicants and their verification status.',
    'select' => "t.*, a.application_no, TRIM(CONCAT_WS(' ', a.first_name, a.last_name)) AS applicant, a.photo AS applicant_photo, u.name AS verified_by_name",
    'joins' => 'JOIN admissions a ON a.id = t.admission_id LEFT JOIN users u ON u.id = t.verified_by',
    'search' => ['a.application_no', 'a.first_name', 'a.last_name', 't.original_name'],
    'order' => 't.created_at DESC, t.id DESC',
    'columns' => [
        ['key' => 'applicant', 'label' => 'Applicant', 'format' => 'person', 'image' => 'applicant_photo', 'sub' => 'application_no', 'link' => '/admissions/{admission_id}', 'sortable' => 'a.first_name'],
        ['key' => 'doc_type', 'label' => 'Document', 'format' => 'badge', 'sortable' => true, 'colors' => array_fill_keys(array_keys(admission_doc_types()), 'slate')],
        ['key' => 'original_name', 'label' => 'File', 'format' => 'truncate', 'truncate' => 40],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
        ['key' => 'remarks', 'label' => 'Remarks', 'format' => 'truncate', 'truncate' => 50],
        ['key' => 'verified_by_name', 'label' => 'Verified By', 'hidden' => true],
        ['key' => 'created_at', 'label' => 'Uploaded', 'format' => 'datetime', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => ['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected']],
        ['key' => 'doc_type', 'label' => 'Document type', 'options' => admission_doc_types()],
    ],
    'scopes' => ['admission_id' => 't.admission_id'],
    'fields' => [
        ['name' => 'admission_id', 'label' => 'Application', 'type' => 'select', 'required' => true, 'source' => 'admissions', 'readonly_on_edit' => true, 'col' => 12],
        ['name' => 'doc_type', 'label' => 'Document type', 'type' => 'select', 'required' => true, 'options' => admission_doc_types()],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'pending', 'options' => ['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected']],
        ['name' => 'file_path', 'label' => 'File', 'type' => 'file', 'required' => true, 'private' => true, 'category' => 'any', 'folder' => 'admission-docs', 'col' => 12],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'text', 'maxlength' => 255, 'col' => 12],
    ],
    'bulk' => ['delete' => true, 'status' => ['pending', 'verified', 'rejected']],
    'export' => true,
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            $status = $data['status'] ?? ($old['status'] ?? 'pending');
            if ($status === 'rejected' && trim((string) ($data['remarks'] ?? ($old['remarks'] ?? ''))) === '') {
                return ['remarks' => 'Enter the reason the document was rejected.'];
            }
            return [];
        },
        'before_save' => function (array $data, ?int $id, ?array $old, array $input): array {
            if (!empty($data['file_path']) && (!$old || $old['file_path'] !== $data['file_path']) && is_file(APP_ROOT . '/' . $data['file_path'])) {
                $abs = APP_ROOT . '/' . $data['file_path'];
                $data['size_bytes'] = filesize($abs) ?: null;
                $data['mime'] = (new finfo(FILEINFO_MIME_TYPE))->file($abs) ?: null;
                $data['original_name'] = $data['original_name'] ?? (admission_doc_types()[$data['doc_type'] ?? ($old['doc_type'] ?? 'other')] ?? 'Document') . '.' . pathinfo($abs, PATHINFO_EXTENSION);
                if ($old) {
                    $data['status'] = 'pending';
                }
            }
            $status = $data['status'] ?? null;
            if ($status !== null && (!$old || $old['status'] !== $status)) {
                $data['verified_by'] = $status === 'pending' ? null : user_id();
                $data['verified_at'] = $status === 'pending' ? null : date('Y-m-d H:i:s');
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row) {
            $stage = db_value('SELECT stage FROM admissions WHERE id = ?', [(int) $row['admission_id']]);
            if (in_array($stage, ['confirmed'], true)) {
                return 'Documents of confirmed admissions are kept on record.';
            }
            return null;
        },
        'after_bulk' => function (array $ids, string $field, $value): void {
            db_exec('UPDATE admission_documents SET verified_by = ?, verified_at = ? WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')',
                $value === 'pending' ? [null, null] : [user_id(), date('Y-m-d H:i:s')]);
        },
        'describe' => fn (array $row) => (admission_doc_types()[$row['doc_type'] ?? ''] ?? 'Document') . ' of application #' . ($row['admission_id'] ?? ''),
    ],
];
