<?php
/**
 * Core seed: website Design v3 settings (public website chrome, campaign landing pages, footer credit, view modes).
 * INSERT IGNORE only — never overwrites values an administrator has changed. Re-runnable.
 *   php tools/seed.php 07_website_v2
 */
return function (array $opts): void {
    $phone = (string) (db_value("SELECT value FROM settings WHERE `key` = 'phone'") ?: '+91 9955446477');
    $settings = [
        // campaign landing pages (/admissions-open and /admissions-open/{program})
        'admissions_deadline' => '2026-11-30 23:59',           // real application deadline → countdown (hidden when empty/past)
        'landing_indexable' => '0',                              // 0 = robots noindex,follow on ad landing pages
        'landing_show_seats' => '0',                             // 1 = show the real seats-filled % (confirmed admissions vs intake) on landing pages
        'landing_hero_title' => 'Industry-Ready UG & PG Programs at',
        'landing_hero_pill' => 'Global Institute of Management & Technology',
        'landing_scholarship_text' => '',                        // empty = computed from the scholarships table
        'landing_strip_text' => '',                              // "A | B | C"; empty = computed (admissions, deadline, scholarships, helpline)
        'landing_form_title' => 'Talk to an Admission Expert',
        'landing_form_highlight' => 'Admission Expert',
        'landing_form_subtitle' => 'Get a Free Career Counselling Session',
        'landing_financing_title' => 'Semester-wise fee payment',
        'landing_financing_text' => 'Pay your tuition semester-wise or in instalments as per the approved fee structure; our admission team also guides you on education-loan documentation.',
        // approvals / affiliations (generic emblems; keep in sync with the "affiliation" setting)
        'approvals_list' => json_encode([
            ['label' => 'State University', 'title' => 'Affiliated to State University', 'text' => 'Programs affiliated to the State University', 'icon' => 'pillars'],
            ['label' => 'AICTE', 'title' => 'Approved by AICTE', 'text' => 'Technical & management programs approved by AICTE', 'icon' => 'seal'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        // site chrome
        'announcement_strip_text' => 'Admissions Open for 2026-27 · UG, PG, Diploma & Certificate programs | Merit scholarships available for eligible students | Talk to an admission counsellor: ' . $phone,
        'admission_bar_text' => '',
        'site_admission_bar' => '0',
        'site_mobile_cta' => '1',
        'site_enquiry_tab' => '1',
        'site_chat_style' => 'tab',                              // tab | button | none
        'site_cursor' => '0',
        'page_hero_variant' => 'light',                          // light | dark | photo
        'view_mode_default' => 'grid',                           // grid | list | compact | detailed
        // footer developer credit
        'footer_credit_text' => 'Design, Maintain And Developed By',
        'footer_credit_name' => 'Appsgain Technologies Private Limited',
        'footer_credit_url' => 'https://appsgain.in',
    ];
    foreach ($settings as $key => $value) {
        db_exec('INSERT IGNORE INTO settings (`group`, `key`, `value`) VALUES (?, ?, ?)', ['website', $key, $value]);
    }
};
