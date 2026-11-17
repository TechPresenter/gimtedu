<?php
/**
 * PageSpeed Insights runs (SEO Management › PageSpeed). Server-side call to Google's public PSI v5 API
 * (https://www.googleapis.com/pagespeedonline/v5/runPagespeed), optional API key from the integration setting
 * pagespeed_api_key (env GIMT_PAGESPEED_KEY). One URL + strategy per call (a run takes 10–40 s); the admin screen
 * queues several and runs them one after another. Results are stored in seo_pagespeed_runs.
 *
 * Core Web Vitals thresholds ("good"): LCP ≤ 2.5 s, INP ≤ 200 ms, CLS ≤ 0.1. Field data (CrUX, real visitors) is used
 * when Google has it for the URL; otherwise the lab run decides (LCP, CLS, and Total Blocking Time ≤ 200 ms as the
 * lab stand-in for INP).
 */
require_once APP_ROOT . '/app/services/seo_presence.php';

const SEO_PSI_ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

function seo_psi_thresholds(): array
{
    return ['lcp_ms' => 2500, 'inp_ms' => 200, 'cls' => 0.1, 'tbt_ms' => 200];
}

/**
 * Origin PageSpeed should test: the website's own address, or — on a local / development copy that Google cannot
 * reach — the public address in env GIMT_PUBLIC_URL (null when unknown: the admin then types it).
 */
function seo_psi_public_origin(): ?string
{
    $env = getenv('GIMT_PUBLIC_URL') ?: ($_SERVER['GIMT_PUBLIC_URL'] ?? '');
    if (is_string($env) && preg_match('#^https://[a-z0-9.\-]+(:\d+)?$#i', rtrim($env, '/'))) {
        return rtrim($env, '/');
    }
    $origin = (string) preg_replace('#^(https?://[^/]+).*$#i', '$1', absolute_url(''));
    return seo_presence_is_local_host((string) parse_url($origin, PHP_URL_HOST)) ? null : $origin;
}

/** Default URL paths to test: home, /programs, the top 3 program pages, /admissions, /contact. */
function seo_psi_default_paths(): array
{
    $paths = ['/', '/programs'];
    try {
        foreach (db_column("SELECT slug FROM programs WHERE status = 'active' AND show_on_website = 1 AND slug IS NOT NULL AND slug <> '' ORDER BY sort_order, name LIMIT 3") as $slug) {
            $paths[] = '/programs/' . $slug;
        }
    } catch (Throwable $e) {
        // programs table optional
    }
    return array_merge($paths, ['/admissions', '/contact']);
}

/**
 * Validate a URL to test. On a public website only its own pages may be tested; on a local copy any public https
 * address (the live website) is allowed because the local address cannot be reached by Google.
 * @return array [normalised url | null, error | null]
 */
function seo_psi_check_url(string $url): array
{
    $url = trim($url);
    if (!preg_match('#^https?://[a-z0-9.\-]+(:\d+)?(/[^\s"\'<>]*)?$#i', $url) || mb_strlen($url) > 500) {
        return [null, 'Enter a full page address starting with https://'];
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (seo_presence_is_local_host($host)) {
        return [null, 'Google cannot reach a local address (' . $host . '). Use the public website address.'];
    }
    $own = seo_psi_public_origin();
    $siteLocal = seo_presence_is_local_host();
    if (!$siteLocal && $own && strtolower((string) parse_url($own, PHP_URL_HOST)) !== $host) {
        return [null, 'Only pages of this website (' . parse_url($own, PHP_URL_HOST) . ') can be tested.'];
    }
    return [$url, null];
}

/** Call PSI for one URL + strategy, store the run and return the stored row (with "error" set when it failed). */
function seo_psi_run(string $url, string $strategy, ?int $userId = null): array
{
    $strategy = $strategy === 'desktop' ? 'desktop' : 'mobile';
    $key = function_exists('seo_integration_value') ? trim((string) seo_integration_value('pagespeed_api_key', '')) : trim((string) setting('pagespeed_api_key', ''));
    $query = 'url=' . rawurlencode($url) . '&strategy=' . $strategy . '&category=performance&category=seo&category=accessibility&category=best-practices&locale=en';
    if ($key !== '') {
        $query .= '&key=' . rawurlencode($key);
    }
    $row = ['url' => $url, 'strategy' => $strategy, 'created_by' => $userId];
    $json = null;
    $error = null;
    if (!function_exists('curl_init')) {
        $error = 'cURL is not available on this server.';
    } else {
        @set_time_limit(120);
        $ch = curl_init(SEO_PSI_ENDPOINT . '?' . $query);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'SmartCampus-SEO/1.0']);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($body === false) {
            $error = 'Could not reach Google PageSpeed Insights: ' . curl_error($ch);
        } else {
            $json = json_decode((string) $body, true);
            if ($code !== 200 || !is_array($json) || empty($json['lighthouseResult'])) {
                $msg = is_array($json) ? (string) ($json['error']['message'] ?? '') : '';
                $error = 'PageSpeed Insights returned HTTP ' . $code . ($msg !== '' ? ': ' . $msg : '.');
                if ($code === 429) {
                    $error .= ' The free quota is used up — add a PageSpeed API key in Settings › Integrations or try again later.';
                }
                $json = null;
            }
        }
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch); // no-op (deprecated) since PHP 8
        }
    }
    if ($json) {
        $row = array_merge($row, seo_psi_parse($json));
    }
    $row['error'] = $error !== null ? mb_substr($error, 0, 500) : null;
    // never store the API key (it is only in the request query string)
    $id = db_insert('seo_pagespeed_runs', $row);
    log_activity('create', 'seo', $id, 'PageSpeed ' . $strategy . ' run for ' . $url . ($error ? ' failed' : ': performance ' . ($row['performance_score'] ?? '—') . '/100'));
    return seo_psi_public_row(db_row('SELECT * FROM seo_pagespeed_runs WHERE id = ?', [$id]) ?: $row + ['id' => $id]);
}

