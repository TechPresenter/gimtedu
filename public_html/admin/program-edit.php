<?php
/**
 * Create / edit / delete a programme.
 * A new programme also gets its detail page (/programs/<slug>/, template "program", parent = Programs page)
 * with a starter set of sections; the page content is edited in Pages & Sections.
 * Unpublishing a programme also unpublishes its page. Deleting a programme never deletes campaigns, events,
 * posts or stories (their programme link is cleared by the database).
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('programs.manage');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$program = $id ? db_row(program_select_sql() . ' WHERE p.id = ?', [$id]) : null;
if ($id && !$program) {
    flash('error', 'Programme not found.');
    admin_redirect('programs.php');
}
$self = 'program-edit.php' . ($program ? '?id=' . $id : '');
$detailPage = $program && $program['detail_page_id'] ? db_row('SELECT * FROM pages WHERE id = ?', [$program['detail_page_id']]) : null;

/** Create the detail page of a programme with starter sections and link it. Returns the page id. */
$createPage = function (int $programId, array $data): int {
    $parentId = db_value("SELECT id FROM pages WHERE slug = 'programs'");
    $art = program_art(['slug' => $data['slug']], false);
    $pageId = db_insert('pages', [
        'parent_id' => $parentId ? (int) $parentId : null, 'slug' => 'programs/' . $data['slug'], 'template' => 'program',
        'title' => $data['title'], 'banner_eyebrow' => 'Our programmes', 'banner_subtitle' => mb_substr($data['summary'], 0, 300),
        'banner_image' => $art, 'meta_description' => mb_substr($data['summary'], 0, 300),
        'settings' => json_encode(['program' => $data['slug'], 'focus' => $data['focus']]),
        'status' => $data['status'] === 'published' ? 'published' : 'draft', 'is_system' => 0, 'show_in_sitemap' => 1, 'noindex' => 0,
        'sort_order' => 40 + max(0, (int) $data['sort_order']), 'published_at' => $data['status'] === 'published' ? date('Y-m-d H:i:s') : null,
        'created_by' => admin_id(), 'updated_by' => admin_id(),
    ]);
    $program = $data + ['key_points' => $data['key_points_json']];
    foreach (program_starter_sections($program) as $i => $s) {
        db_insert('page_sections', $s + ['page_id' => $pageId, 'status' => 'visible', 'sort_order' => ($i + 1) * 10, 'updated_by' => admin_id()]);
    }
    db_update('programs', ['page_id' => $pageId], 'id = :id', ['id' => $programId]);
    return $pageId;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only($self);
    $action = (string) ($_POST['action'] ?? 'save');

    // ----- Delete -----------------------------------------------------
    if ($action === 'delete' && $program) {
        if (empty($_POST['confirm_delete'])) {
            flash('error', 'Tick the confirmation box to delete this programme.');
            admin_redirect($self);
        }
        $pageNote = '';
        if ($detailPage) {
            if (!empty($_POST['delete_page']) && !(int) $detailPage['is_system'] && $detailPage['template'] === 'program') {
                db_query('DELETE FROM pages WHERE id = ?', [$detailPage['id']]);
                $pageNote = ' Its page /' . $detailPage['slug'] . '/ and sections were deleted.';
            } elseif (!(int) $detailPage['is_system']) {
                db_update('pages', ['status' => 'draft', 'updated_by' => admin_id()], 'id = :id', ['id' => $detailPage['id']]);
                $pageNote = ' Its page /' . $detailPage['slug'] . '/ was kept as a draft.';
            } else {
                $pageNote = ' Its system page /' . $detailPage['slug'] . '/ was kept.';
            }
        }
        db_query('DELETE FROM programs WHERE id = ?', [$id]);
        admin_log('program_deleted', 'Programme #' . $id . ' "' . $program['title'] . '" deleted.' . $pageNote);
        flash('success', 'Programme "' . $program['title'] . '" deleted. Linked campaigns and events were kept (no longer linked to a programme).' . $pageNote . ' Remember to remove it from the menus if it is listed there.');
        admin_redirect('programs.php');
    }

    // ----- Save -------------------------------------------------------
    $icons = program_icons();
    if ($program && !in_array($program['icon'], $icons, true)) {
        $icons[] = $program['icon'];
    }
    [$data, $errors] = validate($_POST, [
        'title'      => 'required|min:3|max:150',
        'slug'       => 'max:120',
        'summary'    => 'required|min:10|max:300',
        'icon'       => 'required|in:' . implode(',', $icons),
        'tone'       => 'required|in:' . implode(',', array_keys(program_tones())),
        'focus'      => 'required|in:' . implode(',', array_keys(program_focus_groups())),
        'key_points' => 'text|max:2000',
        'status'     => 'required|in:published,draft',
        'sort_order' => 'integer|min:-9999|max:99999',
    ], ['key_points' => 'Key points', 'sort_order' => 'Display order']);

    $slug = $data['slug'] !== '' ? strtolower($data['slug']) : slugify($data['title']);
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 120) {
        $errors['slug'] = 'The address may only contain lower-case letters, numbers and single hyphens.';
    } elseif (db_value('SELECT COUNT(*) FROM programs WHERE slug = ? AND id <> ?', [$slug, $id])) {
        $errors['slug'] = 'Another programme already uses this address.';
    } else {
        $pagePath = 'programs/' . $slug;
        $ownPageId = $detailPage ? (int) $detailPage['id'] : 0;
        $changesPage = !$detailPage || ($detailPage['slug'] === 'programs/' . $program['slug'] && $slug !== $program['slug']);
        if ($changesPage) {
            $existing = db_row('SELECT p.id, (SELECT COUNT(*) FROM programs x WHERE x.page_id = p.id) AS used FROM pages p WHERE p.slug = ?', [$pagePath]);
            if ($existing && ((int) $existing['id'] !== $ownPageId)) {
                $errors['slug'] = 'A page with the address /' . $pagePath . '/ already exists.';
            } elseif (db_value('SELECT COUNT(*) FROM redirects WHERE source_path IN (?, ?)', [$pagePath, $pagePath . '/'])) {
                $errors['slug'] = 'The address /' . $pagePath . '/ is used by a redirect. Choose another address or remove the redirect first.';
            }
        }
    }

    $points = [];
    foreach (explode("\n", $data['key_points']) as $line) {
        $line = trim(preg_replace('/^[\-\*•]\s*/u', '', $line));
        if ($line !== '') {
            if (mb_strlen($line) > 160) {
                $errors['key_points'] = 'Each key point may be at most 160 characters.';
            }
            $points[] = $line;
        }
    }
    if (count($points) > 8) {
        $errors['key_points'] = 'Please enter at most 8 key points.';
    }

    $cover = $program['cover_image'] ?? null;
    $fileSent = isset($_FILES['cover_image']['error']) && $_FILES['cover_image']['error'] !== UPLOAD_ERR_NO_FILE;
    if (!$errors && $fileSent) {
        $flashBefore = count($_SESSION['_flash'] ?? []);
        $uploaded = admin_upload_image('cover_image', 'programs', $data['title']);
        if ($uploaded === null) {
            $uploadMsgs = array_slice($_SESSION['_flash'] ?? [], $flashBefore);
            $_SESSION['_flash'] = array_slice($_SESSION['_flash'] ?? [], 0, $flashBefore);
            $errors['cover_image'] = $uploadMsgs ? (string) end($uploadMsgs)['message'] : 'The image could not be uploaded. Please try another file.';
        } else {
            $cover = $uploaded;
        }
    } elseif (!$fileSent && !empty($_POST['remove_cover'])) {
        $cover = null;
    }

    if ($errors) {
        back_with_errors($errors, 'admin/' . $self, $_POST);
    }

    $row = [
        'slug' => $slug, 'title' => $data['title'], 'summary' => $data['summary'], 'icon' => $data['icon'], 'tone' => $data['tone'],
        'cover_image' => $cover, 'key_points' => json_encode($points, JSON_UNESCAPED_UNICODE),
        'show_on_home' => !empty($_POST['show_on_home']) ? 1 : 0, 'status' => $data['status'], 'sort_order' => (int) $data['sort_order'],
    ];
    $pageInfo = ['slug' => $slug, 'title' => $data['title'], 'summary' => $data['summary'], 'icon' => $data['icon'], 'focus' => $data['focus'],
        'status' => $data['status'], 'sort_order' => (int) $data['sort_order'], 'key_points_json' => $row['key_points']];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (!$program) {
            $id = db_insert('programs', $row);
            $createPage($id, $pageInfo);
            $pdo->commit();
            admin_log('program_created', 'Programme #' . $id . ' "' . $data['title'] . '" created with page /programs/' . $slug . '/');
            flash('success', 'Programme created with its page /programs/' . $slug . '/ and a starter set of sections. Add the details in "Edit page content".');
            admin_redirect('program-edit.php?id=' . $id);
        }

        db_update('programs', $row, 'id = :id', ['id' => $id]);
        $notes = [];
        if ($detailPage) {
            $settings = json_field($detailPage['settings']);
            $settings['program'] = $slug;
            $settings['focus'] = $data['focus'];
            $pageUpdate = ['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_by' => admin_id()];
            if ($detailPage['title'] === $program['title'] && $data['title'] !== $program['title']) {
                $pageUpdate['title'] = $data['title'];
            }
            if ((string) $detailPage['banner_subtitle'] === (string) $program['summary']) {
                $pageUpdate['banner_subtitle'] = mb_substr($data['summary'], 0, 300);
            }
            if ((string) $detailPage['meta_description'] === (string) $program['summary']) {
                $pageUpdate['meta_description'] = mb_substr($data['summary'], 0, 300);
            }
            if ($data['status'] !== $program['status']) {
                if ($data['status'] === 'draft' && $detailPage['status'] === 'published') {
                    $pageUpdate['status'] = 'draft';
                    $notes[] = 'its page was unpublished';
                } elseif ($data['status'] === 'published' && $detailPage['status'] !== 'published') {
                    $pageUpdate['status'] = 'published';
                    $pageUpdate['published_at'] = date('Y-m-d H:i:s');
                    $notes[] = 'its page was published';
                }
            }
            if ($slug !== $program['slug'] && $detailPage['slug'] === 'programs/' . $program['slug']) {
                $pageUpdate['slug'] = 'programs/' . $slug;
                db_query('INSERT INTO redirects (source_path, target_url, status_code) VALUES (?, ?, 301) ON DUPLICATE KEY UPDATE target_url = VALUES(target_url)',
                    [$detailPage['slug'], '/programs/' . $slug . '/']);
                db_query('UPDATE redirects SET target_url = ? WHERE target_url = ?', ['/programs/' . $slug . '/', '/' . $detailPage['slug'] . '/']);
                $notes[] = 'its address changed to /programs/' . $slug . '/ (the old address redirects there; update menu links)';
            }
            db_update('pages', $pageUpdate, 'id = :id', ['id' => $detailPage['id']]);
            if (!$program['page_id']) {
                db_update('programs', ['page_id' => $detailPage['id']], 'id = :id', ['id' => $id]);
            }
        } else {
            $createPage($id, $pageInfo);
            $notes[] = 'its page /programs/' . $slug . '/ was created';
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Programme save failed: ' . $e->getMessage());
        flash('error', 'The programme could not be saved. Please try again.');
        admin_redirect($self);
    }
    admin_log('program_updated', 'Programme #' . $id . ' "' . $data['title'] . '" updated' . ($notes ? ': ' . implode('; ', $notes) : ''));
    flash('success', 'Programme saved' . ($notes ? ' – ' . implode('; ', $notes) : '') . '.');
    admin_redirect('program-edit.php?id=' . $id);
}

