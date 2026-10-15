<?php
/**
 * Certificates & reports (table `resources`): registration certificates, annual / impact reports, financial
 * statements, utilisation reports, digital resources and press kits. PDFs are stored in uploads/resources/
 * (public), or an external https link can be given instead.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('resources.manage');

/** Where each category is listed on the website. */
$whereShown = [
    'certification' => ['Certificates', 'certifications'], 'annual_report' => ['Impact Reports', 'impact-reports'],
    'impact_report' => ['Impact Reports', 'impact-reports'], 'financial_statement' => ['Financial Transparency', 'financial-transparency'],
    'utilization_report' => ['Utilisation Reports', 'utilization-reports'], 'digital_resource' => ['Digital Resources', 'digital-resources'],
    'press_kit' => ['Media / Press Releases', 'media'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_only('resources.php');
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $existing = $id ? db_row('SELECT * FROM resources WHERE id = ?', [$id]) : null;

    if ($action === 'save') {
        $back = 'admin/resources.php?' . ($existing ? 'edit=' . $id : 'new=1');
        [$data, $errors] = validate($_POST, [
            'category' => 'required|in:' . implode(',', array_keys(RESOURCE_CATEGORIES)),
            'title' => 'required|min:3|max:200',
            'description' => 'max:500',
            'external_url' => 'url|max:255',
            'document_date' => 'date',
            'campaign_id' => 'integer',
            'sort_order' => 'integer|min:-9999|max:9999',
            'status' => 'required|in:published,hidden',
        ], ['external_url' => 'External link', 'document_date' => 'Document date', 'campaign_id' => 'Campaign']);
        if (empty($errors['external_url']) && $data['external_url'] !== '' && stripos($data['external_url'], 'https://') !== 0) {
            $errors['external_url'] = 'External links must start with https://';
        }
        if (empty($errors['document_date']) && $data['document_date'] !== '' && $data['document_date'] > date('Y-m-d')) {
            $errors['document_date'] = 'The document date cannot be in the future.';
        }
        $campaignId = (int) ($data['campaign_id'] ?: 0);
        if ($campaignId && !db_value('SELECT id FROM campaigns WHERE id = ?', [$campaignId])) {
            $errors['campaign_id'] = 'Please choose a valid campaign.';
        }
        $upload = [];
        if (!$errors) {
            $upload = handle_upload('file', 'pdf', 'resources');
            if (!empty($upload['error'])) {
                $errors['file'] = $upload['error'];
            }
        }
        $newFile = $upload['path'] ?? null;
        $keepFile = $existing && $existing['file_path'] && empty($_POST['remove_file']) ? $existing['file_path'] : null;
        if (!$errors && !$newFile && !$keepFile && $data['external_url'] === '') {
            $errors['file'] = 'Upload a PDF or enter an external https link.';
        }
        if ($errors) {
            if ($newFile) {
                delete_upload($newFile);
            }
            back_with_errors($errors, $back, $_POST);
        }
        $row = [
            'category' => $data['category'], 'title' => $data['title'], 'description' => $data['description'] !== '' ? $data['description'] : null,
            'file_path' => $newFile ?: $keepFile, 'external_url' => $data['external_url'] !== '' ? $data['external_url'] : null,
            'campaign_id' => $data['category'] === 'utilization_report' && $campaignId ? $campaignId : null,
            'document_date' => $data['document_date'] !== '' ? $data['document_date'] : null,
            'sort_order' => (int) ($data['sort_order'] ?: 0), 'status' => $data['status'],
        ];
        if ($existing) {
            db_update('resources', $row, 'id = :id', ['id' => $id]);
            if ($existing['file_path'] && $existing['file_path'] !== $row['file_path']) {
                delete_upload($existing['file_path']);
            }
            admin_log('resource_updated', 'Document #' . $id . ' ' . $data['title'] . ($newFile ? ' (new PDF)' : ''));
            flash('success', 'Document updated.');
        } else {
            $id = db_insert('resources', $row + ['uploaded_by' => admin_id()]);
            admin_log('resource_created', 'Document #' . $id . ' ' . $data['title'] . ' [' . $data['category'] . ']');
            flash('success', 'Document added.');
        }
        admin_redirect('resources.php?category=' . $data['category']);
    }
    if ($action === 'toggle' && $existing) {
        $new = $existing['status'] === 'published' ? 'hidden' : 'published';
        db_update('resources', ['status' => $new], 'id = :id', ['id' => $id]);
        admin_log('resource_status', 'Document #' . $id . ' ' . $new);
        flash('success', $new === 'published' ? 'Document published.' : 'Document hidden.');
    }
    if ($action === 'delete' && $existing) {
        db_query('DELETE FROM resources WHERE id = ?', [$id]);
        delete_upload($existing['file_path']);
        admin_log('resource_deleted', 'Document #' . $id . ' ' . $existing['title']);
        flash('success', 'Document deleted.');
    }
    admin_redirect(admin_back('resources.php'));
}

$edit = isset($_GET['edit']) ? db_row('SELECT * FROM resources WHERE id = ?', [(int) $_GET['edit']]) : null;
$showForm = $edit || isset($_GET['new']);
$cat = (string) ($_GET['category'] ?? '');
$params = [];
$where = 'WHERE 1=1';
if (isset(RESOURCE_CATEGORIES[$cat])) {
    $where .= ' AND r.category = ?';
    $params[] = $cat;
}
$q = trim((string) ($_GET['q'] ?? ''));
$where .= search_where(['r.title', 'r.description'], $q, $params);
$rows = db_rows("SELECT r.*, c.title AS campaign_title FROM resources r LEFT JOIN campaigns c ON c.id = r.campaign_id $where
                 ORDER BY r.category, r.sort_order, COALESCE(r.document_date, DATE(r.created_at)) DESC, r.id DESC", $params);
$counts = array_column(db_rows('SELECT category, COUNT(*) n FROM resources GROUP BY category'), 'n', 'category');
$campaigns = db_rows("SELECT id, title FROM campaigns WHERE status IN ('active','completed','closed') ORDER BY created_at DESC LIMIT 200");

admin_page_start('Certificates & Reports', [['label' => 'Add document', 'url' => admin_url('resources.php?new=1' . ($cat ? '&category=' . $cat : '')), 'icon' => 'plus']]);
?>
<div class="alert alert-warning"><?= icon('alert-triangle') ?><div><strong>Upload only genuine documents issued to the foundation.</strong> Files here are public: anyone can open and download them. Never upload documents containing personal data of donors, beneficiaries or staff, bank account details, or signatures that are not meant to be published.</div></div>

<nav class="admin-tabs" aria-label="Categories">
    <a href="<?= e(admin_url('resources.php')) ?>" class="<?= $cat === '' ? 'active' : '' ?>">All (<?= array_sum(array_map('intval', $counts)) ?>)</a>
    <?php foreach (RESOURCE_CATEGORIES as $k => $label): ?>
        <a href="<?= e(admin_url('resources.php?category=' . $k)) ?>" class="<?= $cat === $k ? 'active' : '' ?>"><?= e($label) ?> (<?= (int) ($counts[$k] ?? 0) ?>)</a>
    <?php endforeach; ?>
</nav>

<div<?= $showForm ? ' class="admin-grid-main"' : '' ?>>
    <section class="panel">
        <?php admin_filter_bar([], ['placeholder' => 'Search title or description', 'hidden' => $cat !== '' ? ['category' => $cat] : []]); ?>
        <?php if (!$rows): ?>
            <p class="muted">No documents<?= $cat ? ' in this category' : '' ?> yet. The website shows an honest "will be published here once available" message until documents are added.</p>
        <?php else: ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Document</th><th>Category</th><th>Date</th><th>Status</th><th class="actions-cell">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $href = resource_url($r); ?>
                    <tr>
                        <td><strong><?= e($r['title']) ?></strong><br><small class="muted"><?= $r['file_path'] ? 'PDF' . (is_file(ROOT_PATH . '/' . $r['file_path']) ? ', ' . e(info_file_size((int) filesize(ROOT_PATH . '/' . $r['file_path']))) : ' (file missing)') : 'External link' ?><?= $r['campaign_title'] ? ' · ' . e($r['campaign_title']) : '' ?></small></td>
                        <td><?= e(RESOURCE_CATEGORIES[$r['category']] ?? $r['category']) ?></td>
                        <td class="nowrap"><?= e(format_date($r['document_date'])) ?></td>
                        <td><?= status_badge($r['status']) ?></td>
                        <td class="actions-cell">
                            <?php if ($href !== ''): ?><a class="icon-btn" href="<?= e($href) ?>" target="_blank" rel="noopener" title="Open" aria-label="Open document"><?= icon('external') ?></a><?php endif; ?>
                            <a class="icon-btn" href="<?= e(admin_url('resources.php?edit=' . $r['id'])) ?>" title="Edit" aria-label="Edit document"><?= icon('edit') ?></a>
                            <form method="post" class="inline-form"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button class="icon-btn" type="submit" title="<?= $r['status'] === 'published' ? 'Hide' : 'Publish' ?>" aria-label="<?= $r['status'] === 'published' ? 'Hide' : 'Publish' ?> document"><?= icon($r['status'] === 'published' ? 'eye' : 'check') ?></button></form>
                            <form method="post" class="inline-form" data-confirm="Delete this document and its file permanently?"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button class="icon-btn danger" type="submit" title="Delete" aria-label="Delete document"><?= icon('trash') ?></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
        <h3 class="mt-3">Where documents appear</h3>
        <ul class="small muted">
            <?php foreach ($whereShown as $k => [$label, $slug]): ?>
                <li><?= e(RESOURCE_CATEGORIES[$k]) ?>: <a href="<?= e(page_url($slug)) ?>" target="_blank" rel="noopener"><?= e($label) ?></a><?= $k === 'certification' ? ' and NGO Details' : '' ?></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <?php if ($showForm): $d = $edit ?: []; ?>
    <section class="panel">
        <div class="panel-head"><h2><?= $edit ? 'Edit document' : 'Add a document' ?></h2><?php if ($edit): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('resources.php')) ?>">Cancel</a><?php endif; ?></div>
            <form method="post" enctype="multipart/form-data" class="form-grid">
                <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($d['id'] ?? 0) ?>">
                <div class="form-group full"><label for="category">Category</label><select id="category" name="category"><?= options_html(RESOURCE_CATEGORIES, (string) old('category', $d['category'] ?? ($cat ?: 'certification')), true, '') ?></select><?= field_error('category') ?></div>
                <div class="form-group full"><label for="title">Title</label><input type="text" id="title" name="title" required maxlength="200" placeholder="e.g. Annual Report 2025-26" value="<?= e(old('title', $d['title'] ?? '')) ?>"><?= field_error('title') ?></div>
                <div class="form-group full"><label for="description">Short description (optional)</label><textarea id="description" name="description" rows="3" maxlength="500"><?= e(old('description', $d['description'] ?? '')) ?></textarea><?= field_error('description') ?></div>
                <div class="form-group full"><label for="file">PDF file (max <?= (int) MAX_UPLOAD_MB ?> MB)</label>
                    <?php if (!empty($d['file_path'])): ?><p class="small mb-0">Current file: <a href="<?= e(url($d['file_path'])) ?>" target="_blank" rel="noopener"><?= e(basename($d['file_path'])) ?></a></p>
                        <label class="checkbox"><input type="checkbox" name="remove_file" value="1"><span>Remove the current PDF (use the external link instead)</span></label><?php endif; ?>
                    <input id="file" type="file" name="file" accept=".pdf,application/pdf"><?= field_error('file') ?><span class="help">PDF only. Uploading a new file replaces the current one.</span></div>
                <div class="form-group full"><label for="external_url">Or external link (https)</label><input id="external_url" type="url" name="external_url" maxlength="255" placeholder="https://..." value="<?= e(old('external_url', $d['external_url'] ?? '')) ?>"><?= field_error('external_url') ?><span class="help">For example, a document hosted on a government portal. Used when no PDF is uploaded.</span></div>
                <div class="form-group"><label for="document_date">Document date</label><input id="document_date" type="date" name="document_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('document_date', $d['document_date'] ?? '')) ?>"><?= field_error('document_date') ?><span class="help">Issue date; documents are grouped by its year.</span></div>
                <div class="form-group"><label for="sort_order">Order</label><input id="sort_order" type="number" name="sort_order" value="<?= e(old('sort_order', (string) ($d['sort_order'] ?? 0))) ?>"><?= field_error('sort_order') ?></div>
                <div class="form-group full"><label for="campaign_id">Campaign (utilisation reports only)</label>
                    <select id="campaign_id" name="campaign_id"><?= options_html(array_column($campaigns, 'title', 'id'), (string) old('campaign_id', (string) ($d['campaign_id'] ?? '')), true, 'Not linked to a campaign') ?></select><?= field_error('campaign_id') ?></div>
                <div class="form-group"><label for="status">Status</label><select id="status" name="status"><?= options_html(['published' => 'Published', 'hidden' => 'Hidden'], (string) old('status', $d['status'] ?? 'published'), true, '') ?></select></div>
                <div class="form-actions full" style="grid-column:1/-1"><button class="btn btn-primary" type="submit"><?= icon('upload') ?>Save document</button></div>
            </form>
    </section>
    <?php endif; ?>
</div>
<?php
admin_page_end();
