<?php
/**
 * SEO growth admin endpoints (SEO Management tabs Presence, Keywords, AI visibility, PageSpeed, AI & indexing).
 * Non-public API group (the WAF keeps its XSS/SQLi rules relaxed only for admin groups), login + CSRF required.
 * The tables themselves are edited through the CRUD modules seo_presence / seo_keywords / seo_ai_tests.
 *
 *   GET  /seo-ai/presence                       KPI counts + automatic hints per checklist item (never changes a status)
 *   POST /seo-ai/presence/{id}/checked          set "last checked" to now
 *   GET  /seo-ai/keywords/conflicts             keyword cannibalisation report
 *   GET  /seo-ai/ai-tests/stats?months=6        mention / citation rate per engine per month
 *   GET  /seo-ai/ai-tests/queries               the standard test queries      PUT {queries: "one per line"} (empty = defaults)
 *   GET  /seo-ai/bots?days=30                   search engine / AI crawler hits (seo_bot_hits)
 *   GET  /seo-ai/ai-status                      llms.txt state, robots AI policy, IndexNow availability
 *   GET  /seo-ai/pagespeed                      runs history + default URLs      POST /seo-ai/pagespeed/run {url, strategy}
 */
require_once APP_ROOT . '/app/services/seo_presence.php';
require_once APP_ROOT . '/app/services/seo_pagespeed.php';

route('GET', '/seo-ai/presence', function () {
    cms_api_require('seo', 'view');
    api_ok(seo_presence_summary() + [
        'hints' => (object) seo_presence_hints(),
        'categories' => seo_presence_categories(),
        'statuses' => seo_presence_statuses(),
        'site_url' => absolute_url(''),
        'is_local' => seo_presence_is_local_host(),
        'checked_at' => date('Y-m-d H:i:s'),
        'can' => ['edit' => can('seo', 'edit')],
    ]);
});

route('POST', '/seo-ai/presence/{id}/checked', function (array $p) {
    cms_api_require('seo', 'edit');
    $row = db_row('SELECT id, title FROM seo_presence WHERE id = ?', [(int) $p['id']]);
    if (!$row) {
        api_error('This checklist item no longer exists.', 404);
    }
    db_update('seo_presence', ['last_checked_at' => date('Y-m-d H:i:s'), 'updated_by' => user_id()], 'id = ?', [$row['id']]);
    log_activity('update', 'seo', $row['id'], 'Marked “' . $row['title'] . '” as checked today (digital presence)');
    api_ok(['id' => (int) $row['id'], 'last_checked_at' => date('Y-m-d')], '“' . $row['title'] . '” marked as checked today.');
});

route('GET', '/seo-ai/keywords/conflicts', function () {
    cms_api_require('seo', 'view');
    $c = seo_keyword_conflicts(true);
    unset($c['rows']);
    api_ok($c + ['count' => count($c['clusters']) + count($c['urls'])]);
});

