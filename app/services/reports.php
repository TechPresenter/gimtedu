<?php
/**
 * Reports & Analytics (brief §29) — one engine behind every report in the catalogue.
 *
 *   reports_catalog()                         key => [area, title, description, module, icon]   (static, cheap)
 *   reports_def($key)                         full definition, built lazily for ONE report (SQL, filters, columns, KPIs, chart)
 *   reports_meta($key, $def)                  filters + columns for the SPA filter bar
 *   reports_filters($def, $_GET)              normalised, validated filter values
 *   reports_run($def, $f, $page, $perPage, $sort, $dir)   KPIs + chart + server-side paginated rows ($page = null → export, capped)
 *   reports_export_table($def, $rows, $mode)  header + rows for CSV / Excel ('raw') or print ('print')
 *
 * Definition keys
 *   from      FROM clause (joins included)                   prepare  fn ($f) => [from, args]  (dynamic FROM with params)
 *   where[]   fixed conditions                               group    GROUP BY → grouped report: one query, sorted/paginated in PHP
 *   select    SELECT list for rows                           columns  [{key,label,format,sub,sub_label,labels,suffix}]
 *   sorts     {columnKey: sql expr}  default_sort [key,dir]  tiebreak extra ORDER BY expression (plain reports)
 *   search[]  columns matched by ?q=                         kpis/chart  fn (array $ctx) => [...]
 *   filters   {name: cfg}   name date → ?from&to, session → ?session_id ('all' = every session, absent = current),
 *             department → ?department_id, program → ?program_id, semester → ?semester, others → ?{name}.
 *             cfg: col | sql (one ? param) | sql_map {value: sql} | having_map {value: [sql, args]} | apply=false (handled in prepare),
 *                  label, options {value: label} | options_sql, default, required, datetime (date filter on a DATETIME column)
 *
 * Column formats: text strong person code number decimal money percent progress date datetime month badge label boolean days lpa hours
 * KPI formats:    number money percent decimal text lpa hours
 */

const REPORTS_EXPORT_LIMIT = 10000;
const REPORTS_PRINT_LIMIT = 2000;

/* ------------------------------------------------------------------
 * Catalogue
 * ------------------------------------------------------------------ */

function reports_areas(): array
{
    return [
        'students' => ['label' => 'Students', 'icon' => 'graduation-cap', 'description' => 'Enrolment register, strength and demographics'],
        'admissions' => ['label' => 'Admissions', 'icon' => 'user-plus', 'description' => 'Applications, conversion, sources and seat fill'],
        'attendance' => ['label' => 'Attendance', 'icon' => 'calendar-check', 'description' => 'Student, subject and department attendance with daily trends'],
        'fees' => ['label' => 'Fees', 'icon' => 'wallet', 'description' => 'Collections and outstanding dues'],
        'exams' => ['label' => 'Examinations', 'icon' => 'clipboard-list', 'description' => 'Exam schedule status and subject performance'],
        'results' => ['label' => 'Results', 'icon' => 'award', 'description' => 'Student results, SGPA and pass percentages'],
        'faculty' => ['label' => 'Faculty', 'icon' => 'presentation', 'description' => 'Faculty profile and teaching workload'],
        'library' => ['label' => 'Library', 'icon' => 'library', 'description' => 'Circulation, overdues and inventory'],
        'hostel' => ['label' => 'Hostel', 'icon' => 'building', 'description' => 'Occupancy and allocations'],
        'transport' => ['label' => 'Transport', 'icon' => 'bus', 'description' => 'Route utilisation and allocations'],
        'placement' => ['label' => 'Placement', 'icon' => 'briefcase', 'description' => 'Placement %, recruiters and packages'],
        'alumni' => ['label' => 'Alumni', 'icon' => 'users', 'description' => 'Alumni network and donations'],
        'website' => ['label' => 'Website', 'icon' => 'globe', 'description' => 'Enquiries, UTM campaigns, messages, newsletter, content, traffic'],
    ];
}

function reports_catalog(): array
{
    return [
        'student_register' => ['area' => 'students', 'module' => 'students', 'icon' => 'list', 'title' => 'Student register',
            'description' => 'Every student with program, semester, category, residence and status.'],
        'student_strength' => ['area' => 'students', 'module' => 'students', 'icon' => 'layers', 'title' => 'Program & semester strength',
            'description' => 'Head-count per program and semester with gender, hostel and transport split.'],
        'admission_register' => ['area' => 'admissions', 'module' => 'admissions', 'icon' => 'file-text', 'title' => 'Admission applications',
            'description' => 'Applications with stage, source, counsellor and admission fee received.'],
        'admission_sources' => ['area' => 'admissions', 'module' => 'admissions', 'icon' => 'share', 'title' => 'Source-wise conversion',
            'description' => 'Applications, confirmations and conversion rate for each lead source.'],
        'admission_programs' => ['area' => 'admissions', 'module' => 'admissions', 'icon' => 'gauge', 'title' => 'Seat fill by program',
            'description' => 'Confirmed admissions against intake capacity, waitlist and entrance scores.'],
        'attendance_students' => ['area' => 'attendance', 'module' => 'attendance', 'icon' => 'user-check', 'title' => 'Student attendance %',
            'description' => 'Attendance % per student with classes held and attended — find defaulters.'],
        'attendance_daily' => ['area' => 'attendance', 'module' => 'attendance', 'icon' => 'calendar-days', 'title' => 'Daily attendance trend',
            'description' => 'Sheets taken and present / absent / late counts for each working day.'],
        'attendance_subjects' => ['area' => 'attendance', 'module' => 'attendance', 'icon' => 'book-open-check', 'title' => 'Subject-wise attendance',
            'description' => 'Attendance %, classes held and average strength for every subject.'],
        'attendance_departments' => ['area' => 'attendance', 'module' => 'attendance', 'icon' => 'building-2', 'title' => 'Department & program attendance',
            'description' => 'Attendance % per program and department with students below the minimum.'],
        'fee_collection' => ['area' => 'fees', 'module' => 'fees', 'icon' => 'receipt', 'title' => 'Fee collection',
            'description' => 'Receipts by date, mode, program and collector with totals.'],
        'fee_outstanding' => ['area' => 'fees', 'module' => 'fees', 'icon' => 'alarm-clock', 'title' => 'Outstanding fees',
            'description' => 'Invoices with a balance, ageing and overdue days.'],
        'exam_overview' => ['area' => 'exams', 'module' => 'examination', 'icon' => 'calendar-range', 'title' => 'Examination overview',
            'description' => 'Exams with schedule, registrations, eligibility and pass percentage.'],
        'exam_subjects' => ['area' => 'exams', 'module' => 'examination', 'icon' => 'book-open-check', 'title' => 'Subject-wise performance',
            'description' => 'Appeared, absent, pass % and average score for every exam paper.'],
        'result_register' => ['area' => 'results', 'module' => 'results', 'icon' => 'file-badge', 'title' => 'Student results',
            'description' => 'Percentage, SGPA, CGPA, backlogs, division and rank for each student.'],
        'result_programs' => ['area' => 'results', 'module' => 'results', 'icon' => 'chart-column', 'title' => 'Program-wise pass %',
            'description' => 'Pass, fail, backlog and average SGPA per program and semester.'],
        'faculty_directory' => ['area' => 'faculty', 'module' => 'faculty', 'icon' => 'id-card', 'title' => 'Faculty profile',
            'description' => 'Department, designation, qualification, experience and publications.'],
        'faculty_workload' => ['area' => 'faculty', 'module' => 'faculty', 'icon' => 'clock', 'title' => 'Teaching workload',
            'description' => 'Subjects assigned, classes conducted and approved leave per faculty member.'],
        'library_circulation' => ['area' => 'library', 'module' => 'library', 'icon' => 'arrow-left-right', 'title' => 'Library circulation',
            'description' => 'Issues, returns, overdues and fines by member and book.'],
        'library_inventory' => ['area' => 'library', 'module' => 'library', 'icon' => 'book-copy', 'title' => 'Library inventory',
            'description' => 'Titles, copies, availability and value by category.'],
        'hostel_occupancy' => ['area' => 'hostel', 'module' => 'hostel', 'icon' => 'bed-double', 'title' => 'Hostel occupancy',
            'description' => 'Rooms, beds, occupancy %, allocations and open complaints per hostel.'],
        'hostel_allocations' => ['area' => 'hostel', 'module' => 'hostel', 'icon' => 'key-round', 'title' => 'Hostel allocations',
            'description' => 'Students allocated to hostels with room, bed, dates and fee.'],
        'transport_routes' => ['area' => 'transport', 'module' => 'transport', 'icon' => 'route', 'title' => 'Route utilisation',
            'description' => 'Stops, vehicles, seats, riders and fee per route.'],
        'transport_allocations' => ['area' => 'transport', 'module' => 'transport', 'icon' => 'map-pin', 'title' => 'Transport allocations',
            'description' => 'Students and employees using transport with route, stop and vehicle.'],
        'placement_programs' => ['area' => 'placement', 'module' => 'placement', 'icon' => 'target', 'title' => 'Department & program placement %',
            'description' => 'Students applied, placed, placement percentage and packages per program.'],
        'placement_companies' => ['area' => 'placement', 'module' => 'placement', 'icon' => 'building-2', 'title' => 'Company-wise hiring',
            'description' => 'Drives, applications, offers and packages per recruiter.'],
        'placement_offers' => ['area' => 'placement', 'module' => 'placement', 'icon' => 'badge-check', 'title' => 'Offers & package statistics',
            'description' => 'Every offer with company, role, package and status.'],
        'alumni_directory' => ['area' => 'alumni', 'module' => 'alumni', 'icon' => 'contact', 'title' => 'Alumni network',
            'description' => 'Alumni by batch, program, employment, industry and mentoring.'],
        'alumni_donations' => ['area' => 'alumni', 'module' => 'alumni', 'icon' => 'hand-heart', 'title' => 'Alumni donations',
            'description' => 'Donations by purpose, campaign, mode and status.'],
        'web_enquiries' => ['area' => 'website', 'module' => 'enquiries', 'icon' => 'message-square-text', 'title' => 'Enquiries register',
            'description' => 'Every enquiry with program, source, UTM tags, status and owner.'],
        'web_enquiry_sources' => ['area' => 'website', 'module' => 'enquiries', 'icon' => 'megaphone', 'title' => 'Enquiries by source & UTM',
            'description' => 'Lead volume and conversion per source, UTM source and campaign.'],
        'web_contact' => ['area' => 'website', 'module' => 'contact_messages', 'icon' => 'mail', 'title' => 'Contact messages',
            'description' => 'Website contact messages by type, status and response time.'],
        'web_newsletter' => ['area' => 'website', 'module' => 'communication', 'icon' => 'newspaper', 'title' => 'Newsletter growth',
            'description' => 'Monthly sign-ups, unsubscribes, bounces and retention.'],
        'web_content' => ['area' => 'website', 'module' => 'cms', 'icon' => 'files', 'title' => 'CMS content inventory',
            'description' => 'Pages, blog posts, notices, events and galleries with status and views.'],
        'web_traffic' => ['area' => 'website', 'module' => 'seo', 'icon' => 'activity', 'title' => 'Website traffic',
            'description' => 'Daily unique visitors, visits and pageviews recorded by the site tracker.'],
    ];
}

/** Can the current user open this report? (reports.view + view on the underlying module) */
function reports_allowed(array $cat): bool
{
    return can('reports', 'view') && can($cat['module'], 'view');
}

/** Full definition for one report, or null when unknown. */
function reports_def(string $key): ?array
{
    $cat = reports_catalog()[$key] ?? null;
    if (!$cat || !function_exists('reports_def_' . $key)) {
        return null;
    }
    $def = ('reports_def_' . $key)() + $cat + ['key' => $key, 'where' => [], 'search' => [], 'filters' => [], 'group' => null];
    $def['area_label'] = reports_areas()[$cat['area']]['label'] ?? label_from_key($cat['area']);
    foreach ($def['filters'] as $name => &$cfg) {
        $cfg += ['label' => reports_filter_default_label($name)];
        if (isset($cfg['options_sql'])) {
            $cfg['options'] = [];
            foreach (db_all($cfg['options_sql']) as $o) {
                if ($o['value'] !== null && $o['value'] !== '') {
                    // 'pretty' => true turns raw keys (e.g. a status or source slug) into readable labels.
                    $cfg['options'][(string) $o['value']] = !empty($cfg['pretty']) ? label_from_key((string) $o['label']) : (string) $o['label'];
                }
            }
            unset($cfg['options_sql']);
        }
    }
    unset($cfg);
    return $def;
}

function reports_filter_default_label(string $name): string
{
    return ['date' => 'Date', 'session' => 'Academic session', 'department' => 'Department', 'program' => 'Program', 'semester' => 'Semester', 'status' => 'Status'][$name] ?? label_from_key($name);
}

function reports_param(string $name): string
{
    return ['session' => 'session_id', 'department' => 'department_id', 'program' => 'program_id'][$name] ?? $name;
}

/* ------------------------------------------------------------------
 * Common filter options
 * ------------------------------------------------------------------ */

function reports_session_options(): array
{
    return db_pairs('SELECT id, name FROM academic_sessions ORDER BY start_date DESC');
}

function reports_department_options(): array
{
    return db_pairs("SELECT id, name FROM departments WHERE status = 'active' ORDER BY name");
}

function reports_program_options(): array
{
    return db_all("SELECT id, CONCAT(short_name, ' — ', name) AS label, department_id FROM programs WHERE status = 'active' ORDER BY sort_order, name");
}

function reports_semester_options(): array
{
    $max = max(2, min(12, (int) db_value("SELECT MAX(total_semesters) FROM programs WHERE status = 'active'")));
    $out = [];
    for ($i = 1; $i <= $max; $i++) {
        $out[(string) $i] = "Semester $i";
    }
    return $out;
}

/** Options in the shape the SPA expects. */
function reports_options_list(array $pairs): array
{
    $out = [];
    foreach ($pairs as $v => $l) {
        $out[] = ['value' => (string) $v, 'label' => (string) $l];
    }
    return $out;
}

/** Filter + column metadata for the SPA. */
function reports_meta(array $def): array
{
    $filters = [];
    foreach ($def['filters'] as $name => $cfg) {
        $base = ['name' => $name, 'param' => reports_param($name), 'label' => $cfg['label']];
        switch ($name) {
            case 'date':
                $filters[] = $base + ['type' => 'daterange'];
                break;
            case 'session':
                $filters[] = $base + ['type' => 'select', 'options' => reports_options_list(reports_session_options()), 'all_label' => 'All sessions', 'default' => (string) (current_session_id() ?? '')];
                break;
            case 'department':
                $filters[] = $base + ['type' => 'select', 'options' => reports_options_list(reports_department_options()), 'all_label' => 'All departments'];
                break;
            case 'program':
                $filters[] = $base + ['type' => 'select', 'all_label' => 'All programs', 'options' => array_map(fn ($p) => ['value' => (string) $p['id'], 'label' => $p['label'], 'department_id' => $p['department_id'] !== null ? (string) $p['department_id'] : null], reports_program_options())];
                break;
            case 'semester':
                $filters[] = $base + ['type' => 'select', 'options' => reports_options_list(reports_semester_options()), 'all_label' => 'All semesters'];
                break;
            default:
                $opts = $cfg['options'] ?? [];
                $filters[] = $base + ['type' => 'select', 'options' => reports_options_list($opts), 'all_label' => $cfg['all_label'] ?? 'All', 'default' => isset($cfg['default']) ? (string) $cfg['default'] : null, 'required' => !empty($cfg['required'])];
        }
    }
    $sortable = array_keys($def['sorts'] ?? []);
    return [
        'key' => $def['key'], 'title' => $def['title'], 'description' => $def['description'], 'area' => $def['area'], 'area_label' => $def['area_label'],
        'module' => $def['module'], 'icon' => $def['icon'], 'filters' => $filters,
        'search' => $def['search'] ? ($def['search_hint'] ?? 'Search…') : null,
        'columns' => array_map(fn ($c) => reports_column_public($c, $sortable), $def['columns']),
        'default_sort' => ['key' => $def['default_sort'][0], 'dir' => $def['default_sort'][1]],
        'note' => $def['note'] ?? null,
        'can_export' => can('reports', 'export'),
        'sessions' => db_all('SELECT id, name, start_date, end_date FROM academic_sessions ORDER BY start_date DESC'),
        'today' => date('Y-m-d'),
    ];
}

function reports_column_public(array $c, array $sortable): array
{
    return array_filter([
        'key' => $c['key'], 'label' => $c['label'], 'format' => $c['format'] ?? 'text', 'sub' => $c['sub'] ?? null, 'labels' => $c['labels'] ?? null,
        'suffix' => $c['suffix'] ?? null, 'sortable' => in_array($c['key'], $sortable, true), 'warn_below' => $c['warn_below'] ?? null,
    ], fn ($v) => $v !== null);
}

/* ------------------------------------------------------------------
 * Filters
 * ------------------------------------------------------------------ */

function reports_valid_date($v): ?string
{
    $v = is_string($v) ? trim($v) : '';
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }
    return $v;
}

/** Normalise + validate the filter values of a report from a query array. Invalid values are dropped silently. */
function reports_filters(array $def, array $q): array
{
    $f = ['from' => null, 'to' => null, 'q' => ''];
    foreach ($def['filters'] as $name => $cfg) {
        $param = reports_param($name);
        $raw = $q[$param] ?? null;
        $raw = is_scalar($raw) ? trim((string) $raw) : null;
        switch ($name) {
            case 'date':
                $f['from'] = reports_valid_date($q['from'] ?? null);
                $f['to'] = reports_valid_date($q['to'] ?? null);
                if ($f['from'] && $f['to'] && $f['from'] > $f['to']) {
                    [$f['from'], $f['to']] = [$f['to'], $f['from']];
                }
                break;
            case 'session':
                if ($raw === null) {
                    $f['session_id'] = current_session_id();
                } else {
                    $f['session_id'] = ctype_digit($raw) && isset(reports_session_options()[(int) $raw]) ? (int) $raw : null;
                }
                break;
            case 'department':
            case 'program':
                $f[$param] = $raw !== null && ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : null;
                break;
            case 'semester':
                $f[$param] = $raw !== null && ctype_digit($raw) && (int) $raw >= 1 && (int) $raw <= 12 ? (int) $raw : null;
                break;
            default:
                $allowed = array_map('strval', array_keys($cfg['options'] ?? ($cfg['sql_map'] ?? ($cfg['having_map'] ?? []))));
                if ($raw === null && isset($cfg['default'])) {
                    $raw = (string) $cfg['default'];
                }
                $f[$param] = $raw !== null && $raw !== '' && $raw !== 'all' && in_array($raw, $allowed, true) ? $raw : null;
                if ($f[$param] === null && !empty($cfg['required']) && isset($cfg['default'])) {
                    $f[$param] = (string) $cfg['default'];
                }
        }
    }
    if ($def['search']) {
        $f['q'] = mb_substr(trim((string) ($q['q'] ?? '')), 0, 100);
    }
    return $f;
}

/** Human-readable list of the active filters (print header, export log, UI chips). */
function reports_applied(array $def, array $f): array
{
    $out = [];
    foreach ($def['filters'] as $name => $cfg) {
        $param = reports_param($name);
        switch ($name) {
            case 'date':
                if ($f['from'] || $f['to']) {
                    $out[] = $cfg['label'] . ': ' . ($f['from'] ? format_date($f['from']) : 'start') . ' – ' . ($f['to'] ? format_date($f['to']) : 'today');
                }
                break;
            case 'session':
                $out[] = $f['session_id'] ? 'Session ' . (reports_session_options()[$f['session_id']] ?? $f['session_id']) : 'All sessions';
                break;
            case 'department':
                if ($f[$param]) {
                    $out[] = 'Department: ' . (reports_department_options()[$f[$param]] ?? $f[$param]);
                }
                break;
            case 'program':
                if ($f[$param]) {
                    $out[] = 'Program: ' . (db_value('SELECT short_name FROM programs WHERE id = ?', [$f[$param]]) ?: $f[$param]);
                }
                break;
            case 'semester':
                if ($f[$param]) {
                    $out[] = 'Semester ' . $f[$param];
                }
                break;
            default:
                if (($f[$param] ?? null) !== null) {
                    $out[] = $cfg['label'] . ': ' . ($cfg['options'][$f[$param]] ?? label_from_key((string) $f[$param]));
                }
        }
    }
    if (($f['q'] ?? '') !== '') {
        $out[] = 'Search: "' . $f['q'] . '"';
    }
    return $out;
}

function reports_like(string $s): string
{
    return '%' . addcslashes($s, '%_\\') . '%';
}

