<?php
/**
 * Event registrations (participant data - permission events.registrations).
 *   event-registrations.php                 all events: filters (event, category, upcoming/past, status, under 18, dates)
 *   event-registrations.php?event=<id>      one event: capacity, waiting list, joining links, add a registration,
 *                                           email participants, participation certificates
 *   ...&view=<registration id>              participant detail: status, note, resend email, delete (privacy request)
 * Bulk actions: confirm, attended, no-show, move from the waiting list, cancel (+ offer places to the waiting list),
 * email the online joining link. CSV export needs exports.run. Every change is written to the audit log.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('events.registrations');

$eventId = (int) ($_GET['event'] ?? $_POST['event'] ?? 0);
$event = $eventId ? db_row('SELECT * FROM events WHERE id = ?', [$eventId]) : null;
if ($eventId && !$event) {
    flash('error', 'Event not found.');
    admin_redirect('event-registrations.php');
}
$viewId = (int) ($_GET['view'] ?? 0);
$self = 'event-registrations.php' . ($event ? '?event=' . $eventId : '');
$sep = $event ? '&' : '?';
const PH_BULK_ACTIONS = [
    'confirmed' => 'Confirm', 'attended' => 'Mark as attended', 'no_show' => 'Mark as no-show',
    'registered' => 'Move from the waiting list (registered)', 'cancelled' => 'Cancel registration', 'meeting_link' => 'Email the online joining link',
];

/** Registrations by id, limited to this event when one is open. */
$loadRegs = function (array $ids) use ($event): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $sql = 'SELECT * FROM event_registrations WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' . ($event ? ' AND event_id = ?' : '') . ' ORDER BY id';
    return db_rows($sql, $event ? array_merge($ids, [(int) $event['id']]) : $ids);
};
$evLabel = function (array $ev): string {
    return 'Event #' . (int) $ev['id'] . ' "' . $ev['title'] . '"';
};

