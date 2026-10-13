<?php
/**
 * Website visitor counter (footer "Unique Visitors" / "Total Visits").
 *
 * Counting happens from a small beacon (assets/js/visit.js -> POST /api/visit) so most bots, prefetches and
 * non-browser requests are never counted. A visitor is a random first-party cookie (gimt_vid, 1 year) stored only
 * as a SHA-256 hash; a visit (session) ends after 30 minutes without a page view (cookie gimt_vs).
 * Settings: visitor_counter_enabled, visitor_counter_exclude_admins, visitor_counter_base_unique,
 * visitor_counter_base_visits (carry-over totals from a previous website, default 0).
 */

const VISITOR_COOKIE = 'gimt_vid';
const VISIT_COOKIE = 'gimt_vs';
const VISIT_TIMEOUT = 1800;

function visitors_is_bot(string $ua): bool
{
    if ($ua === '' || strlen($ua) < 12) {
        return true;
    }
    return (bool) preg_match('/bot|crawl|spider|slurp|scrap|headless|lighthouse|pagespeed|preview|facebookexternalhit|whatsapp|telegram|curl|wget|python|java\/|go-http|httpclient|monitor|uptime|playwright|puppeteer|phantom|selenium/i', $ua);
}

function visitors_cookie(string $name, string $value, int $lifetime): void
{
    setcookie($name, $value, [
        'expires' => time() + $lifetime,
        'path' => (base_path() ?: '') . '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Record one page view. Returns what was counted: ['counted' => bool, 'new_visitor' => bool, 'new_visit' => bool].
 */
function visitors_track(): array
{
    $none = ['counted' => false, 'new_visitor' => false, 'new_visit' => false];
    if (setting('visitor_counter_enabled', '1') !== '1' || visitors_is_bot(user_agent())) {
        return $none;
    }
    if (setting('visitor_counter_exclude_admins', '1') === '1' && function_exists('is_logged_in') && is_logged_in()) {
        return $none;
    }
    $vid = (string) ($_COOKIE[VISITOR_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $vid)) {
        $vid = bin2hex(random_bytes(16));
    }
    $hash = hash('sha256', $vid);
    // Abuse guard: at most 300 counted page views per visitor per hour.
    if (!rate_limit('visit:' . substr($hash, 0, 32), 300, 3600)) {
        return $none;
    }
    $newVisit = empty($_COOKIE[VISIT_COOKIE]);
    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');

    $result = db_transaction(function () use ($hash, $newVisit, $now, $today) {
        $row = db_row('SELECT id, last_visit_day FROM site_visitors WHERE visitor_hash = ? FOR UPDATE', [$hash]);
        $newVisitor = !$row;
        $firstToday = $newVisitor || $row['last_visit_day'] !== $today;
        if ($newVisitor) {
            db_exec('INSERT INTO site_visitors (visitor_hash, first_seen_at, last_seen_at, last_visit_day, visits, pageviews) VALUES (?, ?, ?, ?, 1, 1)', [$hash, $now, $now, $today]);
        } else {
            db_exec('UPDATE site_visitors SET last_seen_at = ?, last_visit_day = ?, visits = visits + ?, pageviews = pageviews + 1 WHERE id = ?', [$now, $today, $newVisit ? 1 : 0, $row['id']]);
        }
        $visit = ($newVisit || $newVisitor) ? 1 : 0;
        db_exec('INSERT INTO site_visit_daily (day, new_visitors, unique_visitors, visits, pageviews) VALUES (?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE new_visitors = new_visitors + VALUES(new_visitors), unique_visitors = unique_visitors + VALUES(unique_visitors),
                 visits = visits + VALUES(visits), pageviews = pageviews + 1',
            [$today, $newVisitor ? 1 : 0, $firstToday ? 1 : 0, $visit]);
        return ['counted' => true, 'new_visitor' => $newVisitor, 'new_visit' => (bool) $visit];
    });

    visitors_cookie(VISITOR_COOKIE, $vid, 365 * 86400);
    visitors_cookie(VISIT_COOKIE, '1', VISIT_TIMEOUT);   // sliding: refreshed on every page view
    return $result;
}

function visitors_cache_file(): string
{
    return APP_ROOT . '/storage/cache/visitor-totals.json';
}

/** Footer totals: ['unique' => int, 'visits' => int, 'today' => int, 'pageviews' => int] (cached 60 s). */
function visitors_totals(): array
{
    $file = visitors_cache_file();
    if (is_file($file) && filemtime($file) > time() - 60) {
        $cached = json_decode((string) file_get_contents($file), true);
        if (is_array($cached) && isset($cached['unique'], $cached['visits'])) {
            return $cached;
        }
    }
    $totals = ['unique' => 0, 'visits' => 0, 'today' => 0, 'pageviews' => 0];
    try {
        $row = db_row('SELECT COALESCE(SUM(new_visitors), 0) AS unique_v, COALESCE(SUM(visits), 0) AS visits, COALESCE(SUM(pageviews), 0) AS pv FROM site_visit_daily');
        $today = db_row('SELECT unique_visitors FROM site_visit_daily WHERE day = ?', [date('Y-m-d')]);
        $totals = [
            'unique' => (int) $row['unique_v'] + (int) setting('visitor_counter_base_unique', '0'),
            'visits' => (int) $row['visits'] + (int) setting('visitor_counter_base_visits', '0'),
            'today' => (int) ($today['unique_visitors'] ?? 0),
            'pageviews' => (int) $row['pv'],
        ];
    } catch (Throwable $e) {
        return $totals;   // tables missing (not installed yet) -> zeros, never break the footer
    }
    @file_put_contents($file, json_encode($totals), LOCK_EX);
    return $totals;
}

/** Daily series for admin charts: [{day, new_visitors, unique_visitors, visits, pageviews}] for the last $days days. */
function visitors_series(int $days = 30): array
{
    $days = max(1, min(366, $days));
    return db_all('SELECT day, new_visitors, unique_visitors, visits, pageviews FROM site_visit_daily WHERE day >= ? ORDER BY day',
        [date('Y-m-d', strtotime('-' . ($days - 1) . ' days'))]);
}
