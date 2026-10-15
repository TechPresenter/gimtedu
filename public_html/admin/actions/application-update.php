<?php
/**
 * Review actions for applications, help requests, scholarships, enquiries,
 * memberships, website users, newsletter subscribers and certificates.
 */
require_once dirname(__DIR__) . '/includes/auth.php';

$type = (string) ($_POST['type'] ?? '');
$action = (string) ($_POST['action'] ?? '');
$id = (int) ($_POST['id'] ?? 0);

$permissions = [
    'volunteers' => 'applications', 'teacher_applications' => 'applications', 'staff_applications' => 'applications',
    'beneficiaries' => 'beneficiaries', 'scholarships' => 'beneficiaries',
    'members' => 'members', 'users' => 'members', 'certificates' => 'members',
    'contact_enquiries' => 'enquiries', 'newsletter_subscribers' => 'enquiries',
];
if (!isset($permissions[$type])) {
    flash('error', 'Unknown request.');
    admin_redirect('dashboard.php');
}
// Certificates may be issued by members managers or application reviewers
if ($type === 'certificates') {
    if (!admin_can('members') && !admin_can('applications')) {
        require_permission('members');
    }
} else {
    require_permission($permissions[$type]);
}

$pages = [
    'volunteers' => 'volunteer-view.php?id=', 'teacher_applications' => 'teacher-view.php?id=', 'staff_applications' => 'staff-applications.php?id=',
    'beneficiaries' => 'beneficiaries.php?id=', 'scholarships' => 'scholarships.php?id=', 'members' => 'members.php?id=',
    'users' => 'members.php?id=', 'contact_enquiries' => 'contact-enquiries.php?id=', 'newsletter_subscribers' => 'newsletter.php', 'certificates' => 'members.php',
];
$back = admin_back($pages[$type] . ($pages[$type][strlen($pages[$type]) - 1] === '=' ? $id : ''));
csrf_verify('admin/' . $back);

// ---------------------------------------------------------------------
// Issue a certificate
// ---------------------------------------------------------------------
if ($action === 'certificate') {
    [$data, $errors] = validate($_POST, [
        'user_id'          => 'required|integer',
        'recipient_name'   => 'required|min:2|max:120',
        'recipient_email'  => 'email',
        'certificate_type' => 'required|in:volunteer,internship,teaching,membership,appreciation,participation',
        'title'            => 'required|min:3|max:200',
        'description'      => 'text|max:500',
        'issue_date'       => 'required|date',
    ]);
    $user = !$errors ? db_row('SELECT id, name, email FROM users WHERE id = ?', [(int) $data['user_id']]) : null;
    if (!$errors && !$user) {
        $errors['user_id'] = 'Website account not found.';
    }
    $upload = [];
    if (!$errors) {
        $upload = handle_upload('file', 'pdf', 'certificates');
        if (isset($upload['error'])) {
            $errors['file'] = $upload['error'];
        }
    }
    if ($errors) {
        flash('error', reset($errors));
        admin_redirect($back);
    }
    $certId = db_insert('certificates', [
        'certificate_no'   => 'TMP-' . bin2hex(random_bytes(6)),
        'user_id'          => $user['id'],
        'recipient_name'   => $data['recipient_name'],
        'recipient_email'  => $user['email'],
        'certificate_type' => $data['certificate_type'],
        'title'            => $data['title'],
        'description'      => $data['description'] ?: null,
        'issue_date'       => $data['issue_date'],
        'file_path'        => $upload['path'] ?? null,
        'issued_by'        => admin_id(),
    ]);
    $no = 'PWF/CERT/' . date('Y', strtotime($data['issue_date'])) . '/' . str_pad((string) $certId, 5, '0', STR_PAD_LEFT);
    db_update('certificates', ['certificate_no' => $no], 'id = :id', ['id' => $certId]);
    admin_log('certificate_issued', $no . ' to user #' . $user['id']);
    send_mail($user['email'], 'Your certificate from ' . site_name(),
        '<p>Dear ' . e($user['name']) . ',</p><p>Congratulations! A certificate (<strong>' . e($data['title']) . '</strong>, No. ' . e($no) . ') has been issued to you.</p>'
        . '<p><a class="btn" href="' . e(abs_url('my-certificates.php')) . '">View my certificates</a></p>');
    flash('success', 'Certificate ' . $no . ' issued.');
    admin_redirect($back);
}

// ---------------------------------------------------------------------
// Revoke a certificate
// ---------------------------------------------------------------------
if ($action === 'revoke_certificate' && $type === 'certificates') {
    db_query("UPDATE certificates SET status = 'revoked' WHERE id = ?", [$id]);
    admin_log('certificate_revoked', 'Certificate #' . $id);
    flash('success', 'Certificate revoked.');
    admin_redirect($back);
}

