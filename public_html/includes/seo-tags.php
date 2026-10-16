<?php
/**
 * SEO package WP-5: search-engine verification tags, Google Analytics 4 / Google Tag Manager behind the website's single
 * cookie notice (Consent Mode v2, basic implementation) and privacy-safe dataLayer events.
 *
 *  - seo_verification_tags()      <meta> tags for Google, Bing (msvalidate.01) and, only when set, Yandex / Pinterest.
 *  - seo_tag_mode() / seo_tag_id() which Google tag is configured: 'gtag' (GA4) or 'gtm' - never both - or 'off'.
 *  - seo_queue_event()            called at the end of track_conversion(): stores at most 10 small events (enumerations
 *                                 only - never names, emails, phone numbers, reference / receipt / donation numbers, free text)
 *                                 in $_SESSION['_seo_dl'].
 *  - render_seo_footer()          footer hook: prints two JSON data blocks (type application/json, never executed, so the
 *                                 CSP allows them) - #pwf-tags (configuration) and #pwf-dl (queued events) - and adds
 *                                 assets/js/modules/consent-tags.js. On token, status and account pages and for signed-in
 *                                 staff it prints only {"off":1}: no Google tag is loaded there at all, queued events wait
 *                                 for the next eligible page.
 *  - consent-tags.js              loads gtag.js / gtm.js only after "Allow analytics" (shared localStorage key pwf_consent),
 *                                 with consent default denied for every storage type first and analytics_storage granted
 *                                 by that choice; advertising storage is always denied. page_location / page_referrer are
 *                                 cut down to origin + path + allow-listed parameters.
 *  - seo_csp_google_hosts()       true when the CSP must allow the Google hosts (seo_csp_google_sources() lists them).
 *
 * Hooks (lead / WP-0, all guarded by function_exists): functions.php require + CSP, cms.php track_conversion(),
 * footer.php hook list, script.js loadGa delegation, widgets.php ga_enabled() for a GTM-only setup.
 * Migrations 840-849 (this file).
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** Query parameters that may stay in page_location / page_referrer (values are checked too). */
const SEO_TAGS_URL_PARAMS = ['page', 'category', 'type', 'when', 'utm_source', 'utm_medium', 'utm_campaign'];

/** Templates on which no Google tag is ever loaded (tokens, payment status, account and private pages). */
const SEO_TAGS_BLOCKED_TEMPLATES = [
    'donation_success', 'donation_failed', 'donation_receipt', 'reset_password', 'verify_email', 'forgot_password',
    'login', 'logout', 'member', 'application_status', 'verify_letter',
];

/** A request carrying one of these query parameters is treated as a token / status page: no Google tag. */
const SEO_TAGS_BLOCKED_PARAMS = [
    'token', 't', 'r', 'ref', 'code', 'key', 'sig', 'signature', 'hash', 'email', 'otp', 'unsubscribe', 'unsubscribed',
    'confirm', 'verify', 'reset', 'cf_order', 'order_id', 'payment_id', 'pay', 'preview',
];

/** Client-side events allowed through data-dl-event="..." attributes (consent-tags.js drops anything else). */
const SEO_DL_CLIENT_EVENTS = [
    'cta_click', 'donate_click', 'donation_checkout_open', 'begin_checkout', 'share', 'contact_click', 'program_click', 'event_click',
];

/** data-dl-<key> attributes copied into a client event (values: short lower-case slugs only). */
const SEO_DL_CLIENT_PARAMS = ['cta', 'location', 'gateway', 'method', 'form_type', 'category', 'event_type', 'content_type', 'item_id', 'link_type'];

const SEO_TAGS_GA_RE = '/^G-[A-Z0-9]{4,15}$/';
const SEO_TAGS_GTM_RE = '/^GTM-[A-Z0-9]{4,12}$/';

// =====================================================================
// Settings
// =====================================================================

/**
 * The settings of this package for the admin "Integrations" tab (WP-4): key => [type, label, regex, help, default].
 * Types: text | select (with 'options') | checkbox.
 */
