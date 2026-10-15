<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('content');

$menu = main_menu();
$rows = [];
foreach ($menu as $item) {
    $rows[] = ['label' => $item['label'], 'url' => $item['url'], 'parent' => ''];
    foreach ($item['children'] ?? [] as $child) {
        $rows[] = ['label' => $child['label'], 'url' => $child['url'], 'parent' => $item['label']];
    }
}
$topLabels = array_column($menu, 'label');
$parentOptions = function (string $selected) use ($topLabels): string {
    $html = '<option value="">— Top level —</option>';
    foreach ($topLabels as $l) {
        $html .= '<option value="' . e($l) . '"' . ($l === $selected ? ' selected' : '') . '>Under: ' . e($l) . '</option>';
    }
    return $html;
};
$adminTitle = 'Navigation Menu';
require __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
    <p class="muted mb-0">Each row is a link. Choose "Under: …" to place a link inside a drop-down. Links can be site pages (e.g. <code>about.php</code>, <code>blog.php?category=news</code>) or full https:// addresses.</p>
    <form method="post" action="actions/content-save.php" data-confirm="Reset the menu to the default navigation?">
        <?= csrf_field() ?><input type="hidden" name="type" value="menu"><input type="hidden" name="action" value="reset">
        <button class="btn btn-ghost btn-sm" type="submit"><?= icon('refresh') ?>Reset to default</button>
    </form>
</div>
<form method="post" action="actions/content-save.php">
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="menu">
    <div class="panel">
        <div id="menu-rows">
            <?php foreach ($rows as $r): ?>
                <div class="menu-row">
                    <div class="form-group"><label>Label</label><input type="text" name="label[]" maxlength="40" value="<?= e($r['label']) ?>"></div>
                    <div class="form-group"><label>Link</label><input type="text" name="url[]" maxlength="255" value="<?= e($r['url']) ?>"></div>
                    <div class="form-group"><label>Position</label><select name="parent[]"><?= $parentOptions($r['parent']) ?></select></div>
                    <div></div>
                    <button class="icon-btn danger" type="button" data-remove-row aria-label="Remove"><?= icon('trash') ?></button>
                </div>
            <?php endforeach; ?>
        </div>
        <template id="menu-row-template">
            <div class="menu-row">
                <div class="form-group"><label>Label</label><input type="text" name="label[]" maxlength="40"></div>
                <div class="form-group"><label>Link</label><input type="text" name="url[]" maxlength="255" placeholder="page.php"></div>
                <div class="form-group"><label>Position</label><select name="parent[]"><?= $parentOptions('') ?></select></div>
                <div></div>
                <button class="icon-btn danger" type="button" data-remove-row aria-label="Remove"><?= icon('trash') ?></button>
            </div>
        </template>
        <div class="form-actions">
            <button class="btn btn-ghost" type="button" data-add-row="menu-row-template" data-target="#menu-rows"><?= icon('plus') ?>Add link</button>
            <button class="btn btn-primary" type="submit">Save menu</button>
        </div>
        <p class="small muted">Tip: a new top-level item must be saved first before other links can be placed under it. The "Donate Now" button and login links are always shown.</p>
    </div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
