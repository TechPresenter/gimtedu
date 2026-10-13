<?php
/**
 * Student & Faculty portal (/portal/) - authentication, session guards and account administration.
 * Used by api/routes/portal.php, the per-area route files (api/routes/portal/student.php, portal/faculty.php)
 * and the admin Portal Access endpoints (api/routes/portal-access.php).
 *
 * STUDENTS sign in with their own `student_accounts` row (username = Student ID). Their session lives in
 * $_SESSION['portal_student'] and NEVER sets $_SESSION['user_id'], so require_login() / can() / every admin and
 * /api/crud endpoint keep rejecting a student session. Sign-ins are audited in `student_login_logs`.
 *
 * FACULTY sign in with the `users` row linked to their faculty profile (users.faculty_id) through the normal
 * admin session (attempt_login / login_user), but the portal only accepts accounts linked to an active faculty row.
 *
 * Guards for portal endpoints (later units use these in api/routes/portal/{student,faculty}.php):
 *
 *   $me = portal_student_require();      // 401 when signed out / expired, 403 while a temporary password must be changed
 *   $me['student_id'];  $me['student'];  // ALWAYS scope queries by $me['student_id'] - never trust ids from the request
 *
 *   $me = portal_faculty_require();      // 401 / 403 (not a faculty account)
 *   $me['faculty_id'];  $me['user_id'];  $me['faculty'];
 *   portal_faculty_teaches($me['faculty_id'], $subjectId, $sectionId)   // ownership check for class-level actions
 */

require_once APP_ROOT . '/app/services/students.php';

const PORTAL_STUDENT_KEY = 'portal_student';
const PORTAL_STUDENT_REMEMBER = 'gimt_portal_remember';

/* ------------------------------------------------------------------
 * Settings & shared helpers
 * ------------------------------------------------------------------ */

/** Student record statuses that may use the portal (passed-out students keep access to results/certificates). */
function portal_student_login_statuses(): array
{
    return ['active', 'graduated', 'alumni'];
}

function portal_student_enabled(): bool
{
    return setting('portal_student_enabled', '1') === '1';
}

function portal_faculty_enabled(): bool
{
    return setting('portal_faculty_enabled', '1') === '1';
}

function portal_self_activation_enabled(): bool
{
    return setting('portal_self_activation', '1') === '1';
}

function portal_password_policy(): array
{
    return [
        'min_length' => (int) setting('password_min_length', 8),
        'uppercase' => setting('password_require_uppercase', '1') === '1',
        'number' => setting('password_require_number', '1') === '1',
        'special' => setting('password_require_special', '1') === '1',
    ];
}

/** Branding + configuration shared by both portals (safe to expose before sign-in). */
function portal_app_payload(): array
{
    $session = current_session();
    return [
        'institute_name' => institute_name(),
        'short_name' => setting('institute_short_name', 'GIMT'),
        'tagline' => setting('tagline', ''),
        'logo' => logo_url(),
        'logo_white' => logo_url(true),
        'base_path' => base_path(),
        'site_url' => base_url(''),
        'admin_url' => admin_url(''),
        'support_email' => setting('support_email', setting('email', '')),
        'support_phone' => setting('phone', ''),
        'academic_session' => $session['name'] ?? null,
        'session_timeout' => session_timeout_seconds(),
        'password_policy' => portal_password_policy(),
        'features' => [
            'student_portal' => portal_student_enabled(),
            'faculty_portal' => portal_faculty_enabled(),
            'self_activation' => portal_self_activation_enabled(),
        ],
    ];
}

function portal_mask_email(?string $email): ?string
{
    $email = trim((string) $email);
    if ($email === '' || !str_contains($email, '@')) {
        return null;
    }
    [$local, $domain] = explode('@', $email, 2);
    $keep = mb_substr($local, 0, min(2, mb_strlen($local)));
    return $keep . str_repeat('•', max(3, mb_strlen($local) - 2)) . '@' . $domain;
}

function portal_mask_phone(?string $phone): ?string
{
    $d = preg_replace('/\D/', '', (string) $phone);
    return strlen($d) >= 4 ? '••••••' . substr($d, -4) : null;
}

/** Readable temporary password that always satisfies the password policy. */
function portal_temp_password(): string
{
    $upper = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    $lower = 'abcdefghjkmnpqrstuvwxyz';
    $digits = '23456789';
    $special = '@#$%&*!';
    $len = max(10, (int) setting('password_min_length', 8));
    $chars = [$upper[random_int(0, strlen($upper) - 1)], $digits[random_int(0, strlen($digits) - 1)], $digits[random_int(0, strlen($digits) - 1)],
        $special[random_int(0, strlen($special) - 1)]];
    while (count($chars) < $len) {
        $pool = random_int(0, 3) === 0 ? $upper : $lower;
        $chars[] = $pool[random_int(0, strlen($pool) - 1)];
    }
    shuffle($chars);
    return implode('', $chars);
}

function portal_login_url(string $area): string
{
    return absolute_url('portal/' . ($area === 'faculty' ? 'faculty' : 'student') . '/login');
}

/** Read-only `X-Background: 1` requests (polling) must not keep an idle session alive. */
function portal_is_background_request(): bool
{
    return ($_SERVER['HTTP_X_BACKGROUND'] ?? '') === '1';
}

/** JSON 403 that tells the SPA to send the user to the change-password screen. */
function portal_password_change_required(): void
{
    json_response(['ok' => false, 'code' => 'password_change_required', 'message' => 'Please set a new password to continue. Your current password is a temporary one.',
        'errors' => (object) [], 'data' => ['code' => 'password_change_required']], 403);
}

/* ------------------------------------------------------------------
 * Student lookups
 * ------------------------------------------------------------------ */

/** Student row by Student ID (case-insensitive) or registered email. */
function portal_find_student(string $identifier): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }
    $name = students_name_sql('s');
    $sql = "SELECT s.id, s.student_uid, s.first_name, s.last_name, $name AS full_name, s.email, s.mobile, s.dob, s.status, s.photo FROM students s WHERE ";
    if (str_contains($identifier, '@')) {
        return db_row($sql . 's.email = ? LIMIT 1', [strtolower($identifier)]);
    }
    return db_row($sql . 's.student_uid = ? LIMIT 1', [strtoupper($identifier)]);
}

function portal_student_account(int $studentId): ?array
{
    return db_row('SELECT * FROM student_accounts WHERE student_id = ?', [$studentId]);
}

/** none | pending (no password yet) | active | must_change | locked | disabled */
function portal_account_state(?array $acc): string
{
    if (!$acc) {
        return 'none';
    }
    if ($acc['status'] === 'disabled') {
        return 'disabled';
    }
    if (!empty($acc['locked_until']) && strtotime($acc['locked_until']) > time()) {
        return 'locked';
    }
    if (empty($acc['password_hash'])) {
        return 'pending';
    }
    return (int) $acc['must_change_password'] ? 'must_change' : 'active';
}

