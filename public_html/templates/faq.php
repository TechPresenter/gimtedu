<?php
/**
 * FAQ page: every published FAQ (Admin > FAQs) grouped by category, with a search box and topic filter
 * (assets/js/modules/info.js; without JavaScript all questions are simply listed). Adds FAQPage JSON-LD.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$faqs = faqs_list();
faq_add_to_schema($faqs);
$groups = [];
foreach ($faqs as $f) {
    $groups[$f['category']][] = $f;
}

info_require_script();
page_header($page);
render_sections($page, function () use ($groups, $faqs) {
    echo '<section class="section faq-page" id="questions"><div class="container narrow">';
    if (!$faqs) {
        echo empty_state('Answers to common questions will be published here soon. Meanwhile, please write to us.', 'Contact us', '/contact/');
        echo '</div></section>';
        return;
    }
    ?>
    <div class="faq-tools" data-faq-tools>
        <div class="faq-search">
            <label for="faq-search" class="sr-only">Search the questions</label>
            <?= icon('search') ?>
            <input id="faq-search" type="search" placeholder="Search questions, e.g. receipt, certificate, refund" autocomplete="off" data-faq-search>
        </div>
        <?php if (count($groups) > 1): ?>
            <div class="filter-bar faq-filter" role="group" aria-label="Filter questions by topic">
                <button type="button" class="filter-btn is-active" data-faq-filter="all" aria-pressed="true">All topics</button>
                <?php foreach ($groups as $cat => $rows): ?>
                    <button type="button" class="filter-btn" data-faq-filter="<?= e($cat) ?>" aria-pressed="false"><?= e(FAQ_CATEGORIES[$cat] ?? humanize($cat)) ?></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="faq-status muted small" data-faq-status aria-live="polite"><?= count($faqs) ?> questions</p>
    </div>
    <?php foreach ($groups as $cat => $rows): ?>
        <div class="faq-group" id="faq-<?= e($cat) ?>" data-faq-group="<?= e($cat) ?>">
            <h2 class="faq-group-title"><span class="icon-circle"><?= icon(['general' => 'info', 'donations' => 'heart', 'volunteering' => 'users', 'support' => 'life-buoy', 'accounts' => 'lock', 'events' => 'calendar'][$cat] ?? 'help-circle') ?></span><?= e(FAQ_CATEGORIES[$cat] ?? humanize($cat)) ?></h2>
            <div class="faq-list">
                <?php foreach ($rows as $f): ?>
                    <details class="faq-item" data-faq-item>
                        <summary><?= e($f['question']) ?><?= icon('chevron-down') ?></summary>
                        <div class="faq-answer"><?= e_nl($f['answer']) ?></div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="empty-state faq-none" data-faq-none hidden><?= icon('search') ?><p>No questions match your search. Try other words, or <a href="<?= e(page_url('contact')) ?>">ask us directly</a>.</p></div>
    <?php
    echo '</div></section>';
});
page_footer();
