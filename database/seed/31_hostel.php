<?php
/**
 * Demo data for the hostel unit (brief §20) — deterministic and re-runnable.
 *
 *   - 3 hostels: Aryabhatta Boys Hostel (2 blocks), C.V. Raman Boys Hostel (1 block, first-year), Kalpana Chawla Girls Hostel (2 blocks)
 *   - ~250 rooms (single / double / triple / four-seater, a few AC floors) with one row per bed; 3 rooms + a few beds under maintenance
 *   - ~420 active allocations of active students (gender-correct, cohort-wise roommates) + history: previous-session stays,
 *     room transfers, vacated and cancelled allotments (~480 rows)
 *   - hostel fee invoices (student_fees + student_fee_items with the "hostel" fee head) for current-session stays, most of them
 *     paid through payments / payment_allocations / payment_receipts (purpose = hostel), the rest pending or overdue; a few recent
 *     allotments are left un-invoiced
 *   - ~95 complaints over the last 80 days across the workflow (open → in progress → resolved → closed)
 *   - ~230 visitor register entries over the last 45 days, with visitors inside right now
 *
 * Owns: hostels, hostel_blocks, hostel_rooms, hostel_beds, hostel_allocations, hostel_complaints, hostel_visitors
 *       (+ its own sequences hostel_allocation:*, hostel_complaint:*, hostel_visitor:*).
 * Also writes, as the hostel feature requires: students.is_hosteller = 1 for residents, and the hostel fee invoices / payments it
 * creates (marked "Hostel fee — HST/…"), which are removed again before re-seeding.
 */
