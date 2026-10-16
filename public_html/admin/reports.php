<?php
/**
 * Reports & exports. Tabs: donations, campaigns, applications, support, events, communication, newsletter.
 * Money figures count verified donations only (status = 'success'); expenses count approved expenses only.
 * CSV downloads need the "exports.run" permission (and "donations.view" for the donor-level list).
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('reports.view');

$tabs = ['donations' => ['Donations', 'rupee'], 'campaigns' => ['Campaigns', 'heart'], 'applications' => ['Applications', 'users'],
    'support' => ['Help & scholarships', 'life-buoy'], 'events' => ['Events', 'calendar'], 'communication' => ['Enquiries & feedback', 'inbox'],
    'newsletter' => ['Newsletter', 'mail']];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? (string) $_GET['tab'] : 'donations';
$range = admin_date_range($tab === 'donations' ? 'fy' : '12m');
[$from, $to] = insights_bounds($range);
$params = [$from, $to];
$export = (string) ($_GET['export'] ?? '');
$appTypes = ['volunteer' => ['volunteer_applications', 'Volunteers'], 'teacher' => ['teacher_applications', 'Volunteer teachers'],
    'staff' => ['staff_applications', 'Staff'], 'internship' => ['internship_applications', 'Internships']];

/** Reports: [key => ['title', header[], rows[][] , money column indexes[]]] built per tab, rendered as tables and CSVs. */
$reports = [];
$kpis = [];
$chart = null;
$chartMoney = false;
$chartTitle = '';
$notes = [];
$fyLabel = function (int $startYear): string { return $startYear . '-' . substr((string) ($startYear + 1), -2); };

