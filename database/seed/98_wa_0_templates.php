<?php
/**
 * WhatsApp templates — DEMO state (runs before the other 98_wa_* demo seeders, after 09_wa_1_templates).
 * Marks the built-in GIMT templates as approved in test mode with fake Interakt / Meta ids (so events, workflows,
 * campaigns and the inbox can be demoed locally), and adds a few templates in the other review states
 * (pending, rejected, paused, archived, draft, a Hindi version) so the library shows every state.
 * Re-runnable and deterministic: the extra demo templates are upserted by (name, language) and the built-in ones are
 * updated in place, so template ids never change on a re-run. On a live Interakt connection the built-in templates are
 * left alone (their real Meta status comes from Interakt).
 * It also switches on a few starter events that ship off in production (counsellor intro + staff alerts, application
 * under review, ticket assigned, batch / course events) — see step 3.
 */
return function (array $opts): void {
    // core first: it loads every feature service and registers their hooks (loading this one alone would skip them)
    require_once APP_ROOT . '/app/services/whatsapp.php';
    require_once APP_ROOT . '/app/services/whatsapp_templates.php';

    $today = date('Y-m-d');
    $at = fn (int $daysAgo, string $time = '10:30:00') => date('Y-m-d', strtotime("$today -$daysAgo days")) . ' ' . $time;
    $fakeId = fn (string $name, string $lang) => 'test-tpl-' . substr(md5("$name|$lang"), 0, 12);
    $fakeMeta = fn (string $name, string $lang) => (string) (1000000000000000 + hexdec(substr(md5("meta|$name|$lang"), 0, 12)) % 899999999999999);

    // 1) Built-in templates (09_wa_1_templates): approved in test mode. Never on a live Interakt connection: there the
    //    statuses come from Meta (sync / webhook) and fake approvals would make GIMT send unapproved templates.
    $live = function_exists('wa_mode') && wa_mode() === 'live';
    $builtIn = $live ? [] : db_all("SELECT id, name, language, status FROM wa_templates WHERE is_system = 1 ORDER BY id");
    foreach ($builtIn as $i => $t) {
        if (!in_array($t['status'], ['draft', 'pending', 'approved'], true)) {
            continue;
        }
        $days = 40 - ($i % 30);
        db_update('wa_templates', [
            'status' => 'approved',
            'interakt_id' => $fakeId($t['name'], $t['language']),
            'wa_template_id' => $fakeMeta($t['name'], $t['language']),
            'rejection_reason' => null,
            'submitted_at' => $at($days, '10:' . str_pad((string) (10 + $i % 40), 2, '0', STR_PAD_LEFT) . ':00'),
            'approved_at' => $at($days, '13:' . str_pad((string) (5 + $i % 50), 2, '0', STR_PAD_LEFT) . ':00'),
            'synced_at' => $at(0, '08:00:00'),
            'archived_from' => null,
            'archived_at' => null,
        ], 'id = ?', [$t['id']]);
    }

    // 2) Extra demo templates in every other state
    $demo = [
        [
            'in' => ['name' => 'fee_due_reminder', 'language' => 'hi', 'display_name' => 'फीस भुगतान अनुस्मारक (Hindi)', 'description' => 'Hindi version of the fee due reminder for parents.',
                'category' => 'UTILITY', 'header_type' => 'text', 'header_text' => 'फीस भुगतान अनुस्मारक',
                'body' => "प्रिय {{student_name}}, {{course_name}} की आपकी फीस *{{fee_amount}}* की अंतिम तिथि *{{due_date}}* है।\n\nऑनलाइन भुगतान करें: {{payment_link}}\n\nयदि आप भुगतान कर चुके हैं तो कृपया इस संदेश को अनदेखा करें।",
                'footer' => 'GIMT लेखा कार्यालय', 'button_type' => 'none', 'buttons' => [],
                'samples' => ['student_name' => 'राहुल शर्मा', 'course_name' => 'बीबीए', 'fee_amount' => 'Rs. 45,000', 'due_date' => '15 Nov 2026', 'payment_link' => 'https://gimtedu.com/pay/GIMT8Q2K7']],
            'state' => ['status' => 'approved', 'days' => 21],
        ],
        [
            'in' => ['name' => 'hostel_room_allotted', 'language' => 'en', 'display_name' => 'Hostel room allotted', 'description' => 'Hostel allotment confirmation with room details.',
                'category' => 'UTILITY', 'header_type' => 'text', 'header_text' => 'Hostel room allotted',
                'body' => "Dear {{student_name}}, your hostel room has been allotted: *{{room_details}}*.\n\nPlease report to the hostel warden office with your college ID card and the signed undertaking before {{last_date}}.",
                'footer' => 'GIMT Hostel Office', 'button_type' => 'cta', 'buttons' => [['type' => 'PHONE_NUMBER', 'text' => 'Call hostel office', 'phone_number' => '+919060031540']],
                'samples' => ['student_name' => 'Rahul Sharma', 'room_details' => 'Block B, Room 214 (twin sharing)', 'last_date' => '12 Nov 2026']],
            'state' => ['status' => 'pending', 'days' => 0],
        ],
        [
            'in' => ['name' => 'diwali_fee_offer', 'language' => 'en', 'display_name' => 'Diwali fee offer', 'description' => 'Festive fee discount promotion (rejected by Meta — kept for reference).',
                'category' => 'UTILITY', 'header_type' => 'none',
                'body' => "Hello {{student_name}}, celebrate Diwali with a flat 10% off on your tuition fee! Pay before {{last_date}} to get this festive offer.",
                'footer' => 'GIMT Accounts Office', 'button_type' => 'none', 'buttons' => [],
                'samples' => ['student_name' => 'Rahul Sharma', 'last_date' => '31 Oct 2026']],
            'state' => ['status' => 'rejected', 'days' => 12, 'reason' => 'INCORRECT_CATEGORY: The template is promotional (discount offer) but was submitted as Utility. Submit it again in the Marketing category.'],
        ],
        [
            'in' => ['name' => 'placement_drive_alert', 'language' => 'en', 'display_name' => 'Placement drive alert', 'description' => 'Announces an upcoming campus placement drive to final-year students.',
                'category' => 'MARKETING', 'header_type' => 'text', 'header_text' => 'Campus placement drive',
                'body' => "Hello {{student_name}}, *{{company_name}}* is visiting campus on {{event_date}} for roles open to {{course_name}} students.\n\nRegister on the placement portal before {{last_date}} to be shortlisted.",
                'footer' => 'Reply STOP to unsubscribe', 'button_type' => 'quick_reply', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Interested'], ['type' => 'QUICK_REPLY', 'text' => 'Not interested']],
                'samples' => ['student_name' => 'Rahul Sharma', 'company_name' => 'Infosys', 'event_date' => '18 Nov 2026', 'course_name' => 'MBA', 'last_date' => '14 Nov 2026']],
            'state' => ['status' => 'paused', 'days' => 60],
        ],
        [
            'in' => ['name' => 'fee_reminder_2025', 'language' => 'en', 'display_name' => 'Fee reminder (2025-26)', 'description' => 'Last session\'s fee reminder — replaced by fee_due_reminder.',
                'category' => 'UTILITY', 'header_type' => 'none',
                'body' => "Dear {{student_name}}, your fee of {{fee_amount}} is due on {{due_date}}. Please pay on time to avoid a late fee.",
                'footer' => 'GIMT Accounts Office', 'button_type' => 'none', 'buttons' => [],
                'samples' => ['student_name' => 'Rahul Sharma', 'fee_amount' => 'Rs. 42,000', 'due_date' => '15 Nov 2025']],
            'state' => ['status' => 'archived', 'from' => 'approved', 'days' => 300],
        ],
        [
            'in' => ['name' => 'convocation_invitation', 'language' => 'en', 'display_name' => 'Convocation invitation', 'description' => 'Invitation to graduating students for the annual convocation.',
                'category' => 'MARKETING', 'header_type' => 'text', 'header_text' => 'Convocation {{academic_session}}',
                'body' => "Dear {{student_name}}, we are delighted to invite you to the {{institute_name}} Convocation on {{event_date}} at {{event_venue}}.\n\nPlease confirm your attendance so that we can reserve your gown and seat.",
                'footer' => 'GIMT Examination Cell', 'button_type' => 'quick_reply', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'I will attend'], ['type' => 'QUICK_REPLY', 'text' => 'Cannot attend']],
                'samples' => ['academic_session' => '2026', 'student_name' => 'Rahul Sharma', 'institute_name' => 'Global Institute of Management & Technology', 'event_date' => '19 Dec 2026', 'event_venue' => 'GIMT Auditorium, Noida']],
            'state' => ['status' => 'draft', 'days' => 2],
        ],
    ];

    // Upsert by (name, language) so ids stay stable on re-runs (demo messages / campaigns seeded later point at them)
    $admin = (int) (db_value("SELECT id FROM users WHERE username = 'admin' LIMIT 1") ?: 0) ?: null;
    foreach ($demo as $d) {
        $existingId = (int) (db_value('SELECT id FROM wa_templates WHERE name = ? AND language = ? AND is_system = 0', [$d['in']['name'], $d['in']['language']]) ?: 0);
        $c = wa_tpl_compile($d['in'], $existingId ?: null);
        $errors = wa_tpl_blocking($c['issues']);
        if ($errors) {
            throw new RuntimeException('Demo template ' . $d['in']['name'] . ' invalid: ' . json_encode($errors));
        }
        $s = $d['state'];
        $row = $c['row'] + ['status' => $s['status'], 'is_system' => 0, 'created_by' => $admin, 'updated_by' => $admin,
            'interakt_id' => null, 'wa_template_id' => null, 'rejection_reason' => null, 'submitted_at' => null, 'approved_at' => null,
            'synced_at' => null, 'archived_from' => null, 'archived_at' => null, 'parent_id' => null];
        $name = $d['in']['name'];
        $lang = $d['in']['language'];
        if ($s['status'] !== 'draft') {
            $row['interakt_id'] = $fakeId($name, $lang);
            $row['submitted_at'] = $at($s['days'] + 1, '11:20:00');
            $row['synced_at'] = $at(0, '08:00:00');
        }
        if (in_array($s['status'], ['approved', 'paused', 'archived'], true)) {
            $row['wa_template_id'] = $fakeMeta($name, $lang);
            $row['approved_at'] = $at($s['days'], '15:45:00');
        }
        if ($s['status'] === 'rejected') {
            $row['wa_template_id'] = $fakeMeta($name, $lang);
            $row['rejection_reason'] = $s['reason'];
        }
        if ($s['status'] === 'archived') {
            $row['archived_from'] = $s['from'];
            $row['archived_at'] = $at(150, '17:00:00');
        }
        if ($s['status'] === 'draft') {
            $row['created_at'] = $at($s['days'], '16:10:00');
        }
        if ($existingId) {
            db_update('wa_templates', $row, 'id = ?', [$existingId]);
        } else {
            db_insert('wa_templates', $row);
        }
    }
    // 3) Demo: switch on a few of the starter events that ship OFF in production (09_wa_1 / 09_wa_2), so every brief
    //    section — staff alerts and courses included — is shown working. Only events whose message never doubles one an
    //    already active event sends at the same moment (offer letter, enrolled, certificate approved, feedback received,
    //    status changed, stage changed … stay off), only events no admin has edited, only with an approved template and
    //    only in test mode. 98_wa_7_logs seeds the history of the ones the demo ERP data triggers (counsellor assignments,
    //    applications moved to approval, ticket assignments); batch / programme / course-completion changes are rare and
    //    simply have not happened in the demo window yet.
    $activated = 0;
    if (!$live && db_table_exists('wa_events')) {
        if (function_exists('wa_evt_link_templates')) {
            wa_evt_link_templates(false); // events seeded before their template existed (older installs): link by name only
        }
        $demoActive = ['GIMT_ENQUIRY_ASSIGNED', 'GIMT_LEAD_TRANSFERRED', 'GIMT_APPLICATION_UNDER_REVIEW', 'GIMT_TICKET_ASSIGNED',
            'GIMT_TICKET_ASSIGNED_STAFF', 'GIMT_BATCH_ALLOCATED', 'GIMT_COURSE_COMPLETED', 'GIMT_COURSE_UPDATED'];
        $place = implode(',', array_fill(0, count($demoActive), '?'));
        $activated = db_exec("UPDATE wa_events e JOIN wa_templates t ON t.id = e.template_id
                                 SET e.is_active = 1
                               WHERE e.code IN ($place) AND e.is_active = 0 AND e.updated_by IS NULL AND t.status = 'approved'", $demoActive);
    }
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, '    WhatsApp templates: ' . count($builtIn) . " built-in approved (test mode), " . count($demo) . " demo templates in other states, $activated starter event(s) switched on\n");
    }
};
