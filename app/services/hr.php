<?php
/**
 * HR domain logic for faculty & non-teaching staff: people lookup, masking, leave policy, working days,
 * leave balances & validation, approvals, login accounts, employee attendance, profile + dashboard payloads.
 *
 * Employees are addressed by (type, id) where type is 'faculty' or 'staff'. A compact "employee ref"
 * ("f-12" / "s-4") is used by forms that can pick either kind of employee.
 */

const HR_TYPES = ['faculty', 'staff'];

/* ------------------------------------------------------------------
 * People
 * ------------------------------------------------------------------ */

function hr_type(string $type): string
{
    if (!in_array($type, HR_TYPES, true)) {
        throw new InvalidArgumentException('Unknown employee type.');
    }
    return $type;
}

/** "f-12" for faculty #12, "s-4" for staff #4. */
function hr_ref(string $type, int $id): string
{
    return ($type === 'faculty' ? 'f-' : 's-') . $id;
}

/** Parse an employee ref into [type, id] or null. */
function hr_parse_ref($ref): ?array
{
    if (!is_string($ref) || !preg_match('/^([fs])-(\d+)$/', trim($ref), $m)) {
        return null;
    }
    return [$m[1] === 'f' ? 'faculty' : 'staff', (int) $m[2]];
}

/** SQL expression for an employee's display name. */
function hr_name_sql(string $type, string $alias): string
{
    return $type === 'faculty'
        ? "TRIM(CONCAT_WS(' ', $alias.title, $alias.first_name, $alias.last_name))"
        : "TRIM(CONCAT_WS(' ', $alias.first_name, $alias.last_name))";
}

/** Inline lookup source listing faculty and staff together (value = employee ref). */
function hr_employee_source(): array
{
    $union = "(SELECT CONCAT('f-', f.id) AS ref, 'faculty' AS etype, f.id AS eid, " . hr_name_sql('faculty', 'f') . " AS name, f.employee_id AS code,
                      CONCAT('Faculty · ', COALESCE(d.code, f.designation)) AS info, f.status AS status, f.first_name AS sort_name
               FROM faculty f LEFT JOIN departments d ON d.id = f.department_id
               UNION ALL
               SELECT CONCAT('s-', s.id), 'staff', s.id, " . hr_name_sql('staff', 's') . ", s.employee_id,
                      CONCAT('Staff · ', s.designation), s.status, s.first_name
               FROM staff s) e";
    return [
        'table' => $union, 'value' => 'e.ref', 'label' => "CONCAT(e.name, ' (', e.code, ')')", 'sub' => 'e.info',
        'where' => "e.status IN ('active', 'on_leave')", 'order' => 'e.sort_name', 'search' => ['e.name', 'e.code'],
        'filters' => ['employee_type' => 'e.etype'], 'async' => true,
    ];
}