/** SQL fragments for a report + filters: FROM / WHERE / GROUP / HAVING and their params. */
function reports_context(array $def, array $f): array
{
    $where = $def['where'];
    $args = [];
    $having = [];
    $hargs = [];
    [$from, $fromArgs] = isset($def['prepare']) ? ($def['prepare'])($f) : [$def['from'], []];
    foreach ($def['filters'] as $name => $cfg) {
        if (($cfg['apply'] ?? true) === false) {
            continue;
        }
        if ($name === 'date') {
            $col = $cfg['col'];
            if ($f['from']) {
                $where[] = "$col >= ?";
                $args[] = $f['from'] . (!empty($cfg['datetime']) ? ' 00:00:00' : '');
            }
            if ($f['to']) {
                if (!empty($cfg['datetime'])) {
                    $where[] = "$col < ?";
                    $args[] = date('Y-m-d', strtotime($f['to'] . ' +1 day')) . ' 00:00:00';
                } else {
                    $where[] = "$col <= ?";
                    $args[] = $f['to'];
                }
            }
            continue;
        }
        $v = $f[reports_param($name)] ?? null;
        if ($v === null || $v === '') {
            continue;
        }
        if (isset($cfg['sql_map'])) {
            $where[] = $cfg['sql_map'][$v];
        } elseif (isset($cfg['having_map'])) {
            [$sql, $a] = $cfg['having_map'][$v];
            $having[] = $sql;
            array_push($hargs, ...$a);
        } elseif (isset($cfg['sql'])) {
            $where[] = $cfg['sql'];
            $args[] = $v;
        } else {
            $where[] = $cfg['col'] . ' = ?';
            $args[] = $v;
        }
    }
    if (($f['q'] ?? '') !== '' && $def['search']) {
        $where[] = '(' . implode(' OR ', array_map(fn ($c) => "$c LIKE ?", $def['search'])) . ')';
        foreach ($def['search'] as $_) {
            $args[] = reports_like($f['q']);
        }
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $groupSql = $def['group'] ? 'GROUP BY ' . $def['group'] : '';
    $havingSql = $having ? 'HAVING ' . implode(' AND ', $having) : '';
    $allArgs = array_merge($fromArgs, $args);
    return [
        'f' => $f, 'from' => $from, 'where' => $whereSql, 'args' => $allArgs,
        'base' => "FROM $from $whereSql",
        'sql' => "SELECT {$def['select']} FROM $from $whereSql $groupSql $havingSql",
        'sql_args' => array_merge($allArgs, $hargs),
    ];
}

/* ------------------------------------------------------------------
 * Query helpers used by KPI / chart closures
 * ------------------------------------------------------------------ */

/** One aggregate row over the filtered base: reports_one($c, 'COUNT(*) total, SUM(x) y') */
function reports_one(array $c, string $select, string $extraWhere = '', array $extraArgs = []): array
{
    $sql = "SELECT $select {$c['base']}";
    if ($extraWhere !== '') {
        $sql .= ($c['where'] ? ' AND ' : ' WHERE ') . $extraWhere;
    }
    return db_row($sql, array_merge($c['args'], $extraArgs)) ?? [];
}

/** Grouped rows over the filtered base: reports_all($c, 'x label, COUNT(*) v', 'GROUP BY x ORDER BY v DESC LIMIT 10') */
function reports_all(array $c, string $select, string $tail = ''): array
{
    return db_all("SELECT $select {$c['base']} $tail", $c['args']);
}

function reports_kpi(string $label, $value, string $format = 'number', string $tone = 'blue', ?string $hint = null): array
{
    if (in_array($format, ['number', 'money', 'percent', 'decimal', 'lpa', 'hours'], true)) {
        $value = $value === null ? null : round((float) $value, $format === 'number' ? 0 : 2);
    }
    return ['label' => $label, 'value' => $value, 'format' => $format, 'tone' => $tone, 'hint' => $hint];
}

function reports_pct($part, $whole, int $decimals = 1): ?float
{
    return (float) $whole > 0 ? round((float) $part * 100 / (float) $whole, $decimals) : null;
}

function reports_share($part, $whole): string
{
    $p = reports_pct($part, $whole);
    return $p === null ? 'no records' : rtrim(rtrim(number_format($p, 1), '0'), '.') . '% of total';
}

/**
 * Chart payload from grouped rows: reports_chart('bar', 'Title', $rows, ['v' => 'Students']).
 * Types: bar | hbar | stacked | line | doughnut. Series that are all zero are dropped (except the first).
 * A series label may be [label, '#hex'] to give it a fixed (semantic) colour.
 */
function reports_chart(string $type, string $title, array $rows, array $series, string $format = 'number', string $labelKey = 'label', ?array $labelMap = null): ?array
{
    if (!$rows) {
        return ['type' => $type, 'title' => $title, 'labels' => [], 'series' => [], 'format' => $format];
    }
    $labels = array_map(function ($r) use ($labelKey, $labelMap) {
        $l = (string) ($r[$labelKey] ?? '');
        return $labelMap[$l] ?? ($l === '' ? 'Not set' : $l);
    }, $rows);
    $out = [];
    $i = 0;
    foreach ($series as $key => $label) {
        $color = is_array($label) ? $label[1] : null;
        $label = is_array($label) ? $label[0] : $label;
        $data = array_map(fn ($r) => round((float) ($r[$key] ?? 0), 2), $rows);
        if ($i++ === 0 || array_sum(array_map('abs', $data)) > 0) {
            $out[] = ['label' => $label, 'data' => $data] + ($color ? ['color' => $color] : []);
        }
    }
    return ['type' => $type, 'title' => $title, 'labels' => $labels, 'series' => $out, 'format' => $format];
}

/**
 * Time-series chart: buckets by day when the (filtered) range spans ≤ 62 days, else by month (last 24 buckets).
 *   $measures = ['Collected' => ['py.payment_date', 'SUM(py.amount)', 'py.status = ?', ['success']]]  (condition optional)
 */
function reports_timeseries(array $c, string $type, string $title, array $measures, string $format = 'number'): array
{
    $f = $c['f'];
    $byDay = $f['from'] && $f['to'] && (strtotime($f['to']) - strtotime($f['from'])) <= 62 * 86400;
    $buckets = [];
    $values = [];
    foreach ($measures as $label => $m) {
        [$col, $agg] = $m;
        $cond = $m[2] ?? '';
        $condArgs = $m[3] ?? [];
        $bucket = $byDay ? "DATE($col)" : "DATE_FORMAT($col, '%Y-%m')";
        $conds = $cond !== '' ? [$cond, "$col IS NOT NULL"] : ["$col IS NOT NULL"];
        $rows = db_all("SELECT $bucket AS b, $agg AS v {$c['base']}" . ($c['where'] ? ' AND ' : ' WHERE ') . implode(' AND ', $conds) . ' GROUP BY b ORDER BY b',
            array_merge($c['args'], $condArgs));
        foreach ($rows as $r) {
            $buckets[$r['b']] = true;
            $values[$label][$r['b']] = (float) $r['v'];
        }
    }
    // Fill the gaps so quiet days / months show as zero instead of disappearing from the axis.
    if ($buckets) {
        ksort($buckets);
        $first = array_key_first($buckets);
        $last = array_key_last($buckets);
        if ($byDay) {
            $first = min($first, $f['from']);
            $last = max($last, min($f['to'], date('Y-m-d')));
            for ($d = $first; $d <= $last; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                $buckets[$d] = true;
            }
        } else {
            for ($m = $first; $m <= $last; $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
                $buckets[$m] = true;
            }
        }
    }
    ksort($buckets);
    $keys = array_slice(array_keys($buckets), $byDay ? -62 : -24);
    $cut = count($buckets) > count($keys);
    $labels = array_map(fn ($b) => $byDay ? date('d M', strtotime($b)) : date('M Y', strtotime($b . '-01')), $keys);
    $series = [];
    foreach ($measures as $label => $_) {
        $series[] = ['label' => $label, 'data' => array_map(fn ($k) => round($values[$label][$k] ?? 0, 2), $keys)];
    }
    return ['type' => $type, 'title' => $title . ($byDay ? ' (daily)' : ($cut ? ' (monthly, last 24 months)' : ' (monthly)')), 'labels' => $labels, 'series' => $series, 'format' => $format];
}

/** Rows of a grouped report re-aggregated in PHP (charts from materialised rows). */
function reports_sum_by(array $rows, string $labelKey, array $sumKeys, int $limit = 0, ?string $orderKey = null): array
{
    $out = [];
    foreach ($rows as $r) {
        $k = (string) ($r[$labelKey] ?? '');
        $out[$k] ??= ['label' => $k];
        foreach ($sumKeys as $s) {
            $out[$k][$s] = ($out[$k][$s] ?? 0) + (float) ($r[$s] ?? 0);
        }
    }
    $out = array_values($out);
    if ($orderKey) {
        usort($out, fn ($a, $b) => $b[$orderKey] <=> $a[$orderKey]);
    }
    return $limit ? array_slice($out, 0, $limit) : $out;
}

/* ------------------------------------------------------------------
 * Runner
 * ------------------------------------------------------------------ */

/**
 * Run a report. $page = null returns every row (exports, capped at $cap) and skips KPIs/chart when $withSummary = false.
 */
function reports_run(array $def, array $f, ?int $page = 1, int $perPage = 25, ?string $sort = null, ?string $dir = null, bool $withSummary = true, int $cap = REPORTS_EXPORT_LIMIT): array
{
    if (isset($def['adapt'])) {
        $def = ($def['adapt'])($def, $f);
    }
    $c = reports_context($def, $f);
    $sortKey = $sort !== null && isset($def['sorts'][$sort]) ? $sort : $def['default_sort'][0];
    $dir = in_array(strtolower((string) $dir), ['asc', 'desc'], true) ? strtolower((string) $dir) : ($sortKey === $def['default_sort'][0] ? $def['default_sort'][1] : 'asc');
    $truncated = false;

    if ($def['group']) {
        // Grouped reports are small (one row per program / source / student…): one query, sort + page in PHP.
        $all = db_all($c['sql'], $c['sql_args']);
        if (isset($def['transform'])) {
            $all = array_map($def['transform'], $all);
        }
        $c['rows_all'] = $all;
        $sorted = $all;
        usort($sorted, function ($a, $b) use ($sortKey, $dir) {
            $x = $a[$sortKey] ?? null;
            $y = $b[$sortKey] ?? null;
            if ($x === null || $y === null) {
                return ($x === null) <=> ($y === null); // nulls last in both directions
            }
            $cmp = is_numeric($x) && is_numeric($y) ? ((float) $x <=> (float) $y) : strnatcasecmp((string) $x, (string) $y);
            return $dir === 'desc' ? -$cmp : $cmp;
        });
        $total = count($sorted);
        if ($page === null) {
            $truncated = $total > $cap;
            $rows = array_slice($sorted, 0, $cap);
            $p = null;
        } else {
            $p = paginate($total, $page, $perPage);
            $rows = array_slice($sorted, $p['offset'], $p['per_page']);
        }
    } else {
        $total = (int) db_value("SELECT COUNT(*) {$c['base']}", $c['args']);
        $order = 'ORDER BY ' . $def['sorts'][$sortKey] . ' ' . strtoupper($dir) . (isset($def['tiebreak']) ? ', ' . $def['tiebreak'] : '');
        if ($page === null) {
            $truncated = $total > $cap;
            $limit = 'LIMIT ' . (int) $cap;
            $p = null;
        } else {
            $p = paginate($total, $page, $perPage);
            $limit = 'LIMIT ' . (int) $p['per_page'] . ' OFFSET ' . (int) $p['offset'];
        }
        $rows = db_all("{$c['sql']} $order $limit", $c['sql_args']);
        if (isset($def['transform'])) {
            $rows = array_map($def['transform'], $rows);
        }
    }

    $out = [
        'rows' => $rows, 'total' => $total, 'truncated' => $truncated,
        'pagination' => $p ? ['page' => $p['page'], 'per_page' => $p['per_page'], 'total' => $p['total'], 'pages' => $p['pages'], 'from' => $p['from'], 'to' => $p['to']] : null,
        'sort' => ['key' => $sortKey, 'dir' => $dir],
    ];
    if ($withSummary) {
        $out['kpis'] = isset($def['kpis']) ? array_values(array_filter(($def['kpis'])($c))) : [];
        $out['chart'] = isset($def['chart']) ? ($def['chart'])($c) : null;
    }
    return $out;
}

/* ------------------------------------------------------------------
 * Exports
 * ------------------------------------------------------------------ */

/** Plain-text value of a cell. $mode raw = spreadsheet (numbers stay numeric), print = formatted for reading. */
function reports_cell_text(array $col, $v, string $mode = 'raw'): string
{
    if ($v === null || $v === '') {
        return $mode === 'print' ? '—' : '';
    }
    $fmt = $col['format'] ?? 'text';
    switch ($fmt) {
        case 'money':
            return $mode === 'print' ? money((float) $v, 2) : (string) round((float) $v, 2);
        case 'number':
            return $mode === 'print' ? number_in((float) $v) : (string) (0 + $v);
        case 'decimal':
        case 'lpa':
        case 'hours':
            $txt = $mode === 'print' ? number_in((float) $v, 2) : (string) round((float) $v, 2);
            return $mode === 'print' && $fmt === 'lpa' ? $txt . ' LPA' : ($mode === 'print' && $fmt === 'hours' ? $txt . ' h' : $txt);
        case 'percent':
        case 'progress':
            return $mode === 'print' ? rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%' : (string) round((float) $v, 2);
        case 'days':
            return $mode === 'print' ? ((int) $v > 0 ? number_in((int) $v) . ' d' : '—') : (string) (int) $v;
        case 'date':
            return format_date($v);
        case 'datetime':
            return format_datetime($v);
        case 'month':
            return date('M Y', strtotime($v . '-01'));
        case 'boolean':
            return (int) $v ? 'Yes' : 'No';
        case 'badge':
        case 'label':
            return (string) ($col['labels'][$v] ?? label_from_key((string) $v));
        default:
            return (string) $v . (isset($col['suffix']) ? ' ' . $col['suffix'] : '');
    }
}

/** [headers, rows, numericFlags] — sub values become their own columns (spreadsheet) or "value · sub" (print). */
function reports_export_table(array $def, array $rows, string $mode = 'raw'): array
{
    $headers = [];
    $numeric = [];
    foreach ($def['columns'] as $col) {
        $headers[] = $col['label'];
        $numeric[] = in_array($col['format'] ?? 'text', ['money', 'number', 'decimal', 'percent', 'progress', 'days', 'lpa', 'hours'], true);
        if (!empty($col['sub']) && $mode === 'raw') {
            $headers[] = $col['sub_label'] ?? label_from_key($col['sub']);
            $numeric[] = false;
        }
    }
    $data = [];
    foreach ($rows as $r) {
        $line = [];
        foreach ($def['columns'] as $col) {
            $txt = reports_cell_text($col, $r[$col['key']] ?? null, $mode);
            if (!empty($col['sub'])) {
                $sub = $r[$col['sub']] ?? null;
                if ($mode === 'raw') {
                    $line[] = $txt;
                    $line[] = $sub === null ? '' : (string) $sub;
                    continue;
                }
                if ($sub !== null && $sub !== '') {
                    $txt .= "\n" . $sub;
                }
            }
            $line[] = $txt;
        }
        $data[] = $line;
    }
    return [$headers, $data, $numeric];
}

/** Stream CSV / XLSX (exits). */
function reports_download(array $def, array $f, string $format): void
{
    $run = reports_run($def, $f, null, 0, $_GET['sort'] ?? null, $_GET['dir'] ?? null, false);
    [$headers, $data] = reports_export_table($def, $run['rows'], 'raw');
    $applied = reports_applied($def, $f);
    log_activity('export', 'reports', null, sprintf('Exported %s report (%s, %d rows%s)%s', $def['title'], strtoupper($format), count($data), $run['truncated'] ? ', capped' : '', $applied ? ' — ' . implode('; ', $applied) : ''));
    $file = str_replace('_', '-', $def['key']) . '-report-' . date('Y-m-d-His');
    if ($format === 'xlsx') {
        xlsx_download($file . '.xlsx', $headers, $data, mb_substr($def['title'], 0, 30));
    }
    csv_download($file . '.csv', $headers, $data);
}

/** Recent exports + counts for the catalogue header. */
function reports_activity(): array
{
    $since = date('Y-m-d H:i:s', strtotime('-30 days'));
    $mine = db_row("SELECT description, created_at FROM activity_logs WHERE module = 'reports' AND action IN ('export', 'print') AND user_id = ? ORDER BY id DESC LIMIT 1", [user_id()]);
    return [
        'exports_30d' => (int) db_value("SELECT COUNT(*) FROM activity_logs WHERE module = 'reports' AND action IN ('export', 'print') AND created_at >= ?", [$since]),
        'my_last_export' => $mine ? ['description' => $mine['description'], 'at' => $mine['created_at']] : null,
    ];
}

/* ==================================================================
 * Report definitions
 * ================================================================== */

function reports_sn(string $a = 's'): string
{
    return "TRIM(CONCAT_WS(' ', $a.first_name, $a.last_name))";
}

function reports_name_search(string $a = 's'): string
{
    return "CONCAT_WS(' ', $a.first_name, $a.last_name)";
}

/* ---------------------------------------------------------------- Students */

function reports_def_student_register(): array
{
    require_once APP_ROOT . '/app/services/students.php';
    return [
        'from' => 'students s LEFT JOIN programs p ON p.id = s.program_id LEFT JOIN departments d ON d.id = s.department_id',
        'select' => 's.id, ' . reports_sn() . ' AS student, s.student_uid, s.roll_no, p.short_name AS program, d.name AS department, s.current_semester AS semester, s.gender, s.category, s.mobile, s.admission_date, s.is_hosteller, s.uses_transport, s.status',
        'filters' => [
            'date' => ['col' => 's.admission_date', 'label' => 'Admission date'],
            'session' => ['sql' => 's.id IN (SELECT sa.student_id FROM student_academic sa WHERE sa.academic_session_id = ?)', 'label' => 'Enrolled in session'],
            'department' => ['col' => 's.department_id'],
            'program' => ['col' => 's.program_id'],
            'semester' => ['col' => 's.current_semester'],
            'status' => ['col' => 's.status', 'options' => students_status_options()],
            'gender' => ['col' => 's.gender', 'options' => students_gender_options()],
            'category' => ['col' => 's.category', 'options' => students_category_options()],
            'residence' => ['label' => 'Residence', 'options' => ['hosteller' => 'Hostellers', 'day' => 'Day scholars', 'transport' => 'Uses transport'],
                'sql_map' => ['hosteller' => 's.is_hosteller = 1', 'day' => 's.is_hosteller = 0', 'transport' => 's.uses_transport = 1']],
        ],
        'search' => [reports_name_search(), 's.student_uid', 's.roll_no', 's.mobile', 's.email'],
        'search_hint' => 'Name, student ID, roll no, mobile…',
        'columns' => [
            ['key' => 'student', 'label' => 'Student', 'format' => 'person', 'sub' => 'student_uid', 'sub_label' => 'Student ID'],
            ['key' => 'program', 'label' => 'Program', 'sub' => 'department', 'sub_label' => 'Department'],
            ['key' => 'semester', 'label' => 'Sem', 'format' => 'number'],
            ['key' => 'gender', 'label' => 'Gender', 'format' => 'label', 'labels' => students_gender_options()],
            ['key' => 'category', 'label' => 'Category'],
            ['key' => 'mobile', 'label' => 'Mobile'],
            ['key' => 'admission_date', 'label' => 'Admitted', 'format' => 'date'],
            ['key' => 'is_hosteller', 'label' => 'Hosteller', 'format' => 'boolean'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'labels' => students_status_options()],
        ],
        'sorts' => ['student' => 'student', 'program' => 'program', 'semester' => 's.current_semester', 'category' => 's.category', 'admission_date' => 's.admission_date', 'status' => 's.status'],
        'default_sort' => ['student', 'asc'],
        'tiebreak' => 's.id',
        'kpis' => function (array $c) {
            $r = reports_one($c, "COUNT(*) AS total, SUM(s.status = 'active') AS active, SUM(s.gender = 'female') AS female, SUM(s.is_hosteller = 1) AS hostel, SUM(s.uses_transport = 1) AS transport");
            $t = (int) $r['total'];
            return [
                reports_kpi('Students', $t, 'number', 'navy'),
                reports_kpi('Active', $r['active'], 'number', 'green', reports_share($r['active'], $t)),
                reports_kpi('Female students', $r['female'], 'number', 'pink', reports_share($r['female'], $t)),
                reports_kpi('Hostellers', $r['hostel'], 'number', 'purple', reports_share($r['hostel'], $t)),
                reports_kpi('Transport users', $r['transport'], 'number', 'cyan', reports_share($r['transport'], $t)),
            ];
        },
        'chart' => fn (array $c) => reports_chart('stacked', 'Students by program and gender',
            reports_all($c, "COALESCE(p.short_name, 'Unassigned') AS label, SUM(s.gender = 'male') AS male, SUM(s.gender = 'female') AS female, SUM(COALESCE(s.gender, '') NOT IN ('male', 'female')) AS other", 'GROUP BY p.short_name ORDER BY COUNT(*) DESC LIMIT 12'),
            ['male' => ['Male', '#1D4ED8'], 'female' => ['Female', '#DB2777'], 'other' => ['Other', '#64748B']]),
    ];
}

function reports_def_student_strength(): array
{
    require_once APP_ROOT . '/app/services/students.php';
    return [
        'from' => 'students s LEFT JOIN programs p ON p.id = s.program_id LEFT JOIN departments d ON d.id = p.department_id',
        'group' => 's.program_id, p.short_name, p.name, d.name, s.current_semester',
        'select' => "MIN(s.id) AS id, COALESCE(p.short_name, 'Unassigned') AS program, p.name AS program_name, d.name AS department, s.current_semester AS semester, COUNT(*) AS total,
            SUM(s.status = 'active') AS active, SUM(s.gender = 'male') AS male, SUM(s.gender = 'female') AS female, SUM(s.is_hosteller = 1) AS hostellers,
            SUM(s.uses_transport = 1) AS transport, SUM(s.status IN ('inactive', 'suspended', 'dropped')) AS inactive",
        'filters' => [
            'session' => ['sql' => 's.id IN (SELECT sa.student_id FROM student_academic sa WHERE sa.academic_session_id = ?)', 'label' => 'Enrolled in session'],
            'department' => ['col' => 'p.department_id'],
            'program' => ['col' => 's.program_id'],
            'status' => ['col' => 's.status', 'options' => students_status_options()],
            'gender' => ['col' => 's.gender', 'options' => students_gender_options()],
            'category' => ['col' => 's.category', 'options' => students_category_options()],
        ],
        'columns' => [
            ['key' => 'program', 'label' => 'Program', 'format' => 'strong', 'sub' => 'department', 'sub_label' => 'Department'],
            ['key' => 'semester', 'label' => 'Semester', 'format' => 'number'],
            ['key' => 'total', 'label' => 'Students', 'format' => 'number'],
            ['key' => 'active', 'label' => 'Active', 'format' => 'number'],
            ['key' => 'male', 'label' => 'Male', 'format' => 'number'],
            ['key' => 'female', 'label' => 'Female', 'format' => 'number'],
            ['key' => 'hostellers', 'label' => 'Hostellers', 'format' => 'number'],
            ['key' => 'transport', 'label' => 'Transport', 'format' => 'number'],
            ['key' => 'inactive', 'label' => 'Inactive / left', 'format' => 'number'],
        ],
        'sorts' => array_fill_keys(['program', 'semester', 'total', 'active', 'male', 'female', 'hostellers', 'transport', 'inactive'], true),
        'default_sort' => ['total', 'desc'],
        'kpis' => function (array $c) {
            $rows = $c['rows_all'];
            $total = array_sum(array_column($rows, 'total'));
            $largest = null;
            foreach ($rows as $r) {
                if (!$largest || $r['total'] > $largest['total']) {
                    $largest = $r;
                }
            }
            return [
                reports_kpi('Students', $total, 'number', 'navy'),
                reports_kpi('Active', array_sum(array_column($rows, 'active')), 'number', 'green', reports_share(array_sum(array_column($rows, 'active')), $total)),
                reports_kpi('Program cohorts', count($rows), 'number', 'blue', 'program × semester groups'),
                reports_kpi('Average cohort', count($rows) ? $total / count($rows) : 0, 'number', 'purple', 'students per cohort'),
                reports_kpi('Largest cohort', $largest ? $largest['program'] . ' · Sem ' . $largest['semester'] : '—', 'text', 'orange', $largest ? number_in($largest['total']) . ' students' : null),
            ];
        },
        'chart' => fn (array $c) => reports_chart('doughnut', 'Students by category', reports_all($c, "COALESCE(s.category, 'Not set') AS label, COUNT(*) AS v", 'GROUP BY s.category ORDER BY v DESC'), ['v' => 'Students']),
    ];
}

/* ---------------------------------------------------------------- Admissions */

function reports_admission_open_stages_sql(string $col = 'a.stage'): string
{
    return "$col NOT IN ('confirmed', 'rejected', 'withdrawn', 'waitlisted')";
}

function reports_def_admission_register(): array
{
    require_once APP_ROOT . '/app/services/admissions.php';
    require_once APP_ROOT . '/app/services/students.php';
    $stages = admission_all_stages();
    return [
        'from' => 'admissions a LEFT JOIN programs p ON p.id = a.program_id LEFT JOIN users u ON u.id = a.assigned_to',
        'select' => 'a.id, a.application_no, ' . reports_sn('a') . ' AS applicant, a.phone, p.short_name AS program, a.source, a.stage, a.category, a.created_at, a.entrance_score, a.fee_paid, u.name AS counsellor',
        'filters' => [
            'date' => ['col' => 'a.created_at', 'label' => 'Application date', 'datetime' => true],
            'session' => ['col' => 'a.academic_session_id'],
            'department' => ['col' => 'p.department_id'],
            'program' => ['col' => 'a.program_id'],
            'status' => ['col' => 'a.stage', 'label' => 'Stage', 'options' => $stages],
            'source' => ['col' => 'a.source', 'options' => admission_sources()],
            'category' => ['col' => 'a.category', 'options' => students_category_options()],
            'gender' => ['col' => 'a.gender', 'options' => students_gender_options()],
        ],
        'search' => ['a.application_no', reports_name_search('a'), 'a.phone', 'a.email'],
        'search_hint' => 'Application no, name, phone, email…',
        'columns' => [
            ['key' => 'application_no', 'label' => 'Application', 'format' => 'code'],
            ['key' => 'applicant', 'label' => 'Applicant', 'format' => 'person', 'sub' => 'phone', 'sub_label' => 'Phone'],
            ['key' => 'program', 'label' => 'Program'],
            ['key' => 'source', 'label' => 'Source', 'format' => 'label', 'labels' => admission_sources()],
            ['key' => 'stage', 'label' => 'Stage', 'format' => 'badge', 'labels' => $stages],
            ['key' => 'created_at', 'label' => 'Applied on', 'format' => 'date'],
            ['key' => 'entrance_score', 'label' => 'Entrance', 'format' => 'decimal'],
            ['key' => 'fee_paid', 'label' => 'Fee paid', 'format' => 'money'],
            ['key' => 'counsellor', 'label' => 'Counsellor'],
        ],
        'sorts' => ['application_no' => 'a.application_no', 'applicant' => 'applicant', 'program' => 'program', 'stage' => 'a.stage', 'created_at' => 'a.created_at', 'entrance_score' => 'a.entrance_score', 'fee_paid' => 'a.fee_paid'],
        'default_sort' => ['created_at', 'desc'],
        'tiebreak' => 'a.id DESC',
        'kpis' => function (array $c) {
            $r = reports_one($c, "COUNT(*) AS total, SUM(a.stage = 'confirmed') AS confirmed, SUM(" . reports_admission_open_stages_sql() . ") AS open_count,
                SUM(a.stage IN ('rejected', 'withdrawn')) AS dropped, COALESCE(SUM(a.fee_paid), 0) AS fee");
            return [
                reports_kpi('Applications', $r['total'], 'number', 'navy'),
                reports_kpi('In pipeline', $r['open_count'], 'number', 'amber', 'not yet decided'),
                reports_kpi('Confirmed', $r['confirmed'], 'number', 'green', reports_pct($r['confirmed'], $r['total']) !== null ? reports_pct($r['confirmed'], $r['total']) . '% conversion' : null),
                reports_kpi('Rejected / withdrawn', $r['dropped'], 'number', 'red'),
                reports_kpi('Admission fee received', $r['fee'], 'money', 'purple'),
            ];
        },
        'chart' => function (array $c) use ($stages) {
            $counts = db_pairs("SELECT a.stage, COUNT(*) {$c['base']} GROUP BY a.stage", $c['args']);
            $rows = [];
            foreach ($stages as $k => $label) {
                if (isset($counts[$k])) {
                    $rows[] = ['label' => $label, 'v' => $counts[$k]];
                }
            }
            return reports_chart('hbar', 'Applications by stage', $rows, ['v' => 'Applications']);
        },
    ];
}

function reports_def_admission_sources(): array
{
    require_once APP_ROOT . '/app/services/admissions.php';
    $sources = admission_sources();
    return [
        'from' => 'admissions a LEFT JOIN programs p ON p.id = a.program_id',
        'group' => 'a.source',
        'select' => "COALESCE(a.source, 'other') AS id, COALESCE(a.source, 'other') AS source, COUNT(*) AS applications, SUM(" . reports_admission_open_stages_sql() . ") AS in_pipeline,
            SUM(a.stage = 'confirmed') AS confirmed, SUM(a.stage IN ('rejected', 'withdrawn')) AS dropped, ROUND(SUM(a.stage = 'confirmed') * 100 / COUNT(*), 1) AS conversion, COALESCE(SUM(a.fee_paid), 0) AS fee",
        'filters' => [
            'date' => ['col' => 'a.created_at', 'label' => 'Application date', 'datetime' => true],
            'session' => ['col' => 'a.academic_session_id'],
            'department' => ['col' => 'p.department_id'],
            'program' => ['col' => 'a.program_id'],
        ],
        'columns' => [
            ['key' => 'source', 'label' => 'Source', 'format' => 'label', 'labels' => $sources],
            ['key' => 'applications', 'label' => 'Applications', 'format' => 'number'],
            ['key' => 'in_pipeline', 'label' => 'In pipeline', 'format' => 'number'],
            ['key' => 'confirmed', 'label' => 'Confirmed', 'format' => 'number'],
            ['key' => 'dropped', 'label' => 'Rejected / withdrawn', 'format' => 'number'],
            ['key' => 'conversion', 'label' => 'Conversion', 'format' => 'progress'],
            ['key' => 'fee', 'label' => 'Admission fee', 'format' => 'money'],
        ],
        'sorts' => array_fill_keys(['source', 'applications', 'in_pipeline', 'confirmed', 'dropped', 'conversion', 'fee'], true),
        'default_sort' => ['applications', 'desc'],
        'kpis' => function (array $c) use ($sources) {
            $rows = $c['rows_all'];
            $total = array_sum(array_column($rows, 'applications'));
            $top = $best = null;
            foreach ($rows as $r) {
                if (!$top || $r['applications'] > $top['applications']) {
                    $top = $r;
                }
                if ($r['applications'] >= 5 && (!$best || $r['conversion'] > $best['conversion'])) {
                    $best = $r;
                }
            }
            $confirmed = array_sum(array_column($rows, 'confirmed'));
            return [
                reports_kpi('Applications', $total, 'number', 'navy'),
                reports_kpi('Confirmed', $confirmed, 'number', 'green', reports_pct($confirmed, $total) !== null ? reports_pct($confirmed, $total) . '% overall conversion' : null),
                reports_kpi('Active sources', count($rows), 'number', 'blue'),
                reports_kpi('Top source', $top ? ($sources[$top['source']] ?? label_from_key($top['source'])) : '—', 'text', 'orange', $top ? number_in($top['applications']) . ' applications' : null),
                reports_kpi('Best conversion', $best ? ($sources[$best['source']] ?? label_from_key($best['source'])) : '—', 'text', 'purple', $best ? $best['conversion'] . '% (min. 5 applications)' : 'needs 5+ applications'),
            ];
        },
        'chart' => fn (array $c) => reports_chart('doughnut', 'Applications by source', reports_sum_by($c['rows_all'], 'source', ['applications'], 0, 'applications'), ['applications' => 'Applications'], 'number', 'label', $sources),
    ];
}

function reports_def_admission_programs(): array
{
    require_once APP_ROOT . '/app/services/admissions.php';
    return [
        'from' => 'admissions a JOIN programs p ON p.id = a.program_id LEFT JOIN departments d ON d.id = p.department_id',
        'group' => 'p.id, p.short_name, p.name, d.name, p.intake_capacity',
        'select' => "p.id, p.short_name AS program, p.name AS program_name, d.name AS department, p.intake_capacity AS intake, COUNT(*) AS applications,
            SUM(" . reports_admission_open_stages_sql() . ") AS in_pipeline, SUM(a.stage = 'confirmed') AS confirmed, SUM(a.stage = 'waitlisted') AS waitlisted,
            ROUND(SUM(a.stage = 'confirmed') * 100 / NULLIF(p.intake_capacity, 0), 1) AS fill, ROUND(AVG(a.entrance_score), 1) AS avg_score",
        'filters' => [
            'date' => ['col' => 'a.created_at', 'label' => 'Application date', 'datetime' => true],
            'session' => ['col' => 'a.academic_session_id'],
            'department' => ['col' => 'p.department_id'],
            'program' => ['col' => 'a.program_id'],
            'source' => ['col' => 'a.source', 'options' => admission_sources()],
        ],
        'columns' => [
            ['key' => 'program', 'label' => 'Program', 'format' => 'strong', 'sub' => 'program_name', 'sub_label' => 'Program name'],
            ['key' => 'department', 'label' => 'Department'],
            ['key' => 'intake', 'label' => 'Intake', 'format' => 'number'],
            ['key' => 'applications', 'label' => 'Applications', 'format' => 'number'],
            ['key' => 'in_pipeline', 'label' => 'In pipeline', 'format' => 'number'],
            ['key' => 'confirmed', 'label' => 'Confirmed', 'format' => 'number'],
            ['key' => 'waitlisted', 'label' => 'Waitlisted', 'format' => 'number'],
            ['key' => 'fill', 'label' => 'Seat fill', 'format' => 'progress'],
            ['key' => 'avg_score', 'label' => 'Avg entrance', 'format' => 'decimal'],
        ],
        'sorts' => array_fill_keys(['program', 'department', 'intake', 'applications', 'in_pipeline', 'confirmed', 'waitlisted', 'fill', 'avg_score'], true),
        'default_sort' => ['fill', 'desc'],
        'kpis' => function (array $c) {
            $rows = $c['rows_all'];
            $intake = array_sum(array_column($rows, 'intake'));
            $confirmed = array_sum(array_column($rows, 'confirmed'));
            $apps = array_sum(array_column($rows, 'applications'));
            $full = count(array_filter($rows, fn ($r) => (int) $r['intake'] > 0 && (int) $r['confirmed'] >= (int) $r['intake']));
            return [
                reports_kpi('Seats (intake)', $intake, 'number', 'navy', count($rows) . ' programs with applications'),
                reports_kpi('Confirmed', $confirmed, 'number', 'green'),
                reports_kpi('Overall seat fill', reports_pct($confirmed, $intake), 'percent', 'blue'),
                reports_kpi('Applications per seat', $intake ? $apps / $intake : null, 'decimal', 'purple'),
                reports_kpi('Programs full', $full, 'number', 'orange', 'confirmed ≥ intake'),
            ];
        },
        'chart' => function (array $c) {
            $rows = $c['rows_all'];
            usort($rows, fn ($a, $b) => (int) $b['intake'] <=> (int) $a['intake']);
            return reports_chart('bar', 'Confirmed admissions vs intake', array_slice($rows, 0, 14), ['confirmed' => 'Confirmed', 'intake' => 'Intake'], 'number', 'program');
        },
    ];
}

/* ---------------------------------------------------------------- Attendance */

function reports_def_attendance_students(): array
{
    require_once APP_ROOT . '/app/services/attendance.php';
    $min = att_min_percent();
    $minTxt = rtrim(rtrim(number_format($min, 1), '0'), '.');
    $att = att_attended_expr('r.status');
    $late = att_late_counts() ? 1 : 0;
    $group = 's.id, s.first_name, s.last_name, s.student_uid, p.short_name, s.current_semester';
    $outer = fn (string $x) => 's.id, ' . reports_sn() . " AS student, s.student_uid, p.short_name AS program, s.current_semester AS semester, SUM($x.held) AS held,
        SUM($x.present) AS present, SUM($x.late) AS late, SUM($x.absent) AS absent, SUM($x.on_leave) AS on_leave,
        ROUND(SUM($x.attended) * 100 / NULLIF(SUM($x.held), 0), 1) AS pct, MAX($x.last_date) AS last_date";
    return [
        // Date-range path: aggregate the attendance records per student inside a derived table (covering index on
        // attendance_records(attendance_id, status, person_id)), then join the ~1 row per student to names.
        'prepare' => function (array $f) use ($att) {
            $w = ["a.type = 'student'"];
            $args = [];
            foreach ([['session_id', 'a.academic_session_id = ?'], ['from', 'a.attendance_date >= ?'], ['to', 'a.attendance_date <= ?'], ['semester', 'a.semester_no = ?']] as [$k, $sql]) {
                if (($f[$k] ?? null) !== null && $f[$k] !== '') {
                    $w[] = $sql;
                    $args[] = $f[$k];
                }
            }
            return ["(SELECT r.person_id AS student_id, COUNT(*) AS held, SUM(r.status = 'present') AS present, SUM(r.status = 'late') AS late, SUM(r.status = 'absent') AS absent,
                    SUM(r.status = 'leave') AS on_leave, SUM($att) AS attended, MAX(a.attendance_date) AS last_date
                FROM attendance a JOIN attendance_records r ON r.attendance_id = a.id WHERE " . implode(' AND ', $w) . " GROUP BY r.person_id) x
                JOIN students s ON s.id = x.student_id LEFT JOIN programs p ON p.id = s.program_id", $args];
        },
        'group' => $group,
        'select' => $outer('x'),
        'filters' => [
            'date' => ['col' => 'a.attendance_date', 'label' => 'Class date', 'apply' => false],
            'session' => ['apply' => false],
            'department' => ['col' => 's.department_id'],
            'program' => ['col' => 's.program_id'],
            'semester' => ['apply' => false],
            'band' => ['label' => 'Attendance band', 'all_label' => 'Any %', 'options' => ['shortage' => "Below $minTxt% (shortage)", 'below_60' => 'Below 60%', 'ok' => "$minTxt% and above", 'above_90' => '90% and above'],
                'having_map' => ['shortage' => ['pct < ?', [$min]], 'below_60' => ['pct < ?', [60]], 'ok' => ['pct >= ?', [$min]], 'above_90' => ['pct >= ?', [90]]]],
        ],
        // Without a date range the per-student session totals (attendance_summaries, kept by the attendance module) answer
        // the same question in milliseconds instead of aggregating every attendance record.
        'adapt' => function (array $def, array $f) use ($late, $outer) {
            if ($f['from'] || $f['to']) {
                return $def;
            }
            unset($def['prepare']);
            $def['from'] = "(SELECT sm.student_id, sm.academic_session_id, sm.held, sm.present, sm.late, sm.absent, sm.on_leave, sm.present + $late * sm.late AS attended, sm.last_date
                FROM attendance_summaries sm WHERE sm.held > 0) x JOIN students s ON s.id = x.student_id LEFT JOIN programs p ON p.id = s.program_id";
            $def['filters']['session'] = ['col' => 'x.academic_session_id', 'label' => $def['filters']['session']['label']];
            $def['filters']['semester'] = ['col' => 's.current_semester', 'label' => $def['filters']['semester']['label']];
            return $def;
        },
        'search' => [reports_name_search(), 's.student_uid', 's.roll_no'],
        'search_hint' => 'Name, student ID, roll no…',
        'note' => "Attendance % = (present + late" . (att_late_counts() ? '' : ' ×0') . ") ÷ classes held. Minimum required: $minTxt%.",
        'columns' => [
            ['key' => 'student', 'label' => 'Student', 'format' => 'person', 'sub' => 'student_uid', 'sub_label' => 'Student ID'],
            ['key' => 'program', 'label' => 'Program'],
            ['key' => 'semester', 'label' => 'Sem', 'format' => 'number'],
            ['key' => 'held', 'label' => 'Held', 'format' => 'number'],
            ['key' => 'present', 'label' => 'Present', 'format' => 'number'],
            ['key' => 'late', 'label' => 'Late', 'format' => 'number'],
            ['key' => 'absent', 'label' => 'Absent', 'format' => 'number'],
            ['key' => 'on_leave', 'label' => 'Leave', 'format' => 'number'],
            ['key' => 'pct', 'label' => 'Attendance', 'format' => 'progress', 'warn_below' => $min],
            ['key' => 'last_date', 'label' => 'Last class', 'format' => 'date'],
        ],
        'sorts' => array_fill_keys(['student', 'program', 'semester', 'held', 'present', 'absent', 'pct', 'last_date'], true),
        'default_sort' => ['pct', 'asc'],
        'kpis' => function (array $c) use ($min, $minTxt) {
            $rows = $c['rows_all'];
            $n = count($rows);
            $held = array_sum(array_column($rows, 'held'));
            $below = count(array_filter($rows, fn ($r) => (float) $r['pct'] < $min));
            $good = count(array_filter($rows, fn ($r) => (float) $r['pct'] >= 90));
            return [
                reports_kpi('Students', $n, 'number', 'navy'),
                reports_kpi('Average attendance', $n ? array_sum(array_column($rows, 'pct')) / $n : null, 'percent', 'blue'),
                reports_kpi("Below $minTxt%", $below, 'number', 'red', reports_share($below, $n)),
                reports_kpi('90% and above', $good, 'number', 'green', reports_share($good, $n)),
                reports_kpi('Attendance marks', $held, 'number', 'purple', 'student × class records'),
            ];
        },
        'chart' => function (array $c) {
            $bands = ['< 50%' => [0, 50], '50–60%' => [50, 60], '60–75%' => [60, 75], '75–85%' => [75, 85], '85–95%' => [85, 95], '95%+' => [95, 101]];
            $rows = [];
            foreach ($bands as $label => [$lo, $hi]) {
                $rows[] = ['label' => $label, 'v' => count(array_filter($c['rows_all'], fn ($r) => (float) $r['pct'] >= $lo && (float) $r['pct'] < $hi))];
            }
            return reports_chart('bar', 'Students by attendance band', $rows, ['v' => 'Students']);
        },
    ];
}

function reports_def_attendance_daily(): array
{
    require_once APP_ROOT . '/app/services/attendance.php';
    $late = att_late_counts() ? 1 : 0;
    return [
        'from' => 'attendance a LEFT JOIN programs p ON p.id = a.program_id',
        'group' => 'a.attendance_date',
        'select' => "a.attendance_date AS id, a.attendance_date AS date, COUNT(*) AS sheets, SUM(a.total_count) AS marked, SUM(a.present_count) AS present, SUM(a.late_count) AS late,
            SUM(a.absent_count) AS absent, SUM(a.leave_count) AS on_leave, SUM(a.half_day_count) AS half_day,
            ROUND((SUM(a.present_count) + $late * SUM(a.late_count) + 0.5 * SUM(a.half_day_count)) * 100 / NULLIF(SUM(a.total_count), 0), 1) AS pct",
        'filters' => [
            'date' => ['col' => 'a.attendance_date', 'label' => 'Date'],
            'session' => ['col' => 'a.academic_session_id'],
            'department' => ['col' => 'p.department_id'],
            'program' => ['col' => 'a.program_id'],
            'semester' => ['col' => 'a.semester_no'],
            'type' => ['col' => 'a.type', 'label' => 'Attendance of', 'options' => ['student' => 'Students', 'faculty' => 'Faculty', 'staff' => 'Staff'], 'default' => 'student', 'required' => true],
        ],
        'columns' => [
            ['key' => 'date', 'label' => 'Date', 'format' => 'date'],
            ['key' => 'sheets', 'label' => 'Sheets', 'format' => 'number'],
            ['key' => 'marked', 'label' => 'Marked', 'format' => 'number'],
            ['key' => 'present', 'label' => 'Present', 'format' => 'number'],
            ['key' => 'late', 'label' => 'Late', 'format' => 'number'],
            ['key' => 'absent', 'label' => 'Absent', 'format' => 'number'],
            ['key' => 'on_leave', 'label' => 'Leave', 'format' => 'number'],
            ['key' => 'half_day', 'label' => 'Half day', 'format' => 'number'],
            ['key' => 'pct', 'label' => 'Attendance', 'format' => 'progress', 'warn_below' => att_min_percent()],
        ],
        'sorts' => array_fill_keys(['date', 'sheets', 'marked', 'present', 'absent', 'pct'], true),
        'default_sort' => ['date', 'desc'],
        'kpis' => function (array $c) {
            $rows = $c['rows_all'];
            $n = count($rows);
            $marked = array_sum(array_column($rows, 'marked'));
            $low = null;
            foreach ($rows as $r) {
                if ($r['pct'] !== null && (!$low || (float) $r['pct'] < (float) $low['pct'])) {
                    $low = $r;
                }
            }
            return [
                reports_kpi('Working days', $n, 'number', 'navy'),
                reports_kpi('Sheets taken', array_sum(array_column($rows, 'sheets')), 'number', 'blue'),
                reports_kpi('Average attendance', $n ? array_sum(array_column($rows, 'pct')) / $n : null, 'percent', 'green', 'mean of daily %'),
                reports_kpi('Absences', array_sum(array_column($rows, 'absent')), 'number', 'red', reports_share(array_sum(array_column($rows, 'absent')), $marked)),
                reports_kpi('Lowest day', $low ? format_date($low['date']) : '—', 'text', 'amber', $low ? $low['pct'] . '% attendance' : null),
            ];
        },
        'chart' => function (array $c) {
            $rows = $c['rows_all'];
            usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));
            $rows = array_slice($rows, -60);
            foreach ($rows as &$r) {
                $r['label'] = date('d M', strtotime($r['date']));
            }
            return reports_chart('line', 'Daily attendance % (last ' . count($rows) . ' working days in range)', $rows, ['pct' => 'Attendance %'], 'percent');
        },
    ];
}

