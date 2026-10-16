<?php
/**
 * Deep analytics & visitors (first-party, privacy-respecting): visits (sessions) with engaged time, approximate
 * location, devices, sources, page behaviour, events, funnels, real-time activity and individual visit journeys.
 * Data: analytics_sessions / analytics_events / page_views (includes/analytics.php). No IP addresses are stored.
 *
 * Tabs: overview, realtime (?format=json for the 15-second refresh), audience, acquisition, behaviour, funnels,
 * visitors (?visit=<code> for one journey), export (CSV), settings (POST).
 * Permissions: analytics.deep (page); exports.run (CSV); settings.manage (saving settings).
 * Basic page-view statistics stay in analytics.php, conversions by source in marketing.php, reports in reports.php.
 */
require_once dirname(__DIR__) . '/functions.php';
// real-time refreshes must not count as admin activity (the inactivity timeout keeps working while the tab is open)
$daLastActivity = $_SESSION['admin_last_activity'] ?? null;
require_once __DIR__ . '/includes/auth.php';
require_permission('analytics.deep');

// =====================================================================
// Helpers (prefixed da_)
// =====================================================================

/** A query-string value as text ('' for missing values and arrays such as ?device[]=x). */
function da_get(string $key): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) ? $v : '';
}

/** Visit segment from the filter bar: [sql (alias s), params, active labels]. */
function da_segment(): array
{
    $sql = '';
    $params = [];
    $active = [];
    $device = da_get('device');
    if (in_array($device, ['mobile', 'tablet', 'desktop'], true)) {
        $sql .= ' AND s.device = ?';
        $params[] = $device;
        $active[] = humanize($device);
    }
    $channel = da_get('channel');
    if (isset(ANALYTICS_CHANNELS[$channel])) {
        $sql .= ' AND s.channel = ?';
        $params[] = $channel;
        $active[] = ANALYTICS_CHANNELS[$channel];
    }
    $country = strtoupper(da_get('country'));
    if (preg_match('/^[A-Z]{2}$/', $country)) {
        $sql .= ' AND s.country_code = ?';
        $params[] = $country;
        $active[] = analytics_country_name($country);
    }
    $who = da_get('who');
    $whoSql = ['new' => ' AND s.visitor_id IS NOT NULL AND s.is_returning = 0', 'returning' => ' AND s.is_returning = 1',
        'member' => ' AND s.user_id IS NOT NULL', 'anonymous' => ' AND s.user_id IS NULL', 'converted' => " AND s.converted IS NOT NULL"];
    if (isset($whoSql[$who])) {
        $sql .= $whoSql[$who];
        $active[] = ['new' => 'New visitors (with consent)', 'returning' => 'Returning visitors', 'member' => 'Signed-in members', 'anonymous' => 'Not signed in', 'converted' => 'Converted visits'][$who];
    }
    return [$sql, $params, $active];
}

/** "▲ 12% vs previous period" (colour = good/bad, arrow = direction; never colour alone). */
function da_delta(float $now, ?float $before, bool $lowerIsBetter = false): string
{
    if ($before === null) {
        return '';
    }
    if ($before == 0.0) {
        return $now > 0 ? '<span class="kpi-trend da-flat">New in this period</span>' : '';
    }
    $pct = ($now - $before) / abs($before) * 100;
    if (abs($pct) < 0.5) {
        return '<span class="kpi-trend da-flat">● No change vs previous period</span>';
    }
    $good = $lowerIsBetter ? $pct < 0 : $pct > 0;
    return '<span class="kpi-trend ' . ($good ? 'up' : 'down') . '">' . ($pct > 0 ? '▲ ' : '▼ ') . number_format(abs($pct), 0) . '% vs previous period</span>';
}

/** Compact axis number: 950, 1.2K, 3.4M. */
function da_compact(float $v): string
{
    if ($v >= 1000000) {
        return rtrim(rtrim(number_format($v / 1000000, 1), '0'), '.') . 'M';
    }
    if ($v >= 1000) {
        return rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . 'K';
    }
    return (string) (int) round($v);
}

