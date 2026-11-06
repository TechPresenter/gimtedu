<?php
/**
 * Public website forms (unit site_forms) — business logic shared by api/routes/public.php (AJAX / JSON) and
 * apply.php (server-rendered no-JS fallback of the online application).
 *
 *   pf_submit_contact($in)              -> contact_messages        (Contact page "Send Us a Message")
 *   pf_submit_enquiry($in)              -> enquiries               (counselling / quick enquiry forms)
 *   pf_submit_newsletter($in)           -> newsletter_subscribers  (footer + any newsletter form)
 *   pf_submit_event_registration($in)  -> event_registrations     (event pages)
 *   pf_submit_application($in, $files)  -> admissions + admission_documents + admission_stage_history (online application)
 *
 * Every submitter validates server-side (field errors keyed by input name), throws PublicFormException on failure,
 * stores plain text (escaped on output), notifies staff with notify(), writes log_activity() and sends an
 * acknowledgement email only when a matching email template exists (pf_send_ack()).
 *
 * Contact data (unit forms_platform, app/services/phone.php): phone numbers are validated per country and stored as
 * E.164 (+919876543210; numbers typed without a country code use the form's "<field>_country" value, else India),
 * emails are trimmed / lowercased / IDN-converted and strictly checked, addresses carry a country with per-country
 * state (India: states & UTs) and postal-code rules.
 *
 * Spam & duplicate protection (pf_guard() + pf_token_*(), applied by api/routes/public.php pf_api(), apply.php and the
 * chatbot lead route): per-IP rate_limit(), honeypot field "website", a signed one-time form token "_ft" issued when
 * the form is rendered (pf_form_token(); GET /api/public/form-token for islands) that must be at least
 * PF_MIN_FILL_SECONDS old (bots submit instantly) and is consumed on success (a resubmitted token gets the original
 * answer instead of a second row), an optional captcha (settings captcha_provider = recaptcha_v3 | turnstile | hcaptcha,
 * captcha_site_key, captcha_secret_key / env GIMT_CAPTCHA_SECRET, captcha_min_score) verified server-side, and per-form
 * dedupe windows (same phone / email within PF_DEDUPE_SECONDS -> friendly "already received", no duplicate row).
 */
require_once __DIR__ . '/admissions.php';
require_once __DIR__ . '/phone.php';
if (!function_exists('rate_limit')) {
    require_once APP_ROOT . '/app/api.php'; // rate_limit() for the website (no-JS) form handlers; only defines functions
}

/** User-facing failure of a public form: message + field errors + HTTP status (422 validation, 429 rate limit). */
class PublicFormException extends RuntimeException
{
    public array $errors;
    public int $status;

    public function __construct(string $message, array $errors = [], int $status = 422)
    {
        parent::__construct($message);
        $this->errors = $errors;
        $this->status = $status;
    }
}

const PF_FIX_FIELDS = 'Please fix the highlighted fields and try again.';
/** A form submitted sooner than this after it was rendered is treated as a bot. */
const PF_MIN_FILL_SECONDS = 2;
/** Form tokens stay valid this long (an open tab gets a fresh one automatically from the islands). */
const PF_TOKEN_TTL = 86400;
/** Same form + same phone / email within this window = the same submission. */
const PF_DEDUPE_SECONDS = 600;
/** Phone fields of the public forms (each may come with a "<field>_country" ISO2 value from the country selector). */
const PF_PHONE_FIELDS = ['phone', 'whatsapp', 'father_phone', 'mother_phone', 'guardian_phone', 'mobile', 'alternate_phone'];

/* ------------------------------------------------------------------
 * Small helpers
 * ------------------------------------------------------------------ */

/** Plain-text input: trimmed, control characters removed (newlines kept when $multiline), null when empty. */
function pf_text($value, bool $multiline = false): ?string
{
    if (!is_scalar($value)) {
        return null;
    }
    $s = (string) $value;
    $s = $multiline ? preg_replace('/[^\P{C}\n\t]/u', '', str_replace("\r\n", "\n", $s)) : preg_replace('/\p{C}+/u', ' ', $s);
    $s = trim(preg_replace($multiline ? '/[ \t]+/' : '/\s+/', ' ', (string) $s));
    return $s === '' ? null : $s;
}

/** Checkbox / consent values sent by forms ("1", "on", "true", "yes"). */
function pf_truthy($value): bool
{
    return $value === true || in_array(strtolower(trim(api_str($value))), ['1', 'on', 'true', 'yes'], true);
}

/** Collapse a free-form page/source label (e.g. "admissions-page") into a safe short slug; null when empty. */
function pf_source_label($value): ?string
{
    // not slugify(): it returns "item" for empty input, which would label every form without a source as "Item"
    $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) pf_text($value))), '-');
    return $s === '' ? null : substr($s, 0, 40);
}

/** First name for friendly messages ("Thank you, Ananya!"). */
function pf_first_name(?string $name): string
{
    $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
    return $parts[0] ?? '';
}

/** Mask an email / phone for display on public pages (acknowledgement view). */
function pf_mask_email(?string $email): string
{
    $email = (string) $email;
    if (!str_contains($email, '@')) {
        return $email;
    }
    [$user, $domain] = explode('@', $email, 2);
    return mb_substr($user, 0, 2) . str_repeat('•', max(2, min(6, mb_strlen($user) - 2))) . '@' . $domain;
}

function pf_mask_phone(?string $phone): string
{
    $d = preg_replace('/\D+/', '', (string) $phone);
    return strlen($d) >= 6 ? str_repeat('•', strlen($d) - 4) . substr($d, -4) : (string) $phone;
}

/** Send an acknowledgement email from a stored template — only when that template exists and is active. */
function pf_send_ack(string $code, ?string $to, array $vars, array $opts = []): bool
{
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    try {
        if (!db_value("SELECT COUNT(*) FROM email_templates WHERE code = ? AND status = 'active'", [$code])) {
            return false;
        }
        return send_template_mail($code, $to, $vars, $opts);
    } catch (Throwable $e) {
        log_system('error', 'Public form acknowledgement email failed', ['template' => $code, 'error' => $e->getMessage()]);
        return false;
    }
}

/** True for a plain (no-JS) browser form post, which expects a page instead of JSON. */
function pf_wants_html(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest'
        && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html')
        && !str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

/**
 * Result of a no-JS form post, kept in the session for the page the visitor returns to — rendered inline by
 * enquiry_form() (field errors under each field, typed values refilled) or sf_flash_notice().
 * $fid = form instance id (hidden "_fid" of enquiry_form()), $old = submitted values (scalars, no secrets).
 */
function pf_flash(string $form, bool $ok, string $message, array $errors = [], ?string $fid = null, array $old = []): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $keep = [];
        if (!$ok) {
            foreach ($old as $k => $v) {
                if (is_string($k) && is_scalar($v) && !in_array($k, ['_csrf', '_ft', '_fid', '_back', 'website', 'captcha_token', 'g-recaptcha-response', 'cf-turnstile-response', 'h-captcha-response'], true)
                    && strlen((string) $v) <= 2000) {
                    $keep[$k] = (string) $v;
                }
            }
        }
        $_SESSION['public_form'][$form] = ['ok' => $ok, 'message' => $message, 'errors' => $errors, 'at' => time(), 'fid' => $fid, 'old' => $keep];
    }
}

/** Take (and clear) the no-JS result of a form; with $fid only the result of that form instance is taken. */
function pf_take_flash(string $form, ?string $fid = null): ?array
{
    $f = $_SESSION['public_form'][$form] ?? null;
    if ($f && $fid !== null && !empty($f['fid']) && $f['fid'] !== $fid) {
        return null;
    }
    unset($_SESSION['public_form'][$form]);
    return $f && time() - (int) ($f['at'] ?? 0) < 600 ? $f : null;
}

/**
 * Pages that show the no-JS result of a form inline (sf_flash_notice() in includes/partials/site_forms.php, or any
 * enquiry_form() — those post a "_fid" and always qualify). "*" matches one path segment ("/events/*").
 * A plain post from any other page (footer newsletter, other units' forms) gets pf_result_page() instead.
 */
function pf_flash_pages(string $form): array
{
    return ['contact' => ['/contact'], 'enquiry' => ['/admissions'], 'event' => ['/events/*'], 'alumni' => ['/alumni']][$form] ?? [];
}

/** Does a path (with the base URL prefix) match one of pf_flash_pages()? */
function pf_flash_page_match(string $form, string $path): bool
{
    $path = rtrim($path, '/') ?: '/';
    foreach (pf_flash_pages($form) as $p) {
        $full = rtrim(base_url(ltrim($p, '/')), '/') ?: '/';
        $re = '#^' . str_replace('\*', '[^/]+', preg_quote($full, '#')) . '$#';
        if (preg_match($re, $path)) {
            return true;
        }
    }
    return false;
}

/**
 * Standalone result page for a form posted without JavaScript from a page that cannot show the result inline:
 * success / error message, field errors and a link back to the page the visitor came from.
 */
