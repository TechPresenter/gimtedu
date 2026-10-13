<?php
/**
 * Admin "Portal Access" screen (/admin/portal-access) - student and faculty portal accounts.
 * Every endpoint requires the users.manage permission; every change is written to the activity log (module "portal").
 *
 *   GET  /portal-access/summary
 *   GET  /portal-access/students?q&program_id&semester&section_id&student_status&account&sort&dir&page&per_page
 *   POST /portal-access/students/{id}/generate      {send_email}      -> temporary password (shown once)
 *   POST /portal-access/students/{id}/reset         {send_email}      -> temporary password (shown once)
 *   POST /portal-access/students/{id}/status        {status: active|disabled}
 *   POST /portal-access/students/{id}/unlock
 *   POST /portal-access/students/bulk               {action: generate|enable|disable|unlock, ids[], send_email}
 *   GET  /portal-access/students/bulk-preview?program_id&semester&section_id
 *   POST /portal-access/students/bulk-generate      {program_id, semester?, section_id?, send_email}
 *   GET  /portal-access/faculty?q&department_id&login&sort&dir&page&per_page
 *   POST /portal-access/faculty/{id}/create         {username?, send_email}
 *   POST /portal-access/faculty/{id}/link           {user_id}
 *   POST /portal-access/faculty/{id}/reset          {send_email}
 *   POST /portal-access/faculty/{id}/status         {status: active|inactive}
 *   POST /portal-access/faculty/{id}/unlock
 *   GET  /portal-access/users?q=                     users that can be linked to a faculty profile
 *   GET  /portal-access/logins?type&status&q&from&to&page&per_page
 *   GET  /portal-access/settings  |  POST /portal-access/settings {portal_student_enabled, portal_faculty_enabled, portal_self_activation}
 */

require_once APP_ROOT . '/app/services/portal_auth.php';

const PORTAL_BULK_LIMIT = 500;

function pa_bool($v): bool
{
    return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on';
}

function pa_student_name_sql(string $a = 's'): string
{
    return "TRIM(CONCAT_WS(' ', $a.first_name, $a.middle_name, $a.last_name))";
}

/** SQL condition for a student account state filter. */
function pa_account_state_sql(string $state): ?string
{
    $notLocked = '(a.locked_until IS NULL OR a.locked_until <= NOW())';
    return [
        'none' => 'a.id IS NULL',
        'disabled' => "a.status = 'disabled'",
        'locked' => "a.status = 'active' AND a.locked_until > NOW()",
        'pending' => "a.status = 'active' AND a.password_hash IS NULL",
        'must_change' => "a.status = 'active' AND a.password_hash IS NOT NULL AND a.must_change_password = 1 AND $notLocked",
        'active' => "a.status = 'active' AND a.password_hash IS NOT NULL AND a.must_change_password = 0 AND $notLocked",
        'has_account' => 'a.id IS NOT NULL',
        'never' => 'a.id IS NOT NULL AND a.last_login_at IS NULL',
        'recent' => 'a.last_login_at >= (NOW() - INTERVAL 7 DAY)',
    ][$state] ?? null;
}

function pa_student_filters(array $q): array
{
    $where = ['1 = 1'];
    $args = [];
    $term = trim((string) ($q['q'] ?? ''));
    if ($term !== '') {
        $like = '%' . $term . '%';
        $where[] = '(s.student_uid LIKE ? OR s.admission_no LIKE ? OR s.email LIKE ? OR s.mobile LIKE ? OR ' . pa_student_name_sql() . ' LIKE ? OR CONCAT(s.first_name, \' \', s.last_name) LIKE ?)';
        array_push($args, $like, $like, $like, $like, $like, $like);
    }
    foreach (['program_id' => 's.program_id', 'section_id' => 's.section_id', 'semester' => 's.current_semester'] as $k => $col) {
        if (!empty($q[$k]) && is_numeric($q[$k])) {
            $where[] = "$col = ?";
            $args[] = (int) $q[$k];
        }
    }
    $status = (string) ($q['student_status'] ?? 'active');
    if ($status !== '' && $status !== 'all' && array_key_exists($status, students_status_options())) {
        $where[] = 's.status = ?';
        $args[] = $status;
    }
    if (!empty($q['account']) && ($sql = pa_account_state_sql((string) $q['account']))) {
        $where[] = $sql;
    }
    return [implode(' AND ', $where), $args];
}

