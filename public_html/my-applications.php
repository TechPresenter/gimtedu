<?php
require_once __DIR__ . '/functions.php';

$user = require_login();
$uid = (int) $user['id'];

// Download a document the user uploaded with one of their own applications
$docTables = [
    'teacher' => ['teacher_applications', 'resume_path', 'application_no'],
    'staff' => ['staff_applications', 'resume_path', 'application_no'],
    'scholarship' => ['scholarships', 'document_path', 'application_no'],
    'help' => ['beneficiaries', 'document_path', 'reference_no'],
];
if (isset($_GET['download'], $_GET['id'], $docTables[$_GET['download']])) {
    [$table, $col, $refCol] = $docTables[$_GET['download']];
    $row = db_row("SELECT `$col` AS path, `$refCol` AS ref FROM `$table` WHERE id = ? AND user_id = ?", [(int) $_GET['id'], $uid]);
    if (!$row) {
        not_found();
    }
    send_private_file($row['path'], $row['ref'] ?: 'document');
}

$apps = db_rows("
    SELECT 'volunteer' AS kind, id, application_no AS ref, IF(application_type = 'intern', 'Internship', 'Volunteer') AS label, interests AS detail, status, created_at, updated_at, NULL AS doc
      FROM volunteers WHERE user_id = ?
    UNION ALL
    SELECT 'teacher', id, application_no, 'Volunteer Teacher', subjects, status, created_at, updated_at, resume_path FROM teacher_applications WHERE user_id = ?
    UNION ALL
    SELECT 'staff', id, application_no, 'Job Application', position, status, created_at, updated_at, resume_path FROM staff_applications WHERE user_id = ?
    UNION ALL
    SELECT 'scholarship', id, application_no, 'Scholarship', CONCAT(course_class, ' - ', institution), status, created_at, updated_at, document_path FROM scholarships WHERE user_id = ?
    UNION ALL
    SELECT 'help', id, reference_no, 'Request for Help', help_category, status, created_at, updated_at, document_path FROM beneficiaries WHERE user_id = ?
    ORDER BY created_at DESC", [$uid, $uid, $uid, $uid, $uid]);
$events = db_rows("SELECT r.*, e.title, e.slug, e.start_datetime FROM event_registrations r JOIN events e ON e.id = r.event_id
                   WHERE r.user_id = ? ORDER BY e.start_datetime DESC LIMIT 20", [$uid]);

$pageTitle = 'My Applications';
require __DIR__ . '/header.php';
page_banner('My Applications', 'Track your volunteer, teacher, job, scholarship and help applications');
?>
<section class="section">
    <div class="container member-layout">
        <?php render_member_nav($user); ?>
        <div>
            <div class="panel">
                <div class="panel-head"><h2>Applications</h2><a class="btn btn-ghost btn-sm" href="<?= e(url('volunteer.php')) ?>"><?= icon('plus') ?>New application</a></div>
                <?php if ($apps): ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>Reference</th><th>Type</th><th>Details</th><th>Submitted</th><th>Status</th><th>Document</th></tr></thead>
                            <tbody>
                            <?php foreach ($apps as $a): ?>
                                <tr>
                                    <td class="nowrap"><strong><?= e($a['ref']) ?></strong></td>
                                    <td><?= e($a['label']) ?></td>
                                    <td><?= e(excerpt(humanize($a['detail']), 60)) ?></td>
                                    <td class="nowrap"><?= e(format_date($a['created_at'])) ?></td>
                                    <td><?= status_badge($a['status']) ?></td>
                                    <td><?= $a['doc'] ? '<a href="' . e(url('my-applications.php?download=' . $a['kind'] . '&id=' . (int) $a['id'])) . '">' . icon('download') . ' Download</a>' : '<span class="muted">—</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="small muted mt-2">Only applications submitted while logged in to this account are listed. For others, use <a href="<?= e(url('application-status.php')) ?>">Application Status</a> with your reference number.</p>
                <?php else: ?>
                    <?= empty_state('You have not submitted any applications from this account yet.', 'Explore ways to get involved', 'volunteer.php') ?>
                <?php endif; ?>
            </div>
            <?php if ($events): ?>
                <div class="panel">
                    <div class="panel-head"><h2>Event registrations</h2></div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>Event</th><th>Date</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($events as $ev): ?>
                                <tr>
                                    <td><a href="<?= e(url('event-details.php?slug=' . urlencode($ev['slug']))) ?>"><?= e($ev['title']) ?></a></td>
                                    <td><?= e(format_date($ev['start_datetime'], 'd M Y, h:i A')) ?></td>
                                    <td><?= status_badge($ev['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
