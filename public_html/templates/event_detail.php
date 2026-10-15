<?php
/**
 * Event page: /event-details/<slug>/  and calendar file /event-details/<slug>/ics/
 * Registration: name / email / phone / message, one registration per email per event, capacity checked
 * against real registrations, closed for past, started, cancelled or full events. Rate limited.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$segments = route_segments();
if (!$segments) {
    header('Location: ' . page_url('events-page'), true, 301);
    exit;
}
$slug = $segments[0];
$wantIcs = count($segments) === 2 && $segments[1] === 'ics';
if (count($segments) > 2 || (count($segments) === 2 && !$wantIcs) || !preg_match('/^[a-z0-9][a-z0-9\-]*$/', $slug)) {
    not_found();
}
$event = db_row("SELECT e.*, p.title AS program_title FROM events e LEFT JOIN programs p ON p.id = e.program_id WHERE e.slug = ?", [$slug]);
$public = $event && in_array($event['status'], ['published', 'cancelled'], true);
$preview = $event && !$public && user_can('events.manage');
if (!$event || (!$public && !$preview)) {
    not_found();
}
$self = event_url($event['slug']);

// ---------------------------------------------------------------------
// Calendar file
// ---------------------------------------------------------------------
if ($wantIcs) {
    if (!$public) {
        not_found();
    }
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9\-]/', '', $event['slug']) . '.ics"');
    header('Cache-Control: public, max-age=600');
    header('X-Robots-Tag: noindex');
    echo event_ics($event);
    exit;
}

$state = event_state($event);

// ---------------------------------------------------------------------
// Registration (POST, before any output)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($self);
    if (honeypot_triggered()) {
        flash('success', 'Thank you. Your registration has been received.');
        redirect($self);
    }
    if (!$state['open']) {
        flash('error', $state['reason'] ?: 'Registration is closed for this event.');
        redirect($self . '#register');
    }
    [$data, $errors] = validate($_POST, [
        'name'    => 'required|min:2|max:120',
        'email'   => 'required|email',
        'phone'   => 'required|phone',
        'message' => 'text|max:500',
    ], ['phone' => 'Mobile number']);
    if ($errors) {
        back_with_errors($errors, $self . '#register', $_POST);
    }
    if (form_rate_limited('event', 5)) {
        flash('error', 'Too many registrations were sent from your network in the last hour. Please try again later or contact us.');
        redirect($self . '#register');
    }

    $result = '';
    $regId = 0;
    $pdo = db();
    try {
        $pdo->beginTransaction();
        // Lock the event row so two people cannot take the last place at the same time
        db_value('SELECT id FROM events WHERE id = ? FOR UPDATE', [$event['id']]);
        $existing = db_row('SELECT id, status FROM event_registrations WHERE event_id = ? AND email = ?', [$event['id'], $data['email']]);
        $taken = (int) db_value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status <> 'cancelled'", [$event['id']]);
        if ($existing && $existing['status'] !== 'cancelled') {
            $result = 'duplicate';
        } elseif (!empty($event['capacity']) && $taken >= (int) $event['capacity']) {
            $result = 'full';
        } elseif ($existing) {
            db_update('event_registrations', ['status' => 'registered', 'name' => $data['name'], 'phone' => $data['phone'],
                'message' => $data['message'] ?: null, 'user_id' => current_user_id()], 'id = :id', ['id' => $existing['id']]);
            $regId = (int) $existing['id'];
            $result = 'ok';
        } else {
            $regId = db_insert('event_registrations', [
                'event_id' => $event['id'], 'user_id' => current_user_id(), 'name' => $data['name'], 'email' => $data['email'],
                'phone' => $data['phone'], 'message' => $data['message'] ?: null, 'status' => 'registered',
            ]);
            $result = 'ok';
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // A parallel request with the same email hits the unique key: treat as duplicate
        $result = ($e instanceof PDOException && (string) $e->getCode() === '23000') ? 'duplicate' : 'error';
        if ($result === 'error') {
            error_log('Event registration failed: ' . $e->getMessage());
        }
    }

    if ($result === 'duplicate') {
        flash('info', 'This email address is already registered for this event. Check your inbox for the confirmation, or contact us if you need to change your details.');
        redirect($self . '#register');
    }
    if ($result === 'full') {
        flash('error', 'Sorry, all places for this event have just been taken.');
        redirect($self . '#register');
    }
    if ($result !== 'ok') {
        flash('error', 'Your registration could not be saved. Please try again in a few minutes.');
        redirect($self . '#register');
    }

    log_activity('form_event', 'Registered for event #' . $event['id'] . ' (registration #' . $regId . ')', current_user_id());
    track_conversion('event_registration', $regId);
    $body = '<p>Dear ' . e($data['name']) . ',</p>'
        . '<p>Thank you for registering for <strong>' . e($event['title']) . '</strong>. Your place is confirmed.</p>'
        . '<p><strong>When:</strong> ' . e(event_when($event, true)) . '<br><strong>Where:</strong> ' . e($event['location']) . '</p>'
        . '<p><a class="btn" href="' . e(abs_url('event-details/' . $event['slug'] . '/')) . '">View event details</a></p>'
        . '<p>Add it to your calendar: <a href="' . e(abs_url('event-details/' . $event['slug'] . '/ics/')) . '">download the calendar file (.ics)</a>.</p>'
        . '<p>If you can no longer attend, please reply to this email so that we can offer your place to someone else.</p>'
        . '<p>Regards,<br>' . e(site_name()) . '</p>';
    send_mail($data['email'], 'Registration confirmed: ' . $event['title'], $body, setting('contact_email') ?: null);
    if (current_user_id()) {
        add_notification((int) current_user_id(), 'Event registration confirmed', 'You are registered for ' . $event['title'] . ' (' . event_when($event) . ').', $self, 'event');
    }
    flash('success', 'You are registered for ' . $event['title'] . '. A confirmation has been sent to ' . $data['email'] . '.');
    redirect($self . '#register');
}

// ---------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------
$user = current_user();
$myRegistration = null;
if ($user) {
    $myRegistration = db_row("SELECT * FROM event_registrations WHERE event_id = ? AND (user_id = ? OR email = ?) AND status <> 'cancelled' ORDER BY id LIMIT 1",
        [$event['id'], $user['id'], $user['email']]);
}
$canonical = abs_url('event-details/' . $event['slug'] . '/');
$isAwareness = $event['event_type'] === 'awareness_day';
$online = event_is_online($event);
$showMap = !$online && !$isAwareness && trim((string) $event['location']) !== '';
$typeLabel = event_type_label($event['event_type']);
$listCrumb = $isAwareness ? [get_page('awareness-calendar')['title'] ?? 'Awareness Calendar', page_url('awareness-calendar')] : [get_page('events-page')['title'] ?? 'Events', page_url('events-page')];
$program = content_program($event['program_id'] ? (int) $event['program_id'] : null);
$involvePage = get_page('awareness-campaign') ? 'awareness-campaign' : 'contact';
$similar = db_rows("SELECT * FROM events WHERE status = 'published' AND id <> ? AND event_type " . ($isAwareness ? "= 'awareness_day'" : "<> 'awareness_day'")
    . ' AND COALESCE(end_datetime, start_datetime) >= NOW() ORDER BY start_datetime LIMIT 3', [$event['id']]);

page_header($page, [
    'title' => $event['title'],
    'subtitle' => excerpt($event['summary'], 200),
    'eyebrow' => $typeLabel,
    'meta_title' => $event['title'] . ($isAwareness ? '' : ' - ' . date('d M Y', strtotime($event['start_datetime']))),
    'meta_description' => excerpt($event['summary'] ?: $event['description'], 160),
    'canonical' => $canonical,
    'og_image' => $event['image'] ?: '',
    'crumbs' => [$listCrumb],
    'noindex' => !$public,
    'buttons' => [],
    'schema' => [event_schema($event, $canonical)],
]);
?>
<section class="section event-section">
    <div class="container">
        <?php if ($preview): ?>
            <div class="alert alert-warning mb-3"><?= icon('eye') ?><div>Staff preview of a <strong><?= e($event['status']) ?></strong> event. Visitors cannot see it. <a href="<?= e(url('admin/event-edit.php?id=' . (int) $event['id'])) ?>">Edit event</a></div></div>
        <?php endif; ?>
        <?php if ($event['status'] === 'cancelled'): ?>
            <div class="alert alert-error mb-3" role="status"><?= icon('alert-circle') ?><div><strong>This event has been cancelled.</strong> We are sorry for the inconvenience. Registered participants are informed by email.</div></div>
        <?php elseif ($state['is_past'] && !$isAwareness): ?>
            <div class="alert alert-info mb-3" role="status"><?= icon('info') ?><div>This event took place on <?= e(date('d F Y', strtotime($event['start_datetime']))) ?>. See our <a href="<?= e(page_url('events-page')) ?>">upcoming events</a>.</div></div>
        <?php endif; ?>
        <div class="event-layout">
            <div class="event-main">
                <?php if ($event['image'] && is_file(ROOT_PATH . '/' . $event['image'])): [$iw, $ih] = content_image_size($event['image']); ?>
                    <figure class="article-hero"><img src="<?= e(url($event['image'])) ?>" alt="<?= e($event['title']) ?>"<?= $iw ? ' width="' . $iw . '" height="' . $ih . '"' : '' ?> fetchpriority="high"></figure>
                <?php endif; ?>
                <div class="ev-facts-strip">
                    <div><?= icon('calendar') ?><span><small>Date</small><strong><?= e($isAwareness || event_is_all_day($event) ? event_when($event, true) : date('l, d F Y', strtotime($event['start_datetime']))) ?></strong></span></div>
                    <?php if (!event_is_all_day($event)): ?>
                        <div><?= icon('clock') ?><span><small>Time</small><strong><?= e(date('h:i A', strtotime($event['start_datetime'])) . ($event['end_datetime'] ? ' – ' . (date('Y-m-d', strtotime($event['end_datetime'])) === date('Y-m-d', strtotime($event['start_datetime'])) ? date('h:i A', strtotime($event['end_datetime'])) : date('d M, h:i A', strtotime($event['end_datetime']))) : '')) ?></strong></span></div>
                    <?php endif; ?>
                    <div><?= icon($online ? 'monitor' : 'map-pin') ?><span><small><?= $online ? 'Where' : 'Location' ?></small><strong><?= e($event['location']) ?></strong></span></div>
                </div>
                <?php if ($state['open'] && !$myRegistration): ?><a class="btn btn-primary btn-block ev-jump" href="#register"><?= icon('check') ?>Register to attend</a><?php endif; ?>
                <h2><?= $isAwareness ? 'About this day' : 'About this event' ?></h2>
                <div class="prose article-prose"><?= $event['description'] ?: '<p>' . e($event['summary']) . '</p>' ?></div>
                <?php if ($isAwareness): ?>
                    <div class="aw-cta">
                        <span class="icon-circle gold"><?= icon('megaphone') ?></span>
                        <div><h3>Organise an awareness activity with us</h3>
                            <p>Schools, colleges, resident welfare associations and community groups can plan a talk, drive or campaign around this day with our volunteers.</p></div>
                        <a class="btn btn-primary" href="<?= e(page_url($involvePage)) ?>"><?= $involvePage === 'contact' ? 'Contact us' : 'Plan an activity' ?><?= icon('arrow-right') ?></a>
                    </div>
                <?php endif; ?>
                <?= content_program_box($program ? (int) $program['id'] : null, $isAwareness ? 'Related programme' : 'This event is part of our programme') ?>
                <?php if ($showMap): ?>
                    <p class="map-link"><a href="<?= e(maps_search_url($event['location'])) ?>" target="_blank" rel="noopener"><?= icon('map-pin') ?>Find “<?= e($event['location']) ?>” on Google Maps<?= icon('external') ?></a></p>
                <?php endif; ?>
                <div class="article-foot"><?= share_buttons($canonical, $event['title']) ?></div>
            </div>

            <aside class="event-aside" id="register">
                <div class="raise-box ev-box">
                    <div class="ev-box-head">
                        <?= content_date_badge((string) $event['start_datetime']) ?>
                        <div><span class="ev-chip ev-type-<?= e($event['event_type']) ?>"><?= icon(EVENT_TYPE_ICONS[$event['event_type']] ?? 'calendar') ?><?= e($typeLabel) ?></span>
                            <p class="ev-box-when"><?= e(event_when($event)) ?></p></div>
                    </div>
                    <ul class="meta-list">
                        <li><?= icon($online ? 'monitor' : 'map-pin') ?><span><?= e($event['location']) ?><?php if ($showMap): ?> · <a href="<?= e(maps_search_url($event['location'])) ?>" target="_blank" rel="noopener">Map</a><?php endif; ?></span></li>
                        <?php if ($program): ?><li><?= icon('target') ?><span><a href="<?= e(program_url($program)) ?>"><?= e($program['title']) ?></a></span></li><?php endif; ?>
                        <?php if (!$isAwareness && $state['capacity'] !== null): ?>
                            <li><?= icon('users') ?><span><?= $state['is_past'] || $event['status'] === 'cancelled' ? (int) $state['capacity'] . ' places' : ($state['seats_left'] > 0 ? '<strong>' . (int) $state['seats_left'] . '</strong> of ' . (int) $state['capacity'] . ' places left' : 'Fully booked') ?></span></li>
                        <?php endif; ?>
                    </ul>
                    <?php if (!$isAwareness && $state['capacity'] !== null && !$state['is_past'] && $event['status'] === 'published'): $pct = (int) min(100, round($state['registered'] / max(1, $state['capacity']) * 100)); ?>
                        <div class="progress seat-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>" aria-label="Places taken"><span data-progress="<?= $pct ?>" style="width:<?= $pct ?>%"></span></div>
                    <?php endif; ?>
                    <?php if ($public): ?>
                        <a class="btn btn-ghost btn-sm btn-block mt-2" href="<?= e($self . 'ics/') ?>" rel="nofollow" download><?= icon('download') ?>Add to calendar (.ics)</a>
                    <?php endif; ?>

                    <?php if ($myRegistration): ?>
                        <div class="ev-registered mt-3"><?= icon('check-circle') ?><div><strong>You are registered.</strong><span>We look forward to seeing you. Confirmation sent to <?= e($myRegistration['email']) ?>.</span></div></div>
                    <?php elseif ($state['open']): ?>
                        <h2 class="h3 mt-3">Register to attend</h2>
                        <p class="small muted">Free registration. We use your details only to manage this event.</p>
                        <form method="post" action="<?= e($self) ?>#register" data-validate novalidate>
                            <?= csrf_field() ?><?= honeypot_field() ?>
                            <div class="form-group mb-2"><label for="reg-name">Full name<span class="req">*</span></label>
                                <input id="reg-name" type="text" name="name" required minlength="2" maxlength="120" autocomplete="name" value="<?= e(old('name', $user['name'] ?? '')) ?>"><?= field_error('name') ?></div>
                            <div class="form-group mb-2"><label for="reg-email">Email<span class="req">*</span></label>
                                <input id="reg-email" type="email" name="email" required maxlength="190" autocomplete="email" value="<?= e(old('email', $user['email'] ?? '')) ?>"><?= field_error('email') ?></div>
                            <div class="form-group mb-2"><label for="reg-phone">Mobile number<span class="req">*</span></label>
                                <input id="reg-phone" type="tel" name="phone" required maxlength="15" autocomplete="tel" inputmode="tel" value="<?= e(old('phone', $user['phone'] ?? '')) ?>"><?= field_error('phone') ?></div>
                            <div class="form-group mb-2"><label for="reg-message">Message <span class="muted">(optional)</span></label>
                                <textarea id="reg-message" name="message" maxlength="500" rows="3" data-counter><?= e(old('message')) ?></textarea><?= field_error('message') ?></div>
                            <button class="btn btn-primary btn-block" type="submit"><?= icon('check') ?>Register now</button>
                            <p class="form-note"><?= icon('lock') ?><span>See our <a href="<?= e(page_url('privacy-policy')) ?>">privacy policy</a>.</span></p>
                        </form>
                    <?php elseif ($state['reason'] !== ''): ?>
                        <div class="ev-closed mt-3"><?= icon('info') ?><span><?= e($state['reason']) ?></span></div>
                    <?php endif; ?>
                    <?php if ($isAwareness): ?>
                        <p class="small muted mt-2">An awareness day observed across India and around the world - not an event organised by <?= e(site_name()) ?>.</p>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </div>
</section>
<?php if ($similar): ?>
    <section class="section section-tint">
        <div class="container">
            <?= ui_heading($isAwareness ? 'Coming up next' : 'Other upcoming events', $isAwareness ? 'Awareness calendar' : 'Events') ?>
            <div class="card-grid"><?php foreach ($similar as $i => $s) { echo event_card($s, $i); } ?></div>
        </div>
    </section>
<?php endif;
page_footer();
