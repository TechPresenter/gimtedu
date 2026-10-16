<?php
/**
 * Accounts & member area (M5): helpers shared by the sign-in pages, the member dashboard
 * (/dashboard/<section>/) and the admin user / role / data-request pages.
 *
 * Every member query is scoped to the signed-in user's id. Exports never contain password hashes,
 * tokens, 2FA secrets, staff notes or other people's data.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

// ---------------------------------------------------------------------
// Dashboard structure
// ---------------------------------------------------------------------

/** Dashboard sections: slug => [icon, menu label, page title, short description]. */
function member_sections(): array
{
    return [
        'overview'      => ['grid', 'Overview', 'My Dashboard', 'Your donations, applications, events and certificates at a glance.'],
        'profile'       => ['user', 'Profile', 'My Profile', 'Your contact details, address and profile photo.'],
        'applications'  => ['layers', 'Applications', 'My Applications', 'Volunteer, teacher, staff, internship, scholarship, help and membership applications.'],
        'tasks'         => ['check-circle', 'Tasks', 'My Tasks', 'Tasks assigned to you by our coordinators.'],
        'events'        => ['calendar', 'Events', 'My Events', 'Events you have registered for.'],
        'donations'     => ['heart', 'Donations', 'My Donations', 'Every donation made with your account or verified email address.'],
        'receipts'      => ['file-text', 'Receipts', 'My Receipts', 'Receipts for your verified donations.'],
        'certificates'  => ['award', 'Certificates', 'My Certificates', 'Certificates issued to you by the foundation.'],
        'notifications' => ['bell', 'Notifications', 'Notifications', 'Updates about your account, applications and donations.'],
        'password'      => ['key', 'Password & Security', 'Password & Security', 'Change your password and sign out of other devices.'],
        'settings'      => ['settings', 'Privacy & Settings', 'Privacy & Settings', 'Communication preferences, your data and your account.'],
        'help'          => ['help-circle', 'Help', 'Help & Support', 'Answers, contacts and ways to report a problem.'],
    ];
}

/** Round avatar: uploaded photo or the first letter of the name. */
function member_avatar(array $user, string $class = ''): string
{
    $cls = trim('md-avatar ' . $class);
    if (!empty($user['avatar']) && strpos((string) $user['avatar'], 'uploads/avatars/') === 0 && is_file(ROOT_PATH . '/' . $user['avatar'])) {
        return '<span class="' . e($cls) . ' has-photo"><img src="' . e(url($user['avatar'])) . '" alt="" width="96" height="96"></span>';
    }
    $initial = mb_strtoupper(mb_substr(trim((string) ($user['name'] ?? '?')), 0, 1)) ?: '?';
    return '<span class="' . e($cls) . '" aria-hidden="true">' . e($initial) . '</span>';
}

/** Names of a user's roles, in display order. */
function member_role_names(int $userId): array
{
    return array_column(db_rows('SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.sort_order, r.name', [$userId]), 'name');
}

/** Status badge that also knows the recruitment / task / account statuses. */
function member_status_badge(?string $status): string
{
    $extra = [
        'submitted' => 'gold', 'info_requested' => 'gold', 'interview_scheduled' => 'blue', 'onboarding' => 'blue',
        'withdrawn' => 'gray', 'assigned' => 'gold', 'done' => 'green', 'proposal' => 'gold', 'deactivated' => 'gray',
        'issued' => 'green', 'unverified' => 'gold', 'verified' => 'green',
    ];
    if (isset($extra[(string) $status])) {
        return '<span class="badge badge-' . $extra[$status] . '">' . e(humanize($status)) . '</span>';
    }
    return status_badge($status);
}

/** a****@example.org */
function member_mask_email(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($domain === '') {
        return '***';
    }
    return mb_substr($local, 0, 1) . str_repeat('*', max(2, min(6, mb_strlen($local) - 1))) . '@' . $domain;
}

