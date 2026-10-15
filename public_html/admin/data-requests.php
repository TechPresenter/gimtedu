<?php
/**
 * Personal data requests (access / correction / deletion): list, view, respond, export the user's data (JSON)
 * and complete deletion requests by anonymising the account. Donation and receipt records are kept
 * because the foundation must retain them for its accounts and tax records. Every action is logged.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('data_requests.manage');

$meId = (int) admin_id();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$req = $id ? db_row('SELECT dr.*, u.name AS user_name, u.email AS user_email, u.status AS user_status, h.name AS handler_name
                       FROM data_requests dr LEFT JOIN users u ON u.id = dr.user_id LEFT JOIN users h ON h.id = dr.handled_by
                      WHERE dr.id = ?', [$id]) : null;
/** Account the request is about: linked user, or an account with the requester's email. */
$subjectId = 0;
if ($req) {
    $subjectId = (int) ($req['user_id'] ?: db_value('SELECT id FROM users WHERE email = ?', [mb_strtolower((string) $req['email'])]));
}

// JSON export of the subject's data
if ($req && isset($_GET['export'])) {
    if (!$subjectId) {
        flash('error', 'No website account is linked to this request, so there is no account data to export.');
        admin_redirect('data-requests.php?id=' . $id);
    }
    admin_log('data_exported', 'Exported personal data of user #' . $subjectId . ' for data request #' . $id);
    member_send_export($subjectId, 'personal-data-request-' . $id . '-user-' . $subjectId . '.json');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('data-requests.php' . ($id ? '?id=' . $id : ''));
    if (!$req) {
        flash('error', 'Request not found.');
        admin_redirect('data-requests.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    $response = trim(mb_substr(str_replace("\r\n", "\n", (string) ($_POST['response'] ?? '')), 0, 5000));
    $closed = in_array($req['status'], ['completed', 'rejected'], true);
    $reply = function (string $subject, string $text) use ($req): void {
        send_mail((string) $req['email'], $subject . ' - ' . site_name(),
            '<p>Dear ' . e($req['user_name'] && $req['user_name'] !== 'Deleted user' ? $req['user_name'] : 'Sir/Madam') . ',</p><p>' . e_nl($text) . '</p>'
            . '<p>Reference: data request #' . (int) $req['id'] . '</p><p>Regards,<br>' . e(site_name()) . '</p>');
    };

    if ($action === 'in_progress' && !$closed) {
        db_update('data_requests', ['status' => 'in_progress', 'handled_by' => $meId], 'id = :id', ['id' => $id]);
        admin_log('data_request_in_progress', 'Data request #' . $id . ' marked in progress');
        flash('success', 'Marked as in progress.');
    } elseif ($action === 'respond' && !$closed) {
        $to = (string) ($_POST['status'] ?? '');
        if (!in_array($to, ['in_progress', 'completed', 'rejected'], true)) {
            back_with_errors(['status' => 'Choose a status.'], 'admin/data-requests.php?id=' . $id, $_POST);
        }
        if ($response === '') {
            back_with_errors(['response' => $to === 'rejected' ? 'Please give the reason for rejecting the request.' : 'Please write a response to the requester.'], 'admin/data-requests.php?id=' . $id, $_POST);
        }
        db_update('data_requests', [
            'status' => $to, 'response' => $response, 'handled_by' => $meId,
            'completed_at' => in_array($to, ['completed', 'rejected'], true) ? date('Y-m-d H:i:s') : null,
        ], 'id = :id', ['id' => $id]);
        $reply('Update on your data request #' . $id, $response);
        if ($req['user_id'] && $req['user_status'] !== null && $req['user_name'] !== 'Deleted user') {
            add_notification((int) $req['user_id'], 'Data request #' . $id . ': ' . humanize($to), excerpt($response, 300), '/dashboard/settings/', 'info');
        }
        admin_log('data_request_' . $to, 'Data request #' . $id . ' (' . $req['request_type'] . ') ' . $to . ' with response emailed');
        flash('success', 'Response saved and emailed to ' . $req['email'] . '.');
    } elseif ($action === 'anonymise' && !$closed && $req['request_type'] === 'deletion') {
        if (!$subjectId) {
            flash('error', 'No website account is linked to this request.');
        } elseif ($subjectId === $meId) {
            flash('error', 'You cannot anonymise your own account.');
        } elseif (is_staff($subjectId)) {
            flash('error', 'This account has staff roles. Remove its staff roles under Users before completing the deletion.');
        } elseif (empty($_POST['confirm'])) {
            flash('error', 'Please tick the confirmation box.');
        } else {
            $summary = member_retained_summary($subjectId);
            member_anonymise_account($subjectId);
            $text = ($response !== '' ? $response . "\n\n" : 'We have completed your request to delete your account and personal data.' . "\n\n") . member_retention_note();
            db_update('data_requests', ['status' => 'completed', 'response' => $text, 'handled_by' => $meId, 'completed_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            $reply('Your data deletion request #' . $id . ' has been completed', $text);
            admin_log('account_anonymised', 'Anonymised user #' . $subjectId . ' for data request #' . $id . ' (kept ' . $summary['donations'] . ' donation(s), ' . $summary['receipts'] . ' receipt(s))');
            flash('success', 'The account has been anonymised and the requester has been informed by email.');
        }
    }
    admin_redirect('data-requests.php?id=' . $id);
}

// ---------------------------------------------------------------------
// Detail view
// ---------------------------------------------------------------------
if ($req) {
    $subject = $subjectId ? db_row('SELECT id, name, email, mobile, status, email_verified_at, created_at FROM users WHERE id = ?', [$subjectId]) : null;
    $summary = $subjectId ? member_retained_summary($subjectId) : null;
    $closed = in_array($req['status'], ['completed', 'rejected'], true);
    $anonymised = $subject && $subject['name'] === 'Deleted user' && strpos((string) $subject['email'], 'deleted-') === 0;
    admin_page_start('Data request #' . (int) $req['id'], [['label' => 'All requests', 'url' => admin_url('data-requests.php'), 'icon' => 'chevron-left', 'style' => 'ghost']]);
    ?>
    <div class="admin-grid-main">
        <div>
            <section class="panel">
                <div class="panel-head"><h2><?= e(humanize($req['request_type'])) ?> request</h2><?= member_status_badge($req['status']) ?></div>
                <?php details_list([
                    'Received' => format_date($req['created_at'], 'd M Y, h:i A'),
                    'Requester email' => $req['email'],
                    'Account' => $subject ? [($anonymised ? 'Anonymised account' : e($subject['name'])) . ' (#' . (int) $subject['id'] . ')'
                        . (admin_can('members.view') ? ' &middot; <a href="' . e(admin_url('user-view.php?id=' . (int) $subject['id'])) . '">Open account</a>' : '')] : 'No website account found for this email',
                    'Details from requester' => (string) $req['details'],
                    'Handled by' => (string) $req['handler_name'],
                    'Closed' => $req['completed_at'] ? format_date($req['completed_at'], 'd M Y, h:i A') : '',
                    'Response sent' => (string) $req['response'],
                ]); ?>
            </section>

            <?php if (!$closed): ?>
                <section class="panel">
                    <div class="panel-head"><h2>Respond</h2></div>
                    <form method="post" class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr))">
                        <?= csrf_field() ?><input type="hidden" name="action" value="respond"><input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
                        <div class="form-group full"><label for="response">Response to the requester (emailed)</label>
                            <textarea id="response" name="response" rows="5" maxlength="5000" required><?= e(old('response')) ?></textarea><?= field_error('response') ?></div>
                        <div class="form-group"><label for="status">Set status</label>
                            <select id="status" name="status">
                                <option value="in_progress"<?= old_selected('status', 'in_progress') ?>>In progress (update only)</option>
                                <option value="completed"<?= old_selected('status', 'completed') ?>>Completed</option>
                                <option value="rejected"<?= old_selected('status', 'rejected') ?>>Rejected (give the reason above)</option>
                            </select><?= field_error('status') ?></div>
                        <div class="form-actions full"><button class="btn btn-primary btn-sm" type="submit"><?= icon('send') ?>Save &amp; email response</button>
                            <?php if ($req['status'] === 'pending'): ?><button class="btn btn-ghost btn-sm" type="submit" name="action" value="in_progress" formnovalidate>Mark in progress only</button><?php endif; ?></div>
                    </form>
                    <?php if ($req['request_type'] === 'correction' && $subject && admin_can('members.manage')): ?>
                        <p class="small muted mt-2"><?= icon('edit') ?> Make the corrections on the <a href="<?= e(admin_url('user-view.php?id=' . (int) $subject['id'])) ?>">account page</a> (or in the relevant module), then complete this request.</p>
                    <?php endif; ?>
                </section>

                <?php if ($req['request_type'] === 'deletion'): ?>
                    <section class="panel" style="border-color:#f5c2bd">
                        <div class="panel-head"><h2>Complete deletion (anonymise account)</h2></div>
                        <?php if (!$subject || $anonymised): ?>
                            <p class="muted"><?= $anonymised ? 'This account has already been anonymised. Respond above to close the request.' : 'No website account is linked to this email address. Check other records (for example newsletter or enquiries), then respond above.' ?></p>
                        <?php else: ?>
                            <p>Completing the request will:</p>
                            <ul class="health-list">
                                <li class="ok"><?= icon('check') ?><span>change the name to <strong>Deleted user</strong> and the email to <strong>deleted-<?= (int) $subject['id'] ?>@invalid.local</strong>; remove mobile number, address, city, state, PIN code and photo;</span></li>
                                <li class="ok"><?= icon('check') ?><span>deactivate the account, remove all its roles, sign it out everywhere and delete its remember-me, password reset and verification tokens, two-step secret, notifications and newsletter subscription;</span></li>
                                <li class="warn"><?= icon('info') ?><span><strong>keep</strong> <?= (int) $summary['donations'] ?> donation record(s) and <?= (int) $summary['receipts'] ?> receipt(s): the foundation must retain them for its accounts and tax records. This is explained to the requester in the email.</span></li>
                                <?php if ($summary['applications'] || $summary['events'] || $summary['certificates']): ?>
                                    <li class="warn"><?= icon('info') ?><span><?= (int) $summary['applications'] ?> application(s), <?= (int) $summary['events'] ?> event registration(s) and <?= (int) $summary['certificates'] ?> certificate(s) stay linked to the anonymised account under the data retention policy; delete them from their admin pages if they are no longer needed.</span></li>
                                <?php endif; ?>
                            </ul>
                            <p class="small muted">Tip: export the data first if the requester also asked for a copy.</p>
                            <form method="post" class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr))" data-confirm="Anonymise this account now? This cannot be undone.">
                                <?= csrf_field() ?><input type="hidden" name="action" value="anonymise"><input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
                                <div class="form-group full"><label for="anon-response">Message to the requester (optional - the retention explanation is added automatically)</label>
                                    <textarea id="anon-response" name="response" rows="3" maxlength="2000"></textarea></div>
                                <div class="form-group full"><label class="checkbox"><input type="checkbox" name="confirm" value="1" required><span>I have checked the request and want to anonymise this account permanently.</span></label></div>
                                <div class="form-actions full"><button class="btn btn-danger btn-sm" type="submit"><?= icon('trash') ?>Anonymise account &amp; complete request</button></div>
                            </form>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <aside>
            <section class="panel">
                <div class="panel-head"><h2>Account data</h2></div>
                <?php if ($subject): ?>
                    <p class="small muted">Download everything the website holds about this account as JSON: profile, applications, donations and receipts, events, certificates, tasks, notifications, newsletter and requests. Passwords, tokens, staff notes and other people's data are never included.</p>
                    <a class="btn btn-outline btn-sm" href="<?= e(admin_url('data-requests.php?id=' . (int) $req['id'] . '&export=json')) ?>"><?= icon('download') ?>Export data (JSON)</a>
                    <?php if ($summary): ?>
                        <dl class="detail-list kv-small mt-2" style="grid-template-columns:auto 1fr">
                            <dt>Donations</dt><dd><?= (int) $summary['donations'] ?></dd>
                            <dt>Receipts</dt><dd><?= (int) $summary['receipts'] ?></dd>
                            <dt>Applications</dt><dd><?= (int) $summary['applications'] ?></dd>
                            <dt>Event registrations</dt><dd><?= (int) $summary['events'] ?></dd>
                            <dt>Certificates</dt><dd><?= (int) $summary['certificates'] ?></dd>
                        </dl>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="muted small">No website account matches <?= e($req['email']) ?>.</p>
                <?php endif; ?>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Guidance</h2></div>
                <ul class="health-list">
                    <li class="ok"><?= icon('clock') ?><span>Acknowledge and respond to requests promptly, and keep the requester informed.</span></li>
                    <li class="ok"><?= icon('shield') ?><span>Confirm the requester's identity before sending data to an address that is not the account email.</span></li>
                    <li class="warn"><?= icon('file-text') ?><span>Donation and receipt records are retained for the foundation's accounts even when an account is deleted.</span></li>
                </ul>
            </section>
        </aside>
    </div>
    <?php
    admin_page_end();
    exit;
}

