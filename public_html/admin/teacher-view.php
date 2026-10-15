<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('applications');

$r = db_row('SELECT t.*, a.name AS reviewer_name FROM teacher_applications t LEFT JOIN admins a ON a.id = t.reviewed_by WHERE t.id = ?', [(int) ($_GET['id'] ?? 0)]);
if (!$r) {
    flash('error', 'Application not found.');
    admin_redirect('teachers.php');
}
if (isset($_GET['download'])) {
    admin_log('document_downloaded', 'Teacher resume ' . $r['application_no']);
    send_private_file($r['resume_path'], $r['application_no'] . '-resume');
}
$adminTitle = 'Teacher application ' . $r['application_no'];
require __DIR__ . '/includes/header.php';
?>
<div class="page-actions"><a class="btn btn-ghost btn-sm" href="teachers.php">&larr; All teacher applications</a></div>
<div class="admin-grid-main">
    <div class="panel">
        <div class="panel-head"><h3><?= e($r['name']) ?></h3><?= status_badge($r['status']) ?></div>
        <?php details_list([
            'Application no.' => $r['application_no'],
            'Email' => $r['email'],
            'Phone' => $r['phone'],
            'Date of birth' => $r['date_of_birth'] ? format_date($r['date_of_birth']) : '',
            'Gender' => GENDERS[$r['gender']] ?? '',
            'Address' => trim($r['address'] . ', ' . $r['city'] . ', ' . $r['state'] . ' ' . $r['pincode'], ', '),
            'Qualification' => $r['qualification'],
            'Subjects' => $r['subjects'],
            'Preferred level' => $r['classes_preferred'],
            'Languages' => $r['languages'],
            'Experience' => $r['teaching_experience'],
            'Mode' => humanize($r['teaching_mode']),
            'Availability' => $r['availability'],
            'CV / resume' => $r['resume_path'] ? ['<a class="btn btn-ghost btn-sm" href="teacher-view.php?id=' . (int) $r['id'] . '&amp;download=1">' . icon('download') . 'Download</a>'] : 'Not uploaded',
            'Motivation' => $r['motivation'],
            'Website account' => $r['user_id'] ? ['<a href="members.php?id=' . (int) $r['user_id'] . '">View account</a>'] : 'Applied as guest',
            'Submitted' => format_date($r['created_at'], 'd M Y, h:i A'),
        ]); ?>
    </div>
    <div>
        <?php review_form('teacher_applications', $r); ?>
        <?php certificate_form($r['user_id'] ? (int) $r['user_id'] : null, $r['name'], $r['email'], 'teaching', 'Certificate of Appreciation for Volunteer Teaching'); ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