/** Round an axis maximum up to a clean number. */
function da_nice_max(float $max): float
{
    if ($max <= 4) {
        return 4;
    }
    $exp = pow(10, floor(log10($max)));
    foreach ([1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $m) {
        if ($m * $exp >= $max) {
            return $m * $exp;
        }
    }
    return 10 * $exp;
}

/**
 * Line chart (inline SVG + HTML labels so text stays readable at every width).
 * $series = [['name' => 'Sessions', 'class' => 'c1', 'values' => [...], 'dashed' => false, 'area' => true], ...]
 */
function da_trend_chart(array $labels, array $series, string $title): string
{
    $n = count($labels);
    if ($n === 0) {
        return '';
    }
    $max = 0.0;
    foreach ($series as $s) {
        $max = max($max, $s['values'] ? max(array_map('floatval', $s['values'])) : 0);
    }
    $top = da_nice_max($max);
    $W = 1000;
    $H = 200;
    $x = function (int $i) use ($n, $W): float {
        return $n === 1 ? $W / 2 : $i * $W / ($n - 1);
    };
    $y = function (float $v) use ($top, $H): float {
        return $H - ($v / $top * $H);
    };
    $svg = '<svg class="da-trend-svg" viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none" aria-hidden="true" focusable="false">';
    for ($k = 1; $k <= 4; $k++) {
        $gy = round($H - $k / 4 * $H, 2);
        $svg .= '<line class="da-grid" x1="0" x2="' . $W . '" y1="' . $gy . '" y2="' . $gy . '"/>';
    }
    $svg .= '<line class="da-axis" x1="0" x2="' . $W . '" y1="' . $H . '" y2="' . $H . '"/>';
    $dots = '';
    foreach ($series as $s) {
        $pts = [];
        foreach (array_values($s['values']) as $i => $v) {
            $pts[] = round($x($i), 2) . ',' . round($y((float) $v), 2);
        }
        if (!empty($s['area']) && $n > 1) {
            $svg .= '<polygon class="da-area ' . e($s['class']) . '" points="0,' . $H . ' ' . implode(' ', $pts) . ' ' . $W . ',' . $H . '"/>';
        }
        $svg .= '<polyline class="da-line ' . e($s['class']) . (!empty($s['dashed']) ? ' is-dashed' : '') . '" points="' . implode(' ', $pts) . '"/>';
        if (empty($s['dashed'])) {
            $last = array_values($s['values']);
            $lv = (float) end($last);
            $dots .= '<span class="da-dot ' . e($s['class']) . '" style="left:' . round($x($n - 1) / $W * 100, 3) . '%;top:' . round($y($lv) / $H * 100, 3) . '%"></span>';
        }
    }
    // hover targets: one column per point with a native tooltip listing every series
    $colW = $W / max(1, $n);
    foreach ($labels as $i => $label) {
        $parts = [];
        foreach ($series as $s) {
            $parts[] = $s['name'] . ': ' . number_format((float) (array_values($s['values'])[$i] ?? 0));
        }
        $cx = $x($i);
        $svg .= '<rect class="da-hit" x="' . round(max(0, $cx - $colW / 2), 2) . '" y="0" width="' . round($colW, 2) . '" height="' . $H . '"><title>' . e($label . ' - ' . implode(', ', $parts)) . '</title></rect>';
    }
    $svg .= '</svg>';
    $yLabels = '';
    for ($k = 0; $k <= 4; $k++) {
        $yLabels .= '<span class="da-y" style="top:' . (100 - $k * 25) . '%">' . e(da_compact($top * $k / 4)) . '</span>';
    }
    $step = max(1, (int) ceil($n / 7));
    $mobileStep = max(1, (int) ceil($n / 4));
    $xLabels = '';
    foreach ($labels as $i => $label) {
        if ($i % $step !== 0 && $i !== $n - 1) {
            continue;
        }
        if ($i === $n - 1 && $i % $step !== 0 && ($n - 1) % $step < $step / 2) {
            continue;   // too close to the previous label
        }
        $minor = $i % $mobileStep !== 0 ? ' is-minor' : '';
        $pos = $n === 1 ? 50 : round($i / ($n - 1) * 100, 3);
        $align = $pos <= 3 ? ' is-start' : ($pos >= 97 ? ' is-end' : '');
        $xLabels .= '<span class="da-x' . $minor . $align . '" style="left:' . $pos . '%">' . e($label) . '</span>';
    }
    $legend = '<ul class="da-legend">';
    foreach ($series as $s) {
        $legend .= '<li><span class="da-key ' . e($s['class']) . (!empty($s['dashed']) ? ' is-dashed' : '') . '" aria-hidden="true"></span>' . e($s['name']) . '</li>';
    }
    $legend .= '</ul>';
    // text alternative: the same numbers as a table
    $table = '<details class="da-table-view"><summary>Show as table</summary><div class="table-wrap" tabindex="0" role="region" aria-label="Table (scroll sideways on small screens)"><table class="table table-compact"><caption class="sr-only">' . e($title) . '</caption><thead><tr><th scope="col">Date</th>';
    foreach ($series as $s) {
        $table .= '<th scope="col" class="text-right">' . e($s['name']) . '</th>';
    }
    $table .= '</tr></thead><tbody>';
    foreach ($labels as $i => $label) {
        $table .= '<tr><th scope="row">' . e($label) . '</th>';
        foreach ($series as $s) {
            $table .= '<td class="text-right">' . number_format((float) (array_values($s['values'])[$i] ?? 0)) . '</td>';
        }
        $table .= '</tr>';
    }
    $table .= '</tbody></table></div></details>';
    return '<figure class="da-trend" role="group" aria-label="' . e($title) . '">' . $legend
        . '<div class="da-trend-plot">' . $svg . $dots . '<div class="da-y-axis" aria-hidden="true">' . $yLabels . '</div><div class="da-x-axis" aria-hidden="true">' . $xLabels . '</div></div>'
        . $table . '</figure>';
}

/** Horizontal bar list: rows of [label html, value, extra text]. */
function da_bars(array $rows, float $total, string $empty = 'No data for this period.', int $limit = 12): string
{
    if (!$rows) {
        return '<p class="muted mb-0">' . e($empty) . '</p>';
    }
    $max = max(1, max(array_map(function ($r) { return (float) $r[1]; }, $rows)));
    $html = '<ul class="da-bars">';
    foreach (array_slice($rows, 0, $limit) as [$label, $value, $extra]) {
        $html .= '<li><span class="da-bars-label">' . $label . '</span>'
            . '<span class="da-bars-track" aria-hidden="true"><span style="width:' . ((float) $value > 0 ? max(1, round((float) $value / $max * 100, 1)) : 0) . '%"></span></span>'
            . '<span class="da-bars-value">' . number_format((float) $value) . ' <small class="muted">' . e(insights_pct((float) $value, $total, 0)) . ($extra !== '' ? ' · ' . $extra : '') . '</small></span></li>';
    }
    return $html . '</ul>';
}

/** Page title + path cell. */
function da_page_cell(string $path, array $titles): string
{
    $title = $titles[$path] ?? ($path === '/' ? 'Home' : '');
    $href = url($path === '/' ? '/' : rtrim($path, '/') . '/');
    return ($title !== '' ? '<strong>' . e($title) . '</strong><br>' : '') . '<a class="small da-path" href="' . e($href) . '" target="_blank" rel="noopener">' . e($path) . '</a>';
}

function da_place(array $s, bool $withSource = false): string
{
    $parts = array_filter([(string) ($s['city'] ?? ''), (string) ($s['region'] ?? ''), (string) ($s['country'] ?? '')]);
    if (!$parts) {
        return '<span class="muted">Unknown</span>';
    }
    $src = ['cloudflare' => 'Cloudflare', 'ipinfo' => 'ipinfo.io', 'ipapi' => 'ipapi.co', 'timezone' => 'time zone'][$s['geo_source'] ?? ''] ?? '';
    return e(implode(', ', array_unique($parts))) . ($withSource && $src !== '' ? ' <small class="muted">(' . e($src) . ')</small>' : '');
}

/** "1 visit" / "3 visits" */
function da_n(int $n, string $word): string
{
    return number_format($n) . ' ' . $word . ($n === 1 ? '' : 's');
}

function da_empty(string $title, string $text): string
{
    return '<div class="empty-state da-empty">' . icon('bar-chart') . '<h3>' . e($title) . '</h3><p class="muted">' . e($text) . '</p></div>';
}

/** Rows in chunks for CSV downloads (never loads the whole table in memory). */
function da_chunked(string $sql, array $params, callable $map, int $max = 100000): Generator
{
    $last = 0;
    $total = 0;
    do {
        $rows = db_rows($sql . ' AND x.id > ? ORDER BY x.id LIMIT 2000', array_merge($params, [$last]));
        foreach ($rows as $r) {
            $last = (int) $r['id'];
            yield $map($r);
            if (++$total >= $max) {
                return;
            }
        }
    } while (count($rows) === 2000);
}

/** Real-time panel (also returned as JSON for the 15-second refresh). */
function da_realtime_html(array $titles): string
{
    $active = db_rows("SELECT s.sid, s.user_id, s.started_at, s.last_seen_at, s.exit_path, s.channel, s.referrer_host, s.device, s.browser, s.os,
                              s.city, s.region, s.country, s.geo_source, s.pageviews, s.engaged_seconds, u.name AS member_name
                       FROM analytics_sessions s LEFT JOIN users u ON u.id = s.user_id
                       WHERE s.last_seen_at >= NOW() - INTERVAL 5 MINUTE ORDER BY s.last_seen_at DESC LIMIT 200");
    $count = count($active);
    $perMinute = array_column(db_rows("SELECT FLOOR(TIMESTAMPDIFF(SECOND, created_at, NOW()) / 60) m, COUNT(*) n FROM page_views
                                       WHERE created_at >= NOW() - INTERVAL 30 MINUTE AND session_id IS NOT NULL GROUP BY m"), 'n', 'm');
    $html = '<div class="da-rt-head"><div class="da-rt-count"><strong data-rt-count>' . $count . '</strong><span>' . ($count === 1 ? 'visitor' : 'visitors') . ' active in the last 5 minutes</span></div>';
    $maxPm = max(1, $perMinute ? max($perMinute) : 1);
    $html .= '<div class="da-rt-spark" role="img" aria-label="Page views per minute, last 30 minutes: ' . (int) array_sum($perMinute) . ' in total">';
    for ($m = 29; $m >= 0; $m--) {
        $v = (int) ($perMinute[$m] ?? 0);
        $html .= '<span style="height:' . ($v ? max(8, round($v / $maxPm * 100)) : 3) . '%" title="' . e(($m === 0 ? 'This minute' : $m . ' min ago') . ': ' . $v . ' page views') . '"></span>';
    }
    $html .= '</div><p class="small muted da-rt-spark-label">Page views per minute · last 30 minutes</p></div>';
    if (!$count) {
        return $html . da_empty('Nobody is on the website right now', 'When someone browses the public website, their current page, source, location and device appear here within a few seconds. Staff, bots and Do-Not-Track visitors are never shown.');
    }
    $group = function (callable $key) use ($active): array {
        $out = [];
        foreach ($active as $s) {
            $k = $key($s);
            $out[$k] = ($out[$k] ?? 0) + 1;
        }
        arsort($out);
        return $out;
    };
    $lists = [
        'Pages being viewed' => $group(function ($s) { return (string) $s['exit_path']; }),
        'Sources' => $group(function ($s) { return analytics_channel_label((string) $s['channel']) . ($s['referrer_host'] ? ' · ' . $s['referrer_host'] : ''); }),
        'Locations' => $group(function ($s) { return implode(', ', array_filter([$s['city'], $s['region'], $s['country']])) ?: 'Unknown'; }),
        'Devices' => $group(function ($s) { return humanize((string) $s['device']) . ' · ' . $s['browser'] . ' · ' . $s['os']; }),
    ];
    $html .= '<div class="da-grid-4">';
    foreach ($lists as $title => $rows) {
        $html .= '<section class="panel da-rt-list"><div class="panel-head"><h3>' . e($title) . '</h3></div><ul class="da-list">';
        foreach (array_slice($rows, 0, 8, true) as $k => $n) {
            $label = $title === 'Pages being viewed' ? da_page_cell((string) $k, $titles) : e((string) $k);
            $html .= '<li><span>' . $label . '</span><strong>' . (int) $n . '</strong></li>';
        }
        $html .= '</ul></section>';
    }
    $html .= '</div><section class="panel mt-3"><div class="panel-head"><h3>Active visits</h3></div><div class="table-wrap" tabindex="0" role="region" aria-label="Table (scroll sideways on small screens)"><table class="table table-compact">'
        . '<thead><tr><th>Visit</th><th>Current page</th><th class="hide-sm">Location</th><th class="hide-sm">Device</th><th class="text-right">Pages</th><th class="text-right">Engaged</th></tr></thead><tbody>';
    foreach (array_slice($active, 0, 25) as $s) {
        $html .= '<tr><td class="nowrap"><a href="' . e(admin_url('deep-analytics.php?tab=visitors&visit=' . rawurlencode($s['sid']))) . '">' . e(analytics_visit_code($s['sid'])) . '</a>'
            . ($s['member_name'] ? '<br><small class="muted">' . e($s['member_name']) . '</small>' : '') . '</td>'
            . '<td>' . da_page_cell((string) $s['exit_path'], $titles) . '<br><small class="muted">' . e(analytics_channel_label((string) $s['channel'])) . ' · seen ' . e(format_date($s['last_seen_at'], 'h:i:s A')) . '</small></td>'
            . '<td class="hide-sm small">' . da_place($s) . '</td><td class="hide-sm small">' . e(humanize((string) $s['device']) . ' · ' . $s['browser']) . '</td>'
            . '<td class="text-right">' . (int) $s['pageviews'] . '</td><td class="text-right nowrap">' . e(analytics_duration($s['engaged_seconds'])) . '</td></tr>';
    }
    return $html . '</tbody></table></div></section>';
}

// =====================================================================
// Request setup
// =====================================================================
$installed = table_exists('analytics_sessions') && table_exists('analytics_events') && column_exists('page_views', 'session_id');
$tabs = [
    'overview' => ['Overview', 'bar-chart'], 'realtime' => ['Real-time', 'activity'], 'audience' => ['Audience', 'globe'],
    'acquisition' => ['Acquisition', 'trending-up'], 'behaviour' => ['Behaviour', 'layers'], 'funnels' => ['Funnels', 'filter'],
    'visitors' => ['Visitors & journeys', 'users'], 'export' => ['Export', 'download'], 'settings' => ['Settings', 'settings'],
];
$tab = is_string($_GET['tab'] ?? null) && isset($tabs[$_GET['tab']]) ? $_GET['tab'] : 'overview';
$range = admin_date_range('30d');
[$from, $to] = insights_bounds($range);
$days = (int) round((strtotime($range['to']) - strtotime($range['from'])) / 86400) + 1;
$compare = da_get('compare') !== '0' && $range['key'] !== 'all';
$prevFrom = date('Y-m-d', strtotime($range['from'] . ' -' . $days . ' days')) . ' 00:00:00';
$prevTo = date('Y-m-d', strtotime($range['from'] . ' -1 day')) . ' 23:59:59';
[$seg, $segParams, $segActive] = da_segment();
$keep = [];
foreach (['range', 'from', 'to', 'device', 'channel', 'country', 'who', 'compare'] as $k) {
    if (is_string($_GET[$k] ?? null) && $_GET[$k] !== '') {
        $keep[$k] = $_GET[$k];
    }
}
$tabUrl = function (string $t, array $extra = []) use ($keep): string {
    return '?' . http_build_query(array_merge(['tab' => $t], $keep, $extra));
};
$titles = ['/' => 'Home'];
foreach (db_rows('SELECT slug, title FROM pages') as $p) {
    $titles['/' . $p['slug']] = $p['title'];
}
$canExport = admin_can('exports.run');
$canSettings = admin_can('settings.manage');
$enhanced = analytics_enhanced();

// =====================================================================
// Settings (POST)
// =====================================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('deep-analytics.php?tab=settings');
    require_permission('settings.manage');
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'save') {
        $errors = [];
        $provider = (string) ($_POST['geoip_provider'] ?? 'none');
        if (!isset(analytics_geo_providers()[$provider])) {
            $errors['geoip_provider'] = 'Choose a location source.';
        }
        $mode = (string) ($_POST['analytics_persistent_id'] ?? 'consent');
        if (!in_array($mode, ['consent', 'off'], true)) {
            $errors['analytics_persistent_id'] = 'Choose an option.';
        }
        $retention = (string) ($_POST['analytics_retention_days'] ?? '');
        if (!ctype_digit($retention) || (int) $retention < 30 || (int) $retention > 1095) {
            $errors['analytics_retention_days'] = 'Enter a number of days between 30 and 1095.';
        }
        $token = trim((string) ($_POST['geoip_token'] ?? ''));
        if ($token !== '' && !preg_match('/^[A-Za-z0-9_\-.]{4,120}$/', $token)) {
            $errors['geoip_token'] = 'The token may contain only letters, digits, dots, dashes and underscores.';
        }
        if ($errors) {
            back_with_errors($errors, 'admin/deep-analytics.php?tab=settings', array_diff_key($_POST, ['geoip_token' => 1]));
        }
        $enhancedNew = !empty($_POST['analytics_enhanced']) ? '1' : '0';
        $changes = [];
        foreach (['analytics_enhanced' => $enhancedNew, 'analytics_persistent_id' => $mode, 'geoip_provider' => $provider, 'analytics_retention_days' => (string) (int) $retention] as $k => $v) {
            if (setting($k) !== $v) {
                save_setting($k, $v);
                $changes[] = $k . '=' . $v;
            }
        }
        if (!empty($_POST['geoip_token_remove'])) {
            save_secret_setting('geoip_token', '');
            $changes[] = 'location token removed';
        } elseif ($token !== '') {
            save_secret_setting('geoip_token', $token);
            $changes[] = 'location token replaced';
        }
        if ($changes && $provider !== 'none') {
            save_setting('geoip_backoff_until', '0');
            db_query("DELETE FROM geo_cache WHERE source = 'failed'");   // let the new provider / token try again now
        }
        admin_log('analytics_settings_updated', $changes ? 'Deep analytics settings: ' . implode(', ', $changes) : 'Deep analytics settings saved (no changes)');
        flash('success', $changes ? 'Analytics settings saved.' : 'No changes to save.');
    } elseif ($action === 'test_geo') {
        $provider = analytics_geo_provider();
        if (!in_array($provider, ['ipinfo', 'ipapi'], true)) {
            flash('info', 'The connection test is for ipinfo.io and ipapi.co. With Cloudflare, location appears as soon as visits arrive through Cloudflare.');
        } else {
            save_setting('geoip_backoff_until', '0');
            db_query('DELETE FROM geo_cache WHERE ip_hash = ?', [analytics_ip_hash('8.8.8.8')]);
            $geo = analytics_geo_lookup('8.8.8.8', $provider);
            admin_log('analytics_geo_tested', 'Location lookup test (' . $provider . '): ' . ($geo ? 'OK' : 'failed'));
            if ($geo) {
                flash('success', 'Connection works: the test address (a public DNS server) was located in ' . implode(', ', array_filter([$geo['city'], $geo['region'], $geo['country']])) . '.');
            } else {
                flash('error', 'The lookup did not work: ' . (setting('geoip_last_error') ?: 'no answer') . '. Check the token and the provider\'s free limit.');
            }
        }
    } elseif ($action === 'cleanup') {
        $r = analytics_cleanup();
        admin_log('analytics_cleanup', 'Deep analytics clean-up: ' . $r['sessions'] . ' visits, ' . $r['events'] . ' events, ' . $r['geo'] . ' cache rows deleted');
        flash('success', 'Clean-up done: ' . number_format($r['sessions']) . ' old visits, ' . number_format($r['events']) . ' events and ' . number_format($r['geo']) . ' expired location cache rows deleted.');
    }
    admin_redirect('deep-analytics.php?tab=settings');
}

// =====================================================================
// Real-time JSON (auto refresh)
// =====================================================================
if ($tab === 'realtime' && da_get('format') === 'json') {
    if ($daLastActivity !== null) {
        $_SESSION['admin_last_activity'] = $daLastActivity;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$installed) {
        echo json_encode(['ok' => false]);
        exit;
    }
    $html = da_realtime_html($titles);
    $count = (int) db_value('SELECT COUNT(*) FROM analytics_sessions WHERE last_seen_at >= NOW() - INTERVAL 5 MINUTE');
    echo json_encode(['ok' => true, 'html' => $html, 'active' => $count, 'updated' => date('h:i:s A')]);
    exit;
}

// =====================================================================
// CSV exports
// =====================================================================
$export = da_get('export');
if ($export !== '' && !in_array($export, ['sessions', 'pageviews', 'events'], true)) {
    $export = '';   // unknown file type: show the Export tab instead of writing a misleading audit entry
}
if ($export !== '' && $installed) {
    require_permission('exports.run');
    $suffix = $range['from'] . '-to-' . $range['to'] . '.csv';
    $p = array_merge([$from, $to], $segParams);
    admin_log('report_exported', 'Deep analytics (' . $export . ') ' . $range['from'] . ' to ' . $range['to'] . ($segActive ? ' · ' . implode(', ', $segActive) : ''));
    if ($export === 'sessions') {
        send_csv('visits-' . $suffix, ['Visit', 'Started', 'Last seen', 'Duration (s)', 'Landing page', 'Exit page', 'Channel', 'Referrer', 'UTM source', 'UTM medium', 'UTM campaign',
            'Device', 'Browser', 'Browser version', 'OS', 'Screen', 'Language', 'Time zone', 'Country code', 'Country', 'Region', 'City', 'Location source',
            'Returning', 'Member user id', 'Pages', 'Engaged seconds', 'Events', 'Conversion', 'Bounce'],
            da_chunked("SELECT x.* FROM analytics_sessions x JOIN analytics_sessions s ON s.id = x.id WHERE x.started_at BETWEEN ? AND ? $seg", $p, function ($r) {
                return [analytics_visit_code($r['sid']), $r['started_at'], $r['last_seen_at'], strtotime($r['last_seen_at']) - strtotime($r['started_at']), $r['landing_path'], $r['exit_path'],
                    analytics_channel_label($r['channel']), $r['referrer_host'], $r['utm_source'], $r['utm_medium'], $r['utm_campaign'], $r['device'], $r['browser'], $r['browser_version'], $r['os'],
                    $r['screen'], $r['language'], $r['timezone'], $r['country_code'], $r['country'], $r['region'], $r['city'], $r['geo_source'],
                    $r['visitor_id'] === null ? 'unknown' : ((int) $r['is_returning'] ? 'yes' : 'no'), $r['user_id'], $r['pageviews'], $r['engaged_seconds'], $r['events'], $r['converted'], $r['bounce']];
            }));
    } elseif ($export === 'pageviews') {
        send_csv('page-views-' . $suffix, ['Page view id', 'Visit', 'Time', 'Path', 'Engaged seconds', 'Max scroll %', 'Load time (ms)', 'Left at', 'Device'],
            da_chunked("SELECT x.id, s.sid, x.created_at, x.path, x.engaged_seconds, x.max_scroll, x.load_ms, x.left_at, x.device
                        FROM page_views x JOIN analytics_sessions s ON s.id = x.session_id WHERE x.created_at BETWEEN ? AND ? $seg", $p, function ($r) {
                return [$r['id'], analytics_visit_code($r['sid']), $r['created_at'], $r['path'], $r['engaged_seconds'], $r['max_scroll'], $r['load_ms'], $r['left_at'], $r['device']];
            }));
    } elseif ($export === 'events') {
        send_csv('events-' . $suffix, ['Event id', 'Visit', 'Time', 'Type', 'Label', 'Target', 'Page'],
            da_chunked("SELECT x.id, s.sid, x.created_at, x.type, x.label, x.target, x.path
                        FROM analytics_events x JOIN analytics_sessions s ON s.id = x.session_id WHERE x.created_at BETWEEN ? AND ? $seg", $p, function ($r) {
                return [$r['id'], analytics_visit_code($r['sid']), $r['created_at'], analytics_event_label($r['type']), $r['label'], $r['target'], $r['path']];
            }));
    }
    admin_redirect('deep-analytics.php?tab=export');
}

