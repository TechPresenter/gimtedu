<?php
/**
 * Installer logic shared by the CLI (tools/install.php) and the web installer (install/index.php).
 */

/** Split an SQL file into statements (handles quotes and -- / # comments). */
function sql_split(string $sql): array
{
    $statements = [];
    $buffer = '';
    $len = strlen($sql);
    $quote = null;
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($quote === null) {
            if ($ch === '-' && ($sql[$i + 1] ?? '') === '-' && (($sql[$i + 2] ?? '') === ' ' || ($sql[$i + 2] ?? '') === "\n")) {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl;
                $buffer .= "\n";
                continue;
            }
            if ($ch === '#') {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl;
                $buffer .= "\n";
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
            } elseif ($ch === ';') {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }
        } elseif ($ch === '\\') {
            $buffer .= $ch . ($sql[$i + 1] ?? '');
            $i++;
            continue;
        } elseif ($ch === $quote) {
            if (($sql[$i + 1] ?? '') === $quote) {
                $buffer .= $ch . $ch;
                $i++;
                continue;
            }
            $quote = null;
        }
        $buffer .= $ch;
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }
    return $statements;
}

function install_drop_all_tables(): void
{
    db_exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (db_column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $t) {
        db_exec('DROP TABLE IF EXISTS ' . db_quote_ident($t));
    }
    db_exec('SET FOREIGN_KEY_CHECKS = 1');
}

/** Run every database/schema/*.sql file in order. Returns number of statements executed. */
function install_schema(?callable $log = null): int
{
    $count = 0;
    $files = glob(APP_ROOT . '/database/schema/*.sql');
    sort($files, SORT_NATURAL);
    db_exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($files as $file) {
        foreach (sql_split((string) file_get_contents($file)) as $stmt) {
            try {
                db()->exec($stmt);
            } catch (PDOException $e) {
                throw new RuntimeException(basename($file) . ': ' . $e->getMessage() . "\nStatement: " . mb_substr($stmt, 0, 300));
            }
            $count++;
        }
        $log && $log('schema ' . basename($file));
    }
    db_exec('SET FOREIGN_KEY_CHECKS = 1');
    return $count;
}

/**
 * Run seeders in database/seed/*.php (in order). Each file returns function (array $opts): void.
 * $opts: demo (bool) - include demo data; admin_email, admin_password, admin_name.
 */
function install_seed(array $opts, ?callable $log = null): void
{
    $files = glob(APP_ROOT . '/database/seed/*.php');
    sort($files, SORT_NATURAL);
    foreach ($files as $file) {
        $base = basename($file);
        // Core seeders (00-09) always run; demo seeders (10+) only with demo data.
        $isCore = (int) $base < 10;
        if (!$isCore && empty($opts['demo'])) {
            continue;
        }
        $fn = require $file;
        if (is_callable($fn)) {
            $started = microtime(true);
            $fn($opts);
            $log && $log(sprintf('seed %s (%.1fs)', $base, microtime(true) - $started));
        }
    }
}
