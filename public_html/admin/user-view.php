<?php
/**
 * One user account: profile, roles, status, verification, password reset link, sign out everywhere,
 * forced password change, two-step login status, recent activity and a summary of linked records.
 * ?new=1 creates a staff account (random unusable password + emailed link to choose one).
 *
 * Rules: members.view to look; members.manage for account changes; users.manage for roles.
 * Only administrators may grant/revoke "administrator" or change an administrator's account.
 * Nobody can remove their own administrator role or suspend/deactivate themselves.
 * Non-administrators can only grant or remove roles whose permissions they hold themselves.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('members.view');

$meId = (int) admin_id();
$iAmAdmin = is_administrator($meId);
$isNew = isset($_GET['new']);
$id = (int) ($_GET['id'] ?? 0);
$allRoles = db_rows('SELECT * FROM roles ORDER BY is_staff DESC, sort_order, name');
$rolesBySlug = array_column($allRoles, null, 'slug');
$myPerms = user_permissions($meId);

/** May the signed-in staff member grant or remove this role? */
$canGrant = function (array $role) use ($iAmAdmin, $myPerms): bool {
    if (!admin_can('users.manage')) {
        return false;
    }
    if ($iAmAdmin) {
        return true;
    }
    if ($role['slug'] === 'administrator') {
        return false;
    }
    $perms = array_column(db_rows('SELECT p.perm_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?', [$role['id']]), 'perm_key');
    return !array_diff($perms, $myPerms);
};

