<?php
/**
 * Backup & restore service.
 *
 *   backup_create('database')            pure-PHP SQL dump of every table -> storage/backups/gimt-db-YYYY-mm-dd_His.sql.gz
 *   backup_create('files')               ZIP of assets/uploads           -> storage/backups/gimt-files-YYYY-mm-dd_His.zip
 *   backup_restore($row, ['tables' => [...], 'safety_backup' => true])
 *   backup_verify($row) / backup_prune() / backup_run_due()
 *
 * Dump format (gimt-sql-v1) is a standard MySQL dump (DROP/CREATE/INSERT, one statement per line) that can also be
 * imported with the mysql client or phpMyAdmin. Restores from the admin panel never drop tables: missing tables are
 * created, then every restored table is emptied and refilled inside ONE transaction, so a failure rolls everything back.
 */

const BACKUP_SQL_FORMAT = 'gimt-sql-v1';
const BACKUP_FILES_FORMAT = 'gimt-files-v1';
/** Tables never overwritten by a restore (the backup catalogue must keep matching the files on disk). */
const BACKUP_RESTORE_EXCLUDE = ['backups'];

function backup_dir(): string
{
    $dir = STORAGE_PATH . '/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function backup_file_path(array $backup): string
{
    return backup_dir() . '/' . basename((string) $backup['filename']);
}

function backup_find(int $id): ?array
{
    return db_row('SELECT * FROM backups WHERE id = ?', [$id]);
}

/** Base tables of the current database. */
function backup_db_tables(): array
{
    return db_column("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
}

/** Columns of a table: name => ['type' => data type, 'generated' => bool]. */
function backup_table_columns(string $table): array
{
    $rows = db_all('SELECT COLUMN_NAME, DATA_TYPE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]);
    $out = [];
    foreach ($rows as $r) {
        $out[$r['COLUMN_NAME']] = ['type' => strtolower($r['DATA_TYPE']), 'generated' => (bool) preg_match('/(VIRTUAL|STORED|PERSISTENT) GENERATED/i', (string) $r['EXTRA'])];
    }
    return $out;
}

function backup_primary_key(string $table): array
{
    return db_column("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION", [$table]);
}

function backup_sql_value($value, string $type): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (in_array($type, ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'bit'], true)) {
        return $value === '' ? "''" : '0x' . bin2hex((string) $value);
    }
    if (is_int($value)) {
        return (string) $value;
    }
    if (is_float($value)) {
        return var_export($value, true);
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    return db()->quote((string) $value);
}

/** Size / row statistics of the live database. */
function backup_database_stats(): array
{
    $row = db_row("SELECT COUNT(*) tables, COALESCE(SUM(TABLE_ROWS), 0) row_estimate, COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) bytes
                   FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
    return ['tables' => (int) $row['tables'], 'rows' => (int) $row['row_estimate'], 'bytes' => (int) $row['bytes'], 'name' => (string) db_value('SELECT DATABASE()'),
        'server' => (string) db_value('SELECT VERSION()')];
}

/** Count & size of files under assets/uploads (bounded scan). */
function backup_uploads_stats(int $limit = 20000): array
{
    $files = 0;
    $bytes = 0;
    if (is_dir(UPLOAD_PATH)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(UPLOAD_PATH, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && !in_array($f->getFilename(), ['.htaccess', '.gitkeep'], true)) {
                $files++;
                $bytes += $f->getSize();
                if ($files >= $limit) {
                    break;
                }
            }
        }
    }
    return ['files' => $files, 'bytes' => $bytes];
}

/* ------------------------------------------------------------------
 * Create
 * ------------------------------------------------------------------ */

/**
 * Create a backup and record it in `backups`. Never throws for runtime failures: the row is marked failed instead.
 * @return array the backups row (with decoded meta)
 */
function backup_create(string $type = 'database', string $source = 'manual', ?string $notes = null): array
{
    if (!in_array($type, ['database', 'files'], true)) {
        throw new InvalidArgumentException('Unknown backup type.');
    }
    $userId = function_exists('user_id') ? user_id() : null;
    $stamp = date('Y-m-d_His');
    $base = $type === 'files' ? "gimt-files-$stamp" : "gimt-db-$stamp";
    $ext = $type === 'files' ? '.zip' : '.sql.gz';
    $filename = $base . $ext;
    for ($i = 2; is_file(backup_dir() . '/' . $filename) || db_value('SELECT COUNT(*) FROM backups WHERE filename = ?', [$filename]); $i++) {
        $filename = $base . '-' . $i . $ext;
    }
    $id = db_insert('backups', ['filename' => $filename, 'type' => $type, 'source' => $source, 'status' => 'running', 'notes' => $notes ? mb_substr($notes, 0, 255) : null, 'created_by' => $userId]);
    $path = backup_dir() . '/' . $filename;
    $tmp = $path . '.part';
    $started = microtime(true);
    try {
        if (!is_writable(backup_dir())) {
            throw new RuntimeException('storage/backups is not writable by the web server.');
        }
        @set_time_limit(0);
        $meta = $type === 'files' ? backup_write_files_zip($tmp) : backup_write_sql_dump($tmp);
        if (!@rename($tmp, $path)) {
            throw new RuntimeException('Could not finalise the backup file.');
        }
        $size = (int) filesize($path);
        $meta += ['duration_ms' => (int) round((microtime(true) - $started) * 1000), 'php' => PHP_VERSION, 'app_version' => APP_VERSION];
        db_update('backups', ['status' => 'completed', 'size_bytes' => $size, 'checksum' => hash_file('sha256', $path), 'meta' => json_encode($meta, JSON_UNESCAPED_SLASHES)], 'id = ?', [$id]);
        $what = $type === 'files' ? number_format($meta['files'] ?? 0) . ' files' : ($meta['tables'] ?? 0) . ' tables, ' . number_format($meta['rows'] ?? 0) . ' rows';
        $label = ucfirst((['auto' => 'scheduled ', 'pre-restore' => 'safety ', 'seed' => 'initial '][$source] ?? '') . ($type === 'files' ? 'files' : 'database') . ' backup');
        log_activity('create', 'backup', $id, "$label $filename created ($what, " . human_filesize($size) . ')', 'success',
            ['source' => $source, 'size_bytes' => $size, 'duration_ms' => $meta['duration_ms']]);
        if ($source !== 'pre-restore') {
            backup_prune();
        }
    } catch (Throwable $e) {
        @unlink($tmp);
        db_update('backups', ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'meta' => json_encode(['duration_ms' => (int) round((microtime(true) - $started) * 1000)])], 'id = ?', [$id]);
        log_activity('create', 'backup', $id, "Backup $filename failed: " . mb_substr($e->getMessage(), 0, 300), 'failed');
        log_system('error', 'Backup failed', ['backup_id' => $id, 'error' => $e->getMessage()]);
        notify('perm:backup', 'system', 'Backup failed', "The $type backup $filename could not be created: " . mb_substr($e->getMessage(), 0, 200), 'admin/backup', 'database');
    }
    return backup_public_row(backup_find($id));
}

/** Write a gzip-compressed SQL dump of every base table. Returns meta (tables, rows, per-table counts). */
function backup_write_sql_dump(string $path): array
{
    $gz = @gzopen($path, 'wb6');
    if (!$gz) {
        throw new RuntimeException('Cannot create the backup file in storage/backups.');
    }
    $tables = backup_db_tables();
    $tz = (string) db_value('SELECT @@session.time_zone');
    $header = "-- GIMT SmartCampus database backup\n-- Format: " . BACKUP_SQL_FORMAT . "\n-- Generated: " . date('c') . "\n-- Database: " . db_value('SELECT DATABASE()')
        . "\n-- Server: " . db_value('SELECT VERSION()') . "\n-- Application: " . APP_VERSION . "\n-- Tables: " . count($tables) . "\n\n"
        . "SET NAMES utf8mb4;\nSET time_zone = " . db()->quote($tz) . ";\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
    gzwrite($gz, $header);
    $counts = [];
    $total = 0;
    $maxStatement = 256 * 1024;
    foreach ($tables as $table) {
        $q = db_quote_ident($table);
        $create = (string) (db_row("SHOW CREATE TABLE $q")['Create Table'] ?? '');
        if ($create === '') {
            continue;
        }
        gzwrite($gz, "\n-- @table `$table`\nDROP TABLE IF EXISTS $q;\n" . preg_replace('/\s*\n\s*/', ' ', $create) . ";\n");
        $cols = array_filter(backup_table_columns($table), fn ($c) => !$c['generated']);
        $colNames = array_keys($cols);
        $colSql = implode(', ', array_map('db_quote_ident', $colNames));
        $pk = backup_primary_key($table);
        $keyset = count($pk) === 1 && in_array($cols[$pk[0]]['type'] ?? '', ['int', 'bigint', 'smallint', 'mediumint', 'tinyint'], true);
        $orderBy = $pk ? implode(', ', array_map('db_quote_ident', $pk)) : '1';
        $n = 0;
        $last = null;
        $offset = 0;
        $buffer = '';
        $prefix = "INSERT INTO `$table` ($colSql) VALUES ";
        while (true) {
            if ($keyset) {
                $sql = "SELECT $colSql FROM $q" . ($last !== null ? ' WHERE ' . db_quote_ident($pk[0]) . ' > ?' : '') . ' ORDER BY ' . db_quote_ident($pk[0]) . ' LIMIT 1000';
                $rows = db_all($sql, $last !== null ? [$last] : []);
            } else {
                $rows = db_all("SELECT $colSql FROM $q ORDER BY $orderBy LIMIT 1000 OFFSET $offset");
                $offset += 1000;
            }
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $vals = [];
                foreach ($colNames as $c) {
                    $vals[] = backup_sql_value($row[$c], $cols[$c]['type']);
                }
                $tuple = '(' . implode(',', $vals) . ')';
                if ($buffer !== '' && strlen($buffer) + strlen($tuple) > $maxStatement) {
                    gzwrite($gz, $prefix . $buffer . ";\n");
                    $buffer = '';
                }
                $buffer .= ($buffer === '' ? '' : ',') . $tuple;
                $n++;
            }
            if ($keyset) {
                $last = end($rows)[$pk[0]];
            }
            if (count($rows) < 1000) {
                break;
            }
        }
        if ($buffer !== '') {
            gzwrite($gz, $prefix . $buffer . ";\n");
        }
        $counts[$table] = $n;
        $total += $n;
    }
    gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- @end tables=" . count($counts) . " rows=$total\n");
    gzclose($gz);
    return ['format' => BACKUP_SQL_FORMAT, 'tables' => count($counts), 'rows' => $total, 'table_rows' => $counts, 'database' => (string) db_value('SELECT DATABASE()'),
        'server' => (string) db_value('SELECT VERSION()')];
}

