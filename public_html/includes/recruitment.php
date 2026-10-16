<?php
/**
 * Recruitment & support applications module (M4): volunteer, teacher, staff, internship, membership,
 * scholarship and help-request applications, status workflow, history timeline, applicant notifications,
 * private document downloads, tasks and certificates.
 *
 * Public API used by other modules:
 *   recruitment_types(), recruitment_status_badge(), record_application_history(), notify_applicant(),
 *   send_application_document(), send_certificate_file(), render_certificate_html(),
 *   member_applications(), recruitment_user_certificates(), recruitment_user_tasks(),
 *   section types apply_cards and status_lookup.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

// ---------------------------------------------------------------------
// Registry
// ---------------------------------------------------------------------

/**
 * Recruitment application types (the four volunteer / staff flows).
 * table, label, title, prefix (reference), slug (public page), conversion (track_conversion type),
 * role (website role linked when the application becomes Active), icon, folder (private resume folder),
 * certificate (default certificate type).
 */
function recruitment_types(): array
{
    return [
        'volunteer' => [
            'table' => 'volunteer_applications', 'label' => 'Volunteer', 'plural' => 'Volunteers', 'title' => 'Volunteer application',
            'prefix' => 'VOL', 'slug' => 'become-a-volunteer', 'conversion' => 'volunteer_application', 'role' => 'registered_volunteer',
            'icon' => 'heart', 'folder' => null, 'certificate' => 'volunteer', 'form' => 'volunteer',
        ],
        'teacher' => [
            'table' => 'teacher_applications', 'label' => 'Volunteer teacher', 'plural' => 'Volunteer teachers', 'title' => 'Volunteer teacher application',
            'prefix' => 'TCH', 'slug' => 'volunteer-teacher', 'conversion' => 'teacher_application', 'role' => 'volunteer_teacher',
            'icon' => 'book', 'folder' => 'documents/teachers', 'certificate' => 'teaching', 'form' => 'teacher',
        ],
        'staff' => [
            'table' => 'staff_applications', 'label' => 'Staff / job', 'plural' => 'Staff applications', 'title' => 'Staff application',
            'prefix' => 'STF', 'slug' => 'volunteer-staff', 'conversion' => 'staff_application', 'role' => 'registered_volunteer',
            'icon' => 'briefcase', 'folder' => 'documents/staff', 'certificate' => 'appreciation', 'form' => 'staff',
        ],
        'internship' => [
            'table' => 'internship_applications', 'label' => 'Internship', 'plural' => 'Internships', 'title' => 'Internship application',
            'prefix' => 'INT', 'slug' => 'internships', 'conversion' => 'internship_application', 'role' => 'registered_volunteer',
            'icon' => 'award', 'folder' => 'documents/internships', 'certificate' => 'internship', 'form' => 'internship',
        ],
    ];
}

function recruitment_type(string $type): ?array
{
    return recruitment_types()[$type] ?? null;
}

/** Recruitment status flow (volunteer / teacher / staff / internship). */
function recruitment_status_keys(): array
{
    return ['submitted', 'under_review', 'info_requested', 'shortlisted', 'interview_scheduled', 'selected', 'onboarding', 'active', 'rejected', 'withdrawn'];
}

/**
 * Every kind of application that has a status and a history timeline.
 * kind => table, ref_col, name_col, label, prefix, statuses, doc_col, perm (admin permission), admin (admin page)
 */
function recruitment_kinds(): array
{
    static $kinds = null;
    if ($kinds !== null) {
        return $kinds;
    }
    $kinds = [];
    foreach (recruitment_types() as $key => $t) {
        $kinds[$key] = [
            'table' => $t['table'], 'ref_col' => 'application_no', 'name_col' => 'name', 'label' => $t['title'], 'prefix' => $t['prefix'],
            'statuses' => recruitment_status_keys(), 'doc_col' => $t['folder'] ? 'resume_path' : null, 'perm' => 'recruitment.view',
            'admin' => 'application-view.php?type=' . $key . '&id=',
        ];
    }
    $kinds['scholarship'] = [
        'table' => 'scholarships', 'ref_col' => 'application_no', 'name_col' => 'student_name', 'label' => 'Scholarship application', 'prefix' => 'SCH',
        'statuses' => ['pending', 'under_review', 'shortlisted', 'approved', 'rejected', 'disbursed'], 'doc_col' => 'document_path',
        'perm' => 'beneficiaries.manage', 'admin' => 'scholarships.php?id=',
    ];
    $kinds['beneficiary'] = [
        'table' => 'beneficiaries', 'ref_col' => 'reference_no', 'name_col' => 'name', 'label' => 'Request for help', 'prefix' => 'HLP',
        'statuses' => ['pending', 'under_review', 'approved', 'assisted', 'rejected'], 'doc_col' => 'document_path',
        'perm' => 'beneficiaries.manage', 'admin' => 'beneficiaries.php?id=',
    ];
    $kinds['membership'] = [
        'table' => 'members', 'ref_col' => 'membership_no', 'name_col' => null, 'label' => 'Membership application', 'prefix' => 'MEM',
        'statuses' => ['pending', 'approved', 'rejected', 'suspended'], 'doc_col' => null, 'perm' => 'members.view', 'admin' => 'memberships.php?id=',
    ];
    return $kinds;
}

/** Membership types (members.membership_type). */
function membership_types(): array
{
    return [
        'general' => ['General Member', 'For anyone who shares our goals and wants to stay closely involved in our work.'],
        'volunteer' => ['Volunteer Member', 'For volunteers who give their time regularly to our programmes and drives.'],
        'student' => ['Student Member', 'For school and college students who want to learn and contribute.'],
        'life' => ['Life Member', 'For long-term supporters who wish to stay associated with the foundation.'],
    ];
}

/** Option lists used by the application forms. */
function recruitment_options(string $key): array
{
    switch ($key) {
        case 'subjects':
            return ['Hindi', 'English', 'Mathematics', 'Science', 'Social Studies', 'Computer Basics', 'Spoken English', 'Arts & Crafts', 'Life Skills', 'Adult Literacy'];
        case 'classes':
            return ['Primary (Class 1-5)', 'Middle (Class 6-8)', 'Secondary (Class 9-10)', 'Senior Secondary (11-12)', 'Adult learners'];
        case 'languages':
            return ['Hindi', 'English', 'Urdu', 'Punjabi', 'Bengali', 'Bhojpuri / Maithili'];
        case 'teaching_experience':
            return ['None yet', 'Less than 1 year', '1-3 years', '3-5 years', 'More than 5 years'];
        case 'modes':
            return ['in_person' => 'In person', 'online' => 'Online', 'both' => 'Both'];
        case 'notice_period':
            return ['Immediately available', '15 days', '30 days', '60 days', '90 days or more', 'Not applicable'];
        case 'year_of_study':
            return ['1st year', '2nd year', '3rd year', '4th year', '5th year', 'Final year', 'Postgraduate', 'Recently graduated'];
        case 'durations':
            return ['4 weeks', '6 weeks', '8 weeks', '3 months', '6 months'];
        case 'internship_areas':
            $areas = array_values(array_filter(array_map('trim', explode(',', setting('internship_areas', 'Education,Health,Women Empowerment,Environment,Social Media & Communication,Fundraising,Research & Documentation')))));
            return array_map(function ($a) { return mb_substr($a, 0, 120); }, $areas);
        case 'interview_modes':
            return ['In person', 'Phone call', 'Video call'];
        case 'education':
            return ['School (up to Class 10)', 'Class 12', 'Diploma / ITI', 'Graduate', 'Postgraduate', 'Other'];
    }
    return [];
}

// ---------------------------------------------------------------------
// Statuses: labels, colours and applicant-facing explanations
// ---------------------------------------------------------------------

/**
 * [admin label, badge tone (green|gold|blue|red|gray), applicant explanation, public label]
 */