function portal_student_log(?int $studentId, string $identifier, string $status, string $reason = ''): void
{
    try {
        db_insert('student_login_logs', [
            'student_id' => $studentId, 'identifier' => mb_substr($identifier, 0, 190), 'status' => $status, 'reason' => $reason !== '' ? mb_substr($reason, 0, 190) : null,
            'ip_address' => PHP_SAPI === 'cli' ? '127.0.0.1' : client_ip(), 'user_agent' => PHP_SAPI === 'cli' ? 'cli' : user_agent(),
        ]);
    } catch (Throwable $e) {
        error_log('[GIMT] student login log failed: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------
 * Student session
 * ------------------------------------------------------------------ */

/**
 * Validated student session context or null. Applies the idle timeout, user-agent binding, account status,
 * student status and password-version checks (an admin reset or a password change elsewhere signs other devices out).
 */
function portal_student_session(bool $refresh = false): ?array
{
    if (!$refresh && array_key_exists('__portal_student_ctx', $GLOBALS)) {
        return $GLOBALS['__portal_student_ctx'];
    }
    $GLOBALS['__portal_student_ctx'] = null;
    $s = $_SESSION[PORTAL_STUDENT_KEY] ?? null;
    if (!is_array($s) || empty($s['account_id'])) {
        return null;
    }
    // One identity per browser session: a student sign-in wipes the session, so an admin / faculty user_id next to
    // the student key means someone signed in to the admin panel later - that newer identity wins.
    if (!empty($_SESSION['user_id'])) {
        unset($_SESSION[PORTAL_STUDENT_KEY]);
        portal_student_clear_remember();
        return null;
    }
    $expired = time() - (int) ($s['last_activity'] ?? 0) > session_timeout_seconds();
    $uaOk = hash_equals((string) ($s['ua'] ?? ''), hash('sha256', user_agent()));
    $row = $expired || !$uaOk ? null : db_row(
        'SELECT a.*, s.status AS student_status FROM student_accounts a JOIN students s ON s.id = a.student_id WHERE a.id = ?',
        [(int) $s['account_id']]
    );
    $valid = $row && $row['status'] === 'active' && !empty($row['password_hash']) && (int) $row['student_id'] === (int) $s['student_id']
        && in_array($row['student_status'], portal_student_login_statuses(), true) && (string) $row['password_changed_at'] === (string) ($s['pwd'] ?? '')
        && portal_student_enabled();
    if (!$valid) {
        unset($_SESSION[PORTAL_STUDENT_KEY]);
        $_SESSION['portal_student_expired'] = true;
        return null;
    }
    if (!portal_is_background_request()) {
        $_SESSION[PORTAL_STUDENT_KEY]['last_activity'] = time();
    }
    if (time() - (int) ($s['regenerated_at'] ?? 0) > 900) {
        session_regenerate_id(true);
        $_SESSION[PORTAL_STUDENT_KEY]['regenerated_at'] = time();
    }
    return $GLOBALS['__portal_student_ctx'] = [
        'account_id' => (int) $row['id'],
        'student_id' => (int) $row['student_id'],
        'account' => $row,
        'must_change_password' => (bool) $row['must_change_password'],
        'expires_in' => session_timeout_seconds(),
    ];
}

/** Starts a fresh student session (one identity per browser session: any admin/faculty login held by it is dropped). */
function portal_student_start_session(array $acc, array $student, bool $remember, string $via = 'password'): void
{
    if (!empty($_SESSION['user_id']) || !empty($_COOKIE[REMEMBER_COOKIE])) {
        clear_remember_token(); // this browser's admin "remember me" token must not survive a switch to a student identity
    }
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION[PORTAL_STUDENT_KEY] = [
        'account_id' => (int) $acc['id'], 'student_id' => (int) $acc['student_id'], 'login_at' => time(), 'last_activity' => time(),
        'regenerated_at' => time(), 'ua' => hash('sha256', user_agent()), 'pwd' => (string) $acc['password_changed_at'],
        'previous_login' => ['at' => $acc['last_login_at'], 'ip' => $acc['last_login_ip']],
    ];
    db_exec('UPDATE student_accounts SET last_login_at = NOW(), last_login_ip = ?, failed_attempts = 0, locked_until = NULL, login_count = login_count + 1 WHERE id = ?',
        [client_ip(), (int) $acc['id']]);
    portal_student_log((int) $acc['student_id'], (string) ($student['student_uid'] ?? $acc['username']), 'success', $via === 'remember' ? 'Remember-me cookie' : ($via === 'activation' ? 'Signed in after activation' : ''));
    if ($remember) {
        portal_student_issue_remember((int) $acc['id']);
    }
    unset($GLOBALS['__portal_student_ctx']);
}

function portal_student_logout(): void
{
    $s = $_SESSION[PORTAL_STUDENT_KEY] ?? null;
    if (is_array($s) && !empty($s['student_id'])) {
        portal_student_log((int) $s['student_id'], (string) (db_value('SELECT username FROM student_accounts WHERE id = ?', [(int) $s['account_id']]) ?: ''), 'logout');
    }
    portal_student_clear_remember();
    $_SESSION = [];
    session_regenerate_id(true);
    unset($GLOBALS['__portal_student_ctx']);
}

/** Guard for student endpoints. Returns the session context or ends the request with 401 / 403. */
function portal_student_require(bool $allowMustChange = false): array
{
    $ctx = portal_student_session();
    if (!$ctx && portal_student_login_from_cookie()) {
        $ctx = portal_student_session(true);
    }
    if (!$ctx) {
        api_error('Your session has expired. Please sign in again.', 401);
    }
    if (!$allowMustChange && $ctx['must_change_password']) {
        portal_password_change_required();
    }
    if (!isset($ctx['student'])) {
        $ctx['student'] = portal_student_profile_row($ctx['student_id']);
        $GLOBALS['__portal_student_ctx'] = $ctx;
    }
    return $ctx;
}

/** Student + program / section / department names (no sensitive identifiers). */
function portal_student_profile_row(int $studentId): ?array
{
    $row = students_profile_row($studentId);
    if (!$row) {
        return null;
    }
    unset($row['created_by'], $row['created_by_name'], $row['remarks'], $row['status_reason']);
    return $row;
}

/** Boot payload for the student SPA (GET /api/portal/student/session). */
function portal_student_payload(): array
{
    $ctx = portal_student_session();
    if (!$ctx && portal_student_login_from_cookie()) {
        $ctx = portal_student_session(true);
    }
    $base = ['area' => 'student', 'authenticated' => (bool) $ctx, 'csrf_token' => csrf_token(), 'app' => portal_app_payload()];
    if (!$ctx) {
        $base['expired'] = !empty($_SESSION['portal_student_expired']);
        unset($_SESSION['portal_student_expired']);
        return $base;
    }
    $s = portal_student_profile_row($ctx['student_id']);
    $acc = $ctx['account'];
    $prev = $_SESSION[PORTAL_STUDENT_KEY]['previous_login'] ?? ['at' => null, 'ip' => null];
    return $base + [
        'student' => [
            'id' => (int) $s['id'], 'student_uid' => $s['student_uid'], 'name' => $s['full_name'], 'first_name' => $s['first_name'],
            'photo' => $s['photo'] ? upload_url($s['photo']) : null, 'email' => $s['email'], 'mobile' => $s['mobile'], 'gender' => $s['gender'],
            'program' => $s['program_full_name'], 'program_short' => $s['program_name'], 'program_code' => $s['program_code'],
            'department' => $s['department_name'], 'semester' => (int) $s['current_semester'], 'section' => $s['section_name'],
            'batch' => $s['batch_name'], 'roll_no' => $s['roll_no'], 'status' => $s['status'],
        ],
        'account' => [
            'username' => $acc['username'], 'must_change_password' => (bool) $acc['must_change_password'], 'theme' => $acc['theme'] ?: null,
            'activated_at' => $acc['activated_at'], 'password_changed_at' => $acc['password_changed_at'],
            'last_login_at' => $prev['at'], 'last_login_ip' => $prev['ip'],
        ],
        'expires_in' => session_timeout_seconds(),
    ];
}

/* ------------------------------------------------------------------
 * Student remember-me (selector/validator split token, validator stored hashed, rotated on every use)
 * ------------------------------------------------------------------ */

function portal_cookie_opts(int $expires): array
{
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ['expires' => $expires, 'path' => base_path() . '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax'];
}

function portal_student_issue_remember(int $accountId): void
{
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $days = max(1, min(90, (int) setting('remember_me_days', 30)));
    db_insert('student_account_tokens', [
        'account_id' => $accountId, 'type' => 'remember', 'selector' => $selector, 'token_hash' => hash('sha256', $validator),
        'expires_at' => date('Y-m-d H:i:s', time() + $days * 86400), 'ip_address' => client_ip(), 'user_agent' => user_agent(),
    ]);
    if (PHP_SAPI !== 'cli') {
        setcookie(PORTAL_STUDENT_REMEMBER, $selector . ':' . $validator, portal_cookie_opts(time() + $days * 86400));
    }
}

function portal_student_clear_remember(): void
{
    $cookie = $_COOKIE[PORTAL_STUDENT_REMEMBER] ?? '';
    if (is_string($cookie) && str_contains($cookie, ':')) {
        [$selector] = explode(':', $cookie, 2);
        db_delete('student_account_tokens', "selector = ? AND type = 'remember'", [$selector]);
    }
    if (PHP_SAPI !== 'cli' && isset($_COOKIE[PORTAL_STUDENT_REMEMBER])) {
        setcookie(PORTAL_STUDENT_REMEMBER, '', portal_cookie_opts(time() - 3600));
    }
}

function portal_student_login_from_cookie(): bool
{
    $cookie = $_COOKIE[PORTAL_STUDENT_REMEMBER] ?? '';
    if (!is_string($cookie) || !str_contains($cookie, ':') || !portal_student_enabled()) {
        return false;
    }
    [$selector, $validator] = explode(':', $cookie, 2);
    $token = db_row("SELECT * FROM student_account_tokens WHERE selector = ? AND type = 'remember' AND expires_at > NOW()", [$selector]);
    if (!$token || !hash_equals($token['token_hash'], hash('sha256', $validator))) {
        portal_student_clear_remember();
        return false;
    }
    db_delete('student_account_tokens', 'id = ?', [$token['id']]); // rotate on every use
    $acc = db_row('SELECT * FROM student_accounts WHERE id = ?', [(int) $token['account_id']]);
    $student = $acc ? portal_find_student_by_id((int) $acc['student_id']) : null;
    if (!$acc || !$student || $acc['status'] !== 'active' || empty($acc['password_hash']) || !in_array($student['status'], portal_student_login_statuses(), true)
        || (!empty($acc['locked_until']) && strtotime($acc['locked_until']) > time())) {
        portal_student_clear_remember();
        return false;
    }
    portal_student_start_session($acc, $student, true, 'remember');
    return true;
}

function portal_find_student_by_id(int $id): ?array
{
    $name = students_name_sql('s');
    return db_row("SELECT s.id, s.student_uid, s.first_name, s.last_name, $name AS full_name, s.email, s.mobile, s.dob, s.status, s.photo FROM students s WHERE s.id = ?", [$id]);
}

/* ------------------------------------------------------------------
 * Student sign-in, activation, password reset
 * ------------------------------------------------------------------ */

/** @return array{ok:bool, status:int, message:string} */
function portal_student_attempt(string $identifier, string $password, bool $remember = false): array
{
    $ip = client_ip();
    $fail = fn (int $status, string $message) => ['ok' => false, 'status' => $status, 'message' => $message];
    if (!portal_student_enabled()) {
        return $fail(503, 'The student portal is temporarily unavailable. Please try again later.');
    }
    if (is_ip_blocked($ip)) {
        portal_student_log(null, $identifier, 'blocked', 'IP blocked');
        return $fail(403, 'Access from your network has been blocked. Please contact the institute.');
    }
    $window = max(1, (int) setting('lockout_minutes', 15));
    $ipFailures = (int) db_value("SELECT COUNT(*) FROM student_login_logs WHERE ip_address = ? AND status = 'failed' AND created_at > (NOW() - INTERVAL ? MINUTE)", [$ip, $window]);
    if ($ipFailures >= (int) setting('max_ip_attempts', 20)) {
        portal_student_log(null, $identifier, 'blocked', 'Too many attempts from IP');
        return $fail(429, "Too many sign-in attempts from your network. Please try again in $window minutes.");
    }
    $generic = 'Invalid Student ID / email or password.';
    $student = portal_find_student($identifier);
    $acc = $student ? portal_student_account((int) $student['id']) : null;
    if (!$acc || empty($acc['password_hash'])) {
        password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG'); // equalise timing
        portal_student_log($student ? (int) $student['id'] : null, $identifier, 'failed', !$student ? 'Unknown student' : (!$acc ? 'No portal account' : 'Account not activated'));
        return $fail(422, $generic);
    }
    if (!empty($acc['locked_until']) && strtotime($acc['locked_until']) > time()) {
        portal_student_log((int) $student['id'], $identifier, 'locked', 'Account locked');
        $mins = max(1, (int) ceil((strtotime($acc['locked_until']) - time()) / 60));
        return $fail(423, "Your account is temporarily locked after repeated failed attempts. Try again in $mins minute(s) or reset your password.");
    }
    if (!password_verify($password, $acc['password_hash'])) {
        $attempts = (int) $acc['failed_attempts'] + 1;
        $max = max(1, (int) setting('max_login_attempts', 5));
        $lock = $attempts >= $max ? date('Y-m-d H:i:s', time() + $window * 60) : null;
        db_update('student_accounts', ['failed_attempts' => $lock ? 0 : $attempts, 'locked_until' => $lock, 'last_failed_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$acc['id']]);
        portal_student_log((int) $student['id'], $identifier, 'failed', $lock ? 'Wrong password - account locked' : 'Wrong password');
        if ($lock) {
            return $fail(423, "Too many failed attempts. Your account is locked for $window minutes. You can reset your password to unlock it.");
        }
        $left = $max - $attempts;
        return $fail(422, $generic . ($left <= 2 ? " $left attempt(s) remaining before your account is locked." : ''));
    }
    if ($acc['status'] !== 'active') {
        portal_student_log((int) $student['id'], $identifier, 'failed', 'Account disabled');
        return $fail(403, 'Your portal access has been disabled. Please contact the academic office.');
    }
    if (!in_array($student['status'], portal_student_login_statuses(), true)) {
        portal_student_log((int) $student['id'], $identifier, 'failed', 'Student status: ' . $student['status']);
        return $fail(403, 'Your student record is not active. Please contact the academic office.');
    }
    if (password_needs_rehash($acc['password_hash'], PASSWORD_DEFAULT)) {
        db_update('student_accounts', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$acc['id']]);
    }
    portal_student_start_session($acc, $student, $remember);
    return ['ok' => true, 'status' => 200, 'message' => 'Welcome back, ' . $student['first_name'] . '!'];
}

/** Normalised Y-m-d from "2006-08-02" or "02/08/2006"; null when invalid. */
function portal_parse_dob(string $v): ?string
{
    $v = trim($v);
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $v);
        if ($d && $d->format($fmt) === $v) {
            return $d->format('Y-m-d');
        }
    }
    return null;
}

/**
 * Step 1 of first-time activation: Student ID + date of birth + registered email or mobile.
 * On success stores a 15-minute activation ticket in the session.
 * @return array{ok:bool, status:int, message:string, errors?:array, data?:array}
 */
function portal_student_activation_verify(string $uid, string $dob, string $contact): array
{
    $fail = fn (int $status, string $message, array $errors = []) => ['ok' => false, 'status' => $status, 'message' => $message, 'errors' => $errors];
    if (!portal_student_enabled()) {
        return $fail(503, 'The student portal is temporarily unavailable. Please try again later.');
    }
    if (!portal_self_activation_enabled()) {
        return $fail(403, 'Online activation is switched off. Please collect your portal login from the academic office.');
    }
    $errors = [];
    $uid = strtoupper(trim($uid));
    $contact = trim($contact);
    $dobNorm = portal_parse_dob($dob);
    if ($uid === '') {
        $errors['student_uid'] = 'Enter your Student ID.';
    }
    if (!$dobNorm) {
        $errors['dob'] = 'Enter your date of birth.';
    }
    if ($contact === '') {
        $errors['contact'] = 'Enter your registered email or mobile number.';
    }
    if ($errors) {
        return $fail(422, 'Please fill in all the details.', $errors);
    }
    if (!rate_limit('portal-activate-ip:' . client_ip(), 10, 900) || !rate_limit('portal-activate-uid:' . $uid, 5, 3600)) {
        portal_student_log(null, $uid, 'blocked', 'Activation rate limit');
        return $fail(429, 'Too many activation attempts. Please try again later or contact the academic office.');
    }
    $student = db_row('SELECT id, student_uid, first_name, last_name, email, mobile, whatsapp, dob, status FROM students WHERE student_uid = ?', [$uid]);
    $match = false;
    if ($student && $student['dob'] === $dobNorm) {
        if (str_contains($contact, '@')) {
            $match = $student['email'] && strtolower(trim($student['email'])) === strtolower($contact);
        } else {
            $key = students_mobile_key($contact);
            $match = strlen($key) >= 10 && ($key === students_mobile_key($student['mobile']) || ($student['whatsapp'] && $key === students_mobile_key($student['whatsapp'])));
        }
    }
    if (!$match) {
        portal_student_log($student ? (int) $student['id'] : null, $uid, 'failed', 'Activation: details did not match');
        return $fail(422, "The details you entered don't match our records. Check your Student ID, date of birth and registered email / mobile, or contact the academic office.");
    }
    if (!in_array($student['status'], portal_student_login_statuses(), true)) {
        portal_student_log((int) $student['id'], $uid, 'failed', 'Activation: student status ' . $student['status']);
        return $fail(403, 'Your student record is not active. Please contact the academic office.');
    }
    $acc = portal_student_account((int) $student['id']);
    if ($acc && $acc['status'] === 'disabled') {
        portal_student_log((int) $student['id'], $uid, 'failed', 'Activation: account disabled');
        return $fail(403, 'Your portal access has been disabled. Please contact the academic office.');
    }
    if ($acc && !empty($acc['password_hash']) && !empty($acc['activated_at']) && !(int) $acc['must_change_password']) {
        return $fail(409, 'Your portal account is already active. Sign in with your Student ID and password, or use "Forgot password" to set a new one.');
    }
    $_SESSION['portal_activation'] = ['student_id' => (int) $student['id'], 'expires' => time() + 900];
    return ['ok' => true, 'status' => 200, 'message' => 'Details verified. Now choose your password.', 'data' => [
        'first_name' => $student['first_name'], 'student_uid' => $student['student_uid'], 'email_masked' => portal_mask_email($student['email']),
        'expires_in' => 900,
    ]];
}

/** Step 2 of activation: create/activate the account with the student's own password and sign them in. */
function portal_student_activate(string $password, string $confirmation): array
{
    $ticket = $_SESSION['portal_activation'] ?? null;
    if (!is_array($ticket) || ($ticket['expires'] ?? 0) < time()) {
        unset($_SESSION['portal_activation']);
        return ['ok' => false, 'status' => 410, 'message' => 'Your verification has expired. Please verify your details again.', 'errors' => []];
    }
    if ($err = password_policy_error($password)) {
        return ['ok' => false, 'status' => 422, 'message' => $err, 'errors' => ['password' => $err]];
    }
    if ($password !== $confirmation) {
        return ['ok' => false, 'status' => 422, 'message' => 'Passwords do not match.', 'errors' => ['password_confirmation' => 'Passwords do not match.']];
    }
    $student = portal_find_student_by_id((int) $ticket['student_id']);
    if (!$student || !in_array($student['status'], portal_student_login_statuses(), true)) {
        return ['ok' => false, 'status' => 403, 'message' => 'Your student record is not active. Please contact the academic office.', 'errors' => []];
    }
    $now = date('Y-m-d H:i:s');
    $acc = portal_student_account((int) $student['id']);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($acc) {
        if ($acc['status'] === 'disabled') {
            return ['ok' => false, 'status' => 403, 'message' => 'Your portal access has been disabled. Please contact the academic office.', 'errors' => []];
        }
        db_update('student_accounts', ['password_hash' => $hash, 'must_change_password' => 0, 'failed_attempts' => 0, 'locked_until' => null, 'activated_at' => $now,
            'activation_method' => 'self', 'password_changed_at' => $now, 'reset_selector' => null, 'reset_token_hash' => null, 'reset_expires_at' => null], 'id = ?', [$acc['id']]);
        db_exec("DELETE FROM student_account_tokens WHERE account_id = ? AND type = 'remember'", [$acc['id']]);
    } else {
        db_insert('student_accounts', ['student_id' => (int) $student['id'], 'username' => portal_free_username($student['student_uid']), 'password_hash' => $hash,
            'status' => 'active', 'must_change_password' => 0, 'activated_at' => $now, 'activation_method' => 'self', 'password_changed_at' => $now]);
    }
    unset($_SESSION['portal_activation']);
    $acc = portal_student_account((int) $student['id']);
    portal_student_log((int) $student['id'], $student['student_uid'], 'activated', 'Self activation');
    log_activity('activate', 'portal', $student['id'], 'Student ' . $student['full_name'] . ' (' . $student['student_uid'] . ') activated their student portal account');
    portal_student_start_session($acc, $student, false, 'activation');
    return ['ok' => true, 'status' => 200, 'message' => 'Your account is ready. Welcome, ' . $student['first_name'] . '!', 'errors' => []];
}

function portal_free_username(string $uid): string
{
    $base = mb_substr(strtoupper($uid), 0, 36);
    $u = $base;
    $n = 1;
    while ((int) db_value('SELECT COUNT(*) FROM student_accounts WHERE username = ?', [$u])) {
        $u = $base . '-' . (++$n);
    }
    return $u;
}

/** Emails a reset link when the identifier matches a student with a portal account and an email on file. Always silent. */
function portal_student_forgot(string $identifier): void
{
    $student = portal_find_student($identifier);
    $acc = $student ? portal_student_account((int) $student['id']) : null;
    if (!$student || !$acc) {
        portal_student_log($student ? (int) $student['id'] : null, $identifier, 'failed', $student ? 'Reset requested: no portal account' : 'Reset requested: unknown student');
        return;
    }
    if ($acc['status'] !== 'active' || !in_array($student['status'], portal_student_login_statuses(), true)) {
        portal_student_log((int) $student['id'], $identifier, 'failed', 'Reset requested: account disabled / student inactive');
        return;
    }
    if (!filter_var((string) $student['email'], FILTER_VALIDATE_EMAIL)) {
        portal_student_log((int) $student['id'], $identifier, 'failed', 'Reset requested: no email on file');
        return;
    }
    $selector = bin2hex(random_bytes(9));
    $token = bin2hex(random_bytes(32));
    db_update('student_accounts', ['reset_selector' => $selector, 'reset_token_hash' => hash('sha256', $token), 'reset_expires_at' => date('Y-m-d H:i:s', time() + 3600)], 'id = ?', [$acc['id']]);
    $link = absolute_url('portal/student/reset?selector=' . $selector . '&token=' . $token);
    $html = '<p>Dear ' . e($student['full_name']) . ',</p><p>We received a request to reset the password of your <strong>' . e(institute_name()) . '</strong> student portal account ('
        . e($student['student_uid']) . '). Click the button below to choose a new password. The link is valid for 60 minutes and can be used once.</p>'
        . '<p><a href="' . e($link) . '" style="display:inline-block;background:#D03E16;color:#fff;padding:11px 20px;border-radius:999px;text-decoration:none;font-weight:600">Reset my password</a></p>'
        . '<p style="font-size:13px;color:#64748b">Button not working? Copy this link into your browser:<br>' . e($link) . '</p>'
        . '<p style="font-size:13px;color:#64748b">If you did not request this, you can ignore this email - your password will not change. The institute never asks for your password by phone, SMS or email.</p>';
    send_mail($student['email'], 'Reset your ' . setting('institute_short_name', 'GIMT') . ' student portal password', $html, ['related_type' => 'student', 'related_id' => $student['id']]);
    portal_student_log((int) $student['id'], $identifier, 'reset', 'Reset link emailed');
}

function portal_student_reset_row(string $selector, string $token): ?array
{
    if ($selector === '' || $token === '') {
        return null;
    }
    $row = db_row('SELECT * FROM student_accounts WHERE reset_selector = ? AND reset_expires_at > NOW()', [$selector]);
    if (!$row || !$row['reset_token_hash'] || !hash_equals($row['reset_token_hash'], hash('sha256', $token))) {
        return null;
    }
    return $row;
}

/** Sets a new password on an account, clears lockout + reset token and signs other devices out. */
function portal_student_set_password(int $accountId, string $password, array $extra = []): string
{
    $now = date('Y-m-d H:i:s');
    db_update('student_accounts', $extra + [
        'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'password_changed_at' => $now, 'failed_attempts' => 0, 'locked_until' => null,
        'reset_selector' => null, 'reset_token_hash' => null, 'reset_expires_at' => null,
    ], 'id = ?', [$accountId]);
    db_exec("DELETE FROM student_account_tokens WHERE account_id = ? AND type = 'remember'", [$accountId]);
    return $now;
}

/* ------------------------------------------------------------------
 * Student accounts - administration (Portal Access screen)
 * ------------------------------------------------------------------ */

function portal_student_credentials_mail(array $student, string $username, string $password, bool $reset): bool
{
    if (!filter_var((string) $student['email'], FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $url = portal_login_url('student');
    $html = '<p>Dear ' . e($student['full_name']) . ',</p><p>' . ($reset ? 'The password of your student portal account has been reset by the institute.' : 'Your <strong>' . e(institute_name()) . '</strong> student portal account is ready.')
        . ' Use the details below to sign in. You will be asked to choose your own password straight away.</p>'
        . '<table cellpadding="7" style="border:1px solid #EDE8E2;border-radius:10px;margin:12px 0;border-collapse:separate"><tr><td style="color:#64748b">Portal</td><td><a href="' . e($url) . '">' . e($url) . '</a></td></tr>'
        . '<tr><td style="color:#64748b">Student ID</td><td><strong>' . e($username) . '</strong></td></tr><tr><td style="color:#64748b">Temporary password</td><td><strong>' . e($password) . '</strong></td></tr></table>'
        . '<p style="font-size:13px;color:#64748b">Keep these details private. The institute never asks for your password by phone, SMS or email.</p>';
    return send_mail($student['email'], 'Your ' . setting('institute_short_name', 'GIMT') . ' student portal login', $html, ['related_type' => 'student', 'related_id' => $student['id']]);
}

/**
 * Creates a portal account with a temporary password (must be changed at first sign-in).
 * @return array{student_id:int, name:string, student_uid:string, username:string, password:string, emailed:bool, email:?string}
 */
function portal_student_generate(int $studentId, bool $sendEmail = false, string $method = 'admin', bool $log = true): array
{
    $student = portal_find_student_by_id($studentId);
    if (!$student) {
        throw new CrudException('Student not found.');
    }
    if (portal_student_account($studentId)) {
        throw new CrudException($student['full_name'] . ' already has a portal account. Use "Reset password" instead.');
    }
    $password = portal_temp_password();
    $username = portal_free_username($student['student_uid']);
    db_insert('student_accounts', [
        'student_id' => $studentId, 'username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'status' => 'active',
        'must_change_password' => 1, 'activation_method' => $method, 'password_changed_at' => date('Y-m-d H:i:s'), 'created_by' => user_id(),
    ]);
    $emailed = $sendEmail && portal_student_credentials_mail($student, $username, $password, false);
    if ($log) {
        log_activity('create', 'portal', $studentId, 'Generated student portal account for ' . $student['full_name'] . ' (' . $student['student_uid'] . ')' . ($emailed ? '; login emailed' : ''));
    }
    return ['student_id' => $studentId, 'name' => $student['full_name'], 'student_uid' => $student['student_uid'], 'username' => $username, 'password' => $password,
        'emailed' => $emailed, 'email' => portal_mask_email($student['email'])];
}

/** New temporary password for an existing account (unlocks it and signs the student out everywhere). */
function portal_student_admin_reset(int $studentId, bool $sendEmail = false): array
{
    $student = portal_find_student_by_id($studentId);
    $acc = $student ? portal_student_account($studentId) : null;
    if (!$student || !$acc) {
        throw new CrudException('This student has no portal account yet. Generate one first.');
    }
    $password = portal_temp_password();
    portal_student_set_password((int) $acc['id'], $password, ['must_change_password' => 1]);
    $emailed = $sendEmail && portal_student_credentials_mail($student, $acc['username'], $password, true);
    portal_student_log($studentId, $acc['username'], 'reset', 'Password reset by administrator');
    log_activity('update', 'portal', $studentId, 'Reset student portal password for ' . $student['full_name'] . ' (' . $student['student_uid'] . ')' . ($emailed ? '; new login emailed' : ''));
    return ['student_id' => $studentId, 'name' => $student['full_name'], 'student_uid' => $student['student_uid'], 'username' => $acc['username'], 'password' => $password,
        'emailed' => $emailed, 'email' => portal_mask_email($student['email'])];
}

/* ------------------------------------------------------------------
 * Faculty
 * ------------------------------------------------------------------ */

function portal_faculty_row(int $facultyId): ?array
{
    return db_row("SELECT f.*, TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS full_name, d.name AS department_name, d.code AS department_code
                   FROM faculty f LEFT JOIN departments d ON d.id = f.department_id WHERE f.id = ?", [$facultyId]);
}

/** Faculty statuses that may use the portal. */
function portal_faculty_login_statuses(): array
{
    return ['active', 'on_leave'];
}

/** True when a users row is linked to an active faculty profile. */
function portal_user_is_faculty(array $user): bool
{
    if (empty($user['faculty_id'])) {
        return false;
    }
    $status = db_value('SELECT status FROM faculty WHERE id = ?', [(int) $user['faculty_id']]);
    return $status !== null && in_array($status, portal_faculty_login_statuses(), true);
}

/** Validated faculty context (signed-in users row linked to an active faculty profile) or null. */
function portal_faculty_context(): ?array
{
    if (array_key_exists('__portal_faculty_ctx', $GLOBALS)) {
        return $GLOBALS['__portal_faculty_ctx'];
    }
    if (!session_is_valid() && !login_from_remember_cookie()) {
        return $GLOBALS['__portal_faculty_ctx'] = null;
    }
    $u = current_user();
    if (!$u || empty($u['faculty_id']) || !portal_faculty_enabled()) {
        return $GLOBALS['__portal_faculty_ctx'] = null;
    }
    $f = portal_faculty_row((int) $u['faculty_id']);
    if (!$f || !in_array($f['status'], portal_faculty_login_statuses(), true)) {
        return $GLOBALS['__portal_faculty_ctx'] = null;
    }
    return $GLOBALS['__portal_faculty_ctx'] = [
        'user_id' => (int) $u['id'], 'faculty_id' => (int) $f['id'], 'user' => $u, 'faculty' => $f,
        'must_change_password' => (bool) $u['must_change_password'], 'expires_in' => session_timeout_seconds(),
    ];
}

/** Guard for faculty endpoints. Returns the context or ends the request with 401 / 403. */
function portal_faculty_require(bool $allowMustChange = false): array
{
    $ctx = portal_faculty_context();
    if (!$ctx) {
        if (user_id() && current_user()) {
            api_error('This account is not linked to an active faculty profile.', 403);
        }
        api_error('Your session has expired. Please sign in again.', 401);
    }
    if (!$allowMustChange && $ctx['must_change_password']) {
        portal_password_change_required();
    }
    return $ctx;
}

/** Does this faculty member teach the subject (optionally in the section) - via faculty_subjects or the timetable? */
function portal_faculty_teaches(int $facultyId, int $subjectId, ?int $sectionId = null): bool
{
    $args = [$facultyId, $subjectId];
    $sec = '';
    if ($sectionId) {
        $sec = ' AND (section_id IS NULL OR section_id = ?)';
        $args[] = $sectionId;
    }
    if ((int) db_value('SELECT COUNT(*) FROM faculty_subjects WHERE faculty_id = ? AND subject_id = ?' . $sec, $args)) {
        return true;
    }
    $args = [$facultyId, $subjectId];
    $sec = '';
    if ($sectionId) {
        $sec = ' AND section_id = ?';
        $args[] = $sectionId;
    }
    return (bool) db_value('SELECT COUNT(*) FROM timetables WHERE faculty_id = ? AND subject_id = ?' . $sec, $args);
}

function portal_faculty_payload(): array
{
    $ctx = portal_faculty_context();
    $base = ['area' => 'faculty', 'authenticated' => (bool) $ctx, 'csrf_token' => csrf_token(), 'app' => portal_app_payload()];
    if (!$ctx) {
        $base['expired'] = !empty($_SESSION['_timeout']);
        unset($_SESSION['_timeout']);
        if (user_id() && current_user()) {
            $base['not_faculty'] = true;
        }
        return $base;
    }
    $f = $ctx['faculty'];
    $u = $ctx['user'];
    return $base + [
        'faculty' => [
            'id' => (int) $f['id'], 'employee_id' => $f['employee_id'], 'name' => $f['full_name'], 'first_name' => $f['first_name'], 'title' => $f['title'],
            'photo' => $f['photo'] ? upload_url($f['photo']) : ($u['avatar'] ? upload_url($u['avatar']) : null), 'designation' => $f['designation'],
            'department' => $f['department_name'], 'email' => $f['email'], 'phone' => $f['phone'], 'status' => $f['status'],
        ],
        'account' => [
            'username' => $u['username'], 'email' => $u['email'], 'must_change_password' => (bool) $u['must_change_password'], 'theme' => $u['theme'],
            'last_login_at' => $_SESSION['previous_login']['at'] ?? null, 'last_login_ip' => $_SESSION['previous_login']['ip'] ?? null,
            'password_changed_at' => $u['password_changed_at'], 'roles' => $u['role_names'],
        ],
        'expires_in' => session_timeout_seconds(),
    ];
}

/** @return array{ok:bool, status:int, message:string} */
function portal_faculty_attempt(string $identifier, string $password, bool $remember = false): array
{
    if (!portal_faculty_enabled()) {
        return ['ok' => false, 'status' => 503, 'message' => 'The faculty portal is temporarily unavailable. Please try again later.'];
    }
    $u = db_row('SELECT id, email, faculty_id, password_hash, status, locked_until FROM users WHERE (email = ? OR username = ?) LIMIT 1', [$identifier, $identifier]);
    $locked = $u && $u['locked_until'] && strtotime($u['locked_until']) > time();
    if ($u && !$locked && !portal_user_is_faculty($u) && $u['status'] === 'active' && password_verify($password, $u['password_hash'])) {
        // Correct password but not a faculty login: never open a session from the faculty portal.
        record_login_event((int) $u['id'], $identifier, 'failed', 'Faculty portal: not linked to an active faculty profile');
        return ['ok' => false, 'status' => 403, 'message' => 'This account is not linked to an active faculty profile. Administrators and staff sign in at the admin panel.'];
    }
    $result = attempt_login($identifier, $password, $remember);
    if (!$result['ok']) {
        $status = 422;
        if (str_starts_with($result['message'], 'Too many login attempts')) {
            $status = 429;
        } elseif (str_contains($result['message'], 'blocked')) {
            $status = 403;
        } elseif ($u && ($row = db_row('SELECT locked_until FROM users WHERE id = ?', [$u['id']])) && $row['locked_until'] && strtotime($row['locked_until']) > time()) {
            $status = 423;
        }
        return ['ok' => false, 'status' => $status, 'message' => $result['message']];
    }
    unset($_SESSION[PORTAL_STUDENT_KEY], $GLOBALS['__portal_faculty_ctx']);
    portal_student_clear_remember();
    $_SESSION['portal_area'] = 'faculty';
    return ['ok' => true, 'status' => 200, 'message' => $result['message']];
}

/** Emails a faculty-portal reset link (users / user_tokens, validated by the shared /api/auth/reset endpoints). Always silent. */
function portal_faculty_forgot(string $email): void
{
    $user = db_row("SELECT * FROM users WHERE email = ? AND status = 'active'", [$email]);
    if (!$user || !portal_user_is_faculty($user)) {
        return;
    }
    db_exec("DELETE FROM user_tokens WHERE user_id = ? AND type = 'reset'", [$user['id']]);
    $selector = bin2hex(random_bytes(9));
    $token = bin2hex(random_bytes(32));
    db_insert('user_tokens', ['user_id' => $user['id'], 'type' => 'reset', 'selector' => $selector, 'token_hash' => hash('sha256', $token),
        'expires_at' => date('Y-m-d H:i:s', time() + 3600), 'ip_address' => client_ip(), 'user_agent' => user_agent()]);
    $link = absolute_url('portal/faculty/reset?selector=' . $selector . '&token=' . $token);
    $html = '<p>Dear ' . e($user['name']) . ',</p><p>We received a request to reset the password of your <strong>' . e(institute_name()) . '</strong> faculty portal account. '
        . 'Click the button below to choose a new password. The link is valid for 60 minutes and can be used once.</p>'
        . '<p><a href="' . e($link) . '" style="display:inline-block;background:#0B2A5B;color:#fff;padding:11px 20px;border-radius:999px;text-decoration:none;font-weight:600">Reset my password</a></p>'
        . '<p style="font-size:13px;color:#64748b">Button not working? Copy this link into your browser:<br>' . e($link) . '</p>'
        . '<p style="font-size:13px;color:#64748b">If you did not request this, you can ignore this email - your password will not change.</p>';
    send_mail($user['email'], 'Reset your ' . setting('institute_short_name', 'GIMT') . ' faculty portal password', $html, ['related_type' => 'user', 'related_id' => $user['id']]);
    log_activity('password_reset_requested', 'auth', $user['id'], 'Faculty portal password reset requested for ' . $user['email']);
}

function portal_faculty_credentials_mail(array $faculty, string $email, string $username, string $password, bool $reset): bool
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $url = portal_login_url('faculty');
    $html = '<p>Dear ' . e($faculty['full_name']) . ',</p><p>' . ($reset ? 'The password of your faculty portal account has been reset by the institute.' : 'Your <strong>' . e(institute_name()) . '</strong> faculty portal account is ready.')
        . ' Use the details below to sign in. You will be asked to choose your own password straight away.</p>'
        . '<table cellpadding="7" style="border:1px solid #EDE8E2;border-radius:10px;margin:12px 0;border-collapse:separate"><tr><td style="color:#64748b">Faculty portal</td><td><a href="' . e($url) . '">' . e($url) . '</a></td></tr>'
        . '<tr><td style="color:#64748b">Username</td><td><strong>' . e($username) . '</strong> (or ' . e($email) . ')</td></tr><tr><td style="color:#64748b">Temporary password</td><td><strong>' . e($password) . '</strong></td></tr></table>'
        . '<p style="font-size:13px;color:#64748b">Keep these details private.</p>';
    return send_mail($email, 'Your ' . setting('institute_short_name', 'GIMT') . ' faculty portal login', $html, ['related_type' => 'faculty', 'related_id' => $faculty['id']]);
}

/** Creates a users row with the Faculty role, linked to the faculty profile, with a temporary password. */
function portal_faculty_create_login(int $facultyId, ?string $username = null, bool $sendEmail = false): array
{
    $f = portal_faculty_row($facultyId);
    if (!$f) {
        throw new CrudException('Faculty member not found.');
    }
    if (!empty($f['user_id']) && db_value('SELECT id FROM users WHERE id = ?', [(int) $f['user_id']])) {
        throw new CrudException($f['full_name'] . ' already has a login account.');
    }
    $email = strtolower(trim((string) $f['email']));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new CrudValidationException(['email' => 'A valid email address is required.'], 'Add a valid email address to the faculty profile first.');
    }
    if ((int) db_value('SELECT COUNT(*) FROM users WHERE email = ?', [$email])) {
        throw new CrudValidationException(['email' => 'A user account with this email already exists.'], 'A user account with this email already exists - use "Link existing user" instead.');
    }
    $username = trim((string) $username);
    if ($username === '') {
        $base = trim(strtolower(preg_replace('/[^a-z0-9.]/i', '', (string) strstr($email, '@', true))), '.') ?: 'faculty';
        $username = $base;
        $n = 1;
        while ((int) db_value('SELECT COUNT(*) FROM users WHERE username = ?', [$username])) {
            $username = $base . (++$n);
        }
    } elseif (!preg_match('/^[a-zA-Z0-9._\-]{3,60}$/', $username)) {
        throw new CrudValidationException(['username' => 'Use 3-60 letters, numbers, dots, dashes or underscores.']);
    } elseif ((int) db_value('SELECT COUNT(*) FROM users WHERE username = ?', [$username])) {
        throw new CrudValidationException(['username' => 'This username is already taken.']);
    }
    $role = db_row("SELECT id, name FROM roles WHERE slug = 'faculty'");
    if (!$role) {
        throw new CrudException('The "Faculty" role is missing. Create it under Roles & Permissions first.');
    }
    $password = portal_temp_password();
    $userId = db_transaction(function () use ($f, $email, $username, $password, $role, $facultyId) {
        $uid = db_insert('users', [
            'name' => mb_substr($f['full_name'], 0, 150), 'username' => $username, 'email' => $email, 'phone' => $f['phone'],
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'designation' => $f['designation'], 'department_id' => $f['department_id'],
            'faculty_id' => $facultyId, 'status' => 'active', 'must_change_password' => 1, 'password_changed_at' => date('Y-m-d H:i:s'), 'created_by' => user_id(),
        ]);
        db_insert('user_roles', ['user_id' => $uid, 'role_id' => (int) $role['id']]);
        db_update('faculty', ['user_id' => $uid], 'id = ?', [$facultyId]);
        return $uid;
    });
    $emailed = $sendEmail && portal_faculty_credentials_mail($f, $email, $username, $password, false);
    log_activity('create', 'portal', $facultyId, 'Created faculty portal login "' . $username . '" for ' . $f['full_name'] . ($emailed ? '; login emailed' : ''));
    return ['faculty_id' => $facultyId, 'user_id' => $userId, 'name' => $f['full_name'], 'username' => $username, 'email' => $email, 'password' => $password, 'emailed' => $emailed];
}

/** Links an existing users row to a faculty profile (adds the Faculty role when missing). */
function portal_faculty_link_user(int $facultyId, int $userId): array
{
    $f = portal_faculty_row($facultyId);
    $u = db_row('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$f || !$u) {
        throw new CrudException('Faculty member or user not found.');
    }
    if (!empty($f['user_id']) && (int) $f['user_id'] !== $userId && db_value('SELECT id FROM users WHERE id = ?', [(int) $f['user_id']])) {
        throw new CrudException($f['full_name'] . ' is already linked to another login account.');
    }
    if (!empty($u['faculty_id']) && (int) $u['faculty_id'] !== $facultyId) {
        throw new CrudException('User "' . $u['username'] . '" is already linked to another faculty profile.');
    }
    if (is_super_admin($userId)) {
        throw new CrudException('Super admin accounts cannot be linked to a faculty profile.');
    }
    db_transaction(function () use ($facultyId, $userId) {
        db_update('users', ['faculty_id' => $facultyId], 'id = ?', [$userId]);
        db_update('faculty', ['user_id' => $userId], 'id = ?', [$facultyId]);
        $role = db_value("SELECT id FROM roles WHERE slug = 'faculty'");
        if ($role && !(int) db_value('SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND role_id = ?', [$userId, (int) $role])) {
            db_insert('user_roles', ['user_id' => $userId, 'role_id' => (int) $role]);
        }
    });
    log_activity('update', 'portal', $facultyId, 'Linked user "' . $u['username'] . '" to faculty ' . $f['full_name']);
    return ['faculty_id' => $facultyId, 'user_id' => $userId, 'username' => $u['username']];
}

/** Linked users row of a faculty profile or a CrudException. */
function portal_faculty_user(int $facultyId): array
{
    $f = portal_faculty_row($facultyId);
    if (!$f) {
        throw new CrudException('Faculty member not found.');
    }
    $u = $f['user_id'] ? db_row('SELECT * FROM users WHERE id = ?', [(int) $f['user_id']]) : null;
    if (!$u) {
        throw new CrudException($f['full_name'] . ' has no login account yet. Create or link one first.');
    }
    return [$f, $u];
}

function portal_faculty_admin_reset(int $facultyId, bool $sendEmail = false): array
{
    [$f, $u] = portal_faculty_user($facultyId);
    if (is_super_admin((int) $u['id'])) {
        throw new CrudException('Super admin passwords cannot be reset from this screen.');
    }
    $password = portal_temp_password();
    db_update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'password_changed_at' => date('Y-m-d H:i:s'), 'must_change_password' => 1,
        'failed_attempts' => 0, 'locked_until' => null], 'id = ?', [$u['id']]);
    db_exec("DELETE FROM user_tokens WHERE user_id = ? AND type IN ('remember', 'reset')", [$u['id']]);
    $emailed = $sendEmail && portal_faculty_credentials_mail($f, $u['email'], $u['username'], $password, true);
    log_activity('update', 'portal', $facultyId, 'Reset faculty portal password for ' . $f['full_name'] . ' (' . $u['username'] . ')' . ($emailed ? '; new login emailed' : ''));
    return ['faculty_id' => $facultyId, 'user_id' => (int) $u['id'], 'name' => $f['full_name'], 'username' => $u['username'], 'email' => $u['email'], 'password' => $password, 'emailed' => $emailed];
}

/* ------------------------------------------------------------------
 * Notices for the portals (audience-scoped, published and not expired)
 * ------------------------------------------------------------------ */

/**
 * @param string $for 'student' | 'faculty'
 * @param array  $scope student: department_id, program_id, semester ; faculty: department_id
 */
function portal_notice_where(string $for, array $scope): array
{
    $where = "n.status = 'published' AND (n.publish_date IS NULL OR n.publish_date <= NOW()) AND (n.expiry_date IS NULL OR n.expiry_date > NOW())";
    if ($for === 'student') {
        $where .= " AND (n.audience IN ('all', 'students')
                     OR (n.audience = 'department' AND (n.department_id IS NULL OR n.department_id = ?))
                     OR (n.audience = 'course' AND (n.program_id IS NULL OR n.program_id = ?))
                     OR (n.audience = 'semester' AND (n.program_id IS NULL OR n.program_id = ?) AND (n.semester_no IS NULL OR n.semester_no = ?)))";
        return [$where, [(int) ($scope['department_id'] ?? 0), (int) ($scope['program_id'] ?? 0), (int) ($scope['program_id'] ?? 0), (int) ($scope['semester'] ?? 0)]];
    }
    $where .= " AND (n.audience IN ('all', 'faculty') OR (n.audience = 'department' AND (n.department_id IS NULL OR n.department_id = ?)))";
    return [$where, [(int) ($scope['department_id'] ?? 0)]];
}

