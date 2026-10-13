<?php
/**
 * Transport domain logic (vehicles, drivers, routes & stops, allocations, fuel, maintenance, complaints).
 * Shared by the CRUD module definitions in app/modules/{vehicles,drivers,transport_*}.php and api/routes/transport.php.
 *
 * Capacity model: a route's seats = sum of the capacity of its vehicles that are not inactive; every active allocation
 * (student, faculty or staff) uses one seat. Allocations are tied to the route vehicle with the most free seats.
 * Fee per stop: transport_stops.annual_fare, falling back to transport_routes.annual_fare. An optional invoice is raised in
 * student_fees with the transport fee head (via fees_create_invoice() of the fees unit) and linked through
 * transport_allocations.student_fee_id.
 *
 * GPS / live bus tracking and the driver app are future scope: vehicles.gps_device_id + the transport_gps_* settings
 * keep the integration ready, nothing is simulated.
 */

/* ------------------------------------------------------------------
 * Option lists
 * ------------------------------------------------------------------ */

function transport_vehicle_types(): array
{
    return ['bus' => 'Bus', 'mini_bus' => 'Mini Bus', 'van' => 'Van / Tempo Traveller', 'car' => 'Car', 'other' => 'Other'];
}

function transport_fuel_types(): array
{
    return ['diesel' => 'Diesel', 'cng' => 'CNG', 'petrol' => 'Petrol', 'electric' => 'Electric'];
}

function transport_vehicle_statuses(): array
{
    return ['active' => 'Active', 'maintenance' => 'In maintenance', 'inactive' => 'Inactive'];
}

function transport_driver_types(): array
{
    return ['driver' => 'Driver', 'conductor' => 'Conductor', 'helper' => 'Helper / Attendant'];
}

function transport_license_types(): array
{
    return ['HMV' => 'HMV — Heavy motor vehicle', 'HPMV' => 'HPMV / HTV — Transport', 'LMV' => 'LMV — Light motor vehicle', 'conductor' => 'Conductor licence', 'badge' => 'Attendant badge'];
}

function transport_maintenance_types(): array
{
    return ['service' => 'Periodic service', 'repair' => 'Repair', 'tyre' => 'Tyres', 'battery' => 'Battery', 'insurance' => 'Insurance renewal',
        'fitness' => 'Fitness certificate', 'permit' => 'Permit renewal', 'puc' => 'PUC / Pollution', 'other' => 'Other'];
}

function transport_maintenance_statuses(): array
{
    return ['scheduled' => 'Scheduled', 'in_progress' => 'In workshop', 'completed' => 'Completed'];
}

/** Maintenance types that renew a vehicle document: completing one moves the vehicle expiry to next_service_date. */
function transport_document_renewals(): array
{
    return ['insurance' => 'insurance_expiry', 'fitness' => 'fitness_expiry', 'permit' => 'permit_expiry', 'puc' => 'pollution_expiry'];
}

/** Vehicle document columns => label. */
function transport_vehicle_documents(): array
{
    return ['insurance_expiry' => 'Insurance', 'fitness_expiry' => 'Fitness certificate', 'permit_expiry' => 'Permit', 'pollution_expiry' => 'PUC certificate'];
}

function transport_complaint_categories(): array
{
    return ['delay' => 'Late / missed pickup', 'driver_behaviour' => 'Driver / staff behaviour', 'rash_driving' => 'Rash driving / safety', 'overcrowding' => 'Overcrowding',
        'cleanliness' => 'Cleanliness / AC', 'breakdown' => 'Breakdown', 'route_change' => 'Route / stop request', 'fee' => 'Transport fee', 'other' => 'Other'];
}

function transport_complaint_priorities(): array
{
    return ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'];
}

function transport_complaint_statuses(): array
{
    return ['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed'];
}

function transport_allocation_statuses(): array
{
    return ['active' => 'Active', 'transferred' => 'Transferred', 'cancelled' => 'Cancelled', 'completed' => 'Completed'];
}

function transport_person_types(): array
{
    return ['student' => 'Student', 'faculty' => 'Faculty', 'staff' => 'Staff'];
}

/** Days before an expiry date when it is flagged "due soon". */
function transport_alert_days(): int
{
    return max(7, min(180, (int) setting('transport_alert_days', 30)));
}

/** expired | due | ok | null for an expiry date. */
function transport_expiry_state(?string $date, ?int $days = null): ?string
{
    if (!$date) {
        return null;
    }
    $today = date('Y-m-d');
    if ($date < $today) {
        return 'expired';
    }
    return $date <= date('Y-m-d', strtotime('+' . ($days ?? transport_alert_days()) . ' days')) ? 'due' : 'ok';
}

function transport_days_until(?string $date): ?int
{
    return $date ? (int) floor((strtotime($date) - strtotime(date('Y-m-d'))) / 86400) : null;
}

/** Inline lookup source of transport routes with free seats in the sub label (CRUD module fields/filters). */
function transport_route_source(bool $activeOnly = true): array
{
    return ['table' => 'transport_routes tr', 'value' => 'tr.id', 'label' => "CONCAT(tr.code, ' — ', tr.name)",
        'sub' => "CONCAT(GREATEST(0, " . transport_capacity_sql('tr.id') . ' - ' . transport_load_sql('tr.id') . "), ' seats free')",
        'where' => $activeOnly ? "tr.status = 'active'" : null, 'order' => 'tr.code', 'search' => ['tr.name', 'tr.code']];
}

/* ------------------------------------------------------------------
 * SQL fragments
 * ------------------------------------------------------------------ */

/** Seats on a route (vehicles not inactive). */
function transport_capacity_sql(string $routeCol): string
{
    return "(SELECT COALESCE(SUM(vc.capacity), 0) FROM vehicles vc WHERE vc.route_id = $routeCol AND vc.status <> 'inactive')";
}

/** Active allocations on a route. */
function transport_load_sql(string $routeCol): string
{
    return "(SELECT COUNT(*) FROM transport_allocations al WHERE al.route_id = $routeCol AND al.status = 'active')";
}

/** Earliest document expiry of a vehicle. */
function transport_next_expiry_sql(string $v = 't'): string
{
    $cols = array_map(fn ($c) => "COALESCE($v.$c, '9999-12-31')", array_keys(transport_vehicle_documents()));
    return 'NULLIF(LEAST(' . implode(', ', $cols) . "), '9999-12-31')";
}

/** Person name for an allocation row (aliases s, f, st joined). */
function transport_person_name_sql(string $a = 't'): string
{
    return "CASE $a.person_type WHEN 'student' THEN TRIM(CONCAT_WS(' ', s.first_name, s.last_name))
                                WHEN 'faculty' THEN TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name))
                                ELSE TRIM(CONCAT_WS(' ', st.first_name, st.last_name)) END";
}

function transport_person_joins(string $a = 't'): string
{
    return "LEFT JOIN students s ON s.id = $a.student_id LEFT JOIN programs p ON p.id = s.program_id
            LEFT JOIN faculty f ON f.id = $a.faculty_id LEFT JOIN staff st ON st.id = $a.staff_id";
}

/** Secondary line for an allocation person (Student ID · program / employee id · designation). */
function transport_person_sub_sql(string $a = 't'): string
{
    return "CASE $a.person_type WHEN 'student' THEN CONCAT_WS(' · ', s.student_uid, CONCAT(p.short_name, ' Sem ', s.current_semester))
                                WHEN 'faculty' THEN CONCAT_WS(' · ', f.employee_id, f.designation)
                                ELSE CONCAT_WS(' · ', st.employee_id, st.designation) END";
}

function transport_person_phone_sql(string $a = 't'): string
{
    return "CASE $a.person_type WHEN 'student' THEN s.mobile WHEN 'faculty' THEN f.phone ELSE st.phone END";
}

function transport_person_photo_sql(string $a = 't'): string
{
    return "CASE $a.person_type WHEN 'student' THEN s.photo WHEN 'faculty' THEN f.photo ELSE st.photo END";
}

/* ------------------------------------------------------------------
 * Routes, capacity & vehicles
 * ------------------------------------------------------------------ */

function transport_route_load(int $routeId, ?int $excludeAllocationId = null): array
{
    $cap = (int) db_value("SELECT COALESCE(SUM(capacity), 0) FROM vehicles WHERE route_id = ? AND status <> 'inactive'", [$routeId]);
    $args = [$routeId];
    $sql = "SELECT COUNT(*) FROM transport_allocations WHERE route_id = ? AND status = 'active'";
    if ($excludeAllocationId) {
        $sql .= ' AND id <> ?';
        $args[] = $excludeAllocationId;
    }
    $used = (int) db_value($sql, $args);
    return ['capacity' => $cap, 'allocated' => $used, 'available' => max(0, $cap - $used)];
}

