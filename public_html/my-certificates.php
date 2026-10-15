<?php
require_once __DIR__ . '/functions.php';

$user = require_login();
$uid = (int) $user['id'];

$certId = (int) ($_GET['view'] ?? $_GET['download'] ?? 0);
if ($certId) {
    $cert = db_row("SELECT * FROM certificates WHERE id = ? AND user_id = ? AND status = 'valid'", [$certId, $uid]);
    if (!$cert) {
        not_found();
    }
    if (isset($_GET['download']) && $cert['file_path']) {
        send_private_file($cert['file_path'], $cert['certificate_no']);
    }
    $pageTitle = $cert['title'];
    require __DIR__ . '/header.php';
    ?>
    <section class="section">
        <div class="container">
            <div class="print-actions">
                <button class="btn btn-primary" type="button" data-print><?= icon('printer') ?>Print / Save as PDF</button>
                <a class="btn btn-outline" href="<?= e(url('my-certificates.php')) ?>">Back to certificates</a>
            </div>
            <div class="certificate">
                <img src="<?= e(url('assets/images/logo.png')) ?>" alt="">
                <h1>Certificate</h1>
                <div class="cert-sub">of <?= e(humanize($cert['certificate_type'])) ?></div>
                <p class="mt-3">This certificate is proudly presented to</p>
                <div class="cert-name"><?= e($cert['recipient_name']) ?></div>
                <p class="cert-text"><strong><?= e($cert['title']) ?></strong><?= $cert['description'] ? '<br>' . e($cert['description']) : '' ?></p>
                <div class="cert-foot">
                    <div>Certificate No: <strong><?= e($cert['certificate_no']) ?></strong><br>Date of issue: <?= e(format_date($cert['issue_date'])) ?></div>
                    <div class="signature"><span><?= e(setting('receipt_signatory', 'Authorised Signatory')) ?><br><small><?= e(site_name()) ?></small></span></div>
                </div>
            </div>
        </div>
    </section>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}

$certs = db_rows("SELECT * FROM certificates WHERE user_id = ? AND status = 'valid' ORDER BY issue_date DESC", [$uid]);
$pageTitle = 'My Certificates';
require __DIR__ . '/header.php';
page_banner('My Certificates', 'Certificates issued to you by the foundation');
?>
<section class="section">
    <div class="container member-layout">
        <?php render_member_nav($user); ?>
        <div>
            <div class="panel">
                <div class="panel-head"><h2>Certificates</h2></div>
                <?php if ($certs): ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>Certificate no.</th><th>Title</th><th>Type</th><th>Issued</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($certs as $c): ?>
                                <tr>
                                    <td class="nowrap"><strong><?= e($c['certificate_no']) ?></strong></td>
                                    <td><?= e($c['title']) ?></td>
                                    <td><?= e(humanize($c['certificate_type'])) ?></td>
                                    <td class="nowrap"><?= e(format_date($c['issue_date'])) ?></td>
                                    <td class="nowrap">
                                        <a href="<?= e(url('my-certificates.php?view=' . (int) $c['id'])) ?>"><?= icon('eye') ?> View</a>
                                        <?php if ($c['file_path']): ?> · <a href="<?= e(url('my-certificates.php?download=' . (int) $c['id'])) ?>"><?= icon('download') ?> PDF</a><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <?= empty_state('No certificates have been issued to your account yet. Certificates are issued by the foundation after you complete a volunteering engagement, internship or programme.') ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
