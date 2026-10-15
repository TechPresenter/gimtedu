<?php
/**
 * Recruitment applications: volunteer | teacher | staff | internship (tabs).
 * Status chips with counts, search, date range, coordinator filter, pagination, CSV export, bulk status change.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('recruitment.view');

$types = recruitment_types();
$type = (string) ($_GET['type'] ?? 'volunteer');
if (!isset($types[$type])) {
    $type = 'volunteer';
}
$t = $types[$type];
$table = $t['table'];
$statuses = recruitment_status_keys();
$self = 'applications.php?type=' . $type;
$canManage = admin_can('recruitment.manage');
$detailCol = ['volunteer' => 'interests', 'teacher' => 'subjects', 'staff' => 'position', 'internship' => 'internship_area'][$type];
$detailLabel = ['volunteer' => 'Interests', 'teacher' => 'Subjects', 'staff' => 'Position', 'internship' => 'Area'][$type];

// ---------------------------------------------------------------------
// Bulk status change
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only($self);
    require_permission('recruitment.manage');
    $back = admin_back($self);
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))))), 0, 200);
    $status = (string) ($_POST['bulk_status'] ?? '');
    $note = mb_substr(clean_string($_POST['bulk_note'] ?? '', true), 0, 2000);
    $notify = !empty($_POST['bulk_notify']);
    if (!$ids || !in_array($status, $statuses, true)) {
        flash('error', 'Select at least one application and choose the new status.');
        admin_redirect($back);
    }
    $done = 0;
    $mailFailed = 0;
    $messages = [];
    foreach ($ids as $id) {
        $row = db_row("SELECT * FROM `$table` WHERE id = ?", [$id]);
        if (!$row || $row['status'] === $status) {
            continue;
        }
        foreach (recruitment_change_status($type, $row, $status, $note, true, $notify) as $m) {
            if ($m !== 'The applicant was notified by email.') {
                $messages[] = $row['application_no'] . ': ' . $m;
                if (strpos($m, 'could not be sent') !== false) {
                    $mailFailed++;
                }
            }
        }
        $done++;
    }
    admin_log('applications_bulk_status', $t['title'] . ': ' . $done . ' application(s) set to ' . $status . ($mailFailed ? ' (' . $mailFailed . ' email(s) not sent)' : ''));
    flash($mailFailed ? 'warning' : 'success', $done . ' application' . ($done === 1 ? '' : 's') . ' updated to "' . recruitment_status_info($status)[0] . '"'
        . ($notify && $done ? ($mailFailed ? '; ' . $mailFailed . ' email' . ($mailFailed === 1 ? '' : 's') . ' could not be sent (check Settings > Email delivery).' : ' and applicants notified.') : '.'));
    foreach (array_slice($messages, 0, 5) as $m) {
        flash('info', $m);
    }
    admin_redirect($back);
}

// ---------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------
$q = mb_substr(clean_string($_GET['q'] ?? ''), 0, 100);
$status = (string) ($_GET['status'] ?? '');
if (!in_array($status, $statuses, true)) {
    $status = '';
}
$coord = (string) ($_GET['coordinator'] ?? '');
$range = admin_date_range('all');
$coordinators = recruitment_coordinators();

$params = [$range['from'] . ' 00:00:00', $range['to'] . ' 23:59:59'];
$where = ' AND a.created_at BETWEEN ? AND ?';
$where .= search_where(['a.application_no', 'a.name', 'a.email', 'a.phone', 'a.city', 'a.' . $detailCol], $q, $params);
if ($coord === 'none') {
    $where .= ' AND a.coordinator_id IS NULL';
} elseif (ctype_digit($coord)) {
    $where .= ' AND a.coordinator_id = ?';
    $params[] = (int) $coord;
}
$counts = recruitment_status_counts($table, $where, $params);
if ($status !== '') {
    $where .= ' AND a.status = ?';
    $params[] = $status;
}

$select = "SELECT a.*, c.name AS coordinator_name FROM `$table` a LEFT JOIN users c ON c.id = a.coordinator_id WHERE 1=1 $where";

// CSV export of the filtered list
if (isset($_GET['export'])) {
    require_permission('exports.run');
    $rows = db_rows($select . ' ORDER BY a.created_at DESC LIMIT 5000', $params);
    $extraCols = [
        'volunteer' => ['occupation' => 'Occupation', 'education' => 'Education', 'interests' => 'Interests', 'availability' => 'Availability', 'skills' => 'Skills'],
        'teacher' => ['qualification' => 'Qualification', 'subjects' => 'Subjects', 'classes_preferred' => 'Classes', 'languages' => 'Languages', 'teaching_experience' => 'Experience', 'teaching_mode' => 'Mode', 'availability' => 'Availability'],
        'staff' => ['position' => 'Position', 'qualification' => 'Qualification', 'experience_years' => 'Experience (years)', 'current_organization' => 'Current organisation', 'notice_period' => 'Notice period'],
        'internship' => ['institution' => 'Institution', 'course' => 'Course', 'year_of_study' => 'Year', 'internship_area' => 'Area', 'duration' => 'Duration', 'preferred_start' => 'Preferred start', 'mode' => 'Mode'],
    ][$type];
    $header = array_merge(['Reference', 'Name', 'Email', 'Phone', 'City', 'State'], array_values($extraCols), ['Status', 'Interview', 'Coordinator', 'Submitted', 'Updated']);
    $out = [];
    foreach ($rows as $r) {
        $line = [$r['application_no'], $r['name'], $r['email'], $r['phone'], $r['city'], $r['state']];
        foreach (array_keys($extraCols) as $col) {
            $line[] = $r[$col];
        }
        $out[] = array_merge($line, [recruitment_status_info($r['status'])[0], $r['interview_at'], $r['coordinator_name'], $r['created_at'], $r['updated_at']]);
    }
    admin_log('export', $t['plural'] . ' CSV (' . count($out) . ' rows)');
    send_csv($type . '-applications-' . date('Y-m-d') . '.csv', $header, $out);
}

$p = paginate((int) db_value("SELECT COUNT(*) FROM `$table` a WHERE 1=1 $where", $params), 25);
$rows = db_rows($select . " ORDER BY a.created_at DESC, a.id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);

// Overview numbers for this type (all time)
$all = recruitment_status_counts($table);
$inReview = ($all['under_review'] ?? 0) + ($all['info_requested'] ?? 0) + ($all['shortlisted'] ?? 0) + ($all['interview_scheduled'] ?? 0);

$actions = [];
if (admin_can('exports.run')) {
    $actions[] = ['label' => 'Export CSV', 'url' => '?' . http_build_query(array_merge($_GET, ['type' => $type, 'export' => 1])), 'icon' => 'download', 'style' => 'ghost'];
}
if ($canManage) {
    $actions[] = ['label' => 'Volunteer tasks', 'url' => admin_url('tasks.php'), 'icon' => 'check-circle', 'style' => 'ghost'];
}
admin_page_start('Applications: ' . $t['plural'], $actions);
echo recruitment_admin_css();
?>
<nav class="admin-tabs" aria-label="Application types">
    <?php foreach ($types as $key => $tt):
        $new = (int) db_value('SELECT COUNT(*) FROM `' . $tt['table'] . "` WHERE status = 'submitted'"); ?>
        <a href="<?= e(admin_url('applications.php?type=' . $key)) ?>" class="<?= $key === $type ? 'active' : '' ?>"<?= $key === $type ? ' aria-current="page"' : '' ?>><?= e($tt['plural']) ?><?= $new ? ' <span class="badge badge-gold">' . $new . ' new</span>' : '' ?></a>
    <?php endforeach; ?>
</nav>

<div class="stat-cards">
    <a class="stat-card" href="<?= e('?' . http_build_query(['type' => $type, 'status' => 'submitted'])) ?>"><span class="icon-circle gold"><?= icon('inbox') ?></span><div><strong><?= (int) ($all['submitted'] ?? 0) ?></strong><small>New (submitted)</small></div></a>
    <div class="stat-card"><span class="icon-circle blue"><?= icon('search') ?></span><div><strong><?= (int) $inReview ?></strong><small>In review / interview</small></div></div>
    <div class="stat-card"><span class="icon-circle"><?= icon('check-circle') ?></span><div><strong><?= (int) (($all['selected'] ?? 0) + ($all['onboarding'] ?? 0)) ?></strong><small>Selected / onboarding</small></div></div>
    <a class="stat-card" href="<?= e('?' . http_build_query(['type' => $type, 'status' => 'active'])) ?>"><span class="icon-circle"><?= icon($t['icon']) ?></span><div><strong><?= (int) ($all['active'] ?? 0) ?></strong><small>Active</small></div></a>
</div>

<?php
$coordSelect = '<select name="coordinator" aria-label="Coordinator"><option value="">All coordinators</option><option value="none"' . ($coord === 'none' ? ' selected' : '') . '>Not assigned</option>';
foreach ($coordinators as $c) {
    $coordSelect .= '<option value="' . (int) $c['id'] . '"' . ($coord === (string) $c['id'] ? ' selected' : '') . '>' . e($c['name']) . '</option>';
}
$coordSelect .= '</select>';
$hidden = ['type' => $type];
if ($status !== '') {
    $hidden['status'] = $status;
}
foreach (['range', 'from', 'to'] as $rk) {
    if (isset($_GET[$rk]) && is_string($_GET[$rk])) {
        $hidden[$rk] = $_GET[$rk];
    }
}
admin_filter_bar([], ['placeholder' => 'Name, email, phone, city, reference or ' . strtolower($detailLabel), 'hidden' => $hidden, 'html' => $coordSelect, 'active' => $coord !== '']);
echo admin_range_picker($range);
echo recruitment_chipbar($statuses, $counts, $status, array_diff_key($_GET, ['export' => 1]) + ['type' => $type], $type);
?>
<div class="panel">
    <div class="table-meta"><span><?= number_format($p['total']) ?> application<?= $p['total'] === 1 ? '' : 's' ?> · <?= e($range['label']) ?></span><?php if ($status !== ''): ?><span>Status: <?= recruitment_status_badge($status, $type) ?></span><?php endif; ?></div>
    <?php if ($rows): ?>
        <form method="post" id="bulk-form" data-confirm="Change the status of the selected applications?">
            <?= csrf_field() ?><?= return_field() ?>
            <div class="table-wrap"><table class="table">
                <thead><tr>
                    <?php if ($canManage): ?><th class="rc-table-check"><input type="checkbox" data-check-all=".rc-row-check" aria-label="Select all applications on this page"></th><?php endif; ?>
                    <th>Reference</th><th>Applicant</th><th><?= e($detailLabel) ?></th><th>City</th><th>Status</th><th>Coordinator</th><th>Submitted</th><th><span class="sr-only">Actions</span></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $view = admin_url('application-view.php?type=' . $type . '&id=' . (int) $r['id']); ?>
                    <tr>
                        <?php if ($canManage): ?><td><input type="checkbox" class="rc-row-check" name="ids[]" value="<?= (int) $r['id'] ?>" aria-label="Select <?= e($r['application_no']) ?>"></td><?php endif; ?>
                        <td class="nowrap"><a href="<?= e($view) ?>"><strong><?= e($r['application_no']) ?></strong></a><?= $r['resume_path'] ?? null ? '<br><small class="muted">' . icon('file-text') . ' resume</small>' : '' ?></td>
                        <td><?= e($r['name']) ?><br><small class="muted"><?= e($r['email']) ?></small><br><small class="muted"><?= e($r['phone']) ?></small></td>
                        <td><?= e(excerpt((string) $r[$detailCol], 60)) ?><?php if ($type === 'internship'): ?><br><small class="muted"><?= e($r['duration']) ?></small><?php endif; ?></td>
                        <td><?= e($r['city']) ?></td>
                        <td><?= recruitment_status_badge($r['status'], $type) ?><?php if ($r['status'] === 'interview_scheduled' && $r['interview_at']): ?><br><small class="muted"><?= e(format_date($r['interview_at'], 'd M, h:i A')) ?></small><?php endif; ?></td>
                        <td><?= $r['coordinator_name'] ? e($r['coordinator_name']) : '<span class="muted">-</span>' ?></td>
                        <td class="nowrap"><?= e(format_date($r['created_at'])) ?></td>
                        <td class="actions-cell"><a class="icon-btn" href="<?= e($view) ?>" aria-label="Review <?= e($r['application_no']) ?>" title="Review"><?= icon('eye') ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if ($canManage): ?>
                <div class="rc-bulk">
                    <strong class="small">With selected:</strong>
                    <select name="bulk_status" aria-label="New status" required>
                        <option value="">Change status to…</option>
                        <?php foreach ($statuses as $st): ?><option value="<?= e($st) ?>"><?= e(recruitment_status_info($st)[0]) ?></option><?php endforeach; ?>
                    </select>
                    <input type="text" name="bulk_note" maxlength="2000" placeholder="Note for the applicants (optional)" aria-label="Note for the applicants">
                    <label class="checkbox small"><input type="checkbox" name="bulk_notify" value="1"><span>Email applicants</span></label>
                    <button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?>Apply</button>
                </div>
            <?php endif; ?>
        </form>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state($q !== '' || $status !== '' || $coord !== '' ? 'No applications match these filters.' : 'No ' . strtolower($t['title']) . 's yet. They appear here when someone applies on the website.') ?>
    <?php endif; ?>
</div>
<p class="muted small"><?= icon('info') ?> Public form: <a href="<?= e(page_url($t['slug'])) ?>" target="_blank" rel="noopener"><?= e(page_url($t['slug'])) ?></a></p>
<?php
admin_page_end();
