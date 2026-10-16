<?php
/**
 * Member dashboard: /dashboard/ and /dashboard/<section>/ (see member_sections()).
 * /dashboard/certificates/<certificate-no>/ shows a printable certificate, .../download/ the uploaded PDF.
 *
 * Every query is limited to the signed-in user (user_id = current user; donations also by the user's
 * verified email address). Forms post back to their own section URL (CSRF + PRG).
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$sections = member_sections();
$segments = route_segments();
$section = $segments[0] ?? 'overview';
if ($section === 'overview' && $segments) {
    redirect('/dashboard/');
}
if (!isset($sections[$section])) {
    not_found();
}
$certNo = '';
$certDownload = false;
if ($section === 'certificates' && count($segments) > 1) {
    $certNo = $segments[1];
    if (count($segments) === 3 && $segments[2] === 'download') {
        $certDownload = true;
    } elseif (count($segments) > 2) {
        not_found();
    }
} elseif (count($segments) > 1) {
    not_found();
}

$user = require_login();
$uid = (int) $user['id'];
$base = '/dashboard/' . ($section === 'overview' ? '' : $section . '/');

if (!empty($user['must_change_password']) && $section !== 'password') {
    flash('warning', 'For your security, please choose a new password before continuing.');
    redirect('/dashboard/password/');
}

// ---------------------------------------------------------------------
// Actions (POST -> redirect)
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify($base);
    $action = post_str('action');

    switch ($section) {
        case 'overview':
            if ($action === 'resend_verification') {
                if (!form_rate_limited('resend_verification', 5)) {
                    member_resend_verification($uid);
                }
                log_activity('form_resend_verification', 'Verification resend requested', $uid);
                flash('success', 'If your email address still needs to be verified, a new link is on its way to ' . $user['email'] . '.');
            }
            break;

        case 'profile':
            if ($action === 'profile') {
                [$data, $errors] = validate($_POST, [
                    'name' => 'required|min:2|max:120', 'mobile' => 'phone', 'address' => 'max:255', 'city' => 'max:80',
                    'state' => 'in:' . implode(',', indian_states()), 'pincode' => 'pincode', 'remove_avatar' => 'in:1',
                ], ['mobile' => 'Mobile number', 'pincode' => 'PIN code']);
                $mobile = $data['mobile'] !== '' ? normalize_mobile($data['mobile']) : null;
                if ($mobile !== null && !isset($errors['mobile']) && (strlen($mobile) < 8 || strlen($mobile) > 15)) {
                    $errors['mobile'] = 'Please enter a valid mobile number.';
                }
                // A changed mobile number is throttled (per IP and per account) and a clash is reported generically,
                // so the form cannot be used to find out which numbers have accounts.
                $mobileChanged = $mobile !== null && !isset($errors['mobile']) && $mobile !== (string) ($user['mobile'] ?? '');
                $mobileTaken = 'This mobile number cannot be used for your account. Please use a different number or contact us.';
                if ($mobileChanged) {
                    $limited = form_rate_limited('mobile_change', 5)
                        || (int) db_value("SELECT COUNT(*) FROM activity_logs WHERE action = 'form_mobile_change' AND user_id = ? AND created_at > ?", [$uid, date('Y-m-d H:i:s', time() - 3600)]) >= 5;
                    log_activity('form_mobile_change', 'Mobile number change attempted', $uid);
                    if ($limited) {
                        $errors['mobile'] = 'Too many attempts. Please try again in an hour.';
                    } elseif (db_value('SELECT COUNT(*) FROM users WHERE mobile = ? AND id <> ?', [$mobile, $uid])) {
                        $errors['mobile'] = $mobileTaken;
                    }
                }
                $up = handle_upload('avatar', 'image', 'avatars');
                if (!empty($up['error'])) {
                    $errors['avatar'] = $up['error'];
                }
                if ($errors) {
                    if (!empty($up['path'])) {
                        delete_upload($up['path']);
                    }
                    back_with_errors($errors, $base, $_POST);
                }
                $avatar = $user['avatar'] ?? null;
                $oldAvatar = (string) $avatar;
                if (!empty($up['path'])) {
                    $avatar = $up['path'];
                } elseif ($data['remove_avatar'] === '1') {
                    $avatar = null;
                }
                try {
                    db_update('users', [
                        'name' => $data['name'], 'mobile' => $mobile, 'address' => $data['address'] !== '' ? $data['address'] : null,
                        'city' => $data['city'] !== '' ? $data['city'] : null, 'state' => $data['state'] !== '' ? $data['state'] : null,
                        'pincode' => $data['pincode'] !== '' ? $data['pincode'] : null, 'avatar' => $avatar,
                    ], 'id = :id', ['id' => $uid]);
                } catch (PDOException $ex) {
                    if (!empty($up['path'])) {
                        delete_upload($up['path']);
                    }
                    back_with_errors(['mobile' => $mobileTaken], $base, $_POST);
                }
                if ($oldAvatar !== '' && $oldAvatar !== (string) $avatar && strpos($oldAvatar, 'uploads/avatars/') === 0) {
                    delete_upload($oldAvatar);
                }
                log_activity('profile_updated', 'Updated own profile', $uid);
                flash('success', 'Your profile has been saved.');
            } elseif ($action === 'email') {
                $new = mb_strtolower(trim(post_str('new_email')));
                $confirm = mb_strtolower(trim(post_str('new_email_confirm')));
                $errors = [];
                $limited = form_rate_limited('email_change', 5);
                log_activity('form_email_change', 'Email change requested', $uid);
                if ($limited) {
                    $errors['new_email'] = 'Too many attempts. Please try again in an hour.';
                } elseif (!filter_var($new, FILTER_VALIDATE_EMAIL) || strlen($new) > 190) {
                    $errors['new_email'] = 'Please enter a valid email address.';
                } elseif ($new !== $confirm) {
                    $errors['new_email_confirm'] = 'The two email addresses do not match.';
                } elseif (!password_verify(post_str('current_password'), (string) db_value('SELECT password_hash FROM users WHERE id = ?', [$uid]))) {
                    $errors['current_password'] = 'Your current password is not correct.';
                } elseif ($new === mb_strtolower((string) $user['email'])) {
                    $errors['new_email'] = 'This is already your email address.';
                } elseif (db_value('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [$new, $uid])) {
                    $errors['new_email'] = 'This email address cannot be used for your account. Please use a different address or contact us.';
                }
                if ($errors) {
                    back_with_errors($errors, $base, ['new_email' => $new, 'new_email_confirm' => $confirm]);
                }
                $oldEmail = (string) $user['email'];
                try {
                    db_update('users', ['email' => $new, 'email_verified_at' => null], 'id = :id', ['id' => $uid]);
                } catch (PDOException $ex) {
                    back_with_errors(['new_email' => 'This email address cannot be used for your account. Please use a different address or contact us.'], $base);
                }
                invalidate_password_resets($uid);   // links sent to the old address stop working
                send_verification_email(db_row('SELECT id, name, email FROM users WHERE id = ?', [$uid]));
                send_mail($oldEmail, 'Your account email address was changed - ' . site_name(),
                    '<p>Dear ' . e($user['name']) . ',</p><p>The email address of your ' . e(site_name()) . ' account was changed to <strong>' . e(member_mask_email($new)) . '</strong> on ' . e(date('d M Y, h:i A')) . '.</p>'
                    . '<p>If you did not make this change, please <a href="' . e(abs_url('contact/')) . '">contact us</a> immediately.</p>');
                add_notification($uid, 'Email address changed', 'Your account email was changed to ' . $new . '. Please verify the new address using the link we sent.', '/dashboard/profile/', 'security');
                log_activity('email_changed', 'Account email changed', $uid);
                flash('success', 'Your email address has been changed. Please open the verification link we sent to ' . $new . '.');
            }
            break;

        case 'tasks':
            if ($action === 'task') {
                $taskId = (int) ($_POST['task_id'] ?? 0);
                $to = post_str('status');
                $task = in_array($to, ['in_progress', 'done'], true)
                    ? db_row("SELECT * FROM tasks WHERE id = ? AND user_id = ? AND status IN ('assigned', 'in_progress')", [$taskId, $uid]) : null;
                if ($task && !($to === 'in_progress' && $task['status'] === 'in_progress')) {
                    db_update('tasks', ['status' => $to, 'completed_at' => $to === 'done' ? date('Y-m-d H:i:s') : null], 'id = :id AND user_id = :uid', ['id' => $taskId, 'uid' => $uid]);
                    log_activity('task_' . $to, 'Task #' . $taskId . ' marked ' . str_replace('_', ' ', $to), $uid);
                    if (!empty($task['assigned_by'])) {
                        add_notification((int) $task['assigned_by'], 'Task update: ' . $task['title'], $user['name'] . ' marked this task as ' . str_replace('_', ' ', $to) . '.', null, 'info');
                    }
                    flash('success', $to === 'done' ? 'Well done! The task is marked as done.' : 'The task is now in progress.');
                } else {
                    flash('error', 'This task can no longer be updated.');
                }
            }
            break;

        case 'events':
            if ($action === 'cancel') {
                // own registration (or one made with the verified account email); a freed seat goes to the waiting list
                $reg = programhub_member_cancel($user, (int) ($_POST['registration_id'] ?? 0));
                if ($reg) {
                    log_activity('event_registration_cancelled', 'Cancelled registration #' . $reg['id'], $uid);
                    flash('success', 'Your registration for "' . $reg['event_title'] . '" has been cancelled.');
                } else {
                    flash('error', 'This registration can no longer be cancelled.');
                }
            }
            break;

        case 'notifications':
            if ($action === 'read') {
                db_query('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL', [(int) ($_POST['id'] ?? 0), $uid]);
            } elseif ($action === 'read_all') {
                db_query('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$uid]);
                flash('success', 'All notifications are marked as read.');
            }
            $back = (int) ($_POST['page'] ?? 1);
            redirect($base . ($back > 1 ? '?page=' . $back : ''));
            break;

        case 'password':
            if ($action === 'password') {
                $hash = (string) db_value('SELECT password_hash FROM users WHERE id = ?', [$uid]);
                $current = post_str('current_password');
                $new = post_str('password');
                $errors = [];
                if (form_rate_limited('password_change', 10)) {
                    $errors['current_password'] = 'Too many attempts. Please try again in an hour.';
                } elseif (!password_verify($current, $hash)) {
                    $errors['current_password'] = 'Your current password is not correct.';
                } elseif ($problem = password_problem($new, (string) $user['name'], (string) $user['email'], $uid)) {
                    $errors['password'] = $problem;   // includes the 72-byte limit and the 12-character staff minimum
                } elseif ($new !== post_str('password_confirm')) {
                    $errors['password_confirm'] = 'The two passwords do not match.';
                } elseif (password_verify($new, $hash)) {
                    $errors['password'] = 'Please choose a password that is different from your current one.';
                }
                log_activity('form_password_change', 'Password change form', $uid);
                if ($errors) {
                    back_with_errors($errors, $base);
                }
                set_user_password($uid, $new);
                if (!empty($_COOKIE[REMEMBER_COOKIE])) {
                    issue_remember_cookie($uid); // keep this device remembered; all other devices are signed out
                }
                flash('success', 'Your password has been changed. You have been signed out on all other devices.');
            } elseif ($action === 'logout_others') {
                logout_everywhere($uid);
                if (!empty($_COOKIE[REMEMBER_COOKIE])) {
                    issue_remember_cookie($uid);
                }
                log_activity('logout_everywhere', 'Signed out of all other devices', $uid);
                add_notification($uid, 'Signed out of other devices', 'You signed out of all other browsers and devices on ' . date('d M Y, h:i A') . '.', null, 'security');
                flash('success', 'You have been signed out on all other devices.');
            }
            break;

        case 'settings':
            if ($action === 'consent') {
                $consent = !empty($_POST['communication_consent']) ? 1 : 0;
                db_update('users', ['communication_consent' => $consent], 'id = :id', ['id' => $uid]);
                log_activity('consent_updated', $consent ? 'Communication consent given' : 'Communication consent withdrawn', $uid);
                flash('success', $consent ? 'Thank you - we will keep you informed by email.' : 'Done - we will only email you about your account, donations and applications.');
            } elseif ($action === 'newsletter') {
                $email = mb_strtolower((string) $user['email']);
                $row = db_row('SELECT * FROM newsletter_subscribers WHERE email = ?', [$email]);
                if (($_POST['subscribe'] ?? '') === '1') {
                    if (empty($user['email_verified_at'])) {
                        flash('error', 'Please verify your email address before subscribing to the newsletter.');
                        break;
                    }
                    if (empty($_POST['newsletter_consent'])) {
                        flash('error', 'Please tick the consent box to subscribe.');
                        break;
                    }
                    $consentText = mb_substr(setting('newsletter_consent_text', 'I agree to receive occasional email updates. I can unsubscribe at any time.'), 0, 255);
                    $fields = ['name' => $user['name'], 'status' => 'subscribed', 'consent_text' => $consentText, 'consented_at' => date('Y-m-d H:i:s'), 'source' => 'dashboard', 'ip_address' => client_ip()];
                    if ($row) {
                        db_update('newsletter_subscribers', $fields, 'id = :id', ['id' => $row['id']]);
                        $subId = (int) $row['id'];
                    } else {
                        $subId = db_insert('newsletter_subscribers', $fields + ['email' => $email, 'unsubscribe_token' => bin2hex(random_bytes(32))]);
                    }
                    if (!$row || $row['status'] !== 'subscribed') {
                        track_conversion('newsletter_signup', $subId);
                    }
                    log_activity('newsletter_subscribed', 'Subscribed to the newsletter from the dashboard', $uid);
                    flash('success', 'You are subscribed to our newsletter.');
                } else {
                    if ($row && $row['status'] === 'subscribed') {
                        db_update('newsletter_subscribers', ['status' => 'unsubscribed'], 'id = :id', ['id' => $row['id']]);
                    }
                    log_activity('newsletter_unsubscribed', 'Unsubscribed from the newsletter from the dashboard', $uid);
                    flash('success', 'You have been unsubscribed from the newsletter.');
                }
            } elseif ($action === 'export') {
                log_activity('data_export', 'Downloaded own personal data', $uid);
                member_send_export($uid, 'my-data-' . date('Y-m-d') . '.json');
            } elseif ($action === 'data_request') {
                [$data, $errors] = validate($_POST, ['request_type' => 'required|in:deletion,correction', 'details' => 'text|max:2000'], ['request_type' => 'Request type']);
                if (!$errors && $data['request_type'] === 'correction' && $data['details'] === '') {
                    $errors['details'] = 'Please tell us which details should be corrected.';
                }
                if ($errors) {
                    back_with_errors($errors, $base, $_POST);
                }
                if (db_value("SELECT COUNT(*) FROM data_requests WHERE user_id = ? AND request_type = ? AND status IN ('pending', 'in_progress')", [$uid, $data['request_type']])) {
                    flash('info', 'You already have an open ' . $data['request_type'] . ' request. We will respond to it soon.');
                    break;
                }
                if (form_rate_limited('data_request', 5)) {
                    flash('error', 'Too many requests. Please try again later.');
                    break;
                }
                log_activity('form_data_request', 'Data request form', $uid);
                $reqId = db_insert('data_requests', [
                    'user_id' => $uid, 'email' => $user['email'], 'request_type' => $data['request_type'],
                    'details' => $data['details'] !== '' ? $data['details'] : null, 'status' => 'pending',
                ]);
                log_activity('data_request_created', 'Data ' . $data['request_type'] . ' request #' . $reqId, $uid);
                notify_admin('New data ' . $data['request_type'] . ' request #' . $reqId,
                    '<p>A registered user has made a personal data <strong>' . e($data['request_type']) . '</strong> request (#' . (int) $reqId . ').</p>'
                    . ($data['details'] !== '' ? '<p>' . e_nl($data['details']) . '</p>' : '')
                    . '<p>Review it in Admin &gt; People &gt; Data Requests.</p>');
                send_mail($user['email'], 'We have received your request - ' . site_name(),
                    '<p>Dear ' . e($user['name']) . ',</p><p>We have received your request for <strong>' . e($data['request_type'] === 'deletion' ? 'deletion of your account and personal data' : 'correction of your personal data') . '</strong> (reference #' . (int) $reqId . ').</p>'
                    . '<p>Our team will review it and reply to this email address. You can follow its status in your dashboard under Privacy &amp; Settings.</p>');
                add_notification($uid, 'Request received', 'Your data ' . $data['request_type'] . ' request #' . $reqId . ' has been received.', '/dashboard/settings/', 'info');
                flash('success', 'Your request has been sent (#' . $reqId . '). We will reply by email.');
            } elseif ($action === 'deactivate') {
                if (is_staff($uid)) {
                    flash('error', 'Staff accounts cannot be deactivated here. Please ask an administrator.');
                    break;
                }
                $limited = form_rate_limited('deactivate', 5);
                log_activity('form_deactivate', 'Account deactivation form', $uid);
                if ($limited) {
                    flash('error', 'Too many attempts. Please try again in an hour.');
                    break;
                }
                if (empty($_POST['confirm_deactivate'])) {
                    back_with_errors(['confirm_deactivate' => 'Please confirm that you want to deactivate your account.'], $base);
                }
                if (!password_verify(post_str('deactivate_password'), (string) db_value('SELECT password_hash FROM users WHERE id = ?', [$uid]))) {
                    back_with_errors(['deactivate_password' => 'Your password is not correct.'], $base);
                }
                db_update('users', ['status' => 'deactivated'], 'id = :id', ['id' => $uid]);
                log_activity('account_deactivated', 'Account deactivated by the member', $uid);
                send_mail($user['email'], 'Your account has been deactivated - ' . site_name(),
                    '<p>Dear ' . e($user['name']) . ',</p><p>Your ' . e(site_name()) . ' account was deactivated on ' . e(date('d M Y, h:i A')) . ' at your request. You can no longer sign in.</p>'
                    . '<p>Records of donations and receipts are kept for the foundation\'s accounts. If you would like to reactivate your account, or did not do this, please <a href="' . e(abs_url('contact/')) . '">contact us</a>.</p>');
                logout_everywhere($uid);
                logout_user();
                flash('success', 'Your account has been deactivated and you have been signed out. Thank you for being part of our work.');
                redirect('/');
            }
            break;
    }
    redirect($base);
}

// ---------------------------------------------------------------------
// Certificate pages (view / download of the uploaded PDF - owner only)
// ---------------------------------------------------------------------
$cert = null;
if ($certNo !== '') {
    $cert = member_find_certificate($uid, $certNo);
    if (!$cert) {
        not_found();
    }
    if ($certDownload) {
        if (empty($cert['file_path'])) {
            not_found();
        }
        log_activity('certificate_downloaded', (string) $cert['certificate_no'], $uid);
        send_private_file($cert['file_path'], member_certificate_slug((string) $cert['certificate_no']));
    }
}

// ---------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------
[$secIcon, $secLabel, $secTitle, $secIntro] = $sections[$section];
if ($cert) {
    $secTitle = $cert['title'];
    $secIntro = 'Certificate ' . $cert['certificate_no'] . ' · issued ' . format_date($cert['issue_date']);
}
$unread = unread_notification_count($uid);
$roleNames = member_role_names($uid);
$isStaff = is_staff($uid);
$verified = !empty($user['email_verified_at']);
$crumbs = $section === 'overview' ? [] : [['My Dashboard', member_url()]];
if ($cert) {
    $crumbs[] = ['My Certificates', member_url('certificates')];
}

page_header($page, [
    'title'      => $secTitle,
    'meta_title' => $section === 'overview' ? 'My Dashboard' : ($cert ? $cert['title'] . ' - Certificate' : $secTitle . ' - My Dashboard'),
    'banner'     => false,
    'noindex'    => true,
    'crumbs'     => $crumbs,
    'body_class' => 'md-page' . ($cert ? ' md-print-cert' : ''),
]);
$csrf = csrf_field();
?>
<section class="md-wrap">
    <div class="container md-shell">
        <aside class="md-side" aria-label="My account">
            <div class="md-user">
                <?= member_avatar($user) ?>
                <div class="md-user-text">
                    <strong><?= e($user['name']) ?></strong>
                    <small><?= e($roleNames ? implode(' · ', $roleNames) : 'Website account') ?></small>
                </div>
            </div>
            <nav class="md-nav" aria-label="Dashboard sections">
                <ul>
                    <?php foreach ($sections as $slug => [$ic, $label]): $active = $slug === $section; ?>
                        <li><a class="<?= $active ? 'active' : '' ?>" href="<?= e(member_url($slug === 'overview' ? '' : $slug)) ?>"<?= $active && !$cert ? ' aria-current="page"' : '' ?>>
                            <?= icon($ic) ?><span><?= e($label) ?></span>
                            <?php if ($slug === 'notifications' && $unread > 0): ?><em class="md-badge" aria-label="<?= $unread ?> unread"><?= $unread > 99 ? '99+' : $unread ?></em><?php endif; ?>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>
            <div class="md-side-foot">
                <?php if ($isStaff): ?>
                    <a class="md-admin-link" href="<?= e(url('admin/')) ?>"><?= icon('sliders') ?><span>Admin panel</span></a>
                <?php endif; ?>
                <form method="post" action="<?= e(page_url('logout')) ?>">
                    <?= $csrf ?>
                    <button class="md-logout" type="submit"><?= icon('log-out') ?><span>Sign out</span></button>
                </form>
            </div>
        </aside>

        <div class="md-main">
            <header class="md-head">
                <div class="md-head-text">
                    <span class="md-eyebrow"><?= icon($secIcon) ?><?= $cert ? 'My certificates' : ($section === 'overview' ? 'My account' : 'My dashboard') ?></span>
                    <h1><?= e($secTitle) ?></h1>
                    <p><?= e($secIntro) ?></p>
                </div>
                <?php if ($section === 'receipts' || $cert): ?>
                    <div class="md-head-actions">
                        <?php if ($cert): ?><a class="btn btn-ghost btn-sm" href="<?= e(member_url('certificates')) ?>"><?= icon('chevron-left') ?>All certificates</a><?php endif; ?>
                        <button type="button" class="btn btn-outline btn-sm" data-print><?= icon('printer') ?>Print</button>
                    </div>
                <?php elseif ($section === 'notifications' && $unread > 0): ?>
                    <form class="md-head-actions" method="post" action="<?= e(member_url('notifications')) ?>">
                        <?= $csrf ?><input type="hidden" name="action" value="read_all">
                        <button class="btn btn-outline btn-sm" type="submit"><?= icon('check') ?>Mark all as read</button>
                    </form>
                <?php endif; ?>
            </header>

<?php
switch ($section):

// =====================================================================
case 'overview':
    $params = [];
    $scope = member_donation_scope($user, $params);
    $don = db_row("SELECT COUNT(*) AS n, COALESCE(SUM(d.amount), 0) AS total FROM donations d WHERE d.status = 'success' AND $scope", $params);
    $receiptCount = (int) db_value("SELECT COUNT(*) FROM donation_receipts r JOIN donations d ON d.id = r.donation_id WHERE r.status = 'issued' AND d.status = 'success' AND $scope", $params);
    $apps = member_my_applications($uid);
    $byStatus = [];
    foreach ($apps as $a) {
        $byStatus[$a['status']] = ($byStatus[$a['status']] ?? 0) + 1;
    }
    $upcoming = array_reverse(array_values(array_filter(programhub_member_registrations($user), function ($r) {
        return in_array($r['status'], ['registered', 'confirmed', 'waitlisted'], true) && $r['event_status'] === 'published' && strtotime((string) $r['start_datetime']) >= time();
    })));
    $certCount = (int) db_value("SELECT COUNT(*) FROM certificates WHERE user_id = ? AND status = 'approved'", [$uid]);
    $openTasks = (int) db_value("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status IN ('assigned', 'in_progress')", [$uid]);
    $latest = db_rows('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 4', [$uid]);
    $first = strtok((string) $user['name'], ' ');
    $stats = [
        ['heart', money($don['total']), 'Verified donations' . ((int) $don['n'] ? ' (' . (int) $don['n'] . ')' : ''), 'donations', 'gold'],
        ['file-text', (string) $receiptCount, 'Receipts', 'receipts', 'green'],
        ['layers', (string) count($apps), 'Applications', 'applications', 'blue'],
        ['calendar', (string) count($upcoming), 'Upcoming events', 'events', 'teal'],
        ['award', (string) $certCount, 'Certificates', 'certificates', 'green'],
        ['bell', (string) $unread, 'Unread notifications', 'notifications', 'orange'],
    ];
    if ($openTasks > 0) {
        $stats[] = ['check-circle', (string) $openTasks, 'Open tasks', 'tasks', 'blue'];
    }
    ?>
            <?php if (!$verified): ?>
                <div class="md-banner md-banner-warn" role="status">
                    <span class="md-banner-icon"><?= icon('mail') ?></span>
                    <div><strong>Please verify your email address</strong><p>We sent a verification link to <?= e($user['email']) ?>. Verifying it keeps your account secure and lets donations made with this email appear here.</p></div>
                    <form method="post" action="<?= e(member_url()) ?>"><?= $csrf ?><input type="hidden" name="action" value="resend_verification">
                        <button class="btn btn-donate btn-sm" type="submit"><?= icon('send') ?>Resend link</button></form>
                </div>
            <?php endif; ?>
            <?php if ($isStaff): ?>
                <div class="md-banner md-banner-info">
                    <span class="md-banner-icon"><?= icon('shield') ?></span>
                    <div><strong>You have a staff account</strong><p>Manage the website, donations and applications from the admin panel.</p></div>
                    <a class="btn btn-primary btn-sm" href="<?= e(url('admin/')) ?>"><?= icon('sliders') ?>Open admin panel</a>
                </div>
            <?php endif; ?>

            <div class="md-welcome">
                <div>
                    <p class="md-welcome-hi">Namaste, <?= e($first) ?>!</p>
                    <p class="md-welcome-text">Thank you for being part of <?= e(site_name()) ?>. <span class="hindi" lang="hi"><?= e(setting('site_tagline')) ?></span></p>
                </div>
                <div class="md-welcome-meta">
                    <span><?= icon('user') ?>Member since <?= e(format_date($user['created_at'], 'M Y')) ?></span>
                    <span class="<?= $verified ? 'is-ok' : 'is-warn' ?>"><?= icon($verified ? 'check-circle' : 'alert-circle') ?><?= $verified ? 'Email verified' : 'Email not verified' ?></span>
                </div>
            </div>

            <div class="md-stats">
                <?php foreach ($stats as $i => [$ic, $value, $label, $link, $tone]): ?>
                    <a class="md-stat tone-<?= e($tone) ?> reveal" style="--d:<?= $i % 4 ?>" href="<?= e(member_url($link)) ?>">
                        <span class="md-stat-icon"><?= icon($ic) ?></span>
                        <span><strong><?= e($value) ?></strong><small><?= e($label) ?></small></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="md-cols">
                <div class="md-col">
                    <section class="md-panel">
                        <div class="md-panel-head"><h2>Applications</h2><a class="link-arrow" href="<?= e(member_url('applications')) ?>">View all <?= icon('arrow-right') ?></a></div>
                        <?php if ($apps): ?>
                            <div class="md-chips">
                                <?php foreach ($byStatus as $st => $n): ?><span class="md-chip"><?= member_status_badge($st) ?><b><?= (int) $n ?></b></span><?php endforeach; ?>
                            </div>
                            <ul class="md-mini-list">
                                <?php foreach (array_slice($apps, 0, 3) as $a): ?>
                                    <li><span class="md-mini-icon"><?= icon($a['icon']) ?></span><span class="md-mini-text"><strong><?= e($a['label']) ?></strong><small><?= e($a['ref'] !== '' ? $a['ref'] . ' · ' : '') ?>Submitted <?= e(format_date($a['created_at'])) ?></small></span><?= member_application_badge($a['status'], $a['type']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="muted">You have not applied for anything yet. Volunteer, teach, intern with us or request support.</p>
                            <a class="btn btn-outline btn-sm" href="<?= e(page_url('become-a-volunteer')) ?>"><?= icon('users') ?>Become a volunteer</a>
                        <?php endif; ?>
                    </section>

                    <section class="md-panel">
                        <div class="md-panel-head"><h2>Upcoming events</h2><a class="link-arrow" href="<?= e(member_url('events')) ?>">My events <?= icon('arrow-right') ?></a></div>
                        <?php if ($upcoming): ?>
                            <ul class="md-mini-list">
                                <?php foreach (array_slice($upcoming, 0, 3) as $ev): ?>
                                    <li><span class="md-date"><b><?= e(date('d', strtotime($ev['start_datetime']))) ?></b><?= e(date('M', strtotime($ev['start_datetime']))) ?></span>
                                        <span class="md-mini-text"><a href="<?= e(event_url($ev)) ?>"><strong><?= e($ev['title']) ?></strong></a><small><?= e(format_date($ev['start_datetime'], 'D, d M Y · h:i A')) ?> · <?= e(programhub_event_where($ev)) ?><?= $ev['status'] === 'waitlisted' ? ' · waiting list' : '' ?></small></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="muted">You are not registered for any upcoming events.</p>
                            <a class="btn btn-outline btn-sm" href="<?= e(events_list_url()) ?>"><?= icon('calendar') ?>Browse events</a>
                        <?php endif; ?>
                    </section>
                </div>

                <div class="md-col">
                    <section class="md-panel">
                        <div class="md-panel-head"><h2>Quick actions</h2></div>
                        <div class="md-quick">
                            <a href="<?= e(page_url('donate')) ?>" class="is-gold"><?= icon('heart') ?><span>Donate</span></a>
                            <a href="<?= e(events_list_url()) ?>"><?= icon('calendar') ?><span>Find events</span></a>
                            <a href="<?= e(page_url('become-a-volunteer')) ?>"><?= icon('users') ?><span>Volunteer</span></a>
                            <a href="<?= e(page_url('application-status')) ?>"><?= icon('search') ?><span>Track application</span></a>
                            <a href="<?= e(member_url('receipts')) ?>"><?= icon('download') ?><span>My receipts</span></a>
                            <a href="<?= e(member_url('profile')) ?>"><?= icon('edit') ?><span>Edit profile</span></a>
                        </div>
                    </section>

                    <section class="md-panel">
                        <div class="md-panel-head"><h2>Latest notifications</h2><a class="link-arrow" href="<?= e(member_url('notifications')) ?>">All <?= icon('arrow-right') ?></a></div>
                        <?php if ($latest): ?>
                            <ul class="md-mini-list">
                                <?php foreach ($latest as $n): ?>
                                    <li class="<?= $n['read_at'] ? '' : 'is-unread' ?><?= $n['type'] === 'security' ? ' is-security' : '' ?>"><span class="md-mini-icon"><?= icon($n['type'] === 'security' ? 'shield' : ($n['type'] === 'success' ? 'check-circle' : 'bell')) ?></span>
                                        <span class="md-mini-text"><strong><?= e($n['title']) ?></strong><small><?= e(format_date($n['created_at'], 'd M Y, h:i A')) ?></small></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="muted">No notifications yet.</p>
                        <?php endif; ?>
                    </section>
                </div>
            </div>
    <?php
    break;

// =====================================================================
case 'profile':
    $states = indian_states();
    ?>
            <div class="md-cols md-cols-wide">
                <section class="md-panel">
                    <div class="md-panel-head"><h2>Personal details</h2></div>
                    <form class="md-form" method="post" action="<?= e(member_url('profile')) ?>" enctype="multipart/form-data">
                        <?= $csrf ?><input type="hidden" name="action" value="profile">
                        <div class="md-avatar-row">
                            <?= member_avatar($user, 'md-avatar-lg') ?>
                            <div class="form-group">
                                <label for="avatar">Profile photo</label>
                                <input id="avatar" type="file" name="avatar" accept=".jpg,.jpeg,.png,.webp" data-max-mb="<?= (int) MAX_UPLOAD_MB ?>" aria-describedby="avatar-help">
                                <span class="help" id="avatar-help">JPG, PNG or WebP, up to <?= (int) MAX_UPLOAD_MB ?> MB. Optional.</span>
                                <?= field_error('avatar') ?>
                                <?php if (!empty($user['avatar'])): ?><label class="checkbox"><input type="checkbox" name="remove_avatar" value="1"><span>Remove my photo</span></label><?php endif; ?>
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="form-group full"><label for="name">Full name<span class="req">*</span></label>
                                <input id="name" type="text" name="name" required minlength="2" maxlength="120" autocomplete="name" value="<?= e(old('name', $user['name'])) ?>"><?= field_error('name') ?></div>
                            <div class="form-group"><label for="mobile">Mobile number</label>
                                <input id="mobile" type="tel" name="mobile" maxlength="16" autocomplete="tel" value="<?= e(old('mobile', (string) $user['mobile'])) ?>"><?= field_error('mobile') ?></div>
                            <div class="form-group"><label for="email_display">Email address</label>
                                <input id="email_display" type="email" value="<?= e($user['email']) ?>" disabled>
                                <span class="help"><?= $verified ? 'Verified' : 'Not verified yet' ?> · change it below</span></div>
                            <div class="form-group full"><label for="address">Address</label>
                                <input id="address" type="text" name="address" maxlength="255" autocomplete="street-address" value="<?= e(old('address', (string) $user['address'])) ?>"><?= field_error('address') ?></div>
                            <div class="form-group"><label for="city">City / Town</label>
                                <input id="city" type="text" name="city" maxlength="80" autocomplete="address-level2" value="<?= e(old('city', (string) $user['city'])) ?>"><?= field_error('city') ?></div>
                            <div class="form-group"><label for="state">State</label>
                                <select id="state" name="state"><?= state_options((string) old('state', (string) $user['state'])) ?></select><?= field_error('state') ?></div>
                            <div class="form-group"><label for="pincode">PIN code</label>
                                <input id="pincode" type="text" name="pincode" inputmode="numeric" maxlength="6" pattern="[1-9][0-9]{5}" autocomplete="postal-code" value="<?= e(old('pincode', (string) $user['pincode'])) ?>"><?= field_error('pincode') ?></div>
                        </div>
                        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save profile</button></div>
                    </form>
                </section>

                <section class="md-panel">
                    <div class="md-panel-head"><h2>Change email address</h2><?= member_status_badge($verified ? 'verified' : 'unverified') ?></div>
                    <p class="muted small">Your current address is <strong><?= e($user['email']) ?></strong>. After a change we email a verification link to the new address and a notice to the old one. Until the new address is verified you may not be able to sign in, so please type it carefully.</p>
                    <form class="md-form" method="post" action="<?= e(member_url('profile')) ?>">
                        <?= $csrf ?><input type="hidden" name="action" value="email">
                        <div class="form-group"><label for="new_email">New email address</label>
                            <input id="new_email" type="email" name="new_email" required maxlength="190" autocomplete="email" value="<?= e(old('new_email')) ?>"><?= field_error('new_email') ?></div>
                        <div class="form-group"><label for="new_email_confirm">Confirm new email address</label>
                            <input id="new_email_confirm" type="email" name="new_email_confirm" required maxlength="190" autocomplete="email" value="<?= e(old('new_email_confirm')) ?>"><?= field_error('new_email_confirm') ?></div>
                        <?= account_password_field('email_current_password', 'current_password', ['label' => 'Current password', 'required' => true]) ?>
                        <div class="form-actions"><button class="btn btn-outline" type="submit"><?= icon('mail') ?>Change email</button></div>
                    </form>
                </section>
            </div>
    <?php
    break;

// =====================================================================
case 'applications':
    $apps = member_my_applications($uid);
    $proposals = db_rows('SELECT id, title, slug, status, goal_amount, created_at, reviewed_at FROM campaigns WHERE submitted_by = ? ORDER BY created_at DESC', [$uid]);
    ?>
            <?php if (!$apps && !$proposals): ?>
                <?= empty_state('You have no applications yet. When you apply to volunteer, teach, intern, join as a member or request support while signed in, you can follow it here.', 'Explore ways to get involved', page_url('become-a-volunteer')) ?>
                <div class="md-apply-links">
                    <?php foreach (member_application_sources() as $src): ?>
                        <a href="<?= e(page_url($src['page'])) ?>"><?= icon($src['icon']) ?><span><?= e(preg_replace('/ application$/', '', $src['label'])) ?></span></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php foreach ($apps as $a): ?>
                <article class="md-app">
                    <header class="md-app-head">
                        <span class="md-app-icon"><?= icon($a['icon']) ?></span>
                        <div class="md-app-title">
                            <h2><?= e($a['label']) ?></h2>
                            <small><?= $a['ref'] !== '' ? 'Ref. <strong>' . e($a['ref']) . '</strong> · ' : '' ?>Submitted <?= e(format_date($a['created_at'])) ?><?= $a['detail'] !== '' ? ' · ' . e(excerpt($a['detail'], 60)) : '' ?></small>
                        </div>
                        <?= member_application_badge($a['status'], $a['type']) ?>
                    </header>
                    <?php if (($statusText = member_application_status_text($a['status'], $a['type'])) !== ''): ?><p class="md-app-status"><?= e($statusText) ?></p><?php endif; ?>
                    <?php if ($a['info_request'] !== ''): ?>
                        <div class="md-note md-note-gold"><?= icon('info') ?><div><strong>We need more information</strong><p><?= e_nl($a['info_request']) ?></p><small>Please reply to the email we sent you or <a href="<?= e(page_url('contact')) ?>">contact us</a> with your reference number.</small></div></div>
                    <?php endif; ?>
                    <?php if ($a['interview_at'] !== ''): ?>
                        <div class="md-note md-note-blue"><?= icon('calendar') ?><div><strong>Interview scheduled</strong><p><?= e(format_date($a['interview_at'], 'l, d M Y · h:i A')) ?><?= $a['interview_mode'] !== '' ? ' · ' . e($a['interview_mode']) : '' ?></p></div></div>
                    <?php endif; ?>
                    <?php if ($a['valid_until'] !== '' && $a['status'] === 'approved'): ?>
                        <div class="md-note"><?= icon('id-card') ?><div><strong>Membership valid until <?= e(format_date($a['valid_until'])) ?></strong></div></div>
                    <?php endif; ?>
                    <ol class="timeline md-timeline">
                        <li class="done"><strong>Submitted</strong> <small><?= e(format_date($a['created_at'], 'd M Y, h:i A')) ?></small></li>
                        <?php foreach ($a['history'] as $i => $h): if (in_array($h['status'], ['submitted', 'pending'], true) && $i === 0) { continue; } ?>
                            <li class="<?= $i === count($a['history']) - 1 ? 'current' : 'done' ?>"><strong><?= e(member_history_label($h['status'], $a['type'])) ?></strong> <small><?= e(format_date($h['created_at'], 'd M Y, h:i A')) ?></small>
                                <?php if (!empty($h['note'])): ?><p><?= e_nl($h['note']) ?></p><?php endif; ?></li>
                        <?php endforeach; ?>
                        <?php if (!$a['history'] && $a['status'] !== 'submitted' && $a['status'] !== 'pending'): ?>
                            <li class="current"><strong><?= e(member_history_label($a['status'], $a['type'])) ?></strong><?php if ($a['updated_at'] !== ''): ?> <small><?= e(format_date($a['updated_at'], 'd M Y')) ?></small><?php endif; ?></li>
                        <?php endif; ?>
                    </ol>
                </article>
            <?php endforeach; ?>

            <?php if ($proposals): ?>
                <section class="md-panel">
                    <div class="md-panel-head"><h2>My fundraiser proposals</h2><a class="link-arrow" href="<?= e(page_url('start-a-fundraiser')) ?>">Propose another <?= icon('arrow-right') ?></a></div>
                    <div class="md-table-wrap"><table class="md-table">
                        <thead><tr><th>Proposal</th><th>Submitted</th><th>Goal</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($proposals as $pr): $public = in_array($pr['status'], ['active', 'completed'], true); ?>
                            <tr>
                                <td data-label="Proposal"><?= $public ? '<a href="' . e(campaign_url($pr['slug'])) . '">' . e($pr['title']) . '</a>' : e($pr['title']) ?></td>
                                <td data-label="Submitted"><?= e(format_date($pr['created_at'])) ?></td>
                                <td data-label="Goal"><?= (float) $pr['goal_amount'] > 0 ? e(money($pr['goal_amount'])) : '-' ?></td>
                                <td data-label="Status"><?= member_status_badge($pr['status'] === 'proposal' ? 'under_review' : $pr['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </section>
            <?php endif; ?>
            <p class="md-foot-note"><?= icon('info') ?><span>Applied without signing in? Track it with your reference number on the <a href="<?= e(page_url('application-status')) ?>">application status</a> page.</span></p>
    <?php
    break;

// =====================================================================
case 'tasks':
    $tasks = db_rows("SELECT * FROM tasks WHERE user_id = ? ORDER BY FIELD(status, 'in_progress', 'assigned', 'done', 'cancelled'), due_date IS NULL, due_date, id DESC", [$uid]);
    ?>
            <?php if (!$tasks): ?>
                <?= empty_state('No tasks have been assigned to you yet. When a coordinator assigns you a task, it will appear here.') ?>
            <?php endif; ?>
            <div class="md-task-list">
                <?php foreach ($tasks as $t):
                    $open = in_array($t['status'], ['assigned', 'in_progress'], true);
                    $overdue = $open && $t['due_date'] && $t['due_date'] < date('Y-m-d'); ?>
                    <article class="md-task<?= $open ? '' : ' is-closed' ?><?= $overdue ? ' is-overdue' : '' ?>">
                        <div class="md-task-main">
                            <h2><?= e($t['title']) ?></h2>
                            <?php if (!empty($t['details'])): ?><p><?= e_nl($t['details']) ?></p><?php endif; ?>
                            <small><?= icon('calendar') ?><?= $t['due_date'] ? 'Due ' . e(format_date($t['due_date'])) . ($overdue ? ' · overdue' : '') : 'No due date' ?><?= $t['completed_at'] ? ' · completed ' . e(format_date($t['completed_at'])) : '' ?></small>
                        </div>
                        <div class="md-task-side">
                            <?= member_status_badge($t['status']) ?>
                            <?php if ($open): ?>
                                <form method="post" action="<?= e(member_url('tasks')) ?>" class="md-inline-actions">
                                    <?= $csrf ?><input type="hidden" name="action" value="task"><input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
                                    <?php if ($t['status'] === 'assigned'): ?><button class="btn btn-ghost btn-sm" type="submit" name="status" value="in_progress"><?= icon('clock') ?>Start</button><?php endif; ?>
                                    <button class="btn btn-primary btn-sm" type="submit" name="status" value="done"><?= icon('check') ?>Mark done</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
    <?php
    break;

// =====================================================================
case 'events':
    // own registrations and those made with the verified account email (programhub.php); never includes the meeting link
    $regs = programhub_member_registrations($user);
    $now = date('Y-m-d H:i:s');
    $upcomingRegs = array_reverse(array_values(array_filter($regs, function ($r) use ($now) { return $r['start_datetime'] >= $now; })));
    $pastRegs = array_values(array_filter($regs, function ($r) use ($now) { return $r['start_datetime'] < $now; }));
    ?>
            <section class="md-panel">
                <div class="md-panel-head"><h2>Upcoming</h2><a class="link-arrow" href="<?= e(events_list_url()) ?>">Browse events <?= icon('arrow-right') ?></a></div>
                <?php if (!$upcomingRegs): ?>
                    <p class="muted">You are not registered for any upcoming events.</p>
                <?php endif; ?>
                <div class="md-event-list">
                    <?php foreach ($upcomingRegs as $r): $ts = strtotime($r['start_datetime']); ?>
                        <article class="md-event">
                            <span class="md-date md-date-lg"><b><?= e(date('d', $ts)) ?></b><?= e(date('M Y', $ts)) ?></span>
                            <div class="md-event-main">
                                <h3><?= $r['event_status'] === 'published' ? '<a href="' . e(event_url($r)) . '">' . e($r['title']) . '</a>' : e($r['title']) ?></h3>
                                <small><?= icon('clock') ?><?= e(date('D, d M Y · h:i A', $ts)) ?></small>
                                <small><?= icon(event_mode($r) === 'online' ? 'monitor' : 'map-pin') ?><?= e(programhub_event_where($r)) ?></small>
                                <small><?= icon('file-text') ?>Reference <?= e(programhub_registration_ref($r)) ?></small>
                            </div>
                            <div class="md-event-side">
                                <?= $r['event_status'] === 'cancelled' ? member_status_badge('cancelled') : programhub_reg_badge((string) $r['status']) ?>
                                <?php if (event_mode($r) !== 'in_person' && in_array($r['status'], ['registered', 'confirmed'], true) && $r['event_status'] === 'published'): ?>
                                    <a class="btn btn-blue btn-sm" href="<?= e(event_url($r) . 'join/') ?>" rel="nofollow"><?= icon('monitor') ?>Joining details</a>
                                <?php endif; ?>
                                <?php if (in_array($r['status'], ['registered', 'confirmed', 'waitlisted'], true) && $r['event_status'] !== 'cancelled'): ?>
                                    <form method="post" action="<?= e(member_url('events')) ?>" data-confirm="Cancel your registration for this event?">
                                        <?= $csrf ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="registration_id" value="<?= (int) $r['id'] ?>">
                                        <button class="btn btn-ghost btn-sm" type="submit"><?= icon('x') ?>Cancel registration</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <section class="md-panel">
                <div class="md-panel-head"><h2>Past events</h2></div>
                <?php if (!$pastRegs): ?>
                    <p class="muted">Events you have registered for will be listed here after they take place.</p>
                <?php else: ?>
                    <div class="md-table-wrap"><table class="md-table">
                        <thead><tr><th>Event</th><th>Date</th><th>Location</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($pastRegs as $r): ?>
                            <tr><td data-label="Event"><?= $r['event_status'] === 'published' ? '<a href="' . e(event_url($r)) . '">' . e($r['title']) . '</a>' : e($r['title']) ?></td>
                                <td data-label="Date"><?= e(format_date($r['start_datetime'])) ?></td>
                                <td data-label="Location"><?= e(programhub_event_where($r)) ?></td>
                                <td data-label="Status"><?= programhub_reg_badge((string) $r['status']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </section>
    <?php
    break;

// =====================================================================
case 'donations':
    $params = [];
    $scope = member_donation_scope($user, $params);
    $sum = db_row("SELECT COUNT(*) AS n, COALESCE(SUM(d.amount), 0) AS total FROM donations d WHERE d.status = 'success' AND $scope", $params);
    $total = (int) db_value("SELECT COUNT(*) FROM donations d WHERE $scope", $params);
    $p = paginate($total, 15);
    $rows = db_rows("SELECT d.id, d.donation_ref, d.access_token, d.amount, d.status, d.payment_method, d.created_at, d.paid_at,
                            c.title AS campaign_title, c.slug AS campaign_slug, c.status AS campaign_status, r.receipt_no, r.status AS receipt_status
                       FROM donations d LEFT JOIN campaigns c ON c.id = d.campaign_id LEFT JOIN donation_receipts r ON r.donation_id = d.id
                      WHERE $scope ORDER BY d.created_at DESC, d.id DESC LIMIT " . (int) $p['per_page'] . ' OFFSET ' . (int) $p['offset'], $params);
    ?>
            <div class="md-summary">
                <div><small>Verified total</small><strong><?= e(money($sum['total'])) ?></strong></div>
                <div><small>Verified donations</small><strong><?= (int) $sum['n'] ?></strong></div>
                <div class="md-summary-cta"><a class="btn btn-donate" href="<?= e(page_url('donate')) ?>"><?= icon('heart') ?>Donate again</a></div>
            </div>
            <?php if (!$verified): ?>
                <p class="md-foot-note"><?= icon('info') ?><span>Donations made with <?= e($user['email']) ?> without signing in will also appear here once you verify your email address.</span></p>
            <?php endif; ?>
            <?php if (!$rows): ?>
                <?= empty_state('You have not made any donations with this account yet. Every verified donation and its receipt will appear here.', 'Make a donation', page_url('donate')) ?>
            <?php else: ?>
                <div class="md-table-wrap"><table class="md-table">
                    <thead><tr><th>Date</th><th>Reference</th><th>Cause</th><th>Amount</th><th>Status</th><th>Receipt</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $d): $campPublic = $d['campaign_slug'] && in_array($d['campaign_status'], ['active', 'completed'], true); ?>
                        <tr>
                            <td data-label="Date"><?= e(format_date($d['paid_at'] ?: $d['created_at'])) ?></td>
                            <td data-label="Reference"><code><?= e($d['donation_ref']) ?></code></td>
                            <td data-label="Cause"><?= $d['campaign_title'] ? ($campPublic ? '<a href="' . e(campaign_url($d['campaign_slug'])) . '">' . e($d['campaign_title']) . '</a>' : e($d['campaign_title'])) : 'General donation' ?></td>
                            <td data-label="Amount"><strong><?= e(money($d['amount'], true)) ?></strong></td>
                            <td data-label="Status"><?= member_status_badge($d['status']) ?></td>
                            <td data-label="Receipt"><?php if ($d['status'] === 'success' && $d['receipt_no'] && $d['receipt_status'] === 'issued'): ?>
                                <a class="btn btn-ghost btn-sm" href="<?= e(member_receipt_url($d)) ?>"><?= icon('file-text') ?><?= e($d['receipt_no']) ?></a>
                            <?php elseif ($d['status'] === 'success'): ?><span class="muted small">Being prepared</span><?php else: ?><span class="muted small">-</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?= pagination_links($p) ?>
            <?php endif; ?>
            <p class="md-foot-note"><?= icon('shield') ?><span>Only payments verified with the payment gateway or confirmed by our accounts team are counted as successful. Questions about a payment? <a href="<?= e(page_url('contact')) ?>">Contact us</a> with the reference number.</span></p>
    <?php
    break;

// =====================================================================
case 'receipts':
    $params = [];
    $scope = member_donation_scope($user, $params);
    $receipts = db_rows("SELECT r.receipt_no, r.financial_year, r.issued_at, r.status AS receipt_status, d.donation_ref, d.access_token, d.amount, d.paid_at, c.title AS campaign_title
                           FROM donation_receipts r JOIN donations d ON d.id = r.donation_id LEFT JOIN campaigns c ON c.id = d.campaign_id
                          WHERE d.status = 'success' AND $scope ORDER BY r.issued_at DESC", $params);
    ?>
            <?php if (!$receipts): ?>
                <?= empty_state('No receipts yet. A receipt is issued automatically for every verified donation.', 'View my donations', member_url('donations')) ?>
            <?php else: ?>
                <div class="md-table-wrap"><table class="md-table md-print-table">
                    <thead><tr><th>Receipt no.</th><th>Financial year</th><th>Issued</th><th>Donation</th><th>Amount</th><th class="md-no-print">Receipt</th></tr></thead>
                    <tbody>
                    <?php foreach ($receipts as $r): ?>
                        <tr>
                            <td data-label="Receipt no."><strong><?= e($r['receipt_no']) ?></strong><?= $r['receipt_status'] !== 'issued' ? ' ' . member_status_badge($r['receipt_status']) : '' ?></td>
                            <td data-label="Financial year"><?= e($r['financial_year']) ?></td>
                            <td data-label="Issued"><?= e(format_date($r['issued_at'])) ?></td>
                            <td data-label="Donation"><code><?= e($r['donation_ref']) ?></code><small class="md-sub"><?= e($r['campaign_title'] ?: 'General donation') ?></small></td>
                            <td data-label="Amount"><strong><?= e(money($r['amount'], true)) ?></strong></td>
                            <td data-label="Receipt" class="md-no-print"><?php if ($r['receipt_status'] === 'issued'): ?><a class="btn btn-ghost btn-sm" href="<?= e(member_receipt_url($r)) ?>"><?= icon('printer') ?>View / print</a><?php else: ?><span class="muted small">Cancelled</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
            <p class="md-foot-note"><?= icon('info') ?><span>Open a receipt to print it or save it as PDF. Tax exemption details are shown on the receipt only where the foundation holds the relevant registration.</span></p>
    <?php
    break;

// =====================================================================
case 'certificates':
    if ($cert):
        $certHtml = '';
        if (function_exists('render_certificate_html')) {
            try {
                $certHtml = (string) render_certificate_html($cert);
            } catch (Throwable $ex) {
                error_log('render_certificate_html failed: ' . $ex->getMessage());
                $certHtml = '';
            }
        }
        if ($certHtml === ''):
        ?>
            <div class="certificate md-certificate" role="document" aria-label="Certificate <?= e($cert['certificate_no']) ?>">
                <img src="<?= e(site_logo()) ?>" alt="<?= e(site_name()) ?>" width="110" height="110">
                <p class="md-cert-org"><?= e(site_name()) ?></p>
                <h2 class="md-cert-h">Certificate</h2>
                <p class="cert-sub">of <?= e(in_array($cert['certificate_type'], ['appreciation', 'participation', 'membership'], true) ? $cert['certificate_type'] : $cert['certificate_type'] . ' service') ?></p>
                <p class="md-cert-small">This is to certify that</p>
                <p class="cert-name"><?= e($cert['recipient_name']) ?></p>
                <div class="cert-text">
                    <p class="md-cert-title"><?= e($cert['title']) ?></p>
                    <?php if (!empty($cert['description'])): ?><p><?= e($cert['description']) ?></p><?php endif; ?>
                </div>
                <div class="cert-foot">
                    <div><small>Date of issue</small><strong><?= e(format_date($cert['issue_date'], 'd F Y')) ?></strong></div>
                    <div><small>Certificate no.</small><strong><?= e($cert['certificate_no']) ?></strong></div>
                    <div class="signature"><span><?= e(setting('receipt_signatory', 'Authorised Signatory')) ?></span>For <?= e(site_name()) ?></div>
                </div>
                <p class="md-cert-tagline hindi" lang="hi"><?= e(setting('site_tagline')) ?></p>
            </div>
        <?php else: ?>
            <div class="md-certificate-ext"><?= $certHtml ?></div>
        <?php endif; ?>
            <div class="btn-row md-no-print mt-3">
                <button type="button" class="btn btn-primary" data-print><?= icon('printer') ?>Print / save as PDF</button>
                <?php if (!empty($cert['file_path'])): ?><a class="btn btn-outline" href="<?= e(member_certificate_url($cert['certificate_no'], true)) ?>"><?= icon('download') ?>Download signed copy</a><?php endif; ?>
            </div>
    <?php
    else:
        $certs = db_rows("SELECT * FROM certificates WHERE user_id = ? AND status = 'approved' ORDER BY issue_date DESC, id DESC", [$uid]);
        if (!$certs): ?>
            <?= empty_state('No certificates yet. Certificates for volunteering, internships, teaching, membership and events are issued by our team and appear here once approved.', 'Volunteer with us', page_url('become-a-volunteer')) ?>
        <?php else: ?>
            <div class="md-cert-grid">
                <?php foreach ($certs as $c): ?>
                    <article class="md-cert-card">
                        <span class="md-cert-badge"><?= icon('award') ?></span>
                        <h2><?= e($c['title']) ?></h2>
                        <p class="muted small"><?= e(humanize($c['certificate_type'])) ?> · issued <?= e(format_date($c['issue_date'])) ?></p>
                        <p class="md-cert-no"><?= e($c['certificate_no']) ?></p>
                        <div class="md-inline-actions">
                            <a class="btn btn-primary btn-sm" href="<?= e(member_certificate_url($c['certificate_no'])) ?>"><?= icon('eye') ?>View &amp; print</a>
                            <?php if (!empty($c['file_path'])): ?><a class="btn btn-ghost btn-sm" href="<?= e(member_certificate_url($c['certificate_no'], true)) ?>"><?= icon('download') ?>PDF</a><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif;
    endif;
    break;

// =====================================================================
case 'notifications':
    $total = (int) db_value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$uid]);
    $p = paginate($total, 20);
    $notes = db_rows('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int) $p['per_page'] . ' OFFSET ' . (int) $p['offset'], [$uid]);
    $typeIcon = ['security' => 'shield', 'success' => 'check-circle', 'warning' => 'alert-triangle', 'info' => 'bell'];
    ?>
            <?php if (!$notes): ?>
                <?= empty_state('You have no notifications. Updates about your account, applications and donations will appear here.') ?>
            <?php else: ?>
                <ul class="md-notes">
                    <?php foreach ($notes as $n): $t = isset($typeIcon[$n['type']]) ? $n['type'] : 'info'; ?>
                        <li class="md-note-item type-<?= e($t) ?><?= $n['read_at'] ? '' : ' is-unread' ?>">
                            <span class="md-note-icon"><?= icon($typeIcon[$t]) ?></span>
                            <div class="md-note-body">
                                <h2><?= e($n['title']) ?><?php if ($t === 'security'): ?> <span class="badge badge-red">Security</span><?php endif; ?><?php if (!$n['read_at']): ?> <span class="badge badge-gold">New</span><?php endif; ?></h2>
                                <p><?= e($n['message']) ?></p>
                                <small><?= e(format_date($n['created_at'], 'd M Y, h:i A')) ?></small>
                            </div>
                            <div class="md-note-actions">
                                <?php if (!empty($n['link'])): ?><a class="btn btn-ghost btn-sm" href="<?= e(safe_link((string) $n['link'])) ?>">Open</a><?php endif; ?>
                                <?php if (!$n['read_at']): ?>
                                    <form method="post" action="<?= e(member_url('notifications')) ?>"><?= $csrf ?><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><input type="hidden" name="page" value="<?= (int) $p['page'] ?>">
                                        <button class="btn btn-ghost btn-sm" type="submit" aria-label="Mark &quot;<?= e($n['title']) ?>&quot; as read"><?= icon('check') ?>Mark read</button></form>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?= pagination_links($p) ?>
            <?php endif; ?>
    <?php
    break;

// =====================================================================
case 'password':
    $lastChange = db_value("SELECT MAX(created_at) FROM activity_logs WHERE user_id = ? AND action = 'password_changed'", [$uid]);
    ?>
            <?php if (!empty($user['must_change_password'])): ?>
                <div class="md-banner md-banner-warn" role="alert"><span class="md-banner-icon"><?= icon('key') ?></span><div><strong>Please choose a new password</strong><p>Our team has asked you to set a new password before you continue.</p></div></div>
            <?php endif; ?>
            <div class="md-cols md-cols-wide">
                <section class="md-panel">
                    <div class="md-panel-head"><h2>Change password</h2></div>
                    <form class="md-form" method="post" action="<?= e(member_url('password')) ?>" data-acc-form>
                        <?= $csrf ?><input type="hidden" name="action" value="password">
                        <?= account_password_field('current_password', 'current_password', ['label' => 'Current password', 'required' => true, 'autocomplete' => 'current-password']) ?>
                        <?php $pwStaff = is_staff($uid); ?>
                        <?= account_password_field('password', 'password', ['label' => 'New password', 'required' => true, 'autocomplete' => 'new-password', 'meter' => true, 'minlength' => $pwStaff ? STAFF_PASSWORD_MIN : 8, 'help' => $pwStaff ? 'Staff accounts need at least ' . STAFF_PASSWORD_MIN . ' characters.' : '']) ?>
                        <?= account_password_field('password_confirm', 'password_confirm', ['label' => 'Confirm new password', 'required' => true, 'autocomplete' => 'new-password', 'match' => 'password']) ?>
                        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('key') ?>Change password</button></div>
                        <p class="md-foot-note"><?= icon('info') ?><span>Changing your password signs you out on all other devices and we email you a confirmation.</span></p>
                    </form>
                </section>
                <div>
                    <section class="md-panel">
                        <div class="md-panel-head"><h2>Signed in elsewhere?</h2></div>
                        <p class="muted small">If you used a shared or lost device, sign out everywhere else. This browser stays signed in.</p>
                        <form method="post" action="<?= e(member_url('password')) ?>" data-confirm="Sign out of all other devices?">
                            <?= $csrf ?><input type="hidden" name="action" value="logout_others">
                            <button class="btn btn-outline" type="submit"><?= icon('log-out') ?>Sign out of all other devices</button>
                        </form>
                    </section>
                    <section class="md-panel">
                        <div class="md-panel-head"><h2>Security overview</h2></div>
                        <dl class="md-kv">
                            <dt>Last sign-in</dt><dd><?= e(format_date($user['last_login_at'], 'd M Y, h:i A')) ?></dd>
                            <dt>Password last changed</dt><dd><?= e($lastChange ? format_date($lastChange, 'd M Y') : 'Not changed since sign-up') ?></dd>
                            <dt>Email</dt><dd><?= member_status_badge($verified ? 'verified' : 'unverified') ?></dd>
                            <?php if ($isStaff): ?><dt>Two-step login</dt><dd><?= !empty($user['totp_enabled']) ? '<span class="badge badge-green">On</span>' : '<span class="badge badge-gold">Off</span> <a href="' . e(url('admin/profile.php#two-step')) . '">Set up</a>' ?></dd><?php endif; ?>
                        </dl>
                        <p class="md-foot-note"><?= icon('shield') ?><span>We will never ask for your password or an OTP by phone, WhatsApp or email.</span></p>
                    </section>
                </div>
            </div>
    <?php
    break;

// =====================================================================
case 'settings':
    $newsletter = db_row('SELECT status, consented_at FROM newsletter_subscribers WHERE email = ?', [mb_strtolower((string) $user['email'])]);
    $subscribed = $newsletter && $newsletter['status'] === 'subscribed';
    $requests = db_rows('SELECT id, request_type, status, response, created_at, completed_at FROM data_requests WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$uid]);
    ?>
            <div class="md-cols md-cols-wide">
                <div>
                    <section class="md-panel">
                        <div class="md-panel-head"><h2>Communication preferences</h2></div>
                        <form method="post" action="<?= e(member_url('settings')) ?>" class="md-form">
                            <?= $csrf ?><input type="hidden" name="action" value="consent">
                            <label class="checkbox"><input type="checkbox" name="communication_consent" value="1"<?= !empty($user['communication_consent']) ? ' checked' : '' ?>>
                                <span>Email me about events, campaigns and volunteering opportunities. We will always email you about your account, donations and applications.</span></label>
                            <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?>Save preference</button></div>
                        </form>
                    </section>

                    <section class="md-panel">
                        <div class="md-panel-head"><h2>Newsletter</h2><?= member_status_badge($subscribed ? 'subscribed' : 'unsubscribed') ?></div>
                        <form method="post" action="<?= e(member_url('settings')) ?>" class="md-form">
                            <?= $csrf ?><input type="hidden" name="action" value="newsletter">
                            <?php if ($subscribed): ?>
                                <p class="muted small">You are receiving our newsletter at <?= e($user['email']) ?><?= $newsletter['consented_at'] ? ' (since ' . e(format_date($newsletter['consented_at'])) . ')' : '' ?>.</p>
                                <input type="hidden" name="subscribe" value="0">
                                <button class="btn btn-ghost btn-sm" type="submit"><?= icon('x') ?>Unsubscribe</button>
                            <?php elseif (!$verified): ?>
                                <p class="muted small">Verify your email address to subscribe to our newsletter.</p>
                            <?php else: ?>
                                <input type="hidden" name="subscribe" value="1">
                                <label class="checkbox"><input type="checkbox" name="newsletter_consent" value="1" required><span><?= e(setting('newsletter_consent_text', 'I agree to receive occasional email updates. I can unsubscribe at any time.')) ?></span></label>
                                <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit"><?= icon('mail') ?>Subscribe</button></div>
                            <?php endif; ?>
                        </form>
                    </section>

                    <section class="md-panel">
                        <div class="md-panel-head"><h2>Download my data</h2></div>
                        <p class="muted small">Download a copy of the personal data we hold about you - account, applications, donations, receipts, events, certificates and notifications - as a JSON file. Passwords and security tokens are never included.</p>
                        <form method="post" action="<?= e(member_url('settings')) ?>" data-no-loading>
                            <?= $csrf ?><input type="hidden" name="action" value="export">
                            <button class="btn btn-outline btn-sm" type="submit"><?= icon('download') ?>Download my data (JSON)</button>
                        </form>
                    </section>
                </div>

                <div>
                    <section class="md-panel">
                        <div class="md-panel-head"><h2>Correct or delete my data</h2></div>
                        <form method="post" action="<?= e(member_url('settings')) ?>" class="md-form">
                            <?= $csrf ?><input type="hidden" name="action" value="data_request">
                            <fieldset class="md-radio-row">
                                <legend class="label">What would you like us to do?</legend>
                                <label class="checkbox"><input type="radio" name="request_type" value="correction"<?= old('request_type', 'correction') === 'correction' ? ' checked' : '' ?>><span>Correct my personal details</span></label>
                                <label class="checkbox"><input type="radio" name="request_type" value="deletion"<?= old('request_type') === 'deletion' ? ' checked' : '' ?>><span>Delete my account and personal data</span></label>
                            </fieldset>
                            <?= field_error('request_type') ?>
                            <div class="form-group"><label for="details">Details</label>
                                <textarea id="details" name="details" maxlength="2000" rows="4" placeholder="For corrections, tell us what is wrong and what it should be."><?= e(old('details')) ?></textarea><?= field_error('details') ?></div>
                            <p class="help">Donation and receipt records are kept even after deletion, because the foundation must retain them for its accounts and tax records.</p>
                            <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit"><?= icon('send') ?>Send request</button></div>
                        </form>
                        <?php if ($requests): ?>
                            <h3 class="md-subhead">My requests</h3>
                            <ul class="md-mini-list">
                                <?php foreach ($requests as $rq): ?>
                                    <li><span class="md-mini-icon"><?= icon('shield') ?></span><span class="md-mini-text"><strong>#<?= (int) $rq['id'] ?> · <?= e(humanize($rq['request_type'])) ?></strong>
                                        <small>Sent <?= e(format_date($rq['created_at'])) ?><?= $rq['completed_at'] ? ' · closed ' . e(format_date($rq['completed_at'])) : '' ?></small>
                                        <?php if (!empty($rq['response'])): ?><small class="md-response"><?= e_nl($rq['response']) ?></small><?php endif; ?></span><?= member_status_badge($rq['status']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>

                    <section class="md-panel md-danger">
                        <div class="md-panel-head"><h2>Deactivate my account</h2></div>
                        <?php if ($isStaff): ?>
                            <p class="muted small">Staff accounts can only be deactivated by an administrator.</p>
                        <?php else: ?>
                            <p class="muted small">You will be signed out and will no longer be able to sign in. Your records stay with us; to have them deleted, send a deletion request instead.</p>
                            <form method="post" action="<?= e(member_url('settings')) ?>" class="md-form" data-confirm="Deactivate your account? You will be signed out immediately.">
                                <?= $csrf ?><input type="hidden" name="action" value="deactivate">
                                <?= account_password_field('deactivate_password', 'deactivate_password', ['label' => 'Confirm with your password', 'required' => true]) ?>
                                <label class="checkbox"><input type="checkbox" name="confirm_deactivate" value="1" required><span>I understand that I will no longer be able to sign in.</span></label>
                                <?= field_error('confirm_deactivate') ?>
                                <div class="form-actions"><button class="btn btn-danger btn-sm" type="submit"><?= icon('alert-triangle') ?>Deactivate account</button></div>
                            </form>
                        <?php endif; ?>
                    </section>
                </div>
            </div>
    <?php
    break;

// =====================================================================
case 'help':
    $email = setting('contact_email');
    $phone = setting('contact_phone');
    $cards = [
        ['help-circle', 'Frequently asked questions', 'Quick answers about donations, receipts, volunteering and support.', page_url('faq')],
        ['search', 'Track an application', 'Check the status of an application with its reference number.', page_url('application-status')],
        ['mail', 'Contact us', 'Write to our team - we usually reply by email.', page_url('contact')],
        ['flag', 'Report fraud or misuse', 'Tell us about suspicious calls, messages or accounts using our name.', page_url('report-fraud')],
        ['message', 'Raise a grievance', 'Share a concern or complaint about our work or this website.', page_url('grievance-redressal')],
        ['file-text', 'Donation & refund policy', 'How donations, receipts and refunds work.', page_url('donation-refund-policy')],
    ];
    ?>
            <div class="md-help-grid">
                <?php foreach ($cards as $i => [$ic, $t, $txt, $href]): ?>
                    <a class="md-help-card reveal" style="--d:<?= $i % 3 ?>" href="<?= e($href) ?>"><span class="md-stat-icon"><?= icon($ic) ?></span><span><strong><?= e($t) ?></strong><small><?= e($txt) ?></small></span><?= icon('chevron-right') ?></a>
                <?php endforeach; ?>
            </div>
            <section class="md-panel">
                <div class="md-panel-head"><h2>Common questions about your account</h2></div>
                <?= ui_faqs([
                    ['q' => 'Where do I find my donation receipt?', 'a' => 'Open Receipts in your dashboard. A receipt is issued automatically once a payment has been verified; you can view, print or save it as PDF from there.'],
                    ['q' => 'A donation I made is not listed. Why?', 'a' => 'Donations appear when they were made while signed in, or with the email address of your account after it has been verified. If a payment is still not shown, contact us with the payment reference.'],
                    ['q' => 'How do I change my email address or mobile number?', 'a' => 'Go to Profile. Your mobile number can be changed directly; a new email address has to be verified with the link we send to it.'],
                    ['q' => 'I think someone else used my account.', 'a' => 'Change your password under Password & Security - this signs out every other device - and let us know through the contact page.'],
                    ['q' => 'How can I delete my account?', 'a' => 'Under Privacy & Settings you can download your data and send a deletion request. Donation and receipt records are kept as required for the foundation\'s accounts.'],
                ]) ?>
            </section>
            <?php if ($email || $phone): ?>
                <section class="md-panel md-contact">
                    <div class="md-panel-head"><h2>Talk to us</h2></div>
                    <ul class="md-contact-list">
                        <?php if ($email): ?><li><?= icon('mail') ?><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></li><?php endif; ?>
                        <?php if ($phone): ?><li><?= icon('phone') ?><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a></li><?php endif; ?>
                        <?php if (setting('office_hours')): ?><li><?= icon('clock') ?><span><?= e(setting('office_hours')) ?></span></li><?php endif; ?>
                    </ul>
                    <p class="md-foot-note"><?= icon('shield') ?><span>Our team will never ask you for your password, OTP, card or bank PIN.</span></p>
                </section>
            <?php endif; ?>
    <?php
    break;

endswitch;
?>
        </div>
    </div>
</section>
<?php
$GLOBALS['extraScripts'][] = asset('assets/js/modules/member.js');
page_footer();
