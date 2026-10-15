<?php
/**
 * Enquiries, grievances and fraud reports (contact_enquiries).
 * Workflow: new -> in_progress -> resolved / closed. Internal notes, reply by email (the reply is appended to
 * the internal notes with the date), handler assignment and CSV export. Every change is written to the audit log.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('enquiries.manage');

$statuses = application_statuses('contact_enquiries');

/** Staff members who may handle enquiries. */
$handlers = [];
foreach (db_rows("SELECT DISTINCT u.id, u.name FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                  WHERE r.is_staff = 1 AND u.status = 'active' ORDER BY u.name") as $h) {
    if (user_can('enquiries.manage', (int) $h['id'])) {
        $handlers[(int) $h['id']] = $h['name'];
    }
}

$appendNote = function (?string $notes, string $text): string {
    $admin = current_admin();
    $entry = '[' . date('d M Y, h:i A') . '] ' . ($admin['name'] ?? 'Staff') . ': ' . $text;
    return trim((string) $notes) === '' ? $entry : rtrim((string) $notes) . "\n\n" . $entry;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_only('enquiries.php');
    $id = (int) ($_POST['id'] ?? 0);
    $enq = db_row('SELECT * FROM contact_enquiries WHERE id = ?', [$id]);
    if (!$enq) {
        flash('error', 'Enquiry not found.');
        admin_redirect('enquiries.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    $back = 'enquiries.php?id=' . $id;

    if ($action === 'update') {
        [$data, $errors] = validate($_POST, ['status' => 'required|in:' . implode(',', $statuses), 'handled_by' => 'integer', 'note' => 'text|max:3000']);
        $handler = (int) ($data['handled_by'] ?: 0);
        if ($handler && !isset($handlers[$handler])) {
            $errors['handled_by'] = 'Choose a staff member who can handle enquiries.';
        }
        if ($errors) {
            back_with_errors($errors, 'admin/' . $back, $_POST);
        }
        $row = ['status' => $data['status'], 'handled_by' => $handler ?: null];
        $changes = [];
        if ($data['status'] !== $enq['status']) {
            $changes[] = 'status ' . humanize($enq['status']) . ' -> ' . humanize($data['status']);
        }
        if ((int) $enq['handled_by'] !== $handler) {
            $changes[] = 'handler: ' . ($handler ? $handlers[$handler] : 'none');
        }
        $notes = $enq['admin_notes'];
        if ($changes) {
            $notes = $appendNote($notes, ucfirst(implode('; ', $changes)) . '.');
        }
        if ($data['note'] !== '') {
            $notes = $appendNote($notes, 'Note: ' . $data['note']);
            $changes[] = 'note added';
        }
        $row['admin_notes'] = $notes;
        db_update('contact_enquiries', $row, 'id = :id', ['id' => $id]);
        admin_log('enquiry_updated', $enq['reference_no'] . ': ' . ($changes ? implode('; ', $changes) : 'no changes'));
        flash('success', $changes ? 'Enquiry updated.' : 'No changes to save.');
        admin_redirect($back);
    }

    if ($action === 'reply') {
        [$data, $errors] = validate($_POST, ['reply' => 'required|text|min:5|max:5000', 'reply_status' => 'in:,' . implode(',', $statuses)], ['reply' => 'Reply']);
        if ($errors) {
            back_with_errors($errors, 'admin/' . $back . '#reply', $_POST);
        }
        $subject = 'Re: ' . $enq['subject'] . ' [' . $enq['reference_no'] . ']';
        $body = '<p>Dear ' . e($enq['name']) . ',</p>' . '<div>' . e_nl($data['reply']) . '</div>'
            . '<p>Regards,<br>' . e(current_admin()['name'] ?? site_name()) . '<br>' . e(site_name()) . '</p>'
            . '<p style="font-size:12px;color:#5b6b62">Reference: ' . e($enq['reference_no']) . ' &middot; Your message of ' . e(format_date($enq['created_at'], 'd M Y')) . ':</p>'
            . '<blockquote style="margin:8px 0;padding:10px 14px;background:#f5f9f4;border-left:4px solid #146B43;font-size:13px">' . e_nl(excerpt($enq['message'], 1200)) . '</blockquote>';
        $sent = send_mail($enq['email'], $subject, $body, setting('contact_email') ?: null);
        $newStatus = $data['reply_status'] !== '' ? $data['reply_status'] : ($enq['status'] === 'new' ? 'in_progress' : $enq['status']);
        $notes = $appendNote($enq['admin_notes'], 'Reply emailed to ' . $enq['email'] . ($sent ? '' : ' (email could not be sent - please follow up by phone or email)') . ":\n" . $data['reply']);
        if ($newStatus !== $enq['status']) {
            $notes = $appendNote($notes, 'Status ' . humanize($enq['status']) . ' -> ' . humanize($newStatus) . '.');
        }
        db_update('contact_enquiries', ['admin_notes' => $notes, 'status' => $newStatus, 'handled_by' => $enq['handled_by'] ?: admin_id()], 'id = :id', ['id' => $id]);
        admin_log('enquiry_reply', $enq['reference_no'] . ': reply ' . ($sent ? 'emailed' : 'NOT sent (mail failure)') . ' to ' . $enq['email']);
        flash($sent ? 'success' : 'warning', $sent ? 'Reply sent and saved in the notes.' : 'The reply was saved in the notes, but the email could not be sent. Check the mail settings.');
        admin_redirect($back);
    }
    admin_redirect($back);
}

// ---------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------
$type = (string) ($_GET['type'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$q = trim((string) ($_GET['q'] ?? ''));
$params = [];
$where = 'WHERE 1=1';
if (isset(ENQUIRY_TYPES[$type])) {
    $where .= ' AND e.type = ?';
    $params[] = $type;
}
if (in_array($status, $statuses, true)) {
    $where .= ' AND e.status = ?';
    $params[] = $status;
}
$where .= search_where(['e.reference_no', 'e.name', 'e.email', 'e.phone', 'e.subject', 'e.message'], $q, $params);

if (isset($_GET['export'])) {
    require_permission('exports.run');
    $rows = db_rows("SELECT e.reference_no, e.type, e.status, e.name, e.email, e.phone, e.subject, e.message, u.name AS handler, e.created_at, e.updated_at
                     FROM contact_enquiries e LEFT JOIN users u ON u.id = e.handled_by $where ORDER BY e.created_at DESC", $params);
    admin_log('export_enquiries', count($rows) . ' enquiries exported' . ($type ? ' (' . $type . ')' : ''));
    send_csv('enquiries-' . ($type ?: 'all') . '-' . date('Y-m-d') . '.csv',
        ['Reference', 'Type', 'Status', 'Name', 'Email', 'Phone', 'Subject', 'Message', 'Handler', 'Received', 'Updated'], $rows);
}

$view = isset($_GET['id']) ? db_row('SELECT e.*, u.name AS handler_name, a.name AS account_name FROM contact_enquiries e
    LEFT JOIN users u ON u.id = e.handled_by LEFT JOIN users a ON a.id = e.user_id WHERE e.id = ?', [(int) $_GET['id']]) : null;

$typeCounts = [];
foreach (db_rows("SELECT type, COUNT(*) n, SUM(status = 'new') n_new FROM contact_enquiries GROUP BY type") as $c) {
    $typeCounts[$c['type']] = $c;
}
$totalAll = array_sum(array_map(function ($c) { return (int) $c['n']; }, $typeCounts));
$newAll = array_sum(array_map(function ($c) { return (int) $c['n_new']; }, $typeCounts));

admin_page_start($view ? 'Enquiry ' . ($view['reference_no'] ?: '#' . $view['id']) : 'Enquiries & Grievances',
    $view ? [['label' => 'Back to list', 'url' => admin_url('enquiries.php' . ($view ? '?type=' . $view['type'] : '')), 'icon' => 'chevron-left', 'style' => 'ghost']] : []);

if ($view):
    $isFraud = $view['type'] === 'fraud_report';
    ?>
    <?php if ($isFraud): ?>
        <div class="alert alert-error"><?= icon('alert-triangle') ?><div><strong>Fraud report.</strong> Review promptly. If the report shows a real, verified risk to the public, an administrator can switch on the fraud alert in Widgets &amp; Alerts. Never share the reporter's details publicly.</div></div>
    <?php endif; ?>
    <div class="admin-grid-main">
        <div>
            <section class="panel">
                <div class="panel-head"><h2><?= e($view['subject']) ?></h2><span><?= $isFraud ? '<span class="badge badge-red">Fraud report</span> ' : '' ?><?= status_badge($view['status']) ?></span></div>
                <?php details_list([
                    'Reference' => $view['reference_no'] ?: '#' . $view['id'],
                    'Type' => ENQUIRY_TYPES[$view['type']] ?? $view['type'],
                    'From' => $view['name'],
                    'Email' => ['<a href="mailto:' . e($view['email']) . '">' . e($view['email']) . '</a>'],
                    'Phone' => $view['phone'] ? ['<a href="tel:' . e($view['phone']) . '">' . e($view['phone']) . '</a>'] : '',
                    'Account' => $view['account_name'] ? $view['account_name'] . ' (registered user #' . (int) $view['user_id'] . ')' : '',
                    'Received' => format_date($view['created_at'], 'd M Y, h:i A'),
                    'Handler' => $view['handler_name'] ?: 'Not assigned',
                    'IP address' => $view['ip_address'] ?? '',
                ]); ?>
                <h3 class="mt-3">Message</h3>
                <div class="note-box" style="background:var(--bg-soft);border-color:var(--line)"><?= e($view['message']) ?></div>
            </section>

            <section class="panel" id="reply">
                <div class="panel-head"><h2>Reply by email</h2></div>
                <form method="post" class="form-grid">
                    <?= csrf_field() ?><input type="hidden" name="action" value="reply"><input type="hidden" name="id" value="<?= (int) $view['id'] ?>">
                    <div class="form-group full"><label for="reply">Reply to <?= e($view['email']) ?></label>
                        <textarea id="reply" name="reply" rows="7" maxlength="5000" required placeholder="Write your reply. A greeting, your name and the original message are added automatically."><?= e(old('reply')) ?></textarea><?= field_error('reply') ?>
                        <span class="help">Sent from the website's email address. The reply is saved in the internal notes with today's date.</span></div>
                    <div class="form-group"><label for="reply_status">Then set status to</label>
                        <select id="reply_status" name="reply_status"><option value="">Keep (new becomes in progress)</option><?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>"><?= e(humanize($s)) ?></option><?php endforeach; ?></select></div>
                    <div class="form-actions full" style="grid-column:1/-1"><button class="btn btn-primary" type="submit"><?= icon('send') ?>Send reply</button></div>
                </form>
            </section>
        </div>

        <div>
            <section class="panel">
                <div class="panel-head"><h2>Status &amp; handler</h2></div>
                <form method="post" class="form-grid">
                    <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $view['id'] ?>">
                    <div class="form-group full"><label for="status">Status</label>
                        <select id="status" name="status"><?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>"<?= $view['status'] === $s ? ' selected' : '' ?>><?= e(humanize($s)) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group full"><label for="handled_by">Handler</label>
                        <select id="handled_by" name="handled_by"><?= options_html($handlers, (string) ($view['handled_by'] ?? ''), true, 'Not assigned') ?></select><?= field_error('handled_by') ?></div>
                    <div class="form-group full"><label for="note">Add an internal note (optional)</label>
                        <textarea id="note" name="note" rows="4" maxlength="3000" placeholder="Visible to staff only"></textarea><?= field_error('note') ?></div>
                    <div class="form-actions full" style="grid-column:1/-1"><button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?>Save</button></div>
                </form>
                <p class="small muted mt-2">Workflow: <strong>New</strong> → <strong>In progress</strong> → <strong>Resolved</strong> (answered / action taken) or <strong>Closed</strong> (no action possible or needed).</p>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Internal notes &amp; history</h2></div>
                <?php if (trim((string) $view['admin_notes']) !== ''): ?>
                    <div class="note-box"><?= e($view['admin_notes']) ?></div>
                <?php else: ?>
                    <p class="muted">No notes yet.</p>
                <?php endif; ?>
            </section>
        </div>
    </div>
    <?php
    admin_page_end();
    exit;
endif;

$total = (int) db_value("SELECT COUNT(*) FROM contact_enquiries e $where", $params);
$p = paginate($total, 25);
$rows = db_rows("SELECT e.*, u.name AS handler_name FROM contact_enquiries e LEFT JOIN users u ON u.id = e.handled_by $where
                 ORDER BY FIELD(e.status, 'new', 'in_progress', 'resolved', 'closed'), e.created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$exportQuery = http_build_query(array_filter(['type' => $type, 'status' => $status, 'q' => $q, 'export' => 1]));
?>
<div class="stat-cards">
    <div class="stat-card"><span class="icon-circle gold"><?= icon('inbox') ?></span><div><strong><?= (int) $newAll ?></strong><small>New, not yet handled</small></div></div>
    <div class="stat-card"><span class="icon-circle"><?= icon('message') ?></span><div><strong><?= (int) $totalAll ?></strong><small>All enquiries</small></div></div>
    <div class="stat-card"><span class="icon-circle blue"><?= icon('shield') ?></span><div><strong><?= (int) ($typeCounts['grievance']['n_new'] ?? 0) ?></strong><small>New grievances</small></div></div>
    <div class="stat-card"><span class="icon-circle" style="background:var(--red-100);color:var(--red-600)"><?= icon('alert-triangle') ?></span><div><strong><?= (int) ($typeCounts['fraud_report']['n_new'] ?? 0) ?></strong><small>New fraud reports</small></div></div>
</div>

<nav class="admin-tabs" aria-label="Enquiry types">
    <a href="<?= e(admin_url('enquiries.php')) ?>" class="<?= $type === '' ? 'active' : '' ?>">All (<?= (int) $totalAll ?>)</a>
    <?php foreach (ENQUIRY_TYPES as $k => $label): $c = $typeCounts[$k] ?? ['n' => 0, 'n_new' => 0]; ?>
        <a href="<?= e(admin_url('enquiries.php?type=' . $k)) ?>" class="<?= $type === $k ? 'active' : '' ?>"><?= e($label) ?> (<?= (int) $c['n'] ?>)<?php if ((int) $c['n_new']): ?> <span class="badge badge-gold"><?= (int) $c['n_new'] ?> new</span><?php endif; ?></a>
    <?php endforeach; ?>
</nav>

<section class="panel">
    <?php admin_filter_bar($statuses, ['placeholder' => 'Search reference, name, email, phone or text', 'hidden' => $type !== '' ? ['type' => $type] : []]); ?>
    <div class="table-meta"><span><?= $total ?> <?= $total === 1 ? 'enquiry' : 'enquiries' ?></span>
        <?php if (admin_can('exports.run')): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('enquiries.php?' . $exportQuery)) ?>"><?= icon('download') ?>Export CSV</a><?php endif; ?></div>
    <?php if (!$rows): ?>
        <p class="muted">No enquiries found.</p>
    <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Reference</th><th>From</th><th>Subject</th><th>Type</th><th>Status</th><th>Handler</th><th>Received</th><th class="actions-cell">Open</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap"><a href="<?= e(admin_url('enquiries.php?id=' . $r['id'])) ?>"><strong><?= e($r['reference_no'] ?: '#' . $r['id']) ?></strong></a></td>
                    <td><?= e($r['name']) ?><br><small class="muted"><?= e($r['email']) ?></small></td>
                    <td><?= e(excerpt($r['subject'], 70)) ?></td>
                    <td><?= $r['type'] === 'fraud_report' ? '<span class="badge badge-red">Fraud report</span>' : e(ENQUIRY_TYPES[$r['type']] ?? $r['type']) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><?= e($r['handler_name'] ?: '-') ?></td>
                    <td class="nowrap"><?= e(format_date($r['created_at'], 'd M Y')) ?></td>
                    <td class="actions-cell"><a class="icon-btn" href="<?= e(admin_url('enquiries.php?id=' . $r['id'])) ?>" title="Open" aria-label="Open <?= e($r['reference_no']) ?>"><?= icon('eye') ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php endif; ?>
</section>
<?php
admin_page_end();
