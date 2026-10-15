<?php
/** HTML sitemap: every published page and content item, grouped. */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
page_header($page);
$groups = [];
foreach (sitemap_entries() as $entry) {
    $groups[$entry['group']][] = $entry;
}
?>
<section class="section">
    <div class="container">
        <div class="sitemap-grid">
            <?php foreach ($groups as $group => $entries): ?>
                <div class="aside-card reveal">
                    <h2 class="h3"><?= e($group) ?> <small class="muted">(<?= count($entries) ?>)</small></h2>
                    <ul class="link-list">
                        <?php foreach ($entries as $entry): ?>
                            <li><a href="<?= e(base_path() . substr($entry['loc'], strlen(rtrim(BASE_URL, '/')))) ?>"><?= icon('chevron-right') ?><?= e($entry['title']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="muted small mt-3">XML sitemap for search engines: <a href="<?= e(url('/sitemap.xml')) ?>"><?= e(abs_url('sitemap.xml')) ?></a></p>
    </div>
</section>
<?php
render_sections($page);
page_footer();
