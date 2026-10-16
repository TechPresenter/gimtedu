<?php
/**
 * Database connection (PDO) and small query helpers.
 * Every query uses prepared statements.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // SET NAMES: one collation for the whole session. Some hosts (e.g. MariaDB with skip-character-set-client-handshake)
            // give the connection utf8mb4_unicode_ci while bound parameters stay utf8mb4_general_ci, and then comparing a
            // function result such as MD5(...) with a parameter fails with "Illegal mix of collations".
            $pdo->exec("SET NAMES utf8mb4, time_zone = '+05:30'");
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            if (defined('PWF_INSTALLER')) {
                throw $e;
            }
            http_response_code(503);
            if (APP_ENV === 'development') {
                exit('Database connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
            }
            exit('<!doctype html><meta charset="utf-8"><title>Service unavailable</title>'
                . '<div style="font-family:sans-serif;max-width:560px;margin:80px auto;text-align:center">'
                . '<h1>We will be back shortly</h1><p>The website is temporarily unavailable. Please try again in a few minutes.</p></div>');
        }
    }
    return $pdo;
}

/** Run a prepared statement and return it. */
function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Fetch one row or null. */
function db_row(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Fetch all rows. */
function db_rows(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

/** Fetch a single value. */
function db_value(string $sql, array $params = [])
{
    $value = db_query($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

/**
 * Insert a row. $table and the array keys are always hard-coded by the
 * calling code (never taken from user input).
 */
function db_insert(string $table, array $data): int
{
    $columns = array_keys($data);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES (:' . implode(', :', $columns) . ')';
    db_query($sql, $data);
    return (int) db()->lastInsertId();
}

/** Update rows: db_update('users', ['name' => 'X'], 'id = :id', ['id' => 5]) */
function db_update(string $table, array $data, string $where, array $whereParams = []): int
{
    $sets = [];
    $params = [];
    foreach ($data as $column => $value) {
        $sets[] = '`' . $column . '` = :set_' . $column;
        $params['set_' . $column] = $value;
    }
    $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where;
    return db_query($sql, array_merge($params, $whereParams))->rowCount();
}
