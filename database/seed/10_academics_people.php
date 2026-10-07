<?php
/**
 * Demo seed: departments, programs, courses, semesters, subjects, batches, classrooms,
 * sections, faculty, staff, faculty-subject assignments and demo role users.
 */
require_once __DIR__ . '/lib/demo_data.php';

return function (array $opts): void {
    demo_seed(20261);
    $now = date('Y-m-d H:i:s');
    $sessionId = (int) db_value("SELECT id FROM academic_sessions WHERE name = '2026-27'");

    // ---------------- Departments ----------------
    $departments = [
        ['Department of Management Studies', 'MGT', 'mgt@gimt.ac.in', 2008, 'Business administration, finance, marketing and HR programs with strong industry exposure.'],
        ['Department of Computer Science & Engineering', 'CSE', 'cse@gimt.ac.in', 2010, 'Engineering programs in computer science, AI and data science with modern labs.'],
        ['Department of Computer Applications', 'DCA', 'dca@gimt.ac.in', 2009, 'BCA, MCA and diploma programs in software development and applications.'],
        ['Department of Commerce', 'COM', 'commerce@gimt.ac.in', 2008, 'B.Com (Honours) and M.Com programs in accounting, taxation and finance.'],
        ['Department of Sciences', 'SCI', 'science@gimt.ac.in', 2014, 'Science programs focusing on data science, mathematics and statistics.'],
        ['School of Professional & Skill Development', 'PSD', 'skills@gimt.ac.in', 2018, 'Short-term certificate programs in digital marketing, analytics and soft skills.'],
    ];
    $deptIds = [];
    foreach ($departments as [$name, $code, $email, $year, $desc]) {
        $deptIds[$code] = db_insert('departments', ['name' => $name, 'code' => $code, 'email' => $email, 'phone' => '+91 120 45678' . mt_rand(10, 99), 'established_year' => $year, 'description' => $desc, 'status' => 'active']);
    }

    // ---------------- Programs ----------------
    $programs = [
        // code, dept, name, short, level, degree, category, years, sems, fee, intake, featured, popular, image, duration_label, fee_label
        ['BBA', 'MGT', 'Bachelor of Business Administration', 'BBA', 'UG', "Bachelor's Degree", 'Management', 3, 6, 120000, 120, 1, 0, 'assets/images/site/program-bba.jpg', '3 Years', '/ Year'],
        ['MBA', 'MGT', 'Master of Business Administration', 'MBA', 'PG', "Master's Degree", 'Management', 2, 4, 250000, 120, 1, 1, 'assets/images/site/program-mba.jpg', '2 Years', '/ Year'],
        ['BTCSE', 'CSE', 'B.Tech in Computer Science & Engineering', 'B.Tech (CSE)', 'UG', "Bachelor's Degree", 'Technology', 4, 8, 180000, 120, 1, 1, 'assets/images/site/program-btech.jpg', '4 Years', '/ Year'],
        ['BTAIDS', 'CSE', 'B.Tech in Artificial Intelligence & Data Science', 'B.Tech (AI & DS)', 'UG', "Bachelor's Degree", 'Technology', 4, 8, 195000, 60, 1, 0, 'assets/images/site/facility-labs.jpg', '4 Years', '/ Year'],
        ['BCA', 'DCA', 'Bachelor of Computer Applications', 'BCA', 'UG', "Bachelor's Degree", 'Computer Applications', 3, 6, 95000, 120, 1, 0, 'assets/images/site/students-laptop.jpg', '3 Years', '/ Year'],
        ['MCA', 'DCA', 'Master of Computer Applications', 'MCA', 'PG', "Master's Degree", 'Computer Applications', 2, 4, 140000, 60, 0, 0, 'assets/images/site/facility-classroom.jpg', '2 Years', '/ Year'],
        ['BCOMH', 'COM', 'B.Com (Honours)', 'B.Com (Hons)', 'UG', "Bachelor's Degree", 'Commerce', 3, 6, 90000, 120, 1, 0, 'assets/images/site/program-bcom.jpg', '3 Years', '/ Year'],
        ['MCOM', 'COM', 'Master of Commerce', 'M.Com', 'PG', "Master's Degree", 'Commerce', 2, 4, 80000, 60, 0, 0, 'assets/images/site/students-atrium.jpg', '2 Years', '/ Year'],
        ['BSCDS', 'SCI', 'B.Sc in Data Science', 'B.Sc (Data Science)', 'UG', "Bachelor's Degree", 'Science', 3, 6, 85000, 60, 0, 0, 'assets/images/site/facility-library.jpg', '3 Years', '/ Year'],
        ['DCAP', 'DCA', 'Diploma in Computer Applications', 'DCA', 'Diploma', 'Diploma', 'Diploma', 1, 2, 45000, 60, 1, 0, 'assets/images/site/program-dca.jpg', '1 Year', '/ Year'],
        ['CDM', 'PSD', 'Certificate in Digital Marketing', 'Digital Marketing', 'Certificate', 'Certificate', 'Certificate', 0.5, 1, 25000, 40, 1, 0, 'assets/images/site/program-digital-marketing.jpg', '6 Months', '/ Course'],
        ['CDA', 'PSD', 'Certificate in Data Analytics', 'Data Analytics', 'Certificate', 'Certificate', 'Certificate', 0.5, 1, 30000, 40, 0, 0, 'assets/images/site/students-lawn.jpg', '6 Months', '/ Course'],
    ];
    $overviews = [
        'BBA' => 'Build leadership skills with a strong foundation in management and business fundamentals.',
        'MBA' => 'Advance your career with industry-focused specializations and real-world learning.',
        'BTCSE' => 'Develop technical expertise in the latest technologies and innovation.',
        'BTAIDS' => 'Master machine learning, deep learning and big data engineering for the AI era.',
        'BCA' => 'Learn programming, databases, web and mobile development with hands-on projects.',
        'MCA' => 'Advanced software engineering, cloud computing and enterprise application development.',
        'BCOMH' => 'Gain in-depth knowledge in commerce, finance and business accounting.',
        'MCOM' => 'Specialize in advanced accounting, taxation, auditing and financial management.',
        'BSCDS' => 'Combine statistics, mathematics and programming to turn data into decisions.',
        'DCAP' => 'Practical training in computer applications and digital tools.',
        'CDM' => 'Learn in-demand digital marketing skills with hands-on industry projects.',
        'CDA' => 'Excel, SQL, Power BI and Python for business analytics in just six months.',
    ];
    $eligibility = [
        'UG' => '10+2 or equivalent from a recognised board with minimum 50% aggregate marks (45% for reserved categories).',
        'PG' => "Bachelor's degree in any discipline from a recognised university with minimum 50% marks. Valid CAT/MAT/CMAT score preferred.",
        'Diploma' => '10+2 in any stream from a recognised board.',
        'Certificate' => '10+2 or graduation in any discipline. Working professionals are welcome.',
    ];
    $programIds = [];
    $programMeta = [];
    foreach ($programs as $i => [$code, $dept, $name, $short, $level, $degree, $cat, $years, $sems, $fee, $intake, $feat, $pop, $img, $dur, $feeLabel]) {
        $programIds[$code] = db_insert('programs', [
            'department_id' => $deptIds[$dept], 'name' => $name, 'short_name' => $short, 'code' => $code, 'slug' => slugify($short === 'DCA' ? 'diploma-in-computer-applications' : $name),
            'level' => $level, 'degree' => $degree, 'category' => $cat, 'duration_years' => $years, 'duration_label' => $dur, 'total_semesters' => $sems,
            'total_credits' => $sems * 22, 'intake_capacity' => $intake, 'fee_per_year' => $fee, 'fee_label' => $feeLabel,
            'eligibility' => $eligibility[$level], 'overview' => $overviews[$code],
            'highlights' => "Industry-aligned curriculum designed with recruiters\nLive projects, internships and industrial visits\nCertifications and skill labs included\nDedicated placement and career support",
            'career_prospects' => $cat === 'Management' ? "Business Analyst\nMarketing Manager\nHR Executive\nEntrepreneur\nOperations Manager"
                : ($cat === 'Commerce' ? "Chartered Accountancy\nFinancial Analyst\nTax Consultant\nBanking & Insurance\nAuditor"
                : "Software Engineer\nData Analyst\nCloud Engineer\nFull Stack Developer\nAI/ML Engineer"),
            'image' => $img, 'is_featured' => $feat, 'is_popular' => $pop, 'show_on_website' => 1, 'sort_order' => $i, 'status' => 'active',
        ]);
        $programMeta[$code] = ['id' => $programIds[$code], 'sems' => $sems, 'years' => $years, 'dept' => $deptIds[$dept], 'level' => $level, 'fee' => $fee, 'short' => $short];
        for ($s = 1; $s <= $sems; $s++) {
            db_insert('semesters', ['program_id' => $programIds[$code], 'number' => $s, 'name' => 'Semester ' . $s, 'status' => 'active']);
        }
    }

    // ---------------- Courses (specializations) ----------------
    $courses = [
        ['MBA', 'MBA - Finance', 'MBA-FIN'], ['MBA', 'MBA - Marketing', 'MBA-MKT'], ['MBA', 'MBA - Human Resource Management', 'MBA-HRM'], ['MBA', 'MBA - Business Analytics', 'MBA-BA'],
        ['BBA', 'BBA - General Management', 'BBA-GEN'], ['BBA', 'BBA - Digital Business', 'BBA-DB'],
        ['BTCSE', 'B.Tech CSE - Cloud Computing', 'CSE-CC'], ['BTCSE', 'B.Tech CSE - Cyber Security', 'CSE-CS'],
        ['BCOMH', 'B.Com - Accounting & Taxation', 'BCOM-AT'], ['MCA', 'MCA - Full Stack Development', 'MCA-FS'],
    ];
    $courseIds = [];
    foreach ($courses as [$p, $n, $c]) {
        $courseIds[$p][] = db_insert('courses', ['program_id' => $programIds[$p], 'name' => $n, 'code' => $c, 'status' => 'active']);
    }

    // ---------------- Subjects ----------------
    $catalog = [
        'BBA' => [
            1 => ['Principles of Management', 'Business Economics', 'Financial Accounting', 'Business Communication', 'Business Mathematics', 'Computer Applications in Business'],
            2 => ['Organisational Behaviour', 'Macro Economics', 'Cost Accounting', 'Business Statistics', 'Environmental Studies', 'Business Law'],
            3 => ['Marketing Management', 'Human Resource Management', 'Financial Management', 'Production & Operations Management', 'Indian Economy', 'Research Methodology'],
            4 => ['Consumer Behaviour', 'Management Accounting', 'Business Ethics & CSR', 'Entrepreneurship Development', 'E-Commerce', 'Summer Training Project'],
            5 => ['Strategic Management', 'International Business', 'Digital Marketing', 'Investment Management', 'Supply Chain Management', 'Retail Management'],
            6 => ['Project Management', 'Business Analytics', 'Corporate Governance', 'Startup Ecosystem', 'Banking & Insurance', 'Major Project & Viva'],
        ],
        'MBA' => [
            1 => ['Management Process & Organisational Behaviour', 'Managerial Economics', 'Accounting for Managers', 'Business Statistics & Analytics', 'Marketing Management', 'Business Communication'],
            2 => ['Financial Management', 'Human Resource Management', 'Operations Management', 'Research Methodology', 'Legal Aspects of Business', 'Management Information Systems'],
            3 => ['Strategic Management', 'Security Analysis & Portfolio Management', 'Digital & Social Media Marketing', 'Talent Management', 'Business Analytics with Python', 'Summer Internship Project'],
            4 => ['International Business Management', 'Entrepreneurship & Innovation', 'Corporate Finance', 'Brand Management', 'Leadership & Change Management', 'Dissertation'],
        ],
        'BTCSE' => [
            1 => ['Engineering Mathematics I', 'Engineering Physics', 'Programming for Problem Solving (C)', 'Basic Electrical Engineering', 'Engineering Graphics', 'Programming Lab'],
            2 => ['Engineering Mathematics II', 'Engineering Chemistry', 'Data Structures', 'Digital Electronics', 'Communication Skills', 'Data Structures Lab'],
            3 => ['Discrete Mathematics', 'Object Oriented Programming (Java)', 'Computer Organization & Architecture', 'Database Management Systems', 'Operating Systems', 'DBMS Lab'],
            4 => ['Design & Analysis of Algorithms', 'Theory of Computation', 'Computer Networks', 'Software Engineering', 'Web Technologies', 'Web Technologies Lab'],
            5 => ['Compiler Design', 'Artificial Intelligence', 'Machine Learning', 'Cloud Computing', 'Information Security', 'Machine Learning Lab'],
            6 => ['Deep Learning', 'Big Data Analytics', 'Internet of Things', 'Mobile Application Development', 'DevOps', 'Mini Project'],
            7 => ['Natural Language Processing', 'Blockchain Technology', 'Distributed Systems', 'Cyber Security', 'Industrial Training', 'Project Phase I'],
            8 => ['Quantum Computing Fundamentals', 'Professional Ethics', 'Elective: Computer Vision', 'Elective: Edge AI', 'Seminar', 'Project Phase II'],
        ],
        'BCA' => [
            1 => ['Fundamentals of Computers', 'Programming in C', 'Mathematics for Computing', 'Digital Logic', 'English Communication', 'C Programming Lab'],
            2 => ['Data Structures using C', 'Object Oriented Programming with C++', 'Discrete Mathematics', 'Computer Organization', 'Environmental Science', 'C++ Lab'],
            3 => ['Database Management Systems', 'Java Programming', 'Operating Systems', 'Software Engineering', 'Statistics', 'Java Lab'],
            4 => ['Web Development (HTML, CSS, JS)', 'Python Programming', 'Computer Networks', 'Design of Algorithms', 'Numerical Methods', 'Python Lab'],
            5 => ['PHP & MySQL', 'Mobile App Development', 'Cloud Fundamentals', 'Data Mining', 'Information Security', 'Minor Project'],
            6 => ['React & Modern Frontend', 'Machine Learning Basics', 'E-Commerce Systems', 'Software Testing', 'Internship', 'Major Project'],
        ],
        'BCOMH' => [
            1 => ['Financial Accounting', 'Business Organisation & Management', 'Micro Economics', 'Business Mathematics', 'Business Communication', 'Computer Applications'],
            2 => ['Corporate Accounting', 'Business Law', 'Macro Economics', 'Business Statistics', 'Environmental Studies', 'E-Commerce'],
            3 => ['Cost Accounting', 'Company Law', 'Income Tax Law & Practice', 'Indian Economy', 'Banking & Insurance', 'Tally ERP Lab'],
            4 => ['Management Accounting', 'Goods & Services Tax', 'Financial Markets', 'Human Resource Management', 'Entrepreneurship', 'Research Project'],
            5 => ['Auditing & Assurance', 'Financial Management', 'International Business', 'Investment Analysis', 'Marketing Management', 'Internship'],
            6 => ['Corporate Tax Planning', 'Financial Reporting', 'Business Ethics', 'Fintech Fundamentals', 'Strategic Cost Management', 'Major Project'],
        ],
    ];
    $generic = ['Foundations of %s', 'Applied %s', 'Advanced %s', '%s Lab', 'Research Methods in %s', 'Professional Communication'];
    $topics = ['MCA' => 'Software Systems', 'BTAIDS' => 'Data Science', 'MCOM' => 'Accounting', 'BSCDS' => 'Statistics', 'DCAP' => 'Computer Applications', 'CDM' => 'Digital Marketing', 'CDA' => 'Data Analytics'];
    $subjectIds = [];
    foreach ($programMeta as $code => $pm) {
        for ($s = 1; $s <= $pm['sems']; $s++) {
            $names = $catalog[$code][$s] ?? array_map(fn ($g) => sprintf($g, $topics[$code] ?? 'Management') . ($s > 1 ? ' ' . ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII'][$s] : ''), $generic);
            if (in_array($code, ['CDM', 'CDA'], true)) {
                $names = $code === 'CDM'
                    ? ['Digital Marketing Fundamentals', 'Search Engine Optimization', 'Social Media Marketing', 'Google Ads & PPC', 'Content & Email Marketing', 'Capstone Project']
                    : ['Excel for Business Analytics', 'SQL for Data Analysis', 'Power BI Dashboards', 'Python for Analytics', 'Statistics for Decision Making', 'Capstone Project'];
            }
            foreach ($names as $k => $subj) {
                $isLab = (bool) preg_match('/Lab|Project|Training|Internship|Seminar|Viva|Dissertation/i', $subj);
                $scode = $code . $s . str_pad((string) ($k + 1), 2, '0', STR_PAD_LEFT);
                $subjectIds[$code][$s][] = db_insert('subjects', [
                    'program_id' => $pm['id'], 'semester_no' => $s, 'name' => $subj, 'code' => $scode,
                    'type' => $isLab ? (stripos($subj, 'Lab') !== false ? 'lab' : 'project') : 'theory',
                    'credits' => $isLab ? 2 : 4, 'max_internal' => $isLab ? 40 : 30, 'max_external' => $isLab ? 60 : 70, 'max_practical' => 0,
                    'pass_marks' => 40, 'hours_per_week' => $isLab ? 2 : 4, 'status' => 'active',
                ]);
            }
        }
    }

    // ---------------- Batches ----------------
    $batchIds = [];
    foreach ($programMeta as $code => $pm) {
        $yearsActive = max(1, (int) ceil($pm['years']));
        for ($y = 2026 - $yearsActive + 1; $y <= 2026; $y++) {
            $end = $y + max(1, (int) ceil($pm['years']));
            $batchIds[$code][$y] = db_insert('batches', ['program_id' => $pm['id'], 'name' => $pm['short'] . ' ' . $y . '-' . $end, 'start_year' => $y, 'end_year' => $end, 'status' => 'active']);
        }
    }

    // ---------------- Classrooms ----------------
    $roomIds = [];
    $blocks = ['A' => 'Academic Block A', 'B' => 'Academic Block B', 'C' => 'Technology Block'];
    foreach ($blocks as $b => $building) {
        for ($f = 1; $f <= 3; $f++) {
            for ($r = 1; $r <= 4; $r++) {
                $code = $b . $f . '0' . $r;
                $roomIds[] = db_insert('classrooms', ['name' => 'Room ' . $code, 'code' => $code, 'building' => $building, 'floor' => (string) $f, 'capacity' => 60, 'type' => 'classroom', 'facilities' => 'Smart board, Projector, AC']);
            }
        }
    }
    foreach ([['Computer Lab 1', 'LAB1', 'Technology Block', 40, 'lab'], ['Computer Lab 2', 'LAB2', 'Technology Block', 40, 'lab'], ['AI & Data Science Lab', 'LAB3', 'Technology Block', 36, 'lab'],
              ['Electronics Lab', 'LAB4', 'Technology Block', 30, 'lab'], ['Seminar Hall', 'SEM1', 'Academic Block A', 150, 'seminar_hall'], ['Main Auditorium', 'AUD1', 'Main Building', 600, 'auditorium'],
              ['Examination Hall 1', 'EXH1', 'Academic Block B', 120, 'exam_hall'], ['Examination Hall 2', 'EXH2', 'Academic Block B', 120, 'exam_hall']] as [$n, $c, $bld, $cap, $t]) {
        $roomIds[] = db_insert('classrooms', ['name' => $n, 'code' => $c, 'building' => $bld, 'floor' => 'G', 'capacity' => $cap, 'type' => $t, 'facilities' => 'Projector, AC']);
    }

    // ---------------- Faculty (52) ----------------
    $designations = ['Professor' => 6, 'Associate Professor' => 12, 'Assistant Professor' => 30, 'Lecturer' => 4];
    $deptQuota = ['MGT' => 13, 'CSE' => 13, 'DCA' => 9, 'COM' => 8, 'SCI' => 5, 'PSD' => 4];
    $qual = ['MGT' => ['Ph.D. (Management), MBA', 'MBA (Finance), UGC-NET', 'Ph.D. (Marketing), MBA'], 'CSE' => ['Ph.D. (CSE), M.Tech', 'M.Tech (CSE)', 'Ph.D. (AI), M.Tech'],
        'DCA' => ['MCA, UGC-NET', 'Ph.D. (Computer Applications)', 'M.Tech (IT)'], 'COM' => ['Ph.D. (Commerce), M.Com', 'M.Com, CA', 'M.Com, UGC-NET'],
        'SCI' => ['Ph.D. (Statistics)', 'M.Sc (Mathematics), UGC-NET'], 'PSD' => ['MBA (Marketing), Google Certified', 'M.Sc (Data Science)']];
    $spec = ['MGT' => ['Finance', 'Marketing', 'Human Resources', 'Strategy', 'Operations', 'Business Analytics'], 'CSE' => ['Machine Learning', 'Cloud Computing', 'Cyber Security', 'Data Structures', 'Computer Networks', 'Software Engineering'],
        'DCA' => ['Web Technologies', 'Database Systems', 'Mobile Computing', 'Programming Languages'], 'COM' => ['Taxation', 'Accounting', 'Banking', 'Financial Markets'],
        'SCI' => ['Statistics', 'Applied Mathematics'], 'PSD' => ['Digital Marketing', 'Data Analytics']];
    $designationPool = [];
    foreach ($designations as $d => $n) {
        $designationPool = array_merge($designationPool, array_fill(0, $n, $d));
    }
    shuffle($designationPool);
    $facultyIds = [];
    $facultyByDept = [];
    $n = 0;
    $titles = ['Dr.', 'Prof.', 'Mr.', 'Ms.'];
    foreach ($deptQuota as $dcode => $count) {
        for ($i = 0; $i < $count; $i++) {
            $gender = mt_rand(0, 100) < 45 ? 'female' : 'male';
            $first = demo_pick(demo_first_names($gender));
            $last = demo_pick(demo_last_names());
            $desig = $designationPool[$n] ?? 'Assistant Professor';
            $title = in_array($desig, ['Professor', 'Associate Professor'], true) ? 'Dr.' : ($gender === 'female' ? 'Ms.' : 'Mr.');
            [$city, $state] = demo_city();
            $n++;
            $id = db_insert('faculty', [
                'employee_id' => 'GIMT-F' . str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'title' => $title, 'first_name' => $first, 'last_name' => $last, 'gender' => $gender,
                'dob' => demo_date('1965-01-01', '1992-12-31'), 'designation' => $desig, 'department_id' => $deptIds[$dcode],
                'qualification' => demo_pick($qual[$dcode]), 'specialization' => demo_pick($spec[$dcode]), 'experience_years' => mt_rand(3, 28),
                'email' => strtolower($first . '.' . $last . $n) . '@gimt.ac.in', 'phone' => demo_phone(), 'address' => mt_rand(10, 999) . ', Sector ' . mt_rand(1, 150),
                'city' => $city, 'state' => $state, 'joining_date' => demo_date('2009-07-01', '2026-07-15'),
                'employment_type' => demo_weighted(['permanent' => 80, 'contract' => 12, 'visiting' => 8]), 'salary' => ['Professor' => 185000, 'Associate Professor' => 135000, 'Assistant Professor' => 85000, 'Lecturer' => 55000][$desig] + mt_rand(0, 20) * 1000,
                'bio' => "$title $first $last is a $desig in the " . array_search($deptIds[$dcode], $deptIds, true) . ' department with expertise in ' . demo_pick($spec[$dcode]) . '.',
                'publications_count' => mt_rand(0, 40), 'show_on_website' => 1, 'status' => $n === 7 ? 'on_leave' : 'active',
            ]);
            $facultyIds[] = $id;
            $facultyByDept[$dcode][] = $id;
        }
    }
    // HODs
    foreach ($facultyByDept as $dcode => $ids) {
        db_update('departments', ['hod_faculty_id' => $ids[0]], 'id = ?', [$deptIds[$dcode]]);
        db_update('faculty', ['designation' => 'Professor & Head', 'title' => 'Dr.'], 'id = ?', [$ids[0]]);
    }

    // ---------------- Staff (30) ----------------
    $staffRoles = [
        ['Registrar', 'administration'], ['Administrative Officer', 'administration'], ['Office Assistant', 'administration'], ['Office Assistant', 'administration'], ['Receptionist', 'administration'],
        ['Accounts Officer', 'accounts'], ['Accountant', 'accounts'], ['Accounts Assistant', 'accounts'], ['Chief Librarian', 'library'], ['Library Assistant', 'library'],
        ['Boys Hostel Warden', 'hostel'], ['Girls Hostel Warden', 'hostel'], ['Mess Supervisor', 'hostel'], ['Transport In-charge', 'transport'], ['Transport Supervisor', 'transport'],
        ['System Administrator', 'it'], ['IT Support Engineer', 'it'], ['Lab Assistant', 'laboratory'], ['Lab Assistant', 'laboratory'], ['Lab Technician', 'laboratory'],
        ['Maintenance Supervisor', 'maintenance'], ['Electrician', 'maintenance'], ['Security Supervisor', 'security'], ['Security Guard', 'security'], ['Security Guard', 'security'],
        ['Placement Coordinator', 'administration'], ['Admission Counsellor', 'administration'], ['Admission Counsellor', 'administration'], ['Exam Cell Assistant', 'administration'], ['Alumni Relations Executive', 'administration'],
    ];
    $staffIds = [];
    foreach ($staffRoles as $i => [$desig, $cat]) {
        $gender = str_contains($desig, 'Girls') || mt_rand(0, 100) < 30 ? 'female' : 'male';
        $first = demo_pick(demo_first_names($gender));
        $last = demo_pick(demo_last_names());
        [$city, $state] = demo_city();
        $staffIds[$desig][] = db_insert('staff', [
            'employee_id' => 'GIMT-S' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT), 'first_name' => $first, 'last_name' => $last, 'gender' => $gender,
            'dob' => demo_date('1970-01-01', '1998-12-31'), 'designation' => $desig, 'category' => $cat, 'qualification' => demo_pick(['Graduate', 'Post Graduate', 'Diploma', 'MBA', 'B.Com', 'BCA']),
            'email' => strtolower($first . '.' . $last . 's' . ($i + 1)) . '@gimt.ac.in', 'phone' => demo_phone(), 'city' => $city, 'state' => $state,
            'joining_date' => demo_date('2010-01-01', '2026-06-30'), 'employment_type' => 'permanent', 'salary' => mt_rand(18, 75) * 1000, 'status' => 'active',
        ]);
    }

    // ---------------- Sections (current session) ----------------
    $sectionIds = [];
    foreach ($programMeta as $code => $pm) {
        $yearsActive = max(1, (int) ceil($pm['years']));
        for ($k = 0; $k < $yearsActive; $k++) {
            $sem = $pm['sems'] === 1 ? 1 : min($pm['sems'], 1 + 2 * $k);
            $startYear = 2026 - $k;
            $names = in_array($code, ['BBA', 'BTCSE', 'MBA'], true) ? ['A', 'B'] : ['A'];
            foreach ($names as $sec) {
                $sectionIds[$code][$sem][] = db_insert('sections', [
                    'program_id' => $pm['id'], 'batch_id' => $batchIds[$code][$startYear] ?? null, 'semester_no' => $sem, 'academic_session_id' => $sessionId,
                    'name' => $sec, 'capacity' => 60, 'classroom_id' => demo_pick($roomIds), 'class_teacher_id' => demo_pick($facultyByDept[array_search($pm['dept'], $deptIds, true)]),
                ]);
            }
        }
    }

    // ---------------- Faculty-subject assignments (current semesters) ----------------
    foreach ($sectionIds as $code => $bySem) {
        $dcode = array_search($programMeta[$code]['dept'], $deptIds, true);
        foreach ($bySem as $sem => $secs) {
            foreach ($secs as $secId) {
                foreach ($subjectIds[$code][$sem] ?? [] as $sid) {
                    db_exec('INSERT IGNORE INTO faculty_subjects (faculty_id, subject_id, section_id, academic_session_id) VALUES (?, ?, ?, ?)',
                        [demo_pick($facultyByDept[$dcode]), $sid, $secId, $sessionId]);
                }
            }
        }
    }

    // ---------------- Demo users for each role ----------------
    $demoUsers = [
        ['Rajesh Khanna', 'administrator', 'administrator', 'admin.office@gimt.ac.in', 'Administrator'],
        ['Pooja Malhotra', 'admissions', 'admission-officer', 'admissions@gimt.ac.in', 'Admission Officer'],
        ['Dr. Sunil Mehta', 'academics', 'academic-admin', 'academics@gimt.ac.in', 'Dean Academics'],
        ['Ravi Agarwal', 'accounts', 'accountant', 'accounts@gimt.ac.in', 'Accounts Officer'],
        ['Dr. Kavita Rao', 'exams', 'exam-controller', 'exams@gimt.ac.in', 'Controller of Examinations'],
        ['Meena Iyer', 'library', 'librarian', 'library@gimt.ac.in', 'Chief Librarian'],
        ['Suresh Yadav', 'hostel', 'hostel-warden', 'hostel@gimt.ac.in', 'Chief Warden'],
        ['Mahesh Tyagi', 'transport', 'transport-manager', 'transport@gimt.ac.in', 'Transport Manager'],
        ['Neha Kapoor', 'placement', 'placement-officer', 'placement@gimt.ac.in', 'Training & Placement Officer'],
        ['Arjun Bhatia', 'alumni', 'alumni-coordinator', 'alumni@gimt.ac.in', 'Alumni Relations'],
        ['Simran Sethi', 'content', 'content-manager', 'content@gimt.ac.in', 'Content Manager'],
        ['Office Staff', 'staff', 'staff', 'staff@gimt.ac.in', 'Office Assistant'],
    ];
    $hash = password_hash('Demo@12345', PASSWORD_DEFAULT);
    foreach ($demoUsers as [$name, $username, $role, $email, $desig]) {
        $uid = db_insert('users', ['name' => $name, 'username' => $username, 'email' => $email, 'phone' => demo_phone(), 'password_hash' => $hash, 'designation' => $desig, 'status' => 'active', 'password_changed_at' => $now]);
        db_exec('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?', [$uid, $role]);
    }
    // Faculty login linked to the first CSE faculty member
    $f = db_row('SELECT * FROM faculty WHERE id = ?', [$facultyByDept['CSE'][1]]);
    $uid = db_insert('users', ['name' => trim($f['title'] . ' ' . $f['first_name'] . ' ' . $f['last_name']), 'username' => 'faculty', 'email' => 'faculty@gimt.ac.in', 'phone' => $f['phone'],
        'password_hash' => $hash, 'designation' => $f['designation'], 'department_id' => $f['department_id'], 'faculty_id' => $f['id'], 'status' => 'active', 'password_changed_at' => $now]);
    db_exec('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?', [$uid, 'faculty']);
    db_update('faculty', ['user_id' => $uid], 'id = ?', [$f['id']]);
};
