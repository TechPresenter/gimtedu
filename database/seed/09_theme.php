<?php
/**
 * Core seed: public website colour theme (Light / Dark / System) + listing view modes (Grid / List / Compact /
 * Detailed). INSERT IGNORE only — never overwrites values an administrator has changed. Re-runnable.
 *   php tools/seed.php 09_theme
 *
 * site_theme_default   light | dark | system — theme for visitors who have not picked one (system = follow the
 *                      device's prefers-color-scheme)
 * site_theme_toggle    1 = show the Light / Dark / System control in the header + menu drawer and remember the
 *                      visitor's choice; 0 = hide it and always use site_theme_default
 * view_mode_default    grid | list | compact | detailed — SSR default of every listing (07_website_v2 seeds it too)
 * view_mode_{key}      per-listing default that wins over view_mode_default (keys used by the pages: programs,
 *                      faculty, notices, events, news); visitors' own choices are remembered per listing in their
 *                      browser (localStorage)
 * site_brand_color / site_accent_color / site_font_heading / site_font_body — website brand colours & fonts
 *                      (Settings › Website › Brand colours & fonts, app/services/site_brand.php); the values below are
 *                      the standard design, which prints no override at all
 * Behaviour: includes/partials/theme.php, view_mode_*() in includes/components.php, frontend/site/core/{theme,viewmode}.ts.
 */
return function (array $opts): void {
    $settings = [
        'site_theme_default' => 'system',
        'site_theme_toggle' => '1',
        'view_mode_default' => 'grid',
        'view_mode_programs' => 'grid',
        'view_mode_faculty' => 'grid',
        'view_mode_notices' => 'list',
        'view_mode_events' => 'grid',
        'view_mode_news' => 'grid',
        'site_brand_color' => '#0B2A5B',
        'site_accent_color' => '#F05A28',
        'site_font_heading' => 'plus-jakarta-sans',
        'site_font_body' => 'inter',
    ];
    foreach ($settings as $key => $value) {
        db_exec('INSERT IGNORE INTO settings (`group`, `key`, `value`) VALUES (?, ?, ?)', ['website', $key, $value]);
    }
};
