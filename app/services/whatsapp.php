<?php
/**
 * WhatsApp Automation & Student Communication — CORE service (Interakt WhatsApp Business API).
 *
 * This file is the contract every WhatsApp feature codes against (docs/WHATSAPP.md §4). It owns:
 *   - settings / mode (test | live), business hours
 *   - phone normalisation, the contact CRM primitives, conversations
 *   - the event bus: wa_event() (never throws, logs wa_event_logs with dedupe), wa_on(), wa_on_tick()
 *   - templates: find, render (GIMT named variables ↔ positional {{n}}), variable catalog
 *   - sending: wa_send() → wa_messages + wa_outbox (queued); the worker delivers through the Interakt client
 *   - the Interakt HTTP client (every call logged to wa_api_logs without credentials)
 *   - webhooks: store fast (signature check) → process from the worker; simulated webhooks for local testing
 *   - the background worker (cron / opportunistic ticks), log retention
 *
 * Feature services (app/services/whatsapp_*.php) are loaded at the end of this file; they must only define functions
 * and register hooks (wa_on / wa_on_tick) at include time — never query the database at include time.
 *
 * Mode: wa_mode() is 'live' only when Settings › WhatsApp › Mode = live, an API key is saved and the install is not
 * in dev mode. In test mode nothing reaches Interakt: the outbox marks messages 'simulated' (id test-<uuid>) and logs
 * the request in wa_api_logs with mode = 'test'.
 */

require_once APP_ROOT . '/app/services/phone.php';

if (!defined('WA_WINDOW_SECONDS')) {
    define('WA_WINDOW_SECONDS', 86400);
}

/* =================================================================================================================
 * Small helpers
 * ================================================================================================================= */

/** JSON encode for LONGTEXT columns (unicode + slashes unescaped). */
function wa_json($value): ?string
{
    if ($value === null) {
        return null;
    }
    $j = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    return $j === false ? null : $j;
}

/** Decode a JSON column; returns $default when empty / invalid. */
function wa_json_decode($value, $default = null)
{
    if (is_array($value)) {
        return $value;
    }
    if ($value === null || $value === '') {
        return $default;
    }
    $d = json_decode((string) $value, true);
    return $d === null && json_last_error() !== JSON_ERROR_NONE ? $default : $d;
}

function wa_now(): string
{
    return date('Y-m-d H:i:s');
}

/** RFC 4122 v4 UUID. */
function wa_uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/**
 * Depth of background work in this request (wa_worker_tick(), webhook post-processing). $delta +1 / -1 enters / leaves;
 * use wa_as_worker() rather than calling this directly.
 */
function wa_worker_depth(int $delta = 0): int
{
    static $depth = 0;
    $depth = max(0, $depth + $delta);
    return $depth;
}

/**
 * True while background work runs (cron, "Run worker now", a light tick inside a staff member's request, webhook
 * post-processing): that work has no human actor, so wa_user_id() returns null and wa_event() defaults to source
 * 'worker' — opt-ins, stage changes and agent-activity rows written by the worker are never attributed to whoever
 * happened to trigger the tick.
 */
function wa_in_worker(): bool
{
    return wa_worker_depth() > 0;
}

/** Run $fn as background work (see wa_in_worker()); the previous context is restored afterwards, even on errors. */
function wa_as_worker(callable $fn)
{
    wa_worker_depth(1);
    try {
        return $fn();
    } finally {
        wa_worker_depth(-1);
    }
}

/** Current staff user id (null in CLI / cron / webhook context and while background work runs — wa_in_worker()). */
function wa_user_id(): ?int
{
    if (!function_exists('user_id') || wa_in_worker()) {
        return null;
    }
    $id = user_id();
    return $id ? (int) $id : null;
}

/** can() wrapper usable from CLI / cron (false when auth is not loaded). */
function wa_can(string $module, string $action = 'view'): bool
{
    return function_exists('can') && can($module, $action);
}

/** True when the user holds any of the given [module, action] pairs. */
function wa_can_any(array $pairs): bool
{
    foreach ($pairs as $p) {
        if (wa_can((string) $p[0], (string) ($p[1] ?? 'view'))) {
            return true;
        }
    }
    return false;
}

/** API guard: 403 unless the user holds one of the [module, action] pairs (message spells WhatsApp correctly). */
function wa_require_any(array $pairs, string $what = 'do this'): void
{
    if (!wa_can_any($pairs)) {
        api_error('Your role does not allow you to ' . $what . ' in WhatsApp. Ask a Super Admin to update your role.', 403);
    }
}

/** Every whatsapp* permission module (registry keys). */
function wa_permission_modules(): array
{
    return ['whatsapp', 'whatsapp_contacts', 'whatsapp_templates', 'whatsapp_campaigns', 'whatsapp_automation', 'whatsapp_reports', 'whatsapp_settings'];
}

/** Pairs granting view of any WhatsApp area. */
function wa_any_view_pairs(): array
{
    return array_map(fn ($m) => [$m, 'view'], wa_permission_modules());
}

/** Convert an Interakt UTC timestamp ('2022-06-03T05:43:33.133000', no zone) to Asia/Kolkata 'Y-m-d H:i:s'. */
function wa_utc_to_local($ts): ?string
{
    if ($ts === null || $ts === '' || !is_scalar($ts)) {
        return null;
    }
    $s = trim((string) $ts);
    try {
        if (is_numeric($s)) {
            $n = (float) $s;
            $d = (new DateTimeImmutable('@' . (int) ($n > 1e12 ? $n / 1000 : $n)));
        } else {
            $hasZone = (bool) preg_match('/(Z|[+-]\d\d:?\d\d)$/i', $s);
            $d = new DateTimeImmutable($s, $hasZone ? null : new DateTimeZone('UTC'));
        }
        return $d->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

/** Strip a value for a one-line preview. */
function wa_preview(?string $text, int $len = 120): string
{
    $t = trim((string) preg_replace('/\s+/u', ' ', str_replace(['*', '_', '~', '`'], '', (string) $text)));
    return mb_strlen($t) > $len ? mb_substr($t, 0, $len - 1) . '…' : $t;
}

/* =================================================================================================================
 * Settings / mode
 * ================================================================================================================= */

/** Defaults for the whatsapp settings group (seeded by 09_wa_0_core.php; used when a key is missing). */
function wa_setting_defaults(): array
{
    return [
        'wa_mode' => 'test',
        'wa_interakt_api_key' => '',
        'wa_interakt_base_url' => 'https://api.interakt.ai/v1/public/',
        'wa_webhook_secret' => '',
        'wa_interakt_plan' => 'growth',
        'wa_rate_per_minute' => '250',
        'wa_business_number' => '',
        'wa_business_name' => 'GIMT',
        'wa_default_language' => 'en',
        'wa_business_hours' => '{"mon":["09:00","18:00"],"tue":["09:00","18:00"],"wed":["09:00","18:00"],"thu":["09:00","18:00"],"fri":["09:00","18:00"],"sat":["09:00","14:00"],"sun":null}',
        'wa_bot_enabled' => '0',
        'wa_auto_sync_contacts' => '1',
        'wa_retention_days' => '90',
        'wa_max_attempts' => '5',
        'wa_optout_reply' => 'You have been unsubscribed from GIMT WhatsApp updates. Reply START to subscribe again.',
        'wa_optin_reply' => 'Welcome back! You will now receive GIMT updates on WhatsApp. Reply STOP to unsubscribe.',
        'wa_allow_simulate_live' => '0',
        'wa_brochure_url' => '',
        // admin part (Settings › Notifications / Automation / Security) — read through wa_adm_setting()
        'wa_notify_new_message' => '1',
        'wa_notify_assignment' => '1',
        'wa_notify_handover' => '1',
        'wa_daily_digest' => '0',
        'wa_digest_time' => '09:00',
        'wa_automation_paused' => '0',
        'wa_quiet_enabled' => '0',
        'wa_quiet_start' => '21:00',
        'wa_quiet_end' => '08:00',
        'wa_max_per_contact_day' => '0',
        'wa_export_scope' => 'permission',
        'wa_mask_phones' => '0',
    ];
}

/** Sender types that are automated (held while automations are paused / during quiet hours). Agent, bot and system replies always pass. */
function wa_automated_sender_types(): array
{
    return ['automation', 'event', 'followup', 'campaign'];
}

/**
 * Sender types a contact who replied STOP (wa_contact_opted_out) no longer receives, whatever the template category:
 * workflows, campaigns, follow-ups and the AI bot. Transactional 'event' messages (payment receipt, admission / exam
 * notices — UTILITY templates) still go out; MARKETING templates always need opt_in = 1. Agent and system replies
 * (e.g. the STOP confirmation) pass. The contact resumes everything by replying START.
 */
function wa_optout_blocked_sender_types(): array
{
    return ['automation', 'campaign', 'followup', 'bot'];
}

/** The contact explicitly opted out (replied STOP / unsubscribed) — not merely "never opted in". */
function wa_contact_opted_out(array $contact): bool
{
    return (int) ($contact['opt_in'] ?? 0) !== 1 && !empty($contact['opted_out_at']);
}

/** Keys whose values are secrets (never sent to the browser, never logged). */
function wa_is_secret_key(string $key): bool
{
    return (bool) preg_match('/(api_key|secret|token|password)/i', $key);
}

/**
 * A whatsapp setting (settings group 'whatsapp'). Falls back to $default, then to wa_setting_defaults().
 * Secret values stored encrypted ('enc:…' / 'plain:…', the chatbot scheme) are decrypted transparently.
 */
function wa_setting(string $key, $default = null)
{
    $v = setting($key, null);
    if ($v === null) {
        return $default ?? (wa_setting_defaults()[$key] ?? null);
    }
    if (is_string($v) && wa_is_secret_key($key) && (str_starts_with($v, 'enc:') || str_starts_with($v, 'plain:'))) {
        $v = wa_decrypt($v);
    }
    return $v;
}

/** Encrypt a secret for storage (same scheme as the chatbot keys; falls back to plain text). */
function wa_encrypt(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    if (!function_exists('chatbot_encrypt') && is_file(APP_ROOT . '/app/services/chatbot.php')) {
        require_once APP_ROOT . '/app/services/chatbot.php';
    }
    return function_exists('chatbot_encrypt') ? chatbot_encrypt($plain) : $plain;
}

function wa_decrypt(string $stored): string
{
    if ($stored === '' || (!str_starts_with($stored, 'enc:') && !str_starts_with($stored, 'plain:'))) {
        return $stored;
    }
    if (str_starts_with($stored, 'plain:')) {
        return substr($stored, 6);
    }
    if (!function_exists('chatbot_decrypt') && is_file(APP_ROOT . '/app/services/chatbot.php')) {
        require_once APP_ROOT . '/app/services/chatbot.php';
    }
    return function_exists('chatbot_decrypt') ? chatbot_decrypt($stored) : '';
}

/** 'test' | 'live' — live needs mode=live AND an API key AND a non-dev install. */
function wa_mode(): string
{
    return wa_setting('wa_mode', 'test') === 'live' && wa_is_configured() && !is_dev() ? 'live' : 'test';
}

/** An Interakt API key is saved. */
function wa_is_configured(): bool
{
    return trim((string) wa_setting('wa_interakt_api_key', '')) !== '';
}

function wa_rate_per_minute(): int
{
    return max(1, min(1000, (int) wa_setting('wa_rate_per_minute', 250)));
}

/** Business hours map: day ('mon'..'sun') => ['09:00','18:00'] or null (closed). */
function wa_business_hours(): array
{
    $raw = wa_json_decode(wa_setting('wa_business_hours'), null);
    if (!is_array($raw)) {
        $raw = wa_json_decode(wa_setting_defaults()['wa_business_hours'], []);
    }
    $out = [];
    foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $d) {
        $h = $raw[$d] ?? null;
        if (is_array($h) && count($h) >= 2 && preg_match('/^\d{1,2}:\d{2}$/', (string) $h[0]) && preg_match('/^\d{1,2}:\d{2}$/', (string) $h[1])) {
            $out[$d] = [str_pad((string) $h[0], 5, '0', STR_PAD_LEFT), str_pad((string) $h[1], 5, '0', STR_PAD_LEFT)];
        } else {
            $out[$d] = null;
        }
    }
    return $out;
}

function wa_in_business_hours(?DateTimeInterface $at = null): bool
{
    $at = $at ? DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone(date_default_timezone_get())) : new DateTimeImmutable('now');
    $h = wa_business_hours()[strtolower($at->format('D'))] ?? null;
    if (!$h) {
        return false;
    }
    $t = $at->format('H:i');
    return $t >= $h[0] && $t < $h[1];
}

/** $from itself when inside business hours, else the next opening time (looks ahead 8 days; $from when never open). */
function wa_next_business_time(DateTimeInterface $from): DateTimeImmutable
{
    $from = DateTimeImmutable::createFromInterface($from)->setTimezone(new DateTimeZone(date_default_timezone_get()));
    if (wa_in_business_hours($from)) {
        return $from;
    }
    $hours = wa_business_hours();
    for ($i = 0; $i <= 8; $i++) {
        $day = $from->modify('+' . $i . ' day');
        $h = $hours[strtolower($day->format('D'))] ?? null;
        if (!$h) {
            continue;
        }
        [$oh, $om] = array_map('intval', explode(':', $h[0]));
        $open = $day->setTime($oh, $om, 0);
        if ($open > $from) {
            return $open;
        }
    }
    return $from;
}

/* =================================================================================================================
 * Phones
 * ================================================================================================================= */

/**
 * Normalise a phone number for WhatsApp. Indian 10-digit numbers default to +91.
 * @return array{phone:string,country_code:string,national:string}|null  phone = digits with country code (919876543210)
 */
function wa_normalize_phone(?string $raw, string $defaultCc = '91'): ?array
{
    $raw = trim((string) $raw);
    if ($raw === '' || preg_match('/[A-Za-z]/', $raw)) {
        return null;
    }
    $defaultCc = preg_replace('/\D+/', '', $defaultCc) ?: '91';
    $digits = (string) preg_replace('/\D+/', '', $raw);
    if (strlen($digits) < 8 || strlen($digits) > 15 || preg_match('/^(\d)\1+$/', $digits)) {
        return null;
    }
    $intl = str_starts_with(ltrim($raw), '+') || str_starts_with($digits, '00');
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }
    $parsed = null;
    if ($intl) {
        $parsed = phone_parse('+' . $digits);
    } elseif ($defaultCc === '91') {
        $parsed = phone_parse($digits, 'IN');
    } else {
        $parsed = str_starts_with($digits, $defaultCc) && strlen($digits) > 10 ? phone_parse('+' . $digits) : phone_parse('+' . $defaultCc . ltrim($digits, '0'));
    }
    if ($parsed && $parsed['valid'] && $parsed['calling_code'] && $parsed['national']) {
        $cc = (string) $parsed['calling_code'];
        $nn = (string) $parsed['national'];
        return ['phone' => $cc . $nn, 'country_code' => '+' . $cc, 'national' => $nn];
    }
    // lenient fallback for Indian mobiles (demo data / numbers the parser's ranges do not know yet)
    $d = $digits;
    if (strlen($d) === 11 && $d[0] === '0') {
        $d = substr($d, 1);
    }
    if (strlen($d) === 12 && str_starts_with($d, '91')) {
        $d = substr($d, 2);
    }
    if (strlen($d) === 10 && preg_match('/^[6-9]\d{9}$/', $d) && ($intl ? str_starts_with($digits, '91') : $defaultCc === '91')) {
        return ['phone' => '91' . $d, 'country_code' => '+91', 'national' => $d];
    }
    return null;
}

/* =================================================================================================================
 * Contacts / CRM
 * ================================================================================================================= */

/** Columns wa_contact_upsert() may write. */
function wa_contact_fields(): array
{
    return ['name', 'email', 'alt_phone', 'contact_type', 'student_id', 'enquiry_id', 'admission_id', 'user_id', 'application_no', 'program_id',
        'program_name', 'batch', 'city', 'state', 'admission_status', 'payment_status', 'counsellor_id', 'language', 'source', 'notes', 'status'];
}

/** FK columns => referenced table (checked before writing so a stale id never breaks the caller). */
function wa_contact_fk(): array
{
    return ['student_id' => 'students', 'enquiry_id' => 'enquiries', 'admission_id' => 'admissions', 'program_id' => 'programs',
        'counsellor_id' => 'users', 'user_id' => 'users'];
}

/**
 * Create or update a contact by phone (or by 'id'). Without $opts['overwrite'] only empty columns are filled; links to
 * student / enquiry / admission are filled when empty; contact_type is upgraded from lead/other. opt_in = 1 in $data
 * opts in (unless the contact opted out before and overwrite is not set). Returns the contact id.
 * $opts: overwrite (bool), source (string, used on insert).
 * @throws InvalidArgumentException when the phone number is invalid / missing
 */
