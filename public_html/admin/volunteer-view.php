<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('applications');

$r = db_row('SELECT v.*, a.name AS reviewer_name FROM volunteers v LEFT JOIN admins a ON a.id = v.reviewed_by WHERE v.id = ?', [(int) ($_GET['id'] ?? 0)]);
if (!$r) {
    flash('error', 'Application not found.');
    admin_redirect('volunteers.php');
}
$certs = $r['user_id'] ? db_rows('SELECT * FROM certificates WHERE user_id = ? ORDER BY id DESC', [$r['user_id']]) : [];
$adminTitle = humanize($r['application_type']) . ' application ' . $r['application_no'];
require __DIR__ . '/includes/header.php';
?>
<div class="page-actions"><a class="btn btn-ghost btn-sm" href="volunteers.php">&larr; All applications</a></div>
<div class="admin-grid-main">
    <div>
        <div class="panel">
            <div class="panel-head"><h3><?= e($r['name']) ?></h3><?= status_badge($r['status']) ?></div>
            <?php details_list([
                'Application no.' => $r['application_no'],
                'Type' => humanize($r['application_type']),
                'Email' => $r['email'],
                'Phone' => $r['phone'],
                'Date of birth' => $r['date_of_birth'] ? format_date($r['date_of_birth']) : '',
                'Gender' => GENDERS[$r['gender']] ?? '',
                'Address' => trim($r['address'] . ', ' . $r['city'] . ', ' . $r['state'] . ' ' . $r['pincode'], ', '),
                'Occupation' => $r['occupation'],
                'Education' => $r['education'],
                'Interests' => $r['interests'],
                'Availability' => $r['availability'],
                'Skills' => $r['skills'],
                'Experience' => $r['experience'],
                'Motivation' => $r['motivation'],
                'Website account' => $r['user_id'] ? ['<a href="members.php?id=' . (int) $r['user_id'] . '">View account</a>'] : 'Applied as guest',
                'Submitted' => format_date($r['created_at'], 'd M Y, h:i A') . ' (IP ' . $r['ip_address'] . ')',
            ]); ?>
        </div>
        <?php if ($certs): ?>
            <div class="panel"><h3>Certificates issued</h3>
                <ul class="donor-list"><?php foreach ($certs as $c): ?><li><span><?= e($c['certificate_no']) ?> — <?= e($c['title']) ?></span><?= status_badge($c['status']) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>
    </div>
    <div>
        <?php review_form('volunteers', $r); ?>
        <?php certificate_form($r['user_id'] ? (int) $r['user_id'] : null, $r['name'], $r['email'], $r['application_type'] === 'intern' ? 'internship' : 'volunteer', $r['application_type'] === 'intern' ? 'Certificate of Internship' : 'Certificate of Volunteering'); ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
