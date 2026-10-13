<?php
/**
 * AI chatbot (core, always runs) — unit chatbot, docs/CHATBOT.md.
 *   - settings group "chatbot": every key from chatbot_defaults() with INSERT IGNORE (admin changes are kept)
 *   - demo data (only with $opts['demo'] and when programs exist): ~70 website conversations over the last 30 days,
 *     answered by the real rule-based engine (provider "offline"), with feedback and callback requests, tagged
 *     source = 'demo' so a re-run replaces only these rows.
 */
return function (array $opts): void {
    require_once APP_ROOT . '/app/services/chatbot.php';

    foreach (chatbot_defaults() as $key => $value) {
        db_exec('INSERT IGNORE INTO settings (`group`, `key`, `value`) VALUES (?, ?, ?)', ['chatbot', $key, $value]);
    }
    settings_all(true);
    chatbot_kb_flush();

    try {
        db_exec("DELETE FROM chatbot_conversations WHERE source = 'demo'");
    } catch (Throwable $e) {
        return; // tables not created yet (schema 43 not applied)
    }
    if (empty($opts['demo']) || !(int) db_value("SELECT COUNT(*) FROM programs WHERE status = 'active'")) {
        return;
    }

    require_once __DIR__ . '/lib/demo_data.php';
    demo_seed(909);
    $questions = [
        ['Which programs do you offer?', 'en'], ['What is the fee for BBA?', 'en'], ['MBA eligibility kya hai?', 'hinglish'], ['Is hostel available for girls?', 'en'],
        ['How are the placements?', 'en'], ['What is the last date to apply?', 'en'], ['Do you provide transport from Noida?', 'en'], ['Which scholarships are available?', 'en'],
        ['BCA ki fees kitni hai?', 'hinglish'], ['बी.टेक की फीस कितनी है?', 'hi'], ['How do I apply for admission?', 'en'], ['Which documents are required at the time of admission?', 'en'],
        ['Is there an entrance test or interview?', 'en'], ['Can I visit the campus before applying?', 'en'], ['What is the highest package?', 'en'],
        ['Do you offer B.Tech in AI and Data Science?', 'en'], ['Can fees be paid in instalments?', 'en'], ['Where is the campus located?', 'en'],
        ['Is the college approved by AICTE?', 'en'], ['I want to talk to a counsellor', 'en'], ['Are internships compulsory?', 'en'], ['Hostel fees kitni hai?', 'hinglish'],
        ['What is the eligibility for B.Com (Hons)?', 'en'], ['Do you have a PhD program?', 'en'], ['What is the minimum attendance requirement?', 'en'],
        ['MCA kitne saal ka hai?', 'hinglish'], ['Tell me about the digital marketing course', 'en'], ['मुझे एडमिशन के लिए कॉल करें', 'hi'], ['Is education loan assistance available?', 'en'],
        ['What facilities are available on campus?', 'en'], ['hello', 'en'], ['Which companies visit the campus?', 'en'], ['B.Sc Data Science fees?', 'en'],
    ];
    $followUps = [['Thanks!', 'en'], ['Which scholarships are available?', 'en'], ['How do I apply?', 'en'], ['What is the last date to apply?', 'en'], ['Hostel fees?', 'en'], ['dhanyavad', 'hinglish']];
    $pages = ['/', '/programs', '/admissions', '/programs/bachelor-of-business-administration', '/programs/master-of-business-administration', '/campus', '/contact', '/admissions-open', '/apply'];
    $agents = [
        'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15',
    ];

    for ($i = 0; $i < 72; $i++) {
        $start = strtotime(demo_datetime('-30 days', '-1 hour'));
        $utm = mt_rand(1, 100) <= 30 ? demo_pick([['google', 'cpc', 'admissions-2026'], ['facebook', 'social', 'mba-2026'], ['instagram', 'social', 'ug-admissions']]) : [null, null, null];
        $convId = db_insert('chatbot_conversations', [
            'public_id' => bin2hex(pack('N', mt_rand()) . pack('N', mt_rand()) . pack('N', mt_rand()) . pack('N', $i)), 'visitor_hash' => hash('sha256', 'demo-visitor-' . $i),
            'user_agent' => demo_pick($agents), 'page_url' => demo_pick($pages), 'utm_source' => $utm[0], 'utm_medium' => $utm[1], 'utm_campaign' => $utm[2],
            'source' => 'demo', 'status' => 'closed', 'started_at' => date('Y-m-d H:i:s', $start),
        ]);
        $turns = demo_weighted([1 => 45, 2 => 35, 3 => 20]);
        $t = $start;
        $history = [];
        $lead = false;
        $first = null;
        for ($k = 0; $k < $turns; $k++) {
            [$q, $lang] = $k === 0 ? demo_pick($questions) : demo_pick($followUps);
            $first ??= $q;
            $intents = chatbot_detect_intents($q);
            $programs = chatbot_detect_programs($q);
            $ans = chatbot_offline_answer($q, ['intents' => $intents, 'programs' => $programs, 'lang' => chatbot_detect_language($q)]);
            $t += mt_rand(8, 70);
            db_insert('chatbot_messages', ['conversation_id' => $convId, 'role' => 'user', 'content' => $q, 'intent' => $intents[0] ?? ($programs ? 'program_detail' : 'unknown'), 'created_at' => date('Y-m-d H:i:s', $t)]);
            $t += mt_rand(1, 3);
            db_insert('chatbot_messages', [
                'conversation_id' => $convId, 'role' => 'assistant', 'content' => $ans['reply'], 'intent' => $ans['intent'], 'provider' => 'offline',
                'sources' => $ans['sources'] ? json_encode($ans['sources'], JSON_UNESCAPED_SLASHES) : null, 'latency_ms' => mt_rand(40, 260),
                'feedback' => demo_weighted(['none' => 70, 'up' => 24, 'down' => 6]) === 'none' ? null : (mt_rand(1, 100) <= 80 ? 1 : -1),
                'created_at' => date('Y-m-d H:i:s', $t),
            ]);
            $lead = $lead || $ans['lead_prompt'];
            $history[] = $q;
        }
        $captured = $lead ? mt_rand(1, 100) <= 55 : mt_rand(1, 100) <= 8;
        $name = demo_pick(demo_first_names(demo_pick(['male', 'female']))) . ' ' . demo_pick(demo_last_names());
        if ($captured) {
            $t += mt_rand(20, 90);
            db_insert('chatbot_messages', ['conversation_id' => $convId, 'role' => 'event', 'content' => 'Callback requested (demo data — no enquiry created) — ' . $name, 'created_at' => date('Y-m-d H:i:s', $t)]);
        }
        $up = (int) db_value('SELECT COUNT(*) FROM chatbot_messages WHERE conversation_id = ? AND feedback = 1', [$convId]);
        $down = (int) db_value('SELECT COUNT(*) FROM chatbot_messages WHERE conversation_id = ? AND feedback = -1', [$convId]);
        db_update('chatbot_conversations', [
            'title' => mb_substr((string) $first, 0, 255), 'language' => chatbot_detect_language((string) $first), 'message_count' => $turns * 2, 'user_message_count' => $turns,
            'last_provider' => 'offline', 'lead_status' => $captured ? 'captured' : ($lead ? 'offered' : 'none'), 'lead_name' => $captured ? $name : null,
            'feedback_up' => $up, 'feedback_down' => $down, 'last_message_at' => date('Y-m-d H:i:s', $t),
        ], 'id = ?', [$convId]);
    }
};
