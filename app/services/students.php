<?php
/**
 * Student management domain logic shared by the CRUD modules (app/modules/students*.php), the custom API
 * (api/routes/students.php), print views (admin/print/student-profile.php, id-cards.php) and the demo seeder.
 *
 *   students_next_uid($programId, 2026)          GIMT26BBA0221  (prefix from Settings > Academic)
 *   students_find_duplicates(['email' => ...])   inline duplicate warnings
 *   students_profile_row($id)                    student + program/section/batch names
 *   students_promote($payload)                   bulk promotion / pass-out in one transaction
 */

/* ------------------------------------------------------------------
 * Option lists (kept in one place; the SPA mirrors them in modules/students/constants.ts)
 * ------------------------------------------------------------------ */

function students_status_options(): array
{
    return ['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended', 'dropped' => 'Dropped', 'graduated' => 'Passed Out', 'alumni' => 'Alumni'];
}

function students_gender_options(): array
{
    return ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];
}

function students_blood_groups(): array
{
    return ['A+' => 'A+', 'A-' => 'A-', 'B+' => 'B+', 'B-' => 'B-', 'O+' => 'O+', 'O-' => 'O-', 'AB+' => 'AB+', 'AB-' => 'AB-'];
}

function students_category_options(): array
{
    return ['General' => 'General', 'OBC' => 'OBC', 'SC' => 'SC', 'ST' => 'ST', 'EWS' => 'EWS'];
}

function students_religion_options(): array
{
    return ['Hindu' => 'Hindu', 'Muslim' => 'Muslim', 'Sikh' => 'Sikh', 'Christian' => 'Christian', 'Jain' => 'Jain', 'Buddhist' => 'Buddhist', 'Other' => 'Other', 'Prefer not to say' => 'Prefer not to say'];
}

function students_admission_types(): array
{
    return ['regular' => 'Regular', 'lateral' => 'Lateral Entry', 'management' => 'Management Quota', 'scholarship' => 'Scholarship'];
}

function students_document_types(): array
{
    return [
        '10th_marksheet' => '10th Marksheet', '12th_marksheet' => '12th Marksheet', 'graduation' => 'Graduation Marksheet', 'id_proof' => 'ID Proof (Aadhaar)',
        'photo' => 'Passport Photo', 'transfer_certificate' => 'Transfer Certificate', 'migration' => 'Migration Certificate',
        'caste_certificate' => 'Caste Certificate', 'income_certificate' => 'Income Certificate', 'other' => 'Other',
    ];
}

/** Statuses that require a reason when a student is moved into them. */
function students_inactive_statuses(): array
{
    return ['inactive', 'suspended', 'dropped'];
}

/* ------------------------------------------------------------------
 * Small helpers
 * ------------------------------------------------------------------ */

function students_name_sql(string $a = 's'): string
{
    return "TRIM(CONCAT_WS(' ', $a.first_name, $a.middle_name, $a.last_name))";
}

function students_full_name(array $r): string
{
    return trim(implode(' ', array_filter([$r['first_name'] ?? '', $r['middle_name'] ?? '', $r['last_name'] ?? ''], fn ($p) => $p !== null && $p !== '')));
}

/** "XXXX XXXX 1234" — Aadhaar numbers are never sent to the browser in full. */
function students_mask_aadhaar(?string $aadhaar): ?string
{
    $d = preg_replace('/\D/', '', (string) $aadhaar);
    if ($d === '') {
        return null;
    }
    return 'XXXX XXXX ' . substr($d, -4);
}

function students_digits(?string $v): string
{
    return preg_replace('/\D/', '', (string) $v);
}

/** Last 10 digits of an Indian mobile number (ignores +91, spaces, dashes). */
function students_mobile_key(?string $v): string
{
    $d = students_digits($v);
    return strlen($d) > 10 ? substr($d, -10) : $d;
}

function students_program(int $programId): ?array
{
    static $cache = [];
    if (!array_key_exists($programId, $cache)) {
        $cache[$programId] = db_row('SELECT id, department_id, name, short_name, code, level, total_semesters, duration_years FROM programs WHERE id = ?', [$programId]);
    }
    return $cache[$programId];
}

/** Admission year used by ID formats: admission date -> admission session -> today. */
function students_admission_year(array $data): int
{
    if (!empty($data['admission_date']) && preg_match('/^(\d{4})-/', (string) $data['admission_date'], $m)) {
        return (int) $m[1];
    }
    if (!empty($data['academic_session_id'])) {
        $start = db_value('SELECT start_date FROM academic_sessions WHERE id = ?', [(int) $data['academic_session_id']]);
        if ($start) {
            return (int) substr((string) $start, 0, 4);
        }
    }
    return (int) date('Y');
}

/* ------------------------------------------------------------------
 * Number generation (student_uid / admission_no / roll_no)
 * ------------------------------------------------------------------ */

/** Highest numeric suffix used by $column values that start with $prefix. */
function students_max_suffix(string $column, string $prefix): int
{
    $col = db_quote_ident($column);
    $len = mb_strlen($prefix) + 1;
    return (int) db_value("SELECT MAX(CAST(SUBSTRING($col, $len) AS UNSIGNED)) FROM students WHERE $col LIKE ? AND SUBSTRING($col, $len) REGEXP '^[0-9]+$'", [students_like_prefix($prefix)]);
}

function students_like_prefix(string $prefix): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';
}

/** Make sure a sequence is at least $floor so generated numbers never collide with imported/seeded ones. */
function students_sequence_floor(string $name, int $floor): void
{
    db_exec('INSERT INTO sequences (name, current_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE current_value = GREATEST(current_value, VALUES(current_value))', [$name, $floor]);
}

/** Fill the tokens of an ID format: {prefix} {program} {year} {yy} {Y} {n:4} */
function students_format_parts(string $format, array $program, int $year): array
{
    $tokens = ['{prefix}' => (string) setting('student_id_prefix', 'GIMT'), '{program}' => (string) $program['code'], '{year}' => substr((string) $year, 2), '{yy}' => substr((string) $year, 2), '{Y}' => (string) $year];
    $filled = strtr($format, $tokens);
    if (!preg_match('/\{n(?::(\d+))?\}/', $filled, $m, PREG_OFFSET_CAPTURE)) {
        $filled .= '{n:4}';
        preg_match('/\{n(?::(\d+))?\}/', $filled, $m, PREG_OFFSET_CAPTURE);
    }
    $prefix = substr($filled, 0, $m[0][1]);
    $suffix = substr($filled, $m[0][1] + strlen($m[0][0]));
    $pad = (int) ($m[1][0] ?? 1);
    return [$prefix, $suffix, max(1, $pad)];
}