function pa_student_row(array $r): array
{
    $acc = $r['account_id'] ? [
        'status' => $r['account_status'], 'locked_until' => $r['locked_until'], 'password_hash' => $r['has_password'] ? 'x' : null, 'must_change_password' => $r['must_change_password'],
    ] : null;
    return [
        'id' => (int) $r['id'], 'student_uid' => $r['student_uid'], 'name' => $r['full_name'], 'photo' => $r['photo'] ? upload_url($r['photo']) : null,
        'email' => $r['email'], 'mobile' => $r['mobile'], 'student_status' => $r['student_status'], 'program' => $r['program'], 'semester' => (int) $r['current_semester'],
        'section' => $r['section'], 'account_id' => $r['account_id'] ? (int) $r['account_id'] : null, 'username' => $r['username'], 'state' => portal_account_state($acc),
        'failed_attempts' => (int) ($r['failed_attempts'] ?? 0), 'locked_until' => $r['locked_until'], 'last_login_at' => $r['last_login_at'], 'last_login_ip' => $r['last_login_ip'],
        'login_count' => (int) ($r['login_count'] ?? 0), 'activated_at' => $r['activated_at'], 'activation_method' => $r['activation_method'], 'account_created_at' => $r['account_created_at'],
    ];
}

function pa_student_id(array $p): int
{
    $id = (int) $p['id'];
    if (!db_value('SELECT id FROM students WHERE id = ?', [$id])) {
        api_error('Student not found.', 404);
    }
    return $id;
}

function pa_faculty_id(array $p): int
{
    $id = (int) $p['id'];
    if (!db_value('SELECT id FROM faculty WHERE id = ?', [$id])) {
        api_error('Faculty member not found.', 404);
    }
    return $id;
}

/* ------------------------------------------------------------------
 * Summary
 * ------------------------------------------------------------------ */

