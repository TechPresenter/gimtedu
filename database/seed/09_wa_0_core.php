<?php
/**
 * WhatsApp core (Interakt): default settings (group "whatsapp"), permission rows for every whatsapp* registry module,
 * the WhatsApp team roles and their default grants. Re-runnable and additive only:
 *   - settings are inserted only when missing (user values are never overwritten)
 *   - roles are created only when missing; grants are INSERT IGNORE (existing grants are never removed)
 */
return function (array $opts): void {
    // ------------------------------------------------------------------ settings (never overwrite)
    $settings = [
        'wa_mode' => 'test',
        'wa_interakt_api_key' => '',
        'wa_interakt_base_url' => 'https://api.interakt.ai/v1/public/',
        'wa_webhook_secret' => '',
        'wa_interakt_plan' => 'growth',
        'wa_rate_per_minute' => '250',
        'wa_business_number' => '',
        'wa_business_name' => 'GIMT',
        'wa_default_language' => 'en',
        'wa_business_hours' => '{"mon":["09:00","18:00"],"tue":["09:00","18:00"],"wed":["09:00","18:00"],"thu":["09:00","18:00"],"fri":["09:00","18:00"],"sat":["09:00","14:00"],"sun":null}',
        'wa_bot_enabled' => '0',
        'wa_auto_sync_contacts' => '1',
        'wa_retention_days' => '90',
        'wa_max_attempts' => '5',
        'wa_optout_reply' => 'You have been unsubscribed from GIMT WhatsApp updates. Reply START to subscribe again.',
        'wa_optin_reply' => 'Welcome back! You will now receive GIMT updates on WhatsApp. Reply STOP to unsubscribe.',
        'wa_allow_simulate_live' => '0',
        'wa_brochure_url' => '',
        // Settings › Notifications / Automation / Security (admin part)
        'wa_notify_new_message' => '1',
        'wa_notify_assignment' => '1',
        'wa_notify_handover' => '1',
        'wa_daily_digest' => '0',
        'wa_digest_time' => '09:00',
        'wa_automation_paused' => '0',
        'wa_quiet_enabled' => '0',
        'wa_quiet_start' => '21:00',
        'wa_quiet_end' => '08:00',
        'wa_max_per_contact_day' => '0',
        'wa_export_scope' => 'permission',
        'wa_mask_phones' => '0',
    ];
    foreach ($settings as $k => $v) {
        db_exec('INSERT IGNORE INTO settings (`group`, `key`, `value`) VALUES (?, ?, ?)', ['whatsapp', $k, $v]);
    }
    $biz = db_value("SELECT `value` FROM settings WHERE `key` = 'wa_business_number'");
    if ($biz === '' || $biz === null) {
        $site = db_value("SELECT `value` FROM settings WHERE `key` = 'whatsapp_number'");
        if ($site) {
            db_exec("UPDATE settings SET `value` = ? WHERE `key` = 'wa_business_number'", [preg_replace('/\D+/', '', (string) $site)]);
        }
    }

    // ------------------------------------------------------------------ permission rows
    $modules = array_filter(permission_modules(), fn ($k) => str_starts_with((string) $k, 'whatsapp'), ARRAY_FILTER_USE_KEY);
    foreach ($modules as $module => $def) {
        foreach ($def['actions'] as $a) {
            db_exec('INSERT IGNORE INTO permissions (module, action, label) VALUES (?, ?, ?)', [$module, $a, ucfirst($a) . ' ' . $def['label']]);
        }
    }

    // ------------------------------------------------------------------ roles (create when missing)
    $roles = [
        'admission-manager' => ['Admission Manager', 'Runs the admission desk: WhatsApp inbox, contacts, leads and follow-ups, campaign drafts, reports.', 'blue'],
        'admission-counsellor' => ['Admission Counsellor', 'Chats with applicants and leads on WhatsApp and updates their follow-up stage.', 'cyan'],
        'student-support' => ['Student Support', 'Answers student questions in the WhatsApp inbox.', 'green'],
        'marketing-executive' => ['Marketing Executive', 'Designs WhatsApp templates and runs campaigns.', 'pink'],
    ];
    foreach ($roles as $slug => [$name, $desc, $color]) {
        db_exec('INSERT IGNORE INTO roles (name, slug, description, is_system, is_super, color) VALUES (?, ?, ?, 0, 0, ?)', [$name, $slug, $desc, $color]);
    }

    // ------------------------------------------------------------------ grants (additive)
    // ERP modules (e.g. enquiries) may be granted too: their permission rows come from the core seeders. The admission
    // roles get Enquiries › view + create for "Create admission enquiry" (inbox contact panel, contact record,
    // Leads › Add lead), which needs WhatsApp contacts › edit and Enquiries › create.
    $all = '*';
    $registry = permission_modules();
    $grants = [
        'administrator' => array_fill_keys(array_keys($modules), $all),
        'admission-manager' => ['whatsapp' => $all, 'whatsapp_contacts' => $all, 'whatsapp_templates' => ['view'], 'whatsapp_campaigns' => ['view', 'create'],
            'whatsapp_automation' => ['view'], 'whatsapp_reports' => ['view', 'export'], 'enquiries' => ['view', 'create']],
        'admission-counsellor' => ['whatsapp' => ['view', 'create', 'edit'], 'whatsapp_contacts' => ['view', 'edit'], 'enquiries' => ['view', 'create']],
        'admission-officer' => ['whatsapp' => ['view', 'create', 'edit'], 'whatsapp_contacts' => ['view', 'edit']],
        'accountant' => ['whatsapp' => ['view', 'create'], 'whatsapp_contacts' => ['view'], 'whatsapp_reports' => ['view']],
        'exam-controller' => ['whatsapp' => ['view', 'create'], 'whatsapp_contacts' => ['view']],
        'student-support' => ['whatsapp' => ['view', 'create', 'edit'], 'whatsapp_contacts' => ['view']],
        'marketing-executive' => ['whatsapp_templates' => ['view', 'create', 'edit'], 'whatsapp_campaigns' => $all, 'whatsapp_contacts' => ['view', 'export'],
            'whatsapp_reports' => ['view']],
    ];
    foreach ($grants as $slug => $byModule) {
        $roleId = db_value('SELECT id FROM roles WHERE slug = ?', [$slug]);
        if (!$roleId) {
            continue;
        }
        foreach ($byModule as $module => $actions) {
            if (!isset($registry[$module])) {
                continue;
            }
            $actions = $actions === $all ? $registry[$module]['actions'] : array_values(array_intersect($actions, $registry[$module]['actions']));
            foreach ($actions as $a) {
                db_exec('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE module = ? AND action = ?', [(int) $roleId, $module, $a]);
            }
        }
    }
};
