<?php
/**
 * Website visitor counter.
 *   POST /api/visit            page-view beacon from assets/js/visit.js (public, same-origin only, no CSRF header —
 *                              fetch keepalive cannot always send one; abuse is limited by origin check + rate limits)
 *   GET  /api/visit/totals     {unique, visits, today} for the footer (public, cached 60 s)
 *   GET  /api/visit/stats      daily series for admin reports (?days=30) — requires reports view
 */
require_once APP_ROOT . '/app/services/visitors.php';

/** True when the request comes from a page on this same site. */
function visit_same_origin(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $from = (string) ($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($host === '' || $from === '') {
        return false;
    }
    return strtolower((string) parse_url($from, PHP_URL_HOST) . (parse_url($from, PHP_URL_PORT) ? ':' . parse_url($from, PHP_URL_PORT) : '')) === $host;
}

route('POST', '/visit', function () {
    if (!visit_same_origin()) {
        api_error('Forbidden', 403);
    }
    if (!rate_limit('visit-ip:' . client_ip(), 600, 3600)) {
        api_error('Too many requests.', 429);
    }
    $r = visitors_track();
    api_ok(['counted' => $r['counted']]);
}, ['auth' => false, 'csrf' => false]);

route('GET', '/visit/totals', function () {
    header('Cache-Control: public, max-age=60');
    $t = visitors_totals();
    api_ok(['unique' => $t['unique'], 'visits' => $t['visits'], 'today' => $t['today']]);
}, ['auth' => false]);

route('GET', '/visit/stats', function () {
    api_require('reports', 'view');
    $days = (int) ($_GET['days'] ?? 30);
    api_ok(['totals' => visitors_totals(), 'series' => visitors_series($days)]);
});