/** ZIP every file under assets/uploads (scripts and .htaccess excluded). */
function backup_write_files_zip(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP zip extension is not installed on this server.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create the ZIP archive in storage/backups.');
    }
    $files = 0;
    $bytes = 0;
    if (is_dir(UPLOAD_PATH)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(UPLOAD_PATH, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || in_array($f->getFilename(), ['.htaccess', '.gitkeep'], true) || preg_match('/\.(php\d?|phtml|phar|pl|py|cgi|sh)$/i', $f->getFilename())) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(UPLOAD_PATH) + 1));
            $zip->addFile($f->getPathname(), 'uploads/' . $rel);
            $files++;
            $bytes += $f->getSize();
        }
    }
    $manifest = ['format' => BACKUP_FILES_FORMAT, 'generated' => date('c'), 'root' => 'assets/uploads', 'files' => $files, 'bytes' => $bytes, 'application' => APP_VERSION];
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $zip->setArchiveComment('GIMT SmartCampus files backup (' . BACKUP_FILES_FORMAT . ')');
    if (!$zip->close()) {
        throw new RuntimeException('Writing the ZIP archive failed.');
    }
    return ['format' => BACKUP_FILES_FORMAT, 'files' => $files, 'bytes' => $bytes];
}

/* ------------------------------------------------------------------
 * Inspect / verify
 * ------------------------------------------------------------------ */

