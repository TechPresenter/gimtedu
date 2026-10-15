<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('applications');

$id = (int) ($_GET['id'] ?? 0);
if ($id) {
    $r = db_row('SELECT s.*, a.name AS reviewer_name FROM staff_applications s LEFT JOIN admins a ON a.id = s.reviewed_by WHERE s.id = ?', [$id]);
    if (!$r) {
        flash('error', 'Application not found.');
        admin_redirect('staff-applications.php');
    }
    if (isset($_GET['download'])) {
        admin_log('document_downloaded', 'Staff resume ' . $r['application_no']);
        send_private_file($r['resume_path'], $r['application_no'] . '-resume');
    }
    $adminTitle = 'Job application ' . $r['application_no'];
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="page-actions"><a class="btn btn-ghost btn-sm" href="staff-applications.php">&larr; All staff applications</a></div>
    <div class="admin-grid-main">
        <div class="panel">
            <div class="panel-head"><h3><?= e($r['name']) ?> — <?= e($r['position']) ?></h3><?= status_badge($r['status']) ?></div>
            <?php details_list([
                'Application no.' => $r['application_no'],
                'Position' => $r['position'],
                'Email' => $r['email'],
                'Phone' => $r['phone'],
                'Date of birth' => $r['date_of_birth'] ? format_date($r['date_of_birth']) : '',
                'Gender' => GENDERS[$r['gender']] ?? '',
                'Address' => trim($r['address'] . ', ' . $r['city'] . ', ' . $r['state'] . ' ' . $r['pincode'], ', '),
                'Qualification' => $r['qualification'],
                'Experience' => rtrim(rtrim((string) $r['experience_years'], '0'), '.') . ' years',
                'Current organisation' => $r['current_organization'],
                'Notice period' => $r['notice_period'],
                'Interview' => $r['interview_at'] ? format_date($r['interview_at'], 'd M Y, h:i A') : '',
                'CV / resume' => $r['resume_path'] ? ['<a class="btn btn-ghost btn-sm" href="staff-applications.php?id=' . (int) $r['id'] . '&amp;download=1">' . icon('download') . 'Download CV</a>'] : 'Not uploaded',
                'Cover letter' => $r['cover_letter'],
                'Submitted' => format_date($r['created_at'], 'd M Y, h:i A'),
            ]); ?>
        </div>
        <div><?php review_form('staff_applications', $r, ['interview' => true]); ?></div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$q = clean_string($_GET['q'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$params = [];
$where = 'WHERE 1=1' . search_where(['application_no', 'name', 'email', 'phone', 'position', 'city'], $q, $params);
if (in_array($status, application_statuses('staff_applications'), true)) {
    $where .= ' AND status = ?';
    $params[] = $status;
}
$p = paginate((int) db_value("SELECT COUNT(*) FROM staff_applications $where", $params), 25);
$rows = db_rows("SELECT * FROM staff_applications $where ORDER BY created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);

$adminTitle = 'Staff Applications';
require __DIR__ . '/includes/header.php';
admin_filter_bar(application_statuses('staff_applications'), ['placeholder' => 'Name, email, phone, position or reference']);
?>
<div class="page-actions">
    <span class="muted small"><?= number_format($p['total']) ?> application(s)</span>
    <?php if (admin_can('settings')): ?><a class="btn btn-ghost btn-sm" href="settings.php#positions">Manage open positions</a><?php endif; ?>
</div>
<div class="panel">
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Application</th><th>Name</th><th>Position</th><th>Experience</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap"><a href="?id=<?= (int) $r['id'] ?>"><strong><?= e($r['application_no']) ?></strong></a></td>
                    <td><?= e($r['name']) ?><br><small class="muted"><?= e($r['email']) ?></small></td>
                    <td><?= e($r['position']) ?></td>
                    <td><?= e(rtrim(rtrim((string) $r['experience_years'], '0'), '.')) ?> yrs</td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="nowrap"><?= e(format_date($r['created_at'])) ?></td>
                    <td class="actions-cell"><a class="icon-btn" href="?id=<?= (int) $r['id'] ?>" aria-label="Review"><?= icon('eye') ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state('No staff applications found.') ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