route('GET', '/portal-access/summary', function () {
    api_require('users', 'manage');
    $st = db_row("SELECT COUNT(*) AS students, COUNT(a.id) AS accounts, SUM(a.activated_at IS NOT NULL) AS activated, SUM(a.activation_method = 'self') AS self_activated,
                         SUM(a.status = 'disabled') AS disabled, SUM(a.status = 'active' AND a.locked_until > NOW()) AS locked,
                         SUM(a.status = 'active' AND a.must_change_password = 1) AS must_change, SUM(a.id IS NOT NULL AND a.last_login_at IS NULL) AS never,
                         SUM(a.last_login_at >= (NOW() - INTERVAL 7 DAY)) AS recent
                  FROM students s LEFT JOIN student_accounts a ON a.student_id = s.id WHERE s.status = 'active'") ?? [];
    $fa = db_row("SELECT COUNT(*) AS faculty, COUNT(u.id) AS linked, SUM(u.status = 'active') AS active, SUM(u.locked_until > NOW()) AS locked,
                         SUM(u.must_change_password = 1) AS must_change, SUM(u.last_login_at >= (NOW() - INTERVAL 7 DAY)) AS recent
                  FROM faculty f LEFT JOIN users u ON u.id = f.user_id AND u.faculty_id = f.id WHERE f.status IN ('active', 'on_leave')") ?? [];
    $todayStudent = db_row("SELECT SUM(status = 'success') AS ok, SUM(status IN ('failed', 'locked', 'blocked')) AS failed FROM student_login_logs WHERE created_at >= CURDATE()") ?? [];
    $todayFaculty = db_row("SELECT SUM(l.status = 'success') AS ok, SUM(l.status IN ('failed', 'locked', 'blocked')) AS failed FROM login_logs l JOIN users u ON u.id = l.user_id
                            WHERE u.faculty_id IS NOT NULL AND l.created_at >= CURDATE()") ?? [];
    $i = fn ($row, $k) => (int) ($row[$k] ?? 0);
    api_ok([
        'students' => ['total' => $i($st, 'students'), 'accounts' => $i($st, 'accounts'), 'without' => $i($st, 'students') - $i($st, 'accounts'), 'activated' => $i($st, 'activated'),
            'self_activated' => $i($st, 'self_activated'), 'disabled' => $i($st, 'disabled'), 'locked' => $i($st, 'locked'), 'must_change' => $i($st, 'must_change'),
            'never' => $i($st, 'never'), 'recent' => $i($st, 'recent')],
        'faculty' => ['total' => $i($fa, 'faculty'), 'linked' => $i($fa, 'linked'), 'without' => $i($fa, 'faculty') - $i($fa, 'linked'), 'active' => $i($fa, 'active'),
            'locked' => $i($fa, 'locked'), 'must_change' => $i($fa, 'must_change'), 'recent' => $i($fa, 'recent')],
        'today' => ['student_logins' => $i($todayStudent, 'ok'), 'faculty_logins' => $i($todayFaculty, 'ok'), 'failed' => $i($todayStudent, 'failed') + $i($todayFaculty, 'failed')],
        'urls' => ['student' => portal_login_url('student'), 'faculty' => portal_login_url('faculty')],
        'settings' => ['portal_student_enabled' => portal_student_enabled(), 'portal_faculty_enabled' => portal_faculty_enabled(), 'portal_self_activation' => portal_self_activation_enabled()],
    ]);
});

/* ------------------------------------------------------------------
 * Students
 * ------------------------------------------------------------------ */

route('GET', '/portal-access/students', function () {
    api_require('users', 'manage');
    [$where, $args] = pa_student_filters($_GET);
    [$page, $perPage] = api_pagination(25);
    $total = (int) db_value("SELECT COUNT(*) FROM students s LEFT JOIN student_accounts a ON a.student_id = s.id WHERE $where", $args);
    $pg = paginate($total, $page, $perPage);
    $sorts = ['name' => 's.first_name %d, s.last_name %d', 'student_uid' => 's.student_uid %d', 'last_login' => 'a.last_login_at IS NULL, a.last_login_at %d', 'program' => 'p.short_name %d, s.current_semester %d, s.student_uid'];
    $sort = $sorts[$_GET['sort'] ?? ''] ?? $sorts['student_uid'];
    $dir = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    $order = str_replace('%d', $dir, $sort);
    $rows = db_all('SELECT s.id, s.student_uid, ' . pa_student_name_sql() . " AS full_name, s.photo, s.email, s.mobile, s.status AS student_status, s.current_semester,
                           p.short_name AS program, sc.name AS section, a.id AS account_id, a.username, a.status AS account_status, a.password_hash IS NOT NULL AS has_password,
                           a.must_change_password, a.failed_attempts, a.locked_until, a.last_login_at, a.last_login_ip, a.login_count, a.activated_at, a.activation_method,
                           a.created_at AS account_created_at
                    FROM students s JOIN programs p ON p.id = s.program_id LEFT JOIN sections sc ON sc.id = s.section_id LEFT JOIN student_accounts a ON a.student_id = s.id
                    WHERE $where ORDER BY $order LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $args);
    api_ok(['rows' => array_map('pa_student_row', $rows), 'pagination' => $pg]);
});

route('POST', '/portal-access/students/{id:\d+}/generate', function ($p) {
    api_require('users', 'manage');
    $id = pa_student_id($p);
    $r = portal_student_generate($id, pa_bool(api_param('send_email')));
    api_ok($r, 'Portal account created for ' . $r['name'] . '.' . ($r['emailed'] ? ' Login details emailed.' : ''));
});

route('POST', '/portal-access/students/{id:\d+}/reset', function ($p) {
    api_require('users', 'manage');
    $id = pa_student_id($p);
    $r = portal_student_admin_reset($id, pa_bool(api_param('send_email')));
    api_ok($r, 'Password reset for ' . $r['name'] . '.' . ($r['emailed'] ? ' New login emailed.' : ''));
});

route('POST', '/portal-access/students/{id:\d+}/status', function ($p) {
    api_require('users', 'manage');
    $id = pa_student_id($p);
    $status = (string) api_param('status', '');
    if (!in_array($status, ['active', 'disabled'], true)) {
        api_error('Choose active or disabled.', 422, ['status' => 'Choose active or disabled.']);
    }
    $acc = portal_student_account($id);
    if (!$acc) {
        api_error('This student has no portal account yet.', 422);
    }
    db_update('student_accounts', ['status' => $status], 'id = ?', [$acc['id']]);
    if ($status === 'disabled') {
        db_exec("DELETE FROM student_account_tokens WHERE account_id = ? AND type = 'remember'", [$acc['id']]);
    }
    $s = portal_find_student_by_id($id);
    log_activity('update', 'portal', $id, ($status === 'disabled' ? 'Disabled' : 'Enabled') . ' student portal access for ' . $s['full_name'] . ' (' . $s['student_uid'] . ')');
    api_ok(['state' => portal_account_state(portal_student_account($id))], $status === 'disabled' ? 'Portal access disabled. The student has been signed out.' : 'Portal access enabled.');
});

route('POST', '/portal-access/students/{id:\d+}/unlock', function ($p) {
    api_require('users', 'manage');
    $id = pa_student_id($p);
    $acc = portal_student_account($id);
    if (!$acc) {
        api_error('This student has no portal account yet.', 422);
    }
    db_update('student_accounts', ['failed_attempts' => 0, 'locked_until' => null], 'id = ?', [$acc['id']]);
    $s = portal_find_student_by_id($id);
    log_activity('update', 'portal', $id, 'Unlocked student portal account of ' . $s['full_name'] . ' (' . $s['student_uid'] . ')');
    api_ok(['state' => portal_account_state(portal_student_account($id))], 'Account unlocked.');
});

route('POST', '/portal-access/students/bulk', function () {
    api_require('users', 'manage');
    $action = (string) api_param('action', '');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) (api_input()['ids'] ?? [])))));
    if (!in_array($action, ['generate', 'enable', 'disable', 'unlock'], true)) {
        api_error('Unknown bulk action.', 422);
    }
    if (!$ids) {
        api_error('Select at least one student.', 422);
    }
    if (count($ids) > PORTAL_BULK_LIMIT) {
        api_error('Select at most ' . PORTAL_BULK_LIMIT . ' students at a time.', 422);
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    if ($action === 'generate') {
        @set_time_limit(0);
        $send = pa_bool(api_param('send_email'));
        $targets = db_column("SELECT s.id FROM students s LEFT JOIN student_accounts a ON a.student_id = s.id WHERE s.id IN ($in) AND a.id IS NULL ORDER BY s.student_uid", $ids);
        $created = [];
        foreach ($targets as $sid) {
            $created[] = portal_student_generate((int) $sid, $send, 'bulk', false);
        }
        log_activity('create', 'portal', null, 'Generated ' . count($created) . ' student portal account(s) for selected students' . ($send ? ' (emailed)' : ''), 'success', ['ids' => array_map('intval', $targets)]);
        api_ok(['created' => $created, 'skipped' => count($ids) - count($created)], count($created) . ' account(s) created' . (count($ids) > count($created) ? ', ' . (count($ids) - count($created)) . ' already had one.' : '.'));
    }
    $set = ['enable' => ['status' => 'active'], 'disable' => ['status' => 'disabled'], 'unlock' => ['failed_attempts' => 0, 'locked_until' => null]][$action];
    $n = db_update('student_accounts', $set, "student_id IN ($in)", $ids);
    if ($action === 'disable') {
        db_exec("DELETE t FROM student_account_tokens t JOIN student_accounts a ON a.id = t.account_id WHERE a.student_id IN ($in)", $ids);
    }
    $label = ['enable' => 'Enabled', 'disable' => 'Disabled', 'unlock' => 'Unlocked'][$action];
    log_activity('update', 'portal', null, "$label student portal access for " . count($ids) . ' selected student(s)', 'success', ['ids' => $ids]);
    api_ok(['updated' => $n], "$label " . $n . ' account(s).');
});