/** Attended weight of a group of attendance sheets (sheet counters, no record scan). */
function reports_sheet_pct_sql(): string
{
    $late = att_late_counts() ? 1 : 0;
    return "ROUND((SUM(a.present_count) + $late * SUM(a.late_count) + 0.5 * SUM(a.half_day_count)) * 100 / NULLIF(SUM(a.total_count), 0), 1)";
}

function reports_def_attendance_subjects(): array
{
    require_once APP_ROOT . '/app/services/attendance.php';
    $min = att_min_percent();
    $minTxt = rtrim(rtrim(number_format($min, 1), '0'), '.');
    $pct = reports_sheet_pct_sql();
    return [
        // One row per subject from the per-sheet counters kept by the attendance module (no per-student record scan).
        'from' => 'attendance a JOIN subjects sb ON sb.id = a.subject_id LEFT JOIN programs p ON p.id = a.program_id LEFT JOIN faculty fc ON fc.id = a.faculty_id',
        'where' => ["a.type = 'student'"],
        'group' => 'sb.id, sb.code, sb.name, p.short_name, a.semester_no',
        'select' => "sb.id AS id, sb.code, sb.name AS subject, p.short_name AS program, a.semester_no AS semester,
            GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', fc.first_name, fc.last_name)) ORDER BY fc.first_name SEPARATOR ', ') AS faculty,
            COUNT(*) AS classes, ROUND(SUM(a.total_count) / COUNT(*), 0) AS avg_strength, SUM(a.total_count) AS marked, SUM(a.present_count) AS present,
            SUM(a.late_count) AS late, SUM(a.absent_count) AS absent, SUM(a.leave_count) AS on_leave, $pct AS pct, MAX(a.attendance_date) AS last_date",
        'filters' => [
            'date' => ['col' => 'a.attendance_date', 'label' => 'Class date'],
            'session' => ['col' => 'a.academic_session_id'],
            'department' => ['col' => 'p.department_id'],
            'program' => ['col' => 'a.program_id'],
            'semester' => ['col' => 'a.semester_no'],
            'faculty' => ['col' => 'a.faculty_id', 'label' => 'Faculty', 'all_label' => 'All faculty',
                'options_sql' => "SELECT fc.id AS value, TRIM(CONCAT_WS(' ', fc.first_name, fc.last_name)) AS label FROM faculty fc WHERE fc.id IN (SELECT DISTINCT faculty_id FROM attendance WHERE faculty_id IS NOT NULL) ORDER BY fc.first_name, fc.last_name"],
            'band' => ['label' => 'Attendance band', 'all_label' => 'Any %', 'options' => ['shortage' => "Below $minTxt%", 'ok' => "$minTxt% and above", 'above_90' => '90% and above'],
                'having_map' => ['shortage' => ['pct < ?', [$min]], 'ok' => ['pct >= ?', [$min]], 'above_90' => ['pct >= ?', [90]]]],
        ],
        'search' => ['sb.name', 'sb.code'],
        'search_hint' => 'Subject name or code…',
        'note' => "Subject attendance % = (present + late" . (att_late_counts() ? '' : ' ×0') . " + ½ half days) ÷ students marked, over every class held. Minimum required: $minTxt%.",
        'columns' => [
            ['key' => 'subject', 'label' => 'Subject', 'format' => 'strong', 'sub' => 'code', 'sub_label' => 'Code'],
            ['key' => 'program', 'label' => 'Program'],
            ['key' => 'semester', 'label' => 'Sem', 'format' => 'number'],
            ['key' => 'pct', 'label' => 'Attendance', 'format' => 'progress', 'warn_below' => $min],
            ['key' => 'classes', 'label' => 'Classes', 'format' => 'number'],
            ['key' => 'avg_strength', 'label' => 'Avg strength', 'format' => 'number'],
            ['key' => 'absent', 'label' => 'Absences', 'format' => 'number'],
            ['key' => 'faculty', 'label' => 'Faculty'],
            ['key' => 'last_date', 'label' => 'Last class', 'format' => 'date'],
        ],
        'sorts' => array_fill_keys(['subject', 'program', 'semester', 'faculty', 'classes', 'avg_strength', 'absent', 'pct', 'last_date'], true),
        'default_sort' => ['pct', 'asc'],
        'kpis' => function (array $c) use ($min, $minTxt) {
            $rows = $c['rows_all'];
            $n = count($rows);
            $marked = array_sum(array_column($rows, 'marked'));
            $attended = 0.0;
            foreach ($rows as $r) {
                $attended += (float) $r['pct'] * (float) $r['marked'] / 100;
            }
            $below = count(array_filter($rows, fn ($r) => $r['pct'] !== null && (float) $r['pct'] < $min));
            $low = null;
            foreach ($rows as $r) {
                if ($r['pct'] !== null && (!$low || (float) $r['pct'] < (float) $low['pct'])) {
                    $low = $r;
                }
            }
            return [
                reports_kpi('Subjects', $n, 'number', 'navy'),
                reports_kpi('Classes held', array_sum(array_column($rows, 'classes')), 'number', 'blue'),
                reports_kpi('Overall attendance', $marked ? $attended * 100 / $marked : null, 'percent', 'green', 'weighted by students marked'),
                reports_kpi("Subjects below $minTxt%", $below, 'number', 'red', reports_share($below, $n)),
                reports_kpi('Lowest subject', $low ? $low['subject'] : '—', 'text', 'amber', $low ? $low['pct'] . '% · ' . $low['program'] . ' Sem ' . $low['semester'] : null),
            ];
        },
        'chart' => function (array $c) {
            $rows = array_values(array_filter($c['rows_all'], fn ($r) => $r['pct'] !== null));
            usort($rows, fn ($a, $b) => (float) $a['pct'] <=> (float) $b['pct']);
            $rows = array_map(fn ($r) => ['label' => $r['subject'] . ' (' . $r['program'] . ')', 'pct' => $r['pct']], array_slice($rows, 0, 12));
            return reports_chart('hbar', 'Subjects with the lowest attendance', $rows, ['pct' => 'Attendance %'], 'percent');
        },
    ];
}

