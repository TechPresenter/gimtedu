<?php
/**
 * Photo & video gallery: upload several photos at once, add YouTube videos, edit title / category /
 * description / order, hide or show, delete. An item can be published only with the confirmation
 * "No identifiable children/vulnerable people, or documented consent obtained" (enforced server side).
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('gallery.manage');

const GALLERY_CONSENT_LABEL = 'No identifiable children/vulnerable people, or documented consent obtained';
$type = ($_GET['type'] ?? $_POST['type'] ?? '') === 'video' ? 'video' : 'image';
$self = 'gallery.php' . ($type === 'video' ? '?type=video' : '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only($self);
    $action = (string) ($_POST['action'] ?? '');
    $consent = !empty($_POST['consent_confirmed']) ? 1 : 0;

    // ----- Upload photos -------------------------------------------------
    if ($action === 'upload') {
        [$data, $errors] = validate($_POST, [
            'title' => 'required|min:2|max:150',
            'category' => 'required|in:' . implode(',', array_keys(GALLERY_CATEGORIES)),
            'description' => 'required|min:5|max:300',
            'sort_order' => 'integer|min:-9999|max:9999',
        ], ['description' => 'Description (alt text)']);
        $files = $_FILES['images'] ?? null;
        $count = $files && is_array($files['name'] ?? null) ? count($files['name']) : 0;
        $chosen = 0;
        for ($i = 0; $i < $count; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $chosen++;
            }
        }
        if (!$chosen) {
            $errors['images'] = 'Choose at least one photo to upload.';
        }
        if ($errors) {
            back_with_errors($errors, 'admin/' . $self, $_POST);
        }
        $added = 0;
        $failed = [];
        $n = 0;
        for ($i = 0; $i < $count; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $n++;
            $_FILES['_gallery_one'] = ['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i], 'size' => $files['size'][$i]];
            $title = $chosen > 1 ? mb_substr($data['title'], 0, 142) . ' (' . $n . ')' : $data['title'];
            $before = count($_SESSION['_flash'] ?? []);
            $path = admin_upload_image('_gallery_one', 'gallery', $data['description']);
            if (!$path) {
                $msg = 'could not be uploaded';
                if (count($_SESSION['_flash'] ?? []) > $before) {
                    $last = array_pop($_SESSION['_flash']);
                    $msg = $last['message'] ?? $msg;
                }
                $failed[] = $files['name'][$i] . ': ' . $msg;
                continue;
            }
            $newId = db_insert('gallery_items', [
                'media_type' => 'image', 'title' => $title, 'category' => $data['category'], 'image_path' => $path,
                'description' => $data['description'], 'consent_confirmed' => $consent, 'sort_order' => (int) ($data['sort_order'] ?: 0),
                'status' => $consent ? 'published' : 'hidden', 'uploaded_by' => admin_id(),
            ]);
            admin_log('gallery_uploaded', 'Photo #' . $newId . ' "' . $title . '" (' . $data['category'] . ', ' . ($consent ? 'published' : 'hidden - no consent confirmation') . ')');
            $added++;
        }
        unset($_FILES['_gallery_one']);
        if ($added) {
            flash($consent ? 'success' : 'warning', $added . ' photo' . ($added === 1 ? '' : 's') . ' added' . ($consent ? ' and published.' : ' but kept hidden: tick the consent confirmation to publish them.'));
        }
        if ($failed) {
            flash('error', 'Not uploaded - ' . implode('; ', array_slice($failed, 0, 5)) . (count($failed) > 5 ? ' …' : ''));
        }
        admin_redirect($self);
    }

    // ----- Add a YouTube video --------------------------------------------
    if ($action === 'add_video') {
        [$data, $errors] = validate($_POST, [
            'video_url' => 'required|url|max:255',
            'title' => 'required|min:2|max:150',
            'category' => 'required|in:' . implode(',', array_keys(GALLERY_CATEGORIES)),
            'description' => 'max:300',
            'sort_order' => 'integer|min:-9999|max:9999',
        ], ['video_url' => 'YouTube link']);
        $ytId = $errors ? '' : youtube_id($data['video_url']);
        if (!$errors && $ytId === '') {
            $errors['video_url'] = 'Please paste a YouTube video link (youtube.com/watch?v=…, youtu.be/…, or a Shorts link).';
        }
        if ($errors) {
            back_with_errors($errors, 'admin/gallery.php?type=video', $_POST);
        }
        $newId = db_insert('gallery_items', [
            'media_type' => 'video', 'title' => $data['title'], 'category' => $data['category'],
            'video_url' => 'https://www.youtube.com/watch?v=' . $ytId, 'description' => $data['description'] !== '' ? $data['description'] : null,
            'consent_confirmed' => $consent, 'sort_order' => (int) ($data['sort_order'] ?: 0), 'status' => $consent ? 'published' : 'hidden',
            'uploaded_by' => admin_id(),
        ]);
        admin_log('gallery_video_added', 'Video #' . $newId . ' "' . $data['title'] . '" (' . $ytId . ', ' . ($consent ? 'published' : 'hidden - no consent confirmation') . ')');
        flash($consent ? 'success' : 'warning', $consent ? 'Video added and published.' : 'Video added but kept hidden: tick the consent confirmation to publish it.');
        admin_redirect('gallery.php?type=video');
    }

    // ----- Edit / show-hide / delete an item ------------------------------
    $id = (int) ($_POST['id'] ?? 0);
    $item = $id ? db_row('SELECT * FROM gallery_items WHERE id = ?', [$id]) : null;
    if (!$item) {
        flash('error', 'Gallery item not found.');
        admin_redirect($self);
    }
    $back = admin_back($self);
    $label = ($item['media_type'] === 'video' ? 'Video' : 'Photo') . ' #' . $id . ' "' . $item['title'] . '"';

    if ($action === 'delete') {
        db_query('DELETE FROM gallery_items WHERE id = ?', [$id]);
        if ($item['image_path']) {
            content_delete_image($item['image_path']);
        }
        admin_log('gallery_deleted', $label . ' deleted');
        flash('success', $label . ' deleted.');
        admin_redirect($back);
    }
    if ($action === 'toggle') {
        if ($item['status'] === 'published') {
            db_update('gallery_items', ['status' => 'hidden'], 'id = :id', ['id' => $id]);
            admin_log('gallery_hidden', $label . ' hidden');
            flash('success', $label . ' is now hidden.');
        } elseif ((int) $item['consent_confirmed'] !== 1) {
            flash('error', 'Confirm "' . GALLERY_CONSENT_LABEL . '" (edit the item) before publishing it.');
        } else {
            db_update('gallery_items', ['status' => 'published'], 'id = :id', ['id' => $id]);
            admin_log('gallery_published', $label . ' published');
            flash('success', $label . ' is now visible on the website.');
        }
        admin_redirect($back);
    }
    if ($action === 'update') {
        [$data, $errors] = validate($_POST, [
            'title' => 'required|min:2|max:150',
            'category' => 'required|in:' . implode(',', array_keys(GALLERY_CATEGORIES)),
            'description' => ($item['media_type'] === 'image' ? 'required|min:5|' : '') . 'max:300',
            'sort_order' => 'integer|min:-9999|max:9999',
            'status' => 'required|in:published,hidden',
        ], ['description' => 'Description']);
        if (!$errors && $data['status'] === 'published' && !$consent) {
            $errors['consent_confirmed'] = 'To publish, confirm: ' . GALLERY_CONSENT_LABEL . '.';
        }
        if ($errors) {
            flash('error', $label . ': ' . reset($errors));
            admin_redirect($back);
        }
        db_update('gallery_items', [
            'title' => $data['title'], 'category' => $data['category'], 'description' => $data['description'] !== '' ? $data['description'] : null,
            'sort_order' => (int) ($data['sort_order'] ?: 0), 'consent_confirmed' => $consent, 'status' => $data['status'],
        ], 'id = :id', ['id' => $id]);
        admin_log('gallery_updated', $label . ' updated (' . $data['status'] . ', ' . $data['category'] . ')');
        flash('success', 'Saved: ' . $data['title'] . '.');
        admin_redirect($back);
    }
    admin_redirect($back);
}

// ---------------------------------------------------------------------
// List
// ---------------------------------------------------------------------
$category = isset(GALLERY_CATEGORIES[$_GET['category'] ?? '']) ? (string) $_GET['category'] : '';
$status = in_array($_GET['status'] ?? '', ['published', 'hidden'], true) ? (string) $_GET['status'] : '';
$q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 100));
$where = 'WHERE media_type = ?';
$params = [$type];
if ($category !== '') {
    $where .= ' AND category = ?';
    $params[] = $category;
}
if ($status !== '') {
    $where .= ' AND status = ?';
    $params[] = $status;
}
$where .= search_where(['title', 'description'], $q, $params);
$p = paginate((int) db_value("SELECT COUNT(*) FROM gallery_items $where", $params), 24);
$items = db_rows("SELECT * FROM gallery_items $where ORDER BY sort_order, id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$tabCounts = ['image' => 0, 'video' => 0];
foreach (db_rows('SELECT media_type, COUNT(*) AS n FROM gallery_items GROUP BY media_type') as $c) {
    $tabCounts[$c['media_type']] = (int) $c['n'];
}
$catOptions = function (string $selected): string {
    return options_html(GALLERY_CATEGORIES, $selected, true, '');
};

admin_page_start('Photo & Video Gallery', [['label' => 'View ' . ($type === 'video' ? 'video' : 'photo') . ' gallery', 'url' => page_url($type === 'video' ? 'video-gallery' : 'gallery'), 'icon' => 'external', 'style' => 'ghost']]);
?>
<nav class="admin-tabs" aria-label="Gallery type">
    <a class="<?= $type === 'image' ? 'active' : '' ?>" href="<?= e(admin_url('gallery.php')) ?>">Photos (<?= $tabCounts['image'] ?>)</a>
    <a class="<?= $type === 'video' ? 'active' : '' ?>" href="<?= e(admin_url('gallery.php?type=video')) ?>">Videos (<?= $tabCounts['video'] ?>)</a>
</nav>

<div class="alert alert-info"><?= icon('shield') ?><div>Publish photos and videos of identifiable children or vulnerable people <strong>only with documented consent</strong> (a parent or guardian's for children). Items without the confirmation are kept hidden.</div></div>

<?php if ($type === 'image'): ?>
    <section class="panel" id="upload">
        <div class="panel-head"><h2>Upload photos</h2><span class="muted small">Up to 20 photos at a time · JPG, PNG or WebP · max <?= e(upload_limit_label()) ?> MB each</span></div>
        <form method="post" enctype="multipart/form-data" action="<?= e(admin_url('gallery.php')) ?>" class="form-grid" data-validate>
            <?= csrf_field() ?><input type="hidden" name="action" value="upload">
            <div class="form-group full"><label for="images">Photos<span class="req">*</span></label>
                <input id="images" type="file" name="images[]" accept=".jpg,.jpeg,.png,.webp" multiple required><?= field_error('images') ?></div>
            <div class="form-group"><label for="up-title">Title<span class="req">*</span></label>
                <input type="text" id="up-title" name="title" required minlength="2" maxlength="150" value="<?= e(old('title')) ?>" placeholder="e.g. Health camp, Sector 4">
                <span class="help">With several photos, a number is added: "Title (1)", "Title (2)"…</span><?= field_error('title') ?></div>
            <div class="form-group"><label for="up-category">Category<span class="req">*</span></label>
                <select id="up-category" name="category" required><?= $catOptions((string) old('category', 'general')) ?></select><?= field_error('category') ?></div>
            <div class="form-group full"><label for="up-description">Description (alt text)<span class="req">*</span></label>
                <input type="text" id="up-description" name="description" required minlength="5" maxlength="300" value="<?= e(old('description')) ?>" placeholder="Describe what the photo shows, for people using screen readers">
                <?= field_error('description') ?></div>
            <div class="form-group"><label for="up-sort">Sort order</label>
                <input id="up-sort" type="number" name="sort_order" min="-9999" max="9999" value="<?= e(old('sort_order', '0')) ?>"><span class="help">Lower numbers are shown first.</span></div>
            <div class="form-group full"><label class="checkbox"><input type="checkbox" name="consent_confirmed" value="1"<?= old('consent_confirmed', null) !== null ? ' checked' : '' ?>><span><strong><?= e(GALLERY_CONSENT_LABEL) ?></strong> - required to publish. Without it the photos are saved as hidden.</span></label></div>
            <div class="form-actions full" style="margin-top:4px"><button class="btn btn-primary" type="submit"><?= icon('upload') ?>Upload photos</button></div>
        </form>
    </section>
<?php else: ?>
    <section class="panel" id="add-video">
        <div class="panel-head"><h2>Add a YouTube video</h2><span class="muted small">Videos play in privacy-enhanced mode (youtube-nocookie.com) only after a visitor presses play.</span></div>
        <form method="post" action="<?= e(admin_url('gallery.php?type=video')) ?>" class="form-grid" data-validate>
            <?= csrf_field() ?><input type="hidden" name="action" value="add_video"><input type="hidden" name="type" value="video">
            <div class="form-group full"><label for="video_url">YouTube link<span class="req">*</span></label>
                <input id="video_url" type="url" name="video_url" required maxlength="255" value="<?= e(old('video_url')) ?>" placeholder="https://www.youtube.com/watch?v=…"><?= field_error('video_url') ?></div>
            <div class="form-group"><label for="v-title">Title<span class="req">*</span></label>
                <input type="text" id="v-title" name="title" required minlength="2" maxlength="150" value="<?= e(old('title')) ?>"><?= field_error('title') ?></div>
            <div class="form-group"><label for="v-category">Category<span class="req">*</span></label>
                <select id="v-category" name="category" required><?= $catOptions((string) old('category', 'general')) ?></select><?= field_error('category') ?></div>
            <div class="form-group full"><label for="v-description">Short description</label>
                <input type="text" id="v-description" name="description" maxlength="300" value="<?= e(old('description')) ?>"><?= field_error('description') ?></div>
            <div class="form-group"><label for="v-sort">Sort order</label>
                <input id="v-sort" type="number" name="sort_order" min="-9999" max="9999" value="<?= e(old('sort_order', '0')) ?>"></div>
            <div class="form-group full"><label class="checkbox"><input type="checkbox" name="consent_confirmed" value="1"<?= old('consent_confirmed', null) !== null ? ' checked' : '' ?>><span><strong><?= e(GALLERY_CONSENT_LABEL) ?></strong> - required to publish.</span></label></div>
            <div class="form-actions full" style="margin-top:4px"><button class="btn btn-primary" type="submit"><?= icon('plus') ?>Add video</button></div>
        </form>
    </section>
<?php endif; ?>

<form class="filter-row" method="get">
    <?php if ($type === 'video'): ?><input type="hidden" name="type" value="video"><?php endif; ?>
    <label class="sr-only" for="f-q">Search</label>
    <input id="f-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Search title or description">
    <label class="sr-only" for="f-category">Category</label>
    <select id="f-category" name="category"><option value="">All categories</option><?php foreach (GALLERY_CATEGORIES as $k => $label) { echo '<option value="' . e($k) . '"' . ($category === $k ? ' selected' : '') . '>' . e($label) . '</option>'; } ?></select>
    <label class="sr-only" for="f-status">Status</label>
    <select id="f-status" name="status"><option value="">Published &amp; hidden</option><option value="published"<?= $status === 'published' ? ' selected' : '' ?>>Published</option><option value="hidden"<?= $status === 'hidden' ? ' selected' : '' ?>>Hidden</option></select>
    <button class="btn btn-primary btn-sm" type="submit"><?= icon('search') ?>Filter</button>
    <?php if ($q !== '' || $category !== '' || $status !== ''): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url($self)) ?>">Reset</a><?php endif; ?>
</form>

<?php if ($items): ?>
    <div class="gallery-admin">
        <?php foreach ($items as $g):
            $ytId = $g['media_type'] === 'video' ? youtube_id((string) $g['video_url']) : '';
            $thumb = $g['media_type'] === 'video' ? ($ytId ? 'https://i.ytimg.com/vi/' . $ytId . '/hqdefault.jpg' : '') : image_url($g['image_path']);
            $fid = 'g' . (int) $g['id']; ?>
            <div class="gallery-admin-item"<?= $g['status'] === 'hidden' ? ' style="opacity:.85;border-style:dashed"' : '' ?>>
                <?php if ($thumb): ?><img src="<?= e($thumb) ?>" alt="<?= e($g['description'] ?: $g['title']) ?>" loading="lazy"<?= $g['media_type'] === 'video' ? ' referrerpolicy="no-referrer"' : '' ?>><?php endif; ?>
                <form method="post" action="<?= e(admin_url($self)) ?>" style="grid-template-columns:minmax(0,1fr)">
                    <?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                    <div style="display:flex;justify-content:space-between;gap:6px;align-items:center"><?= status_badge($g['status']) ?><small class="muted">#<?= (int) $g['id'] ?><?= $ytId ? ' · <a href="https://www.youtube.com/watch?v=' . e($ytId) . '" target="_blank" rel="noopener">YouTube</a>' : '' ?></small></div>
                    <label class="sr-only" for="<?= $fid ?>-t">Title</label>
                    <input type="text" id="<?= $fid ?>-t" name="title" required maxlength="150" value="<?= e($g['title']) ?>" placeholder="Title">
                    <label class="sr-only" for="<?= $fid ?>-c">Category</label>
                    <select id="<?= $fid ?>-c" name="category"><?= $catOptions((string) $g['category']) ?></select>
                    <label class="sr-only" for="<?= $fid ?>-d"><?= $g['media_type'] === 'image' ? 'Description (alt text)' : 'Description' ?></label>
                    <input type="text" id="<?= $fid ?>-d" name="description" maxlength="300" value="<?= e((string) $g['description']) ?>" placeholder="<?= $g['media_type'] === 'image' ? 'Description (alt text)' : 'Description' ?>"<?= $g['media_type'] === 'image' ? ' required' : '' ?>>
                    <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:6px">
                        <label class="sr-only" for="<?= $fid ?>-s">Sort order</label>
                        <input id="<?= $fid ?>-s" type="number" name="sort_order" min="-9999" max="9999" value="<?= (int) $g['sort_order'] ?>" title="Sort order">
                        <label class="sr-only" for="<?= $fid ?>-st">Status</label>
                        <select id="<?= $fid ?>-st" name="status"><option value="published"<?= $g['status'] === 'published' ? ' selected' : '' ?>>Published</option><option value="hidden"<?= $g['status'] === 'hidden' ? ' selected' : '' ?>>Hidden</option></select>
                    </div>
                    <label class="checkbox small"><input type="checkbox" name="consent_confirmed" value="1"<?= (int) $g['consent_confirmed'] ? ' checked' : '' ?>><span><?= e(GALLERY_CONSENT_LABEL) ?></span></label>
                    <button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?>Save</button>
                </form>
                <div style="display:flex;gap:6px;padding:0 10px 10px;flex-wrap:wrap">
                    <form method="post" action="<?= e(admin_url($self)) ?>" class="inline-form" style="display:inline-block;padding:0">
                        <?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                        <button class="btn btn-ghost btn-sm" type="submit"><?= icon($g['status'] === 'published' ? 'eye' : 'check-circle') ?><?= $g['status'] === 'published' ? 'Hide' : 'Show' ?></button>
                    </form>
                    <form method="post" action="<?= e(admin_url($self)) ?>" class="inline-form" style="display:inline-block;padding:0" data-confirm="Delete this item permanently?">
                        <?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                        <button class="btn btn-ghost btn-sm" type="submit" aria-label="Delete <?= e($g['title']) ?>"><?= icon('trash') ?>Delete</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?= pagination_links($p) ?>
<?php else: ?>
    <div class="empty-state"><?= icon($type === 'video' ? 'play' : 'image') ?><p><?= $q !== '' || $category !== '' || $status !== '' ? 'Nothing matches these filters.' : ($type === 'video' ? 'No videos yet. Add a YouTube link above.' : 'No photos yet. Upload photos from your programmes and events above.') ?></p></div>
<?php endif; ?>
<?php
admin_page_end();
