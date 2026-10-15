<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('content');

$links = ['about' => 'about.php', 'our-team' => 'our-team.php', 'our-work' => 'our-work.php', 'volunteer' => 'volunteer.php',
    'volunteer-teacher' => 'volunteer-teacher.php', 'volunteer-staff' => 'volunteer-staff.php', 'membership' => 'membership.php',
    'beneficiary' => 'beneficiary.php', 'scholarship' => 'scholarship.php', 'impact-reports' => 'impact-reports.php',
    'financial-transparency' => 'financial-transparency.php', 'privacy-policy' => 'privacy-policy.php', 'terms' => 'terms.php',
    'donation-policy' => 'donation-policy.php', 'volunteer-policy' => 'volunteer-policy.php', 'child-protection-policy' => 'child-protection-policy.php',
    'grievance' => 'grievance.php'];
foreach (PROGRAMS as $k => $p) {
    $links[$k] = $p['file'];
}

$editId = (int) ($_GET['edit'] ?? 0);
if ($editId) {
    $page = db_row('SELECT * FROM website_pages WHERE id = ?', [$editId]);
    if (!$page) {
        flash('error', 'Page not found.');
        admin_redirect('pages.php');
    }
    $adminTitle = 'Edit page: ' . $page['title'];
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="page-actions">
        <a class="btn btn-ghost btn-sm" href="pages.php">&larr; All pages</a>
        <?php if (isset($links[$page['slug']])): ?><a class="btn btn-ghost btn-sm" href="<?= e(url($links[$page['slug']])) ?>" target="_blank"><?= icon('external') ?>View page</a><?php endif; ?>
    </div>
    <form method="post" action="actions/content-save.php" enctype="multipart/form-data" data-validate>
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="page">
        <input type="hidden" name="id" value="<?= (int) $page['id'] ?>">
        <div class="admin-grid-main">
            <div class="panel">
                <div class="form-group mb-2"><label for="title">Title<span class="req">*</span></label><input id="title" type="text" name="title" required maxlength="200" value="<?= e(old('title', $page['title'])) ?>"><?= field_error('title') ?></div>
                <div class="form-group mb-2"><label for="subtitle">Subtitle (shown in the banner)</label><input id="subtitle" type="text" name="subtitle" maxlength="300" value="<?= e(old('subtitle', $page['subtitle'])) ?>"></div>
                <div class="form-group"><label for="content">Content<span class="req">*</span></label>
                    <textarea id="content" name="content" required data-editor data-upload-url="actions/upload-image.php"><?= e(old('content', $page['content'])) ?></textarea>
                    <span class="help">Use the buttons to format text. Only safe HTML is kept (scripts, styles and embedded frames are removed).</span><?= field_error('content') ?></div>
            </div>
            <div>
                <div class="panel">
                    <div class="form-group mb-2"><label for="status">Visibility</label><select id="status" name="status"><?= options_html(['published' => 'Published', 'hidden' => 'Hidden (page shows "not found")'], old('status', $page['status']), true, '') ?></select></div>
                    <div class="form-group mb-2"><label for="meta_description">Search description (SEO)</label><textarea id="meta_description" name="meta_description" maxlength="300" rows="3" style="min-height:80px"><?= e(old('meta_description', $page['meta_description'])) ?></textarea></div>
                    <?php if ($page['page_group'] === 'program'): ?>
                        <div class="form-group mb-2"><label for="banner_image">Programme image</label>
                            <?php if ($page['banner_image']): ?><img src="<?= e(image_url($page['banner_image'])) ?>" alt="" class="mb-1" style="border-radius:8px;max-height:140px"><label class="checkbox"><input type="checkbox" name="remove_banner" value="1"> <span>Remove image</span></label><?php endif; ?>
                            <input id="banner_image" type="file" name="banner_image" accept=".jpg,.jpeg,.png,.webp"><?= field_error('banner_image') ?></div>
                    <?php endif; ?>
                    <button class="btn btn-primary btn-block" type="submit">Save page</button>
                    <p class="small muted mt-2">Last updated <?= e(format_date($page['updated_at'] ?: $page['created_at'], 'd M Y, h:i A')) ?></p>
                </div>
            </div>
        </div>
    </form>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$pages = db_rows("SELECT * FROM website_pages ORDER BY FIELD(page_group,'about','program','involve','resource','policy'), title");
$docs = public_documents();
$adminTitle = 'Pages & Documents';
require __DIR__ . '/includes/header.php';
?>
<div class="panel">
    <div class="panel-head"><h3>Website pages</h3><span class="muted small">Edit the text of each page. Forms, campaigns and lists are generated automatically.</span></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Page</th><th>Group</th><th>Status</th><th>Updated</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pages as $pg): ?>
            <tr>
                <td><a href="?edit=<?= (int) $pg['id'] ?>"><strong><?= e($pg['title']) ?></strong></a><br><small class="muted"><?= e($links[$pg['slug']] ?? $pg['slug']) ?></small></td>
                <td><?= e(humanize($pg['page_group'])) ?></td>
                <td><?= status_badge($pg['status']) ?></td>
                <td class="nowrap"><?= e(format_date($pg['updated_at'] ?: $pg['created_at'])) ?></td>
                <td class="actions-cell">
                    <a class="icon-btn" href="?edit=<?= (int) $pg['id'] ?>" aria-label="Edit"><?= icon('edit') ?></a>
                    <?php if (isset($links[$pg['slug']])): ?><a class="icon-btn" href="<?= e(url($links[$pg['slug']])) ?>" target="_blank" aria-label="View"><?= icon('external') ?></a><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="panel" id="documents">
    <div class="panel-head"><h3>Public documents</h3><span class="muted small">Annual/impact reports, audited statements and registration certificates (PDF). Shown on Impact Reports and Financial Transparency.</span></div>
    <form class="filter-row" method="post" action="actions/content-save.php" enctype="multipart/form-data" data-validate>
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="document">
        <input type="text" name="doc_title" required maxlength="150" placeholder="Document title, e.g. Annual Report 2025-26" aria-label="Title">
        <select name="doc_category" aria-label="Category"><?= options_html(DOCUMENT_CATEGORIES, 'impact', true, '') ?></select>
        <input type="date" name="doc_date" aria-label="Date">
        <input type="file" name="document" required accept=".pdf" aria-label="PDF file">
        <button class="btn btn-primary btn-sm" type="submit"><?= icon('plus') ?>Upload PDF</button>
    </form>
    <?php if ($docs): ?>
        <div class="table-wrap"><table class="table">
            <tbody>
            <?php foreach ($docs as $d): ?>
                <tr>
                    <td><a href="<?= e(url($d['path'])) ?>" target="_blank"><strong><?= e($d['title']) ?></strong></a></td>
                    <td><?= e(DOCUMENT_CATEGORIES[$d['category']] ?? $d['category']) ?></td>
                    <td><?= e(format_date($d['date'] ?? '')) ?></td>
                    <td class="actions-cell">
                        <form class="inline-form" method="post" action="actions/content-save.php" data-confirm="Remove this document from the website?">
                            <?= csrf_field() ?><input type="hidden" name="type" value="document"><input type="hidden" name="action" value="delete"><input type="hidden" name="doc_id" value="<?= e($d['id']) ?>">
                            <button class="icon-btn danger" type="submit" aria-label="Delete"><?= icon('trash') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php else: ?>
        <p class="muted mb-0">No public documents uploaded yet.</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
