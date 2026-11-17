<?php
/**
 * IndexNow (unit seo-core, 2026-11-17) — tells Bing, Yandex, Naver, Seznam, Yep … (not Google) which URLs changed.
 * Protocol: https://www.indexnow.org/documentation — key = 8–128 chars [A-Za-z0-9-], key file /{key}.txt (served by
 * indexnow.php through tools/router.php / .htaccess), batched POST {host, key, keyLocation, urlList} (≤ 10,000 URLs).
 *
 *   seo_indexnow_status()                    admin payload: enabled, key, key_url, endpoint, pending_count, last_runs …
 *   seo_indexnow_pending()                   diff of the sitemap URLs (lastmod) against seo_indexnow_urls
 *   seo_indexnow_submit($mode, $urls, $trigger)   'changed' | 'all' | 'urls' → {submitted, http_status, message}
 *   seo_indexnow_generate_key()              32 hex characters
 *   seo_indexnow_cron_token() / _cron_url()  HMAC token for the server cron URL (GET /api/integrations/indexnow/cron)
 *
 * Every submission attempt with URLs is logged in seo_indexnow_log. A non-production origin (localhost, 127.0.0.1,
 * *.test, *.local, *.localhost, private IPs) never submits to a public endpoint — only to a local test endpoint.
 */

if (!defined('APP_ROOT')) {
    http_response_code(404);
    exit;
}

const SEO_INDEXNOW_DEFAULT_ENDPOINT = 'https://api.indexnow.org/indexnow';
const SEO_INDEXNOW_BATCH = 10000;

/** 32 lower-case hex characters (valid IndexNow key). */
function seo_indexnow_generate_key(): string
{
    return bin2hex(random_bytes(16));
}

function seo_indexnow_key_valid(string $key): bool
{
    return (bool) preg_match('/^[A-Za-z0-9\-]{8,128}$/', $key);
}

function seo_indexnow_enabled(): bool
{
    return seo_integration_value('indexnow_enabled', '0') === '1' && seo_indexnow_key_valid((string) seo_integration_value('indexnow_key', ''));
}

function seo_indexnow_key(): string
{
    $k = trim((string) seo_integration_value('indexnow_key', ''));
    return seo_indexnow_key_valid($k) ? $k : '';
}

/** Public URL of the key file. */
function seo_indexnow_key_url(?string $key = null): ?string
{
    $key = $key ?? seo_indexnow_key();
    return $key !== '' ? absolute_url($key . '.txt') : null;
}

function seo_indexnow_endpoint(): string
{
    $e = trim((string) seo_integration_value('indexnow_endpoint', SEO_INDEXNOW_DEFAULT_ENDPOINT));
    return preg_match('#^https?://[^\s]+$#i', $e) ? $e : SEO_INDEXNOW_DEFAULT_ENDPOINT;
}