/** Decode meta + add display helpers. */
function backup_public_row(?array $b): ?array
{
    if (!$b) {
        return null;
    }
    $b['id'] = (int) $b['id'];
    $b['size_bytes'] = (int) $b['size_bytes'];
    $b['restore_count'] = (int) ($b['restore_count'] ?? 0);
    $b['meta'] = is_string($b['meta'] ?? null) ? (json_decode($b['meta'], true) ?: null) : ($b['meta'] ?? null);
    $b['file_exists'] = is_file(backup_file_path($b));
    return $b;
}

/**
 * Read a SQL dump: header, per-table CREATE statement, column list and INSERT count.
 * @return array{format:?string, time_zone:?string, tables: array<string, array{create:?string, columns:array, statements:int}>}
 */
function backup_scan_dump(string $path): array
{
    $gz = @gzopen($path, 'rb');
    if (!$gz) {
        throw new CrudException('The backup file cannot be opened.');
    }
    $out = ['format' => null, 'time_zone' => null, 'tables' => [], 'complete' => false];
    $current = null;
    $lineNo = 0;
    while (($line = gzgets($gz)) !== false) {
        $lineNo++;
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            continue;
        }
        if ($lineNo < 12 && preg_match('/^-- Format: (\S+)/', $line, $m)) {
            $out['format'] = $m[1];
        } elseif (preg_match("/^SET time_zone = '([^']+)';$/", $line, $m)) {
            $out['time_zone'] = $m[1];
        } elseif (str_starts_with($line, '-- @table ')) {
            $current = trim(substr($line, 10), '` ');
            $out['tables'][$current] = ['create' => null, 'columns' => [], 'statements' => 0];
        } elseif (str_starts_with($line, '-- @end')) {
            $out['complete'] = true;
        } elseif ($current !== null && str_starts_with($line, 'CREATE TABLE ')) {
            $out['tables'][$current]['create'] = rtrim($line, ';');
        } elseif ($current !== null && str_starts_with($line, 'INSERT INTO ')) {
            if (!$out['tables'][$current]['columns'] && preg_match('/^INSERT INTO `[^`]+` \(([^)]*)\) VALUES /', $line, $m)) {
                $out['tables'][$current]['columns'] = array_map(fn ($c) => trim($c, '` '), explode(',', $m[1]));
            }
            $out['tables'][$current]['statements']++;
        }
    }
    gzclose($gz);
    return $out;
}

