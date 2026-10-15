<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('content');

$editId = (int) ($_GET['edit'] ?? 0);
$showForm = $editId || ($_GET['action'] ?? '') === 'add';
$f = $editId ? db_row('SELECT * FROM faqs WHERE id = ?', [$editId]) : null;
$rows = db_rows('SELECT * FROM faqs ORDER BY sort_order, id');
$adminTitle = 'FAQs';
require __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
    <p class="muted mb-0">Questions shown on the public FAQ page, grouped by category.</p>
    <a class="btn btn-primary btn-sm" href="?action=add"><?= icon('plus') ?>Add FAQ</a>
</div>
<div class="<?= $showForm ? 'admin-grid-main' : '' ?>">
    <div class="panel">
        <?php if ($rows): ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Question</th><th>Category</th><th>Order</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><strong><?= e($r['question']) ?></strong><br><small class="muted"><?= e(excerpt($r['answer'], 90)) ?></small></td>
                        <td><?= e(humanize($r['category'])) ?></td>
                        <td><?= (int) $r['sort_order'] ?></td>
                        <td><?= status_badge($r['status']) ?></td>
                        <td class="actions-cell">
                            <a class="icon-btn" href="?edit=<?= (int) $r['id'] ?>" aria-label="Edit"><?= icon('edit') ?></a>
                            <form class="inline-form" method="post" action="actions/content-save.php" data-confirm="Delete this FAQ?">
                                <?= csrf_field() ?><input type="hidden" name="type" value="faq"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button class="icon-btn danger" type="submit" aria-label="Delete"><?= icon('trash') ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?>
            <?= empty_state('No FAQs yet.') ?>
        <?php endif; ?>
    </div>
    <?php if ($showForm): ?>
        <div class="panel">
            <div class="panel-head"><h3><?= $f ? 'Edit FAQ' : 'Add FAQ' ?></h3><a class="btn btn-ghost btn-sm" href="faqs.php">Close</a></div>
            <form method="post" action="actions/content-save.php" data-validate>
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="faq"><input type="hidden" name="id" value="<?= (int) ($f['id'] ?? 0) ?>">
                <div class="form-group mb-2"><label for="question">Question<span class="req">*</span></label><input id="question" type="text" name="question" required maxlength="300" value="<?= e(old('question', $f['question'] ?? '')) ?>"><?= field_error('question') ?></div>
                <div class="form-group mb-2"><label for="answer">Answer<span class="req">*</span></label><textarea id="answer" name="answer" required maxlength="3000"><?= e(old('answer', $f['answer'] ?? '')) ?></textarea><?= field_error('answer') ?></div>
                <div class="form-grid form-grid-3" style="gap:10px">
                    <div class="form-group"><label for="category">Category</label><input id="category" type="text" name="category" required maxlength="60" value="<?= e(old('category', $f['category'] ?? 'general')) ?>" list="faq-cats"></div>
                    <div class="form-group"><label for="sort_order">Order</label><input id="sort_order" type="number" name="sort_order" value="<?= e(old('sort_order', (string) ($f['sort_order'] ?? 0))) ?>"></div>
                    <div class="form-group"><label for="status">Status</label><select id="status" name="status"><?= options_html(['published' => 'Published', 'hidden' => 'Hidden'], (string) old('status', $f['status'] ?? 'published'), true, '') ?></select></div>
                </div>
                <datalist id="faq-cats"><option value="general"><option value="donations"><option value="volunteering"><option value="support"></datalist>
                <button class="btn btn-primary mt-2" type="submit">Save</button>
            </form>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
