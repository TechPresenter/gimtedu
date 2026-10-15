<?php
require_once __DIR__ . '/functions.php';

$types = ['general' => 'General Member', 'volunteer' => 'Volunteer Member', 'student' => 'Student Member', 'life' => 'Life Member'];
$user = current_user();
$membership = $user ? db_row('SELECT * FROM members WHERE user_id = ?', [$user['id']]) : null;

// Membership application (logged-in users only)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify('membership.php');
    $user = require_login();
    $membership = db_row('SELECT * FROM members WHERE user_id = ?', [$user['id']]);
    if ($membership && $membership['status'] !== 'rejected') {
        flash('info', 'You have already applied for membership.');
        redirect('membership.php');
    }
    [$data, $errors] = validate($_POST, personal_rules(['address', 'city', 'state', 'pincode', 'date_of_birth']) + [
        'membership_type'   => 'required|in:' . implode(',', array_keys($types)),
        'occupation'        => 'required|min:2|max:120',
        'areas_of_interest' => 'array|in:' . implode(',', VOLUNTEER_INTERESTS),
        'motivation'        => 'required|text|min:20|max:2000',
        'accept'            => 'accepted',
    ], ['accept' => 'Membership declaration', 'date_of_birth' => 'Date of birth']);
    if ($errors) {
        back_with_errors($errors, 'membership.php#apply', $_POST);
    }
    $row = [
        'membership_type'   => $data['membership_type'],
        'gender'            => $data['gender'] ?: null,
        'date_of_birth'     => $data['date_of_birth'],
        'occupation'        => $data['occupation'],
        'address'           => $data['address'],
        'city'              => $data['city'],
        'state'             => $data['state'],
        'pincode'           => $data['pincode'],
        'areas_of_interest' => implode(', ', $data['areas_of_interest']) ?: null,
        'motivation'        => $data['motivation'],
        'status'            => 'pending',
    ];
    if ($membership) {
        db_update('members', $row + ['admin_notes' => null, 'approved_by' => null, 'approved_at' => null], 'id = :id', ['id' => $membership['id']]);
    } else {
        db_insert('members', $row + ['user_id' => $user['id']]);
    }
    db_update('users', ['address' => $data['address'], 'city' => $data['city'], 'state' => $data['state'], 'pincode' => $data['pincode']], 'id = :id', ['id' => $user['id']]);
    log_activity('membership_applied', $types[$data['membership_type']], null, (int) $user['id']);
    send_mail($user['email'], 'Membership application received',
        '<p>Dear ' . e($user['name']) . ',</p><p>Thank you for applying for <strong>' . e($types[$data['membership_type']]) . '</strong> membership. We will review your application and update you. You can see the status in your dashboard.</p>');
    notify_admin('New membership application', '<p>' . e($user['name']) . ' (' . e($user['email']) . ') applied for ' . e($types[$data['membership_type']]) . ' membership.</p>');
    flash('success', 'Your membership application has been submitted. We will review it shortly.');
    redirect('member-dashboard.php');
}

