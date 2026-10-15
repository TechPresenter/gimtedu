<?php
/**
 * Scholarship applications - sensitive data: phone numbers masked in the list, private documents downloaded
 * only through this page (logged). Status workflow with notes, history, applicant notification, CSV export.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('beneficiaries.manage');

$kind = 'scholarship';
$statuses = recruitment_kinds()[$kind]['statuses'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('scholarships.php');
    $id = (int) ($_POST['id'] ?? 0);
    $self = 'scholarships.php?id=' . $id;
    $row = db_row('SELECT * FROM scholarships WHERE id = ?', [$id]);
    if (!$row) {
        flash('error', 'Application not found.');
        admin_redirect('scholarships.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'status') {
        $status = (string) ($_POST['status'] ?? '');
        $note = mb_substr(clean_string($_POST['note'] ?? '', true), 0, 3000);
        if (!in_array($status, $statuses, true)) {
            flash('error', 'Please choose a valid status.');
            admin_redirect($self);
        }
        if ($status === $row['status'] && $note === '') {
            flash('info', 'The status has not changed. Add a note to record an update.');
            admin_redirect($self);
        }
        foreach (recruitment_support_change_status($kind, $row, $status, $note, !empty($_POST['visible']), !empty($_POST['notify'])) as $m) {
            flash('info', $m);
        }
        flash('success', 'Status updated to "' . recruitment_status_info($status, $kind)[0] . '".');
    } elseif ($action === 'notes') {
        $notes = mb_substr(clean_string($_POST['admin_notes'] ?? '', true), 0, 5000);
        db_update('scholarships', ['admin_notes' => $notes !== '' ? $notes : null], 'id = :id', ['id' => $id]);
        admin_log('scholarship_notes', 'Scholarship ' . $row['application_no'] . ' notes updated');
        flash('success', 'Internal notes saved.');
    }
    admin_redirect($self);
}

// ---------------------------------------------------------------------
// One application
// ---------------------------------------------------------------------
if (!empty($_GET['id'])) {
    $id = (int) $_GET['id'];
    if (isset($_GET['download'])) {
        send_application_document($kind, $id);
    }
    $row = db_row('SELECT s.*, r.name AS reviewer_name FROM scholarships s LEFT JOIN users r ON r.id = s.reviewed_by WHERE s.id = ?', [$id]);
    if (!$row) {
        flash('error', 'Application not found.');
        admin_redirect('scholarships.php');
    }
    admin_log('scholarship_viewed', 'Scholarship ' . $row['application_no']);
    $age = $row['date_of_birth'] ? (int) date_diff(date_create($row['date_of_birth']), date_create('today'))->y : null;
    admin_page_start('Scholarship ' . $row['application_no'], [['label' => 'All scholarship applications', 'url' => admin_url('scholarships.php'), 'icon' => 'list', 'style' => 'ghost']]);
    echo recruitment_admin_css();
    ?>
    <div class="admin-grid-main">
        <div>
            <section class="panel">
                <div class="panel-head"><h2><?= e($row['student_name']) ?></h2><?= recruitment_status_badge($row['status'], $kind) ?></div>
                <?php details_list([
                    'Reference' => $row['application_no'],
                    'Parent / guardian' => $row['guardian_name'],
                    'Email' => ['<a href="mailto:' . e($row['email']) . '">' . e($row['email']) . '</a>'],
                    'Mobile' => ['<a href="tel:' . e($row['phone']) . '">' . e($row['phone']) . '</a>'],
                    'Student date of birth' => $row['date_of_birth'] ? format_date($row['date_of_birth']) . ($age !== null ? ' (' . $age . ' years)' : '') : '',
                    'Gender' => GENDERS[$row['gender']] ?? '',
                    'Address' => trim(implode(', ', array_filter([$row['address'], $row['city'], $row['state'], $row['pincode']]))),
                ]); ?>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Education &amp; need</h2></div>
                <?php details_list([
                    'Institution' => $row['institution'],
                    'Class / course' => $row['course_class'],
                    'Academic year' => $row['academic_year'],
                    'Last exam result' => $row['last_exam_percentage'] !== null ? rtrim(rtrim((string) $row['last_exam_percentage'], '0'), '.') . '%' : 'Not given',
                    'Annual family income' => money($row['annual_family_income']),
                    'Reason' => $row['reason'],
                    'Document' => $row['document_path'] ? ['<a class="btn btn-ghost btn-sm" href="' . e(admin_url('scholarships.php?id=' . $id . '&download=1')) . '">' . icon('download') . 'Download document</a>'] : 'None uploaded',
                    'Submitted' => format_date($row['created_at'], 'd M Y, h:i A') . ($row['user_id'] ? ' (signed-in user #' . $row['user_id'] . ')' : ''),
                    'Last reviewed' => $row['reviewed_at'] ? format_date($row['reviewed_at'], 'd M Y, h:i A') . ($row['reviewer_name'] ? ' by ' . $row['reviewer_name'] : '') : '',
                ]); ?>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>History</h2></div>
                <?= recruitment_timeline_html(application_history($kind, $id), $kind, true) ?>
            </section>
        </div>
        <div>
            <section class="panel">
                <div class="panel-head"><h2>Update status</h2></div>
                <form method="post" class="rc-stack">
                    <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int) $id ?>">
                    <div class="form-group"><label for="status">Status</label>
                        <select id="status" name="status"><?php foreach ($statuses as $st): ?><option value="<?= e($st) ?>"<?= $st === $row['status'] ? ' selected' : '' ?>><?= e(recruitment_status_info($st, $kind)[0]) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label for="note">Note</label><textarea id="note" name="note" rows="3" maxlength="3000" placeholder="e.g. Documents verified with the school."></textarea></div>
                    <label class="checkbox small"><input type="checkbox" name="visible" value="1"><span>Show the note to the applicant on the status page</span></label>
                    <label class="checkbox small"><input type="checkbox" name="notify" value="1" checked><span>Email the applicant about this update</span></label>
                    <p class="help">Approve only after verification and when funds are available. Use <strong>Disbursed</strong> once support has actually been provided.</p>
                    <button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?>Save status</button>
                </form>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Internal notes</h2></div>
                <form method="post" class="rc-stack">
                    <?= csrf_field() ?><input type="hidden" name="action" value="notes"><input type="hidden" name="id" value="<?= (int) $id ?>">
                    <div class="form-group"><label for="admin_notes" class="sr-only">Internal notes</label><textarea id="admin_notes" name="admin_notes" rows="5" maxlength="5000"><?= e((string) $row['admin_notes']) ?></textarea></div>
                    <button class="btn btn-ghost btn-sm" type="submit"><?= icon('check') ?>Save notes</button>
                </form>
            </section>
            <div class="rc-muted-box"><?= icon('shield') ?> This application may concern a child. Share its details only with people working on it.</div>
        </div>
    </div>
    <?php
    admin_page_end();
    exit;
}

// ---------------------------------------------------------------------
// List
// ---------------------------------------------------------------------
$q = mb_substr(clean_string($_GET['q'] ?? ''), 0, 100);
$status = (string) ($_GET['status'] ?? '');
$range = admin_date_range('all');
$params = [$range['from'] . ' 00:00:00', $range['to'] . ' 23:59:59'];
$where = ' AND a.created_at BETWEEN ? AND ?' . search_where(['a.application_no', 'a.student_name', 'a.guardian_name', 'a.email', 'a.phone', 'a.institution', 'a.city'], $q, $params);
$counts = recruitment_status_counts('scholarships', $where, $params);
if (in_array($status, $statuses, true)) {
    $where .= ' AND a.status = ?';
    $params[] = $status;
} else {
    $status = '';
}

if (isset($_GET['export'])) {
    require_permission('exports.run');
    $out = [];
    foreach (db_rows("SELECT a.* FROM scholarships a WHERE 1=1 $where ORDER BY a.created_at DESC LIMIT 5000", $params) as $r) {
        $out[] = [$r['application_no'], $r['student_name'], $r['guardian_name'], $r['phone'], $r['email'], $r['institution'], $r['course_class'], $r['academic_year'],
            $r['last_exam_percentage'], $r['annual_family_income'], $r['city'], $r['state'], recruitment_status_info($r['status'], $kind)[0], $r['created_at'], $r['reviewed_at']];
    }
    admin_log('export', 'Scholarships CSV (' . count($out) . ' rows, sensitive)');
    send_csv('scholarships-' . date('Y-m-d') . '.csv', ['Reference', 'Student', 'Guardian', 'Phone', 'Email', 'Institution', 'Class / course', 'Academic year', 'Last exam %', 'Annual family income', 'City', 'State', 'Status', 'Received', 'Reviewed'], $out);
}

$p = paginate((int) db_value("SELECT COUNT(*) FROM scholarships a WHERE 1=1 $where", $params), 25);
$rows = db_rows("SELECT a.* FROM scholarships a WHERE 1=1 $where ORDER BY FIELD(a.status, 'pending', 'under_review', 'shortlisted', 'approved', 'disbursed', 'rejected'), a.created_at DESC
                 LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);

$actions = admin_can('exports.run') ? [['label' => 'Export CSV', 'url' => '?' . http_build_query(array_merge($_GET, ['export' => 1])), 'icon' => 'download', 'style' => 'ghost']] : [];
admin_page_start('Scholarship Applications', $actions);
echo recruitment_admin_css();
$hidden = array_filter(['status' => $status, 'range' => is_string($_GET['range'] ?? null) ? $_GET['range'] : '', 'from' => is_string($_GET['from'] ?? null) ? $_GET['from'] : '', 'to' => is_string($_GET['to'] ?? null) ? $_GET['to'] : '']);
admin_filter_bar([], ['placeholder' => 'Reference, student, guardian, phone, institution or city', 'hidden' => $hidden]);
echo admin_range_picker($range);
echo recruitment_chipbar($statuses, $counts, $status, array_diff_key($_GET, ['export' => 1]), $kind);
?>
<div class="panel">
    <div class="table-meta"><span><?= number_format($p['total']) ?> application<?= $p['total'] === 1 ? '' : 's' ?> · <?= e($range['label']) ?></span><span><?= icon('lock') ?> Phone numbers are masked in this list</span></div>
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Reference</th><th>Student</th><th>Class / course</th><th>Family income</th><th>Phone</th><th>Status</th><th>Received</th><th><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap"><a href="?id=<?= (int) $r['id'] ?>"><strong><?= e($r['application_no']) ?></strong></a><?= $r['document_path'] ? '<br><small class="muted">document</small>' : '' ?></td>
                    <td><?= e($r['student_name']) ?><br><small class="muted"><?= e($r['city']) ?></small></td>
                    <td><?= e($r['course_class']) ?><br><small class="muted"><?= e(excerpt($r['institution'], 40)) ?></small></td>
                    <td class="nowrap"><?= e(money($r['annual_family_income'])) ?></td>
                    <td class="nowrap"><?= e(rc_mask_phone($r['phone'])) ?></td>
                    <td><?= recruitment_status_badge($r['status'], $kind) ?></td>
                    <td class="nowrap"><?= e(format_date($r['created_at'])) ?></td>
                    <td class="actions-cell"><a class="icon-btn" href="?id=<?= (int) $r['id'] ?>" aria-label="Open <?= e($r['application_no']) ?>" title="Open"><?= icon('eye') ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state($q !== '' || $status !== '' ? 'No applications match these filters.' : 'No scholarship applications yet. They appear here when someone applies on the Scholarship page.') ?>
    <?php endif; ?>
</div>
<?php
admin_page_end();
