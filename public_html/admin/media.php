<?php
/**
 * Media library: upload one or many images (uploads/media/), search, copy the path, edit the
 * description (alt text) and delete with a warning when the image is still used.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('media.manage');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('media.php');
    $action = (string) ($_POST['action'] ?? '');
    $back = admin_back('media.php');

    if ($action === 'upload') {
        $alt = mb_substr(clean_string($_POST['alt_text'] ?? ''), 0, 255);
        $files = $_FILES['images'] ?? null;
        $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
        if ($count === 0 || (int) ($files['error'][0] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash('error', 'Please choose one or more images to upload.');
            admin_redirect($back);
        }
        if ($count > 20) {
            flash('error', 'Please upload at most 20 images at a time.');
            admin_redirect($back);
        }
        $ok = [];
        for ($i = 0; $i < $count; $i++) {
            $file = site_file_at('images', [$i]);
            if (!$file) {
                continue;
            }
            $path = site_upload_image_array($file, 'media', $alt !== '' ? $alt : null);
            if ($path) {
                $ok[] = $path;
            }
        }
        if ($ok) {
            admin_log('media_uploaded', count($ok) . ' image(s) uploaded: ' . implode(', ', array_slice($ok, 0, 5)) . (count($ok) > 5 ? ' …' : ''));
            flash('success', count($ok) . ' image' . (count($ok) === 1 ? '' : 's') . ' uploaded.' . ($alt === '' ? ' Add a short description (alt text) to each image for visitors who use screen readers.' : ''));
        }
        admin_redirect($back);
    }

    $id = (int) ($_POST['id'] ?? 0);
    $media = $id ? db_row('SELECT * FROM media WHERE id = ?', [$id]) : null;
    if (!$media) {
        flash('error', 'Image not found.');
        admin_redirect($back);
    }

    if ($action === 'alt') {
        $alt = mb_substr(clean_string($_POST['alt_text'] ?? ''), 0, 255);
        db_update('media', ['alt_text' => $alt !== '' ? $alt : null], 'id = :id', ['id' => $id]);
        admin_log('media_updated', 'Media #' . $id . ' (' . $media['path'] . ') description updated');
        flash('success', 'Image description saved.');
        admin_redirect($back . (strpos($back, '#') === false ? '#media-' . $id : ''));
    }

    if ($action === 'delete') {
        $uses = site_media_usage($media['path']);
        if ($uses && empty($_POST['confirm_used'])) {
            flash('error', 'This image is still used (' . implode('; ', array_slice(array_keys($uses), 0, 3)) . '). Tick "delete anyway" to remove it.');
            admin_redirect($back);
        }
        delete_upload($media['path']);
        db_query('DELETE FROM media WHERE id = ?', [$id]);
        admin_log('media_deleted', 'Media #' . $id . ' deleted: ' . $media['path'] . ($uses ? ' (was used in ' . count($uses) . ' place(s))' : ''));
        flash('success', 'Image deleted.' . ($uses ? ' Pages that used it now show a placeholder - replace the image there.' : ''));
        admin_redirect($back);
    }
    admin_redirect($back);
}

// ---------------------------------------------------------------------
// List
// ---------------------------------------------------------------------
$q = trim((string) ($_GET['q'] ?? ''));
$folder = preg_replace('/[^a-z0-9_\-]/', '', (string) ($_GET['folder'] ?? ''));
$where = 'WHERE 1=1';
$params = [];
$where .= search_where(['m.path', 'm.original_name', 'm.alt_text'], $q, $params);
if ($folder !== '') {
    $where .= ' AND m.path LIKE ?';
    $params[] = 'uploads/' . $folder . '/%';
}
$total = (int) db_value("SELECT COUNT(*) FROM media m $where", $params);
$pg = paginate($total, 24);
$rows = db_rows("SELECT m.*, u.name AS uploader FROM media m LEFT JOIN users u ON u.id = m.uploaded_by $where ORDER BY m.id DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);
$folders = [];
foreach (db_rows('SELECT path FROM media') as $r) {
    if (preg_match('#^uploads/([a-z0-9_\-]+)/#', $r['path'], $m)) {
        $folders[$m[1]] = true;
    }
}
ksort($folders);
$noAlt = (int) db_value("SELECT COUNT(*) FROM media WHERE alt_text IS NULL OR alt_text = ''");

admin_page_start('Media Library');
?>
<div class="site-admin">
<section class="panel">
    <div class="panel-head"><h2><?= icon('upload') ?>Upload images</h2><span class="muted small">JPG, PNG or WebP, up to <?= (int) MAX_UPLOAD_MB ?> MB each. Large photos are resized to 1920 px.</span></div>
    <form method="post" action="<?= e(admin_url('media.php')) ?>" enctype="multipart/form-data" class="site-upload-box">
        <?= csrf_field() ?><input type="hidden" name="action" value="upload">
        <div class="form-grid">
            <div class="form-group"><label for="images">Images (you can select several)</label><input type="file" id="images" name="images[]" multiple required accept=".jpg,.jpeg,.png,.webp"></div>
            <div class="form-group"><label for="alt_text">Description for all of them (alt text, optional)</label><input id="alt_text" name="alt_text" maxlength="255" placeholder="e.g. Volunteers at a health awareness camp"></div>
        </div>
        <p class="help mb-0">Upload only photos the foundation owns or may use. Photos of identifiable children or vulnerable people need documented consent.</p>
        <div><button class="btn btn-primary" type="submit"><?= icon('upload') ?>Upload</button></div>
    </form>
</section>

<section class="panel">
    <?php
    $folderSelect = '<select name="folder" aria-label="Folder"><option value="">All folders</option>';
    foreach (array_keys($folders) as $fo) {
        $folderSelect .= '<option value="' . e($fo) . '"' . ($folder === $fo ? ' selected' : '') . '>uploads/' . e($fo) . '</option>';
    }
    $folderSelect .= '</select>';
    admin_filter_bar([], ['placeholder' => 'Search file name, path or description', 'html' => $folderSelect, 'active' => $folder !== '']);
    ?>
    <div class="table-meta"><span><?= $total ?> image<?= $total === 1 ? '' : 's' ?></span><?php if ($noAlt): ?><span class="site-warn"><?= icon('alert-circle') ?><?= $noAlt ?> without a description (alt text)</span><?php endif; ?></div>
    <?php if (!$rows): ?>
        <?= empty_state($q !== '' || $folder !== '' ? 'No images match your search.' : 'No images uploaded yet. Images uploaded anywhere in the admin panel also appear here.') ?>
    <?php else: ?>
        <div class="site-media-grid">
            <?php foreach ($rows as $m):
                $exists = is_file(ROOT_PATH . '/' . $m['path']);
                $uses = site_media_usage($m['path']);
                $idp = 'media-' . (int) $m['id']; ?>
                <article class="site-media-card" id="<?= $idp ?>">
                    <figure><?php if ($exists): ?><a href="<?= e(url($m['path'])) ?>" target="_blank" rel="noopener" aria-label="Open the full image"><img src="<?= e(url($m['path'])) ?>" alt="<?= e((string) $m['alt_text']) ?>" loading="lazy" width="320" height="240"></a><?php else: ?><img src="<?= e(url('assets/images/programs/general.svg')) ?>" alt="File missing" width="320" height="240"><?php endif; ?></figure>
                    <div class="site-media-body">
                        <div class="site-media-path"><code title="<?= e($m['path']) ?>"><?= e($m['path']) ?></code>
                            <button type="button" class="btn btn-ghost btn-sm" data-copy-text="<?= e($m['path']) ?>" aria-label="Copy the path of <?= e($m['original_name'] ?: basename($m['path'])) ?>"><?= icon('copy') ?><span>Copy</span></button></div>
                        <div class="site-media-meta">
                            <span><?= $m['width'] ? (int) $m['width'] . ' × ' . (int) $m['height'] . ' px' : 'size unknown' ?></span>
                            <span><?= $m['file_size'] ? e(number_format($m['file_size'] / 1024, 0)) . ' KB' : '' ?></span>
                            <span><?= e(format_date($m['created_at'])) ?><?= $m['uploader'] ? ' · ' . e($m['uploader']) : '' ?></span>
                            <?php if (!$exists): ?><span class="site-bad"><?= icon('alert-triangle') ?>File missing</span><?php endif; ?>
                            <?php if ($uses): ?><span class="badge badge-blue" title="<?= e(implode("\n", array_keys($uses))) ?>">Used in <?= count($uses) ?> place<?= count($uses) === 1 ? '' : 's' ?></span><?php endif; ?>
                        </div>
                        <form method="post" action="<?= e(admin_url('media.php')) ?>">
                            <?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="alt"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                            <label class="sr-only" for="<?= $idp ?>-alt">Description (alt text)</label>
                            <div class="site-media-path"><input id="<?= $idp ?>-alt" name="alt_text" maxlength="255" value="<?= e((string) $m['alt_text']) ?>" placeholder="Describe the image (alt text)">
                                <button class="btn btn-ghost btn-sm" type="submit" aria-label="Save description"><?= icon('check') ?></button></div>
                        </form>
                        <form method="post" action="<?= e(admin_url('media.php')) ?>" class="site-media-foot" data-confirm="<?= e($uses ? 'This image is used in ' . count($uses) . ' place(s): ' . implode(', ', array_slice(array_keys($uses), 0, 4)) . '. Delete it anyway? Those places will show a placeholder.' : 'Delete this image permanently?') ?>">
                            <?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                            <?php if ($uses): ?><label class="checkbox small" for="<?= $idp ?>-force"><input type="checkbox" id="<?= $idp ?>-force" name="confirm_used" value="1"><span>Delete anyway</span></label><?php else: ?><span></span><?php endif; ?>
                            <button class="btn btn-ghost btn-sm" type="submit"><?= icon('trash') ?>Delete</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?= pagination_links($pg) ?>
    <?php endif; ?>
</section>
</div>
<script src="<?= e(asset('assets/js/modules/admin-site.js')) ?>" defer></script>
<?php
admin_page_end();