function students_id_formats(): array
{
    return [
        'student_uid' => (string) setting('student_id_format', '{prefix}{yy}{program}{n:4}'),
        'admission_no' => (string) setting('admission_no_format', 'ADM/{Y}/{n:5}'),
        'roll_no' => (string) setting('roll_no_format', '{program}{year}{n:3}'),
    ];
}

/**
 * Generate (and reserve) the next number for a student ID column.
 * $column: student_uid | admission_no | roll_no
 */
function students_generate_number(string $column, int $programId, int $year): string
{
    $program = students_program($programId);
    if (!$program) {
        throw new CrudException('Select a valid program before generating IDs.');
    }
    [$prefix, $suffix, $pad] = students_format_parts(students_id_formats()[$column], $program, $year);
    // admission numbers share one running serial (like the seeded data); other IDs run per prefix
    $seq = $column === 'admission_no' ? 'student_serial' : $column . ':' . $prefix;
    if ($suffix === '') {
        students_sequence_floor($seq, students_max_suffix($column, $prefix));
    }
    for ($i = 0; $i < 50; $i++) {
        $candidate = next_number($seq, $prefix . '{n:' . $pad . '}' . $suffix);
        if (!(int) db_value('SELECT COUNT(*) FROM students WHERE ' . db_quote_ident($column) . ' = ?', [$candidate])) {
            return $candidate;
        }
    }
    throw new CrudException('Unable to generate a unique ' . str_replace('_', ' ', $column) . '. Please enter it manually.');
}

/** Preview of the IDs that would be generated (does not reserve anything). */
function students_preview_numbers(int $programId, int $year): array
{
    $program = students_program($programId);
    if (!$program) {
        return [];
    }
    $out = [];
    foreach (students_id_formats() as $column => $format) {
        [$prefix, $suffix, $pad] = students_format_parts($format, $program, $year);
        $seq = $column === 'admission_no' ? 'student_serial' : $column . ':' . $prefix;
        $current = max((int) db_value('SELECT current_value FROM sequences WHERE name = ?', [$seq]), $suffix === '' ? students_max_suffix($column, $prefix) : 0);
        $out[$column] = $prefix . str_pad((string) ($current + 1), $pad, '0', STR_PAD_LEFT) . $suffix;
    }
    return $out;
}

/* ------------------------------------------------------------------
 * Duplicate detection
 * ------------------------------------------------------------------ */

/**
 * Find other students sharing an email / mobile / admission no / aadhaar / student id.
 * @return array field => list of {id, name, student_uid, status}
 */
function students_find_duplicates(array $values, ?int $excludeId = null): array
{
    $out = [];
    $name = students_name_sql('s');
    $base = "SELECT s.id, $name AS name, s.student_uid, s.status FROM students s WHERE ";
    $tail = ($excludeId ? ' AND s.id <> ' . (int) $excludeId : '') . ' ORDER BY s.id LIMIT 5';
    $checks = [];
    if (!empty($values['email'])) {
        $checks['email'] = ['LOWER(s.email) = ?', [strtolower(trim((string) $values['email']))]];
    }
    if (!empty($values['mobile']) && strlen(students_mobile_key($values['mobile'])) >= 7) {
        $checks['mobile'] = ["REPLACE(REPLACE(REPLACE(REPLACE(s.mobile, ' ', ''), '-', ''), '+', ''), '(', '') LIKE ?", ['%' . students_mobile_key($values['mobile'])]];
    }
    if (!empty($values['admission_no'])) {
        $checks['admission_no'] = ['s.admission_no = ?', [trim((string) $values['admission_no'])]];
    }
    if (!empty($values['student_uid'])) {
        $checks['student_uid'] = ['s.student_uid = ?', [trim((string) $values['student_uid'])]];
    }
    if (!empty($values['roll_no'])) {
        $checks['roll_no'] = ['s.roll_no = ?', [trim((string) $values['roll_no'])]];
    }
    if (!empty($values['aadhaar_no']) && strlen(students_digits($values['aadhaar_no'])) === 12) {
        $checks['aadhaar_no'] = ["REPLACE(s.aadhaar_no, ' ', '') = ?", [students_digits($values['aadhaar_no'])]];
    }
    foreach ($checks as $field => [$where, $args]) {
        $rows = db_all($base . $where . $tail, $args);
        if ($rows) {
            $out[$field] = array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'student_uid' => $r['student_uid'], 'status' => $r['status']], $rows);
        }
    }
    return $out;
}

/* ------------------------------------------------------------------
 * Parents / guardians
 * ------------------------------------------------------------------ */

/**
 * Validate parent rows posted by the student form: [{relation, name, phone, email, occupation, annual_income, is_emergency_contact}]
 * @return array [clean rows keyed by relation, errors keyed "parents.{relation}.{field}"]
 */
function students_validate_parents($parents): array
{
    $clean = [];
    $errors = [];
    if (!is_array($parents)) {
        return [$clean, $errors];
    }
    foreach ($parents as $p) {
        if (!is_array($p)) {
            continue;
        }
        $rel = strtolower(trim((string) ($p['relation'] ?? '')));
        if (!in_array($rel, ['father', 'mother', 'guardian'], true)) {
            continue;
        }
        $row = [
            'relation' => $rel,
            'name' => trim((string) ($p['name'] ?? '')),
            'phone' => trim((string) ($p['phone'] ?? '')) ?: null,
            'email' => strtolower(trim((string) ($p['email'] ?? ''))) ?: null,
            'occupation' => trim((string) ($p['occupation'] ?? '')) ?: null,
            'annual_income' => ($p['annual_income'] ?? '') === '' || ($p['annual_income'] ?? null) === null ? null : str_replace(',', '', (string) $p['annual_income']),
            'address' => trim((string) ($p['address'] ?? '')) ?: null,
            'relation_label' => trim((string) ($p['relation_label'] ?? '')) ?: null,
            'is_emergency_contact' => in_array($p['is_emergency_contact'] ?? false, [true, 1, '1', 'true', 'on'], true) ? 1 : 0,
        ];
        $hasOther = $row['phone'] || $row['email'] || $row['occupation'] || $row['annual_income'] !== null;
        if ($row['name'] === '' && $hasOther) {
            $errors["parents.$rel.name"] = 'Enter the name or clear the other details.';
        }
        $e = validate($row, ['name' => 'max:150', 'phone' => 'phone', 'email' => 'email|max:190', 'occupation' => 'max:100', 'annual_income' => 'numeric|min:0|max:9999999999', 'address' => 'max:255'],
            ['annual_income' => 'Annual income']);
        foreach ($e as $f => $msg) {
            $errors["parents.$rel.$f"] = $msg;
        }
        $clean[$rel] = $row;
    }
    return [$clean, $errors];
}