$p = $program ?: ['title' => '', 'slug' => '', 'summary' => '', 'icon' => 'heart', 'tone' => 'green', 'cover_image' => null, 'key_points' => '[]',
    'show_on_home' => 1, 'status' => 'published', 'sort_order' => (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM programs'), 'page_settings' => null];
$curIcon = (string) old('icon', $p['icon']);
$curFocus = (string) old('focus', $program ? program_focus($program) : 'community');
$iconList = program_icons();
if (!in_array($p['icon'], $iconList, true)) {
    $iconList[] = $p['icon'];
}

admin_page_start($program ? 'Edit programme' : 'New programme');
?>
<div class="page-actions">
    <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('programs.php')) ?>"><?= icon('chevron-left') ?>All programmes</a>
    <?php if ($program): ?>
        <div class="actions">
            <?php if ($detailPage && admin_can('pages.manage')): ?>
                <a class="btn btn-primary btn-sm" href="<?= e(admin_url('page-edit.php?id=' . (int) $detailPage['id'])) ?>"><?= icon('layers') ?>Edit page content</a>
            <?php endif; ?>
            <?php if ($detailPage): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(page_url($detailPage['slug']) . ($detailPage['status'] !== 'published' ? '?preview=1' : '')) ?>" target="_blank" rel="noopener"><?= icon('external') ?>View page</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<form method="post" enctype="multipart/form-data" class="admin-grid-main">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($program): ?><input type="hidden" name="id" value="<?= (int) $program['id'] ?>"><?php endif; ?>
    <section class="panel">
        <div class="panel-head"><h2>Programme details</h2></div>
        <div class="form-grid">
            <div class="form-group full"><label for="title">Title <span class="req">*</span></label>
                <input type="text" id="title" name="title" required maxlength="150" value="<?= e(old('title', $p['title'])) ?>"><?= field_error('title') ?></div>
            <div class="form-group full"><label for="slug">Address (slug)</label>
                <input type="text" id="slug" name="slug" maxlength="120" pattern="[a-z0-9]+(-[a-z0-9]+)*" value="<?= e(old('slug', $p['slug'])) ?>" placeholder="created from the title">
                <span class="help"><?php if ($detailPage): ?>Page: <strong>/<?= e($detailPage['slug']) ?>/</strong>. Changing the slug moves a /programs/… page and redirects the old address.<?php else: ?>The page will be <strong>/programs/&lt;slug&gt;/</strong>. Lower-case letters, numbers and hyphens.<?php endif; ?></span><?= field_error('slug') ?></div>
            <div class="form-group full"><label for="summary">Summary <span class="req">*</span></label>
                <textarea id="summary" name="summary" required maxlength="300" rows="3"><?= e(old('summary', $p['summary'])) ?></textarea>
                <span class="help">One or two sentences shown on programme cards (max 300 characters). Describe the work – no unverified figures.</span><?= field_error('summary') ?></div>
            <div class="form-group full"><label for="key_points">Key activities</label>
                <textarea id="key_points" name="key_points" rows="5" maxlength="2000" placeholder="One per line, e.g. Health camps and screenings"><?= e(old('key_points', implode("\n", program_key_points($p)))) ?></textarea>
                <span class="help">One per line (up to 8). The first three appear on the programme card; all appear on the programme page.</span><?= field_error('key_points') ?></div>
            <fieldset class="form-group full" style="border:0;padding:0;margin:0">
                <legend class="label">Icon <span class="req">*</span></legend>
                <div class="check-group">
                    <?php foreach ($iconList as $ic): ?>
                        <label class="check-chip" title="<?= e($ic) ?>"><input type="radio" name="icon" value="<?= e($ic) ?>" aria-label="Icon <?= e($ic) ?>"<?= $curIcon === $ic ? ' checked' : '' ?> required><span style="padding:8px 10px"><?= icon($ic) ?></span></label>
                    <?php endforeach; ?>
                </div><?= field_error('icon') ?>
            </fieldset>
            <div class="form-group"><label for="tone">Colour</label>
                <select id="tone" name="tone"><?= options_html(program_tones(), (string) old('tone', $p['tone']), true, '') ?></select><?= field_error('tone') ?></div>
            <div class="form-group"><label for="focus">Focus area</label>
                <select id="focus" name="focus"><?= options_html(program_focus_groups(), $curFocus, true, '') ?></select>
                <span class="help">Used for the filter on the programmes page.</span><?= field_error('focus') ?></div>
        </div>
    </section>

    <div>
        <section class="panel">
            <div class="panel-head"><h2>Publishing</h2><?= $program ? status_badge($program['status']) : '' ?></div>
            <div class="form-group" style="margin-bottom:16px"><label for="status">Status</label>
                <select id="status" name="status"><?= options_html(['published' => 'Published', 'draft' => 'Draft (hidden)'], (string) old('status', $p['status']), true, '') ?></select>
                <span class="help">Draft also unpublishes the programme page.</span></div>
            <div class="form-group" style="margin-bottom:16px"><label for="sort_order">Display order</label>
                <input id="sort_order" type="number" name="sort_order" value="<?= e(old('sort_order', (string) $p['sort_order'])) ?>"><?= field_error('sort_order') ?></div>
            <label class="checkbox"><input type="checkbox" name="show_on_home" value="1"<?= old_checked('show_on_home', '1', (int) $p['show_on_home'] === 1) ?>><span>Show on the home page</span></label>
            <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?><?= $program ? 'Save changes' : 'Create programme' ?></button></div>
        </section>

        <section class="panel mt-3">
            <div class="panel-head"><h2>Cover image</h2></div>
            <img src="<?= e(image_url(program_art($p))) ?>" alt="Current programme image" style="width:100%;max-height:200px;object-fit:contain;background:var(--green-50);border-radius:10px;margin-bottom:12px">
            <div class="form-group" style="margin-bottom:16px"><label for="cover_image">Upload a photo (optional)</label>
                <input id="cover_image" type="file" name="cover_image" accept="image/jpeg,image/png,image/webp">
                <span class="help">JPG, PNG or WebP, up to <?= (int) MAX_UPLOAD_MB ?> MB. Without a photo the programme illustration is used. Publish photos of identifiable people only with their consent.</span><?= field_error('cover_image') ?></div>
            <?php if (!empty($p['cover_image'])): ?>
                <label class="checkbox"><input type="checkbox" name="remove_cover" value="1"><span>Remove the photo (use the illustration)</span></label>
            <?php endif; ?>
        </section>
    </div>
</form>

<?php if ($program): ?>
<section class="panel mt-3">
    <div class="panel-head"><h2>Delete programme</h2></div>
    <p>Deleting removes the programme from programme lists. Campaigns, events, articles and stories linked to it are <strong>kept</strong> – they are simply no longer linked to a programme.</p>
    <form method="post" data-confirm="Delete the programme &quot;<?= e($program['title']) ?>&quot;? This cannot be undone.">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $program['id'] ?>">
        <?php if ($detailPage && !(int) $detailPage['is_system']): ?>
            <label class="checkbox"><input type="checkbox" name="delete_page" value="1" checked><span>Also delete its page /<?= e($detailPage['slug']) ?>/ and the page sections (otherwise the page is kept as a draft)</span></label>
        <?php elseif ($detailPage): ?>
            <p class="small muted">Its page /<?= e($detailPage['slug']) ?>/ is a system page and will be kept.</p>
        <?php endif; ?>
        <label class="checkbox"><input type="checkbox" name="confirm_delete" value="1" required><span>I understand that this programme will be deleted</span></label>
        <div class="form-actions"><button class="btn btn-danger btn-sm" type="submit"><?= icon('trash') ?>Delete programme</button></div>
    </form>
</section>
<?php endif; ?>
<?php
admin_page_end();
