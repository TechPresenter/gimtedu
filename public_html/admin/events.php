<?php
/**
 * Events & calendar: summary, list of events (workshops, seminars, sessions, hackathons ...) and awareness days with
 * filters (when / category / type / mode / status), quick actions (duplicate, archive / restore, delete) and the
 * Settings tab (who receives new-registration alerts). Registrations: event-registrations.php.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('events.manage');

$canPeople = admin_can('events.registrations');
$tab = (string) ($_GET['tab'] ?? 'events');
if (!in_array($tab, ['events', 'awareness', 'settings'], true) || ($tab === 'settings' && !$canPeople)) {
    $tab = 'events';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('events.php');
    $action = (string) ($_POST['action'] ?? '');
    $back = admin_back('events.php');

    if ($action === 'settings') {
        require_permission('events.registrations');
        [$data, $errors] = validate($_POST, ['events_alert_email' => 'email|max:190'], ['events_alert_email' => 'Alert email']);
        if ($errors) {
            back_with_errors($errors, 'admin/events.php?tab=settings', $_POST);
        }
        $alerts = !empty($_POST['events_admin_alerts']) ? '1' : '0';
        save_setting('events_admin_alerts', $alerts);
        save_setting('events_alert_email', $data['events_alert_email']);
        admin_log('settings_updated', 'Event registration alerts ' . ($alerts === '1' ? 'on' : 'off') . ', recipient: ' . ($data['events_alert_email'] !== '' ? $data['events_alert_email'] : 'default admin address'));
        flash('success', 'Registration alert settings saved.');
        admin_redirect('events.php?tab=settings');
    }

    $id = (int) ($_POST['id'] ?? 0);
    $event = $id ? db_row('SELECT * FROM events WHERE id = ?', [$id]) : null;
    if (!$event) {
        flash('error', 'Event not found.');
        admin_redirect($back);
    }
    $label = ($event['event_type'] === 'awareness_day' ? 'Awareness day' : 'Event') . ' #' . $id . ' "' . $event['title'] . '"';

    if ($action === 'delete') {
        $regs = programhub_admin_delete_event($event);
        admin_log('event_deleted', $label . ' deleted' . ($regs ? ' with ' . $regs . ' registrations' : ''));
        flash('success', 'The event "' . $event['title'] . '" was deleted.');
    } elseif ($action === 'duplicate') {
        $newId = programhub_admin_duplicate_event($id);
        admin_log('event_duplicated', $label . ' duplicated as draft #' . $newId);
        flash('success', 'A draft copy was created. Check the title, address and dates, then publish it.');
        admin_redirect('event-edit.php?id=' . $newId);
    } elseif ($action === 'archive' && $event['status'] !== 'archived') {
        db_update('events', ['status' => 'archived', 'publish_at' => null], 'id = :id', ['id' => $id]);
        programhub_refresh_publish_schedule();
        admin_log('event_archived', $label . ' archived (was ' . $event['status'] . ')');
        flash('success', '"' . $event['title'] . '" is archived and hidden from visitors. Registrations and reports are kept.');
    } elseif ($action === 'restore' && $event['status'] === 'archived') {
        db_update('events', ['status' => 'draft'], 'id = :id', ['id' => $id]);
        admin_log('event_restored', $label . ' restored from the archive as a draft');
        flash('success', '"' . $event['title'] . '" was restored as a draft. Publish it from the editor when ready.');
    }
    admin_redirect($back);
}

// ---------------------------------------------------------------------
// Settings tab data
// ---------------------------------------------------------------------
if ($tab === 'settings') {
    $catEmails = db_rows("SELECT id, title, notify_email FROM program_categories WHERE notify_email IS NOT NULL AND notify_email <> '' ORDER BY sort_order, title");
}

// ---------------------------------------------------------------------
// Lists
// ---------------------------------------------------------------------
$status = in_array($_GET['status'] ?? '', EVENT_STATUSES, true) ? (string) $_GET['status'] : '';
$when = in_array($_GET['when'] ?? '', ['upcoming', 'past', 'all'], true) ? (string) $_GET['when'] : 'upcoming';
$type = (string) ($_GET['type'] ?? '');
if (!isset(EVENT_TYPES[$type]) || $type === 'awareness_day') {
    $type = '';
}
$categoryId = (int) ($_GET['category'] ?? 0);
$mode = isset(EVENT_MODES[$_GET['mode'] ?? '']) ? (string) $_GET['mode'] : '';
$q = trim(mb_substr(clean_string($_GET['q'] ?? ''), 0, 100));
$rows = [];
$p = ['total' => 0];
if ($tab !== 'settings') {
    $where = 'WHERE ' . ($tab === 'awareness' ? "e.event_type = 'awareness_day'" : "e.event_type <> 'awareness_day'");
    $params = [];
    if ($tab === 'events') {
        if ($type !== '') {
            $where .= ' AND e.event_type = ?';
            $params[] = $type;
        }
        if ($categoryId > 0) {
            $where .= ' AND e.category_id = ?';
            $params[] = $categoryId;
        } elseif ($categoryId === -1) {
            $where .= ' AND e.category_id IS NULL';
        }
        if ($mode !== '') {
            $where .= ' AND e.mode = ?';
            $params[] = $mode;
        }
    }
    if ($status !== '') {
        $where .= ' AND e.status = ?';
        $params[] = $status;
    }
    if ($when === 'upcoming') {
        $where .= ' AND COALESCE(e.end_datetime, e.start_datetime) >= NOW()';
    } elseif ($when === 'past') {
        $where .= ' AND COALESCE(e.end_datetime, e.start_datetime) < NOW()';
    }
    $where .= search_where(['e.title', 'e.slug', 'e.location'], $q, $params);
    $p = paginate((int) db_value("SELECT COUNT(*) FROM events e $where", $params), 25);
    $rows = db_rows(programhub_admin_event_select_sql() . " $where ORDER BY e.start_datetime " . ($when === 'past' ? 'DESC' : 'ASC') . ", e.id LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
}

// Summary (real data only)
$seat = programhub_seat_status_sql();
$sum = db_row("SELECT SUM(event_type <> 'awareness_day' AND status = 'published' AND COALESCE(end_datetime, start_datetime) >= NOW()) AS upcoming,
                      SUM(event_type <> 'awareness_day' AND status = 'draft') AS drafts,
                      SUM(event_type <> 'awareness_day' AND status = 'scheduled') AS scheduled,
                      SUM(event_type = 'awareness_day' AND status = 'published' AND COALESCE(end_datetime, start_datetime) >= NOW()) AS aw
               FROM events");
$openNow = count(programhub_events(['when' => 'open', 'limit' => 100]));
$regs = db_row("SELECT SUM(r.status IN ($seat) AND COALESCE(e.end_datetime, e.start_datetime) >= NOW()) AS upcoming,
                       SUM(r.status = 'waitlisted' AND COALESCE(e.end_datetime, e.start_datetime) >= NOW()) AS waitlisted,
                       SUM(r.status IN ($seat)) AS total, SUM(r.status = 'attended') AS attended
                FROM event_registrations r JOIN events e ON e.id = r.event_id");
$cats = program_category_options();
$tabUrl = function (string $t): string {
    return admin_url('events.php' . ($t !== 'events' ? '?tab=' . $t : ''));
};
$filtered = $q !== '' || $status !== '' || $type !== '' || $when !== 'upcoming' || $categoryId !== 0 || $mode !== '';

admin_page_start('Events & Calendar', array_values(array_filter([
    ['label' => 'New event', 'url' => admin_url('event-edit.php'), 'icon' => 'plus'],
    ['label' => 'New awareness day', 'url' => admin_url('event-edit.php?type=awareness_day'), 'icon' => 'flag', 'style' => 'ghost'],
    $canPeople ? ['label' => 'All registrations', 'url' => admin_url('event-registrations.php'), 'icon' => 'users', 'style' => 'ghost'] : null,
])));
?>
<div class="ph-admin">
<div class="stat-cards">
    <a class="stat-card" href="<?= e(admin_url('events.php?status=published')) ?>"><span class="icon-circle"><?= icon('calendar') ?></span><span><strong><?= (int) $sum['upcoming'] ?></strong><small>Upcoming published events</small></span></a>
    <div class="stat-card"><span class="icon-circle"><?= icon('check-circle') ?></span><span><strong><?= $openNow ?></strong><small>Open for registration now</small></span></div>
    <?php if ($canPeople): ?>
        <a class="stat-card" href="<?= e(admin_url('event-registrations.php?when=upcoming')) ?>"><span class="icon-circle blue"><?= icon('users') ?></span><span><strong><?= (int) $regs['upcoming'] ?><?= (int) $regs['waitlisted'] ? ' <small>+ ' . (int) $regs['waitlisted'] . ' waiting</small>' : '' ?></strong><small>Registrations for upcoming events</small></span></a>
        <a class="stat-card" href="<?= e(admin_url('event-registrations.php')) ?>"><span class="icon-circle blue"><?= icon('bar-chart') ?></span><span><strong><?= (int) $regs['total'] ?></strong><small>Registrations in total · <?= (int) $regs['attended'] ?> attended</small></span></a>
    <?php else: ?>
        <div class="stat-card"><span class="icon-circle blue"><?= icon('users') ?></span><span><strong><?= (int) $regs['upcoming'] ?></strong><small>Registrations for upcoming events</small></span></div>
    <?php endif; ?>
    <a class="stat-card" href="<?= e(admin_url('events.php?when=all&status=' . ((int) $sum['scheduled'] ? 'scheduled' : 'draft'))) ?>"><span class="icon-circle gold"><?= icon('edit') ?></span><span><strong><?= (int) $sum['drafts'] ?><?= (int) $sum['scheduled'] ? ' <small>+ ' . (int) $sum['scheduled'] . ' scheduled</small>' : '' ?></strong><small>Draft events</small></span></a>
    <a class="stat-card" href="<?= e($tabUrl('awareness')) ?>"><span class="icon-circle gold"><?= icon('flag') ?></span><span><strong><?= (int) $sum['aw'] ?></strong><small>Upcoming awareness days</small></span></a>
</div>

<nav class="admin-tabs" aria-label="Event lists">
    <a class="<?= $tab === 'events' ? 'active' : '' ?>" href="<?= e($tabUrl('events')) ?>"<?= $tab === 'events' ? ' aria-current="page"' : '' ?>><?= icon('calendar') ?>Events, workshops &amp; programmes</a>
    <a class="<?= $tab === 'awareness' ? 'active' : '' ?>" href="<?= e($tabUrl('awareness')) ?>"<?= $tab === 'awareness' ? ' aria-current="page"' : '' ?>><?= icon('flag') ?>Awareness days</a>
    <?php if ($canPeople): ?><a class="<?= $tab === 'settings' ? 'active' : '' ?>" href="<?= e($tabUrl('settings')) ?>"<?= $tab === 'settings' ? ' aria-current="page"' : '' ?>><?= icon('settings') ?>Settings</a><?php endif; ?>
</nav>

<?php if ($tab === 'settings'): ?>
    <div class="admin-grid-2">
        <section class="panel">
            <div class="panel-head"><h2><?= icon('bell') ?>New-registration alerts</h2></div>
            <form method="post" action="<?= e(admin_url('events.php?tab=settings')) ?>" data-validate>
                <?= csrf_field() ?><input type="hidden" name="action" value="settings">
                <label class="checkbox mb-2"><input type="checkbox" name="events_admin_alerts" value="1"<?= setting('events_admin_alerts', '1') === '1' ? ' checked' : '' ?>><span>Email staff about each new registration and waiting-list entry</span></label>
                <div class="form-group mb-2"><label for="events_alert_email">Send alerts to</label>
                    <input type="email" id="events_alert_email" name="events_alert_email" maxlength="190" value="<?= e((string) old('events_alert_email', setting('events_alert_email'))) ?>" placeholder="<?= e((string) setting('admin_notify_email', setting('contact_email'))) ?>">
                    <span class="help">Empty = the general admin notification address<?= setting('admin_notify_email', setting('contact_email')) ? ' (' . e((string) setting('admin_notify_email', setting('contact_email'))) . ')' : '' ?>. Alerts contain the participant's name and the number of places taken - never phone numbers.</span><?= field_error('events_alert_email') ?></div>
                <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save</button></div>
            </form>
        </section>
        <section class="panel">
            <div class="panel-head"><h2><?= icon('layers') ?>Per-category recipients</h2></div>
            <p class="muted small">A category's own alert address replaces the address on the left for its events and enquiries.</p>
            <?php if ($catEmails): ?>
                <ul class="ph-timeline">
                    <?php foreach ($catEmails as $c): ?><li><?= icon('mail') ?><span><?= admin_can('programs.manage') ? '<a href="' . e(admin_url('program-category-edit.php?id=' . (int) $c['id'])) . '">' . e($c['title']) . '</a>' : e($c['title']) ?>: <?= e($c['notify_email']) ?></span></li><?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="muted small mb-0">No category has its own alert address.<?= admin_can('programs.manage') ? ' Set one in <a href="' . e(admin_url('program-categories.php')) . '">Programme categories</a> (Edit &gt; Events &amp; forms).' : '' ?></p>
            <?php endif; ?>
        </section>
    </div>
<?php else: ?>
    <form class="filter-row" method="get">
        <?php if ($tab === 'awareness'): ?><input type="hidden" name="tab" value="awareness"><?php endif; ?>
        <label class="sr-only" for="f-q">Search</label>
        <input id="f-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Search title, address or location">
        <label class="sr-only" for="f-when">When</label>
        <select id="f-when" name="when"><?= options_html(['upcoming' => 'Upcoming', 'past' => 'Past', 'all' => 'All dates'], $when, true, '') ?></select>
        <?php if ($tab === 'events'): ?>
            <label class="sr-only" for="f-category">Category</label>
            <select id="f-category" name="category"><option value="">All categories</option><option value="-1"<?= $categoryId === -1 ? ' selected' : '' ?>>No category</option><?php foreach ($cats as $k => $label) { echo '<option value="' . (int) $k . '"' . ($categoryId === (int) $k ? ' selected' : '') . '>' . e($label) . '</option>'; } ?></select>
            <label class="sr-only" for="f-type">Type</label>
            <select id="f-type" name="type"><option value="">All types</option><?php foreach (EVENT_TYPES as $k => $label) { if ($k !== 'awareness_day') { echo '<option value="' . e($k) . '"' . ($type === $k ? ' selected' : '') . '>' . e($label) . '</option>'; } } ?></select>
            <label class="sr-only" for="f-mode">Mode</label>
            <select id="f-mode" name="mode"><option value="">Any mode</option><?php foreach (EVENT_MODES as $k => $label) { echo '<option value="' . e($k) . '"' . ($mode === $k ? ' selected' : '') . '>' . e($label) . '</option>'; } ?></select>
        <?php endif; ?>
        <label class="sr-only" for="f-status">Status</label>
        <select id="f-status" name="status"><option value="">All statuses</option><?php foreach (EVENT_STATUSES as $s) { echo '<option value="' . e($s) . '"' . ($status === $s ? ' selected' : '') . '>' . e(humanize($s)) . '</option>'; } ?></select>
        <button class="btn btn-primary btn-sm" type="submit"><?= icon('search') ?>Filter</button>
        <?php if ($filtered): ?><a class="btn btn-ghost btn-sm" href="<?= e($tabUrl($tab)) ?>">Reset</a><?php endif; ?>
    </form>

    <section class="panel">
        <?php if ($rows): ?>
            <p class="table-meta"><span><?= (int) $p['total'] ?> <?= $tab === 'awareness' ? 'awareness day' : 'event' ?><?= (int) $p['total'] === 1 ? '' : 's' ?></span></p>
            <div class="table-wrap"><table class="table">
                <thead><tr><th><?= $tab === 'awareness' ? 'Awareness day' : 'Event' ?></th><th>Date</th><?php if ($tab === 'events'): ?><th>Category &amp; type</th><th>Registrations</th><?php endif; ?><th>Status</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $rid = (int) $r['id']; $past = event_is_past($r); $title = $r['title']; ?>
                    <tr<?= $past || $r['status'] === 'archived' ? ' style="opacity:.82"' : '' ?>>
                        <td><div class="ph-item">
                            <?php if ($tab === 'events'): [$img] = function_exists('programhub_event_image') ? programhub_event_image($r) : [$r['image'] ?: CONTENT_FALLBACK_IMAGE]; ?><img class="thumb" src="<?= e(image_url($img, CONTENT_FALLBACK_IMAGE)) ?>" alt="" width="64" height="44" loading="lazy"><?php endif; ?>
                            <div><a href="<?= e(admin_url('event-edit.php?id=' . $rid)) ?>"><strong><?= e($title) ?></strong></a><small><?= e(substr(event_url($r), strlen(base_path()))) ?></small></div></div></td>
                        <td class="nowrap small"><?= e(format_date($r['start_datetime'], 'd M Y')) ?><?= event_is_all_day($r) ? '' : '<br><span class="muted">' . e(format_date($r['start_datetime'], 'h:i A')) . '</span>' ?><?= $past ? '<br><span class="muted">Past</span>' : '' ?></td>
                        <?php if ($tab === 'events'): ?>
                            <td class="small"><?= $r['category_title'] ? e($r['category_title']) : '<span class="muted">No category</span>' ?><br><span class="muted"><?= e(event_type_label($r['event_type'])) ?> · <?= e(['in_person' => 'In person', 'online' => 'Online', 'hybrid' => 'Hybrid'][event_mode($r)]) ?></span>
                                <?php if (in_array(event_mode($r), ['online', 'hybrid'], true) && trim((string) $r['meeting_url']) === '' && !$past): ?><br><span class="ph-chip is-warn"><?= icon('alert-triangle') ?>No joining link</span><?php endif; ?></td>
                            <td class="nowrap small">
                                <?php if ((int) $r['registration_enabled'] || (int) $r['registered'] || (int) $r['waitlisted']): ?>
                                    <?php $regText = (int) $r['registered'] . ($r['capacity'] ? ' / ' . (int) $r['capacity'] : ''); ?>
                                    <?= $canPeople ? '<a href="' . e(admin_url('event-registrations.php?event=' . $rid)) . '">' . e($regText) . '</a>' : e($regText) ?>
                                    <?php if ($r['capacity']): $pct = (int) min(100, round($r['registered'] / max(1, $r['capacity']) * 100)); ?><div class="mini-progress" aria-hidden="true"><span style="width:<?= $pct ?>%"></span></div><?php endif; ?>
                                    <?= (int) $r['waitlisted'] ? '<span class="muted">' . (int) $r['waitlisted'] . ' waiting</span>' : '' ?>
                                    <?= !(int) $r['registration_enabled'] ? '<span class="muted">Registration off</span>' : '' ?>
                                <?php else: ?><span class="muted">No registration</span><?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td><?= programhub_status_badge($r) ?><?php if ($r['status'] === 'scheduled' && $r['publish_at']): ?><br><small class="muted">Publishes <?= e(format_date($r['publish_at'], 'd M, h:i A')) ?></small><?php endif; ?></td>
                        <td class="actions-cell">
                            <a class="icon-btn" href="<?= e(admin_url('event-edit.php?id=' . $rid)) ?>" aria-label="Edit <?= e($title) ?>" title="Edit"><?= icon('edit') ?></a>
                            <?php if ($tab === 'events' && $canPeople): ?><a class="icon-btn" href="<?= e(admin_url('event-registrations.php?event=' . $rid)) ?>" aria-label="Registrations for <?= e($title) ?>" title="Registrations"><?= icon('users') ?></a><?php endif; ?>
                            <a class="icon-btn" href="<?= e(event_url($r)) ?>" target="_blank" rel="noopener" aria-label="<?= in_array($r['status'], ['published', 'cancelled'], true) ? 'View' : 'Preview' ?> <?= e($title) ?>" title="<?= in_array($r['status'], ['published', 'cancelled'], true) ? 'View' : 'Preview' ?>"><?= icon('eye') ?></a>
                            <form class="inline-form" method="post"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?= $rid ?>">
                                <button class="icon-btn" type="submit" aria-label="Duplicate <?= e($title) ?>" title="Duplicate as draft"><?= icon('copy') ?></button></form>
                            <?php if ($r['status'] === 'archived'): ?>
                                <form class="inline-form" method="post"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= $rid ?>">
                                    <button class="icon-btn" type="submit" aria-label="Restore <?= e($title) ?> as a draft" title="Restore as draft"><?= icon('refresh') ?></button></form>
                            <?php else: ?>
                                <form class="inline-form" method="post" data-confirm="<?= e('Archive "' . $title . '"? It is hidden from visitors; registrations and the report are kept.') ?>"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?= $rid ?>">
                                    <button class="icon-btn" type="submit" aria-label="Archive <?= e($title) ?>" title="Archive"><?= icon('inbox') ?></button></form>
                            <?php endif; ?>
                            <form class="inline-form" method="post" data-confirm="<?= e('Delete "' . $title . '"' . ((int) $r['registered'] + (int) $r['waitlisted'] ? ' and its ' . ((int) $r['registered'] + (int) $r['waitlisted']) . ' registrations' : '') . ' permanently? Archiving keeps the record instead.') ?>">
                                <?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $rid ?>">
                                <button class="icon-btn danger" type="submit" aria-label="Delete <?= e($title) ?>" title="Delete"><?= icon('trash') ?></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?= pagination_links($p) ?>
        <?php else: ?>
            <div class="empty-state"><?= icon('calendar') ?><p><?= $tab === 'awareness' ? 'No awareness days match these filters.' : ($filtered ? 'No events match these filters.' : 'No upcoming events. Add the next workshop, seminar, session or community event.') ?></p>
                <a class="btn btn-primary" href="<?= e(admin_url('event-edit.php' . ($tab === 'awareness' ? '?type=awareness_day' : ''))) ?>"><?= icon('plus') ?><?= $tab === 'awareness' ? 'New awareness day' : 'New event' ?></a></div>
        <?php endif; ?>
    </section>
    <p class="muted small"><?= icon('info') ?> Published events appear on <a href="<?= e(events_list_url()) ?>" target="_blank" rel="noopener">Events</a>, on their category page and in the <a href="<?= e(page_url('awareness-calendar')) ?>" target="_blank" rel="noopener">Awareness Calendar</a>. Hackathons and innovation challenges live at /hackathons/&lt;address&gt;/. Awareness days are observances, not events organised by the foundation, and have no registration.</p>
<?php endif; ?>
</div>
<?php
programhub_admin_assets();
admin_page_end();