/** Upsert father / mother / guardian rows for a student (blank name removes the row). */
function students_sync_parents(int $studentId, array $rows): void
{
    foreach (['father', 'mother', 'guardian'] as $rel) {
        if (!array_key_exists($rel, $rows)) {
            continue;
        }
        $r = $rows[$rel];
        $existing = db_value('SELECT id FROM student_parents WHERE student_id = ? AND relation = ? ORDER BY id LIMIT 1', [$studentId, $rel]);
        if ($r['name'] === '') {
            if ($existing) {
                db_exec('DELETE FROM student_parents WHERE student_id = ? AND relation = ?', [$studentId, $rel]);
            }
            continue;
        }
        $data = ['name' => mb_substr($r['name'], 0, 150), 'phone' => $r['phone'], 'email' => $r['email'], 'occupation' => $r['occupation'],
            'annual_income' => $r['annual_income'], 'address' => $r['address'], 'is_emergency_contact' => $r['is_emergency_contact']];
        if ($existing) {
            db_update('student_parents', $data, 'id = ?', [(int) $existing]);
        } else {
            db_insert('student_parents', $data + ['student_id' => $studentId, 'relation' => $rel]);
        }
    }
}

/** Keep the denormalised students.father_name / mother_name / guardian_* columns in sync with student_parents. */
function students_refresh_parent_columns(int $studentId): void
{
    $rows = db_all('SELECT relation, name, phone FROM student_parents WHERE student_id = ? ORDER BY id', [$studentId]);
    $by = [];
    foreach ($rows as $r) {
        $by[$r['relation']] = $by[$r['relation']] ?? $r;
    }
    $guardian = $by['guardian'] ?? $by['father'] ?? $by['mother'] ?? null;
    db_update('students', [
        'father_name' => $by['father']['name'] ?? null,
        'mother_name' => $by['mother']['name'] ?? null,
        'guardian_name' => $guardian['name'] ?? null,
        'guardian_relation' => $guardian ? (isset($by['guardian']) ? ((string) db_value("SELECT COALESCE(NULLIF(guardian_relation, ''), 'Guardian') FROM students WHERE id = ?", [$studentId]) ?: 'Guardian') : ucfirst($guardian['relation'])) : null,
        'guardian_phone' => $guardian['phone'] ?? null,
    ], 'id = ?', [$studentId]);
}

/* ------------------------------------------------------------------
 * Academic record (student_academic) helpers
 * ------------------------------------------------------------------ */

/** Ensure the student has a "studying" academic row that matches the students table. */
function students_sync_current_academic(int $studentId): void
{
    $s = db_row('SELECT id, program_id, current_semester, section_id, roll_no, status FROM students WHERE id = ?', [$studentId]);
    if (!$s || $s['status'] !== 'active') {
        return;
    }
    $sessionId = null;
    if ($s['section_id']) {
        $sessionId = db_value('SELECT academic_session_id FROM sections WHERE id = ?', [(int) $s['section_id']]);
    }
    $sessionId = $sessionId ? (int) $sessionId : current_session_id();
    $row = db_row("SELECT id FROM student_academic WHERE student_id = ? AND status = 'studying' ORDER BY id DESC LIMIT 1", [$studentId]);
    $data = ['program_id' => (int) $s['program_id'], 'semester_no' => (int) $s['current_semester'], 'section_id' => $s['section_id'] ? (int) $s['section_id'] : null, 'roll_no' => $s['roll_no']];
    $clash = db_value('SELECT id FROM student_academic WHERE student_id = ? AND semester_no = ? AND academic_session_id <=> ?' . ($row ? ' AND id <> ' . (int) $row['id'] : ''), [$studentId, $data['semester_no'], $sessionId]);
    if ($clash) {
        db_update('student_academic', $data + ['status' => 'studying'], 'id = ?', [(int) $clash]);
        if ($row) {
            db_exec('DELETE FROM student_academic WHERE id = ?', [(int) $row['id']]);
        }
        return;
    }
    if ($row) {
        db_update('student_academic', $data + ['academic_session_id' => $sessionId], 'id = ?', [(int) $row['id']]);
    } else {
        db_insert('student_academic', $data + ['student_id' => $studentId, 'academic_session_id' => $sessionId, 'status' => 'studying']);
    }
}

/* ------------------------------------------------------------------
 * Profile aggregates (read-only views over other units' tables)
 * ------------------------------------------------------------------ */

function students_profile_row(int $id): ?array
{
    $name = students_name_sql('s');
    $row = db_row(
        "SELECT s.*, $name AS full_name, p.name AS program_full_name, p.short_name AS program_name, p.code AS program_code, p.total_semesters, p.level AS program_level,
                d.name AS department_name, c.name AS course_name, b.name AS batch_name, b.start_year AS batch_start, b.end_year AS batch_end,
                sc.name AS section_name, ses.name AS session_name, u.name AS created_by_name
         FROM students s
         JOIN programs p ON p.id = s.program_id
         LEFT JOIN departments d ON d.id = s.department_id
         LEFT JOIN courses c ON c.id = s.course_id
         LEFT JOIN batches b ON b.id = s.batch_id
         LEFT JOIN sections sc ON sc.id = s.section_id
         LEFT JOIN academic_sessions ses ON ses.id = s.academic_session_id
         LEFT JOIN users u ON u.id = s.created_by
         WHERE s.id = ?",
        [$id]
    );
    if (!$row) {
        return null;
    }
    $row['aadhaar_masked'] = students_mask_aadhaar($row['aadhaar_no']);
    unset($row['aadhaar_no']);
    $row['id'] = (int) $row['id'];
    $row['age'] = $row['dob'] ? (int) (new DateTime($row['dob']))->diff(new DateTime('today'))->y : null;
    $row['valid_until'] = students_card_validity($row);
    return $row;
}

