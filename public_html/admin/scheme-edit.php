<?php
/**
 * Create / edit / delete a welfare scheme.
 * Publishing requires an official https:// source link and the date the details were checked against it
 * (enforced here on the server). Long fields are plain text: line breaks are kept, lines starting with
 * "-" become bullet points and "1." numbered steps.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('schemes.manage');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$scheme = $id ? db_row('SELECT * FROM schemes WHERE id = ?', [$id]) : null;
if ($id && !$scheme) {
    flash('error', 'Scheme not found.');
    admin_redirect('schemes.php');
}
$self = 'scheme-edit.php' . ($scheme ? '?id=' . $id : '');
$cats = scheme_categories();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only($self);

    if (($_POST['action'] ?? '') === 'delete' && $scheme) {
        db_query('DELETE FROM schemes WHERE id = ?', [$id]);
        admin_log('scheme_deleted', 'Scheme #' . $id . ' "' . $scheme['title'] . '" deleted');
        flash('success', 'Scheme "' . $scheme['title'] . '" deleted.');
        admin_redirect('schemes.php');
    }

    [$data, $errors] = validate($_POST, [
        'title'             => 'required|min:3|max:200',
        'slug'              => 'max:200',
        'category'          => 'required|in:' . implode(',', array_keys($cats)),
        'authority'         => 'max:200',
        'summary'           => 'required|min:20|max:400|text',
        'eligibility'       => 'text|max:5000',
        'benefits'          => 'text|max:5000',
        'how_to_apply'      => 'text|max:5000',
        'documents_needed'  => 'text|max:3000',
        'official_url'      => 'url|max:255',
        'source_checked_on' => 'date|past',
        'status'            => 'required|in:draft,published,archived',
        'sort_order'        => 'integer|min:-9999|max:99999',
    ], ['official_url' => 'Official source link', 'source_checked_on' => 'Source checked on', 'how_to_apply' => 'How to apply', 'documents_needed' => 'Documents needed', 'sort_order' => 'Display order']);

    $slug = $data['slug'] !== '' ? strtolower($data['slug']) : slugify($data['title']);
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 200) {
        $errors['slug'] = 'The address may only contain lower-case letters, numbers and single hyphens.';
    } elseif (db_value('SELECT COUNT(*) FROM schemes WHERE slug = ? AND id <> ?', [$slug, $id])) {
        $errors['slug'] = 'Another scheme already uses this address.';
    }
    if (!isset($errors['official_url']) && $data['official_url'] !== '' && stripos($data['official_url'], 'https://') !== 0) {
        $errors['official_url'] = 'Use the secure https:// address of the official source.';
    }
    // Publishing rules: verified official source + date checked + enough detail to be useful
    if ($data['status'] === 'published') {
        if ($data['official_url'] === '' && !isset($errors['official_url'])) {
            $errors['official_url'] = 'An official https:// source link is required to publish a scheme.';
        }
        if ($data['source_checked_on'] === '' && !isset($errors['source_checked_on'])) {
            $errors['source_checked_on'] = 'Enter the date you checked the details against the official source (required to publish).';
        }
        if ($data['eligibility'] === '' && $data['benefits'] === '') {
            $errors['eligibility'] = 'Add the eligibility or the benefits before publishing.';
        }
    }

    $image = $scheme['image'] ?? null;
    $fileSent = isset($_FILES['image']['error']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE;
    if (!$errors && $fileSent) {
        $flashBefore = count($_SESSION['_flash'] ?? []);
        $uploaded = admin_upload_image('image', 'schemes', $data['title']);
        if ($uploaded === null) {
            $uploadMsgs = array_slice($_SESSION['_flash'] ?? [], $flashBefore);
            $_SESSION['_flash'] = array_slice($_SESSION['_flash'] ?? [], 0, $flashBefore);
            $errors['image'] = $uploadMsgs ? (string) end($uploadMsgs)['message'] : 'The image could not be uploaded. Please try another file.';
        } else {
            $image = $uploaded;
        }
    } elseif (!$fileSent && !empty($_POST['remove_image'])) {
        $image = null;
    }

    if ($errors) {
        back_with_errors($errors, 'admin/' . $self, $_POST);
    }

    $row = [
        'title' => $data['title'], 'slug' => $slug, 'category' => $data['category'], 'authority' => $data['authority'] !== '' ? $data['authority'] : null,
        'summary' => $data['summary'], 'eligibility' => $data['eligibility'] ?: null, 'benefits' => $data['benefits'] ?: null,
        'how_to_apply' => $data['how_to_apply'] ?: null, 'documents_needed' => $data['documents_needed'] ?: null,
        'official_url' => $data['official_url'] ?: null, 'source_checked_on' => $data['source_checked_on'] ?: null, 'image' => $image,
        'help_available' => !empty($_POST['help_available']) ? 1 : 0, 'status' => $data['status'], 'sort_order' => (int) $data['sort_order'],
        'updated_by' => admin_id(),
    ];
    if ($scheme) {
        db_update('schemes', $row, 'id = :id', ['id' => $id]);
        $what = $scheme['status'] !== $data['status'] ? ' (status ' . $scheme['status'] . ' → ' . $data['status'] . ')' : '';
        admin_log('scheme_updated', 'Scheme #' . $id . ' "' . $data['title'] . '" updated' . $what);
        flash('success', 'Scheme saved' . ($data['status'] === 'published' ? ' and published.' : '.'));
    } else {
        $id = db_insert('schemes', $row + ['created_by' => admin_id()]);
        admin_log('scheme_created', 'Scheme #' . $id . ' "' . $data['title'] . '" created as ' . $data['status']);
        flash('success', 'Scheme created' . ($data['status'] === 'published' ? ' and published.' : ' as a ' . $data['status'] . '.'));
    }
    // SEO: a changed address of a published scheme gets a one-hop 301; a new or moved address is released from old redirects
    if ($scheme && $scheme['slug'] !== $slug && $scheme['status'] === 'published' && function_exists('seo_slug_changed')) {
        $moved = seo_slug_changed('scheme', scheme_url($scheme['slug']), scheme_url($slug));
        if (!empty($moved['message'])) {
            flash($moved['ok'] ? 'info' : 'warning', (string) $moved['message']);
        }
    } elseif ((!$scheme || $scheme['slug'] !== $slug) && function_exists('seo_slug_claim')) {
        seo_slug_claim(scheme_url($slug));
    }
    if ($data['status'] === 'published' && function_exists('seo_indexnow_ping')) {
        seo_indexnow_ping([scheme_url($slug)]);   // only when IndexNow is switched on (off by default)
    }
    admin_redirect('scheme-edit.php?id=' . $id);
}

$s = $scheme ?: ['title' => '', 'slug' => '', 'category' => 'general', 'authority' => '', 'summary' => '', 'eligibility' => '', 'benefits' => '',
    'how_to_apply' => '', 'documents_needed' => '', 'official_url' => '', 'source_checked_on' => '', 'image' => null, 'help_available' => 1,
    'status' => 'draft', 'sort_order' => 0];
$text = function (string $field, string $label, string $help, int $rows = 5, int $max = 5000) use ($s): string {
    return '<div class="form-group full"><label for="' . $field . '">' . e($label) . '</label>'
        . '<textarea id="' . $field . '" name="' . $field . '" rows="' . $rows . '" maxlength="' . $max . '">' . e(old($field, (string) $s[$field])) . '</textarea>'
        . '<span class="help">' . e($help) . '</span>' . field_error($field) . '</div>';
};

admin_page_start($scheme ? 'Edit scheme' : 'New scheme');
?>
<div class="page-actions">
    <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('schemes.php')) ?>"><?= icon('chevron-left') ?>All schemes</a>
    <?php if ($scheme): ?>
        <div class="actions"><a class="btn btn-ghost btn-sm" href="<?= e(scheme_url($scheme['slug']) . ($scheme['status'] !== 'published' ? '?preview=1' : '')) ?>" target="_blank" rel="noopener"><?= icon('external') ?><?= $scheme['status'] === 'published' ? 'View page' : 'Preview' ?></a>
            <?php if (function_exists('seo_entity_edit_url') && admin_can('seo.manage')): ?><a class="btn btn-ghost btn-sm" href="<?= e(seo_entity_edit_url('scheme', (int) $scheme['id'])) ?>"><?= icon('search') ?>SEO settings</a><?php endif; ?></div>
    <?php endif; ?>
</div>

<?php if ($scheme && $scheme['status'] === 'published' && scheme_is_stale($scheme)): ?>
    <div class="alert alert-warning"><?= icon('refresh') ?><div>These details were last checked on <?= e(format_date($scheme['source_checked_on'])) ?>. Please verify them again on the official source and update the date.</div></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="admin-grid-main">
    <?= csrf_field() ?>
    <?php if ($scheme): ?><input type="hidden" name="id" value="<?= (int) $scheme['id'] ?>"><?php endif; ?>
    <section class="panel">
        <div class="panel-head"><h2>Scheme details</h2></div>
        <div class="form-grid">
            <div class="form-group full"><label for="title">Scheme name <span class="req">*</span></label>
                <input type="text" id="title" name="title" required maxlength="200" value="<?= e(old('title', $s['title'])) ?>"><?= field_error('title') ?></div>
            <div class="form-group"><label for="slug">Address (slug)</label>
                <input type="text" id="slug" name="slug" maxlength="200" pattern="[a-z0-9]+(-[a-z0-9]+)*" value="<?= e(old('slug', $s['slug'])) ?>" placeholder="created from the name">
                <span class="help">Public page: /schemes/&lt;slug&gt;/</span><?= field_error('slug') ?></div>
            <div class="form-group"><label for="category">Category <span class="req">*</span></label>
                <select id="category" name="category" required><?= options_html($cats, (string) old('category', $s['category']), true, '') ?></select><?= field_error('category') ?></div>
            <div class="form-group full"><label for="authority">Run by (ministry / department / state)</label>
                <input type="text" id="authority" name="authority" maxlength="200" value="<?= e(old('authority', (string) $s['authority'])) ?>" placeholder="e.g. Ministry of … , Government of India"><?= field_error('authority') ?></div>
            <div class="form-group full"><label for="summary">Summary <span class="req">*</span></label>
                <textarea id="summary" name="summary" required rows="3" maxlength="400"><?= e(old('summary', $s['summary'])) ?></textarea>
                <span class="help">What the scheme offers and for whom, in plain words (max 400 characters).</span><?= field_error('summary') ?></div>
            <?= $text('eligibility', 'Who can apply (eligibility)', 'One condition per line. Copy the conditions exactly as stated by the official source.') ?>
            <?= $text('benefits', 'Benefits', 'What an eligible person receives. Mention amounts only as stated by the official source.') ?>
            <?= $text('how_to_apply', 'How to apply', 'Steps, one per line. Start lines with 1. 2. 3. for numbered steps.') ?>
            <?= $text('documents_needed', 'Documents usually needed', 'One document per line.', 4, 3000) ?>
        </div>
    </section>

    <div>
        <section class="panel">
            <div class="panel-head"><h2>Source & publishing</h2><?= $scheme ? status_badge($scheme['status']) : '' ?></div>
            <div class="form-group" style="margin-bottom:16px"><label for="official_url">Official source link</label>
                <input id="official_url" type="url" name="official_url" maxlength="255" value="<?= e(old('official_url', (string) $s['official_url'])) ?>" placeholder="https://…gov.in/…">
                <span class="help">The official government page for this scheme (https:// only). Required to publish.</span><?= field_error('official_url') ?></div>
            <div class="form-group" style="margin-bottom:16px"><label for="source_checked_on">Source checked on</label>
                <input id="source_checked_on" type="date" name="source_checked_on" max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('source_checked_on', (string) $s['source_checked_on'])) ?>">
                <span class="help">The date you compared these details with the official source. Required to publish; shown to visitors.</span><?= field_error('source_checked_on') ?></div>
            <div class="form-group" style="margin-bottom:16px"><label for="status">Status</label>
                <select id="status" name="status"><?= options_html(['draft' => 'Draft (hidden)', 'published' => 'Published', 'archived' => 'Archived (hidden)'], (string) old('status', $s['status']), true, '') ?></select></div>
            <div class="form-group" style="margin-bottom:16px"><label for="sort_order">Display order</label>
                <input id="sort_order" type="number" name="sort_order" value="<?= e(old('sort_order', (string) $s['sort_order'])) ?>"><?= field_error('sort_order') ?></div>
            <label class="checkbox"><input type="checkbox" name="help_available" value="1"<?= old_checked('help_available', '1', (int) $s['help_available'] === 1) ?>><span>Our volunteers can help people apply for this scheme</span></label>
            <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?><?= $scheme ? 'Save changes' : 'Create scheme' ?></button></div>
        </section>

        <section class="panel mt-3">
            <div class="panel-head"><h2>Image (optional)</h2></div>
            <?php if (!empty($s['image'])): ?><img src="<?= e(image_url($s['image'])) ?>" alt="Current scheme image" style="width:100%;max-height:180px;object-fit:cover;border-radius:10px;margin-bottom:12px"><?php endif; ?>
            <div class="form-group" style="margin-bottom:16px"><label for="image">Upload an image</label>
                <input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp">
                <span class="help">JPG, PNG or WebP, up to <?= e(upload_limit_label()) ?> MB. Do not use government logos or emblems.</span><?= field_error('image') ?></div>
            <?php if (!empty($s['image'])): ?><label class="checkbox"><input type="checkbox" name="remove_image" value="1"><span>Remove the image</span></label><?php endif; ?>
        </section>
    </div>
</form>

<?php if ($scheme): ?>
<section class="panel mt-3">
    <div class="panel-head"><h2>Delete scheme</h2></div>
    <form method="post" data-confirm="Delete the scheme &quot;<?= e($scheme['title']) ?>&quot;? This cannot be undone.">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $scheme['id'] ?>">
        <p class="muted small">To hide a scheme temporarily, set its status to Draft or Archived instead.</p>
        <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash') ?>Delete scheme</button>
    </form>
</section>
<?php endif; ?>
<?php
admin_page_end();
