<?php
/**
 * Student documents (private files under storage/private/student-docs, streamed by api/routes/files.php).
 * Upload/edit/delete through the CRUD engine (scope student_id); verify/reject via POST students/documents/{id}/verify|reject.
 */
require_once APP_ROOT . '/app/services/students.php';

return [
    'table' => 'student_documents',
    'title' => 'Student Documents',
    'singular' => 'Document',
    'permission' => 'students',
    'icon' => 'file-text',
    'description' => 'Marksheets, certificates and ID proofs uploaded for students.',
    'select' => "t.*, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, s.photo AS student_photo, vu.name AS verified_by_name, uu.name AS uploaded_by_name",
    'joins' => 'JOIN students s ON s.id = t.student_id LEFT JOIN users vu ON vu.id = t.verified_by LEFT JOIN users uu ON uu.id = t.uploaded_by',
    'search' => ['t.title', 't.original_name', 's.first_name', 's.last_name', 's.student_uid'],
    'order' => 't.created_at DESC, t.id DESC',
    'columns' => [
        ['key' => 'title', 'label' => 'Document', 'format' => 'title', 'sub' => 'original_name', 'sortable' => true],
        ['key' => 'student_name', 'label' => 'Student', 'format' => 'person', 'image' => 'student_photo', 'sub' => 'student_uid', 'link' => '/students/{student_id}?tab=documents', 'sortable' => 's.first_name'],
        ['key' => 'doc_type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
        ['key' => 'created_at', 'label' => 'Uploaded', 'format' => 'date', 'sortable' => true],
        ['key' => 'verified_by_name', 'label' => 'Verified by', 'format' => 'text', 'hidden' => true],
        ['key' => 'remarks', 'label' => 'Remarks', 'format' => 'truncate', 'hidden' => true],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => ['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected']],
        ['key' => 'doc_type', 'label' => 'Type', 'options' => students_document_types()],
    ],
    'scopes' => ['student_id' => 't.student_id'],
    'fields' => [
        ['name' => 'student_id', 'label' => 'Student', 'type' => 'combobox', 'source' => 'all_students', 'required' => true, 'readonly_on_edit' => true, 'form' => false],
        ['name' => 'doc_type', 'label' => 'Document type', 'type' => 'select', 'required' => true, 'options' => students_document_types(), 'col' => 6],
        ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'maxlength' => 190, 'col' => 6, 'placeholder' => 'e.g. Class XII Marksheet (CBSE)', 'help' => 'Defaults to the document type.'],
        ['name' => 'file_path', 'label' => 'File', 'type' => 'file', 'required' => true, 'private' => true, 'category' => 'any', 'folder' => 'student-docs', 'col' => 12,
            'help' => 'PDF, image or Office document.'],
        ['name' => 'status', 'label' => 'Verification', 'type' => 'select', 'default' => 'pending', 'options' => ['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected'], 'col' => 6, 'edit_only' => true],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'text', 'maxlength' => 255, 'col' => 6, 'help' => 'Required when rejecting.'],
    ],
    'bulk' => ['delete' => true],
    'import' => false,
    'export' => true,
    'form' => ['size' => 'md'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            $studentId = (int) ($data['student_id'] ?? $old['student_id'] ?? 0);
            if (!$studentId || !db_value('SELECT COUNT(*) FROM students WHERE id = ?', [$studentId])) {
                return ['doc_type' => 'Open the student profile to upload a document.'];
            }
            if ($old && !empty($input['file_path__remove']) && empty($_FILES['file_path']['name'])) {
                return ['file_path' => 'Choose a replacement file — a document record needs a file.'];
            }
            $status = $data['status'] ?? $old['status'] ?? 'pending';
            $remarks = array_key_exists('remarks', $data) ? $data['remarks'] : ($old['remarks'] ?? null);
            if ($status === 'rejected' && empty($remarks)) {
                return ['remarks' => 'Give the reason for rejecting this document.'];
            }
            return [];
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            if (empty($data['title']) && (!$old || array_key_exists('title', $data))) {
                $type = $data['doc_type'] ?? $old['doc_type'] ?? 'other';
                $data['title'] = students_document_types()[$type] ?? 'Document';
            }
            if (!empty($data['file_path']) && (!$old || $old['file_path'] !== $data['file_path'])) {
                $abs = APP_ROOT . '/' . $data['file_path'];
                $data['original_name'] = mb_substr(basename(str_replace('\\', '/', (string) ($_FILES['file_path']['name'] ?? basename($data['file_path'])))), 0, 190);
                $data['size_bytes'] = is_file($abs) ? filesize($abs) : null;
                $data['mime'] = is_file($abs) ? ((new finfo(FILEINFO_MIME_TYPE))->file($abs) ?: null) : null;
                $data['uploaded_by'] = user_id();
                if ($old) {
                    // a replaced file has to be verified again
                    $data['status'] = $data['status'] ?? 'pending';
                    if (($data['status'] ?? null) === ($old['status'] ?? null) && $old['status'] === 'verified') {
                        $data['status'] = 'pending';
                    }
                }
            }
            if ($id === null) {
                $data['status'] = 'pending';
                $data['is_verified'] = 0;
            }
            if (isset($data['status']) && (!$old || $old['status'] !== $data['status'])) {
                $data['is_verified'] = $data['status'] === 'verified' ? 1 : 0;
                $data['verified_by'] = $data['status'] === 'pending' ? null : user_id();
                $data['verified_at'] = $data['status'] === 'pending' ? null : date('Y-m-d H:i:s');
            }
            return $data;
        },
        'describe' => fn (array $row): string => '"' . ($row['title'] ?? 'document') . '" (student #' . ($row['student_id'] ?? '') . ')',
    ],
];
