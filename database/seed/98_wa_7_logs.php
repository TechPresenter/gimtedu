<?php
/**
 * WhatsApp integration history demo (runs after every other 98_wa_* seeder): the last 14 days of business events
 * (wa_event_logs), Interakt webhooks (wa_webhook_logs) and Interakt API calls (wa_api_logs), anchored on the day the
 * seeder runs, so the Event Manager, API & Webhooks logs, the error monitor and the dashboard charts look live.
 *
 * - Events come from the ERP records of the window, with the codes the real GIMT hooks fire for them — enquiries (website
 *   form codes for website / FAQ / chatbot enquiries, "added by staff" for the other sources; WhatsApp-created enquiries
 *   fire no business event), counsellor assignments of those enquiries (lead intro + the counsellor's staff alert),
 *   applications and their stage changes, fee payments (with a few failed online attempts before them), the daily fee
 *   due / overdue scans (days before today; today's scan is the worker's job), counselling bookings, certificates,
 *   support tickets raised / assigned (student + staff alert) / resolved. Website-JavaScript and external-API events
 *   (brochure download, application started, course selected, demo class …) are never seeded: no GIMT page fires them.
 *   Payloads use the events part's own enrichment (wa_evt_enrich, as the ERP hooks do), message texts the real template
 *   renderer. Every processed event has its WhatsApp message (sender "event", delivered / read ticks, a few Meta
 *   failures); a few are skipped (inactive event) or failed (missing template values, a database lock error in a
 *   listener). The event messages of the inbox demo (98_wa_2_inbox) get their event log. Staff alerts go to the
 *   counsellor's own number (Users › phone): its WhatsApp contact (type staff) is created on first use exactly like
 *   the real handler does (wa_contact_upsert) and reused on re-runs.
 * - Webhooks: message_api|campaign_sent / delivered / read / failed for every seeded outbound message of the window,
 *   message_received for every seeded inbound one (signature valid), a few unknown-message notices and two failures.
 * - API calls: POST message/ for every seeded outbound message, track/users/ for new opt-ins / opt-outs; mode test
 *   (in test mode nothing reaches Interakt), never a credential in a logged body.
 *
 * Deterministic and re-runnable. Owns only marked rows: wa_event_logs.dedupe_key 'demo:…', wa_messages.callback_data
 * 'seed:events', wa_webhook_logs.headers "x-gimt-seed", wa_api_logs.response "seed":"98_wa_7_logs". Demo data only —
 * never run it on production / live.
 */
require_once __DIR__ . '/lib/demo_data.php';

