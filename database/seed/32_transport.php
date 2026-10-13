<?php
/**
 * Demo data for the transport unit (re-runnable, deterministic for a given seed date).
 *   - 14 routes around Greater Noida / Noida / Ghaziabad / Delhi with 5-8 timed stops and distance-based fares
 *   - 36 drivers, conductors & attendants; 18 vehicles (16 on routes, a standby bus and the official car) with
 *     document expiries anchored on today's date (a few expired / due soon so the alerts are populated)
 *   - ~405 student + 26 faculty/staff allocations (within vehicle capacity), mid-session transfers and cancellations
 *   - 12 months of fuel history with odometer continuity (summer-vacation dip), periodic services, repairs, tyres,
 *     document renewals, scheduled jobs and one vehicle in the workshop
 *   - transport complaints across the open → in progress → resolved → closed workflow (with history)
 *
 * Owns: drivers, transport_routes, vehicles, transport_stops, transport_allocations, transport_fuel,
 *       transport_maintenance, transport_complaints.
 * Also (as the allocation feature does): sets students.uses_transport for allocated students, links allocations to
 * existing session invoices carrying the Transport fee head and raises transport invoices (fees_create_invoice) for
 * mid-session joiners — those unpaid invoices are removed again on re-run.
 */
return function (array $opts): void {
    require_once __DIR__ . '/lib/demo_data.php';
    require_once APP_ROOT . '/app/auth.php';
    require_once APP_ROOT . '/app/services/transport.php';
    demo_seed(3232);

    $today = date('Y-m-d');
    $ts = fn (string $d) => strtotime($d);
    $day = fn (int $offset) => date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days'));
    $roundTo = fn (float $v, int $step) => (int) (round($v / $step) * $step);
    $timeAdd = fn (string $hm, float $minutes) => date('H:i:s', strtotime('2000-01-01 ' . $hm) + (int) round($minutes) * 60);

    // ---------------------------------------------------------------- clear owned tables (children first)
    // Transport fee invoices raised by this unit (seeded or from the allocation screens) carry "Transport allocation #…";
    // they go together with the transport-fee payments recorded against them.
    $seedInvoices = array_map('intval', db_column("SELECT id FROM student_fees WHERE remarks LIKE 'Transport allocation #%'"));
    if ($seedInvoices) {
        $in = implode(',', $seedInvoices);
        $payIds = array_map('intval', db_column("SELECT DISTINCT p.id FROM payments p LEFT JOIN payment_allocations pa ON pa.payment_id = p.id
                                                 WHERE p.student_fee_id IN ($in) OR pa.student_fee_id IN ($in)"));
        foreach (array_chunk($payIds, 500) as $chunk) {
            $pin = implode(',', $chunk);
            db_exec("DELETE FROM refunds WHERE payment_id IN ($pin)");
            db_exec("DELETE FROM payments WHERE id IN ($pin)");
        }
    }
    db_exec('DELETE FROM transport_complaints');
    db_exec('DELETE FROM transport_maintenance');
    db_exec('DELETE FROM transport_fuel');
    db_exec('UPDATE transport_allocations SET transferred_from_id = NULL');
    db_exec('DELETE FROM transport_allocations');
    db_exec('DELETE FROM vehicles');
    db_exec('DELETE FROM transport_stops');
    db_exec('DELETE FROM transport_routes');
    db_exec('DELETE FROM drivers');
    foreach (array_chunk($seedInvoices, 500) as $chunk) {
        db_exec('DELETE FROM student_fees WHERE id IN (' . implode(',', $chunk) . ')');
    }
    db_exec("DELETE FROM activity_logs WHERE module = 'transport' AND meta LIKE '%\"seed\":\"transport\"%'");

    foreach (['transport_alert_days' => 30, 'transport_fee_due_days' => 15, 'transport_gps_enabled' => 0, 'transport_gps_provider' => '',
        'transport_gps_api_url' => '', 'transport_gps_refresh_seconds' => 30, 'transport_parent_alerts' => 0] as $k => $v) {
        if (db_value('SELECT COUNT(*) FROM settings WHERE `key` = ?', [$k]) == 0) {
            db_insert('settings', ['group' => 'transport', 'key' => $k, 'value' => (string) $v]);
        }
    }
    if (function_exists('settings_all')) {
        settings_all(true);
    }

    $session = db_row('SELECT * FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1') ?: db_row('SELECT * FROM academic_sessions ORDER BY start_date DESC LIMIT 1');
    $sessionId = $session ? (int) $session['id'] : null;
    $sessionStart = $session['start_date'] ?? date('Y') . '-07-01';
    $routeStart = date('Y-m-d', strtotime($sessionStart . ' +14 days'));
    $managerId = (int) (db_value("SELECT id FROM users WHERE email = 'transport@gimt.ac.in'") ?: db_value('SELECT id FROM users ORDER BY id LIMIT 1'));
    $adminId = (int) db_value('SELECT id FROM users ORDER BY id LIMIT 1');

    // ---------------------------------------------------------------- routes & stops
    $campus = [28.4590, 77.4980];
    $arrival = '08:40';
    $eveningDeparture = '16:15';
    // code, name, start point, [lat, lng], morning departure, [[stop, landmark, km from start], ...], total km
    $routeDefs = [
        ['R01', 'Noida Sector 62 – Knowledge Park', 'Sector 62, Noida', [28.6270, 77.3650], '07:20', 34.0, [
            ['Sector 62 – Fortis Hospital', 'Opp. Fortis main gate', 0], ['Sector 63 – Electronic City Metro', 'Metro gate 1', 2.5], ['Sector 71 Underpass', 'Near Kendriya Vidyalaya', 6.0],
            ['Sector 76 – Spectrum Metro Mall', 'Mall service lane', 8.5], ['Sector 50 Metro', 'Pillar 412', 10.5], ['Sector 110 – Expressway', 'Expressway service road', 16.0], ['Pari Chowk', 'Near Ansal Plaza', 30.0]]],
        ['R02', 'Noida Sector 18 – Knowledge Park', 'Sector 18, Noida', [28.5708, 77.3261], '07:10', 38.0, [
            ['Sector 18 – Atta Market', 'Near Centrestage Mall', 0], ['Film City – Sector 16A', 'Gate 2', 2.0], ['Botanical Garden Metro', 'Exit 3', 4.0], ['Sector 37 – Arun Vihar', 'Main gate', 5.5],
            ['Sector 44 – Amity School', 'Opp. school gate', 8.0], ['Sector 93 – Expressway', 'Near Capetown', 13.0], ['Sector 142 Metro', 'Advant Navis Park', 20.0], ['Knowledge Park I', 'Near Sharda Hospital', 33.0]]],
        ['R03', 'Noida Expressway – Sector 137', 'Okhla Bird Sanctuary, Noida', [28.5530, 77.3100], '07:35', 28.0, [
            ['Okhla Bird Sanctuary Metro', 'Exit 1', 0], ['Sector 128 – Wish Town', 'Kalypso Court gate', 4.0], ['Sector 135', 'Near IT park', 8.0], ['Sector 137 Metro', 'Paras Tierea', 10.5],
            ['Sector 143 Metro', 'Near Logix Infotech', 13.5], ['Sector 148 Metro', 'Near ATS Pristine', 16.5], ['Knowledge Park V', 'Galgotias Chowk', 24.0]]],
        ['R04', 'Raj Nagar Extension – Lal Kuan', 'Raj Nagar Extension, Ghaziabad', [28.7027, 77.4410], '06:55', 42.0, [
            ['Raj Nagar Extension – Charms Castle', 'Main gate', 0], ['Raj Nagar District Centre', 'Opp. RDC market', 4.5], ['Hindon Air Base Gate', 'Gate 3', 8.0],
            ['Vijay Nagar Bypass', 'Near Lal Quarter', 11.0], ['Lal Kuan', 'GT Road crossing', 17.0], ['Dadri – GT Road', 'Near Tehsil', 26.0], ['Kasna Chowk', 'Bus stand', 35.0]]],
        ['R05', 'Indirapuram – Vaishali', 'Kaushambi, Ghaziabad', [28.6460, 77.3220], '06:50', 40.0, [
            ['Kaushambi Metro', 'Exit 2', 0], ['Vaishali Metro', 'Mahagun Metro Mall', 2.0], ['Indirapuram – Shipra Mall', 'Main road', 5.0], ['Indirapuram Habitat Centre', 'Ahinsa Khand', 7.0],
            ['Shakti Khand 4', 'Near DPS Indirapuram', 8.5], ['Gaur Chowk (Ek Murti)', 'Roundabout', 21.0], ['Char Murti Chowk', 'Greater Noida West', 25.0]]],
        ['R06', 'Mayur Vihar – Pari Chowk', 'Mayur Vihar Phase-1, Delhi', [28.6040, 77.2890], '06:45', 45.0, [
            ['Mayur Vihar Phase-1 Metro', 'Exit 1', 0], ['Mayur Vihar Phase-3', 'Near Paper Market', 4.0], ['New Ashok Nagar Metro', 'Pillar 101', 6.0], ['Noida Sector 15 Metro', 'Gate 2', 8.5],
            ['Noida Sector 16 Metro', 'Near Gurudwara', 9.5], ['Mahamaya Flyover', 'Bus stop', 13.0], ['Pari Chowk', 'Near Ansal Plaza', 38.0]]],
        ['R07', 'Laxmi Nagar – Anand Vihar', 'Laxmi Nagar, Delhi', [28.6304, 77.2773], '06:30', 50.0, [
            ['Laxmi Nagar Metro', 'Vikas Marg', 0], ['Nirman Vihar Metro', 'V3S Mall', 1.5], ['Preet Vihar Metro', 'Exit 2', 3.0], ['Karkardooma Metro', 'Court gate', 5.0],
            ['Anand Vihar ISBT', 'Bus terminal gate 1', 7.0], ['Kaushambi Bus Depot', 'Opp. Pacific Mall', 8.5], ['Noida Sector 62 – NH 24', 'Near Fortis', 16.0]]],
        ['R08', 'Greater Noida West – Gaur City', 'Gaur City, Greater Noida West', [28.6050, 77.4270], '07:40', 22.0, [
            ['Gaur City – 1st Avenue', 'Gaur City Mall', 0], ['Gaur City – 7th Avenue', 'Near Galaxy Plaza', 1.5], ['Gaur Chowk (Ek Murti)', 'Roundabout', 3.0], ['Techzone IV', 'Near Ajnara Homes', 5.0],
            ['Sector 1 Bisrakh', 'Bisrakh police station', 7.0], ['Char Murti Chowk', 'Supertech Eco Village', 9.0], ['Surajpur', 'Surajpur court', 15.0]]],
        ['R09', 'Greater Noida – Alpha & Beta', 'Jagat Farm, Greater Noida', [28.4730, 77.5170], '08:00', 12.0, [
            ['Jagat Farm Market', 'Gamma-1', 0], ['Alpha-1 Commercial Belt', 'Near Omaxe Arcade', 1.8], ['Alpha-2 Metro', 'Pillar 18', 3.0], ['Pari Chowk Metro', 'Exit 2', 4.5],
            ['Beta-2', 'Near GIMS Hospital', 6.0], ['Delta-1', 'Near Delta market', 7.5], ['Knowledge Park III', 'University gate', 10.0]]],
        ['R10', 'Badarpur – Kalindi Kunj', 'Badarpur Border, Delhi', [28.4930, 77.3030], '06:40', 46.0, [
            ['Badarpur Border Metro', 'Exit 3', 0], ['Sarita Vihar Metro', 'Hospital side', 4.0], ['Jasola Apollo Metro', 'Exit 1', 6.0], ['Kalindi Kunj', 'Near Agrasen Park', 9.0],
            ['Sector 125 – Amity', 'Gate 1', 12.0], ['Sector 132', 'Expressway service road', 15.0], ['Sector 144', 'Near Advant tower', 20.0]]],
        ['R11', 'Crossings Republik – Dadri', 'Crossings Republik, Ghaziabad', [28.6300, 77.4370], '07:05', 32.0, [
            ['Crossings Republik', 'Main gate', 0], ['ABES Engineering College', 'NH 24', 3.5], ['Vijay Nagar', 'Bypass crossing', 7.0], ['Lal Kuan', 'GT Road crossing', 11.0],
            ['Dadri', 'Railway road', 20.0], ['Surajpur Bird Sanctuary', 'Main gate', 24.0], ['Kasna Chowk', 'Bus stand', 28.0]]],
        ['R12', 'Dilshad Garden – Shahdara', 'Dilshad Garden, Delhi', [28.6750, 77.3210], '06:25', 52.0, [
            ['Dilshad Garden Metro', 'Exit 2', 0], ['Jhilmil Metro', 'Jhilmil Colony', 1.5], ['Shahdara Metro', 'Exit 1', 3.5], ['Vivek Vihar', 'Near Ramprastha', 5.0],
            ['Anand Vihar ISBT', 'Gate 1', 8.0], ['Noida Sector 62 – NH 24', 'Near Fortis', 17.0]]],
        ['R13', 'Dadri – Surajpur', 'Dadri, Gautam Buddh Nagar', [28.5520, 77.5530], '07:45', 18.0, [
            ['Dadri Railway Station', 'Station road', 0], ['Dadri Tehsil Chowk', 'GT Road', 1.5], ['NTPC Dadri Gate', 'Main gate', 4.0], ['Surajpur Bird Sanctuary', 'Main gate', 9.0],
            ['Kasna Chowk', 'Bus stand', 12.0], ['Pari Chowk', 'Near Ansal Plaza', 15.0]]],
        ['R14', 'Noida Sector 50 – Sector 121 (Van)', 'Sector 50, Noida', [28.5720, 77.3620], '07:30', 26.0, [
            ['Sector 50 Metro', 'Pillar 412', 0], ['Sector 78 – Mahagun Moderne', 'Main gate', 3.0], ['Sector 76 – Amrapali Princely', 'Main gate', 4.0],
            ['Sector 120 – Amrapali Zodiac', 'Main gate', 6.0], ['Sector 121 – Cleo County', 'Main gate', 7.0]]],
    ];
    $routeIds = [];
    $stopsByRoute = [];
    $routeMeta = [];
    foreach ($routeDefs as [$code, $name, $startPoint, $coord, $depart, $total, $stops]) {
        $travel = (strtotime('2000-01-01 ' . $arrival) - strtotime('2000-01-01 ' . $depart)) / 60;
        $fares = [];
        foreach ($stops as $s) {
            $fares[] = $roundTo(max(16000, min(38000, 15000 + ($total - $s[2]) * 480)), 500);
        }
        $rid = db_insert('transport_routes', [
            'name' => $name, 'code' => $code, 'start_point' => $startPoint, 'end_point' => 'GIMT Campus, Knowledge Park II', 'distance_km' => $total,
            'annual_fare' => max($fares), 'morning_departure' => $depart . ':00', 'evening_departure' => $eveningDeparture . ':00', 'status' => 'active',
            'description' => count($stops) . ' pickup points; reaches campus by 8:40 AM.',
        ]);
        $routeIds[$code] = $rid;
        $routeMeta[$code] = ['id' => $rid, 'km' => $total, 'name' => $name];
        foreach ($stops as $i => [$sName, $landmark, $km]) {
            $f = $total > 0 ? $km / $total : 0;
            $sid = db_insert('transport_stops', [
                'route_id' => $rid, 'name' => $sName, 'landmark' => $landmark, 'sequence' => $i + 1,
                'pickup_time' => $timeAdd($depart, $travel * $f), 'drop_time' => $timeAdd($eveningDeparture, ($travel + 5) * (1 - $f)),
                'distance_km' => $km, 'annual_fare' => $fares[$i],
                'latitude' => round($coord[0] + ($campus[0] - $coord[0]) * $f + mt_rand(-40, 40) / 10000, 7),
                'longitude' => round($coord[1] + ($campus[1] - $coord[1]) * $f + mt_rand(-40, 40) / 10000, 7),
            ]);
            $stopsByRoute[$code][] = ['id' => $sid, 'fare' => $fares[$i], 'name' => $sName];
        }
    }

    // ---------------------------------------------------------------- drivers, conductors, attendants
    $driverNames = ['Ramesh Yadav', 'Suresh Kumar', 'Rajpal Singh', 'Mahesh Chand', 'Om Prakash', 'Satish Bhati', 'Devender Nagar', 'Rakesh Sharma', 'Ashok Kumar', 'Brijesh Tyagi',
        'Jagdish Prasad', 'Mukesh Pal', 'Naresh Chauhan', 'Pawan Kumar', 'Rajesh Bhati', 'Sanjay Gurjar', 'Vinod Kumar', 'Yogesh Sharma', 'Harpal Singh', 'Kuldeep Nagar'];
    $conductorNames = ['Amit Kumar', 'Deepak Yadav', 'Gaurav Bhati', 'Manoj Kumar', 'Sunil Sharma', 'Anil Kumar', 'Ravi Shankar', 'Sonu Gurjar', 'Pradeep Singh', 'Ajay Tyagi', 'Rohit Pal', 'Vikas Kumar'];
    $helperNames = ['Sunita Devi', 'Kamlesh Kumari', 'Rekha Rani', 'Poonam Sharma'];
    $villages = ['Kasna', 'Surajpur', 'Dadri', 'Bisrakh', 'Tugalpur', 'Ghodi Bachheda', 'Jalpura', 'Chhapraula', 'Dankaur', 'Sirsa', 'Rabupura', 'Bilaspur'];
    $drivers = [];
    $conductors = [];
    $helpers = [];
    foreach ($driverNames as $i => $n) {
        $year = mt_rand(2004, 2016);
        $expiry = $day(mt_rand(320, 1900));
        if ($i === 4) {
            $expiry = $day(13);
        } elseif ($i === 11) {
            $expiry = $day(24);
        } elseif ($i === 19) {
            $expiry = $day(-14);
        }
        $drivers[] = db_insert('drivers', [
            'name' => $n, 'phone' => demo_phone(), 'emergency_phone' => demo_phone(), 'license_no' => sprintf('UP16 %d%07d', $year, mt_rand(1000, 9999999)),
            'license_type' => $i === 17 ? 'LMV' : demo_pick(['HMV', 'HPMV', 'HMV']), 'license_expiry' => $expiry,
            'address' => 'Village ' . demo_pick($villages) . ', Greater Noida, UP', 'photo' => null, 'experience_years' => min(30, (int) date('Y') - $year + mt_rand(0, 3)),
            'joining_date' => demo_date('2012-04-01', '2025-06-30'), 'police_verified' => in_array($i, [15, 18], true) ? 0 : 1, 'type' => 'driver', 'status' => 'active',
            'remarks' => $i >= 18 ? 'Relief driver' : null,
        ]);
    }
    foreach ($conductorNames as $i => $n) {
        $conductors[] = db_insert('drivers', [
            'name' => $n, 'phone' => demo_phone(), 'emergency_phone' => demo_phone(), 'license_no' => sprintf('UP16C/%d/%04d', mt_rand(2012, 2022), mt_rand(100, 9999)),
            'license_type' => 'conductor', 'license_expiry' => $i === 6 ? $day(19) : $day(mt_rand(200, 1500)), 'address' => 'Village ' . demo_pick($villages) . ', Greater Noida, UP',
            'photo' => null, 'experience_years' => mt_rand(2, 14), 'joining_date' => demo_date('2015-04-01', '2025-06-30'), 'police_verified' => $i === 9 ? 0 : 1,
            'type' => 'conductor', 'status' => 'active', 'remarks' => null,
        ]);
    }
    foreach ($helperNames as $i => $n) {
        $helpers[] = db_insert('drivers', [
            'name' => $n, 'phone' => demo_phone(), 'emergency_phone' => demo_phone(), 'license_no' => sprintf('GIMT/ATT/%03d', 11 + $i), 'license_type' => 'badge',
            'license_expiry' => $day(mt_rand(250, 700)), 'address' => 'Village ' . demo_pick($villages) . ', Greater Noida, UP', 'photo' => null, 'experience_years' => mt_rand(1, 8),
            'joining_date' => demo_date('2018-04-01', '2025-06-30'), 'police_verified' => 1, 'type' => 'helper', 'status' => 'active', 'remarks' => 'Lady attendant',
        ]);
    }

    // ---------------------------------------------------------------- vehicles
    // no, type, make, model, year, capacity, fuel, route, crew (c = conductor, h = helper), status
    $vehicleDefs = [
        ['UP16 AT 4521', 'bus', 'Tata Motors', 'Starbus Ultra 52', 2021, 52, 'diesel', 'R01', 'c', 'active'],
        ['UP16 AT 4522', 'bus', 'Tata Motors', 'Starbus Ultra 52', 2021, 52, 'diesel', 'R02', 'c', 'active'],
        ['UP16 BT 1187', 'mini_bus', 'Eicher', 'Skyline Pro 3008', 2022, 32, 'cng', 'R02', 'h', 'active'],
        ['UP16 AZ 7310', 'bus', 'Ashok Leyland', 'Lynx Smart 42', 2020, 42, 'diesel', 'R03', 'c', 'active'],
        ['UP14 FT 2264', 'bus', 'Ashok Leyland', 'Viking 52', 2019, 52, 'diesel', 'R04', 'c', 'active'],
        ['UP14 GT 9081', 'bus', 'Eicher', 'Skyline Pro 42', 2022, 42, 'diesel', 'R05', 'c', 'active'],
        ['DL 1PC 4417', 'bus', 'Tata Motors', 'Starbus CNG 52', 2020, 52, 'cng', 'R06', 'c', 'active'],
        ['DL 1PD 2093', 'bus', 'Tata Motors', 'Starbus CNG 42', 2022, 42, 'cng', 'R07', 'c', 'active'],
        ['UP16 CT 5512', 'bus', 'Eicher', 'Skyline Pro 42', 2023, 42, 'diesel', 'R08', 'c', 'active'],
        ['UP16 BZ 3021', 'bus', 'SML Isuzu', 'Executive LX 42', 2019, 42, 'diesel', 'R09', 'c', 'active'],
        ['DL 1PC 7765', 'bus', 'Ashok Leyland', 'Lynx CNG 52', 2021, 52, 'cng', 'R10', 'c', 'active'],
        ['UP14 HT 4410', 'bus', 'Tata Motors', 'Starbus 42', 2020, 42, 'diesel', 'R11', 'c', 'active'],
        ['DL 1PD 5530', 'bus', 'Eicher', 'Skyline CNG 52', 2023, 52, 'cng', 'R12', 'c', 'active'],
        ['UP16 DT 0921', 'mini_bus', 'Eicher', 'Skyline Pro 2075', 2021, 32, 'diesel', 'R13', 'h', 'active'],
        ['UP16 ET 6608', 'van', 'Force Motors', 'Traveller 3350 (17 seats)', 2022, 17, 'diesel', 'R14', 'h', 'active'],
        ['UP16 CT 5513', 'mini_bus', 'Eicher', 'Skyline Pro 3008', 2018, 32, 'diesel', 'R08', 'h', 'maintenance'],
        ['UP16 AT 9090', 'bus', 'Tata Motors', 'Starbus 52', 2017, 52, 'diesel', null, null, 'active'],
        ['UP16 DY 1234', 'car', 'Toyota', 'Innova Crysta', 2022, 7, 'diesel', null, null, 'active'],
    ];
    // document offsets (days from today): [insurance, fitness, permit, puc]
    $docOffsets = [
        0 => [212, 300, 980, 96], 1 => [12, 318, 960, 140], 2 => [260, 410, 1100, 22], 3 => [180, 75, 870, 64], 4 => [24, 150, 27, 118], 5 => [330, 520, 1200, 160],
        6 => [140, 18, 700, 9], 7 => [295, 610, 1300, 88], 8 => [355, 690, 1500, 171], 9 => [65, 240, 640, -3], 10 => [190, 380, 910, 130], 11 => [102, 260, 780, 47],
        12 => [345, 720, 1600, 176], 13 => [228, 330, 1010, 15], 14 => [160, 405, 1150, 101], 15 => [88, -10, 560, 54], 16 => [-5, 120, 420, 33], 17 => [275, 1095, 1700, 150],
    ];
    $gpsDevices = [0 => '866104023456781', 1 => '866104023456799', 3 => '866104023457102', 6 => '866104023457380', 8 => '866104023457415', 12 => '866104023457622'];
    $vehicles = [];
    $ci = 0;
    $hi = 0;
    foreach ($vehicleDefs as $i => [$no, $type, $make, $model, $year, $cap, $fuel, $route, $crew, $status]) {
        $o = $docOffsets[$i];
        $conductorId = null;
        if ($crew === 'c') {
            $conductorId = $conductors[$ci++] ?? null;
        } elseif ($crew === 'h') {
            $conductorId = $helpers[$hi++] ?? null;
        }
        $id = db_insert('vehicles', [
            'vehicle_no' => $no, 'type' => $type, 'make' => $make, 'model' => $model, 'manufacture_year' => $year, 'capacity' => $cap, 'fuel_type' => $fuel,
            'driver_id' => $drivers[$i] ?? null, 'conductor_id' => $conductorId, 'route_id' => $route ? $routeIds[$route] : null,
            'registration_date' => sprintf('%d-%02d-%02d', $year, mt_rand(1, 12), mt_rand(1, 28)), 'insurance_expiry' => $day($o[0]), 'fitness_expiry' => $day($o[1]),
            'permit_expiry' => $day($o[2]), 'pollution_expiry' => $day($o[3]), 'gps_device_id' => $gpsDevices[$i] ?? null, 'current_odometer' => null,
            'remarks' => $route === null ? ($type === 'car' ? 'Official car — Director & guest pickups' : 'Standby bus for breakdowns, trips and events') : null, 'status' => $status,
        ]);
        $vehicles[$i] = ['id' => $id, 'no' => $no, 'type' => $type, 'fuel' => $fuel, 'route' => $route, 'cap' => $cap, 'year' => $year, 'status' => $status, 'docs' => $o, 'model' => $model];
    }
    $vehicleIdsByRoute = [];
    foreach ($vehicles as $v) {
        if ($v['route']) {
            $vehicleIdsByRoute[$v['route']][] = $v['id'];
        }
    }

    // ---------------------------------------------------------------- allocations
    $targets = ['R01' => 40, 'R02' => 76, 'R03' => 26, 'R04' => 30, 'R05' => 24, 'R06' => 28, 'R07' => 20, 'R08' => 50, 'R09' => 38, 'R10' => 24, 'R11' => 22, 'R12' => 16, 'R13' => 20, 'R14' => 17];
    $cityRoutes = ['Delhi' => ['R06', 'R07', 'R10', 'R12'], 'Noida' => ['R01', 'R02', 'R03', 'R14'], 'Ghaziabad' => ['R04', 'R05', 'R11'], 'Greater Noida' => ['R08', 'R09', 'R13']];
    $remaining = $targets;
    $vehicleLoad = [];
    $pickRoute = function (?string $city) use (&$remaining, $cityRoutes): ?string {
        $prefer = array_values(array_filter($cityRoutes[$city] ?? [], fn ($r) => $remaining[$r] > 0));
        if ($prefer && mt_rand(1, 100) <= 85) {
            usort($prefer, fn ($a, $b) => $remaining[$b] <=> $remaining[$a]);
            return mt_rand(1, 100) <= 70 ? $prefer[0] : demo_pick($prefer);
        }
        $any = array_keys(array_filter($remaining, fn ($n) => $n > 0));
        if (!$any) {
            return null;
        }
        usort($any, fn ($a, $b) => $remaining[$b] <=> $remaining[$a]);
        return mt_rand(1, 100) <= 60 ? $any[0] : demo_pick(array_slice($any, 0, 5));
    };
    $pickVehicle = function (string $route) use (&$vehicleLoad, $vehicleIdsByRoute, $vehicles): ?int {
        $best = null;
        $bestFree = -1;
        foreach ($vehicleIdsByRoute[$route] ?? [] as $vid) {
            $cap = 0;
            foreach ($vehicles as $v) {
                if ($v['id'] === $vid) {
                    $cap = $v['cap'];
                }
            }
            $free = $cap - ($vehicleLoad[$vid] ?? 0);
            if ($free > $bestFree) {
                $best = $vid;
                $bestFree = $free;
            }
        }
        if ($best) {
            $vehicleLoad[$best] = ($vehicleLoad[$best] ?? 0) + 1;
        }
        return $best;
    };
    $stopPick = function (string $route) use ($stopsByRoute): array {
        $stops = $stopsByRoute[$route];
        // later stops (closer to campus) are a little less common
        $weights = [];
        foreach ($stops as $i => $s) {
            $weights[$i] = max(2, 10 - $i);
        }
        return $stops[demo_weighted($weights)];
    };

    $localCities = "'Greater Noida', 'Noida', 'Ghaziabad', 'Delhi'";
    $flagged = db_all("SELECT id, city, admission_date FROM students WHERE status = 'active' AND is_hosteller = 0 AND uses_transport = 1 ORDER BY admission_date, id");
    $pending = array_slice($flagged, -13);
    $poolA = array_slice($flagged, 0, max(0, count($flagged) - 13));
    $needB = max(0, 405 - count($poolA));
    $poolB = $needB ? db_all("SELECT id, city, admission_date FROM students WHERE status = 'active' AND is_hosteller = 0 AND uses_transport = 0 AND city IN ($localCities) ORDER BY id LIMIT $needB") : [];
    $students = array_merge($poolA, $poolB);
    $faculty = db_all("SELECT id, city FROM faculty WHERE status = 'active' AND city IN ($localCities, 'Meerut', 'Bulandshahr') ORDER BY id LIMIT 14");
    $staff = db_all("SELECT id, city FROM staff WHERE status = 'active' AND category NOT IN ('transport', 'hostel') ORDER BY id LIMIT 12");

    $allocRows = [];
    $studentAlloc = [];
    foreach ($students as $s) {
        $route = $pickRoute($s['city']);
        if (!$route) {
            break;
        }
        $remaining[$route]--;
        $stop = $stopPick($route);
        $start = max($routeStart, date('Y-m-d', strtotime(($s['admission_date'] ?: $routeStart) . ' +' . mt_rand(3, 9) . ' days')));
        $start = min($start, $day(-2));
        $allocRows[] = ['person_type' => 'student', 'student_id' => (int) $s['id'], 'faculty_id' => null, 'staff_id' => null, 'route' => $route, 'stop' => $stop,
            'start_date' => $start, 'fee' => $stop['fare']];
    }
    foreach ([['faculty', $faculty], ['staff', $staff]] as [$type, $people]) {
        foreach ($people as $pp) {
            $route = $pickRoute($pp['city'] ?? null);
            if (!$route) {
                break;
            }
            $remaining[$route]--;
            $stop = $stopPick($route);
            $allocRows[] = ['person_type' => $type, 'student_id' => null, 'faculty_id' => $type === 'faculty' ? (int) $pp['id'] : null, 'staff_id' => $type === 'staff' ? (int) $pp['id'] : null,
                'route' => $route, 'stop' => $stop, 'start_date' => $routeStart, 'fee' => $roundTo($stop['fare'] * 0.5, 100)];
        }
    }

    // Transfers: a few riders changed route mid-session (old allocation closed as transferred).
    $transferIdx = [];
    $candidates = array_keys(array_filter($allocRows, fn ($a) => $a['person_type'] === 'student' && $a['start_date'] <= date('Y-m-d', strtotime($routeStart . ' +20 days'))));
    shuffle($candidates);
    foreach (array_slice($candidates, 0, 8) as $k) {
        $transferIdx[$k] = demo_date(date('Y-m-d', strtotime($routeStart . ' +35 days')), $day(-10));
    }
    $reasons = ['Family shifted to a new flat', 'Closer stop requested by parents', 'Moved in with relatives', 'Changed residence after admission'];
    $insert = function (array $row) {
        return db_insert('transport_allocations', $row);
    };
    $activeIds = [];
    foreach ($allocRows as $k => $a) {
        $base = ['person_type' => $a['person_type'], 'student_id' => $a['student_id'], 'faculty_id' => $a['faculty_id'], 'staff_id' => $a['staff_id'],
            'academic_session_id' => $sessionId, 'student_fee_id' => null, 'end_date' => null, 'cancelled_at' => null, 'cancelled_by' => null, 'created_by' => $managerId];
        $fromId = null;
        $start = $a['start_date'];
        if (isset($transferIdx[$k])) {
            $oldRoute = demo_pick(array_values(array_diff(array_keys($targets), [$a['route']])));
            $oldStop = $stopPick($oldRoute);
            $fromId = $insert($base + ['route_id' => $routeIds[$oldRoute], 'stop_id' => $oldStop['id'], 'vehicle_id' => $vehicleIdsByRoute[$oldRoute][0] ?? null,
                'fee_amount' => $oldStop['fare'], 'start_date' => $start, 'end_date' => date('Y-m-d', strtotime($transferIdx[$k] . ' -1 day')), 'status' => 'transferred',
                'remarks' => 'Transferred: ' . demo_pick($reasons), 'transferred_from_id' => null]);
            $start = $transferIdx[$k];
        }
        $id = $insert($base + ['route_id' => $routeIds[$a['route']], 'stop_id' => $a['stop']['id'], 'vehicle_id' => $pickVehicle($a['route']), 'fee_amount' => $a['fee'],
            'start_date' => $start, 'status' => 'active', 'remarks' => $fromId ? demo_pick($reasons) : null, 'transferred_from_id' => $fromId]);
        $activeIds[] = $id;
        if ($a['student_id']) {
            $studentAlloc[$a['student_id']] = $id;
        }
    }
    // Cancelled allocations (history): local day scholars who stopped using the bus.
    $allocated = array_keys($studentAlloc);
    $cancelPool = db_all("SELECT id FROM students WHERE status = 'active' AND is_hosteller = 0 AND uses_transport = 0 AND city IN ($localCities)"
        . ($allocated ? ' AND id NOT IN (' . implode(',', $allocated) . ')' : '') . ' ORDER BY id DESC LIMIT 10');
    $cancelReasons = ['Moved to the college hostel', 'Family relocated — own conveyance', 'Now commuting by metro', 'Discontinued after fee reminder', 'Shifted to a PG near campus'];
    foreach ($cancelPool as $c) {
        $route = demo_pick(array_keys($targets));
        $stop = $stopPick($route);
        $end = demo_date(date('Y-m-d', strtotime($routeStart . ' +20 days')), $day(-7));
        $insert(['person_type' => 'student', 'student_id' => (int) $c['id'], 'faculty_id' => null, 'staff_id' => null, 'route_id' => $routeIds[$route], 'stop_id' => $stop['id'],
            'vehicle_id' => $vehicleIdsByRoute[$route][0] ?? null, 'academic_session_id' => $sessionId, 'fee_amount' => $stop['fare'], 'student_fee_id' => null, 'transferred_from_id' => null,
            'start_date' => $routeStart, 'end_date' => $end, 'status' => 'cancelled', 'remarks' => demo_pick($cancelReasons), 'cancelled_at' => $end . ' 11:' . sprintf('%02d', mt_rand(0, 59)) . ':00',
            'cancelled_by' => $managerId, 'created_by' => $managerId]);
    }
    // Allocation flag on students (what the allocation screen does).
    if ($allocated) {
        foreach (array_chunk($allocated, 400) as $chunk) {
            db_exec('UPDATE students SET uses_transport = 1 WHERE id IN (' . implode(',', $chunk) . ')');
        }
    }

    // Transport fee invoices (student_fees + student_fee_items, Transport head) for the session's student riders — the fees
    // unit leaves transport billing to this module — most of them paid through payments / payment_allocations /
    // payment_receipts (purpose = transport), the rest pending or overdue; a few recent joiners are not invoiced yet.
    $headId = transport_fee_head_id();
    $invoiceCount = 0;
    $paymentCount = 0;
    if ($headId && $sessionId) {
        // Link to an existing session invoice that already bills the Transport head (never bill a student twice).
        db_exec("UPDATE transport_allocations a JOIN (
                    SELECT sf.student_id, MIN(sf.id) AS fee_id FROM student_fees sf JOIN student_fee_items i ON i.student_fee_id = sf.id
                    WHERE i.fee_head_id = ? AND sf.academic_session_id = ? AND sf.status <> 'cancelled' GROUP BY sf.student_id) x ON x.student_id = a.student_id
                 SET a.student_fee_id = x.fee_id WHERE a.status IN ('active', 'transferred') AND a.person_type = 'student'", [$headId, $sessionId]);
        $accountsUser = (int) (db_value("SELECT id FROM users WHERE email = 'accounts@gimt.ac.in'") ?: $adminId);
        $invoicePattern = (string) setting('invoice_prefix', 'INV/{session}/{n:6}');
        $receiptPattern = (string) setting('receipt_prefix', 'RCPT/{session}/{n:6}');
        $dueDays = max(0, (int) setting('transport_fee_due_days', 15));
        $modes = ['upi' => 42, 'bank_transfer' => 20, 'cash' => 16, 'card' => 10, 'cheque' => 7, 'online' => 5];
        $banks = ['State Bank of India', 'HDFC Bank', 'ICICI Bank', 'Punjab National Bank', 'Axis Bank', 'Bank of Baroda', 'Canara Bank'];
        $riders = db_all("SELECT a.id, a.student_id, a.fee_amount, a.start_date, a.transferred_from_id, r.code AS route_code, r.name AS route_name, ts.name AS stop_name,
                                 s.first_name, s.last_name, s.current_semester
                          FROM transport_allocations a JOIN transport_routes r ON r.id = a.route_id LEFT JOIN transport_stops ts ON ts.id = a.stop_id
                          JOIN students s ON s.id = a.student_id
                          WHERE a.status = 'active' AND a.person_type = 'student' AND a.student_fee_id IS NULL ORDER BY a.start_date, a.id");
        $now = strtotime($today);
        foreach ($riders as $a) {
            $age = (int) floor(($now - strtotime($a['start_date'])) / 86400);
            if (($age <= 12 && mt_rand(1, 100) <= 55) || mt_rand(1, 100) <= 3) {
                continue; // recent joiner (or paperwork pending): transport fee not invoiced yet
            }
            $amount = round((float) $a['fee_amount'], 2);
            if ($amount <= 0) {
                continue;
            }
            $issued = $a['transferred_from_id'] ? (string) db_value('SELECT start_date FROM transport_allocations WHERE id = ?', [(int) $a['transferred_from_id']]) : $a['start_date'];
            $issued = min($issued ?: $a['start_date'], $today);
            $due = date('Y-m-d', strtotime($issued . " +$dueDays days"));
            do {
                $invNo = next_number('invoice', $invoicePattern);
            } while (db_value('SELECT COUNT(*) FROM student_fees WHERE invoice_no = ?', [$invNo]));
            $fid = db_insert('student_fees', [
                'invoice_no' => $invNo, 'student_id' => (int) $a['student_id'], 'academic_session_id' => $sessionId,
                'title' => mb_substr('Transport Fee ' . ($session['name'] ?? '') . ' — ' . $a['route_code'] . ' ' . $a['route_name'] . ($a['stop_name'] ? ' (' . $a['stop_name'] . ')' : ''), 0, 190),
                'semester_no' => $a['current_semester'], 'gross_amount' => $amount, 'discount_amount' => 0, 'scholarship_amount' => 0, 'fine_amount' => 0,
                'net_amount' => $amount, 'paid_amount' => 0, 'due_date' => $due, 'status' => 'pending', 'remarks' => 'Transport allocation #' . $a['id'],
                'created_by' => $managerId, 'created_at' => $issued . ' 11:' . sprintf('%02d', mt_rand(0, 59)) . ':00',
            ]);
            db_insert('student_fee_items', ['student_fee_id' => $fid, 'fee_head_id' => $headId, 'amount' => $amount]);
            db_update('transport_allocations', ['student_fee_id' => $fid], 'id = ?', [(int) $a['id']]);
            if ($a['transferred_from_id']) {
                db_update('transport_allocations', ['student_fee_id' => $fid], 'id = ?', [(int) $a['transferred_from_id']]);
            }
            $invoiceCount++;
            // payment behaviour: older invoices are mostly settled, recent ones partly
            $roll = mt_rand(1, 100);
            $billAge = (int) floor(($now - strtotime($issued)) / 86400);
            if ($billAge > 45) {
                $paid = $roll <= 82 ? $amount : ($roll <= 90 ? round($amount * 0.5 / 500) * 500 : 0.0);
            } else {
                $paid = $roll <= 48 ? $amount : ($roll <= 60 ? round($amount * 0.5 / 500) * 500 : 0.0);
            }
            if ($paid > 0) {
                $payDate = demo_date(date('Y-m-d', strtotime($issued . ' +1 day')), min($today, date('Y-m-d', strtotime($due . ' +12 days'))));
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
                $pid = db_insert('payments', [
                    'receipt_no' => $rcpt, 'student_id' => (int) $a['student_id'], 'student_fee_id' => $fid, 'amount' => $paid, 'fine_amount' => 0, 'discount_amount' => 0,
                    'payment_date' => $payDate, 'mode' => $mode, 'reference_no' => $ref, 'bank_name' => in_array($mode, ['cheque', 'bank_transfer'], true) ? demo_pick($banks) : null,
                    'gateway' => $mode === 'online' ? 'razorpay' : null, 'gateway_txn_id' => $mode === 'online' ? $ref : null, 'purpose' => 'transport', 'status' => 'success',
                    'remarks' => 'Transport fee — ' . trim($a['first_name'] . ' ' . $a['last_name']) . ' (' . $a['route_code'] . ')', 'collected_by' => $accountsUser, 'created_at' => $stamp,
                ]);
                db_insert('payment_allocations', ['payment_id' => $pid, 'student_fee_id' => $fid, 'amount' => $paid, 'fine_amount' => 0, 'created_at' => $stamp]);
                db_insert('payment_receipts', ['payment_id' => $pid, 'receipt_no' => $rcpt, 'issued_at' => $stamp]);
                $paymentCount++;
            }
            $status = $paid >= $amount ? 'paid' : ($due < $today ? 'overdue' : ($paid > 0 ? 'partial' : 'pending'));
            db_update('student_fees', ['paid_amount' => $paid, 'status' => $status], 'id = ?', [$fid]);
        }
    }

    // ---------------------------------------------------------------- fuel history (12 months)
    $histStart = $day(-365);
    $activity = function (string $d) use ($ts): float {
        $md = date('m-d', $ts($d));
        $dow = (int) date('N', $ts($d));
        if ($dow === 7) {
            return 0.0;
        }
        if ($md >= '06-01' && $md <= '07-12') {
            return 0.1; // summer vacation: admission-office trips only
        }
        if ($md >= '12-25' || $md <= '01-01') {
            return 0.2;
        }
        if ($md >= '10-19' && $md <= '10-23' && date('Y', $ts($d)) === '2025') {
            return 0.15; // Diwali break 2025
        }
        return 1.0;
    };
    $stations = [
        'diesel' => ['Indian Oil — Knowledge Park II', 'HP Petrol Pump — Pari Chowk', 'Bharat Petroleum — Surajpur', 'Indian Oil — Kasna Road'],
        'cng' => ['IGL CNG — Kalindi Kunj', 'IGL CNG — Mayur Vihar', 'IGL CNG — Sector 62 Noida', 'IGL CNG — Knowledge Park III'],
    ];
    $mileageRange = ['bus:diesel' => [4.2, 4.9], 'bus:cng' => [3.3, 3.8], 'mini_bus:diesel' => [6.4, 7.2], 'mini_bus:cng' => [5.5, 6.1], 'van:diesel' => [9.4, 10.6], 'car:diesel' => [11.4, 12.9]];
    $odoTrack = [];
    $fuelRows = [];
    foreach ($vehicles as $i => $v) {
        $dailyKm = $v['route'] ? 2 * $routeMeta[$v['route']]['km'] + 8 : ($v['type'] === 'car' ? 62 : 14);
        $gapRange = $v['type'] === 'car' ? [6, 9] : ($v['route'] ? [4, 6] : [12, 18]);
        $odo = max(8000, ((int) date('Y') - $v['year']) * mt_rand(30000, 38000) + mt_rand(1000, 9000));
        $stopDate = $v['status'] === 'maintenance' ? $day(-5) : $today;
        $d = date('Y-m-d', strtotime($histStart . ' +' . mt_rand(0, 4) . ' days'));
        $prev = null;
        [$mlo, $mhi] = $mileageRange[$v['type'] . ':' . $v['fuel']] ?? [4.5, 5.0];
        $odoTrack[$v['id']] = [];
        while ($d <= $stopDate) {
            $km = 0;
            if ($prev !== null) {
                for ($t = $ts($prev) + 86400; $t <= $ts($d); $t += 86400) {
                    $km += $dailyKm * $activity(date('Y-m-d', $t)) * (mt_rand(92, 108) / 100);
                }
            }
            $km = (int) round($km);
            if ($prev !== null && $km < 25) {
                $d = date('Y-m-d', strtotime($d . ' +' . mt_rand($gapRange[0], $gapRange[1]) . ' days'));
                continue; // vacation: no fill needed
            }
            $mileage = mt_rand((int) ($mlo * 100), (int) ($mhi * 100)) / 100;
            $liters = $prev === null ? mt_rand(40, 90) : max(8, round($km / $mileage, 2));
            $progress = max(0, min(1, ($ts($d) - $ts($histStart)) / (365 * 86400)));
            $price = $v['fuel'] === 'cng' ? 76.4 + 2.4 * $progress + mt_rand(-20, 20) / 100 : 87.3 + 2.3 * $progress + mt_rand(-25, 25) / 100;
            $odo += $km;
            $odoTrack[$v['id']][$d] = $odo;
            $fuelRows[] = ['vehicle_id' => $v['id'], 'fill_date' => $d, 'liters' => $liters, 'cost' => round($liters * $price, 2), 'odometer' => $odo,
                'station' => demo_pick($stations[$v['fuel'] === 'cng' ? 'cng' : 'diesel']), 'bill_no' => ($v['fuel'] === 'cng' ? 'IGL' : 'FS') . '/' . date('ym', $ts($d)) . '/' . mt_rand(10000, 99999),
                'remarks' => null, 'created_by' => $managerId, 'created_at' => $d . ' ' . sprintf('%02d:%02d:00', mt_rand(17, 19), mt_rand(0, 59))];
            $prev = $d;
            $d = date('Y-m-d', strtotime($d . ' +' . mt_rand($gapRange[0], $gapRange[1]) . ' days'));
        }
        db_update('vehicles', ['current_odometer' => $odo + ($v['status'] === 'maintenance' ? 0 : mt_rand(20, 160))], 'id = ?', [$v['id']]);
    }
    demo_bulk_insert('transport_fuel', $fuelRows);
    $odoAt = function (int $vid, string $date) use (&$odoTrack): ?int {
        $best = null;
        foreach ($odoTrack[$vid] ?? [] as $d => $o) {
            if ($d <= $date) {
                $best = $o;
            } else {
                break;
            }
        }
        return $best ?? (($odoTrack[$vid] ?? []) ? reset($odoTrack[$vid]) : null);
    };

    // ---------------------------------------------------------------- maintenance & renewals
    $workshops = [
        'Tata Motors' => 'Sterling Tata Service Centre, Surajpur', 'Ashok Leyland' => 'Malwa Leyland Workshop, Greater Noida', 'Eicher' => 'Pratap Eicher Service, Ghaziabad',
        'SML Isuzu' => 'Shivam SML Isuzu Works, Noida', 'Force Motors' => 'Shree Force Motors, Noida', 'Toyota' => 'Galaxy Toyota Service, Greater Noida',
    ];
    $insurers = ['United India Insurance', 'ICICI Lombard GIC', 'Bajaj Allianz General', 'New India Assurance'];
    $serviceNotes = ['Engine oil & filter change, air filter cleaning, greasing, brake check', 'Periodic service: oil, fuel filter, coolant top-up, wheel alignment',
        'Scheduled service with brake liner inspection and battery check', 'Oil change, gearbox oil top-up, clutch adjustment, AC filter cleaning'];
    $repairs = [['Clutch plate and pressure plate replaced', 18500], ['Brake liners and drum skimming', 7200], ['AC gas refill and compressor belt', 4800], ['Alternator rewinding', 9600],
        ['Front leaf spring replaced', 12400], ['Windshield replaced after stone hit', 15800], ['Door pneumatic cylinder repaired', 5600], ['Radiator flush and hose replacement', 6900]];
    $maintRows = [];
    $mk = function (array $v, string $type, string $date, float $cost, string $desc, string $vendor, ?string $bill, ?string $next, ?int $nextKm, string $status = 'completed') use (&$maintRows, $odoAt, $managerId) {
        $maintRows[] = ['vehicle_id' => $v['id'], 'service_date' => $date, 'type' => $type, 'description' => $desc, 'cost' => round($cost, 2), 'vendor' => $vendor, 'bill_no' => $bill,
            'odometer' => $status === 'completed' ? $odoAt($v['id'], $date) : null, 'next_service_date' => $next, 'next_service_km' => $nextKm, 'status' => $status,
            'created_by' => $managerId, 'created_at' => $date . ' 15:30:00'];
    };
    $serviceCost = ['bus' => [8500, 14000], 'mini_bus' => [6000, 9000], 'van' => [4800, 7200], 'car' => [6200, 9500]];
    $lastServiceOffset = [0 => -97, 1 => -18, 2 => -64, 3 => -40, 4 => -101, 5 => -72, 6 => -25, 7 => -55, 8 => -33, 9 => -94, 10 => -12, 11 => -68, 12 => -8, 13 => -47, 14 => -77, 15 => -60, 16 => -50, 17 => -30];
    foreach ($vehicles as $i => $v) {
        $vendor = $workshops[$vehicleDefs[$i][2]] ?? 'GIMT Transport Workshop';
        // periodic services every ~3 months, anchored on the last one
        $d = $day($lastServiceOffset[$i]);
        $dates = [];
        while ($d >= $histStart) {
            $dates[] = $d;
            $d = date('Y-m-d', strtotime($d . ' -' . mt_rand(85, 100) . ' days'));
        }
        $dates = array_reverse($dates);
        foreach ($dates as $n => $sd) {
            $odo = $odoAt($v['id'], $sd);
            [$lo, $hi] = $serviceCost[$v['type']];
            $mk($v, 'service', $sd, mt_rand($lo, $hi), demo_pick($serviceNotes), $vendor, 'SRV/' . date('ym', $ts($sd)) . '/' . mt_rand(1000, 9999),
                date('Y-m-d', strtotime($sd . ' +90 days')), $odo ? $odo + ($v['type'] === 'car' ? 10000 : 9000) : null);
        }
        // repairs
        foreach (range(1, mt_rand(1, 2)) as $_) {
            [$desc, $cost] = demo_pick($repairs);
            $mk($v, 'repair', demo_date($histStart, $day(-6)), $cost * (mt_rand(90, 115) / 100), $desc, $vendor, 'RPR/' . mt_rand(10000, 99999), null, null);
        }
        if ($v['type'] !== 'car' && $i % 2 === 0) {
            $tyres = $v['type'] === 'bus' ? 6 : 4;
            $mk($v, 'tyre', demo_date($histStart, $day(-20)), $tyres * ($v['type'] === 'bus' ? 10800 : 7200), "$tyres tyres replaced (radial, tubeless) with alignment & balancing", 'Gautam Tyres, Greater Noida', 'TYR/' . mt_rand(1000, 9999), null, null);
        }
        if ($i % 4 === 1) {
            $mk($v, 'battery', demo_date($histStart, $day(-15)), $v['type'] === 'car' ? 7400 : 13800, 'Battery replaced (Exide 150 Ah, 48-month warranty)', 'Power Battery House, Kasna', 'BAT/' . mt_rand(1000, 9999), null, null);
        }
        // statutory renewals for the current documents (renewed one year / six months before expiry)
        [$ins, $fit, , $puc] = $v['docs'];
        $insRenewed = $day($ins - 365);
        if ($insRenewed >= $histStart && $insRenewed <= $today) {
            $premium = ['bus' => mt_rand(52000, 68000), 'mini_bus' => mt_rand(30000, 36000), 'van' => mt_rand(22000, 26000), 'car' => mt_rand(34000, 41000)][$v['type']];
            $mk($v, 'insurance', $insRenewed, $premium, 'Comprehensive policy renewed (passenger liability included)', demo_pick($insurers), 'POL/' . mt_rand(100000, 999999), $day($ins), null);
        }
        $fitRenewed = $day($fit - 365);
        if ($fitRenewed >= $histStart && $fitRenewed <= $today) {
            $mk($v, 'fitness', $fitRenewed, mt_rand(4200, 6800), 'Fitness test passed at ARTO Greater Noida (speed governor & reflective tape checked)', 'ARTO Greater Noida', 'FIT/' . mt_rand(10000, 99999), $day($fit), null);
        }
        foreach ([182, 364] as $back) {
            $pucDone = $day($puc - $back);
            if ($pucDone >= $histStart && $pucDone <= $today) {
                $mk($v, 'puc', $pucDone, $v['fuel'] === 'cng' ? 600 : 500, 'Pollution under control certificate', 'Authorised PUC Centre, Pari Chowk', 'PUC/' . mt_rand(100000, 999999), date('Y-m-d', strtotime($pucDone . ' +182 days')), null);
            }
        }
    }
    // Workshop & scheduled jobs
    $mk($vehicles[15], 'repair', $day(-4), 38500, 'Gearbox overhaul and body work before the fitness test', 'Pratap Eicher Service, Ghaziabad', 'EST/' . mt_rand(1000, 9999), null, null, 'in_progress');
    $mk($vehicles[6], 'fitness', $day(12), 5500, 'Fitness test slot booked at ARTO Greater Noida', 'ARTO Greater Noida', null, null, null, 'scheduled');
    $mk($vehicles[3], 'service', $day(6), 11500, 'Periodic service + AC servicing before winter', 'Malwa Leyland Workshop, Greater Noida', null, null, null, 'scheduled');
    $mk($vehicles[11], 'repair', $day(3), 6200, 'Rear door pneumatic cylinder leaking — replacement', 'Sterling Tata Service Centre, Surajpur', null, null, null, 'scheduled');
    demo_bulk_insert('transport_maintenance', $maintRows);

    // ---------------------------------------------------------------- complaints
    $studentOn = function (string $route) use ($routeIds) {
        return db_row("SELECT s.id, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS name, s.mobile FROM transport_allocations a JOIN students s ON s.id = a.student_id
                       WHERE a.route_id = ? AND a.status = 'active' ORDER BY a.id LIMIT 1 OFFSET " . mt_rand(0, 8), [$routeIds[$route]]);
    };
    $vehicleOn = fn (string $route) => $vehicleIdsByRoute[$route][0] ?? null;
    // route, category, priority, status, days ago, subject, description, parent name (null = student), resolution
    $complaintDefs = [
        ['R01', 'delay', 'high', 'in_progress', 6, 'Bus late by 25 minutes at Sector 62', 'The R01 bus has been reaching the Sector 62 stop 20-25 minutes late for the past week, so we miss the first lecture.', null, null],
        ['R02', 'overcrowding', 'high', 'open', 2, 'Overcrowding on R02 after new admissions', 'Many students stand in the aisle between Botanical Garden and the expressway. Please add seats or another vehicle.', null, null],
        ['R03', 'rash_driving', 'urgent', 'open', 1, 'Driver overspeeding on the expressway', 'My daughter reports the bus regularly crosses 90 km/h on the expressway in the evening trip. This is unsafe.', 'Anil Saxena (parent)', null],
        ['R06', 'cleanliness', 'medium', 'resolved', 20, 'AC not cooling in the evening trip', 'The AC in the R06 bus is not working properly in the afternoon return trip.', null, 'AC gas refilled and compressor belt replaced; checked during the evening trip.'],
        ['R14', 'route_change', 'low', 'open', 4, 'Stop requested at Sector 78 main gate', 'Please add a pickup at the Sector 78 main gate instead of the service road — it is not lit in the morning.', 'Meenakshi Rao (parent)', null],
        ['R05', 'delay', 'medium', 'closed', 35, 'Bus did not wait at Vaishali Metro', 'The bus left the Vaishali Metro stop two minutes before the scheduled time and three students missed it.', null, 'Driver instructed to wait till the scheduled time; stop timing printed on the pass.'],
        ['R07', 'driver_behaviour', 'high', 'resolved', 15, 'Conductor rude to students', 'The conductor on R07 shouted at students for sitting in the front seats.', null, 'Conductor counselled by the transport in-charge and a written warning issued.'],
        ['R04', 'breakdown', 'urgent', 'closed', 41, 'Bus breakdown near Lal Kuan', 'The R04 bus broke down near Lal Kuan at 7:40 AM; students reached campus after 10 AM.', null, 'Standby bus UP16 AT 9090 dispatched within 35 minutes; alternator replaced the same day.'],
        [null, 'fee', 'medium', 'in_progress', 9, 'Transport fee shown twice in the fee portal', 'The transport fee for this session appears twice in my fee statement.', null, null],
        ['R12', 'cleanliness', 'low', 'resolved', 12, 'Torn seats and dirty floor', 'Two seat covers are torn and the floor is not cleaned daily.', null, 'Seat covers replaced and daily cleaning checklist introduced for the conductor.'],
        ['R08', 'delay', 'medium', 'in_progress', 5, 'Evening bus leaves before 4:15 PM', 'The R08 evening bus left at 4:05 PM twice this week; students from the last lecture missed it.', null, null],
        ['R08', 'route_change', 'low', 'closed', 50, 'Pickup far from Gaur City 7th Avenue gate', 'The bus stops about 400 m from the society gate; please stop at the main gate.', null, 'Stop moved to the Galaxy Plaza gate from the next week; route sheet updated.'],
        ['R10', 'driver_behaviour', 'low', 'open', 3, 'Loud music played in the bus', 'The driver plays loud music during the morning trip — students prepare for tests during the ride.', null, null],
        ['R14', 'overcrowding', 'high', 'resolved', 18, 'Van overloaded on the Sector 50 route', 'More than 17 students travel in the van on most days.', null, 'Passes checked at boarding; riders capped at 17 seats and two students moved to R01.'],
    ];
    usort($complaintDefs, fn ($a, $b) => $b[4] <=> $a[4]);
    $year = date('Y');
    foreach ($complaintDefs as $n => [$route, $cat, $prio, $status, $ago, $subject, $desc, $parent, $resolution]) {
        $created = $day(-$ago) . ' ' . sprintf('%02d:%02d:00', mt_rand(8, 17), mt_rand(0, 59));
        $st = $route ? $studentOn($route) : db_row("SELECT s.id, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS name, s.mobile FROM students s WHERE s.status = 'active' AND s.uses_transport = 1 ORDER BY s.id LIMIT 1 OFFSET 17");
        $no = sprintf('TC-%s-%04d', $year, $n + 1);
        $resolvedAt = in_array($status, ['resolved', 'closed'], true) ? date('Y-m-d H:i:s', strtotime($created . ' +' . mt_rand(20, 70) . ' hours')) : null;
        $cid = db_insert('transport_complaints', [
            'complaint_no' => $no, 'route_id' => $route ? $routeIds[$route] : null, 'vehicle_id' => $route ? $vehicleOn($route) : null, 'student_id' => $st['id'] ?? null,
            'complainant_name' => $parent ?? ($st['name'] ?? 'Student'), 'phone' => $parent ? demo_phone() : ($st['mobile'] ?? null), 'category' => $cat, 'priority' => $prio,
            'subject' => $subject, 'description' => $desc, 'status' => $status, 'resolution' => $resolution, 'assigned_to' => $status === 'open' ? null : $managerId,
            'resolved_at' => $resolvedAt, 'resolved_by' => $resolvedAt ? $managerId : null, 'created_by' => $adminId, 'created_at' => $created, 'updated_at' => $resolvedAt ?? $created,
        ]);
        $steps = ['in_progress' => [['open', 'in_progress']], 'resolved' => [['open', 'in_progress'], ['in_progress', 'resolved']], 'closed' => [['open', 'in_progress'], ['in_progress', 'resolved'], ['resolved', 'closed']]][$status] ?? [];
        $labels = transport_complaint_statuses();
        foreach ($steps as $k => [$from, $to]) {
            $at = $to === 'in_progress' ? date('Y-m-d H:i:s', strtotime($created . ' +' . mt_rand(2, 10) . ' hours')) : ($to === 'resolved' ? $resolvedAt : date('Y-m-d H:i:s', strtotime($resolvedAt . ' +2 days')));
            $note = $to === 'in_progress' ? demo_pick(['Spoke to the driver and checked the route log.', 'Assigned to the transport supervisor for a field check.', 'Called the complainant; investigating.'])
                : ($to === 'resolved' ? $resolution : 'Confirmed with the complainant — closing.');
            db_insert('activity_logs', ['user_id' => $managerId, 'action' => 'update', 'module' => 'transport', 'record_id' => (string) $cid,
                'description' => "Complaint $no: {$labels[$from]} → {$labels[$to]}", 'status' => 'success', 'ip_address' => '127.0.0.1', 'user_agent' => 'seed',
                'meta' => json_encode(['complaint_id' => $cid, 'from' => $from, 'to' => $to, 'note' => $note, 'seed' => 'transport'], JSON_UNESCAPED_UNICODE), 'created_at' => $at]);
        }
    }
    db_exec('INSERT INTO sequences (name, current_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE current_value = GREATEST(current_value, VALUES(current_value))', ['transport_complaint', count($complaintDefs)]);

    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, sprintf("    routes %d · stops %d · crew %d · vehicles %d · allocations %d (active %d) · invoices %d · payments %d · fuel %d · maintenance %d · complaints %d\n",
            count($routeIds), (int) db_value('SELECT COUNT(*) FROM transport_stops'), count($drivers) + count($conductors) + count($helpers), count($vehicles),
            (int) db_value('SELECT COUNT(*) FROM transport_allocations'), (int) db_value("SELECT COUNT(*) FROM transport_allocations WHERE status = 'active'"),
            $invoiceCount, $paymentCount, count($fuelRows), count($maintRows), count($complaintDefs)));
    }
};
