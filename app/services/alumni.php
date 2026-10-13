<?php
/**
 * Alumni relations domain services (brief section 23).
 *
 *   - option lists (industries, employment status, event types, job types, donation purposes, payment modes, mentorship areas)
 *   - directory dashboard (KPIs + batch / industry / company / city breakdowns)
 *   - "Import passed-out students" (students with status graduated|alumni → alumni rows, skipping existing ones)
 *   - alumni profile (engagement summary: events, donations, mentorships, jobs, stories, timeline)
 *   - event registrations, job approval workflow, story publishing, donation status + receipt data
 *   - website helper alumni_published_stories() for the public site
 *
 * Used by app/modules/alumni*.php, api/routes/alumni.php and admin/print/donation-receipt.php.
 */

/* ------------------------------------------------------------------
 * Option lists
 * ------------------------------------------------------------------ */

function alumni_industries(): array
{
    $list = ['IT Services', 'Software & Product', 'Consulting', 'Banking & Financial Services', 'Analytics & Data', 'E-commerce & Retail', 'FMCG',
        'Telecom', 'EdTech & Education', 'Healthcare & Pharma', 'Manufacturing & Automotive', 'Media & Advertising', 'Government & PSU', 'Startups', 'Other'];
    return array_combine($list, $list);
}

function alumni_employment_statuses(): array
{
    return ['employed' => 'Employed', 'self_employed' => 'Self-employed', 'entrepreneur' => 'Entrepreneur', 'higher_studies' => 'Higher studies',
        'seeking' => 'Seeking opportunities', 'other' => 'Other'];
}

function alumni_event_types(): array
{
    return ['reunion' => 'Batch reunion', 'homecoming' => 'Homecoming', 'webinar' => 'Webinar', 'chapter_meet' => 'Chapter meet', 'networking' => 'Networking evening',
        'guest_lecture' => 'Guest lecture', 'mentorship' => 'Mentorship session', 'other' => 'Other'];
}

function alumni_event_modes(): array
{
    return ['in_person' => 'In person', 'online' => 'Online', 'hybrid' => 'Hybrid'];
}

function alumni_registration_statuses(): array
{
    return ['registered' => 'Registered', 'attended' => 'Attended', 'no_show' => 'No show', 'cancelled' => 'Cancelled'];
}

function alumni_job_types(): array
{
    return ['full_time' => 'Full time', 'part_time' => 'Part time', 'internship' => 'Internship', 'contract' => 'Contract', 'remote' => 'Remote'];
}

function alumni_job_statuses(): array
{
    return ['pending' => 'Pending approval', 'open' => 'Open', 'rejected' => 'Rejected', 'closed' => 'Closed'];
}

function alumni_donation_purposes(): array
{
    $list = ['Scholarship Fund', 'Infrastructure Development', 'Library & Learning Resources', 'Research & Innovation', 'Sports & Wellness',
        'Student Welfare Fund', 'Alumni Endowment', 'Incubation & Startups', 'General Development Fund'];
    return array_combine($list, $list);
}

function alumni_payment_modes(): array
{
    return ['upi' => 'UPI', 'neft' => 'NEFT / RTGS', 'cheque' => 'Cheque / DD', 'card' => 'Card', 'online' => 'Online gateway', 'cash' => 'Cash'];
}

function alumni_donation_statuses(): array
{
    return ['received' => 'Received', 'pledged' => 'Pledged', 'cancelled' => 'Cancelled'];
}

function alumni_mentorship_areas(): array
{
    $list = ['Career guidance', 'Interview preparation', 'Software engineering', 'Data science & analytics', 'Product management', 'Consulting & case prep',
        'Finance & banking careers', 'Digital marketing', 'Entrepreneurship & startups', 'Higher studies abroad', 'Civil services & PSU exams', 'Leadership & soft skills'];
    return array_combine($list, $list);
}

/** Batch (passing-out) years for filters, newest first. */
function alumni_batch_years(): array
{
    $out = [];
    for ($y = (int) date('Y'); $y >= 2008; $y--) {
        $out[(string) $y] = (string) $y;
    }
    return $out;
}

/** Program id from an id, code, short name, full name or "SHORT — Name" label (import-friendly). */
function alumni_resolve_program($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    $v = trim((string) $value);
    if (ctype_digit($v)) {
        return db_value('SELECT id FROM programs WHERE id = ?', [(int) $v]) ? (int) $v : null;
    }
    $short = trim((string) preg_split('/\s+[—-]\s+/u', $v)[0]);
    $id = db_value('SELECT id FROM programs WHERE code = ? OR short_name = ? OR name = ? OR short_name = ? ORDER BY id LIMIT 1', [$v, $v, $v, $short]);
    return $id ? (int) $id : null;
}

/** Inline lookup source: alumni (async), optionally only mentors. */
function alumni_lookup_source(bool $mentorsOnly = false): array
{
    return [
        'table' => 'alumni a', 'value' => 'a.id', 'label' => 'a.name',
        'sub' => "CONCAT('Batch ', a.batch_year, IFNULL(CONCAT(' · ', NULLIF(a.company, '')), ''), IF(a.is_mentor = 1, ' · Mentor', ''))",
        'where' => "a.status = 'active'" . ($mentorsOnly ? ' AND a.is_mentor = 1' : ''),
        'order' => 'a.is_mentor DESC, a.name', 'search' => ['a.name', 'a.email', 'a.company', 'a.phone'], 'async' => true,
    ];
}

/* ------------------------------------------------------------------
 * Financial years (donations)
 * ------------------------------------------------------------------ */

/** Start year of the Indian financial year (Apr–Mar) a date falls in. */
function alumni_fy_start_year(?string $date = null): int
{
    $ts = $date ? strtotime($date) : time();
    $y = (int) date('Y', $ts);
    return (int) date('n', $ts) >= 4 ? $y : $y - 1;
}

function alumni_fy_label(int $startYear, bool $short = false): string
{
    return ($short ? 'FY ' . substr((string) $startYear, 2) : 'FY ' . $startYear) . '-' . substr((string) ($startYear + 1), 2);
}

/** [from, to] dates of a financial year. */
function alumni_fy_range(int $startYear): array
{
    return [$startYear . '-04-01', ($startYear + 1) . '-03-31'];
}

/** sql_map for a "financial year" filter: ['2026' => "t.donation_date BETWEEN '2026-04-01' AND '2027-03-31'", ...]. */
function alumni_fy_filter_map(string $column = 't.donation_date', int $years = 10): array
{
    $map = [];
    $current = alumni_fy_start_year();
    for ($y = $current; $y > $current - $years; $y--) {
        [$from, $to] = alumni_fy_range($y);
        $map[(string) $y] = "$column BETWEEN '$from' AND '$to'";
    }
    return $map;
}

function alumni_fy_filter_options(int $years = 10): array
{
    $out = [];
    $current = alumni_fy_start_year();
    for ($y = $current; $y > $current - $years; $y--) {
        $out[(string) $y] = alumni_fy_label($y) . ($y === $current ? ' (current)' : '');
    }
    return $out;
}

/* ------------------------------------------------------------------
 * Directory dashboard
 * ------------------------------------------------------------------ */