route('GET', '/seo-ai/ai-tests/stats', function () {
    cms_api_require('seo', 'view');
    $months = max(3, min(24, (int) ($_GET['months'] ?? 6)));
    $start = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
    $labels = [];
    for ($i = 0; $i < $months; $i++) {
        $labels[] = date('Y-m', strtotime($start . " +$i months"));
    }
    $rows = db_all("SELECT engine, DATE_FORMAT(tested_on, '%Y-%m') ym, COUNT(*) n, SUM(mentioned) m, SUM(cited) c,
                           SUM(accuracy = 'inaccurate') bad FROM seo_ai_tests WHERE tested_on >= ? GROUP BY engine, ym", [$start]);
    $engines = seo_ai_engines();
    $grid = [];
    $tot = ['tests' => 0, 'mentioned' => 0, 'cited' => 0, 'inaccurate' => 0];
    foreach ($rows as $r) {
        $grid[$r['engine']][$r['ym']] = $r;
        $tot['tests'] += (int) $r['n'];
        $tot['mentioned'] += (int) $r['m'];
        $tot['cited'] += (int) $r['c'];
        $tot['inaccurate'] += (int) $r['bad'];
    }
    $series = [];
    $byEngine = [];
    foreach ($engines as $key => $label) {
        if (!isset($grid[$key])) {
            continue;
        }
        $series[] = ['engine' => $key, 'label' => $label, 'data' => array_map(fn ($ym) => isset($grid[$key][$ym]) ? (int) round((int) $grid[$key][$ym]['m'] / max(1, (int) $grid[$key][$ym]['n']) * 100) : null, $labels)];
        $n = array_sum(array_map(fn ($r) => (int) $r['n'], $grid[$key]));
        $m = array_sum(array_map(fn ($r) => (int) $r['m'], $grid[$key]));
        $c = array_sum(array_map(fn ($r) => (int) $r['c'], $grid[$key]));
        $byEngine[] = ['engine' => $key, 'label' => $label, 'tests' => $n, 'mentioned' => $m, 'cited' => $c, 'mention_rate' => $n ? (int) round($m / $n * 100) : 0];
    }
    $last = db_value('SELECT MAX(tested_on) FROM seo_ai_tests');
    api_ok(['months' => $labels, 'series' => $series, 'by_engine' => $byEngine, 'totals' => $tot + [
        'mention_rate' => $tot['tests'] ? (int) round($tot['mentioned'] / $tot['tests'] * 100) : 0,
        'citation_rate' => $tot['tests'] ? (int) round($tot['cited'] / $tot['tests'] * 100) : 0,
        'last_tested_on' => $last ?: null,
    ], 'engines' => $engines]);
});

route('GET', '/seo-ai/ai-tests/queries', function () {
    cms_api_require('seo', 'view');
    $custom = trim((string) setting('seo_ai_test_queries', ''));
    api_ok(['queries' => seo_ai_standard_queries(), 'custom' => $custom !== '', 'defaults' => seo_ai_default_queries(), 'engines' => seo_ai_engines(),
        'can' => ['edit' => can('seo', 'edit')]]);
});

route('PUT', '/seo-ai/ai-tests/queries', function () {
    cms_api_require('seo', 'edit');
    $in = api_input();
    $lines = array_values(array_filter(array_map(fn ($l) => trim((string) preg_replace('/\s+/u', ' ', $l)), preg_split('/\r?\n/', api_str($in['queries'] ?? null)) ?: [])));
    if (count($lines) > 30) {
        api_error('Keep the list to 30 queries or fewer.', 422, ['queries' => 'Keep the list to 30 queries or fewer.']);
    }
    foreach ($lines as $i => $l) {
        if (mb_strlen($l) > 300) {
            api_error('Query ' . ($i + 1) . ' is longer than 300 characters.', 422, ['queries' => 'Query ' . ($i + 1) . ' is longer than 300 characters.']);
        }
        if (cms_seo_has_markup($l)) {
            api_error('Use plain text only (no HTML or scripts).', 422, ['queries' => 'Query ' . ($i + 1) . ' contains HTML or script code.']);
        }
    }
    $isDefault = $lines === [] || $lines === seo_ai_default_queries();
    save_setting('seo_ai_test_queries', $isDefault ? '' : implode("\n", $lines), 'seo');
    log_activity('update', 'seo', 'seo_ai_test_queries', $isDefault ? 'Reset the AI visibility test queries to the defaults' : 'Updated the AI visibility test queries (' . count($lines) . ')');
    api_ok(['queries' => seo_ai_standard_queries(), 'custom' => !$isDefault], $isDefault ? 'Test queries reset to the defaults.' : 'Test queries saved.');
});

/** Display label + purpose of a crawler key as stored by seo-core in seo_bot_hits. */
function seo_ai_bot_info(string $bot, array $policy): array
{
    $k = strtolower(preg_replace('/[^a-z0-9]+/i', '', $bot));
    $known = [
        'googlebot' => ['Googlebot', 'Google', 'search'], 'googleother' => ['GoogleOther', 'Google', 'other'], 'bingbot' => ['Bingbot', 'Microsoft', 'search'],
        'yandexbot' => ['YandexBot', 'Yandex', 'search'], 'yandex' => ['Yandex', 'Yandex', 'search'], 'baiduspider' => ['Baiduspider', 'Baidu', 'search'], 'yeti' => ['Yeti (Naver)', 'Naver', 'search'],
        'naver' => ['Naver', 'Naver', 'search'], 'duckduckbot' => ['DuckDuckBot', 'DuckDuckGo', 'search'], 'duckassistbot' => ['DuckAssistBot', 'DuckDuckGo', 'ai_search'],
        'applebot' => ['Applebot', 'Apple', 'search'], 'oaisearchbot' => ['OAI-SearchBot', 'OpenAI', 'ai_search'], 'chatgptuser' => ['ChatGPT-User', 'OpenAI', 'ai_user'],
        'gptbot' => ['GPTBot', 'OpenAI', 'ai_training'], 'claudebot' => ['ClaudeBot', 'Anthropic', 'ai_training'], 'claudeuser' => ['Claude-User', 'Anthropic', 'ai_user'],
        'claudesearchbot' => ['Claude-SearchBot', 'Anthropic', 'ai_search'], 'perplexitybot' => ['PerplexityBot', 'Perplexity', 'ai_search'],
        'perplexityuser' => ['Perplexity-User', 'Perplexity', 'ai_user'], 'ccbot' => ['CCBot', 'Common Crawl', 'ai_training'], 'bytespider' => ['Bytespider', 'ByteDance', 'ai_training'],
        'amazonbot' => ['Amazonbot', 'Amazon', 'ai_training'], 'metaexternalagent' => ['meta-externalagent', 'Meta', 'ai_training'], 'metawebindexer' => ['meta-webindexer', 'Meta', 'ai_search'],
        'metaexternalfetcher' => ['meta-externalfetcher', 'Meta', 'ai_user'], 'facebookexternalhit' => ['facebookexternalhit', 'Meta', 'social'],
        'petalbot' => ['PetalBot', 'Huawei', 'search'], 'seznambot' => ['SeznamBot', 'Seznam', 'search'], 'ahrefsbot' => ['AhrefsBot', 'Ahrefs', 'seo_tool'], 'semrushbot' => ['SemrushBot', 'Semrush', 'seo_tool'],
        'mj12bot' => ['MJ12bot', 'Majestic', 'seo_tool'], 'dotbot' => ['DotBot', 'Moz', 'seo_tool'], 'otherbot' => ['Other / unidentified bots', null, 'other'],
    ];
    $info = $known[$k] ?? [$bot, null, 'other'];
    foreach ($policy as $p) {
        if (strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) ($p['bot'] ?? ''))) === $k) {
            $info[1] = $p['vendor'] ?? $info[1];
            $info[2] = ['search' => 'ai_search', 'user' => 'ai_user', 'training' => 'ai_training'][$p['purpose'] ?? ''] ?? $info[2];
            return ['label' => $info[0], 'vendor' => $info[1], 'purpose' => $info[2], 'allowed' => (bool) ($p['allowed'] ?? true)];
        }
    }
    return ['label' => $info[0], 'vendor' => $info[1], 'purpose' => $info[2], 'allowed' => null];
}

