<?php
/**
 * GIMT 90 Days BFSI & Soft Skills Professional Training Program (owner's course document, 2026-11-12; fee ₹29,999 as
 * instructed by the owner — the document's "proposed ₹14,999" is not used). Certificate course in the School of
 * Professional & Skill Development, group "Management & Business", 17 modules (subjects, semester 1) with their topics,
 * and the long-form details (calendar, timetable, assessment, careers, admission, rules, materials) in
 * programs.details_html (schema 56).
 * Safe on live data and re-runnable: the program and its modules are upserted by code; nothing else is touched.
 */
return function (array $opts): void {
    $deptId = (int) db_value("SELECT id FROM departments WHERE code = 'PSD'");
    if (!$deptId) {
        $deptId = (int) db_insert('departments', ['name' => 'School of Professional & Skill Development', 'code' => 'PSD', 'established_year' => 2018,
            'description' => 'Short-term certificate courses in management, computers, communication, AI and career skills.', 'status' => 'active']);
    }
    $hasDetails = (bool) db_value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'details_html'");

    $code = 'BFSI90';
    $name = 'BFSI & Soft Skills Professional Training Program';
    $overview = "A 90-day job-ready training program in banking, financial services and insurance (BFSI) with soft skills, spoken English and computer skills — for students, freshers and job seekers aiming for entry-level roles in banks, NBFCs, insurance and financial services.";
    $highlights = [
        '90 days (3 months), 6 days a week',
        'Online & offline classes in Hindi and English',
        '17 modules: BFSI, soft skills, spoken English and computer skills',
        'Banking practical & simulation lab — account opening, KYC, loans, EMI, CRM',
        'Daily English speaking practice and customer role-plays',
        'Written, practical, role-play and viva assessment (100 marks)',
        '5 books + trainer-led practical workbook',
        'GIMT course-completion certificate',
        'Resume, mock interviews and placement assistance (no job guarantee)',
    ];
    $careers = ['Banking Relationship Executive', 'CASA Sales Executive', 'Credit Card Sales Executive', 'Customer Service Executive', 'Loan Processing Executive',
        'Branch Operations Support', 'Field Banking Executive', 'Back Office Executive', 'Insurance Sales Executive', 'NBFC Relationship Executive'];
    $eligibility = '10th pass, 12th pass or graduate — college students, freshers and job seekers are welcome. Final eligibility for any job vacancy is decided by the employer; age, language, computer and location requirements may differ by role.';

    // [name, topics, type]
    $modules = [
        ['Introduction to Banking & BFSI', 'Meaning and scope of BFSI; evolution of banking in India; functions of banks; role of the Reserve Bank of India (RBI); public and private sector banks; small finance banks; regional rural banks; cooperative banks; NBFCs; financial inclusion; branch structure; front-office and back-office functions; banking terminology; customer relationship basics; banking career pathways.', 'theory'],
        ['Banking Products & Services', 'Savings, current, salary and basic savings bank deposit accounts; fixed and recurring deposits; CASA; debit and credit cards; personal, home, vehicle, gold, education and business loans; bank statements; nomination facilities; service charges; insurance and pension product awareness.', 'theory'],
        ['CASA Sales & Account Opening', 'CASA concepts; features of savings and current accounts; customer profiling; prospect identification; lead generation; customer needs analysis; account-opening workflow; forms and documentation; customer consent; nomination; account activation; follow-up; sales pitch; cross-selling; retention; daily sales reporting; target planning; ethical sales practices.', 'theory'],
        ['KYC, AML & Documentation', 'KYC fundamentals; customer identification and due diligence; identity and address documents; PAN and Aadhaar verification awareness; CKYC and re-KYC; beneficial ownership; AML fundamentals; suspicious-transaction awareness; account-opening forms; document checking; data confidentiality; customer consent; fraud prevention; record management; common documentation errors; compliance escalation.', 'theory'],
        ['Loan Processing & Credit Fundamentals', 'Loan categories; personal, home, vehicle, gold, education and business loans; loan enquiry and application; eligibility; income assessment; credit score and CIBIL fundamentals; credit history; interest rates; EMI calculations; repayment schedules; loan documentation; verification; credit assessment overview; approval and disbursement stages; repayment; collections and recovery awareness.', 'theory'],
        ['Digital Banking & Payments', 'Internet and mobile banking; UPI; NEFT; RTGS; IMPS; AEPS awareness; ATM operations; POS terminals; QR payments; debit and credit card safety; digital transaction checks; password safety; phishing and social-engineering awareness; cyber fraud reporting; digital banking complaints; safe use of digital financial services.', 'theory'],
        ['Credit Cards, Insurance & Financial Products', 'Credit card features, eligibility, limits, billing cycles, minimum amount due, charges and repayment; life, health, motor and general insurance; term insurance; premiums and claims; insurance documentation; mutual fund and SIP fundamentals for financial literacy; risk and return; customer needs analysis; product suitability; ethical selling and disclosure.', 'theory'],
        ['Banking Sales & Marketing', 'Sales fundamentals and planning; lead generation; customer acquisition; telecalling; field sales; CASA, credit-card and loan sales; cross-selling and up-selling; needs-based sales conversations; product explanation; objection handling; follow-up; target management; sales reporting; CRM basics; customer retention; ethical sales and customer protection.', 'theory'],
        ['Communication Skills', 'Communication process; verbal, non-verbal and written communication; active listening; questioning; clear speaking; reading and comprehension; professional vocabulary; workplace conversations; communication barriers; positive language; business communication; feedback and presentation basics.', 'theory'],
        ['Spoken English', 'Pronunciation; basic grammar; parts of speech; tenses; sentence formation; daily-use English; self-introduction; asking and answering questions; banking vocabulary; office, telephone and customer conversations; professional English; fluency practice; group discussion and interview English.', 'theory'],
        ['Personality Development', 'Self-awareness; confidence building; positive attitude; motivation; goal setting; emotional intelligence; decision-making; problem-solving; leadership; teamwork; time management; stress management; self-discipline; adaptability and professional behaviour.', 'theory'],
        ['Grooming & Body Language', 'Personal hygiene; professional dressing; office grooming; posture; eye contact; facial expressions; handshake etiquette; sitting and standing posture; meeting etiquette; professional appearance; respectful conduct and workplace discipline.', 'theory'],
        ['Customer Service Excellence', 'Customer service fundamentals; identifying customer needs; greeting and listening; query handling; complaint management; difficult-customer situations; customer satisfaction; retention; telephone and email etiquette; service quality; feedback; follow-up and customer experience.', 'theory'],
        ['Leadership & Workplace Behaviour', 'Leadership fundamentals; team coordination; conflict management; workplace ethics; responsibility; accountability; task management; performance awareness; collaboration; adaptability; corporate culture; professional discipline and career development.', 'theory'],
        ['Computer Fundamentals & MS Office', 'Hardware, software, operating systems, keyboard and file management; MS Word letters, notices and reports; MS Excel data entry, SUM, AVERAGE, COUNT, COUNTA, IF, SUMIF, COUNTIF, sorting, filtering, data validation and charts; VLOOKUP/XLOOKUP and Pivot Table awareness; MS PowerPoint presentations; internet, safe browsing and professional email; PDF and digital files; cyber safety and responsible handling of information.', 'practical'],
        ['Banking Practical & Simulation Lab', 'Customer greeting and needs analysis; account-opening simulation; KYC document exercise; CASA product presentation; loan application exercise; EMI calculation worksheet; credit-card explanation role-play; digital payment safety demonstration; customer complaint simulation; CRM and Excel exercise; daily sales report exercise — with training forms, sample documents and fictitious customer records only.', 'practical'],
        ['Interview Preparation & Final Assessment', 'Resume preparation; HR interview; banking interview; group discussion; mock interviews; BFSI practical exam; soft skills and computer assessment; final written examination; viva, evaluation and certificate process.', 'project'],
    ];

    $details = <<<'HTML'
<h3>Program overview</h3>
<p>The GIMT 90 Days BFSI &amp; Soft Skills Professional Training Program helps students, freshers and job seekers build foundational banking knowledge, professional communication, customer service, sales capability and workplace readiness for entry-level opportunities in banking, financial services, insurance, NBFCs and related sectors. The curriculum covers BFSI fundamentals, banking products, KYC, loans, digital banking, sales, communication, spoken English, personality development, computer skills, practical banking simulations and interview preparation.</p>
<p><strong>Program objectives</strong></p>
<ul>
<li>Build understanding of the Indian banking and BFSI ecosystem.</li>
<li>Explain banking products, account types, KYC, loans and digital payment systems.</li>
<li>Develop communication, spoken English, confidence and professional behaviour.</li>
<li>Practise customer handling, sales conversations and complaint resolution.</li>
<li>Improve computer literacy, MS Office and Excel reporting skills.</li>
<li>Prepare candidates for resumes, interviews, assessments and workplace expectations.</li>
<li>Provide structured interview and placement assistance, subject to employer requirements and vacancies.</li>
</ul>

<h3>Course information</h3>
<table><thead><tr><th>Particulars</th><th>Details</th></tr></thead><tbody>
<tr><td>Institute</td><td>Global Institute of Management &amp; Technology (GIMT)</td></tr>
<tr><td>Program</td><td>BFSI &amp; Soft Skills Professional Training</td></tr>
<tr><td>Duration</td><td>90 Days / 3 Months</td></tr>
<tr><td>Schedule</td><td>6 days per week</td></tr>
<tr><td>Mode</td><td>Online &amp; Offline</td></tr>
<tr><td>Eligibility</td><td>10th Pass / 12th Pass / Graduate; subject to role requirements</td></tr>
<tr><td>Teaching language</td><td>Hindi &amp; English</td></tr>
<tr><td>Assessment</td><td>Written, practical, role-play and viva</td></tr>
<tr><td>Course fee</td><td>₹29,999 for the 90-day program</td></tr>
<tr><td>Certificate</td><td>GIMT institute course-completion certificate, subject to completion criteria</td></tr>
<tr><td>Placement support</td><td>Interview preparation and placement assistance; no job guarantee</td></tr>
</tbody></table>
<p><em>BFSI means Banking, Financial Services and Insurance. Securities and investment topics are introduced as financial-literacy subjects; regulated sales or advisory activity requires the applicable authorization.</em></p>

<h3>90-day academic calendar</h3>
<table><thead><tr><th>Days</th><th>Topics / learning focus</th></tr></thead><tbody>
<tr><td>1–7</td><td>Orientation; BFSI introduction; Indian banking system; RBI; bank categories; branch structure; weekly revision and test.</td></tr>
<tr><td>8–14</td><td>Savings and current accounts; salary and basic savings accounts; CASA; banking products; FD/RD; practical test.</td></tr>
<tr><td>15–21</td><td>Account-opening process; KYC; PAN/Aadhaar document awareness; due diligence; CKYC/re-KYC; AML and fraud awareness; assessment.</td></tr>
<tr><td>22–30</td><td>Loan fundamentals; personal/home/vehicle/gold/education/business loans; CIBIL; credit score; EMI; loan documentation; month-one examination.</td></tr>
<tr><td>31–37</td><td>Communication fundamentals; verbal/non-verbal communication; active listening; grammar; tenses; sentence formation; weekly test.</td></tr>
<tr><td>38–44</td><td>Daily English conversation; self-introduction; banking vocabulary; telephone communication; email writing; professional conversation; speaking test.</td></tr>
<tr><td>45–52</td><td>Personality development; confidence; positive thinking; body language; grooming; public speaking; presentation; weekly assessment.</td></tr>
<tr><td>53–60</td><td>Customer service; complaint handling; CRM; difficult customers; sales fundamentals; CASA and credit-card sales role-play; month-two examination.</td></tr>
<tr><td>61–67</td><td>Loan-processing practical; KYC simulation; account-opening simulation; digital banking; payment systems; ATM/POS awareness; practical test.</td></tr>
<tr><td>68–75</td><td>Sales pitch; lead generation; telecalling; field sales; objection handling; cross-selling; reporting; customer follow-up.</td></tr>
<tr><td>76–81</td><td>Computer fundamentals; MS Word; Excel basics and formulas; advanced Excel awareness; CRM simulation.</td></tr>
<tr><td>82–86</td><td>Resume preparation; HR interview; banking interview; group discussion; mock interview.</td></tr>
<tr><td>87–90</td><td>BFSI practical exam; soft skills and computer assessment; final written examination; viva, evaluation and certificate process.</td></tr>
</tbody></table>

<h3>Daily training timetable</h3>
<table><thead><tr><th>Time</th><th>Session</th><th>Duration</th></tr></thead><tbody>
<tr><td>10:00–10:30</td><td>English speaking and daily conversation</td><td>30 min</td></tr>
<tr><td>10:30–11:30</td><td>BFSI theory</td><td>60 min</td></tr>
<tr><td>11:30–11:45</td><td>Break</td><td>15 min</td></tr>
<tr><td>11:45–12:45</td><td>Banking practical / case study</td><td>60 min</td></tr>
<tr><td>12:45–1:30</td><td>Lunch break</td><td>45 min</td></tr>
<tr><td>1:30–2:30</td><td>Soft skills and personality development</td><td>60 min</td></tr>
<tr><td>2:30–3:15</td><td>Computer training</td><td>45 min</td></tr>
<tr><td>3:15–4:00</td><td>Role-play, assignments and assessment</td><td>45 min</td></tr>
</tbody></table>
<p><em>Class timings, session lengths and delivery mode may be adjusted to batch needs.</em></p>

<h3>Banking practical &amp; simulation lab</h3>
<p>Practical sessions use training forms, sample documents, demo systems and fictitious customer records. Students never access live bank systems or process real customer information.</p>
<table><thead><tr><th>Practical activity</th><th>Learning outcome</th></tr></thead><tbody>
<tr><td>Customer greeting and needs analysis</td><td>Professional interaction and listening</td></tr>
<tr><td>Account-opening simulation</td><td>Understanding workflow and documentation</td></tr>
<tr><td>KYC document exercise</td><td>Document-checking awareness</td></tr>
<tr><td>CASA product presentation</td><td>Product explanation and ethical sales</td></tr>
<tr><td>Loan application exercise</td><td>Understanding loan information and forms</td></tr>
<tr><td>EMI calculation worksheet</td><td>Basic financial calculations</td></tr>
<tr><td>Credit-card explanation role-play</td><td>Clear product communication</td></tr>
<tr><td>Digital payment safety demonstration</td><td>Fraud awareness and safe practices</td></tr>
<tr><td>Customer complaint simulation</td><td>Service recovery and escalation</td></tr>
<tr><td>CRM and Excel exercise</td><td>Customer record and report skills</td></tr>
<tr><td>Daily sales report exercise</td><td>Basic performance tracking</td></tr>
</tbody></table>

<h3>Training methodology</h3>
<table><thead><tr><th>Method</th><th>Share</th><th>Application</th></tr></thead><tbody>
<tr><td>Theory and concept learning</td><td>40%</td><td>Trainer-led lessons, presentations and notes</td></tr>
<tr><td>Practical exercises</td><td>30%</td><td>Worksheets, computer practice and banking simulations</td></tr>
<tr><td>Communication and role-play</td><td>20%</td><td>Customer conversations, sales pitch and group activities</td></tr>
<tr><td>Assessment and feedback</td><td>10%</td><td>Quizzes, tests, observation and improvement plans</td></tr>
</tbody></table>

<h3>Assessment &amp; evaluation</h3>
<table><thead><tr><th>Assessment component</th><th>Marks</th></tr></thead><tbody>
<tr><td>BFSI theory</td><td>20</td></tr>
<tr><td>Banking practical</td><td>20</td></tr>
<tr><td>Communication skills</td><td>15</td></tr>
<tr><td>Spoken English</td><td>10</td></tr>
<tr><td>Personality development</td><td>10</td></tr>
<tr><td>Computer skills</td><td>10</td></tr>
<tr><td>Interview performance</td><td>10</td></tr>
<tr><td>Attendance and discipline</td><td>5</td></tr>
<tr><td><strong>Total</strong></td><td><strong>100</strong></td></tr>
</tbody></table>
<table><thead><tr><th>Marks</th><th>Grade / outcome</th></tr></thead><tbody>
<tr><td>90–100</td><td>A+ – Outstanding</td></tr>
<tr><td>80–89</td><td>A – Excellent</td></tr>
<tr><td>70–79</td><td>B+ – Very Good</td></tr>
<tr><td>60–69</td><td>B – Good</td></tr>
<tr><td>50–59</td><td>C – Satisfactory</td></tr>
<tr><td>Below 50</td><td>Further improvement required</td></tr>
</tbody></table>
<p>Passing benchmark: 50% overall, together with minimum attendance and the required practical work.</p>

<h3>Career pathways</h3>
<table><thead><tr><th>Entry-level role</th><th>Indicative monthly salary range</th></tr></thead><tbody>
<tr><td>Banking Relationship Executive</td><td>₹15,000–₹25,000</td></tr>
<tr><td>CASA Sales Executive</td><td>₹12,000–₹20,000</td></tr>
<tr><td>Credit Card Sales Executive</td><td>₹12,000–₹20,000</td></tr>
<tr><td>Customer Service Executive</td><td>₹12,000–₹20,000</td></tr>
<tr><td>Loan Processing Executive</td><td>₹14,000–₹22,000</td></tr>
<tr><td>Branch Operations Support</td><td>₹14,000–₹22,000</td></tr>
<tr><td>Field Banking Executive</td><td>₹14,000–₹24,000</td></tr>
<tr><td>Back Office Executive</td><td>₹12,000–₹20,000</td></tr>
<tr><td>Insurance Sales Executive</td><td>₹12,000–₹22,000</td></tr>
<tr><td>NBFC Relationship Executive</td><td>₹15,000–₹25,000</td></tr>
</tbody></table>
<p><em>Salary figures are illustrative estimates only, not verified vacancies or guaranteed compensation. Actual pay depends on the employer, location, experience, role, incentives and employment terms.</em></p>

<h3>Placement support</h3>
<ul>
<li>Resume preparation and candidate profile creation.</li>
<li>Collection and verification of employer requirements.</li>
<li>Candidate screening and role suitability checks.</li>
<li>Mock interviews and interview scheduling.</li>
<li>Sharing verified opportunities and coordinating employer interviews.</li>
<li>Tracking interview outcomes and joining status with candidate consent.</li>
<li>Post-placement follow-up where feasible.</li>
</ul>
<p>Placement assistance does not constitute a promise or guarantee of employment.</p>

<h3>Admission</h3>
<p><strong>Documents generally required:</strong> completed admission form; educational qualification documents; identity and contact details as required by institute policy; passport-size photograph; email address and mobile number; resume, if available.</p>
<p><strong>Admission process:</strong> enquiry and counselling → course and fee explanation → application form → document verification → fee receipt → student ID → batch allocation → training commencement.</p>

<h3>Course fee — what is included</h3>
<p>Course fee: <strong>₹29,999</strong> for the 90-day program.</p>
<ul>
<li>90-day training curriculum and classroom / online sessions.</li>
<li>BFSI, banking and financial-literacy modules.</li>
<li>Soft skills, spoken English and personality development.</li>
<li>Computer and Excel training.</li>
<li>Practical worksheets and simulated banking activities.</li>
<li>Assessments, interview preparation and course-completion certificate, subject to requirements.</li>
<li>Placement assistance, without guarantee of employment.</li>
</ul>

<h3>Study material</h3>
<table><thead><tr><th>Resource</th><th>Contents</th></tr></thead><tbody>
<tr><td>Book 1 – BFSI &amp; Banking Fundamentals</td><td>Banking system, RBI, accounts, CASA, KYC, loans and financial products</td></tr>
<tr><td>Book 2 – Soft Skills &amp; Personality Development</td><td>Communication, grooming, confidence, customer service and leadership</td></tr>
<tr><td>Book 3 – Banking Practical Workbook</td><td>Sample forms, KYC exercises, loan worksheets, role-plays and simulations</td></tr>
<tr><td>Book 4 – Spoken English &amp; Communication</td><td>Grammar, vocabulary, conversation and speaking exercises</td></tr>
<tr><td>Book 5 – Banking Interview &amp; Job Preparation</td><td>Interview questions, resume guidance, HR rounds and mock tests</td></tr>
<tr><td>Assessment pack</td><td>Quizzes, monthly tests, final paper, viva and evaluation sheets</td></tr>
</tbody></table>

<h3>Training team</h3>
<table><thead><tr><th>Role</th><th>Main responsibility</th></tr></thead><tbody>
<tr><td>Academic Director</td><td>Academic oversight, curriculum approval and quality review</td></tr>
<tr><td>Program Coordinator</td><td>Batch planning, timetable, attendance and trainer coordination</td></tr>
<tr><td>BFSI Trainer</td><td>Banking, financial products, KYC, loans and banking simulations</td></tr>
<tr><td>Soft Skills Trainer</td><td>Communication, spoken English, grooming and customer service</td></tr>
<tr><td>Computer Trainer</td><td>Computer fundamentals, MS Office and Excel</td></tr>
<tr><td>Sales Trainer</td><td>Lead generation, customer acquisition and ethical sales practice</td></tr>
<tr><td>Placement Officer</td><td>Resume support, interview preparation and employer coordination</td></tr>
</tbody></table>

<h3>Facilities</h3>
<ul>
<li>Training classrooms and a computer laboratory for practical batches.</li>
<li>Projector / display, whiteboard and high-speed internet.</li>
<li>Training library with BFSI, communication and computer reference books.</li>
<li>Simulation-based banking practical lab.</li>
<li>Placement cell for interview coordination and support.</li>
</ul>

<h3>Student rules &amp; academic policies</h3>
<ul>
<li>Maintain the minimum attendance requirement (80%).</li>
<li>Attend theory, practical and assessment sessions regularly.</li>
<li>Submit assignments and complete practical work within the stated deadlines.</li>
<li>Maintain classroom discipline and respectful conduct; use mobile devices responsibly.</li>
<li>Follow professional grooming and workplace behaviour guidelines.</li>
<li>Provide accurate admission information and protect confidential data.</li>
<li>Follow the published fee, refund, grievance and re-assessment policies.</li>
<li>Do not use real customer records or unauthorized banking systems in practice.</li>
</ul>

<h3>Certificate</h3>
<p>Candidates who meet the attendance, assessment and practical requirements receive a GIMT course-completion certificate showing the issuing institute, course title, duration and completion date.</p>
HTML;

    db_transaction(function () use ($code, $name, $overview, $highlights, $careers, $eligibility, $modules, $details, $deptId, $hasDetails): void {
        $row = [
            'department_id' => $deptId, 'name' => $name, 'short_name' => 'BFSI & Soft Skills', 'slug' => 'bfsi-soft-skills-professional-training',
            'level' => 'Certificate', 'degree' => 'Certificate', 'category' => 'Management & Business',
            'duration_years' => 0.3, 'duration_label' => '90 Days (3 Months)', 'total_semesters' => 1, 'total_credits' => null,
            'fee_per_year' => 29999, 'fee_label' => '/ Course',
            'eligibility' => $eligibility, 'overview' => $overview, 'highlights' => implode("\n", $highlights), 'career_prospects' => implode("\n", $careers),
            'show_on_website' => 1, 'status' => 'active',
        ] + ($hasDetails ? ['details_html' => $details] : []);
        $id = (int) db_value('SELECT id FROM programs WHERE code = ?', [$code]);
        if ($id) {
            db_update('programs', $row, 'id = ?', [$id]);
        } else {
            $sort = (int) db_value("SELECT COALESCE(MAX(sort_order), 100) FROM programs WHERE level = 'Certificate'") + 1;
            $id = (int) db_insert('programs', $row + ['code' => $code, 'is_featured' => 1, 'is_popular' => 0, 'sort_order' => $sort]);
        }
        if (!db_value('SELECT COUNT(*) FROM semesters WHERE program_id = ? AND number = 1', [$id])) {
            db_insert('semesters', ['program_id' => $id, 'number' => 1, 'name' => 'Semester 1', 'status' => 'active']);
        }
        $codes = [];
        foreach ($modules as $i => [$mName, $topics, $type]) {
            $sCode = $code . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            $codes[] = $sCode;
            $project = $type === 'project';
            $subject = ['program_id' => $id, 'semester_no' => 1, 'name' => $mName, 'type' => $type, 'description' => $topics,
                'credits' => $project ? 1 : 2, 'max_internal' => $project ? 40 : 30, 'max_external' => $project ? 60 : 70, 'max_practical' => 0,
                'pass_marks' => 40, 'hours_per_week' => $type === 'practical' ? 4 : 3, 'status' => 'active'];
            $sid = (int) db_value('SELECT id FROM subjects WHERE code = ?', [$sCode]);
            if ($sid) {
                db_update('subjects', $subject, 'id = ?', [$sid]);
            } else {
                db_insert('subjects', $subject + ['code' => $sCode]);
            }
        }
        foreach (db_column('SELECT id FROM subjects WHERE program_id = ? AND code NOT IN (' . implode(',', array_fill(0, count($codes), '?')) . ')', array_merge([$id], $codes)) as $sid) {
            try {
                db_exec('DELETE FROM subjects WHERE id = ?', [(int) $sid]);
            } catch (Throwable $e) {
                db_exec("UPDATE subjects SET status = 'inactive' WHERE id = ?", [(int) $sid]);
            }
        }
    });
};
