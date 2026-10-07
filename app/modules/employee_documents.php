<?php
/**
 * Employee documents (resume, ID proof, degrees, experience & appointment letters …) of faculty and staff.
 * Used embedded on the faculty/staff profile with scope {employee_type, employee_id}. Files are private
 * (storage/private/employee-docs) and downloaded through /api/files/download. Verification: POST /api/faculty/documents/{id}/verify.
 */
require_once APP_ROOT . '/app/services/hr.php';

$docTypes = ['resume' => 'Resume / CV', 'id_proof' => 'ID Proof (Aadhaar / PAN)', 'degree' => 'Degree Certificate', 'experience' => 'Experience Letter',
    'appointment_letter' => 'Appointment Letter', 'joining_report' => 'Joining Report', 'relieving_letter' => 'Relieving Letter', 'photo' => 'Passport Photo',
    'address_proof' => 'Address Proof', 'research' => 'Research / Publication', 'other' => 'Other'];

return [
    'table' => 'employee_documents',
    'title' => 'Employee Documents',
    'singular' => 'Document',
    'permission' => 'employee_documents',
    'permissions' => [
        'view' => ['faculty', 'view'], 'create' => ['faculty', 'edit'], 'edit' => ['faculty', 'edit'], 'delete' => ['faculty', 'delete'],
        'export' => ['faculty', 'export'], 'import' => ['faculty', 'import'],
    ],
    'icon' => 'folder-open',
    'description' => 'Personnel file documents with verification status.',
    'select' => "t.*, IF(t.employee_type = 'faculty', TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)), TRIM(CONCAT_WS(' ', s.first_name, s.last_name))) AS employee_name,
                 uu.name AS uploaded_by_name, vu.name AS verified_by_name",
    'joins' => "LEFT JOIN faculty f ON t.employee_type = 'faculty' AND f.id = t.employee_id
                LEFT JOIN staff s ON t.employee_type = 'staff' AND s.id = t.employee_id
                LEFT JOIN users uu ON uu.id = t.uploaded_by
                LEFT JOIN users vu ON vu.id = t.verified_by",
    'search' => ['t.title', 't.original_name', 't.doc_type', 't.remarks'],
    'search_placeholder' => 'Search documents…',
    'order' => 't.created_at DESC, t.id DESC',
    'columns' => [
        ['key' => 'title', 'label' => 'Document', 'format' => 'title', 'sub' => 'doc_type_label', 'sortable' => true],
        ['key' => 'original_name', 'label' => 'File', 'format' => 'text'],
        ['key' => 'size_label', 'label' => 'Size', 'format' => 'text', 'align' => 'right', 'sortable' => 't.size_bytes'],
        ['key' => 'created_at', 'label' => 'Uploaded', 'format' => 'date', 'sortable' => true],
        ['key' => 'expiry_date', 'label' => 'Valid Till', 'format' => 'date', 'hidden' => true, 'sortable' => true],
        ['key' => 'uploaded_by_name', 'label' => 'Uploaded By', 'hidden' => true],
        ['key' => 'verified_by_name', 'label' => 'Verified By', 'hidden' => true],
        ['key' => 'employee_name', 'label' => 'Employee', 'hidden' => true],
        ['key' => 'status', 'label' => 'Verification', 'format' => 'badge', 'sortable' => true, 'colors' => ['pending' => 'amber', 'verified' => 'green', 'rejected' => 'red']],
    ],
    'export_columns' => [
        ['key' => 'employee_name', 'label' => 'Employee'], ['key' => 'title', 'label' => 'Document'], ['key' => 'doc_type_label', 'label' => 'Type'],
        ['key' => 'original_name', 'label' => 'File'], ['key' => 'created_at', 'label' => 'Uploaded', 'format' => 'datetime'], ['key' => 'status', 'label' => 'Verification', 'format' => 'badge'],
        ['key' => 'verified_by_name', 'label' => 'Verified By'], ['key' => 'remarks', 'label' => 'Remarks'],
    ],
    'filters' => [
        ['key' => 'doc_type', 'label' => 'Type', 'options' => $docTypes],
        ['key' => 'status', 'label' => 'Verification', 'options' => ['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected']],
    ],
    'scopes' => ['employee_type' => 't.employee_type', 'employee_id' => 't.employee_id'],
    'fields' => [
        ['name' => 'doc_type', 'label' => 'Document type', 'type' => 'select', 'required' => true, 'col' => 6, 'options' => $docTypes],
        ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'col' => 6, 'maxlength' => 190, 'placeholder' => 'e.g. Ph.D. Degree Certificate'],
        ['name' => 'file_path', 'label' => 'File', 'type' => 'file', 'required' => true, 'col' => 12, 'category' => 'any', 'folder' => 'employee-docs', 'private' => true,
            'help' => 'PDF, Word or image. Stored privately — only staff with access to Faculty & Staff can download it.'],
        ['name' => 'expiry_date', 'label' => 'Valid till', 'type' => 'date', 'col' => 6, 'help' => 'Optional (contracts, ID proofs).'],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'text', 'col' => 6, 'maxlength' => 255],
        ['name' => 'status', 'label' => 'Verification', 'type' => 'select', 'form' => false, 'options' => ['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected']],
    ],
    'bulk' => ['delete' => true],
    'import' => false,
    'export' => true,
    'per_page' => 10,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'transform_row' => function (array $row) use ($docTypes): array {
            $row['doc_type_label'] = $docTypes[$row['doc_type']] ?? label_from_key((string) $row['doc_type']);
            $row['size_label'] = $row['size_bytes'] ? human_filesize((int) $row['size_bytes']) : null;
            $row['size_bytes'] = $row['size_bytes'] !== null ? (int) $row['size_bytes'] : null;
            return $row;
        },
        'validate' => function (array $data, ?int $id, ?array $old, array $input) {
            if ($old) {
                return [];
            }
            $scope = (array) ($input['__scope'] ?? []);
            $type = (string) ($scope['employee_type'] ?? '');
            $empId = (int) ($scope['employee_id'] ?? 0);
            if (!in_array($type, HR_TYPES, true) || !$empId || !hr_person($type, $empId)) {
                return ['title' => 'Open the employee profile to upload documents.'];
            }
            if (!empty($data['expiry_date']) && $data['expiry_date'] < date('Y-m-d', strtotime('-10 years'))) {
                return ['expiry_date' => 'Enter a valid expiry date.'];
            }
            return [];
        },
        'before_save' => function (array $data, ?int $id, ?array $old) {
            $file = $_FILES['file_path'] ?? null;
            if (!empty($data['file_path']) && (!$old || $data['file_path'] !== $old['file_path'])) {
                $abs = APP_ROOT . '/' . $data['file_path'];
                $data['original_name'] = $file && !empty($file['name']) ? mb_substr(basename(str_replace('\\', '/', (string) $file['name'])), 0, 190) : basename($data['file_path']);
                $data['size_bytes'] = is_file($abs) ? (int) filesize($abs) : null;
                $data['mime'] = is_file($abs) ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($abs) : null;
                if ($old) {
                    // A replaced file needs to be verified again.
                    $data['status'] = 'pending';
                    $data['verified_by'] = null;
                    $data['verified_at'] = null;
                }
            }
            if (!$old) {
                $data['uploaded_by'] = user_id();
                $data['status'] = 'pending';
            }
            return $data;
        },
        'describe' => function (array $row) {
            $p = hr_person($row['employee_type'], (int) $row['employee_id']);
            return '"' . $row['title'] . '" of ' . ($p['full_name'] ?? '#' . $row['employee_id']);
        },
    ],
];