function wa_contact_upsert(array $data, array $opts = []): int
{
    $overwrite = !empty($opts['overwrite']);
    $existing = null;
    if (!empty($data['id'])) {
        $existing = wa_contact((int) $data['id']);
        if (!$existing) {
            throw new InvalidArgumentException('WhatsApp contact not found.');
        }
    }
    $norm = null;
    if (isset($data['phone']) && $data['phone'] !== '' && $data['phone'] !== null) {
        $norm = wa_normalize_phone((string) $data['phone']);
        if (!$norm) {
            throw new InvalidArgumentException('Invalid WhatsApp number: ' . mb_substr((string) $data['phone'], 0, 30));
        }
        if (!$existing) {
            $existing = db_row('SELECT * FROM wa_contacts WHERE phone = ?', [$norm['phone']]);
        }
    } elseif (!$existing) {
        throw new InvalidArgumentException('A WhatsApp number is required.');
    }

    // clean incoming values
    $in = [];
    foreach (wa_contact_fields() as $f) {
        if (!array_key_exists($f, $data)) {
            continue;
        }
        $v = $data[$f];
        if (is_string($v)) {
            $v = trim($v);
        }
        if ($v === '' || $v === null || is_array($v)) {
            continue;
        }
        $in[$f] = $v;
    }
    foreach (wa_contact_fk() as $f => $table) {
        if (isset($in[$f])) {
            $id = (int) $in[$f];
            if ($id <= 0 || !db_value('SELECT id FROM ' . db_quote_ident($table) . ' WHERE id = ?', [$id])) {
                unset($in[$f]);
            } else {
                $in[$f] = $id;
            }
        }
    }
    if (isset($in['email'])) {
        $in['email'] = mb_substr(mb_strtolower((string) $in['email']), 0, 190);
    }
    if (isset($in['name'])) {
        $in['name'] = mb_substr((string) $in['name'], 0, 150);
    }
    if (isset($in['program_id']) && !isset($in['program_name'])) {
        $pn = db_value('SELECT name FROM programs WHERE id = ?', [$in['program_id']]);
        if ($pn) {
            $in['program_name'] = $pn;
        }
    }
    if (isset($in['contact_type']) && !in_array($in['contact_type'], ['lead', 'applicant', 'student', 'guardian', 'alumni', 'staff', 'other'], true)) {
        unset($in['contact_type']);
    }
    if (isset($in['language']) && !preg_match('/^[a-z]{2}$/', (string) $in['language'])) {
        unset($in['language']);
    }
    // 'false' / 'no' / 'off' from external payloads (ingest API, CSV) must not count as consent
    $optIn = array_key_exists('opt_in', $data) && $data['opt_in'] !== null && $data['opt_in'] !== '' && is_scalar($data['opt_in'])
        ? (is_string($data['opt_in']) ? filter_var(trim($data['opt_in']), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : (bool) $data['opt_in'])
        : null;
    $now = wa_now();

    if (!$existing) {
        $row = $in + [
            'phone' => $norm['phone'],
            'country_code' => $norm['country_code'],
            'national_number' => $norm['national'],
            'source' => $opts['source'] ?? ($in['source'] ?? 'manual'),
            'created_by' => wa_user_id(),
        ];
        $row['source'] = mb_substr((string) ($in['source'] ?? $row['source']), 0, 60);
        if (!empty($data['stage']) && preg_match('/^[a-z0-9_]{2,30}$/', (string) $data['stage'])) {
            $row['stage'] = $data['stage'];
            $row['stage_changed_at'] = $now;
        }
        if ($optIn) {
            $row['opt_in'] = 1;
            $row['opt_in_at'] = $now;
            $row['opt_in_source'] = mb_substr((string) ($data['opt_in_source'] ?? $row['source']), 0, 80);
        }
        try {
            return db_insert('wa_contacts', $row);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            $existing = db_row('SELECT * FROM wa_contacts WHERE phone = ?', [$norm['phone']]); // lost a race: update instead
            if (!$existing) {
                throw $e;
            }
        }
    }

    $set = [];
    $rank = ['lead' => 0, 'other' => 0, 'applicant' => 1, 'guardian' => 1, 'staff' => 1, 'student' => 2, 'alumni' => 3];
    foreach ($in as $f => $v) {
        $cur = $existing[$f] ?? null;
        if ($f === 'contact_type') {
            if ($overwrite || ($rank[$v] ?? 0) > ($rank[$cur] ?? 0)) {
                $set[$f] = $v;
            }
            continue;
        }
        if ($f === 'source') {
            continue; // the first source is kept
        }
        if ($overwrite || $cur === null || $cur === '' || ($f === 'name' && $cur === $existing['phone'])) {
            if ((string) $cur !== (string) $v) {
                $set[$f] = $v;
            }
        }
    }
    if ($optIn === true && (int) $existing['opt_in'] === 0 && ($overwrite || empty($existing['opted_out_at']))) {
        $set['opt_in'] = 1;
        $set['opt_in_at'] = $now;
        $set['opt_in_source'] = mb_substr((string) ($data['opt_in_source'] ?? ($opts['source'] ?? 'manual')), 0, 80);
        $set['opted_out_at'] = null;
        $set['interakt_synced_at'] = null;
    } elseif ($optIn === false && $overwrite && (int) $existing['opt_in'] === 1) {
        $set['opt_in'] = 0;
        $set['interakt_synced_at'] = null;
    }
    if ($set) {
        db_update('wa_contacts', $set, 'id = ?', [(int) $existing['id']]);
    }
    return (int) $existing['id'];
}

function wa_contact(int $id): ?array
{
    return $id > 0 ? db_row('SELECT * FROM wa_contacts WHERE id = ?', [$id]) : null;
}

function wa_contact_by_phone(string $phone): ?array
{
    $n = wa_normalize_phone($phone);
    return $n ? db_row('SELECT * FROM wa_contacts WHERE phone = ?', [$n['phone']]) : null;
}

/** Tag names of a contact. */
function wa_contact_tag_names(int $contactId): array
{
    return db_column('SELECT t.name FROM wa_contact_tags ct JOIN wa_tags t ON t.id = ct.tag_id WHERE ct.contact_id = ? ORDER BY t.name', [$contactId]);
}

/** The 24-hour customer-service window is open (last inbound message within 24 h). */
function wa_contact_window_open(array $contact): bool
{
    $exp = wa_contact_window_expires($contact);
    return $exp !== null && $exp > wa_now();
}

/** When the 24-hour window closes ('Y-m-d H:i:s'), null when the contact never wrote. */
function wa_contact_window_expires(array $contact): ?string
{
    $last = $contact['last_inbound_at'] ?? null;
    if (!$last) {
        return null;
    }
    $t = strtotime((string) $last);
    return $t ? date('Y-m-d H:i:s', $t + WA_WINDOW_SECONDS) : null;
}

/**
 * Move a contact to an admission follow-up stage (wa_followup_stages.stage_key). Fires WA_STAGE_CHANGED
 * {contact_id, from, to, reason, by, source, workflow_id, run_id}. $opts: reason, silent (no event), log (activity log,
 * default true when a user acts), source (workflow|event|bot|manual …), workflow_id, run_id (who moved the stage).
 */
function wa_contact_set_stage(int $contactId, string $stage, array $opts = []): void
{
    $stage = strtolower(trim($stage));
    if (!preg_match('/^[a-z0-9_]{2,30}$/', $stage)) {
        throw new InvalidArgumentException('Invalid stage.');
    }
    $c = wa_contact($contactId);
    if (!$c) {
        throw new InvalidArgumentException('WhatsApp contact not found.');
    }
    if ($c['stage'] === $stage) {
        return;
    }
    db_update('wa_contacts', ['stage' => $stage, 'stage_changed_at' => wa_now()], 'id = ?', [$contactId]);
    $detail = $c['stage'] . ' → ' . $stage . (!empty($opts['reason']) ? ' (' . mb_substr((string) $opts['reason'], 0, 120) . ')' : '');
    wa_agent_log('stage', null, $contactId, $detail);
    if (($opts['log'] ?? (wa_user_id() !== null)) && function_exists('log_activity')) {
        log_activity('update', 'whatsapp_contacts', $contactId, 'Moved WhatsApp contact ' . ($c['name'] ?: $c['phone']) . ' to stage ' . label_from_key($stage));
    }
    if (empty($opts['silent'])) {
        wa_event('WA_STAGE_CHANGED', [
            'contact_id' => $contactId, 'from' => $c['stage'], 'to' => $stage, 'reason' => $opts['reason'] ?? null, 'by' => wa_user_id(),
            'source' => isset($opts['source']) && is_scalar($opts['source']) && $opts['source'] !== '' ? mb_substr((string) $opts['source'], 0, 40) : null,
            'workflow_id' => !empty($opts['workflow_id']) ? (int) $opts['workflow_id'] : null,
            'run_id' => !empty($opts['run_id']) ? (int) $opts['run_id'] : null,
        ], ['source' => 'core', 'contact_id' => $contactId]);
    }
}

/** Tag ids for names (tags are created when missing). */
function wa_tag_ids(array $tagNames, bool $create = true): array
{
    $ids = [];
    foreach ($tagNames as $n) {
        $n = trim(mb_substr((string) $n, 0, 60));
        if ($n === '') {
            continue;
        }
        $id = db_value('SELECT id FROM wa_tags WHERE name = ?', [$n]);
        if (!$id && $create) {
            db_exec('INSERT IGNORE INTO wa_tags (name, color) VALUES (?, ?)', [$n, 'slate']);
            $id = db_value('SELECT id FROM wa_tags WHERE name = ?', [$n]);
        }
        if ($id) {
            $ids[] = (int) $id;
        }
    }
    return array_values(array_unique($ids));
}

function wa_contact_tag(int $contactId, array $tagNames): void
{
    foreach (wa_tag_ids($tagNames, true) as $tid) {
        db_exec('INSERT IGNORE INTO wa_contact_tags (contact_id, tag_id) VALUES (?, ?)', [$contactId, $tid]);
    }
}

function wa_contact_untag(int $contactId, array $tagNames): void
{
    $ids = wa_tag_ids($tagNames, false);
    if ($ids) {
        db_exec('DELETE FROM wa_contact_tags WHERE contact_id = ? AND tag_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([$contactId], $ids));
    }
}

/**
 * Opt a contact in / out of WhatsApp messages. Opt-out fires WA_OPTED_OUT, opt-in WA_OPTED_IN. The change is pushed
 * to Interakt (Track User trait whatsapp_opted_in) by the worker in live mode.
 */
function wa_contact_opt(int $contactId, bool $optIn, string $source): void
{
    $c = wa_contact($contactId);
    if (!$c) {
        throw new InvalidArgumentException('WhatsApp contact not found.');
    }
    $source = mb_substr($source !== '' ? $source : 'manual', 0, 80);
    if ($optIn) {
        if ((int) $c['opt_in'] === 1) {
            return;
        }
        db_update('wa_contacts', ['opt_in' => 1, 'opt_in_at' => wa_now(), 'opt_in_source' => $source, 'opted_out_at' => null, 'interakt_synced_at' => null], 'id = ?', [$contactId]);
        wa_agent_log('opt_in', null, $contactId, $source);
        wa_event('WA_OPTED_IN', ['contact_id' => $contactId, 'source' => $source], ['source' => 'core', 'contact_id' => $contactId]);
        return;
    }
    if ((int) $c['opt_in'] === 0 && !empty($c['opted_out_at'])) {
        return;
    }
    db_update('wa_contacts', ['opt_in' => 0, 'opted_out_at' => wa_now(), 'opt_in_source' => $source, 'interakt_synced_at' => null], 'id = ?', [$contactId]);
    wa_agent_log('opt_out', null, $contactId, $source);
    wa_event('WA_OPTED_OUT', ['contact_id' => $contactId, 'source' => $source], ['source' => 'core', 'contact_id' => $contactId]);
}

/** The contact's conversation (one per contact) — created when missing. */
function wa_conversation_for(int $contactId): array
{
    $row = db_row('SELECT * FROM wa_conversations WHERE contact_id = ?', [$contactId]);
    if ($row) {
        return $row;
    }
    db_exec('INSERT IGNORE INTO wa_conversations (contact_id, status, bot_active) VALUES (?, ?, ?)', [$contactId, 'open', 1]);
    $row = db_row('SELECT * FROM wa_conversations WHERE contact_id = ?', [$contactId]);
    if (!$row) {
        throw new RuntimeException('Could not create the WhatsApp conversation.');
    }
    return $row;
}

/* =================================================================================================================
 * Event bus
 * ================================================================================================================= */

/**
 * Register a listener for an event code ('GIMT_PAYMENT_SUCCESS', 'WA_MESSAGE_RECEIVED' …) or '*' for every event.
 * listener(array $event, array $payload, ?array $contact): ?array — return a short action summary (stored in
 * wa_event_logs.actions), e.g. ['type' => 'message', 'message_id' => 12]. Lower priority runs first.
 */
function wa_on(string $code, callable $listener, int $priority = 10): void
{
    $GLOBALS['__wa_listeners'][strtoupper($code) === '*' ? '*' : strtoupper($code)][] = ['fn' => $listener, 'priority' => $priority, 'seq' => count($GLOBALS['__wa_listeners'] ?? [], COUNT_RECURSIVE)];
}

/**
 * Register a worker tick handler: handler(float $budgetSeconds): array stats. Runs on every wa_worker_tick(), in
 * priority order (lower first; default from wa_tick_default_priority(), then registration order). Every handler gets a
 * guaranteed time slice, so one that overruns delays the others but cannot starve them.
 */
function wa_on_tick(string $name, callable $handler, ?int $priority = null): void
{
    $GLOBALS['__wa_tick_handlers'][$name] = $handler;
    $GLOBALS['__wa_tick_priorities'][$name] = $priority ?? wa_tick_default_priority($name);
}

/** Default run order of the known tick handlers: business events and workflows first, bulk / housekeeping work last. */
function wa_tick_default_priority(string $name): int
{
    return [
        'events' => 10,          // bulk event fan-out jobs + daily scanners (time-critical business messages)
        'workflows' => 20,       // due workflow steps
        'crm_followups' => 30,   // due admission follow-ups
        'campaigns' => 40,       // campaign batches
        'admin' => 60,           // digests, notifications
        'templates_sync' => 70,  // hourly template status sync
        'crm_sync' => 90,        // ERP → CRM contact sync (large, resumable from its cursor)
    ][$name] ?? 50;
}

/** Registered tick handlers in run order (priority, then registration order): name => callable. */
function wa_tick_handlers(): array
{
    $handlers = $GLOBALS['__wa_tick_handlers'] ?? [];
    $seq = array_flip(array_keys($handlers));
    $names = array_keys($handlers);
    usort($names, fn ($a, $b) => [$GLOBALS['__wa_tick_priorities'][$a] ?? 50, $seq[$a]] <=> [$GLOBALS['__wa_tick_priorities'][$b] ?? 50, $seq[$b]]);
    $out = [];
    foreach ($names as $n) {
        $out[$n] = $handlers[$n];
    }
    return $out;
}

/** Listeners for a code ('*' listeners included), sorted by priority. */
function wa_listeners(string $code): array
{
    $all = array_merge($GLOBALS['__wa_listeners'][strtoupper($code)] ?? [], $GLOBALS['__wa_listeners']['*'] ?? []);
    usort($all, fn ($a, $b) => [$a['priority'], $a['seq']] <=> [$b['priority'], $b['seq']]);
    return $all;
}

/** Internal event codes whose occurrences are not stored in wa_event_logs (high volume). */
function wa_unlogged_events(): array
{
    return ['WA_MESSAGE_STATUS'];
}

/**
 * Raise an event. NEVER throws. Logs a wa_event_logs row (dedupe by $opts['dedupe_key']), resolves / creates the
 * contact from the payload (contact_id | phone / mobile / whatsapp + name, email, student_id, enquiry_id, admission_id,
 * program_id, application_no …) and dispatches the listeners registered with wa_on().
 * $opts: dedupe_key, source (website|admission_portal|payment|admin|api|webhook|js|cron|worker|core; default 'worker'
 *        while background work runs, 'admin' when a staff user is logged in, else 'core'), contact_id,
 *        log (bool, default true except WA_MESSAGE_STATUS), create_contact (bool, default true).
 * Returns the event log id, or null for a duplicate, an unlogged event or a failure.
 */
function wa_event(string $code, array $payload = [], array $opts = []): ?int
{
    static $depth = 0;
    if ($depth > 6) {
        error_log('[GIMT] wa_event: recursion limit reached for ' . $code);
        return null;
    }
    $depth++;
    $logId = null;
    try {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9_]{2,80}$/', $code)) {
            return null;
        }
        $source = mb_substr((string) ($opts['source'] ?? ($payload['_source'] ?? (wa_in_worker() ? 'worker' : (wa_user_id() ? 'admin' : 'core')))), 0, 30);
        $log = $opts['log'] ?? !in_array($code, wa_unlogged_events(), true);
        $dedupe = isset($opts['dedupe_key']) && $opts['dedupe_key'] !== '' ? mb_substr($code . ':' . (string) $opts['dedupe_key'], 0, 190) : null;

        // contact
        $contact = null;
        try {
            $contact = wa_event_resolve_contact($payload, $opts);
        } catch (Throwable $e) {
            $contact = null;
        }

        if ($log) {
            try {
                $logId = db_insert('wa_event_logs', [
                    'event_code' => $code,
                    'source' => $source,
                    'payload' => wa_json(wa_event_redact($payload)),
                    'dedupe_key' => $dedupe,
                    'contact_id' => $contact['id'] ?? null,
                    'status' => 'received',
                ]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000' && $dedupe !== null) {
                    return null; // duplicate event
                }
                throw $e;
            }
        }
        $event = ['id' => $logId, 'event_code' => $code, 'source' => $source, 'dedupe_key' => $dedupe, 'contact_id' => $contact['id'] ?? null,
            'created_at' => wa_now(), 'opts' => $opts];

        $actions = [];
        $errors = [];
        foreach (wa_listeners($code) as $l) {
            try {
                $r = ($l['fn'])($event, $payload, $contact);
                if (is_array($r) && $r) {
                    $actions[] = $r;
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                error_log('[GIMT] wa_event listener for ' . $code . ' failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            }
        }
        if ($logId) {
            db_update('wa_event_logs', [
                'status' => $errors && !$actions ? 'failed' : ($actions ? 'processed' : 'skipped'),
                'actions' => $actions ? wa_json($actions) : null,
                'error' => $errors ? mb_substr(implode(' | ', $errors), 0, 2000) : null,
                'processed_at' => wa_now(),
            ], 'id = ?', [$logId]);
        }
        return $logId;
    } catch (Throwable $e) {
        error_log('[GIMT] wa_event(' . $code . ') failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        return $logId;
    } finally {
        $depth--;
    }
}

/** Remove secrets from an event payload before it is stored. */
function wa_event_redact(array $payload): array
{
    foreach ($payload as $k => $v) {
        if (is_string($k) && preg_match('/(password|secret|api_key|token|cvv|card_number)/i', $k)) {
            $payload[$k] = '***';
        } elseif (is_array($v)) {
            $payload[$k] = wa_event_redact($v);
        }
    }
    return $payload;
}

/** Find / create the contact an event is about. */
function wa_event_resolve_contact(array $payload, array $opts = []): ?array
{
    $cid = (int) ($opts['contact_id'] ?? ($payload['contact_id'] ?? 0));
    if ($cid > 0) {
        return wa_contact($cid);
    }
    $phone = null;
    foreach (['phone', 'mobile', 'whatsapp', 'phone_number', 'contact_phone', 'student_phone'] as $k) {
        if (!empty($payload[$k]) && is_scalar($payload[$k])) {
            $phone = (string) $payload[$k];
            if (wa_normalize_phone($phone)) {
                break;
            }
            $phone = null;
        }
    }
    if ($phone === null) {
        foreach (['student_id' => 'student_id', 'enquiry_id' => 'enquiry_id', 'admission_id' => 'admission_id'] as $k => $col) {
            if (!empty($payload[$k]) && is_numeric($payload[$k])) {
                $c = db_row('SELECT * FROM wa_contacts WHERE ' . $col . ' = ? ORDER BY id LIMIT 1', [(int) $payload[$k]]);
                if ($c) {
                    return $c;
                }
            }
        }
        return null;
    }
    if (($opts['create_contact'] ?? true) === false) {
        return wa_contact_by_phone($phone);
    }
    $data = ['phone' => $phone];
    $map = ['name' => ['name', 'student_name', 'full_name', 'contact_name'], 'email' => ['email'], 'student_id' => ['student_id'], 'enquiry_id' => ['enquiry_id'],
        'admission_id' => ['admission_id'], 'program_id' => ['program_id'], 'program_name' => ['program_name', 'course_name', 'program'],
        'application_no' => ['application_no', 'application_number'], 'city' => ['city'], 'state' => ['state'], 'contact_type' => ['contact_type'],
        'counsellor_id' => ['counsellor_id'], 'opt_in' => ['opt_in', 'whatsapp_opt_in', 'consent']];
    foreach ($map as $field => $keys) {
        foreach ($keys as $k) {
            if (isset($payload[$k]) && $payload[$k] !== '' && is_scalar($payload[$k])) {
                $data[$field] = $payload[$k];
                break;
            }
        }
    }
    if (!isset($data['contact_type'])) {
        $data['contact_type'] = !empty($data['student_id']) ? 'student' : (!empty($data['admission_id']) ? 'applicant' : 'lead');
    }
    $id = wa_contact_upsert($data, ['source' => $opts['source'] ?? 'event']);
    return wa_contact($id);
}

/** Inbox / team activity trail (wa_agent_activity). Never throws. */
function wa_agent_log(string $action, ?int $conversationId, ?int $contactId, ?string $detail = null): void
{
    try {
        db_insert('wa_agent_activity', ['user_id' => wa_user_id(), 'conversation_id' => $conversationId, 'contact_id' => $contactId,
            'action' => mb_substr($action, 0, 40), 'detail' => $detail !== null ? mb_substr($detail, 0, 255) : null]);
    } catch (Throwable $e) {
        error_log('[GIMT] wa_agent_log failed: ' . $e->getMessage());
    }
}

/* =================================================================================================================
 * Templates + variables
 * ================================================================================================================= */

/** Decode the JSON columns of a wa_templates row. */
function wa_template_decode(array $t): array
{
    $t['buttons'] = wa_json_decode($t['buttons'] ?? null, []) ?: [];
    $t['variables'] = wa_json_decode($t['variables'] ?? null, []) ?: [];
    $t['otp_settings'] = wa_json_decode($t['otp_settings'] ?? null, null);
    return $t;
}

/**
 * Find a template by id or code name. By name the choice is, in order: an approved row in the requested language, an
 * approved row in the default language (wa_default_language), then — outside live mode only — a sendable row (not
 * archived / disabled / rejected) in the requested, then the default language, and finally any row (requested language,
 * default language, approved, newest first) so a refusal can name the row that blocks the send. A draft / pending
 * Hindi copy therefore never hides the approved English template.
 * JSON columns (buttons, variables, otp_settings) are decoded.
 */
function wa_template_find($idOrName, ?string $language = null): ?array
{
    if (is_array($idOrName)) {
        return isset($idOrName['body']) ? wa_template_decode($idOrName) : (isset($idOrName['id']) ? wa_template_find((int) $idOrName['id']) : null);
    }
    if (is_int($idOrName) || (is_string($idOrName) && ctype_digit($idOrName))) {
        $row = db_row('SELECT * FROM wa_templates WHERE id = ?', [(int) $idOrName]);
        return $row ? wa_template_decode($row) : null;
    }
    $name = trim((string) $idOrName);
    if ($name === '') {
        return null;
    }
    $default = (string) wa_setting('wa_default_language', 'en');
    $language = trim((string) $language) !== '' ? trim((string) $language) : $default;
    $order = "(status = 'approved' AND language = ?) DESC, (status = 'approved' AND language = ?) DESC";
    $args = [$name, $language, $default];
    if (wa_mode() !== 'live') {
        $sendable = "status NOT IN ('archived','disabled','rejected')";
        $order .= ", ($sendable AND language = ?) DESC, ($sendable AND language = ?) DESC";
        array_push($args, $language, $default);
    }
    $order .= ", (language = ?) DESC, (language = ?) DESC, (status = 'approved') DESC, id DESC";
    array_push($args, $language, $default);
    $row = db_row('SELECT * FROM wa_templates WHERE name = ? ORDER BY ' . $order . ' LIMIT 1', $args);
    return $row ? wa_template_decode($row) : null;
}

/** Highest positional placeholder {{n}} in a text (0 when none). */
function wa_placeholder_count(?string $text): int
{
    if (!$text || !preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $m)) {
        return 0;
    }
    return max(array_map('intval', $m[1]));
}

/** Replace {{n}} in a text with values (1-based); unknown positions stay as-is. */
function wa_fill_positional(string $text, array $values): string
{
    return (string) preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', function ($m) use ($values) {
        $i = (int) $m[1] - 1;
        return array_key_exists($i, $values) && $values[$i] !== null && $values[$i] !== '' ? (string) $values[$i] : $m[0];
    }, $text);
}

/** Variable slots of a template: header (count), body (count), buttons (index => type) needing a value. */
function wa_template_slots(array $tpl): array
{
    $tpl = isset($tpl['buttons']) && is_string($tpl['buttons']) ? wa_template_decode($tpl) : $tpl;
    $header = 0;
    $ht = $tpl['header_type'] ?? 'none';
    if ($ht === 'text') {
        $header = wa_placeholder_count($tpl['header_text'] ?? '');
    } elseif (in_array($ht, ['image', 'video', 'document'], true)) {
        $header = 1;
    }
    $buttons = [];
    foreach (array_values($tpl['buttons'] ?? []) as $i => $b) {
        $type = strtoupper((string) ($b['type'] ?? ''));
        if ($type === 'URL' && wa_placeholder_count($b['url'] ?? '') > 0) {
            $buttons[$i] = 'URL';
        } elseif (in_array($type, ['COPY_CODE', 'OTP'], true)) {
            $buttons[$i] = $type;
        }
    }
    return ['header' => $header, 'header_type' => $ht, 'body' => wa_placeholder_count($tpl['body'] ?? ''), 'buttons' => $buttons];
}

/** True when $vars is positional ({header:[], body:[], buttons:{}} or a plain list of body values). */
function wa_vars_positional(array $vars): bool
{
    if (isset($vars['body']) && is_array($vars['body'])) {
        return true;
    }
    if ((isset($vars['header']) && is_array($vars['header'])) || (isset($vars['buttons']) && is_array($vars['buttons']))) {
        return true;
    }
    return $vars !== [] && array_is_list($vars);
}

/**
 * Render a template with variables.
 * $vars: named (student_name => 'Rahul', …; mapped through wa_templates.variables) or positional
 *        (['header' => [], 'body' => [], 'buttons' => [index => [value]]] or a plain list of body values).
 *        Extra keys: header_media_url / media_url (media header), file_name (document header), payloads (quick reply
 *        payloads by button index), _use_samples (fill gaps with the template samples).
 * Dynamic URL buttons: a value that starts with the button's fixed base URL keeps only the suffix; a value that is still
 * an absolute URL is left out and explained in `invalid` (wa_send() then refuses with invalid_message).
 * @return array{header:array,body:array,buttons:array,text:string,missing:array,invalid:array,header_text:?string,media_url:?string,file_name:?string,payloads:array}
 */
function wa_render_template(array $tpl, array $vars): array
{
    if (isset($tpl['buttons']) && is_string($tpl['buttons'])) {
        $tpl = wa_template_decode($tpl);
    }
    $slots = wa_template_slots($tpl);
    $map = is_array($tpl['variables'] ?? null) ? $tpl['variables'] : [];
    $useSamples = !empty($vars['_use_samples']);
    $positional = wa_vars_positional(array_diff_key($vars, array_flip(['header_media_url', 'media_url', 'file_name', 'payloads', '_use_samples'])));
    $missing = [];
    $invalid = []; // values that can never be sent (e.g. a full URL in a dynamic URL button) — wa_send refuses invalid_message
    $str = fn ($v) => is_scalar($v) ? trim((string) $v) : '';

    $mapFor = function (string $section, int $pos) use ($map): array {
        foreach ((array) ($map[$section] ?? []) as $ref) {
            if ((int) ($ref['pos'] ?? 0) === $pos) {
                return ['key' => (string) ($ref['key'] ?? ''), 'sample' => (string) ($ref['sample'] ?? '')];
            }
        }
        return ['key' => '', 'sample' => ''];
    };
    $named = function (string $section, int $pos) use ($vars, $mapFor, $str, $useSamples): array {
        $ref = $mapFor($section, $pos);
        $v = '';
        if ($ref['key'] !== '' && isset($vars[$ref['key']])) {
            $v = $str($vars[$ref['key']]);
        }
        if ($v === '' && isset($vars[$section . '_' . $pos])) {
            $v = $str($vars[$section . '_' . $pos]);
        }
        if ($v === '' && $section === 'body' && isset($vars[(string) $pos])) {
            $v = $str($vars[(string) $pos]);
        }
        if ($v === '' && $useSamples) {
            $v = $ref['sample'];
        }
        return [$v, $ref['key']];
    };

    // body
    $body = [];
    $list = $positional ? (isset($vars['body']) && is_array($vars['body']) ? array_values($vars['body']) : (array_is_list($vars) ? $vars : [])) : [];
    for ($p = 1; $p <= $slots['body']; $p++) {
        if ($positional) {
            $v = $str($list[$p - 1] ?? '');
            $key = $mapFor('body', $p)['key'];
            if ($v === '' && $useSamples) {
                $v = $mapFor('body', $p)['sample'];
            }
        } else {
            [$v, $key] = $named('body', $p);
        }
        $body[] = $v;
        if ($v === '') {
            $missing[] = $key !== '' ? $key : 'body {{' . $p . '}}';
        }
    }

    // header
    $header = [];
    $headerText = null;
    $mediaUrl = null;
    $fileName = $str($vars['file_name'] ?? '') ?: null;
    if ($slots['header_type'] === 'text') {
        for ($p = 1; $p <= $slots['header']; $p++) {
            if ($positional) {
                $v = $str(($vars['header'] ?? [])[$p - 1] ?? '');
                $key = $mapFor('header', $p)['key'];
                if ($v === '' && $useSamples) {
                    $v = $mapFor('header', $p)['sample'];
                }
            } else {
                [$v, $key] = $named('header', $p);
            }
            $header[] = $v;
            if ($v === '') {
                $missing[] = $key !== '' ? $key : 'header {{' . $p . '}}';
            }
        }
        $headerText = $tpl['header_text'] ? wa_fill_positional((string) $tpl['header_text'], $header) : null;
    } elseif (in_array($slots['header_type'], ['image', 'video', 'document'], true)) {
        $mediaUrl = $str($vars['header_media_url'] ?? ($vars['media_url'] ?? '')) ?: ($positional ? $str(($vars['header'] ?? [])[0] ?? '') : '');
        if ($mediaUrl === '' && !$positional) {
            [$mv] = $named('header', 1);
            $mediaUrl = $mv;
        }
        $mediaUrl = $mediaUrl ?: (string) ($tpl['header_media_url'] ?? '');
        if ($mediaUrl === '') {
            $missing[] = 'header_media';
        } else {
            $header = [$mediaUrl];
        }
        if ($slots['header_type'] === 'document' && !$fileName) {
            $fileName = basename((string) parse_url($mediaUrl, PHP_URL_PATH)) ?: 'document.pdf';
        }
    }

    // buttons
    $buttons = [];
    foreach ($slots['buttons'] as $idx => $type) {
        $v = '';
        $key = '';
        if ($positional) {
            $bv = ($vars['buttons'] ?? [])[$idx] ?? (($vars['buttons'] ?? [])[(string) $idx] ?? null);
            $v = $str(is_array($bv) ? ($bv[0] ?? '') : $bv);
        } else {
            $ref = $map['buttons'][$idx] ?? ($map['buttons'][(string) $idx] ?? null);
            $key = is_array($ref) ? (string) ($ref['key'] ?? '') : '';
            if ($key !== '' && isset($vars[$key])) {
                $v = $str($vars[$key]);
            }
            if ($v === '' && isset($vars['button_' . $idx])) {
                $v = $str($vars['button_' . $idx]);
            }
            if ($v === '' && $useSamples && is_array($ref)) {
                $v = (string) ($ref['sample'] ?? '');
            }
        }
        if ($v === '' && in_array($type, ['COPY_CODE', 'OTP'], true) && ($tpl['category'] ?? '') === 'AUTHENTICATION') {
            $v = $body[0] ?? ''; // OTP templates repeat the code on the button
        }
        if ($v === '' && $useSamples) {
            $b = array_values($tpl['buttons'] ?? [])[$idx] ?? [];
            $v = (string) ($b['example'] ?? 'sample');
        }
        if ($v !== '' && $type === 'URL') {
            // Meta appends the value to the button's fixed base (https://site/x/{{1}}): a full link that repeats the base
            // keeps only its suffix; any other absolute URL would build a doubled, broken link, so the send is refused
            $b = array_values($tpl['buttons'] ?? [])[$idx] ?? [];
            $base = (string) strstr((string) ($b['url'] ?? ''), '{{', true);
            if ($base !== '' && strncasecmp($v, $base, strlen($base)) === 0) {
                $v = trim(substr($v, strlen($base)));
            }
            if ($v !== '' && preg_match('#^([a-z][a-z0-9+.\-]*:)?//#i', $v)) {
                $label = trim((string) ($b['text'] ?? '')) ?: 'button ' . ($idx + 1);
                $invalid[] = 'The "' . $label . '" button link ends in a variable' . ($key !== '' ? ' ({{' . $key . '}})' : '') . ' whose value is a full web address ('
                    . mb_substr($v, 0, 80) . '). WhatsApp adds the value after ' . ($base !== '' ? $base : 'the fixed link') . ', so the button would open a broken link. '
                    . 'Map a value that only completes the link (for example a receipt number), or give the button a fixed link.';
                $v = '';
                continue;
            }
        }
        if ($v === '') {
            $missing[] = $key !== '' ? $key : 'button ' . ($idx + 1);
        } else {
            $buttons[(string) $idx] = [$v];
        }
    }
    $payloads = [];
    foreach ((array) ($vars['payloads'] ?? []) as $idx => $pv) {
        $pv = is_array($pv) ? ($pv[0] ?? '') : $pv;
        if (is_scalar($pv) && trim((string) $pv) !== '') {
            $payloads[(string) $idx] = [mb_substr(trim((string) $pv), 0, 128)];
        }
    }

    return [
        'header' => $header,
        'body' => $body,
        'buttons' => $buttons,
        'text' => wa_fill_positional((string) ($tpl['body'] ?? ''), $body),
        'missing' => array_values(array_unique($missing)),
        'invalid' => $invalid,
        'header_text' => $headerText,
        'media_url' => $mediaUrl ?: null,
        'file_name' => $fileName,
        'payloads' => $payloads,
    ];
}

/** Institute support number for {{support_number}}: the WhatsApp business number, else Settings › phone ('+91 90600 31540'). */
function wa_support_number(): string
{
    $biz = preg_replace('/\D+/', '', (string) wa_setting('wa_business_number', ''));
    if ($biz !== '' && ($n = wa_normalize_phone('+' . $biz))) {
        return $n['country_code'] === '+91' && strlen($n['national']) === 10
            ? '+91 ' . substr($n['national'], 0, 5) . ' ' . substr($n['national'], 5)
            : $n['country_code'] . ' ' . $n['national'];
    }
    return trim((string) (setting('phone') ?: setting('whatsapp_number') ?: ''));
}

/**
 * {{brochure_link}}: the programme's own brochure file, else the admin-set wa_brochure_url, else the programme page,
 * else the programmes listing (all real pages of the website — never a dead /brochure link).
 * $program: a programs row (id, slug, brochure) or null.
 */
function wa_brochure_link(?array $program): string
{
    $abs = fn (string $p) => preg_match('#^https?://#i', $p) ? $p : absolute_url(ltrim($p, '/'));
    if ($program && !empty($program['brochure'])) {
        return $abs((string) $program['brochure']);
    }
    $custom = trim((string) wa_setting('wa_brochure_url', ''));
    if ($custom !== '' && preg_match('#^(https?://|/)#i', $custom)) {
        return $abs($custom);
    }
    if ($program && !empty($program['slug'])) {
        return absolute_url('programs/' . rawurlencode((string) $program['slug']));
    }
    return absolute_url('programs');
}

/** GIMT variable catalog: key => [label, sample, group]. Used by the designer chips, event / campaign mapping. */
function wa_variable_catalog(): array
{
    $inst = function_exists('institute_name') ? institute_name() : 'GIMT';
    $v = [
        // contact
        'name' => ['Contact name', 'Rahul Sharma', 'Contact'],
        'first_name' => ['First name', 'Rahul', 'Contact'],
        'phone' => ['Mobile number', '+91 98765 43210', 'Contact'],
        'email' => ['Email', 'rahul.sharma@example.com', 'Contact'],
        'city' => ['City', 'Gurugram', 'Contact'],
        // student
        'student_name' => ['Student name', 'Rahul Sharma', 'Student'],
        'student_first_name' => ['Student first name', 'Rahul', 'Student'],
        'admission_no' => ['Admission number', 'GIMT/2026/0142', 'Student'],
        'roll_no' => ['Roll number', 'BCA-26-042', 'Student'],
        'enrollment_no' => ['Enrollment number', 'EN2026BCA042', 'Student'],
        'course_name' => ['Course / programme', 'Bachelor of Computer Applications', 'Student'],
        'program_short' => ['Programme short name', 'BCA', 'Student'],
        'batch' => ['Batch', '2026-29', 'Student'],
        'semester' => ['Semester', '3', 'Student'],
        'department' => ['Department', 'Computer Applications', 'Student'],
        'guardian_name' => ['Parent / guardian name', 'Suresh Sharma', 'Student'],
        // admission
        'application_number' => ['Application number', 'APP/2026-27/00301', 'Admission'],
        'enquiry_no' => ['Enquiry number', '#142', 'Admission'],
        'application_status' => ['Application status', 'Document verification', 'Admission'],
        'admission_stage' => ['Follow-up stage', 'Interested', 'Admission'],
        'counsellor_name' => ['Counsellor name', 'Priya Verma', 'Admission'],
        'counsellor_phone' => ['Counsellor phone', '+91 98100 12345', 'Admission'],
        'counselling_date' => ['Counselling date', '12 Nov 2026', 'Admission'],
        'counselling_time' => ['Counselling time', '11:30 AM', 'Admission'],
        'documents_pending' => ['Pending documents', '12th marksheet, ID proof', 'Admission'],
        'admission_link' => ['Application / portal link', 'https://gimtedu.com/apply', 'Admission'],
        // fees
        'amount' => ['Amount', '₹25,000', 'Fees'],
        'due_amount' => ['Amount due', '₹18,500', 'Fees'],
        'due_date' => ['Due date', '15 Nov 2026', 'Fees'],
        'receipt_no' => ['Receipt number', 'RCPT/2026-27/000123', 'Fees'],
        'invoice_no' => ['Invoice number', 'INV/2026-27/000456', 'Fees'],
        'payment_mode' => ['Payment mode', 'UPI', 'Fees'],
        'payment_link' => ['Payment link', 'https://gimtedu.com/pay/ABC123', 'Fees'],
        'receipt_link' => ['Receipt link', 'https://gimtedu.com/verify-receipt/RCPT-123', 'Fees'],
        // exams
        'exam_name' => ['Exam name', 'Semester 3 End-Term', 'Exams'],
        'exam_date' => ['Exam date', '02 Dec 2026', 'Exams'],
        'admit_card_link' => ['Admit card link', 'https://gimtedu.com/portal/admit-card', 'Exams'],
        'result_link' => ['Result link', 'https://gimtedu.com/portal/results', 'Exams'],
        'certificate_no' => ['Certificate number', 'GIMT/CERT/2026/0042', 'Exams'],
        'certificate_link' => ['Certificate link', 'https://gimtedu.com/verify-certificate/0042', 'Exams'],
        // institute
        'institute_name' => ['Institute name', $inst, 'Institute'],
        'support_number' => ['Support / helpline number', wa_support_number() ?: '+91 90600 31540', 'Institute'],
        'institute_phone' => ['Institute phone', (string) (setting('phone') ?: '+91 1234 567890'), 'Institute'],
        'institute_email' => ['Institute email', (string) (setting('email') ?: 'info@gimtedu.com'), 'Institute'],
        'website_url' => ['Website', absolute_url(''), 'Institute'],
        'website_link' => ['Website link', absolute_url(''), 'Institute'],
        'apply_link' => ['Apply online link', absolute_url('apply'), 'Institute'],
        'brochure_link' => ['Brochure / programme link', wa_brochure_link(null), 'Institute'],
        'academic_session' => ['Academic session', '2026-27', 'Institute'],
        'date_today' => ['Today\'s date', date('d M Y'), 'Institute'],
        // misc
        'otp' => ['One-time code (OTP)', '482913', 'Other'],
        'event_name' => ['Event name', 'Annual Tech Fest', 'Other'],
        'event_date' => ['Event date', '20 Nov 2026', 'Other'],
        'ticket_no' => ['Support ticket number', 'TKT-2026-00042', 'Other'],
        'link' => ['Any link', 'https://gimtedu.com', 'Other'],
    ];
    // keys of the client brief / the built-in template library (values come from the event payload, the campaign
    // mapping or — for course_duration, admission_status, payment_status — from the contact's linked records)
    $v += [
        'course_duration' => ['Course duration', '3 Years', 'Student'],
        'admission_status' => ['Admission status', 'Confirmed', 'Admission'],
        'last_date' => ['Last date', '30 Nov 2026', 'Admission'],
        'document_list' => ['Documents list', '12th marksheet, Aadhaar card', 'Admission'],
        'fee_amount' => ['Fee amount', '₹45,000', 'Fees'],
        'payment_status' => ['Payment status', 'Paid', 'Fees'],
        'payment_date' => ['Payment date', '03 Nov 2026', 'Fees'],
        'receipt_number' => ['Receipt number', 'RCPT/2026-27/000123', 'Fees'],
        'transaction_id' => ['Transaction ID', 'TXN8812345678', 'Fees'],
        'refund_amount' => ['Refund amount', '₹5,000', 'Fees'],
        'class_date' => ['Class date', '10 Nov 2026', 'Academics'],
        'class_time' => ['Class time', '10:00 AM', 'Academics'],
        'subject_name' => ['Subject', 'Business Communication', 'Academics'],
        'marksheet_link' => ['Marksheet link', 'https://gimtedu.com/portal/marksheets', 'Exams'],
        'event_venue' => ['Event venue', 'GIMT Auditorium', 'Other'],
        'ticket_number' => ['Ticket number', 'TKT-2026-00042', 'Other'],
        'notice_title' => ['Notice title', 'Winter break schedule', 'Other'],
        'notice_link' => ['Notice link', 'https://gimtedu.com/notices', 'Other'],
    ];
    $out = [];
    foreach ($v as $k => [$label, $sample, $group]) {
        $out[$k] = ['key' => $k, 'label' => $label, 'sample' => $sample, 'group' => $group];
    }
    return $out;
}

/**
 * The enquiry number staff see for an ERP enquiry: the row's own number column when the schema has one
 * (enquiry_no / reference_no), else "#<id>" — GIMT's enquiries table has no number column and the ERP lists enquiries
 * as "Enquiry #<id>"; the same form wa_crm_enquiry_no() gives the Create / Link enquiry step, so {{enquiry_no}} reads the
 * same whichever path filled it. Accepts an enquiries row or an id.
 */
function wa_enquiry_no($enquiry): string
{
    if (is_array($enquiry)) {
        foreach (['enquiry_no', 'reference_no'] as $col) {
            if (isset($enquiry[$col]) && trim((string) $enquiry[$col]) !== '') {
                return trim((string) $enquiry[$col]);
            }
        }
        $id = (int) ($enquiry['id'] ?? 0);
    } else {
        $id = (int) $enquiry;
    }
    if ($id <= 0) {
        return '';
    }
    return function_exists('wa_crm_enquiry_no') ? wa_crm_enquiry_no($id) : '#' . $id;
}

/**
 * Named variable values for a contact, resolved from the CRM row and the linked student / admission / enquiry /
 * counsellor rows, plus institute values. $extra (e.g. an event payload) overrides. All values are strings.
 * Linked enquiry: enquiry_id and enquiry_no (wa_enquiry_no(); also resolved from $extra['enquiry_id']).
 */
function wa_variables_for_contact(array $contact, array $extra = []): array
{
    $v = [];
    $set = function (string $k, $val) use (&$v) {
        if ($val !== null && $val !== '' && is_scalar($val) && !isset($v[$k])) {
            $v[$k] = trim((string) $val);
        }
    };
    // institute
    $set('institute_name', function_exists('institute_name') ? institute_name() : 'GIMT');
    $set('support_number', wa_support_number());
    $set('institute_phone', setting('phone'));
    $set('institute_email', setting('email'));
    $set('website_url', absolute_url(''));
    $set('website_link', absolute_url(''));
    $set('apply_link', absolute_url('apply'));
    $set('admission_link', absolute_url('apply'));
    $set('date_today', date('d M Y'));
    $program = null; // programs row of the contact (brochure link, duration)
    try {
        $set('academic_session', db_value('SELECT name FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1'));
    } catch (Throwable $e) {
    }

    // contact
    $name = trim((string) ($contact['name'] ?? ''));
    $first = $name !== '' ? explode(' ', $name)[0] : '';
    $set('name', $name);
    $set('first_name', $first);
    $set('phone', isset($contact['phone']) ? '+' . $contact['phone'] : null);
    $set('email', $contact['email'] ?? null);
    $set('city', $contact['city'] ?? null);
    $set('course_name', $contact['program_name'] ?? null);
    $set('batch', $contact['batch'] ?? null);
    $set('application_number', $contact['application_no'] ?? null);
    $set('admission_status', !empty($contact['admission_status']) ? label_from_key((string) $contact['admission_status']) : null);
    $set('payment_status', !empty($contact['payment_status']) ? label_from_key((string) $contact['payment_status']) : null);
    if (!empty($contact['stage'])) {
        $stageName = null;
        try {
            $stageName = db_value('SELECT name FROM wa_followup_stages WHERE stage_key = ?', [$contact['stage']]);
        } catch (Throwable $e) {
        }
        $set('admission_stage', $stageName ?: label_from_key((string) $contact['stage']));
    }

    try {
        if (!empty($contact['student_id'])) {
            $s = db_row('SELECT s.*, p.name AS program_name, p.short_name AS program_short, d.name AS department_name, b.name AS batch_name
                FROM students s LEFT JOIN programs p ON p.id = s.program_id LEFT JOIN departments d ON d.id = s.department_id
                LEFT JOIN batches b ON b.id = s.batch_id WHERE s.id = ?', [(int) $contact['student_id']]);
            if ($s && !empty($s['program_id'])) {
                $program = db_row('SELECT id, slug, brochure, duration_label, duration_years FROM programs WHERE id = ?', [(int) $s['program_id']]);
            }
            if ($s) {
                $full = trim(implode(' ', array_filter([$s['first_name'] ?? '', $s['middle_name'] ?? '', $s['last_name'] ?? ''])));
                $set('student_name', $full);
                $set('student_first_name', $s['first_name'] ?? null);
                $set('admission_no', $s['admission_no'] ?? null);
                $set('roll_no', $s['roll_no'] ?? null);
                $set('enrollment_no', $s['enrollment_no'] ?? null);
                $set('course_name', $s['program_name'] ?? null);
                $set('program_short', $s['program_short'] ?? null);
                $set('department', $s['department_name'] ?? null);
                $set('batch', $s['batch_name'] ?? null);
                $set('semester', $s['current_semester'] ?? null);
                $set('guardian_name', $s['guardian_name'] ?? ($s['father_name'] ?? null));
                $set('city', $s['city'] ?? null);
                $due = db_row("SELECT SUM(balance_amount) AS due, MIN(CASE WHEN balance_amount > 0 THEN due_date END) AS next_due FROM student_fees
                    WHERE student_id = ? AND status IN ('pending','partial','overdue')", [(int) $s['id']]);
                if ($due && (float) $due['due'] > 0) {
                    $dueText = function_exists('money') ? money($due['due']) : '₹' . number_format((float) $due['due'], 2);
                    $set('due_amount', $dueText);
                    // the built-in fee templates (fee_due_reminder, admission_approved …) map {{fee_amount}}: the outstanding
                    // balance unless the event payload / campaign mapping passes its own fee_amount ($extra wins)
                    $set('fee_amount', $dueText);
                    $set('due_date', $due['next_due'] ? date('d M Y', strtotime((string) $due['next_due'])) : null);
                }
            }
        }
        if (!empty($contact['admission_id'])) {
            $a = db_row('SELECT a.*, p.name AS program_name, p.short_name AS program_short FROM admissions a LEFT JOIN programs p ON p.id = a.program_id WHERE a.id = ?', [(int) $contact['admission_id']]);
            if ($a) {
                if (!$program && !empty($a['program_id'])) {
                    $program = db_row('SELECT id, slug, brochure, duration_label, duration_years FROM programs WHERE id = ?', [(int) $a['program_id']]);
                }
                $set('student_name', trim(implode(' ', array_filter([$a['first_name'] ?? '', $a['middle_name'] ?? '', $a['last_name'] ?? '']))));
                $set('student_first_name', $a['first_name'] ?? null);
                $set('application_number', $a['application_no'] ?? null);
                $set('application_status', label_from_key((string) ($a['stage'] ?? '')));
                $set('admission_status', !empty($a['stage']) ? label_from_key((string) $a['stage']) : null);
                $set('course_name', $a['program_name'] ?? null);
                $set('program_short', $a['program_short'] ?? null);
                $set('guardian_name', $a['guardian_name'] ?? ($a['father_name'] ?? null));
                if (empty($contact['counsellor_id']) && !empty($a['assigned_to'])) {
                    $contact['counsellor_id'] = $a['assigned_to'];
                }
            }
        }
        if (!empty($contact['enquiry_id'])) {
            $en = db_row('SELECT e.*, p.name AS program_name FROM enquiries e LEFT JOIN programs p ON p.id = e.program_id WHERE e.id = ?', [(int) $contact['enquiry_id']]);
            if ($en) {
                if (!$program && !empty($en['program_id'])) {
                    $program = db_row('SELECT id, slug, brochure, duration_label, duration_years FROM programs WHERE id = ?', [(int) $en['program_id']]);
                }
                $set('enquiry_id', (string) (int) $en['id']);
                $set('enquiry_no', wa_enquiry_no($en));
                $set('student_name', $en['name'] ?? null);
                $set('course_name', $en['program_name'] ?: ($en['program_interest'] ?? null));
                if (empty($contact['counsellor_id']) && !empty($en['assigned_to'])) {
                    $contact['counsellor_id'] = $en['assigned_to'];
                }
            }
        }
        if (!$program && !empty($contact['program_id'])) {
            $program = db_row('SELECT id, slug, brochure, duration_label, duration_years FROM programs WHERE id = ?', [(int) $contact['program_id']]);
        }
        if ($program) {
            $dur = trim((string) ($program['duration_label'] ?? ''));
            if ($dur === '' && (float) ($program['duration_years'] ?? 0) > 0) {
                $y = (float) $program['duration_years'];
                $dur = rtrim(rtrim(number_format($y, 1), '0'), '.') . ' ' . ($y == 1.0 ? 'Year' : 'Years');
            }
            $set('course_duration', $dur);
        }
        if (!empty($contact['counsellor_id'])) {
            $u = db_row('SELECT name, phone FROM users WHERE id = ?', [(int) $contact['counsellor_id']]);
            if ($u) {
                $set('counsellor_name', $u['name']);
                $set('counsellor_phone', $u['phone']);
            }
        }
    } catch (Throwable $e) {
        error_log('[GIMT] wa_variables_for_contact: ' . $e->getMessage());
    }
    $set('brochure_link', wa_brochure_link($program));
    $set('student_name', $name);
    $set('student_first_name', $first);
    // an event payload that names an enquiry (GIMT_ENQUIRY_* events) also gets its number when the contact is not linked yet
    if (!isset($v['enquiry_no']) && (int) ($extra['enquiry_id'] ?? 0) > 0 && trim((string) ($extra['enquiry_no'] ?? '')) === '') {
        try {
            $en = db_row('SELECT * FROM enquiries WHERE id = ?', [(int) $extra['enquiry_id']]);
            if ($en) {
                $set('enquiry_no', wa_enquiry_no($en));
            }
        } catch (Throwable $e) {
        }
    }

    $out = [];
    foreach ($extra as $k => $val) {
        if (is_string($k) && is_scalar($val) && $val !== '') {
            $out[$k] = trim((string) $val);
        }
    }
    return $out + $v;
}

/* =================================================================================================================
 * Sending (queue)
 * ================================================================================================================= */

/** Default outbox priority per sender (1 = highest). */
function wa_default_priority(string $senderType): int
{
    return ['agent' => 2, 'bot' => 2, 'system' => 3, 'event' => 4, 'automation' => 5, 'followup' => 6, 'campaign' => 8][$senderType] ?? 5;
}

/** Normalise an interactive payload (Meta-shaped or flat) to {buttons:[{id,title}]} / {button, sections:[…]} (+ header, footer). */
function wa_normalize_interactive(string $type, $in): ?array
{
    if (!is_array($in)) {
        return null;
    }
    $header = $in['header']['text'] ?? ($in['header'] ?? null);
    $footer = $in['footer']['text'] ?? ($in['footer'] ?? null);
    $out = [];
    if (is_string($header) && trim($header) !== '') {
        $out['header'] = mb_substr(trim($header), 0, 60);
    }
    if (is_string($footer) && trim($footer) !== '') {
        $out['footer'] = mb_substr(trim($footer), 0, 60);
    }
    if ($type === 'interactive_button') {
        $buttons = [];
        foreach ((array) ($in['buttons'] ?? ($in['action']['buttons'] ?? [])) as $i => $b) {
            $title = is_array($b) ? ($b['title'] ?? ($b['reply']['title'] ?? ($b['text'] ?? ''))) : $b;
            $id = is_array($b) ? ($b['id'] ?? ($b['reply']['id'] ?? null)) : null;
            $title = trim((string) $title);
            if ($title === '') {
                continue;
            }
            $buttons[] = ['id' => mb_substr((string) ($id ?: 'btn_' . ($i + 1)), 0, 256), 'title' => mb_substr($title, 0, 20)];
        }
        if (!$buttons) {
            return null;
        }
        $out['buttons'] = array_slice($buttons, 0, 3);
        return $out;
    }
    if ($type === 'interactive_list') {
        $sections = [];
        $rowsTotal = 0;
        foreach ((array) ($in['sections'] ?? ($in['action']['sections'] ?? [])) as $s) {
            $rows = [];
            foreach ((array) ($s['rows'] ?? []) as $j => $r) {
                $title = trim((string) ($r['title'] ?? ''));
                if ($title === '' || $rowsTotal >= 10) {
                    continue;
                }
                $rows[] = array_filter(['id' => mb_substr((string) ($r['id'] ?? 'row_' . ($rowsTotal + 1)), 0, 200), 'title' => mb_substr($title, 0, 24),
                    'description' => isset($r['description']) && $r['description'] !== '' ? mb_substr((string) $r['description'], 0, 72) : null], fn ($x) => $x !== null);
                $rowsTotal++;
            }
            if ($rows) {
                $sections[] = array_filter(['title' => isset($s['title']) && $s['title'] !== '' ? mb_substr((string) $s['title'], 0, 24) : null, 'rows' => $rows], fn ($x) => $x !== null);
            }
        }
        if (!$sections) {
            return null;
        }
        $out['button'] = mb_substr(trim((string) ($in['button'] ?? ($in['action']['button'] ?? 'View options'))) ?: 'View options', 0, 20);
        $out['sections'] = array_slice($sections, 0, 10);
        return $out;
    }
    return $in;
}

/** Refusal result. */
function wa_refuse(string $code, string $error): array
{
    return ['ok' => false, 'message_id' => null, 'status' => 'refused', 'error' => $error, 'code' => $code];
}

/** Minutes within which an automated sender does not repeat the same template to the same contact. */
if (!defined('WA_REPEAT_WINDOW_MINUTES')) {
    define('WA_REPEAT_WINDOW_MINUTES', 10);
}

/**
 * Same-template repeat guard for automated senders (event, automation = workflows, followup): when the same template
 * (by name, any language) with the same rendered text and header media was queued or sent to this contact in the last
 * WA_REPEAT_WINDOW_MINUTES and that message did not fail or get cancelled, nothing new is queued — e.g. the Event
 * Manager and the "New Admission Enquiry" workflow both sending enquiry_received for one website enquiry. The text must
 * match so two different notices on one template (a parent's number on two siblings' fee reminders) both go out.
 * $row = the wa_messages row about to be inserted (template_name, body, media_url).
 * Returns null (send) or ['ok' => true, 'message_id' => the earlier message, 'status' => 'duplicate_recent',
 * 'error' => null, 'code' => 'duplicate_recent', 'duplicate_of', 'note'] — ok = true like a dedupe_key 'duplicate', so a
 * workflow run / follow-up carries on instead of failing.
 * Not applied to campaign, agent, bot or system sends, to test sends (skip_opt_in_check / use_samples / a test
 * workflow run), with $opts['allow_repeat'], or to a message scheduled more than the window ahead.
 */
function wa_send_recent_duplicate(array $contact, array $tpl, string $senderType, array $msg, array $opts, array $row): ?array
{
    if (!in_array($senderType, ['event', 'automation', 'followup'], true) || empty($contact['id'])
        || !empty($opts['allow_repeat']) || !empty($opts['skip_opt_in_check']) || !empty($opts['use_samples'])) {
        return null;
    }
    $window = WA_REPEAT_WINDOW_MINUTES * 60;
    $due = !empty($opts['at']) && strtotime((string) $opts['at']) ? (int) strtotime((string) $opts['at']) : time();
    if ($due > time() + $window) {
        return null;
    }
    try {
        if (!empty($msg['workflow_run_id']) && (int) db_value('SELECT is_test FROM wa_workflow_runs WHERE id = ?', [(int) $msg['workflow_run_id']]) === 1) {
            return null;
        }
        $since = date('Y-m-d H:i:s', time() - $window);
        $cands = db_all("SELECT id, status, sender_type, template_vars FROM wa_messages
            WHERE contact_id = ? AND direction = 'out' AND type = 'template' AND template_name = ? AND status NOT IN ('failed','cancelled')
              AND (created_at >= ? OR sent_at >= ?) AND COALESCE(body, '') = ? AND COALESCE(media_url, '') = ?
            ORDER BY id DESC LIMIT 5", [(int) $contact['id'], (string) $tpl['name'], $since, $since, (string) ($row['body'] ?? ''), (string) ($row['media_url'] ?? '')]);
    } catch (Throwable $e) {
        error_log('[GIMT] wa_send_recent_duplicate: ' . $e->getMessage());
        return null;
    }
    // header and button values must match too (a template with a fixed body can differ only in, say, its receipt link)
    $sig = function ($tv): string {
        $tv = is_array($tv) ? $tv : (wa_json_decode($tv, []) ?: []);
        return (string) json_encode([array_values(array_map('strval', (array) ($tv['header'] ?? []))),
            array_map(fn ($b) => array_values(array_map('strval', (array) $b)), (array) ($tv['buttons'] ?? []))]);
    };
    $mine = $sig($row['template_vars'] ?? null);
    $dup = null;
    foreach ($cands as $c) {
        if ($sig($c['template_vars']) === $mine) {
            $dup = $c;
            break;
        }
    }
    if (!$dup) {
        return null;
    }
    $by = ['event' => 'an Event Manager event', 'automation' => 'a workflow', 'followup' => 'a follow-up', 'campaign' => 'a campaign',
        'agent' => 'an agent', 'bot' => 'the bot', 'system' => 'the system'][$dup['sender_type']] ?? 'another sender';
    return ['ok' => true, 'message_id' => (int) $dup['id'], 'status' => 'duplicate_recent', 'error' => null, 'code' => 'duplicate_recent',
        'duplicate_of' => (int) $dup['id'],
        'note' => 'Not sent again: ' . $by . ' already sent template "' . $tpl['name'] . '" to this contact in the last '
            . WA_REPEAT_WINDOW_MINUTES . ' minutes (message #' . (int) $dup['id'] . ', ' . $dup['status'] . ').'];
}

/**
 * Queue a WhatsApp message (the worker delivers it). See docs/WHATSAPP.md §4 for $msg / $opts.
 * $msg: contact_id | phone (+ name), type, body, media_url, file_name, interactive, template (id|name), language, vars,
 *       sender_type, sent_by, campaign_id, workflow_run_id, event_log_id, followup_id, reply_to_id
 * $opts: at, priority (1..9), skip_opt_in_check, respect_business_hours, dedupe_key, use_samples (fill missing template
 *        variables with samples — test sends), max_attempts
 * @return array{ok:bool,message_id:?int,status:?string,error:?string,code:?string}
 * Refusals (ok=false, nothing stored): invalid_phone, blocked, opted_out, window_closed, template_not_approved,
 * template_not_found, missing_variables, invalid_message.
 */
function wa_send(array $msg, array $opts = []): array
{
    // ---- contact
    $contact = null;
    if (!empty($msg['contact_id'])) {
        $contact = wa_contact((int) $msg['contact_id']);
        if (!$contact) {
            return wa_refuse('invalid_phone', 'WhatsApp contact not found.');
        }
    } else {
        $norm = wa_normalize_phone((string) ($msg['phone'] ?? ''));
        if (!$norm) {
            return wa_refuse('invalid_phone', 'This is not a valid WhatsApp number.');
        }
        $contact = db_row('SELECT * FROM wa_contacts WHERE phone = ?', [$norm['phone']]);
        if (!$contact) {
            try {
                $cid = wa_contact_upsert(['phone' => $norm['phone'], 'name' => $msg['name'] ?? null], ['source' => $msg['sender_type'] ?? 'manual']);
            } catch (InvalidArgumentException $e) {
                return wa_refuse('invalid_phone', $e->getMessage());
            }
            $contact = wa_contact($cid);
        }
    }
    if (($contact['status'] ?? 'active') === 'blocked') {
        return wa_refuse('blocked', 'This contact is blocked.');
    }

    $senderType = in_array($msg['sender_type'] ?? '', ['agent', 'bot', 'automation', 'event', 'campaign', 'followup', 'system'], true) ? $msg['sender_type'] : 'agent';
    // a contact who replied STOP gets no campaign / follow-up / workflow / bot messages, whatever the template category
    // (transactional event messages, agent and system replies still go out — wa_optout_blocked_sender_types())
    if (in_array($senderType, wa_optout_blocked_sender_types(), true) && empty($opts['skip_opt_in_check']) && wa_contact_opted_out($contact)) {
        return wa_refuse('opted_out', 'The contact unsubscribed from GIMT WhatsApp updates (STOP).');
    }
    $type = (string) ($msg['type'] ?? (!empty($msg['template']) ? 'template' : 'text'));
    $allowed = ['text', 'image', 'video', 'document', 'audio', 'sticker', 'interactive_button', 'interactive_list', 'template'];
    if (!in_array($type, $allowed, true)) {
        return wa_refuse('invalid_message', 'Unsupported message type: ' . $type);
    }

    // ---- dedupe
    $dedupe = !empty($opts['dedupe_key']) ? 'dk:' . substr(sha1((string) $opts['dedupe_key']), 0, 40) : null;
    if ($dedupe) {
        $dup = db_row('SELECT id, status FROM wa_messages WHERE contact_id = ? AND callback_data = ? ORDER BY id DESC LIMIT 1', [(int) $contact['id'], $dedupe]);
        if ($dup) {
            return ['ok' => true, 'message_id' => (int) $dup['id'], 'status' => $dup['status'], 'error' => null, 'code' => 'duplicate'];
        }
    }

    $row = [
        'contact_id' => (int) $contact['id'],
        'direction' => 'out',
        'type' => $type,
        'status' => 'queued',
        'sender_type' => $senderType,
        'sent_by' => isset($msg['sent_by']) ? ((int) $msg['sent_by'] ?: null) : ($senderType === 'agent' ? wa_user_id() : null),
        'campaign_id' => !empty($msg['campaign_id']) ? (int) $msg['campaign_id'] : null,
        'workflow_run_id' => !empty($msg['workflow_run_id']) ? (int) $msg['workflow_run_id'] : null,
        'event_log_id' => !empty($msg['event_log_id']) ? (int) $msg['event_log_id'] : null,
        'followup_id' => !empty($msg['followup_id']) ? (int) $msg['followup_id'] : null,
        'reply_to_id' => !empty($msg['reply_to_id']) ? (int) $msg['reply_to_id'] : null,
        'callback_data' => $dedupe,
    ];
    $tpl = null;

    if ($type === 'template') {
        $tpl = wa_template_find($msg['template'] ?? null, $msg['language'] ?? ($contact['language'] ?? null));
        if (!$tpl) {
            return wa_refuse('template_not_found', 'WhatsApp template not found: ' . (is_scalar($msg['template'] ?? null) ? (string) $msg['template'] : '?'));
        }
        $okStatus = wa_mode() === 'live' ? $tpl['status'] === 'approved' : !in_array($tpl['status'], ['archived', 'disabled', 'rejected'], true);
        if (!$okStatus) {
            return wa_refuse('template_not_approved', 'Template "' . ($tpl['display_name'] ?: $tpl['name']) . '" is ' . $tpl['status'] . ' — only approved templates can be sent.');
        }
        if ($tpl['category'] === 'MARKETING' && empty($opts['skip_opt_in_check']) && (int) $contact['opt_in'] !== 1) {
            return wa_refuse('opted_out', 'The contact has not opted in to WhatsApp marketing messages.');
        }
        $vars = is_array($msg['vars'] ?? null) ? $msg['vars'] : [];
        if (!empty($opts['use_samples'])) {
            $vars['_use_samples'] = true;
        }
        if (!wa_vars_positional(array_diff_key($vars, array_flip(['header_media_url', 'media_url', 'file_name', 'payloads', '_use_samples'])))) {
            $vars = wa_variables_for_contact($contact, $vars) + $vars;
        }
        if (!empty($msg['media_url']) && empty($vars['header_media_url'])) {
            $vars['header_media_url'] = $msg['media_url'];
        }
        if (!empty($msg['file_name']) && empty($vars['file_name'])) {
            $vars['file_name'] = $msg['file_name'];
        }
        $r = wa_render_template($tpl, $vars);
        if (!empty($r['invalid'])) {
            return wa_refuse('invalid_message', implode(' ', $r['invalid']));
        }
        if ($r['missing']) {
            return wa_refuse('missing_variables', 'Missing template variables: ' . implode(', ', $r['missing']));
        }
        if ($r['media_url'] !== null) {
            // Interakt fetches header media from a public URL: site-relative paths become absolute, anything else is refused
            if (str_starts_with($r['media_url'], '/') && !str_starts_with($r['media_url'], '//')) {
                $r['media_url'] = absolute_url(ltrim($r['media_url'], '/'));
                $r['header'] = [$r['media_url']];
            }
            if (!preg_match('#^https?://[^\s]+$#i', $r['media_url']) || mb_strlen($r['media_url']) > 500) {
                return wa_refuse('invalid_message', 'The template header needs a public http(s) media URL.');
            }
        }
        $namedKeys = [];
        foreach (['header', 'body'] as $sec) {
            foreach ((array) ($tpl['variables'][$sec] ?? []) as $ref) {
                if (!empty($ref['key']) && isset($vars[$ref['key']])) {
                    $namedKeys[$ref['key']] = (string) $vars[$ref['key']];
                }
            }
        }
        $row['template_id'] = (int) $tpl['id'];
        $row['template_name'] = $tpl['name'];
        $row['body'] = $r['text'];
        $row['media_url'] = $r['media_url'];
        $row['file_name'] = $r['file_name'];
        // category = the snapshot at queue time (display); delivery re-reads the template's current category.
        // skip_opt_in marks a test send queued with skip_opt_in_check, so the delivery-time marketing gate lets it through.
        $row['template_vars'] = wa_json(['header' => $r['header'], 'body' => $r['body'], 'buttons' => (object) $r['buttons'], 'payloads' => (object) $r['payloads'],
            'file_name' => $r['file_name'], 'language' => $tpl['language'], 'category' => $tpl['category'], 'named' => (object) $namedKeys]
            + (!empty($opts['skip_opt_in_check']) ? ['skip_opt_in' => true] : []));
        $row['interactive'] = wa_json(['template' => [
            'header_type' => $tpl['header_type'], 'header_text' => $r['header_text'], 'header_media_url' => $r['media_url'], 'file_name' => $r['file_name'],
            'body' => $r['text'], 'footer' => $tpl['footer'], 'buttons' => $tpl['buttons'],
        ]]);
        if ($recent = wa_send_recent_duplicate($contact, $tpl, $senderType, $msg, $opts, $row)) {
            return $recent;
        }
    } else {
        if (!wa_contact_window_open($contact)) {
            return wa_refuse('window_closed', 'The 24-hour window is closed — only approved templates can be sent to this contact.');
        }
        $body = trim((string) ($msg['body'] ?? ''));
        if ($type === 'text') {
            if ($body === '') {
                return wa_refuse('invalid_message', 'Type a message first.');
            }
            if (mb_strlen($body) > 4096) {
                return wa_refuse('invalid_message', 'Messages can be at most 4096 characters.');
            }
        } elseif (in_array($type, ['image', 'video', 'document', 'audio', 'sticker'], true)) {
            $url = trim((string) ($msg['media_url'] ?? ''));
            if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
                $url = absolute_url(ltrim($url, '/'));
            }
            if (!preg_match('#^https?://[^\s]+$#i', $url)) {
                return wa_refuse('invalid_message', 'A public media URL is required for ' . $type . ' messages.');
            }
            $row['media_url'] = mb_substr($url, 0, 500);
            $row['file_name'] = !empty($msg['file_name']) ? mb_substr(basename((string) $msg['file_name']), 0, 190) : null;
            $row['media_mime'] = !empty($msg['media_mime']) ? mb_substr((string) $msg['media_mime'], 0, 100) : null;
            if (mb_strlen($body) > 1024) {
                return wa_refuse('invalid_message', 'Captions can be at most 1024 characters.');
            }
        } else {
            $inter = wa_normalize_interactive($type, $msg['interactive'] ?? null);
            if (!$inter) {
                return wa_refuse('invalid_message', $type === 'interactive_button' ? 'Add 1 to 3 reply buttons.' : 'Add at least one list row.');
            }
            if ($body === '') {
                return wa_refuse('invalid_message', 'Interactive messages need body text.');
            }
            $row['interactive'] = wa_json($inter);
        }
        $row['body'] = $body !== '' ? $body : null;
    }

    // ---- schedule
    $at = !empty($opts['at']) && strtotime((string) $opts['at']) ? date('Y-m-d H:i:s', strtotime((string) $opts['at'])) : wa_now();
    if (!empty($opts['respect_business_hours'])) {
        $at = wa_next_business_time(new DateTimeImmutable($at))->format('Y-m-d H:i:s');
    }
    // admin send gate (Settings › Automation): pause → the message is queued and held by the outbox until resumed;
    // quiet hours / per-contact daily cap → the message is scheduled for later. Agent, bot and system replies always pass.
    $held = null;
    if (function_exists('wa_adm_send_gate')) {
        try {
            $g = wa_adm_send_gate($contact, $senderType);
            if ($g && in_array($g['code'] ?? '', ['quiet_hours', 'daily_limit'], true) && !empty($g['defer_until']) && $g['defer_until'] > $at) {
                $at = date('Y-m-d H:i:s', strtotime((string) $g['defer_until']));
                $held = (string) $g['code'];
            } elseif ($g && ($g['code'] ?? '') === 'paused') {
                $held = 'paused';
            }
            // a message scheduled later must not land inside quiet hours either
            if (in_array($senderType, wa_automated_sender_types(), true) && $at > wa_now() && function_exists('wa_adm_quiet_until')
                && ($q = wa_adm_quiet_until(new DateTimeImmutable($at))) && $q > $at) {
                $at = $q;
                $held = $held ?? 'quiet_hours';
            }
        } catch (Throwable $e) {
            error_log('[GIMT] wa_adm_send_gate: ' . $e->getMessage());
        }
    }
    $priority = max(1, min(9, (int) ($opts['priority'] ?? wa_default_priority($senderType))));
    $maxAttempts = max(1, min(10, (int) ($opts['max_attempts'] ?? wa_setting('wa_max_attempts', 5))));

    $result = db_transaction(function () use ($row, $contact, $at, $priority, $maxAttempts, $tpl) {
        $conv = wa_conversation_for((int) $contact['id']);
        $row['conversation_id'] = (int) $conv['id'];
        $id = db_insert('wa_messages', $row);
        db_insert('wa_outbox', ['message_id' => $id, 'priority' => $priority, 'scheduled_at' => $at, 'status' => 'pending', 'max_attempts' => $maxAttempts]);
        $now = wa_now();
        $preview = wa_preview($row['type'] === 'template' ? ($row['body'] ?: 'Template: ' . $row['template_name']) : ($row['body'] ?: ucfirst(str_replace('_', ' ', $row['type']))));
        db_update('wa_conversations', ['last_message_at' => $now, 'last_message_preview' => mb_substr($preview, 0, 255), 'last_message_direction' => 'out'], 'id = ?', [(int) $conv['id']]);
        db_update('wa_contacts', ['last_message_at' => $now], 'id = ?', [(int) $contact['id']]);
        if ($tpl) {
            // a send is not an edit: keep updated_at (the library's "Recently updated" sort) unchanged
            db_exec('UPDATE wa_templates SET usage_count = usage_count + 1, last_used_at = ?, updated_at = updated_at WHERE id = ?', [$now, (int) $tpl['id']]);
        }
        return $id;
    });

    // 'held' = paused | quiet_hours | daily_limit when the admin send gate delayed the message (still queued, ok = true)
    return ['ok' => true, 'message_id' => (int) $result, 'status' => 'queued', 'error' => null, 'code' => null, 'held' => $held, 'scheduled_at' => $at];
}

/** Message-level keys that may be passed inside $opts of the convenience senders. */
function wa_msg_keys(): array
{
    return ['sender_type', 'sent_by', 'campaign_id', 'workflow_run_id', 'event_log_id', 'followup_id', 'reply_to_id', 'language', 'media_url', 'file_name', 'name'];
}

/**
 * Queue an approved template. $contactOrPhone: contact id, contact row or phone. $vars named (student_name => …) or
 * positional. $opts: wa_send() options plus message keys (sender_type, campaign_id, workflow_run_id, …).
 */
function wa_send_template($contactOrPhone, $template, array $vars = [], array $opts = []): array
{
    $msg = ['type' => 'template', 'template' => $template, 'vars' => $vars];
    if (is_array($contactOrPhone)) {
        $msg['contact_id'] = (int) ($contactOrPhone['id'] ?? 0);
        if (!$msg['contact_id'] && !empty($contactOrPhone['phone'])) {
            unset($msg['contact_id']);
            $msg['phone'] = $contactOrPhone['phone'];
        }
    } elseif (is_int($contactOrPhone) || (is_string($contactOrPhone) && ctype_digit($contactOrPhone) && strlen($contactOrPhone) < 10)) {
        $msg['contact_id'] = (int) $contactOrPhone;
    } else {
        $msg['phone'] = (string) $contactOrPhone;
    }
    foreach (wa_msg_keys() as $k) {
        if (array_key_exists($k, $opts)) {
            $msg[$k] = $opts[$k];
            unset($opts[$k]);
        }
    }
    return wa_send($msg, $opts);
}

/** Queue a free-form text (needs an open 24-hour window). */
function wa_send_text(int $contactId, string $text, array $opts = []): array
{
    $msg = ['contact_id' => $contactId, 'type' => 'text', 'body' => $text];
    foreach (wa_msg_keys() as $k) {
        if (array_key_exists($k, $opts)) {
            $msg[$k] = $opts[$k];
            unset($opts[$k]);
        }
    }
    return wa_send($msg, $opts);
}

/**
 * Cancel queued (not yet sent) messages: pending outbox rows → cancelled, their messages → cancelled, and linked campaign
 * recipients still 'queued' → 'skipped' (skip_reason 'cancelled', error = $reason). Campaign pause then moves its own
 * recipients back to pending. Returns how many were cancelled.
 */
function wa_cancel_queued(array $messageIds, string $reason = 'Cancelled'): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
    if (!$ids) {
        return 0;
    }
    $reason = mb_substr(trim($reason) !== '' ? trim($reason) : 'Cancelled', 0, 255);
    $n = 0;
    foreach (array_chunk($ids, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $n += db_exec("UPDATE wa_outbox SET status = 'cancelled', last_error = ? WHERE status = 'pending' AND message_id IN ($ph)", array_merge([$reason], $chunk));
        db_exec("UPDATE wa_messages m JOIN wa_outbox o ON o.message_id = m.id SET m.status = 'cancelled', m.error = ? WHERE o.status = 'cancelled' AND m.status = 'queued' AND m.id IN ($ph)", array_merge([$reason], $chunk));
        // campaign recipients of the messages that are now cancelled (other readers must not see them as 'queued')
        try {
            db_exec("UPDATE wa_campaign_recipients r JOIN wa_messages m ON m.id = r.message_id
                SET r.status = 'skipped', r.skip_reason = 'cancelled', r.error = ?
                WHERE m.status = 'cancelled' AND r.status = 'queued' AND m.id IN ($ph)", array_merge([$reason], $chunk));
        } catch (Throwable $e) {
            // schema 52 (skip_reason) missing on an old install: still move the rows out of 'queued'
            try {
                db_exec("UPDATE wa_campaign_recipients r JOIN wa_messages m ON m.id = r.message_id SET r.status = 'skipped', r.error = ?
                    WHERE m.status = 'cancelled' AND r.status = 'queued' AND m.id IN ($ph)", array_merge([$reason], $chunk));
            } catch (Throwable $e2) {
                error_log('[GIMT] wa_cancel_queued: campaign recipients not updated: ' . $e2->getMessage());
            }
        }
    }
    return $n;
}

/**
 * Re-queue a failed outbound message — the same wa_messages row, so chat bubbles update in place. Resets the message
 * (status queued, error cleared), its outbox row (pending, attempts 0, due now; inserted when missing) and a linked
 * campaign recipient. A 'queued' message whose outbox row failed or is stuck in processing (> 10 min) is re-queued too.
 * Session messages need an open 24-hour window; templates must still be sendable (approved in live mode).
 * $opts: priority (1..9, default 1)
 * @return array{ok:bool,message_id:int,error:?string,code:?string}
 * Codes: not_found, not_failed, blocked, window_closed, template_not_found, template_not_approved, opted_out
 */
function wa_retry_message(int $messageId, array $opts = []): array
{
    $fail = fn (string $code, string $error) => ['ok' => false, 'message_id' => $messageId, 'error' => $error, 'code' => $code];
    $m = $messageId > 0 ? db_row('SELECT * FROM wa_messages WHERE id = ?', [$messageId]) : null;
    if (!$m || $m['direction'] !== 'out') {
        return $fail('not_found', 'Outgoing WhatsApp message not found.');
    }
    $box = db_row('SELECT * FROM wa_outbox WHERE message_id = ?', [$messageId]);
    $stuck = $box && $box['status'] === 'processing' && (!$box['locked_at'] || strtotime((string) $box['locked_at']) < time() - 600);
    $retryable = $m['status'] === 'failed' || ($m['status'] === 'queued' && (!$box || $box['status'] === 'failed' || $stuck));
    if (!$retryable) {
        return $fail('not_failed', 'Only failed messages can be retried (this one is ' . $m['status'] . ').');
    }
    $contact = wa_contact((int) $m['contact_id']);
    if (!$contact) {
        return $fail('not_found', 'The WhatsApp contact no longer exists.');
    }
    if (($contact['status'] ?? 'active') === 'blocked') {
        return $fail('blocked', 'This contact is blocked.');
    }
    if (in_array($m['sender_type'], wa_optout_blocked_sender_types(), true) && wa_contact_opted_out($contact)) {
        return $fail('opted_out', 'The contact unsubscribed from GIMT WhatsApp updates (STOP).');
    }
    if ($m['type'] === 'template') {
        $tpl = !empty($m['template_id']) ? wa_template_find((int) $m['template_id']) : wa_template_find((string) $m['template_name']);
        if (!$tpl) {
            return $fail('template_not_found', 'The template of this message no longer exists.');
        }
        $okStatus = wa_mode() === 'live' ? $tpl['status'] === 'approved' : !in_array($tpl['status'], ['archived', 'disabled', 'rejected'], true);
        if (!$okStatus) {
            return $fail('template_not_approved', 'Template "' . ($tpl['display_name'] ?: $tpl['name']) . '" is ' . $tpl['status'] . ' — only approved templates can be sent.');
        }
        if ($tpl['category'] === 'MARKETING' && (int) $contact['opt_in'] !== 1) {
            return $fail('opted_out', 'The contact has not opted in to WhatsApp marketing messages.');
        }
    } elseif (!wa_contact_window_open($contact)) {
        return $fail('window_closed', 'The 24-hour window has closed, so this message can no longer be sent. Send an approved template instead.');
    }
    $priority = max(1, min(9, (int) ($opts['priority'] ?? 1)));
    db_transaction(function () use ($m, $box, $priority) {
        $now = wa_now();
        db_update('wa_messages', ['status' => 'queued', 'error' => null, 'error_code' => null, 'failed_at' => null], 'id = ?', [(int) $m['id']]);
        if ($box) {
            db_update('wa_outbox', ['status' => 'pending', 'attempts' => 0, 'scheduled_at' => $now, 'locked_at' => null, 'last_error' => null, 'priority' => $priority], 'id = ?', [(int) $box['id']]);
        } else {
            db_insert('wa_outbox', ['message_id' => (int) $m['id'], 'priority' => $priority, 'scheduled_at' => $now, 'status' => 'pending',
                'max_attempts' => max(1, min(10, (int) wa_setting('wa_max_attempts', 5)))]);
        }
        try {
            db_exec("UPDATE wa_campaign_recipients SET status = 'queued', error = NULL WHERE message_id = ? AND status = 'failed'", [(int) $m['id']]);
        } catch (Throwable $e) {
        }
    });
    wa_agent_log('retry', $m['conversation_id'] ? (int) $m['conversation_id'] : null, (int) $m['contact_id'], 'Retried failed message #' . (int) $m['id']);
    return ['ok' => true, 'message_id' => (int) $m['id'], 'error' => null, 'code' => null];
}

/* =================================================================================================================
 * Interakt client
 * ================================================================================================================= */

/**
 * Interakt API base URL. The Authorization header carries the API key, so the setting is honoured only for an
 * https URL on interakt.ai or a subdomain of it (port 443, no credentials, plain path); anything else — whoever wrote
 * the setting — falls back to the default. The URL is rebuilt from the validated parts (no query / fragment).
 */
function wa_interakt_base_url(): string
{
    $default = 'https://api.interakt.ai/v1/public/';
    $u = trim((string) wa_setting('wa_interakt_base_url', $default));
    if ($u === '' || $u === $default) {
        return $default;
    }
    $p = preg_match('/[\s\\\\]/', $u) ? false : parse_url($u);
    if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || isset($p['user']) || isset($p['pass'])
        || (isset($p['port']) && (int) $p['port'] !== 443)) {
        return $default;
    }
    $host = rtrim(strtolower((string) ($p['host'] ?? '')), '.');
    if (!preg_match('/^[a-z0-9.-]+$/', $host) || !($host === 'interakt.ai' || str_ends_with($host, '.interakt.ai'))) {
        return $default;
    }
    $path = (string) ($p['path'] ?? '/');
    if ($path === '' || !preg_match('#^/[A-Za-z0-9._~/-]*$#', $path) || str_contains($path, '..')) {
        $path = '/v1/public/';
    }
    return 'https://' . $host . rtrim($path, '/') . '/';
}

/** CA bundle for curl on Windows / XAMPP installs without one configured. */
function wa_ca_bundle(): ?string
{
    static $path = false;
    if ($path !== false) {
        return $path;
    }
    $path = null;
    if (ini_get('curl.cainfo') || ini_get('openssl.cafile')) {
        return $path;
    }
    $bin = dirname((string) PHP_BINARY);
    foreach ([getenv('GIMT_CA_BUNDLE') ?: null, APP_ROOT . '/storage/cacert.pem', $bin . '/extras/ssl/cacert.pem', dirname($bin) . '/apache/bin/curl-ca-bundle.crt', $bin . '/curl-ca-bundle.crt'] as $p) {
        if ($p && is_file($p)) {
            return $path = $p;
        }
    }
    return $path;
}

/** Write a wa_api_logs row (never throws). */
function wa_api_log(array $row): void
{
    try {
        foreach (['request', 'response'] as $k) {
            if (isset($row[$k]) && is_string($row[$k]) && strlen($row[$k]) > 65000) {
                $row[$k] = substr($row[$k], 0, 65000) . '…';
            }
        }
        if (isset($row['error'])) {
            $row['error'] = mb_substr((string) $row['error'], 0, 2000);
        }
        $row['endpoint'] = mb_substr((string) ($row['endpoint'] ?? ''), 0, 190);
        db_insert('wa_api_logs', $row);
    } catch (Throwable $e) {
        error_log('[GIMT] wa_api_log failed: ' . $e->getMessage());
    }
}

/**
 * Call the Interakt public API. Logs to wa_api_logs (request without credentials). ok = HTTP 2xx and result !== false.
 * $opts: query (array), message_id, timeout (s, default 15), multipart (array of fields incl. CURLFile), log (bool)
 * @return array{ok:bool,status:int,data:mixed,error:?string,duration_ms:int}
 */
function wa_interakt_request(string $method, string $path, ?array $body = null, array $opts = []): array
{
    $method = strtoupper($method);
    $path = ltrim($path, '/');
    $key = trim((string) wa_setting('wa_interakt_api_key', ''));
    $logPath = $path . (!empty($opts['query']) ? '?' . http_build_query($opts['query']) : '');
    if ($key === '') {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'The Interakt API key is not configured.', 'duration_ms' => 0];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'The PHP curl extension is not enabled on this server.', 'duration_ms' => 0];
    }
    $url = wa_interakt_base_url() . $path . (!empty($opts['query']) ? '?' . http_build_query($opts['query']) : '');
    $headers = ['Authorization: Basic ' . $key, 'Accept: application/json'];
    $ch = curl_init($url);
    $curl = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => (int) ($opts['timeout'] ?? 15),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'GIMT-SmartCampus/1.0 (+WhatsApp)',
    ];
    $logRequest = null;
    if (!empty($opts['multipart'])) {
        $curl[CURLOPT_POSTFIELDS] = $opts['multipart'];
        $logRequest = wa_json(array_map(fn ($v) => $v instanceof CURLFile ? ['file' => $v->getPostFilename()] : $v, $opts['multipart']));
    } elseif ($body !== null) {
        $json = wa_json($body);
        $curl[CURLOPT_POSTFIELDS] = $json;
        $headers[] = 'Content-Type: application/json';
        $logRequest = $json;
    }
    $curl[CURLOPT_HTTPHEADER] = $headers;
    if ($ca = wa_ca_bundle()) {
        $curl[CURLOPT_CAINFO] = $ca;
    }
    curl_setopt_array($ch, $curl);
    $t0 = microtime(true);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = $raw === false ? curl_error($ch) : null;
    unset($ch);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    $ok = $err === null && $status >= 200 && $status < 300 && !(is_array($data) && array_key_exists('result', $data) && $data['result'] !== true);
    if (!$ok && $err === null) {
        $err = is_array($data) ? (string) ($data['message'] ?? ($data['error'] ?? 'HTTP ' . $status)) : ('HTTP ' . $status . ($raw ? ': ' . mb_substr(strip_tags((string) $raw), 0, 200) : ''));
    }
    if ($opts['log'] ?? true) {
        wa_api_log(['endpoint' => $logPath, 'method' => $method, 'request' => $logRequest, 'response' => is_string($raw) ? $raw : null, 'http_status' => $status ?: null,
            'ok' => $ok ? 1 : 0, 'duration_ms' => $ms, 'message_id' => $opts['message_id'] ?? null, 'mode' => 'live', 'error' => $ok ? null : $err]);
    }
    return ['ok' => $ok, 'status' => $status, 'data' => $data, 'error' => $ok ? null : $err, 'duration_ms' => $ms];
}

/** Simulated client result (test mode) — logged with mode = test. */
function wa_interakt_simulate(string $method, string $path, $request, array $data, ?int $messageId = null, int $status = 200): array
{
    wa_api_log(['endpoint' => $path, 'method' => $method, 'request' => is_string($request) ? $request : wa_json($request), 'response' => wa_json($data), 'http_status' => $status,
        'ok' => 1, 'duration_ms' => 0, 'message_id' => $messageId, 'mode' => 'test', 'error' => null]);
    return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => null, 'duration_ms' => 0, 'simulated' => true];
}

