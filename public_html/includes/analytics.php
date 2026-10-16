<?php
/**
 * Deep analytics - first-party, privacy-respecting visitor statistics (no external scripts, no raw IP addresses).
 *
 * How it fits together
 *  - track_page_view() (includes/cms.php) inserts the `page_views` row and calls analytics_after_page_view(), which
 *    creates/updates one `analytics_sessions` row per visit (a random id kept in the PHP session; a new visit starts
 *    after 30 minutes of inactivity): landing/exit page, source and channel, device/browser/OS, language, approximate
 *    location (country/region/city), pages, engaged time, events, conversion and bounce.
 *  - render_analytics_beacon() (footer hook) prints a data element with the page view id and an HMAC token bound to
 *    the visit; assets/js/modules/analytics.js measures visible engaged time, scroll depth, clicks, downloads, outbound
 *    links, form submits (form id / action path only), errors, rage clicks and load time, and sends small batches to
 *    /api/collect/ (api_collect()).
 *  - Approximate location is looked up ONCE per visit: Cloudflare's location headers, or a server-side HTTPS lookup
 *    (ipinfo.io / ipapi.co, 1.5 s timeout) done while answering the first beacon, so the page itself never waits.
 *    Results are cached for 24 hours under a keyed hash of the IP that rotates daily; the IP is never stored.
 *    Without a provider the browser's time zone gives the country only (geo_source = 'timezone').
 *  - A persistent visitor id (cookie pwf_vid, 13 months, HttpOnly, first-party) is set ONLY after the visitor chose
 *    "Allow analytics" in the consent banner; it is the only way returning visitors are recognised.
 *  - Staff, bots and visitors sending Do-Not-Track / Global Privacy Control are never tracked (track_page_view()).
 *
 * Settings: analytics_enhanced (1/0), analytics_persistent_id (consent|off), geoip_provider (none|cloudflare|ipinfo|
 * ipapi), secret geoip_token (encrypted; constant GEOIP_TOKEN in pwf-config.php wins). Admin: admin/deep-analytics.php
 * (permission analytics.deep). Retention: analytics_retention_days (shared with the basic statistics).
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

// Base URL of the location lookup API ('' = the provider's own: https://ipinfo.io or https://ipapi.co).
// Tests point it to a local mock in pwf-config.php.
defined('GEOIP_API_BASE') || define('GEOIP_API_BASE', '');

const ANALYTICS_SESSION_TIMEOUT = 1800;        // a new visit starts after 30 minutes without activity
const ANALYTICS_VID_COOKIE = 'pwf_vid';
const ANALYTICS_VID_LIFETIME = 34186669;       // 13 months (395.7 days) in seconds
const ANALYTICS_MAX_BATCH_EVENTS = 40;
const ANALYTICS_MAX_BODY = 16384;
const ANALYTICS_MAX_EVENTS_PER_VIEW = 150;
const ANALYTICS_MAX_EVENTS_PER_SESSION = 1500;
const ANALYTICS_BATCHES_PER_MINUTE = 40;
const ANALYTICS_EVENT_TYPES = [
    'cta_click' => 'Button / CTA clicks', 'outbound' => 'Outbound links', 'download' => 'Downloads', 'phone' => 'Phone links',
    'email' => 'Email links', 'whatsapp' => 'WhatsApp links', 'form_submit' => 'Form submits', 'scroll_depth' => 'Scroll depth',
    'search' => 'Site searches', 'share' => 'Shares', 'video_play' => 'Video plays', 'js_error' => 'JavaScript errors',
    'rage_click' => 'Rage clicks', 'conversion' => 'Conversions',
];
const ANALYTICS_CHANNELS = [
    'direct' => 'Direct', 'organic' => 'Organic search', 'social' => 'Social', 'referral' => 'Referral',
    'email' => 'Email', 'paid' => 'Paid', 'campaign' => 'Campaign',
];

// =====================================================================
// Settings & small helpers
// =====================================================================

/** Enhanced (session-level) analytics is on (basic statistics on + analytics_enhanced = 1). */
function analytics_enhanced(): bool
{
    return setting('analytics_enabled', '1') === '1' && setting('analytics_enhanced', '0') === '1';
}

/** 'consent' = persistent visitor id only after consent; 'off' = never. */
function analytics_persistent_mode(): string
{
    return setting('analytics_persistent_id', 'consent') === 'off' ? 'off' : 'consent';
}

/** The consent banner must also ask about our own first-party visitor cookie (used by render_consent_banner()). */
function analytics_consent_needed(): bool
{
    return analytics_enhanced() && analytics_persistent_mode() === 'consent';
}

/** Ask this visitor about the visitor cookie? Not when they are never measured (Do Not Track / GPC, staff). */
function analytics_consent_banner_needed(): bool
{
    if (!analytics_consent_needed()) {
        return false;
    }
    if (setting('analytics_respect_dnt', '1') === '1' && (($_SERVER['HTTP_DNT'] ?? '') === '1' || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1')) {
        return false;
    }
    return !is_staff();
}

/** 'none' | 'cloudflare' | 'ipinfo' | 'ipapi' */
function analytics_geo_provider(): string
{
    $p = setting('geoip_provider', 'none');
    return in_array($p, ['cloudflare', 'ipinfo', 'ipapi'], true) ? $p : 'none';
}

function analytics_geo_providers(): array
{
    return [
        'none' => 'Off - country from the browser time zone only',
        'cloudflare' => 'Cloudflare location headers (site is behind Cloudflare)',
        'ipinfo' => 'ipinfo.io (server-side lookup, token recommended)',
        'ipapi' => 'ipapi.co (server-side lookup, key optional)',
    ];
}

function analytics_retention_days(): int
{
    return max(30, min(3650, (int) setting('analytics_retention_days', '400')));
}

/** Keyed hash (key derived from APP_KEY / configuration - never stored in the database). */
function analytics_hmac(string $data): string
{
    [, $key] = secret_key();
    return hash_hmac('sha256', $data, $key . '|pwf-deep-analytics');
}

/** Token that lets the browser report on ONE page view of ONE visit (bound to the server-side session state). */
function analytics_token(int $pageViewId, array $state): string
{
    return substr(analytics_hmac('pv|' . $pageViewId . '|' . (int) ($state['id'] ?? 0) . '|' . (string) ($state['key'] ?? '')), 0, 40);
}

/** Lower-case UTM value with only safe characters (same rules as capture_attribution()). */
function analytics_clean_tag($v, int $len): ?string
{
    $v = is_string($v) ? trim((string) preg_replace('/[^\w\-\.\s]/u', '', $v)) : '';
    return $v === '' ? null : mb_substr(mb_strtolower($v), 0, $len);
}

/** Plain single-line text, control characters removed, cut to $len characters. */
function analytics_text($v, int $len): ?string
{
    if (!is_string($v) && !is_int($v) && !is_float($v)) {
        return null;
    }
    $v = (string) $v;
    if (!mb_check_encoding($v, 'UTF-8')) {
        return null;
    }
    $v = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v));
    $v = (string) preg_replace('/\s{2,}/u', ' ', $v);
    return $v === '' ? null : mb_substr($v, 0, $len);
}

/** Remove query strings / fragments from any URL in a text (error messages, link targets). */
function analytics_strip_queries(string $text): string
{
    return (string) preg_replace('#((?:https?:)?//[^\s?\#"\']+|/[^\s?\#"\']*)[?\#][^\s"\']*#i', '$1', $text);
}

/** Search words without anything that looks like personal data (emails, phone / ID numbers). */
function analytics_scrub_search(string $text): string
{
    $text = (string) preg_replace('/[^\s@]+@[^\s@]+/u', '[email]', $text);
    $text = (string) preg_replace('/\d[\d\s\-]{4,}\d/u', '[number]', $text);
    return mb_strtolower(trim($text));
}

