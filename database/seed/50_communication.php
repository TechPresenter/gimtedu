<?php
/**
 * Demo seed (Communication unit): notices & circulars, events with registrations, newsletter subscribers,
 * e-mail campaigns, extra message templates and the message history they produced (message_logs + message_queue).
 * Everything is anchored on the day the seed runs (date('Y-m-d')), so "upcoming" stays upcoming.
 *
 * Owns: notices (+ PDF attachments in assets/uploads/notices/seed/), events, event_registrations, email_campaigns, message_queue (cleared on every run),
 * demo newsletter_subscribers (token = MD5('gimt-demo:' || email)), message_logs of type notice / event /
 * event_registration / campaign / campaign_test / template_test. Templates are added with INSERT IGNORE (never
 * overwrites a code that already exists, so edits made in the UI survive).
 */
require_once __DIR__ . '/lib/demo_data.php';
require_once APP_ROOT . '/app/services/communication.php';

return function (array $opts): void {
    demo_seed(50050);
    $now = time();
    $today = date('Y-m-d', $now);
    $at = function (int $days, string $time = '10:00') use ($today): string {
        return date('Y-m-d H:i:s', strtotime($today . ' ' . $time . ' ' . ($days >= 0 ? '+' : '') . $days . ' days'));
    };
    $fmt = fn (int $days, string $f = 'd M Y') => date($f, strtotime($today . ' ' . ($days >= 0 ? '+' : '') . $days . ' days'));

    // ---------------------------------------------------------------- clean owned rows (children first)
    db_exec("DELETE FROM message_logs WHERE related_type IN ('notice', 'event', 'event_registration', 'campaign', 'campaign_test', 'template_test') OR campaign_id IS NOT NULL");
    db_exec('DELETE FROM message_queue');
    db_exec('DELETE FROM event_registrations');
    db_exec('DELETE FROM events');
    db_exec('DELETE FROM notices');
    db_exec('DELETE FROM email_campaigns');
    db_exec("DELETE FROM newsletter_subscribers WHERE token = MD5(CONCAT('gimt-demo:', email))");

    $users = db_pairs("SELECT username, id FROM users");
    $uid = fn (string $u) => isset($users[$u]) ? (int) $users[$u] : (int) ($users['admin'] ?? 1);
    $dept = db_pairs('SELECT code, id FROM departments');
    $prog = db_pairs('SELECT short_name, id FROM programs');
    $p = fn (string $short) => isset($prog[$short]) ? (int) $prog[$short] : null;
    $d = fn (string $code) => isset($dept[$code]) ? (int) $dept[$code] : null;

    // ---------------------------------------------------------------- message templates (added only when missing)
    $templates = [
        ['contact_received', 'Contact acknowledgement', 'email', 'We received your message — {{institute_name}}',
            '<p>Dear {{name}},</p><p>Thank you for writing to us about <strong>{{subject}}</strong>. Our team has received your message and will respond within one working day.</p><p>For urgent queries call us on {{phone}}.</p><p>Warm regards,<br>Admissions & Student Support<br>{{institute_name}}</p>'],
        ['enquiry_received', 'Enquiry acknowledgement', 'email', 'Thank you for your interest in {{program}}',
            '<p>Dear {{name}},</p><p>Thank you for your enquiry about <strong>{{program}}</strong> at {{institute_name}}. An admission counsellor will call you within 24 hours to guide you through eligibility, fees and scholarships.</p><p>Meanwhile, explore our programs at <a href="{{site_url}}">{{site_url}}</a>.</p>'],
        ['event_registration', 'Event registration confirmation', 'email', 'You are registered: {{event}}',
            '<p>Dear {{name}},</p><p>Your registration for <strong>{{event}}</strong> is confirmed.</p><table style="border-collapse:collapse;margin:12px 0"><tr><td style="padding:4px 12px 4px 0;color:#64748b">Date &amp; time</td><td style="padding:4px 0"><strong>{{date}}</strong></td></tr><tr><td style="padding:4px 12px 4px 0;color:#64748b">Venue</td><td style="padding:4px 0"><strong>{{venue}}</strong></td></tr></table><p>Please carry a valid ID card and report 15 minutes early.</p>'],
        ['event_reminder', 'Event reminder', 'email', 'Reminder: {{event}} on {{date}}',
            '<p>Dear {{name}},</p><p>This is a friendly reminder that you are registered for <strong>{{event}}</strong>.</p><p><strong>When:</strong> {{date}}<br><strong>Where:</strong> {{venue}}</p><p><a href="{{link}}" style="display:inline-block;background:#0B2A5B;color:#fff;padding:9px 16px;border-radius:8px;text-decoration:none">View event details</a></p><p>See you there!</p>'],
        ['account_created', 'Staff account created', 'email', 'Your {{institute_name}} SmartCampus account',
            '<p>Dear {{name}},</p><p>An account has been created for you on GIMT SmartCampus with the role <strong>{{role}}</strong>.</p><p>Username: <strong>{{username}}</strong><br>Temporary password: <strong>{{password}}</strong></p><p><a href="{{login_url}}">Sign in</a> and change your password on first login.</p>'],
        ['notice_sms', 'Notice (SMS)', 'sms', 'Notice SMS', 'GIMT Notice: {{title}}. {{summary}} Details: {{link}}'],
        ['fee_reminder_sms', 'Fee reminder (SMS)', 'sms', 'Fee reminder SMS', 'Dear {{name}}, {{amount}} towards {{title}} is due on {{due_date}}. Pay online to avoid late fee. - GIMT Accounts'],
        ['attendance_alert_whatsapp', 'Low attendance alert (WhatsApp)', 'whatsapp', 'Attendance alert', 'Dear Parent, attendance of {{name}} is {{percent}}% which is below the required {{required}}%. Please ensure regular attendance. - GIMT'],
        ['newsletter_monthly', 'Monthly newsletter layout', 'email', '{{institute_name}} Newsletter — campus highlights',
            '<h2 style="color:#0B2A5B;margin:0 0 8px">Campus highlights</h2><p>Dear {{name}},</p><p>Here is what happened at {{institute_name}} this month — events, achievements, placements and upcoming admission dates.</p><ul><li>Top story</li><li>Placements &amp; internships</li><li>Upcoming events</li></ul><p><a href="{{site_url}}" style="display:inline-block;background:#22943F;color:#fff;padding:9px 16px;border-radius:8px;text-decoration:none">Visit our website</a></p><p style="font-size:12px;color:#94a3b8">Not interested any more? <a href="{{unsubscribe_link}}">Unsubscribe</a></p>'],
        ['admission_open_campaign', 'Admissions open announcement', 'email', 'Admissions open for the 2027-28 session at {{institute_name}}',
            '<p>Dear {{name}},</p><p>Applications are now open for undergraduate, postgraduate, diploma and certificate programs for the 2027-28 session.</p><ul><li>Merit scholarships up to 50% of tuition</li><li>Industry-integrated curriculum and internships</li><li>Dedicated placement training from the first year</li></ul><p><a href="{{site_url}}apply" style="display:inline-block;background:#22943F;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">Apply now</a></p>'],
    ];
    foreach ($templates as [$code, $name, $channel, $subject, $body]) {
        $vars = implode(',', array_values(array_diff(comm_placeholders_in($subject, $body), ['institute_name', 'site_url', 'year'])));
        db_exec('INSERT IGNORE INTO email_templates (code, name, subject, body_html, variables, channel, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$code, $name, $subject, $body, $vars ?: null, $channel, 'active']);
    }

    // ---------------------------------------------------------------- notices
    $ul = fn (array $items) => '<ul>' . implode('', array_map(fn ($i) => '<li>' . $i . '</li>', $items)) . '</ul>';
    $n = [];
    // [title, category, audience, targets, publishDays|null, expiryDays|null, pinned, status, website, author, body, emailed]
    $n[] = ['Mid-Semester Examination Schedule — ' . $fmt(4, 'F Y'), 'examination', 'students', [], -6, 20, 1, 'published', 1, 'exams',
        '<p>The mid-semester examinations for all undergraduate and postgraduate programs will be held from <strong>' . $fmt(4) . '</strong> to <strong>' . $fmt(13) . '</strong> in two sessions (10:00 AM – 12:00 PM and 2:00 PM – 4:00 PM).</p>'
        . $ul(['The detailed date sheet is attached on the student portal and displayed on department notice boards.', 'Students must carry their admit card and college ID card to every paper.', 'Entry to the examination hall closes 15 minutes after the start of the paper.', 'Use of mobile phones and smart watches inside the hall is strictly prohibited.'])
        . '<p>For clashes or special requirements, contact the Examination Cell (Room A-104) before ' . $fmt(1) . '.</p>', true];
    $n[] = ['Admit Cards for Mid-Semester Examinations Available', 'examination', 'students', [], -3, 14, 0, 'published', 1, 'exams',
        '<p>Admit cards for the mid-semester examinations can now be downloaded from the student portal. Students with fee dues or attendance below 75% must meet the Examination Cell to obtain a provisional admit card.</p><p>Verify your name, roll number, program and subjects. Report discrepancies by ' . $fmt(2) . '.</p>', false];
    $n[] = ['Diwali Vacation: Campus Closed from ' . $fmt(26, 'd M') . ' to ' . $fmt(30, 'd M Y'), 'holiday', 'all', [], -4, 31, 1, 'published', 1, 'administrator',
        '<p>The institute will remain closed from <strong>' . $fmt(26) . '</strong> to <strong>' . $fmt(30) . '</strong> on account of Diwali. Classes resume on <strong>' . $fmt(31, 'l, d M Y') . '</strong> as per the regular timetable.</p>'
        . '<p>Hostels will remain open for students who wish to stay; please inform the warden by ' . $fmt(20) . '. The library and computer labs will be closed during the vacation.</p><p>Wishing everyone a safe and happy Diwali!</p>', true];
    $n[] = ['Admissions Open 2027-28: Early Bird Scholarship Window', 'admission', 'all', [], -10, 60, 0, 'published', 1, 'admissions',
        '<p>Applications for the 2027-28 session are now open for BBA, MBA, B.Tech (CSE, AI & DS), BCA, MCA, B.Com (Hons), M.Com, B.Sc Data Science and diploma programs.</p>'
        . $ul(['Early bird scholarship of up to 25% on tuition for applications completed by ' . $fmt(60) . '.', 'Merit scholarships for students scoring above 85% in the qualifying examination.', 'Sibling and single-girl-child concessions as per the scholarship policy.'])
        . '<p>Apply online or visit the Admission Cell (Ground floor, Admin Block) between 9:30 AM and 5:30 PM, Monday to Saturday.</p>', false];
    $n[] = ['Last Date for Odd Semester Fee Payment — ' . $fmt(19), 'fee', 'parents', [], -8, 19, 0, 'published', 0, 'accounts',
        '<p>Dear Parents,</p><p>The last date for payment of the odd semester tuition fee is <strong>' . $fmt(19) . '</strong>. A late fee of ₹50 per day will be charged after the due date.</p><p>Fees can be paid online through UPI, net banking or card on the student portal, or by demand draft at the Accounts Office. Please ignore this notice if the fee has already been paid.</p>', true];
    $n[] = ['Guest Lecture: Generative AI in Business Strategy', 'academic', 'course', ['program_id' => $p('MBA')], -2, 5, 0, 'published', 1, 'academics',
        '<p>The Department of Management Studies is organising a guest lecture on <strong>Generative AI in Business Strategy</strong> by a senior consultant from a leading technology advisory firm.</p><p><strong>Date:</strong> ' . $fmt(3) . ', 11:00 AM<br><strong>Venue:</strong> Seminar Hall, Management Block</p><p>Attendance is compulsory for all MBA students. Faculty members are welcome.</p>', true];
    $n[] = ['Revised Class Timetable — B.Tech CSE Semester 3', 'academic', 'semester', ['program_id' => $p('B.Tech (CSE)'), 'semester_no' => 3], -5, 40, 0, 'published', 0, 'academics',
        '<p>The class timetable for B.Tech CSE Semester 3 has been revised with effect from <strong>' . $fmt(-3, 'l, d M Y') . '</strong>. Data Structures labs move to Lab C-201 on Tuesdays and Thursdays, and the Discrete Mathematics tutorial is now on Friday, Period 6.</p><p>The updated timetable is available on the student portal.</p>', true];
    $n[] = ['Library Timings Extended During Examinations', 'general', 'students', [], -5, 25, 0, 'published', 1, 'library',
        '<p>The Central Library will remain open from <strong>8:00 AM to 10:00 PM</strong> on all working days and from 9:00 AM to 5:00 PM on Sundays during the examination period.</p><p>Reading hall seats are available on a first-come basis. Silence must be maintained at all times.</p>', false];
    $n[] = ['Campus Recruitment Drive — Registration for the 2027 Batch', 'placement', 'students', [], -1, 10, 1, 'published', 1, 'placement',
        '<p>The Training & Placement Cell invites final-year students of B.Tech, MCA, BCA, MBA and BBA to register for the upcoming campus recruitment drives.</p>'
        . $ul(['Register on the placement portal by ' . $fmt(6) . '.', 'Upload an updated resume (PDF, one page).', 'Minimum 60% aggregate with no active backlogs.', 'Pre-placement talk: ' . $fmt(9) . ' at 9:30 AM, Main Auditorium.'])
        . '<p>Students who do not register will not be allowed to appear for the drives.</p>', false];
    $n[] = ['Faculty Development Programme on Outcome-Based Education', 'academic', 'faculty', [], -12, 3, 0, 'published', 0, 'academics',
        '<p>A five-day Faculty Development Programme on <strong>Outcome-Based Education and NAAC Criterion 2</strong> will be conducted in the Conference Room, Admin Block. Sessions cover CO-PO mapping, attainment calculation and rubric design.</p><p>All teaching staff are requested to attend. Certificates will be issued on 80% attendance.</p>', true];
    $n[] = ['Submission of Internal Assessment Marks', 'examination', 'faculty', [], -7, 6, 0, 'published', 0, 'exams',
        '<p>Faculty members are requested to enter internal assessment marks for all subjects on the ERP by <strong>' . $fmt(5) . '</strong>. Marks entry will be locked after the deadline and changes will require approval from the Controller of Examinations.</p>', true];
    $n[] = ['Anti-Ragging Committee and Helpline Numbers', 'circular', 'students', [], -95, null, 1, 'published', 1, 'administrator',
        '<p>Ragging in any form is a punishable offence under the UGC Regulations. The institute follows a zero-tolerance policy.</p>'
        . $ul(['Anti-Ragging Helpline: 1800-180-5522 (24×7, toll free)', 'Institute Anti-Ragging Squad: +91 99554 46477', 'E-mail: antiragging@gimt.ac.in'])
        . '<p>Students and parents must submit the online anti-ragging undertaking at antiragging.in.</p>', false];
    $n[] = ['Holiday on Account of Gandhi Jayanti', 'holiday', 'all', [], -15, -9, 0, 'expired', 1, 'administrator',
        '<p>The institute will remain closed on <strong>2 October</strong> on account of Gandhi Jayanti. NSS volunteers will hold a cleanliness drive on campus at 8:00 AM.</p>', false];
    $n[] = ['Hostel Mess Menu Revised for the Winter Term', 'general', 'students', [], -9, 60, 0, 'published', 0, 'hostel',
        '<p>Based on student feedback, the mess menu has been revised for the winter term. Breakfast now includes a seasonal fruit counter, and Sunday dinner features a special menu.</p><p>Suggestions can be dropped in the box outside the mess office.</p>', false];
    $n[] = ['Merit-cum-Means Scholarship Applications Open', 'fee', 'students', [], -14, 16, 0, 'published', 1, 'accounts',
        '<p>Applications are invited for the Merit-cum-Means Scholarship for the 2026-27 session. Students with a family income below ₹6 lakh per annum and at least 70% marks in the previous semester are eligible.</p><p>Submit the form with income certificate and marksheets at the Accounts Office by ' . $fmt(16) . '.</p>', false];
    $n[] = ['Parent–Teacher Meeting for Semester 1 Students', 'academic', 'parents', ['program_id' => null], -2, 12, 0, 'published', 0, 'academics',
        '<p>Dear Parents,</p><p>A Parent–Teacher Meeting for Semester 1 students of all programs is scheduled on <strong>' . $fmt(11, 'l, d M Y') . '</strong> from 10:00 AM to 1:00 PM in the respective departments.</p><p>Mentors will share attendance, internal assessment performance and feedback. We look forward to meeting you.</p>', true];
    $n[] = ['Alumni Meet 2026 — Save the Date', 'event', 'alumni', [], -20, 45, 0, 'published', 1, 'alumni',
        '<p>We are delighted to invite all alumni to the <strong>GIMT Alumni Homecoming 2026</strong> on ' . $fmt(45, 'l, d M Y') . ' at the Main Auditorium.</p><p>Reconnect with classmates and faculty, meet current students and celebrate the achievements of our alumni community. Registration is open on the website.</p>', false];
    $n[] = ['Results of Supplementary Examinations Declared', 'examination', 'students', [], -25, 5, 0, 'published', 1, 'exams',
        '<p>Results of the supplementary examinations held in August are now available on the student portal. Students may apply for re-evaluation within seven days of the declaration.</p>', false];
    $n[] = ['Re-registration for Even Semester Courses', 'academic', 'students', [], 3, 30, 0, 'published', 1, 'academics',
        '<p>Re-registration for the even semester opens on <strong>' . $fmt(3) . '</strong>. Students must confirm elective choices and clear all dues before registering. Late registration attracts a fine of ₹500.</p>', false];
    $n[] = ['Workshop on Financial Modelling for MBA Students', 'academic', 'course', ['program_id' => $p('MBA')], -1, 8, 0, 'published', 1, 'academics',
        '<p>A hands-on workshop on <strong>Financial Modelling in Excel</strong> will be held in Computer Lab 2 on ' . $fmt(4) . '. Seats are limited to 50 — register on the events page.</p>', false];
    $n[] = ['Mandatory Fire Safety Drill on Campus', 'urgent', 'all', [], -1, 2, 1, 'published', 0, 'administrator',
        '<p>A fire safety evacuation drill will be conducted on <strong>' . $fmt(1, 'l, d M Y') . ' at 11:30 AM</strong> across all academic blocks.</p>'
        . $ul(['On hearing the alarm, leave the building calmly through the nearest exit.', 'Do not use lifts.', 'Assemble at the marked assembly points near the sports ground.', 'Faculty in class will take attendance at the assembly point.']), true];
    $n[] = ['Power Maintenance Shutdown — Academic Block B', 'urgent', 'staff', [], -3, -1, 0, 'expired', 0, 'administrator',
        '<p>Power supply to Academic Block B will remain suspended from 8:00 AM to 2:00 PM for transformer maintenance. Server room equipment will run on UPS.</p>', false];
    $n[] = ['Annual Sports Meet — Team Registrations Open', 'event', 'students', [], -7, 18, 0, 'published', 1, 'content',
        '<p>Registrations are open for the Annual Sports Meet. Events include cricket, football, basketball, volleyball, badminton, table tennis, chess and athletics.</p><p>Team captains must submit entries to the Sports Officer by ' . $fmt(12) . '.</p>', false];
    $n[] = ['Project Synopsis Submission — B.Tech AI & DS Semester 7', 'academic', 'semester', ['program_id' => $p('B.Tech (AI & DS)'), 'semester_no' => 7], -4, 11, 0, 'published', 0, 'academics',
        '<p>Students of B.Tech AI & DS Semester 7 must submit the major project synopsis (team of up to four) to their project guides by <strong>' . $fmt(10) . '</strong>. The synopsis presentation will be held the following week.</p>', true];
    $n[] = ['Staff Health Check-up Camp', 'general', 'staff', [], -6, 4, 0, 'published', 0, 'administrator',
        '<p>A free health check-up camp for teaching and non-teaching staff will be held in the Medical Room on ' . $fmt(3) . ' from 9:00 AM to 1:00 PM. Tests include blood sugar, BP, lipid profile and eye check-up.</p>', false];
    $n[] = ['Updated Leave Policy for Teaching Staff', 'circular', 'faculty', [], -30, null, 0, 'published', 0, 'administrator',
        '<p>The revised leave policy approved by the Governing Body is effective from this session. Casual leave increases to 12 days per year, and duty leave is available for conferences and FDPs with prior approval.</p><p>All leave applications must be submitted through the ERP.</p>', false];
    $n[] = ['Industrial Visit to Noida SEZ — BBA Semester 5', 'academic', 'semester', ['program_id' => $p('BBA'), 'semester_no' => 5], -2, 9, 0, 'published', 0, 'academics',
        '<p>An industrial visit to the Noida Special Economic Zone is organised for BBA Semester 5 students on ' . $fmt(8) . '. The bus leaves the campus at 8:30 AM. Carry your ID card and wear formals.</p>', true];
    $n[] = ['Online Fee Payment via UPI and Net Banking', 'fee', 'parents', [], -40, null, 0, 'published', 1, 'accounts',
        '<p>Parents can now pay fees online through UPI, net banking, debit and credit cards on the student portal. Receipts are generated instantly and e-mailed to the registered address.</p>', false];
    $n[] = ['Departmental Seminar Series — Department of Commerce', 'academic', 'department', ['department_id' => $d('COM')], -8, 20, 0, 'published', 0, 'academics',
        '<p>The Department of Commerce announces its monthly seminar series. This month\'s topic is <strong>GST compliance for MSMEs</strong>, presented by a practising chartered accountant.</p>', true];
    $n[] = ['Placement Training: Aptitude Practice Sessions', 'placement', 'course', ['program_id' => $p('MCA')], -5, 25, 0, 'published', 0, 'placement',
        '<p>Aptitude and reasoning practice sessions for MCA students will run every Wednesday and Friday from 3:00 PM to 5:00 PM in Lab C-105.</p>', false];
    $n[] = ['Final Merit List for Admissions 2026', 'admission', 'all', [], -100, -60, 0, 'expired', 1, 'admissions',
        '<p>The final merit list for admissions to the 2026-27 session has been published. Selected candidates must complete fee payment and document verification within seven days.</p>', false];
    $n[] = ['Orientation Programme for New Students', 'admission', 'students', [], -95, -85, 0, 'expired', 1, 'academics',
        '<p>The orientation programme for newly admitted students will be held in the Main Auditorium. Attendance is mandatory.</p>', false];
    $n[] = ['Guidelines for Use of Computer Labs', 'circular', 'department', ['department_id' => $d('CSE')], -60, null, 0, 'published', 0, 'academics',
        '<p>Students must log in with their own credentials, save work only in their home folders and shut down systems after use. Installing software is not permitted.</p>', false];
    $n[] = ['Holiday on Account of Guru Nanak Jayanti', 'holiday', 'all', [], -1, 34, 0, 'published', 1, 'administrator',
        '<p>The institute will remain closed on ' . $fmt(33, 'l, d M Y') . ' on account of Guru Nanak Jayanti.</p>', false];
    $n[] = ['Winter Break and New Year Schedule', 'holiday', 'all', [], null, null, 0, 'draft', 1, 'administrator',
        '<p>Draft — dates of the winter break to be confirmed after the examination schedule is finalised.</p>', false];
    $n[] = ['Revised Attendance Policy (75% Rule)', 'academic', 'students', [], null, null, 0, 'draft', 1, 'academics',
        '<p>Students must maintain a minimum of 75% attendance in each subject to be eligible for end-semester examinations. Condonation up to 10% may be granted on medical grounds.</p>', false];
    $n[] = ['Hackathon 2026: Call for Teams', 'event', 'department', ['department_id' => $d('CSE')], 5, 25, 0, 'published', 1, 'content',
        '<p>Teams of 3–4 students can register for the 24-hour Hackathon 2026. Themes include smart campus, healthcare, fintech and sustainability.</p>', false];
    $n[] = ['Mid-Term Feedback on Teaching — Student Survey', 'academic', 'students', [], -3, 7, 0, 'published', 0, 'academics',
        '<p>Students are requested to complete the anonymous mid-term teaching feedback survey on the student portal. Your feedback helps us improve teaching quality.</p>', false];
    $n[] = ['Convocation 2026: Registration for Graduating Students', 'general', 'alumni', [], 2, 40, 0, 'published', 1, 'administrator',
        '<p>Graduates of the 2026 batch can register for the convocation ceremony. Registration closes on ' . $fmt(30) . '.</p>', false];
    $n[] = ['NSS Blood Donation Camp', 'event', 'all', [], -9, -2, 0, 'expired', 1, 'content',
        '<p>The NSS unit, in association with the district blood bank, organised a blood donation camp in the Multipurpose Hall.</p>', false];
    $n[] = ['Faculty Meeting: Academic Calendar Review', 'circular', 'faculty', [], -1, 3, 0, 'published', 0, 'academics',
        '<p>All Heads of Departments and faculty members are requested to attend a meeting to review the academic calendar for the even semester on ' . $fmt(2) . ' at 3:30 PM in the Conference Room.</p>', true];

    // downloadable PDF attachments for a few notices (date sheet, circulars) — written to this unit's own upload folder
    $pdfDir = 'assets/uploads/notices/seed';
    if (!is_dir(APP_ROOT . '/' . $pdfDir)) {
        @mkdir(APP_ROOT . '/' . $pdfDir, 0775, true);
    }
    $pdf = function (string $file, string $heading, array $lines) use ($pdfDir): ?string {
        $esc = fn (string $s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], iconv('UTF-8', 'windows-1252//TRANSLIT', $s) ?: $s);
        $c = "BT /F2 15 Tf 0.04 0.16 0.36 rg 56 780 Td (" . $esc(institute_name()) . ") Tj ET\n"
            . "0.04 0.16 0.36 RG 1.2 w 56 768 m 539 768 l S\n"
            . "BT /F2 13 Tf 0.1 0.1 0.1 rg 56 740 Td (" . $esc($heading) . ") Tj ET\n";
        $y = 712;
        foreach ($lines as $line) {
            $bold = str_starts_with($line, '#');
            $c .= 'BT /F' . ($bold ? 2 : 1) . ' 10.5 Tf 0.15 0.15 0.15 rg 56 ' . $y . ' Td (' . $esc(ltrim($line, '# ')) . ") Tj ET\n";
            $y -= $bold ? 20 : 17;
        }
        $c .= 'BT /F1 9 Tf 0.45 0.5 0.55 rg 56 60 Td (' . $esc('Issued by the office of the Registrar, ' . institute_name() . '.') . ") Tj ET\n";
        $objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>',
            '<< /Length ' . strlen($c) . " >>\nstream\n" . $c . 'endstream',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $k => $o) {
            $offsets[] = strlen($out);
            $out .= ($k + 1) . " 0 obj\n" . $o . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $out .= sprintf("%010d 00000 n \n", $off);
        }
        $out .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return @file_put_contents(APP_ROOT . '/' . $pdfDir . '/' . $file, $out) !== false ? $pdfDir . '/' . $file : null;
    };
    $subjects = ['Business Communication', 'Principles of Management', 'Financial Accounting', 'Data Structures', 'Database Management Systems', 'Business Statistics',
        'Operating Systems', 'Marketing Management', 'Discrete Mathematics', 'Organisational Behaviour'];
    $sheet = ['#Date            Session I (10:00 AM - 12:00 PM)        Session II (2:00 PM - 4:00 PM)'];
    for ($k = 0, $day = 4; $k < 8; $k++, $day++) {
        if (date('N', strtotime($today . " +$day days")) == 7) {
            $day++;
        }
        $sheet[] = $fmt($day, 'D, d M Y') . '      ' . str_pad($subjects[$k % 10], 38) . $subjects[($k + 3) % 10];
    }
    $attachments = [
        0 => $pdf('mid-semester-date-sheet.pdf', 'Mid-Semester Examinations - Date Sheet', array_merge($sheet, ['', '#Instructions', 'Carry the admit card and college ID card to every paper.',
            'Entry closes 15 minutes after the start of the paper.', 'Mobile phones and smart watches are not allowed in the examination hall.'])),
        2 => $pdf('diwali-vacation-circular.pdf', 'Circular - Diwali Vacation', ['Campus closed from ' . $fmt(26) . ' to ' . $fmt(30) . '.', 'Classes resume on ' . $fmt(31, 'l, d M Y') . '.',
            'Hostels remain open - inform the warden by ' . $fmt(20) . '.', 'Library and computer labs are closed during the vacation.']),
        4 => $pdf('odd-semester-fee-schedule.pdf', 'Odd Semester Fee - Payment Schedule', ['#Last date without late fee: ' . $fmt(19), 'Late fee: Rs. 50 per day after the due date.',
            'Online: UPI, net banking, debit / credit card on the student portal.', 'Offline: demand draft at the Accounts Office (9:30 AM - 4:00 PM).']),
        11 => $pdf('anti-ragging-helplines.pdf', 'Anti-Ragging Committee and Helplines', ['#National Anti-Ragging Helpline: 1800-180-5522 (24x7, toll free)',
            'Institute Anti-Ragging Squad: +91 99554 46477', 'E-mail: antiragging@gimt.ac.in', 'Online undertaking: www.antiragging.in']),
    ];

    $noticeIds = [];
    foreach ($n as $i => [$title, $cat, $aud, $t, $pub, $exp, $pin, $status, $web, $author, $body, $emailed]) {
        $publish = $pub === null ? null : $at($pub, sprintf('%02d:%02d', mt_rand(9, 17), demo_pick([0, 15, 30, 45])));
        $expiry = $exp === null ? null : $at($exp, '23:59');
        $views = ($web && $status !== 'draft' && $pub !== null && $pub <= 0) ? mt_rand(20, 60) + (int) (abs($pub) * mt_rand(6, 22)) : 0;
        $created = $publish ? date('Y-m-d H:i:s', strtotime($publish) - mt_rand(3600, 86400 * 2)) : $at(-mt_rand(1, 5), '11:20');
        $id = db_insert('notices', [
            'title' => $title, 'slug' => comm_unique_slug('notices', $title), 'category' => $cat, 'audience' => $aud,
            'department_id' => $t['department_id'] ?? null, 'program_id' => $t['program_id'] ?? null, 'semester_no' => $t['semester_no'] ?? null,
            'description' => $body, 'attachment' => $attachments[$i] ?? null, 'publish_date' => $publish, 'expiry_date' => $expiry, 'is_pinned' => $pin, 'show_on_website' => $web,
            'send_email' => $emailed ? 1 : 0, 'send_sms' => 0, 'send_whatsapp' => 0, 'views' => $views, 'status' => $status, 'created_by' => $uid($author),
            'created_at' => $created,
        ]);
        $noticeIds[$i] = $id;
    }

    // delivery history for e-mailed notices (real audience addresses)
    $bounce = ['550 5.1.1 The email account that you tried to reach does not exist.', '552 5.2.2 The recipient\'s mailbox is full.',
        'SMTP error after "RCPT": 550 5.7.1 Recipient address rejected: domain not found', 'SMTP connect failed: Connection timed out (110)'];
    $logRows = [];
    $queueRows = [];
    foreach ($n as $i => $row) {
        if (!$row[11]) {
            continue;
        }
        $notice = comm_notice_find($noticeIds[$i]);
        $msg = comm_notice_message($notice, 'email');
        $people = comm_audience_people((string) $notice['audience'], comm_notice_targets($notice));
        $sentAt = strtotime((string) $notice['publish_date']);
        $seen = [];
        $k = 0;
        foreach ($people as $person) {
            $addr = comm_address($person, 'email');
            if ($addr === '' || isset($seen[$addr])) {
                continue;
            }
            $seen[$addr] = true;
            $failed = mt_rand(1, 1000) <= 14;
            $ts = date('Y-m-d H:i:s', $sentAt + 20 + intdiv($k++, 4));
            $logRows[] = ['channel' => 'email', 'recipient' => $addr, 'subject' => $msg['subject'], 'body' => $msg['body'], 'status' => $failed ? 'failed' : 'sent',
                'error' => $failed ? demo_pick($bounce) : null, 'related_type' => 'notice', 'related_id' => $noticeIds[$i], 'campaign_id' => null,
                'sent_at' => $failed ? null : $ts, 'created_at' => $ts];
        }
    }

    // ---------------------------------------------------------------- events
    $faculty = db_all("SELECT id, TRIM(CONCAT_WS(' ', title, first_name, last_name)) AS name, email, phone FROM faculty WHERE status = 'active' ORDER BY id");
    $students = db_all("SELECT s.id, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS name, s.email, s.mobile, s.program_id, s.current_semester, s.department_id
                        FROM students s WHERE s.status = 'active' AND s.email IS NOT NULL ORDER BY s.id");
    $orgs = ['Delhi University', 'Amity University, Noida', 'Sharda University', 'Galgotias University', 'IIM Lucknow (alumni)', 'Infosys Ltd', 'HCL Technologies', 'Tata Consultancy Services',
        'KIET Group of Institutions', 'GL Bajaj Institute', 'Bennett University', 'Delhi Public School, Greater Noida', 'Ryan International School', 'Self-employed', 'Deloitte India', 'Genpact'];
    $img = fn (string $f) => 'assets/images/site/' . $f;
    // [title, category, startDays, startTime, durationHours, venue, organiser, capacity, regEnabled, regTarget, mix, status, featured, image, excerpt]
    $ev = [
        ['Freshers\' Welcome 2026 — Aagaaz', 'cultural', -75, '16:00', 4, 'Main Auditorium', 'Student Activities Council', 600, 1, 182, 'students', 'completed', 0, 'students-lawn.jpg', 'An evening of music, dance and introductions to welcome the 2026 batch.'],
        ['Orientation Programme 2026-27', 'academic', -85, '09:30', 7, 'Main Auditorium', 'Office of the Dean Academics', null, 0, 0, 'students', 'completed', 0, 'students-atrium.jpg', 'Induction for new students: academic rules, campus facilities, clubs and mentoring.'],
        ['Workshop: Cloud Computing with AWS', 'workshop', -40, '10:00', 6, 'Lab C-201, Academic Block C', 'Department of Computer Science & Engineering', 60, 1, 58, 'students', 'completed', 0, 'facility-labs.jpg', 'Hands-on workshop on EC2, S3, IAM and serverless deployment.'],
        ['Industry Connect Seminar: The Future of FinTech', 'seminar', -30, '11:00', 3, 'Seminar Hall, Management Block', 'Department of Management Studies', 150, 1, 121, 'mixed', 'completed', 0, 'students-laptop.jpg', 'Industry leaders discuss digital payments, lending and regulation.'],
        ['Independence Day Celebration', 'cultural', -58, '08:30', 3, 'Central Lawn', 'NSS & NCC Units', null, 0, 0, 'students', 'completed', 0, 'facility-green-campus.jpg', 'Flag hoisting followed by a cultural programme.'],
        ['Inter-College Cricket Tournament', 'sports', -25, '08:00', 9, 'Sports Ground', 'Sports Department', 160, 1, 96, 'students', 'completed', 0, 'facility-sports.jpg', 'T20 tournament with 8 participating colleges.'],
        ['Webinar: Cracking Campus Placements', 'webinar', -18, '17:00', 2, 'Online (Zoom)', 'Training & Placement Cell', 500, 1, 236, 'mixed', 'completed', 0, 'placement-student.jpg', 'Resume, aptitude and interview tips from HR professionals.'],
        ['FDP: Outcome-Based Education & NAAC Readiness', 'fdp', -14, '10:00', 6, 'Conference Room, Admin Block', 'IQAC', 80, 1, 46, 'faculty', 'completed', 0, 'facility-classroom.jpg', 'Five-day faculty development programme on OBE and accreditation.'],
        ['Teachers\' Day Celebration', 'cultural', -37, '11:00', 3, 'Main Auditorium', 'Student Activities Council', null, 0, 0, 'students', 'completed', 0, 'graduation.jpg', 'Students honour the faculty with performances and awards.'],
        ['NSS Blood Donation Camp', 'other', -9, '09:00', 6, 'Multipurpose Hall', 'NSS Unit', 150, 1, 87, 'mixed', 'completed', 0, 'campus-main.jpg', 'Blood donation camp with the district blood bank.'],
        ['Admission Counselling Day', 'admission', -5, '10:00', 6, 'Admission Cell, Admin Block', 'Admission Cell', 120, 1, 64, 'guests', 'completed', 0, 'hero-campus-students.jpg', 'Walk-in counselling for students and parents for the 2027-28 session.'],
        ['Hackathon Ideation Bootcamp', 'workshop', -3, '14:00', 3, 'Innovation Lab', 'Innovation & Incubation Cell', 100, 1, 72, 'students', 'completed', 0, 'students-laptop.jpg', 'Problem statements, team formation and mentoring ahead of Hackathon 2026.'],
        ['Guest Lecture: Generative AI in Business', 'seminar', 0, '09:30', 9, 'Seminar Hall, Management Block', 'Department of Management Studies', 180, 1, 112, 'students', 'published', 0, 'students-atrium.jpg', 'Senior industry consultant on how generative AI is reshaping strategy.'],
        ['Workshop: Financial Modelling in Excel', 'workshop', 4, '10:00', 5, 'Computer Lab 2, Management Block', 'Finance Club', 50, 1, 50, 'students', 'published', 0, 'facility-labs.jpg', 'Build a three-statement model and DCF valuation from scratch.'],
        ['Webinar: MBA vs PGDM — Choosing the Right Path', 'webinar', 6, '18:00', 1, 'Online (YouTube Live)', 'Admission Cell', 1000, 1, 131, 'guests', 'published', 0, 'program-mba.jpg', 'Admission counsellors answer questions from aspirants and parents.'],
        ['Placement Drive: Leading IT Services Recruiter', 'placement', 9, '09:30', 8, 'Main Auditorium & Labs C-201/C-202', 'Training & Placement Cell', 200, 1, 163, 'students', 'published', 1, 'placement-student.jpg', 'On-campus drive for B.Tech, MCA and BCA students of the 2027 batch.'],
        ['Innovate 2026 — Annual Tech Fest', 'academic', 12, '09:00', 33, 'Academic Block C & Main Auditorium', 'Department of Computer Science & Engineering', 800, 1, 214, 'mixed', 'published', 1, 'campus-academic-block.jpg', 'Two days of hackathons, robotics, coding contests, talks and exhibitions.'],
        ['Annual Sports Meet 2026', 'sports', 20, '08:00', 34, 'Sports Ground', 'Sports Department', null, 1, 141, 'students', 'published', 1, 'facility-sports.jpg', 'Track and field, team sports and the inter-department championship.'],
        ['Diwali Mela & Cultural Night', 'cultural', 24, '16:00', 5, 'Central Lawn', 'Student Activities Council', null, 1, 34, 'mixed', 'published', 0, 'students-lawn.jpg', 'Food stalls, rangoli competition, music and a cultural night.'],
        ['National Conference on Sustainable Business Practices', 'conference', 28, '09:30', 32, 'Main Auditorium', 'Department of Commerce', 250, 1, 77, 'mixed', 'published', 0, 'campus-building.jpg', 'Paper presentations and keynotes on ESG, green finance and circular economy.'],
        ['Alumni Homecoming 2026', 'alumni', 45, '17:00', 5, 'Main Auditorium', 'Alumni Relations Office', 300, 1, 41, 'alumni', 'published', 1, 'graduation.jpg', 'Reconnect with classmates and faculty at the annual alumni evening.'],
        ['Industrial Visit to Auto Expo', 'academic', 15, '08:00', 9, 'India Expo Mart, Greater Noida', 'Department of Management Studies', 90, 1, 0, 'students', 'cancelled', 0, 'campus-main.jpg', 'Cancelled because the expo dates were moved.'],
        ['Entrepreneurship Summit 2027', 'conference', 70, '10:00', 7, 'Main Auditorium', 'Innovation & Incubation Cell', 400, 0, 0, 'mixed', 'draft', 0, 'campus-academic-block.jpg', 'Startup founders, investors and pitch competitions (draft).'],
    ];
    $eventRows = [];
    $regs = [];
    $usedStudent = 0;
    foreach ($ev as [$title, $cat, $sd, $st, $hrs, $venue, $org, $cap, $regOn, $regN, $mix, $status, $feat, $image, $excerpt]) {
        $start = $at($sd, $st);
        $end = date('Y-m-d H:i:s', strtotime($start) + $hrs * 3600);
        $deadline = $regOn ? date('Y-m-d H:i:s', strtotime($start) - 86400 + 6 * 3600) : null;
        $desc = '<p>' . e($excerpt) . '</p><p><strong>Venue:</strong> ' . e($venue) . '<br><strong>Organised by:</strong> ' . e($org) . '</p>'
            . ($regOn ? '<p>Registration is ' . ($cap ? 'limited to ' . $cap . ' participants' : 'open to all') . '. Carry your ID card on the day of the event.</p>' : '');
        $id = db_insert('events', [
            'title' => $title, 'slug' => comm_unique_slug('events', $title), 'category' => $cat, 'excerpt' => $excerpt, 'description' => $desc, 'venue' => $venue,
            'start_datetime' => $start, 'end_datetime' => $end, 'image' => $img($image), 'organizer' => $org, 'contact_email' => 'events@gimt.ac.in', 'registration_link' => null,
            'registration_enabled' => $regOn, 'registration_deadline' => $deadline, 'max_participants' => $cap, 'is_featured' => $feat, 'show_on_website' => $status === 'draft' ? 0 : 1,
            'status' => $status, 'created_by' => $uid(demo_pick(['content', 'academics', 'admin'])), 'created_at' => date('Y-m-d H:i:s', strtotime($start) - mt_rand(12, 30) * 86400),
        ]);
        $eventRows[$id] = ['start' => strtotime($start), 'end' => strtotime($end), 'title' => $title, 'venue' => $venue, 'past' => strtotime($end) < $now, 'slug' => $title];
        if (!$regN) {
            continue;
        }
        $seenEmail = [];
        $openTo = min($now - 600, strtotime($start) - 3600);
        $openFrom = min(strtotime($start) - 30 * 86400, $now - 20 * 86400);
        if ($openFrom >= $openTo) {
            $openFrom = $openTo - 14 * 86400;
        }
        for ($k = 0; $k < $regN; $k++) {
            $type = match ($mix) {
                'students' => 'student',
                'faculty' => 'faculty',
                'guests' => demo_weighted(['guest' => 70, 'parent' => 30]),
                'alumni' => demo_weighted(['alumni' => 85, 'faculty' => 15]),
                default => demo_weighted(['student' => 55, 'guest' => 25, 'faculty' => 10, 'alumni' => 10]),
            };
            $phone = demo_phone();
            $orgName = null;
            if ($type === 'student' && $students) {
                $s = $students[($usedStudent++ * 7 + $k * 13) % count($students)];
                $name = $s['name'];
                $email = strtolower((string) $s['email']);
                $phone = $s['mobile'];
                $orgName = institute_name();
            } elseif ($type === 'faculty' && $faculty) {
                $f = $faculty[($k * 5) % count($faculty)];
                $name = $f['name'];
                $email = strtolower((string) $f['email']);
                $phone = $f['phone'];
                $orgName = institute_name();
            } else {
                $g = demo_pick(['male', 'female']);
                $first = demo_pick(demo_first_names($g));
                $last = demo_pick(demo_last_names());
                $name = ($type === 'parent' ? ($g === 'male' ? 'Mr. ' : 'Mrs. ') : '') . $first . ' ' . $last;
                $email = demo_email($first, $last, demo_pick(['gmail.com', 'yahoo.com', 'outlook.com', 'rediffmail.com', 'hotmail.com']), mt_rand(1, 999));
                $orgName = $type === 'alumni' ? demo_pick(['Infosys Ltd', 'HCL Technologies', 'Deloitte India', 'Genpact', 'Accenture', 'Paytm', 'ICICI Bank', 'Self-employed']) : demo_pick($orgs);
            }
            if (isset($seenEmail[$email])) {
                continue;
            }
            $seenEmail[$email] = true;
            $created = date('Y-m-d H:i:s', mt_rand($openFrom, $openTo));
            $past = strtotime($end) < $now;
            $ongoing = !$past && strtotime($start) <= $now;
            $rs = 'registered';
            if (mt_rand(1, 100) <= 4) {
                $rs = 'cancelled';
            } elseif ($past && mt_rand(1, 100) <= 82) {
                $rs = 'attended';
            } elseif ($ongoing && mt_rand(1, 100) <= 55) {
                $rs = 'attended';
            }
            $checkIn = $rs === 'attended' ? date('Y-m-d H:i:s', min($now - 60, strtotime($start) - 1800 + mt_rand(0, 5400))) : null;
            $regs[] = ['event_id' => $id, 'name' => mb_substr($name, 0, 150), 'email' => $email, 'phone' => $phone, 'organization' => $orgName, 'participant_type' => $type,
                'source' => demo_weighted(['website' => 72, 'admin' => 20, 'import' => 8]), 'status' => $rs, 'checked_in_at' => $checkIn,
                'checked_in_by' => $checkIn ? $uid('content') : null, 'notes' => null, 'created_at' => $created];
        }
    }
    demo_bulk_insert('event_registrations', $regs);

    // registration confirmations (website registrations) + reminders for events in the next 10 days
    $ackTpl = db_row("SELECT subject, body_html FROM email_templates WHERE code = 'event_registration'");
    foreach (db_all("SELECT r.id, r.name, r.email, r.created_at, r.event_id, e.title, e.venue, e.start_datetime FROM event_registrations r JOIN events e ON e.id = r.event_id WHERE r.source = 'website'") as $r) {
        $vars = comm_base_vars() + ['name' => $r['name'], 'event' => $r['title'], 'date' => format_date($r['start_datetime']) . ', ' . date('g:i A', strtotime($r['start_datetime'])), 'venue' => (string) $r['venue']];
        $ts = date('Y-m-d H:i:s', strtotime($r['created_at']) + 3);
        $logRows[] = ['channel' => 'email', 'recipient' => $r['email'], 'subject' => comm_render((string) $ackTpl['subject'], $vars, false), 'body' => comm_render((string) $ackTpl['body_html'], $vars),
            'status' => 'sent', 'error' => null, 'related_type' => 'event_registration', 'related_id' => (int) $r['id'], 'campaign_id' => null, 'sent_at' => $ts, 'created_at' => $ts];
    }
    $remTpl = db_row("SELECT subject, body_html FROM email_templates WHERE code = 'event_reminder'");
    foreach (db_all("SELECT id, title, venue, slug, start_datetime FROM events WHERE status = 'published' AND start_datetime BETWEEN ? AND ?", [date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + 10 * 86400)]) as $e) {
        $ts0 = max(strtotime($e['start_datetime']) - 5 * 86400, $now - 2 * 86400);
        if ($ts0 > $now) {
            continue;
        }
        foreach (db_all("SELECT id, name, email FROM event_registrations WHERE event_id = ? AND status = 'registered'", [$e['id']]) as $j => $r) {
            $vars = comm_base_vars() + ['name' => $r['name'], 'event' => $e['title'], 'date' => format_date($e['start_datetime']) . ', ' . date('g:i A', strtotime($e['start_datetime'])),
                'venue' => (string) $e['venue'], 'link' => absolute_url('events/' . $e['slug'])];
            $ts = date('Y-m-d H:i:s', $ts0 + intdiv($j, 5));
            $failed = mt_rand(1, 1000) <= 12;
            $logRows[] = ['channel' => 'email', 'recipient' => $r['email'], 'subject' => comm_render((string) $remTpl['subject'], $vars, false), 'body' => comm_render((string) $remTpl['body_html'], $vars),
                'status' => $failed ? 'failed' : 'sent', 'error' => $failed ? demo_pick($bounce) : null, 'related_type' => 'event', 'related_id' => (int) $e['id'], 'campaign_id' => null,
                'sent_at' => $failed ? null : $ts, 'created_at' => $ts];
        }
    }

    // ---------------------------------------------------------------- newsletter subscribers (~300)
    $existing = array_flip(db_column('SELECT email FROM newsletter_subscribers'));
    $subs = [];
    $sourceW = ['website' => 34, 'footer' => 22, 'blog' => 9, 'events' => 11, 'admissions' => 12, 'counselling' => 5, 'alumni' => 4, 'import' => 3];
    $domains = ['gmail.com' => 60, 'yahoo.com' => 12, 'outlook.com' => 10, 'rediffmail.com' => 6, 'hotmail.com' => 5, 'icloud.com' => 4, 'protonmail.com' => 3];
    $target = 300;
    $tries = 0;
    while (count($subs) < $target && $tries++ < 2000) {
        $g = demo_pick(['male', 'female']);
        $first = demo_pick(demo_first_names($g));
        $last = demo_pick(demo_last_names());
        $email = demo_email($first, $last, demo_weighted($domains), mt_rand(0, 3) ? mt_rand(1, 2026) : 0);
        if (isset($existing[$email])) {
            continue;
        }
        $existing[$email] = true;
        // growth skewed towards recent months (admission season)
        $ageDays = (int) floor(pow(mt_rand(0, 1000) / 1000, 1.6) * 300);
        $subAt = date('Y-m-d H:i:s', $now - $ageDays * 86400 - mt_rand(0, 86000));
        $status = demo_weighted(['subscribed' => 88, 'unsubscribed' => 9, 'bounced' => 3]);
        $unsubAt = $status === 'subscribed' ? null : date('Y-m-d H:i:s', min($now - 3600, strtotime($subAt) + mt_rand(5, max(6, $ageDays)) * 86400));
        $subs[] = ['email' => $email, 'name' => mt_rand(1, 100) <= 78 ? $first . ' ' . $last : null, 'status' => $status, 'source' => demo_weighted($sourceW),
            'token' => md5('gimt-demo:' . $email), 'ip_address' => mt_rand(1, 100) <= 85 ? '49.' . mt_rand(32, 47) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254) : null,
            'subscribed_at' => $subAt, 'unsubscribed_at' => $unsubAt];
    }
    demo_bulk_insert('newsletter_subscribers', $subs);

    // ---------------------------------------------------------------- campaigns
    $tplId = db_pairs("SELECT code, id FROM email_templates");
    $nlBody = function (string $headline, array $items, string $cta, string $ctaUrl) use ($ul): string {
        return '<h2 style="color:#0B2A5B;margin:0 0 8px">' . $headline . '</h2><p>Dear {{name}},</p>'
            . '<p>Here is a quick round-up of what is happening at {{institute_name}}.</p>' . $ul($items)
            . '<p><a href="' . $ctaUrl . '" style="display:inline-block;background:#22943F;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">' . $cta . '</a></p>'
            . '<p style="font-size:12px;color:#94a3b8">You are receiving this e-mail because you subscribed on our website. <a href="{{unsubscribe_link}}">Unsubscribe</a></p>';
    };
    $camps = [
        // [title, subject, body, audience, filter, status, sentDays|scheduledDays, template, author]
        ['September 2026 Newsletter', '{{institute_name}} Newsletter — September 2026',
            $nlBody('September at GIMT', ['Freshers\' Welcome "Aagaaz" brings 600 students together', 'AWS cloud workshop: 58 students certified', '42 students placed in the first wave of campus drives', 'Admissions 2027-28 open on ' . $fmt(-10)], 'Read the full newsletter', '{{site_url}}blog'),
            'subscribers', null, 'sent', -38, 'newsletter_monthly', 'content'],
        ['Admissions Open 2027-28 Announcement', 'Admissions open for the 2027-28 session — apply early, save up to 25%',
            '<p>Dear {{name}},</p><p>Applications for the 2027-28 session are now open across BBA, MBA, B.Tech, BCA, MCA, B.Com (Hons), M.Com and B.Sc Data Science.</p><ul><li>Early bird scholarship up to 25% until ' . $fmt(60) . '</li><li>Merit scholarships up to 50% of tuition</li><li>Placement training from the first year</li></ul><p><a href="{{site_url}}apply" style="display:inline-block;background:#22943F;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">Start your application</a></p><p style="font-size:12px;color:#94a3b8"><a href="{{unsubscribe_link}}">Unsubscribe</a></p>',
            'subscribers', null, 'sent', -10, 'admission_open_campaign', 'admissions'],
        ['Parent Connect — Fee Reminder & PTM Dates', 'Odd semester fee due on ' . $fmt(19) . ' and PTM schedule',
            '<p>Dear Parent,</p><p>This is a reminder that the odd semester fee is due on <strong>' . $fmt(19) . '</strong>. The Parent–Teacher Meeting for Semester 1 is scheduled on ' . $fmt(11) . '.</p><p>Pay online on the student portal through UPI, net banking or card.</p><p>Regards,<br>Accounts Office, {{institute_name}}</p>',
            'parents', null, 'sent', -6, null, 'accounts'],
        ['Placement Season Kick-off', 'Placement season 2027: register by ' . $fmt(6) . ' and prepare with the T&P Cell',
            '<p>Dear {{name}},</p><p>The 2027 placement season starts next week. Register on the placement portal, upload your resume and attend the aptitude practice sessions every Wednesday and Friday.</p><p>The first on-campus drive is on <strong>' . $fmt(9) . '</strong>.</p><p>All the best!<br>Training & Placement Cell</p>',
            'program', ['program_ids' => array_values(array_filter([$p('B.Tech (CSE)'), $p('B.Tech (AI & DS)'), $p('MCA'), $p('BCA')])), 'semester_no' => null], 'sent', -4, null, 'placement'],
        ['Faculty Development Week — Schedule', 'FDP on Outcome-Based Education: session schedule',
            '<p>Dear {{name}},</p><p>Please find the schedule of the Faculty Development Programme on Outcome-Based Education. Sessions run from 10:00 AM to 4:00 PM in the Conference Room, Admin Block.</p><ol><li>Day 1: OBE framework and CO-PO mapping</li><li>Day 2: Assessment design and rubrics</li><li>Day 3: Attainment calculation</li><li>Day 4: NAAC Criterion 2 documentation</li><li>Day 5: Action plans and feedback</li></ol>',
            'faculty', null, 'sent', -15, null, 'academics'],
        ['October 2026 Newsletter', '{{institute_name}} Newsletter — October 2026',
            $nlBody('October at GIMT', ['Innovate 2026 tech fest on ' . $fmt(12) . ' — registrations open', 'Industry Connect seminar on FinTech draws 120 participants', 'Inter-college cricket: GIMT finishes runner-up', 'Diwali Mela & Cultural Night on ' . $fmt(24)], 'See upcoming events', '{{site_url}}events'),
            'subscribers', null, 'scheduled', 2, 'newsletter_monthly', 'content'],
        ['Innovate 2026 — Register Now', 'Innovate 2026: hackathon, robotics and coding contests — register your team',
            '<p>Dear {{name}},</p><p>Innovate 2026, our annual tech fest, is on <strong>' . $fmt(12) . '</strong>. Compete in the 24-hour hackathon, robotics challenge and competitive programming contest with prizes worth ₹2 lakh.</p><p><a href="{{site_url}}events" style="display:inline-block;background:#0B2A5B;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">Register your team</a></p>',
            'students', null, 'draft', null, null, 'content'],
        ['Webinar Invite: MBA vs PGDM', 'Join our live webinar: MBA vs PGDM — choosing the right path',
            '<p>Dear {{name}},</p><p>Confused between an MBA and a PGDM? Join our admission counsellors live on <strong>' . $fmt(6) . ' at 6:00 PM</strong> for an honest comparison of curriculum, fees, placements and career paths.</p><p><a href="{{site_url}}events" style="display:inline-block;background:#22943F;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">Save my seat</a></p><p style="font-size:12px;color:#94a3b8"><a href="{{unsubscribe_link}}">Unsubscribe</a></p>',
            'subscribers', null, 'draft', null, null, 'admissions'],
    ];
    foreach ($camps as [$title, $subject, $body, $aud, $filter, $status, $days, $tpl, $author]) {
        $created = $at(($days ?? -2) - mt_rand(2, 6), '12:10');
        $id = db_insert('email_campaigns', [
            'title' => $title, 'channel' => 'email', 'subject' => $subject, 'body_html' => $body, 'template_id' => $tpl ? ($tplId[$tpl] ?? null) : null, 'audience' => $aud,
            'audience_filter' => $filter ? json_encode($filter + ['emails' => '']) : null, 'status' => $status, 'scheduled_at' => $status === 'scheduled' ? $at($days, '09:00') : null,
            'created_by' => $uid($author), 'created_at' => $created,
        ]);
        $c = db_row('SELECT * FROM email_campaigns WHERE id = ?', [$id]);
        if ($status === 'scheduled') {
            $count = comm_audience_summary($aud, comm_campaign_filter($c))['email'];
            db_update('email_campaigns', ['recipients_count' => $count], 'id = ?', [$id]);
            continue;
        }
        if ($status !== 'sent') {
            continue;
        }
        $start = strtotime($at($days, '10:30'));
        $people = comm_campaign_people($c);
        if ($aud === 'subscribers') {   // only people subscribed at that time
            $people = array_values(array_filter($people, fn ($x) => true));
            $subTimes = db_pairs("SELECT email, subscribed_at FROM newsletter_subscribers");
            $people = array_values(array_filter($people, fn ($x) => strtotime((string) ($subTimes[$x['email']] ?? '2000-01-01')) <= $start));
            // include those who unsubscribed after the send
            foreach (db_all("SELECT id, email, name, token FROM newsletter_subscribers WHERE status <> 'subscribed' AND subscribed_at <= ? AND unsubscribed_at > ?",
                [date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $start)]) as $u) {
                $people[] = ['type' => 'subscriber', 'id' => $u['id'], 'name' => $u['name'] ?: strstr($u['email'], '@', true), 'email' => $u['email'], 'token' => $u['token'], 'phone' => null, 'whatsapp' => null, 'user_id' => null];
            }
        }
        $batch = comm_new_batch();
        $sent = $failed = 0;
        $seen = [];
        foreach ($people as $j => $person) {
            $addr = comm_address($person, 'email');
            if ($addr === '' || isset($seen[$addr])) {
                continue;
            }
            $seen[$addr] = true;
            $msg = comm_campaign_message($c, $person);
            $isFail = mt_rand(1, 1000) <= 18;
            $isFail ? $failed++ : $sent++;
            $ts = date('Y-m-d H:i:s', $start + intdiv(count($seen), 3));
            $err = $isFail ? demo_pick($bounce) : null;
            $logRows[] = ['channel' => 'email', 'recipient' => $addr, 'subject' => $msg['subject'], 'body' => $msg['body'], 'status' => $isFail ? 'failed' : 'sent', 'error' => $err,
                'related_type' => 'campaign', 'related_id' => $id, 'campaign_id' => $id, 'sent_at' => $isFail ? null : $ts, 'created_at' => $ts];
            $queueRows[] = ['batch_id' => $batch, 'channel' => 'email', 'recipient' => $addr, 'recipient_name' => mb_substr((string) $person['name'], 0, 150), 'subject' => mb_substr((string) $msg['subject'], 0, 255),
                'body' => null, 'related_type' => 'campaign', 'related_id' => $id, 'campaign_id' => $id, 'status' => $isFail ? 'failed' : 'sent', 'error' => $err,
                'claim_token' => null, 'claimed_at' => $ts, 'processed_at' => $ts, 'created_by' => $uid($author), 'created_at' => date('Y-m-d H:i:s', $start)];
        }
        $end = date('Y-m-d H:i:s', $start + intdiv(count($seen), 3) + 5);
        db_update('email_campaigns', ['recipients_count' => $sent + $failed, 'sent_count' => $sent, 'failed_count' => $failed, 'started_at' => date('Y-m-d H:i:s', $start),
            'sent_at' => $end, 'test_sent_at' => date('Y-m-d H:i:s', $start - 1800)], 'id = ?', [$id]);
        // the test e-mail sent before going live
        $logRows[] = ['channel' => 'email', 'recipient' => db_value('SELECT email FROM users WHERE id = ?', [$uid($author)]) ?: 'admin@gimt.ac.in', 'subject' => '[Test] ' . comm_render($subject, comm_base_vars(), false),
            'body' => comm_campaign_message($c, ['name' => 'Test Recipient', 'email' => 'test@gimt.ac.in', 'token' => null])['body'], 'status' => 'sent', 'error' => null,
            'related_type' => 'campaign_test', 'related_id' => $id, 'campaign_id' => null, 'sent_at' => date('Y-m-d H:i:s', $start - 1800), 'created_at' => date('Y-m-d H:i:s', $start - 1800)];
    }

    // a couple of template tests
    foreach (['fee_reminder', 'event_reminder', 'newsletter_monthly'] as $j => $code) {
        $t = db_row('SELECT * FROM email_templates WHERE code = ?', [$code]);
        if (!$t) {
            continue;
        }
        $pv = comm_template_preview('email', (string) $t['subject'], (string) $t['body_html'], ['name' => 'Anita Desai']);
        $ts = $at(-(3 + $j * 4), '15:4' . $j);
        $logRows[] = ['channel' => 'email', 'recipient' => 'anita.desai@gimt.ac.in', 'subject' => '[Test] ' . $pv['subject'], 'body' => $pv['inner'], 'status' => 'sent', 'error' => null,
            'related_type' => 'template_test', 'related_id' => (int) $t['id'], 'campaign_id' => null, 'sent_at' => $ts, 'created_at' => $ts];
    }

    usort($logRows, fn ($a, $b) => strcmp($a['created_at'], $b['created_at']));
    demo_bulk_insert('message_logs', $logRows, 200);
    demo_bulk_insert('message_queue', $queueRows, 300);

    // one earlier failure that was re-sent successfully (shows the retry trail in the log)
    $failedOne = db_row("SELECT * FROM message_logs WHERE related_type = 'notice' AND status = 'failed' AND error LIKE 'SMTP connect%' ORDER BY id LIMIT 1");
    if ($failedOne) {
        $ts = date('Y-m-d H:i:s', strtotime((string) $failedOne['created_at']) + 3600);
        db_insert('message_logs', ['channel' => 'email', 'recipient' => $failedOne['recipient'], 'subject' => $failedOne['subject'], 'body' => $failedOne['body'], 'status' => 'sent',
            'error' => null, 'related_type' => 'notice', 'related_id' => $failedOne['related_id'], 'campaign_id' => null, 'retry_of' => $failedOne['id'], 'sent_at' => $ts, 'created_at' => $ts]);
    }
};
