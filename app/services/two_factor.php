<?php
/**
 * Two-factor authentication for staff accounts (admin panel + faculty portal): authenticator-app codes (TOTP, RFC 6238 —
 * Google Authenticator, Microsoft Authenticator, Authy …) and one-time recovery codes. Student-portal accounts are not
 * affected.
 *
 *   - Secret: 20 random bytes, base32 without padding, stored encrypted (chatbot_encrypt(), "enc:" prefix) in
 *     users.two_factor_secret; never returned after enrolment and never logged.
 *   - Codes: SHA1, 6 digits, 30-second steps, ±1 step accepted, constant-time compare, a step is accepted only once
 *     (users.two_factor_last_step).
 *   - Recovery codes: 10 codes XXXX-XXXX (A–Z and 2–9 without O / I), password_hash() values in users.two_factor_recovery
 *     (JSON), shown once, each usable once.
 *   - Policy: setting two_factor_enabled = 1 → every staff account must use two-factor authentication (accounts without
 *     it are taken through the setup at sign-in). Anyone can turn it on for their own account at any time.
 *
 * Sign-in: attempt_login() checks the password, then tf_login_challenge() decides whether a second step is needed. If so
 * nobody is signed in yet: the pending sign-in lives in $_SESSION['tf_pending'] (user, remember flag, area, expiry, wrong
 * code count) until /api/auth/2fa/verify or /api/auth/2fa/setup/confirm finishes it with tf_finish_login().
 */
require_once APP_ROOT . '/app/services/chatbot.php';

const TF_DIGITS = 6;
const TF_PERIOD = 30;
const TF_VERIFY_TTL = 300;      // seconds to enter the code after the password
const TF_SETUP_TTL = 600;       // seconds to finish the forced setup at sign-in
const TF_MAX_WRONG = 5;         // wrong codes before the pending sign-in is dropped
const TF_RECOVERY_COUNT = 10;
const TF_RECOVERY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

/* ------------------------------------------------------------------ TOTP */

function tf_base32_encode(string $bin): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