/** Integrity check: file present, size + SHA-256 match, archive readable. */
function backup_verify(array $b): array
{
    $path = backup_file_path($b);
    $checks = [];
    $exists = is_file($path);
    $checks[] = ['label' => 'File present in storage/backups', 'ok' => $exists];
    if ($exists) {
        $size = (int) filesize($path);
        $checks[] = ['label' => 'Size matches catalogue (' . human_filesize((int) $b['size_bytes']) . ')', 'ok' => $size === (int) $b['size_bytes']];
        if (!empty($b['checksum'])) {
            $checks[] = ['label' => 'SHA-256 checksum matches', 'ok' => hash_equals((string) $b['checksum'], hash_file('sha256', $path))];
        }
        if ($b['type'] === 'files') {
            $zip = new ZipArchive();
            $ok = $zip->open($path, ZipArchive::RDONLY) === true;
            $manifest = $ok ? json_decode((string) $zip->getFromName('manifest.json'), true) : null;
            $checks[] = ['label' => 'ZIP archive readable' . ($ok ? ' (' . number_format($zip->numFiles) . ' entries)' : ''), 'ok' => $ok];
            $checks[] = ['label' => 'Manifest found', 'ok' => is_array($manifest) && ($manifest['format'] ?? '') === BACKUP_FILES_FORMAT];
            if ($ok) {
                $zip->close();
            }
        } else {
            try {
                $scan = backup_scan_dump($path);
                $checks[] = ['label' => 'Recognised GIMT dump format', 'ok' => $scan['format'] === BACKUP_SQL_FORMAT];
                $checks[] = ['label' => 'Dump complete (' . count($scan['tables']) . ' tables)', 'ok' => $scan['complete'] && count($scan['tables']) > 0];
            } catch (Throwable $e) {
                $checks[] = ['label' => 'Archive readable', 'ok' => false];
            }
        }
    }
    $ok = !in_array(false, array_column($checks, 'ok'), true);
    return ['ok' => $ok, 'checks' => $checks];
}

