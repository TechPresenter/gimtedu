<?php
/**
 * Demo seed for the Students unit (runs after 11_students.php, re-runnable):
 *   - personal details missing from the base seed (religion, Aadhaar, permanent address)
 *   - local guardians for ~12% of students + parent emails
 *   - semester-wise academic history (student_academic) for seniors, pass-outs and drop-outs
 *   - verification-workflow documents with real (tiny) PDF files in storage/private/student-docs/demo
 *   - promotion run log (student_promotions) for the Dec 2025 / Jun 2026 promotions
 * Never deletes students (other units reference them); only the rows this unit owns are rebuilt.
 */
require_once __DIR__ . '/lib/demo_data.php';
require_once APP_ROOT . '/app/services/students.php';

if (!function_exists('students_demo_pdf')) {
    /** Minimal one-page PDF (A4) with a navy header band and a few lines of text. */
    function students_demo_pdf(string $title, array $lines): string
    {
        $esc = fn (string $s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], preg_replace('/[^\x20-\x7E]/', '-', $s));
        $c = "0.043 0.165 0.357 rg 0 770 595 72 re f\n";
        $c .= "BT 1 1 1 rg /F2 16 Tf 40 812 Td (" . $esc('GLOBAL INSTITUTE OF MANAGEMENT & TECHNOLOGY') . ") Tj ET\n";
        $c .= "BT 1 1 1 rg /F1 10 Tf 40 792 Td (" . $esc('Student document - demo copy') . ") Tj ET\n";
        $c .= "BT 0.043 0.165 0.357 rg /F2 20 Tf 40 720 Td (" . $esc($title) . ") Tj ET\n";
        $y = 690;
        foreach ($lines as $l) {
            $c .= "BT 0.2 0.25 0.33 rg /F1 12 Tf 40 $y Td (" . $esc($l) . ") Tj ET\n";
            $y -= 22;
        }
        $c .= "0.8 0.84 0.9 RG 1 w 40 120 515 0.5 re S\n";
        $c .= "BT 0.45 0.5 0.58 rg /F1 9 Tf 40 100 Td (" . $esc('This file was generated for the GIMT SmartCampus demo. It is not an official record.') . ") Tj ET\n";
        $objs = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
            '<< /Length ' . strlen($c) . " >>\nstream\n" . $c . "endstream",
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
            $pdf .= str_pad((string) $off, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }
        return $pdf . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}

