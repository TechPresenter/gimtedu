<?php
/**
 * Testimonials. A testimonial can be published only when the person's consent is recorded
 * (enforced here on the server; the website also shows only consent_confirmed = 1 rows).
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('testimonials.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_only('testimonials.php');
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $existing = $id ? db_row('SELECT * FROM testimonials WHERE id = ?', [$id]) : null;

    if ($action === 'save') {
        $back = 'admin/testimonials.php?' . ($existing ? 'edit=' . $id : 'new=1');
        [$data, $errors] = validate($_POST, [
            'name' => 'required|min:2|max:120',
            'designation' => 'max:150',
            'message' => 'required|text|min:10|max:1500',
            'sort_order' => 'integer|min:-9999|max:9999',
            'status' => 'required|in:published,hidden',
        ], ['designation' => 'Role / relation', 'message' => 'Testimonial']);
        $consent = !empty($_POST['consent_confirmed']) ? 1 : 0;
        if ($data['status'] === 'published' && !$consent) {
            $errors['consent_confirmed'] = 'A testimonial can be published only after the person has agreed. Tick the consent box (and keep their written consent on file) or save it as hidden.';
        }
        if ($errors) {
            back_with_errors($errors, $back, $_POST);
        }
        $row = ['name' => $data['name'], 'designation' => $data['designation'] !== '' ? $data['designation'] : null, 'message' => $data['message'],
            'consent_confirmed' => $consent, 'sort_order' => (int) ($data['sort_order'] ?: 0), 'status' => $data['status']];
        $photo = admin_upload_image('photo', 'testimonials', $data['name']);
        if ($photo === null && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $_SESSION['_old'] = array_diff_key($_POST, ['csrf_token' => 1]);
            redirect($back);
        }
        if ($photo) {
            $row['photo'] = $photo;
        } elseif ($existing && !empty($_POST['remove_photo'])) {
            $row['photo'] = null;
        }
        if ($existing) {
            db_update('testimonials', $row, 'id = :id', ['id' => $id]);
            admin_log('testimonial_updated', 'Testimonial #' . $id . ' ' . $data['name'] . ' (' . $data['status'] . ', consent ' . ($consent ? 'yes' : 'no') . ')');
        } else {
            $id = db_insert('testimonials', $row);
            admin_log('testimonial_created', 'Testimonial #' . $id . ' ' . $data['name'] . ' (' . $data['status'] . ', consent ' . ($consent ? 'yes' : 'no') . ')');
        }
        flash('success', 'Testimonial saved.');
        admin_redirect('testimonials.php');
    }
    if ($action === 'toggle' && $existing) {
        if ($existing['status'] !== 'published' && !(int) $existing['consent_confirmed']) {
            flash('error', 'Record the person\'s consent (edit the testimonial) before publishing it.');
        } else {
            $new = $existing['status'] === 'published' ? 'hidden' : 'published';
            db_update('testimonials', ['status' => $new], 'id = :id', ['id' => $id]);
            admin_log('testimonial_status', 'Testimonial #' . $id . ' ' . $new);
            flash('success', $new === 'published' ? 'Testimonial published.' : 'Testimonial hidden.');
        }
    }
    if ($action === 'delete' && $existing) {
        db_query('DELETE FROM testimonials WHERE id = ?', [$id]);
        admin_log('testimonial_deleted', 'Testimonial #' . $id . ' ' . $existing['name']);
        flash('success', 'Testimonial deleted.');
    }
    admin_redirect(admin_back('testimonials.php'));
}

$edit = isset($_GET['edit']) ? db_row('SELECT * FROM testimonials WHERE id = ?', [(int) $_GET['edit']]) : null;
$showForm = $edit || isset($_GET['new']);
$rows = db_rows('SELECT * FROM testimonials ORDER BY status, sort_order, id DESC');

admin_page_start('Testimonials', [['label' => 'Add testimonial', 'url' => admin_url('testimonials.php?new=1'), 'icon' => 'plus']]);
?>
<div class="alert alert-info"><?= icon('shield') ?><div>Publish only genuine words from real people, with their permission. A testimonial appears on the website only when it is <strong>published</strong> and <strong>consent is recorded</strong>. Do not edit the meaning of what someone said.</div></div>

<div class="admin-grid-main">
    <section class="panel">
        <?php if (!$rows): ?>
            <p class="muted">No testimonials yet. Testimonial sections stay hidden on the website until one is published with consent.</p>
        <?php else: ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Person</th><th>Testimonial</th><th>Consent</th><th>Status</th><th class="actions-cell">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><strong><?= e($r['name']) ?></strong><?php if ($r['designation']): ?><br><small class="muted"><?= e($r['designation']) ?></small><?php endif; ?></td>
                        <td><?= e(excerpt($r['message'], 110)) ?></td>
                        <td><?= (int) $r['consent_confirmed'] ? '<span class="badge badge-green">Recorded</span>' : '<span class="badge badge-gold">Missing</span>' ?></td>
                        <td><?= status_badge($r['status']) ?></td>
                        <td class="actions-cell">
                            <a class="icon-btn" href="<?= e(admin_url('testimonials.php?edit=' . $r['id'])) ?>" title="Edit" aria-label="Edit testimonial from <?= e($r['name']) ?>"><?= icon('edit') ?></a>
                            <form method="post" class="inline-form"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button class="icon-btn" type="submit" title="<?= $r['status'] === 'published' ? 'Hide' : 'Publish' ?>" aria-label="<?= $r['status'] === 'published' ? 'Hide' : 'Publish' ?> testimonial"><?= icon($r['status'] === 'published' ? 'eye' : 'check') ?></button></form>
                            <form method="post" class="inline-form" data-confirm="Delete this testimonial permanently?"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button class="icon-btn danger" type="submit" title="Delete" aria-label="Delete testimonial"><?= icon('trash') ?></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head"><h2><?= $edit ? 'Edit testimonial' : 'Add a testimonial' ?></h2><?php if ($edit): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('testimonials.php')) ?>">Cancel</a><?php endif; ?></div>
        <?php if (!$showForm): ?>
            <p class="muted">Choose <strong>Add testimonial</strong> or the edit icon next to an entry.</p>
            <a class="btn btn-primary btn-sm" href="<?= e(admin_url('testimonials.php?new=1')) ?>"><?= icon('plus') ?>Add testimonial</a>
        <?php else: $t = $edit ?: []; $consentChecked = old('name', null) !== null ? old('consent_confirmed') === '1' : !empty($t['consent_confirmed']); ?>
            <form method="post" enctype="multipart/form-data" class="form-grid">
                <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($t['id'] ?? 0) ?>">
                <div class="form-group full"><label for="name">Name (as they agreed to be named)</label><input type="text" id="name" name="name" required maxlength="120" value="<?= e(old('name', $t['name'] ?? '')) ?>"><?= field_error('name') ?></div>
                <div class="form-group full"><label for="designation">Role / relation (optional)</label><input type="text" id="designation" name="designation" maxlength="150" placeholder="e.g. Volunteer teacher, Delhi" value="<?= e(old('designation', $t['designation'] ?? '')) ?>"><?= field_error('designation') ?></div>
                <div class="form-group full"><label for="message">Testimonial (their own words)</label><textarea id="message" name="message" required rows="6" maxlength="1500"><?= e(old('message', $t['message'] ?? '')) ?></textarea><?= field_error('message') ?></div>
                <div class="form-group full"><label for="photo">Photo (optional, only with permission)</label>
                    <?php if (!empty($t['photo']) && is_file(ROOT_PATH . '/' . $t['photo'])): ?><img src="<?= e(url($t['photo'])) ?>" alt="Current photo" class="thumb" style="width:64px;height:64px;border-radius:50%"><label class="checkbox"><input type="checkbox" name="remove_photo" value="1"><span>Remove current photo</span></label><?php endif; ?>
                    <input id="photo" type="file" name="photo" accept=".jpg,.jpeg,.png,.webp"></div>
                <div class="form-group full"><label class="checkbox"><input type="checkbox" name="consent_confirmed" value="1"<?= $consentChecked ? ' checked' : '' ?>>
                    <span><strong>Consent recorded:</strong> this person has agreed (preferably in writing) that their name, words and photo may be published on the website. Keep the consent on file.</span></label><?= field_error('consent_confirmed') ?></div>
                <div class="form-group"><label for="status">Status</label><select id="status" name="status"><?= options_html(['hidden' => 'Hidden', 'published' => 'Published'], (string) old('status', $t['status'] ?? 'hidden'), true, '') ?></select></div>
                <div class="form-group"><label for="sort_order">Order</label><input id="sort_order" type="number" name="sort_order" value="<?= e(old('sort_order', (string) ($t['sort_order'] ?? 0))) ?>"></div>
                <div class="form-actions full" style="grid-column:1/-1"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save testimonial</button></div>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php
admin_page_end();
