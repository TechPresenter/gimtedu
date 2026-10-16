<?php
/**
 * Data-safe database upgrades.
 *
 * Every module can add schema/content changes as functions named  migration_<NNN>_<name>()  (NNN = number;
 * ranges: 100-199 core, 200-299 payments, 300-399 AI assistant, 400-499 analytics, 500-599 design/UI).
 * Pending migrations run once, in number order, automatically on the next request after new files are
 * uploaded (protected by a database lock). Each migration MUST be idempotent (CREATE TABLE IF NOT EXISTS,
 * guarded ALTER via column_exists(), INSERT IGNORE, conditional UPDATE) and must never delete user data.
 * Applied migrations are recorded in `schema_migrations`.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

function table_exists(string $table): bool
{
    return (int) db_value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]) > 0;
}

function column_exists(string $table, string $column): bool
{
    return (int) db_value('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $column]) > 0;
}

function index_exists(string $table, string $index): bool
{
    return (int) db_value('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$table, $index]) > 0;
}

/** ALTER TABLE ... ADD COLUMN only when the column is missing. $definition e.g. "VARCHAR(100) DEFAULT NULL AFTER x". */
function add_column_if_missing(string $table, string $column, string $definition): void
{
    if (table_exists($table) && !column_exists($table, $column)) {
        db()->exec('ALTER TABLE `' . str_replace('`', '', $table) . '` ADD COLUMN `' . str_replace('`', '', $column) . '` ' . $definition);
    }
}

function add_index_if_missing(string $table, string $index, string $definition): void
{
    if (table_exists($table) && !index_exists($table, $index)) {
        db()->exec('ALTER TABLE `' . str_replace('`', '', $table) . '` ADD ' . $definition);
    }
}

/** Insert a setting only when it does not exist yet (never overwrites what the admin entered). */
function add_setting_if_missing(string $key, string $value): void
{
    db_query('INSERT IGNORE INTO website_settings (setting_key, setting_value) VALUES (?, ?)', [$key, $value]);
}

/** Set a setting only when it is missing or still empty. */
function fill_setting_if_empty(string $key, string $value): void
{
    add_setting_if_missing($key, $value);
    db_query("UPDATE website_settings SET setting_value = ? WHERE setting_key = ? AND (setting_value IS NULL OR setting_value = '')", [$value, $key]);
}

/** Add a permission and grant it to the given role slugs (administrator always has everything). */
function add_permission_if_missing(string $key, string $label, string $group, int $sort, array $roleSlugs = []): void
{
    db_query('INSERT IGNORE INTO permissions (perm_key, label, group_name, sort_order) VALUES (?, ?, ?, ?)', [$key, $label, $group, $sort]);
    foreach (array_merge(['administrator'], $roleSlugs) as $slug) {
        db_query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.slug = ? AND p.perm_key = ?', [$slug, $key]);
    }
}

/** All migration functions known to the code, sorted by number: [number => function name]. */
function migration_list(): array
{
    $list = [];
    foreach (get_defined_functions()['user'] as $fn) {
        if (preg_match('/^migration_(\d+)_[a-z0-9_]+$/', $fn, $m)) {
            $list[(int) $m[1]] = $fn;
        }
    }
    ksort($list);
    return $list;
}

/** Run pending migrations. Cheap when nothing is pending (one setting read). */
function run_pending_migrations(bool $force = false): array
{
    static $done = false;
    if ($done && !$force) {
        return [];
    }
    $done = true;
    $list = migration_list();
    if (!$list) {
        return [];
    }
    $latest = (string) max(array_keys($list)) . ':' . count($list);
    try {
        if (!$force && setting('schema_version') === $latest) {
            return [];
        }
    } catch (Throwable $e) {
        return [];   // database not installed yet
    }
    if (!table_exists('website_settings')) {
        return [];
    }
    if ((int) db_value("SELECT GET_LOCK('pwf_migrations', 10)") !== 1) {
        return [];
    }
    $ran = [];
    try {
        db()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            name VARCHAR(120) NOT NULL, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $applied = array_flip(array_column(db_rows('SELECT name FROM schema_migrations'), 'name'));
        foreach ($list as $fn) {
            if (isset($applied[$fn])) {
                continue;
            }
            try {
                $fn();
                db_query('INSERT IGNORE INTO schema_migrations (name) VALUES (?)', [$fn]);
                $ran[] = $fn;
            } catch (Throwable $e) {
                error_log('Migration ' . $fn . ' failed: ' . $e->getMessage());
                break;   // stop at the first failure; it is retried on the next request
            }
        }
        settings_all(true);
        if (count($ran) + count($applied) >= count($list)) {
            save_setting('schema_version', $latest);
        }
        if ($ran) {
            log_activity('database_upgraded', 'Applied: ' . implode(', ', $ran), null, true);
        }
    } finally {
        db_value("SELECT RELEASE_LOCK('pwf_migrations')");
    }
    return $ran;
}