/** Country name for an ISO 3166 code ('IN' => 'India'). */
function analytics_country_name(?string $code): string
{
    $code = strtoupper((string) $code);
    if (!preg_match('/^[A-Z]{2}$/', $code)) {
        return '';
    }
    if (class_exists('Locale')) {
        $name = Locale::getDisplayRegion('-' . $code, 'en');
        if (is_string($name) && $name !== '' && $name !== $code) {
            return $name;
        }
    }
    $map = [
        'IN' => 'India', 'US' => 'United States', 'GB' => 'United Kingdom', 'AE' => 'United Arab Emirates', 'CA' => 'Canada',
        'AU' => 'Australia', 'NP' => 'Nepal', 'BD' => 'Bangladesh', 'LK' => 'Sri Lanka', 'PK' => 'Pakistan', 'BT' => 'Bhutan',
        'SG' => 'Singapore', 'MY' => 'Malaysia', 'SA' => 'Saudi Arabia', 'QA' => 'Qatar', 'KW' => 'Kuwait', 'OM' => 'Oman',
        'BH' => 'Bahrain', 'DE' => 'Germany', 'FR' => 'France', 'NL' => 'Netherlands', 'IE' => 'Ireland', 'IT' => 'Italy',
        'ES' => 'Spain', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'NZ' => 'New Zealand', 'JP' => 'Japan', 'CN' => 'China',
        'HK' => 'Hong Kong', 'ID' => 'Indonesia', 'TH' => 'Thailand', 'PH' => 'Philippines', 'ZA' => 'South Africa',
        'KE' => 'Kenya', 'NG' => 'Nigeria', 'BR' => 'Brazil', 'MX' => 'Mexico', 'RU' => 'Russia', 'MV' => 'Maldives',
        'MU' => 'Mauritius', 'FJ' => 'Fiji', 'KR' => 'South Korea',
    ];
    return $map[$code] ?? $code;
}

/** Readable language name for a tag ('hi-IN' => 'Hindi (hi-IN)'). */
function analytics_language_name(?string $tag): string
{
    $tag = (string) $tag;
    if ($tag === '') {
        return 'Unknown';
    }
    if (class_exists('Locale')) {
        $name = Locale::getDisplayLanguage(str_replace('-', '_', $tag), 'en');
        if (is_string($name) && $name !== '' && strcasecmp($name, $tag) !== 0) {
            return $name . ' (' . $tag . ')';
        }
    }
    return $tag;
}

function analytics_channel_label(string $key): string
{
    return ANALYTICS_CHANNELS[$key] ?? humanize($key);
}

function analytics_event_label(string $type): string
{
    return ANALYTICS_EVENT_TYPES[$type] ?? humanize($type);
}

/** "3m 05s" style duration. */
function analytics_duration($seconds): string
{
    $s = max(0, (int) round((float) $seconds));
    if ($s < 60) {
        return $s . 's';
    }
    if ($s < 3600) {
        return intdiv($s, 60) . 'm ' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) . 's';
    }
    return intdiv($s, 3600) . 'h ' . str_pad((string) intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT) . 'm';
}

/** Short public code of a visit (shown instead of any identifier). */
function analytics_visit_code(string $sid): string
{
    return 'V-' . strtoupper(substr($sid, 0, 8));
}

// =====================================================================
// User agent, channel, screen, language
// =====================================================================

/** ['browser' => 'Chrome', 'browser_version' => '126', 'os' => 'Android 14'] from a user agent (no external library). */
function analytics_parse_ua(string $ua): array
{
    $browser = 'Other';
    $version = '';
    $rules = [
        ['Edge', '#\bEdg(?:e|A|iOS)?/(\d+)#'],
        ['Opera', '#\b(?:OPR|OPiOS|OPT)/(\d+)#'],
        ['Samsung Internet', '#SamsungBrowser/(\d+)#'],
        ['UC Browser', '#UCBrowser/(\d+)#'],
        ['Yandex', '#YaBrowser/(\d+)#'],
        ['Facebook app', '#\bFBAV/(\d+)#'],
        ['Instagram app', '#\bInstagram (\d+)#'],
        ['Firefox', '#\b(?:Firefox|FxiOS)/(\d+)#'],
        ['Android WebView', '#; wv\).*?Chrome/(\d+)#'],
        ['Chrome', '#\b(?:Chrome|CriOS)/(\d+)#'],
        ['Safari', '#Version/(\d+)[\d.]*.*Safari/#'],
        ['Internet Explorer', '#(?:MSIE |Trident/.*rv:)(\d+)#'],
    ];
    foreach ($rules as [$name, $re]) {
        if (preg_match($re, $ua, $m)) {
            $browser = $name;
            $version = $m[1];
            break;
        }
    }
    if (preg_match('#Windows NT (\d+\.\d+)#', $ua, $m)) {
        $os = ['10.0' => 'Windows 10/11', '6.3' => 'Windows 8.1', '6.2' => 'Windows 8', '6.1' => 'Windows 7'][$m[1]] ?? 'Windows';
    } elseif (preg_match('#\b(iPhone|iPad|iPod)\b.*? OS (\d+)[_\d]* like Mac#', $ua, $m)) {
        $os = ($m[1] === 'iPad' ? 'iPadOS ' : 'iOS ') . $m[2];
    } elseif (preg_match('#Android (\d+)#', $ua, $m)) {
        $os = 'Android ' . $m[1];
    } elseif (stripos($ua, 'Android') !== false) {
        $os = 'Android';
    } elseif (stripos($ua, 'KaiOS') !== false) {
        $os = 'KaiOS';
    } elseif (strpos($ua, 'CrOS') !== false) {
        $os = 'ChromeOS';
    } elseif (strpos($ua, 'Mac OS X') !== false || strpos($ua, 'Macintosh') !== false) {
        $os = 'macOS';
    } elseif (stripos($ua, 'Linux') !== false) {
        $os = 'Linux';
    } else {
        $os = 'Other';
    }
    return ['browser' => $browser, 'browser_version' => mb_substr($version, 0, 12), 'os' => $os];
}

/**
 * Channel of a visit: paid / email / social / campaign (UTM tags or ad click ids), otherwise from the referring site:
 * organic search / social / email (webmail) / referral, or direct.
 */
function analytics_channel(?string $refHost, ?string $source, ?string $medium, bool $adClick = false): string
{
    $source = (string) $source;
    $medium = (string) $medium;
    $host = strtolower((string) $refHost);
    $socialRe = '/(^|\.)(facebook\.com|fb\.me|fb\.com|instagram\.com|t\.co|twitter\.com|x\.com|linkedin\.com|lnkd\.in|youtube\.com|youtu\.be|whatsapp\.com|wa\.me|reddit\.com|pinterest\.[a-z.]+|telegram\.org|t\.me|quora\.com|threads\.net|snapchat\.com|sharechat\.com|koo\.in|tumblr\.com)$/';
    $searchRe = '/(^|\.)(google\.[a-z.]+|bing\.com|duckduckgo\.com|yahoo\.[a-z.]+|yandex\.[a-z.]+|baidu\.com|ecosia\.org|search\.brave\.com|startpage\.com|qwant\.com|naver\.com|seznam\.cz|perplexity\.ai)$/';
    if ($adClick || preg_match('/^(cpc|ppc|paid|paidsearch|paid[_\-]?search|paid[_\-]?social|display|cpm|cpv|banner|ads?|affiliate|sponsored)$/', $medium)) {
        return 'paid';
    }
    if (preg_match('/^(e-?mail|newsletter|mailer)$/', $medium) || preg_match('/(newsletter|e-?mail|mailchimp|sendinblue|brevo)/', $source)) {
        return 'email';
    }
    if (preg_match('/^(social|social[_\-]?network|social[_\-]?media|sm|sms|whatsapp)$/', $medium)
        || preg_match('/^(facebook|fb|instagram|ig|twitter|x|linkedin|youtube|whatsapp|telegram|reddit|pinterest|threads)$/', $source)) {
        return 'social';
    }
    if ($source !== '' || $medium !== '') {
        return $medium === 'organic' ? 'organic' : 'campaign';
    }
    if ($host === '') {
        return 'direct';
    }
    if (preg_match($searchRe, $host)) {
        return 'organic';
    }
    if (preg_match($socialRe, $host)) {
        return 'social';
    }
    if (preg_match('/^(mail\.google\.com|outlook\.live\.com|outlook\.office\.com|outlook\.office365\.com|mail\.yahoo\.com|mail\.zoho\.(com|in)|webmail\..+|mail\.rediff\.com|rediffmail\.com)$/', $host)) {
        return 'email';
    }
    return 'referral';
}

