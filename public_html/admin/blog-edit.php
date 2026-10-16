<?php
/**
 * Create / edit a blog post, news item or press release.
 * Content is sanitised with clean_html(); a future publish date schedules the post.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('blog.manage');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$post = $id ? db_row('SELECT * FROM blog_posts WHERE id = ?', [$id]) : null;
if ($id && !$post) {
    flash('error', 'Post not found.');
    admin_redirect('blog.php');
}
$self = 'blog-edit.php' . ($post ? '?id=' . $id : '');

// Image upload from the editor toolbar (JSON)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_GET['upload'])) {
    content_editor_upload('blog', $post ? 'post #' . $id : 'new post');
}

$programs = content_programs();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only($self);
    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'delete' && $post) {
        db_query('DELETE FROM blog_posts WHERE id = ?', [$id]);
        content_delete_image($post['image']);
        admin_log('blog_deleted', 'Post #' . $id . ' "' . $post['title'] . '" deleted');
        flash('success', 'The post was deleted.');
        admin_redirect('blog.php');
    }

    [$data, $errors] = validate($_POST, [
        'title' => 'required|min:3|max:220',
        'slug' => 'max:200',
        'category' => 'required|in:' . implode(',', array_keys(BLOG_CATEGORIES)),
        'program_id' => 'integer',
        'excerpt' => 'text|max:400',
        'author_name' => 'max:120',
        'meta_title' => 'max:200',
        'meta_description' => 'text|max:300',
        'status' => 'required|in:draft,published,archived',
        'published_at' => 'datetime',
    ], ['program_id' => 'Programme', 'published_at' => 'Publish date', 'meta_title' => 'Meta title', 'meta_description' => 'Meta description']);
    $content = clean_html((string) ($_POST['content'] ?? ''));
    if (trim(strip_tags($content, '<img>')) === '') {
        $errors['content'] = 'Please write the content of the post.';
    }
    $programId = $data['program_id'] !== '' ? (int) $data['program_id'] : null;
    if ($programId && !isset($programs[$programId])) {
        $errors['program_id'] = 'Please choose a valid programme.';
    }
    $eeat = [];
    if (function_exists('seo_eeat_validate_post')) {
        [$eeat, $eeatErrors] = seo_eeat_validate_post($_POST);   // author / reviewer / sources (SEO module)
        $errors += $eeatErrors;
    }
    if ($errors) {
        back_with_errors($errors, 'admin/' . $self, $_POST);
    }

    // Image (uploaded after validation so a failed form leaves no stray file)
    $image = $post['image'] ?? null;
    $oldImage = $image;
    $before = count($_SESSION['_flash'] ?? []);
    $uploaded = admin_upload_image('image', 'blog', $data['title']);
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
        'slug' => unique_slug('blog_posts', $data['slug'] !== '' ? $data['slug'] : $data['title'], $id),
        'category' => $data['category'],
        'program_id' => $programId,
        'excerpt' => $data['excerpt'] !== '' ? $data['excerpt'] : excerpt($content, 300),
        'content' => $content,
        'image' => $image,
        'author_name' => $data['author_name'] !== '' ? $data['author_name'] : null,
        'meta_title' => $data['meta_title'] !== '' ? $data['meta_title'] : null,
        'meta_description' => $data['meta_description'] !== '' ? $data['meta_description'] : null,
        'status' => $data['status'],
        'published_at' => $publishedAt,
        'updated_by' => admin_id(),
    ];
    $when = $data['status'] === 'published' && strtotime((string) $publishedAt) > time() ? 'scheduled for ' . format_date($publishedAt, 'd M Y, h:i A') : $data['status'];
    if ($post) {
        db_update('blog_posts', $row, 'id = :id', ['id' => $id]);
        admin_log('blog_updated', 'Post #' . $id . ' "' . $data['title'] . '" saved (' . $when . ')');
    } else {
        $row['created_by'] = admin_id();
        $id = db_insert('blog_posts', $row);
        admin_log('blog_created', 'Post #' . $id . ' "' . $data['title'] . '" created (' . $when . ')');
    }
    if ($oldImage && $oldImage !== $image) {
        content_delete_image($oldImage);
    }
    if ($eeat && function_exists('seo_eeat_save')) {
        $eeatChanges = seo_eeat_save('post', (int) $id, $eeat, admin_id());
        if ($eeatChanges) {
            admin_log('seo_eeat_updated', 'Post #' . $id . ': ' . implode(', ', array_keys($eeatChanges)));
        }
    }
    // SEO: a changed address of a published post gets a one-hop 301 (older redirects and menu links re-pointed);
    // a new or moved address is released from any redirect that would hide it
    $slugNote = '';
    if ($post && $post['slug'] !== $row['slug'] && $post['status'] === 'published' && function_exists('seo_slug_changed')) {
        $slugNote = ' ' . (string) (seo_slug_changed('post', post_url($post['slug']), post_url($row['slug']))['message'] ?? '');
    } elseif ((!$post || $post['slug'] !== $row['slug']) && function_exists('seo_slug_claim')) {
        seo_slug_claim(post_url($row['slug']));
    }
    if ($data['status'] === 'published' && strtotime((string) $publishedAt) <= time() && function_exists('seo_indexnow_ping')) {
        seo_indexnow_ping([post_url($row['slug'])]);   // only when IndexNow is switched on (off by default)
    }
    flash('success', 'Post saved (' . $when . ').' . rtrim($slugNote));
    admin_redirect('blog-edit.php?id=' . $id);
}

$v = function (string $key, $default = '') use ($post) {
    return old($key, $post[$key] ?? $default);
};
$status = (string) $v('status', 'draft');
$scheduled = $post && $post['status'] === 'published' && $post['published_at'] && strtotime($post['published_at']) > time();
$isLive = $post && $post['status'] === 'published' && !$scheduled;

admin_page_start($post ? 'Edit post' : 'New post', [['label' => 'All posts', 'url' => admin_url('blog.php'), 'icon' => 'list', 'style' => 'ghost']]);
?>
<?php if ($post): ?>
    <div class="note-box mb-3" style="white-space:normal;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <?= $scheduled ? '<span class="badge badge-blue">Scheduled</span>' : status_badge($post['status']) ?>
        <span><?= $isLive ? 'Live at' : 'Address when published:' ?> <a href="<?= e(post_url($post['slug'])) ?>" target="_blank" rel="noopener"><?= e(abs_url('blog/' . $post['slug'] . '/')) ?></a><?= $isLive ? '' : ' (staff preview available)' ?></span>
        <span class="muted">· <?= number_format((int) $post['views']) ?> views · Updated <?= e(format_date($post['updated_at'] ?: $post['created_at'], 'd M Y, h:i A')) ?></span>
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
                        <input type="text" id="slug" name="slug" maxlength="200" value="<?= e($v('slug')) ?>" <?= $post ? 'data-touched="1"' : '' ?>>
                        <span class="help">/blog/<strong>this-part</strong>/ - lower-case letters, numbers and hyphens. Changing it breaks links that were already shared.</span><?= field_error('slug') ?></div>
                    <div class="form-group full"><label for="excerpt">Summary (excerpt)</label>
                        <textarea id="excerpt" name="excerpt" maxlength="400" rows="3" data-counter style="min-height:90px"><?= e($v('excerpt')) ?></textarea>
                        <span class="help">Shown on cards and in search results. Leave empty to use the start of the content.</span><?= field_error('excerpt') ?></div>
                    <div class="form-group full"><label for="content">Content<span class="req">*</span></label>
                        <textarea id="content" name="content" rows="18" required data-editor data-upload-url="<?= e(admin_url('blog-edit.php?upload=1' . ($post ? '&id=' . $id : ''))) ?>"><?= e($v('content')) ?></textarea>
                        <span class="help">Use headings (H2/H3), short paragraphs and lists. Scripts, styles and embeds are removed when you save.</span><?= field_error('content') ?></div>
                </div>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Search engines &amp; sharing</h2></div>
                <div class="form-grid">
                    <div class="form-group full"><label for="meta_title">Meta title</label>
                        <input type="text" id="meta_title" name="meta_title" maxlength="200" data-counter data-ideal="60" value="<?= e($v('meta_title')) ?>"><?= field_error('meta_title') ?></div>
                    <div class="form-group full"><label for="meta_description">Meta description</label>
                        <textarea id="meta_description" name="meta_description" maxlength="300" rows="3" data-counter style="min-height:84px"><?= e($v('meta_description')) ?></textarea>
                        <span class="help">Aim for 120-160 characters. Leave empty to use the summary.</span><?= field_error('meta_description') ?></div>
                </div>
            </section>
        </div>
        <div>
            <section class="panel">
                <div class="panel-head"><h2>Publish</h2></div>
                <div class="form-group mb-2"><label for="status">Status</label>
                    <select id="status" name="status">
                        <?php foreach (['draft' => 'Draft (not visible)', 'published' => 'Published', 'archived' => 'Archived (hidden)'] as $k => $label): ?>
                            <option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select><?= field_error('status') ?></div>
                <div class="form-group mb-2"><label for="published_at">Publish date &amp; time</label>
                    <input id="published_at" type="datetime-local" name="published_at" value="<?= e(content_dt_input((string) $v('published_at'))) ?>">
                    <span class="help">Empty = now when published. A future date schedules the post.</span><?= field_error('published_at') ?></div>
                <div class="form-group mb-2"><label for="category">Category<span class="req">*</span></label>
                    <select id="category" name="category" required><?= options_html(BLOG_CATEGORIES, (string) $v('category', 'blog'), true, '') ?></select><?= field_error('category') ?></div>
                <div class="form-group mb-2"><label for="program_id">Programme (optional)</label>
                    <select id="program_id" name="program_id"><?= options_html($programs, (string) $v('program_id'), true, 'None') ?></select><?= field_error('program_id') ?></div>
                <div class="form-group mb-2"><label for="author_name">Author name</label>
                    <input type="text" id="author_name" name="author_name" maxlength="120" value="<?= e($v('author_name')) ?>" placeholder="<?= e(site_name()) ?>">
                    <span class="help">Leave empty to show the foundation as the author.</span><?= field_error('author_name') ?></div>
                <div class="form-actions" style="margin-top:14px">
                    <button class="btn btn-primary" type="submit"><?= icon('check') ?>Save post</button>
                    <?php if ($post): ?><a class="btn btn-ghost" href="<?= e(post_url($post['slug'])) ?>" target="_blank" rel="noopener"><?= icon('eye') ?><?= $isLive ? 'View' : 'Preview' ?></a><?php endif; ?>
                    <?php if ($post && function_exists('seo_entity_edit_url') && admin_can('seo.manage')): ?><a class="btn btn-ghost btn-sm" href="<?= e(seo_entity_edit_url('post', (int) $post['id'])) ?>"><?= icon('search') ?>SEO settings</a><?php endif; ?>
                </div>
            </section>
            <?= function_exists('seo_eeat_admin_panel') ? seo_eeat_admin_panel('post', (int) $id) : '' ?>
            <section class="panel">
                <div class="panel-head"><h2>Featured image</h2></div>
                <?php $img = $post['image'] ?? ''; ?>
                <img id="img-preview" class="mb-2" src="<?= e($img ? image_url($img) : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7') ?>" alt="" width="640" height="400" style="width:100%;border-radius:10px;aspect-ratio:16/10;object-fit:cover;background:var(--green-50)"<?= $img ? '' : ' hidden' ?>>
                <div class="form-group"><label for="image"><?= $img ? 'Replace image' : 'Upload image' ?></label>
                    <input id="image" type="file" name="image" accept=".jpg,.jpeg,.png,.webp" data-preview="#img-preview">
                    <span class="help">JPG, PNG or WebP, up to <?= e(upload_limit_label()) ?> MB (resized to 1920px). Use photos of identifiable people only with their consent.</span><?= field_error('image') ?></div>
                <?php if ($img): ?><label class="checkbox mt-1"><input type="checkbox" name="remove_image" value="1"><span>Remove the image</span></label><?php endif; ?>
            </section>
        </div>
    </div>
</form>

<?php if ($post): ?>
    <section class="panel">
        <div class="panel-head"><h2>Delete post</h2></div>
        <form method="post" action="<?= e(admin_url($self)) ?>" data-confirm="Delete this post permanently? This cannot be undone.">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $id ?>">
            <p class="muted small">Deleting removes the post from the website for good. To hide it but keep it, set the status to Archived instead.</p>
            <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash') ?>Delete post</button>
        </form>
    </section>
<?php endif; ?>
<script src="<?= e(asset('assets/js/modules/media.js')) ?>" defer></script>
<?php
admin_page_end();