/* ------------------------------------------------------------------
 * Restore
 * ------------------------------------------------------------------ */

/**
 * Restore a completed backup.
 * $opts: tables => [names] (null/empty = every table in the dump), safety_backup => bool (snapshot first)
 * @throws CrudException with a user-facing message; the database is untouched when it fails.
 */
function backup_restore(array $b, array $opts = []): array
{
    if ($b['status'] !== 'completed') {
        throw new CrudException('Only completed backups can be restored.');
    }
    $path = backup_file_path($b);
    if (!is_file($path)) {
        throw new CrudException('The backup file is missing from storage/backups, so it cannot be restored.');
    }
    if (!empty($b['checksum']) && !hash_equals((string) $b['checksum'], hash_file('sha256', $path))) {
        throw new CrudException('Checksum mismatch — the backup file is damaged or was modified. Restore aborted.');
    }
    @set_time_limit(0);
    $started = microtime(true);
    if ($b['type'] === 'files') {
        return backup_restore_files($b, $path, $started);
    }
    $scan = backup_scan_dump($path);
    if ($scan['format'] !== BACKUP_SQL_FORMAT || !$scan['complete']) {
        throw new CrudException('This file is not a complete GIMT SmartCampus database backup.');
    }
    $targets = array_values(array_diff(array_keys($scan['tables']), BACKUP_RESTORE_EXCLUDE));
    $selected = array_values(array_filter((array) ($opts['tables'] ?? []), 'is_string'));
    if ($selected) {
        $unknown = array_diff($selected, $targets);
        if ($unknown) {
            throw new CrudException('These tables are not in the backup or cannot be restored: ' . implode(', ', $unknown) . '.');
        }
        $targets = $selected;
    }
    if (!$targets) {
        throw new CrudException('There is nothing to restore in this backup.');
    }
    // Pre-flight: every column in the dump must still exist (otherwise abort before changing anything).
    $live = backup_db_tables();
    foreach ($targets as $t) {
        if (!in_array($t, $live, true)) {
            if (empty($scan['tables'][$t]['create'])) {
                throw new CrudException("Table $t is missing and the backup has no definition for it.");
            }
            continue;
        }
        $missing = array_diff($scan['tables'][$t]['columns'], array_keys(backup_table_columns($t)));
        if ($missing) {
            throw new CrudException("Table $t no longer has the column(s) " . implode(', ', $missing) . ' found in the backup. Restore aborted before any change was made.');
        }
    }
    $safety = null;
    if (!empty($opts['safety_backup'])) {
        $safety = backup_create('database', 'pre-restore', 'Automatic snapshot before restoring ' . $b['filename']);
        if ($safety['status'] !== 'completed') {
            throw new CrudException('The safety snapshot could not be created, so the restore was not started: ' . ($safety['error'] ?? 'unknown error'));
        }
    }
    $pdo = db();
    $originalTz = (string) db_value('SELECT @@session.time_zone');
    $rows = 0;
    $statements = 0;
    $targetSet = array_flip($targets);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($targets as $t) {
        if (!in_array($t, $live, true)) {
            $pdo->exec((string) $scan['tables'][$t]['create']); // DDL runs before the transaction (implicit commit)
        }
    }
    if ($scan['time_zone']) {
        $pdo->exec('SET time_zone = ' . $pdo->quote($scan['time_zone']));
    }
    try {
        $pdo->beginTransaction();
        foreach ($targets as $t) {
            $pdo->exec('DELETE FROM ' . db_quote_ident($t));
        }
        $gz = gzopen($path, 'rb');
        $current = null;
        while (($line = gzgets($gz)) !== false) {
            if (str_starts_with($line, '-- @table ')) {
                $current = trim(substr(rtrim($line, "\r\n"), 10), '` ');
                continue;
            }
            if ($current === null || !isset($targetSet[$current]) || !str_starts_with($line, 'INSERT INTO ')) {
                continue;
            }
            $line = rtrim($line, "\r\n");
            if (!str_starts_with($line, 'INSERT INTO `' . $current . '` (') || !str_ends_with($line, ';')) {
                throw new RuntimeException("Unexpected statement in the dump for table $current.");
            }
            $rows += (int) $pdo->exec(substr($line, 0, -1));
            $statements++;
        }
        gzclose($gz);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $pdo->exec('SET time_zone = ' . $pdo->quote($originalTz));
        $msg = $e instanceof PDOException ? preg_replace('/^SQLSTATE\[[^\]]+\]:?\s*/', '', $e->getMessage()) : $e->getMessage();
        log_activity('restore', 'backup', $b['id'], 'Restore of ' . $b['filename'] . ' failed and was rolled back: ' . mb_substr((string) $msg, 0, 250), 'failed');
        log_system('error', 'Restore failed', ['backup_id' => $b['id'], 'error' => $e->getMessage()]);
        throw new CrudException('Restore failed and every change was rolled back: ' . mb_substr((string) $msg, 0, 300));
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec('SET time_zone = ' . $pdo->quote($originalTz));
    $userId = function_exists('user_id') ? user_id() : null;
    db_exec('UPDATE backups SET restored_at = NOW(), restored_by = ?, restore_count = restore_count + 1 WHERE id = ?', [$userId, $b['id']]);
    settings_all(true);
    $result = ['type' => 'database', 'tables' => count($targets), 'rows' => $rows, 'statements' => $statements, 'scope' => $selected ? 'selected' : 'all',
        'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'safety_backup' => $safety ? ['id' => $safety['id'], 'filename' => $safety['filename']] : null];
    $desc = 'Restored ' . ($selected ? count($targets) . ' table(s)' : 'the database') . ' from ' . $b['filename'] . ' (' . number_format($rows) . ' rows)';
    log_activity('restore', 'backup', $b['id'], $desc, 'success', ['tables' => $selected ?: 'all', 'rows' => $rows, 'safety_backup' => $result['safety_backup']]);
    notify('perm:backup', 'system', 'Database restored', $desc . '.', 'admin/backup', 'database');
    return $result;
}

/** Extract a files backup into assets/uploads (adds/overwrites, never deletes; scripts are skipped). */
function backup_restore_files(array $b, string $path, float $started): array
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        throw new CrudException('The ZIP archive cannot be opened.');
    }
    $restored = 0;
    $skipped = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (!str_starts_with($name, 'uploads/') || str_ends_with($name, '/')) {
            continue;
        }
        $rel = substr($name, 8);
        if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0") || str_starts_with($rel, '/') || preg_match('#^[a-z]:#i', $rel)
            || preg_match('/(^|\/)\.|\.(php\d?|phtml|phar|pl|py|cgi|sh|exe)$/i', $rel)) {
            $skipped++;
            continue;
        }
        $dest = UPLOAD_PATH . '/' . $rel;
        if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0775, true)) {
            $skipped++;
            continue;
        }
        $in = $zip->getStream($name);
        $out = $in ? @fopen($dest, 'wb') : false;
        if (!$in || !$out) {
            $skipped++;
            continue;
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        $restored++;
    }
    $zip->close();
    $userId = function_exists('user_id') ? user_id() : null;
    db_exec('UPDATE backups SET restored_at = NOW(), restored_by = ?, restore_count = restore_count + 1 WHERE id = ?', [$userId, $b['id']]);
    $desc = 'Restored ' . number_format($restored) . ' uploaded files from ' . $b['filename'] . ($skipped ? " ($skipped skipped)" : '');
    log_activity('restore', 'backup', $b['id'], $desc, 'success', ['files' => $restored, 'skipped' => $skipped]);
    notify('perm:backup', 'system', 'Files restored', $desc . '.', 'admin/backup', 'database');
    return ['type' => 'files', 'files' => $restored, 'skipped' => $skipped, 'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'safety_backup' => null];
}

