<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('members');

$id = (int) ($_GET['id'] ?? 0);
if ($id) {
    $u = db_row('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$u) {
        flash('error', 'User not found.');
        admin_redirect('members.php');
    }
    $m = db_row('SELECT m.*, a.name AS approver FROM members m LEFT JOIN admins a ON a.id = m.approved_by WHERE m.user_id = ?', [$id]);
    $donated = db_row("SELECT COUNT(*) n, COALESCE(SUM(amount), 0) s FROM donations WHERE user_id = ? AND status = 'success'", [$id]);
    $certs = db_rows('SELECT * FROM certificates WHERE user_id = ? ORDER BY id DESC', [$id]);
    $apps = db_rows("SELECT 'Volunteer' k, application_no ref, status, created_at, CONCAT('volunteer-view.php?id=', id) link FROM volunteers WHERE user_id = ?
                     UNION ALL SELECT 'Teacher', application_no, status, created_at, CONCAT('teacher-view.php?id=', id) FROM teacher_applications WHERE user_id = ?
                     UNION ALL SELECT 'Staff', application_no, status, created_at, CONCAT('staff-applications.php?id=', id) FROM staff_applications WHERE user_id = ?
                     ORDER BY created_at DESC", [$id, $id, $id]);
    $adminTitle = $u['name'];
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="page-actions"><a class="btn btn-ghost btn-sm" href="members.php">&larr; All members &amp; users</a></div>
    <div class="admin-grid-main">
        <div>
            <div class="panel">
                <div class="panel-head"><h3>Account</h3><?= status_badge($u['status']) ?></div>
                <?php details_list([
                    'Name' => $u['name'], 'Email' => $u['email'], 'Phone' => $u['phone'],
                    'Address' => trim($u['address'] . ', ' . $u['city'] . ', ' . $u['state'] . ' ' . $u['pincode'], ', '),
                    'Registered' => format_date($u['created_at'], 'd M Y, h:i A'),
                    'Last login' => format_date($u['last_login_at'], 'd M Y, h:i A'),
                    'Verified donations' => $donated['n'] . ' (' . money($donated['s'], true) . ')',
                ]); ?>
                <form method="post" action="actions/application-update.php" class="mt-3" data-confirm="<?= $u['status'] === 'active' ? 'Block this user? They will not be able to log in.' : 'Re-activate this user?' ?>">
                    <?= csrf_field() ?><?= return_field() ?>
                    <input type="hidden" name="type" value="users"><input type="hidden" name="action" value="user_status"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <input type="hidden" name="status" value="<?= $u['status'] === 'active' ? 'blocked' : 'active' ?>">
                    <button class="btn btn-sm <?= $u['status'] === 'active' ? 'btn-danger' : 'btn-primary' ?>" type="submit"><?= $u['status'] === 'active' ? 'Block user' : 'Re-activate user' ?></button>
                </form>
            </div>
            <?php if ($m): ?>
                <div class="panel">
                    <div class="panel-head"><h3>Membership application</h3><?= status_badge($m['status']) ?></div>
                    <?php details_list([
                        'Membership type' => humanize($m['membership_type']),
                        'Membership no.' => $m['membership_no'],
                        'Date of birth' => $m['date_of_birth'] ? format_date($m['date_of_birth']) : '',
                        'Gender' => GENDERS[$m['gender']] ?? '',
                        'Occupation' => $m['occupation'],
                        'Address' => trim($m['address'] . ', ' . $m['city'] . ', ' . $m['state'] . ' ' . $m['pincode'], ', '),
                        'Interests' => $m['areas_of_interest'],
                        'Motivation' => $m['motivation'],
                        'Applied' => format_date($m['created_at'], 'd M Y, h:i A'),
                        'Approved' => $m['approved_at'] ? format_date($m['approved_at'], 'd M Y') . ($m['approver'] ? ' by ' . $m['approver'] : '') : '',
                    ]); ?>
                </div>
            <?php endif; ?>
            <?php if ($apps): ?>
                <div class="panel"><h3>Applications</h3>
                    <ul class="donor-list"><?php foreach ($apps as $a): ?><li><a href="<?= e($a['link']) ?>"><?= e($a['k']) ?> · <?= e($a['ref']) ?></a><?= status_badge($a['status']) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
            <div class="panel"><h3>Certificates</h3>
                <?php if ($certs): ?>
                    <div class="table-wrap"><table class="table"><tbody>
                    <?php foreach ($certs as $c): ?>
                        <tr><td><strong><?= e($c['certificate_no']) ?></strong><br><small class="muted"><?= e($c['title']) ?></small></td><td><?= e(format_date($c['issue_date'])) ?></td><td><?= status_badge($c['status']) ?></td>
                            <td class="actions-cell"><?php if ($c['status'] === 'valid'): ?>
                                <form class="inline-form" method="post" action="actions/application-update.php" data-confirm="Revoke this certificate?">
                                    <?= csrf_field() ?><?= return_field() ?><input type="hidden" name="type" value="certificates"><input type="hidden" name="action" value="revoke_certificate"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit">Revoke</button></form><?php endif; ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                <?php else: ?><p class="muted mb-0">No certificates issued.</p><?php endif; ?>
            </div>
        </div>
        <div>
            <?php if ($m) { review_form('members', $m + ['email' => $u['email']], ['valid_until' => true]); } ?>
            <?php certificate_form((int) $u['id'], $u['name'], $u['email'], $m ? 'membership' : 'appreciation', $m ? 'Certificate of Membership' : 'Certificate of Appreciation'); ?>
        </div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$q = clean_string($_GET['q'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$params = [];
$where = 'WHERE 1=1' . search_where(['u.name', 'u.email', 'u.phone', 'm.membership_no', 'u.city'], $q, $params);
if (in_array($status, application_statuses('members'), true)) {
    $where .= ' AND m.status = ?';
    $params[] = $status;
} elseif ($status === 'none') {
    $where .= ' AND m.id IS NULL';
} elseif ($status === 'blocked') {
    $where .= " AND u.status = 'blocked'";
}
if (($_GET['export'] ?? '') === 'csv') {
    require_permission('reports');
    $rows = db_rows("SELECT u.name, u.email, u.phone, u.city, u.state, u.status, m.membership_no, m.membership_type, m.status AS membership_status, u.created_at
                     FROM users u LEFT JOIN members m ON m.user_id = u.id $where ORDER BY u.created_at DESC", $params);
    admin_log('members_exported', count($rows) . ' rows');
    send_csv('members-' . date('Ymd') . '.csv', ['Name', 'Email', 'Phone', 'City', 'State', 'Account', 'Membership No', 'Type', 'Membership Status', 'Registered'], $rows);
}
$p = paginate((int) db_value("SELECT COUNT(*) FROM users u LEFT JOIN members m ON m.user_id = u.id $where", $params), 25);
$rows = db_rows("SELECT u.*, m.membership_no, m.membership_type, m.status AS membership_status
                 FROM users u LEFT JOIN members m ON m.user_id = u.id $where
                 ORDER BY (m.status = 'pending') DESC, u.created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);

$adminTitle = 'Members & Users';
require __DIR__ . '/includes/header.php';
admin_filter_bar([], [
    'html' => '<select name="status" aria-label="Membership"><option value="">All users</option>' . options_html(['pending' => 'Membership pending', 'approved' => 'Approved members', 'rejected' => 'Rejected', 'suspended' => 'Suspended', 'none' => 'Not a member', 'blocked' => 'Blocked accounts'], $status, true, '') . '</select>',
    'active' => $status !== '', 'placeholder' => 'Name, email, phone, city or membership no.',
]);
?>
<div class="page-actions">
    <span class="muted small"><?= number_format($p['total']) ?> user(s)</span>
    <?php if (admin_can('reports')): ?><a class="btn btn-ghost btn-sm" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv', 'page' => null]))) ?>"><?= icon('download') ?>Export CSV</a><?php endif; ?>
</div>
<div class="panel">
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Name</th><th>Contact</th><th>City</th><th>Membership</th><th>Account</th><th>Registered</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><a href="?id=<?= (int) $r['id'] ?>"><strong><?= e($r['name']) ?></strong></a></td>
                    <td><?= e($r['email']) ?><br><small class="muted"><?= e($r['phone']) ?></small></td>
                    <td><?= e($r['city'] ?: '-') ?></td>
                    <td><?= $r['membership_status'] ? status_badge($r['membership_status']) . '<br><small class="muted">' . e(humanize($r['membership_type'])) . ' ' . e($r['membership_no']) . '</small>' : '<span class="muted">—</span>' ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="nowrap"><?= e(format_date($r['created_at'])) ?></td>
                    <td class="actions-cell"><a class="icon-btn" href="?id=<?= (int) $r['id'] ?>" aria-label="View"><?= icon('eye') ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state('No users found.') ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
