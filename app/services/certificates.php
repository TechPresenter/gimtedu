<?php
/**
 * Certificates domain: types, extra fields, placeholders, rendering (print / preview / issue snapshot),
 * issue + approval workflow, numbering, verification hash and public verification.
 *
 * Rendering pipeline (one renderer for every surface):
 *   cert_validate_issue() -> cert_compose() (placeholders filled, escaped) -> cert_sheet() (A4 design)
 *   -> cert_document() (full HTML: print view, iframe preview, embed)
 *
 * Certificate numbers: GIMT-{CODE}-{YEAR}-{00001} (no slashes so the pretty URL /verify-certificate/{no} works
 * behind every web server). Verification hash: HMAC-SHA256(no|student|type|issue_date, app key).
 *
 * Template syntax: {{placeholder}} (values escaped), optional blocks {{#key}} … {{/key}} (kept only when key has a
 * value) and {{^key}} … {{/key}} (kept only when key is empty). A placeholder with no value blocks issuing unless it
 * sits inside an optional block for that key.
 */

// rate_limit() lives in the API helpers; the public verification page loads this service without the API.
if (!function_exists('rate_limit')) {
    require_once APP_ROOT . '/app/api.php';
}

/* ------------------------------------------------------------------
 * Definitions
 * ------------------------------------------------------------------ */

/** Certificate types with their extra fields and rules. */
function cert_types(): array
{
    return [
        'bonafide' => [
            'label' => 'Bonafide Certificate', 'short' => 'Bonafide', 'code' => 'BON', 'icon' => 'file-badge', 'tone' => 'blue',
            'description' => 'Confirms current enrolment — bank account, passport, scholarship, visa, concession.',
            'fields' => ['purpose'], 'required' => ['purpose'], 'once' => false, 'active_only' => true, 'validity_days' => 180,
        ],
        'transfer' => [
            'label' => 'Transfer Certificate', 'short' => 'Transfer (TC)', 'code' => 'TC', 'icon' => 'arrow-right-left', 'tone' => 'purple',
            'description' => 'Issued when a student leaves the institute; records leaving date, reason, conduct and dues.',
            'fields' => ['leaving_date', 'leaving_reason', 'last_exam', 'conduct', 'dues_cleared'], 'required' => ['leaving_date', 'leaving_reason', 'last_exam', 'conduct', 'dues_cleared'],
            'once' => true, 'active_only' => false, 'validity_days' => null,
        ],
        'character' => [
            'label' => 'Character Certificate', 'short' => 'Character', 'code' => 'CHR', 'icon' => 'shield-check', 'tone' => 'green',
            'description' => 'Attests the conduct and character of the student during the period of study.',
            'fields' => ['conduct', 'purpose'], 'required' => ['conduct'], 'once' => false, 'active_only' => false, 'validity_days' => null,
        ],
        'course_completion' => [
            'label' => 'Course Completion Certificate', 'short' => 'Course Completion', 'code' => 'CC', 'icon' => 'graduation-cap', 'tone' => 'navy',
            'description' => 'Certifies successful completion of the program with CGPA and division.',
            'fields' => ['completion_date', 'division'], 'required' => ['completion_date'], 'once' => true, 'active_only' => false, 'validity_days' => null,
        ],
        'internship' => [
            'label' => 'Internship Certificate', 'short' => 'Internship', 'code' => 'INT', 'icon' => 'briefcase-business', 'tone' => 'cyan',
            'description' => 'Records an internship or industrial training completed as part of the curriculum.',
            'fields' => ['company', 'internship_role', 'internship_start', 'internship_end', 'project_title', 'mentor_name'],
            'required' => ['company', 'internship_role', 'internship_start', 'internship_end'], 'once' => false, 'active_only' => false, 'validity_days' => null,
        ],
        'migration' => [
            'label' => 'Migration Certificate', 'short' => 'Migration', 'code' => 'MIG', 'icon' => 'plane-takeoff', 'tone' => 'orange',
            'description' => 'No-objection for the student to migrate to another university or institution.',
            'fields' => ['leaving_date', 'migrating_to', 'purpose'], 'required' => ['leaving_date', 'migrating_to'], 'once' => true, 'active_only' => false, 'validity_days' => null,
        ],
        'provisional' => [
            'label' => 'Provisional Certificate', 'short' => 'Provisional', 'code' => 'PRV', 'icon' => 'scroll-text', 'tone' => 'amber',
            'description' => 'Provisional proof of passing until the degree is conferred by the university.',
            'fields' => ['completion_date', 'division'], 'required' => ['completion_date'], 'once' => true, 'active_only' => false, 'validity_days' => 365,
        ],
        'custom' => [
            'label' => 'Other Certificate', 'short' => 'Other', 'code' => 'GEN', 'icon' => 'award', 'tone' => 'pink',
            'description' => 'Any other certificate built from a custom template (participation, merit, NOC …).',
            'fields' => ['purpose'], 'required' => [], 'once' => false, 'active_only' => false, 'validity_days' => null,
        ],
    ];
}

function cert_type(string $type): ?array
{
    return cert_types()[$type] ?? null;
}

function cert_type_label(?string $type): string
{
    return cert_types()[(string) $type]['label'] ?? label_from_key((string) $type);
}

/** Type => label options (for selects / filters). */
function cert_type_options(): array
{
    return array_map(fn ($t) => $t['label'], cert_types());
}

function cert_conduct_options(): array
{
    return ['Excellent', 'Very Good', 'Good', 'Satisfactory'];
}

function cert_division_options(): array
{
    return ['First Division with Distinction', 'First Division', 'Second Division', 'Pass Class'];
}

/** Extra (type-specific) fields entered while issuing. */
function cert_field_defs(): array
{
    return [
        'purpose' => ['label' => 'Purpose', 'type' => 'text', 'maxlength' => 150, 'placeholder' => 'e.g. Opening a bank account',
            'suggestions' => ['Opening a bank account', 'Passport application', 'Education loan', 'Scholarship application', 'Visa application', 'Railway concession', 'Internship application', 'Hostel admission']],
        'conduct' => ['label' => 'Conduct & character', 'type' => 'select', 'options' => cert_conduct_options(), 'default' => 'Good'],
        'leaving_date' => ['label' => 'Date of leaving', 'type' => 'date'],
        'leaving_reason' => ['label' => 'Reason for leaving', 'type' => 'text', 'maxlength' => 150, 'placeholder' => 'e.g. Course completed',
            'suggestions' => ['Course completed', 'Higher studies', 'Migration to another institution', 'Personal reasons', 'Family relocation', 'Financial reasons', 'Medical reasons']],
        'last_exam' => ['label' => 'Last examination appeared', 'type' => 'text', 'maxlength' => 150, 'placeholder' => 'e.g. BBA Semester 4 — May 2026'],
        'dues_cleared' => ['label' => 'All dues cleared', 'type' => 'select', 'options' => ['Yes', 'No'], 'default' => 'Yes'],
        'completion_date' => ['label' => 'Date of completion / result', 'type' => 'date'],
        'division' => ['label' => 'Division', 'type' => 'select', 'options' => cert_division_options(), 'help' => 'Leave blank to derive it from the CGPA.'],
        'company' => ['label' => 'Organisation', 'type' => 'text', 'maxlength' => 150, 'placeholder' => 'e.g. Infosys Limited, Pune'],
        'internship_role' => ['label' => 'Role / designation', 'type' => 'text', 'maxlength' => 120, 'placeholder' => 'e.g. Marketing Intern'],
        'internship_start' => ['label' => 'Internship from', 'type' => 'date'],
        'internship_end' => ['label' => 'Internship to', 'type' => 'date'],
        'project_title' => ['label' => 'Project title', 'type' => 'text', 'maxlength' => 190, 'placeholder' => 'Optional'],
        'mentor_name' => ['label' => 'Industry mentor', 'type' => 'text', 'maxlength' => 120, 'placeholder' => 'Optional'],
        'migrating_to' => ['label' => 'Migrating to', 'type' => 'text', 'maxlength' => 190, 'default' => 'any other recognised University / Institution'],
    ];
}

/** Placeholder catalog: key => [label, group]. */
function cert_placeholders(): array
{
    $p = [
        'Student' => [
            'student_name' => 'Full name', 'first_name' => 'First name', 'salutation' => 'Mr. / Ms.', 'son_daughter' => 'son / daughter',
            'pronoun' => 'he / she', 'pronoun_cap' => 'He / She', 'pronoun_possessive' => 'his / her', 'pronoun_possessive_cap' => 'His / Her',
            'pronoun_object' => 'him / her', 'father_name' => "Father's name", 'mother_name' => "Mother's name", 'guardian_name' => "Guardian's name",
            'dob' => 'Date of birth', 'gender' => 'Gender', 'category' => 'Category', 'nationality' => 'Nationality',
            'student_uid' => 'Student ID', 'admission_no' => 'Admission no.', 'enrollment_no' => 'Enrolment no.', 'roll_no' => 'Roll no.',
        ],
        'Academic' => [
            'program' => 'Program (full)', 'program_short' => 'Program (short)', 'program_level' => 'Level (UG / PG …)', 'degree' => 'Degree',
            'specialization' => 'Specialisation', 'department' => 'Department', 'semester' => 'Semester (e.g. 3rd Semester)', 'semester_no' => 'Semester number',
            'year_of_study' => 'Year of study', 'section' => 'Section', 'batch' => 'Batch', 'session' => 'Current session', 'admission_session' => 'Admission session',
            'admission_date' => 'Date of admission', 'admission_year' => 'Year of admission', 'passing_year' => 'Year of passing', 'duration' => 'Program duration',
        ],
        'Result' => [
            'cgpa' => 'CGPA', 'sgpa' => 'Latest SGPA', 'percentage' => 'Percentage (CGPA × 9.5 if not recorded)', 'division' => 'Division', 'result_status' => 'Result status',
        ],
        'Certificate' => [
            'certificate_no' => 'Certificate number', 'issue_date' => 'Issue date', 'valid_until' => 'Valid until', 'purpose' => 'Purpose', 'verify_url' => 'Verification URL',
            'current_date' => "Today's date",
        ],
        'Type details' => [
            'conduct' => 'Conduct', 'leaving_date' => 'Date of leaving', 'leaving_reason' => 'Reason for leaving', 'last_exam' => 'Last examination',
            'dues_cleared' => 'Dues cleared', 'completion_date' => 'Completion date', 'completion_month_year' => 'Completion month & year',
            'company' => 'Organisation', 'internship_role' => 'Internship role', 'internship_start' => 'Internship from', 'internship_end' => 'Internship to',
            'internship_duration' => 'Internship duration', 'project_title' => 'Project title', 'mentor_name' => 'Industry mentor', 'migrating_to' => 'Migrating to',
        ],
        'Institute' => [
            'institute_name' => 'Institute name', 'institute_short_name' => 'Short name', 'institute_address' => 'Address', 'institute_city' => 'City',
            'affiliation' => 'Affiliation line',
        ],
    ];
    $out = [];
    foreach ($p as $group => $items) {
        foreach ($items as $key => $label) {
            $out[$key] = ['label' => $label, 'group' => $group];
        }
    }
    return $out;
}

function cert_border_styles(): array
{
    return ['classic' => 'Classic (navy & gold)', 'double' => 'Double rule', 'ornate' => 'Ornate guilloche', 'modern' => 'Modern band', 'minimal' => 'Minimal'];
}

function cert_font_styles(): array
{
    return ['serif' => 'Serif (formal)', 'sans' => 'Sans (Inter)', 'display' => 'Display (Plus Jakarta Sans)'];
}

/** Workflow settings. */
function cert_settings(): array
{
    return [
        'approval_required' => setting('certificate_approval_required', '0') === '1',
        'email_student' => setting('certificate_email_student', '1') === '1',
        'verify_rate_limit' => (int) setting('certificate_verify_rate_limit', '30'),
    ];
}

/* ------------------------------------------------------------------
 * Small helpers
 * ------------------------------------------------------------------ */

function cert_ordinal(int $n): string
{
    $s = ['th', 'st', 'nd', 'rd'];
    $v = $n % 100;
    return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
}

/** Formal certificate date: 7th October 2026 */
function cert_date(?string $d): string
{
    if (!$d || str_starts_with($d, '0000')) {
        return '';
    }
    $ts = strtotime($d);
    return $ts ? date('jS F Y', $ts) : '';
}

function cert_valid_date($v): bool
{
    if (!is_string($v) || $v === '') {
        return false;
    }
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v;
}

function cert_hash(string $no, int $studentId, string $type, string $issueDate): string
{
    $key = (string) (app_config('app')['key'] ?? 'gimt');
    return hash_hmac('sha256', implode('|', [$no, $studentId, $type, $issueDate]), $key);
}

/**
 * 12 hex chars of the hash embedded in the QR link as a signature. Taken from a part of the HMAC that is never shown
 * (the public security code uses the first 16 chars), so a forged link cannot be signed from a looked-up result.
 */
function cert_signature(string $hash): string
{
    return substr($hash, 40, 12);
}

/** Human security code shown on the sheet: 3F9A-12C4-77B0-E1D2 */
function cert_security_code(string $hash): string
{
    return strtoupper(implode('-', str_split(substr($hash, 0, 16), 4)));
}

function cert_verify_url(string $no, ?string $hash = null): string
{
    return absolute_url('verify-certificate/' . rawurlencode($no)) . ($hash ? '?h=' . cert_signature($hash) : '');
}

function cert_next_number(string $type, ?string $issueDate = null): string
{
    $code = cert_types()[$type]['code'] ?? 'GEN';
    $year = date('Y', strtotime($issueDate ?: date('Y-m-d')));
    $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) setting('certificate_prefix', setting('institute_short_name', 'GIMT')))) ?: 'GIMT';
    return next_number('certificate:' . $code . ':' . $year, $prefix . '-' . $code . '-' . $year . '-{n:5}');
}

/** Normalise a certificate number typed by a visitor. */
function cert_normalise_no(?string $no): string
{
    $no = strtoupper(trim((string) $no));
    $no = preg_replace('/\s+/', '', $no);
    return mb_substr(preg_replace('/[^A-Z0-9\-\/]/', '', $no), 0, 60);
}