function pf_result_page(bool $ok, string $message, array $errors, string $back, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    $items = '';
    foreach ($errors as $msg) {
        $items .= '<li>' . e($msg) . '</li>';
    }
    $title = $ok ? 'Thank you!' : ([429 => 'Please wait a moment', 419 => 'Your session expired', 413 => 'Your files are too large', 500 => 'Something went wrong'][$status] ?? 'Please check your details');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>' . e($title . ' | ' . setting('institute_short_name', 'GIMT')) . '</title>'
        . '<link rel="stylesheet" href="' . e(asset('assets/css/style.css')) . '"></head>'
        . '<body class="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-12 font-sans text-slate-700">'
        . '<main class="w-full max-w-lg rounded-3xl border border-slate-100 bg-white p-8 text-center shadow-soft">'
        . '<a href="' . e(base_url('')) . '"><img src="' . e(logo_url()) . '" alt="' . e(institute_name()) . '" class="mx-auto h-14 w-auto" width="132" height="56"></a>'
        . '<span class="mx-auto mt-6 inline-flex h-14 w-14 items-center justify-center rounded-2xl ' . ($ok ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-600') . '">'
        . icon($ok ? 'circle-check' : 'circle-alert', 'h-7 w-7') . '</span>'
        . '<h1 class="mt-4 font-display text-2xl font-extrabold text-brand-900">' . e($title) . '</h1>'
        . '<p class="mt-2 text-sm leading-relaxed text-slate-600" role="' . ($ok ? 'status' : 'alert') . '">' . e($message) . '</p>'
        . ($items !== '' ? '<ul class="mx-auto mt-4 max-w-sm list-disc space-y-1 pl-5 text-left text-[13px] text-red-700">' . $items . '</ul>' : '')
        . '<div class="mt-7 flex flex-wrap justify-center gap-3"><a class="btn btn-accent" href="' . e($back) . '">' . ($ok ? 'Continue browsing' : 'Go back and try again') . icon('arrow-right', 'h-4 w-4') . '</a>'
        . '<a class="btn btn-outline" href="' . e(base_url('contact')) . '">Contact us</a></div>'
        . '</main></body></html>';
    exit;
}

/**
 * Same-site path to send a no-JS visitor back to: the form's own "_back" field (path of the page that rendered it,
 * see enquiry_form()), else the Referer when it points at this site, else $fallback.
 */
function pf_back_url(string $fallback = '/', string $anchor = '', ?string $back = null): string
{
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $target = $fallback;
    if ($back !== null && preg_match('#^/(?!/)[^\s\\\\]*$#', $back)) {
        return safe_redirect_target($back, $fallback) . $anchor;
    }
    if ($ref !== '') {
        $p = parse_url($ref);
        $host = strtolower(api_str($p['host'] ?? null));
        $self = strtolower((string) parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
        if ($host !== '' && $host === $self) {
            $target = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        }
    }
    return safe_redirect_target($target, $fallback) . $anchor;
}

/**
 * Spam protection for every public form: per-IP fixed-window rate limit, the honeypot field "website" (hidden from
 * people, filled by bots), the signed form token "_ft" (timing check; required for script posts of the protected
 * forms, see pf_token_required()) and the captcha when one is configured. Throws PublicFormException
 * (429 rate limit, 419 expired token, 422 honeypot / too fast / captcha failed).
 */
function pf_guard(string $form, array $in, int $max, int $window): void
{
    $key = 'public:' . $form . ':' . client_ip();
    if (!rate_limit($key, $max, $window)) {
        $reset = db_value('SELECT reset_at FROM rate_limits WHERE rl_key = ?', [$key]);
        $wait = $reset ? max(1, (int) ceil((strtotime((string) $reset) - time()) / 60)) : (int) ceil($window / 60);
        $GLOBALS['__pf_retry_after'] = $wait * 60;
        log_system('warning', 'Public form rate limit reached', ['form' => $form, 'ip' => client_ip()]);
        throw new PublicFormException('Too many submissions from your network. Please wait about ' . $wait . ' minute' . ($wait === 1 ? '' : 's') . ' and try again.', [], 429);
    }
    if (trim(api_str($in['website'] ?? null)) !== '') {
        log_system('warning', 'Public form submission blocked (honeypot)', ['form' => $form, 'ip' => client_ip(), 'ua' => user_agent()]);
        throw new PublicFormException('Your submission could not be accepted. Please refresh the page and try again.', [], 422);
    }
    pf_token_check($form, $in);
    pf_captcha_verify($form, $in);
}

/* ------------------------------------------------------------------
 * Form tokens: timing check + one-time use (duplicate submissions)
 * ------------------------------------------------------------------ */

function pf_token_secret(): string
{
    return hash('sha256', 'public-form-token|' . pf_ack_secret());
}

/**
 * Signed form token "<issued unix time>.<nonce>.<signature>" bound to the visitor's session (CSRF token). Put it in
 * the form as hidden "_ft" (pf_form_fields()) or fetch one with GET /api/public/form-token.
 */
function pf_form_token(): string
{
    // first form shown to this session: the timing check for plain no-JS posts that carry no token (pf_token_check())
    if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['pf_seen'])) {
        $_SESSION['pf_seen'] = time();
    }
    $ts = (string) time();
    $nonce = bin2hex(random_bytes(9));
    return $ts . '.' . $nonce . '.' . substr(hash_hmac('sha256', $ts . '.' . $nonce . '.' . csrf_token(), pf_token_secret()), 0, 32);
}

/** Hidden inputs every server-rendered public form carries: CSRF + form token (+ the honeypot). */
function pf_form_fields(): string
{
    return csrf_field() . '<input type="hidden" name="_ft" value="' . e(pf_form_token()) . '">'
        . '<input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">';
}

/** ['ts' => issued, 'nonce' => …] of a correctly signed token for this session, else null. */
function pf_token_parse($token): ?array
{
    if (!is_string($token) || !preg_match('/^(\d{9,11})\.([a-f0-9]{18})\.([a-f0-9]{32})$/', $token, $m)) {
        return null;
    }
    $sig = substr(hash_hmac('sha256', $m[1] . '.' . $m[2] . '.' . csrf_token(), pf_token_secret()), 0, 32);
    return hash_equals($sig, $m[3]) ? ['ts' => (int) $m[1], 'nonce' => $m[2]] : null;
}

/**
 * Forms whose script (XHR / fetch) posts must carry a form token — every island and enhanced form of this unit sends
 * one. Plain no-JS posts (Accept: text/html) may come without (the home page fallback and other units' plain forms),
 * and the footer newsletter (other unit's vanilla form) never needs one.
 */
function pf_token_required(string $form): bool
{
    return in_array($form, ['contact', 'enquiry', 'event', 'apply', 'alumni', 'chatbot-lead'], true) && !pf_wants_html();
}

function pf_token_value(array $in): ?string
{
    $t = $in['_ft'] ?? ($_SERVER['HTTP_X_FORM_TOKEN'] ?? null);
    return is_string($t) && $t !== '' ? $t : null;
}

/** Verify the form token (signature, age, expiry). Errors use the "_ft" key so islands can refresh and retry. */
function pf_token_check(string $form, array $in): void
{
    $raw = pf_token_value($in);
    if ($raw === null) {
        if (pf_token_required($form)) {
            throw new PublicFormException('This form has expired. Please refresh the page and try again.', ['_ft' => 'missing'], 419);
        }
        // Plain no-JS post without a token (other units' fallback forms): still apply the minimum fill time, measured
        // from the first form this session was shown (pf_form_token() runs on every page through the enquiry modal).
        $seen = (int) ($_SESSION['pf_seen'] ?? 0);
        if ($form !== 'newsletter' && $seen > 0 && time() - $seen < PF_MIN_FILL_SECONDS) {
            log_system('warning', 'Public form submitted too fast', ['form' => $form, 'ip' => client_ip(), 'seconds' => time() - $seen, 'token' => 'none']);
            throw new PublicFormException('That was very quick! Please check your details and submit the form again.', ['_ft' => 'fast:' . (PF_MIN_FILL_SECONDS - (time() - $seen))], 422);
        }
        return;
    }
    $t = pf_token_parse($raw);
    if (!$t || $t['ts'] > time() + 60 || time() - $t['ts'] > PF_TOKEN_TTL) {
        throw new PublicFormException('This form has expired. Please refresh the page and try again.', ['_ft' => 'expired'], 419);
    }
    $age = time() - $t['ts'];
    if ($age < PF_MIN_FILL_SECONDS) {
        log_system('warning', 'Public form submitted too fast', ['form' => $form, 'ip' => client_ip(), 'seconds' => $age]);
        throw new PublicFormException('That was very quick! Please check your details and submit the form again.', ['_ft' => 'fast:' . (PF_MIN_FILL_SECONDS - $age)], 422);
    }
}

/** Fingerprint of the submitted values (tokens, captcha answers and files excluded). */
function pf_token_fingerprint(array $in): string
{
    $keep = [];
    foreach ($in as $k => $v) {
        if (is_string($k) && is_scalar($v) && !in_array($k, ['_csrf', '_ft', '_fid', '_back', 'website', 'captcha_token', 'g-recaptcha-response', 'cf-turnstile-response', 'h-captcha-response'], true)) {
            $keep[$k] = trim((string) $v);
        }
    }
    ksort($keep);
    return sha1((string) json_encode($keep));
}

/**
 * Stored result of an earlier successful submission with the same one-time token and the same values (double click,
 * refresh, network retry). The same token with different values (a page restored from the back/forward cache after a
 * successful send) is refused as expired, so the island fetches a fresh token and the new submission is stored.
 */
function pf_token_replay(array $in): ?array
{
    $t = pf_token_parse(pf_token_value($in));
    if (!$t || session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    $hit = $_SESSION['pf_used'][$t['nonce']] ?? null;
    if (!is_array($hit)) {
        return null;
    }
    if (($hit['fp'] ?? '') !== pf_token_fingerprint($in)) {
        throw new PublicFormException('This form was already sent. Please refresh the page to send another one.', ['_ft' => 'used'], 419);
    }
    return $hit;
}

/** Mark the token used and remember the answer (last 30 tokens per session). */
function pf_token_consume(array $in, array $result): void
{
    $t = pf_token_parse(pf_token_value($in));
    if (!$t || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $used = (array) ($_SESSION['pf_used'] ?? []);
    $used[$t['nonce']] = ['id' => $result['id'] ?? null, 'message' => (string) ($result['message'] ?? ''), 'data' => $result['data'] ?? null, 'at' => time(),
        'fp' => pf_token_fingerprint($in)];
    $_SESSION['pf_used'] = array_slice($used, -30, null, true);
}

/* ------------------------------------------------------------------
 * Captcha (settings group "integrations", edited in Admin › SEO › Integrations)
 * ------------------------------------------------------------------ */

/** Secret key: environment variable GIMT_CAPTCHA_SECRET wins over the stored setting (never sent to browsers). */
function pf_captcha_secret(): string
{
    $env = getenv('GIMT_CAPTCHA_SECRET');
    if ($env === false || $env === '') {
        $env = $_SERVER['GIMT_CAPTCHA_SECRET'] ?? $_ENV['GIMT_CAPTCHA_SECRET'] ?? '';
    }
    if (trim((string) $env) !== '') {
        return trim((string) $env);
    }
    $stored = trim((string) setting('captcha_secret_key', ''));
    if (str_starts_with($stored, 'enc:')) {
        foreach (['settings_decrypt', 'setting_decrypt', 'seo_decrypt', 'chatbot_decrypt'] as $fn) {
            if (function_exists($fn)) {
                $plain = (string) $fn($stored);
                if ($plain !== '') {
                    return $plain;
                }
            }
        }
        if (!function_exists('chatbot_decrypt') && is_file(APP_ROOT . '/app/services/chatbot.php')) {
            require_once APP_ROOT . '/app/services/chatbot.php';
            return function_exists('chatbot_decrypt') ? (string) chatbot_decrypt($stored) : '';
        }
        return '';
    }
    return $stored;
}

/**
 * Active captcha: ['provider' => recaptcha_v3|turnstile|hcaptcha, 'siteKey' => …, 'minScore' => 0.5], or null when
 * captcha_provider is "none" or a key is missing (the forms then rely on honeypot + timing + rate limits).
 */
function pf_captcha_config(): ?array
{
    $provider = (string) setting('captcha_provider', 'none');
    // site key: environment variable GIMT_CAPTCHA_SITE_KEY wins over the stored setting (same rule as the secret and as
    // the Integrations tab, app/services/seo.php seo_integration_env())
    $site = getenv('GIMT_CAPTCHA_SITE_KEY');
    if ($site === false || trim($site) === '') {
        $site = $_SERVER['GIMT_CAPTCHA_SITE_KEY'] ?? $_ENV['GIMT_CAPTCHA_SITE_KEY'] ?? '';
    }
    $site = trim((string) $site) !== '' ? trim((string) $site) : trim((string) setting('captcha_site_key', ''));
    if (!in_array($provider, ['recaptcha_v3', 'turnstile', 'hcaptcha'], true) || $site === '' || pf_captcha_secret() === '') {
        return null;
    }
    $score = (float) setting('captcha_min_score', 0.5);
    return ['provider' => $provider, 'siteKey' => $site, 'minScore' => $score > 0 && $score <= 1 ? $score : 0.5];
}

/** Public part for the browser (no secret). */
function pf_captcha_public(): ?array
{
    $c = pf_captcha_config();
    return $c ? ['provider' => $c['provider'], 'siteKey' => $c['siteKey']] : null;
}

/** POST application/x-www-form-urlencoded over HTTPS; ['status', 'json'] (json null on failure). */
function pf_http_post_form(string $url, array $fields, int $timeout = 6): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'json' => null, 'error' => 'curl extension missing'];
    }
    $ch = curl_init($url);
    $opts = [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'GIMT-SmartCampus-Forms/1.0'];
    if (is_file(APP_ROOT . '/app/services/chatbot/http.php')) {
        require_once APP_ROOT . '/app/services/chatbot/http.php';
        if (function_exists('chatbot_ca_bundle') && ($ca = chatbot_ca_bundle())) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);
    return ['status' => $status, 'json' => is_string($body) ? json_decode($body, true) : null, 'error' => $error];
}

