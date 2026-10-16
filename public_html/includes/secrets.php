<?php
/**
 * Encrypted secret settings (payment gateway secrets, AI API keys ...).
 *
 * Secrets entered in the admin panel are stored encrypted in `website_settings` (key prefix "secret_").
 * The encryption key comes from APP_KEY in pwf-config.php when defined (recommended: 40+ random characters).
 * Without APP_KEY (or with one shorter than 16 characters) the key is derived from the database credentials in the
 * configuration file. Then a database dump alone does not reveal a secret only while the database password is long and
 * random (the password could otherwise be guessed offline), and changing the database password, user, name or host makes
 * the saved secrets unreadable (they must be entered again). The admin pages warn about this (secret_key_notice()).
 * A constant defined in pwf-config.php always wins over the stored value (config_or_secret()).
 *
 * Secrets are never printed back: the admin panel shows only "saved · ends with 1a2b".
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** 32-byte key + key id ('a' = APP_KEY, 'd' = derived from DB credentials). */
function secret_key(string $id = ''): array
{
    if (($id === '' || $id === 'a') && defined('APP_KEY') && strlen((string) APP_KEY) >= 16) {
        return ['a', hash('sha256', 'pwf-secret-v1|' . APP_KEY, true)];
    }
    if ($id === 'a') {
        return ['a', ''];   // value was encrypted with an APP_KEY that is no longer configured
    }
    return ['d', hash('sha256', 'pwf-secret-v1|' . DB_PASS . '|' . DB_USER . '|' . DB_NAME . '|' . DB_HOST, true)];
}

/**
 * Strength of the encryption key: ['level' => ok|short|derived, 'message' => text for administrators].
 * ok = APP_KEY with 32+ characters; short = APP_KEY with 16-31 characters; derived = no usable APP_KEY.
 */
function secret_key_status(): array
{
    $len = defined('APP_KEY') ? strlen((string) APP_KEY) : 0;
    if ($len >= 32) {
        return ['level' => 'ok', 'message' => 'Saved keys are encrypted with APP_KEY from pwf-config.php.'];
    }
    if ($len >= 16) {
        return ['level' => 'short', 'message' => 'APP_KEY in pwf-config.php has only ' . $len . ' characters. Use 40 or more random letters and digits.'];
    }
    return ['level' => 'derived', 'message' => ($len > 0 ? 'APP_KEY in pwf-config.php is too short (' . $len . ' characters) and is not used. ' : 'APP_KEY is not set in pwf-config.php. ')
        . 'Saved keys are encrypted with a key made from the database login, so they become unreadable if the database password changes, and a database backup is only as safe as that password.'];
}

/** Admin warning shown where secrets are entered ('' when APP_KEY is fine). */
function secret_key_notice(): string
{
    $status = secret_key_status();
    if ($status['level'] === 'ok') {
        return '';
    }
    $fix = $status['level'] === 'derived'
        ? ' Recommended: add <code>define(\'APP_KEY\', \'40+ random letters and digits\');</code> to pwf-config.php <strong>before</strong> you save keys here, never change it afterwards, and then enter the keys again.'
        : ' Replacing APP_KEY makes the keys saved so far unreadable, so enter them again afterwards.';
    return '<div class="alert alert-warning" role="note">' . icon('alert-triangle') . '<div><strong>Encryption key</strong> · ' . e($status['message']) . $fix . '</div></div>';
}

function secret_encrypt(string $plain): string
{
    [$id, $key] = secret_key();
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . $id . ':s:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . $id . ':g:' . base64_encode($iv . $tag . $cipher);
}

/** Decrypt; '' when the value cannot be decrypted (wrong key, tampered). */
function secret_decrypt(string $stored): string
{
    if (!preg_match('/^v1:([ad]):([sg]):([A-Za-z0-9+\/=]+)$/', $stored, $m)) {
        return '';
    }
    [, $key] = secret_key($m[1]);
    $raw = base64_decode($m[3], true);
    if ($key === '' || $raw === false) {
        return '';
    }
    if ($m[2] === 's') {
        if (!function_exists('sodium_crypto_secretbox_open') || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
        return $plain === false ? '' : $plain;
    }
    if (strlen($raw) <= 28) {
        return '';
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}

/** Decrypted secret stored from the admin panel ('' if not set). $name without the "secret_" prefix. */
function secret_setting(string $name): string
{
    $stored = setting('secret_' . $name);
    return $stored === '' ? '' : secret_decrypt($stored);
}

/** Store (or with '' remove) a secret. */
function save_secret_setting(string $name, string $value): void
{
    save_setting('secret_' . $name, $value === '' ? '' : secret_encrypt($value));
}

/** True when a secret is stored but can no longer be decrypted (APP_KEY or DB credentials changed). */
function secret_unreadable(string $name): bool
{
    $stored = setting('secret_' . $name);
    return $stored !== '' && secret_decrypt($stored) === '';
}

/**
 * Value of a configuration constant when it is defined and not empty in pwf-config.php / config.php,
 * otherwise the encrypted admin setting $name.
 */
function config_or_secret(string $constant, string $name): string
{
    if (defined($constant) && (string) constant($constant) !== '') {
        return (string) constant($constant);
    }
    return secret_setting($name);
}

/** Where a secret comes from: 'config', 'admin' or '' (not set). */
function secret_source(string $constant, string $name): string
{
    if ($constant !== '' && defined($constant) && (string) constant($constant) !== '') {
        return 'config';
    }
    return secret_setting($name) !== '' ? 'admin' : '';
}

/** Safe description of a secret for the admin panel, e.g. "Saved · ends with …x9Qa" (never the value). */
function secret_hint(string $constant, string $name): string
{
    $source = secret_source($constant, $name);
    if ($source === '') {
        return secret_unreadable($name) ? 'Saved value cannot be read (the encryption key changed) - please enter it again' : 'Not set';
    }
    $value = config_or_secret($constant, $name);
    $tail = mb_strlen($value) > 8 ? ' · ends with …' . mb_substr($value, -4) : '';
    return ($source === 'config' ? 'Set in pwf-config.php' : 'Saved (encrypted)') . $tail;
}
