<?php
/**
 * Digital presence / submission dashboard (SEO Management › Presence).
 *
 * seo_presence holds one row per platform or check (Search Console, Bing, Clarity, AI engines, directories …). People
 * set the statuses after doing the work; nothing here ever marks a platform verified or submitted. The automatic
 * hints (seo_presence_hints()) only describe what this website itself can see: which verification tags / IDs are
 * configured, how many URLs the sitemap lists, the robots AI policy, llms.txt, IndexNow, the last audit / PageSpeed run.
 *
 * Checklist rows: seo_presence_checklist() (seeded by database/seed/09_seo_presence.php with INSERT IGNORE, all
 * "not_started" or "not_applicable").
 */
require_once APP_ROOT . '/app/services/cms.php';
// seo-core helpers (robots AI policy, llms.txt, IndexNow) — optional, function-only files
foreach (['seo.php', 'seo_ai.php', 'seo_indexnow.php'] as $__seoFile) {
    if (is_file(APP_ROOT . '/app/services/' . $__seoFile)) {
        require_once APP_ROOT . '/app/services/' . $__seoFile;
    }
}
unset($__seoFile);

function seo_presence_categories(): array
{
    return ['search_engines' => 'Search engines', 'google' => 'Google tools', 'microsoft' => 'Microsoft tools', 'ai' => 'AI search',
        'technical' => 'Technical files', 'local_social' => 'Local & social', 'directories' => 'Directories'];
}

function seo_presence_statuses(): array
{
    return ['not_started' => 'Not started', 'in_progress' => 'In progress', 'submitted' => 'Submitted', 'verified' => 'Verified / live',
        'not_applicable' => 'Not applicable', 'error' => 'Problem'];
}

function seo_presence_verification_statuses(): array
{
    return ['not_verified' => 'Not verified', 'pending' => 'Pending', 'verified' => 'Verified', 'failed' => 'Failed', 'not_needed' => 'Not needed'];
}

function seo_presence_connection_statuses(): array
{
    return ['not_connected' => 'Not connected', 'connected' => 'Connected', 'error' => 'Error', 'not_needed' => 'Not needed'];
}

function seo_presence_submission_statuses(): array
{
    return ['not_submitted' => 'Not submitted', 'submitted' => 'Submitted', 'accepted' => 'Accepted', 'error' => 'Error', 'not_needed' => 'Not needed'];
}

/**
 * The checklist: [item_key, category, title, platform_url (official), method, next_action, status]. Status is
 * 'not_started' except where the platform clearly does not apply to an institute website.
 */