// ---------------------------------------------------------------------
// List
// ---------------------------------------------------------------------
$q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 120));
$status = (string) ($_GET['status'] ?? '');
$type = (string) ($_GET['type'] ?? '');
$where = ' WHERE 1 = 1';
$params = [];
$where .= search_where(['dr.email', 'u.name'], $q, $params);
if (in_array($status, application_statuses('data_requests'), true)) {
    $where .= ' AND dr.status = ?';
    $params[] = $status;
}
if (in_array($type, ['access', 'deletion', 'correction'], true)) {
    $where .= ' AND dr.request_type = ?';
    $params[] = $type;
} else {
    $type = '';
}
$total = (int) db_value('SELECT COUNT(*) FROM data_requests dr LEFT JOIN users u ON u.id = dr.user_id' . $where, $params);
$p = paginate($total, 25);
$rows = db_rows('SELECT dr.*, u.name AS user_name, h.name AS handler_name FROM data_requests dr LEFT JOIN users u ON u.id = dr.user_id LEFT JOIN users h ON h.id = dr.handled_by'
    . $where . " ORDER BY FIELD(dr.status, 'pending', 'in_progress', 'completed', 'rejected'), dr.created_at DESC LIMIT " . (int) $p['per_page'] . ' OFFSET ' . (int) $p['offset'], $params);
