<?php
/**
 * SEO & tracking platform (unit seo_platform). Shared by includes/header.php + footer.php, sitemap.php, robots.php,
 * 404.php, tools/router.php, api/routes/redirects.php, api/routes/integrations.php and app/modules/redirects.php.
 *
 *  1. Helpers ........ seo_site_path(), seo_abs_url(), seo_encode_path(), seo_image_meta()
 *  2. Integrations ... seo_integration_sections() (admin Settings → Integrations), seo_integration_value() (env GIMT_*
 *                      overrides settings), seo_integration_payload(), seo_integration_save()
 *  3. <head> ......... seo_page_meta() (title, description, canonical, robots, OG/Twitter, hreflang), seo_robots_merge(),
 *                      seo_jsonld_html() (page / admin / site-wide JSON-LD without duplicates), seo_head_extras(),
 *                      seo_verification_meta(), seo_error_reporter_head(), seo_sentry_head()
 *  4. Redirects ...... seo_redirect_normalize(), seo_redirect_match(), seo_redirect_maybe() (rules, lower-case + trailing
 *                      slash 301s, 410, 404 log), seo_path_exists(), seo_redirect_prepare() (validation, loop + chain
 *                      protection), seo_redirect_flatten_incoming(), seo_redirect_trace() (Test a URL), 404 log
 *  5. robots.txt ..... seo_default_robots_txt(), seo_robots_txt(), seo_robots_rules(), seo_robots_allowed()
 *  6. Sitemaps ....... seo_sitemap_groups(), seo_sitemap_index_xml(), seo_sitemap_xml($group), file cache
 *  7. Client errors .. seo_client_error_store(), seo_error_log_list(), seo_error_log_stats()
 *  8. SEO audit ...... seo_audit_start() / step() / finalize() / report() / pages() / export rows; background worker:
 *                      php app/services/seo.php audit-worker <id>   (used on the single-threaded PHP dev server)
 *
 * No side effects when included (functions only), except the CLI entry point at the very end.
 */

if (!defined('APP_ROOT')) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
    require_once dirname(__DIR__) . '/init.php';
}

/* =====================================================================================================================
 * 1. Helpers
 * ===================================================================================================================*/

/** Scheme + host (+ port) of the website, e.g. https://www.gimt.ac.in */
function seo_origin(): string
{
    return (string) preg_replace('#^(https?://[^/]+).*$#i', '$1', absolute_url(''));
}

/** Site URL with the base path and a trailing slash, e.g. https://www.gimt.ac.in/ */
function seo_site_url(): string
{
    return absolute_url('');
}

/** Is the website served over HTTPS? */
function seo_is_https(): bool
{
    return str_starts_with(strtolower(seo_origin()), 'https://');
}

/**
 * Request path relative to the site root, decoded, without the query string: "/programs/mba".
 * (REQUEST_URI is split by hand: parse_url() treats "//x" as a host.)
 */
function seo_site_path(?string $uri = null): string
{
    $uri = $uri ?? (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = explode('#', explode('?', $uri, 2)[0], 2)[0];
    $path = rawurldecode($path === '' ? '/' : $path);
    $base = base_path();
    if ($base !== '' && (strcasecmp($path, $base) === 0 || stripos($path, $base . '/') === 0)) {
        $path = substr($path, strlen($base));
    }
    $path = '/' . ltrim((string) preg_replace('#/{2,}#', '/', $path), '/');
    return $path;
}

/**
 * Query string the visitor sent ('' when none) — read from REQUEST_URI because Apache rewrites (page.php?slug=…)
 * replace QUERY_STRING with the internal one.
 */
function seo_request_query(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $q = str_contains($uri, '?') ? explode('?', $uri, 2)[1] : '';
    return explode('#', $q, 2)[0];
}

/** Percent-encode a decoded path for use in a URL (keeps "/" and characters that are safe in paths). */
function seo_encode_path(string $path): string
{
    $parts = explode('/', $path);
    foreach ($parts as $i => $p) {
        $parts[$i] = str_replace(['%7E', '%40', '%3A', '%2B', '%21', '%24', '%26', '%27', '%28', '%29', '%2A', '%2C', '%3B', '%3D'],
            ['~', '@', ':', '+', '!', '$', '&', "'", '(', ')', '*', ',', ';', '='], rawurlencode($p));
    }
    return implode('/', $parts);
}

/** Absolute URL for a site path relative to the root ("/about?x=1"), encoding the path. */
function seo_path_url(string $pathWithQuery): string
{
    [$p, $q] = array_pad(explode('?', $pathWithQuery, 2), 2, null);
    return absolute_url(ltrim(seo_encode_path($p), '/')) . ($q !== null && $q !== '' ? '?' . $q : '');
}

/**
 * Absolute URL for an image/link value that may be absolute, protocol-relative, root-relative (with or without the
 * base path, possibly with ?v= cache busting) or relative to the site root ("assets/images/x.jpg").
 */
function seo_abs_url(?string $u): ?string
{
    $u = trim((string) $u);
    if ($u === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $u)) {
        return $u;
    }
    if (str_starts_with($u, '//')) {
        return (seo_is_https() ? 'https:' : 'http:') . $u;
    }
    $base = base_path();
    if (str_starts_with($u, '/')) {
        return seo_origin() . ($base !== '' && str_starts_with($u, $base . '/') ? $u : $base . $u);
    }
    return seo_origin() . $base . '/' . $u;
}

/** Local file behind a site URL (only files under assets/), or null. */
function seo_local_file(?string $url): ?string
{
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $url)) {
        if (stripos($url, seo_origin() . '/') !== 0) {
            return null;
        }
        $url = substr($url, strlen(seo_origin()));
    }
    $path = seo_site_path($url);
    if (!str_starts_with($path, '/assets/') || str_contains($path, '..')) {
        return null;
    }
    $file = APP_ROOT . $path;
    return is_file($file) ? $file : null;
}

/** Width/height/MIME of a local image URL (cached per request); [] when unknown. */
function seo_image_meta(?string $url): array
{
    static $cache = [];
    $key = (string) $url;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $file = seo_local_file($url);
    $out = [];
    if ($file && ($size = @getimagesize($file))) {
        $out = ['width' => (int) $size[0], 'height' => (int) $size[1], 'mime' => (string) ($size['mime'] ?? '')];
    } elseif ($file && str_ends_with(strtolower($file), '.svg')) {
        $out = ['mime' => 'image/svg+xml'];
    }
    return $cache[$key] = $out;
}

/** Is this host (optionally host:port) the website itself? */
function seo_is_own_host(string $hostPort): bool
{
    $hostPort = strtolower(trim($hostPort));
    $own = strtolower((string) parse_url(seo_origin(), PHP_URL_HOST)) . (parse_url(seo_origin(), PHP_URL_PORT) ? ':' . parse_url(seo_origin(), PHP_URL_PORT) : '');
    $req = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $strip = fn ($h) => preg_replace('/^www\./', '', (string) $h);
    return $hostPort !== '' && ($strip($hostPort) === $strip($own) || ($req !== '' && $strip($hostPort) === $strip($req)));
}

/** Fixed-window rate limiter (same table as rate_limit() in app/api.php, usable outside the API too). */
function seo_rate_limit(string $key, int $max, int $windowSeconds): bool
{
    if (function_exists('rate_limit')) {
        return rate_limit($key, $max, $windowSeconds);
    }
    $key = substr($key, 0, 190);
    $row = db_row('SELECT hits, reset_at FROM rate_limits WHERE rl_key = ?', [$key]);
    if (!$row || strtotime($row['reset_at']) <= time()) {
        db_exec('REPLACE INTO rate_limits (rl_key, hits, reset_at) VALUES (?, 1, ?)', [$key, date('Y-m-d H:i:s', time() + $windowSeconds)]);
        return true;
    }
    if ((int) $row['hits'] >= $max) {
        return false;
    }
    db_exec('UPDATE rate_limits SET hits = hits + 1 WHERE rl_key = ?', [$key]);
    return true;
}