function seo_tags_settings_schema(): array
{
    return [
        'google_site_verification' => ['type' => 'text', 'label' => 'Google Search Console verification code', 'regex' => '/^[A-Za-z0-9_\-]{10,100}$/',
            'help' => 'Paste the content value of the HTML tag, or the whole <meta name="google-site-verification" ...> tag. Not needed if you verified a Domain property through DNS (recommended).', 'default' => ''],
        'bing_site_verification' => ['type' => 'text', 'label' => 'Bing Webmaster Tools code (msvalidate.01)', 'regex' => '/^[A-Za-z0-9]{16,64}$/',
            'help' => 'Paste the content value of the <meta name="msvalidate.01" ...> tag, or the whole tag. Easier: in Bing Webmaster Tools choose "Import from Google Search Console" - then no code is needed.', 'default' => ''],
        'yandex_verification' => ['type' => 'text', 'label' => 'Yandex verification code (optional)', 'regex' => '/^[A-Za-z0-9]{8,64}$/',
            'help' => 'Only if you use Yandex Webmaster: the content value of <meta name="yandex-verification" ...>. Printed only when set.', 'default' => ''],
        'pinterest_verification' => ['type' => 'text', 'label' => 'Pinterest domain verification (optional)', 'regex' => '/^[A-Za-z0-9]{16,64}$/',
            'help' => 'Only if you claim the website on Pinterest: the content value of <meta name="p:domain_verify" ...>. Printed only when set.', 'default' => ''],
        'analytics_tag_mode' => ['type' => 'select', 'label' => 'Google tag to load', 'regex' => '/^(gtag|gtm)$/',
            'options' => ['gtag' => 'Google Analytics 4 (gtag.js)', 'gtm' => 'Google Tag Manager'],
            'help' => 'Only one is ever loaded (no double counting). If the chosen one has no valid ID but the other has, the other is used. Both load only after the visitor chooses "Allow analytics" in the cookie notice.', 'default' => 'gtag'],
        'ga_measurement_id' => ['type' => 'text', 'label' => 'Google Analytics 4 measurement ID', 'regex' => SEO_TAGS_GA_RE,
            'help' => 'Format G-XXXXXXXXXX (Google Analytics: Admin > Data streams > Web). Used when the tag mode is "Google Analytics 4".', 'default' => ''],
        'gtm_container_id' => ['type' => 'text', 'label' => 'Google Tag Manager container ID', 'regex' => SEO_TAGS_GTM_RE,
            'help' => 'Format GTM-XXXXXXX. Limits under this website\'s security policy (CSP): Custom HTML tags and Custom JavaScript variables will not run; the Google tag / GA4 and template-based tags work; Tag Assistant preview mode is blocked. In the container, set the Google tag\'s page_location and page_referrer fields to the Data Layer Variables page_location / page_referrer (the website pushes cleaned values without private tokens). Add no advertising tags: advertising storage is always denied.', 'default' => ''],
        'dl_include_donation_value' => ['type' => 'checkbox', 'label' => 'Send donation amounts to Google Analytics', 'regex' => '/^[01]$/',
            'help' => 'Off by default. When on, begin_checkout / purchase events carry the amount (INR). Names, emails, phone numbers, donation / receipt numbers and payment IDs are never sent.', 'default' => '0'],
        'ga_respect_gpc' => ['type' => 'checkbox', 'label' => 'Treat Global Privacy Control as "No"', 'regex' => '/^[01]$/',
            'help' => 'When a browser sends Global Privacy Control and the visitor has made no choice, Google tags stay off and the cookie notice is not opened automatically (the "Cookie settings" link still works). An explicit "Allow analytics" still counts.', 'default' => '1'],
    ];
}

/**
 * Normalise and validate one submitted value: [clean value, null] or [null, error message].
 * Text keys accept '' (removes the value). Pasted <meta ... content="..."> tags are reduced to the content value.
 */
