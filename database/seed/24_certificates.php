<?php
/**
 * Demo data for the Certificates unit (deterministic, re-runnable).
 *   ~60 certificates across all seven types for real students, rendered through the same pipeline as the admin
 *   (template + placeholders -> content snapshot, GIMT-{CODE}-{YEAR}-{n} numbers, HMAC verification hash):
 *     bonafide for active students (a few past their 180-day validity), internship for senior students, character,
 *     transfer + migration for students who left, course completion + provisional for graduates, a revoked pair, a
 *     reissue chain, pending approval requests and one rejected request.
 *   Public verification log (QR scans, web and API lookups, mistyped numbers) for the last ~6 months.
 * Dates are anchored on the day the seeder runs.
 *
 * Owns: certificates, certificate_verifications, sequences named certificate:*.
 * Needs: 04_certificate_templates, students (11_students), student_academic (CGPA).
 */
return function (array $opts): void {
    require_once __DIR__ . '/lib/demo_data.php';
    require_once APP_ROOT . '/app/services/certificates.php';
    demo_seed(2424);

    // ---------------------------------------------------------------- clear owned rows (children first)
    db_exec('DELETE FROM certificate_verifications');
    db_exec('UPDATE certificates SET reissued_from_id = NULL');
    db_exec('DELETE FROM certificates');
    db_exec("DELETE FROM sequences WHERE name LIKE 'certificate:%'");

    $templates = [];
    foreach (db_all("SELECT * FROM certificate_templates WHERE status = 'active' ORDER BY is_default DESC, id") as $t) {
        $templates[$t['type']] = $templates[$t['type']] ?? $t;
    }
    if (count($templates) < 7) {
        fwrite(STDERR, "  ! certificate templates missing - run 04_certificate_templates first\n");
        return;
    }
    $today = date('Y-m-d');
    $day = fn (int $daysAgo) => date('Y-m-d', strtotime($today . ' -' . $daysAgo . ' days'));
    $uid = fn (string $email) => (int) (db_value('SELECT id FROM users WHERE email = ?', [$email]) ?: 0) ?: null;
    $admin = $uid('admin@gimt.ac.in');
    $exams = $uid('exams@gimt.ac.in') ?: $admin;
    $office = $uid('admin.office@gimt.ac.in') ?: $admin;
    $staffUser = $uid('staff@gimt.ac.in') ?: $office;
    $sessions = db_all('SELECT id, start_date, end_date FROM academic_sessions ORDER BY start_date');
    $sessionFor = function (string $date) use ($sessions): ?int {
        foreach ($sessions as $s) {
            if ($date >= $s['start_date'] && $date <= $s['end_date']) {
                return (int) $s['id'];
            }
        }
        return null;
    };
    $students = function (string $sql, array $args = []): array {
        return array_map('intval', db_column($sql, $args));
    };

    // ---------------------------------------------------------------- pick real students
    $withCgpa = 'EXISTS (SELECT 1 FROM student_academic sa WHERE sa.student_id = s.id AND sa.cgpa IS NOT NULL)';
    $activeAll = $students("SELECT s.id FROM students s WHERE s.status = 'active' ORDER BY s.id");
    $senior = $students("SELECT s.id FROM students s WHERE s.status = 'active' AND s.current_semester >= 5 AND $withCgpa ORDER BY s.id");
    $graduated = $students("SELECT s.id FROM students s WHERE s.status = 'graduated' AND $withCgpa ORDER BY s.id");
    $left = $students("SELECT s.id FROM students s WHERE s.status IN ('dropped', 'inactive') AND $withCgpa ORDER BY s.id");
    if (!$activeAll) {
        return;
    }
    $take = function (array &$pool, int $n): array {
        $out = [];
        while ($n-- > 0 && $pool) {
            $i = mt_rand(0, count($pool) - 1);
            $out[] = $pool[$i];
            array_splice($pool, $i, 1);
        }
        return $out;
    };
    $activePool = $activeAll;
    $seniorPool = $senior;
    $leftPool = $left;

    // A certificate can only be issued after the student joined: pick a random student of the pool admitted on or
    // before $by (deterministic - mt_rand is seeded).
    $admitted = db_pairs('SELECT id, admission_date FROM students');
    $takeBy = function (array &$pool, string $by) use ($admitted): ?int {
        $ok = array_values(array_filter(array_keys($pool), fn ($i) => empty($admitted[$pool[$i]]) || $admitted[$pool[$i]] <= $by));
        if (!$ok) {
            return null;
        }
        $i = $ok[mt_rand(0, count($ok) - 1)];
        $sid = $pool[$i];
        array_splice($pool, $i, 1);
        return $sid;
    };
    // Semester a student was in on a past date: one semester per 1 Jan / 1 Jul boundary crossed since then.
    $semAt = function (int $sem, string $date) use ($today): int {
        $n = 0;
        for ($y = (int) substr($date, 0, 4); $y <= (int) substr($today, 0, 4); $y++) {
            foreach (["$y-01-01", "$y-07-01"] as $b) {
                if ($b > $date && $b <= $today) {
                    $n++;
                }
            }
        }
        return max(1, $sem - $n);
    };

    $purposes = ['Opening a bank account', 'Passport application', 'Education loan', 'Scholarship application', 'Railway concession', 'Visa application',
        'Internship application', 'Hostel admission', 'Driving licence application', 'Sports quota trials', 'Aadhaar address update', 'Bus pass concession'];
    $companies = [
        ['Infosys Limited, Pune', 'Business Analyst Intern', 'Customer churn analytics dashboard', 'Mr. Vivek Nair'],
        ['Tata Consultancy Services, Noida', 'Software Engineering Intern', 'Microservices migration of the HR portal', 'Ms. Ritu Sharma'],
        ['HCLTech, Noida', 'Data Science Intern', 'Demand forecasting with gradient boosting', 'Mr. Arvind Menon'],
        ['Deloitte India, Gurugram', 'Audit & Assurance Intern', null, 'Ms. Pallavi Gupta'],
        ['Wipro Limited, Greater Noida', 'Cloud Operations Intern', 'Automated cost reports on AWS', null],
        ['HDFC Bank, New Delhi', 'Retail Banking Intern', 'Customer onboarding turnaround study', 'Mr. Sandeep Arora'],
        ['Zomato, Gurugram', 'Marketing Intern', 'Campus ambassador growth campaign', null],
        ['Amazon Development Centre, Noida', 'SDE Intern', 'Inventory reconciliation service', 'Mr. Karthik Iyer'],
        ['Maruti Suzuki India, Gurugram', 'Supply Chain Intern', 'Vendor lead-time analysis', 'Mr. Deepak Bhatia'],
        ['Paytm, Noida', 'Product Analyst Intern', null, null],
    ];
    $leavingReasons = ['Higher studies', 'Migration to another institution', 'Family relocation', 'Personal reasons', 'Financial reasons', 'Medical reasons'];
    $migratingTo = ['Delhi University, New Delhi', 'Amity University, Noida', 'Chaudhary Charan Singh University, Meerut', 'Sharda University, Greater Noida',
        'Dr. A.P.J. Abdul Kalam Technical University, Lucknow', 'any other recognised University / Institution'];

    // ---------------------------------------------------------------- the plan (type, student, issue date, fields, status …)
    $plan = [];
    // Bonafide: 30 for active students, weighted to recent months; four are older than the 180-day validity (expired)
    for ($i = 0; $i < 30; $i++) {
        $ago = $i < 4 ? mt_rand(195, 300) : ($i < 10 ? mt_rand(60, 180) : mt_rand(1, 58));
        if (!($sid = $takeBy($activePool, $day($ago + 14)))) {
            continue;
        }
        $plan[] = ['type' => 'bonafide', 'student' => $sid, 'date' => $day($ago), 'fields' => ['purpose' => demo_pick($purposes)], 'by' => demo_pick([$exams, $office, $exams])];
    }
    // Character: 6 for active students, 2 for students who left
    for ($i = 0; $i < 8; $i++) {
        $ago = mt_rand(3, 200);
        if (!($sid = $i < 6 ? $takeBy($activePool, $day($ago + 30)) : $takeBy($leftPool, $day($ago + 120)))) {
            continue;
        }
        $plan[] = ['type' => 'character', 'student' => $sid, 'date' => $day($ago), 'by' => $exams,
            'fields' => ['conduct' => demo_weighted(['Excellent' => 3, 'Very Good' => 5, 'Good' => 3]), 'purpose' => demo_pick(['Campus placement', 'Higher studies abroad', 'Employment verification', '', 'Police verification'])]];
    }
    // Internship: 8 senior students, summer internships (May–July) certified in Aug–Sep
    foreach ($take($seniorPool, 8) as $sid) {
        [$company, $role, $project, $mentor] = demo_pick($companies);
        $start = date('Y-m-d', strtotime(date('Y', strtotime($today)) . '-05-' . str_pad((string) mt_rand(12, 28), 2, '0', STR_PAD_LEFT)));
        if ($start > $day(100)) {
            $start = $day(mt_rand(130, 150));
        }
        $end = date('Y-m-d', strtotime($start . ' +' . mt_rand(6, 9) . ' weeks'));
        $plan[] = ['type' => 'internship', 'student' => $sid, 'date' => min($day(1), date('Y-m-d', strtotime($end . ' +' . mt_rand(10, 40) . ' days'))), 'by' => $office,
            'fields' => ['company' => $company, 'internship_role' => $role, 'internship_start' => $start, 'internship_end' => $end, 'project_title' => $project ?? '', 'mentor_name' => $mentor ?? '']];
    }
    // Transfer (+ migration for three of them) for students who left
    for ($k = 0; $k < 4; $k++) {
        $leaving = $day(mt_rand(40, 220));
        // at least a semester of study before leaving
        if (!($sid = $takeBy($leftPool, date('Y-m-d', strtotime($leaving . ' -150 days'))))) {
            continue;
        }
        $sem = (int) db_value('SELECT current_semester FROM students WHERE id = ?', [$sid]);
        $short = (string) db_value('SELECT p.short_name FROM students s JOIN programs p ON p.id = s.program_id WHERE s.id = ?', [$sid]);
        $reason = $k < 3 ? demo_pick(['Higher studies', 'Migration to another institution']) : demo_pick($leavingReasons);
        $tcDate = date('Y-m-d', strtotime($leaving . ' +' . mt_rand(3, 12) . ' days'));
        $plan[] = ['type' => 'transfer', 'student' => $sid, 'date' => min($day(1), $tcDate), 'by' => $exams, 'fields' => [
            'leaving_date' => $leaving, 'leaving_reason' => $reason, 'last_exam' => $short . ' Semester ' . max(1, $sem - 1) . ' — ' . date('F Y', strtotime($leaving . ' -2 months')),
            'conduct' => demo_pick(['Good', 'Very Good', 'Good']), 'dues_cleared' => 'Yes']];
        if ($k < 3) {
            $plan[] = ['type' => 'migration', 'student' => $sid, 'date' => min($day(1), date('Y-m-d', strtotime($tcDate . ' +' . mt_rand(2, 10) . ' days'))), 'by' => $exams,
                'fields' => ['leaving_date' => $leaving, 'migrating_to' => demo_pick($migratingTo), 'purpose' => demo_pick(['Admission to another university', '', 'Higher studies'])]];
        }
    }
    // Graduates: course completion + provisional (results declared ~4 months ago)
    // Only graduates who studied with the institute for at least a year before the results (never a completion
    // certificate that predates the admission).
    $completion = $day(mt_rand(110, 130));
    $graduated = array_values(array_filter($graduated, fn ($sid) => empty($admitted[$sid]) || $admitted[$sid] <= date('Y-m-d', strtotime($completion . ' -300 days'))));
    foreach ($graduated as $k => $sid) {
        $plan[] = ['type' => 'provisional', 'student' => $sid, 'date' => $day(mt_rand(95, 108)), 'by' => $exams, 'fields' => ['completion_date' => $completion, 'division' => '']];
        $plan[] = ['type' => 'course_completion', 'student' => $sid, 'date' => $day(mt_rand(70, 92)), 'by' => $exams, 'fields' => ['completion_date' => $completion, 'division' => '']];
    }
    // Revoked: a character certificate (disciplinary) + a bonafide reissued after a correction (chain)
    $revokedChar = $takeBy($activePool, $day(100));
    if ($revokedChar) {
        $plan[] = ['type' => 'character', 'student' => $revokedChar, 'date' => $day(75), 'by' => $office, 'fields' => ['conduct' => 'Good', 'purpose' => 'Employment verification'],
            'revoke' => ['days_after' => 21, 'by' => $admin, 'reason' => 'Disciplinary committee decision (ref. DC/2026/14) — conduct entry no longer valid.']];
    }
    $chainStudent = $takeBy($activePool, $day(50));
    if ($chainStudent) {
        $plan[] = ['type' => 'bonafide', 'student' => $chainStudent, 'date' => $day(34), 'by' => $office, 'fields' => ['purpose' => 'Passport applicaton'], 'chain' => 'old'];
        $plan[] = ['type' => 'bonafide', 'student' => $chainStudent, 'date' => $day(32), 'by' => $exams, 'fields' => ['purpose' => 'Passport application'], 'chain' => 'new',
            'remarks' => 'Reissued: purpose was misspelt on the original copy.'];
    }
    $revokedBon = $takeBy($activePool, $day(65));
    if ($revokedBon) {
        $plan[] = ['type' => 'bonafide', 'student' => $revokedBon, 'date' => $day(48), 'by' => $office, 'fields' => ['purpose' => 'Education loan'],
            'revoke' => ['days_after' => 2, 'by' => $exams, 'reason' => 'Issued in error — the student had applied for a fee structure letter, not a bonafide certificate.']];
    }
    // Pending approval (requested by the office in the last few days) + one rejected request
    foreach (array_filter([$takeBy($activePool, $day(20)), $takeBy($activePool, $day(20)), $takeBy($activePool, $day(20))]) as $k => $sid) {
        $type = $k === 2 ? 'character' : 'bonafide';
        $plan[] = ['type' => $type, 'student' => $sid, 'date' => $day($k), 'by' => $staffUser, 'status' => 'pending',
            'fields' => $type === 'bonafide' ? ['purpose' => demo_pick($purposes)] : ['conduct' => 'Very Good', 'purpose' => 'Higher studies abroad']];
    }
    $rej = $takeBy($activePool, $day(25));
    if ($rej) {
        $plan[] = ['type' => 'bonafide', 'student' => $rej, 'date' => $day(9), 'by' => $staffUser, 'status' => 'rejected', 'fields' => ['purpose' => 'Visa application'],
            'revoke' => ['days_after' => 1, 'by' => $exams, 'reason' => 'Duplicate request — a valid bonafide for visa was issued last month.']];
    }

    usort($plan, fn ($a, $b) => strcmp($a['date'], $b['date']) ?: ($a['student'] <=> $b['student']));

    // ---------------------------------------------------------------- render + insert
    $created = [];
    $chainOldId = null;
    foreach ($plan as $item) {
        $tpl = $templates[$item['type']];
        $in = ['student_id' => $item['student'], 'type' => $item['type'], 'template_id' => (int) $tpl['id'], 'issue_date' => $item['date'], 'fields' => $item['fields']];
        [$errors, $ctx] = cert_validate_issue($in);
        unset($errors['type']); // "already has one" rules: the demo intentionally includes a reissue chain
        if ($errors || !$ctx['student'] || !$ctx['template']) {
            continue;
        }
        // Back-dated certificates state the semester the student was in on the issue date.
        if ($ctx['student']['status'] === 'active') {
            $ctx['student']['current_semester'] = $semAt((int) $ctx['student']['current_semester'], $item['date']);
        }
        $status = $item['status'] ?? 'issued';
        $no = cert_next_number($item['type'], $item['date']);
        $composed = cert_compose($ctx, ['certificate_no' => $no, 'verify_url' => cert_verify_url($no)]);
        if ($composed['missing'] || $composed['unknown']) {
            continue; // the student lacks data the template needs (e.g. no CGPA yet)
        }
        $s = $ctx['student'];
        $hour = sprintf('%02d:%02d:%02d', mt_rand(10, 16), mt_rand(0, 59), mt_rand(0, 59));
        $createdAt = $item['date'] . ' ' . $hour;
        if ($createdAt > date('Y-m-d H:i:s')) {
            $createdAt = date('Y-m-d H:i:s', strtotime('-' . mt_rand(20, 90) . ' minutes'));
        }
        $approvedBy = $status === 'issued' ? ($item['by'] === $staffUser ? $exams : $item['by']) : null;
        $approvedAt = $status === 'issued' ? $createdAt : null;
        $printed = $status === 'issued' ? mt_rand(1, 3) : 0;
        $row = [
            'certificate_no' => $no, 'type' => $item['type'], 'template_id' => (int) $tpl['id'], 'student_id' => (int) $s['id'],
            'academic_session_id' => $sessionFor($item['date']), 'title' => mb_substr($composed['title'], 0, 190),
            'purpose' => ($ctx['fields']['purpose'] ?? '') !== '' ? $ctx['fields']['purpose'] : null,
            'content_html' => $composed['body'],
            'data' => json_encode([
                'fields' => $ctx['fields'], 'design' => $composed['design'], 'title' => $composed['title'], 'subtitle' => $composed['subtitle'],
                'student' => ['name' => cert_student_name($s), 'uid' => $s['student_uid'], 'program' => $s['program_name'], 'program_short' => $s['program_short'], 'gender' => $s['gender'], 'photo' => $s['photo']],
                'template' => ['id' => (int) $tpl['id'], 'name' => $tpl['name']],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'issue_date' => $item['date'], 'valid_until' => $ctx['valid_until'], 'verification_hash' => cert_hash($no, (int) $s['id'], $item['type'], $item['date']),
            'status' => $status === 'rejected' ? 'rejected' : $status, 'revoked_reason' => null, 'remarks' => $item['remarks'] ?? null,
            'issued_by' => $item['by'], 'approved_by' => $approvedBy, 'approved_at' => $approvedAt, 'revoked_by' => null, 'revoked_at' => null,
            'reissued_from_id' => ($item['chain'] ?? null) === 'new' ? $chainOldId : null, 'verified_count' => 0, 'printed_count' => $printed,
            'last_printed_at' => $printed ? date('Y-m-d H:i:s', strtotime($createdAt . ' +' . mt_rand(3, 40) . ' minutes')) : null, 'last_verified_at' => null,
            'created_at' => $createdAt,
        ];
        if (!empty($item['revoke'])) {
            $at = date('Y-m-d H:i:s', min(time() - 600, strtotime($createdAt . ' +' . $item['revoke']['days_after'] . ' days +' . mt_rand(1, 5) . ' hours')));
            $row['status'] = $status === 'rejected' ? 'rejected' : 'revoked';
            $row['revoked_reason'] = $item['revoke']['reason'];
            $row['revoked_by'] = $item['revoke']['by'];
            $row['revoked_at'] = $at;
        }
        $id = db_insert('certificates', $row);
        if (($item['chain'] ?? null) === 'old') {
            $chainOldId = $id;
        }
        if (($item['chain'] ?? null) === 'new' && $chainOldId) {
            db_update('certificates', ['status' => 'revoked', 'revoked_reason' => 'Superseded by ' . $no . ': purpose corrected on request', 'revoked_by' => $item['by'],
                'revoked_at' => $createdAt], 'id = ?', [$chainOldId]);
        }
        $created[] = ['id' => $id, 'no' => $no, 'issue_date' => $item['date'], 'created_at' => $createdAt];
    }

    // ---------------------------------------------------------------- public verification log
    $rows = db_all('SELECT id, certificate_no, status, issue_date, valid_until, revoked_at, created_at FROM certificates WHERE status IN (\'issued\', \'revoked\')');
    $uas = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15',
        'python-requests/2.32 (HR verification platform)',
    ];
    $ipPrefixes = ['49.36.112', '103.21.58', '117.97.141', '182.69.33', '106.215.90', '223.190.82', '157.34.21', '27.60.148'];
    $log = [];
    $now = time();
    foreach ($rows as $c) {
        if (mt_rand(1, 100) > 62) {
            continue;
        }
        $n = demo_weighted([1 => 30, 2 => 30, 3 => 20, 4 => 12, 6 => 8]);
        $from = strtotime($c['created_at']) + 3600;
        for ($i = 0; $i < $n; $i++) {
            $ts = mt_rand($from, max($from + 60, $now - 300));
            $at = date('Y-m-d H:i:s', $ts);
            $result = 'valid';
            if ($c['status'] === 'revoked' && $c['revoked_at'] && $at >= $c['revoked_at']) {
                $result = 'revoked';
            } elseif ($c['valid_until'] && substr($at, 0, 10) > $c['valid_until']) {
                $result = 'expired';
            }
            $source = demo_weighted(['qr' => 55, 'web' => 35, 'api' => 10]);
            $log[] = ['certificate_id' => (int) $c['id'], 'certificate_no' => $c['certificate_no'], 'result' => $result, 'source' => $source,
                'ip_address' => demo_pick($ipPrefixes) . '.x', 'user_agent' => $source === 'api' ? $uas[4] : demo_pick(array_slice($uas, 0, 4)), 'created_at' => $at];
        }
    }
    // Mistyped / forged numbers
    for ($i = 0; $i < 14; $i++) {
        $ts = mt_rand($now - 170 * 86400, $now - 600);
        $fake = demo_pick([
            'GIMT-BON-' . date('Y', $ts) . '-' . str_pad((string) mt_rand(200, 999), 5, '0', STR_PAD_LEFT),
            'GIMT/BC/' . date('Y', $ts) . '/' . mt_rand(100, 999),
            'GIMT-TC-2025-' . str_pad((string) mt_rand(1, 99), 5, '0', STR_PAD_LEFT),
            'GIMT-PRV-' . date('Y', $ts) . '-0' . mt_rand(1000, 9999),
        ]);
        $log[] = ['certificate_id' => null, 'certificate_no' => $fake, 'result' => 'not_found', 'source' => demo_pick(['web', 'web', 'api']),
            'ip_address' => demo_pick($ipPrefixes) . '.x', 'user_agent' => demo_pick($uas), 'created_at' => date('Y-m-d H:i:s', $ts)];
    }
    usort($log, fn ($a, $b) => strcmp($a['created_at'], $b['created_at']));
    demo_bulk_insert('certificate_verifications', $log);
    db_exec("UPDATE certificates c JOIN (SELECT certificate_id, COUNT(*) AS n, MAX(created_at) AS last_at FROM certificate_verifications
             WHERE certificate_id IS NOT NULL GROUP BY certificate_id) v ON v.certificate_id = c.id SET c.verified_count = v.n, c.last_verified_at = v.last_at");

    fwrite(STDOUT, sprintf("    %d certificates, %d verification log rows\n", count($created), count($log)));
};
