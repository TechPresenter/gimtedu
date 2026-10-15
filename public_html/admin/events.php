<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('content');

// Registrations for one event (+ CSV export)
$regId = (int) ($_GET['registrations'] ?? 0);
if ($regId) {
    $event = db_row('SELECT * FROM events WHERE id = ?', [$regId]);
    if (!$event) {
        admin_redirect('events.php');
    }
    if (($_GET['export'] ?? '') === 'csv') {
        $rows = db_rows('SELECT name, email, phone, message, status, created_at FROM event_registrations WHERE event_id = ? ORDER BY created_at', [$regId]);
        admin_log('event_registrations_exported', $event['title']);
        send_csv('registrations-' . $event['slug'] . '.csv', ['Name', 'Email', 'Phone', 'Message', 'Status', 'Registered'], $rows);
    }
    $regs = db_rows('SELECT * FROM event_registrations WHERE event_id = ? ORDER BY created_at DESC', [$regId]);
    $adminTitle = 'Registrations: ' . $event['title'];
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="page-actions">
        <a class="btn btn-ghost btn-sm" href="events.php">&larr; All events</a>
        <a class="btn btn-ghost btn-sm" href="?registrations=<?= $regId ?>&amp;export=csv"><?= icon('download') ?>Export CSV</a>
    </div>
    <div class="panel">
        <div class="table-meta"><span><?= count($regs) ?> registration(s)<?= $event['capacity'] ? ' · capacity ' . (int) $event['capacity'] : '' ?></span></div>
        <?php if ($regs): ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Name</th><th>Contact</th><th>Message</th><th>Registered</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($regs as $r): ?>
                    <tr>
                        <td><?= e($r['name']) ?></td>
                        <td><?= e($r['email']) ?><br><small class="muted"><?= e($r['phone']) ?></small></td>
                        <td><?= e(excerpt($r['message'], 80)) ?></td>
                        <td class="nowrap"><?= e(format_date($r['created_at'], 'd M Y, h:i A')) ?></td>
                        <td>
                            <form class="inline-form" method="post" action="actions/content-save.php">
                                <?= csrf_field() ?><input type="hidden" name="type" value="registration"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <select name="status" aria-label="Status" style="min-height:34px;padding:4px 30px 4px 8px;font-size:.82rem;width:auto"><?= options_html(['registered' => 'Registered', 'attended' => 'Attended', 'cancelled' => 'Cancelled'], $r['status'], true, '') ?></select>
                                <button class="btn btn-ghost btn-sm" type="submit">Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?>
            <p class="muted mb-0">No registrations yet.</p>
        <?php endif; ?>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// Add / edit form
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId || ($_GET['action'] ?? '') === 'add') {
    $ev = $editId ? db_row('SELECT * FROM events WHERE id = ?', [$editId]) : null;
    if ($editId && !$ev) {
        admin_redirect('events.php');
    }
    $v = function (string $k, string $d = '') use ($ev) {
        return (string) old($k, $ev[$k] ?? $d);
    };
    $dt = function (string $k) use ($ev): string {
        $value = (string) old($k, $ev[$k] ?? '');
        return $value ? date('Y-m-d\TH:i', strtotime($value)) : '';
    };
    $adminTitle = $ev ? 'Edit event' : 'New event';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="page-actions">
        <a class="btn btn-ghost btn-sm" href="events.php">&larr; All events</a>
        <?php if ($ev): ?><div class="actions">
            <a class="btn btn-ghost btn-sm" href="?registrations=<?= (int) $ev['id'] ?>"><?= icon('users') ?>Registrations</a>
            <?php if ($ev['status'] !== 'draft'): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('event-details.php?slug=' . urlencode($ev['slug']))) ?>" target="_blank"><?= icon('external') ?>View</a><?php endif; ?>
        </div><?php endif; ?>
    </div>
    <form method="post" action="actions/content-save.php" enctype="multipart/form-data" data-validate>
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="event">
        <input type="hidden" name="id" value="<?= (int) ($ev['id'] ?? 0) ?>">
        <div class="admin-grid-main">
            <div class="panel">
                <div class="form-group mb-2"><label for="title">Title<span class="req">*</span></label><input id="title" type="text" name="title" required maxlength="200" value="<?= e($v('title')) ?>"><?= field_error('title') ?></div>
                <div class="form-group mb-2"><label for="summary">Summary<span class="req">*</span></label><textarea id="summary" name="summary" required maxlength="300" rows="2" style="min-height:70px"><?= e($v('summary')) ?></textarea><?= field_error('summary') ?></div>
                <div class="form-group"><label for="description">Description<span class="req">*</span></label><textarea id="description" name="description" required data-editor data-upload-url="actions/upload-image.php"><?= e($v('description')) ?></textarea><?= field_error('description') ?></div>
            </div>
            <div>
                <div class="panel">
                    <div class="form-group mb-2"><label for="status">Status</label><select id="status" name="status"><?= options_html(['draft' => 'Draft', 'published' => 'Published', 'cancelled' => 'Cancelled'], $v('status', 'draft'), true, '') ?></select></div>
                    <div class="form-group mb-2"><label for="start_datetime">Starts<span class="req">*</span></label><input id="start_datetime" type="datetime-local" name="start_datetime" required value="<?= e($dt('start_datetime')) ?>"><?= field_error('start_datetime') ?></div>
                    <div class="form-group mb-2"><label for="end_datetime">Ends</label><input id="end_datetime" type="datetime-local" name="end_datetime" value="<?= e($dt('end_datetime')) ?>"><?= field_error('end_datetime') ?></div>
                    <div class="form-group mb-2"><label for="location">Location<span class="req">*</span></label><input id="location" type="text" name="location" required maxlength="200" value="<?= e($v('location')) ?>"><?= field_error('location') ?></div>
                    <div class="form-group mb-2"><label for="category">Category</label><input id="category" type="text" name="category" maxlength="60" value="<?= e($v('category', 'general')) ?>" placeholder="e.g. health-camp"></div>
                    <div class="form-group mb-2"><label for="capacity">Capacity</label><input id="capacity" type="number" name="capacity" min="1" value="<?= e($v('capacity')) ?>" placeholder="Unlimited"><?= field_error('capacity') ?></div>
                    <label class="checkbox"><input type="checkbox" name="registration_enabled" value="1"<?= $v('registration_enabled', '1') ? ' checked' : '' ?>> <span>Allow online registration</span></label>
                </div>
                <div class="panel">
                    <div class="form-group"><label for="image">Image</label>
                        <?php if (!empty($ev['image'])): ?><img src="<?= e(image_url($ev['image'])) ?>" alt="" class="mb-1" style="border-radius:8px;max-height:140px"><?php endif; ?>
                        <input id="image" type="file" name="image" accept=".jpg,.jpeg,.png,.webp"><?= field_error('image') ?></div>
                </div>
                <button class="btn btn-primary btn-lg btn-block" type="submit">Save event</button>
            </div>
        </div>
    </form>
    <?php if ($ev): ?>
        <form method="post" action="actions/content-save.php" class="mt-3" data-confirm="Delete this event and all its registrations?">
            <?= csrf_field() ?><input type="hidden" name="type" value="event"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $ev['id'] ?>">
            <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash') ?>Delete event</button>
        </form>
    <?php endif; ?>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$q = clean_string($_GET['q'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$params = [];
$where = 'WHERE 1=1' . search_where(['e.title', 'e.location'], $q, $params);
if (in_array($status, ['draft', 'published', 'cancelled'], true)) {
    $where .= ' AND e.status = ?';
    $params[] = $status;
}
$p = paginate((int) db_value("SELECT COUNT(*) FROM events e $where", $params), 20);
$rows = db_rows("SELECT e.*, (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status <> 'cancelled') AS regs
                 FROM events e $where ORDER BY e.start_datetime DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$adminTitle = 'Events';
require __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
    <?php admin_filter_bar(['draft', 'published', 'cancelled'], ['placeholder' => 'Search title or location']); ?>
    <a class="btn btn-primary btn-sm" href="?action=add"><?= icon('plus') ?>New event</a>
</div>
<div class="panel">
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Event</th><th>Date</th><th>Location</th><th>Registrations</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><a href="?edit=<?= (int) $r['id'] ?>"><strong><?= e($r['title']) ?></strong></a></td>
                    <td class="nowrap"><?= e(format_date($r['start_datetime'], 'd M Y, h:i A')) ?></td>
                    <td><?= e(excerpt($r['location'], 40)) ?></td>
                    <td><a href="?registrations=<?= (int) $r['id'] ?>"><?= (int) $r['regs'] ?><?= $r['capacity'] ? ' / ' . (int) $r['capacity'] : '' ?></a></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="actions-cell"><a class="icon-btn" href="?edit=<?= (int) $r['id'] ?>" aria-label="Edit"><?= icon('edit') ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state('No events yet.') ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