function alumni_dashboard(): array
{
    $k = db_row(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(employment_status IN ('employed', 'self_employed', 'entrepreneur')), 0) AS working,
                COALESCE(SUM(employment_status = 'higher_studies'), 0) AS higher_studies,
                COALESCE(SUM(employment_status = 'entrepreneur'), 0) AS entrepreneurs,
                COALESCE(SUM(employment_status = 'seeking'), 0) AS seeking,
                COALESCE(SUM(is_mentor = 1), 0) AS mentors,
                COALESCE(SUM(is_verified = 1), 0) AS verified,
                COALESCE(SUM(is_verified = 0 AND created_at >= ?), 0) AS unverified_recent,
                COUNT(DISTINCT NULLIF(TRIM(company), '')) AS companies,
                COUNT(DISTINCT batch_year) AS batches,
                COUNT(DISTINCT NULLIF(TRIM(city), '')) AS cities,
                COALESCE(SUM(COALESCE(country, 'India') <> 'India'), 0) AS abroad,
                MIN(batch_year) AS first_batch,
                COALESCE(SUM(batch_year = ?), 0) AS latest_batch
         FROM alumni WHERE status = 'active'",
        [date('Y-m-d 00:00:00', strtotime('-30 days')), (int) date('Y')]
    ) ?? [];
    $events = db_row(
        "SELECT COALESCE(SUM(status = 'upcoming' AND event_date >= NOW()), 0) AS upcoming,
                COALESCE(SUM(YEAR(event_date) = YEAR(CURDATE()) AND status <> 'cancelled'), 0) AS this_year,
                COUNT(*) AS total
         FROM alumni_events"
    ) ?? [];
    $jobs = db_row(
        "SELECT COALESCE(SUM(status = 'open' AND (last_date IS NULL OR last_date >= CURDATE())), 0) AS open_jobs,
                COALESCE(SUM(status = 'pending'), 0) AS pending
         FROM alumni_jobs"
    ) ?? [];
    $mentorships = (int) db_value("SELECT COUNT(*) FROM alumni_mentorships WHERE status = 'active'");
    [$fyFrom, $fyTo] = alumni_fy_range(alumni_fy_start_year());
    $donations = (float) db_value("SELECT COALESCE(SUM(amount), 0) FROM alumni_donations WHERE status = 'received' AND donation_date BETWEEN ? AND ?", [$fyFrom, $fyTo]);
    $nextEvent = db_row("SELECT id, title, event_date, venue, mode FROM alumni_events WHERE status = 'upcoming' AND event_date >= NOW() ORDER BY event_date LIMIT 1");

    $byBatch = db_all("SELECT batch_year AS year, COUNT(*) AS total, COALESCE(SUM(employment_status IN ('employed', 'self_employed', 'entrepreneur')), 0) AS working
                       FROM alumni WHERE status = 'active' GROUP BY batch_year ORDER BY batch_year");
    $industries = db_all("SELECT COALESCE(NULLIF(industry, ''), 'Not specified') AS label, COUNT(*) AS total FROM alumni WHERE status = 'active'
                          AND employment_status IN ('employed', 'self_employed', 'entrepreneur') GROUP BY label ORDER BY total DESC");
    $topIndustries = array_slice($industries, 0, 7);
    $others = array_sum(array_map(fn ($r) => (int) $r['total'], array_slice($industries, 7)));
    if ($others > 0) {
        $topIndustries[] = ['label' => 'Others', 'total' => $others];
    }
    $companies = db_all("SELECT company AS name, COUNT(*) AS total, MAX(industry) AS industry, COALESCE(SUM(is_mentor = 1), 0) AS mentors
                         FROM alumni WHERE status = 'active' AND company IS NOT NULL AND company <> ''
                         GROUP BY company ORDER BY total DESC, company LIMIT 10");
    $cities = db_all("SELECT city AS label, COUNT(*) AS total FROM alumni WHERE status = 'active' AND city IS NOT NULL AND city <> ''
                      GROUP BY city ORDER BY total DESC LIMIT 8");
    $statuses = [];
    foreach (db_all("SELECT employment_status AS status, COUNT(*) AS total FROM alumni WHERE status = 'active' GROUP BY employment_status ORDER BY total DESC") as $r) {
        $statuses[] = ['status' => $r['status'], 'label' => alumni_employment_statuses()[$r['status']] ?? label_from_key((string) $r['status']), 'total' => (int) $r['total']];
    }
    $programs = db_all("SELECT COALESCE(p.short_name, 'Other') AS label, COUNT(*) AS total FROM alumni a LEFT JOIN programs p ON p.id = a.program_id
                        WHERE a.status = 'active' GROUP BY label ORDER BY total DESC");
    $recent = db_all("SELECT a.id, a.name, a.photo, a.batch_year, a.company, a.designation, p.short_name AS program_short, a.created_at
                      FROM alumni a LEFT JOIN programs p ON p.id = a.program_id ORDER BY a.created_at DESC, a.id DESC LIMIT 6");
    $pendingImport = (int) db_value("SELECT COUNT(*) FROM students s WHERE s.status IN ('graduated', 'alumni') AND NOT EXISTS (SELECT 1 FROM alumni a WHERE a.student_id = s.id)");

    $int = fn ($v) => (int) $v;
    return [
        'kpis' => [
            'total' => $int($k['total'] ?? 0), 'working' => $int($k['working'] ?? 0), 'higher_studies' => $int($k['higher_studies'] ?? 0),
            'entrepreneurs' => $int($k['entrepreneurs'] ?? 0), 'seeking' => $int($k['seeking'] ?? 0), 'mentors' => $int($k['mentors'] ?? 0),
            'verified' => $int($k['verified'] ?? 0), 'unverified_recent' => $int($k['unverified_recent'] ?? 0), 'companies' => $int($k['companies'] ?? 0), 'batches' => $int($k['batches'] ?? 0),
            'cities' => $int($k['cities'] ?? 0), 'abroad' => $int($k['abroad'] ?? 0), 'first_batch' => $k['first_batch'] ? (int) $k['first_batch'] : null,
            'latest_batch' => $int($k['latest_batch'] ?? 0),
            'events_upcoming' => $int($events['upcoming'] ?? 0), 'events_this_year' => $int($events['this_year'] ?? 0), 'events_total' => $int($events['total'] ?? 0),
            'jobs_open' => $int($jobs['open_jobs'] ?? 0), 'jobs_pending' => $int($jobs['pending'] ?? 0),
            'mentorships_active' => $mentorships, 'donations_fy' => $donations, 'fy_label' => alumni_fy_label(alumni_fy_start_year()),
            'pending_import' => $pendingImport,
        ],
        'next_event' => $nextEvent,
        'by_batch' => array_map(fn ($r) => ['year' => (int) $r['year'], 'total' => (int) $r['total'], 'working' => (int) $r['working']], $byBatch),
        'by_industry' => array_map(fn ($r) => ['label' => $r['label'], 'total' => (int) $r['total']], $topIndustries),
        'top_companies' => array_map(fn ($r) => ['name' => $r['name'], 'total' => (int) $r['total'], 'industry' => $r['industry'], 'mentors' => (int) $r['mentors']], $companies),
        'top_cities' => array_map(fn ($r) => ['label' => $r['label'], 'total' => (int) $r['total']], $cities),
        'by_status' => $statuses,
        'by_program' => array_map(fn ($r) => ['label' => $r['label'], 'total' => (int) $r['total']], $programs),
        'recent' => $recent,
    ];
}

/* ------------------------------------------------------------------
 * Import passed-out students
 * ------------------------------------------------------------------ */

/** Passing-out year of a student row (batch end year, never in the future; else the year they were marked passed out). */
function alumni_student_batch_year(array $s): int
{
    $now = (int) date('Y');
    $end = (int) ($s['end_year'] ?? 0);
    if ($end >= 1990 && $end <= $now) {
        return $end;
    }
    $marked = !empty($s['updated_at']) ? (int) date('Y', strtotime((string) $s['updated_at'])) : $now;
    return min($now, max(1990, $marked));
}

/** Students with a passed-out status who are not yet in the alumni directory. */
function alumni_import_candidates(): array
{
    $rows = db_all(
        "SELECT s.id, s.student_uid, s.enrollment_no, TRIM(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name)) AS name, s.email, s.mobile, s.photo, s.gender,
                s.city, s.status, s.program_id, s.department_id, p.short_name AS program_short, p.name AS program_name, b.end_year, s.updated_at,
                (SELECT a.id FROM alumni a WHERE a.email = s.email AND s.email IS NOT NULL AND s.email <> '' LIMIT 1) AS email_match_id
         FROM students s
         LEFT JOIN programs p ON p.id = s.program_id
         LEFT JOIN batches b ON b.id = s.batch_id
         WHERE s.status IN ('graduated', 'alumni') AND NOT EXISTS (SELECT 1 FROM alumni a2 WHERE a2.student_id = s.id)
         ORDER BY p.sort_order, s.first_name, s.last_name
         LIMIT 1000"
    );
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int) $r['id'], 'student_uid' => $r['student_uid'], 'name' => $r['name'], 'email' => $r['email'], 'mobile' => $r['mobile'], 'photo' => $r['photo'],
            'program_short' => $r['program_short'], 'program_name' => $r['program_name'], 'status' => $r['status'], 'city' => $r['city'],
            'batch_year' => alumni_student_batch_year($r), 'existing_alumni_id' => $r['email_match_id'] ? (int) $r['email_match_id'] : null,
        ];
    }
    $already = (int) db_value("SELECT COUNT(*) FROM students s WHERE s.status IN ('graduated', 'alumni') AND EXISTS (SELECT 1 FROM alumni a WHERE a.student_id = s.id)");
    return ['rows' => $out, 'already_imported' => $already];
}

/**
 * Create alumni profiles for passed-out students. Students already linked are skipped; when an alumni profile with the same
 * e-mail exists (added manually) it is linked to the student record instead of creating a duplicate.
 * @return array{inserted:int, linked:int, skipped:int}
 */
function alumni_import_students(array $studentIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
    if (!$ids) {
        throw new CrudException('Select at least one passed-out student to import.');
    }
    if (count($ids) > 1000) {
        throw new CrudException('You can import at most 1,000 students at a time.');
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $students = db_all(
        "SELECT s.*, b.end_year FROM students s LEFT JOIN batches b ON b.id = s.batch_id WHERE s.id IN ($in) AND s.status IN ('graduated', 'alumni')",
        $ids
    );
    $result = ['inserted' => 0, 'linked' => 0, 'skipped' => count($ids) - count($students)];
    db_transaction(function () use ($students, &$result) {
        foreach ($students as $s) {
            if (db_value('SELECT id FROM alumni WHERE student_id = ?', [$s['id']])) {
                $result['skipped']++;
                continue;
            }
            $email = $s['email'] ? strtolower(trim((string) $s['email'])) : null;
            if ($email && ($existing = db_row('SELECT id, student_id FROM alumni WHERE email = ?', [$email]))) {
                if (empty($existing['student_id'])) {
                    db_update('alumni', ['student_id' => (int) $s['id']], 'id = ?', [(int) $existing['id']]);
                    $result['linked']++;
                } else {
                    $result['skipped']++;
                }
                continue;
            }
            db_insert('alumni', [
                'student_id' => (int) $s['id'],
                'name' => trim(implode(' ', array_filter([$s['first_name'], $s['middle_name'], $s['last_name']]))),
                'email' => $email,
                'phone' => $s['mobile'] ?: null,
                'photo' => $s['photo'] ?: null,
                'gender' => $s['gender'] ?: null,
                'batch_year' => alumni_student_batch_year($s),
                'program_id' => $s['program_id'] ?: null,
                'department_id' => $s['department_id'] ?: null,
                'city' => $s['city'] ?: null,
                'country' => $s['country'] ?: 'India',
                'employment_status' => 'seeking',
                'is_verified' => 1,
                'status' => 'active',
            ]);
            $result['inserted']++;
        }
    });
    log_activity('import', 'alumni', null, sprintf('Imported passed-out students into the alumni directory: %d added, %d linked to existing profiles, %d skipped',
        $result['inserted'], $result['linked'], $result['skipped']));
    return $result;
}

/* ------------------------------------------------------------------
 * Profile
 * ------------------------------------------------------------------ */

function alumni_profile(int $id): ?array
{
    $a = db_row(
        "SELECT a.*, p.name AS program_name, p.short_name AS program_short, d.name AS department_name, s.student_uid, s.enrollment_no
         FROM alumni a
         LEFT JOIN programs p ON p.id = a.program_id
         LEFT JOIN departments d ON d.id = a.department_id
         LEFT JOIN students s ON s.id = a.student_id
         WHERE a.id = ?",
        [$id]
    );
    if (!$a) {
        return null;
    }
    $a['id'] = (int) $a['id'];
    $a['batch_year'] = (int) $a['batch_year'];
    foreach (['is_mentor', 'is_verified', 'show_on_website'] as $b) {
        $a[$b] = (bool) $a[$b];
    }
    $a['employment_label'] = alumni_employment_statuses()[$a['employment_status']] ?? label_from_key((string) $a['employment_status']);
    $a['years_since'] = max(0, (int) date('Y') - (int) $a['batch_year']);

    $events = db_all(
        "SELECT r.id AS registration_id, r.status AS registration_status, r.registered_at, e.id, e.title, e.type, e.event_date, e.venue, e.mode, e.status
         FROM alumni_event_registrations r JOIN alumni_events e ON e.id = r.event_id WHERE r.alumni_id = ? ORDER BY e.event_date DESC LIMIT 25",
        [$id]
    );
    $donations = db_all("SELECT id, receipt_no, amount, purpose, campaign, donation_date, payment_mode, status FROM alumni_donations WHERE alumni_id = ? ORDER BY donation_date DESC, id DESC", [$id]);
    $mentorships = db_all(
        "SELECT m.id, m.area, m.start_date, m.end_date, m.sessions_count, m.status, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.photo AS student_photo,
                s.student_uid, p.short_name AS program_short, s.current_semester
         FROM alumni_mentorships m JOIN students s ON s.id = m.student_id LEFT JOIN programs p ON p.id = s.program_id
         WHERE m.alumni_id = ? ORDER BY m.status = 'active' DESC, m.start_date DESC",
        [$id]
    );
    $jobs = db_all(
        "SELECT id, title, company, location, job_type, last_date, created_at,
                CASE WHEN status = 'open' AND last_date IS NOT NULL AND last_date < CURDATE() THEN 'expired' ELSE status END AS job_status
         FROM alumni_jobs WHERE posted_by_alumni_id = ? ORDER BY created_at DESC LIMIT 20",
        [$id]
    );
    $stories = db_all("SELECT id, title, excerpt, image, status, is_featured, show_on_website, published_at, created_at FROM alumni_success_stories WHERE alumni_id = ? ORDER BY created_at DESC", [$id]);

    $received = array_sum(array_map(fn ($d) => $d['status'] === 'received' ? (float) $d['amount'] : 0, $donations));
    $stats = [
        'events_registered' => count(array_filter($events, fn ($e) => $e['registration_status'] !== 'cancelled')),
        'events_attended' => count(array_filter($events, fn ($e) => $e['registration_status'] === 'attended')),
        'donations_total' => $received,
        'donations_count' => count(array_filter($donations, fn ($d) => $d['status'] === 'received')),
        'mentees_active' => count(array_filter($mentorships, fn ($m) => $m['status'] === 'active')),
        'mentees_total' => count(array_filter($mentorships, fn ($m) => $m['status'] !== 'cancelled')),
        'jobs_posted' => count($jobs),
        'stories' => count($stories),
    ];

    // Engagement timeline (newest first)
    $timeline = [];
    foreach ($events as $e) {
        if ($e['registration_status'] === 'cancelled') {
            continue;
        }
        $verb = ['attended' => 'Attended ', 'no_show' => 'Registered (did not attend) ', 'registered' => 'Registered for '][$e['registration_status']] ?? 'Registered for ';
        $timeline[] = ['type' => 'event', 'date' => $e['event_date'], 'title' => $verb . $e['title'],
            'sub' => trim(($e['venue'] ?? '') . ' · ' . (alumni_event_modes()[$e['mode']] ?? $e['mode']), ' ·')];
    }
    foreach ($donations as $d) {
        if ($d['status'] === 'cancelled') {
            continue;
        }
        $timeline[] = ['type' => 'donation', 'date' => $d['donation_date'], 'title' => ($d['status'] === 'pledged' ? 'Pledged ' : 'Donated ') . money($d['amount']) . ' to ' . ($d['purpose'] ?: 'the institute'),
            'sub' => $d['receipt_no']];
    }
    foreach ($mentorships as $m) {
        $timeline[] = ['type' => 'mentorship', 'date' => $m['start_date'] ?? null, 'title' => 'Started mentoring ' . $m['student_name'], 'sub' => trim(($m['area'] ?? '') . ' · ' . ($m['program_short'] ?? ''), ' ·')];
    }
    foreach ($jobs as $j) {
        $timeline[] = ['type' => 'job', 'date' => $j['created_at'], 'title' => 'Posted a job: ' . $j['title'], 'sub' => $j['company']];
    }
    foreach ($stories as $st) {
        $timeline[] = ['type' => 'story', 'date' => $st['published_at'] ?: $st['created_at'], 'title' => 'Success story: ' . $st['title'], 'sub' => $st['status'] === 'published' ? 'Published' : 'Draft'];
    }
    $timeline[] = ['type' => 'joined', 'date' => $a['created_at'], 'title' => 'Profile added to the alumni directory', 'sub' => $a['student_id'] ? 'Imported from student records' : 'Added by the alumni cell'];
    $timeline[] = ['type' => 'graduated', 'date' => $a['batch_year'] . '-06-30', 'title' => 'Graduated' . ($a['program_short'] ? ' — ' . $a['program_short'] : ''), 'sub' => 'Batch of ' . $a['batch_year']];
    usort($timeline, fn ($x, $y) => strcmp((string) ($y['date'] ?? ''), (string) ($x['date'] ?? '')));

    return ['alumni' => $a, 'stats' => $stats, 'events' => $events, 'donations' => $donations, 'mentorships' => $mentorships, 'jobs' => $jobs,
        'stories' => $stories, 'timeline' => array_slice($timeline, 0, 30)];
}

/** Flag / unflag mentors or verify profiles in bulk (CRUD bulk_action hook). */
function alumni_bulk_action(string $action, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        throw new CrudException('Select at least one alumni profile.');
    }
    $in = implode(',', $ids);
    switch ($action) {
        case 'mentor_on':
            $n = db_exec("UPDATE alumni SET is_mentor = 1 WHERE id IN ($in) AND status = 'active'");
            $msg = "$n alumni added to the mentor pool.";
            break;
        case 'mentor_off':
            $n = db_exec("UPDATE alumni SET is_mentor = 0 WHERE id IN ($in)");
            $msg = "$n alumni removed from the mentor pool.";
            break;
        case 'verify':
            $n = db_exec("UPDATE alumni SET is_verified = 1 WHERE id IN ($in)");
            $msg = "$n alumni profiles marked verified.";
            break;
        default:
            throw new CrudException('Unknown bulk action.');
    }
    log_activity('update', 'alumni', implode(',', array_slice($ids, 0, 20)), 'Bulk action "' . str_replace('_', ' ', $action) . '" on ' . count($ids) . ' alumni profiles');
    return ['updated' => $n, 'message' => $msg];
}

/* ------------------------------------------------------------------
 * Mentorships
 * ------------------------------------------------------------------ */

function alumni_mentorship_stats(): array
{
    $k = db_row(
        "SELECT COALESCE(SUM(status = 'active'), 0) AS active, COALESCE(SUM(status = 'completed'), 0) AS completed, COALESCE(SUM(status = 'cancelled'), 0) AS cancelled,
                COUNT(DISTINCT CASE WHEN status = 'active' THEN alumni_id END) AS mentors_engaged,
                COUNT(DISTINCT CASE WHEN status = 'active' THEN student_id END) AS mentees,
                COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN sessions_count END), 0) AS sessions
         FROM alumni_mentorships"
    ) ?? [];
    $pool = (int) db_value("SELECT COUNT(*) FROM alumni WHERE is_mentor = 1 AND status = 'active'");
    $areas = db_all("SELECT COALESCE(NULLIF(area, ''), 'General') AS label, COUNT(*) AS total FROM alumni_mentorships WHERE status <> 'cancelled' GROUP BY label ORDER BY total DESC LIMIT 8");
    $top = db_all(
        "SELECT a.id, a.name, a.photo, a.company, a.designation, a.batch_year, COUNT(m.id) AS mentees, COALESCE(SUM(m.status = 'active'), 0) AS active,
                COALESCE(SUM(m.sessions_count), 0) AS sessions
         FROM alumni a JOIN alumni_mentorships m ON m.alumni_id = a.id AND m.status <> 'cancelled'
         GROUP BY a.id, a.name, a.photo, a.company, a.designation, a.batch_year
         ORDER BY mentees DESC, sessions DESC LIMIT 6"
    );
    $available = (int) db_value(
        "SELECT COUNT(*) FROM alumni a WHERE a.is_mentor = 1 AND a.status = 'active'
         AND (SELECT COUNT(*) FROM alumni_mentorships m WHERE m.alumni_id = a.id AND m.status = 'active') < 3"
    );
    return [
        'kpis' => ['active' => (int) ($k['active'] ?? 0), 'completed' => (int) ($k['completed'] ?? 0), 'cancelled' => (int) ($k['cancelled'] ?? 0),
            'mentors_engaged' => (int) ($k['mentors_engaged'] ?? 0), 'mentees' => (int) ($k['mentees'] ?? 0), 'sessions' => (int) ($k['sessions'] ?? 0),
            'mentor_pool' => $pool, 'mentors_available' => $available],
        'areas' => array_map(fn ($r) => ['label' => $r['label'], 'total' => (int) $r['total']], $areas),
        'top_mentors' => array_map(fn ($r) => array_merge($r, ['id' => (int) $r['id'], 'mentees' => (int) $r['mentees'], 'active' => (int) $r['active'], 'sessions' => (int) $r['sessions']]), $top),
    ];
}