/** Screen width (CSS pixels) to a size range label. */
function analytics_screen_bucket(int $width): ?string
{
    if ($width < 200 || $width > 10000) {
        return null;
    }
    foreach ([[360, '< 360 px'], [414, '360–413 px'], [768, '414–767 px'], [1024, '768–1023 px'], [1280, '1024–1279 px'], [1440, '1280–1439 px'], [1920, '1440–1919 px']] as [$max, $label]) {
        if ($width < $max) {
            return $label;
        }
    }
    return '≥ 1920 px';
}

/** Valid BCP 47-ish language tag ('en-IN'), or null. */
function analytics_language_tag($tag): ?string
{
    $tag = is_string($tag) ? trim($tag) : '';
    if (!preg_match('/^([a-zA-Z]{2,3})(?:[-_]([a-zA-Z0-9]{2,8}))?/', $tag, $m)) {
        return null;
    }
    return strtolower($m[1]) . (isset($m[2]) ? '-' . (strlen($m[2]) === 2 ? strtoupper($m[2]) : $m[2]) : '');
}

/** Valid IANA time zone name, or null. */
function analytics_timezone($tz): ?string
{
    $tz = is_string($tz) ? trim($tz) : '';
    if ($tz === '' || strlen($tz) > 64 || !preg_match('#^[A-Za-z_]+(?:/[A-Za-z0-9_\-+]+){0,2}$#', $tz)) {
        return null;
    }
    // browsers (Chromium's ICU) still report some old names that newer PHP time zone data may not accept
    $aliases = [
        'Asia/Calcutta' => 'Asia/Kolkata', 'Asia/Katmandu' => 'Asia/Kathmandu', 'Asia/Dacca' => 'Asia/Dhaka', 'Asia/Thimbu' => 'Asia/Thimphu',
        'Asia/Rangoon' => 'Asia/Yangon', 'Asia/Saigon' => 'Asia/Ho_Chi_Minh', 'Asia/Ujung_Pandang' => 'Asia/Makassar', 'Asia/Ulan_Bator' => 'Asia/Ulaanbaatar',
        'Europe/Kiev' => 'Europe/Kyiv', 'Atlantic/Faeroe' => 'Atlantic/Faroe', 'Pacific/Truk' => 'Pacific/Chuuk', 'Pacific/Ponape' => 'Pacific/Pohnpei',
        'America/Godthab' => 'America/Nuuk', 'Africa/Asmera' => 'Africa/Asmara', 'Pacific/Enderbury' => 'Pacific/Kanton',
    ];
    $tz = $aliases[$tz] ?? $tz;
    try {
        new DateTimeZone($tz);
    } catch (Throwable $e) {
        return null;
    }
    return $tz;
}

/** Country code for a time zone ('Asia/Kolkata' => 'IN'), or null. */
function analytics_timezone_country(string $tz): ?string
{
    try {
        $loc = (new DateTimeZone($tz))->getLocation();
    } catch (Throwable $e) {
        return null;
    }
    $cc = is_array($loc) ? strtoupper((string) ($loc['country_code'] ?? '')) : '';
    return preg_match('/^[A-Z]{2}$/', $cc) && $cc !== '??' ? $cc : null;
}

// =====================================================================
// Persistent visitor id (only with consent)
// =====================================================================

/** Pseudonymous visitor id (keyed hash of the pwf_vid cookie), only when the cookie exists and consent mode is on. */
function analytics_visitor_id(): ?string
{
    if (analytics_persistent_mode() !== 'consent') {
        return null;
    }
    $raw = $_COOKIE[ANALYTICS_VID_COOKIE] ?? '';
    if (!is_string($raw) || !preg_match('/^[a-f0-9]{32}$/', $raw)) {
        return null;
    }
    return substr(analytics_hmac('vid|' . $raw), 0, 32);
}