function seo_presence_checklist(): array
{
    $settings = 'Settings › Integrations';
    $dir = 'Search for an existing listing first (other same-name institutes in Noida have different addresses). Claim or request a listing with the exact name, address and phone shown on this website; add only facts you can prove.';
    $test = 'No submission exists. Keep its crawler allowed in robots.txt and log the standard test queries monthly in SEO › AI visibility.';
    $rows = [
        // Search engines
        ['gsc', 'search_engines', 'Google Search Console', 'https://search.google.com/search-console', 'Domain property (DNS TXT) or HTML tag',
            "Add the website as a property (Domain property via a DNS TXT record is preferred). For the HTML-tag method paste the code in $settings › Analytics & tags, then click Verify."],
        ['gsc_sitemap', 'search_engines', 'Search Console: sitemap', 'https://search.google.com/search-console/sitemaps', 'Sitemaps report',
            'After verification submit /sitemap.xml under Sitemaps and check that the status is “Success”. Re-check the Pages (indexing) report monthly.'],
        ['bing', 'search_engines', 'Bing Webmaster Tools', 'https://www.bing.com/webmasters', 'Import from Search Console or msvalidate.01 tag',
            "Sign in and use “Import from Google Search Console” (imports the verified site and its sitemaps), or add the site and paste the msvalidate.01 code in $settings."],
        ['bing_sitemap', 'search_engines', 'Bing Webmaster Tools: sitemap', 'https://www.bing.com/webmasters/sitemaps', 'Sitemaps',
            'Confirm /sitemap.xml is listed (imported or submitted by hand) and processed without errors.'],
        ['indexnow', 'search_engines', 'IndexNow (Bing, Yandex, Naver, Seznam …)', 'https://www.indexnow.org/', 'Key file + URL submissions',
            "Enable IndexNow in $settings (the key file is created automatically), then use “Submit changed URLs” on SEO › AI & indexing after publishing. Bing Webmaster Tools shows the URLs it received. Google does not use IndexNow."],
        ['yandex', 'search_engines', 'Yandex Webmaster', 'https://webmaster.yandex.com/', 'yandex-verification meta tag',
            "Optional (very small share in India). Add the site, paste the yandex-verification code in $settings, verify and submit /sitemap.xml."],
        ['baidu', 'search_engines', 'Baidu Search Resource Platform', 'https://ziyuan.baidu.com/', 'baidu-site-verification meta tag',
            'Assess: low relevance for Indian admissions (Chinese-language market). Mark “Not applicable” unless the institute recruits from China.'],
        ['naver', 'search_engines', 'Naver Search Advisor', 'https://searchadvisor.naver.com/', 'naver-site-verification meta tag',
            'Assess: low relevance (Korean market). IndexNow already notifies Naver. Mark “Not applicable” unless needed.'],
        ['brave', 'search_engines', 'Brave Search', 'https://search.brave.com/', 'Independent index (no webmaster console)',
            'No submission exists. Search the institute name monthly and note whether the website and correct details appear.'],
        ['duckduckgo', 'search_engines', 'DuckDuckGo', 'https://duckduckgo.com/', 'Results mostly from Bing',
            'No submission. Being indexed by Bing covers DuckDuckGo; keep DuckDuckBot and DuckAssistBot allowed in robots.txt.'],
        // Google tools
        ['ga4', 'google', 'Google Analytics 4', 'https://analytics.google.com/', 'Measurement ID (G-…)',
            "Create a GA4 property and web data stream, paste the G- ID in $settings (or load GA4 inside Tag Manager, not both) and mark generate_lead / submit_application as key events."],
        ['gtm', 'google', 'Google Tag Manager', 'https://tagmanager.google.com/', 'Container ID (GTM-…)',
            "Optional: create a web container and paste the GTM- ID in $settings. Publish tags from the container; they still wait for cookie consent."],
        ['pagespeed', 'google', 'PageSpeed Insights / Core Web Vitals', 'https://pagespeed.web.dev/', 'Lab + field tests',
            'Run mobile and desktop tests for the key pages from SEO › PageSpeed monthly and after big design changes; fix failing Core Web Vitals.'],
        ['rich_results', 'google', 'Rich Results Test', 'https://search.google.com/test/rich-results', 'Test live URLs',
            'Test the home page, a program page, an event and a news post; fix any errors. (FAQ and Course-info rich results are retired; Course list, Event and Breadcrumb still apply.)'],
        ['tag_assistant', 'google', 'Google Tag Assistant', 'https://tagassistant.google.com/', 'Debug session',
            'After GA4 / GTM go live, connect Tag Assistant to the website and confirm tags fire only after cookie consent and that lead events arrive.'],
        ['looker_studio', 'google', 'Looker Studio report', 'https://lookerstudio.google.com/', 'GA4 + Search Console connectors',
            'Optional: build a monthly report (visits, leads, top queries, top pages) for the management team.'],
        ['google_alerts', 'google', 'Google Alerts', 'https://www.google.com/alerts', 'E-mail alerts',
            'Create alerts for the institute name and the acronym + city to catch mentions, reviews and same-name listings.'],
        ['safe_browsing', 'google', 'Google Safe Browsing status', 'https://transparencyreport.google.com/safe-browsing/search', 'Site status check',
            'Check the domain monthly. Once verified, Search Console › Security issues also reports problems.'],
        ['google_trends', 'google', 'Google Trends', 'https://trends.google.com/', 'Topic research',
            'Use for seasonality (admission months) and to compare course terms; record the source in SEO › Keywords.'],
        ['google_ads_conversion', 'google', 'Google Ads conversion tracking (optional)', 'https://ads.google.com/', 'Import GA4 key events',
            'Only if paid campaigns run: import the GA4 key events (generate_lead) as conversions. Otherwise mark “Not applicable”.'],
        ['merchant_center', 'google', 'Google Merchant Center', 'https://merchants.google.com/', 'Product feed',
            'Not applicable: the institute does not sell products online.', 'not_applicable'],
        ['news_publisher_center', 'google', 'Google News Publisher Center', 'https://publishercenter.google.com/', 'Publication profile',
            'Review: useful only if the News section publishes regular original reporting; otherwise mark “Not applicable”.'],
        ['indexing_api', 'google', 'Google Indexing API', 'https://developers.google.com/search/apis/indexing-api/v3/quickstart', 'Service account',
            'Not applicable: Google supports it only for JobPosting and BroadcastEvent (livestream) pages. Use sitemaps and Search Console instead.', 'not_applicable'],
        // Microsoft tools
        ['clarity', 'microsoft', 'Microsoft Clarity', 'https://clarity.microsoft.com/', 'Project ID (loads after analytics consent)',
            "Create a project, paste the project ID in $settings and keep masking on Balanced or Strict. Complete the privacy review first."],
        ['clarity_privacy', 'microsoft', 'Clarity privacy review', 'https://learn.microsoft.com/en-us/clarity/setup-and-installation/clarity-consent-api-v2', 'Privacy / consent review',
            'Before going live: Clarity’s terms say it should not be used on sites targeting users under 18. Confirm the audience, the masking mode, the cookie policy entries (_clck, _clsk) and the consent signal.'],
        ['microsoft_uet', 'microsoft', 'Microsoft Advertising UET tag (optional)', 'https://ads.microsoft.com/', 'UET tag',
            'Only if Microsoft Ads campaigns run: load the UET tag through Tag Manager with marketing consent. Otherwise mark “Not applicable”.'],
        // AI search
        ['chatgpt', 'ai', 'ChatGPT search', 'https://chatgpt.com/', 'OAI-SearchBot crawl', $test],
        ['perplexity', 'ai', 'Perplexity', 'https://www.perplexity.ai/', 'PerplexityBot crawl', $test],
        ['gemini', 'ai', 'Google Gemini', 'https://gemini.google.com/', 'Google index + Google-Extended token',
            'Keep Google-Extended allowed (blocking it removes Gemini grounding; it does not affect Google Search). Log the standard test queries monthly.'],
        ['google_ai', 'ai', 'Google AI Overviews / AI Mode', 'https://www.google.com/', 'Normal Google indexing',
            'No extra files or markup are needed: be indexed and snippet-eligible. Log the standard test queries monthly.'],
        ['copilot', 'ai', 'Microsoft Copilot', 'https://copilot.microsoft.com/', 'Bing index',
            'Copilot answers are grounded on Bing: complete Bing Webmaster Tools and IndexNow, then log the standard test queries monthly.'],
        ['claude', 'ai', 'Claude', 'https://claude.ai/', 'Claude-SearchBot / Claude-User', $test],
        ['you_com', 'ai', 'You.com', 'https://you.com/', 'Web index', $test],
        ['aeo_content', 'ai', 'AEO content (answer-ready pages)', 'https://developers.google.com/search/docs/fundamentals/ai-optimization-guide', 'Content review',
            'Make sure key pages answer common questions in plain text: fees, eligibility, duration, admission steps, location and contact — using verified facts only.'],
        ['geo_content', 'ai', 'GEO content (entity consistency)', 'https://developers.google.com/search/docs/fundamentals/ai-optimization-guide', 'Content + profiles',
            'Keep the institute name, address, phone and program names identical on the website, Google Business Profile, social profiles and directories; add the profile links in Settings › Social.'],
        ['ai_visibility_db', 'ai', 'AI visibility test log', null, 'Monthly manual tests',
            'Run the 10 standard queries in each AI engine once a month and log mention, citation and accuracy in SEO › AI visibility.'],
        // Technical files
        ['sitemap', 'technical', 'XML sitemap', '/sitemap.xml', 'Generated automatically',
            'Check that it lists only canonical, indexable URLs (SEO › Robots & sitemap) and that Search Console / Bing read it without errors.'],
        ['robots_ai', 'technical', 'robots.txt AI crawler review', '/robots.txt', 'robots.txt policy',
            "Decide the AI search and AI training crawler policy in $settings and check that /robots.txt shows the intended groups."],
        ['llms_txt', 'technical', 'llms.txt / llms-full.txt', '/llms.txt', 'Generated from the website data',
            'Keep it enabled and read it after big content changes. Google ignores llms.txt; other AI agents may use it.'],
        ['schema_validator', 'technical', 'Schema Markup Validator', 'https://validator.schema.org/', 'Validate live URLs',
            'Validate the home, a program, an event and a news page; fix the structured-data errors listed by the site audit.'],
        // Local & social
        ['gbp', 'local_social', 'Google Business Profile', 'https://business.google.com/', 'Postcard / phone / video verification',
            'Create or claim the profile with the real-world name and the address shown on the website (no keywords in the name), verify it, then paste the profile link in Settings › Social. Check the eligibility rules for office-tower and co-working addresses.'],
        ['facebook', 'local_social', 'Facebook page', 'https://www.facebook.com/', 'Official page',
            'Confirm which page is the official one (same-name pages exist), keep name, address and phone identical to the website and put its link in Settings › Social.'],
        ['instagram', 'local_social', 'Instagram profile', 'https://www.instagram.com/', 'Official profile', 'Create or confirm the official profile and put its link in Settings › Social.'],
        ['linkedin', 'local_social', 'LinkedIn page', 'https://www.linkedin.com/', 'Company / school page', 'Create or confirm the official page and put its link in Settings › Social.'],
        ['youtube', 'local_social', 'YouTube channel', 'https://www.youtube.com/', 'Official channel', 'Create or confirm the official channel and put its link in Settings › Social.'],
        ['x', 'local_social', 'X (Twitter) profile', 'https://x.com/', 'Official profile', 'Create or confirm the official profile and put its link in Settings › Social.'],
        // Directories
        ['dir_shiksha', 'directories', 'Shiksha', 'https://www.shiksha.com/', 'Institute listing', $dir],
        ['dir_collegedunia', 'directories', 'Collegedunia', 'https://collegedunia.com/', 'Institute listing', $dir],
        ['dir_collegedekho', 'directories', 'CollegeDekho', 'https://www.collegedekho.com/', 'Institute listing', $dir],
        ['dir_careers360', 'directories', 'Careers360', 'https://www.careers360.com/', 'Institute listing', $dir],
        ['dir_justdial', 'directories', 'Justdial', 'https://www.justdial.com/', 'Local business listing', $dir],
        ['wikidata', 'directories', 'Wikidata item (optional)', 'https://www.wikidata.org/', 'Item with independent references',
            'Create only when independent, reliable sources about the institute exist; then put the item link in Settings › Social.'],
    ];
    $out = [];
    foreach ($rows as $i => $r) {
        $out[] = ['item_key' => $r[0], 'category' => $r[1], 'title' => $r[2], 'platform_url' => $r[3], 'method' => $r[4], 'next_action' => $r[5],
            'status' => $r[6] ?? 'not_started', 'sort_order' => ($i + 1) * 10];
    }
    return $out;
}

