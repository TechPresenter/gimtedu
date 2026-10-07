<?php
/**
 * Backup & restore.
 *   GET    /api/backup/overview                 health card, stats, schedule, scheduled-task URL (list = /api/crud/backups)
 *   POST   /api/backup/create                   {type: database|files, notes?}
 *   GET    /api/backup/{id}                     one backup incl. table list (for the restore dialog)
 *   GET    /api/backup/{id}/download            authenticated file stream
 *   POST   /api/backup/{id}/verify              integrity check (size, SHA-256, archive)
 *   POST   /api/backup/{id}/restore             {confirm: "RESTORE", tables?: [], safety_backup?: bool}
 *   DELETE /api/backup/{id}
 *   POST   /api/backup/settings                 {auto_backup, backup_retention}
 *   POST   /api/backup/run-scheduled            run the scheduled backup now when due
 *   GET|POST /api/backup/cron?token=…           for a server cron job (no session, token derived from app key)
 */
require_once APP_ROOT . '/app/services/backup.php';

function backup_target(int $id): array
{
    $b = backup_find($id);
    if (!$b) {
        api_error('Backup not found. It may have been deleted.', 404);
    }
    return $b;
}

route('GET', '/backup/overview', function () {
    api_require('backup', 'view');
    backup_reap_stale();
    $last = backup_public_row(db_row('SELECT b.*, u.name AS created_by_name FROM backups b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.created_at DESC, b.id DESC LIMIT 1'));
    $lastOk = backup_public_row(db_row("SELECT b.*, u.name AS created_by_name FROM backups b LEFT JOIN users u ON u.id = b.created_by WHERE b.status = 'completed' AND b.type = 'database' ORDER BY b.created_at DESC, b.id DESC LIMIT 1"));
    $lastFiles = db_row("SELECT created_at, size_bytes FROM backups WHERE status = 'completed' AND type = 'files' ORDER BY created_at DESC LIMIT 1");
    $totals = db_row("SELECT COUNT(*) total, COALESCE(SUM(status = 'completed'), 0) completed, COALESCE(SUM(status = 'failed' AND created_at >= NOW() - INTERVAL 30 DAY), 0) failed_30d,
                             COALESCE(SUM(CASE WHEN status = 'completed' THEN size_bytes ELSE 0 END), 0) bytes, COALESCE(SUM(restore_count), 0) restores
                      FROM backups");
    $ageDays = $lastOk ? (time() - strtotime($lastOk['created_at'])) / 86400 : null;
    $interval = backup_interval_seconds();
    $health = $last && $last['status'] === 'failed' ? 'failed' : ($ageDays === null ? 'none' : ($ageDays <= max(7, ($interval ?? 0) / 86400 + 1) ? 'healthy' : 'stale'));
    $dir = backup_dir();
    $free = @disk_free_space($dir);
    $data = [
        'health' => $health, 'last' => $last, 'last_success' => $lastOk, 'last_files' => $lastFiles, 'age_days' => $ageDays !== null ? round($ageDays, 1) : null,
        'totals' => array_map('intval', $totals),
        'schedule' => ['auto_backup' => setting('auto_backup', 'off'), 'backup_retention' => (int) setting('backup_retention', 15), 'next_due' => backup_next_due(),
            'last_cron' => setting('_backup_last_cron')],
        'database' => backup_database_stats(),
        'uploads' => backup_uploads_stats(),
        'storage' => ['path' => 'storage/backups', 'writable' => is_writable($dir), 'free_bytes' => $free !== false ? (int) $free : null, 'zip' => class_exists('ZipArchive'), 'zlib' => function_exists('gzopen')],
        'can' => ['create' => can('backup', 'create'), 'delete' => can('backup', 'delete'), 'manage' => can('backup', 'manage')],
    ];
    if (can('backup', 'manage')) {
        $url = absolute_url('api/backup/cron?token=' . backup_cron_token());
        $data['cron'] = ['url' => $url, 'command' => '0 2 * * * curl -fsS "' . $url . '" > /dev/null', 'windows' => 'schtasks /Create /SC DAILY /ST 02:00 /TN "GIMT Backup" /TR "curl -fsS ' . $url . '"'];
    }
    api_ok($data);
});

route('POST', '/backup/create', function () {
    api_require('backup', 'create');
    $in = api_validate(['type' => 'required|in:database,files', 'notes' => 'max:255'], null, ['type' => 'Backup type']);
    if (!rate_limit('backup-create:' . user_id(), 6, 300)) {
        api_error('Too many backups in a short time. Please wait a few minutes.', 429);
    }
    if (db_value("SELECT COUNT(*) FROM backups WHERE status = 'running' AND created_at > NOW() - INTERVAL 10 MINUTE")) {
        api_error('Another backup is already running. Please wait for it to finish.', 409);
    }
    $b = backup_create($in['type'], 'manual', $in['notes']);
    if ($b['status'] !== 'completed') {
        api_error('Backup failed: ' . ($b['error'] ?? 'unknown error'), 500);
    }
    $what = $b['type'] === 'files' ? number_format($b['meta']['files'] ?? 0) . ' files' : ($b['meta']['tables'] ?? 0) . ' tables, ' . number_format($b['meta']['rows'] ?? 0) . ' rows';
    api_ok($b, ($b['type'] === 'files' ? 'Files' : 'Database') . " backup created — $what, " . human_filesize($b['size_bytes']) . '.', 201);
});

route('POST', '/backup/settings', function () {
    api_require('backup', 'manage');
    $in = api_validate(['auto_backup' => 'required|in:off,daily,weekly,monthly', 'backup_retention' => 'required|integer|min:0|max:3650'],
        null, ['auto_backup' => 'Automatic backup', 'backup_retention' => 'Retention']);
    save_setting('auto_backup', $in['auto_backup'], 'backup');
    save_setting('backup_retention', (string) (int) $in['backup_retention'], 'backup');
    log_activity('update', 'settings', null, 'Updated backup schedule: ' . $in['auto_backup'] . ', keep ' . ((int) $in['backup_retention'] ?: 'forever') . ((int) $in['backup_retention'] ? ' days' : ''), 'success', ['group' => 'backup']);
    api_ok(['auto_backup' => $in['auto_backup'], 'backup_retention' => (int) $in['backup_retention'], 'next_due' => backup_next_due()], 'Backup schedule saved.');
});

route('POST', '/backup/run-scheduled', function () {
    api_require('backup', 'create');
    if (setting('auto_backup', 'off') === 'off') {
        api_error('Automatic backups are switched off.', 422);
    }
    $b = backup_run_due();
    if (!$b) {
        api_ok(['ran' => false, 'next_due' => backup_next_due()], 'No backup is due yet. Next scheduled backup: ' . format_datetime(backup_next_due()) . '.');
    }
    if ($b['status'] !== 'completed') {
        api_error('Scheduled backup failed: ' . ($b['error'] ?? 'unknown error'), 500);
    }
    api_ok(['ran' => true, 'backup' => $b, 'next_due' => backup_next_due()], 'Scheduled backup created (' . human_filesize($b['size_bytes']) . ').');
});

$cron = function () {
    $token = (string) ($_GET['token'] ?? '');
    if ($token === '' || !hash_equals(backup_cron_token(), $token)) {
        api_error('Invalid token.', 403);
    }
    if (!rate_limit('backup-cron:' . client_ip(), 12, 3600)) {
        api_error('Too many requests.', 429);
    }
    backup_reap_stale();
    $b = backup_run_due();
    $pruned = backup_prune();
    api_ok(['ran' => (bool) $b, 'status' => $b['status'] ?? null, 'filename' => $b['filename'] ?? null, 'pruned' => $pruned, 'next_due' => backup_next_due()],
        $b ? 'Scheduled backup ' . $b['status'] . '.' : 'No backup due.');
};
route('GET', '/backup/cron', $cron, ['auth' => false, 'csrf' => false]);
route('POST', '/backup/cron', $cron, ['auth' => false, 'csrf' => false]);

route('GET', '/backup/{id:\d+}', function ($p) {
    api_require('backup', 'view');
    $b = backup_public_row(db_row('SELECT b.*, u.name AS created_by_name, ru.name AS restored_by_name FROM backups b LEFT JOIN users u ON u.id = b.created_by
                                   LEFT JOIN users ru ON ru.id = b.restored_by WHERE b.id = ?', [(int) $p['id']]));
    if (!$b) {
        api_error('Backup not found. It may have been deleted.', 404);
    }
    $tables = [];
    foreach ((array) ($b['meta']['table_rows'] ?? []) as $name => $rows) {
        $tables[] = ['name' => $name, 'rows' => (int) $rows, 'restorable' => !in_array($name, BACKUP_RESTORE_EXCLUDE, true)];
    }
    $b['tables'] = $tables;
    api_ok($b);
});

route('GET', '/backup/{id:\d+}/download', function ($p) {
    api_require('backup', 'create');
    $b = backup_target((int) $p['id']);
    $path = backup_file_path($b);
    if ($b['status'] !== 'completed' || !is_file($path)) {
        api_error('This backup file is not available for download.', 404);
    }
    log_activity('download', 'backup', $b['id'], 'Downloaded backup ' . $b['filename']);
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: ' . ($b['type'] === 'files' ? 'application/zip' : 'application/gzip'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . basename($b['filename']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    readfile($path);
    exit;
});

route('POST', '/backup/{id:\d+}/verify', function ($p) {
    api_require('backup', 'view');
    $b = backup_target((int) $p['id']);
    $res = backup_verify($b);
    log_activity('verify', 'backup', $b['id'], 'Verified backup ' . $b['filename'] . ': ' . ($res['ok'] ? 'OK' : 'problems found'), $res['ok'] ? 'success' : 'failed');
    api_ok($res, $res['ok'] ? 'Backup verified — the file is intact and readable.' : 'Verification found problems with this backup.');
});

route('POST', '/backup/{id:\d+}/restore', function ($p) {
    api_require('backup', 'manage');
    $in = api_input();
    if (trim((string) ($in['confirm'] ?? '')) !== 'RESTORE') {
        api_error('Type RESTORE to confirm.', 422, ['confirm' => 'Type RESTORE (in capitals) to confirm.']);
    }
    $b = backup_target((int) $p['id']);
    if (db_value("SELECT COUNT(*) FROM backups WHERE status = 'running' AND created_at > NOW() - INTERVAL 10 MINUTE")) {
        api_error('A backup is running. Wait for it to finish before restoring.', 409);
    }
    $result = backup_restore($b, ['tables' => $in['tables'] ?? null, 'safety_backup' => !array_key_exists('safety_backup', $in) || !empty($in['safety_backup'])]);
    $msg = $result['type'] === 'files'
        ? 'Restored ' . number_format($result['files']) . ' files from ' . $b['filename'] . '.'
        : 'Restore complete — ' . $result['tables'] . ' table(s), ' . number_format($result['rows']) . ' rows from ' . $b['filename'] . '.';
    api_ok($result, $msg);
});

route('DELETE', '/backup/{id:\d+}', function ($p) {
    api_require('backup', 'delete');
    $b = backup_target((int) $p['id']);
    if ($b['status'] === 'running') {
        api_error('This backup is still running and cannot be deleted yet.', 409);
    }
    backup_delete($b);
    api_ok(null, 'Backup ' . $b['filename'] . ' deleted.');
});
