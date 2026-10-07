<?php
/**
 * Database access layer (PDO, prepared statements only).
 *
 *   db()                         -> PDO instance
 *   db_all($sql, $params)        -> list of rows
 *   db_row($sql, $params)        -> single row or null
 *   db_value($sql, $params)      -> first column of first row or null
 *   db_exec($sql, $params)       -> affected rows
 *   db_insert($table, $data)     -> new id
 *   db_update($table, $data, $where, $whereParams) -> affected rows
 *   db_delete($table, $where, $params)
 *   db_transaction(callable)     -> runs callable inside a transaction
 *
 * Table and column names passed to the helpers must come from code, never from user input.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = app_config('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], (int) $c['port'], $c['name'], $c['charset'] ?? 'utf8mb4');
    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        $pdo->exec("SET time_zone = '" . (new DateTime('now', new DateTimeZone(app_config('app')['timezone'] ?? 'Asia/Kolkata')))->format('P') . "'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    } catch (PDOException $e) {
        error_log('[GIMT] Database connection failed: ' . $e->getMessage());
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'Database connection failed: ' . $e->getMessage() . PHP_EOL);
            exit(1);
        }
        http_response_code(503);
        if (!is_file(APP_ROOT . '/config/local.php') && is_dir(APP_ROOT . '/install')) {
            header('Location: ' . base_url('install/'));
            exit;
        }
        echo '<!doctype html><meta charset="utf-8"><title>Service unavailable</title><div style="font-family:system-ui;padding:40px;color:#0B2A5B"><h1>Service temporarily unavailable</h1><p>The database could not be reached. Please try again shortly.</p></div>';
        exit;
    }
    return $pdo;
}

function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    foreach ($params as $key => $value) {
        $param = is_int($key) ? $key + 1 : (str_starts_with((string) $key, ':') ? $key : ':' . $key);
        if (is_int($value)) {
            $stmt->bindValue($param, $value, PDO::PARAM_INT);
        } elseif (is_bool($value)) {
            $stmt->bindValue($param, $value ? 1 : 0, PDO::PARAM_INT);
        } elseif ($value === null) {
            $stmt->bindValue($param, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($param, (string) $value, PDO::PARAM_STR);
        }
    }
    $stmt->execute();
    return $stmt;
}

function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

function db_row(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_value(string $sql, array $params = [])
{
    $value = db_query($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

function db_column(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
}

/** Returns [key => value] pairs from a two-column query. */
function db_pairs(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll(PDO::FETCH_KEY_PAIR);
}

function db_exec(string $sql, array $params = []): int
{
    return db_query($sql, $params)->rowCount();
}

function db_quote_ident(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)?$/', $name)) {
        throw new InvalidArgumentException('Invalid identifier: ' . $name);
    }
    return implode('.', array_map(fn ($p) => '`' . $p . '`', explode('.', $name)));
}

function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO ' . db_quote_ident($table) . ' (' . implode(', ', array_map('db_quote_ident', $cols)) . ') VALUES (' . implode(', ', array_map(fn ($c) => ':' . $c, $cols)) . ')';
    db_query($sql, $data);
    return (int) db()->lastInsertId();
}

function db_update(string $table, array $data, string $where, array $whereParams = []): int
{
    $sets = [];
    $params = [];
    foreach ($data as $col => $value) {
        $sets[] = db_quote_ident($col) . ' = :set_' . $col;
        $params['set_' . $col] = $value;
    }
    foreach ($whereParams as $k => $v) {
        $params[is_int($k) ? 'w' . $k : ltrim((string) $k, ':')] = $v;
    }
    // Positional placeholders in $where are converted to named ones.
    $i = 0;
    $where = preg_replace_callback('/\?/', function () use (&$i) {
        return ':w' . ($i++);
    }, $where);
    $sql = 'UPDATE ' . db_quote_ident($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where;
    return db_query($sql, $params)->rowCount();
}

function db_delete(string $table, string $where, array $params = []): int
{
    return db_exec('DELETE FROM ' . db_quote_ident($table) . ' WHERE ' . $where, $params);
}

function db_transaction(callable $fn)
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn($pdo);
    }
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Cached list of columns for a table (used by the CRUD engine to ignore unknown fields). */
function db_table_columns(string $table): array
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $cache[$table] = db_column('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]);
    }
    return $cache[$table];
}

function db_table_exists(string $table): bool
{
    return (bool) db_value('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
}