function seo_ai_policy(): array
{
    if (!function_exists('seo_ai_crawler_policy')) {
        return [];
    }
    try {
        return array_values(array_filter((array) seo_ai_crawler_policy(), 'is_array'));
    } catch (Throwable $e) {
        return [];
    }
}

route('GET', '/seo-ai/bots', function () {
    cms_api_require('seo', 'view');
    $days = max(1, min(365, (int) ($_GET['days'] ?? 30)));
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    try {
        $rows = db_all('SELECT hit_date, bot, hits, last_path FROM seo_bot_hits WHERE hit_date >= ? ORDER BY hit_date', [$from]);
    } catch (Throwable $e) {
        $rows = [];
    }
    $policy = seo_ai_policy();
    $bots = [];
    $byPurpose = [];
    $labels = [];
    for ($i = 0; $i < $days; $i++) {
        $labels[] = date('Y-m-d', strtotime($from . " +$i days"));
    }
    $daily = [];
    foreach ($rows as $r) {
        $b = (string) $r['bot'];
        $info = $bots[$b] ?? (seo_ai_bot_info($b, $policy) + ['bot' => $b, 'hits' => 0, 'last_seen' => null, 'last_path' => null]);
        $info['hits'] += (int) $r['hits'];
        if ($info['last_seen'] === null || $r['hit_date'] >= $info['last_seen']) {
            $info['last_seen'] = $r['hit_date'];
            $info['last_path'] = $r['last_path'];
        }
        $bots[$b] = $info;
        $byPurpose[$info['purpose']] = ($byPurpose[$info['purpose']] ?? 0) + (int) $r['hits'];
        $daily[$info['purpose']][$r['hit_date']] = ($daily[$info['purpose']][$r['hit_date']] ?? 0) + (int) $r['hits'];
    }
    usort($bots, fn ($a, $b) => $b['hits'] <=> $a['hits']);
    $purposeLabels = ['search' => 'Search engines', 'ai_search' => 'AI search', 'ai_user' => 'AI user fetches', 'ai_training' => 'AI training', 'social' => 'Link previews', 'seo_tool' => 'SEO tools', 'other' => 'Other bots'];
    $series = [];
    foreach ($purposeLabels as $p => $label) {
        if (isset($daily[$p])) {
            $series[] = ['purpose' => $p, 'label' => $label, 'data' => array_map(fn ($d) => $daily[$p][$d] ?? 0, $labels)];
        }
    }
    $tracking = false;
    try {
        db_value('SELECT 1 FROM seo_bot_hits LIMIT 1');
        $tracking = true;
    } catch (Throwable $e) {
        $tracking = false;
    }
    api_ok(['days' => $days, 'from' => $from, 'total' => array_sum(array_column($bots, 'hits')), 'bots' => array_values($bots), 'by_purpose' => $byPurpose,
        'purpose_labels' => $purposeLabels, 'labels' => $labels, 'series' => $series, 'tracking' => $tracking, 'recorder' => function_exists('seo_bot_hit')]);
});

