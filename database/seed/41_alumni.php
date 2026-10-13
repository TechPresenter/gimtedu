<?php
/**
 * Demo data for the alumni unit (brief §23) — deterministic and re-runnable.
 *
 *   - ~810 alumni across batches 2011 → current year (programs only from the year their first batch passed out), realistic
 *     employers per industry / track, designations by seniority, Indian + overseas cities, ~90 mentors, verified flags
 *   - ~23 alumni events (homecomings, reunions, chapter meets, webinars, guest lectures) from ~3 years ago to ~2 months ahead,
 *     with registrations (attended / no-show for past events, registered for upcoming ones) — dates anchored on today
 *   - ~64 job opportunities posted by alumni over the last 6 months (pending, open, expired, closed, rejected)
 *   - 12 success stories (published / featured / on website + drafts)
 *   - ~240 donations by ~150 alumni since FY 2016-17 (received, pledged, cancelled) with receipt numbers DON-YYYY-nnnnn
 *   - ~75 mentorships pairing mentors with final-year students (active this session, completed last session, a few cancelled)
 *
 * The passed-out students (status graduated) are intentionally NOT added so "Import passed-out students" has work to do.
 *
 * Owns: alumni, alumni_events, alumni_event_registrations, alumni_jobs, alumni_success_stories, alumni_donations,
 *       alumni_mentorships (+ sequence "alumni_donation").
 */
