<?php
/**
 * WhatsApp Template Designer + Template Library (Interakt template sync). Prefix wa_tpl_.
 *
 * The designer edits a template in "named" form (Dear {{student_name}}, …) with a sample per variable; this service
 * compiles it into the Meta-compliant positional form stored in wa_templates ({{1}}..{{n}} in header / body / URL
 * button) plus the `variables` JSON that maps every position back to its GIMT variable key and sample:
 *   {"header":[{"pos":1,"key":"course_name","sample":"BCA"}],"body":[{"pos":1,"key":"student_name","sample":"Rahul"}],
 *    "buttons":{"0":{"key":"receipt_link","sample":"RCPT-2026-00012"}}}
 * Core (wa_render_template / wa_send_template) resolves named values through that mapping when sending.
 *
 * Interakt has create + list APIs only: edits and deletes happen in the Interakt dashboard, so in GIMT only drafts can
 * be edited or deleted; submitted templates are archived locally and changed by creating a new version.
 * Loaded by app/services/whatsapp.php: this file only defines functions and registers hooks (no queries at include).
 */

/* ------------------------------------------------------------------------------------------------------------------
 * Reference data
 * ------------------------------------------------------------------------------------------------------------------ */

/** Template statuses (local). */
function wa_tpl_statuses(): array
{
    return [
        'draft' => 'Draft',
        'pending' => 'Pending approval',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'paused' => 'Paused',
        'disabled' => 'Disabled',
        'archived' => 'Archived',
    ];
}

/** Categories with the explanation shown in the designer. */
function wa_tpl_categories(): array
{
    return [
        'UTILITY' => [
            'label' => 'Utility',
            'description' => 'Transactional updates the student asked for or expects: enquiry acknowledgements, application and document status, fee receipts, due reminders, exam schedules, results.',
            'hint' => 'No promotional wording — Meta re-categorises promotional utility templates as Marketing.',
        ],
        'MARKETING' => [
            'label' => 'Marketing',
            'description' => 'Promotions and announcements: admissions open, scholarships, events, festival greetings, alumni meetups.',
            'hint' => 'Only sent to contacts who opted in to WhatsApp; billed at the marketing rate.',
        ],
        'AUTHENTICATION' => [
            'label' => 'Authentication',
            'description' => 'One-time passwords for portal login or verification. Meta fixes the wording; you only choose the button and expiry.',
            'hint' => 'Body is fixed: "{{1}} is your verification code." — no media, links or custom text.',
        ],
    ];
}

/** Template languages: send code => label + the full name the Interakt create API expects. */
function wa_tpl_languages(): array
{
    return [
        'en' => ['label' => 'English', 'interakt' => 'English'],
        'en_US' => ['label' => 'English (US)', 'interakt' => 'English (US)'],
        'en_GB' => ['label' => 'English (UK)', 'interakt' => 'English (UK)'],
        'hi' => ['label' => 'Hindi', 'interakt' => 'Hindi'],
        'mr' => ['label' => 'Marathi', 'interakt' => 'Marathi'],
        'gu' => ['label' => 'Gujarati', 'interakt' => 'Gujarati'],
        'bn' => ['label' => 'Bengali', 'interakt' => 'Bengali'],
        'pa' => ['label' => 'Punjabi', 'interakt' => 'Punjabi'],
        'ta' => ['label' => 'Tamil', 'interakt' => 'Tamil'],
        'te' => ['label' => 'Telugu', 'interakt' => 'Telugu'],
        'kn' => ['label' => 'Kannada', 'interakt' => 'Kannada'],
        'ml' => ['label' => 'Malayalam', 'interakt' => 'Malayalam'],
        'ur' => ['label' => 'Urdu', 'interakt' => 'Urdu'],
    ];
}

/** Meta / Interakt limits enforced by the designer (client + server). */
function wa_tpl_limits(): array
{
    return [
        'name' => 190,
        'display_name' => 190,
        'header_text' => 60,
        'body' => 1024,
        'footer' => 60,
        'button_text' => 25,
        'buttons_total' => 10,
        'quick_replies' => 10,
        'url_buttons' => 2,
        'phone_buttons' => 1,
        'copy_code_buttons' => 1,
        'url' => 2000,
        'copy_code' => 15,
        'payload' => 128,
        'sample' => 200,
        'otp_expiry_min' => 1,
        'otp_expiry_max' => 90,
        'media' => [
            'image' => ['label' => 'Image', 'max_mb' => 5, 'ext' => ['jpg', 'jpeg', 'png'], 'mime' => ['image/jpeg', 'image/png'], 'accept' => '.jpg,.jpeg,.png,image/jpeg,image/png'],
            'video' => ['label' => 'Video', 'max_mb' => 16, 'ext' => ['mp4'], 'mime' => ['video/mp4'], 'accept' => '.mp4,video/mp4'],
            'document' => ['label' => 'Document', 'max_mb' => 10, 'ext' => ['pdf'], 'mime' => ['application/pdf'], 'accept' => '.pdf,application/pdf'],
        ],
    ];
}

/** GIMT variable catalogue (core) normalised to a list: [{key, label, sample, group}]. */
function wa_tpl_catalog(): array
{
    if (!function_exists('wa_variable_catalog')) {
        return [];
    }
    $out = [];
    foreach ((array) wa_variable_catalog() as $k => $v) {
        if (is_array($v)) {
            $key = is_string($k) ? $k : (string) ($v['key'] ?? '');
            $label = (string) ($v['label'] ?? $key);
            $sample = (string) ($v['sample'] ?? '');
            $group = (string) ($v['group'] ?? '');
        } else {
            $key = (string) $k;
            $label = (string) $v;
            $sample = '';
            $group = '';
        }
        if ($key !== '' && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) {
            $out[] = ['key' => $key, 'label' => $label, 'sample' => $sample, 'group' => $group];
        }
    }
    return $out;
}

/**
 * Does this GIMT variable hold a whole link (receipt_link, payment_link, website_url, link …)? Meta fills a dynamic
 * URL button by appending the value to the fixed address (https://site/x/{{1}}), so such a variable gives a doubled,
 * broken link (https://site/x/https://…). Name rule + catalogue samples that are absolute URLs.
 */
function wa_tpl_link_valued_key(string $key): bool
{
    $key = strtolower(trim($key));
    if ($key === '') {
        return false;
    }
    if ($key === 'link' || $key === 'url' || preg_match('/(_link|_url)$/', $key) || str_starts_with($key, 'website_')) {
        return true;
    }
    static $urlKeys = null;
    if ($urlKeys === null) {
        $urlKeys = [];
        foreach (wa_tpl_catalog() as $c) {
            if (preg_match('#^https?://#i', (string) $c['sample'])) {
                $urlKeys[$c['key']] = true;
            }
        }
    }
    return isset($urlKeys[$key]);
}

/** Interakt / Meta category -> ours (legacy TRANSACTIONAL / OTP mapped); null when unknown. */
function wa_tpl_norm_category($c): ?string
{
    $c = strtoupper(trim(is_scalar($c) ? (string) $c : ''));
    $c = ['TRANSACTIONAL' => 'UTILITY', 'OTP' => 'AUTHENTICATION'][$c] ?? $c;
    return isset(wa_tpl_categories()[$c]) ? $c : null;
}

/** Template code name: lowercase letters, digits and underscores (Meta rule). */
function wa_tpl_slug(?string $s): string
{
    $s = mb_strtolower(trim((string) $s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? '';
    $s = trim(preg_replace('/_+/', '_', $s) ?? '', '_');
    return substr($s, 0, wa_tpl_limits()['name']);
}

/* ------------------------------------------------------------------------------------------------------------------
 * Rows
 * ------------------------------------------------------------------------------------------------------------------ */

function wa_tpl_json($v, $default = [])
{
    if (is_array($v)) {
        return $v;
    }
    if (!is_string($v) || $v === '') {
        return $default;
    }
    $d = json_decode($v, true);
    return is_array($d) ? $d : $default;
}

/** Decode JSON columns and normalise the variables mapping. */
function wa_tpl_decode(array $r): array
{
    $r['id'] = (int) $r['id'];
    $r['buttons'] = array_values(wa_tpl_json($r['buttons'] ?? null));
    $v = wa_tpl_json($r['variables'] ?? null);
    $r['variables'] = [
        'header' => array_values(array_filter((array) ($v['header'] ?? []), 'is_array')),
        'body' => array_values(array_filter((array) ($v['body'] ?? []), 'is_array')),
        'buttons' => (object) array_filter((array) ($v['buttons'] ?? []), 'is_array'),
    ];
    $r['otp_settings'] = wa_tpl_json($r['otp_settings'] ?? null, null);
    $r['usage_count'] = (int) ($r['usage_count'] ?? 0);
    $r['is_system'] = (int) ($r['is_system'] ?? 0);
    $r['parent_id'] = isset($r['parent_id']) ? (int) $r['parent_id'] ?: null : null;
    unset($r['header_handle']); // Interakt file handle: internal only
    return $r;
}

function wa_tpl_get(int $id): ?array
{
    $r = db_row('SELECT * FROM wa_templates WHERE id = ?', [$id]);
    return $r ? wa_tpl_decode($r) : null;
}

/** Raw row (keeps header_handle). */
function wa_tpl_row(int $id): ?array
{
    return db_row('SELECT * FROM wa_templates WHERE id = ?', [$id]);
}

function wa_tpl_label(array $t): string
{
    return trim((string) ($t['display_name'] ?? '')) !== '' ? (string) $t['display_name'] : (string) $t['name'];
}

/** Is a name free for this language? */
function wa_tpl_name_taken(string $name, string $language, ?int $exceptId = null): bool
{
    return (bool) db_value(
        'SELECT id FROM wa_templates WHERE name = ? AND language = ? AND id <> ? LIMIT 1',
        [$name, $language, (int) $exceptId]
    );
}

/** First free "<base>_vN" / "<base>_copy" style name. */
function wa_tpl_free_name(string $base, string $language, string $suffix = 'v'): string
{
    $base = preg_replace('/_(v\d+|copy\d*)$/', '', $base) ?: $base;
    $max = wa_tpl_limits()['name'] - 8;
    $base = substr($base, 0, $max);
    for ($i = 2; $i < 200; $i++) {
        $cand = $suffix === 'copy' ? $base . '_copy' . ($i > 2 ? $i - 1 : '') : $base . '_v' . $i;
        if (!wa_tpl_name_taken($cand, $language)) {
            return $cand;
        }
    }
    return $base . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
}

/* ------------------------------------------------------------------------------------------------------------------
 * Named <-> positional
 * ------------------------------------------------------------------------------------------------------------------ */

const WA_TPL_VAR_RE = '/\{\{\s*([^{}]*?)\s*\}\}/u';

/** Positional template (stored row) -> named form for the designer. */
function wa_tpl_named(array $t): array
{
    $v = $t['variables'] ?? ['header' => [], 'body' => [], 'buttons' => []];
    $samples = [];
    $toNamed = function (?string $text, array $list) use (&$samples): string {
        $byPos = [];
        foreach ($list as $item) {
            $byPos[(int) ($item['pos'] ?? 0)] = $item;
        }
        return (string) preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', function ($m) use ($byPos, &$samples) {
            $pos = (int) $m[1];
            $key = (string) ($byPos[$pos]['key'] ?? '');
            if ($key === '' || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) {
                $key = 'var_' . $pos;
            }
            $s = (string) ($byPos[$pos]['sample'] ?? '');
            if ($s !== '' && !isset($samples[$key])) {
                $samples[$key] = $s;
            } elseif (!isset($samples[$key])) {
                $samples[$key] = '';
            }
            return '{{' . $key . '}}';
        }, (string) $text);
    };
    $header = $toNamed($t['header_text'] ?? '', (array) ($v['header'] ?? []));
    $body = $toNamed($t['body'] ?? '', (array) ($v['body'] ?? []));
    $btnVars = (array) ($v['buttons'] ?? []);
    $buttons = [];
    foreach ((array) ($t['buttons'] ?? []) as $i => $b) {
        $b = (array) $b;
        if (($b['type'] ?? '') === 'URL' && !empty($b['url']) && preg_match('/\{\{\s*\d+\s*\}\}/', (string) $b['url'])) {
            $bv = (array) ($btnVars[(string) $i] ?? $btnVars[$i] ?? []);
            $key = (string) ($bv['key'] ?? '');
            if ($key === '' || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) {
                $key = 'button_' . ($i + 1) . '_var';
            }
            $b['url'] = (string) preg_replace('/\{\{\s*\d+\s*\}\}/', '{{' . $key . '}}', (string) $b['url']);
            if (!isset($samples[$key]) || $samples[$key] === '') {
                $samples[$key] = (string) ($bv['sample'] ?? '');
            }
        }
        $buttons[] = $b;
    }
    $btnType = 'none';
    if ($buttons) {
        $types = array_column($buttons, 'type');
        $btnType = in_array('OTP', $types, true) ? 'otp' : (in_array('QUICK_REPLY', $types, true) ? 'quick_reply' : 'cta');
    }
    return [
        'header_text' => $header,
        'body' => $body,
        'buttons' => $buttons,
        'button_type' => $btnType,
        'samples' => (object) $samples,
    ];
}