/** Request made by the built-in SEO audit crawler? (not counted in redirect hits or the 404 log) */
function seo_is_audit_request(): bool
{
    return str_contains((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'GIMT-SEO-Audit');
}

/* =====================================================================================================================
 * 2. Integrations (admin Settings → Integrations) — settings rows + GIMT_* environment overrides
 * ===================================================================================================================*/

/**
 * Integration sections and fields. Field keys: key, label, type (text|password|select|toggle|number|textarea|url),
 * group (settings group the row lives in — existing keys keep theirs), env (environment variable that overrides the
 * stored value), secret (never returned to the browser), rules (validate()), options, default, help, placeholder, col.
 */
function seo_integration_sections(): array
{
    $policy = '/cookie-policy';
    return [
        'captcha' => [
            'title' => 'Spam protection (CAPTCHA)', 'icon' => 'shield-check',
            'description' => 'Protects every public form (contact, enquiry, applications, newsletter, event registration). With “None” the forms rely on a hidden honeypot field, a minimum fill time and per-IP rate limits.',
            'fields' => [
                ['key' => 'captcha_provider', 'label' => 'Provider', 'type' => 'select', 'group' => 'integrations', 'default' => 'none', 'col' => 12,
                    'options' => ['none' => 'None — honeypot, timing check and rate limits only', 'recaptcha_v3' => 'Google reCAPTCHA v3 (invisible, score based)',
                        'turnstile' => 'Cloudflare Turnstile (privacy friendly)', 'hcaptcha' => 'hCaptcha']],
                ['key' => 'captcha_site_key', 'label' => 'Site key (public)', 'group' => 'integrations', 'env' => 'GIMT_CAPTCHA_SITE_KEY', 'col' => 6,
                    'rules' => ['max:200', 'regex:/^[A-Za-z0-9_\-.]{10,200}$/'], 'placeholder' => 'Shown in the page source'],
                ['key' => 'captcha_secret_key', 'label' => 'Secret key', 'type' => 'password', 'secret' => true, 'group' => 'integrations', 'env' => 'GIMT_CAPTCHA_SECRET', 'col' => 6,
                    'rules' => ['max:255', 'regex:/^[A-Za-z0-9_\-.]{10,255}$/'], 'help' => 'Server-side only — never sent to the browser.'],
                ['key' => 'captcha_min_score', 'label' => 'Minimum score (reCAPTCHA v3)', 'type' => 'number', 'group' => 'integrations', 'default' => '0.5', 'col' => 6,
                    'rules' => 'numeric|min:0|max:1', 'help' => '0.0 (bot) – 1.0 (human). 0.5 is Google’s recommended starting point.'],
            ],
        ],
        'analytics' => [
            'title' => 'Analytics & tags', 'icon' => 'chart-line',
            'description' => 'Google Analytics 4, Google Tag Manager, Meta Pixel and search engine verification. Tags load only after the visitor’s consent and only when an ID is set.',
            'fields' => [
                ['key' => 'google_analytics_id', 'label' => 'Google Analytics 4 measurement ID', 'group' => 'analytics', 'env' => 'GIMT_GA4_ID', 'col' => 6,
                    'rules' => ['regex:/^(G|UA)-[A-Z0-9\-]{4,20}$/'], 'placeholder' => 'G-XXXXXXXXXX'],
                ['key' => 'gtm_id', 'label' => 'Google Tag Manager container ID', 'group' => 'analytics', 'env' => 'GIMT_GTM_ID', 'col' => 6,
                    'rules' => ['regex:/^GTM-[A-Z0-9]{4,12}$/'], 'placeholder' => 'GTM-XXXXXXX', 'help' => 'Use GA4 here or inside the GTM container — not both, or visits are counted twice.'],
                ['key' => 'meta_pixel_id', 'label' => 'Meta (Facebook) Pixel ID', 'group' => 'analytics', 'env' => 'GIMT_META_PIXEL_ID', 'col' => 6,
                    'rules' => ['regex:/^[0-9]{6,20}$/'], 'help' => 'Loads only with “Marketing” consent.'],
                ['key' => 'consent_mode', 'label' => 'Google Consent Mode v2', 'type' => 'select', 'group' => 'integrations', 'default' => 'basic', 'col' => 6,
                    'options' => ['basic' => 'Basic — Google tags load only after consent', 'advanced' => 'Advanced — tags load with consent denied (cookieless pings) and update on choice'],
                    'help' => 'Defaults are always “denied” until the visitor chooses.'],
                ['key' => 'search_console_verification', 'label' => 'Google Search Console verification', 'group' => 'analytics', 'env' => 'GIMT_GSC_VERIFICATION', 'col' => 6,
                    'rules' => ['regex:/^[A-Za-z0-9_\-]{10,100}$/'], 'help' => 'Content of the google-site-verification meta tag (HTML tag method).'],
                ['key' => 'bing_site_verification', 'label' => 'Bing Webmaster verification', 'group' => 'integrations', 'env' => 'GIMT_BING_VERIFICATION', 'col' => 6,
                    'rules' => ['regex:/^[A-Za-z0-9]{16,64}$/'], 'help' => 'Content of the msvalidate.01 meta tag.'],
            ],
        ],
        'consent' => [
            'title' => 'Cookie consent', 'icon' => 'cookie',
            'description' => 'DPDP / GDPR-friendly banner with Necessary (always on), Analytics and Marketing choices, stored in a first-party cookie. Visitors can change their choice any time from “Cookie settings” in the footer.',
            'fields' => [
                ['key' => 'consent_banner', 'label' => 'Show the consent banner', 'type' => 'select', 'group' => 'integrations', 'default' => 'auto', 'col' => 6,
                    'options' => ['auto' => 'Automatic — only when an analytics or marketing tag is configured', 'always' => 'Always', 'off' => 'Never — consent is handled elsewhere (Google tags stay in “denied” mode, Meta Pixel never loads)']],
                ['key' => 'consent_expiry_days', 'label' => 'Remember the choice for', 'type' => 'number', 'group' => 'integrations', 'default' => '180', 'col' => 6,
                    'rules' => 'integer|min:30|max:395', 'suffix' => 'days', 'help' => 'Maximum 13 months (395 days).'],
                ['key' => 'consent_title', 'label' => 'Banner title', 'group' => 'integrations', 'default' => 'We value your privacy', 'col' => 12, 'rules' => 'max:80'],
                ['key' => 'consent_text', 'label' => 'Banner text', 'type' => 'textarea', 'group' => 'integrations', 'col' => 12, 'rules' => 'max:400',
                    'default' => 'We use necessary cookies to run this website. With your permission we also use analytics cookies to understand how visitors use it and marketing cookies to measure our admission campaigns.'],
                ['key' => 'consent_policy_url', 'label' => 'Cookie policy link', 'group' => 'integrations', 'default' => $policy, 'col' => 6, 'rules' => ['max:255', 'regex:/^(\/[^\s]*|https:\/\/[^\s]+)$/'],
                    'placeholder' => $policy],
            ],
        ],
        'maps' => [
            'title' => 'Google Maps', 'icon' => 'map-pin',
            'description' => 'Campus map on the Contact page. The plain embed URL needs no key; an API key (Maps Embed API) enables place maps by name.',
            'fields' => [
                ['key' => 'map_embed_url', 'label' => 'Google Maps embed URL', 'group' => 'general', 'col' => 12, 'rules' => ['max:500', 'regex:/^https:\/\/(www\.)?google\.[a-z.]+\/maps[^\s]*$/i'],
                    'placeholder' => 'https://www.google.com/maps?q=…&output=embed', 'help' => 'Google Maps → Share → Embed a map → copy the src URL.'],
                ['key' => 'google_maps_api_key', 'label' => 'Maps Embed API key (browser key)', 'group' => 'integrations', 'env' => 'GIMT_GOOGLE_MAPS_API_KEY', 'col' => 6,
                    'rules' => ['regex:/^[A-Za-z0-9_\-]{20,60}$/'], 'help' => 'Restrict it to your domain (HTTP referrers) in Google Cloud Console — browser keys are visible in the page.'],
                ['key' => 'map_place_query', 'label' => 'Place to show (with an API key)', 'group' => 'integrations', 'col' => 6, 'rules' => 'max:190',
                    'placeholder' => 'Global Institute of Management & Technology, Noida'],
            ],
        ],
        'monitoring' => [
            'title' => 'Error monitoring', 'icon' => 'activity',
            'description' => 'JavaScript errors from the public website are logged (rate-limited, same-origin only, no personal data) and listed in the error log. Optionally also send them to Sentry.',
            'fields' => [
                ['key' => 'client_error_logging', 'label' => 'Log website JavaScript errors', 'type' => 'toggle', 'group' => 'integrations', 'default' => '1', 'col' => 12,
                    'help' => 'Tiny reporter (window.onerror / unhandledrejection) on public pages → error log.'],
                ['key' => 'sentry_dsn', 'label' => 'Sentry DSN (optional)', 'group' => 'integrations', 'env' => 'GIMT_SENTRY_DSN', 'col' => 8,
                    'rules' => ['max:255', 'regex:/^https:\/\/[A-Za-z0-9]{16,64}@[A-Za-z0-9.\-]+(:[0-9]+)?\/[0-9]+$/'], 'placeholder' => 'https://<key>@o123.ingest.sentry.io/456',
                    'help' => 'Loads the Sentry browser loader script on public pages.'],
                ['key' => 'sentry_environment', 'label' => 'Sentry environment', 'group' => 'integrations', 'default' => 'production', 'col' => 4, 'rules' => ['max:40', 'regex:/^[a-z0-9_\-]+$/i']],
            ],
        ],
        'whatsapp' => [
            'title' => 'WhatsApp', 'icon' => 'message-circle',
            'description' => 'Click-to-chat number for the floating WhatsApp button, chat tab and footer icon. The WhatsApp Business API gateway (automatic messages) is configured under Email & SMS.',
            'fields' => [
                ['key' => 'whatsapp_number', 'label' => 'WhatsApp number', 'group' => 'website', 'col' => 6, 'rules' => ['regex:/^[0-9]{8,15}$/'],
                    'placeholder' => '919955446477', 'help' => 'Digits only, with the country code (no + or spaces). Leave blank to hide WhatsApp buttons.'],
            ],
        ],
        'search' => [
            'title' => 'Search engines', 'icon' => 'search',
            'description' => 'Sitemaps, robots.txt and indexing for the whole website.',
            'fields' => [
                ['key' => 'seo_block_indexing', 'label' => 'Discourage search engines from indexing this website', 'type' => 'toggle', 'group' => 'seo', 'default' => '0', 'col' => 12, 'danger' => true,
                    'help' => 'For staging / pre-launch copies: robots.txt disallows everything, every page is noindex and the sitemaps are empty.'],
                ['key' => 'sitemap_images', 'label' => 'Publish an image sitemap', 'type' => 'toggle', 'group' => 'seo', 'default' => '1', 'col' => 12,
                    'help' => 'sitemap-images.xml lists the photos of programs, news, events, albums and pages for Google Images.'],
            ],
        ],
    ];
}

/** Flat key => field definition (with 'section'). */
function seo_integration_fields(): array
{
    $out = [];
    foreach (seo_integration_sections() as $s => $def) {
        foreach ($def['fields'] as $f) {
            $out[$f['key']] = $f + ['section' => $s, 'type' => 'text', 'secret' => false];
        }
    }
    return $out;
}

/** Environment override for an integration key (GIMT_*), or null. */
function seo_integration_env(string $key): ?string
{
    $env = seo_integration_fields()[$key]['env'] ?? null;
    if (!$env) {
        return null;
    }
    $v = getenv($env);
    if ($v === false || trim($v) === '') {
        $v = $_SERVER[$env] ?? $_ENV[$env] ?? null;
    }
    return is_string($v) && trim($v) !== '' ? trim($v) : null;
}

/** Effective value of an integration setting: environment variable → settings row → field default → $default. */
function seo_integration_value(string $key, $default = null)
{
    if (($env = seo_integration_env($key)) !== null) {
        return $env;
    }
    $v = setting($key);
    if ($v !== null && $v !== '') {
        return $v;
    }
    return seo_integration_fields()[$key]['default'] ?? $default;
}

/** Admin payload: sections (fields without rules), values (secrets masked), secrets stored flags, env flags, status. */
function seo_integration_payload(): array
{
    $all = settings_all(true);
    $sections = [];
    $values = [];
    $secrets = [];
    $env = [];
    foreach (seo_integration_sections() as $key => $def) {
        $fields = [];
        foreach ($def['fields'] as $f) {
            $k = $f['key'];
            $stored = (string) ($all[$k] ?? '');
            if (!empty($f['secret'])) {
                $secrets[$k] = $stored !== '';
                $values[$k] = '';
            } else {
                $values[$k] = $stored !== '' ? $stored : api_str($f['default'] ?? null);
            }
            if (!empty($f['env'])) {
                $env[$k] = ['name' => $f['env'], 'set' => seo_integration_env($k) !== null];
            }
            $out = array_intersect_key($f, array_flip(['key', 'label', 'type', 'help', 'placeholder', 'col', 'secret', 'suffix', 'prefix', 'danger']));
            $out['type'] = $out['type'] ?? 'text';
            if (isset($f['options'])) {
                $out['options'] = array_map(fn ($v, $l) => ['value' => (string) $v, 'label' => $l], array_keys($f['options']), $f['options']);
            }
            $out['env'] = $f['env'] ?? null;
            $fields[] = $out;
        }
        $sections[] = ['key' => $key, 'title' => $def['title'], 'icon' => $def['icon'], 'description' => $def['description'], 'fields' => $fields];
    }
    return ['sections' => $sections, 'values' => $values, 'secrets' => $secrets, 'env' => $env];
}

/**
 * Validate + save one integration section. $input: key => value (only keys sent are saved; blank secret = keep;
 * "__clear" => [secret keys] clears). Returns saved keys. Throws CrudValidationException with field errors.
 */
function seo_integration_save(string $section, array $input): array
{
    $sections = seo_integration_sections();
    if (!isset($sections[$section])) {
        throw new InvalidArgumentException('Unknown integration section.');
    }
    $clear = array_filter((array) ($input['__clear'] ?? []), 'is_string');
    $save = [];
    $errors = [];
    foreach ($sections[$section]['fields'] as $f) {
        $k = $f['key'];
        $type = $f['type'] ?? 'text';
        if (!empty($f['secret']) && in_array($k, $clear, true)) {
            $save[$k] = '';
            continue;
        }
        if (!array_key_exists($k, $input)) {
            continue;
        }
        $v = $input[$k];
        if (is_array($v)) {
            $errors[$k] = $f['label'] . ' is invalid.';
            continue;
        }
        if ($type === 'toggle') {
            $save[$k] = in_array($v, [true, 1, '1', 'true', 'on', 'yes'], true) ? '1' : '0';
            continue;
        }
        $v = trim((string) $v);
        if (!empty($f['secret']) && $v === '') {
            continue; // keep the stored secret
        }
        if (in_array($k, ['google_analytics_id', 'gtm_id'], true)) {
            $v = strtoupper($v);
        }
        $rules = $f['rules'] ?? [];
        $rules = is_array($rules) ? $rules : array_filter(explode('|', (string) $rules));
        if (isset($f['options'])) {
            $rules[] = 'in:' . implode(',', array_map('strval', array_keys($f['options'])));
        }
        if ($v !== '' && $rules && ($e = validate([$k => $v], [$k => $rules], [$k => $f['label']]))) {
            $errors[$k] = $e[$k];
            continue;
        }
        $save[$k] = $v;
    }
    // Cross-field rules
    if ($section === 'captcha') {
        $provider = $save['captcha_provider'] ?? (string) setting('captcha_provider', 'none');
        if ($provider !== 'none') {
            $site = array_key_exists('captcha_site_key', $save) ? $save['captcha_site_key'] : (string) setting('captcha_site_key', '');
            $secret = array_key_exists('captcha_secret_key', $save) ? $save['captcha_secret_key'] : (string) setting('captcha_secret_key', '');
            if ($site === '' && seo_integration_env('captcha_site_key') === null) {
                $errors['captcha_site_key'] = 'Enter the site key for the selected provider.';
            }
            if ($secret === '' && seo_integration_env('captcha_secret_key') === null) {
                $errors['captcha_secret_key'] = 'Enter the secret key (or set GIMT_CAPTCHA_SECRET on the server).';
            }
        }
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $groups = db_pairs('SELECT `key`, `group` FROM settings WHERE `key` IN (' . implode(',', array_fill(0, max(1, count($save)), '?')) . ')', $save ? array_keys($save) : ['__none__']);
    $fields = seo_integration_fields();
    $changed = [];
    $current = settings_all(true);
    foreach ($save as $k => $v) {
        if ((string) ($current[$k] ?? '') === $v && !(!empty($fields[$k]['secret']) && $v === '' && in_array($k, $clear, true))) {
            continue;
        }
        save_setting($k, $v, $groups[$k] ?? ($fields[$k]['group'] ?? 'integrations'));
        $changed[] = $k;
    }
    if (array_intersect($changed, ['seo_block_indexing', 'sitemap_images'])) {
        seo_sitemap_cache_clear();
    }
    return $changed;
}

/* =====================================================================================================================
 * 3. <head>: titles, canonical, robots, social cards, hreflang, JSON-LD, verification, error reporter
 * ===================================================================================================================*/

/**
 * Combine robots directives — the most restrictive wins (noindex/nofollow from any source). Indexable pages also get
 * max-image-preview:large (large image previews in Google Search / Discover).
 */
function seo_robots_merge(...$values): string
{
    $noindex = false;
    $nofollow = false;
    $extra = [];
    foreach ($values as $v) {
        foreach (preg_split('/\s*,\s*/', strtolower(trim((string) $v))) as $t) {
            if ($t === '') {
                continue;
            }
            if ($t === 'noindex' || $t === 'none') {
                $noindex = true;
            }
            if ($t === 'nofollow' || $t === 'none') {
                $nofollow = true;
            }
            if (preg_match('/^(max-(snippet|image-preview|video-preview):[a-z0-9\-]+|noarchive|nosnippet|noimageindex|notranslate)$/', $t)) {
                $extra[$t] = true;
            }
        }
    }
    $out = [$noindex ? 'noindex' : 'index', $nofollow ? 'nofollow' : 'follow'];
    if (!$noindex && !preg_grep('/^max-image-preview:/', array_keys($extra))) {
        $out[] = 'max-image-preview:large';
    }
    return implode(',', array_merge($out, array_keys($extra)));
}

/** Path for the default canonical URL: /index.php → /, /about.php → /about, no trailing slash. */
function seo_canonical_path(string $path): string
{
    if (preg_match('#^(.*?)/index\.php$#i', $path, $m)) {
        $path = $m[1] === '' ? '/' : $m[1];
    } elseif (preg_match('#^/([a-z0-9\-]+)\.php$#i', $path, $m) && is_file(APP_ROOT . '/' . $m[1] . '.php')) {
        $path = '/' . $m[1];
    }
    if ($path !== '/') {
        $path = rtrim($path, '/') ?: '/';
    }
    return $path;
}

/** Real social profile URLs for sameAs (skips placeholders such as "https://facebook.com/"). */
function seo_same_as(): array
{
    $out = [];
    foreach (social_links() as $url) {
        $p = parse_url((string) $url);
        if (!empty($p['host']) && in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) && trim(api_str($p['path'] ?? null), '/') !== '') {
            $out[] = (string) $url;
        }
    }
    return array_values(array_unique($out));
}

/** "@handle" from the X (Twitter) profile setting, or null. */
function seo_twitter_handle(): ?string
{
    $url = (string) setting('x_url', '');
    if (preg_match('#^https?://(www\.)?(x|twitter)\.com/([A-Za-z0-9_]{1,15})/?$#i', $url, $m)) {
        return '@' . $m[3];
    }
    return null;
}

/**
 * Everything header.php prints in <head> for SEO: title, description, keywords, robots, canonical (null = none),
 * hreflang alternates, Open Graph + Twitter Card values (image with dimensions).
 * $page = site_header() options; $seo = seo_settings row (or null); $status = HTTP status of the response.
 */
function seo_page_meta(array $page, ?array $seo, int $status = 200): array
{
    $nz = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;
    $name = institute_name();
    $short = (string) setting('institute_short_name', 'GIMT');
    $title = $nz($seo['meta_title'] ?? null)
        ?? ($nz($page['title'] ?? null) ? $page['title'] . ' | ' . $short : $name . ' | ' . setting('tagline', 'Education • Innovation • Opportunity'));
    $description = $nz($seo['meta_description'] ?? null) ?? $nz($page['description'] ?? null)
        ?? 'Global Institute of Management & Technology (GIMT), Noida - industry-focused UG, PG, diploma and certificate programs in management, technology, commerce and computer applications.';
    $description = str_limit(trim((string) preg_replace('/\s+/u', ' ', strip_tags($description))), 300);

    $blocked = setting('seo_block_indexing', '0') === '1';
    $robots = seo_robots_merge($seo['robots'] ?? '', $page['robots'] ?? '', $blocked ? 'noindex,nofollow' : '', $status >= 400 ? 'noindex,follow' : '');
    $indexable = !str_contains($robots, 'noindex');

    $path = seo_site_path();
    $canonical = null;
    if (($page['canonical'] ?? null) !== false && $status < 400) {
        // The admin canonical describes the route's base URL; on query variants that the page canonicalises itself
        // (?page=2, ?category=…) the page's own canonical wins, so later pages stay self-canonical.
        $adminCanonical = $nz($seo['canonical_url'] ?? null);
        $pageCanonical = $nz(is_string($page['canonical'] ?? null) ? $page['canonical'] : null);
        $c = $pageCanonical !== null && seo_request_query() !== '' ? $pageCanonical : ($adminCanonical ?? $pageCanonical);
        $canonical = $c !== null ? seo_abs_url($c) : seo_path_url(seo_canonical_path($path));
    }
    $currentUrl = seo_path_url(seo_canonical_path($path));

    $ogImage = $nz($seo['og_image'] ?? null) ?? $nz($page['image'] ?? null) ?? 'assets/images/site/hero-campus-students.jpg';
    $ogImageUrl = (string) preg_replace('/[?&]v=\d+$/', '', (string) seo_abs_url($ogImage)); // no cache-busting suffix in share cards
    $img = seo_image_meta($ogImageUrl);
    $ogTitle = $nz($seo['og_title'] ?? null) ?? $title;
    $ogDesc = str_limit($nz($seo['og_description'] ?? null) ?? $description, 300);

    return [
        'title' => $title,
        'description' => $description,
        'keywords' => $nz($seo['meta_keywords'] ?? null),
        'robots' => $robots,
        'indexable' => $indexable,
        'canonical' => $canonical,
        'hreflang' => $canonical && $indexable && setting('seo_hreflang', '1') !== '0' ? ['en-IN' => $canonical, 'x-default' => $canonical] : [],
        'og' => array_filter([
            'og:type' => $page['og_type'] ?? 'website',
            'og:site_name' => $name,
            'og:locale' => 'en_IN',
            'og:title' => $ogTitle,
            'og:description' => $ogDesc,
            'og:url' => $canonical ?? $currentUrl,
            'og:image' => $ogImageUrl,
            'og:image:secure_url' => $ogImageUrl && str_starts_with($ogImageUrl, 'https://') ? $ogImageUrl : null,
            'og:image:type' => $img['mime'] ?? null,
            'og:image:width' => isset($img['width']) ? (string) $img['width'] : null,
            'og:image:height' => isset($img['height']) ? (string) $img['height'] : null,
            'og:image:alt' => $nz($page['image_alt'] ?? null) ?? $ogTitle,
        ], fn ($v) => $v !== null && $v !== ''),
        'twitter' => array_filter([
            'twitter:card' => 'summary_large_image',
            'twitter:site' => seo_twitter_handle(),
            'twitter:title' => $ogTitle,
            'twitter:description' => $ogDesc,
            'twitter:image' => $ogImageUrl,
            'twitter:image:alt' => $nz($page['image_alt'] ?? null) ?? $ogTitle,
        ], fn ($v) => $v !== null && $v !== ''),
    ];
}

/** Top-level entities of a JSON-LD value (lists and @graph are flattened; nested properties are not). */
function seo_schema_entities($node): array
{
    if (!is_array($node)) {
        return [];
    }
    if (array_is_list($node)) {
        $out = [];
        foreach ($node as $n) {
            array_push($out, ...seo_schema_entities($n));
        }
        return $out;
    }
    if (isset($node['@graph']) && is_array($node['@graph'])) {
        return seo_schema_entities($node['@graph']);
    }
    return [$node];
}

/** Family of a schema.org type, used to avoid duplicate entities (BlogPosting and NewsArticle are both "article"). */
function seo_schema_family(string $type): string
{
    static $map = [
        'Article' => 'article', 'NewsArticle' => 'article', 'BlogPosting' => 'article', 'Report' => 'article', 'ScholarlyArticle' => 'article', 'TechArticle' => 'article',
        'Course' => 'course', 'EducationalOccupationalProgram' => 'course', 'CourseInstance' => 'course',
        'Event' => 'event', 'EducationEvent' => 'event', 'BusinessEvent' => 'event', 'SocialEvent' => 'event', 'Festival' => 'event', 'ExhibitionEvent' => 'event',
        'SportsEvent' => 'event', 'MusicEvent' => 'event', 'ChildrensEvent' => 'event', 'Hackathon' => 'event',
        'Organization' => 'organization', 'CollegeOrUniversity' => 'organization', 'EducationalOrganization' => 'organization', 'School' => 'organization',
        'LocalBusiness' => 'organization',
        'WebSite' => 'website', 'BreadcrumbList' => 'breadcrumb', 'FAQPage' => 'faq', 'ItemList' => 'itemlist',
        'WebPage' => 'webpage', 'AboutPage' => 'webpage', 'ContactPage' => 'webpage', 'CollectionPage' => 'webpage', 'ImageGallery' => 'webpage',
        'ProfilePage' => 'webpage', 'SearchResultsPage' => 'webpage', 'MedicalWebPage' => 'webpage',
    ];
    return $map[$type] ?? strtolower($type);
}

/** Families present among the top-level entities of a JSON-LD value. */
function seo_schema_families($node): array
{
    $out = [];
    foreach (seo_schema_entities($node) as $e) {
        foreach ((array) ($e['@type'] ?? []) as $t) {
            if (is_string($t) && $t !== '') {
                $out[seo_schema_family($t)] = true;
            }
        }
    }
    return $out;
}

/** BreadcrumbList items from the URL path: Home › Programs › MBA (labels of known sections, current page = title). */
function seo_breadcrumb_from_path(string $path, string $currentTitle): array
{
    $labels = ['programs' => 'Programs', 'blog' => 'News & Blog', 'events' => 'Events', 'notices' => 'Notice Board', 'gallery' => 'Gallery',
        'admissions-open' => 'Admissions Open', 'verify-certificate' => 'Verify a Certificate'];
    $items = [['name' => 'Home', 'item' => seo_site_url()]];
    $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
    $acc = '';
    foreach ($segments as $i => $seg) {
        $acc .= '/' . $seg;
        if ($seg === 'page' && $i === 0) {
            continue; // /page/{slug} custom CMS pages
        }
        $last = $i === count($segments) - 1;
        $items[] = ['name' => $last ? $currentTitle : ($labels[$seg] ?? ucwords(str_replace('-', ' ', $seg))), 'item' => seo_path_url($acc)];
    }
    $list = [];
    foreach ($items as $n => $it) {
        $list[] = ['@type' => 'ListItem', 'position' => $n + 1, 'name' => $it['name'], 'item' => $it['item']];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
}

/** Site-wide CollegeOrUniversity entity (settings only — verified data). */
function seo_organization_entity(): array
{
    $site = seo_site_url();
    $logo = (string) preg_replace('/\?v=\d+$/', '', (string) seo_abs_url(function_exists('logo_url') ? logo_url() : 'assets/images/logo.svg'));
    $phone = (string) setting('phone', '');
    $email = (string) setting('email', '');
    $addr = array_filter(['@type' => 'PostalAddress', 'streetAddress' => setting('address'), 'addressLocality' => setting('city', 'Noida'),
        'addressRegion' => setting('state', 'Uttar Pradesh'), 'postalCode' => setting('pincode'), 'addressCountry' => 'IN']);
    $lat = setting('latitude');
    $lng = setting('longitude');
    return array_filter([
        '@type' => 'CollegeOrUniversity', '@id' => $site . '#organization', 'name' => institute_name(),
        'alternateName' => array_values(array_unique(array_filter([setting('brand_name', 'Global IMT'), setting('institute_short_name', 'GIMT')]))),
        'url' => $site, 'logo' => ['@type' => 'ImageObject', 'url' => $logo], 'image' => $logo,
        'email' => $email ?: null, 'telephone' => $phone ?: null,
        'foundingDate' => setting('established_year') ? (string) setting('established_year') : null,
        'address' => count($addr) > 2 ? $addr : null,
        'geo' => is_numeric($lat) && is_numeric($lng) ? ['@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lng] : null,
        'contactPoint' => $phone || setting('admission_email') ? [array_filter(['@type' => 'ContactPoint', 'contactType' => 'admissions', 'telephone' => $phone ?: null,
            'email' => setting('admission_email') ?: ($email ?: null), 'areaServed' => 'IN'])] : null,
        'sameAs' => seo_same_as() ?: null,
    ], fn ($v) => $v !== null && $v !== '' && $v !== []);
}

/** Site-wide WebSite entity with the /search SearchAction. */
function seo_website_entity(): array
{
    $site = seo_site_url();
    return array_filter([
        '@type' => 'WebSite', '@id' => $site . '#website', 'url' => $site, 'name' => institute_name(), 'alternateName' => setting('institute_short_name', 'GIMT'),
        'inLanguage' => 'en-IN', 'publisher' => ['@id' => $site . '#organization'],
        'potentialAction' => is_file(APP_ROOT . '/search.php') ? ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $site . 'search?q={search_term_string}'],
            'query-input' => 'required name=search_term_string'] : null,
    ], fn ($v) => $v !== null);
}

/**
 * JSON-LD <script> tags without duplicates:
 *   1. the page's own schema ($page['schema']) — always printed;
 *   2. the admin schema (seo_settings.schema_json) — entities whose family the page already provides are skipped
 *      (a page BlogPosting replaces an admin NewsArticle, a page Course an admin Course …); 'schema_admin' => false
 *      on site_header() skips the admin schema completely;
 *   3. site-wide CollegeOrUniversity + WebSite (with SearchAction) unless 1/2 already contain them;
 *   4. an automatic BreadcrumbList from the URL when neither 1 nor 2 has one (not on the home page or error pages).
 */
function seo_jsonld_html(array $page, ?array $seo, string $title, int $status = 200): string
{
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
    $tag = fn ($data) => '  <script type="application/ld+json">' . json_encode($data, $flags) . "</script>\n";
    $out = '';
    $pageSchemas = array_values(array_filter((array) ($page['schema'] ?? []), 'is_array'));
    $families = seo_schema_families($pageSchemas);
    foreach ($pageSchemas as $s) {
        $out .= $tag($s);
    }
    $adminFamilies = [];
    if (($page['schema_admin'] ?? true) !== false && !empty($seo['schema_json'])) {
        $admin = json_decode((string) $seo['schema_json'], true);
        if (is_array($admin)) {
            foreach (seo_schema_entities($admin) as $entity) {
                $fam = array_keys(seo_schema_families($entity));
                if ($fam && array_intersect($fam, array_keys($families))) {
                    continue; // the page already describes this (e.g. its own BlogPosting replaces an admin NewsArticle)
                }
                foreach ($fam as $f) {
                    $adminFamilies[$f] = true;
                }
                $out .= $tag(['@context' => 'https://schema.org'] + $entity);
            }
        }
    }
    $have = $families + $adminFamilies;
    $graph = [];
    if (empty($have['organization'])) {
        $graph[] = seo_organization_entity();
    }
    if (empty($have['website'])) {
        $graph[] = seo_website_entity();
    }
    if ($graph) {
        $out = $tag(['@context' => 'https://schema.org', '@graph' => $graph]) . $out;
    }
    $path = seo_site_path();
    if (empty($have['breadcrumb']) && $status < 400 && $path !== '/' && ($page['breadcrumb_schema'] ?? true) !== false) {
        $current = trim((string) ($page['title'] ?? '')) ?: trim((string) preg_replace('/\s*[|\-–—]\s*' . preg_quote((string) setting('institute_short_name', 'GIMT'), '/') . '\s*$/u', '', $title));
        $out .= $tag(seo_breadcrumb_from_path(seo_canonical_path($path), $current));
    }
    return $out;
}

/**
 * Extra <head> tags from site_header(['head' => [...]]): raw HTML strings (trusted, from page code) or arrays —
 * ['rel' => 'prev', 'href' => …] → <link>, ['property'|'name' => …, 'content' => …] → <meta>. Empty hrefs are skipped.
 */
function seo_head_extras($items): string
{
    $out = '';
    foreach ((array) $items as $h) {
        if (is_string($h)) {
            if (trim($h) !== '') {
                $out .= '  ' . trim($h) . "\n";
            }
            continue;
        }
        if (!is_array($h)) {
            continue;
        }
        if (!empty($h['rel'])) {
            if (empty($h['href'])) {
                continue;
            }
            $attrs = '';
            foreach (['rel', 'href', 'type', 'title', 'hreflang', 'media', 'sizes', 'as', 'crossorigin'] as $a) {
                if (isset($h[$a]) && $h[$a] !== '' && $h[$a] !== null && $h[$a] !== false) {
                    $attrs .= ' ' . $a . '="' . e($h[$a]) . '"';
                }
            }
            $out .= '  <link' . $attrs . ">\n";
        } elseif (array_key_exists('content', $h) && (!empty($h['name']) || !empty($h['property']))) {
            if ($h['content'] === null || $h['content'] === '') {
                continue;
            }
            $attr = !empty($h['property']) ? 'property="' . e($h['property']) . '"' : 'name="' . e($h['name']) . '"';
            $out .= '  <meta ' . $attr . ' content="' . e($h['content']) . "\">\n";
        }
    }
    return $out;
}

/** Search engine verification meta tags (Google Search Console, Bing). They set no cookies, so no consent is needed. */
function seo_verification_meta(): string
{
    $out = '';
    if ($v = seo_integration_value('search_console_verification')) {
        $out .= '  <meta name="google-site-verification" content="' . e($v) . "\">\n";
    }
    if ($v = seo_integration_value('bing_site_verification')) {
        $out .= '  <meta name="msvalidate.01" content="' . e($v) . "\">\n";
    }
    return $out;
}

/** Endpoint of the client error reporter (works on every server; /api/public/client-error is an alias). */
function seo_client_error_endpoint(): string
{
    return base_url('api/integrations/client-error');
}

/**
 * Tiny inline window.onerror / unhandledrejection / resource-error reporter (≈0.8 KB). At most 5 reports per page,
 * duplicates dropped, sent with sendBeacon (fetch keepalive fallback) to the same-origin endpoint.
 */
function seo_error_reporter_head(): string
{
    if (seo_integration_value('client_error_logging', '1') !== '1') {
        return '';
    }
    $js = '(function(w,d){var u=' . js_json(seo_client_error_endpoint()) . ',n=0,s={};function send(o){if(n>4)return;var k=o.t+"|"+o.m+"|"+(o.s||"")+"|"+(o.l||0);if(s[k])return;s[k]=1;n++;'
        . 'o.p=location.pathname+location.search;try{var b=JSON.stringify(o);if(navigator.sendBeacon&&navigator.sendBeacon(u,new Blob([b],{type:"application/json"})))return;'
        . 'if(w.fetch)fetch(u,{method:"POST",body:b,headers:{"Content-Type":"application/json"},keepalive:true,credentials:"same-origin"}).catch(function(){})}catch(e){}}'
        . 'w.addEventListener("error",function(e){var t=e.target;if(t&&t!==w&&t.tagName){var src=t.currentSrc||t.src||t.href||"";if(src&&!/^data:/.test(src))send({t:"resource",m:"Failed to load "+t.tagName.toLowerCase(),s:String(src).slice(0,300)});return}'
        . 'var er=e.error;send({t:"error",m:String(e.message||"").slice(0,500),s:String(e.filename||"").slice(0,300),l:e.lineno||0,c:e.colno||0,st:er&&er.stack?String(er.stack).slice(0,2000):""})},true);'
        . 'w.addEventListener("unhandledrejection",function(e){var r=e.reason;send({t:"rejection",m:String(r&&r.message||r||"").slice(0,500),st:r&&r.stack?String(r.stack).slice(0,2000):""})})})(window,document);';
    return '  <script>' . $js . "</script>\n";
}

/** Optional Sentry browser loader (when a DSN is configured). */
function seo_sentry_head(): string
{
    $dsn = (string) seo_integration_value('sentry_dsn', '');
    if (!preg_match('#^https://([A-Za-z0-9]{16,64})@[A-Za-z0-9.\-]+(:[0-9]+)?/[0-9]+$#', $dsn, $m)) {
        return '';
    }
    $env = (string) seo_integration_value('sentry_environment', 'production');
    return '  <script>window.sentryOnLoad=function(){Sentry.init({environment:' . js_json($env) . ',sendDefaultPii:false})};</script>' . "\n"
        . '  <script src="https://js.sentry-cdn.com/' . e($m[1]) . '.min.js" crossorigin="anonymous" async></script>' . "\n";
}

/* =====================================================================================================================
 * 4. Redirects (table redirects), lower-case / trailing-slash normalisation, 410 Gone, 404 log
 * ===================================================================================================================*/

/** Status codes an admin can choose. */
function seo_redirect_codes(): array
{
    return [301 => '301 Moved permanently', 302 => '302 Found (temporary)', 307 => '307 Temporary (keeps method)', 308 => '308 Permanent (keeps method)', 410 => '410 Gone (removed)'];
}

/**
 * Normalise a "from" path or an internal target: accepts "/old-page", "old-page", a full URL of this website,
 * "/x?y=1" (query kept when $keepQuery), drops #fragment, decodes %xx, removes the base path, double and trailing
 * slashes. Returns null for external URLs, other schemes or invalid input.
 */
function seo_redirect_normalize(string $raw, bool $keepQuery = true): ?string
{
    $raw = trim(str_replace(["\r", "\n", "\t", "\0"], '', $raw));
    if ($raw === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $raw)) {
        $host = (string) parse_url($raw, PHP_URL_HOST);
        $port = parse_url($raw, PHP_URL_PORT);
        if ($host === '' || !seo_is_own_host($host . ($port ? ':' . $port : ''))) {
            return null;
        }
        $raw = (string) preg_replace('#^https?://[^/?\#]+#i', '', $raw);
        $raw = $raw === '' ? '/' : $raw;
    }
    if (str_starts_with($raw, '//') || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $raw)) {
        return null;
    }
    $raw = explode('#', $raw, 2)[0];
    [$path, $query] = array_pad(explode('?', $raw, 2), 2, null);
    $path = seo_site_path('/' . ltrim($path, '/'));
    if ($path !== '/') {
        $path = rtrim($path, '/') ?: '/';
    }
    if (preg_match('/[\x00-\x1F\x7F<>"\\\\{}|^`]/', $path)) {
        return null;
    }
    $out = $path . ($keepQuery && $query !== null && trim($query) !== '' ? '?' . trim($query) : '');
    return mb_strlen($out) <= 500 ? $out : null;
}