if ($tab === 'donations') {
    $sum = db_row("SELECT COUNT(*) n, COALESCE(SUM(amount), 0) total, COALESCE(AVG(amount), 0) avg, COUNT(DISTINCT LOWER(donor_email)) donors
                   FROM donations WHERE status = 'success' AND paid_at BETWEEN ? AND ?", $params);
    $ref = db_row("SELECT COUNT(*) n, COALESCE(SUM(amount), 0) total FROM donations WHERE status = 'refunded' AND COALESCE(refunded_at, updated_at) BETWEEN ? AND ?", $params);
    $kpis = [['rupee', 'gold', money($sum['total'], true), 'Verified donations'], ['heart', '', number_format((int) $sum['n']), 'Number of verified donations'],
        ['users', 'blue', number_format((int) $sum['donors']), 'Unique donors (by email)'], ['trending-up', '', money($sum['avg']), 'Average donation'],
        ['refresh', 'blue', money($ref['total'], true), 'Refunded (' . (int) $ref['n'] . ')']];
    $byMonth = db_rows("SELECT DATE_FORMAT(paid_at, '%Y-%m') m, COUNT(*) n, SUM(amount) total FROM donations WHERE status = 'success' AND paid_at BETWEEN ? AND ? GROUP BY m ORDER BY m", $params);
    $chart = insights_series(array_column($byMonth, 'total', 'm'), $range, 'month');
    $chartMoney = true;
    $chartTitle = 'Verified donations by month';
    $reports['month'] = ['By month', ['Month', 'Donations', 'Amount (INR)'], array_map(function ($r) {
        return [date('M Y', strtotime($r['m'] . '-01')), (int) $r['n'], (float) $r['total']];
    }, $byMonth), [2]];
    $byFy = db_rows("SELECT IF(MONTH(paid_at) >= 4, YEAR(paid_at), YEAR(paid_at) - 1) fy, COUNT(*) n, SUM(amount) total, COUNT(DISTINCT LOWER(donor_email)) donors
                     FROM donations WHERE status = 'success' AND paid_at IS NOT NULL GROUP BY fy ORDER BY fy DESC");
    $reports['fy'] = ['By financial year (all time, April–March)', ['Financial year', 'Donations', 'Unique donors', 'Amount (INR)'], array_map(function ($r) use ($fyLabel) {
        return [$fyLabel((int) $r['fy']), (int) $r['n'], (int) $r['donors'], (float) $r['total']];
    }, $byFy), [3]];
    $reports['campaign'] = ['By campaign', ['Campaign', 'Donations', 'Amount (INR)'], array_map(function ($r) {
        return [$r['title'], (int) $r['n'], (float) $r['total']];
    }, db_rows("SELECT COALESCE(c.title, 'General fund') title, COUNT(*) n, SUM(d.amount) total FROM donations d LEFT JOIN campaigns c ON c.id = d.campaign_id
                WHERE d.status = 'success' AND d.paid_at BETWEEN ? AND ? GROUP BY title ORDER BY total DESC", $params)), [2]];
    $reports['method'] = ['By payment method', ['Method', 'Donations', 'Amount (INR)'], array_map(function ($r) {
        return [humanize($r['payment_method']) . ($r['gateway_method'] ? ' (' . $r['gateway_method'] . ')' : ''), (int) $r['n'], (float) $r['total']];
    }, db_rows("SELECT payment_method, COALESCE(gateway_method, '') gateway_method, COUNT(*) n, SUM(amount) total FROM donations
                WHERE status = 'success' AND paid_at BETWEEN ? AND ? GROUP BY payment_method, gateway_method ORDER BY total DESC", $params)), [2]];
    $reports['refunds'] = ['Refunds', ['Reference', 'Donor', 'Amount (INR)', 'Refunded on', 'Refund ID'], array_map(function ($r) {
        return [$r['donation_ref'], $r['donor_name'], (float) $r['amount'], format_date($r['refunded_at'], 'd M Y'), (string) $r['refund_id']];
    }, db_rows("SELECT donation_ref, donor_name, amount, refunded_at, refund_id FROM donations WHERE status = 'refunded'
                AND COALESCE(refunded_at, updated_at) BETWEEN ? AND ? ORDER BY refunded_at DESC LIMIT 500", $params)), [2]];
    $attempts = db_rows('SELECT status, COUNT(*) n FROM donations WHERE created_at BETWEEN ? AND ? GROUP BY status', $params);
    $notes[] = 'All donation attempts started in this period: ' . ($attempts ? implode(', ', array_map(function ($a) { return humanize($a['status']) . ' ' . (int) $a['n']; }, $attempts)) : 'none')
        . '. Only “success” (verified) donations are counted in the figures above.';
    if ($export === 'list') {
        require_permission('exports.run');
        require_permission('donations.view');
        admin_log('report_exported', 'Verified donations list ' . $range['from'] . ' to ' . $range['to']);
        send_csv('verified-donations-' . $range['from'] . '-to-' . $range['to'] . '.csv',
            ['Reference', 'Paid at', 'Donor', 'Email', 'Phone', 'PAN', 'Address', 'Amount', 'Method', 'Gateway payment ID', 'Campaign', 'Receipt no', 'Financial year', 'Anonymous'],
            db_rows("SELECT d.donation_ref, d.paid_at, d.donor_name, d.donor_email, d.donor_phone, d.donor_pan, d.donor_address, d.amount, d.payment_method,
                            d.gateway_payment_id, COALESCE(c.title, 'General fund'), r.receipt_no, r.financial_year, IF(d.is_anonymous, 'Yes', 'No')
                     FROM donations d LEFT JOIN campaigns c ON c.id = d.campaign_id LEFT JOIN donation_receipts r ON r.donation_id = d.id AND r.status = 'issued'
                     WHERE d.status = 'success' AND d.paid_at BETWEEN ? AND ? ORDER BY d.paid_at", $params));
    }
} elseif ($tab === 'campaigns') {
    $rows = db_rows("SELECT c.id, c.title, c.status, c.goal_amount, c.start_date, c.end_date,
                            COALESCE(d.raised, 0) raised, COALESCE(d.n, 0) n, COALESCE(x.spent, 0) spent
                     FROM campaigns c
                     LEFT JOIN (SELECT campaign_id, SUM(amount) raised, COUNT(*) n FROM donations WHERE status = 'success' GROUP BY campaign_id) d ON d.campaign_id = c.id
                     LEFT JOIN (SELECT campaign_id, SUM(amount) spent FROM campaign_expenses WHERE status = 'approved' GROUP BY campaign_id) x ON x.campaign_id = c.id
                     WHERE c.status IN ('active','completed','closed') ORDER BY FIELD(c.status, 'active', 'completed', 'closed'), c.created_at DESC");
    $totRaised = array_sum(array_column($rows, 'raised'));
    $totSpent = array_sum(array_column($rows, 'spent'));
    $kpis = [['heart', '', number_format(count(array_filter($rows, function ($r) { return $r['status'] === 'active'; }))), 'Active campaigns'],
        ['rupee', 'gold', money($totRaised, true), 'Raised (verified, all campaigns)'], ['pie-chart', 'blue', money($totSpent, true), 'Approved expenses'],
        ['trending-up', '', insights_pct((float) $totSpent, (float) $totRaised), 'Overall utilisation']];
    $reports['campaigns'] = ['Raised, spent and utilisation (all time)', ['Campaign', 'Status', 'Goal (INR)', 'Raised (INR)', 'Donations', '% of goal', 'Approved expenses (INR)', 'Utilisation', 'Balance (INR)'],
        array_map(function ($r) {
            return [$r['title'], humanize($r['status']), (float) $r['goal_amount'], (float) $r['raised'], (int) $r['n'],
                (float) $r['goal_amount'] > 0 ? insights_pct((float) $r['raised'], (float) $r['goal_amount'], 0) : '–', (float) $r['spent'],
                insights_pct((float) $r['spent'], (float) $r['raised'], 0), (float) $r['raised'] - (float) $r['spent']];
        }, $rows), [2, 3, 6, 8]];
    $notes[] = 'Raised = verified donations; expenses = approved expenses only. Utilisation = approved expenses ÷ amount raised. The date filter does not apply to this lifetime summary.';
} elseif ($tab === 'applications') {
    $statuses = APPLICATION_STATUSES;
    $matrix = [];
    $monthly = [];
    foreach ($appTypes as $type => [$table, $label]) {
        $counts = array_column(db_rows("SELECT status, COUNT(*) n FROM `$table` WHERE created_at BETWEEN ? AND ? GROUP BY status", $params), 'n', 'status');
        $row = [$label];
        foreach ($statuses as $st) {
            $row[] = (int) ($counts[$st] ?? 0);
        }
        $row[] = array_sum($counts);
        $matrix[] = $row;
        foreach (db_rows("SELECT DATE_FORMAT(created_at, '%Y-%m') m, COUNT(*) n FROM `$table` WHERE created_at BETWEEN ? AND ? GROUP BY m", $params) as $r) {
            $monthly[$r['m']][$type] = (int) $r['n'];
        }
    }
    $members = array_column(db_rows('SELECT status, COUNT(*) n FROM members WHERE created_at BETWEEN ? AND ? GROUP BY status', $params), 'n', 'status');
    ksort($monthly);
    $totalApps = array_sum(array_column($matrix, count($statuses) + 1));
    $kpis = [['inbox', '', number_format($totalApps), 'Applications received'],
        ['clock', 'gold', number_format(array_sum(array_map(function ($r) { return $r[1] + $r[2]; }, $matrix))), 'Still submitted / under review'],
        ['check-circle', 'blue', number_format(array_sum(array_map(function ($r) { return $r[8]; }, $matrix))), 'Now active'],
        ['id-card', '', number_format(array_sum($members)), 'Membership applications']];
    $chart = [];
    foreach ($monthly as $m => $byType) {
        $chart[date('M y', strtotime($m . '-01'))] = array_sum($byType);
    }
    $chartTitle = 'Applications received per month';
    $reports['status'] = ['By type and current status', array_merge(['Type'], array_map('humanize', $statuses), ['Total']), $matrix, []];
    $reports['monthly'] = ['Received per month', array_merge(['Month'], array_column($appTypes, 1), ['Total']), array_map(function ($m) use ($monthly, $appTypes) {
        $row = [date('M Y', strtotime($m . '-01'))];
        foreach (array_keys($appTypes) as $t) {
            $row[] = (int) ($monthly[$m][$t] ?? 0);
        }
        $row[] = array_sum($monthly[$m]);
        return $row;
    }, array_keys($monthly)), []];
    $reports['members'] = ['Membership applications by status', ['Status', 'Applications'], array_map(function ($s, $n) { return [humanize($s), (int) $n]; }, array_keys($members), $members), []];
} elseif ($tab === 'support') {
    $helpStatuses = application_statuses('beneficiaries');
    $schStatuses = application_statuses('scholarships');
    $help = db_rows('SELECT help_category, status, COUNT(*) n FROM beneficiaries WHERE created_at BETWEEN ? AND ? GROUP BY help_category, status', $params);
    $helpMatrix = [];
    foreach ($help as $h) {
        $helpMatrix[$h['help_category']][$h['status']] = (int) $h['n'];
    }
    $sch = array_column(db_rows('SELECT status, COUNT(*) n FROM scholarships WHERE created_at BETWEEN ? AND ? GROUP BY status', $params), 'n', 'status');
    $schYear = db_rows('SELECT academic_year, COUNT(*) n, SUM(status IN (\'approved\',\'disbursed\')) approved FROM scholarships WHERE created_at BETWEEN ? AND ? GROUP BY academic_year ORDER BY academic_year DESC', $params);
    $helpTotal = array_sum(array_map('array_sum', $helpMatrix));
    $kpis = [['life-buoy', '', number_format($helpTotal), 'Help requests received'],
        ['check-circle', 'blue', number_format(array_sum(array_map(function ($r) { return ($r['approved'] ?? 0) + ($r['assisted'] ?? 0); }, $helpMatrix))), 'Approved or assisted'],
        ['graduation', 'gold', number_format(array_sum($sch)), 'Scholarship applications'],
        ['award', '', number_format(($sch['approved'] ?? 0) + ($sch['disbursed'] ?? 0)), 'Scholarships approved / disbursed']];
    $reports['help'] = ['Help requests by category and status', array_merge(['Category'], array_map('humanize', $helpStatuses), ['Total']), array_map(function ($cat) use ($helpMatrix, $helpStatuses) {
        $row = [humanize($cat)];
        foreach ($helpStatuses as $st) {
            $row[] = (int) ($helpMatrix[$cat][$st] ?? 0);
        }
        $row[] = array_sum($helpMatrix[$cat]);
        return $row;
    }, array_keys($helpMatrix)), []];
    $reports['scholarships'] = ['Scholarship applications by status', ['Status', 'Applications'], array_map(function ($st) use ($sch) { return [humanize($st), (int) ($sch[$st] ?? 0)]; }, $schStatuses), []];
    $reports['scholarship_years'] = ['Scholarships by academic year', ['Academic year', 'Applications', 'Approved / disbursed'], array_map(function ($r) {
        return [$r['academic_year'], (int) $r['n'], (int) $r['approved']];
    }, $schYear), []];
    $notes[] = 'Counts only – no personal details are shown in reports. Open Help Requests or Scholarships for individual cases.';
} elseif ($tab === 'events') {
    $rows = db_rows("SELECT e.title, e.event_type, e.start_datetime, e.status, e.capacity,
                            SUM(r.status IN ('registered','confirmed','no_show')) registered, SUM(r.status = 'attended') attended, SUM(r.status = 'cancelled') cancelled,
                            SUM(r.status = 'waitlisted') waitlisted, SUM(r.status = 'no_show') no_show
                     FROM events e LEFT JOIN event_registrations r ON r.event_id = e.id
                     WHERE e.start_datetime BETWEEN ? AND ? AND e.status IN ('published','archived','cancelled')
                     GROUP BY e.id ORDER BY e.start_datetime DESC", $params);
    $totReg = array_sum(array_map(function ($r) { return (int) $r['registered'] + (int) $r['attended']; }, $rows));
    $totAtt = array_sum(array_column($rows, 'attended'));
    $past = array_filter($rows, function ($r) { return strtotime($r['start_datetime']) < time() && $r['status'] !== 'cancelled'; });
    $kpis = [['calendar', '', number_format(count($rows)), 'Events in this period'], ['check-circle', 'blue', number_format(count($past)), 'Held (past, not cancelled)'],
        ['users', 'gold', number_format($totReg), 'Registrations (not cancelled)'], ['award', '', $totReg ? insights_pct((float) $totAtt, (float) $totReg, 0) : '–', 'Attendance (marked attended)']];
    $reports['events'] = ['Registrations and attendance per event', ['Event', 'Type', 'Date', 'Status', 'Capacity', 'Registered', 'Attended', 'No-show', 'Waiting list', 'Cancelled', 'Attendance'],
        array_map(function ($r) {
            $active = (int) $r['registered'] + (int) $r['attended'];
            return [$r['title'], humanize($r['event_type']), format_date($r['start_datetime'], 'd M Y'), humanize($r['status']), $r['capacity'] ? (int) $r['capacity'] : '–',
                $active, (int) $r['attended'], (int) $r['no_show'], (int) $r['waitlisted'], (int) $r['cancelled'], $active && strtotime($r['start_datetime']) < time() ? insights_pct((float) $r['attended'], (float) $active, 0) : '–'];
        }, $rows), []];
    $notes[] = 'Filtered by event date. “Registered” counts everyone holding a place (registered, confirmed, attended, no-show); the waiting list is shown separately. Attendance is shown for past events only.';
} elseif ($tab === 'communication') {
    $enqStatuses = application_statuses('contact_enquiries');
    $enq = db_rows('SELECT type, status, COUNT(*) n FROM contact_enquiries WHERE created_at BETWEEN ? AND ? GROUP BY type, status', $params);
    $enqMatrix = [];
    foreach ($enq as $q) {
        $enqMatrix[$q['type']][$q['status']] = (int) $q['n'];
    }
    $fb = db_row('SELECT COUNT(*) n, AVG(rating) avg_rating, SUM(rating IS NOT NULL) rated FROM feedback WHERE created_at BETWEEN ? AND ?', $params);
    $ratings = array_column(db_rows('SELECT rating, COUNT(*) n FROM feedback WHERE rating IS NOT NULL AND created_at BETWEEN ? AND ? GROUP BY rating', $params), 'n', 'rating');
    $fbCat = db_rows('SELECT category, COUNT(*) n, AVG(rating) avg_rating, SUM(status = \'new\') open_items FROM feedback WHERE created_at BETWEEN ? AND ? GROUP BY category ORDER BY n DESC', $params);
    $enqTotal = array_sum(array_map('array_sum', $enqMatrix));
    $kpis = [['inbox', '', number_format($enqTotal), 'Enquiries received'], ['clock', 'gold', number_format(array_sum(array_map(function ($r) { return ($r['new'] ?? 0) + ($r['in_progress'] ?? 0); }, $enqMatrix))), 'Still open'],
        ['message', 'blue', number_format((int) $fb['n']), 'Feedback received'], ['star', 'gold', $fb['rated'] ? number_format((float) $fb['avg_rating'], 1) . ' / 5' : '–', 'Average rating']];
    $reports['enquiries'] = ['Enquiries by type and status', array_merge(['Type'], array_map('humanize', $enqStatuses), ['Total']), array_map(function ($type) use ($enqMatrix, $enqStatuses) {
        $row = [humanize($type)];
        foreach ($enqStatuses as $st) {
            $row[] = (int) ($enqMatrix[$type][$st] ?? 0);
        }
        $row[] = array_sum($enqMatrix[$type]);
        return $row;
    }, array_keys($enqMatrix)), []];
    $reports['ratings'] = ['Feedback ratings', ['Rating', 'Responses'], array_map(function ($n) use ($ratings) { return [$n . ' star' . ($n > 1 ? 's' : ''), (int) ($ratings[$n] ?? 0)]; }, [5, 4, 3, 2, 1]), []];
    $reports['feedback_categories'] = ['Feedback by category', ['Category', 'Responses', 'Average rating', 'New (unread)'], array_map(function ($r) {
        return [humanize($r['category']), (int) $r['n'], $r['avg_rating'] !== null ? number_format((float) $r['avg_rating'], 1) : '–', (int) $r['open_items']];
    }, $fbCat), []];
} elseif ($tab === 'newsletter') {
    $tot = db_row("SELECT SUM(status = 'subscribed') subscribed, SUM(status = 'unsubscribed') unsubscribed, SUM(created_at BETWEEN ? AND ?) new_in_range FROM newsletter_subscribers", $params);
    $byMonth = db_rows("SELECT DATE_FORMAT(created_at, '%Y-%m') m, COUNT(*) n, SUM(status = 'unsubscribed') later_unsub FROM newsletter_subscribers WHERE created_at BETWEEN ? AND ? GROUP BY m ORDER BY m", $params);
    $kpis = [['mail', '', number_format((int) $tot['subscribed']), 'Subscribed now'], ['trending-up', 'blue', number_format((int) $tot['new_in_range']), 'New sign-ups · ' . $range['label']],
        ['x', 'gold', number_format((int) $tot['unsubscribed']), 'Unsubscribed (all time)']];
    $chart = insights_series(array_column($byMonth, 'n', 'm'), $range, 'month');
    $chartTitle = 'New sign-ups per month';
    $reports['months'] = ['Sign-ups per month', ['Month', 'New sign-ups', 'Of whom unsubscribed later'], array_map(function ($r) {
        return [date('M Y', strtotime($r['m'] . '-01')), (int) $r['n'], (int) $r['later_unsub']];
    }, $byMonth), []];
    $reports['sources'] = ['Sign-ups by source', ['Source', 'Sign-ups', 'Still subscribed'], array_map(function ($r) {
        return [$r['src'] !== '' ? humanize($r['src']) : 'Unknown', (int) $r['n'], (int) $r['active']];
    }, db_rows("SELECT COALESCE(source, '') src, COUNT(*) n, SUM(status = 'subscribed') active FROM newsletter_subscribers WHERE created_at BETWEEN ? AND ? GROUP BY src ORDER BY n DESC", $params)), []];
    $notes[] = 'Only people who gave consent are subscribed. Email addresses are available in Communication > Newsletter.';
}

// CSV export of one report table
if ($export !== '' && isset($reports[$export])) {
    require_permission('exports.run');
    [$title, $header, $rows] = $reports[$export];
    admin_log('report_exported', 'Report "' . $tabs[$tab][0] . ' - ' . $title . '" ' . $range['from'] . ' to ' . $range['to']);
    send_csv($tab . '-' . $export . '-' . $range['from'] . '-to-' . $range['to'] . '.csv', $header, $rows);
}

$keepRange = array_intersect_key($_GET, ['range' => 1, 'from' => 1, 'to' => 1]);
$exportUrl = function (string $key) use ($tab, $keepRange): string {
    return '?' . http_build_query(array_merge(['tab' => $tab], $keepRange, ['export' => $key]));
};
$canExport = admin_can('exports.run');

admin_page_start('Reports & Exports');
?>
<nav class="admin-tabs" aria-label="Report">
    <?php foreach ($tabs as $key => [$label, $ic]): ?>
        <a href="?<?= e(http_build_query(array_merge(['tab' => $key], $key === $tab ? $keepRange : []))) ?>" class="<?= $key === $tab ? 'active' : '' ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= icon($ic) ?> <?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<div class="dash-welcome">
    <div><h2><?= e($tabs[$tab][0]) ?></h2><p class="muted small mb-0"><?= $tab === 'campaigns' ? 'All time' : e($range['label'] . ' · ' . format_date($range['from']) . ' – ' . format_date($range['to'])) ?></p></div>
    <div class="report-tools">
        <?php if ($tab !== 'campaigns'): ?><?= admin_range_picker($range) ?><?php endif; ?>
        <?php if ($tab === 'donations' && $canExport && admin_can('donations.view')): ?>
            <a class="btn btn-primary btn-sm" href="<?= e($exportUrl('list')) ?>"><?= icon('download') ?>Verified donations list (CSV)</a>
        <?php endif; ?>
        <button type="button" class="btn btn-ghost btn-sm" data-print><?= icon('printer') ?>Print</button>
    </div>
</div>

<?php if ($kpis): ?>
    <div class="stat-cards insight-kpis">
        <?php foreach ($kpis as [$ic, $tone, $value, $label]): ?>
            <div class="stat-card"><span class="icon-circle<?= $tone ? ' ' . e($tone) : '' ?>"><?= icon($ic) ?></span><div><strong><?= e($value) ?></strong><small><?= e($label) ?></small></div></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($chart !== null): ?>
    <section class="panel mb-3">
        <div class="panel-head"><h2><?= e($chartTitle) ?></h2></div>
        <?= insights_chart($chart, $chartMoney, $chartTitle) ?>
    </section>
<?php endif; ?>

<div class="report-grid">
    <?php foreach ($reports as $key => [$title, $header, $rows, $moneyCols]): ?>
        <section class="panel report-panel<?= count($header) > 5 ? ' is-wide' : '' ?>">
            <div class="panel-head"><h2><?= e($title) ?></h2><?php if ($canExport && $rows): ?><a class="btn btn-ghost btn-sm" href="<?= e($exportUrl($key)) ?>"><?= icon('download') ?>CSV</a><?php endif; ?></div>
            <?php if ($rows): ?>
                <div class="table-wrap"><table class="table">
                    <thead><tr><?php foreach ($header as $i => $h): ?><th<?= $i > 0 ? ' class="text-right"' : '' ?>><?= e($h) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr><?php foreach (array_values($row) as $i => $cell): ?>
                            <td<?= $i > 0 ? ' class="text-right nowrap"' : '' ?>><?= in_array($i, $moneyCols, true) ? e(money((float) $cell, true)) : (is_int($cell) ? number_format($cell) : e((string) $cell)) ?></td>
                        <?php endforeach; ?></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php else: ?>
                <p class="muted mb-0">No data for this period.</p>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>

<?php foreach ($notes as $note): ?>
    <p class="muted small mt-2"><?= icon('info') ?> <?= e($note) ?></p>
<?php endforeach; ?>
<?php if (!$canExport): ?><p class="muted small"><?= icon('lock') ?> CSV downloads need the “Export CSV reports” permission.</p><?php endif; ?>
<?php
admin_page_end();
