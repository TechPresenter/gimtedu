<?php
/**
 * Welfare schemes directory.
 *   /schemes/          the page's sections; the "schemes_list" section (or the end of the page) shows the
 *                      searchable directory with category filter, pagination and an honest empty state.
 *   /schemes/<slug>/   scheme detail: authority, summary, eligibility, benefits, how to apply, documents,
 *                      official source link, "last checked" date, disclaimer and help CTA.
 * Only schemes with status "published" are public (staff with schemes.manage can preview drafts with ?preview=1).
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$param = route_param();
$org = ['@id' => abs_url('#organization')];

// ---------------------------------------------------------------------
// Directory
// ---------------------------------------------------------------------
if ($param === '') {
    $cats = scheme_categories();
    $cat = get_str('category');
    $q = trim(get_str('q'));
    $opts = [];
    if (isset($cats[$cat])) {
        $opts['meta_title'] = ($page['meta_title'] ?: $page['title']) . ': ' . $cats[$cat];
        $opts['canonical'] = abs_page_url($page['slug'], ['category' => $cat]);
    }
    if ($q !== '') {
        $opts['meta_title'] = ($opts['meta_title'] ?? ($page['meta_title'] ?: $page['title'])) . ': search results for “' . mb_substr($q, 0, 40) . '”';
    }
    if ($q !== '' || (int) ($_GET['page'] ?? 1) > 1 || ($cat !== '' && !isset($cats[$cat]))) {
        $opts['noindex'] = true;
    }
    $published = db_rows("SELECT slug, title FROM schemes WHERE status = 'published' ORDER BY sort_order, title LIMIT 50");
    if ($published) {
        $items = [];
        foreach ($published as $i => $r) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $r['title'], 'url' => abs_url('schemes/' . $r['slug'] . '/')];
        }
        $opts['schema'] = [['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => $page['title'], 'itemListElement' => $items]];
    }
    page_header($page, $opts);

    $hasList = false;
    foreach (page_sections((int) $page['id']) as $s) {
        if ($s['section_type'] === 'schemes_list') {
            $hasList = true;
        }
    }
    render_sections($page, $hasList ? null : function (): void {
        echo '<section class="section" id="find-a-scheme"><div class="container">'
            . ui_heading('Find a scheme', 'Directory', 'Every scheme listed here has been checked by our team against its official source.')
            . schemes_directory() . '</div></section>';
    });
    page_footer();
    return;
}

// ---------------------------------------------------------------------
// Scheme detail
// ---------------------------------------------------------------------
if (!preg_match('/^[a-z0-9][a-z0-9\-]*$/', $param)) {
    not_found();
}
$scheme = scheme_by_slug($param, isset($_GET['preview']));
if (!$scheme) {
    not_found();
}

$url = abs_url('schemes/' . $scheme['slug'] . '/');
$catLabel = scheme_category_label($scheme['category']);
$official = (string) ($scheme['official_url'] ?? '');
$officialOk = (bool) preg_match('#^https://[^\s<>"]+$#i', $official);
$checked = !empty($scheme['source_checked_on']) ? format_date($scheme['source_checked_on'], 'd F Y') : '';
$image = !empty($scheme['image']) && is_file(ROOT_PATH . '/' . $scheme['image']) && strpos($scheme['image'], '..') === false ? $scheme['image'] : '';

$about = ['@type' => 'GovernmentService', 'name' => $scheme['title'], 'description' => $scheme['summary'], 'category' => $catLabel];
if ($officialOk) {
    $about['url'] = $official;
}
if (!empty($scheme['authority'])) {
    $about['provider'] = ['@type' => 'GovernmentOrganization', 'name' => $scheme['authority']];
}
$webPage = [
    '@context' => 'https://schema.org', '@type' => 'WebPage', '@id' => $url . '#webpage', 'url' => $url,
    'name' => $scheme['title'], 'description' => excerpt($scheme['summary'], 300), 'inLanguage' => 'en-IN',
    'about' => $about, 'publisher' => $org,
    'dateModified' => date('c', strtotime($scheme['updated_at'] ?: $scheme['created_at'])),
];
if (!empty($scheme['source_checked_on'])) {
    $webPage['lastReviewed'] = $scheme['source_checked_on'];
}

page_header($page, [
    'title'            => $scheme['title'],
    'subtitle'         => (string) ($scheme['authority'] ?: excerpt($scheme['summary'], 140)),
    'eyebrow'          => $catLabel,
    'crumbs'           => [[$page['title'], page_url($page['slug'])]],
    'canonical'        => $url,
    'meta_title'       => $scheme['title'] . ': eligibility, benefits & how to apply',
    'meta_description' => excerpt($scheme['summary'], 160),
    'og_image'         => $image,
    'og_type'          => 'article',
    'image'            => $image ?: ($page['banner_image'] ?: 'assets/images/hero/help.svg'),
    'buttons'          => [],
    'noindex'          => $scheme['status'] !== 'published',
    'schema'           => [$webPage],
]);

$blocks = [
    'eligibility'  => ['Who can apply', 'users', scheme_text_html($scheme['eligibility'], true)],
    'benefits'     => ['Benefits', 'gift', scheme_text_html($scheme['benefits'])],
    'how-to-apply' => ['How to apply', 'edit', scheme_text_html($scheme['how_to_apply'])],
    'documents'    => ['Documents usually needed', 'file-text', scheme_text_html($scheme['documents_needed'], true)],
];
$blocks = array_filter($blocks, function ($b) { return $b[2] !== ''; });
$related = db_rows("SELECT * FROM schemes WHERE status = 'published' AND category = ? AND id <> ? ORDER BY sort_order, title LIMIT 3", [$scheme['category'], $scheme['id']]);
?>
<section class="section">
    <div class="container detail-layout scheme-detail">
        <article class="scheme-main">
            <?php if ($scheme['status'] !== 'published'): ?>
                <div class="alert alert-warning"><?= icon('eye') ?><div>Preview of a <strong><?= e($scheme['status']) ?></strong> scheme. Visitors cannot see it until it is published.</div></div>
            <?php endif; ?>
            <div class="scheme-intro reveal">
                <span class="scheme-cat"><?= icon(scheme_category_icon($scheme['category'])) ?><?= e($catLabel) ?></span>
                <p class="lead"><?= e_nl($scheme['summary']) ?></p>
                <?php if (!empty($scheme['authority'])): ?>
                    <p class="scheme-authority"><?= icon('shield') ?><span><strong>Run by:</strong> <?= e($scheme['authority']) ?></span></p>
                <?php endif; ?>
            </div>
            <?php if ($image): ?>
                <figure class="detail-hero-img"><img src="<?= e(image_url($image)) ?>" alt="<?= e($scheme['title']) ?>" loading="lazy"></figure>
            <?php endif; ?>
            <?php if (count($blocks) > 1): ?>
                <nav class="scheme-toc" aria-label="On this page">
                    <?php foreach ($blocks as $id => [$label]): ?><a href="#<?= e($id) ?>"><?= e($label) ?></a><?php endforeach; ?>
                </nav>
            <?php endif; ?>
            <?php foreach ($blocks as $id => [$label, $ic, $html]): ?>
                <section class="scheme-block reveal" id="<?= e($id) ?>">
                    <h2><span class="icon-circle"><?= icon($ic) ?></span><?= e($label) ?></h2>
                    <div class="prose"><?= $html ?></div>
                </section>
            <?php endforeach; ?>

            <div class="callout callout-gold scheme-disclaimer">
                <span class="callout-icon"><?= icon('alert-triangle') ?></span>
                <div>
                    <?php if ($checked !== ''): ?><strong>Information last checked against the official source on <?= e($checked) ?>.</strong><?php endif; ?>
                    <p>Scheme rules, benefits, amounts and deadlines are decided by the government and can change at any time. If anything on this page differs from the official source, the official source prevails – please confirm the details there before you apply. <?= e(site_name()) ?> is not a government body and does not approve applications. We never charge a fee for scheme information or help, and no one from the foundation will ask you for money to get a scheme sanctioned.</p>
                </div>
            </div>
            <?= share_buttons($url, $scheme['title']) ?>
        </article>

        <aside class="sticky-card scheme-aside" aria-label="Official source and help">
            <div class="aside-card scheme-source">
                <h3><?= icon('shield') ?>Official source</h3>
                <?php if (!empty($scheme['authority'])): ?><p><?= e($scheme['authority']) ?></p><?php endif; ?>
                <?php if ($checked !== ''): ?><p class="small muted"><?= icon('check-circle') ?>Last checked on <?= e($checked) ?></p><?php endif; ?>
                <?php if ($officialOk): ?>
                    <a class="btn btn-blue btn-block" href="<?= e($official) ?>" target="_blank" rel="noopener noreferrer"><?= icon('external') ?>Visit the official website<span class="sr-only"> (opens in a new tab)</span></a>
                    <p class="small muted mt-1 scheme-domain"><?= e((string) parse_url($official, PHP_URL_HOST)) ?></p>
                <?php endif; ?>
            </div>
            <div class="aside-card aside-cta">
                <?php if (!empty($scheme['help_available'])): ?>
                    <h3>Need help applying?</h3>
                    <p>Our volunteers can help you understand this scheme, check your documents and guide you through the application – free of charge.</p>
                    <a class="btn btn-primary btn-block" href="<?= e(page_url('request-help')) ?>"><?= icon('life-buoy') ?>Request help</a>
                <?php else: ?>
                    <h3>Need help applying?</h3>
                    <p>Please apply through the official source. If you have a question about this scheme, you can still write to us or request guidance.</p>
                    <a class="btn btn-primary btn-block" href="<?= e(page_url('request-help')) ?>"><?= icon('life-buoy') ?>Request help</a>
                <?php endif; ?>
                <a class="btn btn-outline btn-block" href="<?= e(page_url('contact')) ?>"><?= icon('mail') ?>Ask a question</a>
            </div>
            <div class="aside-card">
                <h3>More welfare schemes</h3>
                <ul class="link-list">
                    <li><a href="<?= e(page_url($page['slug'])) ?>"><?= icon('list') ?>All schemes</a></li>
                    <li><a href="<?= e(page_url($page['slug'], ['category' => $scheme['category']])) ?>"><?= icon(scheme_category_icon($scheme['category'])) ?><?= e($catLabel) ?> schemes</a></li>
                    <li><a href="<?= e(page_url('report-fraud')) ?>"><?= icon('alert-triangle') ?>Report misuse of our name</a></li>
                </ul>
            </div>
        </aside>
    </div>
</section>
<?php if ($related): ?>
<section class="section section-tint">
    <div class="container">
        <?= ui_heading('Related schemes', $catLabel) ?>
        <div class="scheme-grid"><?php foreach ($related as $i => $r) { echo scheme_card($r, $i); } ?></div>
    </div>
</section>
<?php endif; ?>
<?php
page_footer();