/** Case-insensitive lookup key of a normalised path. */
function seo_redirect_hash(string $path): string
{
    return sha1(mb_strtolower($path));
}

/** Paths that can never be redirected (home page, admin, API, assets, portals, SEO files). Returns a message or null. */
function seo_redirect_reserved(string $path): ?string
{
    $p = mb_strtolower(explode('?', $path, 2)[0]);
    if ($p === '/') {
        return 'The home page cannot be redirected.';
    }
    if (preg_match('#^/(admin|api|assets|portal|storage)(/|$)#', $p)) {
        return 'Paths under /admin, /api, /assets, /portal and /storage cannot be redirected.';
    }
    if (in_array($p, ['/sitemap.xml', '/robots.txt'], true) || preg_match('#^/sitemap-[a-z]+\.xml$#', $p)) {
        return 'sitemap.xml and robots.txt cannot be redirected.';
    }
    return null;
}

/** Paths left alone by the automatic lower-case / trailing-slash 301s (codes are case-sensitive there). */
function seo_redirect_case_sensitive(string $path): bool
{
    return (bool) preg_match('#^/(verify-certificate|verify-marksheet|assets|api|admin|portal|storage)(/|$)#i', $path);
}

/** Active redirect rule for a path (+ query): an exact "path?query" rule wins over a path rule. */
function seo_redirect_match(string $path, string $query = ''): ?array
{
    try {
        $hashes = [seo_redirect_hash($path)];
        if ($query !== '') {
            array_unshift($hashes, seo_redirect_hash($path . '?' . $query));
        }
        $rows = db_all('SELECT * FROM redirects WHERE is_active = 1 AND from_hash IN (' . implode(',', array_fill(0, count($hashes), '?')) . ')', $hashes);
    } catch (Throwable $e) {
        return null; // table not installed yet
    }
    foreach ($hashes as $h) {
        foreach ($rows as $r) {
            if ($r['from_hash'] === $h) {
                return $r;
            }
        }
    }
    return null;
}

/** Absolute Location for a rule (null for 410), appending the visitor's query string when the rule says so. */
function seo_redirect_location(array $rule, string $query = ''): ?string
{
    $to = trim((string) ($rule['to_url'] ?? ''));
    if ((int) $rule['status_code'] === 410 || $to === '') {
        return null;
    }
    $fragment = '';
    if (str_contains($to, '#')) {
        [$to, $fragment] = explode('#', $to, 2);
        $fragment = '#' . $fragment;
    }
    $url = preg_match('#^https?://#i', $to) ? $to : seo_path_url($to);
    if ((int) $rule['preserve_query'] === 1 && $query !== '' && !str_contains((string) $rule['from_path'], '?')) {
        $url .= (str_contains($url, '?') ? '&' : '?') . $query;
    }
    return str_replace(["\r", "\n"], '', $url . $fragment);
}

function seo_redirect_hit(int $id): void
{
    if (seo_is_audit_request()) {
        return;
    }
    try {
        db_exec('UPDATE redirects SET hits = hits + 1, last_hit_at = NOW(), updated_at = updated_at WHERE id = ?', [$id]);
    } catch (Throwable $e) {
    }
}

/** Send a redirect and stop. Permanent redirects may be cached for an hour (an admin can still change them later). */
function seo_send_redirect(string $location, int $code = 301): void
{
    header('Location: ' . str_replace(["\r", "\n"], '', $location), true, $code);
    header('Cache-Control: ' . (in_array($code, [301, 308], true) ? 'public, max-age=3600' : 'no-store'));
    header('X-Redirect-By: GIMT SEO');
    exit;
}