function reports_def_attendance_departments(): array
{
    require_once APP_ROOT . '/app/services/attendance.php';
    $min = att_min_percent();
    $minTxt = rtrim(rtrim(number_format($min, 1), '0'), '.');
    $pct = reports_sheet_pct_sql();
    return [
        'from' => 'attendance a JOIN programs p ON p.id = a.program_id LEFT JOIN departments d ON d.id = p.department_id',
        'where' => ["a.type = 'student'"],
        'group' => 'p.id, p.short_name, p.name, d.name',
        'select' => "p.id AS id, p.short_name AS program, p.name AS program_name, COALESCE(d.name, 'No department') AS department, COUNT(*) AS classes,
            COUNT(DISTINCT a.subject_id) AS subjects, COUNT(DISTINCT a.attendance_date) AS days, SUM(a.total_count) AS marked, SUM(a.present_count) AS present,
            SUM(a.late_count) AS late, SUM(a.absent_count) AS absent, SUM(a.leave_count) AS on_leave, $pct AS pct",
        'filters' => [
            'date' => ['col' => 'a.attendance_date', 'label' => 'Class date'],
            'session' => ['col' => 'a.academic_session_id'],
            'department' => ['col' => 'p.department_id'],
            'program' => ['col' => 'a.program_id'],
            'semester' => ['col' => 'a.semester_no'],
        ],
        // Students below the minimum come from the per-session student totals — only meaningful without a date range.
        'adapt' => function (array $def, array $f) use ($min) {
            if ($f['from'] || $f['to']) {
                return $def;
            }
            $w = ['sm.held > 0'];
            $args = [$min];
            if (!empty($f['session_id'])) {
                $w[] = 'sm.academic_session_id = ?';
                $args[] = $f['session_id'];
            }
            if (!empty($f['semester'])) {
                $w[] = 's.current_semester = ?';
                $args[] = $f['semester'];
            }
            $map = [];
            foreach (db_all('SELECT s.program_id, COUNT(*) AS students, SUM((sm.present + ' . (att_late_counts() ? 1 : 0) . ' * sm.late) * 100 / sm.held < ?) AS shortage
                FROM attendance_summaries sm JOIN students s ON s.id = sm.student_id WHERE ' . implode(' AND ', $w) . ' GROUP BY s.program_id', $args) as $r) {
                $map[(int) $r['program_id']] = $r;
            }
            $def['transform'] = function (array $row) use ($map) {
                $m = $map[(int) $row['id']] ?? null;
                $row['students'] = $m ? (int) $m['students'] : 0;
                $row['shortage'] = $m ? (int) $m['shortage'] : 0;
                return $row;
            };
            return $def;
        },
        'note' => "Attendance % from every class sheet in range. Students below $minTxt% are counted from session totals and shown only when no date range is set.",
        'columns' => [
            ['key' => 'program', 'label' => 'Program', 'format' => 'strong', 'sub' => 'department', 'sub_label' => 'Department'],
            ['key' => 'pct', 'label' => 'Attendance', 'format' => 'progress', 'warn_below' => $min],
            ['key' => 'students', 'label' => 'Students', 'format' => 'number'],
            ['key' => 'shortage', 'label' => "Below $minTxt%", 'format' => 'number'],
            ['key' => 'classes', 'label' => 'Classes', 'format' => 'number'],
            ['key' => 'subjects', 'label' => 'Subjects', 'format' => 'number'],
            ['key' => 'days', 'label' => 'Days', 'format' => 'number'],
            ['key' => 'marked', 'label' => 'Marked', 'format' => 'number'],
            ['key' => 'present', 'label' => 'Present', 'format' => 'number'],
            ['key' => 'absent', 'label' => 'Absent', 'format' => 'number'],
            ['key' => 'on_leave', 'label' => 'Leave', 'format' => 'number'],
        ],
        'sorts' => array_fill_keys(['program', 'subjects', 'days', 'classes', 'marked', 'present', 'absent', 'on_leave', 'pct', 'students', 'shortage'], true),
        'default_sort' => ['pct', 'asc'],
        'kpis' => function (array $c) use ($minTxt) {
            $rows = $c['rows_all'];
            $marked = array_sum(array_column($rows, 'marked'));
            $attended = 0.0;
            foreach ($rows as $r) {
                $attended += (float) $r['pct'] * (float) $r['marked'] / 100;
            }
            $hasShortage = isset($rows[0]['shortage']);
            $students = array_sum(array_column($rows, 'students'));
            $shortage = array_sum(array_column($rows, 'shortage'));
            $depts = [];
            foreach ($rows as $r) {
                $depts[$r['department']] = [($depts[$r['department']][0] ?? 0) + (float) $r['pct'] * (float) $r['marked'] / 100, ($depts[$r['department']][1] ?? 0) + (float) $r['marked']];
            }
            $best = null;
            foreach ($depts as $name => [$att, $m]) {
                if ($m > 0 && (!$best || $att / $m > $best[1])) {
                    $best = [$name, $att / $m];
                }
            }
            return [
                reports_kpi('Programs', count($rows), 'number', 'navy', count($depts) . ' departments'),
                reports_kpi('Classes held', array_sum(array_column($rows, 'classes')), 'number', 'blue'),
                reports_kpi('Overall attendance', $marked ? $attended * 100 / $marked : null, 'percent', 'green', 'weighted by students marked'),
                $hasShortage ? reports_kpi("Students below $minTxt%", $shortage, 'number', 'red', reports_share($shortage, $students)) : reports_kpi('Absences', array_sum(array_column($rows, 'absent')), 'number', 'red', reports_share(array_sum(array_column($rows, 'absent')), $marked)),
                reports_kpi('Best department', $best ? $best[0] : '—', 'text', 'purple', $best ? round($best[1] * 100, 1) . '% attendance' : null),
            ];
        },
        'chart' => function (array $c) {
            $rows = $c['rows_all'];
            usort($rows, fn ($a, $b) => (float) $b['pct'] <=> (float) $a['pct']);
            return reports_chart('bar', 'Attendance % by program', array_map(fn ($r) => ['label' => $r['program'], 'pct' => $r['pct']], $rows), ['pct' => 'Attendance %'], 'percent');
        },
    ];
}

/* ---------------------------------------------------------------- Fees */

function reports_def_fee_collection(): array
{
    require_once APP_ROOT . '/app/services/fees.php';
    $statuses = ['success' => 'Successful', 'refunded' => 'Refunded', 'cancelled' => 'Cancelled'];
    return [
        'from' => 'payments py JOIN students s ON s.id = py.student_id LEFT JOIN programs p ON p.id = s.program_id LEFT JOIN student_fees sf ON sf.id = py.student_fee_id LEFT JOIN users u ON u.id = py.collected_by',
        'select' => 'py.id, py.receipt_no, py.payment_date, ' . reports_sn() . ' AS student, s.student_uid, p.short_name AS program, sf.semester_no AS semester, py.mode, py.reference_no,
            py.amount, py.fine_amount, py.amount + py.fine_amount AS total, py.status, u.name AS collected_by',
        'filters' => [
            'date' => ['col' => 'py.payment_date', 'label' => 'Payment date'],
            'session' => ['col' => 'sf.academic_session_id', 'label' => 'Invoice session'],
            'department' => ['col' => 's.department_id'],
            'program' => ['col' => 's.program_id'],
            'semester' => ['col' => 'sf.semester_no'],
            'status' => ['col' => 'py.status', 'options' => $statuses, 'default' => 'success'],
            'mode' => ['col' => 'py.mode', 'label' => 'Payment mode', 'options' => fees_modes()],
            'collector' => ['col' => 'py.collected_by', 'label' => 'Collected by', 'options_sql' => 'SELECT u.id AS value, u.name AS label FROM users u WHERE u.id IN (SELECT DISTINCT collected_by FROM payments WHERE collected_by IS NOT NULL) ORDER BY u.name'],
        ],
        'search' => ['py.receipt_no', 'py.reference_no', reports_name_search(), 's.student_uid'],
        'search_hint' => 'Receipt, reference, student…',
        'columns' => [
            ['key' => 'receipt_no', 'label' => 'Receipt', 'format' => 'code'],
            ['key' => 'payment_date', 'label' => 'Date', 'format' => 'date'],
            ['key' => 'student', 'label' => 'Student', 'format' => 'person', 'sub' => 'student_uid', 'sub_label' => 'Student ID'],
            ['key' => 'program', 'label' => 'Program'],
            ['key' => 'mode', 'label' => 'Mode', 'format' => 'label', 'labels' => fees_modes(), 'sub' => 'reference_no', 'sub_label' => 'Reference'],
            ['key' => 'amount', 'label' => 'Fee', 'format' => 'money'],
            ['key' => 'fine_amount', 'label' => 'Fine', 'format' => 'money'],
            ['key' => 'total', 'label' => 'Total', 'format' => 'money'],
            ['key' => 'collected_by', 'label' => 'Collected by'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'labels' => $statuses],
        ],
        'sorts' => ['receipt_no' => 'py.receipt_no', 'payment_date' => 'py.payment_date', 'student' => 'student', 'program' => 'program', 'mode' => 'py.mode', 'amount' => 'py.amount', 'total' => 'total'],
        'default_sort' => ['payment_date', 'desc'],
        'tiebreak' => 'py.id DESC',
        'kpis' => function (array $c) {
            $r = reports_one($c, "COUNT(*) AS receipts, COALESCE(SUM(CASE WHEN py.status = 'success' THEN py.amount + py.fine_amount END), 0) AS collected,
                COALESCE(SUM(CASE WHEN py.status = 'success' THEN py.fine_amount END), 0) AS fines, SUM(py.status = 'success') AS ok, SUM(py.status = 'cancelled') AS cancelled, COUNT(DISTINCT py.student_id) AS students");
            return [
                reports_kpi('Collected', $r['collected'], 'money', 'green', 'successful receipts incl. fines'),
                reports_kpi('Receipts', $r['receipts'], 'number', 'navy', number_in($r['students']) . ' students'),
                reports_kpi('Average receipt', $r['ok'] ? $r['collected'] / $r['ok'] : null, 'money', 'blue'),
                reports_kpi('Fines collected', $r['fines'], 'money', 'amber'),
                reports_kpi('Cancelled receipts', $r['cancelled'], 'number', 'red'),
            ];
        },
        'chart' => fn (array $c) => reports_timeseries($c, 'bar', 'Collection trend', ['Collected' => ['py.payment_date', 'SUM(py.amount + py.fine_amount)', "py.status = 'success'"]], 'money'),
    ];
}

function reports_def_fee_outstanding(): array
{
    require_once APP_ROOT . '/app/services/fees.php';
    // Oldest unpaid due date: earliest installment not yet fully paid, else the invoice due date (fees module rules).
    $oldest = 'COALESCE((SELECT MIN(fi.due_date) FROM fee_installments fi WHERE fi.student_fee_id = sf.id AND fi.paid_amount < fi.amount), sf.due_date)';
    $overdue = fees_overdue_sql('sf');
    $overdueAmt = fees_overdue_amount_sql('sf');
    return [
        'from' => 'student_fees sf JOIN students s ON s.id = sf.student_id LEFT JOIN programs p ON p.id = s.program_id',
        'where' => ["sf.status NOT IN ('cancelled', 'paid', 'waived')", 'sf.balance_amount > 0'],
        'select' => 'sf.id, sf.invoice_no, ' . reports_sn() . " AS student, s.student_uid, s.mobile, p.short_name AS program, sf.semester_no AS semester, sf.title,
            sf.net_amount, sf.paid_amount, sf.balance_amount, $overdueAmt AS overdue_amount, $oldest AS oldest_due,
            GREATEST(DATEDIFF(CURDATE(), $oldest), 0) AS days_overdue, CASE WHEN $overdue THEN 'overdue' ELSE sf.status END AS status",
        'filters' => [
            'date' => ['col' => 'sf.due_date', 'label' => 'Invoice due date'],
            'session' => ['col' => 'sf.academic_session_id'],
            'department' => ['col' => 's.department_id'],
            'program' => ['col' => 's.program_id'],
            'semester' => ['col' => 'sf.semester_no'],
            'ageing' => ['label' => 'Ageing', 'all_label' => 'Any', 'options' => ['not_due' => 'Nothing overdue yet', 'overdue' => 'Overdue', 'over30' => 'Overdue 30+ days', 'over60' => 'Overdue 60+ days', 'over90' => 'Overdue 90+ days'],
                'sql_map' => ['not_due' => "NOT $overdue", 'overdue' => $overdue, 'over30' => "$oldest < CURDATE() - INTERVAL 30 DAY", 'over60' => "$oldest < CURDATE() - INTERVAL 60 DAY", 'over90' => "$oldest < CURDATE() - INTERVAL 90 DAY"]],
        ],
        'search' => ['sf.invoice_no', reports_name_search(), 's.student_uid', 's.mobile'],
        'search_hint' => 'Invoice, student, mobile…',
        'note' => 'Overdue = unpaid installments past their due date (or the invoice due date when there is no installment plan).',
        'columns' => [
            ['key' => 'invoice_no', 'label' => 'Invoice', 'format' => 'code', 'sub' => 'title', 'sub_label' => 'Fee head'],
            ['key' => 'student', 'label' => 'Student', 'format' => 'person', 'sub' => 'student_uid', 'sub_label' => 'Student ID'],
            ['key' => 'program', 'label' => 'Program'],
            ['key' => 'semester', 'label' => 'Sem', 'format' => 'number'],
            ['key' => 'net_amount', 'label' => 'Net', 'format' => 'money'],
            ['key' => 'paid_amount', 'label' => 'Paid', 'format' => 'money'],
            ['key' => 'balance_amount', 'label' => 'Balance', 'format' => 'money'],
            ['key' => 'overdue_amount', 'label' => 'Overdue', 'format' => 'money'],
            ['key' => 'oldest_due', 'label' => 'Oldest unpaid due', 'format' => 'date'],
            ['key' => 'days_overdue', 'label' => 'Days late', 'format' => 'days'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
        ],
        'sorts' => ['invoice_no' => 'sf.invoice_no', 'student' => 'student', 'program' => 'program', 'semester' => 'sf.semester_no', 'net_amount' => 'sf.net_amount', 'balance_amount' => 'sf.balance_amount', 'overdue_amount' => 'overdue_amount', 'oldest_due' => 'oldest_due', 'days_overdue' => 'days_overdue'],
        'default_sort' => ['balance_amount', 'desc'],
        'tiebreak' => 'sf.id',
        'kpis' => function (array $c) use ($overdue, $overdueAmt) {
            $r = reports_one($c, "COUNT(*) AS invoices, COUNT(DISTINCT sf.student_id) AS students, COALESCE(SUM(sf.balance_amount), 0) AS balance, COALESCE(SUM(sf.paid_amount), 0) AS paid,
                COALESCE(SUM($overdueAmt), 0) AS overdue, SUM($overdue) AS overdue_count");
            return [
                reports_kpi('Outstanding', $r['balance'], 'money', 'red'),
                reports_kpi('Overdue amount', $r['overdue'], 'money', 'orange', number_in($r['overdue_count']) . ' invoices with overdue dues'),
                reports_kpi('Invoices with balance', $r['invoices'], 'number', 'navy'),
                reports_kpi('Students with dues', $r['students'], 'number', 'purple'),
                reports_kpi('Already paid on these', $r['paid'], 'money', 'green'),
            ];
        },
        'chart' => fn (array $c) => reports_chart('stacked', 'Outstanding by program',
            reports_all($c, "COALESCE(p.short_name, 'Unassigned') AS label, SUM($overdueAmt) AS overdue, SUM(sf.balance_amount) - SUM($overdueAmt) AS not_due", 'GROUP BY p.short_name ORDER BY SUM(sf.balance_amount) DESC LIMIT 12'),
            ['overdue' => ['Overdue', '#DC2626'], 'not_due' => ['Not yet due', '#22943F']], 'money'),
    ];
}

/* ---------------------------------------------------------------- Examinations & results */

function reports_exam_statuses(): array
{
    return ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'marks_entry' => 'Marks entry', 'verified' => 'Verified', 'published' => 'Published'];
}

function reports_exam_options_sql(): string
{
    // Exam names usually carry the program + semester already ("BBA Sem 1 — End Semester…"); append them only when missing.
    return "SELECT e.id AS value, CASE WHEN p.short_name IS NOT NULL AND LOCATE(p.short_name, e.name) > 0 THEN e.name
            ELSE CONCAT(e.name, COALESCE(CONCAT(' · ', p.short_name), ''), COALESCE(CONCAT(' S', e.semester_no), '')) END AS label
        FROM exams e LEFT JOIN programs p ON p.id = e.program_id ORDER BY e.start_date DESC, e.id DESC LIMIT 300";
}

function reports_def_exam_overview(): array
{
    return [
        'from' => 'exams e LEFT JOIN exam_types et ON et.id = e.exam_type_id LEFT JOIN programs p ON p.id = e.program_id',
        'select' => "e.id, e.name, et.name AS exam_type, p.short_name AS program, e.semester_no AS semester, e.start_date, e.end_date, e.result_date, e.status,
            (SELECT COUNT(*) FROM exam_subjects xs WHERE xs.exam_id = e.id) AS subjects,
            (SELECT COUNT(*) FROM exam_students es WHERE es.exam_id = e.id) AS registered,
            (SELECT COUNT(*) FROM exam_students es WHERE es.exam_id = e.id AND es.is_eligible = 0) AS ineligible,
            (SELECT ROUND(SUM(r.result_status = 'PASS') * 100 / NULLIF(COUNT(*), 0), 1) FROM results r WHERE r.exam_id = e.id) AS pass_pct",
        'filters' => [
            'date' => ['col' => 'e.start_date', 'label' => 'Exam start date'],
            'session' => ['col' => 'e.academic_session_id'],
            'department' => ['col' => 'p.department_id'],
            'program' => ['col' => 'e.program_id'],
            'semester' => ['col' => 'e.semester_no'],
            'status' => ['col' => 'e.status', 'options' => reports_exam_statuses()],
            'exam_type' => ['col' => 'e.exam_type_id', 'label' => 'Exam type', 'options_sql' => "SELECT id AS value, name AS label FROM exam_types ORDER BY name"],
        ],
        'search' => ['e.name', 'p.short_name'],
        'search_hint' => 'Exam or program…',
        'columns' => [
            ['key' => 'name', 'label' => 'Exam', 'format' => 'strong', 'sub' => 'exam_type', 'sub_label' => 'Exam type'],
            ['key' => 'program', 'label' => 'Program'],
            ['key' => 'semester', 'label' => 'Sem', 'format' => 'number'],
            ['key' => 'start_date', 'label' => 'Starts', 'format' => 'date'],
            ['key' => 'end_date', 'label' => 'Ends', 'format' => 'date'],
            ['key' => 'subjects', 'label' => 'Papers', 'format' => 'number'],
            ['key' => 'registered', 'label' => 'Registered', 'format' => 'number'],
            ['key' => 'ineligible', 'label' => 'Ineligible', 'format' => 'number'],
            ['key' => 'pass_pct', 'label' => 'Pass %', 'format' => 'percent'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'labels' => reports_exam_statuses()],
        ],
        'sorts' => ['name' => 'e.name', 'program' => 'program', 'semester' => 'e.semester_no', 'start_date' => 'e.start_date', 'end_date' => 'e.end_date', 'status' => 'e.status'],
        'default_sort' => ['start_date', 'desc'],
        'tiebreak' => 'e.id DESC',
        'kpis' => function (array $c) {
            $r = reports_one($c, "COUNT(*) AS total, SUM(e.status = 'published') AS published, SUM(e.start_date > CURDATE()) AS upcoming,
                SUM(e.start_date <= CURDATE() AND COALESCE(e.end_date, e.start_date) >= CURDATE()) AS ongoing");
            $s = db_row("SELECT COUNT(*) AS registered, COALESCE(SUM(es.is_eligible = 0), 0) AS ineligible FROM exam_students es WHERE es.exam_id IN (SELECT e.id {$c['base']})", $c['args']) ?? [];
            return [
                reports_kpi('Exams', $r['total'], 'number', 'navy'),
                reports_kpi('Upcoming', $r['upcoming'], 'number', 'blue', number_in($r['ongoing']) . ' in progress today'),
                reports_kpi('Results published', $r['published'], 'number', 'green'),
                reports_kpi('Registrations', $s['registered'] ?? 0, 'number', 'purple', 'student × exam'),
                reports_kpi('Ineligible', $s['ineligible'] ?? 0, 'number', 'red', reports_share($s['ineligible'] ?? 0, $s['registered'] ?? 0)),
            ];
        },
        'chart' => fn (array $c) => reports_chart('bar', 'Exams by status', reports_all($c, 'e.status AS label, COUNT(*) AS v', "GROUP BY e.status ORDER BY FIELD(e.status, 'draft', 'scheduled', 'marks_entry', 'verified', 'published')"), ['v' => 'Exams'], 'number', 'label', reports_exam_statuses()),
    ];
}

function reports_def_exam_subjects(): array
{
    return [
        'from' => 'exam_marks m JOIN exams e ON e.id = m.exam_id JOIN subjects sb ON sb.id = m.subject_id LEFT JOIN programs p ON p.id = e.program_id',
        'group' => 'e.id, e.name, sb.id, sb.code, sb.name, p.short_name, e.semester_no',
        'select' => "CONCAT(e.id, '-', sb.id) AS id, e.name AS exam, sb.code AS subject_code, sb.name AS subject, p.short_name AS program, e.semester_no AS semester,
            SUM(m.is_absent = 0) AS appeared, SUM(m.is_absent = 1) AS absent, SUM(m.is_pass = 1 AND m.is_absent = 0) AS passed,
            ROUND(SUM(m.is_pass = 1 AND m.is_absent = 0) * 100 / NULLIF(SUM(m.is_absent = 0), 0), 1) AS pass_pct,
            ROUND(AVG(CASE WHEN m.is_absent = 0 AND m.max_marks > 0 THEN m.total_marks * 100 / m.max_marks END), 1) AS avg_pct, MAX(m.total_marks) AS highest, MAX(m.max_marks) AS max_marks",
        'filters' => [
            'date' => ['col' => 'e.start_date', 'label' => 'Exam start date'],
            'session' => ['col' => 'e.academic_session_id'],
            'department' => ['col' => 'p.department_id'],
            'program' => ['col' => 'e.program_id'],
            'semester' => ['col' => 'e.semester_no'],
            'exam' => ['col' => 'm.exam_id', 'label' => 'Exam', 'all_label' => 'All exams', 'options_sql' => reports_exam_options_sql()],
            'status' => ['col' => 'm.status', 'label' => 'Marks status', 'options' => ['draft' => 'Draft', 'submitted' => 'Submitted', 'verified' => 'Verified', 'published' => 'Published']],
        ],
        'search' => ['sb.name', 'sb.code', 'e.name'],
        'search_hint' => 'Subject, code or exam…',
        'columns' => [
            ['key' => 'subject', 'label' => 'Subject', 'format' => 'strong', 'sub' => 'subject_code', 'sub_label' => 'Code'],
            ['key' => 'exam', 'label' => 'Exam'],
            ['key' => 'program', 'label' => 'Program'],
            ['key' => 'semester', 'label' => 'Sem', 'format' => 'number'],
            ['key' => 'appeared', 'label' => 'Appeared', 'format' => 'number'],
            ['key' => 'absent', 'label' => 'Absent', 'format' => 'number'],
            ['key' => 'passed', 'label' => 'Passed', 'format' => 'number'],
            ['key' => 'pass_pct', 'label' => 'Pass %', 'format' => 'progress', 'warn_below' => 60],
            ['key' => 'avg_pct', 'label' => 'Avg score', 'format' => 'percent'],
            ['key' => 'highest', 'label' => 'Highest', 'format' => 'decimal'],
        ],
        'sorts' => array_fill_keys(['subject', 'exam', 'program', 'semester', 'appeared', 'absent', 'passed', 'pass_pct', 'avg_pct', 'highest'], true),
        'default_sort' => ['pass_pct', 'asc'],
        'kpis' => function (array $c) {
            $rows = $c['rows_all'];
            $app = array_sum(array_column($rows, 'appeared'));
            $pass = array_sum(array_column($rows, 'passed'));
            $scores = array_filter(array_column($rows, 'avg_pct'), fn ($v) => $v !== null);
            return [
                reports_kpi('Exam papers', count($rows), 'number', 'navy'),
                reports_kpi('Appearances', $app, 'number', 'blue'),
                reports_kpi('Overall pass %', reports_pct($pass, $app), 'percent', 'green'),
                reports_kpi('Average score', $scores ? array_sum($scores) / count($scores) : null, 'percent', 'purple', 'mean of paper averages'),
                reports_kpi('Absentees', array_sum(array_column($rows, 'absent')), 'number', 'red'),
            ];
        },
        'chart' => function (array $c) {
            $rows = array_filter($c['rows_all'], fn ($r) => $r['pass_pct'] !== null);
            usort($rows, fn ($a, $b) => (float) $a['pass_pct'] <=> (float) $b['pass_pct']);
            $rows = array_slice($rows, 0, 12);
            foreach ($rows as &$r) {
                $r['label'] = $r['subject_code'] . ' · ' . $r['program'];
            }
            return reports_chart('hbar', 'Lowest pass % papers', $rows, ['pass_pct' => 'Pass %'], 'percent');
        },
    ];
}

function reports_result_statuses(): array
{
    return ['PASS' => 'Pass', 'FAIL' => 'Fail', 'BACKLOG' => 'Backlog', 'WITHHELD' => 'Withheld'];
}

function reports_result_common_filters(): array
{
    return [
        'date' => ['col' => 'COALESCE(r.published_at, r.computed_at)', 'label' => 'Result date', 'datetime' => true],
        'session' => ['col' => 'r.academic_session_id'],
        'department' => ['col' => 'p.department_id'],
        'program' => ['col' => 'r.program_id'],
        'semester' => ['col' => 'r.semester_no'],
        'exam' => ['col' => 'r.exam_id', 'label' => 'Exam', 'all_label' => 'All exams', 'options_sql' => reports_exam_options_sql()],
        'published' => ['label' => 'Publication', 'options' => ['yes' => 'Published', 'no' => 'Not published'], 'sql_map' => ['yes' => 'r.is_published = 1', 'no' => 'r.is_published = 0']],
    ];
}

function reports_def_result_register(): array
{
    $statuses = reports_result_statuses();
    return [
        'from' => 'results r JOIN students s ON s.id = r.student_id LEFT JOIN exams e ON e.id = r.exam_id LEFT JOIN programs p ON p.id = r.program_id',
        'select' => 'r.id, ' . reports_sn() . ' AS student, s.student_uid, p.short_name AS program, r.semester_no AS semester, e.name AS exam, r.total_marks, r.max_marks,
            r.percentage, r.sgpa, r.cgpa, r.backlog_count, r.division, r.class_rank, r.result_status',
        'filters' => reports_result_common_filters() + [
            'status' => ['col' => 'r.result_status', 'label' => 'Result', 'options' => $statuses],
            'division' => ['col' => 'r.division', 'options_sql' => 'SELECT DISTINCT division AS value, division AS label FROM results WHERE division IS NOT NULL ORDER BY division'],
        ],
        'search' => [reports_name_search(), 's.student_uid', 's.roll_no'],
        'search_hint' => 'Name, student ID, roll no…',
        'columns' => [
            ['key' => 'student', 'label' => 'Student', 'format' => 'person', 'sub' => 'student_uid', 'sub_label' => 'Student ID'],
            ['key' => 'program', 'label' => 'Program'],
            ['key' => 'semester', 'label' => 'Sem', 'format' => 'number'],
            ['key' => 'exam', 'label' => 'Exam'],
            ['key' => 'percentage', 'label' => 'Percentage', 'format' => 'percent'],
            ['key' => 'sgpa', 'label' => 'SGPA', 'format' => 'decimal'],
            ['key' => 'cgpa', 'label' => 'CGPA', 'format' => 'decimal'],
            ['key' => 'backlog_count', 'label' => 'Backlogs', 'format' => 'number'],
            ['key' => 'division', 'label' => 'Division'],
            ['key' => 'class_rank', 'label' => 'Rank', 'format' => 'number'],
            ['key' => 'result_status', 'label' => 'Result', 'format' => 'badge', 'labels' => $statuses],
        ],
        'sorts' => ['student' => 'student', 'program' => 'program', 'semester' => 'r.semester_no', 'percentage' => 'r.percentage', 'sgpa' => 'r.sgpa', 'cgpa' => 'r.cgpa', 'backlog_count' => 'r.backlog_count', 'class_rank' => 'r.class_rank IS NULL, r.class_rank'],
        'default_sort' => ['sgpa', 'desc'],
        'tiebreak' => 'r.id',
        'kpis' => function (array $c) {
            $r = reports_one($c, "COUNT(*) AS total, SUM(r.result_status = 'PASS') AS passed, SUM(r.result_status = 'WITHHELD') AS withheld, SUM(r.backlog_count > 0) AS backlogs,
                AVG(r.sgpa) AS sgpa, MAX(r.sgpa) AS top, AVG(r.percentage) AS pct");
            return [
                reports_kpi('Results', $r['total'], 'number', 'navy'),
                reports_kpi('Pass %', reports_pct($r['passed'], (int) $r['total'] - (int) $r['withheld']), 'percent', 'green', number_in($r['passed']) . ' passed'),
                $r['sgpa'] !== null ? reports_kpi('Average SGPA', $r['sgpa'], 'decimal', 'blue', $r['top'] !== null ? 'top ' . number_in((float) $r['top'], 2) : null)
                    : reports_kpi('Average percentage', $r['pct'], 'percent', 'blue', 'no SGPA for these exams'),
                reports_kpi('With backlogs', $r['backlogs'], 'number', 'red', reports_share($r['backlogs'], $r['total'])),
                reports_kpi('Withheld', $r['withheld'], 'number', 'amber'),
            ];
        },
        'chart' => fn (array $c) => reports_chart('doughnut', 'Results by outcome', reports_all($c, 'r.result_status AS label, COUNT(*) AS v', 'GROUP BY r.result_status ORDER BY v DESC'), ['v' => 'Students'], 'number', 'label', reports_result_statuses()),
    ];
}

function reports_def_result_programs(): array
{
    return [
        'from' => 'results r LEFT JOIN programs p ON p.id = r.program_id LEFT JOIN departments d ON d.id = p.department_id',
        'group' => 'r.program_id, p.short_name, d.name, r.semester_no',
        'select' => "MIN(r.id) AS id, COALESCE(p.short_name, 'Unassigned') AS program, d.name AS department, r.semester_no AS semester, COUNT(*) AS appeared,
            SUM(r.result_status = 'PASS') AS passed, SUM(r.result_status = 'FAIL') AS failed, SUM(r.result_status = 'BACKLOG') AS backlog, SUM(r.result_status = 'WITHHELD') AS withheld,
            ROUND(SUM(r.result_status = 'PASS') * 100 / NULLIF(SUM(r.result_status <> 'WITHHELD'), 0), 1) AS pass_pct, ROUND(AVG(r.sgpa), 2) AS avg_sgpa, MAX(r.sgpa) AS top_sgpa, ROUND(AVG(r.percentage), 1) AS avg_pct",
        'filters' => reports_result_common_filters(),
        'columns' => [
            ['key' => 'program', 'label' => 'Program', 'format' => 'strong', 'sub' => 'department', 'sub_label' => 'Department'],
            ['key' => 'semester', 'label' => 'Sem', 'format' => 'number'],
            ['key' => 'appeared', 'label' => 'Results', 'format' => 'number'],
            ['key' => 'passed', 'label' => 'Passed', 'format' => 'number'],
            ['key' => 'backlog', 'label' => 'Backlog', 'format' => 'number'],
            ['key' => 'failed', 'label' => 'Failed', 'format' => 'number'],
            ['key' => 'withheld', 'label' => 'Withheld', 'format' => 'number'],
            ['key' => 'pass_pct', 'label' => 'Pass %', 'format' => 'progress', 'warn_below' => 60],
            ['key' => 'avg_pct', 'label' => 'Avg %', 'format' => 'percent'],
            ['key' => 'avg_sgpa', 'label' => 'Avg SGPA', 'format' => 'decimal'],
            ['key' => 'top_sgpa', 'label' => 'Top SGPA', 'format' => 'decimal'],
        ],
        'sorts' => array_fill_keys(['program', 'semester', 'appeared', 'passed', 'backlog', 'failed', 'pass_pct', 'avg_pct', 'avg_sgpa', 'top_sgpa'], true),
        'default_sort' => ['program', 'asc'],
        'kpis' => function (array $c) {
            $rows = $c['rows_all'];
            $app = array_sum(array_column($rows, 'appeared'));
            $wh = array_sum(array_column($rows, 'withheld'));
            $low = null;
            foreach ($rows as $r) {
                if ($r['pass_pct'] !== null && (!$low || (float) $r['pass_pct'] < (float) $low['pass_pct'])) {
                    $low = $r;
                }
            }
            $sg = reports_one($c, 'AVG(r.sgpa) AS sgpa, AVG(r.percentage) AS pct');
            return [
                reports_kpi('Results', $app, 'number', 'navy', count($rows) . ' program-semester cohorts'),
                reports_kpi('Pass %', reports_pct(array_sum(array_column($rows, 'passed')), $app - $wh), 'percent', 'green'),
                ($sg['sgpa'] ?? null) !== null ? reports_kpi('Average SGPA', $sg['sgpa'], 'decimal', 'blue') : reports_kpi('Average percentage', $sg['pct'] ?? null, 'percent', 'blue', 'no SGPA for these exams'),
                reports_kpi('Backlog / fail', array_sum(array_column($rows, 'backlog')) + array_sum(array_column($rows, 'failed')), 'number', 'red'),
                reports_kpi('Lowest pass %', $low ? $low['program'] . ' · Sem ' . $low['semester'] : '—', 'text', 'amber', $low ? $low['pass_pct'] . '%' : null),
            ];
        },
        'chart' => function (array $c) {
            $agg = reports_sum_by($c['rows_all'], 'program', ['passed', 'appeared', 'withheld']);
            foreach ($agg as &$a) {
                $a['pct'] = reports_pct($a['passed'], $a['appeared'] - $a['withheld']) ?? 0;
            }
            usort($agg, fn ($x, $y) => $y['pct'] <=> $x['pct']);
            return reports_chart('bar', 'Pass % by program', $agg, ['pct' => 'Pass %'], 'percent');
        },
    ];
}

/* ---------------------------------------------------------------- Faculty */

function reports_faculty_statuses(): array
{
    return ['active' => 'Active', 'on_leave' => 'On Leave', 'resigned' => 'Resigned', 'retired' => 'Retired', 'inactive' => 'Inactive'];
}

function reports_employment_types(): array
{
    return ['permanent' => 'Permanent', 'contract' => 'Contract', 'probation' => 'Probation', 'visiting' => 'Visiting', 'guest' => 'Guest'];
}

function reports_def_faculty_directory(): array
{
    return [
        'from' => 'faculty f LEFT JOIN departments d ON d.id = f.department_id',
        'select' => "f.id, TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS faculty, f.employee_id, d.name AS department, f.designation, f.qualification, f.specialization,
            f.experience_years, f.employment_type, f.joining_date, f.publications_count, f.email, f.phone, f.status",
        'filters' => [
            'date' => ['col' => 'f.joining_date', 'label' => 'Joining date'],
            'department' => ['col' => 'f.department_id'],
            'status' => ['col' => 'f.status', 'options' => reports_faculty_statuses()],
            'employment' => ['col' => 'f.employment_type', 'label' => 'Employment', 'options' => reports_employment_types()],
            'designation' => ['col' => 'f.designation', 'options_sql' => "SELECT DISTINCT designation AS value, designation AS label FROM faculty WHERE designation IS NOT NULL AND designation <> '' ORDER BY designation"],
            'gender' => ['col' => 'f.gender', 'options' => ['male' => 'Male', 'female' => 'Female', 'other' => 'Other']],
        ],
        'search' => ["CONCAT_WS(' ', f.first_name, f.last_name)", 'f.employee_id', 'f.email', 'f.specialization'],
        'search_hint' => 'Name, employee ID, email, specialisation…',
        'columns' => [
            ['key' => 'faculty', 'label' => 'Faculty', 'format' => 'person', 'sub' => 'employee_id', 'sub_label' => 'Employee ID'],
            ['key' => 'department', 'label' => 'Department'],
            ['key' => 'designation', 'label' => 'Designation'],
            ['key' => 'qualification', 'label' => 'Qualification', 'sub' => 'specialization', 'sub_label' => 'Specialisation'],
            ['key' => 'experience_years', 'label' => 'Experience', 'format' => 'number', 'suffix' => 'yrs'],
            ['key' => 'employment_type', 'label' => 'Employment', 'format' => 'label', 'labels' => reports_employment_types()],
            ['key' => 'joining_date', 'label' => 'Joined', 'format' => 'date'],
            ['key' => 'publications_count', 'label' => 'Publications', 'format' => 'number'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'labels' => reports_faculty_statuses()],
        ],
        'sorts' => ['faculty' => 'f.first_name', 'department' => 'department', 'designation' => 'f.designation', 'experience_years' => 'f.experience_years', 'joining_date' => 'f.joining_date', 'publications_count' => 'f.publications_count', 'status' => 'f.status'],
        'default_sort' => ['department', 'asc'],
        'tiebreak' => 'f.first_name, f.id',
        'kpis' => function (array $c) {
            $r = reports_one($c, "COUNT(*) AS total, SUM(f.employment_type = 'permanent') AS permanent, SUM(f.qualification LIKE '%Ph.D%' OR f.qualification LIKE '%PhD%') AS phd,
                AVG(f.experience_years) AS exp, COALESCE(SUM(f.publications_count), 0) AS pubs");
            return [
                reports_kpi('Faculty', $r['total'], 'number', 'navy'),
                reports_kpi('Permanent', $r['permanent'], 'number', 'green', reports_share($r['permanent'], $r['total'])),
                reports_kpi('Ph.D. holders', $r['phd'], 'number', 'purple', reports_share($r['phd'], $r['total'])),
                reports_kpi('Average experience', $r['exp'], 'decimal', 'blue', 'years'),
                reports_kpi('Publications', $r['pubs'], 'number', 'orange'),
            ];
        },
        'chart' => fn (array $c) => reports_chart('stacked', 'Faculty by department', reports_all($c, "COALESCE(d.name, 'Unassigned') AS label, SUM(f.employment_type = 'permanent') AS permanent, SUM(f.employment_type <> 'permanent' OR f.employment_type IS NULL) AS other", 'GROUP BY d.name ORDER BY COUNT(*) DESC LIMIT 12'),
            ['permanent' => 'Permanent', 'other' => 'Contract / visiting / other']),
    ];
}

function reports_def_faculty_workload(): array
{
    return [
        'prepare' => function (array $f) {
            $sid = $f['session_id'] ?? null;
            $from = $f['from'];
            $to = $f['to'];
            if ($sid && !$from && !$to) {
                $ses = db_row('SELECT start_date, end_date FROM academic_sessions WHERE id = ?', [$sid]);
                $from = $ses['start_date'] ?? null;
                $to = $ses['end_date'] ?? null;
            }
            $args = [];
            $subWhere = '';
            if ($sid) {
                $subWhere = 'WHERE fs.academic_session_id = ?';
                $args[] = $sid;
            }
            $clsWhere = "WHERE a.type = 'student' AND a.faculty_id IS NOT NULL";
            if ($sid) {
                $clsWhere .= ' AND a.academic_session_id = ?';
                $args[] = $sid;
            }
            if ($f['from']) {
                $clsWhere .= ' AND a.attendance_date >= ?';
                $args[] = $f['from'];
            }
            if ($f['to']) {
                $clsWhere .= ' AND a.attendance_date <= ?';
                $args[] = $f['to'];
            }
            $lvWhere = "WHERE l.employee_type = 'faculty' AND l.status = 'approved'";
            if ($from) {
                $lvWhere .= ' AND l.to_date >= ?';
                $args[] = $from;
            }
            if ($to) {
                $lvWhere .= ' AND l.from_date <= ?';
                $args[] = $to;
            }
            $sql = "faculty f LEFT JOIN departments d ON d.id = f.department_id
                LEFT JOIN (SELECT fs.faculty_id, COUNT(DISTINCT fs.subject_id) AS subjects, COUNT(DISTINCT fs.section_id) AS sections FROM faculty_subjects fs $subWhere GROUP BY fs.faculty_id) ws ON ws.faculty_id = f.id
                LEFT JOIN (SELECT a.faculty_id, COUNT(*) AS classes, COUNT(DISTINCT a.attendance_date) AS days, MAX(a.attendance_date) AS last_class FROM attendance a $clsWhere GROUP BY a.faculty_id) wc ON wc.faculty_id = f.id
                LEFT JOIN (SELECT l.employee_id, SUM(l.days) AS leave_days FROM employee_leaves l $lvWhere GROUP BY l.employee_id) wl ON wl.employee_id = f.id";
            return [$sql, $args];
        },
        'select' => "f.id, TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS faculty, f.employee_id, d.name AS department, f.designation,
            COALESCE(ws.subjects, 0) AS subjects, COALESCE(ws.sections, 0) AS sections, COALESCE(wc.classes, 0) AS classes, COALESCE(wc.days, 0) AS teaching_days,
            wc.last_class, COALESCE(wl.leave_days, 0) AS leave_days",
        'filters' => [
            'date' => ['label' => 'Period', 'apply' => false],
            'session' => ['apply' => false],
            'department' => ['col' => 'f.department_id'],
            'status' => ['col' => 'f.status', 'options' => reports_faculty_statuses(), 'default' => 'active'],
            'employment' => ['col' => 'f.employment_type', 'label' => 'Employment', 'options' => reports_employment_types()],
        ],
        'search' => ["CONCAT_WS(' ', f.first_name, f.last_name)", 'f.employee_id'],
        'search_hint' => 'Name or employee ID…',
        'note' => 'Classes = student attendance sheets taken by the faculty member; leave = approved leave days overlapping the period.',
        'columns' => [
            ['key' => 'faculty', 'label' => 'Faculty', 'format' => 'person', 'sub' => 'employee_id', 'sub_label' => 'Employee ID'],
            ['key' => 'department', 'label' => 'Department', 'sub' => 'designation', 'sub_label' => 'Designation'],
            ['key' => 'subjects', 'label' => 'Subjects', 'format' => 'number'],
            ['key' => 'sections', 'label' => 'Sections', 'format' => 'number'],
            ['key' => 'classes', 'label' => 'Classes taken', 'format' => 'number'],
            ['key' => 'teaching_days', 'label' => 'Teaching days', 'format' => 'number'],
            ['key' => 'last_class', 'label' => 'Last class', 'format' => 'date'],
            ['key' => 'leave_days', 'label' => 'Leave days', 'format' => 'decimal'],
        ],
        'sorts' => ['faculty' => 'f.first_name', 'department' => 'department', 'subjects' => 'subjects', 'sections' => 'sections', 'classes' => 'classes', 'teaching_days' => 'teaching_days', 'last_class' => 'wc.last_class', 'leave_days' => 'leave_days'],
        'default_sort' => ['classes', 'desc'],
        'tiebreak' => 'f.id',
        'kpis' => function (array $c) {
            $r = reports_one($c, 'COUNT(*) AS total, COALESCE(SUM(wc.classes), 0) AS classes, COALESCE(SUM(ws.subjects), 0) AS subjects, COALESCE(SUM(wl.leave_days), 0) AS leave_days, SUM(COALESCE(wc.classes, 0) = 0) AS idle');
            return [
                reports_kpi('Faculty', $r['total'], 'number', 'navy'),
                reports_kpi('Classes conducted', $r['classes'], 'number', 'blue'),
                reports_kpi('Classes per faculty', $r['total'] ? $r['classes'] / $r['total'] : null, 'decimal', 'green'),
                reports_kpi('Subject assignments', $r['subjects'], 'number', 'purple'),
                reports_kpi('Approved leave', $r['leave_days'], 'decimal', 'amber', 'days · ' . number_in($r['idle']) . ' faculty without classes'),
            ];
        },
        'chart' => fn (array $c) => reports_chart('hbar', 'Classes conducted (top 12)', reports_all($c, "TRIM(CONCAT_WS(' ', f.first_name, f.last_name)) AS label, COALESCE(wc.classes, 0) AS v", 'ORDER BY v DESC LIMIT 12'), ['v' => 'Classes']),
    ];
}

/* ---------------------------------------------------------------- Library */

function reports_member_name_sql(): string
{
    return "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', s.first_name, s.last_name)), ''), NULLIF(TRIM(CONCAT_WS(' ', f.first_name, f.last_name)), ''), TRIM(CONCAT_WS(' ', st.first_name, st.last_name)))";
}

function reports_def_library_circulation(): array
{
    $status = "CASE WHEN lt.status = 'issued' AND lt.due_date < CURDATE() THEN 'overdue' ELSE lt.status END";
    return [
        'from' => 'library_transactions lt JOIN books b ON b.id = lt.book_id LEFT JOIN book_copies bc ON bc.id = lt.book_copy_id LEFT JOIN library_members lm ON lm.id = lt.member_id
            LEFT JOIN students s ON s.id = lm.student_id LEFT JOIN faculty f ON f.id = lm.faculty_id LEFT JOIN staff st ON st.id = lm.staff_id',
        'select' => "lt.id, bc.accession_no, b.title AS book, b.isbn, " . reports_member_name_sql() . " AS member, lm.membership_no, lm.member_type, lt.issue_date, lt.due_date, lt.return_date,
            CASE WHEN lt.return_date IS NULL AND lt.due_date < CURDATE() THEN DATEDIFF(CURDATE(), lt.due_date) WHEN lt.return_date > lt.due_date THEN DATEDIFF(lt.return_date, lt.due_date) ELSE 0 END AS days_late,
            lt.fine_amount, $status AS status",
        'filters' => [
            'date' => ['col' => 'lt.issue_date', 'label' => 'Issue date'],
            'department' => ['col' => 'COALESCE(s.department_id, f.department_id, st.department_id)'],
            'program' => ['col' => 's.program_id'],
            'status' => ['options' => ['issued' => 'On loan (not due)', 'overdue' => 'Overdue', 'returned' => 'Returned', 'lost' => 'Lost'],
                'sql_map' => ['issued' => "lt.status = 'issued' AND lt.due_date >= CURDATE()", 'overdue' => "lt.status = 'issued' AND lt.due_date < CURDATE()", 'returned' => "lt.status = 'returned'", 'lost' => "lt.status = 'lost'"]],
            'member_type' => ['col' => 'lm.member_type', 'label' => 'Member type', 'options' => ['student' => 'Students', 'faculty' => 'Faculty', 'staff' => 'Staff']],
            'category' => ['col' => 'b.category_id', 'label' => 'Book category', 'options_sql' => 'SELECT id AS value, name AS label FROM book_categories ORDER BY name'],
        ],
        'search' => ['b.title', 'b.isbn', 'bc.accession_no', 'lm.membership_no', reports_name_search('s'), reports_name_search('f')],
        'search_hint' => 'Book, ISBN, accession, member…',
        'columns' => [
            ['key' => 'accession_no', 'label' => 'Accession', 'format' => 'code'],
            ['key' => 'book', 'label' => 'Book', 'format' => 'strong', 'sub' => 'isbn', 'sub_label' => 'ISBN'],
            ['key' => 'member', 'label' => 'Member', 'format' => 'person', 'sub' => 'membership_no', 'sub_label' => 'Membership no'],
            ['key' => 'member_type', 'label' => 'Type', 'format' => 'label'],
            ['key' => 'issue_date', 'label' => 'Issued', 'format' => 'date'],
            ['key' => 'due_date', 'label' => 'Due', 'format' => 'date'],
            ['key' => 'return_date', 'label' => 'Returned', 'format' => 'date'],
            ['key' => 'days_late', 'label' => 'Late', 'format' => 'days'],
            ['key' => 'fine_amount', 'label' => 'Fine', 'format' => 'money'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
        ],
        'sorts' => ['accession_no' => 'bc.accession_no', 'book' => 'b.title', 'member' => 'member', 'issue_date' => 'lt.issue_date', 'due_date' => 'lt.due_date', 'return_date' => 'lt.return_date', 'days_late' => 'days_late', 'fine_amount' => 'lt.fine_amount'],
        'default_sort' => ['issue_date', 'desc'],
        'tiebreak' => 'lt.id DESC',
        'kpis' => function (array $c) {
            $r = reports_one($c, "COUNT(*) AS total, SUM(lt.status = 'issued') AS on_loan, SUM(lt.status = 'issued' AND lt.due_date < CURDATE()) AS overdue, SUM(lt.status = 'returned') AS returned,
                SUM(lt.status = 'lost') AS lost, COALESCE(SUM(lt.fine_amount), 0) AS fines");
            return [
                reports_kpi('Transactions', $r['total'], 'number', 'navy'),
                reports_kpi('On loan now', $r['on_loan'], 'number', 'blue'),
                reports_kpi('Overdue', $r['overdue'], 'number', 'red', reports_pct($r['overdue'], $r['on_loan']) !== null ? reports_pct($r['overdue'], $r['on_loan']) . '% of current loans' : null),
                reports_kpi('Returned', $r['returned'], 'number', 'green', number_in($r['lost']) . ' lost'),
                reports_kpi('Fines charged', $r['fines'], 'money', 'amber'),
            ];
        },
        'chart' => fn (array $c) => reports_timeseries($c, 'bar', 'Issues and returns', ['Issued' => ['lt.issue_date', 'COUNT(*)'], 'Returned' => ['lt.return_date', 'COUNT(*)']]),
    ];
}

function reports_def_library_inventory(): array
{
    return [
        'from' => 'books b LEFT JOIN book_categories c ON c.id = b.category_id LEFT JOIN book_copies bc ON bc.book_id = b.id',
        'group' => 'b.category_id, c.name',
        'select' => "COALESCE(b.category_id, 0) AS id, COALESCE(c.name, 'Uncategorised') AS category, COUNT(DISTINCT b.id) AS titles, COUNT(bc.id) AS copies,
            SUM(bc.status = 'available') AS available, SUM(bc.status = 'issued') AS issued, SUM(bc.status IN ('lost', 'damaged', 'withdrawn')) AS lost,
            ROUND(SUM(bc.status = 'issued') * 100 / NULLIF(COUNT(bc.id), 0), 1) AS utilisation, COALESCE(SUM(COALESCE(bc.price, b.price)), 0) AS value",
        'filters' => [
            'department' => ['col' => 'b.department_id'],
            'status' => ['col' => 'b.status', 'label' => 'Title status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
            'type' => ['col' => 'b.type', 'label' => 'Type', 'options' => ['book' => 'Book', 'reference' => 'Reference', 'journal' => 'Journal', 'magazine' => 'Magazine', 'thesis' => 'Thesis', 'ebook' => 'E-book']],
            'language' => ['col' => 'b.language', 'options_sql' => "SELECT DISTINCT language AS value, language AS label FROM books WHERE language IS NOT NULL AND language <> '' ORDER BY language"],
        ],
        'search' => ['b.title', 'b.isbn'],
        'search_hint' => 'Limit to titles matching…',
        'columns' => [
            ['key' => 'category', 'label' => 'Category', 'format' => 'strong'],
            ['key' => 'titles', 'label' => 'Titles', 'format' => 'number'],
            ['key' => 'copies', 'label' => 'Copies', 'format' => 'number'],
            ['key' => 'available', 'label' => 'Available', 'format' => 'number'],
            ['key' => 'issued', 'label' => 'On loan', 'format' => 'number'],
            ['key' => 'lost', 'label' => 'Lost / damaged / withdrawn', 'format' => 'number'],
            ['key' => 'utilisation', 'label' => 'On loan %', 'format' => 'progress'],
            ['key' => 'value', 'label' => 'Inventory value', 'format' => 'money'],
        ],
        'sorts' => array_fill_keys(['category', 'titles', 'copies', 'available', 'issued', 'lost', 'utilisation', 'value'], true),
        'default_sort' => ['copies', 'desc'],
        'kpis' => function (array $c) {
            $rows = $c['rows_all'];
            $copies = array_sum(array_column($rows, 'copies'));
            return [
                reports_kpi('Titles', array_sum(array_column($rows, 'titles')), 'number', 'navy', count($rows) . ' categories'),
                reports_kpi('Copies', $copies, 'number', 'blue'),
                reports_kpi('Available', array_sum(array_column($rows, 'available')), 'number', 'green', reports_share(array_sum(array_column($rows, 'available')), $copies)),
                reports_kpi('On loan', array_sum(array_column($rows, 'issued')), 'number', 'purple', number_in(array_sum(array_column($rows, 'lost'))) . ' lost / damaged / withdrawn'),
                reports_kpi('Inventory value', array_sum(array_column($rows, 'value')), 'money', 'amber'),
            ];
        },
        'chart' => function (array $c) {
            $rows = $c['rows_all'];
            usort($rows, fn ($a, $b) => (int) $b['copies'] <=> (int) $a['copies']);
            return reports_chart('stacked', 'Copies by category', array_slice($rows, 0, 12), ['available' => ['Available', '#22943F'], 'issued' => ['On loan', '#1D4ED8'], 'lost' => ['Lost / damaged', '#DC2626']], 'number', 'category');
        },
    ];
}

/* ---------------------------------------------------------------- Hostel */

function reports_def_hostel_occupancy(): array
{
    require_once APP_ROOT . '/app/services/hostel.php';
    return [
        'prepare' => function (array $f) {
            $args = [];
            $alWhere = "WHERE ha.status = 'active'";
            if ($f['session_id'] ?? null) {
                $alWhere .= ' AND ha.academic_session_id = ?';
                $args[] = $f['session_id'];
            }
            return ["hostels h
                LEFT JOIN (SELECT r.hostel_id, COUNT(DISTINCT r.id) AS rooms, COUNT(hb.id) AS beds, SUM(hb.status = 'occupied') AS occupied, SUM(hb.status = 'available') AS vacant,
                    SUM(hb.status = 'maintenance') AS maintenance FROM hostel_rooms r LEFT JOIN hostel_beds hb ON hb.room_id = r.id GROUP BY r.hostel_id) cap ON cap.hostel_id = h.id
                LEFT JOIN (SELECT ha.hostel_id, COUNT(*) AS allocations, SUM(ha.fee_amount) AS fee FROM hostel_allocations ha $alWhere GROUP BY ha.hostel_id) al ON al.hostel_id = h.id
                LEFT JOIN (SELECT hc.hostel_id, SUM(hc.status IN ('open', 'in_progress')) AS open_complaints FROM hostel_complaints hc GROUP BY hc.hostel_id) cm ON cm.hostel_id = h.id", $args];
        },
        'select' => 'h.id, h.name AS hostel, h.code, h.type, h.warden_name, COALESCE(cap.rooms, 0) AS rooms, COALESCE(cap.beds, 0) AS beds, COALESCE(cap.occupied, 0) AS occupied,
            COALESCE(cap.vacant, 0) AS vacant, COALESCE(cap.maintenance, 0) AS maintenance, ROUND(COALESCE(cap.occupied, 0) * 100 / NULLIF(cap.beds, 0), 1) AS occupancy,
            COALESCE(al.allocations, 0) AS allocations, COALESCE(al.fee, 0) AS fee, COALESCE(cm.open_complaints, 0) AS open_complaints',
        'filters' => [
            'session' => ['apply' => false, 'label' => 'Allocations in session'],
            'type' => ['col' => 'h.type', 'label' => 'Hostel type', 'options' => hostel_types()],
            'status' => ['col' => 'h.status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
        ],
        'note' => 'Beds and occupancy reflect the current bed status; allocations and fee are for the selected session.',
        'columns' => [
            ['key' => 'hostel', 'label' => 'Hostel', 'format' => 'strong', 'sub' => 'warden_name', 'sub_label' => 'Warden'],
            ['key' => 'type', 'label' => 'Type', 'format' => 'label', 'labels' => hostel_types()],
            ['key' => 'rooms', 'label' => 'Rooms', 'format' => 'number'],
            ['key' => 'beds', 'label' => 'Beds', 'format' => 'number'],
            ['key' => 'occupied', 'label' => 'Occupied', 'format' => 'number'],
            ['key' => 'vacant', 'label' => 'Vacant', 'format' => 'number'],
            ['key' => 'maintenance', 'label' => 'Maintenance', 'format' => 'number'],
            ['key' => 'occupancy', 'label' => 'Occupancy', 'format' => 'progress'],
            ['key' => 'allocations', 'label' => 'Allocations', 'format' => 'number'],
            ['key' => 'fee', 'label' => 'Fee billed', 'format' => 'money'],
            ['key' => 'open_complaints', 'label' => 'Open complaints', 'format' => 'number'],
        ],
        'sorts' => ['hostel' => 'h.name', 'rooms' => 'rooms', 'beds' => 'beds', 'occupied' => 'occupied', 'vacant' => 'vacant', 'occupancy' => 'occupancy', 'allocations' => 'allocations', 'fee' => 'fee'],
        'default_sort' => ['hostel', 'asc'],
        'tiebreak' => 'h.id',
        'kpis' => function (array $c) {
            $r = reports_one($c, 'COUNT(*) AS hostels, COALESCE(SUM(cap.beds), 0) AS beds, COALESCE(SUM(cap.occupied), 0) AS occupied, COALESCE(SUM(cap.vacant), 0) AS vacant, COALESCE(SUM(al.fee), 0) AS fee');
            return [
                reports_kpi('Hostels', $r['hostels'], 'number', 'navy'),
                reports_kpi('Beds', $r['beds'], 'number', 'blue'),
                reports_kpi('Occupied', $r['occupied'], 'number', 'purple'),
                reports_kpi('Vacant', $r['vacant'], 'number', 'green'),
                reports_kpi('Occupancy', reports_pct($r['occupied'], $r['beds']), 'percent', 'orange', money((float) $r['fee']) . ' billed'),
            ];
        },
        'chart' => fn (array $c) => reports_chart('stacked', 'Beds by hostel', reports_all($c, 'h.name AS label, COALESCE(cap.occupied, 0) AS occupied, COALESCE(cap.vacant, 0) AS vacant, COALESCE(cap.maintenance, 0) AS maintenance', 'ORDER BY h.name'),
            ['occupied' => ['Occupied', '#0B2A5B'], 'vacant' => ['Vacant', '#22943F'], 'maintenance' => ['Maintenance', '#F59E0B']]),
    ];
}

function reports_def_hostel_allocations(): array
{
    require_once APP_ROOT . '/app/services/hostel.php';
    return [
        'from' => 'hostel_allocations ha JOIN students s ON s.id = ha.student_id JOIN hostels h ON h.id = ha.hostel_id LEFT JOIN hostel_rooms r ON r.id = ha.room_id
            LEFT JOIN hostel_beds hb ON hb.id = ha.bed_id LEFT JOIN programs p ON p.id = s.program_id',
        'select' => "ha.id, ha.allocation_no, " . reports_sn() . " AS student, s.student_uid, p.short_name AS program, s.current_semester AS semester, h.name AS hostel,
            CONCAT('Room ', COALESCE(r.room_no, '—'), CASE WHEN hb.bed_no IS NULL THEN '' ELSE CONCAT(' · Bed ', hb.bed_no) END) AS room,
            ha.allocated_on, ha.valid_until, ha.vacated_on, ha.fee_amount, ha.status",
        'filters' => [
            'date' => ['col' => 'ha.allocated_on', 'label' => 'Allocated on'],
            'session' => ['col' => 'ha.academic_session_id'],
            'department' => ['col' => 's.department_id'],
            'program' => ['col' => 's.program_id'],
            'semester' => ['col' => 's.current_semester'],
            'status' => ['col' => 'ha.status', 'options' => hostel_allocation_statuses()],
            'hostel' => ['col' => 'ha.hostel_id', 'label' => 'Hostel', 'options_sql' => 'SELECT id AS value, name AS label FROM hostels ORDER BY name'],
            'gender' => ['col' => 's.gender', 'options' => ['male' => 'Male', 'female' => 'Female', 'other' => 'Other']],
        ],
        'search' => ['ha.allocation_no', reports_name_search(), 's.student_uid', 'r.room_no'],
        'search_hint' => 'Allocation, student, room…',
        'columns' => [
            ['key' => 'allocation_no', 'label' => 'Allocation', 'format' => 'code'],
            ['key' => 'student', 'label' => 'Student', 'format' => 'person', 'sub' => 'student_uid', 'sub_label' => 'Student ID'],
            ['key' => 'program', 'label' => 'Program'],
            ['key' => 'hostel', 'label' => 'Hostel', 'sub' => 'room', 'sub_label' => 'Room / bed'],
            ['key' => 'allocated_on', 'label' => 'Allocated', 'format' => 'date'],
            ['key' => 'valid_until', 'label' => 'Valid until', 'format' => 'date'],
            ['key' => 'vacated_on', 'label' => 'Vacated', 'format' => 'date'],
            ['key' => 'fee_amount', 'label' => 'Fee', 'format' => 'money'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'labels' => hostel_allocation_statuses()],
        ],
        'sorts' => ['allocation_no' => 'ha.allocation_no', 'student' => 'student', 'program' => 'program', 'hostel' => 'hostel', 'allocated_on' => 'ha.allocated_on', 'valid_until' => 'ha.valid_until', 'fee_amount' => 'ha.fee_amount', 'status' => 'ha.status'],
        'default_sort' => ['allocated_on', 'desc'],
        'tiebreak' => 'ha.id DESC',
        'kpis' => function (array $c) {
            $r = reports_one($c, "COUNT(*) AS total, SUM(ha.status = 'active') AS active, SUM(ha.status = 'vacated') AS vacated, SUM(ha.status = 'transferred') AS transferred, COALESCE(SUM(ha.fee_amount), 0) AS fee, COUNT(DISTINCT ha.student_id) AS students");
            return [
                reports_kpi('Allocations', $r['total'], 'number', 'navy', number_in($r['students']) . ' students'),
                reports_kpi('Active', $r['active'], 'number', 'green'),
                reports_kpi('Vacated', $r['vacated'], 'number', 'purple'),
                reports_kpi('Transferred', $r['transferred'], 'number', 'amber'),
                reports_kpi('Fee billed', $r['fee'], 'money', 'blue'),
            ];
        },
        'chart' => fn (array $c) => reports_chart('doughnut', 'Allocations by hostel', reports_all($c, 'h.name AS label, COUNT(*) AS v', 'GROUP BY h.name ORDER BY v DESC'), ['v' => 'Allocations']),
    ];
}

/* ---------------------------------------------------------------- Transport */

function reports_def_transport_routes(): array
{
    return [
        'prepare' => function (array $f) {
            $args = [];
            $w = "WHERE ta.status = 'active'";
            if ($f['session_id'] ?? null) {
                $w .= ' AND ta.academic_session_id = ?';
                $args[] = $f['session_id'];
            }
            return ["transport_routes tr
                LEFT JOIN (SELECT v.route_id, COUNT(*) AS vehicles, SUM(v.capacity) AS capacity FROM vehicles v WHERE v.status <> 'inactive' AND v.route_id IS NOT NULL GROUP BY v.route_id) vh ON vh.route_id = tr.id
                LEFT JOIN (SELECT ts.route_id, COUNT(*) AS stops FROM transport_stops ts GROUP BY ts.route_id) sp ON sp.route_id = tr.id
                LEFT JOIN (SELECT ta.route_id, COUNT(*) AS riders, SUM(ta.person_type = 'student') AS students, SUM(ta.fee_amount) AS fee FROM transport_allocations ta $w GROUP BY ta.route_id) al ON al.route_id = tr.id", $args];
        },
        'select' => "tr.id, tr.code, tr.name AS route, CONCAT_WS(' → ', tr.start_point, tr.end_point) AS path, tr.distance_km, COALESCE(sp.stops, 0) AS stops,
            COALESCE(vh.vehicles, 0) AS vehicles, COALESCE(vh.capacity, 0) AS capacity, COALESCE(al.riders, 0) AS riders, COALESCE(al.students, 0) AS students,
            ROUND(COALESCE(al.riders, 0) * 100 / NULLIF(vh.capacity, 0), 1) AS utilisation, tr.annual_fare, COALESCE(al.fee, 0) AS fee, tr.status",
        'filters' => [
            'session' => ['apply' => false, 'label' => 'Allocations in session'],
            'status' => ['col' => 'tr.status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
        ],
        'search' => ['tr.name', 'tr.code', 'tr.start_point', 'tr.end_point'],
        'search_hint' => 'Route name, code, place…',
        'columns' => [
            ['key' => 'route', 'label' => 'Route', 'format' => 'strong', 'sub' => 'path', 'sub_label' => 'From → to'],
            ['key' => 'code', 'label' => 'Code', 'format' => 'code'],
            ['key' => 'distance_km', 'label' => 'Distance', 'format' => 'decimal', 'suffix' => 'km'],
            ['key' => 'stops', 'label' => 'Stops', 'format' => 'number'],
            ['key' => 'vehicles', 'label' => 'Vehicles', 'format' => 'number'],
            ['key' => 'capacity', 'label' => 'Seats', 'format' => 'number'],
            ['key' => 'riders', 'label' => 'Riders', 'format' => 'number'],
            ['key' => 'utilisation', 'label' => 'Utilisation', 'format' => 'progress'],
            ['key' => 'annual_fare', 'label' => 'Annual fare', 'format' => 'money'],
            ['key' => 'fee', 'label' => 'Fee billed', 'format' => 'money'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
        ],
        'sorts' => ['route' => 'tr.name', 'code' => 'tr.code', 'distance_km' => 'tr.distance_km', 'stops' => 'stops', 'vehicles' => 'vehicles', 'capacity' => 'capacity', 'riders' => 'riders', 'utilisation' => 'utilisation', 'fee' => 'fee'],
        'default_sort' => ['riders', 'desc'],
        'tiebreak' => 'tr.id',
        'kpis' => function (array $c) {
            $r = reports_one($c, 'COUNT(*) AS routes, COALESCE(SUM(vh.vehicles), 0) AS vehicles, COALESCE(SUM(vh.capacity), 0) AS capacity, COALESCE(SUM(al.riders), 0) AS riders, COALESCE(SUM(al.fee), 0) AS fee');
            return [
                reports_kpi('Routes', $r['routes'], 'number', 'navy'),
                reports_kpi('Vehicles', $r['vehicles'], 'number', 'blue', number_in($r['capacity']) . ' seats'),
                reports_kpi('Riders', $r['riders'], 'number', 'purple'),
                reports_kpi('Utilisation', reports_pct($r['riders'], $r['capacity']), 'percent', 'green', 'riders ÷ seats'),
                reports_kpi('Fee billed', $r['fee'], 'money', 'amber'),
            ];
        },
        'chart' => fn (array $c) => reports_chart('bar', 'Riders vs seats by route', reports_all($c, 'tr.code AS label, COALESCE(al.riders, 0) AS riders, COALESCE(vh.capacity, 0) AS capacity', 'ORDER BY riders DESC LIMIT 15'), ['riders' => 'Riders', 'capacity' => 'Seats']),
    ];
}

function reports_def_transport_allocations(): array
{
    $person = reports_member_name_sql();
    return [
        'from' => 'transport_allocations ta JOIN transport_routes tr ON tr.id = ta.route_id LEFT JOIN transport_stops ts ON ts.id = ta.stop_id LEFT JOIN vehicles v ON v.id = ta.vehicle_id
            LEFT JOIN students s ON s.id = ta.student_id LEFT JOIN faculty f ON f.id = ta.faculty_id LEFT JOIN staff st ON st.id = ta.staff_id LEFT JOIN programs p ON p.id = s.program_id',
        'select' => "ta.id, $person AS person, COALESCE(s.student_uid, f.employee_id, st.employee_id) AS person_ref, ta.person_type, p.short_name AS program, tr.name AS route, tr.code AS route_code,
            ts.name AS stop, v.vehicle_no, ta.start_date, ta.end_date, ta.fee_amount, ta.status",
        'filters' => [
            'date' => ['col' => 'ta.start_date', 'label' => 'Start date'],
            'session' => ['col' => 'ta.academic_session_id'],
            'department' => ['col' => 'COALESCE(s.department_id, f.department_id, st.department_id)'],
            'program' => ['col' => 's.program_id'],
            'semester' => ['col' => 's.current_semester'],
            'status' => ['col' => 'ta.status', 'options' => ['active' => 'Active', 'transferred' => 'Transferred', 'cancelled' => 'Cancelled', 'completed' => 'Completed']],
            'route' => ['col' => 'ta.route_id', 'label' => 'Route', 'options_sql' => "SELECT id AS value, CONCAT(code, ' — ', name) AS label FROM transport_routes ORDER BY code"],
            'person_type' => ['col' => 'ta.person_type', 'label' => 'Rider type', 'options' => ['student' => 'Students', 'faculty' => 'Faculty', 'staff' => 'Staff']],
        ],
        'search' => [reports_name_search('s'), reports_name_search('f'), reports_name_search('st'), 's.student_uid', 'v.vehicle_no', 'ts.name'],
        'search_hint' => 'Name, student ID, vehicle, stop…',
        'columns' => [
            ['key' => 'person', 'label' => 'Rider', 'format' => 'person', 'sub' => 'person_ref', 'sub_label' => 'ID'],
            ['key' => 'person_type', 'label' => 'Type', 'format' => 'label'],
            ['key' => 'program', 'label' => 'Program'],
            ['key' => 'route', 'label' => 'Route', 'sub' => 'route_code', 'sub_label' => 'Route code'],
            ['key' => 'stop', 'label' => 'Stop'],
            ['key' => 'vehicle_no', 'label' => 'Vehicle', 'format' => 'code'],
            ['key' => 'start_date', 'label' => 'From', 'format' => 'date'],
            ['key' => 'end_date', 'label' => 'Until', 'format' => 'date'],
            ['key' => 'fee_amount', 'label' => 'Fee', 'format' => 'money'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
        ],
        'sorts' => ['person' => 'person', 'person_type' => 'ta.person_type', 'route' => 'route', 'stop' => 'stop', 'start_date' => 'ta.start_date', 'fee_amount' => 'ta.fee_amount', 'status' => 'ta.status'],
        'default_sort' => ['route', 'asc'],
        'tiebreak' => 'ta.id',
        'kpis' => function (array $c) {
            $r = reports_one($c, "COUNT(*) AS total, SUM(ta.status = 'active') AS active, SUM(ta.person_type = 'student') AS students, SUM(ta.person_type <> 'student') AS staff, COALESCE(SUM(ta.fee_amount), 0) AS fee");
            return [
                reports_kpi('Allocations', $r['total'], 'number', 'navy'),
                reports_kpi('Active', $r['active'], 'number', 'green'),
                reports_kpi('Students', $r['students'], 'number', 'blue'),
                reports_kpi('Faculty & staff', $r['staff'], 'number', 'purple'),
                reports_kpi('Fee billed', $r['fee'], 'money', 'amber'),
            ];
        },
        'chart' => fn (array $c) => reports_chart('hbar', 'Riders by route', reports_all($c, 'tr.code AS label, COUNT(*) AS v', 'GROUP BY tr.code ORDER BY v DESC LIMIT 14'), ['v' => 'Riders']),
    ];
}

/* ---------------------------------------------------------------- Placement */

function reports_placed_sql(): string
{
    return "COUNT(DISTINCT CASE WHEN po.status IN ('offered', 'accepted') THEN po.student_id END)";
}

function reports_batch_options_sql(): string
{
    return 'SELECT DISTINCT batch_year AS value, batch_year AS label FROM placement_drives WHERE batch_year IS NOT NULL ORDER BY batch_year DESC';
}

function reports_def_placement_programs(): array
{
    $placed = reports_placed_sql();
    return [
        'from' => 'placement_applications pa JOIN placement_drives pd ON pd.id = pa.drive_id JOIN students s ON s.id = pa.student_id LEFT JOIN programs p ON p.id = s.program_id
            LEFT JOIN departments d ON d.id = p.department_id LEFT JOIN placement_offers po ON po.application_id = pa.id',
        'group' => 's.program_id, p.short_name, d.name',
        'select' => "COALESCE(s.program_id, 0) AS id, COALESCE(p.short_name, 'Unassigned') AS program, d.name AS department, COUNT(DISTINCT pa.student_id) AS applied,
            COUNT(DISTINCT pa.id) AS applications, COUNT(DISTINCT po.id) AS offers, $placed AS placed,
            ROUND($placed * 100 / NULLIF(COUNT(DISTINCT pa.student_id), 0), 1) AS placement_pct, MAX(po.package_lpa) AS highest, ROUND(AVG(po.package_lpa), 2) AS average",
        'filters' => [
            'date' => ['col' => 'pd.drive_date', 'label' => 'Drive date'],
            'department' => ['col' => 'p.department_id'],
            'program' => ['col' => 's.program_id'],
            'batch' => ['col' => 'pd.batch_year', 'label' => 'Batch (passing year)', 'options_sql' => reports_batch_options_sql()],
            'company' => ['col' => 'pd.company_id', 'label' => 'Company', 'options_sql' => 'SELECT id AS value, name AS label FROM companies WHERE id IN (SELECT company_id FROM placement_drives) ORDER BY name'],
        ],
        'note' => 'Placement % = students holding an offer (offered or accepted) ÷ students who applied to at least one drive.',
        'columns' => [
            ['key' => 'program', 'label' => 'Program', 'format' => 'strong', 'sub' => 'department', 'sub_label' => 'Department'],
            ['key' => 'applied', 'label' => 'Students applied', 'format' => 'number'],
            ['key' => 'applications', 'label' => 'Applications', 'format' => 'number'],
            ['key' => 'offers', 'label' => 'Offers', 'format' => 'number'],
            ['key' => 'placed', 'label' => 'Placed', 'format' => 'number'],
            ['key' => 'placement_pct', 'label' => 'Placement %', 'format' => 'progress'],
            ['key' => 'highest', 'label' => 'Highest', 'format' => 'lpa'],
            ['key' => 'average', 'label' => 'Average', 'format' => 'lpa'],
        ],
        'sorts' => array_fill_keys(['program', 'applied', 'applications', 'offers', 'placed', 'placement_pct', 'highest', 'average'], true),
        'default_sort' => ['placement_pct', 'desc'],
        'kpis' => function (array $c) use ($placed) {
            $r = reports_one($c, "COUNT(DISTINCT pa.student_id) AS applied, $placed AS placed, COUNT(DISTINCT po.id) AS offers, MAX(po.package_lpa) AS highest, AVG(po.package_lpa) AS average");
            return [
                reports_kpi('Students applied', $r['applied'], 'number', 'navy'),
                reports_kpi('Students placed', $r['placed'], 'number', 'green', number_in($r['offers']) . ' offers'),
                reports_kpi('Placement %', reports_pct($r['placed'], $r['applied']), 'percent', 'blue'),
                reports_kpi('Highest package', $r['highest'], 'lpa', 'orange'),
                reports_kpi('Average package', $r['average'], 'lpa', 'purple'),
            ];
        },
        'chart' => fn (array $c) => reports_chart('bar', 'Placement % by program', array_values(array_filter($c['rows_all'], fn ($r) => (int) $r['applied'] > 0)), ['placement_pct' => 'Placement %'], 'percent', 'program'),
    ];
}

function reports_def_placement_companies(): array
{
    return [
        'from' => 'placement_drives pd JOIN companies c ON c.id = pd.company_id LEFT JOIN placement_applications pa ON pa.drive_id = pd.id LEFT JOIN placement_offers po ON po.application_id = pa.id',
        'group' => 'c.id, c.name, c.industry',
        'select' => "c.id, c.name AS company, c.industry, COUNT(DISTINCT pd.id) AS drives, COUNT(DISTINCT pa.id) AS applications,
            COUNT(DISTINCT CASE WHEN pa.status = 'selected' THEN pa.id END) AS selected, COUNT(DISTINCT po.id) AS offers,
            COUNT(DISTINCT CASE WHEN po.status = 'accepted' THEN po.id END) AS accepted, MAX(po.package_lpa) AS highest, ROUND(AVG(po.package_lpa), 2) AS average, MAX(pd.drive_date) AS last_drive",
        'filters' => [
            'date' => ['col' => 'pd.drive_date', 'label' => 'Drive date'],
            'batch' => ['col' => 'pd.batch_year', 'label' => 'Batch (passing year)', 'options_sql' => reports_batch_options_sql()],
            'status' => ['col' => 'pd.status', 'label' => 'Drive status', 'options' => ['upcoming' => 'Upcoming', 'open' => 'Open', 'ongoing' => 'Ongoing', 'completed' => 'Completed', 'cancelled' => 'Cancelled']],
            'industry' => ['col' => 'c.industry', 'options_sql' => "SELECT DISTINCT industry AS value, industry AS label FROM companies WHERE industry IS NOT NULL AND industry <> '' ORDER BY industry"],
        ],
        'search' => ['c.name', 'pd.job_role'],
        'search_hint' => 'Company or role…',
        'columns' => [
            ['key' => 'company', 'label' => 'Company', 'format' => 'strong', 'sub' => 'industry', 'sub_label' => 'Industry'],
            ['key' => 'drives', 'label' => 'Drives', 'format' => 'number'],
            ['key' => 'applications', 'label' => 'Applications', 'format' => 'number'],
            ['key' => 'selected', 'label' => 'Selected', 'format' => 'number'],
            ['key' => 'offers', 'label' => 'Offers', 'format' => 'number'],
            ['key' => 'accepted', 'label' => 'Accepted', 'format' => 'number'],
            ['key' => 'highest', 'label' => 'Highest', 'format' => 'lpa'],
            ['key' => 'average', 'label' => 'Average', 'format' => 'lpa'],
            ['key' => 'last_drive', 'label' => 'Last drive', 'format' => 'date'],
        ],
        'sorts' => array_fill_keys(['company', 'drives', 'applications', 'selected', 'offers', 'accepted', 'highest', 'average', 'last_drive'], true),
        'default_sort' => ['offers', 'desc'],
        'kpis' => function (array $c) {
            $rows = $c['rows_all'];
            $top = null;
            foreach ($rows as $r) {
                if ((int) $r['offers'] > 0 && (!$top || (int) $r['offers'] > (int) $top['offers'])) {
                    $top = $r;
                }
            }
            $pk = reports_one($c, 'AVG(po.package_lpa) AS average');
            return [
                reports_kpi('Recruiters', count($rows), 'number', 'navy'),
                reports_kpi('Drives', array_sum(array_column($rows, 'drives')), 'number', 'blue'),
                reports_kpi('Offers', array_sum(array_column($rows, 'offers')), 'number', 'green', number_in(array_sum(array_column($rows, 'accepted'))) . ' accepted'),
                reports_kpi('Average package', $pk['average'] ?? null, 'lpa', 'purple'),
                reports_kpi('Top recruiter', $top ? $top['company'] : '—', 'text', 'orange', $top ? number_in($top['offers']) . ' offers' : null),
            ];
        },
        'chart' => function (array $c) {
            $rows = array_filter($c['rows_all'], fn ($r) => (int) $r['offers'] > 0);
            usort($rows, fn ($a, $b) => (int) $b['offers'] <=> (int) $a['offers']);
            return reports_chart('hbar', 'Offers by company (top 10)', array_slice($rows, 0, 10), ['offers' => ['Offers', '#0B2A5B'], 'accepted' => ['Accepted', '#22943F']], 'number', 'company');
        },
    ];
}

function reports_def_placement_offers(): array
{
    return [
        'from' => 'placement_offers po JOIN students s ON s.id = po.student_id JOIN companies c ON c.id = po.company_id LEFT JOIN placement_drives pd ON pd.id = po.drive_id LEFT JOIN programs p ON p.id = s.program_id',
        'select' => 'po.id, ' . reports_sn() . ' AS student, s.student_uid, p.short_name AS program, c.name AS company, po.job_role, po.package_lpa, po.offer_date, po.joining_date, po.location, po.status',
        'filters' => [
            'date' => ['col' => 'po.offer_date', 'label' => 'Offer date'],
            'department' => ['col' => 's.department_id'],
            'program' => ['col' => 's.program_id'],
            'status' => ['col' => 'po.status', 'options' => ['offered' => 'Offered', 'accepted' => 'Accepted', 'declined' => 'Declined']],
            'company' => ['col' => 'po.company_id', 'label' => 'Company', 'options_sql' => 'SELECT id AS value, name AS label FROM companies WHERE id IN (SELECT company_id FROM placement_offers) ORDER BY name'],
            'batch' => ['col' => 'pd.batch_year', 'label' => 'Batch (passing year)', 'options_sql' => reports_batch_options_sql()],
            'package' => ['label' => 'Package', 'all_label' => 'Any package', 'options' => ['lt5' => 'Below 5 LPA', '5to10' => '5 – 10 LPA', 'gt10' => '10 LPA and above'],
                'sql_map' => ['lt5' => 'po.package_lpa < 5', '5to10' => 'po.package_lpa >= 5 AND po.package_lpa < 10', 'gt10' => 'po.package_lpa >= 10']],
        ],
        'search' => [reports_name_search(), 's.student_uid', 'c.name', 'po.job_role'],
        'search_hint' => 'Student, company, role…',
        'columns' => [
            ['key' => 'student', 'label' => 'Student', 'format' => 'person', 'sub' => 'student_uid', 'sub_label' => 'Student ID'],
            ['key' => 'program', 'label' => 'Program'],
            ['key' => 'company', 'label' => 'Company', 'format' => 'strong', 'sub' => 'job_role', 'sub_label' => 'Role'],
            ['key' => 'package_lpa', 'label' => 'Package', 'format' => 'lpa'],
            ['key' => 'offer_date', 'label' => 'Offered', 'format' => 'date'],
            ['key' => 'joining_date', 'label' => 'Joining', 'format' => 'date'],
            ['key' => 'location', 'label' => 'Location'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
        ],
        'sorts' => ['student' => 'student', 'program' => 'program', 'company' => 'company', 'package_lpa' => 'po.package_lpa', 'offer_date' => 'po.offer_date', 'joining_date' => 'po.joining_date', 'status' => 'po.status'],
        'default_sort' => ['package_lpa', 'desc'],
        'tiebreak' => 'po.id',
        'kpis' => function (array $c) {
            $r = reports_one($c, "COUNT(*) AS offers, SUM(po.status = 'accepted') AS accepted, COUNT(DISTINCT po.student_id) AS students, MAX(po.package_lpa) AS highest, AVG(po.package_lpa) AS average");
            return [
                reports_kpi('Offers', $r['offers'], 'number', 'navy'),
                reports_kpi('Accepted', $r['accepted'], 'number', 'green', reports_share($r['accepted'], $r['offers'])),
                reports_kpi('Students with offers', $r['students'], 'number', 'blue'),
                reports_kpi('Highest package', $r['highest'], 'lpa', 'orange'),
                reports_kpi('Average package', $r['average'], 'lpa', 'purple'),
            ];
        },
        'chart' => function (array $c) {
            $r = reports_one($c, 'SUM(po.package_lpa < 3) AS b1, SUM(po.package_lpa >= 3 AND po.package_lpa < 5) AS b2, SUM(po.package_lpa >= 5 AND po.package_lpa < 8) AS b3,
                SUM(po.package_lpa >= 8 AND po.package_lpa < 12) AS b4, SUM(po.package_lpa >= 12) AS b5');
            $rows = [];
            foreach (['b1' => '< 3 LPA', 'b2' => '3–5 LPA', 'b3' => '5–8 LPA', 'b4' => '8–12 LPA', 'b5' => '12+ LPA'] as $k => $l) {
                $rows[] = ['label' => $l, 'v' => (int) ($r[$k] ?? 0)];
            }
            return reports_chart('bar', 'Offers by package band', $rows, ['v' => 'Offers']);
        },
    ];
}

/* ---------------------------------------------------------------- Alumni */

function reports_def_alumni_directory(): array
{
    require_once APP_ROOT . '/app/services/alumni.php';
    $emp = alumni_employment_statuses();
    return [
        'from' => 'alumni al LEFT JOIN programs p ON p.id = al.program_id LEFT JOIN departments d ON d.id = al.department_id',
        'select' => 'al.id, al.name, al.email, al.batch_year, p.short_name AS program, d.name AS department, al.company, al.designation, al.industry, al.city, al.employment_status, al.is_mentor, al.is_verified, al.status',
        'filters' => [
            'date' => ['col' => 'al.created_at', 'label' => 'Registered on', 'datetime' => true],
            'department' => ['col' => 'al.department_id'],
            'program' => ['col' => 'al.program_id'],
            'status' => ['col' => 'al.status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
            'employment' => ['col' => 'al.employment_status', 'label' => 'Employment', 'options' => $emp],
            'batch' => ['col' => 'al.batch_year', 'label' => 'Batch', 'options_sql' => 'SELECT DISTINCT batch_year AS value, batch_year AS label FROM alumni WHERE batch_year IS NOT NULL ORDER BY batch_year DESC'],
            'industry' => ['col' => 'al.industry', 'options_sql' => "SELECT DISTINCT industry AS value, industry AS label FROM alumni WHERE industry IS NOT NULL AND industry <> '' ORDER BY industry"],
            'mentor' => ['label' => 'Mentoring', 'options' => ['yes' => 'Mentors only', 'no' => 'Not mentoring'], 'sql_map' => ['yes' => 'al.is_mentor = 1', 'no' => 'al.is_mentor = 0']],
        ],
        'search' => ['al.name', 'al.email', 'al.company', 'al.city'],
        'search_hint' => 'Name, email, company, city…',
        'columns' => [
            ['key' => 'name', 'label' => 'Alumnus', 'format' => 'person', 'sub' => 'email', 'sub_label' => 'Email'],
            ['key' => 'batch_year', 'label' => 'Batch'],
            ['key' => 'program', 'label' => 'Program'],
            ['key' => 'company', 'label' => 'Company', 'sub' => 'designation', 'sub_label' => 'Designation'],
            ['key' => 'industry', 'label' => 'Industry'],
            ['key' => 'city', 'label' => 'City'],
            ['key' => 'employment_status', 'label' => 'Employment', 'format' => 'badge', 'labels' => $emp],
            ['key' => 'is_mentor', 'label' => 'Mentor', 'format' => 'boolean'],
            ['key' => 'is_verified', 'label' => 'Verified', 'format' => 'boolean'],
        ],
        'sorts' => ['name' => 'al.name', 'batch_year' => 'al.batch_year', 'program' => 'program', 'company' => 'al.company', 'industry' => 'al.industry', 'city' => 'al.city', 'employment_status' => 'al.employment_status'],
        'default_sort' => ['batch_year', 'desc'],
        'tiebreak' => 'al.name, al.id',
        'kpis' => function (array $c) {
            $r = reports_one($c, "COUNT(*) AS total, SUM(al.employment_status IN ('employed', 'self_employed', 'entrepreneur')) AS working, SUM(al.employment_status = 'higher_studies') AS higher,
                SUM(al.is_mentor = 1) AS mentors, SUM(al.is_verified = 1) AS verified");
            return [
                reports_kpi('Alumni', $r['total'], 'number', 'navy'),
                reports_kpi('Working', $r['working'], 'number', 'green', reports_share($r['working'], $r['total'])),
                reports_kpi('Higher studies', $r['higher'], 'number', 'blue', reports_share($r['higher'], $r['total'])),
                reports_kpi('Mentors', $r['mentors'], 'number', 'purple'),
                reports_kpi('Verified profiles', $r['verified'], 'number', 'amber', reports_share($r['verified'], $r['total'])),
            ];
        },
        'chart' => fn (array $c) => reports_chart('doughnut', 'Alumni by employment status', reports_all($c, 'al.employment_status AS label, COUNT(*) AS v', 'GROUP BY al.employment_status ORDER BY v DESC'), ['v' => 'Alumni'], 'number', 'label', alumni_employment_statuses()),
    ];
}

function reports_def_alumni_donations(): array
{
    require_once APP_ROOT . '/app/services/alumni.php';
    return [
        'from' => 'alumni_donations dn LEFT JOIN alumni al ON al.id = dn.alumni_id',
        'select' => "dn.id, dn.receipt_no, CASE WHEN dn.is_anonymous = 1 THEN 'Anonymous donor' ELSE COALESCE(al.name, 'Well-wisher') END AS donor, al.batch_year, dn.purpose, dn.campaign,
            dn.donation_date, dn.payment_mode, dn.amount, dn.status",
        'filters' => [
            'date' => ['col' => 'dn.donation_date', 'label' => 'Donation date'],
            'status' => ['col' => 'dn.status', 'options' => alumni_donation_statuses()],
            'purpose' => ['col' => 'dn.purpose', 'options' => alumni_donation_purposes()],
            'mode' => ['col' => 'dn.payment_mode', 'label' => 'Payment mode', 'options' => alumni_payment_modes()],
            'batch' => ['col' => 'al.batch_year', 'label' => 'Batch', 'options_sql' => 'SELECT DISTINCT batch_year AS value, batch_year AS label FROM alumni WHERE batch_year IS NOT NULL ORDER BY batch_year DESC'],
        ],
        'search' => ['dn.receipt_no', 'al.name', 'dn.campaign'],
        'search_hint' => 'Receipt, donor, campaign…',
        'columns' => [
            ['key' => 'receipt_no', 'label' => 'Receipt', 'format' => 'code'],
            ['key' => 'donor', 'label' => 'Donor', 'format' => 'person', 'sub' => 'batch_year', 'sub_label' => 'Batch'],
            ['key' => 'purpose', 'label' => 'Purpose', 'format' => 'label', 'labels' => alumni_donation_purposes(), 'sub' => 'campaign', 'sub_label' => 'Campaign'],
            ['key' => 'donation_date', 'label' => 'Date', 'format' => 'date'],
            ['key' => 'payment_mode', 'label' => 'Mode', 'format' => 'label', 'labels' => alumni_payment_modes()],
            ['key' => 'amount', 'label' => 'Amount', 'format' => 'money'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'labels' => alumni_donation_statuses()],
        ],
        'sorts' => ['receipt_no' => 'dn.receipt_no', 'donor' => 'donor', 'purpose' => 'dn.purpose', 'donation_date' => 'dn.donation_date', 'amount' => 'dn.amount', 'status' => 'dn.status'],
        'default_sort' => ['donation_date', 'desc'],
        'tiebreak' => 'dn.id DESC',
        'kpis' => function (array $c) {
            $r = reports_one($c, "COUNT(*) AS total, COALESCE(SUM(CASE WHEN dn.status = 'received' THEN dn.amount END), 0) AS received, SUM(dn.status = 'received') AS received_count,
                COUNT(DISTINCT CASE WHEN dn.status = 'received' THEN dn.alumni_id END) AS donors, COALESCE(SUM(CASE WHEN dn.status = 'pledged' THEN dn.amount END), 0) AS pledged");
            return [
                reports_kpi('Received', $r['received'], 'money', 'green'),
                reports_kpi('Donations', $r['total'], 'number', 'navy', number_in($r['received_count']) . ' received'),
                reports_kpi('Donors', $r['donors'], 'number', 'blue'),
                reports_kpi('Average gift', $r['received_count'] ? $r['received'] / $r['received_count'] : null, 'money', 'purple'),
                reports_kpi('Pledged', $r['pledged'], 'money', 'amber'),
            ];
        },
        'chart' => fn (array $c) => reports_timeseries($c, 'bar', 'Donations received', ['Received' => ['dn.donation_date', 'SUM(dn.amount)', "dn.status = 'received'"]], 'money'),
    ];
}

/* ---------------------------------------------------------------- Website */

function reports_enquiry_statuses(): array
{
    return ['new' => 'New', 'contacted' => 'Contacted', 'interested' => 'Interested', 'not_interested' => 'Not interested', 'converted' => 'Converted', 'closed' => 'Closed'];
}

function reports_enquiry_sources(): array
{
    require_once APP_ROOT . '/app/services/admissions.php';
    return admission_sources() + ['chatbot' => 'Chatbot'];
}

function reports_def_web_enquiries(): array
{
    $sources = reports_enquiry_sources();
    return [
        'from' => 'enquiries q LEFT JOIN programs p ON p.id = q.program_id LEFT JOIN users u ON u.id = q.assigned_to',
        'select' => 'q.id, q.name, q.phone, q.email, q.city, COALESCE(p.short_name, q.program_interest) AS program, q.source, q.utm_source, q.utm_campaign, q.status, q.priority,
            u.name AS assigned_to, q.follow_up_date, q.created_at',
        'filters' => [
            'date' => ['col' => 'q.created_at', 'label' => 'Enquiry date', 'datetime' => true],
            'department' => ['col' => 'p.department_id'],
            'program' => ['col' => 'q.program_id'],
            'status' => ['col' => 'q.status', 'options' => reports_enquiry_statuses()],
            'source' => ['col' => 'q.source', 'options' => $sources],
            'utm_source' => ['col' => 'q.utm_source', 'label' => 'UTM source', 'options_sql' => "SELECT DISTINCT utm_source AS value, utm_source AS label FROM enquiries WHERE utm_source IS NOT NULL AND utm_source <> '' ORDER BY utm_source"],
            'priority' => ['col' => 'q.priority', 'options' => ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low']],
        ],
        'search' => ['q.name', 'q.phone', 'q.email', 'q.city', 'q.utm_campaign'],
        'search_hint' => 'Name, phone, email, city, campaign…',
        'columns' => [
            ['key' => 'name', 'label' => 'Enquirer', 'format' => 'person', 'sub' => 'phone', 'sub_label' => 'Phone'],
            ['key' => 'program', 'label' => 'Program'],
            ['key' => 'source', 'label' => 'Source', 'format' => 'label', 'labels' => $sources],
            ['key' => 'utm_source', 'label' => 'UTM source', 'sub' => 'utm_campaign', 'sub_label' => 'UTM campaign'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'labels' => reports_enquiry_statuses()],
            ['key' => 'priority', 'label' => 'Priority', 'format' => 'badge'],
            ['key' => 'assigned_to', 'label' => 'Owner'],
            ['key' => 'follow_up_date', 'label' => 'Follow-up', 'format' => 'date'],
            ['key' => 'created_at', 'label' => 'Received', 'format' => 'date'],
        ],
        'sorts' => ['name' => 'q.name', 'program' => 'program', 'source' => 'q.source', 'utm_source' => 'q.utm_source', 'status' => 'q.status', 'follow_up_date' => 'q.follow_up_date', 'created_at' => 'q.created_at'],
        'default_sort' => ['created_at', 'desc'],
        'tiebreak' => 'q.id DESC',
        'kpis' => function (array $c) {
            $r = reports_one($c, "COUNT(*) AS total, SUM(q.status = 'new') AS fresh, SUM(q.status = 'converted') AS converted, SUM(q.utm_source IS NOT NULL AND q.utm_source <> '') AS utm,
                SUM(q.source = 'website') AS web");
            return [
                reports_kpi('Enquiries', $r['total'], 'number', 'navy', number_in($r['web']) . ' from the website form'),
                reports_kpi('Awaiting first contact', $r['fresh'], 'number', 'amber'),
                reports_kpi('Converted', $r['converted'], 'number', 'green'),
                reports_kpi('Conversion %', reports_pct($r['converted'], $r['total']), 'percent', 'blue'),
                reports_kpi('UTM-tagged', $r['utm'], 'number', 'purple', reports_share($r['utm'], $r['total'])),
            ];
        },
        'chart' => fn (array $c) => reports_timeseries($c, 'bar', 'Enquiries received', ['Enquiries' => ['q.created_at', 'COUNT(*)'], 'Converted' => ['q.created_at', "SUM(q.status = 'converted')"]]),
    ];
}

function reports_def_web_enquiry_sources(): array
{
    $sources = reports_enquiry_sources();
    return [
        'from' => 'enquiries q LEFT JOIN programs p ON p.id = q.program_id',
        'group' => "q.source, COALESCE(q.utm_source, ''), COALESCE(q.utm_campaign, '')",
        'select' => "q.source AS source, COALESCE(q.utm_source, '') AS utm_source, COALESCE(q.utm_campaign, '') AS utm_campaign, COUNT(*) AS total, SUM(q.status = 'new') AS fresh,
            SUM(q.status IN ('contacted', 'interested')) AS in_progress, SUM(q.status = 'converted') AS converted, SUM(q.status IN ('not_interested', 'closed')) AS lost,
            ROUND(SUM(q.status = 'converted') * 100 / COUNT(*), 1) AS conversion, MAX(q.created_at) AS last_at",
        'filters' => [
            'date' => ['col' => 'q.created_at', 'label' => 'Enquiry date', 'datetime' => true],
            'department' => ['col' => 'p.department_id'],
            'program' => ['col' => 'q.program_id'],
            'source' => ['col' => 'q.source', 'options' => $sources],
            'tagged' => ['label' => 'UTM tagging', 'options' => ['yes' => 'UTM-tagged only', 'no' => 'Untagged only'], 'sql_map' => ['yes' => "q.utm_source IS NOT NULL AND q.utm_source <> ''", 'no' => "(q.utm_source IS NULL OR q.utm_source = '')"]],
        ],
        'transform' => fn (array $r) => $r + ['id' => $r['source'] . '|' . $r['utm_source'] . '|' . $r['utm_campaign']],
        'columns' => [
            ['key' => 'source', 'label' => 'Source', 'format' => 'label', 'labels' => $sources],
            ['key' => 'utm_source', 'label' => 'UTM source'],
            ['key' => 'utm_campaign', 'label' => 'UTM campaign'],
            ['key' => 'total', 'label' => 'Enquiries', 'format' => 'number'],
            ['key' => 'fresh', 'label' => 'New', 'format' => 'number'],
            ['key' => 'in_progress', 'label' => 'In progress', 'format' => 'number'],
            ['key' => 'converted', 'label' => 'Converted', 'format' => 'number'],
            ['key' => 'lost', 'label' => 'Lost / closed', 'format' => 'number'],
            ['key' => 'conversion', 'label' => 'Conversion', 'format' => 'progress'],
            ['key' => 'last_at', 'label' => 'Last enquiry', 'format' => 'date'],
        ],
        'sorts' => array_fill_keys(['source', 'utm_source', 'utm_campaign', 'total', 'fresh', 'in_progress', 'converted', 'lost', 'conversion', 'last_at'], true),
        'default_sort' => ['total', 'desc'],
        'kpis' => function (array $c) use ($sources) {
            $rows = $c['rows_all'];
            $total = array_sum(array_column($rows, 'total'));
            $bySource = reports_sum_by($rows, 'source', ['total', 'converted'], 0, 'total');
            $top = $bySource[0] ?? null;
            $best = null;
            foreach ($bySource as $s) {
                if ($s['total'] >= 5 && (!$best || $s['converted'] / $s['total'] > $best['converted'] / $best['total'])) {
                    $best = $s;
                }
            }
            $tagged = array_sum(array_map(fn ($r) => $r['utm_source'] !== '' ? (int) $r['total'] : 0, $rows));
            return [
                reports_kpi('Enquiries', $total, 'number', 'navy', count($rows) . ' source / campaign combinations'),
                reports_kpi('Converted', array_sum(array_column($rows, 'converted')), 'number', 'green', reports_pct(array_sum(array_column($rows, 'converted')), $total) !== null ? reports_pct(array_sum(array_column($rows, 'converted')), $total) . '% overall' : null),
                reports_kpi('Top source', $top ? ($sources[$top['label']] ?? label_from_key($top['label'])) : '—', 'text', 'orange', $top ? number_in($top['total']) . ' enquiries' : null),
                reports_kpi('Best converting', $best ? ($sources[$best['label']] ?? label_from_key($best['label'])) : '—', 'text', 'purple', $best ? reports_pct($best['converted'], $best['total']) . '% (min. 5 enquiries)' : 'needs 5+ enquiries'),
                reports_kpi('UTM-tagged', reports_pct($tagged, $total), 'percent', 'blue', number_in($tagged) . ' enquiries carry UTM tags'),
            ];
        },
        'chart' => fn (array $c) => reports_chart('doughnut', 'Enquiries by source', reports_sum_by($c['rows_all'], 'source', ['total'], 0, 'total'), ['total' => 'Enquiries'], 'number', 'label', $sources),
    ];
}

function reports_def_web_contact(): array
{
    $types = ['admission' => 'Admission', 'academic' => 'Academic', 'placement' => 'Placement', 'general' => 'General', 'other' => 'Other'];
    $statuses = ['new' => 'New', 'read' => 'Read', 'replied' => 'Replied', 'closed' => 'Closed'];
    return [
        'from' => 'contact_messages cm LEFT JOIN users u ON u.id = cm.replied_by',
        'select' => 'cm.id, cm.name, cm.email, cm.phone, cm.enquiry_type, cm.subject, cm.status, cm.created_at, cm.replied_at,
            CASE WHEN cm.replied_at IS NOT NULL THEN ROUND(TIMESTAMPDIFF(MINUTE, cm.created_at, cm.replied_at) / 60, 1) END AS response_hours, u.name AS replied_by',
        'filters' => [
            'date' => ['col' => 'cm.created_at', 'label' => 'Received', 'datetime' => true],
            'status' => ['col' => 'cm.status', 'options' => $statuses],
            'type' => ['col' => 'cm.enquiry_type', 'label' => 'Enquiry type', 'options' => $types],
            'folder' => ['label' => 'Folder', 'all_label' => 'All messages', 'options' => ['inbox' => 'Inbox (not archived)', 'starred' => 'Starred', 'archived' => 'Archived'],
                'sql_map' => ['inbox' => 'cm.is_archived = 0', 'starred' => 'cm.is_starred = 1', 'archived' => 'cm.is_archived = 1']],
        ],
        'search' => ['cm.name', 'cm.email', 'cm.phone', 'cm.subject'],
        'search_hint' => 'Name, email, phone, subject…',
        'columns' => [
            ['key' => 'name', 'label' => 'From', 'format' => 'person', 'sub' => 'email', 'sub_label' => 'Email'],
            ['key' => 'enquiry_type', 'label' => 'Type', 'format' => 'label', 'labels' => $types],
            ['key' => 'subject', 'label' => 'Subject'],
            ['key' => 'created_at', 'label' => 'Received', 'format' => 'datetime'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'labels' => $statuses],
            ['key' => 'replied_at', 'label' => 'Replied', 'format' => 'datetime'],
            ['key' => 'response_hours', 'label' => 'Response time', 'format' => 'hours'],
            ['key' => 'replied_by', 'label' => 'Replied by'],
        ],
        'sorts' => ['name' => 'cm.name', 'enquiry_type' => 'cm.enquiry_type', 'created_at' => 'cm.created_at', 'status' => 'cm.status', 'replied_at' => 'cm.replied_at', 'response_hours' => 'response_hours'],
        'default_sort' => ['created_at', 'desc'],
        'tiebreak' => 'cm.id DESC',
        'kpis' => function (array $c) {
            $r = reports_one($c, "COUNT(*) AS total, SUM(cm.status = 'new') AS unread, SUM(cm.replied_at IS NOT NULL) AS replied, AVG(CASE WHEN cm.replied_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, cm.created_at, cm.replied_at) / 60 END) AS hours");
            return [
                reports_kpi('Messages', $r['total'], 'number', 'navy'),
                reports_kpi('Unread', $r['unread'], 'number', 'amber'),
                reports_kpi('Replied', $r['replied'], 'number', 'green'),
                reports_kpi('Reply rate', reports_pct($r['replied'], $r['total']), 'percent', 'blue'),
                reports_kpi('Avg first response', $r['hours'], 'hours', 'purple'),
            ];
        },
        'chart' => fn (array $c) => reports_chart('bar', 'Messages by type', reports_all($c, 'cm.enquiry_type AS label, COUNT(*) AS v, SUM(cm.replied_at IS NOT NULL) AS replied', 'GROUP BY cm.enquiry_type ORDER BY v DESC'), ['v' => 'Messages', 'replied' => 'Replied'], 'number', 'label', $types),
    ];
}

function reports_def_web_newsletter(): array
{
    $statuses = ['subscribed' => 'Subscribed', 'unsubscribed' => 'Unsubscribed', 'bounced' => 'Bounced'];
    return [
        'from' => 'newsletter_subscribers ns',
        'where' => ['ns.subscribed_at IS NOT NULL'],
        'group' => "DATE_FORMAT(ns.subscribed_at, '%Y-%m')",
        'select' => "DATE_FORMAT(ns.subscribed_at, '%Y-%m') AS id, DATE_FORMAT(ns.subscribed_at, '%Y-%m') AS month, COUNT(*) AS joined, SUM(ns.status = 'subscribed') AS active,
            SUM(ns.status = 'unsubscribed') AS unsubscribed, SUM(ns.status = 'bounced') AS bounced, ROUND(SUM(ns.status = 'subscribed') * 100 / COUNT(*), 1) AS retention",
        'filters' => [
            'date' => ['col' => 'ns.subscribed_at', 'label' => 'Signed up', 'datetime' => true],
            'status' => ['col' => 'ns.status', 'label' => 'Current status', 'options' => $statuses],
            'source' => ['col' => 'ns.source', 'label' => 'Sign-up source', 'pretty' => true, 'options_sql' => "SELECT DISTINCT source AS value, source AS label FROM newsletter_subscribers WHERE source IS NOT NULL AND source <> '' ORDER BY source"],
        ],
        'note' => 'Grouped by sign-up month; "still subscribed" is each cohort\'s status today.',
        'columns' => [
            ['key' => 'month', 'label' => 'Sign-up month', 'format' => 'month'],
            ['key' => 'joined', 'label' => 'New sign-ups', 'format' => 'number'],
            ['key' => 'active', 'label' => 'Still subscribed', 'format' => 'number'],
            ['key' => 'unsubscribed', 'label' => 'Unsubscribed', 'format' => 'number'],
            ['key' => 'bounced', 'label' => 'Bounced', 'format' => 'number'],
            ['key' => 'retention', 'label' => 'Retention', 'format' => 'progress'],
        ],
        'sorts' => array_fill_keys(['month', 'joined', 'active', 'unsubscribed', 'bounced', 'retention'], true),
        'default_sort' => ['month', 'desc'],
        'kpis' => function (array $c) {
            $rows = $c['rows_all'];
            $joined = array_sum(array_column($rows, 'joined'));
            $active = array_sum(array_column($rows, 'active'));
            $list = (int) db_value("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'subscribed'");
            return [
                reports_kpi('Sign-ups', $joined, 'number', 'navy', count($rows) . ' months'),
                reports_kpi('Still subscribed', $active, 'number', 'green', reports_share($active, $joined)),
                reports_kpi('Unsubscribed', array_sum(array_column($rows, 'unsubscribed')), 'number', 'red'),
                reports_kpi('Bounced', array_sum(array_column($rows, 'bounced')), 'number', 'amber'),
                reports_kpi('List size today', $list, 'number', 'blue', 'all active subscribers'),
            ];
        },
        'chart' => function (array $c) {
            $rows = $c['rows_all'];
            usort($rows, fn ($a, $b) => strcmp($a['month'], $b['month']));
            $rows = array_slice($rows, -24);
            $run = 0;
            foreach ($rows as &$r) {
                $run += (int) $r['active'];
                $r['cumulative'] = $run;
                $r['label'] = date('M Y', strtotime($r['month'] . '-01'));
            }
            return reports_chart('line', 'Sign-ups per month and cumulative retained subscribers', $rows, ['joined' => 'New sign-ups', 'cumulative' => 'Retained (cumulative)']);
        },
    ];
}

function reports_content_union(): string
{
    return "(SELECT CONCAT('page-', id) AS uid, 'page' AS type, title, slug, status, COALESCE(published_at, created_at) AS published_on, updated_at, NULL AS views FROM pages
        UNION ALL SELECT CONCAT('blog-', id), 'blog', title, slug, status, published_at, updated_at, views FROM blog_posts
        UNION ALL SELECT CONCAT('notice-', id), 'notice', title, slug, status, publish_date, updated_at, views FROM notices
        UNION ALL SELECT CONCAT('event-', id), 'event', title, slug, status, start_datetime, updated_at, NULL FROM events
        UNION ALL SELECT CONCAT('gallery-', id), 'gallery', title, slug, status, event_date, updated_at, NULL FROM galleries) c";
}

function reports_def_web_content(): array
{
    $types = ['page' => 'Page', 'blog' => 'Blog / news', 'notice' => 'Notice', 'event' => 'Event', 'gallery' => 'Gallery'];
    return [
        'from' => reports_content_union(),
        'select' => 'c.uid AS id, c.type, c.title, c.slug, c.status, c.published_on, c.updated_at, c.views',
        'filters' => [
            'date' => ['col' => 'c.published_on', 'label' => 'Published / event date', 'datetime' => true],
            'type' => ['col' => 'c.type', 'label' => 'Content type', 'options' => $types],
            'status' => ['col' => 'c.status', 'pretty' => true, 'options_sql' => 'SELECT DISTINCT c.status AS value, c.status AS label FROM ' . reports_content_union() . ' ORDER BY c.status'],
        ],
        'search' => ['c.title', 'c.slug'],
        'search_hint' => 'Title or slug…',
        'columns' => [
            ['key' => 'title', 'label' => 'Title', 'format' => 'strong', 'sub' => 'slug', 'sub_label' => 'Slug'],
            ['key' => 'type', 'label' => 'Type', 'format' => 'label', 'labels' => $types],
            ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
            ['key' => 'published_on', 'label' => 'Published / date', 'format' => 'date'],
            ['key' => 'updated_at', 'label' => 'Last updated', 'format' => 'datetime'],
            ['key' => 'views', 'label' => 'Views', 'format' => 'number'],
        ],
        'sorts' => ['title' => 'c.title', 'type' => 'c.type', 'status' => 'c.status', 'published_on' => 'c.published_on', 'updated_at' => 'c.updated_at', 'views' => 'c.views'],
        'default_sort' => ['updated_at', 'desc'],
        'tiebreak' => 'c.uid',
        'kpis' => function (array $c) {
            $r = reports_one($c, "COUNT(*) AS total, SUM(c.status IN ('published', 'completed')) AS live, SUM(c.status IN ('draft', 'scheduled')) AS pending, COALESCE(SUM(c.views), 0) AS views,
                SUM(c.updated_at >= NOW() - INTERVAL 30 DAY) AS fresh");
            return [
                reports_kpi('Content items', $r['total'], 'number', 'navy'),
                reports_kpi('Published', $r['live'], 'number', 'green', reports_share($r['live'], $r['total'])),
                reports_kpi('Draft / scheduled', $r['pending'], 'number', 'amber'),
                reports_kpi('Views (blog + notices)', $r['views'], 'number', 'blue'),
                reports_kpi('Updated in 30 days', $r['fresh'], 'number', 'purple'),
            ];
        },
        'chart' => fn (array $c) => reports_chart('stacked', 'Content by type and status', reports_all($c, "c.type AS label, SUM(c.status IN ('published', 'completed')) AS live, SUM(c.status NOT IN ('published', 'completed')) AS other", 'GROUP BY c.type ORDER BY COUNT(*) DESC'),
            ['live' => ['Published / completed', '#22943F'], 'other' => ['Draft, scheduled, expired…', '#F59E0B']], 'number', 'label', $types),
    ];
}

function reports_def_web_traffic(): array
{
    return [
        'from' => 'site_visit_daily v',
        'select' => 'v.day AS id, v.day, v.unique_visitors, v.new_visitors, v.visits, v.pageviews, ROUND(v.pageviews / NULLIF(v.visits, 0), 2) AS pages_per_visit',
        'filters' => [
            'date' => ['col' => 'v.day', 'label' => 'Day'],
        ],
        'note' => 'Recorded by the built-in, cookie-less site tracker (bots excluded). Use Google Analytics for acquisition detail.',
        'columns' => [
            ['key' => 'day', 'label' => 'Day', 'format' => 'date'],
            ['key' => 'unique_visitors', 'label' => 'Unique visitors', 'format' => 'number'],
            ['key' => 'new_visitors', 'label' => 'New visitors', 'format' => 'number'],
            ['key' => 'visits', 'label' => 'Visits', 'format' => 'number'],
            ['key' => 'pageviews', 'label' => 'Pageviews', 'format' => 'number'],
            ['key' => 'pages_per_visit', 'label' => 'Pages / visit', 'format' => 'decimal'],
        ],
        'sorts' => ['day' => 'v.day', 'unique_visitors' => 'v.unique_visitors', 'new_visitors' => 'v.new_visitors', 'visits' => 'v.visits', 'pageviews' => 'v.pageviews', 'pages_per_visit' => 'pages_per_visit'],
        'default_sort' => ['day', 'desc'],
        'kpis' => function (array $c) {
            $r = reports_one($c, 'COUNT(*) AS days, COALESCE(SUM(v.visits), 0) AS visits, COALESCE(SUM(v.pageviews), 0) AS pageviews, AVG(v.unique_visitors) AS avg_uv, COALESCE(SUM(v.new_visitors), 0) AS new_visitors');
            return [
                reports_kpi('Days tracked', $r['days'], 'number', 'navy'),
                reports_kpi('Visits', $r['visits'], 'number', 'blue'),
                reports_kpi('Pageviews', $r['pageviews'], 'number', 'purple'),
                reports_kpi('Avg daily visitors', $r['avg_uv'], 'number', 'green', number_in($r['new_visitors']) . ' new visitors'),
                reports_kpi('Pages per visit', $r['visits'] ? $r['pageviews'] / $r['visits'] : null, 'decimal', 'amber'),
            ];
        },
        'chart' => fn (array $c) => reports_timeseries($c, 'line', 'Visits and pageviews', ['Visits' => ['v.day', 'SUM(v.visits)'], 'Pageviews' => ['v.day', 'SUM(v.pageviews)']]),
    ];
}
