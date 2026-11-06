<?php
/**
 * Integrations, error monitoring, sitemap/robots tools and the SEO audit (unit seo_platform).
 *
 * Settings → Integrations (settings.view / settings.edit):
 *   GET    /api/integrations                         sections + values (secrets masked, never returned), env overrides, status
 *   PUT    /api/integrations/{section}               save one section (captcha|analytics|consent|maps|monitoring|whatsapp|search)
 *   POST   /api/integrations/consent/renew           new consent version → every visitor is asked again
 * Error monitoring:
 *   POST   /api/integrations/client-error            public beacon from the website reporter (also /api/public/client-error):
 *                                                    same-origin only, ≤ 8 KB, rate-limited per IP + globally, deduplicated
 *   GET    /api/integrations/errors                  ?channel=client|server|all&q=&level=&days=&page=  (settings.view or security.view)
 *   POST   /api/integrations/errors/clear            {channel, older_than_days?}   (settings.edit)
 * Sitemaps & robots (seo.view / seo.edit):
 *   GET    /api/integrations/robots                  served robots.txt + recommended default
 *   GET    /api/integrations/sitemaps                child sitemaps (name, url, URL count, lastmod)
 *   POST   /api/integrations/sitemaps/rebuild        clear the sitemap cache and rebuild
 * SEO audit (seo.view; starting / stepping / cancelling / deleting needs seo.edit):
 *   GET    /api/integrations/seo-audit               latest + running audit, history, issue definitions
 *   POST   /api/integrations/seo-audit               {max_pages} start (or return the running audit)
 *   GET    /api/integrations/seo-audit/{id}          status + summary
 *   POST   /api/integrations/seo-audit/{id}/step     crawl the next URLs (browser-driven mode)
 *   POST   /api/integrations/seo-audit/{id}/cancel
 *   GET    /api/integrations/seo-audit/{id}/pages    ?issue=&severity=&status=&kind=&q=&sort=&dir=&page=
 *   GET    /api/integrations/seo-audit/{id}/export   CSV of every crawled URL
 *   DELETE /api/integrations/seo-audit/{id}
 */
require_once APP_ROOT . '/app/services/seo.php';

/** Status cards for the Integrations tab (no secret values). */
function integrations_status(): array
{
    $v = fn (string $k) => trim((string) seo_integration_value($k, ''));
    $secretSet = $v('captcha_secret_key') !== '';
    $provider = $v('captcha_provider') ?: 'none';
    $smtpHost = trim((string) setting('smtp_host', ''));
    $driver = (string) setting('mail_driver', 'smtp');
    $audit = db_row("SELECT id, status, pages_crawled, errors_count, warnings_count, finished_at FROM seo_audits WHERE status = 'completed' ORDER BY id DESC LIMIT 1");
    require_once APP_ROOT . '/includes/partials/consent.php';
    $consent = consent_config();
    return [
        'captcha' => ['provider' => $provider, 'ready' => $provider === 'none' || ($v('captcha_site_key') !== '' && $secretSet), 'secret_set' => $secretSet],
        'analytics' => ['ga' => $v('google_analytics_id') !== '', 'gtm' => $v('gtm_id') !== '', 'pixel' => $v('meta_pixel_id') !== '',
            'gsc' => $v('search_console_verification') !== '', 'bing' => $v('bing_site_verification') !== '', 'mode' => $v('consent_mode') ?: 'basic'],
        'consent' => ['mode' => $consent['mode'], 'banner' => $consent['banner'], 'version' => $consent['v'], 'policy' => $consent['policy']],
        'maps' => ['embed' => $v('map_embed_url') !== '', 'key' => $v('google_maps_api_key') !== ''],
        'monitoring' => seo_error_log_stats() + ['enabled' => $v('client_error_logging') !== '0', 'sentry' => $v('sentry_dsn') !== ''],
        'email' => ['driver' => $driver, 'effective' => is_dev() ? 'log' : ($driver === 'smtp' && $smtpHost === '' ? 'mail' : $driver), 'host' => $smtpHost,
            'port' => (string) setting('smtp_port', ''), 'encryption' => (string) setting('smtp_encryption', ''), 'from' => (string) setting('mail_from_email', ''),
            'password_set' => trim((string) setting('smtp_password', '')) !== '', 'dev' => is_dev()],
        'whatsapp' => ['number' => $v('whatsapp_number'), 'gateway' => setting('whatsapp_enabled', '0') === '1'],
        'search' => ['blocked' => setting('seo_block_indexing', '0') === '1', 'sitemap_url' => absolute_url('sitemap.xml'), 'robots_url' => absolute_url('robots.txt'),
            'robots_custom' => trim((string) setting('robots_txt', '')) !== '',
            'last_audit' => $audit ? array_map(fn ($x) => is_numeric($x) ? (int) $x : $x, $audit) : null],
    ];
}

