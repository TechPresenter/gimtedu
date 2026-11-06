<?php
/**
 * Redirects manager API (Website CMS → Redirects, unit seo_platform). Rules themselves are CRUD: /api/crud/redirects
 * (list, create, edit, delete, bulk delete, CSV/Excel export, CSV import — app/modules/redirects.php).
 *
 *   GET    /api/redirects/summary            KPIs (rules by type, hits, 404s) + warnings (missing targets, chains)
 *   POST   /api/redirects/check              {from_path, to_url, status_code, id?} → normalised values, errors, notes
 *                                            (live validation for the form: loops, chains, live pages, missing targets)
 *   POST   /api/redirects/test               {url} → every hop (admin rules, lower-case, trailing slash) + final status
 *   POST   /api/redirects/bulk               {ids[], action: activate|deactivate|reset_hits}
 *   GET    /api/redirects/404s               ?q=&who=humans|bots&sort=hits|last_seen|path&dir=&page=  404 report + suggestions
 *   DELETE /api/redirects/404s/{id}          ignore (remove) one 404 path
 *   POST   /api/redirects/404s/clear         {older_than_days?} remove 404 rows (all, or not seen for N days)
 * Permissions: seo.view for reading, seo.edit for changes. Every change is written to the activity log.
 */
require_once APP_ROOT . '/app/services/seo.php';

