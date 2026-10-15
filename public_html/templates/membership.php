<?php
/**
 * Membership (/membership/): page sections + membership application for signed-in users (one row per user).
 * Visitors are asked to log in or register first; members see the status of their membership.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$slug = $page['slug'];
$types = membership_types();
$consents = ['privacy', 'membership'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify(page_url($slug));
    $user = require_login();
    if (honeypot_triggered()) {
        redirect('/' . $slug . '/');
    }
    $membership = db_row('SELECT * FROM members WHERE user_id = ?', [$user['id']]);
    if ($membership && $membership['status'] !== 'rejected') {
        flash('info', 'You have already applied for membership. Its status is shown below.');
        redirect('/' . $slug . '/#apply');
    }
    if (form_rate_limited('membership', 5)) {
        flash('error', 'Too many attempts from your network. Please try again later.');
        redirect('/' . $slug . '/#apply');
    }
    [$consentRules, $consentLabels] = recruitment_consent_rules($consents);
    [$data, $errors] = validate($_POST, [
        'membership_type'   => 'required|in:' . implode(',', array_keys($types)),
        'gender'            => 'in:' . implode(',', array_keys(GENDERS)),
        'date_of_birth'     => 'required|date|past',
        'occupation'        => 'required|min:2|max:120',
        'address'           => 'required|min:5|max:255',
        'city'              => 'required|min:2|max:80',
        'state'             => 'required|in:' . implode(',', indian_states()),
        'pincode'           => 'required|pincode',
        'areas_of_interest' => 'array|max:6|in:' . implode(',', VOLUNTEER_INTERESTS),
        'motivation'        => 'required|text|min:30|max:2000',
    ] + $consentRules, $consentLabels + ['membership_type' => 'Membership type', 'date_of_birth' => 'Date of birth', 'areas_of_interest' => 'Areas of interest', 'motivation' => 'Why you want to become a member']);
    if (empty($errors['date_of_birth']) && $data['date_of_birth'] < date('Y-m-d', strtotime('-110 years'))) {
        $errors['date_of_birth'] = 'Please check your date of birth.';
    } elseif (empty($errors['date_of_birth']) && $data['date_of_birth'] > date('Y-m-d', strtotime('-18 years'))) {
        $errors['date_of_birth'] = 'Membership is open to adults (18 years or older).';
    }
    if ($errors) {
        back_with_errors($errors, page_url($slug) . '#apply', $_POST);
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
        'areas_of_interest' => $data['areas_of_interest'] ? mb_substr(implode(', ', $data['areas_of_interest']), 0, 255) : null,
        'motivation'        => $data['motivation'],
        'status'            => 'pending',
    ];
    if ($membership) {
        db_update('members', $row + ['approved_by' => null, 'approved_at' => null], 'id = :id', ['id' => $membership['id']]);
        $memberId = (int) $membership['id'];
    } else {
        $memberId = db_insert('members', $row + ['user_id' => $user['id']]);
    }
    // Fill empty profile fields from the application (never overwrite what the user already saved)
    $profile = [];
    foreach (['address', 'city', 'state', 'pincode'] as $f) {
        if (empty($user[$f])) {
            $profile[$f] = $data[$f];
        }
    }
    if ($profile) {
        db_update('users', $profile, 'id = :id', ['id' => $user['id']]);
    }
    record_application_history('membership', $memberId, 'pending', $membership ? 'Membership application submitted again.' : 'Membership application submitted online.', true);
    log_activity('form_membership', 'Membership application (' . $types[$data['membership_type']][0] . ')', (int) $user['id']);
    track_conversion('membership_application', $memberId);
    notify_applicant($user['email'], $user['name'], 'Membership application received - ' . site_name(),
        'Thank you for applying for ' . $types[$data['membership_type']][0] . " membership.\nOur team will review your application and update you. You can see the status at any time on the Membership page or in your dashboard.",
        ['user_id' => (int) $user['id'], 'link' => '/membership/', 'button' => 'View my membership', 'type' => 'success']);
    notify_admin('New membership application (' . $types[$data['membership_type']][0] . ')',
        '<p><strong>' . e($user['name']) . '</strong> (' . e($user['email']) . ') applied for ' . e($types[$data['membership_type']][0]) . ' membership.</p>'
        . '<p><a href="' . e(abs_url('admin/memberships.php?id=' . $memberId)) . '">Review the application</a></p>', $user['email']);
    flash('success', 'Thank you! Your membership application has been submitted. We will review it and let you know.');
    redirect('/' . $slug . '/#apply');
}

$user = current_user();
$membership = $user ? db_row('SELECT * FROM members WHERE user_id = ?', [$user['id']]) : null;
recruitment_form_assets();
page_header($page);
render_sections($page, function () use ($user, $membership, $slug, $types, $consents) {
    ?>
    <section class="section section-tint rc-apply-section" id="apply">
        <div class="container">
            <?php if (!$user): ?>
                <?= ui_heading('Apply for membership', 'Members', 'Membership applications are made from your free website account, so that your membership number and status are always available to you.', true) ?>
                <div class="rc-login-card form-card reveal">
                    <span class="icon-circle rc-login-icon"><?= icon('id-card') ?></span>
                    <h3>Please log in or create a free account</h3>
                    <p class="muted">Your account keeps your membership status, membership number and certificates in one place. After signing in you will return to this page to complete the short application.</p>
                    <div class="btn-row center">
                        <a class="btn btn-primary" href="<?= e(page_url('login', ['next' => '/' . $slug . '/'])) ?>"><?= icon('lock') ?>Log in</a>
                        <a class="btn btn-outline" href="<?= e(page_url('register', ['next' => '/' . $slug . '/'])) ?>"><?= icon('user') ?>Create an account</a>
                    </div>
                </div>
            <?php elseif ($membership && $membership['status'] !== 'rejected'):
                $info = recruitment_status_info($membership['status'], 'membership'); ?>
                <?= ui_heading('Your membership', 'Members', '', true) ?>
                <div class="rc-status-card form-card reveal">
                    <div class="panel-head">
                        <h3><?= e($types[$membership['membership_type']][0] ?? 'Membership') ?></h3>
                        <?= recruitment_status_badge($membership['status'], 'membership', true) ?>
                    </div>
                    <p><?= e($info[2]) ?></p>
                    <div class="kv-grid rc-kv">
                        <div class="kv"><small>Member</small><strong><?= e($user['name']) ?></strong></div>
                        <div class="kv"><small>Membership no.</small><strong><?= e($membership['membership_no'] ?: 'Issued after approval') ?></strong></div>
                        <div class="kv"><small>Applied on</small><strong><?= e(format_date($membership['created_at'])) ?></strong></div>
                        <?php if ($membership['approved_at']): ?><div class="kv"><small>Approved on</small><strong><?= e(format_date($membership['approved_at'])) ?></strong></div><?php endif; ?>
                        <?php if ($membership['valid_until']): ?><div class="kv"><small>Valid until</small><strong><?= e(format_date($membership['valid_until'])) ?></strong></div><?php endif; ?>
                    </div>
                    <h4 class="rc-subhead">Updates</h4>
                    <?= recruitment_timeline_html(application_history('membership', (int) $membership['id'], true), 'membership') ?>
                    <div class="btn-row mt-3">
                        <a class="btn btn-primary" href="<?= e(member_url()) ?>"><?= icon('grid') ?>Go to my dashboard</a>
                        <?php if (setting('contact_email') !== ''): ?><a class="btn btn-ghost" href="mailto:<?= e(setting('contact_email')) ?>"><?= icon('mail') ?>Contact us</a><?php endif; ?>
                    </div>
                </div>
            <?php else:
                $type = (string) old('membership_type', 'general'); ?>
                <?= ui_heading('Membership application', 'Apply now', 'Fields marked * are required. No payment is collected through this form.', true) ?>
                <div class="rc-apply-layout">
                    <div class="form-card rc-form-card">
                        <?php if ($membership): ?>
                            <div class="notice notice-gold mb-0 rc-form-callout"><?= icon('info') ?><div>Your previous membership application was not approved. You are welcome to apply again with updated details.</div></div>
                        <?php endif; ?>
                        <?= recruitment_form_open($slug) ?>
                            <p class="rc-applying-as"><?= icon('user') ?>Applying as <strong><?= e($user['name']) ?></strong> (<?= e($user['email']) ?>)</p>
                            <h3 class="form-section-title"><?= icon('id-card') ?>Membership type</h3>
                            <div class="rc-type-options" role="radiogroup" aria-label="Membership type">
                                <?php foreach ($types as $k => [$label, $desc]): ?>
                                    <label class="rc-type-option"><input type="radio" name="membership_type" value="<?= e($k) ?>"<?= $type === $k ? ' checked' : '' ?> required>
                                        <span><strong><?= e($label) ?></strong><small><?= e($desc) ?></small></span></label>
                                <?php endforeach; ?>
                            </div>
                            <?= field_error('membership_type') ?>

                            <h3 class="form-section-title"><?= icon('user') ?>About you</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="date_of_birth">Date of birth<span class="req">*</span></label>
                                    <input id="date_of_birth" type="date" name="date_of_birth" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('date_of_birth')) ?>">
                                    <?= field_error('date_of_birth') ?>
                                </div>
                                <div class="form-group">
                                    <label for="gender">Gender</label>
                                    <select id="gender" name="gender"><?= options_html(GENDERS, (string) old('gender')) ?></select>
                                    <?= field_error('gender') ?>
                                </div>
                                <div class="form-group full">
                                    <label for="occupation">Occupation<span class="req">*</span></label>
                                    <input id="occupation" type="text" name="occupation" required maxlength="120" placeholder="e.g. Teacher, student, business owner" value="<?= e(old('occupation')) ?>">
                                    <?= field_error('occupation') ?>
                                </div>
                                <div class="form-group full">
                                    <label for="address">Address<span class="req">*</span></label>
                                    <input id="address" type="text" name="address" required maxlength="255" autocomplete="street-address" value="<?= e(old('address', (string) ($user['address'] ?? ''))) ?>">
                                    <?= field_error('address') ?>
                                </div>
                                <div class="form-group">
                                    <label for="city">City / Town<span class="req">*</span></label>
                                    <input id="city" type="text" name="city" required maxlength="80" autocomplete="address-level2" value="<?= e(old('city', (string) ($user['city'] ?? ''))) ?>">
                                    <?= field_error('city') ?>
                                </div>
                                <div class="form-group">
                                    <label for="state">State<span class="req">*</span></label>
                                    <select id="state" name="state" required><?= state_options((string) old('state', (string) ($user['state'] ?? ''))) ?></select>
                                    <?= field_error('state') ?>
                                </div>
                                <div class="form-group">
                                    <label for="pincode">PIN code<span class="req">*</span></label>
                                    <input id="pincode" type="text" name="pincode" required inputmode="numeric" maxlength="6" data-rule="pincode" autocomplete="postal-code" value="<?= e(old('pincode', (string) ($user['pincode'] ?? ''))) ?>">
                                    <?= field_error('pincode') ?>
                                </div>
                            </div>

                            <h3 class="form-section-title"><?= icon('heart') ?>Your interests</h3>
                            <div class="form-grid">
                                <div class="form-group full" role="group" aria-labelledby="aoi-label">
                                    <span class="label" id="aoi-label">Areas of interest (up to 6)</span>
                                    <?= chips_html('areas_of_interest', VOLUNTEER_INTERESTS, (array) old('areas_of_interest', [])) ?>
                                    <?= field_error('areas_of_interest') ?>
                                </div>
                                <div class="form-group full">
                                    <label for="motivation">Why would you like to become a member?<span class="req">*</span></label>
                                    <textarea id="motivation" name="motivation" rows="4" required minlength="30" maxlength="2000" data-counter><?= e(old('motivation')) ?></textarea>
                                    <?= field_error('motivation') ?>
                                </div>
                            </div>

                            <?= recruitment_consent_html($consents) ?>
                            <div class="form-actions">
                                <button class="btn btn-primary btn-lg" type="submit" data-loading="Submitting…"><?= icon('send') ?>Submit application</button>
                            </div>
                        </form>
                    </div>
                    <?= recruitment_apply_aside([
                        ['title' => 'Apply', 'text' => 'From your website account.'],
                        ['title' => 'Review', 'text' => 'Our team reviews every application.'],
                        ['title' => 'Approval', 'text' => 'You receive a membership number.'],
                        ['title' => 'Stay involved', 'text' => 'Updates, events and volunteering.'],
                    ]) ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <?php
});
page_footer();