/** Environment variables the platform reads (names + whether they are set; never values). */
function integrations_env_vars(): array
{
    $out = [];
    foreach (seo_integration_fields() as $key => $f) {
        if (!empty($f['env'])) {
            $out[] = ['name' => $f['env'], 'key' => $key, 'label' => $f['label'], 'secret' => !empty($f['secret']), 'set' => seo_integration_env($key) !== null];
        }
    }
    foreach ([['GIMT_URL', 'Public site URL (canonical links, sitemaps)'], ['GIMT_APP_KEY', 'Application secret key'], ['GIMT_ENV', 'Environment (production | dev)'],
        ['GIMT_DB_HOST', 'Database host'], ['GIMT_DB_PASS', 'Database password']] as [$name, $label]) {
        $val = getenv($name);
        $out[] = ['name' => $name, 'key' => null, 'label' => $label, 'secret' => in_array($name, ['GIMT_APP_KEY', 'GIMT_DB_PASS'], true), 'set' => $val !== false && $val !== ''];
    }
    return $out;
}

function integrations_payload(): array
{
    return seo_integration_payload() + [
        'status' => integrations_status(),
        'env_vars' => integrations_env_vars(),
        'can_edit' => can('settings', 'edit'),
        'can_view_errors' => can('settings', 'view') || can('security', 'view'),
        'site_url' => absolute_url(''),
        'consent_preview_url' => absolute_url('?consent_preview=1'),
    ];
}

route('GET', '/integrations', function () {
    api_require('settings', 'view');
    api_ok(integrations_payload());
});

route('PUT', '/integrations/{section:[a-z]+}', function ($p) {
    api_require('settings', 'edit');
    $sections = seo_integration_sections();
    if (!isset($sections[$p['section']])) {
        api_error('Unknown integration section.', 404);
    }
    $changed = seo_integration_save($p['section'], api_input());
    $fields = seo_integration_fields();
    if ($changed) {
        $names = array_map(fn ($k) => $fields[$k]['label'] ?? $k, $changed);
        log_activity('update', 'settings', null, 'Updated integrations — ' . $sections[$p['section']]['title'] . ': ' . implode(', ', $names), 'success',
            ['section' => $p['section'], 'keys' => $changed]); // names only, never values
    }
    api_ok(integrations_payload(), $changed ? $sections[$p['section']]['title'] . ' saved.' : 'Nothing to save — no changes were made.');
});

route('POST', '/integrations/consent/renew', function () {
    api_require('settings', 'edit');
    $v = max(1, (int) setting('consent_version', '1')) + 1;
    save_setting('consent_version', (string) $v, 'integrations');
    log_activity('update', 'settings', null, "Cookie consent renewed (version $v): every visitor is asked again");
    api_ok(['version' => $v], 'Every visitor will be asked for cookie consent again.');
});

/* ------------------------------------------------------------------ error monitoring */

route('POST', '/integrations/client-error', function () {
    if (!seo_same_origin_request()) {
        api_error('Forbidden', 403);
    }
    $raw = (string) file_get_contents('php://input', false, null, 0, 8193);
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192 || strlen($raw) > 8192) {
        api_error('Report too large.', 413);
    }
    if (seo_integration_value('client_error_logging', '1') === '0') {
        api_ok(['stored' => false]);
    }
    if (!rate_limit('cerr-ip:' . client_ip(), 20, 600) || !rate_limit('cerr-all', 600, 3600)) {
        header('Retry-After: 600');
        api_error('Too many error reports.', 429);
    }
    $in = json_decode($raw, true);
    if (!is_array($in)) {
        $in = api_input();
    }
    $res = seo_client_error_store($in);
    api_ok(['stored' => $res['stored']]);
}, ['auth' => false, 'csrf' => false]);

