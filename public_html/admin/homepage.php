<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('content');

$slides = json_decode(setting('hero_slides', '[]'), true) ?: [];
while (count($slides) < 6) {
    $slides[] = ['eyebrow' => '', 'title' => '', 'text' => '', 'button_text' => '', 'button_link' => '', 'image' => ''];
}
$adminTitle = 'Homepage';
require __DIR__ . '/includes/header.php';
?>
<form method="post" action="actions/content-save.php" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="homepage">
    <div class="panel">
        <div class="panel-head"><h3>Hero slider</h3><span class="muted small">Up to 6 slides. Leave the title empty to skip a slide.</span></div>
        <?php foreach ($slides as $i => $s): ?>
            <div class="slide-box">
                <div class="panel-head"><strong>Slide <?= $i + 1 ?></strong><?php if ($s['title'] !== ''): ?><label class="checkbox"><input type="checkbox" name="slides[<?= $i ?>][remove]" value="1"> <span>Remove</span></label><?php endif; ?></div>
                <div class="form-grid">
                    <div class="form-group"><label>Small heading</label><input type="text" name="slides[<?= $i ?>][eyebrow]" maxlength="60" value="<?= e($s['eyebrow']) ?>" placeholder="e.g. Women Empowerment"></div>
                    <div class="form-group"><label>Title</label><input type="text" name="slides[<?= $i ?>][title]" maxlength="120" value="<?= e($s['title']) ?>"></div>
                    <div class="form-group full"><label>Text</label><input type="text" name="slides[<?= $i ?>][text]" maxlength="300" value="<?= e($s['text']) ?>"></div>
                    <div class="form-group"><label>Button text</label><input type="text" name="slides[<?= $i ?>][button_text]" maxlength="40" value="<?= e($s['button_text']) ?>"></div>
                    <div class="form-group"><label>Button link</label><input type="text" name="slides[<?= $i ?>][button_link]" maxlength="255" value="<?= e($s['button_link']) ?>" placeholder="donate.php"></div>
                    <div class="form-group full"><label>Image (PNG/JPG/WebP, transparent PNG works best)</label>
                        <div style="display:flex;gap:12px;align-items:center">
                            <?php if ($s['image']): ?><img src="<?= e(image_url($s['image'])) ?>" alt="" style="width:90px;height:68px;object-fit:contain;background:#178a48;border-radius:8px"><?php endif; ?>
                            <input type="file" name="slide_image[<?= $i ?>]" accept=".jpg,.jpeg,.png,.webp">
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="panel">
        <div class="panel-head"><h3>"Our Work" section</h3></div>
        <div class="form-group mb-2"><label for="home_work_title">Section title</label><input id="home_work_title" type="text" name="home_work_title" maxlength="80" value="<?= e(setting('home_work_title', 'Our Work')) ?>"></div>
        <div class="form-group mb-2"><label for="home_work_intro">Introduction</label><textarea id="home_work_intro" name="home_work_intro" maxlength="500" rows="3" style="min-height:80px"><?= e(setting('home_work_intro')) ?></textarea></div>
        <label class="checkbox"><input type="checkbox" name="show_impact_counters" value="1"<?= setting('show_impact_counters', '1') === '1' ? ' checked' : '' ?>> <span>Show live impact counters (calculated only from verified donations, approved volunteers and campaigns)</span></label>
    </div>
    <button class="btn btn-primary btn-lg" type="submit">Save homepage</button>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