/**
 * Verify the captcha answer ("captcha_token", or the provider's own field name) server-side with the secret key.
 * Missing / rejected answers -> 422 with a "captcha" field error. When the provider cannot be reached the submission
 * is accepted (logged) so an outage never blocks admissions enquiries.
 */
function pf_captcha_verify(string $form, array $in): void
{
    $cfg = pf_captcha_config();
    if (!$cfg) {
        return;
    }
    $token = '';
    foreach (['captcha_token', 'g-recaptcha-response', 'cf-turnstile-response', 'h-captcha-response'] as $k) {
        if (is_string($in[$k] ?? null) && trim($in[$k]) !== '') {
            $token = trim($in[$k]);
            break;
        }
    }
    // The footer newsletter form (plain enhanced form of another unit) cannot run a captcha widget: it is checked only
    // when it sends an answer. Every lead / application form of this unit always sends one.
    if ($token === '' && $form === 'newsletter') {
        return;
    }
    $fail = 'We could not confirm that you are not a robot. Please try again' . (pf_wants_html() ? ' with JavaScript enabled, or call us.' : '.');
    if ($token === '' || strlen($token) > 4096) {
        throw new PublicFormException($fail, ['captcha' => 'Please complete the security check.'], 422);
    }
    $url = ['recaptcha_v3' => 'https://www.google.com/recaptcha/api/siteverify', 'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'hcaptcha' => 'https://api.hcaptcha.com/siteverify'][$cfg['provider']];
    $fields = ['secret' => pf_captcha_secret(), 'response' => $token, 'remoteip' => client_ip()];
    if ($cfg['provider'] === 'hcaptcha') {
        $fields['sitekey'] = $cfg['siteKey'];
    }
    $res = pf_http_post_form($url, $fields);
    $j = $res['json'];
    if (!is_array($j)) {
        log_system('warning', 'Captcha verification unavailable — submission accepted', ['form' => $form, 'provider' => $cfg['provider'], 'status' => $res['status'], 'error' => $res['error'] ?? null]);
        return;
    }
    $ok = !empty($j['success']);
    if ($ok && $cfg['provider'] === 'recaptcha_v3') {
        $ok = (float) ($j['score'] ?? 0) >= $cfg['minScore'] && (empty($j['action']) || $j['action'] === pf_captcha_action($form));
    }
    if (!$ok) {
        log_system('warning', 'Captcha rejected a public form submission', ['form' => $form, 'provider' => $cfg['provider'], 'ip' => client_ip(),
            'score' => $j['score'] ?? null, 'codes' => $j['error-codes'] ?? null]);
        throw new PublicFormException($fail, ['captcha' => 'The security check failed. Please try again.'], 422);
    }
}

/** reCAPTCHA v3 action name for a form ("enquiry", "chatbot_lead" …). */
function pf_captcha_action(string $form): string
{
    return preg_replace('/[^a-z0-9_]/', '_', strtolower($form)) ?: 'submit';
}

/* ------------------------------------------------------------------
 * Contact data: phone (E.164), email, address
 * ------------------------------------------------------------------ */

/**
 * Prepare raw input of a public form: a phone typed without "+" next to a country selector value ("phone_country"
 * = GB) is turned into an international number so validation and messages use that country; emails are normalised.
 */
function pf_prepare_input(array $in): array
{
    foreach (PF_PHONE_FIELDS as $f) {
        $v = $in[$f] ?? null;
        $country = strtoupper(trim(api_str($in[$f . '_country'] ?? null)));
        if (!is_string($v) || trim($v) === '' || $country === '' || !preg_match('/^[A-Z]{2}$/', $country) || preg_match('/^\s*[(\[]?\s*\+/', $v)) {
            continue;
        }
        $p = phone_parse($v, $country);
        if ($p['e164']) {
            $in[$f] = $p['e164'];
        } elseif (($cc = phone_calling_code($country)) !== '' && preg_match('/\d/', $v) && !preg_match('/[A-Za-z]/', $v)) {
            $in[$f] = '+' . $cc . ' ' . trim($v);
        }
    }
    return $in;
}

/** Normalised email (null when empty) — trim, lowercase, IDN domain -> ASCII. */
function pf_email($value): ?string
{
    return email_normalize(is_scalar($value) ? (string) $value : null);
}

/**
 * Shared phone check: required / per-country validity (libphonenumber metadata, same messages as the browser), and
 * normalises $value to E.164 ("+919876543210") in place. Numbers without a country code use $country, else India.
 */
function pf_check_phone(array &$errors, string $field, ?string &$value, bool $required, string $label = 'Phone number', ?string $country = null): void
{
    if ($value === null || trim($value) === '') {
        $value = null;
        if ($required && !isset($errors[$field])) {
            $errors[$field] = $label . ' is required.';
        }
        return;
    }
    $p = phone_parse($value, $country);
    if ($p['valid']) {
        $value = $p['e164'];
        return;
    }
    if (!isset($errors[$field])) {
        $errors[$field] = phone_invalid_message($p['country'] && $p['country'] !== '001' ? $p['country'] : null);
    }
}

/**
 * Validate + normalise an address block in $data (keys country, state, city, pincode — any may be absent).
 * country: ISO2 / name / alias -> canonical name (default India); India: state must be a state / UT (canonical name);
 * pincode: per-country format, required for countries that use postal codes when $postalRequired.
 * Returns the ISO2 country code.
 */
function pf_check_address(array &$errors, array &$data, array $o = []): string
{
    $countryKey = $o['country'] ?? 'country';
    $stateKey = $o['state'] ?? 'state';
    $pinKey = $o['pincode'] ?? 'pincode';
    $iso = 'IN';
    if (array_key_exists($countryKey, $data)) {
        $code = $data[$countryKey . '_code'] ?? null;
        $c = address_country($code ?: ($data[$countryKey] ?? null)) ?? (($data[$countryKey] ?? null) === null ? address_country('IN') : null);
        if (!$c) {
            $errors[$countryKey] ??= 'Select a valid country.';
        } else {
            $iso = $c['iso'];
            $data[$countryKey] = $c['name'];
        }
        unset($data[$countryKey . '_code']);
    }
    if (array_key_exists($stateKey, $data) && $data[$stateKey] !== null && !isset($errors[$stateKey])) {
        if ($iso === 'IN' && strcasecmp((string) $data[$stateKey], 'Outside India') === 0) {
            $errors[$countryKey] ??= 'Select the country you live in (you chose "Outside India" as the state).';
        } elseif ($iso === 'IN') {
            $state = address_state_normalize($data[$stateKey], 'IN');
            if ($state === null) {
                $errors[$stateKey] = 'Select a valid state or union territory.';
            } else {
                $data[$stateKey] = $state;
            }
        } elseif (strcasecmp((string) $data[$stateKey], 'Outside India') === 0) {
            $data[$stateKey] = null;
        } else {
            $data[$stateKey] = address_state_normalize($data[$stateKey], $iso);
        }
    }
    if (array_key_exists($pinKey, $data) && !isset($errors[$pinKey])) {
        $data[$pinKey] = address_postal_normalize($data[$pinKey], $iso);
        if ($data[$pinKey] === null) {
            if (!empty($o['postal_required']) && address_postal_required($iso)) {
                $errors[$pinKey] = ($o['postal_label'] ?? address_postal_label($iso)) . ' is required.';
            }
        } elseif ($err = address_postal_error($data[$pinKey], $iso, $o['postal_label'] ?? null)) {
            $errors[$pinKey] = $err;
        }
    }
    return $iso;
}

/** Location line for notes ("Noida, Uttar Pradesh, India"). */
function pf_location_text(?string $city, ?string $state, ?string $country): string
{
    return implode(', ', array_filter([$city, $state, $country], fn ($v) => $v !== null && $v !== ''));
}

/** Names: letters (any script), spaces, dots, apostrophes and hyphens. */
function pf_check_name(array &$errors, string $field, ?string $value, string $label): void
{
    if ($value !== null && !isset($errors[$field]) && !preg_match("/^\\p{L}[\\p{L}\\p{M} .'\\-]*$/u", $value)) {
        $errors[$field] = $label . ' may only contain letters, spaces, dots and hyphens.';
    }
}

/* ------------------------------------------------------------------
 * Programs & admission session
 * ------------------------------------------------------------------ */

/** Active programs shown on the website (sorted like the programs page). */
function pf_programs(): array
{
    static $rows = null;
    if ($rows === null) {
        try {
            $rows = db_all("SELECT id, name, short_name, code, slug, level, degree, category, duration_label, duration_years, intake_capacity, fee_per_year, fee_label,
                                   eligibility, overview, image, is_popular, is_featured
                            FROM programs WHERE status = 'active' AND show_on_website = 1 ORDER BY sort_order, name");
        } catch (Throwable $e) {
            $rows = [];
        }
    }
    return $rows;
}

/** Find an active website program by id, slug, short name, code or full name. */
function pf_find_program($ref): ?array
{
    $ref = trim((string) $ref);
    if ($ref === '') {
        return null;
    }
    foreach (pf_programs() as $p) {
        if ((ctype_digit($ref) && (int) $p['id'] === (int) $ref) || $p['slug'] === $ref
            || strcasecmp($p['short_name'], $ref) === 0 || strcasecmp((string) $p['code'], $ref) === 0 || strcasecmp($p['name'], $ref) === 0) {
            return $p;
        }
    }
    return null;
}

/** Options for program selects: {value, label, level, slug, category}. */
function pf_program_options(): array
{
    return array_map(fn ($p) => [
        'value' => (string) $p['id'], 'label' => $p['name'] . (pf_program_suffix($p) !== '' ? ' (' . pf_program_suffix($p) . ')' : ''),
        'short' => $p['short_name'], 'level' => $p['level'], 'slug' => $p['slug'], 'category' => $p['category'],
    ], pf_programs());
}

/** "(BBA)"-style acronym for labels, only when it adds information. */
function pf_program_suffix(array $p): string
{
    $short = api_str($p['short_name'] ?? null);
    $name = api_str($p['name'] ?? null);
    return preg_match('/^[A-Z][A-Za-z.]{1,9}$/', $short) && stripos($name, $short) === false && !str_contains($name, '(') ? $short : '';
}

/** Specialisations (courses) per program id. */
function pf_program_courses(): array
{
    $out = [];
    try {
        foreach (db_all("SELECT id, program_id, name FROM courses WHERE status = 'active' ORDER BY name") as $c) {
            $out[(int) $c['program_id']][] = ['id' => (int) $c['id'], 'name' => $c['name']];
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/** Academic session that online applications go to: the one with admissions open (current first), else the current one. */
function pf_admission_session(): array
{
    static $s = null;
    if ($s === null) {
        try {
            $s = db_row('SELECT * FROM academic_sessions WHERE admissions_open = 1 ORDER BY is_current DESC, start_date ASC LIMIT 1') ?: current_session();
        } catch (Throwable $e) {
            $s = current_session();
        }
    }
    return $s;
}

/* ------------------------------------------------------------------
 * Contact messages
 * ------------------------------------------------------------------ */

/**
 * Enquiry types offered by the contact form → stored contact_messages.enquiry_type (admin set:
 * admission|academic|placement|general|other) + a descriptive subject for the inbox.
 */
function pf_contact_types(): array
{
    return [
        'admission' => ['label' => 'Admission Enquiry', 'type' => 'admission', 'subject' => 'Admission enquiry'],
        'program' => ['label' => 'Program Information', 'type' => 'academic', 'subject' => 'Program information'],
        'scholarship' => ['label' => 'Scholarships & Fees', 'type' => 'admission', 'subject' => 'Scholarships & fee enquiry'],
        'campus_visit' => ['label' => 'Campus Visit', 'type' => 'general', 'subject' => 'Campus visit request'],
        'placement' => ['label' => 'Placements & Careers', 'type' => 'placement', 'subject' => 'Placement enquiry'],
        'student_support' => ['label' => 'Student Support', 'type' => 'academic', 'subject' => 'Student support request'],
        'general' => ['label' => 'General Enquiry', 'type' => 'general', 'subject' => 'General enquiry'],
        // raw admin values are accepted too (API clients)
        'academic' => ['label' => 'Academic', 'type' => 'academic', 'subject' => 'Academic enquiry', 'hidden' => true],
        'other' => ['label' => 'Other', 'type' => 'other', 'subject' => 'Other enquiry', 'hidden' => true],
    ];
}

/** Select options for the contact form. */
function pf_contact_type_options(): array
{
    $out = [];
    foreach (pf_contact_types() as $k => $t) {
        if (empty($t['hidden'])) {
            $out[] = ['value' => $k, 'label' => $t['label']];
        }
    }
    return $out;
}

function pf_submit_contact(array $in): array
{
    $in['enquiry_type'] = $in['enquiry_type'] ?? ($in['type'] ?? null);
    $data = [
        'name' => pf_text($in['name'] ?? null), 'email' => pf_email($in['email'] ?? null),
        'phone' => pf_text($in['phone'] ?? null), 'enquiry_type' => pf_text($in['enquiry_type']),
        'subject' => pf_text($in['subject'] ?? null), 'message' => pf_text($in['message'] ?? null, true),
    ];
    $types = pf_contact_types();
    $errors = validate($data, [
        'name' => 'required|min:2|max:150', 'email' => 'required|email|max:190', 'enquiry_type' => 'required|in:' . implode(',', array_keys($types)),
        'subject' => 'max:255', 'message' => 'required|min:10|max:2000',
    ], ['name' => 'Full Name', 'email' => 'Email Address', 'enquiry_type' => 'Enquiry Type', 'message' => 'Your Message']);
    pf_check_name($errors, 'name', $data['name'], 'Full Name');
    pf_check_phone($errors, 'phone', $data['phone'], true, 'Phone Number');
    if (!pf_truthy($in['consent'] ?? '')) {
        $errors['consent'] = 'Please accept the terms and privacy policy to send your message.';
    }
    if ($errors) {
        throw new PublicFormException(PF_FIX_FIELDS, $errors);
    }
    $type = $types[$data['enquiry_type']];
    // Dedupe window: a message from the same email or phone within 10 minutes is the same conversation — an identical
    // message is stored once, a different one is added to the earlier message (no second row, nothing lost).
    $recent = db_row('SELECT id, message FROM contact_messages WHERE (email = ? OR ' . admission_phone_sql('phone') . ' = ?) AND created_at >= ? ORDER BY id DESC LIMIT 1',
        [$data['email'], admission_phone_key($data['phone']), date('Y-m-d H:i:s', time() - PF_DEDUPE_SECONDS)]);
    if ($recent) {
        if (trim((string) $recent['message']) !== $data['message'] && !str_contains((string) $recent['message'], $data['message'])) {
            db_update('contact_messages', ['message' => mb_substr($recent['message'] . "\n\n— Added " . date('d M Y, g:i A') . " —\n" . $data['message'], 0, 65000)], 'id = ?', [(int) $recent['id']]);
            log_activity('update', 'contact_messages', (int) $recent['id'], sprintf('Website contact message from %s: follow-up added to the earlier message', $data['name']));
            return ['id' => (int) $recent['id'], 'duplicate' => true, 'message' => 'Thank you, ' . pf_first_name($data['name']) . '! We have added this to your earlier message — our team will reply within 24 hours.'];
        }
        return ['id' => (int) $recent['id'], 'duplicate' => true, 'message' => 'Thank you, ' . pf_first_name($data['name']) . '! We already have your message and will reply within 24 hours.'];
    }
    $id = db_insert('contact_messages', [
        'name' => mb_substr($data['name'], 0, 150), 'email' => $data['email'], 'phone' => mb_substr((string) $data['phone'], 0, 20),
        'enquiry_type' => $type['type'], 'subject' => mb_substr($data['subject'] ?: $type['subject'], 0, 255), 'message' => $data['message'],
        'status' => 'new', 'ip_address' => client_ip(), 'user_agent' => user_agent(),
    ]);
    contact_message_notify($id);
    log_activity('create', 'contact_messages', $id, sprintf('Website contact message from %s (%s)', $data['name'], $type['label']));
    try { require_once APP_ROOT . '/app/whatsapp_hook.php'; gimt_wa_event('GIMT_CONTACT_FORM', ['contact_message_id' => $id, 'name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'], 'subject' => $data['subject'] ?: $type['subject'], 'enquiry_type' => $type['type'], 'source' => 'website'], ['dedupe_key' => 'cm:' . $id, 'source' => 'website']); } catch (\Throwable $waE) { error_log('[GIMT] WhatsApp hook: ' . $waE->getMessage()); }
    $mailed = pf_send_ack('contact_received', $data['email'], ['name' => $data['name'], 'subject' => $data['subject'] ?: $type['subject'], 'message' => $data['message']],
        ['related_type' => 'contact_message', 'related_id' => $id]);
    return ['id' => $id, 'message' => 'Thank you, ' . pf_first_name($data['name']) . '! Your message has been received. Our team will get back to you within 24 hours'
        . ($mailed ? ' — a copy has been sent to your email.' : '.')];
}

/* ------------------------------------------------------------------
 * Counselling / quick enquiries
 * ------------------------------------------------------------------ */

/* ------------------------------------------------------------------
 * Dedupe markers (rate_limits rows "dedupe:<form>:<hash>" that expire after PF_DEDUPE_SECONDS)
 * ------------------------------------------------------------------ */

function pf_dedupe_key(string $form, ?string ...$parts): ?string
{
    $parts = array_values(array_filter($parts, fn ($p) => $p !== null && $p !== ''));
    return $parts ? 'dedupe:' . $form . ':' . substr(sha1(strtolower(implode('|', $parts))), 0, 24) : null;
}

function pf_dedupe_hit(?string $key): bool
{
    if ($key === null) {
        return false;
    }
    $reset = db_value('SELECT reset_at FROM rate_limits WHERE rl_key = ?', [$key]);
    return $reset && strtotime((string) $reset) > time();
}

function pf_dedupe_mark(?string $key, int $seconds = PF_DEDUPE_SECONDS): void
{
    if ($key !== null) {
        db_exec('REPLACE INTO rate_limits (rl_key, hits, reset_at) VALUES (?, 1, ?)', [$key, date('Y-m-d H:i:s', time() + $seconds)]);
    }
}

/**
 * Enquiry forms (hidden "form" key) whose UI shows a required consent checkbox: the server rejects them without
 * consent (422, field "consent"). Any other form can opt in by posting consent_required=1 next to the checkbox.
 * Forms without a consent box (admissions counselling, program pages) are unaffected.
 */
function pf_enquiry_consent_forms(): array
{
    return ['smart-enquiry', 'enquiry-popup', 'faq-question', 'callback', 'landing-hero', 'landing-footer'];
}

function pf_enquiry_consent_required(array $in): bool
{
    return pf_truthy($in['consent_required'] ?? '') || in_array(strtolower(trim(api_str($in['form'] ?? null))), pf_enquiry_consent_forms(), true);
}

function pf_submit_enquiry(array $in): array
{
    $form = pf_source_label($in['form'] ?? null);
    $data = [
        'name' => pf_text($in['name'] ?? null), 'phone' => pf_text($in['phone'] ?? null), 'email' => pf_email($in['email'] ?? null),
        'city' => pf_text($in['city'] ?? null), 'state' => pf_text($in['state'] ?? null), 'country' => pf_text($in['country'] ?? null),
        'country_code' => pf_text($in['country_code'] ?? null), 'message' => pf_text($in['message'] ?? null, true),
        'program_id' => pf_text($in['program_id'] ?? null), 'program' => pf_text($in['program'] ?? null),
        'utm_source' => pf_text($in['utm_source'] ?? null), 'utm_campaign' => pf_text($in['utm_campaign'] ?? null),
    ];
    $hasCountry = $data['country'] !== null || $data['country_code'] !== null;
    if (!$hasCountry && $data['state'] !== null && $data['phone'] !== null) {
        // a state without a country (enquiry forms that only ask for the state): the phone number's country decides
        // whether it must be an Indian state / UT — "Bagmati" with a +977 number is a valid Nepali province
        $phoneCountry = phone_parse($data['phone'])['country'] ?? null;
        if ($phoneCountry && $phoneCountry !== '001' && $phoneCountry !== 'IN') {
            $data['country_code'] = $phoneCountry;
            $data['country'] = phone_country_name($phoneCountry);
            $hasCountry = true;
        }
    }
    // a question from the FAQ page is the point of the form: the message is required there
    $isQuestion = in_array($form, ['faq-question', 'question'], true);
    $errors = validate($data, [
        'name' => 'required|min:2|max:150', 'email' => 'email|max:190', 'city' => 'max:80', 'state' => 'max:80', 'country' => 'max:80',
        'message' => $isQuestion ? 'required|min:10|max:2000' : 'max:2000', 'program' => 'max:190', 'utm_source' => 'max:100', 'utm_campaign' => 'max:100',
    ], ['name' => 'Full Name', 'email' => 'Email Address', 'city' => 'City', 'state' => 'State', 'country' => 'Country', 'message' => $isQuestion ? 'Your Question' : 'Message', 'program' => 'Program']);
    pf_check_name($errors, 'name', $data['name'], 'Full Name');
    pf_check_phone($errors, 'phone', $data['phone'], true, 'Phone Number');
    if (pf_enquiry_consent_required($in) && !pf_truthy($in['consent'] ?? '')) {
        // the browser enforces the ticked box too, but a scripted post must not store a lead the visitor never agreed to
        $errors['consent'] = 'Please tick the box to agree to be contacted.';
    }
    if (!$hasCountry) {
        unset($data['country'], $data['country_code']);
    }
    pf_check_address($errors, $data);
    $program = null;
    $interest = null;
    if ($data['program_id'] !== null) {
        $program = pf_find_program($data['program_id']);
        if (!$program) {
            $errors['program_id'] = 'Select a valid program.';
        }
    } elseif ($data['program'] !== null) {
        $program = pf_find_program($data['program']);
        $interest = $program ? null : mb_substr($data['program'], 0, 190);
    }
    if ($errors) {
        throw new PublicFormException(PF_FIX_FIELDS, $errors);
    }
    $page = pf_source_label($in['source'] ?? null) ?? 'website';
    $pageLabel = ucwords(str_replace('-', ' ', $page));
    $what = $program ? $program['short_name'] : ($interest ?: 'a program');
    $phoneKey = admission_phone_key($data['phone']);
    $location = isset($data['state']) || isset($data['country']) ? pf_location_text($data['city'], $data['state'] ?? null, $data['country'] ?? null) : '';
    $first = pf_first_name($data['name']);

    // Dedupe window: the same phone or email within 10 minutes is the same request — no new row, no second
    // notification; a new question is added to the lead's notes so nothing is lost.
    $dedupe = [pf_dedupe_key('enquiry', $phoneKey), pf_dedupe_key('enquiry', $data['email'])];
    if (pf_dedupe_hit($dedupe[0]) || pf_dedupe_hit($dedupe[1])) {
        $recent = db_row('SELECT id, notes, message FROM enquiries WHERE ' . admission_phone_sql('phone') . ' = ?' . ($data['email'] ? ' OR email = ?' : '') . ' ORDER BY id DESC LIMIT 1',
            array_values(array_filter([$phoneKey, $data['email']])));
        if ($recent && $data['message'] && !str_contains((string) $recent['notes'] . (string) $recent['message'], $data['message'])) {
            db_update('enquiries', ['notes' => trim(($recent['notes'] ? $recent['notes'] . "\n" : '') . '[' . date('d M Y H:i') . '] ' . $data['message'])], 'id = ?', [(int) $recent['id']]);
        }
        return ['id' => $recent ? (int) $recent['id'] : null, 'duplicate' => true,
            'message' => 'Thank you, ' . $first . '! We already received your request a few minutes ago — our admission counsellor will call you shortly.'];
    }

    // Repeat enquiry from the same phone (open, last 30 days): update it instead of creating a duplicate lead.
    $open = db_row("SELECT * FROM enquiries WHERE " . admission_phone_sql('phone') . " = ? AND status IN ('new','contacted','interested') AND created_at >= ? ORDER BY id DESC LIMIT 1",
        [$phoneKey, date('Y-m-d H:i:s', strtotime('-30 days'))]);
    if ($open) {
        $note = '[' . date('d M Y H:i') . '] Repeat website enquiry (' . $pageLabel . ($form ? ' · ' . $form : '') . ')' . ($program ? ' for ' . $program['short_name'] : '')
            . ($location !== '' ? ' · ' . $location : '') . ($data['message'] ? ': ' . $data['message'] : '');
        pf_dedupe_mark($dedupe[0]);
        pf_dedupe_mark($dedupe[1]);
        db_update('enquiries', [
            'notes' => trim(($open['notes'] ? $open['notes'] . "\n" : '') . $note),
            'program_id' => $open['program_id'] ?: ($program['id'] ?? null),
            'program_interest' => $open['program_interest'] ?: $interest,
            'email' => $open['email'] ?: $data['email'], 'city' => $open['city'] ?: $data['city'],
            'priority' => 'high',
            'follow_up_date' => $open['follow_up_date'] && $open['follow_up_date'] <= date('Y-m-d', strtotime('+1 day')) ? $open['follow_up_date'] : date('Y-m-d', strtotime('+1 day')),
        ], 'id = ?', [(int) $open['id']]);
        notify($open['assigned_to'] ? (int) $open['assigned_to'] : 'perm:enquiries', 'enquiry', 'Repeat enquiry', $data['name'] . ' (' . $data['phone'] . ') asked again about ' . $what . '.', 'admin/enquiries?q=' . rawurlencode($phoneKey), 'message-circle');
        log_activity('update', 'enquiries', $open['id'], sprintf('Repeat website enquiry from %s for %s (%s)', $data['name'], $what, $pageLabel));
        try { require_once APP_ROOT . '/app/whatsapp_hook.php'; gimt_wa_event('GIMT_ENQUIRY_REPEAT', ['enquiry_id' => (int) $open['id'], 'name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'], 'city' => $data['city'], 'program_id' => $program['id'] ?? $open['program_id'], 'program_name' => $program['name'] ?? $interest, 'counsellor_id' => $open['assigned_to'], 'form' => $form, 'page' => $page, 'message' => $data['message'], 'consent' => pf_truthy($in['consent'] ?? ''), 'source' => 'website'], ['dedupe_key' => 'enq:' . $open['id'] . ':' . date('YmdH'), 'source' => 'website']); } catch (\Throwable $waE) { error_log('[GIMT] WhatsApp hook: ' . $waE->getMessage()); }
        return ['id' => (int) $open['id'], 'message' => 'Thank you, ' . pf_first_name($data['name']) . '! We already have your request — our admission counsellor will call you shortly.'];
    }

    $id = db_insert('enquiries', [
        'name' => mb_substr($data['name'], 0, 150), 'email' => $data['email'], 'phone' => mb_substr($data['phone'], 0, 20), 'city' => $data['city'],
        // questions asked on /faq are tracked as their own lead source (admission_sources(): 'faq' => 'FAQ page')
        'program_id' => $program['id'] ?? null, 'program_interest' => $interest, 'source' => $isQuestion || $page === 'faq' ? 'faq' : 'website', 'message' => $data['message'],
        'status' => 'new', 'priority' => in_array($form, ['counselling', 'apply'], true) ? 'high' : 'medium', 'follow_up_date' => date('Y-m-d', strtotime('+1 day')),
        'notes' => 'Website form: ' . $pageLabel . ($form ? ' · ' . $form : '') . ($location !== '' ? "\nLocation: " . $location : ''),
        'utm_source' => $data['utm_source'], 'utm_campaign' => $data['utm_campaign'], 'ip_address' => client_ip(),
    ]);
    pf_dedupe_mark($dedupe[0]);
    pf_dedupe_mark($dedupe[1]);
    notify('perm:enquiries', 'enquiry', 'New website enquiry', $data['name'] . ' (' . $data['phone'] . ') is interested in ' . $what . '.', 'admin/enquiries?q=' . rawurlencode($phoneKey), 'message-circle');
    log_activity('create', 'enquiries', $id, sprintf('Website enquiry from %s for %s (%s)', $data['name'], $what, $pageLabel));
    try { require_once APP_ROOT . '/app/whatsapp_hook.php'; gimt_wa_event(gimt_wa_enquiry_code($form, !empty($program)), ['enquiry_id' => $id, 'name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'], 'city' => $data['city'], 'state' => $data['state'] ?? null, 'program_id' => $program['id'] ?? null, 'program_name' => $program['name'] ?? $interest, 'form' => $form, 'page' => $page, 'message' => $data['message'], 'callback_time' => is_string($in['callback_time'] ?? null) ? mb_substr($in['callback_time'], 0, 60) : null, 'consent' => pf_truthy($in['consent'] ?? ''), 'utm_source' => $data['utm_source'], 'utm_campaign' => $data['utm_campaign'], 'source' => 'website'], ['dedupe_key' => 'enq:' . $id, 'source' => 'website']); } catch (\Throwable $waE) { error_log('[GIMT] WhatsApp hook: ' . $waE->getMessage()); }
    pf_send_ack('enquiry_received', $data['email'], ['name' => $data['name'], 'program' => $program ? $program['name'] : ($interest ?: 'our programs')], ['related_type' => 'enquiry', 'related_id' => $id]);
    return ['id' => $id, 'message' => 'Thank you, ' . pf_first_name($data['name']) . '! Our admission counsellor will call you within 24 hours.'];
}

/* ------------------------------------------------------------------
 * Newsletter
 * ------------------------------------------------------------------ */

function pf_submit_newsletter(array $in): array
{
    $data = ['email' => pf_email($in['email'] ?? null), 'name' => pf_text($in['name'] ?? null)];
    $errors = validate($data, ['email' => 'required|email|max:190', 'name' => 'max:150'], ['email' => 'Email address', 'name' => 'Name']);
    if ($errors) {
        throw new PublicFormException(PF_FIX_FIELDS, $errors);
    }
    $source = pf_source_label($in['source'] ?? null) ?? 'website';
    $row = db_row('SELECT * FROM newsletter_subscribers WHERE email = ?', [$data['email']]);
    if ($row && $row['status'] === 'subscribed') {
        return ['id' => (int) $row['id'], 'message' => 'You are already subscribed with this email address. Thank you for staying connected!'];
    }
    if ($row) {
        db_update('newsletter_subscribers', ['status' => 'subscribed', 'name' => $data['name'] ?: $row['name'], 'subscribed_at' => date('Y-m-d H:i:s'), 'unsubscribed_at' => null, 'ip_address' => client_ip()],
            'id = ?', [(int) $row['id']]);
        $id = (int) $row['id'];
        $desc = 'Newsletter re-subscription: ' . $data['email'];
    } else {
        $id = db_insert('newsletter_subscribers', [
            'email' => $data['email'], 'name' => $data['name'], 'status' => 'subscribed', 'source' => $source, 'token' => bin2hex(random_bytes(16)),
            'ip_address' => client_ip(), 'subscribed_at' => date('Y-m-d H:i:s'),
        ]);
        $desc = 'Newsletter subscription: ' . $data['email'] . ' (' . $source . ')';
    }
    notify('perm:communication', 'newsletter', 'New newsletter subscriber', $data['email'] . ($data['name'] ? ' — ' . $data['name'] : ''), 'admin/newsletter/subscribers', 'mail-plus');
    log_activity('create', 'communication', $id, $desc);
    $mailed = pf_send_ack('newsletter_welcome', $data['email'], ['name' => $data['name'] ?: 'there', 'email' => $data['email']], ['related_type' => 'newsletter_subscriber', 'related_id' => $id]);
    return ['id' => $id, 'message' => 'Thank you for subscribing! You will now receive admission, campus and event updates' . ($mailed ? ' — check your inbox for a welcome email.' : '.')];
}

/* ------------------------------------------------------------------
 * Event registrations
 * ------------------------------------------------------------------ */

function pf_submit_event_registration(array $in): array
{
    $data = [
        'event_id' => pf_text($in['event_id'] ?? null), 'name' => pf_text($in['name'] ?? null), 'email' => pf_email($in['email'] ?? null),
        'phone' => pf_text($in['phone'] ?? null), 'organization' => pf_text($in['organization'] ?? null),
    ];
    $errors = validate($data, ['event_id' => 'required|integer', 'name' => 'required|min:2|max:150', 'email' => 'required|email|max:190', 'organization' => 'max:190'],
        ['event_id' => 'Event', 'name' => 'Full Name', 'email' => 'Email Address', 'organization' => 'Organisation / College']);
    pf_check_name($errors, 'name', $data['name'], 'Full Name');
    pf_check_phone($errors, 'phone', $data['phone'], false, 'Phone Number');
    $event = null;
    if (!isset($errors['event_id'])) {
        $event = db_row("SELECT * FROM events WHERE id = ? AND status = 'published' AND show_on_website = 1", [(int) $data['event_id']]);
        $deadline = $event && !empty($event['registration_deadline']) ? strtotime((string) $event['registration_deadline']) : false;
        if (!$event) {
            $errors['event_id'] = 'This event is not open for registration.';
        } elseif (strtotime((string) ($event['end_datetime'] ?: $event['start_datetime'])) < time()) {
            $errors['event_id'] = 'Registration is closed — this event has already taken place.';
        } elseif (empty($event['registration_enabled'])) {
            // online registration off (same rule as the event page): an external registration link, or none at all
            $errors['event_id'] = preg_match('#^https?://#i', trim((string) ($event['registration_link'] ?? '')))
                ? 'Registration for this event is handled on the organiser’s page — please use the registration link on the event page.'
                : 'Registration for this event is closed.';
        } elseif ($deadline && $deadline < time()) {
            $errors['event_id'] = 'Registration for this event is closed.';
        }
    }
    if ($errors) {
        throw new PublicFormException(isset($errors['event_id']) && count($errors) === 1 ? $errors['event_id'] : PF_FIX_FIELDS, $errors);
    }
    $when = format_date($event['start_datetime'], 'd M Y') . ', ' . date('g:i A', strtotime((string) $event['start_datetime']));
    $existing = db_row('SELECT * FROM event_registrations WHERE event_id = ? AND (email = ?' . ($data['phone'] ? ' OR ' . admission_phone_sql('phone') . ' = ?' : '') . ') ORDER BY (email = ?) DESC, id DESC LIMIT 1',
        array_merge([(int) $event['id'], $data['email']], $data['phone'] ? [admission_phone_key($data['phone'])] : [], [$data['email']]));
    if ($existing && $existing['status'] !== 'cancelled') {
        // dedupe window: the same person registering again within 10 minutes gets the confirmation again, not an error
        if (strtotime((string) $existing['created_at']) >= time() - PF_DEDUPE_SECONDS || strtotime((string) $existing['updated_at']) >= time() - PF_DEDUPE_SECONDS) {
            return ['id' => (int) $existing['id'], 'duplicate' => true, 'data' => ['event' => $event['title'], 'date' => $when, 'venue' => $event['venue']],
                'message' => 'You are already registered for ' . $event['title'] . ' on ' . $when . ' — we received your registration a few minutes ago.'];
        }
        $field = strcasecmp((string) $existing['email'], (string) $data['email']) === 0 ? 'email' : 'phone';
        throw new PublicFormException('You are already registered for this event.', [$field => ($field === 'email' ? 'This email address' : 'This mobile number') . ' is already registered for ' . $event['title'] . '.']);
    }
    if ($existing && strcasecmp((string) $existing['email'], (string) $data['email']) !== 0) {
        $existing = null; // a cancelled registration of someone else with this phone: register the new email separately
    }
    if ($event['max_participants']) {
        $taken = (int) db_value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status <> 'cancelled'", [(int) $event['id']]);
        if ($taken >= (int) $event['max_participants']) {
            throw new PublicFormException('Sorry, this event is fully booked. Please contact us to join the waiting list.', [], 422);
        }
    }
    $row = ['name' => mb_substr($data['name'], 0, 150), 'phone' => $data['phone'] ? mb_substr($data['phone'], 0, 20) : null, 'organization' => $data['organization'], 'status' => 'registered'];
    if ($existing) {
        db_update('event_registrations', $row, 'id = ?', [(int) $existing['id']]);
        $id = (int) $existing['id'];
    } else {
        $id = db_insert('event_registrations', $row + ['event_id' => (int) $event['id'], 'email' => $data['email']]);
    }
    notify('perm:events', 'event', 'New event registration', $data['name'] . ' registered for ' . $event['title'] . '.', 'admin/events', 'calendar-check');
    log_activity('create', 'events', $event['id'], sprintf('%s (%s) registered for "%s" via the website', $data['name'], $data['email'], $event['title']));
    try { require_once APP_ROOT . '/app/whatsapp_hook.php'; gimt_wa_event('GIMT_EVENT_REGISTERED', ['event_registration_id' => $id, 'event_id' => (int) $event['id'], 'name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'], 'event_title' => $event['title'], 'event_name' => $event['title'], 'event_date' => $when, 'venue' => (string) $event['venue'], 'event_venue' => (string) $event['venue'], 'event_link' => absolute_url('events/' . $event['slug']), 'source' => 'website'], ['dedupe_key' => 'reg:' . $id, 'source' => 'website']); } catch (\Throwable $waE) { error_log('[GIMT] WhatsApp hook: ' . $waE->getMessage()); }
    $mailed = pf_send_ack('event_registration', $data['email'], ['name' => $data['name'], 'event' => $event['title'], 'date' => $when, 'venue' => (string) $event['venue']],
        ['related_type' => 'event_registration', 'related_id' => $id]);
    return ['id' => $id, 'data' => ['event' => $event['title'], 'date' => $when, 'venue' => $event['venue']],
        'message' => 'You are registered for ' . $event['title'] . ' on ' . $when . ($event['venue'] ? ' at ' . $event['venue'] : '') . '.' . ($mailed ? ' A confirmation has been emailed to you.' : '')];
}

/* ------------------------------------------------------------------
 * Online application
 * ------------------------------------------------------------------ */

/** Select options used by the application form (wizard + no-JS form). */
function pf_application_options(): array
{
    return [
        'genders' => ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'],
        'categories' => admission_categories(),
        'quotas' => ['general' => 'General Merit', 'sports' => 'Sports Quota', 'defence' => 'Defence / Ex-servicemen Ward', 'nri' => 'NRI / Foreign National', 'lateral' => 'Lateral Entry'],
        'blood_groups' => ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'],
        'qualifications' => ['Class 12' => 'Class 12 / Intermediate', 'Diploma' => 'Diploma (3 years)', 'Graduation' => 'Graduation', 'Post Graduation' => 'Post Graduation', 'Other' => 'Other'],
        'relations' => ['Uncle', 'Aunt', 'Grandparent', 'Sibling', 'Cousin', 'Other'],
        'states' => ['Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chandigarh', 'Chhattisgarh', 'Dadra and Nagar Haveli and Daman and Diu',
            'Delhi', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jammu and Kashmir', 'Jharkhand', 'Karnataka', 'Kerala', 'Ladakh', 'Lakshadweep', 'Madhya Pradesh',
            'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Puducherry', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura',
            'Uttar Pradesh', 'Uttarakhand', 'West Bengal', 'Outside India'],
    ];
}

/** php.ini size ("8M", "40M", "1G") in bytes; 0 = unlimited / not set. */
function pf_ini_bytes(string $key): int
{
    $v = trim((string) ini_get($key));
    if ($v === '' || $v === '0' || $v === '-1') {
        return 0;
    }
    $n = (float) $v;
    return (int) match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
}

/**
 * Upload limits for application documents (bytes): per file (Settings > max_upload_mb and php.ini upload_max_filesize)
 * and in total (php.ini post_max_size minus room for the text fields), so the browser checks match what the server accepts.
 */
function pf_upload_limits(): array
{
    $server = upload_max_bytes('any');
    $ini = pf_ini_bytes('upload_max_filesize');
    if ($ini > 0) {
        $server = min($server, $ini);
    }
    $total = 20 * 1024 * 1024;
    $post = pf_ini_bytes('post_max_size');
    if ($post > 0) {
        $total = min($total, max(1024 * 1024, $post - 512 * 1024));
    }
    return ['photo' => min(2 * 1024 * 1024, $server), 'document' => min(5 * 1024 * 1024, $server), 'total' => $total];
}

/** True when the request body was larger than post_max_size (PHP then drops $_POST and $_FILES entirely). */
function pf_post_too_large(): bool
{
    $max = pf_ini_bytes('post_max_size');
    return $max > 0 && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $max;
}

/**
 * Documents for an online application. 'when' = always | pg (PG programs) | category (reserved category) | optional.
 * Required = always, pg for PG programs; category certificate is optional (can be submitted at verification).
 */
function pf_document_specs(): array
{
    $l = pf_upload_limits();
    return [
        'photo' => ['label' => 'Passport-size Photograph', 'hint' => 'Recent colour photo, white background. JPG, PNG or WebP, max ' . human_filesize($l['photo']) . '.', 'when' => 'always', 'image' => true, 'max' => $l['photo']],
        '10th_marksheet' => ['label' => 'Class 10 Marksheet', 'hint' => 'PDF, JPG or PNG, max ' . human_filesize($l['document']) . '.', 'when' => 'always', 'image' => false, 'max' => $l['document']],
        '12th_marksheet' => ['label' => 'Class 12 / Diploma Marksheet', 'hint' => 'Latest marksheet (or admit card if results are awaited). Optional for certificate courses.', 'when' => 'always', 'optional_for' => ['Certificate'], 'image' => false, 'max' => $l['document']],
        'graduation' => ['label' => 'Graduation Marksheet / Degree', 'hint' => 'Required for postgraduate programs. Final-year students may upload the latest semester marksheet.', 'when' => 'pg', 'image' => false, 'max' => $l['document']],
        'id_proof' => ['label' => 'ID Proof (Aadhaar / Passport)', 'hint' => 'Aadhaar card, passport or any government photo ID.', 'when' => 'always', 'image' => false, 'max' => $l['document']],
        'caste_certificate' => ['label' => 'Category Certificate', 'hint' => 'Only if you claim a reserved category (OBC / SC / ST / EWS). You can also submit it at verification.', 'when' => 'category', 'image' => false, 'max' => $l['document']],
    ];
}

function pf_doc_required(array $spec, ?array $program): bool
{
    // optional_for: program levels for which an 'always' document is optional (Class 12 marksheet for certificate courses)
    if ($program && in_array($program['level'] ?? '', $spec['optional_for'] ?? [], true)) {
        return false;
    }
    return $spec['when'] === 'always' || ($spec['when'] === 'pg' && ($program['level'] ?? '') === 'PG');
}

/** Field rules of the online application (labels shared with the client-side validation messages). */
function pf_application_rules(): array
{
    $opt = pf_application_options();
    $year = (int) date('Y');
    return [
        'program_id' => ['required|integer', 'Program'],
        'course_id' => ['integer', 'Specialisation'],
        'second_program_id' => ['integer', 'Second preference'],
        'category' => ['required|in:' . implode(',', array_keys($opt['categories'])), 'Category'],
        'quota' => ['in:' . implode(',', array_keys($opt['quotas'])), 'Admission quota'],
        'first_name' => ['required|max:80', 'First name'],
        'middle_name' => ['max:80', 'Middle name'],
        'last_name' => ['max:80', 'Last name'],
        'gender' => ['required|in:' . implode(',', array_keys($opt['genders'])), 'Gender'],
        'dob' => ['required|date', 'Date of birth'],
        'blood_group' => ['in:' . implode(',', $opt['blood_groups']), 'Blood group'],
        'nationality' => ['max:60', 'Nationality'],
        'aadhaar_no' => ['max:14', 'Aadhaar number'],
        'phone' => ['required', 'Mobile number'],
        'whatsapp' => ['', 'WhatsApp number'],
        'email' => ['required|email|max:190', 'Email address'],
        'address' => ['required|min:5|max:255', 'Address'],
        'city' => ['required|max:80', 'City'],
        'state' => ['max:80', 'State'],          // required for India (pf_validate_application)
        'pincode' => ['max:12', 'PIN code'],        // required where the country uses postal codes
        'country' => ['max:80', 'Country'],
        'father_name' => ['required|max:150', "Father's name"],
        'father_phone' => ['', "Father's mobile"],
        'father_occupation' => ['max:100', "Father's occupation"],
        'mother_name' => ['required|max:150', "Mother's name"],
        'mother_phone' => ['', "Mother's mobile"],
        'mother_occupation' => ['max:100', "Mother's occupation"],
        'guardian_name' => ['max:150', 'Guardian name'],
        'guardian_relation' => ['max:40', 'Relation'],
        'guardian_phone' => ['', 'Guardian mobile'],
        'family_income' => ['numeric|min:0|max:100000000', 'Annual family income'],
        'tenth_board' => ['required|max:100', 'Class 10 board'],
        'tenth_year' => ['required|integer|min:1980|max:' . $year, 'Class 10 passing year'],
        'tenth_percentage' => ['required|numeric|min:0|max:100', 'Class 10 marks %'],
        'previous_qualification' => ['required|in:' . implode(',', array_keys($opt['qualifications'])), 'Qualifying exam'],
        'previous_board' => ['required|max:100', 'Board / University'],
        'previous_institution' => ['max:190', 'School / College'],
        'passing_year' => ['required|integer|min:1980|max:' . ($year + 1), 'Passing year'],
        'previous_percentage' => ['required|numeric|min:0|max:100', 'Marks % / CGPA %'],
        'entrance_exam' => ['max:60', 'Entrance exam'],
    ];
}

/** Which wizard step a field belongs to (used to jump to the first step with a server error). */
function pf_application_field_steps(): array
{
    $steps = [
        'program' => ['program_id', 'course_id', 'second_program_id', 'category', 'quota', 'hostel_required', 'transport_required'],
        'personal' => ['first_name', 'middle_name', 'last_name', 'gender', 'dob', 'blood_group', 'nationality', 'aadhaar_no'],
        'contact' => ['phone', 'whatsapp', 'email', 'address', 'city', 'state', 'pincode', 'country'],
        'parents' => ['father_name', 'father_phone', 'father_occupation', 'mother_name', 'mother_phone', 'mother_occupation', 'guardian_name', 'guardian_relation', 'guardian_phone', 'family_income'],
        'academic' => ['tenth_board', 'tenth_year', 'tenth_percentage', 'previous_qualification', 'previous_board', 'previous_institution', 'passing_year', 'previous_percentage', 'entrance_exam'],
        'documents' => array_map(fn ($k) => 'doc_' . $k, array_keys(pf_document_specs())),
        'review' => ['declaration'],
    ];
    $out = [];
    foreach ($steps as $step => $fields) {
        foreach ($fields as $f) {
            $out[$f] = $step;
        }
    }
    return $out;
}

/**
 * Validate an application. Returns [$data (clean column values), $errors, $program, $docs (doc key => $_FILES entry)].
 * $files is $_FILES; documents are posted as doc_<type> (doc_photo, doc_10th_marksheet …).
 */
function pf_validate_application(array $in, array $files): array
{
    $rules = pf_application_rules();
    $data = [];
    foreach ($rules as $k => $_) {
        $data[$k] = pf_text($in[$k] ?? null);
    }
    $data['email'] = pf_email($data['email']);
    $data['country_code'] = pf_text($in['country_code'] ?? null);
    $data['aadhaar_no'] = $data['aadhaar_no'] !== null ? preg_replace('/\s+/', '', $data['aadhaar_no']) : null;
    $data['family_income'] = $data['family_income'] !== null ? str_replace([',', '₹', ' '], '', $data['family_income']) : null;
    $errors = validate($data, array_map(fn ($r) => $r[0], array_filter($rules, fn ($r) => $r[0] !== '')), array_map(fn ($r) => $r[1], $rules));

    // Program, specialisation, second preference
    $program = null;
    if (!isset($errors['program_id'])) {
        $program = pf_find_program($data['program_id']);
        if (!$program) {
            $errors['program_id'] = 'Select the program you want to apply for.';
        }
    } elseif ($data['program_id'] === null) {
        $errors['program_id'] = 'Select the program you want to apply for.';
    }
    if ($program && $data['course_id'] !== null && !isset($errors['course_id'])) {
        $ok = (int) db_value("SELECT COUNT(*) FROM courses WHERE id = ? AND program_id = ? AND status = 'active'", [(int) $data['course_id'], (int) $program['id']]);
        if (!$ok) {
            $errors['course_id'] = 'This specialisation is not offered in the selected program.';
        }
    }
    if ($data['second_program_id'] !== null && !isset($errors['second_program_id'])) {
        $second = pf_find_program($data['second_program_id']);
        if (!$second) {
            $errors['second_program_id'] = 'Select a valid second preference.';
        } elseif ($program && (int) $second['id'] === (int) $program['id']) {
            $errors['second_program_id'] = 'Second preference must be a different program.';
        }
    }

    // Personal
    pf_check_name($errors, 'first_name', $data['first_name'], 'First name');
    pf_check_name($errors, 'middle_name', $data['middle_name'], 'Middle name');
    pf_check_name($errors, 'last_name', $data['last_name'], 'Last name');
    if ($data['dob'] !== null && !isset($errors['dob'])) {
        $age = (int) floor((time() - strtotime($data['dob'])) / 31557600);
        if ($data['dob'] >= date('Y-m-d')) {
            $errors['dob'] = 'Date of birth must be in the past.';
        } elseif ($age < 14 || $age > 65) {
            $errors['dob'] = 'Applicant age must be between 14 and 65 years.';
        }
    }
    if ($data['aadhaar_no'] !== null && !isset($errors['aadhaar_no']) && !preg_match('/^\d{12}$/', $data['aadhaar_no'])) {
        $errors['aadhaar_no'] = 'Enter a valid 12-digit Aadhaar number.';
    }

    // Contact
    pf_check_phone($errors, 'phone', $data['phone'], true, 'Mobile number');
    foreach (['whatsapp' => 'WhatsApp number', 'father_phone' => "Father's mobile", 'mother_phone' => "Mother's mobile", 'guardian_phone' => 'Guardian mobile'] as $ph => $phLabel) {
        pf_check_phone($errors, $ph, $data[$ph], false, $phLabel);
    }
    // Address: country (default India) -> state (India: a state / UT) -> city -> postal code (per-country format)
    $countryIso = pf_check_address($errors, $data, ['postal_required' => true, 'postal_label' => null]);
    if ($countryIso === 'IN' && $data['state'] === null && !isset($errors['state'])) {
        $errors['state'] = 'State is required.';
    }
    $country = $data['country'] ?? 'India';

    // Parents
    pf_check_name($errors, 'father_name', $data['father_name'], "Father's name");
    pf_check_name($errors, 'mother_name', $data['mother_name'], "Mother's name");
    pf_check_name($errors, 'guardian_name', $data['guardian_name'], 'Guardian name');
    if ($data['guardian_name'] !== null && $data['guardian_phone'] === null && !isset($errors['guardian_phone'])) {
        $errors['guardian_phone'] = "Enter the guardian's mobile number.";
    }

    // Academic history — certificate courses ask for Class 10 only: the qualifying exam (Class 12 / graduation) is optional
    if ($program && ($program['level'] ?? '') === 'Certificate') {
        foreach (['previous_qualification', 'previous_board', 'passing_year', 'previous_percentage'] as $k) {
            if ($data[$k] === null) {
                unset($errors[$k]);
            }
        }
    }
    if (!isset($errors['passing_year']) && !isset($errors['tenth_year']) && $data['tenth_year'] && $data['passing_year'] && (int) $data['passing_year'] < (int) $data['tenth_year'] + 2) {
        $errors['passing_year'] = 'The qualifying exam must be at least 2 years after Class 10.';
    }
    if ($data['tenth_year'] && $data['dob'] && !isset($errors['tenth_year']) && !isset($errors['dob']) && (int) $data['tenth_year'] - (int) substr($data['dob'], 0, 4) < 13) {
        $errors['tenth_year'] = 'Class 10 passing year does not match your date of birth.';
    }
    if ($program && ($program['level'] ?? '') === 'PG' && $data['previous_qualification'] !== null && !isset($errors['previous_qualification'])
        && !in_array($data['previous_qualification'], ['Graduation', 'Post Graduation'], true)) {
        $errors['previous_qualification'] = 'Postgraduate programs require a bachelor\'s degree — select Graduation.';
    }

    // Declaration
    if (!pf_truthy($in['declaration'] ?? '')) {
        $errors['declaration'] = 'Please accept the declaration to submit your application.';
    }

    // Documents
    $docs = [];
    $total = 0;
    foreach (pf_document_specs() as $key => $spec) {
        $f = $files['doc_' . $key] ?? null;
        $has = is_array($f) && isset($f['error']) && !is_array($f['error']) && (int) $f['error'] !== UPLOAD_ERR_NO_FILE;
        $field = 'doc_' . $key;
        if (!$has) {
            if (pf_doc_required($spec, $program)) {
                $errors[$field] = 'Upload your ' . $spec['label'] . '.';
            }
            continue;
        }
        if ((int) $f['error'] !== UPLOAD_ERR_OK) {
            $errors[$field] = in_array((int) $f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'This file is too large. Maximum ' . human_filesize($spec['max']) . '.' : 'The upload failed. Please choose the file again.';
            continue;
        }
        $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        $allowed = $spec['image'] ? ['jpg', 'jpeg', 'png', 'webp'] : ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowed, true)) {
            $errors[$field] = 'Upload a ' . strtoupper(implode(', ', array_unique(array_map(fn ($e) => $e === 'jpeg' ? 'jpg' : $e, $allowed)))) . ' file.';
            continue;
        }
        if ((int) $f['size'] > $spec['max']) {
            $errors[$field] = 'This file is ' . human_filesize((int) $f['size']) . '. Maximum ' . human_filesize($spec['max']) . '.';
            continue;
        }
        $total += (int) $f['size'];
        $docs[$key] = $f;
    }
    if ($total > pf_upload_limits()['total']) {
        $errors['doc_other'] = 'Your documents add up to ' . human_filesize($total) . '. Please keep the total under ' . human_filesize(pf_upload_limits()['total']) . '.';
    }

    $data['hostel_required'] = pf_truthy($in['hostel_required'] ?? '') ? 1 : 0;
    $data['transport_required'] = pf_truthy($in['transport_required'] ?? '') ? 1 : 0;
    $data['nationality'] = $data['nationality'] ?: 'Indian';
    $data['country'] = $country;
    $data['quota'] = $data['quota'] ?: 'general';
    return [$data, $errors, $program, $docs];
}