// =====================================================================
// POST
// =====================================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only($self);
    $action = programhub_admin_param($_POST, 'action');
    $back = admin_back($self);

    // ----- Bulk actions -------------------------------------------------
    if ($action === 'bulk') {
        $bulk = programhub_admin_param($_POST, 'bulk_action');
        $regs = $loadRegs((array) ($_POST['ids'] ?? []));
        if (!isset(PH_BULK_ACTIONS[$bulk]) || !$regs) {
            flash('error', !$regs ? 'Select at least one registration first.' : 'Choose an action.');
            admin_redirect($back);
        }
        $idList = '#' . implode(', #', array_slice(array_column($regs, 'id'), 0, 40)) . (count($regs) > 40 ? ' …' : '');
        if ($bulk === 'meeting_link') {
            $byEvent = [];
            foreach ($regs as $r) {
                $byEvent[(int) $r['event_id']][] = $r;
            }
            $sent = 0;
            $failed = 0;
            $skipped = 0;
            $reasons = [];
            foreach ($byEvent as $eid => $list) {
                $ev = db_row('SELECT * FROM events WHERE id = ?', [$eid]);
                $res = programhub_admin_send_meeting_links($ev, $list);
                $sent += $res['sent'];
                $failed += $res['failed'];
                $skipped += $res['skipped'] + ($res['reason'] ? count($list) : 0);
                if ($res['reason']) {
                    $reasons[] = $ev['title'] . ': ' . $res['reason'];
                }
                if ($res['sent'] || $res['failed']) {
                    admin_log('event_meeting_link_sent', $evLabel($ev) . ': joining link emailed to ' . $res['sent'] . ' participant(s)' . ($res['failed'] ? ', ' . $res['failed'] . ' not accepted by the mail server' : ''));
                }
            }
            $msg = ($sent ? 'Joining link emailed to ' . $sent . ' participant' . ($sent === 1 ? '' : 's') . '.' : 'No joining link was sent.')
                . ($failed ? ' The mail server did not accept ' . $failed . ' email' . ($failed === 1 ? '' : 's') . ' - check the mail settings and try again.' : '')
                . ($skipped ? ' ' . $skipped . ' skipped (waiting list, cancelled or no-show, or no meeting link).' : '')
                . ($reasons ? ' ' . implode(' ', array_slice($reasons, 0, 2)) : '');
            flash($sent && !$failed ? 'success' : ($sent ? 'warning' : 'error'), $msg);
            admin_redirect($back);
        }
        $res = programhub_admin_bulk_status($regs, $bulk, ['notify' => !empty($_POST['notify']), 'promote' => !empty($_POST['promote'])]);
        admin_log('event_registrations_bulk', ($event ? $evLabel($event) : 'Several events') . ': ' . $res['changed'] . ' registration(s) ' . PROGRAMHUB_ADMIN_BULK_STATUS[$bulk]
            . ' (' . $idList . ')' . ($res['emailed'] ? ', ' . $res['emailed'] . ' email(s)' : '') . ($res['promoted'] ? ', ' . count($res['promoted']) . ' moved from the waiting list' : ''));
        $msg = $res['changed'] . ' registration' . ($res['changed'] === 1 ? '' : 's') . ' ' . PROGRAMHUB_ADMIN_BULK_STATUS[$bulk] . '.';
        if ($res['skipped']) {
            $msg .= ' ' . $res['skipped'] . ' already had this status.';
        }
        if ($res['promoted']) {
            $msg .= ' Moved from the waiting list: ' . implode(', ', array_slice($res['promoted'], 0, 10)) . '.';
        }
        if ($res['emailed']) {
            $msg .= ' ' . $res['emailed'] . ' email' . ($res['emailed'] === 1 ? '' : 's') . ' sent.';
        }
        flash($res['over'] > 0 ? 'warning' : 'success', $msg . ($res['over'] > 0 ? ' Note: the event now has ' . $res['over'] . ' more registration' . ($res['over'] === 1 ? '' : 's') . ' than places.' : ''));
        admin_redirect($back);
    }

    // ----- One event only -----------------------------------------------
    if (!$event) {
        admin_redirect($back);
    }
    if ($action === 'send_links') {
        $audience = ($_POST['audience'] ?? '') === 'all' ? ['registered', 'confirmed', 'attended'] : ['confirmed'];
        $onlyUnsent = !empty($_POST['only_unsent']);
        $regs = db_rows('SELECT * FROM event_registrations WHERE event_id = ? AND status IN (' . implode(',', array_fill(0, count($audience), '?')) . ')'
            . ($onlyUnsent ? ' AND meeting_link_sent_at IS NULL' : '') . ' ORDER BY id', array_merge([$eventId], $audience));
        if (!$regs) {
            flash('info', 'Nobody to send to: ' . ($onlyUnsent ? 'everyone in this group has already received the joining link.' : 'no participant has this status.'));
            admin_redirect($self . '#joining');
        }
        $res = programhub_admin_send_meeting_links($event, $regs);
        if ($res['reason']) {
            flash('error', $res['reason']);
        } else {
            admin_log('event_meeting_link_sent', $evLabel($event) . ': joining link emailed to ' . $res['sent'] . ' ' . implode('/', $audience) . ' participant(s)' . ($onlyUnsent ? ' (not sent before)' : '')
                . ($res['failed'] ? ', ' . $res['failed'] . ' not accepted by the mail server' : ''));
            flash($res['failed'] ? ($res['sent'] ? 'warning' : 'error') : 'success', 'Joining link emailed to ' . $res['sent'] . ' participant' . ($res['sent'] === 1 ? '' : 's') . '.'
                . ($res['failed'] ? ' The mail server did not accept ' . $res['failed'] . ' email' . ($res['failed'] === 1 ? '' : 's') . ' - they stay marked as "not sent"; check the mail settings and try again.' : ''));
        }
        admin_redirect($self . '#joining');
    }
    if ($action === 'email') {
        [$data, $errors] = validate($_POST, ['subject' => 'required|min:3|max:150', 'message' => 'required|text|min:10|max:5000']);
        $statuses = array_values(array_intersect(array_filter((array) ($_POST['to'] ?? []), 'is_string'), array_keys(EVENT_REG_STATUSES)));
        if (!$statuses) {
            $errors['to'] = 'Choose who should receive the email.';
        }
        if ($errors) {
            back_with_errors($errors, 'admin/' . $self . '#email', $_POST);
        }
        $recipients = db_rows('SELECT name, email FROM event_registrations WHERE event_id = ? AND status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ') ORDER BY id',
            array_merge([$eventId], $statuses));
        $sent = 0;
        foreach ($recipients as $r) {
            $body = '<p>Dear ' . e($r['name']) . ',</p><p>' . e_nl($data['message']) . '</p>'
                . '<p style="border-top:1px solid #e0e9e3;padding-top:12px;color:#5e6e66;font-size:13px">You are receiving this because you registered for <strong>' . e($event['title']) . '</strong> ('
                . e(event_when($event)) . ').<br><a href="' . e(event_abs_url($event)) . '">View the event</a></p>';
            if (send_mail($r['email'], $data['subject'], $body, trim((string) $event['contact_email']) ?: (setting('contact_email') ?: null))) {
                $sent++;
            }
        }
        admin_log('event_registrants_emailed', $evLabel($event) . ': "' . $data['subject'] . '" to ' . count($recipients) . ' participant(s) with status ' . implode('/', $statuses) . ' (' . $sent . ' accepted by the mail server)');
        if (!$recipients) {
            flash('info', 'Nobody has the chosen status, so no email was sent.');
        } elseif ($sent === count($recipients)) {
            flash('success', 'Email sent to ' . $sent . ' participant' . ($sent === 1 ? '' : 's') . '.');
        } else {
            flash('warning', 'Email accepted for ' . $sent . ' of ' . count($recipients) . ' participants. Check the mail settings if this is unexpected.');
        }
        admin_redirect($self . '#email');
    }
    if ($action === 'add') {
        [$data, $errors] = validate($_POST, [
            'name' => 'required|min:2|max:120', 'email' => 'required|email', 'phone' => 'required|phone',
            'organisation' => 'max:200', 'city' => 'max:100', 'team_name' => 'max:150', 'message' => 'text|max:500',
            'guardian_name' => 'max:120', 'guardian_phone' => 'phone', 'add_status' => 'required|in:registered,confirmed,waitlisted,attended',
            'consent' => 'accepted',
        ], ['phone' => 'Mobile', 'guardian_phone' => 'Guardian mobile', 'consent' => 'the participant agreed to the use of their details', 'add_status' => 'Status']);
        $minor = !empty($_POST['is_minor']);
        if ($minor && ($data['guardian_name'] === '' || $data['guardian_phone'] === '' || empty($_POST['guardian_consent']))) {
            $errors['guardian_name'] = 'For participants under 18 enter the parent\'s or guardian\'s name and mobile and confirm their consent.';
        }
        if ($errors) {
            back_with_errors($errors, 'admin/' . $self . '#add', $_POST);
        }
        $user = db_row("SELECT id FROM users WHERE email = ? AND status = 'active'", [$data['email']]);
        $result = programhub_register($eventId, [
            'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'], 'organisation' => $data['organisation'], 'city' => $data['city'],
            'team_name' => $data['team_name'], 'message' => $data['message'], 'consent' => true, 'is_minor' => $minor,
            'guardian_name' => $data['guardian_name'], 'guardian_phone' => $data['guardian_phone'], 'guardian_consent' => !empty($_POST['guardian_consent']),
            'user_id' => $user ? (int) $user['id'] : null, 'source' => 'admin',
        ], ['admin' => true, 'ignore_capacity' => $data['add_status'] !== 'waitlisted' && !empty($_POST['overbook']), 'waitlist' => true]);
        if (!in_array($result['result'], ['ok', 'waitlisted'], true)) {
            back_with_errors(['email' => $result['message']], 'admin/' . $self . '#add', $_POST);
        }
        $newId = (int) $result['id'];
        $final = $result['status'];
        if ($data['add_status'] !== $final && !($final === 'waitlisted' && $data['add_status'] !== 'waitlisted')) {
            programhub_set_registration_status($newId, $data['add_status']);
            $final = $data['add_status'];
        }
        $emailed = false;
        if (!empty($_POST['notify'])) {
            $reg = db_row('SELECT * FROM event_registrations WHERE id = ?', [$newId]);
            $kind = $final === 'waitlisted' ? 'waitlisted' : 'confirmed';
            $extra = in_array(event_mode($event), ['online', 'hybrid'], true) ? ['token' => $result['token']] : [];
            $emailed = programhub_send_participant_email($event, $reg, $kind, $extra);
        }
        admin_log('event_registration_added', $evLabel($event) . ': registration #' . $newId . ' added by staff (' . $final . ')' . ($emailed ? ', confirmation emailed' : ''));
        flash($final === 'waitlisted' && $data['add_status'] !== 'waitlisted' ? 'warning' : 'success',
            ($final === 'waitlisted' && $data['add_status'] !== 'waitlisted' ? 'The event is full, so ' . $data['name'] . ' was added to the waiting list (tick "allow overbooking" to give a place anyway).' : $data['name'] . ' was added (' . strtolower(EVENT_REG_STATUSES[$final]) . ').')
            . ($emailed ? ' Confirmation emailed.' : ''));
        admin_redirect($self);
    }
    if ($action === 'certificates') {
        require_permission('certificates.manage');
        if ((int) $event['certificate_confirmed'] !== 1) {
            flash('error', 'Certificates are not confirmed for this event. Tick "certificate officially confirmed" in the event first.');
            admin_redirect($self . '#certificates');
        }
        $res = programhub_admin_issue_certificates($event);
        if ($res['issued']) {
            admin_log('certificates_issued', $evLabel($event) . ': ' . count($res['issued']) . ' participation certificate(s) issued (pending approval): ' . implode(', ', array_slice($res['issued'], 0, 30)));
        }
        $msg = count($res['issued']) . ' participation certificate' . (count($res['issued']) === 1 ? '' : 's') . ' created (pending approval in Certificates).';
        if ($res['linked']) {
            $msg .= ' ' . $res['linked'] . ' linked to an existing certificate.';
        }
        if ($res['needs_account']) {
            $msg .= ' ' . count($res['needs_account']) . ' attendee' . (count($res['needs_account']) === 1 ? ' needs' : 's need') . ' a verified website account first: ' . implode(', ', array_slice($res['needs_account'], 0, 10)) . '.';
        }
        flash($res['needs_account'] ? 'warning' : 'success', $msg);
        admin_redirect($self . '#certificates');
    }

    // ----- One registration (detail view) -------------------------------
    $regId = (int) ($_POST['id'] ?? 0);
    $reg = $regId ? db_row('SELECT * FROM event_registrations WHERE id = ? AND event_id = ?', [$regId, $eventId]) : null;
    if (!$reg) {
        flash('error', 'Registration not found.');
        admin_redirect($self);
    }
    $viewUrl = $self . '&view=' . $regId;
    $rlabel = $evLabel($event) . ' registration #' . $regId;
    if ($action === 'status') {
        $new = programhub_admin_param($_POST, 'status');
        if (!isset(EVENT_REG_STATUSES[$new]) || $new === $reg['status']) {
            flash('info', 'The status was not changed.');
            admin_redirect($viewUrl);
        }
        $res = programhub_admin_bulk_status([$reg], $new, ['notify' => !empty($_POST['notify']), 'promote' => !empty($_POST['promote'])]);
        admin_log('event_registration_updated', $rlabel . ': ' . $reg['status'] . ' -> ' . $new . ($res['emailed'] ? ' (emailed)' : '') . ($res['promoted'] ? ', ' . count($res['promoted']) . ' moved from the waiting list' : ''));
        flash($res['over'] > 0 ? 'warning' : 'success', $reg['name'] . ': ' . EVENT_REG_STATUSES[$new] . '.' . ($res['emailed'] ? ' Email sent.' : '') . ($res['promoted'] ? ' ' . implode(', ', $res['promoted']) . ' moved from the waiting list.' : '') . ($res['over'] > 0 ? ' The event is now over capacity.' : ''));
        admin_redirect($viewUrl);
    }
    if ($action === 'note') {
        $note = mb_substr(clean_string($_POST['admin_note'] ?? '', true), 0, 500);
        db_update('event_registrations', ['admin_note' => $note !== '' ? $note : null], 'id = :id', ['id' => $regId]);
        admin_log('event_registration_note', $rlabel . ': internal note ' . ($note !== '' ? 'saved' : 'cleared'));
        flash('success', 'Note saved.');
        admin_redirect($viewUrl);
    }
    if ($action === 'resend') {
        $kind = $reg['status'] === 'waitlisted' ? 'waitlisted' : 'confirmed';
        if (!in_array($reg['status'], ['registered', 'confirmed', 'waitlisted'], true)) {
            flash('error', 'A confirmation can only be sent to registered, confirmed or waitlisted participants.');
            admin_redirect($viewUrl);
        }
        $extra = in_array(event_mode($event), ['online', 'hybrid'], true) ? ['token' => programhub_issue_access_token($regId)] : [];
        $ok = programhub_send_participant_email($event, $reg, $kind, $extra);
        admin_log('event_registration_email', $rlabel . ': ' . $kind . ' email re-sent' . ($ok ? '' : ' (not accepted by the mail server)'));
        flash($ok ? 'success' : 'warning', $ok ? 'Confirmation email sent again' . ($extra ? ' with a new private joining page link (the old link no longer works).' : '.') : 'The mail server did not accept the email.');
        admin_redirect($viewUrl);
    }
    if ($action === 'delete') {
        db_query('DELETE FROM event_registrations WHERE id = ?', [$regId]);
        admin_log('event_registration_deleted', $rlabel . ' deleted (' . $reg['status'] . ')');
        $promoted = null;
        if (in_array($reg['status'], EVENT_SEAT_STATUSES, true) && !empty($_POST['promote']) && !event_is_past($event)) {
            $promoted = programhub_promote_waitlist($eventId);
            if ($promoted) {
                $extra = in_array(event_mode($event), ['online', 'hybrid'], true) ? ['token' => programhub_issue_access_token((int) $promoted['id'])] : [];
                programhub_send_participant_email($event, $promoted, 'promoted', $extra);
                admin_log('event_waitlist_promoted', $evLabel($event) . ' registration #' . (int) $promoted['id'] . ' moved from the waiting list after a deletion');
            }
        }
        flash('success', 'The registration of ' . $reg['name'] . ' was deleted.' . ($promoted ? ' ' . $promoted['name'] . ' moved from the waiting list and was emailed.' : ''));
        admin_redirect($self);
    }
    admin_redirect($self);
}