// ---------------------------------------------------------------------
// Donations owned by a member: user_id OR the member's *verified* email
// ---------------------------------------------------------------------

/** SQL condition (for table alias $alias) selecting the member's donations. Adds parameters to $params. */
function member_donation_scope(array $user, array &$params, string $alias = 'd'): string
{
    $params[] = (int) $user['id'];
    if (!empty($user['email_verified_at']) && !empty($user['email'])) {
        $params[] = mb_strtolower((string) $user['email']);
        return '(' . $alias . '.user_id = ? OR ' . $alias . '.donor_email = ?)';
    }
    return $alias . '.user_id = ?';
}

/** URL-safe form of a certificate number: "PWF/CERT/2026/00012" => "pwf-cert-2026-00012". */
function member_certificate_slug(string $certificateNo): string
{
    return trim(strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $certificateNo)), '-');
}

/** /dashboard/certificates/<certificate-slug>/ (and .../download/ for the uploaded PDF). */
function member_certificate_url(string $certificateNo, bool $download = false): string
{
    return member_url('certificates/' . member_certificate_slug($certificateNo) . ($download ? '/download' : ''));
}

/** An approved certificate of the user, looked up by its URL slug. */
function member_find_certificate(int $userId, string $slug): ?array
{
    foreach (db_rows("SELECT * FROM certificates WHERE user_id = ? AND status = 'approved'", [$userId]) as $c) {
        if ($slug !== '' && member_certificate_slug((string) $c['certificate_no']) === $slug) {
            return $c;
        }
    }
    return null;
}

/** Applicant-facing status badge (uses the recruitment module's wording when available). */
function member_application_badge(string $status, string $kind): string
{
    if (function_exists('recruitment_status_badge')) {
        return recruitment_status_badge($status, $kind, true);
    }
    return member_status_badge($status);
}

/** Short explanation of an application status for the applicant ('' when unknown). */
function member_application_status_text(string $status, string $kind): string
{
    if (function_exists('recruitment_status_info')) {
        return (string) (recruitment_status_info($status, $kind)[2] ?? '');
    }
    return '';
}

/** Label of a timeline entry. */
function member_history_label(string $status, string $kind): string
{
    if (function_exists('recruitment_history_label')) {
        return recruitment_history_label($status, $kind);
    }
    return humanize($status);
}

/** Receipt page for a donation (reference + private access token). */
function member_receipt_url(array $donation): string
{
    return page_url('donation-receipt', ['ref' => $donation['donation_ref'], 'token' => $donation['access_token']]);
}

// ---------------------------------------------------------------------
// Applications
// ---------------------------------------------------------------------

/** Application tables a member can see: type => settings. */
function member_application_sources(): array
{
    return [
        'volunteer'   => ['table' => 'volunteer_applications', 'label' => 'Volunteer application', 'icon' => 'heart', 'page' => 'become-a-volunteer', 'ref' => 'application_no', 'detail' => 'interests', 'history' => ['volunteer'], 'recruit' => true],
        'teacher'     => ['table' => 'teacher_applications', 'label' => 'Volunteer teacher application', 'icon' => 'book', 'page' => 'volunteer-teacher', 'ref' => 'application_no', 'detail' => 'subjects', 'history' => ['teacher'], 'recruit' => true],
        'staff'       => ['table' => 'staff_applications', 'label' => 'Staff application', 'icon' => 'briefcase', 'page' => 'volunteer-staff', 'ref' => 'application_no', 'detail' => 'position', 'history' => ['staff'], 'recruit' => true],
        'internship'  => ['table' => 'internship_applications', 'label' => 'Internship application', 'icon' => 'award', 'page' => 'internships', 'ref' => 'application_no', 'detail' => 'internship_area', 'history' => ['internship'], 'recruit' => true],
        'scholarship' => ['table' => 'scholarships', 'label' => 'Scholarship application', 'icon' => 'graduation', 'page' => 'scholarship', 'ref' => 'application_no', 'detail' => 'course_class', 'history' => ['scholarship'], 'recruit' => false],
        'beneficiary' => ['table' => 'beneficiaries', 'label' => 'Help request', 'icon' => 'life-buoy', 'page' => 'request-help', 'ref' => 'reference_no', 'detail' => 'help_category', 'history' => ['beneficiary', 'help', 'help_request'], 'recruit' => false],
        'membership'  => ['table' => 'members', 'label' => 'Membership application', 'icon' => 'id-card', 'page' => 'membership', 'ref' => 'membership_no', 'detail' => 'membership_type', 'history' => ['membership', 'member'], 'recruit' => false],
    ];
}