/** Existing active application of the same person for the same program + session (duplicate guard). */
function pf_application_duplicate(array $data, int $programId, ?int $sessionId): ?array
{
    $args = [$programId, (int) $sessionId, admission_phone_key($data['phone'])];
    $sql = "SELECT a.id, a.application_no, a.phone, a.email, p.short_name FROM admissions a JOIN programs p ON p.id = a.program_id
            WHERE a.program_id = ? AND a.academic_session_id = ? AND a.stage NOT IN ('rejected','withdrawn')
              AND (" . admission_phone_sql('a.phone') . ' = ?' . ($data['email'] ? ' OR LOWER(a.email) = ?' : '') . ') ORDER BY a.id DESC LIMIT 1';
    if ($data['email']) {
        $args[] = $data['email'];
    }
    return db_row($sql, $args);
}

/**
 * Validate + store an online application with its documents. Returns ['message', 'data' => [application_no, ack_url …]].
 * Throws PublicFormException with field errors (document errors use doc_<type> keys).
 */
function pf_submit_application(array $in, array $files): array
{
    [$data, $errors, $program, $docs] = pf_validate_application($in, $files);
    $session = pf_admission_session();
    $sessionId = !empty($session['id']) ? (int) $session['id'] : null;
    if (!$errors && $program) {
        if (!(int) ($session['admissions_open'] ?? 1)) {
            throw new PublicFormException('Online applications are closed at the moment. Please contact the admission office.', [], 422);
        }
        if ($dup = pf_application_duplicate($data, (int) $program['id'], $sessionId)) {
            $field = admission_phone_key($dup['phone']) === admission_phone_key($data['phone']) ? 'phone' : 'email';
            $errors[$field] = sprintf('You have already applied for %s this session (application %s). Our admission team will contact you — call us for any changes.', $dup['short_name'], $dup['application_no']);
        }
    }
    if ($errors) {
        throw new PublicFormException(PF_FIX_FIELDS, $errors);
    }

    // Store the files first (private storage); undo them if anything fails afterwards.
    $stored = [];
    $cleanup = function () use (&$stored) {
        foreach ($stored as $s) {
            delete_upload($s['path']);
            if (!empty($s['public'])) {
                delete_upload($s['public']);
            }
        }
    };
    foreach ($docs as $key => $file) {
        $res = upload_file($file, 'any', 'admission-docs', true);
        if (!$res['ok']) {
            $cleanup();
            throw new PublicFormException(PF_FIX_FIELDS, ['doc_' . $key => $res['error']]);
        }
        $stored[$key] = $res;
    }
    // Public copy of the passport photo for avatars / print views (same as the admin document upload)
    $photoPublic = null;
    if (isset($stored['photo']) && in_array($stored['photo']['extension'], ['jpg', 'jpeg', 'png', 'webp'], true)) {
        $dir = 'assets/uploads/admissions/' . date('Y/m');
        if (is_dir(APP_ROOT . '/' . $dir) || @mkdir(APP_ROOT . '/' . $dir, 0775, true)) {
            $dest = $dir . '/' . bin2hex(random_bytes(16)) . '.' . $stored['photo']['extension'];
            if (@copy(APP_ROOT . '/' . $stored['photo']['path'], APP_ROOT . '/' . $dest)) {
                $photoPublic = $dest;
                $stored['photo']['public'] = $dest;
            }
        }
    }

    try {
        $admission = db_transaction(function () use ($data, $program, $sessionId, $stored, $photoPublic) {
            $now = date('Y-m-d H:i:s');
            $enquiry = db_row("SELECT id FROM enquiries WHERE " . admission_phone_sql('phone') . " = ? AND status NOT IN ('converted','closed','not_interested') ORDER BY id DESC LIMIT 1",
                [admission_phone_key($data['phone'])]);
            $row = [
                'application_no' => admission_next_application_no($sessionId), 'enquiry_id' => $enquiry ? (int) $enquiry['id'] : null, 'academic_session_id' => $sessionId,
                'program_id' => (int) $program['id'], 'course_id' => $data['course_id'] ? (int) $data['course_id'] : null,
                'second_program_id' => $data['second_program_id'] ? (int) pf_find_program($data['second_program_id'])['id'] : null,
                'first_name' => mb_substr($data['first_name'], 0, 80), 'middle_name' => $data['middle_name'], 'last_name' => $data['last_name'], 'photo' => $photoPublic,
                'gender' => $data['gender'], 'dob' => $data['dob'], 'blood_group' => $data['blood_group'], 'category' => $data['category'], 'quota' => $data['quota'],
                'nationality' => $data['nationality'], 'aadhaar_no' => $data['aadhaar_no'], 'email' => $data['email'], 'phone' => mb_substr($data['phone'], 0, 20),
                'whatsapp' => $data['whatsapp'] ? mb_substr($data['whatsapp'], 0, 20) : null, 'address' => mb_substr($data['address'], 0, 255), 'city' => $data['city'],
                'state' => $data['state'], 'country' => $data['country'], 'pincode' => $data['pincode'],
                'father_name' => $data['father_name'], 'father_phone' => $data['father_phone'], 'father_occupation' => $data['father_occupation'],
                'mother_name' => $data['mother_name'], 'mother_phone' => $data['mother_phone'], 'mother_occupation' => $data['mother_occupation'],
                'guardian_name' => $data['guardian_name'], 'guardian_relation' => $data['guardian_relation'], 'guardian_phone' => $data['guardian_phone'],
                'family_income' => $data['family_income'] !== null ? round((float) $data['family_income'], 2) : null,
                'tenth_board' => $data['tenth_board'], 'tenth_percentage' => round((float) $data['tenth_percentage'], 2), 'tenth_year' => (int) $data['tenth_year'],
                'previous_qualification' => $data['previous_qualification'], 'previous_board' => $data['previous_board'], 'previous_institution' => $data['previous_institution'],
                'previous_percentage' => $data['previous_percentage'] !== null ? round((float) $data['previous_percentage'], 2) : null,
                'passing_year' => $data['passing_year'] !== null ? (int) $data['passing_year'] : null, 'entrance_exam' => $data['entrance_exam'],
                'hostel_required' => $data['hostel_required'], 'transport_required' => $data['transport_required'], 'declaration_accepted' => 1, 'declaration_at' => $now,
                'source' => 'website', 'stage' => 'application', 'stage_changed_at' => $now, 'ip_address' => client_ip(),
            ];
            $id = db_insert('admissions', $row);
            foreach ($stored as $key => $res) {
                db_insert('admission_documents', [
                    'admission_id' => $id, 'doc_type' => $key, 'file_path' => $res['path'], 'original_name' => $res['original_name'], 'mime' => $res['mime'],
                    'size_bytes' => $res['size'], 'status' => 'pending', 'remarks' => 'Uploaded with the online application',
                ]);
            }
            admission_add_history($id, null, 'application', 'Online application submitted on the website' . ($enquiry ? ' (from enquiry #' . $enquiry['id'] . ')' : ''));
            if ($enquiry) {
                db_exec("UPDATE enquiries SET status = 'converted', admission_id = ?, follow_up_date = NULL WHERE id = ?", [$id, (int) $enquiry['id']]);
                db_exec('UPDATE admission_followups SET completed_at = NOW() WHERE enquiry_id = ? AND completed_at IS NULL AND next_followup_date IS NOT NULL', [(int) $enquiry['id']]);
            }
            return db_row('SELECT * FROM admissions WHERE id = ?', [$id]);
        });
    } catch (Throwable $e) {
        $cleanup();
        log_system('error', 'Online application could not be saved', ['error' => $e->getMessage()]);
        throw new PublicFormException('We could not save your application right now. Please try again in a few minutes or call the admission office.', [], 500);
    }

    $id = (int) $admission['id'];
    $name = admission_full_name($admission);
    $programLabel = $program['short_name'] . ' — ' . $program['name'];
    notify('perm:admissions', 'application', 'New online application', sprintf('%s applied for %s (%s) on the website.', $name, $program['short_name'], $admission['application_no']), 'admin/admissions/' . $id, 'file-plus');
    log_activity('create', 'admissions', $id, sprintf('Online application %s received from %s for %s (%d document%s)', $admission['application_no'], $name, $program['short_name'], count($stored), count($stored) === 1 ? '' : 's'));
    try { require_once APP_ROOT . '/app/whatsapp_hook.php'; gimt_wa_event(['GIMT_APPLICATION_SUBMITTED', 'GIMT_APPLICATION_NUMBER_GENERATED'], ['admission_id' => $id, 'application_no' => $admission['application_no'], 'name' => $name, 'phone' => $admission['whatsapp'] ?: $admission['phone'], 'email' => $admission['email'], 'program_id' => (int) $program['id'], 'program_name' => $program['name'], 'session' => admission_session_name($sessionId), 'documents_count' => count($stored), 'admission_status' => 'Under review', 'ack_link' => absolute_url('apply') . '?ack=' . rawurlencode($admission['application_no']) . '&t=' . pf_ack_token($admission), 'source' => 'website'], ['dedupe_key' => 'adm:' . $id, 'source' => 'admission_portal']); } catch (\Throwable $waE) { error_log('[GIMT] WhatsApp hook: ' . $waE->getMessage()); }
    $mailed = pf_send_ack('admission_received', $admission['email'], ['name' => $name, 'program' => $programLabel, 'application_no' => $admission['application_no']],
        ['related_type' => 'admission', 'related_id' => $id]);
    return [
        'id' => $id,
        'message' => 'Application ' . $admission['application_no'] . ' submitted successfully.',
        'data' => [
            'application_no' => $admission['application_no'], 'name' => $name, 'first_name' => $admission['first_name'], 'program' => $programLabel,
            'session' => admission_session_name($sessionId), 'email' => $admission['email'], 'email_sent' => $mailed, 'documents' => count($stored),
            'submitted_at' => format_datetime($admission['created_at']), 'ack_url' => pf_ack_url($admission),
        ],
    ];
}

/** Secret for acknowledgement links (HMAC); config app.key, else a per-install fallback. */
function pf_ack_secret(): string
{
    $key = (string) (app_config('app')['key'] ?? '');
    return $key !== '' ? $key : hash('sha256', APP_ROOT . '|' . (app_config('db')['name'] ?? 'gimt'));
}

function pf_ack_token(array $a): string
{
    return substr(hash_hmac('sha256', $a['id'] . '|' . $a['application_no'] . '|' . $a['created_at'], pf_ack_secret()), 0, 32);
}

/** Printable acknowledgement link (relative URL): /apply?ack=APP%2F2026-27%2F00301&t=… */
function pf_ack_url(array $a): string
{
    return pf_site_url('apply') . '?ack=' . rawurlencode($a['application_no']) . '&t=' . pf_ack_token($a);
}

/** base_url() wrapper usable from the API (includes/config.php's site_url() is only loaded on website pages). */
function pf_site_url(string $path): string
{
    return base_url(ltrim($path, '/'));
}

/** Application for the acknowledgement view, when the token matches. */
function pf_find_ack(string $applicationNo, string $token): ?array
{
    if ($applicationNo === '' || strlen($token) !== 32) {
        return null;
    }
    $a = db_row("SELECT a.*, p.name AS program_name, p.short_name AS program_short, p.level AS program_level, p.duration_label, c.name AS course_name, s.name AS session_name
                 FROM admissions a JOIN programs p ON p.id = a.program_id LEFT JOIN courses c ON c.id = a.course_id
                 LEFT JOIN academic_sessions s ON s.id = a.academic_session_id WHERE a.application_no = ?", [$applicationNo]);
    if (!$a || !hash_equals(pf_ack_token($a), $token)) {
        return null;
    }
    $a['documents'] = db_all('SELECT doc_type, original_name, status, created_at FROM admission_documents WHERE admission_id = ? ORDER BY id', [(int) $a['id']]);
    return $a;
}
