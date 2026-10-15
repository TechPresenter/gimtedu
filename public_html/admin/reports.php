<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('reports');

$fyStart = ((int) date('n') >= 4 ? date('Y') : (string) ((int) date('Y') - 1)) . '-04-01';
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : $fyStart;
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$range = [$from . ' 00:00:00', $to . ' 23:59:59'];

if (($_GET['export'] ?? '') === 'csv') {
    $rows = db_rows("SELECT d.donation_ref, d.paid_at, d.donor_name, d.donor_email, d.donor_phone, d.donor_pan, d.donor_address, d.amount,
                            d.payment_method, d.gateway_payment_id, c.title, r.receipt_no
                     FROM donations d LEFT JOIN campaigns c ON c.id = d.campaign_id LEFT JOIN donation_receipts r ON r.donation_id = d.id
                     WHERE d.status = 'success' AND d.paid_at BETWEEN ? AND ? ORDER BY d.paid_at", $range);
    admin_log('report_exported', 'Verified donations ' . $from . ' to ' . $to);
    send_csv('verified-donations-' . $from . '-to-' . $to . '.csv', ['Reference', 'Paid at', 'Donor', 'Email', 'Phone', 'PAN', 'Address', 'Amount', 'Method', 'Payment ID', 'Campaign', 'Receipt No'], $rows);
}

$summary = db_row("SELECT COUNT(*) n, COALESCE(SUM(amount), 0) total, COALESCE(AVG(amount), 0) avg, COUNT(DISTINCT donor_email) donors
                   FROM donations WHERE status = 'success' AND paid_at BETWEEN ? AND ?", $range);
$byMonth = db_rows("SELECT DATE_FORMAT(paid_at, '%Y-%m') m, COUNT(*) n, SUM(amount) total FROM donations
                    WHERE status = 'success' AND paid_at BETWEEN ? AND ? GROUP BY m ORDER BY m", $range);
$byCampaign = db_rows("SELECT COALESCE(c.title, 'General Fund') title, COUNT(*) n, SUM(d.amount) total FROM donations d LEFT JOIN campaigns c ON c.id = d.campaign_id
                       WHERE d.status = 'success' AND d.paid_at BETWEEN ? AND ? GROUP BY title ORDER BY total DESC", $range);
$byMethod = db_rows("SELECT payment_method, COUNT(*) n, SUM(amount) total FROM donations WHERE status = 'success' AND paid_at BETWEEN ? AND ? GROUP BY payment_method ORDER BY total DESC", $range);
$statusCounts = db_rows('SELECT status, COUNT(*) n FROM donations WHERE created_at BETWEEN ? AND ? GROUP BY status', $range);
$apps = [];
foreach (['volunteers' => 'Volunteers & interns', 'teacher_applications' => 'Volunteer teachers', 'staff_applications' => 'Staff', 'scholarships' => 'Scholarships', 'beneficiaries' => 'Help requests'] as $table => $label) {
    $apps[$label] = db_rows("SELECT status, COUNT(*) n FROM `$table` WHERE created_at BETWEEN ? AND ? GROUP BY status", $range);
}
$adminTitle = 'Reports';
require __DIR__ . '/includes/header.php';
$max = max(1, ...array_map(function ($r) { return (float) $r['total']; }, $byMonth ?: [['total' => 1]]));
?>
<form class="filter-row" method="get">
    <label class="small muted" for="from">From</label><input id="from" type="date" name="from" value="<?= e($from) ?>">
    <label class="small muted" for="to">To</label><input id="to" type="date" name="to" value="<?= e($to) ?>">
    <button class="btn btn-primary btn-sm" type="submit">Apply</button>
    <a class="btn btn-ghost btn-sm" href="?from=<?= e($from) ?>&amp;to=<?= e($to) ?>&amp;export=csv"><?= icon('download') ?>Export verified donations (CSV)</a>
</form>
<div class="stat-cards">
    <div class="stat-card"><span class="icon-circle gold"><?= icon('rupee') ?></span><div><strong><?= e(money($summary['total'], true)) ?></strong><small>Verified donations</small></div></div>
    <div class="stat-card"><span class="icon-circle"><?= icon('heart') ?></span><div><strong><?= number_format((int) $summary['n']) ?></strong><small>Successful donations</small></div></div>
    <div class="stat-card"><span class="icon-circle blue"><?= icon('users') ?></span><div><strong><?= number_format((int) $summary['donors']) ?></strong><small>Unique donors</small></div></div>
    <div class="stat-card"><span class="icon-circle"><?= icon('trending-up') ?></span><div><strong><?= e(money($summary['avg'])) ?></strong><small>Average donation</small></div></div>
</div>
<div class="admin-grid-2">
    <div class="panel">
        <h3>Verified donations by month</h3>
        <?php if ($byMonth): ?>
            <div class="chart-bars">
                <?php foreach ($byMonth as $m): ?>
                    <div class="chart-bar" title="<?= e($m['m'] . ': ' . money($m['total'])) ?>"><em><?= e(money($m['total'])) ?></em><span class="bar" style="height:<?= max(2, round($m['total'] / $max * 100)) ?>%"></span><small><?= e(date('M y', strtotime($m['m'] . '-01'))) ?></small></div>
                <?php endforeach; ?>
            </div>
        <?php else: ?><p class="muted">No verified donations in this period.</p><?php endif; ?>
    </div>
    <div class="panel">
        <h3>By campaign</h3>
        <?php if ($byCampaign): ?>
            <div class="table-wrap"><table class="table"><thead><tr><th>Campaign</th><th>Donations</th><th>Amount</th></tr></thead><tbody>
                <?php foreach ($byCampaign as $r): ?><tr><td><?= e($r['title']) ?></td><td><?= (int) $r['n'] ?></td><td class="nowrap"><?= e(money($r['total'], true)) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?><p class="muted">No data.</p><?php endif; ?>
    </div>
    <div class="panel">
        <h3>By payment method</h3>
        <?php if ($byMethod): ?>
            <div class="table-wrap"><table class="table"><thead><tr><th>Method</th><th>Donations</th><th>Amount</th></tr></thead><tbody>
                <?php foreach ($byMethod as $r): ?><tr><td><?= e(humanize($r['payment_method'])) ?></td><td><?= (int) $r['n'] ?></td><td class="nowrap"><?= e(money($r['total'], true)) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?><p class="muted">No data.</p><?php endif; ?>
        <h4 class="mt-3">All donation attempts (by status)</h4>
        <p><?php foreach ($statusCounts as $sc): ?><?= status_badge($sc['status']) ?> <?= (int) $sc['n'] ?> &nbsp; <?php endforeach; ?><?= $statusCounts ? '' : '<span class="muted">None</span>' ?></p>
    </div>
    <div class="panel">
        <h3>Applications received</h3>
        <div class="table-wrap"><table class="table"><tbody>
            <?php foreach ($apps as $label => $rows): ?>
                <tr><td><strong><?= e($label) ?></strong></td><td><?php if ($rows): foreach ($rows as $r): ?><?= status_badge($r['status']) ?> <?= (int) $r['n'] ?> &nbsp;<?php endforeach; else: ?><span class="muted">0</span><?php endif; ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
