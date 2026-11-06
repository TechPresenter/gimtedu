<?php
/**
 * Certificate courses from the GIMT "Course & Certification List" — 30 short courses in 5 groups (29 new + the existing
 * Certificate in Digital Marketing). Each course is a `programs` row (level Certificate, School of Professional & Skill
 * Development) with website content, one semester and a module-wise curriculum (`subjects`, semester 1).
 *
 * The group is stored in programs.category (labels must match site_cert_groups() in includes/config.php).
 * Fees are left empty ("Fee on request") and intake open until the institute confirms them.
 *
 * Safe on live data and re-runnable: programs and subjects are upserted by code, nothing is deleted except modules of
 * these courses that are no longer listed here and have no marks/attendance attached. Existing courses (CDM, CDA) only
 * get their group and sort order.
 */

return function (array $opts): void {
    $deptId = (int) db_value("SELECT id FROM departments WHERE code = 'PSD'");
    if (!$deptId) {
        $deptId = (int) db_insert('departments', ['name' => 'School of Professional & Skill Development', 'code' => 'PSD', 'established_year' => 2018,
            'description' => 'Short-term certificate courses in management, computers, communication, AI and career skills.', 'status' => 'active']);
    }

    $images = [
        'Management & Business' => 'assets/images/site/program-bba.jpg',
        'Computer & Technology' => 'assets/images/site/students-laptop.jpg',
        'Skill Development' => 'assets/images/site/facility-classroom.jpg',
        'Emerging Skills' => 'assets/images/site/facility-labs.jpg',
        'Academic & Career Preparation' => 'assets/images/site/placement-student.jpg',
    ];
    $eligDefault = 'Class 10 pass or above. Open to school and college students, graduates and working professionals — no prior experience needed.';

    // code => [group, name, short name, months, overview, highlights, careers, modules (last = project / assessment), eligibility override]
    $courses = [
        // 1. Management & Business
        'CBM' => ['Management & Business', 'Certificate in Business Management', 'Business Management', 3,
            'Understand how a business runs — planning, organising, finance basics, marketing and operations — and learn to take everyday management decisions with confidence.',
            ['Core management concepts explained through Indian business examples', 'Basics of accounts, budgeting and business finance', 'Group activities on planning, decision-making and problem-solving', 'Final business plan project and assessment'],
            ['Management Trainee', 'Business Development Executive', 'Operations Executive', 'Team Coordinator', 'Small Business Owner'],
            ['Principles of Management', 'Business Environment & Organisation', 'Basics of Accounting & Business Finance', 'Marketing & Sales Essentials', 'Operations & Customer Service', 'Business Plan Project & Assessment']],
        'CHRM' => ['Management & Business', 'Certificate in Human Resource Management', 'HR Management', 3,
            'Learn the day-to-day work of an HR team — recruitment, onboarding, payroll basics, employee relations and labour-law essentials — with practical templates and exercises.',
            ['Recruitment, selection and onboarding, step by step', 'Payroll, attendance and leave records in practice', 'Introduction to Indian labour laws and HR compliance', 'HR documentation project and assessment'],
            ['HR Executive', 'Recruitment Coordinator', 'HR Assistant', 'Payroll Executive', 'Talent Acquisition Associate'],
            ['Fundamentals of Human Resource Management', 'Recruitment, Selection & Onboarding', 'Payroll, Attendance & HR Records', 'Employee Relations & Performance Management', 'Labour Laws & HR Compliance Basics', 'HR Project & Assessment']],
        'CMKM' => ['Management & Business', 'Certificate in Marketing Management', 'Marketing Management', 3,
            'Build a strong base in marketing — customers, products, pricing, promotion and sales — and learn to plan a simple marketing campaign for a real product.',
            ['Marketing mix and consumer behaviour with real brand examples', 'Sales techniques and customer relationship basics', 'Introduction to digital and social media marketing', 'Marketing plan project and assessment'],
            ['Marketing Executive', 'Sales Executive', 'Brand Promotion Associate', 'Customer Relationship Executive', 'Market Research Assistant'],
            ['Marketing Fundamentals', 'Consumer Behaviour & Market Research', 'Product, Pricing & Distribution', 'Advertising, Promotion & Digital Marketing Basics', 'Sales & Customer Relationship Management', 'Marketing Plan Project & Assessment']],
        'COBA' => ['Management & Business', 'Certificate in Office & Business Administration', 'Office & Business Admin', 3,
            'Prepare for administrative roles in companies — business correspondence, records, purchase and accounts support, and the office software that ties it all together.',
            ['Business letters, emails, minutes and reports', 'Filing, records and document management', 'Purchase, inventory and accounts support basics', 'Hands-on practice with office software'],
            ['Administrative Executive', 'Office Administrator', 'Executive Assistant', 'Back Office Executive', 'Front Office Coordinator'],
            ['Business Administration Essentials', 'Business Communication & Correspondence', 'Records & Document Management', 'Purchase, Inventory & Accounts Support', 'Office Software for Administration', 'Administration Project & Assessment']],
        'CEBD' => ['Management & Business', 'Certificate in Entrepreneurship & Business Development', 'Entrepreneurship', 3,
            'Turn an idea into a business plan — find and test an opportunity, understand costs and pricing, explore funding and government schemes, and learn how to grow sales.',
            ['Idea validation and market research for a new venture', 'Costing, pricing and simple financial planning', 'Funding options, registration and government schemes', 'Pitch your own business plan at the end'],
            ['Entrepreneur / Startup Founder', 'Business Development Executive', 'Family Business Manager', 'Startup Associate', 'Franchise Operator'],
            ['Entrepreneurial Mindset & Idea Generation', 'Market Research & Opportunity Validation', 'Business Model, Costing & Pricing', 'Funding, Registration & Government Schemes', 'Sales, Networking & Business Growth', 'Business Plan Pitch & Assessment']],
        'COFM' => ['Management & Business', 'Certificate in Office Management', 'Office Management', 3,
            'Learn to run a well-organised office — front desk and visitors, calendars and meetings, supplies and vendors, and professional office etiquette.',
            ['Front office, reception and telephone etiquette', 'Meeting, travel and calendar management', 'Office supplies, vendor and facility management', 'Practical office simulation and assessment'],
            ['Office Manager', 'Office Assistant', 'Receptionist / Front Desk Executive', 'Admin Assistant', 'Facility Coordinator'],
            ['Office Organisation & Workflow', 'Front Office & Visitor Management', 'Meetings, Calendars & Travel Planning', 'Office Supplies, Vendors & Facilities', 'Professional Etiquette & Office Communication', 'Office Simulation & Assessment']],

        // 2. Computer & Technology (Certificate in Digital Marketing — CDM — already exists)
        'CCF' => ['Computer & Technology', 'Certificate in Computer Fundamentals', 'Computer Fundamentals', 3,
            'Start from zero and become comfortable with computers — hardware, Windows, files and folders, typing, the internet, email and basic online safety.',
            ['Designed for complete beginners', 'Windows, files, folders and everyday computer tasks', 'Typing practice, internet, email and video calls', 'Safe use of computers and smartphones online'],
            ['Computer Operator', 'Office Assistant', 'Data Entry Operator', 'Customer Support Executive', 'Retail Billing Executive'],
            ['Introduction to Computers & Hardware', 'Windows Operating System & File Management', 'Typing Skills & Keyboard Shortcuts', 'Internet, Email & Online Services', 'Basics of Word, Excel & PowerPoint', 'Practical Test & Assessment']],
        'CMSO' => ['Computer & Technology', 'Certificate in MS Office', 'MS Office', 3,
            'Master Microsoft Word, Excel, PowerPoint and Outlook for college and office work — from well-formatted documents and spreadsheets to presentations and email.',
            ['Hands-on lab practice on every application', 'Professional documents, letters and resumes in Word', 'Spreadsheets, formulas and charts in Excel', 'Presentations and email with PowerPoint and Outlook'],
            ['Office Assistant', 'Computer Operator', 'Data Entry Operator', 'Back Office Executive', 'MIS Assistant'],
            ['MS Word — Documents, Letters & Resumes', 'MS Excel — Formulas, Charts & Data', 'MS PowerPoint — Presentations', 'MS Outlook — Email & Calendar', 'Office Productivity Tips & Shortcuts', 'Practical Test & Assessment']],
        'CAEX' => ['Computer & Technology', 'Certificate in Advanced Excel', 'Advanced Excel', 1,
            'Go beyond basic spreadsheets — lookup functions, pivot tables, charts, dashboards, data cleaning and an introduction to macros, practised on real business data.',
            ['Lookup, logical and text functions (XLOOKUP, VLOOKUP, IF, INDEX-MATCH)', 'Pivot tables, pivot charts and interactive dashboards', 'Data cleaning, validation and conditional formatting', 'Introduction to macros for repetitive tasks'],
            ['MIS Executive', 'Data Analyst (Entry Level)', 'Accounts Assistant', 'Reporting Executive', 'Operations Analyst'],
            ['Advanced Formulas & Lookup Functions', 'Data Cleaning, Validation & Conditional Formatting', 'Pivot Tables, Charts & Dashboards', 'Macros & Dashboard Project'],
            'Class 10 pass or above with basic computer skills. Working knowledge of MS Excel is recommended.'],
        'CCAP' => ['Computer & Technology', 'Certificate in Computer Applications', 'Computer Applications', 3,
            'A practical, all-round computer course — operating system, MS Office, internet and cloud tools, computerised accounting basics and simple web pages.',
            ['Complete office computing — Windows, Word, Excel and PowerPoint', 'Computerised accounting basics', 'Introduction to HTML and simple web pages', 'Practical lab work in every module'],
            ['Computer Operator', 'Office Executive', 'Accounts Assistant', 'Data Entry Operator', 'IT Support Assistant'],
            ['Computer & Operating System Basics', 'Office Applications (Word, Excel, PowerPoint)', 'Internet, Email & Cloud Tools', 'Computerised Accounting Basics', 'Introduction to HTML & Web Pages', 'Practical Project & Assessment']],
        'CDSK' => ['Computer & Technology', 'Certificate in Digital Skills', 'Digital Skills', 3,
            'Everyday digital skills for study, work and life — smartphones and computers, online forms, digital payments, government e-services, cloud storage and staying safe online.',
            ['Smartphone and computer skills for daily tasks', 'Digital payments, UPI and safe online banking', 'Government e-services and online applications', 'Cyber safety and protecting personal data'],
            ['Digital Assistant', 'Customer Service Executive', 'Common Service Centre (CSC) Operator', 'Office Assistant', 'Retail & Billing Executive'],
            ['Using Computers & Smartphones Effectively', 'Internet, Email & Cloud Storage', 'Digital Payments & Online Banking', 'Government E-Services & Online Applications', 'Cyber Safety & Digital Etiquette', 'Practical Assessment']],
        'CDEO' => ['Computer & Technology', 'Certificate in Data Entry & Office Automation', 'Data Entry', 3,
            'Become fast and accurate at data entry — typing speed, Excel and form-based entry, document scanning and PDF tools, and automating routine office work with templates and mail merge.',
            ['Typing speed and accuracy practice', 'Data entry in Excel, forms and online portals', 'Scanning, PDF tools and document handling', 'Office automation with templates and mail merge'],
            ['Data Entry Operator', 'Back Office Executive', 'Computer Operator', 'Documentation Assistant', 'MIS Assistant'],
            ['Typing Speed & Accuracy', 'Data Entry in Excel & Forms', 'Online Portals & Document Handling', 'Office Automation — Templates & Mail Merge', 'Data Accuracy & Verification', 'Speed Test & Assessment']],
        'CIIT' => ['Computer & Technology', 'Certificate in Internet & IT Skills', 'Internet & IT Skills', 3,
            'Learn how the internet and IT systems work and use them well — browsers and search, email and collaboration tools, networking basics, troubleshooting and cyber security.',
            ['Effective web search, browsers and online research', 'Email, video meetings and online collaboration tools', 'Networking basics and simple troubleshooting', 'Cyber security awareness and safe browsing'],
            ['IT Support Assistant', 'Help Desk Executive', 'Computer Operator', 'Online Research Assistant', 'Technical Support Associate'],
            ['Internet Basics, Browsers & Web Search', 'Email & Online Collaboration Tools', 'Computer Networks & Wi-Fi Basics', 'Troubleshooting & System Maintenance', 'Cyber Security Essentials', 'Practical Assessment']],

        // 3. Skill Development
        'CCS' => ['Skill Development', 'Certificate in Communication Skills', 'Communication Skills', 3,
            'Speak, write and listen with confidence — presentations, group discussions, professional email and everyday workplace conversations, learned through regular practice.',
            ['Verbal and non-verbal communication in practice', 'Presentation and public speaking sessions', 'Business writing — emails, reports and messages', 'Group discussions and role plays'],
            ['Customer Relationship Executive', 'Front Office Executive', 'Sales Executive', 'Team Coordinator', 'Telecaller'],
            ['Basics of Effective Communication', 'Listening & Speaking Skills', 'Presentation & Public Speaking', 'Business Writing & Email Etiquette', 'Group Discussions & Role Plays', 'Presentation & Assessment']],
        'CSPE' => ['Skill Development', 'Certificate in Spoken English', 'Spoken English', 3,
            'Speak English fluently in daily and professional situations — grammar for speaking, vocabulary, pronunciation and plenty of conversation practice.',
            ['Practical grammar for everyday conversation', 'Vocabulary building and pronunciation practice', 'Role plays for interviews, phone calls and meetings', 'Regular speaking practice and progress checks'],
            ['Customer Support Executive', 'Front Office Executive', 'Sales & Retail Executive', 'Telecaller / BPO Associate', 'Hospitality Staff'],
            ['Grammar for Speaking', 'Vocabulary & Pronunciation', 'Daily Conversation Practice', 'English for Interviews & the Workplace', 'Telephone & Presentation English', 'Speaking Test & Assessment']],
        'CPD' => ['Skill Development', 'Certificate in Personality Development', 'Personality Development', 3,
            'Build confidence and a positive presence — self-awareness, grooming, body language, etiquette, time management and goal setting.',
            ['Self-confidence and self-awareness activities', 'Grooming, body language and first impressions', 'Social and professional etiquette', 'Time management and goal setting'],
            ['Front Office Executive', 'Sales Executive', 'Customer Relationship Executive', 'Hospitality Professional', 'Team Coordinator'],
            ['Self-Awareness & Confidence Building', 'Grooming, Body Language & First Impressions', 'Etiquette & Social Skills', 'Time Management & Goal Setting', 'Positive Attitude & Stress Management', 'Personal Development Project & Assessment']],
        'CSST' => ['Skill Development', 'Certificate in Soft Skills Training', 'Soft Skills', 3,
            'The people skills employers look for — teamwork, problem-solving, emotional intelligence, adaptability and professional conduct — learned through activities and case studies.',
            ['Teamwork and collaboration activities', 'Problem-solving and critical-thinking exercises', 'Emotional intelligence and handling feedback', 'Workplace case studies and role plays'],
            ['Customer Service Executive', 'Sales Executive', 'Operations Executive', 'Team Coordinator', 'Associate (any sector)'],
            ['Teamwork & Collaboration', 'Problem Solving & Critical Thinking', 'Emotional Intelligence', 'Adaptability & Handling Feedback', 'Professional Conduct & Work Ethics', 'Case Study & Assessment']],
        'CIP' => ['Skill Development', 'Certificate in Interview Preparation', 'Interview Preparation', 1,
            'Get ready for your next interview — resume and LinkedIn profile, common interview questions, group discussions, aptitude basics and mock interviews with feedback.',
            ['Resume and LinkedIn profile review', 'Answering common HR and technical interview questions', 'Group discussion practice', 'Mock interviews with individual feedback'],
            ['Graduate Trainee', 'Management Trainee', 'Sales & Marketing Executive', 'Customer Service Executive', 'Internship Roles'],
            ['Resume, Cover Letter & LinkedIn Profile', 'Interview Questions & Answering Techniques', 'Group Discussion & Aptitude Basics', 'Mock Interviews & Feedback']],
        'CJR' => ['Skill Development', 'Certificate in Job Readiness', 'Job Readiness', 3,
            'Bridge the gap between education and your first job — workplace basics, computer and communication skills for work, job search and applications, and interview practice.',
            ['Job search, online applications and job portals', 'Workplace basics — reporting, rules and conduct', 'Essential computer and communication skills for work', 'Mock interviews and a job-readiness assessment'],
            ['Entry-Level Executive', 'Customer Service Executive', 'Retail & Sales Associate', 'Office Assistant', 'Trainee / Apprentice'],
            ['Understanding the World of Work', 'Job Search, Portals & Applications', 'Essential Computer Skills for Work', 'Workplace Communication', 'Interview Practice', 'Job-Readiness Assessment']],
        'CWS' => ['Skill Development', 'Certificate in Workplace Skills', 'Workplace Skills', 3,
            'Work effectively in a modern organisation — professional behaviour, time and task management, reporting, meetings, workplace safety and working well with colleagues.',
            ['Professional behaviour and workplace ethics', 'Time, task and priority management', 'Reporting, meetings and workplace communication', 'Workplace safety, policies and POSH awareness'],
            ['Office Executive', 'Operations Associate', 'Supervisor Trainee', 'Customer Service Executive', 'Team Member (any sector)'],
            ['Professionalism & Work Ethics', 'Time, Task & Priority Management', 'Workplace Communication & Reporting', 'Meetings & Teamwork', 'Workplace Safety, Policies & POSH Awareness', 'Workplace Case Study & Assessment']],
        'CLTM' => ['Skill Development', 'Certificate in Leadership & Team Management', 'Leadership & Teams', 3,
            'Learn to lead people and teams — leadership styles, motivation, delegation, decision-making, conflict resolution and managing team performance.',
            ['Leadership styles and self-assessment', 'Motivating, delegating and coaching team members', 'Conflict resolution and decision-making', 'Team performance reviews in practice'],
            ['Team Leader', 'Supervisor', 'Project Coordinator', 'Assistant Manager', 'Shift / Operations In-charge'],
            ['Leadership Styles & Self-Assessment', 'Building & Motivating Teams', 'Delegation, Coaching & Feedback', 'Decision Making & Conflict Resolution', 'Managing Team Performance', 'Leadership Project & Assessment'],
            'Graduates, final-year students and working professionals. Supervisory experience is helpful but not required.'],

        // 4. Emerging Skills
        'CAIA' => ['Emerging Skills', 'Certificate in Artificial Intelligence (AI) Awareness', 'AI Awareness', 1,
            'Understand what artificial intelligence is, where it is already used around you, what it can and cannot do, and how to use it safely and responsibly — no coding needed.',
            ['AI concepts explained simply, without coding', 'Real examples of AI in daily life and business', 'Hands-on with popular AI assistants', 'Responsible and safe use of AI'],
            ['Office & Administration Roles', 'Customer Support Roles', 'Teaching & Training', 'Small Business Owners', 'Further Study in AI'],
            ['What is AI? — Concepts & Examples', 'AI in Daily Life & Business', 'Using AI Assistants Safely', 'Responsible AI & Final Assessment']],
        'CAIT' => ['Emerging Skills', 'Certificate in AI Tools & Productivity', 'AI Tools', 3,
            'Use AI tools to get everyday work done faster — writing and email, research, presentations, spreadsheets and meeting notes — with good prompting habits and checks for accuracy.',
            ['Prompting techniques for useful, accurate answers', 'AI for writing, email and research', 'AI in spreadsheets, presentations and design tools', 'Checking AI output, privacy and responsible use'],
            ['Office Executive', 'Content Assistant', 'Marketing Executive', 'Operations Executive', 'Freelancer'],
            ['Introduction to AI Tools & Prompting', 'AI for Writing, Email & Research', 'AI for Presentations & Design', 'AI for Spreadsheets & Data', 'Automating Routine Work with AI', 'AI Productivity Project & Assessment'],
            'Class 10 pass or above with basic computer and internet skills.'],
        'CGAI' => ['Emerging Skills', 'Certificate in Generative AI Fundamentals', 'Generative AI', 3,
            'Learn how generative AI creates text, images and code — large language models, prompt engineering, image generation, simple no-code AI workflows and the ethics of using them.',
            ['How large language models and image generators work', 'Prompt engineering techniques and practice', 'Building simple no-code AI workflows', 'Copyright, bias and safety in generative AI'],
            ['AI Content Creator', 'Prompt Writer', 'AI Tools Associate', 'Digital Marketing Executive', 'AI Operations Associate'],
            ['Foundations of AI & Machine Learning', 'How Generative AI & LLMs Work', 'Prompt Engineering', 'Image, Audio & Video Generation', 'No-Code AI Workflows & Chatbots', 'Ethics, Safety & Final Project'],
            'Class 12 pass or above with basic computer skills. No programming background required.'],
        'CDWS' => ['Emerging Skills', 'Certificate in Digital Workplace Skills', 'Digital Workplace', 3,
            'Work confidently in a digital office — cloud documents, shared drives, video meetings, team chat, online calendars, e-signatures and remote-work etiquette.',
            ['Cloud documents and real-time collaboration', 'Video meetings, team chat and online calendars', 'Digital file sharing, e-signatures and workflows', 'Remote and hybrid work etiquette and data security'],
            ['Office Executive', 'Virtual Assistant', 'Remote Support Associate', 'Project Coordinator', 'Operations Executive'],
            ['The Digital Workplace — An Introduction', 'Cloud Documents & Shared Drives', 'Video Meetings, Chat & Calendars', 'Digital Workflows & E-Signatures', 'Remote Work Etiquette & Data Security', 'Digital Workplace Project & Assessment']],

        // 5. Academic & Career Preparation
        'CCEP' => ['Academic & Career Preparation', 'Certificate in Competitive Exam Preparation', 'Competitive Exams', 3,
            'Prepare for government and recruitment exams — quantitative aptitude, reasoning, English, general awareness and computer basics, with regular practice tests.',
            ['Quantitative aptitude and reasoning practice', 'English language and general awareness', 'Exam strategy and time management', 'Regular mock tests with performance review'],
            ['SSC Exams', 'Banking Exams (PO / Clerk)', 'Railway Recruitment Exams', 'State Government Exams', 'MBA Entrance Tests'],
            ['Quantitative Aptitude', 'Logical Reasoning', 'English Language', 'General Awareness & Current Affairs', 'Computer Awareness', 'Mock Tests & Assessment'],
            'Class 12 pass, graduates and final-year students preparing for competitive and recruitment examinations.'],
        'CCD' => ['Academic & Career Preparation', 'Certificate in Career Development', 'Career Development', 3,
            'Plan your career with clarity — self-assessment, exploring career paths and higher studies, setting goals, building your profile and networking.',
            ['Interest and strength self-assessment', 'Exploring career paths and higher-study options', 'Career goal setting and action planning', 'Resume, online profile and networking practice'],
            ['Students Choosing a Career', 'Graduates Planning Next Steps', 'Career Switchers', 'Job Seekers', 'Higher-Studies Aspirants'],
            ['Self-Assessment — Interests, Strengths & Values', 'Exploring Careers & Higher Studies', 'Career Goal Setting & Planning', 'Personal Branding — Resume & Online Profile', 'Networking & Mentorship', 'Career Plan Project & Assessment']],
        'CPRD' => ['Academic & Career Preparation', 'Certificate in Professional Development', 'Professional Development', 3,
            'Grow faster in your job — productivity, professional communication and presentations, business etiquette, ethics and continuous-learning habits for working professionals.',
            ['Workplace productivity and self-management', 'Professional presentations and reports', 'Business etiquette and networking', 'Ethics, integrity and continuous learning'],
            ['Working Professionals (any sector)', 'Executives & Officers', 'Team Leaders', 'Entrepreneurs', 'Fresh Graduates'],
            ['Professional Mindset & Self-Management', 'Productivity & Time Management', 'Professional Communication & Presentations', 'Business Etiquette & Networking', 'Ethics, Integrity & Continuous Learning', 'Professional Growth Plan & Assessment'],
            'Graduates, final-year students and working professionals.'],
        'CEMP' => ['Academic & Career Preparation', 'Certificate in Employability Skills', 'Employability Skills', 3,
            'The core skills that make you employable, in one course — communication, computer and digital skills, numeracy, problem-solving, teamwork and interview readiness.',
            ['Communication and English for the workplace', 'Computer, digital and numeracy skills', 'Problem-solving, teamwork and work ethics', 'Resume writing and interview practice'],
            ['Entry-Level Executive', 'Customer Service Executive', 'Retail & Sales Associate', 'Office Assistant', 'Trainee (any sector)'],
            ['Communication & Workplace English', 'Computer & Digital Skills', 'Numeracy & Problem Solving', 'Teamwork & Work Ethics', 'Resume & Interview Skills', 'Employability Assessment']],
    ];
    // existing certificate courses: only their group + position in the list
    $existing = ['CDM' => 'Computer & Technology', 'CDA' => 'Computer & Technology'];

    $order = array_keys($courses);
    array_splice($order, array_search('CAEX', $order, true) + 1, 0, ['CDM', 'CDA']);
    $sort = array_flip($order);

    db_transaction(function () use ($courses, $existing, $sort, $deptId, $images, $eligDefault): void {
        // the footer "Certificate" link now points at the certificate courses page (/programs?category=certificate redirects there anyway)
        if (db_table_exists('menu_items')) {
            db_exec("UPDATE menu_items SET title = 'Certificate Courses', url = '/certifications' WHERE url = '/programs?category=certificate'");
        }
        // the school now runs every group, not only digital marketing / analytics (only the original demo text is replaced)
        db_exec("UPDATE departments SET description = 'Short-term certificate courses in management, computers, communication, AI and career skills.'
                 WHERE id = ? AND (description IS NULL OR description = 'Short-term certificate programs in digital marketing, analytics and soft skills.')", [$deptId]);
        foreach ($existing as $code => $group) {
            db_exec('UPDATE programs SET category = ?, sort_order = ? WHERE code = ?', [$group, 100 + $sort[$code], $code]);
        }
        foreach ($courses as $code => $c) {
            [$group, $name, $short, $months, $overview, $highlights, $careers, $modules] = $c;
            $row = [
                'department_id' => $deptId, 'name' => $name, 'short_name' => $short, 'slug' => slugify($name),
                'level' => 'Certificate', 'degree' => 'Certificate', 'category' => $group,
                'duration_years' => max(0.1, round($months / 12, 1)), 'duration_label' => $months . ' Month' . ($months === 1 ? '' : 's'),
                'total_semesters' => 1, 'total_credits' => null, 'fee_label' => '/ Course',
                'eligibility' => $c[8] ?? $eligDefault, 'overview' => $overview,
                'highlights' => implode("\n", $highlights), 'career_prospects' => implode("\n", $careers),
                'image' => $images[$group], 'show_on_website' => 1, 'sort_order' => 100 + $sort[$code], 'status' => 'active',
            ];
            $id = (int) db_value('SELECT id FROM programs WHERE code = ?', [$code]);
            if ($id) {
                db_update('programs', $row, 'id = ?', [$id]);
            } else {
                $id = (int) db_insert('programs', $row + ['code' => $code, 'fee_per_year' => null, 'is_featured' => 0, 'is_popular' => 0]);
            }
            if (!db_value('SELECT COUNT(*) FROM semesters WHERE program_id = ? AND number = 1', [$id])) {
                db_insert('semesters', ['program_id' => $id, 'number' => 1, 'name' => 'Semester 1', 'status' => 'active']);
            }

            $codes = [];
            foreach ($modules as $i => $module) {
                $last = $i === count($modules) - 1;
                $sCode = $code . (101 + $i);
                $codes[] = $sCode;
                $subject = ['program_id' => $id, 'semester_no' => 1, 'name' => $module, 'type' => $last ? 'project' : 'theory',
                    'credits' => $last ? 1 : 2, 'max_internal' => $last ? 40 : 30, 'max_external' => $last ? 60 : 70, 'max_practical' => 0,
                    'pass_marks' => 40, 'hours_per_week' => $last ? 2 : 3, 'status' => 'active'];
                $sid = (int) db_value('SELECT id FROM subjects WHERE code = ?', [$sCode]);
                if ($sid) {
                    db_update('subjects', $subject, 'id = ?', [$sid]);
                } else {
                    db_insert('subjects', $subject + ['code' => $sCode]);
                }
            }
            // modules dropped from the list above (kept when marks or attendance already reference them)
            foreach (db_column('SELECT id FROM subjects WHERE program_id = ? AND code NOT IN (' . implode(',', array_fill(0, count($codes), '?')) . ')', array_merge([$id], $codes)) as $sid) {
                try {
                    db_exec('DELETE FROM subjects WHERE id = ?', [(int) $sid]);
                } catch (Throwable $e) {
                    db_exec("UPDATE subjects SET status = 'inactive' WHERE id = ?", [(int) $sid]);
                }
            }
        }
    });
};