/** ID card validity: end of batch, else end of the current session. */
function students_card_validity(array $row): string
{
    if (!empty($row['batch_end'])) {
        return $row['batch_end'] . '-06-30';
    }
    $end = db_value('SELECT end_date FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1');
    return $end ?: date('Y-m-d', strtotime('+1 year'));
}

function students_parents(int $id): array
{
    $rows = db_all("SELECT id, relation, name, phone, email, occupation, annual_income, address, is_emergency_contact FROM student_parents WHERE student_id = ?
                    ORDER BY FIELD(relation, 'father', 'mother', 'guardian', 'other'), id", [$id]);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['is_emergency_contact'] = (bool) $r['is_emergency_contact'];
    }
    return $rows;
}

/** Attendance totals for a student (late counts as present per settings, half day = 0.5). */
function students_attendance_stats(int $id, ?int $sessionId = null): array
{
    $lateCounts = setting('late_counts_as_present', '1') === '1';
    $args = [$id];
    $where = "ar.person_type = 'student' AND ar.person_id = ?";
    if ($sessionId) {
        $where .= ' AND a.academic_session_id = ?';
        $args[] = $sessionId;
    }
    $row = db_row("SELECT COUNT(*) AS total,
            SUM(ar.status = 'present') AS present, SUM(ar.status = 'absent') AS absent, SUM(ar.status = 'late') AS late,
            SUM(ar.status = 'leave') AS leave_count, SUM(ar.status = 'half_day') AS half_day
        FROM attendance_records ar JOIN attendance a ON a.id = ar.attendance_id WHERE $where", $args) ?? [];
    $total = (int) ($row['total'] ?? 0);
    $attended = (int) ($row['present'] ?? 0) + ($lateCounts ? (int) ($row['late'] ?? 0) : 0) + 0.5 * (int) ($row['half_day'] ?? 0);
    return [
        'total' => $total, 'present' => (int) ($row['present'] ?? 0), 'absent' => (int) ($row['absent'] ?? 0), 'late' => (int) ($row['late'] ?? 0),
        'leave' => (int) ($row['leave_count'] ?? 0), 'half_day' => (int) ($row['half_day'] ?? 0),
        'attended' => $attended, 'percent' => $total ? round($attended * 100 / $total, 1) : null,
        'threshold' => (float) setting('min_attendance_percent', 75),
    ];
}

function students_fee_stats(int $id): array
{
    $row = db_row("SELECT COUNT(*) AS invoices, COALESCE(SUM(net_amount), 0) AS net, COALESCE(SUM(paid_amount), 0) AS paid, COALESCE(SUM(balance_amount), 0) AS balance,
            COALESCE(SUM(CASE WHEN balance_amount > 0 AND (status = 'overdue' OR (due_date IS NOT NULL AND due_date < CURDATE())) THEN balance_amount ELSE 0 END), 0) AS overdue,
            COALESCE(SUM(discount_amount), 0) AS discount, COALESCE(SUM(scholarship_amount), 0) AS scholarship, COALESCE(SUM(fine_amount), 0) AS fine,
            MIN(CASE WHEN balance_amount > 0 THEN due_date END) AS next_due
        FROM student_fees WHERE student_id = ? AND status NOT IN ('cancelled', 'waived')", [$id]) ?? [];
    return [
        'invoices' => (int) ($row['invoices'] ?? 0), 'net' => (float) ($row['net'] ?? 0), 'paid' => (float) ($row['paid'] ?? 0), 'balance' => (float) ($row['balance'] ?? 0),
        'overdue' => (float) ($row['overdue'] ?? 0), 'discount' => (float) ($row['discount'] ?? 0), 'scholarship' => (float) ($row['scholarship'] ?? 0),
        'fine' => (float) ($row['fine'] ?? 0), 'next_due' => $row['next_due'] ?? null,
    ];
}

/** Latest SGPA / CGPA from results (falls back to the academic history). */
function students_gpa(int $id): array
{
    $r = db_row('SELECT r.sgpa, r.cgpa, r.semester_no, r.result_status, r.backlog_count FROM results r WHERE r.student_id = ? AND (r.cgpa IS NOT NULL OR r.sgpa IS NOT NULL)
                 ORDER BY r.semester_no DESC, r.id DESC LIMIT 1', [$id]);
    if ($r) {
        return ['sgpa' => $r['sgpa'] !== null ? (float) $r['sgpa'] : null, 'cgpa' => $r['cgpa'] !== null ? (float) $r['cgpa'] : null, 'semester' => (int) $r['semester_no'],
            'result_status' => $r['result_status'], 'backlogs' => (int) db_value("SELECT COUNT(*) FROM exam_marks WHERE student_id = ? AND is_pass = 0 AND status IN ('verified', 'published')", [$id]), 'source' => 'results'];
    }
    $a = db_row('SELECT sgpa, cgpa, semester_no FROM student_academic WHERE student_id = ? AND (cgpa IS NOT NULL OR sgpa IS NOT NULL) ORDER BY semester_no DESC, id DESC LIMIT 1', [$id]);
    if ($a) {
        return ['sgpa' => $a['sgpa'] !== null ? (float) $a['sgpa'] : null, 'cgpa' => $a['cgpa'] !== null ? (float) $a['cgpa'] : null, 'semester' => (int) $a['semester_no'], 'result_status' => null, 'backlogs' => 0, 'source' => 'academic'];
    }
    return ['sgpa' => null, 'cgpa' => null, 'semester' => null, 'result_status' => null, 'backlogs' => 0, 'source' => null];
}

function students_library_stats(int $id): array
{
    $member = db_row('SELECT id, membership_no, max_books, status, valid_until FROM library_members WHERE student_id = ?', [$id]);
    if (!$member) {
        return ['member' => null, 'issued' => 0, 'overdue' => 0, 'total' => 0, 'fines' => 0.0];
    }
    $r = db_row("SELECT COUNT(*) AS total, SUM(status = 'issued') AS issued, SUM(status = 'issued' AND due_date < CURDATE()) AS overdue, COALESCE(SUM(fine_amount), 0) AS fines
                 FROM library_transactions WHERE member_id = ?", [(int) $member['id']]) ?? [];
    return ['member' => $member, 'issued' => (int) ($r['issued'] ?? 0), 'overdue' => (int) ($r['overdue'] ?? 0), 'total' => (int) ($r['total'] ?? 0), 'fines' => (float) ($r['fines'] ?? 0)];
}

function students_hostel_current(int $id): ?array
{
    return db_row("SELECT ha.id, ha.allocated_on, ha.fee_amount, ha.status, h.name AS hostel_name, h.code AS hostel_code, h.type AS hostel_type, h.warden_name, h.warden_phone,
                          hr.room_no, hr.room_type, hr.floor_no, hb.bed_no
                   FROM hostel_allocations ha JOIN hostels h ON h.id = ha.hostel_id JOIN hostel_rooms hr ON hr.id = ha.room_id LEFT JOIN hostel_beds hb ON hb.id = ha.bed_id
                   WHERE ha.student_id = ? AND ha.status = 'active' ORDER BY ha.allocated_on DESC, ha.id DESC LIMIT 1", [$id]);
}

function students_transport_current(int $id): ?array
{
    return db_row("SELECT ta.id, ta.start_date, ta.fee_amount, ta.status, tr.name AS route_name, tr.code AS route_code, ts.name AS stop_name, ts.pickup_time, v.vehicle_no
                   FROM transport_allocations ta JOIN transport_routes tr ON tr.id = ta.route_id LEFT JOIN transport_stops ts ON ts.id = ta.stop_id LEFT JOIN vehicles v ON v.id = ta.vehicle_id
                   WHERE ta.student_id = ? AND ta.status = 'active' ORDER BY ta.start_date DESC, ta.id DESC LIMIT 1", [$id]);
}

function students_placement_stats(int $id): array
{
    $apps = (int) db_value('SELECT COUNT(*) FROM placement_applications WHERE student_id = ?', [$id]);
    $offers = db_row("SELECT COUNT(*) AS n, MAX(package_lpa) AS best FROM placement_offers WHERE student_id = ? AND status <> 'revoked'", [$id]) ?? [];
    return ['applications' => $apps, 'offers' => (int) ($offers['n'] ?? 0), 'best_package' => $offers['best'] !== null ? (float) $offers['best'] : null];
}

function students_document_stats(int $id): array
{
    $r = db_row("SELECT COUNT(*) AS total, SUM(status = 'verified') AS verified, SUM(status = 'pending') AS pending, SUM(status = 'rejected') AS rejected FROM student_documents WHERE student_id = ?", [$id]) ?? [];
    return ['total' => (int) ($r['total'] ?? 0), 'verified' => (int) ($r['verified'] ?? 0), 'pending' => (int) ($r['pending'] ?? 0), 'rejected' => (int) ($r['rejected'] ?? 0)];
}

/* ------------------------------------------------------------------
 * Promotion
 * ------------------------------------------------------------------ */

/** Default target for a promotion from ($semester, $sessionId): odd -> even stays in the session, even -> odd moves to the next one. */
function students_promotion_target(array $program, int $semester, ?int $sessionId): array
{
    $isFinal = $semester >= (int) $program['total_semesters'];
    $toSession = $sessionId;
    if ($sessionId && $semester % 2 === 0) {
        $start = db_value('SELECT start_date FROM academic_sessions WHERE id = ?', [$sessionId]);
        $next = $start ? db_value('SELECT id FROM academic_sessions WHERE start_date > ? ORDER BY start_date LIMIT 1', [$start]) : null;
        $toSession = $next ? (int) $next : $sessionId;
    }
    return ['is_final' => $isFinal, 'to_semester' => $isFinal ? null : $semester + 1, 'to_session_id' => $toSession];
}

/**
 * Students eligible for promotion from a program/semester (optionally one section) with attendance and result indicators.
 */
function students_promotion_preview(int $programId, int $semester, ?int $sectionId, ?int $sessionId): array
{
    $program = students_program($programId);
    if (!$program) {
        throw new CrudException('Program not found.');
    }
    if ($semester < 1 || $semester > (int) $program['total_semesters']) {
        throw new CrudValidationException(['semester' => 'Semester must be between 1 and ' . $program['total_semesters'] . ' for ' . $program['short_name'] . '.']);
    }
    $name = students_name_sql('s');
    $args = [$programId, $semester];
    $where = "s.program_id = ? AND s.current_semester = ? AND s.status = 'active'";
    if ($sectionId) {
        $where .= ' AND s.section_id = ?';
        $args[] = $sectionId;
    }
    $rows = db_all("SELECT s.id, $name AS name, s.student_uid, s.roll_no, s.photo, s.gender, s.section_id, sc.name AS section_name, b.name AS batch_name
                    FROM students s LEFT JOIN sections sc ON sc.id = s.section_id LEFT JOIN batches b ON b.id = s.batch_id
                    WHERE $where ORDER BY sc.name, s.roll_no, s.first_name LIMIT 1000", $args);
    $ids = array_map(fn ($r) => (int) $r['id'], $rows);
    $att = [];
    $res = [];
    if ($ids) {
        $in = implode(',', $ids);
        $lateCounts = setting('late_counts_as_present', '1') === '1' ? " OR ar.status = 'late'" : '';
        $attArgs = [];
        $attWhere = '';
        if ($sessionId) {
            $attWhere = ' AND a.academic_session_id = ?';
            $attArgs[] = $sessionId;
        }
        foreach (db_all("SELECT ar.person_id, COUNT(*) AS total, SUM(ar.status = 'present'$lateCounts) + 0.5 * SUM(ar.status = 'half_day') AS attended
                         FROM attendance_records ar JOIN attendance a ON a.id = ar.attendance_id
                         WHERE ar.person_type = 'student' AND ar.person_id IN ($in)$attWhere GROUP BY ar.person_id", $attArgs) as $a) {
            $att[(int) $a['person_id']] = $a['total'] ? round($a['attended'] * 100 / $a['total'], 1) : null;
        }
        foreach (db_all("SELECT r.student_id, r.sgpa, r.cgpa, r.result_status, r.backlog_count FROM results r
                         JOIN (SELECT student_id, MAX(id) AS id FROM results WHERE student_id IN ($in) GROUP BY student_id) x ON x.id = r.id") as $r) {
            $res[(int) $r['student_id']] = $r;
        }
        foreach (db_all("SELECT sa.student_id, sa.cgpa FROM student_academic sa JOIN (SELECT student_id, MAX(semester_no) AS sem FROM student_academic WHERE student_id IN ($in) AND cgpa IS NOT NULL GROUP BY student_id) x
                         ON x.student_id = sa.student_id AND x.sem = sa.semester_no WHERE sa.cgpa IS NOT NULL") as $r) {
            if (!isset($res[(int) $r['student_id']])) {
                $res[(int) $r['student_id']] = ['cgpa' => $r['cgpa'], 'sgpa' => null, 'result_status' => null, 'backlog_count' => 0];
            }
        }
    }
    $target = students_promotion_target($program, $semester, $sessionId);
    $minAtt = (float) setting('min_attendance_percent', 75);
    $list = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $result = $res[$id] ?? null;
        $flags = [];
        if (isset($att[$id]) && $att[$id] !== null && $att[$id] < $minAtt) {
            $flags[] = 'low_attendance';
        }
        if ($result && (in_array($result['result_status'] ?? '', ['FAIL', 'BACKLOG'], true) || (int) ($result['backlog_count'] ?? 0) > 0)) {
            $flags[] = 'backlog';
        }
        $list[] = [
            'id' => $id, 'name' => $r['name'], 'student_uid' => $r['student_uid'], 'roll_no' => $r['roll_no'], 'photo' => $r['photo'], 'gender' => $r['gender'],
            'section_id' => $r['section_id'] ? (int) $r['section_id'] : null, 'section_name' => $r['section_name'], 'batch_name' => $r['batch_name'],
            'attendance_percent' => $att[$id] ?? null, 'cgpa' => $result && $result['cgpa'] !== null ? (float) $result['cgpa'] : null,
            'result_status' => $result['result_status'] ?? null, 'backlogs' => (int) ($result['backlog_count'] ?? 0), 'flags' => $flags,
            'suggested_action' => $target['is_final'] ? 'pass_out' : 'promote',
        ];
    }
    $targetSections = [];
    if (!$target['is_final']) {
        $sargs = [$programId, $target['to_semester']];
        $swhere = 'sc.program_id = ? AND sc.semester_no = ?';
        if ($target['to_session_id']) {
            $swhere .= ' AND sc.academic_session_id = ?';
            $sargs[] = $target['to_session_id'];
        }
        $targetSections = db_all("SELECT sc.id, sc.name, sc.capacity, sc.academic_session_id, (SELECT COUNT(*) FROM students s WHERE s.section_id = sc.id AND s.status = 'active') AS strength
                                  FROM sections sc WHERE $swhere ORDER BY sc.name", $sargs);
    }
    return [
        'program' => ['id' => (int) $program['id'], 'name' => $program['name'], 'short_name' => $program['short_name'], 'total_semesters' => (int) $program['total_semesters']],
        'semester' => $semester, 'section_id' => $sectionId, 'session_id' => $sessionId,
        'target' => $target, 'target_sections' => array_map(fn ($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'capacity' => (int) $s['capacity'], 'strength' => (int) $s['strength'], 'academic_session_id' => $s['academic_session_id'] ? (int) $s['academic_session_id'] : null], $targetSections),
        'students' => $list, 'min_attendance' => $minAtt,
    ];
}

/**
 * Find (or create when allowed) the section with the same name in the target semester/session.
 */
function students_resolve_target_section(int $programId, ?int $fromSectionId, int $toSemester, ?int $toSessionId, bool $create, array &$created): ?int
{
    if (!$fromSectionId) {
        return null;
    }
    $from = db_row('SELECT id, name, batch_id, capacity, classroom_id, class_teacher_id FROM sections WHERE id = ?', [$fromSectionId]);
    if (!$from) {
        return null;
    }
    $id = db_value('SELECT id FROM sections WHERE program_id = ? AND semester_no = ? AND academic_session_id <=> ? AND name = ?', [$programId, $toSemester, $toSessionId, $from['name']]);
    if ($id) {
        return (int) $id;
    }
    if (!$create) {
        return null;
    }
    $newId = db_insert('sections', ['program_id' => $programId, 'batch_id' => $from['batch_id'], 'semester_no' => $toSemester, 'academic_session_id' => $toSessionId,
        'name' => $from['name'], 'capacity' => $from['capacity'], 'classroom_id' => $from['classroom_id'], 'class_teacher_id' => $from['class_teacher_id'], 'status' => 'active']);
    $created[] = $from['name'];
    return $newId;
}

/**
 * Run a promotion in one transaction.
 * $p: program_id, from_semester, from_section_id?, from_session_id?, to_semester?, to_session_id?, section_mode (same|fixed|none),
 *     to_section_id?, create_sections (bool), remarks?, items: [{student_id, action: promote|detain|pass_out|skip, to_section_id?}]
 */
function students_promote(array $p): array
{
    $errors = validate($p, [
        'program_id' => 'required|integer|exists:programs,id', 'from_semester' => 'required|integer|min:1|max:12', 'from_session_id' => 'integer|exists:academic_sessions,id',
        'to_semester' => 'integer|min:1|max:12', 'to_session_id' => 'integer|exists:academic_sessions,id', 'from_section_id' => 'integer|exists:sections,id',
        'to_section_id' => 'integer|exists:sections,id', 'section_mode' => 'in:same,fixed,none', 'remarks' => 'max:255',
    ], ['from_semester' => 'Current semester', 'to_semester' => 'Target semester', 'to_session_id' => 'Target session', 'from_session_id' => 'Current session']);
    $items = is_array($p['items'] ?? null) ? $p['items'] : [];
    if (!$items) {
        $errors['items'] = 'Select at least one student.';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $programId = (int) $p['program_id'];
    $program = students_program($programId);
    $fromSem = (int) $p['from_semester'];
    $total = (int) $program['total_semesters'];
    $toSem = isset($p['to_semester']) && $p['to_semester'] !== '' && $p['to_semester'] !== null ? (int) $p['to_semester'] : null;
    $fromSession = !empty($p['from_session_id']) ? (int) $p['from_session_id'] : current_session_id();
    $toSession = !empty($p['to_session_id']) ? (int) $p['to_session_id'] : $fromSession;
    $mode = $p['section_mode'] ?? 'same';
    $fixedSection = !empty($p['to_section_id']) ? (int) $p['to_section_id'] : null;
    $createSections = in_array($p['create_sections'] ?? false, [true, 1, '1', 'true'], true);

    $actions = [];
    foreach ($items as $it) {
        $sid = (int) ($it['student_id'] ?? 0);
        $act = (string) ($it['action'] ?? 'promote');
        if (!$sid || !in_array($act, ['promote', 'detain', 'pass_out', 'skip'], true)) {
            throw new CrudValidationException(['items' => 'One or more selected students have an invalid action.']);
        }
        $actions[$sid] = ['action' => $act, 'to_section_id' => !empty($it['to_section_id']) ? (int) $it['to_section_id'] : null];
    }
    $needsTarget = (bool) array_filter($actions, fn ($a) => $a['action'] === 'promote');
    if ($needsTarget) {
        if ($fromSem >= $total) {
            throw new CrudValidationException(['to_semester' => "Semester $fromSem is the final semester of {$program['short_name']}. Mark these students as passed out instead."]);
        }
        if (!$toSem || $toSem <= $fromSem || $toSem > $total) {
            throw new CrudValidationException(['to_semester' => 'Target semester must be after semester ' . $fromSem . ' and at most ' . $total . '.']);
        }
        if ($mode === 'fixed') {
            if (!$fixedSection) {
                throw new CrudValidationException(['to_section_id' => 'Select the target section.']);
            }
            $ok = db_value('SELECT COUNT(*) FROM sections WHERE id = ? AND program_id = ? AND semester_no = ?', [$fixedSection, $programId, $toSem]);
            if (!$ok) {
                throw new CrudValidationException(['to_section_id' => 'The target section does not belong to ' . $program['short_name'] . ' semester ' . $toSem . '.']);
            }
        }
    }
    if (array_filter($actions, fn ($a) => $a['action'] === 'pass_out') && $fromSem < $total) {
        throw new CrudValidationException(['items' => "Only final-semester students can be marked as passed out ({$program['short_name']} has $total semesters)."]);
    }

    $ids = array_keys($actions);
    $in = implode(',', array_map('intval', $ids));
    $students = [];
    foreach (db_all("SELECT id, first_name, last_name, program_id, current_semester, section_id, roll_no, status FROM students WHERE id IN ($in)") as $s) {
        $students[(int) $s['id']] = $s;
    }
    $stale = [];
    foreach ($ids as $sid) {
        $s = $students[$sid] ?? null;
        if (!$s || (int) $s['program_id'] !== $programId || (int) $s['current_semester'] !== $fromSem || $s['status'] !== 'active') {
            $stale[] = $sid;
        }
    }
    if ($stale) {
        throw new CrudException(count($stale) . ' selected student(s) are no longer active in ' . $program['short_name'] . ' semester ' . $fromSem . '. Refresh the preview and try again.');
    }

    $preview = students_promotion_preview($programId, $fromSem, null, $fromSession);
    $metrics = [];
    foreach ($preview['students'] as $ps) {
        $metrics[$ps['id']] = $ps;
    }

    $userId = user_id();
    $now = date('Y-m-d H:i:s');
    $sessionName = $toSession ? (string) db_value('SELECT name FROM academic_sessions WHERE id = ?', [$toSession]) : '';
    $fromSessionName = $fromSession ? (string) db_value('SELECT name FROM academic_sessions WHERE id = ?', [$fromSession]) : '';

    return db_transaction(function () use ($actions, $students, $metrics, $programId, $program, $fromSem, $toSem, $fromSession, $toSession, $mode, $fixedSection, $createSections, $userId, $now, $sessionName, $fromSessionName, $p) {
        $summary = ['promote' => 0, 'detain' => 0, 'pass_out' => 0, 'skip' => 0];
        $createdSections = [];
        $sectionMap = [];
        $details = [];
        $noSection = 0;
        foreach ($actions as $sid => $a) {
            $s = $students[$sid];
            $m = $metrics[$sid] ?? [];
            $att = $m['attendance_percent'] ?? null;
            $cgpa = $m['cgpa'] ?? null;
            $act = $a['action'];
            $summary[$act]++;
            if ($act === 'skip') {
                $details[] = ['student_id' => $sid, 'action' => 'skip'];
                continue;
            }
            $closeStatus = ['promote' => 'promoted', 'detain' => 'detained', 'pass_out' => 'completed'][$act];
            // close (or create) the current academic record
            $updated = db_exec("UPDATE student_academic SET status = ?, promoted_at = ?, promoted_by = ?, attendance_percent = COALESCE(?, attendance_percent), cgpa = COALESCE(?, cgpa), remarks = ?
                                WHERE student_id = ? AND semester_no = ? AND status = 'studying'",
                [$closeStatus, $now, $userId, $att, $cgpa, mb_substr((string) ($p['remarks'] ?? '') ?: ucfirst(str_replace('_', ' ', $act)) . ' via promotion', 0, 255), $sid, $fromSem]);
            if (!$updated) {
                db_exec('INSERT INTO student_academic (student_id, academic_session_id, program_id, semester_no, section_id, roll_no, status, attendance_percent, cgpa, promoted_at, promoted_by, remarks)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE status = VALUES(status), promoted_at = VALUES(promoted_at), promoted_by = VALUES(promoted_by), attendance_percent = VALUES(attendance_percent), cgpa = VALUES(cgpa)',
                    [$sid, $fromSession, $programId, $fromSem, $s['section_id'], $s['roll_no'], $closeStatus, $att, $cgpa, $now, $userId, ucfirst(str_replace('_', ' ', $act)) . ' via promotion']);
            }
            if ($act === 'promote') {
                if ($a['to_section_id']) {
                    $target = $a['to_section_id'];
                } elseif ($mode === 'fixed') {
                    $target = $fixedSection;
                } elseif ($mode === 'same') {
                    $key = (int) $s['section_id'];
                    if (!array_key_exists($key, $sectionMap)) {
                        $sectionMap[$key] = students_resolve_target_section($programId, $key ?: null, $toSem, $toSession, $createSections, $createdSections);
                    }
                    $target = $sectionMap[$key];
                } else {
                    $target = null;
                }
                if (!$target) {
                    $noSection++;
                }
                db_update('students', ['current_semester' => $toSem, 'section_id' => $target], 'id = ?', [$sid]);
                db_exec("INSERT INTO student_academic (student_id, academic_session_id, program_id, semester_no, section_id, roll_no, status)
                         VALUES (?, ?, ?, ?, ?, ?, 'studying')
                         ON DUPLICATE KEY UPDATE status = 'studying', section_id = VALUES(section_id), roll_no = VALUES(roll_no), program_id = VALUES(program_id)",
                    [$sid, $toSession, $programId, $toSem, $target, $s['roll_no']]);
                $details[] = ['student_id' => $sid, 'action' => 'promote', 'to_section_id' => $target];
            } elseif ($act === 'pass_out') {
                db_update('students', ['status' => 'graduated', 'status_reason' => mb_substr('Passed out after semester ' . $fromSem . ($fromSessionName ? " ($fromSessionName)" : ''), 0, 255)], 'id = ?', [$sid]);
                $details[] = ['student_id' => $sid, 'action' => 'pass_out'];
            } else {
                $details[] = ['student_id' => $sid, 'action' => 'detain'];
            }
        }
        $ref = next_number('student_promotion', 'PRM/{session}/{n:4}');
        $sectionId = !empty($p['from_section_id']) ? (int) $p['from_section_id'] : null;
        db_insert('student_promotions', [
            'reference_no' => $ref, 'program_id' => $programId, 'from_semester' => $fromSem, 'to_semester' => $summary['promote'] ? $toSem : null,
            'from_section_id' => $sectionId, 'from_session_id' => $fromSession, 'to_session_id' => $summary['promote'] ? $toSession : null,
            'promoted_count' => $summary['promote'], 'detained_count' => $summary['detain'], 'passed_out_count' => $summary['pass_out'], 'skipped_count' => $summary['skip'],
            'details' => json_encode($details), 'remarks' => mb_substr((string) ($p['remarks'] ?? ''), 0, 255) ?: null, 'created_by' => $userId,
        ]);
        $parts = [];
        if ($summary['promote']) {
            $parts[] = "promoted {$summary['promote']} to semester $toSem" . ($sessionName ? " ($sessionName)" : '');
        }
        if ($summary['pass_out']) {
            $parts[] = "marked {$summary['pass_out']} as passed out";
        }
        if ($summary['detain']) {
            $parts[] = "detained {$summary['detain']}";
        }
        log_activity('promote', 'students', $ref, mb_substr("Promotion $ref — {$program['short_name']} semester $fromSem: " . (implode(', ', $parts) ?: 'no changes'), 0, 500), 'success',
            ['program_id' => $programId, 'from_semester' => $fromSem, 'to_semester' => $toSem, 'counts' => $summary]);
        if ($summary['pass_out']) {
            notify('perm:alumni', 'system', 'Students passed out', $summary['pass_out'] . ' ' . $program['short_name'] . ' students were marked as passed out and are ready for the alumni network.', 'students?f.status=graduated', 'graduation-cap');
        }
        return ['reference_no' => $ref, 'promoted' => $summary['promote'], 'detained' => $summary['detain'], 'passed_out' => $summary['pass_out'], 'skipped' => $summary['skip'],
            'created_sections' => array_values(array_unique($createdSections)), 'without_section' => $noSection, 'to_semester' => $toSem, 'to_session' => $sessionName];
    });
}

/* ------------------------------------------------------------------
 * ID cards (shared by GET /api/students/id-cards and admin/print/id-cards.php)
 * ------------------------------------------------------------------ */

/**
 * Students for ID cards. $q: ids (comma list) | program_id, section_id, batch_id, semester, department_id, status (default active, 'all'), q (search)
 * @return array{rows: array, total: int, by_ids: bool, empty_selection: bool}
 */
function students_card_rows(array $q, int $limit = 300): array
{
    $where = [];
    $args = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($q['ids'] ?? ''))))));
    if ($ids) {
        $where[] = 's.id IN (' . implode(',', $ids) . ')';
    } else {
        foreach (['program_id' => 's.program_id', 'section_id' => 's.section_id', 'batch_id' => 's.batch_id', 'semester' => 's.current_semester', 'department_id' => 's.department_id'] as $k => $col) {
            if (!empty($q[$k])) {
                $where[] = "$col = ?";
                $args[] = (int) $q[$k];
            }
        }
        $status = (string) ($q['status'] ?? 'active');
        if ($status !== 'all') {
            $where[] = 's.status = ?';
            $args[] = array_key_exists($status, students_status_options()) ? $status : 'active';
        }
        $term = trim((string) ($q['q'] ?? ''));
        if ($term !== '') {
            $where[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_uid LIKE ? OR s.roll_no LIKE ? OR CONCAT(s.first_name, ' ', COALESCE(s.last_name, '')) LIKE ?)";
            array_push($args, ...array_fill(0, 5, '%' . mb_substr($term, 0, 60) . '%'));
        }
    }
    $emptySelection = !$ids && empty($q['program_id']) && empty($q['section_id']) && empty($q['batch_id']) && empty($q['department_id']) && trim((string) ($q['q'] ?? '')) === '';
    if ($emptySelection) {
        return ['rows' => [], 'total' => 0, 'by_ids' => false, 'empty_selection' => true];
    }
    $w = implode(' AND ', $where) ?: '1 = 1';
    $name = students_name_sql('s');
    $total = (int) db_value("SELECT COUNT(*) FROM students s WHERE $w", $args);
    $rows = db_all("SELECT s.id, $name AS full_name, s.student_uid, s.roll_no, s.photo, s.gender, s.dob, s.blood_group, s.mobile, s.emergency_contact_name, s.emergency_contact_phone,
                           s.guardian_phone, s.address, s.city, s.state, s.pincode, s.current_semester, s.status,
                           p.short_name AS program_name, p.name AS program_full_name, c.name AS course_name, b.name AS batch_name, b.end_year AS batch_end, sc.name AS section_name
                    FROM students s JOIN programs p ON p.id = s.program_id LEFT JOIN courses c ON c.id = s.course_id LEFT JOIN batches b ON b.id = s.batch_id LEFT JOIN sections sc ON sc.id = s.section_id
                    WHERE $w ORDER BY p.sort_order, s.current_semester, sc.name, s.roll_no, s.first_name LIMIT " . max(1, min(1000, $limit)), $args);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['current_semester'] = (int) $r['current_semester'];
        $r['valid_until'] = students_card_validity($r);
    }
    unset($r);
    return ['rows' => $rows, 'total' => $total, 'by_ids' => (bool) $ids, 'empty_selection' => false];
}

/** Tidy a typed name: collapse spaces and title-case it only when typed all-lower or all-upper ("rahul" / "RAHUL" -> "Rahul", "McDonald" kept). */
function students_name_case(string $v): string
{
    $v = trim(preg_replace('/\s+/', ' ', $v));
    if ($v === mb_strtolower($v) || ($v === mb_strtoupper($v) && mb_strlen(preg_replace('/[^\p{L}]/u', '', $v)) > 3)) {
        return mb_convert_case($v, MB_CASE_TITLE);
    }
    return $v;
}
