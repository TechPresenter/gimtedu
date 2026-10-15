<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('applications');

$q = clean_string($_GET['q'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$kind = (string) ($_GET['kind'] ?? '');
$params = [];
$where = 'WHERE 1=1' . search_where(['application_no', 'name', 'email', 'phone', 'city', 'interests'], $q, $params);
if (in_array($status, application_statuses('volunteers'), true)) {
    $where .= ' AND status = ?';
    $params[] = $status;
}
if (in_array($kind, ['volunteer', 'intern'], true)) {
    $where .= ' AND application_type = ?';
    $params[] = $kind;
}
if (($_GET['export'] ?? '') === 'csv') {
    require_permission('reports');
    $rows = db_rows("SELECT application_no, application_type, name, email, phone, city, state, interests, availability, status, created_at FROM volunteers $where ORDER BY created_at DESC", $params);
    admin_log('volunteers_exported', count($rows) . ' rows');
    send_csv('volunteers-' . date('Ymd') . '.csv', ['Application No', 'Type', 'Name', 'Email', 'Phone', 'City', 'State', 'Interests', 'Availability', 'Status', 'Submitted'], $rows);
}
$p = paginate((int) db_value("SELECT COUNT(*) FROM volunteers $where", $params), 25);
$rows = db_rows("SELECT * FROM volunteers $where ORDER BY created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);

$adminTitle = 'Volunteers & Interns';
require __DIR__ . '/includes/header.php';
admin_filter_bar(application_statuses('volunteers'), [
    'html' => '<select name="kind" aria-label="Type"><option value="">Volunteers &amp; interns</option>' . options_html(['volunteer' => 'Volunteers', 'intern' => 'Interns'], $kind, true, '') . '</select>',
    'active' => $kind !== '',
]);
?>
<div class="page-actions">
    <span class="muted small"><?= number_format($p['total']) ?> application(s)</span>
    <?php if (admin_can('reports')): ?><a class="btn btn-ghost btn-sm" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv', 'page' => null]))) ?>"><?= icon('download') ?>Export CSV</a><?php endif; ?>
</div>
<div class="panel">
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Application</th><th>Name</th><th>Contact</th><th>City</th><th>Interests</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap"><a href="volunteer-view.php?id=<?= (int) $r['id'] ?>"><strong><?= e($r['application_no']) ?></strong></a><br><small class="muted"><?= e(humanize($r['application_type'])) ?></small></td>
                    <td><?= e($r['name']) ?></td>
                    <td><?= e($r['email']) ?><br><small class="muted"><?= e($r['phone']) ?></small></td>
                    <td><?= e($r['city']) ?></td>
                    <td><?= e(excerpt($r['interests'], 50)) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="nowrap"><?= e(format_date($r['created_at'])) ?></td>
                    <td class="actions-cell"><a class="icon-btn" href="volunteer-view.php?id=<?= (int) $r['id'] ?>" aria-label="Review"><?= icon('eye') ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state('No applications found.') ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