function recruitment_status_info(string $status, string $kind = 'volunteer'): array
{
    $base = [
        'submitted' => ['Submitted', 'gold', 'We have received your application. Our team will review it and contact you.', 'Submitted'],
        'under_review' => ['Under Review', 'blue', 'Our team is reviewing your application.', 'Under review'],
        'info_requested' => ['Information Requested', 'gold', 'We need a little more information from you before we can continue.', 'Information requested'],
        'shortlisted' => ['Shortlisted', 'blue', 'Your application has been shortlisted for the next step.', 'Shortlisted'],
        'interview_scheduled' => ['Interview Scheduled', 'blue', 'A conversation with our team has been scheduled.', 'Interview scheduled'],
        'selected' => ['Selected', 'green', 'Congratulations - you have been selected. We will contact you about orientation and the next steps.', 'Selected'],
        'onboarding' => ['Onboarding', 'green', 'You are being onboarded: orientation, verification and an introduction to your coordinator.', 'Onboarding'],
        'active' => ['Active', 'green', 'You are now active with the foundation. Thank you for joining us.', 'Active'],
        'rejected' => ['Rejected', 'red', 'Thank you for your interest. We are unable to take this application forward at this time.', 'Not selected'],
        'withdrawn' => ['Withdrawn', 'gray', 'This application has been withdrawn.', 'Withdrawn'],
        'pending' => ['Pending', 'gold', 'We have received your request. Our team will review it carefully.', 'Received'],
        'approved' => ['Approved', 'green', 'Your application has been approved. Our team will contact you about the next steps.', 'Approved'],
        'assisted' => ['Support Provided', 'green', 'Support has been provided for this request. We hope it helped.', 'Support provided'],
        'disbursed' => ['Disbursed', 'green', 'The scholarship support for this application has been provided.', 'Support provided'],
        'suspended' => ['Suspended', 'red', 'This membership is currently suspended. Please contact us for details.', 'Suspended'],
    ];
    $info = $base[$status] ?? [humanize($status), 'gray', '', humanize($status)];
    if ($kind === 'beneficiary') {
        if ($status === 'rejected') {
            $info = ['Rejected', 'red', 'We are sorry - we are unable to support this request at this time. You are welcome to contact us to discuss other options, such as welfare schemes.', 'Not approved'];
        } elseif ($status === 'approved') {
            $info[2] = 'Your request has been approved for support. Our team will contact you to arrange it.';
        } elseif ($status === 'under_review') {
            $info[2] = 'Our team is reviewing your request and may contact you to understand your situation better.';
        }
    } elseif ($kind === 'scholarship') {
        if ($status === 'rejected') {
            $info = ['Rejected', 'red', 'Thank you for applying. We are unable to offer scholarship support for this application at this time.', 'Not approved'];
        } elseif ($status === 'shortlisted') {
            $info[2] = 'Your application has been shortlisted. We may contact you to verify documents or visit your family.';
        }
    } elseif ($kind === 'membership') {
        if ($status === 'approved') {
            $info[2] = 'Your membership has been approved. Welcome to the foundation!';
        } elseif ($status === 'rejected') {
            $info = ['Rejected', 'red', 'Your membership application was not approved. You may apply again or contact us for details.', 'Not approved'];
        } elseif ($status === 'pending') {
            $info[2] = 'Your membership application has been received and is awaiting review.';
        }
    }
    return $info;
}

/** Status badge. $public uses the gentler applicant-facing wording. */
function recruitment_status_badge(?string $status, string $kind = 'volunteer', bool $public = false): string
{
    $info = recruitment_status_info((string) $status, $kind);
    return '<span class="badge badge-' . e($info[1]) . '">' . e($public ? $info[3] : $info[0]) . '</span>';
}

/** Label for a history entry (status changes plus a few internal event types). */
function recruitment_history_label(string $status, string $kind, bool $public = false): string
{
    $special = [
        'checklist' => 'Onboarding checklist updated', 'coordinator' => 'Coordinator assigned', 'note' => 'Note',
        'role_linked' => 'Website account linked', 'certificate' => 'Certificate issued', 'info_received' => 'Information received',
    ];
    if (isset($special[$status])) {
        return $special[$status];
    }
    if ($status === 'submitted' || ($status === 'pending' && $kind !== 'membership')) {
        return $kind === 'beneficiary' ? 'Request submitted' : 'Application submitted';
    }
    return recruitment_status_info($status, $kind)[$public ? 3 : 0];
}

// ---------------------------------------------------------------------
// History timeline
// ---------------------------------------------------------------------

/** Add a row to the application timeline. $visible = shown to the applicant on the status page / dashboard. */
function record_application_history(string $type, int $id, string $status, ?string $note = null, bool $visible = true): void
{
    $note = $note !== null ? trim($note) : '';
    db_insert('application_history', [
        'application_type'     => mb_substr($type, 0, 40),
        'application_id'       => $id,
        'status'               => mb_substr($status, 0, 40),
        'note'                 => $note !== '' ? mb_substr($note, 0, 5000) : null,
        'visible_to_applicant' => $visible ? 1 : 0,
        'created_by'           => function_exists('current_user_id') ? current_user_id() : null,
    ]);
}

