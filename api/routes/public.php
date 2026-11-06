<?php
/**
 * Public website API (no login) — /api/public/*
 *
 *   GET  /api/public/programs            active website programs for selects {value, label, short, level, slug, category}
 *   GET  /api/public/csrf                {token} — fresh CSRF token for a page that has been open a long time
 *   GET  /api/public/form-token          {token, csrf, captcha: {provider, siteKey} | null, minSeconds} — one-time form
 *                                        token "_ft" (+ captcha config) for islands that did not get one from the page
 *   POST /api/public/contact             name, email, phone, enquiry_type, message, consent [, subject]   -> contact_messages
 *   POST /api/public/enquiry             name, phone [, email, program_id | program, city, state, country, message, source,
 *                                        form, utm_*] -> enquiries (form=faq-question requires the message)
 *   POST /api/public/newsletter          email [, name, source]                                           -> newsletter_subscribers
 *   POST /api/public/apply               multipart online application (fields + doc_<type> files)         -> admissions (+ documents)
 *   POST /api/public/event-registration  event_id, name, email [, phone, organization]                    -> event_registrations
 *   POST /api/public/alumni-register     name, email, phone, batch_year, program_id, employment_status, city, consent
 *                                        [, company, designation, country, linkedin_url, mentor]       -> alumni (unverified)
 *                                        (logic: includes/partials/public_career.php → pc_submit_alumni_registration())
 *
 * Every POST: CSRF (X-CSRF-Token header from <meta name="csrf-token"> or the _csrf field — checked in pf_api() so a
 * plain form post can get a page instead of JSON), honeypot field "website" must be empty, per-IP rate limit, one-time
 * form token "_ft" (timing check, duplicate submissions answered with the first result), captcha when configured,
 * server-side validation (422 + field errors), phone numbers stored as E.164 ("phone" + optional "phone_country"),
 * normalised emails, dedupe windows, JSON envelope (api_ok / api_error), staff notification + activity log,
 * acknowledgement email when a template exists.
 * Plain (no-JS) form posts get a 303 redirect back to the page with the result in the session (pf_take_flash()) when
 * that page shows it inline (forms from enquiry_form() send "_fid" + "_back"; pages in pf_flash_pages()), else a small
 * standalone result page (pf_result_page()).
 * Business logic: app/services/public_forms.php (+ app/services/phone.php).
 */
require_once APP_ROOT . '/app/services/public_forms.php';

/**
 * Run a public form handler with request checks, spam / duplicate protection and the right response type.
 * @param callable $handler fn(array $input): array{message: string, data?: mixed, id?: int, duplicate?: bool}
 */
function pf_api(string $form, callable $handler, int $max, int $window, string $anchor = ''): void
{
    $html = pf_wants_html();
    $raw = pf_post_too_large() ? [] : api_input();
    $in = pf_prepare_input($raw);
    $fid = is_string($in['_fid'] ?? null) && preg_match('/^[a-z0-9-]{4,40}$/', $in['_fid']) ? $in['_fid'] : null;
    $back = pf_back_url('/', $anchor, is_string($in['_back'] ?? null) ? $in['_back'] : null);
    $backPath = (string) parse_url($back, PHP_URL_PATH);
    $inline = $fid !== null || pf_flash_page_match($form, $backPath);
    $fail = function (string $message, int $status, array $errors = []) use ($form, $html, $back, $inline, $window, $fid, $raw): void {
        if ($html) {
            unset($errors['_ft']);
            if ($inline) {
                // refill the form with what the visitor typed (not the E.164 conversion: the country select is kept too)
                pf_flash($form, false, $message, $errors, $fid, $raw);
                redirect($back, 303);
            }
            pf_result_page(false, $message, $errors, $back, $status);
        }
        if ($status === 429) {
            header('Retry-After: ' . (int) ($GLOBALS['__pf_retry_after'] ?? $window));
        }
        api_error($message, $status, $errors);
    };
    $succeed = function (array $res) use ($form, $html, $back, $inline, $fid): void {
        if ($html) {
            if ($inline) {
                pf_flash($form, true, (string) $res['message'], [], $fid);
                redirect($back, 303);
            }
            pf_result_page(true, (string) $res['message'], [], $back);
        }
        $data = $res['data'] ?? ['id' => $res['id'] ?? null];
        if (!empty($res['duplicate']) && is_array($data)) {
            $data['duplicate'] = true;
        }
        api_ok($data, (string) $res['message']);
    };

    if (pf_post_too_large()) {
        $fail('Your files are too large to upload together (maximum ' . human_filesize(pf_upload_limits()['total']) . '). Please use smaller files and try again.', 413);
        return;
    }
    if (!csrf_valid()) {
        $fail('Your session token has expired. Please refresh the page and try again.', 419);
        return;
    }
    try {
        // Same one-time token submitted again (double click, refresh, network retry): answer with the first result.
        if ($replay = pf_token_replay($in)) {
            $succeed(['message' => $replay['message'], 'data' => $replay['data'], 'id' => $replay['id'], 'duplicate' => true]);
            return;
        }
        pf_guard($form, $in, $max, $window);
        $res = $handler($in);
    } catch (PublicFormException $e) {
        $fail($e->getMessage(), $e->status, $e->errors);
        return;
    }
    pf_token_consume($in, $res);
    $succeed($res);
}

