<?php
/**
 * Demo data for the examination unit (re-runnable, deterministic for a given seed date).
 *
 *   History (every past session in student_academic): one End Semester Examination per program/semester (Dec for odd,
 *     May for even semesters) with a date sheet, rooms, invigilators, hall tickets, exam attendance, realistic marks
 *     (stable per-student ability + subject difficulty + noise), computed results (SGPA, CGPA across semesters, ranks,
 *     PASS / BACKLOG / FAIL / ABSENT / WITHHELD), published, synced to student_academic, and marksheets issued
 *     (a few recent exams are left pending generation, one marksheet revoked).
 *   Current session (anchored on today's date):
 *     - Mid Term Examinations for every class: BBA + B.Tech (AI & DS) results published, MBA/MCA verified (awaiting publication),
 *       B.Tech (CSE)/BCA/B.Com/M.Com in marks entry (the demo faculty user's papers part-way) (some subjects submitted, some partial, some not started),
 *       B.Sc DS/DCA/certificate programs ongoing (papers today and in the coming days).
 *     - End Semester Examinations scheduled ~8 weeks ahead (date sheet, rooms, invigilators, eligibility with attendance
 *       shortfalls + manual overrides, hall tickets); certificate programs not yet allocated, DCA still in draft.
 *     - Two practical examinations in draft.
 *
 * Owns: exam_types, grade_scales, exams, exam_subjects, exam_schedules, exam_invigilators, exam_students, exam_attendance,
 *       exam_marks, results, marksheets (+ sequences 'hall_ticket' / 'marksheet', seed-tagged activity logs).
 * Writes student_academic.sgpa / cgpa for published final results (same as publishing from the UI).
 */