/** Extract scores, lab metrics, field (CrUX) metrics and the top opportunities from a PSI v5 response. */
function seo_psi_parse(array $j): array
{
    $lh = $j['lighthouseResult'] ?? [];
    $cat = fn (string $k) => isset($lh['categories'][$k]['score']) && $lh['categories'][$k]['score'] !== null ? (int) round((float) $lh['categories'][$k]['score'] * 100) : null;
    $audit = fn (string $k) => isset($lh['audits'][$k]['numericValue']) ? (float) $lh['audits'][$k]['numericValue'] : null;
    $field = $j['loadingExperience']['metrics'] ?? [];
    $fm = fn (string $k) => isset($field[$k]['percentile']) ? (float) $field[$k]['percentile'] : null;
    $opps = [];
    foreach ($lh['audits'] ?? [] as $id => $a) {
        if (($a['details']['type'] ?? '') === 'opportunity' && isset($a['score']) && $a['score'] !== null && (float) $a['score'] < 0.9) {
            $opps[] = ['id' => (string) $id, 'title' => mb_substr(strip_tags((string) ($a['title'] ?? $id)), 0, 160),
                'savings_ms' => (int) round((float) ($a['details']['overallSavingsMs'] ?? 0)), 'display' => mb_substr(strip_tags((string) ($a['displayValue'] ?? '')), 0, 80)];
        }
    }
    usort($opps, fn ($a, $b) => $b['savings_ms'] <=> $a['savings_ms']);
    $lcp = $audit('largest-contentful-paint');
    $cls = $audit('cumulative-layout-shift');
    $tbt = $audit('total-blocking-time');
    $fcp = $audit('first-contentful-paint');
    $fcls = $fm('CUMULATIVE_LAYOUT_SHIFT_SCORE');
    return [
        'performance_score' => $cat('performance'), 'seo_score' => $cat('seo'), 'accessibility_score' => $cat('accessibility'), 'best_practices_score' => $cat('best-practices'),
        'lcp_ms' => $lcp !== null ? (int) round($lcp) : null, 'cls' => $cls !== null ? round($cls, 3) : null, 'tbt_ms' => $tbt !== null ? (int) round($tbt) : null,
        'fcp_ms' => $fcp !== null ? (int) round($fcp) : null,
        'field_lcp_ms' => ($v = $fm('LARGEST_CONTENTFUL_PAINT_MS')) !== null ? (int) $v : null,
        'field_inp_ms' => ($v = $fm('INTERACTION_TO_NEXT_PAINT')) !== null ? (int) $v : null,
        'field_cls' => $fcls !== null ? round($fcls / 100, 3) : null, // CrUX reports CLS × 100
        'opportunities' => $opps ? json_encode(array_slice($opps, 0, 6), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ];
}

/** Core Web Vitals verdict for a stored run: field data first, else lab (TBT stands in for INP). */
function seo_psi_cwv(array $r): array
{
    $t = seo_psi_thresholds();
    $hasField = $r['field_lcp_ms'] !== null || $r['field_inp_ms'] !== null || $r['field_cls'] !== null;
    $lcp = $hasField ? $r['field_lcp_ms'] : $r['lcp_ms'];
    $cls = $hasField ? $r['field_cls'] : $r['cls'];
    $inp = $hasField ? $r['field_inp_ms'] : null;
    $checks = [
        'lcp' => $lcp !== null ? (float) $lcp <= $t['lcp_ms'] : null,
        'inp' => $hasField ? ($inp !== null ? (float) $inp <= $t['inp_ms'] : null) : ($r['tbt_ms'] !== null ? (float) $r['tbt_ms'] <= $t['tbt_ms'] : null),
        'cls' => $cls !== null ? (float) $cls <= $t['cls'] : null,
    ];
    $known = array_filter($checks, fn ($v) => $v !== null);
    return ['source' => $hasField ? 'field' : 'lab', 'checks' => $checks, 'pass' => $known ? !in_array(false, $known, true) : null];
}

function seo_psi_public_row(array $r): array
{
    foreach (['id', 'performance_score', 'seo_score', 'accessibility_score', 'best_practices_score', 'lcp_ms', 'tbt_ms', 'fcp_ms', 'field_lcp_ms', 'field_inp_ms', 'created_by'] as $k) {
        $r[$k] = isset($r[$k]) && $r[$k] !== null ? (int) $r[$k] : null;
    }
    foreach (['cls', 'field_cls'] as $k) {
        $r[$k] = isset($r[$k]) && $r[$k] !== null ? (float) $r[$k] : null;
    }
    $r['opportunities'] = !empty($r['opportunities']) ? (json_decode((string) $r['opportunities'], true) ?: []) : [];
    $r['cwv'] = seo_psi_cwv($r);
    return $r;
}

/** History (newest first) + the latest run per URL/strategy. */
function seo_psi_history(int $limit = 100): array
{
    $rows = array_map('seo_psi_public_row', db_all('SELECT r.*, u.name AS created_by_name FROM seo_pagespeed_runs r LEFT JOIN users u ON u.id = r.created_by ORDER BY r.id DESC LIMIT ' . max(1, min(500, $limit))));
    $latest = [];
    foreach ($rows as $r) {
        $latest[$r['url'] . '|' . $r['strategy']] ??= $r;
    }
    return ['runs' => $rows, 'latest' => array_values($latest)];
}