function seo_tags_clean_setting(string $key, string $value): array
{
    $schema = seo_tags_settings_schema();
    if (!isset($schema[$key])) {
        return [null, 'Unknown setting.'];
    }
    $def = $schema[$key];
    $v = trim($value);
    if ($def['type'] === 'checkbox') {
        $v = in_array($v, ['1', 'on', 'yes', 'true'], true) ? '1' : '0';
    } elseif ($def['type'] === 'select') {
        $v = strtolower($v);
    } else {
        if (preg_match('/content\s*=\s*["\']([^"\']*)["\']/i', $v, $m)) {
            $v = trim($m[1]);
        }
        if (in_array($key, ['ga_measurement_id', 'gtm_container_id'], true)) {
            $v = strtoupper($v);
        }
        if ($v === '') {
            return ['', null];
        }
    }
    if (!preg_match($def['regex'], $v)) {
        $examples = [
            'ga_measurement_id' => 'a GA4 measurement ID looks like G-ABC123XYZ9.',
            'gtm_container_id' => 'a container ID looks like GTM-ABC1234.',
            'analytics_tag_mode' => 'please choose a valid option.',
        ];
        return [null, $def['label'] . ': ' . ($examples[$key] ?? 'please paste the code exactly as shown by the service (letters and numbers only).')];
    }
    return [$v, null];
}

/** A setting of this package, re-validated on read ('' when missing or invalid). */
function seo_tags_setting(string $key): string
{
    try {
        $raw = setting($key);
    } catch (Throwable $e) {
        return '';
    }
    if ($raw === '') {
        return '';
    }
    [$clean] = seo_tags_clean_setting($key, $raw);
    return $clean ?? '';
}

// =====================================================================
// Verification tags and tag mode
// =====================================================================

/** <meta> verification tags for the <head> (called by seo_head_tags(), WP-1). Each tag only when its code is set. */
function seo_verification_tags(): string
{
    $out = '';
    foreach ([
        'google_site_verification' => 'google-site-verification',
        'bing_site_verification' => 'msvalidate.01',
        'yandex_verification' => 'yandex-verification',
        'pinterest_verification' => 'p:domain_verify',
    ] as $key => $name) {
        $v = seo_tags_setting($key);
        if ($v !== '') {
            $out .= '<meta name="' . $name . '" content="' . e($v) . '">' . "\n";
        }
    }
    return $out;
}

/** 'gtag' (GA4), 'gtm' (Tag Manager) or 'off'. The preferred mode is used when its ID is valid, else the other one. */
function seo_tag_mode(): string
{
    if (defined('PWF_INSTALLER')) {
        return 'off';
    }
    $ga = seo_tags_setting('ga_measurement_id');
    $gtm = seo_tags_setting('gtm_container_id');
    $preferred = seo_tags_setting('analytics_tag_mode') === 'gtm' ? 'gtm' : 'gtag';
    $ids = ['gtag' => $ga, 'gtm' => $gtm];
    if ($ids[$preferred] !== '') {
        return $preferred;
    }
    $other = $preferred === 'gtm' ? 'gtag' : 'gtm';
    return $ids[$other] !== '' ? $other : 'off';
}

/** The ID of the Google tag that is loaded ('' when off). */
function seo_tag_id(): string
{
    $mode = seo_tag_mode();
    if ($mode === 'gtag') {
        return seo_tags_setting('ga_measurement_id');
    }
    return $mode === 'gtm' ? seo_tags_setting('gtm_container_id') : '';
}

function seo_tags_enabled(): bool
{
    return seo_tag_mode() !== 'off';
}

