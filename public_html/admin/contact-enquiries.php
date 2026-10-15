<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('enquiries');

$id = (int) ($_GET['id'] ?? 0);
if ($id) {
    $r = db_row('SELECT c.*, a.name AS handler FROM contact_enquiries c LEFT JOIN admins a ON a.id = c.handled_by WHERE c.id = ?', [$id]);
    if (!$r) {
        flash('error', 'Enquiry not found.');
        admin_redirect('contact-enquiries.php');
    }
    $adminTitle = humanize($r['type']) . ' ' . $r['reference_no'];
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="page-actions">
        <a class="btn btn-ghost btn-sm" href="contact-enquiries.php">&larr; All enquiries</a>
        <a class="btn btn-primary btn-sm" href="mailto:<?= e($r['email']) ?>?subject=<?= e(rawurlencode('Re: ' . $r['subject'] . ' [' . $r['reference_no'] . ']')) ?>"><?= icon('mail') ?>Reply by email</a>
    </div>
    <div class="admin-grid-main">
        <div class="panel">
            <div class="panel-head"><h3><?= e($r['subject']) ?></h3><?= status_badge($r['status']) ?></div>
            <?php details_list([
                'Reference' => $r['reference_no'],
                'Type' => humanize($r['type']),
                'From' => $r['name'],
                'Email' => $r['email'],
                'Phone' => $r['phone'],
                'Received' => format_date($r['created_at'], 'd M Y, h:i A') . ' (IP ' . $r['ip_address'] . ')',
                'Handled by' => $r['handler'],
            ]); ?>
            <h4 class="mt-3">Message</h4>
            <div class="note-box" style="background:var(--bg-soft);border-color:var(--line)"><?= e($r['message']) ?></div>
        </div>
        <div><?php review_form('contact_enquiries', $r); ?></div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$q = clean_string($_GET['q'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$type = (string) ($_GET['type'] ?? '');
$params = [];
$where = 'WHERE 1=1' . search_where(['reference_no', 'name', 'email', 'phone', 'subject'], $q, $params);
if (in_array($status, application_statuses('contact_enquiries'), true)) {
    $where .= ' AND status = ?';
    $params[] = $status;
}
if (in_array($type, ['contact', 'feedback', 'grievance'], true)) {
    $where .= ' AND type = ?';
    $params[] = $type;
}
$p = paginate((int) db_value("SELECT COUNT(*) FROM contact_enquiries $where", $params), 25);
$rows = db_rows("SELECT * FROM contact_enquiries $where ORDER BY (status = 'new') DESC, created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);

$adminTitle = 'Enquiries, Feedback & Grievances';
require __DIR__ . '/includes/header.php';
admin_filter_bar(application_statuses('contact_enquiries'), ['html' => '<select name="type" aria-label="Type"><option value="">All types</option>' . options_html(['contact' => 'Enquiries', 'feedback' => 'Feedback', 'grievance' => 'Grievances'], $type, true, '') . '</select>', 'active' => $type !== '', 'placeholder' => 'Name, email, phone, subject or reference']);
?>
<div class="panel">
    <div class="table-meta"><span><?= number_format($p['total']) ?> message(s)</span></div>
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Reference</th><th>Type</th><th>From</th><th>Subject</th><th>Status</th><th>Received</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap"><a href="?id=<?= (int) $r['id'] ?>"><strong><?= e($r['reference_no']) ?></strong></a></td>
                    <td><?= $r['type'] === 'grievance' ? '<span class="badge badge-red">Grievance</span>' : e(humanize($r['type'])) ?></td>
                    <td><?= e($r['name']) ?><br><small class="muted"><?= e($r['email']) ?></small></td>
                    <td><?= e(excerpt($r['subject'], 60)) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="nowrap"><?= e(format_date($r['created_at'], 'd M Y, h:i A')) ?></td>
                    <td class="actions-cell"><a class="icon-btn" href="?id=<?= (int) $r['id'] ?>" aria-label="Open"><?= icon('eye') ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state('No messages found.') ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
