<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('beneficiaries');

$id = (int) ($_GET['id'] ?? 0);
if ($id) {
    $r = db_row('SELECT s.*, a.name AS reviewer_name FROM scholarships s LEFT JOIN admins a ON a.id = s.reviewed_by WHERE s.id = ?', [$id]);
    if (!$r) {
        flash('error', 'Application not found.');
        admin_redirect('scholarships.php');
    }
    if (isset($_GET['download'])) {
        admin_log('document_downloaded', 'Scholarship document ' . $r['application_no']);
        send_private_file($r['document_path'], $r['application_no'] . '-document');
    }
    $adminTitle = 'Scholarship ' . $r['application_no'];
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="page-actions"><a class="btn btn-ghost btn-sm" href="scholarships.php">&larr; All scholarship applications</a></div>
    <div class="admin-grid-main">
        <div class="panel">
            <div class="panel-head"><h3><?= e($r['student_name']) ?></h3><?= status_badge($r['status']) ?></div>
            <?php details_list([
                'Application no.' => $r['application_no'],
                'Parent / guardian' => $r['guardian_name'],
                'Email' => $r['email'],
                'Phone' => $r['phone'],
                'Date of birth' => $r['date_of_birth'] ? format_date($r['date_of_birth']) : '',
                'Gender' => GENDERS[$r['gender']] ?? '',
                'Address' => trim($r['address'] . ', ' . $r['city'] . ', ' . $r['state'] . ' ' . $r['pincode'], ', '),
                'Institution' => $r['institution'],
                'Class / course' => $r['course_class'],
                'Academic year' => $r['academic_year'],
                'Last exam result' => $r['last_exam_percentage'] !== null ? $r['last_exam_percentage'] . '%' : '',
                'Annual family income' => money($r['annual_family_income']),
                'Reason' => $r['reason'],
                'Documents' => $r['document_path'] ? ['<a class="btn btn-ghost btn-sm" href="scholarships.php?id=' . (int) $r['id'] . '&amp;download=1">' . icon('download') . 'Download</a>'] : 'None',
                'Submitted' => format_date($r['created_at'], 'd M Y, h:i A'),
            ]); ?>
        </div>
        <div><?php review_form('scholarships', $r + ['name' => $r['student_name']]); ?></div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$q = clean_string($_GET['q'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$params = [];
$where = 'WHERE 1=1' . search_where(['application_no', 'student_name', 'guardian_name', 'email', 'phone', 'institution'], $q, $params);
if (in_array($status, application_statuses('scholarships'), true)) {
    $where .= ' AND status = ?';
    $params[] = $status;
}
$p = paginate((int) db_value("SELECT COUNT(*) FROM scholarships $where", $params), 25);
$rows = db_rows("SELECT * FROM scholarships $where ORDER BY created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);

$adminTitle = 'Scholarship Applications';
require __DIR__ . '/includes/header.php';
admin_filter_bar(application_statuses('scholarships'));
?>
<div class="panel">
    <div class="table-meta"><span><?= number_format($p['total']) ?> application(s)</span></div>
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Application</th><th>Student</th><th>Course</th><th>Family income</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap"><a href="?id=<?= (int) $r['id'] ?>"><strong><?= e($r['application_no']) ?></strong></a></td>
                    <td><?= e($r['student_name']) ?><br><small class="muted"><?= e($r['phone']) ?></small></td>
                    <td><?= e($r['course_class']) ?><br><small class="muted"><?= e(excerpt($r['institution'], 40)) ?></small></td>
                    <td class="nowrap"><?= e(money($r['annual_family_income'])) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="nowrap"><?= e(format_date($r['created_at'])) ?></td>
                    <td class="actions-cell"><a class="icon-btn" href="?id=<?= (int) $r['id'] ?>" aria-label="Review"><?= icon('eye') ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state('No scholarship applications found.') ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