function pa_bulk_scope(array $q): array
{
    $programId = (int) ($q['program_id'] ?? 0);
    if (!$programId || !db_value('SELECT id FROM programs WHERE id = ?', [$programId])) {
        api_error('Select a program.', 422, ['program_id' => 'Select a program.']);
    }
    $where = "s.status = 'active' AND a.id IS NULL AND s.program_id = ?";
    $args = [$programId];
    if (!empty($q['semester'])) {
        $where .= ' AND s.current_semester = ?';
        $args[] = (int) $q['semester'];
    }
    if (!empty($q['section_id'])) {
        $where .= ' AND s.section_id = ?';
        $args[] = (int) $q['section_id'];
    }
    return [$where, $args];
}

route('GET', '/portal-access/students/bulk-preview', function () {
    api_require('users', 'manage');
    [$where, $args] = pa_bulk_scope($_GET);
    $count = (int) db_value("SELECT COUNT(*) FROM students s LEFT JOIN student_accounts a ON a.student_id = s.id WHERE $where", $args);
    $withEmail = (int) db_value("SELECT COUNT(*) FROM students s LEFT JOIN student_accounts a ON a.student_id = s.id WHERE $where AND s.email LIKE '%@%'", $args);
    api_ok(['count' => $count, 'with_email' => $withEmail, 'limit' => PORTAL_BULK_LIMIT]);
});

