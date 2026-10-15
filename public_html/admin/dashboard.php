<?php
require_once __DIR__ . '/includes/auth.php';

$stats = [];
if (admin_can('donations') || admin_can('reports')) {
    $stats['raised'] = (float) db_value("SELECT COALESCE(SUM(amount), 0) FROM donations WHERE status = 'success'");
    $stats['month'] = (float) db_value("SELECT COALESCE(SUM(amount), 0) FROM donations WHERE status = 'success' AND paid_at >= ?", [date('Y-m-01 00:00:00')]);
    $stats['donations'] = (int) db_value("SELECT COUNT(*) FROM donations WHERE status = 'success'");
    $stats['pending_pay'] = (int) db_value("SELECT COUNT(*) FROM donations WHERE status = 'pending' AND created_at < NOW() - INTERVAL 30 MINUTE AND created_at > NOW() - INTERVAL 7 DAY");
    // Last 6 months of verified donations for the chart
    $chart = [];
    for ($i = 5; $i >= 0; $i--) {
        $start = date('Y-m-01', strtotime("-$i month", strtotime(date('Y-m-01'))));
        $end = date('Y-m-01', strtotime('+1 month', strtotime($start)));
        $chart[date('M y', strtotime($start))] = (float) db_value("SELECT COALESCE(SUM(amount), 0) FROM donations WHERE status = 'success' AND paid_at >= ? AND paid_at < ?", [$start, $end]);
    }
    $recentDonations = db_rows("SELECT d.*, c.title AS campaign_title FROM donations d LEFT JOIN campaigns c ON c.id = d.campaign_id
                                WHERE d.status = 'success' ORDER BY d.paid_at DESC LIMIT 6");
}
if (admin_can('campaigns')) {
    $stats['campaigns'] = (int) db_value("SELECT COUNT(*) FROM campaigns WHERE status = 'active'");
}
if (admin_can('applications')) {
    $stats['apps'] = (int) db_value("SELECT (SELECT COUNT(*) FROM volunteers WHERE status IN ('pending','under_review'))
        + (SELECT COUNT(*) FROM teacher_applications WHERE status IN ('pending','under_review'))
        + (SELECT COUNT(*) FROM staff_applications WHERE status IN ('pending','under_review'))");
    $recentApps = db_rows("SELECT * FROM (
            SELECT 'Volunteer' AS kind, id, application_no, name, created_at, status, 'volunteer-view.php' AS page FROM volunteers
            UNION ALL SELECT 'Teacher', id, application_no, name, created_at, status, 'teacher-view.php' FROM teacher_applications
            UNION ALL SELECT 'Staff', id, application_no, name, created_at, status, 'staff-applications.php' FROM staff_applications
        ) a ORDER BY created_at DESC LIMIT 6");
}
if (admin_can('members')) {
    $stats['users'] = (int) db_value('SELECT COUNT(*) FROM users');
    $stats['members_pending'] = (int) db_value("SELECT COUNT(*) FROM members WHERE status = 'pending'");
}
if (admin_can('beneficiaries')) {
    $stats['help'] = (int) db_value("SELECT (SELECT COUNT(*) FROM beneficiaries WHERE status IN ('pending','under_review')) + (SELECT COUNT(*) FROM scholarships WHERE status IN ('pending','under_review'))");
}
if (admin_can('enquiries')) {
    $stats['enquiries'] = (int) db_value("SELECT COUNT(*) FROM contact_enquiries WHERE status = 'new'");
    $stats['subscribers'] = (int) db_value("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'subscribed'");
}

$adminTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
    <div><h2 style="margin:0;font-size:1.3rem">Welcome, <?= e($admin['name']) ?></h2><p class="muted small mb-0"><?= e(date('l, d F Y')) ?> · Role: <?= e($admin['role_name']) ?></p></div>
    <div class="actions">
        <?php if (admin_can('campaigns')): ?><a class="btn btn-donate btn-sm" href="campaign-add.php"><?= icon('plus') ?>New campaign</a><?php endif; ?>
        <?php if (admin_can('content')): ?><a class="btn btn-primary btn-sm" href="events.php?action=add"><?= icon('calendar') ?>New event</a><?php endif; ?>
    </div>
</div>

<div class="stat-cards">
    <?php if (isset($stats['raised'])): ?>
        <a class="stat-card" href="donations.php?status=success"><span class="icon-circle gold"><?= icon('rupee') ?></span><div><strong><?= e(money($stats['raised'])) ?></strong><small>Total verified donations</small></div></a>
        <a class="stat-card" href="donations.php?status=success&amp;from=<?= date('Y-m-01') ?>"><span class="icon-circle"><?= icon('trending-up') ?></span><div><strong><?= e(money($stats['month'])) ?></strong><small>Received this month</small></div></a>
        <a class="stat-card" href="donations.php?status=success"><span class="icon-circle blue"><?= icon('heart') ?></span><div><strong><?= number_format($stats['donations']) ?></strong><small>Successful donations</small></div></a>
    <?php endif; ?>
    <?php if (isset($stats['campaigns'])): ?>
        <a class="stat-card" href="campaigns.php?status=active"><span class="icon-circle"><?= icon('target') ?></span><div><strong><?= $stats['campaigns'] ?></strong><small>Active campaigns</small></div></a>
    <?php endif; ?>
    <?php if (isset($stats['apps'])): ?>
        <a class="stat-card" href="volunteers.php?status=pending"><span class="icon-circle gold"><?= icon('users') ?></span><div><strong><?= $stats['apps'] ?></strong><small>Applications to review</small></div></a>
    <?php endif; ?>
    <?php if (isset($stats['help'])): ?>
        <a class="stat-card" href="beneficiaries.php?status=pending"><span class="icon-circle blue"><?= icon('package') ?></span><div><strong><?= $stats['help'] ?></strong><small>Help & scholarship requests</small></div></a>
    <?php endif; ?>
    <?php if (isset($stats['users'])): ?>
        <a class="stat-card" href="members.php"><span class="icon-circle"><?= icon('user') ?></span><div><strong><?= number_format($stats['users']) ?></strong><small>Registered users<?= $stats['members_pending'] ? ' · ' . $stats['members_pending'] . ' membership pending' : '' ?></small></div></a>
    <?php endif; ?>
    <?php if (isset($stats['enquiries'])): ?>
        <a class="stat-card" href="contact-enquiries.php?status=new"><span class="icon-circle gold"><?= icon('inbox') ?></span><div><strong><?= $stats['enquiries'] ?></strong><small>New enquiries · <?= number_format($stats['subscribers']) ?> subscribers</small></div></a>
    <?php endif; ?>
</div>

<?php if (!empty($stats['pending_pay'])): ?>
    <div class="alert alert-warning mb-3"><?= icon('alert-circle') ?><div><?= $stats['pending_pay'] ?> online donation(s) from the last 7 days are still pending. Open them from <a href="donations.php?status=pending">Donations</a> and use “Re-check with gateway” if a donor reports a deduction.</div></div>
<?php endif; ?>

<div class="admin-grid-2">
    <?php if (isset($chart)): ?>
        <div class="panel">
            <div class="panel-head"><h3>Verified donations — last 6 months</h3></div>
            <?php $max = max(1, max($chart)); ?>
            <div class="chart-bars">
                <?php foreach ($chart as $label => $value): ?>
                    <div class="chart-bar" title="<?= e($label . ': ' . money($value)) ?>">
                        <em><?= $value > 0 ? e(money($value)) : '' ?></em>
                        <span class="bar" style="height:<?= max(2, round($value / $max * 100)) ?>%"></span>
                        <small><?= e($label) ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="panel">
            <div class="panel-head"><h3>Latest verified donations</h3><a class="btn btn-ghost btn-sm" href="donations.php">All</a></div>
            <?php if ($recentDonations): ?>
                <div class="table-wrap"><table class="table">
                    <tbody>
                    <?php foreach ($recentDonations as $d): ?>
                        <tr>
                            <td><a href="donation-view.php?id=<?= (int) $d['id'] ?>"><strong><?= e($d['donor_name']) ?></strong></a><br><small class="muted"><?= e($d['campaign_title'] ?: 'General Fund') ?></small></td>
                            <td class="text-right"><strong><?= e(money($d['amount'])) ?></strong><br><small class="muted"><?= e(format_date($d['paid_at'], 'd M, h:i A')) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php else: ?>
                <p class="muted">No verified donations yet.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if (isset($recentApps)): ?>
        <div class="panel">
            <div class="panel-head"><h3>Latest applications</h3></div>
            <?php if ($recentApps): ?>
                <div class="table-wrap"><table class="table">
                    <tbody>
                    <?php foreach ($recentApps as $a): ?>
                        <tr>
                            <td><a href="<?= e($a['page']) ?>?id=<?= (int) $a['id'] ?>"><strong><?= e($a['name']) ?></strong></a><br><small class="muted"><?= e($a['kind']) ?> · <?= e($a['application_no']) ?></small></td>
                            <td class="text-right"><?= status_badge($a['status']) ?><br><small class="muted"><?= e(format_date($a['created_at'])) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php else: ?>
                <p class="muted">No applications yet.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
