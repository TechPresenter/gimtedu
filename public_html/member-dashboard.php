<?php
require_once __DIR__ . '/functions.php';

$user = require_login();
$uid = (int) $user['id'];
$totalGiven = (float) db_value("SELECT COALESCE(SUM(amount), 0) FROM donations WHERE user_id = ? AND status = 'success'", [$uid]);
$donationCount = (int) db_value("SELECT COUNT(*) FROM donations WHERE user_id = ? AND status = 'success'", [$uid]);
$applicationCount = (int) db_value('SELECT
    (SELECT COUNT(*) FROM volunteers WHERE user_id = ?) + (SELECT COUNT(*) FROM teacher_applications WHERE user_id = ?) +
    (SELECT COUNT(*) FROM staff_applications WHERE user_id = ?) + (SELECT COUNT(*) FROM scholarships WHERE user_id = ?) +
    (SELECT COUNT(*) FROM beneficiaries WHERE user_id = ?)', [$uid, $uid, $uid, $uid, $uid]);
$certificateCount = (int) db_value("SELECT COUNT(*) FROM certificates WHERE user_id = ? AND status = 'valid'", [$uid]);
$membership = db_row('SELECT * FROM members WHERE user_id = ?', [$uid]);
$recent = db_rows("SELECT d.*, c.title AS campaign_title FROM donations d LEFT JOIN campaigns c ON c.id = d.campaign_id
                   WHERE d.user_id = ? AND d.status IN ('success','refunded') ORDER BY d.created_at DESC LIMIT 5", [$uid]);
$events = db_rows("SELECT e.title, e.slug, e.start_datetime, e.location FROM event_registrations r JOIN events e ON e.id = r.event_id
                   WHERE r.user_id = ? AND e.start_datetime >= NOW() AND r.status = 'registered' ORDER BY e.start_datetime LIMIT 3", [$uid]);

$pageTitle = 'My Dashboard';
require __DIR__ . '/header.php';
page_banner('My Dashboard', 'Welcome, ' . $user['name']);
?>
<section class="section">
    <div class="container member-layout">
        <?php render_member_nav($user); ?>
        <div>
            <div class="dash-cards">
                <div class="dash-card"><span class="icon-circle gold"><?= icon('heart') ?></span><div><strong><?= e(money($totalGiven)) ?></strong><small>Total donated</small></div></div>
                <div class="dash-card"><span class="icon-circle"><?= icon('gift') ?></span><div><strong><?= $donationCount ?></strong><small>Donations</small></div></div>
                <div class="dash-card"><span class="icon-circle blue"><?= icon('file-text') ?></span><div><strong><?= $applicationCount ?></strong><small>Applications</small></div></div>
                <div class="dash-card"><span class="icon-circle"><?= icon('award') ?></span><div><strong><?= $certificateCount ?></strong><small>Certificates</small></div></div>
            </div>

            <div class="panel">
                <div class="panel-head"><h2>Membership</h2><?= $membership ? status_badge($membership['status']) : '' ?></div>
                <?php if ($membership): ?>
                    <dl class="detail-list">
                        <dt>Membership type</dt><dd><?= e(humanize($membership['membership_type'])) ?> Member</dd>
                        <dt>Membership no.</dt><dd><?= e($membership['membership_no'] ?: 'Will be issued after approval') ?></dd>
                        <?php if ($membership['valid_until']): ?><dt>Valid until</dt><dd><?= e(format_date($membership['valid_until'])) ?></dd><?php endif; ?>
                    </dl>
                    <?php if ($membership['status'] === 'rejected'): ?>
                        <p class="mt-2"><a class="btn btn-outline btn-sm" href="<?= e(url('membership.php')) ?>">Apply again</a></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="muted">You are not a member yet. Members take part in our activities and help us reach more people.</p>
                    <a class="btn btn-primary btn-sm" href="<?= e(url('membership.php#apply')) ?>">Apply for membership</a>
                <?php endif; ?>
            </div>

            <div class="panel">
                <div class="panel-head"><h2>Recent donations</h2><a class="btn btn-ghost btn-sm" href="<?= e(url('my-donations.php')) ?>">View all</a></div>
                <?php if ($recent): ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>Date</th><th>Towards</th><th>Amount</th><th>Status</th><th>Receipt</th></tr></thead>
                            <tbody>
                            <?php foreach ($recent as $d): ?>
                                <tr>
                                    <td><?= e(format_date($d['paid_at'] ?: $d['created_at'])) ?></td>
                                    <td><?= e($d['campaign_title'] ?: 'General Fund') ?></td>
                                    <td><strong><?= e(money($d['amount'], true)) ?></strong></td>
                                    <td><?= status_badge($d['status']) ?></td>
                                    <td><a href="<?= e(url('donation-receipt.php?ref=' . urlencode($d['donation_ref']))) ?>"><?= icon('download') ?> Receipt</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <?= empty_state('You have not made a donation from this account yet.', 'Donate now', 'donate.php') ?>
                <?php endif; ?>
            </div>

            <?php if ($events): ?>
                <div class="panel">
                    <div class="panel-head"><h2>Your upcoming events</h2></div>
                    <ul class="donor-list">
                        <?php foreach ($events as $ev): ?>
                            <li><a href="<?= e(url('event-details.php?slug=' . urlencode($ev['slug']))) ?>"><?= e($ev['title']) ?></a><span class="muted small"><?= e(format_date($ev['start_datetime'], 'd M Y, h:i A')) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="grid grid-3">
                <a class="work-card tone-green" href="<?= e(url('apply-volunteer.php')) ?>"><span class="work-icon"><?= icon('users') ?></span><h3>Volunteer</h3><p class="mb-0">Apply to volunteer or intern.</p></a>
                <a class="work-card tone-blue" href="<?= e(url('apply-teacher.php')) ?>"><span class="work-icon"><?= icon('graduation') ?></span><h3>Teach</h3><p class="mb-0">Become a volunteer teacher.</p></a>
                <a class="work-card tone-gold" href="<?= e(url('campaigns.php')) ?>"><span class="work-icon"><?= icon('heart') ?></span><h3>Campaigns</h3><p class="mb-0">Support an active campaign.</p></a>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