/* ------------------------------------------------------------------
 * Events & registrations
 * ------------------------------------------------------------------ */

function alumni_events_stats(): array
{
    $k = db_row(
        "SELECT COALESCE(SUM(status = 'upcoming' AND event_date >= NOW()), 0) AS upcoming,
                COALESCE(SUM(status = 'completed'), 0) AS completed,
                COALESCE(SUM(status = 'cancelled'), 0) AS cancelled,
                COALESCE(SUM(YEAR(event_date) = YEAR(CURDATE()) AND status <> 'cancelled'), 0) AS this_year,
                COALESCE(SUM(mode IN ('online', 'hybrid')), 0) AS virtual_events
         FROM alumni_events"
    ) ?? [];
    $reg = db_row(
        "SELECT COALESCE(SUM(e.status = 'upcoming' AND e.event_date >= NOW() AND r.status = 'registered'), 0) AS upcoming_registrations,
                COALESCE(SUM(r.status = 'attended' AND e.event_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)), 0) AS attended_12m,
                COALESCE(SUM(e.status = 'completed' AND r.status <> 'cancelled'), 0) AS completed_registered,
                COALESCE(SUM(e.status = 'completed' AND r.status = 'attended'), 0) AS completed_attended
         FROM alumni_event_registrations r JOIN alumni_events e ON e.id = r.event_id"
    ) ?? [];
    $upcoming = db_all(
        "SELECT e.id, e.title, e.type, e.event_date, e.end_date, e.venue, e.mode, e.capacity, e.image,
                (SELECT COUNT(*) FROM alumni_event_registrations r WHERE r.event_id = e.id AND r.status <> 'cancelled') AS registrations
         FROM alumni_events e WHERE e.status = 'upcoming' AND e.event_date >= NOW() ORDER BY e.event_date LIMIT 4"
    );
    $byType = db_all("SELECT type, COUNT(*) AS total FROM alumni_events WHERE status <> 'cancelled' GROUP BY type ORDER BY total DESC");
    $completedRegistered = (int) ($reg['completed_registered'] ?? 0);
    return [
        'kpis' => ['upcoming' => (int) ($k['upcoming'] ?? 0), 'completed' => (int) ($k['completed'] ?? 0), 'cancelled' => (int) ($k['cancelled'] ?? 0),
            'this_year' => (int) ($k['this_year'] ?? 0), 'virtual_events' => (int) ($k['virtual_events'] ?? 0),
            'upcoming_registrations' => (int) ($reg['upcoming_registrations'] ?? 0), 'attended_12m' => (int) ($reg['attended_12m'] ?? 0),
            'attendance_rate' => $completedRegistered ? round(((int) $reg['completed_attended'] / $completedRegistered) * 100, 1) : null],
        'upcoming' => array_map(fn ($r) => array_merge($r, ['id' => (int) $r['id'], 'registrations' => (int) $r['registrations'], 'capacity' => $r['capacity'] !== null ? (int) $r['capacity'] : null]), $upcoming),
        'by_type' => array_map(fn ($r) => ['type' => $r['type'], 'label' => alumni_event_types()[$r['type']] ?? label_from_key((string) $r['type']), 'total' => (int) $r['total']], $byType),
    ];
}

