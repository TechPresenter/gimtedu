<?php
/**
 * Activity log endpoints (list/export use /api/crud/activity_logs).
 *   GET  /api/activity-logs/stats               KPIs, 14-day series, top modules and users
 *   GET  /api/activity-logs/facets              modules / actions present in the log (for filters)
 *   GET  /api/activity-logs/{id}                full entry incl. meta JSON
 *   GET  /api/activity-logs/purge-preview?days=180
 *   POST /api/activity-logs/purge               {days}   (activity_logs delete permission)
 */
require_once APP_ROOT . '/app/services/system.php';

route('GET', '/activity-logs/stats', function () {
    api_require('activity_logs', 'view');
    $k = db_row("SELECT COALESCE(SUM(created_at >= CURDATE()), 0) today,
                        COALESCE(SUM(created_at >= CURDATE() - INTERVAL 1 DAY AND created_at < CURDATE()), 0) yesterday,
                        COALESCE(SUM(created_at >= NOW() - INTERVAL 7 DAY), 0) week,
                        COALESCE(SUM(status <> 'success' AND created_at >= NOW() - INTERVAL 7 DAY), 0) failed_week,
                        COUNT(DISTINCT CASE WHEN created_at >= CURDATE() THEN user_id END) users_today,
                        COUNT(*) total, MIN(created_at) oldest
                 FROM activity_logs");
    $labels = activity_module_labels();
    $series = system_daily_series('activity_logs', ['Successful' => ['success'], 'Failed / denied' => ['failed', 'denied', 'error']], 14);
    $modules = db_all("SELECT module, COUNT(*) c FROM activity_logs WHERE created_at >= NOW() - INTERVAL 30 DAY GROUP BY module ORDER BY c DESC LIMIT 7");
    $modules = array_map(fn ($m) => ['module' => $m['module'], 'label' => $labels[$m['module']] ?? label_from_key($m['module']), 'count' => (int) $m['c']], $modules);
    $users = db_all("SELECT a.user_id, u.name, u.avatar, u.designation, COUNT(*) c FROM activity_logs a JOIN users u ON u.id = a.user_id
                     WHERE a.created_at >= NOW() - INTERVAL 30 DAY GROUP BY a.user_id, u.name, u.avatar, u.designation ORDER BY c DESC LIMIT 5");
    $users = array_map(fn ($u) => ['id' => (int) $u['user_id'], 'name' => $u['name'], 'avatar' => $u['avatar'], 'designation' => $u['designation'], 'count' => (int) $u['c']], $users);
    api_ok([
        'today' => (int) $k['today'], 'yesterday' => (int) $k['yesterday'], 'week' => (int) $k['week'], 'failed_week' => (int) $k['failed_week'],
        'users_today' => (int) $k['users_today'], 'total' => (int) $k['total'], 'oldest' => $k['oldest'],
        'series' => $series, 'modules' => $modules, 'top_users' => $users,
    ]);
});

route('GET', '/activity-logs/facets', function () {
    api_require('activity_logs', 'view');
    $labels = activity_module_labels();
    $modules = array_map(fn ($m) => ['value' => $m, 'label' => $labels[$m] ?? label_from_key($m)], db_column('SELECT DISTINCT module FROM activity_logs ORDER BY module'));
    usort($modules, fn ($a, $b) => strcmp($a['label'], $b['label']));
    $actions = array_map(fn ($a) => ['value' => $a, 'label' => label_from_key($a)], db_column('SELECT DISTINCT action FROM activity_logs ORDER BY action'));
    api_ok(['modules' => $modules, 'actions' => $actions]);
});

route('GET', '/activity-logs/purge-preview', function () {
    api_require('activity_logs', 'delete');
    $days = max(1, (int) ($_GET['days'] ?? 180));
    $cutoff = date('Y-m-d 00:00:00', strtotime("-$days days"));
    api_ok(['days' => $days, 'cutoff' => $cutoff, 'count' => (int) db_value('SELECT COUNT(*) FROM activity_logs WHERE created_at < ?', [$cutoff])]);
});

route('POST', '/activity-logs/purge', function () {
    api_require('activity_logs', 'delete');
    $in = api_validate(['days' => 'required|integer|min:7|max:3650'], null, ['days' => 'Days']);
    $days = (int) $in['days'];
    $cutoff = date('Y-m-d 00:00:00', strtotime("-$days days"));
    $total = 0;
    do {
        $n = db_exec('DELETE FROM activity_logs WHERE created_at < ? ORDER BY id LIMIT 5000', [$cutoff]);
        $total += $n;
    } while ($n === 5000);
    log_activity('purge', 'activity_logs', null, sprintf('Purged %s activity log entries older than %d days (before %s)', number_format($total), $days, format_date($cutoff)), 'success',
        ['days' => $days, 'cutoff' => $cutoff, 'deleted' => $total]);
    api_ok(['deleted' => $total, 'cutoff' => $cutoff], $total ? number_format($total) . ' log entries older than ' . format_date($cutoff) . ' were deleted.' : 'There were no entries older than ' . format_date($cutoff) . '.');
});

route('GET', '/activity-logs/{id:\d+}', function ($p) {
    api_require('activity_logs', 'view');
    $row = db_row('SELECT a.*, u.name AS user_name, u.email AS user_email, u.avatar AS user_avatar, u.designation AS user_designation
                   FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.id = ?', [(int) $p['id']]);
    if (!$row) {
        api_error('Log entry not found. It may have been purged.', 404);
    }
    $labels = activity_module_labels();
    $row['id'] = (int) $row['id'];
    $row['meta'] = $row['meta'] ? json_decode($row['meta'], true) : null;
    $row['browser'] = browser_label($row['user_agent']);
    $row['module_label'] = $labels[$row['module']] ?? label_from_key($row['module']);
    $row['related_count'] = $row['record_id'] !== null
        ? (int) db_value('SELECT COUNT(*) FROM activity_logs WHERE module = ? AND record_id = ? AND id <> ?', [$row['module'], $row['record_id'], $row['id']]) : 0;
    api_ok($row);
});
