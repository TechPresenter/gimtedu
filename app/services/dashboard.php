<?php
/**
 * Admin dashboard aggregation for GET /api/dashboard.
 *
 *   dashboard_payload(int $userId, ?int $sessionId, bool $refresh = false): array
 *
 * One permission-aware payload with KPIs (+ trends, + 6-point sparklines), charts, lists, system status and the hero
 * blocks (session progress rings, "today at GIMT" live counters). A block is only built when the current user may view
 * the module it comes from. Every block is isolated: a failing query (a module that is empty, still being built or
 * missing a column) is logged and the block is simply omitted, so the dashboard always renders.
 * The result is cached per user + academic session for DASH_CACHE_TTL seconds in storage/cache.
 */

const DASH_CACHE_TTL = 60;
/** Bump when the payload shape changes so cached payloads of the previous shape are not served. */
const DASH_CACHE_VERSION = 2;
const DASH_OPEN_STAGES = ['application', 'document_verification', 'entrance_interview', 'approval', 'fee_payment'];

/* ------------------------------------------------------------------
 * Cache & helpers
 * ------------------------------------------------------------------ */

function dash_cache_file(string $name): string
{
    $dir = STORAGE_PATH . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/' . $name . '.json';
}

function dash_cache_read(string $name, int $ttl): ?array
{
    $file = dash_cache_file($name);
    if (!is_file($file) || filemtime($file) <= time() - $ttl) {
        return null;
    }
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function dash_cache_write(string $name, array $data): void
{
    @file_put_contents(dash_cache_file($name), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), LOCK_EX);
}