/** One employee with department + display name, or null. */
function hr_person(string $type, int $id): ?array
{
    hr_type($type);
    $row = db_row('SELECT t.*, ' . hr_name_sql($type, 't') . ' AS full_name, d.name AS department_name, d.code AS department_code
                   FROM ' . $type . ' t LEFT JOIN departments d ON d.id = t.department_id WHERE t.id = ?', [$id]);
    if ($row) {
        $row['employee_type'] = $type;
        $row['ref'] = hr_ref($type, $id);
    }
    return $row;
}

/** Can the current user see unmasked bank / PAN details? */
function hr_can_see_sensitive(): bool
{
    return can('faculty', 'edit');
}

/** Mask all but the last $visible characters: "123456789012" -> "•••• •••• 9012". */
function hr_mask(?string $value, int $visible = 4): ?string
{
    $v = preg_replace('/\s+/', '', (string) $value);
    if ($v === '') {
        return null;
    }
    if (mb_strlen($v) <= $visible) {
        return str_repeat('•', mb_strlen($v));
    }
    return str_repeat('•', min(8, mb_strlen($v) - $visible)) . mb_substr($v, -$visible);
}

/** Applies masking to sensitive columns of an employee row for users without edit rights. */
function hr_mask_row(array $row): array
{
    $row['bank_account_masked'] = hr_mask($row['bank_account'] ?? null);
    $row['pan_masked'] = hr_mask($row['pan_no'] ?? null, 4);
    if (!hr_can_see_sensitive()) {
        foreach (['bank_account', 'pan_no'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = $k === 'bank_account' ? $row['bank_account_masked'] : $row['pan_masked'];
            }
        }
    }
    return $row;
}

/** Next employee number: GIMT-F053 / GIMT-S031. */
function hr_next_employee_id(string $type): string
{
    $prefix = $type === 'faculty' ? 'GIMT-F' : 'GIMT-S';
    $max = (int) db_value('SELECT MAX(CAST(SUBSTRING(employee_id, ?) AS UNSIGNED)) FROM ' . hr_type($type) . ' WHERE employee_id LIKE ?', [strlen($prefix) + 1, $prefix . '%']);
    do {
        $max++;
        $candidate = $prefix . str_pad((string) $max, 3, '0', STR_PAD_LEFT);
    } while ((int) db_value('SELECT COUNT(*) FROM ' . $type . ' WHERE employee_id = ?', [$candidate]));
    return $candidate;
}

/** Shared validation for faculty & staff forms (formats that the generic engine cannot express). */
function hr_validate_person(array $data, ?array $old, array $input, string $type): array
{
    $errors = [];
    $today = date('Y-m-d');
    if (!empty($data['dob'])) {
        $age = (int) date_diff(date_create($data['dob']), date_create($today))->y;
        if ($data['dob'] >= $today || $age < 18 || $age > 80) {
            $errors['dob'] = 'Enter a valid date of birth (employee must be 18-80 years old).';
        }
    }
    if (!empty($data['joining_date']) && $data['joining_date'] > date('Y-m-d', strtotime('+6 months'))) {
        $errors['joining_date'] = 'Joining date cannot be more than 6 months in the future.';
    }
    if (!empty($data['dob']) && !empty($data['joining_date']) && $data['joining_date'] <= $data['dob']) {
        $errors['joining_date'] = 'Joining date must be after the date of birth.';
    }
    if (!empty($data['pan_no']) && !str_contains((string) $data['pan_no'], '•') && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', strtoupper(preg_replace('/\s+/', '', (string) $data['pan_no'])))) {
        $errors['pan_no'] = 'PAN must look like ABCDE1234F.';
    }
    if (!empty($data['bank_ifsc']) && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', strtoupper(trim((string) $data['bank_ifsc'])))) {
        $errors['bank_ifsc'] = 'IFSC must look like SBIN0001234.';
    }
    if (!empty($data['bank_account']) && !str_contains((string) $data['bank_account'], '•') && !preg_match('/^[0-9]{9,18}$/', preg_replace('/\s+/', '', (string) $data['bank_account']))) {
        $errors['bank_account'] = 'Account number must be 9-18 digits.';
    }
    if (!empty($data['salary']) && (float) $data['salary'] > 2000000) {
        $errors['salary'] = 'Monthly salary looks too high. Enter the monthly gross amount.';
    }
    if (!empty($data['pincode']) && !preg_match('/^[1-9][0-9]{5}$/', (string) $data['pincode'])) {
        $errors['pincode'] = 'Enter a valid 6-digit PIN code.';
    }
    // Login account requested on create: the email/username must be free in users.
    if (!$old && !empty($input['create_login']) && in_array($input['create_login'], [true, 1, '1', 'true', 'on'], true)) {
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($email === '') {
            $errors['email'] = 'Email is required to create a login account.';
        } elseif ((int) db_value('SELECT COUNT(*) FROM users WHERE email = ?', [$email])) {
            $errors['email'] = 'A user account with this email already exists.';
        }
        $username = trim((string) ($input['login_username'] ?? ''));
        if ($username !== '' && !preg_match('/^[a-zA-Z0-9._\-]{3,60}$/', $username)) {
            $errors['login_username'] = 'Username may contain letters, numbers, dots, dashes and underscores (3-60).';
        } elseif ($username !== '' && (int) db_value('SELECT COUNT(*) FROM users WHERE username = ?', [$username])) {
            $errors['login_username'] = 'This username is already taken.';
        }
        if ($type === 'staff' && !empty($input['login_role_id'])) {
            if (!(int) db_value('SELECT COUNT(*) FROM roles WHERE id = ? AND is_super = 0', [(int) $input['login_role_id']])) {
                $errors['login_role_id'] = 'Select a valid role.';
            }
        }
    }
    return $errors;
}

/** Normalises formats before saving (upper-case PAN/IFSC, digits-only account, auto employee id). */
function hr_before_save_person(array $data, ?int $id, string $type): array
{
    if ($id === null && empty($data['employee_id'])) {
        $data['employee_id'] = hr_next_employee_id($type);
    }
    if (!empty($data['employee_id'])) {
        $data['employee_id'] = strtoupper(trim((string) $data['employee_id']));
    }
    foreach (['pan_no', 'bank_ifsc'] as $k) {
        if (!empty($data[$k])) {
            $data[$k] = strtoupper(preg_replace('/\s+/', '', (string) $data[$k]));
        }
    }
    // Masked values sent back unchanged by a user without edit rights must never overwrite the stored value.
    foreach (['pan_no', 'bank_account'] as $k) {
        if (isset($data[$k]) && str_contains((string) $data[$k], '•')) {
            unset($data[$k]);
        } elseif (!empty($data[$k]) && $k === 'bank_account') {
            $data[$k] = preg_replace('/\s+/', '', (string) $data[$k]);
        }
    }
    return $data;
}

/**
 * Keeps the linked login account in sync with profile edits (name, phone, designation, department, status).
 * The login email follows the profile email only when it was the same as the previous profile email ($old row).
 */
function hr_sync_user(string $type, int $id, ?array $old = null): void
{
    $p = hr_person($type, $id);
    if (!$p || empty($p['user_id'])) {
        return;
    }
    $user = db_row('SELECT id, email FROM users WHERE id = ?', [(int) $p['user_id']]);
    if (!$user) {
        return;
    }
    $update = ['name' => mb_substr($p['full_name'], 0, 150), 'phone' => $p['phone'], 'designation' => $p['designation'], 'department_id' => $p['department_id']];
    $newEmail = strtolower(trim((string) ($p['email'] ?? '')));
    if ($old && $newEmail !== '' && strtolower((string) ($old['email'] ?? '')) === strtolower((string) $user['email']) && $newEmail !== strtolower((string) $user['email'])
        && !(int) db_value('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [$newEmail, (int) $user['id']])) {
        $update['email'] = $newEmail;
    }
    if (in_array($p['status'], ['resigned', 'retired', 'inactive'], true)) {
        $update['status'] = 'inactive';
    }
    db_update('users', $update, 'id = ?', [(int) $p['user_id']]);
}

/** Blocks deleting people who still own academic responsibilities. Returns a message or null. */
function hr_delete_blocker(string $type, int $id, array $row): ?string
{
    $name = $type === 'faculty' ? trim(($row['title'] ?? '') . ' ' . $row['first_name'] . ' ' . ($row['last_name'] ?? '')) : trim($row['first_name'] . ' ' . ($row['last_name'] ?? ''));
    if ($type === 'faculty') {
        if ($d = db_value('SELECT name FROM departments WHERE hod_faculty_id = ? LIMIT 1', [$id])) {
            return "$name is the Head of $d. Assign another HOD first, or mark the faculty inactive.";
        }
        $subjects = (int) db_value('SELECT COUNT(*) FROM faculty_subjects WHERE faculty_id = ?', [$id]);
        $periods = (int) db_value('SELECT COUNT(*) FROM timetables WHERE faculty_id = ?', [$id]);
        if ($subjects || $periods) {
            return "$name is assigned to $subjects subject(s) and $periods timetable period(s). Reassign them or mark the faculty resigned/inactive instead.";
        }
    }
    return null;
}

/** Cleans HR records that belong to a deleted employee (no FK on these polymorphic tables). */
function hr_after_delete_person(string $type, int $id, array $row): void
{
    foreach (db_all('SELECT file_path FROM employee_documents WHERE employee_type = ? AND employee_id = ?', [$type, $id]) as $doc) {
        delete_upload($doc['file_path']);
    }
    db_exec('DELETE FROM employee_documents WHERE employee_type = ? AND employee_id = ?', [$type, $id]);
    db_exec('DELETE FROM employee_leaves WHERE employee_type = ? AND employee_id = ?', [$type, $id]);
    if (!empty($row['user_id'])) {
        db_exec("UPDATE users SET status = 'inactive', " . ($type === 'faculty' ? 'faculty_id' : 'staff_id') . ' = NULL WHERE id = ?', [(int) $row['user_id']]);
    }
}

/* ------------------------------------------------------------------
 * Login accounts
 * ------------------------------------------------------------------ */

function hr_random_password(): string
{
    $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '@#$%&*!'];
    $chars = [];
    foreach ($sets as $s) {
        $chars[] = $s[random_int(0, strlen($s) - 1)];
    }
    $all = implode('', $sets);
    while (count($chars) < 12) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    shuffle($chars);
    return implode('', $chars);
}

/** Suggests a free username from an email / name. */
function hr_suggest_username(array $person): string
{
    $base = strtolower(preg_replace('/[^a-z0-9.]/i', '', strstr((string) ($person['email'] ?? ''), '@', true) ?: ($person['first_name'] . '.' . ($person['last_name'] ?? ''))));
    $base = trim($base, '.') ?: 'employee';
    $base = mb_substr($base, 0, 50);
    $u = $base;
    $n = 1;
    while ((int) db_value('SELECT COUNT(*) FROM users WHERE username = ?', [$u])) {
        $u = $base . (++$n);
    }
    return $u;
}

/**
 * Creates a users row (+ role) linked to the employee and emails the credentials.
 * Faculty always get the "Faculty" role; staff get $opts['role_id'] or the "Staff" role.
 * @return array{user_id:int, username:string, password:string, email:string, emailed:bool, role:string}
 */
function hr_create_account(string $type, int $id, array $opts = []): array
{
    $p = hr_person($type, $id);
    if (!$p) {
        throw new CrudException('Employee not found.');
    }
    if (!empty($p['user_id']) && db_value('SELECT id FROM users WHERE id = ?', [(int) $p['user_id']])) {
        throw new CrudException($p['full_name'] . ' already has a login account.');
    }
    $email = strtolower(trim((string) ($p['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new CrudValidationException(['email' => 'A valid email address is required to create a login account.'], 'Add an email address to this profile first.');
    }
    if ((int) db_value('SELECT COUNT(*) FROM users WHERE email = ?', [$email])) {
        throw new CrudValidationException(['email' => 'A user account with this email already exists.'], 'A user account with this email already exists.');
    }
    $username = trim((string) ($opts['username'] ?? ''));
    if ($username === '') {
        $username = hr_suggest_username($p);
    } elseif (!preg_match('/^[a-zA-Z0-9._\-]{3,60}$/', $username)) {
        throw new CrudValidationException(['login_username' => 'Username may contain letters, numbers, dots, dashes and underscores (3-60).']);
    } elseif ((int) db_value('SELECT COUNT(*) FROM users WHERE username = ?', [$username])) {
        throw new CrudValidationException(['login_username' => 'This username is already taken.']);
    }
    if ($type === 'faculty') {
        $role = db_row("SELECT id, name FROM roles WHERE slug = 'faculty'");
    } elseif (!empty($opts['role_id'])) {
        $role = db_row('SELECT id, name FROM roles WHERE id = ? AND is_super = 0', [(int) $opts['role_id']]);
        if (!$role) {
            throw new CrudValidationException(['login_role_id' => 'Select a valid role.']);
        }
    } else {
        $role = db_row("SELECT id, name FROM roles WHERE slug = 'staff'");
    }
    if (!$role) {
        throw new CrudException('The login role is not configured. Create it under Roles & Permissions first.');
    }
    $password = hr_random_password();
    $userId = db_transaction(function () use ($p, $type, $id, $email, $username, $password, $role) {
        $uid = db_insert('users', [
            'name' => mb_substr($p['full_name'], 0, 150), 'username' => $username, 'email' => $email, 'phone' => $p['phone'] ?? null,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'designation' => $p['designation'] ?? null,
            'department_id' => $p['department_id'] ?? null, $type === 'faculty' ? 'faculty_id' : 'staff_id' => $id,
            'status' => 'active', 'must_change_password' => 1, 'created_by' => user_id(),
        ]);
        db_insert('user_roles', ['user_id' => $uid, 'role_id' => (int) $role['id']]);
        db_update($type, ['user_id' => $uid], 'id = ?', [$id]);
        return $uid;
    });
    $loginUrl = absolute_url('admin/');
    $vars = ['name' => $p['full_name'], 'username' => $username, 'password' => $password, 'email' => $email, 'login_url' => $loginUrl, 'role' => $role['name']];
    $tpl = db_value("SELECT code FROM email_templates WHERE code IN ('account_created', 'user_credentials', 'welcome_user') AND status = 'active' ORDER BY FIELD(code, 'account_created', 'user_credentials', 'welcome_user') LIMIT 1");
    $opts2 = ['related_type' => $type, 'related_id' => $id];
    if ($tpl) {
        $emailed = send_template_mail((string) $tpl, $email, $vars, $opts2);
    } else {
        $html = '<p>Dear ' . e($p['full_name']) . ',</p><p>Your ' . e(institute_name()) . ' SmartCampus account has been created with the <strong>' . e($role['name']) . '</strong> role.</p>'
            . '<table cellpadding="6" style="border:1px solid #e2e8f0;border-radius:8px;margin:12px 0"><tr><td>Login URL</td><td><a href="' . e($loginUrl) . '">' . e($loginUrl) . '</a></td></tr>'
            . '<tr><td>Username</td><td><strong>' . e($username) . '</strong></td></tr><tr><td>Temporary password</td><td><strong>' . e($password) . '</strong></td></tr></table>'
            . '<p>You will be asked to set a new password when you sign in for the first time. Do not share these credentials.</p>';
        $emailed = send_mail($email, 'Your ' . institute_name() . ' SmartCampus login', $html, $opts2);
    }
    log_activity('create', $type, $id, 'Created login account "' . $username . '" (' . $role['name'] . ') for ' . $p['full_name'] . ($emailed ? '; credentials emailed' : ''));
    return ['user_id' => $userId, 'username' => $username, 'password' => $password, 'email' => $email, 'emailed' => $emailed, 'role' => $role['name']];
}

/* ------------------------------------------------------------------
 * Leave policy, calendar & working days
 * ------------------------------------------------------------------ */

function hr_leave_types(bool $activeOnly = true, ?string $for = null): array
{
    $where = [];
    $args = [];
    if ($activeOnly) {
        $where[] = "status = 'active'";
    }
    if ($for) {
        $where[] = "applies_to IN ('all', ?)";
        $args[] = $for;
    }
    $rows = db_all('SELECT * FROM leave_types' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY sort_order, name', $args);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['annual_quota'] = (float) $r['annual_quota'];
        $r['is_paid'] = (bool) $r['is_paid'];
        $r['max_consecutive'] = $r['max_consecutive'] !== null ? (int) $r['max_consecutive'] : null;
        $r['sort_order'] = (int) $r['sort_order'];
    }
    return $rows;
}

function hr_leave_type(string $code): ?array
{
    foreach (hr_leave_types(false) as $t) {
        if ($t['code'] === $code) {
            return $t;
        }
    }
    return null;
}

/** Inline option source for leave type selects/filters. */
function hr_leave_type_source(): array
{
    return ['table' => 'leave_types lt', 'value' => 'lt.code', 'label' => 'lt.name', 'sub' => "IF(lt.annual_quota > 0, CONCAT(lt.annual_quota + 0, ' days / session'), 'No fixed quota')",
        'where' => "lt.status = 'active'", 'order' => 'lt.sort_order, lt.name', 'search' => ['lt.name', 'lt.code']];
}

/** Start/end of the academic session used for balances (current session by default). */
function hr_session_window(?int $sessionId = null): array
{
    $s = $sessionId ? db_row('SELECT * FROM academic_sessions WHERE id = ?', [$sessionId]) : null;
    $s = $s ?: current_session();
    $start = $s['start_date'] ?? (date('n') >= 7 ? date('Y') . '-07-01' : (date('Y') - 1) . '-07-01');
    $end = $s['end_date'] ?? date('Y-m-d', strtotime($start . ' +1 year -1 day'));
    return ['id' => $s['id'] ?? null, 'name' => $s['name'] ?? '', 'start' => $start, 'end' => $end];
}

/** Weekly off days as PHP date('w') numbers (0 = Sunday). Setting hr_weekly_off = "0" or "0,6". */
function hr_weekly_offs(): array
{
    $v = (string) setting('hr_weekly_off', '0');
    return array_values(array_unique(array_filter(array_map('intval', explode(',', $v)), fn ($d) => $d >= 0 && $d <= 6)));
}

/** Holidays (type holiday/vacation) overlapping a range: ['Y-m-d' => title]. */
function hr_holidays_between(string $from, string $to): array
{
    $out = [];
    $rows = db_all("SELECT title, holiday_date, COALESCE(end_date, holiday_date) AS end_date FROM holidays
                    WHERE type IN ('holiday', 'vacation') AND holiday_date <= ? AND COALESCE(end_date, holiday_date) >= ?", [$to, $from]);
    foreach ($rows as $h) {
        $d = max($h['holiday_date'], $from);
        $end = min($h['end_date'], $to);
        while ($d <= $end) {
            $out[$d] = $h['title'];
            $d = date('Y-m-d', strtotime($d . ' +1 day'));
        }
    }
    ksort($out);
    return $out;
}

/**
 * Working days in a range (weekly offs and holidays excluded). Half day only for single-day leaves.
 * @return array{days: float, calendar_days: int, weekly_offs: int, holidays: array<int, array{date:string,title:string}>}
 */
function hr_working_days(string $from, string $to, bool $halfDay = false): array
{
    $calendar = 0;
    $offCount = 0;
    $holidays = hr_holidays_between($from, $to);
    $offs = hr_weekly_offs();
    $working = 0;
    $d = $from;
    $guard = 0;
    while ($d <= $to && $guard++ < 800) {
        $calendar++;
        if (in_array((int) date('w', strtotime($d)), $offs, true)) {
            $offCount++;
        } elseif (!isset($holidays[$d])) {
            $working++;
        }
        $d = date('Y-m-d', strtotime($d . ' +1 day'));
    }
    $days = (float) $working;
    if ($halfDay && $from === $to && $working === 1) {
        $days = 0.5;
    }
    $hol = [];
    foreach ($holidays as $date => $title) {
        $hol[] = ['date' => $date, 'title' => $title];
    }
    return ['days' => $days, 'calendar_days' => $calendar, 'weekly_offs' => $offCount, 'holidays' => $hol];
}

/**
 * Leave balance per type for an employee in an academic session.
 * @return array<int, array{code:string,name:string,color:?string,quota:float,used:float,pending:float,available:?float,is_paid:bool,unlimited:bool}>
 */
function hr_leave_balance(string $type, int $id, ?int $sessionId = null): array
{
    $w = hr_session_window($sessionId);
    $sums = [];
    foreach (db_all("SELECT leave_type, status, SUM(days) AS days FROM employee_leaves
                     WHERE employee_type = ? AND employee_id = ? AND status IN ('approved', 'pending') AND from_date BETWEEN ? AND ?
                     GROUP BY leave_type, status", [$type, $id, $w['start'], $w['end']]) as $r) {
        $sums[$r['leave_type']][$r['status']] = (float) $r['days'];
    }
    $gender = (string) db_value('SELECT gender FROM ' . hr_type($type) . ' WHERE id = ?', [$id]);
    $out = [];
    foreach (hr_leave_types(true, $type) as $t) {
        $used = $sums[$t['code']]['approved'] ?? 0.0;
        $pending = $sums[$t['code']]['pending'] ?? 0.0;
        // Gender-specific types (maternity / paternity) are hidden unless already used
        if (!empty($t['gender']) && $gender !== '' && $t['gender'] !== $gender && !$used && !$pending) {
            continue;
        }
        $unlimited = $t['annual_quota'] <= 0;
        $out[] = [
            'code' => $t['code'], 'name' => $t['name'], 'color' => $t['color'], 'quota' => $t['annual_quota'], 'used' => $used, 'pending' => $pending,
            'available' => $unlimited ? null : max(0, $t['annual_quota'] - $used), 'is_paid' => $t['is_paid'], 'unlimited' => $unlimited,
        ];
    }
    return $out;
}

/**
 * Business validation for a leave application (create or edit).
 * $data keys: employee_type, employee_id, leave_type, from_date, to_date, is_half_day.
 * @return array field => error
 */
function hr_validate_leave(array $data, ?int $leaveId = null): array
{
    $errors = [];
    $type = $data['employee_type'] ?? null;
    $empId = (int) ($data['employee_id'] ?? 0);
    if (!in_array($type, HR_TYPES, true) || !$empId) {
        return ['employee_ref' => 'Select the employee.'];
    }
    $person = hr_person($type, $empId);
    if (!$person) {
        return ['employee_ref' => 'Selected employee does not exist.'];
    }
    if (in_array($person['status'], ['resigned', 'retired', 'inactive'], true)) {
        $errors['employee_ref'] = $person['full_name'] . ' is ' . $person['status'] . '; leave cannot be applied.';
    }
    $lt = !empty($data['leave_type']) ? hr_leave_type((string) $data['leave_type']) : null;
    if (!$lt || $lt['status'] !== 'active') {
        $errors['leave_type'] = 'Select a valid leave type.';
    } elseif ($lt['applies_to'] !== 'all' && $lt['applies_to'] !== $type) {
        $errors['leave_type'] = $lt['name'] . ' is only available for ' . $lt['applies_to'] . '.';
    } elseif (!empty($lt['gender']) && !empty($person['gender']) && $lt['gender'] !== $person['gender']) {
        $errors['leave_type'] = $lt['name'] . ' is only available to ' . $lt['gender'] . ' employees.';
    }
    $from = $data['from_date'] ?? null;
    $to = $data['to_date'] ?? null;
    if (!$from) {
        $errors['from_date'] = 'From date is required.';
    }
    if (!$to) {
        $errors['to_date'] = 'To date is required.';
    }
    if ($errors || !$from || !$to) {
        return $errors;
    }
    if ($to < $from) {
        return ['to_date' => 'To date must be on or after the from date.'];
    }
    if ((strtotime($to) - strtotime($from)) / 86400 > 365) {
        return ['to_date' => 'A single application cannot exceed one year.'];
    }
    $w = hr_session_window();
    if ($from < date('Y-m-d', strtotime($w['start'] . ' -1 year'))) {
        $errors['from_date'] = 'Leaves older than the previous session cannot be recorded.';
    }
    $half = !empty($data['is_half_day']);
    if ($half && $from !== $to) {
        $errors['is_half_day'] = 'Half day is only possible for a single-day leave.';
    }
    $wd = hr_working_days($from, $to, $half);
    if ($wd['days'] <= 0) {
        $errors['to_date'] = 'The selected dates fall on weekly offs or holidays only.';
        return $errors;
    }
    $overlap = db_row("SELECT id, from_date, to_date, status FROM employee_leaves WHERE employee_type = ? AND employee_id = ? AND status IN ('pending', 'approved')
                       AND from_date <= ? AND to_date >= ?" . ($leaveId ? ' AND id <> ?' : '') . ' LIMIT 1',
        $leaveId ? [$type, $empId, $to, $from, $leaveId] : [$type, $empId, $to, $from]);
    if ($overlap) {
        $errors['from_date'] = sprintf('Overlaps an existing %s leave (%s to %s).', $overlap['status'], format_date($overlap['from_date']), format_date($overlap['to_date']));
    }
    if ($lt && !isset($errors['leave_type'])) {
        if ($lt['max_consecutive'] && $wd['days'] > $lt['max_consecutive']) {
            $errors['to_date'] = sprintf('%s allows at most %d working days in one application.', $lt['name'], $lt['max_consecutive']);
        }
        if ($lt['annual_quota'] > 0 && $from >= $w['start'] && $from <= $w['end']) {
            $taken = (float) db_value("SELECT COALESCE(SUM(days), 0) FROM employee_leaves WHERE employee_type = ? AND employee_id = ? AND leave_type = ?
                                       AND status IN ('pending', 'approved') AND from_date BETWEEN ? AND ?" . ($leaveId ? ' AND id <> ?' : ''),
                $leaveId ? [$type, $empId, $lt['code'], $w['start'], $w['end'], $leaveId] : [$type, $empId, $lt['code'], $w['start'], $w['end']]);
            $left = $lt['annual_quota'] - $taken;
            if ($wd['days'] > $left) {
                $errors['leave_type'] = sprintf('Insufficient %s balance: %s day(s) available, %s requested. Choose another leave type (e.g. Leave Without Pay).',
                    $lt['name'], rtrim(rtrim(number_format(max(0, $left), 1), '0'), '.'), rtrim(rtrim(number_format($wd['days'], 1), '0'), '.'));
            }
        }
    }
    return $errors;
}

/** Users who may approve leaves (faculty approve / manage permission or super admin). */
function hr_leave_approver_ids(): array
{
    return array_map('intval', db_column(
        "SELECT DISTINCT u.id FROM users u
         JOIN user_roles ur ON ur.user_id = u.id
         JOIN roles r ON r.id = ur.role_id
         LEFT JOIN role_permissions rp ON rp.role_id = r.id
         LEFT JOIN permissions p ON p.id = rp.permission_id
         WHERE u.status = 'active' AND (r.is_super = 1 OR (p.module = 'faculty' AND p.action IN ('approve', 'manage')))"
    ));
}

/** Leave row joined with the employee's name. */
function hr_leave_row(int $id): ?array
{
    $row = db_row('SELECT * FROM employee_leaves WHERE id = ?', [$id]);
    if (!$row) {
        return null;
    }
    $p = hr_person($row['employee_type'], (int) $row['employee_id']);
    $row['employee_name'] = $p['full_name'] ?? 'Employee #' . $row['employee_id'];
    $row['employee_user_id'] = $p['user_id'] ?? null;
    $lt = hr_leave_type($row['leave_type']);
    $row['leave_type_name'] = $lt['name'] ?? label_from_key($row['leave_type']);
    $row['leave_type_color'] = $lt['color'] ?? null;
    $row['is_paid'] = $lt ? (int) $lt['is_paid'] : 1;
    return $row;
}

function hr_days_label($days): string
{
    $d = (float) $days;
    return rtrim(rtrim(number_format($d, 1), '0'), '.') . ' day' . ($d == 1.0 ? '' : 's');
}

/**
 * Approve / reject / cancel a leave application.
 * @return array the updated leave row
 */
function hr_leave_decide(int $id, string $decision, ?string $remarks = null): array
{
    $leave = hr_leave_row($id);
    if (!$leave) {
        throw new CrudException('Leave application not found. It may have been deleted.');
    }
    $remarks = $remarks !== null ? mb_substr(trim($remarks), 0, 255) : null;
    $label = sprintf('%s of %s (%s to %s, %s)', $leave['leave_type_name'], $leave['employee_name'], format_date($leave['from_date']), format_date($leave['to_date']), hr_days_label($leave['days']));
    switch ($decision) {
        case 'approve':
            if ($leave['status'] !== 'pending') {
                throw new CrudException('Only pending applications can be approved (this one is ' . $leave['status'] . ').');
            }
            // Re-check balance/overlap at approval time (another leave may have been approved meanwhile).
            $errs = hr_validate_leave($leave, $id);
            if (isset($errs['leave_type']) || isset($errs['from_date'])) {
                throw new CrudException('Cannot approve: ' . ($errs['leave_type'] ?? $errs['from_date']));
            }
            $update = ['status' => 'approved', 'approved_by' => user_id(), 'approved_at' => date('Y-m-d H:i:s'), 'remarks' => $remarks ?: $leave['remarks']];
            $verb = 'Approved';
            break;
        case 'reject':
            if ($leave['status'] !== 'pending') {
                throw new CrudException('Only pending applications can be rejected (this one is ' . $leave['status'] . ').');
            }
            if (!$remarks) {
                throw new CrudValidationException(['remarks' => 'Give a reason for rejecting this application.']);
            }
            $update = ['status' => 'rejected', 'approved_by' => user_id(), 'approved_at' => date('Y-m-d H:i:s'), 'remarks' => $remarks];
            $verb = 'Rejected';
            break;
        case 'cancel':
            if (!in_array($leave['status'], ['pending', 'approved'], true)) {
                throw new CrudException('This application is already ' . $leave['status'] . '.');
            }
            if ($leave['status'] === 'approved' && $leave['to_date'] < date('Y-m-d')) {
                throw new CrudException('This leave has already been availed and cannot be cancelled.');
            }
            $update = ['status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s'), 'remarks' => $remarks ?: $leave['remarks']];
            $verb = 'Cancelled';
            break;
        default:
            throw new InvalidArgumentException('Unknown action.');
    }
    db_update('employee_leaves', $update, 'id = ?', [$id]);
    log_activity($decision === 'cancel' ? 'update' : ($decision === 'approve' ? 'approve' : 'reject'), 'leaves', $id, "$verb $label" . ($remarks ? " — $remarks" : ''));
    if (!empty($leave['employee_user_id']) && (int) $leave['employee_user_id'] !== user_id()) {
        notify((int) $leave['employee_user_id'], 'leave', 'Leave ' . strtolower($verb), "Your $label was " . strtolower($verb) . ($remarks ? ": $remarks" : '.'), 'leaves?tab=applications', $decision === 'approve' ? 'check-circle' : 'x-circle');
    }
    return hr_leave_row($id);
}

/* ------------------------------------------------------------------
 * Profile payloads
 * ------------------------------------------------------------------ */

/** Activity entries about an employee and their leaves/documents. */
function hr_activity(string $type, int $id, int $limit = 60): array
{
    $rows = db_all("SELECT a.id, a.action, a.module, a.description, a.status, a.created_at, u.name AS user_name
                    FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id
                    WHERE (a.module = ? AND a.record_id = ?)
                       OR (a.module = 'leaves' AND a.record_id REGEXP '^[0-9]+$' AND CAST(a.record_id AS UNSIGNED) IN (SELECT id FROM employee_leaves WHERE employee_type = ? AND employee_id = ?))
                       OR (a.module = 'employee_documents' AND a.record_id REGEXP '^[0-9]+$' AND CAST(a.record_id AS UNSIGNED) IN (SELECT id FROM employee_documents WHERE employee_type = ? AND employee_id = ?))
                    ORDER BY a.created_at DESC, a.id DESC LIMIT " . max(1, min(200, $limit)),
        [$type, (string) $id, $type, $id, $type, $id]);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['time_ago'] = time_ago($r['created_at']);
    }
    return $rows;
}

/**
 * Employee attendance for a month from the shared attendance tables (attendance.type = faculty|staff).
 * @return array{month:string, days: array<string, array{status:string,in_time:?string,out_time:?string,remarks:?string}>, summary: array, trend: array, has_any: bool}
 */
function hr_attendance(string $type, int $id, string $month): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    $start = $month . '-01';
    $end = date('Y-m-t', strtotime($start));
    $days = [];
    $summary = ['present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0, 'half_day' => 0];
    foreach (db_all('SELECT a.attendance_date, r.status, r.in_time, r.out_time, r.remarks FROM attendance a
                     JOIN attendance_records r ON r.attendance_id = a.id
                     WHERE a.type = ? AND r.person_type = ? AND r.person_id = ? AND a.attendance_date BETWEEN ? AND ?
                     ORDER BY a.attendance_date', [$type, $type, $id, $start, $end]) as $r) {
        $days[$r['attendance_date']] = ['status' => $r['status'], 'in_time' => $r['in_time'], 'out_time' => $r['out_time'], 'remarks' => $r['remarks']];
        if (isset($summary[$r['status']])) {
            $summary[$r['status']]++;
        }
    }
    $marked = array_sum($summary);
    $presentish = $summary['present'] + $summary['late'] + 0.5 * $summary['half_day'];
    $summary['marked'] = $marked;
    $summary['percent'] = $marked ? round($presentish / $marked * 100, 1) : null;
    // Approved leaves this month (shown on the calendar even when attendance was not marked)
    $leaveDays = [];
    foreach (db_all("SELECT from_date, to_date, leave_type FROM employee_leaves WHERE employee_type = ? AND employee_id = ? AND status = 'approved' AND from_date <= ? AND to_date >= ?",
        [$type, $id, $end, $start]) as $l) {
        $d = max($l['from_date'], $start);
        while ($d <= min($l['to_date'], $end)) {
            $leaveDays[$d] = $l['leave_type'];
            $d = date('Y-m-d', strtotime($d . ' +1 day'));
        }
    }
    // 6-month trend
    $trend = [];
    for ($i = 5; $i >= 0; $i--) {
        $m = date('Y-m', strtotime($start . " -$i month"));
        $r = db_row("SELECT SUM(r.status IN ('present', 'late')) + 0.5 * SUM(r.status = 'half_day') AS p, COUNT(*) AS n FROM attendance a
                     JOIN attendance_records r ON r.attendance_id = a.id
                     WHERE a.type = ? AND r.person_type = ? AND r.person_id = ? AND a.attendance_date BETWEEN ? AND ?",
            [$type, $type, $id, $m . '-01', date('Y-m-t', strtotime($m . '-01'))]);
        $trend[] = ['month' => $m, 'label' => date('M y', strtotime($m . '-01')), 'percent' => (int) $r['n'] ? round((float) $r['p'] / (int) $r['n'] * 100, 1) : null, 'marked' => (int) $r['n']];
    }
    $hasAny = (bool) db_value('SELECT COUNT(*) FROM attendance_records WHERE person_type = ? AND person_id = ?', [$type, $id]);
    return ['month' => $month, 'start' => $start, 'end' => $end, 'days' => (object) $days, 'leave_days' => (object) $leaveDays,
        'holidays' => (object) hr_holidays_between($start, $end), 'weekly_offs' => hr_weekly_offs(), 'summary' => $summary, 'trend' => $trend, 'has_any' => $hasAny];
}

/** Subjects assigned to a faculty member (from academics' faculty_subjects). */
function hr_faculty_subjects(int $facultyId, ?int $sessionId = null): array
{
    $args = [$facultyId];
    $where = 'fs.faculty_id = ?';
    if ($sessionId) {
        $where .= ' AND (fs.academic_session_id = ? OR fs.academic_session_id IS NULL)';
        $args[] = $sessionId;
    }
    $rows = db_all("SELECT fs.id, fs.is_primary, fs.academic_session_id, sb.id AS subject_id, sb.code, sb.name, sb.type, sb.credits, sb.semester_no, sb.hours_per_week,
                           p.id AS program_id, p.short_name AS program, sc.id AS section_id, sc.name AS section, sc.semester_no AS section_semester, ses.name AS session_name,
                           (SELECT COUNT(*) FROM timetables tt WHERE tt.faculty_id = fs.faculty_id AND tt.subject_id = fs.subject_id
                              AND (fs.section_id IS NULL OR tt.section_id = fs.section_id) AND tt.academic_session_id = fs.academic_session_id) AS weekly_periods
                    FROM faculty_subjects fs
                    JOIN subjects sb ON sb.id = fs.subject_id
                    JOIN programs p ON p.id = sb.program_id
                    LEFT JOIN sections sc ON sc.id = fs.section_id
                    LEFT JOIN academic_sessions ses ON ses.id = fs.academic_session_id
                    WHERE $where ORDER BY p.short_name, sb.semester_no, sb.code, sc.name", $args);
    foreach ($rows as &$r) {
        foreach (['id', 'subject_id', 'program_id', 'section_id', 'semester_no', 'weekly_periods'] as $k) {
            $r[$k] = $r[$k] !== null ? (int) $r[$k] : null;
        }
        $r['credits'] = (float) $r['credits'];
        $r['is_primary'] = (bool) $r['is_primary'];
    }
    return $rows;
}

/** Weekly timetable of a faculty member for a session. */
function hr_faculty_timetable(int $facultyId, ?int $sessionId = null): array
{
    $sessionId = $sessionId ?: current_session_id();
    $slots = db_all("SELECT id, name, TIME_FORMAT(start_time, '%H:%i') AS start_time, TIME_FORMAT(end_time, '%H:%i') AS end_time, is_break FROM time_slots WHERE status = 'active' ORDER BY sort_order, start_time");
    foreach ($slots as &$s) {
        $s['id'] = (int) $s['id'];
        $s['is_break'] = (bool) $s['is_break'];
    }
    unset($s);
    $periods = db_all("SELECT tt.id, tt.day_of_week, tt.time_slot_id, tt.type, tt.status, sb.code AS subject_code, sb.name AS subject_name,
                              p.short_name AS program, tt.semester_no, sc.name AS section, r.code AS room
                       FROM timetables tt
                       JOIN subjects sb ON sb.id = tt.subject_id
                       JOIN programs p ON p.id = tt.program_id
                       JOIN sections sc ON sc.id = tt.section_id
                       LEFT JOIN classrooms r ON r.id = tt.classroom_id
                       WHERE tt.faculty_id = ? AND tt.academic_session_id = ? ORDER BY tt.day_of_week, tt.time_slot_id", [$facultyId, (int) $sessionId]);
    foreach ($periods as &$p) {
        $p['id'] = (int) $p['id'];
        $p['day_of_week'] = (int) $p['day_of_week'];
        $p['time_slot_id'] = (int) $p['time_slot_id'];
        $p['semester_no'] = (int) $p['semester_no'];
    }
    return ['slots' => $slots, 'periods' => $periods, 'session_id' => $sessionId];
}

/** Loss-of-pay (unpaid leave) days per month in the current session. */
function hr_lop_by_month(string $type, int $id): array
{
    $w = hr_session_window();
    $rows = db_all("SELECT DATE_FORMAT(l.from_date, '%Y-%m') AS ym, SUM(l.days) AS days FROM employee_leaves l
                    JOIN leave_types lt ON lt.code = l.leave_type AND lt.is_paid = 0
                    WHERE l.employee_type = ? AND l.employee_id = ? AND l.status = 'approved' AND l.from_date BETWEEN ? AND ?
                    GROUP BY ym ORDER BY ym", [$type, $id, $w['start'], $w['end']]);
    return array_map(fn ($r) => ['month' => $r['ym'], 'label' => date('M Y', strtotime($r['ym'] . '-01')), 'days' => (float) $r['days']], $rows);
}

/** Everything the profile header + overview needs. */
function hr_profile(string $type, int $id): ?array
{
    $p = hr_person($type, $id);
    if (!$p) {
        return null;
    }
    $p = hr_mask_row($p);
    $p['id'] = (int) $p['id'];
    $w = hr_session_window();
    $leaveTaken = (float) db_value("SELECT COALESCE(SUM(days), 0) FROM employee_leaves WHERE employee_type = ? AND employee_id = ? AND status = 'approved' AND from_date BETWEEN ? AND ?", [$type, $id, $w['start'], $w['end']]);
    $pendingLeaves = (int) db_value("SELECT COUNT(*) FROM employee_leaves WHERE employee_type = ? AND employee_id = ? AND status = 'pending'", [$type, $id]);
    $today = date('Y-m-d');
    $current = db_row("SELECT l.*, lt.name AS leave_type_name FROM employee_leaves l LEFT JOIN leave_types lt ON lt.code = l.leave_type
                       WHERE l.employee_type = ? AND l.employee_id = ? AND l.status = 'approved' AND l.from_date <= ? AND l.to_date >= ? LIMIT 1", [$type, $id, $today, $today]);
    $upcoming = db_all("SELECT l.id, l.leave_type, lt.name AS leave_type_name, l.from_date, l.to_date, l.days, l.status FROM employee_leaves l LEFT JOIN leave_types lt ON lt.code = l.leave_type
                        WHERE l.employee_type = ? AND l.employee_id = ? AND l.status IN ('approved', 'pending') AND l.to_date >= ? ORDER BY l.from_date LIMIT 5", [$type, $id, $today]);
    $docs = db_row("SELECT COUNT(*) AS total, SUM(status = 'verified') AS verified, SUM(status = 'pending') AS pending FROM employee_documents WHERE employee_type = ? AND employee_id = ?", [$type, $id]);
    $att = db_row("SELECT COUNT(*) AS n, SUM(r.status IN ('present', 'late')) + 0.5 * SUM(r.status = 'half_day') AS p FROM attendance a JOIN attendance_records r ON r.attendance_id = a.id
                   WHERE a.type = ? AND r.person_type = ? AND r.person_id = ? AND a.attendance_date BETWEEN ? AND ?", [$type, $type, $id, $w['start'], $w['end']]);
    $account = !empty($p['user_id']) ? db_row('SELECT u.id, u.username, u.email, u.status, u.last_login_at, u.must_change_password, GROUP_CONCAT(r.name) AS roles
                                               FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id WHERE u.id = ? GROUP BY u.id', [(int) $p['user_id']]) : null;
    $stats = [
        'experience_years' => $type === 'faculty' && $p['experience_years'] !== null ? (float) $p['experience_years'] : null,
        'tenure_years' => $p['joining_date'] ? round((time() - strtotime($p['joining_date'])) / (365.25 * 86400), 1) : null,
        'leave_taken' => $leaveTaken, 'pending_leaves' => $pendingLeaves,
        'documents' => (int) ($docs['total'] ?? 0), 'documents_verified' => (int) ($docs['verified'] ?? 0), 'documents_pending' => (int) ($docs['pending'] ?? 0),
        'attendance_percent' => (int) ($att['n'] ?? 0) ? round((float) $att['p'] / (int) $att['n'] * 100, 1) : null,
    ];
    if ($type === 'faculty') {
        $sid = current_session_id();
        $stats['subjects'] = (int) db_value('SELECT COUNT(*) FROM faculty_subjects WHERE faculty_id = ? AND (academic_session_id = ? OR academic_session_id IS NULL)', [$id, $sid]);
        $stats['weekly_periods'] = (int) db_value('SELECT COUNT(*) FROM timetables WHERE faculty_id = ? AND academic_session_id = ?', [$id, $sid]);
        $stats['sections_class_teacher'] = (int) db_value('SELECT COUNT(*) FROM sections WHERE class_teacher_id = ? AND academic_session_id = ?', [$id, $sid]);
        $p['is_hod_of'] = db_value('SELECT name FROM departments WHERE hod_faculty_id = ? LIMIT 1', [$id]) ?: null;
    }
    unset($p['created_by']);
    return [
        'person' => $p, 'stats' => $stats, 'session' => $w, 'on_leave_today' => $current ?: null, 'upcoming_leaves' => $upcoming, 'account' => $account,
        'can' => ['edit' => can('faculty', 'edit'), 'delete' => can('faculty', 'delete'), 'approve' => can('faculty', 'approve'), 'create' => can('faculty', 'create'), 'sensitive' => hr_can_see_sensitive()],
        'lop' => hr_lop_by_month($type, $id),
    ];
}

/* ------------------------------------------------------------------
 * Dashboards
 * ------------------------------------------------------------------ */

/** KPI + chart data for the faculty / staff list pages. */
function hr_summary(string $type): array
{
    hr_type($type);
    $today = date('Y-m-d');
    $statuses = db_pairs("SELECT status, COUNT(*) FROM $type GROUP BY status");
    $working = (int) (($statuses['active'] ?? 0) + ($statuses['on_leave'] ?? 0));
    $onLeave = (int) db_value("SELECT COUNT(DISTINCT employee_id) FROM employee_leaves WHERE employee_type = ? AND status = 'approved' AND from_date <= ? AND to_date >= ?", [$type, $today, $today]);
    $byDept = db_all("SELECT d.id, COALESCE(d.code, 'N/A') AS code, COALESCE(d.name, 'Not assigned') AS name, COUNT(*) AS total FROM $type t
                      LEFT JOIN departments d ON d.id = t.department_id WHERE t.status IN ('active', 'on_leave') GROUP BY d.id, d.code, d.name ORDER BY total DESC");
    $byEmployment = db_pairs("SELECT employment_type, COUNT(*) FROM $type WHERE status IN ('active', 'on_leave') GROUP BY employment_type ORDER BY COUNT(*) DESC");
    $byGender = db_pairs("SELECT COALESCE(gender, 'unspecified'), COUNT(*) FROM $type WHERE status IN ('active', 'on_leave') GROUP BY COALESCE(gender, 'unspecified')");
    $w = hr_session_window();
    $joined = (int) db_value("SELECT COUNT(*) FROM $type WHERE joining_date BETWEEN ? AND ?", [$w['start'], $w['end']]);
    $pendingLeaves = (int) db_value("SELECT COUNT(*) FROM employee_leaves WHERE employee_type = ? AND status = 'pending'", [$type]);
    $noLogin = (int) db_value("SELECT COUNT(*) FROM $type WHERE status IN ('active', 'on_leave') AND user_id IS NULL");
    $out = [
        'total' => (int) array_sum($statuses), 'working' => $working, 'active' => (int) ($statuses['active'] ?? 0), 'statuses' => $statuses,
        'on_leave_today' => $onLeave, 'joined_this_session' => $joined, 'pending_leaves' => $pendingLeaves, 'without_login' => $noLogin,
        'by_department' => array_map(fn ($r) => ['id' => $r['id'] !== null ? (int) $r['id'] : null, 'code' => $r['code'], 'name' => $r['name'], 'total' => (int) $r['total']], $byDept),
        'by_employment' => array_map('intval', $byEmployment), 'by_gender' => array_map('intval', $byGender), 'session' => $w['name'],
    ];
    if ($type === 'faculty') {
        $out['phd'] = (int) db_value("SELECT COUNT(*) FROM faculty WHERE status IN ('active', 'on_leave') AND (qualification LIKE '%Ph.D%' OR qualification LIKE '%PhD%')");
        $out['avg_experience'] = round((float) db_value("SELECT AVG(experience_years) FROM faculty WHERE status IN ('active', 'on_leave') AND experience_years IS NOT NULL"), 1);
        $out['by_designation'] = array_map('intval', db_pairs("SELECT designation, COUNT(*) FROM faculty WHERE status IN ('active', 'on_leave') GROUP BY designation ORDER BY COUNT(*) DESC"));
    } else {
        $out['by_category'] = array_map('intval', db_pairs("SELECT category, COUNT(*) FROM staff WHERE status IN ('active', 'on_leave') GROUP BY category ORDER BY COUNT(*) DESC"));
        $out['categories'] = count($out['by_category']);
    }
    return $out;
}

/* ------------------------------------------------------------------
 * API route helpers (used by api/routes/faculty.php and api/routes/staff.php)
 * ------------------------------------------------------------------ */

/** Loads an employee for an API handler or responds 404. */
function hr_api_person(string $type, $id): array
{
    $p = hr_person($type, (int) $id);
    if (!$p) {
        api_error(($type === 'faculty' ? 'Faculty member' : 'Staff member') . ' not found. The record may have been deleted.', 404);
    }
    return $p;
}

/** Registers the shared employee endpoints under /{faculty|staff}/... */
function hr_register_employee_routes(string $type): void
{
    $base = '/' . $type;

    route('GET', $base . '/summary', function () use ($type) {
        api_require('faculty', 'view');
        api_ok(hr_summary($type));
    });

    route('GET', $base . '/{id:\d+}/profile', function ($p) use ($type) {
        api_require('faculty', 'view');
        hr_api_person($type, $p['id']);
        api_ok(hr_profile($type, (int) $p['id']));
    });

    route('GET', $base . '/{id:\d+}/attendance', function ($p) use ($type) {
        api_require('faculty', 'view');
        hr_api_person($type, $p['id']);
        api_ok(hr_attendance($type, (int) $p['id'], (string) ($_GET['month'] ?? date('Y-m'))));
    });

    route('GET', $base . '/{id:\d+}/activity', function ($p) use ($type) {
        api_require('faculty', 'view');
        hr_api_person($type, $p['id']);
        api_ok(hr_activity($type, (int) $p['id'], (int) ($_GET['limit'] ?? 60)));
    });

    route('GET', $base . '/{id:\d+}/balance', function ($p) use ($type) {
        api_require('faculty', 'view');
        hr_api_person($type, $p['id']);
        api_ok(['session' => hr_session_window(), 'balance' => hr_leave_balance($type, (int) $p['id'])]);
    });

    // Create a SmartCampus login for an employee who does not have one yet.
    route('POST', $base . '/{id:\d+}/account', function ($p) use ($type) {
        api_require('faculty', 'edit');
        $person = hr_api_person($type, $p['id']);
        if (in_array($person['status'], ['resigned', 'retired', 'inactive'], true)) {
            api_error('Cannot create a login for a ' . $person['status'] . ' employee.', 422);
        }
        $in = api_input();
        $account = hr_create_account($type, (int) $p['id'], ['username' => trim((string) ($in['username'] ?? '')), 'role_id' => $in['role_id'] ?? null]);
        api_ok($account, 'Login account created' . ($account['emailed'] ? ' and credentials emailed to ' . $account['email'] . '.' : '. Email could not be sent — share the credentials securely.'));
    });

    // Quick status change from the profile (active / on leave / resigned / retired / inactive)
    route('POST', $base . '/{id:\d+}/status', function ($p) use ($type) {
        api_require('faculty', 'edit');
        $person = hr_api_person($type, $p['id']);
        $v = api_validate(['status' => 'required|in:active,on_leave,resigned,retired,inactive']);
        db_update($type, ['status' => $v['status']], 'id = ?', [(int) $p['id']]);
        hr_sync_user($type, (int) $p['id']);
        log_activity('update', $type, (int) $p['id'], 'Changed status of ' . $person['full_name'] . ' from ' . label_from_key($person['status']) . ' to ' . label_from_key($v['status']));
        api_ok(['status' => $v['status']], $person['full_name'] . ' marked ' . strtolower(label_from_key($v['status'])) . '.');
    });
}
