<?php
/**
 * WhatsApp inbox demo (part: inbox): ~60 conversations with realistic multi-message threads (English, Hindi /
 * Hinglish), delivery ticks, media + document messages, template sends, interactive buttons / lists, bot hand-overs,
 * assignments to the admission team, internal notes, agent activity and the quick-reply library.
 *
 * Fees, scholarships, hostel rent, bus fares and the address quoted in messages / quick replies are read from the ERP
 * (programs, scholarships, hostels + hostel_rooms, transport_routes + transport_stops, settings) — never invented. Bot
 * messages only say what the bot can answer from verified data; for anything else they hand over to the team.
 * Uses the contacts seeded by 98_wa_1_contacts; when there are too few it creates minimal contacts from demo
 * enquiries / applications / students. Times are anchored on now (last 14 days, a dozen windows still open).
 * Re-runnable: removes only the rows it created (messages with callback_data 'seed:inbox', notes and agent activity of
 * the seeded conversations) and upserts conversations and quick replies.
 */
require_once __DIR__ . '/lib/demo_data.php';

return function (array $opts): void {
    if (!db_table_exists('wa_conversations')) {
        return;
    }
    if (is_file(APP_ROOT . '/app/services/whatsapp.php')) {
        require_once APP_ROOT . '/app/services/whatsapp.php';
    }
    demo_seed(98002);
    $now = time();
    $fmt = fn (int $ts) => date('Y-m-d H:i:s', $ts);

    /* ---------------------------------------------------------------- agents */
    $agentsBySlug = [];
    foreach (db_all("SELECT u.id, u.name, r.slug FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                      WHERE u.status = 'active' AND r.slug IN ('admission-officer','administrator','super-admin') ORDER BY u.id") as $u) {
        $agentsBySlug[$u['slug']][] = ['id' => (int) $u['id'], 'name' => $u['name']];
    }
    $admissionTeam = $agentsBySlug['admission-officer'] ?? [];
    $officeTeam = array_merge($agentsBySlug['administrator'] ?? [], $agentsBySlug['super-admin'] ?? []);
    if (!$admissionTeam) {
        $admissionTeam = $officeTeam;
    }
    if (!$officeTeam) {
        $officeTeam = $admissionTeam;
    }
    if (!$admissionTeam) {
        $first = db_row('SELECT id, name FROM users ORDER BY id LIMIT 1');
        if (!$first) {
            return;
        }
        $admissionTeam = $officeTeam = [['id' => (int) $first['id'], 'name' => $first['name']]];
    }

    /* ---------------------------------------------------------------- verified facts from the ERP
     * Every amount quoted in a seeded message or quick reply comes from these tables (programs, scholarships, hostels /
     * hostel_rooms, transport_routes / transport_stops, settings). Nothing is invented; when a value is missing the
     * text says a counsellor will confirm it instead. */
    $inr = function ($n): string {
        $s = (string) (int) round((float) $n);
        if (strlen($s) <= 3) {
            return '₹' . $s;
        }
        return '₹' . preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($s, 0, -3)) . ',' . substr($s, -3);
    };
    $has = fn (string $t) => db_table_exists($t);
    $programFees = $has('programs') ? array_map('floatval', db_pairs('SELECT id, fee_per_year FROM programs WHERE fee_per_year > 0')) : [];
    $programFeesByName = $has('programs') ? array_map('floatval', db_pairs('SELECT name, fee_per_year FROM programs WHERE fee_per_year > 0')) : [];
    $sch = [];
    if ($has('scholarships')) {
        foreach (db_all("SELECT code, name, type, value, criteria, max_amount FROM scholarships WHERE status = 'active' ORDER BY id") as $s) {
            $sch[strtoupper((string) $s['code'])] = $s;
        }
    }
    $schShort = function (?array $s) use ($inr): ?string {
        if (!$s) {
            return null;
        }
        $v = $s['type'] === 'percentage' ? rtrim(rtrim(number_format((float) $s['value'], 2), '0'), '.') . '% of tuition' : $inr($s['value']);
        return $v . ($s['max_amount'] ? ' (up to ' . $inr($s['max_amount']) . ')' : '');
    };
    $renewal = function (?array $s): string {
        return $s && preg_match('/(Renewed.*?\.)(?=\s|$)/u', (string) $s['criteria'], $m) ? ' ' . $m[1] : '';
    };
    $merit80 = $sch['MERIT80'] ?? null;
    $sports = $sch['SPORTS'] ?? null;
    $hostelLines = [];
    $messFee = null;
    $girls = ['ac' => null, 'nonac' => null, 'mess' => null];
    if ($has('hostels') && $has('hostel_rooms')) {
        foreach (db_all("SELECT h.id, h.name, h.type, h.mess_fee, MIN(r.annual_fee) AS min_fee, MAX(r.annual_fee) AS max_fee,
                                MIN(CASE WHEN r.is_ac = 1 AND r.room_type = 'double' THEN r.annual_fee END) AS ac_double,
                                MIN(CASE WHEN r.is_ac = 0 AND r.room_type = 'double' THEN r.annual_fee END) AS nonac_double
                           FROM hostels h JOIN hostel_rooms r ON r.hostel_id = h.id AND r.annual_fee > 0
                          WHERE h.status = 'active' GROUP BY h.id, h.name, h.type, h.mess_fee ORDER BY h.id") as $h) {
            $hostelLines[] = '• ' . $h['name'] . ' — ' . ($h['min_fee'] === $h['max_fee'] ? $inr($h['min_fee']) : $inr($h['min_fee']) . '–' . $inr($h['max_fee'])) . '/year by room type';
            $messFee = $messFee ?? ($h['mess_fee'] > 0 ? (float) $h['mess_fee'] : null);
            if ($h['type'] === 'girls' && $girls['nonac'] === null) {
                $girls = ['ac' => $h['ac_double'], 'nonac' => $h['nonac_double'], 'mess' => $h['mess_fee'] > 0 ? $h['mess_fee'] : null];
            }
        }
    }
    $routeCount = 0;
    $fareRange = null;
    $sector62 = null;
    if ($has('transport_routes')) {
        $tr = db_row("SELECT COUNT(*) AS n, MIN(annual_fare) AS lo, MAX(annual_fare) AS hi FROM transport_routes WHERE status = 'active'");
        $routeCount = (int) ($tr['n'] ?? 0);
        $fareRange = !empty($tr['lo']) ? [$tr['lo'], $tr['hi']] : null;
        if ($has('transport_stops')) {
            $sector62 = db_row("SELECT r.code, r.name AS route_name, r.evening_departure, s.name AS stop_name, s.pickup_time, COALESCE(s.annual_fare, r.annual_fare) AS fare
                                  FROM transport_stops s JOIN transport_routes r ON r.id = s.route_id
                                 WHERE r.status = 'active' AND s.name LIKE ? ORDER BY s.sequence LIMIT 1", ['%Sector 62%']);
        }
    }
    $address = trim((string) setting('address', ''));
    $mapsLink = $address !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($address) : '';
    $site = 'https://gimtedu.com';
    $clock = fn (?string $t) => $t ? date('g:i A', strtotime('2000-01-01 ' . $t)) : null;

    /* ---------------------------------------------------------------- quick replies */
    $qrOwner = $admissionTeam[0]['id'];
    $scholarshipLines = [];
    foreach (['MERIT90', 'MERIT80', 'SPORTS', 'SGC'] as $code) {
        if (isset($sch[$code])) {
            $scholarshipLines[] = '• ' . $sch[$code]['name'] . ' — ' . $schShort($sch[$code]);
        }
    }
    $quickReplies = [
        ['greet', 'Greeting', "Hello {{first_name}} 👋\nThank you for contacting *GIMT Admissions*. I'm {{agent}} — how can I help you today?", 'General'],
        ['fees', 'Fee details 2026-27', "Hi {{first_name}}, the yearly tuition of every programme is listed on its page: $site/programs\nPayment rules and refunds are in our fee policy: $site/fee-policy\nHostel and transport are optional.", 'Fees'],
        ['scholarship', 'Scholarships', $scholarshipLines
            ? "GIMT scholarships for 2026-27:\n" . implode("\n", $scholarshipLines) . "\nFull rules: $site/scholarship-policy"
            : "Scholarship rules for 2026-27 are on our website: $site/scholarship-policy — a counsellor can check what you qualify for.", 'Admissions'],
        ['apply', 'How to apply', "You can apply online in about 10 minutes: $site/apply\nKeep these ready: photo, Class 10 & 12 marksheets, ID proof.", 'Admissions'],
        ['docs', 'Documents checklist', "Documents needed for admission:\n1. Class 10 & 12 marksheets\n2. Transfer / migration certificate\n3. Aadhaar card\n4. 4 passport-size photos\n5. Category certificate (if applicable)\nPlease upload them in your application portal.", 'Admissions'],
        ['visit', 'Campus visit timings', "Campus visits: Monday–Saturday, 10:00 AM to 4:00 PM. Report at the Admission Cell. Do share your preferred day and I'll arrange a counsellor for you.", 'Admissions'],
        ['hostel', 'Hostel details', $hostelLines
            ? "Separate hostels for boys and girls. Rent for 2026-27:\n" . implode("\n", $hostelLines) . ($messFee ? "\nMess: " . $inr($messFee) . '/year (charged separately).' : '')
            : 'Separate hostels for boys and girls. I will share the current hostel charges for your preferred room type.', 'Campus'],
        ['transport', 'Bus routes', $routeCount && $fareRange
            ? "College buses run on $routeCount routes. The annual transport fee depends on your stop (" . $inr($fareRange[0]) . '–' . $inr($fareRange[1]) . "). Share your area and I'll tell you the nearest pickup point."
            : "College buses cover several routes. Share your area and I'll tell you the nearest pickup point and its fee.", 'Campus'],
        ['paylink', 'Paying fees online', "You can pay securely from the student portal → Fees: $site/student-portal\nUPI, cards and net banking are accepted. The receipt is emailed to you.", 'Fees'],
        ['receipt', 'Receipt sent', "Payment received ✅ Your receipt has been emailed to you. You can also download it anytime from the student portal → Fees → Receipts.", 'Fees'],
        ['exam', 'Exam timetable', "The mid-semester timetable is published on the student portal → Examinations. Admit cards can be downloaded 3 days before the first paper.", 'Exams'],
        ['admitcard', 'Admit card help', "If the admit card does not download: clear your browser cache, log in again and try from Chrome. If fees are pending the admit card stays on hold — please contact the accounts office.", 'Exams'],
        ['callback', 'Callback scheduled', "Sure {{first_name}}, our counsellor will call you within the next 2 working hours. If you prefer a specific time, just reply with it.", 'General'],
        ['hindi', 'Namaste (Hindi)', "Namaste {{first_name}} ji 🙏\nGIMT Admissions mein aapka swagat hai. Main aapki kya madad kar sakti hoon?", 'General'],
        ['thanks', 'Closing', "Thank you for reaching out to GIMT, {{first_name}}! If you have any other question, just message us here. Have a great day 😊", 'General'],
        ['location', 'Our address', $address !== '' ? "GIMT: $address\nGoogle Maps: $mapsLink" : "Our address and map are on $site/contact", 'General'],
    ];
    foreach ($quickReplies as $i => [$shortcut, $title, $body, $cat]) {
        db_exec(
            'INSERT INTO wa_quick_replies (shortcut, title, body, category, usage_count, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), category = VALUES(category), usage_count = VALUES(usage_count)',
            [$shortcut, $title, $body, $cat, max(0, 64 - $i * 4 + mt_rand(0, 9)), $qrOwner, $fmt($now - 40 * 86400)]
        );
    }

    /* ---------------------------------------------------------------- contacts */
    // up to 40 named contacts per type, linked ones first (deterministic order)
    $loadContacts = function (): array {
        $rows = [];
        foreach (['lead', 'applicant', 'student', 'guardian'] as $t) {
            $rows = array_merge($rows, db_all(
                "SELECT id, name, phone, contact_type, program_id, program_name, application_no, student_id, enquiry_id, admission_id, opt_in
                   FROM wa_contacts WHERE status = 'active' AND contact_type = ? AND name IS NOT NULL AND name <> ''
                  ORDER BY (student_id IS NOT NULL OR enquiry_id IS NOT NULL OR admission_id IS NOT NULL) DESC, id LIMIT 40",
                [$t]
            ));
        }
        return $rows;
    };
    $contacts = $loadContacts();
    if (count($contacts) < 40) {
        $makePhone = function (?string $raw): ?string {
            if (function_exists('wa_normalize_phone')) {
                $p = wa_normalize_phone($raw);
                return $p['phone'] ?? null;
            }
            $d = preg_replace('/\D+/', '', (string) $raw);
            return strlen($d) >= 10 ? '91' . substr($d, -10) : null;
        };
        $sources = [];
        foreach (db_all("SELECT e.id, e.name, e.phone, e.email, e.city, e.program_id, p.name AS program_name FROM enquiries e LEFT JOIN programs p ON p.id = e.program_id ORDER BY e.id DESC LIMIT 30") as $e) {
            $sources[] = ['phone' => $e['phone'], 'name' => $e['name'], 'email' => $e['email'], 'city' => $e['city'], 'program_id' => $e['program_id'], 'program_name' => $e['program_name'], 'enquiry_id' => (int) $e['id'], 'contact_type' => 'lead', 'stage' => 'interested'];
        }
        foreach (db_all("SELECT a.id, a.first_name, a.last_name, COALESCE(a.whatsapp, a.phone) AS phone, a.email, a.city, a.state, a.program_id, a.application_no, p.name AS program_name FROM admissions a LEFT JOIN programs p ON p.id = a.program_id ORDER BY a.id DESC LIMIT 15") as $a) {
            $sources[] = ['phone' => $a['phone'], 'name' => trim($a['first_name'] . ' ' . $a['last_name']), 'email' => $a['email'], 'city' => $a['city'], 'state' => $a['state'], 'program_id' => $a['program_id'], 'program_name' => $a['program_name'], 'admission_id' => (int) $a['id'], 'application_no' => $a['application_no'], 'contact_type' => 'applicant', 'stage' => 'application_submitted'];
        }
        foreach (db_all("SELECT s.id, s.first_name, s.last_name, COALESCE(s.whatsapp, s.mobile) AS phone, s.email, s.city, s.state, s.program_id, p.name AS program_name FROM students s LEFT JOIN programs p ON p.id = s.program_id WHERE s.status = 'active' ORDER BY s.id LIMIT 15") as $s) {
            $sources[] = ['phone' => $s['phone'], 'name' => trim($s['first_name'] . ' ' . $s['last_name']), 'email' => $s['email'], 'city' => $s['city'], 'state' => $s['state'], 'program_id' => $s['program_id'], 'program_name' => $s['program_name'], 'student_id' => (int) $s['id'], 'contact_type' => 'student', 'stage' => 'admission_confirmed'];
        }
        foreach ($sources as $src) {
            $phone = $makePhone($src['phone']);
            if (!$phone) {
                continue;
            }
            $data = array_filter($src + ['phone' => $phone, 'opt_in' => 1, 'opt_in_source' => 'enquiry form consent', 'source' => 'sync'], fn ($v) => $v !== null && $v !== '');
            $data['phone'] = $phone;
            try {
                if (function_exists('wa_contact_upsert')) {
                    wa_contact_upsert($data);
                } else {
                    db_exec('INSERT IGNORE INTO wa_contacts (phone, country_code, national_number, name, email, contact_type, student_id, enquiry_id, admission_id, application_no, program_id, program_name, city, state, stage, opt_in, opt_in_at, opt_in_source, source)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?, ?)', [
                        $phone, '+91', substr($phone, 2), $src['name'], $src['email'] ?? null, $src['contact_type'], $src['student_id'] ?? null, $src['enquiry_id'] ?? null,
                        $src['admission_id'] ?? null, $src['application_no'] ?? null, $src['program_id'] ?? null, $src['program_name'] ?? null, $src['city'] ?? null, $src['state'] ?? null,
                        $src['stage'], 'enquiry form consent', 'sync',
                    ]);
                }
            } catch (Throwable $e) {
                // duplicate phone across sources — skip
            }
        }
        $contacts = $loadContacts();
    }
    if (!$contacts) {
        return;
    }
    // a deterministic mix: ~28 leads, ~14 applicants, ~12 students, ~6 guardians / others
    $byType = [];
    foreach ($contacts as $c) {
        $t = in_array($c['contact_type'], ['lead', 'applicant', 'student', 'guardian'], true) ? $c['contact_type'] : 'lead';
        $byType[$t][] = $c;
    }
    $want = ['lead' => 28, 'applicant' => 14, 'student' => 12, 'guardian' => 6];
    $chosen = [];
    foreach ($want as $t => $n) {
        foreach (array_slice($byType[$t] ?? [], 0, $n) as $c) {
            $chosen[$c['id']] = $c + ['kind' => $t];
        }
    }
    foreach ($contacts as $c) {
        if (count($chosen) >= 60) {
            break;
        }
        if (!isset($chosen[$c['id']])) {
            $t = in_array($c['contact_type'], ['lead', 'applicant', 'student', 'guardian'], true) ? $c['contact_type'] : 'lead';
            $chosen[$c['id']] = $c + ['kind' => $t];
        }
    }
    $chosen = array_values(array_slice($chosen, 0, 60, true));

    /* ---------------------------------------------------------------- conversations (upsert) + cleanup */
    $convIds = [];
    foreach ($chosen as $c) {
        db_exec('INSERT IGNORE INTO wa_conversations (contact_id, status, bot_active) VALUES (?, ?, 1)', [(int) $c['id'], 'open']);
        $convIds[(int) $c['id']] = (int) db_value('SELECT id FROM wa_conversations WHERE contact_id = ?', [(int) $c['id']]);
    }
    $ids = array_values($convIds);
    $in = implode(',', array_fill(0, count($ids), '?'));
    // conversations seeded by an earlier run for contacts no longer chosen: drop them when nothing else is left in them
    $previous = array_map('intval', db_column("SELECT DISTINCT conversation_id FROM wa_messages WHERE callback_data = 'seed:inbox'"));
    db_exec("DELETE FROM wa_messages WHERE callback_data = 'seed:inbox'");
    foreach (array_diff($previous, $ids) as $staleId) {
        if (!db_value('SELECT COUNT(*) FROM wa_messages WHERE conversation_id = ?', [$staleId])) {
            db_exec('DELETE FROM wa_agent_activity WHERE conversation_id = ?', [$staleId]);
            db_exec('DELETE FROM wa_conversations WHERE id = ?', [$staleId]);
        }
    }
    db_exec("DELETE FROM wa_notes WHERE conversation_id IN ($in)", $ids);
    db_exec("DELETE FROM wa_agent_activity WHERE conversation_id IN ($in)", $ids);

    /* ---------------------------------------------------------------- tags used by the inbox demo */
    $tagColors = ['Hot lead' => 'red', 'Scholarship query' => 'violet', 'Hostel' => 'cyan', 'Fee query' => 'amber', 'Documents pending' => 'orange', 'Callback requested' => 'blue', 'Campus visit' => 'green', 'Hindi speaker' => 'pink'];
    foreach ($tagColors as $name => $color) {
        db_exec('INSERT IGNORE INTO wa_tags (name, color) VALUES (?, ?)', [$name, $color]);
    }
    $tagIds = db_pairs('SELECT name, id FROM wa_tags WHERE name IN (' . implode(',', array_fill(0, count($tagColors), '?')) . ')', array_keys($tagColors));

    /* ---------------------------------------------------------------- templates (link when the templates part seeded them) */
    $tplId = function (string $name): ?int {
        $id = db_value("SELECT id FROM wa_templates WHERE name = ? ORDER BY (language = 'en') DESC, (status = 'approved') DESC, id LIMIT 1", [$name]);
        return $id ? (int) $id : null;
    };

    /* ---------------------------------------------------------------- thread scripts
     * step: [dir, gap minutes, type, body, extra]; sender 'contact' for in, else bot|agent|automation|followup|campaign|event.
     * {name} {first} {program} {app} {agent} {fee} replaced per contact. */
    $doc = fn (string $file, string $label) => ['media' => 'assets/uploads/media/documents/' . $file, 'file' => $label, 'mime' => 'application/pdf'];
    $img = fn (string $file) => ['media' => 'assets/images/site/' . $file, 'mime' => 'image/jpeg', 'file' => $file];
    $tpl = fn (string $name, string $body, array $vars, array $buttons = [], string $footer = 'GIMT Admissions') => [
        'template' => $name, 'template_body' => $body, 'vars' => $vars, 'buttons' => $buttons, 'footer' => $footer,
    ];

    $scripts = [
        'lead' => [
            ['tags' => ['Fee query', 'Scholarship query'], 'status' => 'open', 'agent' => true, 'steps' => [
                ['in', 0, 'text', 'Hi, I want to know the fee structure for {program} 2026-27'],
                ['out', 1, 'text', "Hello {first}! 👋 Thanks for contacting GIMT. {fee_line} Would you like the detailed fee structure PDF?", ['sender' => 'bot']],
                ['in', 3, 'text', 'Yes please'],
                ['out', 1, 'document', 'Fee structure 2026-27', ['sender' => 'bot'] + $doc('fee-structure-2026-27.pdf', 'GIMT-Fee-Structure-2026-27.pdf')],
                ['in', 6, 'text', 'Is there any scholarship for 87% in 12th?'],
                ['out', 9, 'text', "Hi {first}, this is {agent} from GIMT Admissions. {merit_reply}"],
                ['in', 4, 'text', 'Great! How do I apply?'],
                ['out', 2, 'text', "You can apply online here: {site}/apply — it takes about 10 minutes. Keep your Class 10/12 marksheets and a photo ready. I'll be here if you get stuck 🙂"],
                ['in', 18, 'text', 'Filled the form, payment page pe atak gaya'],
            ]],
            ['tags' => ['Hostel', 'Hindi speaker', 'Campus visit'], 'status' => 'open', 'agent' => true, 'steps' => [
                ['in', 0, 'text', 'Sir {program} mein admission chahiye, last date kya hai?'],
                ['out', 7, 'text', "Namaste {first} ji 🙏 {program} 2026-27 ke liye applications abhi open hain. Aap online apply kar sakte hain: {site}/apply"],
                ['in', 5, 'text', 'Girls ke liye hostel facility hai kya?'],
                ['out', 3, 'text', "{girls_hostel_hi}"],
                ['in', 12, 'text', 'Ok thank you. Kal papa ke saath campus visit karenge'],
                ['out', 2, 'text', "Zaroor! Campus visit timing 10 AM – 4 PM (Mon–Sat). Admission Cell mein aaiye — main aapke liye counsellor arrange kar deti hoon 🙂"],
                ['out', 1, 'text', "{location_msg}"],
                ['in', 3, 'text', '👍'],
            ]],
            ['tags' => ['Campus visit', 'Hot lead'], 'status' => 'pending', 'agent' => true, 'steps' => [
                ['in', 0, 'text', 'Hello, can I talk to a counsellor about {program}?'],
                ['out', 1, 'interactive_button', "Hi {first}! Would you like to book a free counselling session with our admission team?", ['sender' => 'bot', 'interactive' => ['buttons' => [['id' => 'cs_book', 'title' => 'Book a slot'], ['id' => 'cs_call', 'title' => 'Call me later'], ['id' => 'cs_no', 'title' => 'Not now']], 'footer' => 'GIMT Admissions']]],
                ['in', 2, 'button_reply', 'Book a slot', ['interactive' => ['reply' => ['id' => 'cs_book', 'title' => 'Book a slot']]]],
                ['out', 1, 'interactive_list', 'Please choose a convenient slot for your counselling session.', ['sender' => 'bot', 'interactive' => ['button' => 'View slots', 'sections' => [
                    ['title' => 'Saturday', 'rows' => [['id' => 'sat_11', 'title' => 'Sat 11:00 AM', 'description' => 'Admission Cell, Block A'], ['id' => 'sat_15', 'title' => 'Sat 3:00 PM', 'description' => 'Admission Cell, Block A']]],
                    ['title' => 'Online', 'rows' => [['id' => 'zoom_mon', 'title' => 'Mon 5:00 PM (video)', 'description' => 'Google Meet link on WhatsApp']]],
                ]]]],
                ['in', 4, 'list_reply', 'Sat 11:00 AM', ['interactive' => ['reply' => ['id' => 'sat_11', 'title' => 'Sat 11:00 AM', 'description' => 'Admission Cell, Block A']]]],
                ['out', 15, 'text', "Hi {first}, I'm {agent} 👋 Your counselling session is confirmed for *Saturday, 11:00 AM* at the Admission Cell, Block A. Please bring your Class 12 marksheet (or the latest result)."],
                ['in', 6, 'text', 'Thank you ma\'am. Will my parents be allowed to join?'],
                ['out', 3, 'text', 'Of course — parents are most welcome. See you on Saturday!'],
            ]],
            ['tags' => [], 'status' => 'open', 'agent' => false, 'bot' => true, 'steps' => [
                ['in', 0, 'text', 'What are the hostel charges?'],
                // what the real bot answers from the hostels table ({bot_hostel}, see "build" below)
                ['out', 1, 'text', '{bot_hostel}', ['sender' => 'bot']],
                ['in', 2, 'text', 'How do I apply?'],
                ['out', 1, 'text', "Admission steps:\n1️⃣ Apply online — {site}/apply\n2️⃣ Upload documents\n3️⃣ Counselling / interview\n4️⃣ Pay the admission fee to confirm your seat\nShall I connect you with a counsellor?", ['sender' => 'bot']],
                ['in', 4, 'text', 'Ok I will apply first'],
            ]],
            ['tags' => ['Hot lead'], 'status' => 'open', 'agent' => false, 'steps' => [
                ['out', 0, 'template', null, ['sender' => 'automation'] + $tpl('enquiry_received', "Hi {name}, thank you for your interest in *{program}* at GIMT! 🎓\nOur admission counsellor will call you shortly. Meanwhile, explore the programme: https://gimtedu.com/programs", ['{name}', '{program}'], [['type' => 'QUICK_REPLY', 'text' => 'Call me now'], ['type' => 'QUICK_REPLY', 'text' => 'Send brochure']])],
                ['in', 35, 'text', 'Send brochure'],
                ['out', 1, 'document', 'GIMT Prospectus 2026-27', ['sender' => 'bot'] + $doc('gimt-prospectus-2026-27.pdf', 'GIMT-Prospectus-2026-27.pdf')],
                ['in', 20, 'text', 'Is the course AICTE approved? And what about placements?'],
            ]],
            ['tags' => ['Callback requested'], 'status' => 'pending', 'agent' => true, 'steps' => [
                ['out', 0, 'template', null, ['sender' => 'followup'] + $tpl('admission_deadline_reminder', "Hello {name}, we noticed you started exploring *{program}* at GIMT. Seats for 2026-27 are filling fast — would you like help completing your application?", ['{name}', '{program}'], [['type' => 'QUICK_REPLY', 'text' => 'Yes, help me'], ['type' => 'QUICK_REPLY', 'text' => 'Not interested']])],
                ['in', 180, 'text', 'Yes, help me'],
                ['out', 25, 'text', "Hi {first}, {agent} here from GIMT Admissions. Happy to help! What's the best time for a quick call today?"],
                ['in', 10, 'text', 'After 6 pm please, I am in college till 5'],
                ['out', 2, 'text', 'Noted ✅ I will call you at 6:30 PM today.'],
            ]],
            ['tags' => ['Scholarship query'], 'status' => 'resolved', 'agent' => true, 'steps' => [
                ['in', 0, 'text', 'Good evening. Do you have any sports scholarship? I played state level cricket'],
                ['out', 14, 'text', "Good evening {first}! {sports_offer} Please share your sports certificate along with the application and the committee will review it."],
                ['in', 8, 'image', 'State level certificate', $img('campus-library.jpg')],
                ['out', 5, 'text', "Thank you {first}! I've forwarded it to the scholarship committee. You'll hear from us within 3 working days."],
                ['out', 2880, 'text', "Good news {first} 🎉 {sports_approved} Complete your application to lock it in: {site}/apply"],
                ['in', 40, 'text', 'Thank you so much!! 🙏'],
                ['out', 3, 'text', 'Congratulations once again! 😊'],
            ]],
            ['tags' => ['Hindi speaker'], 'status' => 'resolved', 'agent' => true, 'steps' => [
                ['in', 0, 'text', 'नमस्ते, क्या {program} में लेटरल एंट्री होती है?'],
                ['out', 11, 'text', "नमस्ते {first} जी 🙏 हाँ, डिप्लोमा धारकों के लिए दूसरे वर्ष में लेटरल एंट्री उपलब्ध है। सीटें सीमित हैं, इसलिए जल्दी आवेदन करें।"],
                ['in', 6, 'text', 'फीस कितनी है और आवेदन कैसे करें?'],
                ['out', 4, 'text', "{fee_hi} ऑनलाइन आवेदन करें: {site}/apply"],
                ['in', 3, 'text', 'धन्यवाद'],
            ]],
        ],
        'applicant' => [
            ['tags' => ['Documents pending'], 'status' => 'pending', 'agent' => true, 'steps' => [
                ['out', 0, 'template', null, ['sender' => 'event'] + $tpl('documents_pending', "Dear {name}, your application *{app}* for {program} is incomplete. Pending: Class 12 marksheet, ID proof.\nUpload here: https://gimtedu.com/apply/status", ['{name}', '{app}', '{program}'], [['type' => 'URL', 'text' => 'Upload documents', 'url' => 'https://gimtedu.com/apply/status']])],
                ['in', 95, 'image', 'Here is my 12th marksheet', $img('campus-academic-block.jpg')],
                ['in', 1, 'text', 'Aadhaar upload karne mein error aa raha hai'],
                ['out', 12, 'text', "Thanks {first}! Marksheet received ✅ For Aadhaar, please upload a PDF or JPG under 2 MB. You can also send it here and I'll attach it to your application {app}."],
                ['in', 7, 'document', 'Aadhaar', ['media' => 'assets/uploads/notices/seed/anti-ragging-helplines.pdf', 'file' => 'aadhaar_card.pdf', 'mime' => 'application/pdf']],
                ['out', 4, 'text', 'Received. Our team will verify your documents within 24 hours.'],
            ]],
            ['tags' => ['Fee query'], 'status' => 'open', 'agent' => true, 'failed' => true, 'steps' => [
                ['in', 0, 'text', 'I paid the admission fee via UPI but did not get any receipt. Application {app}'],
                ['out', 6, 'text', 'Sorry for the trouble, {first}. Could you please share the UPI transaction reference number?'],
                ['in', 9, 'text', 'UPI Ref No. 431287651290, amount 25000'],
                ['out', 22, 'text', "Thanks! I've checked with accounts — payment verified ✅ Your receipt has been emailed and your seat in {program} is confirmed. Welcome to GIMT! 🎓"],
                ['out', 1500, 'text', 'Hi {first}, orientation for the 2026-27 batch is on Monday at 9:30 AM in the main auditorium. Please carry your fee receipt and ID card.', ['status' => 'failed', 'error_code' => '131047', 'error' => 'Re-engagement message: more than 24 hours have passed since the customer last replied.']],
                ['in', 60, 'text', 'Hello? Any update about orientation date?'],
            ]],
            ['tags' => ['Hindi speaker'], 'status' => 'resolved', 'agent' => true, 'steps' => [
                ['in', 0, 'text', 'Mera application status kya hai? {app}'],
                ['out', 16, 'text', "Namaste {first}! Aapka application {app} abhi *document verification* stage mein hai. 1-2 din mein verification complete ho jayega."],
                ['in', 5, 'text', 'Interview kab hoga?'],
                ['out', 3, 'text', "Verification ke baad interview ki date SMS aur WhatsApp par bhej di jayegi. Usually 3-4 din mein schedule hota hai."],
                ['in', 2, 'text', 'Ok thank you'],
                ['out', 1, 'text', 'Welcome! All the best 👍'],
            ]],
            ['tags' => [], 'status' => 'open', 'agent' => true, 'steps' => [
                ['out', 0, 'template', null, ['sender' => 'event'] + $tpl('counselling_scheduled', "Dear {name}, your personal interview for *{program}* (application {app}) is scheduled on the date shown in your portal at the GIMT campus, Block A.\nPlease carry original documents.", ['{name}', '{program}', '{app}'], [['type' => 'QUICK_REPLY', 'text' => 'Confirm'], ['type' => 'QUICK_REPLY', 'text' => 'Reschedule']])],
                ['in', 50, 'text', 'Reschedule'],
                ['out', 8, 'text', 'Sure {first}. Which day works for you this week? We have slots on Thursday and Friday between 10 AM and 3 PM.'],
                ['in', 15, 'text', 'Friday 12 pm is fine'],
            ]],
            ['tags' => ['Fee query'], 'status' => 'open', 'agent' => false, 'steps' => [
                ['out', 0, 'template', null, ['sender' => 'followup'] + $tpl('admission_approved', "Hi {name}, congratulations! Your admission to *{program}* is approved 🎉 Pay the admission fee shown in your application to confirm your seat.", ['{name}', '{program}'], [['type' => 'URL', 'text' => 'Open application', 'url' => 'https://gimtedu.com/apply']], 'GIMT Accounts')],
                ['in', 240, 'text', 'Can I pay the admission fee in 2 parts?'],
                ['in', 2, 'text', 'Abhi 15000 aur baaki next month'],
            ]],
        ],
        'student' => [
            ['tags' => [], 'status' => 'resolved', 'agent' => true, 'steps' => [
                ['in', 0, 'text', 'When will the mid-sem exam timetable come?'],
                ['out', 18, 'text', "Hi {first}, mid-semester exams start on the 24th. The timetable will be published on the student portal by Friday."],
                ['in', 3, 'text', 'ok thanks'],
            ]],
            ['tags' => ['Fee query'], 'status' => 'pending', 'agent' => true, 'steps' => [
                ['out', 0, 'template', null, ['sender' => 'event'] + $tpl('fee_due_reminder', "Dear {name}, a fee instalment is due. Please check the amount and due date in the student portal → Fees.", ['{name}'], [['type' => 'URL', 'text' => 'Pay fees', 'url' => 'https://gimtedu.com/student-portal']], 'GIMT Accounts')],
                ['in', 120, 'text', 'Can I pay in two instalments?'],
                ['out', 30, 'text', "Hi {first}, yes — you can request an instalment plan. Please visit the accounts office (Block B, ground floor) with a written request signed by your parent."],
                ['in', 6, 'text', 'Ok, will visit tomorrow'],
            ]],
            ['tags' => [], 'status' => 'open', 'agent' => true, 'steps' => [
                ['in', 0, 'text', 'Admit card download nahi ho raha portal pe'],
                ['out', 9, 'text', "Hi {first}, please clear your browser cache and log in again from Chrome. If the fees are pending the admit card stays on hold — let me check for you."],
                ['out', 6, 'document', 'Your admit card', $doc('academic-calendar-2026-27.pdf', 'Admit-Card-MidSem.pdf')],
                ['in', 4, 'text', 'Got it, thank you sir 🙏'],
            ]],
            ['tags' => [], 'status' => 'open', 'agent' => true, 'steps' => [
                ['in', 0, 'text', 'Bus route for Sector 62 Noida?'],
                // what the real bot answers from transport_routes ({bot_bus}); the transport desk then names the exact stop
                ['out', 1, 'text', '{bot_bus}', ['sender' => 'bot']],
                ['out', 6, 'text', "{bus_reply}"],
                ['in', 3, 'text', 'What time does it leave college in evening?'],
            ]],
            ['tags' => [], 'status' => 'resolved', 'agent' => true, 'steps' => [
                ['out', 0, 'template', null, ['sender' => 'campaign'] + $tpl('event_invitation', "Hi {name}! 🎉 *TechFest 2026* is here — hackathon, robotics and coding contests. Register by Friday: https://gimtedu.com/events", ['{name}'], [], 'GIMT Student Affairs')],
                ['in', 300, 'text', 'Can 2nd year students participate in hackathon?'],
                ['out', 20, 'text', 'Yes {first}, the hackathon is open for all years. Teams of 2-4 members.'],
                ['in', 5, 'text', '👍👍'],
            ]],
        ],
        'guardian' => [
            ['tags' => [], 'status' => 'resolved', 'agent' => true, 'steps' => [
                ['in', 0, 'text', 'Good morning. My ward was absent yesterday due to fever. Please inform the class coordinator.'],
                ['out', 21, 'text', 'Good morning. Noted — we have informed the class coordinator. Please submit a medical certificate within 3 days through the student portal or at the department office.'],
                ['in', 4, 'text', 'Thank you'],
            ]],
            ['tags' => ['Fee query'], 'status' => 'open', 'agent' => true, 'steps' => [
                ['in', 0, 'text', 'Namaste, fees ki receipt chahiye income tax ke liye. Kaise milegi?'],
                ['out', 13, 'text', 'Namaste ji 🙏 Saari receipts student portal → Fees → Receipts mein download ho jati hain. Main aapko latest receipt yahan bhej deti hoon.'],
                ['out', 2, 'document', 'Fee receipt', $doc('fee-structure-2026-27.pdf', 'Fee-Receipt-2026-27.pdf')],
                ['in', 30, 'text', 'Dhanyavaad. Pichle saal ki bhi mil sakti hai?'],
            ]],
        ],
    ];

    /* ---------------------------------------------------------------- build */
    // texts that depend only on ERP facts (see "verified facts" above)
    $meritReply = $merit80
        ? 'Yes — with 87% in Class 12 you are eligible for the *' . $merit80['name'] . '*: ' . $schShort($merit80) . '.' . $renewal($merit80)
        : 'Yes — merit scholarships are available. I will check your eligibility with the scholarship committee and get back to you.';
    $sportsOffer = $sports
        ? 'Yes — the *' . $sports['name'] . '* gives ' . $schShort($sports) . ' to state / national level players, verified by the sports committee.'
        : 'Yes — sports achievers can apply for a scholarship; the sports committee reviews each case.';
    $sportsApproved = $sports
        ? 'The committee has approved the *' . $sports['name'] . '* for you — ' . $schShort($sports) . '.'
        : 'The committee has approved your sports scholarship.';
    $girlsHostelHi = $girls['nonac']
        ? 'Ji haan, campus mein alag girls hostel hai — 24×7 security aur mess ke saath. Double room: non-AC ' . $inr($girls['nonac']) . '/year'
            . ($girls['ac'] ? ', AC ' . $inr($girls['ac']) . '/year' : '') . ($girls['mess'] ? '; mess ' . $inr($girls['mess']) . '/year alag se.' : '.')
        : 'Ji haan, campus mein alag girls hostel hai — 24×7 security aur mess ke saath. Room charges main aapko abhi bhej deti hoon.';
    $locationMsg = $address !== '' ? "📍 *Location: GIMT*\n$address\n$mapsLink" : "📍 Our address and map: $site/contact";
    $busReply = $sector62
        ? '🚌 Hi {first}, ' . $sector62['code'] . ' (' . $sector62['route_name'] . ') picks up at *' . $sector62['stop_name'] . '* at ' . $clock($sector62['pickup_time'])
            . '. Annual transport fee for this stop: ' . $inr($sector62['fare']) . '. Need anything else?'
        : 'Hi {first}, our transport desk will share the Sector 62 pickup point and its fee with you shortly.';
    // bot lines come from the real answer engine (verified hostels / transport tables, no AI call), so the demo inbox
    // shows exactly what the live bot says; a hand-over text only when the engine is missing or cannot answer
    $botSays = function (string $question, string $fallback): string {
        if (function_exists('wa_bot_answer')) {
            try {
                $a = wa_bot_answer($question, [], ['no_ai' => true, 'test' => true]);
                if (!empty($a['answered']) && trim((string) ($a['reply'] ?? '')) !== '') {
                    return (string) $a['reply'];
                }
            } catch (Throwable $e) {
                // fall back to the hand-over text
            }
        }
        return $fallback;
    };
    $botHostel = $botSays('What are the hostel charges?', "Hello! 🏠 GIMT has separate hostels for boys and girls. I can only share verified information — our admission team will send you the current hostel charges. Would you like to know about the admission process meanwhile?");
    $botBus = $botSays('Bus route for Sector 62 Noida?', "I can only share verified information. I'm connecting you with our transport desk 🙂");
    $messages = [];
    $convUpdates = [];
    $notes = [];
    $activity = [];
    $seq = 0;
    $openWindowSlots = 14;
    $noteTexts = [
        'Spoke on call — parents want a campus visit before deciding.', 'Interested in hostel; asked for AC room availability.',
        'Strong profile (87% in 12th). Push for early application.', 'Prefers Hindi. Father handles fee decisions.',
        'Asked about education loan — share bank tie-up details.', 'Will apply after board result re-evaluation.',
        'Fee instalment request forwarded to accounts (Ravi).', 'Documents verified except ID proof.',
        'Callback promised at 6:30 PM.', 'Comparing with another college — highlight placements and scholarship.',
    ];
    $typeOrder = ['lead', 'applicant', 'student', 'guardian'];
    $counters = array_fill_keys($typeOrder, 0);

    foreach ($chosen as $i => $c) {
        $kind = $c['kind'];
        $pool = $scripts[$kind];
        $script = $pool[$counters[$kind]++ % count($pool)];
        $convId = $convIds[(int) $c['id']];
        $isOpenWindow = $openWindowSlots > 0 && $i % 4 !== 3 && !in_array($script['status'], ['resolved'], true) && ($i % 2 === 0 || $kind === 'lead');
        $agentPool = in_array($kind, ['lead', 'applicant'], true) ? $admissionTeam : array_merge($officeTeam, $admissionTeam);
        $agent = $agentPool[$i % count($agentPool)];
        $name = trim((string) $c['name']) ?: 'Student';
        $first = explode(' ', $name)[0];
        $program = $c['program_name'] ?: demo_pick(['BCA', 'BBA', 'MBA', 'B.Tech CSE']);
        // tuition only from programs.fee_per_year of the contact's programme — never a made-up figure
        $feeValue = $programFees[(int) ($c['program_id'] ?? 0)] ?? ($programFeesByName[(string) $c['program_name']] ?? null);
        $feeLine = $feeValue
            ? 'The tuition fee for ' . $program . ' is ' . $inr($feeValue) . ' per year.'
            : 'Our counsellor will confirm the exact fee for ' . $program . '.';
        $feeHi = $feeValue
            ? $program . ' की सालाना ट्यूशन फीस ' . $inr($feeValue) . ' है।'
            : 'फीस की पूरी जानकारी हमारी काउंसलर कॉल पर देंगी।';
        $repl = [
            '{fee_line}' => $feeLine, '{fee_hi}' => $feeHi, '{merit_reply}' => $meritReply, '{sports_offer}' => $sportsOffer,
            '{sports_approved}' => $sportsApproved, '{girls_hostel_hi}' => $girlsHostelHi, '{location_msg}' => $locationMsg,
            '{bus_reply}' => $busReply, '{site}' => $site, '{bot_hostel}' => $botHostel, '{bot_bus}' => $botBus,
        ];
        // second pass: placeholders that the texts above may contain
        $repl2 = [
            '{name}' => $name, '{first}' => $first, '{program}' => $program, '{agent}' => explode(' ', $agent['name'])[0],
            '{app}' => $c['application_no'] ?: 'APP/2026-27/' . str_pad((string) (300 + $i), 5, '0', STR_PAD_LEFT),
        ];
        $fill = fn (?string $s) => $s === null ? null : strtr(strtr($s, $repl), $repl2);

        $steps = $script['steps'];
        if ((int) ($c['opt_in'] ?? 0) !== 1) {
            // campaigns reach only opted-in contacts (wa_cmp_skip_reason): without an opt-in the chat starts with the
            // student's own question instead of a campaign broadcast
            $steps = array_values(array_filter($steps, fn ($s) => ($s[4]['sender'] ?? null) !== 'campaign'));
        }
        $total = array_sum(array_map(fn ($s) => (int) $s[1], $steps));
        if ($isOpenWindow) {
            $openWindowSlots--;
            $end = $now - mt_rand(4, 18 * 60) * 60;
            if ($steps[count($steps) - 1][0] !== 'in') {
                $end = $now - mt_rand(4, 8 * 60) * 60;
            }
        } else {
            $end = $now - mt_rand(27 * 60, 13 * 24 * 60) * 60;
        }
        $t = $end - $total * 60 - mt_rand(0, 59);
        $agentTook = false;
        $botWasActive = false;
        $firstAgentAt = null;
        $lastIn = null;
        $lastOut = null;
        $trailingIn = 0;
        $stepCount = count($steps);
        foreach ($steps as $si => $s) {
            [$dir, $gap, $type, $body] = $s;
            $extra = $s[4] ?? [];
            $t += (int) $gap * 60 + ($si ? mt_rand(5, 50) : 0);
            if ($t > $now - 60) {
                $t = $now - 60 - ($stepCount - $si) * 30;
            }
            $sender = $dir === 'in' ? 'contact' : ($extra['sender'] ?? 'agent');
            if ($sender === 'bot') {
                $botWasActive = true;
            }
            if ($sender === 'agent') {
                $agentTook = true;
                $firstAgentAt = $firstAgentAt ?? $t;
            }
            $hasLaterIn = false;
            for ($j = $si + 1; $j < $stepCount; $j++) {
                if ($steps[$j][0] === 'in') {
                    $hasLaterIn = true;
                    break;
                }
            }
            $status = $dir === 'in' ? 'received' : ($extra['status'] ?? ($hasLaterIn ? 'read' : demo_pick(['read', 'read', 'delivered', 'delivered', 'sent'])));
            $interactive = $extra['interactive'] ?? null;
            $templateName = null;
            $templateVars = null;
            $templateId = null;
            if ($type === 'template') {
                $templateName = $extra['template'];
                $templateId = $tplId($templateName);
                $real = $templateId && function_exists('wa_template_find') ? wa_template_find($templateId) : null;
                $vars = [];
                if ($real) {
                    $vars = function_exists('wa_variables_for_contact') ? wa_variables_for_contact(wa_contact((int) $c['id']) ?? []) : [];
                    // real values the CRM row does not carry
                    $deadline = (string) setting('admissions_deadline', '');
                    if (empty($vars['last_date']) && $deadline !== '' && strtotime($deadline)) {
                        $vars['last_date'] = date('d M Y', strtotime($deadline));
                    }
                    if (empty($vars['fee_amount']) && !empty($vars['due_amount'])) {
                        $vars['fee_amount'] = $vars['due_amount']; // the student's real outstanding balance (student_fees)
                    }
                    if (empty($vars['payment_link'])) {
                        $vars['payment_link'] = $site . '/student-portal'; // students pay from the portal (no invented pay link)
                    }
                    if (empty($vars['fee_amount']) && !empty($c['admission_id'])) {
                        $admFee = (float) db_value('SELECT admission_fee FROM admissions WHERE id = ?', [(int) $c['admission_id']]);
                        if ($admFee > 0) {
                            $vars['fee_amount'] = $inr($admFee);
                        }
                    }
                    // a sample must never stand in for money: without a real amount use the script's amount-free text
                    $slotKeys = [];
                    foreach (['header', 'body'] as $sec) {
                        foreach ((array) ($real['variables'][$sec] ?? []) as $ref) {
                            $slotKeys[] = is_array($ref) ? (string) ($ref['key'] ?? '') : '';
                        }
                    }
                    foreach (['fee_amount', 'refund_amount', 'amount'] as $moneyKey) {
                        if (in_array($moneyKey, $slotKeys, true) && empty($vars[$moneyKey])) {
                            $real = null;
                            $templateId = null;
                            break;
                        }
                    }
                }
                if ($real && function_exists('wa_render_template')) {
                    // render the real library template with the contact's CRM values (samples fill non-money gaps)
                    $vars += ['_use_samples' => true];
                    $r = wa_render_template($real, $vars);
                    $body = $r['text'];
                    $templateVars = ['header' => $r['header'], 'body' => $r['body'], 'buttons' => (object) $r['buttons'], 'language' => $real['language'], 'category' => $real['category']];
                    $interactive = ['template' => ['header_type' => $real['header_type'], 'header_text' => $r['header_text'], 'header_media_url' => $r['media_url'], 'file_name' => $r['file_name'],
                        'body' => $r['text'], 'footer' => $real['footer'], 'buttons' => $real['buttons'] ?: null]];
                } else {
                    $rendered = $fill($extra['template_body']);
                    $body = $rendered;
                    $templateVars = ['body' => array_map($fill, $extra['vars'])];
                    $interactive = ['template' => ['header_type' => 'none', 'body' => $rendered, 'footer' => $extra['footer'] ?? null, 'buttons' => $extra['buttons'] ?: null]];
                }
            }
            $mediaUrl = !empty($extra['media']) ? base_url($extra['media']) : null;
            $seq++;
            $messages[] = [
                'conversation_id' => $convId, 'contact_id' => (int) $c['id'], 'direction' => $dir, 'type' => $type,
                'body' => $fill($body), 'media_url' => $mediaUrl, 'media_local' => null, 'media_mime' => $extra['mime'] ?? null,
                'file_name' => $extra['file'] ?? null, 'interactive' => $interactive ? json_encode($interactive, JSON_UNESCAPED_UNICODE) : null,
                'template_id' => $templateId, 'template_name' => $templateName,
                'template_vars' => $templateVars ? json_encode($templateVars, JSON_UNESCAPED_UNICODE) : null,
                'interakt_id' => 'seed-inbox-' . $convId . '-' . ($si + 1), 'callback_data' => 'seed:inbox', 'status' => $status,
                'error_code' => $extra['error_code'] ?? null, 'error' => $extra['error'] ?? null,
                'cost' => $dir === 'out' && $type === 'template' ? '0.1150' : null,
                'sender_type' => $sender, 'sent_by' => $sender === 'agent' ? $agent['id'] : null,
                'created_at' => $fmt($t),
                'sent_at' => $dir === 'out' && $status !== 'failed' ? $fmt($t + 2) : null,
                'delivered_at' => $dir === 'out' && in_array($status, ['delivered', 'read'], true) ? $fmt($t + 5) : null,
                'read_at' => $dir === 'out' && $status === 'read' ? $fmt($t + mt_rand(60, 900)) : null,
                'failed_at' => $status === 'failed' ? $fmt($t + 3) : null,
            ];
            if ($dir === 'in') {
                $lastIn = $t;
                $trailingIn++;
            } else {
                $lastOut = $t;
                $trailingIn = 0;
                if ($sender === 'agent') {
                    $activity[] = ['user_id' => $agent['id'], 'conversation_id' => $convId, 'contact_id' => (int) $c['id'], 'action' => $type === 'template' ? 'template' : 'reply', 'detail' => 'Replied (' . str_replace('_', ' ', $type) . ')', 'created_at' => $fmt($t)];
                }
            }
        }
        $lastAt = max($lastIn ?? 0, $lastOut ?? 0);
        $status = $script['status'];
        if ($status === 'resolved' && $isOpenWindow) {
            $status = 'open';
        }
        $assigned = null;
        $botActive = !empty($script['bot']) || (!$agentTook && $botWasActive);
        $handoverAt = null;
        if ($agentTook) {
            $assigned = $agent['id'];
            $activity[] = ['user_id' => $agent['id'], 'conversation_id' => $convId, 'contact_id' => (int) $c['id'], 'action' => 'assign', 'detail' => $i % 3 === 0 ? 'Claimed the conversation' : 'Assigned to ' . $agent['name'], 'created_at' => $fmt($firstAgentAt - 90)];
            if ($botWasActive) {
                $handoverAt = $firstAgentAt - 60;
                $activity[] = ['user_id' => $agent['id'], 'conversation_id' => $convId, 'contact_id' => (int) $c['id'], 'action' => 'handover', 'detail' => 'Took over from the AI bot', 'created_at' => $fmt($handoverAt)];
            }
            $botActive = false;
        } elseif ($i % 5 === 1 && !$botActive) {
            $assigned = $agent['id'];
            $activity[] = ['user_id' => $agent['id'], 'conversation_id' => $convId, 'contact_id' => (int) $c['id'], 'action' => 'assign', 'detail' => 'Assigned to ' . $agent['name'], 'created_at' => $fmt($lastAt + 120 > $now ? $now - 30 : $lastAt + 120)];
        }
        $unread = 0;
        if ($status !== 'resolved' && $trailingIn > 0 && ($i % 3 !== 2 || !$agentTook)) {
            $unread = $trailingIn;
        }
        $resolvedAt = null;
        if ($status === 'resolved') {
            $resolvedAt = $lastAt + mt_rand(5, 120) * 60;
            if ($resolvedAt > $now) {
                $resolvedAt = $now - 60;
            }
            $activity[] = ['user_id' => $assigned ?? $agent['id'], 'conversation_id' => $convId, 'contact_id' => (int) $c['id'], 'action' => 'resolve', 'detail' => 'Marked Resolved', 'created_at' => $fmt($resolvedAt)];
        } elseif ($status === 'pending' && $agentTook) {
            $activity[] = ['user_id' => $agent['id'], 'conversation_id' => $convId, 'contact_id' => (int) $c['id'], 'action' => 'status', 'detail' => 'Marked Pending', 'created_at' => $fmt(min($now - 30, $lastAt + 300))];
        }
        if ($agentTook && $i % 2 === 0) {
            $nt = min($now - 45, $lastAt + mt_rand(3, 90) * 60);
            $text = $noteTexts[$i % count($noteTexts)];
            $notes[] = ['conversation_id' => $convId, 'contact_id' => (int) $c['id'], 'user_id' => $agent['id'], 'body' => $text, 'created_at' => $fmt($nt)];
            $activity[] = ['user_id' => $agent['id'], 'conversation_id' => $convId, 'contact_id' => (int) $c['id'], 'action' => 'note', 'detail' => mb_substr('Added a note: ' . $text, 0, 255), 'created_at' => $fmt($nt)];
            if ($i % 4 === 0) {
                $other = $agentPool[($i + 1) % count($agentPool)];
                $nt2 = min($now - 20, $nt + mt_rand(30, 600) * 60);
                $text2 = $noteTexts[($i + 3) % count($noteTexts)];
                $notes[] = ['conversation_id' => $convId, 'contact_id' => (int) $c['id'], 'user_id' => $other['id'], 'body' => $text2, 'created_at' => $fmt($nt2)];
                $activity[] = ['user_id' => $other['id'], 'conversation_id' => $convId, 'contact_id' => (int) $c['id'], 'action' => 'note', 'detail' => mb_substr('Added a note: ' . $text2, 0, 255), 'created_at' => $fmt($nt2)];
            }
        }
        foreach ($script['tags'] as $tagName) {
            if (isset($tagIds[$tagName])) {
                db_exec('INSERT IGNORE INTO wa_contact_tags (contact_id, tag_id, created_at) VALUES (?, ?, ?)', [(int) $c['id'], (int) $tagIds[$tagName], $fmt($lastAt)]);
            }
        }
        $convUpdates[$convId] = [
            'status' => $status, 'assigned_to' => $assigned, 'unread_count' => $unread, 'bot_active' => $botActive ? 1 : 0,
            'bot_handover_at' => $handoverAt ? $fmt($handoverAt) : null, 'resolved_at' => $resolvedAt ? $fmt($resolvedAt) : null,
            'resolved_by' => $resolvedAt ? ($assigned ?? $agent['id']) : null,
        ];
    }

    demo_bulk_insert('wa_messages', $messages, 200);
    demo_bulk_insert('wa_notes', $notes);
    usort($activity, fn ($a, $b) => strcmp($a['created_at'], $b['created_at']));
    demo_bulk_insert('wa_agent_activity', $activity);

    /* ---------------------------------------------------------------- denormalised conversation + contact fields */
    foreach ($convUpdates as $convId => $u) {
        $last = db_row('SELECT * FROM wa_messages WHERE conversation_id = ? ORDER BY created_at DESC, id DESC LIMIT 1', [$convId]);
        $first = db_value('SELECT MIN(created_at) FROM wa_messages WHERE conversation_id = ?', [$convId]);
        $lastIn = db_value("SELECT MAX(created_at) FROM wa_messages WHERE conversation_id = ? AND direction = 'in'", [$convId]);
        $preview = $last ? (string) $last['body'] : '';
        if ($last && $last['type'] === 'document') {
            $preview = '📄 ' . ($last['file_name'] ?: $preview);
        } elseif ($last && $last['type'] === 'image') {
            $preview = '📷 ' . ($preview ?: 'Photo');
        }
        $preview = mb_substr(trim(preg_replace('/\s+/', ' ', str_replace(['*', '_', '~'], '', $preview))), 0, 250);
        db_update('wa_conversations', $u + [
            'last_message_at' => $last['created_at'] ?? null, 'last_message_preview' => $preview ?: null,
            'last_message_direction' => $last['direction'] ?? null, 'last_inbound_at' => $lastIn ?: null,
            'created_at' => $first ?: date('Y-m-d H:i:s'),
        ], 'id = ?', [$convId]);
    }
    $contactIds = array_map(fn ($c) => (int) $c['id'], $chosen);
    $cin = implode(',', array_fill(0, count($contactIds), '?'));
    db_exec(
        "UPDATE wa_contacts k SET
            k.last_inbound_at = (SELECT MAX(m.created_at) FROM wa_messages m WHERE m.contact_id = k.id AND m.direction = 'in'),
            k.last_outbound_at = (SELECT MAX(m.created_at) FROM wa_messages m WHERE m.contact_id = k.id AND m.direction = 'out'),
            k.last_message_at = (SELECT MAX(m.created_at) FROM wa_messages m WHERE m.contact_id = k.id)
          WHERE k.id IN ($cin)",
        $contactIds
    );
};