return function (array $opts): void {
    require_once __DIR__ . '/lib/demo_data.php';
    require_once APP_ROOT . '/app/services/hostel.php';
    demo_seed(3131);

    $now = time();
    $today = date('Y-m-d', $now);
    $session = db_row('SELECT * FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1') ?: db_row('SELECT * FROM academic_sessions ORDER BY start_date DESC LIMIT 1');
    if (!$session) {
        return;
    }
    $prev = db_row('SELECT * FROM academic_sessions WHERE start_date < ? ORDER BY start_date DESC LIMIT 1', [$session['start_date']]);
    $sessionStart = $session['start_date'];
    $sessionEnd = $session['end_date'];
    $daysAgo = fn (int $d): string => date('Y-m-d', strtotime("-$d days", $now));
    $clampPast = fn (string $d): string => $d > $today ? $today : $d;
    // Writes into tables shared with other units (student_fees, payments, students) can hit a deadlock / lock wait while other
    // processes work on them: retry the single statement (auto-commit, so nothing else is rolled back).
    $retry = function (callable $fn) {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $fn();
            } catch (PDOException $e) {
                $transient = $e->getCode() === '40001' || str_contains($e->getMessage(), 'Deadlock') || str_contains($e->getMessage(), 'Lock wait timeout');
                if (!$transient || $attempt >= 5 || db()->inTransaction()) {
                    throw $e;
                }
                usleep(150000 * $attempt);
            }
        }
    };

    // ---------------------------------------------------------------- clear previous demo data (children first)
    // hostel fee invoices raised by this unit (seeded or via the allocation screens) carry the remark "Hostel fee — HST/…"
    $feeIds = db_column("SELECT id FROM student_fees WHERE remarks LIKE 'Hostel fee — HST/%'");
    if ($feeIds) {
        $in = implode(',', array_map('intval', $feeIds));
        $retry(fn () => db_exec("DELETE FROM payments WHERE purpose = 'hostel' AND student_fee_id IN ($in)"));
        $retry(fn () => db_exec("UPDATE hostel_allocations SET student_fee_id = NULL WHERE student_fee_id IN ($in)"));
        $retry(fn () => db_exec("DELETE FROM student_fees WHERE id IN ($in)"));
    }
    db_exec('DELETE FROM hostel_visitors');
    db_exec('DELETE FROM hostel_complaints');
    db_exec('UPDATE hostel_allocations SET transferred_from_id = NULL');
    db_exec('DELETE FROM hostel_allocations');
    db_exec('DELETE FROM hostel_beds');
    db_exec('DELETE FROM hostel_rooms');
    db_exec('DELETE FROM hostel_blocks');
    db_exec('DELETE FROM hostels');
    db_exec("DELETE FROM sequences WHERE name LIKE 'hostel\\_allocation:%' OR name LIKE 'hostel\\_complaint:%' OR name LIKE 'hostel\\_visitor:%'");
    foreach (['hostel_visitors', 'hostel_complaints', 'hostel_allocations', 'hostel_beds', 'hostel_rooms', 'hostel_blocks', 'hostels'] as $t) {
        db_exec("ALTER TABLE $t AUTO_INCREMENT = 1");
    }

    // ---------------------------------------------------------------- people
    $staff = fn (string $designation) => db_row("SELECT id, first_name, last_name, phone FROM staff WHERE designation = ? ORDER BY status = 'active' DESC, id LIMIT 1", [$designation]);
    $boysWarden = $staff('Boys Hostel Warden');
    $girlsWarden = $staff('Girls Hostel Warden');
    $messSup = $staff('Mess Supervisor');
    $maintSup = $staff('Maintenance Supervisor');
    $electrician = $staff('Electrician');
    $securitySup = $staff('Security Supervisor');
    $itSupport = $staff('IT Support Engineer');
    $wardenUser = (int) (db_value("SELECT id FROM users WHERE username = 'hostel'") ?: 1);
    $accountsUser = (int) (db_value("SELECT id FROM users WHERE username = 'accounts'") ?: 1);
    $personName = fn (?array $s) => $s ? trim($s['first_name'] . ' ' . $s['last_name']) : null;

    // ---------------------------------------------------------------- hostels & blocks
    $common = ['wifi', 'mess', 'laundry', 'ro_water', 'hot_water', 'power_backup', 'cctv', 'housekeeping', 'medical'];
    $hostelDefs = [
        'ABH' => ['name' => 'Aryabhatta Boys Hostel', 'type' => 'boys', 'warden' => $boysWarden, 'floors' => 4, 'annual_fee' => 60000, 'mess_fee' => 42000,
            'email' => 'abh.hostel@gimt.ac.in', 'address' => 'North Campus, beside the Sports Complex, Knowledge Park III, Greater Noida',
            'amenities' => array_merge($common, ['gym', 'reading_room', 'common_room', 'indoor_games', 'parking']), 'image' => 'assets/images/site/campus-hostel.jpg',
            'description' => 'Four-storey boys hostel for second to final-year students with a 24x7 reading room, gymnasium and an air-conditioned wing in Block B.',
            'blocks' => [['Block A', 4, 'Senior wing — third and final-year students'], ['Block B', 4, 'Second-year wing next to the mess; AC rooms on the 3rd floor']],
            'per_floor' => 12],
        'CVR' => ['name' => 'C.V. Raman Boys Hostel', 'type' => 'boys', 'warden' => null, 'warden_name' => 'Rajesh Tomar', 'warden_phone' => '+91 9873402215', 'floors' => 5,
            'annual_fee' => 55000, 'mess_fee' => 42000, 'email' => 'cvr.hostel@gimt.ac.in', 'address' => 'East Campus, opposite the Academic Block, Knowledge Park III, Greater Noida',
            'amenities' => array_merge($common, ['common_room', 'indoor_games']), 'image' => 'assets/images/site/campus-building.jpg',
            'description' => 'First-year boys hostel with mentor seniors on every floor, a supervised study hall and four-seater rooms on the ground floor.',
            'blocks' => [['Block C', 5, 'First-year block with a supervised study hall']], 'per_floor' => 10],
        'KCG' => ['name' => 'Kalpana Chawla Girls Hostel', 'type' => 'girls', 'warden' => $girlsWarden, 'floors' => 4, 'annual_fee' => 62000, 'mess_fee' => 42000,
            'email' => 'kcg.hostel@gimt.ac.in', 'address' => 'South Campus, inside the secure residential zone, Knowledge Park III, Greater Noida',
            'amenities' => array_merge($common, ['gym', 'reading_room', 'common_room', 'indoor_games']), 'image' => 'assets/images/site/facility-hostel.jpg',
            'description' => 'Secure girls hostel with biometric entry, lady wardens and guards round the clock, a reading room and an AC wing on the 3rd floor of Block A.',
            'blocks' => [['Block A', 4, 'AC rooms on the 3rd floor; reading room on the ground floor'], ['Block B', 4, 'First and second-year students']], 'per_floor' => 13],
    ];
    $rentByType = ['single' => 85000, 'double' => 65000, 'triple' => 52000, 'quad' => 45000];
    $hostelIds = [];
    $blockIds = [];
    foreach ($hostelDefs as $code => $h) {
        $hostelIds[$code] = db_insert('hostels', [
            'name' => $h['name'], 'code' => $code, 'type' => $h['type'], 'warden_staff_id' => $h['warden']['id'] ?? null,
            'warden_name' => $h['warden'] ? $personName($h['warden']) : $h['warden_name'], 'warden_phone' => $h['warden']['phone'] ?? $h['warden_phone'],
            'email' => $h['email'], 'address' => $h['address'], 'total_floors' => $h['floors'], 'amenities' => json_encode($h['amenities']), 'description' => $h['description'],
            'visiting_hours' => '10:00 AM – 7:00 PM', 'annual_fee' => $h['annual_fee'], 'mess_fee' => $h['mess_fee'], 'image' => $h['image'], 'status' => 'active',
        ]);
        foreach ($h['blocks'] as [$bname, $floors, $desc]) {
            $blockIds[$code][$bname] = db_insert('hostel_blocks', ['hostel_id' => $hostelIds[$code], 'name' => $bname, 'floors' => $floors, 'description' => $desc, 'status' => 'active']);
        }
    }

    // ---------------------------------------------------------------- rooms & beds
    $maintenanceRooms = ['ABH' => ['A-012'], 'CVR' => ['C-410'], 'KCG' => ['B-007']];
    $rooms = [];   // id => [hostel code, room_no, capacity, fee, floor, block]
    foreach ($hostelDefs as $code => $h) {
        foreach ($h['blocks'] as [$bname, $floors]) {
            $letter = substr($bname, -1);
            for ($f = 0; $f < $floors; $f++) {
                for ($n = 1; $n <= $h['per_floor']; $n++) {
                    if ($code === 'CVR') {
                        $type = $f === 0 && $n <= 4 ? 'quad' : 'double';
                    } else {
                        $type = $n <= 2 ? 'single' : ($n <= ($code === 'KCG' ? 9 : 8) ? 'double' : 'triple');
                    }
                    $isAc = ($code === 'ABH' && $bname === 'Block B' && $f === 3) || ($code === 'KCG' && $bname === 'Block A' && $f === 3);
                    $fee = $rentByType[$type] + ($isAc ? 18000 : 0) + ($code === 'KCG' && $type !== 'quad' ? 2000 : 0) - ($code === 'CVR' ? 3000 : 0);
                    $amen = ['study_table', 'wardrobe', 'ceiling_fan', 'bookshelf'];
                    if ($type === 'single' || ($type === 'double' && $bname !== 'Block C')) {
                        $amen[] = 'attached_bath';
                    }
                    if ($isAc) {
                        $amen[] = 'ac';
                    } elseif ($f >= 2 && $code !== 'CVR') {
                        $amen[] = 'cooler';
                    }
                    if ($n === 1 || $n === $h['per_floor']) {
                        $amen[] = 'balcony';
                    }
                    if ($code !== 'CVR') {
                        $amen[] = 'lan';
                    }
                    if (in_array('attached_bath', $amen, true)) {
                        $amen[] = 'geyser';
                    }
                    $roomNo = $letter . '-' . $f . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
                    $cap = hostel_room_type_capacity($type);
                    $status = in_array($roomNo, $maintenanceRooms[$code], true) ? 'maintenance' : 'available';
                    $id = db_insert('hostel_rooms', ['hostel_id' => $hostelIds[$code], 'block_id' => $blockIds[$code][$bname], 'floor_no' => $f, 'room_no' => $roomNo, 'room_type' => $type,
                        'capacity' => $cap, 'occupied' => 0, 'is_ac' => $isAc ? 1 : 0, 'annual_fee' => $fee, 'amenities' => json_encode($amen), 'status' => $status]);
                    $rooms[$id] = ['code' => $code, 'room_no' => $roomNo, 'capacity' => $cap, 'fee' => $fee, 'floor' => $f, 'status' => $status];
                }
            }
        }
    }
    $bedRows = [];
    foreach ($rooms as $rid => $r) {
        for ($b = 1; $b <= $r['capacity']; $b++) {
            $bedRows[] = ['room_id' => $rid, 'bed_no' => (string) $b, 'status' => 'available'];
        }
    }
    demo_bulk_insert('hostel_beds', $bedRows);
    $beds = db_all('SELECT hb.id, hb.room_id, hb.bed_no FROM hostel_beds hb ORDER BY hb.room_id, hb.bed_no');
    $bedsByRoom = [];
    foreach ($beds as $b) {
        $bedsByRoom[(int) $b['room_id']][] = ['id' => (int) $b['id'], 'bed_no' => $b['bed_no']];
    }
    // a few beds under maintenance (broken cot / seepage) in otherwise usable rooms
    $maintenanceBeds = [];
    $roomIdsByHostel = [];
    foreach ($rooms as $rid => $r) {
        $roomIdsByHostel[$r['code']][] = $rid;
    }
    foreach (['ABH' => 2, 'CVR' => 1, 'KCG' => 2] as $code => $n) {
        for ($i = 0; $i < $n; $i++) {
            $rid = demo_pick($roomIdsByHostel[$code]);
            if ($rooms[$rid]['status'] !== 'available' || $rooms[$rid]['capacity'] < 2) {
                continue;
            }
            $bed = end($bedsByRoom[$rid]);
            $maintenanceBeds[$bed['id']] = true;
        }
    }
    if ($maintenanceBeds) {
        db_exec("UPDATE hostel_beds SET status = 'maintenance' WHERE id IN (" . implode(',', array_keys($maintenanceBeds)) . ')');
    }

    // Free-bed pools: rooms in random order, beds of a room together (cohort roommates) with ~20 % of beds held back for partly filled rooms.
    $pools = [];
    foreach ($roomIdsByHostel as $code => $rids) {
        shuffle($rids);
        $primary = [];
        $secondary = [];
        foreach ($rids as $rid) {
            if ($rooms[$rid]['status'] !== 'available') {
                continue;
            }
            foreach ($bedsByRoom[$rid] as $bed) {
                if (isset($maintenanceBeds[$bed['id']])) {
                    continue;
                }
                $entry = ['room_id' => $rid, 'bed_id' => $bed['id'], 'bed_no' => $bed['bed_no']];
                if (mt_rand(1, 100) <= 80) {
                    $primary[] = $entry;
                } else {
                    $secondary[] = $entry;
                }
            }
        }
        shuffle($secondary);
        $pool = array_merge($primary, $secondary);
        // keep ~10 % of the first-year block free for late admissions
        $pools[$code] = $code === 'CVR' ? array_slice($pool, 0, (int) floor(count($pool) * 0.9)) : $pool;
    }
    $allBedsByHostel = [];
    foreach ($roomIdsByHostel as $code => $rids) {
        foreach ($rids as $rid) {
            foreach ($bedsByRoom[$rid] as $bed) {
                $allBedsByHostel[$code][] = ['room_id' => $rid, 'bed_id' => $bed['id'], 'bed_no' => $bed['bed_no']];
            }
        }
    }

    // ---------------------------------------------------------------- residents
    $students = db_all("SELECT id, first_name, last_name, gender, current_semester, is_hosteller, program_id FROM students
                        WHERE status = 'active' AND gender IN ('male', 'female') ORDER BY current_semester, program_id, id");
    $residents = array_values(array_filter($students, fn ($s) => (int) $s['is_hosteller'] === 1 || ((int) $s['id'] * 37) % 100 < 9));
    $nonResidents = array_values(array_filter($students, fn ($s) => !((int) $s['is_hosteller'] === 1 || ((int) $s['id'] * 37) % 100 < 9)));
    $studentById = array_column($students, null, 'id');

    $alloc = [];   // rows to insert (key => row)
    $take = function (array $codes) use (&$pools): ?array {
        foreach ($codes as $code) {
            if (!empty($pools[$code])) {
                return ['code' => $code] + array_shift($pools[$code]);
            }
        }
        return null;
    };
    $sessionId = (int) $session['id'];
    foreach ($residents as $s) {
        $codes = $s['gender'] === 'female' ? ['KCG'] : ((int) $s['current_semester'] <= 2 ? ['CVR', 'ABH'] : ['ABH', 'CVR']);
        $spot = $take($codes);
        if (!$spot) {
            continue;
        }
        $recent = mt_rand(1, 100) <= 8;
        if ($recent) {
            $from = demo_date($daysAgo(28), $daysAgo(1));
        } elseif ((int) $s['current_semester'] <= 1) {
            $from = $clampPast(demo_date(date('Y-m-d', strtotime($sessionStart . ' +31 days')), date('Y-m-d', strtotime($sessionStart . ' +75 days'))));
        } else {
            $from = $clampPast(demo_date(date('Y-m-d', strtotime($sessionStart . ' +14 days')), date('Y-m-d', strtotime($sessionStart . ' +40 days'))));
        }
        $alloc[] = ['student' => $s, 'code' => $spot['code'], 'room_id' => $spot['room_id'], 'bed_id' => $spot['bed_id'], 'session_id' => $sessionId, 'allocated_on' => $from,
            'valid_until' => $sessionEnd, 'vacated_on' => null, 'status' => 'active', 'remarks' => null, 'vacate_reason' => null, 'prev_key' => null];
    }

    // Room transfers during this session: the earlier stay becomes "transferred", the current one points back to it.
    $transferReasons = ['Requested a ground-floor room on medical grounds', 'Roommate issue resolved by the warden', 'Shifted to a single room on request',
        'Moved closer to batchmates of the same program', 'Original room taken up for seepage repair', 'Requested an AC room (rent revised)'];
    $transferCandidates = array_keys(array_filter($alloc, fn ($a) => $a['allocated_on'] < $daysAgo(25)));
    shuffle($transferCandidates);
    $transfers = [];
    foreach (array_slice($transferCandidates, 0, 11) as $k) {
        $a = $alloc[$k];
        $old = demo_pick($allBedsByHostel[$a['code']]);
        if ($old['room_id'] === $a['room_id']) {
            continue;
        }
        $moveOn = demo_date(date('Y-m-d', strtotime($a['allocated_on'] . ' +12 days')), $daysAgo(3));
        if ($moveOn <= $a['allocated_on']) {
            continue;
        }
        $reason = demo_pick($transferReasons);
        $transfers[$k] = ['student' => $a['student'], 'code' => $a['code'], 'room_id' => $old['room_id'], 'bed_id' => $old['bed_id'], 'session_id' => $sessionId,
            'allocated_on' => $a['allocated_on'], 'valid_until' => $sessionEnd, 'vacated_on' => $moveOn, 'status' => 'transferred', 'remarks' => null,
            'vacate_reason' => 'Transferred: ' . $reason, 'prev_key' => null];
        $alloc[$k]['allocated_on'] = $moveOn;
        $alloc[$k]['remarks'] = 'Room change from ' . $a['code'] . ' ' . $rooms[$old['room_id']]['room_no'] . ': ' . $reason;
        $alloc[$k]['prev_key'] = $k;
    }

    // Vacated / cancelled stays of students who no longer live in the hostel (current session).
    shuffle($nonResidents);
    $vacateReasons = ['Shifted to day-scholar — family relocated to Noida', 'Moved to a private PG near the campus', 'Medical leave — went home for the semester',
        'Now staying with a local guardian in Greater Noida', 'Opted for a paying-guest accommodation with friends'];
    $history = [];
    foreach (array_slice($nonResidents, 0, 16) as $s) {
        $code = $s['gender'] === 'female' ? 'KCG' : demo_pick(['ABH', 'CVR']);
        $spot = demo_pick($allBedsByHostel[$code]);
        $from = $clampPast(demo_date(date('Y-m-d', strtotime($sessionStart . ' +14 days')), date('Y-m-d', strtotime($sessionStart . ' +45 days'))));
        $out = demo_date(date('Y-m-d', strtotime($from . ' +20 days')), $daysAgo(2));
        if ($out <= $from) {
            $out = $today;
        }
        $history[] = ['student' => $s, 'code' => $code, 'room_id' => $spot['room_id'], 'bed_id' => $spot['bed_id'], 'session_id' => $sessionId, 'allocated_on' => $from,
            'valid_until' => $sessionEnd, 'vacated_on' => $out, 'status' => 'vacated', 'remarks' => null, 'vacate_reason' => demo_pick($vacateReasons), 'prev_key' => null];
    }
    foreach (array_slice($nonResidents, 16, 5) as $s) {
        $code = $s['gender'] === 'female' ? 'KCG' : demo_pick(['ABH', 'CVR']);
        $spot = demo_pick($allBedsByHostel[$code]);
        $from = demo_date($daysAgo(40), $daysAgo(4));
        $history[] = ['student' => $s, 'code' => $code, 'room_id' => $spot['room_id'], 'bed_id' => $spot['bed_id'], 'session_id' => $sessionId, 'allocated_on' => $from,
            'valid_until' => $sessionEnd, 'vacated_on' => $from, 'status' => 'cancelled', 'remarks' => null,
            'vacate_reason' => 'Cancelled: ' . demo_pick(['Student opted out before check-in', 'Allotted by mistake — duplicate request', 'Parents chose a day-scholar arrangement']), 'prev_key' => null];
    }
    // Previous session: senior residents stayed last year too (room surrendered for the summer break).
    if ($prev) {
        $seniors = array_values(array_filter($alloc, fn ($a) => (int) $a['student']['current_semester'] >= 3));
        shuffle($seniors);
        foreach (array_slice($seniors, 0, 34) as $a) {
            $code = $a['student']['gender'] === 'female' ? 'KCG' : 'ABH';
            $spot = demo_pick($allBedsByHostel[$code]);
            $history[] = ['student' => $a['student'], 'code' => $code, 'room_id' => $spot['room_id'], 'bed_id' => $spot['bed_id'], 'session_id' => (int) $prev['id'],
                'allocated_on' => demo_date(date('Y-m-d', strtotime($prev['start_date'] . ' +14 days')), date('Y-m-d', strtotime($prev['start_date'] . ' +40 days'))),
                'valid_until' => $prev['end_date'], 'vacated_on' => demo_date(date('Y-m-d', strtotime($prev['end_date'] . ' -40 days')), date('Y-m-d', strtotime($prev['end_date'] . ' -10 days'))),
                'status' => 'vacated', 'remarks' => null, 'vacate_reason' => 'End of session — room surrendered for the summer vacation', 'prev_key' => null];
        }
    }

    // ---------------------------------------------------------------- insert allocations (numbered by date within each session)
    $all = [];
    foreach ($transfers as $k => $t) {
        $all[] = ['key' => 't' . $k] + $t;
    }
    foreach ($alloc as $k => $a) {
        $all[] = ['key' => 'a' . $k] + $a;
    }
    foreach ($history as $k => $h) {
        $all[] = ['key' => 'h' . $k] + $h;
    }
    usort($all, fn ($x, $y) => [$x['session_id'], $x['allocated_on'], $x['student']['id']] <=> [$y['session_id'], $y['allocated_on'], $y['student']['id']]);
    $seq = [];
    $sessionNames = db_pairs('SELECT id, name FROM academic_sessions');
    $insertedIds = [];
    $hostelFee = db_pairs('SELECT code, annual_fee FROM hostels');
    foreach ($all as $row) {
        $sname = $sessionNames[$row['session_id']] ?? $session['name'];
        $seq[$sname] = ($seq[$sname] ?? 0) + 1;
        $fee = $rooms[$row['room_id']]['fee'] ?? (float) $hostelFee[$row['code']];
        $created = $row['allocated_on'] . ' ' . sprintf('%02d:%02d:00', mt_rand(9, 17), mt_rand(0, 59));
        $insertedIds[$row['key']] = db_insert('hostel_allocations', [
            'allocation_no' => 'HST/' . $sname . '/' . str_pad((string) $seq[$sname], 5, '0', STR_PAD_LEFT),
            'student_id' => (int) $row['student']['id'], 'hostel_id' => $hostelIds[$row['code']], 'room_id' => $row['room_id'], 'bed_id' => $row['bed_id'],
            'transferred_from_id' => $row['prev_key'] !== null ? ($insertedIds['t' . $row['prev_key']] ?? null) : null,
            'academic_session_id' => $row['session_id'], 'allocated_on' => $row['allocated_on'], 'valid_until' => $row['valid_until'], 'vacated_on' => $row['vacated_on'],
            'fee_amount' => $fee, 'student_fee_id' => null, 'status' => $row['status'], 'remarks' => $row['remarks'], 'vacate_reason' => $row['vacate_reason'],
            'allocated_by' => $wardenUser, 'vacated_by' => $row['status'] === 'active' ? null : $wardenUser, 'created_at' => $created,
        ]);
    }
    foreach ($seq as $sname => $n) {
        db_exec('INSERT INTO sequences (name, current_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE current_value = VALUES(current_value)', ['hostel_allocation:' . $sname, $n]);
    }
    // Occupy beds of active stays, then recompute every room.
    $activeBeds = array_map(fn ($a) => (int) $a['bed_id'], array_values($alloc));
    if ($activeBeds) {
        db_exec("UPDATE hostel_beds SET status = 'occupied' WHERE id IN (" . implode(',', $activeBeds) . ')');
    }
    foreach (array_keys($rooms) as $rid) {
        hostel_sync_room((int) $rid);
    }
    $activeStudents = array_map(fn ($a) => (int) $a['student']['id'], array_values($alloc));
    if ($activeStudents) {
        $retry(fn () => db_exec('UPDATE students SET is_hosteller = 1 WHERE id IN (' . implode(',', $activeStudents) . ')'));
        // keep the flag consistent with the allocations (same rule as vacate / cancel): no active room = not a hosteller
        $retry(fn () => db_exec('UPDATE students SET is_hosteller = 0 WHERE is_hosteller = 1 AND id NOT IN (' . implode(',', $activeStudents) . ')'));
    }

    // ---------------------------------------------------------------- hostel fee invoices + payments (current session)
    $headId = hostel_fee_head_id();
    $invoices = 0;
    $payments = 0;
    if ($headId) {
        $messFee = db_pairs('SELECT id, mess_fee FROM hostels');
        $invoicePattern = (string) setting('invoice_prefix', 'INV/{session}/{n:6}');
        $receiptPattern = (string) setting('receipt_prefix', 'RCPT/{session}/{n:6}');
        $rows = db_all("SELECT ha.* FROM hostel_allocations ha WHERE ha.academic_session_id = ? AND ha.status IN ('active', 'vacated') AND ha.transferred_from_id IS NULL ORDER BY ha.allocated_on, ha.id", [$sessionId]);
        $chainOf = db_pairs('SELECT transferred_from_id, id FROM hostel_allocations WHERE transferred_from_id IS NOT NULL');
        // transferred stays: invoice raised for the original allotment, linked to the current one as well
        $originals = db_all("SELECT ha.* FROM hostel_allocations ha WHERE ha.status = 'transferred' AND ha.academic_session_id = ?", [$sessionId]);
        $modes = ['upi' => 40, 'bank_transfer' => 22, 'cash' => 14, 'card' => 12, 'cheque' => 8, 'online' => 4];
        $banks = ['State Bank of India', 'HDFC Bank', 'ICICI Bank', 'Punjab National Bank', 'Axis Bank', 'Bank of Baroda', 'Canara Bank'];
        foreach (array_merge($rows, $originals) as $a) {
            $age = (int) floor(($now - strtotime($a['allocated_on'])) / 86400);
            if ($a['status'] === 'active' && $age <= 12 && mt_rand(1, 100) <= 55) {
                continue; // recent allotment, hostel fee not invoiced yet
            }
            $student = $studentById[(int) $a['student_id']] ?? null;
            $existing = db_value("SELECT sf.id FROM student_fees sf JOIN student_fee_items i ON i.student_fee_id = sf.id JOIN fee_heads fh ON fh.id = i.fee_head_id
                                  WHERE sf.student_id = ? AND sf.academic_session_id = ? AND fh.type = 'hostel' AND sf.status <> 'cancelled' LIMIT 1", [(int) $a['student_id'], $sessionId]);
            if ($existing) {
                db_update('hostel_allocations', ['student_fee_id' => (int) $existing], 'id = ?', [(int) $a['id']]);
                continue;
            }
            $amount = round((float) $a['fee_amount'] + (float) ($messFee[$a['hostel_id']] ?? 0), 2);
            $due = date('Y-m-d', strtotime($a['allocated_on'] . ' +15 days'));
            $room = $rooms[(int) $a['room_id']] ?? null;
            // payment behaviour (decided up front so the invoice is written once with its final paid amount / status)
            $roll = mt_rand(1, 100);
            $paid = 0.0;
            if ($a['status'] === 'vacated' || $age > 45) {
                $paid = $roll <= 84 ? $amount : ($roll <= 92 ? round($amount * 0.5 / 1000) * 1000 : 0);
            } else {
                $paid = $roll <= 50 ? $amount : ($roll <= 62 ? round($amount * 0.5 / 1000) * 1000 : 0);
            }
            $status = $paid >= $amount ? 'paid' : ($due < $today ? 'overdue' : ($paid > 0 ? 'partial' : 'pending'));
            do {
                $invNo = next_number('invoice', $invoicePattern);
            } while (db_value('SELECT COUNT(*) FROM student_fees WHERE invoice_no = ?', [$invNo]));
            $fid = $retry(fn () => db_insert('student_fees', [
                'invoice_no' => $invNo, 'student_id' => (int) $a['student_id'], 'academic_session_id' => $sessionId,
                'title' => mb_substr('Hostel Fee ' . $session['name'] . ' — ' . ($room ? $room['code'] . ' Room ' . $room['room_no'] : 'Hostel'), 0, 190),
                'gross_amount' => $amount, 'discount_amount' => 0, 'scholarship_amount' => 0, 'fine_amount' => 0, 'net_amount' => $amount, 'paid_amount' => $paid,
                'due_date' => $due, 'status' => $status, 'remarks' => 'Hostel fee — ' . $a['allocation_no'], 'created_by' => $wardenUser,
                'created_at' => $a['allocated_on'] . ' 11:' . str_pad((string) mt_rand(0, 59), 2, '0', STR_PAD_LEFT) . ':00',
            ]));
            $retry(fn () => db_insert('student_fee_items', ['student_fee_id' => $fid, 'fee_head_id' => $headId, 'amount' => $amount]));
            $linkIds = [(int) $a['id']];
            if (isset($chainOf[$a['id']])) {
                $linkIds[] = (int) $chainOf[$a['id']];
            }
            $retry(fn () => db_exec('UPDATE hostel_allocations SET student_fee_id = ? WHERE id IN (' . implode(',', $linkIds) . ')', [$fid]));
            $invoices++;
            if ($paid > 0) {
                $payDate = demo_date(date('Y-m-d', strtotime($a['allocated_on'] . ' +1 day')), min($today, date('Y-m-d', strtotime($due . ' +12 days'))));
                $payDate = min($payDate, $today);
                $mode = demo_weighted($modes);
                $ref = match ($mode) {
                    'upi' => (string) mt_rand(100000000, 999999999) . mt_rand(100, 999), 'bank_transfer' => 'NEFT' . strtoupper(substr(md5((string) mt_rand()), 0, 10)),
                    'card' => 'AUTH' . mt_rand(100000, 999999), 'cheque' => (string) mt_rand(100000, 999999), 'online' => 'pay_' . substr(md5((string) mt_rand()), 0, 14), default => null,
                };
                do {
                    $rcpt = next_number('receipt', $receiptPattern);
                } while (db_value('SELECT COUNT(*) FROM payments WHERE receipt_no = ?', [$rcpt]));
                $stamp = $payDate . ' ' . sprintf('%02d:%02d:%02d', mt_rand(10, 16), mt_rand(0, 59), mt_rand(0, 59));
                $payRow = [
                    'receipt_no' => $rcpt, 'student_id' => (int) $a['student_id'], 'student_fee_id' => $fid, 'amount' => $paid, 'fine_amount' => 0, 'discount_amount' => 0,
                    'payment_date' => $payDate, 'mode' => $mode, 'reference_no' => $ref, 'bank_name' => in_array($mode, ['cheque', 'bank_transfer'], true) ? demo_pick($banks) : null,
                    'gateway' => $mode === 'online' ? 'razorpay' : null, 'gateway_txn_id' => $mode === 'online' ? $ref : null, 'purpose' => 'hostel', 'status' => 'success',
                    'remarks' => 'Hostel fee' . ($student ? ' — ' . trim($student['first_name'] . ' ' . $student['last_name']) : ''), 'collected_by' => $accountsUser, 'created_at' => $stamp,
                ];
                $pid = $retry(fn () => db_insert('payments', $payRow));
                $retry(fn () => db_insert('payment_allocations', ['payment_id' => $pid, 'student_fee_id' => $fid, 'amount' => $paid, 'fine_amount' => 0, 'created_at' => $stamp]));
                $retry(fn () => db_insert('payment_receipts', ['payment_id' => $pid, 'receipt_no' => $rcpt, 'issued_at' => $stamp]));
                $payments++;
            }
        }
    }

    // ---------------------------------------------------------------- complaints
    $residentsByHostel = db_all("SELECT ha.student_id, ha.hostel_id, ha.room_id, h.code, r.floor_no FROM hostel_allocations ha JOIN hostels h ON h.id = ha.hostel_id
                                 JOIN hostel_rooms r ON r.id = ha.room_id WHERE ha.status = 'active'");
    $byHostel = [];
    foreach ($residentsByHostel as $r) {
        $byHostel[$r['code']][] = $r;
    }
    $catalog = [
        'electrical' => [['Ceiling fan not working', 0], ['Tube light flickering in the room', 0], ['Power socket near the study table not working', 0], ['MCB trips every evening', 0],
            ['Corridor lights not working on floor {floor}', 1], ['Emergency light in the staircase not working', 1]],
        'plumbing' => [['Water leakage in the attached washroom', 0], ['Geyser not heating water', 0], ['Washroom tap leaking continuously', 0], ['Blocked drain in the common bathroom', 1],
            ['Low water pressure on the top floor', 1]],
        'maintenance' => [['Window glass cracked', 0], ['Door lock jammed', 0], ['Seepage on the wall after rain', 0], ['Cupboard hinge broken', 0], ['Damp patches — room needs repainting', 0]],
        'cleanliness' => [['Room not cleaned for three days', 0], ['Garbage not collected from the corridor', 1], ['Pest control needed — cockroaches in the room', 0],
            ['Common washroom not cleaned properly', 1]],
        'mess' => [['Food quality poor at dinner', 1], ['Breakfast served late on weekdays', 1], ['Need more variety in the mess menu', 1], ['Water cooler in the mess not cooling', 1],
            ['Chapatis served undercooked', 1]],
        'internet' => [['Wi-Fi not reachable in the room', 0], ['Very slow internet after 10 PM', 1], ['LAN port not working', 0], ['Wi-Fi login page not loading', 0]],
        'furniture' => [['Study chair broken', 0], ['Bed slats broken', 0], ['Study table drawer missing', 0]],
        'security' => [['Outsiders seen near the hostel gate late at night', 1], ['CCTV camera on floor {floor} not working', 1], ['Main gate visitor register not being maintained', 1]],
        'other' => [['Request for an extra mattress', 0], ['Laundry not returned on time', 1], ['Parcel missing from the hostel reception', 1]],
    ];
    $resolutions = [
        'electrical' => ['Electrician replaced the capacitor; fan working normally.', 'Faulty choke and tube replaced.', 'Socket board rewired and tested.', 'Loose connection in the DB fixed; MCB load balanced.'],
        'plumbing' => ['Plumber replaced the washer and sealed the joint.', 'Geyser thermostat replaced; hot water restored.', 'Drain line cleared with a pressure jet.', 'Overhead tank valve repaired; pressure normal.'],
        'maintenance' => ['Carpenter fixed the lock and oiled the hinges.', 'Glass pane replaced.', 'Wall treated with waterproofing; repainting scheduled during the break.', 'Hinge replaced.'],
        'cleanliness' => ['Housekeeping staff counselled; room cleaned the same day.', 'Pest control done on the entire floor.', 'Daily cleaning checklist introduced for common washrooms.'],
        'mess' => ['Discussed in the mess committee; menu revised from Monday.', 'Mess contractor warned; quality checks by the warden at dinner.', 'Cooler compressor repaired.'],
        'internet' => ['IT team replaced the access point on the floor.', 'Bandwidth upgraded for the hostel network after 9 PM.', 'LAN port re-crimped and tested.', 'Captive portal certificate renewed.'],
        'furniture' => ['Chair replaced from the store.', 'New bed slats fitted.', 'Drawer fixed by the carpenter.'],
        'security' => ['Security briefed; extra guard posted at the gate from 10 PM.', 'CCTV camera replaced and connected to the NVR.', 'Visitor register now verified by the warden daily.'],
        'other' => ['Extra mattress issued from the store.', 'Laundry vendor warned; turnaround fixed at 48 hours.', 'Reception log checked; parcel handed over to the student.'],
    ];
    $assigneeFor = function (string $cat, string $code) use ($electrician, $maintSup, $messSup, $securitySup, $itSupport, $boysWarden, $girlsWarden) {
        return match ($cat) {
            'electrical' => $electrician, 'plumbing', 'maintenance', 'furniture' => $maintSup, 'mess' => $messSup, 'security' => $securitySup, 'internet' => $itSupport ?: $maintSup,
            default => $code === 'KCG' ? $girlsWarden : $boysWarden,
        };
    };
    $catWeights = ['maintenance' => 16, 'electrical' => 16, 'plumbing' => 15, 'cleanliness' => 11, 'mess' => 12, 'internet' => 14, 'furniture' => 7, 'security' => 4, 'other' => 5];
    $complaintRows = [];
    for ($d = 80; $d >= 0; $d--) {
        $n = $d === 0 ? 3 : demo_weighted([0 => 22, 1 => 42, 2 => 26, 3 => 10]);
        for ($i = 0; $i < $n; $i++) {
            $code = demo_weighted(['ABH' => 40, 'CVR' => 25, 'KCG' => 35]);
            if (empty($byHostel[$code])) {
                continue;
            }
            $cat = demo_weighted($catWeights);
            [$subject, $isCommon] = demo_pick($catalog[$cat]);
            $res = demo_pick($byHostel[$code]);
            $subject = str_replace('{floor}', (string) max(1, (int) $res['floor_no']), $subject);
            $date = date('Y-m-d', strtotime("-$d days", $now));
            $created = $d === 0 ? date('Y-m-d H:i:s', $now - mt_rand(20, 300) * 60) : $date . ' ' . sprintf('%02d:%02d:%02d', mt_rand(7, 22), mt_rand(0, 59), mt_rand(0, 59));
            $ageH = ($now - strtotime($created)) / 3600;
            $priority = demo_weighted(['low' => 20, 'medium' => 45, 'high' => 25, 'urgent' => 10]);
            if ($ageH > 24 * 14) {
                $status = demo_weighted(['closed' => 55, 'resolved' => 41, 'in_progress' => 4]);
            } elseif ($ageH > 72) {
                $status = demo_weighted(['resolved' => 40, 'closed' => 10, 'in_progress' => 30, 'open' => 20]);
            } else {
                $status = demo_weighted(['open' => 55, 'in_progress' => 35, 'resolved' => 10]);
            }
            $assignee = $assigneeFor($cat, $code);
            $assigned = $assignee && ($status !== 'open' || mt_rand(1, 100) <= 45);
            $assignedAt = $assigned ? date('Y-m-d H:i:s', min($now - 60, strtotime($created) + mt_rand(15, 300) * 60)) : null;
            $resolvedAt = null;
            $closedAt = null;
            if (in_array($status, ['resolved', 'closed'], true)) {
                $resolvedTs = min($now - 120, strtotime($created) + mt_rand(2, $priority === 'urgent' ? 12 : 80) * 3600);
                $resolvedAt = date('Y-m-d H:i:s', max($resolvedTs, strtotime($created) + 1800));
                if ($status === 'closed') {
                    $closedTs = strtotime($resolvedAt) + mt_rand(12, 72) * 3600;
                    if ($closedTs < $now) {
                        $closedAt = date('Y-m-d H:i:s', $closedTs);
                    } else {
                        $status = 'resolved';
                    }
                }
            }
            $byStudent = !$isCommon || mt_rand(1, 100) <= 40;
            $complaintRows[] = [
                'complaint_no' => null, 'hostel_id' => (int) $res['hostel_id'], 'room_id' => $isCommon ? null : (int) $res['room_id'], 'student_id' => $byStudent ? (int) $res['student_id'] : null,
                'category' => $cat, 'subject' => $subject . ($isCommon ? '' : ''),
                'description' => $isCommon ? 'Reported at the warden office. Affects residents of ' . hostel_floor_label((int) $res['floor_no']) . ' and nearby rooms.'
                    : 'Raised by the resident. ' . demo_pick(['Issue started two days ago.', 'Problem since last night.', 'Already informed the floor attendant once.', 'Please attend at the earliest — exams are going on.']),
                'priority' => $priority, 'status' => $status, 'assigned_to' => $assigned ? mb_substr($personName($assignee) . ' (' . db_value('SELECT designation FROM staff WHERE id = ?', [(int) $assignee['id']]) . ')', 0, 150) : null,
                'assigned_staff_id' => $assigned ? (int) $assignee['id'] : null, 'assigned_at' => $assignedAt,
                'resolution' => $resolvedAt ? demo_pick($resolutions[$cat]) : null, 'resolved_at' => $resolvedAt, 'closed_at' => $closedAt, 'created_by' => $wardenUser,
                'created_at' => $created, 'updated_at' => $closedAt ?? $resolvedAt ?? $assignedAt,
            ];
        }
    }
    usort($complaintRows, fn ($a, $b) => strcmp($a['created_at'], $b['created_at']));
    foreach ($complaintRows as $i => &$c) {
        $c['complaint_no'] = 'HC/' . $session['name'] . '/' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
    }
    unset($c);
    demo_bulk_insert('hostel_complaints', $complaintRows);
    db_exec('INSERT INTO sequences (name, current_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE current_value = VALUES(current_value)', ['hostel_complaint:' . $session['name'], count($complaintRows)]);

    // ---------------------------------------------------------------- visitors
    $activeRes = db_all("SELECT ha.student_id, ha.hostel_id, s.last_name, s.gender FROM hostel_allocations ha JOIN students s ON s.id = ha.student_id WHERE ha.status = 'active'");
    $relWeights = ['Father' => 30, 'Mother' => 24, 'Brother' => 10, 'Sister' => 7, 'Guardian' => 9, 'Uncle' => 7, 'Aunt' => 4, 'Cousin' => 4, 'Grandparent' => 2, 'Friend' => 3];
    $purposes = ['Weekend visit', 'Dropping home-cooked food and winter clothes', 'Parent–warden meeting', 'Collecting documents for the scholarship form', 'Festival visit',
        'Taking the ward home for the weekend', 'Accompanying for a medical check-up', 'Dropping books and stationery', 'Fee-related discussion with the office', 'Birthday visit'];
    $proofs = ['aadhaar' => 60, 'driving_licence' => 20, 'voter_id' => 10, 'pan' => 6, 'passport' => 4];
    $plates = ['UP16', 'UP14', 'DL3C', 'DL8C', 'HR26', 'UP80', 'UP32', 'RJ14'];
    $makeVisitor = function (array $res, string $checkIn, ?string $checkOut, ?string $remarks = null) use ($relWeights, $purposes, $proofs, $plates, $wardenUser): array {
        $rel = demo_weighted($relWeights);
        $male = demo_first_names('male');
        $female = demo_first_names('female');
        $last = $res['last_name'] ?: demo_pick(demo_last_names());
        $name = match ($rel) {
            'Father', 'Uncle', 'Grandparent' => 'Mr. ' . demo_pick($male) . ' ' . $last,
            'Mother', 'Aunt' => 'Mrs. ' . demo_pick($female) . ' ' . $last,
            'Brother' => demo_pick($male) . ' ' . $last,
            'Sister' => demo_pick($female) . ' ' . $last,
            'Guardian' => 'Mr. ' . demo_pick($male) . ' ' . demo_pick(demo_last_names()),
            default => demo_pick(mt_rand(0, 1) ? $male : $female) . ' ' . demo_pick(demo_last_names()),
        };
        $hasVehicle = mt_rand(1, 100) <= 35;
        return ['pass_no' => null, 'hostel_id' => (int) $res['hostel_id'], 'student_id' => (int) $res['student_id'], 'visitor_name' => $name, 'relation' => $rel, 'phone' => demo_phone(),
            'id_proof_type' => demo_weighted($proofs), 'id_proof' => 'XXXX-' . str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT), 'purpose' => demo_pick($purposes),
            'visitors_count' => demo_weighted([1 => 60, 2 => 30, 3 => 10]),
            'vehicle_no' => $hasVehicle ? demo_pick($plates) . ' ' . chr(mt_rand(65, 90)) . chr(mt_rand(65, 90)) . ' ' . mt_rand(1000, 9999) : null,
            'check_in' => $checkIn, 'check_out' => $checkOut, 'remarks' => $remarks, 'approved_by' => $wardenUser, 'created_by' => $wardenUser, 'created_at' => $checkIn];
    };
    $visitorRows = [];
    if ($activeRes) {
        for ($d = 45; $d >= 1; $d--) {
            $ts = strtotime("-$d days", $now);
            $weekend = in_array((int) date('N', $ts), [6, 7], true);
            $n = $weekend ? mt_rand(7, 12) : mt_rand(1, 5);
            for ($i = 0; $i < $n; $i++) {
                $in = date('Y-m-d', $ts) . ' ' . sprintf('%02d:%02d:00', mt_rand(10, 17), mt_rand(0, 59));
                $out = date('Y-m-d H:i:s', strtotime($in) + mt_rand(25, 170) * 60);
                $visitorRows[] = $makeVisitor(demo_pick($activeRes), $in, $out, mt_rand(1, 100) <= 8 ? demo_pick(["Met in the visitors' lounge", 'Warden informed', 'Carried a parcel — checked at the gate']) : null);
            }
        }
        // today (anchored on the seeding time, inside the gate hours from 08:00): some checked out, some inside right now,
        // one long stay. Before the gate opens there are no visitors yet today.
        $gateOpen = strtotime($today . ' 08:00:00');
        $latest = $now - 10 * 60;
        if ($latest - $gateOpen >= 45 * 60) {
            for ($i = 0; $i < 4; $i++) {
                $inTs = mt_rand($gateOpen, $latest - 30 * 60);
                $outTs = max($inTs + 15 * 60, min($latest - mt_rand(0, 20) * 60, $inTs + mt_rand(25, 140) * 60));
                $visitorRows[] = $makeVisitor(demo_pick($activeRes), date('Y-m-d H:i:s', $inTs), date('Y-m-d H:i:s', $outTs));
            }
            for ($i = 0; $i < 4; $i++) {
                $inTs = max($gateOpen, $now - mt_rand(12, 150) * 60);
                $visitorRows[] = $makeVisitor(demo_pick($activeRes), date('Y-m-d H:i:s', min($inTs, $latest)), null);
            }
        } elseif ($latest > $gateOpen) {
            $visitorRows[] = $makeVisitor(demo_pick($activeRes), date('Y-m-d H:i:s', mt_rand($gateOpen, $latest)), null);
        }
        // a visitor inside for more than 6 hours (overstay alert): today when the day is long enough, otherwise a guardian who
        // stayed overnight in the guest room
        $longIn = $now - mt_rand(400, 460) * 60;
        if ($longIn >= $gateOpen) {
            $visitorRows[] = $makeVisitor(demo_pick($activeRes), date('Y-m-d H:i:s', $longIn), null, 'Waiting for the student to return from the hospital');
        } else {
            $visitorRows[] = $makeVisitor(demo_pick($activeRes), date('Y-m-d', strtotime('-1 day', $now)) . sprintf(' 18:%02d:00', mt_rand(5, 50)), null,
                'Stayed overnight in the hostel guest room — approved by the warden');
        }
    }
    usort($visitorRows, fn ($a, $b) => strcmp($a['check_in'], $b['check_in']));
    foreach ($visitorRows as $i => &$v) {
        $v['pass_no'] = 'VP/' . $session['name'] . '/' . str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT);
    }
    unset($v);
    demo_bulk_insert('hostel_visitors', $visitorRows);
    db_exec('INSERT INTO sequences (name, current_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE current_value = VALUES(current_value)', ['hostel_visitor:' . $session['name'], count($visitorRows)]);

    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, sprintf("    hostels %d · rooms %d · beds %d · allocations %d (active %d) · invoices %d · payments %d · complaints %d · visitors %d\n",
            count($hostelIds), count($rooms), count($bedRows), count($all), count($alloc), $invoices, $payments, count($complaintRows), count($visitorRows)));
    }
};