route('POST', '/portal-access/students/bulk-generate', function () {
    api_require('users', 'manage');
    $in = api_input();
    [$where, $args] = pa_bulk_scope($in);
    $send = pa_bool($in['send_email'] ?? false);
    @set_time_limit(0);
    $ids = db_column("SELECT s.id FROM students s LEFT JOIN student_accounts a ON a.student_id = s.id WHERE $where ORDER BY s.student_uid LIMIT " . PORTAL_BULK_LIMIT, $args);
    if (!$ids) {
        api_error('Every active student in this selection already has a portal account.', 422);
    }
    $created = [];
    foreach ($ids as $sid) {
        $created[] = portal_student_generate((int) $sid, $send, 'bulk', false);
    }
    $program = db_value('SELECT short_name FROM programs WHERE id = ?', [(int) $in['program_id']]);
    $scope = $program . (!empty($in['semester']) ? ' Sem ' . (int) $in['semester'] : '') . (!empty($in['section_id']) ? ' Sec ' . db_value('SELECT name FROM sections WHERE id = ?', [(int) $in['section_id']]) : '');
    log_activity('create', 'portal', null, 'Bulk generated ' . count($created) . " student portal account(s) for $scope" . ($send ? ' (login details emailed)' : ''), 'success', ['count' => count($created)]);
    api_ok(['created' => $created, 'emailed' => count(array_filter($created, fn ($c) => $c['emailed']))], count($created) . " portal account(s) created for $scope.");
});

/* ------------------------------------------------------------------
 * Faculty
 * ------------------------------------------------------------------ */

function pa_faculty_state_sql(string $state): ?string
{
    $linked = 'u.id IS NOT NULL AND u.faculty_id = f.id';
    $notLocked = '(u.locked_until IS NULL OR u.locked_until <= NOW())';
    return [
        'none' => 'u.id IS NULL',
        'unlinked' => 'u.id IS NOT NULL AND (u.faculty_id IS NULL OR u.faculty_id <> f.id)',
        'inactive' => "$linked AND u.status <> 'active'",
        'locked' => "$linked AND u.status = 'active' AND u.locked_until > NOW()",
        'must_change' => "$linked AND u.status = 'active' AND u.must_change_password = 1 AND $notLocked",
        'active' => "$linked AND u.status = 'active' AND u.must_change_password = 0 AND $notLocked",
        'has_login' => $linked,
    ][$state] ?? null;
}

