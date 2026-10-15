<?php
/** FAQs: create, edit, reorder, publish/hide and delete questions (used by /faq/, FAQ sections and the help assistant). */
require_once __DIR__ . '/includes/auth.php';
require_permission('faqs.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_only('faqs.php');
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'save') {
        [$data, $errors] = validate($_POST, [
            'question' => 'required|min:5|max:300',
            'answer' => 'required|text|min:5|max:5000',
            'category' => 'required|in:' . implode(',', array_keys(FAQ_CATEGORIES)),
            'sort_order' => 'integer|min:-9999|max:9999',
            'status' => 'required|in:published,hidden',
        ]);
        if ($errors) {
            back_with_errors($errors, 'admin/faqs.php?' . ($id ? 'edit=' . $id : 'new=1'), $_POST);
        }
        $row = ['question' => $data['question'], 'answer' => $data['answer'], 'category' => $data['category'],
            'sort_order' => (int) ($data['sort_order'] ?: 0), 'status' => $data['status']];
        if ($id && db_value('SELECT id FROM faqs WHERE id = ?', [$id])) {
            db_update('faqs', $row, 'id = :id', ['id' => $id]);
            admin_log('faq_updated', 'FAQ #' . $id . ': ' . mb_substr($data['question'], 0, 120));
            flash('success', 'Question updated.');
        } else {
            $id = db_insert('faqs', $row);
            admin_log('faq_created', 'FAQ #' . $id . ': ' . mb_substr($data['question'], 0, 120));
            flash('success', 'Question added.');
        }
        admin_redirect('faqs.php?category=' . urlencode($data['category']) . '#faq-' . $id);
    }
    if ($action === 'toggle' && ($faq = db_row('SELECT id, status FROM faqs WHERE id = ?', [$id]))) {
        $new = $faq['status'] === 'published' ? 'hidden' : 'published';
        db_update('faqs', ['status' => $new], 'id = :id', ['id' => $id]);
        admin_log('faq_status', 'FAQ #' . $id . ' ' . $new);
        flash('success', $new === 'published' ? 'Question published.' : 'Question hidden.');
    }
    if ($action === 'delete' && db_value('SELECT id FROM faqs WHERE id = ?', [$id])) {
        db_query('DELETE FROM faqs WHERE id = ?', [$id]);
        admin_log('faq_deleted', 'FAQ #' . $id);
        flash('success', 'Question deleted.');
    }
    admin_redirect(admin_back('faqs.php'));
}

$edit = isset($_GET['edit']) ? db_row('SELECT * FROM faqs WHERE id = ?', [(int) $_GET['edit']]) : null;
$showForm = $edit || isset($_GET['new']);
$cat = (string) ($_GET['category'] ?? '');
$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$params = [];
$where = 'WHERE 1=1';
if (isset(FAQ_CATEGORIES[$cat])) {
    $where .= ' AND category = ?';
    $params[] = $cat;
}
if (in_array($status, ['published', 'hidden'], true)) {
    $where .= ' AND status = ?';
    $params[] = $status;
}
$where .= search_where(['question', 'answer'], $q, $params);
$rows = db_rows("SELECT * FROM faqs $where ORDER BY FIELD(category, " . implode(',', array_fill(0, count(FAQ_CATEGORIES), '?')) . "), sort_order, id", array_merge($params, array_keys(FAQ_CATEGORIES)));
$counts = [];
foreach (db_rows('SELECT category, COUNT(*) n, SUM(status = \'published\') p FROM faqs GROUP BY category') as $c) {
    $counts[$c['category']] = $c;
}

admin_page_start('FAQs', [['label' => 'Add question', 'url' => admin_url('faqs.php?new=1'), 'icon' => 'plus']]);
?>
<p class="muted">Published questions appear on the <a href="<?= e(page_url('faq')) ?>" target="_blank" rel="noopener">FAQ page</a>, in FAQ sections and in the help assistant. Write answers that are accurate for how the foundation actually works - avoid promising timelines you cannot guarantee.</p>