route('GET', '/seo-ai/ai-status', function () {
    cms_api_require('seo', 'view');
    $policy = seo_ai_policy();
    $v = 'seo_presence_value';
    api_ok([
        'llms' => seo_presence_llms_check(false) + ['full_url' => absolute_url('llms-full.txt')],
        'policy' => $policy,
        'policy_available' => function_exists('seo_ai_crawler_policy'),
        'ai_search_crawlers' => $v('ai_search_crawlers') ?: 'allow',
        'ai_training_crawlers' => $v('ai_training_crawlers') ?: 'allow',
        'robots_custom' => trim((string) setting('robots_txt', '')) !== '',
        'robots_url' => absolute_url('robots.txt'),
        'indexnow' => seo_presence_indexnow(),
        'block_indexing' => $v('seo_block_indexing') === '1',
        'can' => ['edit' => can('seo', 'edit'), 'settings' => can('settings', 'edit')],
    ]);
});

route('GET', '/seo-ai/pagespeed', function () {
    cms_api_require('seo', 'view');
    $origin = seo_psi_public_origin();
    $hasKey = (function_exists('seo_integration_value') ? trim((string) seo_integration_value('pagespeed_api_key', '')) : trim((string) setting('pagespeed_api_key', ''))) !== '';
    api_ok(seo_psi_history((int) ($_GET['limit'] ?? 100)) + [
        'origin' => $origin, 'site_is_local' => seo_presence_is_local_host(), 'default_paths' => seo_psi_default_paths(), 'thresholds' => seo_psi_thresholds(),
        'has_key' => $hasKey, 'can' => ['run' => can('seo', 'edit')],
    ]);
});

route('POST', '/seo-ai/pagespeed/run', function () {
    cms_api_require('seo', 'edit');
    $in = api_input();
    [$url, $err] = seo_psi_check_url(api_str($in['url'] ?? null));
    if ($err) {
        api_error($err, 422, ['url' => $err]);
    }
    $strategy = api_str($in['strategy'] ?? null, 'mobile');
    if (!in_array($strategy, ['mobile', 'desktop'], true)) {
        api_error('Choose mobile or desktop.', 422, ['strategy' => 'Choose mobile or desktop.']);
    }
    if (!rate_limit('psi:' . user_id(), 30, 900)) {
        api_error('Too many PageSpeed runs in the last 15 minutes. Please wait a little.', 429);
    }
    $row = seo_psi_run($url, $strategy, user_id());
    if ($row['error']) {
        api_error($row['error'], 502);
    }
    api_ok($row, 'PageSpeed ' . $strategy . ': ' . $row['performance_score'] . '/100 for ' . $url);
});