function pa_faculty_state(array $r): string
{
    if (!$r['user_id']) {
        return 'none';
    }
    if ((int) $r['user_faculty_id'] !== (int) $r['id']) {
        return 'unlinked';
    }
    if ($r['user_status'] !== 'active') {
        return 'inactive';
    }
    if ($r['locked_until'] && strtotime($r['locked_until']) > time()) {
        return 'locked';
    }
    return (int) $r['must_change_password'] ? 'must_change' : 'active';
}

route('GET', '/portal-access/faculty', function () {
    api_require('users', 'manage');
    $where = ['1 = 1'];
    $args = [];
    $term = trim((string) ($_GET['q'] ?? ''));
    if ($term !== '') {
        $like = '%' . $term . '%';
        $where[] = "(f.employee_id LIKE ? OR f.email LIKE ? OR CONCAT(f.first_name, ' ', COALESCE(f.last_name, '')) LIKE ? OR u.username LIKE ?)";
        array_push($args, $like, $like, $like, $like);
    }
    if (!empty($_GET['department_id'])) {
        $where[] = 'f.department_id = ?';
        $args[] = (int) $_GET['department_id'];
    }
    $fstatus = (string) ($_GET['faculty_status'] ?? 'current');
    if ($fstatus === 'current') {
        $where[] = "f.status IN ('active', 'on_leave')";
    } elseif ($fstatus !== 'all' && $fstatus !== '') {
        $where[] = 'f.status = ?';
        $args[] = $fstatus;
    }
    if (!empty($_GET['login']) && ($sql = pa_faculty_state_sql((string) $_GET['login']))) {
        $where[] = $sql;
    }
    $w = implode(' AND ', $where);
    [$page, $perPage] = api_pagination(25);
    $from = 'FROM faculty f LEFT JOIN departments d ON d.id = f.department_id LEFT JOIN users u ON u.id = f.user_id';
    $total = (int) db_value("SELECT COUNT(*) $from WHERE $w", $args);
    $pg = paginate($total, $page, $perPage);
    $dir = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    $order = ['name' => "f.first_name $dir, f.last_name $dir", 'employee_id' => "f.employee_id $dir", 'last_login' => "u.last_login_at IS NULL, u.last_login_at $dir",
        'department' => "d.name $dir, f.first_name"][$_GET['sort'] ?? ''] ?? "f.employee_id $dir";
    $rows = db_all("SELECT f.id, f.employee_id, TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS full_name, f.photo, f.designation, f.email, f.phone, f.status AS faculty_status,
                           d.name AS department, u.id AS user_id, u.username, u.email AS login_email, u.status AS user_status, u.faculty_id AS user_faculty_id, u.must_change_password,
                           u.locked_until, u.last_login_at, u.last_login_ip
                    $from WHERE $w ORDER BY $order LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $args);
    $out = array_map(fn ($r) => [
        'id' => (int) $r['id'], 'employee_id' => $r['employee_id'], 'name' => $r['full_name'], 'photo' => $r['photo'] ? upload_url($r['photo']) : null, 'designation' => $r['designation'],
        'email' => $r['email'], 'phone' => $r['phone'], 'faculty_status' => $r['faculty_status'], 'department' => $r['department'],
        'user_id' => $r['user_id'] ? (int) $r['user_id'] : null, 'username' => $r['username'], 'login_email' => $r['login_email'], 'state' => pa_faculty_state($r),
        'locked_until' => $r['locked_until'], 'last_login_at' => $r['last_login_at'], 'last_login_ip' => $r['last_login_ip'],
    ], $rows);
    api_ok(['rows' => $out, 'pagination' => $pg]);
});

route('POST', '/portal-access/faculty/{id:\d+}/create', function ($p) {
    api_require('users', 'manage');
    $id = pa_faculty_id($p);
    $r = portal_faculty_create_login($id, (string) api_param('username', ''), pa_bool(api_param('send_email')));
    api_ok($r, 'Login created for ' . $r['name'] . '.' . ($r['emailed'] ? ' Login details emailed.' : ''));
});

