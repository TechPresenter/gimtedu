<?php
/**
 * Demo website CMS data (unit cms_site): home hero slides and promotional banners using the bundled campus photography,
 * announcements for every type (top bar, ticker, popup, alert banner) with live / scheduled / expired / inactive
 * schedules anchored on today's date.
 * Re-runnable: clears banners and announcements first.
 */
return function (array $opts): void {
    require_once __DIR__ . '/lib/demo_data.php';
    demo_seed(6060);

    db_exec('DELETE FROM banners');
    db_exec('DELETE FROM announcements');
    db_exec('ALTER TABLE banners AUTO_INCREMENT = 1');
    db_exec('ALTER TABLE announcements AUTO_INCREMENT = 1');

    $today = date('Y-m-d');
    $at = fn (int $days, string $time = '09:00:00') => date('Y-m-d', strtotime("$today $days days")) . ' ' . $time;
    $nice = fn (int $days) => date('j F', strtotime("$today $days days"));
    $img = fn (string $f) => 'assets/images/site/' . $f;
    $session = (string) (db_value('SELECT name FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1') ?: '2026-27');
    [$y1] = explode('-', $session);
    $nextSession = ((int) $y1 + 1) . '-' . substr((string) ((int) $y1 + 2), 2);
    $mba = db_value("SELECT slug FROM programs WHERE short_name = 'MBA' LIMIT 1") ?: 'master-of-business-administration';
    $aids = db_value("SELECT slug FROM programs WHERE short_name LIKE 'B.Tech (AI%' LIMIT 1") ?: 'b-tech-in-artificial-intelligence-data-science';

    /* ------------------------------------------------------------------ banners */
    $banners = [
        // placement, eyebrow, title, highlight, subheading, desktop, mobile, alt, cta, cta url, cta2, cta2 url, starts, ends, status, created days ago
        // (a slide repeating the home hero headline would be skipped by the website, so every slide has its own message)
        ['home_hero', "Admissions Open $session", 'Applications Are Open', 'for every program.', 'Apply online in about ten minutes for undergraduate, postgraduate, diploma and certificate programs — our counsellors help at every step.',
            'students-lawn.jpg', 'students-lawn.jpg', 'GIMT students relaxing on the campus lawn', 'Apply for Admission', '/apply', 'Explore Programs', '/programs', null, null, 'active', 60],
        ['home_hero', 'Master of Business Administration', 'An MBA Built With Industry', 'Learn from leaders who hire.', 'Specialise in marketing, finance, HR or business analytics with live projects, a summer internship and mentoring from industry leaders.',
            'program-mba.jpg', 'program-mba.jpg', 'MBA students discussing a case study', 'Explore the MBA', '/programs/' . $mba, 'Download Brochure', '/programs/' . $mba . '#brochure', null, null, 'active', 45],
        ['home_hero', 'B.Tech · Artificial Intelligence & Data Science', 'Build the Intelligence', 'of tomorrow.', 'Hands-on machine learning, cloud and data engineering labs with industry certifications built into the curriculum.',
            'facility-labs.jpg', 'facility-labs.jpg', 'Students working in the AI and data science laboratory', 'View B.Tech AI & DS', '/programs/' . $aids, 'Talk to a Counsellor', '/contact?type=program#message', null, null, 'active', 30],
        ['home_hero', 'Training & Placements', 'Placements That Speak', 'Top recruiters. Real careers.', 'Aptitude training, mock interviews and a dedicated placement cell that connects every final-year student with leading recruiters.',
            'placement-student.jpg', 'placement-student.jpg', 'A GIMT graduate smiling after a successful interview', 'View Placements', '/placement', 'Meet Our Alumni', '/alumni', null, null, 'active', 20],
        ['home_hero', 'Convocation ' . date('Y'), 'Celebrating Our Graduates', 'The class of ' . date('Y') . '.', 'Join us as we celebrate the achievements of our graduating students with their families and faculty.',
            'graduation.jpg', 'graduation.jpg', 'Graduates throwing their caps in the air', 'Event Details', '/events', null, null, $at(18), $at(32, '23:59:00'), 'active', 4],
        ['home_hero', 'Festival Greetings', 'Happy Diwali from GIMT', 'May this festival light up your path.', 'Wishing our students, parents, alumni and partners a joyful and safe Diwali.',
            'news-campus.jpg', null, 'The GIMT campus decorated with lights', null, null, null, null, $at(24), $at(30, '23:59:00'), 'inactive', 2],
        ['home_hero', "Early Applications $nextSession", 'Admissions Open', "for $nextSession.", 'Apply early to secure your seat and become eligible for the early-bird scholarship.',
            'students-atrium.jpg', null, 'Students in the GIMT atrium', 'Register Interest', '/admissions#enquiry', null, null, null, null, 'inactive', 1],
        ['home_hero', 'Independence Day', 'Freedom to Dream', 'Freedom to Achieve.', 'GIMT celebrated Independence Day with a flag hoisting ceremony, cultural programme and a campus cleanliness drive.',
            'facility-green-campus.jpg', null, 'Tree-lined green campus', 'See the Gallery', '/gallery', null, null, $at(-70), $at(-55, '23:59:00'), 'active', 75],
        ['page_header', 'Scholarships', 'Merit & Need-based Scholarships', null, 'Scholarships for meritorious students, single girl children, defence wards and sports achievers — apply along with your admission form.',
            'campus-library.jpg', null, 'Students in the GIMT library', 'View Scholarships', '/admissions#scholarships', 'Check Eligibility', '/contact?type=scholarship#message', null, $at(80, '23:59:00'), 'active', 40],
        ['popup', "Admissions $session", 'Talk to an Admission Counsellor', null, 'Get free one-to-one career counselling and help with choosing a program and completing your application.',
            'students-laptop.jpg', null, 'A student applying online on a laptop', 'Apply Now', '/apply', 'Request a Call Back', '/admissions#enquiry', $at(-3), $at(11, '23:59:00'), 'active', 3],
        ['strip', 'Every Saturday', 'Free Career Counselling at Campus', null, 'Meet our counsellors between 10 AM and 2 PM — no appointment needed.',
            'campus-academic-block.jpg', null, 'The GIMT academic block', 'Plan a Visit', '/contact?type=campus_visit#message', null, null, null, null, 'active', 15],
    ];
    $order = [];
    $rows = [];
    foreach ($banners as [$placement, $eyebrow, $title, $highlight, $sub, $desk, $mob, $alt, $cta, $ctaUrl, $cta2, $cta2Url, $starts, $ends, $status, $ago]) {
        $order[$placement] = ($order[$placement] ?? 0) + 1;
        $created = date('Y-m-d H:i:s', strtotime("$today -$ago days 10:30:00"));
        $rows[] = ['title' => $title, 'eyebrow' => $eyebrow, 'highlight' => $highlight, 'subheading' => $sub, 'desktop_image' => $img($desk), 'mobile_image' => $mob ? $img($mob) : null,
            'image_alt' => $alt, 'cta_text' => $cta, 'cta_url' => $ctaUrl, 'cta2_text' => $cta2, 'cta2_url' => $cta2Url, 'placement' => $placement, 'sort_order' => $order[$placement],
            'starts_at' => $starts, 'ends_at' => $ends, 'status' => $status, 'created_at' => $created, 'updated_at' => date('Y-m-d H:i:s', strtotime($created . ' +' . mt_rand(0, max(0, $ago - 1)) . ' days'))];
    }
    demo_bulk_insert('banners', $rows);

    /* ------------------------------------------------------------------ announcements */
    $ann = [
        // type, title, content, link, link text, starts, ends, status, sort, created days ago
        ['topbar', "Admissions Open for $session — Apply Online", null, '/apply', 'Apply now', null, null, 'active', 1, 50],
        ['topbar', 'Scholarship test on ' . $nice(19) . ' — register by ' . $nice(14), null, '/admissions#scholarships', 'Register', $at(-6), $at(19, '18:00:00'), 'active', 2, 6],
        ['topbar', 'Helpline: +91 99554 46477 (Mon–Sat, 9 AM – 6 PM)', null, 'tel:+919955446477', 'Call us', null, null, 'active', 3, 50],
        ['topbar', 'Summer internship results announced', null, '/notices', 'View', $at(-60), $at(-30, '23:59:00'), 'active', 4, 60],
        ['ticker', 'Mid-semester examinations begin on ' . $nice(9) . ' — check the date sheet on the notice board', null, '/notices?category=examination', 'Date sheet', $at(-2), $at(12, '23:59:00'), 'active', 1, 2],
        ['ticker', 'Registrations open for the annual tech fest "Innovate ' . date('Y') . '" — hackathon, robotics and coding contests', null, '/events', 'Register', $at(-5), $at(25, '23:59:00'), 'active', 2, 5],
        ['ticker', 'Campus placement drive for final-year B.Tech, BCA and MCA students on ' . $nice(6), null, '/placement', 'Details', $at(-4), $at(6, '18:00:00'), 'active', 3, 4],
        ['ticker', 'Library hours extended to 10 PM during the examination period', null, null, null, $at(-1), $at(20, '23:59:00'), 'active', 4, 1],
        ['ticker', 'Guest lecture: "Careers in Business Analytics" by industry experts on ' . $nice(4), null, '/events', 'Know more', $at(-3), $at(4, '18:00:00'), 'active', 5, 3],
        ['ticker', 'Orientation programme for the new batch begins on ' . $nice(-40), null, '/notices', 'Schedule', $at(-55), $at(-38, '23:59:00'), 'active', 6, 56],
        ['ticker', 'Results of the odd-semester supplementary examinations will be declared soon', null, '/notices', null, $at(15), $at(30, '23:59:00'), 'active', 7, 1],
        ['popup', 'Campus Visit Day — ' . $nice(13), 'Tour our classrooms, labs, library and hostels, meet faculty and current students, and get on-the-spot counselling and application support. Parents are welcome.', '/contact?type=campus_visit#message', 'Book your visit', $at(-2), $at(13, '14:00:00'), 'active', 1, 2],
        ['popup', 'Early-bird application fee waiver', 'Apply before the end of this month and the ₹1,000 application fee is waived for all undergraduate programs.', '/apply', 'Apply now', null, null, 'inactive', 2, 9],
        ['banner', 'The campus will remain closed on ' . $nice(27) . ' for Diwali. Online services remain available.', null, '/notices', 'Holiday notice', $at(20), $at(28, '09:00:00'), 'active', 1, 1],
        ['banner', 'Website maintenance on Sunday, 2 AM – 4 AM. The online application may be briefly unavailable.', null, null, null, $at(-20), $at(-18, '23:59:00'), 'active', 2, 21],
    ];
    $rows = [];
    foreach ($ann as [$type, $title, $content, $link, $linkText, $starts, $ends, $status, $sort, $ago]) {
        $rows[] = ['title' => $title, 'content' => $content, 'link' => $link, 'link_text' => $linkText, 'type' => $type, 'starts_at' => $starts, 'ends_at' => $ends,
            'sort_order' => $sort, 'status' => $status, 'created_at' => date('Y-m-d H:i:s', strtotime("$today -$ago days 11:00:00"))];
    }
    demo_bulk_insert('announcements', $rows);

};
