<?php
/**
 * Memberships: list / filter, review one application (?id=), approve (membership number, validity, Member role),
 * reject, suspend, internal notes, history, CSV export. Applicants are notified by email and in their account.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('members.view');

$types = membership_types();
$statuses = recruitment_kinds()['membership']['statuses'];
$canManage = admin_can('members.manage');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('memberships.php');
    require_permission('members.manage');
    $id = (int) ($_POST['id'] ?? 0);
    $self = 'memberships.php?id=' . $id;
    $m = db_row('SELECT * FROM members WHERE id = ?', [$id]);
    if (!$m) {
        flash('error', 'Membership not found.');
        admin_redirect('memberships.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    $note = mb_substr(clean_string($_POST['note'] ?? '', true), 0, 2000);
    $notify = !empty($_POST['notify']);
    $messages = [];

    if ($action === 'approve') {
        $no = strtoupper(mb_substr(clean_string($_POST['membership_no'] ?? ''), 0, 40));
        if ($no === '') {
            $no = $m['membership_no'] ?: 'PWF/MEM/' . date('Y') . '/' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
        }
        if (!preg_match('#^[A-Z0-9/\-]{3,40}$#', $no)) {
            flash('error', 'The membership number may contain only letters, numbers, "/" and "-".');
            admin_redirect($self);
        }
        if (db_value('SELECT COUNT(*) FROM members WHERE membership_no = ? AND id <> ?', [$no, $id])) {
            flash('error', 'Membership number ' . $no . ' is already used by another member.');
            admin_redirect($self);
        }
        $validUntil = (string) ($_POST['valid_until'] ?? '');
        if ($validUntil !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil) || $validUntil <= date('Y-m-d'))) {
            flash('error', 'Valid until must be a future date (leave it empty for no end date).');
            admin_redirect($self);
        }
        $messages = recruitment_support_change_status('membership', $m, 'approved', $note, true, $notify, [
            'membership_no' => $no, 'valid_until' => $validUntil !== '' ? $validUntil : null,
            'approved_by' => admin_id(), 'approved_at' => $m['approved_at'] && $m['status'] === 'approved' ? $m['approved_at'] : date('Y-m-d H:i:s'),
        ]);
        assign_role((int) $m['user_id'], 'member', admin_id());
        flash('success', 'Membership approved (' . $no . '). The Member role has been added to the account.');
    } elseif ($action === 'reject' || $action === 'suspend') {
        $status = $action === 'reject' ? 'rejected' : 'suspended';
        if ($action === 'suspend' && $m['status'] !== 'approved') {
            flash('error', 'Only approved memberships can be suspended.');
            admin_redirect($self);
        }
        $messages = recruitment_support_change_status('membership', $m, $status, $note, true, $notify);
        remove_role((int) $m['user_id'], 'member');
        flash('success', 'Membership ' . ($status === 'rejected' ? 'rejected' : 'suspended') . '. The Member role has been removed from the account.');
    } elseif ($action === 'notes') {
        db_update('members', ['admin_notes' => $note !== '' ? $note : null], 'id = :id', ['id' => $id]);
        admin_log('membership_notes', 'Membership #' . $id . ' notes updated');
        flash('success', 'Internal notes saved.');
    } else {
        flash('error', 'Unknown action.');
    }
    foreach ($messages as $msg) {
        flash('info', $msg);
    }
    admin_redirect($self);
}

// ---------------------------------------------------------------------
// One membership
// ---------------------------------------------------------------------
if (!empty($_GET['id'])) {
    $id = (int) $_GET['id'];
    $m = db_row('SELECT m.*, u.name, u.email, u.mobile, u.status AS user_status, a.name AS approver FROM members m JOIN users u ON u.id = m.user_id
                  LEFT JOIN users a ON a.id = m.approved_by WHERE m.id = ?', [$id]);
    if (!$m) {
        flash('error', 'Membership not found.');
        admin_redirect('memberships.php');
    }
    $age = $m['date_of_birth'] ? (int) date_diff(date_create($m['date_of_birth']), date_create('today'))->y : null;
    admin_page_start('Membership: ' . $m['name'], [['label' => 'All memberships', 'url' => admin_url('memberships.php'), 'icon' => 'list', 'style' => 'ghost']]);
    echo recruitment_admin_css();
    ?>
    <div class="admin-grid-main">
        <div>
            <section class="panel">
                <div class="panel-head"><h2><?= e($m['name']) ?></h2><?= recruitment_status_badge($m['status'], 'membership') ?></div>
                <?php details_list([
                    'Membership type' => $types[$m['membership_type']][0] ?? humanize($m['membership_type']),
                    'Membership no.' => $m['membership_no'] ?: 'Assigned on approval',
                    'Valid until' => $m['valid_until'] ? format_date($m['valid_until']) : ($m['status'] === 'approved' ? 'No end date' : ''),
                    'Approved' => $m['approved_at'] ? format_date($m['approved_at'], 'd M Y, h:i A') . ($m['approver'] ? ' by ' . $m['approver'] : '') : '',
                    'Email' => ['<a href="mailto:' . e($m['email']) . '">' . e($m['email']) . '</a>'],
                    'Mobile' => (string) $m['mobile'],
                    'Account status' => humanize($m['user_status']),
                    'Date of birth' => $m['date_of_birth'] ? format_date($m['date_of_birth']) . ($age !== null ? ' (' . $age . ' years)' : '') : '',
                    'Gender' => GENDERS[$m['gender']] ?? '',
                    'Occupation' => $m['occupation'],
                    'Address' => trim(implode(', ', array_filter([$m['address'], $m['city'], $m['state'], $m['pincode']]))),
                    'Areas of interest' => $m['areas_of_interest'],
                    'Motivation' => $m['motivation'],
                    'Applied' => format_date($m['created_at'], 'd M Y, h:i A'),
                ]); ?>
                <p class="mt-2"><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('user-view.php?id=' . (int) $m['user_id'])) ?>"><?= icon('user') ?>Open user account</a></p>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>History</h2></div>
                <?= recruitment_timeline_html(application_history('membership', $id), 'membership', true) ?>
            </section>
        </div>
        <div>
            <?php if ($canManage): ?>
                <section class="panel">
                    <div class="panel-head"><h2><?= $m['status'] === 'approved' ? 'Update approval' : 'Approve' ?></h2></div>
                    <form method="post" class="rc-stack">
                        <?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int) $id ?>">
                        <div class="form-group"><label for="membership_no">Membership number</label><input id="membership_no" name="membership_no" maxlength="40" value="<?= e((string) $m['membership_no']) ?>" placeholder="PWF/MEM/<?= date('Y') ?>/<?= str_pad((string) $id, 5, '0', STR_PAD_LEFT) ?>"><span class="help">Leave empty to generate it automatically.</span></div>
                        <div class="form-group"><label for="valid_until">Valid until</label><input id="valid_until" type="date" name="valid_until" min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>" value="<?= e((string) ($m['valid_until'] ?: ($m['membership_type'] === 'life' ? '' : date('Y-m-d', strtotime('+1 year'))))) ?>"><span class="help">Leave empty for no end date (e.g. life membership).</span></div>
                        <div class="form-group"><label for="anote">Message to the member</label><textarea id="anote" name="note" rows="2" maxlength="2000"></textarea></div>
                        <label class="checkbox small"><input type="checkbox" name="notify" value="1" checked><span>Email the member</span></label>
                        <button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?><?= $m['status'] === 'approved' ? 'Save' : 'Approve membership' ?></button>
                    </form>
                </section>
                <section class="panel">
                    <div class="panel-head"><h2>Reject or suspend</h2></div>
                    <form method="post" class="rc-stack" data-confirm="Change this membership? The Member role will be removed from the account.">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $id ?>">
                        <div class="form-group"><label for="rnote">Reason (shown to the member)</label><textarea id="rnote" name="note" rows="2" maxlength="2000"></textarea></div>
                        <label class="checkbox small"><input type="checkbox" name="notify" value="1" checked><span>Email the member</span></label>
                        <div class="btn-row">
                            <?php if ($m['status'] !== 'rejected'): ?><button class="btn btn-ghost btn-sm" type="submit" name="action" value="reject"><?= icon('x') ?>Reject</button><?php endif; ?>
                            <?php if ($m['status'] === 'approved'): ?><button class="btn btn-danger btn-sm" type="submit" name="action" value="suspend"><?= icon('lock') ?>Suspend</button><?php endif; ?>
                        </div>
                    </form>
                </section>
                <section class="panel">
                    <div class="panel-head"><h2>Internal notes</h2></div>
                    <form method="post" class="rc-stack">
                        <?= csrf_field() ?><input type="hidden" name="action" value="notes"><input type="hidden" name="id" value="<?= (int) $id ?>">
                        <div class="form-group"><label for="mnotes" class="sr-only">Internal notes</label><textarea id="mnotes" name="note" rows="3" maxlength="2000"><?= e((string) $m['admin_notes']) ?></textarea></div>
                        <button class="btn btn-ghost btn-sm" type="submit"><?= icon('check') ?>Save notes</button>
                    </form>
                </section>
                <?php if ($m['status'] === 'approved' && admin_can('certificates.manage')): ?>
                    <section class="panel"><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('certificates.php?' . http_build_query(['user_id' => $m['user_id'], 'type' => 'membership', 'name' => $m['name']]))) ?>#issue"><?= icon('award') ?>Issue membership certificate</a></section>
                <?php endif; ?>
            <?php else: ?>
                <section class="panel"><p class="muted small"><?= icon('lock') ?> Approving memberships requires the "Approve members" permission.</p><?php if ($m['admin_notes']): ?><div class="note-box"><?= e($m['admin_notes']) ?></div><?php endif; ?></section>
            <?php endif; ?>
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
$mtype = (string) ($_GET['mtype'] ?? '');
$params = [];
$where = search_where(['u.name', 'u.email', 'u.mobile', 'a.membership_no', 'a.city'], $q, $params);
if (isset($types[$mtype])) {
    $where .= ' AND a.membership_type = ?';
    $params[] = $mtype;
}
$counts = [];
foreach (db_rows("SELECT a.status, COUNT(*) n FROM members a JOIN users u ON u.id = a.user_id WHERE 1=1 $where GROUP BY a.status", $params) as $r) {
    $counts[$r['status']] = (int) $r['n'];
}
if (in_array($status, $statuses, true)) {
    $where .= ' AND a.status = ?';
    $params[] = $status;
} else {
    $status = '';
}
$select = "SELECT a.*, u.name, u.email, u.mobile FROM members a JOIN users u ON u.id = a.user_id WHERE 1=1 $where";

if (isset($_GET['export'])) {
    require_permission('exports.run');
    $out = [];
    foreach (db_rows($select . ' ORDER BY a.created_at DESC LIMIT 5000', $params) as $r) {
        $out[] = [$r['membership_no'], $r['name'], $r['email'], $r['mobile'], $types[$r['membership_type']][0] ?? $r['membership_type'], $r['city'], $r['state'],
            recruitment_status_info($r['status'], 'membership')[0], $r['created_at'], $r['approved_at'], $r['valid_until']];
    }
    admin_log('export', 'Memberships CSV (' . count($out) . ' rows)');
    send_csv('memberships-' . date('Y-m-d') . '.csv', ['Membership no.', 'Name', 'Email', 'Mobile', 'Type', 'City', 'State', 'Status', 'Applied', 'Approved', 'Valid until'], $out);
}

$p = paginate((int) db_value("SELECT COUNT(*) FROM members a JOIN users u ON u.id = a.user_id WHERE 1=1 $where", $params), 25);
$rows = db_rows($select . " ORDER BY FIELD(a.status, 'pending', 'approved', 'suspended', 'rejected'), a.created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);

$actions = admin_can('exports.run') ? [['label' => 'Export CSV', 'url' => '?' . http_build_query(array_merge($_GET, ['export' => 1])), 'icon' => 'download', 'style' => 'ghost']] : [];
admin_page_start('Memberships', $actions);
echo recruitment_admin_css();
$typeSelect = '<select name="mtype" aria-label="Membership type"><option value="">All types</option>';
foreach ($types as $k => [$label]) {
    $typeSelect .= '<option value="' . e($k) . '"' . ($mtype === $k ? ' selected' : '') . '>' . e($label) . '</option>';
}
$typeSelect .= '</select>';
admin_filter_bar([], ['placeholder' => 'Name, email, mobile, city or membership no.', 'html' => $typeSelect, 'active' => $mtype !== '', 'hidden' => $status !== '' ? ['status' => $status] : []]);
echo recruitment_chipbar($statuses, $counts, $status, array_filter(['q' => $q, 'mtype' => $mtype]), 'membership');
?>
<div class="panel">
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Member</th><th>Type</th><th>Membership no.</th><th>City</th><th>Status</th><th>Applied</th><th>Valid until</th><th><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><a href="?id=<?= (int) $r['id'] ?>"><strong><?= e($r['name']) ?></strong></a><br><small class="muted"><?= e($r['email']) ?></small></td>
                    <td><?= e($types[$r['membership_type']][0] ?? humanize($r['membership_type'])) ?></td>
                    <td class="nowrap"><?= $r['membership_no'] ? e($r['membership_no']) : '<span class="muted">-</span>' ?></td>
                    <td><?= e((string) $r['city']) ?></td>
                    <td><?= recruitment_status_badge($r['status'], 'membership') ?></td>
                    <td class="nowrap"><?= e(format_date($r['created_at'])) ?></td>
                    <td class="nowrap"><?= $r['valid_until'] ? e(format_date($r['valid_until'])) : '<span class="muted">-</span>' ?></td>
                    <td class="actions-cell"><a class="icon-btn" href="?id=<?= (int) $r['id'] ?>" aria-label="Review membership of <?= e($r['name']) ?>" title="Review"><?= icon('eye') ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state($q !== '' || $status !== '' || $mtype !== '' ? 'No memberships match these filters.' : 'No membership applications yet. Signed-in users apply on the Membership page.') ?>
    <?php endif; ?>
</div>
<?php
admin_page_end();