function analytics_set_vid_cookie(string $value, int $expires): void
{
    if (headers_sent()) {
        return;
    }
    setcookie(ANALYTICS_VID_COOKIE, $value, [
        'expires' => $expires, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

// =====================================================================
// Approximate location
// =====================================================================

/** Location from Cloudflare's request headers (CF-IPCountry; CF-Region / CF-IPCity with "visitor location headers"). */
function analytics_geo_from_cloudflare(): ?array
{
    $cc = strtoupper((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
    if (!preg_match('/^[A-Z]{2}$/', $cc) || in_array($cc, ['XX', 'T1'], true)) {
        return null;
    }
    $clean = function ($key): ?string {
        $v = analytics_text($_SERVER[$key] ?? null, 80);
        return $v !== null && preg_match('/^[\p{L}\p{M}\p{N} .,\'()\-]+$/u', $v) ? $v : null;
    };
    return ['country_code' => $cc, 'country' => analytics_country_name($cc), 'region' => $clean('HTTP_CF_REGION'), 'city' => $clean('HTTP_CF_IPCITY'), 'source' => 'cloudflare'];
}

/** Cache key for an IP address: keyed hash that also changes every day (the IP itself is never stored). */
function analytics_ip_hash(string $ip): string
{
    return analytics_hmac('geo|' . date('Ymd') . '|' . $ip);
}

/** Cached lookup result: array (may have null country = "lookup failed recently"), or null when not cached. */
function analytics_geo_cached(string $ip): ?array
{
    $row = db_row('SELECT country_code, country, region, city, source FROM geo_cache WHERE ip_hash = ? AND expires_at > NOW()', [analytics_ip_hash($ip)]);
    return $row ?: null;
}

/** True when a lookup can be attempted for this address (public IPs; any IP when the API base is a local mock). */
function analytics_geo_lookup_allowed(string $ip): bool
{
    if (preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?(/|$)#', (string) GEOIP_API_BASE)) {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/**
 * Server-side HTTPS lookup (ipinfo.io / ipapi.co), 1.5 s timeout, result cached for 24 hours (failures for 1 hour).
 * Returns ['country_code', 'country', 'region', 'city', 'source'] or null.
 */
function analytics_geo_lookup(string $ip, string $provider): ?array
{
    if (!in_array($provider, ['ipinfo', 'ipapi'], true) || !function_exists('curl_init') || !analytics_geo_lookup_allowed($ip)) {
        return null;
    }
    if ((int) setting('geoip_backoff_until', '0') > time()) {
        return null;
    }
    $token = config_or_secret('GEOIP_TOKEN', 'geoip_token');
    $base = rtrim(GEOIP_API_BASE !== '' ? GEOIP_API_BASE : ($provider === 'ipinfo' ? 'https://ipinfo.io' : 'https://ipapi.co'), '/');
    $headers = ['Accept: application/json'];
    if ($provider === 'ipinfo') {
        $url = $base . '/' . rawurlencode($ip) . '/json';
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;   // token in a header, never in the URL
        }
    } else {
        $url = $base . '/' . rawurlencode($ip) . '/json/' . ($token !== '' ? '?key=' . rawurlencode($token) : '');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 1500, CURLOPT_CONNECTTIMEOUT_MS => 1000,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'PWF-Website-Analytics/1.0',
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    $data = is_string($body) ? json_decode($body, true) : null;
    $geo = null;
    if ($status === 200 && is_array($data) && empty($data['error']) && empty($data['bogon'])) {
        $cc = strtoupper((string) ($provider === 'ipinfo' ? ($data['country'] ?? '') : ($data['country_code'] ?? ($data['country'] ?? ''))));
        if (preg_match('/^[A-Z]{2}$/', $cc)) {
            $name = $provider === 'ipapi' ? analytics_text($data['country_name'] ?? null, 80) : null;
            $geo = [
                'country_code' => $cc, 'country' => $name ?: analytics_country_name($cc),
                'region' => analytics_text($data['region'] ?? null, 80), 'city' => analytics_text($data['city'] ?? null, 80), 'source' => $provider,
            ];
        }
    }
    try {
        db_query('REPLACE INTO geo_cache (ip_hash, country_code, country, region, city, source, expires_at) VALUES (?, ?, ?, ?, ?, ?, NOW() + INTERVAL ? HOUR)', [
            analytics_ip_hash($ip), $geo['country_code'] ?? null, $geo['country'] ?? null, $geo['region'] ?? null, $geo['city'] ?? null,
            $geo ? $provider : 'failed', $geo ? 24 : 1,
        ]);
        if (!$geo) {
            // remember why (never the token or the IP) and pause lookups briefly when the provider refuses or is down
            $reason = $curlError !== '' ? 'connection failed or timed out' : ('HTTP ' . $status . (is_array($data) && !empty($data['reason']) ? ' ' . analytics_text($data['reason'], 60) : ''));
            save_setting('geoip_last_error', date('Y-m-d H:i') . ' · ' . $provider . ' · ' . $reason);
            if ($curlError !== '' || in_array($status, [401, 403, 429], true) || $status >= 500) {
                save_setting('geoip_backoff_until', (string) (time() + ($status === 429 ? 900 : 120)));
            }
        } elseif (setting('geoip_last_ok') < date('Y-m-d H', time() - 3600)) {
            save_setting('geoip_last_ok', date('Y-m-d H:i'));
        }
    } catch (Throwable $e) {
        error_log('Geo cache: ' . $e->getMessage());
    }
    return $geo;
}

/** Location available without any network call: Cloudflare headers or the 24-hour cache. */
function analytics_geo_fast(string $provider): ?array
{
    if ($provider === 'none') {
        return null;
    }
    $cf = analytics_geo_from_cloudflare();
    if ($cf) {
        return $cf;
    }
    if ($provider === 'cloudflare') {
        return null;
    }
    $cached = analytics_geo_cached(client_ip());
    return $cached && !empty($cached['country_code']) ? $cached : null;
}

// =====================================================================
// Page view hook: one session row per visit
// =====================================================================

/** Called by track_page_view() right after the page_views row was inserted (staff, bots and DNT/GPC never get here). */
function analytics_after_page_view(int $pageViewId, array $page): void
{
    if (!analytics_enhanced() || PHP_SAPI === 'cli' && !defined('PWF_ANALYTICS_BENCH')) {
        return;
    }
    try {
        // consent mode switched off: remove a visitor cookie set earlier
        if (analytics_persistent_mode() === 'off' && isset($_COOKIE[ANALYTICS_VID_COOKIE])) {
            analytics_set_vid_cookie('', time() - 3600);
        }
        $now = time();
        $path = mb_substr('/' . route_path(), 0, 255);
        $userId = current_user_id();
        $state = $_SESSION['pwf_an'] ?? null;
        if (!is_array($state) || empty($state['id']) || $now - (int) ($state['last'] ?? 0) > ANALYTICS_SESSION_TIMEOUT) {
            $state = analytics_start_session($path, $userId);
        } else {
            db_query('UPDATE analytics_sessions SET last_seen_at = NOW(), exit_path = ?, pageviews = pageviews + 1, bounce = 0,
                             user_id = COALESCE(user_id, ?) WHERE id = ?', [$path, $userId, (int) $state['id']]);
        }
        $state['last'] = $now;
        $_SESSION['pwf_an'] = $state;
        db_query('UPDATE page_views SET session_id = ? WHERE id = ?', [(int) $state['id'], $pageViewId]);
        $GLOBALS['analytics_pv'] = ['pv' => $pageViewId, 'id' => (int) $state['id']];
    } catch (Throwable $e) {
        error_log('Deep analytics (page view): ' . $e->getMessage());
    }
}

/** Insert the session row for a new visit and return the server-side state kept in $_SESSION['pwf_an']. */
function analytics_start_session(string $path, ?int $userId): array
{
    $ua = analytics_parse_ua(user_agent());
    $ref = referrer_host();
    $source = analytics_clean_tag($_GET['utm_source'] ?? '', 100);
    $medium = analytics_clean_tag($_GET['utm_medium'] ?? '', 100);
    $campaign = analytics_clean_tag($_GET['utm_campaign'] ?? '', 150);
    $adClick = isset($_GET['gclid']) || isset($_GET['gbraid']) || isset($_GET['wbraid']) || isset($_GET['msclkid']);
    $visitorId = analytics_visitor_id();
    $returning = $visitorId !== null && db_value('SELECT 1 FROM analytics_sessions WHERE visitor_id = ? LIMIT 1', [$visitorId]) ? 1 : 0;
    $provider = analytics_geo_provider();
    $geo = analytics_geo_fast($provider);
    $salt = setting('analytics_salt_' . date('Ymd'));
    $sid = bin2hex(random_bytes(8));
    $id = db_insert('analytics_sessions', [
        'sid' => $sid, 'visitor_id' => $visitorId, 'day_hash' => substr(hash('sha256', $salt . client_ip() . user_agent()), 0, 16),
        'user_id' => $userId, 'started_at' => date('Y-m-d H:i:s'), 'last_seen_at' => date('Y-m-d H:i:s'),
        'landing_path' => $path, 'exit_path' => $path, 'referrer_host' => $ref,
        'utm_source' => $source, 'utm_medium' => $medium, 'utm_campaign' => $campaign,
        'channel' => analytics_channel($ref, $source, $medium, $adClick),
        'device' => device_type(), 'browser' => $ua['browser'], 'browser_version' => $ua['browser_version'] !== '' ? $ua['browser_version'] : null, 'os' => $ua['os'],
        'language' => analytics_language_tag((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')),
        'country_code' => $geo['country_code'] ?? null, 'country' => $geo['country'] ?? null, 'region' => $geo['region'] ?? null,
        'city' => $geo['city'] ?? null, 'geo_source' => $geo['source'] ?? null, 'is_returning' => $returning,
    ]);
    // a lookup is still needed (done while answering the first beacon, so the page never waits for it)
    $geoPending = !$geo && in_array($provider, ['ipinfo', 'ipapi'], true) ? 1 : 0;
    return ['id' => $id, 'sid' => $sid, 'key' => bin2hex(random_bytes(16)), 'started' => time(), 'geo' => $geo ? 1 : ($geoPending ? 0 : 2),
        'seq' => [], 'evc' => [], 'evt' => 0, 'rl' => [0, 0]];
}

// =====================================================================
// Beacon (footer hook) and collector API
// =====================================================================

/** Data element for assets/js/modules/analytics.js (only on pages whose view was recorded). */
function render_analytics_beacon(): string
{
    $pv = $GLOBALS['analytics_pv'] ?? null;
    $state = $_SESSION['pwf_an'] ?? null;
    if (!is_array($pv) || !is_array($state) || (int) ($state['id'] ?? 0) !== (int) $pv['id'] || !analytics_enhanced()) {
        return '';
    }
    $GLOBALS['extraScripts'][] = asset('assets/js/modules/analytics.js');
    $consent = analytics_consent_needed();
    return '<div id="pwf-analytics" hidden data-pv="' . (int) $pv['pv'] . '" data-t="' . e(analytics_token((int) $pv['pv'], $state)) . '"'
        . ' data-endpoint="' . e(url('/api/collect/')) . '" data-consent="' . ($consent ? 'ask' : 'off') . '"'
        . ' data-vid="' . ($consent && analytics_visitor_id() !== null ? '1' : '0') . '"'
        . ' data-dnt="' . (setting('analytics_respect_dnt', '1') === '1' ? '1' : '0') . '"'
        . ' data-privacy="' . e(page_url('privacy-policy') . '#website-analytics-details') . '"></div>';
}

/** JSON (or empty 204) reply for the collector, then stop. */
function analytics_reply(int $status, string $error = ''): void
{
    http_response_code($status);
    if ($status !== 204) {
        echo json_encode(['ok' => $status < 300, 'error' => $error]);
    }
    exit;
}

/**
 * POST /api/collect/ - batches from analytics.js.
 * Body (JSON, max 16 KB): pv, t (token), s (sequence number), e (engaged seconds, cumulative), sc (max scroll %),
 * l (load ms), f (1 = page left), ev ([[type, label, target], ... max 40]), tz, lg, sw (screen width), c ('yes'|'no').
 */
function api_collect(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        analytics_reply(405, 'method');
    }
    if (!analytics_enhanced()) {
        analytics_reply(204);
    }
    // only this website's own pages may report (browsers send these headers; forged cross-site posts are refused)
    $fetchSite = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'none'], true)) {
        analytics_reply(403, 'origin');
    }
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && strcasecmp((string) parse_url($origin, PHP_URL_HOST), (string) parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) !== 0) {
        analytics_reply(403, 'origin');
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > ANALYTICS_MAX_BODY) {
        analytics_reply(413, 'too_large');
    }
    $raw = (string) file_get_contents('php://input', false, null, 0, ANALYTICS_MAX_BODY + 1);
    if (strlen($raw) > ANALYTICS_MAX_BODY) {
        analytics_reply(413, 'too_large');
    }
    $in = json_decode($raw, true, 6);
    if (!is_array($in)) {
        analytics_reply(400, 'invalid');
    }
    if (setting('analytics_respect_dnt', '1') === '1' && (($_SERVER['HTTP_DNT'] ?? '') === '1' || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1')) {
        analytics_reply(204);
    }
    if (device_type() === 'bot' || is_staff()) {
        analytics_reply(204);
    }
    $state = $_SESSION['pwf_an'] ?? null;
    $pv = is_int($in['pv'] ?? null) || (is_string($in['pv'] ?? null) && ctype_digit($in['pv'])) ? (int) $in['pv'] : 0;
    $token = is_string($in['t'] ?? null) ? $in['t'] : '';
    if (!is_array($state) || empty($state['id']) || $pv <= 0 || $token === '' || !hash_equals(analytics_token($pv, $state), $token)) {
        analytics_reply(403, 'token');
    }
    $seq = is_int($in['s'] ?? null) ? $in['s'] : 0;
    if ($seq < 1 || $seq > 100000) {
        analytics_reply(400, 'sequence');
    }
    if ($seq <= (int) ($state['seq'][$pv] ?? 0)) {
        analytics_reply(409, 'replayed');
    }
    $events = $in['ev'] ?? [];
    if (!is_array($events)) {
        analytics_reply(400, 'invalid');
    }
    if (count($events) > ANALYTICS_MAX_BATCH_EVENTS) {
        analytics_reply(413, 'too_many_events');
    }
    // per-visit rate limit: batches per minute
    $now = time();
    [$windowStart, $count] = array_pad(is_array($state['rl'] ?? null) ? $state['rl'] : [0, 0], 2, 0);
    if ($now - (int) $windowStart >= 60) {
        $windowStart = $now;
        $count = 0;
    }
    if ((int) $count >= ANALYTICS_BATCHES_PER_MINUTE) {
        analytics_reply(429, 'rate_limited');
    }
    $state['rl'] = [(int) $windowStart, (int) $count + 1];
    $state['seq'][$pv] = $seq;
    if (count($state['seq']) > 30) {
        $state['seq'] = array_slice($state['seq'], -30, null, true);
    }
    // event quotas per page view and per visit (extra events are dropped silently)
    $allowed = max(0, min(ANALYTICS_MAX_EVENTS_PER_VIEW - (int) ($state['evc'][$pv] ?? 0), ANALYTICS_MAX_EVENTS_PER_SESSION - (int) ($state['evt'] ?? 0)));
    $events = array_slice($events, 0, $allowed);
    $state['evc'][$pv] = (int) ($state['evc'][$pv] ?? 0) + count($events);
    if (count($state['evc']) > 30) {
        $state['evc'] = array_slice($state['evc'], -30, null, true);
    }
    $state['evt'] = (int) ($state['evt'] ?? 0) + count($events);
    $state['last'] = $now;   // reading a page keeps the visit alive
    $needGeo = (int) ($state['geo'] ?? 2) === 0;
    if ($needGeo) {
        $state['geo'] = 1;   // one attempt per visit
    }
    $sessionId = (int) $state['id'];
    $_SESSION['pwf_an'] = $state;
    session_write_close();   // never hold the session lock during database work or the location lookup

    try {
        $row = db_row('SELECT id, path, engaged_seconds, max_scroll, load_ms, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
                       FROM page_views WHERE id = ? AND session_id = ?', [$pv, $sessionId]);
        if (!$row) {
            analytics_reply(403, 'unknown_view');
        }
        // engaged time: cumulative per page, never more than the time since the page was opened (max 4 h)
        $oldEng = (int) $row['engaged_seconds'];
        $eng = is_numeric($in['e'] ?? null) ? (int) $in['e'] : 0;
        $eng = max($oldEng, min(max(0, $eng), max(0, (int) $row['age']) + 5, 14400));
        $deltaEng = $eng - $oldEng;
        $scroll = is_numeric($in['sc'] ?? null) ? max(0, min(100, (int) $in['sc'])) : null;
        $load = is_numeric($in['l'] ?? null) ? (int) $in['l'] : 0;
        $load = $load >= 1 && $load <= 120000 ? $load : null;
        db_query('UPDATE page_views SET engaged_seconds = ?, max_scroll = GREATEST(COALESCE(max_scroll, 0), ?), load_ms = COALESCE(load_ms, ?),
                         left_at = IF(? = 1, NOW(), left_at) WHERE id = ?',
            [$eng, $scroll ?? (int) $row['max_scroll'], $load, !empty($in['f']) ? 1 : 0, $pv]);

        $rows = [];
        foreach ($events as $ev) {
            $clean = analytics_clean_event($ev);
            if ($clean) {
                $rows[] = $clean;
            }
        }
        if ($rows) {
            $sql = 'INSERT INTO analytics_events (session_id, page_view_id, type, label, target, path, created_at) VALUES ';
            $params = [];
            foreach ($rows as $i => [$type, $label, $target]) {
                $sql .= ($i ? ', ' : '') . '(?, ?, ?, ?, ?, ?, NOW())';
                array_push($params, $sessionId, $pv, $type, $label, $target, $row['path']);
            }
            db_query($sql, $params);
        }

        $tz = analytics_timezone($in['tz'] ?? null);
        $lang = analytics_language_tag($in['lg'] ?? null);
        $screen = is_numeric($in['sw'] ?? null) ? analytics_screen_bucket((int) $in['sw']) : null;
        db_query('UPDATE analytics_sessions SET bounce = IF(engaged_seconds + ? >= 10 OR pageviews > 1 OR conversions > 0, 0, bounce),
                         engaged_seconds = engaged_seconds + ?, events = events + ?, last_seen_at = NOW(),
                         timezone = COALESCE(timezone, ?), language = COALESCE(language, ?), screen = COALESCE(screen, ?)
                  WHERE id = ?', [$deltaEng, $deltaEng, count($rows), $tz, $lang, $screen, $sessionId]);

        analytics_apply_consent($in['c'] ?? null, $sessionId);

        if ($needGeo) {
            $geo = analytics_geo_lookup(client_ip(), analytics_geo_provider());
            if ($geo) {
                db_query('UPDATE analytics_sessions SET country_code = ?, country = ?, region = ?, city = ?, geo_source = ? WHERE id = ? AND geo_source IS NULL',
                    [$geo['country_code'], $geo['country'], $geo['region'], $geo['city'], $geo['source'], $sessionId]);
            }
        }
        if ($tz !== null) {
            // no location from the network: the browser's time zone tells the country (nothing more)
            $cc = analytics_timezone_country($tz);
            if ($cc) {
                db_query("UPDATE analytics_sessions SET country_code = ?, country = ?, geo_source = 'timezone' WHERE id = ? AND geo_source IS NULL",
                    [$cc, analytics_country_name($cc), $sessionId]);
            }
        }
        if (random_int(1, 200) === 1) {
            analytics_cleanup();
        }
    } catch (Throwable $e) {
        error_log('Deep analytics (collect): ' . $e->getMessage());
        analytics_reply(500, 'server');
    }
    analytics_reply(204);
}

/** Validate one event from the browser: [type, label, target] -> cleaned triple or null. Never stores query strings. */
function analytics_clean_event($ev): ?array
{
    if (!is_array($ev) || !isset($ev[0]) || !is_string($ev[0])) {
        return null;
    }
    $type = $ev[0];
    if (!isset(ANALYTICS_EVENT_TYPES[$type]) || $type === 'conversion') {
        return null;
    }
    $label = analytics_text($ev[1] ?? null, $type === 'js_error' ? 150 : 120);
    $target = analytics_text($ev[2] ?? null, 255);
    if ($label !== null) {
        $label = analytics_strip_queries($label);
    }
    if ($target !== null) {
        $target = analytics_strip_queries($target);
        if (preg_match('#^(?:[a-z][a-z0-9+.\-]*:)?//#i', $target) || $target[0] === '/') {
            $target = (string) preg_replace('/[?\#].*$/s', '', $target);
        }
    }
    switch ($type) {
        case 'scroll_depth':
            $label = in_array($label, ['25', '50', '75', '100'], true) ? $label : null;
            if ($label === null) {
                return null;
            }
            $target = null;
            break;
        case 'search':
            $label = $label !== null ? mb_substr(analytics_scrub_search($label), 0, 80) : null;
            if ($label === null || $label === '') {
                return null;
            }
            break;
        case 'phone':
        case 'email':
        case 'whatsapp':
            // the foundation's own contact links: keep the kind of link, not numbers or addresses typed elsewhere
            $target = null;
            break;
        case 'form_submit':
            $label = $label !== null && preg_match('/^[A-Za-z0-9_\-:. ]{1,80}$/', $label) ? $label : null;
            break;
    }
    return [$type, $label, $target];
}

/** Consent choice reported by the browser: set or remove the persistent visitor id. */
function analytics_apply_consent($choice, int $sessionId): void
{
    if ($choice !== 'yes' && $choice !== 'no') {
        return;
    }
    $raw = $_COOKIE[ANALYTICS_VID_COOKIE] ?? '';
    $hasCookie = is_string($raw) && preg_match('/^[a-f0-9]{32}$/', $raw);
    if ($choice === 'yes' && analytics_consent_needed()) {
        if (!$hasCookie) {
            $raw = bin2hex(random_bytes(16));
            analytics_set_vid_cookie($raw, time() + ANALYTICS_VID_LIFETIME);
            $_COOKIE[ANALYTICS_VID_COOKIE] = $raw;
        }
        $vid = substr(analytics_hmac('vid|' . $raw), 0, 32);
        $returning = db_value('SELECT 1 FROM analytics_sessions WHERE visitor_id = ? AND id <> ? LIMIT 1', [$vid, $sessionId]) ? 1 : 0;
        db_query('UPDATE analytics_sessions SET visitor_id = ?, is_returning = ? WHERE id = ? AND visitor_id IS NULL', [$vid, $returning, $sessionId]);
        return;
    }
    if ($choice === 'no' && $hasCookie) {
        // consent withdrawn: delete the cookie and unlink every visit that carried it
        $vid = substr(analytics_hmac('vid|' . $raw), 0, 32);
        db_query('UPDATE analytics_sessions SET visitor_id = NULL, is_returning = 0 WHERE visitor_id = ?', [$vid]);
        analytics_set_vid_cookie('', time() - 3600);
    }
}

// =====================================================================
// Conversions: link marketing events created during a visitor's POST request to the visit
// =====================================================================

/** At the end of a POST request of a tracked visit, link conversions recorded by track_conversion() to the visit. */
function analytics_watch_conversions(): void
{
    if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || empty($_SESSION['pwf_an']['id'])) {
        return;
    }
    // admin actions and JSON endpoints (/api/collect/ heartbeats ...) never record conversions
    if (strpos((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/') !== false || strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/') !== false) {
        return;
    }
    register_shutdown_function('analytics_link_conversions', (int) $_SESSION['pwf_an']['id'], (int) ($_SERVER['REQUEST_TIME'] ?? time()));
}

function analytics_link_conversions(int $sessionId, int $requestTime): void
{
    try {
        if (!analytics_enhanced() || is_staff()) {
            return;
        }
        // track_conversion() stores this visit's attribution; matching it within this request's time window is reliable
        $attr = $_SESSION['attr'] ?? [];
        $rows = db_rows('SELECT id, event_type, reference_id, value FROM marketing_events
                         WHERE created_at >= FROM_UNIXTIME(?) AND landing_path <=> ? AND referrer_host <=> ? AND utm_source <=> ? AND utm_medium <=> ? AND utm_campaign <=> ?
                         ORDER BY id LIMIT 10', [
            $requestTime - 1, $attr['landing_path'] ?? null, $attr['referrer_host'] ?? null, $attr['utm_source'] ?? null, $attr['utm_medium'] ?? null, $attr['utm_campaign'] ?? null,
        ]);
        if (!$rows) {
            return;
        }
        $path = mb_substr('/' . route_path(), 0, 255);
        $current = (string) db_value('SELECT converted FROM analytics_sessions WHERE id = ?', [$sessionId]);
        $best = $current;
        $linked = 0;
        foreach ($rows as $r) {
            $n = db_query('INSERT IGNORE INTO analytics_events (session_id, type, label, target, path, ref_id, value, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                [$sessionId, 'conversion', $r['event_type'], $r['reference_id'] !== null ? (string) $r['reference_id'] : null, $path, (int) $r['id'], $r['value']])->rowCount();
            if ($n) {
                $linked++;
                if (analytics_conversion_rank((string) $r['event_type']) > analytics_conversion_rank($best)) {
                    $best = (string) $r['event_type'];
                }
            }
        }
        if ($linked) {
            db_query('UPDATE analytics_sessions SET conversions = conversions + ?, events = events + ?, converted = ?, bounce = 0 WHERE id = ?',
                [$linked, $linked, $best !== '' ? $best : null, $sessionId]);
        }
    } catch (Throwable $e) {
        error_log('Deep analytics (conversions): ' . $e->getMessage());
    }
}

/** Which conversion describes a visit best (higher = more important). */
function analytics_conversion_rank(string $type): int
{
    if ($type === '') {
        return 0;
    }
    $rank = ['donation_success' => 100, 'donation_started' => 60, 'volunteer_application' => 80, 'teacher_application' => 80,
        'staff_application' => 80, 'internship_application' => 80, 'membership_application' => 75, 'scholarship_application' => 70,
        'help_request' => 70, 'fundraiser_proposal' => 70, 'event_registration' => 65, 'registration' => 50, 'contact_enquiry' => 40, 'newsletter_signup' => 30];
    return $rank[$type] ?? 20;
}

/** Visits whose started donation has since been verified (e.g. by the payment webhook) are marked as donation_success. */
function analytics_sync_verified_donations(string $since): void
{
    try {
        db_query("UPDATE analytics_sessions s
                  JOIN analytics_events ae ON ae.session_id = s.id AND ae.type = 'conversion' AND ae.label = 'donation_started'
                  JOIN donations d ON d.id = CAST(ae.target AS UNSIGNED) AND d.status = 'success'
                  SET s.converted = 'donation_success'
                  WHERE s.started_at >= ? AND (s.converted IS NULL OR s.converted <> 'donation_success')", [$since]);
    } catch (Throwable $e) {
        error_log('Deep analytics (donation sync): ' . $e->getMessage());
    }
}

// =====================================================================
// Retention
// =====================================================================

/** Delete visits/events older than the retention period and expired location cache rows (in small batches). */
function analytics_cleanup(): array
{
    $days = analytics_retention_days();
    $out = ['events' => 0, 'sessions' => 0, 'geo' => 0];
    try {
        $out['events'] = db_query('DELETE FROM analytics_events WHERE created_at < NOW() - INTERVAL ? DAY LIMIT 20000', [$days])->rowCount();
        $out['sessions'] = db_query('DELETE FROM analytics_sessions WHERE last_seen_at < NOW() - INTERVAL ? DAY LIMIT 5000', [$days])->rowCount();
        $out['geo'] = db_query('DELETE FROM geo_cache WHERE expires_at < NOW() LIMIT 5000')->rowCount();
    } catch (Throwable $e) {
        error_log('Deep analytics (cleanup): ' . $e->getMessage());
    }
    return $out;
}

// =====================================================================
// Admin: "Website activity" panel on admin/user-view.php
// =====================================================================

/** Recent visits of a signed-in member (needs analytics.deep - checked by the caller). */
function analytics_user_activity_panel(int $userId): string
{
    if (!table_exists('analytics_sessions')) {
        return '';
    }
    $sessions = db_rows('SELECT id, sid, started_at, last_seen_at, landing_path, exit_path, channel, referrer_host, device, browser, os, city, region, country,
                                pageviews, engaged_seconds, converted
                         FROM analytics_sessions WHERE user_id = ? ORDER BY started_at DESC LIMIT 8', [$userId]);
    $totals = db_row('SELECT COUNT(*) visits, COALESCE(SUM(pageviews), 0) pages, COALESCE(SUM(engaged_seconds), 0) engaged, MAX(last_seen_at) last_seen
                      FROM analytics_sessions WHERE user_id = ?', [$userId]);
    $topPages = db_rows('SELECT pv.path, COUNT(*) views, COALESCE(SUM(pv.engaged_seconds), 0) engaged
                         FROM page_views pv JOIN analytics_sessions s ON s.id = pv.session_id
                         WHERE s.user_id = ? GROUP BY pv.path ORDER BY views DESC LIMIT 6', [$userId]);
    $link = function (string $sid): string {
        return admin_url('deep-analytics.php?tab=visitors&visit=' . rawurlencode($sid));
    };
    $html = '<section class="panel da-user-activity" aria-labelledby="da-user-activity-h"><div class="panel-head"><h2 id="da-user-activity-h">Website activity</h2>'
        . '<a class="btn btn-ghost btn-sm" href="' . e(admin_url('deep-analytics.php?tab=visitors&member=' . $userId)) . '">' . icon('activity') . 'All visits</a></div>';
    if (!$sessions) {
        return $html . '<p class="muted mb-0">No website visits recorded while signed in. Visits are recorded only when enhanced analytics is on, never for staff accounts, and not when the browser sends Do Not Track / Global Privacy Control.</p></section>';
    }
    $html .= '<ul class="da-mini-kpis">'
        . '<li><strong>' . number_format((int) $totals['visits']) . '</strong><small>Visits</small></li>'
        . '<li><strong>' . number_format((int) $totals['pages']) . '</strong><small>Pages viewed</small></li>'
        . '<li><strong>' . e(analytics_duration($totals['engaged'])) . '</strong><small>Engaged time</small></li>'
        . '<li><strong>' . e(format_date($totals['last_seen'], 'd M Y')) . '</strong><small>Last seen</small></li></ul>';
    $html .= '<div class="table-wrap" tabindex="0" role="region" aria-label="Recent visits (scroll sideways on small screens)"><table class="table table-compact"><thead><tr><th>Visit</th><th>Pages</th><th class="text-right">Engaged</th><th class="hide-sm">Source · device</th></tr></thead><tbody>';
    foreach ($sessions as $s) {
        $place = implode(', ', array_filter([$s['city'], $s['region'] ?: null, $s['country']]));
        $html .= '<tr><td class="nowrap"><a href="' . e($link($s['sid'])) . '">' . e(format_date($s['started_at'], 'd M Y, h:i A')) . '</a>'
            . ($s['converted'] ? '<br><span class="badge badge-green">' . e((function_exists('insights_conversion_labels') ? (insights_conversion_labels()[$s['converted']] ?? null) : null) ?? humanize($s['converted'])) . '</span>' : '') . '</td>'
            . '<td><span class="small">' . e($s['landing_path']) . ((int) $s['pageviews'] > 1 ? ' → … → ' . e((string) $s['exit_path']) : '') . '</span><br><small class="muted">' . (int) $s['pageviews'] . ' page' . ((int) $s['pageviews'] === 1 ? '' : 's') . '</small></td>'
            . '<td class="text-right nowrap">' . e(analytics_duration($s['engaged_seconds'])) . '</td>'
            . '<td class="hide-sm small">' . e(analytics_channel_label((string) $s['channel'])) . ($s['referrer_host'] ? ' (' . e($s['referrer_host']) . ')' : '')
            . '<br><span class="muted">' . e(humanize((string) $s['device']) . ' · ' . $s['browser'] . ' · ' . $s['os']) . ($place !== '' ? ' · ' . e($place) : '') . '</span></td></tr>';
    }
    $html .= '</tbody></table></div>';
    if ($topPages) {
        $html .= '<h3 class="da-subhead">Most viewed pages</h3><ul class="da-chip-list">';
        foreach ($topPages as $p) {
            $html .= '<li><span>' . e($p['path']) . '</span><em>' . (int) $p['views'] . '×</em></li>';
        }
        $html .= '</ul>';
    }
    return $html . '<p class="small muted mt-2 mb-0">Shown to staff with the “Deep analytics” permission only. Visits are deleted after ' . analytics_retention_days() . ' days.</p></section>';
}

// =====================================================================
// Migrations (400-499)
// =====================================================================

function migration_401_deep_analytics_tables(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS analytics_sessions (
        id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
        sid              CHAR(16)     NOT NULL,
        visitor_id       CHAR(32)     DEFAULT NULL,
        day_hash         CHAR(16)     DEFAULT NULL,
        user_id          INT UNSIGNED DEFAULT NULL,
        started_at       DATETIME     NOT NULL,
        last_seen_at     DATETIME     NOT NULL,
        landing_path     VARCHAR(255) NOT NULL DEFAULT '/',
        exit_path        VARCHAR(255) DEFAULT NULL,
        referrer_host    VARCHAR(190) DEFAULT NULL,
        utm_source       VARCHAR(100) DEFAULT NULL,
        utm_medium       VARCHAR(100) DEFAULT NULL,
        utm_campaign     VARCHAR(150) DEFAULT NULL,
        channel          VARCHAR(20)  NOT NULL DEFAULT 'direct',
        device           VARCHAR(10)  NOT NULL DEFAULT 'desktop',
        browser          VARCHAR(40)  DEFAULT NULL,
        browser_version  VARCHAR(12)  DEFAULT NULL,
        os               VARCHAR(40)  DEFAULT NULL,
        screen           VARCHAR(16)  DEFAULT NULL,
        language         VARCHAR(16)  DEFAULT NULL,
        timezone         VARCHAR(64)  DEFAULT NULL,
        country_code     CHAR(2)      DEFAULT NULL,
        country          VARCHAR(80)  DEFAULT NULL,
        region           VARCHAR(80)  DEFAULT NULL,
        city             VARCHAR(80)  DEFAULT NULL,
        geo_source       VARCHAR(12)  DEFAULT NULL,
        is_returning     TINYINT(1)   NOT NULL DEFAULT 0,
        pageviews        INT UNSIGNED NOT NULL DEFAULT 1,
        engaged_seconds  INT UNSIGNED NOT NULL DEFAULT 0,
        events           INT UNSIGNED NOT NULL DEFAULT 0,
        conversions      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        converted        VARCHAR(60)  DEFAULT NULL,
        bounce           TINYINT(1)   NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY uq_as_sid (sid),
        KEY idx_as_started (started_at),
        KEY idx_as_last (last_seen_at),
        KEY idx_as_visitor (visitor_id, started_at),
        KEY idx_as_user (user_id, started_at),
        KEY idx_as_channel (channel, started_at),
        KEY idx_as_country (country_code, started_at),
        KEY idx_as_landing (landing_path(100), started_at),
        CONSTRAINT fk_as_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS analytics_events (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id    INT UNSIGNED    NOT NULL,
        page_view_id  BIGINT UNSIGNED DEFAULT NULL,
        type          VARCHAR(20)     NOT NULL,
        label         VARCHAR(150)    DEFAULT NULL,
        target        VARCHAR(255)    DEFAULT NULL,
        path          VARCHAR(255)    DEFAULT NULL,
        ref_id        BIGINT UNSIGNED DEFAULT NULL,
        value         DECIMAL(12,2)   DEFAULT NULL,
        created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_ae_ref (ref_id),
        KEY idx_ae_session (session_id, created_at),
        KEY idx_ae_type (type, created_at),
        KEY idx_ae_created (created_at),
        KEY idx_ae_view (page_view_id),
        CONSTRAINT fk_ae_session FOREIGN KEY (session_id) REFERENCES analytics_sessions (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS geo_cache (
        ip_hash       CHAR(64)    NOT NULL,
        country_code  CHAR(2)     DEFAULT NULL,
        country       VARCHAR(80) DEFAULT NULL,
        region        VARCHAR(80) DEFAULT NULL,
        city          VARCHAR(80) DEFAULT NULL,
        source        VARCHAR(12) DEFAULT NULL,
        expires_at    DATETIME    NOT NULL,
        PRIMARY KEY (ip_hash),
        KEY idx_geo_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // page_views: one ALTER for all missing columns (quick even on a large table)
    $add = [];
    foreach ([
        'session_id' => 'INT UNSIGNED DEFAULT NULL', 'engaged_seconds' => 'INT UNSIGNED DEFAULT NULL',
        'max_scroll' => 'TINYINT UNSIGNED DEFAULT NULL', 'load_ms' => 'MEDIUMINT UNSIGNED DEFAULT NULL', 'left_at' => 'DATETIME DEFAULT NULL',
    ] as $col => $def) {
        if (!column_exists('page_views', $col)) {
            $add[] = 'ADD COLUMN `' . $col . '` ' . $def;
        }
    }
    if ($add) {
        db()->exec('ALTER TABLE page_views ' . implode(', ', $add));
    }
    add_index_if_missing('page_views', 'idx_pv_session', 'INDEX idx_pv_session (session_id)');
}

function migration_402_deep_analytics_settings(): void
{
    add_setting_if_missing('analytics_enhanced', '1');
    add_setting_if_missing('analytics_persistent_id', 'consent');
    add_setting_if_missing('geoip_provider', 'none');
    add_setting_if_missing('geoip_last_error', '');
    add_setting_if_missing('geoip_last_ok', '');
    add_setting_if_missing('geoip_backoff_until', '0');
    // migration 103 only reserved the switch ('0', no admin control existed): turn the new module on once
    if (setting('analytics_deep_installed') === '') {
        db_query("UPDATE website_settings SET setting_value = '1' WHERE setting_key = 'analytics_enhanced' AND setting_value = '0'");
        add_setting_if_missing('analytics_deep_installed', date('Y-m-d'));
    }
    // visitor journeys of named members are personal data: administrators only by default (grant it in Roles)
    add_permission_if_missing('analytics.deep', 'Deep analytics: visits, journeys, locations and analytics settings', 'Reports', 63, []);
    settings_all(true);
}

/** Privacy policy: what deep analytics measures (added once; never edits existing sections). */
function migration_403_deep_analytics_privacy(): void
{
    $pageId = (int) db_value("SELECT id FROM pages WHERE slug = 'privacy-policy'");
    if (!$pageId || (int) db_value("SELECT COUNT(*) FROM page_sections WHERE page_id = ? AND anchor = 'website-analytics-details'", [$pageId]) > 0) {
        return;
    }
    $body = '<p>To understand how the website is used and to improve it, we keep our own first-party statistics on our own server. No advertising or third-party tracking scripts are used for this, and the website works the same whether or not you allow analytics cookies.</p>'
        . '<h3>What we measure</h3><ul>'
        . '<li><strong>Your visit</strong> - the pages you open, in which order and when, the page where you arrived and the one you left from; how long each page was actually visible and in use (the timer pauses when the tab is hidden or after 30 seconds without activity), how far you scrolled (25, 50, 75 or 100%) and how quickly the page loaded.</li>'
        . '<li><strong>Actions on a page</strong> - clicks on buttons such as Donate or Apply, links to other websites, downloads, phone, email and WhatsApp links, share buttons and video plays; that a form was sent (only the name of the form and its address on this website - <strong>never what you typed</strong>); words typed into the website\'s search boxes (numbers and email addresses are removed); technical errors on the page; and quick repeated clicks that suggest something did not work.</li>'
        . '<li><strong>How you found us</strong> - the website that linked to us (its domain only), campaign tags in the link (utm_source, utm_medium, utm_campaign) and the resulting channel, such as search engine, social media, email or direct.</li>'
        . '<li><strong>Your device</strong> - device type (mobile, tablet or desktop), browser and operating system with their main version number, a screen-size range, the browser language and time zone.</li>'
        . '<li><strong>Approximate location</strong> - country, state or region and city, worked out from your IP address at the time of the visit, using the location information of our network provider or a location lookup service, if enabled. <strong>Your IP address is not stored</strong> in these statistics: a lookup result is kept for at most 24 hours in a cache under a coded value that changes every day, so that the same address is not looked up again. When no lookup is enabled, only the country suggested by your browser\'s time zone is recorded.</li>'
        . '<li><strong>Results</strong> - whether the visit led to a donation, an application, a registration or a similar action on this website, so that we can see which pages help people get involved.</li>'
        . '</ul>'
        . '<h3>Signed-in members</h3><p>If you are signed in to your account, your visits are linked to your account, so that authorised staff can understand how members use the website and help you when you contact us. Staff accounts are never measured.</p>'
        . '<h3>Recognising return visits - only with your consent</h3><p>The pages of one visit are grouped with the website\'s normal session (the session cookie that also protects forms), which ends when you close the browser or after 30 minutes without activity. Only if you choose <strong>"Allow analytics"</strong> in the cookie notice do we set our own first-party cookie, <strong>pwf_vid</strong>, with a random code, for up to 13 months, so that we can tell first visits from return visits. It is never shared with anyone. If you later choose "No, thanks" (use the "Cookie settings" link at the bottom of any page), the cookie is deleted and your earlier visits are no longer linked together.</p>'
        . '<h3>Do Not Track and Global Privacy Control</h3><p>If your browser sends a "Do Not Track" or "Global Privacy Control" signal, none of these statistics are recorded for your visit.</p>'
        . '<h3>Who can see it and how long we keep it</h3><p>These statistics are stored on our own server, can be seen only by authorised staff in the admin panel, and are never sold or used for advertising. They are deleted automatically after the retention period set by our administrator (400 days by default).</p>';
    db_insert('page_sections', [
        'page_id' => $pageId, 'section_type' => 'richtext', 'anchor' => 'website-analytics-details', 'title' => 'Website analytics: details',
        'body' => $body, 'background' => 'white', 'status' => 'visible', 'sort_order' => 62,
    ]);
}

analytics_watch_conversions();
