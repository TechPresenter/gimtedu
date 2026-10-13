<?php
/**
 * Demo seed: Student & Faculty portals (DEMO ONLY - never run on a production database).
 *  - student_accounts for every active student, demo password "Demo@12345", must_change_password = 0
 *    (one shared bcrypt hash keeps the seeder fast), realistic activation / last sign-in dates
 *  - student_login_logs: sign-in history for the last 45 days (successes, a few failed attempts, logouts)
 *  - faculty logins: ~10 faculty members with the heaviest timetables get a linked `users` row with the Faculty
 *    role and the demo password (existing linked users are reused and their password reset to the demo one)
 * Re-runnable: clears student_account_tokens, student_login_logs and student_accounts (tables owned by this unit);
 * faculty users are created once and updated in place afterwards.
 */
require_once __DIR__ . '/lib/demo_data.php';

return function (array $opts): void {
    demo_seed(9745);
    $demoPassword = 'Demo@12345';
    $hash = password_hash($demoPassword, PASSWORD_DEFAULT);
    $now = time();

    // ---------------- Clean owned data ----------------
    db_exec('DELETE FROM student_account_tokens');
    db_exec('DELETE FROM student_login_logs');
    db_exec('DELETE FROM student_accounts');

    // ---------------- Student accounts ----------------
    $students = db_all("SELECT id, student_uid, admission_date FROM students WHERE status = 'active' ORDER BY id");
    $agents = [
        'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 Edg/128.0.0.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
    ];
    $accounts = [];
    $logs = [];
    foreach ($students as $s) {
        $method = demo_weighted(['self' => 62, 'bulk' => 30, 'admin' => 8]);
        $activated = demo_datetime('2026-07-20 09:00:00', '2026-09-30 20:00:00');
        $active = mt_rand(1, 100) <= 82; // most students have used the portal
        $lastLogin = $active ? demo_datetime(date('Y-m-d H:i:s', $now - 20 * 86400), date('Y-m-d H:i:s', $now - 600)) : null;
        $ip = '49.' . mt_rand(32, 47) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);
        $count = $active ? mt_rand(3, 46) : 0;
        $accounts[] = [
            'student_id' => (int) $s['id'], 'username' => $s['student_uid'], 'password_hash' => $hash, 'status' => 'active', 'must_change_password' => 0,
            'failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => $lastLogin, 'last_login_ip' => $lastLogin ? $ip : null, 'login_count' => $count,
            'activated_at' => $activated, 'activation_method' => $method, 'password_changed_at' => $activated, 'created_by' => null,
        ];
        $ua = demo_pick($agents);
        $logs[] = ['student_id' => (int) $s['id'], 'identifier' => $s['student_uid'], 'status' => 'activated', 'reason' => $method === 'self' ? 'Self activation' : 'Signed in with institute-issued password',
            'ip_address' => $ip, 'user_agent' => $ua, 'created_at' => $activated];
        if ($active) {
            $n = mt_rand(1, 6);
            for ($i = 0; $i < $n; $i++) {
                $at = $i === 0 ? $lastLogin : demo_datetime(date('Y-m-d H:i:s', $now - 45 * 86400), $lastLogin);
                if (mt_rand(1, 100) <= 14) {
                    $logs[] = ['student_id' => (int) $s['id'], 'identifier' => mt_rand(0, 3) ? $s['student_uid'] : strtolower($s['student_uid']), 'status' => 'failed', 'reason' => 'Wrong password',
                        'ip_address' => $ip, 'user_agent' => $ua, 'created_at' => date('Y-m-d H:i:s', strtotime($at) - mt_rand(20, 90))];
                }
                $logs[] = ['student_id' => (int) $s['id'], 'identifier' => $s['student_uid'], 'status' => 'success', 'reason' => mt_rand(1, 10) === 1 ? 'Remember-me cookie' : null,
                    'ip_address' => $ip, 'user_agent' => $ua, 'created_at' => $at];
                if (mt_rand(1, 100) <= 35) {
                    $logs[] = ['student_id' => (int) $s['id'], 'identifier' => $s['student_uid'], 'status' => 'logout', 'reason' => null,
                        'ip_address' => $ip, 'user_agent' => $ua, 'created_at' => date('Y-m-d H:i:s', min($now - 60, strtotime($at) + mt_rand(300, 2400)))];
                }
            }
        }
    }
    // A few unknown identifiers (typos) - realistic noise for the login activity screen
    for ($i = 0; $i < 25; $i++) {
        $logs[] = ['student_id' => null, 'identifier' => 'GIMT26' . demo_pick(['BBA', 'BCA', 'MBA', 'BCOM']) . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT) . 'X', 'status' => 'failed',
            'reason' => 'Unknown student', 'ip_address' => '106.' . mt_rand(192, 223) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254), 'user_agent' => demo_pick($agents),
            'created_at' => demo_datetime(date('Y-m-d H:i:s', $now - 30 * 86400), date('Y-m-d H:i:s', $now - 3600))];
    }
    usort($logs, fn ($a, $b) => strcmp($a['created_at'], $b['created_at']));
    demo_bulk_insert('student_accounts', $accounts);
    demo_bulk_insert('student_login_logs', $logs, 500);

    // ---------------- Faculty logins ----------------
    $role = db_value("SELECT id FROM roles WHERE slug = 'faculty'");
    if (!$role) {
        return;
    }
    $session = db_value('SELECT id FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1');
    $facultyIds = db_column("SELECT f.id FROM faculty f LEFT JOIN timetables t ON t.faculty_id = f.id AND t.academic_session_id = ?
                             WHERE f.status = 'active' GROUP BY f.id ORDER BY COUNT(t.id) DESC, f.id LIMIT 10", [(int) $session]);
    // keep the long-standing demo "faculty" user's profile in the set
    if ($linked = db_value("SELECT faculty_id FROM users WHERE username = 'faculty' AND faculty_id IS NOT NULL")) {
        $facultyIds = array_values(array_unique(array_merge([(int) $linked], array_map('intval', $facultyIds))));
    }
    foreach ($facultyIds as $fid) {
        $f = db_row("SELECT f.*, TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS full_name FROM faculty f WHERE f.id = ?", [(int) $fid]);
        if (!$f) {
            continue;
        }
        $email = strtolower(trim((string) $f['email']));
        $user = $f['user_id'] ? db_row('SELECT * FROM users WHERE id = ?', [(int) $f['user_id']]) : null;
        $user = $user ?: db_row('SELECT * FROM users WHERE faculty_id = ? ORDER BY id LIMIT 1', [(int) $fid]);
        $user = $user ?: ($email !== '' ? db_row('SELECT * FROM users WHERE email = ?', [$email]) : null);
        if ($user) {
            db_update('users', ['password_hash' => $hash, 'must_change_password' => 0, 'status' => 'active', 'faculty_id' => (int) $fid, 'failed_attempts' => 0, 'locked_until' => null],
                'id = ?', [(int) $user['id']]);
            $uid = (int) $user['id'];
        } else {
            $base = trim(strtolower(preg_replace('/[^a-z0-9.]/i', '', (string) strstr($email, '@', true))), '.') ?: 'faculty' . $fid;
            $username = $base;
            $n = 1;
            while ((int) db_value('SELECT COUNT(*) FROM users WHERE username = ?', [$username])) {
                $username = $base . (++$n);
            }
            $uid = db_insert('users', [
                'name' => mb_substr($f['full_name'], 0, 150), 'username' => $username, 'email' => $email, 'phone' => $f['phone'], 'password_hash' => $hash,
                'designation' => $f['designation'], 'department_id' => $f['department_id'], 'faculty_id' => (int) $fid, 'status' => 'active', 'must_change_password' => 0,
                'password_changed_at' => date('Y-m-d H:i:s'),
            ]);
        }
        if (!(int) db_value('SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND role_id = ?', [$uid, (int) $role])) {
            db_insert('user_roles', ['user_id' => $uid, 'role_id' => (int) $role]);
        }
        db_update('faculty', ['user_id' => $uid], 'id = ?', [(int) $fid]);
    }
};
