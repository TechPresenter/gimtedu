<?php
/**
 * Create / edit an event or awareness day.
 * Awareness days (observances such as World Health Day) never take registrations.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('events.manage');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$event = $id ? db_row('SELECT * FROM events WHERE id = ?', [$id]) : null;
if ($id && !$event) {
    flash('error', 'Event not found.');
    admin_redirect('events.php');
}
$self = 'event-edit.php' . ($event ? '?id=' . $id : '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_GET['upload'])) {
    content_editor_upload('events', $event ? 'event #' . $id : 'new event');
}

$programs = content_programs();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only($self);
    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'delete' && $event) {
        $regs = (int) db_value('SELECT COUNT(*) FROM event_registrations WHERE event_id = ?', [$id]);
        db_query('DELETE FROM events WHERE id = ?', [$id]);
        content_delete_image($event['image']);
        admin_log('event_deleted', 'Event #' . $id . ' "' . $event['title'] . '" deleted' . ($regs ? ' with ' . $regs . ' registrations' : ''));
        flash('success', 'The event was deleted.');
        admin_redirect('events.php' . ($event['event_type'] === 'awareness_day' ? '?tab=awareness' : ''));
    }

    [$data, $errors] = validate($_POST, [
        'title' => 'required|min:3|max:200',
        'slug' => 'max:200',
        'event_type' => 'required|in:' . implode(',', array_keys(EVENT_TYPES)),
        'program_id' => 'integer',
        'summary' => 'required|text|max:300',
        'location' => 'required|max:200',
        'start_datetime' => 'required|datetime',
        'end_datetime' => 'datetime',
        'capacity' => 'integer|min:1|max:100000',
        'status' => 'required|in:' . implode(',', EVENT_STATUSES),
    ], ['event_type' => 'Type', 'program_id' => 'Programme', 'start_datetime' => 'Start', 'end_datetime' => 'End']);
    $description = clean_html((string) ($_POST['description'] ?? ''));
    $programId = $data['program_id'] !== '' ? (int) $data['program_id'] : null;
    if ($programId && !isset($programs[$programId])) {
        $errors['program_id'] = 'Please choose a valid programme.';
    }
    $allDay = !empty($_POST['all_day']);
    $start = $data['start_datetime'];
    $end = $data['end_datetime'] !== '' ? $data['end_datetime'] : null;
    if (!$errors && $allDay) {
        $start = substr($start, 0, 10) . ' 00:00:00';
        $end = substr($end ?: $start, 0, 10) . ' 23:59:00';
    }
    if (!$errors && $end && strtotime($end) < strtotime($start)) {
        $errors['end_datetime'] = 'The end must be after the start.';
    }
    if ($errors) {
        back_with_errors($errors, 'admin/' . $self, $_POST);
    }

    $image = $event['image'] ?? null;
    $oldImage = $image;
    $before = count($_SESSION['_flash'] ?? []);
    $uploaded = admin_upload_image('image', 'events', $data['title']);
    if (!$uploaded && count($_SESSION['_flash'] ?? []) > $before) {
        $last = array_pop($_SESSION['_flash']);
        back_with_errors(['image' => $last['message'] ?? 'The image could not be uploaded.'], 'admin/' . $self, $_POST);
    }
    if ($uploaded) {
        $image = $uploaded;
    } elseif (!empty($_POST['remove_image'])) {
        $image = null;
    }

    $isAwareness = $data['event_type'] === 'awareness_day';
    $row = [
        'title' => $data['title'],
        'slug' => unique_slug('events', $data['slug'] !== '' ? $data['slug'] : $data['title'] . ($isAwareness ? ' ' . substr($start, 0, 4) : ''), $id),
        'event_type' => $data['event_type'],
        'program_id' => $programId,
        'summary' => $data['summary'],
        'description' => $description !== '' ? $description : '<p>' . e($data['summary']) . '</p>',
        'image' => $image,
        'location' => $data['location'],
        'start_datetime' => $start,
        'end_datetime' => $end,
        'registration_enabled' => !$isAwareness && !empty($_POST['registration_enabled']) ? 1 : 0,
        'capacity' => !$isAwareness && $data['capacity'] !== '' ? (int) $data['capacity'] : null,
        'status' => $data['status'],
    ];
    $statusChanged = $event && $event['status'] !== $data['status'];
    if ($event) {
        db_update('events', $row, 'id = :id', ['id' => $id]);
        admin_log('event_updated', 'Event #' . $id . ' "' . $data['title'] . '" saved (' . $data['status'] . ($statusChanged ? ', was ' . $event['status'] : '') . ')');
    } else {
        $row['created_by'] = admin_id();
        $id = db_insert('events', $row);
        admin_log('event_created', ($isAwareness ? 'Awareness day' : 'Event') . ' #' . $id . ' "' . $data['title'] . '" created (' . $data['status'] . ')');
    }
    if ($oldImage && $oldImage !== $image) {
        content_delete_image($oldImage);
    }
    $active = (int) db_value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status = 'registered'", [$id]);
    if ($statusChanged && $data['status'] === 'cancelled' && $active) {
        flash('warning', 'The event is cancelled. ' . $active . ' people are registered - let them know from the registrations page (Email all registrants).');
    } elseif ($row['capacity'] !== null && $active > $row['capacity']) {
        flash('warning', 'There are already ' . $active . ' registrations - more than the new capacity of ' . $row['capacity'] . '. Existing registrations are kept.');
    }
    flash('success', ($isAwareness ? 'Awareness day' : 'Event') . ' saved (' . $data['status'] . ').');
    admin_redirect('event-edit.php?id=' . $id);
}

$v = function (string $key, $default = '') use ($event) {
    return old($key, $event[$key] ?? $default);
};
$typeDefault = isset(EVENT_TYPES[$_GET['type'] ?? '']) ? (string) $_GET['type'] : 'event';
$type = (string) $v('event_type', $typeDefault);
$failed = old('title', null) !== null;
$allDayChecked = $failed ? old('all_day', null) !== null : ($event ? event_is_all_day($event) : $type === 'awareness_day');
$regChecked = $failed ? old('registration_enabled', null) !== null : (bool) ($event['registration_enabled'] ?? ($type !== 'awareness_day'));
$registered = $event ? event_registered_count($id) : 0;
$isPublic = $event && in_array($event['status'], ['published', 'cancelled'], true);

admin_page_start($event ? 'Edit ' . ($event['event_type'] === 'awareness_day' ? 'awareness day' : 'event') : ($type === 'awareness_day' ? 'New awareness day' : 'New event'), array_filter([
    ['label' => 'All events', 'url' => admin_url('events.php' . ($type === 'awareness_day' ? '?tab=awareness' : '')), 'icon' => 'list', 'style' => 'ghost'],
    $event && $event['event_type'] !== 'awareness_day' ? ['label' => 'Registrations (' . $registered . ')', 'url' => admin_url('event-registrations.php?event=' . $id), 'icon' => 'users', 'style' => 'ghost'] : null,
]));
?>
<?php if ($event): ?>
    <div class="note-box mb-3" style="white-space:normal;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <?= status_badge($event['status']) ?><?= event_is_past($event) ? ' <span class="badge badge-gray">Past</span>' : '' ?>
        <span><?= $isPublic ? 'Live at' : 'Address when published:' ?> <a href="<?= e(event_url($event['slug'])) ?>" target="_blank" rel="noopener"><?= e(abs_url('event-details/' . $event['slug'] . '/')) ?></a></span>
    </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" action="<?= e(admin_url($self)) ?>" data-validate>
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <div class="admin-grid-main">
        <div>
            <section class="panel">
                <div class="form-grid">
                    <div class="form-group full"><label for="title">Title<span class="req">*</span></label>
                        <input id="title" name="title" required minlength="3" maxlength="200" value="<?= e($v('title')) ?>" data-slug-source="#slug"><?= field_error('title') ?></div>
                    <div class="form-group full"><label for="slug">Web address (slug)</label>
                        <input id="slug" name="slug" maxlength="200" value="<?= e($v('slug')) ?>" <?= $event ? 'data-touched="1"' : '' ?>>
                        <span class="help">/event-details/<strong>this-part</strong>/ - leave empty to create it from the title.</span><?= field_error('slug') ?></div>
                    <div class="form-group full"><label for="summary">Short summary<span class="req">*</span></label>
                        <textarea id="summary" name="summary" required maxlength="300" rows="3" data-counter style="min-height:84px"><?= e($v('summary')) ?></textarea>
                        <span class="help">One or two sentences shown on cards, in the calendar and in search results.</span><?= field_error('summary') ?></div>
                    <div class="form-group full"><label for="description">Full description</label>
                        <textarea id="description" name="description" rows="14" data-editor data-upload-url="<?= e(admin_url('event-edit.php?upload=1' . ($event ? '&id=' . $id : ''))) ?>"><?= e($v('description')) ?></textarea>
                        <span class="help">What will happen, who it is for, what to bring, how to reach. For awareness days: the facts and how people can take part.</span><?= field_error('description') ?></div>
                </div>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Date, time &amp; place</h2></div>
                <div class="form-grid">
                    <div class="form-group"><label for="start_datetime">Starts<span class="req">*</span></label>
                        <input id="start_datetime" type="datetime-local" name="start_datetime" required value="<?= e(content_dt_input((string) $v('start_datetime'))) ?>"><?= field_error('start_datetime') ?></div>
                    <div class="form-group"><label for="end_datetime">Ends</label>
                        <input id="end_datetime" type="datetime-local" name="end_datetime" value="<?= e(content_dt_input((string) $v('end_datetime'))) ?>"><?= field_error('end_datetime') ?></div>
                    <div class="form-group full"><label class="checkbox"><input type="checkbox" name="all_day" value="1"<?= $allDayChecked ? ' checked' : '' ?>><span>All-day (times are ignored; shown as a date)</span></label></div>
                    <div class="form-group full"><label for="location">Location<span class="req">*</span></label>
                        <input id="location" name="location" required maxlength="200" value="<?= e($v('location', $type === 'awareness_day' ? 'India' : '')) ?>" placeholder="Venue, area, city - or Online">
                        <span class="help">Visitors get a Google Maps search link for this text. Write "Online" for webinars.</span><?= field_error('location') ?></div>
                </div>
            </section>
        </div>
        <div>
            <section class="panel">
                <div class="panel-head"><h2>Publish</h2></div>
                <div class="form-group mb-2"><label for="event_type">Type<span class="req">*</span></label>
                    <select id="event_type" name="event_type" required><?= options_html(EVENT_TYPES, $type, true, '') ?></select><?= field_error('event_type') ?></div>
                <div class="form-group mb-2"><label for="status">Status</label>
                    <select id="status" name="status">
                        <?php foreach (['draft' => 'Draft (not visible)', 'published' => 'Published', 'cancelled' => 'Cancelled (shown as cancelled)', 'archived' => 'Archived (hidden)'] as $k => $label): ?>
                            <option value="<?= $k ?>"<?= (string) $v('status', 'draft') === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select><?= field_error('status') ?></div>
                <div class="form-group mb-2"><label for="program_id">Programme (optional)</label>
                    <select id="program_id" name="program_id"><?= options_html($programs, (string) $v('program_id'), true, 'None') ?></select><?= field_error('program_id') ?></div>
                <div class="form-actions" style="margin-top:14px">
                    <button class="btn btn-primary" type="submit"><?= icon('check') ?>Save</button>
                    <?php if ($event): ?><a class="btn btn-ghost" href="<?= e(event_url($event['slug'])) ?>" target="_blank" rel="noopener"><?= icon('eye') ?><?= $isPublic ? 'View' : 'Preview' ?></a><?php endif; ?>
                </div>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Registration</h2></div>
                <label class="checkbox mb-2"><input type="checkbox" name="registration_enabled" value="1"<?= $regChecked ? ' checked' : '' ?>><span>Accept registrations on the website</span></label>
                <div class="form-group"><label for="capacity">Capacity (places)</label>
                    <input id="capacity" type="number" name="capacity" min="1" max="100000" step="1" value="<?= e((string) $v('capacity')) ?>" placeholder="No limit">
                    <span class="help">Registration closes automatically when full, when the event starts, or if it is cancelled. Not used for awareness days.</span><?= field_error('capacity') ?></div>
                <?php if ($event && $event['event_type'] !== 'awareness_day'): ?>
                    <p class="mt-2 mb-0"><a href="<?= e(admin_url('event-registrations.php?event=' . $id)) ?>"><?= icon('users') ?> <?= $registered ?> registered<?= $event['capacity'] ? ' of ' . (int) $event['capacity'] : '' ?> - manage</a></p>
                <?php endif; ?>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Image</h2></div>
                <?php $img = $event['image'] ?? ''; ?>
                <img id="img-preview" class="mb-2"<?= $img ? ' src="' . e(image_url($img)) . '"' : '' ?> alt="" style="width:100%;border-radius:10px;aspect-ratio:16/10;object-fit:cover;background:var(--green-50)"<?= $img ? '' : ' hidden' ?>>
                <div class="form-group"><label for="image"><?= $img ? 'Replace image' : 'Upload image' ?></label>
                    <input id="image" type="file" name="image" accept=".jpg,.jpeg,.png,.webp" data-preview="#img-preview">
                    <span class="help">Optional poster or photo (JPG, PNG, WebP).</span><?= field_error('image') ?></div>
                <?php if ($img): ?><label class="checkbox mt-1"><input type="checkbox" name="remove_image" value="1"><span>Remove the image</span></label><?php endif; ?>
            </section>
        </div>
    </div>
</form>

<?php if ($event): ?>
    <section class="panel">
        <div class="panel-head"><h2>Delete</h2></div>
        <form method="post" action="<?= e(admin_url($self)) ?>" data-confirm="<?= e('Delete this ' . ($event['event_type'] === 'awareness_day' ? 'awareness day' : 'event') . ($registered ? ' and its ' . $registered . ' registrations' : '') . ' permanently?') ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $id ?>">
            <p class="muted small">To keep the record but show that it will not happen, set the status to <strong>Cancelled</strong> instead.</p>
            <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash') ?>Delete</button>
        </form>
    </section>
<?php endif; ?>
<script src="<?= e(asset('assets/js/modules/media.js')) ?>" defer></script>
<?php
admin_page_end();