/**
 * Consult the redirect manager for the current GET/HEAD request (runs at most once per request).
 *   $missing = true (the page is about to answer 404): admin rules (3xx or 410), then lower-case / trailing-slash 301
 *              when that URL exists, then the 404 is recorded in seo_404_log. Returns 410, 404 or null.
 *   $missing = false (a live page): only admin 3xx rules apply (410 rules are for missing URLs).
 * Sends the redirect and exits when one applies.
 */
function seo_redirect_maybe(bool $missing = true): ?int
{
    static $done = false;
    if ($done) {
        return null;
    }
    $done = true;
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true) || headers_sent() || PHP_SAPI === 'cli') {
        return null;
    }
    $path = seo_site_path();
    $query = seo_request_query();
    if (preg_match('#^/(admin|api|portal|storage)(/|$)#i', $path)) {
        return null;
    }
    // rules are stored without a trailing slash and matched case-insensitively (/Old-Page/ finds /old-page)
    if ($rule = seo_redirect_match($path !== '/' ? (rtrim($path, '/') ?: '/') : $path, $query)) {
        $code = (int) $rule['status_code'];
        if ($code === 410) {
            if ($missing) {
                seo_redirect_hit((int) $rule['id']);
                http_response_code(410);
                header('X-Robots-Tag: noindex');
                return 410;
            }
        } elseif ($loc = seo_redirect_location($rule, $query)) {
            // A rule for one exact query string (/old?x=1 → /old) compares path + query, a path rule the path only
            // (a rule can never send a visitor to the URL they are already on).
            $exactQuery = str_contains((string) $rule['from_path'], '?');
            $target = seo_redirect_normalize($loc, $exactQuery);
            $self = $exactQuery ? (($path !== '/' ? (rtrim($path, '/') ?: '/') : $path) . ($query !== '' ? '?' . $query : '')) : $path;
            if ($target === null || mb_strtolower($target) !== mb_strtolower($self)) {
                seo_redirect_hit((int) $rule['id']);
                seo_send_redirect($loc, $code);
            }
        }
    }
    if (!$missing) {
        return null;
    }
    $norm = $path;
    if ($norm !== '/' && str_ends_with($norm, '/')) {
        $norm = rtrim($norm, '/') ?: '/';
    }
    if (preg_match('/[A-Z]/', $norm) && !seo_redirect_case_sensitive($norm)) {
        $norm = mb_strtolower($norm);
    }
    if ($norm !== $path && seo_path_exists($norm)) {
        seo_send_redirect(seo_path_url($norm) . ($query !== '' ? '?' . $query : ''), 301);
    }
    seo_404_record($path);
    return 404;
}

/**
 * Hook for includes/header.php (every page rendered with the site layout): 404 responses consult the rules + 404 log,
 * live pages only explicit 3xx rules. Returns the (possibly changed) status.
 */
function seo_redirect_on_render(int $status): int
{
    if ($status === 404) {
        return seo_redirect_maybe(true) ?? 404;
    }
    if ($status >= 200 && $status < 300) {
        seo_redirect_maybe(false);
    }
    return $status;
}

/**
 * Does a site path resolve to a page today? (static routes, published CMS pages, programs, posts, events, notices,
 * albums, landing pages, SEO files, assets). Used for lower-case 301s, redirect validation and the URL tester.
 */
function seo_path_exists(string $path): bool
{
    static $cache = [];
    $p = '/' . trim(explode('?', $path, 2)[0], '/');
    if (isset($cache[$p])) {
        return $cache[$p];
    }
    $root = APP_ROOT;
    $ok = false;
    try {
        if ($p === '/') {
            $ok = true;
        } elseif (preg_match('#^/(admin|portal)(/.*)?$#', $p)) {
            $ok = is_file($root . '/' . explode('/', ltrim($p, '/'))[0] . '/index.php');
        } elseif (in_array($p, ['/sitemap.xml', '/robots.txt'], true) || preg_match('#^/sitemap-[a-z]+\.xml$#', $p)) {
            $ok = true;
        } elseif (str_starts_with($p, '/assets/')) {
            $ok = !str_contains($p, '..') && is_file($root . $p);
        } elseif (preg_match('#^/verify-(certificate|marksheet)(/.*)?$#', $p)) {
            $ok = true;
        } elseif (preg_match('#^/(programs|blog|events|notices|gallery|page|admissions-open)/([a-z0-9-]+)$#', $p, $m)) {
            $slug = $m[2];
            $ok = match ($m[1]) {
                'programs' => (bool) db_value("SELECT COUNT(*) FROM programs WHERE slug = ? AND status = 'active' AND show_on_website = 1", [$slug]),
                'blog' => (bool) db_value("SELECT COUNT(*) FROM blog_posts WHERE slug = ? AND status = 'published' AND (published_at IS NULL OR published_at <= NOW())", [$slug]),
                'events' => (bool) db_value("SELECT COUNT(*) FROM events WHERE slug = ? AND show_on_website = 1 AND status IN ('published','completed','cancelled')", [$slug]),
                'notices' => (bool) db_value("SELECT COUNT(*) FROM notices WHERE slug = ? AND status = 'published' AND show_on_website = 1", [$slug]),
                'gallery' => (bool) db_value("SELECT COUNT(*) FROM galleries WHERE slug = ? AND status = 'published'", [$slug]),
                'page' => (bool) db_value("SELECT COUNT(*) FROM pages WHERE slug = ? AND status = 'published' AND (published_at IS NULL OR published_at <= NOW())", [$slug]),
                'admissions-open' => (bool) db_value("SELECT COUNT(*) FROM programs WHERE status = 'active' AND (slug = ? OR LOWER(code) = ? OR LOWER(REPLACE(REPLACE(short_name, '.', ''), ' ', '-')) = ?)", [$slug, $slug, $slug]),
            };
        } elseif (preg_match('#^/([a-z0-9-]+)$#', $p, $m)) {
            $internal = ['index', 'site-kit', '404', '500', 'page', 'post', 'event', 'notice', 'program', 'gallery-item', 'sitemap', 'robots'];
            $ok = (is_file($root . '/' . $m[1] . '.php') && !in_array($m[1], $internal, true))
                || (bool) db_value("SELECT COUNT(*) FROM pages WHERE slug = ? AND status = 'published' AND (published_at IS NULL OR published_at <= NOW())", [$m[1]]);
        }
    } catch (Throwable $e) {
        $ok = false;
    }
    return $cache[$p] = $ok;
}

/** Scanner noise that is not worth logging (wp-admin probes, dot-files, server-side extensions …). */
function seo_404_is_noise(string $path): bool
{
    return (bool) preg_match('#(^/(wp-|wordpress|xmlrpc|phpmyadmin|pma|cgi-bin|vendor|\.)|/\.(env|git|svn|ht)|\.(asp|aspx|jsp|cgi|sql|bak|old|zip|tar|gz|rar|7z|ini|log|ya?ml|sh|env|swp)$|/(wp-login|wp-admin|wp-content|wp-includes)/)#i', $path);
}

/** Record a 404 in seo_404_log (once per request, crawler hits counted separately, no personal data). */
function seo_404_record(string $path): void
{
    static $done = false;
    if ($done || seo_is_audit_request() || seo_404_is_noise($path) || mb_strlen($path) > 500) {
        return;
    }
    $done = true;
    try {
        if (!seo_rate_limit('seo404:' . client_ip(), 60, 600)) {
            return;
        }
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $bot = preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|embedly|preview/i', $ua) ? 1 : 0;
        $ref = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        $ref = $ref !== '' && preg_match('#^https?://#i', $ref) ? explode('#', $ref, 2)[0] : null;
        if ($ref !== null && !seo_is_own_host((string) parse_url($ref, PHP_URL_HOST) . (parse_url($ref, PHP_URL_PORT) ? ':' . parse_url($ref, PHP_URL_PORT) : ''))) {
            $ref = explode('?', $ref, 2)[0]; // other websites: no query string (it may carry personal data)
        }
        $ref = $ref !== null ? mb_substr($ref, 0, 500) : null;
        db_exec('INSERT INTO seo_404_log (path, path_hash, hits, bot_hits, last_referrer, first_seen_at, last_seen_at) VALUES (?, ?, 1, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE hits = hits + 1, bot_hits = bot_hits + VALUES(bot_hits), last_referrer = COALESCE(VALUES(last_referrer), last_referrer), last_seen_at = NOW()',
            [$path, seo_redirect_hash($path), $bot, $ref]);
        if (random_int(1, 50) === 1) {
            db_exec('DELETE FROM seo_404_log WHERE last_seen_at < NOW() - INTERVAL 120 DAY');
        }
    } catch (Throwable $e) {
    }
}

/**
 * Normalised internal target of a redirect: ['url' => stored to_url, 'path' => normalised path or null (external),
 * 'external' => bool] or null when the target is not a valid URL.
 */
function seo_redirect_target(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '' || preg_match('/[\s<>"]/', $raw) || preg_match('#^\s*(javascript|data|vbscript):#i', $raw)) {
        return null;
    }
    if (preg_match('#^https?://#i', $raw)) {
        $host = (string) parse_url($raw, PHP_URL_HOST);
        $port = parse_url($raw, PHP_URL_PORT);
        if ($host === '') {
            return null;
        }
        if (!seo_is_own_host($host . ($port ? ':' . $port : ''))) {
            return filter_var($raw, FILTER_VALIDATE_URL) ? ['url' => mb_substr($raw, 0, 1000), 'path' => null, 'external' => true] : null;
        }
    }
    $fragment = str_contains($raw, '#') ? '#' . explode('#', $raw, 2)[1] : '';
    $norm = seo_redirect_normalize($raw, true);
    if ($norm === null) {
        return null;
    }
    return ['url' => $norm . $fragment, 'path' => explode('?', $norm, 2)[0], 'external' => false];
}

/** Follow active rules from a path: [hops (from, to, code), final path|external url, loop flag, gone flag]. */
function seo_redirect_follow(string $path, ?int $ignoreId = null, int $max = 10): array
{
    $hops = [];
    $seen = [mb_strtolower($path) => true];
    $current = $path;
    for ($i = 0; $i < $max; $i++) {
        $rule = seo_redirect_match(explode('?', $current, 2)[0], str_contains($current, '?') ? explode('?', $current, 2)[1] : '');
        if (!$rule || ($ignoreId && (int) $rule['id'] === $ignoreId)) {
            break;
        }
        $code = (int) $rule['status_code'];
        $hops[] = ['id' => (int) $rule['id'], 'from' => $rule['from_path'], 'to' => $rule['to_url'], 'code' => $code];
        if ($code === 410 || empty($rule['to_url'])) {
            return ['hops' => $hops, 'final' => $current, 'loop' => false, 'gone' => true, 'external' => false];
        }
        if (empty($rule['to_path'])) {
            return ['hops' => $hops, 'final' => $rule['to_url'], 'loop' => false, 'gone' => false, 'external' => true];
        }
        $next = $rule['to_path'];
        if (isset($seen[mb_strtolower($next)])) {
            return ['hops' => $hops, 'final' => $next, 'loop' => true, 'gone' => false, 'external' => false];
        }
        $seen[mb_strtolower($next)] = true;
        $current = $next;
    }
    return ['hops' => $hops, 'final' => $current, 'loop' => false, 'gone' => false, 'external' => false];
}

/**
 * Validate and normalise a redirect for the CRUD module (create, edit, CSV import).
 * $in: from_path, to_url, status_code, preserve_query, is_active, notes (missing keys fall back to $old).
 * @return array{data: array, errors: array, notes: string[]}
 */
function seo_redirect_prepare(array $in, ?int $id = null, ?array $old = null): array
{
    $errors = [];
    $notes = [];
    $get = fn (string $k, $default = null) => array_key_exists($k, $in) ? $in[$k] : ($old[$k] ?? $default);
    $code = (int) ($get('status_code', 301) ?: 301);
    if (!isset(seo_redirect_codes()[$code])) {
        $errors['status_code'] = 'Choose 301, 302, 307, 308 or 410.';
    }
    $from = seo_redirect_normalize((string) $get('from_path', ''), true);
    if ($from === null) {
        $errors['from_path'] = 'Enter a path on this website, e.g. /old-page (or a full URL of this website).';
    } elseif ($msg = seo_redirect_reserved($from)) {
        $errors['from_path'] = $msg;
    } else {
        $dup = db_row('SELECT id FROM redirects WHERE from_hash = ?' . ($id ? ' AND id <> ?' : ''), $id ? [seo_redirect_hash($from), $id] : [seo_redirect_hash($from)]);
        if ($dup) {
            $errors['from_path'] = 'A redirect for ' . $from . ' already exists.';
        }
    }
    $target = null;
    $toUrl = null;
    $toPath = null;
    if ($code !== 410) {
        $rawTo = trim((string) $get('to_url', ''));
        if ($rawTo === '') {
            $errors['to_url'] = 'Enter the new URL (a path such as /programs or a full https:// address).';
        } elseif (!($target = seo_redirect_target($rawTo))) {
            $errors['to_url'] = 'Enter a valid path starting with / or a full http(s):// URL.';
        } else {
            $toUrl = $target['url'];
            $toPath = $target['path'];
        }
    }
    if (!$errors && $from !== null && $toPath !== null) {
        $fromPath = explode('?', $from, 2)[0];
        if (mb_strtolower($toPath) === mb_strtolower($fromPath) && !str_contains($from, '?')) {
            $errors['to_url'] = 'The new URL is the same as the old one.';
        } else {
            // Loop protection: following the target's own rules must never lead back here.
            $follow = seo_redirect_follow($toPath, $id);
            $chain = array_merge([$from], array_map(fn ($h) => $h['to'] ?? '410', $follow['hops']));
            if ($follow['loop'] || in_array(mb_strtolower($fromPath), array_map(fn ($h) => mb_strtolower((string) explode('?', (string) ($h['to'] ?? ''), 2)[0]), $follow['hops']), true)) {
                $errors['to_url'] = 'This would create a redirect loop: ' . implode(' → ', array_merge([$fromPath, $toPath], array_slice($chain, 1))) . '.';
            } elseif ($follow['gone']) {
                $errors['to_url'] = $toPath . ' is marked as gone (410). Choose a live page.';
            } elseif ($follow['hops']) {
                // Chain protection: point straight at the final destination.
                $last = end($follow['hops']);
                $notes[] = 'Chain avoided: ' . $toPath . ' already redirects to ' . $last['to'] . ', so this redirect now points there directly.';
                $toUrl = $last['to'];
                $toPath = $follow['external'] ? null : explode('?', (string) $last['to'], 2)[0];
            }
        }
        if (!$errors && $toPath !== null && !seo_path_exists($toPath)) {
            $notes[] = 'Check the target: ' . $toPath . ' does not exist on the website yet.';
        }
    }
    if (!$errors && $from !== null && (seo_path_exists($from) || seo_path_exists(mb_strtolower(explode('?', $from, 2)[0])))) {
        // rules match case-insensitively, so /About also catches the live /about page
        $notes[] = (seo_path_exists($from) ? $from : mb_strtolower(explode('?', $from, 2)[0])) . ' is a live page — visitors will be redirected away from it.';
    }
    $data = [
        'from_path' => $from,
        'from_hash' => $from !== null ? seo_redirect_hash($from) : null,
        'to_url' => $code === 410 ? null : $toUrl,
        'to_path' => $code === 410 ? null : $toPath,
        'status_code' => $code,
        'preserve_query' => in_array($get('preserve_query', 1), [true, 1, '1', 'true', 'on', 'yes'], true) ? 1 : 0,
        'is_active' => in_array($get('is_active', 1), [true, 1, '1', 'true', 'on', 'yes', 'active'], true) ? 1 : 0,
        'notes' => ($n = trim((string) $get('notes', ''))) !== '' ? mb_substr($n, 0, 500) : null,
    ];
    return ['data' => $data, 'errors' => $errors, 'notes' => $notes];
}

/**
 * After saving rule $id (from → to): rules that pointed at "from" now point at the new target (no chains), and the
 * path leaves the 404 log. Returns the number of rules updated.
 */
