<?php
/**
 * Demo seed: Faculty & staff management (HR).
 *  - leave_types (leave policy)
 *  - enriches faculty / staff rows created by 10_academics_people (bank, PAN, IFSC, PIN, sections, consistent titles/dates)
 *    — faculty/staff rows are referenced by other units, so they are updated in place, never deleted
 *  - employee_leaves for the 2026-27 session (past, on-leave-today, pending and upcoming)
 *  - employee_documents with small generated PDF files in storage/private/employee-docs/demo
 * Re-runnable: deletes leaves, documents (+ their files) and leave types first.
 */
require_once __DIR__ . '/lib/demo_data.php';
require_once APP_ROOT . '/app/services/hr.php';

return function (array $opts): void {
    demo_seed(20263);
    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');

    // ---------------- Clean owned data ----------------
    foreach (db_column('SELECT file_path FROM employee_documents') as $path) {
        delete_upload($path);
    }
    db_exec('DELETE FROM employee_documents');
    db_exec('DELETE FROM employee_leaves');
    db_exec('DELETE FROM leave_types');
    db_exec("DELETE FROM activity_logs WHERE module = 'leaves' AND user_agent = 'seed'");   // audit rows written by this seeder only

    // ---------------- Leave policy ----------------
    $types = [
        // code, name, quota, applies_to, paid, max consecutive, color, description
        ['casual', 'Casual Leave', 12, 'all', 1, 3, 'blue', 'Short personal leave; at most 3 working days at a time.'],
        ['sick', 'Sick Leave', 10, 'all', 1, null, 'pink', 'Illness or medical appointments. Medical certificate for more than 2 days.'],
        ['earned', 'Earned Leave', 15, 'all', 1, 15, 'green', 'Planned leave earned with service.'],
        ['duty', 'On Duty (OD)', 0, 'all', 1, null, 'purple', 'Official duty away from campus: exam duty, industry visits, admission drives.'],
        ['academic', 'Academic Leave (FDP / Conference)', 7, 'faculty', 1, 7, 'cyan', 'Faculty development programmes, conferences and workshops.'],
        ['compensatory', 'Compensatory Off', 6, 'staff', 1, 2, 'amber', 'Against work on holidays / weekends.'],
        ['maternity', 'Maternity Leave', 180, 'all', 1, 180, 'amber', 'As per the Maternity Benefit Act.'],
        ['paternity', 'Paternity Leave', 15, 'all', 1, 15, 'cyan', 'Within six months of childbirth.'],
        ['unpaid', 'Leave Without Pay', 0, 'all', 0, null, 'slate', 'Counted as loss of pay (LOP) in payroll.'],
    ];
    foreach ($types as $i => [$code, $name, $quota, $applies, $paid, $max, $color, $desc]) {
        db_insert('leave_types', ['code' => $code, 'name' => $name, 'annual_quota' => $quota, 'applies_to' => $applies, 'is_paid' => $paid,
            'max_consecutive' => $max, 'color' => $color, 'description' => $desc, 'sort_order' => $i + 1, 'status' => 'active']);
    }
    db_exec("UPDATE leave_types SET gender = 'female' WHERE code = 'maternity'");
    db_exec("UPDATE leave_types SET gender = 'male' WHERE code = 'paternity'");
    $quota = array_column(array_map(fn ($t) => ['c' => $t[0], 'q' => $t[2]], $types), 'q', 'c');

    // ---------------- Enrich faculty ----------------
    $banks = [['State Bank of India', 'SBIN'], ['HDFC Bank', 'HDFC'], ['ICICI Bank', 'ICIC'], ['Punjab National Bank', 'PUNB'], ['Axis Bank', 'UTIB'],
        ['Canara Bank', 'CNRB'], ['Bank of Baroda', 'BARB'], ['Kotak Mahindra Bank', 'KKBK']];
    $pins = ['Greater Noida' => '201310', 'Noida' => '201301', 'Ghaziabad' => '201001', 'Delhi' => '110092', 'Meerut' => '250001', 'Agra' => '282001', 'Lucknow' => '226001',
        'Gurugram' => '122001', 'Faridabad' => '121001', 'Aligarh' => '202001', 'Dehradun' => '248001', 'Jaipur' => '302001', 'Patna' => '800001', 'Bulandshahr' => '203001', 'Mathura' => '281001'];
    $letters = 'ABCDEFGHJKLMNPRSTUVWXYZ';
    $pan = function (string $last) use ($letters): string {
        $p = '';
        for ($i = 0; $i < 3; $i++) {
            $p .= $letters[mt_rand(0, strlen($letters) - 1)];
        }
        return $p . 'P' . strtoupper(substr(preg_replace('/[^a-z]/i', '', $last) ?: 'X', 0, 1)) . mt_rand(1000, 9999) . $letters[mt_rand(0, strlen($letters) - 1)];
    };
    $bankFields = function (string $last, bool $withBank = true) use ($banks, $pan): array {
        [$bank, $code] = demo_pick($banks);
        return $withBank ? ['bank_name' => $bank, 'bank_ifsc' => $code . '0' . str_pad((string) mt_rand(1000, 999999), 6, '0', STR_PAD_LEFT),
            'bank_account' => (string) mt_rand(1000, 9999) . str_pad((string) mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT) . (string) mt_rand(10, 99), 'pan_no' => $pan($last)]
            : ['bank_name' => null, 'bank_ifsc' => null, 'bank_account' => null, 'pan_no' => $pan($last)];
    };
    $research = [
        'MGT' => ['Behavioural finance, corporate governance', 'Consumer behaviour, digital marketing analytics', 'Organisational behaviour, talent management', 'Supply chain resilience, operations strategy'],
        'CSE' => ['Deep learning for computer vision', 'Cloud-native systems, distributed computing', 'Network security, intrusion detection', 'Natural language processing, LLM evaluation'],
        'DCA' => ['Web engineering, progressive web apps', 'Database optimisation, big-data systems', 'Mobile computing, IoT applications'],
        'COM' => ['GST compliance, indirect taxation', 'Financial reporting, IFRS convergence', 'Banking sector reforms, fintech adoption'],
        'SCI' => ['Bayesian statistics, survey sampling', 'Applied mathematics, numerical methods'],
        'PSD' => ['Performance marketing, SEO', 'Business intelligence, data storytelling'],
    ];
    $faculty = db_all('SELECT f.*, d.code AS dcode FROM faculty f LEFT JOIN departments d ON d.id = f.department_id ORDER BY f.id');
    foreach ($faculty as $f) {
        $u = [];
        $dob = $f['dob'] ?: '1985-06-15';
        $age = (int) date_diff(date_create($dob), date_create($today))->y;
        // Joining at least 24 years after birth
        $minJoin = date('Y-m-d', strtotime($dob . ' +24 years'));
        $join = $f['joining_date'] && $f['joining_date'] >= $minJoin ? $f['joining_date'] : max($minJoin, date('Y', strtotime($minJoin)) . '-07-' . str_pad((string) mt_rand(1, 20), 2, '0', STR_PAD_LEFT));
        if ($join > '2026-07-15') {
            $join = '2026-07-' . str_pad((string) mt_rand(1, 15), 2, '0', STR_PAD_LEFT);
        }
        $tenure = (time() - strtotime($join)) / (365.25 * 86400);
        $exp = max((float) $f['experience_years'], round($tenure + mt_rand(0, 6), 0));
        $exp = min($exp, max(1, $age - 23));
        $u['joining_date'] = $join;
        $u['experience_years'] = $exp;
        $hasPhd = (bool) preg_match('/Ph\.?D/i', (string) $f['qualification']);
        if ($f['title'] === 'Dr.' && !$hasPhd) {
            $u['qualification'] = 'Ph.D. (' . ($f['specialization'] ?: 'Management') . '), ' . $f['qualification'];
            $hasPhd = true;
        } elseif ($hasPhd && $f['title'] !== 'Dr.') {
            $u['title'] = 'Dr.';
        }
        $u['pincode'] = $pins[$f['city']] ?? '201310';
        $u['alternate_phone'] = mt_rand(0, 100) < 45 ? demo_phone() : null;
        $u += $bankFields((string) $f['last_name'], $f['employment_type'] !== 'visiting');
        $u['research_interests'] = $hasPhd || mt_rand(0, 100) < 40 ? demo_pick($research[$f['dcode']] ?? $research['MGT']) : null;
        $title = $u['title'] ?? $f['title'];
        $desig = (string) $f['designation'];
        $deptName = (string) db_value('SELECT name FROM departments WHERE id = ?', [(int) $f['department_id']]);
        $u['bio'] = sprintf('%s %s %s is %s %s in the %s with %d years of teaching and industry experience, specialising in %s.%s', $title, $f['first_name'], $f['last_name'],
            preg_match('/^[AEIOU]/i', $desig) ? 'an' : 'a', $desig, $deptName ?: 'institute', (int) $exp, strtolower((string) $f['specialization']) ?: 'the core curriculum',
            $u['research_interests'] ? ' Research interests include ' . strtolower($u['research_interests']) . '.' : '');
        $u['linkedin_url'] = mt_rand(0, 100) < 45 ? 'https://www.linkedin.com/in/' . strtolower(preg_replace('/[^a-z]/i', '', $f['first_name']) . '-' . preg_replace('/[^a-z]/i', '', (string) $f['last_name'])) . '-gimt' : null;
        db_update('faculty', $u, 'id = ?', [(int) $f['id']]);
        if ($f['user_id']) {
            // keep the linked login's display name in step with the (possibly corrected) title
            db_update('users', ['name' => trim(($u['title'] ?? $f['title']) . ' ' . $f['first_name'] . ' ' . $f['last_name'])], 'id = ?', [(int) $f['user_id']]);
        }
    }

    // ---------------- Enrich staff ----------------
    $sectionFor = [
        'Registrar' => 'Registrar Office', 'Administrative Officer' => 'Administration Office', 'Office Assistant' => 'Administration Office', 'Receptionist' => 'Front Office',
        'Accounts Officer' => 'Accounts Office', 'Accountant' => 'Accounts Office', 'Accounts Assistant' => 'Fee Counter', 'Chief Librarian' => 'Central Library', 'Library Assistant' => 'Central Library',
        'Boys Hostel Warden' => 'Boys Hostel', 'Girls Hostel Warden' => 'Girls Hostel', 'Mess Supervisor' => 'Hostel Mess', 'Transport In-charge' => 'Transport Office', 'Transport Supervisor' => 'Transport Office',
        'System Administrator' => 'IT Cell', 'IT Support Engineer' => 'IT Cell', 'Lab Assistant' => 'Computer Lab', 'Lab Technician' => 'Science Lab', 'Maintenance Supervisor' => 'Estate & Maintenance',
        'Electrician' => 'Estate & Maintenance', 'Security Supervisor' => 'Security Office', 'Security Guard' => 'Main Gate', 'Placement Coordinator' => 'Training & Placement Cell',
        'Admission Counsellor' => 'Admission Office', 'Exam Cell Assistant' => 'Examination Cell', 'Alumni Relations Executive' => 'Alumni Relations Office',
    ];
    $employmentFor = ['Security Guard' => 'outsourced', 'Electrician' => 'contract', 'Receptionist' => 'contract', 'Mess Supervisor' => 'contract', 'IT Support Engineer' => 'probation', 'Admission Counsellor' => 'contract'];
    $deptIds = db_pairs('SELECT code, id FROM departments');
    $staff = db_all('SELECT * FROM staff ORDER BY id');
    foreach ($staff as $s) {
        $u = ['section' => $sectionFor[$s['designation']] ?? 'Administration Office', 'pincode' => $pins[$s['city']] ?? '201310',
            'alternate_phone' => mt_rand(0, 100) < 30 ? demo_phone() : null, 'employment_type' => $employmentFor[$s['designation']] ?? 'permanent'];
        $dob = $s['dob'] ?: '1988-01-01';
        $minJoin = date('Y-m-d', strtotime($dob . ' +20 years'));
        if (!$s['joining_date'] || $s['joining_date'] < $minJoin) {
            $u['joining_date'] = min('2026-06-30', max($minJoin, date('Y', strtotime($minJoin)) . '-04-01'));
        }
        if ($s['designation'] === 'Lab Assistant') {
            $u['department_id'] = $deptIds['CSE'] ?? null;
        } elseif ($s['designation'] === 'Lab Technician') {
            $u['department_id'] = $deptIds['SCI'] ?? null;
            $u['section'] = 'Science Lab';
        }
        $u += $bankFields((string) $s['last_name'], $u['employment_type'] !== 'outsourced');
        if ($u['employment_type'] === 'outsourced') {
            $u['salary'] = mt_rand(16, 22) * 1000;
        }
        db_update('staff', $u, 'id = ?', [(int) $s['id']]);
    }
    // Mess supervisor is on long medical leave
    $messId = (int) db_value("SELECT id FROM staff WHERE designation = 'Mess Supervisor' ORDER BY id LIMIT 1");
    if ($messId) {
        db_update('staff', ['status' => 'on_leave'], 'id = ?', [$messId]);
    }

    // ---------------- Leaves ----------------
    $adminId = (int) (db_value("SELECT id FROM users WHERE username = 'admin'") ?: db_value('SELECT MIN(id) FROM users'));
    $approverId = (int) (db_value("SELECT id FROM users WHERE username = 'administrator'") ?: $adminId);
    $reasons = [
        'casual' => ['Personal work at home town', 'Family function (sister\'s engagement)', 'Bank and property registration work', 'Child\'s school parent-teacher meeting', 'House shifting', 'Attending a family wedding'],
        'sick' => ['Viral fever, advised rest by doctor', 'Dental surgery and recovery', 'Migraine, unable to attend', 'Food poisoning', 'Medical check-up and tests', 'Back pain — physiotherapy sessions'],
        'earned' => ['Annual family vacation', 'Travel to native place for Durga Puja', 'Planned trip during Diwali week', 'Personal leave for home renovation'],
        'duty' => ['External examiner duty at AKTU', 'Industry visit with BBA students', 'Admission counselling drive at Meerut', 'University evaluation duty', 'NAAC documentation meeting at university'],
        'academic' => ['AICTE-ATAL FDP on Generative AI', 'Presenting a paper at IIM Lucknow conference', 'Workshop on outcome-based education', 'International conference on data science (paper presentation)'],
        'compensatory' => ['Worked on Sunday during admission week', 'Duty during convocation weekend'],
        'maternity' => ['Maternity leave as per policy'],
        'paternity' => ['Birth of child'],
        'unpaid' => ['Extended personal leave — balance exhausted', 'Family emergency out of station'],
    ];
    $rejectRemarks = ['Mid-semester examinations scheduled on these dates.', 'Insufficient staff coverage during admission week. Please reschedule.', 'Overlaps with NAAC peer team visit.', 'Apply for casual leave instead; earned leave needs 15 days notice.'];
    $approveRemarks = [null, null, 'Approved. Arrange substitute classes.', 'Approved. Hand over pending work to the section.', 'Approved.', null];
    $people = [];
    foreach (db_all("SELECT id, user_id, gender, status FROM faculty WHERE status IN ('active', 'on_leave') ORDER BY id") as $r) {
        $people[] = ['faculty', (int) $r['id'], $r['user_id'] ? (int) $r['user_id'] : null, $r['gender'], $r['status']];
    }
    foreach (db_all("SELECT id, user_id, gender, status FROM staff WHERE status IN ('active', 'on_leave') ORDER BY id") as $r) {
        $people[] = ['staff', (int) $r['id'], $r['user_id'] ? (int) $r['user_id'] : null, $r['gender'], $r['status']];
    }
    $rows = [];
    $taken = [];      // [ref][type] => days (pending + approved)
    $ranges = [];     // [ref] => [[from, to], ...]
    $addLeave = function (array $p, string $type, string $from, string $to, ?string $status = null, bool $half = false) use (&$rows, &$taken, &$ranges, $quota, $today, $reasons, $rejectRemarks, $approveRemarks, $adminId, $approverId): bool {
        $ref = $p[0] . $p[1];
        foreach ($ranges[$ref] ?? [] as [$a, $b]) {
            if ($from <= $b && $to >= $a) {
                return false;
            }
        }
        $days = hr_working_days($from, $to, $half)['days'];
        if ($days <= 0) {
            return false;
        }
        if ($status === null) {
            if ($to < $today) {
                $status = demo_weighted(['approved' => 84, 'rejected' => 10, 'cancelled' => 6]);
            } elseif ($from <= $today) {
                $status = 'approved';
            } else {
                $status = demo_weighted(['pending' => 40, 'approved' => 60]);
            }
        }
        $counts = in_array($status, ['approved', 'pending'], true);
        if ($counts && ($quota[$type] ?? 0) > 0 && ($taken[$ref][$type] ?? 0) + $days > $quota[$type]) {
            return false;
        }
        $created = min($now = date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime($from . ' -' . mt_rand(1, 12) . ' days +' . mt_rand(9, 17) . ' hours')));
        if ($from > $today) {
            $created = date('Y-m-d H:i:s', strtotime($today . ' -' . mt_rand(0, 6) . ' days +' . mt_rand(9, 17) . ' hours'));
            $created = min($created, date('Y-m-d H:i:s'));
        }
        $decided = in_array($status, ['approved', 'rejected'], true) ? min(date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime($created . ' +' . mt_rand(3, 40) . ' hours'))) : null;
        $remarks = $status === 'rejected' ? demo_pick($rejectRemarks) : ($status === 'approved' ? demo_pick($approveRemarks) : ($status === 'cancelled' ? 'Cancelled by the employee — plans changed.' : null));
        $rows[] = [
            'employee_type' => $p[0], 'employee_id' => $p[1], 'leave_type' => $type, 'from_date' => $from, 'to_date' => $to, 'days' => $days, 'is_half_day' => $half ? 1 : 0,
            'reason' => demo_pick($reasons[$type]), 'applied_by' => $p[2] && mt_rand(0, 100) < 70 ? $p[2] : $adminId, 'status' => $status,
            'approved_by' => $decided ? $approverId : null, 'approved_at' => $decided, 'remarks' => $remarks,
            'cancelled_at' => $status === 'cancelled' ? date('Y-m-d H:i:s', strtotime($created . ' +1 day')) : null, 'created_at' => $created,
        ];
        $ranges[$ref][] = [$from, $to];
        if ($counts) {
            $taken[$ref][$type] = ($taken[$ref][$type] ?? 0) + $days;
        }
        return true;
    };
    $workday = function (string $d): string {
        while ((int) date('w', strtotime($d)) === 0) {
            $d = date('Y-m-d', strtotime($d . ' +1 day'));
        }
        return $d;
    };
    $lengths = ['casual' => [1, 2], 'sick' => [1, 4], 'earned' => [3, 6], 'duty' => [1, 2], 'academic' => [2, 5], 'compensatory' => [1, 1], 'unpaid' => [1, 3]];

    // On leave today: the faculty member marked "on leave" + a few others, and the mess supervisor
    $onLeaveToday = [];
    foreach ($people as $i => $p) {
        if ($p[4] === 'on_leave') {
            $onLeaveToday[] = $i;
        }
    }
    foreach ([3, 11, 24, 38, 57, 66] as $i) {
        if (isset($people[$i]) && !in_array($i, $onLeaveToday, true)) {
            $onLeaveToday[] = $i;
        }
    }
    foreach ($onLeaveToday as $k => $i) {
        $p = $people[$i];
        if ($p[4] === 'on_leave') {
            $addLeave($p, 'sick', date('Y-m-d', strtotime($today . ' -6 days')), date('Y-m-d', strtotime($today . ' +7 days')), 'approved');
        } else {
            $type = ['casual', 'duty', 'sick', 'academic', 'earned', 'casual'][$k % 6];
            if ($type === 'academic' && $p[0] !== 'faculty') {
                $type = 'duty';
            }
            $len = $type === 'earned' ? 4 : ($type === 'academic' ? 3 : 1);
            $from = date('Y-m-d', strtotime($today . ' -' . ($k % 2) . ' days'));
            $addLeave($p, $type, $workday($from), date('Y-m-d', strtotime($from . ' +' . ($len - 1 + ($k % 2)) . ' days')), 'approved');
        }
    }
    // A maternity leave
    foreach ($people as $p) {
        if ($p[0] === 'faculty' && $p[3] === 'female' && $p[4] === 'active') {
            $addLeave($p, 'maternity', '2026-08-03', '2027-01-29', 'approved');
            break;
        }
    }
    // Regular leaves across the session
    foreach ($people as $p) {
        $n = demo_weighted([1 => 25, 2 => 35, 3 => 25, 4 => 15]);
        for ($j = 0; $j < $n; $j++) {
            $type = demo_weighted($p[0] === 'faculty'
                ? ['casual' => 40, 'sick' => 22, 'earned' => 8, 'duty' => 14, 'academic' => 12, 'unpaid' => 4]
                : ['casual' => 42, 'sick' => 22, 'earned' => 10, 'duty' => 8, 'compensatory' => 13, 'unpaid' => 5]);
            [$lo, $hi] = $lengths[$type];
            $from = $workday(demo_date('2026-07-06', '2026-11-27'));
            $len = mt_rand($lo, $hi);
            $to = date('Y-m-d', strtotime($from . ' +' . ($len - 1) . ' days'));
            $half = $type === 'casual' && $len === 1 && mt_rand(0, 100) < 25;
            $addLeave($p, $type, $from, $to, null, $half);
        }
    }
    // Make sure the approval queue has fresh requests
    foreach ([2, 9, 17, 29, 44, 61, 70] as $k => $i) {
        if (isset($people[$i])) {
            $from = $workday(date('Y-m-d', strtotime($today . ' +' . (2 + $k * 3) . ' days')));
            $addLeave($people[$i], $k % 3 === 0 ? 'casual' : ($k % 3 === 1 ? 'earned' : 'duty'), $from, date('Y-m-d', strtotime($from . ' +' . ($k % 3 === 1 ? 3 : 1) . ' days')), 'pending');
        }
    }
    usort($rows, fn ($a, $b) => strcmp($a['created_at'], $b['created_at']));
    demo_bulk_insert('employee_leaves', $rows);

    // Audit trail for the demo applications (shown on profile Activity tabs)
    $logs = [];
    foreach (db_all("SELECT l.*, lt.name AS type_name FROM employee_leaves l JOIN leave_types lt ON lt.code = l.leave_type ORDER BY l.id") as $l) {
        $p = hr_person($l['employee_type'], (int) $l['employee_id']);
        $label = sprintf('%s of %s (%s to %s, %s)', $l['type_name'], $p['full_name'] ?? '', format_date($l['from_date']), format_date($l['to_date']), hr_days_label($l['days']));
        $logs[] = ['user_id' => $l['applied_by'], 'action' => 'create', 'module' => 'leaves', 'record_id' => (string) $l['id'], 'description' => 'Applied ' . $label, 'status' => 'success',
            'ip_address' => '127.0.0.1', 'user_agent' => 'seed', 'created_at' => $l['created_at']];
        if ($l['approved_at']) {
            $logs[] = ['user_id' => $l['approved_by'], 'action' => $l['status'] === 'approved' ? 'approve' : 'reject', 'module' => 'leaves', 'record_id' => (string) $l['id'],
                'description' => ($l['status'] === 'approved' ? 'Approved ' : 'Rejected ') . $label . ($l['remarks'] ? ' — ' . $l['remarks'] : ''), 'status' => 'success',
                'ip_address' => '127.0.0.1', 'user_agent' => 'seed', 'created_at' => $l['approved_at']];
        }
    }
    demo_bulk_insert('activity_logs', $logs);

    // ---------------- Documents ----------------
    $pdf = function (string $title, string $sub): string {
        $esc = fn ($s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], preg_replace('/[^\x20-\x7E]/', '', $s));
        $text = 'BT /F1 20 Tf 72 740 Td (' . $esc($title) . ') Tj ET BT /F1 12 Tf 72 712 Td (' . $esc($sub) . ') Tj ET '
            . 'BT /F1 10 Tf 72 680 Td (Global Institute of Management & Technology, Greater Noida) Tj ET '
            . 'BT /F1 9 Tf 72 664 Td (Demo document generated for the SmartCampus personnel file.) Tj ET '
            . '0.043 0.165 0.357 RG 2 w 72 650 m 540 650 l S';
        $objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            "<< /Length " . strlen($text) . " >>\nstream\n" . $text . "\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $i => $o) {
            $offsets[] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $out .= sprintf("%010d 00000 n \n", $off);
        }
        return $out . 'trailer << /Size ' . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    };
    $dir = 'storage/private/employee-docs/demo';
    if (!is_dir(APP_ROOT . '/' . $dir)) {
        mkdir(APP_ROOT . '/' . $dir, 0775, true);
    }
    $docRows = [];
    $people = array_merge(
        array_map(fn ($r) => ['faculty', $r], db_all("SELECT id, TRIM(CONCAT_WS(' ', title, first_name, last_name)) AS name, employee_id, qualification, joining_date, user_id FROM faculty ORDER BY id")),
        array_map(fn ($r) => ['staff', $r], db_all("SELECT id, TRIM(CONCAT_WS(' ', first_name, last_name)) AS name, employee_id, qualification, joining_date, user_id FROM staff ORDER BY id"))
    );
    foreach ($people as [$type, $p]) {
        $docs = [['appointment_letter', 'Appointment Letter']];
        $docs[] = ['resume', 'Resume / CV'];
        $docs[] = ['id_proof', demo_pick(['Aadhaar Card', 'PAN Card', 'Passport'])];
        if ($type === 'faculty') {
            $docs[] = ['degree', preg_match('/Ph\.?D/i', (string) $p['qualification']) ? 'Ph.D. Degree Certificate' : 'Post-graduate Degree Certificate'];
            if (mt_rand(0, 100) < 60) {
                $docs[] = ['experience', 'Experience Letter — previous institution'];
            }
        } elseif (mt_rand(0, 100) < 50) {
            $docs[] = ['experience', 'Experience Certificate'];
        }
        foreach ($docs as $k => [$docType, $title]) {
            if ($k > 1 && mt_rand(0, 100) < 25) {
                continue;
            }
            $file = $dir . '/' . $type . '-' . $p['id'] . '-' . $docType . '.pdf';
            $content = $pdf($title, $p['name'] . ' (' . $p['employee_id'] . ')');
            file_put_contents(APP_ROOT . '/' . $file, $content);
            $status = demo_weighted(['verified' => 72, 'pending' => 22, 'rejected' => 6]);
            $uploaded = date('Y-m-d H:i:s', strtotime(max((string) $p['joining_date'], '2024-01-01') . ' +' . mt_rand(0, 20) . ' days +' . mt_rand(9, 17) . ' hours'));
            $uploaded = min($uploaded, $now);
            $docRows[] = [
                'employee_type' => $type, 'employee_id' => (int) $p['id'], 'doc_type' => $docType, 'title' => $title, 'file_path' => $file,
                'original_name' => slugify($title) . '.pdf', 'mime' => 'application/pdf', 'size_bytes' => strlen($content),
                'status' => $status, 'verified_by' => $status !== 'pending' ? $approverId : null,
                'verified_at' => $status !== 'pending' ? min($now, date('Y-m-d H:i:s', strtotime($uploaded . ' +2 days'))) : null,
                'remarks' => $status === 'rejected' ? 'Scan is unclear — please upload a clearer copy.' : null,
                'expiry_date' => $docType === 'id_proof' && str_contains($title, 'Passport') ? demo_date('2028-01-01', '2034-12-31') : null,
                'uploaded_by' => $adminId, 'created_at' => $uploaded,
            ];
        }
    }
    demo_bulk_insert('employee_documents', $docRows);
};