/** Integration value (environment → setting → default) — seo.php when present. */
function seo_presence_value(string $key): string
{
    if (function_exists('seo_integration_value')) {
        return trim((string) seo_integration_value($key, ''));
    }
    return trim((string) setting($key, ''));
}

/** A real profile URL from a settings key (skips bare placeholders such as https://facebook.com/), or null. */
function seo_presence_profile_url(string $key): ?string
{
    $url = trim((string) setting($key, ''));
    $p = $url !== '' ? parse_url($url) : false;
    if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)
        || (trim((string) ($p['path'] ?? ''), '/') === '' && trim((string) ($p['query'] ?? '')) === '')) {
        return null;
    }
    return $url;
}

/** Is this website running on a local / development address (search engines cannot reach it)? */
function seo_presence_is_local_host(?string $host = null): bool
{
    $host = strtolower($host ?? (string) parse_url(absolute_url(''), PHP_URL_HOST));
    return $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.test')
        || (bool) preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host);
}

/**
 * llms.txt state: enabled flag, whether the generator exists, and whether the URL answers 200. On the single-threaded
 * PHP development server the site cannot fetch itself, so the generator is called in-process there instead.
 */
function seo_presence_llms_check(bool $full = false): array
{
    // same rule as llms.php (off when the setting is off or "Discourage search engines" blocks the whole site)
    $enabled = function_exists('seo_llms_enabled') ? seo_llms_enabled() : seo_presence_value('llms_txt_enabled') !== '0';
    $url = absolute_url($full ? 'llms-full.txt' : 'llms.txt');
    $out = ['enabled' => $enabled, 'available' => function_exists('seo_llms_txt'), 'url' => $url, 'status' => null, 'bytes' => null, 'method' => 'none', 'error' => null,
        'custom' => seo_presence_value('llms_txt_custom') !== ''];
    if (!$enabled) {
        return $out;
    }
    $singleThreaded = PHP_SAPI === 'cli-server' && (int) (getenv('PHP_CLI_SERVER_WORKERS') ?: 1) < 2;
    if ($singleThreaded || PHP_SAPI === 'cli') {
        $out['method'] = 'inprocess';
        if (!$out['available']) {
            $out['error'] = 'The llms.txt generator is not installed on this server yet.';
            return $out;
        }
        try {
            $txt = (string) seo_llms_txt($full);
            $out['bytes'] = strlen($txt);
            $out['status'] = $txt !== '' ? 200 : 404;
        } catch (Throwable $e) {
            $out['status'] = 500;
            $out['error'] = mb_substr($e->getMessage(), 0, 200);
        }
        return $out;
    }
    if (!function_exists('curl_init')) {
        $out['error'] = 'cURL is not available to check the URL.';
        return $out;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => false, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'GIMT-SEO-check/1.0', CURLOPT_RANGE => '0-65535']);
    $body = curl_exec($ch);
    $out['method'] = 'http';
    $out['status'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: null;
    $out['bytes'] = is_string($body) ? strlen($body) : null;
    if ($body === false) {
        $out['error'] = mb_substr(curl_error($ch), 0, 200);
    }
    if (PHP_VERSION_ID < 80000) {
        curl_close($ch); // no-op (deprecated) since PHP 8
    }
    return $out;
}

