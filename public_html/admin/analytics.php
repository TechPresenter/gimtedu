<?php
/**
 * Website analytics from the first-party, privacy-friendly `page_views` table:
 * views, unique visitors (one per visitor per day), top pages, referrers, UTM campaigns, devices, logged-in share.
 * No IP addresses are stored; staff, bots and Do-Not-Track visitors are not recorded.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('analytics.view');

$range = admin_date_range('30d');
[$from, $to] = insights_bounds($range);
$base = "FROM page_views WHERE device <> 'bot' AND created_at BETWEEN ? AND ?";
$params = [$from, $to];
$uniq = 'COUNT(DISTINCT visitor_hash, DATE(created_at))';
$mode = insights_group($range);

// ---------------------------------------------------------------------
// CSV exports
// ---------------------------------------------------------------------
$export = (string) ($_GET['export'] ?? '');
if ($export !== '') {
    require_permission('exports.run');
    $suffix = $range['from'] . '-to-' . $range['to'] . '.csv';
    admin_log('report_exported', 'Analytics (' . $export . ') ' . $range['from'] . ' to ' . $range['to']);
    if ($export === 'daily') {
        send_csv('analytics-daily-' . $suffix, ['Date', 'Page views', 'Unique visitors', 'Logged-in views'],
            db_rows("SELECT DATE(created_at) d, COUNT(*) views, COUNT(DISTINCT visitor_hash) visitors, SUM(is_logged_in) logged $base GROUP BY d ORDER BY d", $params));
    } elseif ($export === 'pages') {
        send_csv('analytics-pages-' . $suffix, ['Path', 'Page title', 'Page views', 'Unique visitors'],
            db_rows("SELECT pv.path, MAX(p.title) title, COUNT(*) views, COUNT(DISTINCT pv.visitor_hash, DATE(pv.created_at)) visitors
                     FROM page_views pv LEFT JOIN pages p ON p.id = pv.page_id WHERE pv.device <> 'bot' AND pv.created_at BETWEEN ? AND ?
                     GROUP BY pv.path ORDER BY views DESC", $params));
    } elseif ($export === 'sources') {
        send_csv('analytics-sources-' . $suffix, ['Source', 'Medium', 'Campaign', 'Page views', 'Unique visitors'],
            db_rows('SELECT ' . insights_source_sql() . ' src, ' . insights_medium_sql() . " med, COALESCE(utm_campaign, '') camp, COUNT(*) views, $uniq visitors
                     $base GROUP BY src, med, camp ORDER BY views DESC", $params));
    }
    admin_redirect('analytics.php');
}

// ---------------------------------------------------------------------
// Figures
// ---------------------------------------------------------------------
$totals = db_row("SELECT COUNT(*) views, $uniq visitors, COALESCE(SUM(is_logged_in), 0) logged $base", $params);
$days = (int) round((strtotime($range['to']) - strtotime($range['from'])) / 86400) + 1;
$prevFrom = date('Y-m-d', strtotime($range['from'] . ' -' . $days . ' days'));
$prevTo = date('Y-m-d', strtotime($range['from'] . ' -1 day'));
$prev = $range['key'] === 'all' ? null : db_row("SELECT COUNT(*) views, $uniq visitors FROM page_views WHERE device <> 'bot' AND created_at BETWEEN ? AND ?", [$prevFrom . ' 00:00:00', $prevTo . ' 23:59:59']);
$trend = function (int $now, ?int $before): string {
    if ($before === null || $before === 0) {
        return '';
    }
    $pct = ($now - $before) / $before * 100;
    return '<span class="kpi-trend ' . ($pct >= 0 ? 'up' : 'down') . '">' . ($pct >= 0 ? '▲ ' : '▼ ') . number_format(abs($pct), 0) . '% vs previous period</span>';
};
$views = (int) $totals['views'];
$visitors = (int) $totals['visitors'];

$series = db_rows('SELECT ' . insights_group_sql('created_at', $mode) . " k, COUNT(*) v, $uniq u $base GROUP BY k", $params);
$viewsChart = insights_series(array_column($series, 'v', 'k'), $range, $mode);
$visitorsChart = insights_series(array_column($series, 'u', 'k'), $range, $mode);

$topPages = db_rows("SELECT pv.path, MAX(p.title) title, COUNT(*) views, COUNT(DISTINCT pv.visitor_hash, DATE(pv.created_at)) visitors
                     FROM page_views pv LEFT JOIN pages p ON p.id = pv.page_id
                     WHERE pv.device <> 'bot' AND pv.created_at BETWEEN ? AND ? GROUP BY pv.path ORDER BY views DESC LIMIT 15", $params);
$referrers = db_rows("SELECT COALESCE(NULLIF(referrer_host, ''), '') host, COUNT(*) views, $uniq visitors $base GROUP BY host ORDER BY views DESC LIMIT 12", $params);
$utm = [];
foreach (['utm_source' => 'Sources', 'utm_medium' => 'Mediums', 'utm_campaign' => 'Campaigns'] as $col => $label) {
    $utm[$label] = db_rows("SELECT $col AS name, COUNT(*) views, $uniq visitors $base AND $col IS NOT NULL AND $col <> '' GROUP BY $col ORDER BY views DESC LIMIT 10", $params);
}
$devices = db_rows("SELECT device, COUNT(*) views, $uniq visitors $base GROUP BY device ORDER BY views DESC", $params);

$exportLink = function (string $type) use ($range): string {
    return '?' . http_build_query(['range' => $range['key'], 'from' => $range['key'] === 'custom' ? $range['from'] : null, 'to' => $range['key'] === 'custom' ? $range['to'] : null, 'export' => $type]);
};

admin_page_start('Website Analytics');
?>
<div class="dash-welcome">
    <div><h2>Website analytics</h2><p class="muted small mb-0"><?= e($range['label']) ?> · <?= e(format_date($range['from'])) ?> – <?= e(format_date($range['to'])) ?></p></div>
    <?= admin_range_picker($range) ?>
</div>

<div class="stat-cards insight-kpis">
    <div class="stat-card"><span class="icon-circle"><?= icon('eye') ?></span><div><strong><?= number_format($views) ?></strong><small>Page views</small><?= $trend($views, $prev ? (int) $prev['views'] : null) ?></div></div>
    <div class="stat-card"><span class="icon-circle blue"><?= icon('users') ?></span><div><strong><?= number_format($visitors) ?></strong><small>Unique visitors <span class="help-tip" title="One visitor per device per day (salted daily hash), summed over the period.">?</span></small><?= $trend($visitors, $prev ? (int) $prev['visitors'] : null) ?></div></div>
    <div class="stat-card"><span class="icon-circle gold"><?= icon('layers') ?></span><div><strong><?= $visitors ? number_format($views / $visitors, 1) : '–' ?></strong><small>Views per visitor</small></div></div>
    <div class="stat-card"><span class="icon-circle"><?= icon('lock') ?></span><div><strong><?= insights_pct((float) $totals['logged'], (float) $views) ?></strong><small>Views by signed-in members</small></div></div>
</div>

<?php if (!$views): ?>
    <div class="alert alert-info mb-3"><?= icon('info') ?><div>No page views were recorded in this period. Views are recorded for public pages only – not for staff, search-engine bots or visitors whose browser sends “Do Not Track”.<?= setting('analytics_enabled', '1') !== '1' ? ' <strong>Website statistics are currently switched off in Settings.</strong>' : '' ?></div></div>
<?php endif; ?>

<div class="admin-grid-2">
    <section class="panel">
        <div class="panel-head"><h2>Page views</h2><span class="muted small"><?= $mode === 'day' ? 'Per day' : 'Per month' ?></span></div>
        <?= insights_chart($viewsChart, false, 'Page views per ' . $mode) ?>
    </section>
    <section class="panel">
        <div class="panel-head"><h2>Unique visitors</h2><span class="muted small"><?= $mode === 'day' ? 'Per day' : 'Per month (daily visitors summed)' ?></span></div>
        <?= insights_chart($visitorsChart, false, 'Unique visitors per ' . $mode) ?>
    </section>
</div>

<section class="panel mt-3">
    <div class="panel-head"><h2>Top pages</h2><?php if (admin_can('exports.run')): ?><a class="btn btn-ghost btn-sm" href="<?= e($exportLink('pages')) ?>"><?= icon('download') ?>CSV</a><?php endif; ?></div>
    <?php if ($topPages): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Page</th><th class="text-right">Views</th><th class="text-right">Visitors</th><th class="hide-sm">Share of views</th></tr></thead>
            <tbody>
            <?php foreach ($topPages as $p): ?>
                <tr>
                    <td><strong><?= e($p['title'] ?: ($p['path'] === '/' ? 'Home' : humanize(basename($p['path'])))) ?></strong><br><a class="small" href="<?= e(url($p['path'] === '/' ? '/' : rtrim($p['path'], '/') . '/')) ?>" target="_blank" rel="noopener"><?= e($p['path']) ?></a></td>
                    <td class="text-right"><?= number_format((int) $p['views']) ?></td>
                    <td class="text-right"><?= number_format((int) $p['visitors']) ?></td>
                    <td class="hide-sm"><div class="mini-progress"><span style="width:<?= $views ? max(1, round($p['views'] / $views * 100)) : 0 ?>%"></span></div><small class="muted"><?= insights_pct((float) $p['views'], (float) $views) ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php else: ?>
        <p class="muted mb-0">No data for this period.</p>
    <?php endif; ?>
</section>

<div class="admin-grid-2 mt-3">
    <section class="panel">
        <div class="panel-head"><h2>Top referring websites</h2><?php if (admin_can('exports.run')): ?><a class="btn btn-ghost btn-sm" href="<?= e($exportLink('sources')) ?>"><?= icon('download') ?>CSV</a><?php endif; ?></div>
        <?php if ($referrers): ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Website</th><th class="text-right">Views</th><th class="text-right">Visitors</th></tr></thead>
                <tbody>
                <?php foreach ($referrers as $r): ?>
                    <tr><td><?= $r['host'] === '' ? '<span class="muted">Direct, bookmarks or apps</span>' : e($r['host']) ?></td><td class="text-right"><?= number_format((int) $r['views']) ?></td><td class="text-right"><?= number_format((int) $r['visitors']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?>
            <p class="muted mb-0">No data for this period.</p>
        <?php endif; ?>
    </section>
    <section class="panel">
        <div class="panel-head"><h2>Devices</h2></div>
        <?php if ($devices): ?>
            <ul class="bar-list">
                <?php foreach ($devices as $dv): ?>
                    <li><span class="bar-list-label"><?= icon($dv['device'] === 'mobile' ? 'smartphone' : ($dv['device'] === 'tablet' ? 'layers' : 'monitor')) ?><?= e(humanize($dv['device'])) ?></span>
                        <span class="bar-list-bar"><span style="width:<?= $views ? max(1, round($dv['views'] / $views * 100)) : 0 ?>%"></span></span>
                        <span class="bar-list-value"><?= insights_pct((float) $dv['views'], (float) $views, 0) ?> <small class="muted">(<?= number_format((int) $dv['visitors']) ?> visitors)</small></span></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted mb-0">No data for this period.</p>
        <?php endif; ?>
        <p class="muted small mt-2 mb-0">Signed-in members: <?= insights_pct((float) $totals['logged'], (float) $views) ?> of page views.</p>
    </section>
</div>

<section class="panel mt-3">
    <div class="panel-head"><h2>Campaign links (UTM tags)</h2><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('marketing.php')) ?>"><?= icon('megaphone') ?>Build a campaign link</a></div>
    <div class="utm-grid">
        <?php foreach ($utm as $label => $rows): ?>
            <div>
                <h3><?= e($label) ?></h3>
                <?php if ($rows): ?>
                    <table class="table table-compact"><tbody>
                        <?php foreach ($rows as $r): ?><tr><td><?= e($r['name']) ?></td><td class="text-right nowrap"><?= number_format((int) $r['views']) ?> <small class="muted">views</small></td></tr><?php endforeach; ?>
                    </tbody></table>
                <?php else: ?>
                    <p class="muted small">None in this period.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<div class="admin-grid-2 mt-3">
    <section class="panel">
        <div class="panel-head"><h2>Downloads</h2></div>
        <?php if (admin_can('exports.run')): ?>
            <div class="quick-actions">
                <a class="btn btn-ghost btn-sm" href="<?= e($exportLink('daily')) ?>"><?= icon('download') ?>Daily totals (CSV)</a>
                <a class="btn btn-ghost btn-sm" href="<?= e($exportLink('pages')) ?>"><?= icon('download') ?>Pages (CSV)</a>
                <a class="btn btn-ghost btn-sm" href="<?= e($exportLink('sources')) ?>"><?= icon('download') ?>Sources & campaigns (CSV)</a>
            </div>
        <?php else: ?>
            <p class="muted mb-0">Your role does not include CSV exports.</p>
        <?php endif; ?>
    </section>
    <section class="panel privacy-note">
        <div class="panel-head"><h2><?= icon('shield') ?> How these statistics protect privacy</h2></div>
        <ul class="check-list check-cols-1">
            <li><?= icon('check') ?><span>No IP addresses or cookies are stored – each visitor is counted with a salted hash that changes every day, so visits cannot be linked across days.</span></li>
            <li><?= icon('check') ?><span>Visitors whose browser sends “Do Not Track” or Global Privacy Control are not counted<?= setting('analytics_respect_dnt', '1') === '1' ? '' : ' <strong>(currently switched off in Settings)</strong>' ?>.</span></li>
            <li><?= icon('check') ?><span>Staff members and search-engine bots are excluded, so the figures reflect real visitors.</span></li>
            <li><?= icon('check') ?><span>Page views are deleted after <?= (int) retention_days()['analytics'] ?> days (Backup &amp; Data Retention).</span></li>
        </ul>
    </section>
</div>
<?php
admin_page_end();