function seo_redirect_flatten_incoming(int $id): int
{
    $rule = db_row('SELECT * FROM redirects WHERE id = ?', [$id]);
    if (!$rule) {
        return 0;
    }
    $fromPath = explode('?', (string) $rule['from_path'], 2)[0];
    try {
        db_exec('DELETE FROM seo_404_log WHERE path_hash = ?', [seo_redirect_hash($fromPath)]);
    } catch (Throwable $e) {
    }
    if (!(int) $rule['is_active'] || (int) $rule['status_code'] === 410 || empty($rule['to_url'])) {
        return 0;
    }
    $n = 0;
    foreach (db_all('SELECT id, from_path FROM redirects WHERE id <> ? AND is_active = 1 AND status_code <> 410 AND LOWER(to_path) = LOWER(?)', [$id, $fromPath]) as $r) {
        if ($rule['to_path'] !== null && mb_strtolower(explode('?', (string) $r['from_path'], 2)[0]) === mb_strtolower((string) $rule['to_path'])) {
            continue; // would point at itself
        }
        db_exec('UPDATE redirects SET to_url = ?, to_path = ?, updated_at = NOW() WHERE id = ?', [$rule['to_url'], $rule['to_path'], (int) $r['id']]);
        $n++;
    }
    if ($n) {
        log_activity('update', 'seo', $id, "Redirect chain flattened: $n rule" . ($n === 1 ? '' : 's') . " pointing at {$rule['from_path']} now go straight to {$rule['to_url']}");
    }
    return $n;
}

/**
 * "Test a URL": what happens when a visitor opens $input — every redirect hop (admin rules, lower-case and
 * trailing-slash normalisation), the final URL and whether it exists. No HTTP request is made.
 */
function seo_redirect_trace(string $input): array
{
    $input = trim($input);
    $external = preg_match('#^https?://#i', $input) && seo_redirect_normalize($input) === null;
    if ($input === '' || $external) {
        return ['ok' => false, 'error' => $input === '' ? 'Enter a path such as /old-page or a full URL of this website.' : 'Only URLs of this website can be tested.'];
    }
    $start = seo_redirect_normalize($input, true);
    if ($start === null) {
        return ['ok' => false, 'error' => 'That is not a valid path on this website.'];
    }
    // keep a trailing slash / upper case of the input for the normalisation steps
    $raw = explode('#', preg_replace('#^https?://[^/?\#]+#i', '', $input), 2)[0];
    [$rawPath, $rawQuery] = array_pad(explode('?', $raw === '' ? '/' : $raw, 2), 2, '');
    $current = seo_site_path('/' . ltrim($rawPath, '/'));
    $query = (string) $rawQuery;
    $hops = [];
    $warnings = [];
    $seen = [];
    for ($i = 0; $i < 10; $i++) {
        $key = mb_strtolower($current . '?' . $query);
        if (isset($seen[$key])) {
            $warnings[] = 'Redirect loop detected.';
            break;
        }
        $seen[$key] = true;
        $rule = seo_redirect_match($current !== '/' ? (rtrim($current, '/') ?: '/') : $current, $query);
        if ($rule) {
            $code = (int) $rule['status_code'];
            if ($code === 410) {
                $live = seo_path_exists($current);
                $hops[] = ['from' => $current, 'to' => null, 'code' => 410, 'reason' => $live ? 'Gone rule (ignored: the page is live)' : 'Admin rule: gone', 'rule_id' => (int) $rule['id']];
                if (!$live) {
                    return ['ok' => true, 'hops' => $hops, 'final' => ['url' => seo_path_url($current), 'path' => $current, 'status' => 410, 'exists' => false, 'external' => false], 'warnings' => $warnings];
                }
                break;
            }
            $loc = seo_redirect_location($rule, $query);
            $hops[] = ['from' => $current . ($query !== '' ? '?' . $query : ''), 'to' => $loc, 'code' => $code, 'reason' => 'Admin rule #' . $rule['id'], 'rule_id' => (int) $rule['id']];
            $next = $loc ? seo_redirect_normalize($loc, true) : null;
            if ($next === null) {
                return ['ok' => true, 'hops' => $hops, 'final' => ['url' => $loc, 'path' => null, 'status' => null, 'exists' => null, 'external' => true], 'warnings' => $warnings];
            }
            [$current, $query] = array_pad(explode('?', $next, 2), 2, '');
            continue;
        }
        if (seo_path_exists($current)) {
            break;
        }
        $norm = $current;
        if ($norm !== '/' && str_ends_with($norm, '/')) {
            $norm = rtrim($norm, '/') ?: '/';
        }
        if (preg_match('/[A-Z]/', $norm) && !seo_redirect_case_sensitive($norm)) {
            $norm = mb_strtolower($norm);
        }
        if ($norm !== $current) {
            $hops[] = ['from' => $current . ($query !== '' ? '?' . $query : ''), 'to' => seo_path_url($norm) . ($query !== '' ? '?' . $query : ''), 'code' => 301,
                'reason' => str_ends_with($current, '/') ? 'Trailing slash removed' : 'Lower-case URL', 'rule_id' => null];
            $current = $norm;
            continue;
        }
        break;
    }
    if (count(array_filter($hops, fn ($h) => $h['rule_id'])) > 1) {
        $warnings[] = 'More than one admin redirect is chained — point the first one straight at the final URL.';
    }
    $exists = seo_path_exists($current);
    return ['ok' => true, 'hops' => $hops, 'final' => ['url' => seo_path_url($current) . ($query !== '' ? '?' . $query : ''), 'path' => $current, 'status' => $exists ? 200 : 404,
        'exists' => $exists, 'external' => false], 'warnings' => $warnings];
}

/** Best guess for a 404 path among the sitemap URLs (same last segment / similar words), or null. */
function seo_suggest_target(string $path, ?array $candidates = null): ?string
{
    static $urls = null;
    if ($candidates === null) {
        if ($urls === null) {
            require_once APP_ROOT . '/app/services/cms.php';
            $urls = array_values(array_filter(array_map(fn ($u) => (string) $u['path'], cms_sitemap_urls()), fn ($p) => !str_contains($p, '?')));
        }
        $candidates = $urls;
    }
    $needle = mb_strtolower(trim((string) preg_replace('/\.(php|html?|aspx?)$/i', '', basename(rtrim($path, '/'))), '/'));
    $needle = trim((string) preg_replace('/[^a-z0-9]+/', '-', $needle), '-');
    if ($needle === '' || strlen($needle) < 3) {
        return null;
    }
    $best = null;
    $bestScore = 0.0;
    foreach ($candidates as $c) {
        $seg = basename($c) ?: '';
        if ($seg === '') {
            continue;
        }
        similar_text($needle, $seg, $pct);
        $words = array_intersect(explode('-', $needle), explode('-', $seg));
        $score = $pct + count($words) * 12 + (str_contains($seg, $needle) || str_contains($needle, $seg) ? 25 : 0);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $c;
        }
    }
    return $bestScore >= 70 ? $best : null;
}

/* =====================================================================================================================
 * 5. robots.txt
 * ===================================================================================================================*/

/**
 * Query parameters of filtered / internal views kept out of crawlers (listing pages, categories and pagination stay
 * crawlable). Campaign parameters (utm_*, gclid, fbclid) are deliberately NOT blocked: such URLs carry a canonical tag
 * without the query string, and blocking them would hide that canonical (and the links' value) from search engines.
 */
function seo_robots_blocked_params(): array
{
    return ['q', 'search', 'sort', 'order', 'tag', 'from', 'to', 'date', 'ack', 'preview', 'consent_preview', 'brochure', 'print'];
}

/** Recommended rules: allow the website, keep out admin / portals / API / internal search and filtered URLs. */
function seo_default_robots_txt(): string
{
    $base = base_path();
    $lines = ['# robots.txt — ' . institute_name(), 'User-agent: *', 'Allow: /'];
    // "/search$" + "/search?" block the internal search page only (not CMS pages such as /search-engine-marketing)
    foreach (['/admin/', '/api/', '/portal/', '/storage/', '/search$', '/search?'] as $p) {
        $lines[] = 'Disallow: ' . $base . $p;
    }
    foreach (seo_robots_blocked_params() as $param) {
        $lines[] = 'Disallow: ' . $base . '/*?' . $param . '=';
        $lines[] = 'Disallow: ' . $base . '/*&' . $param . '=';
    }
    $lines[] = '';
    $lines[] = 'Sitemap: ' . absolute_url('sitemap.xml');
    return implode("\n", $lines) . "\n";
}

/** robots.txt served at /robots.txt: blocked staging → disallow all; custom CMS rules (+ Sitemap line); else defaults. */
function seo_robots_txt(): string
{
    if (setting('seo_block_indexing', '0') === '1') {
        return "# Indexing is switched off (Settings → Integrations → Search engines)\nUser-agent: *\nDisallow: /\n";
    }
    $custom = trim(str_replace("\r\n", "\n", (string) setting('robots_txt', '')));
    if ($custom === '') {
        return seo_default_robots_txt();
    }
    if (!preg_match('/^\s*sitemap\s*:/im', $custom)) {
        $custom .= "\n\nSitemap: " . absolute_url('sitemap.xml');
    }
    return $custom . "\n";
}

/** Allow/Disallow rules that apply to every crawler ("User-agent: *") as [[allow(bool), pattern], …]. */
function seo_robots_rules(?string $txt = null): array
{
    $txt = $txt ?? seo_robots_txt();
    $rules = [];
    $agents = [];
    $inGroup = false;
    foreach (preg_split('/\r?\n/', $txt) as $line) {
        $line = trim(explode('#', $line, 2)[0]);
        if ($line === '' || !str_contains($line, ':')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode(':', $line, 2));
        $k = strtolower($k);
        if ($k === 'user-agent') {
            if ($inGroup) {
                $agents = [];
                $inGroup = false;
            }
            $agents[] = strtolower($v);
            continue;
        }
        if (in_array($k, ['allow', 'disallow'], true)) {
            $inGroup = true;
            if (in_array('*', $agents, true) && $v !== '') {
                $rules[] = [$k === 'allow', $v];
            }
        }
    }
    return $rules;
}

/** Is a path (+ query) crawlable under the rules? Longest matching pattern wins; Allow wins a tie. */
function seo_robots_allowed(string $pathWithQuery, array $rules): bool
{
    $best = -1;
    $allowed = true;
    $target = base_path() . $pathWithQuery;
    foreach ($rules as [$allow, $pattern]) {
        $re = '#^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '#')) . '#';
        if (preg_match($re, $target)) {
            $len = strlen($pattern);
            if ($len > $best || ($len === $best && $allow)) {
                $best = $len;
                $allowed = $allow;
            }
        }
    }
    return $allowed;
}

/* =====================================================================================================================
 * 6. Sitemaps: /sitemap.xml (index) + /sitemap-{pages|programs|posts|events|notices|galleries|images}.xml
 * ===================================================================================================================*/

/** Child sitemaps: name => [label, types from cms_sitemap_urls()]. */
function seo_sitemap_group_defs(): array
{
    return [
        'pages' => ['Pages', ['route', 'page']],
        'programs' => ['Programs', ['program']],
        'posts' => ['News & blog', ['post', 'blog_category']],
        'events' => ['Events', ['event']],
        'notices' => ['Notices', ['notice']],
        'galleries' => ['Photo albums', ['gallery']],
    ];
}

/** Indexable URLs grouped by child sitemap (cached per request). Empty while indexing is switched off. */
function seo_sitemap_groups(): array
{
    static $groups = null;
    if ($groups !== null) {
        return $groups;
    }
    $groups = array_fill_keys(array_keys(seo_sitemap_group_defs()), []);
    if (setting('seo_block_indexing', '0') === '1') {
        return $groups;
    }
    require_once APP_ROOT . '/app/services/cms.php';
    $byType = [];
    foreach (seo_sitemap_group_defs() as $g => [, $types]) {
        foreach ($types as $t) {
            $byType[$t] = $g;
        }
    }
    foreach (cms_sitemap_urls() as $u) {
        $groups[$byType[$u['type']] ?? 'pages'][] = $u;
    }
    return $groups;
}

/** Images per indexable URL for sitemap-images.xml: path => [[loc, title], …] (max 1000 per URL). */
function seo_sitemap_images(): array
{
    if (setting('sitemap_images', '1') !== '1') {
        return [];
    }
    $indexable = [];
    foreach (seo_sitemap_groups() as $list) {
        foreach ($list as $u) {
            $indexable[$u['path']] = $u;
        }
    }
    if (!$indexable) {
        return [];
    }
    $out = [];
    $add = function (string $path, ?string $img, string $title) use (&$out, $indexable) {
        $img = trim((string) $img);
        if ($img === '' || !isset($indexable[$path]) || count($out[$path] ?? []) >= 1000) {
            return;
        }
        $url = seo_abs_url($img); // stored paths are relative to the site root (assets/images/…, assets/uploads/…) or absolute
        $url = $url ? (string) preg_replace('/[?&]v=\d+$/', '', $url) : null;
        if ($url && !isset($out[$path][$url])) {
            $out[$path][$url] = [$url, $title];
        }
    };
    $try = function (string $sql): array {
        try {
            return db_all($sql);
        } catch (Throwable $e) {
            return [];
        }
    };
    require_once APP_ROOT . '/app/services/cms.php';
    foreach ($try("SELECT name, slug, image FROM programs WHERE status = 'active' AND show_on_website = 1 AND image IS NOT NULL AND image <> ''") as $r) {
        $add('/programs/' . $r['slug'], $r['image'], (string) $r['name']);
    }
    foreach ($try("SELECT title, slug, thumbnail FROM blog_posts WHERE status = 'published' AND thumbnail IS NOT NULL AND thumbnail <> ''") as $r) {
        $add('/blog/' . $r['slug'], $r['thumbnail'], (string) $r['title']);
    }
    foreach ($try("SELECT title, slug, image FROM events WHERE show_on_website = 1 AND image IS NOT NULL AND image <> ''") as $r) {
        $add('/events/' . $r['slug'], $r['image'], (string) $r['title']);
    }
    foreach ($try("SELECT g.title, g.slug, g.cover_image, gi.image, gi.caption, m.path AS media_path FROM galleries g LEFT JOIN gallery_items gi ON gi.gallery_id = g.id
                   LEFT JOIN media m ON m.id = gi.media_id WHERE g.status = 'published' ORDER BY g.id, gi.sort_order, gi.id") as $r) {
        $add('/gallery/' . $r['slug'], $r['cover_image'], (string) $r['title']);
        $add('/gallery/' . $r['slug'], $r['image'] ?: $r['media_path'], trim((string) ($r['caption'] ?: $r['title'])));
    }
    foreach ($try("SELECT title, slug, is_system, banner_image FROM pages WHERE status = 'published' AND banner_image IS NOT NULL AND banner_image <> ''") as $r) {
        $add(cms_page_url($r), $r['banner_image'], (string) $r['title']);
    }
    return array_map('array_values', $out);
}

function seo_xml(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Change fingerprint of everything the sitemaps contain (cheap aggregate query) — rebuilt when it changes. */
function seo_sitemap_fingerprint(): string
{
    $parts = [date('Y-m-d H'), setting('seo_block_indexing', '0'), setting('sitemap_images', '1'), setting('landing_indexable', '0'), absolute_url('')];
    foreach (['pages', 'programs', 'blog_posts', 'events', 'notices', 'galleries', 'seo_settings', 'blog_categories'] as $t) {
        try {
            $col = $t === 'blog_categories' ? 'created_at' : 'updated_at';
            $r = db_row("SELECT COUNT(*) n, MAX($col) m FROM $t");
            $parts[] = $t . ':' . ($r['n'] ?? 0) . ':' . ($r['m'] ?? '');
        } catch (Throwable $e) {
            $parts[] = $t . ':-';
        }
    }
    return md5(implode('|', $parts));
}

function seo_sitemap_cache_dir(): string
{
    return STORAGE_PATH . '/cache/seo';
}

/** Cached XML for a sitemap name (index or a child), rebuilt when content changes or after 6 hours. */
function seo_sitemap_cached(string $name, callable $build): string
{
    $dir = seo_sitemap_cache_dir();
    $file = $dir . '/sitemap-' . preg_replace('/[^a-z]/', '', $name) . '.xml';
    $fp = seo_sitemap_fingerprint();
    if (is_file($file) && is_file($file . '.fp') && trim((string) @file_get_contents($file . '.fp')) === $fp && filemtime($file) > time() - 6 * 3600) {
        return (string) file_get_contents($file);
    }
    $xml = $build();
    if (is_dir($dir) || @mkdir($dir, 0775, true)) {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $xml) !== false) {
            @rename($tmp, $file);
            @file_put_contents($file . '.fp', $fp);
        }
    }
    return $xml;
}

function seo_sitemap_cache_clear(): int
{
    $n = 0;
    foreach (glob(seo_sitemap_cache_dir() . '/sitemap-*') ?: [] as $f) {
        if (@unlink($f)) {
            $n++;
        }
    }
    return $n;
}

/** Newest lastmod of a URL list (Y-m-d) or null. */
function seo_sitemap_lastmod(array $urls): ?string
{
    $max = null;
    foreach ($urls as $u) {
        if (!empty($u['lastmod']) && ($max === null || $u['lastmod'] > $max)) {
            $max = $u['lastmod'];
        }
    }
    return $max;
}

