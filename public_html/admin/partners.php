<?php
/**
 * Partners & sponsors: list (partners.php) and create / edit (partners.php?id=<id> or ?id=new).
 * A partner's name and logo appear on event pages only with documented permission (display_consent).
 * Contact person, email and phone are private (admin only).
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('events.manage');

$idParam = programhub_admin_param($_GET, 'id', programhub_admin_param($_POST, 'id'));
$isNew = $idParam === 'new';
$id = $isNew ? 0 : (int) $idParam;
$pt = $id ? db_row('SELECT * FROM partners WHERE id = ?', [$id]) : null;
if ($id && !$pt) {
    flash('error', 'Partner not found.');
    admin_redirect('partners.php');
}
$editing = $isNew || $pt;
$self = 'partners.php' . ($pt ? '?id=' . $id : ($isNew ? '?id=new' : ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only($self);
    $action = programhub_admin_param($_POST, 'action', 'save');

    if ($action === 'toggle' && $pt) {
        $new = $pt['status'] === 'active' ? 'hidden' : 'active';
        db_update('partners', ['status' => $new], 'id = :id', ['id' => $id]);
        admin_log('partner_' . ($new === 'active' ? 'shown' : 'hidden'), 'Partner #' . $id . ' "' . $pt['name'] . '" ' . $new);
        flash('success', $pt['name'] . ' is now ' . ($new === 'active' ? 'active' : 'hidden from event pages') . '.');
        admin_redirect(admin_back('partners.php'));
    }
    if ($action === 'delete' && $pt) {
        $links = (int) db_value('SELECT COUNT(*) FROM event_partners WHERE partner_id = ?', [$id]);
        if ($links && empty($_POST['confirm_unlink'])) {
            flash('error', $pt['name'] . ' is linked to ' . $links . ' event' . ($links === 1 ? '' : 's') . '. Tick the confirmation to remove it from these events and delete it.');
            admin_redirect('partners.php?id=' . $id . '#delete');
        }
        db_query('DELETE FROM partners WHERE id = ?', [$id]);   // event links are removed with it (cascade)
        programhub_admin_delete_file($pt['logo']);
        admin_log('partner_deleted', 'Partner #' . $id . ' "' . $pt['name'] . '" deleted' . ($links ? ' and removed from ' . $links . ' event(s)' : ''));
        flash('success', $pt['name'] . ' was deleted.');
        admin_redirect('partners.php');
    }

    [$data, $errors] = validate($_POST, [
        'name' => 'required|min:2|max:150',
        'slug' => 'max:170',
        'partner_type' => 'required|in:' . implode(',', array_keys(PARTNER_TYPES)),
        'website_url' => 'max:255',
        'description' => 'text|max:500',
        'contact_name' => 'max:120',
        'contact_email' => 'email|max:190',
        'contact_phone' => 'phone',
        'status' => 'required|in:active,hidden',
        'sort_order' => 'integer|min:-9999|max:9999',
    ], ['partner_type' => 'Type', 'website_url' => 'Website', 'contact_email' => 'Contact email', 'contact_phone' => 'Contact mobile']);
    $website = programhub_admin_https($data['website_url'], $err, true);
    if ($err) {
        $errors['website_url'] = 'Website ' . $err;
    }
    $slugIn = $data['slug'] !== '' ? strtolower($data['slug']) : '';
    if ($slugIn !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slugIn)) {
        $errors['slug'] = 'Use lower-case letters, numbers and single hyphens only.';
    }
    if ($errors) {
        back_with_errors($errors, 'admin/' . $self, $_POST);
    }
    [$logo, $err] = programhub_admin_upload_image('logo', PROGRAMHUB_UPLOADS['partner'], $data['name'] . ' logo');
    if ($err) {
        back_with_errors(['logo' => $err], 'admin/' . $self, $_POST);
    }
    $row = [
        'name' => $data['name'], 'slug' => unique_slug('partners', $slugIn !== '' ? $slugIn : $data['name'], $id), 'partner_type' => $data['partner_type'],
        'website_url' => $website ?: null, 'description' => $data['description'] ?: null,
        'contact_name' => $data['contact_name'] ?: null, 'contact_email' => $data['contact_email'] ?: null, 'contact_phone' => $data['contact_phone'] ?: null,
        'display_consent' => !empty($_POST['display_consent']) ? 1 : 0, 'status' => $data['status'], 'sort_order' => (int) ($data['sort_order'] ?: 0),
    ];
    $oldLogo = $pt['logo'] ?? null;
    if ($logo) {
        $row['logo'] = $logo;
    } elseif (!empty($_POST['remove_logo'])) {
        $row['logo'] = null;
    }
    if ($pt) {
        db_update('partners', $row, 'id = :id', ['id' => $id]);
        if (array_key_exists('logo', $row) && $oldLogo && $oldLogo !== $row['logo']) {
            programhub_admin_delete_file($oldLogo);
        }
        admin_log('partner_updated', 'Partner #' . $id . ' "' . $data['name'] . '" saved (' . $data['status'] . ', display permission ' . ($row['display_consent'] ? 'yes' : 'no') . ')');
    } else {
        $row['created_by'] = admin_id();
        $id = db_insert('partners', $row);
        admin_log('partner_created', 'Partner #' . $id . ' "' . $data['name'] . '" created');
    }
    flash($row['display_consent'] ? 'success' : 'warning', 'Partner saved' . ($row['display_consent'] ? '.' : ' - it stays hidden on event pages until their permission to be shown is recorded.'));
    admin_redirect('partners.php?id=' . $id);
}

// ---------------------------------------------------------------------
// Edit form
// ---------------------------------------------------------------------
if ($editing) {
    $failed = old('name', null) !== null;
    $v = function (string $k, $d = '') use ($pt) {
        return old($k, $pt[$k] ?? $d);
    };
    $linked = $pt ? db_rows('SELECT e.id, e.title, e.event_type, e.status, e.start_datetime, ep.role FROM event_partners ep JOIN events e ON e.id = ep.event_id
                             WHERE ep.partner_id = ? ORDER BY e.start_datetime DESC', [$id]) : [];
    admin_page_start($pt ? 'Partner: ' . $pt['name'] : 'New partner or sponsor', [
        ['label' => 'All partners', 'url' => admin_url('partners.php'), 'icon' => 'list', 'style' => 'ghost'],
    ]);
    $logoPath = (string) ($pt['logo'] ?? '');
    $consentChecked = ($failed ? old('display_consent', null) !== null : (int) ($pt['display_consent'] ?? 0) === 1) ? ' checked' : '';
    ?>
    <div class="ph-admin">
    <form method="post" enctype="multipart/form-data" action="<?= e(admin_url($self)) ?>" data-validate>
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_UPLOAD_MB * 1024 * 1024 ?>">
        <div class="admin-grid-main">
            <div>
                <section class="panel">
                    <div class="panel-head"><h2><?= icon('briefcase') ?>Organisation</h2></div>
                    <div class="form-grid">
                        <div class="form-group"><label for="name">Name<span class="req">*</span></label>
                            <input type="text" id="name" name="name" required minlength="2" maxlength="150" value="<?= e($v('name')) ?>" data-slug-source="#slug"><?= field_error('name') ?></div>
                        <div class="form-group"><label for="partner_type">Type<span class="req">*</span></label>
                            <select id="partner_type" name="partner_type" required><?= options_html(PARTNER_TYPES, (string) $v('partner_type', 'institution'), true, '') ?></select><?= field_error('partner_type') ?></div>
                        <div class="form-group"><label for="slug">Short name (slug)</label>
                            <input type="text" id="slug" name="slug" maxlength="170" value="<?= e($v('slug')) ?>" <?= $pt ? 'data-touched="1"' : '' ?>><span class="help">Internal identifier; created from the name when empty.</span><?= field_error('slug') ?></div>
                        <div class="form-group"><label for="website_url">Website</label>
                            <input type="url" id="website_url" name="website_url" maxlength="255" value="<?= e($v('website_url')) ?>" placeholder="https://…"><?= field_error('website_url') ?></div>
                        <div class="form-group full"><label for="description">Short description</label>
                            <textarea id="description" name="description" rows="4" maxlength="500" data-counter="500"><?= e($v('description')) ?></textarea>
                            <span class="help">What the organisation does and how it works with us - as agreed with the partner.</span><?= field_error('description') ?></div>
                    </div>
                </section>
                <section class="panel ph-private">
                    <div class="panel-head"><h2><?= icon('lock') ?>Private contact</h2></div>
                    <p class="ph-private-note"><?= icon('lock') ?><span>Only staff with event access see these. They are never shown on the website.</span></p>
                    <div class="form-grid">
                        <div class="form-group"><label for="contact_name">Contact person</label><input type="text" id="contact_name" name="contact_name" maxlength="120" value="<?= e($v('contact_name')) ?>"><?= field_error('contact_name') ?></div>
                        <div class="form-group"><label for="contact_email">Email</label><input type="email" id="contact_email" name="contact_email" maxlength="190" value="<?= e($v('contact_email')) ?>" autocomplete="off"><?= field_error('contact_email') ?></div>
                        <div class="form-group"><label for="contact_phone">Mobile</label><input type="tel" id="contact_phone" name="contact_phone" maxlength="20" value="<?= e($v('contact_phone')) ?>" autocomplete="off"><?= field_error('contact_phone') ?></div>
                    </div>
                </section>
                <?php if ($pt): ?>
                    <section class="panel">
                        <div class="panel-head"><h2><?= icon('calendar') ?>Events</h2></div>
                        <?php if ($linked): ?>
                            <ul class="ph-timeline">
                                <?php foreach ($linked as $ev): ?>
                                    <li><?= icon(EVENT_TYPE_ICONS[$ev['event_type']] ?? 'calendar') ?><span><a href="<?= e(admin_url('event-edit.php?id=' . (int) $ev['id'] . '#people')) ?>"><?= e($ev['title']) ?></a> · <?= e(format_date($ev['start_datetime'], 'd M Y')) ?> · <?= e(EVENT_PARTNER_ROLES[$ev['role']] ?? humanize($ev['role'])) ?> <?= status_badge($ev['status']) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="muted small mb-0">Not linked to any event yet. Add partners in the event editor (People section).</p>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </div>
            <div>
                <div class="ph-side-sticky">
                    <section class="panel">
                        <div class="panel-head"><h2><?= icon('check-circle') ?>Visibility &amp; permission</h2></div>
                        <div class="form-group mb-2"><label for="status">Status</label>
                            <select id="status" name="status"><?= options_html(['active' => 'Active', 'hidden' => 'Hidden'], (string) $v('status', 'active'), true, '') ?></select></div>
                        <label class="checkbox mb-2"><input type="checkbox" name="display_consent" value="1"<?= $consentChecked ?>><span>We have the organisation's documented permission to show its name and logo as our partner</span></label>
                        <div class="form-group mb-2"><label for="sort_order">Order</label><input type="number" id="sort_order" name="sort_order" min="-9999" max="9999" value="<?= e((string) $v('sort_order', '0')) ?>"></div>
                        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save</button></div>
                    </section>
                    <section class="panel">
                        <div class="panel-head"><h2><?= icon('image') ?>Logo</h2></div>
                        <img id="pt-preview" src="<?= e($logoPath !== '' ? image_url($logoPath) : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7') ?>" alt="" width="200" height="100" style="width:200px;height:100px;object-fit:contain;background:#fff;border:1px solid var(--line);border-radius:10px;margin-bottom:10px"<?= $logoPath !== '' ? '' : ' hidden' ?>>
                        <div class="form-group"><label for="logo"><?= $logoPath !== '' ? 'Replace logo' : 'Upload logo' ?></label>
                            <input id="logo" type="file" name="logo" accept=".jpg,.jpeg,.png,.webp" data-preview="#pt-preview">
                            <span class="help">PNG or WebP with a transparent or white background works best.</span><?= field_error('logo') ?></div>
                        <?php if ($logoPath !== ''): ?><label class="checkbox mt-1"><input type="checkbox" name="remove_logo" value="1"><span>Remove the logo</span></label><?php endif; ?>
                    </section>
                </div>
            </div>
        </div>
    </form>
    <?php if ($pt): ?>
        <section class="panel ph-danger" id="delete">
            <div class="panel-head"><h2><?= icon('trash') ?>Delete partner</h2></div>
            <form method="post" action="<?= e(admin_url($self)) ?>" data-confirm="<?= e('Delete ' . $pt['name'] . '?') ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete">
                <?php if ($linked): ?><label class="checkbox mb-2"><input type="checkbox" name="confirm_unlink" value="1" required><span>Also remove <?= e($pt['name']) ?> from <?= count($linked) ?> event<?= count($linked) === 1 ? '' : 's' ?></span></label>
                <?php else: ?><p class="muted small">The partner and its logo are deleted permanently.</p><?php endif; ?>
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
$type = isset(PARTNER_TYPES[programhub_admin_param($_GET, 'type')]) ? (string) $_GET['type'] : '';
$params = [];
$where = 'WHERE 1=1' . search_where(['p.name', 'p.description'], $q, $params);
if ($status !== '') {
    $where .= ' AND p.status = ?';
    $params[] = $status;
}
if ($type !== '') {
    $where .= ' AND p.partner_type = ?';
    $params[] = $type;
}
$p = paginate((int) db_value("SELECT COUNT(*) FROM partners p $where", $params), 30);
$rows = db_rows("SELECT p.*, (SELECT COUNT(*) FROM event_partners ep WHERE ep.partner_id = p.id) AS events_count
                 FROM partners p $where ORDER BY p.sort_order, p.name LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$totals = db_row("SELECT COUNT(*) AS total, SUM(status = 'active') AS active, SUM(display_consent = 0) AS no_consent FROM partners");

admin_page_start('Partners & sponsors', [
    ['label' => 'New partner', 'url' => admin_url('partners.php?id=new'), 'icon' => 'plus'],
    ['label' => 'Speakers', 'url' => admin_url('speakers.php'), 'icon' => 'users', 'style' => 'ghost'],
]);
$typeSelect = '<label class="sr-only" for="f-type">Type</label><select id="f-type" name="type"><option value="">All types</option>';
foreach (PARTNER_TYPES as $k => $label) {
    $typeSelect .= '<option value="' . e($k) . '"' . ($type === $k ? ' selected' : '') . '>' . e($label) . '</option>';
}
$typeSelect .= '</select>';
?>
<div class="ph-admin">
    <div class="stat-cards">
        <div class="stat-card"><span class="icon-circle"><?= icon('briefcase') ?></span><span><strong><?= (int) $totals['total'] ?></strong><small>Partners &amp; sponsors</small></span></div>
        <div class="stat-card"><span class="icon-circle"><?= icon('check-circle') ?></span><span><strong><?= (int) $totals['active'] ?></strong><small>Active</small></span></div>
        <div class="stat-card"><span class="icon-circle gold"><?= icon('alert-triangle') ?></span><span><strong><?= (int) $totals['no_consent'] ?></strong><small>Without display permission (not shown publicly)</small></span></div>
    </div>
    <?php admin_filter_bar(['active', 'hidden'], ['placeholder' => 'Search name or description', 'html' => $typeSelect, 'active' => $type !== '']); ?>
    <section class="panel">
        <?php if ($rows): ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Partner</th><th>Type</th><th>Permission</th><th>Events</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $rid = (int) $r['id']; ?>
                    <tr>
                        <td><div class="ph-item">
                            <?php if ($r['logo']): ?><img class="ph-avatar is-logo" src="<?= e(image_url($r['logo'])) ?>" alt="" width="46" height="46" loading="lazy"><?php else: ?><span class="ph-initials" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($r['name'], 0, 1))) ?></span><?php endif; ?>
                            <div><a href="<?= e(admin_url('partners.php?id=' . $rid)) ?>"><strong><?= e($r['name']) ?></strong></a><?php if ($r['website_url']): ?><small><?= e(preg_replace('#^https?://(www\.)?#i', '', (string) $r['website_url'])) ?></small><?php endif; ?></div></div></td>
                        <td class="small"><?= e(PARTNER_TYPES[$r['partner_type']] ?? humanize($r['partner_type'])) ?></td>
                        <td><span class="ph-chip<?= (int) $r['display_consent'] ? '' : ' is-warn' ?>"><?= icon((int) $r['display_consent'] ? 'check' : 'x') ?><?= (int) $r['display_consent'] ? 'May be shown' : 'Not confirmed' ?></span></td>
                        <td class="small nowrap"><?= (int) $r['events_count'] ?> linked</td>
                        <td><form method="post" class="inline-form" action="<?= e(admin_url('partners.php?id=' . $rid)) ?>"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="toggle">
                            <button class="btn btn-ghost btn-sm" type="submit" aria-label="<?= $r['status'] === 'active' ? 'Hide' : 'Activate' ?> <?= e($r['name']) ?>"><?= status_badge($r['status']) ?></button></form></td>
                        <td class="actions-cell"><a class="icon-btn" href="<?= e(admin_url('partners.php?id=' . $rid)) ?>" aria-label="Edit <?= e($r['name']) ?>" title="Edit"><?= icon('edit') ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?= pagination_links($p) ?>
        <?php else: ?>
            <?= empty_state($q !== '' || $status !== '' || $type !== '' ? 'No partners match these filters.' : 'No partners or sponsors yet. Add an organisation only after it has agreed to be named as a partner.', 'New partner', 'admin/partners.php?id=new') ?>
        <?php endif; ?>
    </section>
    <p class="muted small"><?= icon('info') ?> Event pages show a partner only when it is active and its permission to be shown is recorded. Contact details are never shown publicly.</p>
</div>
<?php
programhub_admin_assets();
admin_page_end();