/** Latest IndexNow state (seo-core's seo_indexnow_status() when installed, else the settings + log table). */
function seo_presence_indexnow(): array
{
    $st = ['available' => function_exists('seo_indexnow_submit'), 'enabled' => seo_presence_value('indexnow_enabled') === '1', 'key' => seo_presence_value('indexnow_key'), 'last' => null];
    if (function_exists('seo_indexnow_status')) {
        try {
            $s = (array) seo_indexnow_status();
            $st['enabled'] = !empty($s['enabled']);
            $st['key'] = (string) ($s['key'] ?? $st['key']);
        } catch (Throwable $e) {
            // fall back to the stored settings
        }
    }
    try {
        $st['last'] = db_row('SELECT trigger_type, url_count, http_status, response, created_at FROM seo_indexnow_log ORDER BY id DESC LIMIT 1');
    } catch (Throwable $e) {
        $st['last'] = null;
    }
    return $st;
}

/**
 * Automatic hints per checklist item: item_key => [['tone' => ok|info|warn|error, 'text' => …], …]. Computed from this
 * website's own state only. They never change a row's status.
 */
function seo_presence_hints(): array
{
    $h = [];
    $add = function (string $key, string $tone, string $text) use (&$h) {
        $h[$key][] = ['tone' => $tone, 'text' => $text];
    };
    $v = 'seo_presence_value';
    // (a local / development copy is flagged once by the Presence screen itself: GET /api/seo-ai/presence → is_local)
    $blocked = $v('seo_block_indexing') === '1';
    // Verification tags
    $tags = ['gsc' => ['search_console_verification', 'google-site-verification'], 'bing' => ['bing_site_verification', 'msvalidate.01'],
        'yandex' => ['yandex_verification', 'yandex-verification'], 'baidu' => ['baidu_verification', 'baidu-site-verification'], 'naver' => ['naver_verification', 'naver-site-verification']];
    foreach ($tags as $item => [$key, $meta]) {
        if ($v($key) !== '') {
            $add($item, 'ok', "Verification tag configured: <meta name=\"$meta\"> is printed on every public page.");
        } else {
            $add($item, 'info', $item === 'gsc' ? 'No HTML-tag code set. That is fine if the property is verified by DNS; otherwise paste the code in Settings › Integrations.'
                : ($item === 'bing' ? 'No msvalidate.01 code set. “Import from Google Search Console” verifies without a tag.' : "No $meta code set in Settings › Integrations."));
        }
    }
    // Sitemap
    try {
        $urls = cms_sitemap_urls();
        $count = count($urls);
    } catch (Throwable $e) {
        $count = 0;
    }
    $sitemapText = $blocked ? null : 'The sitemap index (/sitemap.xml) lists ' . number_format($count) . ' indexable URLs.';
    foreach (['gsc_sitemap', 'bing_sitemap', 'sitemap'] as $k) {
        $blocked ? $add($k, 'error', 'Indexing is switched off (Settings › Integrations › Search engines): robots.txt disallows everything and the sitemaps are empty.')
            : $add($k, $count ? 'ok' : 'warn', (string) $sitemapText);
    }
    if (trim((string) setting('robots_txt', '')) !== '') {
        $add('sitemap', 'info', 'robots.txt uses custom rules (SEO › Robots & sitemap) — make sure it still has the Sitemap line.');
    }
    // IndexNow
    $in = seo_presence_indexnow();
    if (!$in['available'] && !$in['enabled']) {
        $add('indexnow', 'info', 'IndexNow submission is not installed on this server yet.');
    } elseif (!$in['enabled']) {
        $add('indexnow', 'info', 'IndexNow is switched off in Settings › Integrations.');
    } elseif ($in['key'] === '') {
        $add('indexnow', 'warn', 'IndexNow is on but has no key yet.');
    } else {
        $add('indexnow', 'ok', 'IndexNow is on; key file /' . $in['key'] . '.txt.');
    }
    if ($in['last']) {
        $code = (int) $in['last']['http_status'];
        $add('indexnow', in_array($code, [200, 202], true) ? 'ok' : 'error', 'Last submission ' . date('j M Y, H:i', strtotime((string) $in['last']['created_at'])) . ': '
            . (int) $in['last']['url_count'] . ' URL(s), HTTP ' . ($code ?: 'no response') . ($in['last']['response'] && !in_array($code, [200, 202], true) ? ' — ' . mb_substr((string) $in['last']['response'], 0, 120) : '') . '.');
    } elseif ($in['enabled']) {
        $add('indexnow', 'info', 'Nothing submitted yet.');
    }
    // Analytics IDs
    foreach (['ga4' => ['google_analytics_id', 'GA4 measurement ID'], 'gtm' => ['gtm_id', 'Tag Manager container'], 'clarity' => ['clarity_project_id', 'Clarity project ID']] as $item => [$key, $label]) {
        $val = $v($key);
        $val !== '' ? $add($item, 'ok', "$label configured ($val). It loads only after the visitor’s consent.")
            : $add($item, 'info', "No $label set in Settings › Integrations.");
    }
    if ($v('google_analytics_id') !== '' || $v('gtm_id') !== '') {
        $add('tag_assistant', 'info', 'Analytics tags are configured — run a Tag Assistant session to confirm they fire after consent.');
    }
    if ($v('google_analytics_id') !== '' && $v('gtm_id') !== '') {
        $add('ga4', 'warn', 'Both GA4 and Tag Manager are set — if GTM also loads GA4, visits are counted twice.');
    }
    if ($v('clarity_project_id') !== '') {
        $add('clarity_privacy', 'warn', 'Clarity is configured: complete the privacy review (audience under 18, masking, cookie policy).');
    }
    // robots.txt AI policy
    $search = $v('ai_search_crawlers') ?: 'allow';
    $training = $v('ai_training_crawlers') ?: 'allow';
    $add('robots_ai', 'info', 'AI search crawlers: ' . ($search === 'block' ? 'blocked' : 'allowed') . ' · AI training crawlers: ' . ($training === 'block' ? 'blocked' : 'allowed') . '.');
    if (function_exists('seo_ai_crawler_policy')) {
        try {
            $policy = (array) seo_ai_crawler_policy();
            $blockedBots = array_values(array_filter($policy, fn ($p) => empty($p['allowed'])));
            $add('robots_ai', 'ok', count($policy) . ' AI crawler tokens are managed in robots.txt' . ($blockedBots ? ' (' . count($blockedBots) . ' blocked: ' . implode(', ', array_slice(array_column($blockedBots, 'bot'), 0, 6)) . ')' : '') . '.');
        } catch (Throwable $e) {
            // optional
        }
    } else {
        $add('robots_ai', 'warn', 'The robots.txt AI crawler policy is not installed on this server yet.');
    }
    if (trim((string) setting('robots_txt', '')) !== '') {
        $overridden = function_exists('seo_ai_crawler_policy') ? count(array_filter((array) seo_ai_crawler_policy(), fn ($p) => !empty($p['overridden']))) : 0;
        $add('robots_ai', $overridden ? 'warn' : 'info', 'robots.txt uses custom rules (SEO › Robots & sitemap)'
            . ($overridden ? " — $overridden AI token(s) have their own group in the custom text, so the managed policy does not apply to them." : '; open /robots.txt to confirm the AI groups.'));
    }
    if ($search === 'block') {
        foreach (['chatgpt', 'perplexity', 'claude'] as $k) {
            $add($k, 'warn', 'AI search crawlers are blocked in robots.txt, so this engine cannot cite the website.');
        }
    }
    // llms.txt
    $llms = seo_presence_llms_check();
    if (!$llms['enabled']) {
        $add('llms_txt', 'info', 'llms.txt is switched off in Settings › Integrations.');
    } elseif ($llms['status'] === 200) {
        $add('llms_txt', 'ok', '/llms.txt answers 200 (' . number_format((int) $llms['bytes']) . ' bytes' . ($llms['method'] === 'inprocess' ? ', generated in-process on the dev server' : '') . ')' . ($llms['custom'] ? ' — custom text.' : ' — generated from the website data.'));
    } else {
        $add('llms_txt', 'warn', '/llms.txt is not answering yet' . ($llms['status'] ? ' (HTTP ' . $llms['status'] . ')' : '') . ($llms['error'] ? ': ' . $llms['error'] : '.'));
    }
    // Site audit / structured data
    $audit = null;
    try {
        $audit = db_row("SELECT id, finished_at, pages_crawled, errors_count, warnings_count FROM seo_audits WHERE status = 'completed' ORDER BY id DESC LIMIT 1");
    } catch (Throwable $e) {
        $audit = null;
    }
    if ($audit) {
        $schemaPages = 0;
        try {
            $schemaPages = (int) db_value("SELECT COUNT(*) FROM seo_audit_pages WHERE audit_id = ? AND issues LIKE '%\"schema\\_%'", [$audit['id']]);
        } catch (Throwable $e) {
            $schemaPages = 0;
        }
        $txt = 'Last site audit ' . date('j M Y', strtotime((string) $audit['finished_at'])) . ': ' . (int) $audit['pages_crawled'] . ' pages, ' . (int) $audit['errors_count'] . ' errors, ' . (int) $audit['warnings_count'] . ' warnings';
        foreach (['rich_results', 'schema_validator'] as $k) {
            $add($k, $schemaPages ? 'warn' : 'ok', $txt . ($schemaPages ? "; $schemaPages page(s) with structured-data issues." : '; no structured-data issues found.'));
        }
    } else {
        foreach (['rich_results', 'schema_validator'] as $k) {
            $add($k, 'info', 'No completed site audit yet — run one on SEO › Audit.');
        }
    }
    // PageSpeed
    try {
        $psi = db_row('SELECT url, strategy, performance_score, error, created_at FROM seo_pagespeed_runs ORDER BY id DESC LIMIT 1');
    } catch (Throwable $e) {
        $psi = null;
    }
    if ($psi) {
        $add('pagespeed', $psi['error'] ? 'error' : ((int) $psi['performance_score'] >= 90 ? 'ok' : 'warn'), 'Last run ' . date('j M Y, H:i', strtotime((string) $psi['created_at'])) . ': ' . $psi['url'] . ' (' . $psi['strategy'] . ') — '
            . ($psi['error'] ? 'failed: ' . mb_substr((string) $psi['error'], 0, 100) : 'performance ' . (int) $psi['performance_score'] . '/100') . '.');
    } else {
        $add('pagespeed', 'info', 'No PageSpeed run stored yet — run one on SEO › PageSpeed.');
    }
    // AI visibility tests
    try {
        $tests = db_all('SELECT engine, COUNT(*) n, SUM(mentioned) m, SUM(cited) c, MAX(tested_on) last FROM seo_ai_tests WHERE tested_on >= ? GROUP BY engine', [date('Y-m-d', strtotime('-90 days'))]);
    } catch (Throwable $e) {
        $tests = [];
    }
    $total = array_sum(array_map(fn ($t) => (int) $t['n'], $tests));
    $add('ai_visibility_db', $total ? 'ok' : 'info', $total ? "$total test(s) logged in the last 90 days." : 'No AI visibility tests logged in the last 90 days.');
    $engineItem = ['chatgpt' => 'chatgpt', 'perplexity' => 'perplexity', 'gemini' => 'gemini', 'copilot' => 'copilot', 'claude' => 'claude', 'google_ai' => 'google_ai'];
    foreach ($tests as $t) {
        if (isset($engineItem[$t['engine']])) {
            $add($engineItem[$t['engine']], (int) $t['m'] ? 'ok' : 'warn', (int) $t['n'] . ' test(s) in 90 days, mentioned in ' . (int) $t['m'] . ', cited in ' . (int) $t['c'] . ' (last ' . date('j M Y', strtotime((string) $t['last'])) . ').');
        }
    }
    // Social / local profiles (Settings › Social)
    foreach (['facebook' => 'facebook_url', 'instagram' => 'instagram_url', 'linkedin' => 'linkedin_url', 'youtube' => 'youtube_url', 'x' => 'x_url', 'gbp' => 'gbp_url', 'wikidata' => 'wikidata_url'] as $item => $key) {
        $url = seo_presence_profile_url($key);
        $url ? $add($item, 'ok', 'Profile link set in Settings › Social (used as sameAs in the structured data): ' . $url)
            : $add($item, 'info', 'No profile link in Settings › Social yet' . (trim((string) setting($key, '')) !== '' ? ' (the saved value is only a placeholder).' : '.'));
    }
    // AEO: FAQ coverage
    try {
        $faqs = (int) db_value("SELECT COUNT(*) FROM faqs WHERE status = 'active'");
        $add('aeo_content', $faqs ? 'info' : 'warn', $faqs ? "$faqs active FAQ answers are published on the website." : 'No active FAQs are published.');
    } catch (Throwable $e) {
        // table optional
    }
    return $h;
}

