<?php
/**
 * Hostel domain services (brief section 20).
 *
 *   - option lists (hostel / room types, amenities, complaint categories, ID proofs …)
 *   - room <-> bed synchronisation (capacity, occupied count, full/available status)
 *   - allocations: validate + occupy (CRUD hooks), vacate, transfer, cancel, hostel fee invoice (student_fees)
 *   - complaint workflow (assign / start / resolve / close / reopen) and visitor check-out
 *   - dashboard, room grid and availability data for the SPA
 *   - server-rendered print views (allotment letter, occupancy register, visitor pass, complaint job card)
 *
 * Used by app/modules/hostel*.php and api/routes/hostel.php.
 */

/* ------------------------------------------------------------------
 * Option lists
 * ------------------------------------------------------------------ */

function hostel_types(): array
{
    return ['boys' => 'Boys', 'girls' => 'Girls', 'staff' => 'Staff', 'international' => 'International'];
}

function hostel_room_types(): array
{
    return ['single' => 'Single', 'double' => 'Double', 'triple' => 'Triple', 'quad' => 'Four seater', 'dormitory' => 'Dormitory'];
}

function hostel_room_type_capacity(string $type): int
{
    return ['single' => 1, 'double' => 2, 'triple' => 3, 'quad' => 4, 'dormitory' => 8][$type] ?? 2;
}

function hostel_amenities(): array
{
    return ['wifi' => 'Wi-Fi', 'mess' => 'Mess / dining hall', 'laundry' => 'Laundry', 'gym' => 'Gymnasium', 'reading_room' => 'Reading room', 'common_room' => 'Common room & TV',
        'ro_water' => 'RO drinking water', 'hot_water' => 'Hot water (geyser / solar)', 'power_backup' => '24x7 power backup', 'cctv' => 'CCTV & security', 'indoor_games' => 'Indoor games',
        'medical' => 'Sick room / first aid', 'housekeeping' => 'Daily housekeeping', 'parking' => 'Two-wheeler parking'];
}

function hostel_room_amenities(): array
{
    return ['attached_bath' => 'Attached washroom', 'balcony' => 'Balcony', 'study_table' => 'Study table & chair', 'wardrobe' => 'Wardrobe', 'ceiling_fan' => 'Ceiling fan',
        'ac' => 'Air conditioner', 'geyser' => 'Geyser', 'cooler' => 'Air cooler', 'lan' => 'LAN point', 'bookshelf' => 'Bookshelf'];
}

function hostel_room_statuses(): array
{
    return ['available' => 'Available', 'full' => 'Full', 'maintenance' => 'Under maintenance', 'inactive' => 'Inactive'];
}

function hostel_allocation_statuses(): array
{
    return ['active' => 'Active', 'vacated' => 'Vacated', 'transferred' => 'Transferred', 'cancelled' => 'Cancelled'];
}

function hostel_complaint_categories(): array
{
    return ['maintenance' => 'Maintenance', 'electrical' => 'Electrical', 'plumbing' => 'Plumbing', 'cleanliness' => 'Cleanliness', 'mess' => 'Mess & food',
        'security' => 'Security', 'internet' => 'Internet / Wi-Fi', 'furniture' => 'Furniture', 'other' => 'Other'];
}

function hostel_complaint_priorities(): array
{
    return ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'];
}

function hostel_complaint_statuses(): array
{
    return ['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed'];
}

function hostel_visitor_relations(): array
{
    return ['Father' => 'Father', 'Mother' => 'Mother', 'Brother' => 'Brother', 'Sister' => 'Sister', 'Guardian' => 'Local guardian', 'Uncle' => 'Uncle',
        'Aunt' => 'Aunt', 'Cousin' => 'Cousin', 'Grandparent' => 'Grandparent', 'Friend' => 'Friend', 'Other' => 'Other'];
}

function hostel_id_proof_types(): array
{
    return ['aadhaar' => 'Aadhaar card', 'driving_licence' => 'Driving licence', 'voter_id' => 'Voter ID', 'pan' => 'PAN card', 'passport' => 'Passport', 'other' => 'Other'];
}

/** "Ground floor", "1st floor", "2nd floor" … */
function hostel_floor_label($floor): string
{
    $n = (int) $floor;
    if ($n === 0) {
        return 'Ground floor';
    }
    $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
    return $n . $suffix . ' floor';
}

/** Gender a hostel type admits (null = any). */
function hostel_gender_for_type(?string $type): ?string
{
    return ['boys' => 'male', 'girls' => 'female'][(string) $type] ?? null;
}

function hostel_student_name(array $s): string
{
    return trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
}

/** Gender rule check. Returns an error message or null. */
function hostel_gender_error(array $hostel, array $student): ?string
{
    $need = hostel_gender_for_type($hostel['type'] ?? null);
    if ($need === null) {
        return null;
    }
    if (($student['gender'] ?? '') !== $need) {
        return sprintf('%s is a %s hostel — %s is listed as %s.', $hostel['name'], strtolower(hostel_types()[$hostel['type']] ?? $hostel['type']), hostel_student_name($student),
            $student['gender'] ? strtolower(label_from_key((string) $student['gender'])) : 'gender not set');
    }
    return null;
}

/* ------------------------------------------------------------------
 * Rooms & beds
 * ------------------------------------------------------------------ */

/** Recalculate a room's capacity (= beds), occupied count and full/available status from its beds. */
function hostel_sync_room(int $roomId): void
{
    $room = db_row('SELECT id, capacity, status FROM hostel_rooms WHERE id = ?', [$roomId]);
    if (!$room) {
        return;
    }
    $b = db_row("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'occupied'), 0) AS occ FROM hostel_beds WHERE room_id = ?", [$roomId]) ?? [];
    $total = (int) ($b['total'] ?? 0);
    $occ = (int) ($b['occ'] ?? 0);
    $cap = $total ?: (int) $room['capacity'];
    $status = $room['status'];
    if (!in_array($status, ['maintenance', 'inactive'], true)) {
        $status = $cap > 0 && $occ >= $cap ? 'full' : 'available';
    }
    db_update('hostel_rooms', ['capacity' => $cap, 'occupied' => $occ, 'status' => $status], 'id = ?', [$roomId]);
}