/** Trait value accepted by Track User (no newline / tab / 3+ spaces). */
function wa_trait_value($v)
{
    if (is_bool($v) || is_int($v) || is_float($v) || $v === null) {
        return $v;
    }
    return mb_substr(trim((string) preg_replace('/[\r\n\t]+| {3,}/', ' ', (string) $v)), 0, 250);
}

/** Push a contact to Interakt (Track User: traits + tags + whatsapp_opted_in). Sets interakt_synced_at on success. */
function wa_interakt_track_user(array $contact): array
{
    $traits = array_filter([
        'name' => $contact['name'] ?? null,
        'email' => $contact['email'] ?? null,
        'contact_type' => $contact['contact_type'] ?? null,
        'program' => $contact['program_name'] ?? null,
        'application_no' => $contact['application_no'] ?? null,
        'city' => $contact['city'] ?? null,
        'stage' => $contact['stage'] ?? null,
        'gimt_contact_id' => isset($contact['id']) ? (string) $contact['id'] : null,
    ], fn ($v) => $v !== null && $v !== '');
    $traits = array_map('wa_trait_value', $traits);
    $traits['whatsapp_opted_in'] = (int) ($contact['opt_in'] ?? 0) === 1;
    $payload = ['countryCode' => $contact['country_code'] ?? '+91', 'phoneNumber' => $contact['national_number'] ?? '', 'traits' => $traits];
    if (!empty($contact['id'])) {
        $tags = array_slice(wa_contact_tag_names((int) $contact['id']), 0, 50);
        if ($tags) {
            $payload['tags'] = $tags;
        }
    }
    $r = wa_mode() === 'live'
        ? wa_interakt_request('POST', 'track/users/', $payload)
        : wa_interakt_simulate('POST', 'track/users/', $payload, ['result' => true, 'message' => 'Simulated (test mode): customer updated'], null, 202);
    if ($r['ok'] && !empty($contact['id'])) {
        db_exec('UPDATE wa_contacts SET interakt_synced_at = ? WHERE id = ?', [wa_now(), (int) $contact['id']]);
    }
    return $r;
}