function alumni_event_counts(int $eventId): array
{
    $r = db_row(
        "SELECT COALESCE(SUM(status <> 'cancelled'), 0) AS registered, COALESCE(SUM(status = 'attended'), 0) AS attended,
                COALESCE(SUM(status = 'no_show'), 0) AS no_show, COALESCE(SUM(status = 'cancelled'), 0) AS cancelled,
                COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN guests END), 0) AS guests
         FROM alumni_event_registrations WHERE event_id = ?",
        [$eventId]
    ) ?? [];
    return array_map('intval', $r + ['registered' => 0, 'attended' => 0, 'no_show' => 0, 'cancelled' => 0, 'guests' => 0]);
}

function alumni_event_detail(int $id): ?array
{
    $e = db_row('SELECT * FROM alumni_events WHERE id = ?', [$id]);
    if (!$e) {
        return null;
    }
    $e['id'] = (int) $e['id'];
    $e['type_label'] = alumni_event_types()[$e['type']] ?? label_from_key((string) $e['type']);
    $e['mode_label'] = alumni_event_modes()[$e['mode']] ?? label_from_key((string) $e['mode']);
    $regs = db_all(
        "SELECT r.id, r.alumni_id, r.status, r.guests, r.registered_at, r.remarks, a.name, a.photo, a.email, a.phone, a.batch_year, a.company, a.designation, p.short_name AS program_short
         FROM alumni_event_registrations r JOIN alumni a ON a.id = r.alumni_id LEFT JOIN programs p ON p.id = a.program_id
         WHERE r.event_id = ? ORDER BY FIELD(r.status, 'attended', 'registered', 'no_show', 'cancelled'), a.name",
        [$id]
    );
    foreach ($regs as &$r) {
        $r['id'] = (int) $r['id'];
        $r['alumni_id'] = (int) $r['alumni_id'];
        $r['guests'] = (int) $r['guests'];
    }
    unset($r);
    return ['event' => $e, 'counts' => alumni_event_counts($id), 'registrations' => $regs];
}