/**
 * "Join the alumni network" wrapper around pc_submit_alumni_registration() (includes/partials/public_career.php):
 * canonical country name (selector sends ISO2 "country_code" + name), normalised email / E.164 phone, and a 10-minute
 * dedupe window (same email or phone -> the same reply, no second coordinator notification).
 */
function pf_alumni_register(array $in): array
{
    $in['email'] = pf_email($in['email'] ?? null) ?? '';
    $country = address_country(($in['country_code'] ?? '') ?: ($in['country'] ?? null));
    if ($country) {
        $in['country'] = $country['name'];
    }
    $phone = phone_parse(is_string($in['phone'] ?? null) ? $in['phone'] : '', $country['iso'] ?? null);
    if ($phone['valid']) {
        $in['phone'] = $phone['e164'];
    }
    $keys = [pf_dedupe_key('alumni', $in['email']), pf_dedupe_key('alumni', $phone['valid'] ? admission_phone_key($phone['e164']) : null)];
    if ((pf_dedupe_hit($keys[0]) || pf_dedupe_hit($keys[1])) && $in['email'] !== '') {
        $first = pf_first_name(pf_text($in['name'] ?? null));
        return ['id' => null, 'duplicate' => true, 'data' => ['received' => true],
            'message' => 'Thank you' . ($first !== '' ? ', ' . $first : '') . '! We already received your alumni registration a few minutes ago. Our alumni coordinator will verify your details and get in touch.'];
    }
    $res = pc_submit_alumni_registration($in);
    pf_dedupe_mark($keys[0]);
    pf_dedupe_mark($keys[1]);
    return $res;
}

// CSRF is verified inside pf_api() ('csrf' => false only skips the router's JSON-only check).
$pfOpts = ['auth' => false, 'csrf' => false];

route('GET', '/public/programs', function () {
    api_ok(pf_program_options());
}, ['auth' => false]);

// Fresh CSRF token for long-lived forms (the ApplyWizard retries once with it after a 419).
route('GET', '/public/csrf', function () {
    api_ok(['token' => csrf_token()]);
}, ['auth' => false]);

// One-time form token + captcha config for islands rendered without one (and after "submit another").
route('GET', '/public/form-token', function () {
    header('Cache-Control: no-store, private');
    api_ok(['token' => pf_form_token(), 'csrf' => csrf_token(), 'captcha' => pf_captcha_public(), 'minSeconds' => PF_MIN_FILL_SECONDS]);
}, ['auth' => false]);

route('POST', '/public/contact', function () {
    pf_api('contact', fn (array $in) => pf_submit_contact($in), 6, 600, '#message');
}, $pfOpts);

route('POST', '/public/enquiry', function () {
    pf_api('enquiry', fn (array $in) => pf_submit_enquiry($in), 6, 600, '#enquiry');
}, $pfOpts);

route('POST', '/public/newsletter', function () {
    pf_api('newsletter', fn (array $in) => pf_submit_newsletter($in), 8, 600);
}, $pfOpts);

route('POST', '/public/event-registration', function () {
    pf_api('event', fn (array $in) => pf_submit_event_registration($in), 10, 600, '#register');
}, $pfOpts);

route('POST', '/public/apply', function () {
    pf_api('apply', fn (array $in) => pf_submit_application($in, $_FILES), 20, 3600, '#application');
}, $pfOpts);

// "Join the alumni network" (/alumni#join) → unverified alumni profile + alumni coordinator notification.
route('POST', '/public/alumni-register', function () {
    require_once APP_ROOT . '/includes/partials/public_career.php';
    pf_api('alumni', fn (array $in) => pf_alumni_register($in), 5, 600, '#join');
}, $pfOpts);