$page = require_page('membership');
$pageTitle = $page['title'];
$pageDescription = $page['meta_description'];
require __DIR__ . '/header.php';
page_banner($page['title'], (string) $page['subtitle'], ['Get Involved' => null]);
?>
<section class="section">
    <div class="container">
        <div class="split">
            <article class="prose"><?= $page['content'] ?></article>
            <div id="apply">
                <?php if (!$user): ?>
                    <div class="form-card text-center">
                        <span class="icon-circle" style="width:60px;height:60px;margin:0 auto 12px"><?= icon('users') ?></span>
                        <h2>Apply for membership</h2>
                        <p class="muted">Please create a free account or log in to apply. Your membership status and number will appear in your dashboard.</p>
                        <div class="form-actions" style="justify-content:center">
                            <a class="btn btn-primary" href="<?= e(url('register.php')) ?>">Create Account</a>
                            <a class="btn btn-outline" href="<?= e(url('login.php?next=membership.php')) ?>">Log In</a>
                        </div>
                    </div>
                <?php elseif ($membership && $membership['status'] !== 'rejected'): ?>
                    <div class="form-card">
                        <div class="panel-head"><h2>Your membership</h2><?= status_badge($membership['status']) ?></div>
                        <dl class="detail-list">
                            <dt>Type</dt><dd><?= e($types[$membership['membership_type']]) ?></dd>
                            <dt>Membership no.</dt><dd><?= e($membership['membership_no'] ?: 'Issued after approval') ?></dd>
                            <dt>Applied on</dt><dd><?= e(format_date($membership['created_at'])) ?></dd>
                            <?php if ($membership['valid_until']): ?><dt>Valid until</dt><dd><?= e(format_date($membership['valid_until'])) ?></dd><?php endif; ?>
                        </dl>
                        <a class="btn btn-outline mt-3" href="<?= e(url('member-dashboard.php')) ?>">Go to dashboard</a>
                    </div>
                <?php else: ?>
                    <div class="form-card">
                        <h2>Membership application</h2>
                        <?php if ($membership): ?>
                            <div class="notice notice-gold mb-2"><?= icon('info') ?><div>Your previous application was not approved. You may apply again.</div></div>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('membership.php')) ?>" data-validate>
                            <?= csrf_field() ?>
                            <div class="form-grid">
                                <div class="form-group full">
                                    <label for="membership_type">Membership type<span class="req">*</span></label>
                                    <select id="membership_type" name="membership_type" required><?= options_html($types, (string) old('membership_type')) ?></select>
                                    <?= field_error('membership_type') ?>
                                </div>
                                <div class="form-group">
                                    <label for="date_of_birth">Date of birth<span class="req">*</span></label>
                                    <input id="date_of_birth" type="date" name="date_of_birth" required max="<?= date('Y-m-d') ?>" value="<?= e(old('date_of_birth')) ?>">
                                    <?= field_error('date_of_birth') ?>
                                </div>
                                <div class="form-group">
                                    <label for="gender">Gender</label>
                                    <select id="gender" name="gender"><?= options_html(GENDERS, (string) old('gender')) ?></select>
                                </div>
                                <div class="form-group full">
                                    <label for="occupation">Occupation<span class="req">*</span></label>
                                    <input id="occupation" type="text" name="occupation" required maxlength="120" value="<?= e(old('occupation')) ?>">
                                    <?= field_error('occupation') ?>
                                </div>
                                <div class="form-group full">
                                    <label for="address">Address<span class="req">*</span></label>
                                    <input id="address" type="text" name="address" required maxlength="255" value="<?= e(old('address', $user['address'] ?? '')) ?>">
                                    <?= field_error('address') ?>
                                </div>
                                <div class="form-group">
                                    <label for="city">City<span class="req">*</span></label>
                                    <input id="city" type="text" name="city" required maxlength="80" value="<?= e(old('city', $user['city'] ?? '')) ?>">
                                    <?= field_error('city') ?>
                                </div>
                                <div class="form-group">
                                    <label for="state">State<span class="req">*</span></label>
                                    <select id="state" name="state" required><?= state_options((string) old('state', $user['state'] ?? '')) ?></select>
                                    <?= field_error('state') ?>
                                </div>
                                <div class="form-group">
                                    <label for="pincode">PIN code<span class="req">*</span></label>
                                    <input id="pincode" type="text" name="pincode" required inputmode="numeric" maxlength="6" data-rule="pincode" value="<?= e(old('pincode', $user['pincode'] ?? '')) ?>">
                                    <?= field_error('pincode') ?>
                                </div>
                                <div class="form-group full">
                                    <span class="label">Areas of interest</span>
                                    <?= chips_html('areas_of_interest', VOLUNTEER_INTERESTS, (array) old('areas_of_interest', [])) ?>
                                </div>
                                <div class="form-group full">
                                    <label for="motivation">Why would you like to become a member?<span class="req">*</span></label>
                                    <textarea id="motivation" name="motivation" required minlength="20" maxlength="2000"><?= e(old('motivation')) ?></textarea>
                                    <?= field_error('motivation') ?>
                                </div>
                                <div class="form-group full">
                                    <label class="checkbox"><input type="checkbox" name="accept" value="1" required> <span>I agree to support the objectives of the foundation and abide by its policies.</span></label>
                                    <?= field_error('accept') ?>
                                </div>
                            </div>
                            <div class="form-actions"><button class="btn btn-primary btn-lg" type="submit">Submit Application</button></div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
