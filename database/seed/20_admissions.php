<?php
/**
 * Demo seed (admissions unit): ~300 applications for the current session (2026-27) spread across every pipeline stage
 * with stage history, document metadata (+ small placeholder PDFs/photos in private storage), follow-ups, assessment
 * scores, offer letters and admission fees; ~150 enquiries; ~40 website contact messages; ~30 feedback tickets.
 * Confirmed + converted applications are linked to existing 2026 first-semester students.
 *
 * Re-runnable: clears the tables owned by the admissions unit first.  php tools/seed.php 20_admissions
 */
require_once __DIR__ . '/lib/demo_data.php';

if (!function_exists('adm_seed_pdf')) {
    /** Minimal one-page PDF with a title and a few text lines (placeholder scan). */
    function adm_seed_pdf(array $lines): string
    {
        $esc = fn (string $s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], preg_replace('/[^\x20-\x7E]/', '-', $s));
        $c = "0.043 0.165 0.357 RG 2 w 36 36 523 770 re S\n0.043 0.165 0.357 rg 36 760 523 46 re f\n";
        $c .= 'BT /F2 16 Tf 1 1 1 rg 52 778 Td (' . $esc('GLOBAL INSTITUTE OF MANAGEMENT & TECHNOLOGY') . ") Tj ET\n";
        $c .= 'BT /F2 20 Tf 0.043 0.165 0.357 rg 52 712 Td (' . $esc($lines[0]) . ") Tj ET\n";
        $y = 680;
        foreach (array_slice($lines, 1) as $l) {
            $c .= 'BT /F1 12 Tf 0.2 0.25 0.33 rg 52 ' . $y . ' Td (' . $esc($l) . ") Tj ET\n";
            $y -= 22;
        }
        $c .= 'BT /F1 9 Tf 0.5 0.55 0.6 rg 52 52 Td (' . $esc('Demo placeholder document generated for GIMT SmartCampus.') . ") Tj ET\n";
        $objs = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>',
            '<< /Length ' . strlen($c) . " >>\nstream\n" . $c . 'endstream',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $i => $o) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        return $pdf . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    /** Small passport-style placeholder photo (PNG) with initials. */
    function adm_seed_photo(string $name, int $seed): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }
        $im = imagecreatetruecolor(150, 190);
        $palette = [[219, 230, 246], [210, 242, 220], [237, 233, 254], [254, 243, 199], [255, 228, 230], [207, 250, 254]];
        [$r, $g, $b] = $palette[$seed % count($palette)];
        imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
        $navy = imagecolorallocate($im, 11, 42, 91);
        imagefilledellipse($im, 75, 78, 70, 80, imagecolorallocate($im, 255, 255, 255));
        imagefilledellipse($im, 75, 200, 130, 120, imagecolorallocate($im, 255, 255, 255));
        $ini = strtoupper(implode('', array_map(fn ($p) => $p[0] ?? '', array_slice(preg_split('/\s+/', trim($name)), 0, 2))));
        imagestring($im, 5, 75 - strlen($ini) * 5, 70, $ini, $navy);
        ob_start();
        imagepng($im);
        imagedestroy($im);
        return (string) ob_get_clean();
    }
}

