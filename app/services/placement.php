<?php
/**
 * Placement cell domain logic (companies, drives, eligibility, applications & interview rounds, offers, trainings,
 * dashboard and the printable placement report). Used by api/routes/placement.php, the placement CRUD modules,
 * admin/print/placement-report.php and the demo seeder.
 *
 * Definitions used everywhere (kept in line with the main dashboard):
 *   - a "valid" offer is one with status offered | accepted | joined; a student with a valid offer counts as placed,
 *     accepted/joined offers are "confirmed" placements.
 *   - a drive / offer belongs to the academic session whose date range contains its drive_date / offer_date.
 *   - eligibility for a drive = active student of an eligible program, in the passing-out batch (batch end year) or
 *     final year when no batch is set, latest published CGPA ≥ min CGPA, active backlogs ≤ max backlogs and
 *     previous qualification % ≥ min percentage.
 */

const PLACEMENT_VALID_OFFER_SQL = "('offered', 'accepted', 'joined')";

function placement_app_statuses(): array
{
    return ['applied' => 'Applied', 'shortlisted' => 'Shortlisted', 'aptitude' => 'Aptitude round', 'technical' => 'Technical round', 'hr' => 'HR round',
        'selected' => 'Selected', 'rejected' => 'Rejected', 'on_hold' => 'On hold', 'withdrawn' => 'Withdrawn'];
}

function placement_offer_statuses(): array
{
    return ['offered' => 'Offered', 'accepted' => 'Accepted (placed)', 'declined' => 'Declined', 'joined' => 'Joined', 'revoked' => 'Revoked'];
}

