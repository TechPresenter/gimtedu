<?php
/**
 * Demo data for the CMS content unit (brief §24/§25) — deterministic and re-runnable.
 *
 *   - media library: every bundled photo in assets/images/site/*.jpg (real dimensions/sizes) + 4 generated PDF documents
 *   - 6 blog categories, 24 posts (admissions, campus life, placements, research, events, achievements) with SEO for most
 *     published posts; 2 scheduled + 3 drafts; dates anchored on today
 *   - 6 gallery albums (5 published, 1 draft) with items taken from the media library
 *   - 31 FAQs across admissions / fees / academics / hostel / placement / campus / general (home + contact flags)
 *   - 12 testimonials from students, alumni, parents and a recruiter linked to programs
 *
 * Owns: media, blog_categories, blog_posts, galleries, gallery_items, faqs, testimonials and the seo_settings rows with
 * entity_type = 'post'.
 */
return function (array $opts): void {
    require_once __DIR__ . '/lib/demo_data.php';
    require_once APP_ROOT . '/app/services/media.php';
    demo_seed(6161);

    $now = time();
    $at = fn (int $days, string $time = '10:30:00'): string => date('Y-m-d ' . $time, strtotime(($days >= 0 ? '+' : '') . $days . ' days', $now));

    // ---------------------------------------------------------------- clear previous demo data (children first)
    db_exec("DELETE FROM seo_settings WHERE entity_type = 'post'");
    foreach (['gallery_items', 'galleries', 'testimonials', 'faqs', 'blog_posts', 'blog_categories', 'media'] as $t) {
        db_exec("DELETE FROM $t");
        db_exec("ALTER TABLE $t AUTO_INCREMENT = 1");
    }

    $userId = function (string $username) {
        static $cache = [];
        if (!array_key_exists($username, $cache)) {
            $cache[$username] = db_value('SELECT id FROM users WHERE username = ?', [$username]);
        }
        return $cache[$username] ? (int) $cache[$username] : null;
    };
    $adminId = $userId('admin') ?: (int) db_value('SELECT MIN(id) FROM users');
    $contentId = $userId('content') ?: $adminId;
    $placementId = $userId('placement') ?: $contentId;
    $academicsId = $userId('academics') ?: $contentId;
    $admissionsId = $userId('admissions') ?: $contentId;

    // ---------------------------------------------------------------- media: bundled photography
    $photos = [
        'campus-main.jpg' => ['Main Campus', 'campus', 'GIMT main campus building in Knowledge Park, Greater Noida', 'The main campus at dusk.'],
        'campus-building.jpg' => ['Administrative Block', 'campus', 'Front view of the GIMT administrative block with landscaped entrance', 'Administrative block and main entrance.'],
        'campus-academic-block.jpg' => ['Academic Block', 'campus', 'Academic block exterior with classrooms and faculty offices', 'Academic block housing classrooms and faculty cabins.'],
        'campus-library.jpg' => ['Central Library', 'campus', 'Reading hall of the central library and learning centre', 'Central library and learning centre.'],
        'campus-hostel.jpg' => ['Hostel Building', 'campus', 'On-campus hostel building for students', 'Separate hostels for boys and girls inside the campus.'],
        'facility-classroom.jpg' => ['Smart Classroom', 'campus', 'Smart classroom with interactive display and tiered seating', 'Smart classrooms with interactive panels.'],
        'facility-labs.jpg' => ['Computer Laboratory', 'campus', 'Students working on desktops in the computer laboratory', 'Computer labs with licensed software.'],
        'facility-library.jpg' => ['Library Reading Hall', 'campus', 'Students reading in the quiet zone of the library', 'Quiet reading zone of the library.'],
        'facility-hostel.jpg' => ['Hostel Room', 'campus', 'Furnished twin-sharing hostel room', 'Furnished twin-sharing hostel rooms.'],
        'facility-sports.jpg' => ['Sports Courts', 'events', 'Outdoor basketball and volleyball courts on campus', 'Outdoor courts used for the annual sports meet.'],
        'facility-green-campus.jpg' => ['Green Campus', 'campus', 'Tree-lined walkway across the green campus lawns', 'Tree-lined walkways across the campus.'],
        'students-atrium.jpg' => ['Student Atrium', 'campus', 'Students walking through the campus atrium between classes', 'The atrium between classes.'],
        'students-lawn.jpg' => ['Campus Lawns', 'campus', 'Students studying together on the campus lawn', 'Group study on the central lawn.'],
        'students-laptop.jpg' => ['Students Collaborating', 'general', 'Two students collaborating on a laptop', 'Project work in the learning commons.'],
        'hero-campus-students.jpg' => ['Students on Campus', 'banners', 'Smiling students standing in front of the campus building', 'Hero image used on the home page.'],
        'dashboard-banner.jpg' => ['Campus Panorama', 'banners', 'Wide view of the campus buildings and lawns', null],
        'graduation.jpg' => ['Convocation Ceremony', 'events', 'Graduates throwing their caps at the convocation ceremony', 'Convocation day celebrations.'],
        'news-campus.jpg' => ['Campus Event', 'events', 'Students gathered at a campus event', 'Students at a campus event.'],
        'placement-student.jpg' => ['Placement Interview', 'placements', 'Student in formal wear ready for a placement interview', 'Placement season on campus.'],
        'program-bba.jpg' => ['BBA Classroom', 'programs', 'BBA students in a case-study discussion', 'BBA program.'],
        'program-mba.jpg' => ['MBA Students', 'programs', 'MBA students presenting a business plan', 'MBA program.'],
        'program-btech.jpg' => ['B.Tech Lab Session', 'programs', 'Engineering students working on a hardware project', 'B.Tech program.'],
        'program-bcom.jpg' => ['B.Com Students', 'programs', 'Commerce students in an accounting lecture', 'B.Com (Hons) program.'],
        'program-dca.jpg' => ['Computer Applications', 'programs', 'Students learning computer applications in the lab', 'Diploma in Computer Applications.'],
        'program-digital-marketing.jpg' => ['Digital Marketing', 'programs', 'Digital marketing workshop with analytics dashboards', 'Certificate in Digital Marketing.'],
        'leader-1.jpg' => ['Dr. R.K. Sharma', 'people', 'Portrait of Dr. R.K. Sharma, Chairman', 'Chairman.'],
        'leader-2.jpg' => ['Prof. Neha Verma', 'people', 'Portrait of Prof. Neha Verma, Director', 'Director.'],
        'leader-3.jpg' => ['Dr. Amit Kumar', 'people', 'Portrait of Dr. Amit Kumar, Academic Dean', 'Academic Dean.'],
    ];
    $mediaRows = [];
    $i = 0;
    foreach ($photos as $file => [$title, $folder, $alt, $caption]) {
        $rel = 'assets/images/site/' . $file;
        $abs = APP_ROOT . '/' . $rel;
        if (!is_file($abs)) {
            continue;
        }
        $dims = @getimagesize($abs);
        $mediaRows[] = [
            'filename' => $file, 'original_name' => $file, 'path' => $rel, 'mime' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => filesize($abs), 'type' => 'image',
            'width' => $dims ? $dims[0] : null, 'height' => $dims ? $dims[1] : null,
            // a few images intentionally without alt text so the "missing alt text" filter has work to do
            'alt_text' => in_array($file, ['dashboard-banner.jpg', 'program-dca.jpg', 'students-laptop.jpg'], true) ? null : $alt,
            'title' => $title, 'caption' => $caption, 'folder' => $folder, 'uploaded_by' => $i % 3 === 0 ? $adminId : $contentId,
            'created_at' => $at(-172 + $i * 6, sprintf('%02d:%02d:00', 9 + $i % 8, ($i * 7) % 60)),
        ];
        $i++;
    }

    // ---------------------------------------------------------------- media: generated PDF documents
    $pdf = function (string $title, array $lines): string {
        $esc = fn (string $s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], iconv('UTF-8', 'Windows-1252//TRANSLIT', $s) ?: $s);
        $stream = "BT /F2 22 Tf 56 770 Td (" . $esc('Global Institute of Management & Technology') . ") Tj ET\n"
            . "BT /F2 16 Tf 56 738 Td (" . $esc($title) . ") Tj ET\n0.13 0.58 0.25 RG 2 w 56 726 m 539 726 l S\nBT /F1 11 Tf 56 700 Td 17 TL\n";
        foreach ($lines as $l) {
            $stream .= '(' . $esc($l) . ") Tj T*\n";
        }
        $stream .= "ET\nBT /F1 9 Tf 56 60 Td (" . $esc('Knowledge Park, Greater Noida  |  info@gimt.ac.in  |  +91 9955446477') . ") Tj ET\n";
        $objs = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>',
            "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $n => $o) {
            $offsets[] = strlen($out);
            $out .= ($n + 1) . " 0 obj\n" . $o . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $out .= sprintf("%010d 00000 n \n", $off);
        }
        return $out . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    };
    $programNames = db_column("SELECT CONCAT(short_name, ' - ', name) FROM programs WHERE status = 'active' ORDER BY sort_order, name");
    $docs = [
        'gimt-prospectus-2026-27.pdf' => ['Prospectus 2026-27', 'Admissions prospectus with programs, eligibility and the admission process.', array_merge(
            ['Programs offered in the 2026-27 session:', ''], array_map(fn ($p) => '  - ' . $p, array_slice($programNames, 0, 14)),
            ['', 'Admission process: apply online, upload documents, counselling / interview, fee payment.', 'Scholarships: merit and need-based scholarships for eligible students.'])],
        'fee-structure-2026-27.pdf' => ['Fee Structure 2026-27', 'Program-wise tuition, hostel and transport fees for the 2026-27 session.', [
            'Tuition fees are payable semester-wise.', 'Hostel and transport fees are charged separately.', 'Merit scholarships are adjusted against tuition fees.',
            'Refund rules follow the university and regulatory guidelines.', '', 'Contact the accounts office for the program-wise fee schedule.']],
        'academic-calendar-2026-27.pdf' => ['Academic Calendar 2026-27', 'Semester dates, examinations, holidays and events for 2026-27.', [
            'Odd semester classes begin: July 2026', 'Mid-term examinations: September 2026', 'End-term examinations: December 2026',
            'Even semester classes begin: January 2027', 'Annual fest and sports meet: February 2027', 'End-term examinations: May 2027']],
        'anti-ragging-policy.pdf' => ['Anti-Ragging Policy', 'Zero-tolerance anti-ragging policy, committee and helpline details.', [
            'GIMT follows a zero-tolerance policy towards ragging in any form.', 'Every student and parent submits an anti-ragging undertaking at admission.',
            'The anti-ragging committee and squad can be reached through the student support desk.', 'National anti-ragging helpline: 1800-180-5522']],
    ];
    $docDir = APP_ROOT . '/assets/uploads/media/documents';
    if (!is_dir($docDir)) {
        @mkdir($docDir, 0775, true);
    }
    $j = 0;
    foreach ($docs as $file => [$title, $caption, $lines]) {
        $content = $pdf($title, $lines);
        if (@file_put_contents($docDir . '/' . $file, $content) === false) {
            continue;
        }
        $mediaRows[] = [
            'filename' => $file, 'original_name' => $file, 'path' => 'assets/uploads/media/documents/' . $file, 'mime' => 'application/pdf', 'extension' => 'pdf',
            'size_bytes' => strlen($content), 'type' => 'pdf', 'width' => null, 'height' => null, 'alt_text' => null, 'title' => $title, 'caption' => $caption,
            'folder' => 'documents', 'uploaded_by' => $j % 2 ? $admissionsId : $contentId, 'created_at' => $at(-120 + $j * 9, '11:15:00'),
        ];
        $j++;
    }

    // ---------------------------------------------------------------- media: a spreadsheet built from the real programs table
    require_once APP_ROOT . '/app/xlsx.php';
    $progRows = db_all("SELECT p.short_name, p.name, p.level, p.duration_label, p.total_semesters, p.intake_capacity, d.name AS dept
                        FROM programs p LEFT JOIN departments d ON d.id = p.department_id WHERE p.status = 'active' ORDER BY p.sort_order, p.name");
    if ($progRows && function_exists('xlsx_build')) {
        $file = 'programs-and-intake-2026-27.xlsx';
        $xlsx = xlsx_build(['Program', 'Full name', 'Level', 'Duration', 'Semesters', 'Intake', 'Department'],
            array_map(fn ($r) => [$r['short_name'], $r['name'], ucfirst((string) $r['level']), $r['duration_label'], $r['total_semesters'], $r['intake_capacity'], $r['dept']], $progRows),
            'Programs 2026-27');
        if (@file_put_contents($docDir . '/' . $file, $xlsx) !== false) {
            $mediaRows[] = [
                'filename' => $file, 'original_name' => $file, 'path' => 'assets/uploads/media/documents/' . $file,
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'extension' => 'xlsx', 'size_bytes' => strlen($xlsx), 'type' => 'document',
                'width' => null, 'height' => null, 'alt_text' => null, 'title' => 'Programs & Intake 2026-27', 'caption' => 'Program-wise duration, semesters and intake for the 2026-27 session.',
                'folder' => 'documents', 'uploaded_by' => $admissionsId, 'created_at' => $at(-25, '10:40:00'),
            ];
        }
    }
    demo_bulk_insert('media', $mediaRows);
    $mediaByFile = db_pairs('SELECT filename, id FROM media');
    $img = fn (string $file): string => 'assets/images/site/' . $file;

    // ---------------------------------------------------------------- blog categories
    $cats = [
        ['Admissions', 'admissions', 'Admission updates, eligibility, scholarships and application tips.'],
        ['Campus Life', 'campus-life', 'Life at GIMT — clubs, fests, hostels and the campus community.'],
        ['Placements', 'placements', 'Placement season, internships, recruiters and career preparation.'],
        ['Research & Innovation', 'research-innovation', 'Student projects, faculty research and the innovation cell.'],
        ['Events', 'events', 'Seminars, expert talks, sports and cultural events on campus.'],
        ['Achievements', 'achievements', 'Awards and recognitions earned by our students, faculty and alumni.'],
    ];
    $catRows = [];
    foreach ($cats as $k => [$name, $slug, $desc]) {
        $catRows[] = ['name' => $name, 'slug' => $slug, 'description' => $desc, 'sort_order' => ($k + 1) * 10, 'status' => 'active', 'created_at' => $at(-260)];
    }
    demo_bulk_insert('blog_categories', $catRows);
    $catId = db_pairs('SELECT slug, id FROM blog_categories');

    // ---------------------------------------------------------------- blog posts
    $p = fn (string ...$paras): string => implode('', array_map(fn ($x) => str_starts_with($x, '<') ? $x : '<p>' . $x . '</p>', $paras));
    $ul = fn (array $items): string => '<ul>' . implode('', array_map(fn ($x) => '<li>' . $x . '</li>', $items)) . '</ul>';
    $h2 = fn (string $t): string => '<h2>' . $t . '</h2>';
    $session = (string) (db_value('SELECT name FROM academic_sessions WHERE is_current = 1') ?: '2026-27');
    $recruiters = (int) (db_table_exists('companies') ? db_value("SELECT COUNT(*) FROM companies WHERE status = 'active'") : 0);
    $recruiterLine = $recruiters >= 10 ? "More than $recruiters companies are registered with the placement cell this year" : 'Recruiters from IT services, consulting, banking and retail are registered with the placement cell this year';

    // [title, category slug, type, image, tags, excerpt, content, author, featured, status, day offset, views]
    $posts = [
        ["Admissions Open for $session: Programs, Eligibility and Key Dates", 'admissions', 'news', 'hero-campus-students.jpg', 'admissions, ' . $session . ', eligibility',
            "Applications are now open for undergraduate, postgraduate, diploma and certificate programs at GIMT for the $session session. Here is everything you need to apply.",
            $p("Applications for the $session academic session are now open across all GIMT programs — BBA, MBA, B.Tech (CSE and AI & DS), BCA, MCA, B.Com (Hons), M.Com, B.Sc (Data Science), DCA and our industry certificates in Digital Marketing and Data Analytics.",
                $h2('Who can apply'),
                $ul(['<strong>Undergraduate programs:</strong> Class 12 from a recognised board with the subject requirements of the program.', '<strong>Postgraduate programs:</strong> a bachelor\'s degree in a relevant discipline; entrance scores are considered where applicable.', '<strong>Diploma and certificates:</strong> Class 12 or equivalent; working professionals are welcome.']),
                $h2('How to apply'),
                'Fill in the online application, upload your documents and pay the registration fee. Our admissions team verifies documents within three working days and schedules counselling or an interview where required. Seats are confirmed once the first semester fee is paid.',
                'Have questions? Book a free counselling call or visit the campus at Knowledge Park, Greater Noida — the admissions office is open Monday to Saturday, 9 am to 6 pm.'),
            $admissionsId, true, 'published', -150, 4820],
        ['BBA or B.Com (Hons)? How to Choose After Class 12', 'admissions', 'blog', 'program-bba.jpg', 'bba, bcom, career guidance',
            'Both degrees open doors to business careers, but they build different strengths. A practical comparison to help students and parents decide.',
            $p('Every admission season, the question we hear most at the counselling desk is whether to pick BBA or B.Com (Hons). Both are three-year undergraduate degrees in business, yet the classroom experience and the career paths they lead to are quite different.',
                $h2('BBA builds managers'),
                'BBA focuses on management functions — marketing, human resources, operations and entrepreneurship. Learning is case-based, with presentations, live projects and a summer internship. It is a natural foundation for an MBA.',
                $h2('B.Com (Hons) builds specialists'),
                'B.Com (Hons) goes deep into accounting, taxation, auditing, corporate law and finance. It suits students aiming for CA, CS, CMA, banking or financial analysis roles.',
                $ul(['Enjoy leading teams and pitching ideas? Choose BBA.', 'Enjoy numbers, rules and analysis? Choose B.Com (Hons).', 'Not sure yet? Talk to our counsellors and sit in on a demo class of each program.'])),
            $contentId, false, 'published', -132, 2310],
        ['Merit and Need-Based Scholarships at GIMT: A Complete Guide', 'admissions', 'blog', 'graduation.jpg', 'scholarships, fees, financial aid',
            'Scholarships at GIMT reward academic merit, sporting talent and support students from economically weaker families. Learn who is eligible and how to apply.',
            $p('We believe that talent should never be held back by finances. Every year a significant share of our students receive scholarships that reduce their tuition fees.',
                $h2('Types of scholarships'),
                $ul(['<strong>Merit scholarship</strong> — based on Class 12 or graduation scores, renewed every year on academic performance.', '<strong>Need-based support</strong> — for students from families with limited income, on verification of documents.', '<strong>Sports and cultural excellence</strong> — for students who have represented their district, state or country.', '<strong>Single girl child and wards of defence personnel</strong> — special concessions on tuition fees.']),
                $h2('How to apply'),
                'Select the scholarship while filling the admission form and upload the supporting documents. The scholarship committee reviews applications every fortnight and the approved amount is adjusted against your semester fee.'),
            $admissionsId, false, 'published', -118, 3650],
        ['Your GIMT Admission Document Checklist', 'admissions', 'blog', 'campus-building.jpg', 'admissions, documents, checklist',
            'Keep these documents ready before you start the online application — it saves time during verification.',
            $p('A complete application is verified faster. Before you begin, scan the following documents as clear PDFs or photos (maximum 5 MB each):',
                $ul(['Class 10 and Class 12 marksheets and pass certificates', 'Graduation marksheets (postgraduate programs)', 'Transfer certificate and migration certificate', 'Aadhaar card or another government photo ID', 'Recent passport-size photograph', 'Category or EWS certificate, if applicable', 'Entrance exam scorecard, if applicable']),
                'Original documents are checked once on the day of reporting. If a document is pending — for example, results awaited — you can still apply and upload it later from the applicant portal.'),
            $admissionsId, false, 'draft', -6, 0],
        ['Utkarsh 2026: Three Days of Music, Dance and Ideas', 'campus-life', 'news', 'news-campus.jpg', 'utkarsh, fest, cultural',
            'The annual cultural fest brought together students from 30 colleges for competitions, performances and a startup expo.',
            $p('Utkarsh, our annual cultural and management fest, filled the campus with colour for three days. Students organised more than twenty events — from battle of the bands and street play to business quiz, B-plan pitching and a fashion show.',
                'The startup expo was a highlight this year: twelve student teams showcased products ranging from a campus food-ordering app to biodegradable packaging made from agricultural waste.',
                $h2('Highlights'),
                $ul(['Inter-college dance competition with participation from Delhi NCR colleges', 'B-plan competition judged by alumni entrepreneurs', 'Evening concert by an independent band from Delhi', 'Food and craft stalls run by student clubs']),
                'Thank you to the 200+ student volunteers and the faculty coordinators who made Utkarsh 2026 possible.'),
            $contentId, true, 'published', -34, 3920],
        ['Inside the Renovated Central Library and Digital Learning Centre', 'campus-life', 'news', 'campus-library.jpg', 'library, infrastructure, e-resources',
            'Longer hours, a quiet zone, group discussion rooms and access to e-journals — here is what is new at the central library.',
            $p('The central library reopens this semester after a complete renovation. The reading hall now seats more students, with a dedicated quiet zone and six bookable group discussion rooms.',
                $ul(['Open from 8 am to 10 pm on working days, with extended hours during examinations', 'Self-issue kiosks and online renewals through the student portal', 'E-journals, e-books and databases available on campus Wi-Fi and through remote login', 'A digital learning centre with desktops for coding practice and online certifications']),
                'Students can reserve discussion rooms from the library desk. Faculty members can request books for course reserves through the librarian.'),
            $academicsId, false, 'published', -96, 1780],
        ['Hostel Life at GIMT: What First-Year Students Should Know', 'campus-life', 'blog', 'campus-hostel.jpg', 'hostel, first year, student life',
            'From room allocation and mess timings to security and weekend outings — a friendly guide for students moving into the hostel.',
            $p('Moving away from home is a big step. Our hostels are designed to make the transition comfortable and safe.',
                $h2('Rooms and facilities'),
                'Separate hostels for boys and girls offer furnished twin and triple-sharing rooms, 24x7 power backup, Wi-Fi, RO drinking water, laundry service and a common room with indoor games.',
                $h2('Mess and timings'),
                'The mess serves four vegetarian meals a day with a weekly menu decided with student representatives. Hostel in-time is 9:30 pm; late entries need prior permission from the warden.',
                $h2('Safety first'),
                'CCTV coverage, biometric entry, resident wardens and a medical room on campus ensure students are looked after round the clock.'),
            $contentId, false, 'published', -84, 2140],
        ['Student Clubs to Join in Your First Semester', 'campus-life', 'blog', 'students-lawn.jpg', 'clubs, student life, activities',
            'Coding, finance, photography, debating, community service — clubs are the fastest way to find your people on campus.',
            $p('Clubs at GIMT are run by students, mentored by faculty, and open to everyone. Recruitment for first-year students happens in the third week of the semester.',
                $ul(['<strong>Code Club</strong> — weekly contests, hackathon teams and open-source sprints', '<strong>Finance & Investment Club</strong> — mock portfolios, budget analysis and market talks', '<strong>Lens & Light</strong> — photography walks and the official fest coverage team', '<strong>Toastmasters-style Speakers\' Forum</strong> — public speaking and debates', '<strong>NSS unit</strong> — literacy drives, blood donation camps and village outreach']),
                'Joining two clubs — one related to your program and one completely different — is a great way to build both skills and friendships.'),
            $contentId, false, 'published', -4, 960],
        ["Placement Season $session Begins", 'placements', 'news', 'placement-student.jpg', 'placements, recruiters, ' . $session,
            "The placement season for the $session batch is underway with pre-placement talks, aptitude rounds and on-campus interviews.",
            $p("The training and placement cell has opened the placement season for the $session graduating batch. $recruiterLine, with roles in IT services, analytics, sales, banking, consulting and operations.",
                $h2('What students can expect'),
                $ul(['Pre-placement talks by recruiters explaining roles and compensation', 'Online aptitude and technical assessments', 'Group discussions and personal interviews on campus', 'Offer letters shared through the placement portal']),
                'Students must keep their profile and resume updated on the placement portal and maintain the attendance required to be eligible for drives.'),
            $placementId, true, 'published', -45, 4410],
        ['Five Interview Habits Our Placement Cell Swears By', 'placements', 'blog', 'students-laptop.jpg', 'interviews, career tips, placements',
            'Small habits make a big difference on interview day. Our placement officers share what consistently works for GIMT students.',
            $p('After hundreds of mock interviews, our placement team sees the same habits separating confident candidates from nervous ones.',
                '<ol><li><strong>Research the company for 30 minutes.</strong> Know its products, customers and recent news.</li><li><strong>Prepare three stories.</strong> A challenge you solved, a team you led and a mistake you learned from.</li><li><strong>Practise out loud.</strong> Mock interviews with friends or the placement cell beat silent reading.</li><li><strong>Ask one thoughtful question.</strong> It shows genuine interest in the role.</li><li><strong>Follow up.</strong> A short thank-you email leaves a professional impression.</li></ol>',
                'Book a mock interview slot with the placement cell any weekday between 2 pm and 5 pm.'),
            $placementId, false, 'published', -70, 2890],
        ['From Campus to Corporate: MBA Summer Internship Highlights', 'placements', 'news', 'program-mba.jpg', 'mba, internships, industry',
            'Our MBA students spent eight weeks with companies across banking, FMCG, IT and consulting. Here is what they worked on.',
            $p('The summer internship is a defining part of the MBA program. This year students interned in sales and marketing, financial analysis, HR operations, supply chain and business analytics.',
                'Projects included a customer-retention study for a regional bank, a distributor mapping exercise for an FMCG brand and an employee-engagement survey for an IT services company.',
                'Several students received pre-placement offers on the strength of their internship performance. The final presentations were evaluated by faculty and industry mentors last week.'),
            $placementId, false, 'published', -58, 1990],
        ['Why Data Analytics Skills Matter for Every Graduate', 'placements', 'blog', 'program-digital-marketing.jpg', 'data analytics, skills, careers',
            'Whether you study commerce, management or computing, the ability to work with data is now a core employability skill.',
            $p('Recruiters across sectors now expect graduates to be comfortable with spreadsheets, dashboards and basic statistics — not only those applying for analyst roles.',
                $ul(['Marketing teams track campaign performance daily', 'Finance teams build forecasts and variance reports', 'HR teams measure hiring funnels and attrition', 'Operations teams optimise inventory using demand data']),
                'Our Certificate in Data Analytics and the value-added courses in Excel, SQL and Power BI are open to students of every program.'),
            $academicsId, false, 'scheduled', 3, 0],
        ['B.Tech Students Build a Low-Cost Air Quality Monitor', 'research-innovation', 'achievement', 'program-btech.jpg', 'b.tech, iot, research, air quality',
            'A team of final-year B.Tech (CSE) students designed a solar-powered sensor network that reports PM2.5 levels across the campus.',
            $p('Air quality in the National Capital Region is a daily concern. A team of four final-year B.Tech (CSE) students turned it into their major project — a low-cost, solar-powered air quality monitor built with open-source hardware.',
                'The devices measure PM2.5, PM10, temperature and humidity and publish readings every five minutes to a dashboard that students and staff can check on their phones.',
                'The project was guided by the Department of Computer Science and has been shortlisted for the state innovation challenge. The team now plans to deploy units in nearby schools.'),
            $academicsId, true, 'published', -40, 3270],
        ['Faculty Paper on Financial Inclusion Accepted at National Conference', 'research-innovation', 'news', 'program-bcom.jpg', 'research, commerce, faculty',
            'A study by the Department of Commerce on digital payments among small retailers in Greater Noida will be presented at a national conference.',
            $p('Faculty members from the Department of Commerce surveyed 220 small retailers in Greater Noida to understand how digital payments have changed the way they manage credit and savings.',
                'The study finds that UPI adoption is now near universal, but access to formal credit still lags. The paper recommends financial-literacy workshops tailored to small shop owners.',
                'Two B.Com (Hons) students worked as research assistants on the project and are co-authors of the paper.'),
            $academicsId, false, 'published', -102, 870],
        ['GIMT Launches an Innovation & Incubation Cell for Student Start-ups', 'research-innovation', 'news', 'students-atrium.jpg', 'innovation, startups, incubation',
            'Mentoring, workspace, legal help and seed-funding guidance — the new cell supports students turning ideas into ventures.',
            $p('The Innovation & Incubation Cell gives student founders a dedicated workspace on campus, weekly mentoring from alumni entrepreneurs and guidance on company registration, IP and fundraising.',
                $ul(['Ideation bootcamp every semester', 'Pre-incubation for up to ten student teams per year', 'Demo day with angel investors and alumni', 'Access to government start-up schemes']),
                'Applications for the first cohort close at the end of this month. Teams with at least one current GIMT student are eligible.'),
            $contentId, true, 'published', -2, 1630],
        ['AI in the Classroom: How Our Faculty Use Generative AI Responsibly', 'research-innovation', 'blog', 'facility-classroom.jpg', 'ai, teaching, academics',
            'Generative AI is changing how students learn. Our academic council has published guidelines that encourage its thoughtful use.',
            $p('Rather than banning AI tools, GIMT\'s academic council has published guidelines that help faculty and students use them responsibly.',
                $ul(['AI may be used for brainstorming, explanations and practice questions', 'Assignments must declare where AI tools were used', 'Assessments emphasise viva, presentations and in-class problem solving', 'Faculty receive training on designing AI-aware assignments']),
                'The goal is simple: graduates who know how to use AI well — and who still think for themselves.'),
            $academicsId, false, 'draft', -3, 0],
        ['National Seminar on Sustainable Business Practices — Highlights', 'events', 'news', 'campus-academic-block.jpg', 'seminar, sustainability, management',
            'Academics and industry leaders discussed ESG reporting, circular supply chains and green finance at the two-day seminar.',
            $p('The Department of Management Studies hosted a two-day national seminar on sustainable business practices, with paper presentations from faculty and research scholars across the country.',
                'Panel discussions covered ESG reporting for small and medium enterprises, circular supply chains in manufacturing and the growth of green bonds in India.',
                'Selected papers will be published in the seminar proceedings with an ISBN.'),
            $contentId, false, 'published', -110, 740],
        ['Industry Expert Talk Series: Careers in Cloud Computing', 'events', 'news', 'facility-labs.jpg', 'expert talk, cloud, careers',
            'Cloud practitioners explained certification paths, real project work and the skills recruiters look for in freshers.',
            $p('The second session of our Industry Expert Talk Series focused on careers in cloud computing. Engineers working with large cloud platforms walked students through day-to-day work on migration and DevOps projects.',
                'Students learned how entry-level certifications, a GitHub portfolio and hands-on labs can make their profiles stand out.',
                'The next session in the series will cover careers in cybersecurity.'),
            $contentId, false, 'published', -26, 1210],
        ['Annual Sports Meet 2026: Results and Records', 'events', 'news', 'facility-sports.jpg', 'sports, athletics, events',
            'Two new campus records, a closely fought inter-department trophy and plenty of spirit — the highlights of this year\'s sports meet.',
            $p('The annual sports meet featured athletics, basketball, volleyball, kabaddi, badminton and chess, with more than 600 students participating.',
                'Two campus records were broken in the 400 m and long jump events. The Department of Computer Science won the overall championship trophy, narrowly ahead of Management Studies.',
                'Congratulations to all participants and thank you to the sports committee for a well-organised meet.'),
            $contentId, false, 'published', -52, 1480],
        ['Convocation 2026: Celebrating Our Graduating Class', 'events', 'news', 'graduation.jpg', 'convocation, graduation, alumni',
            'Graduates from every program received their degrees in the presence of families, faculty and distinguished guests.',
            $p('Convocation 2026 was a proud day for the GIMT family. Graduates from undergraduate, postgraduate and diploma programs received their degrees, and gold medals were awarded to the toppers of each program.',
                'In his address, the Chairman encouraged graduates to stay curious, stay ethical and stay connected with their alma mater.',
                'Photographs from the ceremony are available in the gallery. Graduates can collect their original degree certificates from the examination office.'),
            $adminId, true, 'published', -76, 5230],
        ['GIMT Coders Win First Prize at Inter-College Hackathon', 'achievements', 'achievement', 'students-laptop.jpg', 'hackathon, coding, achievement',
            'A team of BCA and B.Tech students built a campus-safety app in 24 hours and took home the first prize.',
            $p('A five-member team of BCA and B.Tech students won first prize at an inter-college hackathon held in Noida, competing against teams from across Delhi NCR.',
                'Their app lets students share live location with trusted contacts, raise an SOS alert to campus security and report unsafe spots anonymously.',
                'The team was mentored by faculty from the Department of Computer Applications and will present the app to the campus security committee.'),
            $contentId, false, 'published', -6, 2060],
        ['B.Com (Hons) Students Top the University Examinations', 'achievements', 'achievement', 'program-bcom.jpg', 'results, b.com, toppers',
            'Three GIMT students are among the top ten in the university\'s B.Com (Hons) results this year.',
            $p('We are proud to share that three students of B.Com (Hons) secured ranks in the top ten of the affiliating university\'s final-year results.',
                'The toppers credit regular tests, the department\'s doubt-clearing sessions and the peer study groups that run in the library every weekend.',
                'Congratulations to the students and the faculty of the Department of Commerce.'),
            $academicsId, false, 'published', -64, 1340],
        ['NSS Unit Recognised for Village Literacy Drive', 'achievements', 'achievement', 'students-atrium.jpg', 'nss, community, social impact',
            'The GIMT NSS unit received a district-level appreciation for its year-long adult literacy and digital awareness programme.',
            $p('Volunteers of the NSS unit visited two adopted villages every weekend for a year, running adult literacy classes and teaching basic smartphone and digital-payment skills.',
                'The district administration recognised the unit for its contribution at a ceremony last week. More than 150 adults completed the programme.'),
            $contentId, false, 'scheduled', 10, 0],
        ['Alumni Spotlight: From a BCA Classroom to Product Manager', 'achievements', 'blog', 'program-dca.jpg', 'alumni, careers, bca',
            'A BCA graduate shares how projects, internships and curiosity helped her move from software testing to product management.',
            $p('When she joined BCA, she had never written a line of code. Eight years later she leads a product team at a fintech company in Bengaluru.',
                '"The projects we built in our final year — and the feedback from faculty — taught me how real users think," she says. Her advice to current students: take on internships early, learn to write clearly and never stop asking why.',
                'Want to share your story? Alumni can write to the alumni relations office.'),
            $contentId, false, 'draft', -1, 0],
    ];

    $postRows = [];
    foreach ($posts as $k => [$title, $cat, $type, $image, $tags, $excerpt, $content, $author, $featured, $status, $offset, $views]) {
        $published = $status === 'draft' ? null : $at($offset, sprintf('%02d:%02d:00', 9 + $k % 9, ($k * 13) % 60));
        $created = $at(min($offset, 0) - 2, '09:05:00');
        $postRows[] = [
            'title' => $title, 'slug' => slugify($title), 'category_id' => $catId[$cat] ?? null, 'author_id' => $author, 'thumbnail' => $img($image),
            'excerpt' => $excerpt, 'content' => $content, 'tags' => $tags, 'is_featured' => $featured ? 1 : 0, 'type' => $type, 'status' => $status,
            'published_at' => $published, 'views' => $status === 'published' ? $views + mt_rand(0, 140) : 0, 'created_by' => $author, 'updated_by' => $author,
            'created_at' => $created, 'updated_at' => $status === 'draft' ? $at($offset, '17:40:00') : $published,
        ];
    }
    demo_bulk_insert('blog_posts', $postRows);

    // SEO for most published / scheduled posts (a few left without, so the "SEO missing" filter is meaningful)
    $site = rtrim((string) setting('site_url', ''), '/');
    $seoRows = [];
    foreach (db_all("SELECT id, title, slug, excerpt, thumbnail, tags, published_at, status FROM blog_posts WHERE status <> 'draft' ORDER BY id") as $n => $post) {
        if ($n % 6 === 5) {
            continue;
        }
        $metaTitle = mb_strlen($post['title']) <= 52 ? $post['title'] . ' | GIMT'
            : (mb_strlen($post['title']) <= 60 ? $post['title'] : preg_replace('/[\s:,\-–—]+\S*$/u', '', mb_substr($post['title'], 0, 60)));
        $desc = mb_strlen($post['excerpt']) <= 158 ? $post['excerpt'] : preg_replace('/\s+\S*$/u', '', mb_substr($post['excerpt'], 0, 157)) . '…';
        $seoRows[] = [
            'entity_type' => 'post', 'entity_id' => (int) $post['id'], 'route' => null, 'meta_title' => $metaTitle, 'meta_description' => $desc,
            'meta_keywords' => $post['tags'], 'canonical_url' => null, 'og_title' => $post['title'], 'og_description' => $desc, 'og_image' => $post['thumbnail'],
            'robots' => 'index,follow',
            'schema_json' => json_encode(['@context' => 'https://schema.org', '@type' => 'NewsArticle', 'headline' => mb_substr($post['title'], 0, 110),
                'datePublished' => date('c', strtotime($post['published_at'])), 'author' => ['@type' => 'Organization', 'name' => 'Global Institute of Management & Technology'],
                'image' => ($site ?: '') . '/' . $post['thumbnail']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'include_in_sitemap' => 1, 'sitemap_priority' => 0.6, 'sitemap_changefreq' => 'monthly',
        ];
    }
    demo_bulk_insert('seo_settings', $seoRows);

    // ---------------------------------------------------------------- galleries + items
    $albums = [
        ['Campus Tour', 'campus', 'A walk through the GIMT campus — academic blocks, lawns, library and hostels.', -180, 'published',
            ['campus-main.jpg', 'campus-building.jpg', 'campus-academic-block.jpg', 'facility-green-campus.jpg', 'students-atrium.jpg', 'students-lawn.jpg', 'campus-library.jpg', 'campus-hostel.jpg']],
        ['Labs & Learning Spaces', 'labs', 'Smart classrooms, computer labs, the library and student facilities.', -150, 'published',
            ['facility-labs.jpg', 'facility-classroom.jpg', 'facility-library.jpg', 'program-btech.jpg', 'program-dca.jpg', 'facility-hostel.jpg']],
        ['Convocation 2026', 'convocation', 'Graduates, families and faculty celebrate the class of 2026.', -76, 'published',
            ['graduation.jpg', 'hero-campus-students.jpg', 'students-lawn.jpg', 'leader-1.jpg', 'leader-2.jpg', 'leader-3.jpg']],
        ['Utkarsh 2026 — Annual Fest', 'cultural', 'Three days of performances, competitions and the student startup expo.', -34, 'published',
            ['news-campus.jpg', 'students-atrium.jpg', 'dashboard-banner.jpg', 'students-lawn.jpg', 'program-digital-marketing.jpg']],
        ['Annual Sports Meet 2026', 'sports', 'Athletics, team sports and the inter-department championship.', -52, 'published',
            ['facility-sports.jpg', 'facility-green-campus.jpg', 'students-lawn.jpg', 'news-campus.jpg']],
        ['Industry Visit — Noida Tech Park', 'placements', 'MBA and BCA students visit technology and operations centres in Noida.', -9, 'draft',
            ['placement-student.jpg', 'students-laptop.jpg', 'program-mba.jpg']],
    ];
    $captions = [];
    foreach ($photos as $file => $info) {
        $captions[$file] = $info[3] ?: $info[0];
    }
    foreach ($albums as $k => [$title, $category, $desc, $offset, $status, $files]) {
        $gid = db_insert('galleries', [
            'title' => $title, 'slug' => slugify($title), 'category' => $category, 'description' => $desc,
            'cover_image' => $k === 2 ? $img('graduation.jpg') : null, 'event_date' => date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days', $now)),
            'sort_order' => ($k + 1) * 10, 'status' => $status, 'created_at' => $at($offset + 1, '16:00:00'),
        ]);
        $items = [];
        foreach ($files as $n => $file) {
            if (!isset($mediaByFile[$file])) {
                continue;
            }
            $items[] = ['gallery_id' => $gid, 'media_id' => (int) $mediaByFile[$file], 'image' => null, 'video_url' => null, 'caption' => $captions[$file] ?? null, 'sort_order' => ($n + 1) * 10];
        }
        demo_bulk_insert('gallery_items', $items);
    }

    // ---------------------------------------------------------------- FAQs
    // [category, question, answer, home, contact]
    $faqs = [
        ['admissions', 'When do admissions open for the 2026-27 session?', "Applications for most programs open in February and continue until seats are filled. Postgraduate programs follow the entrance and counselling schedule of the university.\n\nWe recommend applying early, as hostel rooms and scholarship funds are allotted on a first-come basis.", 1, 1],
        ['admissions', 'How do I apply to GIMT?', "Apply online from the Admissions page: fill in the form, upload your documents and pay the registration fee. You will receive an application number by email and SMS to track your status.", 1, 1],
        ['admissions', 'What is the eligibility for undergraduate programs?', "Class 12 (or equivalent) from a recognised board. Some programs have subject requirements — for example, Mathematics for B.Tech, BCA and B.Sc (Data Science). Check the program page for exact criteria.", 1, 0],
        ['admissions', 'Is there an entrance test or interview?', "Undergraduate admissions are based on Class 12 marks followed by a counselling session. MBA and MCA admissions consider national or state entrance scores along with a personal interview.", 0, 0],
        ['admissions', 'Can I apply before my Class 12 results are declared?', "Yes. You can apply with your Class 10 and Class 11 marks and upload the Class 12 marksheet when results are declared. Admission is confirmed after verification.", 0, 0],
        ['admissions', 'Which documents are required at the time of admission?', "Class 10 and 12 marksheets, transfer and migration certificates, a government photo ID, passport-size photographs and category certificates if applicable. Postgraduate applicants also need graduation marksheets.", 0, 0],
        ['admissions', 'Do you offer lateral entry into B.Tech?', "Yes, diploma holders in engineering can join the second year of B.Tech through lateral entry, subject to university rules and seat availability.", 0, 0],
        ['fees', 'How can I pay the fees?', "Fees can be paid online by UPI, net banking, debit or credit card, or at the accounts office by demand draft. Every payment generates a receipt that you can download from the student portal.", 1, 1],
        ['fees', 'Can fees be paid in instalments?', "Tuition fees are payable semester-wise. In genuine cases, students can request an instalment plan from the accounts office before the due date.", 0, 1],
        ['fees', 'What scholarships are available?', "Merit scholarships, need-based support, sports and cultural excellence scholarships, and concessions for single girl child and wards of defence personnel. The scholarship is adjusted against the tuition fee.", 1, 0],
        ['fees', 'Is education loan assistance available?', "Yes. Our accounts office provides the documents banks require and has tie-ups with leading banks for education loans.", 0, 0],
        ['fees', 'What is the refund policy if I withdraw?', "Refunds follow the university and regulatory guidelines and depend on the date of withdrawal. Submit a written request to the admissions office to start the process.", 0, 0],
        ['academics', 'Which university is GIMT affiliated with?', "GIMT programs are affiliated to the state university and approved by the relevant regulatory bodies. Degrees are awarded by the affiliating university.", 0, 0],
        ['academics', 'What is the minimum attendance requirement?', "Students must maintain at least 75% attendance in each subject to be eligible for end-semester examinations. Attendance is updated daily on the student portal.", 0, 0],
        ['academics', 'How are students evaluated?', "Evaluation combines internal assessment (assignments, quizzes, mid-term tests, presentations) with end-semester examinations conducted by the university.", 0, 0],
        ['academics', 'Are there value-added courses alongside the degree?', "Yes. Students can take certifications in data analytics, digital marketing, cloud computing, communication skills and more — many are free for enrolled students.", 1, 0],
        ['academics', 'Is there a mentor or counsellor for every student?', "Every class has a faculty mentor who tracks attendance, academic progress and well-being. A professional counsellor is also available on campus.", 0, 0],
        ['hostel', 'Is hostel accommodation available?', "Yes, separate hostels for boys and girls are available inside the campus with twin and triple-sharing rooms, mess, Wi-Fi, laundry and 24x7 security.", 1, 1],
        ['hostel', 'How are hostel rooms allotted?', "Rooms are allotted on a first-come, first-served basis after the hostel fee is paid. First-year students are usually allotted rooms in the same block.", 0, 0],
        ['hostel', 'Does the college provide transport?', "Yes, college buses cover major routes in Greater Noida, Noida, Ghaziabad and Delhi. Transport fees depend on the route and pickup point.", 0, 1],
        ['hostel', 'What are the hostel in-time rules?', "The hostel in-time is 9:30 pm. Late entry or night-out requires prior permission from the warden and parents.", 0, 0],
        ['placement', 'Does GIMT provide placement support?', "Yes. The training and placement cell runs aptitude training, soft-skills workshops, mock interviews and on-campus recruitment drives for every program.", 1, 1],
        ['placement', 'Which companies visit the campus?', "Recruiters from IT services, consulting, banking and financial services, e-commerce and manufacturing visit the campus. The placement page lists recent recruiters.", 1, 0],
        ['placement', 'Are internships compulsory?', "Internships are part of the curriculum for BBA, MBA, B.Tech and BCA. The placement cell helps students find internships through its industry network.", 0, 0],
        ['placement', 'Who is eligible to sit for placements?', "Final-year students with the required attendance and no active backlog are eligible. Some companies also set minimum academic criteria.", 0, 0],
        ['campus', 'What facilities are available on campus?', "Smart classrooms, computer labs, a central library with e-resources, sports grounds, a gym, cafeteria, medical room, hostels and Wi-Fi across the campus.", 0, 1],
        ['campus', 'How can I join student clubs and events?', "Club recruitment happens in the first month of each semester. Follow the student council notices or visit the student activity centre to sign up.", 0, 0],
        ['general', 'Where is the GIMT campus located?', "The campus is in Knowledge Park, Greater Noida, Uttar Pradesh, well connected by road and the Aqua Line metro.", 0, 1],
        ['general', 'Can I visit the campus before applying?', "Of course. Campus visits are welcome Monday to Saturday, 9 am to 5 pm. Book a visit from the Contact page and a counsellor will show you around.", 1, 1],
        ['general', 'How do I contact the admissions office?', "Call +91 9955446477, email admissions@gimt.ac.in or use the enquiry form on the website. We usually respond within one working day.", 0, 1],
        ['general', 'Is ragging allowed on campus?', "No. GIMT follows a zero-tolerance anti-ragging policy with an anti-ragging committee, squad and helpline. Strict action is taken on any complaint.", 0, 0],
    ];
    $faqRows = [];
    $order = [];
    foreach ($faqs as $k => [$cat, $q, $a, $home, $contact]) {
        $order[$cat] = ($order[$cat] ?? 0) + 10;
        $faqRows[] = ['category' => $cat, 'question' => $q, 'answer' => $a, 'sort_order' => $order[$cat], 'show_on_home' => $home, 'show_on_contact' => $contact,
            'status' => $k === 6 ? 'inactive' : 'active', 'created_at' => $at(-160 + $k, '12:00:00'), 'updated_at' => $at(-60 + $k, '12:00:00')];
    }
    demo_bulk_insert('faqs', $faqRows);

    // ---------------------------------------------------------------- testimonials
    $prog = db_pairs('SELECT code, id FROM programs');
    $pid = fn (string $code) => isset($prog[$code]) ? (int) $prog[$code] : null;
    // [name, type, program code, batch, designation, company, photo, quote, rating, featured, status]
    $testimonials = [
        ['Ananya Gupta', 'alumni', 'MBA', '2022-24', 'Business Analyst', 'Deloitte', 'placement-student.jpg',
            'The case-based teaching and the live projects in my MBA prepared me for consulting far better than I expected. The placement cell ran mock interviews until I was confident — I received my offer in the first week of the season.', 5, 1, 'active'],
        ['Rohit Verma', 'alumni', 'BTCSE', '2020-24', 'Software Engineer', 'Infosys', null,
            'Our faculty pushed us to build real projects and take part in hackathons. That portfolio is what interviewers asked about. GIMT gave me both the skills and the confidence.', 5, 1, 'active'],
        ['Sneha Rathore', 'student', 'BBA', '2024-27', 'President, Entrepreneurship Club', null, null,
            'From organising Utkarsh to pitching at the startup expo, BBA at GIMT is about doing, not just studying. The mentors are always available when you need advice.', 5, 1, 'active'],
        ['Karan Malhotra', 'alumni', 'BCOMH', '2019-22', 'Audit Associate', 'KPMG', null,
            'The B.Com (Hons) curriculum and the regular tests kept me disciplined. The faculty helped me balance CA preparation with college, and the accounts lab made concepts practical.', 4, 0, 'active'],
        ['Priya Nair', 'student', 'BSCDS', '2025-28', 'Second-year student', null, null,
            'I love that we work with real datasets from the first semester. The labs are open late and seniors run study circles before every exam.', 5, 0, 'active'],
        ['Mr. Suresh Chauhan', 'parent', 'BTAIDS', '2025-29', 'Parent of a B.Tech (AI & DS) student', null, null,
            'As a parent I appreciate the regular attendance and result updates on the portal. The hostel is safe and the wardens respond quickly whenever we call.', 5, 0, 'active'],
        ['Vikram Singh', 'alumni', 'MCA', '2021-23', 'Cloud Engineer', 'HCLTech', null,
            'The cloud and DevOps electives in MCA, along with certification support, made the transition to my first job smooth. I still reach out to my professors for guidance.', 4, 0, 'active'],
        ['Meera Joshi', 'student', 'MBA', '2025-27', 'MBA first year', null, 'students-lawn.jpg',
            'The diversity of my batch is amazing — engineers, commerce graduates and working professionals. Group projects here feel like a real workplace.', 5, 0, 'active'],
        ['Ms. Kavita Bansal', 'recruiter', null, null, 'Talent Acquisition Lead', 'HDFC Bank', null,
            'GIMT students come to interviews well prepared and with strong communication skills. The placement team coordinates drives professionally, which makes campus hiring easy for us.', 5, 1, 'active'],
        ['Aditya Mishra', 'student', 'BCA', '2024-27', 'Code Club lead', null, 'students-laptop.jpg',
            'Weekly coding contests and the hackathon win this year made BCA unforgettable. Faculty treat our ideas seriously and help us improve them.', 5, 0, 'active'],
        ['Mrs. Rekha Agarwal', 'parent', 'BCOMH', '2024-27', 'Parent of a B.Com (Hons) student', null, null,
            'The mentor system gives us confidence. We meet our daughter\'s mentor every semester and always get honest feedback on her progress.', 4, 0, 'active'],
        ['Nikhil Pandey', 'alumni', 'DCAP', '2023-24', 'Junior Web Developer', 'Freelance', null,
            'The DCA program was short but very practical. I built my first websites in the lab and now work with small businesses on their online presence.', 4, 0, 'inactive'],
    ];
    $tRows = [];
    foreach ($testimonials as $k => [$name, $type, $code, $batch, $designation, $company, $photo, $quote, $rating, $featured, $status]) {
        $tRows[] = ['name' => $name, 'designation' => $designation, 'company' => $company, 'photo' => $photo ? $img($photo) : null, 'content' => $quote, 'video_url' => null,
            'rating' => $rating, 'is_featured' => $featured, 'type' => $type, 'program_id' => $code ? $pid($code) : null, 'batch' => $batch,
            'sort_order' => ($k + 1) * 10, 'status' => $status, 'created_at' => $at(-140 + $k * 5, '15:00:00')];
    }
    demo_bulk_insert('testimonials', $tRows);
};