route('POST', '/portal-access/faculty/{id:\d+}/link', function ($p) {
    api_require('users', 'manage');
    $id = pa_faculty_id($p);
    $userId = (int) api_param('user_id', 0);
    if (!$userId) {
        api_error('Select a user account.', 422, ['user_id' => 'Select a user account.']);
    }
    $r = portal_faculty_link_user($id, $userId);
    api_ok($r, 'User "' . $r['username'] . '" is now linked to this faculty profile.');
});

route('POST', '/portal-access/faculty/{id:\d+}/reset', function ($p) {
    api_require('users', 'manage');
    $id = pa_faculty_id($p);
    $r = portal_faculty_admin_reset($id, pa_bool(api_param('send_email')));
    api_ok($r, 'Password reset for ' . $r['name'] . '.' . ($r['emailed'] ? ' New login emailed.' : ''));
});

route('POST', '/portal-access/faculty/{id:\d+}/status', function ($p) {
    api_require('users', 'manage');
    $id = pa_faculty_id($p);
    $status = (string) api_param('status', '');
    if (!in_array($status, ['active', 'inactive'], true)) {
        api_error('Choose active or inactive.', 422, ['status' => 'Choose active or inactive.']);
    }
    [$f, $u] = portal_faculty_user($id);
    if ((int) $u['id'] === (int) user_id()) {
        api_error('You cannot disable your own account.', 422);
    }
    if (is_super_admin((int) $u['id'])) {
        api_error('Super admin accounts cannot be disabled from this screen.', 422);
    }
    db_update('users', ['status' => $status], 'id = ?', [$u['id']]);
    if ($status === 'inactive') {
        db_exec("DELETE FROM user_tokens WHERE user_id = ? AND type = 'remember'", [$u['id']]);
    }
    log_activity('update', 'portal', $id, ($status === 'inactive' ? 'Disabled' : 'Enabled') . ' faculty login "' . $u['username'] . '" of ' . $f['full_name']);
    api_ok(null, $status === 'inactive' ? 'Login disabled. ' . $f['full_name'] . ' can no longer sign in.' : 'Login enabled.');
});

route('POST', '/portal-access/faculty/{id:\d+}/unlock', function ($p) {
    api_require('users', 'manage');
    $id = pa_faculty_id($p);
    [$f, $u] = portal_faculty_user($id);
    db_update('users', ['failed_attempts' => 0, 'locked_until' => null], 'id = ?', [$u['id']]);
    log_activity('update', 'portal', $id, 'Unlocked faculty login "' . $u['username'] . '" of ' . $f['full_name']);
    api_ok(null, 'Account unlocked.');
});

route('GET', '/portal-access/users', function () {
    api_require('users', 'manage');
    $term = trim((string) ($_GET['q'] ?? ''));
    $where = "u.faculty_id IS NULL AND NOT EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.is_super = 1)";
    $args = [];
    if ($term !== '') {
        $where .= ' AND (u.name LIKE ? OR u.email LIKE ? OR u.username LIKE ?)';
        $like = '%' . $term . '%';
        array_push($args, $like, $like, $like);
    }
    $rows = db_all("SELECT u.id, u.name, u.username, u.email, u.status FROM users u WHERE $where ORDER BY u.name LIMIT 20", $args);
    api_ok(array_map(fn ($u) => ['value' => (int) $u['id'], 'label' => $u['name'] . ' (' . $u['username'] . ')', 'sub' => $u['email'] . ($u['status'] !== 'active' ? ' · inactive' : '')], $rows));
});

/* ------------------------------------------------------------------
 * Login activity (students + faculty)
 * ------------------------------------------------------------------ */