// ---------------------------------------------------------------------
// Create a staff account
// ---------------------------------------------------------------------
if ($isNew) {
    require_permission('users.manage');
    $staffRoles = array_values(array_filter($allRoles, function ($r) { return (int) $r['is_staff'] === 1; }));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        admin_post_only('user-view.php?new=1');
        [$data, $errors] = validate($_POST, ['name' => 'required|min:2|max:120', 'email' => 'required|email', 'mobile' => 'phone'], ['mobile' => 'Mobile number']);
        $chosen = array_values(array_intersect(array_map('strval', (array) ($_POST['roles'] ?? [])), array_column($staffRoles, 'slug')));
        if (!$chosen) {
            $errors['roles'] = 'Choose at least one staff role.';
        }
        foreach ($chosen as $slug) {
            if (!$canGrant($rolesBySlug[$slug])) {
                $errors['roles'] = 'You are not allowed to grant the role "' . $rolesBySlug[$slug]['name'] . '".';
            }
        }
        $mobile = $data['mobile'] !== '' ? normalize_mobile($data['mobile']) : '';
        if (!isset($errors['email']) && db_value('SELECT COUNT(*) FROM users WHERE email = ?', [$data['email']])) {
            $errors['email'] = 'An account with this email address already exists. Open it from the user list to add a staff role.';
        }
        if ($mobile !== '' && !isset($errors['mobile']) && db_value('SELECT COUNT(*) FROM users WHERE mobile = ?', [$mobile])) {
            $errors['mobile'] = 'This mobile number is already used by another account.';
        }
        if ($errors) {
            back_with_errors($errors, 'admin/user-view.php?new=1', $_POST);
        }
        $newId = create_user(['name' => $data['name'], 'email' => $data['email'], 'mobile' => $mobile, 'password' => bin2hex(random_bytes(32)), 'status' => 'active'], [], $meId);
        foreach ($chosen as $slug) {
            assign_role($newId, $slug, $meId);
        }
        $invited = member_send_reset_link(db_row('SELECT id, name, email FROM users WHERE id = ?', [$newId]), 48, true);
        add_notification($newId, 'Welcome to the team', 'Your staff account has been created. Choose your password with the link we emailed you, then turn on two-step login in the admin panel.', null, 'info');
        admin_log('staff_user_created', 'Created staff user #' . $newId . ' (' . $data['email'] . ') with roles: ' . implode(', ', $chosen));
        if ($invited) {
            flash('success', 'Staff account created. A link to choose a password (valid for 48 hours) has been emailed to ' . $data['email'] . '.');
        } else {
            flash('warning', 'Staff account created, but the email with the link to choose a password could not be sent (check Settings > Email delivery). Use "Send password reset link" once email works.');
        }
        admin_redirect('user-view.php?id=' . $newId);
    }

    admin_page_start('Create staff user', [['label' => 'All users', 'url' => admin_url('users.php'), 'icon' => 'chevron-left', 'style' => 'ghost']]);
    $oldRoles = (array) old('roles', []);
    ?>
    <div class="admin-grid-main">
        <section class="panel">
            <div class="panel-head"><h2>New staff account</h2></div>
            <form method="post" class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr))" data-validate>
                <?= csrf_field() ?>
                <div class="form-group"><label for="name">Full name<span class="req">*</span></label><input id="name" type="text" name="name" required maxlength="120" value="<?= e(old('name')) ?>"><?= field_error('name') ?></div>
                <div class="form-group"><label for="email">Email address<span class="req">*</span></label><input id="email" type="email" name="email" required maxlength="190" value="<?= e(old('email')) ?>"><?= field_error('email') ?></div>
                <div class="form-group"><label for="mobile">Mobile number</label><input id="mobile" type="tel" name="mobile" maxlength="16" value="<?= e(old('mobile')) ?>"><?= field_error('mobile') ?></div>
                <fieldset class="form-group full" style="border:0;padding:0;margin:0;min-width:0">
                    <legend class="label">Staff roles<span class="req">*</span></legend>
                    <div class="perm-grid" style="grid-template-columns:repeat(auto-fill,minmax(min(100%,240px),1fr))">
                        <?php foreach ($staffRoles as $r): $ok = $canGrant($r); ?>
                            <label class="checkbox"><input type="checkbox" name="roles[]" value="<?= e($r['slug']) ?>"<?= in_array($r['slug'], $oldRoles, true) ? ' checked' : '' ?><?= $ok ? '' : ' disabled' ?>>
                                <span><strong><?= e($r['name']) ?></strong><br><small class="muted"><?= e((string) $r['description']) ?><?= $ok ? '' : ' (you cannot grant this role)' ?></small></span></label>
                        <?php endforeach; ?>
                    </div>
                    <?= field_error('roles') ?>
                </fieldset>
                <div class="form-actions full"><button class="btn btn-primary" type="submit"><?= icon('plus') ?>Create account &amp; email invitation</button></div>
            </form>
        </section>
        <aside class="panel">
            <div class="panel-head"><h2>How it works</h2></div>
            <ul class="health-list">
                <li class="ok"><?= icon('lock') ?><span>The account gets a random password that nobody knows - no password is ever shown or sent.</span></li>
                <li class="ok"><?= icon('mail') ?><span>The new team member receives a single-use link (valid for 48 hours) to choose their own password.</span></li>
                <li class="ok"><?= icon('shield') ?><span>Ask them to turn on two-step login under My Profile after signing in.</span></li>
                <li class="warn"><?= icon('info') ?><span>To give an existing website user a staff role, open their account from the user list instead.</span></li>
            </ul>
        </aside>
    </div>
    <?php
    admin_page_end();
    exit;
}

