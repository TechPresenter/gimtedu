<?php
/**
 * Registrations of one event (?event=<id>): list, mark attended / cancelled, CSV export,
 * email all registrants (logged in the audit log).
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('events.manage');

$eventId = (int) ($_GET['event'] ?? $_POST['event'] ?? 0);
$event = $eventId ? db_row('SELECT * FROM events WHERE id = ?', [$eventId]) : null;
if (!$event) {
    flash('error', 'Choose an event to see its registrations.');
    admin_redirect('events.php');
}
$self = 'event-registrations.php?event=' . $eventId;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only($self);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'status') {
        $regId = (int) ($_POST['id'] ?? 0);
        $new = (string) ($_POST['status'] ?? '');
        $reg = db_row('SELECT * FROM event_registrations WHERE id = ? AND event_id = ?', [$regId, $eventId]);
        if ($reg && in_array($new, ['registered', 'attended', 'cancelled'], true) && $reg['status'] !== $new) {
            if ($new !== 'cancelled' && $reg['status'] === 'cancelled' && $event['capacity']
                && event_registered_count($eventId) >= (int) $event['capacity']) {
                flash('warning', 'The event is full. Registration #' . $regId . ' was restored anyway - check the capacity.');
            }
            db_update('event_registrations', ['status' => $new], 'id = :id', ['id' => $regId]);
            admin_log('event_registration_updated', 'Event #' . $eventId . ' registration #' . $regId . ': ' . $reg['status'] . ' -> ' . $new);
            flash('success', $reg['name'] . ' marked as ' . $new . '.');
        }
        admin_redirect(admin_back($self));
    }

    if ($action === 'email') {
        [$data, $errors] = validate($_POST, ['subject' => 'required|min:3|max:150', 'message' => 'required|text|min:10|max:5000']);
        if ($errors) {
            back_with_errors($errors, 'admin/' . $self . '#email', $_POST);
        }
        $statuses = !empty($_POST['include_cancelled']) ? ['registered', 'attended', 'cancelled'] : ['registered', 'attended'];
        $recipients = db_rows('SELECT name, email FROM event_registrations WHERE event_id = ? AND status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ') ORDER BY id',
            array_merge([$eventId], $statuses));
        $sent = 0;
        foreach ($recipients as $r) {
            $body = '<p>Dear ' . e($r['name']) . ',</p><p>' . e_nl($data['message']) . '</p>'
                . '<p style="border-top:1px solid #e0e9e3;padding-top:12px;color:#5e6e66;font-size:13px">You are receiving this because you registered for <strong>' . e($event['title']) . '</strong> ('
                . e(event_when($event)) . ', ' . e($event['location']) . ').<br><a href="' . e(abs_url('event-details/' . $event['slug'] . '/')) . '">View the event</a></p>';
            if (send_mail($r['email'], $data['subject'], $body, setting('contact_email') ?: null)) {
                $sent++;
            }
        }
        admin_log('event_registrants_emailed', 'Event #' . $eventId . ' "' . $event['title'] . '": "' . $data['subject'] . '" to ' . count($recipients) . ' registrants (' . $sent . ' accepted by the mail server)');
        if (!$recipients) {
            flash('info', 'There are no registrants to email.');
        } elseif ($sent === count($recipients)) {
            flash('success', 'Email sent to ' . $sent . ' registrant' . ($sent === 1 ? '' : 's') . '.');
        } else {
            flash('warning', 'Email accepted for ' . $sent . ' of ' . count($recipients) . ' registrants. Check the mail settings if this is unexpected.');
        }
        admin_redirect($self);
    }
    admin_redirect($self);
}

$status = in_array($_GET['status'] ?? '', ['registered', 'attended', 'cancelled'], true) ? (string) $_GET['status'] : '';
$q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 100));
$where = 'WHERE r.event_id = ?';
$params = [$eventId];
if ($status !== '') {
    $where .= ' AND r.status = ?';
    $params[] = $status;
}
$where .= search_where(['r.name', 'r.email', 'r.phone'], $q, $params);

if (isset($_GET['export'])) {
    require_permission('exports.run');
    admin_log('event_registrations_exported', 'Event #' . $eventId . ' "' . $event['title'] . '" registrations exported');
    $rows = db_rows("SELECT r.id, r.name, r.email, r.phone, r.message, r.status, IF(r.user_id IS NULL, 'No', 'Yes'), r.created_at FROM event_registrations r $where ORDER BY r.id", $params);
    send_csv('registrations-' . $event['slug'] . '.csv', ['ID', 'Name', 'Email', 'Mobile', 'Message', 'Status', 'Has account', 'Registered on'], $rows);
}

$p = paginate((int) db_value("SELECT COUNT(*) FROM event_registrations r $where", $params), 50);
$rows = db_rows("SELECT r.* FROM event_registrations r $where ORDER BY r.id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$counts = ['registered' => 0, 'attended' => 0, 'cancelled' => 0];
foreach (db_rows('SELECT status, COUNT(*) AS n FROM event_registrations WHERE event_id = ? GROUP BY status', [$eventId]) as $c) {
    $counts[$c['status']] = (int) $c['n'];
}
$taken = $counts['registered'] + $counts['attended'];

admin_page_start('Registrations', [
    ['label' => 'Edit event', 'url' => admin_url('event-edit.php?id=' . $eventId), 'icon' => 'edit', 'style' => 'ghost'],
    ['label' => 'All events', 'url' => admin_url('events.php'), 'icon' => 'list', 'style' => 'ghost'],
]);
?>
<section class="panel">
    <div class="panel-head">
        <div><h2><?= e($event['title']) ?></h2><p class="muted small mb-0"><?= e(event_when($event, true)) ?> · <?= e($event['location']) ?></p></div>
        <div><?= status_badge($event['status']) ?><?= event_is_past($event) ? ' <span class="badge badge-gray">Past</span>' : '' ?> <a class="btn btn-ghost btn-sm" href="<?= e(event_url($event['slug'])) ?>" target="_blank" rel="noopener"><?= icon('eye') ?>View page</a></div>
    </div>
    <div class="stat-cards mb-0">
        <a class="stat-card" href="<?= e(admin_url($self . '&status=registered')) ?>"><span class="icon-circle"><?= icon('users') ?></span><span><strong><?= $counts['registered'] ?></strong><small>Registered</small></span></a>
        <a class="stat-card" href="<?= e(admin_url($self . '&status=attended')) ?>"><span class="icon-circle blue"><?= icon('check-circle') ?></span><span><strong><?= $counts['attended'] ?></strong><small>Attended</small></span></a>
        <a class="stat-card" href="<?= e(admin_url($self . '&status=cancelled')) ?>"><span class="icon-circle gold"><?= icon('x') ?></span><span><strong><?= $counts['cancelled'] ?></strong><small>Cancelled</small></span></a>
        <div class="stat-card"><span class="icon-circle"><?= icon('target') ?></span><span><strong><?= $event['capacity'] ? max(0, (int) $event['capacity'] - $taken) . ' / ' . (int) $event['capacity'] : '-' ?></strong><small><?= $event['capacity'] ? 'Places left' : 'No capacity limit' ?><?= (int) $event['registration_enabled'] ? '' : ' · registration off' ?></small></span></div>
    </div>
</section>

<form class="filter-row" method="get">
    <input type="hidden" name="event" value="<?= (int) $eventId ?>">
    <label class="sr-only" for="f-q">Search</label>
    <input id="f-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, email or mobile">
    <label class="sr-only" for="f-status">Status</label>
    <select id="f-status" name="status"><option value="">All statuses</option><?php foreach (['registered', 'attended', 'cancelled'] as $s) { echo '<option value="' . $s . '"' . ($status === $s ? ' selected' : '') . '>' . e(humanize($s)) . '</option>'; } ?></select>
    <button class="btn btn-primary btn-sm" type="submit"><?= icon('search') ?>Filter</button>
    <?php if ($q !== '' || $status !== ''): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url($self)) ?>">Reset</a><?php endif; ?>
    <?php if (admin_can('exports.run') && $p['total']): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url($self . '&' . http_build_query(array_filter(['status' => $status, 'q' => $q, 'export' => 1])))) ?>"><?= icon('download') ?>Export CSV</a><?php endif; ?>
</form>

<section class="panel">
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Name</th><th>Contact</th><th>Message</th><th>Registered</th><th>Status</th><th class="text-right">Mark as</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['name']) ?></strong><?= $r['user_id'] ? '<br><small class="muted">Has an account</small>' : '' ?></td>
                    <td><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a><br><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $r['phone'])) ?>"><?= e($r['phone']) ?></a></td>
                    <td style="max-width:280px"><?= $r['message'] ? e_nl($r['message']) : '<span class="muted">-</span>' ?></td>
                    <td class="nowrap"><?= e(format_date($r['created_at'], 'd M Y, h:i A')) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="actions-cell">
                        <?php foreach (['attended' => ['check-circle', 'Attended'], 'cancelled' => ['x', 'Cancelled'], 'registered' => ['refresh', 'Registered']] as $to => [$ic, $label]): if ($r['status'] === $to) { continue; } ?>
                            <form class="inline-form" method="post"<?= $to === 'cancelled' ? ' data-confirm="Cancel this registration?"' : '' ?>>
                                <?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="event" value="<?= (int) $eventId ?>">
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="status" value="<?= $to ?>">
                                <button class="btn btn-ghost btn-sm" type="submit" aria-label="Mark <?= e($r['name']) ?> as <?= e(strtolower($label)) ?>"><?= icon($ic) ?><?= e($label) ?></button>
                            </form>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <p class="muted mb-0"><?= $q !== '' || $status !== '' ? 'No registrations match these filters.' : 'No registrations yet.' ?></p>
    <?php endif; ?>
</section>

<section class="panel" id="email">
    <div class="panel-head"><h2>Email all registrants</h2><span class="muted small"><?= $taken ?> recipient<?= $taken === 1 ? '' : 's' ?> (registered + attended)</span></div>
    <form method="post" action="<?= e(admin_url($self)) ?>#email" data-validate data-confirm="Send this email to all registrants of this event?">
        <?= csrf_field() ?><input type="hidden" name="action" value="email"><input type="hidden" name="event" value="<?= (int) $eventId ?>">
        <div class="form-grid">
            <div class="form-group full"><label for="subject">Subject<span class="req">*</span></label>
                <input type="text" id="subject" name="subject" required minlength="3" maxlength="150" value="<?= e(old('subject', 'Update: ' . $event['title'])) ?>"><?= field_error('subject') ?></div>
            <div class="form-group full"><label for="message">Message<span class="req">*</span></label>
                <textarea id="message" name="message" required minlength="10" maxlength="5000" rows="7" data-counter><?= e(old('message')) ?></textarea>
                <span class="help">Plain text. Each email starts with "Dear &lt;name&gt;" and ends with the event's date, place and link.</span><?= field_error('message') ?></div>
            <div class="form-group full"><label class="checkbox"><input type="checkbox" name="include_cancelled" value="1"><span>Also send to people whose registration was cancelled</span></label></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit"<?= $taken || $counts['cancelled'] ? '' : ' disabled' ?>><?= icon('send') ?>Send email</button></div>
    </form>
</section>
<?php
admin_page_end();