// =====================================================================
// Detail view
// =====================================================================
if ($event && $viewId) {
    $reg = db_row('SELECT r.*, c.certificate_no, c.status AS certificate_status FROM event_registrations r LEFT JOIN certificates c ON c.id = r.certificate_id WHERE r.id = ? AND r.event_id = ?', [$viewId, $eventId]);
    if (!$reg) {
        flash('error', 'Registration not found.');
        admin_redirect($self);
    }
    $viewUrl = $self . '&view=' . $viewId;
    $online = in_array(event_mode($event), ['online', 'hybrid'], true);
    $account = $reg['user_id'] ? db_row('SELECT id, name, email, email_verified_at, status FROM users WHERE id = ?', [(int) $reg['user_id']]) : null;
    admin_page_start('Registration ' . programhub_admin_reg_ref($reg), [
        ['label' => 'All registrations of this event', 'url' => admin_url($self), 'icon' => 'list', 'style' => 'ghost'],
    ]);
    ?>
    <div class="ph-admin">
    <div class="admin-grid-main">
        <div>
            <section class="panel">
                <div class="panel-head"><div><h2><?= icon('user') ?><?= e($reg['name']) ?></h2><p class="muted small"><?= e($event['title']) ?> · <?= e(event_when($event)) ?></p></div><?= programhub_admin_reg_badge($reg['status']) ?></div>
                <dl class="ph-kv">
                    <dt>Reference</dt><dd><?= e(programhub_admin_reg_ref($reg)) ?></dd>
                    <dt>Email</dt><dd><a href="mailto:<?= e($reg['email']) ?>"><?= e($reg['email']) ?></a></dd>
                    <dt>Mobile</dt><dd><a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $reg['phone'])) ?>"><?= e($reg['phone']) ?></a></dd>
                    <?php if ($reg['organisation']): ?><dt>School / organisation</dt><dd><?= e($reg['organisation']) ?></dd><?php endif; ?>
                    <?php if ($reg['city']): ?><dt>City</dt><dd><?= e($reg['city']) ?></dd><?php endif; ?>
                    <?php if ($reg['team_name']): ?><dt>Team</dt><dd><?= e($reg['team_name']) ?></dd><?php endif; ?>
                    <?php if ($reg['message']): ?><dt>Message</dt><dd><?= e_nl($reg['message']) ?></dd><?php endif; ?>
                    <?php if (($answers = programhub_admin_answers_text($reg['answers'])) !== ''): ?><dt>Other answers</dt><dd><?= e($answers) ?></dd><?php endif; ?>
                    <dt>Under 18</dt><dd><?= (int) $reg['is_minor'] ? 'Yes' : 'No' ?></dd>
                    <?php if ((int) $reg['is_minor']): ?>
                        <dt>Parent / guardian</dt><dd><?= e((string) $reg['guardian_name']) ?> · <a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $reg['guardian_phone'])) ?>"><?= e((string) $reg['guardian_phone']) ?></a></dd>
                        <dt>Guardian consent</dt><dd><?= $reg['guardian_consent_at'] ? e(format_date($reg['guardian_consent_at'], 'd M Y, h:i A')) : '<span class="badge badge-red">Missing</span>' ?></dd>
                    <?php endif; ?>
                    <dt>Privacy consent</dt><dd><?= $reg['consent_at'] ? e(format_date($reg['consent_at'], 'd M Y, h:i A')) : '<span class="muted">Not recorded (older registration)</span>' ?></dd>
                    <dt>Source</dt><dd><?= e(humanize((string) ($reg['source'] ?: 'event_page'))) ?></dd>
                    <dt>Website account</dt><dd><?php if ($account): ?><?= admin_can('users.manage') ? '<a href="' . e(admin_url('user-view.php?id=' . (int) $account['id'])) . '">' . e($account['name']) . '</a>' : e($account['name']) ?><?= $account['email_verified_at'] ? '' : ' <span class="muted">(email not verified)</span>' ?><?php else: ?><span class="muted">None</span><?php endif; ?></dd>
                    <?php if ($reg['certificate_no']): ?><dt>Certificate</dt><dd><?= admin_can('certificates.manage') ? '<a href="' . e(admin_url('certificates.php?preview=' . (int) $reg['certificate_id'])) . '">' . e($reg['certificate_no']) . '</a>' : e($reg['certificate_no']) ?> <?= status_badge($reg['certificate_status']) ?></dd><?php endif; ?>
                </dl>
            </section>
            <section class="panel">
                <div class="panel-head"><h2><?= icon('clock') ?>History</h2></div>
                <ul class="ph-timeline">
                    <li><?= icon('check') ?>Registered <?= e(format_date($reg['created_at'], 'd M Y, h:i A')) ?></li>
                    <?php foreach (['confirmed_at' => 'Confirmed', 'meeting_link_sent_at' => 'Joining link emailed', 'attended_at' => 'Marked as attended', 'cancelled_at' => 'Cancelled'] as $col => $lbl): if (!empty($reg[$col])): ?>
                        <li><?= icon('check') ?><?= e($lbl) ?> <?= e(format_date($reg[$col], 'd M Y, h:i A')) ?></li>
                    <?php endif; endforeach; ?>
                    <?php if ($reg['updated_at']): ?><li><?= icon('edit') ?>Last changed <?= e(format_date($reg['updated_at'], 'd M Y, h:i A')) ?></li><?php endif; ?>
                </ul>
            </section>
        </div>
        <div>
            <section class="panel">
                <div class="panel-head"><h2><?= icon('refresh') ?>Status</h2></div>
                <form method="post" action="<?= e(admin_url($viewUrl)) ?>">
                    <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $viewId ?>">
                    <div class="form-group mb-2"><label for="status">Change to</label>
                        <select id="status" name="status"><?= options_html(EVENT_REG_STATUSES, $reg['status'], true, '') ?></select></div>
                    <label class="checkbox mb-2"><input type="checkbox" name="notify" value="1"><span>Email the participant (confirmation or "place available")</span></label>
                    <label class="checkbox mb-2"><input type="checkbox" name="promote" value="1" checked><span>When cancelling, offer the place to the waiting list</span></label>
                    <button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?>Save status</button>
                </form>
            </section>
            <section class="panel">
                <div class="panel-head"><h2><?= icon('mail') ?>Emails</h2></div>
                <div class="ph-actions-row">
                    <?php if (in_array($reg['status'], ['registered', 'confirmed', 'waitlisted'], true)): ?>
                        <form method="post" action="<?= e(admin_url($viewUrl)) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><input type="hidden" name="id" value="<?= $viewId ?>">
                            <button class="btn btn-ghost btn-sm" type="submit"><?= icon('send') ?>Resend confirmation</button></form>
                    <?php endif; ?>
                    <?php if ($online && in_array($reg['status'], EVENT_ACCESS_STATUSES, true)): ?>
                        <form method="post" action="<?= e(admin_url($self)) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="bulk"><input type="hidden" name="bulk_action" value="meeting_link"><input type="hidden" name="ids[]" value="<?= $viewId ?>"><input type="hidden" name="return_to" value="<?= e($viewUrl) ?>">
                            <button class="btn btn-ghost btn-sm" type="submit"><?= icon('link') ?>Email joining link</button></form>
                    <?php endif; ?>
                </div>
                <p class="muted small mt-2 mb-0">A new private joining-page link is created each time; earlier links stop working.</p>
            </section>
            <section class="panel">
                <div class="panel-head"><h2><?= icon('lock') ?>Internal note</h2></div>
                <form method="post" action="<?= e(admin_url($viewUrl)) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="note"><input type="hidden" name="id" value="<?= $viewId ?>">
                    <label class="sr-only" for="admin_note">Internal note</label>
                    <textarea id="admin_note" name="admin_note" rows="3" maxlength="500" placeholder="Only staff see this"><?= e((string) $reg['admin_note']) ?></textarea>
                    <button class="btn btn-ghost btn-sm mt-1" type="submit"><?= icon('check') ?>Save note</button></form>
            </section>
            <section class="panel ph-danger">
                <div class="panel-head"><h2><?= icon('trash') ?>Delete</h2></div>
                <form method="post" action="<?= e(admin_url($viewUrl)) ?>" data-confirm="<?= e('Delete the registration of ' . $reg['name'] . ' permanently?') ?>">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $viewId ?>">
                    <p class="muted small">For example after a request to delete personal data. To keep the record, cancel it instead.</p>
                    <?php if (in_array($reg['status'], EVENT_SEAT_STATUSES, true)): ?><label class="checkbox mb-2"><input type="checkbox" name="promote" value="1" checked><span>Offer the place to the waiting list</span></label><?php endif; ?>
                    <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash') ?>Delete registration</button>
                </form>
            </section>
        </div>
    </div>
    </div>
    <?php
    programhub_admin_assets();
    admin_page_end();
    exit;
}

