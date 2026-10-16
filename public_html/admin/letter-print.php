<?php
/**
 * A4 print view of an authorization letter: screen preview with a toolbar, then the browser's Print / "Save as PDF".
 * A standalone page (no admin sidebar) so that the printout is exactly one A4 sheet (@page A4, no margins).
 * The private signature image is embedded here as a data: URI only. Opening the view and printing it are recorded in
 * the audit log (admin-letters.js sends a small POST when the print dialog opens).
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('letters.manage');

$id = (int) ($_GET['id'] ?? 0);
$letter = $id ? letter_get($id) : null;
if (!$letter) {
    flash('error', 'Letter not found.');
    admin_redirect('letters.php');
}
$label = 'Authorization letter ' . $letter['ref_no'] . ' (#' . $id . ')';

// Print beacon: POST action=printed (CSRF token), answered with 204. Counted at most once a minute per letter and session.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_valid() || post_str('action') !== 'printed') {
        http_response_code(403);
        exit;
    }
    $last = (int) ($_SESSION['al_printed'][$id] ?? 0);
    if (time() - $last > 60) {
        $_SESSION['al_printed'][$id] = time();
        db_query('UPDATE authorization_letters SET print_count = print_count + 1, last_printed_at = NOW() WHERE id = ?', [$id]);
        admin_log('letter_printed', $label . ' printed / saved as PDF' . ($letter['status'] === 'revoked' ? ' (revoked copy)' : ''));
    }
    http_response_code(204);
    exit;
}

// Opening the letter (it shows personal data) is logged once per 10 minutes per letter and session
$lastView = (int) ($_SESSION['al_viewed'][$id] ?? 0);
if (time() - $lastView > 600) {
    $_SESSION['al_viewed'][$id] = time();
    admin_log('letter_viewed', $label . ' opened in print view');
}

$revoked = $letter['status'] === 'revoked';
$sheet = letter_sheet_html($letter, ['signature' => $revoked ? '' : letter_signature_data_uri()]);
$docTitle = 'Authorization Letter ' . str_replace('/', '-', $letter['ref_no']) . ' - ' . $letter['candidate_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($docTitle) ?></title>
    <link rel="icon" type="image/png" href="<?= e(asset('assets/images/favicon.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/modules/admin-letters.css')) ?>">
    <style>@page { size: A4 portrait; margin: 0; }</style>
</head>
<body class="al-print-body">
<header class="al-toolbar">
    <div class="al-toolbar-inner">
        <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('letters.php')) ?>"><?= icon('chevron-left') ?>All letters</a>
        <div class="al-toolbar-info">
            <h1><?= e($letter['ref_no']) ?></h1>
            <span><?= status_badge($revoked ? 'revoked' : 'valid') ?> <span class="al-tb-meta"><?= e($letter['candidate_name']) ?> · Code <?= e(letter_format_code($letter['verify_code'])) ?></span></span>
        </div>
        <div class="al-toolbar-actions">
            <?php if (!$revoked): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('letter-edit.php?id=' . $id)) ?>"><?= icon('edit') ?>Edit</a>
                <button type="button" class="btn btn-primary btn-sm" data-al-print><?= icon('printer') ?>Print / Save as PDF</button>
            <?php endif; ?>
        </div>
    </div>
    <?= str_replace('flash-wrap container', 'flash-wrap al-flash', render_flash()) ?>
    <div class="al-notes">
        <?php if ($revoked): ?>
            <p class="al-note al-note-red"><?= icon('alert-triangle') ?><span>Revoked on <?= e(format_date($letter['revoked_at'], 'd M Y, h:i A')) ?><?= $letter['revoked_by_name'] ? ' by ' . e($letter['revoked_by_name']) : '' ?>: <?= e((string) $letter['revoked_reason']) ?>. The verification page shows it as revoked; it is shown here for the record only.</span></p>
        <?php else: ?>
            <p class="al-note"><?= icon('info') ?><span>In the print dialog choose paper size <strong>A4</strong> and margins <strong>None</strong> (or Default). To keep a PDF copy, choose <strong>Save as PDF</strong> as the printer.</span></p>
        <?php endif; ?>
        <p class="al-note al-note-gold" data-al-fit-note hidden aria-live="polite"></p>
    </div>
</header>
<main class="al-stage">
    <div class="al-sheet-wrap" data-al-wrap>
        <?= $sheet ?>
    </div>
</main>
<form method="post" action="<?= e(admin_url('letter-print.php?id=' . $id)) ?>" data-al-beacon hidden><?= csrf_field() ?><input type="hidden" name="action" value="printed"></form>
<script src="<?= e(asset('assets/js/modules/admin-letters.js')) ?>" defer></script>
</body>
</html>
