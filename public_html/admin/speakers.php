<?php
/**
 * Speakers & trainers: list (speakers.php) and create / edit (speakers.php?id=<id> or ?id=new).
 * A profile appears on event pages only with profile consent; the photo only with photo consent.
 * Email and phone are private (admin only).
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('events.manage');

$idParam = programhub_admin_param($_GET, 'id', programhub_admin_param($_POST, 'id'));
$isNew = $idParam === 'new';
$id = $isNew ? 0 : (int) $idParam;
$sp = $id ? db_row('SELECT * FROM speakers WHERE id = ?', [$id]) : null;
if ($id && !$sp) {
    flash('error', 'Speaker not found.');
    admin_redirect('speakers.php');
}
$editing = $isNew || $sp;
$self = 'speakers.php' . ($sp ? '?id=' . $id : ($isNew ? '?id=new' : ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only($self);
    $action = programhub_admin_param($_POST, 'action', 'save');

    if ($action === 'toggle' && $sp) {
        $new = $sp['status'] === 'active' ? 'hidden' : 'active';
        db_update('speakers', ['status' => $new], 'id = :id', ['id' => $id]);
        admin_log('speaker_' . ($new === 'active' ? 'shown' : 'hidden'), 'Speaker #' . $id . ' "' . $sp['name'] . '" ' . $new);
        flash('success', $sp['name'] . ' is now ' . ($new === 'active' ? 'active' : 'hidden from event pages') . '.');
        admin_redirect(admin_back('speakers.php'));
    }
    if ($action === 'delete' && $sp) {
        $links = (int) db_value('SELECT COUNT(*) FROM event_speakers WHERE speaker_id = ?', [$id]);
        if ($links && empty($_POST['confirm_unlink'])) {
            flash('error', $sp['name'] . ' is linked to ' . $links . ' event' . ($links === 1 ? '' : 's') . '. Tick the confirmation to remove them from these events and delete the profile.');
            admin_redirect('speakers.php?id=' . $id . '#delete');
        }
        db_query('DELETE FROM speakers WHERE id = ?', [$id]);   // event links are removed with it (cascade)
        programhub_admin_delete_file($sp['photo']);
        admin_log('speaker_deleted', 'Speaker #' . $id . ' "' . $sp['name'] . '" deleted' . ($links ? ' and removed from ' . $links . ' event(s)' : ''));
        flash('success', 'The profile of ' . $sp['name'] . ' was deleted.');
        admin_redirect('speakers.php');
    }

    [$data, $errors] = validate($_POST, [
        'name' => 'required|min:2|max:120',
        'slug' => 'max:140',
        'designation' => 'max:150',
        'organisation' => 'max:150',
        'bio' => 'text|max:2000',
        'website_url' => 'max:255',
        'linkedin_url' => 'max:255',
        'email' => 'email|max:190',
        'phone' => 'phone',
        'status' => 'required|in:active,hidden',
        'sort_order' => 'integer|min:-9999|max:9999',
    ], ['website_url' => 'Website', 'linkedin_url' => 'LinkedIn profile', 'phone' => 'Mobile']);
    $website = programhub_admin_https($data['website_url'], $err, true);
    if ($err) {
        $errors['website_url'] = 'Website ' . $err;
    }
    $linkedin = programhub_admin_https($data['linkedin_url'], $err);
    if ($err) {
        $errors['linkedin_url'] = 'LinkedIn profile ' . $err;
    } elseif ($linkedin !== '' && !preg_match('#^https://([a-z]{2,3}\.)?linkedin\.com/#i', $linkedin)) {
        $errors['linkedin_url'] = 'Please enter a linkedin.com profile address (https://www.linkedin.com/in/…).';
    }
    $slugIn = $data['slug'] !== '' ? strtolower($data['slug']) : '';
    if ($slugIn !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slugIn)) {
        $errors['slug'] = 'Use lower-case letters, numbers and single hyphens only.';
    }
    if ($errors) {
        back_with_errors($errors, 'admin/' . $self, $_POST);
    }
    [$photo, $err] = programhub_admin_upload_image('photo', PROGRAMHUB_UPLOADS['speaker'], $data['name']);
    if ($err) {
        back_with_errors(['photo' => $err], 'admin/' . $self, $_POST);
    }
    $row = [
        'name' => $data['name'], 'slug' => unique_slug('speakers', $slugIn !== '' ? $slugIn : $data['name'], $id),
        'designation' => $data['designation'] ?: null, 'organisation' => $data['organisation'] ?: null, 'bio' => $data['bio'] ?: null,
        'website_url' => $website ?: null, 'linkedin_url' => $linkedin ?: null, 'email' => $data['email'] ?: null, 'phone' => $data['phone'] ?: null,
        'profile_consent' => !empty($_POST['profile_consent']) ? 1 : 0, 'photo_consent' => !empty($_POST['photo_consent']) ? 1 : 0,
        'status' => $data['status'], 'sort_order' => (int) ($data['sort_order'] ?: 0),
    ];
    $oldPhoto = $sp['photo'] ?? null;
    if ($photo) {
        $row['photo'] = $photo;
    } elseif (!empty($_POST['remove_photo'])) {
        $row['photo'] = null;
    }
    if ($sp) {
        db_update('speakers', $row, 'id = :id', ['id' => $id]);
        if (array_key_exists('photo', $row) && $oldPhoto && $oldPhoto !== $row['photo']) {
            programhub_admin_delete_file($oldPhoto);
        }
        admin_log('speaker_updated', 'Speaker #' . $id . ' "' . $data['name'] . '" saved (' . $data['status'] . ', profile consent ' . ($row['profile_consent'] ? 'yes' : 'no') . ', photo consent ' . ($row['photo_consent'] ? 'yes' : 'no') . ')');
    } else {
        $row['created_by'] = admin_id();
        $id = db_insert('speakers', $row);
        admin_log('speaker_created', 'Speaker #' . $id . ' "' . $data['name'] . '" created');
    }
    $warn = [];
    if (!$row['profile_consent']) {
        $warn[] = 'the profile stays hidden on event pages until the speaker\'s consent is recorded';
    } elseif (($row['photo'] ?? $oldPhoto) && !$row['photo_consent']) {
        $warn[] = 'the photo is not shown publicly until photo consent is recorded';
    }
    flash($warn ? 'warning' : 'success', 'Profile saved' . ($warn ? ' - ' . implode('; ', $warn) : '') . '.');
    admin_redirect('speakers.php?id=' . $id);
}

// ---------------------------------------------------------------------
// Edit form
// ---------------------------------------------------------------------
if ($editing) {
    $failed = old('name', null) !== null;
    $v = function (string $k, $d = '') use ($sp) {
        return old($k, $sp[$k] ?? $d);
    };
    $chk = function (string $k) use ($failed, $sp): string {
        return ($failed ? old($k, null) !== null : (int) ($sp[$k] ?? 0) === 1) ? ' checked' : '';
    };
    $linked = $sp ? db_rows('SELECT e.id, e.title, e.slug, e.event_type, e.status, e.start_datetime, es.role FROM event_speakers es JOIN events e ON e.id = es.event_id
                             WHERE es.speaker_id = ? ORDER BY e.start_datetime DESC', [$id]) : [];
    admin_page_start($sp ? 'Speaker: ' . $sp['name'] : 'New speaker or trainer', [
        ['label' => 'All speakers', 'url' => admin_url('speakers.php'), 'icon' => 'list', 'style' => 'ghost'],
    ]);
    $photo = (string) ($sp['photo'] ?? '');
    ?>
    <div class="ph-admin">
    <form method="post" enctype="multipart/form-data" action="<?= e(admin_url($self)) ?>" data-validate>
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_UPLOAD_MB * 1024 * 1024 ?>">
        <div class="admin-grid-main">
            <div>
                <section class="panel">
                    <div class="panel-head"><h2><?= icon('user') ?>Public profile</h2></div>
                    <div class="form-grid">
                        <div class="form-group"><label for="name">Name<span class="req">*</span></label>
                            <input type="text" id="name" name="name" required minlength="2" maxlength="120" value="<?= e($v('name')) ?>" data-slug-source="#slug"><?= field_error('name') ?></div>
                        <div class="form-group"><label for="slug">Short name (slug)</label>
                            <input type="text" id="slug" name="slug" maxlength="140" value="<?= e($v('slug')) ?>" <?= $sp ? 'data-touched="1"' : '' ?>><span class="help">Internal identifier; created from the name when empty.</span><?= field_error('slug') ?></div>
                        <div class="form-group"><label for="designation">Designation / role</label>
                            <input type="text" id="designation" name="designation" maxlength="150" value="<?= e($v('designation')) ?>" placeholder="e.g. Certified yoga instructor"><?= field_error('designation') ?></div>
                        <div class="form-group"><label for="organisation">Organisation</label>
                            <input type="text" id="organisation" name="organisation" maxlength="150" value="<?= e($v('organisation')) ?>"><?= field_error('organisation') ?></div>
                        <div class="form-group full"><label for="bio">Short biography</label>
                            <textarea id="bio" name="bio" rows="6" maxlength="2000" data-counter="2000"><?= e($v('bio')) ?></textarea>
                            <span class="help">Plain text, written or approved by the speaker. Only facts they have confirmed.</span><?= field_error('bio') ?></div>
                        <div class="form-group"><label for="website_url">Website</label>
                            <input type="url" id="website_url" name="website_url" maxlength="255" value="<?= e($v('website_url')) ?>" placeholder="https://…"><?= field_error('website_url') ?></div>
                        <div class="form-group"><label for="linkedin_url">LinkedIn profile</label>
                            <input type="url" id="linkedin_url" name="linkedin_url" maxlength="255" value="<?= e($v('linkedin_url')) ?>" placeholder="https://www.linkedin.com/in/…"><?= field_error('linkedin_url') ?></div>
                    </div>
                </section>
                <section class="panel ph-private">
                    <div class="panel-head"><h2><?= icon('lock') ?>Private contact details</h2></div>
                    <p class="ph-private-note"><?= icon('lock') ?><span>Only staff with event access see these. They are never shown on the website or in exports.</span></p>
                    <div class="form-grid">
                        <div class="form-group"><label for="email">Email</label><input type="email" id="email" name="email" maxlength="190" value="<?= e($v('email')) ?>" autocomplete="off"><?= field_error('email') ?></div>
                        <div class="form-group"><label for="phone">Mobile</label><input type="tel" id="phone" name="phone" maxlength="20" value="<?= e($v('phone')) ?>" autocomplete="off"><?= field_error('phone') ?></div>
                    </div>
                </section>
                <?php if ($sp): ?>
                    <section class="panel">
                        <div class="panel-head"><h2><?= icon('calendar') ?>Events</h2></div>
                        <?php if ($linked): ?>
                            <ul class="ph-timeline">
                                <?php foreach ($linked as $ev): ?>
                                    <li><?= icon(EVENT_TYPE_ICONS[$ev['event_type']] ?? 'calendar') ?><span><a href="<?= e(admin_url('event-edit.php?id=' . (int) $ev['id'] . '#people')) ?>"><?= e($ev['title']) ?></a> · <?= e(format_date($ev['start_datetime'], 'd M Y')) ?> · <?= e(SPEAKER_ROLES[$ev['role']] ?? humanize($ev['role'])) ?> <?= status_badge($ev['status']) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="muted small mb-0">Not linked to any event yet. Add speakers in the event editor (People section).</p>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </div>
            <div>
                <div class="ph-side-sticky">
                    <section class="panel">
                        <div class="panel-head"><h2><?= icon('check-circle') ?>Visibility &amp; consent</h2></div>
                        <div class="form-group mb-2"><label for="status">Status</label>
                            <select id="status" name="status"><?= options_html(['active' => 'Active', 'hidden' => 'Hidden'], (string) $v('status', 'active'), true, '') ?></select></div>
                        <label class="checkbox mb-2"><input type="checkbox" name="profile_consent" value="1"<?= $chk('profile_consent') ?>><span>The speaker agreed that their name and profile may be shown on the website</span></label>
                        <label class="checkbox mb-2"><input type="checkbox" name="photo_consent" value="1"<?= $chk('photo_consent') ?>><span>The speaker gave consent for this photo to be published</span></label>
                        <div class="form-group mb-2"><label for="sort_order">Order</label><input type="number" id="sort_order" name="sort_order" min="-9999" max="9999" value="<?= e((string) $v('sort_order', '0')) ?>"></div>
                        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save</button></div>
                    </section>
                    <section class="panel">
                        <div class="panel-head"><h2><?= icon('image') ?>Photo</h2></div>
                        <img id="sp-preview" src="<?= e($photo !== '' ? image_url($photo) : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7') ?>" alt="" width="160" height="160" style="width:160px;height:160px;border-radius:50%;object-fit:cover;background:var(--green-50);margin-bottom:10px"<?= $photo !== '' ? '' : ' hidden' ?>>
                        <div class="form-group"><label for="photo"><?= $photo !== '' ? 'Replace photo' : 'Upload photo' ?></label>
                            <input id="photo" type="file" name="photo" accept=".jpg,.jpeg,.png,.webp" data-preview="#sp-preview">
                            <span class="help">Square, at least 400 × 400 px. Shown only with photo consent.</span><?= field_error('photo') ?></div>
                        <?php if ($photo !== ''): ?><label class="checkbox mt-1"><input type="checkbox" name="remove_photo" value="1"><span>Remove the photo</span></label><?php endif; ?>
                    </section>
                </div>
            </div>
        </div>
    </form>
    <?php if ($sp): ?>
        <section class="panel ph-danger" id="delete">
            <div class="panel-head"><h2><?= icon('trash') ?>Delete profile</h2></div>
            <form method="post" action="<?= e(admin_url($self)) ?>" data-confirm="<?= e('Delete the profile of ' . $sp['name'] . '?') ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete">
                <?php if ($linked): ?><label class="checkbox mb-2"><input type="checkbox" name="confirm_unlink" value="1" required><span>Also remove <?= e($sp['name']) ?> from <?= count($linked) ?> event<?= count($linked) === 1 ? '' : 's' ?></span></label>
                <?php else: ?><p class="muted small">The profile and its photo are deleted permanently.</p><?php endif; ?>
                <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash') ?>Delete</button>
            </form>
        </section>
    <?php endif; ?>
    </div>
    <?php
    programhub_admin_assets();
    admin_page_end();
    exit;
}

// ---------------------------------------------------------------------
// List
// ---------------------------------------------------------------------
$q = trim(mb_substr(clean_string($_GET['q'] ?? ''), 0, 100));
$status = in_array($_GET['status'] ?? '', ['active', 'hidden'], true) ? (string) $_GET['status'] : '';
$consent = in_array($_GET['consent'] ?? '', ['missing', 'given'], true) ? (string) $_GET['consent'] : '';
$params = [];
$where = 'WHERE 1=1' . search_where(['s.name', 's.designation', 's.organisation'], $q, $params);
if ($status !== '') {
    $where .= ' AND s.status = ?';
    $params[] = $status;
}
if ($consent === 'missing') {
    $where .= ' AND s.profile_consent = 0';
} elseif ($consent === 'given') {
    $where .= ' AND s.profile_consent = 1';
}
$p = paginate((int) db_value("SELECT COUNT(*) FROM speakers s $where", $params), 30);
$rows = db_rows("SELECT s.*, (SELECT COUNT(*) FROM event_speakers es WHERE es.speaker_id = s.id) AS events_count,
                        (SELECT COUNT(*) FROM event_speakers es JOIN events e ON e.id = es.event_id WHERE es.speaker_id = s.id AND e.status = 'published' AND COALESCE(e.end_datetime, e.start_datetime) >= NOW()) AS upcoming
                 FROM speakers s $where ORDER BY s.sort_order, s.name LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$totals = db_row("SELECT COUNT(*) AS total, SUM(status = 'active') AS active, SUM(profile_consent = 0) AS no_consent FROM speakers");

admin_page_start('Speakers & trainers', [
    ['label' => 'New speaker', 'url' => admin_url('speakers.php?id=new'), 'icon' => 'plus'],
    ['label' => 'Partners', 'url' => admin_url('partners.php'), 'icon' => 'briefcase', 'style' => 'ghost'],
]);
$consentSelect = '<label class="sr-only" for="f-consent">Consent</label><select id="f-consent" name="consent"><option value="">Any consent</option>'
    . '<option value="given"' . ($consent === 'given' ? ' selected' : '') . '>Profile consent given</option>'
    . '<option value="missing"' . ($consent === 'missing' ? ' selected' : '') . '>Profile consent missing</option></select>';
?>
<div class="ph-admin">
    <div class="stat-cards">
        <div class="stat-card"><span class="icon-circle"><?= icon('users') ?></span><span><strong><?= (int) $totals['total'] ?></strong><small>Profiles</small></span></div>
        <div class="stat-card"><span class="icon-circle"><?= icon('check-circle') ?></span><span><strong><?= (int) $totals['active'] ?></strong><small>Active</small></span></div>
        <a class="stat-card" href="<?= e(admin_url('speakers.php?consent=missing')) ?>"><span class="icon-circle gold"><?= icon('alert-triangle') ?></span><span><strong><?= (int) $totals['no_consent'] ?></strong><small>Without profile consent (not shown publicly)</small></span></a>
    </div>
    <?php admin_filter_bar(['active', 'hidden'], ['placeholder' => 'Search name, designation or organisation', 'html' => $consentSelect, 'active' => $consent !== '']); ?>
    <section class="panel">
        <?php if ($rows): ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Speaker / trainer</th><th>Consent</th><th>Events</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $rid = (int) $r['id']; ?>
                    <tr>
                        <td><div class="ph-item">
                            <?php if ($r['photo']): ?><img class="ph-avatar" src="<?= e(image_url($r['photo'])) ?>" alt="" width="46" height="46" loading="lazy"><?php else: ?><span class="ph-initials" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($r['name'], 0, 1))) ?></span><?php endif; ?>
                            <div><a href="<?= e(admin_url('speakers.php?id=' . $rid)) ?>"><strong><?= e($r['name']) ?></strong></a><small><?= e(trim(($r['designation'] ?: '') . ($r['designation'] && $r['organisation'] ? ', ' : '') . ($r['organisation'] ?: ''))) ?: '&nbsp;' ?></small></div></div></td>
                        <td><div class="ph-chips">
                            <span class="ph-chip<?= (int) $r['profile_consent'] ? '' : ' is-warn' ?>"><?= icon((int) $r['profile_consent'] ? 'check' : 'x') ?>Profile</span>
                            <?php if ($r['photo']): ?><span class="ph-chip<?= (int) $r['photo_consent'] ? '' : ' is-warn' ?>"><?= icon((int) $r['photo_consent'] ? 'check' : 'x') ?>Photo</span><?php endif; ?>
                        </div></td>
                        <td class="small nowrap"><?= (int) $r['events_count'] ?> linked<?= (int) $r['upcoming'] ? '<br><span class="muted">' . (int) $r['upcoming'] . ' upcoming</span>' : '' ?></td>
                        <td><form method="post" class="inline-form" action="<?= e(admin_url('speakers.php?id=' . $rid)) ?>"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="toggle">
                            <button class="btn btn-ghost btn-sm" type="submit" aria-label="<?= $r['status'] === 'active' ? 'Hide' : 'Activate' ?> <?= e($r['name']) ?>"><?= status_badge($r['status']) ?></button></form></td>
                        <td class="actions-cell"><a class="icon-btn" href="<?= e(admin_url('speakers.php?id=' . $rid)) ?>" aria-label="Edit <?= e($r['name']) ?>" title="Edit"><?= icon('edit') ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?= pagination_links($p) ?>
        <?php else: ?>
            <?= empty_state($q !== '' || $status !== '' || $consent !== '' ? 'No profiles match these filters.' : 'No speakers or trainers yet. Add the people who lead your sessions - with their consent to be shown.', 'New speaker', 'admin/speakers.php?id=new') ?>
        <?php endif; ?>
    </section>
    <p class="muted small"><?= icon('info') ?> Event pages show a speaker only when the profile is active and the speaker's consent is recorded; photos need separate photo consent. Contact details are never shown publicly.</p>
</div>
<?php
programhub_admin_assets();
admin_page_end();