// =====================================================================
// Lists
// =====================================================================
$status = isset(EVENT_REG_STATUSES[programhub_admin_param($_GET, 'status')]) ? (string) $_GET['status'] : '';
$q = trim(mb_substr(clean_string($_GET['q'] ?? ''), 0, 100));
$minor = ($_GET['minor'] ?? '') === '1';
$filterEvent = !$event ? (int) ($_GET['e'] ?? 0) : 0;
$filterCat = !$event ? (int) ($_GET['category'] ?? 0) : 0;
$when = !$event && in_array($_GET['when'] ?? '', ['upcoming', 'past'], true) ? (string) $_GET['when'] : '';
$range = !$event ? admin_date_range('all') : null;
$where = 'WHERE 1=1';
$params = [];
if ($event) {
    $where .= ' AND r.event_id = ?';
    $params[] = $eventId;
} else {
    if ($filterEvent) {
        $where .= ' AND r.event_id = ?';
        $params[] = $filterEvent;
    }
    if ($filterCat) {
        $where .= ' AND e.category_id = ?';
        $params[] = $filterCat;
    }
    if ($when === 'upcoming') {
        $where .= ' AND COALESCE(e.end_datetime, e.start_datetime) >= NOW()';
    } elseif ($when === 'past') {
        $where .= ' AND COALESCE(e.end_datetime, e.start_datetime) < NOW()';
    }
    if ($range && $range['key'] !== 'all') {
        $where .= ' AND r.created_at >= ? AND r.created_at < ?';
        $params[] = $range['from'] . ' 00:00:00';
        $params[] = date('Y-m-d', strtotime($range['to'] . ' +1 day')) . ' 00:00:00';
    }
}
if ($minor) {
    $where .= ' AND r.is_minor = 1';
}
if ($q !== '' && preg_match('/^(?:PWF-EVR-\d{4}-|#)?0*(\d{1,9})$/i', $q, $m) && (stripos($q, 'PWF-EVR-') === 0 || $q[0] === '#')) {
    $where .= ' AND r.id = ?';
    $params[] = (int) $m[1];
} else {
    $where .= search_where(['r.name', 'r.email', 'r.phone', 'r.organisation', 'r.city', 'r.team_name'], $q, $params);
}
$countWhere = $where;
$countParams = $params;
if ($status !== '') {
    $where .= ' AND r.status = ?';
    $params[] = $status;
}
$from = 'FROM event_registrations r JOIN events e ON e.id = r.event_id LEFT JOIN certificates c ON c.id = r.certificate_id';

