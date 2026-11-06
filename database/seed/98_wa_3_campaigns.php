<?php
/**
 * WhatsApp bulk campaigns demo (part: campaigns): 8 campaigns of the 2026-27 admission season anchored on the day the
 * seeder runs — completed (admissions open, scholarship, Dussehra wishes, exam schedule), one sending right now (PG fee
 * reminders: sent late in the morning, paused, resumed a minute ago with the last 10 recipients left, so the worker
 * finishes it within a minute or two without topping the inbox), one scheduled (event invitation), one cancelled midway
 * (admission closing reminder) and one draft with an uploaded alumni list (CSV rows incl. invalid / duplicate numbers).
 *
 * Recipients come from the real audience rules (wa_cmp_candidates: every campaign — utility ones too — only reaches
 * contacts with opt_in = 1; opted-out, blocked and not-opted-in contacts are recorded as skipped) over the demo contacts
 * of 98_wa_1_contacts, with template messages (rendered per recipient), realistic delivery / read / failure mixes, Meta
 * error reasons, costs and inbound replies within 72 h. Conversations are created only for contacts that had none.
 *
 * Re-runnable: removes every campaign (+ recipients, uploads), the messages of those campaigns, the seeded replies
 * (callback_data 'seed:campaign'), seeded activity-log lines and conversations left empty by that cleanup.
 */
require_once __DIR__ . '/lib/demo_data.php';

