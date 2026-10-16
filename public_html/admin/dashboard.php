<?php
/**
 * Admin dashboard: key figures for the selected period, verified donations chart, recent activity,
 * quick actions and (for administrators) the set-up checklist. Every widget is shown only to staff
 * whose role includes the matching permission. Money figures count verified donations only (status = success).
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('dashboard.view');
// The data-retention clean-up promised in the Privacy Policy runs automatically at most once a day.
if (function_exists('retention_auto_run')) {
    retention_auto_run();
}

$range = admin_date_range('30d');
[$from, $to] = insights_bounds($range);
$me = current_admin();
$fyStart = ((int) date('n') >= 4 ? date('Y') : (string) ((int) date('Y') - 1)) . '-04-01';
$fyLabel = substr($fyStart, 0, 4) . '-' . substr((string) ((int) substr($fyStart, 0, 4) + 1), -2);
$appTypes = ['volunteer' => ['volunteer_applications', 'Volunteers', 'heart'], 'teacher' => ['teacher_applications', 'Volunteer teachers', 'book'],
    'staff' => ['staff_applications', 'Staff', 'briefcase'], 'internship' => ['internship_applications', 'Internships', 'award']];

$kpis = [];
$kpi = function (string $icon, string $tone, string $value, string $label, string $sub = '', string $url = '') use (&$kpis): void {
    $kpis[] = compact('icon', 'tone', 'value', 'label', 'sub', 'url');
};

if (admin_can('donations.view')) {
    $inRange = db_row("SELECT COALESCE(SUM(amount), 0) total, COUNT(*) n FROM donations WHERE status = 'success' AND paid_at BETWEEN ? AND ?", [$from, $to]);
    $fy = db_row("SELECT COALESCE(SUM(amount), 0) total, COUNT(*) n FROM donations WHERE status = 'success' AND paid_at >= ?", [$fyStart . ' 00:00:00']);
    $kpi('rupee', 'gold', money($inRange['total']), 'Confirmed donations · ' . $range['label'], number_format((int) $inRange['n']) . ' verified donation' . ((int) $inRange['n'] === 1 ? '' : 's'), admin_url('donations.php?status=success'));
    $kpi('trending-up', '', money($fy['total']), 'Financial year ' . $fyLabel . ' to date', number_format((int) $fy['n']) . ' verified donation' . ((int) $fy['n'] === 1 ? '' : 's'), admin_url('reports.php?tab=donations&range=fy'));
    $pendingOnline = (int) db_value("SELECT COUNT(*) FROM donations WHERE status = 'pending' AND payment_method = 'online'
                                     AND created_at < NOW() - INTERVAL 30 MINUTE AND created_at > NOW() - INTERVAL 7 DAY");
    $mode = insights_group($range);
    $chart = insights_series(array_column(db_rows('SELECT ' . insights_group_sql('paid_at', $mode) . " k, SUM(amount) v FROM donations
                             WHERE status = 'success' AND paid_at BETWEEN ? AND ? GROUP BY k", [$from, $to]), 'v', 'k'), $range, $mode);
    $recentDonations = db_rows("SELECT d.id, d.donation_ref, d.donor_name, d.is_anonymous, d.amount, d.paid_at, d.payment_method, c.title AS campaign_title
                                FROM donations d LEFT JOIN campaigns c ON c.id = d.campaign_id
                                WHERE d.status = 'success' ORDER BY d.paid_at DESC, d.id DESC LIMIT 5");
}
if (admin_can('receipts.manage')) {
    $receiptsRange = (int) db_value("SELECT COUNT(*) FROM donation_receipts WHERE status = 'issued' AND issued_at BETWEEN ? AND ?", [$from, $to]);
    $receiptsAll = (int) db_value("SELECT COUNT(*) FROM donation_receipts WHERE status = 'issued'");
    $kpi('file-text', 'blue', number_format($receiptsRange), 'Receipts issued · ' . $range['label'], number_format($receiptsAll) . ' issued in total', admin_url('receipts.php'));
}
if (admin_can('campaigns.manage')) {
    $kpi('heart', '', number_format((int) db_value("SELECT COUNT(*) FROM campaigns WHERE status = 'active'")), 'Active campaigns',
        number_format((int) db_value("SELECT COUNT(*) FROM campaigns WHERE status = 'completed'")) . ' completed', admin_url('campaigns.php?status=active'));
}
if (admin_can('campaigns.proposals')) {
    $kpi('flag', 'gold', number_format((int) db_value("SELECT COUNT(*) FROM campaigns WHERE status = 'proposal'")), 'Fundraiser proposals to review', '', admin_url('proposals.php'));
}
if (admin_can('members.view')) {
    $kpi('users', 'blue', number_format((int) db_value('SELECT COUNT(*) FROM users')), 'Registered users',
        '+' . number_format((int) db_value('SELECT COUNT(*) FROM users WHERE created_at BETWEEN ? AND ?', [$from, $to])) . ' new · ' . $range['label'], admin_url('users.php'));
}
if (admin_can('recruitment.view')) {
    $activeVol = (int) db_value("SELECT COUNT(*) FROM volunteer_applications WHERE status = 'active'");
    $activeTeach = (int) db_value("SELECT COUNT(*) FROM teacher_applications WHERE status = 'active'");
    $kpi('award', '', number_format($activeVol + $activeTeach), 'Active volunteers & teachers', $activeVol . ' volunteer' . ($activeVol === 1 ? '' : 's') . ' · ' . $activeTeach . ' teacher' . ($activeTeach === 1 ? '' : 's'), admin_url('applications.php?type=volunteer&status=active'));
    $pendingApps = [];
    foreach ($appTypes as $type => [$table, $label, $ic]) {
        $pendingApps[$type] = (int) db_value("SELECT COUNT(*) FROM `$table` WHERE status IN ('submitted','under_review')");
    }
    $kpi('inbox', 'gold', number_format(array_sum($pendingApps)), 'Applications awaiting review', 'Submitted or under review', admin_url('applications.php?type=volunteer&status=submitted'));
    $latestApps = db_rows("SELECT * FROM (
            SELECT 'volunteer' AS type, id, application_no, name, status, created_at FROM volunteer_applications
            UNION ALL SELECT 'teacher', id, application_no, name, status, created_at FROM teacher_applications
            UNION ALL SELECT 'staff', id, application_no, name, status, created_at FROM staff_applications
            UNION ALL SELECT 'internship', id, application_no, name, status, created_at FROM internship_applications
        ) a ORDER BY created_at DESC, id DESC LIMIT 5");
}
if (admin_can('beneficiaries.manage')) {
    $help = (int) db_value("SELECT COUNT(*) FROM beneficiaries WHERE status IN ('pending','under_review')");
    $sch = (int) db_value("SELECT COUNT(*) FROM scholarships WHERE status IN ('pending','under_review')");
    $kpi('life-buoy', 'blue', number_format($help), 'Help requests pending', $sch . ' scholarship application' . ($sch === 1 ? '' : 's') . ' pending', admin_url('beneficiaries.php?status=pending'));
}
if (admin_can('enquiries.manage')) {
    $kpi('mail', 'gold', number_format((int) db_value("SELECT COUNT(*) FROM contact_enquiries WHERE status = 'new'")), 'New enquiries',
        number_format((int) db_value('SELECT COUNT(*) FROM contact_enquiries WHERE created_at BETWEEN ? AND ?', [$from, $to])) . ' received · ' . $range['label'], admin_url('enquiries.php?status=new'));
}
if (admin_can('analytics.view')) {
    $traffic = db_row("SELECT COUNT(*) views, COUNT(DISTINCT visitor_hash, DATE(created_at)) visitors FROM page_views WHERE device <> 'bot' AND created_at BETWEEN ? AND ?", [$from, $to]);
    $kpi('eye', '', number_format((int) $traffic['views']), 'Page views · ' . $range['label'], number_format((int) $traffic['visitors']) . ' unique visitors', admin_url('analytics.php?' . http_build_query(array_intersect_key($_GET, ['range' => 1, 'from' => 1, 'to' => 1]))));
}
if (admin_can('events.manage')) {
    $upcomingEvents = db_rows("SELECT e.id, e.title, e.slug, e.start_datetime, e.end_datetime, e.registration_enabled, e.location, e.event_type, e.capacity,
                                      (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status <> 'cancelled') AS registered
                               FROM events e WHERE e.status = 'published' AND e.start_datetime >= NOW() ORDER BY e.start_datetime LIMIT 5");
}
if (admin_can('logs.view')) {
    $staffActivity = db_rows('SELECT l.action, l.details, l.created_at, u.name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
                              WHERE l.is_staff = 1 ORDER BY l.id DESC LIMIT 8');
}
$checklist = is_administrator() ? insights_setup_checklist() : [];
$heroShortcut = admin_can('pages.manage') && function_exists('home_hero_admin_summary') ? home_hero_admin_summary() : null;

$quick = array_values(array_filter([
    admin_can('campaigns.manage') ? ['New campaign', 'campaign-edit.php', 'plus', 'donate'] : null,
    admin_can('donations.manage') ? ['Donations & reconciliation', 'donations.php', 'rupee', 'primary'] : null,
    admin_can('events.manage') ? ['New event', 'event-edit.php', 'calendar', 'primary'] : null,
    admin_can('blog.manage') ? ['Write an article', 'blog-edit.php', 'edit', 'primary'] : null,
    admin_can('recruitment.view') ? ['Review applications', 'applications.php?type=volunteer&status=submitted', 'users', 'primary'] : null,
    admin_can('pages.manage') ? ['Edit pages & home page', 'pages.php', 'file-text', 'ghost'] : null,
    admin_can('reports.view') ? ['Reports & exports', 'reports.php', 'bar-chart', 'ghost'] : null,
    admin_can('settings.manage') ? ['Website settings', 'settings.php', 'sliders', 'ghost'] : null,
]));

admin_page_start('Dashboard');
?>
<div class="dash-welcome">
    <div>
        <h2>Namaste, <?= e(strtok((string) $me['name'], ' ')) ?></h2>
        <p class="muted small mb-0"><?= e(date('l, d F Y')) ?> · <?= e($me['role_name']) ?> · Showing <strong><?= e($range['label']) ?></strong></p>
    </div>
    <?= admin_range_picker($range) ?>
</div>

<?php if (!empty($pendingOnline) && admin_can('donations.manage')): ?>
    <div class="alert alert-warning mb-3"><?= icon('alert-circle') ?><div><?= (int) $pendingOnline ?> online donation<?= $pendingOnline === 1 ? ' is' : 's are' ?> still pending from the last 7 days. Open <a href="<?= e(admin_url('donations.php?status=pending')) ?>">Donations</a> to re-check them with the payment gateway.</div></div>
<?php endif; ?>

<?php if ($kpis): ?>
    <div class="stat-cards insight-kpis">
        <?php foreach ($kpis as $k): ?>
            <a class="stat-card" href="<?= e($k['url']) ?>">
                <span class="icon-circle<?= $k['tone'] ? ' ' . e($k['tone']) : '' ?>"><?= icon($k['icon']) ?></span>
                <div><strong><?= e($k['value']) ?></strong><small><?= e($k['label']) ?></small><?php if ($k['sub'] !== ''): ?><span class="kpi-sub"><?= e($k['sub']) ?></span><?php endif; ?></div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="admin-grid-main">
    <div class="insight-col">
        <?php if (isset($chart)): ?>
            <section class="panel">
                <div class="panel-head"><h2>Verified donations · <?= e($range['label']) ?></h2><span class="muted small"><?= insights_group($range) === 'day' ? 'Per day' : 'Per month' ?> · status “success” only</span></div>
                <?= insights_chart($chart, true, 'Verified donations per ' . (insights_group($range) === 'day' ? 'day' : 'month')) ?>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Recent verified donations</h2><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('donations.php?status=success')) ?>">All donations</a></div>
                <?php if ($recentDonations): ?>
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>Donor</th><th>Campaign</th><th class="text-right">Amount</th><th>Paid</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentDonations as $d): ?>
                            <tr>
                                <td><a href="<?= e(admin_url('donation-view.php?id=' . (int) $d['id'])) ?>"><strong><?= e($d['donor_name']) ?></strong></a><?= $d['is_anonymous'] ? ' <span class="badge badge-gray">Anonymous</span>' : '' ?><br><small class="muted"><?= e($d['donation_ref']) ?> · <?= e(humanize($d['payment_method'])) ?></small></td>
                                <td><?= e($d['campaign_title'] ?: 'General fund') ?></td>
                                <td class="text-right nowrap"><strong><?= e(money($d['amount'], true)) ?></strong></td>
                                <td class="nowrap"><?= e(format_date($d['paid_at'], 'd M Y, h:i A')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php else: ?>
                    <p class="muted mb-0">No verified donations yet. Confirmed online payments and offline donations recorded by staff will appear here.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (isset($latestApps)): ?>
            <section class="panel">
                <div class="panel-head"><h2>Latest applications</h2><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('applications.php?type=volunteer')) ?>">Open applications</a></div>
                <?php if ($latestApps): ?>
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>Applicant</th><th>Type</th><th>Status</th><th>Received</th></tr></thead>
                        <tbody>
                        <?php foreach ($latestApps as $a): ?>
                            <tr>
                                <td><a href="<?= e(admin_url('application-view.php?type=' . $a['type'] . '&id=' . (int) $a['id'])) ?>"><strong><?= e($a['name']) ?></strong></a><?php if ($a['application_no']): ?><br><small class="muted"><?= e($a['application_no']) ?></small><?php endif; ?></td>
                                <td><?= e($appTypes[$a['type']][1]) ?></td>
                                <td><?= status_badge($a['status']) ?></td>
                                <td class="nowrap"><?= e(format_date($a['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php else: ?>
                    <p class="muted mb-0">No applications have been received yet.</p>
                <?php endif; ?>
                <div class="pending-chips">
                    <?php foreach ($pendingApps as $type => $n): ?>
                        <a class="pending-chip<?= $n ? ' has-items' : '' ?>" href="<?= e(admin_url('applications.php?type=' . $type . '&status=submitted')) ?>"><?= icon($appTypes[$type][2]) ?><span><?= e($appTypes[$type][1]) ?></span><strong><?= (int) $n ?></strong></a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (isset($staffActivity)): ?>
            <section class="panel">
                <div class="panel-head"><h2>Recent staff activity</h2><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('activity-logs.php?scope=staff')) ?>">Audit log</a></div>
                <?php if ($staffActivity): ?>
                    <ul class="activity-feed">
                        <?php foreach ($staffActivity as $l): ?>
                            <li><span class="feed-dot" aria-hidden="true"></span><div><strong><?= e($l['name'] ?: 'Staff') ?></strong> <code><?= e($l['action']) ?></code>
                                <?php if ($l['details']): ?><span class="muted"> – <?= e(excerpt(insights_scrub($l['details']), 110)) ?></span><?php endif; ?>
                                <br><small class="muted"><?= e(format_date($l['created_at'], 'd M Y, h:i A')) ?></small></div></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="muted mb-0">No staff activity recorded yet.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (!$kpis && !isset($chart) && !isset($latestApps) && !isset($staffActivity)): ?>
            <section class="panel"><p class="mb-0">Welcome to the admin panel. Use the menu to open the sections your role allows.</p></section>
        <?php endif; ?>
    </div>

    <aside class="insight-col">
        <?php if ($heroShortcut): $hs = $heroShortcut; ?>
            <section class="panel hero-shortcut" aria-labelledby="hero-shortcut-title">
                <a class="hero-shortcut-link" href="<?= e(admin_url($hs['href'])) ?>">
                    <span class="hero-shortcut-thumbs" aria-hidden="true">
                        <?php foreach (array_slice($hs['thumbs'], 0, 4) as [$src, $isPhoto]): ?>
                            <img src="<?= e($src) ?>" alt="" width="96" height="72" loading="lazy" class="<?= $isPhoto ? 'is-photo' : 'is-art' ?>">
                        <?php endforeach; ?>
                        <?php if (!$hs['thumbs']): ?><span class="hero-shortcut-empty"><?= icon('image') ?></span><?php endif; ?>
                    </span>
                    <span class="hero-shortcut-text">
                        <strong id="hero-shortcut-title">Homepage hero slider — change slide text &amp; right-side images</strong>
                        <small><?php if (!$hs['exists']): ?>No hero slider on the home page yet - open Home › Sections to add one.<?php else: ?><?= (int) $hs['slides'] ?> slide<?= $hs['slides'] === 1 ? '' : 's' ?> · <?= (int) $hs['photos'] ?> photo<?= $hs['photos'] === 1 ? '' : 's' ?>, <?= (int) ($hs['slides'] - $hs['photos']) ?> illustration<?= $hs['slides'] - $hs['photos'] === 1 ? '' : 's' ?><?= $hs['hidden'] ? ' · hidden from the website' : '' ?><?php endif; ?></small>
                    </span>
                    <span class="btn btn-primary btn-sm hero-shortcut-btn" aria-hidden="true"><?= icon('edit') ?>Edit slides</span>
                </a>
            </section>
        <?php endif; ?>
        <?php if ($quick): ?>
            <section class="panel">
                <div class="panel-head"><h2>Quick actions</h2></div>
                <div class="quick-actions">
                    <?php foreach ($quick as [$label, $href, $ic, $style]): ?>
                        <a class="btn btn-<?= e($style) ?> btn-sm" href="<?= e(admin_url($href)) ?>"><?= icon($ic) ?><?= e($label) ?></a>
                    <?php endforeach; ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/')) ?>" target="_blank" rel="noopener"><?= icon('external') ?>View website</a>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($checklist):
            $doneCount = count(array_filter(array_column($checklist, 'done'))); ?>
            <section class="panel setup-panel">
                <div class="panel-head"><h2>Set-up checklist</h2><span class="badge <?= $doneCount === count($checklist) ? 'badge-green' : 'badge-gold' ?>"><?= $doneCount ?> of <?= count($checklist) ?> done</span></div>
                <div class="mini-progress setup-progress" aria-hidden="true"><span style="width:<?= (int) round($doneCount / count($checklist) * 100) ?>%"></span></div>
                <ul class="setup-list">
                    <?php foreach ($checklist as $c): ?>
                        <li class="<?= $c['done'] ? 'is-done' : '' ?>">
                            <span class="setup-mark"><?= icon($c['done'] ? 'check' : 'alert-circle') ?></span>
                            <div><a href="<?= e(admin_url($c['url'])) ?>"><strong><?= e($c['label']) ?></strong></a><?php if (!$c['done']): ?><small><?= e($c['hint']) ?></small><?php endif; ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if (isset($upcomingEvents)): ?>
            <section class="panel">
                <div class="panel-head"><h2>Upcoming events</h2><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('events.php')) ?>">All events</a></div>
                <?php if ($upcomingEvents): ?>
                    <ul class="event-mini-list">
                        <?php foreach ($upcomingEvents as $ev): ?>
                            <li>
                                <span class="event-mini-date"><strong><?= e(date('d', strtotime($ev['start_datetime']))) ?></strong><small><?= e(date('M', strtotime($ev['start_datetime']))) ?></small></span>
                                <div><a href="<?= e(admin_url('event-edit.php?id=' . (int) $ev['id'])) ?>"><strong><?= e($ev['title']) ?></strong></a>
                                    <small class="muted"><?= e(function_exists('event_is_all_day') && event_is_all_day($ev) ? format_date($ev['start_datetime'], 'D') . ' · All day' : format_date($ev['start_datetime'], 'D, h:i A')) ?> · <?= e($ev['location']) ?></small>
                                    <?php if ((int) $ev['registration_enabled'] === 1 || (int) $ev['registered'] > 0): ?>
                                        <small><a class="muted" href="<?= e(admin_url('event-registrations.php?event=' . (int) $ev['id'])) ?>"><?= (int) $ev['registered'] ?> registered<?= $ev['capacity'] ? ' of ' . (int) $ev['capacity'] : '' ?></a></small>
                                    <?php else: ?>
                                        <small class="muted"><?= $ev['event_type'] === 'awareness_day' ? 'Awareness day' : 'Registration off' ?></small>
                                    <?php endif; ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="muted mb-0">No upcoming published events.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </aside>
</div>
<?php
admin_page_end();