if (isset($_GET['export'])) {
    require_permission('exports.run');
    admin_log('event_registrations_exported', ($event ? $evLabel($event) : 'All events') . ': registrations exported' . ($status ? ' (' . $status . ')' : '') . ($q !== '' ? ' (search)' : ''));
    $rows = db_rows("SELECT r.*, e.title AS event_title, e.start_datetime AS event_start, c.certificate_no $from $where ORDER BY e.start_datetime, r.id", $params);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [programhub_admin_reg_ref($r), $r['event_title'], date('Y-m-d H:i', strtotime((string) $r['event_start'])), $r['name'], $r['email'], $r['phone'],
            $r['organisation'], $r['city'], $r['team_name'], (int) $r['is_minor'] ? 'Yes' : 'No', $r['guardian_name'], $r['guardian_phone'], $r['guardian_consent_at'],
            $r['consent_at'], EVENT_REG_STATUSES[$r['status']] ?? $r['status'], $r['source'], $r['user_id'] ? 'Yes' : 'No', $r['created_at'], $r['confirmed_at'],
            $r['attended_at'], $r['cancelled_at'], $r['meeting_link_sent_at'], $r['certificate_no'], $r['message'], programhub_admin_answers_text($r['answers']), $r['admin_note']];
    }
    send_csv('registrations-' . ($event ? $event['slug'] : 'all-' . date('Y-m-d')) . '.csv', ['Reference', 'Event', 'Event date', 'Name', 'Email', 'Mobile', 'Organisation', 'City', 'Team',
        'Under 18', 'Guardian name', 'Guardian mobile', 'Guardian consent', 'Privacy consent', 'Status', 'Source', 'Has account', 'Registered on', 'Confirmed on',
        'Attended on', 'Cancelled on', 'Joining link sent', 'Certificate', 'Message', 'Other answers', 'Internal note'], $out);
}