/** Run one dashboard block; failures are logged and yield null (block omitted). */
function dash_try(string $block, callable $fn)
{
    try {
        return $fn();
    } catch (Throwable $e) {
        error_log('[GIMT] dashboard block "' . $block . '" failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Trend descriptor consumed by the SPA StatCard: ['value' => '12%', 'dir' => 'up', 'label' => 'from last session', 'positive' => true].
 * Returns null when there is nothing to compare against.
 */
function dash_trend(float $current, ?float $previous, string $label, bool $higherIsGood = true): ?array
{
    if ($previous === null) {
        return null;
    }
    if ($previous <= 0) {
        if ($current <= 0) {
            return ['value' => '0%', 'dir' => 'flat', 'label' => 'no change', 'positive' => true];
        }
        return ['value' => 'New', 'dir' => 'up', 'label' => $label, 'positive' => $higherIsGood];
    }
    $pct = round(($current - $previous) * 100 / $previous, 1);
    if (abs($pct) < 0.05) {
        return ['value' => '0%', 'dir' => 'flat', 'label' => 'no change', 'positive' => true];
    }
    $dir = $pct > 0 ? 'up' : 'down';
    $abs = abs($pct);
    return ['value' => ($abs >= 10 ? round($abs) : $abs) . '%', 'dir' => $dir, 'label' => $label, 'positive' => ($dir === 'up') === $higherIsGood];
}

/** Same calendar offset into another session: day N of the current session -> day N of $other. */
function dash_same_point(array $ses, array $other, string $asOf): string
{
    $offset = max(0, strtotime($asOf) - strtotime($ses['start_date']));
    return date('Y-m-d', min(strtotime($other['end_date']), strtotime($other['start_date']) + $offset));
}

function dash_short_department(?string $name): string
{
    $n = trim((string) preg_replace('/^(Department|School|Faculty) of\s+/i', '', (string) $name));
    return $n !== '' ? $n : 'Unassigned';
}

function dash_session(?int $sessionId): array
{
    $row = $sessionId ? db_row('SELECT * FROM academic_sessions WHERE id = ?', [$sessionId]) : null;
    if (!$row) {
        $cur = current_session();
        $row = !empty($cur['id']) ? db_row('SELECT * FROM academic_sessions WHERE id = ?', [(int) $cur['id']]) : null;
    }
    if (!$row) {
        $y = (int) date('n') >= 7 ? (int) date('Y') : (int) date('Y') - 1;
        $row = ['id' => null, 'name' => $y . '-' . substr((string) ($y + 1), 2), 'start_date' => $y . '-07-01', 'end_date' => ($y + 1) . '-06-30', 'is_current' => 1, 'admissions_open' => 0, 'status' => 'active'];
    }
    return $row;
}

/* ------------------------------------------------------------------
 * Entry point
 * ------------------------------------------------------------------ */

function dashboard_payload(int $userId, ?int $sessionId, bool $refresh = false): array
{
    $ses = dash_session($sessionId);
    $key = 'dashboard-v' . DASH_CACHE_VERSION . '-u' . $userId . '-s' . ((int) ($ses['id'] ?? 0));
    if (!$refresh && ($cached = dash_cache_read($key, DASH_CACHE_TTL))) {
        $cached['cached'] = true;
        return $cached;
    }
    $data = dashboard_build($ses);
    $data['generated_at'] = date('Y-m-d H:i:s');
    dash_cache_write($key, $data);
    $data['cached'] = false;
    return $data;
}

function dashboard_build(array $ses): array
{
    $sid = $ses['id'] ? (int) $ses['id'] : null;
    $today = date('Y-m-d');
    $asOf = $today < $ses['start_date'] ? $ses['start_date'] : min($today, $ses['end_date']);
    $prev = db_row('SELECT * FROM academic_sessions WHERE start_date < ? ORDER BY start_date DESC LIMIT 1', [$ses['start_date']]);
    // The institution's live session (what "today" belongs to), independent of the session the user is browsing.
    $live = db_row('SELECT id, name FROM academic_sessions WHERE is_current = 1 ORDER BY start_date DESC LIMIT 1') ?: null;
    $ctx = ['ses' => $ses, 'sid' => $sid, 'prev' => $prev, 'today' => $today, 'as_of' => $asOf, 'live' => $live];

    $kpis = [];
    $add = function (string $key, ?array $kpi) use (&$kpis) {
        if ($kpi !== null) {
            $kpis[$key] = $kpi;
        }
    };
    if (can('students')) {
        $add('total_students', dash_try('total_students', fn () => dash_kpi_total_students($ctx)));
        $add('active_students', dash_try('active_students', fn () => dash_kpi_active_students($ctx)));
    }
    if (can_any(['admissions', 'students'])) {
        $add('new_admissions', dash_try('new_admissions', fn () => dash_kpi_new_admissions($ctx)));
    }
    if (can('admissions')) {
        $add('pending_applications', dash_try('pending_applications', fn () => dash_kpi_pending_applications($ctx)));
    }
    if (can_any(['academics', 'admissions', 'students'])) {
        $add('active_programs', dash_try('active_programs', fn () => dash_kpi_programs($ctx)));
    }
    if (can('faculty')) {
        $add('faculty', dash_try('faculty', fn () => dash_kpi_faculty()));
        $add('staff', dash_try('staff', fn () => dash_kpi_staff()));
    }
    if (can('fees')) {
        $add('fee_collected', dash_try('fee_collected', fn () => dash_kpi_fee_collected($ctx)));
        $add('pending_dues', dash_try('pending_dues', fn () => dash_kpi_pending_dues($ctx)));
    }
    if (can('attendance')) {
        $add('attendance_today', dash_try('attendance_today', fn () => dash_kpi_attendance($ctx)));
    }
    if (can('enquiries')) {
        $add('enquiries_open', dash_try('enquiries_open', fn () => dash_kpi_enquiries()));
    }
    if (can('examination')) {
        $add('upcoming_exams', dash_try('upcoming_exams', fn () => dash_kpi_exams($ctx)));
    }
    if (can('placement')) {
        $add('placed_students', dash_try('placed_students', fn () => dash_kpi_placed($ctx)));
    }
    if (can('alumni')) {
        $add('alumni', dash_try('alumni', fn () => dash_kpi_alumni()));
    }
    if (can('library')) {
        $add('library_books', dash_try('library_books', fn () => dash_kpi_library()));
    }
    if (can('transport')) {
        $add('transport_vehicles', dash_try('transport_vehicles', fn () => dash_kpi_transport()));
    }
    // 6-point trend per KPI (sparklines on the KPI cards); a KPI without a meaningful history simply has none.
    foreach (array_keys($kpis) as $kpiKey) {
        $spark = dash_try('spark_' . $kpiKey, fn () => dash_spark($kpiKey, $ctx));
        if ($spark !== null) {
            $kpis[$kpiKey]['spark'] = $spark;
        }
    }

    $charts = [];
    $lists = [];
    if (can('admissions')) {
        $charts['admissions_trend'] = dash_try('admissions_trend', fn () => dash_chart_admissions_trend($ctx));
        $lists['recent_admissions'] = dash_try('recent_admissions', fn () => dash_list_recent_admissions($ctx));
    }
    if (can_any(['admissions', 'students'])) {
        $lists['top_programs'] = dash_try('top_programs', fn () => dash_list_top_programs($ctx));
    }
    if (can('fees')) {
        $charts['fee_collection'] = dash_try('fee_collection', fn () => dash_chart_fee_collection($ctx));
        $lists['recent_payments'] = dash_try('recent_payments', fn () => dash_list_recent_payments());
    }
    if (can('attendance')) {
        $charts['attendance'] = dash_try('attendance', fn () => dash_chart_attendance($ctx));
    }
    if (can('students')) {
        $charts['distribution'] = dash_try('distribution', fn () => dash_chart_distribution());
        $charts['student_growth'] = dash_try('student_growth', fn () => dash_chart_student_growth($ctx));
        $charts['departments'] = dash_try('departments', fn () => dash_chart_departments());
        $charts['gender'] = dash_try('gender', fn () => dash_chart_gender());
    }
    if (can('results') || can('examination')) {
        $charts['exam_performance'] = dash_try('exam_performance', fn () => dash_chart_exam_performance($ctx));
    }
    if (can('placement')) {
        $charts['placement'] = dash_try('placement', fn () => dash_chart_placement($ctx));
    }
    if (can('enquiries')) {
        $lists['recent_enquiries'] = dash_try('recent_enquiries', fn () => dash_list_recent_enquiries());
    }
    if (can('notices')) {
        $lists['recent_notices'] = dash_try('recent_notices', fn () => dash_list_recent_notices());
    }
    $lists['activities'] = dash_try('activities', fn () => dash_list_activities());
    $lists['upcoming'] = dash_try('upcoming', fn () => dash_list_upcoming($ctx));

    $system = can_any(['backup', 'settings', 'security']) ? dash_try('system', fn () => dash_system_status()) : null;
    $live = $ctx['live'];

    return [
        'session' => [
            'id' => $sid, 'name' => $ses['name'], 'start_date' => $ses['start_date'], 'end_date' => $ses['end_date'],
            'is_current' => (bool) $ses['is_current'], 'admissions_open' => (bool) ($ses['admissions_open'] ?? false),
            'previous' => $prev['name'] ?? null,
            // Hero hint: a non-current session without admissions, students or invoices yet -> offer to switch to the live one.
            'has_data' => (bool) (dash_try('has_data', fn () => dash_session_has_data($sid)) ?? true),
            'current_id' => $live ? (int) $live['id'] : null,
            'current_name' => $live['name'] ?? null,
        ],
        'kpis' => (object) $kpis,
        'charts' => (object) array_filter($charts, fn ($v) => $v !== null),
        'lists' => (object) array_filter($lists, fn ($v) => $v !== null),
        'system' => $system,
        'hero' => dash_try('hero', fn () => dash_hero($ctx, $live ? (int) $live['id'] : $sid)),
    ];
}

function dash_session_has_data(?int $sid): bool
{
    if (!$sid) {
        return false;
    }
    foreach (['students', 'student_fees', 'admissions'] as $table) {
        if (db_value("SELECT 1 FROM $table WHERE academic_session_id = ? LIMIT 1", [$sid])) {
            return true;
        }
    }
    return false;
}

/* ------------------------------------------------------------------
 * KPIs
 * ------------------------------------------------------------------ */

function dash_enrolled(?int $sid): ?int
{
    if (!$sid) {
        return null;
    }
    $n = (int) db_value('SELECT COUNT(DISTINCT student_id) FROM student_academic WHERE academic_session_id = ?', [$sid]);
    return $n > 0 ? $n : null;
}

function dash_kpi_total_students(array $c): array
{
    $cur = dash_enrolled($c['sid']);
    if ($cur === null) {
        $cur = (int) db_value('SELECT COUNT(*) FROM students WHERE admission_date IS NULL OR admission_date <= ?', [$c['ses']['end_date']]);
    }
    $prev = $c['prev'] ? dash_enrolled((int) $c['prev']['id']) : null;
    return ['value' => $cur, 'trend' => dash_trend($cur, $prev, 'from last session')];
}

function dash_kpi_active_students(array $c): array
{
    $r = db_row("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'active'), 0) AS active FROM students");
    $active = (int) $r['active'];
    $total = (int) $r['total'];
    return ['value' => $active, 'hint' => $total ? round($active * 100 / $total) . '% of ' . number_in($total) . ' on roll' : null];
}

function dash_kpi_new_admissions(array $c): array
{
    $cur = (int) db_value('SELECT COUNT(*) FROM students WHERE academic_session_id = ?', [$c['sid']]);
    $prev = null;
    if ($c['prev']) {
        $point = dash_same_point($c['ses'], $c['prev'], $c['as_of']);
        $prev = (float) db_value('SELECT COUNT(*) FROM students WHERE academic_session_id = ? AND (admission_date IS NULL OR admission_date <= ?)', [$c['prev']['id'], $point]);
    }
    $thisMonth = (int) db_value('SELECT COUNT(*) FROM students WHERE academic_session_id = ? AND admission_date >= ?', [$c['sid'], date('Y-m-01')]);
    return ['value' => $cur, 'trend' => dash_trend($cur, $prev, 'vs last session'), 'hint' => $thisMonth ? number_in($thisMonth) . ' joined this month' : null];
}

function dash_kpi_pending_applications(array $c): array
{
    $in = "'" . implode("','", DASH_OPEN_STAGES) . "'";
    $cur = (int) db_value("SELECT COUNT(*) FROM admissions WHERE academic_session_id = ? AND stage IN ($in)", [$c['sid']]);
    // Pipeline size one week ago, rebuilt from the stage history.
    $cutoff = date('Y-m-d H:i:s', strtotime('-7 days'));
    $weekAgo = (int) db_value("SELECT COUNT(*) FROM admissions a WHERE a.academic_session_id = ? AND a.created_at < ?
        AND COALESCE((SELECT h.to_stage FROM admission_stage_history h WHERE h.admission_id = a.id AND h.created_at < ? ORDER BY h.created_at DESC, h.id DESC LIMIT 1), 'application') IN ($in)",
        [$c['sid'], $cutoff, $cutoff]);
    return ['value' => $cur, 'trend' => dash_trend($cur, $c['ses']['is_current'] ? (float) $weekAgo : null, 'from last week', false)];
}

function dash_kpi_programs(array $c): array
{
    $r = db_row("SELECT COALESCE(SUM(status = 'active'), 0) AS active, COUNT(DISTINCT CASE WHEN status = 'active' THEN department_id END) AS departments,
                        COUNT(DISTINCT CASE WHEN status = 'active' THEN level END) AS levels FROM programs");
    $active = (int) $r['active'];
    $depts = (int) $r['departments'];
    return ['value' => $active, 'hint' => $active ? 'Across ' . $depts . ' department' . ($depts === 1 ? '' : 's') . ' · ' . (int) $r['levels'] . ' levels' : 'No active programs'];
}

function dash_kpi_faculty(): array
{
    $r = db_row("SELECT COALESCE(SUM(status = 'active'), 0) AS active, COALESCE(SUM(status = 'active' AND joining_date >= ?), 0) AS joined FROM faculty", [date('Y-m-d', strtotime('-30 days'))]);
    $active = (int) $r['active'];
    $joined = (int) $r['joined'];
    return ['value' => $active, 'trend' => dash_trend($active, (float) ($active - $joined), 'this month')];
}

function dash_kpi_staff(): array
{
    $r = db_row("SELECT COALESCE(SUM(status = 'active'), 0) AS active, COUNT(*) AS total FROM staff");
    return ['value' => (int) $r['active'], 'hint' => 'non-teaching staff'];
}

function dash_paid_sql(string $a = 'p'): string
{
    return "$a.status IN ('success', 'refunded')";
}

function dash_kpi_fee_collected(array $c): array
{
    $paid = dash_paid_sql();
    $sum = fn (string $from, string $to) => (float) db_value("SELECT COALESCE(SUM(p.amount + p.fine_amount), 0) FROM payments p WHERE $paid AND p.payment_date BETWEEN ? AND ?", [$from, $to]);
    $total = $sum($c['ses']['start_date'], $c['ses']['end_date']);
    $trend = null;
    if ($c['ses']['is_current']) {
        $day = (int) date('j');
        $prevStart = date('Y-m-01', strtotime('first day of last month'));
        $prevEnd = date('Y-m-d', min(strtotime(date('Y-m-t', strtotime($prevStart))), strtotime($prevStart . ' +' . ($day - 1) . ' days')));
        $thisMonth = $sum(date('Y-m-01'), $c['today']);
        $lastMonth = $sum($prevStart, $prevEnd);
        $trend = dash_trend($thisMonth, $lastMonth, 'this month');
    }
    return ['value' => $total, 'trend' => $trend];
}

function dash_kpi_pending_dues(array $c): array
{
    $r = db_row("SELECT COALESCE(SUM(t.balance_amount), 0) AS pending, COUNT(DISTINCT CASE WHEN t.balance_amount > 0 THEN t.student_id END) AS students,
                        COALESCE(SUM(CASE WHEN t.due_date < CURDATE() THEN t.balance_amount ELSE 0 END), 0) AS overdue
                 FROM student_fees t WHERE t.status NOT IN ('cancelled', 'paid', 'waived') AND t.balance_amount > 0 AND (t.academic_session_id = ? OR ? IS NULL)", [$c['sid'], $c['sid']]);
    $pending = (float) $r['pending'];
    $overdue = (float) $r['overdue'];
    $students = (int) $r['students'];
    $hint = $pending > 0 ? ($overdue > 0 ? money_short($overdue) . ' overdue · ' : '') . number_in($students) . ' student' . ($students === 1 ? '' : 's') : 'No dues outstanding';
    return ['value' => $pending, 'hint' => $hint];
}

/** Attendance aggregate (student sheets) for a date range. */
function dash_attendance_between(string $from, string $to): array
{
    $late = (string) setting('late_counts_as_present', '1') === '1' ? 1 : 0;
    $r = db_row("SELECT COUNT(*) AS sheets, COALESCE(SUM(total_count), 0) AS total, COALESCE(SUM(present_count), 0) AS present, COALESCE(SUM(absent_count), 0) AS absent,
                        COALESCE(SUM(late_count), 0) AS late, COALESCE(SUM(leave_count), 0) AS on_leave, COALESCE(SUM(half_day_count), 0) AS half_day
                 FROM attendance WHERE type = 'student' AND attendance_date BETWEEN ? AND ?", [$from, $to]);
    $total = (int) $r['total'];
    $attended = (int) $r['present'] + $late * (int) $r['late'] + 0.5 * (int) $r['half_day'];
    return [
        'from' => $from, 'to' => $to, 'sheets' => (int) $r['sheets'], 'total' => $total,
        'present' => (int) $r['present'] + (int) $r['half_day'], 'late' => (int) $r['late'], 'absent' => (int) $r['absent'], 'leave' => (int) $r['on_leave'],
        'percent' => $total > 0 ? round($attended * 100 / $total, 1) : null,
    ];
}

/** Latest day with student attendance on or before $date. */
function dash_last_attendance_day(string $date): ?string
{
    $d = db_value("SELECT MAX(attendance_date) FROM attendance WHERE type = 'student' AND attendance_date <= ?", [$date]);
    return $d ?: null;
}

function dash_kpi_attendance(array $c): ?array
{
    $day = dash_last_attendance_day($c['as_of']);
    if (!$day) {
        return ['value' => null, 'date' => null, 'hint' => 'Not marked yet'];
    }
    $cur = dash_attendance_between($day, $day);
    $prevDay = dash_last_attendance_day(date('Y-m-d', strtotime($day . ' -1 day')));
    $prev = $prevDay ? dash_attendance_between($prevDay, $prevDay) : null;
    $isToday = $day === $c['today'];
    $trend = null;
    if ($prev && $prev['percent'] !== null && $cur['percent'] !== null) {
        $diff = round($cur['percent'] - $prev['percent'], 1);
        $label = $isToday && $prevDay === date('Y-m-d', strtotime('-1 day')) ? 'from yesterday' : 'from ' . date('d M', strtotime($prevDay));
        $trend = abs($diff) < 0.05 ? ['value' => '0%', 'dir' => 'flat', 'label' => 'no change', 'positive' => true]
            : ['value' => abs($diff) . ' pts', 'dir' => $diff > 0 ? 'up' : 'down', 'label' => $label, 'positive' => $diff > 0];
    }
    return ['value' => $cur['percent'], 'date' => $day, 'is_today' => $isToday, 'trend' => $trend, 'hint' => $isToday ? null : 'Last marked ' . date('d M', strtotime($day))];
}

function dash_kpi_enquiries(): array
{
    $r = db_row("SELECT COALESCE(SUM(status IN ('new', 'contacted', 'interested')), 0) AS open, COALESCE(SUM(status = 'new'), 0) AS fresh,
                        COALESCE(SUM(follow_up_date IS NOT NULL AND follow_up_date <= CURDATE() AND status IN ('new', 'contacted', 'interested')), 0) AS due
                 FROM enquiries");
    return ['value' => (int) $r['open'], 'hint' => (int) $r['due'] ? number_in((int) $r['due']) . ' follow-ups due' : number_in((int) $r['fresh']) . ' new'];
}

function dash_kpi_exams(array $c): array
{
    $r = db_row("SELECT COALESCE(SUM(start_date > CURDATE()), 0) AS upcoming, COALESCE(SUM(start_date <= CURDATE() AND (end_date IS NULL OR end_date >= CURDATE())), 0) AS ongoing,
                        MIN(CASE WHEN start_date > CURDATE() THEN start_date END) AS next_date
                 FROM exams WHERE status NOT IN ('cancelled', 'draft', 'published')");
    $hint = (int) $r['ongoing'] ? number_in((int) $r['ongoing']) . ' ongoing now' : ($r['next_date'] ? 'Next on ' . date('d M', strtotime($r['next_date'])) : 'None scheduled');
    return ['value' => (int) $r['upcoming'], 'hint' => $hint];
}

function dash_placement_range(array $c): array
{
    $n = (int) db_value("SELECT COUNT(*) FROM placement_offers WHERE status IN ('offered', 'accepted', 'joined') AND offer_date BETWEEN ? AND ?", [$c['ses']['start_date'], $c['ses']['end_date']]);
    return $n > 0 ? ['scope' => 'session', 'from' => $c['ses']['start_date'], 'to' => $c['ses']['end_date']] : ['scope' => 'all', 'from' => '1970-01-01', 'to' => '2999-12-31'];
}

function dash_kpi_placed(array $c): array
{
    $range = dash_placement_range($c);
    $r = db_row("SELECT COUNT(DISTINCT student_id) AS placed, MAX(package_lpa) AS highest FROM placement_offers WHERE status IN ('offered', 'accepted', 'joined') AND offer_date BETWEEN ? AND ?", [$range['from'], $range['to']]);
    $placed = (int) $r['placed'];
    $hint = $placed ? 'Highest ' . rtrim(rtrim(number_format((float) $r['highest'], 1), '0'), '.') . ' LPA' . ($range['scope'] === 'all' ? ' · all batches' : '') : 'No offers yet';
    return ['value' => $placed, 'hint' => $hint];
}

function dash_kpi_alumni(): array
{
    $r = db_row("SELECT COUNT(*) AS total, COALESCE(SUM(is_mentor = 1), 0) AS mentors FROM alumni WHERE status <> 'inactive'");
    return ['value' => (int) $r['total'], 'hint' => (int) $r['mentors'] ? number_in((int) $r['mentors']) . ' mentors' : 'Alumni network'];
}

function dash_kpi_library(): array
{
    $r = db_row("SELECT COUNT(*) AS titles, COALESCE(SUM(total_copies), 0) AS copies, COALESCE(SUM(available_copies), 0) AS available FROM books WHERE status <> 'withdrawn'");
    $copies = (int) $r['copies'];
    return ['value' => $copies ?: (int) $r['titles'], 'hint' => (int) $r['titles'] ? number_in((int) $r['titles']) . ' titles · ' . number_in((int) $r['available']) . ' available' : 'Catalogue empty'];
}

function dash_kpi_transport(): array
{
    $r = db_row("SELECT COALESCE(SUM(status = 'active'), 0) AS active, COALESCE(SUM(status = 'maintenance'), 0) AS maint FROM vehicles");
    return ['value' => (int) $r['active'], 'hint' => (int) $r['maint'] ? number_in((int) $r['maint']) . ' in maintenance' : 'On active routes'];
}

/* ------------------------------------------------------------------
 * Charts
 * ------------------------------------------------------------------ */

/** Last 12 calendar months ending at the session's reference month. */
function dash_months(string $asOf, int $count = 12): array
{
    $months = [];
    $base = strtotime(date('Y-m-01', strtotime($asOf)));
    for ($i = $count - 1; $i >= 0; $i--) {
        $ts = strtotime("-$i months", $base);
        $months[] = ['ym' => date('Y-m', $ts), 'label' => date('M', $ts), 'full' => date('M Y', $ts)];
    }
    return $months;
}

function dash_chart_admissions_trend(array $c): array
{
    $months = dash_months($c['as_of']);
    $from = $months[0]['ym'] . '-01';
    $to = date('Y-m-t', strtotime(end($months)['ym'] . '-01')) . ' 23:59:59';
    $apps = db_pairs("SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) FROM admissions WHERE created_at BETWEEN ? AND ? GROUP BY ym", [$from, $to]);
    $confirmed = db_pairs("SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(DISTINCT admission_id) FROM admission_stage_history WHERE to_stage = 'confirmed' AND created_at BETWEEN ? AND ? GROUP BY ym", [$from, $to]);
    return array_map(fn ($m) => ['month' => $m['ym'], 'label' => $m['label'], 'full' => $m['full'], 'applications' => (int) ($apps[$m['ym']] ?? 0), 'admissions' => (int) ($confirmed[$m['ym']] ?? 0)], $months);
}

function dash_chart_fee_collection(array $c): array
{
    $ses = $c['ses'];
    $paid = dash_paid_sql();
    $months = [];
    $cursor = strtotime(date('Y-m-01', strtotime($ses['start_date'])));
    $end = min(strtotime($ses['end_date']), strtotime(date('Y-m-01', strtotime($c['as_of'] . ' +1 month'))));
    while ($cursor <= $end && count($months) < 12) {
        $months[] = date('Y-m', $cursor);
        $cursor = strtotime('+1 month', $cursor);
    }
    $collected = db_pairs("SELECT DATE_FORMAT(p.payment_date, '%Y-%m') AS ym, SUM(p.amount + p.fine_amount) FROM payments p WHERE $paid AND p.payment_date BETWEEN ? AND ? GROUP BY ym", [$ses['start_date'], $ses['end_date']]);
    $targetInst = db_pairs("SELECT DATE_FORMAT(fi.due_date, '%Y-%m') AS ym, SUM(fi.amount) FROM fee_installments fi JOIN student_fees t ON t.id = fi.student_fee_id
                            WHERE t.status <> 'cancelled' AND (t.academic_session_id = ? OR ? IS NULL) GROUP BY ym", [$c['sid'], $c['sid']]);
    $targetFlat = db_pairs("SELECT DATE_FORMAT(t.due_date, '%Y-%m') AS ym, SUM(t.net_amount) FROM student_fees t WHERE t.status <> 'cancelled' AND (t.academic_session_id = ? OR ? IS NULL)
                            AND NOT EXISTS (SELECT 1 FROM fee_installments fi WHERE fi.student_fee_id = t.id) GROUP BY ym", [$c['sid'], $c['sid']]);
    // Targets due before the first charted month are folded into the first month.
    $first = $months[0] ?? null;
    $carry = 0.0;
    foreach ([$targetInst, $targetFlat] as $set) {
        foreach ($set as $ym => $v) {
            if ($first && $ym < $first) {
                $carry += (float) $v;
            }
        }
    }
    $rows = [];
    foreach ($months as $i => $ym) {
        $rows[] = [
            'month' => $ym, 'label' => date('M', strtotime($ym . '-01')), 'full' => date('M Y', strtotime($ym . '-01')),
            'collected' => round((float) ($collected[$ym] ?? 0), 2),
            'target' => round((float) ($targetInst[$ym] ?? 0) + (float) ($targetFlat[$ym] ?? 0) + ($i === 0 ? $carry : 0), 2),
        ];
    }
    $billed = (float) db_value("SELECT COALESCE(SUM(net_amount), 0) FROM student_fees WHERE status <> 'cancelled' AND (academic_session_id = ? OR ? IS NULL)", [$c['sid'], $c['sid']]);
    return ['months' => $rows, 'collected' => round(array_sum(array_column($rows, 'collected')), 2), 'billed' => $billed];
}

function dash_chart_attendance(array $c): ?array
{
    $day = dash_last_attendance_day($c['as_of']);
    if (!$day) {
        return null;
    }
    $weekStart = date('Y-m-d', strtotime('monday this week', strtotime($day)));
    return [
        'day' => dash_attendance_between($day, $day) + ['label' => $day === $c['today'] ? 'Today' : date('d M Y', strtotime($day))],
        'week' => dash_attendance_between($weekStart, $day) + ['label' => 'Week of ' . date('d M', strtotime($weekStart))],
        'month' => dash_attendance_between(date('Y-m-01', strtotime($day)), $day) + ['label' => date('F Y', strtotime($day))],
    ];
}

function dash_chart_distribution(): array
{
    $cat = db_all("SELECT COALESCE(NULLIF(p.category, ''), 'Others') AS label, COUNT(*) AS value FROM students s LEFT JOIN programs p ON p.id = s.program_id
                   WHERE s.status = 'active' GROUP BY label ORDER BY value DESC");
    $lvl = db_all("SELECT COALESCE(NULLIF(p.level, ''), 'Others') AS label, COUNT(*) AS value FROM students s LEFT JOIN programs p ON p.id = s.program_id
                   WHERE s.status = 'active' GROUP BY label ORDER BY value DESC");
    $levelNames = ['UG' => 'Undergraduate', 'PG' => 'Postgraduate'];
    $norm = fn (array $rows, array $names = []) => array_map(fn ($r) => ['label' => $names[$r['label']] ?? $r['label'], 'value' => (int) $r['value']], $rows);
    // Collapse a long tail into "Others" so the doughnut stays readable.
    // An existing "Others" bucket (programs without a category) is merged into the tail so the label appears once.
    $collapse = function (array $rows, int $keep = 6): array {
        $others = array_sum(array_column(array_filter($rows, fn ($r) => $r['label'] === 'Others'), 'value'));
        $rows = array_values(array_filter($rows, fn ($r) => $r['label'] !== 'Others'));
        if (count($rows) + ($others > 0 ? 1 : 0) > $keep) {
            $others += array_sum(array_column(array_slice($rows, $keep - 1), 'value'));
            $rows = array_slice($rows, 0, $keep - 1);
        }
        if ($others > 0) {
            $rows[] = ['label' => 'Others', 'value' => $others];
        }
        return $rows;
    };
    return ['category' => $collapse($norm($cat)), 'level' => $collapse($norm($lvl, $levelNames))];
}

function dash_chart_student_growth(array $c): array
{
    $sessions = db_all('SELECT id, name, start_date FROM academic_sessions WHERE start_date <= ? ORDER BY start_date DESC LIMIT 5', [$c['ses']['start_date']]);
    $sessions = array_reverse($sessions);
    $ids = array_map(fn ($s) => (int) $s['id'], $sessions);
    if (!$ids) {
        return [];
    }
    $in = implode(',', $ids);
    $intake = db_pairs("SELECT academic_session_id, COUNT(*) FROM students WHERE academic_session_id IN ($in) GROUP BY academic_session_id");
    $enrolled = db_pairs("SELECT academic_session_id, COUNT(DISTINCT student_id) FROM student_academic WHERE academic_session_id IN ($in) GROUP BY academic_session_id");
    $rows = [];
    $running = 0;
    foreach ($sessions as $s) {
        $new = (int) ($intake[$s['id']] ?? 0);
        $running += $new;
        $rows[] = ['label' => $s['name'], 'new' => $new, 'total' => (int) ($enrolled[$s['id']] ?? $running)];
    }
    return $rows;
}

function dash_chart_departments(): array
{
    $rows = db_all("SELECT d.id, d.name, d.code, COUNT(s.id) AS value FROM departments d LEFT JOIN students s ON s.department_id = d.id AND s.status = 'active'
                    WHERE d.status = 'active' GROUP BY d.id, d.name, d.code HAVING value > 0 ORDER BY value DESC");
    return array_map(fn ($r) => ['label' => dash_short_department($r['name']), 'code' => $r['code'], 'value' => (int) $r['value']], $rows);
}

function dash_chart_gender(): array
{
    $rows = db_pairs("SELECT COALESCE(NULLIF(gender, ''), 'unspecified') AS g, COUNT(*) FROM students WHERE status = 'active' GROUP BY g ORDER BY COUNT(*) DESC");
    $out = [];
    foreach ($rows as $g => $n) {
        $out[] = ['label' => ucfirst((string) $g), 'value' => (int) $n];
    }
    return $out;
}

function dash_chart_exam_performance(array $c): array
{
    $where = 'r.academic_session_id = ?';
    $args = [$c['sid']];
    $published = (int) db_value("SELECT COUNT(*) FROM results r WHERE $where AND r.is_published = 1", $args);
    if ($published > 0) {
        $where .= ' AND r.is_published = 1';
    }
    $rows = db_all("SELECT p.id, COALESCE(NULLIF(p.short_name, ''), p.name) AS label, COUNT(*) AS students, COALESCE(SUM(r.result_status = 'PASS'), 0) AS passed,
                           ROUND(AVG(r.percentage), 1) AS avg_percent
                    FROM results r JOIN programs p ON p.id = r.program_id WHERE $where GROUP BY p.id, label, p.sort_order ORDER BY p.sort_order, label", $args);
    $total = array_sum(array_map(fn ($r) => (int) $r['students'], $rows));
    $passed = array_sum(array_map(fn ($r) => (int) $r['passed'], $rows));
    return [
        'scope' => $published > 0 ? 'published' : 'computed',
        'students' => $total, 'pass_percent' => $total ? round($passed * 100 / $total, 1) : null,
        'programs' => array_map(fn ($r) => [
            'label' => $r['label'], 'students' => (int) $r['students'], 'pass_percent' => (int) $r['students'] ? round((int) $r['passed'] * 100 / (int) $r['students'], 1) : 0,
            'avg_percent' => $r['avg_percent'] !== null ? (float) $r['avg_percent'] : 0,
        ], $rows),
    ];
}

function dash_chart_placement(array $c): array
{
    $range = dash_placement_range($c);
    $ok = "o.status IN ('offered', 'accepted', 'joined')";
    $sum = db_row("SELECT COUNT(*) AS offers, COUNT(DISTINCT o.student_id) AS placed, COUNT(DISTINCT o.company_id) AS companies, MAX(o.package_lpa) AS highest, ROUND(AVG(o.package_lpa), 2) AS average
                   FROM placement_offers o WHERE $ok AND o.offer_date BETWEEN ? AND ?", [$range['from'], $range['to']]);
    $drives = (int) db_value('SELECT COUNT(*) FROM placement_drives WHERE status <> ? AND (drive_date IS NULL OR drive_date BETWEEN ? AND ?)', ['cancelled', $range['from'], $range['to']]);
    $programs = db_all("SELECT COALESCE(NULLIF(p.short_name, ''), p.name, 'Other') AS label, COUNT(DISTINCT o.student_id) AS placed, ROUND(AVG(o.package_lpa), 2) AS avg_lpa
                        FROM placement_offers o LEFT JOIN students s ON s.id = o.student_id LEFT JOIN programs p ON p.id = s.program_id
                        WHERE $ok AND o.offer_date BETWEEN ? AND ? GROUP BY label ORDER BY placed DESC LIMIT 8", [$range['from'], $range['to']]);
    $companies = db_all("SELECT c.name AS label, COUNT(*) AS offers FROM placement_offers o JOIN companies c ON c.id = o.company_id
                         WHERE $ok AND o.offer_date BETWEEN ? AND ? GROUP BY c.id, c.name ORDER BY offers DESC LIMIT 5", [$range['from'], $range['to']]);
    return [
        'scope' => $range['scope'], 'offers' => (int) $sum['offers'], 'placed' => (int) $sum['placed'], 'companies' => (int) $sum['companies'], 'drives' => $drives,
        'highest' => $sum['highest'] !== null ? (float) $sum['highest'] : null, 'average' => $sum['average'] !== null ? (float) $sum['average'] : null,
        'programs' => array_map(fn ($r) => ['label' => $r['label'], 'placed' => (int) $r['placed'], 'avg_lpa' => (float) $r['avg_lpa']], $programs),
        'top_companies' => array_map(fn ($r) => ['label' => $r['label'], 'offers' => (int) $r['offers']], $companies),
    ];
}

/* ------------------------------------------------------------------
 * Lists
 * ------------------------------------------------------------------ */

function dash_list_top_programs(array $c): array
{
    $admitted = db_all("SELECT p.id, COALESCE(NULLIF(p.short_name, ''), p.name) AS label, p.name, p.category, COUNT(s.id) AS value
                        FROM programs p JOIN students s ON s.program_id = p.id AND s.academic_session_id = ?
                        GROUP BY p.id, label, p.name, p.category ORDER BY value DESC, p.sort_order LIMIT 6", [$c['sid']]);
    $apps = can('admissions') ? db_all("SELECT p.id, COALESCE(NULLIF(p.short_name, ''), p.name) AS label, p.name, p.category, COUNT(a.id) AS value
                        FROM programs p JOIN admissions a ON a.program_id = p.id AND a.academic_session_id = ?
                        GROUP BY p.id, label, p.name, p.category ORDER BY value DESC, p.sort_order LIMIT 6", [$c['sid']]) : null;
    $norm = fn ($rows) => array_map(fn ($r) => ['id' => (int) $r['id'], 'label' => $r['label'], 'name' => $r['name'], 'category' => $r['category'], 'value' => (int) $r['value']], $rows);
    return ['admitted' => $norm($admitted), 'applications' => $apps === null ? null : $norm($apps)];
}

function dash_list_recent_admissions(array $c): array
{
    $rows = db_all("SELECT a.id, a.application_no, TRIM(CONCAT_WS(' ', a.first_name, a.last_name)) AS name, a.photo, a.stage, a.source, a.created_at,
                           COALESCE(NULLIF(p.short_name, ''), p.name) AS program
                    FROM admissions a LEFT JOIN programs p ON p.id = a.program_id ORDER BY a.created_at DESC, a.id DESC LIMIT 6");
    return array_map(fn ($r) => ['id' => (int) $r['id'], 'application_no' => $r['application_no'], 'name' => $r['name'], 'photo' => $r['photo'] ? upload_url($r['photo']) : null,
        'stage' => $r['stage'], 'source' => $r['source'], 'program' => $r['program'], 'created_at' => $r['created_at']], $rows);
}

function dash_list_recent_enquiries(): array
{
    $rows = db_all("SELECT e.id, e.name, e.phone, e.city, e.source, e.status, e.priority, e.created_at, COALESCE(NULLIF(p.short_name, ''), p.name, e.program_interest) AS program
                    FROM enquiries e LEFT JOIN programs p ON p.id = e.program_id ORDER BY e.created_at DESC, e.id DESC LIMIT 6");
    return array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'phone' => $r['phone'], 'city' => $r['city'], 'source' => $r['source'], 'status' => $r['status'],
        'priority' => $r['priority'], 'program' => $r['program'], 'created_at' => $r['created_at']], $rows);
}

function dash_list_recent_payments(): array
{
    $rows = db_all("SELECT p.id, p.receipt_no, p.amount + p.fine_amount AS amount, p.mode, p.payment_date, p.status, p.created_at, p.student_id,
                           TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.photo, COALESCE(NULLIF(pr.short_name, ''), pr.name) AS program,
                           TRIM(CONCAT_WS(' ', a.first_name, a.last_name)) AS applicant_name
                    FROM payments p LEFT JOIN students s ON s.id = p.student_id LEFT JOIN programs pr ON pr.id = s.program_id LEFT JOIN admissions a ON a.id = p.admission_id
                    ORDER BY p.payment_date DESC, p.id DESC LIMIT 6");
    return array_map(fn ($r) => ['id' => (int) $r['id'], 'receipt_no' => $r['receipt_no'], 'amount' => (float) $r['amount'], 'mode' => $r['mode'], 'payment_date' => $r['payment_date'],
        'status' => $r['status'], 'created_at' => $r['created_at'], 'student_id' => $r['student_id'] ? (int) $r['student_id'] : null,
        'name' => ($r['student_name'] ?: $r['applicant_name']) ?: 'Walk-in payment', 'photo' => $r['photo'] ? upload_url($r['photo']) : null, 'program' => $r['program']], $rows);
}

function dash_list_recent_notices(): array
{
    $rows = db_all("SELECT id, title, category, audience, status, is_pinned, publish_date, created_at FROM notices
                    ORDER BY is_pinned DESC, COALESCE(publish_date, created_at) DESC, id DESC LIMIT 6");
    return array_map(fn ($r) => ['id' => (int) $r['id'], 'title' => $r['title'], 'category' => $r['category'], 'audience' => $r['audience'], 'status' => $r['status'],
        'is_pinned' => (bool) $r['is_pinned'], 'date' => $r['publish_date'] ?: $r['created_at']], $rows);
}

/** Recent meaningful audit-trail entries (no sign-ins / page views), limited to modules the user can see. */
function dash_list_activities(): array
{
    $where = "a.action NOT IN ('login', 'logout', 'denied', 'view', 'print', 'ping') AND a.status = 'success' AND a.module NOT IN ('messages', 'profile')";
    $args = [];
    if (!can('activity_logs')) {
        $visible = [];
        foreach (array_keys(permission_modules()) as $m) {
            if (can($m)) {
                $visible[] = $m;
            }
        }
        $place = $visible ? implode(',', array_fill(0, count($visible), '?')) : "''";
        $where .= " AND (a.user_id = ? OR a.module IN ($place))";
        $args = array_merge([user_id()], $visible);
    }
    $labels = function_exists('activity_module_labels') ? activity_module_labels() : [];
    $rows = db_all("SELECT a.id, a.action, a.module, a.record_id, a.description, a.created_at, a.user_id, u.name AS user_name, u.avatar
                    FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id WHERE $where ORDER BY a.created_at DESC, a.id DESC LIMIT 8", $args);
    return array_map(fn ($r) => [
        'id' => (int) $r['id'], 'action' => $r['action'], 'module' => $r['module'], 'module_label' => $labels[$r['module']] ?? label_from_key((string) $r['module']),
        'record_id' => $r['record_id'], 'description' => $r['description'] ?: label_from_key($r['action']) . ' ' . label_from_key($r['module']),
        'user' => $r['user_name'] ?: 'System', 'avatar' => $r['avatar'] ? upload_url($r['avatar']) : null, 'created_at' => $r['created_at'],
    ], $rows);
}

/** Group rows by a date column, keeping order. */
function dash_group_by_date(array $rows, string $col): array
{
    $out = [];
    foreach ($rows as $r) {
        $out[(string) $r[$col]][] = $r;
    }
    return $out;
}

/** "MCA Sem 3, MBA Sem 1 +2 more" from exam names like "MCA Sem 3 — Mid Term Examination (Sep 2026)". */
function dash_exam_names(array $group, int $show = 2): string
{
    $names = array_map(fn ($e) => trim((string) preg_split('/\s+[—–-]\s+/u', (string) $e['name'])[0]), $group);
    $names = array_values(array_unique($names));
    $more = count($names) - $show;
    return implode(', ', array_slice($names, 0, $show)) . ($more > 0 ? ' +' . $more . ' more' : '');
}

/** Upcoming events & deadlines across modules (next 60 days), coloured by category. */
function dash_list_upcoming(array $c): array
{
    $today = $c['today'];
    $until = date('Y-m-d', strtotime('+60 days'));
    $items = [];
    if (can('events')) {
        foreach (db_all("SELECT id, title, category, venue, start_datetime FROM events WHERE status = 'published' AND start_datetime >= ? AND start_datetime <= ? ORDER BY start_datetime LIMIT 6",
            [$today . ' 00:00:00', $until . ' 23:59:59']) as $r) {
            $items[] = ['date' => substr($r['start_datetime'], 0, 10), 'time' => substr($r['start_datetime'], 11, 5), 'title' => $r['title'],
                'subtitle' => trim(label_from_key((string) $r['category']) . ($r['venue'] ? ' · ' . $r['venue'] : '')), 'category' => 'event', 'url' => '/events?q=' . rawurlencode($r['title'])];
        }
    }
    if (can('examination')) {
        $exams = db_all("SELECT e.id, e.name, e.start_date, e.end_date FROM exams e
                         WHERE e.status NOT IN ('cancelled', 'draft', 'published') AND e.start_date BETWEEN ? AND ? ORDER BY e.start_date, e.id LIMIT 60", [$today, $until]);
        foreach (dash_group_by_date($exams, 'start_date') as $date => $group) {
            $end = max(array_map(fn ($e) => (string) $e['end_date'], $group));
            $span = $end && $end !== $date ? date('d M', strtotime($date)) . ' – ' . date('d M', strtotime($end)) : date('d M', strtotime($date));
            $items[] = count($group) === 1
                ? ['date' => $date, 'title' => $group[0]['name'], 'subtitle' => 'Examination · ' . $span, 'category' => 'exam', 'url' => '/examination/' . (int) $group[0]['id']]
                : ['date' => $date, 'title' => count($group) . ' examinations begin', 'subtitle' => dash_exam_names($group) . ' · ' . $span, 'category' => 'exam', 'url' => '/exam-schedule'];
        }
        foreach (db_all("SELECT holiday_date, end_date, title FROM holidays WHERE type = 'exam_break' AND holiday_date BETWEEN ? AND ? ORDER BY holiday_date LIMIT 3", [$today, $until]) as $r) {
            $items[] = ['date' => $r['holiday_date'], 'title' => $r['title'], 'subtitle' => 'Academic calendar' . ($r['end_date'] ? ' · till ' . date('d M', strtotime($r['end_date'])) : ''),
                'category' => 'exam', 'url' => '/attendance?tab=holidays'];
        }
    }
    if (can('results')) {
        $results = db_all("SELECT e.id, e.name, e.result_date FROM exams e WHERE e.status NOT IN ('cancelled', 'published') AND e.result_date BETWEEN ? AND ? ORDER BY e.result_date, e.id LIMIT 60", [$today, $until]);
        foreach (dash_group_by_date($results, 'result_date') as $date => $group) {
            $items[] = ['date' => $date, 'title' => 'Result Declaration' . (count($group) > 1 ? ' · ' . count($group) . ' exams' : ''), 'subtitle' => dash_exam_names($group), 'category' => 'result', 'url' => '/results'];
        }
    }
    if (can('fees')) {
        foreach (db_all("SELECT d.due_date, COUNT(*) AS invoices, SUM(d.balance) AS balance FROM (
                            SELECT fi.due_date, fi.amount - fi.paid_amount AS balance FROM fee_installments fi JOIN student_fees t ON t.id = fi.student_fee_id
                            WHERE t.status NOT IN ('cancelled', 'paid', 'waived') AND fi.paid_amount < fi.amount AND fi.due_date BETWEEN ? AND ?
                            UNION ALL
                            SELECT t.due_date, t.balance_amount FROM student_fees t WHERE t.status NOT IN ('cancelled', 'paid', 'waived') AND t.balance_amount > 0 AND t.due_date BETWEEN ? AND ?
                              AND NOT EXISTS (SELECT 1 FROM fee_installments fx WHERE fx.student_fee_id = t.id)
                         ) d GROUP BY d.due_date HAVING COUNT(*) >= 3 ORDER BY COUNT(*) DESC, d.due_date LIMIT 2", [$today, $until, $today, $until]) as $r) {
            $items[] = ['date' => $r['due_date'], 'title' => 'Fee Payment Due Date', 'subtitle' => number_in((int) $r['invoices']) . ' invoice' . ((int) $r['invoices'] === 1 ? '' : 's') . ' · ' . money_short((float) $r['balance']) . ' pending',
                'category' => 'finance', 'url' => '/fees/invoices'];
        }
    }
    if (can('admissions')) {
        foreach (db_all("SELECT DATE(interview_date) AS d, COUNT(*) AS n FROM admissions WHERE stage = 'entrance_interview' AND interview_date BETWEEN ? AND ? GROUP BY DATE(interview_date) ORDER BY d LIMIT 3",
            [$today . ' 00:00:00', $until . ' 23:59:59']) as $r) {
            $items[] = ['date' => $r['d'], 'title' => 'Entrance / Interview Round', 'subtitle' => number_in((int) $r['n']) . ' applicant' . ((int) $r['n'] === 1 ? '' : 's') . ' scheduled',
                'category' => 'admission', 'url' => '/admissions/list?f.stage=entrance_interview'];
        }
        // Application deadline published on the website (Settings: admissions_deadline / admission_last_date).
        foreach (['admissions_deadline', 'admission_last_date'] as $settingKey) {
            $raw = trim((string) setting($settingKey, ''));
            $ts = $raw !== '' ? strtotime($raw) : false;
            if ($ts) {
                $date = date('Y-m-d', $ts);
                if ($date >= $today && $date <= $until) {
                    $open = (int) db_value("SELECT COUNT(*) FROM admissions WHERE academic_session_id = ? AND stage IN ('" . implode("','", DASH_OPEN_STAGES) . "')", [$c['sid']]);
                    $items[] = ['date' => $date, 'title' => 'Last Date for Application', 'subtitle' => 'Session ' . $c['ses']['name'] . ' · ' . number_in($open) . ' applications in progress',
                        'category' => 'admission', 'url' => '/admissions/list'];
                }
                break;
            }
        }
        $next = db_row('SELECT name, start_date FROM academic_sessions WHERE start_date > ? AND admissions_open = 1 ORDER BY start_date LIMIT 1', [$c['ses']['start_date']]);
        if ($next && $next['start_date'] >= $today && $next['start_date'] <= date('Y-m-d', strtotime('+270 days'))) {
            $items[] = ['date' => $next['start_date'], 'title' => 'Session ' . $next['name'] . ' begins', 'subtitle' => 'Admissions open · UG & PG programs', 'category' => 'admission', 'url' => '/admissions'];
        }
    }
    if (can('placement')) {
        foreach (db_all("SELECT d.id, d.title, d.drive_date, c.name AS company FROM placement_drives d LEFT JOIN companies c ON c.id = d.company_id
                         WHERE d.status IN ('upcoming', 'open') AND d.drive_date BETWEEN ? AND ? ORDER BY d.drive_date LIMIT 3", [$today, $until]) as $r) {
            $items[] = ['date' => $r['drive_date'], 'title' => $r['title'], 'subtitle' => ($r['company'] ?: 'Campus drive') . ' · Placement drive', 'category' => 'placement', 'url' => '/placement/drives'];
        }
    }
    if (can('attendance') || can('academics')) {
        foreach (db_all("SELECT holiday_date, title, type FROM holidays WHERE type <> 'exam_break' AND holiday_date BETWEEN ? AND ? ORDER BY holiday_date LIMIT 3", [$today, $until]) as $r) {
            $items[] = ['date' => $r['holiday_date'], 'title' => $r['title'], 'subtitle' => label_from_key((string) $r['type']) . ' · Campus closed', 'category' => 'holiday', 'url' => '/attendance?tab=holidays'];
        }
    }
    return dash_pick_upcoming($items, 6);
}

/**
 * Pick $limit items so that one busy category (e.g. many result dates) cannot crowd out the others: round-robin over
 * the categories (earliest first within each), then show the picks in date order.
 */
function dash_pick_upcoming(array $items, int $limit): array
{
    $key = fn (array $i) => $i['date'] . ' ' . ($i['time'] ?? '');
    usort($items, fn ($a, $b) => strcmp($key($a), $key($b)));
    $queues = [];
    foreach ($items as $i) {
        $queues[$i['category']][] = $i;
    }
    // When more categories compete than slots remain, the mockup's core deadlines win over holidays.
    $priority = ['admission' => 0, 'exam' => 1, 'finance' => 2, 'event' => 3, 'result' => 4, 'placement' => 5, 'holiday' => 6];
    $picked = [];
    while (count($picked) < $limit && $queues) {
        $cats = array_keys($queues);
        $slots = $limit - count($picked);
        if (count($cats) > $slots) {
            usort($cats, fn ($a, $b) => ($priority[$a] ?? 9) <=> ($priority[$b] ?? 9) ?: strcmp($key($queues[$a][0]), $key($queues[$b][0])));
            $cats = array_slice($cats, 0, $slots);
        }
        foreach ($cats as $cat) {
            $picked[] = array_shift($queues[$cat]);
            if (!$queues[$cat]) {
                unset($queues[$cat]);
            }
        }
    }
    usort($picked, fn ($a, $b) => strcmp($key($a), $key($b)));
    return $picked;
}

/* ------------------------------------------------------------------
 * System status
 * ------------------------------------------------------------------ */

function dash_dir_size(string $dir, int $limit = 30000): array
{
    $files = 0;
    $bytes = 0;
    if (is_dir($dir)) {
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $files++;
                    $bytes += $f->getSize();
                    if ($files >= $limit) {
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            // unreadable directory: report what was counted
        }
    }
    return ['files' => $files, 'bytes' => $bytes];
}

function dash_system_status(): array
{
    $db = ['status' => 'connected', 'version' => null, 'size' => null, 'tables' => null];
    try {
        $db['version'] = (string) db_value('SELECT VERSION()');
        $r = db_row('SELECT COUNT(*) AS tables, COALESCE(SUM(data_length + index_length), 0) AS size FROM information_schema.tables WHERE table_schema = DATABASE()');
        $db['size'] = (int) $r['size'];
        $db['tables'] = (int) $r['tables'];
    } catch (Throwable $e) {
        $db['status'] = 'error';
    }

    $last = db_row("SELECT id, filename, type, size_bytes, status, created_at FROM backups WHERE status IN ('completed', 'restored') ORDER BY created_at DESC, id DESC LIMIT 1");
    $failed = db_row("SELECT created_at FROM backups WHERE status = 'failed' ORDER BY created_at DESC LIMIT 1");
    $ageDays = $last ? (int) floor((time() - strtotime($last['created_at'])) / 86400) : null;
    $backup = [
        'last_at' => $last['created_at'] ?? null, 'filename' => $last['filename'] ?? null, 'type' => $last['type'] ?? null,
        'size' => $last ? (int) $last['size_bytes'] : null, 'age_days' => $ageDays,
        'status' => !$last ? 'missing' : ($ageDays > 7 ? 'stale' : 'ok'),
        'last_failed_at' => $failed && (!$last || $failed['created_at'] > $last['created_at']) ? $failed['created_at'] : null,
        'schedule' => setting('auto_backup', 'off'),
    ];

    $storage = dash_cache_read('dashboard-storage', 600);
    if (!$storage) {
        $uploads = dash_dir_size(UPLOAD_PATH);
        $store = dash_dir_size(STORAGE_PATH);
        $total = @disk_total_space(APP_ROOT) ?: null;
        $free = @disk_free_space(APP_ROOT) ?: null;
        $storage = [
            'uploads' => $uploads['bytes'], 'upload_files' => $uploads['files'], 'storage' => $store['bytes'], 'used' => $uploads['bytes'] + $store['bytes'],
            'disk_total' => $total ? (int) $total : null, 'disk_free' => $free ? (int) $free : null,
            'disk_percent' => $total && $free !== null ? round(($total - $free) * 100 / $total, 1) : null,
            'measured_at' => date('Y-m-d H:i:s'),
        ];
        dash_cache_write('dashboard-storage', $storage);
    }

    $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
    return [
        'server' => ['status' => 'online', 'php' => PHP_VERSION, 'software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI), 'os' => PHP_OS_FAMILY,
            'load' => is_array($load) ? round((float) $load[0], 2) : null, 'time' => date('Y-m-d H:i:s'), 'timezone' => date_default_timezone_get(), 'app_version' => APP_VERSION],
        'database' => $db,
        'backup' => $backup,
        'storage' => $storage,
    ];
}

/* ------------------------------------------------------------------
 * KPI sparklines (6 points each)
 * ------------------------------------------------------------------ */

/**
 * Six consecutive date buckets. 'month' / 'week' buckets end with the one holding $anchor, or start with it when
 * $forward is true. Each bucket: ['from' => 'Y-m-d', 'to' => 'Y-m-d', 'label' => 'Sep' | '29 Sep'].
 */
function dash_buckets(string $unit, string $anchor, int $n = 6, bool $forward = false): array
{
    $out = [];
    $a = strtotime($anchor);
    for ($i = 0; $i < $n; $i++) {
        $k = $forward ? $i : $i - ($n - 1);
        if ($unit === 'month') {
            $start = strtotime(sprintf('%+d months', $k), strtotime(date('Y-m-01', $a)));
            $out[] = ['from' => date('Y-m-01', $start), 'to' => date('Y-m-t', $start), 'label' => date('M', $start)];
        } else {
            $from = $forward ? strtotime(sprintf('%+d days', 7 * $k), $a) : strtotime(sprintf('%+d days', 7 * $k - 6), $a);
            $to = strtotime('+6 days', $from);
            $out[] = ['from' => date('Y-m-d', $from), 'to' => date('Y-m-d', $to), 'label' => date('d M', $from)];
        }
    }
    return $out;
}

/**
 * One aggregate query returning a value per bucket: SUM($expr) of the rows whose $col falls in the bucket, or with
 * $cumulative every row up to the bucket's end (running totals such as "students on roll at month-end").
 */
function dash_series(string $from, string $where, array $args, string $col, array $buckets, string $expr = '1', bool $cumulative = false, bool $datetime = false): array
{
    $lo = fn (string $d) => $datetime ? $d . ' 00:00:00' : $d;
    $hi = fn (string $d) => $datetime ? $d . ' 23:59:59' : $d;
    $cols = [];
    $params = [];
    foreach ($buckets as $i => $b) {
        if ($cumulative) {
            $cols[] = "COALESCE(SUM(CASE WHEN $col <= ? THEN $expr ELSE 0 END), 0) AS b$i";
            $params[] = $hi($b['to']);
        } else {
            $cols[] = "COALESCE(SUM(CASE WHEN $col BETWEEN ? AND ? THEN $expr ELSE 0 END), 0) AS b$i";
            $params[] = $lo($b['from']);
            $params[] = $hi($b['to']);
        }
    }
    $sql = 'SELECT ' . implode(', ', $cols) . " FROM $from WHERE ($where)";
    $params = array_merge($params, $args);
    if ($cumulative) {
        $sql .= " AND $col <= ?";
        $params[] = $hi(end($buckets)['to']);
    } else {
        $sql .= " AND $col BETWEEN ? AND ?";
        $params[] = $lo($buckets[0]['from']);
        $params[] = $hi(end($buckets)['to']);
    }
    $row = db_row($sql, $params) ?: [];
    return array_map(fn ($i) => (float) ($row["b$i"] ?? 0), array_keys($buckets));
}

/** Sparkline for one KPI: ['points' => [6 numbers], 'labels' => [6], 'caption' => 'what is plotted', 'format' => number|money|percent]. */
function dash_spark(string $key, array $c): ?array
{
    $sid = $c['sid'];
    $months = dash_buckets('month', $c['as_of']);
    $weeks = dash_buckets('week', $c['today']);
    $mk = fn (array $b, array $points, string $caption, string $format = 'number') => [
        'points' => array_map(fn ($v) => round((float) $v, $format === 'money' ? 0 : 1), $points),
        'labels' => array_column($b, 'label'), 'caption' => $caption, 'format' => $format,
    ];
    $paid = dash_paid_sql();
    switch ($key) {
        case 'total_students':
            return $mk($months, dash_series('students', '1 = 1', [], 'COALESCE(admission_date, DATE(created_at))', $months, '1', true), 'Students on roll at month-end');
        case 'active_students':
            return $mk($months, dash_series('students', "status = 'active'", [], 'COALESCE(admission_date, DATE(created_at))', $months, '1', true), 'Active students at month-end');
        case 'new_admissions':
            return $sid ? $mk($months, dash_series('students', 'academic_session_id = ?', [$sid], 'admission_date', $months), 'Students admitted per month') : null;
        case 'pending_applications':
            return $sid ? $mk($weeks, dash_series('admissions', 'academic_session_id = ?', [$sid], 'created_at', $weeks, '1', false, true), 'Applications received per week') : null;
        case 'faculty':
            return $mk($months, dash_series('faculty', "status = 'active'", [], "COALESCE(joining_date, '1970-01-01')", $months, '1', true), 'Faculty at month-end');
        case 'staff':
            return $mk($months, dash_series('staff', "status = 'active'", [], "COALESCE(joining_date, '1970-01-01')", $months, '1', true), 'Staff at month-end');
        case 'fee_collected':
            return $mk($months, dash_series('payments p', $paid, [], 'p.payment_date', $months, 'p.amount + p.fine_amount'), 'Fees collected per month', 'money');
        case 'pending_dues':
            if (!$sid) {
                return null;
            }
            $open = "t.status NOT IN ('cancelled', 'waived') AND t.academic_session_id = ?";
            $billed = dash_series('student_fees t', $open, [$sid], 'DATE(t.created_at)', $months, 't.net_amount', true);
            $received = dash_series('payments p JOIN student_fees t ON t.id = p.student_fee_id', "$paid AND $open", [$sid], 'p.payment_date', $months, 'p.amount', true);
            return $mk($months, array_map(fn ($b, $r) => max(0, $b - $r), $billed, $received), 'Outstanding at month-end', 'money');
        case 'attendance_today':
            $days = array_reverse(db_column("SELECT DISTINCT attendance_date FROM attendance WHERE type = 'student' AND attendance_date <= ? ORDER BY attendance_date DESC LIMIT 6", [$c['today']]));
            if (count($days) < 2) {
                return null;
            }
            $late = (string) setting('late_counts_as_present', '1') === '1' ? 1 : 0;
            $place = implode(',', array_fill(0, count($days), '?'));
            $rows = db_all("SELECT attendance_date AS d, SUM(total_count) AS total, SUM(present_count) AS present, SUM(late_count) AS late, SUM(half_day_count) AS half
                            FROM attendance WHERE type = 'student' AND attendance_date IN ($place) GROUP BY attendance_date", $days);
            $by = [];
            foreach ($rows as $r) {
                $by[$r['d']] = (int) $r['total'] > 0 ? ((int) $r['present'] + $late * (int) $r['late'] + 0.5 * (int) $r['half']) * 100 / (int) $r['total'] : 0;
            }
            return ['points' => array_map(fn ($d) => round((float) ($by[$d] ?? 0), 1), $days), 'labels' => array_map(fn ($d) => date('d M', strtotime($d)), $days),
                'caption' => 'Last 6 marked days', 'format' => 'percent'];
        case 'enquiries_open':
            return $mk($weeks, dash_series('enquiries', '1 = 1', [], 'created_at', $weeks, '1', false, true), 'Enquiries received per week');
        case 'upcoming_exams':
            $ahead = dash_buckets('week', $c['today'], 6, true);
            return $mk($ahead, dash_series('exam_schedules es JOIN exams e ON e.id = es.exam_id', "e.status NOT IN ('cancelled', 'draft')", [], 'es.exam_date', $ahead), 'Papers per week, next 6 weeks');
        case 'placed_students':
            return $mk($months, dash_series('placement_offers', "status IN ('offered', 'accepted', 'joined')", [], 'offer_date', $months), 'Offers per month');
        case 'library_books':
            return $mk($weeks, dash_series('library_transactions', '1 = 1', [], 'issue_date', $weeks), 'Books issued per week');
    }
    return null;
}

/* ------------------------------------------------------------------
 * Hero: progress rings + "Today at GIMT"
 * ------------------------------------------------------------------ */

/**
 * Rings (selected session): attendance of the latest marked day, fee collection % of billed, seats filled of intake.
 * Today (always live, from the institute's current session): classes, exam papers, events, dues this week,
 * enquiries and today's collection. Every part is permission-aware and isolated.
 */
function dash_hero(array $c, ?int $liveSid): array
{
    $sid = $c['sid'];
    $today = $c['today'];
    $now = date('H:i:s');
    $rings = [];
    $day = [];

    if (can('attendance')) {
        $rings['attendance'] = dash_try('hero_attendance', function () use ($today) {
            $d = dash_last_attendance_day($today);
            if (!$d) {
                return ['percent' => null, 'date' => null, 'is_today' => false, 'total' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0];
            }
            $a = dash_attendance_between($d, $d);
            return ['percent' => $a['percent'], 'date' => $d, 'is_today' => $d === $today, 'total' => $a['total'], 'present' => $a['present'], 'late' => $a['late'], 'absent' => $a['absent'], 'leave' => $a['leave']];
        });
    }
    if (can('fees')) {
        $rings['fees'] = dash_try('hero_fees', function () use ($sid) {
            $r = db_row("SELECT COALESCE(SUM(net_amount), 0) AS billed, COALESCE(SUM(paid_amount), 0) AS paid, COALESCE(SUM(CASE WHEN status NOT IN ('paid', 'waived') THEN balance_amount ELSE 0 END), 0) AS balance,
                                COUNT(*) AS invoices FROM student_fees WHERE status <> 'cancelled' AND academic_session_id = ?", [$sid]);
            $billed = (float) $r['billed'];
            $paidAmt = (float) $r['paid'];
            return ['percent' => $billed > 0 ? round(min(100, $paidAmt * 100 / $billed), 1) : null, 'collected' => $paidAmt, 'billed' => $billed, 'balance' => (float) $r['balance'], 'invoices' => (int) $r['invoices']];
        });
    }
    if (can_any(['admissions', 'students'])) {
        $rings['admissions'] = dash_try('hero_admissions', function () use ($sid) {
            $admitted = $sid ? (int) db_value('SELECT COUNT(*) FROM students WHERE academic_session_id = ?', [$sid]) : 0;
            $intake = (int) db_value("SELECT COALESCE(SUM(intake_capacity), 0) FROM programs WHERE status = 'active'");
            $pipeline = can('admissions') && $sid ? (int) db_value("SELECT COUNT(*) FROM admissions WHERE academic_session_id = ? AND stage IN ('" . implode("','", DASH_OPEN_STAGES) . "')", [$sid]) : null;
            return ['percent' => $intake > 0 ? round($admitted * 100 / $intake, 1) : null, 'admitted' => $admitted, 'intake' => $intake, 'pipeline' => $pipeline];
        });
    }

    if (can_any(['timetable', 'academics', 'attendance'])) {
        $day['classes'] = dash_try('hero_classes', function () use ($liveSid, $today, $now) {
            $holiday = db_value("SELECT title FROM holidays WHERE type <> 'exam_break' AND holiday_date <= ? AND COALESCE(end_date, holiday_date) >= ? ORDER BY holiday_date LIMIT 1", [$today, $today]);
            if ($holiday || !$liveSid) {
                return ['total' => 0, 'running' => 0, 'done' => 0, 'next_at' => null, 'next_count' => 0, 'holiday' => $holiday ?: null];
            }
            $dow = (int) date('N', strtotime($today));
            $r = db_row("SELECT COUNT(*) AS total, COALESCE(SUM(ts.start_time <= ? AND ts.end_time > ?), 0) AS running, COALESCE(SUM(ts.end_time <= ?), 0) AS done,
                                MIN(CASE WHEN ts.start_time > ? THEN ts.start_time END) AS next_at
                         FROM timetables t JOIN time_slots ts ON ts.id = t.time_slot_id
                         WHERE t.academic_session_id = ? AND t.day_of_week = ? AND t.status = 'published' AND ts.is_break = 0", [$now, $now, $now, $now, $liveSid, $dow]);
            $next = $r['next_at'] ? substr((string) $r['next_at'], 0, 5) : null;
            $nextCount = $next ? (int) db_value("SELECT COUNT(*) FROM timetables t JOIN time_slots ts ON ts.id = t.time_slot_id
                                                 WHERE t.academic_session_id = ? AND t.day_of_week = ? AND t.status = 'published' AND ts.start_time = ?", [$liveSid, $dow, $r['next_at']]) : 0;
            return ['total' => (int) $r['total'], 'running' => (int) $r['running'], 'done' => (int) $r['done'], 'next_at' => $next, 'next_count' => $nextCount, 'holiday' => null];
        });
    }
    if (can('examination')) {
        $day['exams'] = dash_try('hero_exams', function () use ($today, $now) {
            $r = db_row("SELECT COUNT(*) AS papers, COUNT(DISTINCT es.exam_id) AS exams, COALESCE(SUM(es.start_time <= ? AND es.end_time > ?), 0) AS running,
                                MIN(CASE WHEN es.start_time > ? THEN es.start_time END) AS next_at
                         FROM exam_schedules es JOIN exams e ON e.id = es.exam_id WHERE es.exam_date = ? AND e.status NOT IN ('cancelled', 'draft')", [$now, $now, $now, $today]);
            return ['papers' => (int) $r['papers'], 'exams' => (int) $r['exams'], 'running' => (int) $r['running'], 'next_at' => $r['next_at'] ? substr((string) $r['next_at'], 0, 5) : null];
        });
    }
    if (can('events')) {
        $day['events'] = dash_try('hero_events', function () use ($today) {
            $rows = db_all("SELECT title, start_datetime FROM events WHERE status IN ('published', 'completed') AND start_datetime <= ? AND COALESCE(end_datetime, start_datetime) >= ?
                            ORDER BY start_datetime LIMIT 20", [$today . ' 23:59:59', $today . ' 00:00:00']);
            $first = $rows[0] ?? null;
            return ['count' => count($rows), 'title' => $first['title'] ?? null, 'time' => $first && substr((string) $first['start_datetime'], 0, 10) === $today ? substr((string) $first['start_datetime'], 11, 5) : null];
        });
    }
    if (can('fees')) {
        $day['dues_week'] = dash_try('hero_dues', function () use ($today) {
            $until = date('Y-m-d', strtotime('sunday this week', strtotime($today)));
            $r = db_row("SELECT COUNT(*) AS invoices, COALESCE(SUM(d.balance), 0) AS amount FROM (
                            SELECT fi.amount - fi.paid_amount AS balance FROM fee_installments fi JOIN student_fees t ON t.id = fi.student_fee_id
                            WHERE t.status NOT IN ('cancelled', 'paid', 'waived') AND fi.paid_amount < fi.amount AND fi.due_date BETWEEN ? AND ?
                            UNION ALL
                            SELECT t.balance_amount FROM student_fees t WHERE t.status NOT IN ('cancelled', 'paid', 'waived') AND t.balance_amount > 0 AND t.due_date BETWEEN ? AND ?
                              AND NOT EXISTS (SELECT 1 FROM fee_installments fx WHERE fx.student_fee_id = t.id)
                         ) d", [$today, $until, $today, $until]);
            return ['invoices' => (int) $r['invoices'], 'amount' => (float) $r['amount'], 'until' => $until];
        });
        $day['collected'] = dash_try('hero_collected', function () use ($today) {
            $paid = dash_paid_sql();
            $r = db_row("SELECT COUNT(*) AS receipts, COALESCE(SUM(p.amount + p.fine_amount), 0) AS amount FROM payments p WHERE $paid AND p.payment_date = ?", [$today]);
            return ['amount' => (float) $r['amount'], 'receipts' => (int) $r['receipts']];
        });
    }
    if (can('enquiries')) {
        $day['enquiries'] = dash_try('hero_enquiries', function () use ($today) {
            $r = db_row("SELECT COALESCE(SUM(created_at BETWEEN ? AND ?), 0) AS fresh,
                                COALESCE(SUM(follow_up_date IS NOT NULL AND follow_up_date <= ? AND status IN ('new', 'contacted', 'interested')), 0) AS due
                         FROM enquiries", [$today . ' 00:00:00', $today . ' 23:59:59', $today]);
            return ['new' => (int) $r['fresh'], 'followups' => (int) $r['due']];
        });
    }

    return [
        'rings' => (object) array_filter($rings, fn ($v) => $v !== null),
        'today' => (object) array_filter($day, fn ($v) => $v !== null),
        'date' => $today,
        'time' => date('H:i'),
    ];
}