/** For the CSP in functions.php (WP-0): add the Google hosts when GA4 or GTM is configured (whatever the page). */
function seo_csp_google_hosts(): bool
{
    try {
        return seo_tags_enabled();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * CSP sources to append when seo_csp_google_hosts() is true (Google's published CSP guidance for the Google tag / GA4;
 * images are already covered by img-src https:). Each value starts with a space.
 */
function seo_csp_google_sources(): array
{
    return [
        'script-src' => ' https://*.googletagmanager.com',
        'connect-src' => ' https://*.google-analytics.com https://*.analytics.google.com https://*.googletagmanager.com',
    ];
}

// =====================================================================
// Where Google tags may load
// =====================================================================

/** Why Google tags must not load on this request ('' = they may load after consent). */
function seo_tags_blocked_reason(): string
{
    $page = function_exists('current_page_row') ? current_page_row() : ($GLOBALS['route']['page'] ?? null);
    $template = is_array($page) ? (string) ($page['template'] ?? '') : '';
    if ($template !== '' && in_array($template, SEO_TAGS_BLOCKED_TEMPLATES, true)) {
        return 'template';
    }
    foreach (array_keys($_GET) as $name) {
        if (in_array(strtolower((string) $name), SEO_TAGS_BLOCKED_PARAMS, true)) {
            return 'token';
        }
    }
    $path = function_exists('route_path') ? route_path() : trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
    // private event pages: <event>/join/ (meeting details) and <event>/confirmation/
    if (preg_match('#(^|/)(join|confirmation)$#', $path)) {
        return 'private';
    }
    if (preg_match('#^(admin|member|api)(/|$)#', $path)) {
        return 'private';
    }
    if (seo_tags_staff_request()) {
        return 'staff';
    }
    return '';
}

/** Staff are never measured (like the first-party statistics), and admin-side conversions are not theirs. */
function seo_tags_staff_request(): bool
{
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if (strpos($script, '/admin/') !== false) {
        return true;
    }
    try {
        return function_exists('is_staff') && function_exists('current_user_id') && current_user_id() && is_staff();
    } catch (Throwable $e) {
        return false;
    }
}

// =====================================================================
// dataLayer events
// =====================================================================

/**
 * track_conversion() type => dataLayer event (CRITIC C8: contact_enquiry carries the enquiry type, program_interest is mapped).
 * 'params' are fixed enumerations; 'lookup' adds enumerations read from the referenced row.
 */
function seo_dl_map(): array
{
    $lead = function (string $formType, string $lookup = ''): array {
        return ['event' => 'generate_lead', 'params' => ['form_type' => $formType], 'lookup' => $lookup];
    };
    return [
        'volunteer_application' => $lead('volunteer_application'),
        'teacher_application' => $lead('teacher_application'),
        'staff_application' => $lead('staff_application'),
        'internship_application' => $lead('internship_application'),
        'membership_application' => $lead('membership_application'),
        'scholarship_application' => $lead('scholarship_application'),
        'help_request' => $lead('help_request'),
        'fundraiser_proposal' => $lead('fundraiser_proposal'),
        'contact_enquiry' => $lead('contact_enquiry', 'enquiry'),          // enquiry_type: contact|grievance|partnership|media|fraud_report
        'partnership_enquiry' => $lead('partnership_enquiry', 'enquiry'),  // not emitted today (W2 uses contact_enquiry + type)
        'program_interest' => $lead('program_interest', 'interest'),
        'registration' => ['event' => 'sign_up', 'params' => ['method' => 'email'], 'lookup' => ''],
        'newsletter_signup' => ['event' => 'newsletter_signup', 'params' => [], 'lookup' => 'newsletter'],
        'event_registration' => ['event' => 'event_registration', 'params' => [], 'lookup' => 'event_registration'],
        'donation_started' => ['event' => 'begin_checkout', 'params' => ['currency' => 'INR'], 'lookup' => 'donation'],
        'donation_success' => ['event' => 'purchase', 'params' => ['currency' => 'INR'], 'lookup' => 'donation'],
        'donation_failed' => ['event' => 'donation_failed', 'params' => [], 'lookup' => 'donation'],   // WP-7b: failed page
    ];
}

/** A dataLayer parameter value: a short lower-case slug without '@' or long digit runs ('' = drop it). */
function seo_dl_clean_value($v): string
{
    if (!is_scalar($v)) {
        return '';
    }
    $v = strtolower(trim((string) $v));
    if ($v === '' || strlen($v) > 60 || !preg_match('/^[a-z0-9][a-z0-9_.\-]*$/', $v) || preg_match('/\d{5,}/', $v)) {
        return '';
    }
    return $v;
}

/** Enumerations from the row a conversion refers to (never personal data). */
function seo_dl_lookup(string $lookup, ?int $referenceId, ?float $value): array
{
    $p = [];
    try {
        if ($lookup === 'newsletter') {
            $src = isset($_POST['source']) && is_string($_POST['source']) ? $_POST['source'] : '';
            $page = function_exists('current_page_row') ? current_page_row() : null;
            if (is_array($page) && ($page['template'] ?? '') === 'member') {
                $src = 'dashboard';
            }
            $p['source'] = seo_dl_clean_value($src) ?: 'website';
            return $p;
        }
        if (!$referenceId) {
            return $p;
        }
        if ($lookup === 'enquiry') {
            $row = db_row('SELECT type, partner_kind, category_id FROM contact_enquiries WHERE id = ?', [$referenceId]);
            if ($row) {
                $p['enquiry_type'] = seo_dl_clean_value($row['type'] ?? '');
                $kinds = defined('PARTNERSHIP_KINDS') ? array_keys(PARTNERSHIP_KINDS) : [];
                if (!empty($row['partner_kind']) && in_array($row['partner_kind'], $kinds, true)) {
                    $p['partner_kind'] = (string) $row['partner_kind'];
                }
                if (!empty($row['category_id'])) {
                    $p['category'] = seo_dl_clean_value(db_value('SELECT slug FROM program_categories WHERE id = ?', [(int) $row['category_id']]));
                }
            }
        } elseif ($lookup === 'interest') {
            $p['category'] = seo_dl_clean_value(db_value('SELECT c.slug FROM program_interests i JOIN program_categories c ON c.id = i.category_id WHERE i.id = ?', [$referenceId]));
        } elseif ($lookup === 'event_registration') {
            $row = db_row('SELECT e.event_type, e.category_id FROM event_registrations r JOIN events e ON e.id = r.event_id WHERE r.id = ?', [$referenceId]);
            if ($row) {
                $types = defined('EVENT_TYPES') ? array_keys(EVENT_TYPES) : [];
                if (in_array($row['event_type'] ?? '', $types, true)) {
                    $p['event_type'] = (string) $row['event_type'];
                }
                if (!empty($row['category_id'])) {
                    $p['category'] = seo_dl_clean_value(db_value('SELECT slug FROM program_categories WHERE id = ?', [(int) $row['category_id']]));
                }
            }
        } elseif ($lookup === 'donation') {
            $row = db_row('SELECT gateway, payment_method, amount, campaign_id FROM donations WHERE id = ?', [$referenceId]);
            if ($row) {
                $gw = seo_dl_clean_value($row['gateway'] ?? '');
                $p['gateway'] = in_array($gw, ['razorpay', 'cashfree'], true) ? $gw : (($row['payment_method'] ?? '') === 'online' ? 'online' : 'offline');
                $item = ['item_id' => 'donation', 'item_name' => 'Donation', 'item_category' => !empty($row['campaign_id']) ? 'campaign' : 'general', 'quantity' => 1];
                if (setting('dl_include_donation_value', '0') === '1') {
                    $amount = $value !== null ? $value : (float) ($row['amount'] ?? 0);
                    if ($amount > 0) {
                        $p['value'] = round($amount, 2);
                        $item['price'] = round($amount, 2);
                    }
                }
                $p['items'] = [$item];
            }
        }
    } catch (Throwable $e) {
        error_log('seo_dl_lookup: ' . $e->getMessage());
    }
    return array_filter($p, function ($v) {
        return $v !== '' && $v !== null;
    });
}

/**
 * Queue a dataLayer event for the visitor's next eligible page (hook at the end of track_conversion()).
 * Only with an active visitor session and a configured GA4 / GTM tag; never for staff or admin pages, webhooks or CLI.
 * $referenceId (the conversion's row id) is used only to read enumerations; it is never queued.
 */
function seo_queue_event(string $type, ?float $value = null, ?int $referenceId = null, array $params = []): void
{
    try {
        if (session_status() !== PHP_SESSION_ACTIVE || !seo_tags_enabled() || seo_tags_staff_request()) {
            return;
        }
        $map = seo_dl_map();
        if (!isset($map[$type])) {
            return;
        }
        $def = $map[$type];
        $p = $def['params'];
        foreach ($params as $k => $v) {           // extra enumerations from the caller (e.g. WP-7b: gateway)
            if (is_string($k) && in_array($k, SEO_DL_CLIENT_PARAMS, true) && ($cv = seo_dl_clean_value($v)) !== '') {
                $p[$k] = $cv;
            }
        }
        if ($def['lookup'] !== '') {
            $p = array_merge($p, seo_dl_lookup($def['lookup'], $referenceId, $value));
        }
        $queue = isset($_SESSION['_seo_dl']) && is_array($_SESSION['_seo_dl']) ? $_SESSION['_seo_dl'] : [];
        $queue[] = ['n' => $def['event'], 'p' => $p, 'u' => seo_dl_uid(), 't' => time()];
        $_SESSION['_seo_dl'] = array_slice(seo_dl_fresh($queue), -10);
    } catch (Throwable $e) {
        error_log('seo_queue_event: ' . $e->getMessage());
    }
}

/** Events younger than 30 minutes. */
function seo_dl_fresh(array $queue): array
{
    $min = time() - 1800;
    return array_values(array_filter($queue, function ($ev) use ($min) {
        return is_array($ev) && isset($ev['n'], $ev['t']) && (int) $ev['t'] >= $min;
    }));
}

/** Random letters-only id (de-duplicates a re-sent page; contains no digits, links to nothing). */
function seo_dl_uid(): string
{
    $s = '';
    for ($i = 0; $i < 12; $i++) {
        $s .= chr(97 + random_int(0, 25));
    }
    return $s;
}

/** Take the queued events (and clear the queue). */
function seo_dl_take(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['_seo_dl']) || !is_array($_SESSION['_seo_dl'])) {
        return [];
    }
    $out = [];
    foreach (seo_dl_fresh($_SESSION['_seo_dl']) as $ev) {
        $out[] = ['n' => (string) $ev['n'], 'p' => is_array($ev['p'] ?? null) ? $ev['p'] : [], 'u' => (string) ($ev['u'] ?? '')];
    }
    unset($_SESSION['_seo_dl']);
    return $out;
}