/**
 * List Interakt templates (GET track/organization/templates). The double-encoded `buttons` string of every template
 * is decoded into `buttons_decoded`.
 */
function wa_interakt_list_templates(array $query = []): array
{
    $query += ['offset' => 0, 'autosubmitted_for' => 'all', 'language' => 'all'];
    if (wa_mode() !== 'live') {
        return ['ok' => true, 'status' => 200, 'data' => ['count' => 0, 'has_next' => false, 'results' => ['templates' => []]], 'error' => null, 'duration_ms' => 0, 'simulated' => true];
    }
    $r = wa_interakt_request('GET', 'track/organization/templates', null, ['query' => $query, 'timeout' => 20]);
    if ($r['ok'] && isset($r['data']['results']['templates']) && is_array($r['data']['results']['templates'])) {
        foreach ($r['data']['results']['templates'] as &$t) {
            $b = $t['buttons'] ?? null;
            for ($i = 0; $i < 3 && is_string($b); $i++) {
                $d = json_decode($b, true);
                if ($d === null && json_last_error() !== JSON_ERROR_NONE) {
                    break;
                }
                $b = $d;
            }
            $t['buttons_decoded'] = is_array($b) ? array_values(array_filter($b, 'is_array')) : [];
        }
        unset($t);
    }
    return $r;
}

/** Create / submit a template (POST track/templates/). Test mode returns a simulated WAITING template. */
function wa_interakt_create_template(array $payload): array
{
    if (wa_mode() !== 'live') {
        $name = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '_', (string) ($payload['display_name'] ?? 'template')), '_'));
        return wa_interakt_simulate('POST', 'track/templates/', $payload, ['result' => true, 'message' => 'Simulated (test mode): template created', 'data' => [
            'id' => 'test-' . wa_uuid(), 'name' => $name, 'language' => 'en', 'category' => strtoupper((string) ($payload['category'] ?? 'UTILITY')),
            'approval_status' => 'WAITING', 'wa_template_id' => null, 'rejection_reason' => null]], null, 201);
    }
    return wa_interakt_request('POST', 'track/templates/', $payload, ['timeout' => 25]);
}

/** Upload a template sample media file (multipart upload_to_fb). Returns data.file_url / file_handle / file_name. */
function wa_interakt_upload_media(string $absPath, string $fileName): array
{
    if (!is_file($absPath)) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'File not found.', 'duration_ms' => 0];
    }
    if (wa_mode() !== 'live') {
        $rel = str_starts_with(str_replace('\\', '/', $absPath), str_replace('\\', '/', APP_ROOT) . '/assets/')
            ? absolute_url(ltrim(substr(str_replace('\\', '/', $absPath), strlen(str_replace('\\', '/', APP_ROOT))), '/')) : null;
        return wa_interakt_simulate('POST', 'track/files/upload_to_fb/?fileCategory=message_template_media', ['uploadFile' => $fileName],
            ['result' => true, 'message' => 'Simulated (test mode): media uploaded', 'data' => ['file_url' => $rel, 'file_handle' => 'test-handle-' . substr(sha1($absPath), 0, 16), 'file_name' => $fileName]]);
    }
    $mime = function_exists('mime_content_type') ? (mime_content_type($absPath) ?: 'application/octet-stream') : 'application/octet-stream';
    return wa_interakt_request('POST', 'track/files/upload_to_fb/', null, ['query' => ['fileCategory' => 'message_template_media'],
        'multipart' => ['uploadFile' => new CURLFile($absPath, $mime, $fileName)], 'timeout' => 60]);
}

/* =================================================================================================================
 * Outbox / delivery
 * ================================================================================================================= */

/**
 * One template parameter value in the form Meta accepts: line breaks / tabs → one space, runs of spaces collapsed to one,
 * trimmed (Meta error 132018 rejects new-line / tab characters and more than 4 consecutive spaces in parameters).
 * $max > 0 caps the length (the cut value ends with "…").
 */
function wa_template_param($v, int $max = 0): string
{
    $s = is_scalar($v) ? (string) $v : '';
    $t = preg_replace('/[\r\n\t\v\f\x{0085}\x{2028}\x{2029}]+/u', ' ', $s);
    if ($t === null) { // not valid UTF-8: plain byte classes
        $t = (string) preg_replace('/[\r\n\t\v\f]+/', ' ', $s);
    }
    $t = trim((string) preg_replace('/ {2,}/', ' ', $t));
    if ($max > 0 && mb_strlen($t) > $max) {
        $t = $max > 1 ? rtrim(mb_substr($t, 0, $max - 1)) . '…' : mb_substr($t, 0, $max);
    }
    return $t;
}

/**
 * Interakt send payload for a queued message. Template parameters (header / body / button values) are normalised with
 * wa_template_param() here, so retries of rows queued earlier are covered too; a text header stays within Meta's
 * 60-character limit. The stored message text (preview) keeps its line breaks. template_category uses the template's
 * CURRENT category (wa_templates), not the snapshot taken when the message was queued.
 */