/** KPI counts by status + per category. */
function seo_presence_summary(): array
{
    $counts = array_fill_keys(array_keys(seo_presence_statuses()), 0);
    $byCat = [];
    foreach (db_all('SELECT category, status, COUNT(*) n FROM seo_presence GROUP BY category, status') as $r) {
        $s = isset($counts[$r['status']]) ? $r['status'] : 'not_started';
        $counts[$s] += (int) $r['n'];
        $byCat[$r['category']][$s] = ($byCat[$r['category']][$s] ?? 0) + (int) $r['n'];
    }
    return ['counts' => $counts, 'total' => array_sum($counts), 'by_category' => $byCat];
}

/* =====================================================================================================================
 * Keyword master database (SEO Management › Keywords) — helpers shared by app/modules/seo_keywords.php (form + CSV
 * import) and api/routes/seo-ai.php (cannibalisation report).
 * ===================================================================================================================*/

function seo_keyword_intents(): array
{
    return ['informational' => 'Informational', 'navigational' => 'Navigational', 'commercial' => 'Commercial', 'transactional' => 'Transactional', 'local' => 'Local'];
}

function seo_keyword_priorities(): array
{
    return ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
}

function seo_keyword_statuses(): array
{
    return ['pending' => 'Pending', 'planned' => 'Planned (page needed)', 'targeting' => 'Targeting', 'ranking' => 'Ranking', 'achieved' => 'Achieved', 'paused' => 'Paused'];
}

