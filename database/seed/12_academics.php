<?php
/**
 * Demo seed (unit: academics): refines the academic structure created by 10_academics_people and builds a
 * realistic, conflict-free, published weekly timetable for every section of the current session (2026-27).
 *
 * Re-runnable and deterministic: it keeps every program/subject/section id, only UPDATEs owned rows, rebalances
 * faculty_subjects in place (same ids) and rebuilds `timetables` from scratch with a reset AUTO_INCREMENT so the
 * generated ids are stable between runs.
 *
 *   - electives + specialization (course) links for subjects, semester dates for the current session
 *   - varied classroom capacities and one distinct home room per section (largest sections -> largest rooms)
 *   - faculty workload balanced within each department (no one above the department average + 3 hrs)
 *   - timetable: Mon-Fri 7 periods + Saturday 4 periods, labs as double periods in labs, theory spread over
 *     different days, faculty/room/class never double-booked
 */
require_once __DIR__ . '/lib/demo_data.php';

return function (array $opts): void {
    demo_seed(26124);
    $sid = (int) db_value("SELECT id FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1") ?: (int) db_value("SELECT id FROM academic_sessions WHERE name = '2026-27'");
    if (!$sid) {
        return;
    }
    $adminId = db_value("SELECT id FROM users WHERE username = 'admin'") ?: null;

    // ---------------- Subjects: electives & specialization links ----------------
    $byName = function (string $program, int $sem, string $name) {
        return db_value('SELECT sb.id FROM subjects sb JOIN programs p ON p.id = sb.program_id WHERE p.code = ? AND sb.semester_no = ? AND sb.name = ?', [$program, $sem, $name]);
    };
    $electives = [
        ['BBA', 5, 'Investment Management', null], ['BBA', 5, 'Retail Management', 'BBA-GEN'], ['BBA', 6, 'Banking & Insurance', null], ['BBA', 4, 'E-Commerce', 'BBA-DB'],
        ['MBA', 3, 'Security Analysis & Portfolio Management', 'MBA-FIN'], ['MBA', 3, 'Digital & Social Media Marketing', 'MBA-MKT'], ['MBA', 3, 'Talent Management', 'MBA-HRM'],
        ['MBA', 3, 'Business Analytics with Python', 'MBA-BA'], ['MBA', 4, 'Corporate Finance', 'MBA-FIN'], ['MBA', 4, 'Brand Management', 'MBA-MKT'],
        ['BTCSE', 7, 'Blockchain Technology', null], ['BTCSE', 7, 'Cyber Security', 'CSE-CS'], ['BTCSE', 5, 'Cloud Computing', 'CSE-CC'], ['BTCSE', 6, 'DevOps', 'CSE-CC'],
        ['BTCSE', 8, 'Elective: Computer Vision', null], ['BTCSE', 8, 'Elective: Edge AI', null],
        ['BCA', 5, 'Mobile App Development', null], ['BCA', 6, 'E-Commerce Systems', null],
        ['BCOMH', 5, 'Investment Analysis', 'BCOM-AT'], ['BCOMH', 6, 'Fintech Fundamentals', null], ['BCOMH', 4, 'Goods & Services Tax', 'BCOM-AT'],
    ];
    foreach ($electives as [$prog, $sem, $name, $course]) {
        if ($id = $byName($prog, $sem, $name)) {
            $courseId = $course ? db_value('SELECT id FROM courses WHERE code = ?', [$course]) : null;
            db_exec('UPDATE subjects SET is_elective = 1, course_id = COALESCE(?, course_id) WHERE id = ?', [$courseId ?: null, $id]);
        }
    }
    db_exec("UPDATE subjects SET hours_per_week = 2 WHERE type IN ('lab','project') AND (hours_per_week IS NULL OR hours_per_week = 0)");
    db_exec("UPDATE subjects SET hours_per_week = 4 WHERE type = 'theory' AND (hours_per_week IS NULL OR hours_per_week = 0)");

    // ---------------- Semester dates for the current session ----------------
    $sessionStart = (int) substr((string) db_value('SELECT start_date FROM academic_sessions WHERE id = ?', [$sid]), 0, 4);
    db_exec('UPDATE semesters SET academic_session_id = ?, start_date = ?, end_date = ? WHERE MOD(number, 2) = 1', [$sid, $sessionStart . '-07-15', $sessionStart . '-12-19']);
    db_exec('UPDATE semesters SET academic_session_id = ?, start_date = ?, end_date = ? WHERE MOD(number, 2) = 0', [$sid, ($sessionStart + 1) . '-01-11', ($sessionStart + 1) . '-05-29']);

    // ---------------- Classrooms: realistic capacities ----------------
    $capByFloor = ['A' => [1 => 72, 2 => 66, 3 => 60], 'B' => [1 => 72, 2 => 66, 3 => 60], 'C' => [1 => 66, 2 => 60, 3 => 48]];
    foreach (db_all("SELECT id, code FROM classrooms WHERE type = 'classroom'") as $r) {
        if (preg_match('/^([ABC])([123])0\d$/', $r['code'], $m)) {
            db_exec('UPDATE classrooms SET capacity = ? WHERE id = ?', [$capByFloor[$m[1]][(int) $m[2]], $r['id']]);
        }
    }

    // ---------------- Sections: capacity + distinct home rooms ----------------
    $sections = db_all("SELECT sc.id, sc.program_id, sc.semester_no, sc.name, sc.capacity, p.code AS program_code, p.department_id, d.code AS dept_code,
                               (SELECT COUNT(*) FROM students s WHERE s.section_id = sc.id AND s.status = 'active') AS strength
                        FROM sections sc JOIN programs p ON p.id = sc.program_id JOIN departments d ON d.id = p.department_id
                        WHERE sc.academic_session_id = ? AND sc.status = 'active' ORDER BY sc.id", [$sid]);
    foreach ($sections as &$s) {
        $s['strength'] = (int) $s['strength'];
        if ($s['strength'] > (int) $s['capacity']) {
            $s['capacity'] = (int) (ceil($s['strength'] / 6) * 6);
            db_exec('UPDATE sections SET capacity = ? WHERE id = ?', [$s['capacity'], $s['id']]);
        }
    }
    unset($s);
    $classrooms = db_all("SELECT id, code, capacity, building FROM classrooms WHERE type = 'classroom' AND status = 'active' ORDER BY capacity DESC, code");
    $labs = db_all("SELECT id, code, capacity FROM classrooms WHERE type = 'lab' AND status = 'active' ORDER BY code");
    // preferred building per department
    $deptBlock = ['MGT' => 'Academic Block A', 'COM' => 'Academic Block A', 'CSE' => 'Technology Block', 'DCA' => 'Technology Block', 'SCI' => 'Academic Block B', 'PSD' => 'Academic Block B'];
    $order = $sections;
    usort($order, fn ($a, $b) => [$b['strength'], $a['id']] <=> [$a['strength'], $b['id']]);
    $used = [];
    $home = [];
    foreach ($order as $s) {
        if (in_array($s['program_code'], ['CDM', 'CDA'], true) && $labs) {
            // Certificate cohorts (digital marketing / analytics) learn in the computer labs.
            $lab = $s['program_code'] === 'CDM' ? $labs[min(1, count($labs) - 1)] : $labs[0];
            $home[$s['id']] = (int) $lab['id'];
            continue;
        }
        $pick = null;
        foreach ([true, false] as $sameBlock) {
            foreach ($classrooms as $r) {
                if (isset($used[$r['id']]) || (int) $r['capacity'] < $s['strength'] || ($sameBlock && $r['building'] !== ($deptBlock[$s['dept_code']] ?? null))) {
                    continue;
                }
                $pick = $r;
            }
            if ($pick) {
                break;
            }
        }
        $pick = $pick ?? null;
        if (!$pick) {
            foreach ($classrooms as $r) {
                if (!isset($used[$r['id']])) {
                    $pick = $r;
                    break;
                }
            }
        }
        if ($pick) {
            $used[$pick['id']] = true;
            $home[$s['id']] = (int) $pick['id'];
        }
    }
    foreach ($home as $secId => $roomId) {
        db_exec('UPDATE sections SET classroom_id = ? WHERE id = ?', [$roomId, $secId]);
    }

    // ---------------- Faculty workload rebalance (same rows, same ids) ----------------
    $hoursOf = fn (array $sb) => (int) ($sb['hours_per_week'] ?: (in_array($sb['type'], ['lab', 'project', 'practical'], true) ? 2 : 4));
    $assignRows = db_all("SELECT fs.id, fs.faculty_id, fs.subject_id, fs.section_id, sb.hours_per_week, sb.type, p.department_id
                          FROM faculty_subjects fs JOIN subjects sb ON sb.id = fs.subject_id JOIN programs p ON p.id = sb.program_id
                          WHERE fs.academic_session_id = ? ORDER BY fs.id", [$sid]);
    $facultyByDept = [];
    foreach (db_all("SELECT id, department_id, status FROM faculty WHERE department_id IS NOT NULL ORDER BY id") as $f) {
        if ($f['status'] === 'active') {
            $facultyByDept[(int) $f['department_id']][] = (int) $f['id'];
        }
    }
    $rowsByDept = [];
    foreach ($assignRows as $r) {
        $rowsByDept[(int) $r['department_id']][] = $r;
    }
    foreach ($rowsByDept as $deptId => $rows) {
        $pool = $facultyByDept[$deptId] ?? [];
        if (!$pool) {
            continue;
        }
        $total = array_sum(array_map($hoursOf, $rows));
        $cap = (int) ceil($total / count($pool)) + 3;
        $load = array_fill_keys($pool, 0);
        $pending = [];
        foreach ($rows as $r) {
            $f = (int) $r['faculty_id'];
            if (isset($load[$f]) && $load[$f] + $hoursOf($r) <= $cap) {
                $load[$f] += $hoursOf($r);
            } else {
                $pending[] = $r;
            }
        }
        foreach ($pending as $r) {
            asort($load);
            $f = (int) array_key_first($load);
            $load[$f] += $hoursOf($r);
            if ($f !== (int) $r['faculty_id']) {
                $dup = db_value('SELECT id FROM faculty_subjects WHERE faculty_id = ? AND subject_id = ? AND section_id <=> ? AND academic_session_id = ? AND id <> ?', [$f, $r['subject_id'], $r['section_id'], $sid, $r['id']]);
                if (!$dup) {
                    db_exec('UPDATE faculty_subjects SET faculty_id = ?, is_primary = 1 WHERE id = ?', [$f, $r['id']]);
                }
            }
        }
    }
    // Every (section, subject) of the session gets a teacher.
    foreach (db_all("SELECT sc.id AS section_id, sb.id AS subject_id, p.department_id FROM sections sc JOIN programs p ON p.id = sc.program_id
                     JOIN subjects sb ON sb.program_id = sc.program_id AND sb.semester_no = sc.semester_no AND sb.status = 'active'
                     WHERE sc.academic_session_id = ? AND sc.status = 'active'
                       AND NOT EXISTS (SELECT 1 FROM faculty_subjects fs WHERE fs.section_id = sc.id AND fs.subject_id = sb.id AND fs.academic_session_id = ?)
                     ORDER BY sc.id, sb.id", [$sid, $sid]) as $miss) {
        $pool = $facultyByDept[(int) $miss['department_id']] ?? [];
        if ($pool) {
            $f = (int) db_value('SELECT f.id FROM faculty f LEFT JOIN faculty_subjects fs ON fs.faculty_id = f.id AND fs.academic_session_id = ? WHERE f.id IN (' . implode(',', $pool) . ') GROUP BY f.id ORDER BY COUNT(fs.id), f.id LIMIT 1', [$sid]);
            db_exec('INSERT IGNORE INTO faculty_subjects (faculty_id, subject_id, section_id, academic_session_id, is_primary) VALUES (?, ?, ?, ?, 1)', [$f, $miss['subject_id'], $miss['section_id'], $sid]);
        }
    }

    // ---------------- Timetable generation ----------------
    db_exec('DELETE FROM timetables');
    db_exec('ALTER TABLE timetables AUTO_INCREMENT = 1');

    $allSlots = db_all("SELECT id, is_break FROM time_slots WHERE status = 'active' ORDER BY sort_order, start_time");
    $teaching = [];
    $pairs = [];
    foreach ($allSlots as $i => $sl) {
        if ((int) $sl['is_break']) {
            continue;
        }
        $teaching[] = (int) $sl['id'];
        $next = $allSlots[$i + 1] ?? null;
        if ($next && !(int) $next['is_break']) {
            $pairs[] = [(int) $sl['id'], (int) $next['id']];
        }
    }
    if (!$teaching) {
        return;
    }
    // Labs prefer afternoon double periods, then late morning, then early morning.
    $labPairs = $pairs;
    usort($labPairs, function ($a, $b) use ($teaching) {
        $pa = (int) array_search($a[0], $teaching, true);
        $pb = (int) array_search($b[0], $teaching, true);
        return [$pa >= 4 ? 0 : 1, $pa >= 4 ? $pa : -$pa] <=> [$pb >= 4 ? 0 : 1, $pb >= 4 ? $pb : -$pb];
    });
    $days = [1, 2, 3, 4, 5, 6];
    $saturdaySlots = array_slice($teaching, 0, 4);
    $allowed = fn (int $day, int $slot) => $day !== 6 || in_array($slot, $saturdaySlots, true);

    $secBusy = $facBusy = $roomBusy = [];
    $free = fn (array $map, $key, int $day, int $slot) => !isset($map[$key][$day][$slot]);
    $assignments = [];
    foreach (db_all('SELECT section_id, subject_id, faculty_id FROM faculty_subjects WHERE academic_session_id = ? ORDER BY is_primary DESC, id', [$sid]) as $a) {
        $assignments[(int) $a['section_id']][(int) $a['subject_id']] ??= (int) $a['faculty_id'];
    }
    $sectionInfo = [];
    foreach ($sections as $s) {
        $sectionInfo[(int) $s['id']] = $s + ['home' => $home[$s['id']] ?? null];
    }
    $requirements = [];
    foreach ($sections as $s) {
        foreach (db_all("SELECT id, code, name, type, hours_per_week FROM subjects WHERE program_id = ? AND semester_no = ? AND status = 'active' ORDER BY code", [$s['program_id'], $s['semester_no']]) as $sb) {
            $requirements[] = ['section' => (int) $s['id'], 'subject' => (int) $sb['id'], 'type' => $sb['type'], 'name' => $sb['name'], 'hours' => $hoursOf($sb),
                'faculty' => $assignments[(int) $s['id']][(int) $sb['id']] ?? null];
        }
    }
    // Labs first (double periods are hardest to place), then theory with the most constrained faculty first.
    $facLoad = [];
    foreach ($requirements as $r) {
        if ($r['faculty']) {
            $facLoad[$r['faculty']] = ($facLoad[$r['faculty']] ?? 0) + $r['hours'];
        }
    }
    foreach ($requirements as $i => $r) {
        $requirements[$i]['rank'] = ($r['type'] === 'lab' ? 0 : ($r['type'] === 'project' ? 2 : 1)) * 1000 - ($facLoad[$r['faculty']] ?? 0) * 10 + mt_rand(0, 9);
    }
    usort($requirements, fn ($a, $b) => [$a['rank'], $a['section'], $a['subject']] <=> [$b['rank'], $b['section'], $b['subject']]);

    $rows = [];
    $dayLoad = [];
    $missed = 0;
    $book = function (array $r, int $day, int $slot, ?int $room, string $type, ?string $notes) use (&$rows, &$secBusy, &$facBusy, &$roomBusy, &$dayLoad, $sid, $sectionInfo, $adminId) {
        $sec = $sectionInfo[$r['section']];
        $secBusy[$r['section']][$day][$slot] = true;
        if ($r['faculty']) {
            $facBusy[$r['faculty']][$day][$slot] = true;
        }
        if ($room) {
            $roomBusy[$room][$day][$slot] = true;
        }
        $dayLoad[$r['section']][$day] = ($dayLoad[$r['section']][$day] ?? 0) + 1;
        $rows[] = [
            'academic_session_id' => $sid, 'program_id' => (int) $sec['program_id'], 'semester_no' => (int) $sec['semester_no'], 'section_id' => $r['section'],
            'day_of_week' => $day, 'time_slot_id' => $slot, 'subject_id' => $r['subject'], 'faculty_id' => $r['faculty'], 'classroom_id' => $room,
            'type' => $type, 'notes' => $notes, 'status' => 'published', 'created_by' => $adminId,
        ];
    };
    $pickRoom = function (array $r, int $day, array $slotsNeeded, bool $lab) use (&$roomBusy, $sectionInfo, $classrooms, $labs) {
        $sec = $sectionInfo[$r['section']];
        $isFree = function (int $room) use (&$roomBusy, $day, $slotsNeeded) {
            foreach ($slotsNeeded as $sl) {
                if (isset($roomBusy[$room][$day][$sl])) {
                    return false;
                }
            }
            return true;
        };
        if ($lab) {
            foreach ($labs as $l) {
                if ((int) $l['capacity'] >= min(40, $sec['strength']) && $isFree((int) $l['id'])) {
                    return (int) $l['id'];
                }
            }
            return null;
        }
        if ($sec['home'] && $isFree((int) $sec['home'])) {
            return (int) $sec['home'];
        }
        foreach (array_reverse($classrooms) as $c) {
            if ((int) $c['capacity'] >= $sec['strength'] && $isFree((int) $c['id'])) {
                return (int) $c['id'];
            }
        }
        return null;
    };
    $orderedDays = function (int $section, array $exclude = []) use (&$dayLoad, $days) {
        $list = array_values(array_diff($days, $exclude));
        $keyed = [];
        foreach ($list as $d) {
            $keyed[] = [($dayLoad[$section][$d] ?? 0) + ($d === 6 ? 1 : 0), mt_rand(0, 99), $d];
        }
        sort($keyed);
        return array_map(fn ($k) => $k[2], $keyed);
    };

    foreach ($requirements as $r) {
        $sec = $r['section'];
        $fac = $r['faculty'];
        if ($r['type'] === 'lab') {
            $placed = false;
            foreach ($orderedDays($sec) as $day) {
                foreach ($labPairs as [$s1, $s2]) {
                    if (!$allowed($day, $s1) || !$allowed($day, $s2)) {
                        continue;
                    }
                    if (!$free($secBusy, $sec, $day, $s1) || !$free($secBusy, $sec, $day, $s2) || ($fac && (!$free($facBusy, $fac, $day, $s1) || !$free($facBusy, $fac, $day, $s2)))) {
                        continue;
                    }
                    $room = $pickRoom($r, $day, [$s1, $s2], true) ?? $pickRoom($r, $day, [$s1, $s2], false);
                    if (!$room) {
                        continue;
                    }
                    $book($r, $day, $s1, $room, 'lab', null);
                    $book($r, $day, $s2, $room, 'lab', null);
                    $placed = true;
                    break 2;
                }
            }
            $missed += $placed ? 0 : 2;
            continue;
        }
        $usedDays = [];
        for ($h = 0; $h < $r['hours']; $h++) {
            $placed = false;
            foreach ([true, false] as $distinctDay) {
                foreach ($orderedDays($sec, $distinctDay ? $usedDays : []) as $day) {
                    // mornings first for theory, with a small deterministic shuffle between neighbouring periods
                    $weight = [];
                    foreach ($teaching as $pos => $sl) {
                        $weight[$sl] = $pos + mt_rand(0, 25) / 10;
                    }
                    asort($weight);
                    foreach (array_keys($weight) as $slot) {
                        if (!$allowed($day, $slot) || !$free($secBusy, $sec, $day, $slot) || ($fac && !$free($facBusy, $fac, $day, $slot))) {
                            continue;
                        }
                        $room = $pickRoom($r, $day, [$slot], false);
                        if (!$room) {
                            continue;
                        }
                        $type = $r['type'] === 'project' ? (stripos($r['name'], 'Seminar') !== false ? 'seminar' : 'tutorial') : 'lecture';
                        $book($r, $day, $slot, $room, $type, $r['type'] === 'project' ? 'Project guidance / review' : null);
                        $usedDays[] = $day;
                        $placed = true;
                        break 3;
                    }
                }
            }
            if (!$placed) {
                $missed++;
            }
        }
    }
    // stable insert order: by section, day, slot
    usort($rows, fn ($a, $b) => [$a['section_id'], $a['day_of_week'], array_search($a['time_slot_id'], $teaching, true)] <=> [$b['section_id'], $b['day_of_week'], array_search($b['time_slot_id'], $teaching, true)]);
    demo_bulk_insert('timetables', $rows);
    if ($missed && PHP_SAPI === 'cli') {
        fwrite(STDOUT, "    (timetable: $missed periods could not be placed)\n");
    }
};