route('GET', '/portal-access/logins', function () {
    api_require('users', 'manage');
    $type = (string) ($_GET['type'] ?? 'all');
    $status = (string) ($_GET['status'] ?? '');
    $term = trim((string) ($_GET['q'] ?? ''));
    $from = (string) ($_GET['from'] ?? '');
    $to = (string) ($_GET['to'] ?? '');
    $parts = [];
    $args = [];
    $common = function (string $nameSql, string $refSql) use ($status, $term, $from, $to): array {
        $w = [];
        $a = [];
        if ($status !== '' && in_array($status, ['success', 'failed', 'locked', 'blocked', 'logout', 'activated', 'reset'], true)) {
            $w[] = 'l.status = ?';
            $a[] = $status;
        }
        if ($term !== '') {
            $like = '%' . $term . '%';
            $w[] = "($nameSql LIKE ? OR $refSql LIKE ? OR l.identifier LIKE ? OR l.ip_address LIKE ?)";
            array_push($a, $like, $like, $like, $like);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $w[] = 'l.created_at >= ?';
            $a[] = $from . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $w[] = 'l.created_at <= ?';
            $a[] = $to . ' 23:59:59';
        }
        return [$w ? ' AND ' . implode(' AND ', $w) : '', $a];
    };
    if ($type === 'all' || $type === 'student') {
        $name = "TRIM(CONCAT_WS(' ', s.first_name, s.last_name))";
        [$w, $a] = $common($name, 's.student_uid');
        $parts[] = "SELECT 'student' AS type, l.id, l.created_at, l.status, l.reason, l.ip_address, l.user_agent, l.identifier, s.id AS person_id, $name AS name, s.student_uid AS ref
                    FROM student_login_logs l LEFT JOIN students s ON s.id = l.student_id WHERE 1 = 1$w";
        $args = array_merge($args, $a);
    }
    if ($type === 'all' || $type === 'faculty') {
        $name = "TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name))";
        [$w, $a] = $common($name, 'f.employee_id');
        $parts[] = "SELECT 'faculty' AS type, l.id, l.created_at, l.status, l.reason, l.ip_address, l.user_agent, l.identifier, f.id AS person_id, $name AS name, f.employee_id AS ref
                    FROM login_logs l JOIN users u ON u.id = l.user_id JOIN faculty f ON f.id = u.faculty_id WHERE 1 = 1$w";
        $args = array_merge($args, $a);
    }
    if (!$parts) {
        api_error('Unknown type.', 422);
    }
    $union = implode(' UNION ALL ', $parts);
    [$page, $perPage] = api_pagination(25);
    $total = (int) db_value("SELECT COUNT(*) FROM ($union) x", $args);
    $pg = paginate($total, $page, $perPage);
    $rows = db_all("SELECT * FROM ($union) x ORDER BY x.created_at DESC, x.id DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $args);
    foreach ($rows as &$r) {
        $r['person_id'] = $r['person_id'] !== null ? (int) $r['person_id'] : null;
        $r['device'] = browser_label($r['user_agent']);
        $r['key'] = $r['type'] . '-' . $r['id'];
        unset($r['user_agent']);
    }
    unset($r);
    api_ok(['rows' => $rows, 'pagination' => $pg]);
});

/* ------------------------------------------------------------------
 * Portal settings
 * ------------------------------------------------------------------ */

route('GET', '/portal-access/settings', function () {
    api_require('users', 'manage');
    api_ok(['portal_student_enabled' => portal_student_enabled(), 'portal_faculty_enabled' => portal_faculty_enabled(), 'portal_self_activation' => portal_self_activation_enabled()]);
});

route('POST', '/portal-access/settings', function () {
    api_require('users', 'manage');
    $in = api_input();
    $changes = [];
    foreach (['portal_student_enabled' => 'Student portal', 'portal_faculty_enabled' => 'Faculty portal', 'portal_self_activation' => 'Student self-activation'] as $key => $label) {
        if (!array_key_exists($key, $in)) {
            continue;
        }
        $val = pa_bool($in[$key]) ? '1' : '0';
        if ((string) setting($key, '1') !== $val) {
            save_setting($key, $val, 'portal');
            $changes[] = $label . ' ' . ($val === '1' ? 'on' : 'off');
        }
    }
    if ($changes) {
        log_activity('update', 'portal', null, 'Portal settings changed: ' . implode(', ', $changes));
    }
    api_ok(['portal_student_enabled' => portal_student_enabled(), 'portal_faculty_enabled' => portal_faculty_enabled(), 'portal_self_activation' => portal_self_activation_enabled()],
        $changes ? 'Portal settings saved.' : 'No changes to save.');
});
