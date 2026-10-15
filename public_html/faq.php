<?php
require_once __DIR__ . '/functions.php';

$faqs = db_rows("SELECT * FROM faqs WHERE status = 'published' ORDER BY sort_order ASC, id ASC");
$groups = [];
foreach ($faqs as $f) {
    $groups[$f['category']][] = $f;
}
$pageTitle = 'Frequently Asked Questions';
$pageDescription = 'Answers to common questions about donating, volunteering and getting support from Prerit Welfare Foundation.';
require __DIR__ . '/header.php';

page_banner('Frequently Asked Questions', 'Quick answers about donations, volunteering and support');
?>
<section class="section">
    <div class="container narrow">
        <?php if (!$faqs): ?>
            <?= empty_state('No questions have been published yet.', 'Contact us', 'contact.php') ?>
        <?php endif; ?>
        <?php foreach ($groups as $category => $items): ?>
            <h2 class="mt-3" style="font-size:1.25rem;color:var(--green-800)"><?= e(humanize($category)) ?></h2>
            <div class="faq-list mb-3">
                <?php foreach ($items as $f): ?>
                    <details class="faq-item">
                        <summary><?= e($f['question']) ?><?= icon('chevron-down') ?></summary>
                        <div class="faq-answer"><?= e_nl($f['answer']) ?></div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <div class="notice mt-4"><?= icon('help-circle') ?><div>Still have a question? <a href="<?= e(url('contact.php')) ?>">Contact us</a> and our team will get back to you.</div></div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
