<?php
/**
 * Demo data for the attendance unit (re-runnable, deterministic).
 *   - 2026-27 holiday calendar (holidays, vacations, exam breaks, events)
 *   - Student attendance for every current-session section over the last ~8 weeks of working days
 *     (Mon-Fri 4 periods, Saturday 2 periods; uses the timetable when one exists for the section/day,
 *     otherwise rotates the section's subjects through the first periods). Realistic ~85-92% with a few
 *     defaulters below the minimum attendance setting.
 *   - Faculty & staff daily attendance (in/out times, late, leave, half day) for the same period
 *   - A few biometric device punches for today (device integration demo)
 *
 * Owns: attendance, attendance_records, holidays, attendance_punches, attendance_summaries.
 */
return function (array $opts): void {
    require_once __DIR__ . '/lib/demo_data.php';
    require_once APP_ROOT . '/app/services/attendance.php';
    demo_seed(2126);

    // ---------------------------------------------------------------- clear owned tables (children first)
    db_exec('DELETE FROM attendance_punches');
    db_exec('DELETE FROM attendance_summaries');
    db_exec('DELETE FROM attendance_records');
    db_exec('DELETE FROM attendance');
    db_exec('DELETE FROM holidays');

    $session = db_row("SELECT * FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1")
        ?: db_row('SELECT * FROM academic_sessions ORDER BY start_date DESC LIMIT 1');
    if (!$session) {
        return;
    }
    $sessionId = (int) $session['id'];
    $y1 = (int) substr($session['start_date'], 0, 4);
    $y2 = $y1 + 1;

    // ---------------------------------------------------------------- holidays (2026-27 calendar)
    $holidays = [
        ["$y1-08-15", null, 'holiday', 'Independence Day', 'National holiday - flag hoisting ceremony at 8:30 AM on the main lawn.'],
        ["$y1-08-26", null, 'holiday', 'Milad-un-Nabi', 'Gazetted holiday.'],
        ["$y1-08-28", null, 'holiday', 'Raksha Bandhan', 'Gazetted holiday.'],
        ["$y1-09-04", null, 'holiday', 'Janmashtami', 'Gazetted holiday.'],
        ["$y1-09-14", null, 'holiday', 'Ganesh Chaturthi', 'Restricted holiday observed by the institute.'],
        ["$y1-09-25", "$y1-09-26", 'event', 'Techno-Vision ' . $y1 . ' (Annual Tech Fest)', 'Classes run as per timetable; participants are marked on duty leave.'],
        ["$y1-10-02", null, 'holiday', 'Gandhi Jayanti', 'National holiday.'],
        ["$y1-10-12", "$y1-10-17", 'exam_break', 'Mid-Semester Examinations', 'Regular classes suspended during mid-semester examinations.'],
        ["$y1-10-20", null, 'holiday', 'Dussehra (Vijayadashami)', 'Gazetted holiday.'],
        ["$y1-11-07", "$y1-11-11", 'vacation', 'Diwali Break', 'Deepawali, Govardhan Puja and Bhai Dooj.'],
        ["$y1-11-24", null, 'holiday', 'Guru Nanak Jayanti', 'Gazetted holiday.'],
        ["$y1-12-07", "$y1-12-19", 'exam_break', 'End-Semester Examinations (Odd Semester)', 'University end-semester theory and practical examinations.'],
        ["$y1-12-25", null, 'holiday', 'Christmas Day', 'Gazetted holiday.'],
        ["$y1-12-26", "$y2-01-03", 'vacation', 'Winter Vacation', 'Even semester classes commence on ' . date('d M Y', strtotime("$y2-01-04")) . '.'],
        ["$y2-01-14", null, 'holiday', 'Makar Sankranti / Lohri', 'Restricted holiday observed by the institute.'],
        ["$y2-01-26", null, 'holiday', 'Republic Day', 'National holiday - parade and cultural programme.'],
        ["$y2-02-05", "$y2-02-06", 'event', 'Spardha ' . $y2 . ' (Annual Sports Meet)', 'Inter-department sports meet. Attendance as per timetable.'],
        ["$y2-03-06", null, 'holiday', 'Maha Shivratri', 'Gazetted holiday.'],
        ["$y2-03-10", null, 'holiday', 'Eid-ul-Fitr', 'Gazetted holiday (subject to moon sighting).'],
        ["$y2-03-22", "$y2-03-23", 'holiday', 'Holi', 'Gazetted holiday.'],
        ["$y2-03-26", null, 'holiday', 'Good Friday', 'Gazetted holiday.'],
        ["$y2-04-14", null, 'holiday', 'Dr. B.R. Ambedkar Jayanti', 'Gazetted holiday.'],
        ["$y2-04-15", null, 'holiday', 'Ram Navami', 'Gazetted holiday.'],
        ["$y2-05-03", "$y2-05-22", 'exam_break', 'End-Semester Examinations (Even Semester)', 'University end-semester examinations.'],
        ["$y2-05-17", null, 'holiday', 'Eid al-Adha (Bakrid)', 'Gazetted holiday (subject to moon sighting).'],
        ["$y2-06-01", "$y2-06-30", 'vacation', 'Summer Vacation', 'Summer internships and training period.'],
    ];
    $hRows = [];
    foreach ($holidays as [$from, $to, $type, $title, $desc]) {
        $hRows[] = ['title' => $title, 'holiday_date' => $from, 'end_date' => $to, 'type' => $type, 'academic_session_id' => $sessionId, 'description' => $desc];
    }
    demo_bulk_insert('holidays', $hRows);

    // Dates on which classes are not held (holidays + vacations; exam breaks too - no regular classes)
    $closed = [];
    foreach ($holidays as [$from, $to, $type]) {
        if (!in_array($type, ['holiday', 'vacation', 'exam_break'], true)) {
            continue;
        }
        for ($d = strtotime($from); $d <= strtotime($to ?? $from); $d += 86400) {
            $closed[date('Y-m-d', $d)] = true;
        }
    }

    // ---------------------------------------------------------------- working days (last ~8 weeks)
    $today = date('Y-m-d');
    $end = min($today, $session['end_date']);
    $start = max(date('Y-m-d', strtotime($end . ' -55 days')), date('Y-m-d', strtotime($session['start_date'] . ' +14 days')));
    $days = [];
    for ($d = strtotime($start); $d <= strtotime($end); $d += 86400) {
        $date = date('Y-m-d', $d);
        $dow = (int) date('N', $d);
        if ($dow === 7 || isset($closed[$date])) {
            continue;
        }
        $days[] = $date;
    }
    if (!$days) {
        return;
    }

    $slots = db_all("SELECT id, name, start_time, end_time FROM time_slots WHERE is_break = 0 AND status = 'active' ORDER BY sort_order, start_time");
    if (!$slots) {
        return;
    }
    $slotById = [];
    foreach ($slots as $s) {
        $slotById[(int) $s['id']] = $s;
    }
    $adminId = (int) (db_value("SELECT id FROM users WHERE username = 'admin'") ?: db_value('SELECT MIN(id) FROM users'));
    $facultyUsers = db_pairs('SELECT faculty_id, id FROM users WHERE faculty_id IS NOT NULL');

    // ---------------------------------------------------------------- student attendance
    $sections = db_all("SELECT sc.*, p.short_name FROM sections sc JOIN programs p ON p.id = sc.program_id
                        WHERE sc.academic_session_id = ? AND sc.status = 'active' ORDER BY sc.id", [$sessionId]);
    $hasTimetable = (int) db_value('SELECT COUNT(*) FROM timetables WHERE academic_session_id = ?', [$sessionId]) > 0;

    foreach ($sections as $sec) {
        $secId = (int) $sec['id'];
        $students = db_column("SELECT id FROM students WHERE section_id = ? AND status = 'active' ORDER BY roll_no, id", [$secId]);
        $subjects = db_all("SELECT id FROM subjects WHERE program_id = ? AND semester_no = ? AND status = 'active' ORDER BY code", [$sec['program_id'], $sec['semester_no']]);
        if (!$students || !$subjects) {
            continue;
        }
        $subjectIds = array_map(fn ($r) => (int) $r['id'], $subjects);
        $facultyBySubject = db_pairs('SELECT subject_id, faculty_id FROM faculty_subjects WHERE section_id = ? ORDER BY is_primary DESC, id DESC', [$secId]);
        $tt = [];
        if ($hasTimetable) {
            foreach (db_all("SELECT t.id, t.day_of_week, t.time_slot_id, t.subject_id, t.faculty_id FROM timetables t JOIN time_slots ts ON ts.id = t.time_slot_id
                             WHERE t.section_id = ? AND t.academic_session_id = ? AND ts.is_break = 0 ORDER BY ts.sort_order", [$secId, $sessionId]) as $r) {
                $tt[(int) $r['day_of_week']][] = $r;
            }
        }

        // Student propensity: most attend regularly, a few are chronic absentees (defaulters)
        $profile = [];
        foreach ($students as $sid) {
            $roll = mt_rand(1, 1000);
            $profile[$sid] = $roll <= 38 ? mt_rand(52, 70) / 100 : ($roll <= 120 ? mt_rand(79, 86) / 100 : mt_rand(87, 98) / 100);
        }

        $sheets = [];
        $plan = [];
        foreach ($days as $di => $date) {
            $dow = (int) date('N', strtotime($date));
            $periods = [];
            if (!empty($tt[$dow])) {
                foreach ($tt[$dow] as $r) {
                    $periods[] = ['slot' => (int) $r['time_slot_id'], 'subject' => (int) $r['subject_id'], 'faculty' => $r['faculty_id'] ? (int) $r['faculty_id'] : null, 'timetable' => (int) $r['id']];
                }
            } else {
                $n = $dow === 6 ? 2 : 4;
                for ($k = 0; $k < $n && $k < count($slots); $k++) {
                    $subj = $subjectIds[($di * 4 + $k + $secId) % count($subjectIds)];
                    $periods[] = ['slot' => (int) $slots[$k]['id'], 'subject' => $subj, 'faculty' => isset($facultyBySubject[$subj]) ? (int) $facultyBySubject[$subj] : null, 'timetable' => null];
                }
            }
            if ($date === $today) {
                // Today: only the morning periods are marked so far, and not in every section yet
                if (mt_rand(1, 100) > 82) {
                    continue;
                }
                $periods = array_slice($periods, 0, 2);
            }
            foreach ($periods as $pr) {
                $slot = $slotById[$pr['slot']] ?? null;
                $created = $date . ' ' . ($slot ? date('H:i:s', strtotime($slot['start_time']) + mt_rand(5, 25) * 60) : '10:00:00');
                $key = 'student|' . $date . '|' . $secId . '|' . $pr['subject'] . '|' . $pr['slot'];
                $sheets[] = [
                    'type' => 'student', 'attendance_date' => $date, 'academic_session_id' => $sessionId, 'program_id' => $sec['program_id'],
                    'semester_no' => $sec['semester_no'], 'section_id' => $secId, 'subject_id' => $pr['subject'], 'timetable_id' => $pr['timetable'],
                    'time_slot_id' => $pr['slot'], 'faculty_id' => $pr['faculty'], 'sheet_key' => $key, 'method' => 'manual',
                    'taken_by' => $pr['faculty'] && isset($facultyUsers[$pr['faculty']]) ? (int) $facultyUsers[$pr['faculty']] : $adminId,
                    'remarks' => null, 'is_locked' => $date < date('Y-m-d', strtotime($today . ' -30 days')) ? 1 : 0, 'created_at' => $created, 'updated_at' => $created,
                ];
                $plan[$key] = $date;
            }
        }
        demo_bulk_insert('attendance', $sheets, 500);
        $ids = db_pairs('SELECT sheet_key, id FROM attendance WHERE section_id = ? AND type = ?', [$secId, 'student']);

        // Day-level behaviour per student (whole-day absence / leave), then per-period variation
        $records = [];
        $dayState = [];
        foreach ($plan as $key => $date) {
            $attId = (int) $ids[$key];
            foreach ($students as $sid) {
                $p = $profile[$sid];
                if (!isset($dayState[$date][$sid])) {
                    $r = mt_rand(1, 10000) / 10000;
                    $dayState[$date][$sid] = $r < (1 - $p) * 0.75 ? 'absent' : ($r < (1 - $p) * 0.75 + 0.012 ? 'leave' : 'in');
                }
                $state = $dayState[$date][$sid];
                if ($state === 'in') {
                    $r = mt_rand(1, 10000) / 10000;
                    $status = $r < (1 - $p) * 0.3 ? 'absent' : ($r < (1 - $p) * 0.3 + 0.035 ? 'late' : 'present');
                } else {
                    $status = $state;
                }
                $remarks = null;
                if ($status === 'leave') {
                    $remarks = demo_pick(['Medical leave', 'Family function', 'Sports duty', 'Approved leave', 'NCC camp']);
                } elseif ($status === 'late' && mt_rand(1, 3) === 1) {
                    $remarks = demo_pick(['Bus delayed', 'Came 10 min late', 'Traffic']);
                }
                $records[] = ['attendance_id' => $attId, 'person_type' => 'student', 'person_id' => (int) $sid, 'status' => $status, 'remarks' => $remarks];
            }
            if (count($records) >= 6000) {
                demo_bulk_insert('attendance_records', $records, 1500);
                $records = [];
            }
        }
        demo_bulk_insert('attendance_records', $records, 1500);
    }

    // ---------------------------------------------------------------- faculty & staff attendance
    $leaves = db_all("SELECT employee_type, employee_id, from_date, to_date FROM employee_leaves WHERE status = 'approved'");
    $onLeave = function (string $type, int $id, string $date) use ($leaves): bool {
        foreach ($leaves as $l) {
            if ($l['employee_type'] === $type && (int) $l['employee_id'] === $id && $date >= $l['from_date'] && $date <= $l['to_date']) {
                return true;
            }
        }
        return false;
    };
    $people = [
        'faculty' => db_all("SELECT id, status FROM faculty WHERE status IN ('active', 'on_leave') ORDER BY id"),
        'staff' => db_all("SELECT id, status FROM staff WHERE status IN ('active', 'on_leave') ORDER BY id"),
    ];
    $empDays = $days;
    $punches = [];
    foreach ($people as $type => $list) {
        if (!$list) {
            continue;
        }
        $reliability = [];
        foreach ($list as $pp) {
            $reliability[(int) $pp['id']] = mt_rand(1, 100) <= 12 ? mt_rand(80, 88) / 100 : mt_rand(92, 99) / 100;
        }
        $sheets = [];
        foreach ($empDays as $date) {
            $sheets[] = [
                'type' => $type, 'attendance_date' => $date, 'academic_session_id' => $sessionId, 'program_id' => null, 'semester_no' => null, 'section_id' => null,
                'subject_id' => null, 'timetable_id' => null, 'time_slot_id' => null, 'faculty_id' => null, 'sheet_key' => $type . '|' . $date . '|0|0|0',
                'method' => $type === 'staff' ? 'biometric' : 'manual', 'taken_by' => $adminId, 'remarks' => null,
                'is_locked' => $date < date('Y-m-d', strtotime($today . ' -30 days')) ? 1 : 0, 'created_at' => $date . ' 10:30:00', 'updated_at' => $date . ($date === $today ? ' 10:30:00' : ' 18:05:00'),
            ];
        }
        demo_bulk_insert('attendance', $sheets, 300);
        $ids = db_pairs('SELECT attendance_date, id FROM attendance WHERE type = ? AND section_id IS NULL', [$type]);
        $records = [];
        foreach ($empDays as $date) {
            $isToday = $date === $today;
            $isSat = (int) date('N', strtotime($date)) === 6;
            foreach ($list as $pp) {
                $pid = (int) $pp['id'];
                $rel = $reliability[$pid];
                $in = null;
                $out = null;
                $remarks = null;
                if (($pp['status'] === 'on_leave' && $date >= date('Y-m-d', strtotime($today . ' -12 days'))) || $onLeave($type, $pid, $date)) {
                    $status = 'leave';
                    $remarks = 'Approved leave';
                } else {
                    $r = mt_rand(1, 10000) / 10000;
                    $miss = 1 - $rel;
                    if ($r < $miss * 0.35) {
                        $status = 'absent';
                    } elseif ($r < $miss * 0.75) {
                        $status = 'leave';
                        $remarks = demo_pick(['Casual leave', 'Sick leave', 'Duty leave - external exam', 'Personal work']);
                    } elseif ($r < $miss * 0.75 + 0.045) {
                        $status = 'late';
                    } elseif ($r < $miss * 0.75 + 0.06) {
                        $status = 'half_day';
                        $remarks = 'Half day - personal work';
                    } else {
                        $status = 'present';
                    }
                    if ($status === 'present') {
                        $in = date('H:i:s', strtotime('08:35:00') + mt_rand(0, 38) * 60);
                    } elseif ($status === 'late') {
                        $in = date('H:i:s', strtotime('09:17:00') + mt_rand(0, 55) * 60);
                        $remarks = mt_rand(0, 1) ? 'Late arrival' : null;
                    } elseif ($status === 'half_day') {
                        $in = date('H:i:s', strtotime('08:45:00') + mt_rand(0, 20) * 60);
                        $out = date('H:i:s', strtotime('13:00:00') + mt_rand(0, 35) * 60);
                    }
                    if (in_array($status, ['present', 'late'], true) && !$isToday) {
                        $out = date('H:i:s', strtotime($isSat ? '13:30:00' : '16:35:00') + mt_rand(0, 70) * 60);
                    }
                }
                $records[] = ['attendance_id' => (int) $ids[$date], 'person_type' => $type, 'person_id' => $pid, 'status' => $status, 'in_time' => $in, 'out_time' => $out, 'remarks' => $remarks];
                if ($isToday && $type === 'staff' && $in) {
                    $punches[] = [
                        'device_id' => 'BIO-ADMIN-01', 'punch_key' => 'P' . str_replace('-', '', $date) . sprintf('%04d', $pid), 'method' => 'biometric',
                        'identifier' => (string) db_value('SELECT employee_id FROM staff WHERE id = ?', [$pid]), 'person_type' => 'staff', 'person_id' => $pid,
                        'punched_at' => $date . ' ' . $in, 'direction' => 'in', 'result' => 'recorded', 'attendance_id' => (int) $ids[$date],
                        'record_status' => $status, 'message' => 'Marked ' . $status . ' (in ' . substr($in, 0, 5) . ')', 'ip_address' => '10.10.4.21',
                        'created_at' => $date . ' ' . $in,
                    ];
                }
            }
        }
        demo_bulk_insert('attendance_records', $records, 1000);
    }
    usort($punches, fn ($a, $b) => strcmp($a['punched_at'], $b['punched_at']));
    demo_bulk_insert('attendance_punches', $punches, 200);

    // Per-sheet counts + per-student session summaries used by dashboards and reports
    att_rebuild_aggregates();
};
