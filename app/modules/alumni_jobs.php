<?php
/** Job opportunities posted by alumni (brief §23): approval workflow pending → open → closed, with expiry. */
require_once APP_ROOT . '/app/services/alumni.php';

return [
    'table' => 'alumni_jobs',
    'title' => 'Job Opportunities',
    'singular' => 'Job opportunity',
    'permission' => 'alumni',
    'icon' => 'briefcase',
    'description' => 'Openings shared by alumni for students and fellow alumni.',
    'select' => "t.*, a.name AS poster_name, a.photo AS poster_photo, a.batch_year AS poster_batch,
                 CONCAT('Batch ', a.batch_year, IFNULL(CONCAT(' · ', NULLIF(a.designation, '')), '')) AS poster_sub,
                 " . ALUMNI_JOB_STATUS_SQL . " AS job_status, u.name AS approved_by_name",
    'joins' => 'LEFT JOIN alumni a ON a.id = t.posted_by_alumni_id LEFT JOIN users u ON u.id = t.approved_by',
    'search' => ['t.title', 't.company', 't.location', 't.skills', 'a.name'],
    'search_placeholder' => 'Search role, company, location, skills…',
    'order' => "FIELD(t.status, 'pending', 'open', 'closed', 'rejected'), t.created_at DESC",
    'columns' => [
        ['key' => 'title', 'label' => 'Role', 'format' => 'title', 'sub' => 'company', 'link' => 'view', 'sortable' => true],
        ['key' => 'poster_name', 'label' => 'Posted by', 'format' => 'person', 'image' => 'poster_photo', 'sub' => 'poster_sub', 'link' => '/alumni/{posted_by_alumni_id}', 'sortable' => 'a.name'],
        ['key' => 'location', 'label' => 'Location', 'sortable' => true],
        ['key' => 'job_type', 'label' => 'Type', 'format' => 'badge', 'hidden' => true, 'sortable' => true,
            'colors' => ['full_time' => 'navy', 'part_time' => 'cyan', 'internship' => 'purple', 'contract' => 'amber', 'remote' => 'green']],
        ['key' => 'experience', 'label' => 'Experience', 'hidden' => true],
        ['key' => 'salary_range', 'label' => 'Salary', 'hidden' => true],
        ['key' => 'created_at', 'label' => 'Posted', 'format' => 'date', 'hidden' => true, 'sortable' => true],
        ['key' => 'last_date', 'label' => 'Apply by', 'format' => 'date', 'sortable' => true],
        ['key' => 'job_status', 'label' => 'Status', 'format' => 'badge', 'sortable' => 'job_status',
            'colors' => ['pending' => 'amber', 'open' => 'green', 'expired' => 'red', 'closed' => 'purple', 'rejected' => 'red']],
        ['key' => 'approved_by_name', 'label' => 'Reviewed by', 'hidden' => true],
    ],
    'filters' => [
        ['key' => 'job_status', 'label' => 'Status', 'sql_map' => [
            'pending' => "t.status = 'pending'",
            'open' => "t.status = 'open' AND (t.last_date IS NULL OR t.last_date >= CURDATE())",
            'expired' => "t.status = 'open' AND t.last_date < CURDATE()",
            'closed' => "t.status = 'closed'",
            'rejected' => "t.status = 'rejected'",
        ], 'options' => ['pending' => 'Pending approval', 'open' => 'Open', 'expired' => 'Expired', 'closed' => 'Closed', 'rejected' => 'Rejected']],
        ['key' => 'job_type', 'label' => 'Type', 'options' => alumni_job_types()],
        ['key' => 'posted_by_alumni_id', 'label' => 'Posted by', 'source' => alumni_lookup_source()],
        ['key' => 'created_at', 'label' => 'Posted', 'type' => 'daterange'],
    ],
    'fields' => [
        ['name' => 'title', 'label' => 'Job title / role', 'type' => 'text', 'required' => true, 'col' => 6, 'maxlength' => 190, 'placeholder' => 'e.g. Associate Software Engineer'],
        ['name' => 'company', 'label' => 'Company', 'type' => 'text', 'required' => true, 'col' => 6, 'maxlength' => 190],
        ['name' => 'posted_by_alumni_id', 'label' => 'Posted by (alumni)', 'type' => 'combobox', 'source' => alumni_lookup_source(), 'col' => 6, 'placeholder' => 'Search alumni…',
            'help' => 'Leave empty for openings shared by the alumni cell.'],
        ['name' => 'location', 'label' => 'Location', 'type' => 'text', 'col' => 6, 'maxlength' => 150, 'placeholder' => 'e.g. Gurugram / Remote'],
        ['name' => 'job_type', 'label' => 'Job type', 'type' => 'select', 'required' => true, 'default' => 'full_time', 'col' => 4, 'options' => alumni_job_types()],
        ['name' => 'experience', 'label' => 'Experience', 'type' => 'text', 'col' => 4, 'maxlength' => 60, 'placeholder' => 'e.g. 0–2 years'],
        ['name' => 'salary_range', 'label' => 'Salary / stipend', 'type' => 'text', 'col' => 4, 'maxlength' => 60, 'placeholder' => 'e.g. ₹6–9 LPA'],
        ['name' => 'skills', 'label' => 'Key skills', 'type' => 'text', 'col' => 12, 'maxlength' => 255, 'placeholder' => 'Comma separated, e.g. Java, Spring Boot, SQL'],
        ['name' => 'last_date', 'label' => 'Apply by', 'type' => 'date', 'col' => 4],
        ['name' => 'apply_link', 'label' => 'Application link', 'type' => 'url', 'col' => 4],
        ['name' => 'contact_email', 'label' => 'Contact email', 'type' => 'email', 'col' => 4],
        ['name' => 'description', 'label' => 'Job description', 'type' => 'richtext'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'open', 'col' => 4, 'options' => alumni_job_statuses(),
            'help' => 'New posts from alumni start as "Pending approval".'],
        ['name' => 'rejection_reason', 'label' => 'Rejection reason', 'type' => 'text', 'col' => 8, 'maxlength' => 255, 'edit_only' => true],
    ],
    'bulk' => ['delete' => true],
    'export' => true,
    'import' => false,
    'form' => ['size' => 'xl'],
    'scopes' => ['posted_by_alumni_id' => 't.posted_by_alumni_id'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $status = $data['status'] ?? ($old['status'] ?? 'open');
            $last = array_key_exists('last_date', $data) ? $data['last_date'] : ($old['last_date'] ?? null);
            $changedDate = array_key_exists('last_date', $data) && $data['last_date'] !== ($old['last_date'] ?? null);
            if ($last && $last < date('Y-m-d') && in_array($status, ['open', 'pending'], true) && ($id === null || $changedDate || ($old['status'] ?? '') !== $status)) {
                $errors['last_date'] = 'The last date to apply cannot be in the past for an open post.';
            }
            if ($last && $last > date('Y-m-d', strtotime('+1 year'))) {
                $errors['last_date'] = 'The last date can be at most one year ahead.';
            }
            $link = array_key_exists('apply_link', $data) ? $data['apply_link'] : ($old['apply_link'] ?? null);
            $mail = array_key_exists('contact_email', $data) ? $data['contact_email'] : ($old['contact_email'] ?? null);
            if (!$link && !$mail) {
                $errors['apply_link'] = 'Provide an application link or a contact email.';
            }
            // Publishing (open) or rejecting a post that is awaiting review is an approval decision.
            $prev = $old['status'] ?? null;
            if (in_array($status, ['open', 'rejected'], true) && $status !== $prev && ($prev === null || in_array($prev, ['pending', 'rejected'], true))
                && !can('alumni', 'approve')) {
                $errors['status'] = 'You do not have permission to approve or reject job posts. Save the post as "Pending approval".';
            }
            $reason = array_key_exists('rejection_reason', $data) ? $data['rejection_reason'] : ($old['rejection_reason'] ?? null);
            if ($status === 'rejected' && !$reason) {
                $errors['rejection_reason'] = 'Give a reason for rejecting this post.';
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            $status = $data['status'] ?? ($old['status'] ?? null);
            $prev = $old['status'] ?? null;
            if (in_array($status, ['open', 'rejected'], true) && $prev !== $status && ($prev === null || $prev === 'pending' || $prev === 'rejected')) {
                $data['approved_by'] = user_id();
                $data['approved_at'] = date('Y-m-d H:i:s');
            }
            if ($status === 'closed' && $prev !== 'closed') {
                $data['closed_at'] = date('Y-m-d H:i:s');
            } elseif ($status !== 'closed' && $prev === 'closed') {
                $data['closed_at'] = null;
            }
            if ($status !== 'rejected' && isset($data['status'])) {
                $data['rejection_reason'] = null;
            }
            if (!empty($data['skills'])) {
                $data['skills'] = implode(', ', array_filter(array_map('trim', explode(',', (string) $data['skills']))));
            }
            return $data;
        },
        'bulk_action' => function (string $action, array $ids): array {
            if (!in_array($action, ['approve', 'close'], true)) {
                throw new CrudException('Unknown bulk action.');
            }
            if ($action === 'approve' && !can('alumni', 'approve')) {
                throw new CrudException('You do not have permission to approve job posts.');
            }
            return alumni_jobs_bulk($action, $ids);
        },
        'describe' => fn (array $row) => '"' . ($row['title'] ?? '') . '" at ' . ($row['company'] ?? ''),
    ],
];