<nav class="admin-tabs" aria-label="Categories">
    <a href="<?= e(admin_url('faqs.php')) ?>" class="<?= $cat === '' ? 'active' : '' ?>">All (<?= array_sum(array_map(function ($c) { return (int) $c['n']; }, $counts)) ?>)</a>
    <?php foreach (FAQ_CATEGORIES as $k => $label): ?>
        <a href="<?= e(admin_url('faqs.php?category=' . $k)) ?>" class="<?= $cat === $k ? 'active' : '' ?>"><?= e($label) ?> (<?= (int) ($counts[$k]['n'] ?? 0) ?>)</a>
    <?php endforeach; ?>
</nav>

<div<?= $showForm ? ' class="admin-grid-main"' : '' ?>>
    <section class="panel">
        <?php admin_filter_bar(['published', 'hidden'], ['placeholder' => 'Search questions and answers', 'hidden' => $cat !== '' ? ['category' => $cat] : []]); ?>
        <?php if (!$rows): ?>
            <p class="muted">No questions found.</p>
        <?php else: ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Question</th><th>Category</th><th>Order</th><th>Status</th><th class="actions-cell">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr id="faq-<?= (int) $r['id'] ?>">
                        <td><strong><?= e($r['question']) ?></strong><br><small class="muted"><?= e(excerpt($r['answer'], 120)) ?></small></td>
                        <td><?= e(FAQ_CATEGORIES[$r['category']] ?? humanize($r['category'])) ?></td>
                        <td><?= (int) $r['sort_order'] ?></td>
                        <td><?= status_badge($r['status']) ?></td>
                        <td class="actions-cell">
                            <a class="icon-btn" href="<?= e(admin_url('faqs.php?edit=' . $r['id'] . ($cat !== '' ? '&category=' . $cat : ''))) ?>" title="Edit" aria-label="Edit question"><?= icon('edit') ?></a>
                            <form method="post" class="inline-form"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button class="icon-btn" type="submit" title="<?= $r['status'] === 'published' ? 'Hide' : 'Publish' ?>" aria-label="<?= $r['status'] === 'published' ? 'Hide' : 'Publish' ?> question"><?= icon($r['status'] === 'published' ? 'eye' : 'check') ?></button></form>
                            <form method="post" class="inline-form" data-confirm="Delete this question permanently?"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button class="icon-btn danger" type="submit" title="Delete" aria-label="Delete question"><?= icon('trash') ?></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <?php if ($showForm): $f = $edit ?: []; ?>
    <section class="panel" id="faq-form">
        <div class="panel-head"><h2><?= $edit ? 'Edit question' : 'Add a question' ?></h2><?php if ($edit): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('faqs.php')) ?>">Cancel</a><?php endif; ?></div>
            <form method="post" class="form-grid" data-validate>
                <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($f['id'] ?? 0) ?>">
                <div class="form-group full"><label for="question">Question</label>
                    <input type="text" id="question" name="question" required maxlength="300" value="<?= e(old('question', $f['question'] ?? '')) ?>"><?= field_error('question') ?></div>
                <div class="form-group full"><label for="answer">Answer</label>
                    <textarea id="answer" name="answer" required rows="8" maxlength="5000"><?= e(old('answer', $f['answer'] ?? '')) ?></textarea><?= field_error('answer') ?>
                    <span class="help">Plain text. Line breaks are kept. Mention page names (e.g. "the Application Status page") rather than pasting links.</span></div>
                <div class="form-group"><label for="category">Category</label>
                    <select id="category" name="category"><?= options_html(FAQ_CATEGORIES, (string) old('category', $f['category'] ?? ($cat ?: 'general')), true, '') ?></select><?= field_error('category') ?></div>
                <div class="form-group"><label for="sort_order">Order</label>
                    <input id="sort_order" name="sort_order" type="number" value="<?= e(old('sort_order', (string) ($f['sort_order'] ?? 0))) ?>"><?= field_error('sort_order') ?><span class="help">Lower numbers appear first.</span></div>
                <div class="form-group"><label for="status">Status</label>
                    <select id="status" name="status"><?= options_html(['published' => 'Published', 'hidden' => 'Hidden'], (string) old('status', $f['status'] ?? 'published'), true, '') ?></select></div>
                <div class="form-actions full" style="grid-column:1/-1"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save question</button></div>
            </form>
    </section>
    <?php endif; ?>
</div>
<?php
admin_page_end();