function placement_drive_statuses(): array
{
    return ['upcoming' => 'Upcoming', 'open' => 'Registration open', 'ongoing' => 'Ongoing', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
}

function placement_job_types(): array
{
    return ['full_time' => 'Full-time', 'internship' => 'Internship', 'internship_ppo' => 'Internship + PPO', 'contract' => 'Contract'];
}

function placement_industries(): array
{
    $list = ['IT Services', 'Software Product', 'Consulting', 'BFSI', 'Fintech', 'E-commerce', 'FMCG', 'Retail', 'Manufacturing', 'Automobile', 'Analytics',
        'EdTech', 'Healthcare', 'Telecom', 'Media & Entertainment', 'Infrastructure', 'Logistics', 'Agritech', 'Hospitality', 'Government / PSU', 'Other'];
    return array_combine($list, $list);
}

function placement_company_types(): array
{
    return ['mnc' => 'MNC', 'indian_mnc' => 'Indian MNC', 'large_indian' => 'Large Indian company', 'startup' => 'Startup', 'psu' => 'PSU', 'government' => 'Government',
        'sme' => 'SME', 'ngo' => 'NGO / Non-profit'];
}

function placement_tie_up_statuses(): array
{
    return ['prospect' => 'Prospect', 'active' => 'Active recruiter', 'mou_signed' => 'MoU signed', 'dormant' => 'Dormant'];
}

/* ------------------------------------------------------------------
 * Sessions, rounds, student metrics
 * ------------------------------------------------------------------ */

/** Academic session row (id, name, start_date, end_date) — the requested one or the current one. */
function placement_session(?int $id = null): array
{
    $row = $id ? db_row('SELECT id, name, start_date, end_date FROM academic_sessions WHERE id = ?', [$id]) : null;
    if (!$row) {
        $cur = current_session();
        $row = !empty($cur['id']) ? db_row('SELECT id, name, start_date, end_date FROM academic_sessions WHERE id = ?', [(int) $cur['id']]) : null;
    }
    if (!$row) {
        $y = (int) date('n') >= 7 ? (int) date('Y') : (int) date('Y') - 1;
        $row = ['id' => null, 'name' => $y . '-' . substr((string) ($y + 1), 2), 'start_date' => $y . '-07-01', 'end_date' => ($y + 1) . '-06-30'];
    }
    return $row;
}

/** Session that contains a date (for drives / offers). */
function placement_session_for_date(?string $date): ?array
{
    if (!$date) {
        return null;
    }
    return db_row('SELECT id, name, start_date, end_date FROM academic_sessions WHERE ? BETWEEN start_date AND end_date ORDER BY start_date DESC LIMIT 1', [$date]);
}

/** "Aptitude Test, Technical Interview, HR" → ['Aptitude Test', 'Technical Interview', 'HR'] */
function placement_drive_rounds(?string $rounds): array
{
    $out = [];
    foreach (preg_split('/\s*[,;\n]\s*/', (string) $rounds) as $r) {
        $r = trim($r);
        if ($r !== '' && !in_array(mb_strtolower($r), array_map('mb_strtolower', $out), true)) {
            $out[] = mb_substr($r, 0, 60);
        }
    }
    return $out;
}

/** Map a round name to the application status shown while a student is in that round. */
function placement_round_stage(string $round): string
{
    $r = mb_strtolower($round);
    if (preg_match('/aptitude|online test|written|assessment|coding|test/', $r)) {
        return 'aptitude';
    }
    if (preg_match('/\bhr\b|human resource|managerial|final|partner|director/', $r)) {
        return 'hr';
    }
    if (preg_match('/technical|case|group discussion|\bgd\b|interview|presentation|assignment/', $r)) {
        return 'technical';
    }
    return 'shortlisted';
}

/** SQL expression: latest published CGPA of student alias $s. */
function placement_cgpa_sql(string $s = 's'): string
{
    return "(SELECT r.cgpa FROM results r WHERE r.student_id = $s.id AND r.is_published = 1 AND r.cgpa IS NOT NULL ORDER BY r.semester_no DESC, r.id DESC LIMIT 1)";
}

/** SQL expression: active backlogs (from the latest published result) of student alias $s. */
function placement_backlog_sql(string $s = 's'): string
{
    return "(SELECT r.backlog_count FROM results r WHERE r.student_id = $s.id AND r.is_published = 1 AND r.cgpa IS NOT NULL ORDER BY r.semester_no DESC, r.id DESC LIMIT 1)";
}

function placement_student_name_sql(string $s = 's'): string
{
    return "TRIM(CONCAT_WS(' ', $s.first_name, $s.last_name))";
}

/* ------------------------------------------------------------------
 * Eligibility
 * ------------------------------------------------------------------ */

function placement_drive_row(int $id): ?array
{
    return db_row('SELECT d.*, c.name AS company_name, c.logo AS company_logo, c.industry, c.website AS company_website, c.tie_up_status
                   FROM placement_drives d JOIN companies c ON c.id = d.company_id WHERE d.id = ?', [$id]);
}

/** Eligible program ids of a drive (empty = all programs). */
function placement_drive_programs($drive): array
{
    $raw = is_array($drive) ? ($drive['eligible_programs'] ?? null) : $drive;
    $arr = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
    return array_values(array_unique(array_filter(array_map('intval', (array) $arr))));
}

const PLACEMENT_ELIGIBLE_FROM = ' FROM students s JOIN programs p ON p.id = s.program_id LEFT JOIN batches b ON b.id = s.batch_id';

/**
 * WHERE conditions (on students s / programs p / batches b) for a drive's eligibility criteria.
 * @return array{0: string[], 1: array}
 */
function placement_eligibility_conditions(array $drive): array
{
    $where = ["s.status = 'active'"];
    $args = [];
    $programs = placement_drive_programs($drive);
    if ($programs) {
        $where[] = 's.program_id IN (' . implode(',', $programs) . ')';
    }
    if (!empty($drive['batch_year'])) {
        $where[] = '(b.end_year = ? OR (b.id IS NULL AND s.current_semester >= GREATEST(p.total_semesters - 1, 1)))';
        $args[] = (int) $drive['batch_year'];
    } else {
        $where[] = 's.current_semester >= GREATEST(p.total_semesters - 1, 1)';
    }
    if ($drive['min_cgpa'] !== null && $drive['min_cgpa'] !== '' && (float) $drive['min_cgpa'] > 0) {
        $where[] = 'COALESCE(' . placement_cgpa_sql() . ', 0) >= ?';
        $args[] = (float) $drive['min_cgpa'];
    }
    if ($drive['max_backlogs'] !== null && $drive['max_backlogs'] !== '') {
        $where[] = 'COALESCE(' . placement_backlog_sql() . ', 0) <= ?';
        $args[] = (int) $drive['max_backlogs'];
    }
    if ($drive['min_percentage'] !== null && $drive['min_percentage'] !== '' && (float) $drive['min_percentage'] > 0) {
        $where[] = 'COALESCE(s.previous_percentage, 0) >= ?';
        $args[] = (float) $drive['min_percentage'];
    }
    return [$where, $args];
}

/** Student ids eligible for a drive. */
function placement_eligible_ids(array $drive): array
{
    [$where, $args] = placement_eligibility_conditions($drive);
    return array_map('intval', db_column('SELECT s.id' . PLACEMENT_ELIGIBLE_FROM . ' WHERE ' . implode(' AND ', $where), $args));
}

/** Why a student is not eligible for a drive (null when eligible). */
function placement_ineligibility_reason(array $drive, int $studentId): ?string
{
    $s = db_row('SELECT s.id, s.status, s.program_id, s.current_semester, s.previous_percentage, p.short_name, p.total_semesters, b.end_year, '
        . placement_cgpa_sql() . ' AS cgpa, ' . placement_backlog_sql() . ' AS backlogs' . PLACEMENT_ELIGIBLE_FROM . ' WHERE s.id = ?', [$studentId]);
    if (!$s) {
        return 'Student not found.';
    }
    if ($s['status'] !== 'active') {
        return 'Only active students can be registered (status: ' . label_from_key($s['status']) . ').';
    }
    $programs = placement_drive_programs($drive);
    if ($programs && !in_array((int) $s['program_id'], $programs, true)) {
        return $s['short_name'] . ' is not an eligible program for this drive.';
    }
    if (!empty($drive['batch_year'])) {
        if ($s['end_year'] !== null && (int) $s['end_year'] !== (int) $drive['batch_year']) {
            return 'The drive is for the ' . $drive['batch_year'] . ' passing-out batch (student graduates in ' . $s['end_year'] . ').';
        }
        if ($s['end_year'] === null && (int) $s['current_semester'] < max(1, (int) $s['total_semesters'] - 1)) {
            return 'The drive is open to final-year students only.';
        }
    } elseif ((int) $s['current_semester'] < max(1, (int) $s['total_semesters'] - 1)) {
        return 'The drive is open to final-year students only.';
    }
    if ($drive['min_cgpa'] !== null && (float) $drive['min_cgpa'] > 0 && (float) ($s['cgpa'] ?? 0) < (float) $drive['min_cgpa']) {
        return 'CGPA ' . ($s['cgpa'] !== null ? number_format((float) $s['cgpa'], 2) : 'not available') . ' is below the required ' . number_format((float) $drive['min_cgpa'], 2) . '.';
    }
    if ($drive['max_backlogs'] !== null && $drive['max_backlogs'] !== '' && (int) ($s['backlogs'] ?? 0) > (int) $drive['max_backlogs']) {
        return $s['backlogs'] . ' active backlog(s); at most ' . (int) $drive['max_backlogs'] . ' allowed.';
    }
    if ($drive['min_percentage'] !== null && (float) $drive['min_percentage'] > 0 && (float) ($s['previous_percentage'] ?? 0) < (float) $drive['min_percentage']) {
        return 'Previous qualification ' . (float) $s['previous_percentage'] . '% is below the required ' . (float) $drive['min_percentage'] . '%.';
    }
    return null;
}

/** Paginated eligible students of a drive with their registration state. */
function placement_eligible_list(array $drive, array $req): array
{
    [$where, $args] = placement_eligibility_conditions($drive);
    $driveId = (int) $drive['id'];
    $base = PLACEMENT_ELIGIBLE_FROM . ' LEFT JOIN placement_applications pa ON pa.drive_id = ' . $driveId . ' AND pa.student_id = s.id';
    $baseWhere = $where;
    $baseArgs = $args;
    $q = trim((string) ($req['q'] ?? ''));
    if ($q !== '') {
        foreach (preg_split('/\s+/', mb_substr($q, 0, 80)) as $word) {
            $where[] = '(s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_uid LIKE ? OR s.roll_no LIKE ?)';
            array_push($args, "%$word%", "%$word%", "%$word%", "%$word%");
        }
    }
    if (!empty($req['program_id'])) {
        $where[] = 's.program_id = ?';
        $args[] = (int) $req['program_id'];
    }
    $show = (string) ($req['show'] ?? 'all');
    if ($show === 'unregistered') {
        $where[] = 'pa.id IS NULL';
    } elseif ($show === 'registered') {
        $where[] = 'pa.id IS NOT NULL';
    }
    $total = (int) db_value('SELECT COUNT(*)' . $base . ' WHERE ' . implode(' AND ', $where), $args);
    $perPage = max(5, min(100, (int) ($req['per_page'] ?? 25)));
    $p = paginate($total, max(1, (int) ($req['page'] ?? 1)), $perPage);
    $sort = ['cgpa' => 'cgpa DESC', 'name' => 's.first_name ASC, s.last_name ASC', 'program' => 'p.sort_order ASC, s.first_name ASC'][$req['sort'] ?? 'cgpa'] ?? 'cgpa DESC';
    $rows = db_all('SELECT s.id, ' . placement_student_name_sql() . ' AS name, s.student_uid, s.roll_no, s.photo, s.gender, s.current_semester, s.previous_percentage,
                           p.short_name AS program_short, ' . placement_cgpa_sql() . ' AS cgpa, ' . placement_backlog_sql() . ' AS backlogs,
                           pa.id AS application_id, pa.status AS application_status, pa.current_round,
                           (SELECT MAX(o.package_lpa) FROM placement_offers o WHERE o.student_id = s.id AND o.status IN ' . PLACEMENT_VALID_OFFER_SQL . ') AS best_offer_lpa'
        . $base . ' WHERE ' . implode(' AND ', $where) . " ORDER BY $sort, s.id LIMIT {$p['per_page']} OFFSET {$p['offset']}", $args);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['application_id'] = $r['application_id'] !== null ? (int) $r['application_id'] : null;
        $r['cgpa'] = $r['cgpa'] !== null ? (float) $r['cgpa'] : null;
        $r['backlogs'] = (int) ($r['backlogs'] ?? 0);
        $r['best_offer_lpa'] = $r['best_offer_lpa'] !== null ? (float) $r['best_offer_lpa'] : null;
    }
    unset($r);
    $sum = db_row('SELECT COUNT(*) AS eligible, SUM(pa.id IS NOT NULL) AS registered,
                          SUM(EXISTS (SELECT 1 FROM placement_offers o WHERE o.student_id = s.id AND o.status IN ' . PLACEMENT_VALID_OFFER_SQL . ')) AS placed'
        . $base . ' WHERE ' . implode(' AND ', $baseWhere), $baseArgs);
    return ['rows' => $rows, 'total' => $p['total'], 'page' => $p['page'], 'per_page' => $p['per_page'], 'pages' => $p['pages'], 'from' => $p['from'], 'to' => $p['to'],
        'summary' => ['eligible' => (int) $sum['eligible'], 'registered' => (int) $sum['registered'], 'unregistered' => (int) $sum['eligible'] - (int) $sum['registered'], 'placed' => (int) $sum['placed']]];
}

/**
 * Register students for a drive (creates 'applied' applications).
 * @return array{registered:int, already:int, ineligible:int, errors: array}
 */
function placement_register_students(int $driveId, array $studentIds, bool $allEligible = false, bool $override = false): array
{
    $drive = placement_drive_row($driveId);
    if (!$drive) {
        throw new CrudException('Placement drive not found.');
    }
    if (in_array($drive['status'], ['completed', 'cancelled'], true)) {
        throw new CrudException('Registrations are closed — this drive is ' . $drive['status'] . '.');
    }
    $eligible = array_flip(placement_eligible_ids($drive));
    $ids = $allEligible ? array_keys($eligible) : array_values(array_unique(array_filter(array_map('intval', $studentIds))));
    if (!$ids) {
        throw new CrudException($allEligible ? 'No students currently meet the eligibility criteria of this drive.' : 'Select at least one student to register.');
    }
    $existing = array_flip(array_map('intval', db_column('SELECT student_id FROM placement_applications WHERE drive_id = ?', [$driveId])));
    $out = ['registered' => 0, 'already' => 0, 'ineligible' => 0, 'errors' => []];
    $rows = [];
    $now = date('Y-m-d H:i:s');
    foreach ($ids as $sid) {
        if (isset($existing[$sid])) {
            $out['already']++;
            continue;
        }
        if (!isset($eligible[$sid])) {
            $reason = placement_ineligibility_reason($drive, $sid);
            if (!$override || $reason === 'Student not found.' || str_starts_with((string) $reason, 'Only active')) {
                $out['ineligible']++;
                if (count($out['errors']) < 20) {
                    $out['errors'][] = ['student_id' => $sid, 'message' => $reason ?? 'Not eligible.'];
                }
                continue;
            }
        }
        $rows[] = [$driveId, $sid, 'applied', $now];
    }
    if ($rows) {
        db_transaction(function () use ($rows) {
            foreach (array_chunk($rows, 200) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?)'));
                db_exec("INSERT INTO placement_applications (drive_id, student_id, status, applied_at) VALUES $ph", array_merge(...$chunk));
            }
        });
        $out['registered'] = count($rows);
        log_activity('create', 'placement', $driveId, sprintf('Registered %d student(s) for %s — %s', count($rows), $drive['company_name'], $drive['title']));
    }
    return $out;
}

/* ------------------------------------------------------------------
 * Drives
 * ------------------------------------------------------------------ */