/** Register alumni for an event (re-activates cancelled registrations). */
function alumni_event_register(int $eventId, array $alumniIds, int $guests = 0): array
{
    $event = db_row('SELECT * FROM alumni_events WHERE id = ?', [$eventId]);
    if (!$event) {
        throw new CrudException('Event not found. It may have been deleted.');
    }
    if ($event['status'] === 'cancelled') {
        throw new CrudException('This event has been cancelled. Re-open it before adding registrations.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $alumniIds))));
    if (!$ids) {
        throw new CrudValidationException(['alumni_ids' => 'Select at least one alumni to register.']);
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $valid = array_map('intval', db_column("SELECT id FROM alumni WHERE id IN ($in) AND status = 'active'", $ids));
    if (count($valid) !== count($ids)) {
        throw new CrudValidationException(['alumni_ids' => 'One or more selected alumni are inactive or no longer exist.']);
    }
    $existing = db_pairs("SELECT alumni_id, status FROM alumni_event_registrations WHERE event_id = ? AND alumni_id IN ($in)", array_merge([$eventId], $ids));
    $new = array_values(array_filter($ids, fn ($id) => !isset($existing[$id]) || $existing[$id] === 'cancelled'));
    if (!$new) {
        throw new CrudException('The selected alumni are already registered for this event.');
    }
    $counts = alumni_event_counts($eventId);
    if ($event['capacity'] && $counts['registered'] + count($new) > (int) $event['capacity']) {
        $left = max(0, (int) $event['capacity'] - $counts['registered']);
        throw new CrudException('Only ' . $left . ' seat' . ($left === 1 ? '' : 's') . ' left for this event (capacity ' . (int) $event['capacity'] . ').');
    }
    $status = $event['status'] === 'completed' ? 'attended' : 'registered';
    db_transaction(function () use ($new, $existing, $eventId, $status, $guests) {
        foreach ($new as $aid) {
            if (isset($existing[$aid])) {
                db_update('alumni_event_registrations', ['status' => $status, 'registered_at' => date('Y-m-d H:i:s'), 'guests' => $guests], 'event_id = ? AND alumni_id = ?', [$eventId, $aid]);
            } else {
                db_insert('alumni_event_registrations', ['event_id' => $eventId, 'alumni_id' => $aid, 'status' => $status, 'guests' => $guests,
                    'registered_at' => date('Y-m-d H:i:s'), 'created_by' => user_id()]);
            }
        }
    });
    if ($event['status'] === 'completed') {
        alumni_event_sync_attendees($eventId);
    }
    $skipped = count($ids) - count($new);
    log_activity('create', 'alumni', $eventId, 'Registered ' . count($new) . ' alumni for event "' . $event['title'] . '"');
    return ['added' => count($new), 'skipped' => $skipped, 'counts' => alumni_event_counts($eventId)];
}

function alumni_event_sync_attendees(int $eventId): void
{
    $attended = (int) db_value("SELECT COUNT(*) FROM alumni_event_registrations WHERE event_id = ? AND status = 'attended'", [$eventId]);
    if ($attended > 0) {
        db_update('alumni_events', ['attendees_count' => $attended], 'id = ?', [$eventId]);
    }
}

function alumni_registration_set_status(int $regId, string $status): array
{
    if (!isset(alumni_registration_statuses()[$status])) {
        throw new CrudValidationException(['status' => 'Select a valid registration status.']);
    }
    $r = db_row('SELECT r.*, e.title, e.status AS event_status, e.event_date, e.capacity, a.name FROM alumni_event_registrations r JOIN alumni_events e ON e.id = r.event_id JOIN alumni a ON a.id = r.alumni_id WHERE r.id = ?', [$regId]);
    if (!$r) {
        throw new CrudException('Registration not found. It may have been removed.');
    }
    if (in_array($status, ['attended', 'no_show'], true) && strtotime($r['event_date']) > time() + 86400) {
        throw new CrudException('Attendance can be marked only on or after the event date.');
    }
    if ($r['status'] === 'cancelled' && $status !== 'cancelled' && $r['capacity']) {
        $counts = alumni_event_counts((int) $r['event_id']);
        if ($counts['registered'] >= (int) $r['capacity']) {
            throw new CrudException('The event is full. Increase the capacity to restore this registration.');
        }
    }
    db_update('alumni_event_registrations', ['status' => $status], 'id = ?', [$regId]);
    if ($r['event_status'] === 'completed') {
        alumni_event_sync_attendees((int) $r['event_id']);
    }
    log_activity('update', 'alumni', (int) $r['event_id'], $r['name'] . ' marked ' . strtolower(alumni_registration_statuses()[$status]) . ' for "' . $r['title'] . '"');
    return ['id' => $regId, 'status' => $status, 'counts' => alumni_event_counts((int) $r['event_id'])];
}

function alumni_registration_delete(int $regId): array
{
    $r = db_row('SELECT r.*, e.title, a.name FROM alumni_event_registrations r JOIN alumni_events e ON e.id = r.event_id JOIN alumni a ON a.id = r.alumni_id WHERE r.id = ?', [$regId]);
    if (!$r) {
        throw new CrudException('Registration not found. It may have been removed.');
    }
    db_delete('alumni_event_registrations', 'id = ?', [$regId]);
    log_activity('delete', 'alumni', (int) $r['event_id'], 'Removed ' . $r['name'] . ' from "' . $r['title'] . '" registrations');
    return ['counts' => alumni_event_counts((int) $r['event_id'])];
}

/** Mark an event completed / cancelled / upcoming again. */
function alumni_event_set_status(int $id, string $status): array
{
    $e = db_row('SELECT * FROM alumni_events WHERE id = ?', [$id]);
    if (!$e) {
        throw new CrudException('Event not found. It may have been deleted.');
    }
    if (!in_array($status, ['upcoming', 'completed', 'cancelled'], true)) {
        throw new CrudException('Unknown event status.');
    }
    if ($e['status'] === $status) {
        throw new CrudException('This event is already ' . $status . '.');
    }
    if ($status === 'completed' && strtotime($e['event_date']) > time()) {
        throw new CrudException('This event has not taken place yet. It can be marked completed on or after ' . format_date($e['event_date']) . '.');
    }
    if ($status === 'upcoming' && strtotime($e['event_date']) < time() - 86400) {
        throw new CrudException('The event date has passed. Change the date before re-opening it.');
    }
    db_update('alumni_events', ['status' => $status], 'id = ?', [$id]);
    if ($status === 'completed') {
        // Everyone still "registered" on a completed event without attendance marked counts as a no-show only when some attendance was taken.
        alumni_event_sync_attendees($id);
    }
    $verb = ['completed' => 'Marked completed', 'cancelled' => 'Cancelled', 'upcoming' => 'Re-opened'][$status];
    log_activity('update', 'alumni', $id, $verb . ' alumni event "' . $e['title'] . '"');
    if ($status === 'cancelled') {
        $n = (int) db_value("SELECT COUNT(*) FROM alumni_event_registrations WHERE event_id = ? AND status = 'registered'", [$id]);
        if ($n) {
            notify('perm:alumni', 'event', 'Alumni event cancelled', '"' . $e['title'] . '" was cancelled. ' . $n . ' registered alumni need to be informed.', 'admin/alumni/events', 'calendar-x');
        }
    }
    return ['id' => $id, 'status' => $status, 'message' => $verb === 'Cancelled' ? 'Event cancelled.' : ($verb === 'Re-opened' ? 'Event re-opened for registrations.' : 'Event marked completed.')];
}

/* ------------------------------------------------------------------
 * Job opportunities
 * ------------------------------------------------------------------ */

const ALUMNI_JOB_STATUS_SQL = "CASE WHEN t.status = 'open' AND t.last_date IS NOT NULL AND t.last_date < CURDATE() THEN 'expired' ELSE t.status END";

function alumni_jobs_stats(): array
{
    $k = db_row(
        "SELECT COALESCE(SUM(status = 'pending'), 0) AS pending,
                COALESCE(SUM(status = 'open' AND (last_date IS NULL OR last_date >= CURDATE())), 0) AS open_jobs,
                COALESCE(SUM(status = 'open' AND last_date < CURDATE()), 0) AS expired,
                COALESCE(SUM(status = 'open' AND last_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)), 0) AS closing_soon,
                COALESCE(SUM(status = 'closed'), 0) AS closed,
                COALESCE(SUM(status = 'rejected'), 0) AS rejected,
                COALESCE(SUM(created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)), 0) AS last_30,
                COUNT(DISTINCT posted_by_alumni_id) AS posters,
                COUNT(DISTINCT CASE WHEN status = 'open' THEN company END) AS companies
         FROM alumni_jobs"
    ) ?? [];
    $pending = db_all(
        "SELECT t.id, t.title, t.company, t.location, t.job_type, t.experience, t.salary_range, t.last_date, t.created_at, a.id AS alumni_id, a.name AS poster_name, a.photo AS poster_photo, a.batch_year
         FROM alumni_jobs t LEFT JOIN alumni a ON a.id = t.posted_by_alumni_id WHERE t.status = 'pending' ORDER BY t.created_at LIMIT 6"
    );
    $types = db_all("SELECT job_type, COUNT(*) AS total FROM alumni_jobs WHERE status IN ('open', 'closed') GROUP BY job_type ORDER BY total DESC");
    return [
        'kpis' => array_map('intval', $k),
        'pending' => $pending,
        'by_type' => array_map(fn ($r) => ['type' => $r['job_type'], 'label' => alumni_job_types()[$r['job_type']] ?? label_from_key((string) $r['job_type']), 'total' => (int) $r['total']], $types),
    ];
}

/** approve | reject | close | reopen a job post. */
function alumni_job_action(int $id, string $action, ?string $reason = null): array
{
    $job = db_row('SELECT * FROM alumni_jobs WHERE id = ?', [$id]);
    if (!$job) {
        throw new CrudException('Job opportunity not found. It may have been deleted.');
    }
    $today = date('Y-m-d');
    $label = '"' . $job['title'] . '" at ' . $job['company'];
    switch ($action) {
        case 'approve':
            if (!in_array($job['status'], ['pending', 'rejected'], true)) {
                throw new CrudException('Only pending or rejected posts can be approved.');
            }
            if ($job['last_date'] && $job['last_date'] < $today) {
                throw new CrudException('The last date to apply (' . format_date($job['last_date']) . ') has passed. Extend it before approving.');
            }
            db_update('alumni_jobs', ['status' => 'open', 'approved_by' => user_id(), 'approved_at' => date('Y-m-d H:i:s'), 'rejection_reason' => null, 'closed_at' => null], 'id = ?', [$id]);
            log_activity('approve', 'alumni', $id, 'Approved alumni job post ' . $label);
            notify('perm:placement', 'placement', 'New alumni job opportunity', $job['title'] . ' at ' . $job['company'] . ' is now open for students.', 'admin/alumni/jobs?f.job_status=open', 'briefcase');
            $msg = 'Job post approved and published to students.';
            break;
        case 'reject':
            if ($job['status'] !== 'pending') {
                throw new CrudException('Only posts awaiting approval can be rejected.');
            }
            $reason = trim((string) $reason);
            if (mb_strlen($reason) < 5) {
                throw new CrudValidationException(['reason' => 'Give a reason (at least 5 characters) so the alumni can be informed.']);
            }
            db_update('alumni_jobs', ['status' => 'rejected', 'rejection_reason' => mb_substr($reason, 0, 255), 'approved_by' => user_id(), 'approved_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            log_activity('update', 'alumni', $id, 'Rejected alumni job post ' . $label . ': ' . $reason);
            $msg = 'Job post rejected.';
            break;
        case 'close':
            if ($job['status'] !== 'open') {
                throw new CrudException('Only open job posts can be closed.');
            }
            db_update('alumni_jobs', ['status' => 'closed', 'closed_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            log_activity('update', 'alumni', $id, 'Closed alumni job post ' . $label);
            $msg = 'Job post closed.';
            break;
        case 'reopen':
            if ($job['status'] !== 'closed') {
                throw new CrudException('Only closed job posts can be re-opened.');
            }
            if ($job['last_date'] && $job['last_date'] < $today) {
                throw new CrudException('The last date to apply has passed. Edit the post and extend the last date to re-open it.');
            }
            db_update('alumni_jobs', ['status' => 'open', 'closed_at' => null], 'id = ?', [$id]);
            log_activity('update', 'alumni', $id, 'Re-opened alumni job post ' . $label);
            $msg = 'Job post re-opened.';
            break;
        default:
            throw new CrudException('Unknown action.');
    }
    return ['id' => $id, 'message' => $msg];
}

function alumni_jobs_bulk(string $action, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    $done = 0;
    $errors = [];
    foreach ($ids as $id) {
        try {
            alumni_job_action($id, $action);
            $done++;
        } catch (CrudException $e) {
            $errors[$id] = $e->getMessage();
        }
    }
    $verb = $action === 'approve' ? 'approved' : 'closed';
    return ['updated' => $done, 'errors' => $errors, 'message' => $done . ' job post' . ($done === 1 ? '' : 's') . ' ' . $verb . '.' . ($errors ? ' ' . count($errors) . ' skipped (not in a valid state).' : '')];
}

function alumni_jobs_close_expired(): int
{
    $n = db_exec("UPDATE alumni_jobs SET status = 'closed', closed_at = NOW() WHERE status = 'open' AND last_date IS NOT NULL AND last_date < CURDATE()");
    if ($n) {
        log_activity('update', 'alumni', null, "Closed $n expired alumni job post" . ($n === 1 ? '' : 's'));
    }
    return $n;
}

/* ------------------------------------------------------------------
 * Success stories
 * ------------------------------------------------------------------ */

const ALUMNI_MAX_FEATURED_STORIES = 6;

function alumni_stories_stats(): array
{
    $k = db_row(
        "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'published'), 0) AS published, COALESCE(SUM(status = 'draft'), 0) AS drafts,
                COALESCE(SUM(status = 'published' AND show_on_website = 1), 0) AS on_website, COALESCE(SUM(is_featured = 1), 0) AS featured
         FROM alumni_success_stories"
    ) ?? [];
    $featured = db_all(
        "SELECT t.id, t.title, t.excerpt, t.image, t.company, t.designation, t.status, t.show_on_website, a.id AS alumni_id, a.name AS alumni_name, a.photo AS alumni_photo,
                a.batch_year, p.short_name AS program_short
         FROM alumni_success_stories t JOIN alumni a ON a.id = t.alumni_id LEFT JOIN programs p ON p.id = a.program_id
         WHERE t.is_featured = 1 ORDER BY t.sort_order, t.published_at DESC, t.id DESC LIMIT " . ALUMNI_MAX_FEATURED_STORIES
    );
    return ['kpis' => array_map('intval', $k), 'featured' => $featured, 'max_featured' => ALUMNI_MAX_FEATURED_STORIES];
}

function alumni_story_publish(int $id, bool $publish): array
{
    $s = db_row('SELECT * FROM alumni_success_stories WHERE id = ?', [$id]);
    if (!$s) {
        throw new CrudException('Success story not found. It may have been deleted.');
    }
    if ($publish) {
        if (trim(strip_tags((string) $s['story'])) === '') {
            throw new CrudException('Write the story before publishing it.');
        }
        db_update('alumni_success_stories', ['status' => 'published', 'show_on_website' => 1, 'published_at' => $s['published_at'] ?: date('Y-m-d H:i:s')], 'id = ?', [$id]);
        log_activity('publish', 'alumni', $id, 'Published success story "' . $s['title'] . '" to the website');
        return ['id' => $id, 'message' => 'Story published to the website.'];
    }
    db_update('alumni_success_stories', ['show_on_website' => 0], 'id = ?', [$id]);
    log_activity('update', 'alumni', $id, 'Removed success story "' . $s['title'] . '" from the website');
    return ['id' => $id, 'message' => 'Story removed from the website.'];
}

function alumni_story_feature(int $id, bool $featured): array
{
    $s = db_row('SELECT * FROM alumni_success_stories WHERE id = ?', [$id]);
    if (!$s) {
        throw new CrudException('Success story not found. It may have been deleted.');
    }
    if ($featured) {
        $count = (int) db_value('SELECT COUNT(*) FROM alumni_success_stories WHERE is_featured = 1 AND id <> ?', [$id]);
        if ($count >= ALUMNI_MAX_FEATURED_STORIES) {
            throw new CrudException('Up to ' . ALUMNI_MAX_FEATURED_STORIES . ' stories can be featured. Un-feature another story first.');
        }
    }
    db_update('alumni_success_stories', ['is_featured' => $featured ? 1 : 0], 'id = ?', [$id]);
    log_activity('update', 'alumni', $id, ($featured ? 'Featured' : 'Un-featured') . ' success story "' . $s['title'] . '"');
    return ['id' => $id, 'message' => $featured ? 'Story featured.' : 'Story removed from featured.'];
}

/**
 * Published alumni success stories for the public website (featured first).
 * Shape matches the website testimonial helpers: name, role, company, program, quote, title, photo, image.
 */
function alumni_published_stories(int $limit = 6): array
{
    try {
        $rows = db_all(
            "SELECT t.id, t.title, t.excerpt, t.story, t.image, COALESCE(t.company, a.company) AS company, COALESCE(t.designation, a.designation) AS designation,
                    a.name, a.photo, a.batch_year, p.short_name AS program_short
             FROM alumni_success_stories t JOIN alumni a ON a.id = t.alumni_id LEFT JOIN programs p ON p.id = a.program_id
             WHERE t.status = 'published' AND t.show_on_website = 1
             ORDER BY t.is_featured DESC, t.sort_order, t.published_at DESC LIMIT " . max(1, min(50, $limit))
        );
    } catch (Throwable $e) {
        return [];
    }
    return array_map(fn ($r) => [
        'id' => (int) $r['id'], 'title' => $r['title'], 'name' => $r['name'], 'role' => (string) $r['designation'], 'company' => (string) $r['company'],
        'program' => trim(($r['program_short'] ?? '') . ', Batch of ' . $r['batch_year'], ', '),
        'quote' => $r['excerpt'] ?: str_limit(trim(strip_tags((string) $r['story'])), 220),
        'photo' => $r['photo'] ? upload_url($r['photo']) : null, 'image' => $r['image'] ? upload_url($r['image']) : null,
    ], $rows);
}

/* ------------------------------------------------------------------
 * Donations
 * ------------------------------------------------------------------ */

function alumni_donations_stats(): array
{
    $fy = alumni_fy_start_year();
    [$from, $to] = alumni_fy_range($fy);
    [$pFrom, $pTo] = alumni_fy_range($fy - 1);
    $pToDate = min($pTo, date('Y-m-d', strtotime('-1 year')));
    $k = db_row(
        "SELECT COALESCE(SUM(CASE WHEN status = 'received' THEN amount END), 0) AS all_time,
                COALESCE(SUM(CASE WHEN status = 'received' AND donation_date BETWEEN ? AND ? THEN amount END), 0) AS this_fy,
                COALESCE(SUM(CASE WHEN status = 'received' AND donation_date BETWEEN ? AND ? THEN amount END), 0) AS last_fy,
                COALESCE(SUM(CASE WHEN status = 'received' AND donation_date BETWEEN ? AND ? THEN amount END), 0) AS last_fy_to_date,
                COALESCE(SUM(status = 'received' AND donation_date BETWEEN ? AND ?), 0) AS this_fy_count,
                COALESCE(SUM(status = 'received'), 0) AS received_count,
                COUNT(DISTINCT CASE WHEN status = 'received' THEN alumni_id END) AS donors,
                COUNT(DISTINCT CASE WHEN status = 'received' AND donation_date BETWEEN ? AND ? THEN alumni_id END) AS donors_fy,
                COALESCE(SUM(CASE WHEN status = 'pledged' THEN amount END), 0) AS pledged,
                COALESCE(SUM(status = 'pledged'), 0) AS pledged_count,
                COALESCE(MAX(CASE WHEN status = 'received' THEN amount END), 0) AS largest
         FROM alumni_donations",
        [$from, $to, $pFrom, $pTo, $pFrom, $pToDate, $from, $to, $from, $to]
    ) ?? [];
    $receivedCount = (int) ($k['received_count'] ?? 0);
    $byPurpose = db_all("SELECT COALESCE(NULLIF(purpose, ''), 'General Development Fund') AS label, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS n
                         FROM alumni_donations WHERE status = 'received' GROUP BY label ORDER BY total DESC");
    $byYear = [];
    for ($y = $fy - 7; $y <= $fy; $y++) {
        [$a, $b] = alumni_fy_range($y);
        $r = db_row("SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS n, COUNT(DISTINCT alumni_id) AS donors FROM alumni_donations WHERE status = 'received' AND donation_date BETWEEN ? AND ?", [$a, $b]);
        $byYear[] = ['fy' => $y, 'label' => alumni_fy_label($y, true), 'total' => (float) $r['total'], 'count' => (int) $r['n'], 'donors' => (int) $r['donors']];
    }
    $byMode = db_all("SELECT payment_mode AS mode, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS n FROM alumni_donations WHERE status = 'received' GROUP BY payment_mode ORDER BY total DESC");
    $topDonors = db_all(
        "SELECT a.id, a.name, a.photo, a.batch_year, a.company, a.designation, COALESCE(SUM(d.amount), 0) AS total, COUNT(d.id) AS n, MAX(d.donation_date) AS last_date
         FROM alumni_donations d JOIN alumni a ON a.id = d.alumni_id WHERE d.status = 'received' AND d.is_anonymous = 0
         GROUP BY a.id, a.name, a.photo, a.batch_year, a.company, a.designation ORDER BY total DESC LIMIT 6"
    );
    $campaigns = db_all(
        "SELECT campaign AS label, COALESCE(SUM(CASE WHEN status = 'received' THEN amount END), 0) AS total, COALESCE(SUM(CASE WHEN status = 'pledged' THEN amount END), 0) AS pledged,
                COUNT(DISTINCT alumni_id) AS donors, MAX(donation_date) AS last_date
         FROM alumni_donations WHERE campaign IS NOT NULL AND campaign <> '' AND status <> 'cancelled' GROUP BY campaign ORDER BY last_date DESC LIMIT 5"
    );
    return [
        'kpis' => [
            'all_time' => (float) $k['all_time'], 'this_fy' => (float) $k['this_fy'], 'last_fy' => (float) $k['last_fy'], 'this_fy_count' => (int) $k['this_fy_count'],
            'received_count' => $receivedCount, 'donors' => (int) $k['donors'], 'donors_fy' => (int) $k['donors_fy'], 'pledged' => (float) $k['pledged'],
            'pledged_count' => (int) $k['pledged_count'], 'largest' => (float) $k['largest'],
            'average' => $receivedCount ? round((float) $k['all_time'] / $receivedCount) : 0,
            'fy_label' => alumni_fy_label($fy), 'last_fy_label' => alumni_fy_label($fy - 1),
            'last_fy_to_date' => (float) $k['last_fy_to_date'],
            // growth vs the same period of the previous financial year (FY-to-date comparison)
            'growth_pct' => (float) $k['last_fy_to_date'] > 0 ? round((((float) $k['this_fy'] - (float) $k['last_fy_to_date']) / (float) $k['last_fy_to_date']) * 100, 1) : null,
        ],
        'by_purpose' => array_map(fn ($r) => ['label' => $r['label'], 'total' => (float) $r['total'], 'count' => (int) $r['n']], $byPurpose),
        'by_year' => $byYear,
        'by_mode' => array_map(fn ($r) => ['mode' => $r['mode'], 'label' => alumni_payment_modes()[$r['mode']] ?? label_from_key((string) $r['mode']), 'total' => (float) $r['total'], 'count' => (int) $r['n']], $byMode),
        'top_donors' => array_map(fn ($r) => array_merge($r, ['id' => (int) $r['id'], 'total' => (float) $r['total'], 'n' => (int) $r['n']]), $topDonors),
        'campaigns' => array_map(fn ($r) => array_merge($r, ['total' => (float) $r['total'], 'pledged' => (float) $r['pledged'], 'donors' => (int) $r['donors']]), $campaigns),
    ];
}

/** Pledged → received (with reference) or cancel a donation. */
function alumni_donation_set_status(int $id, string $status, array $input = []): array
{
    $d = db_row('SELECT d.*, a.name FROM alumni_donations d JOIN alumni a ON a.id = d.alumni_id WHERE d.id = ?', [$id]);
    if (!$d) {
        throw new CrudException('Donation not found. It may have been deleted.');
    }
    if ($status === 'received') {
        if ($d['status'] !== 'pledged') {
            throw new CrudException('Only pledged donations can be marked as received.');
        }
        $mode = (string) ($input['payment_mode'] ?? $d['payment_mode'] ?? '');
        $ref = trim((string) ($input['reference_no'] ?? ''));
        $date = (string) ($input['donation_date'] ?? date('Y-m-d'));
        $errors = [];
        if (!isset(alumni_payment_modes()[$mode])) {
            $errors['payment_mode'] = 'Select the payment mode.';
        }
        if ($mode !== 'cash' && $ref === '') {
            $errors['reference_no'] = 'Reference / transaction no. is required for ' . (alumni_payment_modes()[$mode] ?? 'this mode') . ' payments.';
        }
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            $errors['donation_date'] = 'Enter a valid date.';
        } elseif ($date > date('Y-m-d')) {
            $errors['donation_date'] = 'Received date cannot be in the future.';
        }
        if ($errors) {
            throw new CrudValidationException($errors);
        }
        db_update('alumni_donations', ['status' => 'received', 'payment_mode' => $mode, 'reference_no' => $ref !== '' ? mb_substr($ref, 0, 100) : null, 'donation_date' => $date], 'id = ?', [$id]);
        log_activity('update', 'alumni', $id, 'Received pledged donation of ' . money($d['amount']) . ' from ' . $d['name'] . ' (' . $d['receipt_no'] . ')');
        return ['id' => $id, 'message' => 'Donation marked as received. Receipt ' . $d['receipt_no'] . ' is ready to print.'];
    }
    if ($status === 'cancelled') {
        if ($d['status'] === 'cancelled') {
            throw new CrudException('This donation is already cancelled.');
        }
        $reason = trim((string) ($input['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            throw new CrudValidationException(['reason' => 'Give a reason for cancelling (at least 5 characters).']);
        }
        $remarks = trim(($d['remarks'] ? $d['remarks'] . ' | ' : '') . 'Cancelled: ' . $reason);
        db_update('alumni_donations', ['status' => 'cancelled', 'remarks' => mb_substr($remarks, 0, 255)], 'id = ?', [$id]);
        log_activity('update', 'alumni', $id, 'Cancelled donation ' . $d['receipt_no'] . ' of ' . money($d['amount']) . ' from ' . $d['name'] . ': ' . $reason);
        return ['id' => $id, 'message' => 'Donation ' . $d['receipt_no'] . ' cancelled.'];
    }
    throw new CrudException('Unknown donation status.');
}

/** Rupees in words (Indian numbering) for receipts. */
function alumni_amount_in_words(float $amount): string
{
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two = function (int $n) use ($ones, $tens): string {
        return $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
    };
    $three = function (int $n) use ($two, $ones): string {
        $h = intdiv($n, 100);
        $r = $n % 100;
        return trim(($h ? $ones[$h] . ' Hundred' : '') . ($h && $r ? ' ' : '') . ($r ? $two($r) : ''));
    };
    $rupees = (int) floor($amount);
    $paise = (int) round(($amount - $rupees) * 100);
    if ($rupees === 0 && $paise === 0) {
        return 'Rupees Zero Only';
    }
    $parts = [];
    foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as [$div, $word]) {
        if ($rupees >= $div) {
            $q = intdiv($rupees, $div);
            $parts[] = ($q >= 100 ? $three($q) : $two($q)) . ' ' . $word;
            $rupees %= $div;
        }
    }
    if ($rupees > 0) {
        $parts[] = $three($rupees);
    }
    $words = 'Rupees ' . implode(' ', $parts);
    if ($paise > 0) {
        $words .= ' and ' . $two($paise) . ' Paise';
    }
    return $words . ' Only';
}