/** Child sitemaps that currently have URLs: name => [loc, lastmod, count]. */
function seo_sitemap_children(): array
{
    $out = [];
    foreach (seo_sitemap_groups() as $name => $urls) {
        if ($urls) {
            $out[$name] = ['loc' => absolute_url('sitemap-' . $name . '.xml'), 'lastmod' => seo_sitemap_lastmod($urls) ?? date('Y-m-d'), 'count' => count($urls)];
        }
    }
    $images = seo_sitemap_images();
    if ($images) {
        $out['images'] = ['loc' => absolute_url('sitemap-images.xml'), 'lastmod' => date('Y-m-d'), 'count' => array_sum(array_map('count', $images))];
    }
    return $out;
}

/** /sitemap.xml — sitemap index. */
function seo_sitemap_index_xml(): string
{
    return seo_sitemap_cached('index', function () {
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (seo_sitemap_children() as $c) {
            $x .= '  <sitemap><loc>' . seo_xml($c['loc']) . '</loc><lastmod>' . seo_xml($c['lastmod']) . "</lastmod></sitemap>\n";
        }
        return $x . "</sitemapindex>\n";
    });
}

/** /sitemap-{name}.xml or null for an unknown name. */
function seo_sitemap_xml(string $name): ?string
{
    if ($name === 'images') {
        return seo_sitemap_cached('images', function () {
            $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
            foreach (seo_sitemap_images() as $path => $imgs) {
                $x .= '  <url><loc>' . seo_xml(seo_path_url($path)) . '</loc>';
                foreach ($imgs as [$loc]) {
                    $x .= '<image:image><image:loc>' . seo_xml($loc) . '</image:loc></image:image>';
                }
                $x .= "</url>\n";
            }
            return $x . "</urlset>\n";
        });
    }
    if (!isset(seo_sitemap_group_defs()[$name])) {
        return null;
    }
    return seo_sitemap_cached($name, function () use ($name) {
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (seo_sitemap_groups()[$name] as $u) {
            $x .= '  <url><loc>' . seo_xml($u['loc']) . '</loc>'
                . (!empty($u['lastmod']) ? '<lastmod>' . seo_xml($u['lastmod']) . '</lastmod>' : '')
                . '<changefreq>' . seo_xml((string) $u['changefreq']) . '</changefreq>'
                . '<priority>' . number_format((float) $u['priority'], 1, '.', '') . "</priority></url>\n";
        }
        return $x . "</urlset>\n";
    });
}

/** Send XML with caching headers (ETag / 304). */
function seo_send_xml(string $xml, int $maxAge = 3600): void
{
    $etag = '"' . md5($xml) . '"';
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=' . $maxAge);
    header('ETag: ' . $etag);
    header('X-Robots-Tag: noindex');
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    echo $xml;
    exit;
}

/* =====================================================================================================================
 * 7. Client (JavaScript) error log — stored in system_logs as "Client …" entries
 * ===================================================================================================================*/

/** True when the request comes from a page of this website (Origin, else Referer). */
function seo_same_origin_request(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $from = (string) ($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($host === '' || $from === '' || $from === 'null') {
        return false;
    }
    $h = strtolower((string) parse_url($from, PHP_URL_HOST));
    $p = parse_url($from, PHP_URL_PORT);
    return $h !== '' && $h . ($p ? ':' . $p : '') === $host;
}

/**
 * Store one report from the website error reporter. $in keys: t (error|rejection|resource), m message, s source,
 * l line, c column, st stack, p page path. Returns ['stored' => bool, 'reason' => ?string].
 */
function seo_client_error_store(array $in): array
{
    $type = in_array($in['t'] ?? '', ['error', 'rejection', 'resource'], true) ? $in['t'] : 'error';
    $clip = fn ($v, int $n) => mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', is_scalar($v) ? (string) $v : '')), 0, $n);
    $msg = $clip($in['m'] ?? '', 500);
    $src = $clip($in['s'] ?? '', 300);
    $stack = $clip($in['st'] ?? '', 2000);
    $page = $clip($in['p'] ?? '', 300);
    if ($msg === '') {
        return ['stored' => false, 'reason' => 'empty'];
    }
    if (($msg === 'Script error.' || $msg === 'Script error') && $src === '') {
        return ['stored' => false, 'reason' => 'opaque'];
    }
    if (preg_match('#^(chrome|moz|safari|safari-web|ms-browser)-extension://#i', $src) || preg_match('#(chrome|moz|safari)-extension://#i', $stack)
        || preg_match('/ResizeObserver loop|Non-Error promise rejection captured|__gCrWeb|webkit-masked-url/i', $msg)) {
        return ['stored' => false, 'reason' => 'noise'];
    }
    if ($page !== '' && !str_starts_with($page, '/')) {
        $page = '';
    }
    // No personal data: query values are redacted except for harmless listing parameters (?email=… → ?email=…redacted)
    if (str_contains($page, '?')) {
        [$pp, $pq] = explode('?', $page, 2);
        parse_str($pq, $params);
        $keep = ['page', 'category', 'tab', 'type', 'view', 'sort', 'level', 'department', 'year', 'month'];
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = rawurlencode((string) $k) . '=' . (in_array($k, $keep, true) && is_scalar($v) ? rawurlencode(mb_substr((string) $v, 0, 40)) : '~');
        }
        $page = $pp . ($parts ? '?' . implode('&', array_slice($parts, 0, 10)) : '');
    }
    $sig = md5($type . '|' . $msg . '|' . $src . '|' . (int) ($in['l'] ?? 0));
    if (!seo_rate_limit('cerr-sig:' . $sig, 1, 600)) {
        return ['stored' => false, 'reason' => 'duplicate'];
    }
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    log_system($type === 'resource' ? 'warning' : 'error', 'Client ' . $type . ': ' . $msg, [
        'channel' => 'client', 'type' => $type, 'source' => $src ?: null, 'line' => (int) ($in['l'] ?? 0) ?: null, 'column' => (int) ($in['c'] ?? 0) ?: null,
        'stack' => $stack ?: null, 'page' => $page ?: null, 'browser' => browser_label($ua), 'user_agent' => mb_substr($ua, 0, 255),
    ]);
    return ['stored' => true, 'reason' => null];
}

/** SQL condition for a log channel: client (website JS), server (PHP / application) or all. */
function seo_error_log_where(string $channel): string
{
    return match ($channel) {
        'client' => "message LIKE 'Client %'",
        'server' => "message NOT LIKE 'Client %'",
        default => '1 = 1',
    };
}

/** Paginated error log: channel client|server|all, level, q, days. */
function seo_error_log_list(array $f, int $page = 1, int $perPage = 25): array
{
    $where = [seo_error_log_where(api_str($f['channel'] ?? null, 'client'))];
    $args = [];
    if (!empty($f['level']) && in_array($f['level'], ['debug', 'info', 'warning', 'error', 'critical'], true)) {
        $where[] = 'level = ?';
        $args[] = $f['level'];
    }
    if (($q = trim(api_str($f['q'] ?? null))) !== '') {
        $where[] = '(message LIKE ? OR context LIKE ?)';
        $args[] = '%' . $q . '%';
        $args[] = '%' . $q . '%';
    }
    if (($days = (int) ($f['days'] ?? 0)) > 0) {
        $where[] = 'created_at >= NOW() - INTERVAL ' . min(3650, $days) . ' DAY';
    }
    $sql = ' FROM system_logs WHERE ' . implode(' AND ', $where);
    $total = (int) db_value('SELECT COUNT(*)' . $sql, $args);
    $p = paginate($total, $page, $perPage);
    $rows = db_all('SELECT id, level, message, context, created_at' . $sql . ' ORDER BY id DESC LIMIT ' . (int) $p['per_page'] . ' OFFSET ' . (int) $p['offset'], $args);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $ctx = json_decode((string) $r['context'], true);
        $r['context'] = is_array($ctx) ? $ctx : null;
        $r['channel'] = str_starts_with((string) $r['message'], 'Client ') ? 'client' : 'server';
    }
    unset($r);
    return ['rows' => $rows, 'total' => $p['total'], 'page' => $p['page'], 'per_page' => $p['per_page'], 'pages' => $p['pages']];
}

