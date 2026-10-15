<?php
/**
 * Certificates: issue (pending) -> approve (visible to the member in the dashboard) -> revoke.
 * Number PWF/CERT/<year>/<id>. Optional signed PDF stored privately in uploads/certificates.
 * ?preview=ID shows the printable certificate (render_certificate_html), ?download=ID streams the uploaded PDF.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('certificates.manage');

$types = CERTIFICATE_TYPES;
$defaultTitles = [
    'volunteer' => 'Certificate of Volunteering', 'internship' => 'Certificate of Internship', 'teaching' => 'Certificate of Volunteer Teaching',
    'membership' => 'Certificate of Membership', 'appreciation' => 'Certificate of Appreciation', 'participation' => 'Certificate of Participation',
];

if (isset($_GET['download'])) {
    send_certificate_file((int) $_GET['download']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('certificates.php');
    $back = admin_back('certificates.php');
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'issue') {
        [$data, $errors] = validate($_POST, [
            'recipient_email' => 'required|email',
            'recipient_name' => 'required|min:2|max:120',
            'certificate_type' => 'required|in:' . implode(',', array_keys($types)),
            'title' => 'required|min:3|max:200',
            'description' => 'text|max:500',
            'issue_date' => 'required|date',
        ], ['recipient_email' => 'Recipient account email', 'recipient_name' => 'Name on the certificate', 'certificate_type' => 'Certificate type']);
        $user = null;
        if (!$errors) {
            $user = db_row('SELECT id, name, email, status, email_verified_at FROM users WHERE email = ?', [$data['recipient_email']]);
            if (!$user || $user['status'] !== 'active') {
                $errors['recipient_email'] = 'No active website account uses this email address. The recipient needs an account to receive certificates.';
            } elseif (empty($user['email_verified_at'])) {
                $errors['recipient_email'] = 'The website account for this email has not verified its address yet. Certificates can only be issued to verified accounts.';
            }
        }
        if (!$errors && ($data['issue_date'] > date('Y-m-d') || $data['issue_date'] < '2000-01-01')) {
            $errors['issue_date'] = 'The issue date cannot be in the future.';
        }
        $file = null;
        if (!$errors) {
            $up = handle_upload('file', 'pdf', 'certificates');
            if (!empty($up['error'])) {
                $errors['file'] = $up['error'];
            }
            $file = $up['path'] ?? null;
        }
        if ($errors) {
            back_with_errors($errors, 'admin/certificates.php#issue', $_POST);
        }
        try {
            $certId = db_insert('certificates', [
                'certificate_no' => 'TMP-' . bin2hex(random_bytes(8)),
                'user_id' => $user['id'], 'recipient_name' => $data['recipient_name'], 'certificate_type' => $data['certificate_type'],
                'title' => $data['title'], 'description' => $data['description'] ?: null, 'issue_date' => $data['issue_date'],
                'file_path' => $file, 'status' => 'pending', 'issued_by' => admin_id(),
            ]);
        } catch (Throwable $e) {
            delete_upload($file);
            throw $e;
        }
        $no = certificate_number($certId, $data['issue_date']);
        db_update('certificates', ['certificate_no' => $no], 'id = :id', ['id' => $certId]);
        // Link to the application the certificate was issued from (internal history)
        [$appType, $appId] = array_pad(explode(':', (string) ($_POST['app'] ?? ''), 2), 2, '');
        if (recruitment_type($appType) && (int) $appId > 0) {
            record_application_history($appType, (int) $appId, 'certificate', 'Certificate ' . $no . ' issued (pending approval).', false);
        }
        admin_log('certificate_issued', $no . ' for user #' . $user['id'] . ' (' . $data['title'] . ')');
        flash('success', 'Certificate ' . $no . ' created. It becomes visible to the recipient after approval.');
        admin_redirect('certificates.php?preview=' . $certId);
    }

    $certId = (int) ($_POST['id'] ?? 0);
    $cert = db_row('SELECT c.*, u.name AS user_name, u.email AS user_email FROM certificates c JOIN users u ON u.id = c.user_id WHERE c.id = ?', [$certId]);
    if (!$cert) {
        flash('error', 'Certificate not found.');
        admin_redirect($back);
    }
    if ($action === 'approve') {
        if ($cert['status'] !== 'pending') {
            flash('error', 'Only pending certificates can be approved.');
            admin_redirect($back);
        }
        db_update('certificates', ['status' => 'approved', 'approved_by' => admin_id(), 'approved_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $certId]);
        notify_applicant($cert['user_email'], $cert['user_name'], 'Your certificate is ready - ' . $cert['certificate_no'],
            'Congratulations! Your certificate "' . $cert['title'] . '" (No. ' . $cert['certificate_no'] . ') has been issued by ' . site_name()
            . ".\n\nYou can view, print or save it from the Certificates section of your dashboard.",
            ['user_id' => (int) $cert['user_id'], 'link' => '/dashboard/certificates/', 'button' => 'View my certificate', 'type' => 'success',
                'notice' => 'Your certificate "' . $cert['title'] . '" is ready in your dashboard.']);
        admin_log('certificate_approved', $cert['certificate_no'] . ' (user #' . $cert['user_id'] . ')');
        flash('success', 'Certificate ' . $cert['certificate_no'] . ' approved. The recipient has been notified.');
    } elseif ($action === 'revoke') {
        if ($cert['status'] === 'revoked') {
            flash('info', 'This certificate is already revoked.');
            admin_redirect($back);
        }
        db_update('certificates', ['status' => 'revoked'], 'id = :id', ['id' => $certId]);
        admin_log('certificate_revoked', $cert['certificate_no'] . ' (user #' . $cert['user_id'] . ')' . (!empty($_POST['reason']) ? ': ' . mb_substr(clean_string($_POST['reason']), 0, 200) : ''));
        flash('success', 'Certificate ' . $cert['certificate_no'] . ' revoked. It is no longer shown to the recipient.');
    }
    admin_redirect($back);
}

// ---------------------------------------------------------------------
// Preview
// ---------------------------------------------------------------------
if (isset($_GET['preview'])) {
    $cert = db_row('SELECT c.*, u.email AS user_email, i.name AS issuer, a.name AS approver FROM certificates c JOIN users u ON u.id = c.user_id
                     LEFT JOIN users i ON i.id = c.issued_by LEFT JOIN users a ON a.id = c.approved_by WHERE c.id = ?', [(int) $_GET['preview']]);
    if (!$cert) {
        flash('error', 'Certificate not found.');
        admin_redirect('certificates.php');
    }
    admin_page_start('Certificate ' . $cert['certificate_no'], [['label' => 'All certificates', 'url' => admin_url('certificates.php'), 'icon' => 'list', 'style' => 'ghost']]);
    echo recruitment_admin_css();
    ?>
    <div class="panel rc-noprint">
        <div class="panel-head">
            <h2><?= e($cert['recipient_name']) ?> <small class="muted">&lt;<?= e($cert['user_email']) ?>&gt;</small></h2>
            <?= status_badge($cert['status']) ?>
        </div>
        <p class="small muted">Issued by <?= e($cert['issuer'] ?: '-') ?> on <?= e(format_date($cert['created_at'], 'd M Y, h:i A')) ?><?= $cert['approved_at'] ? ' · Approved by ' . e($cert['approver'] ?: '-') . ' on ' . e(format_date($cert['approved_at'], 'd M Y, h:i A')) : '' ?></p>
        <div class="btn-row">
            <?php if ($cert['status'] === 'pending'): ?>
                <form method="post" class="inline-form" data-confirm="Approve this certificate? The recipient will be able to see and print it."><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int) $cert['id'] ?>"><input type="hidden" name="return_to" value="certificates.php?preview=<?= (int) $cert['id'] ?>"><button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?>Approve &amp; notify</button></form>
            <?php endif; ?>
            <button type="button" class="btn btn-ghost btn-sm" data-print><?= icon('printer') ?>Print</button>
            <?php if ($cert['file_path']): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('certificates.php?download=' . (int) $cert['id'])) ?>"><?= icon('download') ?>Uploaded PDF</a><?php endif; ?>
            <?php if ($cert['status'] !== 'revoked'): ?>
                <form method="post" class="inline-form" data-confirm="Revoke this certificate? It will no longer be shown to the recipient."><?= csrf_field() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int) $cert['id'] ?>"><input type="hidden" name="return_to" value="certificates.php?preview=<?= (int) $cert['id'] ?>"><button class="btn btn-danger btn-sm" type="submit"><?= icon('x') ?>Revoke</button></form>
            <?php endif; ?>
        </div>
    </div>
    <div class="rc-cert-wrap"><?= render_certificate_html($cert) ?></div>
    <?php
    admin_page_end();
    exit;
}

// ---------------------------------------------------------------------
// List + issue form
// ---------------------------------------------------------------------
$q = mb_substr(clean_string($_GET['q'] ?? ''), 0, 100);
$status = (string) ($_GET['status'] ?? '');
$ctype = (string) ($_GET['ctype'] ?? '');
$params = [];
$where = search_where(['c.certificate_no', 'c.recipient_name', 'c.title', 'u.email'], $q, $params);
if (isset($types[$ctype])) {
    $where .= ' AND c.certificate_type = ?';
    $params[] = $ctype;
}
$counts = [];
foreach (db_rows("SELECT c.status, COUNT(*) n FROM certificates c JOIN users u ON u.id = c.user_id WHERE 1=1 $where GROUP BY c.status", $params) as $r) {
    $counts[$r['status']] = (int) $r['n'];
}
if (in_array($status, ['pending', 'approved', 'revoked'], true)) {
    $where .= ' AND c.status = ?';
    $params[] = $status;
} else {
    $status = '';
}
$p = paginate((int) db_value("SELECT COUNT(*) FROM certificates c JOIN users u ON u.id = c.user_id WHERE 1=1 $where", $params), 25);
$rows = db_rows("SELECT c.*, u.email AS user_email FROM certificates c JOIN users u ON u.id = c.user_id WHERE 1=1 $where
                 ORDER BY FIELD(c.status, 'pending', 'approved', 'revoked'), c.created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);

// Prefill (e.g. from an application: ?user_id=&type=&name=&app=type:id)
$prefillUser = !empty($_GET['user_id']) ? db_row('SELECT id, name, email FROM users WHERE id = ?', [(int) $_GET['user_id']]) : null;
$prefillType = (string) old('certificate_type', isset($types[$_GET['type'] ?? '']) ? (string) $_GET['type'] : 'volunteer');
$suggest = db_rows("SELECT DISTINCT u.email, u.name FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                     WHERE r.slug IN ('registered_volunteer', 'volunteer_teacher', 'member') AND u.status = 'active' ORDER BY u.name LIMIT 300");

admin_page_start('Certificates');
echo recruitment_admin_css();
?>
<div class="admin-grid-main">
    <div>
        <?php
        $typeSelect = '<select name="ctype" aria-label="Certificate type"><option value="">All types</option>';
        foreach ($types as $k => $label) {
            $typeSelect .= '<option value="' . e($k) . '"' . ($ctype === $k ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        $typeSelect .= '</select>';
        admin_filter_bar([], ['placeholder' => 'Certificate no., name, title or email', 'html' => $typeSelect, 'active' => $ctype !== '', 'hidden' => $status !== '' ? ['status' => $status] : []]);
        $chipQuery = array_filter(['q' => $q, 'ctype' => $ctype]);
        ?>
        <nav class="rc-chipbar" aria-label="Filter by status">
            <a href="?<?= e(http_build_query($chipQuery)) ?>"<?= $status === '' ? ' class="active"' : '' ?>>All <em><?= array_sum($counts) ?></em></a>
            <?php foreach (['pending' => 'Pending approval', 'approved' => 'Approved', 'revoked' => 'Revoked'] as $k => $label): ?>
                <a href="?<?= e(http_build_query($chipQuery + ['status' => $k])) ?>"<?= $status === $k ? ' class="active"' : '' ?>><?= e($label) ?> <em><?= (int) ($counts[$k] ?? 0) ?></em></a>
            <?php endforeach; ?>
        </nav>
        <div class="panel">
            <?php if ($rows): ?>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Certificate</th><th>Recipient</th><th>Type</th><th>Issued</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="nowrap"><a href="?preview=<?= (int) $r['id'] ?>"><strong><?= e($r['certificate_no']) ?></strong></a><br><small class="muted"><?= e(excerpt($r['title'], 50)) ?></small></td>
                            <td><?= e($r['recipient_name']) ?><br><small class="muted"><?= e($r['user_email']) ?></small></td>
                            <td><?= e($types[$r['certificate_type']] ?? humanize($r['certificate_type'])) ?><?= $r['file_path'] ? '<br><small class="muted">PDF attached</small>' : '' ?></td>
                            <td class="nowrap"><?= e(format_date($r['issue_date'])) ?></td>
                            <td><?= status_badge($r['status']) ?></td>
                            <td class="actions-cell">
                                <a class="icon-btn" href="?preview=<?= (int) $r['id'] ?>" aria-label="Preview <?= e($r['certificate_no']) ?>" title="Preview"><?= icon('eye') ?></a>
                                <?php if ($r['status'] === 'pending'): ?>
                                    <form method="post" class="inline-form" data-confirm="Approve certificate <?= e($r['certificate_no']) ?>?"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="icon-btn" type="submit" aria-label="Approve <?= e($r['certificate_no']) ?>" title="Approve"><?= icon('check') ?></button></form>
                                <?php endif; ?>
                                <?php if ($r['status'] !== 'revoked'): ?>
                                    <form method="post" class="inline-form" data-confirm="Revoke certificate <?= e($r['certificate_no']) ?>?"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="icon-btn danger" type="submit" aria-label="Revoke <?= e($r['certificate_no']) ?>" title="Revoke"><?= icon('x') ?></button></form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?= pagination_links($p) ?>
            <?php else: ?>
                <?= empty_state($q !== '' || $status !== '' || $ctype !== '' ? 'No certificates match these filters.' : 'No certificates have been issued yet.') ?>
            <?php endif; ?>
        </div>
    </div>

    <section class="panel" id="issue">
        <div class="panel-head"><h2>Issue a certificate</h2></div>
        <form method="post" enctype="multipart/form-data" class="rc-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="issue">
            <input type="hidden" name="app" value="<?= e(preg_match('/^[a-z]+:\d+$/', (string) ($_GET['app'] ?? '')) ? (string) $_GET['app'] : (string) old('app')) ?>">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_UPLOAD_MB * 1024 * 1024 ?>">
            <div class="form-group"><label for="recipient_email">Recipient's account email<span class="req">*</span></label>
                <input id="recipient_email" type="email" name="recipient_email" required maxlength="190" list="cert-users" value="<?= e(old('recipient_email', $prefillUser['email'] ?? '')) ?>">
                <datalist id="cert-users"><?php foreach ($suggest as $sgt): ?><option value="<?= e($sgt['email']) ?>"><?= e($sgt['name']) ?></option><?php endforeach; ?></datalist>
                <?= field_error('recipient_email') ?></div>
            <div class="form-group"><label for="recipient_name">Name on the certificate<span class="req">*</span></label>
                <input id="recipient_name" name="recipient_name" required maxlength="120" value="<?= e(old('recipient_name', (string) ($_GET['name'] ?? ($prefillUser['name'] ?? '')))) ?>"><?= field_error('recipient_name') ?></div>
            <div class="form-group"><label for="certificate_type">Type<span class="req">*</span></label>
                <select id="certificate_type" name="certificate_type" required><?= options_html($types, $prefillType, true, '') ?></select><?= field_error('certificate_type') ?></div>
            <div class="form-group"><label for="ctitle">Title<span class="req">*</span></label>
                <input id="ctitle" name="title" required maxlength="200" value="<?= e(old('title', $defaultTitles[$prefillType] ?? 'Certificate')) ?>"><?= field_error('title') ?></div>
            <div class="form-group"><label for="cdescription">Description</label>
                <textarea id="cdescription" name="description" rows="3" maxlength="500" placeholder="e.g. For volunteering with the Education Support programme from June to September 2026."><?= e(old('description')) ?></textarea><?= field_error('description') ?></div>
            <div class="form-group"><label for="issue_date">Issue date<span class="req">*</span></label>
                <input id="issue_date" type="date" name="issue_date" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('issue_date', date('Y-m-d'))) ?>"><?= field_error('issue_date') ?></div>
            <div class="form-group"><label for="file">Signed PDF (optional)</label>
                <input id="file" type="file" name="file" accept=".pdf,application/pdf"><span class="help">Stored privately. Only the recipient (after approval) and certificate managers can download it.</span><?= field_error('file') ?></div>
            <p class="help">Only issue certificates for verified service. New certificates stay <strong>pending</strong> until approved.</p>
            <button class="btn btn-primary btn-sm" type="submit"><?= icon('award') ?>Create certificate</button>
        </form>
    </section>
</div>
<?php
admin_page_end();
