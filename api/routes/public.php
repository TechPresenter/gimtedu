<?php
/**
 * Public website API (no login) — /api/public/*
 *
 *   GET  /api/public/programs            active website programs for selects {value, label, short, level, slug, category}
 *   GET  /api/public/csrf                {token} — fresh CSRF token for a page that has been open a long time
 *   POST /api/public/contact             name, email, phone, enquiry_type, message, consent [, subject]   -> contact_messages
 *   POST /api/public/enquiry             name, phone [, email, program_id | program, city, message, source, form, utm_*] -> enquiries
 *   POST /api/public/newsletter          email [, name, source]                                           -> newsletter_subscribers
 *   POST /api/public/apply               multipart online application (fields + doc_<type> files)         -> admissions (+ documents)
 *   POST /api/public/event-registration  event_id, name, email [, phone, organization]                    -> event_registrations
 *   POST /api/public/alumni-register     name, email, phone, batch_year, program_id, employment_status, city, consent
 *                                        [, company, designation, country, linkedin_url, mentor]       -> alumni (unverified)
 *                                        (logic: includes/partials/public_career.php → pc_submit_alumni_registration())
 *
 * Every POST: CSRF (X-CSRF-Token header from <meta name="csrf-token"> or the _csrf field — checked in pf_api() so a
 * plain form post can get a page instead of JSON), honeypot field "website" must be empty, per-IP rate limit,
 * server-side validation (422 + field errors), JSON envelope (api_ok / api_error), staff notification + activity log,
 * acknowledgement email when a template exists.
 * Plain (no-JS) form posts get a 303 redirect back to the page with the result in the session (pf_take_flash()) when
 * that page shows it inline (pf_flash_pages()), else a small standalone result page (pf_result_page()).
 * Business logic: app/services/public_forms.php.
 */
require_once APP_ROOT . '/app/services/public_forms.php';

/**
 * Run a public form handler with request checks, spam protection and the right response type.
 * @param callable $handler fn(array $input): array{message: string, data?: mixed}
 */
function pf_api(string $form, callable $handler, int $max, int $window, string $anchor = ''): void
{
    $html = pf_wants_html();
    $back = pf_back_url('/', $anchor);
    $backPath = (string) parse_url($back, PHP_URL_PATH);
    $inline = in_array(rtrim($backPath, '/') ?: '/', array_map(fn ($p) => base_url(ltrim($p, '/')), pf_flash_pages($form)), true);
    $fail = function (string $message, int $status, array $errors = []) use ($form, $html, $back, $inline, $window): void {
        if ($html) {
            if ($inline) {
                pf_flash($form, false, $message, $errors);
                redirect($back, 303);
            }
            pf_result_page(false, $message, $errors, $back, $status);
        }
        if ($status === 429) {
            header('Retry-After: ' . (int) ($GLOBALS['__pf_retry_after'] ?? $window));
        }
        api_error($message, $status, $errors);
    };

    if (pf_post_too_large()) {
        $fail('Your files are too large to upload together (maximum ' . human_filesize(pf_upload_limits()['total']) . '). Please use smaller files and try again.', 413);
        return;
    }
    if (!csrf_valid()) {
        $fail('Your session token has expired. Please refresh the page and try again.', 419);
        return;
    }
    $in = api_input();
    try {
        pf_guard($form, $in, $max, $window);
        $res = $handler($in);
    } catch (PublicFormException $e) {
        $fail($e->getMessage(), $e->status, $e->errors);
        return;
    }
    if ($html) {
        if ($inline) {
            pf_flash($form, true, $res['message']);
            redirect($back, 303);
        }
        pf_result_page(true, $res['message'], [], $back);
    }
    api_ok($res['data'] ?? ['id' => $res['id'] ?? null], $res['message']);
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
    pf_api('alumni', fn (array $in) => pc_submit_alumni_registration($in), 5, 600, '#join');
}, $pfOpts);