/* ------------------------------------------------------------------
 * Delete, retention, schedule
 * ------------------------------------------------------------------ */

function backup_delete(array $b, string $reason = 'manual'): void
{
    $path = backup_file_path($b);
    if (is_file($path)) {
        @unlink($path);
    }
    db_delete('backups', 'id = ?', [$b['id']]);
    log_activity('delete', 'backup', $b['id'], ($reason === 'retention' ? 'Removed expired backup ' : 'Deleted backup ') . $b['filename'] . ($b['size_bytes'] ? ' (' . human_filesize((int) $b['size_bytes']) . ')' : ''));
}

/** Delete backups older than the retention period (always keeps the 3 newest completed backups). */
function backup_prune(): int
{
    $days = (int) setting('backup_retention', 15);
    if ($days <= 0) {
        return 0;
    }
    $keep = array_map('intval', db_column("SELECT id FROM backups WHERE status = 'completed' ORDER BY created_at DESC, id DESC LIMIT 3"));
    $old = db_all("SELECT * FROM backups WHERE status <> 'running' AND created_at < (NOW() - INTERVAL ? DAY)", [$days]);
    $n = 0;
    foreach ($old as $b) {
        if (!in_array((int) $b['id'], $keep, true)) {
            backup_delete($b, 'retention');
            $n++;
        }
    }
    return $n;
}