/** Variable keys used in a named text, in order of occurrence (with duplicates). */
function wa_tpl_keys(string $text): array
{
    preg_match_all(WA_TPL_VAR_RE, $text, $m);
    return $m[1] ?? [];
}

function wa_tpl_valid_key(string $key): bool
{
    return (bool) preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key);
}

/** Normalise a phone number for a call button: +<country><number>. */
function wa_tpl_phone(?string $raw): string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '';
    }
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($digits) === 10 && !str_starts_with($raw, '+')) {
        $digits = '91' . $digits;
    } elseif (strlen($digits) === 11 && $digits[0] === '0') {
        $digits = '91' . substr($digits, 1);
    }
    return '+' . $digits;
}

/** Contains an emoji / pictograph (Meta forbids them in header text). */
function wa_tpl_has_emoji(string $s): bool
{
    return (bool) preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{1F1E6}-\x{1F1FF}]/u', $s);
}

/**
 * Compile designer input (named form) into a wa_templates row + compliance issues.
 * Issues: [{field, level: error (blocks saving) | submit (blocks submission) | warning (advice), message}].
 *
 * Input: name, display_name, description, category, language, header_type, header_text, header_media_url,
 * header_media_name, body, footer, button_type (none|cta|quick_reply), buttons[], samples{key: value}, otp{...}.
 */