/** JSON for a <script type="application/json"> block: < > & ' " escaped, so it can never close the element. */
function seo_tags_json($data): string
{
    $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    return $json === false ? '{}' : $json;
}

// =====================================================================
// Footer hook
// =====================================================================

/**
 * Footer hook (WP-0 adds it to footer.php next to render_analytics_beacon): the tag configuration and queued events as
 * JSON data blocks + consent-tags.js. Prints {"off":1} only on pages where Google tags must never load (script.js then
 * skips its own loader as well).
 */
function render_seo_footer(): string
{
    try {
        $mode = seo_tag_mode();
        if ($mode === 'off') {
            return '';
        }
        if (seo_tags_blocked_reason() !== '') {
            return '<script type="application/json" id="pwf-tags">' . seo_tags_json(['off' => 1]) . '</script>';
        }
        $cfg = [
            'v' => 1,
            'mode' => $mode,
            'id' => seo_tag_id(),
            'gpc' => setting('ga_respect_gpc', '1') === '1' ? 1 : 0,
            'params' => SEO_TAGS_URL_PARAMS,
            'client' => SEO_DL_CLIENT_EVENTS,
            'ckeys' => SEO_DL_CLIENT_PARAMS,
        ];
        $js = asset('assets/js/modules/consent-tags.js');
        if (!in_array($js, $GLOBALS['extraScripts'] ?? [], true)) {
            $GLOBALS['extraScripts'][] = $js;
        }
        return '<script type="application/json" id="pwf-tags">' . seo_tags_json($cfg) . '</script>'
            . '<script type="application/json" id="pwf-dl">' . seo_tags_json(seo_dl_take()) . '</script>';
    } catch (Throwable $e) {
        error_log('render_seo_footer: ' . $e->getMessage());
        return '';
    }
}