// ---------------------------------------------------------------------
// Existing account
// ---------------------------------------------------------------------
$user = $id > 0 ? db_row('SELECT * FROM users WHERE id = ?', [$id]) : null;
if (!$user) {
    http_response_code(404);
    admin_page_start('User not found');
    echo '<div class="panel">' . empty_state('This account does not exist.', 'Back to users', admin_url('users.php')) . '</div>';
    admin_page_end();
    exit;
}
$self = $id === $meId;
$targetIsAdmin = is_administrator($id);
$targetIsStaff = is_staff($id);
// A non-administrator may change another STAFF account only when they may manage users and hold every permission
// that account has - otherwise a members.manage holder could take over a better-privileged colleague's account.
$outranks = $iAmAdmin || (!$targetIsAdmin && admin_can('users.manage') && !array_diff(user_permissions($id), $myPerms));
$staffGuarded = $targetIsStaff && !$self && !$outranks;
$back = 'user-view.php?id=' . $id;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only($back);
    $action = (string) ($_POST['action'] ?? '');
    $deny = function (string $msg) use ($back): void {
        flash('error', $msg);
        admin_redirect($back);
    };
    if ($targetIsAdmin && !$iAmAdmin && $action !== 'send_reset') {
        $deny('Only administrators can change an administrator account.');
    }
    if ($staffGuarded && in_array($action, ['profile', 'status', 'send_reset', 'logout_everywhere', 'require_password_change', 'mark_verified', 'resend_verification'], true)) {
        admin_log('access_denied', 'Tried "' . $action . '" on staff user #' . $id . ' without holding all of its permissions');
        $deny('You can only change staff accounts whose permissions you also hold. Please ask an administrator.');
    }

    switch ($action) {
        case 'profile':
            require_permission('members.manage');
            [$data, $errors] = validate($_POST, [
                'name' => 'required|min:2|max:120', 'email' => 'required|email', 'mobile' => 'phone', 'city' => 'max:80',
                'state' => 'in:' . implode(',', indian_states()),
            ], ['mobile' => 'Mobile number']);
            $mobile = $data['mobile'] !== '' ? normalize_mobile($data['mobile']) : null;
            if (!isset($errors['email']) && db_value('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [$data['email'], $id])) {
                $errors['email'] = 'Another account already uses this email address.';
            }
            if ($mobile && !isset($errors['mobile']) && db_value('SELECT COUNT(*) FROM users WHERE mobile = ? AND id <> ?', [$mobile, $id])) {
                $errors['mobile'] = 'Another account already uses this mobile number.';
            }
            if ($errors) {
                back_with_errors($errors, 'admin/' . $back, $_POST);
            }
            $emailChanged = $data['email'] !== mb_strtolower((string) $user['email']);
            db_update('users', [
                'name' => $data['name'], 'email' => $data['email'], 'mobile' => $mobile,
                'city' => $data['city'] !== '' ? $data['city'] : null, 'state' => $data['state'] !== '' ? $data['state'] : null,
            ] + ($emailChanged ? ['email_verified_at' => null] : []), 'id = :id', ['id' => $id]);
            if ($emailChanged) {
                // links already sent to the old address (password reset, staff invitation) must stop working
                invalidate_password_resets($id);
                logout_everywhere($id);
                send_verification_email(['id' => $id, 'name' => $data['name'], 'email' => $data['email']]);
                send_mail((string) $user['email'], 'Your account email address was changed - ' . site_name(),
                    '<p>Dear ' . e($user['name']) . ',</p><p>Our team changed the email address of your ' . e(site_name()) . ' account to <strong>' . e(member_mask_email($data['email'])) . '</strong>.'
                    . ' If you did not ask for this, please <a href="' . e(abs_url('contact/')) . '">contact us</a>.</p>');
                add_notification($id, 'Email address changed', 'Our team changed your account email to ' . $data['email'] . '. Please verify it with the link we sent.', '/dashboard/profile/', 'security');
            }
            admin_log('user_updated', 'Updated profile of user #' . $id . ($emailChanged ? ' (email changed from ' . $user['email'] . ' to ' . $data['email'] . ')' : ''));
            flash('success', 'Profile saved.' . ($emailChanged ? ' A verification link was sent to the new address. Any outstanding password or invitation links were cancelled and the account was signed out; send a new link from this page if needed.' : ''));
            break;

        case 'status':
            require_permission('members.manage');
            $to = (string) ($_POST['status'] ?? '');
            if (!in_array($to, ['active', 'suspended', 'deactivated'], true) || $to === $user['status']) {
                break;
            }
            if ($self) {
                $deny('You cannot change the status of your own account.');
            }
            db_update('users', ['status' => $to], 'id = :id', ['id' => $id]);
            if ($to !== 'active') {
                logout_everywhere($id);
            }
            $msg = ['active' => 'Your ' . site_name() . ' account has been reactivated. You can sign in again.',
                'suspended' => 'Your ' . site_name() . ' account has been suspended. Please contact us if you have questions.',
                'deactivated' => 'Your ' . site_name() . ' account has been deactivated. Please contact us if you think this is a mistake.'][$to];
            send_mail((string) $user['email'], 'Your account status has changed - ' . site_name(), '<p>Dear ' . e($user['name']) . ',</p><p>' . e($msg) . '</p>');
            add_notification($id, 'Account ' . ($to === 'active' ? 'reactivated' : $to), $msg, null, 'security');
            admin_log('user_status_changed', 'User #' . $id . ' status ' . $user['status'] . ' -> ' . $to);
            flash('success', 'Account status changed to ' . $to . '.');
            break;

        case 'resend_verification':
            require_permission('members.manage');
            if (empty($user['email_verified_at'])) {
                if (send_verification_email($user)) {
                    admin_log('verification_resent', 'Sent a verification email to user #' . $id);
                    flash('success', 'A new verification link has been emailed to ' . $user['email'] . '.');
                } else {
                    admin_log('verification_resend_failed', 'Verification email to user #' . $id . ' could not be sent');
                    flash('warning', 'The verification email could not be sent' . (MAIL_ENABLED ? ' (check Settings > Email delivery)' : ' because email sending is switched off on this website') . '. You can use "Mark email as verified" after confirming the address with the user.');
                }
            }
            break;

        case 'mark_verified':
            require_permission('members.manage');
            db_query('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?', [$id]);
            admin_log('email_marked_verified', 'Marked the email of user #' . $id . ' as verified');
            flash('success', 'Email address marked as verified.');
            break;

        case 'send_reset':
            require_permission('members.manage');
            if ($user['status'] !== 'active') {
                $deny('Reactivate the account before sending a password reset link.');
            }
            if (member_send_reset_link($user)) {
                admin_log('password_reset_sent', 'Sent a password reset link to user #' . $id);
                flash('success', 'A password reset link (valid for 1 hour) has been emailed to ' . $user['email'] . '.');
            } else {
                admin_log('password_reset_failed', 'Password reset email to user #' . $id . ' could not be sent');
                flash('warning', 'The password reset email could not be sent (check Settings > Email delivery).');
            }
            break;

        case 'logout_everywhere':
            require_permission('members.manage');
            logout_everywhere($id);
            add_notification($id, 'Signed out everywhere', 'Our team signed your account out on all devices. Please sign in again.', null, 'security');
            admin_log('user_logout_everywhere', 'Signed user #' . $id . ' out on all devices');
            flash('success', 'The account has been signed out on all devices' . ($self ? ' (except this session)' : '') . '.');
            break;

        case 'require_password_change':
            require_permission('members.manage');
            $on = ($_POST['value'] ?? '1') === '1' ? 1 : 0;
            db_update('users', ['must_change_password' => $on], 'id = :id', ['id' => $id]);
            if ($on) {
                add_notification($id, 'Please choose a new password', 'For your security, please set a new password the next time you sign in.', '/dashboard/password/', 'security');
            }
            admin_log('password_change_required', ($on ? 'Required' : 'Cleared required') . ' password change for user #' . $id);
            flash('success', $on ? 'The user must choose a new password at their next sign-in.' : 'Password change requirement removed.');
            break;

        case 'totp_off':
            if (!$iAmAdmin) {
                $deny('Only administrators can turn off two-step login for another account.');
            }
            db_update('users', ['totp_secret' => null, 'totp_enabled' => 0], 'id = :id', ['id' => $id]);
            add_notification($id, 'Two-step login turned off', 'An administrator turned off two-step login for your account. Please set it up again.', null, 'security');
            admin_log('totp_reset', 'Turned off two-step login for user #' . $id);
            flash('warning', 'Two-step login has been turned off for this account.');
            break;

        case 'roles':
            require_permission('users.manage');
            $current = array_column(db_rows('SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$id]), 'slug');
            $wanted = array_values(array_intersect(array_map('strval', (array) ($_POST['roles'] ?? [])), array_keys($rolesBySlug)));
            $add = array_values(array_diff($wanted, $current));
            $remove = array_values(array_diff($current, $wanted));
            if ($self && in_array('administrator', $remove, true)) {
                $deny('You cannot remove your own administrator role.');
            }
            foreach (array_merge($add, $remove) as $slug) {
                if (!$canGrant($rolesBySlug[$slug])) {
                    $deny($slug === 'administrator' ? 'Only administrators can grant or remove the Administrator role.'
                        : 'You are not allowed to change the role "' . $rolesBySlug[$slug]['name'] . '" (it includes permissions you do not have).');
                }
            }
            if (!$add && !$remove) {
                flash('info', 'No role changes.');
                break;
            }
            foreach ($add as $slug) {
                assign_role($id, $slug, $meId);
            }
            foreach ($remove as $slug) {
                remove_role($id, $slug);
            }
            $names = member_role_names($id);
            add_notification($id, 'Your roles were updated', 'Your account roles are now: ' . ($names ? implode(', ', $names) : 'none') . '.', null, 'info');
            admin_log('user_roles_changed', 'User #' . $id . ': added [' . implode(', ', $add) . '] removed [' . implode(', ', $remove) . ']');
            flash('success', 'Roles updated.');
            break;
    }
    admin_redirect($back);
}

// ---------------------------------------------------------------------
// View
// ---------------------------------------------------------------------
$userRoles = array_column(db_rows('SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$id]), 'slug');
$roleNames = member_role_names($id);
$staff = is_staff($id);
$canManage = admin_can('members.manage') && (!$targetIsAdmin || $iAmAdmin) && !$staffGuarded;
$canRoles = admin_can('users.manage') && (!$targetIsAdmin || $iAmAdmin);
$showIp = admin_can('security.manage');

$appCounts = [];
foreach (member_application_sources() as $kind => $src) {
    $n = (int) db_value('SELECT COUNT(*) FROM `' . $src['table'] . '` WHERE user_id = ?', [$id]);
    if ($n) {
        $appCounts[$src['label']] = $n;
    }
}
$dParams = [];
$dScope = member_donation_scope($user, $dParams);
$don = db_row("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN d.status = 'success' THEN d.amount END), 0) AS total, SUM(d.status = 'success') AS ok FROM donations d WHERE $dScope", $dParams);
$events = (int) db_value('SELECT COUNT(*) FROM event_registrations WHERE user_id = ?', [$id]);
$certs = (int) db_value("SELECT COUNT(*) FROM certificates WHERE user_id = ? AND status = 'approved'", [$id]);
$tasksOpen = (int) db_value("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status IN ('assigned', 'in_progress')", [$id]);
$requests = db_rows('SELECT id, request_type, status, created_at FROM data_requests WHERE user_id = ? ORDER BY id DESC LIMIT 5', [$id]);
$activity = db_rows('SELECT action, details, ip_address, is_staff, created_at FROM activity_logs WHERE user_id = ? ORDER BY id DESC LIMIT 30', [$id]);
$remembered = (int) db_value('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ? AND expires_at > NOW()', [$id]);

admin_page_start($user['name'], [['label' => 'All users', 'url' => admin_url('users.php'), 'icon' => 'chevron-left', 'style' => 'ghost']]);
?>
<section class="panel">
    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
        <span class="avatar" style="width:64px;height:64px;font-size:1.6rem" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
        <div style="flex:1;min-width:220px">
            <h2 style="margin:0"><?= e($user['name']) ?><?= $self ? ' <span class="badge badge-blue">You</span>' : '' ?></h2>
            <p class="muted" style="margin:2px 0 6px;overflow-wrap:anywhere"><?= e($user['email']) ?><?= $user['mobile'] ? ' · ' . e($user['mobile']) : '' ?></p>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <?= member_status_badge($user['status']) ?>
                <?= member_status_badge($user['email_verified_at'] ? 'verified' : 'unverified') ?>
                <?php if ($staff): ?><span class="badge badge-blue">Staff</span><?php endif; ?>
                <?php if (!empty($user['must_change_password'])): ?><span class="badge badge-gold">Must change password</span><?php endif; ?>
            </div>
        </div>
        <dl class="detail-list kv-small" style="grid-template-columns:auto 1fr;min-width:240px">
            <dt>Member since</dt><dd><?= e(format_date($user['created_at'], 'd M Y')) ?></dd>
            <dt>Last sign-in</dt><dd><?= e(format_date($user['last_login_at'], 'd M Y, h:i A')) ?><?= $showIp && $user['last_login_ip'] ? ' · ' . e($user['last_login_ip']) : '' ?></dd>
            <dt>User ID</dt><dd>#<?= (int) $user['id'] ?></dd>
        </dl>
    </div>
</section>

<div class="admin-grid-main">
    <div>
        <section class="panel">
            <div class="panel-head"><h2>Profile</h2></div>
            <?php if ($canManage): ?>
                <form method="post" class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr))" data-validate>
                    <?= csrf_field() ?><input type="hidden" name="action" value="profile">
                    <div class="form-group"><label for="name">Name</label><input id="name" type="text" name="name" required maxlength="120" value="<?= e(old('name', $user['name'])) ?>"><?= field_error('name') ?></div>
                    <div class="form-group"><label for="email">Email</label><input id="email" type="email" name="email" required maxlength="190" value="<?= e(old('email', $user['email'])) ?>"><?= field_error('email') ?><span class="help">Changing it marks the address unverified and emails both addresses.</span></div>
                    <div class="form-group"><label for="mobile">Mobile</label><input id="mobile" type="tel" name="mobile" maxlength="16" value="<?= e(old('mobile', (string) $user['mobile'])) ?>"><?= field_error('mobile') ?></div>
                    <div class="form-group"><label for="city">City</label><input id="city" type="text" name="city" maxlength="80" value="<?= e(old('city', (string) $user['city'])) ?>"><?= field_error('city') ?></div>
                    <div class="form-group"><label for="state">State</label><select id="state" name="state"><?= state_options((string) old('state', (string) $user['state'])) ?></select></div>
                    <div class="form-group"><label for="addr">Address</label><input id="addr" type="text" value="<?= e(trim(($user['address'] ?? '') . ' ' . ($user['pincode'] ?? ''))) ?>" disabled><span class="help">Edited by the member in their dashboard.</span></div>
                    <div class="form-actions full"><button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?>Save profile</button></div>
                </form>
            <?php else: ?>
                <?php details_list(['Name' => $user['name'], 'Email' => $user['email'], 'Mobile' => $user['mobile'], 'Address' => $user['address'], 'City' => $user['city'], 'State' => $user['state'], 'PIN code' => $user['pincode']]); ?>
            <?php endif; ?>
            <p class="small muted mt-2">Communication consent: <strong><?= $user['communication_consent'] ? 'Yes' : 'No' ?></strong> · Privacy policy accepted: <strong><?= e(format_date($user['privacy_accepted_at'], 'd M Y')) ?></strong></p>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Roles</h2><?php if (admin_can('roles.manage')): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('roles.php')) ?>"><?= icon('lock') ?>Roles &amp; permissions</a><?php endif; ?></div>
            <?php if ($canRoles): ?>
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="roles">
                    <?php foreach (['Staff roles (admin panel access)' => 1, 'Member roles' => 0] as $groupLabel => $isStaffGroup): ?>
                        <h3 class="h4 mt-2"><?= e($groupLabel) ?></h3>
                        <div class="perm-grid" style="grid-template-columns:repeat(auto-fill,minmax(min(100%,240px),1fr))">
                            <?php foreach ($allRoles as $r): if ((int) $r['is_staff'] !== $isStaffGroup) { continue; }
                                $has = in_array($r['slug'], $userRoles, true);
                                $locked = !$canGrant($r) || ($self && $r['slug'] === 'administrator' && $has); ?>
                                <label class="checkbox"><input type="checkbox" name="roles[]" value="<?= e($r['slug']) ?>"<?= $has ? ' checked' : '' ?><?= $locked ? ' disabled' : '' ?>>
                                    <span><strong><?= e($r['name']) ?></strong><?= $locked ? ' ' . icon('lock') : '' ?><br><small class="muted"><?= e((string) $r['description']) ?></small></span></label>
                                <?php if ($locked && $has): ?><input type="hidden" name="roles[]" value="<?= e($r['slug']) ?>"><?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    <p class="small muted mt-2"><?= icon('info') ?> Only administrators can grant or remove the Administrator role. Locked roles include permissions you do not have.</p>
                    <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?>Save roles</button></div>
                </form>
            <?php else: ?>
                <p><?= $roleNames ? e(implode(', ', $roleNames)) : '<span class="muted">No roles</span>' ?></p>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Linked records</h2></div>
            <div class="stat-cards" style="margin-bottom:0">
                <div class="stat-card"><div><strong><?= e(money($don['total'])) ?></strong><small>Verified donations (<?= (int) $don['ok'] ?> of <?= (int) $don['n'] ?>)</small></div></div>
                <div class="stat-card"><div><strong><?= array_sum($appCounts) ?></strong><small>Applications</small></div></div>
                <div class="stat-card"><div><strong><?= $events ?></strong><small>Event registrations</small></div></div>
                <div class="stat-card"><div><strong><?= $certs ?></strong><small>Certificates</small></div></div>
                <div class="stat-card"><div><strong><?= $tasksOpen ?></strong><small>Open tasks</small></div></div>
            </div>
            <?php if ($appCounts): ?>
                <p class="small mt-2"><?php foreach ($appCounts as $label => $n): ?><span class="badge badge-gray"><?= e($label) ?>: <?= $n ?></span> <?php endforeach; ?></p>
            <?php endif; ?>
            <p class="small muted mt-2">Donations are counted by account and, once the email is verified, by the donor email address.
                <?php if (admin_can('donations.view')): ?><a href="<?= e(admin_url('donations.php?q=' . urlencode((string) $user['email']))) ?>">Open donations</a><?php endif; ?></p>
            <?php if ($requests): ?>
                <h3 class="h4 mt-2">Data requests</h3>
                <ul class="health-list">
                    <?php foreach ($requests as $rq): ?>
                        <li><?= icon('shield') ?><span>#<?= (int) $rq['id'] ?> <?= e(humanize($rq['request_type'])) ?> · <?= e(format_date($rq['created_at'])) ?> <?= member_status_badge($rq['status']) ?>
                            <?php if (admin_can('data_requests.manage')): ?> <a href="<?= e(admin_url('data-requests.php?id=' . (int) $rq['id'])) ?>">Open</a><?php endif; ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Recent activity</h2><?php if (admin_can('logs.view')): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('activity-logs.php?q=' . urlencode((string) $user['email']))) ?>">Audit log</a><?php endif; ?></div>
            <?php if (!$activity): ?>
                <p class="muted">No activity recorded yet.</p>
            <?php else: ?>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>When</th><th>Action</th><th>Details</th><?php if ($showIp): ?><th>IP</th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($activity as $a): ?>
                        <tr><td class="nowrap"><?= e(format_date($a['created_at'], 'd M Y, h:i A')) ?></td>
                            <td><?= e(humanize($a['action'])) ?><?= $a['is_staff'] ? ' <span class="badge badge-blue">staff</span>' : '' ?></td>
                            <td class="small"><?= e(excerpt((string) $a['details'], 90)) ?></td>
                            <?php if ($showIp): ?><td class="small nowrap"><?= e((string) $a['ip_address']) ?></td><?php endif; ?></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </section>
    </div>

    <aside>
        <section class="panel">
            <div class="panel-head"><h2>Account status</h2><?= member_status_badge($user['status']) ?></div>
            <?php if ($canManage && !$self): ?>
                <form method="post" class="filter-row" data-confirm="Change the status of this account?">
                    <?= csrf_field() ?><input type="hidden" name="action" value="status">
                    <select name="status" aria-label="New status">
                        <?php foreach (['active' => 'Active', 'suspended' => 'Suspended', 'deactivated' => 'Deactivated'] as $k => $label): ?>
                            <option value="<?= $k ?>"<?= $user['status'] === $k ? ' selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-primary btn-sm" type="submit">Update</button>
                </form>
                <p class="small muted">Suspending or deactivating signs the user out on every device. The user is informed by email.</p>
            <?php elseif ($self): ?>
                <p class="small muted">You cannot suspend or deactivate your own account.</p>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Sign-in &amp; security</h2></div>
            <dl class="detail-list kv-small" style="grid-template-columns:auto 1fr">
                <dt>Email</dt><dd><?= $user['email_verified_at'] ? 'Verified ' . e(format_date($user['email_verified_at'])) : 'Not verified' ?></dd>
                <dt>Two-step login</dt><dd><?= !empty($user['totp_enabled']) ? '<span class="badge badge-green">On</span>' : '<span class="badge badge-gray">Off</span>' ?></dd>
                <dt>Remembered devices</dt><dd><?= $remembered ?></dd>
            </dl>
            <?php if ($canManage): ?>
                <div style="display:grid;gap:8px;margin-top:14px">
                    <?php if (empty($user['email_verified_at'])): ?>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resend_verification"><button class="btn btn-ghost btn-sm btn-block" type="submit"><?= icon('send') ?>Resend verification email</button></form>
                        <form method="post" data-confirm="Mark this email address as verified without the user clicking the link?"><?= csrf_field() ?><input type="hidden" name="action" value="mark_verified"><button class="btn btn-ghost btn-sm btn-block" type="submit"><?= icon('check-circle') ?>Mark email as verified</button></form>
                    <?php endif; ?>
                    <?php if ($user['status'] === 'active'): ?>
                        <form method="post" data-confirm="Email a password reset link to <?= e($user['email']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="send_reset"><button class="btn btn-ghost btn-sm btn-block" type="submit"><?= icon('key') ?>Send password reset link</button></form>
                    <?php endif; ?>
                    <form method="post" data-confirm="Sign this account out on every device?"><?= csrf_field() ?><input type="hidden" name="action" value="logout_everywhere"><button class="btn btn-ghost btn-sm btn-block" type="submit"><?= icon('log-out') ?>Sign out everywhere</button></form>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="require_password_change"><input type="hidden" name="value" value="<?= !empty($user['must_change_password']) ? '0' : '1' ?>">
                        <button class="btn btn-ghost btn-sm btn-block" type="submit"><?= icon('refresh') ?><?= !empty($user['must_change_password']) ? 'Cancel required password change' : 'Require password change' ?></button></form>
                    <?php if ($iAmAdmin && !empty($user['totp_enabled']) && !$self): ?>
                        <form method="post" data-confirm="Turn off two-step login for this account (for example after a lost phone)?"><?= csrf_field() ?><input type="hidden" name="action" value="totp_off"><button class="btn btn-ghost btn-sm btn-block" type="submit"><?= icon('shield') ?>Turn off two-step login</button></form>
                    <?php endif; ?>
                </div>
            <?php elseif ($targetIsAdmin && !$iAmAdmin): ?>
                <p class="small muted mt-2">Only administrators can change an administrator account.</p>
            <?php elseif ($staffGuarded && admin_can('members.manage')): ?>
                <p class="small muted mt-2">Only an administrator, or someone who holds all of this account's permissions, can change this staff account.</p>
            <?php endif; ?>
            <p class="small muted mt-2">Passwords and two-step secrets are never displayed. Reset links are single use and expire after 1 hour.</p>
        </section>
    </aside>
</div>
<?php
admin_page_end();
