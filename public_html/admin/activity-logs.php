<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('logs');

$q = clean_string($_GET['q'] ?? '');
$who = (string) ($_GET['who'] ?? '');
$params = [];
$where = 'WHERE 1=1' . search_where(['l.action', 'l.details', 'l.ip_address'], $q, $params);
if ($who === 'admin') {
    $where .= ' AND l.admin_id IS NOT NULL';
} elseif ($who === 'security') {
    $where .= " AND (l.action LIKE '%failed%' OR l.action LIKE '%invalid%' OR l.action = 'access_denied')";
}
$p = paginate((int) db_value("SELECT COUNT(*) FROM activity_logs l $where", $params), 50);
$rows = db_rows("SELECT l.*, a.name AS admin_name, u.name AS user_name FROM activity_logs l
                 LEFT JOIN admins a ON a.id = l.admin_id LEFT JOIN users u ON u.id = l.user_id
                 $where ORDER BY l.id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$adminTitle = 'Activity Logs';
require __DIR__ . '/includes/header.php';
admin_filter_bar([], [
    'html' => '<select name="who" aria-label="Filter"><option value="">All activity</option>' . options_html(['admin' => 'Admin actions', 'security' => 'Security events (failed logins, denied access)'], $who, true, '') . '</select>',
    'active' => $who !== '', 'placeholder' => 'Search action, details or IP',
]);
?>
<div class="panel">
    <div class="table-meta"><span><?= number_format($p['total']) ?> entries</span></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Time</th><th>Who</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="nowrap"><?= e(format_date($r['created_at'], 'd M Y, h:i:s A')) ?></td>
                <td><?= $r['admin_name'] ? '<span class="badge badge-blue">Admin</span> ' . e($r['admin_name']) : ($r['user_name'] ? '<span class="badge badge-gray">User</span> ' . e($r['user_name']) : '<span class="muted">Visitor</span>') ?></td>
                <td><code><?= e($r['action']) ?></code></td>
                <td><?= e(excerpt($r['details'], 120)) ?></td>
                <td class="nowrap"><?= e($r['ip_address']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?= pagination_links($p) ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
