<?php
/**
 * Events & awareness calendar: list (events / awareness days), filters, delete.
 * Registrations are managed in event-registrations.php?event=<id>.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('events.manage');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('events.php');
    $id = (int) ($_POST['id'] ?? 0);
    $event = $id ? db_row('SELECT * FROM events WHERE id = ?', [$id]) : null;
    if ($event && ($_POST['action'] ?? '') === 'delete') {
        $regs = (int) db_value('SELECT COUNT(*) FROM event_registrations WHERE event_id = ?', [$id]);
        db_query('DELETE FROM events WHERE id = ?', [$id]);
        content_delete_image($event['image']);
        admin_log('event_deleted', 'Event #' . $id . ' "' . $event['title'] . '" deleted' . ($regs ? ' with ' . $regs . ' registrations' : ''));
        flash('success', 'The event "' . $event['title'] . '" was deleted.');
    }
    admin_redirect(admin_back('events.php'));
}

$tab = ($_GET['tab'] ?? '') === 'awareness' ? 'awareness' : 'events';
$status = in_array($_GET['status'] ?? '', EVENT_STATUSES, true) ? (string) $_GET['status'] : '';
$when = in_array($_GET['when'] ?? '', ['upcoming', 'past', 'all'], true) ? (string) $_GET['when'] : 'upcoming';
$type = (string) ($_GET['type'] ?? '');
if (!isset(EVENT_TYPES[$type]) || $type === 'awareness_day') {
    $type = '';
}
$q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 100));
$where = 'WHERE ' . ($tab === 'awareness' ? "e.event_type = 'awareness_day'" : "e.event_type <> 'awareness_day'");
$params = [];
if ($type !== '' && $tab === 'events') {
    $where .= ' AND e.event_type = ?';
    $params[] = $type;
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
$rows = db_rows("SELECT e.*, (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status <> 'cancelled') AS registered
                 FROM events e $where ORDER BY e.start_datetime " . ($when === 'past' ? 'DESC' : 'ASC') . ", e.id LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$counts = db_row("SELECT SUM(event_type <> 'awareness_day' AND status = 'published' AND COALESCE(end_datetime, start_datetime) >= NOW()) AS upcoming,
                         SUM(event_type <> 'awareness_day' AND status = 'draft') AS drafts,
                         SUM(event_type = 'awareness_day' AND status = 'published' AND COALESCE(end_datetime, start_datetime) >= NOW()) AS aw
                  FROM events");
$regTotal = (int) db_value("SELECT COUNT(*) FROM event_registrations r JOIN events e ON e.id = r.event_id WHERE r.status = 'registered' AND COALESCE(e.end_datetime, e.start_datetime) >= NOW()");
$tabUrl = function (string $t): string {
    return admin_url('events.php' . ($t === 'awareness' ? '?tab=awareness' : ''));
};

admin_page_start('Events & Calendar', [
    ['label' => 'New event', 'url' => admin_url('event-edit.php'), 'icon' => 'plus'],
    ['label' => 'New awareness day', 'url' => admin_url('event-edit.php?type=awareness_day'), 'icon' => 'flag', 'style' => 'ghost'],
]);
?>
<div class="stat-cards">
    <a class="stat-card" href="<?= e(admin_url('events.php?status=published')) ?>"><span class="icon-circle"><?= icon('calendar') ?></span><span><strong><?= (int) $counts['upcoming'] ?></strong><small>Upcoming published events</small></span></a>
    <div class="stat-card"><span class="icon-circle blue"><?= icon('users') ?></span><span><strong><?= $regTotal ?></strong><small>Registrations for upcoming events</small></span></div>
    <a class="stat-card" href="<?= e(admin_url('events.php?status=draft&when=all')) ?>"><span class="icon-circle gold"><?= icon('edit') ?></span><span><strong><?= (int) $counts['drafts'] ?></strong><small>Draft events</small></span></a>
    <a class="stat-card" href="<?= e($tabUrl('awareness')) ?>"><span class="icon-circle gold"><?= icon('flag') ?></span><span><strong><?= (int) $counts['aw'] ?></strong><small>Upcoming awareness days</small></span></a>
</div>

<nav class="admin-tabs" aria-label="Event lists">
    <a class="<?= $tab === 'events' ? 'active' : '' ?>" href="<?= e($tabUrl('events')) ?>">Events, camps, drives &amp; webinars</a>
    <a class="<?= $tab === 'awareness' ? 'active' : '' ?>" href="<?= e($tabUrl('awareness')) ?>">Awareness days</a>
</nav>

<form class="filter-row" method="get">
    <?php if ($tab === 'awareness'): ?><input type="hidden" name="tab" value="awareness"><?php endif; ?>
    <label class="sr-only" for="f-q">Search</label>
    <input id="f-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Search title or location">
    <label class="sr-only" for="f-when">When</label>
    <select id="f-when" name="when"><?= options_html(['upcoming' => 'Upcoming', 'past' => 'Past', 'all' => 'All dates'], $when, true, '') ?></select>
    <?php if ($tab === 'events'): ?>
        <label class="sr-only" for="f-type">Type</label>
        <select id="f-type" name="type"><option value="">All types</option><?php foreach (EVENT_TYPES as $k => $label) { if ($k !== 'awareness_day') { echo '<option value="' . e($k) . '"' . ($type === $k ? ' selected' : '') . '>' . e($label) . '</option>'; } } ?></select>
    <?php endif; ?>
    <label class="sr-only" for="f-status">Status</label>
    <select id="f-status" name="status"><option value="">All statuses</option><?php foreach (EVENT_STATUSES as $s) { echo '<option value="' . e($s) . '"' . ($status === $s ? ' selected' : '') . '>' . e(humanize($s)) . '</option>'; } ?></select>
    <button class="btn btn-primary btn-sm" type="submit"><?= icon('search') ?>Filter</button>
    <?php if ($q !== '' || $status !== '' || $type !== '' || $when !== 'upcoming'): ?><a class="btn btn-ghost btn-sm" href="<?= e($tabUrl($tab)) ?>">Reset</a><?php endif; ?>
</form>

<section class="panel">
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th><?= $tab === 'awareness' ? 'Awareness day' : 'Event' ?></th><th>Date</th><?php if ($tab === 'events'): ?><th>Type</th><th>Location</th><th>Registrations</th><?php endif; ?><th>Status</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $past = event_is_past($r); ?>
                <tr<?= $past ? ' style="opacity:.8"' : '' ?>>
                    <td><div style="display:flex;gap:12px;align-items:center;min-width:220px">
                        <?php if ($tab === 'events'): ?><img class="thumb" src="<?= e(image_url($r['image'], CONTENT_FALLBACK_IMAGE)) ?>" alt="" width="64" height="44" loading="lazy"><?php endif; ?>
                        <div><a href="<?= e(admin_url('event-edit.php?id=' . (int) $r['id'])) ?>"><strong><?= e($r['title']) ?></strong></a><br><small class="muted">/event-details/<?= e($r['slug']) ?>/</small></div></div></td>
                    <td class="nowrap"><?= e(format_date($r['start_datetime'], 'd M Y')) ?><?= event_is_all_day($r) ? '' : '<br><small class="muted">' . e(format_date($r['start_datetime'], 'h:i A')) . '</small>' ?><?= $past ? '<br><small class="muted">Past</small>' : '' ?></td>
                    <?php if ($tab === 'events'): ?>
                        <td><?= e(event_type_label($r['event_type'])) ?></td>
                        <td><?= e(excerpt($r['location'], 40)) ?></td>
                        <td class="nowrap"><a href="<?= e(admin_url('event-registrations.php?event=' . (int) $r['id'])) ?>"><?= (int) $r['registered'] ?><?= $r['capacity'] ? ' / ' . (int) $r['capacity'] : '' ?></a>
                            <?php if ($r['capacity']): $pct = (int) min(100, round($r['registered'] / max(1, $r['capacity']) * 100)); ?><div class="mini-progress"><span style="width:<?= $pct ?>%"></span></div><?php endif; ?>
                            <?= !(int) $r['registration_enabled'] ? '<small class="muted">Registration off</small>' : '' ?></td>
                    <?php endif; ?>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="actions-cell">
                        <a class="icon-btn" href="<?= e(admin_url('event-edit.php?id=' . (int) $r['id'])) ?>" aria-label="Edit <?= e($r['title']) ?>" title="Edit"><?= icon('edit') ?></a>
                        <?php if ($tab === 'events'): ?><a class="icon-btn" href="<?= e(admin_url('event-registrations.php?event=' . (int) $r['id'])) ?>" aria-label="Registrations for <?= e($r['title']) ?>" title="Registrations"><?= icon('users') ?></a><?php endif; ?>
                        <a class="icon-btn" href="<?= e(event_url($r['slug'])) ?>" target="_blank" rel="noopener" aria-label="View <?= e($r['title']) ?>" title="View / preview"><?= icon('eye') ?></a>
                        <form class="inline-form" method="post" data-confirm="<?= e('Delete "' . $r['title'] . '"' . ((int) $r['registered'] ? ' and its ' . (int) $r['registered'] . ' registrations' : '') . ' permanently?') ?>">
                            <?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button class="icon-btn danger" type="submit" aria-label="Delete <?= e($r['title']) ?>" title="Delete"><?= icon('trash') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <div class="empty-state"><?= icon('calendar') ?><p><?= $tab === 'awareness' ? 'No awareness days match these filters.' : 'No events match these filters. Add the next camp, drive, workshop or webinar.' ?></p>
            <a class="btn btn-primary" href="<?= e(admin_url('event-edit.php' . ($tab === 'awareness' ? '?type=awareness_day' : ''))) ?>"><?= icon('plus') ?><?= $tab === 'awareness' ? 'New awareness day' : 'New event' ?></a></div>
    <?php endif; ?>
</section>
<p class="muted small"><?= icon('info') ?> Published events appear on <a href="<?= e(page_url('events-page')) ?>" target="_blank" rel="noopener">Events</a> and the <a href="<?= e(page_url('awareness-calendar')) ?>" target="_blank" rel="noopener">Awareness Calendar</a>. Awareness days are observances, not events organised by the foundation, and have no registration.</p>
<?php
admin_page_end();
