<?php
/**
 * One recruitment application (?type=volunteer|teacher|staff|internship&id=N): all details, private resume download,
 * status workflow, information request, interview scheduling, coordinator, onboarding checklist, internal notes,
 * account/role linking on "Active", history timeline and a certificate shortcut.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('recruitment.view');

$type = (string) ($_GET['type'] ?? '');
$t = recruitment_type($type);
$id = (int) ($_GET['id'] ?? 0);
if (!$t) {
    flash('error', 'Unknown application type.');
    admin_redirect('applications.php');
}
$table = $t['table'];
$row = db_row("SELECT a.*, c.name AS coordinator_name, c.email AS coordinator_email, rv.name AS reviewer_name
                 FROM `$table` a LEFT JOIN users c ON c.id = a.coordinator_id LEFT JOIN users rv ON rv.id = a.reviewed_by WHERE a.id = ?", [$id]);
if (!$row) {
    flash('error', 'Application not found.');
    admin_redirect('applications.php?type=' . $type);
}
$self = 'application-view.php?type=' . $type . '&id=' . $id;
$canManage = admin_can('recruitment.manage');
$statuses = recruitment_status_keys();

// Private resume download (permission-checked, logged)
if (isset($_GET['download'])) {
    send_application_document($type, $id);
}

// ---------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only($self);
    require_permission('recruitment.manage');
    $action = (string) ($_POST['action'] ?? '');
    $messages = [];

    if ($action === 'status') {
        $status = (string) ($_POST['status'] ?? '');
        $note = mb_substr(clean_string($_POST['note'] ?? '', true), 0, 3000);
        if (!in_array($status, $statuses, true)) {
            flash('error', 'Please choose a valid status.');
            admin_redirect($self);
        }
        if ($status === $row['status'] && $note === '') {
            flash('info', 'The status is already "' . recruitment_status_info($status)[0] . '". Add a note to record an update.');
            admin_redirect($self);
        }
        $messages = recruitment_change_status($type, $row, $status, $note, !empty($_POST['visible']), !empty($_POST['notify']));
        flash('success', 'Status updated to "' . recruitment_status_info($status)[0] . '".');
    } elseif ($action === 'info_request') {
        $message = mb_substr(clean_string($_POST['message'] ?? '', true), 0, 3000);
        if (mb_strlen($message) < 10) {
            flash('error', 'Please write what information you need (at least 10 characters).');
            admin_redirect($self . '#info-request');
        }
        $messages = recruitment_change_status($type, $row, 'info_requested', $message, true, true, ['info_request' => $message]);
        flash('success', 'Information requested.');
    } elseif ($action === 'interview') {
        [$data, $errors] = validate($_POST, [
            'interview_at' => 'required|datetime',
            'interview_mode' => 'required|in:' . implode(',', recruitment_options('interview_modes')),
            'location' => 'max:255',
            'note' => 'text|max:1000',
        ], ['interview_at' => 'Interview date and time', 'interview_mode' => 'Interview mode', 'location' => 'Location / link']);
        if (!$errors && strtotime($data['interview_at']) <= time()) {
            $errors['interview_at'] = 'The interview must be in the future.';
        }
        if ($errors) {
            flash('error', reset($errors));
            admin_redirect($self . '#interview');
        }
        $when = date('l, d F Y \a\t h:i A', strtotime($data['interview_at']));
        $visibleNote = 'Interview: ' . $when . ' (' . $data['interview_mode'] . ')' . ($data['location'] !== '' ? "\nWhere / link: " . $data['location'] : '') . ($data['note'] !== '' ? "\n" . $data['note'] : '');
        $extraHtml = '<table role="presentation" cellpadding="6" style="border:1px solid #e0e9e3;border-radius:8px;margin:8px 0"><tr><td><strong>Date &amp; time</strong></td><td>' . e($when) . '</td></tr>'
            . '<tr><td><strong>Mode</strong></td><td>' . e($data['interview_mode']) . '</td></tr>'
            . ($data['location'] !== '' ? '<tr><td><strong>Where / link</strong></td><td>' . e($data['location']) . '</td></tr>' : '') . '</table>'
            . '<p>If this time does not suit you, please reply to this email so that we can find another slot.</p>';
        $messages = recruitment_change_status($type, $row, 'interview_scheduled', $visibleNote, true, !empty($_POST['notify']),
            ['interview_at' => $data['interview_at'], 'interview_mode' => mb_substr($data['interview_mode'], 0, 60)], $extraHtml);
        flash('success', 'Interview scheduled for ' . $when . '.');
    } elseif ($action === 'coordinator') {
        $cid = (int) ($_POST['coordinator_id'] ?? 0);
        $allowed = array_column(recruitment_coordinators(), null, 'id');
        if ($cid && !isset($allowed[$cid])) {
            flash('error', 'Please choose a volunteer coordinator or administrator.');
            admin_redirect($self);
        }
        db_update($table, ['coordinator_id' => $cid ?: null], 'id = :id', ['id' => $id]);
        record_application_history($type, $id, 'coordinator', $cid ? 'Assigned to ' . $allowed[$cid]['name'] . '.' : 'Coordinator removed.', false);
        if ($cid && $cid !== (int) admin_id()) {
            add_notification($cid, 'Application assigned to you', $t['title'] . ' ' . $row['application_no'] . ' (' . $row['name'] . ') has been assigned to you.', '/admin/' . $self, 'info');
        }
        admin_log('application_coordinator', $row['application_no'] . ' coordinator ' . ($cid ? '#' . $cid : 'removed'));
        flash('success', $cid ? 'Coordinator assigned: ' . $allowed[$cid]['name'] . '.' : 'Coordinator removed.');
    } elseif ($action === 'checklist') {
        $items = recruitment_onboarding_items($type);
        $done = array_map('intval', (array) ($_POST['done'] ?? []));
        $lines = [];
        foreach ($items as $i => $label) {
            $lines[] = (in_array($i, $done, true) ? '[x] ' : '[ ] ') . $label;
        }
        $note = mb_substr(clean_string($_POST['checklist_note'] ?? '', true), 0, 1000);
        record_application_history($type, $id, 'checklist', implode("\n", $lines) . ($note !== '' ? "\nNote: " . $note : ''), false);
        admin_log('application_checklist', $row['application_no'] . ' onboarding checklist ' . count($done) . '/' . count($items));
        flash('success', 'Onboarding checklist saved (' . count(array_intersect($done, array_keys($items))) . ' of ' . count($items) . ' done).');
    } elseif ($action === 'notes') {
        $notes = mb_substr(clean_string($_POST['admin_notes'] ?? '', true), 0, 5000);
        db_update($table, ['admin_notes' => $notes !== '' ? $notes : null], 'id = :id', ['id' => $id]);
        admin_log('application_notes', $row['application_no'] . ' internal notes updated');
        flash('success', 'Internal notes saved.');
    } elseif ($action === 'link_account') {
        if ($row['status'] !== 'active') {
            flash('error', 'Set the application to Active before linking the account role.');
        } else {
            $link = recruitment_link_account($type, $row, true);
            flash($link['linked'] ? 'success' : 'warning', $link['message']);
        }
        admin_redirect($self);
    } else {
        flash('error', 'Unknown action.');
    }
    foreach ($messages as $m) {
        flash('info', $m);
    }
    admin_redirect($self);
}

// ---------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------
$history = application_history($type, $id);
$account = null;
if (!empty($row['user_id'])) {
    $account = db_row('SELECT id, name, email, status FROM users WHERE id = ?', [(int) $row['user_id']]);
}
$unverifiedAccount = null;
if (!$account) {
    // an account with the same email is linked only once that address is verified (see recruitment_link_account)
    $byEmail = db_row('SELECT id, name, email, status, email_verified_at FROM users WHERE email = ?', [mb_strtolower((string) $row['email'])]);
    if ($byEmail && empty($byEmail['email_verified_at'])) {
        $unverifiedAccount = $byEmail;
    } else {
        $account = $byEmail;
    }
}
$accountRoles = $account ? array_column(db_rows('SELECT r.name, r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$account['id']]), 'name', 'slug') : [];
$roleName = (string) db_value('SELECT name FROM roles WHERE slug = ?', [$t['role']]);
$checklistState = [];
foreach (array_reverse($history) as $h) {
    if ($h['status'] === 'checklist') {
        foreach (explode("\n", (string) $h['note']) as $line) {
            if (strpos($line, '[x] ') === 0) {
                $checklistState[] = substr($line, 4);
            }
        }
        break;
    }
}
$modes = recruitment_options('modes');
$age = $row['date_of_birth'] ? (int) date_diff(date_create($row['date_of_birth']), date_create('today'))->y : null;
$address = trim(implode(', ', array_filter([$row['address'], $row['city'], $row['state'], $row['pincode']])));
$resumeLink = !empty($row['resume_path'])
    ? ['<a class="btn btn-ghost btn-sm" href="' . e(admin_url($self . '&download=1')) . '">' . icon('download') . 'Download resume (PDF)</a>']
    : ($t['folder'] ? 'Not uploaded' : '');

$personal = [
    'Reference' => $row['application_no'],
    'Email' => ['<a href="mailto:' . e($row['email']) . '">' . e($row['email']) . '</a>'],
    'Mobile' => ['<a href="tel:' . e($row['phone']) . '">' . e($row['phone']) . '</a>'],
    'Date of birth' => $row['date_of_birth'] ? [e(format_date($row['date_of_birth'])) . ($age !== null ? ' (' . $age . ' years)' : '')
        . ($age !== null && $age < 18 ? '<br><span class="badge badge-gold">Under 18</span> <small>Written parent / guardian consent is needed before any activity (Volunteer Policy).</small>' : '')] : '',
    'Gender' => GENDERS[$row['gender']] ?? '',
    'Address' => $address,
    'Website account' => $account
        ? [e($account['name']) . ' &lt;' . e($account['email']) . '&gt;' . ($accountRoles ? '<br><small class="muted">Roles: ' . e(implode(', ', $accountRoles)) . '</small>' : '')
            . (admin_can('members.view') ? '<br><a class="small" href="' . e(admin_url('user-view.php?id=' . (int) $account['id'])) . '">Open user</a>' : '')]
        : 'No website account uses this email',
    'Submitted' => format_date($row['created_at'], 'd M Y, h:i A') . ($row['ip_address'] ? ' (IP ' . $row['ip_address'] . ')' : ''),
    'Last reviewed' => $row['reviewed_at'] ? format_date($row['reviewed_at'], 'd M Y, h:i A') . ($row['reviewer_name'] ? ' by ' . $row['reviewer_name'] : '') : '',
];
switch ($type) {
    case 'volunteer':
        $details = ['Occupation' => $row['occupation'], 'Education' => $row['education'], 'Areas of interest' => $row['interests'], 'Availability' => $row['availability'],
            'Skills' => $row['skills'], 'Experience' => $row['experience'], 'Motivation' => $row['motivation']];
        break;
    case 'teacher':
        $details = ['Qualification' => $row['qualification'], 'Subjects' => $row['subjects'], 'Classes preferred' => $row['classes_preferred'], 'Languages' => $row['languages'],
            'Teaching experience' => $row['teaching_experience'], 'Mode' => $modes[$row['teaching_mode']] ?? $row['teaching_mode'], 'Availability' => $row['availability'],
            'Resume' => $resumeLink, 'Motivation' => $row['motivation']];
        break;
    case 'staff':
        $details = ['Position' => $row['position'], 'Qualification' => $row['qualification'], 'Experience' => rtrim(rtrim((string) $row['experience_years'], '0'), '.') . ' years',
            'Current organisation' => $row['current_organization'], 'Notice period' => $row['notice_period'], 'Resume' => $resumeLink, 'Cover letter' => $row['cover_letter']];
        break;
    default:
        $details = ['Institution' => $row['institution'], 'Course' => $row['course'], 'Year of study' => $row['year_of_study'], 'Internship area' => $row['internship_area'],
            'Duration' => $row['duration'], 'Preferred start' => $row['preferred_start'] ? format_date($row['preferred_start']) : '', 'Mode' => $modes[$row['mode']] ?? $row['mode'],
            'Skills' => $row['skills'], 'Resume' => $resumeLink, 'Motivation' => $row['motivation']];
}

admin_page_start($t['title'] . ' ' . $row['application_no'], [
    ['label' => 'All ' . strtolower($t['plural']), 'url' => admin_url('applications.php?type=' . $type), 'icon' => 'list', 'style' => 'ghost'],
]);
echo recruitment_admin_css();
?>
<div class="admin-grid-main">
    <div>
        <section class="panel">
            <div class="panel-head"><h2><?= e($row['name']) ?></h2><?= recruitment_status_badge($row['status'], $type) ?></div>
            <?php if ($row['status'] === 'interview_scheduled' && $row['interview_at']): ?>
                <div class="alert alert-info"><?= icon('calendar') ?><div>Interview: <strong><?= e(format_date($row['interview_at'], 'D, d M Y, h:i A')) ?></strong> · <?= e((string) $row['interview_mode']) ?></div></div>
            <?php elseif ($row['status'] === 'info_requested' && $row['info_request']): ?>
                <div class="alert alert-warning"><?= icon('message') ?><div><strong>Information requested:</strong> <?= e_nl($row['info_request']) ?></div></div>
            <?php endif; ?>
            <?php details_list($personal); ?>
        </section>

        <section class="panel">
            <div class="panel-head"><h2><?= e($t['label']) ?> details</h2></div>
            <?php details_list($details); ?>
        </section>

        <section class="panel" id="history">
            <div class="panel-head"><h2>History</h2><small class="muted">Green = visible to the applicant on the status page</small></div>
            <?= recruitment_timeline_html($history, $type, true) ?>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Internal notes</h2><small class="muted">Never shown to the applicant</small></div>
            <?php if ($canManage): ?>
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="notes">
                    <div class="form-group"><label for="admin_notes" class="sr-only">Internal notes</label><textarea id="admin_notes" name="admin_notes" rows="4" maxlength="5000"><?= e((string) $row['admin_notes']) ?></textarea></div>
                    <div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit"><?= icon('check') ?>Save notes</button></div>
                </form>
            <?php elseif ($row['admin_notes']): ?>
                <div class="note-box"><?= e($row['admin_notes']) ?></div>
            <?php else: ?>
                <p class="muted small">No notes.</p>
            <?php endif; ?>
        </section>
    </div>

    <div>
        <?php if ($canManage): ?>
            <section class="panel" id="update-status">
                <div class="panel-head"><h2>Update status</h2></div>
                <form method="post" class="rc-stack">
                    <?= csrf_field() ?><input type="hidden" name="action" value="status">
                    <div class="form-group"><label for="status">New status</label>
                        <select id="status" name="status" required>
                            <?php foreach ($statuses as $st): ?><option value="<?= e($st) ?>"<?= $st === $row['status'] ? ' selected' : '' ?>><?= e(recruitment_status_info($st)[0]) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="form-group"><label for="note">Note</label><textarea id="note" name="note" rows="3" maxlength="3000" placeholder="Optional. Shown to the applicant if visible is ticked."></textarea></div>
                    <label class="checkbox small"><input type="checkbox" name="visible" value="1" checked><span>Show the note to the applicant (status page &amp; dashboard)</span></label>
                    <label class="checkbox small"><input type="checkbox" name="notify" value="1" checked><span>Email the applicant about this update</span></label>
                    <p class="help">Setting <strong>Active</strong> adds the <?= e($roleName) ?> role to a website account with the same email address.</p>
                    <button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?>Save status</button>
                </form>
            </section>

            <section class="panel" id="info-request">
                <div class="panel-head"><h2>Request more information</h2></div>
                <form method="post" class="rc-stack">
                    <?= csrf_field() ?><input type="hidden" name="action" value="info_request">
                    <div class="form-group"><label for="message">What do you need from the applicant?</label><textarea id="message" name="message" rows="3" required minlength="10" maxlength="3000"><?= e((string) ($row['status'] === 'info_requested' ? $row['info_request'] : '')) ?></textarea></div>
                    <p class="help">Sets the status to Information Requested, emails the message and shows it on the status page.</p>
                    <button class="btn btn-ghost btn-sm" type="submit"><?= icon('send') ?>Send request</button>
                </form>
            </section>

            <section class="panel" id="interview">
                <div class="panel-head"><h2>Schedule interview</h2></div>
                <form method="post" class="rc-stack">
                    <?= csrf_field() ?><input type="hidden" name="action" value="interview">
                    <div class="form-group"><label for="interview_at">Date &amp; time</label><input id="interview_at" type="datetime-local" name="interview_at" required min="<?= e(date('Y-m-d\TH:i')) ?>" value="<?= e($row['interview_at'] && strtotime($row['interview_at']) > time() ? date('Y-m-d\TH:i', strtotime($row['interview_at'])) : '') ?>"></div>
                    <div class="form-group"><label for="interview_mode">Mode</label><select id="interview_mode" name="interview_mode" required><?= options_html(recruitment_options('interview_modes'), (string) $row['interview_mode'], false, '') ?></select></div>
                    <div class="form-group"><label for="location">Location / meeting link / phone</label><input id="location" type="text" name="location" maxlength="255"></div>
                    <div class="form-group"><label for="inote">Note for the applicant</label><textarea id="inote" name="note" rows="2" maxlength="1000" placeholder="e.g. Please bring a photo ID."></textarea></div>
                    <label class="checkbox small"><input type="checkbox" name="notify" value="1" checked><span>Email the interview details</span></label>
                    <button class="btn btn-ghost btn-sm" type="submit"><?= icon('calendar') ?>Schedule</button>
                </form>
            </section>

            <section class="panel">
                <div class="panel-head"><h2>Coordinator</h2></div>
                <form method="post" class="rc-stack">
                    <?= csrf_field() ?><input type="hidden" name="action" value="coordinator">
                    <div class="form-group"><label for="coordinator_id">Assigned coordinator</label>
                        <select id="coordinator_id" name="coordinator_id"><option value="0">Not assigned</option>
                            <?php foreach (recruitment_coordinators() as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $row['coordinator_id'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                        </select></div>
                    <button class="btn btn-ghost btn-sm" type="submit"><?= icon('user') ?>Save coordinator</button>
                </form>
            </section>

            <section class="panel" id="onboarding">
                <div class="panel-head"><h2>Onboarding checklist</h2></div>
                <form method="post" class="rc-stack">
                    <?= csrf_field() ?><input type="hidden" name="action" value="checklist">
                    <ul class="rc-check-list">
                        <?php foreach (recruitment_onboarding_items($type) as $i => $label): ?>
                            <li><label class="checkbox small"><input type="checkbox" name="done[]" value="<?= $i ?>"<?= in_array($label, $checklistState, true) ? ' checked' : '' ?>><span><?= e($label) ?></span></label></li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="form-group"><label for="checklist_note">Onboarding note</label><textarea id="checklist_note" name="checklist_note" rows="2" maxlength="1000"></textarea></div>
                    <button class="btn btn-ghost btn-sm" type="submit"><?= icon('check-circle') ?>Save checklist</button>
                </form>
            </section>
        <?php else: ?>
            <section class="panel"><p class="muted small"><?= icon('lock') ?> You can view this application. Reviewing requires the "Review applications" permission.</p></section>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-head"><h2>Account &amp; certificate</h2></div>
            <?php if ($account): ?>
                <p class="small">Account: <strong><?= e($account['email']) ?></strong><br><?= isset($accountRoles[$t['role']]) ? status_badge('active') . ' ' . e($roleName) . ' role' : '<span class="muted">No ' . e($roleName) . ' role yet</span>' ?></p>
                <?php if ($canManage && $row['status'] === 'active' && !isset($accountRoles[$t['role']])): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="link_account"><button class="btn btn-ghost btn-sm" type="submit"><?= icon('link') ?>Link account &amp; add role</button></form>
                <?php endif; ?>
                <?php if (admin_can('certificates.manage')): ?>
                    <a class="btn btn-primary btn-sm mt-2" href="<?= e(admin_url('certificates.php?' . http_build_query(['user_id' => $account['id'], 'type' => $t['certificate'], 'name' => $row['name'], 'app' => $type . ':' . $id]))) ?>#issue"><?= icon('award') ?>Issue certificate</a>
                <?php endif; ?>
                <?php if ($canManage && isset($accountRoles[$t['role']])): ?>
                    <a class="btn btn-ghost btn-sm mt-2" href="<?= e(admin_url('tasks.php?user_id=' . (int) $account['id'])) ?>#new-task"><?= icon('check-circle') ?>Assign a task</a>
                <?php endif; ?>
            <?php elseif ($unverifiedAccount): ?>
                <p class="muted small">A website account uses <?= e($row['email']) ?> but its email address is not verified, so it will not be linked. Once the owner verifies the address, the account can be linked and certificates or tasks shared.</p>
            <?php else: ?>
                <p class="muted small">No website account uses <?= e($row['email']) ?>. Certificates and tasks need an account - the applicant can register with this email address.</p>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php
admin_page_end();
