<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('applications');

$q = clean_string($_GET['q'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$params = [];
$where = 'WHERE 1=1' . search_where(['application_no', 'name', 'email', 'phone', 'city', 'subjects', 'qualification'], $q, $params);
if (in_array($status, application_statuses('teacher_applications'), true)) {
    $where .= ' AND status = ?';
    $params[] = $status;
}
$p = paginate((int) db_value("SELECT COUNT(*) FROM teacher_applications $where", $params), 25);
$rows = db_rows("SELECT * FROM teacher_applications $where ORDER BY created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);

$adminTitle = 'Volunteer Teacher Applications';
require __DIR__ . '/includes/header.php';
admin_filter_bar(application_statuses('teacher_applications'));
?>
<div class="panel">
    <div class="table-meta"><span><?= number_format($p['total']) ?> application(s)</span></div>
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Application</th><th>Name</th><th>Contact</th><th>Subjects</th><th>Mode</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap"><a href="teacher-view.php?id=<?= (int) $r['id'] ?>"><strong><?= e($r['application_no']) ?></strong></a></td>
                    <td><?= e($r['name']) ?><br><small class="muted"><?= e($r['qualification']) ?></small></td>
                    <td><?= e($r['email']) ?><br><small class="muted"><?= e($r['phone']) ?></small></td>
                    <td><?= e(excerpt($r['subjects'], 50)) ?></td>
                    <td><?= e(humanize($r['teaching_mode'])) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="nowrap"><?= e(format_date($r['created_at'])) ?></td>
                    <td class="actions-cell"><a class="icon-btn" href="teacher-view.php?id=<?= (int) $r['id'] ?>" aria-label="Review"><?= icon('eye') ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state('No teacher applications found.') ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