function tf_base32_decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[\s=]+/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) {
        $pos = strpos($alphabet, $c);
        if ($pos === false) {
            return '';
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

/** The code of one 30-second step for a raw (binary) secret. */
function tf_totp(string $secretBin, int $step, int $digits = TF_DIGITS): string
{
    $hash = hash_hmac('sha1', pack('J', $step), $secretBin, true);
    $o = ord($hash[19]) & 0x0f;
    $n = ((ord($hash[$o]) & 0x7f) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
    return str_pad((string) ($n % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/**
 * Step number the code matches (current step ±1), or null. Steps up to $lastStep were already used and never match again.
 */
function tf_match_code(string $secretB32, string $code, ?int $lastStep = null, ?int $now = null): ?int
{
    $code = preg_replace('/\D+/', '', $code);
    $bin = tf_base32_decode($secretB32);
    if (strlen($code) !== TF_DIGITS || $bin === '') {
        return null;
    }
    $current = intdiv($now ?? time(), TF_PERIOD);
    $found = null;
    foreach ([-1, 0, 1] as $d) {
        $step = $current + $d;
        // compare every candidate (constant time), keep the newest match
        if (hash_equals(tf_totp($bin, $step), $code) && ($lastStep === null || $step > $lastStep)) {
            $found = $step;
        }
    }
    return $found;
}

function tf_new_secret(): string
{
    return tf_base32_encode(random_bytes(20));
}

function tf_issuer(): string
{
    $name = trim((string) setting('institute_short_name', '')) ?: 'GIMT';
    return trim(str_replace(':', '', $name)) ?: 'GIMT';
}

function tf_account(array $user): string
{
    return trim((string) ($user['email'] ?? '')) ?: (string) ($user['username'] ?? 'user');
}

function tf_otpauth_uri(string $secretB32, array $user): string
{
    $issuer = tf_issuer();
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode(tf_account($user)) . '?secret=' . $secretB32 . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=' . TF_DIGITS . '&period=' . TF_PERIOD;
}

/** {secret, otpauth_uri, issuer, account} for an enrolment screen. */
function tf_setup_payload(string $secretB32, array $user): array
{
    return ['secret' => $secretB32, 'otpauth_uri' => tf_otpauth_uri($secretB32, $user), 'issuer' => tf_issuer(), 'account' => tf_account($user)];
}

/** a***n@gimt.ac.in */
function tf_mask_account(array $user): string
{
    $email = (string) ($user['email'] ?? '');
    if (!str_contains($email, '@')) {
        $u = (string) ($user['username'] ?? '');
        return mb_strlen($u) > 2 ? mb_substr($u, 0, 1) . '***' . mb_substr($u, -1) : $u;
    }
    [$local, $domain] = explode('@', $email, 2);
    return (mb_strlen($local) > 2 ? mb_substr($local, 0, 1) . '***' . mb_substr($local, -1) : mb_substr($local, 0, 1) . '***') . '@' . $domain;
}

/* ------------------------------------------------------------------ account state */

/** Policy: every staff account must use two-factor authentication. */
function tf_required(): bool
{
    return (string) setting('two_factor_enabled', '0') === '1';
}

function tf_user_enabled(array $user): bool
{
    return (int) ($user['two_factor_enabled'] ?? 0) === 1 && trim((string) ($user['two_factor_secret'] ?? '')) !== '';
}

function tf_user_secret(array $user): string
{
    return chatbot_decrypt((string) ($user['two_factor_secret'] ?? ''));
}

function tf_recovery_hashes(array $user): array
{
    $list = json_decode((string) ($user['two_factor_recovery'] ?? ''), true);
    return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
}

function tf_recovery_left(array $user): int
{
    return tf_user_enabled($user) ? count(tf_recovery_hashes($user)) : 0;
}

/** @return array{0: string[], 1: string} plain codes (shown once) and the JSON of their hashes */
function tf_recovery_generate(): array
{
    $codes = [];
    $max = strlen(TF_RECOVERY_ALPHABET) - 1;
    while (count($codes) < TF_RECOVERY_COUNT) {
        $c = '';
        for ($i = 0; $i < 8; $i++) {
            $c .= TF_RECOVERY_ALPHABET[random_int(0, $max)];
        }
        $codes[substr($c, 0, 4) . '-' . substr($c, 4)] = true;
    }
    $codes = array_keys($codes);
    return [$codes, json_encode(array_map(fn ($c) => password_hash($c, PASSWORD_DEFAULT), $codes))];
}

function tf_normalize_recovery(string $code): string
{
    $raw = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    return strlen($raw) === 8 ? substr($raw, 0, 4) . '-' . substr($raw, 4) : '';
}

/** Turn two-factor authentication on for a user (secret already confirmed with a code of $step). Returns the recovery codes. */
function tf_enable(int $userId, string $secretB32, int $step): array
{
    [$codes, $json] = tf_recovery_generate();
    db_update('users', ['two_factor_enabled' => 1, 'two_factor_secret' => chatbot_encrypt($secretB32), 'two_factor_recovery' => $json,
        'two_factor_enabled_at' => date('Y-m-d H:i:s'), 'two_factor_last_step' => $step], 'id = ?', [$userId]);
    return $codes;
}

function tf_disable(int $userId): void
{
    db_update('users', ['two_factor_enabled' => 0, 'two_factor_secret' => null, 'two_factor_recovery' => null, 'two_factor_enabled_at' => null, 'two_factor_last_step' => null], 'id = ?', [$userId]);
}

/**
 * Check a code for an enrolled user and consume it: an authenticator code moves two_factor_last_step forward (a code is
 * accepted once), a recovery code is removed. Returns ['ok' => bool, 'method' => totp|recovery, 'left' => recovery codes left].
 */
function tf_check_code(array $user, string $code, string $method = 'totp'): array
{
    $uid = (int) $user['id'];
    if ($method === 'recovery') {
        $norm = tf_normalize_recovery($code);
        if ($norm === '') {
            return ['ok' => false, 'method' => 'recovery'];
        }
        return db_transaction(function () use ($uid, $norm) {
            $row = db_row('SELECT two_factor_recovery FROM users WHERE id = ? FOR UPDATE', [$uid]);
            $hashes = tf_recovery_hashes($row ?: []);
            foreach ($hashes as $i => $h) {
                if (password_verify($norm, $h)) {
                    unset($hashes[$i]);
                    db_update('users', ['two_factor_recovery' => json_encode(array_values($hashes))], 'id = ?', [$uid]);
                    return ['ok' => true, 'method' => 'recovery', 'left' => count($hashes)];
                }
            }
            return ['ok' => false, 'method' => 'recovery'];
        });
    }
    $last = $user['two_factor_last_step'] !== null && $user['two_factor_last_step'] !== '' ? (int) $user['two_factor_last_step'] : null;
    $step = tf_match_code(tf_user_secret($user), $code, $last);
    if ($step === null) {
        return ['ok' => false, 'method' => 'totp'];
    }
    // atomic: two parallel requests with the same code cannot both win
    $won = db_exec('UPDATE users SET two_factor_last_step = ? WHERE id = ? AND (two_factor_last_step IS NULL OR two_factor_last_step < ?)', [$step, $uid, $step]) === 1;
    return ['ok' => $won, 'method' => 'totp', 'left' => count(tf_recovery_hashes($user))];
}

/* ------------------------------------------------------------------ pending sign-in */

/**
 * Called by attempt_login() after a correct password: the second step the account needs, or null to sign in straight away.
 * Stores the pending sign-in in the session (nobody is signed in yet).
 */
function tf_login_challenge(array $user, bool $remember, string $area = 'admin'): ?array
{
    $enabled = tf_user_enabled($user);
    if (!$enabled && !tf_required()) {
        unset($_SESSION['tf_pending']);
        return null;
    }
    $step = $enabled ? 'verify' : 'setup';
    $ttl = $step === 'verify' ? TF_VERIFY_TTL : TF_SETUP_TTL;
    $_SESSION['tf_pending'] = ['user_id' => (int) $user['id'], 'remember' => $remember, 'area' => $area, 'step' => $step, 'expires' => time() + $ttl,
        'wrong' => 0, 'ua' => hash('sha256', user_agent()), 'setup_secret' => null];
    return $step === 'verify'
        ? ['step' => 'verify', 'methods' => ['totp', 'recovery'], 'expires_in' => $ttl, 'account' => tf_mask_account($user)]
        : ['step' => 'setup', 'expires_in' => $ttl, 'account' => tf_mask_account($user)];
}

/** The pending sign-in of this browser and its user, or null (none, expired, other browser, account no longer usable). */
function tf_pending(): ?array
{
    $p = $_SESSION['tf_pending'] ?? null;
    if (!is_array($p) || ($p['expires'] ?? 0) < time() || !hash_equals((string) ($p['ua'] ?? ''), hash('sha256', user_agent()))) {
        unset($_SESSION['tf_pending']);
        return null;
    }
    $user = db_row('SELECT * FROM users WHERE id = ?', [(int) $p['user_id']]);
    if (!$user || $user['status'] !== 'active' || ($user['locked_until'] && strtotime($user['locked_until']) > time())) {
        unset($_SESSION['tf_pending']);
        return null;
    }
    return ['pending' => $p, 'user' => $user];
}

function tf_pending_clear(): void
{
    unset($_SESSION['tf_pending']);
}

/**
 * A wrong code during sign-in: counts towards the account lockout like a wrong password; after TF_MAX_WRONG the pending
 * sign-in is dropped. Returns true when the sign-in has to start again.
 */
function tf_pending_wrong(array $user, string $what): bool
{
    $_SESSION['tf_pending']['wrong'] = (int) ($_SESSION['tf_pending']['wrong'] ?? 0) + 1;
    $attempts = (int) $user['failed_attempts'] + 1;
    $max = max(1, (int) setting('max_login_attempts', 5));
    $window = (int) setting('lockout_minutes', 15);
    $lock = $attempts >= $max ? date('Y-m-d H:i:s', time() + $window * 60) : null;
    db_update('users', ['failed_attempts' => $lock ? 0 : $attempts, 'locked_until' => $lock, 'last_failed_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
    record_login_event((int) $user['id'], (string) $user['email'], 'failed', $lock ? "Wrong $what - account locked" : "Wrong $what");
    log_activity('two_factor_failed', 'auth', (int) $user['id'], 'Wrong ' . $what . ' at sign-in', 'failed');
    if ($lock) {
        notify('perm:security', 'security', 'Account locked', "Account {$user['email']} was locked after repeated wrong two-factor codes from " . client_ip() . '.', 'admin/security?tab=locked', 'shield-alert');
    }
    if ($lock || $_SESSION['tf_pending']['wrong'] >= TF_MAX_WRONG) {
        tf_pending_clear();
        return true;
    }
    return false;
}

/** Second step passed: sign the user in for real (new session id, remember-me, faculty-portal area). */
function tf_finish_login(array $user, array $pending, string $via): void
{
    tf_pending_clear();
    login_user($user, (bool) $pending['remember'], $via);
    if (($pending['area'] ?? 'admin') === 'faculty') {
        require_once APP_ROOT . '/app/services/portal_auth.php';
        unset($_SESSION[PORTAL_STUDENT_KEY], $GLOBALS['__portal_faculty_ctx']);
        portal_student_clear_remember();
        $_SESSION['portal_area'] = 'faculty';
    }
}

/* ------------------------------------------------------------------ profile summary */

/** Two-factor fields for /api/profile. */
function tf_profile_fields(array $user): array
{
    return ['two_factor_enabled' => tf_user_enabled($user), 'two_factor_required' => tf_required(), 'two_factor_enabled_at' => tf_user_enabled($user) ? $user['two_factor_enabled_at'] : null,
        'recovery_codes_left' => tf_recovery_left($user)];
}
