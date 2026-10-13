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
 * acknowledgement email only when a matching email template exists (pf_send_ack()). Spam protection (honeypot field
 * "website" + per-IP rate_limit()) lives in pf_guard() and is applied by the callers.
 */
require_once __DIR__ . '/admissions.php';
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
    return in_array(strtolower(trim((string) $value)), ['1', 'on', 'true', 'yes'], true);
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

/** Result of a no-JS form post, kept in the session for the page the visitor returns to (see sf_flash_notice()). */
function pf_flash(string $form, bool $ok, string $message, array $errors = []): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['public_form'][$form] = ['ok' => $ok, 'message' => $message, 'errors' => $errors, 'at' => time()];
    }
}

function pf_take_flash(string $form): ?array
{
    $f = $_SESSION['public_form'][$form] ?? null;
    unset($_SESSION['public_form'][$form]);
    return $f && time() - (int) ($f['at'] ?? 0) < 600 ? $f : null;
}

/**
 * Pages that show the no-JS result of a form inline (sf_flash_notice() in includes/partials/site_forms.php).
 * A plain post from any other page (footer newsletter, other units' forms) gets pf_result_page() instead.
 */
function pf_flash_pages(string $form): array
{
    return ['contact' => ['/contact'], 'enquiry' => ['/admissions']][$form] ?? [];
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

/** Same-site path to send a no-JS visitor back to (Referer when it points at this site, else $fallback). */
function pf_back_url(string $fallback = '/', string $anchor = ''): string
{
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $target = $fallback;
    if ($ref !== '') {
        $p = parse_url($ref);
        $host = strtolower((string) ($p['host'] ?? ''));
        $self = strtolower((string) parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
        if ($host !== '' && $host === $self) {
            $target = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        }
    }
    return safe_redirect_target($target, $fallback) . $anchor;
}

/**
 * Spam protection for every public form: per-IP fixed-window rate limit, then the honeypot field "website"
 * (hidden from people, filled by bots). Throws PublicFormException (429 / 422).
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
    if (trim((string) ($in['website'] ?? '')) !== '') {
        log_system('warning', 'Public form submission blocked (honeypot)', ['form' => $form, 'ip' => client_ip(), 'ua' => user_agent()]);
        throw new PublicFormException('Your submission could not be accepted. Please refresh the page and try again.', [], 422);
    }
}

/** Shared phone check (validate()'s "phone" rule + at least 10 digits, as in the admin forms). */
function pf_check_phone(array &$errors, string $field, ?string $value, bool $required, string $label = 'Phone number'): void
{
    if ($value === null || $value === '') {
        if ($required && !isset($errors[$field])) {
            $errors[$field] = $label . ' is required.';
        }
        return;
    }
    if (!isset($errors[$field]) && (!preg_match('/^\+?[0-9\s\-()]{7,20}$/', $value) || strlen(admission_phone_key($value)) < 10)) {
        $errors[$field] = 'Enter a valid 10-digit mobile number.';
    }
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
    $short = (string) ($p['short_name'] ?? '');
    $name = (string) ($p['name'] ?? '');
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
        'name' => pf_text($in['name'] ?? null), 'email' => strtolower((string) pf_text($in['email'] ?? null)) ?: null,
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
    // Double-submit guard: the same message from the same email within 10 minutes is stored once.
    $existing = db_value('SELECT id FROM contact_messages WHERE email = ? AND message = ? AND created_at >= ? ORDER BY id DESC LIMIT 1',
        [$data['email'], $data['message'], date('Y-m-d H:i:s', time() - 600)]);
    if ($existing) {
        return ['id' => (int) $existing, 'message' => 'Thank you, ' . pf_first_name($data['name']) . '! We already have your message and will reply within 24 hours.'];
    }
    $id = db_insert('contact_messages', [
        'name' => mb_substr($data['name'], 0, 150), 'email' => $data['email'], 'phone' => mb_substr((string) $data['phone'], 0, 20),
        'enquiry_type' => $type['type'], 'subject' => mb_substr($data['subject'] ?: $type['subject'], 0, 255), 'message' => $data['message'],
        'status' => 'new', 'ip_address' => client_ip(), 'user_agent' => user_agent(),
    ]);
    contact_message_notify($id);
    log_activity('create', 'contact_messages', $id, sprintf('Website contact message from %s (%s)', $data['name'], $type['label']));
    $mailed = pf_send_ack('contact_received', $data['email'], ['name' => $data['name'], 'subject' => $data['subject'] ?: $type['subject'], 'message' => $data['message']],
        ['related_type' => 'contact_message', 'related_id' => $id]);
    return ['id' => $id, 'message' => 'Thank you, ' . pf_first_name($data['name']) . '! Your message has been received. Our team will get back to you within 24 hours'
        . ($mailed ? ' — a copy has been sent to your email.' : '.')];
}

/* ------------------------------------------------------------------
 * Counselling / quick enquiries
 * ------------------------------------------------------------------ */

function pf_submit_enquiry(array $in): array
{
    $data = [
        'name' => pf_text($in['name'] ?? null), 'phone' => pf_text($in['phone'] ?? null), 'email' => strtolower((string) pf_text($in['email'] ?? null)) ?: null,
        'city' => pf_text($in['city'] ?? null), 'message' => pf_text($in['message'] ?? null, true),
        'program_id' => pf_text($in['program_id'] ?? null), 'program' => pf_text($in['program'] ?? null),
        'utm_source' => pf_text($in['utm_source'] ?? null), 'utm_campaign' => pf_text($in['utm_campaign'] ?? null),
    ];
    $errors = validate($data, [
        'name' => 'required|min:2|max:150', 'email' => 'email|max:190', 'city' => 'max:80', 'message' => 'max:2000',
        'program' => 'max:190', 'utm_source' => 'max:100', 'utm_campaign' => 'max:100',
    ], ['name' => 'Full Name', 'email' => 'Email Address', 'city' => 'City', 'message' => 'Message', 'program' => 'Program']);
    pf_check_name($errors, 'name', $data['name'], 'Full Name');
    pf_check_phone($errors, 'phone', $data['phone'], true, 'Phone Number');
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
    $form = pf_source_label($in['form'] ?? null);
    $what = $program ? $program['short_name'] : ($interest ?: 'a program');
    $phoneKey = admission_phone_key($data['phone']);

    // Repeat enquiry from the same phone (open, last 30 days): update it instead of creating a duplicate lead.
    $open = db_row("SELECT * FROM enquiries WHERE " . admission_phone_sql('phone') . " = ? AND status IN ('new','contacted','interested') AND created_at >= ? ORDER BY id DESC LIMIT 1",
        [$phoneKey, date('Y-m-d H:i:s', strtotime('-30 days'))]);
    if ($open) {
        $note = '[' . date('d M Y H:i') . '] Repeat website enquiry (' . $pageLabel . ($form ? ' · ' . $form : '') . ')' . ($program ? ' for ' . $program['short_name'] : '') . ($data['message'] ? ': ' . $data['message'] : '');
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
        return ['id' => (int) $open['id'], 'message' => 'Thank you, ' . pf_first_name($data['name']) . '! We already have your request — our admission counsellor will call you shortly.'];
    }

    $id = db_insert('enquiries', [
        'name' => mb_substr($data['name'], 0, 150), 'email' => $data['email'], 'phone' => mb_substr($data['phone'], 0, 20), 'city' => $data['city'],
        'program_id' => $program['id'] ?? null, 'program_interest' => $interest, 'source' => 'website', 'message' => $data['message'],
        'status' => 'new', 'priority' => in_array($form, ['counselling', 'apply'], true) ? 'high' : 'medium', 'follow_up_date' => date('Y-m-d', strtotime('+1 day')),
        'notes' => 'Website form: ' . $pageLabel . ($form ? ' · ' . $form : ''), 'utm_source' => $data['utm_source'], 'utm_campaign' => $data['utm_campaign'], 'ip_address' => client_ip(),
    ]);
    notify('perm:enquiries', 'enquiry', 'New website enquiry', $data['name'] . ' (' . $data['phone'] . ') is interested in ' . $what . '.', 'admin/enquiries?q=' . rawurlencode($phoneKey), 'message-circle');
    log_activity('create', 'enquiries', $id, sprintf('Website enquiry from %s for %s (%s)', $data['name'], $what, $pageLabel));
    pf_send_ack('enquiry_received', $data['email'], ['name' => $data['name'], 'program' => $program ? $program['name'] : ($interest ?: 'our programs')], ['related_type' => 'enquiry', 'related_id' => $id]);
    return ['id' => $id, 'message' => 'Thank you, ' . pf_first_name($data['name']) . '! Our admission counsellor will call you within 24 hours.'];
}

/* ------------------------------------------------------------------
 * Newsletter
 * ------------------------------------------------------------------ */

function pf_submit_newsletter(array $in): array
{
    $data = ['email' => strtolower((string) pf_text($in['email'] ?? null)) ?: null, 'name' => pf_text($in['name'] ?? null)];
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
        'event_id' => pf_text($in['event_id'] ?? null), 'name' => pf_text($in['name'] ?? null), 'email' => strtolower((string) pf_text($in['email'] ?? null)) ?: null,
        'phone' => pf_text($in['phone'] ?? null), 'organization' => pf_text($in['organization'] ?? null),
    ];
    $errors = validate($data, ['event_id' => 'required|integer', 'name' => 'required|min:2|max:150', 'email' => 'required|email|max:190', 'organization' => 'max:190'],
        ['event_id' => 'Event', 'name' => 'Full Name', 'email' => 'Email Address', 'organization' => 'Organisation / College']);
    pf_check_name($errors, 'name', $data['name'], 'Full Name');
    pf_check_phone($errors, 'phone', $data['phone'], false, 'Phone Number');
    $event = null;
    if (!isset($errors['event_id'])) {
        $event = db_row("SELECT * FROM events WHERE id = ? AND status = 'published' AND show_on_website = 1", [(int) $data['event_id']]);
        if (!$event) {
            $errors['event_id'] = 'This event is not open for registration.';
        } elseif (strtotime((string) ($event['end_datetime'] ?: $event['start_datetime'])) < time()) {
            $errors['event_id'] = 'Registration is closed — this event has already taken place.';
        }
    }
    if ($errors) {
        throw new PublicFormException(isset($errors['event_id']) && count($errors) === 1 ? $errors['event_id'] : PF_FIX_FIELDS, $errors);
    }
    $existing = db_row('SELECT * FROM event_registrations WHERE event_id = ? AND email = ?', [(int) $event['id'], $data['email']]);
    if ($existing && $existing['status'] !== 'cancelled') {
        throw new PublicFormException('You are already registered for this event.', ['email' => 'This email address is already registered for ' . $event['title'] . '.']);
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
    $when = format_date($event['start_datetime'], 'd M Y') . ', ' . date('g:i A', strtotime((string) $event['start_datetime']));
    notify('perm:events', 'event', 'New event registration', $data['name'] . ' registered for ' . $event['title'] . '.', 'admin/events', 'calendar-check');
    log_activity('create', 'events', $event['id'], sprintf('%s (%s) registered for "%s" via the website', $data['name'], $data['email'], $event['title']));
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
        '12th_marksheet' => ['label' => 'Class 12 / Diploma Marksheet', 'hint' => 'Latest marksheet (or admit card if results are awaited).', 'when' => 'always', 'image' => false, 'max' => $l['document']],
        'graduation' => ['label' => 'Graduation Marksheet / Degree', 'hint' => 'Required for postgraduate programs. Final-year students may upload the latest semester marksheet.', 'when' => 'pg', 'image' => false, 'max' => $l['document']],
        'id_proof' => ['label' => 'ID Proof (Aadhaar / Passport)', 'hint' => 'Aadhaar card, passport or any government photo ID.', 'when' => 'always', 'image' => false, 'max' => $l['document']],
        'caste_certificate' => ['label' => 'Category Certificate', 'hint' => 'Only if you claim a reserved category (OBC / SC / ST / EWS). You can also submit it at verification.', 'when' => 'category', 'image' => false, 'max' => $l['document']],
    ];
}

function pf_doc_required(array $spec, ?array $program): bool
{
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
        'state' => ['required|max:80', 'State'],
        'pincode' => ['required|max:12', 'PIN code'],
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
    $data['email'] = $data['email'] !== null ? strtolower($data['email']) : null;
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
    foreach (['whatsapp', 'father_phone', 'mother_phone', 'guardian_phone'] as $ph) {
        pf_check_phone($errors, $ph, $data[$ph], false);
    }
    $country = $data['country'] ?? 'India';
    if ($data['pincode'] !== null && !isset($errors['pincode']) && strcasecmp($country, 'India') === 0 && !preg_match('/^\d{6}$/', $data['pincode'])) {
        $errors['pincode'] = 'Enter a valid 6-digit PIN code.';
    }

    // Parents
    pf_check_name($errors, 'father_name', $data['father_name'], "Father's name");
    pf_check_name($errors, 'mother_name', $data['mother_name'], "Mother's name");
    pf_check_name($errors, 'guardian_name', $data['guardian_name'], 'Guardian name');
    if ($data['guardian_name'] !== null && $data['guardian_phone'] === null && !isset($errors['guardian_phone'])) {
        $errors['guardian_phone'] = "Enter the guardian's mobile number.";
    }

    // Academic history
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
                'previous_percentage' => round((float) $data['previous_percentage'], 2), 'passing_year' => (int) $data['passing_year'], 'entrance_exam' => $data['entrance_exam'],
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