/** Make the number of beds in a room match its capacity (adds numbered beds / removes free ones from the end). */
function hostel_sync_beds(int $roomId, int $capacity): void
{
    $beds = db_all('SELECT b.id, b.bed_no, b.status, (SELECT COUNT(*) FROM hostel_allocations ha WHERE ha.bed_id = b.id AND ha.status = \'active\') AS active_allocs
                    FROM hostel_beds b WHERE b.room_id = ? ORDER BY LENGTH(b.bed_no), b.bed_no', [$roomId]);
    $count = count($beds);
    if ($count < $capacity) {
        $existing = array_map('strval', array_column($beds, 'bed_no'));
        $n = 1;
        while ($count < $capacity) {
            while (in_array((string) $n, $existing, true)) {
                $n++;
            }
            db_insert('hostel_beds', ['room_id' => $roomId, 'bed_no' => (string) $n, 'status' => 'available']);
            $existing[] = (string) $n;
            $count++;
        }
    } elseif ($count > $capacity) {
        $need = $count - $capacity;
        $removable = array_values(array_filter(array_reverse($beds), fn ($b) => $b['status'] !== 'occupied' && !(int) $b['active_allocs']));
        if (count($removable) < $need) {
            $occupied = $count - count($removable);
            throw new CrudValidationException(['capacity' => "Capacity cannot be lower than the number of occupied beds ($occupied). Vacate or transfer students first."]);
        }
        foreach (array_slice($removable, 0, $need) as $b) {
            db_delete('hostel_beds', 'id = ?', [(int) $b['id']]);
        }
    }
    hostel_sync_room($roomId);
}

/** Set a bed to available / maintenance (occupied beds are managed by allocations). */
function hostel_set_bed_status(int $bedId, string $status): array
{
    if (!in_array($status, ['available', 'maintenance'], true)) {
        throw new CrudValidationException(['status' => 'Choose Available or Under maintenance.']);
    }
    $bed = db_row('SELECT b.*, r.room_no, r.status AS room_status, h.code AS hostel_code FROM hostel_beds b JOIN hostel_rooms r ON r.id = b.room_id JOIN hostels h ON h.id = r.hostel_id WHERE b.id = ?', [$bedId]);
    if (!$bed) {
        throw new CrudException('Bed not found. It may have been removed.');
    }
    $active = (int) db_value("SELECT COUNT(*) FROM hostel_allocations WHERE bed_id = ? AND status = 'active'", [$bedId]);
    if ($active || $bed['status'] === 'occupied') {
        throw new CrudException('Bed ' . $bed['bed_no'] . ' is occupied. Vacate or transfer the student before changing its status.');
    }
    db_transaction(function () use ($bedId, $status, $bed) {
        db_update('hostel_beds', ['status' => $status], 'id = ?', [$bedId]);
        hostel_sync_room((int) $bed['room_id']);
    });
    log_activity('update', 'hostel', $bedId, sprintf('Marked %s Room %s Bed %s as %s', $bed['hostel_code'], $bed['room_no'], $bed['bed_no'], $status === 'maintenance' ? 'under maintenance' : 'available'));
    return $bed;
}

/* ------------------------------------------------------------------
 * Allocations
 * ------------------------------------------------------------------ */

function hostel_session_name(?int $sessionId): string
{
    $name = $sessionId ? db_value('SELECT name FROM academic_sessions WHERE id = ?', [$sessionId]) : null;
    return (string) ($name ?: (current_session()['name'] ?? date('Y')));
}

function hostel_next_allocation_no(?int $sessionId): string
{
    $s = hostel_session_name($sessionId);
    do {
        $no = next_number('hostel_allocation:' . $s, 'HST/' . $s . '/{n:5}');
    } while (db_value('SELECT COUNT(*) FROM hostel_allocations WHERE allocation_no = ?', [$no]));
    return $no;
}

/** Full allocation row with student / hostel / room / bed / invoice details. */
function hostel_allocation_row(int $id): ?array
{
    return db_row("SELECT ha.*, s.first_name, s.last_name, s.student_uid, s.gender, s.photo, s.mobile, s.email, s.father_name, s.guardian_name, s.guardian_phone, s.current_semester,
                          s.status AS student_status, p.short_name AS program_short, p.name AS program_name,
                          h.name AS hostel_name, h.code AS hostel_code, h.type AS hostel_type, h.mess_fee, h.warden_name, h.warden_phone, h.visiting_hours, h.address AS hostel_address,
                          r.room_no, r.floor_no, r.room_type, r.is_ac, r.annual_fee AS room_rent, b.name AS block_name, hb.bed_no,
                          ses.name AS session_name, sf.invoice_no, sf.title AS invoice_title, sf.net_amount AS invoice_amount, sf.paid_amount AS invoice_paid,
                          sf.balance_amount AS invoice_balance, sf.status AS invoice_status, sf.due_date AS invoice_due,
                          ua.name AS allocated_by_name, uv.name AS vacated_by_name, pf.allocation_no AS transferred_from_no
                   FROM hostel_allocations ha
                   JOIN students s ON s.id = ha.student_id
                   LEFT JOIN programs p ON p.id = s.program_id
                   JOIN hostels h ON h.id = ha.hostel_id
                   JOIN hostel_rooms r ON r.id = ha.room_id
                   LEFT JOIN hostel_blocks b ON b.id = r.block_id
                   LEFT JOIN hostel_beds hb ON hb.id = ha.bed_id
                   LEFT JOIN academic_sessions ses ON ses.id = ha.academic_session_id
                   LEFT JOIN student_fees sf ON sf.id = ha.student_fee_id
                   LEFT JOIN users ua ON ua.id = ha.allocated_by
                   LEFT JOIN users uv ON uv.id = ha.vacated_by
                   LEFT JOIN hostel_allocations pf ON pf.id = ha.transferred_from_id
                   WHERE ha.id = ?", [$id]);
}

function hostel_active_allocation(int $studentId): ?array
{
    return db_row("SELECT ha.id, ha.allocation_no, ha.hostel_id, ha.room_id, ha.bed_id, ha.allocated_on, ha.fee_amount, h.name AS hostel_name, h.code AS hostel_code, r.room_no, hb.bed_no
                   FROM hostel_allocations ha JOIN hostels h ON h.id = ha.hostel_id JOIN hostel_rooms r ON r.id = ha.room_id LEFT JOIN hostel_beds hb ON hb.id = ha.bed_id
                   WHERE ha.student_id = ? AND ha.status = 'active' ORDER BY ha.id DESC LIMIT 1", [$studentId]);
}

function hostel_room_row(int $roomId): ?array
{
    return db_row('SELECT r.*, h.name AS hostel_name, h.code AS hostel_code, h.type AS hostel_type, h.status AS hostel_status, h.annual_fee AS hostel_fee, h.mess_fee, b.name AS block_name
                   FROM hostel_rooms r JOIN hostels h ON h.id = r.hostel_id LEFT JOIN hostel_blocks b ON b.id = r.block_id WHERE r.id = ?', [$roomId]);
}

/**
 * Validate a target room/bed for a student (new allocation or transfer).
 * @return array field => message
 */
function hostel_validate_target(array $student, ?int $hostelId, ?int $roomId, ?int $bedId, ?int $ignoreBedId = null): array
{
    $errors = [];
    $room = $roomId ? hostel_room_row($roomId) : null;
    if (!$room) {
        $errors['room_id'] = 'Select a room.';
        return $errors;
    }
    if ($hostelId && (int) $room['hostel_id'] !== $hostelId) {
        $errors['room_id'] = 'The selected room does not belong to the selected hostel.';
        return $errors;
    }
    if ($room['hostel_status'] !== 'active') {
        $errors['hostel_id'] = $room['hostel_name'] . ' is inactive and cannot take new residents.';
    }
    if ($err = hostel_gender_error(['name' => $room['hostel_name'], 'type' => $room['hostel_type']], $student)) {
        $errors['hostel_id'] = $err;
    }
    if (in_array($room['status'], ['maintenance', 'inactive'], true)) {
        $errors['room_id'] = 'Room ' . $room['room_no'] . ' is ' . ($room['status'] === 'maintenance' ? 'under maintenance' : 'inactive') . '.';
    }
    $bed = $bedId ? db_row('SELECT * FROM hostel_beds WHERE id = ?', [$bedId]) : null;
    if (!$bed) {
        $errors['bed_id'] = 'Select a free bed.';
    } elseif ((int) $bed['room_id'] !== (int) $room['id']) {
        $errors['bed_id'] = 'The selected bed is not in Room ' . $room['room_no'] . '.';
    } elseif ($bed['status'] !== 'available' && (int) $bed['id'] !== (int) $ignoreBedId) {
        $errors['bed_id'] = 'Bed ' . $bed['bed_no'] . ' is ' . ($bed['status'] === 'maintenance' ? 'under maintenance' : 'already occupied') . '. Pick a free bed.';
    } elseif ((int) $bed['id'] === (int) $ignoreBedId) {
        $errors['bed_id'] = 'The student already occupies this bed. Pick a different bed.';
    }
    return $errors;
}

/** Validation for a new allocation (CRUD validate hook). */
function hostel_validate_allocation(array $data): array
{
    $errors = [];
    $sid = (int) ($data['student_id'] ?? 0);
    $student = $sid ? db_row('SELECT id, first_name, last_name, gender, status FROM students WHERE id = ?', [$sid]) : null;
    if (!$student) {
        return ['student_id' => 'Select a student.'];
    }
    if ($student['status'] !== 'active') {
        $errors['student_id'] = 'Only active students can be allotted a hostel room.';
    } elseif ($cur = hostel_active_allocation($sid)) {
        $errors['student_id'] = sprintf('%s already stays in %s Room %s. Use "Transfer" to change the room.', hostel_student_name($student), $cur['hostel_code'], $cur['room_no']);
    }
    $errors += hostel_validate_target($student, !empty($data['hostel_id']) ? (int) $data['hostel_id'] : null, !empty($data['room_id']) ? (int) $data['room_id'] : null,
        !empty($data['bed_id']) ? (int) $data['bed_id'] : null);
    $from = $data['allocated_on'] ?? null;
    if ($from && $from > date('Y-m-d', strtotime('+90 days'))) {
        $errors['allocated_on'] = 'Allotment date cannot be more than 90 days ahead.';
    }
    if ($from && !empty($data['valid_until']) && $data['valid_until'] < $from) {
        $errors['valid_until'] = 'Valid until must be on or after the allotment date.';
    }
    if (isset($data['fee_amount']) && $data['fee_amount'] !== null && (float) $data['fee_amount'] < 0) {
        $errors['fee_amount'] = 'Rent cannot be negative.';
    }
    return $errors;
}

/** Fill derived columns of a new allocation (CRUD before_save hook). */
function hostel_prepare_allocation(array $data): array
{
    $room = hostel_room_row((int) $data['room_id']);
    $data['hostel_id'] = (int) $room['hostel_id'];
    $data['academic_session_id'] = $data['academic_session_id'] ?? current_session_id();
    $data['allocation_no'] = hostel_next_allocation_no($data['academic_session_id'] ? (int) $data['academic_session_id'] : null);
    $data['allocated_by'] = user_id();
    $data['status'] = 'active';
    $data['vacated_on'] = null;
    if (!isset($data['fee_amount']) || $data['fee_amount'] === null || $data['fee_amount'] === '') {
        $data['fee_amount'] = $room['annual_fee'] !== null ? (float) $room['annual_fee'] : ($room['hostel_fee'] !== null ? (float) $room['hostel_fee'] : null);
    }
    if (empty($data['valid_until'])) {
        $data['valid_until'] = db_value('SELECT end_date FROM academic_sessions WHERE id = ?', [$data['academic_session_id']]) ?: null;
    }
    return $data;
}

/** Mark the bed occupied, resync the room and flag the student as a hosteller (inside the save transaction). */
function hostel_occupy(int $allocationId): void
{
    $a = db_row('SELECT * FROM hostel_allocations WHERE id = ?', [$allocationId]);
    if (!$a) {
        return;
    }
    if ($a['bed_id']) {
        $n = db_exec("UPDATE hostel_beds SET status = 'occupied' WHERE id = ? AND status = 'available'", [(int) $a['bed_id']]);
        if (!$n) {
            throw new CrudValidationException(['bed_id' => 'This bed was just allotted to someone else or is under maintenance. Pick another bed.']);
        }
    }
    hostel_sync_room((int) $a['room_id']);
    db_exec('UPDATE students SET is_hosteller = 1 WHERE id = ? AND is_hosteller = 0', [(int) $a['student_id']]);
}

/** Free the bed of an allocation and resync. */
function hostel_release_bed(array $a): void
{
    if (!empty($a['bed_id'])) {
        $others = (int) db_value("SELECT COUNT(*) FROM hostel_allocations WHERE bed_id = ? AND status = 'active' AND id <> ?", [(int) $a['bed_id'], (int) $a['id']]);
        if (!$others) {
            db_exec("UPDATE hostel_beds SET status = 'available' WHERE id = ? AND status = 'occupied'", [(int) $a['bed_id']]);
        }
    }
    hostel_sync_room((int) $a['room_id']);
}

function hostel_sync_student_flag(int $studentId): void
{
    $active = (int) db_value("SELECT COUNT(*) FROM hostel_allocations WHERE student_id = ? AND status = 'active'", [$studentId]);
    db_exec('UPDATE students SET is_hosteller = ? WHERE id = ?', [$active ? 1 : 0, $studentId]);
}

function hostel_describe_allocation(array $a): string
{
    $name = hostel_student_name($a);
    if ($name === '' && !empty($a['student_id'])) {
        $s = db_row('SELECT first_name, last_name FROM students WHERE id = ?', [(int) $a['student_id']]);
        $name = $s ? hostel_student_name($s) : '';
    }
    $room = $a['room_no'] ?? db_value('SELECT CONCAT(h.code, \' Room \', r.room_no) FROM hostel_rooms r JOIN hostels h ON h.id = r.hostel_id WHERE r.id = ?', [(int) ($a['room_id'] ?? 0)]);
    return trim(($a['allocation_no'] ?? '#' . ($a['id'] ?? '')) . ' — ' . $name . ($room ? ' (' . (isset($a['hostel_code'], $a['room_no']) ? $a['hostel_code'] . ' Room ' . $a['room_no'] : $room) . ')' : ''));
}

/** Validate a date input (Y-m-d). */
function hostel_date(?string $value, string $field, string $label, bool $required = true): ?string
{
    $value = $value !== null ? trim($value) : null;
    if ($value === null || $value === '') {
        if ($required) {
            throw new CrudValidationException([$field => "$label is required."]);
        }
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        throw new CrudValidationException([$field => "$label must be a valid date."]);
    }
    return $value;
}

/** Vacate (check out) an active allocation. */
function hostel_vacate(int $id, array $input): array
{
    $a = hostel_allocation_row($id);
    if (!$a) {
        throw new CrudException('Allocation not found. It may have been deleted.');
    }
    if ($a['status'] !== 'active') {
        throw new CrudException('Only active allocations can be vacated (this one is ' . $a['status'] . ').');
    }
    $date = hostel_date($input['vacated_on'] ?? date('Y-m-d'), 'vacated_on', 'Vacating date');
    if ($date < $a['allocated_on']) {
        throw new CrudValidationException(['vacated_on' => 'Vacating date cannot be before the allotment date (' . format_date($a['allocated_on']) . ').']);
    }
    if ($date > date('Y-m-d', strtotime('+30 days'))) {
        throw new CrudValidationException(['vacated_on' => 'Vacating date cannot be more than 30 days ahead.']);
    }
    $reason = trim((string) ($input['reason'] ?? ''));
    if (mb_strlen($reason) > 255) {
        throw new CrudValidationException(['reason' => 'Reason may not be longer than 255 characters.']);
    }
    db_transaction(function () use ($a, $date, $reason) {
        db_update('hostel_allocations', ['status' => 'vacated', 'vacated_on' => $date, 'vacate_reason' => $reason ?: null, 'vacated_by' => user_id()], 'id = ?', [(int) $a['id']]);
        hostel_release_bed($a);
        hostel_sync_student_flag((int) $a['student_id']);
    });
    log_activity('update', 'hostel', $a['id'], sprintf('Vacated %s Room %s (Bed %s) for %s on %s%s', $a['hostel_code'], $a['room_no'], $a['bed_no'] ?? '-', hostel_student_name($a), format_date($date), $reason ? " — $reason" : ''));
    return hostel_allocation_row($id);
}

/** Cancel an allocation made by mistake: frees the bed and optionally cancels the unpaid hostel invoice. */
function hostel_cancel(int $id, array $input): array
{
    $a = hostel_allocation_row($id);
    if (!$a) {
        throw new CrudException('Allocation not found. It may have been deleted.');
    }
    if ($a['status'] !== 'active') {
        throw new CrudException('Only active allocations can be cancelled.');
    }
    $reason = trim((string) ($input['reason'] ?? ''));
    if (mb_strlen($reason) < 5) {
        throw new CrudValidationException(['reason' => 'Give a short reason for the cancellation (at least 5 characters).']);
    }
    $cancelInvoice = !empty($input['cancel_invoice']);
    if ($cancelInvoice && $a['student_fee_id'] && (float) $a['invoice_paid'] > 0) {
        throw new CrudValidationException(['cancel_invoice' => 'Invoice ' . $a['invoice_no'] . ' already has payments. Refund through Fees & Accounts instead.']);
    }
    db_transaction(function () use ($a, $reason, $cancelInvoice) {
        db_update('hostel_allocations', ['status' => 'cancelled', 'vacated_on' => date('Y-m-d'), 'vacate_reason' => mb_substr('Cancelled: ' . $reason, 0, 255), 'vacated_by' => user_id()], 'id = ?', [(int) $a['id']]);
        hostel_release_bed($a);
        hostel_sync_student_flag((int) $a['student_id']);
        if ($cancelInvoice && $a['student_fee_id'] && !in_array($a['invoice_status'], ['cancelled', 'paid'], true)) {
            db_update('student_fees', ['status' => 'cancelled', 'cancelled_reason' => mb_substr('Hostel allocation cancelled: ' . $reason, 0, 255), 'cancelled_by' => user_id(), 'cancelled_at' => date('Y-m-d H:i:s')],
                'id = ?', [(int) $a['student_fee_id']]);
        }
    });
    log_activity('update', 'hostel', $a['id'], sprintf('Cancelled allocation %s for %s (%s Room %s)%s — %s', $a['allocation_no'], hostel_student_name($a), $a['hostel_code'], $a['room_no'],
        $cancelInvoice && $a['invoice_no'] ? ' and invoice ' . $a['invoice_no'] : '', $reason));
    return hostel_allocation_row($id);
}

/** Move a student to another room / bed (old allocation becomes "transferred", a new active one is created). */
function hostel_transfer(int $id, array $input): array
{
    $a = hostel_allocation_row($id);
    if (!$a) {
        throw new CrudException('Allocation not found. It may have been deleted.');
    }
    if ($a['status'] !== 'active') {
        throw new CrudException('Only active allocations can be transferred.');
    }
    $student = ['first_name' => $a['first_name'], 'last_name' => $a['last_name'], 'gender' => $a['gender']];
    $roomId = (int) ($input['room_id'] ?? 0);
    $bedId = (int) ($input['bed_id'] ?? 0);
    $errors = hostel_validate_target($student, !empty($input['hostel_id']) ? (int) $input['hostel_id'] : null, $roomId ?: null, $bedId ?: null, $a['bed_id'] ? (int) $a['bed_id'] : null);
    $date = null;
    try {
        $date = hostel_date($input['transfer_date'] ?? date('Y-m-d'), 'transfer_date', 'Transfer date');
    } catch (CrudValidationException $e) {
        $errors += $e->errors;
    }
    if ($date && $date < $a['allocated_on']) {
        $errors['transfer_date'] = 'Transfer date cannot be before the current allotment date (' . format_date($a['allocated_on']) . ').';
    }
    $reason = trim((string) ($input['reason'] ?? ''));
    if ($reason === '') {
        $errors['reason'] = 'Reason for the room change is required.';
    } elseif (mb_strlen($reason) > 200) {
        $errors['reason'] = 'Reason may not be longer than 200 characters.';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $room = hostel_room_row($roomId);
    $rent = !empty($input['update_rent']) && $room['annual_fee'] !== null ? (float) $room['annual_fee'] : ($a['fee_amount'] !== null ? (float) $a['fee_amount'] : null);
    $newId = db_transaction(function () use ($a, $room, $bedId, $date, $reason, $rent) {
        db_update('hostel_allocations', ['status' => 'transferred', 'vacated_on' => $date, 'vacate_reason' => mb_substr('Transferred: ' . $reason, 0, 255), 'vacated_by' => user_id()], 'id = ?', [(int) $a['id']]);
        hostel_release_bed($a);
        $newId = db_insert('hostel_allocations', [
            'allocation_no' => hostel_next_allocation_no($a['academic_session_id'] ? (int) $a['academic_session_id'] : null),
            'student_id' => (int) $a['student_id'], 'hostel_id' => (int) $room['hostel_id'], 'room_id' => (int) $room['id'], 'bed_id' => $bedId,
            'transferred_from_id' => (int) $a['id'], 'academic_session_id' => $a['academic_session_id'], 'allocated_on' => $date, 'valid_until' => $a['valid_until'],
            'fee_amount' => $rent, 'student_fee_id' => $a['student_fee_id'], 'status' => 'active', 'remarks' => mb_substr('Room change from ' . $a['hostel_code'] . ' ' . $a['room_no'] . ': ' . $reason, 0, 255),
            'allocated_by' => user_id(),
        ]);
        hostel_occupy($newId);
        return $newId;
    });
    log_activity('update', 'hostel', $newId, sprintf('Transferred %s from %s Room %s (Bed %s) to %s Room %s (Bed %s) — %s', hostel_student_name($a), $a['hostel_code'], $a['room_no'], $a['bed_no'] ?? '-',
        $room['hostel_code'], $room['room_no'], db_value('SELECT bed_no FROM hostel_beds WHERE id = ?', [$bedId]), $reason));
    return hostel_allocation_row((int) $newId);
}

/** Next fee invoice number (same series as Fees & Accounts). */
function hostel_next_invoice_no(): string
{
    $pattern = (string) setting('invoice_prefix', 'INV/{session}/{n:6}');
    do {
        $no = next_number('invoice', $pattern);
    } while (db_value('SELECT COUNT(*) FROM student_fees WHERE invoice_no = ?', [$no]));
    return $no;
}

/** The fee head used for hostel invoices (type = hostel). */
function hostel_fee_head_id(): ?int
{
    $id = db_value("SELECT id FROM fee_heads WHERE type = 'hostel' AND status = 'active' ORDER BY (code = 'HST') DESC, sort_order, id LIMIT 1");
    return $id ? (int) $id : null;
}

/** Raise a hostel fee invoice (student_fees + student_fee_items with the hostel fee head) for an allocation. */
function hostel_raise_invoice(int $allocationId, array $input = []): array
{
    $a = hostel_allocation_row($allocationId);
    if (!$a) {
        throw new CrudException('Allocation not found. It may have been deleted.');
    }
    if (in_array($a['status'], ['cancelled'], true)) {
        throw new CrudException('Cannot invoice a cancelled allocation.');
    }
    if ($a['student_fee_id'] && $a['invoice_status'] !== 'cancelled') {
        throw new CrudException('Invoice ' . $a['invoice_no'] . ' has already been raised for this allocation.');
    }
    $headId = hostel_fee_head_id();
    if (!$headId) {
        throw new CrudException('No active fee head of type "Hostel" exists. Add one under Fees & Accounts first.');
    }
    $includeMess = array_key_exists('include_mess', $input) ? !empty($input['include_mess']) : true;
    $default = round((float) $a['fee_amount'] + ($includeMess ? (float) $a['mess_fee'] : 0), 2);
    $amount = isset($input['amount']) && $input['amount'] !== '' && $input['amount'] !== null ? $input['amount'] : $default;
    if (!is_numeric($amount) || (float) $amount <= 0) {
        throw new CrudValidationException(['amount' => 'Invoice amount must be greater than zero.']);
    }
    if ((float) $amount > 1000000) {
        throw new CrudValidationException(['amount' => 'Invoice amount looks too high (max ₹10,00,000).']);
    }
    $amount = round((float) $amount, 2);
    $due = !empty($input['due_date']) ? hostel_date((string) $input['due_date'], 'due_date', 'Due date') : date('Y-m-d', max(strtotime($a['allocated_on'] . ' +15 days'), strtotime('+15 days')));
    $sessionName = $a['session_name'] ?: hostel_session_name(null);
    $invoiceNo = hostel_next_invoice_no();
    $feeId = db_transaction(function () use ($a, $headId, $amount, $due, $sessionName, $invoiceNo) {
        $feeId = db_insert('student_fees', [
            'invoice_no' => $invoiceNo, 'student_id' => (int) $a['student_id'], 'academic_session_id' => $a['academic_session_id'],
            'title' => mb_substr('Hostel Fee ' . $sessionName . ' — ' . $a['hostel_code'] . ' Room ' . $a['room_no'], 0, 190), 'semester_no' => null,
            'gross_amount' => $amount, 'discount_amount' => 0, 'scholarship_amount' => 0, 'fine_amount' => 0, 'net_amount' => $amount, 'paid_amount' => 0,
            'due_date' => $due, 'status' => $due < date('Y-m-d') ? 'overdue' : 'pending', 'remarks' => 'Hostel fee — ' . $a['allocation_no'], 'created_by' => user_id(),
        ]);
        db_insert('student_fee_items', ['student_fee_id' => $feeId, 'fee_head_id' => $headId, 'amount' => $amount]);
        db_update('hostel_allocations', ['student_fee_id' => $feeId], 'id = ?', [(int) $a['id']]);
        return $feeId;
    });
    log_activity('create', 'hostel', $a['id'], sprintf('Raised hostel fee invoice %s of %s for %s (%s)', $invoiceNo, money($amount), hostel_student_name($a), $a['allocation_no']));
    notify('perm:fees', 'fee', 'Hostel fee invoice raised', sprintf('%s · %s · %s', $invoiceNo, hostel_student_name($a), money($amount)), 'admin/fees/invoices?q=' . rawurlencode($invoiceNo), 'receipt');
    return ['id' => (int) $feeId, 'invoice_no' => $invoiceNo, 'amount' => $amount, 'due_date' => $due];
}

/**
 * Outstanding hostel fees: balance of open invoices, apportioned to their hostel-type items
 * (works for pure hostel invoices and for combined invoices raised by Fees & Accounts).
 */
function hostel_pending_fees(): array
{
    $r = db_row("SELECT COALESCE(SUM(sf.balance_amount * LEAST(1, x.hostel_amount / NULLIF(sf.gross_amount, 0))), 0) AS amount, COUNT(DISTINCT sf.student_id) AS students,
                        COALESCE(SUM(CASE WHEN sf.due_date < CURDATE() THEN sf.balance_amount * LEAST(1, x.hostel_amount / NULLIF(sf.gross_amount, 0)) ELSE 0 END), 0) AS overdue
                 FROM student_fees sf
                 JOIN (SELECT i.student_fee_id, SUM(i.amount) AS hostel_amount FROM student_fee_items i JOIN fee_heads fh ON fh.id = i.fee_head_id WHERE fh.type = 'hostel' GROUP BY i.student_fee_id) x
                   ON x.student_fee_id = sf.id
                 WHERE sf.status NOT IN ('paid', 'cancelled', 'waived') AND sf.balance_amount > 0") ?? [];
    $billed = (float) db_value("SELECT COALESCE(SUM(i.amount), 0) FROM student_fee_items i JOIN fee_heads fh ON fh.id = i.fee_head_id JOIN student_fees sf ON sf.id = i.student_fee_id
                                WHERE fh.type = 'hostel' AND sf.status NOT IN ('cancelled', 'waived') AND (sf.academic_session_id = ? OR ? IS NULL)", [current_session_id(), current_session_id()]);
    return ['amount' => round((float) ($r['amount'] ?? 0), 2), 'students' => (int) ($r['students'] ?? 0), 'overdue' => round((float) ($r['overdue'] ?? 0), 2), 'billed' => round($billed, 2)];
}

/* ------------------------------------------------------------------
 * Complaints & visitors
 * ------------------------------------------------------------------ */

function hostel_next_complaint_no(): string
{
    $s = hostel_session_name(null);
    do {
        $no = next_number('hostel_complaint:' . $s, 'HC/' . $s . '/{n:4}');
    } while (db_value('SELECT COUNT(*) FROM hostel_complaints WHERE complaint_no = ?', [$no]));
    return $no;
}

function hostel_next_pass_no(): string
{
    $s = hostel_session_name(null);
    do {
        $no = next_number('hostel_visitor:' . $s, 'VP/' . $s . '/{n:5}');
    } while (db_value('SELECT COUNT(*) FROM hostel_visitors WHERE pass_no = ?', [$no]));
    return $no;
}

function hostel_staff_name(int $staffId): ?string
{
    $s = db_row('SELECT first_name, last_name, designation FROM staff WHERE id = ?', [$staffId]);
    return $s ? trim($s['first_name'] . ' ' . $s['last_name']) . ($s['designation'] ? ' (' . $s['designation'] . ')' : '') : null;
}

/** Complaint workflow transition: assign | start | resolve | close | reopen. */
function hostel_complaint_action(int $id, string $action, array $input): array
{
    $c = db_row('SELECT c.*, h.code AS hostel_code FROM hostel_complaints c JOIN hostels h ON h.id = c.hostel_id WHERE c.id = ?', [$id]);
    if (!$c) {
        throw new CrudException('Complaint not found. It may have been deleted.');
    }
    $now = date('Y-m-d H:i:s');
    $upd = [];
    $note = trim((string) ($input['remarks'] ?? ''));
    switch ($action) {
        case 'assign':
            $staffId = (int) ($input['assigned_staff_id'] ?? 0);
            $name = $staffId ? hostel_staff_name($staffId) : null;
            if (!$name) {
                throw new CrudValidationException(['assigned_staff_id' => 'Select the staff member to assign.']);
            }
            if (in_array($c['status'], ['resolved', 'closed'], true)) {
                throw new CrudException('This complaint is already ' . $c['status'] . '. Reopen it to reassign.');
            }
            $upd = ['assigned_staff_id' => $staffId, 'assigned_to' => mb_substr($name, 0, 150), 'assigned_at' => $now];
            if (!empty($input['start'])) {
                $upd['status'] = 'in_progress';
            }
            $msg = 'Assigned complaint ' . $c['complaint_no'] . ' to ' . $name;
            break;
        case 'start':
            if ($c['status'] !== 'open') {
                throw new CrudException('Only open complaints can be moved to In progress.');
            }
            $upd = ['status' => 'in_progress'];
            $msg = 'Started work on complaint ' . $c['complaint_no'];
            break;
        case 'resolve':
            if (!in_array($c['status'], ['open', 'in_progress'], true)) {
                throw new CrudException('Only open or in-progress complaints can be resolved.');
            }
            $resolution = trim((string) ($input['resolution'] ?? ''));
            if (mb_strlen($resolution) < 5) {
                throw new CrudValidationException(['resolution' => 'Describe what was done to resolve the complaint (at least 5 characters).']);
            }
            $upd = ['status' => 'resolved', 'resolution' => mb_substr($resolution, 0, 500), 'resolved_at' => $now];
            $msg = 'Resolved complaint ' . $c['complaint_no'] . ': ' . str_limit($resolution, 120);
            break;
        case 'close':
            if ($c['status'] !== 'resolved') {
                throw new CrudException('Resolve the complaint before closing it.');
            }
            $upd = ['status' => 'closed', 'closed_at' => $now];
            $msg = 'Closed complaint ' . $c['complaint_no'];
            break;
        case 'reopen':
            if (!in_array($c['status'], ['resolved', 'closed'], true)) {
                throw new CrudException('Only resolved or closed complaints can be reopened.');
            }
            if (mb_strlen($note) < 5) {
                throw new CrudValidationException(['remarks' => 'Tell us why the complaint is being reopened (at least 5 characters).']);
            }
            $upd = ['status' => 'open', 'resolved_at' => null, 'closed_at' => null,
                'description' => mb_substr(trim(($c['description'] ? $c['description'] . "\n\n" : '') . 'Reopened on ' . date('d M Y, h:i A') . ': ' . $note), 0, 2000)];
            if ($c['resolution']) {
                $upd['resolution'] = mb_substr('Earlier fix: ' . $c['resolution'], 0, 500);
            }
            $msg = 'Reopened complaint ' . $c['complaint_no'] . ': ' . str_limit($note, 120);
            break;
        default:
            throw new InvalidArgumentException('Unknown complaint action.');
    }
    db_update('hostel_complaints', $upd, 'id = ?', [$id]);
    log_activity('update', 'hostel', $id, $msg . ' (' . $c['hostel_code'] . ')');
    return db_row('SELECT * FROM hostel_complaints WHERE id = ?', [$id]);
}

/** Visitor check-out. */
function hostel_visitor_checkout(int $id, array $input): array
{
    $v = db_row('SELECT v.*, h.code AS hostel_code FROM hostel_visitors v JOIN hostels h ON h.id = v.hostel_id WHERE v.id = ?', [$id]);
    if (!$v) {
        throw new CrudException('Visitor entry not found. It may have been deleted.');
    }
    if ($v['check_out']) {
        throw new CrudException($v['visitor_name'] . ' was already checked out at ' . format_datetime($v['check_out']) . '.');
    }
    $out = date('Y-m-d H:i:s');
    if (!empty($input['check_out'])) {
        $ts = strtotime(str_replace('T', ' ', (string) $input['check_out']));
        if (!$ts) {
            throw new CrudValidationException(['check_out' => 'Check-out time must be a valid date & time.']);
        }
        $out = date('Y-m-d H:i:s', $ts);
    }
    if ($out < $v['check_in']) {
        throw new CrudValidationException(['check_out' => 'Check-out cannot be before check-in (' . format_datetime($v['check_in']) . ').']);
    }
    if (strtotime($out) > time() + 300) {
        throw new CrudValidationException(['check_out' => 'Check-out time cannot be in the future.']);
    }
    $upd = ['check_out' => $out];
    $remarks = trim((string) ($input['remarks'] ?? ''));
    if ($remarks !== '') {
        $upd['remarks'] = mb_substr(trim(($v['remarks'] ? $v['remarks'] . ' · ' : '') . $remarks), 0, 255);
    }
    db_update('hostel_visitors', $upd, 'id = ?', [$id]);
    log_activity('update', 'hostel', $id, sprintf('Checked out visitor %s (%s) at %s', $v['visitor_name'], $v['pass_no'] ?? $v['hostel_code'], date('h:i A', strtotime($out))));
    return db_row('SELECT * FROM hostel_visitors WHERE id = ?', [$id]);
}

/** Mask an ID proof number, keeping only the last 4 characters (privacy). */
function hostel_mask_id(?string $value): ?string
{
    $v = preg_replace('/\s+/', '', (string) $value);
    if ($v === '') {
        return null;
    }
    if (preg_match('/^X+[-X]*\w{0,4}$/i', $v) || str_starts_with($v, 'XXXX')) {
        return mb_substr((string) $value, 0, 60);
    }
    $last = mb_substr($v, -4);
    return 'XXXX-' . $last;
}

/* ------------------------------------------------------------------
 * Dashboard / grid / availability data
 * ------------------------------------------------------------------ */

/** Per-hostel stats (rooms, beds, occupancy, complaints). */
function hostel_overview(): array
{
    $rows = db_all("SELECT h.id, h.name, h.code, h.type, h.status, h.image, h.warden_name, h.warden_phone, h.total_floors, h.annual_fee, h.mess_fee, h.amenities, h.address, h.visiting_hours,
                           (SELECT COUNT(*) FROM hostel_blocks b WHERE b.hostel_id = h.id) AS blocks,
                           (SELECT COUNT(*) FROM hostel_rooms r WHERE r.hostel_id = h.id) AS rooms,
                           (SELECT COUNT(*) FROM hostel_rooms r WHERE r.hostel_id = h.id AND r.occupied > 0) AS rooms_occupied,
                           (SELECT COUNT(*) FROM hostel_rooms r WHERE r.hostel_id = h.id AND r.status = 'maintenance') AS rooms_maintenance,
                           (SELECT COUNT(*) FROM hostel_beds hb JOIN hostel_rooms r ON r.id = hb.room_id WHERE r.hostel_id = h.id) AS beds,
                           (SELECT COUNT(*) FROM hostel_beds hb JOIN hostel_rooms r ON r.id = hb.room_id WHERE r.hostel_id = h.id AND hb.status = 'occupied') AS occupied,
                           (SELECT COUNT(*) FROM hostel_beds hb JOIN hostel_rooms r ON r.id = hb.room_id WHERE r.hostel_id = h.id AND (hb.status = 'maintenance' OR r.status IN ('maintenance', 'inactive')) AND hb.status <> 'occupied') AS maintenance,
                           (SELECT COUNT(*) FROM hostel_complaints c WHERE c.hostel_id = h.id AND c.status IN ('open', 'in_progress')) AS open_complaints,
                           (SELECT COUNT(*) FROM hostel_visitors v WHERE v.hostel_id = h.id AND v.check_out IS NULL) AS visitors_inside
                    FROM hostels h ORDER BY h.status = 'active' DESC, h.type, h.name");
    foreach ($rows as &$r) {
        foreach (['id', 'blocks', 'rooms', 'rooms_occupied', 'rooms_maintenance', 'beds', 'occupied', 'maintenance', 'open_complaints', 'visitors_inside', 'total_floors'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['available'] = max(0, $r['beds'] - $r['occupied'] - $r['maintenance']);
        $r['occupancy_pct'] = $r['beds'] ? round($r['occupied'] * 100 / $r['beds'], 1) : 0;
        $r['amenities'] = $r['amenities'] ? (json_decode($r['amenities'], true) ?: array_filter(explode(',', $r['amenities']))) : [];
        $r['annual_fee'] = $r['annual_fee'] !== null ? (float) $r['annual_fee'] : null;
        $r['mess_fee'] = $r['mess_fee'] !== null ? (float) $r['mess_fee'] : null;
    }
    return $rows;
}

function hostel_dashboard(): array
{
    $hostels = hostel_overview();
    $rooms = db_row("SELECT COUNT(*) AS total, COALESCE(SUM(occupied > 0), 0) AS occupied, COALESCE(SUM(occupied >= capacity AND capacity > 0), 0) AS full,
                            COALESCE(SUM(occupied = 0 AND status NOT IN ('maintenance', 'inactive')), 0) AS vacant, COALESCE(SUM(status = 'maintenance'), 0) AS maintenance FROM hostel_rooms") ?? [];
    $beds = array_sum(array_column($hostels, 'beds'));
    $occupied = array_sum(array_column($hostels, 'occupied'));
    $maint = array_sum(array_column($hostels, 'maintenance'));
    $students = db_row("SELECT COUNT(*) AS total, COALESCE(SUM(s.gender = 'male'), 0) AS boys, COALESCE(SUM(s.gender = 'female'), 0) AS girls
                        FROM hostel_allocations ha JOIN students s ON s.id = ha.student_id WHERE ha.status = 'active'") ?? [];
    $complaints = db_row("SELECT COALESCE(SUM(status = 'open'), 0) AS open, COALESCE(SUM(status = 'in_progress'), 0) AS in_progress,
                                 COALESCE(SUM(status IN ('open', 'in_progress') AND priority IN ('high', 'urgent')), 0) AS urgent,
                                 COALESCE(SUM(status IN ('resolved', 'closed') AND resolved_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')), 0) AS resolved_month,
                                 AVG(CASE WHEN resolved_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) END) AS avg_hours
                          FROM hostel_complaints") ?? [];
    $visitors = db_row('SELECT COALESCE(SUM(DATE(check_in) = CURDATE()), 0) AS today, COALESCE(SUM(check_out IS NULL), 0) AS inside FROM hostel_visitors') ?? [];
    $fees = hostel_pending_fees();

    $byType = db_all("SELECT r.room_type, COUNT(DISTINCT r.id) AS rooms, COUNT(hb.id) AS beds, COALESCE(SUM(hb.status = 'occupied'), 0) AS occupied
                      FROM hostel_rooms r LEFT JOIN hostel_beds hb ON hb.room_id = r.id GROUP BY r.room_type ORDER BY FIELD(r.room_type, 'single', 'double', 'triple', 'quad', 'dormitory')");
    $categories = db_all("SELECT category, COUNT(*) AS total, COALESCE(SUM(status IN ('open', 'in_progress')), 0) AS open FROM hostel_complaints
                          WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY category ORDER BY total DESC");
    $trend = db_all("SELECT DATE_FORMAT(m.d, '%Y-%m') AS ym,
                            (SELECT COUNT(*) FROM hostel_allocations a WHERE DATE_FORMAT(a.allocated_on, '%Y-%m') = DATE_FORMAT(m.d, '%Y-%m') AND a.transferred_from_id IS NULL AND a.status <> 'cancelled') AS allocated,
                            (SELECT COUNT(*) FROM hostel_allocations a WHERE a.status = 'vacated' AND DATE_FORMAT(a.vacated_on, '%Y-%m') = DATE_FORMAT(m.d, '%Y-%m')) AS vacated,
                            (SELECT COUNT(*) FROM hostel_allocations a WHERE a.status = 'transferred' AND DATE_FORMAT(a.vacated_on, '%Y-%m') = DATE_FORMAT(m.d, '%Y-%m')) AS transferred
                     FROM (SELECT DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL n MONTH) AS d FROM
                            (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5) k) m ORDER BY ym");
    $recentComplaints = db_all("SELECT c.id, c.complaint_no, c.subject, c.category, c.priority, c.status, c.created_at, c.assigned_to, h.code AS hostel_code, r.room_no,
                                       TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.photo
                                FROM hostel_complaints c JOIN hostels h ON h.id = c.hostel_id LEFT JOIN hostel_rooms r ON r.id = c.room_id LEFT JOIN students s ON s.id = c.student_id
                                ORDER BY (c.status IN ('open', 'in_progress')) DESC, FIELD(c.priority, 'urgent', 'high', 'medium', 'low'), c.created_at DESC LIMIT 6");
    $visitorsToday = db_all("SELECT v.id, v.pass_no, v.visitor_name, v.relation, v.purpose, v.check_in, v.check_out, v.visitors_count, h.code AS hostel_code,
                                    TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.photo, s.student_uid
                             FROM hostel_visitors v JOIN hostels h ON h.id = v.hostel_id JOIN students s ON s.id = v.student_id
                             WHERE DATE(v.check_in) = CURDATE() OR v.check_out IS NULL ORDER BY v.check_out IS NULL DESC, v.check_in DESC LIMIT 8");
    $recentAllocations = db_all("SELECT ha.id, ha.allocation_no, ha.allocated_on, ha.status, ha.transferred_from_id, h.code AS hostel_code, r.room_no, hb.bed_no,
                                        TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.photo, s.student_uid, p.short_name AS program_short
                                 FROM hostel_allocations ha JOIN students s ON s.id = ha.student_id LEFT JOIN programs p ON p.id = s.program_id JOIN hostels h ON h.id = ha.hostel_id
                                 JOIN hostel_rooms r ON r.id = ha.room_id LEFT JOIN hostel_beds hb ON hb.id = ha.bed_id
                                 WHERE ha.status = 'active' ORDER BY ha.allocated_on DESC, ha.id DESC LIMIT 6");
    return [
        'kpis' => [
            'rooms' => (int) ($rooms['total'] ?? 0), 'rooms_occupied' => (int) ($rooms['occupied'] ?? 0), 'rooms_full' => (int) ($rooms['full'] ?? 0),
            'rooms_vacant' => (int) ($rooms['vacant'] ?? 0), 'rooms_maintenance' => (int) ($rooms['maintenance'] ?? 0),
            'beds' => $beds, 'beds_occupied' => $occupied, 'beds_available' => max(0, $beds - $occupied - $maint), 'beds_maintenance' => $maint,
            'occupancy_pct' => $beds ? round($occupied * 100 / $beds, 1) : 0,
            'students' => (int) ($students['total'] ?? 0), 'boys' => (int) ($students['boys'] ?? 0), 'girls' => (int) ($students['girls'] ?? 0),
            'pending_fees' => $fees['amount'], 'pending_fee_students' => $fees['students'], 'overdue_fees' => $fees['overdue'], 'billed_fees' => $fees['billed'],
            'open_complaints' => (int) ($complaints['open'] ?? 0) + (int) ($complaints['in_progress'] ?? 0), 'complaints_open' => (int) ($complaints['open'] ?? 0),
            'complaints_in_progress' => (int) ($complaints['in_progress'] ?? 0), 'urgent_complaints' => (int) ($complaints['urgent'] ?? 0),
            'resolved_this_month' => (int) ($complaints['resolved_month'] ?? 0), 'avg_resolution_hours' => $complaints['avg_hours'] !== null ? round((float) $complaints['avg_hours'], 1) : null,
            'visitors_today' => (int) ($visitors['today'] ?? 0), 'visitors_inside' => (int) ($visitors['inside'] ?? 0),
        ],
        'hostels' => $hostels,
        'room_types' => array_map(fn ($r) => ['room_type' => $r['room_type'], 'label' => hostel_room_types()[$r['room_type']] ?? label_from_key($r['room_type']), 'rooms' => (int) $r['rooms'], 'beds' => (int) $r['beds'], 'occupied' => (int) $r['occupied']], $byType),
        'complaint_categories' => array_map(fn ($r) => ['category' => $r['category'], 'label' => hostel_complaint_categories()[$r['category']] ?? label_from_key($r['category']), 'total' => (int) $r['total'], 'open' => (int) $r['open']], $categories),
        'trend' => array_map(fn ($r) => ['month' => $r['ym'], 'label' => date('M Y', strtotime($r['ym'] . '-01')), 'allocated' => (int) $r['allocated'], 'vacated' => (int) $r['vacated'], 'transferred' => (int) $r['transferred']], $trend),
        'recent_complaints' => $recentComplaints,
        'visitors_today' => $visitorsToday,
        'recent_allocations' => $recentAllocations,
    ];
}

/**
 * Room grid of a hostel: blocks, rooms with beds and current occupants.
 * $onlyFree = rooms that can take a new resident (allocation / transfer picker).
 */
function hostel_grid(int $hostelId, bool $onlyFree = false): array
{
    $hostel = db_row('SELECT id, name, code, type, status, warden_name, warden_phone, total_floors, annual_fee, mess_fee FROM hostels WHERE id = ?', [$hostelId]);
    if (!$hostel) {
        throw new CrudException('Hostel not found.');
    }
    $blocks = db_all('SELECT b.id, b.name, b.floors, b.status, (SELECT COUNT(*) FROM hostel_rooms r WHERE r.block_id = b.id) AS rooms FROM hostel_blocks b WHERE b.hostel_id = ? ORDER BY b.name', [$hostelId]);
    $rooms = db_all("SELECT r.id, r.block_id, r.floor_no, r.room_no, r.room_type, r.capacity, r.occupied, r.is_ac, r.annual_fee, r.amenities, r.status
                     FROM hostel_rooms r WHERE r.hostel_id = ?" . ($onlyFree ? " AND r.status NOT IN ('maintenance', 'inactive') AND EXISTS (SELECT 1 FROM hostel_beds hb WHERE hb.room_id = r.id AND hb.status = 'available')" : '') . "
                     ORDER BY r.floor_no, LENGTH(r.room_no), r.room_no", [$hostelId]);
    $beds = db_all("SELECT hb.id, hb.room_id, hb.bed_no, hb.status, ha.id AS allocation_id, ha.allocation_no, ha.allocated_on, s.id AS student_id, s.student_uid, s.photo, s.gender, s.current_semester,
                           TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, p.short_name AS program_short
                    FROM hostel_beds hb JOIN hostel_rooms r ON r.id = hb.room_id
                    LEFT JOIN hostel_allocations ha ON ha.bed_id = hb.id AND ha.status = 'active'
                    LEFT JOIN students s ON s.id = ha.student_id LEFT JOIN programs p ON p.id = s.program_id
                    WHERE r.hostel_id = ? ORDER BY hb.room_id, LENGTH(hb.bed_no), hb.bed_no", [$hostelId]);
    $byRoom = [];
    foreach ($beds as $b) {
        $byRoom[(int) $b['room_id']][] = [
            'id' => (int) $b['id'], 'bed_no' => $b['bed_no'], 'status' => $b['status'],
            'occupant' => $b['allocation_id'] ? ['allocation_id' => (int) $b['allocation_id'], 'allocation_no' => $b['allocation_no'], 'allocated_on' => $b['allocated_on'], 'student_id' => (int) $b['student_id'],
                'name' => $b['student_name'], 'uid' => $b['student_uid'], 'photo' => $b['photo'], 'gender' => $b['gender'], 'program' => $b['program_short'], 'semester' => $b['current_semester'] !== null ? (int) $b['current_semester'] : null] : null,
        ];
    }
    $out = [];
    foreach ($rooms as $r) {
        $rb = $byRoom[(int) $r['id']] ?? [];
        $out[] = [
            'id' => (int) $r['id'], 'block_id' => $r['block_id'] !== null ? (int) $r['block_id'] : null, 'floor_no' => (int) $r['floor_no'], 'room_no' => $r['room_no'], 'room_type' => $r['room_type'],
            'capacity' => (int) $r['capacity'], 'occupied' => (int) $r['occupied'], 'is_ac' => (bool) $r['is_ac'], 'annual_fee' => $r['annual_fee'] !== null ? (float) $r['annual_fee'] : null,
            'amenities' => $r['amenities'] ? (json_decode($r['amenities'], true) ?: []) : [], 'status' => $r['status'],
            'free' => count(array_filter($rb, fn ($b) => $b['status'] === 'available')), 'beds' => $rb,
        ];
    }
    foreach ($blocks as &$b) {
        $b['id'] = (int) $b['id'];
        $b['floors'] = (int) $b['floors'];
        $b['rooms'] = (int) $b['rooms'];
    }
    $hostel['id'] = (int) $hostel['id'];
    $hostel['mess_fee'] = $hostel['mess_fee'] !== null ? (float) $hostel['mess_fee'] : null;
    $hostel['annual_fee'] = $hostel['annual_fee'] !== null ? (float) $hostel['annual_fee'] : null;
    return ['hostel' => $hostel, 'blocks' => $blocks, 'rooms' => $out];
}

/** Student summary for the allocation dialog (gender, program, current + past allocations). */
function hostel_student_summary(int $id): ?array
{
    $s = db_row("SELECT s.id, s.student_uid, s.first_name, s.last_name, s.gender, s.photo, s.mobile, s.email, s.status, s.current_semester, s.is_hosteller, s.father_name, s.guardian_phone,
                        s.city, s.state, p.short_name AS program_short, p.name AS program_name, d.name AS department_name
                 FROM students s LEFT JOIN programs p ON p.id = s.program_id LEFT JOIN departments d ON d.id = s.department_id WHERE s.id = ?", [$id]);
    if (!$s) {
        return null;
    }
    $s['name'] = hostel_student_name($s);
    $s['active_allocation'] = hostel_active_allocation($id);
    $s['history'] = db_all("SELECT ha.id, ha.allocation_no, ha.allocated_on, ha.vacated_on, ha.status, ha.vacate_reason, ha.fee_amount, h.code AS hostel_code, h.name AS hostel_name, r.room_no, hb.bed_no, ses.name AS session_name
                            FROM hostel_allocations ha JOIN hostels h ON h.id = ha.hostel_id JOIN hostel_rooms r ON r.id = ha.room_id LEFT JOIN hostel_beds hb ON hb.id = ha.bed_id
                            LEFT JOIN academic_sessions ses ON ses.id = ha.academic_session_id WHERE ha.student_id = ? ORDER BY ha.allocated_on DESC, ha.id DESC LIMIT 20", [$id]);
    $s['eligible_hostels'] = array_values(array_filter(hostel_overview(), fn ($h) => $h['status'] === 'active' && (hostel_gender_for_type($h['type']) === null || hostel_gender_for_type($h['type']) === $s['gender'])));
    return $s;
}

/** Raise hostel fee invoices for several allocations (bulk action). */
function hostel_bulk_invoice(array $ids, array $input = []): array
{
    $created = 0;
    $skipped = 0;
    $total = 0.0;
    $errors = [];
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        $a = db_row('SELECT ha.id, ha.status, ha.student_fee_id, sf.status AS invoice_status FROM hostel_allocations ha LEFT JOIN student_fees sf ON sf.id = ha.student_fee_id WHERE ha.id = ?', [$id]);
        if (!$a || $a['status'] !== 'active' || ($a['student_fee_id'] && $a['invoice_status'] !== 'cancelled')) {
            $skipped++;
            continue;
        }
        try {
            $res = hostel_raise_invoice($id, ['include_mess' => $input['include_mess'] ?? true, 'due_date' => $input['due_date'] ?? null]);
            $created++;
            $total += $res['amount'];
        } catch (CrudValidationException $e) {
            $errors[$id] = implode(' ', $e->errors);
        } catch (CrudException $e) {
            $errors[$id] = $e->getMessage();
        }
    }
    $msg = $created ? sprintf('%d hostel fee invoice%s raised (%s).', $created, $created === 1 ? '' : 's', money($total)) : 'No new invoices were needed.';
    if ($skipped) {
        $msg .= " $skipped skipped (already invoiced or not active).";
    }
    if ($errors) {
        $msg .= ' ' . count($errors) . ' failed: ' . reset($errors);
    }
    return ['created' => $created, 'skipped' => $skipped, 'failed' => count($errors), 'amount' => round($total, 2), 'errors' => $errors, 'message' => $msg];
}

/* ------------------------------------------------------------------
 * Detail payloads & page statistics
 * ------------------------------------------------------------------ */

/** Room with beds, current residents, allocation history and complaints (room drawer). */
function hostel_room_detail(int $roomId): ?array
{
    $room = hostel_room_row($roomId);
    if (!$room) {
        return null;
    }
    $beds = db_all("SELECT hb.id, hb.bed_no, hb.status, ha.id AS allocation_id, ha.allocation_no, ha.allocated_on, ha.valid_until, ha.fee_amount,
                           s.id AS student_id, s.student_uid, s.photo, s.gender, s.mobile, s.current_semester, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name,
                           p.short_name AS program_short, sf.invoice_no, sf.status AS invoice_status, sf.balance_amount AS invoice_balance
                    FROM hostel_beds hb LEFT JOIN hostel_allocations ha ON ha.bed_id = hb.id AND ha.status = 'active'
                    LEFT JOIN students s ON s.id = ha.student_id LEFT JOIN programs p ON p.id = s.program_id LEFT JOIN student_fees sf ON sf.id = ha.student_fee_id
                    WHERE hb.room_id = ? ORDER BY LENGTH(hb.bed_no), hb.bed_no", [$roomId]);
    $history = db_all("SELECT ha.id, ha.allocation_no, ha.allocated_on, ha.vacated_on, ha.status, ha.vacate_reason, hb.bed_no, s.student_uid, s.photo,
                              TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name
                       FROM hostel_allocations ha JOIN students s ON s.id = ha.student_id LEFT JOIN hostel_beds hb ON hb.id = ha.bed_id
                       WHERE ha.room_id = ? AND ha.status <> 'active' ORDER BY COALESCE(ha.vacated_on, ha.allocated_on) DESC, ha.id DESC LIMIT 12", [$roomId]);
    $complaints = db_all("SELECT c.id, c.complaint_no, c.subject, c.category, c.priority, c.status, c.created_at, c.assigned_to FROM hostel_complaints c
                          WHERE c.room_id = ? ORDER BY (c.status IN ('open', 'in_progress')) DESC, c.created_at DESC LIMIT 8", [$roomId]);
    foreach (['id', 'hostel_id', 'block_id', 'floor_no', 'capacity', 'occupied', 'is_ac'] as $k) {
        $room[$k] = $room[$k] !== null ? (int) $room[$k] : null;
    }
    $room['is_ac'] = (bool) $room['is_ac'];
    $room['floor_label'] = hostel_floor_label($room['floor_no']);
    $room['amenities'] = $room['amenities'] ? (json_decode($room['amenities'], true) ?: []) : [];
    $room['amenity_labels'] = array_values(array_map(fn ($a) => hostel_room_amenities()[$a] ?? label_from_key((string) $a), $room['amenities']));
    $room['effective_rent'] = $room['annual_fee'] !== null ? (float) $room['annual_fee'] : ($room['hostel_fee'] !== null ? (float) $room['hostel_fee'] : null);
    $room['free'] = count(array_filter($beds, fn ($b) => $b['status'] === 'available'));
    return ['room' => $room, 'beds' => $beds, 'history' => $history, 'complaints' => $complaints];
}

/** Allocation detail for the drawer: allocation, student's stay history, complaints and recent visitors. */
function hostel_allocation_detail(int $id): ?array
{
    $a = hostel_allocation_row($id);
    if (!$a) {
        return null;
    }
    $sid = (int) $a['student_id'];
    $a['student_name'] = hostel_student_name($a);
    $a['floor_label'] = hostel_floor_label($a['floor_no']);
    $a['room_type_label'] = hostel_room_types()[$a['room_type']] ?? label_from_key((string) $a['room_type']);
    $history = db_all("SELECT ha.id, ha.allocation_no, ha.allocated_on, ha.vacated_on, ha.status, ha.vacate_reason, ha.fee_amount, h.code AS hostel_code, r.room_no, hb.bed_no
                       FROM hostel_allocations ha JOIN hostels h ON h.id = ha.hostel_id JOIN hostel_rooms r ON r.id = ha.room_id LEFT JOIN hostel_beds hb ON hb.id = ha.bed_id
                       WHERE ha.student_id = ? ORDER BY ha.allocated_on DESC, ha.id DESC LIMIT 15", [$sid]);
    $roommates = db_all("SELECT s.id, s.student_uid, s.photo, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, hb.bed_no, p.short_name AS program_short
                         FROM hostel_allocations ha JOIN students s ON s.id = ha.student_id LEFT JOIN hostel_beds hb ON hb.id = ha.bed_id LEFT JOIN programs p ON p.id = s.program_id
                         WHERE ha.room_id = ? AND ha.status = 'active' AND ha.student_id <> ? ORDER BY hb.bed_no", [(int) $a['room_id'], $sid]);
    $complaints = db_all("SELECT id, complaint_no, subject, category, priority, status, created_at FROM hostel_complaints WHERE student_id = ? ORDER BY created_at DESC LIMIT 6", [$sid]);
    $visitors = db_all("SELECT id, pass_no, visitor_name, relation, check_in, check_out FROM hostel_visitors WHERE student_id = ? ORDER BY check_in DESC LIMIT 6", [$sid]);
    return ['allocation' => $a, 'history' => $history, 'roommates' => $roommates, 'complaints' => $complaints, 'visitors' => $visitors,
        'default_invoice_amount' => round((float) $a['fee_amount'] + (float) $a['mess_fee'], 2)];
}

/** KPIs for the allocations page. */
function hostel_allocation_stats(): array
{
    $r = db_row("SELECT COALESCE(SUM(ha.status = 'active'), 0) AS active,
                        COALESCE(SUM(ha.status = 'active' AND s.gender = 'male'), 0) AS boys,
                        COALESCE(SUM(ha.status = 'active' AND s.gender = 'female'), 0) AS girls,
                        COALESCE(SUM(ha.allocated_on >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND ha.status <> 'cancelled' AND ha.transferred_from_id IS NULL), 0) AS new_30,
                        COALESCE(SUM(ha.status = 'vacated' AND ha.vacated_on >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)), 0) AS vacated_30,
                        COALESCE(SUM(ha.status = 'transferred' AND ha.vacated_on >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)), 0) AS transferred_30,
                        COALESCE(SUM(ha.status = 'active' AND (ha.student_fee_id IS NULL OR sf.status = 'cancelled')), 0) AS not_invoiced,
                        COALESCE(SUM(ha.status = 'active' AND ha.valid_until IS NOT NULL AND ha.valid_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)), 0) AS ending_30,
                        COALESCE(SUM(CASE WHEN ha.status = 'active' THEN ha.fee_amount END), 0) AS annual_rent
                 FROM hostel_allocations ha JOIN students s ON s.id = ha.student_id LEFT JOIN student_fees sf ON sf.id = ha.student_fee_id") ?? [];
    $beds = db_row("SELECT COUNT(*) AS total, COALESCE(SUM(hb.status = 'available' AND r.status = 'available'), 0) AS free FROM hostel_beds hb JOIN hostel_rooms r ON r.id = hb.room_id
                    JOIN hostels h ON h.id = r.hostel_id WHERE h.status = 'active'") ?? [];
    $fees = hostel_pending_fees();
    $out = array_map(fn ($v) => (int) $v, $r);
    $out['annual_rent'] = round((float) ($r['annual_rent'] ?? 0), 2);
    $out['free_beds'] = (int) ($beds['free'] ?? 0);
    $out['total_beds'] = (int) ($beds['total'] ?? 0);
    $out['pending_fees'] = $fees['amount'];
    $out['pending_fee_students'] = $fees['students'];
    $out['overdue_fees'] = $fees['overdue'];
    return $out;
}

/** KPIs for the complaints page. */
function hostel_complaint_stats(): array
{
    $r = db_row("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'open'), 0) AS open, COALESCE(SUM(status = 'in_progress'), 0) AS in_progress,
                        COALESCE(SUM(status = 'resolved'), 0) AS resolved, COALESCE(SUM(status = 'closed'), 0) AS closed,
                        COALESCE(SUM(status IN ('open', 'in_progress') AND priority IN ('high', 'urgent')), 0) AS urgent,
                        COALESCE(SUM(status IN ('open', 'in_progress') AND assigned_to IS NULL), 0) AS unassigned,
                        COALESCE(SUM(status IN ('open', 'in_progress') AND created_at < DATE_SUB(NOW(), INTERVAL 72 HOUR)), 0) AS ageing,
                        COALESCE(SUM(resolved_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')), 0) AS resolved_month,
                        COALESCE(SUM(created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')), 0) AS raised_month,
                        AVG(CASE WHEN resolved_at IS NOT NULL AND resolved_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) END) AS avg_hours
                 FROM hostel_complaints") ?? [];
    $out = [];
    foreach ($r as $k => $v) {
        $out[$k] = $k === 'avg_hours' ? ($v !== null ? round((float) $v, 1) : null) : (int) $v;
    }
    $out['by_category'] = array_map(fn ($c) => ['category' => $c['category'], 'label' => hostel_complaint_categories()[$c['category']] ?? label_from_key($c['category']), 'total' => (int) $c['total'], 'open' => (int) $c['open']],
        db_all("SELECT category, COUNT(*) AS total, COALESCE(SUM(status IN ('open', 'in_progress')), 0) AS open FROM hostel_complaints GROUP BY category ORDER BY total DESC"));
    return $out;
}

/** KPIs + "currently inside" list for the visitors page. */
function hostel_visitor_stats(?int $hostelId = null): array
{
    $args = $hostelId ? [$hostelId] : [];
    $cond = $hostelId ? ' AND v.hostel_id = ?' : '';
    $r = db_row("SELECT COALESCE(SUM(DATE(v.check_in) = CURDATE()), 0) AS today, COALESCE(SUM(v.check_out IS NULL), 0) AS inside,
                        COALESCE(SUM(v.check_in >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)), 0) AS week,
                        COALESCE(SUM(v.check_out IS NULL AND v.check_in < DATE_SUB(NOW(), INTERVAL 6 HOUR)), 0) AS overstay,
                        AVG(CASE WHEN v.check_out IS NOT NULL AND v.check_in >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN TIMESTAMPDIFF(MINUTE, v.check_in, v.check_out) END) AS avg_minutes
                 FROM hostel_visitors v WHERE 1 = 1$cond", $args) ?? [];
    $inside = db_all("SELECT v.id, v.pass_no, v.visitor_name, v.relation, v.phone, v.visitors_count, v.purpose, v.check_in, h.code AS hostel_code, h.name AS hostel_name,
                             TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, s.photo,
                             (SELECT r.room_no FROM hostel_allocations ha JOIN hostel_rooms r ON r.id = ha.room_id WHERE ha.student_id = v.student_id AND ha.status = 'active' ORDER BY ha.id DESC LIMIT 1) AS room_no,
                             TIMESTAMPDIFF(MINUTE, v.check_in, NOW()) AS minutes_inside
                      FROM hostel_visitors v JOIN hostels h ON h.id = v.hostel_id JOIN students s ON s.id = v.student_id
                      WHERE v.check_out IS NULL$cond ORDER BY v.check_in ASC LIMIT 50", $args);
    $days = db_all("SELECT DATE(v.check_in) AS d, COUNT(*) AS n FROM hostel_visitors v WHERE v.check_in >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)$cond GROUP BY DATE(v.check_in)", $args);
    $map = array_column($days, 'n', 'd');
    $trend = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $trend[] = ['date' => $d, 'label' => date('d M', strtotime($d)), 'visitors' => (int) ($map[$d] ?? 0)];
    }
    foreach ($inside as &$v) {
        $v['id'] = (int) $v['id'];
        $v['minutes_inside'] = (int) $v['minutes_inside'];
        $v['relation_label'] = $v['relation'] ? (hostel_visitor_relations()[$v['relation']] ?? $v['relation']) : null;
    }
    return ['today' => (int) ($r['today'] ?? 0), 'inside' => (int) ($r['inside'] ?? 0), 'week' => (int) ($r['week'] ?? 0), 'overstay' => (int) ($r['overstay'] ?? 0),
        'avg_minutes' => isset($r['avg_minutes']) ? (int) round((float) $r['avg_minutes']) : null, 'inside_list' => $inside, 'trend' => $trend];
}

/**
 * Create rooms in bulk for a hostel / block: floors × rooms per floor, numbered with a pattern.
 * Pattern tokens: {block} (block letter / name), {floor} (floor number), {nn} (room on the floor, 2 digits), {n}.
 * e.g. "{block}-{floor}{nn}" -> A-101, A-102 …  Existing room numbers are skipped.
 */
function hostel_generate_rooms(array $in): array
{
    $errors = [];
    $hostelId = (int) ($in['hostel_id'] ?? 0);
    $hostel = $hostelId ? db_row('SELECT id, name, code, total_floors, annual_fee FROM hostels WHERE id = ?', [$hostelId]) : null;
    if (!$hostel) {
        $errors['hostel_id'] = 'Select the hostel.';
    }
    $blockId = !empty($in['block_id']) ? (int) $in['block_id'] : null;
    $block = $blockId ? db_row('SELECT id, hostel_id, name, floors FROM hostel_blocks WHERE id = ?', [$blockId]) : null;
    if ($blockId && (!$block || (int) $block['hostel_id'] !== $hostelId)) {
        $errors['block_id'] = 'The selected block does not belong to this hostel.';
    } elseif (!$blockId && $hostel && (int) db_value('SELECT COUNT(*) FROM hostel_blocks WHERE hostel_id = ?', [$hostelId])) {
        $errors['block_id'] = $hostel['name'] . ' is organised in blocks. Select the block these rooms belong to.';
    }
    $from = (int) ($in['floor_from'] ?? 0);
    $to = (int) ($in['floor_to'] ?? $from);
    $maxFloors = $block ? (int) $block['floors'] : (int) ($hostel['total_floors'] ?? 30);
    if ($from < 0 || $to < $from) {
        $errors['floor_to'] = 'The last floor must be the same as or above the first floor.';
    } elseif ($maxFloors && $to >= $maxFloors) {
        $errors['floor_to'] = ($block ? $block['name'] : ($hostel['name'] ?? 'The hostel')) . ' has floors up to the ' . hostel_floor_label($maxFloors - 1) . '.';
    }
    $perFloor = (int) ($in['rooms_per_floor'] ?? 0);
    if ($perFloor < 1 || $perFloor > 60) {
        $errors['rooms_per_floor'] = 'Rooms per floor must be between 1 and 60.';
    }
    $type = (string) ($in['room_type'] ?? 'double');
    if (!isset(hostel_room_types()[$type])) {
        $errors['room_type'] = 'Select a valid room type.';
    }
    $capacity = (int) ($in['capacity'] ?? hostel_room_type_capacity($type));
    if ($capacity < 1 || $capacity > 12) {
        $errors['capacity'] = 'Beds per room must be between 1 and 12.';
    }
    $rent = isset($in['annual_fee']) && $in['annual_fee'] !== '' && $in['annual_fee'] !== null ? $in['annual_fee'] : null;
    if ($rent !== null && (!is_numeric($rent) || (float) $rent < 0 || (float) $rent > 1000000)) {
        $errors['annual_fee'] = 'Rent must be between 0 and 10,00,000.';
    }
    $pattern = trim((string) ($in['pattern'] ?? '{floor}{nn}'));
    if ($pattern === '' || !str_contains($pattern, '{n') || mb_strlen($pattern) > 30) {
        $errors['pattern'] = 'The numbering pattern must contain {nn} or {n} (e.g. {block}-{floor}{nn}).';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $total = ($to - $from + 1) * $perFloor;
    if ($total > 400) {
        throw new CrudValidationException(['rooms_per_floor' => "That would create $total rooms. Create at most 400 rooms at a time."]);
    }
    $letter = $block ? strtoupper(preg_replace('/^(block|wing|tower)\s*/i', '', $block['name'])) : $hostel['code'];
    $amenities = array_values(array_intersect((array) ($in['amenities'] ?? []), array_keys(hostel_room_amenities())));
    $isAc = !empty($in['is_ac']) && $in['is_ac'] !== '0' ? 1 : 0;
    $existing = array_flip(db_column('SELECT room_no FROM hostel_rooms WHERE hostel_id = ?', [$hostelId]));
    $created = 0;
    $skipped = [];
    db_transaction(function () use ($from, $to, $perFloor, $pattern, $letter, $existing, $hostelId, $blockId, $type, $capacity, $isAc, $rent, $amenities, &$created, &$skipped) {
        for ($f = $from; $f <= $to; $f++) {
            for ($n = 1; $n <= $perFloor; $n++) {
                $no = strtoupper(strtr($pattern, ['{block}' => $letter, '{floor}' => (string) $f, '{nn}' => str_pad((string) $n, 2, '0', STR_PAD_LEFT), '{n}' => (string) $n]));
                $no = mb_substr($no, 0, 20);
                if (isset($existing[$no])) {
                    $skipped[] = $no;
                    continue;
                }
                $rid = db_insert('hostel_rooms', ['hostel_id' => $hostelId, 'block_id' => $blockId, 'floor_no' => $f, 'room_no' => $no, 'room_type' => $type, 'capacity' => $capacity,
                    'occupied' => 0, 'is_ac' => $isAc, 'annual_fee' => $rent !== null ? round((float) $rent, 2) : null, 'amenities' => $amenities ? json_encode($amenities) : null, 'status' => 'available']);
                for ($b = 1; $b <= $capacity; $b++) {
                    db_insert('hostel_beds', ['room_id' => $rid, 'bed_no' => (string) $b, 'status' => 'available']);
                }
                $existing[$no] = true;
                $created++;
            }
        }
    });
    if ($created) {
        log_activity('create', 'hostel', $hostelId, sprintf('Added %d room%s (%s, %d beds each) to %s%s', $created, $created === 1 ? '' : 's', hostel_room_types()[$type], $capacity, $hostel['name'],
            $block ? ' · ' . $block['name'] : ''));
    }
    return ['created' => $created, 'skipped' => count($skipped), 'skipped_rooms' => array_slice($skipped, 0, 20), 'beds' => $created * $capacity];
}

/* ------------------------------------------------------------------
 * Print views (rendered through api/routes/hostel.php, A4 print layout)
 * ------------------------------------------------------------------ */

function hostel_print_fail(string $title, string $text, int $status = 404): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    print_layout_start($title);
    echo '<div class="py-24 text-center"><p class="font-display text-xl font-bold text-brand-900">' . e($title) . '</p>'
        . '<p class="mx-auto mt-2 max-w-md text-sm text-slate-500">' . e($text) . '</p>'
        . '<a href="' . e(admin_url('hostel')) . '" class="btn btn-primary btn-sm mt-5 no-print">Back to Hostel</a></div>';
    print_layout_end();
    exit;
}

function hostel_print_guard(): void
{
    header('Content-Type: text/html; charset=utf-8');
    if (!can('hostel', 'view')) {
        hostel_print_fail('Access denied', 'You do not have permission to view hostel records.', 403);
    }
}

/** Two-column label/value table row for print views. */
function hostel_print_cell(string $label, string $valueHtml): string
{
    return '<tr><th class="w-[38%] border border-slate-200 bg-slate-50 px-3 py-1.5 text-left text-[11px] font-semibold text-slate-600">' . e($label) . '</th>'
        . '<td class="border border-slate-200 px-3 py-1.5 text-[12px] font-medium text-slate-800">' . $valueHtml . '</td></tr>';
}

function hostel_print_allotment(int $id): void
{
    hostel_print_guard();
    $a = hostel_allocation_row($id);
    if (!$a) {
        hostel_print_fail('Allocation not found', 'The hostel allocation does not exist or has been deleted.');
    }
    $name = hostel_student_name($a);
    $salutation = $a['gender'] === 'female' ? 'Ms.' : ($a['gender'] === 'male' ? 'Mr.' : '');
    // "Single seater", "Double seater", "Triple seater", "Four seater", "Dormitory"
    $typeLabel = hostel_room_types()[$a['room_type']] ?? label_from_key($a['room_type']);
    $roomType = (in_array($a['room_type'], ['single', 'double', 'triple'], true) ? $typeLabel . ' seater' : $typeLabel) . ' · ' . ($a['is_ac'] ? 'AC' : 'Non-AC');
    $invoice = $a['invoice_no'] ? e($a['invoice_no']) . ' · ' . e(money($a['invoice_amount'])) . ' · ' . e(label_from_key((string) $a['invoice_status'])) . ($a['invoice_due'] ? ' (due ' . e(format_date($a['invoice_due'])) . ')' : '') : 'Not raised yet';
    log_activity('print', 'hostel', $id, 'Printed allotment letter ' . $a['allocation_no'] . ' for ' . $name);
    print_layout_start('Hostel Allotment Letter - ' . $a['allocation_no']);
    ?>
<div class="mt-5 flex items-start justify-between text-[12px] text-slate-700">
  <div>
    <p>Ref: <strong class="font-mono"><?= e($a['allocation_no']) ?></strong></p>
    <p>Session: <strong><?= e($a['session_name'] ?? '') ?></strong></p>
    <p>Status: <strong><?= e(hostel_allocation_statuses()[$a['status']] ?? label_from_key($a['status'])) ?></strong></p>
  </div>
  <div class="text-right">
    <p>Date: <strong><?= e(format_date($a['allocated_on'])) ?></strong></p>
    <div data-qr="<?= e(absolute_url('admin/hostel/allocations?view=' . $id)) ?>" class="ml-auto mt-2 h-16 w-16"></div>
  </div>
</div>
<div class="mt-4 text-[12px] leading-relaxed text-slate-700">
  <p>To,</p>
  <p class="font-semibold text-slate-900"><?= e(trim($salutation . ' ' . $name)) ?> (<?= e($a['student_uid']) ?>)</p>
  <p><?= e(trim(($a['program_short'] ?? '') . ' — ' . ($a['program_name'] ?? ''), ' —')) ?><?= $a['current_semester'] ? ' · Semester ' . (int) $a['current_semester'] : '' ?></p>
  <?php if ($a['father_name']): ?><p><?= e(($a['gender'] === 'female' ? 'D/o ' : 'S/o ') . $a['father_name']) ?></p><?php endif; ?>
</div>
<h1 class="mt-6 text-center font-display text-lg font-extrabold uppercase tracking-wide text-brand-900">Hostel Allotment Letter</h1>
<p class="mt-1 text-center text-[12px] font-semibold text-accent-700"><?= e($a['hostel_name']) ?></p>
<div class="mt-4 space-y-3 text-[12px] leading-relaxed text-slate-700">
  <p>Dear <?= e($a['first_name']) ?>,</p>
  <p>With reference to your application for hostel accommodation, we are pleased to allot you the following room for the academic session <?= e($a['session_name'] ?? '') ?>. Please report to the warden's office with this letter, two passport-size photographs and a copy of your ID card to complete the check-in formalities.</p>
</div>
<table class="mt-4 w-full border-collapse"><tbody>
  <?= hostel_print_cell('Hostel', e($a['hostel_name'] . ' (' . $a['hostel_code'] . ')')) ?>
  <?= hostel_print_cell('Block / Floor', e(($a['block_name'] ?: '—') . ' · ' . hostel_floor_label($a['floor_no']))) ?>
  <?= hostel_print_cell('Room / Bed', '<strong>Room ' . e($a['room_no']) . '</strong>' . ($a['bed_no'] ? ' · Bed ' . e($a['bed_no']) : '')) ?>
  <?= hostel_print_cell('Room type', e($roomType)) ?>
  <?= hostel_print_cell('Allotted from', e(format_date($a['allocated_on'])) . ($a['valid_until'] ? ' to ' . e(format_date($a['valid_until'])) : '')) ?>
  <?= hostel_print_cell('Room rent (per year)', $a['fee_amount'] !== null ? e(money($a['fee_amount'])) : '—') ?>
  <?= hostel_print_cell('Mess charges (per year)', $a['mess_fee'] !== null ? e(money($a['mess_fee'])) : '—') ?>
  <?= hostel_print_cell('Hostel fee invoice', $invoice) ?>
  <?= hostel_print_cell('Warden', e(trim(($a['warden_name'] ?? '—') . ($a['warden_phone'] ? ' · ' . $a['warden_phone'] : '')))) ?>
  <?= hostel_print_cell('Visiting hours', e($a['visiting_hours'] ?: '10:00 AM – 7:00 PM')) ?>
</tbody></table>
<div class="mt-5 text-[11px] leading-relaxed text-slate-700">
  <p class="font-semibold text-slate-900">Hostel rules (summary)</p>
  <ol class="mt-1 list-decimal space-y-0.5 pl-5">
    <li>The room and furniture are allotted on an "as is" basis; any damage will be recovered from the resident.</li>
    <li>Residents must be inside the hostel by the gate-closing time; late entry requires the warden's prior permission.</li>
    <li>Visitors are allowed only in the visitors' lounge during visiting hours after registering at the gate.</li>
    <li>Ragging, alcohol, smoking and any form of indiscipline are strictly prohibited and invite disciplinary action.</li>
    <li>Room changes are permitted only through the warden's office; the allotment is non-transferable.</li>
    <li>Hostel and mess fees must be paid by the due date; the room may be withdrawn for non-payment.</li>
  </ol>
</div>
<div class="mt-14 grid grid-cols-3 gap-6 text-center text-[11px] text-slate-600">
  <div><div class="border-t border-slate-400 pt-1">Student</div></div>
  <div><div class="border-t border-slate-400 pt-1">Parent / Guardian</div></div>
  <div><div class="border-t border-slate-400 pt-1">Chief Warden</div></div>
</div>
<p class="mt-8 text-center text-[10px] text-slate-400">Allotted by <?= e($a['allocated_by_name'] ?? 'Hostel office') ?> · Generated on <?= e(format_datetime(date('Y-m-d H:i:s'))) ?> · Scan the QR code to verify.</p>
    <?php
    print_layout_end();
    exit;
}

function hostel_print_register(int $hostelId, ?int $blockId = null): void
{
    hostel_print_guard();
    $hostel = db_row('SELECT * FROM hostels WHERE id = ?', [$hostelId]);
    if (!$hostel) {
        hostel_print_fail('Hostel not found', 'The hostel does not exist or has been deleted.');
    }
    $args = [$hostelId];
    $where = 'r.hostel_id = ?';
    if ($blockId) {
        $where .= ' AND r.block_id = ?';
        $args[] = $blockId;
    }
    $rows = db_all("SELECT r.room_no, r.floor_no, r.room_type, r.is_ac, r.status AS room_status, b.name AS block_name, hb.bed_no, hb.status AS bed_status,
                           TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, s.mobile, s.current_semester, p.short_name AS program_short, ha.allocated_on
                    FROM hostel_rooms r LEFT JOIN hostel_blocks b ON b.id = r.block_id JOIN hostel_beds hb ON hb.room_id = r.id
                    LEFT JOIN hostel_allocations ha ON ha.bed_id = hb.id AND ha.status = 'active' LEFT JOIN students s ON s.id = ha.student_id LEFT JOIN programs p ON p.id = s.program_id
                    WHERE $where ORDER BY b.name, r.floor_no, LENGTH(r.room_no), r.room_no, LENGTH(hb.bed_no), hb.bed_no", $args);
    $beds = count($rows);
    $occ = count(array_filter($rows, fn ($r) => $r['student_name']));
    $blocked = count(array_filter($rows, fn ($r) => !$r['student_name'] && ($r['bed_status'] === 'maintenance' || in_array($r['room_status'], ['maintenance', 'inactive'], true))));
    $blockName = $blockId ? db_value('SELECT name FROM hostel_blocks WHERE id = ?', [$blockId]) : null;
    log_activity('print', 'hostel', $hostelId, 'Printed occupancy register of ' . $hostel['name'] . ($blockName ? ' (' . $blockName . ')' : ''));
    print_layout_start('Occupancy Register - ' . $hostel['name'], true, 'landscape');
    ?>
<div class="mt-5 mb-3 flex items-end justify-between">
  <div>
    <h1 class="font-display text-xl font-bold text-brand-900">Occupancy Register — <?= e($hostel['name']) ?><?= $blockName ? ' · ' . e($blockName) : '' ?></h1>
    <p class="text-xs text-slate-500">Warden: <?= e($hostel['warden_name'] ?? '—') ?><?= $hostel['warden_phone'] ? ' · ' . e($hostel['warden_phone']) : '' ?> · Generated on <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p>
  </div>
  <p class="text-right text-xs text-slate-600"><strong class="text-brand-900"><?= $occ ?></strong> residents · <strong class="text-brand-900"><?= $beds - $occ - $blocked ?></strong> vacant<?= $blocked ? ' · <strong class="text-brand-900">' . $blocked . '</strong> under maintenance' : '' ?> · <?= $beds ?> beds</p>
</div>
<table class="w-full border-collapse text-[11px]">
  <thead><tr>
    <?php foreach (['Block', 'Floor', 'Room', 'Type', 'Bed', 'Resident', 'Student ID', 'Program', 'Mobile', 'Since'] as $h): ?>
      <th class="border border-brand-900 bg-brand-900 px-2 py-1.5 text-left font-semibold text-white"><?= e($h) ?></th>
    <?php endforeach; ?>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $i => $r): ?>
    <tr class="<?= $i % 2 ? 'bg-slate-50' : '' ?>">
      <td class="border border-slate-200 px-2 py-1"><?= e($r['block_name'] ?? '—') ?></td>
      <td class="border border-slate-200 px-2 py-1"><?= e(hostel_floor_label($r['floor_no'])) ?></td>
      <td class="border border-slate-200 px-2 py-1 font-semibold"><?= e($r['room_no']) ?></td>
      <td class="border border-slate-200 px-2 py-1"><?= e((hostel_room_types()[$r['room_type']] ?? $r['room_type']) . ($r['is_ac'] ? ' · AC' : '')) ?></td>
      <td class="border border-slate-200 px-2 py-1"><?= e($r['bed_no']) ?></td>
      <td class="border border-slate-200 px-2 py-1"><?= $r['student_name'] ? e($r['student_name']) : '<span class="text-slate-400">' . ($r['bed_status'] === 'maintenance' || $r['room_status'] === 'maintenance' ? 'Under maintenance' : 'Vacant') . '</span>' ?></td>
      <td class="border border-slate-200 px-2 py-1 font-mono"><?= e($r['student_uid'] ?? '') ?></td>
      <td class="border border-slate-200 px-2 py-1"><?= e(trim(($r['program_short'] ?? '') . ($r['current_semester'] ? ' · Sem ' . $r['current_semester'] : ''))) ?></td>
      <td class="border border-slate-200 px-2 py-1"><?= e($r['mobile'] ?? '') ?></td>
      <td class="border border-slate-200 px-2 py-1"><?= $r['allocated_on'] ? e(format_date($r['allocated_on'])) : '' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?>
    <tr><td colspan="10" class="border border-slate-200 px-2 py-6 text-center text-slate-500">No rooms have been added to this hostel yet.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
    <?php
    print_layout_end();
    exit;
}

function hostel_print_visitor_pass(int $id): void
{
    hostel_print_guard();
    $v = db_row("SELECT v.*, h.name AS hostel_name, h.code AS hostel_code, h.visiting_hours, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid,
                        (SELECT CONCAT(r.room_no, IFNULL(CONCAT(' · Bed ', hb.bed_no), '')) FROM hostel_allocations ha JOIN hostel_rooms r ON r.id = ha.room_id LEFT JOIN hostel_beds hb ON hb.id = ha.bed_id
                          WHERE ha.student_id = v.student_id AND ha.status = 'active' ORDER BY ha.id DESC LIMIT 1) AS room_label, u.name AS approved_by_name
                 FROM hostel_visitors v JOIN hostels h ON h.id = v.hostel_id JOIN students s ON s.id = v.student_id LEFT JOIN users u ON u.id = v.approved_by WHERE v.id = ?", [$id]);
    if (!$v) {
        hostel_print_fail('Visitor entry not found', 'The visitor entry does not exist or has been deleted.');
    }
    $proof = $v['id_proof_type'] ? (hostel_id_proof_types()[$v['id_proof_type']] ?? label_from_key($v['id_proof_type'])) . ($v['id_proof'] ? ' · ' . $v['id_proof'] : '') : ($v['id_proof'] ?: '—');
    print_layout_start('Visitor Pass - ' . ($v['pass_no'] ?? $id));
    ?>
<div class="mx-auto mt-6 max-w-xl rounded-2xl border-2 border-brand-900 p-5">
  <div class="flex items-start justify-between gap-4">
    <div>
      <p class="text-[11px] font-semibold uppercase tracking-wider text-accent-700">Visitor gate pass</p>
      <h1 class="font-display text-xl font-extrabold text-brand-900"><?= e($v['hostel_name']) ?></h1>
      <p class="mt-0.5 text-[12px] text-slate-600">Pass No. <strong class="font-mono"><?= e($v['pass_no'] ?? ('#' . $id)) ?></strong> · <?= e(format_date($v['check_in'])) ?></p>
    </div>
    <div data-qr="<?= e(absolute_url('admin/hostel/visitors?view=' . $id)) ?>" class="h-20 w-20 shrink-0"></div>
  </div>
  <table class="mt-4 w-full border-collapse"><tbody>
    <?= hostel_print_cell('Visitor', '<strong>' . e($v['visitor_name']) . '</strong>' . ($v['visitors_count'] > 1 ? ' + ' . ((int) $v['visitors_count'] - 1) . ' accompanying' : '')) ?>
    <?= hostel_print_cell('Relation / Phone', e(trim(($v['relation'] ?? '—') . ($v['phone'] ? ' · ' . $v['phone'] : '')))) ?>
    <?= hostel_print_cell('ID proof', e($proof)) ?>
    <?= hostel_print_cell('Visiting', e($v['student_name'] . ' (' . $v['student_uid'] . ')') . ($v['room_label'] ? ' · Room ' . e($v['room_label']) : '')) ?>
    <?= hostel_print_cell('Purpose', e($v['purpose'] ?: '—')) ?>
    <?= hostel_print_cell('Vehicle', e($v['vehicle_no'] ?: '—')) ?>
    <?= hostel_print_cell('Check-in', e(format_datetime($v['check_in']))) ?>
    <?= hostel_print_cell('Check-out', $v['check_out'] ? e(format_datetime($v['check_out'])) : '<span class="text-amber-700">Inside campus</span>') ?>
  </tbody></table>
  <p class="mt-3 text-[10px] leading-relaxed text-slate-500">Valid for the date of issue only. Visitors must stay in the visitors' lounge (<?= e($v['visiting_hours'] ?: '10:00 AM – 7:00 PM') ?>) and return this pass at the gate while leaving.</p>
  <div class="mt-10 grid grid-cols-2 gap-6 text-center text-[11px] text-slate-600">
    <div><div class="border-t border-slate-400 pt-1">Visitor's signature</div></div>
    <div><div class="border-t border-slate-400 pt-1">Security / Warden<?= $v['approved_by_name'] ? ' (' . e($v['approved_by_name']) . ')' : '' ?></div></div>
  </div>
</div>
    <?php
    print_layout_end();
    exit;
}

function hostel_print_complaint(int $id): void
{
    hostel_print_guard();
    $c = db_row("SELECT c.*, h.name AS hostel_name, h.code AS hostel_code, h.warden_name, r.room_no, r.floor_no, b.name AS block_name,
                        TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, s.mobile, u.name AS created_by_name
                 FROM hostel_complaints c JOIN hostels h ON h.id = c.hostel_id LEFT JOIN hostel_rooms r ON r.id = c.room_id LEFT JOIN hostel_blocks b ON b.id = r.block_id
                 LEFT JOIN students s ON s.id = c.student_id LEFT JOIN users u ON u.id = c.created_by WHERE c.id = ?", [$id]);
    if (!$c) {
        hostel_print_fail('Complaint not found', 'The complaint does not exist or has been deleted.');
    }
    print_layout_start('Complaint Job Card - ' . ($c['complaint_no'] ?? $id));
    ?>
<div class="mt-5 flex items-start justify-between">
  <div>
    <p class="text-[11px] font-semibold uppercase tracking-wider text-accent-700">Maintenance job card</p>
    <h1 class="font-display text-xl font-extrabold text-brand-900"><?= e($c['subject']) ?></h1>
    <p class="mt-0.5 text-[12px] text-slate-600">Complaint No. <strong class="font-mono"><?= e($c['complaint_no'] ?? ('#' . $id)) ?></strong> · Raised <?= e(format_datetime($c['created_at'])) ?></p>
  </div>
  <div data-qr="<?= e(absolute_url('admin/hostel/complaints?view=' . $id)) ?>" class="h-16 w-16 shrink-0"></div>
</div>
<table class="mt-4 w-full border-collapse"><tbody>
  <?= hostel_print_cell('Hostel / Location', e($c['hostel_name']) . ' · ' . ($c['room_no'] ? e(($c['block_name'] ? $c['block_name'] . ' · ' : '') . hostel_floor_label($c['floor_no']) . ' · Room ' . $c['room_no']) : 'Common area')) ?>
  <?= hostel_print_cell('Reported by', $c['student_name'] ? e($c['student_name'] . ' (' . $c['student_uid'] . ')' . ($c['mobile'] ? ' · ' . $c['mobile'] : '')) : e($c['created_by_name'] ?? 'Hostel office')) ?>
  <?= hostel_print_cell('Category / Priority', e((hostel_complaint_categories()[$c['category']] ?? label_from_key($c['category'])) . ' · ' . (hostel_complaint_priorities()[$c['priority']] ?? label_from_key($c['priority'])))) ?>
  <?= hostel_print_cell('Status', e(hostel_complaint_statuses()[$c['status']] ?? label_from_key($c['status']))) ?>
  <?= hostel_print_cell('Assigned to', e($c['assigned_to'] ?: 'Not assigned') . ($c['assigned_at'] ? ' · ' . e(format_datetime($c['assigned_at'])) : '')) ?>
  <?= hostel_print_cell('Description', nl2br(e($c['description'] ?: '—'))) ?>
  <?= hostel_print_cell('Resolution', $c['resolution'] ? nl2br(e($c['resolution'])) . ($c['resolved_at'] ? '<br><span class="text-slate-500">Resolved ' . e(format_datetime($c['resolved_at'])) . '</span>' : '') : '<span class="text-slate-400">&nbsp;</span>') ?>
</tbody></table>
<div class="mt-6 rounded-lg border border-slate-200 p-3 text-[11px] text-slate-600">
  <p class="font-semibold text-slate-800">Work done / material used</p>
  <div class="mt-10 border-t border-dashed border-slate-300"></div>
  <div class="mt-8 border-t border-dashed border-slate-300"></div>
</div>
<div class="mt-14 grid grid-cols-3 gap-6 text-center text-[11px] text-slate-600">
  <div><div class="border-t border-slate-400 pt-1">Technician</div></div>
  <div><div class="border-t border-slate-400 pt-1">Resident</div></div>
  <div><div class="border-t border-slate-400 pt-1">Warden<?= $c['warden_name'] ? ' (' . e($c['warden_name']) . ')' : '' ?></div></div>
</div>
    <?php
    print_layout_end();
    exit;
}