function portal_notices(string $for, array $scope, int $limit = 10, int $offset = 0, string $q = '', string $category = ''): array
{
    [$where, $args] = portal_notice_where($for, $scope);
    if ($q !== '') {
        $where .= ' AND n.title LIKE ?';
        $args[] = '%' . $q . '%';
    }
    if ($category !== '') {
        $where .= ' AND n.category = ?';
        $args[] = $category;
    }
    $total = (int) db_value("SELECT COUNT(*) FROM notices n WHERE $where", $args);
    $rows = db_all("SELECT n.id, n.title, n.slug, n.category, n.audience, n.publish_date, n.expiry_date, n.is_pinned, n.show_on_website, n.attachment, n.description, n.created_at
                    FROM notices n WHERE $where ORDER BY n.is_pinned DESC, COALESCE(n.publish_date, n.created_at) DESC, n.id DESC LIMIT " . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset), $args);
    $items = array_map(fn ($n) => portal_notice_item($n, false), $rows);
    return ['items' => $items, 'total' => $total];
}

function portal_notice_find(string $for, array $scope, int $id): ?array
{
    [$where, $args] = portal_notice_where($for, $scope);
    $args[] = $id;
    $n = db_row("SELECT n.* FROM notices n WHERE $where AND n.id = ?", $args);
    return $n ? portal_notice_item($n, true) : null;
}

function portal_notice_item(array $n, bool $full): array
{
    $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/li|/h[1-6])[^>]*>#i', ' $0', (string) $n['description'])), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $item = [
        'id' => (int) $n['id'], 'title' => $n['title'], 'category' => $n['category'], 'audience' => $n['audience'],
        'date' => $n['publish_date'] ?: $n['created_at'], 'expiry_date' => $n['expiry_date'], 'is_pinned' => (bool) $n['is_pinned'],
        'excerpt' => mb_substr($text, 0, 180) . (mb_strlen($text) > 180 ? '…' : ''),
        'attachment' => $n['attachment'] ? upload_url($n['attachment']) : null,
        'url' => !empty($n['show_on_website']) && $n['slug'] ? base_url('notices/' . $n['slug']) : null,
    ];
    if ($full) {
        $item['html'] = sanitize_html((string) $n['description']);
    }
    return $item;
}