/** Vehicle of the route with the most free seats (null when the route has no vehicle). */
function transport_pick_vehicle(int $routeId): ?int
{
    $id = db_value("SELECT v.id FROM vehicles v WHERE v.route_id = ? AND v.status <> 'inactive'
                    ORDER BY (v.status = 'active') DESC, (v.capacity - (SELECT COUNT(*) FROM transport_allocations a WHERE a.vehicle_id = v.id AND a.status = 'active')) DESC, v.id LIMIT 1", [$routeId]);
    return $id ? (int) $id : null;
}

/** Re-attach active allocations of a route whose vehicle no longer serves it. */
function transport_sync_route_vehicles(int $routeId): int
{
    $ids = db_column("SELECT a.id FROM transport_allocations a LEFT JOIN vehicles v ON v.id = a.vehicle_id
                      WHERE a.route_id = ? AND a.status = 'active' AND (a.vehicle_id IS NULL OR v.route_id IS NULL OR v.route_id <> a.route_id OR v.status = 'inactive')", [$routeId]);
    foreach ($ids as $id) {
        db_update('transport_allocations', ['vehicle_id' => transport_pick_vehicle($routeId)], 'id = ?', [(int) $id]);
    }
    return count($ids);
}

/** Keep students.uses_transport in line with active allocations. */
function transport_sync_student_flag(?int $studentId): void
{
    if (!$studentId) {
        return;
    }
    $active = (int) db_value("SELECT COUNT(*) FROM transport_allocations WHERE student_id = ? AND status = 'active'", [$studentId]);
    db_exec('UPDATE students SET uses_transport = ? WHERE id = ? AND uses_transport <> ?', [$active ? 1 : 0, $studentId, $active ? 1 : 0]);
}

/** Default annual fee for a stop (stop fare, else route fare). */
function transport_stop_fee(?int $stopId, int $routeId): float
{
    if ($stopId) {
        $fare = db_value('SELECT annual_fare FROM transport_stops WHERE id = ? AND route_id = ?', [$stopId, $routeId]);
        if ($fare !== null && $fare !== false) {
            return (float) $fare;
        }
    }
    return (float) (db_value('SELECT annual_fare FROM transport_routes WHERE id = ?', [$routeId]) ?: 0);
}

/** Share of the stop fare charged to faculty / staff riders (Transport settings, default 50%). */
function transport_staff_fee_percent(): int
{
    return max(0, min(100, (int) setting('transport_staff_fee_percent', 50)));
}

/** Default annual fee for a rider: stop fare for students, staff concession (default 50%) for faculty/staff. */
function transport_default_fee(?int $stopId, int $routeId, string $personType = 'student'): float
{
    $fare = transport_stop_fee($stopId, $routeId);
    if ($personType === 'student') {
        return $fare;
    }
    return round($fare * transport_staff_fee_percent() / 100 / 100) * 100;
}

/** Renumber the stops of a route 1..n; $placeId/$position puts one stop at the requested position. */
function transport_normalise_stops(int $routeId, ?int $placeId = null, ?int $position = null): void
{
    $ids = array_map('intval', db_column('SELECT id FROM transport_stops WHERE route_id = ? ORDER BY sequence, id', [$routeId]));
    if ($placeId && in_array($placeId, $ids, true)) {
        $ids = array_values(array_filter($ids, fn ($i) => $i !== $placeId));
        $pos = max(0, min(count($ids), ($position ?? count($ids) + 1) - 1));
        array_splice($ids, $pos, 0, [$placeId]);
    }
    foreach ($ids as $i => $id) {
        db_exec('UPDATE transport_stops SET sequence = ? WHERE id = ? AND sequence <> ?', [$i + 1, $id, $i + 1]);
    }
}

/** Save a new order of stops for a route ($ids must be exactly the route's stops). */
function transport_reorder_stops(int $routeId, array $ids): void
{
    $ids = array_values(array_map('intval', $ids));
    $current = array_map('intval', db_column('SELECT id FROM transport_stops WHERE route_id = ?', [$routeId]));
    sort($current);
    $check = $ids;
    sort($check);
    if ($check !== $current) {
        throw new CrudException('The stop list changed while you were reordering. Reload the route and try again.');
    }
    db_transaction(function () use ($ids) {
        foreach ($ids as $i => $id) {
            db_exec('UPDATE transport_stops SET sequence = ? WHERE id = ?', [$i + 1, $id]);
        }
    });
}

/**
 * Validation shared by the vehicles module: crew types/assignments, capacity vs. allocations when a vehicle leaves a
 * route, goes inactive or shrinks.
 */
function transport_validate_vehicle(array $data, ?int $id, ?array $old): array
{
    $errors = [];
    $merged = array_merge($old ?? [], $data);
    if (!empty($data['vehicle_no'])) {
        $no = transport_normalise_vehicle_no((string) $data['vehicle_no']);
        if (!preg_match('/^[A-Z]{2} ?\d{1,2} ?[A-Z]{0,3} ?\d{1,4}$/', $no) && !preg_match('/^\d{2} ?BH ?\d{4} ?[A-Z]{1,2}$/', $no)) {
            $errors['vehicle_no'] = 'Enter a valid registration number, e.g. UP16 AT 4521.';
        } elseif (db_value('SELECT COUNT(*) FROM vehicles WHERE REPLACE(vehicle_no, \' \', \'\') = ?' . ($id ? ' AND id <> ' . (int) $id : ''), [str_replace(' ', '', $no)])) {
            $errors['vehicle_no'] = 'A vehicle with this registration number already exists.';
        }
    }
    foreach (['driver_id' => ['driver'], 'conductor_id' => ['conductor', 'helper']] as $col => $types) {
        $pid = (int) ($merged[$col] ?? 0);
        if (!$pid || (array_key_exists($col, $data) === false && $old)) {
            continue;
        }
        $d = db_row('SELECT id, name, type, status FROM drivers WHERE id = ?', [$pid]);
        if (!$d) {
            $errors[$col] = 'The selected person does not exist.';
            continue;
        }
        if (!in_array($d['type'], $types, true)) {
            $errors[$col] = $d['name'] . ' is registered as a ' . strtolower(transport_driver_types()[$d['type']] ?? $d['type']) . '.';
            continue;
        }
        if ($d['status'] !== 'active') {
            $errors[$col] = $d['name'] . ' is not active.';
            continue;
        }
        $other = db_value("SELECT vehicle_no FROM vehicles WHERE (driver_id = ? OR conductor_id = ?) AND status <> 'inactive'" . ($id ? ' AND id <> ' . (int) $id : '') . ' LIMIT 1', [$pid, $pid]);
        if ($other) {
            $errors[$col] = $d['name'] . ' is already assigned to ' . $other . '. Unassign them there first.';
        }
    }
    if (!empty($merged['driver_id']) && !empty($merged['conductor_id']) && (int) $merged['driver_id'] === (int) $merged['conductor_id']) {
        $errors['conductor_id'] = 'Driver and conductor must be different people.';
    }
    if (!empty($data['manufacture_year']) && ((int) $data['manufacture_year'] < 1990 || (int) $data['manufacture_year'] > (int) date('Y') + 1)) {
        $errors['manufacture_year'] = 'Enter a year between 1990 and ' . ((int) date('Y') + 1) . '.';
    }
    if (!empty($data['registration_date']) && $data['registration_date'] > date('Y-m-d')) {
        $errors['registration_date'] = 'Registration date cannot be in the future.';
    }
    // Capacity check when this vehicle stops serving its old route (moved, inactive) or shrinks.
    if ($old && $old['route_id'] && $old['status'] !== 'inactive') {
        $oldRoute = (int) $old['route_id'];
        $newRoute = (int) ($merged['route_id'] ?? 0);
        $leaving = $newRoute !== $oldRoute || ($merged['status'] ?? 'active') === 'inactive';
        $shrink = !$leaving ? (int) $old['capacity'] - (int) ($merged['capacity'] ?? $old['capacity']) : 0;
        if ($leaving || $shrink > 0) {
            $load = transport_route_load($oldRoute);
            $after = $load['capacity'] - ($leaving ? (int) $old['capacity'] : $shrink);
            if ($load['allocated'] > $after) {
                $code = (string) db_value('SELECT code FROM transport_routes WHERE id = ?', [$oldRoute]);
                $msg = sprintf('Route %s has %d active allocations but would only have %d seats. Transfer riders or add another vehicle first.', $code, $load['allocated'], max(0, $after));
                $errors[$leaving ? (($merged['status'] ?? '') === 'inactive' && $newRoute === $oldRoute ? 'status' : 'route_id') : 'capacity'] = $msg;
            }
        }
    }
    return $errors;
}

function transport_normalise_vehicle_no(string $no): string
{
    return trim(preg_replace('/\s+/', ' ', strtoupper(str_replace(['-', '.'], ' ', $no))));
}

/** After a vehicle is saved: re-attach allocations of the routes it left / joined. */
function transport_after_vehicle_save(int $id, ?array $old): void
{
    $v = db_row('SELECT * FROM vehicles WHERE id = ?', [$id]);
    $routes = array_filter([(int) ($old['route_id'] ?? 0), (int) ($v['route_id'] ?? 0)]);
    foreach (array_unique($routes) as $r) {
        transport_sync_route_vehicles($r);
    }
}

/* ------------------------------------------------------------------
 * Allocations
 * ------------------------------------------------------------------ */

/** Display name of the person of an allocation. */
function transport_allocation_person(array $a): string
{
    switch ($a['person_type'] ?? 'student') {
        case 'faculty':
            return trim((string) db_value("SELECT TRIM(CONCAT_WS(' ', title, first_name, last_name)) FROM faculty WHERE id = ?", [(int) $a['faculty_id']]));
        case 'staff':
            return trim((string) db_value("SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) FROM staff WHERE id = ?", [(int) $a['staff_id']]));
        default:
            $s = db_row('SELECT first_name, last_name, student_uid FROM students WHERE id = ?', [(int) $a['student_id']]);
            return $s ? trim($s['first_name'] . ' ' . $s['last_name']) . ' (' . $s['student_uid'] . ')' : 'Student #' . (int) $a['student_id'];
    }
}

/**
 * Validate a new or changed allocation. $data holds the merged values (person_type, student_id/faculty_id/staff_id,
 * route_id, stop_id, start_date, end_date, fee_amount). Returns field => message.
 */
function transport_validate_allocation(array $data, ?int $id = null, ?array $old = null): array
{
    $errors = [];
    $type = $data['person_type'] ?? 'student';
    if (!isset(transport_person_types()[$type])) {
        return ['person_type' => 'Select who is travelling (student, faculty or staff).'];
    }
    $personCol = $type === 'student' ? 'student_id' : ($type === 'faculty' ? 'faculty_id' : 'staff_id');
    $personId = (int) ($data[$personCol] ?? 0);
    if (!$personId) {
        $errors[$personCol] = 'Select the ' . $type . ' to allocate.';
    } else {
        $table = $type === 'student' ? 'students' : ($type === 'faculty' ? 'faculty' : 'staff');
        $status = db_value("SELECT status FROM $table WHERE id = ?", [$personId]);
        if ($status === null || $status === false) {
            $errors[$personCol] = 'The selected ' . $type . ' does not exist.';
        } elseif (!in_array($status, $type === 'faculty' ? ['active', 'on_leave'] : ['active'], true)) {
            $errors[$personCol] = 'Only active ' . ($type === 'staff' ? 'staff' : $type . 's') . ' can be allocated transport (current status: ' . label_from_key((string) $status) . ').';
        } else {
            $args = [$personId];
            $sql = "SELECT a.id, r.code FROM transport_allocations a JOIN transport_routes r ON r.id = a.route_id WHERE a.$personCol = ? AND a.status = 'active'";
            if ($id) {
                $sql .= ' AND a.id <> ?';
                $args[] = $id;
            }
            $dup = db_row($sql . ' LIMIT 1', $args);
            if ($dup) {
                $errors[$personCol] = 'Already has an active transport allocation on route ' . $dup['code'] . '. Transfer or cancel it instead.';
            }
        }
    }
    $routeId = (int) ($data['route_id'] ?? 0);
    $route = $routeId ? db_row('SELECT * FROM transport_routes WHERE id = ?', [$routeId]) : null;
    if (!$route) {
        $errors['route_id'] = 'Select a route.';
    } elseif ($route['status'] !== 'active' && (!$old || (int) $old['route_id'] !== $routeId)) {
        $errors['route_id'] = 'Route ' . $route['code'] . ' is not active.';
    }
    $stopId = (int) ($data['stop_id'] ?? 0);
    if (!$stopId) {
        $errors['stop_id'] = 'Select the pickup stop.';
    } elseif ($route && !db_value('SELECT COUNT(*) FROM transport_stops WHERE id = ? AND route_id = ?', [$stopId, $routeId])) {
        $errors['stop_id'] = 'This stop is not on route ' . $route['code'] . '.';
    }
    if ($route && !isset($errors['route_id']) && (!$old || (int) $old['route_id'] !== $routeId || ($old['status'] ?? '') !== 'active')) {
        $load = transport_route_load($routeId, $id);
        if ($load['capacity'] === 0) {
            $errors['route_id'] = 'No vehicle is assigned to route ' . $route['code'] . ' yet. Assign a vehicle before allocating seats.';
        } elseif ($load['available'] <= 0) {
            $errors['route_id'] = sprintf('Route %s is full (%d of %d seats allocated). Choose another route or add a vehicle.', $route['code'], $load['allocated'], $load['capacity']);
        }
    }
    $start = $data['start_date'] ?? null;
    $end = $data['end_date'] ?? null;
    if (!$start) {
        $errors['start_date'] = 'Start date is required.';
    }
    if ($start && $end && $end < $start) {
        $errors['end_date'] = 'End date cannot be before the start date.';
    }
    if (isset($data['fee_amount']) && $data['fee_amount'] !== null && $data['fee_amount'] !== '' && (float) $data['fee_amount'] < 0) {
        $errors['fee_amount'] = 'Fee cannot be negative.';
    }
    return $errors;
}

/** Load the fees unit service (invoices). */
function transport_fees_loaded(): bool
{
    static $ok = null;
    if ($ok === null) {
        $file = APP_ROOT . '/app/services/fees.php';
        if (is_file($file)) {
            require_once $file;
        }
        $ok = function_exists('fees_create_invoice');
    }
    return $ok;
}

/** Transport fee head id (fees schema). */
function transport_fee_head_id(): ?int
{
    $id = db_value("SELECT id FROM fee_heads WHERE type = 'transport' AND status = 'active' ORDER BY (code = 'TRN') DESC, sort_order LIMIT 1");
    return $id ? (int) $id : null;
}

/**
 * Raise the transport fee invoice (student_fees + student_fee_items) for a student allocation.
 * Returns the invoice row. Throws CrudException when not applicable.
 */
function transport_create_invoice(int $allocationId, ?string $dueDate = null, bool $log = true): array
{
    $a = db_row('SELECT a.*, r.code AS route_code, r.name AS route_name, ts.name AS stop_name FROM transport_allocations a
                 JOIN transport_routes r ON r.id = a.route_id LEFT JOIN transport_stops ts ON ts.id = a.stop_id WHERE a.id = ?', [$allocationId]);
    if (!$a) {
        throw new CrudException('Allocation not found.');
    }
    if ($a['person_type'] !== 'student' || !$a['student_id']) {
        throw new CrudException('Fee invoices can only be raised for student allocations.');
    }
    if ($a['student_fee_id'] && db_value("SELECT COUNT(*) FROM student_fees WHERE id = ? AND status <> 'cancelled'", [(int) $a['student_fee_id']])) {
        throw new CrudException('A transport fee invoice has already been raised for this allocation.');
    }
    if ($a['status'] !== 'active') {
        throw new CrudException('Invoices can only be raised for active allocations.');
    }
    $amount = round((float) ($a['fee_amount'] ?? 0), 2);
    if ($amount <= 0) {
        throw new CrudException('Set a transport fee amount on the allocation before raising an invoice.');
    }
    if ($dueDate !== null && $dueDate !== '' && !DateTime::createFromFormat('Y-m-d', $dueDate)) {
        throw new CrudValidationException(['due_date' => 'Enter a valid due date.']);
    }
    $headId = transport_fee_head_id();
    if (!$headId) {
        throw new CrudException('No active "Transport" fee head exists. Add one in Fees › Fee heads first.');
    }
    $student = db_row('SELECT id, current_semester FROM students WHERE id = ?', [(int) $a['student_id']]);
    $sessionId = $a['academic_session_id'] ? (int) $a['academic_session_id'] : current_session_id();
    $sessionName = $sessionId ? (string) db_value('SELECT name FROM academic_sessions WHERE id = ?', [$sessionId]) : (current_session()['name'] ?? '');
    $due = $dueDate ?: date('Y-m-d', strtotime('+' . max(0, (int) setting('transport_fee_due_days', 15)) . ' days'));
    $title = mb_substr('Transport Fee ' . $sessionName . ' — ' . $a['route_code'] . ' ' . $a['route_name'] . ($a['stop_name'] ? ' (' . $a['stop_name'] . ')' : ''), 0, 190);
    $remarks = 'Transport allocation #' . $allocationId;

    $invoiceId = db_transaction(function () use ($a, $allocationId, $amount, $headId, $student, $sessionId, $due, $title, $remarks) {
        if (transport_fees_loaded()) {
            $invoiceId = fees_create_invoice((int) $a['student_id'], $title, [['head' => $headId, 'amount' => $amount]],
                ['due_date' => $due, 'academic_session_id' => $sessionId, 'semester_no' => $student['current_semester'] ?? null, 'remarks' => $remarks, 'log' => false]);
        } else {
            $pattern = (string) setting('invoice_prefix', 'INV/{session}/{n:6}');
            do {
                $invoiceNo = next_number('invoice', $pattern);
            } while (db_value('SELECT COUNT(*) FROM student_fees WHERE invoice_no = ?', [$invoiceNo]));
            $invoiceId = db_insert('student_fees', [
                'invoice_no' => $invoiceNo, 'student_id' => (int) $a['student_id'], 'academic_session_id' => $sessionId, 'title' => $title,
                'semester_no' => $student['current_semester'] ?? null, 'gross_amount' => $amount, 'net_amount' => $amount, 'paid_amount' => 0,
                'due_date' => $due, 'status' => 'pending', 'remarks' => $remarks, 'created_by' => user_id(),
            ]);
            db_insert('student_fee_items', ['student_fee_id' => $invoiceId, 'fee_head_id' => $headId, 'amount' => $amount]);
        }
        db_update('transport_allocations', ['student_fee_id' => $invoiceId], 'id = ?', [$allocationId]);
        return $invoiceId;
    });
    $inv = db_row('SELECT * FROM student_fees WHERE id = ?', [$invoiceId]);
    if ($log) {
        log_activity('create', 'transport', $allocationId, sprintf('Raised transport fee invoice %s of %s for %s', $inv['invoice_no'], money($amount), transport_allocation_person($a)));
    }
    return $inv;
}

/** Set the Transport line of an unpaid invoice to a new annual fee and recompute its totals / status. */
function transport_update_invoice_amount(array $inv, float $fee): void
{
    $headId = (int) transport_fee_head_id();
    $fee = round($fee, 2);
    $other = (float) db_value('SELECT COALESCE(SUM(amount), 0) FROM student_fee_items WHERE student_fee_id = ? AND fee_head_id <> ?', [(int) $inv['id'], $headId]);
    db_exec('UPDATE student_fee_items SET amount = ? WHERE student_fee_id = ? AND fee_head_id = ?', [$fee, (int) $inv['id'], $headId]);
    $gross = round($other + $fee, 2);
    $net = round($gross - (float) $inv['discount_amount'] - (float) $inv['scholarship_amount'] + (float) $inv['fine_amount'], 2);
    db_update('student_fees', ['gross_amount' => $gross, 'net_amount' => max(0, $net)], 'id = ?', [(int) $inv['id']]);
    if (transport_fees_loaded()) {
        fees_refresh_invoice((int) $inv['id']);
    }
}

/**
 * Validation for changing the annual fee of an invoiced allocation: the open invoice follows the new fee, so it must
 * not have payments against it (adjust those from Fees) and the fee cannot drop to zero (cancel the invoice instead).
 */
function transport_validate_fee_change(array $old, $fee): ?string
{
    if (!$old['student_fee_id'] || $fee === null || $fee === '' || abs((float) $fee - (float) $old['fee_amount']) < 0.01) {
        return null;
    }
    $inv = db_row('SELECT invoice_no, status, paid_amount FROM student_fees WHERE id = ?', [(int) $old['student_fee_id']]);
    if (!$inv || $inv['status'] === 'cancelled') {
        return null;
    }
    if ((float) $inv['paid_amount'] > 0) {
        return sprintf('Invoice %s already has %s paid against it. Adjust the fee from Fees (concession or refund) instead.', $inv['invoice_no'], money($inv['paid_amount']));
    }
    if ((float) $fee <= 0) {
        return 'Cancel invoice ' . $inv['invoice_no'] . ' in Fees before setting the transport fee to zero.';
    }
    return null;
}

/** Cancel an unpaid invoice raised for an allocation. Returns a note for the user. */
function transport_cancel_invoice(?int $invoiceId, string $reason): ?string
{
    if (!$invoiceId) {
        return null;
    }
    $inv = db_row('SELECT * FROM student_fees WHERE id = ?', [$invoiceId]);
    if (!$inv || $inv['status'] === 'cancelled') {
        return null;
    }
    if ((float) $inv['paid_amount'] > 0) {
        return sprintf('Invoice %s has %s paid, so it was kept — process a refund from Fees if required.', $inv['invoice_no'], money($inv['paid_amount']));
    }
    if (transport_fees_loaded()) {
        try {
            fees_cancel_invoice($invoiceId, mb_strlen($reason) >= 5 ? $reason : 'Transport allocation cancelled');
        } catch (CrudException $e) {
            return 'Invoice ' . $inv['invoice_no'] . ' was kept: ' . $e->getMessage();
        } catch (CrudValidationException $e) {
            return 'Invoice ' . $inv['invoice_no'] . ' was kept: ' . $e->getMessage();
        }
    } else {
        db_update('student_fees', ['status' => 'cancelled', 'cancelled_reason' => mb_substr($reason, 0, 255), 'cancelled_by' => user_id(), 'cancelled_at' => date('Y-m-d H:i:s')], 'id = ?', [$invoiceId]);
    }
    return 'Invoice ' . $inv['invoice_no'] . ' was cancelled.';
}

/**
 * Cancel an active allocation.
 * $in: date (Y-m-d), reason, cancel_invoice (bool)
 */
function transport_cancel_allocation(int $id, array $in, bool $log = true): array
{
    $a = db_row('SELECT * FROM transport_allocations WHERE id = ?', [$id]);
    if (!$a) {
        throw new CrudException('Allocation not found.');
    }
    if ($a['status'] !== 'active') {
        throw new CrudException('Only active allocations can be cancelled.');
    }
    $date = (string) ($in['date'] ?? '') ?: date('Y-m-d');
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        throw new CrudValidationException(['date' => 'Enter a valid cancellation date.']);
    }
    if ($date < $a['start_date']) {
        if ($a['start_date'] > date('Y-m-d')) {
            $date = $a['start_date']; // not started yet: closes on its start date
        } else {
            throw new CrudValidationException(['date' => 'Cancellation date cannot be before the start date (' . format_date($a['start_date']) . ').']);
        }
    }
    $reason = trim((string) ($in['reason'] ?? ''));
    if (mb_strlen($reason) < 3) {
        throw new CrudValidationException(['reason' => 'Enter a reason for cancelling.']);
    }
    $note = null;
    db_transaction(function () use ($a, $id, $date, $reason, $in, &$note) {
        db_update('transport_allocations', ['status' => 'cancelled', 'end_date' => $date, 'cancelled_at' => date('Y-m-d H:i:s'), 'cancelled_by' => user_id(),
            'remarks' => mb_substr($reason, 0, 255)], 'id = ?', [$id]);
        if (!empty($in['cancel_invoice']) && in_array($in['cancel_invoice'], [true, 1, '1', 'true', 'on', 'yes'], true)) {
            $note = transport_cancel_invoice($a['student_fee_id'] ? (int) $a['student_fee_id'] : null, 'Transport allocation cancelled: ' . $reason);
        }
        transport_sync_student_flag($a['student_id'] ? (int) $a['student_id'] : null);
    });
    if ($log) {
        log_activity('update', 'transport', $id, 'Cancelled transport allocation of ' . transport_allocation_person($a) . ' from ' . format_date($date) . ' — ' . $reason);
    }
    return ['allocation' => db_row('SELECT * FROM transport_allocations WHERE id = ?', [$id]), 'note' => $note];
}

/**
 * Transfer an active allocation to another route/stop. The old allocation is closed as "transferred" the day before the
 * effective date and a new active allocation is created (linked through transferred_from_id).
 * $in: route_id, stop_id, effective_date, fee_amount (optional), update_invoice (bool), remarks
 */
function transport_transfer_allocation(int $id, array $in): array
{
    $a = db_row('SELECT * FROM transport_allocations WHERE id = ?', [$id]);
    if (!$a) {
        throw new CrudException('Allocation not found.');
    }
    if ($a['status'] !== 'active') {
        throw new CrudException('Only active allocations can be transferred.');
    }
    $routeId = (int) ($in['route_id'] ?? 0);
    $stopId = (int) ($in['stop_id'] ?? 0);
    $date = (string) ($in['effective_date'] ?? '');
    $errors = [];
    $dt = $date ? DateTime::createFromFormat('Y-m-d', $date) : null;
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        $errors['effective_date'] = 'Enter the date the transfer takes effect.';
    } elseif ($date <= $a['start_date']) {
        $errors['effective_date'] = 'Transfer date must be after the current start date (' . format_date($a['start_date']) . ').';
    }
    if ($routeId === (int) $a['route_id'] && $stopId === (int) $a['stop_id']) {
        $errors['stop_id'] = 'Choose a different route or stop.';
    }
    $fee = $in['fee_amount'] ?? null;
    if ($fee !== null && $fee !== '' && !is_numeric($fee)) {
        $errors['fee_amount'] = 'Fee must be a number.';
        $fee = null;
    }
    $new = [
        'person_type' => $a['person_type'], 'student_id' => $a['student_id'], 'faculty_id' => $a['faculty_id'], 'staff_id' => $a['staff_id'],
        'route_id' => $routeId, 'stop_id' => $stopId, 'start_date' => $date ?: null, 'end_date' => $a['end_date'] && $a['end_date'] > $date ? $a['end_date'] : null,
        'fee_amount' => $fee !== null && $fee !== '' ? round((float) $fee, 2) : null,
    ];
    // Moving between stops of the same route does not need a new seat.
    $sameRoute = $routeId === (int) $a['route_id'];
    $errors += transport_validate_allocation($new, $id, $sameRoute ? $a : null);
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    if ($new['fee_amount'] === null) {
        $new['fee_amount'] = transport_default_fee($stopId, $routeId, (string) $a['person_type']);
    }
    $note = null;
    $newId = db_transaction(function () use ($a, $id, $new, $date, $in, $routeId, &$note) {
        $remarks = trim((string) ($in['remarks'] ?? ''));
        db_update('transport_allocations', ['status' => 'transferred', 'end_date' => date('Y-m-d', strtotime($date . ' -1 day')),
            'remarks' => mb_substr('Transferred' . ($remarks ? ': ' . $remarks : ''), 0, 255)], 'id = ?', [$id]);
        $newId = db_insert('transport_allocations', $new + [
            'vehicle_id' => transport_pick_vehicle($routeId), 'academic_session_id' => $a['academic_session_id'] ?: current_session_id(),
            'student_fee_id' => $a['student_fee_id'], 'transferred_from_id' => $id, 'status' => 'active', 'remarks' => $remarks ?: null, 'created_by' => user_id(),
        ]);
        // Keep the invoice in step with the new fee while nothing has been paid.
        if ($a['student_fee_id'] && abs((float) $new['fee_amount'] - (float) $a['fee_amount']) >= 0.01) {
            $inv = db_row('SELECT * FROM student_fees WHERE id = ?', [(int) $a['student_fee_id']]);
            if ($inv && $inv['status'] !== 'cancelled') {
                $update = in_array($in['update_invoice'] ?? false, [true, 1, '1', 'true', 'on', 'yes'], true);
                if ($update && (float) $inv['paid_amount'] <= 0 && (float) $new['fee_amount'] > 0) {
                    transport_update_invoice_amount($inv, (float) $new['fee_amount']);
                    $note = 'Invoice ' . $inv['invoice_no'] . ' updated to ' . money($new['fee_amount']) . '.';
                } else {
                    $note = sprintf('Fee changed from %s to %s; invoice %s was not changed — adjust it from Fees if required.', money($a['fee_amount']), money($new['fee_amount']), $inv['invoice_no']);
                }
            }
        }
        return $newId;
    });
    $route = db_row('SELECT code, name FROM transport_routes WHERE id = ?', [$routeId]);
    $stop = (string) db_value('SELECT name FROM transport_stops WHERE id = ?', [$stopId]);
    log_activity('update', 'transport', $newId, sprintf('Transferred %s to route %s (%s) from %s', transport_allocation_person($a), $route['code'] ?? '', $stop, format_date($date)));
    return ['allocation' => db_row('SELECT * FROM transport_allocations WHERE id = ?', [$newId]), 'note' => $note];
}

/** Day scholars who asked for transport (students.uses_transport = 1) but have no active allocation. */
function transport_pending_students(string $q = '', int $page = 1, int $perPage = 20): array
{
    $where = "s.status = 'active' AND s.uses_transport = 1 AND s.is_hosteller = 0 AND NOT EXISTS (SELECT 1 FROM transport_allocations a WHERE a.student_id = s.id AND a.status = 'active')";
    $args = [];
    if ($q !== '') {
        $where .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_uid LIKE ? OR CONCAT(s.first_name, ' ', s.last_name) LIKE ? OR s.city LIKE ? OR s.address LIKE ?)";
        array_push($args, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
    }
    $total = (int) db_value("SELECT COUNT(*) FROM students s WHERE $where", $args);
    $p = paginate($total, $page, $perPage);
    $rows = db_all("SELECT s.id, s.student_uid, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS name, s.photo, s.mobile, s.city, s.address,
                           p.short_name AS program, s.current_semester, s.admission_date
                    FROM students s LEFT JOIN programs p ON p.id = s.program_id WHERE $where ORDER BY s.admission_date DESC, s.first_name, s.last_name LIMIT {$p['per_page']} OFFSET {$p['offset']}", $args);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
    }
    return ['rows' => $rows, 'total' => $p['total'], 'page' => $p['page'], 'pages' => $p['pages'], 'per_page' => $p['per_page']];
}

/** Routes with stops, fares and free seats (allocation / transfer forms). */
function transport_route_options(): array
{
    $routes = db_all('SELECT r.id, r.code, r.name, r.start_point, r.end_point, r.annual_fare, r.morning_departure, r.evening_departure, r.status,
                             ' . transport_capacity_sql('r.id') . ' AS capacity, ' . transport_load_sql('r.id') . " AS allocated
                      FROM transport_routes r WHERE r.status = 'active' ORDER BY r.code");
    $stops = db_all("SELECT ts.id, ts.route_id, ts.name, ts.landmark, ts.sequence, ts.pickup_time, ts.drop_time, ts.distance_km, ts.annual_fare,
                            (SELECT COUNT(*) FROM transport_allocations a WHERE a.stop_id = ts.id AND a.status = 'active') AS riders
                     FROM transport_stops ts JOIN transport_routes r ON r.id = ts.route_id WHERE r.status = 'active' ORDER BY ts.route_id, ts.sequence");
    $byRoute = [];
    foreach ($stops as $s) {
        $byRoute[(int) $s['route_id']][] = ['id' => (int) $s['id'], 'name' => $s['name'], 'landmark' => $s['landmark'], 'sequence' => (int) $s['sequence'],
            'pickup_time' => $s['pickup_time'], 'drop_time' => $s['drop_time'], 'distance_km' => $s['distance_km'] !== null ? (float) $s['distance_km'] : null,
            'annual_fare' => $s['annual_fare'] !== null ? (float) $s['annual_fare'] : null, 'riders' => (int) $s['riders']];
    }
    return array_map(fn ($r) => [
        'id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'start_point' => $r['start_point'], 'end_point' => $r['end_point'],
        'annual_fare' => $r['annual_fare'] !== null ? (float) $r['annual_fare'] : null, 'morning_departure' => $r['morning_departure'],
        'capacity' => (int) $r['capacity'], 'allocated' => (int) $r['allocated'], 'available' => max(0, (int) $r['capacity'] - (int) $r['allocated']),
        'stops' => $byRoute[(int) $r['id']] ?? [],
    ], $routes);
}

/* ------------------------------------------------------------------
 * Fuel & maintenance
 * ------------------------------------------------------------------ */

/** Previous odometer reading of a vehicle before a fill (by date, then id). */
function transport_prev_odometer_sql(string $f = 't'): string
{
    return "(SELECT MAX(f2.odometer) FROM transport_fuel f2 WHERE f2.vehicle_id = $f.vehicle_id AND f2.odometer IS NOT NULL
             AND (f2.fill_date < $f.fill_date OR (f2.fill_date = $f.fill_date AND f2.id < $f.id)))";
}

/** Raise the vehicle odometer to a new reading. */
function transport_bump_odometer(int $vehicleId, ?int $reading): void
{
    if ($reading) {
        db_exec('UPDATE vehicles SET current_odometer = ? WHERE id = ? AND (current_odometer IS NULL OR current_odometer < ?)', [$reading, $vehicleId, $reading]);
    }
}

/** Validate a fuel entry (dates, quantities and odometer continuity). */
function transport_validate_fuel(array $data, ?int $id, ?array $old): array
{
    $m = array_merge($old ?? [], $data);
    $errors = [];
    $vehicle = !empty($m['vehicle_id']) ? db_row('SELECT id, vehicle_no, status, fuel_type FROM vehicles WHERE id = ?', [(int) $m['vehicle_id']]) : null;
    if (!$vehicle) {
        $errors['vehicle_id'] = 'Select a vehicle.';
    } elseif ($vehicle['status'] === 'inactive' && (!$old || (int) $old['vehicle_id'] !== (int) $vehicle['id'])) {
        $errors['vehicle_id'] = $vehicle['vehicle_no'] . ' is inactive.';
    }
    if (!empty($m['fill_date']) && $m['fill_date'] > date('Y-m-d')) {
        $errors['fill_date'] = 'Fill date cannot be in the future.';
    }
    if (isset($m['liters']) && $m['liters'] !== null && ((float) $m['liters'] <= 0 || (float) $m['liters'] > 1000)) {
        $errors['liters'] = 'Enter a quantity between 0.1 and 1,000.';
    }
    if (isset($m['cost']) && $m['cost'] !== null && (float) $m['cost'] <= 0) {
        $errors['cost'] = 'Cost must be greater than zero.';
    }
    if (!isset($errors['liters']) && !isset($errors['cost']) && !empty($m['liters']) && !empty($m['cost'])) {
        $rate = (float) $m['cost'] / (float) $m['liters'];
        if ($rate < 20 || $rate > 250) {
            $errors['cost'] = sprintf('That is %s per unit — check the quantity and amount.', money($rate, 2));
        }
    }
    if ($vehicle && !empty($m['odometer']) && !empty($m['fill_date'])) {
        $odo = (int) $m['odometer'];
        $args = [(int) $vehicle['id'], $m['fill_date']];
        $excl = '';
        if ($id) {
            $excl = ' AND id <> ?';
            $args[] = $id;
        }
        $prev = db_row("SELECT odometer, fill_date FROM transport_fuel WHERE vehicle_id = ? AND odometer IS NOT NULL AND fill_date <= ?$excl ORDER BY fill_date DESC, odometer DESC LIMIT 1", $args);
        $next = db_row("SELECT odometer, fill_date FROM transport_fuel WHERE vehicle_id = ? AND odometer IS NOT NULL AND fill_date > ?$excl ORDER BY fill_date, odometer LIMIT 1", $args);
        if ($prev && $odo < (int) $prev['odometer']) {
            $errors['odometer'] = sprintf('Reading is lower than the previous fill (%s km on %s).', number_in((int) $prev['odometer']), format_date($prev['fill_date']));
        } elseif ($next && $odo > (int) $next['odometer']) {
            $errors['odometer'] = sprintf('Reading is higher than the next fill (%s km on %s).', number_in((int) $next['odometer']), format_date($next['fill_date']));
        }
    }
    return $errors;
}

/** Validate a maintenance record. */
function transport_validate_maintenance(array $data, ?int $id, ?array $old): array
{
    $m = array_merge($old ?? [], $data);
    $errors = [];
    if (empty($m['vehicle_id']) || !db_value('SELECT COUNT(*) FROM vehicles WHERE id = ?', [(int) $m['vehicle_id']])) {
        $errors['vehicle_id'] = 'Select a vehicle.';
    }
    $status = $m['status'] ?? 'completed';
    if (!empty($m['service_date']) && $status === 'completed' && $m['service_date'] > date('Y-m-d')) {
        $errors['service_date'] = 'A completed service cannot be dated in the future. Mark it scheduled instead.';
    }
    if (!empty($m['next_service_date']) && !empty($m['service_date']) && $m['next_service_date'] <= $m['service_date']) {
        $errors['next_service_date'] = 'Next due date must be after the service date.';
    }
    if (!empty($m['next_service_km']) && !empty($m['odometer']) && (int) $m['next_service_km'] <= (int) $m['odometer']) {
        $errors['next_service_km'] = 'Next due reading must be above the current odometer.';
    }
    if (isset($m['cost']) && $m['cost'] !== null && (float) $m['cost'] < 0) {
        $errors['cost'] = 'Cost cannot be negative.';
    }
    if (isset(transport_document_renewals()[$m['type'] ?? '']) && $status === 'completed' && empty($m['next_service_date'])) {
        $errors['next_service_date'] = 'Enter the new expiry date of the renewed document.';
    }
    return $errors;
}

/**
 * After a maintenance record is saved: odometer, document renewal (vehicle expiry) and vehicle workshop status.
 */
function transport_after_maintenance_save(int $id, ?array $old): void
{
    $m = db_row('SELECT * FROM transport_maintenance WHERE id = ?', [$id]);
    if (!$m) {
        return;
    }
    $vid = (int) $m['vehicle_id'];
    if ($m['status'] === 'completed') {
        transport_bump_odometer($vid, $m['odometer'] ? (int) $m['odometer'] : null);
        $col = transport_document_renewals()[$m['type']] ?? null;
        if ($col && $m['next_service_date']) {
            db_exec("UPDATE vehicles SET $col = ? WHERE id = ? AND ($col IS NULL OR $col < ?)", [$m['next_service_date'], $vid, $m['next_service_date']]);
        }
    }
    transport_sync_vehicle_workshop($vid);
    if ($old && (int) $old['vehicle_id'] !== $vid) {
        transport_sync_vehicle_workshop((int) $old['vehicle_id']);
    }
}

/** Vehicle status follows open workshop jobs: in_progress record => maintenance; last job closed => active. */
function transport_sync_vehicle_workshop(int $vehicleId): void
{
    $v = db_row('SELECT status FROM vehicles WHERE id = ?', [$vehicleId]);
    if (!$v || $v['status'] === 'inactive') {
        return;
    }
    $open = (int) db_value("SELECT COUNT(*) FROM transport_maintenance WHERE vehicle_id = ? AND status = 'in_progress'", [$vehicleId]);
    if ($open && $v['status'] === 'active') {
        db_update('vehicles', ['status' => 'maintenance'], 'id = ?', [$vehicleId]);
    } elseif (!$open && $v['status'] === 'maintenance') {
        db_update('vehicles', ['status' => 'active'], 'id = ?', [$vehicleId]);
    }
}

/**
 * Close a scheduled / in-workshop job. $in: service_date, cost, odometer, vendor, bill_no, next_service_date, next_service_km, description
 */
function transport_complete_maintenance(int $id, array $in): array
{
    $m = db_row('SELECT m.*, v.vehicle_no FROM transport_maintenance m JOIN vehicles v ON v.id = m.vehicle_id WHERE m.id = ?', [$id]);
    if (!$m) {
        throw new CrudException('Maintenance record not found.');
    }
    if ($m['status'] === 'completed') {
        throw new CrudException('This job is already completed.');
    }
    $data = ['status' => 'completed'];
    $errors = [];
    $date = (string) ($in['service_date'] ?? '') ?: date('Y-m-d');
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        $errors['service_date'] = 'Enter a valid date.';
    }
    $data['service_date'] = $date;
    foreach (['cost' => 'money', 'odometer' => 'int', 'next_service_km' => 'int'] as $k => $t) {
        if (isset($in[$k]) && $in[$k] !== '' && $in[$k] !== null) {
            if (!is_numeric($in[$k]) || (float) $in[$k] < 0) {
                $errors[$k] = 'Enter a valid number.';
            } else {
                $data[$k] = $t === 'int' ? (int) $in[$k] : round((float) $in[$k], 2);
            }
        }
    }
    if (!empty($in['next_service_date'])) {
        $nd = DateTime::createFromFormat('Y-m-d', (string) $in['next_service_date']);
        if (!$nd) {
            $errors['next_service_date'] = 'Enter a valid date.';
        } else {
            $data['next_service_date'] = (string) $in['next_service_date'];
        }
    }
    foreach (['vendor' => 150, 'bill_no' => 60, 'description' => 500] as $k => $len) {
        if (isset($in[$k]) && trim((string) $in[$k]) !== '') {
            $data[$k] = mb_substr(trim((string) $in[$k]), 0, $len);
        }
    }
    $errors += transport_validate_maintenance($data, $id, $m);
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    db_transaction(function () use ($id, $data, $m) {
        db_update('transport_maintenance', $data, 'id = ?', [$id]);
        transport_after_maintenance_save($id, $m);
    });
    $types = transport_maintenance_types();
    log_activity('update', 'transport', $id, sprintf('Completed %s of %s (%s)', strtolower($types[$m['type']] ?? $m['type']), $m['vehicle_no'], money($data['cost'] ?? $m['cost'])));
    return db_row('SELECT * FROM transport_maintenance WHERE id = ?', [$id]);
}

/**
 * Renew a vehicle document (insurance / fitness / permit / PUC): records a completed maintenance entry and moves the
 * expiry date. $in: document (insurance|fitness|permit|puc), expiry, cost, vendor, bill_no, date
 */
function transport_renew_document(int $vehicleId, array $in): array
{
    $v = db_row('SELECT * FROM vehicles WHERE id = ?', [$vehicleId]);
    if (!$v) {
        throw new CrudException('Vehicle not found.');
    }
    $doc = (string) ($in['document'] ?? '');
    $col = transport_document_renewals()[$doc] ?? null;
    $errors = [];
    if (!$col) {
        $errors['document'] = 'Select the document to renew.';
    }
    $expiry = (string) ($in['expiry'] ?? '');
    $dt = $expiry ? DateTime::createFromFormat('Y-m-d', $expiry) : null;
    if (!$dt || $dt->format('Y-m-d') !== $expiry) {
        $errors['expiry'] = 'Enter the new expiry date.';
    } elseif ($expiry <= date('Y-m-d')) {
        $errors['expiry'] = 'New expiry must be in the future.';
    } elseif ($col && $v[$col] && $expiry <= $v[$col]) {
        $errors['expiry'] = 'New expiry must be after the current one (' . format_date($v[$col]) . ').';
    }
    $date = (string) ($in['date'] ?? '') ?: date('Y-m-d');
    if ($date > date('Y-m-d')) {
        $errors['date'] = 'Renewal date cannot be in the future.';
    }
    $cost = $in['cost'] ?? 0;
    if ($cost !== '' && $cost !== null && (!is_numeric($cost) || (float) $cost < 0)) {
        $errors['cost'] = 'Enter a valid amount.';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $label = transport_vehicle_documents()[$col];
    $mid = db_transaction(function () use ($vehicleId, $doc, $col, $expiry, $date, $cost, $in, $v, $label) {
        $mid = db_insert('transport_maintenance', [
            'vehicle_id' => $vehicleId, 'service_date' => $date, 'type' => $doc, 'description' => $label . ' renewed till ' . format_date($expiry),
            'cost' => round((float) $cost, 2), 'vendor' => trim((string) ($in['vendor'] ?? '')) ?: null, 'bill_no' => trim((string) ($in['bill_no'] ?? '')) ?: null,
            'odometer' => $v['current_odometer'], 'next_service_date' => $expiry, 'status' => 'completed', 'created_by' => user_id(),
        ]);
        db_update('vehicles', [$col => $expiry], 'id = ?', [$vehicleId]);
        return $mid;
    });
    log_activity('update', 'transport', $vehicleId, sprintf('Renewed %s of %s till %s (%s)', strtolower($label), $v['vehicle_no'], format_date($expiry), money((float) $cost)));
    return ['vehicle' => db_row('SELECT * FROM vehicles WHERE id = ?', [$vehicleId]), 'maintenance_id' => (int) $mid];
}

/**
 * Upcoming / overdue services: scheduled & in-workshop records plus vehicles whose last completed mechanical service
 * has a next due date within $days (or a km-based due reached within 500 km).
 */
function transport_upcoming_services(int $days = 30): array
{
    $today = date('Y-m-d');
    $limit = date('Y-m-d', strtotime("+$days days"));
    $out = [];
    $open = db_all("SELECT m.id, m.vehicle_id, m.type, m.status, m.service_date AS due_date, m.vendor, m.description, m.cost, v.vehicle_no, v.model, v.current_odometer, r.code AS route_code
                    FROM transport_maintenance m JOIN vehicles v ON v.id = m.vehicle_id LEFT JOIN transport_routes r ON r.id = v.route_id
                    WHERE m.status IN ('scheduled', 'in_progress') ORDER BY m.service_date, m.id");
    $covered = [];
    foreach ($open as $o) {
        if (!isset(transport_document_renewals()[$o['type']])) {
            $covered[(int) $o['vehicle_id']] = true;
        }
        $out[] = ['source' => 'record', 'id' => (int) $o['id'], 'vehicle_id' => (int) $o['vehicle_id'], 'vehicle_no' => $o['vehicle_no'], 'model' => $o['model'], 'route_code' => $o['route_code'],
            'type' => $o['type'], 'status' => $o['status'], 'due_date' => $o['due_date'], 'due_km' => null, 'odometer' => $o['current_odometer'] !== null ? (int) $o['current_odometer'] : null,
            'vendor' => $o['vendor'], 'description' => $o['description'], 'cost' => (float) $o['cost']];
    }
    $mech = "'service','repair','tyre','battery','other'";
    $last = db_all("SELECT m.*, v.vehicle_no, v.model, v.current_odometer, v.status AS vehicle_status, r.code AS route_code FROM transport_maintenance m
                    JOIN vehicles v ON v.id = m.vehicle_id LEFT JOIN transport_routes r ON r.id = v.route_id
                    WHERE m.status = 'completed' AND m.type IN ($mech) AND v.status <> 'inactive' AND (m.next_service_date IS NOT NULL OR m.next_service_km IS NOT NULL)
                    ORDER BY m.vehicle_id, m.service_date DESC, m.id DESC");
    $seen = [];
    foreach ($last as $m) {
        $vid = (int) $m['vehicle_id'];
        if (isset($seen[$vid])) {
            continue;
        }
        $seen[$vid] = true;
        if (isset($covered[$vid])) {
            continue;
        }
        $kmDue = $m['next_service_km'] !== null && $m['current_odometer'] !== null && (int) $m['current_odometer'] >= (int) $m['next_service_km'] - 500;
        if (($m['next_service_date'] && $m['next_service_date'] <= $limit) || $kmDue) {
            $overdue = ($m['next_service_date'] && $m['next_service_date'] < $today) || ($m['next_service_km'] !== null && $m['current_odometer'] !== null && (int) $m['current_odometer'] >= (int) $m['next_service_km']);
            $out[] = ['source' => 'due', 'id' => (int) $m['id'], 'vehicle_id' => $vid, 'vehicle_no' => $m['vehicle_no'], 'model' => $m['model'], 'route_code' => $m['route_code'],
                'type' => 'service', 'status' => $overdue ? 'overdue' : 'due',
                'due_date' => $m['next_service_date'], 'due_km' => $m['next_service_km'] !== null ? (int) $m['next_service_km'] : null,
                'odometer' => $m['current_odometer'] !== null ? (int) $m['current_odometer'] : null, 'vendor' => $m['vendor'],
                'description' => 'Next service after ' . strtolower(transport_maintenance_types()[$m['type']] ?? $m['type']) . ' on ' . format_date($m['service_date']),
                'cost' => null];
        }
    }
    foreach ($out as &$o) {
        $o['days'] = transport_days_until($o['due_date']);
        if ($o['source'] === 'record' && $o['status'] === 'scheduled' && $o['due_date'] < $today) {
            $o['status'] = 'overdue';
        }
    }
    unset($o);
    usort($out, function ($a, $b) {
        $rank = fn ($x) => $x['status'] === 'in_progress' ? 0 : ($x['status'] === 'overdue' ? 1 : 2);
        return [$rank($a), $a['due_date'] ?? '9999'] <=> [$rank($b), $b['due_date'] ?? '9999'];
    });
    return $out;
}

/**
 * Vehicle document and driver licence expiries that are expired or due within $days.
 * @return array<int, array{kind:string, id:int, label:string, sub:?string, document:string, field:string, date:string, days:int}>
 */
function transport_document_alerts(?int $days = null, int $limit = 100): array
{
    $days = $days ?? max(60, transport_alert_days());
    $limitDate = date('Y-m-d', strtotime("+$days days"));
    $out = [];
    $renewKey = array_flip(transport_document_renewals());
    foreach (transport_vehicle_documents() as $col => $label) {
        foreach (db_all("SELECT v.id, v.vehicle_no, v.model, v.$col AS d, r.code AS route_code FROM vehicles v LEFT JOIN transport_routes r ON r.id = v.route_id
                         WHERE v.status <> 'inactive' AND v.$col IS NOT NULL AND v.$col <= ?", [$limitDate]) as $v) {
            $out[] = ['kind' => 'vehicle', 'id' => (int) $v['id'], 'label' => $v['vehicle_no'], 'sub' => trim(($v['model'] ?? '') . ($v['route_code'] ? ' · ' . $v['route_code'] : ''), ' ·'),
                'document' => $label, 'field' => $col, 'renew' => $renewKey[$col] ?? null, 'date' => $v['d'], 'days' => transport_days_until($v['d'])];
        }
    }
    foreach (db_all("SELECT d.id, d.name, d.type, d.license_no, d.license_expiry FROM drivers d WHERE d.status = 'active' AND d.license_expiry IS NOT NULL AND d.license_expiry <= ?", [$limitDate]) as $d) {
        $out[] = ['kind' => 'driver', 'id' => (int) $d['id'], 'label' => $d['name'], 'sub' => (transport_driver_types()[$d['type']] ?? $d['type']) . ' · ' . $d['license_no'],
            'document' => $d['type'] === 'driver' ? 'Driving licence' : 'Licence / badge', 'field' => 'license_expiry', 'renew' => null, 'date' => $d['license_expiry'], 'days' => transport_days_until($d['license_expiry'])];
    }
    usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));
    return array_slice($out, 0, $limit);
}

/* ------------------------------------------------------------------
 * Complaints workflow
 * ------------------------------------------------------------------ */

function transport_complaint_no(): string
{
    do {
        $no = next_number('transport_complaint', 'TC-{Y}-{n:4}');
    } while (db_value('SELECT COUNT(*) FROM transport_complaints WHERE complaint_no = ?', [$no]));
    return $no;
}

function transport_complaint_transitions(string $from): array
{
    return [
        'open' => ['in_progress', 'resolved', 'closed'],
        'in_progress' => ['resolved', 'open'],
        'resolved' => ['closed', 'open'],
        'closed' => ['open'],
    ][$from] ?? [];
}

/** Move a complaint through the workflow. */
function transport_complaint_set_status(int $id, string $to, ?string $resolution = null, ?string $note = null, ?int $assignTo = null): array
{
    $c = db_row('SELECT * FROM transport_complaints WHERE id = ?', [$id]);
    if (!$c) {
        throw new CrudException('Complaint not found.');
    }
    if (!isset(transport_complaint_statuses()[$to])) {
        throw new CrudValidationException(['status' => 'Select a valid status.']);
    }
    if (!in_array($to, transport_complaint_transitions($c['status']), true)) {
        throw new CrudException(sprintf('A complaint cannot move from “%s” to “%s”.', transport_complaint_statuses()[$c['status']] ?? $c['status'], transport_complaint_statuses()[$to]));
    }
    $resolution = trim((string) $resolution);
    if (in_array($to, ['resolved', 'closed'], true) && $resolution === '' && !$c['resolution']) {
        throw new CrudValidationException(['resolution' => 'Describe how the complaint was resolved.']);
    }
    if ($to === 'open' && trim((string) $note) === '') {
        throw new CrudValidationException(['note' => 'Enter why the complaint is being reopened.']);
    }
    if ($assignTo && !db_value("SELECT COUNT(*) FROM users WHERE id = ? AND status = 'active'", [$assignTo])) {
        throw new CrudValidationException(['assigned_to' => 'Select an active user.']);
    }
    $data = ['status' => $to];
    if ($resolution !== '') {
        $data['resolution'] = mb_substr($resolution, 0, 500);
    }
    if ($to === 'resolved' || ($to === 'closed' && !$c['resolved_at'])) {
        $data['resolved_at'] = date('Y-m-d H:i:s');
        $data['resolved_by'] = user_id();
    }
    if ($to === 'open') {
        $data['resolved_at'] = null;
        $data['resolved_by'] = null;
    }
    if ($assignTo) {
        $data['assigned_to'] = $assignTo;
    } elseif ($to === 'in_progress' && !$c['assigned_to']) {
        $data['assigned_to'] = user_id();
    }
    db_update('transport_complaints', $data, 'id = ?', [$id]);
    $labels = transport_complaint_statuses();
    $desc = sprintf('%s → %s', $labels[$c['status']] ?? $c['status'], $labels[$to]);
    log_activity('update', 'transport', $id, 'Complaint ' . ($c['complaint_no'] ?? '#' . $id) . ': ' . $desc, 'success',
        array_filter(['complaint_id' => $id, 'from' => $c['status'], 'to' => $to, 'note' => trim((string) $note) ?: ($resolution !== '' ? $resolution : null)]));
    if (!empty($data['assigned_to']) && (int) $data['assigned_to'] !== (int) user_id() && (int) $data['assigned_to'] !== (int) $c['assigned_to']) {
        notify((int) $data['assigned_to'], 'complaint', 'Transport complaint assigned', ($c['complaint_no'] ?? '') . ' — ' . $c['subject'], 'admin/transport/maintenance?tab=complaints&complaint=' . $id, 'bus');
    }
    return db_row('SELECT * FROM transport_complaints WHERE id = ?', [$id]);
}

/** Timeline of a complaint (creation + workflow log entries). */
function transport_complaint_history(int $id): array
{
    $c = db_row('SELECT tc.*, u.name AS created_by_name FROM transport_complaints tc LEFT JOIN users u ON u.id = tc.created_by WHERE tc.id = ?', [$id]);
    if (!$c) {
        return [];
    }
    $items = [['action' => 'created', 'title' => 'Complaint registered', 'note' => $c['description'], 'user' => $c['created_by_name'], 'at' => $c['created_at']]];
    $logs = db_all("SELECT l.description, l.meta, l.created_at, u.name AS user_name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
                    WHERE l.module IN ('transport', 'transport_complaints') AND l.record_id = ? AND l.description LIKE 'Complaint %' ORDER BY l.created_at, l.id", [(string) $id]);
    foreach ($logs as $l) {
        $meta = $l['meta'] ? (json_decode($l['meta'], true) ?: []) : [];
        $items[] = ['action' => $meta['to'] ?? 'update', 'title' => preg_replace('/^Complaint [^:]+:\s*/', '', $l['description']), 'note' => $meta['note'] ?? null, 'user' => $l['user_name'], 'at' => $l['created_at']];
    }
    return $items;
}

/* ------------------------------------------------------------------
 * Dashboard, details
 * ------------------------------------------------------------------ */

function transport_dashboard(): array
{
    $monthStart = date('Y-m-01');
    $prevStart = date('Y-m-01', strtotime('first day of last month'));
    $v = db_row("SELECT COUNT(*) AS total, SUM(status = 'active') AS active, SUM(status = 'maintenance') AS maintenance, SUM(status = 'inactive') AS inactive,
                        COALESCE(SUM(CASE WHEN status <> 'inactive' AND route_id IS NOT NULL THEN capacity ELSE 0 END), 0) AS seats,
                        SUM(gps_device_id IS NOT NULL AND gps_device_id <> '' AND status <> 'inactive') AS gps
                 FROM vehicles") ?? [];
    $r = db_row("SELECT COUNT(*) AS total, SUM(status = 'active') AS active, (SELECT COUNT(*) FROM transport_stops) AS stops FROM transport_routes") ?? [];
    $a = db_row("SELECT SUM(person_type = 'student') AS students, SUM(person_type <> 'student') AS staff, COUNT(*) AS total,
                        COALESCE(SUM(fee_amount), 0) AS fee_total, SUM(student_fee_id IS NOT NULL) AS invoiced
                 FROM transport_allocations WHERE status = 'active'") ?? [];
    $d = db_row("SELECT SUM(type = 'driver') AS drivers, SUM(type <> 'driver') AS crew FROM drivers WHERE status = 'active'") ?? [];
    $fuel = db_row('SELECT COALESCE(SUM(CASE WHEN fill_date >= ? THEN cost ELSE 0 END), 0) AS month_cost,
                           COALESCE(SUM(CASE WHEN fill_date >= ? THEN liters ELSE 0 END), 0) AS month_liters,
                           COALESCE(SUM(CASE WHEN fill_date >= ? AND fill_date < ? THEN cost ELSE 0 END), 0) AS prev_cost
                    FROM transport_fuel WHERE fill_date >= ?', [$monthStart, $monthStart, $prevStart, $monthStart, $prevStart]) ?? [];
    // Same-period comparison: last month up to the same day.
    $prevToDate = (float) db_value('SELECT COALESCE(SUM(cost), 0) FROM transport_fuel WHERE fill_date >= ? AND fill_date <= ?', [$prevStart, date('Y-m-d', strtotime('-1 month'))]);
    $upcoming = transport_upcoming_services(30);
    $alerts = transport_document_alerts(null, 50);

    // Fuel & maintenance cost by month (last 12 months)
    $from = date('Y-m-01', strtotime('-11 months', strtotime($monthStart)));
    $fuelRows = db_pairs("SELECT DATE_FORMAT(fill_date, '%Y-%m') AS ym, SUM(cost) FROM transport_fuel WHERE fill_date >= ? GROUP BY ym", [$from]);
    $litreRows = db_pairs("SELECT DATE_FORMAT(fill_date, '%Y-%m') AS ym, SUM(liters) FROM transport_fuel WHERE fill_date >= ? GROUP BY ym", [$from]);
    $maintRows = db_pairs("SELECT DATE_FORMAT(service_date, '%Y-%m') AS ym, SUM(cost) FROM transport_maintenance WHERE status = 'completed' AND service_date >= ? GROUP BY ym", [$from]);
    $months = [];
    for ($i = 0; $i < 12; $i++) {
        $ts = strtotime("+$i months", strtotime($from));
        $ym = date('Y-m', $ts);
        $months[] = ['month' => $ym, 'label' => date('M y', $ts), 'fuel' => round((float) ($fuelRows[$ym] ?? 0), 2), 'liters' => round((float) ($litreRows[$ym] ?? 0), 1), 'maintenance' => round((float) ($maintRows[$ym] ?? 0), 2)];
    }

    $routes = db_all("SELECT r.id, r.code, r.name, r.start_point, r.end_point, r.distance_km, r.morning_departure, r.status,
                             " . transport_capacity_sql('r.id') . ' AS capacity, ' . transport_load_sql('r.id') . " AS allocated,
                             (SELECT COUNT(*) FROM transport_allocations al WHERE al.route_id = r.id AND al.status = 'active' AND al.person_type = 'student') AS students,
                             (SELECT COUNT(*) FROM transport_stops ts WHERE ts.route_id = r.id) AS stops,
                             (SELECT GROUP_CONCAT(v.vehicle_no ORDER BY v.id SEPARATOR ', ') FROM vehicles v WHERE v.route_id = r.id AND v.status <> 'inactive') AS vehicles,
                             (SELECT dr.name FROM vehicles v2 JOIN drivers dr ON dr.id = v2.driver_id WHERE v2.route_id = r.id AND v2.status <> 'inactive' ORDER BY v2.id LIMIT 1) AS driver_name,
                             (SELECT dr.phone FROM vehicles v3 JOIN drivers dr ON dr.id = v3.driver_id WHERE v3.route_id = r.id AND v3.status <> 'inactive' ORDER BY v3.id LIMIT 1) AS driver_phone,
                             (SELECT COUNT(*) FROM transport_complaints tc WHERE tc.route_id = r.id AND tc.status IN ('open', 'in_progress')) AS open_complaints
                      FROM transport_routes r ORDER BY r.status = 'active' DESC, r.code");
    foreach ($routes as &$row) {
        foreach (['id', 'capacity', 'allocated', 'students', 'stops', 'open_complaints'] as $k) {
            $row[$k] = (int) $row[$k];
        }
        $row['distance_km'] = $row['distance_km'] !== null ? (float) $row['distance_km'] : null;
        $row['occupancy'] = $row['capacity'] ? round($row['allocated'] * 100 / $row['capacity'], 1) : 0;
    }
    unset($row);
    $complaints = db_all("SELECT tc.id, tc.complaint_no, tc.subject, tc.category, tc.priority, tc.status, tc.complainant_name, tc.created_at, r.code AS route_code
                          FROM transport_complaints tc LEFT JOIN transport_routes r ON r.id = tc.route_id
                          WHERE tc.status IN ('open', 'in_progress') ORDER BY FIELD(tc.priority, 'urgent', 'high', 'medium', 'low'), tc.created_at DESC LIMIT 5");
    $openComplaints = (int) db_value("SELECT COUNT(*) FROM transport_complaints WHERE status IN ('open', 'in_progress')");
    $maintenanceDue = count(array_filter($upcoming, fn ($u) => in_array($u['status'], ['overdue', 'due', 'scheduled'], true) && ($u['days'] === null || $u['days'] <= 30)));
    $pending = (int) db_value("SELECT COUNT(*) FROM students s WHERE s.status = 'active' AND s.uses_transport = 1 AND s.is_hosteller = 0 AND NOT EXISTS (SELECT 1 FROM transport_allocations a WHERE a.student_id = s.id AND a.status = 'active')");
    $seats = (int) ($v['seats'] ?? 0);
    $riders = (int) ($a['total'] ?? 0);
    return [
        'kpis' => [
            'vehicles_total' => (int) ($v['total'] ?? 0), 'vehicles_active' => (int) ($v['active'] ?? 0), 'vehicles_maintenance' => (int) ($v['maintenance'] ?? 0),
            'vehicles_inactive' => (int) ($v['inactive'] ?? 0), 'seats' => $seats, 'riders' => $riders, 'occupancy' => $seats ? round($riders * 100 / $seats, 1) : 0,
            'routes_total' => (int) ($r['total'] ?? 0), 'routes_active' => (int) ($r['active'] ?? 0), 'stops' => (int) ($r['stops'] ?? 0),
            'students' => (int) ($a['students'] ?? 0), 'staff_riders' => (int) ($a['staff'] ?? 0), 'fee_total' => (float) ($a['fee_total'] ?? 0), 'invoiced' => (int) ($a['invoiced'] ?? 0),
            'drivers' => (int) ($d['drivers'] ?? 0), 'crew' => (int) ($d['crew'] ?? 0),
            'maintenance_due' => $maintenanceDue, 'in_workshop' => count(array_filter($upcoming, fn ($u) => $u['status'] === 'in_progress')),
            'fuel_month' => (float) ($fuel['month_cost'] ?? 0), 'fuel_month_liters' => (float) ($fuel['month_liters'] ?? 0), 'fuel_prev_month' => (float) ($fuel['prev_cost'] ?? 0),
            'fuel_prev_to_date' => $prevToDate,
            'documents_expired' => count(array_filter($alerts, fn ($x) => $x['days'] < 0)), 'documents_due' => count(array_filter($alerts, fn ($x) => $x['days'] >= 0 && $x['days'] <= transport_alert_days())),
            'open_complaints' => $openComplaints, 'pending_requests' => $pending,
        ],
        'months' => $months,
        'routes' => $routes,
        'alerts' => array_slice($alerts, 0, 12),
        'alert_days' => transport_alert_days(),
        'upcoming' => array_slice($upcoming, 0, 6),
        'complaints' => $complaints,
        'settings' => transport_settings(),
    ];
}

/** Route detail: route, load, vehicles & crew, stops with riders. */
function transport_route_detail(int $id): ?array
{
    $route = db_row('SELECT r.* FROM transport_routes r WHERE r.id = ?', [$id]);
    if (!$route) {
        return null;
    }
    $route['id'] = (int) $route['id'];
    $load = transport_route_load($id);
    $vehicles = db_all("SELECT v.id, v.vehicle_no, v.type, v.make, v.model, v.capacity, v.fuel_type, v.status, v.current_odometer, v.gps_device_id,
                               v.insurance_expiry, v.fitness_expiry, v.permit_expiry, v.pollution_expiry,
                               d.id AS driver_id, d.name AS driver_name, d.phone AS driver_phone, d.photo AS driver_photo, d.license_expiry AS driver_license_expiry,
                               c.id AS conductor_id, c.name AS conductor_name, c.phone AS conductor_phone, c.photo AS conductor_photo,
                               (SELECT COUNT(*) FROM transport_allocations a WHERE a.vehicle_id = v.id AND a.status = 'active') AS allocated
                        FROM vehicles v LEFT JOIN drivers d ON d.id = v.driver_id LEFT JOIN drivers c ON c.id = v.conductor_id
                        WHERE v.route_id = ? ORDER BY v.status = 'inactive', v.id", [$id]);
    foreach ($vehicles as &$v) {
        $v['id'] = (int) $v['id'];
        $v['capacity'] = (int) $v['capacity'];
        $v['allocated'] = (int) $v['allocated'];
        $v['next_expiry'] = min(array_filter([$v['insurance_expiry'], $v['fitness_expiry'], $v['permit_expiry'], $v['pollution_expiry']]) ?: [null]);
        $v['next_expiry_state'] = transport_expiry_state($v['next_expiry']);
    }
    unset($v);
    $stops = db_all('SELECT ts.* FROM transport_stops ts WHERE ts.route_id = ? ORDER BY ts.sequence, ts.id', [$id]);
    $riders = db_all('SELECT t.id, t.person_type, t.student_id, t.faculty_id, t.staff_id, t.stop_id, t.fee_amount, t.start_date, t.student_fee_id, ' . transport_person_name_sql('t') . ' AS name,
                             ' . transport_person_sub_sql('t') . ' AS sub, ' . transport_person_phone_sql('t') . ' AS phone, ' . transport_person_photo_sql('t') . ' AS photo,
                             v.vehicle_no, sf.invoice_no, sf.status AS invoice_status
                      FROM transport_allocations t ' . transport_person_joins('t') . "
                      LEFT JOIN vehicles v ON v.id = t.vehicle_id LEFT JOIN student_fees sf ON sf.id = t.student_fee_id
                      WHERE t.route_id = ? AND t.status = 'active' ORDER BY name", [$id]);
    $byStop = [];
    foreach ($riders as $r) {
        $r['id'] = (int) $r['id'];
        $byStop[(int) $r['stop_id']][] = $r;
    }
    foreach ($stops as &$s) {
        $s['id'] = (int) $s['id'];
        $s['sequence'] = (int) $s['sequence'];
        $s['riders'] = $byStop[$s['id']] ?? [];
        $s['rider_count'] = count($s['riders']);
    }
    unset($s);
    $unassigned = $byStop[0] ?? [];
    $complaints = db_all("SELECT id, complaint_no, subject, status, priority, created_at FROM transport_complaints WHERE route_id = ? ORDER BY created_at DESC LIMIT 5", [$id]);
    $byType = ['student' => 0, 'faculty' => 0, 'staff' => 0];
    foreach ($riders as $r) {
        $byType[$r['person_type']] = ($byType[$r['person_type']] ?? 0) + 1;
    }
    return [
        'route' => $route + ['load' => $load, 'occupancy' => $load['capacity'] ? round($load['allocated'] * 100 / $load['capacity'], 1) : 0],
        'vehicles' => $vehicles, 'stops' => $stops, 'unassigned' => $unassigned, 'by_type' => $byType,
        'fee_total' => round(array_sum(array_map(fn ($r) => (float) $r['fee_amount'], $riders)), 2),
        'complaints' => $complaints,
    ];
}

/** Vehicle detail: documents, crew, fuel stats, maintenance history. */
function transport_vehicle_detail(int $id): ?array
{
    $v = db_row('SELECT v.*, r.code AS route_code, r.name AS route_name, d.name AS driver_name, d.phone AS driver_phone, d.photo AS driver_photo, d.license_no AS driver_license,
                        d.license_expiry AS driver_license_expiry, c.name AS conductor_name, c.phone AS conductor_phone, c.photo AS conductor_photo,
                        (SELECT COUNT(*) FROM transport_allocations a WHERE a.vehicle_id = v.id AND a.status = \'active\') AS allocated
                 FROM vehicles v LEFT JOIN transport_routes r ON r.id = v.route_id LEFT JOIN drivers d ON d.id = v.driver_id LEFT JOIN drivers c ON c.id = v.conductor_id
                 WHERE v.id = ?', [$id]);
    if (!$v) {
        return null;
    }
    $v['id'] = (int) $v['id'];
    $v['allocated'] = (int) $v['allocated'];
    $docs = [];
    foreach (transport_vehicle_documents() as $col => $label) {
        $docs[] = ['field' => $col, 'label' => $label, 'renew' => array_flip(transport_document_renewals())[$col], 'date' => $v[$col], 'days' => transport_days_until($v[$col]), 'state' => transport_expiry_state($v[$col])];
    }
    $since = date('Y-m-d', strtotime('-90 days'));
    $fuel = db_row('SELECT COUNT(*) AS fills, COALESCE(SUM(liters), 0) AS liters, COALESCE(SUM(cost), 0) AS cost, MIN(odometer) AS odo_min, MAX(odometer) AS odo_max
                    FROM transport_fuel WHERE vehicle_id = ? AND fill_date >= ?', [$id, $since]) ?? [];
    $firstLitres = (float) db_value('SELECT liters FROM transport_fuel WHERE vehicle_id = ? AND fill_date >= ? AND odometer IS NOT NULL ORDER BY fill_date, id LIMIT 1', [$id, $since]);
    $km = $fuel['odo_max'] && $fuel['odo_min'] ? (int) $fuel['odo_max'] - (int) $fuel['odo_min'] : 0;
    $usable = (float) $fuel['liters'] - $firstLitres;
    $recentFuel = db_all('SELECT t.id, t.fill_date, t.liters, t.cost, t.odometer, t.station, ' . transport_prev_odometer_sql('t') . ' AS prev_odometer
                          FROM transport_fuel t WHERE t.vehicle_id = ? ORDER BY t.fill_date DESC, t.id DESC LIMIT 6', [$id]);
    foreach ($recentFuel as &$f) {
        $f['km'] = $f['odometer'] && $f['prev_odometer'] ? (int) $f['odometer'] - (int) $f['prev_odometer'] : null;
        $f['mileage'] = $f['km'] && (float) $f['liters'] > 0 ? round($f['km'] / (float) $f['liters'], 2) : null;
    }
    unset($f);
    $maintenance = db_all('SELECT id, service_date, type, description, cost, vendor, odometer, next_service_date, status FROM transport_maintenance WHERE vehicle_id = ? ORDER BY service_date DESC, id DESC LIMIT 8', [$id]);
    $session = current_session();
    $maintCost = (float) db_value("SELECT COALESCE(SUM(cost), 0) FROM transport_maintenance WHERE vehicle_id = ? AND status = 'completed' AND service_date >= ?", [$id, $session['start_date'] ?? date('Y') . '-07-01']);
    return [
        'vehicle' => $v, 'documents' => $docs,
        'fuel' => ['fills' => (int) $fuel['fills'], 'liters' => round((float) $fuel['liters'], 1), 'cost' => round((float) $fuel['cost'], 2), 'km' => $km,
            'mileage' => $km > 0 && $usable > 0 ? round($km / $usable, 2) : null, 'cost_per_km' => $km > 0 ? round((float) $fuel['cost'] / $km, 2) : null],
        'recent_fuel' => $recentFuel, 'maintenance' => $maintenance, 'maintenance_cost_session' => $maintCost,
    ];
}

/** Fuel analytics for the fuel log tab: totals for a period + per-vehicle efficiency. */
function transport_fuel_stats(?string $from = null, ?string $to = null, ?int $vehicleId = null): array
{
    $from = $from ?: date('Y-m-01', strtotime('-2 months'));
    $to = $to ?: date('Y-m-d');
    $args = [$from, $to];
    $vw = '';
    if ($vehicleId) {
        $vw = ' AND t.vehicle_id = ?';
        $args[] = $vehicleId;
    }
    $rows = db_all('SELECT v.id, v.vehicle_no, v.type, v.fuel_type, COUNT(t.id) AS fills, COALESCE(SUM(t.liters), 0) AS liters, COALESCE(SUM(t.cost), 0) AS cost,
                           MIN(t.odometer) AS odo_min, MAX(t.odometer) AS odo_max,
                           (SELECT t2.liters FROM transport_fuel t2 WHERE t2.vehicle_id = v.id AND t2.fill_date BETWEEN ? AND ? AND t2.odometer IS NOT NULL ORDER BY t2.fill_date, t2.id LIMIT 1) AS first_liters
                    FROM transport_fuel t JOIN vehicles v ON v.id = t.vehicle_id
                    WHERE t.fill_date BETWEEN ? AND ?' . $vw . ' GROUP BY v.id, v.vehicle_no, v.type, v.fuel_type ORDER BY cost DESC', array_merge([$from, $to], $args));
    $out = [];
    $tot = ['liters' => 0.0, 'cost' => 0.0, 'km' => 0, 'fills' => 0];
    foreach ($rows as $r) {
        $km = $r['odo_max'] && $r['odo_min'] ? (int) $r['odo_max'] - (int) $r['odo_min'] : 0;
        $usable = (float) $r['liters'] - (float) $r['first_liters'];
        $out[] = ['id' => (int) $r['id'], 'vehicle_no' => $r['vehicle_no'], 'type' => $r['type'], 'fuel_type' => $r['fuel_type'], 'fills' => (int) $r['fills'],
            'liters' => round((float) $r['liters'], 1), 'cost' => round((float) $r['cost'], 2), 'km' => $km,
            'mileage' => $km > 0 && $usable > 0 ? round($km / $usable, 2) : null, 'cost_per_km' => $km > 0 ? round((float) $r['cost'] / $km, 2) : null];
        $tot['liters'] += (float) $r['liters'];
        $tot['cost'] += (float) $r['cost'];
        $tot['km'] += $km;
        $tot['fills'] += (int) $r['fills'];
    }
    return ['from' => $from, 'to' => $to, 'vehicles' => $out, 'totals' => [
        'liters' => round($tot['liters'], 1), 'cost' => round($tot['cost'], 2), 'km' => $tot['km'], 'fills' => $tot['fills'],
        'avg_rate' => $tot['liters'] > 0 ? round($tot['cost'] / $tot['liters'], 2) : null, 'cost_per_km' => $tot['km'] > 0 ? round($tot['cost'] / $tot['km'], 2) : null,
    ]];
}

/* ------------------------------------------------------------------
 * Settings (GPS / live tracking integration is future scope)
 * ------------------------------------------------------------------ */

function transport_settings(): array
{
    return [
        'alert_days' => transport_alert_days(),
        'fee_due_days' => (int) setting('transport_fee_due_days', 15),
        'staff_fee_percent' => transport_staff_fee_percent(),
        'gps_enabled' => (bool) (int) setting('transport_gps_enabled', 0),
        'gps_provider' => (string) setting('transport_gps_provider', ''),
        'gps_api_url' => (string) setting('transport_gps_api_url', ''),
        'gps_refresh_seconds' => (int) setting('transport_gps_refresh_seconds', 30),
        'parent_alerts' => (bool) (int) setting('transport_parent_alerts', 0),
        'devices_mapped' => (int) db_value("SELECT COUNT(*) FROM vehicles WHERE gps_device_id IS NOT NULL AND gps_device_id <> '' AND status <> 'inactive'"),
        'vehicles' => (int) db_value("SELECT COUNT(*) FROM vehicles WHERE status <> 'inactive'"),
        'providers' => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(transport_gps_providers()), transport_gps_providers()),
    ];
}

function transport_gps_providers(): array
{
    return ['' => 'Not connected', 'traccar' => 'Traccar (self-hosted)', 'fleetx' => 'Fleetx', 'loconav' => 'LocoNav', 'trackolap' => 'TrackoLap', 'custom' => 'Custom REST endpoint'];
}

/** Validate + store transport settings. */
function transport_save_settings(array $in): array
{
    $errors = [];
    $alert = (int) ($in['alert_days'] ?? transport_alert_days());
    if ($alert < 7 || $alert > 180) {
        $errors['alert_days'] = 'Choose between 7 and 180 days.';
    }
    $due = (int) ($in['fee_due_days'] ?? 15);
    if ($due < 0 || $due > 90) {
        $errors['fee_due_days'] = 'Choose between 0 and 90 days.';
    }
    $staffPct = $in['staff_fee_percent'] ?? transport_staff_fee_percent();
    if (!is_numeric($staffPct) || (int) $staffPct < 0 || (int) $staffPct > 100) {
        $errors['staff_fee_percent'] = 'Enter a percentage between 0 and 100.';
    }
    $provider = (string) ($in['gps_provider'] ?? '');
    if (!array_key_exists($provider, transport_gps_providers())) {
        $errors['gps_provider'] = 'Select a supported provider.';
    }
    $url = trim((string) ($in['gps_api_url'] ?? ''));
    if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url))) {
        $errors['gps_api_url'] = 'Enter a valid http(s) URL.';
    }
    $refresh = (int) ($in['gps_refresh_seconds'] ?? 30);
    if ($refresh < 10 || $refresh > 600) {
        $errors['gps_refresh_seconds'] = 'Choose between 10 and 600 seconds.';
    }
    $enabled = in_array($in['gps_enabled'] ?? false, [true, 1, '1', 'true', 'on'], true);
    if ($enabled && ($provider === '' || $url === '')) {
        $errors['gps_enabled'] = 'Select a provider and API endpoint before enabling the integration.';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    save_setting('transport_alert_days', $alert, 'transport');
    save_setting('transport_fee_due_days', $due, 'transport');
    save_setting('transport_staff_fee_percent', (int) $staffPct, 'transport');
    save_setting('transport_gps_enabled', $enabled ? 1 : 0, 'transport');
    save_setting('transport_gps_provider', $provider, 'transport');
    save_setting('transport_gps_api_url', $url, 'transport');
    save_setting('transport_gps_refresh_seconds', $refresh, 'transport');
    save_setting('transport_parent_alerts', in_array($in['parent_alerts'] ?? false, [true, 1, '1', 'true', 'on'], true) ? 1 : 0, 'transport');
    settings_all(true);
    log_activity('update', 'transport', null, 'Updated transport settings (alerts ' . $alert . ' days, GPS ' . ($enabled ? 'enabled via ' . $provider : 'not connected') . ')');
    return transport_settings();
}

/* ------------------------------------------------------------------
 * Print views (rendered through api/routes/transport.php)
 * ------------------------------------------------------------------ */

/**
 * Guard for the HTML print views served from the API: a cold open without a session goes to the admin login (instead of
 * a JSON 401) and a missing permission renders a printable "access denied" sheet (instead of JSON).
 */
function transport_print_guard(string $action = 'view'): bool
{
    if (!session_is_valid() && !login_from_remember_cookie()) {
        redirect(admin_url('login'));
    }
    if (can('transport', $action)) {
        return true;
    }
    log_activity('denied', 'transport', null, "Access denied: print transport ($action)", 'failed');
    http_response_code(403);
    print_layout_start('Access denied', false);
    echo '<div class="mt-16 text-center"><p class="font-display text-lg font-bold text-brand-900">You don\'t have access to this document</p>'
        . '<p class="mt-1 text-sm text-slate-500">Your role does not include the "' . e($action) . '" permission for Transport.</p></div>';
    print_layout_end();
    return false;
}

/** Route sheet: stops with timings and riders per stop, vehicle and crew. */
function transport_print_route_sheet(int $id): void
{
    $d = transport_route_detail($id);
    if (!$d) {
        http_response_code(404);
        print_layout_start('Route not found');
        echo '<p class="mt-10 text-center text-slate-500">This route does not exist or was deleted.</p>';
        print_layout_end();
        return;
    }
    $r = $d['route'];
    print_layout_start('Route Sheet ' . $r['code']);
    echo '<div class="mt-5 flex items-start justify-between gap-4">';
    echo '<div><p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Transport route sheet · Session ' . e(current_session()['name'] ?? '') . '</p>';
    echo '<h1 class="font-display text-xl font-bold text-brand-900">' . e($r['code'] . ' — ' . $r['name']) . '</h1>';
    echo '<p class="text-xs text-slate-600">' . e(($r['start_point'] ?? '') . ' → ' . ($r['end_point'] ?? '')) . ($r['distance_km'] ? ' · ' . e(number_in((float) $r['distance_km'], 1)) . ' km' : '') . '</p></div>';
    echo '<div class="text-right text-xs text-slate-600"><p>Morning departure: <strong>' . e($r['morning_departure'] ? format_time($r['morning_departure']) : '—') . '</strong></p>';
    echo '<p>Evening departure: <strong>' . e($r['evening_departure'] ? format_time($r['evening_departure']) : '—') . '</strong></p>';
    echo '<p>Seats: <strong>' . (int) $r['load']['allocated'] . ' / ' . (int) $r['load']['capacity'] . '</strong></p></div></div>';

    // Fixed column widths (inline styles: no extra print CSS needed) so every stop table lines up.
    echo '<table class="mt-4 w-full border-collapse text-[11px]" style="table-layout:fixed"><colgroup><col style="width:17%"><col style="width:29%"><col style="width:10%"><col style="width:22%"><col style="width:22%"></colgroup><thead><tr>';
    foreach (['Vehicle', 'Type / model', 'Seats', 'Driver', 'Conductor'] as $h) {
        echo '<th class="border border-brand-900 bg-brand-900 px-2 py-1.5 text-left font-semibold text-white">' . e($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    $crew = function (?string $name, ?string $phone): string {
        return $name ? '<span class="font-medium">' . e($name) . '</span>' . ($phone ? '<br><span class="text-slate-500">' . e($phone) . '</span>' : '') : '<span class="text-slate-400">Not assigned</span>';
    };
    foreach ($d['vehicles'] as $v) {
        if ($v['status'] === 'inactive') {
            continue;
        }
        echo '<tr><td class="border border-slate-200 px-2 py-1 align-top font-semibold whitespace-nowrap">' . e($v['vehicle_no']) . '</td>'
            . '<td class="border border-slate-200 px-2 py-1 align-top">' . e((transport_vehicle_types()[$v['type']] ?? $v['type']) . ' · ' . trim(($v['make'] ?? '') . ' ' . ($v['model'] ?? ''))) . '</td>'
            . '<td class="border border-slate-200 px-2 py-1 align-top whitespace-nowrap">' . (int) $v['allocated'] . ' / ' . (int) $v['capacity'] . '</td>'
            . '<td class="border border-slate-200 px-2 py-1 align-top">' . $crew($v['driver_name'], $v['driver_phone']) . '</td>'
            . '<td class="border border-slate-200 px-2 py-1 align-top">' . $crew($v['conductor_name'], $v['conductor_phone']) . '</td></tr>';
    }
    if (!$d['vehicles']) {
        echo '<tr><td colspan="5" class="border border-slate-200 px-2 py-3 text-center text-slate-500">No vehicle assigned.</td></tr>';
    }
    echo '</tbody></table>';
    $riderCols = '<colgroup><col style="width:5%"><col style="width:24%"><col style="width:35%"><col style="width:16%"><col style="width:9%"><col style="width:11%"></colgroup>';

    foreach ($d['stops'] as $s) {
        echo '<section class="mt-4 break-inside-avoid">';
        echo '<div class="flex items-center justify-between rounded-t-md bg-slate-100 px-2 py-1.5 text-[11px]"><span class="font-bold text-brand-900">' . (int) $s['sequence'] . '. ' . e($s['name']) . ($s['landmark'] ? ' <span class="font-normal text-slate-500">(' . e($s['landmark']) . ')</span>' : '') . '</span>'
            . '<span class="text-slate-600">Pickup ' . e($s['pickup_time'] ? format_time($s['pickup_time']) : '—') . ' · Drop ' . e($s['drop_time'] ? format_time($s['drop_time']) : '—') . ' · ' . count($s['riders']) . ' rider(s)</span></div>';
        if ($s['riders']) {
            echo '<table class="w-full border-collapse text-[10.5px]" style="table-layout:fixed">' . $riderCols . '<tbody>';
            foreach ($s['riders'] as $i => $p) {
                echo '<tr class="' . ($i % 2 ? 'bg-slate-50' : '') . '"><td class="border border-slate-200 px-2 py-0.5 text-slate-500">' . ($i + 1) . '</td>'
                    . '<td class="truncate border border-slate-200 px-2 py-0.5 font-medium">' . e($p['name']) . '</td>'
                    . '<td class="truncate border border-slate-200 px-2 py-0.5 text-slate-600">' . e($p['sub'] ?? '') . '</td>'
                    . '<td class="whitespace-nowrap border border-slate-200 px-2 py-0.5">' . e($p['phone'] ?? '') . '</td>'
                    . '<td class="border border-slate-200 px-2 py-0.5 text-slate-500">' . e(label_from_key($p['person_type'])) . '</td>'
                    . '<td class="border border-slate-200 px-2 py-0.5"></td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p class="border border-t-0 border-slate-200 px-2 py-1.5 text-[10.5px] text-slate-400">No riders at this stop.</p>';
        }
        echo '</section>';
    }
    echo '<div class="mt-8 grid grid-cols-3 gap-6 text-center text-[11px] text-slate-600">'
        . '<div class="border-t border-slate-400 pt-1">Driver</div><div class="border-t border-slate-400 pt-1">Conductor</div><div class="border-t border-slate-400 pt-1">Transport In-charge</div></div>';
    echo '<p class="mt-4 text-[10px] text-slate-400">Generated on ' . e(format_datetime(date('Y-m-d H:i:s'))) . '. The last column is for daily attendance / remarks.</p>';
    print_layout_end();
}

/** Transport passes (bus ID) for one or more allocations, 2 per row. */
function transport_print_passes(array $ids): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $rows = $ids ? db_all('SELECT t.*, ' . transport_person_name_sql('t') . ' AS name, ' . transport_person_sub_sql('t') . ' AS sub, ' . transport_person_phone_sql('t') . ' AS phone,
                                  ' . transport_person_photo_sql('t') . ' AS photo, r.code AS route_code, r.name AS route_name, ts.name AS stop_name, ts.pickup_time, ts.drop_time,
                                  v.vehicle_no, ses.name AS session_name, s.blood_group,
                                  CASE t.person_type WHEN \'student\' THEN s.student_uid WHEN \'faculty\' THEN f.employee_id ELSE st.employee_id END AS person_code
                           FROM transport_allocations t ' . transport_person_joins('t') . '
                           JOIN transport_routes r ON r.id = t.route_id LEFT JOIN transport_stops ts ON ts.id = t.stop_id LEFT JOIN vehicles v ON v.id = t.vehicle_id
                           LEFT JOIN academic_sessions ses ON ses.id = t.academic_session_id
                           WHERE t.id IN (' . implode(',', $ids) . ") AND t.status = 'active' ORDER BY r.code, name", []) : [];
    print_layout_start(count($rows) === 1 ? 'Transport Pass — ' . $rows[0]['name'] : 'Transport Passes (' . count($rows) . ')', false);
    if (!$rows) {
        echo '<p class="mt-10 text-center text-slate-500">No active allocations selected. Passes are issued for active allocations only.</p>';
        print_layout_end();
        return;
    }
    echo '<div class="grid grid-cols-2 gap-5">';
    foreach ($rows as $p) {
        // QR opens the allocation register filtered by the rider's ID (no personal name in the URL).
        $verify = absolute_url('admin/transport/allocations?q=' . rawurlencode((string) ($p['person_code'] ?: ('#' . $p['id']))));
        $times = '<span class="whitespace-nowrap">' . e($p['pickup_time'] ? format_time($p['pickup_time']) : '—') . '</span> / <span class="whitespace-nowrap">' . e($p['drop_time'] ? format_time($p['drop_time']) : '—') . '</span>';
        $photo = $p['photo'] ? upload_url($p['photo']) : '';
        echo '<div class="break-inside-avoid overflow-hidden rounded-xl border border-slate-300">';
        echo '<div class="flex items-center gap-2 bg-brand-900 px-3 py-2 text-white"><img src="' . e(logo_url(true)) . '" alt="" class="h-7 w-auto">'
            . '<div class="min-w-0 flex-1 leading-tight"><p class="truncate text-[10px] font-bold uppercase">' . e(institute_name()) . '</p><p class="text-[9px] opacity-80">Transport Pass · Session ' . e($p['session_name'] ?? (current_session()['name'] ?? '')) . '</p></div>'
            . '<span class="rounded bg-accent-600 px-1.5 py-0.5 text-[10px] font-bold">' . e($p['route_code']) . '</span></div>';
        echo '<div class="flex gap-3 p-3">';
        echo $photo
            ? '<img src="' . e($photo) . '" alt="" class="h-20 w-16 shrink-0 rounded-md object-cover ring-1 ring-slate-200">'
            : '<div class="flex h-20 w-16 shrink-0 items-center justify-center rounded-md bg-slate-100 text-lg font-bold text-slate-400">' . e(initials($p['name'])) . '</div>';
        echo '<div class="min-w-0 flex-1 text-[10.5px] leading-snug"><p class="text-[12px] font-bold text-brand-900">' . e($p['name']) . '</p>'
            . '<p class="text-slate-600">' . e($p['sub'] ?? '') . '</p>'
            . '<p class="mt-1"><span class="text-slate-500">Route:</span> <strong>' . e($p['route_code'] . ' — ' . $p['route_name']) . '</strong></p>'
            . '<p><span class="text-slate-500">Stop:</span> <strong>' . e($p['stop_name'] ?? '—') . '</strong></p>'
            . '<p><span class="text-slate-500">Pickup / Drop:</span> ' . $times . '</p>'
            . '<p><span class="text-slate-500">Vehicle:</span> <span class="whitespace-nowrap">' . e($p['vehicle_no'] ?? '—') . '</span>'
            . ($p['blood_group'] ? ' · <span class="whitespace-nowrap"><span class="text-slate-500">Blood</span> <strong>' . e($p['blood_group']) . '</strong></span>' : '') . '</p>'
            . '<p><span class="text-slate-500">Valid:</span> <span class="whitespace-nowrap">' . e(format_date($p['start_date'])) . '</span> – <span class="whitespace-nowrap">' . e($p['end_date'] ? format_date($p['end_date']) : 'end of session') . '</span></p></div>';
        echo '<div class="shrink-0 text-center"><div data-qr="' . e($verify) . '" class="h-16 w-16"></div><p class="mt-1 text-[8px] text-slate-400">#' . (int) $p['id'] . '</p></div>';
        echo '</div><div class="flex items-end justify-between gap-3 border-t border-dashed border-slate-300 px-3 py-1.5 text-[9px] text-slate-500"><span>Carry while travelling · Non-transferable</span><span class="whitespace-nowrap border-t border-slate-400 px-3 pt-0.5">Transport In-charge</span></div></div>';
    }
    echo '</div>';
    print_layout_end();
}