function cert_mask_ip(string $ip): string
{
    if (str_contains($ip, ':')) {
        $parts = explode(':', $ip);
        return implode(':', array_slice($parts, 0, 3)) . '::';
    }
    $parts = explode('.', $ip);
    if (count($parts) === 4) {
        $parts[3] = 'x';
    }
    return implode('.', $parts);
}

function cert_safe_color(?string $c, string $default = '#0B2A5B'): string
{
    return is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtoupper($c) : $default;
}

/* ------------------------------------------------------------------
 * Data for placeholders
 * ------------------------------------------------------------------ */

function cert_student(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    return db_row(
        "SELECT s.*, p.name AS program_name, p.short_name AS program_short, p.level AS program_level, p.degree AS program_degree, p.duration_years,
                p.duration_label, p.total_semesters, c.name AS course_name, d.name AS department_name, sc.name AS section_name,
                b.name AS batch_name, b.start_year AS batch_start, b.end_year AS batch_end, ss.name AS admission_session_name
         FROM students s
         JOIN programs p ON p.id = s.program_id
         LEFT JOIN courses c ON c.id = s.course_id
         LEFT JOIN departments d ON d.id = s.department_id
         LEFT JOIN sections sc ON sc.id = s.section_id
         LEFT JOIN batches b ON b.id = s.batch_id
         LEFT JOIN academic_sessions ss ON ss.id = s.academic_session_id
         WHERE s.id = ?",
        [$id]
    );
}

/**
 * Academic session that contains a date (id + name). A certificate states the session of its issue date — not the
 * session currently selected in the admin header, which may be a past or future one.
 */
function cert_session_for(?string $date): ?array
{
    static $cache = [];
    $date = cert_valid_date((string) $date) ? (string) $date : date('Y-m-d');
    if (!array_key_exists($date, $cache)) {
        $row = null;
        try {
            $row = db_row('SELECT id, name FROM academic_sessions WHERE ? BETWEEN start_date AND end_date ORDER BY start_date DESC LIMIT 1', [$date]);
        } catch (Throwable $e) {
            $row = null;
        }
        if (!$row) {
            $cur = current_session();
            $row = !empty($cur['id']) ? ['id' => $cur['id'], 'name' => $cur['name'] ?? ''] : null;
        }
        $cache[$date] = $row ? ['id' => (int) $row['id'], 'name' => (string) $row['name']] : null;
    }
    return $cache[$date];
}

function cert_student_name(array $s): string
{
    return trim(implode(' ', array_filter([$s['first_name'] ?? '', $s['middle_name'] ?? '', $s['last_name'] ?? ''], fn ($p) => $p !== null && $p !== '')));
}

function cert_division_for(?float $cgpa): string
{
    if ($cgpa === null) {
        return '';
    }
    return $cgpa >= 7.5 ? 'First Division with Distinction' : ($cgpa >= 6.0 ? 'First Division' : ($cgpa >= 5.0 ? 'Second Division' : 'Pass Class'));
}

/** Latest published result (falls back to the semester history). */
function cert_result_vars(int $studentId): array
{
    $r = null;
    try {
        $r = db_row('SELECT sgpa, cgpa, percentage, division, result_status FROM results WHERE student_id = ? AND is_published = 1 AND (cgpa IS NOT NULL OR sgpa IS NOT NULL)
                     ORDER BY semester_no DESC, id DESC LIMIT 1', [$studentId]);
        if (!$r) {
            $r = db_row('SELECT sgpa, cgpa, NULL AS percentage, NULL AS division, NULL AS result_status FROM student_academic
                         WHERE student_id = ? AND (cgpa IS NOT NULL OR sgpa IS NOT NULL) ORDER BY semester_no DESC, id DESC LIMIT 1', [$studentId]);
        }
    } catch (Throwable $e) {
        $r = null;
    }
    $cgpa = $r && $r['cgpa'] !== null ? (float) $r['cgpa'] : null;
    $sgpa = $r && $r['sgpa'] !== null ? (float) $r['sgpa'] : null;
    $pct = $r && $r['percentage'] !== null ? (float) $r['percentage'] : ($cgpa !== null ? min(100, round($cgpa * 9.5, 2)) : null);
    return [
        'cgpa' => $cgpa !== null ? number_format($cgpa, 2) : '',
        'sgpa' => $sgpa !== null ? number_format($sgpa, 2) : '',
        'percentage' => $pct !== null ? rtrim(rtrim(number_format($pct, 2), '0'), '.') . '%' : '',
        'division' => $r && !empty($r['division']) ? (string) $r['division'] : cert_division_for($cgpa),
        'result_status' => $r && !empty($r['result_status']) ? ucfirst(strtolower((string) $r['result_status'])) : '',
    ];
}

function cert_duration_label(array $s): string
{
    if (!empty($s['duration_label'])) {
        return (string) $s['duration_label'];
    }
    $y = (float) ($s['duration_years'] ?? 0);
    if ($y <= 0) {
        return '';
    }
    if ($y < 1) {
        $m = (int) round($y * 12);
        return $m . ' month' . ($m === 1 ? '' : 's');
    }
    $txt = rtrim(rtrim(number_format($y, 1), '0'), '.');
    return $txt . ' year' . ($y > 1 ? 's' : '');
}

function cert_span_label(string $from, string $to): string
{
    $a = strtotime($from);
    $b = strtotime($to);
    if (!$a || !$b || $b < $a) {
        return '';
    }
    $days = (int) round(($b - $a) / 86400) + 1;
    if ($days >= 56) {
        $m = max(1, (int) round($days / 30.44));
        return $m . ' month' . ($m === 1 ? '' : 's');
    }
    $w = max(1, (int) round($days / 7));
    return $days < 7 ? $days . ' day' . ($days === 1 ? '' : 's') : $w . ' week' . ($w === 1 ? '' : 's');
}

/** Placeholder values for a student + extra fields + certificate meta (certificate_no, issue_date, valid_until, verify_url). */
function cert_build_vars(array $s, array $fields, array $meta = []): array
{
    $gender = strtolower((string) ($s['gender'] ?? ''));
    $female = $gender === 'female';
    $male = $gender === 'male';
    $sem = (int) ($s['current_semester'] ?? 0);
    $admYear = !empty($s['admission_date']) ? (int) date('Y', strtotime($s['admission_date'])) : (!empty($s['batch_start']) ? (int) $s['batch_start'] : null);
    $passYear = !empty($s['batch_end']) ? (int) $s['batch_end'] : ($admYear ? $admYear + (int) ceil((float) ($s['duration_years'] ?? 0)) : null);
    $program = trim((string) ($s['program_name'] ?? ''));
    $short = trim((string) ($s['program_short'] ?? ''));
    $vars = [
        'student_name' => cert_student_name($s),
        'first_name' => (string) ($s['first_name'] ?? ''),
        'salutation' => $female ? 'Ms.' : ($male ? 'Mr.' : 'Mx.'),
        'son_daughter' => $female ? 'daughter' : ($male ? 'son' : 'ward'),
        'pronoun' => $female ? 'she' : ($male ? 'he' : 'they'),
        'pronoun_cap' => $female ? 'She' : ($male ? 'He' : 'They'),
        'pronoun_possessive' => $female ? 'her' : ($male ? 'his' : 'their'),
        'pronoun_possessive_cap' => $female ? 'Her' : ($male ? 'His' : 'Their'),
        'pronoun_object' => $female ? 'her' : ($male ? 'him' : 'them'),
        'father_name' => (string) ($s['father_name'] ?? ''),
        'mother_name' => (string) ($s['mother_name'] ?? ''),
        'guardian_name' => (string) ($s['guardian_name'] ?? ($s['father_name'] ?? '')),
        'dob' => cert_date($s['dob'] ?? null),
        'gender' => $gender !== '' ? ucfirst($gender) : '',
        'category' => (string) ($s['category'] ?? ''),
        'nationality' => (string) ($s['nationality'] ?? 'Indian'),
        'student_uid' => (string) ($s['student_uid'] ?? ''),
        'admission_no' => (string) ($s['admission_no'] ?? ''),
        'enrollment_no' => (string) ($s['enrollment_no'] ?? ''),
        'roll_no' => (string) ($s['roll_no'] ?? ''),
        // "Bachelor of Business Administration (BBA)" — the short name is appended only when it is a plain acronym
        'program' => $program !== '' && preg_match('/^[A-Z]{2,6}$/', $short) && stripos($program, $short) === false ? "$program ($short)" : $program,
        'program_short' => $short,
        'program_level' => (string) ($s['program_level'] ?? ''),
        'degree' => (string) ($s['program_degree'] ?? ''),
        'specialization' => (string) ($s['course_name'] ?? ''),
        'department' => (string) ($s['department_name'] ?? ''),
        'semester' => $sem ? cert_ordinal($sem) . ' Semester' : '',
        'semester_no' => $sem ? (string) $sem : '',
        'year_of_study' => $sem ? cert_ordinal((int) ceil($sem / 2)) . ' Year' : '',
        'section' => (string) ($s['section_name'] ?? ''),
        'batch' => (string) ($s['batch_name'] ?? ''),
        'session' => (string) (cert_session_for($meta['issue_date'] ?? null)['name'] ?? ''),
        'admission_session' => (string) ($s['admission_session_name'] ?? ''),
        'admission_date' => cert_date($s['admission_date'] ?? null),
        'admission_year' => $admYear ? (string) $admYear : '',
        'passing_year' => $passYear ? (string) $passYear : '',
        'duration' => cert_duration_label($s),
        'institute_name' => institute_name(),
        'institute_short_name' => (string) setting('institute_short_name', 'GIMT'),
        'institute_address' => (string) setting('address', ''),
        'institute_city' => (string) setting('city', ''),
        'affiliation' => (string) setting('affiliation', ''),
        'current_date' => cert_date(date('Y-m-d')),
    ];
    $vars += !empty($s['id']) ? cert_result_vars((int) $s['id']) : ['cgpa' => '', 'sgpa' => '', 'percentage' => '', 'division' => '', 'result_status' => ''];
    foreach ($s['__result'] ?? [] as $k => $v) {
        $vars[$k] = (string) $v; // sample data
    }

    // Extra fields (dates formatted)
    $defs = cert_field_defs();
    foreach ($defs as $key => $def) {
        $v = trim((string) ($fields[$key] ?? ''));
        if ($key === 'division') {
            if ($v !== '') {
                $vars['division'] = $v;
            }
            continue;
        }
        $vars[$key] = $def['type'] === 'date' ? cert_date($v) : $v;
    }
    $vars['completion_month_year'] = !empty($fields['completion_date']) && cert_valid_date((string) $fields['completion_date']) ? date('F Y', strtotime($fields['completion_date'])) : '';
    $vars['internship_duration'] = !empty($fields['internship_start']) && !empty($fields['internship_end']) ? cert_span_label((string) $fields['internship_start'], (string) $fields['internship_end']) : '';

    $vars['certificate_no'] = (string) ($meta['certificate_no'] ?? '');
    $vars['issue_date'] = cert_date($meta['issue_date'] ?? date('Y-m-d'));
    $vars['valid_until'] = cert_date($meta['valid_until'] ?? null);
    $vars['verify_url'] = (string) ($meta['verify_url'] ?? ($vars['certificate_no'] !== '' ? cert_verify_url($vars['certificate_no']) : ''));
    return $vars;
}

/** Built-in sample student for template previews (works with an empty database). */
function cert_sample_student(): array
{
    return [
        'id' => 0, 'first_name' => 'Ananya', 'middle_name' => null, 'last_name' => 'Sharma', 'gender' => 'female', 'dob' => '2005-03-14',
        'father_name' => 'Rajesh Sharma', 'mother_name' => 'Sunita Sharma', 'guardian_name' => null, 'category' => 'General', 'nationality' => 'Indian',
        'student_uid' => 'GIMT24BBA0042', 'admission_no' => 'ADM/2024/0042', 'enrollment_no' => 'SU2024BBA10042', 'roll_no' => 'BBA24042',
        'program_name' => 'Bachelor of Business Administration', 'program_short' => 'BBA', 'program_level' => 'UG', 'program_degree' => "Bachelor's Degree",
        'duration_years' => 3, 'duration_label' => null, 'course_name' => 'Marketing', 'department_name' => 'Department of Management Studies',
        'current_semester' => 5, 'section_name' => 'A', 'batch_name' => 'BBA 2024-2027', 'batch_start' => 2024, 'batch_end' => 2027,
        'admission_session_name' => '2024-25', 'admission_date' => '2024-07-22', 'photo' => null, 'status' => 'active',
        '__result' => ['cgpa' => '8.46', 'sgpa' => '8.72', 'percentage' => '80.37%', 'division' => 'First Division with Distinction', 'result_status' => 'Pass'],
    ];
}

/** Sample values for extra fields (template previews). */
function cert_sample_fields(): array
{
    return [
        'purpose' => 'Opening a bank account', 'conduct' => 'Very Good', 'leaving_date' => date('Y-m-d', strtotime('-5 days')), 'leaving_reason' => 'Course completed',
        'last_exam' => 'BBA Semester 6 — May 2026', 'dues_cleared' => 'Yes', 'completion_date' => date('Y-m-d', strtotime('-30 days')), 'division' => '',
        'company' => 'Infosys Limited, Pune', 'internship_role' => 'Business Analyst Intern', 'internship_start' => date('Y-m-d', strtotime('-90 days')),
        'internship_end' => date('Y-m-d', strtotime('-34 days')), 'project_title' => 'Customer Churn Analytics Dashboard', 'mentor_name' => 'Mr. Vivek Nair',
        'migrating_to' => 'any other recognised University / Institution',
    ];
}

/* ------------------------------------------------------------------
 * Rendering
 * ------------------------------------------------------------------ */

/** Placeholder keys used in a piece of HTML. */
function cert_used_placeholders(string $html): array
{
    preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $html, $m);
    return array_values(array_unique($m[1] ?? []));
}

/** Every key referenced by a template, including optional-block markers ({{#key}}, {{^key}}, {{/key}}). */
function cert_template_keys(string $html): array
{
    preg_match_all('/\{\{\s*[#^\/]?\s*([a-zA-Z0-9_]+)\s*\}\}/', $html, $m);
    return array_values(array_unique($m[1] ?? []));
}

/** Resolve optional blocks: {{#key}}…{{/key}} kept when key has a value, {{^key}}…{{/key}} when it is empty. */
function cert_apply_conditionals(string $html, array $vars): string
{
    for ($i = 0; $i < 4; $i++) {
        $next = preg_replace_callback('/\{\{\s*([#^])\s*([a-zA-Z0-9_]+)\s*\}\}(.*?)\{\{\s*\/\s*\2\s*\}\}/s', function ($m) use ($vars) {
            $has = trim((string) ($vars[$m[2]] ?? '')) !== '';
            return ($m[1] === '#' ? $has : !$has) ? $m[3] : '';
        }, $html);
        if ($next === null || $next === $html) {
            break;
        }
        $html = $next;
    }
    // Paragraphs emptied by removed blocks
    return (string) preg_replace('#<p>(\s|&nbsp;|<br\s*/?>)*</p>#i', '', $html);
}

/**
 * Structural problems of a template (for the template form): unknown placeholders, unbalanced optional blocks.
 * @return string[] human messages
 */
function cert_template_problems(string $html): array
{
    $problems = [];
    $catalog = cert_placeholders();
    $unknown = array_values(array_filter(cert_template_keys($html), fn ($k) => !isset($catalog[$k])));
    if ($unknown) {
        $problems[] = 'Unknown placeholder' . (count($unknown) > 1 ? 's' : '') . ': ' . implode(', ', array_map(fn ($k) => '{{' . $k . '}}', $unknown)) . '. Insert placeholders from the picker.';
    }
    preg_match_all('/\{\{\s*([#^])\s*([a-zA-Z0-9_]+)\s*\}\}/', $html, $open);
    preg_match_all('/\{\{\s*\/\s*([a-zA-Z0-9_]+)\s*\}\}/', $html, $close);
    $o = array_count_values($open[2] ?? []);
    $c = array_count_values($close[1] ?? []);
    foreach (array_unique(array_merge(array_keys($o), array_keys($c))) as $k) {
        if (($o[$k] ?? 0) !== ($c[$k] ?? 0)) {
            $problems[] = 'Optional block {{#' . $k . '}} … {{/' . $k . '}} is not closed correctly.';
        }
    }
    return $problems;
}

/** Fill {{placeholders}} (values escaped). Missing values become a highlighted marker (preview) or an empty string. */
function cert_fill(string $html, array $vars, bool $markMissing = false): string
{
    $catalog = cert_placeholders();
    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($vars, $markMissing, $catalog) {
        $k = $m[1];
        $v = $vars[$k] ?? null;
        if ($v === null || $v === '') {
            if (!$markMissing) {
                return '';
            }
            $label = isset($catalog[$k]) ? $catalog[$k]['label'] : 'unknown: ' . $k;
            return '<mark class="cert-missing" title="' . e($label) . '">{{' . e($k) . '}}</mark>';
        }
        return e((string) $v);
    }, $html);
}

