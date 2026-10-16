<?php
/**
 * Event page. Canonical addresses: /events/<slug>/ and, for hackathons / innovation challenges, /hackathons/<slug>/
 * (reached through the `events` page - templates/events.php hands over - and the `hackathons` page).
 * Old /event-details/<slug>/ addresses and a wrong prefix 301 to the canonical address.
 *   <event>/ics/                 calendar file (.ics) - never contains the meeting link
 *   <event>/confirmation/        confirmation after registering (this browser session only; noindex, no-store)
 *   <event>/join/?r=<id>&t=<tok> private joining page: a valid link is remembered in the session and the visitor is sent
 *                                to <event>/join/ (no token in the address bar, analytics or referrers); a signed-in member
 *                                with an active registration can open <event>/join/ directly
 *   <event>/file/<id>/           registered-only download (same access rule), served by send_private_file()
 * Registration: programhub_handle_registration() -> programhub_register() (transaction + row lock: seat limit, deadline,
 * waiting list, one registration per email).
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$segments = route_segments();
if (!$segments) {
    // /event-details/ and /hackathons/ have no list of their own
    header('Location: ' . ($page['slug'] === 'hackathons' ? programhub_hackathons_url() : page_url('events')), true, 301);
    exit;
}
$slug = $segments[0];
$sub = $segments[1] ?? '';
$fileId = 0;
if ($sub === 'file') {
    if (count($segments) !== 3 || !preg_match('/^[1-9][0-9]{0,9}$/', $segments[2])) {
        not_found();
    }
    $fileId = (int) $segments[2];
} elseif (count($segments) > 2 || !in_array($sub, ['', 'ics', 'join', 'confirmation'], true)) {
    not_found();
}
if (!preg_match('/^[a-z0-9][a-z0-9\-]*$/', $slug)) {
    not_found();
}
$event = db_row('SELECT e.*, p.title AS program_title FROM events e LEFT JOIN programs p ON p.id = e.program_id WHERE e.slug = ?', [$slug]);
$public = $event && in_array($event['status'], ['published', 'cancelled'], true);
$preview = $event && !$public && staff_session_can('events.manage');
if (!$event || (!$public && !$preview)) {
    not_found();
}
$event['registered'] = event_registered_count((int) $event['id']);
$self = event_url($event);
$subPath = implode('/', array_slice($segments, 1));
// one address per event: old /event-details/<slug>/ or the wrong prefix -> 301 (GET/HEAD; a POST is answered on the canonical page)
$selfPath = trim(substr($self, strlen(base_path())), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (route_path() !== $selfPath . ($subPath !== '' ? '/' . $subPath : '') && in_array($method, ['GET', 'HEAD'], true)) {
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    header('Location: ' . $self . ($subPath !== '' ? $subPath . '/' : '') . ($qs !== '' ? '?' . $qs : ''), true, 301);
    exit;
}
if ($method === 'POST' && $sub !== '') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed');
}
$category = programhub_event_category($event);
$eventsPage = get_page('events');
// Breadcrumb: Home > Programs & Events > Category > Event (the category page lives under /programs/, CRITIC C16);
// events without a category: Home > Events > Event
$crumbsBase = $category && get_page('programs')
    ? [['Programs & Events', page_url('programs')], [$category['title'], program_category_url($category)]]
    : array_merge([[$eventsPage['title'] ?? 'Events', page_url('events')]], $category ? [[$category['title'], program_category_url($category)]] : []);
$privateHeaders = function (): void {
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
};

// ---------------------------------------------------------------------
// Calendar file
// ---------------------------------------------------------------------
if ($sub === 'ics') {
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

// ---------------------------------------------------------------------
// Registered-only download
// ---------------------------------------------------------------------
if ($sub === 'file') {
    $res = db_row("SELECT * FROM event_resources WHERE id = ? AND event_id = ? AND status = 'published'", [$fileId, (int) $event['id']]);
    if (!$res || trim((string) $res['file_path']) === '') {
        not_found();
    }
    if ($res['visibility'] === 'public') {
        if (strpos((string) $res['file_path'], 'uploads/') !== 0 || strpos((string) $res['file_path'], 'uploads/documents/') === 0 || strpos((string) $res['file_path'], '..') !== false) {
            not_found();
        }
        header('Location: ' . url((string) $res['file_path']), true, 302);
        exit;
    }
    $privateHeaders();
    $access = programhub_event_access($event);
    $allowed = staff_session_can('events.manage')
        || ($access && in_array($access['reg']['status'], EVENT_ACCESS_STATUSES, true) && $event['status'] === 'published');
    if (!$allowed) {
        log_activity('form_event_join_fail', 'Refused registered-only file #' . $fileId . ' of event #' . $event['id']);
        http_response_code(403);
        page_header($page, ['title' => 'For registered participants', 'noindex' => true, 'canonical' => event_abs_url($event), 'crumbs' => array_merge($crumbsBase, [[$event['title'], $self]]), 'buttons' => [], 'image' => '']);
        echo '<section class="section"><div class="container narrow"><div class="alert alert-info" role="status">' . icon('lock') . '<div><strong>This file is only for registered participants.</strong> '
            . 'Open it from your private joining page (the link is in your confirmation email)' . (is_logged_in() ? '' : ', or <a href="' . e(page_url('login', ['next' => $self . 'join/'])) . '">sign in</a> if you registered with your account') . '.</div></div>'
            . '<p class="mt-3"><a class="link-arrow" href="' . e($self) . '">Back to the event page' . icon('arrow-right') . '</a></p></div></section>';
        page_footer();
        exit;
    }
    send_private_file((string) $res['file_path'], slugify((string) $res['title']) ?: 'event-file');
}

// ---------------------------------------------------------------------
// Confirmation (right after registering, in the same browser session)
// ---------------------------------------------------------------------
if ($sub === 'confirmation') {
    $privateHeaders();
    $c = $_SESSION['ph_confirm'] ?? null;
    $reg = is_array($c) && (int) ($c['event_id'] ?? 0) === (int) $event['id'] && time() - (int) ($c['at'] ?? 0) < 7200
        ? db_row('SELECT * FROM event_registrations WHERE id = ? AND event_id = ?', [(int) $c['reg_id'], (int) $event['id']]) : null;
    if (!$reg) {
        redirect($self);
    }
    page_header($page, ['title' => $reg['status'] === 'waitlisted' ? 'Waiting list: ' . $event['title'] : 'Registration confirmed', 'subtitle' => $event['title'],
        'eyebrow' => event_type_label($event['event_type']), 'noindex' => true, 'canonical' => event_abs_url($event),
        'crumbs' => array_merge($crumbsBase, [[$event['title'], $self]]), 'buttons' => [], 'image' => '']);
    echo '<section class="section ph-confirm-section"><div class="container">' . programhub_confirmation_html($event, $reg, (string) ($c['token'] ?? ''), !empty($c['sent'])) . '</div></section>';
    page_footer();
    exit;
}

// ---------------------------------------------------------------------
// Private joining page (meeting link only for registered participants; never cached, indexed or referred)
// ---------------------------------------------------------------------
if ($sub === 'join') {
    $privateHeaders();
    $invalid = false;
    if (isset($_GET['t']) || isset($_GET['r'])) {
        $reg = null;
        if (!form_rate_limited('event_join_fail', 20)) {
            $reg = programhub_registration_by_token((int) $event['id'], (int) get_str('r'), get_str('t'));
        }
        if ($reg) {
            programhub_remember_access($event, $reg);
            header('Location: ' . $self . 'join/', true, 303);
            exit;
        }
        log_activity('form_event_join_fail', 'Invalid joining link for event #' . $event['id']);
        $invalid = true;
    }
    $access = $invalid ? null : programhub_event_access($event);
    page_header($page, ['title' => 'Your joining details', 'subtitle' => $event['title'], 'eyebrow' => event_type_label($event['event_type']), 'noindex' => true,
        'canonical' => event_abs_url($event), 'crumbs' => array_merge($crumbsBase, [[$event['title'], $self]]), 'buttons' => [], 'image' => '']);
    $mode = event_mode($event);
    echo '<section class="section"><div class="container narrow" data-no-track>';
    if (!$access) {
        echo '<div class="alert alert-error" role="alert">' . icon('alert-circle') . '<div><strong>' . ($invalid ? 'This joining link is not valid.' : 'Your joining details are not available here.') . '</strong> '
            . 'Please use the latest link from your registration email - a link stops working when we send you a new one.'
            . (is_logged_in() ? '' : ' If you registered while signed in, <a href="' . e(page_url('login', ['next' => $self . 'join/'])) . '">sign in</a> to see your joining details.') . '</div></div>'
            . '<p class="mt-3"><a class="link-arrow" href="' . e($self) . '">Back to the event page' . icon('arrow-right') . '</a> &nbsp; <a class="link-arrow" href="' . e(page_url('contact')) . '">Contact us' . icon('arrow-right') . '</a></p>';
    } else {
        $reg = $access['reg'];
        $active = in_array($reg['status'], EVENT_ACCESS_STATUSES, true) && $event['status'] === 'published';
        echo '<div class="ph-join"><div class="ph-confirm-event">' . content_date_badge((string) $event['start_datetime']) . '<div><h2 class="h3"><a href="' . e($self) . '">' . e($event['title']) . '</a></h2>'
            . '<p>' . icon('clock') . '<span>' . e(event_when($event, true)) . '</span></p>'
            . '<p>' . icon($mode === 'online' ? 'monitor' : 'map-pin') . '<span>' . e(programhub_event_where($event, false)) . '</span></p></div></div>'
            . '<p class="ph-join-who">Hello ' . e($reg['name']) . ' · ' . programhub_reg_badge((string) $reg['status']) . ' · Reference <strong>' . e(programhub_registration_ref($reg)) . '</strong></p>';
        if ($event['status'] === 'cancelled') {
            echo '<div class="alert alert-error" role="status">' . icon('alert-circle') . '<div>This event has been cancelled.' . (trim((string) ($event['cancel_reason'] ?? '')) !== '' ? ' ' . e(trim((string) $event['cancel_reason'])) : '') . ' We are sorry for the inconvenience.</div></div>';
        } elseif ($reg['status'] === 'waitlisted') {
            echo '<div class="alert alert-info" role="status">' . icon('info') . '<div>You are on the waiting list. If a place becomes available we will email you, and the joining details will appear here.</div></div>';
        } elseif (!$active) {
            echo '<div class="alert alert-info" role="status">' . icon('info') . '<div>This registration is not active. If you think this is a mistake, please contact us.</div></div>';
        } else {
            if ($mode !== 'in_person') {
                echo '<div class="ph-join-online">';
                if (programhub_can_access_meeting($event, $reg)) {
                    echo '<h3>' . icon('monitor') . 'Join online</h3><p><a class="btn btn-primary btn-lg" href="' . e(safe_link((string) $event['meeting_url'])) . '" target="_blank" rel="noopener noreferrer">' . icon('external') . 'Join the online session</a></p>';
                    if (trim((string) $event['meeting_info']) !== '') {
                        echo '<p class="ph-join-info">' . e_nl((string) $event['meeting_info']) . '</p>';
                    }
                    echo '<p class="small muted">' . icon('lock') . ' This link is only for you. Please do not share it or post it publicly.</p>';
                } elseif (event_end_ts($event) <= time() - 86400) {
                    echo '<p class="muted">This session has ended.</p>';
                } else {
                    echo '<h3>' . icon('monitor') . 'Join online</h3><p class="muted">The joining link will appear here, and will be emailed to you, before the session.</p>';
                }
                echo '</div>';
            }
            if ($mode !== 'online') {
                $map = programhub_map_url($event);
                echo '<div class="ph-join-venue"><h3>' . icon('map-pin') . 'Venue</h3><p><strong>' . e(trim((string) $event['location']) ?: 'Venue to be announced') . '</strong>'
                    . (programhub_event_address($event) !== '' ? '<br>' . e(programhub_event_address($event)) : '') . '</p>'
                    . ($map !== '' ? '<p><a href="' . e($map) . '" target="_blank" rel="noopener noreferrer">' . icon('map-pin') . 'Open in Google Maps</a></p>' : '') . '</div>';
            }
            $files = programhub_resources_html($event, $access);
            if ($files !== '') {
                echo '<h3>' . icon('download') . 'Materials</h3>' . $files;
            }
        }
        echo '<div class="btn-row ph-join-actions"><a class="btn btn-outline btn-sm" href="' . e($self . 'ics/') . '" rel="nofollow" download>' . icon('download') . 'Add to calendar (.ics)</a>'
            . '<a class="btn btn-ghost btn-sm" href="' . e($self) . '">' . icon('arrow-right') . 'Event page</a>'
            . (is_logged_in() ? '<a class="btn btn-ghost btn-sm" href="' . e(member_url('events')) . '">' . icon('calendar') . 'My events</a>' : '') . '</div>'
            . '<p class="small muted">' . ($access['via'] === 'account' ? 'Shown because you are signed in with the account used for this registration.' : 'Opened from your private link on this device. Keep the link in your email to yourself.') . '</p></div>';
    }
    echo '</div></section>';
    page_footer();
    exit;
}

$state = event_state($event);

// ---------------------------------------------------------------------
// Registration (POST, before any output)
// ---------------------------------------------------------------------
if ($method === 'POST') {
    csrf_verify($self);
    if (!$public || $event['status'] !== 'published') {
        flash('error', 'Registration is not open for this event.');
        redirect($self);
    }
    programhub_handle_registration($event, 'event_page', $self);
}

// ---------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------
$canonical = event_abs_url($event);
$status = programhub_event_status($event, $state);
$isAwareness = $event['event_type'] === 'awareness_day';
$mode = event_mode($event);
$typeLabel = event_type_label($event['event_type']);
$access = $isAwareness ? null : programhub_event_access($event);
// the visitor's own registration: signed-in owner, or a private link opened in this browser session
$myRegistration = $access && $access['reg']['status'] !== 'cancelled' ? $access['reg'] : null;
$program = content_program($event['program_id'] ? (int) $event['program_id'] : null);
$involvePage = get_page('awareness-campaign') ? 'awareness-campaign' : 'contact';
$speakers = $isAwareness ? [] : programhub_event_speakers((int) $event['id']);
$partners = $isAwareness ? [] : programhub_event_partners((int) $event['id']);
$agenda = $isAwareness ? '' : programhub_agenda_html($event);
$photos = $isAwareness ? [] : programhub_event_gallery((int) $event['id'], 24);
$report = $isAwareness || !$state['is_past'] ? '' : programhub_report_html($event);
$materials = $isAwareness ? '' : programhub_resources_html($event, $access, ['material', 'promo', 'slides', 'rules', 'other']);
$reportFiles = $isAwareness ? '' : programhub_resources_html($event, $access, ['report']);
$faqs = $isAwareness ? '' : programhub_event_faqs_html($event);
$guidelines = trim(strip_tags((string) ($event['guidelines'] ?? ''))) !== '' ? programhub_rich_images(clean_html((string) $event['guidelines'])) : '';
$map = $isAwareness ? '' : programhub_map_url($event);
$interestUrl = $category && (int) $category['interest_enabled'] === 1 ? program_category_url($category) . '#register' : '';
if ($isAwareness) {
    $related = db_rows("SELECT * FROM events WHERE status = 'published' AND id <> ? AND event_type = 'awareness_day' AND COALESCE(end_datetime, start_datetime) >= NOW() ORDER BY start_datetime LIMIT 3", [$event['id']]);
} else {
    $related = programhub_related_events($event, 3);
}
$crumbs = $isAwareness ? [[get_page('awareness-calendar')['title'] ?? 'Awareness Calendar', page_url('awareness-calendar')]] : $crumbsBase;
$bannerButtons = [];
if (!$isAwareness && $state['can_register'] && !$myRegistration) {
    $bannerButtons[] = ['label' => $state['open'] ? 'Register now' : 'Join the waiting list', 'url' => '#register', 'style' => 'donate', 'icon' => 'check'];
}
if ($public && !$state['is_past'] && $event['status'] === 'published') {
    $bannerButtons[] = ['label' => 'Add to calendar', 'url' => $self . 'ics/', 'style' => 'light', 'icon' => 'calendar'];
}
programhub_js();

page_header($page, [
    'title' => $event['title'],
    'subtitle' => excerpt($event['summary'], 200),
    'eyebrow' => $typeLabel . ($category && !$isAwareness ? ' · ' . $category['title'] : ''),
    'meta_title' => ($event['meta_title'] ?? '') ?: $event['title'] . ($isAwareness ? '' : ' - ' . date('d M Y', strtotime($event['start_datetime']))),
    'meta_description' => ($event['meta_description'] ?? '') ?: excerpt($event['summary'] ?: $event['description'], 160),
    'canonical' => $canonical,
    'og_image' => $event['image'] ?: '',
    'image' => $category && !$isAwareness ? program_category_art($category) : ($page['banner_image'] ?? ''),
    'crumbs' => $crumbs,
    'noindex' => !$public,
    'buttons' => $bannerButtons,
    'schema' => [programhub_event_schema(event_schema($event, $canonical), $event, $state)],
    'body_class' => 'ph-event-page',
]);

$heading = function (string $id, string $title, string $iconName): string {
    return '<h2 class="ph-block-head" id="' . e($id) . '-title">' . icon($iconName) . e($title) . '</h2>';
};
?>
<section class="section event-section ph-event">
    <div class="container">
        <?php if ($preview): ?>
            <div class="alert alert-warning mb-3"><?= icon('eye') ?><div>Staff preview of a <strong><?= e($event['status']) ?></strong> event. Visitors cannot see it. <a href="<?= e(url('admin/event-edit.php?id=' . (int) $event['id'])) ?>">Edit event</a></div></div>
        <?php endif; ?>
        <?php if ($event['status'] === 'cancelled'): ?>
            <div class="alert alert-error mb-3" role="status"><?= icon('alert-circle') ?><div><strong>This event has been cancelled.</strong><?= trim((string) ($event['cancel_reason'] ?? '')) !== '' ? ' ' . e(trim((string) $event['cancel_reason'])) : '' ?> We are sorry for the inconvenience. Registered participants are informed by email.</div></div>
        <?php elseif ($state['is_past'] && !$isAwareness): ?>
            <div class="alert alert-info mb-3" role="status"><?= icon('info') ?><div>This event took place on <?= e(date('d F Y', strtotime($event['start_datetime']))) ?>.<?= $related ? ' See our <a href="#related">upcoming events</a>.' : ' See our <a href="' . e(events_list_url()) . '">upcoming events</a>.' ?></div></div>
        <?php endif; ?>
        <?php if ($isAwareness && function_exists('seo_awareness_notice') && ($awNotice = seo_awareness_notice($event)) !== ''): ?>
            <div class="mb-3"><?= $awNotice ?></div>
        <?php endif; ?>
        <div class="event-layout ph-event-layout">
            <div class="event-main">
                <?php if ($event['image'] && is_file(ROOT_PATH . '/' . $event['image'])): [$iw, $ih] = content_image_size($event['image']); ?>
                    <figure class="article-hero ph-poster"><?= program_picture($event['image'], (string) $event['title'], ['fetchpriority' => 'high', 'sizes' => '(max-width: 900px) 100vw, 800px'] + ($iw ? ['width' => $iw, 'height' => $ih] : []),
                        '<img src="' . e(url($event['image'])) . '" alt="' . e($event['title']) . '"' . ($iw ? ' width="' . $iw . '" height="' . $ih . '"' : '') . ' fetchpriority="high">') ?></figure>
                <?php endif; ?>
                <div class="ev-facts-strip">
                    <div><?= icon('calendar') ?><span><small>Date</small><strong><?= e($isAwareness || event_is_all_day($event) ? event_when($event, true) : date('l, d F Y', strtotime($event['start_datetime']))) ?></strong></span></div>
                    <?php if (!event_is_all_day($event)): ?>
                        <div><?= icon('clock') ?><span><small>Time</small><strong><?= e(date('h:i A', strtotime($event['start_datetime'])) . ($event['end_datetime'] ? ' – ' . (date('Y-m-d', strtotime($event['end_datetime'])) === date('Y-m-d', strtotime($event['start_datetime'])) ? date('h:i A', strtotime($event['end_datetime'])) : date('d M, h:i A', strtotime($event['end_datetime']))) : '')) ?></strong></span></div>
                    <?php endif; ?>
                    <?php if (!$isAwareness): ?>
                        <div><?= icon($mode === 'online' ? 'monitor' : 'map-pin') ?><span><small><?= $mode === 'online' ? 'Where' : 'Venue' ?></small><strong><?= e(programhub_event_where($event)) ?></strong></span></div>
                    <?php endif; ?>
                </div>

                <?php if ($report !== '' || ($state['is_past'] && $reportFiles !== '')): ?>
                    <section class="ph-block ph-report-block" aria-labelledby="report-title">
                        <?= $heading('report', 'Report & outcomes', 'bar-chart') ?>
                        <?= $report ?><?= $reportFiles ?>
                    </section>
                <?php endif; ?>

                <section class="ph-block" aria-labelledby="about-title">
                    <?= $heading('about', $isAwareness ? 'About this day' : 'About this event', 'info') ?>
                    <div class="prose article-prose"><?= $event['description'] ? programhub_rich_images((string) $event['description']) : '<p>' . e($event['summary']) . '</p>' ?></div>
                </section>

                <?php if (!$isAwareness): ?>
                    <section class="ph-block" aria-labelledby="details-title">
                        <?= $heading('details', 'Key details', 'list') ?>
                        <?= programhub_event_glance($event, $state) ?>
                    </section>
                <?php endif; ?>

                <?php if ($agenda !== ''): ?>
                    <section class="ph-block" id="agenda" aria-labelledby="agenda-title"><?= $heading('agenda', 'Agenda', 'clock') ?><?= $agenda ?></section>
                <?php endif; ?>

                <?php if ($speakers): ?>
                    <section class="ph-block" id="speakers" aria-labelledby="speakers-title"><?= $heading('speakers', count(array_unique(array_column($speakers, 'role'))) === 1 && $speakers[0]['role'] === 'trainer' ? 'Trainers' : 'Speakers & trainers', 'user') ?><?= programhub_speakers_html($speakers) ?></section>
                <?php endif; ?>

                <?php if ($partners): ?>
                    <section class="ph-block" id="partners" aria-labelledby="partners-title"><?= $heading('partners', 'Partners', 'users') ?><?= programhub_partners_html($partners) ?></section>
                <?php endif; ?>

                <?php if ($guidelines !== '' || $materials !== ''): ?>
                    <section class="ph-block" id="guidelines" aria-labelledby="guidelines-title">
                        <?= $heading('guidelines', $guidelines !== '' ? 'Rules, guidelines & materials' : 'Downloads & materials', 'file-text') ?>
                        <?php if ($guidelines !== ''): ?><div class="prose ph-guidelines"><?= $guidelines ?></div><?php endif; ?>
                        <?= $materials ?>
                    </section>
                <?php endif; ?>

                <?php if ($photos): $grid = programhub_photo_grid($photos); if ($grid !== ''): ?>
                    <section class="ph-block" id="photos" aria-labelledby="photos-title"><?= $heading('photos', 'Photos', 'image') ?><?= $grid ?>
                        <p class="small muted">Photos are published only with the consent of the people in them.</p></section>
                <?php endif; endif; ?>

                <?php if ($isAwareness): ?>
                    <div class="aw-cta">
                        <span class="icon-circle gold"><?= icon('megaphone') ?></span>
                        <div><h3>Organise an awareness activity with us</h3>
                            <p>Schools, colleges, resident welfare associations and community groups can plan a talk, drive or campaign around this day with our volunteers.</p></div>
                        <a class="btn btn-primary" href="<?= e(page_url($involvePage)) ?>"><?= $involvePage === 'contact' ? 'Contact us' : 'Plan an activity' ?><?= icon('arrow-right') ?></a>
                    </div>
                <?php endif; ?>
                <?= content_program_box($program ? (int) $program['id'] : null, $isAwareness ? 'Related programme' : 'This event is part of our programme') ?>

                <?php if (!$isAwareness): ?>
                    <section class="ph-block ph-register" id="register" aria-labelledby="register-title">
                        <?php if ($myRegistration): ?>
                            <?= $heading('register', 'Your registration', 'check-circle') ?>
                            <div class="ph-done" role="status"><?= icon($myRegistration['status'] === 'waitlisted' ? 'clock' : 'check-circle') ?><div>
                                <strong><?= $myRegistration['status'] === 'waitlisted' ? 'You are on the waiting list' : 'You are registered' ?></strong>
                                <p>Reference <strong><?= e(programhub_registration_ref($myRegistration)) ?></strong> · confirmation sent to <?= e(programhub_mask_email((string) $myRegistration['email'])) ?>.</p>
                                <div class="btn-row">
                                    <?php if ($mode !== 'in_person' && in_array($myRegistration['status'], EVENT_ACCESS_STATUSES, true) && $event['status'] === 'published'): ?>
                                        <a class="btn btn-blue btn-sm" href="<?= e($self . 'join/') ?>" rel="nofollow"><?= icon('monitor') ?>Joining details</a>
                                    <?php endif; ?>
                                    <?php if (is_logged_in()): ?><a class="btn btn-ghost btn-sm" href="<?= e(member_url('events')) ?>"><?= icon('calendar') ?>My events</a><?php endif; ?>
                                </div></div></div>
                        <?php elseif ($state['can_register']): ?>
                            <?= $heading('register', $state['open'] ? 'Register to attend' : 'Join the waiting list', 'edit') ?>
                            <p class="ph-register-intro"><?= $state['open']
                                ? 'Fill in the form to book your place.' . ($state['seats_left'] !== null ? ' <strong>' . (int) $state['seats_left'] . ' of ' . (int) $state['capacity'] . '</strong> places are left.' : '')
                                : 'All places are taken. Join the waiting list and we will email you, in the order of registration, if a place becomes available.' ?>
                                <?= !empty($state['deadline']) ? ' Registration closes on <strong>' . e(date('d M Y, h:i A', (int) $state['deadline'])) . '</strong>.' : '' ?></p>
                            <div class="form-card ph-form-card"><?= programhub_registration_form([$event], ['action' => $self . '#register']) ?></div>
                        <?php else: ?>
                            <?= $heading('register', 'Registration', 'edit') ?>
                            <div class="ph-state ph-state-<?= e(str_replace('_', '-', $status['key'])) ?>">
                                <?= icon($status['key'] === 'cancelled' ? 'x' : ($status['key'] === 'completed' ? 'check-circle' : ($status['key'] === 'upcoming' ? 'calendar' : 'lock'))) ?>
                                <div><strong><?= e([
                                        'cancelled' => 'This event has been cancelled', 'completed' => 'This event has taken place', 'ongoing' => 'This event is under way',
                                        'full' => 'All places have been taken', 'registration_closed' => 'Registration is closed', 'draft' => 'Not published yet',
                                        'scheduled' => 'Not published yet', 'archived' => 'Archived event',
                                    ][$status['key']] ?? ((int) $event['registration_enabled'] === 1 ? 'Registration is not open yet' : 'No registration needed')) ?></strong>
                                    <p><?= e([
                                        'completed' => 'Registration is closed.' . ($interestUrl !== '' ? ' Leave your details on the programme page to hear about future sessions.' : ''),
                                        'cancelled' => 'Registration is closed. We are sorry for the inconvenience.',
                                    ][$status['key']] ?? ($state['reason'] !== '' ? $state['reason'] : 'Registration is not required for this event.')) ?></p>
                                    <div class="btn-row">
                                        <?php if ($interestUrl !== '' && in_array($status['key'], ['full', 'registration_closed', 'completed', 'cancelled', 'ongoing'], true)): ?>
                                            <a class="btn btn-primary btn-sm" href="<?= e($interestUrl) ?>"><?= icon('bell') ?>Tell me about future sessions</a>
                                        <?php endif; ?>
                                        <a class="btn btn-ghost btn-sm" href="<?= e(events_list_url()) ?>"><?= icon('calendar') ?>Other upcoming events</a>
                                    </div></div>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php if ($faqs !== ''): ?>
                    <section class="ph-block" id="faqs" aria-labelledby="faqs-title"><?= $heading('faqs', 'Questions about this event', 'help-circle') ?><?= $faqs ?></section>
                <?php endif; ?>

                <div class="article-foot"><?= share_buttons($canonical, $event['title']) ?></div>
            </div>

            <aside class="event-aside ph-aside" aria-label="Event summary">
                <div class="raise-box ev-box ph-ticket">
                    <div class="ev-box-head">
                        <?= content_date_badge((string) $event['start_datetime']) ?>
                        <div><div class="ph-chips"><span class="ev-chip ev-type-<?= e($event['event_type']) ?>"><?= icon(EVENT_TYPE_ICONS[$event['event_type']] ?? 'calendar') ?><?= e($typeLabel) ?></span>
                            <?= $isAwareness ? '' : programhub_status_badge($event, $state) ?></div>
                            <p class="ev-box-when"><?= e(event_when($event)) ?></p></div>
                    </div>
                    <ul class="meta-list">
                        <?php if (!$isAwareness): ?>
                            <li><?= icon($mode === 'online' ? 'monitor' : 'map-pin') ?><span><?= e(programhub_event_where($event, false)) ?><?php if ($map !== ''): ?> · <a href="<?= e($map) ?>" target="_blank" rel="noopener noreferrer">Map</a><?php endif; ?></span></li>
                            <li><?= icon('rupee') ?><span><?= (float) ($event['fee_amount'] ?? 0) > 0 ? e(money((float) $event['fee_amount'])) . ' <span class="muted">(not paid on this website)</span>' : 'Free' ?></span></li>
                            <?php if (!empty($state['deadline']) && !$state['is_past'] && $event['status'] === 'published'): ?><li><?= icon('alert-circle') ?><span><?= $state['deadline_passed'] ? 'Registration closed ' : 'Register by ' ?><?= e(date('d M Y, h:i A', (int) $state['deadline'])) ?></span></li><?php endif; ?>
                            <?php if ($category): ?><li><?= icon((string) ($category['icon'] ?: 'layers')) ?><span><a href="<?= e(program_category_url($category)) ?>"><?= e($category['title']) ?></a></span></li><?php endif; ?>
                        <?php elseif ($program): ?>
                            <li><?= icon('target') ?><span><a href="<?= e(program_url($program)) ?>"><?= e($program['title']) ?></a></span></li>
                        <?php endif; ?>
                    </ul>
                    <?php if (!$isAwareness && $state['capacity'] !== null && !$state['is_past'] && $event['status'] === 'published'): $pct = (int) min(100, round($state['registered'] / max(1, $state['capacity']) * 100)); ?>
                        <div class="ph-seat-line"><span><?= $state['seats_left'] > 0 ? '<strong>' . (int) $state['seats_left'] . '</strong> of ' . (int) $state['capacity'] . ' places left' : 'Fully booked' . ($state['waitlist_open'] ? ' · waiting list open' : '') ?></span></div>
                        <div class="progress seat-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>" aria-label="Places taken"><span data-progress="<?= $pct ?>" style="width:<?= $pct ?>%"></span></div>
                    <?php endif; ?>
                    <?php if (!$isAwareness): ?>
                        <?php if ($myRegistration): ?>
                            <div class="ev-registered mt-2"><?= icon('check-circle') ?><div><strong><?= $myRegistration['status'] === 'waitlisted' ? 'On the waiting list' : 'You are registered' ?></strong><span>Reference <?= e(programhub_registration_ref($myRegistration)) ?></span></div></div>
                            <?php if ($mode !== 'in_person' && in_array($myRegistration['status'], EVENT_ACCESS_STATUSES, true) && $event['status'] === 'published'): ?>
                                <a class="btn btn-blue btn-block mt-2" href="<?= e($self . 'join/') ?>" rel="nofollow"><?= icon('monitor') ?>Joining details</a>
                            <?php endif; ?>
                        <?php elseif ($state['can_register']): ?>
                            <a class="btn btn-primary btn-block mt-2" href="#register"><?= icon('check') ?><?= $state['open'] ? 'Register now' : 'Join the waiting list' ?></a>
                        <?php elseif ($state['reason'] !== ''): ?>
                            <div class="ev-closed mt-2"><?= icon('info') ?><span><?= e($state['reason']) ?></span></div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($public && $event['status'] === 'published' && !$state['is_past']): ?>
                        <div class="ph-cal-row">
                            <a class="btn btn-ghost btn-sm" href="<?= e($self . 'ics/') ?>" rel="nofollow" download><?= icon('download') ?>.ics file</a>
                            <a class="btn btn-ghost btn-sm" href="<?= e(programhub_google_calendar_url($event)) ?>" target="_blank" rel="noopener noreferrer"><?= icon('calendar') ?>Google Calendar</a>
                        </div>
                    <?php endif; ?>
                    <?php if ($isAwareness): ?>
                        <p class="small muted mt-2">A national or international awareness day - not an event organised by <?= e(site_name()) ?>.</p>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </div>
</section>
<?php if ($related): ?>
    <section class="section section-tint" id="related">
        <div class="container">
            <?= ui_heading($isAwareness ? 'Coming up next' : 'Other upcoming events', $isAwareness ? 'Awareness calendar' : 'Events') ?>
            <div class="card-grid"><?php foreach ($related as $i => $s) { echo $isAwareness ? event_card($s, $i) : programhub_event_card($s, $i); } ?></div>
        </div>
    </section>
<?php endif;
page_footer();
