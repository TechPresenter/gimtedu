<?php
/**
 * Demo data for the placement unit (re-runnable, deterministic for a given seed date).
 *   - 35 recruiters (well-known names as plain text + a few fictional start-ups / SMEs), HR contacts, tie-up status
 *   - previous session: summer-internship / internship + PPO drives for the current final-year batch (PPO offers in May–June)
 *   - current session: full-time campus drives for the passing-out batch anchored on today's date — completed drives
 *     with all interview rounds and offers, two ongoing drives with pending rounds, drives open for registration,
 *     upcoming drives (incl. internships for the next batch) and one cancelled drive
 *   - eligibility comes from the real students / batches / latest published results (same rules as the app)
 *   - pre-placement trainings, workshops, mock tests and mock interviews with participants, attendance and scores
 *
 * Owns: companies, placement_drives, placement_applications, placement_interviews, placement_offers,
 *       placement_trainings, placement_training_participants.
 */
return function (array $opts): void {
    require_once __DIR__ . '/lib/demo_data.php';
    require_once APP_ROOT . '/app/auth.php';
    require_once APP_ROOT . '/app/services/placement.php';
    demo_seed(4040);

    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');
    $day = fn (int $offset, ?string $base = null) => date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days', strtotime($base ?? $today)));
    $minD = fn (string $a, string $b) => $a < $b ? $a : $b;
    $rnd = fn (float $a, float $b) => $a + (mt_rand() / mt_getrandmax()) * ($b - $a);
    $chance = fn (float $p) => (mt_rand() / mt_getrandmax()) < $p;
    $shuffle = function (array $a): array {
        for ($i = count($a) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
        }
        return $a;
    };

    // ---------------------------------------------------------------- clear owned tables (children first)
    db_exec('DELETE FROM placement_training_participants');
    db_exec('DELETE FROM placement_trainings');
    db_exec('DELETE FROM placement_offers');
    db_exec('DELETE FROM placement_interviews');
    db_exec('DELETE FROM placement_applications');
    db_exec('DELETE FROM placement_drives');
    db_exec('DELETE FROM companies');
    $letterDir = APP_ROOT . '/storage/private/placement-offers/demo';
    foreach (glob($letterDir . '/*.pdf') ?: [] as $f) {
        @unlink($f);
    }

    $cur = db_row('SELECT * FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1') ?: db_row('SELECT * FROM academic_sessions ORDER BY start_date DESC LIMIT 1');
    if (!$cur) {
        return;
    }
    $prev = db_row('SELECT * FROM academic_sessions WHERE end_date < ? ORDER BY end_date DESC LIMIT 1', [$cur['start_date']]);
    $batch = (int) substr($cur['end_date'], 0, 4);           // passing-out year of the final-year students
    $joinBase = $day(5, $cur['end_date']);                   // joining after graduation (early July)
    $curStart = $cur['start_date'];
    $creator = (int) (db_value("SELECT id FROM users WHERE username = 'placement'") ?: db_value('SELECT id FROM users ORDER BY id LIMIT 1'));

    $pid = db_pairs('SELECT code, id FROM programs');
    $P = fn (array $codes) => array_values(array_filter(array_map(fn ($c) => isset($pid[$c]) ? (int) $pid[$c] : null, $codes)));
    $tech = $P(['BTCSE', 'BTAIDS', 'BCA', 'MCA', 'BSCDS']);
    $engg = $P(['BTCSE', 'BTAIDS', 'MCA']);
    $mgmt = $P(['BBA', 'MBA']);
    $comm = $P(['BCOMH', 'MCOM']);
    $mba = $P(['MBA']);

    // ---------------------------------------------------------------- companies
    $C = [
        // name, industry, type, website, HQ, office city, tie-up, contact designation, about
        ['Tata Consultancy Services', 'IT Services', 'indian_mnc', 'https://www.tcs.com', 'Mumbai, Maharashtra', 'Noida', 'mou_signed', 'Campus Relations Manager', 'Global IT services, consulting and business solutions organisation; mass recruiter through the TCS National Qualifier Test.'],
        ['Infosys', 'IT Services', 'indian_mnc', 'https://www.infosys.com', 'Bengaluru, Karnataka', 'Noida', 'mou_signed', 'Talent Acquisition Lead', 'Digital services and consulting; hires Systems Engineers, Digital Specialist Engineers and Specialist Programmers.'],
        ['Wipro', 'IT Services', 'indian_mnc', 'https://www.wipro.com', 'Bengaluru, Karnataka', 'Greater Noida', 'active', 'University Relations Partner', 'Technology services company; Elite National Talent Hunt for engineering and computer-applications graduates.'],
        ['HCLTech', 'IT Services', 'indian_mnc', 'https://www.hcltech.com', 'Noida, Uttar Pradesh', 'Noida', 'mou_signed', 'Campus Hiring Manager', 'Global technology company headquartered in Noida; Graduate Engineer Trainee programme.'],
        ['Tech Mahindra', 'IT Services', 'indian_mnc', 'https://www.techmahindra.com', 'Pune, Maharashtra', 'Noida', 'active', 'Senior Talent Acquisition Specialist', 'Digital transformation, consulting and engineering services.'],
        ['Accenture', 'Consulting', 'mnc', 'https://www.accenture.com', 'Dublin, Ireland', 'Gurugram', 'active', 'Campus Recruitment Lead', 'Professional services across strategy, consulting, technology and operations.'],
        ['Capgemini', 'IT Services', 'mnc', 'https://www.capgemini.com', 'Paris, France', 'Noida', 'active', 'Campus Talent Acquisition', 'Consulting, technology services and digital transformation.'],
        ['Cognizant', 'IT Services', 'mnc', 'https://www.cognizant.com', 'Teaneck, New Jersey, USA', 'Gurugram', 'active', 'Campus Hiring Specialist', 'Professional services; GenC and GenC Next hiring tracks for graduates.'],
        ['LTIMindtree', 'IT Services', 'indian_mnc', 'https://www.ltimindtree.com', 'Mumbai, Maharashtra', 'Noida', 'active', 'Talent Acquisition Manager', 'Technology consulting and digital solutions company.'],
        ['Nagarro', 'IT Services', 'mnc', 'https://www.nagarro.com', 'Munich, Germany', 'Gurugram', 'active', 'University Hiring Lead', 'Digital product engineering company with a large Gurugram delivery centre.'],
        ['Coforge', 'IT Services', 'indian_mnc', 'https://www.coforge.com', 'Noida, Uttar Pradesh', 'Greater Noida', 'mou_signed', 'Campus Relations Executive', 'IT solutions organisation focused on BFSI, travel and insurance domains.'],
        ['Genpact', 'IT Services', 'mnc', 'https://www.genpact.com', 'New York, USA', 'Gurugram', 'active', 'Talent Acquisition Partner', 'Professional services firm for digital-led transformation and business process management.'],
        ['Deloitte USI', 'Consulting', 'mnc', 'https://www.deloitte.com', 'London, United Kingdom', 'Gurugram', 'active', 'Campus Recruiting Lead', 'Audit, consulting, tax and advisory services — US India offices.'],
        ['KPMG India', 'Consulting', 'mnc', 'https://kpmg.com/in', 'Mumbai, Maharashtra', 'Gurugram', 'active', 'Campus Talent Acquisition', 'Audit, tax and advisory services.'],
        ['EY GDS', 'Consulting', 'mnc', 'https://www.ey.com', 'London, United Kingdom', 'Noida', 'active', 'University Relations Lead', 'Assurance, consulting, tax and strategy services — Global Delivery Services.'],
        ['ZS Associates', 'Analytics', 'mnc', 'https://www.zs.com', 'Evanston, Illinois, USA', 'Gurugram', 'active', 'Campus Recruiting Manager', 'Management consulting and technology firm focused on healthcare and life sciences.'],
        ['HDFC Bank', 'BFSI', 'large_indian', 'https://www.hdfcbank.com', 'Mumbai, Maharashtra', 'Noida', 'mou_signed', 'Regional HR Business Partner', 'Private sector bank; hires Relationship Managers and Management Trainees.'],
        ['ICICI Bank', 'BFSI', 'large_indian', 'https://www.icicibank.com', 'Mumbai, Maharashtra', 'Noida', 'active', 'Campus Hiring Lead', 'Private sector bank; Probationary Officer programme for fresh graduates.'],
        ['Axis Bank', 'BFSI', 'large_indian', 'https://www.axisbank.com', 'Mumbai, Maharashtra', 'Delhi', 'active', 'Talent Acquisition Manager', 'Private sector bank with retail, corporate and treasury businesses.'],
        ['Bajaj Finserv', 'BFSI', 'large_indian', 'https://www.bajajfinserv.in', 'Pune, Maharashtra', 'Noida', 'active', 'Area HR Manager', 'Financial services — lending, insurance and payments.'],
        ['Kotak Mahindra Bank', 'BFSI', 'large_indian', 'https://www.kotak.com', 'Mumbai, Maharashtra', 'Delhi', 'prospect', 'HR Manager — Campus', 'Banking and financial services group.'],
        ['Amazon', 'E-commerce', 'mnc', 'https://www.amazon.in', 'Seattle, USA', 'Gurugram', 'active', 'University Recruiter', 'E-commerce and cloud technology; SDE, business analyst and operations roles.'],
        ['Flipkart', 'E-commerce', 'large_indian', 'https://www.flipkart.com', 'Bengaluru, Karnataka', 'Gurugram', 'active', 'Campus Engagement Manager', 'E-commerce marketplace; business analyst and supply chain internships.'],
        ['Hindustan Unilever', 'FMCG', 'mnc', 'https://www.hul.co.in', 'Mumbai, Maharashtra', 'Noida', 'active', 'Campus Relations Manager', 'FMCG company; Unilever Future Leaders Programme for MBA graduates.'],
        ['ITC Limited', 'FMCG', 'large_indian', 'https://www.itcportal.com', 'Kolkata, West Bengal', 'Delhi', 'active', 'Talent Acquisition Head — North', 'Diversified conglomerate — FMCG, hotels, paperboards and agri-business.'],
        ['Asian Paints', 'Manufacturing', 'large_indian', 'https://www.asianpaints.com', 'Mumbai, Maharashtra', 'Noida', 'dormant', 'HR Business Partner', 'Paints and home décor company.'],
        ['Maruti Suzuki', 'Automobile', 'large_indian', 'https://www.marutisuzuki.com', 'New Delhi', 'Gurugram', 'active', 'Senior Manager — Campus Hiring', 'Passenger vehicle manufacturer; management trainee roles in supply chain and sales.'],
        ['Reliance Retail', 'Retail', 'large_indian', 'https://www.relianceretail.com', 'Mumbai, Maharashtra', 'Noida', 'active', 'Talent Acquisition Manager', 'Retail business across grocery, fashion, electronics and digital commerce.'],
        ['Fractal Analytics', 'Analytics', 'indian_mnc', 'https://fractal.ai', 'Mumbai, Maharashtra', 'Gurugram', 'active', 'Campus Hiring Lead', 'AI and analytics company; analyst and data scientist roles.'],
        ['Mu Sigma', 'Analytics', 'indian_mnc', 'https://www.mu-sigma.com', 'Bengaluru, Karnataka', 'Bengaluru', 'dormant', 'University Relations', 'Decision sciences and analytics company.'],
        ['NovaByte Technologies', 'Software Product', 'startup', null, 'Noida, Uttar Pradesh', 'Noida', 'mou_signed', 'Co-founder & Head of Engineering', 'Series-B SaaS start-up building workflow automation for mid-size manufacturers (fictional demo company).'],
        ['FinEdge Payments', 'Fintech', 'startup', null, 'Gurugram, Haryana', 'Gurugram', 'active', 'People Operations Lead', 'Payments and merchant-lending start-up (fictional demo company).'],
        ['GreenLeaf Agritech', 'Agritech', 'startup', null, 'Lucknow, Uttar Pradesh', 'Lucknow', 'prospect', 'HR Executive', 'Farm-to-retail supply chain platform (fictional demo company).'],
        ['Brightpath Learning', 'EdTech', 'startup', null, 'Noida, Uttar Pradesh', 'Noida', 'active', 'Talent Acquisition Specialist', 'K-12 and test-prep learning platform (fictional demo company).'],
        ['Skyline Infra Projects', 'Infrastructure', 'sme', null, 'Ghaziabad, Uttar Pradesh', 'Ghaziabad', 'prospect', 'HR Manager', 'Civil and MEP contractor for commercial projects in NCR (fictional demo company).'],
    ];
    $female = demo_first_names('female');
    $male = demo_first_names('male');
    $last = demo_last_names();
    $company = [];
    foreach ($C as $i => [$name, $industry, $type, $web, $hq, $city, $tie, $desig, $about]) {
        $g = $i % 2 ? 'female' : 'male';
        $first = demo_pick($g === 'female' ? $female : $male);
        $host = $web ? preg_replace('#^www\.#', '', parse_url($web, PHP_URL_HOST)) : slugify($name) . '.example.com';
        $id = db_insert('companies', [
            'name' => $name, 'slug' => slugify($name), 'industry' => $industry, 'company_type' => $type, 'website' => $web, 'headquarters' => $hq,
            'email' => 'campus.relations@' . $host, 'phone' => demo_phone(), 'contact_person' => ($g === 'female' ? 'Ms. ' : 'Mr. ') . $first . ' ' . demo_pick($last),
            'contact_designation' => $desig, 'address' => null, 'city' => $city, 'description' => $about,
            'notes' => demo_pick([null, 'Prefers pre-placement talk one week before the drive.', 'Bond: none. Shares results within 48 hours of the final round.',
                'Requests separate aptitude venue for 150+ candidates.', 'Follow up for summer internship slots in December.', 'Asked for CGPA cut-off 7.0 and no active backlogs.', null]),
            'is_recruiter' => $tie === 'prospect' ? 0 : 1, 'is_mou' => $tie === 'mou_signed' ? 1 : 0, 'tie_up_status' => $tie, 'sort_order' => $i,
            'status' => $tie === 'dormant' && $i % 2 ? 'inactive' : 'active',
        ]);
        $company[$name] = $id;
    }

    // ---------------------------------------------------------------- students (with the metrics eligibility uses)
    $students = [];
    foreach (db_all('SELECT s.id, s.program_id, s.department_id, s.gender, s.current_semester, ' . placement_cgpa_sql() . ' AS cgpa FROM students s WHERE s.status = \'active\'') as $s) {
        $students[(int) $s['id']] = ['program_id' => (int) $s['program_id'], 'cgpa' => $s['cgpa'] !== null ? (float) $s['cgpa'] : 6.5, 'sem' => (int) $s['current_semester']];
    }

    // ---------------------------------------------------------------- drives
    $R3 = 'Aptitude Test, Technical Interview, HR Interview';
    $drives = [];
    $mk = function (array $d) use ($company, &$drives, $creator) {
        $row = $d + ['openings' => null, 'stipend' => null, 'location' => null, 'venue' => 'Seminar Hall, Academic Block A', 'mode' => 'on_campus', 'min_percentage' => 60,
            'max_backlogs' => 0, 'eligibility_notes' => null, 'description' => null, 'job_type' => 'full_time'];
        $insert = [
            'company_id' => $company[$row['company']], 'title' => $row['title'], 'job_role' => $row['role'], 'job_type' => $row['job_type'], 'openings' => $row['openings'],
            'description' => $row['description'], 'package_min' => $row['min'], 'package_max' => $row['max'], 'stipend' => $row['stipend'], 'location' => $row['location'],
            'eligible_programs' => json_encode(array_values($row['programs'])), 'batch_year' => $row['batch'], 'min_cgpa' => $row['cgpa'], 'min_percentage' => $row['min_percentage'],
            'max_backlogs' => $row['max_backlogs'], 'eligibility_notes' => $row['eligibility_notes'], 'rounds' => $row['rounds'], 'drive_date' => $row['date'],
            'registration_deadline' => $row['deadline'] ?? date('Y-m-d', strtotime($row['date'] . ' -4 days')), 'venue' => $row['venue'], 'mode' => $row['mode'],
            'status' => $row['status'], 'created_by' => $creator, 'created_at' => date('Y-m-d H:i:s', strtotime(($row['deadline'] ?? $row['date']) . ' -18 days 11:00')),
        ];
        $id = db_insert('placement_drives', $insert);
        $drives[] = $insert + ['id' => $id, 'ppo' => $row['ppo'] ?? false, 'offers' => $row['offers'] ?? true, 'sim' => $row['sim']];
    };

    if ($prev) {
        $py = (int) substr($prev['end_date'], 0, 4);
        $pd = fn (string $md) => $py . '-' . $md;
        $mk(['company' => 'Amazon', 'title' => 'SDE Summer Internship ' . $py, 'role' => 'Software Development Engineer Intern', 'job_type' => 'internship_ppo', 'min' => 22, 'max' => 28, 'stipend' => 80000,
            'programs' => $engg, 'batch' => $batch, 'cgpa' => 7.5, 'rounds' => 'Online Coding Assessment, Technical Interview 1, Technical Interview 2', 'date' => (int) substr($prev['start_date'], 0, 4) . '-09-10',
            'location' => 'Hyderabad / Bengaluru', 'mode' => 'virtual', 'venue' => 'Virtual — Amazon Chime', 'status' => 'completed', 'sim' => 'done', 'openings' => 4, 'ppo' => true]);
        $mk(['company' => 'Infosys', 'title' => 'InStep Summer Internship ' . $py, 'role' => 'Software Engineering Intern', 'job_type' => 'internship_ppo', 'min' => 6.5, 'max' => 9.5, 'stipend' => 25000,
            'programs' => $tech, 'batch' => $batch, 'cgpa' => 7.0, 'rounds' => 'Online Assessment, Technical Interview, HR Interview', 'date' => $pd('01-20'), 'location' => 'Bengaluru / Pune',
            'status' => 'completed', 'sim' => 'done', 'openings' => 15, 'ppo' => true]);
        $mk(['company' => 'HDFC Bank', 'title' => 'Management Trainee Summer Internship', 'role' => 'Management Trainee Intern', 'job_type' => 'internship_ppo', 'min' => 5.5, 'max' => 7, 'stipend' => 20000,
            'programs' => array_merge($mgmt, $comm), 'batch' => $batch, 'cgpa' => 6.5, 'max_backlogs' => 1, 'rounds' => 'Aptitude Test, Group Discussion, Personal Interview', 'date' => $pd('02-05'),
            'location' => 'Delhi NCR', 'status' => 'completed', 'sim' => 'done', 'openings' => 12, 'ppo' => true]);
        $mk(['company' => 'Deloitte USI', 'title' => 'Analyst Internship — Consulting & Tax', 'role' => 'Analyst Intern', 'job_type' => 'internship_ppo', 'min' => 6.5, 'max' => 8.5, 'stipend' => 30000,
            'programs' => array_merge($mba, $comm, $P(['BSCDS'])), 'batch' => $batch, 'cgpa' => 7.0, 'rounds' => 'Online Assessment, Case Interview, Partner Interview', 'date' => $pd('02-18'),
            'location' => 'Gurugram / Hyderabad', 'status' => 'completed', 'sim' => 'done', 'openings' => 8, 'ppo' => true]);
        $mk(['company' => 'NovaByte Technologies', 'title' => 'Product Engineering Internship', 'role' => 'Product Engineering Intern', 'job_type' => 'internship_ppo', 'min' => 8, 'max' => 11, 'stipend' => 40000,
            'programs' => $tech, 'batch' => $batch, 'cgpa' => 6.5, 'max_backlogs' => 1, 'rounds' => 'Coding Assignment, Technical Interview, Founder Interview', 'date' => $pd('03-03'),
            'location' => 'Noida', 'status' => 'completed', 'sim' => 'done', 'openings' => 6, 'ppo' => true]);
        $mk(['company' => 'ITC Limited', 'title' => 'Summer Internship — Sales & Marketing', 'role' => 'Sales & Marketing Intern', 'job_type' => 'internship', 'min' => null, 'max' => null, 'stipend' => 30000,
            'programs' => $mgmt, 'batch' => $batch, 'cgpa' => 6.0, 'max_backlogs' => 1, 'rounds' => 'Group Discussion, Personal Interview', 'date' => $pd('01-28'), 'location' => 'Delhi NCR',
            'status' => 'completed', 'sim' => 'done', 'openings' => 10, 'offers' => false]);
        $mk(['company' => 'Fractal Analytics', 'title' => 'Data Science Internship', 'role' => 'Data Science Intern', 'job_type' => 'internship', 'min' => null, 'max' => null, 'stipend' => 35000,
            'programs' => $P(['BSCDS', 'BTAIDS', 'MCA']), 'batch' => $batch, 'cgpa' => 7.0, 'rounds' => 'Online Assessment, Technical Interview', 'date' => $pd('02-25'), 'location' => 'Gurugram',
            'mode' => 'virtual', 'venue' => 'Virtual — MS Teams', 'status' => 'completed', 'sim' => 'done', 'openings' => 6, 'offers' => false]);
        $mk(['company' => 'KPMG India', 'title' => 'Audit & Tax Internship', 'role' => 'Audit / Tax Intern', 'job_type' => 'internship', 'min' => null, 'max' => null, 'stipend' => 15000,
            'programs' => array_merge($comm, $mba), 'batch' => $batch, 'cgpa' => 6.5, 'max_backlogs' => 1, 'rounds' => 'Aptitude Test, Personal Interview', 'date' => $pd('03-12'), 'location' => 'Gurugram',
            'status' => 'completed', 'sim' => 'done', 'openings' => 10, 'offers' => false]);
    }

    $cd = fn (int $offset) => max($day($offset), $day(18, $curStart));
    $mk(['company' => 'Tata Consultancy Services', 'title' => 'TCS NQT Campus Hiring ' . $batch . ' — Ninja & Digital', 'role' => 'Assistant System Engineer, Digital Engineer', 'min' => 3.6, 'max' => 7.0,
        'programs' => $tech, 'batch' => $batch, 'cgpa' => 6.0, 'max_backlogs' => 1, 'rounds' => 'TCS NQT, Technical Interview, Managerial & HR Interview', 'date' => $cd(-58), 'location' => 'Pan India',
        'status' => 'completed', 'sim' => 'done', 'openings' => 60, 'description' => "Ninja (3.6 LPA) and Digital (7 LPA) cadres based on the NQT score and interview performance."]);
    $mk(['company' => 'Infosys', 'title' => 'Infosys Campus Hiring ' . $batch, 'role' => 'Systems Engineer, Specialist Programmer', 'min' => 3.6, 'max' => 9.5, 'programs' => $tech, 'batch' => $batch, 'cgpa' => 6.0,
        'max_backlogs' => 0, 'rounds' => 'Online Assessment, Technical Interview, HR Interview', 'date' => $cd(-50), 'location' => 'Mysuru (training), then Pan India', 'status' => 'completed', 'sim' => 'done', 'openings' => 40]);
    $mk(['company' => 'Accenture', 'title' => 'Accenture Campus Hiring — ASE & Analyst', 'role' => 'Associate Software Engineer, Analyst', 'min' => 4.5, 'max' => 6.5, 'programs' => array_merge($tech, $P(['BBA', 'BCOMH'])),
        'batch' => $batch, 'cgpa' => 6.5, 'rounds' => 'Cognitive & Technical Assessment, Coding Test, Communication Assessment, Interview', 'date' => $cd(-44), 'location' => 'Bengaluru / Gurugram / Pune',
        'status' => 'completed', 'sim' => 'done', 'openings' => 35]);
    $mk(['company' => 'HDFC Bank', 'title' => 'Relationship Manager — Campus ' . $batch, 'role' => 'Relationship Manager', 'min' => 4.0, 'max' => 5.5, 'programs' => array_merge($mgmt, $comm), 'batch' => $batch,
        'cgpa' => 6.0, 'max_backlogs' => 1, 'rounds' => 'Aptitude Test, Group Discussion, Personal Interview', 'date' => $cd(-38), 'location' => 'Delhi NCR', 'status' => 'completed', 'sim' => 'done', 'openings' => 25]);
    $mk(['company' => 'Deloitte USI', 'title' => 'Deloitte USI — Analyst (Consulting / Tax)', 'role' => 'Analyst, Tax Analyst', 'min' => 6.5, 'max' => 9.0, 'programs' => array_merge($mba, $comm, $P(['BSCDS', 'BBA'])),
        'batch' => $batch, 'cgpa' => 7.0, 'rounds' => 'Online Assessment, Case Interview, Partner Interview', 'date' => $cd(-30), 'location' => 'Gurugram / Hyderabad', 'status' => 'completed', 'sim' => 'done', 'openings' => 15]);
    $mk(['company' => 'Capgemini', 'title' => 'Capgemini Analyst Hiring ' . $batch, 'role' => 'Analyst, Senior Analyst', 'min' => 4.25, 'max' => 7.5, 'programs' => $tech, 'batch' => $batch, 'cgpa' => 6.0,
        'rounds' => 'Game-based Aptitude, Technical Interview, HR Interview', 'date' => $cd(-24), 'location' => 'Noida / Pune', 'mode' => 'virtual', 'venue' => 'Virtual — Superset', 'status' => 'completed', 'sim' => 'done', 'openings' => 30]);
    $mk(['company' => 'Wipro', 'title' => 'Wipro Elite NTH ' . $batch, 'role' => 'Project Engineer', 'min' => 3.5, 'max' => 6.5, 'programs' => $tech, 'batch' => $batch, 'cgpa' => 6.0, 'rounds' => 'Online Assessment, Technical Interview, HR Interview',
        'date' => $cd(-17), 'location' => 'Pan India', 'status' => 'completed', 'sim' => 'done', 'openings' => 30]);
    $mk(['company' => 'ICICI Bank', 'title' => 'ICICI Bank Probationary Officer Programme', 'role' => 'Probationary Officer (Deputy Manager)', 'min' => 5.0, 'max' => 6.0, 'programs' => array_merge($mgmt, $comm), 'batch' => $batch,
        'cgpa' => 6.0, 'max_backlogs' => 0, 'rounds' => 'Aptitude Test, Personal Interview', 'date' => $cd(-12), 'location' => 'Pan India', 'status' => 'completed', 'sim' => 'done', 'openings' => 20]);
    $mk(['company' => 'Cognizant', 'title' => 'Cognizant GenC / GenC Next', 'role' => 'Programmer Analyst Trainee', 'min' => 4.0, 'max' => 6.75, 'programs' => $tech, 'batch' => $batch, 'cgpa' => 6.0, 'max_backlogs' => 1,
        'rounds' => 'Communication & Aptitude Assessment, Technical Interview, HR Interview', 'date' => $cd(-9), 'location' => 'Chennai / Pune / Kolkata', 'mode' => 'virtual', 'venue' => 'Virtual — Cognizant platform', 'status' => 'completed', 'sim' => 'done', 'openings' => 30]);
    $mk(['company' => 'HCLTech', 'title' => 'HCLTech Graduate Engineer Trainee ' . $batch, 'role' => 'Graduate Engineer Trainee', 'min' => 4.25, 'max' => 6.0, 'programs' => $tech, 'batch' => $batch, 'cgpa' => 6.0,
        'rounds' => $R3, 'date' => $day(-1), 'deadline' => $day(-6), 'location' => 'Noida / Lucknow', 'status' => 'ongoing', 'sim' => 'ongoing', 'openings' => 25]);
    $mk(['company' => 'Amazon', 'title' => 'Amazon Business Analyst & Operations — MBA ' . $batch, 'role' => 'Business Analyst, Area Manager (Operations)', 'min' => 12, 'max' => 18, 'programs' => $mba, 'batch' => $batch, 'cgpa' => 7.0,
        'rounds' => 'Online Assessment, Functional Interview, Bar Raiser Interview', 'date' => $day(1), 'deadline' => $day(-8), 'location' => 'Gurugram / Hyderabad', 'mode' => 'virtual', 'venue' => 'Virtual — Amazon Chime',
        'status' => 'ongoing', 'sim' => 'ongoing', 'openings' => 4]);
    $mk(['company' => 'Hindustan Unilever', 'title' => 'Unilever Future Leaders Programme ' . $batch, 'role' => 'Area Sales Manager (UFLP)', 'min' => 12, 'max' => 16, 'programs' => $mba, 'batch' => $batch, 'cgpa' => 7.0,
        'rounds' => 'Online Assessment, Group Discussion, Panel Interview', 'date' => $day(9), 'deadline' => $day(4), 'location' => 'Pan India', 'status' => 'open', 'sim' => 'open', 'openings' => 3]);
    $mk(['company' => 'Fractal Analytics', 'title' => 'Fractal Analyst / Data Scientist Hiring', 'role' => 'Analyst, Data Scientist', 'min' => 8, 'max' => 11, 'programs' => $P(['BSCDS', 'BTAIDS', 'BTCSE', 'MCA']), 'batch' => $batch,
        'cgpa' => 7.5, 'rounds' => 'Online Assessment, Case Study Round, Technical Interview, HR Interview', 'date' => $day(14), 'deadline' => $day(7), 'location' => 'Gurugram / Bengaluru', 'status' => 'open', 'sim' => 'open', 'openings' => 6]);
    $mk(['company' => 'Tech Mahindra', 'title' => 'Tech Mahindra Associate Software Engineer', 'role' => 'Associate Software Engineer', 'min' => 3.25, 'max' => 5.5, 'programs' => $tech, 'batch' => $batch, 'cgpa' => 6.0, 'max_backlogs' => 1,
        'rounds' => 'Online Assessment, Technical Interview, HR Interview', 'date' => $day(18), 'deadline' => $day(10), 'location' => 'Noida / Pune', 'status' => 'open', 'sim' => 'open', 'openings' => 30]);
    $mk(['company' => 'ZS Associates', 'title' => 'ZS Business Technology Solutions Associate', 'role' => 'Business Technology Solutions Associate', 'min' => 9, 'max' => 11.5, 'programs' => $engg, 'batch' => $batch, 'cgpa' => 7.5,
        'rounds' => 'Online Assessment, Case Interview, Technical Interview, HR Interview', 'date' => $day(28), 'deadline' => $day(20), 'location' => 'Gurugram / Pune', 'status' => 'upcoming', 'sim' => 'none', 'openings' => 5]);
    $mk(['company' => 'Bajaj Finserv', 'title' => 'Bajaj Finserv Management Trainee — Sales & Credit', 'role' => 'Management Trainee', 'min' => 4.5, 'max' => 7, 'programs' => array_merge($mgmt, $comm), 'batch' => $batch, 'cgpa' => 6.0,
        'max_backlogs' => 1, 'rounds' => 'Aptitude Test, Group Discussion, Personal Interview', 'date' => $day(35), 'deadline' => $day(27), 'location' => 'Delhi NCR / Lucknow', 'status' => 'upcoming', 'sim' => 'none', 'openings' => 20]);
    $mk(['company' => 'NovaByte Technologies', 'title' => 'NovaByte Software Engineer I', 'role' => 'Software Engineer I', 'min' => 10, 'max' => 14, 'programs' => $tech, 'batch' => $batch, 'cgpa' => 7.0,
        'rounds' => 'Coding Assignment, Technical Interview, Founder Interview', 'date' => $day(45), 'deadline' => $day(36), 'location' => 'Noida', 'status' => 'upcoming', 'sim' => 'none', 'openings' => 6]);
    $mk(['company' => 'Maruti Suzuki', 'title' => 'Maruti Suzuki Management Trainee — Supply Chain', 'role' => 'Management Trainee (Supply Chain)', 'min' => 9, 'max' => 11, 'programs' => $mba, 'batch' => $batch, 'cgpa' => 6.5,
        'rounds' => 'Online Assessment, Group Discussion, Technical & HR Interview', 'date' => $day(60), 'deadline' => $day(50), 'location' => 'Gurugram / Manesar', 'status' => 'upcoming', 'sim' => 'none', 'openings' => 4]);
    $mk(['company' => 'EY GDS', 'title' => 'EY GDS Associate — Assurance & Tax', 'role' => 'Associate (Assurance), Associate (Tax)', 'min' => 5, 'max' => 6.5, 'programs' => array_merge($comm, $mba), 'batch' => $batch, 'cgpa' => 6.5,
        'rounds' => 'Online Assessment, Technical Interview, HR Interview', 'date' => $day(75), 'deadline' => $day(64), 'location' => 'Noida / Gurugram', 'status' => 'upcoming', 'sim' => 'none', 'openings' => 12]);
    $mk(['company' => 'Flipkart', 'title' => 'Flipkart Summer Internship ' . $batch . ' — Business Analyst', 'role' => 'Business Analyst Intern', 'job_type' => 'internship', 'min' => null, 'max' => null, 'stipend' => 60000,
        'programs' => $mba, 'batch' => $batch + 1, 'cgpa' => 6.5, 'rounds' => 'Online Assessment, Case Interview, HR Interview', 'date' => $day(40), 'deadline' => $day(30), 'location' => 'Bengaluru',
        'mode' => 'virtual', 'venue' => 'Virtual — Google Meet', 'status' => 'upcoming', 'sim' => 'none', 'openings' => 3, 'offers' => false]);
    $mk(['company' => 'KPMG India', 'title' => 'KPMG Winter Internship — Audit', 'role' => 'Audit Intern', 'job_type' => 'internship', 'min' => null, 'max' => null, 'stipend' => 15000, 'programs' => $comm,
        'batch' => $batch + 1, 'cgpa' => 6.5, 'max_backlogs' => 1, 'rounds' => 'Aptitude Test, Personal Interview', 'date' => $day(55), 'deadline' => $day(45), 'location' => 'Gurugram',
        'status' => 'upcoming', 'sim' => 'none', 'openings' => 8, 'offers' => false]);
    $mk(['company' => 'Kotak Mahindra Bank', 'title' => 'Kotak Sales Officer Hiring', 'role' => 'Sales Officer', 'min' => 3.5, 'max' => 4.5, 'programs' => array_merge($mgmt, $comm), 'batch' => $batch, 'cgpa' => 5.5,
        'max_backlogs' => 2, 'rounds' => 'Group Discussion, Personal Interview', 'date' => $day(6), 'deadline' => $day(-2), 'location' => 'Delhi NCR', 'status' => 'cancelled', 'sim' => 'cancelled', 'openings' => 15,
        'eligibility_notes' => 'Cancelled by the company — hiring freeze for the quarter.']);

    usort($drives, fn ($a, $b) => strcmp($a['drive_date'], $b['drive_date']) ?: $a['id'] <=> $b['id']);

    // ---------------------------------------------------------------- applications, rounds, offers
    $panelNames = ['Mr. Arvind Rao (Tech Lead)', 'Ms. Pooja Iyer (HR)', 'Mr. Sandeep Bhatia (Delivery Manager)', 'Ms. Ritu Malhotra (Talent Acquisition)', 'Mr. Kunal Sethi (Senior Architect)',
        'Ms. Shalini Nair (HR Business Partner)', 'Mr. Vivek Chopra (Engagement Manager)', 'Ms. Anita Desai (Partner)', 'Mr. Rakesh Gupta (Regional Head)', 'Ms. Meenal Joshi (Product Manager)'];
    $fbCleared = ['Strong fundamentals; explained the approach clearly.', 'Good problem-solving; solved both coding questions within time.', 'Confident and well prepared about the company and role.',
        'Sound understanding of financial statements and ratios.', 'Excellent communication; good cultural fit.', 'Cleared with a good score; recommended for the next round.', 'Structured answers in the case discussion.',
        'Good grasp of DBMS and OOP concepts.', 'Positive attitude and clear career goals.'];
    $fbTestOk = ['Cleared all sectional cut-offs.', 'Strong quantitative and logical reasoning scores.', 'Solved both coding questions; good accuracy.', 'Above the company cut-off in every section.'];
    $fbTestNo = ['Below the sectional cut-off in quantitative aptitude.', 'Could not clear the coding section.', 'Low accuracy in verbal ability.', 'Did not meet the overall cut-off score.'];
    $fbFailed = ['Needs to strengthen core concepts (OOP, DBMS).', 'Could not complete the coding problem within time.', 'Answers were not structured; communication needs work.', 'Low score in the quantitative section.',
        'Weak domain knowledge for the role.', 'Did not meet the sectional cut-off.', 'Struggled with the case framework.'];
    $offerRows = [];
    $bestOffer = [];          // student => best valid full-time/PPO package so far
    $appRows = [];            // drive_id|student_id => app row (without id)
    $interviewPlan = [];      // key => list of interviews
    foreach ($drives as $d) {
        if ($d['sim'] === 'none') {
            continue;
        }
        $elig = placement_eligible_ids($d);
        sort($elig);
        $elig = $shuffle($elig);
        $rate = $d['sim'] === 'open' ? $rnd(0.35, 0.6) : $rnd(0.62, 0.85);
        $cap = $d['sim'] === 'open' ? 70 : 95;
        $applicants = [];
        foreach ($elig as $sid) {
            if (count($applicants) >= $cap) {
                break;
            }
            // Dream-offer policy: already-placed students only apply to clearly better full-time roles.
            if (isset($bestOffer[$sid]) && $d['job_type'] !== 'internship' && (float) ($d['package_max'] ?? 0) < $bestOffer[$sid] * 1.4) {
                continue;
            }
            if ($chance($rate)) {
                $applicants[] = $sid;
            }
        }
        $rounds = placement_drive_rounds($d['rounds']);
        $driveTs = strtotime($d['drive_date']);
        $deadlineTs = strtotime($d['registration_deadline'] ?? $d['drive_date']);
        $roundTimes = [];
        foreach ($rounds as $ri => $r) {
            $roundTimes[$ri] = date('Y-m-d H:i:s', strtotime($d['drive_date'] . ' ' . ['09:30', '13:30', '16:00', '11:00'][$ri % 4]) + ($ri >= 2 ? 86400 : 0));
        }
        $selectedHere = [];
        foreach ($applicants as $sid) {
            $cg = $students[$sid]['cgpa'] ?? 6.5;
            $applied = date('Y-m-d H:i:s', mt_rand(strtotime('-12 days', $deadlineTs), min($deadlineTs + 64800, $d['sim'] === 'open' ? time() : $deadlineTs + 64800)));
            $app = ['drive_id' => $d['id'], 'student_id' => $sid, 'status' => 'applied', 'current_round' => null, 'resume_path' => null, 'remarks' => null, 'applied_at' => $applied];
            $ivs = [];
            if ($d['sim'] === 'cancelled') {
                $app['status'] = 'withdrawn';
                $app['remarks'] = 'Drive cancelled by the company.';
            } elseif ($d['sim'] === 'done' || $d['sim'] === 'ongoing') {
                if ($chance(0.02)) {
                    $app['status'] = 'withdrawn';
                    $app['remarks'] = 'Withdrew before the drive.';
                } else {
                    $status = 'selected';
                    $current = null;
                    $doneRounds = $d['sim'] === 'ongoing' ? ($d['company_id'] === $company['Amazon'] ? 1 : 2) : count($rounds);
                    foreach ($rounds as $ri => $r) {
                        $isLast = $ri === count($rounds) - 1;
                        $current = $r;
                        if ($ri >= $doneRounds) {
                            // next round scheduled, result pending
                            $ivs[] = ['round_name' => $r, 'scheduled_at' => $day(1) . ' 10:30:00', 'mode' => $d['mode'] === 'virtual' ? 'online' : 'in_person',
                                'panel' => demo_pick($panelNames), 'result' => 'pending', 'score' => null, 'feedback' => null];
                            $status = placement_round_stage($r);
                            break;
                        }
                        $when = $d['sim'] === 'ongoing'
                            ? date('Y-m-d H:i:s', strtotime($day(-1 - ($doneRounds - 1 - $ri)) . ' ' . ['09:30', '14:00'][$ri % 2]))
                            : $roundTimes[$ri];
                        $base = $ri === 0 ? 0.55 : ($isLast ? 0.82 : 0.6);
                        $p = max(0.08, min(0.95, $base + ($cg - 7) * 0.09));
                        $absent = $ri === 0 && $chance(0.03);
                        $ok = !$absent && $chance($p);
                        $test = $ri === 0 && preg_match('/assessment|test|nqt|coding|aptitude/i', $r);
                        $ivs[] = ['round_name' => $r, 'scheduled_at' => $when, 'mode' => $test || $d['mode'] === 'virtual' ? 'online' : 'in_person',
                            'panel' => $test ? null : demo_pick($panelNames),
                            'result' => $absent ? 'absent' : ($ok ? 'cleared' : 'failed'),
                            'score' => $absent ? null : round($ok ? $rnd(58, 96) : $rnd(22, 57), 1),
                            'feedback' => $absent ? 'Did not appear for the round.' : ($test ? ($ok ? demo_pick($fbTestOk) : demo_pick($fbTestNo)) : ($ok ? demo_pick($fbCleared) : demo_pick($fbFailed)))];
                        if (!$ok) {
                            $status = 'rejected';
                            break;
                        }
                        if ($isLast) {
                            $status = 'selected';
                        }
                    }
                    if (!$rounds) {
                        $status = 'selected';
                    }
                    if ($status === 'selected' && $chance(0.05)) {
                        $status = 'on_hold';
                        $app['remarks'] = 'Waitlisted — will be considered if a selected candidate declines.';
                    }
                    $app['status'] = $status;
                    $app['current_round'] = $current;
                    if ($status === 'selected') {
                        $selectedHere[] = $sid;
                    }
                }
            } elseif ($d['sim'] === 'open' && $chance(0.15)) {
                $app['status'] = 'shortlisted';
                $app['current_round'] = $rounds[0] ?? null;
                $app['remarks'] = 'Resume shortlisted by the company.';
            }
            $key = $d['id'] . '|' . $sid;
            $appRows[$key] = $app;
            $interviewPlan[$key] = $ivs;
        }
        // Companies rarely select more than ~1.5x their openings: the rest are turned down in the final round.
        $cap = $d['openings'] ? max(2, (int) ceil($d['openings'] * 1.5)) : PHP_INT_MAX;
        if (count($selectedHere) > $cap) {
            $selectedHere = $shuffle($selectedHere);
            foreach (array_slice($selectedHere, $cap) as $sid) {
                $key = $d['id'] . '|' . $sid;
                $appRows[$key]['status'] = 'rejected';
                $lastIv = count($interviewPlan[$key]) - 1;
                $interviewPlan[$key][$lastIv]['result'] = 'failed';
                $interviewPlan[$key][$lastIv]['score'] = round($rnd(45, 57), 1);
                $interviewPlan[$key][$lastIv]['feedback'] = 'Good profile, but the company closed its positions with stronger candidates.';
            }
            $selectedHere = array_slice($selectedHere, 0, $cap);
            sort($selectedHere);
        }
        // Offers
        if ($d['sim'] === 'done' && $d['offers']) {
            foreach ($selectedHere as $sid) {
                $cg = $students[$sid]['cgpa'] ?? 6.5;
                if ($d['ppo'] && !$chance(0.72)) {
                    continue; // internship completed without a PPO
                }
                if (!$d['ppo'] && $d['drive_date'] >= $day(-10) && $chance(0.45)) {
                    continue; // selected, the company has not released this offer yet
                }
                $f = max(0, min(1, ($cg - 6.2) / 3.4 + $rnd(-0.18, 0.18)));
                $pkg = round(((float) $d['package_min'] + ((float) $d['package_max'] - (float) $d['package_min']) * $f) * 4) / 4;
                if ($d['ppo']) {
                    $offerDate = $minD($day(mt_rand(-50, -8), $prev ? $prev['end_date'] : $today), $today);
                } else {
                    $offerDate = $minD(date('Y-m-d', strtotime($d['drive_date'] . ' +' . mt_rand(2, 9) . ' days')), $today);
                }
                $age = (strtotime($today) - strtotime($offerDate)) / 86400;
                if (isset($bestOffer[$sid]) && $bestOffer[$sid] >= $pkg) {
                    $status = 'declined';
                } elseif ($age < 10) {
                    $status = $chance(0.65) ? 'offered' : 'accepted';
                } else {
                    $status = demo_weighted(['accepted' => 86, 'offered' => 8, 'declined' => 6]);
                }
                if ($status === 'accepted' && isset($bestOffer[$sid])) {
                    // a better offer arrived: the earlier accepted offer is declined
                    foreach ($offerRows as &$prevOffer) {
                        if ($prevOffer['student_id'] === $sid && $prevOffer['status'] === 'accepted') {
                            $prevOffer['status'] = 'declined';
                            $prevOffer['remarks'] = 'Declined after accepting a better offer.';
                            $prevOffer['responded_at'] = $offerDate;
                        }
                    }
                    unset($prevOffer);
                }
                if (in_array($status, ['offered', 'accepted'], true)) {
                    $bestOffer[$sid] = max($bestOffer[$sid] ?? 0, $pkg);
                }
                $offerRows[] = ['drive_key' => $d['id'] . '|' . $sid, 'drive_id' => $d['id'], 'student_id' => $sid, 'company_id' => $d['company_id'],
                    'job_role' => $d['ppo']
                        ? strtr(preg_replace('/\s+Intern$/', '', trim($d['job_role'])), ['Software Engineering' => 'Software Engineer', 'Product Engineering' => 'Product Engineer'])
                        : trim(explode(',', $d['job_role'])[$pkg >= ((float) $d['package_min'] + (float) $d['package_max']) / 2 && str_contains($d['job_role'], ',') ? 1 : 0]),
                    'package_lpa' => $pkg, 'offer_date' => $offerDate, 'joining_date' => $day(mt_rand(0, 40), $joinBase),
                    'location' => trim(explode('/', (string) $d['location'])[0]) ?: null, 'status' => $status,
                    'responded_at' => in_array($status, ['accepted', 'declined'], true) ? $minD($day(mt_rand(1, 6), $offerDate), $today) : null,
                    'remarks' => $status === 'declined' ? 'Declined — holds a better offer.' : ($d['ppo'] ? 'Pre-placement offer after the summer internship.' : null)];
            }
        }
    }

    // insert applications, then interviews and offers with the generated ids
    demo_bulk_insert('placement_applications', array_values($appRows));
    $appIds = [];
    foreach (db_all('SELECT id, drive_id, student_id FROM placement_applications') as $r) {
        $appIds[$r['drive_id'] . '|' . $r['student_id']] = (int) $r['id'];
    }
    $ivRows = [];
    foreach ($interviewPlan as $key => $ivs) {
        foreach ($ivs as $iv) {
            $ivRows[] = ['application_id' => $appIds[$key], 'round_name' => $iv['round_name'], 'scheduled_at' => $iv['scheduled_at'], 'mode' => $iv['mode'], 'panel' => $iv['panel'],
                'result' => $iv['result'], 'score' => $iv['score'], 'feedback' => $iv['feedback'], 'created_at' => min($iv['scheduled_at'], $now)];
        }
    }
    demo_bulk_insert('placement_interviews', $ivRows);
    // Minimal one-page PDF offer letters (private storage) for most accepted / joined offers.
    $pdf = function (array $lines): string {
        $text = "BT /F1 11 Tf 56 780 Td 16 TL\n";
        foreach ($lines as $i => $l) {
            $l = preg_replace('/[^\x20-\x7E]/', '', str_replace(['—', '–', '₹', '·'], ['-', '-', 'Rs. ', '-'], $l));
            $l = strtr($l, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
            $text .= ($i ? 'T* ' : '') . '(' . $l . ") Tj\n";
        }
        $text .= 'ET';
        $objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length ' . strlen($text) . " >>\nstream\n" . $text . "\nendstream"];
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $i => $o) {
            $offsets[] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $out .= sprintf("%010d 00000 n \n", $off);
        }
        return $out . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    };
    if (!is_dir($letterDir)) {
        @mkdir($letterDir, 0775, true);
    }
    $names = db_pairs("SELECT id, TRIM(CONCAT_WS(' ', first_name, last_name)) FROM students");
    $companyNames = array_flip($company);
    $offers = [];
    foreach ($offerRows as $n => $o) {
        $key = $o['drive_key'];
        unset($o['drive_key']);
        $letter = null;
        if (in_array($o['status'], ['accepted', 'joined'], true) && $chance(0.75) && is_dir($letterDir)) {
            $file = sprintf('offer-%04d-%s.pdf', $n + 1, substr(md5($key . $o['offer_date']), 0, 10));
            $body = $pdf([
                strtoupper($companyNames[$o['company_id']] ?? 'Company'), 'Offer of Employment', '', 'Date: ' . date('d M Y', strtotime($o['offer_date'])), '',
                'Dear ' . ($names[$o['student_id']] ?? 'Candidate') . ',', '',
                'We are pleased to offer you the position of ' . $o['job_role'] . '.',
                'Annual CTC: ' . rtrim(rtrim(number_format((float) $o['package_lpa'], 2), '0'), '.') . ' LPA   Location: ' . ($o['location'] ?? 'As assigned'),
                'Expected date of joining: ' . date('d M Y', strtotime($o['joining_date'])) . ' (subject to successful completion of your degree).', '',
                'Please confirm your acceptance through the Training & Placement Cell.', '', 'Talent Acquisition Team',
                '(Demo document generated for GIMT SmartCampus)',
            ]);
            if (@file_put_contents($letterDir . '/' . $file, $body) !== false) {
                $letter = 'storage/private/placement-offers/demo/' . $file;
            }
        }
        $offers[] = ['application_id' => $appIds[$key] ?? null] + $o + ['offer_letter' => $letter, 'created_by' => $creator, 'created_at' => $o['offer_date'] . ' 15:30:00'];
    }
    demo_bulk_insert('placement_offers', $offers);

    // ---------------------------------------------------------------- trainings & mock tests
    $finalYear = db_all("SELECT s.id, s.program_id, s.current_semester FROM students s JOIN programs p ON p.id = s.program_id
                         WHERE s.status = 'active' AND p.total_semesters > 2 AND s.current_semester >= p.total_semesters - 1 ORDER BY s.id");
    $mbaFirst = db_all("SELECT s.id, s.program_id FROM students s JOIN programs p ON p.id = s.program_id WHERE s.status = 'active' AND p.code = 'MBA' AND s.current_semester = 1 ORDER BY s.id");
    $pool = function (array $programs = [], int $cap = 120) use ($finalYear, $shuffle): array {
        $ids = array_values(array_map(fn ($r) => (int) $r['id'], array_filter($finalYear, fn ($r) => !$programs || in_array((int) $r['program_id'], $programs, true))));
        sort($ids);
        return array_slice($shuffle($ids), 0, $cap);
    };
    $T = [];
    if ($prev) {
        $py = (int) substr($prev['end_date'], 0, 4);
        $T[] = ['Aptitude Foundation Bootcamp', 'training', 'offline', 'Aptiverse Learning — Mr. Rajeev Malhotra', null, null, $py . '-02-02', $py . '-02-14', 24, 'Seminar Hall, Academic Block A', null, 'completed', [], 140,
            'Quantitative aptitude, logical reasoning and verbal ability fundamentals for the pre-final year.'];
        $T[] = ['Mock Aptitude Test — Series 1', 'mock_test', 'online', 'Training & Placement Cell', null, null, $py . '-03-07', null, 1.5, 'Computer Lab 2 & 3', 100, 'completed', [], 130,
            'Company-pattern online test: 30 quant, 30 reasoning, 20 verbal, 20 technical MCQs.'];
        $T[] = ['Resume Writing & LinkedIn Profile Workshop', 'workshop', 'offline', 'Ms. Neha Kapoor (TPO)', null, null, $py . '-04-11', null, 3, 'Conference Room, Admin Block', null, 'completed', [], 90,
            'ATS-friendly resumes, project write-ups and LinkedIn profile optimisation.'];
    }
    $td = fn (int $o) => max($day($o), $day(8, $curStart));
    $T[] = ['Placement Orientation & Policy Briefing ' . $cur['name'], 'seminar', 'offline', 'Ms. Neha Kapoor (TPO)', null, null, $td(-82), null, 2, 'Main Auditorium', null, 'completed', [], 220,
        'Placement policy (one-student-one-job, dream offers), registration process, code of conduct and the season calendar.'];
    $T[] = ['Quantitative Aptitude & Logical Reasoning Crash Course', 'training', 'offline', 'Aptiverse Learning — Mr. Rajeev Malhotra', null, null, $td(-77), $td(-65), 30, 'Seminar Hall, Academic Block A', null, 'completed', array_merge($tech, $mgmt), 160,
        'Ten-day intensive covering arithmetic, data interpretation, puzzles and time management.'];
    $T[] = ['Mock Test — TCS NQT Pattern', 'mock_test', 'online', 'Training & Placement Cell', null, null, $td(-63), null, 2, 'Computer Lab 1–3', 100, 'completed', $tech, 140,
        'Numerical, verbal and reasoning ability plus programming logic and hands-on coding.'];
    $T[] = ['Group Discussion Practice Lab', 'workshop', 'offline', 'Dr. Alok Verma (Dept. of Management Studies)', $pid['MBA'] ?? null, 3, $td(-49), null, 4, 'Seminar Room 204', 20, 'completed', $mba, 60,
        'Moderated GD rounds on current affairs and business topics with individual feedback.'];
    $T[] = ['Mock Technical Interviews — Java, DBMS & DSA', 'mock_interview', 'offline', 'Alumni panel (NovaByte, HCLTech, Nagarro)', null, null, $td(-39), $td(-38), 12, 'Placement Cell Interview Rooms', 50, 'completed', $engg, 70,
        'One-to-one 25-minute technical interviews with alumni engineers; scored on fundamentals, coding and communication.'];
    $T[] = ['Banking & Finance Interview Preparation', 'training', 'offline', 'Mr. Harish Bansal (ex-Branch Manager)', null, null, $td(-28), $td(-27), 8, 'Seminar Room 204', null, 'completed', array_merge($mgmt, $comm), 90,
        'Banking awareness, KYC/AML basics, sales role-plays and HR interview practice for bank recruitment drives.'];
    $T[] = ['Mock Test — Data Interpretation & Verbal Ability', 'mock_test', 'online', 'Training & Placement Cell', null, null, $day(-6), null, 1.5, 'Computer Lab 2', 60, 'completed', [], 150,
        'Sectional test ahead of the HUL, Fractal and Tech Mahindra drives.'];
    $T[] = ['Advanced Excel & Power BI for Analysts', 'certification', 'online', 'DataCraft Academy — Ms. Kritika Arora', null, null, $day(-4), $day(10), 20, 'Online — MS Teams', 100, 'ongoing',
        array_merge($P(['BSCDS', 'BBA', 'MBA', 'BCOMH'])), 80, 'Lookups, pivot tables, Power Query and dashboards; certificate on clearing the final assessment.'];
    $T[] = ['Mock HR Interviews with Industry Panel', 'mock_interview', 'hybrid', 'Industry HR panel (HDFC Bank, Accenture, Deloitte)', null, null, $day(5), $day(6), 10, 'Placement Cell Interview Rooms / Zoom', 20, 'scheduled', [], 90,
        'HR and behavioural interview practice for students still to be placed.'];
    $T[] = ['Corporate Etiquette & Communication Skills', 'training', 'offline', 'Ms. Sunita Rawat (Soft-skills Trainer)', null, null, $day(12), $day(13), 6, 'Seminar Hall, Academic Block A', null, 'scheduled', [], 120,
        'Business communication, email etiquette, grooming and workplace conduct.'];
    $T[] = ['Mock Test — Product Company Coding Round', 'mock_test', 'online', 'Training & Placement Cell', null, null, $day(20), null, 2, 'Computer Lab 1', 100, 'scheduled', $engg, 60,
        'Two DSA problems + debugging task in 90 minutes (HackerRank style) ahead of the NovaByte and ZS drives.'];
    $T[] = ['Summer Internship Readiness Workshop — MBA', 'workshop', 'offline', 'Ms. Neha Kapoor (TPO)', $pid['MBA'] ?? null, 1, $day(26), null, 3, 'Seminar Room 204', null, 'scheduled', [], 0,
        'Internship search, SIP report expectations and preparation for the Flipkart summer internship drive.'];
    $T[] = ['Pre-Placement Talk — Life at NovaByte', 'seminar', 'offline', 'NovaByte Technologies leadership team', null, null, $day(3), null, 1.5, 'Main Auditorium', null, 'cancelled', $tech, 0,
        'Rescheduled by the company to the week before its drive.'];

    $partRows = [];
    foreach ($T as [$title, $type, $mode, $trainer, $progId, $sem, $start, $end, $hours, $venue, $max, $status, $programs, $count, $desc]) {
        $tid = db_insert('placement_trainings', [
            'title' => $title, 'type' => $type, 'mode' => $mode, 'trainer' => $trainer, 'program_id' => $progId, 'semester_no' => $sem, 'start_date' => $start, 'end_date' => $end,
            'duration_hours' => $hours, 'venue' => $venue, 'max_marks' => $max, 'participants_count' => 0, 'description' => $desc, 'status' => $status,
            'created_at' => date('Y-m-d H:i:s', strtotime($start . ' -12 days 10:00')),
        ]);
        $ids = $count ? $pool($programs, $count) : [];
        if (str_starts_with($title, 'Summer Internship Readiness')) {
            $ids = array_slice(array_map(fn ($r) => (int) $r['id'], $mbaFirst), 0, 70);
        }
        foreach ($ids as $sid) {
            $cg = $students[$sid]['cgpa'] ?? 6.5;
            $attended = $status === 'completed' ? ($chance(0.88) ? 1 : 0) : ($status === 'ongoing' ? ($chance(0.8) ? 1 : 0) : 0);
            $score = null;
            if ($attended && $max && $status === 'completed') {
                $score = round(max(0, min((float) $max, (float) $max * (0.32 + ($cg - 5) * 0.09 + $rnd(-0.12, 0.12)))) * 2) / 2;
            }
            $partRows[] = ['training_id' => $tid, 'student_id' => $sid, 'attended' => $attended, 'score' => $score,
                'remarks' => $score !== null && $score >= (float) $max * 0.85 ? 'Top performer' : (!$attended && $status === 'completed' ? 'Absent' : null)];
        }
    }
    demo_bulk_insert('placement_training_participants', $partRows);
    db_exec('UPDATE placement_trainings t SET participants_count = (SELECT COUNT(*) FROM placement_training_participants tp WHERE tp.training_id = t.id)');

    // The companies were re-created above: re-attach the bundled official recruiter logos (idempotent seeder 95) so a
    // standalone re-run of this seeder does not leave the recruiter lists without their logos.
    $logoSeeder = __DIR__ . '/95_recruiter_logos.php';
    if (is_file($logoSeeder)) {
        $attach = require $logoSeeder;
        if (is_callable($attach)) {
            $attach($opts);
        }
    }
};