/** Mark backups stuck in "running" for over an hour as failed (process was interrupted). */
function backup_reap_stale(): void
{
    $stale = db_all("SELECT * FROM backups WHERE status = 'running' AND created_at < (NOW() - INTERVAL 1 HOUR)");
    foreach ($stale as $b) {
        @unlink(backup_file_path($b) . '.part');
        db_update('backups', ['status' => 'failed', 'error' => 'The backup process was interrupted before it finished.'], 'id = ?', [$b['id']]);
    }
}

function backup_interval_seconds(?string $schedule = null): ?int
{
    return ['daily' => 86400, 'weekly' => 7 * 86400, 'monthly' => 30 * 86400][$schedule ?? setting('auto_backup', 'off')] ?? null;
}

/** When the next automatic backup is due (Y-m-d H:i:s) or null when the schedule is off. */
function backup_next_due(): ?string
{
    $interval = backup_interval_seconds();
    if (!$interval) {
        return null;
    }
    $last = db_value("SELECT MAX(created_at) FROM backups WHERE status = 'completed' AND type = 'database'");
    return date('Y-m-d H:i:s', $last ? strtotime($last) + $interval : time());
}

/** Run the scheduled backup when due. Returns the new backup row or null when nothing was due. */
function backup_run_due(bool $force = false): ?array
{
    $due = backup_next_due();
    if (!$force && ($due === null || strtotime($due) > time() + 600)) {
        return null;
    }
    $b = backup_create('database', 'auto', 'Scheduled ' . setting('auto_backup', 'manual') . ' backup');
    save_setting('_backup_last_cron', date('Y-m-d H:i:s'), 'internal');
    return $b;
}

/** Secret token for the scheduled-task URL (derived from the application key). */
function backup_cron_token(): string
{
    return substr(hash_hmac('sha256', 'gimt-backup-cron', (string) (app_config('app')['key'] ?? '')), 0, 40);
}
