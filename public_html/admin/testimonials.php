<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('content');

$editId = (int) ($_GET['edit'] ?? 0);
$showForm = $editId || ($_GET['action'] ?? '') === 'add';
$t = $editId ? db_row('SELECT * FROM testimonials WHERE id = ?', [$editId]) : null;
$rows = db_rows('SELECT * FROM testimonials ORDER BY sort_order, id DESC');
$adminTitle = 'Testimonials';
require __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
    <p class="muted mb-0">Publish only genuine testimonials, with the permission of the person quoted.</p>
    <a class="btn btn-primary btn-sm" href="?action=add"><?= icon('plus') ?>Add testimonial</a>
</div>
<div class="<?= $showForm ? 'admin-grid-main' : '' ?>">
    <div class="panel">
        <?php if ($rows): ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Name</th><th>Message</th><th>Order</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><strong><?= e($r['name']) ?></strong><br><small class="muted"><?= e($r['designation']) ?></small></td>
                        <td><?= e(excerpt($r['message'], 90)) ?></td>
                        <td><?= (int) $r['sort_order'] ?></td>
                        <td><?= status_badge($r['status']) ?></td>
                        <td class="actions-cell">
                            <a class="icon-btn" href="?edit=<?= (int) $r['id'] ?>" aria-label="Edit"><?= icon('edit') ?></a>
                            <form class="inline-form" method="post" action="actions/content-save.php" data-confirm="Delete this testimonial?">
                                <?= csrf_field() ?><input type="hidden" name="type" value="testimonial"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button class="icon-btn danger" type="submit" aria-label="Delete"><?= icon('trash') ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?>
            <?= empty_state('No testimonials yet.') ?>
        <?php endif; ?>
    </div>
    <?php if ($showForm): ?>
        <div class="panel">
            <div class="panel-head"><h3><?= $t ? 'Edit testimonial' : 'Add testimonial' ?></h3><a class="btn btn-ghost btn-sm" href="testimonials.php">Close</a></div>
            <form method="post" action="actions/content-save.php" enctype="multipart/form-data" data-validate>
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="testimonial"><input type="hidden" name="id" value="<?= (int) ($t['id'] ?? 0) ?>">
                <div class="form-group mb-2"><label for="name">Name<span class="req">*</span></label><input id="name" type="text" name="name" required maxlength="120" value="<?= e(old('name', $t['name'] ?? '')) ?>"><?= field_error('name') ?></div>
                <div class="form-group mb-2"><label for="designation">Designation / relation</label><input id="designation" type="text" name="designation" maxlength="150" placeholder="e.g. Volunteer, Donor, SHG member" value="<?= e(old('designation', $t['designation'] ?? '')) ?>"></div>
                <div class="form-group mb-2"><label for="message">Testimonial<span class="req">*</span></label><textarea id="message" name="message" required maxlength="1500"><?= e(old('message', $t['message'] ?? '')) ?></textarea><?= field_error('message') ?></div>
                <div class="form-grid" style="gap:10px">
                    <div class="form-group"><label for="sort_order">Order</label><input id="sort_order" type="number" name="sort_order" value="<?= e(old('sort_order', (string) ($t['sort_order'] ?? 0))) ?>"></div>
                    <div class="form-group"><label for="status">Status</label><select id="status" name="status"><?= options_html(['published' => 'Published', 'hidden' => 'Hidden'], (string) old('status', $t['status'] ?? 'published'), true, '') ?></select></div>
                </div>
                <div class="form-group mt-2"><label for="photo">Photo (optional)</label><input id="photo" type="file" name="photo" accept=".jpg,.jpeg,.png,.webp"><?= field_error('photo') ?></div>
                <button class="btn btn-primary mt-2" type="submit">Save</button>
            </form>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