function wa_tpl_compile(array $in, ?int $id = null): array
{
    $L = wa_tpl_limits();
    $issues = [];
    $add = function (string $field, string $level, string $message) use (&$issues) {
        $issues[] = ['field' => $field, 'level' => $level, 'message' => $message];
    };
    $str = fn ($v) => is_scalar($v) ? trim((string) $v) : '';

    $samplesIn = [];
    foreach ((array) ($in['samples'] ?? []) as $k => $s) {
        if (is_string($k) && is_scalar($s)) {
            $samplesIn[$k] = trim((string) $s);
        }
    }
    $usedKeys = [];
    $sampleFor = function (string $key) use (&$samplesIn, &$usedKeys): string {
        $usedKeys[$key] = true;
        return mb_substr($samplesIn[$key] ?? '', 0, wa_tpl_limits()['sample']);
    };

    // ---- identity
    $rawName = $str($in['name'] ?? '');
    $name = wa_tpl_slug($rawName);
    if ($name === '') {
        $add('name', 'error', 'Enter a template name (lowercase letters, numbers and underscores).');
    } elseif ($rawName !== $name) {
        // auto-slugged; fine — the designer shows the slug
    }
    if ($name !== '' && !preg_match('/^[a-z]/', $name)) {
        $add('name', 'error', 'The template name must start with a letter.');
    }
    $category = strtoupper($str($in['category'] ?? 'UTILITY'));
    if (!isset(wa_tpl_categories()[$category])) {
        $add('category', 'error', 'Choose Marketing, Utility or Authentication.');
        $category = 'UTILITY';
    }
    $language = $str($in['language'] ?? 'en');
    if (!isset(wa_tpl_languages()[$language])) {
        $add('language', 'error', 'Choose a supported language.');
        $language = 'en';
    }
    if ($name !== '' && wa_tpl_name_taken($name, $language, $id)) {
        $add('name', 'error', "A template named \"$name\" already exists in " . wa_tpl_languages()[$language]['label'] . '. Pick another name or language.');
    }
    $displayName = mb_substr($str($in['display_name'] ?? ''), 0, $L['display_name']);
    $description = mb_substr($str($in['description'] ?? ''), 0, 255);

    $vars = ['header' => [], 'body' => [], 'buttons' => []];
    $headerType = 'none';
    $headerText = null;
    $headerMedia = null;
    $headerMediaName = null;
    $footer = null;
    $buttons = [];
    $otp = null;

    if ($category === 'AUTHENTICATION') {
        // ---- authentication (OTP): Meta fixes the wording
        $o = (array) ($in['otp'] ?? $in['otp_settings'] ?? []);
        $exp = (int) ($o['code_expiration_minutes'] ?? 10);
        if ($exp < $L['otp_expiry_min'] || $exp > $L['otp_expiry_max']) {
            $add('otp.code_expiration_minutes', 'error', 'Code expiry must be between 1 and 90 minutes.');
            $exp = max($L['otp_expiry_min'], min($L['otp_expiry_max'], $exp ?: 10));
        }
        $otpType = strtoupper($str($o['otp_type'] ?? 'COPY_CODE'));
        if (!in_array($otpType, ['COPY_CODE', 'ONE_TAP'], true)) {
            $otpType = 'COPY_CODE';
        }
        $btnText = mb_substr($str($o['button_text'] ?? '') ?: 'Copy code', 0, 60);
        if (mb_strlen($btnText) > $L['button_text']) {
            $add('otp.button_text', 'error', "Button text can be at most {$L['button_text']} characters.");
            $btnText = mb_substr($btnText, 0, $L['button_text']);
        }
        $otp = [
            'code_expiration_minutes' => $exp,
            'add_security_recommendation' => !empty($o['add_security_recommendation']),
            'otp_type' => $otpType,
            'button_text' => $btnText,
        ];
        $btn = ['type' => 'OTP', 'otp_type' => $otpType, 'text' => $btnText];
        if ($otpType === 'ONE_TAP') {
            $otp['autofill_text'] = mb_substr($str($o['autofill_text'] ?? '') ?: 'Autofill', 0, 25);
            $otp['package_name'] = mb_substr($str($o['package_name'] ?? ''), 0, 200);
            $otp['signature_hash'] = mb_substr($str($o['signature_hash'] ?? ''), 0, 20);
            if ($otp['package_name'] === '' || !preg_match('/^[a-zA-Z][\w]*(\.[a-zA-Z][\w]*)+$/', $otp['package_name'])) {
                $add('otp.package_name', 'submit', 'One-tap autofill needs the Android package name (e.g. in.ac.gimt.portal).');
            }
            if (!preg_match('/^[A-Za-z0-9+\/]{11}$/', $otp['signature_hash'])) {
                $add('otp.signature_hash', 'submit', 'One-tap autofill needs the 11-character app signature hash.');
            }
            $btn += ['autofill_text' => $otp['autofill_text'], 'package_name' => $otp['package_name'], 'signature_hash' => $otp['signature_hash']];
        }
        $buttons = [$btn];
        $body = '{{1}} is your verification code.';
        $otpSample = $sampleFor('otp_code') ?: '482913';
        if (!preg_match('/^[A-Za-z0-9]{4,15}$/', $otpSample)) {
            $add('samples.otp_code', 'error', 'Sample code: 4–15 letters or digits.');
        }
        $vars['body'] = [['pos' => 1, 'key' => 'otp_code', 'sample' => $otpSample]];
        $vars['buttons'] = ['0' => ['key' => 'otp_code', 'sample' => $otpSample]];
        if (!empty($in['header_type']) && $in['header_type'] !== 'none') {
            $add('header_type', 'warning', 'Authentication templates have no header; it was removed.');
        }
        $add('category', 'warning', 'Interakt does not document creating authentication templates through its API. If the submission is refused, create the OTP template in the Interakt dashboard and import it.');
    } else {
        // ---- header
        $headerType = strtolower($str($in['header_type'] ?? 'none'));
        if (!in_array($headerType, ['none', 'text', 'image', 'video', 'document'], true)) {
            $add('header_type', 'error', 'Unknown header type.');
            $headerType = 'none';
        }
        if ($headerType === 'video' || $headerType === 'document') {
            $add('header_type', 'warning', 'Interakt documents Text and Image headers for template creation; ' . $headerType . ' headers are submitted the same way but are undocumented — if Interakt refuses it, create the template in the Interakt dashboard and import it.');
        }
        if ($headerType === 'text') {
            $hNamed = str_replace(["\r", "\n"], ' ', $str($in['header_text'] ?? ''));
            $keys = wa_tpl_keys($hNamed);
            $pos = 0;
            $hPositional = (string) preg_replace_callback(WA_TPL_VAR_RE, function ($m) use (&$pos, &$vars, $sampleFor, $add) {
                $key = trim($m[1]);
                if (!wa_tpl_valid_key($key)) {
                    $add('header_text', 'error', 'Use variables from the variable menu, e.g. {{course_name}}.');
                    $key = 'var_header';
                }
                $pos++;
                $vars['header'][] = ['pos' => $pos, 'key' => $key, 'sample' => $sampleFor($key)];
                return '{{' . $pos . '}}';
            }, $hNamed);
            if ($hNamed === '') {
                $add('header_text', 'error', 'Enter the header text or choose "No header".');
            }
            if (count($keys) > 1) {
                $add('header_text', 'error', 'A text header can contain at most one variable.');
            }
            if (mb_strlen($hPositional) > $L['header_text']) {
                $add('header_text', 'error', "Header text can be at most {$L['header_text']} characters (now " . mb_strlen($hPositional) . ').');
            }
            if (wa_tpl_has_emoji($hNamed) || preg_match('/[*_~`]/', preg_replace(WA_TPL_VAR_RE, '', $hNamed) ?? '')) {
                $add('header_text', 'submit', 'Header text cannot contain emojis or formatting characters (* _ ~ `).');
            }
            $headerText = mb_substr($hPositional, 0, $L['header_text']);
        } elseif ($headerType !== 'none') {
            $headerMedia = $str($in['header_media_url'] ?? '') ?: null;
            $headerMediaName = mb_substr($str($in['header_media_name'] ?? ''), 0, 190) ?: null;
            $spec = $L['media'][$headerType];
            if (!$headerMedia) {
                $add('header_media_url', 'submit', "Upload a sample {$spec['label']} for the header — Meta reviews it with the template.");
            } else {
                if (!preg_match('#^(https?://|/|assets/)#i', $headerMedia)) {
                    $add('header_media_url', 'error', 'The header media must be an uploaded file or an https:// link.');
                }
                $ext = strtolower(pathinfo((string) parse_url($headerMedia, PHP_URL_PATH), PATHINFO_EXTENSION));
                if ($ext !== '' && !in_array($ext, $spec['ext'], true)) {
                    $add('header_media_url', 'submit', "{$spec['label']} headers accept " . strtoupper(implode('/', array_unique(array_map(fn ($e) => $e === 'jpeg' ? 'jpg' : $e, $spec['ext'])))) . " files only (this is .$ext).");
                }
                $local = wa_tpl_local_media_path($headerMedia);
                if ($local && filesize($local) > $spec['max_mb'] * 1048576) {
                    $add('header_media_url', 'submit', "{$spec['label']} headers can be at most {$spec['max_mb']} MB.");
                }
                if (!$headerMediaName) {
                    $headerMediaName = basename((string) parse_url($headerMedia, PHP_URL_PATH)) ?: null;
                }
            }
        }

        // ---- body
        $bNamed = str_replace("\r\n", "\n", is_scalar($in['body'] ?? null) ? (string) $in['body'] : '');
        $bNamed = trim($bNamed);
        $pos = 0;
        $badKey = false;
        $body = (string) preg_replace_callback(WA_TPL_VAR_RE, function ($m) use (&$pos, &$vars, $sampleFor, &$badKey) {
            $key = trim($m[1]);
            if (!wa_tpl_valid_key($key)) {
                $badKey = true;
                $key = 'var_' . ($pos + 1);
            }
            $pos++;
            $vars['body'][] = ['pos' => $pos, 'key' => $key, 'sample' => $sampleFor($key)];
            return '{{' . $pos . '}}';
        }, $bNamed);
        if ($badKey) {
            $add('body', 'error', 'Insert variables from the variable menu — names use lowercase letters, numbers and underscores, e.g. {{student_name}}.');
        }
        if ($bNamed === '') {
            $add('body', 'error', 'Write the message body.');
        }
        $len = mb_strlen($body);
        if ($len > $L['body']) {
            $add('body', 'error', "The body can be at most {$L['body']} characters (now $len).");
        }
        if ($body !== '') {
            if (preg_match('/^\{\{\d+\}\}/', $body)) {
                $add('body', 'submit', 'The body cannot start with a variable — add a word before it (e.g. "Dear {{student_name}}").');
            }
            if (preg_match('/\{\{\d+\}\}$/', $body)) {
                $add('body', 'submit', 'The body cannot end with a variable — add text or a full stop after it.');
            }
            if (preg_match('/\}\}\s*\{\{/', $body)) {
                $add('body', 'submit', 'Two variables cannot be next to each other — put some words between them.');
            }
            $words = str_word_count((string) preg_replace('/\{\{\d+\}\}/', ' ', $body));
            if ($pos > 0 && $words < 3 * $pos) {
                $add('body', 'warning', 'Many variables for a short message — Meta may reject it. Add more fixed text around the variables.');
            }
            if (preg_match('/\n{3,}/', $body)) {
                $add('body', 'warning', 'More than one blank line in a row may be collapsed by WhatsApp.');
            }
            if ($category === 'UTILITY' && preg_match('/\b(offer|discount|scholarships?|limited seats|hurry|admissions? (are )?open|apply now|enrol+ now|festive|% off|free gift|last chance|don\'t miss)\b/i', $bNamed)) {
                $add('category', 'warning', 'The body has promotional wording — Meta may re-categorise this utility template as Marketing.');
            }
        }

        // ---- footer
        $footer = $str($in['footer'] ?? '') ?: null;
        if ($footer !== null) {
            if (preg_match('/\{\{.*?\}\}/', $footer)) {
                $add('footer', 'error', 'The footer cannot contain variables.');
            }
            if (mb_strlen($footer) > $L['footer']) {
                $add('footer', 'error', "The footer can be at most {$L['footer']} characters.");
            }
            $footer = mb_substr(str_replace(["\r", "\n"], ' ', $footer), 0, $L['footer']);
        }

        // ---- buttons
        $btnType = strtolower($str($in['button_type'] ?? ''));
        $inButtons = array_values(array_filter((array) ($in['buttons'] ?? []), 'is_array'));
        if ($btnType === '') {
            $btnType = $inButtons ? (strtoupper((string) ($inButtons[0]['type'] ?? '')) === 'QUICK_REPLY' ? 'quick_reply' : 'cta') : 'none';
        }
        if ($btnType === 'none') {
            $inButtons = [];
        }
        $counts = ['QUICK_REPLY' => 0, 'URL' => 0, 'PHONE_NUMBER' => 0, 'COPY_CODE' => 0];
        $texts = [];
        $dynamicUrls = 0;
        foreach ($inButtons as $i => $b) {
            $type = strtoupper($str($b['type'] ?? ''));
            $f = "buttons.$i";
            if (!isset($counts[$type])) {
                $add("$f.type", 'error', 'Unknown button type.');
                continue;
            }
            if ($btnType === 'quick_reply' && $type !== 'QUICK_REPLY' || $btnType === 'cta' && $type === 'QUICK_REPLY') {
                $add("$f.type", 'error', 'Quick replies and call-to-action buttons cannot be mixed in one template.');
                continue;
            }
            $counts[$type]++;
            $text = $str($b['text'] ?? '');
            $out = ['type' => $type];
            if ($type === 'COPY_CODE') {
                $code = $str($b['example'] ?? $b['code'] ?? '');
                if ($code === '') {
                    $add("$f.example", 'error', 'Enter the sample offer code.');
                } elseif (mb_strlen($code) > $L['copy_code'] || !preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
                    $add("$f.example", 'error', "Offer code: letters and digits only, at most {$L['copy_code']} characters.");
                }
                $out['text'] = 'Copy offer code';
                $out['example'] = mb_substr($code, 0, $L['copy_code']);
                if ($category === 'MARKETING') {
                    $add("$f.type", 'warning', 'Copy-code buttons on marketing templates are not documented by Interakt — confirm on your account after approval.');
                } else {
                    $add("$f.type", 'error', 'Offer-code (copy code) buttons are only allowed in Marketing templates.');
                }
            } else {
                if ($text === '') {
                    $add("$f.text", 'error', 'Enter the button text.');
                } elseif (mb_strlen($text) > $L['button_text']) {
                    $add("$f.text", 'error', "Button text can be at most {$L['button_text']} characters.");
                } elseif (wa_tpl_has_emoji($text)) {
                    $add("$f.text", 'submit', 'Button text cannot contain emojis.');
                }
                $lower = mb_strtolower($text);
                if ($text !== '' && isset($texts[$lower])) {
                    $add("$f.text", 'error', 'Each button needs a different text.');
                }
                $texts[$lower] = true;
                $out['text'] = mb_substr($text, 0, $L['button_text']);
            }
            if ($type === 'QUICK_REPLY') {
                $payload = mb_substr($str($b['payload'] ?? ''), 0, $L['payload']);
                if ($payload !== '') {
                    $out['payload'] = $payload;
                }
            } elseif ($type === 'URL') {
                $url = $str($b['url'] ?? '');
                $keys = wa_tpl_keys($url);
                if ($url === '') {
                    $add("$f.url", 'error', 'Enter the website address.');
                } elseif (!preg_match('#^https?://[^\s/$.?\#][^\s{}]*(\{\{\s*[a-z][a-z0-9_]*\s*\}\})?$#i', $url)) {
                    $add("$f.url", 'error', $keys ? 'A link may contain one variable, only at the very end, that completes the address (https://gimtedu.com/verify-receipt/{{receipt_no}}).' : 'Enter a full link starting with https://');
                } elseif (mb_strlen($url) > $L['url']) {
                    $add("$f.url", 'error', "Links can be at most {$L['url']} characters.");
                } elseif (!str_starts_with(strtolower($url), 'https://')) {
                    $add("$f.url", 'warning', 'Use an https:// link — WhatsApp shows a warning for plain http links.');
                }
                if (count($keys) > 1) {
                    $add("$f.url", 'error', 'A link can contain only one variable.');
                }
                if ($keys) {
                    $key = trim($keys[0]);
                    if (!wa_tpl_valid_key($key)) {
                        $add("$f.url", 'error', 'Use a variable from the variable menu at the end of the link.');
                        $key = 'button_' . ($i + 1) . '_var';
                    }
                    $dynamicUrls++;
                    $sample = $sampleFor($key);
                    $base = (string) preg_replace(WA_TPL_VAR_RE, '', $url, 1);
                    if (wa_tpl_link_valued_key($key)) {
                        // Meta appends the value to the fixed address: a full link here opens https://site/x/https://…
                        $add("$f.url", 'submit', "{{{$key}}} holds a whole link, but WhatsApp adds the button variable to the end of $base — the button would open a broken address ({$base}https://…). Use a variable that completes the address, e.g. https://gimtedu.com/verify-receipt/{{receipt_no}}, or a static link.");
                    } elseif ($sample !== '' && preg_match('#^https?://#i', $sample)) {
                        $add("samples.$key", 'submit', "This sample is added to the end of $base — enter only the part that completes the address (e.g. RCPT-123), not a full link.");
                    }
                    $out['url'] = (string) preg_replace(WA_TPL_VAR_RE, '{{1}}', $url, 1);
                    $out['example'] = str_replace('{{1}}', $sample !== '' ? $sample : 'sample', $out['url']);
                    $vars['buttons'][(string) count($buttons)] = ['key' => $key, 'sample' => $sample];
                } else {
                    $out['url'] = $url;
                }
            } elseif ($type === 'PHONE_NUMBER') {
                $phone = wa_tpl_phone($str($b['phone_number'] ?? ''));
                if ($phone === '') {
                    $add("$f.phone_number", 'error', 'Enter the phone number to call.');
                } elseif (!preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
                    $add("$f.phone_number", 'error', 'Enter a valid phone number with country code, e.g. +91 98765 43210.');
                }
                $out['phone_number'] = $phone;
            }
            $buttons[] = $out;
        }
        $total = count($buttons);
        if ($total > $L['buttons_total']) {
            $add('buttons', 'error', "A template can have at most {$L['buttons_total']} buttons.");
        }
        if ($counts['QUICK_REPLY'] > $L['quick_replies']) {
            $add('buttons', 'error', "At most {$L['quick_replies']} quick replies.");
        }
        if ($counts['URL'] > $L['url_buttons']) {
            $add('buttons', 'error', 'At most 2 website buttons.');
        }
        if ($counts['PHONE_NUMBER'] > $L['phone_buttons']) {
            $add('buttons', 'error', 'At most 1 call button.');
        }
        if ($counts['COPY_CODE'] > $L['copy_code_buttons']) {
            $add('buttons', 'error', 'At most 1 copy-code button.');
        }
        if ($dynamicUrls > 1) {
            $add('buttons', 'submit', 'Interakt accepts one dynamic link per template — make the other link static.');
        }
        if ($btnType !== 'none' && !$buttons && !$inButtons) {
            $add('buttons', 'error', 'Add at least one button or choose "No buttons".');
        }
    }

    // ---- samples: every variable needs one
    foreach (array_keys($usedKeys) as $key) {
        if (($samplesIn[$key] ?? '') === '') {
            $add("samples.$key", 'submit', "Add a sample value for {{{$key}}} — Meta reviews templates with their samples.");
        } elseif (preg_match('/[\n\t]|\s{4,}/', $samplesIn[$key])) {
            $add("samples.$key", 'submit', 'Samples cannot contain line breaks, tabs or more than 3 spaces in a row.');
        }
    }

    $row = [
        'name' => $name,
        'display_name' => $displayName !== '' ? $displayName : null,
        'description' => $description !== '' ? $description : null,
        'category' => $category,
        'language' => $language,
        'header_type' => $headerType,
        'header_text' => $headerText,
        'header_media_url' => $headerMedia,
        'header_media_name' => $headerMediaName,
        'body' => $body,
        'footer' => $footer,
        'buttons' => $buttons ? json_encode($buttons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'variables' => json_encode([
            'header' => $vars['header'],
            'body' => $vars['body'],
            'buttons' => (object) $vars['buttons'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'otp_settings' => $otp ? json_encode($otp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ];
    return ['row' => $row, 'issues' => $issues];
}

/** Issues that block an action: level error (save) or error + submit (submission). */
function wa_tpl_blocking(array $issues, bool $forSubmit = false): array
{
    $out = [];
    foreach ($issues as $i) {
        if ($i['level'] === 'error' || ($forSubmit && $i['level'] === 'submit')) {
            $out[$i['field']] = $out[$i['field']] ?? $i['message'];
        }
    }
    return $out;
}

/** Re-run the compliance check on a stored template. */
function wa_tpl_issues(array $t): array
{
    $n = wa_tpl_named($t);
    $in = [
        'name' => $t['name'], 'display_name' => $t['display_name'], 'description' => $t['description'] ?? null,
        'category' => $t['category'], 'language' => $t['language'], 'header_type' => $t['header_type'],
        'header_text' => $n['header_text'], 'header_media_url' => $t['header_media_url'],
        'header_media_name' => $t['header_media_name'] ?? null, 'body' => $n['body'], 'footer' => $t['footer'],
        'button_type' => $n['button_type'] === 'otp' ? 'none' : $n['button_type'], 'buttons' => $n['buttons'],
        'samples' => (array) $n['samples'], 'otp' => $t['otp_settings'] ?? [],
    ];
    return wa_tpl_compile($in, (int) $t['id'])['issues'];
}

/** Absolute path of an uploaded file referenced by URL (only inside assets/uploads), else null. */
function wa_tpl_local_media_path(?string $url): ?string
{
    if (!$url) {
        return null;
    }
    $path = (string) (preg_match('#^https?://#i', $url) ? parse_url($url, PHP_URL_PATH) : parse_url($url, PHP_URL_PATH));
    $path = rawurldecode($path);
    $base = base_path();
    if ($base !== '' && str_starts_with($path, $base . '/')) {
        $path = substr($path, strlen($base));
    }
    $path = ltrim($path, '/');
    if (!str_starts_with($path, 'assets/uploads/')) {
        return null;
    }
    $root = realpath(APP_ROOT . '/assets/uploads');
    $abs = realpath(APP_ROOT . '/' . $path);
    if (!$root || !$abs || !str_starts_with($abs, $root) || !is_file($abs)) {
        return null;
    }
    return $abs;
}

/* ------------------------------------------------------------------------------------------------------------------
 * Save / duplicate / archive / delete
 * ------------------------------------------------------------------------------------------------------------------ */

/** Only drafts can change their content (Interakt has no edit API). */
function wa_tpl_is_editable(array $t): bool
{
    return ($t['status'] ?? '') === 'draft';
}

/**
 * Create or update a draft from designer input. Returns ['id', 'issues', 'template'].
 * Throws CrudValidationException (field => message) on blocking errors.
 */
function wa_tpl_save(array $in, ?int $id = null): array
{
    $old = $id ? wa_tpl_get($id) : null;
    if ($id && !$old) {
        throw new InvalidArgumentException('Template not found.');
    }
    if ($old && !wa_tpl_is_editable($old)) {
        return wa_tpl_save_mapping($old, $in);
    }
    $c = wa_tpl_compile($in, $id);
    $errors = wa_tpl_blocking($c['issues']);
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $row = $c['row'];
    $uid = function_exists('user_id') ? user_id() : null;
    if ($old) {
        $row['updated_by'] = $uid;
        db_update('wa_templates', $row, 'id = ?', [$id]);
    } else {
        $row += ['status' => 'draft', 'created_by' => $uid, 'updated_by' => $uid];
        $parent = is_scalar($in['parent_id'] ?? null) ? (int) $in['parent_id'] : 0;
        if ($parent > 0 && db_value('SELECT id FROM wa_templates WHERE id = ?', [$parent])) {
            $row['parent_id'] = $parent;
        }
        $id = db_insert('wa_templates', $row);
    }
    return ['id' => $id, 'issues' => $c['issues'], 'template' => wa_tpl_get($id)];
}

/**
 * Submitted / approved / imported templates: only the local metadata can change — display name, description,
 * default header media for sends, and the variable mapping (GIMT key + sample per position).
 */
function wa_tpl_save_mapping(array $t, array $in): array
{
    $upd = [];
    $str = fn ($v) => is_scalar($v) ? trim((string) $v) : '';
    if (array_key_exists('display_name', $in)) {
        $upd['display_name'] = mb_substr($str($in['display_name']), 0, 190) ?: null;
    }
    if (array_key_exists('description', $in)) {
        $upd['description'] = mb_substr($str($in['description']), 0, 255) ?: null;
    }
    if (array_key_exists('header_media_url', $in) && in_array($t['header_type'], ['image', 'video', 'document'], true)) {
        $url = $str($in['header_media_url']);
        if ($url !== '' && !preg_match('#^(https?://|/|assets/)#i', $url)) {
            throw new CrudValidationException(['header_media_url' => 'The header media must be an uploaded file or an https:// link.']);
        }
        $upd['header_media_url'] = $url !== '' ? mb_substr($url, 0, 500) : null;
        if (array_key_exists('header_media_name', $in)) {
            $upd['header_media_name'] = mb_substr(basename(str_replace('\\', '/', $str($in['header_media_name']))), 0, 190) ?: null;
        }
    }
    $vin = $in['variables'] ?? null;
    if (is_array($vin)) {
        $errors = [];
        $v = $t['variables'];
        foreach (['header', 'body'] as $part) {
            $byPos = [];
            foreach ((array) ($vin[$part] ?? []) as $item) {
                if (is_array($item) && isset($item['pos'])) {
                    $byPos[(int) $item['pos']] = $item;
                }
            }
            foreach ($v[$part] as $k => $item) {
                $p = (int) $item['pos'];
                if (!isset($byPos[$p])) {
                    continue;
                }
                $key = $t['category'] === 'AUTHENTICATION' ? 'otp_code' : $str($byPos[$p]['key'] ?? '');
                if (!wa_tpl_valid_key($key)) {
                    $errors["variables.$part.$p"] = 'Choose a variable (lowercase letters, numbers, underscores).';
                    continue;
                }
                $v[$part][$k]['key'] = $key;
                $v[$part][$k]['sample'] = mb_substr($str($byPos[$p]['sample'] ?? ''), 0, 200);
            }
        }
        $btn = (array) $v['buttons'];
        foreach ((array) ($vin['buttons'] ?? []) as $idx => $item) {
            if (!is_array($item) || !isset($btn[(string) $idx])) {
                continue;
            }
            $key = $t['category'] === 'AUTHENTICATION' ? 'otp_code' : $str($item['key'] ?? '');
            if (!wa_tpl_valid_key($key)) {
                $errors["variables.buttons.$idx"] = 'Choose a variable for the link.';
                continue;
            }
            $tb = $t['buttons'][(int) $idx] ?? [];
            if (($tb['type'] ?? '') === 'URL' && wa_tpl_link_valued_key($key)) {
                $base = (string) strstr((string) ($tb['url'] ?? ''), '{{', true);
                $errors["variables.buttons.$idx"] = "{{{$key}}} holds a whole link, but WhatsApp adds this value to the end of " . ($base !== '' ? $base : 'the button address')
                    . ' — choose a variable that completes the address (e.g. receipt_no).';
                continue;
            }
            $btn[(string) $idx] = ['key' => $key, 'sample' => mb_substr($str($item['sample'] ?? ''), 0, 200)];
        }
        if ($errors) {
            throw new CrudValidationException($errors);
        }
        $v['buttons'] = (object) $btn;
        $upd['variables'] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if ($upd) {
        $upd['updated_by'] = function_exists('user_id') ? user_id() : null;
        db_update('wa_templates', $upd, 'id = ?', [$t['id']]);
    }
    $fresh = wa_tpl_get((int) $t['id']);
    return ['id' => (int) $t['id'], 'issues' => wa_tpl_issues($fresh), 'template' => $fresh, 'mapping_only' => true];
}

/** Copy a template as a new draft ($asVersion: name_v2, linked as a new version). */
function wa_tpl_duplicate(int $id, bool $asVersion = false): int
{
    $t = wa_tpl_row($id);
    if (!$t) {
        throw new InvalidArgumentException('Template not found.');
    }
    $name = wa_tpl_free_name($t['name'], $t['language'], $asVersion ? 'v' : 'copy');
    $row = [
        'name' => $name,
        'display_name' => $t['display_name'] ? mb_substr($t['display_name'] . ($asVersion ? ' (new version)' : ' (copy)'), 0, 190) : null,
        'description' => $t['description'],
        'category' => $t['category'],
        'language' => $t['language'],
        'header_type' => $t['header_type'],
        'header_text' => $t['header_text'],
        'header_media_url' => $t['header_media_url'],
        'header_media_name' => $t['header_media_name'],
        'body' => $t['body'],
        'footer' => $t['footer'],
        'buttons' => $t['buttons'],
        'variables' => $t['variables'],
        'otp_settings' => $t['otp_settings'],
        'status' => 'draft',
        'parent_id' => $id,
        'created_by' => function_exists('user_id') ? user_id() : null,
        'updated_by' => function_exists('user_id') ? user_id() : null,
    ];
    return db_insert('wa_templates', $row);
}

function wa_tpl_archive(int $id): array
{
    $t = wa_tpl_row($id);
    if (!$t) {
        throw new InvalidArgumentException('Template not found.');
    }
    if ($t['status'] === 'archived') {
        return $t;
    }
    db_update('wa_templates', ['status' => 'archived', 'archived_from' => $t['status'], 'archived_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
    return $t;
}

function wa_tpl_restore(int $id): string
{
    $t = wa_tpl_row($id);
    if (!$t) {
        throw new InvalidArgumentException('Template not found.');
    }
    $to = $t['archived_from'] ?: ($t['approved_at'] ? 'approved' : ($t['rejection_reason'] ? 'rejected' : ($t['submitted_at'] ? 'pending' : 'draft')));
    if (!isset(wa_tpl_statuses()[$to]) || $to === 'archived') {
        $to = 'draft';
    }
    db_update('wa_templates', ['status' => $to, 'archived_from' => null, 'archived_at' => null], 'id = ?', [$id]);
    return $to;
}

/** Where a template is used: events, workflows, follow-up stages, campaigns. */
function wa_tpl_usage(int $id, string $name): array
{
    $out = ['events' => [], 'workflows' => [], 'followups' => [], 'campaigns' => []];
    try {
        $out['events'] = db_all('SELECT id, code, name, is_active FROM wa_events WHERE template_id = ? ORDER BY name', [$id]);
        $out['followups'] = db_all('SELECT id, stage_key, name, is_active FROM wa_followup_stages WHERE template_id = ? ORDER BY sort_order', [$id]);
        $out['campaigns'] = db_all('SELECT id, name, status, scheduled_at FROM wa_campaigns WHERE template_id = ? ORDER BY id DESC LIMIT 50', [$id]);
        $map = wa_tpl_workflow_usage_map();
        foreach ($map as $wf) {
            if (in_array($id, $wf['ids'], true) || in_array($name, $wf['names'], true)) {
                $out['workflows'][] = ['id' => $wf['id'], 'name' => $wf['name'], 'status' => $wf['status']];
            }
        }
    } catch (Throwable $e) {
        error_log('[GIMT] wa_tpl_usage: ' . $e->getMessage());
    }
    return $out;
}

/** Template ids / names referenced by every workflow definition (scans node data recursively). Cached per request. */
function wa_tpl_workflow_usage_map(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $rows = db_all("SELECT id, name, status, definition FROM wa_workflows WHERE definition LIKE '%template%'");
    foreach ($rows as $w) {
        $ids = [];
        $names = [];
        $walk = function ($node) use (&$walk, &$ids, &$names) {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $k => $v) {
                if (in_array($k, ['template_id', 'templateId'], true) && is_numeric($v)) {
                    $ids[] = (int) $v;
                } elseif (in_array($k, ['template', 'template_name', 'templateName'], true) && is_scalar($v)) {
                    if (is_numeric($v)) {
                        $ids[] = (int) $v;
                    } else {
                        $names[] = (string) $v;
                    }
                } elseif (is_array($v)) {
                    $walk($v);
                }
            }
        };
        $walk(json_decode((string) $w['definition'], true));
        if ($ids || $names) {
            $cache[] = ['id' => (int) $w['id'], 'name' => $w['name'], 'status' => $w['status'], 'ids' => array_values(array_unique($ids)), 'names' => array_values(array_unique($names))];
        }
    }
    return $cache;
}

/** Usage counts for many templates: id => {events, workflows, followups, campaigns, total}. */
function wa_tpl_usage_counts(array $templates): array
{
    $out = [];
    foreach ($templates as $t) {
        $out[(int) $t['id']] = ['events' => 0, 'workflows' => 0, 'followups' => 0, 'campaigns' => 0, 'total' => 0];
    }
    if (!$out) {
        return $out;
    }
    try {
        foreach ([
            'events' => 'SELECT template_id, COUNT(*) FROM wa_events WHERE template_id IS NOT NULL GROUP BY template_id',
            'followups' => 'SELECT template_id, COUNT(*) FROM wa_followup_stages WHERE template_id IS NOT NULL GROUP BY template_id',
            'campaigns' => 'SELECT template_id, COUNT(*) FROM wa_campaigns WHERE template_id IS NOT NULL GROUP BY template_id',
        ] as $k => $sql) {
            foreach (db_pairs($sql) as $tid => $n) {
                if (isset($out[(int) $tid])) {
                    $out[(int) $tid][$k] = (int) $n;
                }
            }
        }
        $byName = [];
        foreach ($templates as $t) {
            $byName[$t['name']][] = (int) $t['id'];
        }
        foreach (wa_tpl_workflow_usage_map() as $wf) {
            $hit = $wf['ids'];
            foreach ($wf['names'] as $n) {
                $hit = array_merge($hit, $byName[$n] ?? []);
            }
            foreach (array_unique($hit) as $tid) {
                if (isset($out[$tid])) {
                    $out[$tid]['workflows']++;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[GIMT] wa_tpl_usage_counts: ' . $e->getMessage());
    }
    foreach ($out as &$o) {
        $o['total'] = $o['events'] + $o['workflows'] + $o['followups'] + $o['campaigns'];
    }
    return $out;
}

/** Delete a draft (refused when anything still references it). */
function wa_tpl_delete(int $id): array
{
    $t = wa_tpl_row($id);
    if (!$t) {
        throw new InvalidArgumentException('Template not found.');
    }
    if (!in_array($t['status'], ['draft', 'archived'], true) || ($t['status'] === 'archived' && ($t['interakt_id'] || $t['submitted_at']))) {
        throw new CrudException('Only drafts can be deleted. Templates submitted to Interakt are deleted in the Interakt dashboard — archive it here instead.');
    }
    $u = wa_tpl_usage($id, $t['name']);
    $refs = count($u['events']) + count($u['workflows']) + count($u['followups']) + count($u['campaigns']);
    if ($refs > 0) {
        throw new CrudException("This draft is used by $refs event(s), workflow(s), follow-up stage(s) or campaign(s). Remove it there first.");
    }
    db_delete('wa_templates', 'id = ?', [$id]);
    return $t;
}

/* ------------------------------------------------------------------------------------------------------------------
 * Analytics
 * ------------------------------------------------------------------------------------------------------------------ */

/**
 * Message statistics per template id: sent, delivered, read, failed, queued, cost, last_used_at, replies (template
 * messages answered within 24 h), clicks (every button click) and clicked (messages with at least one click).
 */
function wa_tpl_stats(array $ids, ?string $since = null): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $out = [];
    foreach ($ids as $id) {
        $out[$id] = ['total' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'queued' => 0, 'clicks' => 0, 'clicked' => 0, 'replies' => 0, 'cost' => 0.0, 'last_used_at' => null];
    }
    if (!$ids) {
        return $out;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $args = $ids;
    $sinceSql = '';
    if ($since) {
        $sinceSql = ' AND created_at >= ?';
        $args[] = $since;
    }
    try {
        $rows = db_all(
            "SELECT template_id, COUNT(*) AS total,
                    SUM(status IN ('sent','delivered','read','simulated')) AS sent,
                    SUM(status IN ('delivered','read')) AS delivered,
                    SUM(status = 'read') AS read_n,
                    SUM(status = 'failed') AS failed,
                    SUM(status = 'queued') AS queued,
                    COALESCE(SUM(cost), 0) AS cost,
                    MAX(created_at) AS last_used_at
             FROM wa_messages WHERE direction = 'out' AND template_id IN ($in)$sinceSql GROUP BY template_id",
            $args
        );
        foreach ($rows as $r) {
            $tid = (int) $r['template_id'];
            $out[$tid] = array_merge($out[$tid], [
                'total' => (int) $r['total'], 'sent' => (int) $r['sent'], 'delivered' => (int) $r['delivered'],
                'read' => (int) $r['read_n'], 'failed' => (int) $r['failed'], 'queued' => (int) $r['queued'],
                'cost' => round((float) $r['cost'], 2), 'last_used_at' => $r['last_used_at'],
            ]);
        }
        // Replies: template messages answered by the contact (any inbound message, button taps included) within 24 h
        $sinceO = $since ? ' AND o.created_at >= ?' : '';
        $rows = db_all(
            "SELECT o.template_id, COUNT(DISTINCT o.id) AS replies
             FROM wa_messages o
             JOIN wa_messages i ON i.contact_id = o.contact_id AND i.direction = 'in'
                  AND i.created_at >= o.created_at AND i.created_at < DATE_ADD(o.created_at, INTERVAL 1 DAY)
             WHERE o.direction = 'out' AND o.template_id IN ($in)$sinceO GROUP BY o.template_id",
            $args
        );
        foreach ($rows as $r) {
            $out[(int) $r['template_id']]['replies'] = (int) $r['replies'];
        }
        // Button clicks: core raises WA_BUTTON_CLICKED for every Interakt message_api_clicked webhook (quick reply and
        // CTA website / call buttons) and logs it with the template id of the clicked message.
        $clickArgs = $since ? [$since] : [];
        $clickedMsgs = [];
        foreach (db_column("SELECT payload FROM wa_event_logs WHERE event_code = 'WA_BUTTON_CLICKED'" . ($since ? ' AND created_at >= ?' : ''), $clickArgs) as $p) {
            $d = json_decode((string) $p, true);
            $tid = (int) ($d['template_id'] ?? 0);
            if ($tid > 0 && isset($out[$tid])) {
                $out[$tid]['clicks']++;
                $clickedMsgs[$tid][(string) ($d['message_id'] ?? ('c' . ($d['contact_id'] ?? '')))] = true;
            }
        }
        foreach ($clickedMsgs as $tid => $set) {
            $out[$tid]['clicked'] = count($set);
        }
    } catch (Throwable $e) {
        error_log('[GIMT] wa_tpl_stats: ' . $e->getMessage());
    }
    return $out;
}

/** Daily sends of one template (last $days days) for the detail chart. */
function wa_tpl_trend(int $id, int $days = 30): array
{
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $rows = db_all(
        "SELECT DATE(created_at) AS d, COUNT(*) AS sent, SUM(status IN ('delivered','read')) AS delivered, SUM(status = 'read') AS read_n, SUM(status = 'failed') AS failed
         FROM wa_messages WHERE direction = 'out' AND template_id = ? AND created_at >= ? GROUP BY DATE(created_at)",
        [$id, $from . ' 00:00:00']
    );
    $by = [];
    foreach ($rows as $r) {
        $by[$r['d']] = $r;
    }
    $out = ['labels' => [], 'sent' => [], 'delivered' => [], 'read' => [], 'failed' => []];
    for ($i = 0; $i < $days; $i++) {
        $d = date('Y-m-d', strtotime($from . " +$i days"));
        $out['labels'][] = $d;
        $out['sent'][] = (int) ($by[$d]['sent'] ?? 0);
        $out['delivered'][] = (int) ($by[$d]['delivered'] ?? 0);
        $out['read'][] = (int) ($by[$d]['read_n'] ?? 0);
        $out['failed'][] = (int) ($by[$d]['failed'] ?? 0);
    }
    return $out;
}

/** Library list: filters status|category|language|q|sort, PHP-side paging (template counts are small). */
function wa_tpl_list(array $f, int $page = 1, int $perPage = 24): array
{
    $where = ['1=1'];
    $args = [];
    $status = (string) ($f['status'] ?? '');
    if ($status !== '' && isset(wa_tpl_statuses()[$status])) {
        $where[] = 'status = ?';
        $args[] = $status;
    } elseif ($status !== 'all_with_archived') {
        $where[] = "status <> 'archived'";
    }
    $cat = strtoupper((string) ($f['category'] ?? ''));
    if ($cat !== '' && isset(wa_tpl_categories()[$cat])) {
        $where[] = 'category = ?';
        $args[] = $cat;
    }
    $lang = (string) ($f['language'] ?? '');
    if ($lang !== '' && isset(wa_tpl_languages()[$lang])) {
        $where[] = 'language = ?';
        $args[] = $lang;
    }
    $q = trim((string) ($f['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(name LIKE ? OR display_name LIKE ? OR body LIKE ? OR description LIKE ?)';
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        array_push($args, $like, $like, $like, $like);
    }
    $rows = array_map('wa_tpl_decode', db_all('SELECT * FROM wa_templates WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(updated_at, created_at) DESC, id DESC', $args));
    $stats = wa_tpl_stats(array_column($rows, 'id'));
    $usage = wa_tpl_usage_counts($rows);
    foreach ($rows as &$r) {
        $r['stats'] = $stats[$r['id']];
        $r['usage'] = $usage[$r['id']];
        $r['last_used_at'] = $r['stats']['last_used_at'] ?: $r['last_used_at'];
        $r['editable'] = wa_tpl_is_editable($r);
    }
    unset($r);
    $sort = (string) ($f['sort'] ?? 'updated');
    if ($sort === 'name') {
        usort($rows, fn ($a, $b) => strnatcasecmp(wa_tpl_label($a), wa_tpl_label($b)));
    } elseif ($sort === 'usage') {
        usort($rows, fn ($a, $b) => [$b['stats']['total'], $b['id']] <=> [$a['stats']['total'], $a['id']]);
    } elseif ($sort === 'last_used') {
        usort($rows, fn ($a, $b) => strcmp((string) $b['last_used_at'], (string) $a['last_used_at']));
    } elseif ($sort === 'read_rate') {
        $rate = fn ($r) => $r['stats']['sent'] ? $r['stats']['read'] / $r['stats']['sent'] : -1;
        usort($rows, fn ($a, $b) => $rate($b) <=> $rate($a));
    }
    $total = count($rows);
    $perPage = max(1, min(100, $perPage));
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    $items = array_slice($rows, ($page - 1) * $perPage, $perPage);
    $recat = wa_tpl_recategorised($items);
    foreach ($items as &$r) {
        $r['recategorised'] = $recat[$r['id']] ?? null;
    }
    unset($r);
    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'pages' => $pages,
    ];
}

/** KPI summary for the library header. */
function wa_tpl_summary(): array
{
    $byStatus = db_pairs('SELECT status, COUNT(*) FROM wa_templates GROUP BY status');
    $byCat = db_pairs("SELECT category, COUNT(*) FROM wa_templates WHERE status <> 'archived' GROUP BY category");
    $byLang = db_pairs("SELECT language, COUNT(*) FROM wa_templates WHERE status <> 'archived' GROUP BY language");
    $since = date('Y-m-d 00:00:00', strtotime('-29 days'));
    $m = db_row(
        "SELECT COUNT(*) AS total, SUM(status IN ('sent','delivered','read','simulated')) AS sent, SUM(status IN ('delivered','read')) AS delivered,
                SUM(status = 'read') AS read_n, SUM(status = 'failed') AS failed
         FROM wa_messages WHERE direction = 'out' AND template_id IS NOT NULL AND created_at >= ?",
        [$since]
    ) ?: [];
    $top = db_all(
        "SELECT t.id, t.name, t.display_name, COUNT(*) AS sent, SUM(m.status = 'read') AS read_n
         FROM wa_messages m JOIN wa_templates t ON t.id = m.template_id
         WHERE m.direction = 'out' AND m.created_at >= ? GROUP BY t.id, t.name, t.display_name ORDER BY sent DESC LIMIT 6",
        [$since]
    );
    $all = array_sum(array_map('intval', $byStatus));
    return [
        'total' => $all - (int) ($byStatus['archived'] ?? 0),
        'by_status' => array_map('intval', $byStatus + array_fill_keys(array_keys(wa_tpl_statuses()), 0)),
        'by_category' => array_map('intval', $byCat),
        'by_language' => array_map('intval', $byLang),
        'messages_30d' => [
            'total' => (int) ($m['total'] ?? 0), 'sent' => (int) ($m['sent'] ?? 0), 'delivered' => (int) ($m['delivered'] ?? 0),
            'read' => (int) ($m['read_n'] ?? 0), 'failed' => (int) ($m['failed'] ?? 0),
        ],
        'top' => array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'label' => $r['display_name'] ?: $r['name'], 'sent' => (int) $r['sent'], 'read' => (int) $r['read_n']], $top),
        'mode' => function_exists('wa_mode') ? wa_mode() : 'test',
        'configured' => function_exists('wa_is_configured') ? wa_is_configured() : false,
        'last_sync' => setting('_wa_tpl_last_sync'),
    ];
}

/* ------------------------------------------------------------------------------------------------------------------
 * Interakt: submit, sync, import
 * ------------------------------------------------------------------------------------------------------------------ */

function wa_tpl_mode(): string
{
    return function_exists('wa_mode') ? wa_mode() : 'test';
}

/** Interakt approval_status -> local status. */
function wa_tpl_map_status($remote, $reason = null): string
{
    $s = strtoupper(trim((string) $remote));
    if (in_array($s, ['APPROVED', 'ACTIVE', 'ENABLED'], true)) {
        return 'approved';
    }
    if (in_array($s, ['REJECTED', 'DECLINED'], true)) {
        return 'rejected';
    }
    if (in_array($s, ['PAUSED'], true)) {
        return 'paused';
    }
    if (in_array($s, ['DISABLED', 'DELETED', 'FLAGGED', 'LIMIT_EXCEEDED'], true)) {
        return 'disabled';
    }
    return trim((string) $reason) !== '' ? 'rejected' : 'pending';
}

/** Buttons from an Interakt list/create response (double JSON-encoded string) -> our button array. */
function wa_tpl_decode_remote_buttons($b): array
{
    for ($i = 0; $i < 4 && is_string($b); $i++) {
        $d = json_decode($b, true);
        if ($d === null && json_last_error() !== JSON_ERROR_NONE) {
            break;
        }
        $b = $d;
    }
    if (!is_array($b)) {
        return [];
    }
    if (isset($b['buttons']) && is_array($b['buttons'])) {
        $b = $b['buttons'];
    }
    if (!array_is_list($b)) {
        return [];
    }
    $out = [];
    foreach ($b as $x) {
        if (!is_array($x)) {
            continue;
        }
        $type = strtoupper((string) ($x['type'] ?? ''));
        $btn = ['type' => $type, 'text' => (string) ($x['text'] ?? '')];
        if ($type === 'URL') {
            $btn['url'] = (string) ($x['url'] ?? '');
            $ex = $x['example'] ?? null;
            if ($ex) {
                $btn['example'] = is_array($ex) ? (string) reset($ex) : (string) $ex;
            }
        } elseif ($type === 'PHONE_NUMBER') {
            $btn['phone_number'] = (string) ($x['phone_number'] ?? '');
        } elseif ($type === 'COPY_CODE') {
            $ex = $x['example'] ?? '';
            $btn['example'] = is_array($ex) ? (string) reset($ex) : (string) $ex;
            $btn['text'] = $btn['text'] ?: 'Copy offer code';
        } elseif ($type === 'OTP') {
            $btn['otp_type'] = strtoupper((string) ($x['otp_type'] ?? 'COPY_CODE'));
            $btn['text'] = $btn['text'] ?: 'Copy code';
        } elseif ($type !== 'QUICK_REPLY') {
            continue;
        }
        $out[] = $btn;
    }
    return $out;
}

/** Build the POST track/templates/ payload for Interakt. $media = upload_to_fb result {file_handle, file_url, file_name}. */
function wa_tpl_interakt_payload(array $t, ?array $media = null): array
{
    $v = $t['variables'];
    $langs = wa_tpl_languages();
    $p = [
        'display_name' => $t['name'],
        'language' => $langs[$t['language']]['interakt'] ?? 'English',
        'category' => ucfirst(strtolower($t['category'])),
        'header_format' => null,
    ];
    $sorted = function (array $list): array {
        usort($list, fn ($a, $b) => (int) $a['pos'] <=> (int) $b['pos']);
        return array_map(fn ($i) => (string) ($i['sample'] ?? ''), $list);
    };
    if ($t['category'] === 'AUTHENTICATION') {
        $o = (array) ($t['otp_settings'] ?? []);
        $p['body'] = $t['body'];
        $p['body_text'] = $sorted($v['body']);
        $p['add_security_recommendation'] = !empty($o['add_security_recommendation']);
        $p['code_expiration_minutes'] = (int) ($o['code_expiration_minutes'] ?? 10);
        $p['button_type'] = 'OTP';
        $btn = ['type' => 'OTP', 'otp_type' => $o['otp_type'] ?? 'COPY_CODE', 'text' => $o['button_text'] ?? 'Copy code'];
        if (($o['otp_type'] ?? '') === 'ONE_TAP') {
            $btn += ['autofill_text' => $o['autofill_text'] ?? 'Autofill', 'package_name' => $o['package_name'] ?? '', 'signature_hash' => $o['signature_hash'] ?? ''];
        }
        $p['buttons'] = [$btn];
        return $p;
    }
    if ($t['header_type'] === 'text') {
        $p['header_format'] = 'TEXT';
        $p['header'] = $t['header_text'];
        if ($v['header']) {
            $p['header_text'] = $sorted($v['header']);
        }
    } elseif (in_array($t['header_type'], ['image', 'video', 'document'], true)) {
        $p['header_format'] = strtoupper($t['header_type']);
        if ($media) {
            $p['header_handle'] = [(string) ($media['file_handle'] ?? '')];
            $p['header_handle_file_url'] = (string) ($media['file_url'] ?? '');
            $p['header_handle_file_name'] = (string) ($media['file_name'] ?? ($t['header_media_name'] ?? 'sample'));
        }
    }
    $p['body'] = $t['body'];
    if ($v['body']) {
        $p['body_text'] = $sorted($v['body']);
    }
    if ($t['footer']) {
        $p['footer'] = $t['footer'];
    }
    if ($t['buttons']) {
        $isQr = $t['buttons'][0]['type'] === 'QUICK_REPLY';
        $p['button_type'] = $isQr ? 'Quick Replies' : 'Call To Action';
        $p['buttons'] = [];
        foreach ($t['buttons'] as $b) {
            switch ($b['type']) {
                case 'QUICK_REPLY':
                    $p['buttons'][] = ['type' => 'QUICK_REPLY', 'text' => $b['text']];
                    break;
                case 'URL':
                    $p['buttons'][] = ['type' => 'URL', 'text' => $b['text'], 'url' => $b['url']];
                    if (str_contains($b['url'], '{{1}}') && !isset($p['button_text'])) {
                        $p['button_text'] = (string) ($b['example'] ?? '');
                    }
                    break;
                case 'PHONE_NUMBER':
                    $p['buttons'][] = ['type' => 'PHONE_NUMBER', 'text' => $b['text'], 'phone_number' => $b['phone_number']];
                    break;
                case 'COPY_CODE':
                    $p['buttons'][] = ['type' => 'COPY_CODE', 'example' => $b['example'] ?? ''];
                    break;
            }
        }
    }
    return $p;
}

/** Unwrap a core Interakt response: ['ok', 'data' => decoded body] -> [ok, body array, message]. */
function wa_tpl_unwrap(array $res): array
{
    $body = is_array($res['data'] ?? null) ? $res['data'] : [];
    $ok = !empty($res['ok']) && (!array_key_exists('result', $body) || $body['result'] === true);
    $msg = (string) ($body['message'] ?? ($res['error'] ?? ''));
    return [$ok, $body, $msg];
}

/**
 * Submit a draft to Interakt for Meta approval.
 * Live: uploads the header sample via upload_to_fb, POSTs track/templates/, stores interakt id / name / status.
 * Test mode: nothing leaves GIMT — status becomes pending with a test id (approve / reject with the simulate tool).
 */
function wa_tpl_submit(int $id): array
{
    // one submission at a time per template (two admins clicking Submit must not create it twice in Interakt)
    $lock = 'gimt_wa_tpl_submit_' . $id;
    if ((int) db_value('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
        throw new CrudException('This template is being submitted right now. Refresh in a moment to see its status.');
    }
    try {
        return wa_tpl_submit_locked($id);
    } finally {
        db_value('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

function wa_tpl_submit_locked(int $id): array
{
    $t = wa_tpl_get($id);
    if (!$t) {
        throw new InvalidArgumentException('Template not found.');
    }
    if (!in_array($t['status'], ['draft'], true)) {
        throw new CrudException('Only drafts can be submitted. To change a submitted template, create a new version.');
    }
    $issues = wa_tpl_issues($t);
    $errors = wa_tpl_blocking($issues, true);
    if ($errors) {
        throw new CrudValidationException($errors, 'Fix the highlighted items before submitting to Interakt.');
    }
    $mode = wa_tpl_mode();
    $raw = wa_tpl_row($id);
    $media = null;
    $now = date('Y-m-d H:i:s');

    if ($mode === 'live') {
        if (in_array($t['header_type'], ['image', 'video', 'document'], true)) {
            $abs = wa_tpl_local_media_path($t['header_media_url']);
            if (!$abs) {
                throw new CrudException('Upload the header sample file in the designer (Interakt needs the file itself, not an outside link).');
            }
            if (!function_exists('wa_interakt_upload_media')) {
                throw new CrudException('The WhatsApp core service is not available.');
            }
            [$ok, $body, $msg] = wa_tpl_unwrap(wa_interakt_upload_media($abs, $t['header_media_name'] ?: basename($abs)));
            $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
            if (!$ok || empty($data['file_handle'])) {
                throw new CrudException('Interakt did not accept the header sample: ' . ($msg ?: 'unknown error'));
            }
            $media = $data;
        }
        $payload = wa_tpl_interakt_payload($t, $media);
        [$ok, $body, $msg] = wa_tpl_unwrap(wa_interakt_create_template($payload));
        if (!$ok) {
            $hint = $t['category'] === 'AUTHENTICATION' ? ' Authentication templates can also be created in the Interakt dashboard and then imported here.' : '';
            throw new CrudException('Interakt rejected the submission: ' . ($msg ?: 'unknown error') . $hint);
        }
        $d = is_array($body['data'] ?? null) ? $body['data'] : [];
        $remoteName = (string) ($d['name'] ?? $t['name']);
        $upd = [
            'interakt_id' => isset($d['id']) ? (string) $d['id'] : null,
            'wa_template_id' => isset($d['wa_template_id']) ? (string) $d['wa_template_id'] : null,
            'status' => wa_tpl_map_status($d['approval_status'] ?? 'WAITING', $d['rejection_reason'] ?? null),
            'rejection_reason' => ($d['rejection_reason'] ?? null) ?: null,
            'submitted_at' => $now,
            'synced_at' => $now,
            'header_handle' => $media['file_handle'] ?? $raw['header_handle'],
        ];
        if ($remoteName !== $t['name'] && preg_match('/^[a-z0-9_]+$/', $remoteName) && !wa_tpl_name_taken($remoteName, $t['language'], $id)) {
            $upd['name'] = $remoteName;
        }
        if ($upd['status'] === 'approved') {
            $upd['approved_at'] = $now;
        }
    } else {
        // test mode: log the would-be request through core (it never leaves GIMT in test mode)
        $payload = wa_tpl_interakt_payload($t, in_array($t['header_type'], ['image', 'video', 'document'], true)
            ? ['file_handle' => 'test-handle', 'file_url' => (string) $t['header_media_url'], 'file_name' => (string) ($t['header_media_name'] ?? '')] : null);
        $d = [];
        if (function_exists('wa_interakt_create_template')) {
            try {
                [, $body] = wa_tpl_unwrap(wa_interakt_create_template($payload));
                $d = is_array($body['data'] ?? null) ? $body['data'] : [];
            } catch (Throwable $e) {
                $d = [];
            }
        }
        $upd = [
            'interakt_id' => !empty($d['id']) ? (string) $d['id'] : 'test-tpl-' . substr(bin2hex(random_bytes(8)), 0, 12),
            'wa_template_id' => !empty($d['wa_template_id']) ? (string) $d['wa_template_id'] : null,
            'status' => 'pending',
            'rejection_reason' => null,
            'submitted_at' => $now,
            'synced_at' => $now,
        ];
    }
    db_update('wa_templates', $upd + ['updated_by' => function_exists('user_id') ? user_id() : null], 'id = ?', [$id]);
    return ['template' => wa_tpl_get($id), 'mode' => $mode, 'payload' => $payload];
}

/** Test mode only: approve / reject / pause a pending template locally (fires WA_TEMPLATE_STATUS). */
function wa_tpl_simulate(int $id, string $status, ?string $reason = null): array
{
    $t = wa_tpl_row($id);
    if (!$t) {
        throw new InvalidArgumentException('Template not found.');
    }
    if (wa_tpl_mode() !== 'test') {
        throw new CrudException('Simulation is only available in test mode — live templates are reviewed by Meta.');
    }
    if (!in_array($status, ['approved', 'rejected', 'paused', 'pending'], true)) {
        throw new CrudValidationException(['status' => 'Choose approved, rejected, paused or pending.']);
    }
    if ($t['status'] === 'draft') {
        throw new CrudException('Submit the template first.');
    }
    if ($status === 'rejected' && trim((string) $reason) === '') {
        throw new CrudValidationException(['reason' => 'Give the rejection reason Meta would send.']);
    }
    $now = date('Y-m-d H:i:s');
    $upd = [
        'status' => $status,
        'rejection_reason' => $status === 'rejected' ? mb_substr(trim((string) $reason), 0, 1000) : null,
        'synced_at' => $now,
        'wa_template_id' => $t['wa_template_id'] ?: (string) random_int(1000000000000000, 1999999999999999),
    ];
    if ($status === 'approved') {
        $upd['approved_at'] = $t['approved_at'] ?: $now;
    }
    db_update('wa_templates', $upd, 'id = ?', [$id]);
    if ($t['status'] !== $status) {
        wa_tpl_fire_status($id, $status, $upd['rejection_reason'], 'simulate');
    }
    return wa_tpl_get($id);
}

/** Raise WA_TEMPLATE_STATUS (listeners: notifications here, anything else in other parts). */
function wa_tpl_fire_status(int $id, string $status, ?string $reason = null, string $source = 'sync'): void
{
    if (function_exists('wa_event')) {
        wa_event('WA_TEMPLATE_STATUS', ['template_id' => $id, 'status' => $status, 'reason' => $reason, 'source' => $source], ['source' => 'admin']);
    } else {
        wa_tpl_notify_status($id, $status, $reason);
    }
}

/** In-app notification for a template review result. */
function wa_tpl_notify_status(int $id, string $status, ?string $reason = null): void
{
    if (!in_array($status, ['approved', 'rejected', 'paused', 'disabled'], true)) {
        return;
    }
    $t = db_row('SELECT id, name, display_name, rejection_reason FROM wa_templates WHERE id = ?', [$id]);
    if (!$t) {
        return;
    }
    $label = $t['display_name'] ?: $t['name'];
    $titles = [
        'approved' => "WhatsApp template approved: $label",
        'rejected' => "WhatsApp template rejected: $label",
        'paused' => "WhatsApp template paused by Meta: $label",
        'disabled' => "WhatsApp template disabled: $label",
    ];
    $msg = match ($status) {
        'approved' => "\"{$t['name']}\" can now be used in events, workflows and campaigns.",
        'rejected' => 'Reason: ' . (($reason ?: $t['rejection_reason']) ?: 'not given') . '. Create a new version to fix it.',
        'paused' => 'Meta paused it after low quality feedback. Sends will fail until it is active again.',
        default => 'Sends using this template will fail. Check the Interakt dashboard.',
    };
    notify('perm:whatsapp_templates', 'whatsapp', $titles[$status], $msg, 'admin/whatsapp/templates?view=' . $id, $status === 'approved' ? 'check-circle' : 'alert-triangle');
}

/** Every template on the Interakt account (all pages; stops early at $deadline = microtime(true) limit). */
function wa_tpl_remote_all(?float $deadline = null): array
{
    if (!function_exists('wa_interakt_list_templates')) {
        throw new CrudException('The WhatsApp core service is not available.');
    }
    $all = [];
    $seen = [];
    $offset = 0;
    for ($page = 0; $page < 40; $page++) {
        if ($page > 0 && $deadline !== null && microtime(true) > $deadline) {
            break;
        }
        [$ok, $body, $msg] = wa_tpl_unwrap(wa_interakt_list_templates(['offset' => $offset, 'autosubmitted_for' => 'all', 'language' => 'all']));
        if (!$ok) {
            throw new CrudException('Interakt did not return the template list: ' . ($msg ?: 'unknown error'));
        }
        $list = $body['results']['templates'] ?? $body['data']['results']['templates'] ?? $body['templates'] ?? [];
        if (!is_array($list) || !$list) {
            break;
        }
        $new = 0;
        foreach ($list as $r) {
            if (!is_array($r)) {
                continue;
            }
            $key = (string) ($r['id'] ?? ($r['name'] ?? '') . '|' . ($r['language'] ?? ''));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $all[] = $r;
            $new++;
        }
        if (!$new || empty($body['has_next'] ?? $body['data']['has_next'] ?? false)) {
            break;
        }
        $offset += count($list);
    }
    return $all;
}

/** Interakt language (code or full name) -> our code. */
function wa_tpl_lang_code($lang): string
{
    $lang = trim((string) $lang);
    $langs = wa_tpl_languages();
    if (isset($langs[$lang])) {
        return $lang;
    }
    $norm = str_replace('-', '_', $lang);
    if (isset($langs[$norm])) {
        return $norm;
    }
    foreach ($langs as $code => $l) {
        if (strcasecmp($l['interakt'], $lang) === 0 || strcasecmp($l['label'], $lang) === 0) {
            return $code;
        }
    }
    return strtolower(substr($norm, 0, 2)) === 'hi' ? 'hi' : 'en';
}

/** Remote Interakt template -> local row (for import). Variables get placeholder keys var_1… (map them afterwards). */
function wa_tpl_from_remote(array $r): array
{
    $header = (string) ($r['header'] ?? '');
    $body = (string) ($r['body'] ?? '');
    $vars = ['header' => [], 'body' => [], 'buttons' => []];
    $hf = strtolower((string) ($r['header_format'] ?? ''));
    $headerType = in_array($hf, ['text', 'image', 'video', 'document'], true) ? $hf : 'none';
    if ($headerType === 'text' && preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $header, $m)) {
        foreach (array_unique($m[1]) as $p) {
            $vars['header'][] = ['pos' => (int) $p, 'key' => 'header_var_' . $p, 'sample' => ''];
        }
    }
    if (preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $m)) {
        $ps = array_unique(array_map('intval', $m[1]));
        sort($ps);
        foreach ($ps as $p) {
            $vars['body'][] = ['pos' => $p, 'key' => 'var_' . $p, 'sample' => ''];
        }
    }
    $buttons = wa_tpl_decode_remote_buttons($r['buttons'] ?? null);
    foreach ($buttons as $i => $b) {
        if ($b['type'] === 'URL' && str_contains($b['url'] ?? '', '{{')) {
            $sample = '';
            if (!empty($b['example'])) {
                $prefix = (string) strstr($b['url'], '{{', true);
                $sample = str_starts_with($b['example'], $prefix) ? substr($b['example'], strlen($prefix)) : $b['example'];
            }
            $vars['buttons'][(string) $i] = ['key' => 'button_' . ($i + 1) . '_var', 'sample' => $sample];
        } elseif ($b['type'] === 'OTP') {
            $vars['buttons'][(string) $i] = ['key' => 'otp_code', 'sample' => ''];
        }
    }
    // unknown / missing category → Marketing: the marketing opt-in gate applies and no utility guard is sent, so a
    // wrong guess never reaches contacts without consent (a later sync copies the category once Interakt reports it)
    $category = wa_tpl_norm_category($r['category'] ?? null) ?? 'MARKETING';
    if ($category === 'AUTHENTICATION') {
        foreach ($vars['body'] as &$bv) {
            $bv['key'] = 'otp_code';
        }
        unset($bv);
    }
    $status = wa_tpl_map_status($r['approval_status'] ?? '', $r['rejection_reason'] ?? null);
    $created = !empty($r['created_at_utc']) ? date('Y-m-d H:i:s', strtotime($r['created_at_utc'] . ' UTC')) : null;
    $otp = null;
    if ($category === 'AUTHENTICATION') {
        $otp = ['code_expiration_minutes' => (int) ($r['code_expiration_minutes'] ?? 10) ?: 10, 'add_security_recommendation' => !empty($r['add_security_recommendation']), 'otp_type' => 'COPY_CODE', 'button_text' => $buttons[0]['text'] ?? 'Copy code'];
    }
    return [
        'name' => substr(wa_tpl_slug((string) ($r['name'] ?? '')) ?: 'imported_' . substr(md5(json_encode($r)), 0, 8), 0, 190),
        'display_name' => mb_substr((string) ($r['display_name'] ?? '') ?: (string) ($r['name'] ?? ''), 0, 190) ?: null,
        'category' => $category,
        'language' => wa_tpl_lang_code($r['language'] ?? 'en'),
        'header_type' => $headerType,
        'header_text' => $headerType === 'text' ? mb_substr($header, 0, 60) : null,
        'header_media_url' => in_array($headerType, ['image', 'video', 'document'], true) ? (($r['header_handle_file_url'] ?? null) ?: null) : null,
        'header_media_name' => ($r['header_handle_file_name'] ?? null) ?: null,
        'body' => $body,
        'footer' => mb_substr((string) ($r['footer'] ?? ''), 0, 60) ?: null,
        'buttons' => $buttons ? json_encode($buttons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'variables' => json_encode(['header' => $vars['header'], 'body' => $vars['body'], 'buttons' => (object) $vars['buttons']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'otp_settings' => $otp ? json_encode($otp) : null,
        'status' => $status,
        'interakt_id' => isset($r['id']) ? (string) $r['id'] : null,
        'wa_template_id' => isset($r['wa_template_id']) ? (string) $r['wa_template_id'] : null,
        'rejection_reason' => ($r['rejection_reason'] ?? null) ?: null,
        'submitted_at' => $created,
        'approved_at' => $status === 'approved' ? ($created ?: date('Y-m-d H:i:s')) : null,
        'synced_at' => date('Y-m-d H:i:s'),
    ];
}

/** Find the local template for a remote one: interakt id, Meta id, then name + language. */
function wa_tpl_match_remote(array $r): ?array
{
    if (!empty($r['id']) && ($row = db_row('SELECT * FROM wa_templates WHERE interakt_id = ? LIMIT 1', [(string) $r['id']]))) {
        return $row;
    }
    if (!empty($r['wa_template_id']) && ($row = db_row('SELECT * FROM wa_templates WHERE wa_template_id = ? LIMIT 1', [(string) $r['wa_template_id']]))) {
        return $row;
    }
    if (!empty($r['name'])) {
        return db_row('SELECT * FROM wa_templates WHERE name = ? AND language = ? LIMIT 1', [(string) $r['name'], wa_tpl_lang_code($r['language'] ?? 'en')]);
    }
    return null;
}

/**
 * Copy Interakt's (= Meta's) category into a local template when it differs. Meta re-categorises templates on review
 * (Interakt creates them with allow_category_change), typically Utility → Marketing; the local category decides the
 * marketing opt-in gate and the utility guard (template_category) on every send, so it must follow Meta. The change is
 * written to the activity log (action 'recategorise', meta {from,to,source} — the library reads it back) and, unless
 * $notify is false, template managers get an in-app notification. Unknown remote categories change nothing.
 * @return array{from:string,to:string}|null the change, or null when nothing changed
 */
function wa_tpl_apply_remote_category(array $local, $remoteCategory, string $source = 'sync', bool $notify = true): ?array
{
    $to = wa_tpl_norm_category($remoteCategory);
    $from = strtoupper((string) ($local['category'] ?? ''));
    $id = (int) ($local['id'] ?? 0);
    if ($to === null || $to === $from || $id <= 0) {
        return null;
    }
    db_exec('UPDATE wa_templates SET category = ? WHERE id = ?', [$to, $id]);
    $cats = wa_tpl_categories();
    $fromLabel = $cats[$from]['label'] ?? ($from !== '' ? ucfirst(strtolower($from)) : 'unknown');
    $toLabel = $cats[$to]['label'];
    $name = (string) ($local['name'] ?? '');
    $label = wa_tpl_label($local);
    log_activity('recategorise', 'whatsapp_templates', $id, "Meta re-categorised WhatsApp template \"$name\" from $fromLabel to $toLabel (Interakt $source)", 'success',
        ['from' => $from, 'to' => $to, 'source' => $source]);
    if ($notify) {
        if ($to === 'MARKETING') {
            $title = "Meta re-categorised $label to Marketing";
            $msg = ($from === 'UTILITY' ? "Utility sends of \"$name\" will fail at Interakt, so GIMT sends it as Marketing from now on" : "GIMT sends \"$name\" as Marketing from now on")
                . ' — a marketing opt-in is now required: automated messages to contacts without one are skipped, including those already queued. Review the events, workflows and follow-ups that use it, or create a utility version without promotional wording.';
        } elseif ($to === 'UTILITY') {
            $title = "Meta re-categorised $label to Utility";
            $msg = "\"$name\" is now sent as Utility (Interakt checks the utility category on every send); a marketing opt-in is no longer required for it.";
        } else {
            $title = "Meta re-categorised $label to $toLabel";
            $msg = "\"$name\" changed from $fromLabel to $toLabel. Check the events, workflows and campaigns that use it.";
        }
        notify('perm:whatsapp_templates', 'whatsapp', $title, $msg, 'admin/whatsapp/templates?view=' . $id, 'alert-triangle');
    }
    return ['from' => $from, 'to' => $to];
}

/**
 * Latest Meta re-categorisation per template id (activity log rows written by wa_tpl_apply_remote_category) that still
 * matches the template's current category. @return array<int, array{from:string,to:string,at:string}>
 */
function wa_tpl_recategorised(array $templates): array
{
    $current = [];
    foreach ($templates as $t) {
        if (isset($t['id'])) {
            $current[(int) $t['id']] = strtoupper((string) ($t['category'] ?? ''));
        }
    }
    if (!$current) {
        return [];
    }
    $ids = array_map('strval', array_keys($current));
    $rows = db_all("SELECT record_id, meta, created_at FROM activity_logs WHERE module = 'whatsapp_templates' AND action = 'recategorise'
                    AND record_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id DESC', $ids);
    $out = [];
    $seen = [];
    foreach ($rows as $r) {
        $id = (int) $r['record_id'];
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true; // only the latest change counts
        $m = json_decode((string) $r['meta'], true);
        if (is_array($m) && !empty($m['to']) && !empty($m['from']) && strtoupper((string) $m['to']) === ($current[$id] ?? '')) {
            $out[$id] = ['from' => strtoupper((string) $m['from']), 'to' => strtoupper((string) $m['to']), 'at' => (string) $r['created_at']];
        }
    }
    return $out;
}

/**
 * Pull statuses (and Meta's category) from Interakt (live). $import: also add remote templates missing locally
 * ($onlyRemoteIds limits that). Returns ['mode','checked','updated','imported','changes'=>[...],'recategorised'=>[...]].
 */
function wa_tpl_sync(bool $import = false, ?array $onlyRemoteIds = null, ?float $deadline = null): array
{
    $mode = wa_tpl_mode();
    if ($mode !== 'live') {
        // test mode: Interakt is not contacted, so there is no "last sync" to record
        return ['mode' => $mode, 'checked' => 0, 'updated' => 0, 'imported' => 0, 'unchanged' => 0, 'changes' => [], 'recategorised' => []];
    }
    $res = wa_tpl_sync_apply(wa_tpl_remote_all($deadline), $import, $onlyRemoteIds);
    save_setting('_wa_tpl_last_sync', date('Y-m-d H:i:s'), 'whatsapp');
    return $res;
}

/**
 * Apply Interakt's template list (rows as returned by GET /v1/public/track/organization/templates) to the library:
 * status, rejection reason, ids, name and Meta's category for matched templates; $import adds missing ones. Split from
 * wa_tpl_sync() so the matching rules can be exercised with a given list (dev installs never run live).
 */
function wa_tpl_sync_apply(array $remote, bool $import = false, ?array $onlyRemoteIds = null): array
{
    $res = ['mode' => 'live', 'checked' => count($remote), 'updated' => 0, 'imported' => 0, 'unchanged' => 0, 'changes' => [], 'recategorised' => []];
    foreach ($remote as $r) {
        if (!is_array($r)) {
            continue;
        }
        $local = wa_tpl_match_remote($r);
        if ($local) {
            $status = wa_tpl_map_status($r['approval_status'] ?? '', $r['rejection_reason'] ?? null);
            if ($local['status'] === 'archived' || $local['status'] === 'draft') {
                // archived stays archived locally (remember the remote status + category for restore); drafts never sync
                if ($local['status'] === 'archived') {
                    db_update('wa_templates', ['archived_from' => $status, 'synced_at' => date('Y-m-d H:i:s')], 'id = ?', [$local['id']]);
                    if ($cat = wa_tpl_apply_remote_category($local, $r['category'] ?? null, 'sync', false)) {
                        $res['recategorised'][] = ['id' => (int) $local['id'], 'name' => $local['name']] + $cat;
                    }
                }
                $res['unchanged']++;
                continue;
            }
            // Meta's category is authoritative (it re-categorises on review, typically Utility → Marketing): the local
            // category drives the marketing opt-in gate and the utility guard on every send
            if ($cat = wa_tpl_apply_remote_category($local, $r['category'] ?? null, 'sync')) {
                $res['recategorised'][] = ['id' => (int) $local['id'], 'name' => $local['name']] + $cat;
            }
            $upd = [
                'status' => $status,
                'rejection_reason' => ($r['rejection_reason'] ?? null) ?: null,
                'interakt_id' => $local['interakt_id'] ?: (isset($r['id']) ? (string) $r['id'] : null),
                'wa_template_id' => (isset($r['wa_template_id']) && $r['wa_template_id'] !== '') ? (string) $r['wa_template_id'] : $local['wa_template_id'],
                'synced_at' => date('Y-m-d H:i:s'),
            ];
            if ($status === 'approved' && !$local['approved_at']) {
                $upd['approved_at'] = date('Y-m-d H:i:s');
            }
            if (!empty($r['name']) && $r['name'] !== $local['name'] && !wa_tpl_name_taken((string) $r['name'], $local['language'], (int) $local['id'])) {
                $upd['name'] = (string) $r['name'];
            }
            db_update('wa_templates', $upd, 'id = ?', [$local['id']]);
            if ($status !== $local['status']) {
                $res['updated']++;
                $res['changes'][] = ['id' => (int) $local['id'], 'name' => $local['name'], 'from' => $local['status'], 'to' => $status];
                wa_tpl_fire_status((int) $local['id'], $status, $upd['rejection_reason'], 'sync');
            } else {
                $res['unchanged']++;
            }
        } elseif ($import && ($onlyRemoteIds === null || in_array((string) ($r['id'] ?? ''), $onlyRemoteIds, true))) {
            $row = wa_tpl_from_remote($r);
            if (wa_tpl_name_taken($row['name'], $row['language'])) {
                continue;
            }
            $row['created_by'] = function_exists('user_id') ? user_id() : null;
            $newId = db_insert('wa_templates', $row);
            $res['imported']++;
            $res['changes'][] = ['id' => $newId, 'name' => $row['name'], 'from' => null, 'to' => $row['status']];
        }
    }
    return $res;
}

/** Remote templates that are not in the library yet (import dialog). */
function wa_tpl_remote_missing(): array
{
    if (wa_tpl_mode() !== 'live') {
        return [];
    }
    $out = [];
    foreach (wa_tpl_remote_all() as $r) {
        if (wa_tpl_match_remote($r)) {
            continue;
        }
        $row = wa_tpl_from_remote($r);
        $out[] = [
            'remote_id' => (string) ($r['id'] ?? ''),
            'name' => $row['name'],
            'display_name' => $row['display_name'],
            'category' => $row['category'],
            'language' => $row['language'],
            'status' => $row['status'],
            'header_type' => $row['header_type'],
            'body' => $row['body'],
            'buttons' => json_decode((string) $row['buttons'], true) ?: [],
            'variables' => count(json_decode($row['variables'], true)['body'] ?? []),
            'created_at' => $row['submitted_at'],
        ];
    }
    return $out;
}

/** Hourly status sync from the worker (live mode only). */
function wa_tpl_tick(float $budget): array
{
    if (wa_tpl_mode() !== 'live' || (function_exists('wa_is_configured') && !wa_is_configured())) {
        return ['skipped' => 'test mode'];
    }
    $last = (string) setting('_wa_tpl_last_sync', '');
    $hasPending = (int) db_value("SELECT COUNT(*) FROM wa_templates WHERE status = 'pending'");
    $every = $hasPending ? 900 : 3600; // poll faster while something waits for Meta
    if ($last !== '' && strtotime($last) > time() - $every) {
        return ['skipped' => 'recent'];
    }
    try {
        $r = wa_tpl_sync(false, null, microtime(true) + max(1.0, $budget * 0.8));
        return ['checked' => $r['checked'], 'updated' => $r['updated'], 'recategorised' => count($r['recategorised'])];
    } catch (Throwable $e) {
        save_setting('_wa_tpl_last_sync', date('Y-m-d H:i:s'), 'whatsapp'); // back off one interval
        return ['error' => $e->getMessage()];
    }
}

/* ------------------------------------------------------------------------------------------------------------------
 * Hooks (registered at include time; no queries here)
 * ------------------------------------------------------------------------------------------------------------------ */

if (function_exists('wa_on')) {
    wa_on('WA_TEMPLATE_STATUS', function (array $event, array $payload, ?array $contact) {
        $id = (int) ($payload['template_id'] ?? 0);
        $status = (string) ($payload['status'] ?? '');
        if ($id > 0 && $status !== '') {
            wa_tpl_notify_status($id, $status, isset($payload['reason']) ? (string) $payload['reason'] : null);
            return ['notified' => $status];
        }
        return null;
    });
}
if (function_exists('wa_on_tick')) {
    wa_on_tick('templates_sync', 'wa_tpl_tick');
}