return function (array $opts): void {
    if (!db_table_exists('wa_campaigns') || !db_table_exists('wa_campaign_uploads') || !db_table_exists('wa_contacts')) {
        return;
    }
    if (is_file(APP_ROOT . '/app/crud.php')) {
        require_once APP_ROOT . '/app/crud.php';
    }
    require_once APP_ROOT . '/app/services/whatsapp.php';
    if (!function_exists('wa_cmp_candidates')) {
        return;
    }
    demo_seed(98003);
    $now = time();
    $fmt = fn (int $ts) => date('Y-m-d H:i:s', $ts);

    /* ---------------------------------------------------------------- cleanup */
    $oldContacts = array_map('intval', db_column('SELECT DISTINCT contact_id FROM wa_campaign_recipients WHERE contact_id IS NOT NULL'));
    $oldCampaigns = array_map('intval', db_column('SELECT id FROM wa_campaigns'));
    foreach (array_chunk($oldCampaigns, 200) as $chunk) {
        db_exec('DELETE FROM wa_messages WHERE campaign_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk);
    }
    db_exec("DELETE FROM wa_messages WHERE callback_data = 'seed:campaign'");
    db_exec('DELETE FROM wa_campaign_recipients');
    db_exec('DELETE FROM wa_campaign_uploads');
    db_exec('DELETE FROM wa_campaigns');
    db_exec("DELETE FROM activity_logs WHERE module = 'whatsapp_campaigns' AND meta LIKE '%\"seed\":\"wa_campaigns\"%'");
    foreach (array_chunk($oldContacts, 500) as $chunk) {
        db_exec('DELETE cv FROM wa_conversations cv LEFT JOIN wa_messages m ON m.conversation_id = cv.id
                  WHERE m.id IS NULL AND cv.contact_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk);
    }

    $contactsCount = (int) db_value("SELECT COUNT(*) FROM wa_contacts WHERE status <> 'archived'");
    if ($contactsCount < 20) {
        return; // the contacts demo (98_wa_1_contacts) has not run
    }

    /* ---------------------------------------------------------------- people */
    $admin = db_row("SELECT u.id, u.name FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                     WHERE u.status = 'active' AND r.is_super = 1 ORDER BY u.id LIMIT 1") ?: db_row('SELECT id, name FROM users ORDER BY id LIMIT 1');
    if (!$admin) {
        return;
    }
    $officer = db_row("SELECT u.id, u.name FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                       WHERE u.status = 'active' AND r.slug IN ('admission-officer','admissions') ORDER BY u.id LIMIT 1") ?: $admin;
    $accounts = db_row("SELECT u.id, u.name FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                        WHERE u.status = 'active' AND r.slug IN ('accountant','accounts') ORDER BY u.id LIMIT 1") ?: $admin;

    $programIds = fn (array $codes) => array_map('intval', db_column('SELECT id FROM programs WHERE code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')', $codes));
    $segmentId = fn (string $name) => ($v = db_value('SELECT id FROM wa_segments WHERE name = ?', [$name])) ? (int) $v : null;
    $day = fn (int $offsetDays, string $time = '10:00') => strtotime(date('Y-m-d', $now + $offsetDays * 86400) . ' ' . $time . ':00');

    /* ---------------------------------------------------------------- campaign definitions */
    $failReasons = [
        'MARKETING' => [
            ['131049', 'This message was not delivered to maintain healthy ecosystem engagement.', 45],
            ['1013', 'Recipient is not a valid WhatsApp user', 30],
            ['131026', 'Message undeliverable', 20],
            ['130472', 'User\'s number is part of an experiment', 5],
        ],
        'UTILITY' => [
            ['1013', 'Recipient is not a valid WhatsApp user', 55],
            ['131026', 'Message undeliverable', 40],
            ['131047', 'Re-engagement message: more than 24 hours have passed since the customer last replied', 5],
        ],
    ];
    $replies = [
        'admission' => ['Please share the fee structure for MBA', 'Interested. Please call me after 5 pm', 'Is there any scholarship for 85% in 12th?', 'Hostel facility available hai?',
            'What is the last date to apply?', 'Kya entrance exam dena hoga?', 'Please send the brochure', 'Can I visit the campus this Saturday?', 'STOP'],
        'scholarship' => ['How do I apply for the scholarship?', 'My 12th score is 82%, am I eligible?', 'Interested, please call', 'Is the scholarship for all 3 years?', 'Documents kaun se chahiye?'],
        'festival' => ['Happy Dussehra to you too!', 'Thank you 🙏', 'Same to you and the GIMT family', 'Thanks! Wishing you the same'],
        'exam' => ['Thank you', 'Where can I download the admit card?', 'Is the exam offline?', 'Ok noted', 'Practical exam ki date kab aayegi?'],
        'fee_reminder' => ['Paid today via UPI', 'Can I pay in two instalments?', 'The payment link is not opening', 'Will pay by Friday', 'Scholarship adjust hogi kya?'],
        'admission_closing' => ['Please extend the date, documents pending', 'Applied today', 'Call me please', 'Is lateral entry available?'],
    ];

    $defs = [
        [
            'key' => 'admission', 'name' => 'Admissions Open 2026-27 — MBA, BBA & BCA', 'type' => 'admission', 'tpl' => 'admission_open_campaign',
            'description' => 'Launch announcement to every opted-in enquiry and applicant for the 2026-27 intake.',
            'audience' => ['contact_types' => ['lead', 'applicant']], 'status' => 'completed', 'start' => $day(-26, '10:30'),
            'rate' => null, 'by' => $officer, 'mix' => ['failed' => 0.04, 'delivered' => 0.95, 'read' => 0.71, 'reply' => 0.12], 'progress' => 1.0,
            'map' => [],
        ],
        [
            'key' => 'scholarship', 'name' => 'Merit Scholarship 2026 — up to 50% tuition waiver', 'type' => 'scholarship', 'tpl' => 'scholarship_announcement',
            'description' => 'Scholarship slabs for 90%+ / 80%+ scorers, sent to interested leads and their parents.',
            'audience' => ['contact_types' => ['lead', 'applicant', 'guardian'], 'programs' => $programIds(['BBA', 'MBA', 'BCA', 'BTCSE'])],
            'status' => 'completed', 'start' => $day(-19, '11:00'), 'rate' => 120, 'by' => $officer,
            'mix' => ['failed' => 0.05, 'delivered' => 0.94, 'read' => 0.64, 'reply' => 0.09], 'progress' => 1.0,
            'map' => ['body.4' => ['source' => 'literal', 'value' => date('d M Y', $day(-5)), 'fallback' => '']],
        ],
        [
            'key' => 'festival', 'name' => 'Dussehra wishes to students & parents', 'type' => 'festival', 'tpl' => 'festival_greeting',
            'description' => 'Festival greeting from the Director to opted-in students and parents.',
            'audience' => ['contact_types' => ['student', 'guardian'], 'programs' => $programIds(['BBA', 'BCA'])],
            'status' => 'completed', 'start' => $day(-14, '08:30'), 'rate' => null, 'by' => $admin,
            'mix' => ['failed' => 0.03, 'delivered' => 0.96, 'read' => 0.78, 'reply' => 0.06], 'progress' => 1.0,
            'map' => ['body.2' => ['source' => 'literal', 'value' => 'Dussehra', 'fallback' => '']],
        ],
        [
            'key' => 'exam', 'name' => 'Semester 1 end-term exam schedule released', 'type' => 'exam', 'tpl' => 'exam_schedule_released',
            'description' => 'Utility notice to current students who opted in to WhatsApp updates.',
            'audience' => ['contact_types' => ['student'], 'programs' => $programIds(['BBA', 'MBA', 'BCA'])],
            'status' => 'completed', 'start' => $day(-9, '16:00'), 'rate' => null, 'by' => $admin,
            'mix' => ['failed' => 0.02, 'delivered' => 0.98, 'read' => 0.84, 'reply' => 0.05], 'progress' => 1.0,
            'map' => ['body.2' => ['source' => 'literal', 'value' => 'Semester 1 End-Term Examination', 'fallback' => ''],
                'body.3' => ['source' => 'literal', 'value' => date('d M Y', $day(32)), 'fallback' => '']],
        ],
        [
            'key' => 'fee_reminder', 'name' => 'November fee reminder — PG overdue instalments', 'type' => 'fee_reminder', 'tpl' => 'fee_due_reminder',
            'description' => 'Second-instalment reminder for MBA, MCA and M.Com students with the online payment link; throttled to 10 per minute and paused while the accounts helpline caught up.',
            'audience' => $segmentId('Fee overdue — students') ? ['segment_id' => $segmentId('Fee overdue — students'), 'programs' => $programIds(['MBA', 'MCA', 'MCOM'])]
                : ['contact_types' => ['student'], 'payment_status' => ['overdue', 'pending'], 'programs' => $programIds(['MBA', 'MCA', 'MCOM'])],
            // nearly done: sent late in the morning, paused, resumed a minute ago with only the last few recipients left —
            // the worker finishes it within a minute or two, and the inbox is not topped by a wall of reminders
            'status' => 'sending', 'start' => $now - 5 * 3600, 'rate' => 10, 'remaining' => 10, 'by' => $accounts,
            'mix' => ['failed' => 0.03, 'delivered' => 0.96, 'read' => 0.58, 'reply' => 0.08], 'progress' => 1.0,
            'map' => [
                'body.2' => ['source' => 'contact', 'value' => 'due_amount', 'fallback' => 'your pending fee'],
                'body.4' => ['source' => 'contact', 'value' => 'due_date', 'fallback' => date('d M Y', $day(10))],
                'body.5' => ['source' => 'literal', 'value' => 'https://gimtedu.com/pay', 'fallback' => ''],
            ],
        ],
        [
            'key' => 'event', 'name' => 'Industry Connect Summit 2026 — invitation', 'type' => 'event', 'tpl' => 'event_invitation',
            'description' => 'Invite opted-in students and alumni to the summit with HR leaders from 20+ recruiters.',
            'audience' => ['contact_types' => ['student', 'alumni']], 'status' => 'scheduled', 'scheduled' => $day(2, '10:30'),
            'rate' => 200, 'by' => $officer, 'business_hours' => 1,
            'map' => ['body.2' => ['source' => 'literal', 'value' => 'Industry Connect Summit 2026', 'fallback' => ''],
                'body.4' => ['source' => 'literal', 'value' => date('d M Y', $day(9)), 'fallback' => ''],
                'body.5' => ['source' => 'literal', 'value' => 'GIMT Auditorium, Knowledge Park, Greater Noida', 'fallback' => '']],
        ],
        [
            'key' => 'admission_closing', 'name' => 'Last date reminder — BCA & B.Tech applications', 'type' => 'admission_closing', 'tpl' => 'admission_closing_reminder',
            'description' => 'Stopped midway: the last date was extended by a week, a corrected reminder will follow.',
            'audience' => ['contact_types' => ['lead', 'applicant'], 'programs' => $programIds(['BCA', 'BTCSE', 'BTAIDS'])],
            'status' => 'cancelled', 'start' => $day(-5, '12:00'), 'cancel_after_min' => 3, 'rate' => 30, 'by' => $officer,
            'mix' => ['failed' => 0.05, 'delivered' => 0.93, 'read' => 0.6, 'reply' => 0.07], 'progress' => 0.35,
            'map' => ['body.4' => ['source' => 'literal', 'value' => date('d M Y', $day(-1)), 'fallback' => '']],
        ],
        [
            'key' => 'alumni', 'name' => 'Alumni Meet 2026 — save the date', 'type' => 'alumni', 'tpl' => 'alumni_meetup',
            'description' => 'Draft: alumni in the CRM plus the list shared by the Alumni Cell (to be reviewed before scheduling).',
            'audience' => ['contact_types' => ['alumni']], 'source' => 'mixed', 'status' => 'draft', 'rate' => null, 'by' => $admin,
            'map' => ['body.3' => ['source' => 'literal', 'value' => date('d M Y', $day(40)), 'fallback' => ''],
                'body.4' => ['source' => 'literal', 'value' => '', 'fallback' => '']],
        ],
    ];

    $pickReason = function (string $category) use ($failReasons): array {
        $list = $failReasons[$category === 'MARKETING' ? 'MARKETING' : 'UTILITY'];
        $total = array_sum(array_column($list, 2));
        $r = mt_rand(1, $total);
        foreach ($list as $f) {
            $r -= $f[2];
            if ($r <= 0) {
                return $f;
            }
        }
        return $list[0];
    };
    $chance = fn (float $p) => mt_rand(0, 9999) < (int) round($p * 10000);
    $seedLog = function (int $campaignId, string $action, string $text, ?int $userId, int $ts) use ($fmt) {
        db_insert('activity_logs', ['user_id' => $userId, 'action' => $action, 'module' => 'whatsapp_campaigns', 'record_id' => (string) $campaignId,
            'description' => mb_substr($text, 0, 500), 'status' => 'success', 'ip_address' => '127.0.0.1', 'user_agent' => 'seed',
            'meta' => json_encode(['seed' => 'wa_campaigns']), 'created_at' => $fmt($ts)]);
    };

    $convNew = [];       // contact_id => true (conversation created here)
    $convLast = [];      // contact_id => [ts, preview, direction]
    $convFor = function (array $contactIds) use (&$convNew, $fmt, $now): array {
        $contactIds = array_values(array_unique(array_filter(array_map('intval', $contactIds))));
        $map = [];
        foreach (array_chunk($contactIds, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            foreach (db_all("SELECT contact_id, id FROM wa_conversations WHERE contact_id IN ($ph)", $chunk) as $r) {
                $map[(int) $r['contact_id']] = (int) $r['id'];
            }
        }
        $missing = array_values(array_diff($contactIds, array_keys($map)));
        if ($missing) {
            $rows = [];
            foreach ($missing as $cid) {
                $rows[] = ['contact_id' => $cid, 'status' => 'resolved', 'bot_active' => 1, 'unread_count' => 0, 'created_at' => $fmt($now - 30 * 86400), 'resolved_at' => $fmt($now - 86400)];
                $convNew[$cid] = true;
            }
            demo_bulk_insert('wa_conversations', $rows);
            foreach (array_chunk($missing, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                foreach (db_all("SELECT contact_id, id FROM wa_conversations WHERE contact_id IN ($ph)", $chunk) as $r) {
                    $map[(int) $r['contact_id']] = (int) $r['id'];
                }
            }
        }
        return $map;
    };

    foreach ($defs as $def) {
        $tpl = wa_template_find($def['tpl'], 'en');
        if (!$tpl || $tpl['status'] !== 'approved') {
            continue;
        }
        $category = strtoupper((string) $tpl['category']);
        $aud = wa_cmp_audience_clean($def['audience']);
        $map = array_replace(wa_cmp_default_map($tpl), $def['map'] ?? []);
        $createdTs = isset($def['start']) ? (int) $def['start'] - mt_rand(2, 4) * 86400 - mt_rand(0, 6 * 3600) : (isset($def['scheduled']) ? $now - mt_rand(1, 3) * 86400 : $now - mt_rand(3, 30) * 3600);
        $createdTs = min($createdTs, $now - 1800);
        $byId = (int) $def['by']['id'];
        $cid = db_insert('wa_campaigns', [
            'name' => $def['name'], 'type' => $def['type'], 'description' => $def['description'], 'template_id' => (int) $tpl['id'],
            'audience' => wa_json($aud), 'audience_source' => $def['source'] ?? 'filters', 'variable_map' => wa_json((object) $map),
            'rate_per_minute' => $def['rate'], 'respect_business_hours' => (int) ($def['business_hours'] ?? 0), 'status' => 'draft',
            'created_by' => $byId, 'updated_by' => $byId, 'created_at' => $fmt($createdTs), 'updated_at' => $fmt($createdTs + 1800),
        ]);
        $c = wa_cmp_get($cid);

        /* ---- draft with an uploaded alumni list */
        if ($def['status'] === 'draft') {
            $rows = [['Name', 'Mobile', 'Batch', 'Company', 'WhatsApp consent']];
            $companies = ['Infosys', 'Deloitte', 'HCLTech', 'Axis Bank', 'Wipro', 'KPMG', 'Genpact', 'TCS', 'Maruti Suzuki', 'Paytm', 'Zomato', 'EY'];
            for ($i = 0; $i < 38; $i++) {
                $g = mt_rand(0, 1) ? 'male' : 'female';
                $name = demo_pick(demo_first_names($g)) . ' ' . demo_pick(demo_last_names());
                $phone = substr((string) preg_replace('/\D+/', '', demo_phone()), -10);
                $fmtPhone = ['+91 ' . substr($phone, 0, 5) . ' ' . substr($phone, 5), $phone, '0' . $phone, '91' . $phone][mt_rand(0, 3)];
                $rows[] = [$name, $fmtPhone, (function () { $y = mt_rand(2016, 2023); return $y . '-' . substr((string) ($y + 3), 2); })(), demo_pick($companies), mt_rand(0, 9) < 8 ? 'Yes' : 'No'];
            }
            // realistic problems in a shared spreadsheet
            $rows[] = ['Kunal Mehta', '98765', '2019-22', 'Accenture', 'Yes'];
            $rows[] = ['Sneha Kapoor', '', '2018-21', 'Amazon', 'Yes'];
            $rows[] = ['Arjun Nair', 'not available', '2020-23', 'Flipkart', 'No'];
            $rows[] = [$rows[3][0], $rows[3][1], $rows[3][2], $rows[3][3], 'Yes'];
            $rows[] = [$rows[7][0], $rows[7][1], $rows[7][2], $rows[7][3], 'Yes'];
            try {
                wa_cmp_upload_save_rows($cid, 'alumni-cell-contacts-2016-2023.xlsx', $rows);
                db_update('wa_campaign_uploads', ['uploaded_by' => $byId, 'created_at' => $fmt($createdTs + 1200)], 'campaign_id = ?', [$cid]);
                db_update('wa_campaigns', ['audience_source' => 'mixed'], 'id = ?', [$cid]);
            } catch (Throwable $e) {
                error_log('[seed] campaign upload: ' . $e->getMessage());
            }
            $c = wa_cmp_get($cid);
            $p = wa_cmp_audience_preview($c);
            db_update('wa_campaigns', ['total_recipients' => $p['total'], 'skipped_count' => $p['skipped']], 'id = ?', [$cid]);
            continue;
        }

        /* ---- scheduled: audience estimate only (recipients are built when it starts) */
        if ($def['status'] === 'scheduled') {
            $p = wa_cmp_audience_preview($c);
            db_update('wa_campaigns', ['status' => 'scheduled', 'scheduled_at' => $fmt((int) $def['scheduled']), 'total_recipients' => $p['total'], 'skipped_count' => $p['skipped']], 'id = ?', [$cid]);
            $seedLog($cid, 'test', 'Sent a test of WhatsApp campaign "' . $def['name'] . '" to +919810012345', $byId, $createdTs + 2400);
            $seedLog($cid, 'schedule', 'Scheduled WhatsApp campaign "' . $def['name'] . '" for ' . date('d M Y, h:i A', (int) $def['scheduled']) . ' (' . number_format($p['eligible']) . ' recipients)', $byId, $createdTs + 3000);
            continue;
        }

        /* ---- started campaigns: recipients, messages, outcomes */
        $startTs = (int) $def['start'];
        $rate = (int) ($def['rate'] ?: 250);
        $res = wa_cmp_candidates($c, $category);
        $cands = $res['rows'];
        $eligibleIdx = [];
        foreach ($cands as $i => $r) {
            if (!$r['skip']) {
                $eligibleIdx[] = $i;
            }
        }
        $processN = (int) floor(count($eligibleIdx) * (float) $def['progress']);
        $pauseTs = $resumeTs = null;
        if (isset($def['remaining'])) {
            // in progress: everything but the last few went out at the campaign's rate (late morning when the seeder runs
            // in the afternoon), then Accounts paused it and resumed it a minute ago
            $processN = max(0, count($eligibleIdx) - (int) $def['remaining']);
            $sendSec = (intdiv(max(0, $processN - 1), $rate) + 1) * 60;
            $resumeTs = $now - 75;
            $pauseLen = max(900, min(4 * 3600, $resumeTs - $sendSec - strtotime(date('Y-m-d 10:30:00', $now))));
            $startTs = $resumeTs - $pauseLen - $sendSec;
            $pauseTs = $startTs + $sendSec + mt_rand(20, 50);
        }
        $endTs = $def['status'] === 'cancelled' ? $startTs + (int) $def['cancel_after_min'] * 60 + 40 : null;
        if ($def['status'] === 'cancelled') {
            $processN = min($processN, (int) $def['cancel_after_min'] * $rate);
        }
        // conversations only for the recipients that get a message now (no empty chats in the inbox)
        $reached = [];
        $k = 0;
        foreach ($cands as $r) {
            if (!$r['skip'] && $k++ < $processN && $r['contact_id']) {
                $reached[] = $r['contact_id'];
            }
        }
        $conv = $convFor($reached);
        $contactCache = [];

        $recipients = [];
        $messages = [];
        $inbound = [];
        $seq = 0;
        $reasons = wa_cmp_skip_reasons();
        $cost = $category === 'MARKETING' ? 0.7846 : 0.1150;
        foreach ($cands as $i => $r) {
            $base = [
                'campaign_id' => $cid, 'contact_id' => $r['contact_id'], 'phone' => $r['phone'], 'name' => $r['name'], 'source' => $r['source'],
                'contact_type' => $r['contact_type'], 'variables' => wa_json(['csv' => (object) [], 'consent' => false]), 'message_id' => null,
                'status' => 'pending', 'error' => null, 'skip_reason' => null, 'attempts' => 0, 'queued_at' => null, 'replied_at' => null,
                'created_at' => $fmt($startTs),
            ];
            if ($r['skip']) {
                $base['status'] = 'skipped';
                $base['skip_reason'] = $r['skip'];
                $base['error'] = $reasons[$r['skip']] ?? $r['skip'];
                $recipients[] = $base;
                continue;
            }
            $pos = $seq++;
            if ($pos >= $processN) {
                if ($def['status'] === 'cancelled') {
                    $base['status'] = 'skipped';
                    $base['skip_reason'] = 'cancelled';
                    $base['error'] = 'Campaign cancelled';
                }
                $recipients[] = $base; // pending (sending) / skipped (cancelled)
                continue;
            }
            $contact = $contactCache[$r['contact_id']] ??= wa_contact((int) $r['contact_id']);
            if (!$contact || (int) $contact['opt_in'] !== 1) {
                // every campaign needs a WhatsApp opt-in (wa_cmp_skip_reason) — never record a send to anyone else
                $base['status'] = 'skipped';
                $base['skip_reason'] = 'no_consent';
                $base['error'] = $reasons['no_consent'];
                $recipients[] = $base;
                continue;
            }
            $rv = wa_cmp_resolve($tpl, $map, $contact, []);
            if ($rv['missing']) {
                $base['status'] = 'skipped';
                $base['skip_reason'] = 'missing_variables';
                $base['error'] = 'No value for ' . implode(', ', $rv['missing']);
                $recipients[] = $base;
                continue;
            }
            $queuedTs = $startTs + intdiv($pos, max(1, $rate)) * 60 + mt_rand(0, 55);
            if ($queuedTs > $now - 30) {
                $recipients[] = $base; // not reached yet
                continue;
            }
            $sentTs = $queuedTs + mt_rand(1, 25);
            $render = wa_render_template($tpl, $rv['vars']);
            $iid = 'seed-cmp-' . $cid . '-' . $pos;
            $m = [
                'conversation_id' => $conv[(int) $r['contact_id']] ?? null, 'contact_id' => (int) $r['contact_id'], 'direction' => 'out', 'type' => 'template',
                'body' => $render['text'], 'template_id' => (int) $tpl['id'], 'template_name' => $tpl['name'],
                'template_vars' => wa_json(['header' => $render['header'], 'body' => $render['body'], 'buttons' => (object) $render['buttons'], 'payloads' => (object) [],
                    'file_name' => $render['file_name'], 'language' => $tpl['language'], 'category' => $tpl['category'], 'named' => (object) []]),
                'interactive' => wa_json(['template' => ['header_type' => $tpl['header_type'], 'header_text' => $render['header_text'], 'header_media_url' => $render['media_url'],
                    'file_name' => $render['file_name'], 'body' => $render['text'], 'footer' => $tpl['footer'], 'buttons' => $tpl['buttons']]]),
                'interakt_id' => $iid, 'callback_data' => 'seed:campaign', 'status' => 'sent', 'error_code' => null, 'error' => null, 'cost' => null,
                'sender_type' => 'campaign', 'campaign_id' => $cid, 'created_at' => $fmt($queuedTs), 'sent_at' => $fmt($sentTs),
                'delivered_at' => null, 'read_at' => null, 'failed_at' => null,
            ];
            if (!$m['conversation_id']) {
                $recipients[] = $base;
                continue;
            }
            $rec = $base;
            $rec['attempts'] = 1;
            $rec['queued_at'] = $fmt($queuedTs);
            $rec['variables'] = wa_json(['csv' => (object) [], 'consent' => false, 'resolved' => (object) $rv['values']]);
            $rec['status'] = 'sent';
            $lastTs = $sentTs;
            if ($chance($def['mix']['failed'])) {
                [$code, $err] = $pickReason($category);
                $failTs = $sentTs + mt_rand(2, 90);
                $m['status'] = 'failed';
                $m['error_code'] = $code;
                $m['error'] = $err;
                $m['failed_at'] = $fmt(min($failTs, $now - 5));
                $rec['status'] = 'failed';
                $rec['error'] = $err;
            } elseif ($chance($def['mix']['delivered'])) {
                $delTs = $sentTs + (mt_rand(0, 9) < 8 ? mt_rand(1, 40) : mt_rand(60, 4 * 3600));
                if ($delTs < $now) {
                    $m['status'] = 'delivered';
                    $m['delivered_at'] = $fmt($delTs);
                    $m['cost'] = $cost;
                    $rec['status'] = 'delivered';
                    $lastTs = $delTs;
                    if ($chance($def['mix']['read'])) {
                        $u = mt_rand(0, 99);
                        $readTs = $delTs + ($u < 45 ? mt_rand(5, 900) : ($u < 80 ? mt_rand(900, 4 * 3600) : ($u < 95 ? mt_rand(4 * 3600, 20 * 3600) : mt_rand(20 * 3600, 48 * 3600))));
                        if ($readTs < $now - 10) {
                            $m['status'] = 'read';
                            $m['read_at'] = $fmt($readTs);
                            $rec['status'] = 'read';
                            $lastTs = $readTs;
                            if ($chance($def['mix']['reply'] / max(0.01, $def['mix']['read']))) {
                                $repTs = $readTs + mt_rand(20, 3 * 3600);
                                if ($repTs < $now - 5 && $repTs - $sentTs < WA_CMP_REPLY_WINDOW_HOURS * 3600) {
                                    $rec['replied_at'] = $fmt($repTs);
                                    $text = demo_pick($replies[$def['key']] ?? $replies['admission']);
                                    $inbound[] = [
                                        'conversation_id' => $m['conversation_id'], 'contact_id' => (int) $r['contact_id'], 'direction' => 'in', 'type' => 'text', 'body' => $text,
                                        'interakt_id' => 'seed-cmp-in-' . $cid . '-' . $pos, 'callback_data' => 'seed:campaign', 'status' => 'received', 'sender_type' => 'contact',
                                        'campaign_id' => null, 'created_at' => $fmt($repTs),
                                    ];
                                    $lastTs = $repTs;
                                    $prev = $convLast[(int) $r['contact_id']] ?? null;
                                    if (!$prev || $prev[0] < $repTs) {
                                        $convLast[(int) $r['contact_id']] = [$repTs, $text, 'in'];
                                    }
                                }
                            }
                        }
                    }
                }
            }
            $prev = $convLast[(int) $r['contact_id']] ?? null;
            if (!$prev || $prev[0] < $sentTs) {
                $convLast[(int) $r['contact_id']] = [$sentTs, wa_preview($render['text']), 'out'];
            }
            $rec['_iid'] = $iid;
            $recipients[] = $rec;
            $messages[] = $m;
        }

        demo_bulk_insert('wa_messages', $messages, 200);
        if ($inbound) {
            demo_bulk_insert('wa_messages', $inbound, 200);
        }
        $ids = db_pairs("SELECT interakt_id, id FROM wa_messages WHERE campaign_id = ? AND interakt_id LIKE 'seed-cmp-%'", [$cid]);
        foreach ($recipients as &$rec) {
            if (isset($rec['_iid'])) {
                $rec['message_id'] = isset($ids[$rec['_iid']]) ? (int) $ids[$rec['_iid']] : null;
                unset($rec['_iid']);
            }
        }
        unset($rec);
        demo_bulk_insert('wa_campaign_recipients', $recipients, 300);

        $last = (int) strtotime((string) (db_value('SELECT MAX(sent_at) FROM wa_messages WHERE campaign_id = ?', [$cid]) ?: $fmt($startTs)));
        $set = ['status' => 'sending', 'scheduled_at' => $fmt($startTs), 'started_at' => $fmt($startTs)];
        db_update('wa_campaigns', $set, 'id = ?', [$cid]);
        wa_cmp_sync($cid); // counters (+ completion when nothing is pending)
        if ($def['status'] === 'completed') {
            db_update('wa_campaigns', ['status' => 'completed', 'completed_at' => $fmt($last + mt_rand(20, 70))], 'id = ?', [$cid]);
        } elseif ($def['status'] === 'cancelled') {
            db_update('wa_campaigns', ['status' => 'cancelled', 'cancelled_at' => $fmt(min($endTs, $now - 60)), 'completed_at' => null], 'id = ?', [$cid]);
        } else {
            db_update('wa_campaigns', ['status' => 'sending', 'completed_at' => null, 'paused_at' => null], 'id = ?', [$cid]);
        }
        $c = wa_cmp_get($cid);
        $seedLog($cid, 'test', 'Sent a test of WhatsApp campaign "' . $def['name'] . '" to +919810012345', $byId, $startTs - mt_rand(900, 5400));
        $seedLog($cid, 'send', 'Started WhatsApp campaign "' . $def['name'] . '" to ' . number_format($c['total_recipients'] - $c['skipped_count']) . ' recipients ('
            . number_format($c['skipped_count']) . ' skipped)', $byId, $startTs);
        if ($pauseTs && $resumeTs) {
            $seedLog($cid, 'pause', 'Paused WhatsApp campaign "' . $def['name'] . '"', $byId, $pauseTs);
            $seedLog($cid, 'resume', 'Resumed WhatsApp campaign "' . $def['name'] . '"', $byId, $resumeTs);
        }
        if ($def['status'] === 'cancelled') {
            $seedLog($cid, 'cancel','Cancelled WhatsApp campaign "' . $def['name'] . '" (last date extended)', $byId, min($endTs, $now - 60));
        }
        if ($def['status'] === 'completed' && in_array($def['key'], ['admission', 'exam'], true)) {
            $seedLog($cid, 'export', 'Exported ' . number_format($c['total_recipients']) . ' recipient(s) of WhatsApp campaign "' . $def['name'] . '" (XLSX)', $byId, $last + 86400 + mt_rand(0, 7200));
        }
    }

    /* ---------------------------------------------------------------- conversations created here */
    foreach ($convLast as $contactId => [$ts, $preview, $dir]) {
        if (!isset($convNew[$contactId])) {
            continue;
        }
        db_update('wa_conversations', ['last_message_at' => $fmt($ts), 'last_message_preview' => mb_substr((string) $preview, 0, 255), 'last_message_direction' => $dir,
            'last_inbound_at' => $dir === 'in' ? $fmt($ts) : null, 'status' => $dir === 'in' && $ts > $now - 86400 ? 'open' : 'resolved'], 'contact_id = ?', [$contactId]);
    }
};