/** Plain-text status rows for the admin Integrations tab (WP-4): [[label, value], ...]. */
function seo_tags_status_lines(): array
{
    $mode = seo_tag_mode();
    $verified = [];
    foreach (['google_site_verification' => 'Google', 'bing_site_verification' => 'Bing', 'yandex_verification' => 'Yandex', 'pinterest_verification' => 'Pinterest'] as $k => $label) {
        if (seo_tags_setting($k) !== '') {
            $verified[] = $label;
        }
    }
    return [
        ['Verification tags printed', $verified ? implode(', ', $verified) : 'None'],
        ['Google tag', $mode === 'off' ? 'Off (no GA4 or GTM ID set)' : ($mode === 'gtm' ? 'Google Tag Manager ' : 'Google Analytics 4 ') . seo_tag_id()],
        ['Loads', $mode === 'off' ? '-' : 'Only after "Allow analytics" in the cookie notice; advertising storage always denied'],
        ['Never loads on', 'donation status and receipt pages, password reset, email verification, login, member area, application status, letter verification, unsubscribe / confirm links, event joining pages, any URL with a token, and for signed-in staff'],
        ['Donation amounts sent', setting('dl_include_donation_value', '0') === '1' ? 'Yes' : 'No'],
    ];
}

// =====================================================================
// Migrations 840-849
// =====================================================================