function wa_build_payload(array $m, array $contact): array
{
    $p = ['countryCode' => $contact['country_code'] ?: '+91', 'phoneNumber' => $contact['national_number'], 'callbackData' => 'gimt:msg:' . $m['id']];
    if ($m['type'] === 'template') {
        $tv = wa_json_decode($m['template_vars'] ?? null, []) ?: [];
        $tpl = !empty($m['template_id']) ? db_row('SELECT name, language, category, header_type, header_text FROM wa_templates WHERE id = ?', [(int) $m['template_id']]) : null;
        $t = ['name' => $tpl['name'] ?? $m['template_name'], 'languageCode' => $tv['language'] ?? ($tpl['language'] ?? 'en')];
        $param = fn ($v) => wa_template_param($v);
        if (!empty($tv['header'])) {
            $headerCap = 0;
            if (($tpl['header_type'] ?? '') === 'text' && ($n = preg_match_all('/\{\{\s*\d+\s*\}\}/', (string) ($tpl['header_text'] ?? ''))) > 0) {
                // Meta: a text header is at most 60 characters with its variable filled in
                $static = mb_strlen((string) preg_replace('/\{\{\s*\d+\s*\}\}/', '', (string) $tpl['header_text']));
                $headerCap = max(1, intdiv(max(0, 60 - $static), $n));
            }
            $t['headerValues'] = array_values(array_map(fn ($v) => wa_template_param($v, $headerCap), (array) $tv['header']));
        }
        if (!empty($tv['file_name']) && !empty($t['headerValues'])) {
            $t['fileName'] = (string) $tv['file_name'];
        }
        $t['bodyValues'] = array_values(array_map($param, (array) ($tv['body'] ?? [])));
        if (!empty($tv['buttons'])) {
            $t['buttonValues'] = (object) array_map(fn ($v) => array_values(array_map($param, (array) $v)), (array) $tv['buttons']);
        }
        if (!empty($tv['payloads'])) {
            $t['buttonPayload'] = (object) array_map(fn ($v) => array_values(array_map('strval', (array) $v)), (array) $tv['payloads']);
        }
        $p['type'] = 'Template';
        $p['template'] = $t;
        $cat = strtoupper((string) ($tpl['category'] ?? ($tv['category'] ?? '')));
        if ($cat === 'UTILITY') {
            // documented guard: a template Meta re-categorised to MARKETING fails loudly instead of being billed as marketing
            // (Interakt documents only the "utility" value, so other categories are sent without it)
            $p['template_category'] = 'utility';
        }
        if (!empty($m['campaign_id'])) {
            try {
                $ic = db_value('SELECT interakt_campaign_id FROM wa_campaigns WHERE id = ?', [(int) $m['campaign_id']]);
                if ($ic) {
                    $p['campaignId'] = (string) $ic;
                }
            } catch (Throwable $e) {
            }
        }
        return $p;
    }
    $body = (string) ($m['body'] ?? '');
    switch ($m['type']) {
        case 'text':
            $p['type'] = 'Text';
            $p['data'] = ['message' => $body];
            break;
        case 'image':
        case 'video':
        case 'document':
        case 'audio':
            $p['type'] = ucfirst($m['type']);
            $p['data'] = ['message' => $body, 'mediaUrl' => (string) $m['media_url']];
            if (!empty($m['file_name']) && $m['type'] !== 'image') {
                $p['data']['fileName'] = (string) $m['file_name'];
            }
            break;
        case 'sticker':
            $p['type'] = 'Sticker';
            $p['data'] = ['mediaUrl' => (string) $m['media_url']];
            break;
        case 'interactive_button':
        case 'interactive_list':
            $in = wa_json_decode($m['interactive'] ?? null, []) ?: [];
            $im = ['type' => $m['type'] === 'interactive_button' ? 'button' : 'list', 'body' => ['text' => $body]];
            if (!empty($in['header'])) {
                $im['header'] = ['type' => 'text', 'text' => (string) $in['header']];
            }
            if (!empty($in['footer'])) {
                $im['footer'] = ['text' => (string) $in['footer']];
            }
            if ($m['type'] === 'interactive_button') {
                $im['action'] = ['buttons' => array_map(fn ($b) => ['type' => 'reply', 'reply' => ['id' => (string) $b['id'], 'title' => (string) $b['title']]], $in['buttons'] ?? [])];
                $p['type'] = 'InteractiveButton';
            } else {
                $im['action'] = ['button' => (string) ($in['button'] ?? 'View options'), 'sections' => $in['sections'] ?? []];
                $p['type'] = 'InteractiveList';
            }
            $p['data'] = ['message' => $im];
            break;
        default:
            $p['type'] = 'Text';
            $p['data'] = ['message' => $body];
    }
    return $p;
}

/** Map an Interakt send error to [code, retryable]. */
function wa_classify_error(int $status, ?string $error): array
{
    $e = strtolower((string) $error);
    if ($status === 429 || str_contains($e, 'rate limit')) {
        return ['rate_limited', true];
    }
    if (str_contains($e, 'not messaged within last 24 hours') || str_contains($e, '24 hours')) {
        return ['window_closed', false];
    }
    if (str_contains($e, 'category mismatch')) {
        return ['category_mismatch', false];
    }
    if (str_contains($e, 'not a valid whatsapp user') || str_contains($e, 'invalid phone') || str_contains($e, 'phone number') && str_contains($e, 'invalid')) {
        return ['invalid_number', false];
    }
    if (str_contains($e, 'template') && (str_contains($e, 'not found') || str_contains($e, 'does not exist') || str_contains($e, 'not approved'))) {
        return ['template_error', false];
    }
    if ($status === 0 || $status >= 500) {
        return ['send_failed', true];
    }
    if ($status === 401 || $status === 403) {
        return ['auth_failed', true];
    }
    return ['rejected', false];
}

/** Ranking used to move statuses forward only. */
function wa_status_rank(?string $s): int
{
    return ['queued' => 0, 'cancelled' => 0, 'simulated' => 1, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'received' => 3, 'failed' => 4][$s ?? ''] ?? 0;
}

/** Update linked campaign recipient rows (forward-only). */
function wa_update_campaign_recipient(int $messageId, string $status, ?string $error = null): void
{
    try {
        $map = ['sent' => 'sent', 'simulated' => 'sent', 'delivered' => 'delivered', 'read' => 'read', 'failed' => 'failed', 'cancelled' => 'skipped'];
        $to = $map[$status] ?? null;
        if (!$to) {
            return;
        }
        $rank = ['pending' => 0, 'queued' => 1, 'skipped' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4, 'failed' => 5];
        foreach (db_all('SELECT id, status FROM wa_campaign_recipients WHERE message_id = ?', [$messageId]) as $r) {
            $cur = $rank[$r['status']] ?? 0;
            // a cancelled message (STOP / no opt-in at delivery) moves a pending / queued recipient to 'skipped' (same rank as queued)
            $move = match ($to) {
                'failed' => $cur < 3,
                'skipped' => in_array($r['status'], ['pending', 'queued'], true),
                default => $rank[$to] > $cur && $r['status'] !== 'failed',
            };
            if ($move) {
                db_update('wa_campaign_recipients', ['status' => $to, 'error' => $error !== null ? mb_substr($error, 0, 255) : null], 'id = ?', [(int) $r['id']]);
            }
        }
    } catch (Throwable $e) {
        error_log('[GIMT] wa_update_campaign_recipient: ' . $e->getMessage());
    }
}

/** Mark a message failed (message + outbox + campaign recipient) and fire WA_MESSAGE_STATUS. */
function wa_fail_message(array $m, int $outboxId, string $code, string $error): void
{
    $now = wa_now();
    db_update('wa_messages', ['status' => 'failed', 'failed_at' => $now, 'error_code' => mb_substr($code, 0, 40), 'error' => mb_substr($error, 0, 2000)], 'id = ?', [(int) $m['id']]);
    db_update('wa_outbox', ['status' => 'failed', 'locked_at' => null, 'last_error' => mb_substr($code . ': ' . $error, 0, 2000)], 'id = ?', [$outboxId]);
    wa_update_campaign_recipient((int) $m['id'], 'failed', $error);
    wa_event('WA_MESSAGE_STATUS', ['message_id' => (int) $m['id'], 'status' => 'failed', 'error_code' => $code, 'error' => $error, 'campaign_id' => $m['campaign_id'],
        'workflow_run_id' => $m['workflow_run_id'], 'followup_id' => $m['followup_id']], ['source' => 'core', 'contact_id' => (int) $m['contact_id']]);
}

/** Deliver one claimed outbox row. Returns 'sent'|'simulated'|'failed'|'retry'|'throttled'|'cancelled'. */
function wa_outbox_deliver(array $ob): string
{
    $m = db_row('SELECT * FROM wa_messages WHERE id = ?', [(int) $ob['message_id']]);
    if (!$m || $m['status'] === 'cancelled') {
        db_update('wa_outbox', ['status' => 'cancelled', 'locked_at' => null], 'id = ?', [(int) $ob['id']]);
        return 'cancelled';
    }
    if ($m['status'] !== 'queued') {
        db_update('wa_outbox', ['status' => 'done', 'locked_at' => null], 'id = ?', [(int) $ob['id']]);
        return 'cancelled';
    }
    $contact = wa_contact((int) $m['contact_id']);
    if (!$contact || $contact['status'] === 'blocked') {
        wa_fail_message($m, (int) $ob['id'], 'blocked', 'The contact is blocked.');
        return 'failed';
    }
    if ($m['type'] !== 'template' && !wa_contact_window_open($contact)) {
        wa_fail_message($m, (int) $ob['id'], 'window_closed', 'The 24-hour window closed before the message could be sent.');
        return 'failed';
    }
    // templates: the CURRENT status and category from wa_templates (Meta may re-categorise a template after the message
    // was queued — the snapshot in template_vars is only the fallback when the template row is gone)
    $cat = null;
    $tv = [];
    $tplNow = null;
    if ($m['type'] === 'template') {
        $tv = wa_json_decode($m['template_vars'] ?? null, []) ?: [];
        $tplNow = !empty($m['template_id']) ? (db_row('SELECT name, display_name, status, category FROM wa_templates WHERE id = ?', [(int) $m['template_id']]) ?: null) : null;
        $cat = strtoupper((string) (($tplNow['category'] ?? '') ?: ($tv['category'] ?? '')));
    }
    // the contact replied STOP after this message was queued (e.g. mid-campaign): never send it
    if (wa_contact_opted_out($contact) && (in_array($m['sender_type'], wa_optout_blocked_sender_types(), true) || $cat === 'MARKETING')) {
        $why = 'The contact unsubscribed (STOP) before the message was sent.';
        db_update('wa_messages', ['status' => 'cancelled', 'error' => $why], 'id = ?', [(int) $m['id']]);
        db_update('wa_outbox', ['status' => 'cancelled', 'locked_at' => null, 'last_error' => $why], 'id = ?', [(int) $ob['id']]);
        wa_update_campaign_recipient((int) $m['id'], 'cancelled', $why);
        try {
            db_exec("UPDATE wa_campaign_recipients SET skip_reason = 'opted_out' WHERE message_id = ? AND status = 'skipped' AND (skip_reason IS NULL OR skip_reason = '')", [(int) $m['id']]);
        } catch (Throwable $e) {
        }
        return 'cancelled';
    }
    // marketing consent, checked again at delivery: the template may have become MARKETING (Meta re-categorised it) or
    // the contact's opt-in may have been withdrawn since the message was queued. Automated senders (event, workflow,
    // follow-up, campaign, bot) then never send it — the same rule wa_send() applies. Test sends queued with
    // skip_opt_in_check (template_vars.skip_opt_in) and agent / system messages are not re-checked.
    if ($cat === 'MARKETING' && (int) ($contact['opt_in'] ?? 0) !== 1 && empty($tv['skip_opt_in'])
        && in_array($m['sender_type'], array_merge(wa_automated_sender_types(), ['bot']), true)) {
        $tplLabel = (string) (($tplNow['display_name'] ?? '') ?: ($tplNow['name'] ?? '') ?: ($m['template_name'] ?? ''));
        $why = 'Not sent: template "' . $tplLabel . '" is a MARKETING template' . (strtoupper((string) ($tv['category'] ?? '')) !== 'MARKETING' ? ' now (it was '
            . strtoupper((string) ($tv['category'] ?? '?')) . ' when the message was queued)' : '') . ' and the contact has not opted in to WhatsApp marketing messages.';
        db_update('wa_messages', ['status' => 'cancelled', 'error_code' => 'no_opt_in', 'error' => $why], 'id = ?', [(int) $m['id']]);
        db_update('wa_outbox', ['status' => 'cancelled', 'locked_at' => null, 'last_error' => 'no_opt_in: ' . $why], 'id = ?', [(int) $ob['id']]);
        wa_update_campaign_recipient((int) $m['id'], 'cancelled', $why);
        try {
            db_exec("UPDATE wa_campaign_recipients SET skip_reason = 'no_opt_in' WHERE message_id = ? AND status = 'skipped' AND (skip_reason IS NULL OR skip_reason = '')", [(int) $m['id']]);
        } catch (Throwable $e) {
        }
        return 'cancelled';
    }
    if ($m['type'] === 'template' && wa_mode() === 'live' && $tplNow && $tplNow['status'] !== 'approved') {
        wa_fail_message($m, (int) $ob['id'], 'template_not_approved', 'The template is ' . $tplNow['status'] . ' in Interakt — only approved templates can be sent.');
        return 'failed';
    }
    $payload = wa_build_payload($m, $contact);
    $now = wa_now();

    if (wa_mode() !== 'live') {
        $fakeId = 'test-' . wa_uuid();
        wa_interakt_simulate('POST', 'message/', $payload, ['result' => true, 'message' => 'Simulated (test mode): message not sent to WhatsApp', 'id' => $fakeId], (int) $m['id'], 201);
        db_update('wa_messages', ['status' => 'simulated', 'interakt_id' => $fakeId, 'sent_at' => $now, 'raw' => wa_json(['request' => $payload, 'mode' => 'test'])], 'id = ?', [(int) $m['id']]);
        db_update('wa_outbox', ['status' => 'done', 'locked_at' => null, 'last_error' => null], 'id = ?', [(int) $ob['id']]);
        db_update('wa_contacts', ['last_outbound_at' => $now], 'id = ?', [(int) $contact['id']]);
        wa_update_campaign_recipient((int) $m['id'], 'simulated');
        wa_event('WA_MESSAGE_STATUS', ['message_id' => (int) $m['id'], 'status' => 'simulated', 'campaign_id' => $m['campaign_id'], 'workflow_run_id' => $m['workflow_run_id'],
            'followup_id' => $m['followup_id']], ['source' => 'core', 'contact_id' => (int) $contact['id']]);
        return 'simulated';
    }

    $r = wa_interakt_request('POST', 'message/', $payload, ['message_id' => (int) $m['id']]);
    if ($r['ok'] && is_array($r['data']) && ($r['data']['result'] ?? null) === true) {
        $iid = isset($r['data']['id']) ? mb_substr((string) $r['data']['id'], 0, 64) : null;
        try {
            db_update('wa_messages', ['status' => 'sent', 'interakt_id' => $iid, 'sent_at' => $now, 'error' => null, 'error_code' => null, 'raw' => wa_json(['request' => $payload])], 'id = ?', [(int) $m['id']]);
        } catch (PDOException $e) {
            db_update('wa_messages', ['status' => 'sent', 'sent_at' => $now, 'raw' => wa_json(['request' => $payload, 'interakt_id' => $iid])], 'id = ?', [(int) $m['id']]);
        }
        db_update('wa_outbox', ['status' => 'done', 'locked_at' => null, 'last_error' => null], 'id = ?', [(int) $ob['id']]);
        db_update('wa_contacts', ['last_outbound_at' => $now], 'id = ?', [(int) $contact['id']]);
        wa_update_campaign_recipient((int) $m['id'], 'sent');
        wa_event('WA_MESSAGE_STATUS', ['message_id' => (int) $m['id'], 'status' => 'sent', 'campaign_id' => $m['campaign_id'], 'workflow_run_id' => $m['workflow_run_id'],
            'followup_id' => $m['followup_id']], ['source' => 'core', 'contact_id' => (int) $contact['id']]);
        return 'sent';
    }
    $error = (string) ($r['error'] ?? 'Unknown error');
    [$code, $retry] = wa_classify_error((int) $r['status'], $error);
    $attempts = (int) $ob['attempts'];
    if ($code === 'rate_limited') {
        db_update('wa_outbox', ['status' => 'pending', 'locked_at' => null, 'attempts' => max(0, $attempts - 1), 'scheduled_at' => date('Y-m-d H:i:s', time() + 60),
            'last_error' => 'Rate limited by Interakt — retrying in 1 minute'], 'id = ?', [(int) $ob['id']]);
        return 'throttled';
    }
    if ($retry && $attempts < (int) $ob['max_attempts']) {
        $delay = [60, 300, 900, 3600, 10800, 21600][min($attempts - 1, 5)] ?? 3600;
        db_update('wa_outbox', ['status' => 'pending', 'locked_at' => null, 'scheduled_at' => date('Y-m-d H:i:s', time() + $delay), 'last_error' => mb_substr($code . ': ' . $error, 0, 2000)], 'id = ?', [(int) $ob['id']]);
        db_update('wa_messages', ['error_code' => $code, 'error' => mb_substr($error, 0, 2000)], 'id = ?', [(int) $m['id']]);
        return 'retry';
    }
    wa_fail_message($m, (int) $ob['id'], $code, $error);
    return 'failed';
}

/**
 * Deliver due outbox rows (priority, then schedule). Throttled to wa_rate_per_minute (counting the last 60 s of
 * message/ calls), 429 → reschedule + stop, exponential retries up to max_attempts. GET_LOCK prevents overlap.
 */
