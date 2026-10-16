<?php
/** Create / edit a campaign. Publishing (active, completed, closed) requires a description and an image. */
require_once __DIR__ . '/includes/auth.php';
require_permission('campaigns.manage');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$c = $id ? db_row('SELECT * FROM campaigns WHERE id = ?', [$id]) : null;
if ($id && !$c) {
    flash('error', 'Campaign not found.');
    admin_redirect('campaigns.php');
}
$self = 'campaign-edit.php' . ($id ? '?id=' . $id : '');
$categories = campaign_categories();
$programs = db_rows("SELECT id, title FROM programs ORDER BY sort_order, title");
$editableStatuses = ['draft' => 'Draft (staff only)', 'active' => 'Active - accepting donations', 'completed' => 'Completed', 'closed' => 'Closed'];
// A campaign with donations or approved expenses cannot go back to Draft: that would hide its figures from the public
// transparency and utilisation reports while the verified totals still count its donations.
$draftLocked = $c && $c['status'] !== 'draft' && (
    (int) db_value("SELECT COUNT(*) FROM donations WHERE campaign_id = ? AND status IN ('success','refunded','pending')", [$id]) > 0
    || (int) db_value("SELECT COUNT(*) FROM campaign_expenses WHERE campaign_id = ? AND status = 'approved'", [$id]) > 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_only($self);
    [$data, $errors] = validate($_POST, [
        'title'             => 'required|min:5|max:200',
        'slug'              => 'max:200',
        'program_id'        => 'integer',
        'category'          => 'required|in:' . implode(',', array_keys($categories)),
        'short_description' => 'required|text|min:20|max:300',
        'description'       => 'text|max:200000',
        'goal_amount'       => 'numeric|min:0|max:1000000000',
        'location'          => 'max:150',
        'start_date'        => 'date',
        'end_date'          => 'date',
        'status'            => 'required|in:' . implode(',', array_keys($editableStatuses)),
        'meta_title'        => 'max:200',
        'meta_description'  => 'text|max:300',
    ], ['short_description' => 'Short description', 'goal_amount' => 'Target amount', 'program_id' => 'Programme', 'meta_title' => 'SEO title', 'meta_description' => 'SEO description']);

    if ($draftLocked && $data['status'] === 'draft' && !isset($errors['status'])) {
        $errors['status'] = 'This campaign already has donations or approved expenses, so it cannot go back to Draft (that would hide its figures from the public transparency reports). Set it to Completed or Closed instead.';
    }
    if (!isset($errors['end_date']) && $data['start_date'] !== '' && $data['end_date'] !== '' && $data['end_date'] < $data['start_date']) {
        $errors['end_date'] = 'The end date cannot be before the start date.';
    }
    if (!isset($errors['program_id']) && $data['program_id'] !== '' && (int) $data['program_id'] > 0 && !db_value('SELECT id FROM programs WHERE id = ?', [(int) $data['program_id']])) {
        $errors['program_id'] = 'Programme not found.';
    }
    $description = clean_html((string) $data['description']);
    $publishing = in_array($data['status'], ['active', 'completed', 'closed'], true);
    if ($publishing && mb_strlen(trim(strip_tags($description))) < 80) {
        $errors['description'] = 'Add a full description (at least a short paragraph) before publishing the campaign.';
    }
    if ($data['status'] === 'active' && $data['end_date'] !== '' && $data['end_date'] < date('Y-m-d')) {
        $errors['end_date'] = 'An active campaign cannot have an end date in the past. Change the date or set the status to Completed / Closed.';
    }
    $removeImage = !empty($_POST['remove_image']);
    $hasImage = ($c && $c['image'] && !$removeImage) || (!empty($_FILES['image']['name']) && ($_FILES['image']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE);
    if ($publishing && !$hasImage) {
        $errors['image'] = 'Upload a campaign image before publishing the campaign.';
    }
    if ($errors) {
        back_with_errors($errors, 'admin/' . $self, $_POST);
    }
    $newImage = null;
    if (!empty($_FILES['image']['name']) && ($_FILES['image']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
        $newImage = admin_upload_image('image', 'campaigns', $data['title']);
        if (!$newImage) {
            back_with_errors(['image' => 'The image could not be uploaded. Use a JPG, PNG or WebP file up to ' . upload_limit_label() . ' MB.'], 'admin/' . $self, $_POST);
        }
    }

    $slugSource = trim($data['slug']) !== '' ? $data['slug'] : $data['title'];
    $row = [
        'title'             => $data['title'],
        'slug'              => unique_slug('campaigns', $slugSource, $id),
        'program_id'        => $data['program_id'] !== '' && (int) $data['program_id'] > 0 ? (int) $data['program_id'] : null,
        'category'          => $data['category'],
        'short_description' => $data['short_description'],
        'description'       => $description,
        'goal_amount'       => $data['goal_amount'] !== '' ? round((float) $data['goal_amount'], 2) : 0,
        'location'          => $data['location'] !== '' ? $data['location'] : null,
        'start_date'        => $data['start_date'] !== '' ? $data['start_date'] : null,
        'end_date'          => $data['end_date'] !== '' ? $data['end_date'] : null,
        'status'            => $data['status'],
        'is_featured'       => !empty($_POST['is_featured']) ? 1 : 0,
        'is_urgent'         => !empty($_POST['is_urgent']) ? 1 : 0,
        'meta_title'        => $data['meta_title'] !== '' ? $data['meta_title'] : null,
        'meta_description'  => $data['meta_description'] !== '' ? $data['meta_description'] : null,
        'updated_by'        => admin_id(),
    ];
    if ($publishing && !$row['start_date'] && $data['status'] === 'active') {
        $row['start_date'] = date('Y-m-d');
    }
    $oldImage = $c['image'] ?? null;
    if ($newImage) {
        $row['image'] = $newImage;
    } elseif ($removeImage) {
        $row['image'] = null;
    }
    if ($c) {
        db_update('campaigns', $row, 'id = :id', ['id' => $id]);
        $change = $c['status'] !== $data['status'] ? ' status ' . $c['status'] . ' -> ' . $data['status'] : '';
        admin_log($c['status'] !== $data['status'] && $data['status'] === 'active' ? 'campaign_published' : 'campaign_updated', 'Campaign #' . $id . ' ' . $data['title'] . $change);
        flash('success', 'Campaign saved.' . ($change ? ' Status is now ' . humanize($data['status']) . '.' : ''));
    } else {
        $row['created_by'] = admin_id();
        $id = db_insert('campaigns', $row);
        admin_log('campaign_created', 'Campaign #' . $id . ' ' . $data['title'] . ' (' . $data['status'] . ')');
        flash('success', 'Campaign created.' . ($data['status'] === 'draft' ? ' It is a draft - publish it by setting the status to Active.' : ''));
    }
    if (($newImage || $removeImage) && $oldImage && !db_value('SELECT COUNT(*) FROM campaigns WHERE image = ?', [$oldImage])) {
        delete_upload($oldImage);
    }
    // SEO: a changed address of a public campaign gets a one-hop 301; a new or moved address is released from old redirects
    $public = ['active', 'completed', 'closed'];
    if ($c && $c['slug'] !== $row['slug'] && in_array($c['status'], $public, true) && function_exists('seo_slug_changed')) {
        $moved = seo_slug_changed('campaign', campaign_url($c['slug']), campaign_url($row['slug']));
        if (!empty($moved['message'])) {
            flash($moved['ok'] ? 'info' : 'warning', (string) $moved['message']);
        }
    } elseif ((!$c || $c['slug'] !== $row['slug']) && function_exists('seo_slug_claim')) {
        seo_slug_claim(campaign_url($row['slug']));
    }
    if (in_array($data['status'], $public, true) && function_exists('seo_indexnow_ping')) {
        seo_indexnow_ping([campaign_url($row['slug'])]);   // only when IndexNow is switched on (off by default)
    }
    admin_redirect('campaign-view.php?id=' . $id);
}

$v = function (string $key, $default = '') use ($c) {
    return old($key, $c[$key] ?? $default);
};
admin_page_start($c ? 'Edit campaign' : 'New campaign', $c ? [['label' => 'Campaign overview', 'url' => admin_url('campaign-view.php?id=' . $id), 'icon' => 'eye', 'style' => 'ghost']] : []);
?>
<?php if ($c && in_array($c['status'], ['proposal', 'rejected'], true)): ?>
    <div class="alert alert-info"><?= icon('info') ?><div>This campaign came from a fundraiser proposal (status: <?= e(humanize($c['status'])) ?>). Saving it here moves it into the normal campaign workflow.</div></div>
<?php endif; ?>
<form method="post" enctype="multipart/form-data" class="admin-grid-main" data-validate>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <div>
        <section class="panel">
            <div class="panel-head"><h2>Campaign details</h2></div>
            <div class="form-grid">
                <div class="form-group full"><label for="title">Title<span class="req">*</span></label><input id="title" name="title" required minlength="5" maxlength="200" value="<?= e($v('title')) ?>"><?= field_error('title') ?></div>
                <div class="form-group"><label for="slug">URL slug</label><input id="slug" name="slug" maxlength="200" pattern="[a-z0-9\-]*" placeholder="created from the title" value="<?= e($v('slug')) ?>"><span class="help">Address: /campaign/<strong><?= e($c['slug'] ?? 'your-slug') ?></strong>/ (lowercase letters, numbers, hyphens).</span><?= field_error('slug') ?></div>
                <div class="form-group"><label for="category">Cause / category<span class="req">*</span></label><select id="category" name="category" required><?= options_html($categories, (string) $v('category', 'general'), true, 'Select') ?></select><?= field_error('category') ?></div>
                <div class="form-group"><label for="program_id">Linked programme</label><select id="program_id" name="program_id"><option value="">None</option><?php foreach ($programs as $pr): ?><option value="<?= (int) $pr['id'] ?>"<?= (string) $v('program_id') === (string) $pr['id'] ? ' selected' : '' ?>><?= e($pr['title']) ?></option><?php endforeach; ?></select><?= field_error('program_id') ?></div>
                <div class="form-group"><label for="location">Location</label><input id="location" name="location" maxlength="150" placeholder="District, state" value="<?= e($v('location')) ?>"><?= field_error('location') ?></div>
                <div class="form-group full"><label for="short_description">Short description<span class="req">*</span></label><textarea id="short_description" name="short_description" required minlength="20" maxlength="300" rows="2"><?= e($v('short_description')) ?></textarea><span class="help">Shown on campaign cards and in search results (max 300 characters).</span><?= field_error('short_description') ?></div>
                <div class="form-group full"><label for="description">Full description<?= ' ' ?><small class="muted">(required to publish)</small></label><textarea id="description" name="description" rows="14" data-editor><?= e($v('description')) ?></textarea>
                    <span class="help">Describe the need, what donations will pay for and how progress will be reported. Do not invent figures or beneficiary numbers; publish identifiable photos only with consent.</span><?= field_error('description') ?></div>
            </div>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>Search engines & sharing</h2></div>
            <div class="form-grid">
                <div class="form-group full"><label for="meta_title">SEO title</label><input id="meta_title" name="meta_title" maxlength="200" value="<?= e($v('meta_title')) ?>"><span class="help">Leave blank to use the campaign title.</span><?= field_error('meta_title') ?></div>
                <div class="form-group full"><label for="meta_description">SEO description</label><textarea id="meta_description" name="meta_description" maxlength="300" rows="2"><?= e($v('meta_description')) ?></textarea><span class="help">Leave blank to use the short description.</span><?= field_error('meta_description') ?></div>
            </div>
        </section>
    </div>
    <div>
        <section class="panel">
            <div class="panel-head"><h2>Publishing</h2><?= $c ? status_badge($c['status']) : '' ?></div>
            <div class="form-group"><label for="status">Status<span class="req">*</span></label><select id="status" name="status" required<?= $draftLocked ? ' aria-describedby="status_help"' : '' ?>><?= options_html($draftLocked ? array_diff_key($editableStatuses, ['draft' => 1]) : $editableStatuses, (string) old('status', in_array($c['status'] ?? 'draft', array_keys($editableStatuses), true) ? ($c['status'] ?? 'draft') : 'draft'), true, '') ?></select><?php if ($draftLocked): ?><span class="help" id="status_help">Draft is not available: this campaign has donations or approved expenses.</span><?php endif; ?><?= field_error('status') ?></div>
            <label class="checkbox mt-2"><input type="checkbox" name="is_featured" value="1"<?= (old('is_featured', $c['is_featured'] ?? 0)) ? ' checked' : '' ?>> <span>Featured (shown at the top of the campaign list)</span></label>
            <label class="checkbox mt-1"><input type="checkbox" name="is_urgent" value="1"<?= (old('is_urgent', $c['is_urgent'] ?? 0)) ? ' checked' : '' ?>> <span>Urgent (only for a genuine, current emergency)</span></label>
            <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save campaign</button>
                <a class="btn btn-ghost" href="<?= e(admin_url($c ? 'campaign-view.php?id=' . $id : 'campaigns.php')) ?>">Cancel</a>
                <?php if ($c && function_exists('seo_entity_edit_url') && admin_can('seo.manage')): ?><a class="btn btn-ghost btn-sm" href="<?= e(seo_entity_edit_url('campaign', (int) $c['id'])) ?>"><?= icon('search') ?>SEO settings</a><?php endif; ?></div>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>Target & dates</h2></div>
            <div class="form-group"><label for="goal_amount">Target amount (₹)</label><input id="goal_amount" name="goal_amount" type="number" min="0" step="1" value="<?= e((string) (float) $v('goal_amount', 0) ?: '0') ?>"><span class="help">0 = no target (the page shows the verified amount without a percentage).</span><?= field_error('goal_amount') ?></div>
            <div class="form-group mt-2"><label for="start_date">Start date</label><input id="start_date" name="start_date" type="date" value="<?= e($v('start_date')) ?>"><?= field_error('start_date') ?></div>
            <div class="form-group mt-2"><label for="end_date">End date</label><input id="end_date" name="end_date" type="date" value="<?= e($v('end_date')) ?>"><span class="help">A countdown is shown only while a real end date is in the future. Leave blank for an open-ended campaign.</span><?= field_error('end_date') ?></div>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>Campaign image</h2></div>
            <?php if ($c && $c['image']): ?>
                <img src="<?= e(image_url($c['image'])) ?>" alt="Current image" style="width:100%;border-radius:10px;margin-bottom:10px;aspect-ratio:16/9;object-fit:cover">
                <label class="checkbox mb-2"><input type="checkbox" name="remove_image" value="1"> <span>Remove the current image</span></label>
            <?php endif; ?>
            <div class="form-group"><label for="image"><?= $c && $c['image'] ? 'Replace image' : 'Upload image' ?></label><input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp"><span class="help">JPG, PNG or WebP, up to <?= e(upload_limit_label()) ?> MB, landscape 16:9 works best. Required to publish.</span><?= field_error('image') ?></div>
        </section>
        <?php if ($c && $c['submitted_by']): ?>
            <section class="panel">
                <div class="panel-head"><h2>Organiser</h2></div>
                <?php details_list(['Name' => $c['organizer_name'], 'Email' => $c['organizer_email'], 'Phone' => $c['organizer_phone'], 'Notes' => $c['proposal_notes'], 'Review notes' => $c['review_notes']]); ?>
            </section>
        <?php endif; ?>
    </div>
</form>
<?php
admin_page_end();
