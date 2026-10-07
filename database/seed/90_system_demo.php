<?php
/**
 * Demo seed (System administration): a few extra user accounts (locked / inactive / must-change), 30 days of
 * login history and audit-trail activity across every module (referencing real users, students, programs …),
 * in-app notifications, blocked IP addresses, remembered sessions and an initial database backup.
 *
 * Re-runnable and safe in a shared database: it only removes rows it created itself. The ID ranges of the rows it
 * bulk-inserts into shared log tables are recorded in the internal setting `_demo_seed_system`.
 */
require_once __DIR__ . '/lib/demo_data.php';
require_once APP_ROOT . '/app/services/system.php';
require_once APP_ROOT . '/app/services/backup.php';

return function (array $opts): void {
    demo_seed(90201);
    $seedAt = date('Y-m-d H:i:s');
    $now = time();
    $today = strtotime('today');

    /* ---------------------------------------------------------- 1. clean previous run */
    $prev = json_decode((string) db_value("SELECT `value` FROM settings WHERE `key` = '_demo_seed_system'"), true) ?: [];
    foreach (['activity_logs', 'login_logs', 'notifications'] as $t) {
        foreach ($prev['ranges'][$t] ?? [] as [$a, $b]) {
            db_exec("DELETE FROM `$t` WHERE id BETWEEN ? AND ? AND created_at <= ?", [$a, $b, $prev['at'] ?? $seedAt]);
        }
    }
    db_exec("DELETE FROM user_tokens WHERE selector LIKE 'demo%'");
    $demoIps = ['45.155.205.233', '185.220.101.47', '103.152.18.9'];
    db_exec('DELETE FROM blocked_ips WHERE ip_address IN (?, ?, ?)', $demoIps);
    $extraUsers = [
        // name, username, email, role, designation, status, extra
        ['Vikas Rana', 'vikas.rana', 'vikas.rana@gimt.ac.in', 'staff', 'Front Office Executive', 'active', 'locked'],
        ['Anita Desai', 'anita.desai', 'anita.desai@gimt.ac.in', 'content-manager', 'Web Content Editor', 'inactive', 'inactive'],
        ['Rohit Bansal', 'rohit.bansal', 'rohit.bansal@gimt.ac.in', 'accountant', 'Assistant Accountant', 'active', 'new'],
        ['Kiran Joshi', 'kiran.joshi', 'kiran.joshi@gimt.ac.in', 'admission-officer', 'Admission Counsellor', 'active', 'regular'],
    ];
    db_exec('DELETE FROM users WHERE email IN (' . implode(',', array_fill(0, count($extraUsers), '?')) . ')', array_column($extraUsers, 2));
    foreach (db_all("SELECT * FROM backups WHERE source = 'seed'") as $b) {
        @unlink(backup_file_path($b));
        db_delete('backups', 'id = ?', [$b['id']]);
    }

    /* ---------------------------------------------------------- 2. extra demo accounts */
    $hash = password_hash('Demo@12345', PASSWORD_DEFAULT);
    $adminId = (int) (db_value("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.is_super = 1 ORDER BY u.id LIMIT 1") ?: 1);
    $deptMgt = db_value("SELECT id FROM departments WHERE code = 'MGT'");
    foreach ($extraUsers as [$name, $username, $email, $role, $desig, $status, $kind]) {
        $created = date('Y-m-d H:i:s', $kind === 'new' ? $now - 2 * 86400 : $now - mt_rand(70, 200) * 86400);
        $uid = db_insert('users', [
            'name' => $name, 'username' => $username, 'email' => $email, 'phone' => demo_phone(), 'password_hash' => $hash, 'designation' => $desig,
            'department_id' => $kind === 'regular' ? $deptMgt : null, 'status' => $status, 'must_change_password' => $kind === 'new' ? 1 : 0,
            'password_changed_at' => $created, 'created_by' => $adminId, 'created_at' => $created,
            'locked_until' => $kind === 'locked' ? date('Y-m-d H:i:s', $now + 6 * 3600) : null,
            'last_failed_login_at' => $kind === 'locked' ? date('Y-m-d H:i:s', $now - 25 * 60) : null,
            'last_login_at' => $kind === 'inactive' ? date('Y-m-d H:i:s', $now - 41 * 86400 + 3600 * 3) : null,
            'last_login_ip' => $kind === 'inactive' ? '49.36.112.18' : null,
        ]);
        db_exec('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?', [$uid, $role]);
    }

    /* ---------------------------------------------------------- 3. reference data */
    $users = db_all("SELECT u.id, u.name, u.email, u.username, u.status, u.must_change_password, u.locked_until,
                            (SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id ORDER BY r.is_super DESC, r.id LIMIT 1) AS role
                     FROM users u ORDER BY u.id");
    $students = db_all("SELECT s.id, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) name, s.student_uid uid, p.short_name program, s.current_semester sem
                        FROM students s LEFT JOIN programs p ON p.id = s.program_id WHERE s.status = 'active' ORDER BY s.id LIMIT 400");
    if (!$students) {
        $students = [['id' => null, 'name' => 'Ananya Sharma', 'uid' => 'GIMT26BBA001', 'program' => 'BBA', 'sem' => 1]];
    }
    $programs = db_column("SELECT short_name FROM programs WHERE status = 'active' ORDER BY sort_order") ?: ['BBA', 'MBA', 'B.Tech (CSE)', 'BCA', 'B.Com (Hons)'];
    $subjects = db_all("SELECT sb.name, p.short_name program, sb.semester_no sem FROM subjects sb JOIN programs p ON p.id = sb.program_id ORDER BY sb.id LIMIT 300")
        ?: [['name' => 'Financial Management', 'program' => 'MBA', 'sem' => 2]];
    $faculty = db_column("SELECT TRIM(CONCAT_WS(' ', title, first_name, last_name)) FROM faculty WHERE status = 'active' ORDER BY id LIMIT 60") ?: ['Dr. Sakshi Garg'];
    $applicants = db_all("SELECT id, TRIM(CONCAT_WS(' ', first_name, last_name)) name, application_no FROM admissions ORDER BY id DESC LIMIT 120");
    $enquirers = db_column("SELECT name FROM enquiries ORDER BY id DESC LIMIT 80");
    $tableHas = function (string $table, string $col): array {
        try {
            return db_table_exists($table) ? db_column("SELECT `$col` FROM `$table` ORDER BY id DESC LIMIT 40") : [];
        } catch (Throwable $e) {
            return [];
        }
    };
    $companies = $tableHas('companies', 'name') ?: ['Tata Consultancy Services', 'Infosys', 'Wipro', 'HCLTech', 'Deloitte', 'Capgemini', 'Accenture', 'HDFC Bank', 'Amazon', 'Cognizant', 'ICICI Bank', 'KPMG'];
    $books = $tableHas('books', 'title') ?: ['Principles of Marketing', 'Introduction to Algorithms', 'Financial Accounting', 'Operating System Concepts', 'Business Statistics', 'Database System Concepts', 'Managerial Economics', 'Let Us C'];
    $notices = $tableHas('notices', 'title') ?: ['Mid-term examination schedule — Odd Semester 2026', 'Diwali vacation notice', 'Fee payment deadline extended', 'Guest lecture on AI in Business', 'Blood donation camp on campus', 'Revised timetable for B.Tech (CSE) Semester 3'];
    $posts = $tableHas('blog_posts', 'title') ?: ['5 Reasons to Choose an MBA in 2026', 'GIMT Students Shine at National Hackathon', 'Campus Placement Season 2026-27 Begins', 'How to Prepare for Your First Internship'];
    $routes = ['R-01 Greater Noida West', 'R-02 Noida Sector 62', 'R-03 Ghaziabad', 'R-04 Pari Chowk', 'R-05 Dadri'];
    $vehicles = ['UP16 AT 4521', 'UP16 BT 7712', 'UP14 CT 3098', 'UP16 DT 1186'];
    $rooms = ['A-104', 'A-212', 'B-118', 'B-204', 'C-007', 'G-112', 'G-215'];
    $hostels = ['Aryabhatta Boys Hostel', 'Kalpana Chawla Girls Hostel'];
    $student = fn () => demo_pick($students);
    $subject = fn () => demo_pick($subjects);
    $inr = fn (int $n) => '₹' . number_in($n);

    /* ---------------------------------------------------------- 4. devices & networks */
    $ua = [
        'chrome_win' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'edge_win' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0',
        'firefox_win' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0',
        'safari_mac' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15',
        'chrome_android' => 'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
        'safari_iphone' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1',
    ];
    $profiles = [];
    foreach ($users as $u) {
        $desk = demo_pick(['chrome_win', 'chrome_win', 'edge_win', 'firefox_win', 'safari_mac']);
        $profiles[$u['id']] = [
            'desk' => $ua[$desk], 'mobile' => $ua[demo_pick(['chrome_android', 'chrome_android', 'safari_iphone'])],
            'lan' => '10.20.' . demo_pick([1, 1, 2, 5]) . '.' . mt_rand(20, 220),
            'home' => demo_pick(['49.36.', '106.205.', '223.181.', '157.38.', '117.97.']) . mt_rand(1, 250) . '.' . mt_rand(2, 250),
        ];
    }

    /* ---------------------------------------------------------- 5. what each role does */
    $actionsFor = function (string $role) use ($student, $subject, $programs, $faculty, $applicants, $enquirers, $companies, $books, $notices, $posts, $routes, $vehicles, $rooms, $hostels, $inr): array {
        $s = $student();
        $sb = $subject();
        $prog = demo_pick($programs);
        $app = $applicants ? demo_pick($applicants) : ['id' => null, 'name' => $s['name'], 'application_no' => 'APP-2026-0' . mt_rand(1000, 1400)];
        $receipt = 'RCPT/2026-27/' . str_pad((string) mt_rand(1200, 4800), 6, '0', STR_PAD_LEFT);
        $amount = demo_pick([12500, 18000, 22500, 45000, 47500, 60000, 90000, 125000]);
        $company = demo_pick($companies);
        $pool = [
            'super-admin' => [
                ['update', 'settings', null, 'Updated General settings: Office hours, Alternate phone', ['keys' => ['office_hours', 'phone_alt']]],
                ['update', 'roles', null, 'Updated permissions of role Accountant (+2 / −0)', ['added' => ['reports.export', 'fees.approve'], 'removed' => []]],
                ['create', 'users', null, 'Created user "' . demo_pick(['Kiran Joshi', 'Rohit Bansal', 'Meera Pillai']) . '"', null],
                ['export', 'students', null, 'Exported ' . number_in(mt_rand(1100, 1350)) . ' students (XLSX)', null],
                ['publish', 'notices', null, 'Published notice "' . demo_pick($notices) . '"', null],
                ['update', 'security', null, 'Updated security policy: Lockout duration, Idle session timeout', ['keys' => ['lockout_minutes', 'session_timeout']]],
                ['verify', 'backup', null, 'Verified backup gimt-db-' . date('Y-m-d', strtotime('-' . mt_rand(1, 20) . ' days')) . '_020000.sql.gz: OK', null],
            ],
            'administrator' => [
                ['update', 'students', $s['id'], "Updated student \"{$s['name']}\"", ['changes' => ['mobile' => ['from' => demo_phone(), 'to' => demo_phone()]]]],
                ['create', 'notices', null, 'Created notice "' . demo_pick($notices) . '"', null],
                ['update', 'faculty', null, 'Updated faculty "' . demo_pick($faculty) . '"', null],
                ['export', 'reports', null, 'Exported admission report (PDF)', null],
                ['create', 'events', null, 'Created event "' . demo_pick(['Tech Fest Innovista 2026', 'Annual Sports Meet', 'Industry Connect Summit', 'Freshers\' Welcome 2026']) . '"', null],
                ['approve', 'expenses', null, 'Approved expense ' . $inr(demo_pick([18500, 42000, 7800])) . ' — ' . demo_pick(['Electricity bill', 'Lab consumables', 'Annual fest stage hire']), null],
            ],
            'admission-officer' => [
                ['create', 'enquiries', null, 'Added enquiry from ' . ($enquirers ? demo_pick($enquirers) : $s['name']) . " for $prog", null],
                ['update', 'admissions', $app['id'], "Moved application {$app['application_no']} ({$app['name']}) to Document Verification", ['stage' => ['from' => 'application', 'to' => 'document_verification']]],
                ['approve', 'admissions', $app['id'], "Approved admission of {$app['name']} for $prog", null],
                ['create', 'admissions', $app['id'], "Created application {$app['application_no']} for {$app['name']}", null],
                ['create', 'students', $s['id'], "Converted applicant {$s['name']} to student {$s['uid']}", null],
                ['update', 'enquiries', null, 'Logged follow-up call with ' . ($enquirers ? demo_pick($enquirers) : $s['name']) . ' — interested, campus visit planned', null],
            ],
            'academic-admin' => [
                ['publish', 'timetable', null, "Published timetable for $prog Semester " . mt_rand(1, 6) . ' Section ' . demo_pick(['A', 'B']), null],
                ['update', 'academics', null, "Updated subject \"{$sb['name']}\" ({$sb['program']} Sem {$sb['sem']})", ['changes' => ['credits' => ['from' => 3, 'to' => 4]]]],
                ['create', 'attendance', null, "Marked attendance for {$sb['program']} Sem {$sb['sem']}: " . mt_rand(44, 58) . ' present, ' . mt_rand(1, 6) . ' absent', null],
                ['create', 'academics', null, 'Assigned ' . demo_pick($faculty) . " to {$sb['name']}", null],
                ['export', 'attendance', null, 'Exported attendance defaulters report (XLSX)', null],
            ],
            'faculty' => [
                ['create', 'attendance', null, "Marked attendance: {$sb['name']} ({$sb['program']} Sem {$sb['sem']}) — " . mt_rand(40, 58) . ' present, ' . mt_rand(1, 7) . ' absent', null],
                ['update', 'results', null, "Entered internal marks for {$sb['name']} (" . mt_rand(48, 62) . ' students)', null],
                ['view', 'students', $s['id'], "Viewed profile of {$s['name']} ({$s['uid']})", null],
            ],
            'accountant' => [
                ['create', 'fees', $s['id'], 'Collected ' . $inr($amount) . " from {$s['name']} ($receipt) via " . demo_pick(['UPI', 'Cash', 'Bank transfer', 'Card']), ['amount' => $amount, 'receipt_no' => $receipt]],
                ['create', 'fees', $s['id'], 'Collected ' . $inr($amount) . " from {$s['name']} ($receipt) via " . demo_pick(['UPI', 'Online gateway']), ['amount' => $amount, 'receipt_no' => $receipt]],
                ['create', 'expenses', null, 'Recorded expense ' . $inr(demo_pick([18500, 6400, 32000, 9800])) . ' — ' . demo_pick(['Electricity bill (Utilities)', 'Printer cartridges (Office)', 'Diesel for buses (Transport)', 'Library journals']), null],
                ['approve', 'fees', $s['id'], 'Approved refund of ' . $inr(demo_pick([5000, 10000, 2500])) . " to {$s['name']} (caution deposit)", null],
                ['export', 'fees', null, 'Exported fee collection report for ' . date('F Y') . ' (XLSX)', null],
                ['create', 'fees', null, "Applied scholarship \"Merit Scholarship 25%\" to {$s['name']}", null],
            ],
            'exam-controller' => [
                ['create', 'examination', null, "Scheduled End Semester Examination for $prog Semester " . mt_rand(1, 6), null],
                ['update', 'results', null, "Verified marks of {$sb['name']} for {$sb['program']}", null],
                ['publish', 'results', null, "Published results of Mid Term Examination ($prog)", null],
                ['create', 'certificates', $s['id'], 'Issued ' . demo_pick(['Bonafide', 'Character', 'Provisional']) . ' certificate GIMT/' . demo_pick(['BC', 'CC', 'PC']) . '/2026/' . str_pad((string) mt_rand(10, 480), 4, '0', STR_PAD_LEFT) . " to {$s['name']}", null],
                ['update', 'examination', null, 'Allocated invigilators for ' . date('d M', strtotime('+' . mt_rand(5, 40) . ' days')) . ' morning session', null],
            ],
            'librarian' => [
                ['create', 'library', null, 'Issued "' . demo_pick($books) . "\" to {$s['name']}", null],
                ['update', 'library', null, 'Returned "' . demo_pick($books) . "\" from {$s['name']}" . (mt_rand(0, 3) ? '' : ' (fine ' . $inr(demo_pick([10, 20, 40])) . ')'), null],
                ['create', 'library', null, 'Added ' . mt_rand(2, 12) . ' copies of "' . demo_pick($books) . '"', null],
            ],
            'hostel-warden' => [
                ['create', 'hostel', $s['id'], 'Allocated Room ' . demo_pick($rooms) . ' (Bed ' . mt_rand(1, 3) . ') in ' . demo_pick($hostels) . " to {$s['name']}", null],
                ['update', 'hostel', null, 'Resolved complaint "' . demo_pick(['Water leakage in washroom', 'Wi-Fi not working', 'Fan not working', 'Room cleaning pending']) . '" (Room ' . demo_pick($rooms) . ')', null],
                ['create', 'hostel', null, 'Logged visitor ' . demo_pick(['Mr. Rakesh Sharma', 'Mrs. Sunita Verma', 'Mr. Anil Gupta']) . " for {$s['name']}", null],
            ],
            'transport-manager' => [
                ['create', 'transport', $s['id'], "Assigned {$s['name']} to Route " . demo_pick($routes), null],
                ['create', 'transport', null, 'Logged fuel refill ' . mt_rand(40, 90) . ' L for ' . demo_pick($vehicles), null],
                ['update', 'transport', null, 'Scheduled maintenance for ' . demo_pick($vehicles) . ' (' . demo_pick(['oil change', 'brake inspection', 'tyre replacement']) . ')', null],
            ],
            'placement-officer' => [
                ['create', 'placement', null, "Scheduled placement drive: $company — " . demo_pick(['Graduate Engineer Trainee', 'Business Analyst', 'Sales Executive', 'Associate Software Engineer']), null],
                ['update', 'placement', null, 'Shortlisted ' . mt_rand(8, 40) . " students for $company technical round", null],
                ['create', 'placement', $s['id'], "Recorded offer for {$s['name']} from $company (₹" . demo_pick(['4.5', '5.2', '6.5', '7.8', '12']) . ' LPA)', null],
                ['create', 'placement', null, 'Created training batch "' . demo_pick(['Aptitude Bootcamp', 'Mock Interview Week', 'Resume Writing Workshop']) . '"', null],
            ],
            'alumni-coordinator' => [
                ['create', 'alumni', null, 'Added alumni profile ' . demo_pick(['Rohan Kapoor', 'Sneha Iyer', 'Varun Malhotra', 'Pooja Arora']) . ' (Batch ' . mt_rand(2012, 2024) . ", $company)", null],
                ['publish', 'events', null, 'Published alumni meet "Homecoming 2026"', null],
                ['create', 'alumni', null, "Posted job opportunity from alumni at $company", null],
            ],
            'content-manager' => [
                ['publish', 'blog', null, 'Published blog post "' . demo_pick($posts) . '"', null],
                ['update', 'cms', null, 'Updated home page banner "Admissions Open 2026-27"', null],
                ['update', 'seo', null, 'Updated SEO for page /' . demo_pick(['programs', 'admissions', 'about', 'placement']), ['changes' => ['meta_title' => ['from' => 'Programs', 'to' => 'Programs | GIMT Greater Noida']]]],
                ['create', 'media', null, 'Uploaded ' . mt_rand(6, 24) . ' images to gallery "' . demo_pick(['Annual Fest 2026', 'Convocation 2026', 'Campus Life']) . '"', null],
            ],
            'staff' => [
                ['view', 'students', $s['id'], "Viewed profile of {$s['name']} ({$s['uid']})", null],
                ['denied', 'students', null, 'Access denied: export students', null, 'failed'],
            ],
        ];
        return $pool[$role] ?? $pool['staff'];
    };

    /* ---------------------------------------------------------- 6. sessions over the last 30 days */
    $login = [];
    $activity = [];
    $lastLogin = [];
    $addActivity = function (?int $uid, string $action, string $module, $record, string $desc, int $ts, string $ip, string $agent, ?array $meta = null, string $status = 'success') use (&$activity) {
        $activity[] = ['user_id' => $uid, 'action' => $action, 'module' => $module, 'record_id' => $record !== null ? (string) $record : null, 'description' => mb_substr($desc, 0, 500),
            'status' => $status, 'ip_address' => $ip, 'user_agent' => $agent, 'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, 'created_at' => date('Y-m-d H:i:s', $ts)];
    };
    $addLogin = function (?int $uid, string $identifier, string $status, ?string $reason, string $ip, string $agent, int $ts) use (&$login) {
        $login[] = ['user_id' => $uid, 'identifier' => $identifier, 'status' => $status, 'reason' => $reason, 'ip_address' => $ip, 'user_agent' => $agent, 'created_at' => date('Y-m-d H:i:s', $ts)];
    };
    foreach ($users as $u) {
        $uid = (int) $u['id'];
        $role = (string) ($u['role'] ?? 'staff');
        $p = $profiles[$uid];
        $kind = null;
        foreach ($extraUsers as $x) {
            if ($x[2] === $u['email']) {
                $kind = $x[6];
            }
        }
        if (in_array($kind, ['new', 'inactive'], true)) {
            continue; // never signed in / dormant account
        }
        $busy = in_array($role, ['super-admin', 'administrator', 'accountant', 'admission-officer'], true) ? 92 : ($role === 'staff' ? 60 : 78);
        for ($d = 30; $d >= 0; $d--) {
            $day = $today - $d * 86400;
            $dow = (int) date('N', $day);
            if ($dow === 7 && mt_rand(1, 100) > 12) {
                continue;
            }
            if (mt_rand(1, 100) > ($dow === 6 ? (int) ($busy * 0.6) : $busy)) {
                continue;
            }
            $start = $day + mt_rand(8 * 3600 + 40 * 60, 10 * 3600 + 30 * 60);
            if ($start > $now - 600) {
                continue;
            }
            $mobile = mt_rand(1, 100) <= 15;
            $remote = $mobile || mt_rand(1, 100) <= 12;
            $ip = $remote ? $p['home'] : $p['lan'];
            $agent = $mobile ? $p['mobile'] : $p['desk'];
            $identifier = mt_rand(0, 1) ? $u['email'] : $u['username'];
            if (mt_rand(1, 100) <= 9) {
                $addLogin($uid, $identifier, 'failed', 'Wrong password', $ip, $agent, $start - mt_rand(20, 90));
            }
            $addLogin($uid, $u['email'], 'success', null, $ip, $agent, $start);
            $addActivity($uid, 'login', 'auth', $uid, $u['name'] . ' signed in', $start, $ip, $agent);
            $lastLogin[$uid] = [$start, $ip];
            $t = $start;
            $n = $role === 'staff' ? mt_rand(0, 2) : mt_rand(2, 7);
            for ($i = 0; $i < $n; $i++) {
                $t += mt_rand(4, 75) * 60;
                if ($t > $now - 60) {
                    break;
                }
                $a = demo_pick($actionsFor($role));
                $addActivity($uid, $a[0], $a[1], $a[2], $a[3], $t, $ip, $agent, $a[4] ?? null, $a[5] ?? 'success');
            }
            if (mt_rand(1, 100) <= 55 && $t + 1800 < $now) {
                $out = $t + mt_rand(10, 120) * 60;
                $addLogin($uid, $u['email'], 'logout', null, $ip, $agent, $out);
                $addActivity($uid, 'logout', 'auth', $uid, 'Signed out', $out, $ip, $agent);
            }
        }
    }

    /* ---------------------------------------------------------- 7. attacks, lockouts and blocked IPs */
    $botAgents = ['python-requests/2.31.0', 'curl/8.4.0', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0.0.0 Safari/537.36'];
    $attacks = [
        ['45.155.205.233', 9, 46, ['admin', 'administrator', 'admin@gimt.ac.in', 'root', 'test@gimt.ac.in', 'info@gimt.ac.in']],
        ['185.220.101.47', 5, 28, ['admin', 'principal@gimt.ac.in', 'accounts@gimt.ac.in', 'webmaster']],
        ['103.152.18.9', 12, 14, ['admissions@gimt.ac.in', 'admissions']],
        ['91.240.118.172', 1, 9, ['admin', 'staff@gimt.ac.in']],
    ];
    $emailToId = array_column($users, 'id', 'email') + array_column($users, 'id', 'username');
    foreach ($attacks as [$ip, $daysAgo, $count, $targets]) {
        $ts = $today - $daysAgo * 86400 + mt_rand(1, 5) * 3600;
        $agent = demo_pick($botAgents);
        for ($i = 0; $i < $count && $ts < $now - 300; $i++) {
            $ident = demo_pick($targets);
            $uid = isset($emailToId[$ident]) ? (int) $emailToId[$ident] : null;
            $addLogin($uid, $ident, 'failed', $uid ? 'Wrong password' : 'Unknown user', $ip, $agent, $ts);
            $ts += mt_rand(4, 45);
        }
        if (in_array($ip, ['45.155.205.233', '185.220.101.47'], true)) {
            for ($i = 0; $i < 6 && $ts < $now - 300; $i++) {
                $ts += mt_rand(600, 7200);
                $addLogin(null, demo_pick($targets), 'blocked', 'IP blocked', $ip, $agent, $ts);
            }
        }
    }
    // Vikas Rana locked himself out this morning
    $vikas = db_row("SELECT id, email FROM users WHERE email = 'vikas.rana@gimt.ac.in'");
    if ($vikas) {
        $ts = $now - 32 * 60;
        $p = ['ip' => '10.20.1.45', 'ua' => $ua['chrome_win']];
        for ($i = 1; $i <= 5; $i++) {
            $addLogin((int) $vikas['id'], $vikas['email'], 'failed', $i === 5 ? 'Wrong password - account locked' : 'Wrong password', $p['ip'], $p['ua'], $ts);
            $ts += mt_rand(15, 70);
        }
        $addLogin((int) $vikas['id'], $vikas['email'], 'locked', 'Account locked', $p['ip'], $p['ua'], $ts + 240);
        $lastLogin[(int) $vikas['id']] = [$today - 3 * 86400 + 9 * 3600 + 1200, '10.20.1.45'];
        $addLogin((int) $vikas['id'], $vikas['email'], 'success', null, '10.20.1.45', $p['ua'], $today - 3 * 86400 + 9 * 3600 + 1200);
    }
    foreach ([[45, '45.155.205.233'], [12, '185.220.101.47']] as [$minsAgo, $ip]) {
        $addActivity($adminId, 'create', 'security', null, "Created blocked ip $ip", $now - $minsAgo * 3600, $profiles[$adminId]['lan'] ?? '10.20.1.10', $profiles[$adminId]['desk'] ?? $ua['chrome_win']);
    }

    /* ---------------------------------------------------------- 8. insert logs with tracked ID ranges */
    $ranges = [];
    $insert = function (string $table, array $rows) use (&$ranges) {
        if (!$rows) {
            return;
        }
        usort($rows, fn ($a, $b) => strcmp($a['created_at'], $b['created_at']));
        $cols = array_keys($rows[0]);
        $colSql = implode(', ', array_map('db_quote_ident', $cols));
        foreach (array_chunk($rows, 250) as $part) {
            $ph = [];
            $params = [];
            foreach ($part as $r) {
                $ph[] = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
                foreach ($cols as $c) {
                    $params[] = $r[$c];
                }
            }
            db_query('INSERT INTO ' . db_quote_ident($table) . " ($colSql) VALUES " . implode(', ', $ph), $params);
            $first = (int) db()->lastInsertId();
            $ranges[$table][] = [$first, $first + count($part) - 1];
        }
    };
    $insert('login_logs', $login);
    $insert('activity_logs', $activity);

    foreach ($lastLogin as $uid => [$ts, $ip]) {
        db_exec('UPDATE users SET last_login_at = ?, last_login_ip = ? WHERE id = ? AND (last_login_at IS NULL OR last_login_at < ?)', [date('Y-m-d H:i:s', $ts), $ip, $uid, date('Y-m-d H:i:s', $ts)]);
    }

    /* ---------------------------------------------------------- 9. blocked IPs & remembered sessions */
    db_insert('blocked_ips', ['ip_address' => '45.155.205.233', 'reason' => 'Brute-force: 46 failed sign-ins against admin accounts', 'expires_at' => null, 'created_by' => $adminId,
        'created_at' => date('Y-m-d H:i:s', $now - 45 * 3600)]);
    db_insert('blocked_ips', ['ip_address' => '185.220.101.47', 'reason' => 'Tor exit node — credential stuffing on staff accounts', 'expires_at' => date('Y-m-d H:i:s', $now + 30 * 86400),
        'created_by' => $adminId, 'created_at' => date('Y-m-d H:i:s', $now - 12 * 3600)]);
    db_insert('blocked_ips', ['ip_address' => '103.152.18.9', 'reason' => 'Repeated failures on the admissions account', 'expires_at' => date('Y-m-d H:i:s', $now - 3 * 86400),
        'created_by' => $adminId, 'created_at' => date('Y-m-d H:i:s', $now - 10 * 86400)]);
    $i = 0;
    foreach ($users as $u) {
        if ($u['status'] !== 'active' || !isset($lastLogin[(int) $u['id']]) || mt_rand(1, 100) > 45) {
            continue;
        }
        $p = $profiles[(int) $u['id']];
        foreach (array_slice([[$p['desk'], $p['lan']], [$p['mobile'], $p['home']]], 0, mt_rand(1, 2)) as [$agent, $ip]) {
            $created = $now - mt_rand(1, 20) * 86400 - mt_rand(0, 36000);
            db_insert('user_tokens', ['user_id' => $u['id'], 'type' => 'remember', 'selector' => 'demo' . substr(md5('gimt-demo-' . $i), 0, 16),
                'token_hash' => hash('sha256', 'gimt-demo-token-' . $i . '-' . mt_rand()), 'expires_at' => date('Y-m-d H:i:s', $created + 30 * 86400),
                'ip_address' => $ip, 'user_agent' => $agent, 'created_at' => date('Y-m-d H:i:s', $created)]);
            $i++;
        }
    }

    /* ---------------------------------------------------------- 10. notifications */
    $templates = [
        ['admissions', 'admission', 'New application received', fn () => (($a = $applicants ? demo_pick($applicants) : null) ? "{$a['name']} applied for " : demo_pick($students)['name'] . ' applied for ') . demo_pick($programs), 'admin/admissions/list', 'user-plus'],
        ['enquiries', 'enquiry', 'New enquiry', fn () => ($enquirers ? demo_pick($enquirers) : demo_pick($students)['name']) . ' enquired about ' . demo_pick($programs), 'admin/enquiries', 'message-circle'],
        ['contact_messages', 'contact', 'New contact message', fn () => 'Message from ' . demo_pick($students)['name'] . ': ' . demo_pick(['Hostel availability for girls', 'Scholarship eligibility', 'Bus route to Ghaziabad', 'Migration certificate']), 'admin/contact-messages', 'inbox'],
        ['fees', 'fee', 'Payment received', fn () => $inr(demo_pick([12500, 45000, 60000, 90000])) . ' received from ' . demo_pick($students)['name'] . ' via ' . demo_pick(['UPI', 'online gateway', 'bank transfer']), 'admin/fees/payments', 'indian-rupee'],
        ['fees', 'fee', 'Fees overdue', fn () => mt_rand(12, 48) . ' students have fees overdue by more than 15 days.', 'admin/fees/invoices', 'alarm-clock'],
        ['attendance', 'attendance', 'Low attendance alert', fn () => mt_rand(6, 19) . ' students in ' . demo_pick($programs) . ' Sem ' . mt_rand(1, 6) . ' are below 75% attendance.', 'admin/attendance/reports', 'user-x'],
        ['examination', 'exam', 'Exam reminder', fn () => 'End Semester Examinations begin on ' . date('d M Y', strtotime('+' . mt_rand(20, 45) . ' days')) . '. Hall tickets are ready.', 'admin/examination', 'clipboard-list'],
        ['certificates', 'certificate', 'Certificate generated', fn () => demo_pick(['Bonafide', 'Character', 'Provisional']) . ' certificate issued to ' . demo_pick($students)['name'] . '.', 'admin/certificates', 'award'],
        ['placement', 'application', 'Placement applications', fn () => mt_rand(18, 64) . ' students applied for the ' . demo_pick($companies) . ' drive.', 'admin/placement/applications', 'briefcase'],
        ['security', 'security', 'Multiple failed sign-ins', fn () => mt_rand(9, 46) . ' failed sign-in attempts from ' . demo_pick(['45.155.205.233', '185.220.101.47', '91.240.118.172']) . '.', 'admin/security?tab=logins', 'shield-alert'],
        ['security', 'security', 'Account locked', fn () => 'Account vikas.rana@gimt.ac.in was locked after 5 failed login attempts from 10.20.1.45.', 'admin/security', 'shield-alert'],
        ['backup', 'system', 'Backup completed', fn () => 'Weekly database backup completed (' . demo_pick(['1.8', '2.1', '2.4']) . ' MB).', 'admin/backup', 'database'],
        ['settings', 'system', 'System update', fn () => demo_pick(['Email settings were updated by Administrator.', 'Academic session 2026-27 is now current.', 'Website maintenance window completed.']), 'admin/settings', 'settings'],
        ['notices', 'system', 'Notice published', fn () => '"' . demo_pick($notices) . '" is now live for students and staff.', 'admin/notices', 'megaphone'],
    ];
    $userPerms = [];
    foreach ($users as $u) {
        $uid = (int) $u['id'];
        $super = system_user_is_super($uid);
        $userPerms[$uid] = $super ? '*' : array_flip(db_column('SELECT DISTINCT p.module FROM user_roles ur JOIN role_permissions rp ON rp.role_id = ur.role_id JOIN permissions p ON p.id = rp.permission_id WHERE ur.user_id = ? AND p.action IN (\'view\', \'manage\')', [$uid]));
    }
    $notes = [];
    foreach ($users as $u) {
        $uid = (int) $u['id'];
        if ($u['status'] !== 'active') {
            continue;
        }
        $mine = array_values(array_filter($templates, fn ($t) => $userPerms[$uid] === '*' || isset($userPerms[$uid][$t[0]])));
        if (!$mine) {
            continue;
        }
        $count = $userPerms[$uid] === '*' ? 34 : mt_rand(8, 18);
        for ($k = 0; $k < $count; $k++) {
            $t = demo_pick($mine);
            $ago = (int) (pow(mt_rand(0, 1000) / 1000, 1.6) * 30 * 86400) + mt_rand(300, 3000);
            $ts = $now - $ago;
            $notes[] = ['user_id' => $uid, 'type' => $t[1], 'title' => $t[2], 'message' => mb_substr(($t[3])(), 0, 500), 'url' => $t[4], 'icon' => $t[5],
                'is_read' => $ago > 2 * 86400 ? (mt_rand(1, 100) <= 92 ? 1 : 0) : (mt_rand(1, 100) <= 30 ? 1 : 0),
                'read_at' => null, 'created_at' => date('Y-m-d H:i:s', $ts)];
        }
    }
    foreach ($notes as &$n) {
        $n['read_at'] = $n['is_read'] ? date('Y-m-d H:i:s', strtotime($n['created_at']) + mt_rand(300, 20000)) : null;
        if ($n['read_at'] && strtotime($n['read_at']) > $now) {
            $n['read_at'] = date('Y-m-d H:i:s', $now);
        }
    }
    unset($n);
    $insert('notifications', $notes);

    save_setting('_demo_seed_system', json_encode(['at' => date('Y-m-d H:i:s'), 'ranges' => $ranges]), 'internal');

    /* ---------------------------------------------------------- 11. initial backup */
    backup_create('database', 'seed', 'Initial snapshot after loading demo data');
};