return function (array $opts): void {
    require_once APP_ROOT . '/app/services/admissions.php';
    demo_seed(20262027);
    $T = strtotime('today');
    $nowTs = time();
    $clamp = fn (int $ts) => date('Y-m-d H:i:s', min($ts, $nowTs - 300));
    $ago = function (float $minDays, float $maxDays) use ($T, $clamp) {
        $day = $T - (int) round(mt_rand((int) ($minDays * 100), (int) ($maxDays * 100)) / 100 * 86400);
        return $clamp(strtotime(date('Y-m-d', $day)) + mt_rand(9 * 3600, 18 * 3600 + 1800));
    };
    $between = fn (string $a, string $b) => $clamp(mt_rand(strtotime($a), max(strtotime($a), strtotime($b))));
    $addDays = fn (string $dt, float $days) => date('Y-m-d H:i:s', strtotime($dt) + (int) ($days * 86400));
    // keep generated timestamps inside office hours (09:00-19:00)
    $inHours = function (string $dt) use ($clamp): string {
        $ts = strtotime($dt);
        $h = (int) date('G', $ts);
        if ($h >= 9 && $h < 19) {
            return $dt;
        }
        return $clamp(strtotime(date('Y-m-d', $ts)) + mt_rand(9 * 3600, 18 * 3600 + 1800));
    };

    // ---------------------------------------------------------------- Clear owned tables (children first)
    db_exec("DELETE FROM message_logs WHERE related_type IN ('contact_message')");
    foreach (['admission_followups', 'admission_stage_history', 'admission_documents', 'contact_messages', 'feedback'] as $t) {
        db_exec("DELETE FROM $t");
    }
    db_exec('UPDATE enquiries SET admission_id = NULL');
    db_exec('UPDATE students SET admission_id = NULL WHERE admission_id IS NOT NULL');
    db_exec('DELETE FROM admissions');
    db_exec('DELETE FROM enquiries');
    foreach (['enquiries', 'admissions', 'admission_documents', 'admission_followups', 'admission_stage_history', 'contact_messages', 'feedback'] as $t) {
        db_exec("ALTER TABLE $t AUTO_INCREMENT = 1");
    }
    $seedDir = APP_ROOT . '/storage/private/admission-docs/seed';
    if (is_dir($seedDir)) {
        foreach (glob($seedDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
    } else {
        @mkdir($seedDir, 0775, true);
    }

    // ---------------------------------------------------------------- Reference data
    $sessionId = (int) db_value('SELECT id FROM academic_sessions WHERE is_current = 1') ?: (int) db_value('SELECT id FROM academic_sessions ORDER BY start_date DESC LIMIT 1');
    $sessionName = (string) db_value('SELECT name FROM academic_sessions WHERE id = ?', [$sessionId]);
    $programs = [];
    foreach (db_all("SELECT id, code, short_name, level, category FROM programs WHERE status = 'active'") as $p) {
        $programs[$p['code']] = $p;
    }
    $courses = [];
    foreach (db_all('SELECT id, program_id FROM courses') as $c) {
        $courses[(int) $c['program_id']][] = (int) $c['id'];
    }
    $userBySlug = db_pairs('SELECT r.slug, MIN(ur.user_id) FROM user_roles ur JOIN roles r ON r.id = ur.role_id GROUP BY r.slug');
    $admin = (int) ($userBySlug['super-admin'] ?? 1);
    $officer = (int) ($userBySlug['admission-officer'] ?? $admin);
    $office = (int) ($userBySlug['administrator'] ?? $admin);
    $counsellorWeights = [$officer => 55, $office => 25, $admin => 8, 0 => 12];
    $pickCounsellor = fn () => (int) demo_weighted($counsellorWeights) ?: null;
    $programWeights = ['BBA' => 18, 'MBA' => 14, 'BTCSE' => 20, 'BTAIDS' => 9, 'BCA' => 12, 'MCA' => 5, 'BCOMH' => 9, 'MCOM' => 3, 'BSCDS' => 4, 'DCAP' => 3, 'CDM' => 2, 'CDA' => 1];
    $programWeights = array_intersect_key($programWeights, $programs);
    $sourceWeights = ['website' => 30, 'walk_in' => 16, 'phone' => 10, 'social_media' => 14, 'referral' => 10, 'campaign' => 8, 'education_fair' => 7, 'whatsapp' => 5];
    $boards = ['CBSE' => 55, 'UP Board' => 18, 'ICSE' => 10, 'Haryana Board' => 6, 'Rajasthan Board' => 5, 'Bihar Board' => 6];
    $universities = ['CCS University, Meerut', 'AKTU, Lucknow', 'University of Delhi', 'GGSIP University', 'Amity University', 'MDU Rohtak', 'Dr. A.P.J. Abdul Kalam Technical University'];
    $schools = ['Delhi Public School', 'Kendriya Vidyalaya', 'Ryan International School', 'DAV Public School', 'St. Joseph\'s Convent', 'Amity International School', 'Govt. Inter College', 'Lotus Valley School', 'Bal Bharati Public School'];
    $occupations = ['Business', 'Government Service', 'Private Service', 'Teacher', 'Farmer', 'Engineer', 'Doctor', 'Self Employed', 'Bank Officer', 'Army (Retd.)'];
    $motherOcc = ['Homemaker', 'Teacher', 'Private Service', 'Business', 'Nurse', 'Government Service'];
    $bloods = ['A+', 'B+', 'O+', 'AB+', 'A-', 'B-', 'O-'];
    $categories = ['General' => 52, 'OBC' => 28, 'SC' => 10, 'ST' => 3, 'EWS' => 7];
    $quotas = ['general' => 80, 'management' => 8, 'sports' => 3, 'scholarship' => 5, 'nri' => 1, 'defence' => 2, 'lateral' => 1];
    $streets = ['Sector ', 'Block ', 'Pocket ', 'Gali No. ', 'House No. '];
    $localities = ['Shastri Nagar', 'Raj Nagar Extension', 'Indirapuram', 'Alpha 2', 'Gamma 1', 'Model Town', 'Civil Lines', 'Vasundhara', 'Mayur Vihar', 'Knowledge Park', 'Rohini', 'Janakpuri'];
    $isPg = fn (string $code) => in_array($programs[$code]['level'] ?? 'UG', ['PG'], true);
    $examFor = function (string $code): array {
        return match ($code) {
            'BTCSE', 'BTAIDS' => ['JEE Main', mt_rand(5500, 9850) / 100],
            'MBA' => [demo_pick(['CAT', 'MAT', 'CMAT', 'GIMT-MAT']), mt_rand(5000, 9700) / 100],
            'MCA' => [demo_pick(['NIMCET', 'GIMT Aptitude Test']), mt_rand(5000, 9400) / 100],
            'DCAP', 'CDM', 'CDA' => ['GIMT Aptitude Test', mt_rand(4500, 9300) / 100],
            default => [demo_pick(['CUET (UG)', 'GIMT Aptitude Test']), mt_rand(5000, 9600) / 100],
        };
    };
    $feeFor = fn (string $code) => match ($programs[$code]['level'] ?? 'UG') { 'PG' => 25000.0, 'Diploma', 'Certificate' => 5000.0, default => 15000.0 };
    $modes = ['upi' => 40, 'bank_transfer' => 22, 'card' => 14, 'cash' => 12, 'cheque' => 6, 'dd' => 3, 'online' => 3];
    $refFor = fn (string $mode) => match ($mode) {
        'upi' => 'UPI/' . mt_rand(100000, 999999) . mt_rand(100000, 999999), 'bank_transfer' => 'UTR' . mt_rand(1000000, 9999999) . mt_rand(10000, 99999),
        'card' => 'POS-' . mt_rand(100000, 999999), 'cheque' => 'CHQ ' . mt_rand(100000, 999999), 'dd' => 'DD ' . mt_rand(100000, 999999),
        'online' => 'pay_' . substr(md5((string) mt_rand()), 0, 14), default => null,
    };
    $stageOrder = array_keys(admission_stages());

    // ---------------------------------------------------------------- Applications plan
    $plan = ['confirmed' => 95, 'fee_payment' => 18, 'approval' => 16, 'entrance_interview' => 22, 'document_verification' => 28, 'application' => 44,
        'enquiry' => 16, 'waitlisted' => 10, 'rejected' => 34, 'withdrawn' => 17];
    $convertedTarget = 80;
    $studentsByProgram = [];
    foreach (db_all("SELECT s.*, p.code FROM students s JOIN programs p ON p.id = s.program_id WHERE s.academic_session_id = ? AND s.status = 'active' AND s.current_semester = 1 AND s.admission_id IS NULL ORDER BY s.id", [$sessionId]) as $s) {
        $studentsByProgram[$s['code']][] = $s;
    }
    foreach ($studentsByProgram as &$list) {
        // deterministic shuffle
        for ($i = count($list) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        }
    }
    unset($list);

    $apps = [];
    $usedPhones = [];
    $mkPerson = function (string $code) use (&$usedPhones, $boards, $universities, $schools, $occupations, $motherOcc, $bloods, $categories, $streets, $localities, $isPg) {
        $gender = mt_rand(1, 100) <= 46 ? 'female' : 'male';
        $first = demo_pick(demo_first_names($gender));
        $last = demo_pick(demo_last_names());
        [$city, $state, $pin] = demo_city();
        do {
            $phone = demo_phone();
        } while (isset($usedPhones[$phone]));
        $usedPhones[$phone] = true;
        $pg = $isPg($code);
        $passYear = $pg ? 2026 - mt_rand(0, 3) : 2026 - (mt_rand(1, 100) <= 85 ? 0 : 1);
        $tenthYear = $pg ? $passYear - 5 - mt_rand(0, 1) : $passYear - 2;
        $fatherFirst = demo_pick(demo_first_names('male'));
        return [
            'first_name' => $first, 'middle_name' => mt_rand(1, 100) <= 12 ? demo_pick(['Kumar', 'Kumari', 'Pratap', 'Singh', 'Devi', 'Lal']) : null, 'last_name' => $last,
            'gender' => $gender, 'dob' => demo_date(($passYear - ($pg ? 22 : 18)) . '-01-01', ($passYear - ($pg ? 20 : 17)) . '-12-31'), 'blood_group' => demo_pick($bloods),
            'category' => demo_weighted($categories), 'nationality' => 'Indian', 'aadhaar_no' => (string) mt_rand(2000, 9999) . mt_rand(1000, 9999) . mt_rand(1000, 9999),
            'email' => demo_email($first, $last, demo_pick(['gmail.com', 'gmail.com', 'gmail.com', 'yahoo.com', 'outlook.com', 'rediffmail.com']), mt_rand(1, 999)),
            'phone' => $phone, 'whatsapp' => $phone,
            'address' => mt_rand(1, 999) . ', ' . demo_pick($streets) . mt_rand(1, 60) . ', ' . demo_pick($localities), 'city' => $city, 'state' => $state, 'country' => 'India', 'pincode' => $pin,
            'father_name' => $fatherFirst . ' ' . $last, 'father_phone' => demo_phone(), 'father_occupation' => demo_pick($occupations),
            'mother_name' => demo_pick(demo_first_names('female')) . ' ' . $last, 'mother_phone' => mt_rand(1, 100) <= 70 ? demo_phone() : null, 'mother_occupation' => demo_pick($motherOcc),
            'guardian_name' => null, 'guardian_relation' => null, 'guardian_phone' => null, 'family_income' => mt_rand(3, 36) * 100000,
            'tenth_board' => demo_weighted($boards), 'tenth_percentage' => mt_rand(5500, 9800) / 100, 'tenth_year' => $tenthYear,
            'previous_qualification' => $pg ? 'Graduation' : (mt_rand(1, 100) <= 94 ? 'Class 12' : 'Diploma'),
            'previous_board' => $pg ? demo_pick($universities) : demo_weighted($boards),
            'previous_institution' => $pg ? demo_pick(['Shri Ram College', 'Galgotias College', 'IMS Ghaziabad', 'Amity Institute', 'KIET Group', 'JSS Academy', 'Hindu College', 'Sharda University']) : demo_pick($schools) . ', ' . $city,
            'passing_year' => $passYear, 'previous_percentage' => mt_rand(5200, 9650) / 100,
        ];
    };

    $receiptSeq = 0;
    $offerSeq = 0;
    $appSeq = 0;
    $history = [];
    $docs = [];
    $followups = [];
    $studentLinks = [];
    $notes = [
        'Interested in industry internships and placement support; asked about the curriculum.',
        'Parents visited campus; impressed with labs and library. Wants hostel for first year.',
        'Comparing with two other colleges; fee and scholarship are the deciding factors.',
        'Strong academic record; discussed merit scholarship eligibility.',
        'Prefers weekend counselling calls. Father is the decision maker.',
        'Asked about bus route from Noida Sector 62 and hostel food.',
        'Needs education loan guidance; shared the bank tie-up details.',
        'Keen on the specialisation; asked about certifications included in the program.',
    ];
    $followNotes = [
        'call' => ['Discussed program structure and fee; will confirm after family discussion.', 'Called to remind about pending documents.', 'Explained the admission timeline and next steps.', 'Did not pick up; will try again.', 'Shared scholarship criteria over the call.'],
        'whatsapp' => ['Sent brochure and fee structure on WhatsApp.', 'Shared document checklist on WhatsApp.', 'Sent campus tour video link.'],
        'email' => ['Emailed offer details and fee payment instructions.', 'Sent interview schedule by email.', 'Emailed the list of documents required for verification.'],
        'meeting' => ['Counselling session at the admission office with parents.', 'Met the applicant and discussed specialisation options.'],
        'campus_visit' => ['Applicant visited campus with parents; toured labs and hostel.', 'Attended the Saturday campus open house.'],
        'sms' => ['SMS reminder sent for the entrance test.', 'SMS sent with application number.'],
        'note' => ['Applicant requested a call back after 6 PM.', 'Waiting for Class 12 marksheet (results awaited).'],
    ];
    $rejectReasons = ['Did not meet the minimum eligibility (50% in qualifying exam).', 'Low score in the personal interview.', 'Documents could not be verified.', 'Seats in the selected program are full.',
        'Applicant did not appear for the entrance test.', 'Incomplete application — mandatory documents not submitted.'];
    $withdrawReasons = ['Applicant joined another institute.', 'Family relocated to another city.', 'Applicant decided to take a drop year.', 'Fee not affordable; scholarship not approved.', 'Applicant chose a different career path.'];
    $docRejects = ['Scan is blurred — please upload a clear copy.', 'Name mismatch with the application.', 'Marksheet is incomplete (page 2 missing).', 'Document has expired; upload a current copy.'];

    // Showcase application first (id 1): approval stage, documents verified, interview done
    $stageList = [];
    $stageList[] = 'approval';
    foreach ($plan as $stage => $n) {
        for ($i = 0; $i < ($stage === 'approval' ? $n - 1 : $n); $i++) {
            $stageList[] = $stage;
        }
    }
    $converted = 0;
    foreach ($stageList as $idx => $stage) {
        $code = $idx === 0 ? 'BBA' : (string) demo_weighted($programWeights);
        $student = null;
        if ($stage === 'confirmed' && $converted < $convertedTarget && !empty($studentsByProgram[$code])) {
            $student = array_shift($studentsByProgram[$code]);
            $converted++;
        }
        $person = $mkPerson($code);
        if ($idx === 0) {
            $person = array_merge($person, ['first_name' => 'Ananya', 'middle_name' => null, 'last_name' => 'Sharma', 'gender' => 'female', 'city' => 'Greater Noida', 'state' => 'Uttar Pradesh',
                'pincode' => '201310', 'email' => 'ananya.sharma.gimt@gmail.com', 'father_name' => 'Rakesh Sharma', 'mother_name' => 'Sunita Sharma', 'previous_percentage' => 91.4,
                'previous_board' => 'CBSE', 'previous_institution' => 'Delhi Public School, Greater Noida', 'tenth_percentage' => 93.2]);
        }
        if ($student) {
            $person = array_merge($person, [
                'first_name' => $student['first_name'], 'middle_name' => $student['middle_name'], 'last_name' => $student['last_name'], 'gender' => $student['gender'], 'dob' => $student['dob'],
                'blood_group' => $student['blood_group'], 'category' => $student['category'], 'email' => $student['email'], 'phone' => $student['mobile'], 'whatsapp' => $student['mobile'],
                'address' => $student['address'], 'city' => $student['city'], 'state' => $student['state'], 'pincode' => $student['pincode'], 'father_name' => $student['father_name'],
                'mother_name' => $student['mother_name'], 'previous_percentage' => $student['previous_percentage'] ?? $person['previous_percentage'],
            ]);
        }
        $program = $programs[$code];
        $source = (string) demo_weighted($sourceWeights);
        $counsellor = $idx === 0 ? $officer : $pickCounsellor();
        // Timeline windows (days ago) per stage
        if ($student) {
            $convertedAt = date('Y-m-d H:i:s', strtotime($student['admission_date'] ?: '2026-07-15') + mt_rand(10, 17) * 3600);
            $created = $addDays($convertedAt, -mt_rand(18, 70) - mt_rand(0, 99) / 100);
            $stageAt = $convertedAt;
        } else {
            [$cMin, $cMax, $sMinFrac] = match ($stage) {
                'confirmed' => [70, 200, 0.6], 'rejected' => [8, 200, 0.2], 'withdrawn' => [10, 190, 0.3], 'waitlisted' => [25, 90, 0.5],
                'fee_payment' => [16, 75, 0.6], 'approval' => [10, 62, 0.6], 'entrance_interview' => [6, 55, 0.5], 'document_verification' => [3, 48, 0.4],
                'application' => [0, 40, 0.0], 'enquiry' => [0, 30, 0.0], default => [1, 30, 0.2],
            };
            $created = $idx === 0 ? $ago(21, 21) : $ago($cMin, $cMax);
            $span = max(3600, $nowTs - 600 - strtotime($created));
            $stageAt = in_array($stage, ['application', 'enquiry'], true) ? $created
                : date('Y-m-d H:i:s', strtotime($created) + (int) ($span * (mt_rand((int) ($sMinFrac * 100), 95) / 100)));
            if ($idx === 0) {
                $stageAt = $ago(2, 2);
            }
        }
        $created = $inHours($created);
        $stageAt = max($created, $inHours($stageAt));
        $appSeq++;
        $appNo = 'APP/' . $sessionName . '/' . str_pad((string) $appSeq, 5, '0', STR_PAD_LEFT);
        $courseList = $courses[(int) $program['id']] ?? [];
        $second = mt_rand(1, 100) <= 35 ? (string) demo_weighted(array_diff_key($programWeights, [$code => 1])) : null;
        $reachedIdx = match ($stage) {
            'rejected' => mt_rand(1, 4), 'withdrawn' => mt_rand(1, 5), 'waitlisted' => 4, default => array_search($stage, $stageOrder, true),
        };
        $scored = $reachedIdx >= 4 || ($stage === 'entrance_interview' && mt_rand(1, 100) <= 45);
        [$exam, $examScore] = $examFor($code);
        $interview = $scored && (in_array($program['level'], ['PG'], true) || mt_rand(1, 100) <= 70) ? mt_rand(55, 95) : null;
        if ($idx === 0) {
            [$exam, $examScore, $interview] = ['CUET (UG)', 88.6, 84];
        }
        $approved = in_array($stage, ['fee_payment', 'confirmed'], true);
        $fee = $feeFor($code);
        $feePaid = 0.0;
        $feeData = ['fee_mode' => null, 'fee_reference' => null, 'fee_paid_on' => null, 'fee_receipt_no' => null, 'fee_collected_by' => null];
        $approvedAt = null;
        $offerNo = null;
        if ($approved) {
            $approvedAt = $stage === 'confirmed' ? $addDays($stageAt, -mt_rand(2, 9)) : $stageAt;
            $offerSeq++;
            $offerNo = 'GIMT/OL/' . $sessionName . '/' . str_pad((string) $offerSeq, 4, '0', STR_PAD_LEFT);
            $payState = $stage === 'confirmed' ? 'full' : demo_weighted(['full' => 6, 'partial' => 3, 'none' => 9]);
            if ($payState !== 'none') {
                $feePaid = $payState === 'full' ? $fee : round($fee * demo_pick([0.4, 0.5, 0.6]), -2);
                $mode = (string) demo_weighted($modes);
                $receiptSeq++;
                $paidOn = $stage === 'confirmed' ? date('Y-m-d', strtotime($stageAt)) : date('Y-m-d', min($nowTs, strtotime($stageAt) + mt_rand(1, 6) * 86400));
                $feeData = ['fee_mode' => $mode, 'fee_reference' => $refFor($mode), 'fee_paid_on' => $paidOn,
                    'fee_receipt_no' => 'ADMR/' . $sessionName . '/' . str_pad((string) $receiptSeq, 5, '0', STR_PAD_LEFT), 'fee_collected_by' => $officer];
            }
        }
        $apps[] = array_merge($person, $feeData, [
            'application_no' => $appNo, 'enquiry_id' => null, 'academic_session_id' => $sessionId, 'program_id' => (int) $program['id'],
            'course_id' => $courseList && mt_rand(1, 100) <= 70 ? demo_pick($courseList) : null,
            'second_program_id' => $second ? (int) $programs[$second]['id'] : null, 'photo' => null,
            'quota' => $idx === 0 ? 'general' : (string) demo_weighted($quotas), 'source' => $idx === 0 ? 'website' : $source,
            'entrance_exam' => $scored || mt_rand(1, 100) <= 30 ? $exam : null, 'entrance_score' => $scored ? $examScore : null,
            'interview_date' => $interview ? $addDays($created, mt_rand(5, 15)) : null, 'interview_score' => $interview,
            'interview_remarks' => $interview ? demo_pick(['Confident and articulate; good clarity of goals.', 'Good communication skills; needs to improve technical depth.', 'Average performance; motivated.',
                'Excellent aptitude and attitude.', 'Clear about career plans; recommended.']) : null,
            'hostel_required' => mt_rand(1, 100) <= 30 ? 1 : 0, 'transport_required' => mt_rand(1, 100) <= 35 ? 1 : 0,
            'declaration_accepted' => $stage === 'enquiry' ? 0 : 1, 'declaration_at' => $stage === 'enquiry' ? null : $created,
            'stage' => $stage, 'stage_changed_at' => $stageAt,
            'counselling_notes' => mt_rand(1, 100) <= 65 || $idx === 0 ? ($idx === 0 ? 'Wants BBA with Digital Business specialisation; strong Class 12 record (91.4%). Parents visited campus and prefer day-scholar with transport.' : demo_pick($notes)) : null,
            'rejection_reason' => $stage === 'rejected' ? demo_pick($rejectReasons) : null,
            'assigned_to' => $counsellor, 'admission_fee' => $approved ? $fee : null, 'fee_paid' => $feePaid, 'payment_id' => null,
            'offer_letter_no' => $offerNo, 'offer_issued_at' => $approvedAt, 'approved_by' => $approved ? demo_pick([$admin, $office]) : null, 'approved_at' => $approvedAt,
            'student_id' => $student ? (int) $student['id'] : null, 'converted_at' => $student ? $stageAt : null, 'ip_address' => $source === 'website' ? '49.36.' . mt_rand(1, 250) . '.' . mt_rand(1, 250) : null,
            'created_by' => $source === 'website' ? null : ($counsellor ?: $officer), 'created_at' => $created, 'updated_at' => $stageAt,
            '__reached' => $reachedIdx, '__code' => $code, '__fee' => $fee,
        ]);
    }

    $insert = [];
    foreach ($apps as $a) {
        $insert[] = array_filter($a, fn ($k) => !str_starts_with($k, '__'), ARRAY_FILTER_USE_KEY);
    }
    demo_bulk_insert('admissions', $insert, 100);
    $idByNo = db_pairs('SELECT application_no, id FROM admissions');

    // ---------------------------------------------------------------- History, documents, follow-ups
    $outcomesFor = fn (string $stage) => match ($stage) {
        'enquiry' => ['interested' => 4, 'callback' => 3, 'not_reachable' => 2],
        'rejected', 'withdrawn' => ['not_interested' => 3, 'callback' => 1, 'interested' => 1],
        default => ['interested' => 6, 'callback' => 3, 'not_reachable' => 1, 'visited' => 2, 'applied' => 1],
    };
    $remarksFor = [
        'document_verification' => ['Documents submitted at the admission desk.', 'Documents uploaded online; verification started.', 'Received photocopies and originals for verification.'],
        'entrance_interview' => ['All documents verified.', 'Documents verified; entrance test scheduled.', 'Verification complete — sent for interview.'],
        'approval' => ['Interview completed; recommended for admission.', 'Entrance score above cut-off; sent to the admission committee.', 'Assessment complete.'],
        'fee_payment' => ['Approved by the admission committee. Offer letter issued.', 'Approved — merit list round 2.', 'Approved; offer letter emailed.'],
        'confirmed' => ['Admission fee received; admission confirmed.', 'Fee paid at the accounts counter; seat confirmed.'],
        'waitlisted' => ['Waitlisted — seats in the program are currently full.', 'Placed on the waitlist (merit rank pending).'],
        'withdrawn' => [],
        'application' => ['Application received'],
    ];
    $docTypes = admission_doc_types();
    foreach ($apps as $i => $a) {
        $id = (int) $idByNo[$a['application_no']];
        $stage = $a['stage'];
        $reached = (int) $a['__reached'];
        $code = $a['__code'];
        $name = admission_full_name($a);
        $by = $a['assigned_to'] ?: $officer;
        // Stage history: application -> ... -> reached stage (-> closed stage)
        $path = [];
        if ($stage === 'enquiry') {
            $path = ['enquiry'];
        } else {
            for ($s = 1; $s <= min($reached, 6); $s++) {
                $path[] = $stageOrder[$s];
            }
            if (in_array($stage, ['rejected', 'withdrawn', 'waitlisted'], true)) {
                $path[] = $stage;
            }
        }
        $t0 = strtotime($a['created_at']);
        $t1 = max($t0 + 3600, strtotime($a['stage_changed_at']));
        $n = count($path);
        $prev = null;
        foreach ($path as $k => $to) {
            $at = $k === 0 ? $a['created_at'] : ($k === $n - 1 ? date('Y-m-d H:i:s', $t1) : $inHours(date('Y-m-d H:i:s', (int) ($t0 + ($t1 - $t0) * $k / max(1, $n - 1)) + mt_rand(0, 3600))));
            $remark = match (true) {
                $k === 0 => $to === 'enquiry' ? 'Started an online application (incomplete).' : ($a['source'] === 'website' ? 'Application submitted online.' : 'Application received at the admission office.'),
                $to === 'rejected' => $a['rejection_reason'],
                $to === 'withdrawn' => demo_pick($withdrawReasons),
                $to === 'confirmed' && $a['fee_receipt_no'] => 'Admission fee received (' . $a['fee_receipt_no'] . '); admission confirmed.',
                default => demo_pick($remarksFor[$to] ?? ['Moved to next stage.']),
            };
            $history[] = ['admission_id' => $id, 'from_stage' => $prev, 'to_stage' => $to, 'remarks' => $remark,
                'changed_by' => $k === 0 && $a['source'] === 'website' ? null : (in_array($to, ['fee_payment', 'rejected', 'waitlisted'], true) ? ($a['approved_by'] ?: $admin) : $by), 'created_at' => $at];
            $prev = $to;
        }
        if ($a['student_id']) {
            $history[] = ['admission_id' => $id, 'from_stage' => 'confirmed', 'to_stage' => 'confirmed', 'remarks' => 'Converted to student record.', 'changed_by' => $officer, 'created_at' => $a['converted_at']];
            $studentLinks[(int) $a['student_id']] = $id;
        }
        // Documents
        $required = ['photo', '10th_marksheet', '12th_marksheet', 'id_proof'];
        if (in_array($programs[$code]['level'], ['PG'], true)) {
            $required[] = 'graduation';
        }
        $extra = [];
        if ($a['category'] !== 'General' && mt_rand(1, 100) <= 70) {
            $extra[] = 'caste_certificate';
        }
        if (mt_rand(1, 100) <= 30) {
            $extra[] = 'transfer_certificate';
        }
        $docSet = match (true) {
            $stage === 'enquiry' => [],
            $stage === 'application' => array_slice($required, 0, mt_rand(0, 3)),
            $stage === 'document_verification' => array_merge($required, $extra),
            $stage === 'rejected', $stage === 'withdrawn' => $reached >= 2 ? array_merge($required, $extra) : array_slice($required, 0, mt_rand(0, 2)),
            default => array_merge($required, $extra),
        };
        if ($stage === 'document_verification' && mt_rand(1, 100) <= 30) {
            array_pop($docSet); // one still missing
        }
        $docBase = $a['created_at'];
        foreach ($docSet as $j => $dt) {
            $status = 'verified';
            $remarks = null;
            if ($stage === 'application' || ($reached < 2 && in_array($stage, ['rejected', 'withdrawn'], true))) {
                $status = 'pending';
            } elseif ($stage === 'document_verification') {
                $status = demo_weighted(['verified' => 55, 'pending' => 35, 'rejected' => 10]);
                if ($status === 'rejected') {
                    $remarks = demo_pick($docRejects);
                }
            }
            $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $a['application_no']) . '-' . $dt;
            $isPhoto = $dt === 'photo';
            $ext = $isPhoto ? 'png' : 'pdf';
            $rel = 'storage/private/admission-docs/seed/' . strtolower($slug) . '.' . $ext;
            $content = $isPhoto ? adm_seed_photo($name, $i) : adm_seed_pdf([$docTypes[$dt], 'Applicant: ' . $name, 'Application No: ' . $a['application_no'],
                'Program: ' . $programs[$code]['short_name'] . ' (' . $sessionName . ')', $dt === '10th_marksheet' ? 'Board: ' . $a['tenth_board'] . '   Year: ' . $a['tenth_year'] . '   Marks: ' . $a['tenth_percentage'] . '%'
                : ($dt === '12th_marksheet' || $dt === 'graduation' ? 'Board/University: ' . $a['previous_board'] . '   Year: ' . $a['passing_year'] . '   Marks: ' . $a['previous_percentage'] . '%' : 'Document reference: ' . strtoupper(substr(md5($slug), 0, 10)))]);
            if ($content === null) {
                $rel = 'storage/private/admission-docs/seed/' . strtolower($slug) . '.pdf';
                $content = adm_seed_pdf([$docTypes[$dt], 'Applicant: ' . $name]);
                $ext = 'pdf';
            }
            file_put_contents(APP_ROOT . '/' . $rel, $content);
            $uploadedAt = date('Y-m-d H:i:s', strtotime($docBase) + $j * 120 + mt_rand(0, 600));
            $verifiedAt = $status === 'pending' ? null : date('Y-m-d H:i:s', min($nowTs - 60, strtotime($uploadedAt) + mt_rand(1, 4) * 86400));
            $docs[] = ['admission_id' => $id, 'doc_type' => $dt, 'file_path' => $rel, 'original_name' => str_replace(' ', '_', $docTypes[$dt]) . '_' . $a['first_name'] . '.' . $ext,
                'mime' => $isPhoto ? 'image/png' : 'application/pdf', 'size_bytes' => strlen($content), 'status' => $status, 'remarks' => $remarks,
                'verified_by' => $status === 'pending' ? null : $by, 'verified_at' => $verifiedAt, 'created_at' => $uploadedAt];
        }
        // Follow-ups
        $active = !in_array($stage, ['confirmed', 'rejected', 'withdrawn'], true);
        $count = $stage === 'enquiry' ? mt_rand(1, 2) : mt_rand(1, $active ? 4 : 3);
        $tA = strtotime($a['created_at']);
        $tB = $active ? $nowTs - 3600 : strtotime($a['stage_changed_at']);
        for ($f = 0; $f < $count; $f++) {
            $type = (string) demo_weighted(['call' => 45, 'whatsapp' => 18, 'email' => 12, 'meeting' => 10, 'campus_visit' => 7, 'sms' => 4, 'note' => 4]);
            $at = $inHours(date('Y-m-d H:i:s', (int) ($tA + ($tB - $tA) * ($f + 1) / ($count + 1))));
            $isLast = $f === $count - 1;
            $next = null;
            $completedAt = null;
            if ($isLast && $active && mt_rand(1, 100) <= 78) {
                $next = date('Y-m-d', $T + mt_rand(-4, 11) * 86400);
            } elseif (!$isLast || !$active) {
                $next = mt_rand(1, 100) <= 60 ? date('Y-m-d', strtotime($at) + mt_rand(1, 5) * 86400) : null;
                $completedAt = $next ? date('Y-m-d H:i:s', min($nowTs - 60, strtotime($next) + mt_rand(9, 17) * 3600)) : null;
            }
            if ($i === 0 && $isLast) {
                $next = date('Y-m-d', $T);
                $completedAt = null;
            }
            $followups[] = ['admission_id' => $id, 'enquiry_id' => null, 'type' => $type, 'notes' => demo_pick($followNotes[$type]), 'outcome' => (string) demo_weighted($outcomesFor($stage)),
                'next_followup_date' => $next, 'completed_at' => $completedAt, 'completed_by' => $completedAt ? $by : null, 'created_by' => $by, 'created_at' => $at];
        }
    }
    demo_bulk_insert('admission_stage_history', $history, 300);
    demo_bulk_insert('admission_documents', $docs, 200);
    demo_bulk_insert('admission_followups', $followups, 300);
    foreach ($studentLinks as $sid => $aid) {
        db_exec('UPDATE students SET admission_id = ? WHERE id = ? AND admission_id IS NULL', [$aid, $sid]);
    }

    // ---------------------------------------------------------------- Enquiries (~150)
    $enqStatus = ['new' => 30, 'contacted' => 34, 'interested' => 30, 'not_interested' => 14, 'converted' => 26, 'closed' => 16];
    $interests = ['Hotel Management', 'Law (BA LLB)', 'B.Pharma', 'Fashion Design', 'Animation & VFX', 'Aviation', 'Ph.D. admission', 'Distance learning MBA'];
    $enqMessages = [
        'Please share the fee structure and scholarship details.', 'What is the eligibility for admission and last date to apply?', 'Is hostel available for girls? Need details.',
        'I want to know about placements for this program.', 'Do you offer education loan assistance?', 'Can I get admission through management quota?',
        'Interested in the program; please call me in the evening.', 'Is there any entrance exam? What is the syllabus?', 'Need information about lateral entry.',
        'Please share the campus visit timings for Saturday.',
    ];
    // Applications that came from an enquiry (converted enquiries point at them)
    $convertedApps = db_all("SELECT id, first_name, last_name, middle_name, phone, email, city, program_id, source, assigned_to, created_at FROM admissions
                             WHERE source IN ('website','phone','walk_in','social_media','whatsapp','campaign','education_fair','referral') AND id > 1 ORDER BY id");
    $pickedApps = [];
    for ($k = 0; $k < $enqStatus['converted'] && $convertedApps; $k++) {
        $pickedApps[] = $convertedApps[(int) floor($k * count($convertedApps) / $enqStatus['converted'])];
    }
    $enqRows = [];
    $enqMeta = [];
    $statusList = [];
    foreach ($enqStatus as $s => $n) {
        for ($k = 0; $k < $n; $k++) {
            $statusList[] = $s;
        }
    }
    // interleave statuses deterministically
    for ($i = count($statusList) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$statusList[$i], $statusList[$j]] = [$statusList[$j], $statusList[$i]];
    }
    $convIdx = 0;
    foreach ($statusList as $s) {
        $newToday = $s === 'new' && ($newCount = ($newCount ?? 0) + 1) <= 4;
        $created = match ($s) { 'new' => $newToday ? $ago(0, 0) : $ago(0, 9), 'contacted' => $ago(2, 55), 'interested' => $ago(3, 70), 'not_interested', 'closed' => $ago(15, 120), default => $ago(20, 150) };
        $app = null;
        if ($s === 'converted') {
            $app = $pickedApps[$convIdx++] ?? null;
            if (!$app) {
                $s = 'interested';
            }
        }
        if ($app) {
            $created = $addDays($app['created_at'], -mt_rand(3, 25));
            $row = ['name' => trim($app['first_name'] . ' ' . ($app['middle_name'] ? $app['middle_name'] . ' ' : '') . $app['last_name']), 'email' => $app['email'], 'phone' => $app['phone'],
                'city' => $app['city'], 'program_id' => (int) $app['program_id'], 'program_interest' => null, 'source' => $app['source'], 'assigned_to' => $app['assigned_to'] ? (int) $app['assigned_to'] : null];
        } else {
            $gender = mt_rand(1, 100) <= 45 ? 'female' : 'male';
            $first = demo_pick(demo_first_names($gender));
            $last = demo_pick(demo_last_names());
            do {
                $phone = demo_phone();
            } while (isset($usedPhones[$phone]));
            $usedPhones[$phone] = true;
            $hasProgram = mt_rand(1, 100) <= 85;
            $row = ['name' => $first . ' ' . $last, 'email' => mt_rand(1, 100) <= 75 ? demo_email($first, $last, demo_pick(['gmail.com', 'yahoo.com', 'outlook.com']), mt_rand(1, 99)) : null,
                'phone' => $phone, 'city' => demo_city()[0], 'program_id' => $hasProgram ? (int) $programs[(string) demo_weighted($programWeights)]['id'] : null,
                'program_interest' => $hasProgram ? null : demo_pick($interests), 'source' => (string) demo_weighted($sourceWeights), 'assigned_to' => $s === 'new' && mt_rand(1, 100) <= 55 ? null : $pickCounsellor()];
        }
        $open = in_array($s, ['new', 'contacted', 'interested'], true);
        $follow = $open && $s !== 'new' ? date('Y-m-d', $T + mt_rand(-3, 9) * 86400) : ($s === 'new' && mt_rand(1, 100) <= 50 ? date('Y-m-d', $T + mt_rand(0, 3) * 86400) : null);
        $enqRows[] = $row + [
            'message' => mt_rand(1, 100) <= 80 ? demo_pick($enqMessages) : null, 'status' => $s, 'priority' => (string) demo_weighted(['high' => 25, 'medium' => 55, 'low' => 20]),
            'follow_up_date' => $follow, 'notes' => mt_rand(1, 100) <= 35 ? demo_pick(['Prefers call after 5 PM.', 'Parent called on behalf of the student.', 'Visited the education fair stall.', 'Referred by an alumnus.', 'Asked for scholarship details.']) : null,
            'admission_id' => $app ? (int) $app['id'] : null, 'utm_source' => $row['source'] === 'campaign' ? demo_pick(['google', 'facebook', 'instagram']) : null,
            'utm_campaign' => $row['source'] === 'campaign' ? demo_pick(['admissions-2026', 'mba-2026', 'btech-2026']) : null,
            'ip_address' => $row['source'] === 'website' ? '106.' . mt_rand(192, 223) . '.' . mt_rand(1, 250) . '.' . mt_rand(1, 250) : null,
            'created_at' => $created, 'updated_at' => $created,
        ];
        $enqMeta[] = ['status' => $s, 'created' => $created, 'follow' => $follow, 'assigned' => $row['assigned_to'], 'app' => $app];
    }
    // oldest first so ids follow time
    array_multisort(array_column($enqRows, 'created_at'), SORT_ASC, $enqRows, $enqMeta);
    demo_bulk_insert('enquiries', $enqRows, 100);
    $enqIds = db_column('SELECT id FROM enquiries ORDER BY id');
    $enqFollow = [];
    foreach ($enqMeta as $k => $m) {
        $eid = (int) $enqIds[$k];
        if ($m['app']) {
            db_exec('UPDATE admissions SET enquiry_id = ? WHERE id = ?', [$eid, (int) $m['app']['id']]);
        }
        if ($m['status'] === 'new') {
            continue;
        }
        $count = mt_rand(1, 3);
        $tA = strtotime($m['created']);
        $tB = $m['app'] ? strtotime($m['app']['created_at']) : $nowTs - 7200;
        $by = $m['assigned'] ?: $officer;
        for ($f = 0; $f < $count; $f++) {
            $type = (string) demo_weighted(['call' => 55, 'whatsapp' => 20, 'email' => 10, 'campus_visit' => 8, 'sms' => 7]);
            $at = $inHours(date('Y-m-d H:i:s', (int) ($tA + ($tB - $tA) * ($f + 1) / ($count + 1))));
            $isLast = $f === $count - 1;
            $outcome = match ($m['status']) {
                'not_interested' => $isLast ? 'not_interested' : 'callback', 'interested' => $isLast ? 'interested' : demo_pick(['callback', 'not_reachable', 'interested']),
                'converted' => $isLast ? 'applied' : 'interested', 'closed' => $isLast ? 'not_reachable' : 'callback', default => demo_pick(['callback', 'not_reachable', 'interested']),
            };
            $next = $isLast && in_array($m['status'], ['contacted', 'interested'], true) ? $m['follow'] : (!$isLast ? date('Y-m-d', strtotime($at) + mt_rand(1, 4) * 86400) : null);
            $completed = !$isLast && $next ? date('Y-m-d H:i:s', min($nowTs - 60, strtotime($next) + 36000)) : null;
            $enqFollow[] = ['admission_id' => null, 'enquiry_id' => $eid, 'type' => $type, 'notes' => demo_pick($followNotes[$type]), 'outcome' => $outcome, 'next_followup_date' => $next,
                'completed_at' => $completed, 'completed_by' => $completed ? $by : null, 'created_by' => $by, 'created_at' => $at];
        }
    }
    demo_bulk_insert('admission_followups', $enqFollow, 300);

    // ---------------------------------------------------------------- Contact messages (~40)
    $cm = [
        ['admission', 'Admission enquiry for BBA 2026-27', 'Hello, I have completed Class 12 (Commerce) with 82%. Please let me know if admissions for BBA are still open and the documents required.'],
        ['admission', 'MBA fee structure and scholarship', 'Could you share the complete fee structure for the MBA program along with the scholarship criteria for students with CAT percentile above 85?'],
        ['admission', 'Lateral entry in B.Tech CSE', 'I hold a 3-year diploma in Computer Engineering (78%). Is lateral entry available in the second year of B.Tech CSE?'],
        ['academic', 'Syllabus of B.Sc Data Science', 'Please share the semester-wise syllabus of the B.Sc (Data Science) program and the programming languages taught.'],
        ['placement', 'Placement record of MCA batch', 'What was the highest and average package for the MCA batch of 2025? Which companies visited the campus?'],
        ['general', 'Campus visit on Saturday', 'We would like to visit the campus with my son this Saturday around 11 AM. Is prior appointment needed?'],
        ['general', 'Hostel facility for girls', 'Is there a separate hostel for girls with mess facility? Please share charges and availability for first-year students.'],
        ['general', 'Bus route from Ghaziabad', 'Does the college bus cover Raj Nagar Extension, Ghaziabad? What are the timings and annual transport fee?'],
        ['academic', 'Transfer certificate request', 'I passed out in 2024 (BCA). I need a duplicate transfer certificate. Kindly guide me on the process and fee.'],
        ['other', 'Collaboration proposal — coding bootcamp', 'We run industry coding bootcamps and would like to propose a collaboration with your Computer Applications department.'],
        ['other', 'Guest lecture invitation', 'I am a product manager at a fintech company and would be happy to deliver a guest lecture for MBA students.'],
        ['general', 'Alumni degree verification', 'Our company needs to verify the degree of a candidate who claims to have completed B.Com (Hons) from GIMT in 2023.'],
        ['admission', 'Admission in DCA program', 'Is the Diploma in Computer Applications available as a weekend batch? I am working and can attend only on weekends.'],
        ['admission', 'Management quota seats in B.Tech', 'Are management quota seats available in B.Tech AI & DS? Please share the process and fee.'],
        ['placement', 'Internship opportunities for BBA students', 'Do BBA students get summer internship support from the placement cell? Which companies participate?'],
        ['academic', 'Re-evaluation of semester result', 'I want to apply for re-evaluation of my semester 3 result (BCA). What is the last date and fee?'],
        ['general', 'Refund of caution deposit', 'I completed my MBA in June 2026. When will the caution deposit be refunded to my bank account?'],
        ['admission', 'Certificate course in Digital Marketing', 'When does the next batch of the Digital Marketing certificate start? Is it online or classroom?'],
        ['general', 'Library membership for alumni', 'Can alumni get library membership? I would like to access reference books for competitive exams.'],
        ['admission', 'NRI quota admission', 'My daughter is studying in Dubai (CBSE). Please share the NRI quota admission process for BBA.'],
        ['other', 'Sports quota trials', 'I am a state-level basketball player. Are there sports quota trials for admission this year?'],
        ['placement', 'Campus recruitment drive', 'We are an IT services company and want to conduct a campus recruitment drive for 2027 graduates. Whom should we contact?'],
        ['general', 'Anti-ragging committee contact', 'Please share the contact details of the anti-ragging committee and helpline numbers.'],
        ['academic', 'Credit transfer from another university', 'I studied one year of B.Com at another university. Can my credits be transferred if I take admission at GIMT?'],
        ['admission', 'Last date to apply for MCA', 'What is the last date for MCA applications and when is the GIMT aptitude test scheduled?'],
        ['general', 'Fee payment receipt not received', 'I paid the semester fee online on 28 September but have not received the receipt by email. Transaction ID: pay_N7x2k9.'],
        ['other', 'Request for annual report', 'Please share the latest annual report / NAAC SSR of the institute for our research study.'],
        ['admission', 'Documents for admission', 'What documents are required at the time of admission? My Class 12 marksheet is still awaited from the board.'],
        ['general', 'Scholarship for economically weaker students', 'Is there any fee concession for students from economically weaker families? My family income is below 3 lakh.'],
        ['academic', 'Value added courses', 'Are value-added certification courses (AWS, Tally, Excel) included in the BCA curriculum?'],
        ['admission', 'Admission open for M.Com?', 'Is M.Com admission still open for 2026-27? I have scored 68% in B.Com.'],
        ['placement', 'Placement training schedule', 'When will the aptitude and soft skills training for final-year students begin this semester?'],
        ['general', 'Event participation — TechFest', 'Can students from other colleges participate in your annual TechFest? Please share registration details.'],
        ['general', 'Parking facility for day scholars', 'Is there a two-wheeler parking facility for day scholars and is a pass required?'],
        ['admission', 'Hostel + admission package', 'Please share the total first-year cost including tuition, hostel and mess for B.Tech CSE.'],
        ['other', 'Vendor registration for canteen', 'We would like to register as a vendor for the college canteen. Please share the tender process.'],
        ['academic', 'Attendance shortage', 'My attendance in one subject is 68%. Is there any provision for condonation due to medical reasons?'],
        ['admission', 'Admission counselling call', 'Please arrange a call with an admission counsellor for B.Tech AI & DS. Best time: after 6 PM.'],
        ['general', 'Wi-Fi access for students', 'How can first-year students get campus Wi-Fi credentials? The help desk was closed today.'],
        ['admission', 'Spot admission round', 'Is there any spot admission round for BBA or BCA in October? I missed the earlier rounds.'],
    ];
    $cmRows = [];
    $n = count($cm);
    foreach ($cm as $k => [$type, $subject, $message]) {
        $gender = mt_rand(1, 100) <= 45 ? 'female' : 'male';
        $first = demo_pick(demo_first_names($gender));
        $last = demo_pick(demo_last_names());
        $created = $ago(max(0, (int) floor(($n - $k - 1) * 1.4)), max(0.4, ($n - $k - 1) * 1.4 + 1.2));
        $status = $k >= $n - 12 ? 'new' : (string) demo_weighted(['read' => 30, 'replied' => 40, 'closed' => 20, 'new' => 10]);
        $replied = in_array($status, ['replied', 'closed'], true) && mt_rand(1, 100) <= 85;
        $repliedAt = $replied ? date('Y-m-d H:i:s', min($nowTs - 120, strtotime($created) + mt_rand(2, 30) * 3600)) : null;
        $cmRows[] = [
            'name' => $first . ' ' . $last, 'email' => demo_email($first, $last, demo_pick(['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com']), mt_rand(1, 99)),
            'phone' => mt_rand(1, 100) <= 85 ? demo_phone() : null, 'enquiry_type' => $type, 'subject' => $subject, 'message' => $message, 'status' => $status,
            'is_starred' => mt_rand(1, 100) <= 20 ? 1 : 0, 'is_archived' => $status !== 'new' && mt_rand(1, 100) <= 12 ? 1 : 0,
            'read_at' => $status === 'new' ? null : date('Y-m-d H:i:s', min($nowTs - 60, strtotime($created) + mt_rand(1, 20) * 3600)),
            'resolved_at' => $status === 'closed' ? date('Y-m-d H:i:s', min($nowTs - 60, strtotime($created) + mt_rand(24, 96) * 3600)) : null,
            'enquiry_id' => null,
            'reply' => $replied ? demo_pick([
                "Thank you for writing to us. Admissions for 2026-27 are open for select programs. Our admission counsellor will call you within 24 hours with complete details.\n\nYou can also visit the campus Monday to Saturday between 9 AM and 5 PM.",
                "Thank you for your query. Please find the requested details on our website under the Admissions section. For anything else, call our helpline +91 9955446477.",
                "Greetings from GIMT! We have shared your request with the concerned department; you will hear from them shortly.",
                "Thank you for reaching out. The process and fee details have been shared with you; please carry the original documents when you visit the campus.",
            ]) : null,
            'replied_by' => $replied ? demo_pick([$officer, $office]) : null, 'replied_at' => $repliedAt,
            'ip_address' => '157.' . mt_rand(32, 51) . '.' . mt_rand(1, 250) . '.' . mt_rand(1, 250), 'user_agent' => demo_pick(['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0', 'Mozilla/5.0 (Linux; Android 14) Chrome/127.0 Mobile', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5) Safari/604.1']),
            'created_at' => $created, 'updated_at' => $repliedAt ?: $created,
        ];
    }
    array_multisort(array_column($cmRows, 'created_at'), SORT_ASC, $cmRows);
    demo_bulk_insert('contact_messages', $cmRows, 100);

    // ---------------------------------------------------------------- Feedback & complaints (~30)
    $fb = [
        ['complaint', 'hostel', 'high', 'Water cooler not working in Boys Hostel Block B', 'The water cooler on the second floor of Block B has not been working for four days. Students are buying bottled water.'],
        ['complaint', 'transport', 'medium', 'Bus Route 4 regularly late in the morning', 'Route 4 bus reaches campus 20-25 minutes late almost every day, causing us to miss the first lecture.'],
        ['feedback', 'academic', 'low', 'Excellent guest lecture on cloud computing', 'The guest lecture by the AWS solutions architect was very informative. Please organise more such sessions.'],
        ['suggestion', 'library', 'low', 'Extend library timings during exams', 'Kindly keep the library open till 10 PM during the end-semester examination period.'],
        ['grievance', 'fees', 'high', 'Late fee charged despite timely payment', 'I paid my semester fee on 10 September via UPI but a late fee of ₹500 has been added to my account.'],
        ['complaint', 'infrastructure', 'medium', 'Projector not working in Room 204', 'The projector in Room 204 (Academic Block) flickers and switches off during lectures.'],
        ['complaint', 'canteen', 'medium', 'Food quality in the canteen', 'The quality of food in the main canteen has declined. Several students reported stale snacks last week.'],
        ['feedback', 'faculty', 'low', 'Appreciation for the Data Structures faculty', 'The DSA classes are very well structured with practical examples. Thank you!'],
        ['suggestion', 'infrastructure', 'low', 'More charging points in the library', 'Please install more charging points near the reading tables in the library.'],
        ['complaint', 'administration', 'medium', 'Delay in issuing bonafide certificate', 'I applied for a bonafide certificate 10 days ago for my bank loan and it has not been issued yet.'],
        ['grievance', 'ragging', 'urgent', 'Senior students misbehaving near the hostel gate', 'A group of senior students has been forcing first-year students to perform tasks near the hostel gate in the evening.'],
        ['complaint', 'hostel', 'high', 'Wi-Fi outage in Girls Hostel', 'Wi-Fi in the girls hostel has been down since Sunday. We are unable to submit online assignments.'],
        ['feedback', 'academic', 'low', 'Industrial visit was very useful', 'The industrial visit to the manufacturing plant gave us practical exposure to operations management.'],
        ['complaint', 'transport', 'high', 'Overcrowding in Route 2 bus', 'Route 2 bus carries more students than seats; many students travel standing for 45 minutes.'],
        ['suggestion', 'academic', 'low', 'Add a Python elective for B.Com students', 'A short Python/Excel analytics elective would be very helpful for commerce students.'],
        ['complaint', 'infrastructure', 'medium', 'Washroom maintenance in Block C', 'Washrooms on the ground floor of Block C are not cleaned regularly.'],
        ['grievance', 'administration', 'high', 'Marks not updated on the portal', 'My internal assessment marks for Business Statistics are not updated on the student portal.'],
        ['feedback', 'administration', 'low', 'Smooth admission process', 'The admission process was smooth and the counsellors were very helpful. — Parent of BBA student'],
        ['complaint', 'library', 'medium', 'Reference books not available', 'Only two copies of the recommended Operating Systems reference book are available for 120 students.'],
        ['complaint', 'fees', 'medium', 'Receipt not generated for online payment', 'I paid the exam fee online but the receipt was not generated and the amount is shown as due.'],
        ['suggestion', 'canteen', 'low', 'Healthy food options in canteen', 'Please introduce salads, fruits and healthy snack options in the canteen menu.'],
        ['feedback', 'hostel', 'low', 'Improved hostel mess food', 'The new mess menu introduced this month is much better. Thank you to the hostel committee.'],
        ['complaint', 'infrastructure', 'urgent', 'Electric wire exposed near the parking area', 'An electric wire is exposed near the two-wheeler parking. It is dangerous during rain.'],
        ['grievance', 'faculty', 'medium', 'Practical classes not conducted', 'Our Computer Networks practical classes have not been conducted for the last three weeks.'],
        ['suggestion', 'administration', 'low', 'Online leave application for students', 'An online leave application with approval tracking would save time for students and faculty.'],
        ['complaint', 'transport', 'low', 'Bus AC not working', 'The AC in bus Route 6 is not working; it gets very hot in the afternoon.'],
        ['feedback', 'academic', 'low', 'Placement training sessions helpful', 'The aptitude training sessions conducted by the placement cell are very useful. Please continue weekly.'],
        ['complaint', 'hostel', 'medium', 'Room allotment change request pending', 'My request to change rooms due to a medical condition has been pending for two weeks.'],
        ['grievance', 'fees', 'medium', 'Scholarship amount not adjusted', 'My merit scholarship of 25% has not been adjusted in the semester 3 fee invoice.'],
        ['suggestion', 'library', 'low', 'E-book access from home', 'Please provide remote access to the e-library so that we can read e-books from home.'],
    ];
    $assigneeFor = fn (string $cat) => match ($cat) {
        'hostel' => (int) ($userBySlug['hostel-warden'] ?? $admin), 'transport' => (int) ($userBySlug['transport-manager'] ?? $admin), 'fees' => (int) ($userBySlug['accountant'] ?? $admin),
        'library' => (int) ($userBySlug['librarian'] ?? $admin), 'academic', 'faculty' => (int) ($userBySlug['academic-admin'] ?? $admin), default => $office,
    };
    $resolutions = [
        'hostel' => 'Maintenance team repaired the issue; warden verified with the students.', 'transport' => 'Route timing revised and driver counselled; transport manager monitoring for two weeks.',
        'fees' => 'Accounts verified the payment and reversed the incorrect charge; updated invoice shared with the student.', 'library' => 'Additional copies ordered; e-book access enabled for the title.',
        'infrastructure' => 'Estate department fixed the issue and scheduled weekly checks.', 'canteen' => 'Canteen vendor warned; quality checks by the committee every Monday.',
        'academic' => 'HOD reviewed and arranged extra sessions; schedule shared with the class.', 'faculty' => 'Discussed with the faculty member; pending classes rescheduled.',
        'administration' => 'Request processed and the document issued; turnaround time reduced to 3 working days.', 'ragging' => 'Anti-ragging committee investigated; disciplinary action taken and counselling arranged.',
    ];
    $students = db_all("SELECT id, first_name, last_name, email, mobile FROM students WHERE status = 'active' ORDER BY id LIMIT 400");
    $fbRows = [];
    $tk = 0;
    foreach ($fb as $k => [$type, $cat, $prio, $subject, $message]) {
        $status = $k < 8 ? 'open' : ($k < 16 ? 'in_progress' : ($k < 24 ? 'resolved' : 'closed'));
        if (in_array($k, [10, 22], true)) {
            $status = $k === 10 ? 'in_progress' : 'open';
        }
        $slaH = feedback_sla_hours($prio);
        $created = in_array($status, ['open', 'in_progress'], true)
            ? $inHours($clamp($nowTs - (int) ($slaH * mt_rand(8, 118) / 100 * 3600)))
            : ($status === 'resolved' ? $ago(6, 40) : $ago(15, 75));
        $by = (string) demo_weighted(['student' => 60, 'parent' => 12, 'faculty' => 8, 'staff' => 6, 'alumni' => 6, 'visitor' => 8]);
        if ($cat === 'ragging') {
            $by = 'student';
        }
        $st = in_array($by, ['student', 'parent'], true) && $students ? demo_pick($students) : null;
        $name = $st ? ($by === 'parent' ? demo_pick(demo_first_names('male')) . ' ' . $st['last_name'] : $st['first_name'] . ' ' . $st['last_name'])
            : demo_pick(demo_first_names(mt_rand(0, 1) ? 'male' : 'female')) . ' ' . demo_pick(demo_last_names());
        $started = $status !== 'open' ? date('Y-m-d H:i:s', min($nowTs - 120, strtotime($created) + mt_rand(2, 20) * 3600)) : null;
        $resolved = in_array($status, ['resolved', 'closed'], true) ? date('Y-m-d H:i:s', min($nowTs - 60, strtotime($created) + mt_rand((int) ($slaH * 0.3), (int) ($slaH * 1.3)) * 3600)) : null;
        $closed = $status === 'closed' ? date('Y-m-d H:i:s', min($nowTs - 30, strtotime($resolved) + mt_rand(24, 72) * 3600)) : null;
        $tk++;
        $assigned = $status === 'open' && mt_rand(1, 100) <= 50 ? null : $assigneeFor($cat);
        $fbRows[] = [
            'ticket_no' => 'TKT-2026-' . str_pad((string) $tk, 5, '0', STR_PAD_LEFT), 'type' => $type, 'category' => $cat, 'submitted_by_type' => $by,
            'student_id' => $st && in_array($by, ['student', 'parent'], true) ? (int) $st['id'] : null, 'name' => $name,
            'email' => $st ? $st['email'] : demo_email(explode(' ', $name)[0], explode(' ', $name)[1] ?? 'user', 'gmail.com', mt_rand(1, 99)), 'phone' => $st ? $st['mobile'] : demo_phone(),
            'subject' => $subject, 'message' => $message, 'rating' => $type === 'feedback' ? mt_rand(4, 5) : ($status === 'closed' ? mt_rand(3, 5) : null),
            'priority' => $prio, 'status' => $status, 'assigned_to' => $assigned, 'resolution' => $resolved ? $resolutions[$cat] ?? 'Issue addressed by the concerned department.' : null,
            'resolved_at' => $resolved, 'resolved_by' => $resolved ? ($assigned ?: $office) : null, 'closed_at' => $closed, 'started_at' => $started,
            'is_anonymous' => $cat === 'ragging' || mt_rand(1, 100) <= 6 ? 1 : 0, 'created_at' => $created, 'updated_at' => $closed ?: ($resolved ?: ($started ?: $created)),
        ];
    }
    array_multisort(array_column($fbRows, 'created_at'), SORT_ASC, $fbRows);
    foreach ($fbRows as $k => &$r) {
        $r['ticket_no'] = 'TKT-2026-' . str_pad((string) ($k + 1), 5, '0', STR_PAD_LEFT);
    }
    unset($r);
    demo_bulk_insert('feedback', $fbRows, 100);

    // ---------------------------------------------------------------- Sequences
    $seq = [
        'application:' . $sessionName => $appSeq, 'offer_letter' => $offerSeq, 'admission_receipt' => $receiptSeq, 'feedback_ticket' => count($fbRows),
    ];
    foreach ($seq as $name => $val) {
        db_exec('INSERT INTO sequences (name, current_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE current_value = VALUES(current_value)', [$name, $val]);
    }
    fwrite(STDOUT, sprintf("    admissions %d (converted %d), history %d, documents %d, follow-ups %d, enquiries %d, contact messages %d, feedback %d\n",
        count($apps), count($studentLinks), count($history), count($docs), count($followups) + count($enqFollow), count($enqRows), count($cmRows), count($fbRows)));
};