$open = db_row("SELECT SUM(status = 'pending') AS pending, SUM(status = 'in_progress') AS progress, SUM(status = 'completed') AS done FROM data_requests");

admin_page_start('Data Requests');
?>
<div class="stat-cards">
    <a class="stat-card" href="<?= e(admin_url('data-requests.php?status=pending')) ?>"><span class="icon-circle gold"><?= icon('inbox') ?></span><div><strong><?= (int) $open['pending'] ?></strong><small>Pending</small></div></a>
    <a class="stat-card" href="<?= e(admin_url('data-requests.php?status=in_progress')) ?>"><span class="icon-circle blue"><?= icon('clock') ?></span><div><strong><?= (int) $open['progress'] ?></strong><small>In progress</small></div></a>
    <a class="stat-card" href="<?= e(admin_url('data-requests.php?status=completed')) ?>"><span class="icon-circle"><?= icon('check-circle') ?></span><div><strong><?= (int) $open['done'] ?></strong><small>Completed</small></div></a>
</div>
<section class="panel">
    <?php
    $typeSelect = '<select name="type" aria-label="Request type"><option value="">All types</option>';
    foreach (['access' => 'Access', 'correction' => 'Correction', 'deletion' => 'Deletion'] as $k => $label) {
        $typeSelect .= '<option value="' . $k . '"' . ($type === $k ? ' selected' : '') . '>' . $label . '</option>';
    }
    $typeSelect .= '</select>';
    admin_filter_bar(application_statuses('data_requests'), ['placeholder' => 'Search email or name', 'html' => $typeSelect, 'active' => $type !== '']);
    ?>
    <?php if (!$rows): ?>
        <?= empty_state('No data requests found.') ?>
    <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>#</th><th>Received</th><th>Requester</th><th>Type</th><th>Status</th><th>Handled by</th><th class="actions-cell">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td>#<?= (int) $r['id'] ?></td>
                    <td class="nowrap"><?= e(format_date($r['created_at'], 'd M Y')) ?></td>
                    <td><?= e((string) ($r['user_name'] ?: '-')) ?><br><small class="muted"><?= e($r['email']) ?></small></td>
                    <td><span class="badge <?= $r['request_type'] === 'deletion' ? 'badge-red' : 'badge-blue' ?>"><?= e(humanize($r['request_type'])) ?></span></td>
                    <td><?= member_status_badge($r['status']) ?></td>
                    <td><?= e((string) ($r['handler_name'] ?: '-')) ?></td>
                    <td class="actions-cell"><a class="icon-btn" href="<?= e(admin_url('data-requests.php?id=' . (int) $r['id'])) ?>" aria-label="Open request #<?= (int) $r['id'] ?>" title="Open"><?= icon('eye') ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php endif; ?>
</section>
<?php
admin_page_end();