function seo_keyword_competition(): array
{
    return ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];
}

function seo_keyword_volume_sources(): array
{
    return ['not_measured' => 'Not measured', 'keyword_planner' => 'Google Keyword Planner', 'search_console' => 'Google Search Console', 'google_trends' => 'Google Trends',
        'bing_keywords' => 'Bing keyword research', 'third_party' => 'Third-party tool', 'estimate' => 'Estimate'];
}

/** Text that means "no number" in the owner's sheets. */
function seo_keyword_is_blank($v): bool
{
    $v = mb_strtolower(trim((string) $v));
    return $v === '' || in_array($v, ['not measured', 'not_measured', 'unknown', 'n/a', 'na', '-', '—', 'none', 'not ranking', 'not ranked', 'tbd'], true);
}

/** Compare key for target URLs: path (+ query) without scheme, host, fragment or trailing slash, lower-case. */
function seo_keyword_url_key(?string $url): string
{
    $u = trim((string) $url);
    if ($u === '') {
        return '';
    }
    $u = (string) preg_replace('/^proposed\s*:\s*/i', '', $u);
    $u = (string) preg_replace('#^https?://[^/]+#i', '', $u);
    $u = explode('#', $u, 2)[0];
    $u = '/' . ltrim($u, '/');
    return mb_strtolower($u === '/' ? '/' : rtrim($u, '/'));
}

/**
 * Normalise one keyword row (form or CSV import, including the owner's sheet format: category "Brand / primary
 * (note)", volume "not measured", competition "low (why …)", target "PROPOSED:https://…", position "unknown").
 * @return array [clean data, errors]
 */
