<?php
/**
 * Demo seed: ~1,290 students across programs/batches/sections (1,248 active) with parents and
 * current-semester academic records.
 */
require_once __DIR__ . '/lib/demo_data.php';

return function (array $opts): void {
    demo_seed(20262);
    $sessionByYear = db_pairs("SELECT CAST(LEFT(name, 4) AS UNSIGNED) AS y, id FROM academic_sessions");
    $currentSession = (int) db_value('SELECT id FROM academic_sessions WHERE is_current = 1');
    $programs = db_all('SELECT id, code, short_name, department_id, total_semesters, duration_years FROM programs');
    $quota = ['BBA' => 220, 'MBA' => 160, 'BTCSE' => 240, 'BTAIDS' => 120, 'BCA' => 150, 'MCA' => 60, 'BCOMH' => 140, 'MCOM' => 40, 'BSCDS' => 60, 'DCAP' => 30, 'CDM' => 15, 'CDA' => 13];
    $bloods = ['A+', 'B+', 'O+', 'AB+', 'A-', 'B-', 'O-', 'AB-'];
    $categories = ['General' => 55, 'OBC' => 27, 'SC' => 10, 'ST' => 3, 'EWS' => 5];

    $students = [];
    $serial = 0;
    $usedEmails = [];
    foreach ($programs as $p) {
        $years = max(1, (int) ceil((float) $p['duration_years']));
        $total = $quota[$p['code']] ?? 30;
        // more students in junior batches
        $weights = [];
        for ($k = 0; $k < $years; $k++) {
            $weights[$k] = $years - $k + 1;
        }
        $wsum = array_sum($weights);
        $assigned = 0;
        for ($k = 0; $k < $years; $k++) {
            $count = $k === $years - 1 ? $total - $assigned : (int) round($total * $weights[$k] / $wsum);
            $assigned += $count;
            $startYear = 2026 - $k;
            $sem = (int) $p['total_semesters'] === 1 ? 1 : min((int) $p['total_semesters'], 1 + 2 * $k);
            $batchId = db_value('SELECT id FROM batches WHERE program_id = ? AND start_year = ?', [$p['id'], $startYear]);
            $sections = db_column('SELECT id FROM sections WHERE program_id = ? AND semester_no = ? AND academic_session_id = ? ORDER BY name', [$p['id'], $sem, $currentSession]);
            $courseIds = db_column('SELECT id FROM courses WHERE program_id = ?', [$p['id']]);
            for ($i = 0; $i < $count; $i++) {
                $serial++;
                $gender = mt_rand(1, 100) <= 46 ? 'female' : 'male';
                $first = demo_pick(demo_first_names($gender));
                $last = demo_pick(demo_last_names());
                [$city, $state, $pin] = demo_city();
                $email = demo_email($first, $last, demo_pick(['gmail.com', 'gmail.com', 'yahoo.com', 'outlook.com']), $serial);
                $fatherFirst = demo_pick(demo_first_names('male'));
                $motherFirst = demo_pick(demo_first_names('female'));
                $yy = substr((string) $startYear, 2);
                $mobile = demo_phone();
                $students[] = [
                    'student_uid' => 'GIMT' . $yy . $p['code'] . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                    'admission_no' => 'ADM/' . $startYear . '/' . str_pad((string) $serial, 5, '0', STR_PAD_LEFT),
                    'roll_no' => $p['code'] . $yy . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                    'enrollment_no' => 'SU' . $startYear . str_pad((string) (100000 + $serial), 6, '0', STR_PAD_LEFT),
                    'first_name' => $first, 'last_name' => $last, 'gender' => $gender,
                    'dob' => demo_date(($startYear - ($p['code'] === 'MBA' || $p['code'] === 'MCA' || $p['code'] === 'MCOM' ? 24 : 20)) . '-01-01', ($startYear - ($p['code'] === 'MBA' || $p['code'] === 'MCA' || $p['code'] === 'MCOM' ? 21 : 17)) . '-12-31'),
                    'blood_group' => demo_pick($bloods), 'category' => demo_weighted($categories), 'nationality' => 'Indian',
                    'mobile' => $mobile, 'email' => $email, 'whatsapp' => $mobile,
                    'address' => mt_rand(1, 999) . ', ' . demo_pick(['Sector ', 'Block ', 'Gali No. ', 'Pocket ']) . mt_rand(1, 60) . ', ' . demo_pick(['Shastri Nagar', 'Raj Nagar', 'Indirapuram', 'Alpha 2', 'Gamma 1', 'Model Town', 'Civil Lines', 'Vasundhara', 'Mayur Vihar']),
                    'city' => $city, 'state' => $state, 'country' => 'India', 'pincode' => $pin,
                    'father_name' => $fatherFirst . ' ' . $last, 'mother_name' => $motherFirst . ' ' . $last,
                    'guardian_name' => $fatherFirst . ' ' . $last, 'guardian_relation' => 'Father', 'guardian_phone' => demo_phone(),
                    'emergency_contact_name' => $fatherFirst . ' ' . $last, 'emergency_contact_phone' => demo_phone(),
                    'department_id' => $p['department_id'], 'program_id' => $p['id'], 'course_id' => $courseIds ? demo_pick($courseIds) : null,
                    'batch_id' => $batchId ?: null, 'current_semester' => $sem, 'section_id' => $sections ? $sections[$i % count($sections)] : null,
                    'academic_session_id' => $sessionByYear[$startYear] ?? $currentSession,
                    'admission_date' => demo_date($startYear . '-06-15', $startYear . '-08-20'), 'admission_type' => demo_weighted(['regular' => 88, 'lateral' => 4, 'management' => 5, 'scholarship' => 3]),
                    'previous_qualification' => in_array($p['code'], ['MBA', 'MCA', 'MCOM'], true) ? demo_pick(['BBA', 'B.Com', 'BCA', 'B.Sc', 'B.Tech', 'BA']) : '12th (' . demo_pick(['CBSE', 'UP Board', 'ICSE', 'Haryana Board']) . ')',
                    'previous_percentage' => mt_rand(5200, 9650) / 100,
                    'is_hosteller' => mt_rand(1, 100) <= 28 ? 1 : 0, 'uses_transport' => mt_rand(1, 100) <= 35 ? 1 : 0,
                    'status' => 'active', 'created_at' => demo_datetime($startYear . '-06-15', $startYear . '-08-25'),
                ];
            }
        }
    }
    // A few inactive / dropped / suspended records for realistic filters
    $extra = 42;
    for ($j = 0; $j < $extra; $j++) {
        $s = $students[mt_rand(0, count($students) - 1)];
        $serial++;
        $gender = mt_rand(1, 100) <= 46 ? 'female' : 'male';
        $s['first_name'] = demo_pick(demo_first_names($gender));
        $s['gender'] = $gender;
        $s['student_uid'] = 'GIMTX' . str_pad((string) $serial, 6, '0', STR_PAD_LEFT);
        $s['admission_no'] = 'ADM/X/' . str_pad((string) $serial, 5, '0', STR_PAD_LEFT);
        $s['roll_no'] = null;
        $s['email'] = demo_email($s['first_name'], (string) $s['last_name'], 'gmail.com', $serial);
        $s['status'] = demo_weighted(['inactive' => 10, 'dropped' => 20, 'suspended' => 4, 'graduated' => 8]);
        $students[] = $s;
    }
    demo_bulk_insert('students', $students);

    // Parents + academic records
    $rows = db_all('SELECT id, father_name, mother_name, guardian_phone, program_id, current_semester, section_id, roll_no, status FROM students');
    $parents = [];
    $academic = [];
    foreach ($rows as $r) {
        $parents[] = ['student_id' => $r['id'], 'relation' => 'father', 'name' => $r['father_name'], 'phone' => $r['guardian_phone'], 'occupation' => demo_pick(['Business', 'Service', 'Government Employee', 'Farmer', 'Teacher', 'Engineer', 'Doctor', 'Self Employed']), 'annual_income' => mt_rand(3, 30) * 100000, 'is_emergency_contact' => 1];
        $parents[] = ['student_id' => $r['id'], 'relation' => 'mother', 'name' => $r['mother_name'], 'phone' => demo_phone(), 'occupation' => demo_pick(['Homemaker', 'Teacher', 'Service', 'Business', 'Doctor']), 'annual_income' => null, 'is_emergency_contact' => 0];
        if ($r['status'] === 'active') {
            $academic[] = ['student_id' => $r['id'], 'academic_session_id' => $currentSession, 'program_id' => $r['program_id'], 'semester_no' => $r['current_semester'], 'section_id' => $r['section_id'], 'roll_no' => $r['roll_no'], 'status' => 'studying'];
        }
    }
    demo_bulk_insert('student_parents', $parents);
    demo_bulk_insert('student_academic', $academic);
    db_exec("INSERT INTO sequences (name, current_value) VALUES ('student_serial', ?) ON DUPLICATE KEY UPDATE current_value = VALUES(current_value)", [$serial]);
};