return function (array $opts): void {
    demo_seed(140214);
    $admin = (int) db_value("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.is_super = 1 ORDER BY u.id LIMIT 1") ?: null;
    $sessionByYear = array_map('intval', db_pairs('SELECT CAST(LEFT(name, 4) AS UNSIGNED) AS y, id FROM academic_sessions'));
    $sessionName = db_pairs('SELECT id, name FROM academic_sessions');
    $current = (int) db_value('SELECT id FROM academic_sessions WHERE is_current = 1');
    $students = db_all('SELECT s.id, s.first_name, s.last_name, s.student_uid, s.program_id, s.batch_id, s.current_semester, s.section_id, s.roll_no, s.status, s.category,
                               s.city, s.state, s.pincode, s.address, s.father_name, s.admission_date, s.academic_session_id, s.previous_qualification,
                               p.code, p.short_name, p.level, p.total_semesters, b.start_year
                        FROM students s JOIN programs p ON p.id = s.program_id LEFT JOIN batches b ON b.id = s.batch_id
                        WHERE s.created_by IS NULL ORDER BY s.id'); // seeded rows only — never touch students added through the UI / admissions
    if (!$students) {
        return;
    }

    /* ---------------- Personal details ---------------- */
    $religions = ['Hindu' => 78, 'Muslim' => 11, 'Sikh' => 4, 'Christian' => 3, 'Jain' => 2, 'Buddhist' => 1, 'Prefer not to say' => 1];
    db_transaction(function () use ($students, $religions) {
        foreach ($students as $s) {
            $aadhaar = (string) mt_rand(2, 9) . str_pad((string) mt_rand(0, 999), 3, '0', STR_PAD_LEFT) . str_pad((string) (((int) $s['id'] * 7919 + 13) % 100000000), 8, '0', STR_PAD_LEFT);
            $same = mt_rand(1, 100) <= 72;
            [$city, $state, $pin] = demo_city();
            $permanent = $same ? trim($s['address'] . ', ' . $s['city'] . ', ' . $s['state'] . ' ' . $s['pincode'], ', ')
                : mt_rand(1, 400) . ', ' . demo_pick(['Ward No. ', 'Mohalla ', 'Village ', 'Sector ']) . mt_rand(1, 30) . ', ' . $city . ', ' . $state . ' ' . $pin;
            db_exec('UPDATE students SET religion = COALESCE(religion, ?), aadhaar_no = COALESCE(aadhaar_no, ?), permanent_address = COALESCE(permanent_address, ?) WHERE id = ?', [demo_weighted($religions), $aadhaar, mb_substr($permanent, 0, 255), (int) $s['id']]);
        }
    });

    /* ---------------- Guardians & parent emails ---------------- */
    db_exec("DELETE sp FROM student_parents sp JOIN students s ON s.id = sp.student_id WHERE sp.relation = 'guardian' AND s.created_by IS NULL");
    $guardians = [];
    db_transaction(function () use ($students, &$guardians) {
        foreach ($students as $s) {
            $father = db_row("SELECT id, name, phone FROM student_parents WHERE student_id = ? AND relation = 'father' ORDER BY id LIMIT 1", [(int) $s['id']]);
            if ($father && mt_rand(1, 100) <= 42) {
                $parts = explode(' ', $father['name']);
                db_exec('UPDATE student_parents SET email = ? WHERE id = ?', [demo_email($parts[0], $parts[1] ?? 'parent', demo_pick(['gmail.com', 'yahoo.co.in', 'rediffmail.com', 'outlook.com']), (int) $s['id']), (int) $father['id']]);
            }
            if ($s['status'] === 'active' && mt_rand(1, 100) <= 12) {
                $relation = demo_pick(['Uncle', 'Aunt', 'Elder Brother', 'Local Guardian', 'Grandfather']);
                $gender = in_array($relation, ['Aunt'], true) ? 'female' : 'male';
                $name = demo_pick(demo_first_names($gender)) . ' ' . (mt_rand(1, 100) <= 70 ? $s['last_name'] : demo_pick(demo_last_names()));
                $phone = demo_phone();
                [$city] = demo_city();
                $guardians[] = ['student_id' => (int) $s['id'], 'relation' => 'guardian', 'name' => $name, 'phone' => $phone, 'email' => null,
                    'occupation' => demo_pick(['Business', 'Service', 'Teacher', 'Government Employee', 'Shopkeeper']), 'annual_income' => null,
                    'address' => mt_rand(10, 900) . ', ' . demo_pick(['Sector 62', 'Knowledge Park II', 'Alpha 1', 'Pari Chowk', 'Indirapuram']) . ', ' . $city, 'is_emergency_contact' => 0];
                db_exec('UPDATE students SET guardian_name = ?, guardian_relation = ?, guardian_phone = ? WHERE id = ?', [$name, $relation, $phone, (int) $s['id']]);
            } elseif ($father) {
                db_exec("UPDATE students SET guardian_name = ?, guardian_relation = 'Father', guardian_phone = ? WHERE id = ? AND (guardian_relation IS NULL OR guardian_relation NOT IN ('Father'))",
                    [$father['name'], $father['phone'], (int) $s['id']]);
            }
        }
    });
    demo_bulk_insert('student_parents', $guardians);

    /* ---------------- Academic history ---------------- */
    db_exec("DELETE sa FROM student_academic sa JOIN students s ON s.id = sa.student_id WHERE sa.status <> 'studying' AND s.created_by IS NULL");
    $history = [];
    $existingStudying = array_flip(array_map('intval', db_column("SELECT student_id FROM student_academic WHERE status = 'studying'")));
    $missingStudying = [];
    foreach ($students as $s) {
        $id = (int) $s['id'];
        $start = (int) ($s['start_year'] ?: substr((string) $s['admission_date'], 0, 4));
        $ability = 5.6 + mt_rand(0, 380) / 100;
        $cur = (int) $s['current_semester'];
        $total = (int) $s['total_semesters'];
        $lastSem = match ($s['status']) {
            'graduated' => $total,
            'active' => $cur - 1,
            'dropped', 'inactive', 'suspended' => max(0, $cur - 1),
            default => 0,
        };
        $sum = 0.0;
        for ($k = 1; $k <= $lastSem; $k++) {
            $year = $start + intdiv($k - 1, 2);
            $sessionId = $sessionByYear[$year] ?? null;
            if (!$sessionId) {
                continue;
            }
            $sgpa = round(max(4.6, min(9.9, $ability + mt_rand(-60, 60) / 100)), 2);
            $sum += $sgpa;
            $closed = $k % 2 === 1 ? $year . '-12-' . mt_rand(18, 23) : ($year + 1) . '-06-' . mt_rand(16, 24);
            $isLast = $s['status'] === 'graduated' && $k === $total;
            $history[] = [
                'student_id' => $id, 'academic_session_id' => $sessionId, 'program_id' => (int) $s['program_id'], 'semester_no' => $k, 'section_id' => null,
                'roll_no' => $s['roll_no'], 'status' => $isLast ? 'completed' : 'promoted', 'sgpa' => $sgpa, 'cgpa' => round($sum / $k, 2),
                'attendance_percent' => mt_rand(7000, 9800) / 100, 'promoted_at' => $closed . ' ' . sprintf('%02d:%02d:00', mt_rand(10, 16), mt_rand(0, 59)),
                'promoted_by' => $admin, 'remarks' => $isLast ? 'Programme completed' : 'Promoted to semester ' . ($k + 1),
            ];
        }
        if (in_array($s['status'], ['dropped', 'inactive', 'suspended'], true) && $cur >= 1) {
            $year = $start + intdiv($cur - 1, 2);
            if (isset($sessionByYear[$year])) {
                $history[] = [
                    'student_id' => $id, 'academic_session_id' => $sessionByYear[$year], 'program_id' => (int) $s['program_id'], 'semester_no' => $cur, 'section_id' => null,
                    'roll_no' => $s['roll_no'], 'status' => $s['status'] === 'dropped' ? 'dropped' : 'detained', 'sgpa' => null, 'cgpa' => $lastSem ? round($sum / $lastSem, 2) : null,
                    'attendance_percent' => mt_rand(3000, 6500) / 100, 'promoted_at' => $year . '-' . demo_pick(['09', '10', '11']) . '-' . mt_rand(10, 28) . ' 11:00:00',
                    'promoted_by' => $admin, 'remarks' => $s['status'] === 'dropped' ? 'Discontinued the programme' : 'Not attending — record on hold',
                ];
            }
        }
        if ($s['status'] === 'active' && !isset($existingStudying[$id])) {
            $missingStudying[] = ['student_id' => $id, 'academic_session_id' => $current, 'program_id' => (int) $s['program_id'], 'semester_no' => $cur, 'section_id' => $s['section_id'] ? (int) $s['section_id'] : null,
                'roll_no' => $s['roll_no'], 'status' => 'studying', 'sgpa' => null, 'cgpa' => null, 'attendance_percent' => null, 'promoted_at' => null, 'promoted_by' => null, 'remarks' => null];
        }
    }
    demo_bulk_insert('student_academic', $history);
    demo_bulk_insert('student_academic', $missingStudying);

    /* ---------------- Documents (private files) ---------------- */
    // only the seeded demo files are rebuilt; documents uploaded through the UI are kept
    $existing = db_column("SELECT file_path FROM student_documents WHERE file_path LIKE 'storage/private/student-docs/demo/%'");
    db_exec("DELETE FROM student_documents WHERE file_path LIKE 'storage/private/student-docs/demo/%'");
    foreach ($existing as $path) {
        if (str_starts_with((string) $path, 'storage/private/student-docs/demo/')) {
            delete_upload($path);
        }
    }
    $dir = APP_ROOT . '/storage/private/student-docs/demo';
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.pdf') ?: [] as $f) {
            @unlink($f);
        }
    } else {
        mkdir($dir, 0775, true);
    }
    $types = students_document_types();
    $boards = ['CBSE', 'UP Board', 'ICSE', 'Haryana Board', 'Rajasthan Board', 'Bihar Board'];
    $docs = [];
    foreach ($students as $s) {
        $id = (int) $s['id'];
        if (!($id <= 40 || $id % 7 === 0) || !in_array($s['status'], ['active', 'graduated'], true)) {
            continue;
        }
        $pg = in_array($s['level'], ['PG'], true);
        $set = ['10th_marksheet', '12th_marksheet', 'id_proof'];
        if ($pg) {
            $set[] = 'graduation';
            if (mt_rand(1, 100) <= 60) {
                $set[] = 'migration';
            }
        }
        if (mt_rand(1, 100) <= 70) {
            $set[] = 'transfer_certificate';
        }
        if (in_array($s['category'], ['OBC', 'SC', 'ST'], true)) {
            $set[] = 'caste_certificate';
        }
        if (mt_rand(1, 100) <= 25) {
            $set[] = 'income_certificate';
        }
        $name = trim($s['first_name'] . ' ' . $s['last_name']);
        $base = $s['admission_date'] ?: '2026-07-15';
        foreach ($set as $i => $type) {
            $title = match ($type) {
                '10th_marksheet' => 'Class X Marksheet (' . demo_pick($boards) . ')',
                '12th_marksheet' => 'Class XII Marksheet (' . demo_pick($boards) . ')',
                'graduation' => ($s['previous_qualification'] ?: 'Graduation') . ' Final Year Marksheet',
                'id_proof' => 'Aadhaar Card',
                default => $types[$type],
            };
            $file = 'storage/private/student-docs/demo/' . $id . '-' . str_replace('_', '-', $type) . '.pdf';
            $pdf = students_demo_pdf($title, ['Student: ' . $name, 'Student ID: ' . $s['student_uid'], 'Program: ' . $s['short_name'], 'Document type: ' . $types[$type], 'Uploaded during admission ' . substr($base, 0, 4)]);
            file_put_contents(APP_ROOT . '/' . $file, $pdf);
            $roll = mt_rand(1, 100);
            $status = $roll <= 72 ? 'verified' : ($roll <= 94 ? 'pending' : 'rejected');
            $uploaded = date('Y-m-d H:i:s', strtotime($base . ' +' . mt_rand(0, 6) . ' days ' . mt_rand(9, 17) . ' hours'));
            $docs[] = [
                'student_id' => $id, 'doc_type' => $type, 'title' => $title, 'file_path' => $file, 'original_name' => strtolower(str_replace(' ', '-', $types[$type])) . '.pdf',
                'mime' => 'application/pdf', 'size_bytes' => strlen($pdf), 'status' => $status,
                'remarks' => $status === 'rejected' ? demo_pick(['Scanned copy is not legible — please upload a clearer scan.', 'Name does not match the admission form.', 'Page 2 is missing.']) : null,
                'is_verified' => $status === 'verified' ? 1 : 0, 'verified_by' => $status === 'pending' ? null : $admin,
                'verified_at' => $status === 'pending' ? null : date('Y-m-d H:i:s', strtotime($uploaded . ' +' . mt_rand(1, 9) . ' days')),
                'uploaded_by' => $admin, 'created_at' => $uploaded,
            ];
        }
    }
    demo_bulk_insert('student_documents', $docs);

    /* ---------------- Promotion log ---------------- */
    db_exec("DELETE FROM student_promotions WHERE reference_no LIKE 'PRM/2025-26/%'"); // seeded history runs
    $programs = db_all('SELECT id, short_name, total_semesters FROM programs ORDER BY sort_order, id');
    $runs = [];
    $n = 0;
    $prevSession = $sessionByYear[2025] ?? null;
    foreach ([['2025-12-22', 'odd'], ['2026-06-20', 'even']] as [$date, $parity]) {
        foreach ($programs as $p) {
            for ($sem = $parity === 'odd' ? 1 : 2; $sem <= (int) $p['total_semesters']; $sem += 2) {
                $final = $sem === (int) $p['total_semesters'];
                if ($parity === 'odd') {
                    // Dec 2025: students now in semester sem+2 moved sem -> sem+1 within 2025-26
                    $ids = db_column("SELECT id FROM students WHERE program_id = ? AND current_semester = ? AND status IN ('active', 'inactive', 'suspended', 'dropped')", [(int) $p['id'], $sem + 2]);
                    $from = $prevSession;
                    $to = $prevSession;
                    $toSem = $final ? null : $sem + 1;
                } else {
                    $ids = $final ? db_column("SELECT id FROM students WHERE program_id = ? AND status = 'graduated'", [(int) $p['id']])
                        : db_column("SELECT id FROM students WHERE program_id = ? AND current_semester = ? AND status IN ('active', 'inactive', 'suspended', 'dropped')", [(int) $p['id'], $sem + 1]);
                    $from = $prevSession;
                    $to = $final ? null : $current;
                    $toSem = $final ? null : $sem + 1;
                }
                if (!$ids || ($final && $parity === 'odd')) {
                    continue;
                }
                $n++;
                $detained = mt_rand(0, 100) <= 30 ? mt_rand(1, 2) : 0;
                $details = array_map(fn ($sid) => ['student_id' => (int) $sid, 'action' => $final ? 'pass_out' : 'promote'], $ids);
                $runs[] = [
                    'reference_no' => 'PRM/' . ($sessionName[$prevSession] ?? '2025-26') . '/' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                    'program_id' => (int) $p['id'], 'from_semester' => $sem, 'to_semester' => $toSem, 'from_section_id' => null, 'from_session_id' => $from, 'to_session_id' => $to,
                    'promoted_count' => $final ? 0 : count($ids), 'detained_count' => $final ? 0 : $detained, 'passed_out_count' => $final ? count($ids) : 0, 'skipped_count' => 0,
                    'details' => json_encode($details), 'remarks' => $final ? 'Final semester results declared — batch passed out' : ($parity === 'odd' ? 'End of odd semester' : 'End of academic session'),
                    'created_by' => $admin, 'created_at' => $date . ' ' . sprintf('%02d:%02d:00', 10 + intdiv($n, 6) % 7, ($n * 7) % 60),
                ];
            }
        }
    }
    demo_bulk_insert('student_promotions', $runs);
    students_sequence_floor('student_promotion', $n);
};
