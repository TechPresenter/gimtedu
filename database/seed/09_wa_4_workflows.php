<?php
/**
 * WhatsApp automation workflows — the brief's example workflow "New Admission Enquiry" (trigger GIMT_ENQUIRY_RECEIVED →
 * create / link contact + ERP enquiry ID → welcome template → wait 10 minutes → course information → wait 24 h for a
 * reply → replied: assign counsellor (notified with the lead's name, phone and course) → "a counsellor will call you"
 * (callback_requested — no date or time is promised) | no reply: follow-up → wait 48 h → final follow-up → end) plus two
 * more starters (admission-fee payment pending nudges, application incomplete reminder). Seeded as DRAFTS: an admin
 * reviews the templates and activates them in Automation Builder.
 *
 * Re-runnable and non-destructive: each seeded workflow carries trigger_config.seed_key. A re-run inserts missing ones
 * and refreshes a seeded workflow only while it is untouched (version 1) and not active — or, when active, only if its
 * stored definition no longer passes validation (e.g. the earlier starter's made-up "counselling tomorrow at 11:00 AM"
 * step). An edited workflow (version > 1) is never changed.
 */
return function (array $opts): void {
    require_once is_file(APP_ROOT . '/app/services/whatsapp.php') ? APP_ROOT . '/app/services/whatsapp.php' : APP_ROOT . '/app/services/whatsapp_workflows.php';

    $adminId = db_value("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.is_super = 1 ORDER BY u.id LIMIT 1");
    foreach (wa_wf_starters() as $key => $s) {
        $def = wa_wf_normalize_definition($s['definition']);
        $cols = wa_wf_trigger_columns($def);
        $cols['trigger_config'] = wa_wf_json(wa_wf_decode($cols['trigger_config'], []) + ['seed_key' => $key]);
        $row = [
            'name' => $s['name'], 'description' => $s['description'], 'definition' => wa_wf_json($def),
            'business_hours_only' => (int) $s['business_hours_only'], 'allow_reentry' => 0,
        ] + $cols;
        $existing = db_row('SELECT * FROM wa_workflows WHERE trigger_config LIKE ? ORDER BY id LIMIT 1', ['%"seed_key":"' . $key . '"%']);
        if (!$existing) {
            db_insert('wa_workflows', $row + ['status' => 'draft', 'version' => 1, 'created_by' => $adminId ?: null, 'updated_by' => $adminId ?: null]);
        } elseif ((int) $existing['version'] === 1
            && ($existing['status'] !== 'active' || wa_wf_has_errors(wa_wf_validate(wa_wf_definition($existing))))) {
            // runs keep the definition they started with; only new runs use the refreshed one
            db_update('wa_workflows', $row, 'id = ?', [(int) $existing['id']]);
        }
    }
    wa_wf_reset_cache();
};
