<?php
/**
 * Admin dashboard.
 *   GET /api/dashboard?session_id=4&refresh=1     aggregated, permission-aware dashboard payload (cached 60 s per user + session):
 *                                                 session (+ has_data / current_id / current_name), kpis (+ 6-point spark), charts,
 *                                                 lists, system, hero { rings: attendance / fees / admissions, today: classes / exams /
 *                                                 events / dues_week / collected / enquiries }
 *   GET /api/dashboard/notification-types         notification types of the current user with total / unread counts
 */
require_once APP_ROOT . '/app/services/system.php';
require_once APP_ROOT . '/app/services/dashboard.php';

route('GET', '/dashboard', function () {
    api_require('dashboard', 'view');
    $sid = isset($_GET['session_id']) && ctype_digit((string) $_GET['session_id']) ? (int) $_GET['session_id'] : current_session_id();
    api_ok(dashboard_payload((int) user_id(), $sid, !empty($_GET['refresh'])));
});

route('GET', '/dashboard/notification-types', function () {
    $rows = db_all('SELECT type, COUNT(*) AS total, COALESCE(SUM(is_read = 0), 0) AS unread FROM notifications WHERE user_id = ? GROUP BY type ORDER BY total DESC', [user_id()]);
    $labels = [
        'admission' => 'Admissions', 'application' => 'Applications', 'fee' => 'Fees', 'payment' => 'Payments', 'attendance' => 'Attendance', 'exam' => 'Examinations',
        'result' => 'Results', 'enquiry' => 'Enquiries', 'contact' => 'Contact messages', 'certificate' => 'Certificates', 'security' => 'Security', 'system' => 'System',
        'leave' => 'Leave', 'library' => 'Library', 'hostel' => 'Hostel', 'transport' => 'Transport', 'placement' => 'Placement', 'feedback' => 'Feedback', 'backup' => 'Backup', 'event' => 'Events', 'newsletter' => 'Newsletter', 'academic' => 'Academics',
    ];
    api_ok(array_map(fn ($r) => ['type' => $r['type'], 'label' => $labels[$r['type']] ?? label_from_key((string) $r['type']), 'total' => (int) $r['total'], 'unread' => (int) $r['unread']], $rows));
});