/** Status changes shared with the applicant (visible_to_applicant = 1), oldest first. */
function member_application_history(array $types, int $applicationId): array
{
    if (!$types) {
        return [];
    }
    $in = implode(',', array_fill(0, count($types), '?'));
    return db_rows("SELECT status, note, created_at FROM application_history
                     WHERE application_type IN ($in) AND application_id = ? AND visible_to_applicant = 1
                     ORDER BY created_at, id", array_merge($types, [$applicationId]));
}

/** All applications that belong to a user, newest first (normalised rows). */
function member_my_applications(int $userId): array
{
    $out = [];
    foreach (member_application_sources() as $type => $src) {
        $rows = db_rows('SELECT * FROM `' . $src['table'] . '` WHERE user_id = ? ORDER BY created_at DESC', [$userId]);
        foreach ($rows as $r) {
            $detail = (string) ($r[$src['detail']] ?? '');
            if ($type === 'beneficiary' && isset(HELP_CATEGORIES[$detail])) {
                $detail = HELP_CATEGORIES[$detail];
            } elseif ($type === 'membership' && $detail !== '') {
                $detail = humanize($detail) . ' membership';
            }
            $out[] = [
                'type'           => $type,
                'id'             => (int) $r['id'],
                'label'          => $src['label'],
                'icon'           => $src['icon'],
                'page'           => $src['page'],
                'ref'            => (string) ($r[$src['ref']] ?? ''),
                'status'         => (string) $r['status'],
                'detail'         => $detail,
                'created_at'     => (string) $r['created_at'],
                'updated_at'     => (string) ($r['updated_at'] ?? ''),
                'info_request'   => $src['recruit'] && $r['status'] === 'info_requested' ? (string) ($r['info_request'] ?? '') : '',
                'interview_at'   => $src['recruit'] && $r['status'] === 'interview_scheduled' ? (string) ($r['interview_at'] ?? '') : '',
                'interview_mode' => $src['recruit'] ? (string) ($r['interview_mode'] ?? '') : '',
                'valid_until'    => $type === 'membership' ? (string) ($r['valid_until'] ?? '') : '',
                'history'        => member_application_history($src['history'], (int) $r['id']),
            ];
        }
    }
    usort($out, function ($a, $b) {
        return strcmp($b['created_at'], $a['created_at']);
    });
    return $out;
}

// ---------------------------------------------------------------------
// Account e-mails
// ---------------------------------------------------------------------

/**
 * E-mail a password reset (or invitation) link. $hours > 1 extends the validity (used for staff invitations).
 * Returns whether the email was sent.
 */
function member_send_reset_link(array $user, int $hours = 1, bool $invitation = false): bool
{
    $token = create_password_reset((int) $user['id']);
    if ($hours > 1) {
        db_query('UPDATE password_resets SET expires_at = ? WHERE token_hash = ?', [date('Y-m-d H:i:s', time() + $hours * 3600), hash('sha256', $token)]);
    }
    $link = abs_url('reset-password/?token=' . $token);
    $valid = $hours === 1 ? '1 hour' : $hours . ' hours';
    if ($invitation) {
        $subject = 'Your ' . site_name() . ' staff account';
        $body = '<p>Dear ' . e($user['name']) . ',</p><p>An administrator has created a staff account for you on the ' . e(site_name()) . ' website.'
            . ' Please choose your own password to activate it:</p><p><a class="btn" href="' . e($link) . '">Choose my password</a></p>'
            . '<p>This link can be used once and is valid for ' . e($valid) . '. Afterwards, sign in at <a href="' . e(abs_url('admin/login.php')) . '">' . e(abs_url('admin/login.php')) . '</a>.</p>'
            . '<p>If you were not expecting this, please ignore this email or let us know.</p>';
    } else {
        $subject = 'Reset your password - ' . site_name();
        $body = '<p>Dear ' . e($user['name']) . ',</p><p>We received a request to reset the password for your ' . e(site_name()) . ' account.</p>'
            . '<p><a class="btn" href="' . e($link) . '">Choose a new password</a></p>'
            . '<p>This link can be used once and is valid for ' . e($valid) . '. If you did not ask for a new password, you can ignore this email - your password will not change.</p>';
    }
    return send_mail($user['email'], $subject, $body);
}

/**
 * Send a new verification e-mail to an unverified, active account (max 3 per hour per account).
 * Returns true when an e-mail was sent. Callers always show a generic message.
 */
function member_resend_verification(int $userId): bool
{
    $user = db_row("SELECT id, name, email, email_verified_at, status FROM users WHERE id = ?", [$userId]);
    if (!$user || $user['status'] !== 'active' || !empty($user['email_verified_at'])) {
        return false;
    }
    $recent = (int) db_value("SELECT COUNT(*) FROM activity_logs WHERE action = 'verification_resent' AND user_id = ? AND created_at > ?",
        [$userId, date('Y-m-d H:i:s', time() - 3600)]);
    if ($recent >= 3) {
        return false;
    }
    send_verification_email($user);
    log_activity('verification_resent', 'Verification email sent', $userId);
    return true;
}

// ---------------------------------------------------------------------
// Sign-in / registration page building blocks
// ---------------------------------------------------------------------

/** Left side of the split-screen sign-in pages: brand, mission, illustration and trust points. */
function account_side_panel(string $context = 'login'): string
{
    $title = setting('auth_panel_title', setting('site_tagline_en', 'Together, let us build a better tomorrow'));
    $text = setting('auth_panel_text', 'Sign in to track your donations and receipts, follow your applications and download your certificates.');
    $img = setting('auth_panel_image');
    $art = ($img !== '' && is_file(ROOT_PATH . '/' . $img)) ? $img : 'assets/images/hero/account.svg';
    $points = $context === 'register'
        ? [['heart', 'Receipts for every verified donation', 'Find all your receipts in one place.'],
           ['file-text', 'Follow your applications', 'Volunteer, teacher, internship and support requests.'],
           ['shield', 'Your data stays yours', 'Download it or ask us to delete it at any time.']]
        : [['lock', 'Secure sign-in', 'Passwords are stored as secure one-way hashes - nobody, including our staff, can read them.'],
           ['award', 'Certificates & events', 'Register for events and download your certificates.'],
           ['shield', 'Private by design', 'Only you and the authorised staff who handle them can see your donations and applications.']];
    $html = '<aside class="acc-panel"><span class="acc-orb acc-orb-1" aria-hidden="true"></span><span class="acc-orb acc-orb-2" aria-hidden="true"></span>'
        . '<a class="acc-brand" href="' . e(url('/')) . '" aria-label="' . e(site_name()) . ' - home">' . brand_lockup('full') . '</a>'
        . '<div class="acc-panel-body"><p class="acc-tagline hindi" lang="hi">' . e(setting('site_tagline', 'मिलकर बनाये बेहतर कल')) . '</p>'
        . '<p class="acc-panel-title">' . e($title) . '</p><p class="acc-panel-text">' . e($text) . '</p></div>'
        . '<div class="acc-art"><img src="' . e(url($art)) . '" alt="" width="480" height="360" loading="eager"></div><ul class="acc-trust">';
    foreach ($points as [$ic, $t, $s]) {
        $html .= '<li><span class="acc-trust-icon">' . icon($ic) . '</span><span><strong>' . e($t) . '</strong><small>' . e($s) . '</small></span></li>';
    }
    return $html . '</ul></aside>';
}

/**
 * Password input with a show/hide button (and an optional strength meter for new passwords).
 * $o: label, autocomplete, required (bool), meter (bool), match (id of the field to match), help
 */
function account_password_field(string $id, string $name, array $o = []): string
{
    $label = $o['label'] ?? 'Password';
    $min = (int) ($o['minlength'] ?? 8);   // 12 for staff accounts (STAFF_PASSWORD_MIN)
    $html = '<div class="form-group' . (!empty($o['full']) ? ' full' : '') . '"><label for="' . e($id) . '">' . e($label) . (!empty($o['required']) ? '<span class="req">*</span>' : '') . '</label>'
        . '<div class="acc-pw"><input id="' . e($id) . '" type="password" name="' . e($name) . '" maxlength="72"'
        . (!empty($o['required']) ? ' required' : '') . ' autocomplete="' . e($o['autocomplete'] ?? 'current-password') . '"'
        . (!empty($o['meter']) ? ' minlength="' . $min . '" data-strength="' . e($id) . '-meter"' : '')
        . (!empty($o['match']) ? ' data-match-with="' . e($o['match']) . '"' : '')
        . (!empty($o['describedby']) ? ' aria-describedby="' . e($o['describedby']) . '"' : '') . '>'
        . '<button type="button" class="acc-pw-toggle" data-pw-toggle="' . e($id) . '" aria-label="Show password" aria-pressed="false">' . icon('eye') . '</button></div>';
    if (!empty($o['meter'])) {
        $html .= '<div class="acc-meter" id="' . e($id) . '-meter" data-level="0" aria-live="polite"><span class="acc-meter-bar"><span></span></span><small class="acc-meter-text">' . $min . '+ characters with letters and numbers</small></div>';
    }
    if (!empty($o['match'])) {
        $html .= '<small class="acc-match" data-match-msg="' . e($id) . '" aria-live="polite"></small>';
    }
    if (!empty($o['help'])) {
        $html .= '<span class="help">' . e($o['help']) . '</span>';
    }
    return $html . field_error($name) . '</div>';
}

/** Session-scoped one-time message for the auth card: ['type' => error|success|info, 'text' => ...]. */
function account_notice(?array $set = null): ?array
{
    if ($set !== null) {
        $_SESSION['m5_notice'] = $set;
        return null;
    }
    $n = $_SESSION['m5_notice'] ?? null;
    unset($_SESSION['m5_notice']);
    return is_array($n) ? $n : null;
}

function account_notice_html(?array $n): string
{
    if (!$n) {
        return '';
    }
    $type = in_array($n['type'] ?? '', ['error', 'success', 'info', 'warning'], true) ? $n['type'] : 'info';
    $ic = ['error' => 'alert-circle', 'success' => 'check-circle', 'info' => 'info', 'warning' => 'alert-triangle'][$type];
    return '<div class="alert alert-' . $type . ' acc-alert" role="' . ($type === 'error' ? 'alert' : 'status') . '">' . icon($ic) . '<div>' . e($n['text'] ?? '') . '</div></div>';
}

// ---------------------------------------------------------------------
// Personal data: export & anonymisation
// ---------------------------------------------------------------------

/** Columns never included in a personal-data export (secrets, staff notes, other people's ids, file paths). */
const MEMBER_EXPORT_DROP = [
    'password_hash', 'totp_secret', 'session_version', 'access_token', 'gateway_signature', 'gateway_session_id', 'unsubscribe_token', 'token_hash',
    'access_token_hash', 'admin_note',
    'validator_hash', 'selector', 'admin_notes', 'review_notes', 'ip_address', 'last_login_ip', 'user_agent', 'reviewed_by',
    'coordinator_id', 'verified_by', 'reconciled_by', 'approved_by', 'issued_by', 'handled_by', 'assigned_by', 'created_by',
    'updated_by', 'resume_path', 'document_path', 'file_path', 'must_change_password',
];

function member_export_clean(array $rows): array
{
    return array_map(function ($r) {
        foreach (MEMBER_EXPORT_DROP as $col) {
            unset($r[$col]);
        }
        return $r;
    }, $rows);
}

/** Everything the foundation holds about one user (their own records only). */
function member_export_data(int $userId): array
{
    $user = db_row('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$user) {
        return [];
    }
    $account = member_export_clean([$user])[0];
    $account['totp_enabled'] = (int) $user['totp_enabled'];

    $applications = [];
    foreach (member_application_sources() as $type => $src) {
        $rows = member_export_clean(db_rows('SELECT * FROM `' . $src['table'] . '` WHERE user_id = ? ORDER BY id', [$userId]));
        foreach ($rows as &$r) {
            $r['status_history'] = member_application_history($src['history'], (int) $r['id']);
        }
        unset($r);
        if ($rows) {
            $applications[$type] = $rows;
        }
    }

    $params = [];
    $scope = member_donation_scope($user, $params);
    $donations = member_export_clean(db_rows("SELECT d.*, c.title AS campaign_title, r.receipt_no, r.financial_year, r.issued_at AS receipt_issued_at, r.status AS receipt_status
        FROM donations d LEFT JOIN campaigns c ON c.id = d.campaign_id LEFT JOIN donation_receipts r ON r.donation_id = d.id
        WHERE $scope ORDER BY d.id", $params));

    $newsletter = db_row('SELECT email, status, consent_text, consented_at, source, created_at, updated_at FROM newsletter_subscribers WHERE email = ?', [$user['email']]);

    return [
        'export'                => ['generated_at' => date('c'), 'organisation' => site_name(), 'user_id' => (int) $userId,
                                    'note' => 'Personal data held about you by ' . site_name() . '. Passwords, security tokens and internal staff notes are not included.'],
        'account'               => $account,
        'roles'                 => member_role_names($userId),
        'applications'          => $applications,
        'fundraiser_proposals'  => member_export_clean(db_rows('SELECT id, title, slug, category, short_description, goal_amount, location, start_date, end_date, status, organizer_name, organizer_email, organizer_phone, proposal_notes, created_at FROM campaigns WHERE submitted_by = ? ORDER BY id', [$userId])),
        'donations'             => $donations,
        // own registrations and those made with the verified account email (as shown under My events); never the meeting link
        'event_registrations'   => member_export_clean(db_rows('SELECT er.*, e.title AS event_title, e.start_datetime AS event_start, e.location AS event_location FROM event_registrations er JOIN events e ON e.id = er.event_id WHERE er.user_id = ?' . (!empty($user['email_verified_at']) ? ' OR er.email = ?' : '') . ' ORDER BY er.id', !empty($user['email_verified_at']) ? [$userId, mb_strtolower((string) $user['email'])] : [$userId])),
        'certificates'          => member_export_clean(db_rows("SELECT * FROM certificates WHERE user_id = ? AND status = 'approved' ORDER BY id", [$userId])),
        'tasks'                 => member_export_clean(db_rows('SELECT * FROM tasks WHERE user_id = ? ORDER BY id', [$userId])),
        'notifications'         => db_rows('SELECT title, message, type, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY id', [$userId]),
        'website_visits'        => function_exists('analytics_user_export') ? analytics_user_export($userId) : [],
        'newsletter'            => $newsletter ?: null,
        'contact_enquiries'     => member_export_clean(db_rows('SELECT * FROM contact_enquiries WHERE user_id = ? ORDER BY id', [$userId])),
        'feedback'              => member_export_clean(db_rows('SELECT * FROM feedback WHERE user_id = ? ORDER BY id', [$userId])),
        'data_requests'         => member_export_clean(db_rows('SELECT id, email, request_type, details, status, response, completed_at, created_at FROM data_requests WHERE user_id = ? ORDER BY id', [$userId])),
        'account_activity'      => db_rows('SELECT action, created_at FROM activity_logs WHERE user_id = ? ORDER BY id DESC LIMIT 200', [$userId]),
    ];
}

/** Send a JSON download of the export and stop. */
function member_send_export(int $userId, string $filename): void
{
    $data = member_export_data($userId);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._\-]/', '_', $filename) . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Records that stay linked to an account after anonymisation (shown to staff before deleting). */
function member_retained_summary(int $userId): array
{
    $user = db_row('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$user) {
        return [];
    }
    $params = [];
    $scope = member_donation_scope($user, $params);
    $apps = 0;
    foreach (member_application_sources() as $src) {
        $apps += (int) db_value('SELECT COUNT(*) FROM `' . $src['table'] . '` WHERE user_id = ?', [$userId]);
    }
    return [
        'donations'    => (int) db_value("SELECT COUNT(*) FROM donations d WHERE $scope", $params),
        'receipts'     => (int) db_value("SELECT COUNT(*) FROM donation_receipts r JOIN donations d ON d.id = r.donation_id WHERE $scope", $params),
        'applications' => $apps,
        'events'       => (int) db_value('SELECT COUNT(*) FROM event_registrations WHERE user_id = ?', [$userId]),
        'certificates' => (int) db_value('SELECT COUNT(*) FROM certificates WHERE user_id = ?', [$userId]),
    ];
}

/**
 * Anonymise an account for a completed deletion request. Donation and receipt records are kept
 * (needed for the foundation's accounts and tax records); the account itself can no longer be used.
 */
function member_anonymise_account(int $userId): bool
{
    $user = db_row('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$user) {
        return false;
    }
    if (!empty($user['avatar']) && strpos((string) $user['avatar'], 'uploads/avatars/') === 0) {
        delete_upload($user['avatar']);
    }
    db_query('DELETE FROM newsletter_subscribers WHERE email = ?', [$user['email']]);
    db_update('users', [
        'name' => 'Deleted user', 'email' => 'deleted-' . $userId . '@invalid.local', 'mobile' => null, 'address' => null,
        'city' => null, 'state' => null, 'pincode' => null, 'avatar' => null, 'status' => 'deactivated',
        'communication_consent' => 0, 'totp_secret' => null, 'totp_enabled' => 0, 'must_change_password' => 0,
        'last_login_ip' => null, 'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
    ], 'id = :id', ['id' => $userId]);
    db_query('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
    logout_everywhere($userId);
    db_query('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
    db_query('DELETE FROM email_verifications WHERE user_id = ?', [$userId]);
    db_query('DELETE FROM notifications WHERE user_id = ?', [$userId]);
    db_query('UPDATE members SET gender = NULL, date_of_birth = NULL, occupation = NULL, address = NULL, city = NULL, state = NULL, pincode = NULL, areas_of_interest = NULL, motivation = NULL WHERE user_id = ?', [$userId]);
    if (function_exists('analytics_forget_user')) {
        analytics_forget_user($userId);   // website visits are no longer linked to the account
    }
    return true;
}

/** Text used on screen and in the response e-mail when an account is anonymised. */
function member_retention_note(): string
{
    return 'Your account and profile details have been anonymised and the account can no longer be used. '
        . 'Records of donations and their receipts are kept, because the foundation is required to retain them for its accounts and tax records; '
        . 'they are used only for that purpose.';
}
