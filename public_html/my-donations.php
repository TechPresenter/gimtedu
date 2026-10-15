<?php
require_once __DIR__ . '/functions.php';

$user = require_login();
$uid = (int) $user['id'];
$p = paginate((int) db_value("SELECT COUNT(*) FROM donations WHERE user_id = ? AND status <> 'pending'", [$uid]), 15);
$donations = db_rows("SELECT d.*, c.title AS campaign_title, r.receipt_no FROM donations d
                      LEFT JOIN campaigns c ON c.id = d.campaign_id
                      LEFT JOIN donation_receipts r ON r.donation_id = d.id
                      WHERE d.user_id = ? AND d.status <> 'pending'
                      ORDER BY d.created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", [$uid]);
$total = (float) db_value("SELECT COALESCE(SUM(amount), 0) FROM donations WHERE user_id = ? AND status = 'success'", [$uid]);

$pageTitle = 'My Donations';
require __DIR__ . '/header.php';
page_banner('My Donations', 'Your donation history and receipts');
?>
<section class="section">
    <div class="container member-layout">
        <?php render_member_nav($user); ?>
        <div>
            <div class="panel">
                <div class="panel-head">
                    <h2>Donation history</h2>
                    <span class="badge badge-green">Total verified: <?= e(money($total, true)) ?></span>
                </div>
                <?php if ($donations): ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>Date</th><th>Reference</th><th>Towards</th><th>Amount</th><th>Status</th><th>Receipt</th></tr></thead>
                            <tbody>
                            <?php foreach ($donations as $d): ?>
                                <tr>
                                    <td class="nowrap"><?= e(format_date($d['paid_at'] ?: $d['created_at'])) ?></td>
                                    <td><?= e($d['donation_ref']) ?></td>
                                    <td><?= e($d['campaign_title'] ?: 'General Fund') ?></td>
                                    <td class="nowrap"><strong><?= e(money($d['amount'], true)) ?></strong></td>
                                    <td><?= status_badge($d['status']) ?></td>
                                    <td class="nowrap">
                                        <?php if ($d['receipt_no']): ?>
                                            <a href="<?= e(url('donation-receipt.php?ref=' . urlencode($d['donation_ref']))) ?>"><?= icon('download') ?> <?= e($d['receipt_no']) ?></a>
                                        <?php else: ?>
                                            <span class="muted small">Not issued</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?= pagination_links($p) ?>
                    <p class="small muted mt-2">Receipts are issued only for payments verified with our payment gateway. Donations made without logging in are not shown here; use the receipt link in your confirmation email.</p>
                <?php else: ?>
                    <?= empty_state('No donations yet. Donations you make while logged in will appear here with their receipts.', 'Make a donation', 'donate.php') ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
