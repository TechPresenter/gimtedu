<?php
/**
 * Demo data for the WhatsApp AI chatbot (Module 9): bot sessions + bot turns for the demo WhatsApp contacts, so the
 * Chatbot overview (answer rate, hand-overs, intents, languages, unanswered questions) and the Sessions tab look live.
 * Runs after the 98_wa_* contacts / inbox seeds (it needs wa_contacts + wa_conversations). Every demo answer is
 * produced by the real answer engine (verified program data, knowledge base, rule-based path — never an AI call), so the
 * demo never shows a fee or fact that is not in the database. Dates are anchored on today (last 30 days).
 *
 * Demo only, re-runnable: owns the bot turns without an inbound message (live turns always have one) and the sessions
 * marked _flow.demo; the production settings + knowledge base come from 09_wa_5_bot.php. Handed-over demo sessions use
 * contacts whose conversation already has bot_active = 0; wa_conversations rows are not modified. Contacts who opted out
 * (replied STOP) get no demo bot chat — the live bot never answers them (wa_bot_skip_reason 'opted_out'). Demo
 * qualified leads do not create ERP enquiries (enquiries belong to the admissions seeders).
 */
return function (array $opts): void {
    require_once APP_ROOT . '/app/services/whatsapp.php';
    require_once __DIR__ . '/lib/demo_data.php';
    if (!function_exists('wa_bot_answer')) {
        return;
    }
    demo_seed(909);
    db_exec('DELETE FROM wa_bot_turns WHERE inbound_message_id IS NULL AND is_test = 0');
    db_exec("DELETE FROM wa_bot_sessions WHERE qualification LIKE '%\"demo\":true%'");
    $pick = fn (int $botActive, int $n) => db_all(
        "SELECT c.*, cv.id AS conv_id, cv.bot_active FROM wa_contacts c JOIN wa_conversations cv ON cv.contact_id = c.id
         LEFT JOIN wa_bot_sessions s ON s.contact_id = c.id
         WHERE s.id IS NULL AND c.status = 'active' AND c.contact_type IN ('lead', 'applicant', 'student') AND cv.bot_active = ?
           AND NOT (c.opt_in <> 1 AND c.opted_out_at IS NOT NULL)
         ORDER BY c.id LIMIT " . $n, [$botActive]);
    $contacts = array_merge($pick(0, 16), $pick(1, 54));
    if (!$contacts) {
        return; // no WhatsApp demo contacts (98_wa_1_contacts / 98_wa_2_inbox not run)
    }
    $bank = [
        'en' => ['What is the fee for BBA?', 'BCA fees per year?', 'What is the eligibility for MBA?', 'Which courses do you offer?', 'What documents are required for admission?',
            'How do I apply for admission?', 'Is there an application fee?', 'How can I pay the fees?', 'What is the duration of B.Tech CSE?', 'What is the last date to apply?',
            'What is your contact number?', 'Tell me about MCA', 'How can I verify a certificate?', 'Where can I see my result?', 'B.Com Hons fees?', 'Eligibility for B.Tech AI & DS?',
            'What is the address of the college?', 'Tell me about GIMT', 'When will results be declared?', 'How do I get my degree certificate?'],
        'hing' => ['BBA ki fees kitni hai?', 'MBA ke liye eligibility kya hai?', 'Admission kaise milega?', 'Kaunse courses hai aapke yahan?', 'Documents kya kya lagenge?',
            'Fees kaise jama karein?', 'BCA kitne saal ka hai?', 'Result kahan dekhein?', 'MCA ki fees batao', 'GIMT ke baare mein batao', 'Result kab aayega?'],
        'hi' => ['बीबीए की फीस कितनी है?', 'एमबीए के लिए योग्यता क्या है?', 'एडमिशन कैसे होगा?', 'कौन से कोर्स उपलब्ध हैं?', 'कौन से दस्तावेज़ चाहिए?', 'फीस कैसे जमा करें?', 'GIMT के बारे में बताइए'],
    ];
    $unknown = [
        'en' => ['Is there an NRI quota?', 'Do you have a swimming pool?', 'Is there lateral entry in B.Tech?', 'What was the cut-off for BBA last year?',
            'Do you have a pilot training course?', 'What is the dress code?', 'Can I change my branch after first year?'],
        'hing' => ['Kya NRI quota hai?', 'Branch change ho sakta hai kya?', 'Lateral entry milti hai kya?'],
        'hi' => ['क्या कैंपस में स्विमिंग पूल है?', 'क्या NRI कोटा है?'],
    ];
    $quals = ['10th pass', '12th appearing', '12th pass', 'Diploma', 'Graduate', 'Postgraduate', 'Working professional'];
    $intakes = wa_bot_intake_options('en');
    $reasons = ['requested: counsellor' => 40, 'low_confidence' => 20, 'sensitive: refund' => 8, 'agent: Agent took over from the inbox' => 22, 'max_turns' => 5, 'student_record' => 5];
    $turns = [];
    $kbHits = [];
    $fmt = fn (int $ts) => date('Y-m-d H:i:s', $ts);
    $today = strtotime(date('Y-m-d') . ' 00:00:00');
    foreach ($contacts as $c) {
        $daysAgo = mt_rand(1, 100) <= 55 ? mt_rand(0, 13) : mt_rand(14, 29);
        $ts = $today - $daysAgo * 86400 + mt_rand(9 * 3600, 21 * 3600);
        if ($ts > time() - 1800) {
            // later today: an earlier day at the same time (keeps today from piling up, never in the future)
            $ts -= 86400 * mt_rand(1, 6);
        }
        $lang = demo_weighted(['en' => 60, 'hing' => 25, 'hi' => 15]);
        $isLead = $c['contact_type'] === 'lead';
        $handedOver = (int) $c['bot_active'] === 0;
        $answers = [];
        $flow = ['pending' => null, 'done' => false, 'started' => false, 'lang_locked' => $lang === 'hi' && mt_rand(0, 1) === 1, 'low_conf' => 0, 'turns' => 0, 'retries' => 0,
            'skipped' => [], 'program_id' => null, 'greeted' => true, 'handback' => false, 'demo' => true];
        $rows = [];
        $add = function (array $r) use (&$rows, &$ts, $c) {
            $ts += mt_rand(40, 260);
            $rows[] = $r + ['contact_id' => (int) $c['id'], 'created_at' => date('Y-m-d H:i:s', min($ts, time()))];
        };
        if (mt_rand(1, 100) <= 65) {
            $add(['question' => demo_pick(['Hi', 'Hello', 'Namaste', 'Hello sir', 'नमस्ते']), 'reply' => wa_bot_text('greeting', $lang, ['name' => '']), 'language' => $lang,
                'intent' => 'greeting', 'action' => 'greeting', 'confidence' => 1, 'answered' => 1, 'provider' => 'flow', 'latency_ms' => mt_rand(20, 90)]);
        }
        $qs = (array) array_rand(array_flip($bank[$lang]), min(count($bank[$lang]), mt_rand(2, 5)));
        if (mt_rand(1, 100) <= 40) {
            $qs[] = demo_pick($unknown[$lang]);
        }
        if (mt_rand(1, 100) <= 8) {
            $qs[] = demo_pick(['en' => ['Will I surely get admission in BBA?'], 'hing' => ['Pakka admission milega na?'], 'hi' => ['क्या एडमिशन पक्का मिलेगा?']][$lang]);
        }
        foreach ($qs as $q) {
            $ans = wa_bot_answer($q, [], ['lang' => $lang, 'no_ai' => true, 'test' => true]);
            foreach ($ans['facts'] as $f) {
                if (($f['type'] ?? '') === 'whatsapp_kb') {
                    $kid = (int) substr((string) $f['id'], 5);
                    $kbHits[$kid] = ($kbHits[$kid] ?? 0) + 1;
                }
            }
            $add(['question' => $q, 'reply' => $ans['answered'] ? $ans['reply'] : wa_bot_text('fallback', $lang), 'language' => $lang, 'intent' => mb_substr((string) $ans['intent'], 0, 40),
                'intents' => mb_substr(implode(',', $ans['intents']), 0, 190), 'action' => $ans['answered'] ? 'answer' : 'fallback', 'confidence' => $ans['confidence'],
                'answered' => $ans['answered'] ? 1 : 0, 'provider' => $ans['provider'], 'facts' => $ans['facts'] ? wa_bot_json($ans['facts']) : null, 'latency_ms' => max(15, (int) $ans['latency_ms'])]);
            if ($ans['answered'] && $ans['programs'] && empty($answers['program'])) {
                $answers['program'] = $ans['programs'][0];
            }
        }
        // lead qualification
        if ($isLead && mt_rand(1, 100) <= 70) {
            $first = trim((string) explode(' ', (string) $c['name'])[0]);
            $answers += array_filter(['name' => $c['name'] ?: null, 'program' => $c['program_name'] ? ((string) db_value('SELECT short_name FROM programs WHERE id = ?', [(int) $c['program_id']]) ?: $c['program_name']) : null,
                'city' => $c['city'] ?: null]);
            $answers['qualification'] = demo_pick($quals);
            $done = mt_rand(1, 100) <= 75;
            if ($done) {
                $answers['intake'] = demo_pick($intakes);
            }
            foreach (['program', 'city', 'qualification', 'intake'] as $k) {
                if (isset($answers[$k])) {
                    $add(['question' => $answers[$k], 'reply' => null, 'language' => $lang, 'intent' => 'qualify:' . $k, 'action' => 'qualify', 'confidence' => 1, 'answered' => 1, 'provider' => 'flow',
                        'latency_ms' => mt_rand(10, 60)]);
                }
            }
            $flow['started'] = true;
            $flow['done'] = $done;
            $flow['pending'] = $done ? null : 'intake';
            if ($done && $rows) {
                $rows[count($rows) - 1]['reply'] = wa_bot_text('qualify_done', $lang, ['name' => $first, 'program' => $answers['program'] ?? wa_bot_t('program_any', $lang)]);
            }
        }
        $reason = null;
        if ($handedOver) {
            $reason = demo_weighted($reasons);
            $code = explode(':', $reason)[0];
            $q = ['requested' => demo_pick(['I want to talk to a counsellor', 'Counsellor se baat karni hai', 'Please call me', 'Talk to counsellor']), 'low_confidence' => demo_pick($unknown['en']),
                'sensitive' => 'I want a refund of my admission fee', 'agent' => null, 'max_turns' => 'One more question', 'student_record' => 'What is my result?'][$code] ?? null;
            if ($q !== null) {
                $pre = $code === 'low_confidence' ? wa_bot_t('unsure_handover', $lang) . "\n\n" : ($code === 'student_record' ? wa_bot_t('student_data', $lang) . "\n\n" : '');
                $add(['question' => $q, 'reply' => $pre . wa_bot_text('handover', $lang, ['counsellor' => wa_bot_t('team', $lang)]), 'language' => $lang,
                    'intent' => $code === 'requested' ? 'counsellor' : ($code === 'low_confidence' ? 'unknown' : $code),
                    'action' => 'handover', 'confidence' => $code === 'low_confidence' ? 0.1 : 1, 'answered' => $code === 'low_confidence' ? 0 : 1, 'provider' => 'flow', 'handover' => 1, 'latency_ms' => mt_rand(30, 120)]);
            }
        }
        foreach ($rows as $r) {
            $turns[] = $r + ['intents' => null, 'facts' => null, 'handover' => 0, 'is_test' => 0, 'session_id' => null];
        }
        $last = end($rows);
        $state = $handedOver ? 'handed_over' : ($flow['pending'] ? 'qualifying' : 'active');
        $flow['turns'] = count($rows);
        db_insert('wa_bot_sessions', [
            'contact_id' => (int) $c['id'], 'language' => $lang, 'state' => $state, 'qualification' => wa_bot_meta_encode(['answers' => $answers, 'flow' => $flow]),
            'messages_count' => count($rows), 'handed_over_at' => $handedOver ? ($last['created_at'] ?? $fmt($ts)) : null, 'handover_reason' => $reason,
            'last_activity_at' => $last['created_at'] ?? $fmt($ts), 'created_at' => $rows[0]['created_at'] ?? $fmt($ts),
        ]);
    }
    // link turns to their sessions, then insert
    $sessionIds = db_pairs("SELECT contact_id, id FROM wa_bot_sessions WHERE qualification LIKE '%\"demo\":true%'");
    foreach ($turns as &$t) {
        $t['session_id'] = $sessionIds[$t['contact_id']] ?? null;
        $t['confidence'] = $t['confidence'] !== null ? round((float) $t['confidence'], 3) : null;
    }
    unset($t);
    $cols = ['contact_id', 'session_id', 'question', 'reply', 'language', 'intent', 'intents', 'action', 'confidence', 'answered', 'provider', 'facts', 'handover', 'latency_ms', 'is_test', 'created_at'];
    demo_bulk_insert('wa_bot_turns', array_map(fn ($t) => array_intersect_key($t + array_fill_keys($cols, null), array_flip($cols)), $turns));
    // demo usage counters on the starter answers (only answers nobody has used live yet)
    foreach ($kbHits as $id => $n) {
        db_exec('UPDATE wa_bot_knowledge SET hits = ?, last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR hits <= ?)', [$n, date('Y-m-d H:i:s', time() - mt_rand(600, 86400 * 3)), $id, $n]);
    }
    foreach (array_keys($sessionIds) as $cid) {
        wa_bot_refresh_summary((int) $cid, false);
    }
};
