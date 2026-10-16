<?php
/** HTML sitemap: every published page and content item, grouped. */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
page_header($page);
$entries = sitemap_entries();
// SEO module: list only what the XML sitemaps list (no noindex or empty list pages, no older awareness-day editions,
// no page whose canonical is another address). Without it, or when the XML sitemaps are empty, everything is listed.
if (function_exists('seo_sitemap_sets') && function_exists('seo_sitemap_normal_url')) {
    $listed = [];
    foreach (seo_sitemap_sets() as $set => $items) {
        if ($set === 'images') {
            continue;
        }
        foreach ($items as $item) {
            $listed[seo_sitemap_normal_url((string) $item['loc'])] = true;
        }
    }
    if ($listed) {
        $entries = array_filter($entries, function (array $entry) use ($listed): bool {
            return isset($listed[seo_sitemap_normal_url((string) $entry['loc'])]);
        });
    }
}
$groups = [];
foreach ($entries as $entry) {
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
