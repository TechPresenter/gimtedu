<?php
/**
 * Core seed (unit seo-core, 2026-11-17): defaults of the SEO growth settings — extra webmaster verification tags,
 * Microsoft Clarity, click-event tracking, AI crawler policy, llms.txt, IndexNow, PageSpeed key and the extra sameAs
 * profiles (admin: Settings → Integrations; Settings → Social). INSERT IGNORE only — a value an administrator has
 * already saved is never changed, and nothing here claims a profile, verification or submission exists. Re-runnable:
 *   php tools/seed.php 09_seo_growth
 */
return function (array $opts): void {
    $settings = [
        // Webmaster verification (meta tags printed by seo_verification_meta(); env GIMT_*_VERIFICATION wins)
        ['integrations', 'yandex_verification', ''],
        ['integrations', 'baidu_verification', ''],
        ['integrations', 'naver_verification', ''],
        // Analytics (consent-gated loaders live in includes/partials/consent.php)
        ['analytics', 'clarity_project_id', ''],                 // env GIMT_CLARITY_ID
        ['integrations', 'track_click_events', '1'],             // phone / WhatsApp / e-mail / apply / brochure dataLayer events
        // AI search: robots.txt managed block + llms.txt
        ['seo', 'ai_search_crawlers', 'allow'],                  // allow | block (search + user-triggered AI agents)
        ['seo', 'ai_training_crawlers', 'allow'],                // allow | block (training crawlers / control tokens)
        ['seo', 'llms_txt_enabled', '1'],
        ['seo', 'llms_txt_custom', ''],                          // empty = generated from the database
        // IndexNow (off until an administrator switches it on — the key is generated then)
        ['seo', 'indexnow_enabled', '0'],
        ['seo', 'indexnow_key', ''],
        ['seo', 'indexnow_endpoint', 'https://api.indexnow.org/indexnow'],
        // PageSpeed Insights (optional; env GIMT_PAGESPEED_KEY wins)
        ['integrations', 'pagespeed_api_key', ''],
        // Extra sameAs profiles (Settings → Social): only real URLs entered by an administrator are ever printed
        ['social', 'gbp_url', ''],
        ['social', 'wikidata_url', ''],
        ['social', 'sameas_extra', ''],
    ];
    foreach ($settings as [$group, $key, $value]) {
        db_exec('INSERT IGNORE INTO settings (`group`, `key`, `value`) VALUES (?, ?, ?)', [$group, $key, $value]);
    }
};