return function (array $opts): void {
    foreach (['wa_event_logs', 'wa_webhook_logs', 'wa_api_logs', 'wa_events', 'wa_messages', 'wa_conversations', 'wa_contacts'] as $t) {
        if (!db_table_exists($t)) {
            return;
        }
    }
    if (!is_file(APP_ROOT . '/app/services/whatsapp.php')) {
        return;
    }
    require_once APP_ROOT . '/app/services/whatsapp.php';
    if (is_file(APP_ROOT . '/app/services/fees.php')) {
        require_once APP_ROOT . '/app/services/fees.php'; // receipt links / payment mode labels exactly as the fee hook sends them
    }
    if (is_file(APP_ROOT . '/app/whatsapp_hook.php')) {
        require_once APP_ROOT . '/app/whatsapp_hook.php'; // gimt_wa_enquiry_code(): the website form → event code map of the real hook
    }
    if (!function_exists('wa_evt_enrich') || !function_exists('wa_render_template') || !function_exists('wa_build_payload')) {
        return;
    }
    demo_seed(98007);
    $now = time();
    $seedTag = '98_wa_7_logs';
    $fmt = fn (int $ts): string => date('Y-m-d H:i:s', $ts);
    $in = fn (array $ids): string => implode(',', array_fill(0, count($ids), '?'));
    $json = fn ($v): string => (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $since = strtotime(date('Y-m-d 00:00:00', $now - 13 * 86400)); // 14 days including today
    $today0 = strtotime(date('Y-m-d 00:00:00', $now));
    $uuid = function (): string {
        $h = '';
        for ($i = 0; $i < 8; $i++) {
            $h .= sprintf('%04x', mt_rand(0, 0xffff));
        }
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-' . dechex(8 + mt_rand(0, 3)) . substr($h, 17, 3) . '-' . substr($h, 20, 12);
    };
    $customerId = function (string $phone): string {
        $h = sha1('interakt-customer:' . $phone);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
    };
    $preview = fn (array $m): string => mb_substr(wa_preview($m['type'] === 'template' ? ((string) $m['body'] ?: 'Template: ' . $m['template_name'])
        : ((string) $m['body'] ?: ucfirst(str_replace('_', ' ', (string) $m['type'])))), 0, 255);

    // keep the worker away while the demo history is rebuilt
    $locked = (int) db_value("SELECT GET_LOCK('gimt_wa_worker', 30)") === 1;
    try {
        /* ============================================================================ cleanup (own rows only) */
        $oldLogIds = array_map('intval', db_column("SELECT id FROM wa_event_logs WHERE dedupe_key LIKE 'demo:%'"));
        $touchedConv = array_map('intval', db_column("SELECT DISTINCT conversation_id FROM wa_messages WHERE callback_data = 'seed:events'"));
        $touchedContacts = array_map('intval', db_column("SELECT DISTINCT contact_id FROM wa_messages WHERE callback_data = 'seed:events'"));
        db_exec("DELETE FROM wa_messages WHERE callback_data = 'seed:events'");
        foreach (array_chunk($oldLogIds, 500) as $chunk) {
            db_exec('UPDATE wa_messages SET event_log_id = NULL WHERE event_log_id IN (' . $in($chunk) . ')', $chunk);
        }
        db_exec("DELETE FROM wa_event_logs WHERE dedupe_key LIKE 'demo:%'");
        db_exec('DELETE FROM wa_webhook_logs WHERE headers LIKE ?', ['%"x-gimt-seed":"' . $seedTag . '"%']);
        db_exec("DELETE FROM wa_api_logs WHERE mode = 'test' AND response LIKE ?", ['%"seed":"' . $seedTag . '"%']);
        foreach (array_chunk($touchedConv, 300) as $chunk) {
            // conversations that only held demo event messages are dropped; the others point at their latest message again
            db_exec('DELETE cv FROM wa_conversations cv LEFT JOIN wa_messages m ON m.conversation_id = cv.id LEFT JOIN wa_notes n ON n.conversation_id = cv.id
                      WHERE m.id IS NULL AND n.id IS NULL AND cv.id IN (' . $in($chunk) . ')', $chunk);
            foreach (db_column('SELECT cv.id FROM wa_conversations cv WHERE cv.id IN (' . $in($chunk) . ')
                                   AND NOT EXISTS (SELECT 1 FROM wa_messages m WHERE m.conversation_id = cv.id AND m.created_at = cv.last_message_at)', $chunk) as $cvId) {
                $last = db_row('SELECT created_at, body, type, template_name, direction FROM wa_messages WHERE conversation_id = ? ORDER BY created_at DESC, id DESC LIMIT 1', [(int) $cvId]);
                db_update('wa_conversations', ['last_message_at' => $last['created_at'] ?? null, 'last_message_preview' => $last ? $preview($last) : null,
                    'last_message_direction' => $last['direction'] ?? null], 'id = ?', [(int) $cvId]);
            }
        }
        $refreshContacts = function (array $ids) use ($in): void {
            foreach (array_chunk(array_values(array_unique($ids)), 300) as $chunk) {
                db_exec("UPDATE wa_contacts k SET
                            k.last_outbound_at = (SELECT MAX(m.created_at) FROM wa_messages m WHERE m.contact_id = k.id AND m.direction = 'out'),
                            k.last_message_at = (SELECT MAX(m.created_at) FROM wa_messages m WHERE m.contact_id = k.id)
                          WHERE k.id IN (" . $in($chunk) . ')', $chunk);
            }
        };
        $refreshContacts($touchedContacts);

        if ((int) db_value("SELECT COUNT(*) FROM wa_contacts WHERE status <> 'archived'") < 20) {
            return; // the contacts demo (98_wa_1_contacts) has not run
        }

        /* ============================================================================ reference data */
        $events = [];
        foreach (db_all('SELECT * FROM wa_events') as $e) {
            $events[$e['code']] = $e;
        }
        $tplCache = [];
        $tplFor = function (array $evt) use (&$tplCache): ?array {
            $id = (int) ($evt['template_id'] ?? 0);
            if ($id <= 0) {
                return null;
            }
            if (!array_key_exists($id, $tplCache)) {
                $tplCache[$id] = wa_template_find($id) ?: null;
            }
            return $tplCache[$id];
        };
        $contactCache = [];
        $contactRow = function (int $id) use (&$contactCache): ?array {
            if (!array_key_exists($id, $contactCache)) {
                $contactCache[$id] = wa_contact($id) ?: null;
            }
            return $contactCache[$id];
        };
        // the counsellor's own WhatsApp contact for staff alerts (Users › phone), created on first use exactly as the real
        // handler does (wa_evt_resolve_recipient → wa_contact_upsert, type staff) and reused on re-runs
        $staffCache = [];
        $staffFirst = []; // contact created here => its first alert (its created_at)
        $staffContact = function (int $userId, int $at) use (&$staffCache, &$staffFirst): ?int {
            if (!array_key_exists($userId, $staffCache)) {
                $staffCache[$userId] = null;
                $u = $userId > 0 ? db_row("SELECT id, name, phone FROM users WHERE id = ? AND phone IS NOT NULL AND phone <> ''", [$userId]) : null;
                $norm = $u ? wa_normalize_phone((string) $u['phone']) : null;
                if ($norm) {
                    $existing = (int) (db_value('SELECT id FROM wa_contacts WHERE phone = ?', [$norm['phone']]) ?: 0);
                    try {
                        $staffCache[$userId] = $existing ?: wa_contact_upsert(['phone' => '+' . $norm['phone'], 'name' => $u['name'], 'contact_type' => 'staff', 'user_id' => (int) $u['id']]);
                    } catch (Throwable $e) {
                        $staffCache[$userId] = null;
                    }
                    if (!$existing && $staffCache[$userId]) {
                        $staffFirst[$staffCache[$userId]] = $at;
                    }
                }
            }
            $id = $staffCache[$userId];
            if ($id && isset($staffFirst[$id])) {
                $staffFirst[$id] = min($staffFirst[$id], $at);
            }
            return $id;
        };
        $byEnquiry = array_map('intval', db_pairs('SELECT enquiry_id, MIN(id) FROM wa_contacts WHERE enquiry_id IS NOT NULL GROUP BY enquiry_id'));
        $byAdmission = array_map('intval', db_pairs('SELECT admission_id, MIN(id) FROM wa_contacts WHERE admission_id IS NOT NULL GROUP BY admission_id'));
        $byStudent = array_map('intval', db_pairs("SELECT student_id, MIN(id) FROM wa_contacts WHERE student_id IS NOT NULL AND contact_type IN ('student','alumni') GROUP BY student_id"));
        $crmOwned = function_exists('wa_evt_crm_owned_codes') ? wa_evt_crm_owned_codes() : [];
        $stageMap = function_exists('wa_evt_stage_map') ? wa_evt_stage_map() : [];
        $officeTime = fn (int $dayTs, int $fromMin = 600, int $toMin = 1080): int => strtotime(date('Y-m-d 00:00:00', $dayTs)) + mt_rand($fromMin, $toMin) * 60 + mt_rand(0, 59);

        /* ============================================================================ event occurrences */
        $occ = [];
        $add = function (string $code, int $at, ?int $contactId, array $payload, string $key, array $extra = []) use (&$occ, $events, $now, $since): void {
            if ($at < $since || $at > $now - 90 || !isset($events[$code])) {
                return;
            }
            $occ[] = $extra + ['code' => $code, 'at' => $at, 'contact_id' => $contactId, 'payload' => $payload, 'key' => $key, 'link' => null, 'source' => null];
        };

        // 1. the event messages already shown in the inbox demo get the event that sent them
        $tplToCode = [];
        foreach ([true, false] as $byConvention) {
            foreach ($events as $code => $e) {
                $tpl = (int) $e['is_active'] && $e['template_id'] ? $tplFor($e) : null;
                if ($tpl && !isset($tplToCode[(int) $tpl['id']]) && (!$byConvention || wa_evt_template_name($code) === $tpl['name'])) {
                    $tplToCode[(int) $tpl['id']] = $code;
                }
            }
        }
        foreach (db_all("SELECT id, contact_id, template_id, created_at FROM wa_messages
                          WHERE sender_type = 'event' AND direction = 'out' AND callback_data = 'seed:inbox' AND event_log_id IS NULL AND created_at >= ? ORDER BY id", [$fmt($since)]) as $m) {
            $code = $tplToCode[(int) $m['template_id']] ?? null;
            $c = $code ? $contactRow((int) $m['contact_id']) : null;
            if (!$c) {
                continue;
            }
            $p = array_filter(['student_id' => $c['student_id'] ? (int) $c['student_id'] : null, 'admission_id' => $c['admission_id'] ? (int) $c['admission_id'] : null,
                'enquiry_id' => $c['enquiry_id'] ? (int) $c['enquiry_id'] : null]);
            $add($code, strtotime($m['created_at']) - mt_rand(2, 6), (int) $c['id'], $p ?: ['phone' => '+' . $c['phone'], 'name' => $c['name']], 'inbox:' . $m['id'], ['link' => (int) $m['id']]);
        }

        // 2. enquiries of the window, with the code the real hook fires for each path: website forms (public_forms →
        //    gimt_wa_enquiry_code: home / pop-up / FAQ, program page, page callback form, admissions counselling form,
        //    landing page with UTM), the website AI chat (source chatbot), and enquiries added by staff in the ERP (CRUD →
        //    GIMT_ENQUIRY_CREATED_ADMIN). Enquiries created from WhatsApp chats fire no business event, so none is seeded.
        $assignments = [];
        foreach (db_all('SELECT id, source, program_id, assigned_to, created_at, utm_source, utm_campaign FROM enquiries WHERE created_at >= ? ORDER BY id', [$fmt($since)]) as $e) {
            $src = (string) $e['source'];
            $cid = $byEnquiry[(int) $e['id']] ?? null;
            if (!empty($e['assigned_to'])) {
                $assignments[] = $e + ['contact_id' => $cid];
            }
            if ($src === 'whatsapp') {
                continue;
            }
            $form = null;
            if ($src === 'website') {
                $form = $e['utm_source'] ? 'landing-hero'
                    : demo_weighted(['home-hero' => 30, 'enquiry-popup' => 20, '' => $e['program_id'] ? 25 : 0, 'callback' => 13, 'counselling' => 12]);
            } elseif ($src === 'faq') {
                $form = 'faq-question';
            } elseif ($src === 'chatbot') {
                $form = 'ai-chat';
            }
            $code = $form === null ? 'GIMT_ENQUIRY_CREATED_ADMIN'
                : (function_exists('gimt_wa_enquiry_code') ? gimt_wa_enquiry_code($form, !empty($e['program_id']))
                    : ['landing-hero' => 'GIMT_LANDING_LEAD', 'ai-chat' => 'GIMT_CHATBOT_LEAD', 'callback' => 'GIMT_CALLBACK_REQUEST', 'counselling' => 'GIMT_COUNSELLING_REQUEST', '' => 'GIMT_COURSE_ENQUIRY'][$form] ?? 'GIMT_ENQUIRY_RECEIVED');
            $p = ['enquiry_id' => (int) $e['id'], 'source' => $form === null ? $src : 'website'];
            if ($form !== null) {
                $p += ['form' => $form, 'consent' => true, 'page' => match ($form) {
                    'landing-hero' => 'admissions-open', 'counselling' => 'admissions-page', 'faq-question' => 'faq', 'ai-chat' => 'chatbot', 'callback' => 'page-contact', '' => 'program', default => 'home',
                }];
            }
            if ($e['utm_source']) {
                $p['utm_source'] = $e['utm_source'];
                $p['utm_campaign'] = $e['utm_campaign'];
            }
            $add($code, strtotime($e['created_at']) + mt_rand(1, 4), $cid, $p, 'enq:' . $e['id'], ['source' => $form === null ? 'admin' : 'website']);
        }

        // 2b. counsellor assignment of those enquiries: the lead hears who their counsellor is (contact) and the counsellor
        //     gets a staff alert on their own number — the two codes the assign action / CRUD tap fire together
        foreach ($assignments as $e) {
            $at = $officeTime(strtotime($e['created_at']) + mt_rand(0, 1) * 86400, 600, 1080);
            if ($at <= strtotime($e['created_at'])) {
                $at = strtotime($e['created_at']) + mt_rand(20, 180) * 60;
            }
            $p = ['enquiry_id' => (int) $e['id'], 'counsellor_id' => (int) $e['assigned_to']];
            $key = 'enq:' . $e['id'] . ':u' . $e['assigned_to'];
            $add('GIMT_ENQUIRY_ASSIGNED', $at, $e['contact_id'], $p, $key, ['source' => 'admin']);
            if ($at >= $since && $at <= $now - 90 && ($staff = $staffContact((int) $e['assigned_to'], $at))) {
                $add('GIMT_LEAD_TRANSFERRED', $at + mt_rand(0, 1), $e['contact_id'], $p, $key, ['source' => 'admin', 'recipient_id' => $staff, 'via' => 'counsellor']);
            }
        }

        // 3. applications submitted + their stage changes
        foreach (db_all('SELECT id, stage, created_at, stage_changed_at FROM admissions WHERE created_at >= ? OR stage_changed_at >= ? ORDER BY id', [$fmt($since), $fmt($since)]) as $a) {
            $cid = $byAdmission[(int) $a['id']] ?? null;
            $created = strtotime($a['created_at']);
            if ($created >= $since) {
                $add('GIMT_APPLICATION_SUBMITTED', $created + mt_rand(3, 20), $cid, ['admission_id' => (int) $a['id']], 'adm:' . $a['id'] . ':submitted', ['source' => 'admission_portal']);
            }
            $changed = $a['stage_changed_at'] ? strtotime($a['stage_changed_at']) : 0;
            if ($changed < $since || $changed - $created < 600) {
                continue;
            }
            // documents pending only for applications that really miss a document
            $docs = $a['stage'] === 'document_verification' && function_exists('wa_evt_pending_docs') ? (string) wa_evt_pending_docs((int) $a['id']) : '';
            $docsMissing = $docs !== '' && !str_starts_with($docs, 'none');
            $code = match ((string) $a['stage']) {
                'document_verification' => $docsMissing ? 'GIMT_DOCUMENTS_PENDING' : null,
                'approval' => 'GIMT_APPLICATION_UNDER_REVIEW',
                'fee_payment' => 'GIMT_ADMISSION_APPROVED',
                'confirmed' => 'GIMT_ADMISSION_CONFIRMED',
                'rejected' => 'GIMT_ADMISSION_REJECTED',
                'entrance_interview' => mt_rand(1, 100) <= 30 ? 'GIMT_INTERVIEW_SCHEDULED' : null,
                default => null,
            };
            if ($code) {
                $add($code, $changed + mt_rand(2, 12), $cid, ['admission_id' => (int) $a['id'], 'admission_stage' => $a['stage']], 'adm:' . $a['id'] . ':' . $a['stage'], ['source' => 'admin']);
            }
        }

        // 4. fee payments (+ a few failed online attempts just before a successful one)
        $failedLeft = 4;
        foreach (db_all("SELECT id, student_id, receipt_no, amount, mode, payment_date, reference_no, gateway_txn_id, created_at FROM payments
                          WHERE status = 'success' AND student_id IS NOT NULL AND created_at >= ? ORDER BY id", [$fmt($since)]) as $p) {
            $cid = $byStudent[(int) $p['student_id']] ?? null;
            $at = strtotime($p['created_at']);
            $online = in_array($p['mode'], ['online', 'upi'], true);
            $txn = (string) ($p['gateway_txn_id'] ?: $p['reference_no'] ?: '');
            $add('GIMT_PAYMENT_SUCCESS', $at + mt_rand(2, 15), $cid, [
                'student_id' => (int) $p['student_id'], 'payment_id' => (int) $p['id'], 'receipt_no' => $p['receipt_no'], 'amount' => (float) $p['amount'],
                'mode' => function_exists('fees_mode_label') ? fees_mode_label((string) $p['mode']) : ucwords(str_replace('_', ' ', (string) $p['mode'])),
                'payment_date' => date('d M Y', strtotime($p['payment_date'])), 'transaction_id' => $txn,
                'receipt_link' => function_exists('fees_receipt_verify_url') ? fees_receipt_verify_url((string) $p['receipt_no']) : absolute_url('portal/student/fees'),
            ], 'pay:' . $p['id'], ['source' => $online ? 'payment' : 'admin']);
            if ($online && $failedLeft > 0 && mt_rand(1, 100) <= 12) {
                $failedLeft--;
                $add('GIMT_PAYMENT_FAILED', $at - mt_rand(6, 25) * 60, $cid, [
                    'student_id' => (int) $p['student_id'], 'amount' => (float) $p['amount'], 'gateway' => 'cashfree',
                    'transaction_id' => 'CF' . mt_rand(10000000, 99999999) . mt_rand(1000, 9999),
                    'reason' => demo_pick(['Payment declined by the bank', 'UPI request expired before approval', 'Transaction timed out at the bank']),
                    'payment_link' => absolute_url('portal/student/fees'),
                ], 'payfail:' . $p['id'], ['source' => 'payment']);
            }
        }

        // 5. the daily fee scans (cron) of the days before today — today's scan is the worker's job
        // (a sample of each scan: a big instalment date shows as a spike without hundreds of demo messages)
        $feeRows = function (string $dueDate): array {
            return db_all("SELECT sf.id, sf.invoice_no, sf.title, sf.student_id, sf.balance_amount, sf.due_date FROM student_fees sf JOIN students s ON s.id = sf.student_id AND s.status = 'active'
                            WHERE sf.due_date = ? AND sf.balance_amount > 0 AND sf.status NOT IN ('paid','cancelled','waived') ORDER BY sf.id LIMIT 25", [$dueDate]);
        };
        for ($day = $since; $day < $today0; $day += 86400) {
            foreach ([3, 1] as $d) {
                foreach ($feeRows(date('Y-m-d', $day + $d * 86400)) as $i => $f) {
                    $add('GIMT_FEE_INSTALMENT_DUE', strtotime(date('Y-m-d 09:00:00', $day)) + 4 + $i * mt_rand(1, 3), $byStudent[(int) $f['student_id']] ?? null, [
                        'student_id' => (int) $f['student_id'], 'invoice_no' => $f['invoice_no'], 'fee_title' => $f['title'], 'installment_no' => 1,
                        'amount' => round((float) $f['balance_amount'], 2), 'due_date' => date('d M Y', strtotime($f['due_date'])), 'days_left' => $d,
                        'payment_link' => absolute_url('portal/student/fees'),
                    ], 'inv:' . $f['id'] . ':' . $f['due_date'] . ':d' . $d, ['source' => 'cron']);
                }
            }
            foreach ([1, 7, 15] as $d) {
                foreach ($feeRows(date('Y-m-d', $day - $d * 86400)) as $i => $f) {
                    $add('GIMT_FEE_OVERDUE', strtotime(date('Y-m-d 10:00:00', $day)) + 4 + $i * mt_rand(1, 3), $byStudent[(int) $f['student_id']] ?? null, [
                        'student_id' => (int) $f['student_id'], 'invoice_no' => $f['invoice_no'], 'fee_title' => $f['title'], 'installment_no' => 1,
                        'amount' => round((float) $f['balance_amount'], 2), 'due_date' => date('d M Y', strtotime($f['due_date'])), 'days_overdue' => $d,
                        'payment_link' => absolute_url('portal/student/fees'),
                    ], 'inv:' . $f['id'] . ':' . $f['due_date'] . ':d' . $d, ['source' => 'cron']);
                }
            }
        }

        // 6. counselling sessions booked by the counsellors (CRM stage changes of the window)
        foreach (db_all("SELECT id, enquiry_id, stage_changed_at FROM wa_contacts WHERE stage = 'counselling_scheduled' AND stage_changed_at >= ? ORDER BY id", [$fmt($since)]) as $c) {
            $at = strtotime($c['stage_changed_at']);
            $slot = $officeTime($at + mt_rand(1, 3) * 86400, 630, 990);
            $add('GIMT_COUNSELLING_SCHEDULED', $at + mt_rand(5, 40), (int) $c['id'], array_filter([
                'enquiry_id' => $c['enquiry_id'] ? (int) $c['enquiry_id'] : null, 'counselling_date' => date('d M Y', $slot), 'counselling_time' => date('h:i A', $slot),
                'counselling_mode' => demo_pick(['Campus visit (Admission Cell, Block A)', 'Video call', 'Campus visit (Admission Cell, Block A)']),
            ]), 'counsel:' . $c['id'] . ':' . date('Ymd', $at), ['source' => 'admin']);
        }

        // 7. certificates issued + support tickets raised / resolved
        if (db_table_exists('certificates')) {
            foreach (db_all("SELECT id, student_id, issue_date, approved_at FROM certificates WHERE status = 'issued' AND issue_date >= ? ORDER BY id", [date('Y-m-d', $since)]) as $c) {
                $at = $c['approved_at'] ? strtotime($c['approved_at']) + mt_rand(30, 600) : $officeTime(strtotime($c['issue_date']), 660, 1020);
                $add('GIMT_CERTIFICATE_ISSUED', $at, $byStudent[(int) $c['student_id']] ?? null, ['certificate_id' => (int) $c['id'], 'student_id' => (int) $c['student_id']], 'cert:' . $c['id'], ['source' => 'admin']);
            }
        }
        if (db_table_exists('feedback')) {
            // (anonymous tickets fire nothing — the hooks skip them)
            foreach (db_all('SELECT f.id, f.student_id, f.assigned_to, f.created_at, f.resolved_at, u.name AS assignee_name FROM feedback f LEFT JOIN users u ON u.id = f.assigned_to
                              WHERE f.student_id IS NOT NULL AND COALESCE(f.is_anonymous, 0) = 0 AND (f.created_at >= ? OR f.resolved_at >= ?) ORDER BY f.id', [$fmt($since), $fmt($since)]) as $t) {
                $cid = $byStudent[(int) $t['student_id']] ?? null;
                $created = strtotime($t['created_at']);
                $add('GIMT_TICKET_CREATED', $created + mt_rand(2, 8), $cid, ['ticket_id' => (int) $t['id']], 'ticket:' . $t['id'] . ':created', ['source' => 'admin']);
                if ($t['assigned_to']) {
                    // assigned to a staff member a little later (before it was resolved): student update + staff alert
                    $at = $created + mt_rand(25, 240) * 60;
                    if ($t['resolved_at'] && $at >= strtotime($t['resolved_at'])) {
                        $at = $created + (int) max(60, (strtotime($t['resolved_at']) - $created) / 3);
                    }
                    $p = ['ticket_id' => (int) $t['id'], 'counsellor_id' => (int) $t['assigned_to'], 'assignee_name' => (string) $t['assignee_name']];
                    $key = 'fb:' . $t['id'] . ':u' . $t['assigned_to'];
                    $add('GIMT_TICKET_ASSIGNED', $at, $cid, $p, $key, ['source' => 'admin']);
                    if ($at >= $since && $at <= $now - 90 && ($staff = $staffContact((int) $t['assigned_to'], $at))) {
                        $add('GIMT_TICKET_ASSIGNED_STAFF', $at + mt_rand(0, 1), $cid, $p, $key, ['source' => 'admin', 'recipient_id' => $staff, 'via' => 'counsellor']);
                    }
                }
                if ($t['resolved_at']) {
                    $add('GIMT_TICKET_RESOLVED', strtotime($t['resolved_at']) + mt_rand(2, 8), $cid, ['ticket_id' => (int) $t['id']], 'ticket:' . $t['id'] . ':resolved', ['source' => 'admin']);
                }
            }
        }

        // (no website brochure / application-started / course-selected / demo-class events: no GIMT page fires them yet —
        //  callback and counselling requests come from the enquiry forms above, each with its enquiry)

        usort($occ, fn ($a, $b) => [$a['at'], $a['key']] <=> [$b['at'], $b['key']]);
        $seenKeys = [];
        $occ = array_values(array_filter($occ, function ($o) use (&$seenKeys) {
            if (isset($seenKeys[$o['code'] . '|' . $o['key']])) {
                return false;
            }
            $seenKeys[$o['code'] . '|' . $o['key']] = true;
            return true;
        }));
        // two listener errors (database lock) on ordinary events of the past week
        $errorIdx = [];
        foreach ([0.58, 0.83] as $frac) {
            for ($i = (int) floor(count($occ) * $frac); $i < count($occ); $i++) {
                if (!$occ[$i]['link'] && $occ[$i]['contact_id'] && in_array($occ[$i]['code'], ['GIMT_FEE_OVERDUE', 'GIMT_FEE_INSTALMENT_DUE', 'GIMT_CERTIFICATE_ISSUED', 'GIMT_TICKET_CREATED', 'GIMT_ENQUIRY_RECEIVED'], true)
                    && $occ[$i]['at'] < $today0) {
                    $errorIdx[$i] = true;
                    break;
                }
            }
        }

        /* ============================================================================ outcomes + messages */
        $metaFail = [['131026', 'Message undeliverable', 55], ['1013', 'Recipient is not a valid WhatsApp user', 35], ['131000', 'Something went wrong (Meta)', 10]];
        $missingLeft = 4;
        $missingBy = [];
        $logs = [];      // per occurrence: [row, actions, message interakt id | null]
        $messages = [];
        $n = 0;
        foreach ($occ as $idx => $o) {
            $evt = $events[$o['code']];
            $payload = wa_evt_enrich($o['code'], $o['payload']);
            $source = $o['source'] ?: ($evt['source'] ?: 'admin');
            $contact = $o['contact_id'] ? $contactRow((int) $o['contact_id']) : null;
            // recipient: the event's contact, or the counsellor's own staff contact for staff alerts
            $to = !empty($o['recipient_id']) ? $contactRow((int) $o['recipient_id']) : $contact;
            $summary = ['handler' => 'event_manager'];
            $pre = [];
            if (in_array($o['code'], $crmOwned, true) && array_intersect_key($o['payload'], array_flip(['enquiry_id', 'admission_id', 'student_id']))) {
                $pre[] = ['crm_sync' => ['created' => 0, 'updated' => 1, 'stage_changes' => isset($stageMap[$o['code']]) && $o['code'] !== 'GIMT_ENQUIRY_RECEIVED' ? 1 : 0]];
            } elseif ($contact && isset($stageMap[$o['code']])) {
                $summary['crm'] = ['contact_id' => (int) $contact['id'], 'created' => false];
            }
            $summary['event_id'] = (int) $evt['id'];
            $row = ['event_code' => $o['code'], 'source' => $source, 'payload' => $json(wa_event_redact($payload)), 'dedupe_key' => mb_substr('demo:' . $o['code'] . ':' . $o['key'], 0, 190),
                'contact_id' => $contact ? (int) $contact['id'] : null, 'status' => 'processed', 'actions' => null, 'error' => null,
                'created_at' => $fmt($o['at']), 'processed_at' => $fmt($o['at'] + mt_rand(0, 1))];
            if (isset($errorIdx[$idx])) {
                $row['status'] = 'failed';
                $row['error'] = 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction';
                $logs[] = [$row, $pre, null];
                continue;
            }
            if (!(int) $evt['is_active']) {
                $logs[] = [$row, array_merge($pre, [$summary + ['status' => 'skipped', 'reason' => 'Event is inactive']]), null];
                continue;
            }
            if (!$to) {
                continue; // (an ERP record without a CRM contact: the real hook would have created one — leave it out)
            }
            $tpl = $tplFor($evt);
            if (!$tpl) {
                $logs[] = [$row, array_merge($pre, [$summary + ['status' => 'skipped', 'reason' => 'No template linked']]), null];
                continue;
            }
            $summary['recipient'] = ['type' => $o['via'] ?? 'contact', 'contact_id' => (int) $to['id'], 'phone' => wa_evt_mask_phone($to['phone']), 'name' => $to['name']];
            $summary['template'] = ['id' => (int) $tpl['id'], 'name' => $tpl['name'], 'status' => $tpl['status']];
            if ($o['link']) {
                $logs[] = [$row, array_merge($pre, [$summary + ['message_id' => $o['link'], 'send_status' => 'queued', 'status' => 'queued']]), 'link:' . $o['link']];
                continue;
            }
            if ($to['status'] === 'blocked') {
                $logs[] = [$row, array_merge($pre, [$summary + ['message_id' => null, 'send_status' => null, 'status' => 'failed', 'reason' => 'This contact is blocked.', 'code' => 'blocked']]), null];
                continue;
            }
            $vars = wa_evt_build_vars($evt, $tpl, $payload, $to);
            $render = wa_render_template($tpl, $vars);
            if (!empty($render['missing'])) {
                // a few real "missing value" refusals (at most 2 per event); the rest went out with the template samples
                if ($missingLeft > 0 && ($missingBy[$o['code']] ?? 0) < 2 && $o['at'] < $today0) {
                    $missingLeft--;
                    $missingBy[$o['code']] = ($missingBy[$o['code']] ?? 0) + 1;
                    $logs[] = [$row, array_merge($pre, [$summary + ['message_id' => null, 'send_status' => null, 'status' => 'failed',
                        'reason' => 'Missing template variables: ' . implode(', ', array_slice($render['missing'], 0, 4)), 'code' => 'missing_variables']]), null];
                    continue;
                }
                $render = wa_render_template($tpl, $vars + ['_use_samples' => true]);
            }
            // delivery as Interakt reported it
            $sentTs = $o['at'] + mt_rand(1, 4);
            $iid = 'seed-evt-' . (++$n);
            $m = [
                'conversation_id' => 0, 'contact_id' => (int) $to['id'], 'direction' => 'out', 'type' => 'template', 'body' => $render['text'],
                'template_id' => (int) $tpl['id'], 'template_name' => $tpl['name'],
                'template_vars' => wa_json(['header' => $render['header'], 'body' => $render['body'], 'buttons' => (object) $render['buttons'], 'payloads' => (object) ($render['payloads'] ?? []),
                    'file_name' => $render['file_name'] ?? null, 'language' => $tpl['language'], 'category' => $tpl['category'], 'named' => (object) []]),
                'interactive' => wa_json(['template' => ['header_type' => $tpl['header_type'], 'header_text' => $render['header_text'] ?? null, 'header_media_url' => $render['media_url'] ?? null,
                    'file_name' => $render['file_name'] ?? null, 'body' => $render['text'], 'footer' => $tpl['footer'], 'buttons' => $tpl['buttons']]]),
                'interakt_id' => $iid, 'callback_data' => 'seed:events', 'status' => 'sent', 'error_code' => null, 'error' => null, 'cost' => null,
                'sender_type' => 'event', 'event_log_id' => null, 'created_at' => $fmt($o['at']), 'sent_at' => $fmt($sentTs), 'delivered_at' => null, 'read_at' => null, 'failed_at' => null,
            ];
            if ($sentTs < $now - 120) {
                $r = mt_rand(1, 100);
                if ($r <= 4) {
                    [$code, $err] = (function () use ($metaFail) {
                        $f = demo_weighted(array_combine(array_keys($metaFail), array_column($metaFail, 2)));
                        return $metaFail[$f];
                    })();
                    $m['status'] = 'failed';
                    $m['error_code'] = $code;
                    $m['error'] = $err;
                    $m['failed_at'] = $fmt($sentTs + mt_rand(2, 60));
                } elseif ($r <= 7) {
                    // sent, no delivery report yet (phone off)
                } else {
                    $del = $sentTs + (mt_rand(1, 10) <= 8 ? mt_rand(2, 40) : mt_rand(60, 3 * 3600));
                    if ($del < $now - 30) {
                        $m['status'] = 'delivered';
                        $m['delivered_at'] = $fmt($del);
                        $m['cost'] = strtoupper((string) $tpl['category']) === 'MARKETING' ? 0.7846 : 0.1150;
                        if (mt_rand(1, 100) <= 68) {
                            $u = mt_rand(1, 100);
                            $read = $del + ($u <= 45 ? mt_rand(5, 900) : ($u <= 85 ? mt_rand(900, 4 * 3600) : mt_rand(4 * 3600, 26 * 3600)));
                            if ($read < $now - 10) {
                                $m['status'] = 'read';
                                $m['read_at'] = $fmt($read);
                            }
                        }
                    }
                }
            }
            $messages[] = $m;
            $logs[] = [$row, array_merge($pre, [$summary + ['message_id' => $iid, 'send_status' => 'queued', 'status' => 'queued']]), $iid];
        }

        // conversations for contacts that had none (transactional notifications only → resolved)
        $convMap = array_map('intval', db_pairs('SELECT contact_id, id FROM wa_conversations'));
        $needConv = [];
        foreach ($messages as $m) {
            if (!isset($convMap[$m['contact_id']])) {
                $needConv[$m['contact_id']] = min($needConv[$m['contact_id']] ?? PHP_INT_MAX, strtotime($m['created_at']));
            }
        }
        if ($needConv) {
            $rows = [];
            foreach ($needConv as $cid => $firstAt) {
                $rows[] = ['contact_id' => (int) $cid, 'status' => 'resolved', 'bot_active' => 1, 'unread_count' => 0, 'created_at' => $fmt($firstAt), 'resolved_at' => $fmt($firstAt + 60)];
            }
            demo_bulk_insert('wa_conversations', $rows);
            $convMap = array_map('intval', db_pairs('SELECT contact_id, id FROM wa_conversations'));
        }
        foreach ($messages as &$m) {
            $m['conversation_id'] = $convMap[$m['contact_id']];
        }
        unset($m);
        demo_bulk_insert('wa_messages', $messages, 200);
        $msgIds = array_map('intval', db_pairs("SELECT interakt_id, id FROM wa_messages WHERE callback_data = 'seed:events'"));

        // event logs (one insert each: the message ↔ log link needs the id)
        $linkUpdates = [];
        foreach ($logs as [$row, $actions, $ref]) {
            $messageId = null;
            if ($ref !== null) {
                $messageId = str_starts_with($ref, 'link:') ? (int) substr($ref, 5) : ($msgIds[$ref] ?? null);
                foreach ($actions as &$a) {
                    if (($a['handler'] ?? '') === 'event_manager') {
                        $a['message_id'] = $messageId;
                    }
                }
                unset($a);
            }
            $row['actions'] = $actions ? $json($actions) : null;
            $logId = db_insert('wa_event_logs', $row);
            if ($messageId) {
                $linkUpdates[$messageId] = $logId;
            }
        }
        foreach ($linkUpdates as $messageId => $logId) {
            db_exec('UPDATE wa_messages SET event_log_id = ? WHERE id = ?', [$logId, $messageId]);
        }

        // conversation list / contact columns follow the newest message
        $latest = [];
        foreach ($messages as $m) {
            $cv = $m['conversation_id'];
            if (!isset($latest[$cv]) || $latest[$cv]['created_at'] < $m['created_at']) {
                $latest[$cv] = $m;
            }
        }
        foreach ($latest as $cv => $m) {
            db_exec('UPDATE wa_conversations SET last_message_at = ?, last_message_preview = ?, last_message_direction = ? WHERE id = ? AND (last_message_at IS NULL OR last_message_at < ?)',
                [$m['created_at'], $preview($m), 'out', $cv, $m['created_at']]);
        }
        $refreshContacts(array_map(fn ($m) => (int) $m['contact_id'], $messages));
        // run counters are not an edit of the event: keep updated_at (ON UPDATE would otherwise stamp the seed time)
        db_exec('UPDATE wa_events e JOIN (SELECT event_code, COUNT(*) AS n, MAX(created_at) AS last_at FROM wa_event_logs GROUP BY event_code) l ON l.event_code = e.code
                    SET e.execution_count = l.n, e.last_executed_at = l.last_at, e.updated_at = e.updated_at');
        // events whose only logs were demo rows of an earlier run (e.g. a code no longer seeded): never fired
        db_exec('UPDATE wa_events e SET e.execution_count = 0, e.last_executed_at = NULL, e.updated_at = e.updated_at
                  WHERE (e.execution_count <> 0 OR e.last_executed_at IS NOT NULL) AND NOT EXISTS (SELECT 1 FROM wa_event_logs l WHERE l.event_code = e.code)');
        // staff contacts created here for the alerts: created when their first alert went out
        foreach ($staffFirst as $staffId => $firstAt) {
            db_exec('UPDATE wa_contacts SET created_at = ?, updated_at = updated_at WHERE id = ? AND NOT EXISTS (SELECT 1 FROM wa_messages m WHERE m.contact_id = wa_contacts.id AND m.created_at < ?)',
                [$fmt($firstAt - 5), (int) $staffId, $fmt($firstAt - 5)]);
        }

        /* ============================================================================ webhooks + API calls */
        $hookRows = [];
        $hook = function (array $payload, int $at, ?string $iid, string $status = 'processed', ?string $note = null) use (&$hookRows, $json, $fmt, $seedTag): void {
            $raw = $json($payload);
            $hookRows[] = [
                'event_type' => (string) $payload['type'], 'signature_valid' => 1, 'payload' => $raw,
                'headers' => $json(['content-type' => 'application/json', 'user-agent' => 'Interakt-Webhooks/1.0', 'interakt-signature' => 'sha256=' . hash_hmac('sha256', $raw, 'gimt-demo-webhook'),
                    'x-gimt-seed' => $seedTag]),
                'interakt_message_id' => $iid, 'status' => $status, 'error' => $note, 'is_simulated' => 0,
                'received_at' => $fmt($at), 'processed_at' => $fmt($at + mt_rand(0, 2)),
            ];
        };
        $statusPayload = function (array $m, string $status, int $at, ?array $override = null) use ($customerId): array {
            $kind = $m['campaign_id'] ? 'campaign' : 'api';
            $isTpl = $m['type'] === 'template';
            return ['version' => '1.0', 'timestamp' => wa_utc_stamp($at), 'type' => 'message_' . $kind . '_' . $status, 'data' => [
                'customer' => ['id' => $customerId((string) $m['phone']), 'channel_phone_number' => (string) $m['phone'], 'traits' => (object) array_filter(['name' => $m['name']])],
                'message' => [
                    'id' => $m['interakt_id'], 'chat_message_type' => 'PublicApiMessage', 'message_status' => ucfirst($status),
                    'received_at_utc' => wa_utc_stamp(strtotime((string) $m['sent_at'])),
                    'delivered_at_utc' => $m['delivered_at'] && in_array($status, ['delivered', 'read'], true) ? wa_utc_stamp(strtotime((string) $m['delivered_at'])) : null,
                    'seen_at_utc' => $m['read_at'] && $status === 'read' ? wa_utc_stamp(strtotime((string) $m['read_at'])) : null,
                    'campaign_id' => null, 'is_template_message' => $isTpl,
                    'channel_error_code' => $status === 'failed' ? (string) ($m['error_code'] ?: '131026') : null,
                    'channel_failure_reason' => $status === 'failed' ? (string) ($m['error'] ?: 'Message undeliverable') : null,
                    'message_content_type' => $isTpl ? 'Template' : ucfirst(str_replace('_', '', ucwords((string) $m['type'], '_'))),
                    'media_url' => null, 'message' => mb_substr((string) $m['body'], 0, 1000),
                    'meta_data' => ['source' => 'PublicInterakt', 'source_data' => ['callback_data' => 'gimt:msg:' . $m['id']],
                        'message_cost' => $status === 'delivered' && $isTpl ? ['whatsapp_cost' => number_format((float) ($m['cost'] ?: 0.115), 4, '.', ''), 'interakt_markup' => '0.0',
                            'actual_message_cost' => number_format((float) ($m['cost'] ?: 0.115), 4, '.', '')] : null],
                ] + ($override ?? []),
            ]];
        };
        $apiRows = [];
        $api = function (string $endpoint, $request, array $response, int $status, ?int $messageId, int $at) use (&$apiRows, $json, $fmt, $seedTag): void {
            $apiRows[] = ['endpoint' => $endpoint, 'method' => 'POST', 'request' => is_string($request) ? $request : $json($request),
                'response' => $json($response + ['seed' => $seedTag]), 'http_status' => $status, 'ok' => 1, 'duration_ms' => 0, 'message_id' => $messageId,
                'mode' => 'test', 'error' => null, 'created_at' => $fmt($at)];
        };

        // every seeded outbound message of the window: the send call + Interakt's status webhooks
        $out = db_all("SELECT m.*, c.phone, c.name, c.country_code, c.national_number FROM wa_messages m JOIN wa_contacts c ON c.id = m.contact_id
                        WHERE m.direction = 'out' AND m.callback_data LIKE 'seed:%' AND m.interakt_id IS NOT NULL AND m.sent_at >= ? AND m.sent_at <= ?
                        ORDER BY m.sent_at, m.id", [$fmt($since), $fmt($now)]);
        $failedHooks = 2;
        foreach ($out as $i => $m) {
            $sent = strtotime((string) $m['sent_at']);
            try {
                $req = wa_build_payload($m, $m);
            } catch (Throwable $e) {
                $req = ['countryCode' => $m['country_code'] ?: '+91', 'phoneNumber' => $m['national_number'], 'callbackData' => 'gimt:msg:' . $m['id'], 'type' => 'Template'];
            }
            $api('message/', $req, ['result' => true, 'message' => 'Simulated (test mode): message not sent to WhatsApp', 'id' => $m['interakt_id']], 201, (int) $m['id'], $sent - 1);
            $hook($statusPayload($m, 'sent', $sent + 1), $sent + mt_rand(1, 3), $m['interakt_id']);
            if ($m['delivered_at']) {
                $del = strtotime((string) $m['delivered_at']);
                if ($m['read_at'] && mt_rand(1, 100) <= 2) {
                    // Interakt sometimes reports delivery after the read receipt
                    $hook($statusPayload($m, 'delivered', $del), strtotime((string) $m['read_at']) + mt_rand(2, 30), $m['interakt_id'], 'processed', 'No status change (already read)');
                } else {
                    $hook($statusPayload($m, 'delivered', $del), $del + mt_rand(1, 3), $m['interakt_id']);
                }
            }
            if ($m['read_at']) {
                $rd = strtotime((string) $m['read_at']);
                $hook($statusPayload($m, 'read', $rd), $rd + mt_rand(1, 3), $m['interakt_id']);
            } elseif ($m['status'] === 'delivered' && $failedHooks > 0 && $sent >= $today0 - 5 * 86400 && $sent < $today0 - 86400 && mt_rand(1, 100) <= 4) {
                // a read receipt that hit a database deadlock (the message stayed "delivered"; Reprocess fixes it)
                $failedHooks--;
                $rd = strtotime((string) $m['delivered_at']) + mt_rand(600, 7200);
                $hook($statusPayload($m + ['read_at' => $fmt($rd)], 'read', $rd), $rd + 1, $m['interakt_id'], 'failed',
                    'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
            }
            if ($m['status'] === 'failed' && $m['failed_at']) {
                $ft = strtotime((string) $m['failed_at']);
                $hook($statusPayload($m, 'failed', $ft), $ft + mt_rand(1, 3), $m['interakt_id']);
            }
        }

        // every seeded inbound message of the window (message_received)
        $types = ['text' => 'Text', 'image' => 'Image', 'document' => 'Document', 'audio' => 'Audio', 'video' => 'Video', 'location' => 'Location',
            'button_reply' => 'InteractiveButtonReply', 'list_reply' => 'InteractiveListReply', 'sticker' => 'Sticker'];
        foreach (db_all("SELECT m.id, m.type, m.body, m.media_url, m.file_name, m.interakt_id, m.created_at, c.phone, c.name FROM wa_messages m JOIN wa_contacts c ON c.id = m.contact_id
                          WHERE m.direction = 'in' AND m.callback_data LIKE 'seed:%' AND m.interakt_id IS NOT NULL AND m.created_at >= ? AND m.created_at <= ?
                          ORDER BY m.created_at, m.id", [$fmt($since), $fmt($now)]) as $m) {
            $at = strtotime((string) $m['created_at']);
            $type = (string) $m['type'];
            $text = (string) $m['body'];
            if ($type === 'button_reply' || $type === 'list_reply') {
                $text = $json([$type => ['id' => strtolower((string) preg_replace('/\W+/', '_', $text)), 'title' => $text]]);
            } elseif (in_array($type, ['image', 'video', 'document', 'audio', 'sticker'], true)) {
                $text = $json(['caption' => $m['body'], 'filename' => $m['file_name']]);
            }
            $hook(['version' => '1.0', 'timestamp' => wa_utc_stamp($at), 'type' => 'message_received', 'data' => [
                'customer' => ['id' => $customerId((string) $m['phone']), 'channel_phone_number' => (string) $m['phone'], 'traits' => (object) array_filter(['name' => $m['name']])],
                'message' => ['id' => $m['interakt_id'], 'chat_message_type' => 'CustomerMessage', 'message_status' => 'Sent', 'received_at_utc' => wa_utc_stamp($at),
                    'is_template_message' => false, 'raw_template' => null, 'message_content_type' => $types[$type] ?? 'Text', 'media_url' => $m['media_url'] ?: null,
                    'message' => $text, 'meta_data' => (object) []],
            ]], $at + mt_rand(0, 1), $m['interakt_id']);
        }

        // statuses of messages sent from the Interakt dashboard itself (not GIMT's): stored, ignored
        $someone = db_all("SELECT phone, name FROM wa_contacts WHERE status = 'active' AND contact_type IN ('lead','student') ORDER BY id LIMIT 40");
        for ($day = $since; $day <= $today0 && $someone; $day += 86400) {
            if (mt_rand(1, 100) > 70) {
                continue;
            }
            $c = demo_pick($someone);
            $at = $officeTime($day, 600, 1080);
            if ($at > $now - 60) {
                continue;
            }
            $id = $uuid();
            $fake = ['id' => 0, 'interakt_id' => $id, 'campaign_id' => null, 'type' => 'text', 'body' => 'Hi, this is GIMT Admissions. Please call us back when you are free.',
                'phone' => $c['phone'], 'name' => $c['name'], 'sent_at' => $fmt($at - 5), 'delivered_at' => $fmt($at), 'read_at' => null, 'error_code' => null, 'error' => null, 'cost' => null];
            $p = $statusPayload($fake, 'delivered', $at);
            $p['data']['message']['meta_data'] = ['source' => 'Interakt dashboard'];
            $hook($p, $at + 1, $id, 'ignored', 'Unknown message ' . $id);
        }

        // Track User calls for the opt-ins / opt-outs of the window
        foreach (db_all("SELECT id, name, email, contact_type, program_name, application_no, city, stage, opt_in, country_code, national_number,
                                opt_in_at, opted_out_at FROM wa_contacts
                          WHERE (opt_in = 1 AND opt_in_at >= ? AND opt_in_at <= ?) OR (opted_out_at >= ? AND opted_out_at <= ?) ORDER BY id", [$fmt($since), $fmt($now), $fmt($since), $fmt($now)]) as $c) {
            $at = strtotime((string) ((int) $c['opt_in'] === 1 ? $c['opt_in_at'] : $c['opted_out_at'])) + mt_rand(2, 30);
            if ($at > $now) {
                continue;
            }
            $traits = array_filter(['name' => $c['name'], 'email' => $c['email'], 'contact_type' => $c['contact_type'], 'program' => $c['program_name'],
                'application_no' => $c['application_no'], 'city' => $c['city'], 'stage' => $c['stage'], 'gimt_contact_id' => (string) $c['id']], fn ($v) => $v !== null && $v !== '');
            $traits['whatsapp_opted_in'] = (int) $c['opt_in'] === 1;
            $req = ['countryCode' => $c['country_code'] ?: '+91', 'phoneNumber' => (string) $c['national_number'], 'traits' => $traits];
            $tags = function_exists('wa_contact_tag_names') ? array_slice(wa_contact_tag_names((int) $c['id']), 0, 50) : [];
            if ($tags) {
                $req['tags'] = $tags;
            }
            $api('track/users/', $req, ['result' => true, 'message' => 'Simulated (test mode): customer updated'], 202, null, $at);
        }

        usort($hookRows, fn ($a, $b) => strcmp($a['received_at'], $b['received_at']));
        usort($apiRows, fn ($a, $b) => strcmp($a['created_at'], $b['created_at']));
        demo_bulk_insert('wa_webhook_logs', $hookRows, 200);
        demo_bulk_insert('wa_api_logs', $apiRows, 200);

        $stats = db_row("SELECT COUNT(*) AS n, SUM(status = 'failed') AS failed, SUM(actions LIKE '%\"status\":\"skipped\"%') AS skipped,
                                SUM(actions LIKE '%\"status\":\"failed\"%') AS send_failed FROM wa_event_logs WHERE dedupe_key LIKE 'demo:%'") ?: [];
        echo '    event logs: ' . (int) ($stats['n'] ?? 0) . ' (' . count($messages) . ' new messages, ' . count($linkUpdates) . ' linked, ' . (int) ($stats['skipped'] ?? 0) . ' skipped, '
            . (int) ($stats['send_failed'] ?? 0) . ' send failed, ' . (int) ($stats['failed'] ?? 0) . ' listener errors), webhooks: ' . count($hookRows) . ', api calls: ' . count($apiRows) . "\n";
    } finally {
        if ($locked) {
            db_value("SELECT RELEASE_LOCK('gimt_wa_worker')");
        }
    }
};