return function (array $opts): void {
    require_once __DIR__ . '/lib/demo_data.php';
    require_once APP_ROOT . '/app/services/alumni.php';
    demo_seed(4141);

    $now = time();
    $today = date('Y-m-d', $now);
    $year = (int) date('Y', $now);
    // A batch passes out at the end of June: before July the latest batch is last year's.
    $lastBatch = (int) date('n', $now) >= 7 ? $year : $year - 1;
    $days = fn (int $d, string $time = 'H:i:s'): string => date('Y-m-d ' . $time, strtotime(($d >= 0 ? '+' : '') . $d . ' days', $now));
    $rand = fn (): float => mt_rand(0, 10000) / 10000;

    // ---------------------------------------------------------------- clear previous demo data (children first)
    foreach (['alumni_event_registrations', 'alumni_mentorships', 'alumni_donations', 'alumni_success_stories', 'alumni_jobs', 'alumni_events', 'alumni'] as $t) {
        db_exec("DELETE FROM $t");
        db_exec("ALTER TABLE $t AUTO_INCREMENT = 1");
    }
    db_exec("DELETE FROM sequences WHERE name = 'alumni_donation'");

    $adminId = (int) (db_value("SELECT id FROM users WHERE username = 'admin'") ?: db_value('SELECT MIN(id) FROM users'));
    $coordinatorId = (int) (db_value("SELECT id FROM users WHERE email = 'alumni@gimt.ac.in'") ?: $adminId);

    // ---------------------------------------------------------------- programs & tracks
    $programs = db_all('SELECT id, code, short_name, department_id FROM programs');
    if (!$programs) {
        return;
    }
    $firstBatch = ['BBA' => 2011, 'MBA' => 2011, 'BCA' => 2011, 'MCA' => 2012, 'BCOMH' => 2011, 'MCOM' => 2013, 'BTCSE' => 2015, 'BTAIDS' => 2025,
        'BSCDS' => 2023, 'DCAP' => 2011, 'CDM' => 2020, 'CDA' => 2022];
    $programWeight = ['BBA' => 18, 'MBA' => 16, 'BTCSE' => 16, 'BCA' => 13, 'MCA' => 7, 'BCOMH' => 12, 'MCOM' => 5, 'BSCDS' => 5, 'BTAIDS' => 5, 'DCAP' => 4, 'CDM' => 2, 'CDA' => 2];
    $trackOf = ['BBA' => 'mgmt', 'MBA' => 'mgmt', 'CDM' => 'mgmt', 'BCOMH' => 'com', 'MCOM' => 'com', 'BTCSE' => 'tech', 'BTAIDS' => 'tech', 'BCA' => 'tech', 'MCA' => 'tech',
        'BSCDS' => 'data', 'CDA' => 'data', 'DCAP' => 'tech'];
    $progByCode = [];
    foreach ($programs as $p) {
        $progByCode[$p['code']] = $p;
    }

    // ---------------------------------------------------------------- employers
    $companies = [
        'IT Services' => ['TCS', 'Infosys', 'Wipro', 'HCLTech', 'Tech Mahindra', 'Cognizant', 'Capgemini', 'Accenture', 'LTIMindtree', 'Coforge', 'Mphasis', 'Hexaware'],
        'Software & Product' => ['Microsoft', 'Amazon', 'Google', 'Adobe', 'Oracle', 'SAP Labs', 'Salesforce', 'Zoho', 'Freshworks', 'Paytm', 'PhonePe', 'Razorpay', 'Zomato', 'Atlassian'],
        'Consulting' => ['Deloitte', 'EY', 'KPMG', 'PwC', 'Grant Thornton Bharat', 'ZS Associates', 'Protiviti', 'BDO India'],
        'Banking & Financial Services' => ['HDFC Bank', 'ICICI Bank', 'Axis Bank', 'Kotak Mahindra Bank', 'Bajaj Finserv', 'American Express', 'Goldman Sachs', 'JP Morgan Chase', 'Yes Bank', 'HDFC Life', 'Policybazaar'],
        'Analytics & Data' => ['Mu Sigma', 'Fractal Analytics', 'Tiger Analytics', 'LatentView Analytics', 'EXL', 'Genpact', 'Nielsen'],
        'E-commerce & Retail' => ['Flipkart', 'Myntra', 'Meesho', 'Nykaa', 'Reliance Retail', 'BigBasket', 'Lenskart'],
        'FMCG' => ['Hindustan Unilever', 'ITC', 'Nestle India', 'Dabur', 'Marico', 'Britannia Industries', 'Godrej Consumer Products', 'PepsiCo India'],
        'Telecom' => ['Bharti Airtel', 'Reliance Jio', 'Vodafone Idea', 'Ericsson India'],
        'EdTech & Education' => ['upGrad', 'PhysicsWallah', 'Great Learning', 'Unacademy', 'Global Institute of Management & Technology'],
        'Healthcare & Pharma' => ['Max Healthcare', 'Fortis Healthcare', 'Apollo Hospitals', 'Sun Pharma', 'Practo'],
        'Manufacturing & Automotive' => ['Maruti Suzuki', 'Tata Motors', 'Hero MotoCorp', 'Mahindra & Mahindra', 'Samsung India', 'LG Electronics'],
        'Media & Advertising' => ['Ogilvy', 'Dentsu India', 'Times Internet', 'Network18'],
        'Government & PSU' => ['State Bank of India', 'LIC of India', 'NTPC', 'Indian Oil Corporation', 'Punjab National Bank'],
    ];
    $industryWeights = [
        'tech' => ['IT Services' => 36, 'Software & Product' => 28, 'Analytics & Data' => 8, 'Consulting' => 6, 'Banking & Financial Services' => 7, 'E-commerce & Retail' => 7, 'Telecom' => 3, 'EdTech & Education' => 3, 'Healthcare & Pharma' => 2],
        'data' => ['Analytics & Data' => 38, 'IT Services' => 18, 'Software & Product' => 12, 'Banking & Financial Services' => 12, 'Consulting' => 10, 'E-commerce & Retail' => 10],
        'mgmt' => ['Consulting' => 13, 'Banking & Financial Services' => 18, 'FMCG' => 14, 'E-commerce & Retail' => 12, 'IT Services' => 10, 'Media & Advertising' => 7, 'Telecom' => 5,
            'EdTech & Education' => 5, 'Healthcare & Pharma' => 5, 'Manufacturing & Automotive' => 7, 'Government & PSU' => 2, 'Software & Product' => 2],
        'com' => ['Banking & Financial Services' => 36, 'Consulting' => 22, 'IT Services' => 8, 'FMCG' => 7, 'Government & PSU' => 9, 'E-commerce & Retail' => 6, 'Manufacturing & Automotive' => 6, 'Healthcare & Pharma' => 3, 'EdTech & Education' => 3],
    ];
    $ladder = [
        'tech' => [['Associate Software Engineer', 'Graduate Engineer Trainee', 'Systems Engineer', 'Software Engineer Trainee', 'Junior Web Developer'],
            ['Software Engineer', 'Senior Systems Engineer', 'Full Stack Developer', 'QA Automation Engineer', 'Cloud Engineer', 'Mobile App Developer'],
            ['Senior Software Engineer', 'Technical Lead', 'DevOps Engineer', 'Product Engineer', 'Solutions Architect'],
            ['Engineering Manager', 'Principal Engineer', 'Senior Architect', 'Technical Program Manager'],
            ['Director of Engineering', 'AVP — Technology', 'Senior Engineering Manager', 'Chief Technology Officer']],
        'data' => [['Data Analyst Trainee', 'Junior Data Analyst', 'Business Intelligence Associate'],
            ['Data Analyst', 'BI Developer', 'Analytics Consultant', 'Data Engineer'],
            ['Senior Data Scientist', 'Data Scientist', 'Analytics Manager', 'ML Engineer'],
            ['Lead Data Scientist', 'Associate Director — Analytics', 'Principal Data Engineer'],
            ['Head of Data Science', 'Director — Analytics', 'VP — Data & AI']],
        'mgmt' => [['Management Trainee', 'Business Development Executive', 'Sales Executive', 'HR Executive', 'Marketing Executive'],
            ['Business Analyst', 'Assistant Manager', 'Key Account Manager', 'Marketing Manager', 'HR Business Partner', 'Consultant'],
            ['Senior Consultant', 'Product Manager', 'Brand Manager', 'Area Sales Manager', 'Manager — Strategy'],
            ['Senior Manager', 'Regional Sales Manager', 'Associate Director', 'Senior Product Manager'],
            ['Director', 'Vice President', 'General Manager', 'Head of Marketing', 'Country Manager']],
        'com' => [['Audit Associate', 'Accounts Executive', 'Tax Associate', 'Credit Analyst', 'Relationship Officer'],
            ['Assistant Manager — Finance', 'Financial Analyst', 'Senior Tax Associate', 'Relationship Manager', 'Audit Senior'],
            ['Finance Manager', 'Manager — Audit', 'Credit Manager', 'Branch Manager', 'Senior Financial Analyst'],
            ['Senior Manager — Finance', 'Assistant Vice President', 'Chief Manager', 'Financial Controller'],
            ['Vice President — Finance', 'Chief Financial Officer', 'Director — Taxation', 'Regional Head']],
    ];
    $level = fn (int $exp): int => $exp <= 1 ? 0 : ($exp <= 4 ? 1 : ($exp <= 8 ? 2 : ($exp <= 12 ? 3 : 4)));
    $universities = [['IIM Ahmedabad', 'Ahmedabad', 'India', 'MBA (PGP) candidate'], ['IIM Lucknow', 'Lucknow', 'India', 'MBA (PGP) candidate'], ['XLRI Jamshedpur', 'Jamshedpur', 'India', 'PGDM — HRM'],
        ['IIT Delhi', 'Delhi', 'India', 'M.Tech scholar'], ['Delhi School of Economics', 'Delhi', 'India', 'M.A. Economics'], ['University of Toronto', 'Toronto', 'Canada', 'MS in Computer Science'],
        ['University of Melbourne', 'Melbourne', 'Australia', 'Master of Business Analytics'], ['Arizona State University', 'Tempe', 'United States', 'MS in Information Systems'],
        ['University of Manchester', 'Manchester', 'United Kingdom', 'MSc Finance'], ['Technical University of Munich', 'Munich', 'Germany', 'M.Sc Data Engineering'],
        ['National University of Singapore', 'Singapore', 'Singapore', 'MSc Business Analytics'], ['NIT Kurukshetra', 'Kurukshetra', 'India', 'M.Tech CSE']];
    $cityWeights = ['Noida' => 14, 'Gurugram' => 14, 'Delhi' => 12, 'Bengaluru' => 14, 'Hyderabad' => 7, 'Pune' => 7, 'Mumbai' => 8, 'Chennai' => 4, 'Greater Noida' => 5,
        'Ghaziabad' => 3, 'Lucknow' => 3, 'Jaipur' => 2, 'Chandigarh' => 2, 'Kolkata' => 2, 'Ahmedabad' => 1, 'abroad' => 6];
    $abroad = [['Dubai', 'UAE'], ['Singapore', 'Singapore'], ['London', 'United Kingdom'], ['Toronto', 'Canada'], ['San Jose', 'United States'], ['Sydney', 'Australia'],
        ['Melbourne', 'Australia'], ['Frankfurt', 'Germany'], ['Seattle', 'United States']];
    $startupWords = ['Kraft', 'Nexa', 'Bharat', 'Udaan', 'Zeal', 'Pixel', 'Agri', 'Tatva', 'Shiksha', 'Swasth', 'Fin', 'Kirana', 'Yatra', 'Vriddhi'];
    $startupSuffix = ['Labs', 'Technologies', 'Ventures', 'Analytics', 'Solutions', 'Foods', 'Studio', 'Mart'];
    $areaByTrack = [
        'tech' => ['Software engineering', 'Interview preparation', 'Career guidance', 'Higher studies abroad', 'Product management'],
        'data' => ['Data science & analytics', 'Interview preparation', 'Career guidance', 'Higher studies abroad'],
        'mgmt' => ['Consulting & case prep', 'Product management', 'Digital marketing', 'Career guidance', 'Leadership & soft skills', 'Entrepreneurship & startups'],
        'com' => ['Finance & banking careers', 'Career guidance', 'Civil services & PSU exams', 'Interview preparation', 'Leadership & soft skills'],
    ];

    // ---------------------------------------------------------------- alumni
    $emails = [];
    $rows = [];
    $meta = []; // index => [track, exp, code]
    for ($y = 2011; $y <= $lastBatch; $y++) {
        $count = 28 + (int) round(($y - 2011) * 3) + mt_rand(-3, 3);
        $eligible = [];
        foreach ($programWeight as $code => $w) {
            if (isset($progByCode[$code]) && ($firstBatch[$code] ?? 2011) <= $y) {
                $eligible[$code] = $w;
            }
        }
        for ($i = 0; $i < $count; $i++) {
            $code = (string) demo_weighted($eligible);
            $prog = $progByCode[$code];
            $track = $trackOf[$code] ?? 'mgmt';
            $gender = $rand() < 0.56 ? 'male' : 'female';
            $first = demo_pick(demo_first_names($gender));
            $last = demo_pick(demo_last_names());
            $name = "$first $last";
            $domain = (string) demo_weighted(['gmail.com' => 62, 'outlook.com' => 14, 'yahoo.co.in' => 10, 'hotmail.com' => 6, 'icloud.com' => 8]);
            $email = demo_email($first, $last, $domain);
            if (isset($emails[$email])) {
                $email = demo_email($first, $last . substr((string) $y, 2), $domain);
            }
            $n = 2;
            while (isset($emails[$email])) {
                $email = demo_email($first, $last, $domain, $n++);
            }
            $emails[$email] = true;
            $exp = max(0, $year - $y);

            if ($y >= $lastBatch) {
                $status = (string) demo_weighted(['employed' => 55, 'higher_studies' => 14, 'seeking' => 26, 'other' => 5]);
            } elseif ($y >= $lastBatch - 2) {
                $status = (string) demo_weighted(['employed' => 72, 'higher_studies' => 12, 'seeking' => 9, 'self_employed' => 3, 'entrepreneur' => 3, 'other' => 1]);
            } else {
                $status = (string) demo_weighted(['employed' => 76, 'self_employed' => 6, 'entrepreneur' => 8, 'higher_studies' => 5, 'seeking' => 2, 'other' => 3]);
            }

            $cityKey = (string) demo_weighted($cityWeights);
            [$city, $country] = $cityKey === 'abroad' ? demo_pick($abroad) : [$cityKey, 'India'];
            $company = $designation = $industry = null;
            switch ($status) {
                case 'employed':
                    $industry = (string) demo_weighted($industryWeights[$track]);
                    $company = demo_pick($companies[$industry]);
                    $designation = demo_pick($ladder[$track][$level($exp)]);
                    if ($industry === 'EdTech & Education' && $company === 'Global Institute of Management & Technology') {
                        $designation = demo_pick(['Assistant Professor', 'Placement Officer', 'Lab Instructor', 'Training & Placement Coordinator']);
                    }
                    break;
                case 'self_employed':
                    if ($track === 'com') {
                        $company = $last . ' & Associates';
                        $designation = demo_pick(['Chartered Accountant (Proprietor)', 'Tax Consultant', 'Founder — Accounting Practice']);
                        $industry = 'Consulting';
                    } elseif ($track === 'mgmt') {
                        $company = demo_pick(['Independent Consultant', $last . ' Enterprises', $last . ' Trading Co.']);
                        $designation = demo_pick(['Freelance Business Consultant', 'Proprietor', 'Managing Partner']);
                        $industry = demo_pick(['Consulting', 'E-commerce & Retail']);
                    } else {
                        $company = demo_pick(['Freelance', $last . ' Digital']);
                        $designation = demo_pick(['Freelance Full Stack Developer', 'Independent Data Consultant', 'Freelance UI/UX Designer']);
                        $industry = 'Software & Product';
                    }
                    break;
                case 'entrepreneur':
                    $company = demo_pick($startupWords) . demo_pick(['ly', 'ify', 'Kart', 'Hub', 'Wise', '']) . ' ' . demo_pick($startupSuffix);
                    $designation = demo_pick(['Founder & CEO', 'Co-founder & CEO', 'Co-founder & CTO', 'Founder', 'Co-founder & COO']);
                    $industry = 'Startups';
                    break;
                case 'higher_studies':
                    [$uni, $uCity, $uCountry, $course] = demo_pick($universities);
                    $company = $uni;
                    $designation = $course;
                    $industry = 'EdTech & Education';
                    $city = $uCity;
                    $country = $uCountry;
                    break;
                case 'other':
                    $designation = $exp <= 2 ? demo_pick(['Preparing for civil services', 'Preparing for CAT', 'Preparing for GATE']) : demo_pick(['Career break', 'Family business', 'Social sector volunteer']);
                    break;
            }
            if ($status === 'employed' && $country !== 'India' && in_array($industry, ['IT Services', 'Government & PSU', 'Telecom'], true)) {
                $city = demo_pick(['Noida', 'Gurugram', 'Bengaluru']);
                $country = 'India';
            }
            $isMentor = $exp >= 4 && in_array($status, ['employed', 'entrepreneur', 'self_employed'], true) && $rand() < 0.17 ? 1 : 0;
            $alStatus = $rand() < 0.03 ? 'inactive' : 'active';
            if ($alStatus === 'inactive') {
                $isMentor = 0;
            }
            $bio = null;
            if (($isMentor || ($exp >= 6 && $rand() < 0.25)) && in_array($status, ['employed', 'entrepreneur', 'self_employed'], true)) {
                $area = demo_pick($areaByTrack[$track]);
                $bio = trim(($designation ? "$designation" : 'Professional') . ($company ? " at $company" : '') . " with {$exp}+ years of experience. "
                    . 'Passionate about ' . strtolower($area) . ' and helping GIMT students make confident career choices.');
            }
            $achievements = null;
            if ($exp >= 5 && $rand() < 0.1) {
                $achievements = demo_pick(['Featured in Economic Times 40 Under 40 (regional edition).', 'Received the company\'s "Star Performer" award twice.',
                    'Filed two patents in machine learning for document processing.', 'Led a 30-member team that delivered a national-scale digital payments rollout.',
                    'Speaker at NASSCOM Product Conclave.', 'Raised seed funding from a leading Indian angel network.', 'Cleared CFA Level III.',
                    'Mentored 50+ students through the GIMT alumni mentorship programme.']);
            }
            // Profiles are added after the batch passes out: the two latest batches over the months since, older batches in
            // their graduation half-year (so events, donations and mentoring on the profile timeline come after "profile added").
            $created = $y >= $lastBatch - 1
                ? demo_datetime($y . '-07-01 10:00:00', date('Y-m-d H:i:s', $now - 86400 * 3))
                : demo_datetime($y . '-07-01 10:00:00', date('Y-m-d H:i:s', min(strtotime($y . '-12-31 18:00:00'), $now - 86400 * 20)));
            $slug = strtolower(preg_replace('/[^a-z]+/i', '-', $name));
            $rows[] = [
                'student_id' => null, 'name' => $name, 'email' => $email, 'phone' => $rand() < 0.88 ? demo_phone() : null, 'photo' => null, 'gender' => $gender,
                'batch_year' => $y, 'program_id' => (int) $prog['id'], 'department_id' => $prog['department_id'] ? (int) $prog['department_id'] : null,
                'company' => $company, 'designation' => $designation, 'industry' => $industry, 'city' => $city, 'country' => $country,
                'linkedin_url' => $rand() < 0.66 ? 'https://www.linkedin.com/in/' . trim($slug, '-') . '-' . mt_rand(1000, 9999) : null,
                'employment_status' => $status, 'is_mentor' => $isMentor, 'is_verified' => $rand() < 0.78 ? 1 : 0, 'show_on_website' => $isMentor && $rand() < 0.3 ? 1 : 0,
                'bio' => $bio, 'achievements' => $achievements, 'status' => $alStatus, 'created_at' => $created,
            ];
            $meta[] = ['track' => $track, 'exp' => $exp, 'code' => $code];
        }
    }
    demo_bulk_insert('alumni', $rows);
    $alumni = db_all('SELECT id, name, email, batch_year, program_id, company, designation, industry, city, country, employment_status, is_mentor, status, created_at FROM alumni ORDER BY id');
    foreach ($alumni as $i => &$a) {
        $a += $meta[$i];
    }
    unset($a);
    $active = array_values(array_filter($alumni, fn ($a) => $a['status'] === 'active'));
    $mentors = array_values(array_filter($active, fn ($a) => (int) $a['is_mentor'] === 1));
    $working = array_values(array_filter($active, fn ($a) => in_array($a['employment_status'], ['employed', 'entrepreneur', 'self_employed'], true) && $a['company']));

    // ---------------------------------------------------------------- events + registrations
    $eventDefs = [
        // [title ({Y} = event year), type, offset days, hour, venue, mode, capacity, organiser]
        ['Alumni Homecoming {Y}', 'homecoming', -1030, '10:00', 'GIMT Main Auditorium, Greater Noida', 'in_person', 600, 'Alumni Relations Cell'],
        ['Batch of {Y-10} Decennial Reunion', 'reunion', -975, '17:00', 'GIMT Convention Hall', 'in_person', 150, 'Batch of {Y-10} reunion committee'],
        ['Bengaluru Chapter Meet {Y}', 'chapter_meet', -905, '18:30', 'Hotel Royal Orchid, Bengaluru', 'in_person', 120, 'GIMT Alumni — Bengaluru Chapter'],
        ['Webinar: Breaking into Product Management', 'webinar', -840, '19:00', 'Zoom', 'online', 500, 'Alumni Relations Cell'],
        ['Alumni Homecoming {Y}', 'homecoming', -665, '10:00', 'GIMT Main Auditorium, Greater Noida', 'in_person', 700, 'Alumni Relations Cell'],
        ['Gurugram Networking Evening', 'networking', -600, '18:30', 'Cyber Hub, Gurugram', 'in_person', 100, 'GIMT Alumni — NCR Chapter'],
        ['Webinar: Careers in Data Science & AI', 'webinar', -535, '19:00', 'Google Meet', 'online', 600, 'Department of Computer Science & Engineering'],
        ['Batch of {Y-10} Decennial Reunion', 'reunion', -430, '17:00', 'GIMT Convention Hall', 'in_person', 160, 'Batch of {Y-10} reunion committee'],
        ['Guest Lecture: Building a Startup for Bharat', 'guest_lecture', -395, '11:00', 'Seminar Hall 2 + YouTube Live', 'hybrid', 250, 'GIMT Incubation Centre'],
        ['Dubai Chapter Meet {Y}', 'chapter_meet', -340, '19:30', 'Hotel Rove Downtown, Dubai', 'in_person', 60, 'GIMT Alumni — Middle East Chapter'],
        ['Alumni Homecoming {Y}', 'homecoming', -298, '10:00', 'GIMT Main Auditorium, Greater Noida', 'in_person', 800, 'Alumni Relations Cell'],
        ['Mentorship Programme Kick-off {Y}', 'mentorship', -268, '15:00', 'Board Room + Microsoft Teams', 'hybrid', 200, 'Training & Placement Cell'],
        ['Webinar: Cracking Big 4 Interviews', 'webinar', -212, '19:00', 'Zoom', 'online', 400, 'Department of Commerce'],
        ['Pune Chapter Meet {Y}', 'chapter_meet', -142, '18:30', 'The Westin, Pune', 'in_person', 80, 'GIMT Alumni — West Chapter'],
        ['Hyderabad Chapter Meet {Y}', 'chapter_meet', -114, '18:30', 'Novotel HICC, Hyderabad', 'in_person', 80, 'GIMT Alumni — South Chapter'],
        ['Webinar: Higher Studies Abroad — Admissions & Scholarships', 'webinar', -93, '19:00', 'Zoom', 'online', 500, 'Alumni Relations Cell'],
        ['Batch of {Y-10} Reunion', 'reunion', -44, '17:00', 'GIMT Convention Hall', 'in_person', 150, 'Batch of {Y-10} reunion committee'],
        ['Alumni Talk: Life at a Global Bank', 'guest_lecture', -23, '11:00', 'Seminar Hall 1 + YouTube Live', 'hybrid', 250, 'Department of Management Studies'],
        ['Webinar: AI Careers for Non-Engineers', 'webinar', 5, '18:30', 'Zoom', 'online', 500, 'Alumni Relations Cell'],
        ['Mentorship Mid-term Meet', 'mentorship', 16, '15:00', 'Board Room + Microsoft Teams', 'hybrid', 150, 'Training & Placement Cell'],
        ['Mumbai Chapter Meet {Y}', 'chapter_meet', 27, '18:30', 'Trident, Bandra Kurla Complex, Mumbai', 'in_person', 100, 'GIMT Alumni — West Chapter'],
        ['Batch of {Y-5} Five-Year Reunion', 'reunion', 40, '17:00', 'GIMT Convention Hall', 'in_person', 180, 'Batch of {Y-5} reunion committee'],
        ['Alumni Homecoming {Y} — Silver Jubilee', 'homecoming', 68, '10:00', 'GIMT Main Auditorium, Greater Noida', 'in_person', 900, 'Alumni Relations Cell'],
    ];
    $descriptions = [
        'homecoming' => '<p>Our annual homecoming brings together alumni from every batch for a day of nostalgia, networking and celebration.</p><ul><li>Campus walk and lab visits</li><li>Distinguished Alumni Awards</li><li>Panel: "Careers that didn\'t exist ten years ago"</li><li>Cultural evening and dinner</li></ul>',
        'reunion' => '<p>A reunion for the batch to reconnect with classmates and faculty mentors.</p><ul><li>Welcome high tea with faculty</li><li>Batch photograph at the main lawn</li><li>Dinner and memories session</li></ul>',
        'chapter_meet' => '<p>An evening meet-up for alumni living and working in the city — meet fellow GIMTians, share opportunities and hear what is new on campus.</p>',
        'webinar' => '<p>An interactive online session led by alumni practitioners, followed by a live Q&amp;A for students and young alumni.</p>',
        'networking' => '<p>Informal networking evening for alumni across industries. Bring your business cards!</p>',
        'guest_lecture' => '<p>A guest lecture by an accomplished alumnus for current students, open to alumni on campus and online.</p>',
        'mentorship' => '<p>Session for alumni mentors and their student mentees — goal setting, progress review and matching for the new cohort.</p>',
    ];
    $images = ['homecoming' => 'assets/images/site/graduation.jpg', 'reunion' => 'assets/images/site/students-lawn.jpg', 'chapter_meet' => 'assets/images/site/news-campus.jpg',
        'webinar' => 'assets/images/site/students-laptop.jpg', 'networking' => 'assets/images/site/students-atrium.jpg', 'guest_lecture' => 'assets/images/site/facility-classroom.jpg',
        'mentorship' => 'assets/images/site/placement-student.jpg'];
    $events = [];
    foreach ($eventDefs as $idx => [$title, $type, $offset, $hour, $venue, $mode, $cap, $org]) {
        $start = date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days', $now)) . ' ' . $hour . ':00';
        $ey = (int) substr($start, 0, 4);
        $fill = fn (string $s) => str_replace(['{Y}', '{Y-10}', '{Y-5}'], [(string) $ey, (string) ($ey - 10), (string) ($ey - 5)], $s);
        $durationH = in_array($type, ['homecoming'], true) ? 9 : (in_array($type, ['webinar', 'guest_lecture', 'mentorship'], true) ? 2 : 4);
        $status = $offset < 0 ? 'completed' : 'upcoming';
        if ($title === 'Hyderabad Chapter Meet {Y}') {
            $status = 'cancelled';
        }
        $events[] = [
            'title' => $fill($title), 'type' => $type, 'description' => $descriptions[$type] ?? null, 'event_date' => $start,
            'end_date' => date('Y-m-d H:i:s', strtotime($start) + $durationH * 3600), 'venue' => $venue, 'mode' => $mode,
            'image' => $images[$type] ?? null, 'registration_link' => $mode !== 'in_person' ? 'https://meet.gimt.ac.in/alumni/' . ($idx + 101) : null,
            'capacity' => $cap, 'organiser' => $fill($org), 'attendees_count' => 0, 'status' => $status, 'created_by' => $coordinatorId,
            'created_at' => date('Y-m-d H:i:s', min(strtotime($start) - 86400 * mt_rand(25, 45), $now - 86400 * mt_rand(2, 6) - 3600 * mt_rand(1, 9))),
        ];
    }
    demo_bulk_insert('alumni_events', $events);
    $eventRows = db_all('SELECT id, title, type, event_date, venue, mode, capacity, status, created_at FROM alumni_events ORDER BY id');
    $regs = [];
    $byBatch = [];
    $byCity = [];
    foreach ($active as $a) {
        $byBatch[(int) $a['batch_year']][] = $a;
        $byCity[(string) $a['city']][] = $a;
    }
    foreach ($eventRows as $e) {
        $pool = $active;
        if ($e['type'] === 'reunion' && preg_match('/Batch of (\d{4})/', $e['title'], $mm)) {
            $pool = $byBatch[(int) $mm[1]] ?? $active;
        } elseif ($e['type'] === 'chapter_meet') {
            foreach (['Bengaluru', 'Dubai', 'Pune', 'Hyderabad', 'Mumbai'] as $c) {
                if (str_contains($e['title'], $c)) {
                    $pool = array_merge($byCity[$c] ?? [], array_slice($byCity['Gurugram'] ?? [], 0, 10));
                }
            }
        } elseif ($e['type'] === 'mentorship') {
            $pool = $mentors ?: $active;
        }
        if (!$pool) {
            continue;
        }
        $past = strtotime($e['event_date']) < $now;
        $target = $e['mode'] === 'online' ? mt_rand(60, 160) : ($e['type'] === 'homecoming' ? mt_rand(110, 190) : mt_rand(20, 70));
        if ($e['type'] === 'reunion' || $e['type'] === 'chapter_meet') {
            $target = (int) round(count($pool) * (mt_rand(45, 75) / 100));
        }
        if (!$past) {
            $target = (int) round($target * (strtotime($e['event_date']) - $now < 86400 * 20 ? 0.75 : 0.45));
        }
        $target = max(3, min($target, count($pool), (int) $e['capacity']));
        $picked = [];
        $keys = array_keys($pool);
        $guard = 0;
        while (count($picked) < $target && $guard++ < $target * 20) {
            $a = $pool[$keys[mt_rand(0, count($keys) - 1)]];
            if (strtotime($a['batch_year'] . '-06-30') > strtotime($e['event_date']) || strtotime($a['created_at']) > strtotime($e['event_date']) - 86400 * 2) {
                continue;
            }
            $picked[(int) $a['id']] = $a;
        }
        foreach ($picked as $aid => $a) {
            if ($e['status'] === 'cancelled') {
                $st = 'cancelled';
            } elseif ($past) {
                $st = (string) demo_weighted(['attended' => 76, 'no_show' => 18, 'cancelled' => 6]);
            } else {
                $st = $rand() < 0.04 ? 'cancelled' : 'registered';
            }
            $openFrom = max(strtotime($e['created_at']), strtotime($a['created_at'])) + 3600;
            $regAt = date('Y-m-d H:i:s', $past
                ? max($openFrom, strtotime($e['event_date']) - 86400 * mt_rand(1, 24) - mt_rand(0, 80000))
                : mt_rand(min($openFrom, $now - 7200), $now - 3600));
            $regs[] = ['event_id' => (int) $e['id'], 'alumni_id' => $aid, 'status' => $st, 'guests' => $e['type'] === 'homecoming' && $rand() < 0.3 ? mt_rand(1, 2) : 0,
                'registered_at' => $regAt, 'remarks' => null, 'created_by' => null];
        }
    }
    demo_bulk_insert('alumni_event_registrations', $regs);
    db_exec("UPDATE alumni_events e SET attendees_count = (SELECT COUNT(*) FROM alumni_event_registrations r WHERE r.event_id = e.id AND r.status = 'attended') WHERE e.status = 'completed'");

    // ---------------------------------------------------------------- job opportunities
    $roles = [
        'tech' => [['Associate Software Engineer', 'Java, Spring Boot, SQL, Git', 'Freshers', '₹4.5–6.5 LPA'], ['Frontend Developer (React)', 'React, TypeScript, Tailwind CSS, REST APIs', '1–3 years', '₹7–12 LPA'],
            ['Java Backend Developer', 'Java 17, Spring Boot, Microservices, Kafka', '2–4 years', '₹10–16 LPA'], ['QA Automation Engineer', 'Selenium, Java, TestNG, CI/CD', '1–3 years', '₹6–9 LPA'],
            ['Cloud Support Associate', 'AWS, Linux, Networking basics', 'Freshers', '₹5–7 LPA'], ['DevOps Engineer', 'Docker, Kubernetes, Terraform, AWS', '2–5 years', '₹12–20 LPA'],
            ['Software Engineering Intern', 'DSA, Python or Java, Git', 'Students (final year)', '₹30,000/month']],
        'data' => [['Data Analyst', 'SQL, Excel, Power BI, Python', '0–2 years', '₹5–8 LPA'], ['Data Engineer', 'Python, Spark, Airflow, SQL', '2–4 years', '₹12–18 LPA'],
            ['ML Engineer Intern', 'Python, scikit-learn, PyTorch', 'Students (final year)', '₹35,000/month'], ['Business Intelligence Associate', 'Tableau, SQL, Excel', 'Freshers', '₹4.5–6 LPA']],
        'mgmt' => [['Management Trainee — Sales', 'Communication, negotiation, MS Excel', 'Freshers', '₹4–6 LPA'], ['Business Development Associate', 'B2B sales, CRM, communication', '0–2 years', '₹5–7 LPA'],
            ['HR Executive', 'Recruitment, HRMS, employee engagement', '1–3 years', '₹4–6 LPA'], ['Digital Marketing Executive', 'SEO, Google Ads, Meta Ads, analytics', '0–2 years', '₹3.6–5.5 LPA'],
            ['Product Analyst', 'SQL, product metrics, A/B testing', '1–3 years', '₹9–14 LPA'], ['Marketing Intern', 'Content, social media, Canva', 'Students (final year)', '₹15,000/month'],
            ['Key Account Manager', 'Account management, B2B sales', '3–5 years', '₹10–14 LPA']],
        'com' => [['Audit Associate', 'Statutory audit, Ind AS, Excel', 'Freshers', '₹4–5.5 LPA'], ['Tax Analyst', 'GST, direct tax, TDS returns', '0–2 years', '₹4.5–6.5 LPA'],
            ['Credit Analyst', 'Financial statement analysis, credit appraisal', '1–3 years', '₹6–9 LPA'], ['Accounts Executive', 'Tally, GST, reconciliation', 'Freshers', '₹3–4.5 LPA'],
            ['Relationship Officer', 'Customer handling, banking products', '0–2 years', '₹3.5–5 LPA'], ['Financial Analyst', 'Financial modelling, MIS, Excel', '2–4 years', '₹8–12 LPA']],
    ];
    $posters = array_values(array_filter($working, fn ($a) => $a['exp'] >= 2 && $a['employment_status'] === 'employed' && $a['company'] !== 'Global Institute of Management & Technology'));
    $posterMentors = array_values(array_filter($posters, fn ($a) => (int) $a['is_mentor'] === 1));
    $jobs = [];
    for ($i = 0; $i < 64; $i++) {
        $p = $posterMentors && $rand() < 0.35 ? demo_pick($posterMentors) : demo_pick($posters);
        [$title, $skills, $expText, $salary] = demo_pick($roles[$p['track']] ?? $roles['mgmt']);
        $createdDays = $i < 6 ? mt_rand(0, 5) : ($i < 32 ? mt_rand(6, 38) : mt_rand(39, 180));
        $created = date('Y-m-d H:i:s', $now - $createdDays * 86400 - mt_rand(0, 70000));
        $last = date('Y-m-d', strtotime($created) + 86400 * mt_rand(20, 45));
        $type = str_contains($title, 'Intern') ? 'internship' : (string) demo_weighted(['full_time' => 82, 'contract' => 6, 'remote' => 8, 'part_time' => 4]);
        if ($createdDays <= 5) {
            $status = $rand() < 0.75 ? 'pending' : 'open';
        } elseif ($last >= $today) {
            $status = $rand() < 0.88 ? 'open' : 'closed';
        } else {
            $status = (string) demo_weighted(['closed' => 76, 'open' => 12, 'rejected' => 12]);
        }
        $location = $type === 'remote' ? 'Remote (India)' : (($p['country'] ?? 'India') !== 'India' ? 'Gurugram' : $p['city']);
        $approved = in_array($status, ['open', 'closed', 'rejected'], true) ? date('Y-m-d H:i:s', strtotime($created) + mt_rand(3600 * 3, 86400 * 2)) : null;
        $desc = '<p>' . e($p['company']) . ' is hiring for the role of <strong>' . e($title) . '</strong>' . ($location ? ' in ' . e($location) : '') . '. Shared by ' . e($p['name'])
            . ' (Batch of ' . $p['batch_year'] . ') for GIMT students and alumni.</p><p><strong>What you will do</strong></p><ul><li>Work with a cross-functional team on day-to-day deliverables</li>'
            . '<li>Own tasks end-to-end and report progress to the team lead</li><li>Learn the company\'s tools, processes and quality standards</li></ul>'
            . '<p><strong>What we are looking for</strong></p><ul><li>' . e($skills) . '</li><li>Good communication and a willingness to learn</li></ul>'
            . '<p>Mention "GIMT alumni referral" in your application to be fast-tracked.</p>';
        $jobs[] = [
            'posted_by_alumni_id' => (int) $p['id'], 'company' => $p['company'], 'title' => $title, 'location' => $location, 'job_type' => $type, 'experience' => $expText,
            'salary_range' => $salary, 'skills' => $skills, 'description' => $desc,
            'apply_link' => $rand() < 0.55 ? 'https://www.linkedin.com/jobs/view/' . mt_rand(3800000000, 3999999999) : null,
            'contact_email' => $p['email'], 'last_date' => $last, 'status' => $status,
            'approved_by' => $approved ? $coordinatorId : null, 'approved_at' => $approved,
            'rejection_reason' => $status === 'rejected' ? demo_pick(['Duplicate of an existing post.', 'Role requires 8+ years of experience — not suitable for students.', 'Incomplete details; asked the alumni to repost with the job description.']) : null,
            'closed_at' => $status === 'closed' ? date('Y-m-d H:i:s', min($now - 3600, strtotime($last . ' 18:00:00'))) : null,
            'created_by' => $coordinatorId, 'created_at' => $created,
        ];
    }
    usort($jobs, fn ($x, $y) => strcmp($x['created_at'], $y['created_at']));
    demo_bulk_insert('alumni_jobs', $jobs);

    // ---------------------------------------------------------------- success stories
    $storyTemplates = [
        ['tech', 'From campus coder to {designation} at {company}', 'GIMT taught me to build things that matter — the hackathons and the coding club were where I truly learned to engineer.',
            '<p>{first} joined GIMT\'s {program} programme with a second-hand laptop and a lot of curiosity. Within the first year {pronoun} was leading the campus coding club and shipping small projects for local businesses.</p><p>Today {first} is <strong>{designation} at {company}</strong>, leading a team that builds systems used by millions of people every day.</p><blockquote>"The faculty pushed us to solve real problems, not just pass exams. That mindset is what interviewers notice."</blockquote><p>{first} now mentors final-year students on system design and interview preparation through the GIMT alumni mentorship programme.</p>'],
        ['data', 'Turning data into decisions at {company}', 'The live analytics projects in my final year were my first real portfolio — they opened every door after that.',
            '<p>Batch of {batch} graduate {first} discovered analytics during a semester project on retail footfall data. That project became a capstone, then an internship, then a career.</p><p>As <strong>{designation} at {company}</strong>, {first} helps business leaders make faster, data-backed decisions.</p><ul><li>Built forecasting models used across 20+ business units</li><li>Speaks regularly at analytics meet-ups in {city}</li></ul>'],
        ['mgmt', 'Leading growth for one of India\'s best-known brands', 'Case competitions and the summer internship at GIMT gave me the confidence to walk into any boardroom.',
            '<p>{first} graduated from the {program} programme in {batch} and started as a management trainee. A decade of hard work later, {pronoun} is <strong>{designation} at {company}</strong>.</p><p>{first} credits the case-study pedagogy and industry projects at GIMT for building strong fundamentals in strategy and consumer insight.</p><p>"Say yes to every live project — it is where you learn how businesses really work."</p>'],
        ['com', 'Building a career in finance, one audit at a time', 'The faculty\'s focus on accounting standards and practical taxation set me apart in my very first job.',
            '<p>{first} chose GIMT\'s {program} for its strong commerce faculty and practical approach to accounting and taxation.</p><p>Now <strong>{designation} at {company}</strong>, {first} oversees financial reporting and compliance for large clients and regularly hires GIMT interns.</p>'],
        ['mgmt', 'From the classroom to founding a startup', 'The incubation centre gave me my first mentors and my first customers — GIMT believed in the idea before anyone else did.',
            '<p>{first} started working on a business idea in the second year at GIMT and incubated it at the campus incubation centre after graduating in {batch}.</p><p>As <strong>{designation} of {company}</strong>, {first} now employs over 40 people and has hired several GIMT graduates.</p>'],
        ['tech', 'Engineering at scale — and giving back', 'Every summer I come back to campus to run a system-design bootcamp. It is my way of saying thank you.',
            '<p>{first} ({program}, {batch}) is <strong>{designation} at {company}</strong>, working on large-scale distributed systems.</p><p>Beyond work, {first} runs an annual bootcamp for GIMT students on cloud computing and system design, and has referred more than 25 juniors for internships.</p>'],
        ['com', 'Banking on fundamentals', 'GIMT made me comfortable with numbers and confident with people — banking needs both.',
            '<p>After completing {program} in {batch}, {first} joined banking as a relationship officer. Steady performance and continuous learning took {object} to the role of <strong>{designation} at {company}</strong>.</p><p>{first} is an active mentor in the GIMT network for students interested in banking and financial services.</p>'],
        ['data', 'Making AI practical for Indian businesses', 'The best thing GIMT gave me was the habit of learning something new every single week.',
            '<p>{first} graduated in {batch} and quickly moved from analytics into machine learning. As <strong>{designation} at {company}</strong>, {first} builds AI solutions for banking and retail clients.</p><p>{first} recently delivered a guest lecture on responsible AI to GIMT students.</p>'],
        ['mgmt', 'People first: a journey in HR leadership', 'My professors taught me that organisations are, above all, about people.',
            '<p>{first} specialised in human resources during the {program} programme and today is <strong>{designation} at {company}</strong>.</p><p>{first} has led campus hiring for the company at several institutes and returns to GIMT every year to conduct mock interviews.</p>'],
        ['tech', 'Shipping products used across India', 'Final-year projects at GIMT were treated like real products — that discipline stayed with me.',
            '<p>{first} ({program}, Batch of {batch}) is <strong>{designation} at {company}</strong>, building consumer products used across the country.</p><p>{first}\'s advice to juniors: "Contribute to open source and build in public — your GitHub is your resume."</p>'],
        ['com', 'A global career in financial services', 'GIMT\'s international exposure programme gave me the confidence to work with teams across continents.',
            '<p>{first} graduated in {batch} and now works as <strong>{designation} at {company}</strong> in {city}, partnering with teams across Asia and Europe.</p>'],
        ['mgmt', 'Consulting the country\'s biggest companies', 'Live consulting projects with local MSMEs during my MBA were the best preparation for consulting.',
            '<p>{first} ({program}, {batch}) is <strong>{designation} at {company}</strong>, advising leading Indian companies on strategy and transformation.</p><p>{first} mentors GIMT students preparing for consulting case interviews.</p>'],
    ];
    $storyImages = ['assets/images/site/graduation.jpg', 'assets/images/site/placement-student.jpg', 'assets/images/site/students-laptop.jpg', 'assets/images/site/students-atrium.jpg',
        'assets/images/site/students-lawn.jpg', 'assets/images/site/hero-campus-students.jpg', 'assets/images/site/news-campus.jpg', 'assets/images/site/campus-library.jpg',
        'assets/images/site/facility-labs.jpg', 'assets/images/site/campus-main.jpg', 'assets/images/site/students-laptop.jpg', 'assets/images/site/graduation.jpg'];
    $progShort = db_pairs('SELECT id, short_name FROM programs');
    $used = [];
    $stories = [];
    foreach ($storyTemplates as $i => [$track, $title, $excerpt, $body]) {
        $founder = str_contains($title, 'startup');
        $cands = array_values(array_filter($working, function ($a) use ($track, $used, $founder) {
            $trackOk = $a['track'] === $track || ($track === 'data' && $a['track'] === 'tech');
            return $trackOk && !isset($used[$a['id']]) && ($founder ? $a['employment_status'] === 'entrepreneur' : $a['employment_status'] === 'employed' && $a['exp'] >= 5);
        }));
        if (!$cands) {
            $cands = array_values(array_filter($working, fn ($a) => !isset($used[$a['id']]) && $a['exp'] >= 4));
        }
        if (!$cands) {
            continue;
        }
        usort($cands, fn ($x, $y) => $y['exp'] <=> $x['exp']);
        $a = $cands[mt_rand(0, min(count($cands) - 1, 25))];
        $used[$a['id']] = true;
        $first = explode(' ', $a['name'])[0];
        $female = in_array($first, demo_first_names('female'), true);
        $vars = ['{first}' => $first, '{designation}' => $a['designation'], '{company}' => $a['company'], '{program}' => $progShort[$a['program_id']] ?? 'GIMT',
            '{batch}' => $a['batch_year'], '{city}' => $a['city'], '{pronoun}' => $female ? 'she' : 'he', '{object}' => $female ? 'her' : 'him'];
        $status = $i < 10 ? 'published' : 'draft';
        $pub = $status === 'published' ? date('Y-m-d H:i:s', $now - 86400 * (20 + $i * 23) - mt_rand(0, 40000)) : null;
        $stories[] = [
            'alumni_id' => (int) $a['id'], 'title' => strtr($title, $vars), 'company' => $a['company'], 'designation' => $a['designation'], 'excerpt' => $excerpt,
            'story' => strtr($body, $vars), 'image' => $storyImages[$i], 'is_featured' => $i < 4 ? 1 : 0, 'show_on_website' => $status === 'published' && $i !== 6 && $i !== 9 ? 1 : 0,
            'status' => $status, 'sort_order' => $i < 4 ? $i + 1 : 10, 'published_at' => $pub, 'created_by' => $coordinatorId,
            'created_at' => $pub ? date('Y-m-d H:i:s', strtotime($pub) - 86400 * 3) : date('Y-m-d H:i:s', $now - 86400 * mt_rand(2, 9)),
        ];
    }
    demo_bulk_insert('alumni_success_stories', $stories);

    // ---------------------------------------------------------------- donations
    $purposeWeights = ['Scholarship Fund' => 30, 'Infrastructure Development' => 14, 'Library & Learning Resources' => 10, 'Research & Innovation' => 8, 'Sports & Wellness' => 7,
        'Student Welfare Fund' => 12, 'Alumni Endowment' => 6, 'Incubation & Startups' => 6, 'General Development Fund' => 7];
    $donorPool = array_values(array_filter($working, fn ($a) => $a['exp'] >= 3));
    usort($donorPool, fn ($x, $y) => $y['exp'] <=> $x['exp']);
    $donors = [];
    foreach ($donorPool as $a) {
        if (count($donors) >= 150) {
            break;
        }
        if ($rand() < ($a['exp'] >= 10 ? 0.55 : 0.3)) {
            $donors[] = $a;
        }
    }
    $fyNow = alumni_fy_start_year($today);
    $donations = [];
    foreach ($donors as $a) {
        $times = (int) demo_weighted([1 => 45, 2 => 30, 3 => 17, 4 => 8]);
        $earliest = max(strtotime('2016-04-01'), strtotime(($a['batch_year'] + 2) . '-07-01'), strtotime($a['created_at']) + 86400);
        for ($k = 0; $k < $times; $k++) {
            // pick a financial year — later years weigh more (the network and giving culture grew); the current FY by elapsed share
            $fyWeights = [];
            for ($fy = max(2016, alumni_fy_start_year(date('Y-m-d', $earliest))); $fy <= $fyNow; $fy++) {
                $w = 10 + ($fy - 2016) * 7;
                if ($fy === $fyNow) {
                    $w = (int) round($w * max(0.1, ($now - strtotime($fyNow . '-04-01')) / (365 * 86400)) * 1.15);
                }
                $fyWeights[$fy] = max(1, $w);
            }
            $pickFy = (int) demo_weighted($fyWeights);
            $from = max($earliest, strtotime($pickFy . '-04-01'));
            $to = min($now - 86400, strtotime(($pickFy + 1) . '-03-31'));
            $date = date('Y-m-d', mt_rand(min($from, $to), $to));
            $tier = $a['exp'] >= 10 ? demo_weighted(['s' => 30, 'm' => 38, 'l' => 22, 'xl' => 10]) : demo_weighted(['s' => 52, 'm' => 36, 'l' => 12]);
            $amount = match ($tier) {
                's' => mt_rand(4, 20) * 500,
                'm' => mt_rand(11, 50) * 1000,
                'l' => mt_rand(11, 40) * 5000,
                default => mt_rand(5, 20) * 50000,
            };
            $purpose = (string) demo_weighted($purposeWeights);
            $dTs = strtotime($date);
            $m = (int) date('n', $dTs);
            $dy = (int) date('Y', $dTs);
            $campaign = null;
            if ($dy === 2020 || ($dy === 2021 && $m <= 6)) {
                $campaign = $rand() < 0.6 ? 'COVID-19 Student Relief Fund' : null;
                if ($campaign) {
                    $purpose = 'Student Welfare Fund';
                }
            } elseif ($m === 12 || ($m === 1 && $rand() < 0.5)) {
                $campaign = $rand() < 0.7 ? 'Homecoming ' . ($m === 1 ? $dy - 1 : $dy) . ' Scholarship Drive' : null;
                if ($campaign) {
                    $purpose = 'Scholarship Fund';
                }
            } elseif ($purpose === 'Library & Learning Resources' && $rand() < 0.6) {
                $campaign = 'Library Modernisation Drive ' . $dy;
            } elseif ($purpose === 'Infrastructure Development' && $rand() < 0.5) {
                $campaign = demo_pick(['Smart Classroom Fund', 'New Academic Block Campaign']);
            } elseif ($purpose === 'Incubation & Startups' && $rand() < 0.5) {
                $campaign = 'GIMT Startup Seed Fund';
            }
            $mode = $dy < 2017 ? (string) demo_weighted(['neft' => 45, 'cheque' => 40, 'cash' => 15]) : (string) demo_weighted(['upi' => 36, 'neft' => 28, 'cheque' => 10, 'card' => 8, 'online' => 12, 'cash' => 6]);
            if ($mode === 'cash' && $amount > 20000) {
                $mode = 'neft';
            }
            $ref = match ($mode) {
                'upi' => (string) mt_rand(100000, 999999) . str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT),
                'neft' => demo_pick(['HDFCN', 'ICICN', 'SBINN', 'UTIBN', 'KKBKN']) . $dy . str_pad((string) mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT),
                'cheque' => str_pad((string) mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT) . ' / ' . demo_pick(['HDFC Bank', 'SBI', 'ICICI Bank', 'Axis Bank', 'PNB']),
                'card', 'online' => 'pay_' . substr(strtoupper(md5($a['id'] . $date . $k . $amount)), 0, 14),
                default => null,
            };
            $status = 'received';
            if ($dTs >= $now - 86400 * 90 && $rand() < 0.35) {
                $status = 'pledged';
            } elseif ($rand() < 0.02) {
                $status = 'cancelled';
            }
            $pan = null;
            if ($amount >= 10000 && $rand() < 0.55) {
                $parts = explode(' ', $a['name']);
                $pan = chr(mt_rand(65, 90)) . chr(mt_rand(65, 90)) . chr(mt_rand(65, 90)) . 'P' . strtoupper(substr(end($parts), 0, 1)) . str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT) . chr(mt_rand(65, 90));
            }
            $donations[] = [
                'alumni_id' => (int) $a['id'], 'amount' => $amount, 'purpose' => $purpose, 'campaign' => $campaign, 'donation_date' => $date,
                'payment_mode' => $mode, 'reference_no' => $status === 'pledged' ? null : $ref, 'pan_no' => $pan, 'is_anonymous' => $rand() < 0.04 ? 1 : 0, 'status' => $status,
                'remarks' => $status === 'cancelled' ? 'Cancelled: payment bounced / reversed by bank' : ($status === 'pledged' ? 'Pledged at ' . demo_pick(['the reunion', 'the chapter meet', 'homecoming', 'a phone call with the alumni cell']) : null),
                'created_by' => $coordinatorId, 'created_at' => $date . ' ' . sprintf('%02d:%02d:00', mt_rand(9, 18), mt_rand(0, 59)),
            ];
        }
    }
    usort($donations, fn ($x, $y) => strcmp($x['created_at'], $y['created_at']));
    // Keep a realistic follow-up list: a few recent gifts are still pledges (promised at events / calls, not yet paid).
    $openPledges = count(array_filter($donations, fn ($d) => $d['status'] === 'pledged'));
    $recentFrom = date('Y-m-d', $now - 86400 * 75);
    $alt = 0;
    foreach ($donations as &$d) {
        if ($openPledges >= 5) {
            break;
        }
        if ($d['status'] === 'received' && $d['donation_date'] >= $recentFrom && $d['payment_mode'] !== 'cash' && $alt++ % 2 === 0) {
            $d['status'] = 'pledged';
            $d['reference_no'] = null;
            $d['remarks'] = 'Pledged at ' . demo_pick(['the reunion', 'the chapter meet', 'homecoming', 'a phone call with the alumni cell']);
            $openPledges++;
        }
    }
    unset($d);
    foreach ($donations as $n => &$d) {
        $d = ['receipt_no' => 'DON-' . substr($d['donation_date'], 0, 4) . '-' . str_pad((string) ($n + 1), 5, '0', STR_PAD_LEFT)] + $d;
    }
    unset($d);
    demo_bulk_insert('alumni_donations', $donations);
    db_exec('INSERT INTO sequences (name, current_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE current_value = VALUES(current_value)', ['alumni_donation', count($donations)]);

    // ---------------------------------------------------------------- mentorships
    $students = db_all(
        "SELECT s.id, s.program_id, s.current_semester, p.code FROM students s JOIN programs p ON p.id = s.program_id
         WHERE s.status = 'active' AND ((p.level = 'UG' AND s.current_semester >= 5) OR (p.level = 'PG' AND s.current_semester >= 3))
         ORDER BY s.id"
    );
    $session = db_row('SELECT * FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1');
    $sessionStart = $session['start_date'] ?? date('Y-m-d', strtotime('-90 days', $now));
    $mentorRows = [];
    $load = [];
    if ($mentors && $students) {
        shuffle($students);
        $pickedStudents = array_slice($students, 0, 75);
        foreach ($pickedStudents as $i => $s) {
            $sTrack = $trackOf[$s['code']] ?? 'mgmt';
            $cands = array_values(array_filter($mentors, fn ($m) => ($load[$m['id']] ?? 0) < 4 && ($m['track'] === $sTrack || ($sTrack === 'data' && $m['track'] === 'tech') || $rand() < 0.08)));
            if (!$cands) {
                $cands = array_values(array_filter($mentors, fn ($m) => ($load[$m['id']] ?? 0) < 4));
            }
            if (!$cands) {
                break;
            }
            $m = demo_pick($cands);
            $load[$m['id']] = ($load[$m['id']] ?? 0) + 1;
            $status = $i < 52 ? 'active' : ($i < 70 ? 'completed' : 'cancelled');
            if ($status === 'active') {
                $start = demo_date(max($sessionStart, date('Y-m-d', strtotime('-100 days', $now))), date('Y-m-d', strtotime('-6 days', $now)));
                $end = null;
                $sessions = max(1, (int) floor((($now - strtotime($start)) / 86400) / 14) + mt_rand(-1, 1));
            } else {
                $start = demo_date(date('Y-m-d', strtotime('-420 days', $now)), date('Y-m-d', strtotime('-300 days', $now)));
                $end = date('Y-m-d', strtotime($start) + 86400 * mt_rand(90, 200));
                $sessions = $status === 'completed' ? mt_rand(6, 12) : mt_rand(1, 3);
            }
            $area = demo_pick($areaByTrack[$sTrack] ?? $areaByTrack['mgmt']);
            $mentorRows[] = [
                'alumni_id' => (int) $m['id'], 'student_id' => (int) $s['id'], 'area' => $area, 'start_date' => $start, 'end_date' => $end, 'sessions_count' => $sessions, 'status' => $status,
                'notes' => $status === 'cancelled' ? 'Mentor relocated abroad — student to be re-matched.' : demo_pick([
                    'Monthly 1:1 call; working on resume and LinkedIn profile.', 'Goal: internship by the end of the semester.', 'Mock interviews every fortnight.',
                    'Reviewing final-year project scope and industry relevance.', 'Exploring higher studies options and scholarship timelines.', null]),
                'created_by' => $coordinatorId, 'created_at' => $start . ' 11:00:00',
            ];
        }
    }
    demo_bulk_insert('alumni_mentorships', $mentorRows);

    // Alumni the office actively works with (mentors, donors, job posters, story subjects) have verified profiles; the
    // unverified ones are recent additions / registrations awaiting review.
    db_exec("UPDATE alumni SET is_verified = 1
             WHERE is_mentor = 1 OR id IN (SELECT alumni_id FROM alumni_donations) OR id IN (SELECT alumni_id FROM alumni_success_stories)
                OR id IN (SELECT posted_by_alumni_id FROM alumni_jobs WHERE posted_by_alumni_id IS NOT NULL)
                OR id IN (SELECT alumni_id FROM alumni_mentorships)");
};