route('GET', '/integrations/errors', function () {
    if (!can('settings', 'view') && !can('security', 'view')) {
        api_error('You do not have permission to view the error log.', 403);
    }
    [$page, $perPage] = api_pagination(25);
    $channel = in_array($_GET['channel'] ?? 'client', ['client', 'server', 'all'], true) ? api_str($_GET['channel'] ?? null, 'client') : 'client';
    api_ok(seo_error_log_list(['channel' => $channel, 'q' => $_GET['q'] ?? '', 'level' => $_GET['level'] ?? '', 'days' => (int) ($_GET['days'] ?? 0)], $page, $perPage)
        + ['stats' => seo_error_log_stats(), 'can_clear' => can('settings', 'edit')]);
});

route('POST', '/integrations/errors/clear', function () {
    api_require('settings', 'edit');
    $channel = in_array(api_param('channel', 'client'), ['client', 'server', 'all'], true) ? api_str(api_param('channel', 'client')) : 'client';
    $days = max(0, (int) api_param('older_than_days', 0));
    $where = seo_error_log_where($channel) . ($days > 0 ? ' AND created_at < NOW() - INTERVAL ' . min(3650, $days) . ' DAY' : '');
    $n = db_exec('DELETE FROM system_logs WHERE ' . $where);
    $label = ['client' => 'website JavaScript', 'server' => 'server', 'all' => 'all'][$channel];
    log_activity('delete', 'settings', null, "Cleared $n $label error log entr" . ($n === 1 ? 'y' : 'ies') . ($days ? " older than $days days" : ''));
    api_ok(['deleted' => $n], $n ? "$n log entr" . ($n === 1 ? 'y' : 'ies') . ' removed.' : 'Nothing to remove.');
});

/* ------------------------------------------------------------------ robots + sitemaps */

route('GET', '/integrations/robots', function () {
    api_require('seo', 'view');
    api_ok(['served' => seo_robots_txt(), 'default' => rtrim(seo_default_robots_txt()), 'is_custom' => trim((string) setting('robots_txt', '')) !== '',
        'blocked' => setting('seo_block_indexing', '0') === '1', 'robots_url' => absolute_url('robots.txt')]);
});

route('GET', '/integrations/sitemaps', function () {
    api_require('seo', 'view');
    $labels = array_map(fn ($d) => $d[0], seo_sitemap_group_defs()) + ['images' => 'Images'];
    $children = [];
    foreach (seo_sitemap_children() as $name => $c) {
        $children[] = ['name' => $name, 'label' => $labels[$name] ?? ucfirst($name)] + $c;
    }
    api_ok(['index_url' => absolute_url('sitemap.xml'), 'children' => $children, 'blocked' => setting('seo_block_indexing', '0') === '1',
        'images_enabled' => setting('sitemap_images', '1') === '1']);
});

route('POST', '/integrations/sitemaps/rebuild', function () {
    api_require('seo', 'edit');
    seo_sitemap_cache_clear();
    $xml = seo_sitemap_index_xml();
    foreach (array_keys(seo_sitemap_children()) as $name) {
        seo_sitemap_xml($name);
    }
    $count = substr_count($xml, '<sitemap>');
    log_activity('update', 'seo', 'sitemap', "Rebuilt the XML sitemaps ($count child sitemaps)");
    api_ok(['sitemaps' => $count], 'Sitemaps rebuilt.');
});

/* ------------------------------------------------------------------ SEO audit */

function integrations_audit_or_404(int $id): array
{
    $a = seo_audit_status($id);
    if (!$a) {
        api_error('This audit no longer exists.', 404);
    }
    return $a;
}

route('GET', '/integrations/seo-audit', function () {
    api_require('seo', 'view');
    $active = seo_audit_active();
    $latestId = $active['id'] ?? db_value("SELECT id FROM seo_audits ORDER BY id DESC LIMIT 1");
    api_ok(['current' => $latestId ? seo_audit_status((int) $latestId) : null, 'history' => seo_audit_history(10),
        'issues' => array_map(fn ($d) => ['label' => $d[0], 'severity' => $d[1]], seo_audit_issue_defs()),
        'mode' => seo_audit_mode(), 'can' => ['run' => can('seo', 'edit')], 'site_url' => absolute_url('')]);
});