$counts = array_fill_keys(array_keys(EVENT_REG_STATUSES), 0);
foreach (db_rows("SELECT r.status, COUNT(*) AS n $from $countWhere GROUP BY r.status", $countParams) as $c) {
    $counts[$c['status']] = (int) $c['n'];
}
$p = paginate((int) db_value("SELECT COUNT(*) $from $where", $params), 50);
$rows = db_rows("SELECT r.*, e.title AS event_title, e.slug AS event_slug, e.event_type, e.mode, e.start_datetime AS event_start, c.certificate_no
                 $from $where ORDER BY " . ($event ? 'r.id DESC' : 'r.created_at DESC, r.id DESC') . " LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$baseQuery = array_filter(['event' => $event ? $eventId : '', 'q' => $q, 'minor' => $minor ? '1' : '', 'e' => $filterEvent ?: '', 'category' => $filterCat ?: '', 'when' => $when,
    'range' => $range && $range['key'] !== 'all' ? $range['key'] : '', 'from' => $range && $range['key'] === 'custom' ? $range['from'] : '', 'to' => $range && $range['key'] === 'custom' ? $range['to'] : '']);
$listUrl = function (array $extra = []) use ($baseQuery): string {
    return admin_url('event-registrations.php?' . http_build_query(array_filter($extra + $baseQuery, function ($v) { return $v !== '' && $v !== null; })));
};
$online = $event && in_array(event_mode($event), ['online', 'hybrid'], true);
$seats = $event ? $counts['registered'] + $counts['confirmed'] + $counts['attended'] + $counts['no_show'] : 0;
if ($event) {
    // per-event figures independent of the search box
    $all = programhub_admin_reg_counts($eventId);
    $seats = $all['registered'] + $all['confirmed'] + $all['attended'] + $all['no_show'];
    $unsentConfirmed = (int) db_value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status = 'confirmed' AND meeting_link_sent_at IS NULL", [$eventId]);
    $unsentAll = (int) db_value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status IN ('registered','confirmed','attended') AND meeting_link_sent_at IS NULL", [$eventId]);
    $attendedNoCert = (int) db_value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status = 'attended' AND certificate_id IS NULL", [$eventId]);
}

admin_page_start($event ? 'Registrations' : 'All event registrations', array_values(array_filter([
    $event ? ['label' => 'Edit event', 'url' => admin_url('event-edit.php?id=' . $eventId), 'icon' => 'edit', 'style' => 'ghost'] : null,
    $event ? ['label' => 'All registrations', 'url' => admin_url('event-registrations.php'), 'icon' => 'users', 'style' => 'ghost'] : null,
    ['label' => 'Events', 'url' => admin_url('events.php'), 'icon' => 'calendar', 'style' => 'ghost'],
    ['label' => 'Alert settings', 'url' => admin_url('events.php?tab=settings'), 'icon' => 'bell', 'style' => 'ghost'],
])));
?>
<div class="ph-admin">
<?php if ($event): ?>
    <section class="panel">
        <div class="panel-head">
            <div><h2><?= icon(EVENT_TYPE_ICONS[$event['event_type']] ?? 'calendar') ?><?= e($event['title']) ?></h2><p class="muted small mb-0"><?= e(event_when($event, true)) ?> · <?= e($event['location']) ?> · <?= e(EVENT_MODES[event_mode($event)]) ?></p></div>
            <div><?= programhub_status_badge(['registered' => $seats] + $event) ?> <a class="btn btn-ghost btn-sm" href="<?= e(event_url($event)) ?>" target="_blank" rel="noopener"><?= icon('eye') ?>View page</a></div>
        </div>
        <div class="stat-cards mb-0">
            <div class="stat-card"><span class="icon-circle"><?= icon('target') ?></span><span><strong><?= $seats ?><?= $event['capacity'] ? ' / ' . (int) $event['capacity'] : '' ?></strong><small><?= $event['capacity'] ? max(0, (int) $event['capacity'] - $seats) . ' places left' : 'Places taken (no limit)' ?><?= (int) $event['registration_enabled'] ? '' : ' · registration off' ?></small></span></div>
            <a class="stat-card" href="<?= e($listUrl(['status' => 'waitlisted'])) ?>"><span class="icon-circle gold"><?= icon('users') ?></span><span><strong><?= $all['waitlisted'] ?></strong><small>On the waiting list<?= (int) $event['waitlist_enabled'] ? '' : ' (waiting list off)' ?></small></span></a>
            <a class="stat-card" href="<?= e($listUrl(['status' => 'attended'])) ?>"><span class="icon-circle blue"><?= icon('check-circle') ?></span><span><strong><?= $all['attended'] ?></strong><small>Attended · <?= $all['no_show'] ?> no-show</small></span></a>
            <a class="stat-card" href="<?= e($listUrl(['status' => 'cancelled'])) ?>"><span class="icon-circle gold"><?= icon('x') ?></span><span><strong><?= $all['cancelled'] ?></strong><small>Cancelled</small></span></a>
        </div>
    </section>
<?php endif; ?>

<form class="filter-row" method="get">
    <?php if ($event): ?><input type="hidden" name="event" value="<?= $eventId ?>"><?php endif; ?>
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <label class="sr-only" for="f-q">Search</label>
    <input id="f-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, email, mobile, organisation, city or PWF-EVR reference">
    <?php if (!$event): ?>
        <label class="sr-only" for="f-e">Event</label>
        <select id="f-e" name="e"><option value="">All events</option><?php foreach (programhub_admin_event_options(true) as $k => $lbl) { echo '<option value="' . (int) $k . '"' . ($filterEvent === (int) $k ? ' selected' : '') . '>' . e($lbl) . '</option>'; } ?></select>
        <label class="sr-only" for="f-cat">Category</label>
        <select id="f-cat" name="category"><option value="">All categories</option><?php foreach (program_category_options() as $k => $lbl) { echo '<option value="' . (int) $k . '"' . ($filterCat === (int) $k ? ' selected' : '') . '>' . e($lbl) . '</option>'; } ?></select>
        <label class="sr-only" for="f-when">Event date</label>
        <select id="f-when" name="when"><?= options_html(['' => 'Upcoming & past events', 'upcoming' => 'Upcoming events', 'past' => 'Past events'], $when, true, '') ?></select>
        <?php if ($range && $range['key'] !== 'all'): ?><input type="hidden" name="range" value="<?= e($range['key']) ?>"><?php if ($range['key'] === 'custom'): ?><input type="hidden" name="from" value="<?= e($range['from']) ?>"><input type="hidden" name="to" value="<?= e($range['to']) ?>"><?php endif; ?><?php endif; ?>
    <?php endif; ?>
    <label class="checkbox small"><input type="checkbox" name="minor" value="1"<?= $minor ? ' checked' : '' ?>><span>Under 18 only</span></label>
    <button class="btn btn-primary btn-sm" type="submit"><?= icon('search') ?>Filter</button>
    <?php if ($q !== '' || $minor || $filterEvent || $filterCat || $when !== '' || $status !== '' || ($range && $range['key'] !== 'all')): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url($self)) ?>">Reset</a><?php endif; ?>
    <?php if (admin_can('exports.run') && $p['total']): ?><a class="btn btn-ghost btn-sm" href="<?= e($listUrl(['status' => $status, 'export' => '1'])) ?>"><?= icon('download') ?>Export CSV</a><?php endif; ?>
</form>
<?php if (!$event): ?><?= admin_range_picker($range) ?><?php endif; ?>

<nav class="ph-chipbar" aria-label="Filter by status">
    <a href="<?= e($listUrl()) ?>"<?= $status === '' ? ' class="active" aria-current="page"' : '' ?>>All <em><?= array_sum($counts) ?></em></a>
    <?php foreach (EVENT_REG_STATUSES as $k => $lbl): ?>
        <a href="<?= e($listUrl(['status' => $k])) ?>"<?= $status === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($lbl) ?> <em><?= (int) $counts[$k] ?></em></a>
    <?php endforeach; ?>
</nav>