function seo_keyword_normalize(array $d): array
{
    $errors = [];
    $notes = [];
    $one = fn ($v) => trim((string) preg_replace('/\s+/u', ' ', (string) $v));
    $data = [];
    $data['keyword'] = $one($d['keyword'] ?? '');
    if ($data['keyword'] === '') {
        $errors['keyword'] = 'Keyword is required.';
    }
    // Category "Name / primary (note)"
    $cat = $one($d['category'] ?? '');
    $primary = array_key_exists('is_primary', $d) && $d['is_primary'] !== '' && $d['is_primary'] !== null
        ? (in_array(is_string($d['is_primary']) ? mb_strtolower(trim($d['is_primary'])) : $d['is_primary'], [true, 1, '1', 'true', 'yes', 'y', 'on', 'primary'], true) ? 1 : 0) : null;
    if ($cat !== '') {
        if (preg_match_all('/\(([^)]*)\)/u', $cat, $m)) {
            foreach ($m[1] as $n) {
                $notes[] = trim($n);
            }
            $cat = $one(preg_replace('/\([^)]*\)/u', '', $cat));
        }
        if (str_contains($cat, ' / ')) {
            [$catName, $role] = array_map($one, explode(' / ', $cat, 2));
            $roleL = mb_strtolower($role);
            if (str_starts_with($roleL, 'primary')) {
                $primary = 1;
            } elseif (str_starts_with($roleL, 'secondary') || str_contains($roleL, 'long-tail') || str_contains($roleL, 'long tail')) {
                $primary = 0;
            }
            if (!in_array($roleL, ['primary', 'secondary'], true) && $roleL !== '') {
                $notes[] = 'Role: ' . $role;
            }
            $cat = $catName;
        }
    }
    $data['category'] = mb_substr($cat, 0, 60) ?: null;
    $data['is_primary'] = $primary ?? 0;
    $cluster = $one($d['cluster'] ?? '');
    $data['cluster'] = mb_substr($cluster !== '' ? $cluster : $cat, 0, 100) ?: null;
    // Options (keys and labels accepted)
    $pick = function (string $field, array $options, ?string $default, string $label) use ($d, &$errors, $one) {
        $v = mb_strtolower($one($d[$field] ?? ''));
        if ($v === '' || seo_keyword_is_blank($v)) {
            return $default;
        }
        foreach ($options as $k => $l) {
            if ($v === (string) $k || $v === mb_strtolower((string) $l) || str_starts_with($v, (string) $k)) {
                return (string) $k;
            }
        }
        $errors[$field] = "Select a valid $label.";
        return $default;
    };
    $data['intent'] = $pick('intent', seo_keyword_intents(), null, 'intent');
    $prio = $pick('priority', seo_keyword_priorities() + ['1' => '1', '2' => '2', '3' => '3'], 'medium', 'priority');
    $data['priority'] = ['1' => 'high', '2' => 'medium', '3' => 'low'][$prio] ?? $prio;
    $data['status'] = $pick('status', seo_keyword_statuses(), 'pending', 'status');
    // Competition "low (reason)" → low + note
    $comp = $one($d['competition'] ?? '');
    $data['competition'] = null;
    if ($comp !== '' && !seo_keyword_is_blank($comp)) {
        if (preg_match('/^(low|medium|med|high)\b\s*[-–:(]?\s*(.*)$/iu', $comp, $m)) {
            $data['competition'] = ['med' => 'medium'][mb_strtolower($m[1])] ?? mb_strtolower($m[1]);
            $rest = trim($m[2], ' )');
            if ($rest !== '') {
                $notes[] = 'Competition: ' . $rest;
            }
        } else {
            $errors['competition'] = 'Use low, medium or high.';
        }
    }
    // Volume + source
    $vol = $one($d['monthly_volume'] ?? ($d['volume'] ?? ''));
    $src = mb_strtolower($one($d['volume_source'] ?? ''));
    $data['monthly_volume'] = null;
    if (!seo_keyword_is_blank($vol)) {
        $n = str_replace([',', ' '], '', $vol);
        if (ctype_digit($n) && (int) $n <= 100000000) {
            $data['monthly_volume'] = (int) $n;
        } else {
            $errors['monthly_volume'] = 'Volume must be a whole number, or “not measured”.';
        }
    }
    $srcKey = null;
    foreach (seo_keyword_volume_sources() as $k => $l) {
        if ($src === $k || $src === mb_strtolower($l)) {
            $srcKey = $k;
        }
    }
    $data['volume_source'] = $srcKey ?? (mb_substr($one($d['volume_source'] ?? ''), 0, 40) ?: ($data['monthly_volume'] === null ? 'not_measured' : null));
    // Difficulty 0–100
    $diff = $one($d['difficulty'] ?? '');
    $data['difficulty'] = null;
    if (!seo_keyword_is_blank($diff)) {
        if (is_numeric($diff) && (float) $diff >= 0 && (float) $diff <= 100) {
            $data['difficulty'] = (int) round((float) $diff);
        } else {
            $errors['difficulty'] = 'Difficulty must be 0–100, or “not measured”.';
        }
    }
    // Position
    $pos = $one($d['current_position'] ?? '');
    $data['current_position'] = null;
    if (!seo_keyword_is_blank($pos)) {
        if (is_numeric($pos) && (float) $pos >= 1 && (float) $pos <= 1000) {
            $data['current_position'] = round((float) $pos, 1);
        } else {
            $errors['current_position'] = 'Position must be a number from 1, or “unknown”.';
        }
    }
    $checked = $one($d['position_checked_at'] ?? '');
    $data['position_checked_at'] = $checked !== '' && strtotime($checked) ? date('Y-m-d', strtotime($checked)) : null;
    // Target URL ("PROPOSED:https://…" = a page still to be created)
    $url = $one($d['target_url'] ?? '');
    if (preg_match('/^proposed\s*:\s*(.*)$/i', $url, $m)) {
        $url = trim($m[1]);
        $notes[] = 'Proposed page — not live yet';
        if ($data['status'] === 'pending') {
            $data['status'] = 'planned';
        }
    }
    if ($url !== '' && !preg_match('#^(https?://[^\s"\'<>]+|/[^\s"\'<>]*)$#i', $url)) {
        $errors['target_url'] = 'Use a full URL (https://…) or a path starting with /.';
    }
    $data['target_url'] = mb_substr($url, 0, 500) ?: null;
    // Notes: typed notes first, then what the import parsed out
    $typed = trim((string) ($d['notes'] ?? ''));
    foreach ($notes as $n) {
        if ($n !== '' && !str_contains(mb_strtolower($typed), mb_strtolower($n))) {
            $typed = trim($typed . ($typed !== '' ? ' · ' : '') . $n);
        }
    }
    $data['notes'] = mb_substr($typed, 0, 500) ?: null;
    foreach (['keyword', 'category', 'cluster', 'notes', 'volume_source'] as $k) {
        if ($data[$k] !== null && cms_seo_has_markup((string) $data[$k])) {
            $errors[$k] = 'Use plain text only (no HTML or scripts).';
        }
    }
    if (mb_strlen($data['keyword']) > 190) {
        $errors['keyword'] = 'Keyword may not be longer than 190 characters.';
    }
    $data['keyword_hash'] = sha1(mb_strtolower($data['keyword']));
    return [$data, $errors];
}