route('POST', '/integrations/seo-audit', function () {
    api_require('seo', 'edit');
    if (!function_exists('curl_init')) {
        api_error('The SEO audit needs the PHP curl extension on the server.', 422);
    }
    $max = (int) api_param('max_pages', 250);
    if ($max < 20 || $max > 1000) {
        api_error('Choose between 20 and 1,000 URLs.', 422, ['max_pages' => 'Choose between 20 and 1,000 URLs.']);
    }
    $res = seo_audit_start($max, user_id());
    if (!$res['existing']) {
        log_activity('create', 'seo', $res['audit']['id'], 'Started SEO audit #' . $res['audit']['id'] . " (up to $max URLs)");
    }
    api_ok(seo_audit_status((int) $res['audit']['id']), $res['existing'] ? 'An audit is already running — showing its progress.' : 'SEO audit started.');
});

route('GET', '/integrations/seo-audit/{id:\d+}', function ($p) {
    api_require('seo', 'view');
    api_ok(integrations_audit_or_404((int) $p['id']));
});

route('POST', '/integrations/seo-audit/{id:\d+}/step', function ($p) {
    api_require('seo', 'edit');
    $a = integrations_audit_or_404((int) $p['id']);
    if (!in_array($a['status'], ['running', 'queued'], true)) {
        api_ok($a);
    }
    if (PHP_SAPI === 'cli-server') {
        // Single-threaded development server: it cannot fetch its own pages inside a request. Restart the
        // background crawler when it has stopped, then just report progress.
        if ($a['stale'] || $a['mode'] !== 'worker') {
            db_update('seo_audits', ['mode' => 'worker', 'heartbeat_at' => date('Y-m-d H:i:s')], 'id = ?', [$a['id']]);
            seo_audit_spawn((int) $a['id']);
        }
        api_ok(seo_audit_status((int) $a['id']));
    }
    // A step crawls for ~8 s: release the session lock first, or every other request of this admin (the progress
    // polling, other tabs) would wait for it.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    api_ok(seo_audit_step((int) $a['id'], 8.0));
});

route('POST', '/integrations/seo-audit/{id:\d+}/cancel', function ($p) {
    api_require('seo', 'edit');
    $a = integrations_audit_or_404((int) $p['id']);
    if (in_array($a['status'], ['running', 'queued'], true)) {
        db_update('seo_audits', ['status' => 'cancelled', 'finished_at' => date('Y-m-d H:i:s'), 'message' => 'Cancelled by ' . (current_user()['name'] ?? 'an administrator') . '.'], 'id = ?', [$a['id']]);
        log_activity('update', 'seo', $a['id'], 'Cancelled SEO audit #' . $a['id']);
    }
    api_ok(seo_audit_status((int) $a['id']), 'Audit cancelled.');
});

route('GET', '/integrations/seo-audit/{id:\d+}/pages', function ($p) {
    api_require('seo', 'view');
    integrations_audit_or_404((int) $p['id']);
    [$page, $perPage] = api_pagination(25);
    api_ok(seo_audit_pages((int) $p['id'], $_GET, $page, $perPage));
});

route('GET', '/integrations/seo-audit/{id:\d+}/export', function ($p) {
    api_require('seo', 'view');
    $a = integrations_audit_or_404((int) $p['id']);
    $rows = seo_audit_export_rows((int) $a['id']);
    log_activity('export', 'seo', $a['id'], 'Exported SEO audit #' . $a['id'] . ' (' . count($rows) . ' URLs)');
    csv_download('seo-audit-' . $a['id'] . '-' . date('Y-m-d') . '.csv',
        ['URL', 'Kind', 'Status', 'Redirects to', 'Response (ms)', 'Size (KB)', 'Title', 'Title length', 'Description length', 'H1 count', 'Images without alt',
            'Canonical', 'Robots', 'In sitemap', 'Depth', 'First found on', 'Issues', 'Error'], $rows);
});

route('DELETE', '/integrations/seo-audit/{id:\d+}', function ($p) {
    api_require('seo', 'edit');
    $a = integrations_audit_or_404((int) $p['id']);
    if (in_array($a['status'], ['running', 'queued'], true) && !$a['stale']) {
        api_error('Cancel the running audit before deleting it.', 409);
    }
    db_delete('seo_audits', 'id = ?', [$a['id']]);
    log_activity('delete', 'seo', $a['id'], 'Deleted SEO audit #' . $a['id']);
    api_ok(null, 'Audit deleted.');
});