/** Settings for verification codes, the tag mode and the dataLayer (admin-entered values are never overwritten). */
function migration_840_seo_tags_settings(): void
{
    add_setting_if_missing('bing_site_verification', '');
    add_setting_if_missing('yandex_verification', '');
    add_setting_if_missing('pinterest_verification', '');
    add_setting_if_missing('gtm_container_id', '');
    add_setting_if_missing('analytics_tag_mode', 'gtag');
    add_setting_if_missing('dl_include_donation_value', '0');
    add_setting_if_missing('ga_respect_gpc', '1');
}

/**
 * Privacy policy: Google Analytics / Tag Manager and consent mode (added once; never edits existing sections).
 * The one-time marker keeps a section the owner deleted from coming back when the migration runs again.
 */
function migration_841_seo_consent_mode_privacy(): void
{
    $once = '841_privacy_consent_mode';
    $pageId = (int) db_value("SELECT id FROM pages WHERE slug = 'privacy-policy'");
    if (!$pageId || migration_once_done($once)) {
        return;
    }
    if ((int) db_value("SELECT COUNT(*) FROM page_sections WHERE page_id = ? AND anchor = 'consent-mode'", [$pageId]) > 0) {
        migration_once_mark($once);
        return;
    }
    $body = '<p>The foundation may turn on <strong>Google Analytics</strong> (or load it through <strong>Google Tag Manager</strong>) to understand how the website is used. If it is turned on, it loads <strong>only after you choose "Allow analytics"</strong> in the cookie notice. If you choose "No, thanks", or make no choice, nothing from Google Analytics or Google Tag Manager is loaded. Advertising storage, ad personalisation and sending data for advertising stay off in every case: the foundation does not run advertising through this website.</p>'
        . '<h3>What is sent if you allow it</h3><ul>'
        . '<li>The address of the page you view and of the previous page, <strong>shortened to the page path</strong>: private codes and personal details that can appear in an address (such as payment, email-verification or password-reset codes) are removed before anything is sent. Only a few harmless parts are kept, such as the page number of a list, a category filter and campaign tags (utm_source, utm_medium, utm_campaign).</li>'
        . '<li>That a form was sent (for example a volunteer application, an enquiry, an event registration or a newsletter sign-up), with its type only, and the steps of an online donation. <strong>Never your name, email address, phone number, what you typed, or any donation, receipt or reference number.</strong> Donation amounts are sent only if our administrator turns this on.</li>'
        . '<li>The technical information that Google Analytics itself collects, such as browser and device type and an approximate location derived by Google.</li></ul>'
        . '<h3>Pages where it never loads</h3><p>Google Analytics and Google Tag Manager are not loaded on donation result and receipt pages, password-reset, email-verification and sign-in pages, your member area, application-status and letter-verification pages, unsubscribe links, private event joining pages or any page opened through a personal link.</p>'
        . '<h3>Changing your choice</h3><p>Use the "Cookie settings" link at the bottom of any page. If you change to "No, thanks", Google Analytics cookies (_ga, _ga_*) are removed and it is not loaded again. If your browser sends a Global Privacy Control signal and you have not made a choice, we treat it as "No, thanks".</p>';
    db_insert('page_sections', [
        'page_id' => $pageId, 'section_type' => 'richtext', 'anchor' => 'consent-mode', 'title' => 'Google Analytics and Google Tag Manager (optional)',
        'body' => $body, 'background' => 'white', 'status' => 'visible', 'sort_order' => 63,
    ]);
    migration_once_mark($once);
}
