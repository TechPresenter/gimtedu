<?php
/**
 * Create / edit a success story. Publishing requires the consent confirmation (enforced here, server side):
 * "Written consent to publish this story and photo is on file".
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('stories.manage');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$story = $id ? db_row('SELECT * FROM success_stories WHERE id = ?', [$id]) : null;
if ($id && !$story) {
    flash('error', 'Story not found.');
    admin_redirect('stories.php');
}
$self = 'story-edit.php' . ($story ? '?id=' . $id : '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_GET['upload'])) {
    content_editor_upload('stories', $story ? 'story #' . $id : 'new story');
}

$programs = content_programs();
const STORY_CONSENT_LABEL = 'Written consent to publish this story and photo is on file';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only($self);
    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'delete' && $story) {
        db_query('DELETE FROM success_stories WHERE id = ?', [$id]);
        content_delete_image($story['image']);
        admin_log('story_deleted', 'Story #' . $id . ' "' . $story['title'] . '" deleted');
        flash('success', 'The story was deleted.');
        admin_redirect('stories.php');
    }

    [$data, $errors] = validate($_POST, [
        'title' => 'required|min:3|max:220',
        'slug' => 'max:200',
        'program_id' => 'integer',
        'person_name' => 'max:120',
        'location' => 'max:150',
        'excerpt' => 'text|max:400',
        'status' => 'required|in:draft,published,archived',
        'published_at' => 'datetime',
    ], ['program_id' => 'Programme', 'person_name' => 'Name of the person', 'published_at' => 'Publish date']);
    $consent = !empty($_POST['consent_confirmed']) ? 1 : 0;
    $content = clean_html((string) ($_POST['content'] ?? ''));
    if (trim(strip_tags($content, '<img>')) === '') {
        $errors['content'] = 'Please write the story.';
    }
    $programId = $data['program_id'] !== '' ? (int) $data['program_id'] : null;
    if ($programId && !isset($programs[$programId])) {
        $errors['program_id'] = 'Please choose a valid programme.';
    }
    if ($data['status'] === 'published' && !$consent) {
        $errors['consent_confirmed'] = 'A story can be published only when written consent is on file. Tick the consent box or save it as a draft.';
    }
    if ($errors) {
        back_with_errors($errors, 'admin/' . $self, $_POST);
    }

    $image = $story['image'] ?? null;
    $oldImage = $image;
    $before = count($_SESSION['_flash'] ?? []);
    $uploaded = admin_upload_image('image', 'stories', $data['title']);
    if (!$uploaded && count($_SESSION['_flash'] ?? []) > $before) {
        $last = array_pop($_SESSION['_flash']);
        back_with_errors(['image' => $last['message'] ?? 'The image could not be uploaded.'], 'admin/' . $self, $_POST);
    }
    if ($uploaded) {
        $image = $uploaded;
    } elseif (!empty($_POST['remove_image'])) {
        $image = null;
    }

    $publishedAt = $data['published_at'] !== '' ? $data['published_at'] : null;
    if ($data['status'] === 'published' && !$publishedAt) {
        $publishedAt = date('Y-m-d H:i:s');
    }
    $row = [
        'title' => $data['title'],
        'slug' => unique_slug('success_stories', $data['slug'] !== '' ? $data['slug'] : $data['title'], $id),
        'program_id' => $programId,
        'person_name' => $data['person_name'] !== '' ? $data['person_name'] : null,
        'location' => $data['location'] !== '' ? $data['location'] : null,
        'excerpt' => $data['excerpt'] !== '' ? $data['excerpt'] : excerpt($content, 300),
        'content' => $content,
        'image' => $image,
        'consent_confirmed' => $consent,
        'status' => $data['status'],
        'published_at' => $publishedAt,
    ];
    if ($story) {
        db_update('success_stories', $row, 'id = :id', ['id' => $id]);
        admin_log('story_updated', 'Story #' . $id . ' "' . $data['title'] . '" saved (' . $data['status'] . ', consent ' . ($consent ? 'confirmed' : 'not confirmed') . ')');
    } else {
        $row['created_by'] = admin_id();
        $id = db_insert('success_stories', $row);
        admin_log('story_created', 'Story #' . $id . ' "' . $data['title'] . '" created (' . $data['status'] . ', consent ' . ($consent ? 'confirmed' : 'not confirmed') . ')');
    }
    if ($oldImage && $oldImage !== $image) {
        content_delete_image($oldImage);
    }
    flash('success', 'Story saved' . ($data['status'] === 'published' ? ' and published.' : ' as ' . $data['status'] . '.'));
    admin_redirect('story-edit.php?id=' . $id);
}

$v = function (string $key, $default = '') use ($story) {
    return old($key, $story[$key] ?? $default);
};
$status = (string) $v('status', 'draft');
$consentChecked = old('consent_confirmed', null) !== null ? true : (bool) ($story['consent_confirmed'] ?? false);
if (old('title', null) !== null && old('consent_confirmed', null) === null) {
    $consentChecked = false;
}
$visible = $story && $story['status'] === 'published' && (int) $story['consent_confirmed'] === 1;

admin_page_start($story ? 'Edit story' : 'New story', [['label' => 'All stories', 'url' => admin_url('stories.php'), 'icon' => 'list', 'style' => 'ghost']]);
?>
<?php if ($story): ?>
    <div class="note-box mb-3" style="white-space:normal;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <?= status_badge($story['status']) ?> <?= (int) $story['consent_confirmed'] ? '<span class="badge badge-green">Consent confirmed</span>' : '<span class="badge badge-gold">Consent not confirmed</span>' ?>
        <span><?= $visible ? 'Live at' : 'Address when published:' ?> <a href="<?= e(story_url($story['slug'])) ?>" target="_blank" rel="noopener"><?= e(abs_url('success-stories/' . $story['slug'] . '/')) ?></a></span>
    </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" action="<?= e(admin_url($self)) ?>" data-validate>
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <div class="admin-grid-main">
        <div>
            <section class="panel">
                <div class="form-grid">
                    <div class="form-group full"><label for="title">Title<span class="req">*</span></label>
                        <input type="text" id="title" name="title" required minlength="3" maxlength="220" value="<?= e($v('title')) ?>" data-slug-source="#slug"><?= field_error('title') ?></div>
                    <div class="form-group full"><label for="slug">Web address (slug)</label>
                        <input type="text" id="slug" name="slug" maxlength="200" value="<?= e($v('slug')) ?>" <?= $story ? 'data-touched="1"' : '' ?>>
                        <span class="help">/success-stories/<strong>this-part</strong>/</span><?= field_error('slug') ?></div>
                    <div class="form-group"><label for="person_name">Name of the person</label>
                        <input type="text" id="person_name" name="person_name" maxlength="120" value="<?= e($v('person_name')) ?>">
                        <span class="help">Use a changed name or first name only if the person prefers.</span><?= field_error('person_name') ?></div>
                    <div class="form-group"><label for="location">Location</label>
                        <input type="text" id="location" name="location" maxlength="150" value="<?= e($v('location')) ?>" placeholder="e.g. Village / district, state">
                        <?= field_error('location') ?></div>
                    <div class="form-group full"><label for="excerpt">Summary</label>
                        <textarea id="excerpt" name="excerpt" maxlength="400" rows="3" data-counter style="min-height:90px"><?= e($v('excerpt')) ?></textarea>
                        <span class="help">Shown on cards. Leave empty to use the start of the story.</span><?= field_error('excerpt') ?></div>
                    <div class="form-group full"><label for="content">Story<span class="req">*</span></label>
                        <textarea id="content" name="content" rows="16" required data-editor data-upload-url="<?= e(admin_url('story-edit.php?upload=1' . ($story ? '&id=' . $id : ''))) ?>"><?= e($v('content')) ?></textarea>
                        <span class="help">Tell the story in the person's own words. Avoid details that could identify or endanger them unless they have agreed.</span><?= field_error('content') ?></div>
                </div>
            </section>
        </div>
        <div>
            <section class="panel">
                <div class="panel-head"><h2>Consent &amp; publishing</h2></div>
                <label class="checkbox mb-2" style="background:var(--gold-100);border:1px solid #f1dc93;border-radius:10px;padding:12px">
                    <input type="checkbox" id="consent_confirmed" name="consent_confirmed" value="1"<?= $consentChecked ? ' checked' : '' ?> data-consent-for="status" data-consent-warning="consent-warning">
                    <span><strong><?= e(STORY_CONSENT_LABEL) ?></strong><br><small class="muted">Including a parent or guardian's consent for anyone under 18.</small></span>
                </label>
                <?= field_error('consent_confirmed') ?>
                <p class="field-error" id="consent-warning" hidden>Publishing needs the consent confirmation above.</p>
                <div class="form-group mb-2"><label for="status">Status</label>
                    <select id="status" name="status">
                        <?php foreach (['draft' => 'Draft (not visible)', 'published' => 'Published', 'archived' => 'Archived (hidden)'] as $k => $label): ?>
                            <option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select><?= field_error('status') ?></div>
                <div class="form-group mb-2"><label for="published_at">Publish date</label>
                    <input id="published_at" type="datetime-local" name="published_at" value="<?= e(content_dt_input((string) $v('published_at'))) ?>">
                    <span class="help">Empty = now when published.</span><?= field_error('published_at') ?></div>
                <div class="form-group mb-2"><label for="program_id">Programme (optional)</label>
                    <select id="program_id" name="program_id"><?= options_html($programs, (string) $v('program_id'), true, 'None') ?></select><?= field_error('program_id') ?></div>
                <div class="form-actions" style="margin-top:14px">
                    <button class="btn btn-primary" type="submit"><?= icon('check') ?>Save story</button>
                    <?php if ($story): ?><a class="btn btn-ghost" href="<?= e(story_url($story['slug'])) ?>" target="_blank" rel="noopener"><?= icon('eye') ?><?= $visible ? 'View' : 'Preview' ?></a><?php endif; ?>
                </div>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Photo</h2></div>
                <?php $img = $story['image'] ?? ''; ?>
                <img id="img-preview" class="mb-2" src="<?= e($img ? image_url($img) : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7') ?>" alt="" width="640" height="400" style="width:100%;border-radius:10px;aspect-ratio:3/2;object-fit:cover;background:var(--green-50)"<?= $img ? '' : ' hidden' ?>>
                <div class="form-group"><label for="image"><?= $img ? 'Replace photo' : 'Upload photo' ?></label>
                    <input id="image" type="file" name="image" accept=".jpg,.jpeg,.png,.webp" data-preview="#img-preview">
                    <span class="help">Only with the person's consent. Prefer photos that show people with dignity.</span><?= field_error('image') ?></div>
                <?php if ($img): ?><label class="checkbox mt-1"><input type="checkbox" name="remove_image" value="1"><span>Remove the photo</span></label><?php endif; ?>
            </section>
        </div>
    </div>
</form>

<?php if ($story): ?>
    <section class="panel">
        <div class="panel-head"><h2>Delete story</h2></div>
        <form method="post" action="<?= e(admin_url($self)) ?>" data-confirm="Delete this story permanently?">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $id ?>">
            <p class="muted small">If the person withdraws consent, delete the story (or archive it) straight away.</p>
            <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash') ?>Delete story</button>
        </form>
    </section>
<?php endif; ?>
<script src="<?= e(asset('assets/js/modules/media.js')) ?>" defer></script>
<?php
admin_page_end();
