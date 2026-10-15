<?php
/**
 * Marketing: conversions (from track_conversion()) by type and by traffic source / medium / campaign,
 * conversion rate against visitors, landing pages that convert, newsletter growth and a campaign-link (UTM) builder.
 * Donation value counts verified donations only (donations.status = 'success'). The link builder stores nothing.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('analytics.view');

$range = admin_date_range('30d');
[$from, $to] = insights_bounds($range);
$params = [$from, $to];
$labels = insights_conversion_labels();
$src = insights_source_sql('me');
$med = insights_medium_sql('me');
$verifiedJoin = "LEFT JOIN donations d ON me.event_type = 'donation_success' AND d.id = me.reference_id AND d.status = 'success'";

$bySource = db_rows("SELECT $src src, $med med, COALESCE(NULLIF(me.utm_campaign, ''), '') camp, COUNT(*) conversions,
                            COUNT(d.id) donations, COALESCE(SUM(d.amount), 0) value
                     FROM marketing_events me $verifiedJoin
                     WHERE me.event_type <> 'donation_started' AND me.created_at BETWEEN ? AND ?
                     GROUP BY src, med, camp ORDER BY conversions DESC, value DESC LIMIT 30", $params);

if (isset($_GET['export'])) {
    require_permission('exports.run');
    admin_log('report_exported', 'Marketing conversions ' . $range['from'] . ' to ' . $range['to']);
    send_csv('conversions-by-source-' . $range['from'] . '-to-' . $range['to'] . '.csv', ['Source', 'Medium', 'Campaign', 'Conversions', 'Verified donations', 'Verified donation value (INR)'],
        array_map(function ($r) { return [$r['src'], $r['med'], $r['camp'], $r['conversions'], $r['donations'], $r['value']]; }, $bySource));
}

$byType = db_rows("SELECT me.event_type, COUNT(*) n, COUNT(d.id) verified, COALESCE(SUM(d.amount), 0) value
                   FROM marketing_events me $verifiedJoin WHERE me.created_at BETWEEN ? AND ? GROUP BY me.event_type ORDER BY n DESC", $params);
$typeCounts = array_column($byType, 'n', 'event_type');
$conversions = array_sum($typeCounts) - (int) ($typeCounts['donation_started'] ?? 0);
$started = (int) ($typeCounts['donation_started'] ?? 0);
$verifiedDonations = 0;
$verifiedValue = 0.0;
foreach ($byType as $t) {
    if ($t['event_type'] === 'donation_success') {
        $verifiedDonations = (int) $t['verified'];
        $verifiedValue = (float) $t['value'];
    }
}
$visitorsTotal = (int) db_value("SELECT COUNT(DISTINCT visitor_hash, DATE(created_at)) FROM page_views WHERE device <> 'bot' AND created_at BETWEEN ? AND ?", $params);

// Conversion rate by source (visitors from page_views, conversions from marketing_events)
$visitorsBySource = array_column(db_rows('SELECT ' . insights_source_sql() . " s, COUNT(DISTINCT visitor_hash, DATE(created_at)) v
                                          FROM page_views WHERE device <> 'bot' AND created_at BETWEEN ? AND ? GROUP BY s", $params), 'v', 's');
$convBySource = array_column(db_rows("SELECT $src s, COUNT(*) c FROM marketing_events me WHERE me.event_type <> 'donation_started' AND me.created_at BETWEEN ? AND ? GROUP BY s", $params), 'c', 's');
$rateRows = [];
foreach (array_unique(array_merge(array_keys($visitorsBySource), array_keys($convBySource))) as $s) {
    $rateRows[] = ['source' => (string) $s, 'visitors' => (int) ($visitorsBySource[$s] ?? 0), 'conversions' => (int) ($convBySource[$s] ?? 0)];
}
usort($rateRows, function ($a, $b) { return [$b['visitors'], $b['conversions']] <=> [$a['visitors'], $a['conversions']]; });
$rateRows = array_slice($rateRows, 0, 15);

$landing = db_rows("SELECT COALESCE(NULLIF(me.landing_path, ''), '') lp, COUNT(*) conversions, COUNT(d.id) donations, COALESCE(SUM(d.amount), 0) value
                    FROM marketing_events me $verifiedJoin WHERE me.event_type <> 'donation_started' AND me.created_at BETWEEN ? AND ?
                    GROUP BY lp ORDER BY conversions DESC LIMIT 10", $params);

// Newsletter growth: new sign-ups per month (at least the last 12 months)
$nlFrom = min($range['from'], date('Y-m-01', strtotime('-11 months')));
$nlRange = ['from' => $nlFrom, 'to' => $range['to']];
$nlPairs = array_column(db_rows("SELECT DATE_FORMAT(created_at, '%Y-%m') k, COUNT(*) v FROM newsletter_subscribers WHERE created_at BETWEEN ? AND ? GROUP BY k",
    [$nlFrom . ' 00:00:00', $to]), 'v', 'k');
$nlChart = insights_series($nlPairs, $nlRange, 'month');
$nlTotals = db_row("SELECT SUM(status = 'subscribed') subscribed, SUM(status = 'unsubscribed') unsubscribed, SUM(created_at BETWEEN ? AND ?) new_in_range FROM newsletter_subscribers", $params);

// ---------------------------------------------------------------------
// Campaign link builder (GET only, nothing stored)
// ---------------------------------------------------------------------
$utmClean = function ($v): string {
    $v = mb_strtolower(trim((string) $v));
    $v = (string) preg_replace('/\s+/', '-', $v);
    return mb_substr((string) preg_replace('/[^a-z0-9_\-.]/', '', $v), 0, 100);
};
$pages = db_rows("SELECT slug, title FROM pages WHERE status = 'published' AND noindex = 0 ORDER BY sort_order, title");
$b = ['page' => (string) ($_GET['b_page'] ?? ''), 'url' => trim((string) ($_GET['b_url'] ?? '')), 'source' => $utmClean($_GET['b_source'] ?? ''),
    'medium' => $utmClean($_GET['b_medium'] ?? ''), 'campaign' => $utmClean($_GET['b_campaign'] ?? ''), 'content' => $utmClean($_GET['b_content'] ?? '')];
$builtUrl = '';
$builderError = '';
if (isset($_GET['build'])) {
    $target = '';
    if ($b['url'] !== '') {
        $own = (string) parse_url(BASE_URL, PHP_URL_HOST);
        if (preg_match('#^/[A-Za-z0-9\-_/]*$#', $b['url'])) {
            $target = rtrim(BASE_URL, '/') . '/' . ltrim($b['url'], '/');
        } elseif (filter_var($b['url'], FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $b['url']) && strcasecmp((string) parse_url($b['url'], PHP_URL_HOST), $own) === 0) {
            $target = strtok($b['url'], '#');
        } else {
            $builderError = 'Enter an address on this website (for example /donate/ or ' . abs_url('donate/') . ').';
        }
    } elseif ($b['page'] !== '' && in_array($b['page'], array_column($pages, 'slug'), true)) {
        $target = abs_page_url($b['page']);
    } else {
        $builderError = 'Choose a page or enter an address on this website.';
    }
    if (!$builderError && ($b['source'] === '' || $b['medium'] === '' || $b['campaign'] === '')) {
        $builderError = 'Source, medium and campaign name are required.';
    }
    if (!$builderError) {
        $query = array_filter(['utm_source' => $b['source'], 'utm_medium' => $b['medium'], 'utm_campaign' => $b['campaign'], 'utm_content' => $b['content']]);
        $builtUrl = $target . (strpos($target, '?') === false ? '?' : '&') . http_build_query($query);
    }
}

admin_page_start('Marketing & Campaign Links');
?>
<div class="dash-welcome">
    <div><h2>Marketing &amp; conversions</h2><p class="muted small mb-0"><?= e($range['label']) ?> · first traffic source of each visit</p></div>
    <?= admin_range_picker($range) ?>
</div>

<div class="stat-cards insight-kpis">
    <div class="stat-card"><span class="icon-circle"><?= icon('target') ?></span><div><strong><?= number_format($conversions) ?></strong><small>Conversions</small><span class="kpi-sub">Applications, sign-ups, enquiries, donations…</span></div></div>
    <div class="stat-card"><span class="icon-circle gold"><?= icon('rupee') ?></span><div><strong><?= e(money($verifiedValue)) ?></strong><small>Verified donation value</small><span class="kpi-sub"><?= number_format($verifiedDonations) ?> verified donation<?= $verifiedDonations === 1 ? '' : 's' ?> · <?= number_format($started) ?> checkouts started</span></div></div>
    <div class="stat-card"><span class="icon-circle blue"><?= icon('users') ?></span><div><strong><?= number_format($visitorsTotal) ?></strong><small>Unique visitors</small></div></div>
    <div class="stat-card"><span class="icon-circle"><?= icon('trending-up') ?></span><div><strong><?= insights_pct((float) $conversions, (float) $visitorsTotal) ?></strong><small>Conversion rate</small><span class="kpi-sub">Conversions ÷ unique visitors</span></div></div>
</div>

<div class="admin-grid-2">
    <section class="panel">
        <div class="panel-head"><h2>Conversions by type</h2></div>
        <?php if ($byType): ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Type</th><th class="text-right">Count</th><th class="text-right">Verified value</th></tr></thead>
                <tbody>
                <?php foreach ($byType as $t): ?>
                    <tr>
                        <td><?= e($labels[$t['event_type']] ?? humanize($t['event_type'])) ?><?= $t['event_type'] === 'donation_started' ? ' <small class="muted">(not counted as a conversion)</small>' : '' ?></td>
                        <td class="text-right"><?= number_format((int) $t['n']) ?></td>
                        <td class="text-right nowrap"><?= $t['event_type'] === 'donation_success' ? e(money($t['value'])) : '<span class="muted">–</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if ($started): ?><p class="muted small mt-2 mb-0">Checkout completion: <?= insights_pct((float) ($typeCounts['donation_success'] ?? 0), (float) $started) ?> of started donation checkouts were confirmed.</p><?php endif; ?>
        <?php else: ?>
            <p class="muted mb-0">No conversions recorded in this period.</p>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Newsletter growth</h2><span class="muted small">New sign-ups per month</span></div>
        <?= insights_chart($nlChart, false, 'New newsletter subscribers per month') ?>
        <p class="muted small mt-2 mb-0"><?= number_format((int) $nlTotals['subscribed']) ?> subscribed · <?= number_format((int) $nlTotals['unsubscribed']) ?> unsubscribed · <?= number_format((int) $nlTotals['new_in_range']) ?> new in <?= e(mb_strtolower($range['label'])) ?></p>
    </section>
</div>

<section class="panel mt-3">
    <div class="panel-head"><h2>Conversions by source, medium &amp; campaign</h2><?php if (admin_can('exports.run') && $bySource): ?><a class="btn btn-ghost btn-sm" href="?<?= e(http_build_query(array_merge(array_intersect_key($_GET, ['range' => 1, 'from' => 1, 'to' => 1]), ['export' => 'sources']))) ?>"><?= icon('download') ?>CSV</a><?php endif; ?></div>
    <?php if ($bySource): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Source</th><th>Medium</th><th>Campaign</th><th class="text-right">Conversions</th><th class="text-right">Verified donations</th><th class="text-right">Value</th></tr></thead>
            <tbody>
            <?php foreach ($bySource as $r): ?>
                <tr><td><strong><?= e($r['src']) ?></strong></td><td><?= e($r['med']) ?></td><td><?= $r['camp'] !== '' ? e($r['camp']) : '<span class="muted">–</span>' ?></td>
                    <td class="text-right"><?= number_format((int) $r['conversions']) ?></td><td class="text-right"><?= number_format((int) $r['donations']) ?></td><td class="text-right nowrap"><?= e(money($r['value'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php else: ?>
        <p class="muted mb-0">No conversions recorded in this period. Share links built below to see which channels bring volunteers and donors.</p>
    <?php endif; ?>
</section>

<div class="admin-grid-2 mt-3">
    <section class="panel">
        <div class="panel-head"><h2>Conversion rate by source</h2></div>
        <?php if ($rateRows): ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Source</th><th class="text-right">Visitors</th><th class="text-right">Conversions</th><th class="text-right">Rate</th></tr></thead>
                <tbody>
                <?php foreach ($rateRows as $r): ?>
                    <tr><td><?= e($r['source']) ?></td><td class="text-right"><?= number_format($r['visitors']) ?></td><td class="text-right"><?= number_format($r['conversions']) ?></td>
                        <td class="text-right"><?= $r['visitors'] ? insights_pct((float) $r['conversions'], (float) $r['visitors']) : '<span class="muted">–</span>' ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?>
            <p class="muted mb-0">No visitors or conversions in this period.</p>
        <?php endif; ?>
    </section>
    <section class="panel">
        <div class="panel-head"><h2>Landing pages that convert</h2></div>
        <?php if ($landing): ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>First page of the visit</th><th class="text-right">Conversions</th><th class="text-right">Value</th></tr></thead>
                <tbody>
                <?php foreach ($landing as $r): ?>
                    <tr><td><?= $r['lp'] !== '' ? e($r['lp']) : '<span class="muted">Unknown</span>' ?></td><td class="text-right"><?= number_format((int) $r['conversions']) ?></td><td class="text-right nowrap"><?= e(money($r['value'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?>
            <p class="muted mb-0">No data for this period.</p>
        <?php endif; ?>
    </section>
</div>

<div class="admin-grid-main mt-3">
    <section class="panel" id="link-builder">
        <div class="panel-head"><h2>Campaign link builder</h2><span class="muted small">Nothing is saved</span></div>
        <p class="muted small">Add tracking tags to a link before sharing it on WhatsApp, social media, posters or emails. Visits and conversions from the link then appear in these reports under its source, medium and campaign.</p>
        <form method="get" action="<?= e(admin_url('marketing.php')) ?>#link-builder" class="form-grid utm-builder">
            <input type="hidden" name="build" value="1">
            <?php foreach (array_intersect_key($_GET, ['range' => 1, 'from' => 1, 'to' => 1]) as $k => $v): if (is_string($v)): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endif; endforeach; ?>
            <div class="form-group"><label for="b_page">Page</label>
                <select id="b_page" name="b_page"><option value="">Choose a page…</option>
                    <?php foreach ($pages as $p): ?><option value="<?= e($p['slug']) ?>"<?= $b['page'] === $p['slug'] ? ' selected' : '' ?>><?= e($p['title']) ?> (<?= e($p['slug'] === 'home' ? '/' : '/' . $p['slug'] . '/') ?>)</option><?php endforeach; ?>
                </select></div>
            <div class="form-group"><label for="b_url">…or an address on this website</label><input id="b_url" name="b_url" value="<?= e($b['url']) ?>" placeholder="/campaign/your-campaign/" maxlength="255"><span class="help">Overrides the page above.</span></div>
            <div class="form-group"><label for="b_source">Source *</label><input id="b_source" name="b_source" value="<?= e($b['source']) ?>" list="utm-sources" placeholder="whatsapp" maxlength="100"></div>
            <div class="form-group"><label for="b_medium">Medium *</label><input id="b_medium" name="b_medium" value="<?= e($b['medium']) ?>" list="utm-mediums" placeholder="social" maxlength="100"></div>
            <div class="form-group"><label for="b_campaign">Campaign name *</label><input id="b_campaign" name="b_campaign" value="<?= e($b['campaign']) ?>" placeholder="flood-relief-2026" maxlength="100"></div>
            <div class="form-group"><label for="b_content">Content (optional)</label><input id="b_content" name="b_content" value="<?= e($b['content']) ?>" placeholder="poster-a" maxlength="100"><span class="help">To tell apart two links in the same campaign.</span></div>
            <datalist id="utm-sources"><option value="whatsapp"><option value="facebook"><option value="instagram"><option value="linkedin"><option value="youtube"><option value="newsletter"><option value="poster"><option value="sms"></datalist>
            <datalist id="utm-mediums"><option value="social"><option value="email"><option value="print"><option value="messaging"><option value="referral"><option value="video"></datalist>
            <div class="form-actions full"><button class="btn btn-primary btn-sm" type="submit"><?= icon('link') ?>Create link</button></div>
        </form>
        <?php if ($builderError): ?>
            <div class="alert alert-error mt-2"><?= icon('alert-circle') ?><div><?= e($builderError) ?></div></div>
        <?php elseif ($builtUrl !== ''): ?>
            <div class="utm-result mt-2">
                <label for="utm-out">Your campaign link</label>
                <div class="utm-result-row"><input id="utm-out" type="text" readonly value="<?= e($builtUrl) ?>"><button type="button" class="btn btn-donate btn-sm" data-copy="<?= e($builtUrl) ?>"><?= icon('copy') ?>Copy</button></div>
                <p class="muted small mb-0">Test it once in a private browser window before sharing.</p>
            </div>
        <?php endif; ?>
    </section>
    <aside class="panel">
        <div class="panel-head"><h2>Tips for sharing</h2></div>
        <ul class="check-list check-cols-1 small">
            <li><?= icon('check') ?><span>Use lowercase names and keep them consistent (“whatsapp”, not “WhatsApp” one day and “wa” the next).</span></li>
            <li><?= icon('check') ?><span>Give each appeal its own campaign name, e.g. <code>education-support-2026</code>.</span></li>
            <li><?= icon('check') ?><span>Link previews (title, description and image) are created automatically from each page’s SEO settings and image.</span></li>
            <li><?= icon('check') ?><span>The share buttons on campaign, event and article pages already add <code>utm_medium=social</code> and <code>utm_campaign=share</code>.</span></li>
            <li><?= icon('check') ?><span>Donation value here counts verified payments only, so it always matches the Donations report.</span></li>
        </ul>
    </aside>
</div>
<?php
admin_page_end();