// =====================================================================
// Data for the selected tab
// =====================================================================
$P = array_merge([$from, $to], $segParams);
$PP = array_merge([$prevFrom, $prevTo], $segParams);
$S = "FROM analytics_sessions s WHERE s.started_at BETWEEN ? AND ? $seg";
$data = [];
$visit = null;

if ($installed && in_array($tab, ['overview', 'funnels', 'visitors'], true)) {
    analytics_sync_verified_donations($range['key'] === 'all' ? '2000-01-01' : $prevFrom);
}
if ($installed && in_array($tab, ['realtime', 'visitors'], true)) {
    analytics_unlink_erased_users();   // members who asked for their account to be deleted are never shown by name
}

if ($installed && $tab === 'overview') {
    $kpiSql = "SELECT COUNT(*) sessions, COUNT(DISTINCT COALESCE(s.visitor_id, s.day_hash, s.sid)) visitors, COALESCE(SUM(s.pageviews), 0) views,
                      COALESCE(AVG(s.engaged_seconds), 0) engaged, COALESCE(AVG(s.bounce), 0) bounce, COALESCE(SUM(s.is_returning), 0) returning_n,
                      COALESCE(SUM(s.visitor_id IS NOT NULL), 0) known, COALESCE(SUM(s.converted IS NOT NULL AND s.converted <> 'donation_started'), 0) conv_sessions $S";
    $data['k'] = db_row($kpiSql, $P);
    $data['kp'] = $compare ? db_row($kpiSql, $PP) : null;
    $data['conversions'] = (int) db_value("SELECT COUNT(*) FROM analytics_events ae JOIN analytics_sessions s ON s.id = ae.session_id
                                           WHERE ae.type = 'conversion' AND ae.label <> 'donation_started' AND s.started_at BETWEEN ? AND ? $seg", $P);
    $data['conversions_prev'] = $compare ? (int) db_value("SELECT COUNT(*) FROM analytics_events ae JOIN analytics_sessions s ON s.id = ae.session_id
                                           WHERE ae.type = 'conversion' AND ae.label <> 'donation_started' AND s.started_at BETWEEN ? AND ? $seg", $PP) : null;
    // daily trend (monthly for long periods)
    $byMonth = $days > 92;
    $fmt = $byMonth ? '%Y-%m' : '%Y-%m-%d';
    $cur = [];
    foreach (db_rows("SELECT DATE_FORMAT(s.started_at, '$fmt') k, COUNT(*) n, COALESCE(SUM(s.pageviews), 0) v $S GROUP BY k", $P) as $r) {
        $cur[$r['k']] = $r;
    }
    $prevRows = [];
    if ($compare && !$byMonth) {
        foreach (db_rows("SELECT DATE_FORMAT(s.started_at, '$fmt') k, COUNT(*) n $S GROUP BY k", $PP) as $r) {
            $prevRows[$r['k']] = (int) $r['n'];
        }
    }
    $labels = $sessionsSeries = $viewsSeries = $prevSeries = [];
    if ($byMonth) {
        $start = $range['key'] === 'all' && $cur ? strtotime(min(array_keys($cur)) . '-01') : strtotime(date('Y-m-01', strtotime($range['from'])));
        for ($t = $start; $t <= strtotime($range['to']); $t = strtotime('+1 month', $t)) {
            $k = date('Y-m', $t);
            $labels[] = date('M Y', $t);
            $sessionsSeries[] = (int) ($cur[$k]['n'] ?? 0);
            $viewsSeries[] = (int) ($cur[$k]['v'] ?? 0);
        }
    } else {
        $i = 0;
        for ($t = strtotime($range['from']); $t <= strtotime($range['to']); $t = strtotime('+1 day', $t), $i++) {
            $k = date('Y-m-d', $t);
            $labels[] = date('d M', $t);
            $sessionsSeries[] = (int) ($cur[$k]['n'] ?? 0);
            $viewsSeries[] = (int) ($cur[$k]['v'] ?? 0);
            $prevSeries[] = (int) ($prevRows[date('Y-m-d', strtotime($prevFrom) + $i * 86400)] ?? 0);
        }
    }
    $series = [['name' => 'Page views', 'class' => 'c2', 'values' => $viewsSeries], ['name' => 'Visits', 'class' => 'c1', 'values' => $sessionsSeries, 'area' => true]];
    if ($compare && !$byMonth && array_sum($prevSeries) > 0) {
        $series[] = ['name' => 'Visits, previous period', 'class' => 'c0', 'values' => $prevSeries, 'dashed' => true];
    }
    $data['trend'] = array_sum($sessionsSeries) > 0 ? da_trend_chart($labels, $series, 'Visits and page views per ' . ($byMonth ? 'month' : 'day')) : '';
    $data['trend_unit'] = $byMonth ? 'month' : 'day';
    $data['top_pages'] = db_rows("SELECT pv.path, COUNT(*) views, COALESCE(AVG(pv.engaged_seconds), 0) eng FROM page_views pv JOIN analytics_sessions s ON s.id = pv.session_id
                                  WHERE pv.created_at BETWEEN ? AND ? $seg GROUP BY pv.path ORDER BY views DESC LIMIT 6", $P);
    $data['channels'] = db_rows("SELECT s.channel k, COUNT(*) n $S GROUP BY k ORDER BY n DESC", $P);
    $data['countries'] = db_rows("SELECT COALESCE(s.country, '') k, COUNT(*) n $S GROUP BY k ORDER BY n DESC LIMIT 6", $P);
    $data['devices'] = db_rows("SELECT s.device k, COUNT(*) n $S GROUP BY k ORDER BY n DESC", $P);
    $data['since'] = db_value('SELECT MIN(started_at) FROM analytics_sessions');
}

if ($installed && $tab === 'audience') {
    $data['total'] = (int) db_value("SELECT COUNT(*) $S", $P);
    $data['countries'] = db_rows("SELECT s.country_code cc, COALESCE(s.country, '') k, COUNT(*) n, AVG(s.engaged_seconds) eng $S GROUP BY cc, k ORDER BY n DESC LIMIT 25", $P);
    $data['regions'] = db_rows("SELECT COALESCE(s.region, '') k, COALESCE(s.country, '') c, COUNT(*) n $S AND s.region IS NOT NULL GROUP BY k, c ORDER BY n DESC LIMIT 20", $P);
    $data['cities'] = db_rows("SELECT COALESCE(s.city, '') k, COALESCE(s.region, '') r, COALESCE(s.country, '') c, COUNT(*) n $S AND s.city IS NOT NULL GROUP BY k, r, c ORDER BY n DESC LIMIT 20", $P);
    $data['geo_sources'] = array_column(db_rows("SELECT COALESCE(s.geo_source, 'unknown') k, COUNT(*) n $S GROUP BY k", $P), 'n', 'k');
    foreach (['device' => 's.device', 'browser' => 's.browser', 'os' => 's.os', 'screen' => "COALESCE(s.screen, '')", 'language' => "COALESCE(s.language, '')"] as $key => $col) {
        $data[$key] = db_rows("SELECT $col k, COUNT(*) n, AVG(s.engaged_seconds) eng $S GROUP BY k ORDER BY n DESC LIMIT 15", $P);
    }
    $data['browser_versions'] = db_rows("SELECT CONCAT(s.browser, ' ', COALESCE(s.browser_version, '')) k, COUNT(*) n $S GROUP BY k ORDER BY n DESC LIMIT 10", $P);
    $data['who'] = db_row("SELECT SUM(s.visitor_id IS NOT NULL AND s.is_returning = 0) new_n, SUM(s.is_returning = 1) ret_n, SUM(s.visitor_id IS NULL) unknown_n,
                                  SUM(s.user_id IS NOT NULL) members, AVG(CASE WHEN s.is_returning = 1 THEN s.engaged_seconds END) ret_eng,
                                  AVG(CASE WHEN s.visitor_id IS NOT NULL AND s.is_returning = 0 THEN s.engaged_seconds END) new_eng $S", $P);
    $heat = [];
    foreach (db_rows("SELECT WEEKDAY(s.started_at) d, HOUR(s.started_at) h, COUNT(*) n $S GROUP BY d, h", $P) as $r) {
        $heat[(int) $r['d']][(int) $r['h']] = (int) $r['n'];
    }
    $data['heat'] = $heat;
}

if ($installed && $tab === 'acquisition') {
    $data['total'] = (int) db_value("SELECT COUNT(*) $S", $P);
    $agg = "COUNT(*) n, AVG(s.engaged_seconds) eng, AVG(s.bounce) bounce, AVG(s.pageviews) pps, SUM(s.converted IS NOT NULL AND s.converted <> 'donation_started') conv";
    $data['channels'] = db_rows("SELECT s.channel k, $agg $S GROUP BY k ORDER BY n DESC", $P);
    $data['referrers'] = db_rows("SELECT s.referrer_host k, $agg $S AND s.referrer_host IS NOT NULL GROUP BY k ORDER BY n DESC LIMIT 25", $P);
    $data['campaigns'] = db_rows("SELECT COALESCE(s.utm_source, '') src, COALESCE(s.utm_medium, '') med, COALESCE(s.utm_campaign, '') camp, $agg $S
                                  AND (s.utm_source IS NOT NULL OR s.utm_medium IS NOT NULL OR s.utm_campaign IS NOT NULL) GROUP BY src, med, camp ORDER BY n DESC LIMIT 25", $P);
    $data['landing_by_channel'] = db_rows("SELECT s.channel ch, s.landing_path lp, COUNT(*) n $S GROUP BY ch, lp ORDER BY n DESC LIMIT 15", $P);
}

if ($installed && $tab === 'behaviour') {
    $PV = "FROM page_views pv JOIN analytics_sessions s ON s.id = pv.session_id WHERE pv.created_at BETWEEN ? AND ? $seg";
    $pages = db_rows("SELECT pv.path, COUNT(*) views, COUNT(DISTINCT COALESCE(s.visitor_id, s.day_hash, s.sid)) visitors, AVG(pv.engaged_seconds) eng,
                             AVG(pv.max_scroll) scroll, AVG(pv.load_ms) load_ms $PV GROUP BY pv.path ORDER BY views DESC LIMIT 40", $P);
    $entr = array_column(db_rows("SELECT s.landing_path k, COUNT(*) n, SUM(s.bounce) b $S GROUP BY k", $P), null, 'k');
    $exits = array_column(db_rows("SELECT s.exit_path k, COUNT(*) n $S GROUP BY k", $P), 'n', 'k');
    $data['pages'] = $pages;
    $data['entr'] = $entr;
    $data['exits'] = $exits;
    $data['views_total'] = (int) db_value("SELECT COUNT(*) $PV", $P);
    $data['landing'] = db_rows("SELECT s.landing_path k, COUNT(*) n, AVG(s.bounce) bounce, AVG(s.engaged_seconds) eng, AVG(s.pageviews) pps,
                                       SUM(s.converted IS NOT NULL AND s.converted <> 'donation_started') conv $S GROUP BY k ORDER BY n DESC LIMIT 15", $P);
    $viewsByPath = array_column(db_rows("SELECT pv.path k, COUNT(*) n $PV GROUP BY k", $P), 'n', 'k');
    $exitRows = [];
    foreach ($exits as $path => $n) {
        $exitRows[] = ['k' => (string) $path, 'n' => (int) $n, 'views' => (int) ($viewsByPath[$path] ?? 0)];
    }
    usort($exitRows, function ($a, $b) { return $b['n'] <=> $a['n']; });
    $data['exit_rows'] = array_slice($exitRows, 0, 15);
    $EV = "FROM analytics_events ae JOIN analytics_sessions s ON s.id = ae.session_id WHERE ae.created_at BETWEEN ? AND ? $seg";
    $data['event_types'] = db_rows("SELECT ae.type k, COUNT(*) n, COUNT(DISTINCT ae.session_id) sessions $EV GROUP BY k ORDER BY n DESC", $P);
    $data['event_labels'] = db_rows("SELECT ae.type t, COALESCE(ae.label, '') l, COUNT(*) n, COUNT(DISTINCT ae.session_id) sessions $EV
                                     AND ae.type IN ('cta_click', 'form_submit', 'share', 'video_play', 'phone', 'email', 'whatsapp', 'rage_click', 'conversion')
                                     GROUP BY t, l ORDER BY n DESC LIMIT 30", $P);
    $data['downloads'] = db_rows("SELECT COALESCE(ae.target, '') k, MAX(ae.label) l, COUNT(*) n, COUNT(DISTINCT ae.session_id) sessions $EV AND ae.type = 'download' GROUP BY k ORDER BY n DESC LIMIT 15", $P);
    $data['outbound'] = db_rows("SELECT SUBSTRING_INDEX(COALESCE(ae.target, ''), '/', 1) k, COUNT(*) n, COUNT(DISTINCT ae.session_id) sessions $EV AND ae.type = 'outbound' GROUP BY k ORDER BY n DESC LIMIT 15", $P);
    $data['searches'] = db_rows("SELECT COALESCE(ae.label, '') k, COUNT(*) n, COUNT(DISTINCT ae.session_id) sessions, MAX(ae.path) p $EV AND ae.type = 'search' GROUP BY k ORDER BY n DESC LIMIT 20", $P);
    $data['errors'] = db_rows("SELECT COALESCE(ae.label, '') k, COALESCE(ae.target, '') t, COUNT(*) n, MAX(ae.created_at) last_at $EV AND ae.type = 'js_error' GROUP BY k, t ORDER BY n DESC LIMIT 10", $P);
    $data['scroll'] = array_column(db_rows("SELECT ae.label k, COUNT(DISTINCT ae.page_view_id) n $EV AND ae.type = 'scroll_depth' GROUP BY k", $P), 'n', 'k');
    $data['slow'] = db_rows("SELECT pv.path, AVG(pv.load_ms) avg_ms, MAX(pv.load_ms) max_ms, COUNT(pv.load_ms) samples $PV AND pv.load_ms IS NOT NULL
                             GROUP BY pv.path HAVING samples >= 2 ORDER BY avg_ms DESC LIMIT 10", $P);
}

if ($installed && $tab === 'funnels') {
    $data['sessions'] = (int) db_value("SELECT COUNT(*) $S", $P);
    $pathOf = function (string $template): array {
        return array_map(function ($slug) { return '/' . $slug; }, array_column(db_rows("SELECT slug FROM pages WHERE template = ? AND status = 'published'", [$template]), 'slug'));
    };
    $viewed = function (array $paths) use ($P, $seg): int {
        if (!$paths) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($paths), '?'));
        return (int) db_value("SELECT COUNT(DISTINCT pv.session_id) FROM page_views pv JOIN analytics_sessions s ON s.id = pv.session_id
                               WHERE s.started_at BETWEEN ? AND ? $seg AND pv.path IN ($in)", array_merge($P, $paths));
    };
    $converted = function (string $type) use ($P, $seg): int {
        return (int) db_value("SELECT COUNT(DISTINCT ae.session_id) FROM analytics_events ae JOIN analytics_sessions s ON s.id = ae.session_id
                               WHERE s.started_at BETWEEN ? AND ? $seg AND ae.type = 'conversion' AND ae.label = ?", array_merge($P, [$type]));
    };
    $donatePaths = $pathOf('donate') ?: ['/donate'];
    $data['donation'] = [
        ['Visits', $data['sessions'], 'All recorded visits in the period'],
        ['Viewed the donate page', $viewed($donatePaths), implode(', ', $donatePaths)],
        ['Started a donation', $converted('donation_started'), 'Submitted the donation form (checkout opened)'],
        ['Donation verified', (int) db_value("SELECT COUNT(DISTINCT ae.session_id) FROM analytics_events ae JOIN analytics_sessions s ON s.id = ae.session_id
                                                JOIN donations d ON d.id = CAST(ae.target AS UNSIGNED) AND d.status = 'success'
                                                WHERE s.started_at BETWEEN ? AND ? $seg AND ae.type = 'conversion' AND ae.label = 'donation_started'", $P), 'Payment verified with the gateway'],
    ];
    $data['donation_all'] = db_row("SELECT SUM(event_type = 'donation_started') started, SUM(event_type = 'donation_success') verified FROM marketing_events WHERE created_at BETWEEN ? AND ?", [$from, $to]);
    $data['donation_value'] = (float) db_value("SELECT COALESCE(SUM(amount), 0) FROM donations WHERE status = 'success' AND created_at BETWEEN ? AND ?", [$from, $to]);
    $data['by_channel'] = db_rows("SELECT s.channel ch, COUNT(DISTINCT s.id) n,
                                          COUNT(DISTINCT CASE WHEN pv.path IN (" . implode(',', array_fill(0, count($donatePaths), '?')) . ") THEN s.id END) donate_page,
                                          COUNT(DISTINCT CASE WHEN ae.label = 'donation_started' THEN s.id END) started,
                                          COUNT(DISTINCT CASE WHEN s.converted = 'donation_success' THEN s.id END) verified
                                   FROM analytics_sessions s
                                   LEFT JOIN page_views pv ON pv.session_id = s.id
                                   LEFT JOIN analytics_events ae ON ae.session_id = s.id AND ae.type = 'conversion'
                                   WHERE s.started_at BETWEEN ? AND ? $seg GROUP BY ch ORDER BY n DESC", array_merge($donatePaths, $P));
    $apply = [];
    foreach ([['volunteer', 'Volunteer', 'volunteer_application'], ['teacher', 'Volunteer teacher', 'teacher_application'],
              ['staff', 'Jobs / staff', 'staff_application'], ['internship', 'Internship', 'internship_application']] as [$tpl, $label, $type]) {
        $paths = $pathOf($tpl);
        $views = $viewed($paths);
        $apps = $converted($type);
        $apply[] = ['label' => $label, 'paths' => $paths, 'views' => $views, 'apps' => $apps,
            'all' => (int) db_value('SELECT COUNT(*) FROM marketing_events WHERE event_type = ? AND created_at BETWEEN ? AND ?', [$type, $from, $to])];
    }
    $data['apply'] = $apply;
    $data['other'] = db_rows("SELECT ae.label k, COUNT(DISTINCT ae.session_id) n FROM analytics_events ae JOIN analytics_sessions s ON s.id = ae.session_id
                              WHERE s.started_at BETWEEN ? AND ? $seg AND ae.type = 'conversion' GROUP BY k ORDER BY n DESC", $P);
}

if ($installed && $tab === 'visitors') {
    $visitCode = da_get('visit');
    if ($visitCode !== '' && preg_match('/^[a-f0-9]{16}$/', $visitCode)) {
        $visit = db_row('SELECT s.*, u.name AS member_name, u.email AS member_email FROM analytics_sessions s LEFT JOIN users u ON u.id = s.user_id WHERE s.sid = ?', [$visitCode]);
    }
    if ($visit) {
        $views = db_rows('SELECT id, path, created_at, engaged_seconds, max_scroll, load_ms, left_at FROM page_views WHERE session_id = ? ORDER BY created_at, id LIMIT 500', [$visit['id']]);
        $events = db_rows('SELECT id, page_view_id, type, label, target, path, created_at, value FROM analytics_events WHERE session_id = ? ORDER BY created_at, id LIMIT 1000', [$visit['id']]);
        $timeline = [];
        foreach ($views as $v) {
            $timeline[] = ['t' => strtotime($v['created_at']), 'o' => 0, 'id' => (int) $v['id'], 'kind' => 'view', 'row' => $v];
        }
        foreach ($events as $ev) {
            $timeline[] = ['t' => strtotime($ev['created_at']), 'o' => 1, 'id' => (int) $ev['id'], 'kind' => 'event', 'row' => $ev];
        }
        usort($timeline, function ($a, $b) { return [$a['t'], $a['o'], $a['id']] <=> [$b['t'], $b['o'], $b['id']]; });
        $data['timeline'] = $timeline;
        $data['related'] = [];
        if ($visit['visitor_id'] !== null || $visit['user_id'] !== null) {
            $data['related'] = db_rows('SELECT sid, started_at, landing_path, pageviews, engaged_seconds, converted, channel FROM analytics_sessions
                                        WHERE id <> ? AND ((visitor_id IS NOT NULL AND visitor_id = ?) OR (user_id IS NOT NULL AND user_id = ?)) ORDER BY started_at DESC LIMIT 15',
                [$visit['id'], $visit['visitor_id'], $visit['user_id']]);
        }
        $data['donations'] = db_rows("SELECT d.id, d.donation_ref, d.amount, d.status FROM analytics_events ae JOIN donations d ON d.id = CAST(ae.target AS UNSIGNED)
                                      WHERE ae.session_id = ? AND ae.type = 'conversion' AND ae.label IN ('donation_started', 'donation_success') GROUP BY d.id", [$visit['id']]);
    } else {
        $where = $S;
        $params = $P;
        $q = trim(da_get('q'));
        if ($q !== '') {
            $code = strtolower((string) preg_replace('/^v-/i', '', $q));
            $where .= ' AND (s.sid LIKE ? OR s.user_id IN (SELECT id FROM users WHERE name LIKE ? OR email LIKE ?) OR s.landing_path LIKE ? OR s.city LIKE ?)';
            array_push($params, (preg_match('/^[a-f0-9]{2,16}$/', $code) ? $code : '-') . '%', '%' . $q . '%', '%' . $q . '%', '%' . $q . '%', $q . '%');
        }
        $member = (int) da_get('member');
        if ($member > 0) {
            $where .= ' AND s.user_id = ?';
            $params[] = $member;
            $data['member_name'] = (string) db_value('SELECT name FROM users WHERE id = ?', [$member]);
        }
        $pg = paginate((int) db_value("SELECT COUNT(*) $where", $params), 25);
        $data['pg'] = $pg;
        $data['list'] = db_rows("SELECT s.* $where ORDER BY s.started_at DESC LIMIT " . (int) $pg['per_page'] . ' OFFSET ' . (int) $pg['offset'], $params);
        // member names in one extra query ($where is shared with the COUNT above)
        if ($data['list']) {
            $ids = array_column($data['list'], 'user_id');
            $names = [];
            $ids = array_values(array_filter(array_unique(array_map('intval', $ids))));
            if ($ids) {
                $names = array_column(db_rows('SELECT id, name FROM users WHERE id IN (' . implode(',', $ids) . ')'), 'name', 'id');
            }
            foreach ($data['list'] as &$row) {
                $row['member_name'] = $row['user_id'] ? ($names[(int) $row['user_id']] ?? null) : null;
            }
            unset($row);
        }
    }
}

if ($installed && $tab === 'settings') {
    $data['stats'] = db_row('SELECT (SELECT COUNT(*) FROM analytics_sessions) sessions, (SELECT COUNT(*) FROM analytics_events) events,
                                    (SELECT COUNT(*) FROM geo_cache) geo, (SELECT MIN(started_at) FROM analytics_sessions) oldest');
}

$GLOBALS['adminExtraScripts'][] = asset('assets/js/modules/admin-deep-analytics.js');
admin_page_start('Deep Analytics & Visitors');
?>
<nav class="admin-tabs da-tabs" aria-label="Deep analytics sections">
    <?php foreach ($tabs as $key => [$label, $ic]): ?>
        <a href="<?= e($tabUrl($key)) ?>" class="<?= $key === $tab ? 'active' : '' ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= icon($ic) ?> <?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!$installed): ?>
    <div class="alert alert-warning"><?= icon('alert-triangle') ?><div>The deep analytics tables are being installed. Reload this page in a moment; if this message stays, check the PHP error log for a failed database upgrade (migration_401).</div></div>
<?php admin_page_end(); return; endif; ?>

<?php if (!$enhanced && $tab !== 'settings'): ?>
    <div class="alert alert-info mb-3"><?= icon('info') ?><div><strong>Enhanced analytics is switched off.</strong> No new visits, engaged time or events are being recorded<?= setting('analytics_enabled', '1') !== '1' ? ' (website statistics as a whole are switched off in Settings)' : '' ?>. Earlier data is still shown. <a href="<?= e($tabUrl('settings')) ?>">Change in Settings</a>.</div></div>
<?php endif; ?>

<?php if (!in_array($tab, ['realtime', 'settings'], true) && !$visit): ?>
<div class="dash-welcome da-toolbar">
    <div>
        <h2><?= e($tabs[$tab][0]) ?></h2>
        <p class="muted small mb-0"><?= $range['key'] === 'custom' ? '' : e($range['label']) . ' · ' ?><?= e(format_date($range['from'])) ?> – <?= e(format_date($range['to'])) ?><?= $compare && $tab === 'overview' ? ' · compared with ' . e(format_date($prevFrom) . ' – ' . format_date($prevTo)) : '' ?></p>
    </div>
    <div class="report-tools">
        <?= admin_range_picker($range) ?>
        <?php if ($tab === 'overview' && $range['key'] !== 'all'): ?>
            <a class="btn btn-ghost btn-sm" href="<?= e($tabUrl('overview', ['compare' => $compare ? '0' : '1'])) ?>"><?= icon($compare ? 'check-circle' : 'refresh') ?><?= $compare ? 'Comparing with previous period' : 'Compare with previous period' ?></a>
        <?php endif; ?>
    </div>
</div>
<?php if ($tab !== 'export'): ?>
<form class="filter-row da-segment" method="get" aria-label="Filter visits">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <?php foreach (['range', 'from', 'to', 'compare'] as $k): if (isset($keep[$k])): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($keep[$k]) ?>"><?php endif; endforeach; ?>
    <?php if ($tab === 'visitors' && (int) da_get('member') > 0): ?><input type="hidden" name="member" value="<?= (int) da_get('member') ?>"><?php endif; ?>
    <label class="sr-only" for="da-device">Device</label>
    <select id="da-device" name="device"><option value="">All devices</option><?php foreach (['mobile', 'tablet', 'desktop'] as $d): ?><option value="<?= $d ?>"<?= da_get('device') === $d ? ' selected' : '' ?>><?= e(humanize($d)) ?></option><?php endforeach; ?></select>
    <label class="sr-only" for="da-channel">Channel</label>
    <select id="da-channel" name="channel"><option value="">All channels</option><?php foreach (ANALYTICS_CHANNELS as $k => $label): ?><option value="<?= e($k) ?>"<?= da_get('channel') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
    <label class="sr-only" for="da-country">Country</label>
    <select id="da-country" name="country"><option value="">All countries</option>
        <?php foreach (db_rows('SELECT country_code cc, MAX(country) c, COUNT(*) n FROM analytics_sessions WHERE started_at BETWEEN ? AND ? AND country_code IS NOT NULL GROUP BY cc ORDER BY n DESC LIMIT 40', [$from, $to]) as $c): ?>
            <option value="<?= e($c['cc']) ?>"<?= strtoupper(da_get('country')) === $c['cc'] ? ' selected' : '' ?>><?= e($c['c'] ?: $c['cc']) ?></option>
        <?php endforeach; ?>
    </select>
    <label class="sr-only" for="da-who">Visitors</label>
    <select id="da-who" name="who"><option value="">All visitors</option><?php foreach (['new' => 'New (with consent)', 'returning' => 'Returning', 'member' => 'Signed-in members', 'anonymous' => 'Not signed in', 'converted' => 'Converted visits'] as $k => $label): ?><option value="<?= $k ?>"<?= da_get('who') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
    <button class="btn btn-primary btn-sm" type="submit"><?= icon('filter') ?>Apply</button>
    <?php if ($segActive): ?><a class="btn btn-ghost btn-sm" href="<?= e('?' . http_build_query(array_intersect_key(array_merge(['tab' => $tab], $keep), array_flip(['tab', 'range', 'from', 'to', 'compare'])))) ?>">Clear filters</a><?php endif; ?>
</form>
<?php if ($segActive): ?><p class="small muted da-seg-note"><?= icon('filter') ?> Showing: <?= e(implode(' · ', $segActive)) ?></p><?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<?php
// =====================================================================
// OVERVIEW
// =====================================================================
if ($tab === 'overview'):
    $k = $data['k'];
    $kp = $data['kp'];
    $sessions = (int) $k['sessions'];
    $pps = $sessions ? (float) $k['views'] / $sessions : 0;
    $ppsPrev = $kp && (int) $kp['sessions'] ? (float) $kp['views'] / (int) $kp['sessions'] : null;
    $convRate = $sessions ? (float) $k['conv_sessions'] / $sessions * 100 : 0;
    $retRate = (int) $k['known'] ? (float) $k['returning_n'] / (int) $k['known'] * 100 : null;
    ?>
    <div class="stat-cards insight-kpis da-kpis">
        <div class="stat-card"><span class="icon-circle"><?= icon('users') ?></span><div><strong><?= number_format((int) $k['visitors']) ?></strong><small>Visitors <span class="help-tip" title="Visitors who allowed analytics cookies are counted once over the whole period; everyone else once per day (daily coded value).">?</span></small><?= $kp ? da_delta((float) $k['visitors'], (float) $kp['visitors']) : '' ?></div></div>
        <div class="stat-card"><span class="icon-circle blue"><?= icon('activity') ?></span><div><strong><?= number_format($sessions) ?></strong><small>Visits (sessions)</small><?= $kp ? da_delta($sessions, (float) $kp['sessions']) : '' ?></div></div>
        <div class="stat-card"><span class="icon-circle gold"><?= icon('eye') ?></span><div><strong><?= number_format((int) $k['views']) ?></strong><small>Page views</small><?= $kp ? da_delta((float) $k['views'], (float) $kp['views']) : '' ?></div></div>
        <div class="stat-card"><span class="icon-circle"><?= icon('clock') ?></span><div><strong><?= e(analytics_duration($k['engaged'])) ?></strong><small>Avg. engaged time per visit <span class="help-tip" title="Time the page was visible and in use; paused when the tab is hidden or after 30 seconds without activity.">?</span></small><?= $kp ? da_delta((float) $k['engaged'], (float) $kp['engaged']) : '' ?></div></div>
        <div class="stat-card"><span class="icon-circle blue"><?= icon('log-out') ?></span><div><strong><?= $sessions ? e(insights_pct((float) $k['bounce'] * 100, 100)) : '–' ?></strong><small>Bounce rate <span class="help-tip" title="Visits with one page, under 10 seconds of engaged time and no conversion.">?</span></small><?= $kp ? da_delta((float) $k['bounce'], (float) $kp['bounce'], true) : '' ?></div></div>
        <div class="stat-card"><span class="icon-circle gold"><?= icon('layers') ?></span><div><strong><?= $sessions ? number_format($pps, 1) : '–' ?></strong><small>Pages per visit</small><?= $kp && $sessions ? da_delta($pps, $ppsPrev) : '' ?></div></div>
        <div class="stat-card"><span class="icon-circle"><?= icon('refresh') ?></span><div><strong><?= $retRate === null ? '–' : number_format($retRate, 1) . '%' ?></strong><small>Returning visitors <span class="help-tip" title="Share of visits by people who allowed analytics cookies and had visited before. Without consent a return visit cannot be recognised.">?</span></small><span class="kpi-sub small muted"><?= number_format((int) $k['known']) ?> visits with consent</span></div></div>
        <div class="stat-card"><span class="icon-circle blue"><?= icon('target') ?></span><div><strong><?= number_format($data['conversions']) ?></strong><small>Conversions · <?= number_format($convRate, 1) ?>% of visits</small><?= $compare ? da_delta((float) $data['conversions'], $data['conversions_prev'] === null ? null : (float) $data['conversions_prev']) : '' ?></div></div>
    </div>

    <?php if (!$sessions): ?>
        <?= da_empty('No visits recorded in this period', 'Once people browse the public website, this page shows visitors, engaged time, bounce rate, sources, locations and conversions. Staff members, search-engine bots and visitors whose browser sends Do Not Track / Global Privacy Control are never counted.' . ($data['since'] ? ' Data is available from ' . format_date($data['since']) . ' - try a longer period.' : '')) ?>
    <?php else: ?>
        <section class="panel">
            <div class="panel-head"><h2>Visits and page views per <?= e($data['trend_unit']) ?></h2></div>
            <?= $data['trend'] ?>
        </section>
        <div class="da-grid-3 mt-3">
            <section class="panel">
                <div class="panel-head"><h2>Top pages</h2><a class="btn btn-ghost btn-sm" href="<?= e($tabUrl('behaviour')) ?>">All pages</a></div>
                <ul class="da-list">
                    <?php foreach ($data['top_pages'] as $p): ?><li><span><?= da_page_cell((string) $p['path'], $titles) ?></span><strong><?= number_format((int) $p['views']) ?></strong></li><?php endforeach; ?>
                </ul>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Channels</h2><a class="btn btn-ghost btn-sm" href="<?= e($tabUrl('acquisition')) ?>">Acquisition</a></div>
                <?= da_bars(array_map(function ($r) { return [e(analytics_channel_label((string) $r['k'])), (int) $r['n'], '']; }, $data['channels']), $sessions) ?>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Countries</h2><a class="btn btn-ghost btn-sm" href="<?= e($tabUrl('audience')) ?>">Audience</a></div>
                <?= da_bars(array_map(function ($r) { return [$r['k'] !== '' ? e($r['k']) : '<span class="muted">Unknown</span>', (int) $r['n'], '']; }, $data['countries']), $sessions) ?>
                <h3 class="da-subhead">Devices</h3>
                <?= da_bars(array_map(function ($r) { return [e(humanize((string) $r['k'])), (int) $r['n'], '']; }, $data['devices']), $sessions) ?>
            </section>
        </div>
    <?php endif; ?>
    <p class="small muted mt-3 da-text"><?= icon('info') ?> Basic page-view counts are in <a href="<?= e(admin_url('analytics.php')) ?>">Website Analytics</a>, conversions by source and the campaign-link builder in <a href="<?= e(admin_url('marketing.php')) ?>">Marketing</a>, and donation / application reports in <a href="<?= e(admin_url('reports.php')) ?>">Reports</a>.</p>

<?php
// =====================================================================
// REAL-TIME
// =====================================================================
elseif ($tab === 'realtime'): ?>
    <div class="dash-welcome da-toolbar">
        <div><h2>Real-time</h2><p class="muted small mb-0">Visits active in the last 5 minutes · <span data-rt-status aria-live="polite">Updated <?= e(date('h:i:s A')) ?></span></p></div>
        <div class="report-tools"><button type="button" class="btn btn-ghost btn-sm" data-rt-toggle aria-pressed="false"><?= icon('clock') ?><span>Pause auto-refresh</span></button>
            <a class="btn btn-ghost btn-sm" href="<?= e($tabUrl('realtime')) ?>"><?= icon('refresh') ?>Refresh now</a></div>
    </div>
    <div data-realtime data-endpoint="<?= e($tabUrl('realtime', ['format' => 'json'])) ?>" data-interval="15000">
        <?= da_realtime_html($titles) ?>
    </div>

<?php
// =====================================================================
// AUDIENCE
// =====================================================================
elseif ($tab === 'audience'):
    $total = $data['total'];
    if (!$total): ?>
        <?= da_empty('No visits in this period', 'Countries, states and cities (approximate, from the visitor\'s network at the time of the visit), devices, browsers, operating systems, screen sizes, languages, new vs returning visitors and the busiest hours will appear here.') ?>
    <?php else:
        $gs = $data['geo_sources']; ?>
        <div class="da-grid-3">
            <section class="panel">
                <div class="panel-head"><h2>Countries</h2></div>
                <?= da_bars(array_map(function ($r) { return [$r['k'] !== '' ? e($r['k']) : '<span class="muted">Unknown</span>', (int) $r['n'], e(analytics_duration($r['eng'])) . ' avg']; }, $data['countries']), $total, 'No locations recorded.', 15) ?>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>States / regions</h2></div>
                <?= da_bars(array_map(function ($r) { return [e($r['k']) . ' <small class="muted">' . e($r['c']) . '</small>', (int) $r['n'], '']; }, $data['regions']), $total, 'No states or regions yet - they need Cloudflare location headers or a location lookup service (Settings).', 15) ?>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Cities</h2></div>
                <?= da_bars(array_map(function ($r) { return [e($r['k']) . ' <small class="muted">' . e(implode(', ', array_filter([$r['r'], $r['c']]))) . '</small>', (int) $r['n'], '']; }, $data['cities']), $total, 'No cities yet - they need Cloudflare location headers or a location lookup service (Settings).', 15) ?>
            </section>
        </div>
        <p class="small muted mt-2"><?= icon('map-pin') ?> Location source: <?= e(implode(' · ', array_map(function ($k, $n) {
            return (['cloudflare' => 'Cloudflare', 'ipinfo' => 'ipinfo.io', 'ipapi' => 'ipapi.co', 'timezone' => 'browser time zone (country only)', 'unknown' => 'unknown', 'failed' => 'lookup failed'][$k] ?? $k) . ' ' . number_format((int) $n);
        }, array_keys($gs), $gs))) ?>. Locations are approximate (network location at the time of the visit); IP addresses are never stored.</p>

        <div class="da-grid-3 mt-3">
            <section class="panel">
                <div class="panel-head"><h2>Devices</h2></div>
                <?= da_bars(array_map(function ($r) { return [icon($r['k'] === 'mobile' ? 'smartphone' : ($r['k'] === 'tablet' ? 'layers' : 'monitor')) . e(humanize((string) $r['k'])), (int) $r['n'], e(analytics_duration($r['eng'])) . ' avg']; }, $data['device']), $total) ?>
                <h3 class="da-subhead">Screen sizes</h3>
                <?= da_bars(array_map(function ($r) { return [$r['k'] !== '' ? e($r['k']) : '<span class="muted">Not measured</span>', (int) $r['n'], '']; }, $data['screen']), $total) ?>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Browsers</h2></div>
                <?= da_bars(array_map(function ($r) { return [e((string) $r['k']), (int) $r['n'], '']; }, $data['browser']), $total) ?>
                <details class="da-details"><summary>Browser versions</summary>
                    <ul class="da-list"><?php foreach ($data['browser_versions'] as $r): ?><li><span><?= e(trim($r['k'])) ?></span><strong><?= number_format((int) $r['n']) ?></strong></li><?php endforeach; ?></ul>
                </details>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Operating systems</h2></div>
                <?= da_bars(array_map(function ($r) { return [e((string) $r['k']), (int) $r['n'], '']; }, $data['os']), $total) ?>
            </section>
        </div>

        <div class="da-grid-2 mt-3">
            <section class="panel">
                <div class="panel-head"><h2>Languages</h2></div>
                <?= da_bars(array_map(function ($r) { return [e(analytics_language_name($r['k'])), (int) $r['n'], '']; }, $data['language']), $total) ?>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>New and returning visitors</h2></div>
                <?php $w = $data['who']; ?>
                <?= da_bars([
                    ['New visitors (allowed cookies)', (int) $w['new_n'], $w['new_eng'] !== null ? e(analytics_duration($w['new_eng'])) . ' avg' : ''],
                    ['Returning visitors', (int) $w['ret_n'], $w['ret_eng'] !== null ? e(analytics_duration($w['ret_eng'])) . ' avg' : ''],
                    ['Not recognisable (no consent)', (int) $w['unknown_n'], ''],
                ], $total) ?>
                <p class="small muted mt-2 mb-0">Return visits are recognised only for visitors who chose “Allow analytics”. Signed-in members: <?= e(da_n((int) $w['members'], 'visit')) ?> (<?= e(insights_pct((float) $w['members'], $total)) ?>).</p>
            </section>
        </div>

        <section class="panel mt-3">
            <div class="panel-head"><h2>When people visit</h2><span class="muted small">Visits by weekday and hour (<?= e(TIMEZONE) ?>)</span></div>
            <?php
            $heat = $data['heat'];
            $maxHeat = 1;
            $busiest = [0, 0, 0];
            foreach ($heat as $d => $hours) {
                foreach ($hours as $h => $n) {
                    $maxHeat = max($maxHeat, $n);
                    if ($n > $busiest[2]) {
                        $busiest = [$d, $h, $n];
                    }
                }
            }
            $dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            ?>
            <div class="table-wrap da-heat-wrap" tabindex="0" role="region" aria-label="Visits by weekday and hour (scrollable)">
                <table class="da-heat">
                    <caption class="sr-only">Number of visits for each weekday and hour</caption>
                    <thead><tr><th scope="col"><span class="sr-only">Day</span></th><?php for ($h = 0; $h < 24; $h++): ?><th scope="col"><?= $h % 3 === 0 ? str_pad((string) $h, 2, '0', STR_PAD_LEFT) : '<span class="sr-only">' . $h . '</span>' ?></th><?php endfor; ?></tr></thead>
                    <tbody>
                    <?php foreach ($dayNames as $d => $dn): ?>
                        <tr><th scope="row"><?= $dn ?></th>
                            <?php for ($h = 0; $h < 24; $h++): $n = (int) ($heat[$d][$h] ?? 0); $a = $n ? round(0.12 + 0.88 * $n / $maxHeat, 2) : 0; ?>
                                <td title="<?= e($dn . ' ' . str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00 – ' . $n . ' visit' . ($n === 1 ? '' : 's')) ?>"<?= $n ? ' style="--a:' . $a . '"' : '' ?> class="<?= $n ? 'has-v' . ($a > 0.6 ? ' is-dark' : '') : '' ?>"><span class="sr-only"><?= $n ?></span></td>
                            <?php endfor; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="da-heat-legend small muted"><span>Fewer</span><span class="da-heat-scale" aria-hidden="true"></span><span>More visits</span>
                <?php if ($busiest[2]): ?><span class="da-heat-peak">Busiest: <?= e($dayNames[$busiest[0]] . ' ' . str_pad((string) $busiest[1], 2, '0', STR_PAD_LEFT) . ':00–' . str_pad((string) (($busiest[1] + 1) % 24), 2, '0', STR_PAD_LEFT) . ':00') ?> (<?= e(da_n($busiest[2], 'visit')) ?>)</span><?php endif; ?></div>
        </section>
    <?php endif; ?>

<?php
// =====================================================================
// ACQUISITION
// =====================================================================
elseif ($tab === 'acquisition'):
    $total = $data['total'];
    $aggCells = function (array $r) use ($total): string {
        return '<td class="text-right">' . number_format((int) $r['n']) . ' <small class="muted">' . e(insights_pct((float) $r['n'], $total, 0)) . '</small></td>'
            . '<td class="text-right hide-sm">' . e(analytics_duration($r['eng'])) . '</td>'
            . '<td class="text-right hide-sm">' . e(insights_pct((float) $r['bounce'] * 100, 100, 0)) . '</td>'
            . '<td class="text-right hide-sm">' . number_format((float) $r['pps'], 1) . '</td>'
            . '<td class="text-right">' . number_format((int) $r['conv']) . ' <small class="muted">' . e(insights_pct((float) $r['conv'], (float) $r['n'], 1)) . '</small></td>';
    };
    $head = '<th class="text-right">Visits</th><th class="text-right hide-sm">Avg. engaged</th><th class="text-right hide-sm">Bounce</th><th class="text-right hide-sm">Pages / visit</th><th class="text-right">Conversions</th>';
    if (!$total): ?>
        <?= da_empty('No visits in this period', 'Channels (direct, organic search, social, referral, email, paid, campaign), referring websites and UTM campaign links will appear here with visits, engaged time and conversions.') ?>
    <?php else: ?>
        <section class="panel">
            <div class="panel-head"><h2>Channels</h2></div>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Table (scroll sideways on small screens)"><table class="table">
                <thead><tr><th>Channel</th><?= $head ?></tr></thead>
                <tbody><?php foreach ($data['channels'] as $r): ?><tr><td><a href="<?= e('?' . http_build_query(array_merge(['tab' => 'acquisition'], $keep, ['channel' => $r['k']]))) ?>"><?= e(analytics_channel_label((string) $r['k'])) ?></a></td><?= $aggCells($r) ?></tr><?php endforeach; ?></tbody>
            </table></div>
            <p class="small muted mt-2 mb-0">Channel rules: campaign tags first (paid: cpc/ads/gclid · email: email/newsletter · social: social media sources · other tags: campaign), otherwise the referring website (search engines = organic search, social networks = social, webmail = email, other websites = referral) or direct.</p>
        </section>
        <div class="da-grid-2 mt-3">
            <section class="panel">
                <div class="panel-head"><h2>Referring websites</h2></div>
                <?php if ($data['referrers']): ?>
                    <div class="table-wrap" tabindex="0" role="region" aria-label="Table (scroll sideways on small screens)"><table class="table table-compact">
                        <thead><tr><th>Website</th><?= $head ?></tr></thead>
                        <tbody><?php foreach ($data['referrers'] as $r): ?><tr><td><?= e($r['k']) ?></td><?= $aggCells($r) ?></tr><?php endforeach; ?></tbody>
                    </table></div>
                <?php else: ?><p class="muted mb-0">No visits from other websites in this period.</p><?php endif; ?>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Campaign links (UTM)</h2><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('marketing.php')) ?>"><?= icon('megaphone') ?>Build a link</a></div>
                <?php if ($data['campaigns']): ?>
                    <div class="table-wrap" tabindex="0" role="region" aria-label="Table (scroll sideways on small screens)"><table class="table table-compact">
                        <thead><tr><th>Source / medium / campaign</th><?= $head ?></tr></thead>
                        <tbody><?php foreach ($data['campaigns'] as $r): ?><tr><td><strong><?= e($r['src'] ?: '–') ?></strong> / <?= e($r['med'] ?: '–') ?><br><small class="muted"><?= e($r['camp'] ?: 'no campaign name') ?></small></td><?= $aggCells($r) ?></tr><?php endforeach; ?></tbody>
                    </table></div>
                <?php else: ?><p class="muted mb-0">No visits through tagged campaign links in this period. Create tagged links in <a href="<?= e(admin_url('marketing.php')) ?>">Marketing</a> to compare posts, newsletters and campaigns.</p><?php endif; ?>
            </section>
        </div>
        <section class="panel mt-3">
            <div class="panel-head"><h2>Where each channel lands</h2></div>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Table (scroll sideways on small screens)"><table class="table table-compact">
                <thead><tr><th>Channel</th><th>Landing page</th><th class="text-right">Visits</th></tr></thead>
                <tbody><?php foreach ($data['landing_by_channel'] as $r): ?><tr><td><?= e(analytics_channel_label((string) $r['ch'])) ?></td><td><?= da_page_cell((string) $r['lp'], $titles) ?></td><td class="text-right"><?= number_format((int) $r['n']) ?></td></tr><?php endforeach; ?></tbody>
            </table></div>
        </section>
    <?php endif; ?>

<?php
// =====================================================================
// BEHAVIOUR
// =====================================================================
elseif ($tab === 'behaviour'):
    if (!$data['pages']): ?>
        <?= da_empty('No page views in this period', 'Pages with views, unique visitors, engaged time, scroll depth, exits and bounce rate; landing and exit pages; clicks, downloads, outbound links, site searches and the slowest pages will appear here.') ?>
    <?php else: ?>
        <section class="panel">
            <div class="panel-head"><h2>Pages</h2><?php if ($canExport): ?><a class="btn btn-ghost btn-sm" href="<?= e($tabUrl('export')) ?>"><?= icon('download') ?>Export</a><?php endif; ?></div>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Table (scroll sideways on small screens)"><table class="table da-table">
                <thead><tr><th>Page</th><th class="text-right">Views</th><th class="text-right hide-sm">Visitors</th><th class="text-right">Avg. engaged</th><th class="text-right hide-sm">Avg. scroll</th><th class="text-right hide-sm">Entrances</th><th class="text-right hide-sm">Bounce</th><th class="text-right">Exits</th><th class="text-right hide-sm">Load</th></tr></thead>
                <tbody>
                <?php foreach ($data['pages'] as $p):
                    $en = $data['entr'][$p['path']] ?? null;
                    $ex = (int) ($data['exits'][$p['path']] ?? 0); ?>
                    <tr><td><?= da_page_cell((string) $p['path'], $titles) ?></td>
                        <td class="text-right"><?= number_format((int) $p['views']) ?></td>
                        <td class="text-right hide-sm"><?= number_format((int) $p['visitors']) ?></td>
                        <td class="text-right nowrap"><?= $p['eng'] !== null ? e(analytics_duration($p['eng'])) : '–' ?></td>
                        <td class="text-right hide-sm"><?= $p['scroll'] !== null ? (int) round((float) $p['scroll']) . '%' : '–' ?></td>
                        <td class="text-right hide-sm"><?= $en ? number_format((int) $en['n']) : '0' ?></td>
                        <td class="text-right hide-sm"><?= $en ? e(insights_pct((float) $en['b'], (float) $en['n'], 0)) : '–' ?></td>
                        <td class="text-right"><?= number_format($ex) ?> <small class="muted"><?= e(insights_pct($ex, (float) $p['views'], 0)) ?></small></td>
                        <td class="text-right hide-sm nowrap"><?= $p['load_ms'] !== null ? number_format((float) $p['load_ms'] / 1000, 1) . ' s' : '–' ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <p class="small muted mt-2 mb-0">Engaged time and scroll depth come from the page script; “–” means not measured (for example JavaScript blocked). Exits = visits that ended on the page (and its share of the page's views).</p>
        </section>

        <div class="da-grid-2 mt-3">
            <section class="panel">
                <div class="panel-head"><h2>Landing pages</h2></div>
                <div class="table-wrap" tabindex="0" role="region" aria-label="Table (scroll sideways on small screens)"><table class="table table-compact">
                    <thead><tr><th>Page</th><th class="text-right">Visits</th><th class="text-right">Bounce</th><th class="text-right hide-sm">Engaged</th><th class="text-right">Conv.</th></tr></thead>
                    <tbody><?php foreach ($data['landing'] as $r): ?><tr><td><?= da_page_cell((string) $r['k'], $titles) ?></td><td class="text-right"><?= number_format((int) $r['n']) ?></td><td class="text-right"><?= e(insights_pct((float) $r['bounce'] * 100, 100, 0)) ?></td><td class="text-right hide-sm"><?= e(analytics_duration($r['eng'])) ?></td><td class="text-right"><?= number_format((int) $r['conv']) ?></td></tr><?php endforeach; ?></tbody>
                </table></div>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Exit pages</h2></div>
                <div class="table-wrap" tabindex="0" role="region" aria-label="Table (scroll sideways on small screens)"><table class="table table-compact">
                    <thead><tr><th>Page</th><th class="text-right">Exits</th><th class="text-right">Exit rate</th></tr></thead>
                    <tbody><?php foreach ($data['exit_rows'] as $r): ?><tr><td><?= da_page_cell($r['k'], $titles) ?></td><td class="text-right"><?= number_format($r['n']) ?></td><td class="text-right"><?= e(insights_pct($r['n'], (float) $r['views'], 0)) ?></td></tr><?php endforeach; ?></tbody>
                </table></div>
            </section>
        </div>

        <div class="da-grid-2 mt-3">
            <section class="panel">
                <div class="panel-head"><h2>Events</h2></div>
                <?php if ($data['event_types']): ?>
                    <?= da_bars(array_map(function ($r) { return [e(analytics_event_label((string) $r['k'])), (int) $r['n'], e(da_n((int) $r['sessions'], 'visit'))]; }, $data['event_types']), (float) array_sum(array_column($data['event_types'], 'n')), '', 20) ?>
                    <?php $sc = $data['scroll']; $pvTotal = max(1, $data['views_total']); ?>
                    <h3 class="da-subhead">Scroll depth reached (share of page views)</h3>
                    <ul class="da-scroll-steps"><?php foreach (['25', '50', '75', '100'] as $m): ?><li><strong><?= e(insights_pct((float) ($sc[$m] ?? 0), $pvTotal, 0)) ?></strong><small><?= $m ?>%</small></li><?php endforeach; ?></ul>
                <?php else: ?><p class="muted mb-0">No clicks or other events recorded in this period.</p><?php endif; ?>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Top clicks and actions</h2></div>
                <?php if ($data['event_labels']): ?>
                    <div class="table-wrap" tabindex="0" role="region" aria-label="Table (scroll sideways on small screens)"><table class="table table-compact">
                        <thead><tr><th>Action</th><th class="text-right">Count</th><th class="text-right hide-sm">Visits</th></tr></thead>
                        <tbody><?php foreach ($data['event_labels'] as $r): ?><tr><td><small class="muted"><?= e(analytics_event_label((string) $r['t'])) ?></small><br><?= e($r['t'] === 'conversion' ? (insights_conversion_labels()[$r['l']] ?? humanize((string) $r['l'])) : ($r['l'] !== '' ? $r['l'] : '(no label)')) ?></td><td class="text-right"><?= number_format((int) $r['n']) ?></td><td class="text-right hide-sm"><?= number_format((int) $r['sessions']) ?></td></tr><?php endforeach; ?></tbody>
                    </table></div>
                <?php else: ?><p class="muted mb-0">Clicks on Donate, Apply and other buttons, phone / email / WhatsApp links, shares and form submissions will be listed here.</p><?php endif; ?>
            </section>
        </div>

        <div class="da-grid-3 mt-3">
            <section class="panel">
                <div class="panel-head"><h2>Downloads</h2></div>
                <?php if ($data['downloads']): ?><ul class="da-list"><?php foreach ($data['downloads'] as $r): ?><li><span><?= e($r['l'] ?: basename((string) $r['k'])) ?><br><small class="muted da-path"><?= e($r['k']) ?></small></span><strong><?= number_format((int) $r['n']) ?></strong></li><?php endforeach; ?></ul>
                <?php else: ?><p class="muted mb-0">No downloads (PDFs and other documents) in this period.</p><?php endif; ?>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Outbound links</h2></div>
                <?php if ($data['outbound']): ?><ul class="da-list"><?php foreach ($data['outbound'] as $r): ?><li><span><?= e($r['k']) ?></span><strong><?= number_format((int) $r['n']) ?></strong></li><?php endforeach; ?></ul>
                <?php else: ?><p class="muted mb-0">No clicks to other websites in this period.</p><?php endif; ?>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Site searches</h2></div>
                <?php if ($data['searches']): ?><ul class="da-list"><?php foreach ($data['searches'] as $r): ?><li><span>“<?= e($r['k']) ?>”<br><small class="muted"><?= e($r['p']) ?></small></span><strong><?= number_format((int) $r['n']) ?></strong></li><?php endforeach; ?></ul>
                <?php else: ?><p class="muted mb-0">Words typed into the website's search boxes (FAQ, articles, campaigns, 404 page) will appear here, with email addresses and long numbers (6 or more digits, such as phone numbers) masked.</p><?php endif; ?>
            </section>
        </div>

        <div class="da-grid-2 mt-3">
            <section class="panel">
                <div class="panel-head"><h2>Slowest pages</h2><span class="muted small">Average load time</span></div>
                <?php if ($data['slow']): ?>
                    <div class="table-wrap" tabindex="0" role="region" aria-label="Table (scroll sideways on small screens)"><table class="table table-compact">
                        <thead><tr><th>Page</th><th class="text-right">Average</th><th class="text-right hide-sm">Slowest</th><th class="text-right">Samples</th></tr></thead>
                        <tbody><?php foreach ($data['slow'] as $r): ?><tr><td><?= da_page_cell((string) $r['path'], $titles) ?></td><td class="text-right nowrap"><?= number_format((float) $r['avg_ms'] / 1000, 2) ?> s</td><td class="text-right hide-sm nowrap"><?= number_format((float) $r['max_ms'] / 1000, 2) ?> s</td><td class="text-right"><?= number_format((int) $r['samples']) ?></td></tr><?php endforeach; ?></tbody>
                    </table></div>
                <?php else: ?><p class="muted mb-0">Load times (from the browser's Navigation Timing) appear once pages have at least two measurements.</p><?php endif; ?>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Problems seen by visitors</h2></div>
                <?php if ($data['errors']): ?>
                    <ul class="da-list"><?php foreach ($data['errors'] as $r): ?><li><span><code><?= e($r['k']) ?></code><br><small class="muted"><?= e($r['t']) ?> · last <?= e(format_date($r['last_at'], 'd M, h:i A')) ?></small></span><strong><?= number_format((int) $r['n']) ?></strong></li><?php endforeach; ?></ul>
                <?php else: ?><p class="muted mb-0"><?= icon('check-circle') ?> No JavaScript errors reported in this period.</p><?php endif; ?>
                <?php $rage = array_values(array_filter($data['event_types'], function ($r) { return $r['k'] === 'rage_click'; })); ?>
                <p class="small muted mt-2 mb-0">Rage clicks (3+ rapid clicks in one spot, often a sign that something did not respond): <?= number_format((int) ($rage[0]['n'] ?? 0)) ?>.</p>
            </section>
        </div>
    <?php endif; ?>

<?php
// =====================================================================
// FUNNELS
// =====================================================================
elseif ($tab === 'funnels'):
    $funnel = function (array $steps): string {
        $first = max(1, (int) $steps[0][1]);
        $html = '<ol class="da-funnel">';
        $prev = null;
        foreach ($steps as $i => [$label, $n, $note]) {
            $w = $n ? max(2, round($n / $first * 100, 1)) : 0;
            $html .= '<li><div class="da-funnel-head"><span class="da-funnel-step">' . ($i + 1) . '</span><strong>' . e($label) . '</strong><span class="da-funnel-n">' . number_format((int) $n) . '</span></div>'
                . '<div class="da-funnel-track" aria-hidden="true"><span style="width:' . $w . '%"></span></div>'
                . '<p class="small muted">' . e($note) . ($prev !== null ? ' · <strong>' . e(insights_pct((float) $n, (float) $prev, 1)) . '</strong> of the previous step' . ($prev > $n ? ' (' . number_format($prev - $n) . ' dropped off)' : '') : '') . '</p></li>';
            $prev = (int) $n;
        }
        return $html . '</ol>';
    };
    if (!$data['sessions']): ?>
        <?= da_empty('No visits in this period', 'The donation funnel (visit → donate page → donation started → verified) and the application funnels for volunteers, volunteer teachers, jobs and internships will appear here.') ?>
    <?php else: ?>
        <div class="da-grid-2">
            <section class="panel">
                <div class="panel-head"><h2>Donation funnel</h2></div>
                <?= $funnel($data['donation']) ?>
                <?php $da = $data['donation_all']; ?>
                <p class="small muted mb-0 da-text">Whole website in this period (including visitors who are not measured, e.g. Do Not Track): <?= number_format((int) ($da['started'] ?? 0)) ?> donations started, <?= number_format((int) ($da['verified'] ?? 0)) ?> verified<?= admin_can('donations.view') ? ' (' . e(money($data['donation_value'])) . ' verified donations created in the period)' : '' ?>. See <a href="<?= e(admin_url('reports.php')) ?>">Reports</a> for donation details.</p>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Applications</h2></div>
                <div class="table-wrap" tabindex="0" role="region" aria-label="Table (scroll sideways on small screens)"><table class="table">
                    <thead><tr><th>Application</th><th class="text-right">Visits to the page</th><th class="text-right">Applied</th><th class="text-right">Rate</th></tr></thead>
                    <tbody><?php foreach ($data['apply'] as $a): ?>
                        <tr><td><strong><?= e($a['label']) ?></strong><br><small class="muted da-path"><?= e(implode(', ', $a['paths']) ?: 'page not published') ?></small><br><small class="muted"><?= e(da_n($a['all'], 'application')) ?> site-wide</small></td>
                            <td class="text-right"><?= number_format($a['views']) ?></td><td class="text-right"><?= number_format($a['apps']) ?></td><td class="text-right"><?= e(insights_pct($a['apps'], $a['views'], 1)) ?></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
                <?php if ($data['other']): ?>
                    <h3 class="da-subhead">All conversions in measured visits</h3>
                    <ul class="da-list"><?php foreach ($data['other'] as $r): ?><li><span><?= e(insights_conversion_labels()[$r['k']] ?? humanize((string) $r['k'])) ?></span><strong><?= e(da_n((int) $r['n'], 'visit')) ?></strong></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </section>
        </div>
        <section class="panel mt-3">
            <div class="panel-head"><h2>Donation funnel by channel</h2></div>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Table (scroll sideways on small screens)"><table class="table table-compact">
                <thead><tr><th>Channel</th><th class="text-right">Visits</th><th class="text-right">Donate page</th><th class="text-right">Started</th><th class="text-right">Verified</th><th class="text-right hide-sm">Visit → verified</th></tr></thead>
                <tbody><?php foreach ($data['by_channel'] as $r): ?><tr><td><?= e(analytics_channel_label((string) $r['ch'])) ?></td><td class="text-right"><?= number_format((int) $r['n']) ?></td><td class="text-right"><?= number_format((int) $r['donate_page']) ?></td><td class="text-right"><?= number_format((int) $r['started']) ?></td><td class="text-right"><?= number_format((int) $r['verified']) ?></td><td class="text-right hide-sm"><?= e(insights_pct((float) $r['verified'], (float) $r['n'], 1)) ?></td></tr><?php endforeach; ?></tbody>
            </table></div>
            <p class="small muted mt-2 mb-0">A visit counts once per step. Conversions are linked to the visit in which the form was sent; a donation verified later by the payment gateway's webhook is credited to that visit.</p>
        </section>
    <?php endif; ?>

<?php
// =====================================================================
// VISITORS & JOURNEYS
// =====================================================================
elseif ($tab === 'visitors'):
    if ($visit):
        $dur = strtotime($visit['last_seen_at']) - strtotime($visit['started_at']); ?>
        <p><a class="btn btn-ghost btn-sm" href="<?= e($tabUrl('visitors')) ?>"><?= icon('chevron-left') ?>All visits</a></p>
        <div class="admin-grid-main">
            <section class="panel">
                <div class="panel-head"><h2>Journey of visit <?= e(analytics_visit_code($visit['sid'])) ?></h2><span class="muted small"><?= e(format_date($visit['started_at'], 'd M Y, h:i A')) ?></span></div>
                <?php if (!$data['timeline']): ?>
                    <p class="muted mb-0">No page views remain for this visit (they may have been removed by the retention clean-up).</p>
                <?php else: ?>
                    <ol class="da-timeline">
                        <?php foreach ($data['timeline'] as $item): $r = $item['row']; ?>
                            <?php if ($item['kind'] === 'view'): ?>
                                <li class="is-view"><span class="da-tl-time"><?= e(date('h:i:s A', $item['t'])) ?></span>
                                    <div><span class="da-tl-icon" aria-hidden="true"><?= icon('eye') ?></span><?= da_page_cell((string) $r['path'], $titles) ?>
                                        <p class="small muted mb-0"><?= $r['engaged_seconds'] !== null ? e(analytics_duration($r['engaged_seconds'])) . ' engaged' : 'engaged time not measured' ?><?= $r['max_scroll'] !== null ? ' · scrolled ' . (int) $r['max_scroll'] . '%' : '' ?><?= $r['load_ms'] !== null ? ' · loaded in ' . number_format((int) $r['load_ms'] / 1000, 1) . ' s' : '' ?><?= $r['left_at'] ? ' · left ' . e(format_date($r['left_at'], 'h:i:s A')) : '' ?></p></div></li>
                            <?php else: ?>
                                <li class="is-event type-<?= e($r['type']) ?>"><span class="da-tl-time"><?= e(date('h:i:s A', $item['t'])) ?></span>
                                    <div><span class="da-tl-icon" aria-hidden="true"><?= icon($r['type'] === 'conversion' ? 'target' : ($r['type'] === 'js_error' || $r['type'] === 'rage_click' ? 'alert-triangle' : ($r['type'] === 'download' ? 'download' : ($r['type'] === 'scroll_depth' ? 'chevron-down' : 'zap')))) ?></span>
                                        <strong><?= e(analytics_event_label((string) $r['type'])) ?></strong>
                                        <?php if ($r['type'] === 'conversion'): ?>
                                            <span class="badge badge-green"><?= e(insights_conversion_labels()[$r['label']] ?? humanize((string) $r['label'])) ?></span>
                                        <?php elseif ($r['label'] !== null && $r['label'] !== ''): ?>
                                            · <?= e($r['type'] === 'scroll_depth' ? $r['label'] . '%' : $r['label']) ?>
                                        <?php endif; ?>
                                        <?php if ($r['target'] !== null && $r['target'] !== '' && $r['type'] !== 'conversion'): ?><br><small class="muted da-path"><?= e($r['target']) ?></small><?php endif; ?></div></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>
            <aside>
                <section class="panel">
                    <div class="panel-head"><h2>Visit details</h2><?= $visit['converted'] ? '<span class="badge badge-green">' . e(insights_conversion_labels()[$visit['converted']] ?? humanize((string) $visit['converted'])) . '</span>' : '' ?></div>
                    <dl class="detail-list kv-small da-kv">
                        <dt>Visitor</dt><dd><?php if ($visit['user_id']): ?><?= admin_can('members.view') ? '<a href="' . e(admin_url('user-view.php?id=' . (int) $visit['user_id'])) . '">' . e((string) $visit['member_name']) . '</a>' : e((string) $visit['member_name']) ?> <span class="badge badge-blue">member</span><?php else: ?>Anonymous<?php endif; ?>
                            <br><small class="muted"><?= $visit['visitor_id'] === null ? 'Return visits not recognisable (no analytics consent)' : ((int) $visit['is_returning'] ? 'Returning visitor (allowed analytics cookies)' : 'First recorded visit (allowed analytics cookies)') ?></small></dd>
                        <dt>Duration</dt><dd><?= e(analytics_duration($dur)) ?> · <?= e(analytics_duration($visit['engaged_seconds'])) ?> engaged</dd>
                        <dt>Pages</dt><dd><?= (int) $visit['pageviews'] ?> · <?= (int) $visit['events'] ?> events<?= (int) $visit['bounce'] ? ' · bounced' : '' ?></dd>
                        <dt>Source</dt><dd><?= e(analytics_channel_label((string) $visit['channel'])) ?><?= $visit['referrer_host'] ? ' · ' . e($visit['referrer_host']) : '' ?><?php if ($visit['utm_source'] || $visit['utm_campaign']): ?><br><small class="muted">utm: <?= e(implode(' / ', array_filter([$visit['utm_source'], $visit['utm_medium'], $visit['utm_campaign']]))) ?></small><?php endif; ?></dd>
                        <dt>Location</dt><dd><?= da_place($visit, true) ?></dd>
                        <dt>Device</dt><dd><?= e(humanize((string) $visit['device'])) ?> · <?= e(trim($visit['browser'] . ' ' . $visit['browser_version'])) ?> · <?= e((string) $visit['os']) ?><?= $visit['screen'] ? '<br><small class="muted">Screen ' . e($visit['screen']) . '</small>' : '' ?></dd>
                        <dt>Language</dt><dd><?= e(analytics_language_name($visit['language'])) ?><?= $visit['timezone'] ? '<br><small class="muted">' . e($visit['timezone']) . '</small>' : '' ?></dd>
                        <dt>Landed / left</dt><dd class="da-path"><?= e($visit['landing_path']) ?> → <?= e((string) $visit['exit_path']) ?></dd>
                    </dl>
                    <?php if ($data['donations'] && admin_can('donations.view')): ?>
                        <h3 class="da-subhead">Donations from this visit</h3>
                        <ul class="da-list"><?php foreach ($data['donations'] as $d): ?><li><span><a href="<?= e(admin_url('donation-view.php?id=' . (int) $d['id'])) ?>"><?= e($d['donation_ref']) ?></a> <?= status_badge($d['status']) ?></span><strong><?= e(money($d['amount'])) ?></strong></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </section>
                <?php if ($data['related']): ?>
                    <section class="panel">
                        <div class="panel-head"><h2>Other visits by this <?= $visit['user_id'] ? 'member' : 'visitor' ?></h2></div>
                        <ul class="da-list"><?php foreach ($data['related'] as $r): ?><li><span><a href="<?= e($tabUrl('visitors', ['visit' => $r['sid']])) ?>"><?= e(format_date($r['started_at'], 'd M Y, h:i A')) ?></a><br><small class="muted"><?= e(analytics_channel_label((string) $r['channel'])) ?> · <?= e($r['landing_path']) ?></small></span><strong><?= (int) $r['pageviews'] ?> <small class="muted">pages</small></strong></li><?php endforeach; ?></ul>
                    </section>
                <?php endif; ?>
            </aside>
        </div>
    <?php else: ?>
        <form class="filter-row" method="get" role="search" aria-label="Search visits">
            <input type="hidden" name="tab" value="visitors">
            <?php foreach ($keep as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
            <?php if ((int) da_get('member') > 0): ?><input type="hidden" name="member" value="<?= (int) da_get('member') ?>"><?php endif; ?>
            <label class="sr-only" for="da-q">Search visits</label>
            <input id="da-q" type="search" name="q" value="<?= e(da_get('q')) ?>" placeholder="Visit code, member name or email, landing page, city" maxlength="80">
            <button class="btn btn-primary btn-sm" type="submit"><?= icon('search') ?>Search</button>
            <?php if (da_get('q') !== '' || (int) da_get('member') > 0): ?><a class="btn btn-ghost btn-sm" href="<?= e($tabUrl('visitors')) ?>">Reset</a><?php endif; ?>
        </form>
        <?php if (!empty($data['member_name'])): ?><p class="small"><?= icon('user') ?> Visits of member <strong><?= e($data['member_name']) ?></strong></p><?php endif; ?>
        <?php if (!$data['list']): ?>
            <?= da_empty('No visits found', 'Each visit appears here with an anonymous code, approximate location, device, source, pages and engaged time. Visits of signed-in members show the member\'s name. Open a visit to see its full journey.') ?>
        <?php else: ?>
            <section class="panel">
                <div class="table-meta"><span><?= number_format($data['pg']['total']) ?> visits</span><span>Newest first</span></div>
                <div class="table-wrap" tabindex="0" role="region" aria-label="Table (scroll sideways on small screens)"><table class="table da-table">
                    <thead><tr><th>Visit</th><th class="hide-sm">Location</th><th class="hide-sm">Device</th><th>Source</th><th>Pages</th><th class="text-right">Engaged</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['list'] as $s): ?>
                        <tr>
                            <td class="nowrap"><a href="<?= e($tabUrl('visitors', ['visit' => $s['sid']])) ?>"><strong><?= e(analytics_visit_code($s['sid'])) ?></strong></a><br><small class="muted"><?= e(format_date($s['started_at'], 'd M, h:i A')) ?></small>
                                <?php if ($s['user_id']): ?><br><?= admin_can('members.view') ? '<a class="small" href="' . e(admin_url('user-view.php?id=' . (int) $s['user_id'])) . '">' . e((string) ($s['member_name'] ?? 'Member #' . $s['user_id'])) . '</a>' : '<span class="small">' . e((string) ($s['member_name'] ?? 'Member')) . '</span>' ?><?php elseif ((int) $s['is_returning']): ?><br><span class="badge badge-blue">returning</span><?php endif; ?></td>
                            <td class="hide-sm small"><?= da_place($s) ?></td>
                            <td class="hide-sm small"><?= e(humanize((string) $s['device'])) ?><br><span class="muted"><?= e($s['browser'] . ' · ' . $s['os']) ?></span></td>
                            <td class="small"><?= e(analytics_channel_label((string) $s['channel'])) ?><?= $s['referrer_host'] ? '<br><span class="muted">' . e($s['referrer_host']) . '</span>' : '' ?></td>
                            <td class="small"><span class="da-path"><?= e($s['landing_path']) ?></span><?= (int) $s['pageviews'] > 1 ? '<br><span class="muted">+ ' . ((int) $s['pageviews'] - 1) . ' more · exit ' . e((string) $s['exit_path']) . '</span>' : '' ?>
                                <?= $s['converted'] ? '<br><span class="badge badge-green">' . e(insights_conversion_labels()[$s['converted']] ?? humanize((string) $s['converted'])) . '</span>' : '' ?></td>
                            <td class="text-right nowrap"><?= e(analytics_duration($s['engaged_seconds'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?= pagination_links($data['pg']) ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>

<?php
// =====================================================================
// EXPORT
// =====================================================================
elseif ($tab === 'export'): ?>
    <section class="panel">
        <div class="panel-head"><h2>Download as CSV</h2></div>
        <?php if (!$canExport): ?>
            <p class="muted mb-0">Your role does not include CSV exports (permission “exports.run”).</p>
        <?php else: ?>
            <p>Each file covers <strong><?= e(format_date($range['from'])) ?> – <?= e(format_date($range['to'])) ?></strong><?= $segActive ? ' with the filters ' . e(implode(', ', $segActive)) : '' ?>. Files open in Excel or Google Sheets; at most 100,000 rows each.</p>
            <div class="quick-actions">
                <a class="btn btn-primary btn-sm" href="<?= e($tabUrl('export', ['export' => 'sessions'])) ?>"><?= icon('download') ?>Visits (sessions)</a>
                <a class="btn btn-ghost btn-sm" href="<?= e($tabUrl('export', ['export' => 'pageviews'])) ?>"><?= icon('download') ?>Page views</a>
                <a class="btn btn-ghost btn-sm" href="<?= e($tabUrl('export', ['export' => 'events'])) ?>"><?= icon('download') ?>Events</a>
            </div>
            <p class="small muted mt-2 mb-0">Exports contain no IP addresses, names or email addresses: visits are identified by their anonymous code, signed-in members only by their user number. Every download is recorded in the audit log. Keep downloaded files safe and delete them when no longer needed.</p>
        <?php endif; ?>
    </section>

<?php
// =====================================================================
// SETTINGS
// =====================================================================
elseif ($tab === 'settings'):
    $st = $data['stats'];
    $provider = analytics_geo_provider(); ?>
    <div class="admin-grid-main">
        <section class="panel">
            <div class="panel-head"><h2>Deep analytics settings</h2></div>
            <?php if (!$canSettings): ?><p class="alert alert-info"><?= icon('lock') ?><span>You can view these settings; changing them needs the “settings.manage” permission.</span></p><?php endif; ?>
            <form method="post" class="da-settings" novalidate>
                <?= csrf_field() ?><input type="hidden" name="action" value="save">
                <fieldset class="da-fieldset"<?= $canSettings ? '' : ' disabled' ?>>
                    <legend class="form-section-title">Recording</legend>
                    <label class="checkbox"><input type="checkbox" name="analytics_enhanced" value="1"<?= old('analytics_enhanced', setting('analytics_enhanced', '0')) === '1' ? ' checked' : '' ?>><span><strong>Record visits, engaged time, events and approximate location</strong><br><small class="muted">Requires website statistics to be on (<?= setting('analytics_enabled', '1') === '1' ? 'currently on' : '<strong>currently off</strong>' ?>, Settings › Analytics). Staff, bots and Do-Not-Track / GPC visitors (<?= setting('analytics_respect_dnt', '1') === '1' ? 'respected' : '<strong>currently not respected</strong>' ?>) are never recorded.</small></span></label>

                </fieldset>
                <fieldset class="da-fieldset"<?= $canSettings ? '' : ' disabled' ?>>
                    <legend class="form-section-title">Recognising return visits</legend>
                    <?php $mode = old('analytics_persistent_id', analytics_persistent_mode()); ?>
                    <label class="checkbox"><input type="radio" name="analytics_persistent_id" value="consent"<?= $mode === 'consent' ? ' checked' : '' ?>><span><strong>Only with consent</strong> - the cookie notice asks visitors; “Allow analytics” sets a first-party cookie (pwf_vid, 13 months) so return visits can be recognised.</span></label>
                    <label class="checkbox"><input type="radio" name="analytics_persistent_id" value="off"<?= $mode === 'off' ? ' checked' : '' ?>><span><strong>Off</strong> - no visitor cookie, no cookie notice for our own statistics (returning visitors cannot be recognised).</span></label>
                    <?= field_error('analytics_persistent_id') ?>

                </fieldset>
                <fieldset class="da-fieldset"<?= $canSettings ? '' : ' disabled' ?>>
                    <legend class="form-section-title">Approximate location</legend>
                    <?php $prov = old('geoip_provider', $provider); ?>
                    <?php foreach (analytics_geo_providers() as $k => $label): ?>
                        <label class="checkbox"><input type="radio" name="geoip_provider" value="<?= e($k) ?>"<?= $prov === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
                    <?php endforeach; ?>
                    <?= field_error('geoip_provider') ?>
                    <div class="form-group mt-2">
                        <label for="geoip_token">Location service token / key <small class="muted">(ipinfo.io token or ipapi.co key)</small></label>
                        <input id="geoip_token" name="geoip_token" type="password" autocomplete="off" value="" maxlength="120" aria-describedby="geoip_token_help">
                        <span class="help" id="geoip_token_help"><?= e(secret_hint('GEOIP_TOKEN', 'geoip_token')) ?>. Leave blank to keep the saved value. Stored encrypted; never shown again.</span>
                        <?= field_error('geoip_token') ?>
                        <?php if (secret_source('GEOIP_TOKEN', 'geoip_token') === 'admin'): ?><label class="checkbox mt-1"><input type="checkbox" name="geoip_token_remove" value="1"><span>Remove the saved token</span></label><?php endif; ?>
                    </div>
                    <p class="small muted">Lookups happen on the server, once per visit, with a 1.5-second limit while answering the visitor's first statistics report (pages never wait for it). Results are cached for 24 hours under a coded value that changes daily; IP addresses are never stored. ipinfo.io and ipapi.co offer free plans with monthly limits - check their terms. Without a service, the country comes from the browser's time zone.</p>

                </fieldset>
                <fieldset class="da-fieldset"<?= $canSettings ? '' : ' disabled' ?>>
                    <legend class="form-section-title">Retention</legend>
                    <div class="form-group">
                        <label for="analytics_retention_days">Keep visitor statistics for (days)</label>
                        <input id="analytics_retention_days" name="analytics_retention_days" type="number" min="30" max="1095" required value="<?= e(old('analytics_retention_days', (string) analytics_retention_days())) ?>">
                        <span class="help da-text">Same setting as in <a href="<?= e(admin_url('backups.php')) ?>">Backup &amp; Data Retention</a>; applies to page views, visits and events. Older data is deleted automatically.</span>
                        <?= field_error('analytics_retention_days') ?>
                    </div>
                </fieldset>
                <?php if ($canSettings): ?><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save settings</button><?php endif; ?>
            </form>
        </section>
        <aside>
            <section class="panel">
                <div class="panel-head"><h2>Status</h2></div>
                <dl class="detail-list kv-small da-kv">
                    <dt>Recording</dt><dd><?= $enhanced ? '<span class="badge badge-green">On</span>' : '<span class="badge badge-gray">Off</span>' ?></dd>
                    <dt>Visits stored</dt><dd><?= number_format((int) $st['sessions']) ?><?= $st['oldest'] ? ' <small class="muted">since ' . e(format_date($st['oldest'])) . '</small>' : '' ?></dd>
                    <dt>Events stored</dt><dd><?= number_format((int) $st['events']) ?></dd>
                    <dt>Location cache</dt><dd><?= e(da_n((int) $st['geo'], 'row')) ?> (24 h)</dd>
                    <dt>Location source</dt><dd><?= e(analytics_geo_providers()[$provider]) ?></dd>
                    <?php if (setting('geoip_last_ok') !== ''): ?><dt>Last lookup OK</dt><dd><?= e(setting('geoip_last_ok')) ?></dd><?php endif; ?>
                    <?php if (setting('geoip_last_error') !== ''): ?><dt>Last lookup error</dt><dd class="small"><?= e(setting('geoip_last_error')) ?></dd><?php endif; ?>
                    <?php if ((int) setting('geoip_backoff_until', '0') > time()): ?><dt>Lookups paused</dt><dd>until <?= e(date('h:i A', (int) setting('geoip_backoff_until'))) ?></dd><?php endif; ?>
                </dl>
                <?php if ($canSettings): ?>
                    <div class="da-actions">
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_geo"><button class="btn btn-ghost btn-sm btn-block" type="submit"<?= in_array($provider, ['ipinfo', 'ipapi'], true) ? '' : ' disabled' ?>><?= icon('map-pin') ?>Test the location service</button></form>
                        <form method="post" data-confirm="Delete visits and events older than <?= analytics_retention_days() ?> days now?"><?= csrf_field() ?><input type="hidden" name="action" value="cleanup"><button class="btn btn-ghost btn-sm btn-block" type="submit"><?= icon('trash') ?>Run clean-up now</button></form>
                    </div>
                    <p class="small muted mt-2 mb-0">The test looks up a public DNS server address (8.8.8.8), never your own address. Clean-up also runs automatically.</p>
                <?php endif; ?>
            </section>
            <section class="panel privacy-note">
                <div class="panel-head"><h2><?= icon('shield') ?> Privacy</h2></div>
                <ul class="check-list check-cols-1 da-text">
                    <li><?= icon('check') ?><span>First-party only: no external scripts; data stays on this server.</span></li>
                    <li><?= icon('check') ?><span>No IP addresses stored; location is approximate and looked up once per visit.</span></li>
                    <li><?= icon('check') ?><span>Form contents are never recorded - only that a form was sent.</span></li>
                    <li><?= icon('check') ?><span>Visitor cookie only after “Allow analytics”; withdrawing deletes it and unlinks earlier visits.</span></li>
                    <li><?= icon('check') ?><span>Explained to visitors in the <a href="<?= e(page_url('privacy-policy') . '#website-analytics-details') ?>" target="_blank" rel="noopener">privacy policy</a>.</span></li>
                </ul>
            </section>
        </aside>
    </div>
<?php endif; ?>
<?php
admin_page_end();