/**
 * Check placeholders of a template against values.
 * @return array{unknown: string[], missing: string[]}
 */
function cert_placeholder_issues(array $design, string $body, array $vars): array
{
    $catalog = cert_placeholders();
    $used = cert_used_placeholders($body . ' ' . ($design['title'] ?? '') . ' ' . ($design['subtitle'] ?? ''));
    $unknown = [];
    $missing = [];
    foreach ($used as $k) {
        if (!isset($catalog[$k])) {
            $unknown[] = $k;
        } elseif (($vars[$k] ?? '') === '') {
            // certificate_no / verify_url are assigned on issue
            if (!in_array($k, ['certificate_no', 'verify_url'], true)) {
                $missing[] = $k;
            }
        }
    }
    return ['unknown' => $unknown, 'missing' => $missing];
}

/** Design snapshot from a template row. */
function cert_design(array $t): array
{
    $orientation = ($t['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait';
    return [
        'title' => (string) ($t['title'] ?? 'Certificate'),
        'subtitle' => (string) ($t['subtitle'] ?? ''),
        'orientation' => $orientation,
        'border_style' => array_key_exists($t['border_style'] ?? '', cert_border_styles()) ? $t['border_style'] : 'classic',
        'accent_color' => cert_safe_color($t['accent_color'] ?? null),
        'font_style' => array_key_exists($t['font_style'] ?? '', cert_font_styles()) ? $t['font_style'] : 'serif',
        'signatory_name' => (string) ($t['signatory_name'] ?? ''),
        'signatory_designation' => (string) ($t['signatory_designation'] ?? ''),
        'signatory2_name' => (string) ($t['signatory2_name'] ?? ''),
        'signatory2_designation' => (string) ($t['signatory2_designation'] ?? ''),
        'show_photo' => !empty($t['show_photo']),
        'show_seal' => !array_key_exists('show_seal', $t) || !empty($t['show_seal']),
        'show_qr' => !array_key_exists('show_qr', $t) || !empty($t['show_qr']),
    ];
}

/** Default design used when a certificate has no snapshot and its template was deleted. */
function cert_default_design(string $type): array
{
    return cert_design(['title' => cert_type_label($type), 'border_style' => 'classic', 'signatory_designation' => 'Registrar']);
}

/** Stylesheet for certificate sheets (self-contained so print, preview and embed look identical). */
function cert_css(): string
{
    return <<<'CSS'
.cert-sheet{position:relative;width:210mm;height:297mm;box-sizing:border-box;padding:9mm;background:#fff;color:#1f2937;overflow:hidden;--accent:#0B2A5B;--gold:#B08D3C;--green:#22943F;-webkit-print-color-adjust:exact;print-color-adjust:exact;page-break-after:always;break-after:page}
.cert-sheet:last-child{page-break-after:auto;break-after:auto}
.cert-sheet.cert-landscape{width:297mm;height:210mm;padding:8mm}
.cert-sheet *{box-sizing:border-box}
.cert-paper{position:absolute;inset:0;background:#fff}
.cert-classic .cert-paper,.cert-ornate .cert-paper,.cert-double .cert-paper{background:radial-gradient(ellipse at 50% 38%,#ffffff 0,#fffdf8 55%,#fbf5e8 100%)}
.cert-modern .cert-paper{background-color:#fff;background-image:radial-gradient(rgba(11,42,91,.07) 1px,transparent 1px);background-size:7mm 7mm}
.cert-ring{position:relative;height:100%}
.cert-ornate .cert-ring{padding:4.2mm;background:repeating-linear-gradient(45deg,var(--accent) 0 1.1px,transparent 1.1px 4.6px),repeating-linear-gradient(-45deg,var(--gold) 0 1.1px,transparent 1.1px 4.6px);border:1.6px solid var(--accent)}
.cert-frame{position:relative;height:100%;display:flex;flex-direction:column;padding:9mm 13mm 6mm}
.cert-landscape .cert-frame{padding:7mm 16mm 5mm}
.cert-classic .cert-frame{border:3px solid var(--accent)}
.cert-classic .cert-frame:before{content:"";position:absolute;inset:2.4mm;border:1px solid var(--gold);pointer-events:none}
.cert-double .cert-frame{border:7px double var(--accent)}
.cert-double .cert-frame:before{content:"";position:absolute;inset:2.6mm;border:1px solid var(--accent);opacity:.3;pointer-events:none}
.cert-ornate .cert-frame{background:radial-gradient(ellipse at 50% 38%,#ffffff 0,#fffdf8 60%,#fbf5e8 100%);border:1.4px solid var(--gold)}
.cert-ornate .cert-frame:before{content:"";position:absolute;inset:2mm;border:.6px solid var(--accent);opacity:.55;pointer-events:none}
.cert-modern .cert-frame{border:1px solid #e2e8f0;padding-left:24mm;background:rgba(255,255,255,.86)}
.cert-modern .cert-band{position:absolute;left:0;top:0;bottom:0;width:12mm;background:linear-gradient(180deg,var(--accent) 0,var(--accent) 62%,var(--green) 100%)}
.cert-modern .cert-band:after{content:"";position:absolute;top:22mm;left:12mm;width:3mm;height:40mm;background:var(--green)}
.cert-modern .cert-arc{position:absolute;right:-28mm;top:-28mm;width:80mm;height:80mm;border-radius:50%;border:10mm solid var(--accent);opacity:.06}
.cert-minimal .cert-frame{border:1px solid #cbd5e1;border-top:4px solid var(--accent)}
.cert-corner{position:absolute;width:15mm;height:15mm;color:var(--gold);z-index:1}
.cert-corner.tl{top:1.2mm;left:1.2mm}.cert-corner.tr{top:1.2mm;right:1.2mm;transform:scaleX(-1)}
.cert-corner.bl{bottom:1.2mm;left:1.2mm;transform:scaleY(-1)}.cert-corner.br{bottom:1.2mm;right:1.2mm;transform:scale(-1,-1)}
.cert-watermark{position:absolute;left:50%;top:52%;width:58%;transform:translate(-50%,-50%);opacity:.045;pointer-events:none;z-index:0}
.cert-landscape .cert-watermark{width:40%}
.cert-content{position:relative;z-index:1;display:flex;flex-direction:column;height:100%}
.cert-head{text-align:center}
.cert-logo{height:17mm;width:auto;margin:0 auto}
.cert-landscape .cert-logo{height:13mm}
.cert-inst{margin:2.2mm 0 0;font-family:"Plus Jakarta Sans",Inter,sans-serif;font-weight:800;font-size:15.5pt;letter-spacing:.035em;text-transform:uppercase;color:var(--accent);line-height:1.15}
.cert-landscape .cert-inst{font-size:14pt;margin-top:1.5mm}
.cert-aff{margin:1mm 0 0;font-size:8.2pt;color:#475569;font-weight:500}
.cert-addr{margin:.6mm 0 0;font-size:7.6pt;color:#64748b}
.cert-divider{display:flex;align-items:center;justify-content:center;gap:3mm;margin:3.2mm auto 0;width:70%;color:var(--gold)}
.cert-divider span{flex:1;height:1px;background:linear-gradient(90deg,transparent,currentColor,transparent)}
.cert-divider i{width:2.2mm;height:2.2mm;transform:rotate(45deg);background:currentColor;display:block}
.cert-meta{display:flex;justify-content:space-between;gap:6mm;margin-top:5mm;font-size:9.2pt;color:#334155}
.cert-landscape .cert-meta{margin-top:3mm}
.cert-meta b{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-weight:700;color:#0f172a;letter-spacing:.02em}
.cert-photo{position:absolute;right:0;top:27mm;width:26mm;height:32mm;border:1px solid #cbd5e1;background:#f8fafc;display:flex;align-items:center;justify-content:center;font-size:7pt;color:#94a3b8;text-align:center;overflow:hidden}
.cert-photo img{width:100%;height:100%;object-fit:cover}
.cert-titleblock{text-align:center;margin-top:7mm}
.cert-landscape .cert-titleblock{margin-top:4mm}
.cert-title{margin:0;font-family:Georgia,"Times New Roman",Times,serif;font-weight:700;font-size:23pt;letter-spacing:.14em;text-transform:uppercase;color:var(--accent);line-height:1.2}
.cert-landscape .cert-title{font-size:25pt}
.cert-subtitle{display:inline-flex;align-items:center;gap:3mm;margin:2.5mm 0 0;font-family:Georgia,"Times New Roman",serif;font-style:italic;font-size:11pt;color:#475569}
.cert-subtitle:before,.cert-subtitle:after{content:"";width:12mm;height:1px;background:var(--gold)}
.cert-body{flex:1;margin-top:8mm;font-size:12.4pt;line-height:1.95;color:#1f2937;text-align:justify;hyphens:manual;font-variant-numeric:lining-nums}
.cert-landscape .cert-body{margin-top:5mm;font-size:12.6pt;line-height:1.85;text-align:center;padding:0 6mm}
.cert-font-serif .cert-body{font-family:Georgia,"Times New Roman",Times,serif}
.cert-font-sans .cert-body{font-family:Inter,ui-sans-serif,system-ui,sans-serif;font-size:11.4pt}
.cert-font-display .cert-body{font-family:"Plus Jakarta Sans",Inter,sans-serif;font-size:11.4pt}
.cert-body p{margin:0 0 3.6mm}
.cert-body strong,.cert-body b{color:#0f172a}
.cert-body h2,.cert-body h3{font-family:Georgia,"Times New Roman",serif;color:var(--accent);margin:2mm 0;line-height:1.25}
.cert-body h2{font-size:23pt;font-weight:700;letter-spacing:.02em;margin:0 0 3mm}.cert-body h3{font-size:15pt}
.cert-body ul,.cert-body ol{margin:0 0 3mm 6mm}
.cert-body table{width:100%;border-collapse:collapse;margin:1mm 0 3.2mm;font-size:.84em;line-height:1.38;text-align:left}
.cert-body th,.cert-body td{border:1px solid #cbd5e1;padding:1.1mm 2.6mm;vertical-align:top}
.cert-body th{width:42%;background:rgba(11,42,91,.04);font-weight:600;color:#334155}
.cert-missing{background:#fef3c7;color:#92400e;border:1px dashed #f59e0b;border-radius:3px;padding:0 1.2mm;font-family:ui-monospace,Consolas,monospace;font-size:.82em}
.cert-foot{display:flex;align-items:flex-end;justify-content:space-between;gap:5mm;margin-top:4mm;min-height:30mm}
.cert-qrbox{width:24mm;text-align:center}
.cert-qr{width:21mm;height:21mm;margin:0 auto;padding:1mm;background:#fff;border:1px solid #e2e8f0}
.cert-qr svg{width:100%;height:100%;display:block}
.cert-qrbox p{margin:1mm 0 0;font-size:6.4pt;color:#64748b;line-height:1.25}
.cert-seal{width:27mm;height:27mm;color:var(--accent);opacity:.82;transform:rotate(-11deg)}
.cert-sign{min-width:44mm;max-width:58mm;text-align:center}
.cert-sign-space{height:13mm}
.cert-sign-line{border-top:1px solid #334155;margin:0 2mm}
.cert-sign-name{margin:1.4mm 0 0;font-weight:700;font-size:9.6pt;color:#0f172a;line-height:1.25}
.cert-sign-title{margin:.4mm 0 0;font-size:8pt;color:#475569;line-height:1.25}
.cert-note{margin-top:3mm;padding-top:2mm;border-top:1px solid rgba(148,163,184,.45);text-align:center;font-size:6.9pt;color:#64748b;line-height:1.45}
.cert-note b{font-family:ui-monospace,Consolas,monospace;color:#334155;font-weight:600;white-space:nowrap}
.cert-stamp{position:absolute;left:50%;top:48%;z-index:5;transform:translate(-50%,-50%) rotate(-24deg);border:2.2mm solid currentColor;border-radius:4mm;padding:2mm 7mm;font-family:"Plus Jakarta Sans",Inter,sans-serif;font-weight:800;font-size:38pt;letter-spacing:.12em;white-space:nowrap;opacity:.16;pointer-events:none}
.cert-stamp-revoked{color:#dc2626;opacity:.24}.cert-stamp-pending{color:#d97706}.cert-stamp-specimen,.cert-stamp-preview{color:#64748b;opacity:.13}.cert-stamp-rejected{color:#475569;opacity:.2}
CSS;
}

/** Circular institute seal (SVG). */
function cert_seal_svg(string $uid): string
{
    $name = strtoupper(institute_name());
    $city = strtoupper((string) setting('city', 'Greater Noida'));
    $short = strtoupper((string) setting('institute_short_name', 'GIMT'));
    $est = (string) setting('established_year', '');
    $id = 'seal-' . preg_replace('/[^a-z0-9]/i', '', $uid);
    return '<svg class="cert-seal" viewBox="0 0 200 200" aria-hidden="true"><defs><path id="' . $id . '" d="M100,100 m-73,0 a73,73 0 1,1 146,0 a73,73 0 1,1 -146,0"/></defs>'
        . '<circle cx="100" cy="100" r="97" fill="none" stroke="currentColor" stroke-width="4"/>'
        . '<circle cx="100" cy="100" r="89" fill="none" stroke="currentColor" stroke-width="1.4"/>'
        . '<circle cx="100" cy="100" r="57" fill="none" stroke="currentColor" stroke-width="1.4"/>'
        . '<text font-family="Inter,Arial,sans-serif" font-size="12.5" font-weight="700" fill="currentColor"><textPath href="#' . $id . '" textLength="452" lengthAdjust="spacingAndGlyphs">'
        . e($name) . ' • ' . e($city) . ' •</textPath></text>'
        . '<text x="100" y="99" text-anchor="middle" font-family="Plus Jakarta Sans,Inter,Arial,sans-serif" font-size="27" font-weight="800" fill="currentColor">' . e($short) . '</text>'
        . '<text x="100" y="117" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="9" font-weight="700" letter-spacing="2" fill="currentColor">OFFICIAL SEAL</text>'
        . ($est !== '' ? '<text x="100" y="131" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="8" letter-spacing="1.5" fill="currentColor">EST. ' . e($est) . '</text>' : '')
        . '<path d="M100 56 l3 6.5 7 .6 -5.3 4.6 1.6 6.9 -6.3 -3.7 -6.3 3.7 1.6 -6.9 -5.3 -4.6 7 -.6z" fill="currentColor"/></svg>';
}

function cert_corner_svg(string $pos): string
{
    return '<svg class="cert-corner ' . $pos . '" viewBox="0 0 60 60" aria-hidden="true"><path d="M3 57V15Q3 3 15 3h42" fill="none" stroke="currentColor" stroke-width="2.4"/>'
        . '<path d="M9 57V19Q9 9 19 9h38" fill="none" stroke="currentColor" stroke-width=".9"/><circle cx="15" cy="15" r="3.4" fill="currentColor"/>'
        . '<path d="M15 24c4-5 10-5 13 0M24 15c-5 4-5 10 0 13" fill="none" stroke="currentColor" stroke-width="1.1"/></svg>';
}

/**
 * One A4 certificate sheet.
 * $o: design (cert_design), body (filled HTML), title, subtitle, certificate_no, issue_date (Y-m-d), qr_url, verify_display,
 *     security_code, photo (url|null), watermark (null|revoked|pending|specimen|preview|rejected), uid
 */
function cert_sheet(array $o): string
{
    $d = $o['design'];
    $uid = (string) ($o['uid'] ?? substr(md5(json_encode($o['certificate_no'] ?? '') . microtime()), 0, 8));
    $land = $d['orientation'] === 'landscape';
    $style = '--accent:' . cert_safe_color($d['accent_color']) . ';';
    $cls = 'cert-sheet cert-' . $d['border_style'] . ' cert-font-' . $d['font_style'] . ($land ? ' cert-landscape' : '');
    $logo = logo_url();
    $addr = (string) setting('address', '');
    $aff = (string) setting('affiliation', '');
    $issue = cert_date($o['issue_date'] ?? null) ?: '—';

    $h = '<section class="' . e($cls) . '" style="' . e($style) . '">';
    $h .= '<div class="cert-paper"></div><div class="cert-ring">';
    if ($d['border_style'] === 'modern') {
        $h .= '<div class="cert-band"></div>';
    }
    $h .= '<div class="cert-frame">';
    if ($d['border_style'] === 'modern') {
        $h .= '<div class="cert-arc"></div>';
    }
    if (in_array($d['border_style'], ['classic', 'ornate'], true)) {
        $h .= cert_corner_svg('tl') . cert_corner_svg('tr') . cert_corner_svg('bl') . cert_corner_svg('br');
    }
    $h .= '<img class="cert-watermark" src="' . e($logo) . '" alt="">';
    $h .= '<div class="cert-content">';
    // Letterhead
    $h .= '<header class="cert-head"><img class="cert-logo" src="' . e($logo) . '" alt="' . e(setting('institute_short_name', 'GIMT')) . '">'
        . '<p class="cert-inst">' . e(institute_name()) . '</p>'
        . ($aff !== '' ? '<p class="cert-aff">' . e($aff) . '</p>' : '')
        . ($addr !== '' && !$land ? '<p class="cert-addr">' . e($addr) . '</p>' : '')
        . '<div class="cert-divider"><span></span><i></i><span></span></div></header>';
    $h .= '<div class="cert-meta"><span>Certificate No.: <b>' . e($o['certificate_no'] ?? '—') . '</b></span><span>Date: <b>' . e($issue) . '</b></span></div>';
    if (!empty($d['show_photo'])) {
        $h .= '<div class="cert-photo">' . (!empty($o['photo']) ? '<img src="' . e($o['photo']) . '" alt="">' : 'Affix recent<br>photograph') . '</div>';
    }
    $h .= '<div class="cert-titleblock"><h1 class="cert-title">' . e($o['title'] ?? $d['title']) . '</h1>'
        . (($o['subtitle'] ?? '') !== '' ? '<p class="cert-subtitle">' . e($o['subtitle']) . '</p>' : '') . '</div>';
    $h .= '<div class="cert-body">' . ($o['body'] ?? '') . '</div>';
    // Footer: QR · second signatory · seal · signatory
    $h .= '<footer class="cert-foot">';
    $h .= !empty($d['show_qr']) && !empty($o['qr_url'])
        ? '<div class="cert-qrbox"><div class="cert-qr" data-qr="' . e($o['qr_url']) . '"></div><p>Scan to verify</p></div>'
        : '<div class="cert-qrbox"></div>';
    $sign = fn (string $name, string $title) => '<div class="cert-sign"><div class="cert-sign-space"></div><div class="cert-sign-line"></div>'
        . '<p class="cert-sign-name">' . e($name !== '' ? $name : ' ') . '</p><p class="cert-sign-title">' . e($title) . '</p></div>';
    if ($d['signatory2_name'] !== '' || $d['signatory2_designation'] !== '') {
        $h .= $sign($d['signatory2_name'], $d['signatory2_designation']);
    }
    $h .= !empty($d['show_seal']) ? cert_seal_svg($uid) : '';
    $h .= $sign($d['signatory_name'], $d['signatory_designation'] !== '' ? $d['signatory_designation'] : 'Authorised Signatory');
    $h .= '</footer>';
    $h .= '<p class="cert-note">This certificate is issued electronically by ' . e(institute_name()) . ' and can be verified at '
        . '<b>' . e($o['verify_display'] ?? '') . '</b>' . (!empty($o['security_code']) ? ' · Security code <b>' . e($o['security_code']) . '</b>' : '') . '</p>';
    $h .= '</div></div></div>';
    $wm = $o['watermark'] ?? null;
    if ($wm) {
        $labels = ['revoked' => 'REVOKED', 'pending' => 'PENDING APPROVAL', 'specimen' => 'SPECIMEN', 'preview' => 'PREVIEW', 'rejected' => 'REJECTED'];
        $h .= '<div class="cert-stamp cert-stamp-' . e($wm) . '">' . e($labels[$wm] ?? strtoupper($wm)) . '</div>';
    }
    return $h . '</section>';
}

/** Short verification address shown on the sheet: 127.0.0.1:8000/verify-certificate/GIMT-BON-2026-00012 */
function cert_verify_display(string $no): string
{
    return preg_replace('#^https?://#', '', absolute_url('verify-certificate/' . $no));
}

/** Sheet for a stored certificate row (uses its snapshot). */
function cert_sheet_for(array $c, array $opts = []): string
{
    $data = is_array($c['data'] ?? null) ? $c['data'] : (json_decode((string) ($c['data'] ?? ''), true) ?: []);
    $design = !empty($data['design']) && is_array($data['design']) ? cert_design($data['design'] + ['show_seal' => 1, 'show_qr' => 1]) : null;
    if (!$design) {
        $tpl = !empty($c['template_id']) ? db_row('SELECT * FROM certificate_templates WHERE id = ?', [(int) $c['template_id']]) : null;
        $design = $tpl ? cert_design($tpl) : cert_default_design((string) $c['type']);
    }
    $watermark = $opts['watermark'] ?? (in_array($c['status'], ['revoked', 'pending', 'rejected'], true) ? $c['status'] : null);
    $photo = null;
    if (!empty($design['show_photo'])) {
        $p = $data['student']['photo'] ?? db_value('SELECT photo FROM students WHERE id = ?', [(int) $c['student_id']]);
        $photo = $p ? upload_url((string) $p) : null;
    }
    return cert_sheet([
        'design' => $design,
        'title' => $data['title'] ?? $c['title'] ?? $design['title'],
        'subtitle' => $data['subtitle'] ?? $design['subtitle'],
        'body' => (string) ($c['content_html'] ?? ''),
        'certificate_no' => $c['certificate_no'],
        'issue_date' => $c['issue_date'],
        'qr_url' => cert_verify_url($c['certificate_no'], $c['verification_hash'] ?? null),
        'verify_display' => cert_verify_display($c['certificate_no']),
        'security_code' => !empty($c['verification_hash']) ? cert_security_code($c['verification_hash']) : '',
        'photo' => $photo,
        'watermark' => $watermark,
        'uid' => 'c' . ($c['id'] ?? '0'),
    ]);
}

/**
 * Full HTML document around one or more sheets.
 * $opts: title, embed (bool — iframe: no toolbar, transparent page), toolbar (HTML), auto_print (bool), orientation
 */
function cert_document(string $sheets, array $opts = []): string
{
    $embed = !empty($opts['embed']);
    $land = ($opts['orientation'] ?? 'portrait') === 'landscape';
    $title = (string) ($opts['title'] ?? 'Certificate');
    // Default page follows the first sheet; landscape sheets in a mixed batch use a named page.
    $page = '@page{size:A4 ' . ($land ? 'landscape' : 'portrait') . ';margin:0}@page certland{size:A4 landscape;margin:0}@page certport{size:A4 portrait;margin:0}'
        . '.cert-sheet{page:certport}.cert-sheet.cert-landscape{page:certland}';
    $screen = $embed
        ? 'html,body{margin:0;padding:0;background:transparent;overflow:hidden}.cert-sheet{margin:0}'
        : 'body{margin:0;background:#e9eef5}.cert-stack{display:flex;flex-direction:column;align-items:center;gap:8mm;padding:8mm 0 16mm}.cert-stack .cert-sheet{box-shadow:0 18px 50px -18px rgba(11,42,91,.45);flex:none}'
          . '@media print{body{background:#fff}.no-print{display:none!important}.cert-stack{display:block;padding:0;zoom:1!important}.cert-stack .cert-sheet{box-shadow:none;margin:0}}';
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>' . e($title) . ' - ' . e(institute_name()) . '</title>'
        . '<link rel="icon" href="' . e(asset('assets/images/favicon.svg')) . '">'
        . '<link rel="stylesheet" href="' . e(asset('assets/css/style.css')) . '">'
        . '<style>' . $page . $screen . cert_css() . '</style></head><body class="font-sans antialiased">';
    if (!$embed && !empty($opts['toolbar'])) {
        $html .= $opts['toolbar'];
    }
    $html .= $embed ? $sheets : '<main class="cert-stack">' . $sheets . '</main>';
    $html .= '<script src="' . e(asset('assets/vendor/qrcode.js')) . '"></script>'
        . '<script>document.querySelectorAll("[data-qr]").forEach(function(el){try{var q=qrcode(0,"M");q.addData(el.getAttribute("data-qr"));q.make();el.innerHTML=q.createSvgTag({cellSize:3,margin:0,scalable:true});}catch(e){}});'
        // Long certificates (e.g. a TC with its particulars table) are scaled down step by step until the signatures,
        // seal and QR fit on the A4 sheet — the footer is never pushed off the page.
        . 'function certFit(){document.querySelectorAll(".cert-sheet").forEach(function(sh){var c=sh.querySelector(".cert-content"),b=sh.querySelector(".cert-body");if(!c||!b)return;'
        . 'b.style.fontSize="";b.style.lineHeight="";var cs=getComputedStyle(b),fs=parseFloat(cs.fontSize),lh=parseFloat(cs.lineHeight)/fs;if(!lh||isNaN(lh))lh=1.9;'
        . 'for(var f=1;c.scrollHeight>c.clientHeight+1&&f>0.64;){f-=0.03;b.style.fontSize=(fs*f).toFixed(2)+"px";b.style.lineHeight=Math.max(1.4,lh-(1-f)*2.2).toFixed(2);}});}'
        . 'certFit();if(document.fonts&&document.fonts.ready){document.fonts.ready.then(certFit);}window.addEventListener("load",certFit);'
        // Phones: shrink the A4 stack to the viewport on screen (print is unaffected).
        . (!$embed ? '(function(){var s=document.querySelector(".cert-stack");if(!s)return;function fit(){var w=s.querySelector(".cert-landscape")?1123:794;var z=Math.min(1,(window.innerWidth-16)/w);s.style.zoom=z<1?String(z):""}fit();window.addEventListener("resize",fit)})();' : '')
        . (!empty($opts['auto_print']) ? 'window.addEventListener("load",function(){setTimeout(function(){window.print()},500)});' : '')
        . '</script></body></html>';
    return $html;
}

/* ------------------------------------------------------------------
 * Issue workflow
 * ------------------------------------------------------------------ */

/** Template to use for a type: explicit id (must be active and match) or the type's default. */
function cert_template_for(string $type, ?int $templateId): ?array
{
    if ($templateId) {
        return db_row("SELECT * FROM certificate_templates WHERE id = ? AND status = 'active'", [$templateId]);
    }
    return db_row("SELECT * FROM certificate_templates WHERE type = ? AND status = 'active' ORDER BY is_default DESC, id ASC LIMIT 1", [$type]);
}

/**
 * Why a student cannot receive a certificate type (null = eligible). Needs status, current_semester and
 * total_semesters on the student row. The sentence continues the student's name: "Riya Sharma is Dropped. …"
 */
function cert_ineligible_reason(array $student, string $type): ?string
{
    $def = cert_types()[$type] ?? null;
    if (!$def) {
        return null;
    }
    $status = (string) ($student['status'] ?? '');
    if (!empty($def['active_only']) && $status !== 'active') {
        return 'is ' . label_from_key($status) . '. A ' . $def['label'] . ' can only be issued to an active student.';
    }
    if (in_array($type, ['course_completion', 'provisional'], true)) {
        $sem = (int) ($student['current_semester'] ?? 0);
        $total = (int) ($student['total_semesters'] ?? 0);
        if ($status === 'active' && $total > 0 && $sem < $total) {
            return 'is in semester ' . $sem . ' of ' . $total . '. A ' . $def['label'] . ' can only be issued after the final semester (final-semester or graduated students).';
        }
        if (!in_array($status, ['active', 'graduated'], true)) {
            return 'is ' . label_from_key($status) . ' and has not completed the programme. A ' . $def['label'] . ' is only for graduated or final-semester students.';
        }
    }
    return null;
}

/** Active (pending or issued) certificate of a type for a student. */
function cert_active_of_type(int $studentId, string $type, ?int $ignoreId = null): ?array
{
    return db_row("SELECT id, certificate_no, status, issue_date FROM certificates WHERE student_id = ? AND type = ? AND status IN ('pending','issued')"
        . ($ignoreId ? ' AND id <> ' . (int) $ignoreId : '') . ' ORDER BY id DESC LIMIT 1', [$studentId, $type]);
}

/**
 * Validate an issue request.
 * Input: student_id, type, template_id, issue_date, valid_until, remarks, fields{}.
 * @return array{0: array errors, 1: array context}
 */
function cert_validate_issue(array $in, array $opts = []): array
{
    $errors = [];
    $types = cert_types();
    $type = (string) ($in['type'] ?? '');
    $studentId = (int) ($in['student_id'] ?? 0);
    $templateId = (int) ($in['template_id'] ?? 0) ?: null;
    $issueDate = trim((string) ($in['issue_date'] ?? '')) ?: date('Y-m-d');
    $validUntil = trim((string) ($in['valid_until'] ?? '')) ?: null;
    $remarks = trim((string) ($in['remarks'] ?? '')) ?: null;
    $rawFields = is_array($in['fields'] ?? null) ? $in['fields'] : [];

    $student = $studentId ? cert_student($studentId) : null;
    if (!$studentId) {
        $errors['student_id'] = 'Select the student.';
    } elseif (!$student) {
        $errors['student_id'] = 'Selected student was not found.';
    }
    $def = $types[$type] ?? null;
    if ($type === '') {
        $errors['type'] = 'Choose the certificate type.';
    } elseif (!$def) {
        $errors['type'] = 'Select a valid certificate type.';
    }
    $template = null;
    if ($def) {
        $template = cert_template_for($type, $templateId);
        if ($templateId && !$template) {
            $errors['template_id'] = 'The selected template is inactive or was deleted.';
        } elseif (!$template) {
            $errors['template_id'] = 'No active template exists for ' . $def['label'] . '. Create one under Certificate Templates.';
        } elseif ($template['type'] !== $type && $template['type'] !== 'custom') {
            $errors['template_id'] = 'This template is for ' . cert_type_label($template['type']) . ', not ' . $def['label'] . '.';
        }
    }
    if (!cert_valid_date($issueDate)) {
        $errors['issue_date'] = 'Issue date must be a valid date.';
    } elseif ($issueDate > date('Y-m-d', strtotime('+30 days'))) {
        $errors['issue_date'] = 'Issue date cannot be more than 30 days in the future.';
    } elseif ($issueDate < date('Y-m-d', strtotime('-2 years'))) {
        $errors['issue_date'] = 'Issue date cannot be more than 2 years in the past.';
    }
    if ($validUntil !== null) {
        if (!cert_valid_date($validUntil)) {
            $errors['valid_until'] = 'Valid until must be a valid date.';
        } elseif (cert_valid_date($issueDate) && $validUntil < $issueDate) {
            $errors['valid_until'] = 'Valid until must be on or after the issue date.';
        }
    }
    if ($remarks !== null && mb_strlen($remarks) > 255) {
        $errors['remarks'] = 'Remarks may not be longer than 255 characters.';
    }

    // Extra fields
    $fields = [];
    if ($def) {
        $defs = cert_field_defs();
        foreach ($def['fields'] as $key) {
            $fd = $defs[$key];
            $v = $rawFields[$key] ?? null;
            $v = is_scalar($v) ? trim((string) $v) : '';
            if ($v === '' && isset($fd['default']) && !array_key_exists($key, $rawFields)) {
                $v = (string) $fd['default'];
            }
            if ($v === '') {
                if (in_array($key, $def['required'], true)) {
                    $errors['fields.' . $key] = $fd['label'] . ' is required.';
                }
                $fields[$key] = '';
                continue;
            }
            if ($fd['type'] === 'date' && !cert_valid_date($v)) {
                $errors['fields.' . $key] = $fd['label'] . ' must be a valid date.';
            } elseif ($fd['type'] === 'select' && !in_array($v, $fd['options'], true)) {
                $errors['fields.' . $key] = 'Select a valid ' . strtolower($fd['label']) . '.';
            } elseif ($fd['type'] === 'text' && mb_strlen($v) > (int) ($fd['maxlength'] ?? 190)) {
                $errors['fields.' . $key] = $fd['label'] . ' may not be longer than ' . (int) ($fd['maxlength'] ?? 190) . ' characters.';
            }
            $fields[$key] = $v;
        }
        if (!empty($fields['internship_start']) && !empty($fields['internship_end']) && empty($errors['fields.internship_start']) && empty($errors['fields.internship_end'])
            && $fields['internship_end'] < $fields['internship_start']) {
            $errors['fields.internship_end'] = 'Internship end date must be on or after the start date.';
        }
        foreach (['leaving_date', 'completion_date', 'internship_end'] as $k) {
            if (!empty($fields[$k]) && empty($errors['fields.' . $k]) && cert_valid_date($fields[$k]) && $fields[$k] > date('Y-m-d', strtotime('+60 days'))) {
                $errors['fields.' . $k] = cert_field_defs()[$k]['label'] . ' cannot be that far in the future.';
            }
        }
    }

    // Business rules
    $warnings = [];
    if ($student && $def) {
        $name = cert_student_name($student);
        if ($why = cert_ineligible_reason($student, $type)) {
            $errors['student_id'] = $name . ' ' . $why;
        }
        $existing = cert_active_of_type((int) $student['id'], $type, $opts['ignore_id'] ?? null);
        if ($existing && !empty($def['once'])) {
            $errors['type'] = $name . ' already has ' . ($existing['status'] === 'pending' ? 'a pending request for this ' : 'an active ') . $def['label']
                . ' (' . $existing['certificate_no'] . '). Reissue that certificate instead.';
        } elseif ($existing) {
            $warnings[] = $name . ' already has ' . ($existing['status'] === 'pending' ? 'a pending ' : 'an active ') . $def['label'] . ' (' . $existing['certificate_no'] . ', ' . format_date($existing['issue_date']) . ').';
        }
        if (in_array($type, ['course_completion', 'provisional'], true) && $student['status'] === 'active' && empty($errors['student_id'])) {
            $warnings[] = $name . ' is in the final semester but still marked active — confirm the final results have been declared.';
        }
        if (in_array($type, ['transfer', 'migration'], true) && $student['status'] === 'active') {
            $warnings[] = $name . ' is still marked active. Update the student status after issuing the ' . $def['short'] . ' certificate.';
        }
    }
    if ($validUntil === null && $template && empty($errors['issue_date'])) {
        $days = $template['validity_days'] !== null ? (int) $template['validity_days'] : ($def['validity_days'] ?? null);
        if ($days) {
            $validUntil = date('Y-m-d', strtotime($issueDate . ' +' . $days . ' days'));
        }
    }
    return [$errors, [
        'student' => $student, 'type' => $type, 'def' => $def, 'template' => $template, 'issue_date' => $issueDate, 'valid_until' => $validUntil,
        'remarks' => $remarks, 'fields' => $fields, 'warnings' => $warnings,
    ]];
}

/**
 * Render content for a validated context.
 * @return array{design: array, title: string, subtitle: string, body: string, body_marked: string, vars: array, unknown: string[], missing: string[]}
 */
function cert_compose(array $ctx, array $meta = []): array
{
    $design = cert_design($ctx['template']);
    $vars = cert_build_vars($ctx['student'], $ctx['fields'], $meta + ['issue_date' => $ctx['issue_date'], 'valid_until' => $ctx['valid_until']]);
    $body = cert_apply_conditionals(sanitize_html((string) $ctx['template']['body_html']), $vars);
    $design['title'] = cert_apply_conditionals($design['title'], $vars);
    $design['subtitle'] = cert_apply_conditionals($design['subtitle'], $vars);
    $issues = cert_placeholder_issues($design, $body, $vars);
    $plain = fn (string $text) => trim(html_entity_decode(cert_fill($text, $vars), ENT_QUOTES, 'UTF-8'));
    $title = $plain($design['title']);
    return [
        'design' => $design,
        'title' => $title !== '' ? $title : cert_type_label($ctx['type'] ?? ''),
        'subtitle' => $plain($design['subtitle']),
        'body' => cert_fill($body, $vars),
        'body_marked' => cert_fill($body, $vars, true),
        'vars' => $vars,
        'unknown' => $issues['unknown'],
        'missing' => $issues['missing'],
    ];
}

/** Human message for placeholder problems (blocks issuing). */
function cert_placeholder_message(array $composed): ?string
{
    $parts = [];
    $catalog = cert_placeholders();
    if ($composed['missing']) {
        $parts[] = 'No value for ' . implode(', ', array_map(fn ($k) => ($catalog[$k]['label'] ?? $k) . ' ({{' . $k . '}})', $composed['missing']));
    }
    if ($composed['unknown']) {
        $parts[] = 'unknown placeholder' . (count($composed['unknown']) > 1 ? 's' : '') . ' ' . implode(', ', array_map(fn ($k) => '{{' . $k . '}}', $composed['unknown']));
    }
    return $parts ? ucfirst(implode('; ', $parts)) . '. Fill in the missing details or update the template.' : null;
}

/** Map a missing placeholder to the extra field that supplies it (for inline errors). */
function cert_missing_field_errors(array $composed, array $def): array
{
    $errors = [];
    $defs = cert_field_defs();
    foreach ($composed['missing'] as $k) {
        $field = $k === 'internship_duration' ? 'internship_end' : ($k === 'completion_month_year' ? 'completion_date' : $k);
        if (in_array($field, $def['fields'] ?? [], true)) {
            $errors['fields.' . $field] = ($defs[$field]['label'] ?? $field) . ' is used by the template and is required.';
        }
    }
    return $errors;
}

/** Sheet HTML for a validated context (preview / specimen). */
function cert_preview_sheet(array $ctx, array $composed, string $watermark = 'preview', ?string $number = null): string
{
    $no = $number ?? (strtoupper((string) setting('institute_short_name', 'GIMT')) . '-' . ($ctx['def']['code'] ?? 'GEN') . '-' . date('Y', strtotime($ctx['issue_date'])) . '-•••••');
    $photo = !empty($composed['design']['show_photo']) && !empty($ctx['student']['photo']) ? upload_url($ctx['student']['photo']) : null;
    return cert_sheet([
        'design' => $composed['design'], 'title' => $composed['title'], 'subtitle' => $composed['subtitle'], 'body' => $composed['body_marked'],
        'certificate_no' => $no, 'issue_date' => $ctx['issue_date'], 'qr_url' => absolute_url('verify-certificate'),
        'verify_display' => cert_verify_display($no), 'security_code' => '', 'photo' => $photo, 'watermark' => $watermark, 'uid' => 'pv' . substr(md5($no), 0, 6),
    ]);
}

/**
 * Issue (or request) a certificate. Throws CrudValidationException / CrudException.
 * $opts: user_id, can_approve (bool), notify (bool), email_student (bool), reissued_from (row), log (bool)
 * @return array the stored certificate row
 */
function cert_issue(array $in, array $opts = []): array
{
    [$errors, $ctx] = cert_validate_issue($in, ['ignore_id' => $opts['reissued_from']['id'] ?? null]);
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $composed = cert_compose($ctx);
    if ($msg = cert_placeholder_message($composed)) {
        $fieldErrors = cert_missing_field_errors($composed, $ctx['def']);
        throw new CrudValidationException($fieldErrors + ['_placeholders' => $msg], $msg);
    }
    $settings = cert_settings();
    $userId = $opts['user_id'] ?? (function_exists('user_id') ? user_id() : null);
    $canApprove = $opts['can_approve'] ?? (function_exists('can') && can('certificates', 'approve'));
    // With the approval step on, requests wait for an approver; approvers may approve their own request at once.
    $status = $settings['approval_required'] && !($canApprove && ($opts['issue_now'] ?? true)) ? 'pending' : 'issued';
    $student = $ctx['student'];
    $name = cert_student_name($student);

    $row = db_transaction(function () use ($ctx, $composed, $status, $userId, $opts, $student, $name) {
        $no = cert_next_number($ctx['type'], $ctx['issue_date']);
        $hash = cert_hash($no, (int) $student['id'], $ctx['type'], $ctx['issue_date']);
        // Re-fill with the real number / verify URL
        $final = cert_compose($ctx, ['certificate_no' => $no, 'verify_url' => cert_verify_url($no)]);
        $data = [
            'fields' => $ctx['fields'],
            'design' => $final['design'],
            'title' => $final['title'],
            'subtitle' => $final['subtitle'],
            'student' => ['name' => $name, 'uid' => $student['student_uid'], 'program' => $student['program_name'], 'program_short' => $student['program_short'],
                'gender' => $student['gender'], 'photo' => $student['photo']],
            'template' => ['id' => (int) $ctx['template']['id'], 'name' => $ctx['template']['name']],
        ];
        $id = db_insert('certificates', [
            'certificate_no' => $no, 'type' => $ctx['type'], 'template_id' => (int) $ctx['template']['id'], 'student_id' => (int) $student['id'],
            'academic_session_id' => cert_session_for($ctx['issue_date'])['id'] ?? current_session_id(), 'title' => mb_substr($final['title'], 0, 190),
            'purpose' => ($ctx['fields']['purpose'] ?? '') !== '' ? mb_substr($ctx['fields']['purpose'], 0, 255) : null,
            'content_html' => $final['body'], 'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'issue_date' => $ctx['issue_date'], 'valid_until' => $ctx['valid_until'], 'verification_hash' => $hash, 'status' => $status,
            'remarks' => $ctx['remarks'], 'issued_by' => $userId, 'approved_by' => $status === 'issued' ? $userId : null,
            'approved_at' => $status === 'issued' ? date('Y-m-d H:i:s') : null,
            'reissued_from_id' => !empty($opts['reissued_from']['id']) ? (int) $opts['reissued_from']['id'] : null,
        ]);
        if ($status === 'issued' && !empty($opts['reissued_from'])) {
            cert_supersede($opts['reissued_from'], $no, (string) ($opts['reissue_reason'] ?? ''), $userId);
        }
        return db_row('SELECT * FROM certificates WHERE id = ?', [$id]);
    });

    if ($opts['log'] ?? true) {
        $label = cert_type_label($row['type']);
        $verb = $status === 'pending' ? 'Requested' : (!empty($opts['reissued_from']) ? 'Reissued' : 'Issued');
        log_activity($status === 'pending' ? 'request' : 'create', 'certificates', $row['id'], sprintf('%s %s %s for %s (%s)%s', $verb, $label, $row['certificate_no'], $name,
            $student['student_uid'], !empty($opts['reissued_from']) ? ' replacing ' . $opts['reissued_from']['certificate_no'] : ''));
    }
    if ($opts['notify'] ?? true) {
        cert_notify_issued($row, $name);
    }
    if ($status === 'issued' && ($opts['email_student'] ?? false)) {
        cert_email_student($row, $student);
    }
    return $row;
}

/** Mark the certificate a reissue replaces as revoked. */
function cert_supersede(array $old, string $newNo, string $reason, ?int $userId): void
{
    if ($old['status'] !== 'issued') {
        return;
    }
    db_update('certificates', [
        'status' => 'revoked', 'revoked_reason' => mb_substr('Superseded by ' . $newNo . ($reason !== '' ? ': ' . $reason : ''), 0, 255),
        'revoked_by' => $userId, 'revoked_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [(int) $old['id']]);
}

function cert_notify_issued(array $row, string $studentName): void
{
    $url = 'admin/certificates?view=' . $row['id'];
    if ($row['status'] === 'pending') {
        $approvers = db_column(
            "SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
             LEFT JOIN role_permissions rp ON rp.role_id = r.id LEFT JOIN permissions p ON p.id = rp.permission_id
             WHERE u.status = 'active' AND (r.is_super = 1 OR (p.module = 'certificates' AND p.action IN ('approve', 'manage')))"
        );
        if ($approvers) {
            notify(array_map('intval', $approvers), 'certificate', 'Certificate awaiting approval', cert_type_label($row['type']) . ' ' . $row['certificate_no'] . ' for ' . $studentName . ' needs approval.', $url, 'file-clock');
        }
        return;
    }
    notify('perm:certificates', 'certificate', 'Certificate generated', cert_type_label($row['type']) . ' ' . $row['certificate_no'] . ' issued to ' . $studentName . '.', $url, 'badge-check');
}

function cert_email_student(array $row, array $student): bool
{
    if (empty($student['email'])) {
        return false;
    }
    $url = cert_verify_url($row['certificate_no'], $row['verification_hash']);
    $html = '<p>Dear ' . e($student['first_name']) . ',</p>'
        . '<p>Your <strong>' . e(cert_type_label($row['type'])) . '</strong> has been issued by ' . e(institute_name()) . '.</p>'
        . '<table cellpadding="6" style="border-collapse:collapse;font-size:14px"><tr><td style="color:#64748b">Certificate no.</td><td><strong>' . e($row['certificate_no']) . '</strong></td></tr>'
        . '<tr><td style="color:#64748b">Issue date</td><td>' . e(format_date($row['issue_date'])) . '</td></tr>'
        . ($row['purpose'] ? '<tr><td style="color:#64748b">Purpose</td><td>' . e($row['purpose']) . '</td></tr>' : '') . '</table>'
        . '<p>Anyone you share it with can confirm its authenticity online:</p>'
        . '<p><a href="' . e($url) . '" style="display:inline-block;background:#0B2A5B;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">Verify certificate</a></p>'
        . '<p style="color:#64748b;font-size:13px">Collect the signed copy from the examination office.</p>';
    return send_mail($student['email'], 'Your ' . cert_type_label($row['type']) . ' ' . $row['certificate_no'], $html, ['related_type' => 'certificate', 'related_id' => (int) $row['id']]);
}

/** Approve a pending request: issue date becomes today, content and hash are refreshed. */
function cert_approve(array $c, ?int $userId): array
{
    if ($c['status'] !== 'pending') {
        throw new CrudException($c['certificate_no'] . ' is ' . $c['status'] . ' — only pending requests can be approved.');
    }
    $data = json_decode((string) $c['data'], true) ?: [];
    $tpl = $c['template_id'] ? db_row('SELECT * FROM certificate_templates WHERE id = ?', [(int) $c['template_id']]) : null;
    $student = cert_student((int) $c['student_id']);
    if (!$student) {
        throw new CrudException('The student of this certificate no longer exists.');
    }
    $issue = max(date('Y-m-d'), (string) $c['issue_date']);
    $validUntil = $c['valid_until'];
    if ($validUntil && $issue !== $c['issue_date']) {
        $shift = (strtotime($issue) - strtotime($c['issue_date']));
        $validUntil = date('Y-m-d', strtotime($validUntil) + $shift);
    }
    $content = $c['content_html'];
    if ($tpl) {
        $ctx = ['template' => $tpl, 'student' => $student, 'fields' => $data['fields'] ?? [], 'issue_date' => $issue, 'valid_until' => $validUntil, 'type' => $c['type']];
        $composed = cert_compose($ctx, ['certificate_no' => $c['certificate_no'], 'verify_url' => cert_verify_url($c['certificate_no'])]);
        if ($msg = cert_placeholder_message($composed)) {
            throw new CrudException('The template "' . $tpl['name'] . '" changed since this request was made. ' . $msg);
        }
        $content = $composed['body'];
        $data['design'] = $composed['design'];
        $data['title'] = $composed['title'];
        $data['subtitle'] = $composed['subtitle'];
    }
    $hash = cert_hash($c['certificate_no'], (int) $c['student_id'], $c['type'], $issue);
    db_transaction(function () use ($c, $issue, $validUntil, $content, $data, $hash, $userId) {
        db_update('certificates', [
            'status' => 'issued', 'issue_date' => $issue, 'valid_until' => $validUntil, 'content_html' => $content, 'verification_hash' => $hash,
            'academic_session_id' => cert_session_for($issue)['id'] ?? $c['academic_session_id'],
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'approved_by' => $userId, 'approved_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [(int) $c['id']]);
        if (!empty($c['reissued_from_id'])) {
            $old = db_row('SELECT * FROM certificates WHERE id = ?', [(int) $c['reissued_from_id']]);
            if ($old) {
                cert_supersede($old, $c['certificate_no'], (string) ($c['remarks'] ?? ''), $userId);
            }
        }
    });
    $row = db_row('SELECT * FROM certificates WHERE id = ?', [(int) $c['id']]);
    $name = cert_student_name($student);
    log_activity('approve', 'certificates', $c['id'], 'Approved ' . cert_type_label($c['type']) . ' ' . $c['certificate_no'] . ' for ' . $name);
    if (!empty($c['issued_by']) && (int) $c['issued_by'] !== (int) $userId) {
        notify((int) $c['issued_by'], 'certificate', 'Certificate approved', cert_type_label($c['type']) . ' ' . $c['certificate_no'] . ' for ' . $name . ' was approved.', 'admin/certificates?view=' . $c['id'], 'badge-check');
    }
    if (cert_settings()['email_student']) {
        cert_email_student($row, $student);
    }
    return $row;
}

function cert_find(int $id): ?array
{
    return db_row('SELECT * FROM certificates WHERE id = ?', [$id]);
}

/* ------------------------------------------------------------------
 * Public verification
 * ------------------------------------------------------------------ */

/**
 * Look up a certificate for public verification (rate limited, logged). Returns only safe details.
 * @return array{state: string, certificate: ?array, signature_ok: ?bool, number: string}
 */
function cert_verify(string $rawNo, string $source = 'web', ?string $signature = null): array
{
    $no = cert_normalise_no($rawNo);
    $ip = PHP_SAPI === 'cli' ? '127.0.0.1' : client_ip();
    $limit = max(5, cert_settings()['verify_rate_limit']);
    if ($no === '') {
        return ['state' => 'empty', 'certificate' => null, 'signature_ok' => null, 'number' => ''];
    }
    if (!rate_limit('certverify:' . $ip, $limit, 600)) {
        return ['state' => 'rate_limited', 'certificate' => null, 'signature_ok' => null, 'number' => $no];
    }
    $c = db_row("SELECT c.*, s.first_name, s.middle_name, s.last_name, p.name AS program_name, p.short_name AS program_short
                 FROM certificates c JOIN students s ON s.id = c.student_id JOIN programs p ON p.id = s.program_id
                 WHERE c.certificate_no = ? AND c.status IN ('issued', 'revoked')", [$no]);
    $state = 'not_found';
    $safe = null;
    $sigOk = null;
    if ($c) {
        $data = json_decode((string) $c['data'], true) ?: [];
        $expired = $c['status'] === 'issued' && !empty($c['valid_until']) && $c['valid_until'] < date('Y-m-d');
        $state = $c['status'] === 'revoked' ? 'revoked' : ($expired ? 'expired' : 'valid');
        $sigOk = $signature !== null && $signature !== '' ? hash_equals(cert_signature((string) $c['verification_hash']), strtolower(substr($signature, 0, 12))) : null;
        $safe = [
            'certificate_no' => $c['certificate_no'],
            'type' => $c['type'],
            'type_label' => cert_type_label($c['type']),
            'title' => $c['title'],
            'holder' => $data['student']['name'] ?? cert_student_name($c),
            'program' => $data['student']['program'] ?? $c['program_name'],
            'program_short' => $data['student']['program_short'] ?? $c['program_short'],
            'issue_date' => $c['issue_date'],
            'valid_until' => $c['valid_until'],
            'status' => $state,
            'revoked_at' => $c['status'] === 'revoked' ? substr((string) $c['revoked_at'], 0, 10) : null,
            'institute' => institute_name(),
            'security_code' => cert_security_code((string) $c['verification_hash']),
        ];
        // Count at most one verification per visitor per certificate per hour
        if (rate_limit('certcount:' . $ip . ':' . $c['id'], 1, 3600)) {
            db_exec('UPDATE certificates SET verified_count = verified_count + 1, last_verified_at = NOW() WHERE id = ?', [(int) $c['id']]);
        }
    }
    try {
        db_insert('certificate_verifications', [
            'certificate_id' => $c ? (int) $c['id'] : null, 'certificate_no' => $no, 'result' => $state,
            'source' => in_array($source, ['web', 'qr', 'api'], true) ? $source : 'web', 'ip_address' => cert_mask_ip($ip),
            'user_agent' => PHP_SAPI === 'cli' ? 'cli' : mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('[GIMT] certificate verification log failed: ' . $e->getMessage());
    }
    return ['state' => $state, 'certificate' => $safe, 'signature_ok' => $sigOk, 'number' => $no];
}

/* ------------------------------------------------------------------
 * Status helpers
 * ------------------------------------------------------------------ */

/** Effective status (issued certificates past their validity show as expired). */
function cert_effective_status(array $c): string
{
    if (($c['status'] ?? '') === 'issued' && !empty($c['valid_until']) && $c['valid_until'] < date('Y-m-d')) {
        return 'expired';
    }
    return (string) ($c['status'] ?? '');
}

function cert_status_labels(): array
{
    return ['issued' => 'Valid', 'pending' => 'Pending approval', 'revoked' => 'Revoked', 'rejected' => 'Rejected', 'expired' => 'Expired'];
}

function cert_reason(?string $reason, string $what = 'reason'): string
{
    $reason = trim((string) $reason);
    if (mb_strlen($reason) < 5) {
        throw new CrudValidationException(['reason' => 'Enter the ' . $what . ' (at least 5 characters).']);
    }
    if (mb_strlen($reason) > 230) {
        throw new CrudValidationException(['reason' => 'The ' . $what . ' may not be longer than 230 characters.']);
    }
    return $reason;
}

function cert_student_display_name(int $studentId): string
{
    return (string) db_value("SELECT TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) FROM students WHERE id = ?", [$studentId]);
}

/* ------------------------------------------------------------------
 * Revoke / reject / reissue
 * ------------------------------------------------------------------ */

/** Revoke an issued certificate. The public verification page reports it as revoked from now on. */
function cert_revoke(array $c, ?string $reason, ?int $userId): array
{
    if ($c['status'] !== 'issued') {
        throw new CrudException($c['certificate_no'] . ' is ' . strtolower(cert_status_labels()[$c['status']] ?? $c['status']) . ' — only issued certificates can be revoked.');
    }
    $reason = cert_reason($reason, 'reason for revoking');
    db_update('certificates', ['status' => 'revoked', 'revoked_reason' => $reason, 'revoked_by' => $userId, 'revoked_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $c['id']]);
    $name = cert_student_display_name((int) $c['student_id']);
    log_activity('revoke', 'certificates', $c['id'], 'Revoked ' . cert_type_label($c['type']) . ' ' . $c['certificate_no'] . ' of ' . $name . ': ' . $reason);
    notify('perm:certificates', 'certificate', 'Certificate revoked', cert_type_label($c['type']) . ' ' . $c['certificate_no'] . ' of ' . $name . ' was revoked.', 'admin/certificates?view=' . $c['id'], 'ban');
    return cert_find((int) $c['id']);
}

/** Reject a pending request. */
function cert_reject(array $c, ?string $reason, ?int $userId): array
{
    if ($c['status'] !== 'pending') {
        throw new CrudException($c['certificate_no'] . ' is ' . strtolower(cert_status_labels()[$c['status']] ?? $c['status']) . ' — only pending requests can be rejected.');
    }
    $reason = cert_reason($reason, 'reason for rejecting');
    db_update('certificates', ['status' => 'rejected', 'revoked_reason' => $reason, 'revoked_by' => $userId, 'revoked_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $c['id']]);
    $name = cert_student_display_name((int) $c['student_id']);
    log_activity('reject', 'certificates', $c['id'], 'Rejected ' . cert_type_label($c['type']) . ' request ' . $c['certificate_no'] . ' for ' . $name . ': ' . $reason);
    if (!empty($c['issued_by']) && (int) $c['issued_by'] !== (int) $userId) {
        notify((int) $c['issued_by'], 'certificate', 'Certificate request rejected', cert_type_label($c['type']) . ' ' . $c['certificate_no'] . ' for ' . $name . ' was rejected: ' . $reason, 'admin/certificates?view=' . $c['id'], 'file-x');
    }
    return cert_find((int) $c['id']);
}

/**
 * Reissue a certificate (corrected details / lost copy): a new number is issued with the same student, type and
 * template; the old certificate is revoked as "Superseded by …" once the new one is issued.
 * $in: reason (required), issue_date, valid_until, template_id, fields{} (override the original extra fields)
 */
function cert_reissue(array $c, array $in, array $opts = []): array
{
    if (!in_array($c['status'], ['issued', 'revoked'], true)) {
        throw new CrudException('Only issued or revoked certificates can be reissued. ' . $c['certificate_no'] . ' is ' . strtolower(cert_status_labels()[$c['status']] ?? $c['status']) . '.');
    }
    $pending = db_value("SELECT certificate_no FROM certificates WHERE reissued_from_id = ? AND status = 'pending'", [(int) $c['id']]);
    if ($pending) {
        throw new CrudException('A reissue request (' . $pending . ') for this certificate is already waiting for approval.');
    }
    $reason = cert_reason($in['reason'] ?? null, 'reason for reissuing');
    $data = json_decode((string) $c['data'], true) ?: [];
    $fields = array_merge($data['fields'] ?? [], is_array($in['fields'] ?? null) ? $in['fields'] : []);
    $templateId = (int) ($in['template_id'] ?? 0) ?: ((int) $c['template_id'] ?: null);
    if ($templateId && !db_value("SELECT id FROM certificate_templates WHERE id = ? AND status = 'active'", [$templateId])) {
        $templateId = null; // original template retired: fall back to the type's default
    }
    return cert_issue([
        'student_id' => $c['student_id'], 'type' => $c['type'], 'template_id' => $templateId, 'issue_date' => $in['issue_date'] ?? date('Y-m-d'),
        'valid_until' => $in['valid_until'] ?? null, 'remarks' => mb_substr('Reissue of ' . $c['certificate_no'] . ': ' . $reason, 0, 255), 'fields' => $fields,
    ], $opts + ['reissued_from' => $c, 'reissue_reason' => $reason]);
}

/* ------------------------------------------------------------------
 * Detail (admin drawer)
 * ------------------------------------------------------------------ */

function cert_detail(int $id): ?array
{
    $c = db_row("SELECT c.*, TRIM(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name)) AS student_name, s.student_uid, s.photo AS student_photo,
                        s.status AS student_status, s.current_semester, s.email AS student_email, p.short_name AS program_short,
                        p.name AS program_name, sc.name AS section_name, ct.name AS template_name, ct.status AS template_status, ss.name AS session_name,
                        iu.name AS issued_by_name, au.name AS approved_by_name, ru.name AS revoked_by_name
                 FROM certificates c JOIN students s ON s.id = c.student_id JOIN programs p ON p.id = s.program_id
                 LEFT JOIN sections sc ON sc.id = s.section_id LEFT JOIN certificate_templates ct ON ct.id = c.template_id
                 LEFT JOIN academic_sessions ss ON ss.id = c.academic_session_id LEFT JOIN users iu ON iu.id = c.issued_by
                 LEFT JOIN users au ON au.id = c.approved_by LEFT JOIN users ru ON ru.id = c.revoked_by WHERE c.id = ?", [$id]);
    if (!$c) {
        return null;
    }
    $data = json_decode((string) $c['data'], true) ?: [];
    $defs = cert_field_defs();
    $fields = [];
    foreach ($data['fields'] ?? [] as $k => $v) {
        if ($v === '' || $v === null || !isset($defs[$k])) {
            continue;
        }
        $fields[] = ['key' => $k, 'label' => $defs[$k]['label'], 'value' => $defs[$k]['type'] === 'date' ? format_date($v) : (string) $v];
    }
    $replaces = $c['reissued_from_id'] ? db_row('SELECT id, certificate_no, status, issue_date, valid_until FROM certificates WHERE id = ?', [(int) $c['reissued_from_id']]) : null;
    $replacedBy = db_all('SELECT id, certificate_no, status, issue_date, valid_until FROM certificates WHERE reissued_from_id = ? ORDER BY id', [$id]);
    $verifications = db_all('SELECT id, result, source, ip_address, created_at FROM certificate_verifications WHERE certificate_id = ? ORDER BY id DESC LIMIT 12', [$id]);
    $sources = db_pairs('SELECT source, COUNT(*) FROM certificate_verifications WHERE certificate_id = ? GROUP BY source', [$id]);

    // Timeline from the record itself (works for seeded/imported rows too)
    $direct = $c['approved_by'] !== null && (int) $c['approved_by'] === (int) $c['issued_by']
        && $c['approved_at'] && abs(strtotime((string) $c['approved_at']) - strtotime((string) $c['created_at'])) < 120;
    $events = [];
    $events[] = ['type' => $direct ? 'issued' : 'requested', 'title' => $direct ? 'Issued' : 'Requested', 'by' => $c['issued_by_name'], 'at' => $c['created_at'],
        'note' => $replaces ? 'Reissue of ' . $replaces['certificate_no'] : ($c['purpose'] ? 'Purpose: ' . $c['purpose'] : null)];
    if ($c['approved_at'] && !$direct) {
        $events[] = ['type' => 'approved', 'title' => 'Approved & issued', 'by' => $c['approved_by_name'], 'at' => $c['approved_at'], 'note' => null];
    }
    if ($c['last_printed_at']) {
        $n = (int) $c['printed_count'];
        $events[] = ['type' => 'printed', 'title' => 'Printed ' . $n . ' time' . ($n === 1 ? '' : 's'), 'by' => null, 'at' => $c['last_printed_at'], 'note' => 'Most recent print'];
    }
    if ($c['last_verified_at']) {
        $n = (int) $c['verified_count'];
        $events[] = ['type' => 'verified', 'title' => 'Verified online ' . $n . ' time' . ($n === 1 ? '' : 's'), 'by' => null, 'at' => $c['last_verified_at'], 'note' => 'Most recent verification'];
    }
    if ($c['revoked_at']) {
        $events[] = ['type' => $c['status'] === 'rejected' ? 'rejected' : 'revoked', 'title' => $c['status'] === 'rejected' ? 'Request rejected' : 'Revoked',
            'by' => $c['revoked_by_name'], 'at' => $c['revoked_at'], 'note' => $c['revoked_reason']];
    }
    foreach ($replacedBy as $r) {
        $events[] = ['type' => 'reissued', 'title' => 'Reissued as ' . $r['certificate_no'], 'by' => null, 'at' => $r['issue_date'] . ' 00:00:00',
            'note' => cert_status_labels()[cert_effective_status($r)] ?? $r['status']];
    }
    usort($events, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

    $hash = (string) $c['verification_hash'];
    unset($c['data'], $c['content_html'], $c['verification_hash']);
    $c['security_code'] = cert_security_code($hash);
    $c['effective_status'] = cert_effective_status($c);
    $c['type_label'] = cert_type_label($c['type']);
    $c['student_email'] = $c['student_email'] ? true : false;
    return [
        'certificate' => $c,
        'fields' => $fields,
        'raw_fields' => (object) ($data['fields'] ?? []),
        'replaces' => $replaces,
        'replaced_by' => $replacedBy,
        'verifications' => $verifications,
        'verification_sources' => array_map('intval', $sources),
        'events' => $events,
        'verify_url' => cert_verify_url($c['certificate_no'], $hash),
        'orientation' => (string) (($data['design']['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait'),
    ];
}

/* ------------------------------------------------------------------
 * Template preview (editor + specimen print)
 * ------------------------------------------------------------------ */

/** Normalise a template draft coming from the editor (unsaved values). */
function cert_template_draft(array $in): array
{
    $bool = fn ($v, bool $default) => $v === null ? $default : in_array($v, [true, 1, '1', 'true', 'on', 'yes'], true);
    $type = isset(cert_types()[$in['type'] ?? '']) ? (string) $in['type'] : 'custom';
    $days = $in['validity_days'] ?? null;
    return [
        'id' => (int) ($in['id'] ?? 0),
        'name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 150) ?: 'Untitled template',
        'type' => $type,
        'title' => mb_substr(trim((string) ($in['title'] ?? '')), 0, 190) ?: cert_type_label($type),
        'subtitle' => mb_substr(trim((string) ($in['subtitle'] ?? '')), 0, 190),
        'body_html' => sanitize_html((string) ($in['body_html'] ?? '')),
        'orientation' => ($in['orientation'] ?? '') === 'landscape' ? 'landscape' : 'portrait',
        'border_style' => (string) ($in['border_style'] ?? 'classic'),
        'accent_color' => cert_safe_color((string) ($in['accent_color'] ?? '')),
        'font_style' => (string) ($in['font_style'] ?? 'serif'),
        'signatory_name' => mb_substr((string) ($in['signatory_name'] ?? ''), 0, 150),
        'signatory_designation' => mb_substr((string) ($in['signatory_designation'] ?? ''), 0, 150),
        'signatory2_name' => mb_substr((string) ($in['signatory2_name'] ?? ''), 0, 150),
        'signatory2_designation' => mb_substr((string) ($in['signatory2_designation'] ?? ''), 0, 150),
        'show_photo' => $bool($in['show_photo'] ?? null, false) ? 1 : 0,
        'show_seal' => $bool($in['show_seal'] ?? null, true) ? 1 : 0,
        'show_qr' => $bool($in['show_qr'] ?? null, true) ? 1 : 0,
        'validity_days' => $days !== null && $days !== '' && is_numeric($days) && (int) $days > 0 ? (int) $days : null,
    ];
}

/**
 * Render a template (saved row or draft) with a real student or the built-in sample student and sample extra fields.
 */
function cert_template_preview(array $tpl, ?int $studentId = null, bool $embed = true): array
{
    $type = isset(cert_types()[$tpl['type'] ?? '']) ? $tpl['type'] : 'custom';
    $def = cert_types()[$type];
    $student = $studentId ? cert_student($studentId) : null;
    $sample = !$student;
    $student = $student ?: cert_sample_student();
    $issue = date('Y-m-d');
    $days = isset($tpl['validity_days']) && $tpl['validity_days'] !== null && $tpl['validity_days'] !== '' ? (int) $tpl['validity_days'] : (int) ($def['validity_days'] ?? 0);
    $valid = $days > 0 ? date('Y-m-d', strtotime('+' . $days . ' days')) : null;
    $no = strtoupper((string) setting('institute_short_name', 'GIMT')) . '-' . $def['code'] . '-' . date('Y') . '-00042';
    $ctx = ['template' => $tpl, 'student' => $student, 'fields' => cert_sample_fields(), 'issue_date' => $issue, 'valid_until' => $valid, 'type' => $type, 'def' => $def];
    $composed = cert_compose($ctx, ['certificate_no' => $no, 'verify_url' => cert_verify_url($no)]);
    $sheet = cert_preview_sheet($ctx, $composed, 'specimen', $no);
    $source = (string) ($tpl['title'] ?? '') . ' ' . (string) ($tpl['subtitle'] ?? '') . ' ' . (string) ($tpl['body_html'] ?? '');
    return [
        'sheet' => $sheet,
        'html' => cert_document($sheet, ['embed' => $embed, 'orientation' => $composed['design']['orientation'], 'title' => 'Template preview']),
        'used' => cert_template_keys($source),
        'unknown' => $composed['unknown'],
        'missing' => $composed['missing'],
        'problems' => cert_template_problems($source),
        'student' => ['id' => $sample ? null : (int) $student['id'], 'name' => cert_student_name($student), 'uid' => (string) $student['student_uid'], 'program' => (string) ($student['program_short'] ?? '')],
        'sample' => $sample,
        'orientation' => $composed['design']['orientation'],
        'words' => str_word_count(strip_tags(cert_fill((string) $tpl['body_html'], $composed['vars']))),
    ];
}

/* ------------------------------------------------------------------
 * Student search + bulk issue
 * ------------------------------------------------------------------ */

/** Options for the issue dialog's student picker (all statuses: TC / migration go to students who left). */
function cert_student_options(array $params): array
{
    $where = [];
    $args = [];
    if (!empty($params['ids'])) {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $params['ids']))));
        if (!$ids) {
            return [];
        }
        $where[] = 's.id IN (' . implode(',', $ids) . ')';
    } else {
        $q = trim((string) ($params['q'] ?? ''));
        if ($q !== '') {
            foreach (preg_split('/\s+/', mb_substr($q, 0, 80)) as $w) {
                $where[] = '(s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_uid LIKE ? OR s.admission_no LIKE ? OR s.roll_no LIKE ? OR s.enrollment_no LIKE ? OR s.mobile LIKE ?)';
                array_push($args, ...array_fill(0, 7, '%' . $w . '%'));
            }
        }
        if (!empty($params['program_id'])) {
            $where[] = 's.program_id = ?';
            $args[] = (int) $params['program_id'];
        }
    }
    $limit = max(1, min(50, (int) ($params['limit'] ?? 30)));
    $rows = db_all("SELECT s.id, TRIM(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name)) AS name, s.student_uid, s.status, s.current_semester, s.photo, p.short_name
                    FROM students s JOIN programs p ON p.id = s.program_id" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . "
                    ORDER BY (s.status = 'active') DESC, s.first_name, s.last_name LIMIT $limit", $args);
    return array_map(fn ($r) => [
        'value' => (int) $r['id'], 'label' => $r['name'] . ' (' . $r['student_uid'] . ')',
        'sub' => $r['short_name'] . ' · Sem ' . (int) $r['current_semester'] . ($r['status'] !== 'active' ? ' · ' . label_from_key($r['status']) : ''),
        'status' => $r['status'], 'photo' => $r['photo'],
    ], $rows);
}

/** Compact student card for the issue dialog. */
function cert_student_card(int $id): ?array
{
    $s = cert_student($id);
    if (!$s) {
        return null;
    }
    $certs = db_all('SELECT id, certificate_no, type, status, issue_date, valid_until FROM certificates WHERE student_id = ? ORDER BY issue_date DESC, id DESC LIMIT 12', [$id]);
    foreach ($certs as &$c) {
        $c['id'] = (int) $c['id'];
        $c['effective_status'] = cert_effective_status($c);
        $c['type_label'] = cert_type_label($c['type']);
    }
    unset($c);
    $res = cert_result_vars($id);
    return [
        'id' => (int) $s['id'], 'name' => cert_student_name($s), 'student_uid' => $s['student_uid'], 'photo' => $s['photo'], 'status' => $s['status'],
        'gender' => $s['gender'], 'program' => $s['program_name'], 'program_short' => $s['program_short'], 'semester' => (int) $s['current_semester'],
        'total_semesters' => (int) $s['total_semesters'], 'section' => $s['section_name'], 'batch' => $s['batch_name'], 'father_name' => $s['father_name'],
        'has_email' => (bool) $s['email'], 'cgpa' => $res['cgpa'], 'certificates' => $certs,
    ];
}

/**
 * Students of a section (or program + semester) with their eligibility for a certificate type.
 */
function cert_bulk_candidates(array $p): array
{
    $type = (string) ($p['type'] ?? '');
    $def = cert_types()[$type] ?? null;
    $errors = [];
    if (!$def) {
        $errors['type'] = 'Choose the certificate type.';
    }
    $where = [];
    $args = [];
    if (!empty($p['section_id'])) {
        $where[] = 's.section_id = ?';
        $args[] = (int) $p['section_id'];
    } elseif (!empty($p['program_id'])) {
        $where[] = 's.program_id = ?';
        $args[] = (int) $p['program_id'];
        if (!empty($p['semester'])) {
            $where[] = 's.current_semester = ?';
            $args[] = (int) $p['semester'];
        }
    } else {
        $errors['section_id'] = 'Select a section, or a program and semester.';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    if (empty($p['include_inactive'])) {
        $where[] = "s.status = 'active'";
    }
    $rows = db_all("SELECT s.id, TRIM(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name)) AS name, s.student_uid, s.roll_no, s.status, s.current_semester,
                           s.photo, p.short_name AS program_short, p.total_semesters, sc.name AS section_name,
                           (SELECT c.certificate_no FROM certificates c WHERE c.student_id = s.id AND c.type = ? AND c.status IN ('pending','issued') ORDER BY c.id DESC LIMIT 1) AS existing_no,
                           (SELECT c.status FROM certificates c WHERE c.student_id = s.id AND c.type = ? AND c.status IN ('pending','issued') ORDER BY c.id DESC LIMIT 1) AS existing_status
                    FROM students s JOIN programs p ON p.id = s.program_id LEFT JOIN sections sc ON sc.id = s.section_id
                    WHERE " . implode(' AND ', $where) . ' ORDER BY s.roll_no, s.first_name LIMIT 300', array_merge([$type, $type], $args));
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $reason = null;
        if (!empty($def['active_only']) && $r['status'] !== 'active') {
            $reason = label_from_key($r['status']) . ' — ' . $def['short'] . ' is for active students only';
        } elseif (cert_ineligible_reason($r, $type)) {
            $reason = in_array($r['status'], ['active', 'graduated'], true)
                ? 'Semester ' . (int) $r['current_semester'] . ' of ' . (int) $r['total_semesters'] . ' — not completed yet'
                : label_from_key($r['status']) . ' — programme not completed';
        } elseif ($r['existing_no'] && !empty($def['once'])) {
            $reason = 'Already has ' . $r['existing_no'] . ($r['existing_status'] === 'pending' ? ' (pending)' : '');
        }
        $r['eligible'] = $reason === null;
        $r['reason'] = $reason;
        $r['warning'] = $reason === null && $r['existing_no'] ? 'Already has ' . $r['existing_no'] . ($r['existing_status'] === 'pending' ? ' (pending)' : '') : null;
    }
    unset($r);
    return ['students' => $rows, 'type' => $type, 'type_label' => $def['label']];
}

/**
 * Issue one certificate type to many students. Each student is validated separately; failures are reported, not fatal.
 * $in: student_ids[], type, template_id, issue_date, valid_until, fields{}, remarks
 * @return array{issued: array, skipped: array}
 */
function cert_bulk_issue(array $in, array $opts = []): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['student_ids'] ?? [])))));
    if (!$ids) {
        throw new CrudValidationException(['student_ids' => 'Select at least one student.']);
    }
    if (count($ids) > 300) {
        throw new CrudValidationException(['student_ids' => 'Issue at most 300 certificates at a time.']);
    }
    // Validate the shared part (type, template, dates, extra fields) once so form errors come back inline.
    [$errors, $ctx] = cert_validate_issue(['student_id' => $ids[0]] + $in);
    unset($errors['student_id']);
    if (isset($errors['type']) && !empty($ctx['def'])) {
        unset($errors['type']); // per-student rule (already has one) - reported per student below
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    if (!empty($ctx['template'])) {
        $composed = cert_compose($ctx);
        $missingFields = cert_missing_field_errors($composed, $ctx['def']);
        if ($missingFields) {
            throw new CrudValidationException($missingFields, 'The template needs a few more details.');
        }
    }
    $issued = [];
    $skipped = [];
    foreach ($ids as $sid) {
        try {
            $row = cert_issue(['student_id' => $sid] + $in, $opts + ['notify' => false]);
            $issued[] = ['id' => (int) $row['id'], 'certificate_no' => $row['certificate_no'], 'status' => $row['status'], 'student_id' => $sid];
        } catch (CrudValidationException $e) {
            $msg = $e->errors ? (string) reset($e->errors) : $e->getMessage();
            $skipped[] = ['student_id' => $sid, 'name' => cert_student_display_name($sid), 'reason' => $msg];
        } catch (CrudException $e) {
            $skipped[] = ['student_id' => $sid, 'name' => cert_student_display_name($sid), 'reason' => $e->getMessage()];
        }
    }
    if ($issued) {
        $label = cert_type_label((string) $in['type']);
        $pending = count(array_filter($issued, fn ($r) => $r['status'] === 'pending'));
        $n = count($issued);
        log_activity('create', 'certificates', implode(',', array_slice(array_column($issued, 'id'), 0, 20)),
            sprintf('Bulk %s %d × %s%s', $pending ? 'requested' : 'issued', $n, $label, $skipped ? ' (' . count($skipped) . ' skipped)' : ''));
        notify('perm:certificates', 'certificate', $pending ? 'Certificates awaiting approval' : 'Certificates generated',
            sprintf('%d × %s %s in bulk.', $n, $label, $pending ? 'requested' : 'issued'), 'admin/certificates', 'files');
    }
    return ['issued' => $issued, 'skipped' => $skipped];
}

/* ------------------------------------------------------------------
 * Dashboard summary
 * ------------------------------------------------------------------ */

function cert_summary(): array
{
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $prevStart = date('Y-m-01', strtotime($monthStart . ' -1 month'));
    $sessionId = current_session_id();
    $k = db_row("SELECT
            COALESCE(SUM(status = 'issued' AND (valid_until IS NULL OR valid_until >= ?)), 0) AS valid_count,
            COALESCE(SUM(status = 'issued' AND valid_until < ?), 0) AS expired_count,
            COALESCE(SUM(status = 'pending'), 0) AS pending_count,
            COALESCE(SUM(status = 'revoked'), 0) AS revoked_count,
            COALESCE(SUM(status = 'rejected'), 0) AS rejected_count,
            COALESCE(SUM(status IN ('issued','revoked') AND academic_session_id <=> ?), 0) AS session_count,
            COALESCE(SUM(status IN ('issued','revoked') AND issue_date >= ?), 0) AS month_count,
            COALESCE(SUM(status IN ('issued','revoked') AND issue_date >= ? AND issue_date < ?), 0) AS prev_month_count,
            COALESCE(SUM(printed_count), 0) AS printed_total,
            COUNT(*) AS all_count
        FROM certificates", [$today, $today, $sessionId, $monthStart, $prevStart, $monthStart]) ?: [];
    $k = array_map('intval', $k);
    $since30 = date('Y-m-d H:i:s', strtotime('-30 days'));
    $v30 = (int) db_value('SELECT COUNT(*) FROM certificate_verifications WHERE created_at >= ?', [$since30]);
    $vPrev = (int) db_value('SELECT COUNT(*) FROM certificate_verifications WHERE created_at >= ? AND created_at < ?', [date('Y-m-d H:i:s', strtotime('-60 days')), $since30]);
    $k['verifications_30d'] = $v30;
    $k['verifications_trend'] = $vPrev ? round(($v30 - $vPrev) / $vPrev * 100, 1) : null;
    $k['verifications_flagged'] = (int) db_value("SELECT COUNT(*) FROM certificate_verifications WHERE created_at >= ? AND result IN ('not_found','revoked','expired')", [$since30]);
    $k['month_trend'] = $k['prev_month_count'] ? round(($k['month_count'] - $k['prev_month_count']) / $k['prev_month_count'] * 100, 1) : null;

    $byType = [];
    foreach (db_all("SELECT type, COUNT(*) AS total, SUM(status = 'issued') AS issued, SUM(status = 'pending') AS pending FROM certificates WHERE status IN ('issued','revoked','pending') GROUP BY type") as $r) {
        $byType[$r['type']] = $r;
    }
    $typesOut = [];
    foreach (cert_types() as $key => $t) {
        $r = $byType[$key] ?? null;
        if ($key === 'custom' && !$r) {
            continue;
        }
        $typesOut[] = ['type' => $key, 'label' => $t['short'], 'tone' => $t['tone'], 'total' => (int) ($r['total'] ?? 0), 'issued' => (int) ($r['issued'] ?? 0), 'pending' => (int) ($r['pending'] ?? 0)];
    }

    $months = [];
    for ($i = 5; $i >= 0; $i--) {
        $m = date('Y-m', strtotime($monthStart . " -$i month"));
        $months[$m] = ['month' => $m, 'label' => date('M y', strtotime($m . '-01')), 'issued' => 0, 'verifications' => 0];
    }
    $first = array_key_first($months) . '-01';
    foreach (db_all("SELECT DATE_FORMAT(issue_date, '%Y-%m') AS m, COUNT(*) AS n FROM certificates WHERE status IN ('issued','revoked') AND issue_date >= ? GROUP BY m", [$first]) as $r) {
        if (isset($months[$r['m']])) {
            $months[$r['m']]['issued'] = (int) $r['n'];
        }
    }
    foreach (db_all("SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, COUNT(*) AS n FROM certificate_verifications WHERE created_at >= ? GROUP BY m", [$first]) as $r) {
        if (isset($months[$r['m']])) {
            $months[$r['m']]['verifications'] = (int) $r['n'];
        }
    }
    $recent = db_all("SELECT v.id, v.certificate_no, v.result, v.source, v.created_at, c.id AS certificate_id, c.type,
                             TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name
                      FROM certificate_verifications v LEFT JOIN certificates c ON c.id = v.certificate_id LEFT JOIN students s ON s.id = c.student_id
                      ORDER BY v.id DESC LIMIT 8");
    foreach ($recent as &$r) {
        $r['type_label'] = $r['type'] ? (cert_types()[$r['type']]['short'] ?? label_from_key((string) $r['type'])) : null;
        $r['certificate_id'] = $r['certificate_id'] !== null ? (int) $r['certificate_id'] : null;
    }
    unset($r);
    return ['kpis' => $k, 'by_type' => $typesOut, 'monthly' => array_values($months), 'recent_verifications' => $recent, 'settings' => cert_settings()];
}

/** Public-facing configuration for the admin screens (types, fields, placeholders, styles, templates, settings). */
function cert_config(): array
{
    $types = [];
    foreach (cert_types() as $key => $t) {
        $types[] = ['key' => $key] + $t;
    }
    $fields = [];
    foreach (cert_field_defs() as $key => $f) {
        $fields[$key] = ['key' => $key] + $f;
    }
    $groups = [];
    foreach (cert_placeholders() as $key => $p) {
        $groups[$p['group']][] = ['key' => $key, 'label' => $p['label']];
    }
    $placeholders = [];
    foreach ($groups as $g => $items) {
        $placeholders[] = ['group' => $g, 'items' => $items];
    }
    $templates = db_all("SELECT id, name, type, title, orientation, border_style, accent_color, is_default, validity_days FROM certificate_templates
                         WHERE status = 'active' ORDER BY type, is_default DESC, name");
    foreach ($templates as &$t) {
        $t['id'] = (int) $t['id'];
        $t['is_default'] = (bool) $t['is_default'];
        $t['validity_days'] = $t['validity_days'] !== null ? (int) $t['validity_days'] : null;
    }
    unset($t);
    return [
        'types' => $types,
        'fields' => $fields,
        'placeholders' => $placeholders,
        'border_styles' => cert_border_styles(),
        'font_styles' => cert_font_styles(),
        'templates' => $templates,
        'settings' => cert_settings() + ['prefix' => (string) setting('certificate_prefix', setting('institute_short_name', 'GIMT'))],
        'status_labels' => cert_status_labels(),
        'verify_base' => absolute_url('verify-certificate'),
    ];
}