<section class="panel">
    <?php if ($rows): ?>
        <form method="post" id="bulk-form" action="<?= e(admin_url($self)) ?>" data-ph-bulk-form><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="bulk"><?php if ($event): ?><input type="hidden" name="event" value="<?= $eventId ?>"><?php endif; ?></form>
        <p class="table-meta"><span><?= (int) $p['total'] ?> registration<?= (int) $p['total'] === 1 ? '' : 's' ?></span><span>Tick rows, then choose an action below.</span></p>
        <div class="table-wrap"><table class="table">
            <thead><tr><th class="ph-check-col"><input type="checkbox" data-check-all=".ph-reg-check" aria-label="Select all on this page"></th><th>Participant</th><?php if (!$event): ?><th>Event</th><?php endif; ?><th>Status</th><th>Registered</th><th>Details</th><th class="text-right"><span class="sr-only">Open</span></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $rid = (int) $r['id']; $rUrl = admin_url('event-registrations.php?event=' . (int) $r['event_id'] . '&view=' . $rid); ?>
                <tr>
                    <td class="ph-check-col"><input class="ph-reg-check" type="checkbox" name="ids[]" value="<?= $rid ?>" form="bulk-form" aria-label="Select <?= e($r['name']) ?>"></td>
                    <td class="ph-person"><a href="<?= e($rUrl) ?>"><strong><?= e($r['name']) ?></strong></a>
                        <small><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a> · <a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $r['phone'])) ?>"><?= e($r['phone']) ?></a></small>
                        <?php $extra = array_filter([(string) $r['organisation'], (string) $r['city'], $r['team_name'] ? 'Team: ' . $r['team_name'] : '']); if ($extra): ?><small><?= e(implode(' · ', $extra)) ?></small><?php endif; ?></td>
                    <?php if (!$event): ?><td class="small"><a href="<?= e(admin_url('event-registrations.php?event=' . (int) $r['event_id'])) ?>"><?= e($r['event_title']) ?></a><br><span class="muted"><?= e(format_date($r['event_start'], 'd M Y')) ?></span></td><?php endif; ?>
                    <td><?= programhub_admin_reg_badge($r['status']) ?></td>
                    <td class="nowrap small"><?= e(format_date($r['created_at'], 'd M Y')) ?><br><span class="muted"><?= e(format_date($r['created_at'], 'h:i A')) ?> · <?= e(humanize((string) ($r['source'] ?: 'website'))) ?></span></td>
                    <td><div class="ph-chips">
                        <?php if ((int) $r['is_minor']): ?><span class="ph-chip<?= $r['guardian_consent_at'] ? '' : ' is-warn' ?>" title="Under 18 - guardian: <?= e((string) $r['guardian_name']) ?>"><?= icon('shield') ?>Under 18</span><?php endif; ?>
                        <?php if ($r['meeting_link_sent_at']): ?><span class="ph-chip" title="Joining link emailed <?= e(format_date($r['meeting_link_sent_at'], 'd M Y, h:i A')) ?>"><?= icon('link') ?>Link sent</span><?php endif; ?>
                        <?php if ($r['certificate_no']): ?><span class="ph-chip"><?= icon('award') ?><?= e($r['certificate_no']) ?></span><?php endif; ?>
                        <?php if ($r['user_id']): ?><span class="ph-chip"><?= icon('user') ?>Account</span><?php endif; ?>
                        <?php if ($r['admin_note']): ?><span class="ph-chip" title="<?= e($r['admin_note']) ?>"><?= icon('file-text') ?>Note</span><?php endif; ?>
                    </div></td>
                    <td class="actions-cell"><a class="icon-btn" href="<?= e($rUrl) ?>" aria-label="Open the registration of <?= e($r['name']) ?>" title="Open"><?= icon('eye') ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
        <div class="ph-bulkbar" data-ph-bulk=".ph-reg-check">
            <span data-ph-count aria-live="polite">0 selected</span>
            <label class="sr-only" for="bulk_action">Action for the selected registrations</label>
            <select id="bulk_action" name="bulk_action" form="bulk-form" required>
                <option value="">Choose an action…</option>
                <?php foreach (PH_BULK_ACTIONS as $k => $lbl): ?>
                    <option value="<?= e($k) ?>"<?= $k === 'cancelled' ? ' data-confirm-msg="Cancel the selected registrations?"' : ($k === 'meeting_link' ? ' data-confirm-msg="Email the private online joining link to the selected participants?"' : '') ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="checkbox"><input type="checkbox" name="notify" value="1" form="bulk-form"><span>Email participants (confirm / place available)</span></label>
            <label class="checkbox"><input type="checkbox" name="promote" value="1" form="bulk-form" checked><span>Offer cancelled places to the waiting list</span></label>
            <button class="btn btn-primary btn-sm" type="submit" form="bulk-form" data-ph-bulk-submit><?= icon('check') ?>Apply</button>
        </div>
    <?php else: ?>
        <?= empty_state($q !== '' || $status !== '' || $minor || $filterEvent || $filterCat || $when !== '' || ($range && $range['key'] !== 'all') ? 'No registrations match these filters.' : ($event ? 'No registrations yet.' : 'No event registrations yet.')) ?>
    <?php endif; ?>
</section>