/** Is a host local / private (dev machine, LAN, *.test …)? */
function seo_host_is_local(string $host): bool
{
    $host = strtolower(trim($host, '[]'));
    if ($host === '' || $host === 'localhost' || preg_match('/\.(test|local|localhost|internal|example|invalid)$/', $host)) {
        return true;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $flags = defined('FILTER_FLAG_GLOBAL_RANGE') ? FILTER_FLAG_GLOBAL_RANGE : (FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        return !filter_var($host, FILTER_VALIDATE_IP, $flags);
    }
    return false;
}

/** IP addresses of a host name (A + AAAA; an IP literal is returned as is). */
function seo_indexnow_resolve(string $host): array
{
    $host = trim($host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return [$host];
    }
    $ips = @gethostbynamel($host) ?: [];
    if (function_exists('dns_get_record')) {
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
            if (!empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
    }
    return array_values(array_unique($ips));
}

/**
 * SSRF guard for a public website: the endpoint (an admin setting) must be https and resolve to public addresses only,
 * so the server never POSTs to itself, the LAN or a cloud metadata service. Returns the problem or null.
 */
function seo_indexnow_endpoint_problem(string $endpoint): ?string
{
    $p = parse_url($endpoint) ?: [];
    $host = strtolower(trim((string) ($p['host'] ?? ''), '[]'));
    if (strtolower((string) ($p['scheme'] ?? '')) !== 'https' || $host === '' || isset($p['user']) || isset($p['pass'])) {
        return 'The IndexNow endpoint must be an https:// address of a search engine.';
    }
    if (seo_host_is_local($host)) {
        return 'The IndexNow endpoint points to a local or private address (' . $host . '), so nothing is sent.';
    }
    $ips = seo_indexnow_resolve($host);
    if (!$ips) {
        return 'The IndexNow endpoint host ' . $host . ' could not be resolved.';
    }
    foreach ($ips as $ip) {
        if (seo_host_is_local($ip)) {
            return 'The IndexNow endpoint ' . $host . ' resolves to a private address, so nothing is sent.';
        }
    }
    return null;
}

/**
 * Why this installation may not submit right now (null = allowed): a local / private site origin may only talk to a
 * local test endpoint (never the real search engines), a public site only to a public https endpoint (SSRF guard), and
 * a 429 answer pauses submissions until Retry-After.
 */
function seo_indexnow_guard(): ?string
{
    $originHost = (string) parse_url(seo_origin(), PHP_URL_HOST);
    $endpoint = seo_indexnow_endpoint();
    $endpointHost = (string) parse_url($endpoint, PHP_URL_HOST);
    if (seo_host_is_local($originHost)) {
        if (!seo_host_is_local($endpointHost)) {
            return 'This copy of the website runs on ' . $originHost . ' (not the public domain), so nothing is sent to ' . $endpointHost . '.';
        }
    } elseif ($problem = seo_indexnow_endpoint_problem($endpoint)) {
        return $problem;
    }
    $until = (int) setting('_indexnow_retry_after', '0');
    if ($until > time()) {
        return 'The search engine asked us to slow down (HTTP 429). Submissions resume after ' . date('d M Y, H:i', $until) . '.';
    }
    return null;
}

/** Indexable sitemap URLs: path => lastmod (Y-m-d or ''). */
function seo_indexnow_sitemap_paths(): array
{
    $out = [];
    foreach (seo_sitemap_groups() as $list) {
        foreach ($list as $u) {
            $out[$u['path']] = (string) ($u['lastmod'] ?? '');
        }
    }
    return $out;
}

/**
 * URLs to submit in "changed" mode: new or modified sitemap URLs (lastmod differs from the last submission) and URLs
 * that left the sitemap since they were submitted (deleted / noindexed — IndexNow wants those too). URLs submitted in
 * the last 5 minutes wait (protocol rule). Returns ['changed' => [path => lastmod], 'removed' => [path, …]].
 */
function seo_indexnow_pending(): array
{
    $current = seo_indexnow_sitemap_paths();
    $known = [];
    try {
        foreach (db_all('SELECT path, last_lastmod, submitted_at FROM seo_indexnow_urls') as $r) {
            $known[$r['path']] = $r;
        }
    } catch (Throwable $e) {
    }
    $recent = fn (array $r) => strtotime((string) $r['submitted_at']) > time() - 300;
    $changed = [];
    foreach ($current as $path => $lm) {
        $k = $known[$path] ?? null;
        if (!$k || (string) $k['last_lastmod'] !== $lm) {
            if ($k && $recent($k)) {
                continue;
            }
            $changed[$path] = $lm;
        }
    }
    $removed = [];
    foreach ($known as $path => $k) {
        if (!isset($current[$path]) && !$recent($k)) {
            $removed[] = $path;
        }
    }
    return ['changed' => $changed, 'removed' => $removed];
}

/** Last submissions (newest first). */
function seo_indexnow_runs(int $limit = 10): array
{
    try {
        $rows = db_all('SELECT l.id, l.trigger_type, l.url_count, l.urls_sample, l.http_status, l.response, l.created_at, u.name AS created_by_name
                        FROM seo_indexnow_log l LEFT JOIN users u ON u.id = l.created_by ORDER BY l.id DESC LIMIT ' . max(1, min(100, $limit)));
    } catch (Throwable $e) {
        return [];
    }
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['url_count'] = (int) $r['url_count'];
        $r['http_status'] = $r['http_status'] === null ? null : (int) $r['http_status'];
        $r['ok'] = in_array($r['http_status'], [200, 202], true);
    }
    return $rows;
}

/** HMAC token of the cron URL (derived from the application key, like comm_cron_token()). */
function seo_indexnow_cron_token(): string
{
    return substr(hash_hmac('sha256', 'gimt-indexnow-cron', (string) (app_config('app')['key'] ?? '')), 0, 40);
}

function seo_indexnow_cron_info(): array
{
    $url = absolute_url('api/integrations/indexnow/cron?token=' . seo_indexnow_cron_token());
    return ['url' => $url, 'command' => '*/30 * * * * www-data curl -fsS "' . $url . '" > /dev/null 2>&1', 'last_run' => setting('_indexnow_last_run') ?: null];
}

/** Admin payload (contract: {enabled, key, key_url, endpoint, pending_count, last_runs}). */
function seo_indexnow_status(bool $withCron = false): array
{
    $enabled = seo_indexnow_enabled();
    $pending = $enabled ? seo_indexnow_pending() : ['changed' => [], 'removed' => []];
    $out = [
        'enabled' => $enabled,
        'key' => seo_indexnow_key(),
        'key_url' => seo_indexnow_key_url(),
        'endpoint' => seo_indexnow_endpoint(),
        'pending_count' => count($pending['changed']) + count($pending['removed']),
        'pending_sample' => array_map('seo_path_url', array_slice(array_merge(array_keys($pending['changed']), $pending['removed']), 0, 10)),
        'submitted_urls' => (int) (function () { try { return db_value('SELECT COUNT(*) FROM seo_indexnow_urls'); } catch (Throwable $e) { return 0; } })(),
        'sitemap_urls' => count(seo_indexnow_sitemap_paths()),
        'blocked_reason' => $enabled ? seo_indexnow_guard() : null,
        'last_run' => setting('_indexnow_last_run') ?: null,
        'last_runs' => seo_indexnow_runs(10),
    ];
    if ($withCron) {
        $out['cron'] = seo_indexnow_cron_info();
    }
    return $out;
}

/** POST one batch. Returns [http_status (0 = no answer), response text]. */
function seo_indexnow_post(string $endpoint, array $payload): array
{
    if (!function_exists('curl_init')) {
        return [0, 'The PHP curl extension is not installed.'];
    }
    $pin = [];
    if (!seo_host_is_local((string) parse_url(seo_origin(), PHP_URL_HOST))) {
        // public site: re-check and pin the resolved public address (no DNS rebinding between the check and the POST)
        if ($problem = seo_indexnow_endpoint_problem($endpoint)) {
            return [0, $problem];
        }
        $eHost = trim((string) parse_url($endpoint, PHP_URL_HOST), '[]');
        if (!filter_var($eHost, FILTER_VALIDATE_IP)) {
            $ip = seo_indexnow_resolve($eHost)[0];
            $pin = [$eHost . ':' . ((int) (parse_url($endpoint, PHP_URL_PORT) ?: 443)) . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)];
        }
    }
    $ch = curl_init($endpoint);
    if ($pin) {
        curl_setopt($ch, CURLOPT_RESOLVE, $pin);
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8', 'Accept: */*'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'GIMT-IndexNow/1.0',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    unset($ch); // curl_close() is a no-op since PHP 8 (deprecated in 8.5)
    if ($raw === false) {
        return [0, 'Connection failed: ' . $err];
    }
    $headers = substr((string) $raw, 0, $hsize);
    $body = trim(substr((string) $raw, $hsize));
    if ($status === 429 && preg_match('/^retry-after:\s*(\d+)/im', $headers, $m)) {
        save_setting('_indexnow_retry_after', (string) (time() + min(86400, max(60, (int) $m[1]))), 'seo');
    } elseif ($status === 429) {
        save_setting('_indexnow_retry_after', (string) (time() + 3600), 'seo');
    }
    $text = [200 => 'OK — URLs received', 202 => 'Accepted — key validation pending', 400 => 'Bad request (invalid format)',
        403 => 'Key not valid (key file missing or does not match)', 422 => 'URLs do not belong to the host, or the key does not match the protocol',
        429 => 'Too many requests (potential spam)'][$status] ?? ('HTTP ' . $status);
    return [$status, mb_substr($text . ($body !== '' ? ' · ' . preg_replace('/\s+/', ' ', strip_tags($body)) : ''), 0, 480)];
}

/**
 * Submit URLs. $mode: 'changed' (diff, default), 'all' (every sitemap URL), 'urls' ($urls: absolute URLs of this site
 * or paths). $trigger: manual | sitemap | cron | save. Returns {submitted, http_status, message, skipped?}.
 */
function seo_indexnow_submit(string $mode = 'changed', array $urls = [], string $trigger = 'manual'): array
{
    $trigger = in_array($trigger, ['manual', 'sitemap', 'cron', 'save'], true) ? $trigger : 'manual';
    if (!seo_indexnow_enabled()) {
        return ['submitted' => 0, 'http_status' => null, 'message' => 'IndexNow is switched off (Settings → Integrations → IndexNow).', 'skipped' => true];
    }
    $key = seo_indexnow_key();
    $paths = [];
    $lastmods = [];
    $removed = [];
    if ($mode === 'all') {
        $lastmods = seo_indexnow_sitemap_paths();
        $paths = array_keys($lastmods);
    } elseif ($mode === 'urls') {
        foreach (array_slice($urls, 0, SEO_INDEXNOW_BATCH) as $u) {
            $u = trim((string) $u);
            if ($u === '') {
                continue;
            }
            if (preg_match('#^https?://#i', $u)) {
                $origin = seo_origin();
                if (stripos($u . '/', $origin . '/') !== 0 && stripos($u, $origin . '?') !== 0) {
                    return ['submitted' => 0, 'http_status' => null, 'message' => 'Only URLs of this website can be submitted (' . $origin . ').', 'skipped' => true, 'invalid' => true];
                }
                $u = substr($u, strlen($origin)) ?: '/';
            }
            $p = seo_site_path($u);
            $q = str_contains($u, '?') ? '?' . explode('?', $u, 2)[1] : '';
            $paths[] = $p . $q;
        }
        $paths = array_values(array_unique($paths));
        $lastmods = array_intersect_key(seo_indexnow_sitemap_paths(), array_flip($paths));
    } else {
        $pending = seo_indexnow_pending();
        $lastmods = $pending['changed'];
        $removed = $pending['removed'];
        $paths = array_merge(array_keys($lastmods), $removed);
    }
    if (!$paths) {
        return ['submitted' => 0, 'http_status' => null, 'message' => 'Nothing to submit — every URL is up to date.', 'skipped' => true];
    }
    $absolute = array_map('seo_path_url', $paths);
    $log = function (int $count, array $sample, ?int $status, string $response) use ($trigger) {
        try {
            db_insert('seo_indexnow_log', ['trigger_type' => $trigger, 'url_count' => $count,
                'urls_sample' => mb_substr(implode("\n", array_slice($sample, 0, 10)), 0, 1000) ?: null,
                'http_status' => $status, 'response' => mb_substr($response, 0, 500), 'created_by' => function_exists('user_id') ? user_id() : null]);
            if (random_int(1, 20) === 1) {
                db_exec('DELETE FROM seo_indexnow_log WHERE created_at < NOW() - INTERVAL 365 DAY');
            }
        } catch (Throwable $e) {
        }
    };
    if ($why = seo_indexnow_guard()) {
        $log(count($absolute), $absolute, null, 'Not sent: ' . $why);
        return ['submitted' => 0, 'http_status' => null, 'message' => $why, 'skipped' => true];
    }
    $endpoint = seo_indexnow_endpoint();
    $host = (string) parse_url(seo_origin(), PHP_URL_HOST);
    $submitted = 0;
    $status = null;
    $message = '';
    foreach (array_chunk($absolute, SEO_INDEXNOW_BATCH) as $i => $batch) {
        [$status, $message] = seo_indexnow_post($endpoint, ['host' => $host, 'key' => $key, 'keyLocation' => seo_indexnow_key_url($key), 'urlList' => $batch]);
        $log(count($batch), $batch, $status ?: null, $message);
        if (!in_array($status, [200, 202], true)) {
            break;
        }
        $submitted += count($batch);
        // remember what was submitted (diff base for the next "changed" run)
        $now = date('Y-m-d H:i:s');
        $batchPaths = array_slice($paths, $i * SEO_INDEXNOW_BATCH, count($batch));
        foreach ($batchPaths as $p) {
            if (in_array($p, $removed, true) || !array_key_exists($p, $lastmods)) {
                if (in_array($p, $removed, true)) {
                    db_exec('DELETE FROM seo_indexnow_urls WHERE path_hash = ?', [sha1($p)]);
                }
                continue;
            }
            db_exec('INSERT INTO seo_indexnow_urls (path_hash, path, last_lastmod, submitted_at) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE path = VALUES(path), last_lastmod = VALUES(last_lastmod), submitted_at = VALUES(submitted_at)',
                [sha1($p), mb_substr($p, 0, 500), $lastmods[$p] !== '' ? $lastmods[$p] : null, $now]);
        }
    }
    save_setting('_indexnow_last_run', date('Y-m-d H:i:s'), 'seo');
    $ok = in_array($status, [200, 202], true);
    $msg = $ok ? sprintf('%d URL%s submitted to IndexNow (%s).', $submitted, $submitted === 1 ? '' : 's', $message) : 'IndexNow did not accept the URLs: ' . $message;
    if (function_exists('log_activity') && $trigger !== 'cron') {
        log_activity('update', 'seo', 'indexnow', $msg, $ok ? 'success' : 'failed', ['mode' => $mode, 'urls' => count($absolute), 'http_status' => $status]);
    }
    return ['submitted' => $submitted, 'http_status' => $status ?: null, 'message' => $msg, 'skipped' => false];
}