return function (array $opts): void {
    require_once __DIR__ . '/lib/demo_data.php';
    require_once APP_ROOT . '/app/services/exams.php';
    demo_seed(2323);
    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');

    // ---------------------------------------------------------------- clear owned rows (children first)
    foreach (['marksheets', 'results', 'exam_marks', 'exam_attendance', 'exam_invigilators', 'exam_students', 'exam_schedules', 'exam_subjects', 'exams'] as $t) {
        db_exec('DELETE FROM ' . $t);
    }
    foreach (['exams', 'exam_subjects', 'exam_schedules', 'exam_invigilators', 'exam_students', 'exam_attendance', 'exam_marks', 'results', 'marksheets'] as $t) {
        db_exec('ALTER TABLE ' . $t . ' AUTO_INCREMENT = 1'); // ids start again at 1 (e.g. /examination/1) - tables are empty here
    }
    db_exec("DELETE FROM activity_logs WHERE module IN ('examination','results') AND meta LIKE ?", ['%"seed":"23_exams"%']);
    db_exec("DELETE FROM sequences WHERE name IN ('hall_ticket','marksheet')");

    // ---------------------------------------------------------------- exam types + grading scale (upsert, keeps ids)
    $types = [
        ['IA', 'Internal Assessment', 30.00, 0, 'Continuous internal assessment - class tests, assignments and quizzes.'],
        ['MID', 'Mid Term Examination', 20.00, 0, 'Mid-semester written examination (40 + 10 marks continuous assessment).'],
        ['ESE', 'End Semester Examination', 70.00, 1, 'End-semester theory and practical examination - produces SGPA / CGPA and marksheets.'],
        ['PRAC', 'Practical Examination', null, 0, 'Laboratory / practical examination with viva voce.'],
        ['SUPP', 'Supplementary Examination', null, 1, 'Re-appear examination for students with backlogs.'],
    ];
    foreach ($types as [$code, $name, $w, $final, $desc]) {
        db_exec('INSERT INTO exam_types (name, code, weightage, is_final, description, status) VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), weightage = VALUES(weightage), is_final = VALUES(is_final), description = VALUES(description), status = VALUES(status)',
            [$name, $code, $w, $final, $desc, 'active']);
    }
    $typeIds = array_map('intval', db_pairs('SELECT code, id FROM exam_types'));
    $typeNames = db_pairs('SELECT code, name FROM exam_types');
    $grades = [['O', 90, 100, 10, 'Outstanding', 1], ['A+', 80, 89.99, 9, 'Excellent', 1], ['A', 70, 79.99, 8, 'Very Good', 1], ['B+', 60, 69.99, 7, 'Good', 1],
        ['B', 50, 59.99, 6, 'Above Average', 1], ['C', 45, 49.99, 5, 'Average', 1], ['P', 40, 44.99, 4, 'Pass', 1], ['F', 0, 39.99, 0, 'Fail', 0]];
    db_exec('DELETE FROM grade_scales WHERE grade NOT IN (' . implode(',', array_fill(0, count($grades), '?')) . ')', array_column($grades, 0));
    foreach ($grades as $i => [$g, $min, $max, $gp, $desc, $pass]) {
        db_exec('INSERT INTO grade_scales (grade, min_percent, max_percent, grade_point, description, is_pass, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE min_percent = VALUES(min_percent), max_percent = VALUES(max_percent), grade_point = VALUES(grade_point), description = VALUES(description),
                   is_pass = VALUES(is_pass), sort_order = VALUES(sort_order)', [$g, $min, $max, $gp, $desc, $pass, $i]);
    }
    $scale = grade_scale(true);

    // Examination settings (only when missing - the settings screen owns them afterwards)
    $settings = settings_all(true);
    foreach (['exam_max_backlogs' => '3', 'exam_block_fee_dues' => '1', 'exam_fee_grace_days' => '30', 'exam_controller_name' => 'Dr. Rajesh Khanna', 'exam_controller_designation' => 'Controller of Examinations'] as $k => $v) {
        if (!array_key_exists($k, $settings)) {
            save_setting($k, $v, 'academic');
        }
    }

    // ---------------------------------------------------------------- reference data
    $examsUser = (int) (db_value("SELECT id FROM users WHERE username = 'exams'") ?: db_value('SELECT id FROM users ORDER BY id LIMIT 1'));
    $facultyUser = db_row("SELECT id, faculty_id FROM users WHERE username = 'faculty' AND faculty_id IS NOT NULL");
    $facultyUserSubjects = $facultyUser ? array_flip(array_map('intval', db_column('SELECT DISTINCT subject_id FROM faculty_subjects WHERE faculty_id = ?', [(int) $facultyUser['faculty_id']]))) : [];
    $sessions = db_all('SELECT * FROM academic_sessions ORDER BY start_date');
    $current = current_session();
    $currentId = (int) $current['id'];
    $programs = [];
    foreach (db_all('SELECT * FROM programs') as $p) {
        $programs[(int) $p['id']] = $p;
    }
    $subjectsOf = [];
    foreach (db_all("SELECT * FROM subjects WHERE status = 'active' ORDER BY program_id, semester_no, FIELD(type, 'theory', 'elective', 'lab', 'practical', 'project'), code") as $s) {
        $subjectsOf[(int) $s['program_id'] . '-' . (int) $s['semester_no']][] = $s;
    }
    $rooms = db_all("SELECT id, code, capacity, type FROM classrooms WHERE status = 'active' ORDER BY capacity, code");
    $roomById = array_column($rooms, null, 'id');
    $facultyByDept = [];
    $allFaculty = [];
    foreach (db_all("SELECT id, department_id FROM faculty WHERE status = 'active' ORDER BY id") as $f) {
        $facultyByDept[(int) $f['department_id']][] = (int) $f['id'];
        $allFaculty[] = (int) $f['id'];
    }
    $studentRows = db_all('SELECT id, program_id, current_semester, section_id, roll_no, student_uid, status FROM students ORDER BY roll_no, student_uid');
    $roll = [];
    foreach ($studentRows as $i => $s) {
        $roll[(int) $s['id']] = $i;
    }
    $blocked = exam_blocked_dates('2020-01-01', date('Y-m-d', strtotime($today . ' +400 days')));

    // ---------------------------------------------------------------- helpers
    $gauss = function (): float {
        $u1 = (mt_rand() + 1) / (mt_getrandmax() + 2);
        $u2 = (mt_rand() + 1) / (mt_getrandmax() + 2);
        return sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    };
    // Stable "ability" per student (independent of processing order) so CGPA histories look consistent
    $ability = function (int $sid): float {
        $h = crc32('gimt-ability-' . $sid);
        $u1 = (($h & 0xFFFF) + 1) / 65537;
        $u2 = ((($h >> 16) & 0x7FFF) + 1) / 32769;
        $z = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
        return max(0.34, min(0.97, 0.705 + 0.115 * $z));
    };
    $difficulty = fn (int $subjectId, int $examKey): float => ((crc32("diff-$subjectId-$examKey") % 1000) / 1000 - 0.5) * 0.12;
    $nextWorkday = function (string $d) use ($blocked): string {
        $t = strtotime($d);
        while (date('N', $t) == 7 || isset($blocked[date('Y-m-d', $t)])) {
            $t += 86400;
        }
        return date('Y-m-d', $t);
    };
    $paperDates = function (string $start, int $n, int $gap) use ($nextWorkday): array {
        $out = [];
        $d = $nextWorkday($start);
        for ($i = 0; $i < $n; $i++) {
            $out[] = $d;
            $d = $nextWorkday(date('Y-m-d', strtotime($d . " +$gap days")));
        }
        return $out;
    };
    $mondayOnOrAfter = function (string $d): string {
        $t = strtotime($d);
        while (date('N', $t) != 1) {
            $t += 86400;
        }
        return date('Y-m-d', $t);
    };
    $roomUse = [];   // "date|start" => [room_id => seats used]
    $allocRoom = function (string $date, string $start, int $need) use (&$roomUse, $rooms): ?int {
        $k = "$date|$start";
        foreach ($rooms as $r) {
            if ($r['type'] === 'classroom' && (int) $r['capacity'] >= $need && empty($roomUse[$k][$r['id']])) {
                $roomUse[$k][$r['id']] = $need;
                return (int) $r['id'];
            }
        }
        foreach ($rooms as $r) {
            if (in_array($r['type'], ['exam_hall', 'seminar_hall', 'auditorium'], true) && ($roomUse[$k][$r['id']] ?? 0) + $need <= (int) $r['capacity']) {
                $roomUse[$k][$r['id']] = ($roomUse[$k][$r['id']] ?? 0) + $need;
                return (int) $r['id'];
            }
        }
        return null;
    };
    $busy = [];      // "date|start" => [faculty_id => true]
    $rot = [];
    $pickInvigilators = function (int $deptId, string $key, int $n) use (&$busy, &$rot, $facultyByDept, $allFaculty): array {
        $pool = $facultyByDept[$deptId] ?? [];
        $pool = array_merge($pool, array_values(array_diff($allFaculty, $pool)));
        if (!$pool) {
            return [];
        }
        $out = [];
        $start = $rot[$deptId] ?? 0;
        for ($i = 0; $i < count($pool) && count($out) < $n; $i++) {
            $fid = $pool[($start + $i) % count($pool)];
            if (empty($busy[$key][$fid])) {
                $busy[$key][$fid] = true;
                $out[] = $fid;
            }
        }
        $rot[$deptId] = ($start + max(1, count($out))) % count($pool);
        return $out;
    };
    $ht = 0;
    $logs = [];
    $log = function (string $action, string $module, int $examId, string $desc, string $at) use (&$logs, $examsUser): void {
        $logs[] = ['user_id' => $examsUser, 'action' => $action, 'module' => $module, 'record_id' => (string) $examId, 'description' => mb_substr($desc, 0, 500), 'status' => 'success',
            'ip_address' => '10.0.12.25', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
            'meta' => '{"seed":"23_exams"}', 'created_at' => $at];
    };

    /**
     * Create an exam with subjects, (optionally) its date sheet and allocated students.
     * $c: type, session_id, program_id, semester, start, gap, slot [start,end], status, min_att, students (ids), schedule (bool),
     *     allocate (bool), eligibility (student_id => [eligible, reason, attendance, fee_due]), created_at, result_date, name_suffix, subject_filter
     */
    $createExam = function (array $c) use (&$ht, $typeIds, $typeNames, $programs, $subjectsOf, $paperDates, $allocRoom, $pickInvigilators, $roomById, $roll, $examsUser, $log): array {
        $p = $programs[$c['program_id']];
        $subjects = $subjectsOf[$c['program_id'] . '-' . $c['semester']] ?? [];
        if (!empty($c['subject_filter'])) {
            $subjects = array_values(array_filter($subjects, $c['subject_filter']));
        }
        if (!$subjects) {
            return [];
        }
        $dates = $paperDates($c['start'], count($subjects), $c['gap']);
        $start = $dates[0];
        $end = end($dates);
        $label = $typeNames[$c['type']];
        $name = $p['short_name'] . ' Sem ' . $c['semester'] . ' — ' . $label . ' (' . date('M Y', strtotime($start)) . ')';
        $examId = db_insert('exams', [
            'name' => $name, 'exam_type_id' => $typeIds[$c['type']], 'academic_session_id' => $c['session_id'], 'program_id' => $c['program_id'], 'semester_no' => $c['semester'],
            'start_date' => $start, 'end_date' => $end, 'result_date' => $c['result_date'] ?? null, 'status' => $c['status'], 'min_attendance_percent' => $c['min_att'] ?? null,
            'instructions' => $c['instructions'] ?? 'Report 30 minutes before the paper with your admit card and college ID card. Mobile phones, smart watches and programmable calculators are not allowed inside the examination hall.',
            'created_by' => $examsUser, 'created_at' => $c['created_at'],
        ]);
        $e = ['id' => $examId, 'is_final' => $c['type'] === 'ESE' ? 1 : 0, 'type_code' => $c['type']];
        $es = [];
        foreach ($subjects as $s) {
            $es[(int) $s['id']] = ['exam_id' => $examId, 'subject_id' => (int) $s['id']] + exam_subject_defaults($s, $e);
        }
        demo_bulk_insert('exam_subjects', array_values($es));
        $log('create', 'examination', $examId, 'Created exam "' . $name . '"', $c['created_at']);
        $log('update', 'examination', $examId, 'Added ' . count($es) . ' curriculum subjects to "' . $name . '"', date('Y-m-d H:i:s', strtotime($c['created_at'] . ' +4 minutes')));

        $students = $c['students'] ?? [];
        usort($students, fn ($a, $b) => ($roll[$a] ?? 0) <=> ($roll[$b] ?? 0));
        $elig = $c['eligibility'] ?? [];
        $eligibleCount = 0;
        foreach ($students as $sid) {
            $eligibleCount += ($elig[$sid][0] ?? true) ? 1 : 0;
        }
        $papers = [];
        if (!empty($c['schedule'])) {
            $i = 0;
            $examRooms = [];
            foreach ($subjects as $s) {
                $d = $dates[$i++];
                [$t1, $t2] = $c['slot'];
                $key = "$d|$t1";
                $room = $allocRoom($d, $t1, max($eligibleCount, (int) ($c['headcount'] ?? 0)) ?: 40);
                $inv = $pickInvigilators((int) $p['department_id'], $key, ($eligibleCount > 60 ? 3 : 2));
                $sch = db_insert('exam_schedules', ['exam_id' => $examId, 'subject_id' => (int) $s['id'], 'exam_date' => $d, 'start_time' => $t1, 'end_time' => $t2,
                    'classroom_id' => $room, 'invigilator_id' => $inv[0] ?? null, 'notes' => in_array($s['type'], ['lab', 'practical'], true) ? 'Practical batches as per lab roster; viva voce in the lab.' : null,
                    'created_at' => date('Y-m-d H:i:s', strtotime($c['created_at'] . ' +1 day'))]);
                $extra = [];
                foreach (array_slice($inv, 1) as $fid) {
                    $extra[] = ['exam_schedule_id' => $sch, 'faculty_id' => $fid, 'classroom_id' => $room];
                }
                demo_bulk_insert('exam_invigilators', $extra);
                $papers[(int) $s['id']] = ['id' => $sch, 'date' => $d, 'room' => $room, 'type' => $s['type']];
                if ($room) {
                    $examRooms[$room] = true;
                }
            }
            $log('update', 'examination', $examId, 'Generated the date sheet of "' . $name . '" (' . count($papers) . ' papers)', date('Y-m-d H:i:s', strtotime($c['created_at'] . ' +1 day')));
        }
        if (!empty($c['allocate']) && $students) {
            // Seats: largest exam room first, roll-number order (same rule as exam_assign_seats())
            $seatRooms = [];
            foreach ($papers as $pp) {
                if ($pp['room']) {
                    $seatRooms[$pp['room']] = $roomById[$pp['room']];
                }
            }
            uasort($seatRooms, fn ($a, $b) => [(int) $b['capacity'], $a['code']] <=> [(int) $a['capacity'], $b['code']]);
            $seatRooms = array_values($seatRooms);
            $ri = 0;
            $used = 0;
            $rows = [];
            $yy = date('y', strtotime($start));
            foreach ($students as $n => $sid) {
                [$ok, $reason, $att, $fee] = $elig[$sid] ?? [true, null, null, 0];
                $seat = null;
                $room = null;
                if ($ok) {
                    $r = $seatRooms[$ri] ?? null;
                    if ($r && $used >= (int) $r['capacity'] && isset($seatRooms[$ri + 1])) {
                        $ri++;
                        $used = 0;
                        $r = $seatRooms[$ri];
                    }
                    $used++;
                    $seat = ($r ? $r['code'] . '-' : 'S-') . str_pad((string) ($r ? $used : $n + 1), 3, '0', STR_PAD_LEFT);
                    $room = $r ? (int) $r['id'] : null;
                }
                $ht++;
                $rows[] = ['exam_id' => $examId, 'student_id' => $sid, 'is_eligible' => $ok ? 1 : 0, 'ineligibility_reason' => $reason, 'attendance_percent' => $att, 'fee_due' => $fee,
                    'is_override' => 0, 'override_reason' => null, 'overridden_by' => null, 'overridden_at' => null,
                    'hall_ticket_no' => 'HT' . $yy . str_pad((string) $ht, 6, '0', STR_PAD_LEFT), 'seat_no' => $seat, 'classroom_id' => $room, 'created_at' => date('Y-m-d H:i:s', strtotime($c['created_at'] . ' +2 days'))];
            }
            demo_bulk_insert('exam_students', $rows);
            $ineligible = count($students) - $eligibleCount;
            $log('update', 'examination', $examId, sprintf('Allocated %d students to "%s" (%d eligible, %d not eligible)', count($students), $name, $eligibleCount, $ineligible), date('Y-m-d H:i:s', strtotime($c['created_at'] . ' +2 days')));
        }
        return ['id' => $examId, 'name' => $name, 'subjects' => $es, 'subject_rows' => $subjects, 'papers' => $papers, 'students' => $students, 'start' => $start, 'end' => $end,
            'program' => $p, 'semester' => $c['semester'], 'eligible' => array_values(array_filter($students, fn ($sid) => $elig[$sid][0] ?? true))];
    };

    /**
     * Marks + exam attendance for held papers. $plan: subject_id => ['fill' => 0..1, 'status' => draft|submitted|verified|published]
     */
    $enterMarks = function (array $x, array $plan, float $absentRate, string $enteredAt, ?string $verifiedAt = null, array $absentOverride = []) use ($gauss, $ability, $difficulty, $scale, $examsUser, $facultyUser, $facultyUserSubjects, $today): array {
        $marks = [];
        $att = [];
        $stats = ['entered' => 0];
        foreach ($x['subjects'] as $subjectId => $es) {
            $paper = $x['papers'][$subjectId] ?? null;
            $held = $paper && $paper['date'] < $today;
            $p = $plan[$subjectId] ?? null;
            $diff = $difficulty($subjectId, (int) $x['id']);
            foreach ($x['eligible'] as $i => $sid) {
                $absent = isset($absentOverride["$sid-$subjectId"]) || (mt_rand(1, 10000) <= $absentRate * 10000);
                if ($held) {
                    $att[] = ['exam_schedule_id' => $paper['id'], 'student_id' => $sid, 'status' => $absent ? 'absent' : 'present', 'remarks' => null, 'marked_by' => $examsUser,
                        'created_at' => $paper['date'] . ' 13:15:00'];
                }
                if (!$p || !$held) {
                    continue;
                }
                if ($p['fill'] < 1 && mt_rand(1, 1000) > $p['fill'] * 1000) {
                    continue;
                }
                $a = $ability($sid) + $diff + 0.075 * $gauss();
                $int = (int) $es['max_internal'] ? round(max(0.25, min(1, $a + 0.07 + 0.05 * $gauss())) * (int) $es['max_internal']) : null;
                $ext = (int) $es['max_external'] ? round(max(0.04, min(1, $a + 0.035 * $gauss())) * (int) $es['max_external'] * 2) / 2 : null;
                $prac = (int) $es['max_practical'] ? round(max(0.25, min(1, $a + 0.09 + 0.05 * $gauss())) * (int) $es['max_practical']) : null;
                $calc = exam_mark_compute($es, $int !== null ? (float) $int : null, $ext, $prac !== null ? (float) $prac : null, $absent, $scale);
                $by = $facultyUser && isset($facultyUserSubjects[$subjectId]) ? (int) $facultyUser['id'] : $examsUser;
                $marks[] = ['exam_id' => (int) $x['id'], 'subject_id' => $subjectId, 'student_id' => $sid, 'internal_marks' => $calc['internal_marks'], 'external_marks' => $calc['external_marks'],
                    'practical_marks' => $calc['practical_marks'], 'total_marks' => $calc['total_marks'], 'max_marks' => $calc['max_marks'], 'grade' => $calc['grade'], 'grade_point' => $calc['grade_point'],
                    'is_absent' => $calc['is_absent'], 'is_pass' => $calc['is_pass'], 'status' => $p['status'], 'remarks' => null, 'entered_by' => $by,
                    'verified_by' => in_array($p['status'], ['verified', 'published'], true) ? $examsUser : null, 'verified_at' => in_array($p['status'], ['verified', 'published'], true) ? $verifiedAt : null,
                    'created_at' => $enteredAt, 'updated_at' => $enteredAt];
                $stats['entered']++;
            }
        }
        demo_bulk_insert('exam_attendance', $att, 500);
        demo_bulk_insert('exam_marks', $marks, 400);
        return $stats;
    };

    $publish = function (array $x, string $verifiedAt, string $publishedAt, string $resultDate) use ($examsUser, $log): void {
        db_update('exams', ['status' => 'verified', 'verified_at' => $verifiedAt, 'verified_by' => $examsUser], 'id = ?', [$x['id']]);
        exam_compute_results((int) $x['id']);
        db_exec('UPDATE results SET is_published = 1, published_at = ? WHERE exam_id = ?', [$publishedAt, $x['id']]);
        db_exec("UPDATE exam_marks SET status = 'published' WHERE exam_id = ?", [$x['id']]);
        db_update('exams', ['status' => 'published', 'published_at' => $publishedAt, 'published_by' => $examsUser, 'result_date' => $resultDate], 'id = ?', [$x['id']]);
        $e = exam_find((int) $x['id']);
        exam_sync_student_academic($e);
        $c = exam_result_counts((int) $x['id']);
        $log('approve', 'results', (int) $x['id'], 'Verified and locked the marks of "' . $x['name'] . '" — ' . $c['total'] . ' results computed', $verifiedAt);
        $log('publish', 'results', (int) $x['id'], sprintf('Published the results of "%s" — %d students, %s%% pass', $x['name'], $c['total'], $c['pass_percent'] ?? 0), $publishedAt);
    };

    // ================================================================ HISTORY: past sessions (End Semester Examinations)
    $history = [];
    foreach ($sessions as $ss) {
        if ((int) $ss['id'] === $currentId || $ss['start_date'] >= $current['start_date']) {
            continue;
        }
        $cohorts = db_all("SELECT sa.program_id, sa.semester_no, GROUP_CONCAT(sa.student_id ORDER BY sa.student_id) AS ids FROM student_academic sa
                           WHERE sa.academic_session_id = ? AND sa.status IN ('promoted','completed','studying','detained')
                           GROUP BY sa.program_id, sa.semester_no ORDER BY sa.semester_no, sa.program_id", [(int) $ss['id']]);
        $y1 = (int) substr($ss['start_date'], 0, 4);
        foreach ($cohorts as $co) {
            $sem = (int) $co['semester_no'];
            $odd = $sem % 2 === 1;
            $start = $mondayOnOrAfter($odd ? "$y1-12-01" : ($y1 + 1) . '-05-04');
            $history[] = ['session' => $ss, 'program_id' => (int) $co['program_id'], 'semester' => $sem, 'start' => $start,
                'students' => array_map('intval', explode(',', $co['ids']))];
        }
    }
    usort($history, fn ($a, $b) => [$a['start'], $a['semester'], $a['program_id']] <=> [$b['start'], $b['semester'], $b['program_id']]);
    $latestEven = max(array_map(fn ($h) => $h['semester'] % 2 === 0 ? $h['start'] : '0000', $history) ?: ['0000']);
    $withheldPlan = null;
    $msPending = [];
    $histDone = [];
    foreach ($history as $h) {
        $slot = $h['semester'] >= 5 ? ['14:00:00', '17:00:00'] : ['10:00:00', '13:00:00'];
        $x = $createExam(['type' => 'ESE', 'session_id' => (int) $h['session']['id'], 'program_id' => $h['program_id'], 'semester' => $h['semester'], 'start' => $h['start'], 'gap' => 2,
            'slot' => $slot, 'status' => 'scheduled', 'min_att' => null, 'students' => $h['students'], 'schedule' => true, 'allocate' => true,
            'created_at' => date('Y-m-d 11:20:00', strtotime($h['start'] . ' -35 days'))]);
        if (!$x) {
            continue;
        }
        $plan = array_fill_keys(array_keys($x['subjects']), ['fill' => 1, 'status' => 'verified']);
        $absentOverride = [];
        $isLatest = $h['start'] === $latestEven;
        if ($isLatest && !$withheldPlan && $h['program_id'] === 1) {
            $sid = $x['eligible'][7] ?? $x['eligible'][0];
            $withheldPlan = ['exam' => $x, 'student_id' => $sid, 'subject_id' => array_keys($x['subjects'])[2]];
        }
        $verifiedAt = date('Y-m-d 16:30:00', strtotime($x['end'] . ' +18 days'));
        $publishedAt = date('Y-m-d 11:00:00', strtotime($x['end'] . ' +24 days'));
        $enterMarks($x, $plan, 0.008, date('Y-m-d 15:00:00', strtotime($x['end'] . ' +9 days')), $verifiedAt, $absentOverride);
        if ($withheldPlan && $withheldPlan['exam']['id'] === $x['id']) {
            // Malpractice case: recorded at the paper, result withheld pending the committee's decision
            $paper = $x['papers'][$withheldPlan['subject_id']];
            db_exec("UPDATE exam_attendance SET status = 'malpractice', remarks = ? WHERE exam_schedule_id = ? AND student_id = ?",
                ['Found in possession of unauthorised written material; answer book seized and case referred to the UFM committee.', $paper['id'], $withheldPlan['student_id']]);
            db_exec('INSERT INTO results (exam_id, student_id, academic_session_id, program_id, semester_no, result_status, withheld_reason, withheld_by, withheld_at, is_published)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)', [$x['id'], $withheldPlan['student_id'], (int) $h['session']['id'], $h['program_id'], $h['semester'], 'WITHHELD',
                'Unfair means (UFM) case under enquiry by the examination committee.', $examsUser, date('Y-m-d 12:00:00', strtotime($x['end'] . ' +15 days'))]);
        }
        $publish($x, $verifiedAt, $publishedAt, date('Y-m-d', strtotime($publishedAt)));
        if ($isLatest && in_array($h['program_id'], [5, 7, 9], true)) {
            $msPending[$x['id']] = true;
        }
        $x['published_at'] = $publishedAt;
        $histDone[] = $x;
    }

    // Marksheets for published history results (some recent exams left for the "generate" workflow)
    $msNo = 0;
    $msRows = [];
    foreach ($histDone as $x) {
        if (isset($msPending[$x['id']])) {
            continue;
        }
        $issued = date('Y-m-d 10:30:00', strtotime($x['published_at'] . ' +6 days'));
        $year = date('Y', strtotime($issued));
        foreach (db_all("SELECT id, student_id FROM results WHERE exam_id = ? AND is_published = 1 AND result_status <> 'WITHHELD' ORDER BY id", [$x['id']]) as $r) {
            $msNo++;
            $prints = demo_weighted([0 => 20, 1 => 65, 2 => 15]);
            $msRows[] = ['marksheet_no' => 'GIMT-MS-' . $year . '-' . str_pad((string) $msNo, 6, '0', STR_PAD_LEFT), 'result_id' => (int) $r['id'], 'student_id' => (int) $r['student_id'],
                'verification_code' => bin2hex(pack('N*', crc32("ms-$msNo-a"), crc32("ms-$msNo-b"), crc32("ms-$msNo-c"), crc32("ms-$msNo-d"))), 'issued_at' => $issued, 'issued_by' => $examsUser,
                'printed_count' => $prints, 'last_printed_at' => $prints ? date('Y-m-d H:i:s', strtotime($issued . ' +' . mt_rand(1, 20) . ' days')) : null, 'status' => 'issued',
                'revoked_reason' => null, 'revoked_at' => null];
        }
        $log('create', 'results', (int) $x['id'], 'Generated marksheets for "' . $x['name'] . '"', $issued);
    }
    if ($msRows) {
        // One revoked marksheet (name correction requested - a fresh copy will be re-issued)
        $k = (int) floor(count($msRows) * 0.8);
        $msRows[$k]['status'] = 'revoked';
        $msRows[$k]['revoked_reason'] = "Spelling of father's name corrected in the student record - marksheet to be re-issued.";
        $msRows[$k]['revoked_at'] = date('Y-m-d H:i:s', strtotime($msRows[$k]['issued_at'] . ' +12 days'));
    }
    demo_bulk_insert('marksheets', $msRows, 400);

    // ================================================================ CURRENT SESSION
    $cohorts = [];
    foreach ($studentRows as $s) {
        if ($s['status'] === 'active') {
            $cohorts[(int) $s['program_id'] . '-' . (int) $s['current_semester']][] = (int) $s['id'];
        }
    }
    uksort($cohorts, function ($a, $b) use ($programs) {
        [$pa, $sa] = array_map('intval', explode('-', $a));
        [$pb, $sb] = array_map('intval', explode('-', $b));
        return [(int) ($programs[$pa]['sort_order'] ?? 0), $sa] <=> [(int) ($programs[$pb]['sort_order'] ?? 0), $sb];
    });
    $daysAgo = fn (int $n) => date('Y-m-d', strtotime($today . " -$n days"));

    // ---- Mid Term Examinations
    // B.Tech (CSE) stays in marks entry so the demo faculty user (who teaches B.Tech subjects) has marks to enter
    $midGroups = [1 => 'published', 4 => 'published', 2 => 'verified', 6 => 'verified', 3 => 'marks', 5 => 'marks', 7 => 'marks', 8 => 'marks', 9 => 'ongoing', 10 => 'ongoing', 11 => 'ongoing', 12 => 'ongoing'];
    $midStart = ['published' => 27, 'verified' => 20, 'marks' => 12, 'ongoing' => 3];
    foreach ($cohorts as $key => $ids) {
        [$pid, $sem] = array_map('intval', explode('-', $key));
        if (!isset($programs[$pid])) {
            continue;
        }
        $group = $midGroups[$pid] ?? 'marks';
        $start = $daysAgo($midStart[$group]);
        $slot = $sem >= 5 ? ['14:00:00', '15:30:00'] : ['10:00:00', '11:30:00'];
        $x = $createExam(['type' => 'MID', 'session_id' => $currentId, 'program_id' => $pid, 'semester' => $sem, 'start' => $start, 'gap' => 1, 'slot' => $slot,
            'status' => 'scheduled', 'min_att' => 0, 'students' => $ids, 'schedule' => true, 'allocate' => true,
            'created_at' => date('Y-m-d 10:05:00', strtotime($start . ' -24 days'))]);
        if (!$x) {
            continue;
        }
        $subjectIds = array_keys($x['subjects']);
        if ($group === 'published' || $group === 'verified') {
            $verifiedAt = date('Y-m-d 17:10:00', strtotime($x['end'] . ' +6 days'));
            $enterMarks($x, array_fill_keys($subjectIds, ['fill' => 1, 'status' => $group === 'published' ? 'published' : 'verified']), 0.006,
                date('Y-m-d 16:00:00', strtotime($x['end'] . ' +4 days')), $verifiedAt);
            if ($group === 'published') {
                $pubAt = date('Y-m-d 11:30:00', strtotime($x['end'] . ' +8 days'));
                $publish($x, $verifiedAt, $pubAt, date('Y-m-d', strtotime($pubAt)));
            } else {
                db_update('exams', ['status' => 'verified', 'verified_at' => $verifiedAt, 'verified_by' => $examsUser, 'result_date' => date('Y-m-d', strtotime($today . ' +3 days'))], 'id = ?', [$x['id']]);
                exam_compute_results((int) $x['id']);
                $c = exam_result_counts((int) $x['id']);
                $log('approve', 'results', (int) $x['id'], 'Verified and locked the marks of "' . $x['name'] . '" — ' . $c['total'] . ' results computed', $verifiedAt);
            }
        } elseif ($group === 'marks') {
            // Some subjects submitted, one complete in draft, one partly entered, the rest not started
            $done = 2 + (($pid + $sem) % 2);
            $plan = [];
            foreach ($subjectIds as $i => $sid) {
                if ($i < $done) {
                    $plan[$sid] = ['fill' => 1, 'status' => 'submitted'];
                } elseif ($i === $done) {
                    $plan[$sid] = ['fill' => 1, 'status' => 'draft'];
                } elseif ($i === $done + 1) {
                    $plan[$sid] = ['fill' => 0.55, 'status' => 'draft'];
                }
                if (isset($facultyUserSubjects[$sid])) {
                    $plan[$sid] = ['fill' => 0.4, 'status' => 'draft']; // the demo faculty user's own paper is part-way through
                }
            }
            $enterMarks($x, $plan, 0.006, date('Y-m-d 15:40:00', strtotime($today . ' -1 day')));
            db_update('exams', ['status' => 'marks_entry', 'result_date' => date('Y-m-d', strtotime($today . ' +10 days'))], 'id = ?', [$x['id']]);
            foreach (array_slice($subjectIds, 0, $done + 2) as $i => $sid) {
                $s = $x['subject_rows'][$i];
                $submitted = $i < $done && !isset($facultyUserSubjects[$sid]);
                $log($submitted ? 'submit' : 'update', 'results', (int) $x['id'], ($submitted ? 'Submitted' : 'Entered') . ' marks of ' . $s['code'] . ' ' . $s['name'] . ' in "' . $x['name'] . '"',
                    date('Y-m-d 15:00:00', strtotime($today . ' -' . (4 - min(3, $i)) . ' days')));
            }
        } else {
            // Ongoing: attendance for papers already held, no marks yet
            $enterMarks($x, [], 0.006, $now);
            db_update('exams', ['result_date' => date('Y-m-d', strtotime($x['end'] . ' +14 days'))], 'id = ?', [$x['id']]);
        }
    }

    // ---- End Semester Examinations (upcoming)
    $calendar = db_value("SELECT MIN(holiday_date) FROM holidays WHERE type = 'exam_break' AND academic_session_id = ? AND title LIKE ? AND holiday_date >= ?",
        [$currentId, '%End-Semester%', date('Y-m-d', strtotime($today . ' +21 days'))]);
    $eseStart = $calendar ?: $mondayOnOrAfter(date('Y-m-d', strtotime($today . ' +49 days')));
    $overridesDone = 0;
    $debarDone = false;
    foreach ($cohorts as $key => $ids) {
        [$pid, $sem] = array_map('intval', explode('-', $key));
        if (!isset($programs[$pid])) {
            continue;
        }
        $draft = $pid === 10;                       // DCA: subjects assigned, date sheet pending
        $noStudents = in_array($pid, [11, 12], true); // certificate programs: scheduled, allocation pending
        $slot = $sem >= 5 ? ['14:00:00', '17:00:00'] : ['10:00:00', '13:00:00'];
        $tmp = ['id' => 0, 'academic_session_id' => $currentId, 'min_attendance_percent' => null, 'start_date' => $eseStart];
        $elig = [];
        if (!$draft && !$noStudents) {
            foreach (exam_eligibility($tmp, $ids) as $sid => $r) {
                $elig[$sid] = [$r['eligible'], $r['reason'], $r['attendance_percent'], $r['fee_due']];
            }
        }
        $x = $createExam(['type' => 'ESE', 'session_id' => $currentId, 'program_id' => $pid, 'semester' => $sem, 'start' => $eseStart, 'gap' => 2, 'slot' => $slot,
            'status' => $draft ? 'draft' : 'scheduled', 'min_att' => null, 'students' => ($draft || $noStudents) ? [] : $ids, 'eligibility' => $elig, 'headcount' => count($ids),
            'schedule' => !$draft, 'allocate' => !$draft && !$noStudents, 'result_date' => date('Y-m-d', strtotime($eseStart . ' +45 days')),
            'created_at' => date('Y-m-d 12:15:00', strtotime($today . ' -' . (6 + ($pid % 5)) . ' days'))]);
        if (!$x || $draft || $noStudents) {
            continue;
        }
        // Manual overrides: medical cases allowed (at most one per class), one disciplinary debarment
        $overrideHere = 0;
        foreach ($elig as $sid => [$ok, $reason, $att]) {
            if (!$ok && $overrideHere < 1 && $overridesDone < 5 && $att !== null && $att >= 60 && $att < 75 && mt_rand(1, 2) === 1) {
                $overrideHere++;
                $overridesDone++;
                $why = demo_pick(['Medical certificate for hospitalisation (dengue) verified by the HOD - shortage condoned.',
                    'Represented the institute at the inter-university sports meet - duty leave condoned by the Dean.',
                    'Bereavement in the family; condonation approved by the Principal.']);
                db_update('exam_students', ['is_eligible' => 1, 'is_override' => 1, 'override_reason' => $why, 'overridden_by' => $examsUser,
                    'overridden_at' => date('Y-m-d 11:00:00', strtotime($today . ' -2 days'))], 'exam_id = ? AND student_id = ?', [$x['id'], $sid]);
                $log('approve', 'examination', (int) $x['id'], 'Allowed a student for "' . $x['name'] . '" (override: ' . $why . ')', date('Y-m-d 11:00:00', strtotime($today . ' -2 days')));
            }
        }
        if (!$debarDone && $pid === 3 && $sem === 3) {
            $sid = $x['eligible'][count($x['eligible']) - 3] ?? null;
            if ($sid) {
                $debarDone = true;
                $why = 'Debarred by the examination committee for repeated misconduct (order EC/2026/014).';
                db_update('exam_students', ['is_eligible' => 0, 'ineligibility_reason' => $why, 'is_override' => 1, 'override_reason' => $why, 'overridden_by' => $examsUser,
                    'overridden_at' => date('Y-m-d 15:30:00', strtotime($today . ' -1 day')), 'seat_no' => null, 'classroom_id' => null], 'exam_id = ? AND student_id = ?', [$x['id'], $sid]);
            }
        }
        exam_assign_seats((int) $x['id']);
    }

    // ---- Practical examinations (draft, lab subjects only)
    foreach ([[3, 5], [5, 3]] as [$pid, $sem]) {
        if (empty($cohorts["$pid-$sem"])) {
            continue;
        }
        $createExam(['type' => 'PRAC', 'session_id' => $currentId, 'program_id' => $pid, 'semester' => $sem, 'start' => date('Y-m-d', strtotime($eseStart . ' -12 days')), 'gap' => 1,
            'slot' => ['09:30:00', '12:30:00'], 'status' => 'draft', 'min_att' => null, 'students' => [], 'schedule' => false, 'allocate' => false,
            'subject_filter' => fn ($s) => in_array($s['type'], ['lab', 'practical', 'project'], true),
            'instructions' => 'Bring your lab record duly signed by the course faculty. Viva voce follows each practical.',
            'created_at' => date('Y-m-d 09:40:00', strtotime($today . ' -1 day'))]);
    }

    // ---------------------------------------------------------------- sequences + timeline
    db_exec('INSERT INTO sequences (name, current_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE current_value = VALUES(current_value)', ['hall_ticket', $ht]);
    db_exec('INSERT INTO sequences (name, current_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE current_value = VALUES(current_value)', ['marksheet', $msNo]);
    demo_bulk_insert('activity_logs', $logs, 300);
};
