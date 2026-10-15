<?php
/**
 * SEO audit: page titles & descriptions, duplicate titles, images without descriptions, headings,
 * hidden (noindex) pages, menu links to missing or unpublished pages, campaigns/articles without descriptions,
 * plus setup steps for Google Search Console and Google Analytics 4.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('pages.manage');

$suffix = setting('seo_title_suffix');
$pages = db_rows("SELECT id, slug, title, meta_title, meta_description, status, noindex, template FROM pages WHERE status = 'published' ORDER BY sort_order, title");
$indexable = array_filter($pages, function ($p) { return !(int) $p['noindex']; });
$edit = function (array $p, string $tab = 'settings'): string {
    return admin_url('page-edit.php?id=' . (int) $p['id'] . '&tab=' . $tab);
};

// Titles & descriptions
$missingDesc = $longDesc = $shortDesc = $longTitle = [];
$titles = [];
foreach ($indexable as $p) {
    $t = trim((string) ($p['meta_title'] ?: $p['title']));
    $d = trim((string) $p['meta_description']);
    if ($d === '') {
        $missingDesc[] = $p;
    } elseif (mb_strlen($d) > 160) {
        $longDesc[] = $p + ['len' => mb_strlen($d)];
    } elseif (mb_strlen($d) < 70) {
        $shortDesc[] = $p + ['len' => mb_strlen($d)];
    }
    if (mb_strlen($t) > 60) {
        $longTitle[] = $p + ['len' => mb_strlen($t)];
    }
    $titles[mb_strtolower($t)][] = $p;
}
$dupTitles = array_filter($titles, function ($list) { return count($list) > 1; });

// Section content: images without description, <h1> inside sections
$imgNoAlt = $h1s = [];
foreach (db_rows("SELECT s.id, s.page_id, s.section_type, s.title, s.body, s.image, s.settings, p.title AS page_title, p.slug
                  FROM page_sections s JOIN pages p ON p.id = s.page_id WHERE s.status = 'visible' AND p.status = 'published' ORDER BY p.sort_order, s.sort_order") as $s) {
    $body = (string) $s['body'];
    $n = 0;
    if ($body !== '' && preg_match_all('/<img\b[^>]*>/i', $body, $m)) {
        foreach ($m[0] as $tag) {
            if (!preg_match('/\balt\s*=\s*("[^"]+"|\'[^\']+\')/i', $tag)) {
                $n++;
            }
        }
    }
    $set = json_field($s['settings']);
    if (!empty($s['image']) && in_array($s['section_type'], ['richtext', 'split', 'image'], true) && trim((string) ($set['image_alt'] ?? '')) === ''
        && !($s['section_type'] === 'image' && trim((string) $s['title']) !== '')) {
        $n++;
    }
    if ($n) {
        $imgNoAlt[] = $s + ['count' => $n];
    }
    if ($body !== '' && preg_match('/<h1\b/i', $body)) {
        $h1s[] = $s;
    }
}

// Hidden pages
$noindexPages = array_filter($pages, function ($p) { return (int) $p['noindex']; });

// Menus: links to unpublished or missing pages
$draftLinks = $brokenLinks = [];
foreach (db_rows("SELECT mi.id, mi.label, mi.url, mi.status, m.menu_key, m.name FROM menu_items mi JOIN menus m ON m.id = mi.menu_id ORDER BY m.id, mi.sort_order") as $mi) {
    $st = site_link_status($mi['url']);
    if ($st === 'draft') {
        $draftLinks[] = $mi;
    } elseif ($st === 'missing') {
        $brokenLinks[] = $mi;
    }
}

// Campaigns & articles without a description (only when those modules have content)
$campaignsNoDesc = $postsNoDesc = [];
try {
    $campaignsNoDesc = db_rows("SELECT id, title, slug FROM campaigns WHERE status IN ('active','completed') AND (meta_description IS NULL OR meta_description = '') ORDER BY title LIMIT 100");
} catch (Throwable $e) {
    $campaignsNoDesc = [];
}
try {
    $postsNoDesc = db_rows("SELECT id, title, slug FROM blog_posts WHERE status = 'published' AND (meta_description IS NULL OR meta_description = '') ORDER BY published_at DESC LIMIT 100");
} catch (Throwable $e) {
    $postsNoDesc = [];
}

$cards = [
    ['Missing descriptions', count($missingDesc), '#descriptions'],
    ['Descriptions too long', count($longDesc), '#descriptions'],
    ['Titles over 60 characters', count($longTitle), '#titles'],
    ['Duplicate titles', count($dupTitles), '#titles'],
    ['Images without description', count($imgNoAlt), '#images'],
    ['Menu link problems', count($draftLinks) + count($brokenLinks), '#menus'],
];

$list = function (array $rows, callable $line): string {
    if (!$rows) {
        return '<p class="site-ok-line">' . icon('check-circle') . 'Nothing to fix.</p>';
    }
    $html = '<ul class="site-audit-list">';
    foreach ($rows as $r) {
        $html .= '<li>' . $line($r) . '</li>';
    }
    return $html . '</ul>';
};
$fix = function (string $href, string $label = 'Fix'): string {
    return '<a class="btn btn-ghost btn-sm" href="' . e($href) . '">' . icon('edit') . e($label) . '</a>';
};

admin_page_start('SEO Audit');
?>
<div class="site-admin">
<?php if (setting('seo_noindex_site') === '1'): ?>
    <div class="alert alert-error"><?= icon('alert-triangle') ?><div><strong>The whole website is hidden from search engines.</strong> Turn this off in <a href="<?= e(admin_url('settings.php?tab=seo')) ?>">Settings &gt; SEO &amp; analytics</a> when the site is ready.</div></div>
<?php endif; ?>

<div class="site-audit-cards">
    <?php foreach ($cards as [$label, $count, $anchor]): ?>
        <a class="site-audit-card <?= $count ? 'warn' : 'ok' ?>" href="<?= e($anchor) ?>"><strong><?= (int) $count ?></strong><small><?= e($label) ?></small></a>
    <?php endforeach; ?>
</div>
<p class="help">Checked <?= count($indexable) ?> published pages that search engines may index (<?= count($pages) - count($indexable) ?> more are published but hidden with noindex).</p>

<section class="panel" id="descriptions">
    <div class="panel-head"><h2><?= icon('file-text') ?>Search descriptions</h2><span class="muted small">Aim for 120-160 characters</span></div>
    <h3 class="site-sub">Missing (the default site description is used)</h3>
    <?= $list($missingDesc, function ($p) use ($edit, $fix) { return '<span><strong>' . e($p['title']) . '</strong><small>/' . e($p['slug'] === 'home' ? '' : $p['slug'] . '/') . '</small></span>' . $fix($edit($p)); }) ?>
    <h3 class="site-sub">Too long (over 160 characters - Google cuts them off)</h3>
    <?= $list($longDesc, function ($p) use ($edit, $fix) { return '<span><strong>' . e($p['title']) . '</strong><small>' . (int) $p['len'] . ' characters</small></span>' . $fix($edit($p)); }) ?>
    <?php if ($shortDesc): ?>
        <h3 class="site-sub">Short (under 70 characters - could say more)</h3>
        <?= $list($shortDesc, function ($p) use ($edit, $fix) { return '<span><strong>' . e($p['title']) . '</strong><small>' . (int) $p['len'] . ' characters</small></span>' . $fix($edit($p), 'Improve'); }) ?>
    <?php endif; ?>
</section>

<section class="panel" id="titles">
    <div class="panel-head"><h2><?= icon('search') ?>Page titles</h2><span class="muted small">Search title (or page title), aim for 60 characters or less<?= $suffix !== '' ? '; "' . e(trim($suffix)) . '" is added when it fits' : '' ?></span></div>
    <h3 class="site-sub">Longer than 60 characters</h3>
    <?= $list($longTitle, function ($p) use ($edit, $fix) { return '<span><strong>' . e($p['meta_title'] ?: $p['title']) . '</strong><small>' . (int) $p['len'] . ' characters</small></span>' . $fix($edit($p)); }) ?>
    <h3 class="site-sub">Duplicate titles (each page should have its own)</h3>
    <?php if (!$dupTitles): ?><p class="site-ok-line"><?= icon('check-circle') ?>Every page has its own title.</p><?php else: ?>
        <ul class="site-audit-list"><?php foreach ($dupTitles as $group): ?>
            <li><span><strong><?= e($group[0]['meta_title'] ?: $group[0]['title']) ?></strong><small>Used by: <?php foreach ($group as $i => $p): ?><?= $i ? ', ' : '' ?><a href="<?= e($edit($p)) ?>">/<?= e($p['slug']) ?>/</a><?php endforeach; ?></small></span></li>
        <?php endforeach; ?></ul>
    <?php endif; ?>
</section>

<section class="panel" id="images">
    <div class="panel-head"><h2><?= icon('image') ?>Content</h2></div>
    <h3 class="site-sub">Images without a description (alt text)</h3>
    <?= $list($imgNoAlt, function ($s) use ($fix) {
        return '<span><strong>' . e($s['page_title']) . '</strong><small>Section "' . e($s['title'] ?: humanize($s['section_type'])) . '" · ' . (int) $s['count'] . ' image(s)</small></span>'
            . $fix(admin_url('page-edit.php?id=' . (int) $s['page_id'] . '&tab=sections&open=' . (int) $s['id'] . '#section-' . (int) $s['id']));
    }) ?>
    <h3 class="site-sub">Main headings (&lt;h1&gt;) inside sections</h3>
    <p class="help mt-0">Each page has exactly one main heading - the page title in the banner. Use H2/H3 inside sections.</p>
    <?= $list($h1s, function ($s) use ($fix) {
        return '<span><strong>' . e($s['page_title']) . '</strong><small>Section "' . e($s['title'] ?: humanize($s['section_type'])) . '"</small></span>'
            . $fix(admin_url('page-edit.php?id=' . (int) $s['page_id'] . '&tab=sections&open=' . (int) $s['id'] . '#section-' . (int) $s['id']));
    }) ?>
    <h3 class="site-sub">Published pages hidden from search engines (noindex)</h3>
    <p class="help mt-0">Normal for login, account, receipt and thank-you pages. Check that no important page is in this list.</p>
    <?= $list(array_values($noindexPages), function ($p) use ($edit, $fix) { return '<span><strong>' . e($p['title']) . '</strong><small>/' . e($p['slug']) . '/</small></span>' . $fix($edit($p), 'Review'); }) ?>
</section>

<section class="panel" id="menus">
    <div class="panel-head"><h2><?= icon('list') ?>Menu links</h2></div>
    <h3 class="site-sub">Links to pages that do not exist</h3>
    <?= $list($brokenLinks, function ($mi) use ($fix) {
        return '<span><strong>' . e($mi['label']) . '</strong><small>' . e($mi['name']) . ' · ' . e($mi['url']) . ($mi['status'] === 'hidden' ? ' · hidden link' : '') . '</small></span>'
            . $fix(admin_url('menus.php?menu=' . urlencode($mi['menu_key']) . '&edit=' . (int) $mi['id'] . '#item-form'));
    }) ?>
    <h3 class="site-sub">Links to pages that are not published</h3>
    <?= $list($draftLinks, function ($mi) use ($fix) {
        return '<span><strong>' . e($mi['label']) . '</strong><small>' . e($mi['name']) . ' · ' . e($mi['url']) . '</small></span>'
            . $fix(admin_url('menus.php?menu=' . urlencode($mi['menu_key']) . '&edit=' . (int) $mi['id'] . '#item-form'));
    }) ?>
</section>

<?php if ($campaignsNoDesc || $postsNoDesc): ?>
<section class="panel" id="other">
    <div class="panel-head"><h2><?= icon('heart') ?>Campaigns &amp; articles without a search description</h2></div>
    <?php if ($campaignsNoDesc): ?><h3 class="site-sub">Campaigns</h3>
        <?= $list($campaignsNoDesc, function ($c) use ($fix) { return '<span><strong>' . e($c['title']) . '</strong><small>/campaign/' . e($c['slug']) . '/</small></span>' . $fix(admin_url('campaign-edit.php?id=' . (int) $c['id'])); }) ?>
    <?php endif; ?>
    <?php if ($postsNoDesc): ?><h3 class="site-sub">Articles</h3>
        <?= $list($postsNoDesc, function ($b) use ($fix) { return '<span><strong>' . e($b['title']) . '</strong><small>/blog/' . e($b['slug']) . '/</small></span>' . $fix(admin_url('blog-edit.php?id=' . (int) $b['id'])); }) ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<div class="admin-grid-2">
    <section class="panel">
        <div class="panel-head"><h2><?= icon('globe') ?>Google Search Console</h2><span class="badge <?= setting('google_site_verification') ? 'badge-green' : 'badge-gray' ?>"><?= setting('google_site_verification') ? 'Verification code set' : 'Not verified yet' ?></span></div>
        <ol class="site-steps">
            <li>Open <a href="https://search.google.com/search-console" target="_blank" rel="noopener">Google Search Console</a> and add a "URL prefix" property for <code><?= e(abs_url('')) ?></code>.</li>
            <li>Choose the <strong>HTML tag</strong> method, copy the code and paste it in <a href="<?= e(admin_url('settings.php?tab=seo')) ?>">Settings &gt; SEO &amp; analytics &gt; Google Search Console verification code</a>. Save, then click "Verify" in Google.</li>
            <li>In Search Console open <strong>Sitemaps</strong> and submit <code><?= e(abs_url('sitemap.xml')) ?></code>.</li>
            <li>Check "Pages" in Search Console every few weeks for errors, and fix missing pages in <a href="<?= e(admin_url('redirects.php?tab=404')) ?>">Redirects &amp; 404s</a>.</li>
        </ol>
        <p class="mt-2 mb-0"><a class="btn btn-ghost btn-sm" href="<?= e(url('sitemap.xml')) ?>" target="_blank" rel="noopener"><?= icon('external') ?>sitemap.xml</a>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('robots.txt')) ?>" target="_blank" rel="noopener"><?= icon('external') ?>robots.txt</a>
            <a class="btn btn-ghost btn-sm" href="<?= e(page_url('sitemap')) ?>" target="_blank" rel="noopener"><?= icon('external') ?>HTML sitemap</a></p>
    </section>
    <section class="panel">
        <div class="panel-head"><h2><?= icon('bar-chart') ?>Google Analytics 4 (optional)</h2><span class="badge <?= setting('ga_measurement_id') ? 'badge-green' : 'badge-gray' ?>"><?= setting('ga_measurement_id') ? e(setting('ga_measurement_id')) : 'Not set' ?></span></div>
        <ol class="site-steps">
            <li>The website already counts visits privately without cookies (Admin &gt; Website Analytics). Google Analytics is only needed for deeper reports.</li>
            <li>In <a href="https://analytics.google.com" target="_blank" rel="noopener">Google Analytics</a> create a GA4 property and a "Web" data stream for <code><?= e(abs_url('')) ?></code>.</li>
            <li>Copy the <strong>Measurement ID</strong> (G-XXXXXXXXXX) into <a href="<?= e(admin_url('settings.php?tab=seo')) ?>">Settings &gt; SEO &amp; analytics</a>.</li>
            <li>Visitors then see a consent banner; Google Analytics loads only for visitors who click "Allow analytics". Mention Google Analytics in the privacy policy.</li>
        </ol>
    </section>
</div>
</div>
<?php
admin_page_end();