route('GET', '/redirects/summary', function () {
    api_require('seo', 'view');
    $r = db_row("SELECT COUNT(*) total, COALESCE(SUM(is_active = 1), 0) active, COALESCE(SUM(is_active = 1 AND status_code IN (301, 308)), 0) permanent,
                 COALESCE(SUM(is_active = 1 AND status_code IN (302, 307)), 0) temporary, COALESCE(SUM(is_active = 1 AND status_code = 410), 0) gone,
                 COALESCE(SUM(hits), 0) hits, COALESCE(SUM(last_hit_at >= NOW() - INTERVAL 30 DAY), 0) used_30d, MAX(last_hit_at) last_hit FROM redirects");
    $n = db_row('SELECT COUNT(*) paths, COALESCE(SUM(hits), 0) hits, COALESCE(SUM(last_seen_at >= NOW() - INTERVAL 7 DAY), 0) recent, MAX(last_seen_at) last_seen FROM seo_404_log');
    // Warnings: active internal targets that do not exist, and chains (a target that is itself redirected)
    $missing = [];
    foreach (db_all("SELECT id, from_path, to_path FROM redirects WHERE is_active = 1 AND status_code <> 410 AND to_path IS NOT NULL ORDER BY id DESC LIMIT 1000") as $row) {
        if (!seo_path_exists((string) $row['to_path'])) {
            $missing[] = ['id' => (int) $row['id'], 'from' => $row['from_path'], 'to' => $row['to_path']];
        }
    }
    $chains = db_all("SELECT a.id, a.from_path AS `from`, a.to_path AS `to`, b.to_url AS final FROM redirects a JOIN redirects b ON b.from_hash = SHA1(LOWER(a.to_path)) AND b.is_active = 1
                      WHERE a.is_active = 1 AND a.status_code <> 410 AND a.to_path IS NOT NULL LIMIT 50");
    api_ok([
        'rules' => array_map('intval', array_intersect_key($r, array_flip(['total', 'active', 'permanent', 'temporary', 'gone', 'hits', 'used_30d']))) + ['last_hit' => $r['last_hit']],
        'not_found' => ['paths' => (int) $n['paths'], 'hits' => (int) $n['hits'], 'recent' => (int) $n['recent'], 'last_seen' => $n['last_seen']],
        'warnings' => ['missing_targets' => array_slice($missing, 0, 50), 'missing_count' => count($missing), 'chains' => $chains],
        'can' => ['edit' => can('seo', 'edit')],
        'site_url' => absolute_url(''),
    ]);
});

route('POST', '/redirects/check', function () {
    api_require('seo', 'view');
    $in = api_input();
    $id = isset($in['id']) && (int) $in['id'] > 0 ? (int) $in['id'] : null;
    $old = $id ? db_row('SELECT * FROM redirects WHERE id = ?', [$id]) : null;
    $r = seo_redirect_prepare($in, $id, $old);
    api_ok(['errors' => $r['errors'], 'notes' => $r['notes'], 'normalized' => [
        'from_path' => $r['data']['from_path'], 'to_url' => $r['data']['to_url'], 'status_code' => $r['data']['status_code'],
        'from_url' => $r['data']['from_path'] ? seo_path_url($r['data']['from_path']) : null,
        'to_full' => $r['data']['to_url'] ? (preg_match('#^https?://#i', (string) $r['data']['to_url']) ? $r['data']['to_url'] : seo_path_url((string) $r['data']['to_url'])) : null,
    ]]);
});

route('POST', '/redirects/test', function () {
    api_require('seo', 'view');
    $url = trim(api_str(api_param('url')));
    if ($url === '') {
        api_error('Enter a URL to test.', 422, ['url' => 'Enter a path such as /old-page or a full URL of this website.']);
    }
    $res = seo_redirect_trace(mb_substr($url, 0, 1000));
    if (!$res['ok']) {
        api_error($res['error'], 422, ['url' => $res['error']]);
    }
    api_ok($res);
});

route('POST', '/redirects/bulk', function () {
    api_require('seo', 'edit');
    $in = api_input();
    $ids = array_values(array_filter(array_map('intval', (array) ($in['ids'] ?? [])), fn ($v) => $v > 0));
    $action = api_str($in['action'] ?? null);
    if (!$ids || !in_array($action, ['activate', 'deactivate', 'reset_hits'], true)) {
        api_error('Select redirects and an action.', 422);
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $n = match ($action) {
        'activate' => db_exec("UPDATE redirects SET is_active = 1, updated_by = ? WHERE id IN ($ph)", array_merge([user_id()], $ids)),
        'deactivate' => db_exec("UPDATE redirects SET is_active = 0, updated_by = ? WHERE id IN ($ph)", array_merge([user_id()], $ids)),
        'reset_hits' => db_exec("UPDATE redirects SET hits = 0, last_hit_at = NULL, updated_at = updated_at WHERE id IN ($ph)", $ids),
    };
    $label = ['activate' => 'Activated', 'deactivate' => 'Deactivated', 'reset_hits' => 'Reset the hit counters of'][$action];
    log_activity($action === 'reset_hits' ? 'update' : $action, 'seo', implode(',', array_slice($ids, 0, 20)), "$label " . count($ids) . ' redirect' . (count($ids) === 1 ? '' : 's'));
    api_ok(['updated' => $n], "$label " . count($ids) . ' redirect' . (count($ids) === 1 ? '' : 's') . '.');
});

route('GET', '/redirects/404s', function () {
    api_require('seo', 'view');
    $where = ['1 = 1'];
    $args = [];
    if (($q = trim(api_str($_GET['q'] ?? null))) !== '') {
        $where[] = '(path LIKE ? OR last_referrer LIKE ?)';
        $args[] = '%' . $q . '%';
        $args[] = '%' . $q . '%';
    }
    $who = api_str($_GET['who'] ?? null);
    if ($who === 'humans') {
        $where[] = 'hits > bot_hits';
    } elseif ($who === 'bots') {
        $where[] = 'bot_hits > 0';
    } elseif ($who === 'internal') {
        $where[] = 'last_referrer LIKE ?';
        $args[] = rtrim(absolute_url(''), '/') . '%';
    }
    $sorts = ['hits' => 'hits', 'last_seen' => 'last_seen_at', 'first_seen' => 'first_seen_at', 'path' => 'path'];
    $sort = $sorts[api_query('sort')] ?? 'last_seen_at';
    $dir = api_query('dir', 'desc') === 'asc' ? 'ASC' : 'DESC';
    [$page, $perPage] = api_pagination(25);
    $sql = ' FROM seo_404_log WHERE ' . implode(' AND ', $where);
    $total = (int) db_value('SELECT COUNT(*)' . $sql, $args);
    $p = paginate($total, $page, $perPage);
    $rows = db_all('SELECT *' . $sql . " ORDER BY $sort $dir, id DESC LIMIT " . (int) $p['per_page'] . ' OFFSET ' . (int) $p['offset'], $args);
    $own = rtrim(absolute_url(''), '/');
    foreach ($rows as &$row) {
        foreach (['id', 'hits', 'bot_hits'] as $k) {
            $row[$k] = (int) $row[$k];
        }
        $row['internal_referrer'] = $row['last_referrer'] && str_starts_with((string) $row['last_referrer'], $own);
        $row['suggestion'] = seo_suggest_target((string) $row['path']);
        $row['url'] = seo_path_url((string) $row['path']);
    }
    unset($row);
    api_ok(['rows' => $rows, 'total' => $p['total'], 'page' => $p['page'], 'per_page' => $p['per_page'], 'pages' => $p['pages']]);
});

route('DELETE', '/redirects/404s/{id:\d+}', function ($p) {
    api_require('seo', 'edit');
    $row = db_row('SELECT * FROM seo_404_log WHERE id = ?', [(int) $p['id']]);
    if (!$row) {
        api_error('This 404 entry no longer exists.', 404);
    }
    db_delete('seo_404_log', 'id = ?', [(int) $p['id']]);
    log_activity('delete', 'seo', (int) $p['id'], 'Removed ' . $row['path'] . ' from the 404 report');
    api_ok(null, $row['path'] . ' removed from the 404 report.');
});

route('POST', '/redirects/404s/clear', function () {
    api_require('seo', 'edit');
    $days = max(0, (int) api_param('older_than_days', 0));
    $n = $days > 0 ? db_exec('DELETE FROM seo_404_log WHERE last_seen_at < NOW() - INTERVAL ' . min(3650, $days) . ' DAY') : db_exec('DELETE FROM seo_404_log');
    log_activity('delete', 'seo', null, $days > 0 ? "Cleared $n 404 entries not seen for $days days" : "Cleared the 404 report ($n entries)");
    api_ok(['deleted' => $n], $n ? "$n 404 entr" . ($n === 1 ? 'y' : 'ies') . ' removed.' : 'Nothing to remove.');
});