/**
 * Keyword cannibalisation: a cluster whose keywords point to 2+ different URLs, or one URL with 2+ primary keywords.
 * Paused keywords are ignored. @return array{clusters: array, urls: array, rows: array<int, string[]>}
 */
function seo_keyword_conflicts(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) {
        return $cache;
    }
    try {
        $rows = db_all("SELECT id, keyword, cluster, category, is_primary, target_url FROM seo_keywords WHERE status <> 'paused' AND target_url IS NOT NULL AND target_url <> ''");
    } catch (Throwable $e) {
        $rows = [];
    }
    $byCluster = [];
    $byUrl = [];
    foreach ($rows as $r) {
        $u = seo_keyword_url_key($r['target_url']);
        $label = trim((string) ($r['cluster'] ?: $r['category']));
        if ($label !== '') {
            $c = mb_strtolower($label);
            $byCluster[$c]['label'] = $label;
            $byCluster[$c]['urls'][$u] = $r['target_url'];
            $byCluster[$c]['rows'][] = $r;
        }
        if ((int) $r['is_primary'] === 1) {
            $byUrl[$u]['url'] = $r['target_url'];
            $byUrl[$u]['rows'][] = $r;
        }
    }
    $out = ['clusters' => [], 'urls' => [], 'rows' => []];
    foreach ($byCluster as $c) {
        if (count($c['urls']) > 1) {
            $out['clusters'][] = ['cluster' => $c['label'], 'urls' => array_values($c['urls']),
                'keywords' => array_map(fn ($r) => ['id' => (int) $r['id'], 'keyword' => $r['keyword'], 'target_url' => $r['target_url'], 'is_primary' => (bool) $r['is_primary']], $c['rows'])];
            foreach ($c['rows'] as $r) {
                $out['rows'][(int) $r['id']][] = 'cluster_multi_url';
            }
        }
    }
    foreach ($byUrl as $u) {
        if (count($u['rows']) > 1) {
            $out['urls'][] = ['url' => $u['url'], 'keywords' => array_map(fn ($r) => ['id' => (int) $r['id'], 'keyword' => $r['keyword'], 'cluster' => $r['cluster']], $u['rows'])];
            foreach ($u['rows'] as $r) {
                $out['rows'][(int) $r['id']][] = 'url_multi_primary';
            }
        }
    }
    return $cache = $out;
}

/* =====================================================================================================================
 * AI visibility test log (SEO Management › AI visibility) — manual entries only; nothing here queries an AI engine.
 * ===================================================================================================================*/

function seo_ai_engines(): array
{
    return ['chatgpt' => 'ChatGPT', 'perplexity' => 'Perplexity', 'gemini' => 'Gemini', 'copilot' => 'Copilot', 'claude' => 'Claude',
        'google_ai' => 'Google AI Overviews / AI Mode', 'bing' => 'Bing', 'other' => 'Other'];
}

function seo_ai_accuracy_options(): array
{
    return ['accurate' => 'Accurate', 'partly' => 'Partly accurate', 'inaccurate' => 'Inaccurate', 'not_applicable' => 'Not applicable (not mentioned)'];
}

/**
 * The standard monthly test queries: the institute's own list (setting seo_ai_test_queries, one per line) or a
 * default set built only from Settings › General (name, short name, city) — no facts are assumed.
 */
function seo_ai_standard_queries(): array
{
    $custom = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) setting('seo_ai_test_queries', '')) ?: [])));
    if ($custom) {
        return array_slice($custom, 0, 30);
    }
    return seo_ai_default_queries();
}

function seo_ai_default_queries(): array
{
    $name = trim((string) setting('institute_name', '')) ?: 'the institute';
    $short = trim((string) setting('institute_short_name', ''));
    $city = trim((string) setting('city', ''));
    $brand = $short !== '' ? $short . ($city !== '' ? ' ' . $city : '') : $name;
    $where = $city !== '' ? $city : 'my city';
    return [
        "What is $name" . ($city !== '' ? " in $city" : '') . '?',
        "Is $brand a good college for management and technology courses?",
        "Which courses does $brand offer and what are the fees?",
        "How do I apply for admission to $brand?",
        "Best colleges for BBA in $where",
        "Best colleges for MBA in $where",
        "Best colleges for BCA and MCA in $where",
        "Colleges in $where offering UG and PG management courses",
        "Where is $name located and how do I contact the admissions office?",
        "$brand placements and recruiters",
    ];
}