<?php if ($event): ?>
    <div class="admin-grid-2">
        <?php if ($online): ?>
            <section class="panel ph-private" id="joining">
                <div class="panel-head"><h2><?= icon('lock') ?>Online joining link</h2></div>
                <?php if (trim((string) $event['meeting_url']) === ''): ?>
                    <p class="ph-private-note"><?= icon('alert-triangle') ?><span>No meeting link is saved yet. Add it in the event (Details &gt; Online joining details).</span></p>
                    <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('event-edit.php?id=' . $eventId)) ?>"><?= icon('edit') ?>Add the meeting link</a>
                <?php else: ?>
                    <p class="small">Each participant receives the link by email together with a private joining page that only works for their registration. <?= $unsentConfirmed ?> confirmed and <?= $unsentAll ?> registered/confirmed/attended participant<?= $unsentAll === 1 ? ' has' : 's have' ?> not received it yet.</p>
                    <form method="post" action="<?= e(admin_url($self)) ?>" data-confirm="Email the online joining link now?">
                        <?= csrf_field() ?><input type="hidden" name="action" value="send_links"><input type="hidden" name="event" value="<?= $eventId ?>">
                        <div class="form-group mb-2"><label for="audience">Send to</label>
                            <select id="audience" name="audience"><option value="confirmed">Confirmed participants</option><option value="all">Everyone holding a place (registered, confirmed, attended)</option></select></div>
                        <label class="checkbox mb-2"><input type="checkbox" name="only_unsent" value="1" checked><span>Only people who have not received it yet</span></label>
                        <button class="btn btn-primary btn-sm" type="submit"<?= $event['status'] === 'published' ? '' : ' disabled' ?>><?= icon('send') ?>Email the joining link</button>
                        <?php if ($event['status'] !== 'published'): ?><p class="muted small mt-1 mb-0">Available while the event is published.</p><?php endif; ?>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="panel" id="add">
            <div class="panel-head"><h2><?= icon('plus') ?>Add a registration</h2></div>
            <details<?= old('name', null) !== null ? ' open' : '' ?>><summary class="small"><strong>Register someone by phone or at the desk</strong></summary>
                <form method="post" action="<?= e(admin_url($self)) ?>" class="mt-2" data-validate data-ph-form>
                    <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="event" value="<?= $eventId ?>">
                    <div class="form-grid">
                        <div class="form-group"><label for="a-name">Name<span class="req">*</span></label><input id="a-name" name="name" required maxlength="120" value="<?= e((string) old('name')) ?>"><?= field_error('name') ?></div>
                        <div class="form-group"><label for="a-email">Email<span class="req">*</span></label><input id="a-email" type="email" name="email" required maxlength="190" value="<?= e((string) old('email')) ?>"><?= field_error('email') ?></div>
                        <div class="form-group"><label for="a-phone">Mobile<span class="req">*</span></label><input id="a-phone" type="tel" name="phone" required maxlength="20" value="<?= e((string) old('phone')) ?>"><?= field_error('phone') ?></div>
                        <div class="form-group"><label for="a-org">School / organisation</label><input id="a-org" name="organisation" maxlength="200" value="<?= e((string) old('organisation')) ?>"></div>
                        <div class="form-group"><label for="a-city">City</label><input id="a-city" name="city" maxlength="100" value="<?= e((string) old('city')) ?>"></div>
                        <?php if (in_array($event['event_type'], HACKATHON_EVENT_TYPES, true)): ?><div class="form-group"><label for="a-team">Team name</label><input id="a-team" name="team_name" maxlength="150" value="<?= e((string) old('team_name')) ?>"></div><?php endif; ?>
                        <div class="form-group"><label for="a-status">Status</label><select id="a-status" name="add_status"><?= options_html(['registered' => 'Registered', 'confirmed' => 'Confirmed', 'waitlisted' => 'Waiting list', 'attended' => 'Attended (walk-in)'], (string) old('add_status', 'registered'), true, '') ?></select></div>
                        <div class="form-group full"><label class="checkbox"><input type="checkbox" name="is_minor" value="1"<?= old('is_minor', null) !== null ? ' checked' : '' ?>><span>Participant is under 18</span></label></div>
                        <div class="form-group" data-ph-when="is_minor=1"><label for="a-gname">Parent / guardian name</label><input id="a-gname" name="guardian_name" maxlength="120" value="<?= e((string) old('guardian_name')) ?>"><?= field_error('guardian_name') ?></div>
                        <div class="form-group" data-ph-when="is_minor=1"><label for="a-gphone">Parent / guardian mobile</label><input id="a-gphone" type="tel" name="guardian_phone" maxlength="20" value="<?= e((string) old('guardian_phone')) ?>"><?= field_error('guardian_phone') ?></div>
                        <div class="form-group full" data-ph-when="is_minor=1"><label class="checkbox"><input type="checkbox" name="guardian_consent" value="1"><span>The parent / guardian gave consent</span></label></div>
                        <div class="form-group full"><label for="a-msg">Note from the participant</label><input id="a-msg" name="message" maxlength="500" value="<?= e((string) old('message')) ?>"></div>
                        <div class="form-group full"><label class="checkbox"><input type="checkbox" name="consent" value="1" required><span>The participant agreed that we use their details for this registration</span></label><?= field_error('consent') ?></div>
                        <div class="form-group full"><label class="checkbox"><input type="checkbox" name="overbook" value="1"><span>Allow overbooking if the event is full</span></label></div>
                        <div class="form-group full"><label class="checkbox"><input type="checkbox" name="notify" value="1" checked><span>Email a confirmation to the participant</span></label></div>
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit"><?= icon('plus') ?>Add registration</button>
                </form>
            </details>
        </section>

        <section class="panel" id="email">
            <div class="panel-head"><h2><?= icon('mail') ?>Email participants</h2></div>
            <form method="post" action="<?= e(admin_url($self)) ?>" data-validate data-confirm="Send this email now?">
                <?= csrf_field() ?><input type="hidden" name="action" value="email"><input type="hidden" name="event" value="<?= $eventId ?>">
                <fieldset class="mb-2" style="border:0;padding:0;margin:0"><legend class="small"><strong>Send to</strong></legend>
                    <div class="ph-check-grid">
                        <?php $toOld = old('to', null); foreach (EVENT_REG_STATUSES as $k => $lbl): $def = in_array($k, ['registered', 'confirmed', 'attended'], true); ?>
                            <label class="checkbox"><input type="checkbox" name="to[]" value="<?= e($k) ?>"<?= (is_array($toOld) ? in_array($k, $toOld, true) : $def) ? ' checked' : '' ?>><span><?= e($lbl) ?> (<?= (int) $all[$k] ?>)</span></label>
                        <?php endforeach; ?>
                    </div><?= field_error('to') ?></fieldset>
                <div class="form-group mb-2"><label for="subject">Subject<span class="req">*</span></label>
                    <input type="text" id="subject" name="subject" required minlength="3" maxlength="150" value="<?= e((string) old('subject', 'Update: ' . $event['title'])) ?>"><?= field_error('subject') ?></div>
                <div class="form-group mb-2"><label for="message">Message<span class="req">*</span></label>
                    <textarea id="message" name="message" required minlength="10" maxlength="5000" rows="6"><?= e((string) old('message')) ?></textarea>
                    <span class="help">Plain text. Each email starts with "Dear &lt;name&gt;" and ends with the event's date and link. Never include the meeting link here - use "Online joining link".</span><?= field_error('message') ?></div>
                <button class="btn btn-primary btn-sm" type="submit"><?= icon('send') ?>Send email</button>
            </form>
        </section>

        <section class="panel" id="certificates">
            <div class="panel-head"><h2><?= icon('award') ?>Participation certificates</h2></div>
            <?php if ((int) $event['certificate_confirmed'] !== 1): ?>
                <p class="muted small mb-0">Certificates are not confirmed for this event. When they are officially confirmed, tick "certificate officially confirmed" in the event - then certificates can be issued here to participants marked as attended.</p>
            <?php elseif (!admin_can('certificates.manage')): ?>
                <p class="muted small mb-0">Issuing certificates needs the certificates permission.</p>
            <?php else: ?>
                <p class="small"><?= $attendedNoCert ?> attendee<?= $attendedNoCert === 1 ? '' : 's' ?> without a certificate. Certificates are created for attendees with an active, verified website account (status <strong>pending</strong>; approve them in <a href="<?= e(admin_url('certificates.php?ctype=participation&status=pending')) ?>">Certificates</a>). Attendees without an account are listed so you can invite them to register.</p>
                <form method="post" action="<?= e(admin_url($self)) ?>" data-confirm="Create participation certificates for the attendees of this event?">
                    <?= csrf_field() ?><input type="hidden" name="action" value="certificates"><input type="hidden" name="event" value="<?= $eventId ?>">
                    <button class="btn btn-primary btn-sm" type="submit"<?= $attendedNoCert ? '' : ' disabled' ?>><?= icon('award') ?>Issue participation certificates</button>
                </form>
            <?php endif; ?>
        </section>
    </div>
<?php else: ?>
    <p class="muted small"><?= icon('info') ?> Open an event's registrations to add participants, send online joining links, email participants or issue participation certificates. New-registration alerts are set in <a href="<?= e(admin_url('events.php?tab=settings')) ?>">Events &gt; Settings</a>.</p>
<?php endif; ?>
</div>
<?php
programhub_admin_assets();
admin_page_end();
