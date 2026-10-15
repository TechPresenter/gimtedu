<?php
require_once __DIR__ . '/functions.php';

$page = require_page('financial-transparency');
$details = ngo_details();
$byYear = db_rows("SELECT CASE WHEN MONTH(paid_at) >= 4 THEN YEAR(paid_at) ELSE YEAR(paid_at) - 1 END AS fy_start,
                          COUNT(*) AS donations, SUM(amount) AS total
                   FROM donations WHERE status = 'success' AND paid_at IS NOT NULL
                   GROUP BY fy_start ORDER BY fy_start DESC");
$docs = array_merge(public_documents('financial'), public_documents('registration'));
$bank = [
    'Account name' => setting('bank_account_name'),
    'Bank' => setting('bank_name'),
    'Account number' => setting('bank_account_number'),
    'IFSC' => setting('bank_ifsc'),
    'Branch' => setting('bank_branch'),
    'UPI ID' => setting('upi_id'),
];
$bank = array_filter($bank);
$pageTitle = $page['title'];
$pageDescription = $page['meta_description'];
require __DIR__ . '/header.php';

page_banner($page['title'], (string) $page['subtitle'], ['About' => 'about.php']);
?>
<section class="section">
    <div class="container narrow">
        <article class="prose"><?= $page['content'] ?></article>

        <h2 class="mt-4" id="ngo-details">NGO details</h2>
        <?php if ($details): ?>
            <div class="kv-grid">
                <?php foreach ($details as $label => $value): ?>
                    <div class="kv"><small><?= e($label) ?></small><strong><?= e($value) ?></strong></div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted">Registration details will be published here by the foundation.</p>
        <?php endif; ?>

        <h2 class="mt-4">Donations received (verified)</h2>
        <?php if ($byYear): ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Financial year</th><th>Verified donations</th><th>Total amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($byYear as $row): ?>
                        <tr>
                            <td><?= e($row['fy_start'] . '-' . substr((string) ($row['fy_start'] + 1), -2)) ?></td>
                            <td><?= number_format((int) $row['donations']) ?></td>
                            <td><strong><?= e(money($row['total'], true)) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small muted mt-1">Calculated automatically from donations confirmed by our payment gateway or verified by our finance team. Refunded donations are excluded.</p>
        <?php else: ?>
            <p class="muted">No verified donations have been recorded yet.</p>
        <?php endif; ?>

        <h2 class="mt-4" id="documents">Documents &amp; certificates</h2>
        <?= $docs ? document_list($docs) : '<p class="muted">Audited statements and registration certificates will be published here once available.</p>' ?>

        <?php if ($bank): ?>
            <h2 class="mt-4">Direct bank transfer</h2>
            <div class="kv-grid">
                <?php foreach ($bank as $label => $value): ?>
                    <div class="kv"><small><?= e($label) ?></small><strong><?= e($value) ?></strong></div>
                <?php endforeach; ?>
            </div>
            <p class="small muted mt-1">After a bank transfer, please email the transaction reference to <?= e(setting('contact_email')) ?> so that we can verify it and issue your receipt.</p>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