function application_history(string $type, int $id, bool $visibleOnly = false): array
{
    return db_rows('SELECT h.*, u.name AS created_by_name FROM application_history h LEFT JOIN users u ON u.id = h.created_by
                     WHERE h.application_type = ? AND h.application_id = ?' . ($visibleOnly ? ' AND h.visible_to_applicant = 1' : '') . '
                     ORDER BY h.created_at, h.id', [$type, $id]);
}

/** Timeline markup (uses the shared .timeline styles; enhanced by modules/recruitment.css on public pages). */
function recruitment_timeline_html(array $rows, string $kind, bool $admin = false): string
{
    if (!$rows) {
        return '<p class="muted small">No updates yet.</p>';
    }
    $html = '<ol class="timeline rc-timeline">';
    $last = count($rows) - 1;
    foreach (array_values($rows) as $i => $h) {
        $cls = $i === $last ? 'current' : 'done';
        if (in_array($h['status'], ['rejected', 'withdrawn', 'suspended'], true)) {
            $cls .= ' is-closed';
        }
        $html .= '<li class="' . $cls . '"><div class="rc-tl-head"><strong>' . e(recruitment_history_label($h['status'], $kind, !$admin)) . '</strong>'
            . '<time datetime="' . e(date('c', strtotime($h['created_at']))) . '">' . e(format_date($h['created_at'], 'd M Y, h:i A')) . '</time>';
        if ($admin) {
            $html .= ' <span class="badge badge-' . ($h['visible_to_applicant'] ? 'green' : 'gray') . '">' . ($h['visible_to_applicant'] ? 'Visible to applicant' : 'Internal') . '</span>';
            if (!empty($h['created_by_name'])) {
                $html .= ' <small class="muted">by ' . e($h['created_by_name']) . '</small>';
            }
        }
        $html .= '</div>';
        if (!empty($h['note'])) {
            $note = e_nl($h['note']);
            if ($h['status'] === 'checklist') {
                $note = str_replace(['[x] ', '[ ] '], ['&#10003; ', '&#9675; '], $note);
            }
            $html .= '<p class="rc-tl-note">' . $note . '</p>';
        }
        $html .= '</li>';
    }
    return $html . '</ol>';
}

// ---------------------------------------------------------------------
// Applicant notifications
// ---------------------------------------------------------------------

/**
 * Email the applicant and, when a website account exists for them, add an in-app notification.
 * $message is plain text (escaped, line breaks kept).
 * $opts: user_id, link (site path for the button and the notification), button (label), html (trusted extra HTML),
 *        ref (reference number), type (info|success|warning), no_email (bool), notice (shorter in-app text)
 * Returns true only when an email was actually sent (false: no address, no_email, or sending failed / switched off).
 * Accounts are matched by email only when that address is verified.
 */
function notify_applicant(string $email, string $name, string $subject, string $message, array $opts = []): bool
{
    $email = mb_strtolower(trim($email));
    $link = $opts['link'] ?? null;
    $sent = false;
    if ($email !== '' && empty($opts['no_email'])) {
        $html = '<p>Dear ' . e($name !== '' ? $name : 'applicant') . ',</p><p>' . e_nl($message) . '</p>' . ($opts['html'] ?? '');
        if ($link) {
            $html .= '<p><a class="btn" href="' . e(abs_url(ltrim($link, '/'))) . '">' . e($opts['button'] ?? 'View status') . '</a></p>';
        }
        $contact = setting('contact_email');
        if ($contact !== '') {
            $html .= '<p>If you have any questions, simply reply to this email or write to ' . e($contact)
                . (!empty($opts['ref']) ? ' quoting your reference number <strong>' . e($opts['ref']) . '</strong>' : '') . '.</p>';
        }
        $html .= '<p>Regards,<br>' . e(site_name()) . '</p>';
        $sent = send_mail($email, $subject, $html, $contact !== '' ? $contact : null);
    }
    $uid = (int) ($opts['user_id'] ?? 0);
    if (!$uid && $email !== '') {
        $uid = (int) db_value("SELECT id FROM users WHERE email = ? AND status = 'active' AND email_verified_at IS NOT NULL", [$email]);
    }
    if ($uid) {
        add_notification($uid, $subject, excerpt($opts['notice'] ?? $message, 900), $opts['notify_link'] ?? $link, $opts['type'] ?? 'info');
    }
    return $sent;
}

// ---------------------------------------------------------------------
// Status changes (admin)
// ---------------------------------------------------------------------

/** Users who may be assigned as coordinators (Volunteer Coordinator or Administrator role). */
function recruitment_coordinators(): array
{
    return db_rows("SELECT DISTINCT u.id, u.name, u.email FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                     WHERE r.slug IN ('volunteer_coordinator', 'administrator') AND u.status = 'active' ORDER BY u.name");
}

/**
 * Link the applicant's website account (same email, or the account that applied) and give it the
 * volunteer / teacher role. Returns ['linked' => bool, 'user' => ?array, 'role' => string, 'message' => string].
 */
function recruitment_link_account(string $type, array $row, bool $notify = true): array
{
    $t = recruitment_type($type);
    if (!$t || empty($t['role'])) {
        return ['linked' => false, 'user' => null, 'role' => '', 'message' => ''];
    }
    $user = null;
    if (!empty($row['user_id'])) {
        $user = db_row('SELECT id, name, email, status FROM users WHERE id = ?', [(int) $row['user_id']]);
    }
    $roleName = (string) db_value('SELECT name FROM roles WHERE slug = ?', [$t['role']]);
    if (!$user && !empty($row['email'])) {
        // Match by email only when the account has proved it owns that address: otherwise anyone could set their
        // account email to an applicant's address and receive the application (and its personal data).
        $byEmail = db_row('SELECT id, name, email, status, email_verified_at FROM users WHERE email = ?', [mb_strtolower((string) $row['email'])]);
        if ($byEmail && empty($byEmail['email_verified_at'])) {
            return ['linked' => false, 'user' => null, 'role' => $roleName,
                'message' => 'A website account uses ' . $row['email'] . ' but its email address is not verified, so it was not linked and no role was added. Once the account owner verifies the address, set the status to Active again or use "Link account & add role".'];
        }
        $user = $byEmail;
    }
    if (!$user) {
        return ['linked' => false, 'user' => null, 'role' => $roleName,
            'message' => 'No website account uses ' . $row['email'] . ', so no role was added. If the applicant registers with this email, set the status to Active again to link the account.'];
    }
    if ($user['status'] !== 'active') {
        return ['linked' => false, 'user' => $user, 'role' => $roleName, 'message' => 'The website account for ' . $user['email'] . ' is ' . $user['status'] . ', so no role was added.'];
    }
    if (empty($row['user_id'])) {
        db_update($t['table'], ['user_id' => $user['id']], 'id = :id', ['id' => $row['id']]);
    }
    $had = (int) db_value('SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug = ?', [$user['id'], $t['role']]) > 0;
    assign_role((int) $user['id'], $t['role'], current_user_id());
    if (!$had) {
        record_application_history($type, (int) $row['id'], 'role_linked', 'Role "' . $roleName . '" added to website account #' . $user['id'] . '.', false);
        if (function_exists('admin_log')) {
            admin_log('role_assigned', $roleName . ' role given to user #' . $user['id'] . ' (' . ($row['application_no'] ?? '') . ')');
        }
        if ($notify) {
            add_notification((int) $user['id'], 'You are now an active ' . strtolower($t['label']),
                'Your ' . strtolower($t['title']) . ' ' . ($row['application_no'] ?? '') . ' is now active and your account has the ' . $roleName . ' role. Your tasks and certificates will appear in your dashboard.',
                '/dashboard/', 'success');
        }
    }
    return ['linked' => true, 'user' => $user, 'role' => $roleName, 'new' => !$had,
        'message' => $had ? 'The website account ' . $user['email'] . ' already has the ' . $roleName . ' role.' : 'The ' . $roleName . ' role was added to the website account ' . $user['email'] . '.'];
}

/**
 * Change the status of a recruitment application: update the row, add history, link the account role when Active
 * and (optionally) email the applicant. $extra = additional columns to update (interview_at, info_request ...).
 * Returns a list of messages for the admin.
 */
function recruitment_change_status(string $type, array $row, string $status, string $note = '', bool $visible = true, bool $notify = false, array $extra = [], string $emailExtra = ''): array
{
    $t = recruitment_type($type);
    if (!$t || !in_array($status, recruitment_status_keys(), true)) {
        return [];
    }
    db_update($t['table'], ['status' => $status, 'reviewed_by' => current_user_id(), 'reviewed_at' => date('Y-m-d H:i:s')] + $extra, 'id = :id', ['id' => $row['id']]);
    rc_record_status($type, (int) $row['id'], $status, $note, $visible);
    $messages = [];
    $link = null;
    if ($status === 'active') {
        $link = recruitment_link_account($type, $row, !$notify);
        if ($link['message'] !== '') {
            $messages[] = $link['message'];
        }
    }
    if ($notify && !empty($row['email'])) {
        $info = recruitment_status_info($status, $type);
        $text = 'There is an update on your ' . strtolower($t['title']) . ' ' . $row['application_no'] . ".\n\nCurrent status: " . $info[3] . "\n" . $info[2];
        if ($visible && trim($note) !== '') {
            $text .= "\n\nMessage from our team:\n" . trim($note);
        }
        if ($link && !empty($link['linked']) && !empty($link['new'])) {
            $text .= "\n\nYour website account now has the " . $link['role'] . ' role. Sign in to see your tasks and certificates in your dashboard.';
        } elseif ($status === 'active' && $link && empty($link['linked'])) {
            $text .= "\n\nTip: create a free account on our website with this email address so that your tasks and certificates can be shared with you.";
        }
        $messages[] = notify_applicant((string) $row['email'], (string) $row['name'], 'Update on your application ' . $row['application_no'], $text, [
            'link' => '/application-status/?ref=' . rawurlencode((string) $row['application_no']), 'ref' => $row['application_no'],
            'user_id' => $link['user']['id'] ?? ($row['user_id'] ?? null), 'html' => $emailExtra,
            'type' => in_array($status, ['selected', 'onboarding', 'active'], true) ? 'success' : 'info',
        ])
            ? 'The applicant was notified by email.'
            : 'The email to the applicant could not be sent (check Settings > Email delivery). The new status is shown on their application status page'
                . (!empty($link['user']['id']) || !empty($row['user_id']) ? ' and in their account' : '') . ' - please inform them by phone if needed.';
    }
    if (function_exists('admin_log')) {
        admin_log('application_status', $t['title'] . ' ' . $row['application_no'] . ': ' . $row['status'] . ' -> ' . $status);
    }
    return $messages;
}

/**
 * History for a status change: the status itself is always visible to the applicant (it is shown on the status page anyway);
 * the note is attached to it when $noteVisible, otherwise it is stored as a separate internal note.
 */
function rc_record_status(string $kind, int $id, string $status, string $note, bool $noteVisible): void
{
    $note = trim($note);
    record_application_history($kind, $id, $status, $noteVisible ? $note : null, true);
    if (!$noteVisible && $note !== '') {
        record_application_history($kind, $id, 'note', $note, false);
    }
}

/**
 * Status change for scholarships, help requests and memberships (kinds scholarship|beneficiary|membership):
 * row update, history, optional email + in-app notification. $extra = more columns to update.
 */
function recruitment_support_change_status(string $kind, array $row, string $status, string $note = '', bool $visible = true, bool $notify = false, array $extra = []): array
{
    $k = recruitment_kinds()[$kind] ?? null;
    if (!$k || !in_array($status, $k['statuses'], true)) {
        return [];
    }
    $update = ['status' => $status] + $extra;
    if ($kind !== 'membership') {
        $update += ['reviewed_by' => current_user_id(), 'reviewed_at' => date('Y-m-d H:i:s')];
    }
    db_update($k['table'], $update, 'id = :id', ['id' => $row['id']]);
    rc_record_status($kind, (int) $row['id'], $status, $note, $visible);
    $messages = [];
    if ($notify) {
        $info = recruitment_status_info($status, $kind);
        if ($kind === 'membership') {
            $user = db_row('SELECT id, name, email FROM users WHERE id = ?', [(int) $row['user_id']]);
            $email = $user['email'] ?? '';
            $name = $user['name'] ?? '';
            $ref = (string) ($extra['membership_no'] ?? $row['membership_no'] ?? '');
            $text = 'There is an update on your membership application.' . "\n\nCurrent status: " . $info[3] . "\n" . $info[2];
            if ($status === 'approved' && $ref !== '') {
                $text .= "\n\nYour membership number is " . $ref . '.';
            }
            $link = '/membership/';
            $uid = (int) ($user['id'] ?? 0);
        } else {
            $email = (string) ($row['email'] ?? '');
            $name = (string) ($kind === 'scholarship' ? $row['guardian_name'] : $row['name']);
            $ref = (string) $row[$k['ref_col']];
            $text = 'There is an update on your ' . strtolower($k['label']) . ' ' . $ref . ".\n\nCurrent status: " . $info[3] . "\n" . $info[2];
            $link = '/application-status/?ref=' . rawurlencode($ref);
            $uid = (int) ($row['user_id'] ?? 0);
        }
        if ($visible && trim($note) !== '') {
            $text .= "\n\nMessage from our team:\n" . trim($note);
        }
        if ($email !== '' || $uid) {
            $sent = notify_applicant($email, $name, 'Update on your ' . strtolower($k['label']) . ($ref !== '' ? ' ' . $ref : ''), $text,
                ['link' => $link, 'ref' => $ref, 'user_id' => $uid ?: null, 'type' => in_array($status, ['approved', 'assisted', 'disbursed'], true) ? 'success' : 'info']);
            if ($email === '') {
                $messages[] = 'The applicant was notified in their account (no email address on file).';
            } elseif ($sent) {
                $messages[] = 'The applicant was notified by email.';
            } else {
                $messages[] = 'The email could not be sent (check Settings > Email delivery)' . ($uid ? '; the applicant was notified in their account.' : ' - please inform the applicant by phone.');
            }
        } else {
            $messages[] = 'No email address or website account is on file - please inform the applicant by phone.';
        }
    }
    if (function_exists('admin_log')) {
        admin_log('application_status', $k['label'] . ' ' . ($row[$k['ref_col']] ?? '#' . $row['id']) . ': ' . $row['status'] . ' -> ' . $status);
    }
    return $messages;
}

/** Status filter chips with counts (admin lists). $query = GET parameters to keep. */
function recruitment_chipbar(array $statuses, array $counts, string $current, array $query, string $kind): string
{
    unset($query['status'], $query['page']);
    $total = array_sum($counts);
    $html = '<nav class="rc-chipbar" aria-label="Filter by status"><a href="?' . e(http_build_query($query)) . '"' . ($current === '' ? ' class="active" aria-current="true"' : '') . '>All <em>' . (int) $total . '</em></a>';
    foreach ($statuses as $st) {
        $html .= '<a href="?' . e(http_build_query($query + ['status' => $st])) . '"' . ($current === $st ? ' class="active" aria-current="true"' : '') . '>'
            . e(recruitment_status_info($st, $kind)[0]) . ' <em>' . (int) ($counts[$st] ?? 0) . '</em></a>';
    }
    return $html . '</nav>';
}

/** Status counts for a table with an optional extra WHERE (" AND ..."). */
function recruitment_status_counts(string $table, string $where = '', array $params = []): array
{
    $counts = [];
    foreach (db_rows('SELECT status, COUNT(*) n FROM `' . $table . '` a WHERE 1=1 ' . $where . ' GROUP BY status', $params) as $r) {
        $counts[$r['status']] = (int) $r['n'];
    }
    return $counts;
}

/** Onboarding checklist items for a recruitment type. */
function recruitment_onboarding_items(string $type): array
{
    $items = ['Orientation session completed', 'Identity and contact details verified', 'Volunteer Policy & code of conduct acknowledged',
        'Child protection briefing completed'];
    if (in_array($type, ['teacher', 'staff', 'internship'], true)) {
        $items[] = 'References / background check completed';
    }
    if ($type === 'internship') {
        $items[] = 'Internship plan and mentor agreed';
    }
    $items[] = 'Introduced to coordinator and programme team';
    return $items;
}

/** Phone number masked for list views: 98xxxxxx10 */
function rc_mask_phone(?string $phone): string
{
    $digits = preg_replace('/\D/', '', (string) $phone);
    if (strlen($digits) < 6) {
        return $digits === '' ? '-' : str_repeat('x', strlen($digits));
    }
    return substr($digits, 0, 2) . str_repeat('x', strlen($digits) - 4) . substr($digits, -2);
}

/** Small CSS block for admin pages of this module (admin pages do not load modules/recruitment.css). */
function recruitment_admin_css(): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    return '<style>
.rc-chipbar{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px}
.rc-chipbar a{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid var(--line);border-radius:999px;font-size:.82rem;font-weight:600;color:var(--ink-soft);background:#fff}
.rc-chipbar a em{font-style:normal;background:var(--bg-soft);border-radius:999px;padding:0 7px;font-size:.75rem;color:var(--muted)}
.rc-chipbar a.active{background:var(--green-600);border-color:var(--green-600);color:#fff}
.rc-chipbar a.active em{background:rgba(255,255,255,.2);color:#fff}
.rc-bulk{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:12px;border:1px dashed var(--line);border-radius:12px;margin-top:14px;background:var(--bg-soft)}
.rc-bulk select,.rc-bulk input[type=text]{width:auto;flex:1 1 180px;min-height:38px;padding:6px 10px;font-size:.86rem}
.rc-stack{display:grid;gap:12px}
.rc-stack .form-group{gap:4px}
.rc-timeline .rc-tl-head{display:flex;flex-wrap:wrap;gap:6px 10px;align-items:center}
.rc-timeline time{font-size:.8rem;color:var(--muted)}
.rc-tl-note{margin:4px 0 0;font-size:.88rem;color:var(--ink-soft);white-space:normal}
.rc-check-list{list-style:none;padding:0;margin:0;display:grid;gap:6px}
.rc-muted-box{background:var(--bg-soft);border:1px solid var(--line);border-radius:12px;padding:12px 14px;font-size:.88rem}
.rc-table-check{width:34px}
.rc-cert-wrap{overflow-x:auto;padding:6px}
.rc-task-status{min-width:140px;width:auto;padding:6px 30px 6px 10px;font-size:.85rem}
.admin-content .table-wrap{position:relative}
@media (max-width:600px){.admin-content .detail-list{grid-template-columns:minmax(0,1fr);gap:2px}.admin-content .detail-list dd{margin-bottom:10px}.rc-bulk select,.rc-bulk input[type=text]{flex-basis:100%}}
@media print{.admin-sidebar,.admin-topbar,.admin-footer,.rc-noprint,.flash-wrap{display:none!important}.admin-main,.admin-content{margin:0!important;padding:0!important}.rc-cert-wrap{overflow:visible;padding:0}}
</style>';
}

// ---------------------------------------------------------------------
// Private documents (resumes, scholarship / help request documents, certificates)
// ---------------------------------------------------------------------

/** May the signed-in visitor download this application's document? Staff need the kind's permission (password sign-in), applicants their own row. */
function recruitment_can_download(string $kind, array $row): bool
{
    $k = recruitment_kinds()[$kind] ?? null;
    if (!$k) {
        return false;
    }
    $uid = current_user_id();
    if (!$uid) {
        return false;
    }
    if (staff_session_can($k['perm'])) {
        return true;
    }
    return !empty($row['user_id']) && (int) $row['user_id'] === $uid;
}

/** Stream the private document of an application after a permission check (404 when missing, 403 when not allowed). */
function send_application_document(string $kind, int $id): void
{
    $k = recruitment_kinds()[$kind] ?? null;
    if (!$k || !$k['doc_col']) {
        not_found();
    }
    $row = db_row('SELECT * FROM `' . $k['table'] . '` WHERE id = ?', [$id]);
    if (!$row || empty($row[$k['doc_col']])) {
        not_found();
    }
    if (!recruitment_can_download($kind, $row)) {
        http_response_code(403);
        exit('You are not allowed to download this document.');
    }
    $ref = (string) ($row[$k['ref_col']] ?: $kind . '-' . $id);
    log_activity('document_downloaded', $k['label'] . ' ' . $ref, current_user_id(), is_staff());
    send_private_file($row[$k['doc_col']], $ref . '-' . ($k['doc_col'] === 'resume_path' ? 'resume' : 'document'));
}

/** Certificate number: PWF/CERT/<year>/<id> */
function certificate_number(int $id, ?string $issueDate = null): string
{
    return 'PWF/CERT/' . date('Y', $issueDate ? strtotime($issueDate) : time()) . '/' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}

const CERTIFICATE_TYPES = [
    'volunteer' => 'Volunteering', 'internship' => 'Internship', 'teaching' => 'Volunteer teaching',
    'membership' => 'Membership', 'appreciation' => 'Appreciation', 'participation' => 'Participation',
];

/** Approved certificates of a user (member dashboard). */
function recruitment_user_certificates(int $userId): array
{
    return db_rows("SELECT * FROM certificates WHERE user_id = ? AND status = 'approved' ORDER BY issue_date DESC, id DESC", [$userId]);
}

/** Stream an uploaded certificate PDF: the owner (approved only) or staff with certificates.manage. */
function send_certificate_file(int $certId): void
{
    $cert = db_row('SELECT * FROM certificates WHERE id = ?', [$certId]);
    if (!$cert || empty($cert['file_path'])) {
        not_found();
    }
    $uid = current_user_id();
    $staff = $uid && staff_session_can('certificates.manage');
    $owner = $uid && (int) $cert['user_id'] === $uid && $cert['status'] === 'approved';
    if (!$staff && !$owner) {
        http_response_code(403);
        exit('You are not allowed to download this certificate.');
    }
    log_activity('certificate_downloaded', $cert['certificate_no'], $uid, $staff);
    send_private_file($cert['file_path'], str_replace('/', '-', $cert['certificate_no']));
}

/**
 * Printable certificate (self-contained styles, works on public pages, in the member dashboard and in the admin panel).
 * $cert: certificate_no, recipient_name, certificate_type, title, description, issue_date, status (optional).
 */
function render_certificate_html(array $cert): string
{
    static $styled = false;
    $css = '';
    if (!$styled) {
        $styled = true;
        $css = '<style>
.pwf-cert{--c-green:#0f5734;--c-gold:#c9a227;--c-ink:#24312B;position:relative;max-width:960px;margin:0 auto;background:#fffdf7;color:var(--c-ink);padding:clamp(14px,2.4vw,26px);border-radius:6px;box-shadow:0 18px 50px rgba(20,50,34,.16);-webkit-print-color-adjust:exact;print-color-adjust:exact;font-family:"Poppins","Segoe UI",Arial,sans-serif;overflow:hidden}
.pwf-cert-frame{position:relative;border:clamp(4px,.8vw,8px) solid var(--c-green);padding:clamp(6px,1vw,10px);background:linear-gradient(135deg,rgba(244,201,0,.08),transparent 30%,transparent 70%,rgba(44,166,90,.08))}
.pwf-cert-inner{position:relative;border:2px solid var(--c-gold);padding:clamp(22px,5vw,54px) clamp(16px,5vw,64px) clamp(20px,4vw,40px);text-align:center;background:radial-gradient(circle at 50% 42%,rgba(255,255,255,.9),rgba(255,253,247,.6) 60%),repeating-linear-gradient(45deg,rgba(15,87,52,.025) 0 2px,transparent 2px 9px)}
.pwf-cert-inner::before,.pwf-cert-inner::after{content:"";position:absolute;width:clamp(34px,6vw,64px);height:clamp(34px,6vw,64px);border:3px solid var(--c-gold)}
.pwf-cert-inner::before{top:8px;left:8px;border-right:0;border-bottom:0}
.pwf-cert-inner::after{bottom:8px;right:8px;border-left:0;border-top:0}
.pwf-cert-logo{width:clamp(64px,10vw,96px);height:clamp(64px,10vw,96px);object-fit:contain;margin:0 auto 6px;display:block}
.pwf-cert-org{margin:0;font-size:clamp(.95rem,2vw,1.25rem);font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:var(--c-green)}
.pwf-cert-tag{margin:2px 0 0;font-size:clamp(.72rem,1.4vw,.85rem);color:#7a6a2a;letter-spacing:.06em}
.pwf-cert-rule{width:min(320px,60%);height:2px;margin:clamp(12px,2.4vw,22px) auto;background:linear-gradient(90deg,transparent,var(--c-gold),transparent)}
.pwf-cert-title{margin:0;font-family:Georgia,"Times New Roman",serif;font-size:clamp(1.5rem,4.6vw,2.6rem);font-weight:700;color:var(--c-green);letter-spacing:.02em;line-height:1.15}
.pwf-cert-type{margin:6px 0 0;font-size:clamp(.7rem,1.4vw,.82rem);letter-spacing:.3em;text-transform:uppercase;color:#a07400;font-weight:600}
.pwf-cert-present{margin:clamp(14px,3vw,26px) 0 4px;font-size:clamp(.85rem,1.6vw,1rem);color:#5e6e66;font-style:italic}
.pwf-cert-name{display:inline-block;margin:0;padding:0 clamp(10px,3vw,36px) 6px;font-family:Georgia,"Times New Roman",serif;font-size:clamp(1.6rem,5.2vw,2.8rem);font-weight:700;color:#10506e;border-bottom:2px solid var(--c-gold);line-height:1.2;word-break:break-word}
.pwf-cert-desc{max-width:640px;margin:clamp(12px,2.6vw,22px) auto 0;font-size:clamp(.86rem,1.7vw,1.02rem);line-height:1.7;color:#3b4a42}
.pwf-cert-foot{display:grid;grid-template-columns:1fr auto 1fr;gap:clamp(10px,3vw,30px);align-items:end;margin-top:clamp(22px,4.6vw,46px)}
.pwf-cert-sig{text-align:center;font-size:clamp(.72rem,1.4vw,.85rem);color:#5e6e66}
.pwf-cert-sig strong{display:block;color:var(--c-ink);font-size:clamp(.82rem,1.6vw,.98rem);border-top:1.5px solid #8b9a92;padding-top:6px;margin-top:clamp(14px,3vw,28px)}
.pwf-cert-seal{width:clamp(64px,11vw,104px);height:clamp(64px,11vw,104px);border-radius:50%;display:flex;align-items:center;justify-content:center;background:radial-gradient(circle,#f8e27a 0,#e2bd2c 60%,#c9a227 100%);box-shadow:0 0 0 4px #fff7d1,0 0 0 6px var(--c-gold);color:var(--c-green);font-weight:800;font-size:clamp(.5rem,1vw,.66rem);letter-spacing:.12em;text-transform:uppercase;line-height:1.25;text-align:center;padding:8px}
.pwf-cert-no{margin:clamp(16px,3vw,26px) 0 0;font-size:clamp(.68rem,1.3vw,.8rem);color:#5e6e66;letter-spacing:.06em}
.pwf-cert-hindi{font-family:"Poppins Devanagari","Noto Sans Devanagari",Mangal,sans-serif}
.pwf-cert-mark{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;font-size:clamp(2rem,9vw,6rem);font-weight:800;letter-spacing:.1em;color:rgba(198,40,40,.13);transform:rotate(-18deg);text-transform:uppercase}
@media (max-width:520px){.pwf-cert-foot{grid-template-columns:1fr 1fr}.pwf-cert-seal{grid-column:1/-1;grid-row:1;margin:0 auto}}
@media print{.pwf-cert{box-shadow:none;max-width:none;margin:0}}
</style>';
    }
    $status = (string) ($cert['status'] ?? 'approved');
    $signatory = trim(setting('certificate_signatory_name'));
    $signTitle = setting('certificate_signatory_title', setting('receipt_signatory', 'Authorised Signatory'));
    $typeLabel = CERTIFICATE_TYPES[$cert['certificate_type'] ?? ''] ?? '';
    $mark = $status === 'revoked' ? 'Revoked' : ($status === 'pending' ? 'Draft' : '');
    $issue = !empty($cert['issue_date']) ? format_date($cert['issue_date'], 'd F Y') : '';
    $html = $css . '<div class="pwf-cert" role="document" aria-label="' . e(($cert['title'] ?? 'Certificate') . ' - ' . ($cert['recipient_name'] ?? '')) . '">'
        . '<div class="pwf-cert-frame"><div class="pwf-cert-inner">'
        . '<img class="pwf-cert-logo" src="' . e(site_logo()) . '" alt="' . e(site_name()) . ' logo" width="96" height="96">'
        . '<p class="pwf-cert-org">' . e(site_name()) . '</p>'
        . (setting('brand_slogan') !== '' ? '<p class="pwf-cert-tag">' . e(setting('brand_slogan')) . '</p>' : '')
        . '<div class="pwf-cert-rule" aria-hidden="true"></div>'
        . '<h2 class="pwf-cert-title">' . e($cert['title'] ?? 'Certificate') . '</h2>'
        . ($typeLabel !== '' && stripos((string) ($cert['title'] ?? ''), $typeLabel) === false ? '<p class="pwf-cert-type">' . e($typeLabel) . '</p>' : '')
        . '<p class="pwf-cert-present">This certificate is proudly presented to</p>'
        . '<p class="pwf-cert-name">' . e($cert['recipient_name'] ?? '') . '</p>'
        . (!empty($cert['description']) ? '<p class="pwf-cert-desc">' . e_nl($cert['description']) . '</p>' : '')
        . '<div class="pwf-cert-foot">'
        . '<div class="pwf-cert-sig"><strong>' . e($issue !== '' ? $issue : '-') . '</strong>Date of issue</div>'
        . '<div class="pwf-cert-seal" aria-hidden="true">' . implode('<br>', array_map('e', array_slice(preg_split('/\s+/', trim(site_name())), 0, 4))) . '</div>'
        . '<div class="pwf-cert-sig"><strong>' . e($signatory !== '' ? $signatory : $signTitle) . '</strong>' . e($signatory !== '' ? $signTitle : 'For ' . site_name()) . '</div>'
        . '</div>'
        . '<p class="pwf-cert-no">Certificate No. <strong>' . e($cert['certificate_no'] ?? '') . '</strong>'
        . (setting('site_tagline') !== '' ? ' &middot; <span class="pwf-cert-hindi" lang="hi">' . e(setting('site_tagline')) . '</span>' : '') . '</p>'
        . '</div></div>'
        . ($mark !== '' ? '<div class="pwf-cert-mark" aria-hidden="true">' . e($mark) . '</div>' : '')
        . '</div>';
    return $html;
}

// ---------------------------------------------------------------------
// Member-area helpers (used by the member dashboard)
// ---------------------------------------------------------------------

/** All applications that belong to a user (WHERE user_id = ?), newest first. */
function recruitment_member_applications(int $userId): array
{
    $list = [];
    foreach (recruitment_kinds() as $kind => $k) {
        if ($kind === 'membership') {
            continue;
        }
        $nameCol = $k['name_col'];
        foreach (db_rows('SELECT id, `' . $k['ref_col'] . '` AS ref, `' . $nameCol . '` AS name, status, created_at, updated_at FROM `' . $k['table'] . '` WHERE user_id = ? ORDER BY created_at DESC', [$userId]) as $r) {
            $info = recruitment_status_info($r['status'], $kind);
            $list[] = $r + ['kind' => $kind, 'label' => $k['label'], 'status_label' => $info[3], 'status_text' => $info[2], 'tone' => $info[1],
                'status_url' => page_url('application-status', ['ref' => $r['ref']])];
        }
    }
    $m = db_row('SELECT id, membership_no AS ref, status, created_at, updated_at FROM members WHERE user_id = ?', [$userId]);
    if ($m) {
        $info = recruitment_status_info($m['status'], 'membership');
        $list[] = $m + ['name' => '', 'kind' => 'membership', 'label' => 'Membership application', 'status_label' => $info[3], 'status_text' => $info[2],
            'tone' => $info[1], 'status_url' => page_url('membership')];
    }
    usort($list, function ($a, $b) {
        return strcmp((string) $b['created_at'], (string) $a['created_at']);
    });
    return $list;
}

/** Tasks assigned to a user. */
function recruitment_user_tasks(int $userId): array
{
    return db_rows("SELECT * FROM tasks WHERE user_id = ? AND status <> 'cancelled' ORDER BY FIELD(status, 'in_progress', 'assigned', 'done'), due_date IS NULL, due_date, id DESC", [$userId]);
}

// ---------------------------------------------------------------------
// Public application status lookup
// ---------------------------------------------------------------------

/** Find an application by reference number + the email or mobile number used. Generic null when anything does not match. */
function recruitment_find_application(string $ref, string $contact): ?array
{
    $ref = strtoupper(trim($ref));
    if (!preg_match('/^PWF-([A-Z]{3})-\d{4}-\d{5,}$/', $ref, $m)) {
        return null;
    }
    $kind = null;
    foreach (recruitment_kinds() as $key => $k) {
        if ($k['prefix'] === $m[1] && $key !== 'membership') {
            $kind = $key;
        }
    }
    if (!$kind) {
        return null;
    }
    $k = recruitment_kinds()[$kind];
    $row = db_row('SELECT * FROM `' . $k['table'] . '` WHERE `' . $k['ref_col'] . '` = ?', [$ref]);
    if (!$row) {
        return null;
    }
    $contact = mb_strtolower(trim($contact));
    $digits = preg_replace('/\D/', '', $contact);
    $emailMatch = $contact !== '' && !empty($row['email']) && hash_equals(mb_strtolower((string) $row['email']), $contact);
    $phoneMatch = strpos($contact, '@') === false && strlen($digits) >= 10
        && substr(preg_replace('/\D/', '', (string) $row['phone']), -10) === substr($digits, -10);
    if (!$emailMatch && !$phoneMatch) {
        return null;
    }
    return ['kind' => $kind, 'id' => (int) $row['id'], 'row' => $row];
}

// ---------------------------------------------------------------------
// Public form helpers (templates volunteer, teacher, staff, internship, membership, scholarship, request_help)
// ---------------------------------------------------------------------

/** Load the shared client-side validation script on application pages. */
function recruitment_form_assets(): void
{
    $src = asset('assets/js/form-validation.js');
    if (!in_array($src, $GLOBALS['extraScripts'] ?? [], true)) {
        $GLOBALS['extraScripts'][] = $src;
    }
}

/** Consent checkboxes. Keys: privacy, child, conduct, verify, contact, declaration, guardian, datause, membership, adult, minor. */
function recruitment_consent_defs(): array
{
    $privacy = '<a href="' . e(page_url('privacy-policy')) . '" target="_blank" rel="noopener">Privacy Policy</a>';
    $child = '<a href="' . e(page_url('child-protection-policy')) . '" target="_blank" rel="noopener">Child Protection Policy</a>';
    $conduct = '<a href="' . e(page_url('volunteer-policy')) . '" target="_blank" rel="noopener">Volunteer Policy and code of conduct</a>';
    return [
        'privacy' => ['accept_privacy', 'I have read the ' . $privacy . ' and agree to my details being used to process this application.', 'Privacy Policy'],
        'child' => ['accept_child', 'I have read the ' . $child . ' and will follow it whenever I work with or near children.', 'Child Protection Policy'],
        'conduct' => ['accept_conduct', 'I agree to follow the ' . $conduct . '.', 'Volunteer code of conduct'],
        'verify' => ['accept_verify', 'I consent to verification of my details, including identity and reference checks, before I am placed in any activity with children.', 'Background verification'],
        'contact' => ['accept_contact', 'I agree that the foundation may contact me by phone or email about this request and visit or verify the details if needed.', 'Consent to be contacted'],
        'declaration' => ['accept_declaration', 'I confirm that the information I have given is true to the best of my knowledge.', 'Declaration'],
        'guardian' => ['accept_guardian', 'I am the student (18 years or older) or the student\'s parent / guardian, and I agree to this application being made.', 'Parent / guardian consent'],
        'datause' => ['accept_datause', 'I agree that these details may be used to assess this request and shared only with the foundation\'s staff and volunteers who help with it, as explained in the ' . $privacy . '.', 'Use of your information'],
        'membership' => ['accept_membership', 'I support the objectives of the foundation and agree to abide by its policies and the ' . $conduct . '.', 'Membership declaration'],
        // Volunteer Policy: 18+, or under 18 with written parent / guardian consent (DPDP Act - consent for a child's data)
        'adult' => ['accept_adult', 'I am 18 years or older - or I am under 18 and my parent / guardian agrees to this application (the foundation will ask for their written consent before I take part in any activity).', 'Age / parent consent'],
        'minor' => ['accept_minor', 'If the person who needs support is under 18, I am their parent or lawful guardian, or I am sending this with their parent / guardian\'s permission.', 'Consent for a child'],
    ];
}

function recruitment_consent_html(array $which, array $conditional = []): string
{
    $defs = recruitment_consent_defs();
    $html = '<fieldset class="rc-consents"><legend class="form-section-title">' . icon('shield') . 'Consent &amp; declarations</legend>';
    foreach ($which as $key) {
        if (!isset($defs[$key])) {
            continue;
        }
        [$name, $label] = $defs[$key];
        if (isset($conditional[$key])) {
            // Required only in a specific situation (checked on the server), e.g. when the applicant is a child.
            $html .= '<div class="form-group full"><label class="checkbox"><input type="checkbox" name="' . e($name) . '" value="1"' . old_checked($name, '1') . '><span>' . $label . '</span></label>'
                . '<span class="help">' . e($conditional[$key]) . '</span>' . field_error($name) . '</div>';
            continue;
        }
        $html .= '<div class="form-group full"><label class="checkbox"><input type="checkbox" name="' . e($name) . '" value="1" required' . old_checked($name, '1') . '><span>' . $label . '<span class="req">*</span></span></label>' . field_error($name) . '</div>';
    }
    return $html . '</fieldset>';
}

/** Validation rules + labels for the consent checkboxes. Returns [rules, labels]. */
function recruitment_consent_rules(array $which): array
{
    $defs = recruitment_consent_defs();
    $rules = $labels = [];
    foreach ($which as $key) {
        if (isset($defs[$key])) {
            $rules[$defs[$key][0]] = 'accepted';
            $labels[$defs[$key][0]] = $defs[$key][2];
        }
    }
    return [$rules, $labels];
}

/** Volunteer teacher terms (Admin > Settings > Recruitment): title, intro, points and a short version id of the text. */
function teacher_terms(): array
{
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', setting('teacher_terms_text')))));
    return [
        'title' => setting('teacher_terms_title', 'Volunteer Teacher - Terms & Conditions'),
        'intro' => setting('teacher_terms_intro'),
        'lines' => $lines,
        'version' => substr(hash('sha256', implode("\n", $lines)), 0, 12),
    ];
}

/** Pop-up with the volunteer teacher terms; the form stays locked until "I accept" (no-JS: the checkbox in the form). */
function teacher_terms_modal_html(): string
{
    $t = teacher_terms();
    if (!$t['lines']) {
        return '';
    }
    $items = '';
    foreach ($t['lines'] as $line) {
        $items .= '<li>' . e($line) . '</li>';
    }
    return '<dialog class="modal terms-modal" id="teacher-terms" aria-labelledby="teacher-terms-title" aria-describedby="teacher-terms-intro">'
        . '<div class="modal-card"><div class="modal-head"><h2 id="teacher-terms-title">' . icon('shield') . e($t['title']) . '</h2>'
        . '<button type="button" class="modal-close" data-modal-close aria-label="Close">' . icon('x') . '</button></div>'
        . '<div class="modal-body">' . ($t['intro'] !== '' ? '<p id="teacher-terms-intro" class="terms-intro">' . e($t['intro']) . '</p>' : '')
        . '<ol class="terms-list">' . $items . '</ol>'
        . '<p class="terms-links">Read in full: <a href="' . e(page_url('volunteer-policy')) . '" target="_blank" rel="noopener">Volunteer Policy</a> · '
        . '<a href="' . e(page_url('child-protection-policy')) . '" target="_blank" rel="noopener">Child Protection Policy</a> · '
        . '<a href="' . e(page_url('privacy-policy')) . '" target="_blank" rel="noopener">Privacy Policy</a></p></div>'
        . '<div class="terms-actions"><button type="button" class="btn btn-outline" data-modal-close>Not now</button>'
        . '<button type="button" class="btn btn-primary" data-terms-accept>' . icon('check') . 'I accept</button></div></div></dialog>';
}

/** Opening of an application form (CSRF, honeypot, upload limit). */
function recruitment_form_open(string $slug, bool $files = false, string $id = 'application-form'): string
{
    return '<form id="' . e($id) . '" class="rc-form" action="' . e(page_url($slug)) . '#apply" method="post"' . ($files ? ' enctype="multipart/form-data"' : '') . ' data-validate>'
        . csrf_field() . honeypot_field()
        . ($files ? '<input type="hidden" name="MAX_FILE_SIZE" value="' . (MAX_UPLOAD_MB * 1024 * 1024) . '">' : '');
}

/** Note under the form about the account link. */
function recruitment_account_note(string $slug): string
{
    $user = current_user();
    if ($user) {
        return '<p class="form-note">' . icon('user') . '<span>You are signed in as <strong>' . e($user['name']) . '</strong>. This application will be linked to your account and appear in <a href="' . e(member_url('applications')) . '">your dashboard</a>.</span></p>';
    }
    return '<p class="form-note">' . icon('info') . '<span>You will receive a reference number by email. Already have an account? <a href="' . e(page_url('login', ['next' => '/' . $slug . '/'])) . '">Log in</a> first to follow this application in your dashboard.</span></p>';
}

/** Help text + file input for a private document. */
function recruitment_file_field(string $name, string $label, bool $required, string $help, bool $pdfOnly = true): string
{
    $accept = $pdfOnly ? '.pdf,application/pdf' : '.pdf,.jpg,.jpeg,.png';
    return '<div class="form-group full"><label for="' . e($name) . '">' . e($label) . ($required ? '<span class="req">*</span>' : '') . '</label>'
        . '<input id="' . e($name) . '" type="file" name="' . e($name) . '" accept="' . $accept . '" data-max-mb="' . MAX_UPLOAD_MB . '"' . ($required ? ' required' : '') . '>'
        . '<span class="help">' . icon('lock') . ' ' . e($help) . ' Maximum ' . MAX_UPLOAD_MB . ' MB. Stored privately and seen only by authorised staff.</span>'
        . field_error($name) . '</div>';
}

/** Remember the successful submission for the confirmation screen (PRG). */
function recruitment_set_success(string $slug, array $data): void
{
    $_SESSION['rc_success'][$slug] = $data + ['at' => time()];
}

function recruitment_take_success(string $slug): ?array
{
    $s = $_SESSION['rc_success'][$slug] ?? null;
    unset($_SESSION['rc_success'][$slug]);
    return ($s && time() - (int) $s['at'] < 1800) ? $s : null;
}

/** Confirmation screen shown after a successful submission (the banner keeps the page h1). */
function recruitment_success_html(array $s): string
{
    $first = trim((string) strtok((string) ($s['name'] ?? ''), ' '));
    $ref = (string) ($s['ref'] ?? '');
    $html = '<section class="section rc-success-section"><div class="container narrow"><div class="rc-success reveal" role="status">'
        . '<span class="result-icon success">' . icon('check-circle') . '</span>'
        . '<h2>' . e(($first !== '' ? 'Thank you, ' . $first . '!' : 'Thank you!') . ' ' . ($s['headline'] ?? 'Your application has been received.')) . '</h2>';
    if ($ref !== '') {
        $html .= '<div class="rc-ref"><small>Your reference number</small><strong>' . e($ref) . '</strong>'
            . '<button type="button" class="btn btn-ghost btn-sm" data-copy="' . e($ref) . '">' . icon('copy') . 'Copy</button></div>';
    }
    if (!empty($s['email'])) {
        $html .= '<p class="muted">We have sent an acknowledgement to <strong>' . e($s['email']) . '</strong>. If you do not see it, please check your spam folder.</p>';
    } elseif ($ref !== '') {
        $html .= '<p class="muted">Please write down this reference number - you will need it, with your mobile number, to check the status.</p>';
    }
    if (!empty($s['next'])) {
        $html .= '<div class="rc-next"><h3>What happens next</h3>' . ui_steps($s['next']) . '</div>';
    }
    $buttons = [];
    if ($ref !== '') {
        $buttons[] = ['label' => 'Check application status', 'url' => '/application-status/?ref=' . rawurlencode($ref), 'style' => 'primary', 'icon' => 'search'];
    }
    if (is_logged_in()) {
        $buttons[] = ['label' => 'Go to my dashboard', 'url' => '/dashboard/', 'style' => 'outline', 'icon' => 'grid'];
    }
    $buttons[] = ['label' => 'Explore our programmes', 'url' => '/programs/', 'style' => 'ghost', 'icon' => 'arrow-right'];
    $html .= ui_buttons($buttons, 'btn-row center') . '</div></div></section>';
    return $html;
}

/**
 * Finish a public submission: reference number, history, acknowledgement email, admin notification,
 * conversion, rate-limit log, confirmation screen. Redirects (never returns).
 * $p: kind, id, prefix, table, ref_col, name, email, label (e.g. "Volunteer application"), conversion, form (rate-limit key),
 *     slug, admin_html, admin_subject, next (steps)
 */
function recruitment_finish_submission(array $p): void
{
    $ref = reference_no($p['prefix'], (int) $p['id']);
    db_update($p['table'], [$p['ref_col'] ?? 'application_no' => $ref], 'id = :id', ['id' => $p['id']]);
    record_application_history($p['kind'], (int) $p['id'], $p['status'] ?? 'submitted', $p['history_note'] ?? 'Submitted online.', true);
    log_activity('form_' . $p['form'], $p['label'] . ' ' . $ref, current_user_id());
    track_conversion($p['conversion'], (int) $p['id']);
    if (!empty($p['email'])) {
        send_application_ack($p['email'], $p['name'], $p['label'], $ref);
    }
    $k = recruitment_kinds()[$p['kind']] ?? null;
    $adminLink = $k ? '<p><a href="' . e(abs_url('admin/' . $k['admin'] . (int) $p['id'])) . '">Review ' . e($ref) . ' in the admin panel</a></p>' : '';
    notify_admin(($p['admin_subject'] ?? ('New ' . strtolower($p['label']))) . ' ' . $ref, ($p['admin_html'] ?? '') . $adminLink, !empty($p['email']) ? $p['email'] : null);
    if (current_user_id()) {
        add_notification((int) current_user_id(), $p['label'] . ' received', 'Thank you. Your ' . strtolower($p['label']) . ' ' . $ref . ' has been received. We will keep you updated here.', '/application-status/?ref=' . rawurlencode($ref), 'success');
    }
    recruitment_set_success($p['slug'], ['ref' => $ref, 'name' => $p['name'], 'email' => $p['email'] ?? '', 'next' => $p['next'] ?? [], 'headline' => $p['headline'] ?? null]);
    redirect('/' . $p['slug'] . '/');
}

/** Store an upload (or collect its error). */
function recruitment_upload(string $field, string $kind, string $folder, bool $required, array &$errors): ?string
{
    if ($errors) {
        return null; // validate the fields first so a re-upload is needed only once
    }
    $up = handle_upload($field, $kind, $folder, $required);
    if (!empty($up['error'])) {
        $errors[$field] = $up['error'];
        return null;
    }
    return $up['path'] ?? null;
}

/** Common pre-checks for a public application POST: CSRF, honeypot, rate limit. */
function recruitment_guard_post(string $slug, string $form, int $max = 5): void
{
    csrf_verify(page_url($slug));
    if (honeypot_triggered()) {
        flash('success', 'Thank you. Your submission has been received.');
        redirect('/' . $slug . '/');
    }
    if (form_rate_limited($form, $max)) {
        flash('error', 'Several applications were sent from your network in the last hour. Please try again later or write to ' . setting('contact_email', 'us') . '.');
        redirect('/' . $slug . '/#apply');
    }
}

/** Aside shown next to application forms. */
function recruitment_apply_aside(array $steps, string $extraHtml = '', ?array $links = null): string
{
    $links = $links ?? [
        'Volunteer Policy' => page_url('volunteer-policy'), 'Child Protection Policy' => page_url('child-protection-policy'),
        'Privacy Policy' => page_url('privacy-policy'), 'Check application status' => page_url('application-status'),
    ];
    $html = '<aside class="rc-apply-aside">';
    if ($steps) {
        $html .= '<div class="aside-card"><h3>' . icon('list') . ' How it works</h3><ol class="rc-mini-steps">';
        foreach ($steps as $i => $st) {
            $html .= '<li><span>' . ($i + 1) . '</span><div><strong>' . e($st['title']) . '</strong>' . (!empty($st['text']) ? '<small>' . e($st['text']) . '</small>' : '') . '</div></li>';
        }
        $html .= '</ol></div>';
    }
    $html .= $extraHtml;
    if ($links) {
        $html .= '<div class="aside-card"><h3>' . icon('file-text') . ' Good to know</h3><ul class="link-list">';
        foreach ($links as $label => $href) {
            $html .= '<li><a href="' . e($href) . '">' . icon('chevron-right') . e($label) . '</a></li>';
        }
        $html .= '</ul></div>';
    }
    if (setting('contact_email') !== '') {
        $html .= '<div class="aside-card rc-aside-help"><h3>' . icon('help-circle') . ' Need help?</h3><p>Write to us and we will be happy to guide you.</p>'
            . '<a class="btn btn-outline btn-sm btn-block" href="mailto:' . e(setting('contact_email')) . '">' . icon('mail') . e(setting('contact_email')) . '</a></div>';
    }
    return $html . '</aside>';
}

// ---------------------------------------------------------------------
// Section types (CMS): apply_cards, status_lookup
// ---------------------------------------------------------------------

function section_schema_apply_cards(): array
{
    return [
        'label' => 'Apply / get involved cards',
        'fields' => ['pill', 'title', 'intro'],
        'items' => ['icon' => ['icon', 'Icon'], 'title' => ['text', 'Title'], 'text' => ['textarea', 'Short text'], 'button' => ['text', 'Button text'], 'url' => ['url', 'Link']],
        'settings' => [
            'hide_current' => ['checkbox', 'Hide the card for the page being viewed'],
            'cols' => ['select', 'Columns', ['' => 'Auto', '2' => '2', '3' => '3', '4' => '4', '5' => '5']],
        ],
        'help' => 'Leave the items empty to show the standard cards: Volunteer, Volunteer Teacher, Staff / Job, Internship and Membership.',
    ];
}

/** Default "ways to join" cards. */
function recruitment_default_cards(): array
{
    return [
        ['icon' => 'heart', 'title' => 'Volunteer', 'text' => 'Support community drives, awareness programmes, events, relief work and campaigns - on weekends or whenever you are free.', 'button' => 'Apply as volunteer', 'url' => '/become-a-volunteer/'],
        ['icon' => 'book', 'title' => 'Volunteer Teacher', 'text' => 'Teach children and adult learners - school subjects, spoken English, computers or life skills, in person or online.', 'button' => 'Apply to teach', 'url' => '/volunteer-teacher/'],
        ['icon' => 'briefcase', 'title' => 'Staff / Job', 'text' => 'Work with our team on programmes, field coordination, communication and administration. No fee is ever charged to apply.', 'button' => 'View openings', 'url' => '/volunteer-staff/'],
        ['icon' => 'award', 'title' => 'Internship', 'text' => 'Students and young graduates can learn by doing in education, health, environment, communication and research.', 'button' => 'Apply for internship', 'url' => '/internships/'],
        ['icon' => 'id-card', 'title' => 'Membership', 'text' => 'Become a member, stay closely connected with our work and help shape the foundation\'s activities.', 'button' => 'Become a member', 'url' => '/membership/'],
    ];
}

function section_apply_cards(array $s, array $items, array $set, array $page): string
{
    $cards = [];
    foreach ($items as $it) {
        if (is_array($it) && !empty($it['title']) && !empty($it['url'])) {
            $cards[] = $it;
        }
    }
    if (!$cards) {
        foreach (recruitment_default_cards() as $c) {
            $slug = trim($c['url'], '/');
            if (get_page($slug)) {
                $cards[] = $c;
            }
        }
    }
    if (!empty($set['hide_current']) && !empty($page['slug'])) {
        $cards = array_values(array_filter($cards, function ($c) use ($page) {
            return trim((string) parse_url((string) $c['url'], PHP_URL_PATH), '/') !== $page['slug'];
        }));
    }
    if (!$cards) {
        return '';
    }
    $cols = (int) ($set['cols'] ?? 0);
    if ($cols < 2 || $cols > 5) {
        $cols = count($cards) >= 5 ? 5 : max(2, count($cards));
    }
    $tones = ['green', 'blue', 'gold', 'orange', 'teal'];
    $html = '<div class="rc-apply-grid rc-cols-' . $cols . '">';
    foreach ($cards as $i => $c) {
        $href = safe_link((string) $c['url']);
        $html .= '<article class="rc-apply-card tone-' . $tones[$i % 5] . ' reveal tilt" style="--d:' . ($i % 5) . '">'
            . '<span class="work-icon">' . icon($c['icon'] ?? 'heart') . '</span>'
            . '<h3>' . e($c['title']) . '</h3>'
            . (!empty($c['text']) ? '<p>' . e($c['text']) . '</p>' : '')
            . '<a class="btn btn-outline btn-sm" href="' . e($href) . '">' . e($c['button'] ?? 'Apply now') . icon('arrow-right') . '</a>'
            . '</article>';
    }
    return $html . '</div>';
}

function section_schema_status_lookup(): array
{
    return [
        'label' => 'Application status lookup (compact form)',
        'fields' => ['pill', 'title', 'intro'],
        'settings' => ['button' => ['text', 'Button text (default: Check status)']],
        'help' => 'A small form where applicants enter their reference number and email / mobile. Results open on the Application Status page.',
    ];
}

function section_status_lookup(array $s, array $items, array $set, array $page): string
{
    if (!get_page('application-status')) {
        return '';
    }
    $sid = (int) ($s['id'] ?? 0);
    $prefix = ['request-help' => 'HLP', 'scholarship' => 'SCH', 'volunteer-teacher' => 'TCH', 'volunteer-staff' => 'STF', 'internships' => 'INT'][$page['slug'] ?? ''] ?? 'VOL';
    return '<form class="rc-lookup reveal" method="post" action="' . e(page_url('application-status')) . '">' . csrf_field() . honeypot_field()
        . '<div class="rc-lookup-field"><label for="sl-ref-' . $sid . '">Reference number</label>'
        . '<input id="sl-ref-' . $sid . '" type="text" name="ref" required maxlength="40" placeholder="PWF-' . $prefix . '-' . date('Y') . '-00012" autocomplete="off" data-uppercase></div>'
        . '<div class="rc-lookup-field"><label for="sl-contact-' . $sid . '">Email or mobile used</label>'
        . '<input id="sl-contact-' . $sid . '" type="text" name="contact" required maxlength="190" autocomplete="email"></div>'
        . '<button class="btn btn-primary" type="submit">' . icon('search') . e($set['button'] ?? 'Check status') . '</button></form>';
}