// ---------------------------------------------------------------------
// Website user block / unblock
// ---------------------------------------------------------------------
if ($type === 'users' && $action === 'user_status') {
    $status = ($_POST['status'] ?? '') === 'blocked' ? 'blocked' : 'active';
    db_update('users', ['status' => $status], 'id = :id', ['id' => $id]);
    admin_log('user_' . $status, 'User #' . $id);
    flash('success', $status === 'blocked' ? 'User blocked. They can no longer log in.' : 'User re-activated.');
    admin_redirect($back);
}

// ---------------------------------------------------------------------
// Newsletter subscriber status
// ---------------------------------------------------------------------
if ($type === 'newsletter_subscribers') {
    if ($action === 'delete') {
        db_query('DELETE FROM newsletter_subscribers WHERE id = ?', [$id]);
        admin_log('subscriber_deleted', 'Subscriber #' . $id);
        flash('success', 'Subscriber removed.');
    } else {
        $status = ($_POST['status'] ?? '') === 'unsubscribed' ? 'unsubscribed' : 'subscribed';
        db_update('newsletter_subscribers', ['status' => $status], 'id = :id', ['id' => $id]);
        admin_log('subscriber_' . $status, 'Subscriber #' . $id);
        flash('success', 'Subscriber updated.');
    }
    admin_redirect($back);
}

// ---------------------------------------------------------------------
// Status update with notes (+ optional email)
// ---------------------------------------------------------------------
if ($action !== 'status' || !in_array($type, ['volunteers', 'teacher_applications', 'staff_applications', 'beneficiaries', 'scholarships', 'contact_enquiries', 'members'], true)) {
    admin_redirect($back);
}
$row = db_row("SELECT * FROM `$type` WHERE id = ?", [$id]);
if (!$row) {
    flash('error', 'Record not found.');
    admin_redirect('dashboard.php');
}
$status = (string) ($_POST['status'] ?? '');
if (!in_array($status, application_statuses($type), true)) {
    flash('error', 'Invalid status.');
    admin_redirect($back);
}
$update = ['status' => $status, 'admin_notes' => mb_substr(clean_string($_POST['admin_notes'] ?? '', true), 0, 5000) ?: null];

if ($type === 'contact_enquiries') {
    $update['handled_by'] = admin_id();
} elseif ($type === 'members') {
    if ($status === 'approved') {
        $update['approved_by'] = admin_id();
        $update['approved_at'] = $row['approved_at'] ?: date('Y-m-d H:i:s');
        if (!$row['membership_no']) {
            $update['membership_no'] = 'PWF/MEM/' . date('Y') . '/' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
        }
    }
    $validUntil = (string) ($_POST['valid_until'] ?? '');
    $update['valid_until'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil) ? $validUntil : null;
} else {
    $update['reviewed_by'] = admin_id();
    $update['reviewed_at'] = date('Y-m-d H:i:s');
}
if ($type === 'staff_applications') {
    $interview = str_replace('T', ' ', (string) ($_POST['interview_at'] ?? ''));
    $update['interview_at'] = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $interview) ? $interview . ':00' : null;
}
db_update($type, $update, 'id = :id', ['id' => $id]);

$refCol = ['beneficiaries' => 'reference_no', 'contact_enquiries' => 'reference_no', 'members' => 'membership_no'][$type] ?? 'application_no';
$ref = $row[$refCol] ?? ($update['membership_no'] ?? '#' . $id);
admin_log('status_updated', humanize($type) . ' ' . $ref . ': ' . $row['status'] . ' -> ' . $status);

// Optional email to the applicant
if (!empty($_POST['notify'])) {
    if ($type === 'members') {
        $person = db_row('SELECT name, email FROM users WHERE id = ?', [$row['user_id']]);
    } else {
        $person = ['name' => $row['name'] ?? $row['student_name'] ?? '', 'email' => $row['email'] ?? ''];
    }
    $message = clean_string($_POST['message'] ?? '', true);
    if (!empty($person['email'])) {
        $body = '<p>Dear ' . e($person['name']) . ',</p>'
            . '<p>The status of your ' . ($type === 'members' ? 'membership application' : 'application / request') . ' <strong>' . e($ref) . '</strong> has been updated to: <strong>' . e(humanize($status)) . '</strong>.</p>'
            . ($type === 'members' && !empty($update['membership_no']) && $status === 'approved' ? '<p>Your membership number is <strong>' . e($update['membership_no']) . '</strong>.</p>' : '')
            . (!empty($update['interview_at']) ? '<p>Interview scheduled: <strong>' . e(date('d M Y, h:i A', strtotime($update['interview_at']))) . '</strong></p>' : '')
            . ($message !== '' ? '<p>' . e_nl($message) . '</p>' : '')
            . '<p><a class="btn" href="' . e(abs_url($type === 'members' ? 'member-dashboard.php' : 'application-status.php')) . '">View status</a></p>'
            . '<p>Regards,<br>' . e(site_name()) . '</p>';
        send_mail($person['email'], 'Update on ' . $ref . ' - ' . site_name(), $body);
    }
}
flash('success', 'Status updated to ' . humanize($status) . '.');
admin_redirect($back);
