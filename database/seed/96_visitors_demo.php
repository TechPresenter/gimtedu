<?php
/**
 * Demo seed: 120 days of website traffic so the footer counter and admin reports have data.
 * Only runs with --demo; a real installation starts counting from zero.
 */
require_once __DIR__ . '/lib/demo_data.php';

return function (array $opts): void {
    demo_seed(44044);
    db_exec('DELETE FROM site_visit_daily');
    db_exec('DELETE FROM site_visitors');
    $days = [];
    $visitors = [];
    $today = strtotime(date('Y-m-d'));
    for ($i = 119; $i >= 0; $i--) {
        $ts = $today - $i * 86400;
        $day = date('Y-m-d', $ts);
        // Admission season (Jun-Sep) is busier; weekends are quieter.
        $season = in_array((int) date('n', $ts), [6, 7, 8, 9], true) ? 1.6 : 1.0;
        $weekend = in_array((int) date('N', $ts), [6, 7], true) ? 0.7 : 1.0;
        $unique = (int) round(mt_rand(140, 220) * $season * $weekend);
        $new = (int) round($unique * mt_rand(55, 70) / 100);
        $visits = (int) round($unique * mt_rand(115, 135) / 100);
        $pageviews = (int) round($visits * mt_rand(260, 340) / 100);
        $days[] = ['day' => $day, 'new_visitors' => $new, 'unique_visitors' => $unique, 'visits' => $visits, 'pageviews' => $pageviews];
        for ($k = 0; $k < $new; $k++) {
            $first = date('Y-m-d H:i:s', $ts + mt_rand(0, 86399));
            $visitors[] = ['visitor_hash' => hash('sha256', 'demo-' . $day . '-' . $k), 'first_seen_at' => $first, 'last_seen_at' => $first,
                'last_visit_day' => $day, 'visits' => 1, 'pageviews' => mt_rand(1, 6)];
        }
    }
    demo_bulk_insert('site_visit_daily', $days);
    demo_bulk_insert('site_visitors', $visitors, 500);
    @unlink(APP_ROOT . '/storage/cache/visitor-totals.json');
};