function wa_process_outbox(float $budget = 15.0, int $max = 200): array
{
    $stats = ['processed' => 0, 'sent' => 0, 'simulated' => 0, 'failed' => 0, 'retry' => 0, 'throttled' => 0, 'cancelled' => 0];
    if ((int) db_value("SELECT GET_LOCK('gimt_wa_outbox', 0)") !== 1) {
        $stats['skipped'] = 1;
        return $stats;
    }
    try {
        $t0 = microtime(true);
        db_exec("UPDATE wa_outbox SET status = 'pending', locked_at = NULL WHERE status = 'processing' AND locked_at < ?", [date('Y-m-d H:i:s', time() - 300)]);
        $rate = wa_rate_per_minute();
        $recent = (int) db_value("SELECT COUNT(*) FROM wa_api_logs WHERE endpoint = 'message/' AND created_at >= ?", [date('Y-m-d H:i:s', time() - 60)]);
        $allowance = max(0, min($max, $rate - $recent));
        if ($allowance === 0) {
            $stats['throttled'] = 1;
            return $stats;
        }
        // Settings › Automation: while automations are paused (or quiet hours run) automated messages stay queued;
        // agent, bot and system replies keep flowing. They go out on the first run after resume / the quiet period.
        $hold = '';
        $holdArgs = [];
        $paused = function_exists('wa_adm_automation_paused') && wa_adm_automation_paused();
        $quiet = !$paused && function_exists('wa_adm_quiet_until') && wa_adm_quiet_until() !== null;
        if ($paused || $quiet) {
            $types = wa_automated_sender_types();
            $hold = ' AND NOT EXISTS (SELECT 1 FROM wa_messages hm WHERE hm.id = o.message_id AND hm.sender_type IN (' . implode(',', array_fill(0, count($types), '?')) . '))';
            $holdArgs = $types;
            $stats['held'] = $paused ? 'paused' : 'quiet_hours';
        }
        while ($stats['processed'] < $allowance && (microtime(true) - $t0) < $budget) {
            $batch = db_all("SELECT o.* FROM wa_outbox o WHERE o.status = 'pending' AND o.scheduled_at <= ?" . $hold . ' ORDER BY o.priority, o.scheduled_at, o.id LIMIT '
                . (int) min(25, $allowance - $stats['processed']), array_merge([wa_now()], $holdArgs));
            if (!$batch) {
                break;
            }
            foreach ($batch as $ob) {
                if ((microtime(true) - $t0) >= $budget) {
                    break 2;
                }
                $claimed = db_exec("UPDATE wa_outbox SET status = 'processing', locked_at = ?, attempts = attempts + 1 WHERE id = ? AND status = 'pending'", [wa_now(), (int) $ob['id']]);
                if ($claimed !== 1) {
                    continue;
                }
                $ob['attempts'] = (int) $ob['attempts'] + 1;
                try {
                    $res = wa_outbox_deliver($ob);
                } catch (Throwable $e) {
                    error_log('[GIMT] wa_outbox_deliver #' . $ob['id'] . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                    $res = 'retry';
                    try {
                        if ($ob['attempts'] < (int) $ob['max_attempts']) {
                            db_update('wa_outbox', ['status' => 'pending', 'locked_at' => null, 'scheduled_at' => date('Y-m-d H:i:s', time() + 120),
                                'last_error' => mb_substr($e->getMessage(), 0, 2000)], 'id = ?', [(int) $ob['id']]);
                        } else {
                            // out of attempts: fail the message too, so it never stays 'queued' forever
                            $fm = db_row('SELECT * FROM wa_messages WHERE id = ?', [(int) $ob['message_id']]);
                            if ($fm && $fm['status'] === 'queued') {
                                wa_fail_message($fm, (int) $ob['id'], 'send_failed', 'Internal error while sending: ' . $e->getMessage());
                            } else {
                                db_update('wa_outbox', ['status' => 'failed', 'locked_at' => null, 'last_error' => mb_substr($e->getMessage(), 0, 2000)], 'id = ?', [(int) $ob['id']]);
                            }
                            $res = 'failed';
                        }
                    } catch (Throwable $e2) {
                        error_log('[GIMT] wa_process_outbox recovery #' . $ob['id'] . ': ' . $e2->getMessage());
                    }
                }
                $stats['processed']++;
                $stats[$res] = ($stats[$res] ?? 0) + 1;
                if ($res === 'throttled') {
                    break 2;
                }
            }
        }
        return $stats;
    } finally {
        db_value("SELECT RELEASE_LOCK('gimt_wa_outbox')");
    }
}

/* =================================================================================================================
 * Media
 * ================================================================================================================= */

/** Accepted media for sending: ext => [kind, mimes, max bytes] (Meta limits). */
function wa_media_types(): array
{
    $mb = 1024 * 1024;
    return [
        'jpg' => ['image', ['image/jpeg'], 5 * $mb], 'jpeg' => ['image', ['image/jpeg'], 5 * $mb], 'png' => ['image', ['image/png'], 5 * $mb],
        'webp' => ['sticker', ['image/webp'], 500 * 1024],
        'mp4' => ['video', ['video/mp4'], 16 * $mb], '3gp' => ['video', ['video/3gpp', 'video/3gpp2'], 16 * $mb],
        'aac' => ['audio', ['audio/aac', 'audio/x-hx-aac-adts', 'audio/x-aac'], 16 * $mb], 'mp3' => ['audio', ['audio/mpeg', 'audio/mp3'], 16 * $mb],
        'm4a' => ['audio', ['audio/mp4', 'audio/x-m4a', 'video/mp4'], 16 * $mb], 'amr' => ['audio', ['audio/amr', 'audio/AMR'], 16 * $mb],
        'ogg' => ['audio', ['audio/ogg', 'application/ogg', 'video/ogg'], 16 * $mb], 'opus' => ['audio', ['audio/ogg', 'audio/opus'], 16 * $mb],
        'pdf' => ['document', ['application/pdf'], 100 * $mb],
        'doc' => ['document', ['application/msword', 'application/CDFV2', 'application/octet-stream'], 100 * $mb],
        'docx' => ['document', ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'], 100 * $mb],
        'xls' => ['document', ['application/vnd.ms-excel', 'application/CDFV2', 'application/octet-stream'], 100 * $mb],
        'xlsx' => ['document', ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], 100 * $mb],
        'ppt' => ['document', ['application/vnd.ms-powerpoint', 'application/CDFV2', 'application/octet-stream'], 100 * $mb],
        'pptx' => ['document', ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'], 100 * $mb],
        'txt' => ['document', ['text/plain'], 100 * $mb],
    ];
}

/**
 * Validate and store an uploaded file as a PUBLIC copy (assets/uploads/whatsapp/out/<yyyy>/<random>.<ext>) for media
 * sends / template headers. $kind restricts the type (image|video|audio|document|sticker; a PNG/JPEG is also accepted
 * as a 'document' header? no — kinds must match). Returns ['ok'=>true, url, path, mime, name, size, type] or ['ok'=>false,'error'].
 */
function wa_media_store_upload(?array $file, ?string $kind = null): array
{
    if (!$file || !isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'error' => 'No file was uploaded.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE ? 'The file is larger than the server allows.' : 'Upload failed. Please try again.'];
    }
    if (PHP_SAPI !== 'cli' && !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    $original = basename(str_replace('\\', '/', (string) $file['name']));
    if (preg_match('/\.(php\d?|phtml|phar|pl|py|cgi|asp|aspx|jsp|sh|exe|htaccess|svg|html?)(\.|$)/i', $original)) {
        return ['ok' => false, 'error' => 'This file type is not allowed.'];
    }
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $types = wa_media_types();
    if (!isset($types[$ext])) {
        return ['ok' => false, 'error' => 'File type .' . mb_substr((string) preg_replace('/[^a-z0-9]/i', '', $ext), 0, 10) . ' is not supported by WhatsApp. Allowed: ' . implode(', ', array_keys($types)) . '.'];
    }
    [$type, $mimes, $maxBytes] = $types[$ext];
    if ($kind !== null && $kind !== '' && $kind !== $type && !($kind === 'image' && $type === 'sticker')) {
        return ['ok' => false, 'error' => 'Please choose ' . ($kind === 'image' ? 'an' : 'a') . ' ' . $kind . ' file (this is ' . ($type === 'image' ? 'an' : 'a') . ' ' . $type . ').'];
    }
    if ($kind === 'image' && $type === 'sticker') {
        $type = 'image';
        $maxBytes = 5 * 1024 * 1024;
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        return ['ok' => false, 'error' => 'WhatsApp allows ' . $type . ' files up to ' . (function_exists('human_filesize') ? human_filesize($maxBytes) : round($maxBytes / 1048576) . ' MB') . '.'];
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $mimes, true)) {
        return ['ok' => false, 'error' => 'The file content does not match its extension.'];
    }
    if ($type === 'image' && !@getimagesize($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'The image file is invalid or corrupted.'];
    }
    $relDir = 'assets/uploads/whatsapp/out/' . date('Y');
    $absDir = APP_ROOT . '/' . $relDir;
    if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
        return ['ok' => false, 'error' => 'Upload directory is not writable.'];
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $absDir . '/' . $name;
    $moved = false;
    if ($type === 'image' && in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        $moved = wa_reencode_image($file['tmp_name'], $dest, $ext);
    }
    if (!$moved) {
        $moved = PHP_SAPI === 'cli' ? @copy($file['tmp_name'], $dest) : @move_uploaded_file($file['tmp_name'], $dest);
    }
    if (!$moved) {
        return ['ok' => false, 'error' => 'Could not save the uploaded file.'];
    }
    @chmod($dest, 0644);
    $path = $relDir . '/' . $name;
    return ['ok' => true, 'url' => absolute_url($path), 'path' => $path, 'mime' => $mime, 'name' => mb_substr($original, 0, 190), 'size' => (int) filesize($dest), 'type' => $type];
}

/** Re-encode a JPEG / PNG with GD (strips metadata and appended payloads; downsizes beyond 2400 px). */
function wa_reencode_image(string $src, string $dest, string $ext): bool
{
    if (!function_exists('imagecreatefromstring')) {
        return false;
    }
    $data = @file_get_contents($src);
    $img = $data !== false ? @imagecreatefromstring($data) : false;
    if (!$img) {
        return false;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    if (max($w, $h) > 2400) {
        $scale = 2400 / max($w, $h);
        $resized = imagescale($img, (int) round($w * $scale), (int) round($h * $scale));
        if ($resized) {
            $img = $resized;
        }
    }
    if ($ext === 'png') {
        imagesavealpha($img, true);
        return @imagepng($img, $dest, 6);
    }
    return @imagejpeg($img, $dest, 85);
}

/** True when the binary address $bin (inet_pton) lies inside $cidr ('10.0.0.0/8', '64:ff9b::/96'). */
function wa_ip_in_cidr(string $bin, string $cidr): bool
{
    [$net, $bits] = explode('/', $cidr) + [1 => null];
    $netBin = @inet_pton($net);
    if ($netBin === false || strlen($netBin) !== strlen($bin)) {
        return false;
    }
    $bits = $bits === null ? strlen($bin) * 8 : (int) $bits;
    $bytes = intdiv($bits, 8);
    if ($bytes && substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
        return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return (ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
}

/**
 * True only for a globally routable unicast address — the same blocked ranges as the workflow HTTP step
 * (wa_wf_ip_is_public): loopback, private (RFC 1918), CGNAT 100.64/10, link-local (cloud metadata 169.254.169.254),
 * benchmarking 198.18/15, documentation, multicast, reserved, unique-local / site-local IPv6, IPv4-mapped / -compatible
 * IPv6 (judged as the embedded IPv4) and the NAT64 (64:ff9b::/96, 64:ff9b:1::/48) / 6to4 / Teredo prefixes.
 */
function wa_ip_is_public(string $ip): bool
{
    $ip = trim($ip, '[] ');
    if (str_contains($ip, '%')) {
        return false; // zone ids (fe80::1%eth0) are always local
    }
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    if (strlen($bin) === 16) {
        if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff" || (substr($bin, 0, 12) === str_repeat("\0", 12) && substr($bin, 12) !== "\0\0\0\0" && substr($bin, 12) !== "\0\0\0\1")) {
            return wa_ip_is_public((string) inet_ntop(substr($bin, 12)));
        }
        foreach (['::/128', '::1/128', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/32', '2001:2::/48', '2001:10::/28', '2001:20::/28', '2001:db8::/32', '2002::/16',
            '3fff::/20', '5f00::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8'] as $cidr) {
            if (wa_ip_in_cidr($bin, $cidr)) {
                return false;
            }
        }
        return wa_ip_in_cidr($bin, '2000::/3'); // global unicast only
    }
    foreach (['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.31.196.0/24',
        '192.52.193.0/24', '192.88.99.0/24', '192.168.0.0/16', '192.175.48.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'] as $cidr) {
        if (wa_ip_in_cidr($bin, $cidr)) {
            return false;
        }
    }
    return true;
}

/**
 * SSRF guard for server-side fetches of URLs that arrive in webhooks: http(s) only, ports 80 / 443 only, no
 * credentials, no trailing-dot host names (curl would resolve them again and skip the pin), and the host must resolve to
 * public addresses only — every A / AAAA answer must pass wa_ip_is_public(). Returns ['host', 'port', 'ip'] (ip = the
 * address to pin with CURLOPT_RESOLVE) or null. Dev installs allow any http(s) host.
 */
function wa_public_target(string $url): ?array
{
    $u = parse_url($url);
    $scheme = strtolower((string) ($u['scheme'] ?? ''));
    if (!$u || !in_array($scheme, ['http', 'https'], true) || empty($u['host']) || isset($u['user']) || isset($u['pass'])) {
        return null;
    }
    $host = strtolower(trim((string) $u['host'], '[]'));
    $port = (int) ($u['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (is_dev()) {
        return ['host' => $host, 'port' => $port, 'ip' => null];
    }
    if (!in_array($port, [80, 443], true) || $host === '' || str_ends_with($host, '.') || str_contains($host, '%')) {
        return null;
    }
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $ips = gethostbynamel($host) ?: [];
        if (function_exists('dns_get_record')) {
            foreach ((@dns_get_record($host, DNS_AAAA)) ?: [] as $r) {
                if (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
    }
    if (!$ips) {
        return null;
    }
    foreach ($ips as $ip) {
        if (!wa_ip_is_public((string) $ip)) {
            return null;
        }
    }
    $v4 = array_values(array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
    $pin = $v4[0] ?? $ips[0];
    return ['host' => $host, 'port' => $port, 'ip' => str_contains($pin, ':') ? '[' . $pin . ']' : $pin];
}

/**
 * Download an inbound media URL into storage/whatsapp/YYYY/MM/ (private). Redirects are followed by hand (max 3) and
 * every hop passes wa_public_target() with the resolved address pinned (no SSRF, no DNS rebinding).
 * Returns ['path','mime','size'] or null.
 */
function wa_media_download(string $url, ?string $nameHint = null): ?array
{
    if (!preg_match('#^https?://#i', $url) || !function_exists('curl_init') || !wa_public_target($url)) {
        return null;
    }
    $relDir = 'storage/whatsapp/' . date('Y/m');
    $absDir = APP_ROOT . '/' . $relDir;
    if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
        return null;
    }
    $tmp = $absDir . '/.dl-' . bin2hex(random_bytes(8));
    $fh = @fopen($tmp, 'wb');
    if (!$fh) {
        return null;
    }
    $max = 100 * 1024 * 1024;
    $ok = false;
    $status = 0;
    $current = $url;
    for ($hop = 0; $hop <= 3; $hop++) {
        $target = wa_public_target($current);
        if (!$target) {
            $ok = false;
            break;
        }
        ftruncate($fh, 0);
        rewind($fh);
        $ch = curl_init($current);
        $opts = [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'GIMT-SmartCampus/1.0', CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => fn ($c, $dlTotal, $dlNow) => $dlNow > $max ? 1 : 0];
        if ($target['ip'] !== null) {
            $opts[CURLOPT_RESOLVE] = [$target['host'] . ':' . $target['port'] . ':' . $target['ip']];
        }
        if ($ca = wa_ca_bundle()) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $next = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        unset($ch);
        if ($ok && $status >= 300 && $status < 400 && $next !== '') {
            $current = $next;
            $ok = false;
            continue;
        }
        break;
    }
    fclose($fh);
    if (!$ok || $status < 200 || $status >= 300 || !filesize($tmp)) {
        @unlink($tmp);
        return null;
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'video/mp4' => 'mp4', 'video/3gpp' => '3gp',
        'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/amr' => 'amr', 'application/ogg' => 'ogg',
        'application/pdf' => 'pdf', 'text/plain' => 'txt', 'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx', 'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx', 'application/zip' => 'zip'];
    $ext = $extMap[$mime] ?? (strtolower(pathinfo((string) ($nameHint ?: parse_url($url, PHP_URL_PATH)), PATHINFO_EXTENSION)) ?: 'bin');
    if (!preg_match('/^[a-z0-9]{1,5}$/', $ext) || in_array($ext, ['php', 'phtml', 'phar', 'html', 'htm', 'svg', 'js'], true)) {
        $ext = 'bin';
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!@rename($tmp, $absDir . '/' . $name)) {
        @unlink($tmp);
        return null;
    }
    return ['path' => $relDir . '/' . $name, 'mime' => $mime, 'size' => (int) filesize($absDir . '/' . $name)];
}

/* =================================================================================================================
 * Webhooks
 * ================================================================================================================= */

/** Event type of a webhook payload (handles the CTA-click shape without type/data and account_update's `field`). */
function wa_webhook_type(array $p): string
{
    if (!empty($p['type']) && is_string($p['type'])) {
        return mb_substr($p['type'], 0, 60);
    }
    if (!empty($p['field']) && is_string($p['field'])) {
        return mb_substr($p['field'], 0, 60);
    }
    if (isset($p['event']['click_type']) || (isset($p['customer'], $p['message'], $p['event']))) {
        return 'message_api_clicked';
    }
    return 'unknown';
}

/** Largest webhook body GIMT accepts, in bytes (Interakt webhooks are a few KB; bigger requests get HTTP 413). */
function wa_webhook_max_bytes(): int
{
    return 131072;
}

/**
 * Limits for webhooks that will never be processed (forged, unsigned, wrong secret): at most rows_per_hour such rows
 * are stored per hour across all senders (the rest are only counted), and the full payloads kept for later
 * verification (signed while no secret is saved yet) use at most kept_bytes_per_hour.
 */
function wa_webhook_reject_limits(): array
{
    return ['rows_per_hour' => 500, 'kept_bytes_per_hour' => 2 * 1048576];
}

/**
 * Fixed-window counter in rate_limits that keeps counting past any cap (unlike rate_limit()). Adds $add (0 = read only)
 * and returns the total of the current window.
 */
function wa_window_counter(string $key, int $add, int $windowSeconds): int
{
    $key = substr($key, 0, 190);
    $add = max(0, $add);
    $row = db_row('SELECT hits, reset_at FROM rate_limits WHERE rl_key = ?', [$key]);
    if (!$row || strtotime((string) $row['reset_at']) <= time()) {
        db_exec('REPLACE INTO rate_limits (rl_key, hits, reset_at) VALUES (?, ?, ?)', [$key, $add, date('Y-m-d H:i:s', time() + $windowSeconds)]);
        return $add;
    }
    if ($add > 0) {
        db_exec('UPDATE rate_limits SET hits = hits + ? WHERE rl_key = ?', [$add, $key]);
    }
    return (int) $row['hits'] + $add;
}

/** Current total of a wa_window_counter() window without starting a new one (0 when none is running). */
function wa_window_count(string $key): int
{
    $row = db_row('SELECT hits, reset_at FROM rate_limits WHERE rl_key = ?', [substr($key, 0, 190)]);
    return $row && strtotime((string) $row['reset_at']) > time() ? (int) $row['hits'] : 0;
}

/** log_system() at most once per 10 minutes per $kind, so rejected webhooks can never flood system_logs. */
function wa_webhook_log_throttled(string $kind, string $message, array $context = []): void
{
    try {
        if (wa_window_counter('wa-webhook-log:' . $kind, 1, 600) === 1) {
            log_system('warning', $message . ' Similar events in the next 10 minutes are not logged again — see WhatsApp › API & Webhooks › Webhook logs.', $context);
        }
    } catch (Throwable $e) {
        error_log('[GIMT] ' . $message);
    }
}

/** The Interakt-Signature header of a request ('' when absent). */
function wa_webhook_signature_header(array $server): string
{
    return trim((string) ($server['HTTP_INTERAKT_SIGNATURE'] ?? ($server['HTTP_X_INTERAKT_SIGNATURE'] ?? '')));
}

/** `sha256=<hex HMAC-SHA256 of the raw body>` checked in constant time against $secret (hex case-insensitive). */
function wa_webhook_signature_ok(string $raw, string $sig, string $secret): bool
{
    $sig = strtolower(trim($sig));
    return $secret !== '' && $sig !== '' && hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $sig);
}

/**
 * Decide, before anything is stored, whether a webhook request may be processed.
 *   valid    1 = signature verified with the saved secret, 0 = wrong / missing signature while a secret is saved,
 *            null = no secret saved
 *   trusted  process it: a built-in simulator (WA_SIMULATED — set only by wa_simulate_webhook(), never by an HTTP
 *            header), a verified signature, or a dev install without a secret. Every other install ignores unsigned
 *            webhooks in test AND live mode: forged STOP / START keywords, inbound messages, ticks or template status
 *            changes would otherwise be stored as genuine (consent records included) and carried into live mode.
 *   reason   why it is not trusted: invalid_signature | unsigned
 * @return array{valid:?int,trusted:bool,reason:?string,signature:string,simulated:bool}
 */
function wa_webhook_verdict(string $raw, array $server): array
{
    $simulated = !empty($server['WA_SIMULATED']);
    $secret = (string) wa_setting('wa_webhook_secret', '');
    $sig = wa_webhook_signature_header($server);
    $valid = $secret !== '' ? (wa_webhook_signature_ok($raw, $sig, $secret) ? 1 : 0) : null;
    $trusted = $simulated || $valid === 1 || ($valid === null && is_dev());
    return ['valid' => $valid, 'trusted' => $trusted, 'reason' => $trusted ? null : ($valid === 0 ? 'invalid_signature' : 'unsigned'),
        'signature' => $sig, 'simulated' => $simulated];
}

/** First ~1 KB of a body, cut on a UTF-8 character boundary (metadata-only webhook rows). */
function wa_webhook_excerpt(string $raw, int $bytes = 1024): string
{
    return mb_scrub(mb_strcut($raw, 0, $bytes, 'UTF-8'), 'UTF-8');
}

/**
 * Store an incoming webhook quickly; processing happens later (wa_process_webhooks from the worker). The decision is
 * made before anything is stored (wa_webhook_verdict()):
 *   - trusted → stored in full as 'received';
 *   - not trusted (invalid signature, unsigned on a non-dev install in test or live mode, invalid JSON, over 128 KB) →
 *     'ignored' / 'failed', never processed, and only metadata is stored: event type, size, sha256, client IP, the
 *     Interakt-Signature header and the first 1 KB (headers.payload_stored = 'excerpt'). Exception: a request that
 *     carries a signature while no secret is saved yet keeps its payload (headers.payload_stored = 'full', within an
 *     hourly byte budget) so it can be verified and reprocessed once the secret is saved — wa_webhook_log_can_reprocess().
 *   - at most wa_webhook_reject_limits()['rows_per_hour'] rejected rows are stored per hour; the rest are only counted
 *     (rate_limits key 'wa-webhook-rejected'), and log_system() runs at most once per 10 minutes per kind.
 * @return array{ok:bool,id:?int,event_type:string,signature_valid:?int,error:?string,dropped?:bool}
 */
function wa_webhook_receive(string $raw, array $server): array
{
    $v = wa_webhook_verdict($raw, $server);
    $valid = $v['valid'];
    $sig = $v['signature'];
    $size = strlen($raw);
    $p = json_decode($raw, true);
    $isJson = is_array($p);
    $type = $isJson ? wa_webhook_type($p) : 'invalid';
    $tooLarge = $size > wa_webhook_max_bytes() && !$v['simulated'];
    $reason = !$isJson ? 'invalid_json' : ($tooLarge ? 'too_large' : $v['reason']);
    $iid = null;
    if ($isJson) {
        $iid = $p['data']['message']['id'] ?? ($p['message']['id'] ?? null);
        $iid = is_scalar($iid) ? mb_substr((string) $iid, 0, 64) : null;
    }
    $headers = array_filter([
        'content-type' => isset($server['CONTENT_TYPE']) ? mb_substr((string) $server['CONTENT_TYPE'], 0, 100) : null,
        'user-agent' => isset($server['HTTP_USER_AGENT']) ? mb_substr((string) $server['HTTP_USER_AGENT'], 0, 200) : null,
        'interakt-signature' => $sig !== '' ? mb_substr($sig, 0, 80) : null,
        'ip' => function_exists('client_ip') && PHP_SAPI !== 'cli' ? client_ip() : null,
    ]);
    $payload = $raw;
    $status = 'received';
    $error = null;
    if ($reason !== null) {
        $limits = wa_webhook_reject_limits();
        $n = wa_window_counter('wa-webhook-rejected', 1, 3600);
        if ($n > $limits['rows_per_hour']) {
            wa_webhook_log_throttled('dropped', 'WhatsApp webhooks dropped: more than ' . $limits['rows_per_hour'] . ' rejected webhooks arrived this hour ('
                . $n . ' so far); the extra ones are counted but not stored.', ['count' => $n, 'reason' => $reason, 'ip' => $headers['ip'] ?? null]);
            return ['ok' => false, 'id' => null, 'event_type' => $type, 'signature_valid' => $valid, 'error' => 'Not stored: hourly limit for rejected webhooks reached', 'dropped' => true];
        }
        // signed while no secret is saved yet: keep the payload so it can be verified once the secret is saved
        $keep = $reason === 'unsigned' && $sig !== '' && wa_window_count('wa-webhook-kept-bytes') + $size <= $limits['kept_bytes_per_hour'];
        if ($keep) {
            wa_window_counter('wa-webhook-kept-bytes', $size, 3600);
        }
        $headers += ['size' => $size, 'sha256' => hash('sha256', $raw), 'payload_stored' => $keep ? 'full' : 'excerpt', 'rejected' => $reason];
        $payload = $keep ? $raw : wa_webhook_excerpt($raw);
        $status = $isJson ? 'ignored' : 'failed';
        $saveSecret = 'Webhook secret not configured — generate one in WhatsApp › Integrations › Webhook setup, save it and enter the same secret in'
            . ' Interakt › Developer Settings › Webhooks';
        $error = match ($reason) {
            'invalid_json' => 'Invalid JSON (only an excerpt is stored)',
            'too_large' => 'Payload too large (' . $size . ' bytes; the limit is ' . (wa_webhook_max_bytes() / 1024) . ' KB) — only an excerpt is stored',
            'invalid_signature' => 'Invalid signature — the Interakt-Signature header does not match the saved webhook secret (only an excerpt is stored)',
            default => $sig === ''
                ? $saveSecret . '. Unsigned webhooks are never processed (only an excerpt is stored).'
                : ($keep ? $saveSecret . '. The signed payload is kept so it can be verified and reprocessed once the secret is saved.'
                    : $saveSecret . '. The hourly storage budget for unverified payloads is used up, so only an excerpt is stored.'),
        };
        wa_webhook_log_throttled($reason, match ($reason) {
            'invalid_signature' => 'WhatsApp webhook rejected (invalid Interakt signature — the secret in GIMT and Interakt must match).',
            'unsigned' => 'WhatsApp webhook ignored (no webhook secret is saved; unsigned webhooks are never processed outside dev installs).',
            default => 'WhatsApp webhook rejected (' . $reason . ').',
        }, ['event_type' => $type, 'size' => $size, 'ip' => $headers['ip'] ?? null]);
    }
    $id = db_insert('wa_webhook_logs', [
        'event_type' => $type,
        'signature_valid' => $valid,
        'payload' => $payload,
        'headers' => wa_json($headers),
        'interakt_message_id' => $iid,
        'status' => $status,
        'error' => $error,
        'is_simulated' => $v['simulated'] ? 1 : 0,
        'processed_at' => $status === 'received' ? null : wa_now(),
    ]);
    if ($valid === 1 && !$v['simulated']) {
        wa_webhook_mark_signed(); // webhook health (wa_webhook_health)
    }
    return ['ok' => $status === 'received', 'id' => $id, 'event_type' => $type, 'signature_valid' => $valid, 'error' => $error];
}

/**
 * May this stored webhook be run again (reprocess)? Only when it would be trusted if it arrived now:
 *   - simulated by GIMT's own test tools, or
 *   - its signature was verified when it arrived (signature_valid = 1), or
 *   - it arrived while no secret was saved (signature_valid NULL) and either a secret is saved now AND the stored
 *     Interakt-Signature header verifies against the stored full payload, or this is a dev install that still has no
 *     secret (webhooks are trusted there while no secret is saved).
 * Never: rows that failed the signature check on arrival (signature_valid = 0: a secret was saved and the signature was
 * wrong or missing) — on every install, dev included, whatever secret is saved now; and rows that kept only metadata
 * (headers.payload_stored = 'excerpt').
 * @return array{ok:bool,reason:string,verified_now?:bool}
 */
function wa_webhook_log_can_reprocess(array $row): array
{
    $headers = wa_json_decode($row['headers'] ?? null, []);
    $headers = is_array($headers) ? $headers : [];
    $sig = trim((string) ($headers['interakt-signature'] ?? ''));
    $sv = isset($row['signature_valid']) && $row['signature_valid'] !== null && $row['signature_valid'] !== '' ? (int) $row['signature_valid'] : null;
    if ($sv === 0) { // GIMT's simulators sign with the saved secret, so they never store 0
        return ['ok' => false, 'reason' => $sig !== ''
            ? 'This webhook failed the signature check when it arrived: its Interakt signature did not match the webhook secret saved at that time, so it'
                . ' may be forged and can never be reprocessed.'
            : 'This webhook arrived without an Interakt signature while a webhook secret was saved, so it failed the signature check, may be forged and can'
                . ' never be reprocessed.'];
    }
    if (($headers['payload_stored'] ?? 'full') === 'excerpt') {
        return ['ok' => false, 'reason' => 'Only an excerpt of this webhook was stored because it was rejected when it arrived, so it cannot be reprocessed.'];
    }
    $raw = (string) ($row['payload'] ?? '');
    if (!is_array(json_decode($raw, true))) {
        return ['ok' => false, 'reason' => 'The stored payload is not valid JSON, so it cannot be reprocessed.'];
    }
    if (!empty($row['is_simulated'])) {
        return ['ok' => true, 'reason' => 'Simulated by the GIMT test tools.'];
    }
    if ($sv === 1) {
        return ['ok' => true, 'reason' => 'The Interakt signature was verified when this webhook arrived.'];
    }
    // signature_valid NULL: no secret was saved when it arrived
    $secret = (string) wa_setting('wa_webhook_secret', '');
    if ($secret !== '') {
        if ($sig === '') {
            return ['ok' => false, 'reason' => 'This webhook arrived without an Interakt signature before the webhook secret was saved, so it may be forged and'
                . ' cannot be reprocessed.'];
        }
        return wa_webhook_signature_ok($raw, $sig, $secret)
            ? ['ok' => true, 'reason' => 'The stored Interakt signature matches the webhook secret saved now.', 'verified_now' => true]
            : ['ok' => false, 'reason' => 'The stored Interakt signature does not match the webhook secret saved now, so it may be forged and cannot be reprocessed.'];
    }
    if (is_dev()) {
        return ['ok' => true, 'reason' => 'Development install with no webhook secret saved: webhooks that arrived while no secret was saved are trusted here.'];
    }
    return ['ok' => false, 'reason' => $sig !== ''
        ? 'Save the webhook secret first: the stored Interakt signature can only be verified once the secret is saved.'
        : 'This webhook arrived without an Interakt signature, so it may be forged and cannot be reprocessed.'];
}

/** Process pending webhooks (oldest first) within the budget. GET_LOCK prevents parallel processing. */
function wa_process_webhooks(float $budget = 5.0): array
{
    $stats = ['processed' => 0, 'ignored' => 0, 'failed' => 0];
    if ((int) db_value("SELECT GET_LOCK('gimt_wa_webhooks', 0)") !== 1) {
        $stats['skipped'] = 1;
        return $stats;
    }
    try {
        $t0 = microtime(true);
        while ((microtime(true) - $t0) < $budget) {
            $rows = db_all("SELECT id FROM wa_webhook_logs WHERE status = 'received' ORDER BY id LIMIT 25");
            if (!$rows) {
                break;
            }
            foreach ($rows as $r) {
                $res = wa_process_webhook_log((int) $r['id']);
                $stats[$res] = ($stats[$res] ?? 0) + 1;
                if ((microtime(true) - $t0) >= $budget) {
                    break 2;
                }
            }
        }
        return $stats;
    } finally {
        db_value("SELECT RELEASE_LOCK('gimt_wa_webhooks')");
    }
}

/**
 * Process one stored webhook. Returns 'processed' | 'ignored' | 'failed'. Runs as background work (wa_as_worker()):
 * whatever the webhook causes (opt-in / opt-out, inbound message, stage change) is the contact's or Interakt's doing, so
 * it is never attributed to a staff member — also when the test tools or "Reprocess" run it inside a staff request.
 */
function wa_process_webhook_log(int $id): string
{
    $log = db_row('SELECT * FROM wa_webhook_logs WHERE id = ?', [$id]);
    if (!$log || $log['status'] !== 'received') {
        return 'ignored';
    }
    $p = json_decode((string) $log['payload'], true);
    try {
        if (!is_array($p)) {
            throw new RuntimeException('Invalid JSON');
        }
        [$status, $note] = wa_as_worker(fn () => wa_webhook_handle($p, $log));
        db_update('wa_webhook_logs', ['status' => $status, 'error' => $note !== null ? mb_substr($note, 0, 2000) : null, 'processed_at' => wa_now()], 'id = ?', [$id]);
        return $status;
    } catch (Throwable $e) {
        error_log('[GIMT] WhatsApp webhook #' . $id . ' failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        db_update('wa_webhook_logs', ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'processed_at' => wa_now()], 'id = ?', [$id]);
        return 'failed';
    }
}

/** Dispatch a webhook payload. Returns [status, note]. */
function wa_webhook_handle(array $p, array $log): array
{
    $type = wa_webhook_type($p);
    if (preg_match('/^message_(api|campaign)_(sent|delivered|read|failed)$/', $type, $m)) {
        return wa_webhook_status($p, $m[2]);
    }
    if ($type === 'message_received') {
        return wa_webhook_inbound($p);
    }
    if ($type === 'message_api_clicked' || $type === 'message_campaign_clicked') {
        return wa_webhook_click($p);
    }
    if ($type === 'message_template_status_update') {
        return wa_webhook_template_status($p);
    }
    return ['ignored', 'Event type not handled: ' . $type];
}

/** Find the GIMT message a webhook refers to (Interakt id, then callbackData gimt:msg:<id>). */
function wa_webhook_find_message(array $msg): ?array
{
    $iid = isset($msg['id']) && is_scalar($msg['id']) ? (string) $msg['id'] : '';
    if ($iid !== '') {
        $row = db_row('SELECT * FROM wa_messages WHERE interakt_id = ?', [$iid]);
        if ($row) {
            return $row;
        }
    }
    $cb = $msg['meta_data']['source_data']['callback_data'] ?? null;
    if (is_string($cb) && preg_match('/^gimt:msg:(\d+)/', $cb, $mm)) {
        return db_row('SELECT * FROM wa_messages WHERE id = ?', [(int) $mm[1]]);
    }
    return null;
}

/** message_api_* / message_campaign_* status updates. */
function wa_webhook_status(array $p, string $status): array
{
    $msg = $p['data']['message'] ?? [];
    if (!is_array($msg)) {
        return ['ignored', 'No message in payload'];
    }
    $row = wa_webhook_find_message($msg);
    if (!$row) {
        return ['ignored', 'Unknown message ' . ($msg['id'] ?? '')];
    }
    $set = [];
    if (!empty($msg['id']) && empty($row['interakt_id'])) {
        $set['interakt_id'] = mb_substr((string) $msg['id'], 0, 64);
    }
    $sentAt = wa_utc_to_local($msg['received_at_utc'] ?? null);
    $delAt = wa_utc_to_local($msg['delivered_at_utc'] ?? null);
    $readAt = wa_utc_to_local($msg['seen_at_utc'] ?? null);
    if ($sentAt && empty($row['sent_at'])) {
        $set['sent_at'] = $sentAt;
    }
    if ($delAt && empty($row['delivered_at'])) {
        $set['delivered_at'] = $delAt;
    }
    if ($readAt && empty($row['read_at'])) {
        $set['read_at'] = $readAt;
    }
    $now = wa_now();
    if ($status === 'delivered' && empty($row['delivered_at']) && !isset($set['delivered_at'])) {
        $set['delivered_at'] = $now;
    }
    if ($status === 'read') {
        if (empty($row['read_at']) && !isset($set['read_at'])) {
            $set['read_at'] = $now;
        }
        if (empty($row['delivered_at']) && !isset($set['delivered_at'])) {
            $set['delivered_at'] = $set['read_at'] ?? $now;
        }
    }
    $cost = $msg['meta_data']['message_cost']['actual_message_cost'] ?? null;
    if ($cost !== null && is_numeric($cost)) {
        $set['cost'] = round((float) $cost, 4);
    }
    $cur = $row['status'];
    $changed = false;
    if ($status === 'failed') {
        if (wa_status_rank($cur) < 2) {
            $set['status'] = 'failed';
            $set['failed_at'] = $now;
            $set['error_code'] = mb_substr((string) ($msg['channel_error_code'] ?? 'failed'), 0, 40);
            $set['error'] = mb_substr((string) ($msg['channel_failure_reason'] ?? 'Delivery failed'), 0, 2000);
            $changed = true;
        }
    } elseif (wa_status_rank($status) > wa_status_rank($cur) && $cur !== 'failed') {
        $set['status'] = $status;
        $changed = true;
    } elseif ($cur === 'simulated' && $status === 'sent') {
        $set['status'] = 'sent';
        $changed = true;
    }
    if ($set) {
        db_update('wa_messages', $set, 'id = ?', [(int) $row['id']]);
    }
    if ($changed) {
        wa_update_campaign_recipient((int) $row['id'], $set['status'], $set['error'] ?? null);
        if ($set['status'] === 'failed') {
            db_exec("UPDATE wa_outbox SET status = 'failed', last_error = ? WHERE message_id = ? AND status <> 'failed'", ['webhook: ' . ($set['error'] ?? ''), (int) $row['id']]);
        }
        wa_event('WA_MESSAGE_STATUS', ['message_id' => (int) $row['id'], 'status' => $set['status'], 'error_code' => $set['error_code'] ?? null, 'error' => $set['error'] ?? null,
            'campaign_id' => $row['campaign_id'], 'workflow_run_id' => $row['workflow_run_id'], 'followup_id' => $row['followup_id']],
            ['source' => 'webhook', 'contact_id' => (int) $row['contact_id']]);
    }
    return ['processed', $changed ? null : 'No status change (already ' . $cur . ')'];
}

/** Opt-out / opt-in keywords. Returns 'out' | 'in' | null. */
function wa_opt_keyword(?string $text): ?string
{
    $t = strtolower(trim((string) preg_replace('/[^\p{L}\p{N} ]+/u', '', (string) $text)));
    $t = (string) preg_replace('/\s+/', ' ', $t);
    if ($t === '') {
        return null;
    }
    if (in_array($t, ['stop', 'unsubscribe', 'stop all', 'opt out', 'optout', 'band karo', 'bandh karo', 'band kro', 'bandh kro', 'band kar do', 'message band karo', 'बंद करो', 'बंद'], true)) {
        return 'out';
    }
    if (in_array($t, ['start', 'subscribe', 'unstop', 'opt in', 'optin', 'shuru karo', 'chalu karo'], true)) {
        return 'in';
    }
    return null;
}

/**
 * The outbound message a button / list reply answers: the newest template (quick-reply buttons / payloads) or
 * interactive message of the contact in the last 30 days whose button or row matches the reply id, else its title.
 * Used for reply_to_id (template click analytics, workflows waiting for a reply).
 */
function wa_find_reply_target(int $contactId, ?string $replyId, ?string $title): ?int
{
    $replyId = trim((string) $replyId);
    $title = mb_strtolower(trim((string) $title));
    if ($contactId <= 0 || ($replyId === '' && $title === '')) {
        return null;
    }
    $rows = db_all("SELECT id, type, interactive, template_vars FROM wa_messages WHERE contact_id = ? AND direction = 'out'
                     AND type IN ('template','interactive_button','interactive_list') AND created_at >= ? ORDER BY id DESC LIMIT 40",
        [$contactId, date('Y-m-d H:i:s', time() - 30 * 86400)]);
    $byTitle = null;
    foreach ($rows as $r) {
        $in = wa_json_decode($r['interactive'] ?? null, []) ?: [];
        $ids = [];
        $titles = [];
        if ($r['type'] === 'template') {
            foreach ((array) ($in['template']['buttons'] ?? []) as $b) {
                if (is_array($b) && strtoupper((string) ($b['type'] ?? '')) === 'QUICK_REPLY') {
                    $titles[] = (string) ($b['text'] ?? '');
                }
            }
            foreach ((array) (wa_json_decode($r['template_vars'] ?? null, [])['payloads'] ?? []) as $pv) {
                $ids[] = (string) (is_array($pv) ? ($pv[0] ?? '') : $pv);
            }
        } elseif ($r['type'] === 'interactive_button') {
            foreach ((array) ($in['buttons'] ?? []) as $b) {
                $ids[] = (string) ($b['id'] ?? '');
                $titles[] = (string) ($b['title'] ?? '');
            }
        } else {
            foreach ((array) ($in['sections'] ?? []) as $s) {
                foreach ((array) ($s['rows'] ?? []) as $row) {
                    $ids[] = (string) ($row['id'] ?? '');
                    $titles[] = (string) ($row['title'] ?? '');
                }
            }
        }
        if ($replyId !== '' && in_array($replyId, array_filter($ids, fn ($x) => $x !== ''), true)) {
            return (int) $r['id'];
        }
        if ($byTitle === null && $title !== '' && in_array($title, array_map(fn ($t) => mb_strtolower(trim($t)), $titles), true)) {
            $byTitle = (int) $r['id'];
        }
    }
    return $byTitle;
}

/**
 * Interakt may report one quick-reply tap twice (message_api_clicked and message_received). The twin of an inbound
 * button reply: same contact and text within 2 minutes, recorded through the other webhook. Returns its row or null.
 */
function wa_find_button_reply_twin(int $contactId, string $text, string $at, bool $fromClick): ?array
{
    if ($contactId <= 0 || trim($text) === '') {
        return null;
    }
    $t = strtotime($at) ?: time();
    return db_row("SELECT id, reply_to_id FROM wa_messages WHERE contact_id = ? AND direction = 'in' AND type = 'button_reply' AND body = ?
                    AND created_at BETWEEN ? AND ? AND " . ($fromClick ? "(interakt_id IS NULL OR interakt_id NOT LIKE 'qr-%')" : "interakt_id LIKE 'qr-%'") . ' ORDER BY id DESC LIMIT 1',
        [$contactId, $text, date('Y-m-d H:i:s', $t - 120), date('Y-m-d H:i:s', $t + 120)]);
}

/** Store an inbound message for a contact (conversation reopen, unread++, window) and fire WA_MESSAGE_RECEIVED. */
function wa_record_inbound(array $contact, array $m, array $raw, string $source = 'webhook'): ?int
{
    $at = $m['created_at'] ?? wa_now();
    if ($at > wa_now()) {
        $at = wa_now();
    }
    if (in_array($m['type'] ?? '', ['button_reply', 'list_reply'], true) && empty($m['reply_to_id'])) {
        $m['reply_to_id'] = wa_find_reply_target((int) $contact['id'], isset($m['button_payload']) && is_scalar($m['button_payload']) ? (string) $m['button_payload'] : null, $m['body'] ?? null);
    }
    if (($m['type'] ?? '') === 'button_reply' && ($twin = wa_find_button_reply_twin((int) $contact['id'], (string) ($m['body'] ?? ''), $at, str_starts_with((string) ($m['interakt_id'] ?? ''), 'qr-')))) {
        if (empty($twin['reply_to_id']) && !empty($m['reply_to_id'])) {
            db_update('wa_messages', ['reply_to_id' => (int) $m['reply_to_id']], 'id = ?', [(int) $twin['id']]);
        }
        return null; // the same tap was already recorded through the other webhook
    }
    $conv = wa_conversation_for((int) $contact['id']);
    $row = [
        'conversation_id' => (int) $conv['id'],
        'contact_id' => (int) $contact['id'],
        'direction' => 'in',
        'type' => $m['type'],
        'body' => $m['body'] ?? null,
        'media_url' => isset($m['media_url']) ? mb_substr((string) $m['media_url'], 0, 500) : null,
        'media_local' => $m['media_local'] ?? null,
        'media_mime' => $m['media_mime'] ?? null,
        'file_name' => $m['file_name'] ?? null,
        'interactive' => isset($m['interactive']) ? wa_json($m['interactive']) : null,
        'interakt_id' => $m['interakt_id'] ?? null,
        'status' => 'received',
        'sender_type' => 'contact',
        'reply_to_id' => $m['reply_to_id'] ?? null,
        'raw' => wa_json($raw),
        'created_at' => $at,
    ];
    try {
        $id = db_insert('wa_messages', $row);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return null; // duplicate delivery of the same Interakt message
        }
        throw $e;
    }
    $preview = wa_preview(($m['body'] ?? '') !== '' ? $m['body'] : ucfirst(str_replace('_', ' ', $m['type'])));
    $convSet = ['last_message_at' => $at, 'last_message_preview' => mb_substr($preview, 0, 255), 'last_message_direction' => 'in', 'last_inbound_at' => $at];
    if ($conv['status'] === 'resolved') {
        $convSet['status'] = 'open';
        $convSet['resolved_at'] = null;
        $convSet['resolved_by'] = null;
    }
    db_update('wa_conversations', $convSet, 'id = ?', [(int) $conv['id']]);
    db_exec('UPDATE wa_conversations SET unread_count = unread_count + 1 WHERE id = ?', [(int) $conv['id']]);
    db_update('wa_contacts', ['last_inbound_at' => $at, 'last_message_at' => $at], 'id = ?', [(int) $contact['id']]);
    if ($conv['status'] === 'resolved') {
        wa_agent_log('reopen', (int) $conv['id'], (int) $contact['id'], 'Reopened by an inbound message');
    }

    // opt-out / opt-in keywords
    if (in_array($m['type'], ['text', 'button_reply'], true)) {
        $kw = wa_opt_keyword($m['body'] ?? '');
        if ($kw === 'out') {
            wa_contact_opt((int) $contact['id'], false, 'inbound STOP');
            $reply = trim((string) wa_setting('wa_optout_reply', ''));
            if ($reply !== '') {
                wa_send_text((int) $contact['id'], $reply, ['sender_type' => 'system', 'priority' => 1]);
            }
        } elseif ($kw === 'in') {
            wa_contact_opt((int) $contact['id'], true, 'inbound START');
            $reply = trim((string) wa_setting('wa_optin_reply', ''));
            if ($reply !== '') {
                wa_send_text((int) $contact['id'], $reply, ['sender_type' => 'system', 'priority' => 1]);
            }
        }
    }

    wa_event('WA_MESSAGE_RECEIVED', [
        'message_id' => $id, 'conversation_id' => (int) $conv['id'], 'contact_id' => (int) $contact['id'], 'type' => $m['type'],
        'text' => $m['body'] ?? null, 'button_payload' => $m['button_payload'] ?? null, 'phone' => $contact['phone'], 'name' => $contact['name'],
        'opt_keyword' => isset($kw) ? $kw : null,
    ], ['source' => $source, 'contact_id' => (int) $contact['id'], 'dedupe_key' => !empty($m['interakt_id']) ? 'in:' . $m['interakt_id'] : null]);
    return $id;
}

/** Contact for a webhook customer (created with source inbound when new). */
function wa_webhook_contact(array $customer): ?array
{
    $phone = $customer['channel_phone_number'] ?? null;
    if (!$phone && !empty($customer['phone_number'])) {
        $phone = ltrim((string) ($customer['country_code'] ?? '+91'), '+') . preg_replace('/\D+/', '', (string) $customer['phone_number']);
    }
    if (!$phone) {
        return null;
    }
    $norm = wa_normalize_phone('+' . preg_replace('/\D+/', '', (string) $phone));
    if (!$norm) {
        return null;
    }
    $traits = is_array($customer['traits'] ?? null) ? $customer['traits'] : [];
    $name = $traits['name'] ?? ($customer['name'] ?? null);
    $id = wa_contact_upsert(['phone' => $norm['phone'], 'name' => is_scalar($name) ? (string) $name : null,
        'email' => isset($traits['email']) && is_scalar($traits['email']) && filter_var($traits['email'], FILTER_VALIDATE_EMAIL) ? $traits['email'] : null], ['source' => 'inbound']);
    $c = wa_contact($id);
    if ($c && !empty($customer['id']) && empty($c['interakt_user_id'])) {
        db_exec('UPDATE wa_contacts SET interakt_user_id = ? WHERE id = ?', [mb_substr((string) $customer['id'], 0, 64), $id]);
    }
    return $c;
}

/** message_received → contact upsert, conversation, inbound message (+ media download), opt keywords, WA_MESSAGE_RECEIVED. */
function wa_webhook_inbound(array $p): array
{
    $data = $p['data'] ?? [];
    $msg = is_array($data['message'] ?? null) ? $data['message'] : [];
    $contact = wa_webhook_contact(is_array($data['customer'] ?? null) ? $data['customer'] : []);
    if (!$contact) {
        return ['ignored', 'No valid customer phone'];
    }
    $iid = isset($msg['id']) && is_scalar($msg['id']) ? mb_substr((string) $msg['id'], 0, 64) : null;
    if ($iid && db_value('SELECT id FROM wa_messages WHERE interakt_id = ?', [$iid])) {
        return ['ignored', 'Duplicate message ' . $iid];
    }
    $ctype = strtolower((string) ($msg['message_content_type'] ?? 'Text'));
    $text = $msg['message'] ?? null;
    $decoded = is_string($text) ? json_decode($text, true) : (is_array($text) ? $text : null);
    $map = ['text' => 'text', 'image' => 'image', 'document' => 'document', 'audio' => 'audio', 'voice' => 'audio', 'video' => 'video', 'sticker' => 'sticker',
        'location' => 'location', 'contacts' => 'contacts', 'contact' => 'contacts', 'interactivebuttonreply' => 'button_reply', 'buttonreply' => 'button_reply',
        'button' => 'button_reply', 'interactivelistreply' => 'list_reply', 'listreply' => 'list_reply'];
    $type = $map[str_replace(['_', ' '], '', $ctype)] ?? null;
    $m = ['interakt_id' => $iid, 'created_at' => wa_utc_to_local($msg['received_at_utc'] ?? ($p['timestamp'] ?? null)) ?? wa_now()];
    $interactive = null;
    $body = is_string($text) && !is_array($decoded) ? $text : null;
    if ($type === 'button_reply' || $type === 'list_reply') {
        $reply = is_array($decoded) ? ($decoded['button_reply'] ?? ($decoded['list_reply'] ?? ($decoded['interactive']['button_reply'] ?? ($decoded['interactive']['list_reply'] ?? $decoded)))) : null;
        $title = is_array($reply) ? (string) ($reply['title'] ?? ($reply['text'] ?? '')) : (string) $text;
        $interactive = ['reply' => array_filter(['id' => is_array($reply) ? ($reply['id'] ?? ($reply['payload'] ?? null)) : null, 'title' => $title,
            'description' => is_array($reply) ? ($reply['description'] ?? null) : null], fn ($x) => $x !== null && $x !== '')];
        $body = $title;
        $m['button_payload'] = $interactive['reply']['id'] ?? $title;
    } elseif ($type === 'location') {
        $loc = is_array($decoded) ? ($decoded['location'] ?? $decoded) : [];
        $interactive = array_filter(['latitude' => isset($loc['latitude']) ? (float) $loc['latitude'] : null, 'longitude' => isset($loc['longitude']) ? (float) $loc['longitude'] : null,
            'name' => $loc['name'] ?? null, 'address' => $loc['address'] ?? null], fn ($x) => $x !== null);
        $body = $loc['name'] ?? ($loc['address'] ?? 'Location');
    } elseif ($type === 'contacts') {
        $interactive = is_array($decoded) ? ['contacts' => $decoded] : null;
        $first = is_array($decoded) ? ($decoded[0]['name']['formatted_name'] ?? ($decoded['name']['formatted_name'] ?? null)) : null;
        $body = $first ? 'Contact: ' . $first : 'Contact card';
    } elseif (in_array($type, ['image', 'video', 'document', 'audio', 'sticker'], true)) {
        $body = is_array($decoded) ? ($decoded['caption'] ?? null) : $body;
        $m['file_name'] = is_array($decoded) && !empty($decoded['filename']) ? mb_substr(basename((string) $decoded['filename']), 0, 190) : null;
    } elseif ($type === null) {
        // unknown content type: keep raw, show something readable
        $type = 'text';
        $body = is_string($text) && $text !== '' && !is_array($decoded) ? $text : '[' . ($msg['message_content_type'] ?? 'Unsupported') . ' message]';
    }
    $m['type'] = $type;
    $m['body'] = $body !== null ? mb_substr((string) $body, 0, 65000) : null;
    $m['interactive'] = $interactive;
    $mediaUrl = $msg['media_url'] ?? null;
    if (is_string($mediaUrl) && preg_match('#^https?://#i', $mediaUrl)) {
        $m['media_url'] = $mediaUrl;
        $dl = wa_media_download($mediaUrl, $m['file_name'] ?? null);
        if ($dl) {
            $m['media_local'] = $dl['path'];
            $m['media_mime'] = mb_substr($dl['mime'], 0, 100);
        }
    }
    $id = wa_record_inbound($contact, $m, $p, 'webhook');
    return $id ? ['processed', null] : ['ignored', 'Duplicate message'];
}

/** message_api_clicked (both payload shapes) → WA_BUTTON_CLICKED; quick replies also become inbound messages. */
function wa_webhook_click(array $p): array
{
    $data = isset($p['data']) && is_array($p['data']) ? $p['data'] : $p;
    $msg = is_array($data['message'] ?? null) ? $data['message'] : [];
    $ev = is_array($p['event'] ?? null) ? $p['event'] : (is_array($data['event'] ?? null) ? $data['event'] : []);
    $clickType = strtoupper((string) ($msg['click_type'] ?? ($ev['click_type'] ?? 'QR')));
    $buttonText = (string) ($msg['button_text'] ?? ($ev['button_text'] ?? ''));
    $buttonLink = (string) ($msg['button_link'] ?? ($ev['button_link'] ?? ''));
    $clickTs = (string) ($msg['click_timestamp'] ?? ($ev['click_timestamp'] ?? ''));
    $payload = $msg['button_payload']['payload']['text'] ?? ($msg['button_payload']['payload'] ?? ($msg['button_payload'] ?? null));
    $payload = is_scalar($payload) ? (string) $payload : $buttonText;
    $orig = wa_webhook_find_message($msg);
    $contact = $orig ? wa_contact((int) $orig['contact_id']) : wa_webhook_contact(is_array($data['customer'] ?? null) ? $data['customer'] : []);
    if (!$contact) {
        return ['ignored', 'Unknown contact for click'];
    }
    if (!$orig && $clickType !== 'CTA' && ($tid = wa_find_reply_target((int) $contact['id'], $payload, $buttonText))) {
        $orig = db_row('SELECT * FROM wa_messages WHERE id = ?', [$tid]); // click on a message Interakt did not identify
    }
    if ($orig && wa_status_rank($orig['status']) < 3 && $orig['status'] !== 'failed') {
        db_update('wa_messages', ['status' => 'read', 'read_at' => $orig['read_at'] ?: wa_now(), 'delivered_at' => $orig['delivered_at'] ?: wa_now()], 'id = ?', [(int) $orig['id']]);
        wa_update_campaign_recipient((int) $orig['id'], 'read');
    }
    $dedupe = 'click:' . ($msg['id'] ?? ($orig['id'] ?? $contact['id'])) . ':' . substr(sha1($clickTs . $buttonText), 0, 12);
    $inboundId = null;
    if ($clickType !== 'CTA' && $buttonText !== '') {
        $qid = 'qr-' . mb_substr((string) ($msg['id'] ?? ('c' . $contact['id'])), 0, 40) . '-' . substr(sha1($clickTs . $buttonText), 0, 12);
        if (!db_value('SELECT id FROM wa_messages WHERE interakt_id = ?', [$qid])) {
            $clickAt = wa_utc_to_local($clickTs) ?? wa_now();
            $inboundId = wa_record_inbound($contact, ['type' => 'button_reply', 'body' => $buttonText, 'interakt_id' => $qid, 'button_payload' => $payload,
                'interactive' => ['reply' => ['id' => $payload, 'title' => $buttonText]], 'reply_to_id' => $orig['id'] ?? null,
                'created_at' => $clickAt], $p, 'webhook');
            if (!$inboundId && ($twin = wa_find_button_reply_twin((int) $contact['id'], $buttonText, min($clickAt, wa_now()), true))) {
                $inboundId = (int) $twin['id']; // already stored from message_received
            }
        }
    }
    wa_event('WA_BUTTON_CLICKED', ['message_id' => $orig['id'] ?? null, 'inbound_message_id' => $inboundId, 'contact_id' => (int) $contact['id'], 'click_type' => $clickType,
        'button_text' => $buttonText, 'button_link' => $buttonLink, 'button_payload' => $payload, 'campaign_id' => $orig['campaign_id'] ?? null,
        'template_id' => $orig['template_id'] ?? null, 'callback_data' => $ev['callbackData'] ?? ($msg['meta_data']['source_data']['callback_data'] ?? null)],
        ['source' => 'webhook', 'contact_id' => (int) $contact['id'], 'dedupe_key' => $dedupe]);
    return ['processed', null];
}

/** message_template_status_update → wa_templates status (by Meta id, then name + language). */
function wa_webhook_template_status(array $p): array
{
    $d = $p['data'] ?? [];
    $event = strtoupper((string) ($d['event'] ?? ''));
    $map = ['APPROVED' => 'approved', 'REJECTED' => 'rejected', 'PENDING' => 'pending', 'IN_APPEAL' => 'pending', 'PAUSED' => 'paused', 'FLAGGED' => 'paused',
        'DISABLED' => 'disabled', 'PENDING_DELETION' => 'archived', 'DELETED' => 'archived', 'REINSTATED' => 'approved'];
    $status = $map[$event] ?? null;
    if (!$status) {
        return ['ignored', 'Unknown template event ' . $event];
    }
    $metaId = isset($d['message_template_id']) ? (string) $d['message_template_id'] : '';
    $tpl = $metaId !== '' ? db_row('SELECT * FROM wa_templates WHERE wa_template_id = ?', [$metaId]) : null;
    if (!$tpl && !empty($d['message_template_name'])) {
        $lang = (string) ($d['message_template_language'] ?? '');
        $tpl = db_row('SELECT * FROM wa_templates WHERE name = ? AND language = ?', [(string) $d['message_template_name'], $lang])
            ?: db_row('SELECT * FROM wa_templates WHERE name = ? AND (language = ? OR ? LIKE CONCAT(language, \'%\')) ORDER BY id DESC LIMIT 1', [(string) $d['message_template_name'], substr($lang, 0, 2), $lang])
            ?: db_row('SELECT * FROM wa_templates WHERE name = ? ORDER BY id DESC LIMIT 1', [(string) $d['message_template_name']]);
    }
    if (!$tpl) {
        return ['ignored', 'Template not found: ' . ($d['message_template_name'] ?? $metaId)];
    }
    $wasArchived = $tpl['status'] === 'archived';
    $set = ['synced_at' => wa_now()];
    if ($status === 'archived') {
        // deleted in Meta / Interakt: archive locally; a later Restore must never make it sendable again
        $set += ['status' => 'archived', 'archived_from' => 'disabled', 'archived_at' => $wasArchived && !empty($tpl['archived_at']) ? $tpl['archived_at'] : wa_now()];
    } elseif ($wasArchived) {
        // archived in GIMT: stays archived — only remember the remote status for a later Restore (as the templates sync does)
        $set['archived_from'] = $status;
    } else {
        $set['status'] = $status;
    }
    if ($metaId !== '' && empty($tpl['wa_template_id'])) {
        $set['wa_template_id'] = mb_substr($metaId, 0, 64);
    }
    if ($status === 'approved') {
        $set['approved_at'] = $tpl['approved_at'] ?: wa_now();
        $set['rejection_reason'] = null;
    }
    if ($status === 'rejected' || $status === 'paused' || $status === 'disabled') {
        $set['rejection_reason'] = isset($d['reason']) && $d['reason'] !== null ? mb_substr((string) $d['reason'], 0, 2000) : ($tpl['rejection_reason'] ?? null);
    }
    try {
        db_update('wa_templates', $set, 'id = ?', [(int) $tpl['id']]);
    } catch (Throwable $e) {
        if (!array_key_exists('archived_from', $set)) {
            throw $e;
        }
        // schema 54 (archived_from / archived_at) missing on an old install: keep the status rule, drop the extra columns
        unset($set['archived_from'], $set['archived_at']);
        if (count($set) > 1) {
            db_update('wa_templates', $set, 'id = ?', [(int) $tpl['id']]);
        }
    }
    // status = the template's effective local status (an archived template stays 'archived'); remote_status = Meta's
    $local = $set['status'] ?? $tpl['status'];
    wa_event('WA_TEMPLATE_STATUS', ['template_id' => (int) $tpl['id'], 'name' => $tpl['name'], 'status' => $local, 'remote_status' => $status, 'previous' => $tpl['status'],
        'reason' => $d['reason'] ?? null], ['source' => 'webhook']);
    return ['processed', $wasArchived && $status !== 'archived' ? 'Archived in GIMT — kept archived (remote status ' . $status . ' stored for Restore)' : null];
}

/**
 * Run a webhook payload through receive + process exactly like Interakt would (signed with the configured secret).
 * Used by the test tools (POST whatsapp/simulate/*) and CLI tests.
 * @return array{webhook_log_id:?int,status:string,result:array}
 */
function wa_simulate_webhook(array $payload): array
{
    $raw = (string) wa_json($payload);
    $secret = (string) wa_setting('wa_webhook_secret', '');
    $server = ['WA_SIMULATED' => 1, 'CONTENT_TYPE' => 'application/json', 'HTTP_USER_AGENT' => 'GIMT webhook simulator'];
    if ($secret !== '') {
        $server['HTTP_INTERAKT_SIGNATURE'] = 'sha256=' . hash_hmac('sha256', $raw, $secret);
    }
    $r = wa_webhook_receive($raw, $server);
    $status = $r['ok'] ? wa_process_webhook_log((int) $r['id']) : 'failed';
    $log = $r['id'] ? db_row('SELECT status, error FROM wa_webhook_logs WHERE id = ?', [(int) $r['id']]) : null;
    return ['webhook_log_id' => $r['id'], 'status' => $status, 'result' => ['event_type' => $r['event_type'], 'note' => $log['error'] ?? null]];
}

/** UTC timestamp in Interakt's format (no zone, microseconds). */
function wa_utc_stamp(?int $t = null): string
{
    return gmdate('Y-m-d\TH:i:s', $t ?? time()) . '.000000';
}

/** Build a message_received payload for the simulator. $type text|image|document|audio|video|location|button_reply. */
function wa_simulate_inbound_payload(string $phone, ?string $text, string $type = 'text', ?string $mediaUrl = null, ?string $name = null): array
{
    $norm = wa_normalize_phone($phone);
    $digits = $norm['phone'] ?? preg_replace('/\D+/', '', $phone);
    $ctype = ['text' => 'Text', 'image' => 'Image', 'document' => 'Document', 'audio' => 'Audio', 'video' => 'Video', 'location' => 'Location',
        'button_reply' => 'InteractiveButtonReply', 'list_reply' => 'InteractiveListReply', 'sticker' => 'Sticker'][$type] ?? 'Text';
    $message = $text;
    if ($type === 'location') {
        $message = wa_json(['latitude' => 28.4595, 'longitude' => 77.0266, 'name' => $text ?: 'Shared location', 'address' => 'Gurugram, Haryana']);
    } elseif ($type === 'button_reply' || $type === 'list_reply') {
        $message = wa_json([$type => ['id' => strtolower((string) preg_replace('/\W+/', '_', (string) $text)), 'title' => (string) $text]]);
    } elseif (in_array($type, ['image', 'video', 'document', 'audio', 'sticker'], true)) {
        $message = $text !== null && $text !== '' ? wa_json(['caption' => $text, 'filename' => $mediaUrl ? basename((string) parse_url($mediaUrl, PHP_URL_PATH)) : null]) : null;
    }
    return ['version' => '1.0', 'timestamp' => wa_utc_stamp(), 'type' => 'message_received', 'data' => [
        'customer' => ['id' => 'sim-' . substr(sha1((string) $digits), 0, 24), 'channel_phone_number' => (string) $digits, 'traits' => array_filter(['name' => $name])],
        'message' => ['id' => 'sim-' . wa_uuid(), 'chat_message_type' => 'CustomerMessage', 'message_status' => 'Sent', 'received_at_utc' => wa_utc_stamp(),
            'is_template_message' => false, 'raw_template' => null, 'message_content_type' => $ctype, 'media_url' => $mediaUrl, 'message' => $message, 'meta_data' => (object) []],
    ]];
}

/** Build a message_api_<status> payload for an existing outbound message (simulator). */
function wa_simulate_status_payload(array $m, string $status, ?string $error = null): array
{
    $contact = wa_contact((int) $m['contact_id']);
    $now = time();
    $cat = $m['campaign_id'] ? 'campaign' : 'api';
    return ['version' => '1.0', 'timestamp' => wa_utc_stamp($now), 'type' => 'message_' . $cat . '_' . $status, 'data' => [
        'customer' => ['id' => 'sim-' . substr(sha1((string) ($contact['phone'] ?? '')), 0, 24), 'channel_phone_number' => (string) ($contact['phone'] ?? ''), 'traits' => (object) []],
        'message' => [
            'id' => $m['interakt_id'] ?: 'sim-' . wa_uuid(),
            'chat_message_type' => 'PublicApiMessage',
            'message_status' => ucfirst($status),
            'received_at_utc' => wa_utc_stamp($m['sent_at'] ? strtotime((string) $m['sent_at']) : $now),
            'delivered_at_utc' => in_array($status, ['delivered', 'read'], true) ? wa_utc_stamp($now) : null,
            'seen_at_utc' => $status === 'read' ? wa_utc_stamp($now) : null,
            'campaign_id' => null,
            'is_template_message' => $m['type'] === 'template',
            'channel_error_code' => $status === 'failed' ? '1013' : null,
            'channel_failure_reason' => $status === 'failed' ? ($error ?: 'Recipient is not a valid WhatsApp user') : null,
            'message_content_type' => $m['type'] === 'template' ? 'Template' : ucfirst((string) $m['type']),
            'media_url' => null,
            'message' => $m['body'],
            'meta_data' => ['source' => 'PublicInterakt', 'source_data' => ['callback_data' => 'gimt:msg:' . $m['id']],
                'message_cost' => $status === 'delivered' && $m['type'] === 'template' ? ['whatsapp_cost' => '0.12', 'interakt_markup' => '0.0', 'actual_message_cost' => '0.12'] : null],
        ],
    ]];
}

/* =================================================================================================================
 * Worker / cron
 * ================================================================================================================= */

/**
 * Secret token of the cron URL. Derived from the install's app key; when the key is missing or still the public
 * default from config.php, a random per-install secret (setting _wa_cron_secret, group internal) is used instead so
 * the URL can never be guessed.
 */
function wa_cron_token(): string
{
    $key = (string) (app_config('app')['key'] ?? '');
    if (strlen($key) < 16 || $key === 'change-this-key-on-install') {
        $key = (string) setting('_wa_cron_secret', '');
        if (strlen($key) < 32) {
            db_exec("INSERT IGNORE INTO settings (`group`, `key`, `value`) VALUES ('internal', '_wa_cron_secret', ?)", [bin2hex(random_bytes(32))]);
            $key = (string) db_value("SELECT `value` FROM settings WHERE `key` = '_wa_cron_secret'");
        }
    }
    return substr(hash_hmac('sha256', 'gimt-whatsapp-cron', $key), 0, 40);
}

/** Cron URL + ready-to-paste commands + last run. The URL contains the token: show only to settings managers. */
function wa_cron_info(): array
{
    $url = absolute_url('api/whatsapp/cron?token=' . wa_cron_token());
    return [
        'url' => $url,
        'linux' => '* * * * * curl -fsS "' . $url . '" > /dev/null',
        'windows' => 'schtasks /Create /SC MINUTE /MO 1 /TN "GIMT WhatsApp worker" /TR "curl -fsS ' . $url . '"',
        'last_run' => setting('_wa_last_cron') ?: null,
        'last_stats' => wa_json_decode(setting('_wa_last_cron_stats'), null),
    ];
}

/**
 * Purge integration logs older than wa_retention_days (default 90): webhook logs, API logs, finished outbox rows — plus
 * expired WhatsApp rate-limit windows (wa_purge_rate_limits()).
 */
function wa_purge_logs(): array
{
    $days = max(7, (int) wa_setting('wa_retention_days', 90));
    $cut = date('Y-m-d H:i:s', time() - $days * 86400);
    return [
        'webhook_logs' => db_exec("DELETE FROM wa_webhook_logs WHERE received_at < ? AND status <> 'received' LIMIT 5000", [$cut]),
        'api_logs' => db_exec('DELETE FROM wa_api_logs WHERE created_at < ? LIMIT 5000', [$cut]),
        'outbox' => db_exec("DELETE FROM wa_outbox WHERE status IN ('done','cancelled','failed') AND updated_at < ? LIMIT 5000", [$cut]),
        'rate_limits' => wa_purge_rate_limits(),
    ];
}

/**
 * Delete expired WhatsApp rate-limit windows, so per-IP / per-contact keys cannot pile up in rate_limits (an attacker
 * rotating addresses adds one 'wa-webhook:<ip>' row per address). Only keys with the 'wa-' prefix are touched — every
 * key the WhatsApp module writes uses it ('wa-webhook:[signed:]<ip>', 'wa-webhook-rejected', 'wa-webhook-kept-bytes',
 * 'wa-webhook-log:<kind>', 'wa-webhook-health-recovered', 'wa-cron:<ip>', 'wa-sim:', 'wa-media:', 'wa-bot:<contact>',
 * 'wa-track-ip:', 'wa-ingest-ip:' …) and no other module does. rate_limit() and wa_window_counter() treat an expired row
 * exactly like a missing one, so this never changes a limit. The prefix is a primary-key range (no table scan); at most
 * $max rows per run in batches of 5000, with a minute of slack for a request that is renewing a window right now.
 */
function wa_purge_rate_limits(int $max = 20000): int
{
    $cut = date('Y-m-d H:i:s', time() - 60);
    $n = 0;
    while ($n < $max) {
        $batch = min(5000, $max - $n);
        $d = db_exec("DELETE FROM rate_limits WHERE rl_key LIKE 'wa-%' AND reset_at < ? LIMIT " . (int) $batch, [$cut]);
        $n += $d;
        if ($d < $batch) {
            break;
        }
    }
    return $n;
}

/** Push contacts whose opt-in changed (interakt_synced_at NULL) to Interakt — live mode, wa_auto_sync_contacts on. */
function wa_sync_pending_contacts(float $budget = 3.0, int $max = 30): array
{
    if (wa_mode() !== 'live' || wa_setting('wa_auto_sync_contacts', '1') !== '1') {
        return ['skipped' => 1];
    }
    $t0 = microtime(true);
    $n = 0;
    $failed = 0;
    foreach (db_all("SELECT * FROM wa_contacts WHERE interakt_synced_at IS NULL AND status = 'active' AND (opt_in = 1 OR opted_out_at IS NOT NULL) ORDER BY updated_at DESC LIMIT " . (int) $max) as $c) {
        if ((microtime(true) - $t0) > $budget) {
            break;
        }
        $r = wa_interakt_track_user($c);
        $r['ok'] ? $n++ : $failed++;
        if (($r['status'] ?? 0) === 429) {
            break;
        }
    }
    return ['synced' => $n, 'failed' => $failed];
}

/**
 * One worker run: webhooks → outbox → registered tick handlers in priority order (events, workflows, follow-ups,
 * campaigns, admin, template sync, CRM sync — each with a guaranteed slice) → outbox again → contact sync → hourly log
 * purge. GET_LOCK('gimt_wa_worker') so overlapping runs exit at once. Saves _wa_last_cron (light ticks:
 * _wa_last_light_tick only).
 */
function wa_worker_tick(float $budget = 20.0, array $opts = []): array
{
    if ((int) db_value("SELECT GET_LOCK('gimt_wa_worker', 0)") !== 1) {
        return ['ok' => false, 'skipped' => 'Another worker run is in progress.'];
    }
    $t0 = microtime(true);
    $left = fn () => $budget - (microtime(true) - $t0);
    $light = !empty($opts['light']); // opportunistic tick from polling: shorter grace, no purge / contact sync, no _wa_last_cron
    // background work has no human actor, even when a light tick runs inside a staff member's request: wa_user_id() is
    // null and wa_event() defaults to source 'worker' until the tick ends (restored in the finally block)
    wa_worker_depth(1);
    try {
        $stats = ['ok' => true, 'mode' => wa_mode()];
        @set_time_limit(max(90, (int) ceil($budget) + 60)); // handlers may overrun their budget
        if (PHP_SAPI !== 'cli') {
            ignore_user_abort(true);
        }
        try {
            $stats['webhooks'] = wa_process_webhooks(min(5.0, max(0.5, $left() * 0.3)));
        } catch (Throwable $e) {
            $stats['webhooks'] = ['error' => $e->getMessage()];
        }
        try {
            $stats['outbox'] = wa_process_outbox(min(15.0, max(0.5, $left() * 0.6)));
        } catch (Throwable $e) {
            $stats['outbox'] = ['error' => $e->getMessage()];
        }
        $stats['handlers'] = [];
        // Handlers run in priority order (events, workflows … crm_sync last). Each one may use the time left minus a
        // reserved minimum slice for every handler after it (the reserve is capped at half of the time left, so the
        // first handlers of a short light tick still get a useful budget), and always gets at least that slice itself
        // — so a handler that overruns delays the rest but cannot starve them. Only past the hard stop (budget +
        // grace) are the remaining handlers skipped until the next run.
        $handlers = wa_tick_handlers();
        $count = count($handlers);
        $minSlice = $count ? min(1.5, max(0.25, $budget / $count)) : 0.0;
        $hardStop = $budget + ($light ? min(2.0, $budget) : 5.0);
        $i = 0;
        foreach ($handlers as $name => $fn) {
            $after = $count - (++$i);
            $elapsed = microtime(true) - $t0;
            if ($elapsed + $minSlice > $hardStop) {
                $stats['handlers'][$name] = ['skipped' => 'no time left'];
                continue; // the whole run is past its hard stop: the remaining handlers run on the next tick
            }
            $remain = $left();
            $slice = max($minSlice, $remain - min($after * $minSlice, max(0.0, $remain) * 0.5));
            $h0 = microtime(true);
            try {
                $stats['handlers'][$name] = $fn($slice) ?? [];
            } catch (Throwable $e) {
                error_log('[GIMT] WhatsApp tick handler ' . $name . ' failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $stats['handlers'][$name] = ['error' => $e->getMessage()];
            }
            $took = microtime(true) - $h0;
            if ($took > $slice + 1.0) {
                error_log(sprintf('[GIMT] WhatsApp tick handler %s overran its budget (%.1f s of %.1f s)', $name, $took, $slice));
                $stats['overran'][$name] = round($took, 1);
            }
        }
        // messages queued by handlers (workflows, follow-ups, campaigns) go out in the same run when time allows
        if ($left() > 2.0) {
            try {
                $more = wa_process_outbox(min(10.0, $left() - 1.0));
                foreach ($more as $k => $v) {
                    if (is_int($v)) {
                        $stats['outbox'][$k] = ($stats['outbox'][$k] ?? 0) + $v;
                    }
                }
            } catch (Throwable $e) {
            }
        }
        if (!$light && $left() > 1.0) {
            try {
                $stats['contacts_sync'] = wa_sync_pending_contacts(min(3.0, $left() - 0.5));
            } catch (Throwable $e) {
                $stats['contacts_sync'] = ['error' => $e->getMessage()];
            }
        }
        try {
            $stats['webhook_health'] = wa_webhook_health_watch(); // live mode: alert settings managers when webhooks stop
        } catch (Throwable $e) {
            $stats['webhook_health'] = ['error' => $e->getMessage()];
        }
        $lastPurge = (int) strtotime((string) (setting('_wa_last_purge') ?: '2000-01-01'));
        if (!$light && time() - $lastPurge > 3600) {
            try {
                $stats['purged'] = wa_purge_logs();
                save_setting('_wa_last_purge', wa_now(), 'internal');
            } catch (Throwable $e) {
                $stats['purged'] = ['error' => $e->getMessage()];
            }
        }
        $stats['duration_ms'] = (int) round((microtime(true) - $t0) * 1000);
        if ($light) {
            // opportunistic ticks do not count as the cron: the worker-health warning must stay until the real cron runs
            save_setting('_wa_last_light_tick', wa_now(), 'internal');
        } else {
            save_setting('_wa_last_cron', wa_now(), 'internal');
            save_setting('_wa_last_cron_stats', wa_json($stats), 'internal');
        }
        return $stats;
    } finally {
        wa_worker_depth(-1);
        db_value("SELECT RELEASE_LOCK('gimt_wa_worker')");
    }
}

/**
 * Opportunistic short tick (≈3 s) when the cron has not run for 2 minutes — call from dashboard / inbox polling so local
 * development and a missing cron still work. At most one attempt per 30 s across requests. Never throws.
 */
function wa_maybe_tick(float $budget = 2.5, bool $defer = true): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $last = (int) strtotime((string) (setting('_wa_last_cron') ?: '2000-01-01'));
        if (time() - $last < 120) {
            return;
        }
        $lastTry = (int) strtotime((string) (setting('_wa_last_maybe_tick') ?: '2000-01-01'));
        if (time() - $lastTry < 30) {
            return;
        }
        save_setting('_wa_last_maybe_tick', wa_now(), 'internal');
        $budget = max(0.5, min(5.0, $budget));
        $run = function () use ($budget) {
            try {
                wa_worker_tick($budget, ['light' => true]);
            } catch (Throwable $e) {
                error_log('[GIMT] wa_maybe_tick: ' . $e->getMessage());
            }
        };
        if ($defer && PHP_SAPI !== 'cli') {
            // after the endpoint has answered: end the HTTP response first, then tick — polling never waits for it
            register_shutdown_function(function () use ($run) {
                wa_finish_request_early();
                $run();
            });
            return;
        }
        $run();
    } catch (Throwable $e) {
        error_log('[GIMT] wa_maybe_tick: ' . $e->getMessage());
    }
}

/**
 * End the HTTP response for the client while the script keeps running (shutdown work). FPM / LiteSpeed:
 * fastcgi_finish_request() / litespeed_finish_request(). Other SAPIs: when the response is still buffered, it is sent
 * with a Content-Length so the client stops waiting; otherwise the client waits for the script (dev servers only).
 */
function wa_finish_request_early(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // never hold the user's session lock during background work
    }
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
        return;
    }
    if (!headers_sent() && !filter_var(ini_get('zlib.output_compression'), FILTER_VALIDATE_BOOLEAN)) {
        $buf = '';
        while (ob_get_level() > 0) {
            $buf = (string) ob_get_clean() . $buf; // innermost buffer first
        }
        header('Content-Length: ' . strlen($buf));
        header('Connection: close');
        echo $buf;
    } else {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
    }
    @flush();
}

/**
 * Send a JSON API response to the client and end the request for the browser, so slow follow-up work (an
 * opportunistic worker tick) cannot delay it. Uses fastcgi/litespeed_finish_request when available, else a flushed
 * response with Content-Length. Returns normally; the caller does the extra work and exits.
 */
function wa_respond_then_continue(array $payload, int $status = 200): void
{
    $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (!filter_var(ini_get('zlib.output_compression'), FILTER_VALIDATE_BOOLEAN)) {
            header('Content-Length: ' . strlen($body)); // the client stops reading here, even before the script ends
        }
        header('Connection: close');
    }
    echo $body;
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } else {
        @flush();
    }
}

/**
 * Remember when the last non-simulated webhook signed with the saved secret arrived (setting
 * _wa_webhook_last_signed_at, written at most once a minute), so wa_webhook_health() never has to scan the whole log.
 */
function wa_webhook_mark_signed(): void
{
    try {
        $last = (int) strtotime((string) (setting('_wa_webhook_last_signed_at') ?: '2000-01-01'));
        if (time() - $last >= 60) {
            save_setting('_wa_webhook_last_signed_at', wa_now(), 'internal');
        }
    } catch (Throwable $e) {
        error_log('[GIMT] wa_webhook_mark_signed: ' . $e->getMessage());
    }
}

/**
 * Live-mode health of the Interakt webhook. Interakt turns a webhook off after 5 failed deliveries in 10 minutes and
 * never resends the events it missed, so silence has to be noticed. Only non-simulated webhooks count; "accepted" =
 * signed with the saved secret (signature_valid = 1; live mode never runs on a dev install, so unsigned ones are never
 * accepted there). Reads the latest 2000 log rows (primary key) plus _wa_webhook_last_signed_at — never a full scan.
 * Rejected rows of the last hour are split by their stored headers (wa_webhook_receive() keeps the Interakt-Signature
 * header): "bad signature" = carried a signature that did not verify against the saved secret (signature_valid = 0);
 * "unsigned" = carried no signature at all (scanners, or a secret never entered in Interakt). Unsigned requests never
 * turn the state red — they only add a line to the never / silent note.
 *   n/a            test mode (ticks and replies are simulated)
 *   bad_signature  in the last hour at least 3 signed webhooks failed the signature check and they outnumber the
 *                  accepted ones
 *   never          no accepted webhook has ever arrived
 *   silent         an outbound message was accepted by Interakt (sent_at) between 5 minutes and 24 hours ago and no
 *                  accepted webhook has arrived since. Interakt reports "sent" within seconds, so this covers "messages
 *                  went out in the last 60 minutes but no webhook arrived in that window" and stays set until a webhook
 *                  arrives again (or a day after the last send).
 *   ok             otherwise
 * @return array{state:string,note:string,last_valid_at:?string,invalid_1h:int,valid_1h:int,unsigned_1h:int,unverified_1h:int}
 */
function wa_webhook_health(): array
{
    $out = ['last_valid_at' => null, 'invalid_1h' => 0, 'valid_1h' => 0, 'unsigned_1h' => 0, 'unverified_1h' => 0];
    if (wa_mode() !== 'live') {
        return ['state' => 'n/a', 'note' => 'Webhook health is checked in live mode only — in test mode delivery ticks and replies are simulated.'] + $out;
    }
    $now = time();
    $hourAgo = date('Y-m-d H:i:s', $now - 3600);
    $lastValid = null;
    $valid = 0;      // signed with the saved secret
    $invalid = 0;    // carried a signature that did not verify (the secrets differ, or forged)
    $unsigned = 0;   // carried no signature (scanners, or no secret entered in Interakt)
    $unverified = 0; // signed while no secret was saved in GIMT (kept, can be reprocessed once the secret is saved)
    foreach (db_all('SELECT signature_valid, received_at, headers FROM wa_webhook_logs WHERE is_simulated = 0 ORDER BY id DESC LIMIT 2000') as $r) {
        $sv = $r['signature_valid'] === null ? null : (int) $r['signature_valid'];
        if ($sv === 1 && $lastValid === null) {
            $lastValid = (string) $r['received_at'];
        }
        if ((string) $r['received_at'] < $hourAgo) {
            continue;
        }
        if ($sv === 1) {
            $valid++;
            continue;
        }
        $h = wa_json_decode($r['headers'] ?? null, []);
        $signed = is_array($h) && trim((string) ($h['interakt-signature'] ?? '')) !== '';
        if (!$signed) {
            $unsigned++;
        } elseif ($sv === 0) {
            $invalid++;
        } else {
            $unverified++;
        }
    }
    $marked = (string) (setting('_wa_webhook_last_signed_at') ?: '');
    if ($marked !== '' && ($lastValid === null || $marked > $lastValid)) {
        $lastValid = $marked; // older than the scanned rows, or written by another request since this one loaded settings
    }
    $out = ['last_valid_at' => $lastValid, 'invalid_1h' => $invalid, 'valid_1h' => $valid, 'unsigned_1h' => $unsigned, 'unverified_1h' => $unverified];
    $noSecret = trim((string) wa_setting('wa_webhook_secret', '')) !== '' ? ''
        : 'No webhook secret is saved in GIMT, so every incoming webhook is ignored — generate one in WhatsApp › Integrations › Webhook setup, save it and'
            . ' enter the same secret in Interakt. ';
    $rejectedNote = ($invalid > 0 ? ' ' . $invalid . ' signed webhook' . ($invalid === 1 ? '' : 's') . ' in the last hour failed the signature check — the'
            . ' secret saved in GIMT must be exactly the one entered in Interakt.' : '')
        . ($unverified > 0 ? ' ' . $unverified . ' signed webhook' . ($unverified === 1 ? ' was' : 's were') . ' kept in the last hour — after saving the'
            . ' matching secret, Reprocess ' . ($unverified === 1 ? 'it' : 'them') . ' in WhatsApp › API & Webhooks › Webhook logs.' : '')
        . ($unsigned > 0 ? ' ' . $unsigned . ' unsigned request' . ($unsigned === 1 ? ' was' : 's were') . ' ignored in the last hour — if Interakt is'
            . ' sending ' . ($unsigned === 1 ? 'it' : 'them') . ', enter the same secret there (Interakt › Developer Settings › Webhooks).' : '');
    if ($invalid >= 3 && $invalid > $valid) {
        return ['state' => 'bad_signature', 'note' => $invalid . ' signed webhooks in the last hour carried an Interakt signature that did not match the'
            . ' saved secret' . ($valid ? ' (only ' . $valid . ' passed)' : '') . ' and were ignored, so delivery ticks, replies and STOP keywords are being lost. The secret saved in GIMT must be exactly the one entered in'
            . ' Interakt › Developer Settings › Webhooks: generate one in WhatsApp › Integrations, save it and paste the same value into Interakt.'] + $out;
    }
    if ($lastValid === null) {
        return ['state' => 'never', 'note' => $noSecret . 'No signed webhook from Interakt has reached GIMT yet. Enter the webhook URL and the same secret in'
            . ' Interakt › Developer Settings › Webhooks and tick the message events, then send a test message and reply to it.' . $rejectedNote] + $out;
    }
    $lastTs = (int) strtotime($lastValid);
    $pending = (int) db_value("SELECT COUNT(*) FROM wa_messages
                                WHERE status IN ('sent', 'delivered', 'read', 'failed') AND direction = 'out' AND created_at >= ?
                                  AND sent_at > ? AND sent_at <= ? AND (interakt_id IS NULL OR interakt_id NOT LIKE 'test-%')",
        [date('Y-m-d H:i:s', $now - 2 * 86400), date('Y-m-d H:i:s', max($lastTs, $now - 86400)), date('Y-m-d H:i:s', $now - 300)]);
    if ($pending > 0) {
        return ['state' => 'silent', 'note' => $noSecret . 'No signed webhook from Interakt has arrived since ' . date('d M, h:i A', $lastTs) . ', although '
            . $pending . ' message(s) went out after that — delivery ticks, replies and STOP keywords are not reaching GIMT. Interakt turns a webhook off'
            . ' after 5 failed deliveries in 10 minutes and never resends the events it missed: re-enable it in Interakt › Developer Settings › Webhooks'
            . ' and check that the URL and secret match GIMT.' . $rejectedNote] + $out;
    }
    $age = max(0, $now - $lastTs);
    $ago = $age < 60 ? $age . ' s ago' : ($age < 7200 ? round($age / 60) . ' min ago' : ($age < 172800 ? round($age / 3600) . ' hours ago' : 'on ' . date('d M Y', $lastTs)));
    return ['state' => 'ok', 'note' => 'Last signed webhook from Interakt ' . $ago . '.'] + $out;
}

/** Active users who may manage WhatsApp settings (Super Admins included) — recipients of integration alerts. */
function wa_settings_manager_ids(): array
{
    return array_map('intval', db_column(
        "SELECT DISTINCT u.id FROM users u
           JOIN user_roles ur ON ur.user_id = u.id
           JOIN roles r ON r.id = ur.role_id
           LEFT JOIN role_permissions rp ON rp.role_id = r.id
           LEFT JOIN permissions p ON p.id = rp.permission_id
          WHERE u.status = 'active' AND (r.is_super = 1 OR (p.module = 'whatsapp_settings' AND p.action = 'manage'))"
    ));
}

/**
 * Worker hook (every tick, live mode only): remember the webhook health state (setting _wa_webhook_health) and, when it
 * turns silent or bad_signature, log it and notify the WhatsApp settings managers — at most once per 6 hours per state
 * (_wa_webhook_health_notified). Returns the current state.
 */
function wa_webhook_health_watch(): string
{
    if (wa_mode() !== 'live') {
        return 'n/a';
    }
    $h = wa_webhook_health();
    $state = $h['state'];
    $prev = (string) (setting('_wa_webhook_health') ?: '');
    if ($prev === $state) {
        return $state;
    }
    save_setting('_wa_webhook_health', $state, 'internal');
    $bad = ['silent', 'bad_signature'];
    if (!in_array($state, $bad, true)) {
        // a state flapping around a threshold must not flood system_logs: at most one recovery line per 10 minutes
        if (in_array($prev, $bad, true) && wa_window_counter('wa-webhook-health-recovered', 1, 600) === 1) {
            log_system('info', 'WhatsApp webhook health recovered (' . $prev . ' → ' . $state . ').');
        }
        return $state;
    }
    $notified = wa_json_decode(setting('_wa_webhook_health_notified'), []);
    $notified = is_array($notified) ? $notified : [];
    if (time() - (int) ($notified[$state] ?? 0) < 6 * 3600) {
        return $state; // already logged and notified for this state in the last 6 hours
    }
    $notified[$state] = time();
    save_setting('_wa_webhook_health_notified', wa_json($notified), 'internal');
    log_system('warning', 'WhatsApp webhook health: ' . $state . ' — ' . $h['note']);
    $ids = wa_settings_manager_ids();
    if ($ids) {
        notify($ids, 'whatsapp', $state === 'silent' ? 'WhatsApp webhook has gone silent' : 'WhatsApp webhooks are failing the signature check',
            $h['note'], 'admin/whatsapp/integrations', 'alert-triangle');
    }
    return $state;
}

/** Status summary for GET whatsapp/status (mode, configuration, queue, worker health, webhook health). */
function wa_status_summary(): array
{
    $now = wa_now();
    $health = wa_webhook_health();
    $lastCron = setting('_wa_last_cron') ?: null;
    $age = $lastCron ? time() - (int) strtotime((string) $lastCron) : null;
    $lastHook = db_row('SELECT received_at, is_simulated FROM wa_webhook_logs ORDER BY id DESC LIMIT 1');
    $q = db_row("SELECT SUM(status = 'pending') AS pending, SUM(status = 'pending' AND scheduled_at <= ?) AS due, SUM(status = 'processing') AS processing FROM wa_outbox", [$now]) ?: [];
    $lastLight = setting('_wa_last_light_tick') ?: null;
    $lightAge = $lastLight ? time() - (int) strtotime((string) $lastLight) : null;
    $fallback = $lightAge !== null && $lightAge <= 600
        ? ' Until then messages are processed only while someone has a WhatsApp page open (last ' . ($lightAge < 60 ? $lightAge . ' s' : round($lightAge / 60) . ' min') . ' ago).'
        : '';
    $worker = $lastCron === null
        ? ['healthy' => false, 'status' => 'never', 'message' => 'The WhatsApp cron has never run. Add the cron job (every minute) so queued messages, follow-ups and campaigns go out on time.' . $fallback]
        : ($age <= 180
            ? ['healthy' => true, 'status' => 'ok', 'message' => 'Last run ' . ($age < 60 ? $age . ' s' : round($age / 60) . ' min') . ' ago.']
            : ['healthy' => false, 'status' => 'stale', 'message' => 'Last run ' . ($age < 7200 ? round($age / 60) . ' minutes' : round($age / 3600) . ' hours') . ' ago — check the cron job.' . $fallback]);
    $paused = function_exists('wa_adm_automation_paused') && wa_adm_automation_paused();
    $quietUntil = function_exists('wa_adm_quiet_until') ? wa_adm_quiet_until() : null;
    $out = [
        'mode' => wa_mode(),
        'mode_setting' => wa_setting('wa_mode', 'test') === 'live' ? 'live' : 'test',
        'configured' => wa_is_configured(),
        'is_dev' => is_dev(),
        'plan' => (string) wa_setting('wa_interakt_plan', 'growth'),
        'business_number' => wa_setting('wa_business_number') ?: null,
        'business_name' => wa_setting('wa_business_name') ?: (function_exists('institute_name') ? institute_name() : 'GIMT'),
        'rate_per_minute' => wa_rate_per_minute(),
        'in_business_hours' => wa_in_business_hours(),
        'last_cron_at' => $lastCron,
        'last_light_tick_at' => $lastLight,
        'automation_paused' => $paused,
        'quiet_until' => $quietUntil,
        'webhook_secret_set' => trim((string) wa_setting('wa_webhook_secret', '')) !== '',
        'last_webhook_at' => $lastHook['received_at'] ?? null,
        'last_webhook_simulated' => !empty($lastHook['is_simulated']),
        // live mode: ok | silent | bad_signature | never; test mode: n/a (wa_webhook_health())
        'webhook_health' => $health['state'],
        'webhook_health_note' => $health['note'],
        'last_valid_webhook_at' => $health['last_valid_at'],
        'queue' => [
            'pending' => (int) ($q['pending'] ?? 0),
            'due' => (int) ($q['due'] ?? 0),
            'processing' => (int) ($q['processing'] ?? 0),
            'failed_24h' => (int) db_value("SELECT COUNT(*) FROM wa_messages WHERE status = 'failed' AND direction = 'out' AND created_at >= ?", [date('Y-m-d H:i:s', time() - 86400)]),
            'sent_today' => (int) db_value("SELECT COUNT(*) FROM wa_messages WHERE direction = 'out' AND sent_at >= ?", [date('Y-m-d 00:00:00')]),
        ],
        'webhooks_pending' => (int) db_value("SELECT COUNT(*) FROM wa_webhook_logs WHERE status = 'received'"),
        'worker' => $worker,
    ];
    if (wa_can('whatsapp_settings', 'manage')) {
        $out['cron'] = wa_cron_info();
        $out['webhook_url'] = absolute_url('api/whatsapp/webhook');
        // rejected webhooks (forged / unsigned / wrong secret) this hour, and how many of them were over the storage cap
        $rejected = wa_window_count('wa-webhook-rejected');
        $out['webhooks_rejected_hour'] = $rejected;
        $out['webhooks_dropped_hour'] = max(0, $rejected - wa_webhook_reject_limits()['rows_per_hour']);
    }
    return $out;
}

/* =================================================================================================================
 * Feature services (each only defines functions and registers hooks at include time)
 * ================================================================================================================= */

$waFeatureServices = glob(__DIR__ . '/whatsapp_*.php') ?: [];
sort($waFeatureServices);
foreach ($waFeatureServices as $waFeatureService) {
    require_once $waFeatureService;
}
unset($waFeatureServices, $waFeatureService);
