<?php
/**
 * Core seed (unit seo_platform): settings rows for robots / sitemaps, cookie consent, CAPTCHA, Google Maps, error
 * monitoring and verification (admin: Settings → Integrations, CMS → SEO), plus a few "common alternative URL"
 * redirects (/about-us → /about …). INSERT IGNORE only — values an administrator has already saved are never changed.
 */
return function (array $opts): void {
    $settings = [
        // Spam protection — contract shared with the public forms unit (verification + widget live there)
        ['integrations', 'captcha_provider', 'none'],           // none | recaptcha_v3 | turnstile | hcaptcha
        ['integrations', 'captcha_site_key', ''],
        ['integrations', 'captcha_secret_key', ''],             // secret: masked in every API response; env GIMT_CAPTCHA_SECRET wins
        ['integrations', 'captcha_min_score', '0.5'],
        // Cookie consent + Google Consent Mode v2
        ['integrations', 'consent_banner', 'auto'],             // auto | always | off
        ['integrations', 'consent_mode', 'basic'],              // basic | advanced
        ['integrations', 'consent_title', 'We value your privacy'],
        ['integrations', 'consent_text', 'We use necessary cookies to run this website. With your permission we also use analytics cookies to understand how visitors use it and marketing cookies to measure our admission campaigns.'],
        ['integrations', 'consent_policy_url', '/cookie-policy'],
        ['integrations', 'consent_expiry_days', '180'],
        ['integrations', 'consent_version', '1'],
        // Google Maps (contact page) — the plain embed URL (general.map_embed_url) needs no key
        ['integrations', 'google_maps_api_key', ''],
        ['integrations', 'map_place_query', ''],
        // Error monitoring
        ['integrations', 'client_error_logging', '1'],
        ['integrations', 'sentry_dsn', ''],
        ['integrations', 'sentry_environment', 'production'],
        // Verification / analytics (GA4, GTM, Pixel and Search Console rows belong to the analytics group)
        ['integrations', 'bing_site_verification', ''],
        ['analytics', 'google_analytics_id', ''],
        ['analytics', 'gtm_id', ''],
        ['analytics', 'meta_pixel_id', ''],
        ['analytics', 'search_console_verification', ''],
        // Crawling & indexing
        ['seo', 'robots_txt', ''],                              // empty = recommended rules (app/services/seo.php)
        ['seo', 'seo_block_indexing', '0'],
        ['seo', 'sitemap_images', '1'],
    ];
    foreach ($settings as [$group, $key, $value]) {
        db_exec('INSERT IGNORE INTO settings (`group`, `key`, `value`) VALUES (?, ?, ?)', [$group, $key, $value]);
    }

    // Common alternative URLs visitors and old links use (all 301, editable under Website CMS → Redirects).
    $redirects = [
        ['/home', '/'], ['/index.html', '/'], ['/index.htm', '/'], ['/about-us', '/about'], ['/contact-us', '/contact'],
        ['/courses', '/programs'], ['/course', '/programs'], ['/admission', '/admissions'], ['/apply-now', '/apply'],
        ['/placements', '/placement'], ['/news', '/blog'], ['/faqs', '/faq'], ['/notice-board', '/notices'],
        ['/sitemap_index.xml', '/sitemap.xml'], ['/favicon.ico', '/assets/images/favicon.svg'],
    ];
    if (!db_table_exists('redirects')) {
        return;
    }
    foreach ($redirects as [$from, $to]) {
        db_exec("INSERT IGNORE INTO redirects (from_path, from_hash, to_url, to_path, status_code, preserve_query, is_active, source, notes)
                 VALUES (?, ?, ?, ?, 301, 1, 1, 'system', 'Common alternative URL')", [$from, sha1(mb_strtolower($from)), $to, $to]);
    }
};