/** Error counts for the Integrations tab. */
function seo_error_log_stats(): array
{
    try {
        $r = db_row("SELECT SUM(created_at >= NOW() - INTERVAL 1 DAY) d1, SUM(created_at >= NOW() - INTERVAL 7 DAY) d7, COUNT(*) total, MAX(created_at) last
                     FROM system_logs WHERE message LIKE 'Client %'");
        $s = db_row("SELECT SUM(created_at >= NOW() - INTERVAL 7 DAY) d7 FROM system_logs WHERE message NOT LIKE 'Client %' AND level IN ('error', 'critical')");
    } catch (Throwable $e) {
        return ['client_24h' => 0, 'client_7d' => 0, 'client_total' => 0, 'client_last' => null, 'server_7d' => 0];
    }
    return ['client_24h' => (int) ($r['d1'] ?? 0), 'client_7d' => (int) ($r['d7'] ?? 0), 'client_total' => (int) ($r['total'] ?? 0), 'client_last' => $r['last'] ?? null,
        'server_7d' => (int) ($s['d7'] ?? 0)];
}

/* =====================================================================================================================
 * 8. SEO audit — crawls the site's own URLs (sitemap + internal links), capped and resumable
 * ===================================================================================================================*/

/** Issue code => [label, severity error|warning|notice]. */
function seo_audit_issue_defs(): array
{
    return [
        'http_4xx' => ['Not found / client error (4xx)', 'error'],
        'http_5xx' => ['Server error (5xx)', 'error'],
        'fetch_failed' => ['Could not be fetched (timeout or connection error)', 'error'],
        'broken_links' => ['Links to broken internal URLs', 'error'],
        'noindex_in_sitemap' => ['noindex page listed in the sitemap', 'error'],
        'title_missing' => ['Missing page title', 'error'],
        'very_slow' => ['Very slow response (over 4 s)', 'error'],
        'sitemap_invalid' => ['Sitemap is not valid XML', 'error'],
        'redirect_in_sitemap' => ['Sitemap URL redirects', 'warning'],
        'title_duplicate' => ['Duplicate title', 'warning'],
        'title_long' => ['Title longer than 60 characters', 'warning'],
        'description_missing' => ['Missing meta description', 'warning'],
        'description_duplicate' => ['Duplicate meta description', 'warning'],
        'h1_missing' => ['Missing H1 heading', 'warning'],
        'img_alt_missing' => ['Images without alt text', 'warning'],
        'canonical_elsewhere' => ['Sitemap URL whose canonical points elsewhere', 'warning'],
        'slow' => ['Slow response (over 1.5 s)', 'warning'],
        'mixed_content' => ['Insecure http:// resources on an https page', 'warning'],
        'robots_no_sitemap' => ['robots.txt has no Sitemap line', 'warning'],
        'redirect' => ['URL redirects', 'notice'],
        'links_to_redirect' => ['Links to URLs that redirect permanently', 'notice'],
        'title_short' => ['Title shorter than 20 characters', 'notice'],
        'description_long' => ['Meta description longer than 160 characters', 'notice'],
        'description_short' => ['Meta description shorter than 70 characters', 'notice'],
        'h1_multiple' => ['More than one H1 heading', 'notice'],
        'canonical_missing' => ['No canonical URL', 'notice'],
        'not_in_sitemap' => ['Indexable page missing from the sitemap', 'notice'],
        'og_image_missing' => ['No social share image', 'notice'],
        'og_image_small' => ['Social share image narrower than 600 px (1200 × 630 recommended)', 'notice'],
        'heavy' => ['HTML larger than 1.5 MB', 'notice'],
        'lang_missing' => ['No lang attribute on <html>', 'notice'],
    ];
}

/** Background worker mode? (the PHP built-in dev server is single-threaded: it cannot answer its own crawl requests) */
function seo_audit_mode(): string
{
    return PHP_SAPI === 'cli-server' && (function_exists('popen') || function_exists('exec')) && seo_php_cli_binary() ? 'worker' : 'inline';
}

/** PHP CLI binary able to run a script (the running binary for php -S / CLI), or null. */
function seo_php_cli_binary(): ?string
{
    $bin = PHP_BINARY;
    return $bin !== '' && is_file($bin) && preg_match('/^php(\d+(\.\d+)?)?(\.exe)?$/i', basename($bin)) ? $bin : null;
}

/** Start the background worker for an audit (detached). */
function seo_audit_spawn(int $id): bool
{
    $php = seo_php_cli_binary();
    if (!$php) {
        return false;
    }
    $args = escapeshellarg(__FILE__) . ' audit-worker ' . $id;
    try {
        if (DIRECTORY_SEPARATOR === '\\') {
            if (!function_exists('popen')) {
                return false;
            }
            $h = @popen('start "" /B ' . escapeshellarg($php) . ' ' . $args . ' > NUL 2>&1', 'r');
            if (!$h) {
                return false;
            }
            pclose($h);
            return true;
        }
        if (!function_exists('exec')) {
            return false;
        }
        @exec('nohup ' . escapeshellarg($php) . ' ' . $args . ' > /dev/null 2>&1 &', $o, $rc);
        return $rc === 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** Running (or queued) audit, if any. Runs without a heartbeat for 3 minutes are marked failed. */
function seo_audit_active(): ?array
{
    $a = db_row("SELECT * FROM seo_audits WHERE status IN ('queued', 'running') ORDER BY id DESC LIMIT 1");
    if ($a && strtotime((string) ($a['heartbeat_at'] ?: $a['created_at'])) < time() - 180) {
        if ($a['mode'] === 'inline') {
            return $a; // paused (browser closed) — can be resumed
        }
        db_update('seo_audits', ['status' => 'failed', 'message' => 'Stopped: the background crawler made no progress for 3 minutes.', 'finished_at' => date('Y-m-d H:i:s')], 'id = ?', [$a['id']]);
        return null;
    }
    return $a;
}

/** Normalise a link found on a page into a site path (+query) and kind, or null when it is out of scope. */
function seo_audit_link(string $href, string $pagePath, string $baseUrl): ?array
{
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '' || $href[0] === '#' || preg_match('#^(mailto|tel|sms|javascript|data|whatsapp|intent|geo):#i', $href)) {
        return null;
    }
    $origin = (string) preg_replace('#^(https?://[^/]+).*$#i', '$1', $baseUrl);
    $basePath = rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/');
    if (str_starts_with($href, '//')) {
        $href = (str_starts_with($origin, 'https') ? 'https:' : 'http:') . $href;
    }
    if (preg_match('#^https?://#i', $href)) {
        if (stripos($href . '/', $origin . '/') !== 0) {
            return null; // external
        }
        $href = substr($href, strlen($origin)) ?: '/';
    } elseif ($href[0] !== '/') {
        $dir = preg_replace('#/[^/]*$#', '/', explode('?', $pagePath, 2)[0]);
        $href = $basePath . $dir . $href;
    }
    $href = explode('#', $href, 2)[0];
    [$path, $query] = array_pad(explode('?', $href, 2), 2, '');
    // resolve ./ and ../
    $segs = [];
    foreach (explode('/', $path) as $s) {
        if ($s === '..') {
            array_pop($segs);
        } elseif ($s !== '.') {
            $segs[] = $s;
        }
    }
    $path = '/' . ltrim(implode('/', $segs), '/');
    if ($basePath !== '' && (stripos($path, $basePath . '/') === 0 || strcasecmp($path, $basePath) === 0)) {
        $path = substr($path, strlen($basePath)) ?: '/';
    }
    if (preg_match('#^/(admin|api|portal|storage|cdn-cgi)(/|$)#i', $path)) {
        return null;
    }
    $isFile = str_starts_with($path, '/assets/') || preg_match('/\.(pdf|docx?|xlsx?|pptx?|zip|csv|txt|jpe?g|png|webp|gif|svg|avif|mp4|webm|mp3|ics)$/i', $path);
    $out = $path . ($query !== '' ? '?' . $query : '');
    return mb_strlen($out) <= 480 ? ['path' => $out, 'kind' => $isFile ? 'file' : 'page'] : null;
}

/** Queue URLs for an audit (duplicates ignored, total capped at max_pages). Returns the number added. */
function seo_audit_enqueue(array $audit, array $items): int
{
    if (!$items) {
        return 0;
    }
    $room = (int) $audit['max_pages'] - (int) db_value("SELECT COUNT(*) FROM seo_audit_pages WHERE audit_id = ? AND state <> 'skipped' AND kind <> 'seo'", [$audit['id']]);
    $rows = [];
    $seen = [];
    foreach ($items as $it) {
        $hash = sha1($it['path']);
        if (isset($seen[$hash])) {
            continue;
        }
        $seen[$hash] = true;
        $skipped = !empty($it['skipped']);
        if (!$skipped && ($it['kind'] ?? 'page') !== 'seo') {
            if ($room <= 0) {
                continue;
            }
        }
        $rows[] = [$audit['id'], rtrim($audit['base_url'], '/') . seo_encode_path(explode('?', $it['path'], 2)[0]) . (str_contains($it['path'], '?') ? '?' . explode('?', $it['path'], 2)[1] : ''),
            $it['path'], $hash, $it['kind'] ?? 'page', $it['source'] ?? 'link', !empty($it['in_sitemap']) ? 1 : 0, min(255, (int) ($it['depth'] ?? 0)),
            isset($it['found_on']) ? mb_substr((string) $it['found_on'], 0, 500) : null, $skipped ? 'skipped' : 'queued', $skipped ? ($it['error'] ?? null) : null];
    }
    $added = 0;
    foreach (array_chunk($rows, 100) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?,?)'));
        $args = array_merge(...$chunk);
        $n = db_exec("INSERT IGNORE INTO seo_audit_pages (audit_id, url, path, path_hash, kind, source, in_sitemap, depth, found_on, state, error) VALUES $placeholders", $args);
        $added += $n;
    }
    // Room check is approximate per batch; trim any overflow beyond the cap (keeps the earliest rows).
    $over = (int) db_value("SELECT COUNT(*) FROM seo_audit_pages WHERE audit_id = ? AND state = 'queued' AND kind <> 'seo'", [$audit['id']])
        + (int) db_value("SELECT COUNT(*) FROM seo_audit_pages WHERE audit_id = ? AND state = 'done' AND kind <> 'seo'", [$audit['id']]) - (int) $audit['max_pages'];
    if ($over > 0) {
        db_exec("DELETE FROM seo_audit_pages WHERE audit_id = ? AND state = 'queued' AND kind <> 'seo' ORDER BY id DESC LIMIT " . (int) $over, [$audit['id']]);
    }
    return $added;
}

/**
 * Start an audit (or return the one that is already running). Seeds robots.txt, sitemap.xml, the home page and every
 * sitemap URL; then starts the background worker (dev server) or waits for the admin browser to drive the steps.
 */
function seo_audit_start(int $maxPages = 250, ?int $userId = null): array
{
    if ($active = seo_audit_active()) {
        return ['audit' => $active, 'existing' => true];
    }
    require_once APP_ROOT . '/app/services/cms.php';
    $maxPages = max(20, min(1000, $maxPages));
    $mode = seo_audit_mode();
    $id = db_insert('seo_audits', ['status' => 'running', 'mode' => $mode, 'base_url' => rtrim(absolute_url(''), '/'), 'max_pages' => $maxPages,
        'started_by' => $userId, 'started_at' => date('Y-m-d H:i:s'), 'heartbeat_at' => date('Y-m-d H:i:s')]);
    $audit = db_row('SELECT * FROM seo_audits WHERE id = ?', [$id]);
    $items = [['path' => '/robots.txt', 'kind' => 'seo', 'source' => 'start'], ['path' => '/sitemap.xml', 'kind' => 'seo', 'source' => 'start'],
        ['path' => '/', 'kind' => 'page', 'source' => 'start', 'in_sitemap' => 1]];
    foreach (cms_sitemap_urls() as $u) {
        $items[] = ['path' => (string) $u['path'], 'kind' => 'page', 'source' => 'sitemap', 'in_sitemap' => 1];
    }
    seo_audit_enqueue($audit, $items);
    // URLs listed in the sitemap but also reached first as "/" are still flagged in_sitemap
    db_exec("UPDATE seo_audits SET pages_found = (SELECT COUNT(*) FROM seo_audit_pages WHERE audit_id = ?) WHERE id = ?", [$id, $id]);
    if ($mode === 'worker' && !seo_audit_spawn($id)) {
        db_update('seo_audits', ['mode' => 'inline'], 'id = ?', [$id]);
    }
    // keep the 10 most recent audits
    $old = db_column('SELECT id FROM seo_audits ORDER BY id DESC LIMIT 1000 OFFSET 10');
    if ($old) {
        db_exec('DELETE FROM seo_audits WHERE id IN (' . implode(',', array_map('intval', $old)) . ')');
    }
    return ['audit' => db_row('SELECT * FROM seo_audits WHERE id = ?', [$id]), 'existing' => false];
}

/**
 * Fetch a batch of URLs. $reqs: [key => ['url', 'method' => GET|HEAD]]. Parallel with curl_multi when $concurrency > 1.
 * Returns key => [status, ms, bytes, type, location, body, error, truncated].
 */
function seo_http_fetch_many(array $reqs, int $concurrency = 1): array
{
    $out = [];
    if (!function_exists('curl_init')) {
        foreach ($reqs as $k => $r) {
            $out[$k] = ['status' => 0, 'ms' => 0, 'bytes' => 0, 'type' => '', 'location' => null, 'body' => '', 'error' => 'The PHP curl extension is not available.', 'truncated' => false];
        }
        return $out;
    }
    $make = function (array $r, array &$buf, string $key) {
        $ch = curl_init($r['url']);
        $buf[$key] = ['body' => '', 'headers' => [], 'truncated' => false];
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 25, CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; GIMT-SEO-Audit/1.0; +' . absolute_url('') . ')',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: en-IN,en;q=0.9'],
            CURLOPT_NOBODY => ($r['method'] ?? 'GET') === 'HEAD',
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$buf, $key) {
                if (str_contains($line, ':')) {
                    [$n, $v] = explode(':', $line, 2);
                    $buf[$key]['headers'][strtolower(trim($n))] = trim($v);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$buf, $key) {
                if (strlen($buf[$key]['body']) > 3 * 1024 * 1024) {
                    $buf[$key]['truncated'] = true;
                    return -1;
                }
                $buf[$key]['body'] .= $data;
                return strlen($data);
            },
        ]);
        return $ch;
    };
    $buf = [];
    $result = function ($ch, string $key, ?string $err) use (&$buf) {
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $truncated = $buf[$key]['truncated'];
        return ['status' => $status, 'ms' => (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000), 'bytes' => strlen($buf[$key]['body']),
            'type' => strtolower((string) ($buf[$key]['headers']['content-type'] ?? '')), 'location' => $buf[$key]['headers']['location'] ?? null,
            'body' => $buf[$key]['body'], 'error' => $status ? null : ($err ?: 'No response'), 'truncated' => $truncated];
    };
    foreach (array_chunk($reqs, max(1, $concurrency), true) as $chunk) {
        if (count($chunk) === 1 || $concurrency <= 1) {
            foreach ($chunk as $k => $r) {
                $ch = $make($r, $buf, (string) $k);
                curl_exec($ch);
                $out[$k] = $result($ch, (string) $k, curl_errno($ch) && !$buf[(string) $k]['truncated'] ? curl_error($ch) : null);
                curl_close($ch);
            }
            continue;
        }
        $mh = curl_multi_init();
        $handles = [];
        foreach ($chunk as $k => $r) {
            $handles[$k] = $make($r, $buf, (string) $k);
            curl_multi_add_handle($mh, $handles[$k]);
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $st === CURLM_OK);
        foreach ($handles as $k => $ch) {
            $err = curl_error($ch);
            $out[$k] = $result($ch, (string) $k, $err !== '' && !$buf[(string) $k]['truncated'] ? $err : null);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    return $out;
}

/** Parse an HTML page: title, description, robots, canonical, og:image, lang, h1s, images, links, mixed content. */
function seo_audit_parse_html(string $html, bool $https): array
{
    $out = ['title' => null, 'description' => null, 'robots' => null, 'canonical' => null, 'og_image' => null, 'lang' => null, 'h1' => [],
        'images' => 0, 'images_no_alt' => 0, 'links' => [], 'mixed' => 0];
    if (!class_exists('DOMDocument')) {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $out['title'] = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        preg_match_all('#<a\s[^>]*href=["\']([^"\']+)["\']#i', $html, $m);
        $out['links'] = $m[1] ?? [];
        return $out;
    }
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $xp = new DOMXPath($doc);
    $text = fn ($n) => trim((string) preg_replace('/\s+/u', ' ', $n->textContent ?? ''));
    $lower = "translate(%s,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')";
    if (($n = $xp->query('//head/title|//title')->item(0))) {
        $out['title'] = $text($n);
    }
    $meta = function (string $attr, string $value) use ($xp, $lower): ?string {
        $n = $xp->query('//meta[' . sprintf($lower, '@' . $attr) . "='" . $value . "']/@content")->item(0);
        return $n ? trim((string) $n->nodeValue) : null;
    };
    $out['description'] = $meta('name', 'description');
    $out['robots'] = $meta('name', 'robots');
    $out['og_image'] = $meta('property', 'og:image');
    $out['og_image_width'] = (int) ($meta('property', 'og:image:width') ?? 0) ?: null;
    if (($n = $xp->query('//link[' . sprintf($lower, '@rel') . "='canonical']/@href")->item(0))) {
        $out['canonical'] = trim((string) $n->nodeValue);
    }
    if (($n = $xp->query('/html/@lang')->item(0))) {
        $out['lang'] = trim((string) $n->nodeValue);
    }
    foreach ($xp->query('//h1') as $h) {
        $out['h1'][] = $text($h);
    }
    foreach ($xp->query('//img') as $img) {
        /** @var DOMElement $img */
        if ($img->getAttribute('aria-hidden') === 'true' || in_array($img->getAttribute('role'), ['presentation', 'none'], true)
            || ($img->getAttribute('width') === '1' && $img->getAttribute('height') === '1')) {
            continue;
        }
        $out['images']++;
        if (!$img->hasAttribute('alt')) {
            $out['images_no_alt']++;
        }
    }
    foreach ($xp->query('//a[@href]') as $a) {
        /** @var DOMElement $a */
        $out['links'][] = $a->getAttribute('href');
    }
    if ($https) {
        $out['mixed'] = $xp->query("//*[starts-with(@src,'http:')]|//link[" . sprintf($lower, '@rel') . "='stylesheet'][starts-with(@href,'http:')]")->length;
    }
    return $out;
}

/** Crawl the next queued URLs of an audit for about $budget seconds. Returns the audit status payload. */
function seo_audit_step(int $id, float $budget = 8.0): ?array
{
    $audit = db_row('SELECT * FROM seo_audits WHERE id = ?', [$id]);
    if (!$audit || !in_array($audit['status'], ['running', 'queued'], true)) {
        return $audit ? seo_audit_status($id) : null;
    }
    require_once APP_ROOT . '/app/services/cms.php';
    @set_time_limit((int) ceil($budget) + 60);
    $t0 = microtime(true);
    // Web requests crawl 2 URLs at a time: each one occupies a PHP worker of this same server (small FPM pools).
    $concurrency = PHP_SAPI === 'cli' ? 1 : 2;
    $rules = seo_robots_rules();
    $https = str_starts_with(strtolower((string) $audit['base_url']), 'https://');
    while (microtime(true) - $t0 < $budget) {
        $batch = db_all("SELECT * FROM seo_audit_pages WHERE audit_id = ? AND state = 'queued' ORDER BY (kind = 'seo') DESC, depth, id LIMIT " . $concurrency, [$id]);
        if (!$batch) {
            seo_audit_finalize($id);
            break;
        }
        $reqs = [];
        foreach ($batch as $row) {
            $reqs[$row['id']] = ['url' => $row['url'], 'method' => $row['kind'] === 'file' ? 'HEAD' : 'GET'];
        }
        $responses = seo_http_fetch_many($reqs, $concurrency);
        foreach ($batch as $row) {
            seo_audit_process($audit, $row, $responses[$row['id']] ?? ['status' => 0, 'error' => 'No response', 'ms' => 0, 'bytes' => 0, 'type' => '', 'location' => null, 'body' => '', 'truncated' => false], $rules, $https);
        }
        db_exec("UPDATE seo_audits SET pages_crawled = (SELECT COUNT(*) FROM seo_audit_pages WHERE audit_id = ? AND state = 'done'),
                 pages_found = (SELECT COUNT(*) FROM seo_audit_pages WHERE audit_id = ?), heartbeat_at = NOW(), status = IF(status = 'queued', 'running', status) WHERE id = ?", [$id, $id, $id]);
        if (db_value('SELECT status FROM seo_audits WHERE id = ?', [$id]) !== 'running') {
            break;
        }
    }
    return seo_audit_status($id);
}

/** Store the result of one fetched URL and queue the internal links found on it. */
function seo_audit_process(array $audit, array $row, array $res, array $rules, bool $https): void
{
    $issues = [];
    $status = (int) ($res['status'] ?? 0);
    $upd = ['state' => 'done', 'status_code' => $status ?: null, 'response_ms' => (int) ($res['ms'] ?? 0), 'bytes' => (int) ($res['bytes'] ?? 0),
        'content_type' => mb_substr((string) ($res['type'] ?? ''), 0, 100) ?: null, 'crawled_at' => date('Y-m-d H:i:s'), 'error' => $res['error'] ? mb_substr((string) $res['error'], 0, 255) : null];
    if (!$status) {
        $issues[] = 'fetch_failed';
    } elseif ($status >= 500) {
        $issues[] = 'http_5xx';
    } elseif ($status >= 400 && !($row['kind'] === 'file' && $status === 405)) {
        $issues[] = 'http_4xx';
    } elseif ($status >= 300 && $status < 400) {
        $issues[] = $row['in_sitemap'] ? 'redirect_in_sitemap' : 'redirect';
        $loc = (string) ($res['location'] ?? '');
        $upd['redirect_to'] = mb_substr($loc, 0, 1000) ?: null;
        if ($loc !== '' && ($l = seo_audit_link($loc, $row['path'], $audit['base_url']))) {
            seo_audit_enqueue($audit, [['path' => $l['path'], 'kind' => $l['kind'], 'source' => 'link', 'depth' => (int) $row['depth'], 'found_on' => $row['path']]]);
        }
    }
    if ($row['kind'] === 'seo' && $status === 200) {
        $body = (string) ($res['body'] ?? '');
        if (str_ends_with($row['path'], '.txt')) {
            if (!preg_match('/^\s*sitemap\s*:/im', $body)) {
                $issues[] = 'robots_no_sitemap';
            }
            $upd['title'] = 'robots.txt (' . count(array_filter(preg_split('/\r?\n/', $body), fn ($l) => trim($l) !== '' && trim($l)[0] !== '#')) . ' rules)';
        } else {
            $prev = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            if (!$xml) {
                $issues[] = 'sitemap_invalid';
            } else {
                $children = [];
                $count = 0;
                foreach ($xml->children() as $child) {
                    $count++;
                    if ($child->getName() === 'sitemap' && ($l = seo_audit_link((string) $child->loc, $row['path'], $audit['base_url']))) {
                        $children[] = ['path' => $l['path'], 'kind' => 'seo', 'source' => 'sitemap', 'found_on' => $row['path']];
                    }
                }
                $upd['title'] = ($xml->getName() === 'sitemapindex' ? 'Sitemap index' : 'Sitemap') . ' (' . $count . ' ' . ($xml->getName() === 'sitemapindex' ? 'sitemaps' : 'URLs') . ')';
                seo_audit_enqueue($audit, $children);
            }
        }
    }
    if ($row['kind'] === 'page' && $status === 200 && str_contains((string) ($res['type'] ?? ''), 'html')) {
        $p = seo_audit_parse_html((string) $res['body'], $https);
        $title = $p['title'];
        $desc = $p['description'];
        $upd += ['title' => $title !== null ? mb_substr($title, 0, 500) : null, 'description' => $desc !== null ? mb_substr($desc, 0, 1000) : null,
            'h1' => $p['h1'] ? mb_substr($p['h1'][0], 0, 500) : null, 'h1_count' => count($p['h1']), 'images' => min(65535, $p['images']),
            'images_no_alt' => min(65535, $p['images_no_alt']), 'canonical' => $p['canonical'] ? mb_substr($p['canonical'], 0, 1000) : null,
            'robots' => $p['robots'] ? mb_substr($p['robots'], 0, 100) : null];
        if ($title === null || $title === '') {
            $issues[] = 'title_missing';
        } elseif (mb_strlen($title) > 60) {
            $issues[] = 'title_long';
        } elseif (mb_strlen($title) < 20) {
            $issues[] = 'title_short';
        }
        if ($desc === null || $desc === '') {
            $issues[] = 'description_missing';
        } elseif (mb_strlen($desc) > 160) {
            $issues[] = 'description_long';
        } elseif (mb_strlen($desc) < 70) {
            $issues[] = 'description_short';
        }
        if (!$p['h1']) {
            $issues[] = 'h1_missing';
        } elseif (count($p['h1']) > 1) {
            $issues[] = 'h1_multiple';
        }
        if ($p['images_no_alt'] > 0) {
            $issues[] = 'img_alt_missing';
        }
        $noindex = $p['robots'] && str_contains(strtolower($p['robots']), 'noindex');
        if ($noindex && $row['in_sitemap']) {
            $issues[] = 'noindex_in_sitemap';
        }
        if (!$p['canonical'] && !$noindex) {
            $issues[] = 'canonical_missing';
        } elseif ($p['canonical'] && $row['in_sitemap']) {
            $c = seo_audit_link($p['canonical'], $row['path'], $audit['base_url']);
            if (!$c || rtrim($c['path'], '/') !== rtrim($row['path'], '/')) {
                $issues[] = 'canonical_elsewhere';
            }
        }
        if (!$p['og_image']) {
            $issues[] = 'og_image_missing';
        } elseif (($p['og_image_width'] ?? null) !== null && $p['og_image_width'] < 600) {
            $issues[] = 'og_image_small';
        }
        if (!$p['lang']) {
            $issues[] = 'lang_missing';
        }
        if ($p['mixed'] > 0) {
            $issues[] = 'mixed_content';
        }
        if (!empty($res['truncated']) || (int) $res['bytes'] > 1.5 * 1024 * 1024) {
            $issues[] = 'heavy';
        }
        $links = [];
        $queue = [];
        foreach ($p['links'] as $href) {
            $l = seo_audit_link((string) $href, $row['path'], $audit['base_url']);
            if (!$l || isset($links[$l['path']])) {
                continue;
            }
            $links[$l['path']] = true;
            $allowed = $l['kind'] === 'file' || seo_robots_allowed($l['path'], $rules);
            $queue[] = ['path' => $l['path'], 'kind' => $l['kind'], 'source' => 'link', 'depth' => (int) $row['depth'] + 1, 'found_on' => $row['path'],
                'skipped' => !$allowed, 'error' => $allowed ? null : 'Blocked by robots.txt'];
            if (count($links) >= 400) {
                break;
            }
        }
        $upd['links'] = json_encode(array_keys($links), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        seo_audit_enqueue($audit, $queue);
    }
    $ms = (int) ($res['ms'] ?? 0);
    if ($status && $row['kind'] === 'page') {
        if ($ms > 4000) {
            $issues[] = 'very_slow';
        } elseif ($ms > 1500) {
            $issues[] = 'slow';
        }
    }
    $upd['issues'] = json_encode(array_values(array_unique($issues)));
    db_update('seo_audit_pages', $upd, 'id = ?', [$row['id']]);
}

/** Cross-page checks (duplicates, broken links, sitemap coverage) + summary. */
function seo_audit_finalize(int $id): void
{
    $audit = db_row('SELECT * FROM seo_audits WHERE id = ?', [$id]);
    if (!$audit || $audit['status'] === 'completed') {
        return;
    }
    $rows = db_all("SELECT id, path, kind, source, in_sitemap, state, status_code, redirect_to, response_ms, title, description, robots, canonical, links, issues FROM seo_audit_pages WHERE audit_id = ?", [$id]);
    $byPath = [];
    $crossPage = ['title_duplicate', 'description_duplicate', 'broken_links', 'links_to_redirect', 'not_in_sitemap'];
    foreach ($rows as &$r) {
        // per-page issues from the crawl; cross-page issues are (re)computed below, so finalising twice is safe
        $r['issues'] = array_values(array_diff(json_decode((string) $r['issues'], true) ?: [], $crossPage));
        $byPath[$r['path']] = &$r;
    }
    unset($r);
    // Indexable pages: 200, not noindex, canonical = itself (query variants consolidated by their canonical are not duplicates)
    $selfCanonical = function (array $r) use ($audit): bool {
        if (!$r['canonical']) {
            return true;
        }
        $c = seo_audit_link((string) $r['canonical'], $r['path'], $audit['base_url']);
        return $c && rtrim($c['path'], '/') === rtrim($r['path'], '/');
    };
    $html = array_filter($rows, fn ($r) => $r['kind'] === 'page' && (int) $r['status_code'] === 200 && !str_contains(strtolower((string) $r['robots']), 'noindex') && $selfCanonical($r));
    $dupes = ['title' => [], 'description' => []];
    foreach (['title', 'description'] as $field) {
        $groups = [];
        foreach ($html as $r) {
            if (preg_match('/[?&]page=([2-9]|\d{2,})/', $r['path'])) {
                continue; // later pages of a paginated listing may repeat the first page's description
            }
            $v = mb_strtolower(trim((string) $r[$field]));
            if ($v !== '') {
                $groups[$v][] = $r['path'];
            }
        }
        foreach ($groups as $v => $paths) {
            if (count($paths) > 1) {
                $dupes[$field][] = ['value' => (string) $byPath[$paths[0]][$field], 'paths' => array_slice($paths, 0, 20), 'count' => count($paths)];
                foreach ($paths as $p) {
                    $byPath[$p]['issues'][] = $field . '_duplicate';
                }
            }
        }
    }
    $broken = [];
    foreach ($rows as $r) {
        if ($r['kind'] !== 'page' || !$r['links']) {
            continue;
        }
        $links = json_decode((string) $r['links'], true) ?: [];
        $nBroken = 0;
        $nRedirect = 0;
        foreach ($links as $target) {
            $t = $byPath[$target] ?? null;
            if (!$t || $t['state'] !== 'done') {
                continue;
            }
            $code = (int) $t['status_code'];
            if ($code === 0 || $code >= 400) {
                if ($t['kind'] === 'file' && $code === 405) {
                    continue;
                }
                $nBroken++;
                $broken[$target]['status'] = $code;
                $broken[$target]['sources'][] = $r['path'];
            } elseif (in_array($code, [301, 308], true)) {
                $nRedirect++; // permanent: the link should point at the new URL (302/307 gateways such as portal logins are intentional)
            }
        }
        if ($nBroken) {
            $byPath[$r['path']]['issues'][] = 'broken_links';
        }
        if ($nRedirect) {
            $byPath[$r['path']]['issues'][] = 'links_to_redirect';
        }
    }
    foreach ($html as $r) {
        if (!$r['in_sitemap'] && !str_contains($r['path'], '?') && $r['path'] !== '/') {
            $byPath[$r['path']]['issues'][] = 'not_in_sitemap';
        }
    }
    $defs = seo_audit_issue_defs();
    $issueCounts = [];
    $errors = 0;
    $warnings = 0;
    foreach ($rows as $r) {
        $iss = array_values(array_unique($byPath[$r['path']]['issues']));
        db_exec('UPDATE seo_audit_pages SET issues = ? WHERE id = ?', [json_encode($iss), $r['id']]);
        $sev = array_map(fn ($c) => $defs[$c][1] ?? 'notice', $iss);
        $errors += in_array('error', $sev, true) ? 1 : 0;
        $warnings += in_array('warning', $sev, true) ? 1 : 0;
        foreach ($iss as $c) {
            $issueCounts[$c] = ($issueCounts[$c] ?? 0) + 1;
        }
    }
    $done = array_filter($rows, fn ($r) => $r['state'] === 'done');
    $times = array_values(array_filter(array_map(fn ($r) => $r['kind'] === 'page' && $r['status_code'] ? (int) $r['response_ms'] : null, $done), fn ($v) => $v !== null));
    sort($times);
    $pct = fn (float $q) => $times ? $times[min(count($times) - 1, (int) floor($q * (count($times) - 1)))] : 0;
    $statusClasses = ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'failed' => 0];
    foreach ($done as $r) {
        $c = (int) $r['status_code'];
        $statusClasses[$c === 0 ? 'failed' : ($c >= 500 ? '5xx' : ($c >= 400 ? '4xx' : ($c >= 300 ? '3xx' : '2xx')))]++;
    }
    $slowest = array_values(array_filter($done, fn ($r) => $r['kind'] === 'page' && $r['status_code']));
    usort($slowest, fn ($a, $b) => (int) $b['response_ms'] <=> (int) $a['response_ms']);
    $brokenList = [];
    foreach ($broken as $target => $b) {
        $brokenList[] = ['path' => $target, 'status' => $b['status'], 'sources' => array_slice(array_values(array_unique($b['sources'])), 0, 8), 'count' => count(array_unique($b['sources']))];
    }
    usort($brokenList, fn ($a, $b) => $b['count'] <=> $a['count']);
    $queuedLeft = (int) db_value("SELECT COUNT(*) FROM seo_audit_pages WHERE audit_id = ? AND state = 'queued'", [$id]);
    $summary = [
        'issues' => $issueCounts,
        'status' => $statusClasses,
        'pages' => count(array_filter($done, fn ($r) => $r['kind'] === 'page')),
        'files' => count(array_filter($done, fn ($r) => $r['kind'] === 'file')),
        'skipped' => count(array_filter($rows, fn ($r) => $r['state'] === 'skipped')),
        'sitemap_urls' => count(array_filter($rows, fn ($r) => (int) $r['in_sitemap'] === 1)),
        'response' => ['avg' => $times ? (int) round(array_sum($times) / count($times)) : 0, 'median' => $pct(0.5), 'p95' => $pct(0.95), 'max' => $times ? end($times) : 0],
        'slowest' => array_map(fn ($r) => ['path' => $r['path'], 'ms' => (int) $r['response_ms'], 'title' => $r['title']], array_slice($slowest, 0, 10)),
        'broken' => array_slice($brokenList, 0, 200),
        'duplicates' => ['title' => array_slice($dupes['title'], 0, 50), 'description' => array_slice($dupes['description'], 0, 50)],
        'limit_reached' => count(array_filter($rows, fn ($r) => $r['kind'] !== 'seo' && $r['state'] !== 'skipped')) >= (int) $audit['max_pages'],
    ];
    db_update('seo_audits', ['status' => 'completed', 'finished_at' => date('Y-m-d H:i:s'), 'heartbeat_at' => date('Y-m-d H:i:s'),
        'pages_crawled' => count($done), 'pages_found' => count($rows), 'errors_count' => $errors, 'warnings_count' => $warnings,
        'summary' => json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'message' => $queuedLeft ? $queuedLeft . ' queued URLs were not crawled.' : null], 'id = ?', [$id]);
    $msg = sprintf('SEO audit #%d completed: %d URLs crawled, %d with errors, %d with warnings', $id, count($done), $errors, $warnings);
    log_activity('audit', 'seo', $id, $msg);
    if (!empty($audit['started_by'])) {
        notify((int) $audit['started_by'], 'system', 'SEO audit completed', sprintf('%d URLs crawled · %d with errors · %d with warnings.', count($done), $errors, $warnings), 'admin/cms/seo?tab=audit', 'search');
    }
}

/** Public status of an audit (no page rows). */
function seo_audit_status(int $id): ?array
{
    $a = db_row('SELECT a.*, u.name AS started_by_name FROM seo_audits a LEFT JOIN users u ON u.id = a.started_by WHERE a.id = ?', [$id]);
    if (!$a) {
        return null;
    }
    foreach (['id', 'max_pages', 'pages_found', 'pages_crawled', 'errors_count', 'warnings_count', 'started_by'] as $k) {
        $a[$k] = $a[$k] === null ? null : (int) $a[$k];
    }
    $a['summary'] = $a['summary'] ? json_decode((string) $a['summary'], true) : null;
    $a['queued'] = (int) db_value("SELECT COUNT(*) FROM seo_audit_pages WHERE audit_id = ? AND state = 'queued'", [$id]);
    $a['stale'] = in_array($a['status'], ['running', 'queued'], true) && strtotime((string) ($a['heartbeat_at'] ?: $a['created_at'])) < time() - 180;
    return $a;
}

/** Audit history (most recent first). */
function seo_audit_history(int $limit = 10): array
{
    $rows = db_all('SELECT a.id, a.status, a.mode, a.max_pages, a.pages_found, a.pages_crawled, a.errors_count, a.warnings_count, a.started_at, a.finished_at, a.message,
                    u.name AS started_by_name FROM seo_audits a LEFT JOIN users u ON u.id = a.started_by ORDER BY a.id DESC LIMIT ' . (int) $limit);
    foreach ($rows as &$r) {
        foreach (['id', 'max_pages', 'pages_found', 'pages_crawled', 'errors_count', 'warnings_count'] as $k) {
            $r[$k] = (int) $r[$k];
        }
    }
    return $rows;
}

/** Crawled URLs of an audit with filters: issue, severity, status (2xx|3xx|4xx|5xx|failed|skipped|queued), kind, q, sort. */
function seo_audit_pages(int $id, array $f, int $page = 1, int $perPage = 25): array
{
    $where = ['audit_id = ?'];
    $args = [$id];
    if (($issue = api_str($f['issue'] ?? null)) !== '' && isset(seo_audit_issue_defs()[$issue])) {
        $where[] = 'issues LIKE ?';
        $args[] = '%"' . $issue . '"%';
    }
    if (($sev = api_str($f['severity'] ?? null)) !== '' && in_array($sev, ['error', 'warning', 'notice'], true)) {
        $codes = array_keys(array_filter(seo_audit_issue_defs(), fn ($d) => $d[1] === $sev));
        $where[] = '(' . implode(' OR ', array_fill(0, count($codes), 'issues LIKE ?')) . ')';
        foreach ($codes as $c) {
            $args[] = '%"' . $c . '"%';
        }
    }
    $status = api_str($f['status'] ?? null);
    $map = ['2xx' => "state = 'done' AND status_code BETWEEN 200 AND 299", '3xx' => "state = 'done' AND status_code BETWEEN 300 AND 399",
        '4xx' => "state = 'done' AND status_code BETWEEN 400 AND 499", '5xx' => "state = 'done' AND status_code >= 500",
        'failed' => "state = 'done' AND (status_code IS NULL OR status_code = 0)", 'skipped' => "state = 'skipped'", 'queued' => "state = 'queued'"];
    if (isset($map[$status])) {
        $where[] = $map[$status];
    }
    if (in_array($f['kind'] ?? '', ['page', 'file', 'seo'], true)) {
        $where[] = 'kind = ?';
        $args[] = $f['kind'];
    }
    if (($q = trim(api_str($f['q'] ?? null))) !== '') {
        $where[] = '(path LIKE ? OR title LIKE ?)';
        $args[] = '%' . $q . '%';
        $args[] = '%' . $q . '%';
    }
    $sorts = ['path' => 'path', 'status' => 'status_code', 'time' => 'response_ms', 'issues' => 'CHAR_LENGTH(issues)', 'depth' => 'depth'];
    $sort = $sorts[api_str($f['sort'] ?? null)] ?? 'CHAR_LENGTH(issues)';
    $dir = ($f['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
    $sql = ' FROM seo_audit_pages WHERE ' . implode(' AND ', $where);
    $total = (int) db_value('SELECT COUNT(*)' . $sql, $args);
    $p = paginate($total, $page, $perPage);
    $rows = db_all('SELECT id, url, path, kind, source, in_sitemap, depth, found_on, state, status_code, redirect_to, content_type, response_ms, bytes, title, description,
                    h1, h1_count, images, images_no_alt, canonical, robots, issues, error' . $sql . " ORDER BY $sort $dir, id ASC LIMIT " . (int) $p['per_page'] . ' OFFSET ' . (int) $p['offset'], $args);
    foreach ($rows as &$r) {
        $r['issues'] = json_decode((string) $r['issues'], true) ?: [];
        foreach (['id', 'in_sitemap', 'depth', 'status_code', 'response_ms', 'bytes', 'h1_count', 'images', 'images_no_alt'] as $k) {
            $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        }
    }
    unset($r);
    return ['rows' => $rows, 'total' => $p['total'], 'page' => $p['page'], 'per_page' => $p['per_page'], 'pages' => $p['pages']];
}

/** CSV rows for an audit export. */
function seo_audit_export_rows(int $id): array
{
    $defs = seo_audit_issue_defs();
    $out = [];
    foreach (db_all('SELECT * FROM seo_audit_pages WHERE audit_id = ? ORDER BY kind, path', [$id]) as $r) {
        $iss = json_decode((string) $r['issues'], true) ?: [];
        $out[] = [$r['url'], $r['kind'], $r['state'] === 'done' ? ($r['status_code'] ?: 'failed') : $r['state'], $r['redirect_to'], $r['response_ms'],
            $r['bytes'] !== null ? round((int) $r['bytes'] / 1024, 1) : '', $r['title'], $r['title'] !== null ? mb_strlen((string) $r['title']) : '',
            $r['description'] !== null ? mb_strlen((string) $r['description']) : '', $r['h1_count'], $r['images_no_alt'], $r['canonical'], $r['robots'],
            $r['in_sitemap'] ? 'yes' : 'no', $r['depth'], $r['found_on'], implode('; ', array_map(fn ($c) => $defs[$c][0] ?? $c, $iss)), $r['error']];
    }
    return $out;
}

/** Background crawler (CLI): runs steps until the audit is finished, cancelled or 20 minutes have passed. */
function seo_audit_worker(int $id): void
{
    $deadline = time() + 1200;
    while (time() < $deadline) {
        $a = seo_audit_step($id, 15.0);
        if (!$a || $a['status'] !== 'running') {
            return;
        }
    }
    db_update('seo_audits', ['mode' => 'inline', 'message' => 'The background crawler stopped after 20 minutes — open the audit to continue.'], 'id = ? AND status = ?', [$id, 'running']);
}

/* ------------------------------------------------------------------ CLI entry: php app/services/seo.php audit-worker <id> */
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === realpath(__FILE__)) {
    if (($argv[1] ?? '') === 'audit-worker' && (int) ($argv[2] ?? 0) > 0) {
        if (!function_exists('notify')) {
            require_once APP_ROOT . '/app/functions.php';
        }
        seo_audit_worker((int) $argv[2]);
        exit(0);
    }
    fwrite(STDERR, "Usage: php app/services/seo.php audit-worker <audit-id>\n");
    exit(1);
}