function placement_drive_detail(int $id): ?array
{
    $d = placement_drive_row($id);
    if (!$d) {
        return null;
    }
    $programIds = placement_drive_programs($d);
    $programs = $programIds ? db_all('SELECT id, short_name, name FROM programs WHERE id IN (' . implode(',', $programIds) . ') ORDER BY sort_order, name') : [];
    $byStatus = db_pairs('SELECT status, COUNT(*) FROM placement_applications WHERE drive_id = ? GROUP BY status', [$id]);
    $applied = array_sum(array_map('intval', $byStatus));
    $rounds = placement_drive_rounds($d['rounds']);
    $roundStats = [];
    foreach ($rounds as $r) {
        $st = db_row("SELECT COUNT(*) AS appeared, SUM(i.result = 'cleared') AS cleared, SUM(i.result IN ('failed', 'absent')) AS dropped, SUM(i.result = 'pending') AS pending
                      FROM placement_interviews i JOIN placement_applications a ON a.id = i.application_id WHERE a.drive_id = ? AND i.round_name = ?", [$id, $r]);
        $roundStats[] = ['round' => $r, 'stage' => placement_round_stage($r), 'appeared' => (int) $st['appeared'], 'cleared' => (int) $st['cleared'], 'dropped' => (int) $st['dropped'], 'pending' => (int) $st['pending']];
    }
    $offers = db_row('SELECT COUNT(*) AS total, SUM(status IN ' . PLACEMENT_VALID_OFFER_SQL . ") AS valid, SUM(status IN ('accepted', 'joined')) AS confirmed,
                             MAX(CASE WHEN status IN " . PLACEMENT_VALID_OFFER_SQL . ' THEN package_lpa END) AS highest,
                             ROUND(AVG(CASE WHEN status IN ' . PLACEMENT_VALID_OFFER_SQL . ' THEN package_lpa END), 2) AS average
                      FROM placement_offers WHERE drive_id = ?', [$id]);
    $eligible = count(placement_eligible_ids($d));
    $d['id'] = (int) $d['id'];
    $d['eligible_programs'] = $programIds;
    $d['session'] = placement_session_for_date($d['drive_date'])['name'] ?? null;
    return [
        'drive' => $d,
        'programs' => $programs,
        'rounds' => $rounds,
        'stats' => [
            'eligible' => $eligible, 'applied' => $applied, 'by_status' => array_map('intval', $byStatus),
            'in_process' => (int) ($byStatus['shortlisted'] ?? 0) + (int) ($byStatus['aptitude'] ?? 0) + (int) ($byStatus['technical'] ?? 0) + (int) ($byStatus['hr'] ?? 0),
            'selected' => (int) ($byStatus['selected'] ?? 0), 'rejected' => (int) ($byStatus['rejected'] ?? 0),
            'offers' => (int) $offers['valid'], 'offers_total' => (int) $offers['total'], 'confirmed' => (int) $offers['confirmed'],
            'highest' => $offers['highest'] !== null ? (float) $offers['highest'] : null, 'average' => $offers['average'] !== null ? (float) $offers['average'] : null,
            'registration_rate' => $eligible ? round($applied / $eligible * 100, 1) : 0,
        ],
        'round_stats' => $roundStats,
        'can_register' => !in_array($d['status'], ['completed', 'cancelled'], true),
    ];
}

/* ------------------------------------------------------------------
 * Applications & interview rounds
 * ------------------------------------------------------------------ */

function placement_application_row(int $id): ?array
{
    return db_row('SELECT a.*, d.title AS drive_title, d.job_role, d.rounds, d.drive_date, d.status AS drive_status, d.company_id, d.package_min, d.package_max, d.location AS drive_location,
                          c.name AS company_name, c.logo AS company_logo, ' . placement_student_name_sql() . ' AS student_name, s.student_uid, s.photo AS student_photo
                   FROM placement_applications a JOIN placement_drives d ON d.id = a.drive_id JOIN companies c ON c.id = d.company_id JOIN students s ON s.id = a.student_id
                   WHERE a.id = ?', [$id]);
}

function placement_application_detail(int $id): ?array
{
    $a = placement_application_row($id);
    if (!$a) {
        return null;
    }
    $student = db_row('SELECT s.id, ' . placement_student_name_sql() . ' AS name, s.student_uid, s.roll_no, s.photo, s.email, s.mobile, s.gender, s.current_semester, s.previous_percentage,
                              p.short_name AS program_short, p.name AS program_name, d.name AS department_name, b.name AS batch_name, '
        . placement_cgpa_sql() . ' AS cgpa, ' . placement_backlog_sql() . ' AS backlogs
                       FROM students s JOIN programs p ON p.id = s.program_id LEFT JOIN departments d ON d.id = s.department_id LEFT JOIN batches b ON b.id = s.batch_id WHERE s.id = ?', [(int) $a['student_id']]);
    $interviews = db_all('SELECT id, round_name, scheduled_at, mode, panel, result, score, feedback, created_at FROM placement_interviews WHERE application_id = ?
                          ORDER BY COALESCE(scheduled_at, created_at), id', [$id]);
    foreach ($interviews as &$i) {
        $i['id'] = (int) $i['id'];
        $i['score'] = $i['score'] !== null ? (float) $i['score'] : null;
    }
    unset($i);
    $offer = db_row('SELECT id, job_role, package_lpa, offer_date, joining_date, location, status, offer_letter FROM placement_offers
                     WHERE application_id = ? OR (drive_id = ? AND student_id = ?) ORDER BY id DESC LIMIT 1', [$id, (int) $a['drive_id'], (int) $a['student_id']]);
    $otherOffers = db_all('SELECT o.id, c.name AS company_name, o.package_lpa, o.status FROM placement_offers o JOIN companies c ON c.id = o.company_id
                           WHERE o.student_id = ? AND (o.drive_id IS NULL OR o.drive_id <> ?) ORDER BY o.offer_date DESC', [(int) $a['student_id'], (int) $a['drive_id']]);
    $rounds = placement_drive_rounds($a['rounds']);
    $a['id'] = (int) $a['id'];
    return ['application' => $a, 'student' => $student, 'rounds' => $rounds, 'interviews' => $interviews, 'offer' => $offer, 'other_offers' => $otherOffers,
        'next_round' => placement_next_round($rounds, $interviews, $a)];
}

/** The round a student should face next (first round not yet cleared). */
function placement_next_round(array $rounds, array $interviews, array $app): ?string
{
    if (in_array($app['status'], ['selected', 'rejected', 'withdrawn'], true)) {
        return null;
    }
    $cleared = [];
    foreach ($interviews as $i) {
        if ($i['result'] === 'cleared') {
            $cleared[mb_strtolower($i['round_name'])] = true;
        }
    }
    foreach ($rounds as $r) {
        if (!isset($cleared[mb_strtolower($r)])) {
            return $r;
        }
    }
    return $rounds ? null : ($app['current_round'] ?: 'Interview');
}

/** Update an application's status / current round after a round result. */
function placement_apply_round_result(int $appId, string $round, string $result): void
{
    $a = db_row('SELECT a.id, a.status, d.rounds FROM placement_applications a JOIN placement_drives d ON d.id = a.drive_id WHERE a.id = ?', [$appId]);
    if (!$a || $a['status'] === 'withdrawn') {
        return;
    }
    $rounds = placement_drive_rounds($a['rounds']);
    if (in_array($result, ['failed', 'absent'], true)) {
        $status = 'rejected';
        $current = $round;
    } elseif ($result === 'pending') {
        $status = placement_round_stage($round);
        $current = $round;
    } else {
        $idx = null;
        foreach ($rounds as $i => $r) {
            if (mb_strtolower($r) === mb_strtolower($round)) {
                $idx = $i;
            }
        }
        $next = $idx !== null ? ($rounds[$idx + 1] ?? null) : null;
        if ($idx === null && $rounds) {
            // an extra round outside the drive's plan: keep the student in process
            $status = placement_round_stage($round);
            $current = $round;
        } elseif ($next !== null) {
            $status = placement_round_stage($next);
            $current = $next;
        } else {
            $status = 'selected';
            $current = $round;
        }
    }
    db_update('placement_applications', ['status' => $status, 'current_round' => mb_substr($current, 0, 60)], 'id = ?', [$appId]);
}

/**
 * Re-derive an application's state from its interviews (after an interview is edited / deleted). The deciding
 * interview is the one for the furthest round of the drive's plan (then the latest by date), so an out-of-order
 * date on an earlier round cannot roll the application back.
 */
function placement_resync_application(int $appId): void
{
    $app = db_row('SELECT a.id, a.status, d.rounds FROM placement_applications a JOIN placement_drives d ON d.id = a.drive_id WHERE a.id = ?', [$appId]);
    if (!$app || $app['status'] === 'withdrawn') {
        return;
    }
    $order = array_flip(array_map('mb_strtolower', placement_drive_rounds($app['rounds'])));
    $interviews = db_all('SELECT id, round_name, result, COALESCE(scheduled_at, created_at) AS at FROM placement_interviews WHERE application_id = ?', [$appId]);
    if (!$interviews) {
        // every round was removed: back to the start of the process (manual selections without rounds are kept)
        if (in_array($app['status'], ['aptitude', 'technical', 'hr'], true)) {
            db_update('placement_applications', ['status' => 'shortlisted', 'current_round' => placement_drive_rounds($app['rounds'])[0] ?? null], 'id = ?', [$appId]);
        }
        return;
    }
    usort($interviews, function ($a, $b) use ($order) {
        $ia = $order[mb_strtolower($a['round_name'])] ?? -1;
        $ib = $order[mb_strtolower($b['round_name'])] ?? -1;
        return [$ia, $a['at'], (int) $a['id']] <=> [$ib, $b['at'], (int) $b['id']];
    });
    $last = end($interviews);
    placement_apply_round_result($appId, $last['round_name'], $last['result']);
}

/** Validate + record an interview round for an application. */
function placement_record_round(int $appId, array $in): array
{
    $a = placement_application_row($appId);
    if (!$a) {
        throw new CrudException('Application not found.');
    }
    if ($a['status'] === 'withdrawn') {
        throw new CrudException('This application was withdrawn. Reopen it before recording rounds.');
    }
    if ((int) db_value('SELECT COUNT(*) FROM placement_offers WHERE (application_id = ? OR (drive_id = ? AND student_id = ?)) AND status IN ' . PLACEMENT_VALID_OFFER_SQL,
        [$appId, (int) $a['drive_id'], (int) $a['student_id']])) {
        throw new CrudException('The student already holds an offer from this drive — no further rounds can be recorded.');
    }
    $errors = validate($in, [
        'round_name' => 'required|max:60',
        'scheduled_at' => 'required',
        'mode' => 'in:in_person,online,telephonic',
        'result' => 'required|in:pending,cleared,failed,absent',
        'score' => 'numeric|min:0|max:100',
        'panel' => 'max:255',
        'feedback' => 'max:500',
    ], ['round_name' => 'Round', 'scheduled_at' => 'Date & time', 'score' => 'Score']);
    $ts = !empty($in['scheduled_at']) ? strtotime(str_replace('T', ' ', (string) $in['scheduled_at'])) : false;
    if (!isset($errors['scheduled_at']) && !$ts) {
        $errors['scheduled_at'] = 'Enter a valid date & time.';
    }
    if ($ts && ($in['result'] ?? '') !== 'pending' && $ts > time() + 3600) {
        $errors['result'] = 'A future round can only be recorded as Pending (scheduled).';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $round = trim((string) $in['round_name']);
    $data = [
        'application_id' => $appId, 'round_name' => mb_substr($round, 0, 60), 'scheduled_at' => date('Y-m-d H:i:s', $ts),
        'mode' => ($in['mode'] ?? '') ?: null, 'panel' => trim((string) ($in['panel'] ?? '')) ?: null, 'result' => $in['result'],
        'score' => ($in['score'] ?? '') === '' || !isset($in['score']) ? null : round((float) $in['score'], 2), 'feedback' => trim((string) ($in['feedback'] ?? '')) ?: null,
    ];
    $id = db_transaction(function () use ($appId, $data, $round) {
        // Re-recording a pending round updates it instead of adding a duplicate.
        $pending = db_value("SELECT id FROM placement_interviews WHERE application_id = ? AND round_name = ? AND result = 'pending' ORDER BY id DESC LIMIT 1", [$appId, $round]);
        if ($pending) {
            db_update('placement_interviews', $data, 'id = ?', [(int) $pending]);
            $iid = (int) $pending;
        } else {
            $iid = db_insert('placement_interviews', $data);
        }
        placement_apply_round_result($appId, $round, $data['result']);
        return $iid;
    });
    $after = db_row('SELECT status, current_round FROM placement_applications WHERE id = ?', [$appId]);
    log_activity('update', 'placement', $appId, sprintf('Recorded %s (%s) for %s — %s', $round, $data['result'], $a['student_name'], $a['company_name']));
    if (($after['status'] ?? '') === 'selected' && $a['status'] !== 'selected') {
        notify('perm:placement', 'placement', 'Student selected', $a['student_name'] . ' cleared all rounds of ' . $a['company_name'] . ' (' . $a['job_role'] . ').', 'placement/applications?f.drive_id=' . $a['drive_id'], 'badge-check');
    }
    return ['interview_id' => (int) $id, 'status' => $after['status'] ?? null, 'current_round' => $after['current_round'] ?? null];
}

/**
 * Bulk workflow on applications.
 *   shortlist | advance (current round cleared → next round / selected) | select | reject | hold | withdraw | reopen
 */
function placement_bulk_applications(string $action, array $ids, array $opts = []): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        throw new CrudException('Select at least one application.');
    }
    if (!in_array($action, ['shortlist', 'advance', 'select', 'reject', 'hold', 'withdraw', 'reopen'], true)) {
        throw new CrudException('Unknown action.');
    }
    $apps = db_all('SELECT a.id, a.status, a.current_round, a.drive_id, d.rounds, c.name AS company_name FROM placement_applications a
                    JOIN placement_drives d ON d.id = a.drive_id JOIN companies c ON c.id = d.company_id WHERE a.id IN (' . implode(',', $ids) . ')');
    $when = !empty($opts['date']) && strtotime((string) $opts['date']) ? date('Y-m-d H:i:s', strtotime((string) $opts['date'])) : date('Y-m-d H:i:s');
    $remarks = mb_substr(trim((string) ($opts['remarks'] ?? '')), 0, 255) ?: null;
    $done = 0;
    $skipped = 0;
    $selected = 0;
    $closed = ['selected', 'rejected', 'withdrawn'];
    db_transaction(function () use ($apps, $action, $when, $remarks, $closed, &$done, &$skipped, &$selected) {
        foreach ($apps as $a) {
            $id = (int) $a['id'];
            $rounds = placement_drive_rounds($a['rounds']);
            switch ($action) {
                case 'shortlist':
                    if (in_array($a['status'], $closed, true) || $a['status'] === 'shortlisted') {
                        $skipped++;
                        continue 2;
                    }
                    db_update('placement_applications', ['status' => 'shortlisted', 'current_round' => $a['current_round'] ?: ($rounds[0] ?? null)] + ($remarks ? ['remarks' => $remarks] : []), 'id = ?', [$id]);
                    break;
                case 'advance':
                    if (in_array($a['status'], $closed, true)) {
                        $skipped++;
                        continue 2;
                    }
                    $round = $a['current_round'] ?: ($rounds[0] ?? 'Interview');
                    $pending = db_value("SELECT id FROM placement_interviews WHERE application_id = ? AND round_name = ? AND result = 'pending' ORDER BY id DESC LIMIT 1", [$id, $round]);
                    if ($pending) {
                        // a round scheduled for later but cleared now is dated when it was actually held
                        db_exec("UPDATE placement_interviews SET result = 'cleared', feedback = COALESCE(?, feedback),
                                        scheduled_at = CASE WHEN scheduled_at IS NULL OR scheduled_at > ? THEN ? ELSE scheduled_at END WHERE id = ?",
                            [$remarks, $when, $when, (int) $pending]);
                    } elseif (!db_value("SELECT COUNT(*) FROM placement_interviews WHERE application_id = ? AND round_name = ? AND result = 'cleared'", [$id, $round])) {
                        db_insert('placement_interviews', ['application_id' => $id, 'round_name' => mb_substr($round, 0, 60), 'scheduled_at' => $when, 'result' => 'cleared', 'feedback' => $remarks]);
                    }
                    placement_apply_round_result($id, $round, 'cleared');
                    if (db_value('SELECT status FROM placement_applications WHERE id = ?', [$id]) === 'selected') {
                        $selected++;
                    }
                    break;
                case 'select':
                    if ($a['status'] === 'selected' || $a['status'] === 'withdrawn') {
                        $skipped++;
                        continue 2;
                    }
                    db_update('placement_applications', ['status' => 'selected', 'current_round' => $rounds ? end($rounds) : $a['current_round']] + ($remarks ? ['remarks' => $remarks] : []), 'id = ?', [$id]);
                    $selected++;
                    break;
                case 'reject':
                    if (in_array($a['status'], ['rejected', 'withdrawn'], true) || db_value('SELECT COUNT(*) FROM placement_offers WHERE application_id = ? AND status IN ' . PLACEMENT_VALID_OFFER_SQL, [$id])) {
                        $skipped++;
                        continue 2;
                    }
                    db_update('placement_applications', ['status' => 'rejected'] + ($remarks ? ['remarks' => $remarks] : []), 'id = ?', [$id]);
                    break;
                case 'hold':
                    if (in_array($a['status'], $closed, true) || $a['status'] === 'on_hold') {
                        $skipped++;
                        continue 2;
                    }
                    db_update('placement_applications', ['status' => 'on_hold'] + ($remarks ? ['remarks' => $remarks] : []), 'id = ?', [$id]);
                    break;
                case 'withdraw':
                    if ($a['status'] === 'withdrawn' || $a['status'] === 'selected') {
                        $skipped++;
                        continue 2;
                    }
                    db_update('placement_applications', ['status' => 'withdrawn'] + ($remarks ? ['remarks' => $remarks] : []), 'id = ?', [$id]);
                    break;
                case 'reopen':
                    if (!in_array($a['status'], ['rejected', 'withdrawn', 'on_hold'], true)) {
                        $skipped++;
                        continue 2;
                    }
                    $stage = $a['current_round'] ? placement_round_stage($a['current_round']) : 'applied';
                    db_update('placement_applications', ['status' => $stage] + ($remarks ? ['remarks' => $remarks] : []), 'id = ?', [$id]);
                    break;
            }
            $done++;
        }
    });
    $verbs = ['shortlist' => 'Shortlisted', 'advance' => 'Moved to the next round', 'select' => 'Selected', 'reject' => 'Rejected', 'hold' => 'Put on hold', 'withdraw' => 'Withdrew', 'reopen' => 'Reopened'];
    if ($done) {
        $companies = implode(', ', array_slice(array_unique(array_column($apps, 'company_name')), 0, 3));
        log_activity('update', 'placement', implode(',', array_slice($ids, 0, 20)), sprintf('%s %d placement application(s) — %s', $verbs[$action], $done, $companies));
        if ($selected) {
            notify('perm:placement', 'placement', 'Students selected', sprintf('%d student(s) selected by %s. Record their offers to mark them placed.', $selected, $companies), 'placement/applications?f.status=selected', 'badge-check');
        }
    }
    $msg = !$done ? 'No applications were changed.' : ($action === 'advance'
        ? sprintf('Moved %d application%s to the next round.', $done, $done === 1 ? '' : 's')
        : sprintf('%s %d application%s.', $verbs[$action], $done, $done === 1 ? '' : 's'));
    if ($action === 'advance' && $selected) {
        $msg .= sprintf(' %d cleared the final round and %s selected.', $selected, $selected === 1 ? 'is' : 'are');
    }
    if ($skipped) {
        $msg .= sprintf(' %d skipped (already in that state or closed).', $skipped);
    }
    return ['updated' => $done, 'skipped' => $skipped, 'selected' => $selected, 'message' => $msg];
}

/* ------------------------------------------------------------------
 * Offers
 * ------------------------------------------------------------------ */

/** Allowed offer status transitions. */
function placement_offer_transitions(): array
{
    return ['offered' => ['accepted', 'declined', 'revoked'], 'accepted' => ['joined', 'declined', 'revoked'], 'declined' => ['offered'], 'joined' => [], 'revoked' => ['offered']];
}

function placement_set_offer_status(int $id, string $status, ?string $remarks = null, ?string $date = null): array
{
    $o = db_row('SELECT o.*, c.name AS company_name, ' . placement_student_name_sql() . ' AS student_name FROM placement_offers o JOIN companies c ON c.id = o.company_id
                 JOIN students s ON s.id = o.student_id WHERE o.id = ?', [$id]);
    if (!$o) {
        throw new CrudException('Offer not found.');
    }
    if (!isset(placement_offer_statuses()[$status])) {
        throw new CrudValidationException(['status' => 'Select a valid status.']);
    }
    if ($o['status'] === $status) {
        throw new CrudException('The offer is already ' . $status . '.');
    }
    if (!in_array($status, placement_offer_transitions()[$o['status']] ?? [], true)) {
        throw new CrudException(sprintf('An offer that is %s cannot be marked %s.', $o['status'], $status));
    }
    $date = $date && DateTime::createFromFormat('Y-m-d', $date) ? $date : date('Y-m-d');
    if ($status === 'joined' && $o['joining_date'] === null) {
        $o['joining_date'] = $date;
    }
    $data = ['status' => $status];
    if (in_array($status, ['accepted', 'declined'], true)) {
        $data['responded_at'] = $date;
    }
    if ($status === 'joined') {
        $data['joining_date'] = $o['joining_date'];
    }
    if ($remarks !== null && trim($remarks) !== '') {
        $data['remarks'] = mb_substr(trim($remarks), 0, 255);
    }
    db_transaction(function () use ($id, $data, $o, $status) {
        db_update('placement_offers', $data, 'id = ?', [$id]);
        if ($o['application_id'] && in_array($status, ['accepted', 'joined', 'offered'], true)) {
            db_update('placement_applications', ['status' => 'selected'], 'id = ?', [(int) $o['application_id']]);
        }
    });
    $label = ['accepted' => 'Marked placed (offer accepted)', 'joined' => 'Marked joined', 'declined' => 'Offer declined', 'revoked' => 'Offer revoked', 'offered' => 'Offer reinstated'][$status];
    log_activity('update', 'placement', $id, sprintf('%s: %s — %s (%s LPA)', $label, $o['student_name'], $o['company_name'], rtrim(rtrim(number_format((float) $o['package_lpa'], 2), '0'), '.')));
    if ($status === 'accepted') {
        notify('perm:placement', 'placement', 'Student placed', $o['student_name'] . ' accepted the offer from ' . $o['company_name'] . ' (' . rtrim(rtrim(number_format((float) $o['package_lpa'], 2), '0'), '.') . ' LPA).', 'placement/offers', 'party-popper');
    }
    return db_row('SELECT * FROM placement_offers WHERE id = ?', [$id]) ?? [];
}

/* ------------------------------------------------------------------
 * Companies
 * ------------------------------------------------------------------ */

function placement_unique_slug(string $name, ?int $ignoreId = null): string
{
    $base = slugify($name) ?: 'company';
    $slug = $base;
    $n = 2;
    while ((int) db_value('SELECT COUNT(*) FROM companies WHERE slug = ?' . ($ignoreId ? ' AND id <> ?' : ''), $ignoreId ? [$slug, $ignoreId] : [$slug])) {
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

function placement_company_history(int $id): ?array
{
    $c = db_row('SELECT * FROM companies WHERE id = ?', [$id]);
    if (!$c) {
        return null;
    }
    $stats = db_row('SELECT COUNT(*) AS offers, COUNT(DISTINCT student_id) AS students, MAX(package_lpa) AS highest, ROUND(AVG(package_lpa), 2) AS average, MAX(offer_date) AS last_offer
                     FROM placement_offers WHERE company_id = ? AND status IN ' . PLACEMENT_VALID_OFFER_SQL, [$id]);
    $drives = db_all("SELECT d.id, d.title, d.job_role, d.job_type, d.drive_date, d.status, d.package_min, d.package_max, d.mode,
                             (SELECT COUNT(*) FROM placement_applications a WHERE a.drive_id = d.id) AS applicants,
                             (SELECT COUNT(*) FROM placement_applications a WHERE a.drive_id = d.id AND a.status = 'selected') AS selected,
                             (SELECT COUNT(*) FROM placement_offers o WHERE o.drive_id = d.id AND o.status IN " . PLACEMENT_VALID_OFFER_SQL . ') AS offers
                      FROM placement_drives d WHERE d.company_id = ? ORDER BY COALESCE(d.drive_date, d.created_at) DESC, d.id DESC', [$id]);
    foreach ($drives as &$d) {
        foreach (['id', 'applicants', 'selected', 'offers'] as $k) {
            $d[$k] = (int) $d[$k];
        }
    }
    unset($d);
    $bySession = db_all('SELECT ss.name AS session, COUNT(*) AS offers, MAX(o.package_lpa) AS highest FROM placement_offers o
                         JOIN academic_sessions ss ON o.offer_date BETWEEN ss.start_date AND ss.end_date
                         WHERE o.company_id = ? AND o.status IN ' . PLACEMENT_VALID_OFFER_SQL . ' GROUP BY ss.id, ss.name ORDER BY ss.start_date', [$id]);
    $offers = db_all('SELECT o.id, o.job_role, o.package_lpa, o.offer_date, o.status, ' . placement_student_name_sql() . ' AS student_name, s.student_uid, s.photo, p.short_name AS program_short
                      FROM placement_offers o JOIN students s ON s.id = o.student_id JOIN programs p ON p.id = s.program_id
                      WHERE o.company_id = ? ORDER BY o.offer_date DESC, o.id DESC LIMIT 12', [$id]);
    return [
        'company' => $c,
        'stats' => ['drives' => count($drives), 'offers' => (int) $stats['offers'], 'students' => (int) $stats['students'],
            'highest' => $stats['highest'] !== null ? (float) $stats['highest'] : null, 'average' => $stats['average'] !== null ? (float) $stats['average'] : null,
            'last_offer' => $stats['last_offer'], 'last_drive' => $drives[0]['drive_date'] ?? null],
        'drives' => $drives,
        'by_session' => array_map(fn ($r) => ['session' => $r['session'], 'offers' => (int) $r['offers'], 'highest' => (float) $r['highest']], $bySession),
        'offers' => $offers,
    ];
}

/* ------------------------------------------------------------------
 * Trainings & mock tests
 * ------------------------------------------------------------------ */

function placement_training_types(): array
{
    return ['training' => 'Training', 'mock_test' => 'Mock test', 'mock_interview' => 'Mock interview', 'workshop' => 'Workshop', 'seminar' => 'Seminar', 'certification' => 'Certification'];
}

function placement_refresh_training_count(int $tid): void
{
    db_exec('UPDATE placement_trainings SET participants_count = (SELECT COUNT(*) FROM placement_training_participants WHERE training_id = ?) WHERE id = ?', [$tid, $tid]);
}

function placement_training_participants(int $tid, array $req): array
{
    $where = ['tp.training_id = ?'];
    $args = [$tid];
    $q = trim((string) ($req['q'] ?? ''));
    if ($q !== '') {
        foreach (preg_split('/\s+/', mb_substr($q, 0, 80)) as $word) {
            $where[] = '(s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_uid LIKE ?)';
            array_push($args, "%$word%", "%$word%", "%$word%");
        }
    }
    if (($req['attended'] ?? '') !== '' && isset($req['attended'])) {
        $where[] = 'tp.attended = ?';
        $args[] = (int) $req['attended'] ? 1 : 0;
    }
    $from = ' FROM placement_training_participants tp JOIN students s ON s.id = tp.student_id JOIN programs p ON p.id = s.program_id WHERE ' . implode(' AND ', $where);
    $total = (int) db_value('SELECT COUNT(*)' . $from, $args);
    $p = paginate($total, max(1, (int) ($req['page'] ?? 1)), max(5, min(200, (int) ($req['per_page'] ?? 50))));
    $rows = db_all('SELECT tp.id, tp.student_id, tp.attended, tp.score, tp.remarks, ' . placement_student_name_sql() . ' AS name, s.student_uid, s.photo, p.short_name AS program_short, s.current_semester'
        . $from . " ORDER BY s.first_name, s.last_name LIMIT {$p['per_page']} OFFSET {$p['offset']}", $args);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['student_id'] = (int) $r['student_id'];
        $r['attended'] = (bool) $r['attended'];
        $r['score'] = $r['score'] !== null ? (float) $r['score'] : null;
    }
    unset($r);
    $sum = db_row('SELECT COUNT(*) AS total, SUM(attended) AS attended, ROUND(AVG(score), 2) AS avg_score, MAX(score) AS top_score, SUM(score IS NOT NULL) AS scored
                   FROM placement_training_participants WHERE training_id = ?', [$tid]);
    return ['rows' => $rows, 'total' => $p['total'], 'page' => $p['page'], 'per_page' => $p['per_page'], 'pages' => $p['pages'], 'from' => $p['from'], 'to' => $p['to'],
        'summary' => ['total' => (int) $sum['total'], 'attended' => (int) $sum['attended'], 'scored' => (int) $sum['scored'],
            'avg_score' => $sum['avg_score'] !== null ? (float) $sum['avg_score'] : null, 'top_score' => $sum['top_score'] !== null ? (float) $sum['top_score'] : null]];
}

/** Add participants by explicit ids and/or a whole program + semester. */
function placement_add_participants(int $tid, array $studentIds, ?int $programId = null, ?int $semester = null): array
{
    $t = db_row('SELECT * FROM placement_trainings WHERE id = ?', [$tid]);
    if (!$t) {
        throw new CrudException('Training not found.');
    }
    if ($t['status'] === 'cancelled') {
        throw new CrudException('This session is cancelled — participants cannot be added.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
    if ($programId) {
        $sql = "SELECT id FROM students WHERE status = 'active' AND program_id = ?";
        $args = [$programId];
        if ($semester) {
            $sql .= ' AND current_semester = ?';
            $args[] = $semester;
        }
        $ids = array_values(array_unique(array_merge($ids, array_map('intval', db_column($sql, $args)))));
    }
    if (!$ids) {
        throw new CrudValidationException(['student_ids' => 'Choose students or a program to add.']);
    }
    $valid = array_map('intval', db_column("SELECT id FROM students WHERE status = 'active' AND id IN (" . implode(',', $ids) . ')'));
    $existing = array_flip(array_map('intval', db_column('SELECT student_id FROM placement_training_participants WHERE training_id = ?', [$tid])));
    $new = array_values(array_filter($valid, fn ($id) => !isset($existing[$id])));
    if ($new) {
        db_transaction(function () use ($tid, $new) {
            foreach (array_chunk($new, 200) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '(?, ?, 0)'));
                $args = [];
                foreach ($chunk as $sid) {
                    array_push($args, $tid, $sid);
                }
                db_exec("INSERT INTO placement_training_participants (training_id, student_id, attended) VALUES $ph", $args);
            }
            placement_refresh_training_count($tid);
        });
        log_activity('update', 'placement', $tid, sprintf('Added %d participant(s) to "%s"', count($new), $t['title']));
    }
    return ['added' => count($new), 'already' => count($valid) - count($new), 'invalid' => count($ids) - count($valid)];
}

/** Save attendance / scores: rows = [{id, attended, score, remarks}] */
function placement_save_scores(int $tid, array $rows): int
{
    $t = db_row('SELECT * FROM placement_trainings WHERE id = ?', [$tid]);
    if (!$t) {
        throw new CrudException('Training not found.');
    }
    $max = $t['max_marks'] !== null ? (float) $t['max_marks'] : 100.0;
    $errors = [];
    $clean = [];
    foreach ($rows as $i => $r) {
        $pid = (int) ($r['id'] ?? 0);
        if (!$pid) {
            continue;
        }
        $score = $r['score'] ?? null;
        if ($score === '' || $score === null) {
            $score = null;
        } elseif (!is_numeric($score) || (float) $score < 0 || (float) $score > $max) {
            $errors['score_' . $pid] = 'Score must be between 0 and ' . rtrim(rtrim(number_format($max, 2), '0'), '.') . '.';
            continue;
        } else {
            $score = round((float) $score, 2);
        }
        $attended = in_array($r['attended'] ?? false, [true, 1, '1', 'true', 'on'], true) || $score !== null ? 1 : 0;
        $clean[$pid] = ['attended' => $attended, 'score' => $score, 'remarks' => mb_substr(trim((string) ($r['remarks'] ?? '')), 0, 255) ?: null];
    }
    if ($errors) {
        throw new CrudValidationException($errors, 'Some scores are out of range. Please correct them and save again.');
    }
    $n = 0;
    db_transaction(function () use ($tid, $clean, &$n) {
        foreach ($clean as $pid => $data) {
            $n += db_update('placement_training_participants', $data, 'id = ? AND training_id = ?', [$pid, $tid]);
        }
    });
    log_activity('update', 'placement', $tid, sprintf('Updated attendance / scores of %d participant(s) for "%s"', count($clean), $t['title']));
    return count($clean);
}

/* ------------------------------------------------------------------
 * Dashboard & report
 * ------------------------------------------------------------------ */

function placement_dashboard(?int $sessionId = null): array
{
    $ses = placement_session($sessionId);
    $from = $ses['start_date'];
    $to = $ses['end_date'];
    $today = date('Y-m-d');
    $valid = PLACEMENT_VALID_OFFER_SQL;

    $drives = db_all("SELECT d.*, c.name AS company_name, c.logo AS company_logo FROM placement_drives d JOIN companies c ON c.id = d.company_id
                      WHERE d.status <> 'cancelled' AND COALESCE(d.drive_date, d.registration_deadline, DATE(d.created_at)) BETWEEN ? AND ?", [$from, $to]);
    $eligibleIds = [];
    foreach ($drives as $d) {
        foreach (placement_eligible_ids($d) as $sid) {
            $eligibleIds[$sid] = true;
        }
    }
    $offerRows = db_all("SELECT o.student_id, o.company_id, o.package_lpa, o.offer_date, o.status, s.department_id, s.program_id FROM placement_offers o
                         JOIN students s ON s.id = o.student_id WHERE o.status IN $valid AND o.offer_date BETWEEN ? AND ?", [$from, $to]);
    $placed = [];
    $confirmed = [];
    $packages = [];
    foreach ($offerRows as $o) {
        $placed[(int) $o['student_id']] = true;
        if (in_array($o['status'], ['accepted', 'joined'], true)) {
            $confirmed[(int) $o['student_id']] = true;
        }
        $packages[] = (float) $o['package_lpa'];
    }
    $pool = $eligibleIds + $placed;
    sort($packages);
    $n = count($packages);
    $median = $n ? ($n % 2 ? $packages[intdiv($n, 2)] : ($packages[$n / 2 - 1] + $packages[$n / 2]) / 2) : null;
    $top = db_row("SELECT o.package_lpa, c.name AS company_name, " . placement_student_name_sql() . " AS student_name FROM placement_offers o JOIN companies c ON c.id = o.company_id
                   JOIN students s ON s.id = o.student_id WHERE o.status IN $valid AND o.offer_date BETWEEN ? AND ? ORDER BY o.package_lpa DESC, o.offer_date LIMIT 1", [$from, $to]);

    // Department-wise placement %
    $deptStudents = [];
    if ($pool) {
        foreach (array_chunk(array_keys($pool), 500) as $chunk) {
            foreach (db_all('SELECT s.id, s.department_id FROM students s WHERE s.id IN (' . implode(',', $chunk) . ')') as $r) {
                $deptStudents[(int) $r['id']] = (int) $r['department_id'];
            }
        }
    }
    $departments = db_all('SELECT id, name, code FROM departments ORDER BY name');
    $deptRows = [];
    foreach ($departments as $dep) {
        $did = (int) $dep['id'];
        $elig = 0;
        $pl = 0;
        foreach ($deptStudents as $sid => $sd) {
            if ($sd === $did) {
                $elig++;
                if (isset($placed[$sid])) {
                    $pl++;
                }
            }
        }
        if ($elig) {
            $pkgs = array_map(fn ($o) => (float) $o['package_lpa'], array_filter($offerRows, fn ($o) => (int) $o['department_id'] === $did));
            $deptRows[] = ['id' => $did, 'label' => $dep['code'] ?: $dep['name'], 'name' => $dep['name'], 'eligible' => $elig, 'placed' => $pl,
                'percent' => round($pl / $elig * 100, 1), 'average' => $pkgs ? round(array_sum($pkgs) / count($pkgs), 2) : null, 'highest' => $pkgs ? max($pkgs) : null];
        }
    }
    usort($deptRows, fn ($a, $b) => $b['percent'] <=> $a['percent']);

    // Package distribution
    $bands = [['< 3 LPA', 0, 3], ['3–5 LPA', 3, 5], ['5–8 LPA', 5, 8], ['8–12 LPA', 8, 12], ['12–20 LPA', 12, 20], ['20+ LPA', 20, 10000]];
    $distribution = array_map(fn ($b) => ['label' => $b[0], 'count' => count(array_filter($packages, fn ($p) => $p >= $b[1] && $p < $b[2]))], $bands);

    // Company-wise hiring
    $companies = db_all("SELECT c.id, c.name AS label, c.logo, COUNT(*) AS offers, COUNT(DISTINCT o.student_id) AS students, MAX(o.package_lpa) AS highest, ROUND(AVG(o.package_lpa), 2) AS average
                         FROM placement_offers o JOIN companies c ON c.id = o.company_id WHERE o.status IN $valid AND o.offer_date BETWEEN ? AND ?
                         GROUP BY c.id, c.name, c.logo ORDER BY offers DESC, highest DESC LIMIT 10", [$from, $to]);

    // Month-wise offers across the session
    $byMonth = db_pairs("SELECT DATE_FORMAT(offer_date, '%Y-%m') AS ym, COUNT(*) FROM placement_offers WHERE status IN $valid AND offer_date BETWEEN ? AND ? GROUP BY ym", [$from, $to]);
    $months = [];
    $cursor = strtotime(date('Y-m-01', strtotime($from)));
    $end = strtotime($to);
    while ($cursor <= $end) {
        $ym = date('Y-m', $cursor);
        $months[] = ['label' => date('M y', $cursor), 'offers' => (int) ($byMonth[$ym] ?? 0), 'future' => $ym > date('Y-m')];
        $cursor = strtotime('+1 month', $cursor);
    }

    // Upcoming drives
    $upcoming = db_all("SELECT d.id, d.title, d.job_role, d.job_type, d.drive_date, d.registration_deadline, d.package_min, d.package_max, d.status, d.mode, d.location,
                               d.eligible_programs, d.batch_year, d.min_cgpa, d.max_backlogs, d.min_percentage,
                               c.name AS company_name, c.logo AS company_logo, (SELECT COUNT(*) FROM placement_applications a WHERE a.drive_id = d.id) AS registered
                        FROM placement_drives d JOIN companies c ON c.id = d.company_id
                        WHERE d.status IN ('upcoming', 'open', 'ongoing') AND (d.drive_date IS NULL OR d.drive_date >= ?)
                        ORDER BY d.drive_date IS NULL, d.drive_date, d.id LIMIT 6", [date('Y-m-d', strtotime('-3 days'))]);
    foreach ($upcoming as &$u) {
        $u['eligible'] = count(placement_eligible_ids($u));
        $u['registered'] = (int) $u['registered'];
        $u['id'] = (int) $u['id'];
        unset($u['eligible_programs'], $u['batch_year'], $u['min_cgpa'], $u['max_backlogs'], $u['min_percentage']);
    }
    unset($u);

    // Top recruiters (all time hires; ranked by session hires first)
    $recruiters = db_all("SELECT c.id, c.name, c.logo, c.industry, c.tie_up_status,
                                 SUM(o.offer_date BETWEEN ? AND ?) AS session_hires, COUNT(*) AS total_hires, MAX(o.package_lpa) AS highest
                          FROM placement_offers o JOIN companies c ON c.id = o.company_id WHERE o.status IN $valid
                          GROUP BY c.id, c.name, c.logo, c.industry, c.tie_up_status ORDER BY session_hires DESC, total_hires DESC LIMIT 6", [$from, $to]);

    $offerStatus = db_pairs('SELECT status, COUNT(*) FROM placement_offers WHERE offer_date BETWEEN ? AND ? GROUP BY status', [$from, $to]);
    $appStatus = db_pairs("SELECT a.status, COUNT(*) FROM placement_applications a JOIN placement_drives d ON d.id = a.drive_id
                           WHERE d.status <> 'cancelled' AND COALESCE(d.drive_date, d.registration_deadline, DATE(d.created_at)) BETWEEN ? AND ? GROUP BY a.status", [$from, $to]);
    $training = db_row("SELECT SUM(start_date BETWEEN ? AND ?) AS session_total, SUM(start_date >= ? AND status IN ('scheduled', 'ongoing')) AS upcoming,
                               (SELECT COUNT(*) FROM placement_training_participants tp JOIN placement_trainings t2 ON t2.id = tp.training_id WHERE t2.start_date BETWEEN ? AND ? AND tp.attended = 1) AS attended
                        FROM placement_trainings", [$from, $to, $today, $from, $to]);
    $nextTraining = db_row("SELECT id, title, type, start_date, trainer FROM placement_trainings WHERE start_date >= ? AND status IN ('scheduled', 'ongoing') ORDER BY start_date LIMIT 1", [$today]);

    $poolCount = count($pool);
    return [
        'session' => $ses,
        'kpis' => [
            'companies' => (int) db_value("SELECT COUNT(*) FROM companies WHERE status = 'active'"),
            'companies_visited' => count(array_unique(array_column($drives, 'company_id'))),
            'mou' => (int) db_value("SELECT COUNT(*) FROM companies WHERE status = 'active' AND tie_up_status = 'mou_signed'"),
            'drives' => count($drives),
            'drives_upcoming' => count(array_filter($drives, fn ($d) => in_array($d['status'], ['upcoming', 'open', 'ongoing'], true))),
            'drives_completed' => count(array_filter($drives, fn ($d) => $d['status'] === 'completed')),
            'eligible' => $poolCount,
            'placed' => count($placed),
            'confirmed' => count($confirmed),
            'placement_percent' => $poolCount ? round(count($placed) / $poolCount * 100, 1) : 0,
            'offers' => $n,
            'highest' => $n ? max($packages) : null,
            'highest_company' => $top['company_name'] ?? null,
            'highest_student' => $top['student_name'] ?? null,
            'average' => $n ? round(array_sum($packages) / $n, 2) : null,
            'median' => $median !== null ? round($median, 2) : null,
        ],
        'departments' => $deptRows,
        'distribution' => $distribution,
        'companies' => array_map(fn ($r) => ['id' => (int) $r['id'], 'label' => $r['label'], 'logo' => $r['logo'], 'offers' => (int) $r['offers'], 'students' => (int) $r['students'],
            'highest' => (float) $r['highest'], 'average' => (float) $r['average']], $companies),
        'months' => $months,
        'upcoming' => $upcoming,
        'recruiters' => array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'logo' => $r['logo'], 'industry' => $r['industry'], 'tie_up_status' => $r['tie_up_status'],
            'session_hires' => (int) $r['session_hires'], 'total_hires' => (int) $r['total_hires'], 'highest' => (float) $r['highest']], $recruiters),
        'offer_status' => array_map('intval', $offerStatus),
        'application_status' => array_map('intval', $appStatus),
        'training' => ['session_total' => (int) ($training['session_total'] ?? 0), 'upcoming' => (int) ($training['upcoming'] ?? 0), 'attended' => (int) ($training['attended'] ?? 0), 'next' => $nextTraining],
    ];
}

/** Full data set for the printable placement report. */
function placement_report(?int $sessionId = null): array
{
    $dash = placement_dashboard($sessionId);
    $ses = $dash['session'];
    $valid = PLACEMENT_VALID_OFFER_SQL;
    $drives = db_all("SELECT d.id, d.title, d.job_role, d.job_type, d.drive_date, d.status, d.package_min, d.package_max, c.name AS company_name,
                             (SELECT COUNT(*) FROM placement_applications a WHERE a.drive_id = d.id) AS applicants,
                             (SELECT COUNT(*) FROM placement_applications a WHERE a.drive_id = d.id AND a.status = 'selected') AS selected,
                             (SELECT COUNT(*) FROM placement_offers o WHERE o.drive_id = d.id AND o.status IN $valid) AS offers
                      FROM placement_drives d JOIN companies c ON c.id = d.company_id
                      WHERE d.status <> 'cancelled' AND COALESCE(d.drive_date, d.registration_deadline, DATE(d.created_at)) BETWEEN ? AND ?
                      ORDER BY d.drive_date, d.id", [$ses['start_date'], $ses['end_date']]);
    $placed = db_all("SELECT s.id, " . placement_student_name_sql() . " AS name, s.student_uid, p.short_name AS program_short, dp.code AS department_code,
                             c.name AS company_name, o.job_role, o.package_lpa, o.offer_date, o.status
                      FROM placement_offers o JOIN students s ON s.id = o.student_id JOIN programs p ON p.id = s.program_id LEFT JOIN departments dp ON dp.id = s.department_id
                      JOIN companies c ON c.id = o.company_id
                      WHERE o.status IN $valid AND o.offer_date BETWEEN ? AND ?
                      ORDER BY p.sort_order, s.first_name, s.last_name, o.package_lpa DESC", [$ses['start_date'], $ses['end_date']]);
    // one line per student: best offer + number of offers
    $students = [];
    foreach ($placed as $r) {
        $sid = (int) $r['id'];
        if (!isset($students[$sid])) {
            $students[$sid] = $r + ['offers' => 0];
        } elseif ((float) $r['package_lpa'] > (float) $students[$sid]['package_lpa']) {
            $students[$sid] = $r + ['offers' => $students[$sid]['offers']];
        }
        $students[$sid]['offers']++;
    }
    $programs = db_all("SELECT p.short_name AS label, COUNT(DISTINCT o.student_id) AS placed, COUNT(*) AS offers, MAX(o.package_lpa) AS highest, ROUND(AVG(o.package_lpa), 2) AS average
                        FROM placement_offers o JOIN students s ON s.id = o.student_id JOIN programs p ON p.id = s.program_id
                        WHERE o.status IN $valid AND o.offer_date BETWEEN ? AND ? GROUP BY p.id, p.short_name ORDER BY p.sort_order", [$ses['start_date'], $ses['end_date']]);
    return $dash + ['drives' => $drives, 'placed_students' => array_values($students), 'programs' => $programs];
}

/* ------------------------------------------------------------------
 * KPI strips for the list pages (current academic session)
 * ------------------------------------------------------------------ */

function placement_page_stats(string $scope): array
{
    $ses = placement_session();
    $from = $ses['start_date'];
    $to = $ses['end_date'];
    $valid = PLACEMENT_VALID_OFFER_SQL;
    $driveInSession = 'COALESCE(d.drive_date, d.registration_deadline, DATE(d.created_at)) BETWEEN ? AND ?';
    switch ($scope) {
        case 'companies':
            $r = db_row("SELECT SUM(status = 'active') AS active, SUM(status = 'active' AND tie_up_status = 'mou_signed') AS mou,
                                SUM(status = 'active' AND tie_up_status = 'prospect') AS prospects, SUM(is_recruiter = 1 AND status = 'active') AS on_website FROM companies");
            return ['session' => $ses['name'], 'active' => (int) $r['active'], 'mou' => (int) $r['mou'], 'prospects' => (int) $r['prospects'], 'on_website' => (int) $r['on_website'],
                'visited' => (int) db_value("SELECT COUNT(DISTINCT d.company_id) FROM placement_drives d WHERE d.status <> 'cancelled' AND $driveInSession", [$from, $to]),
                'hiring' => (int) db_value("SELECT COUNT(DISTINCT company_id) FROM placement_offers WHERE status IN $valid AND offer_date BETWEEN ? AND ?", [$from, $to])];
        case 'drives':
            $r = db_row("SELECT COUNT(*) AS total, SUM(d.status IN ('upcoming', 'open')) AS upcoming, SUM(d.status = 'ongoing') AS ongoing, SUM(d.status = 'completed') AS completed
                         FROM placement_drives d WHERE d.status <> 'cancelled' AND $driveInSession", [$from, $to]);
            $applicants = (int) db_value("SELECT COUNT(*) FROM placement_applications a JOIN placement_drives d ON d.id = a.drive_id WHERE d.status <> 'cancelled' AND $driveInSession", [$from, $to]);
            $next = db_row("SELECT d.id, d.title, d.drive_date, c.name AS company_name FROM placement_drives d JOIN companies c ON c.id = d.company_id
                            WHERE d.status IN ('upcoming', 'open', 'ongoing') AND d.drive_date >= CURDATE() ORDER BY d.drive_date LIMIT 1");
            return ['session' => $ses['name'], 'total' => (int) $r['total'], 'upcoming' => (int) $r['upcoming'], 'ongoing' => (int) $r['ongoing'], 'completed' => (int) $r['completed'],
                'applicants' => $applicants, 'next' => $next];
        case 'applications':
            $r = db_row("SELECT COUNT(*) AS total, SUM(a.status IN ('applied', 'shortlisted', 'aptitude', 'technical', 'hr', 'on_hold')) AS in_process,
                                SUM(a.status = 'selected') AS selected, SUM(a.status = 'rejected') AS rejected
                         FROM placement_applications a JOIN placement_drives d ON d.id = a.drive_id WHERE d.status <> 'cancelled' AND $driveInSession", [$from, $to]);
            $upcoming = (int) db_value("SELECT COUNT(*) FROM placement_interviews WHERE result = 'pending' AND scheduled_at >= ?", [date('Y-m-d 00:00:00')]);
            $decided = (int) $r['selected'] + (int) $r['rejected'];
            $awaiting = (int) db_value("SELECT COUNT(*) FROM placement_applications a JOIN placement_drives d ON d.id = a.drive_id
                                        WHERE a.status = 'selected' AND d.job_type IN ('full_time', 'contract')
                                          AND NOT EXISTS (SELECT 1 FROM placement_offers o WHERE o.application_id = a.id OR (o.drive_id = a.drive_id AND o.student_id = a.student_id))");
            return ['session' => $ses['name'], 'total' => (int) $r['total'], 'in_process' => (int) $r['in_process'], 'selected' => (int) $r['selected'], 'rejected' => (int) $r['rejected'],
                'upcoming_interviews' => $upcoming, 'selection_rate' => $decided ? round((int) $r['selected'] / $decided * 100, 1) : 0, 'awaiting_offer' => $awaiting];
        case 'offers':
            $r = db_row("SELECT SUM(status IN $valid) AS offers, COUNT(DISTINCT CASE WHEN status IN $valid THEN student_id END) AS placed,
                                COUNT(DISTINCT CASE WHEN status IN ('accepted', 'joined') THEN student_id END) AS confirmed, SUM(status = 'offered') AS pending,
                                SUM(status = 'declined') AS declined, MAX(CASE WHEN status IN $valid THEN package_lpa END) AS highest,
                                ROUND(AVG(CASE WHEN status IN $valid THEN package_lpa END), 2) AS average, SUM(status IN $valid AND offer_letter IS NULL) AS letters_missing
                         FROM placement_offers WHERE offer_date BETWEEN ? AND ?", [$from, $to]);
            return ['session' => $ses['name'], 'offers' => (int) $r['offers'], 'placed' => (int) $r['placed'], 'confirmed' => (int) $r['confirmed'], 'pending' => (int) $r['pending'],
                'declined' => (int) $r['declined'], 'highest' => $r['highest'] !== null ? (float) $r['highest'] : null, 'average' => $r['average'] !== null ? (float) $r['average'] : null,
                'letters_missing' => (int) $r['letters_missing']];
        case 'trainings':
            $r = db_row("SELECT SUM(start_date >= CURDATE() AND status = 'scheduled') AS upcoming, SUM(status = 'ongoing') AS ongoing,
                                SUM(status = 'completed' AND start_date BETWEEN ? AND ?) AS completed FROM placement_trainings", [$from, $to]);
            $p = db_row("SELECT SUM(tp.attended) AS attended, COUNT(DISTINCT CASE WHEN tp.attended = 1 THEN tp.student_id END) AS students,
                                ROUND(AVG(CASE WHEN t.type = 'mock_test' AND tp.score IS NOT NULL AND t.max_marks > 0 THEN tp.score / t.max_marks * 100 END), 1) AS mock_avg
                         FROM placement_training_participants tp JOIN placement_trainings t ON t.id = tp.training_id WHERE t.start_date BETWEEN ? AND ?", [$from, $to]);
            $next = db_row("SELECT id, title, start_date, type FROM placement_trainings WHERE start_date >= CURDATE() AND status IN ('scheduled', 'ongoing') ORDER BY start_date LIMIT 1");
            return ['session' => $ses['name'], 'upcoming' => (int) $r['upcoming'], 'ongoing' => (int) $r['ongoing'], 'completed' => (int) $r['completed'],
                'attendances' => (int) $p['attended'], 'students' => (int) $p['students'], 'mock_avg' => $p['mock_avg'] !== null ? (float) $p['mock_avg'] : null, 'next' => $next];
    }
    throw new InvalidArgumentException('Unknown stats scope.');
}