// =====================================================================
// Core migrations (100-199)
// =====================================================================

/**
 * Sites installed from the earlier v2 package (before the verification round) get the final v2 schema and the
 * content corrections (wording, 80G/FCRA and consent text). A section is only updated while it is still exactly
 * the earlier package's text; anything edited in the admin panel is left alone.
 */
function migration_100_v2_final_catchup(): void
{
    add_column_if_missing('donations', 'offline_reference', 'VARCHAR(100) DEFAULT NULL AFTER gateway_method');
    add_index_if_missing('donations', 'idx_donations_offline_ref', 'INDEX idx_donations_offline_ref (offline_reference)');
    foreach (['grievance_ack_days', 'grievance_officer_email', 'grievance_officer_name', 'grievance_resolution_days'] as $key) {
        add_setting_if_missing($key, '');
    }
    db_query("UPDATE website_settings SET setting_value = ? WHERE setting_key = 'fraud_alert_points' AND setting_value = ?", [
        "Do not share OTPs, passwords, card or bank details with anyone.\nDonate only through the Donate page on this website or to the foundation's own bank account.\nIf someone contacts you in our name, verify it using the contact details on this website.",
        "Do not share OTPs, passwords, card or bank details with anyone.\nDonate only through the Donate page on this website or the bank details shown on it.\nIf someone contacts you in our name, verify it using the contact details on this website.",
    ]);
    foreach ([['programs', 'Our Programs', 'Our Programmes'], ['utilization-reports', 'Campaign Utilization Reports', 'Campaign Utilisation Reports'],
              ['volunteer-teacher', 'Volunteer Teacher Program', 'Volunteer Teacher Programme']] as [$slug, $old, $new]) {
        db_query('UPDATE pages SET title = ? WHERE slug = ? AND title = ?', [$new, $slug, $old]);
    }
    // the public /wp-admin/ -> /admin/ redirect advertised the admin address
    db_query("DELETE FROM redirects WHERE source_path = 'wp-admin' AND target_url = '/admin/'");
    // roles: Member / Registered Volunteer only on approval; Supporter for public sign-ups
    db_query("UPDATE roles SET allow_self_register = 0, description = 'Approved member (added when a membership application is approved).' WHERE slug = 'member' AND description = 'Registered member of the foundation.'");
    db_query("UPDATE roles SET allow_self_register = 0, description = 'Volunteer whose application has been activated (added on approval).' WHERE slug = 'registered_volunteer' AND description = 'Website user who volunteers with the foundation.'");
    db_query("UPDATE roles SET description = 'Donates to the foundation and keeps receipts in one place.' WHERE slug = 'donor' AND description = 'Supporter who donates to campaigns.'");
    db_query("UPDATE roles SET description = 'Requests support or a scholarship.' WHERE slug = 'beneficiary_applicant' AND description = 'Person requesting support or a scholarship.'");
    if (!db_value("SELECT COUNT(*) FROM roles WHERE slug = 'supporter'")) {
        db_query("INSERT INTO roles (name, slug, description, is_staff, is_system, allow_self_register, sort_order)
                  VALUES ('Supporter', 'supporter', 'Website account to follow your donations, applications, events and certificates.', 0, 1, 1, 6)");
    }
    $meta = require __DIR__ . '/upgrade/v2-final-meta.php';
    foreach ($meta['pages'] as $p) {
        db_query('UPDATE pages SET meta_description = ? WHERE slug = ? AND meta_description <=> ?', [$p['final'], $p['slug'], $p['early']]);
    }
    foreach ($meta['faq_changed'] as $q) {
        db_query('UPDATE faqs SET question = ?, answer = ? WHERE question = ? AND MD5(answer) = ?', [$q['question'], $q['answer'], $q['early_question'], $q['early_md5']]);
    }
    foreach ($meta['faq_new'] as $q) {
        if (!db_value('SELECT COUNT(*) FROM faqs WHERE question = ?', [$q['question']])) {
            db_query('INSERT INTO faqs (question, answer, category, sort_order, status) VALUES (?, ?, ?, ?, ?)', [$q['question'], $q['answer'], $q['category'], (int) $q['sort_order'], $q['status']]);
        }
    }
    $sections = require __DIR__ . '/upgrade/v2-final-content.php';
    foreach ($sections as $s) {
        $id = db_value("SELECT s.id FROM page_sections s JOIN pages p ON p.id = s.page_id
                        WHERE p.slug = ? AND s.section_type = ? AND s.sort_order = ?
                          AND MD5(CONCAT_WS('|', s.title, s.intro, s.body, s.items, s.settings, s.image, s.pill, s.background)) = ?",
            [$s['page'], $s['type'], $s['sort'], $s['early_md5']]);
        if ($id) {
            $set = $s['set'];
            db_query('UPDATE page_sections SET title = ?, intro = ?, body = ?, items = ?, settings = ?, image = ?, pill = ?, background = ? WHERE id = ?',
                [$set['title'], $set['intro'], $set['body'], $set['items'], $set['settings'], $set['image'], $set['pill'], $set['background'], (int) $id]);
        }
    }
}

/** Official registration details provided by the foundation (Oct 2026) + focus areas line. */
function migration_101_ngo_registration_details(): void
{
    add_setting_if_missing('cin_number', '');
    add_setting_if_missing('ngo_id', '');
    add_setting_if_missing('msme_udyam_number', '');
    add_setting_if_missing('registration_authority', '');
    add_setting_if_missing('focus_areas_text', '');
    fill_setting_if_empty('cin_number', 'U88900BR2025NPL079155');
    fill_setting_if_empty('ngo_id', 'BR/2025/0847299');
    fill_setting_if_empty('msme_udyam_number', 'UDYAM-BR-37-0067136');
    fill_setting_if_empty('registration_authority', 'Ministry of Corporate Affairs, Govt. of India');
    fill_setting_if_empty('focus_areas_text', 'Education | Skill Development | Teacher Support | Community Empowerment');
    fill_setting_if_empty('contact_email', 'info@preritwelfarefoundation.org');
}

/** The home page no longer shows the live impact counters (kept, hidden, so it can be switched back on). */
function migration_102_hide_home_impact(): void
{
    db_query("UPDATE page_sections s JOIN pages p ON p.id = s.page_id SET s.status = 'hidden'
              WHERE p.slug = 'home' AND s.section_type = 'impact_counters'");
}

/** Settings used by the new payment, AI assistant and analytics modules (empty = off / not configured). */
function migration_103_module_settings(): void
{
    foreach ([
        'payment_gateway' => 'razorpay', 'cashfree_enabled' => '0', 'razorpay_enabled' => '1',
        'ai_assistant_enabled' => '0', 'analytics_enhanced' => '0', 'geoip_provider' => 'none',
    ] as $k => $v) {
        add_setting_if_missing($k, $v);
    }
}

/** Volunteer teacher page shows only the application form; terms are accepted in a pop-up before applying. */
function migration_104_teacher_form_only(): void
{
    db_query("UPDATE page_sections s JOIN pages p ON p.id = s.page_id SET s.status = 'hidden'
              WHERE p.slug = 'volunteer-teacher' AND s.section_type <> 'module' AND s.status = 'visible'");
    add_column_if_missing('teacher_applications', 'terms_accepted_at', 'DATETIME DEFAULT NULL');
    add_column_if_missing('teacher_applications', 'terms_version', 'VARCHAR(16) DEFAULT NULL');
    add_setting_if_missing('teacher_terms_title', 'Volunteer Teacher - Terms & Conditions');
    add_setting_if_missing('teacher_terms_intro', 'Please read these terms before you fill in the application form. You need to accept them to continue.');
    add_setting_if_missing('teacher_terms_text', implode("\n", [
        'Volunteer teaching is a voluntary role. No payment is made for volunteering unless it has been agreed in writing by the foundation.',
        'The foundation never charges any fee for applications, interviews, joining, training or certificates. If anyone asks you for money in our name, report it on the Report Fraud page.',
        'You confirm that you are 18 years or older, or that your parent / guardian agrees to this application (the foundation will ask for their written consent before you take part in any activity).',
        'Before any placement the foundation will verify your identity and references and give you a child protection briefing. The foundation may decline or end a placement at any time to keep learners safe.',
        'You will follow the Child Protection Policy and the Volunteer Policy and code of conduct: teach only in group settings or sessions arranged by our coordinators, never contact children privately outside these sessions, and never use physical punishment or abusive language.',
        'You will not photograph, record or share images or personal details of learners without written permission from the foundation and the parent / guardian.',
        'You will keep the personal information of learners and their families confidential.',
        'Submitting an application does not guarantee selection or a placement; sessions, timings and placements depend on the needs of learners and partner schools.',
        'The information you give must be true. Your details are used only to process your application, as explained in the Privacy Policy.',
        'Please inform your coordinator in advance if you cannot attend a session or wish to stop volunteering.',
    ]));
}
