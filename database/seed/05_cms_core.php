<?php
/**
 * Core website CMS content (always runs, also without demo data) — unit cms_site, docs/CMS.md.
 *   - pages: system content pages (about, vision-mission, leadership, research, campus-life, legal pages) with real copy
 *     and page-builder blocks, plus route pages (programs, admissions, academics, campus, placement, alumni, faculty,
 *     contact) holding the hero copy their templates read
 *   - menus: every location, identical to the fallback links of includes/navbar.php and includes/footer.php
 *     (cms_menu_fallback()); the Programs mega menu is auto-filled from the programs table by the website
 *   - homepage_sections: all 14 sections with the home page mockup content (cms_default_sections())
 *   - seo_settings: the main public routes and the content pages
 * Re-runnable: clears the tables it owns completely (menu_items, menus, homepage_sections) and resets only ITS OWN pages
 * (matched by slug, updated in place so their ids — and links such as /admin/cms/pages/1/builder — stay stable) plus the
 * seo_settings rows of the routes and pages it seeds. Pages created by other seeders (08_policies) or by editors are
 * never touched.
 */
return function (array $opts): void {
    require_once APP_ROOT . '/app/services/cms.php';
    mt_srand(505);

    db_exec('DELETE FROM menu_items');
    db_exec('DELETE FROM menus');
    db_exec('DELETE FROM homepage_sections');
    db_exec('ALTER TABLE menus AUTO_INCREMENT = 1');
    db_exec('ALTER TABLE menu_items AUTO_INCREMENT = 1');

    $adminId = db_value("SELECT id FROM users WHERE username = 'admin' LIMIT 1") ?: null;
    $now = date('Y-m-d H:i:s');
    $published = date('Y-m-d H:i:s', strtotime('-120 days'));
    $img = fn (string $f) => 'assets/images/site/' . $f;
    $b = function (string $type, array $data, array $settings = []) {
        static $n = 0;
        $n++;
        return ['id' => 'seed' . $n, 'type' => $type, 'hidden' => false, 'settings' => $settings + ['anchor' => '', 'background' => 'white', 'padding' => 'normal'], 'data' => $data];
    };
    $stat = fn (string $value, string $label, string $icon, string $source = '', string $suffix = '+', string $prefix = '') => compact('value', 'prefix', 'suffix', 'label', 'icon', 'source');
    $phone = setting('phone', '+91 9955446477');
    $email = setting('email', 'info@gimt.ac.in');
    $address = setting('address', 'Plot No. 123, Knowledge Park, Greater Noida, Uttar Pradesh 201310, India');
    $updated = date('d F Y', strtotime('2026-07-01'));

    /* ------------------------------------------------------------------ pages */
    $pages = [];
    // [slug, title, subtitle, excerpt, banner, content html, blocks, parent slug, sort]
    $pages[] = ['about', 'About GIMT', 'Empowering Minds. Shaping Futures.',
        'Global Institute of Management & Technology (GIMT) is committed to providing high-quality, industry-oriented education with a focus on innovation, research and holistic development.',
        $img('campus-building.jpg'),
        '<p>Founded in 2008 in Knowledge Park, Greater Noida, the Global Institute of Management &amp; Technology began with a single management program and a simple belief: that education should prepare students for the world as it is, and the world they will build. Today GIMT offers undergraduate, postgraduate, diploma and certificate programs across management, technology, computer applications, commerce and data science.</p>'
        . '<p>Our classrooms are deliberately small and our faculty deliberately accessible. Every student is assigned a faculty mentor in the first semester, works on live projects with industry partners and completes at least one internship before graduating. The result is a campus where theory meets practice every single day.</p>'
        . '<p>We measure our success by the success of our graduates — the managers, engineers, analysts and entrepreneurs who carry the GIMT name into leading organisations across India and abroad.</p>',
        [
            $b('statistics', ['eyebrow' => 'GIMT at a glance', 'heading' => 'A growing community of learners', 'subheading' => 'Numbers update automatically from our records.', 'variant' => 'band',
                'items' => [$stat('12', 'Programs', 'graduation-cap', 'programs'), $stat('1000', 'Students', 'users', 'students'), $stat('50', 'Faculty members', 'user-check', 'faculty'), $stat('18', 'Years of excellence', 'award', 'years')]],
                ['background' => 'dark']),
            $b('cards', ['eyebrow' => 'What we stand for', 'heading' => 'Vision, Mission & Values', 'subheading' => '', 'style' => 'pastel', 'columns' => '3', 'items' => [
                ['icon' => 'eye', 'title' => 'Our Vision', 'text' => 'To be a globally recognised institution for excellence in education and innovation, creating leaders who make a positive impact on society.', 'tint' => 'lavender', 'url' => '/vision-mission', 'link_label' => 'Read more'],
                ['icon' => 'target', 'title' => 'Our Mission', 'text' => 'To empower students with knowledge, skills and values through industry-relevant education, research and holistic development.', 'tint' => 'sky', 'url' => '/vision-mission', 'link_label' => 'Read more'],
                ['icon' => 'heart', 'title' => 'Our Values', 'text' => 'Integrity, innovation, excellence, inclusivity and social responsibility are at the core of everything we do.', 'tint' => 'aqua', 'url' => '/vision-mission#values', 'link_label' => 'Read more']]],
                ['anchor' => 'vision', 'background' => 'light']),
            $b('image', ['image' => $img('campus-main.jpg'), 'alt' => 'Aerial view of the GIMT main campus in Knowledge Park, Greater Noida', 'caption' => 'The GIMT main campus, Knowledge Park, Greater Noida', 'width' => 'wide', 'link' => '']),
            $b('cta', ['eyebrow' => 'Admissions 2026-27', 'title' => 'Join GIMT and Be Part of a Global Community', 'text' => 'Applications are open for undergraduate, postgraduate, diploma and certificate programs.', 'primary' => ['label' => 'Apply for Admission', 'url' => '/apply'],
                'secondary' => ['label' => 'Explore Programs', 'url' => '/programs'], 'image' => $img('graduation.jpg'), 'theme' => 'dark']),
        ], null, 1];
    $pages[] = ['vision-mission', 'Vision & Mission', 'What drives us every day', 'The vision, mission and core values that guide teaching, research and student life at GIMT.', $img('students-atrium.jpg'),
        '',
        [
            $b('hero', ['eyebrow' => 'Who we are', 'title' => 'Vision & Mission', 'highlight' => 'Purpose that shapes every classroom.', 'text' => 'Our vision tells us where we are going; our mission tells us how we will get there; our values tell us how we behave along the way.',
                'image' => $img('students-atrium.jpg'), 'image_alt' => 'Students in the GIMT atrium', 'align' => 'left', 'size' => 'md', 'primary_cta' => ['label' => 'Explore Programs', 'url' => '/programs'], 'secondary_cta' => ['label' => '', 'url' => ''], 'chips' => []]),
            $b('cards', ['eyebrow' => '', 'heading' => 'Our purpose', 'subheading' => '', 'style' => 'icon', 'columns' => '2', 'items' => [
                ['icon' => 'eye', 'title' => 'Vision', 'text' => 'To be a globally recognised institution for excellence in education and innovation, creating leaders who make a positive impact on society.'],
                ['icon' => 'target', 'title' => 'Mission', 'text' => 'To empower students with knowledge, skills and values through industry-relevant education, research and holistic development — and to keep our programs, pedagogy and partnerships aligned with the needs of a changing economy.']]],
                ['anchor' => 'vision']),
            $b('text', ['heading' => 'Our core values', 'html' => '<ul><li><strong>Integrity</strong> — we are honest in our teaching, assessment and conduct, and we expect the same from our students.</li>'
                . '<li><strong>Innovation</strong> — we encourage curiosity, experimentation and new ideas in and beyond the classroom.</li>'
                . '<li><strong>Excellence</strong> — we set high standards for academics, research and service, and we support every learner to reach them.</li>'
                . '<li><strong>Inclusivity</strong> — we welcome students of every background and make our campus accessible and respectful for all.</li>'
                . '<li><strong>Social responsibility</strong> — we use what we learn to serve our communities and the environment.</li></ul>', 'align' => 'left', 'width' => 'narrow'],
                ['anchor' => 'values', 'background' => 'light']),
            $b('text', ['heading' => 'Our quality policy', 'html' => '<p>GIMT is committed to delivering outcome-based education that meets the expectations of students, parents, employers and regulators. We review every program each year with industry experts and alumni, track learning outcomes and placement results, and act on student feedback collected every semester.</p>', 'align' => 'left', 'width' => 'narrow']),
        ], 'about', 2];
    $pages[] = ['leadership', 'Leadership', 'Guiding Towards a Brighter Future', 'Meet the leadership team guiding GIMT\'s academic vision, governance and student development.', $img('students-lawn.jpg'),
        '',
        [
            $b('text', ['heading' => 'Leadership that listens', 'html' => '<p>GIMT is guided by a leadership team that combines decades of academic, research and industry experience. Our leaders meet students every semester through open forums and remain closely involved with curriculum design, faculty development and placements.</p>', 'align' => 'center', 'width' => 'narrow']),
            $b('cards', ['eyebrow' => 'Our leadership', 'heading' => 'Meet the team', 'subheading' => '', 'style' => 'person', 'columns' => '3', 'items' => [
                ['title' => 'Dr. R.K. Sharma', 'subtitle' => 'Chairman', 'text' => 'Visionary leader with 25+ years in education and research, who founded GIMT in 2008 to bring industry-focused higher education to Greater Noida.', 'image' => $img('leader-1.jpg')],
                ['title' => 'Prof. Neha Verma', 'subtitle' => 'Director', 'text' => 'Focused on academic excellence and student development; leads curriculum reform, faculty development and international collaborations.', 'image' => $img('leader-2.jpg')],
                ['title' => 'Dr. Amit Kumar', 'subtitle' => 'Academic Dean', 'text' => 'Expert in curriculum design and industry collaboration; oversees examinations, research and outcome-based education across departments.', 'image' => $img('leader-3.jpg')]]],
                ['anchor' => 'team', 'background' => 'light']),
            $b('text', ['heading' => 'Governance', 'html' => '<p>The Governing Body sets the strategic direction of the institute and approves its annual plan and budget. The Academic Council approves programs, curricula and examination regulations, while the Board of Studies of each department reviews syllabi every year with external experts and alumni.</p>'
                . '<p>Statutory committees — the Internal Complaints Committee, Anti-Ragging Committee, Grievance Redressal Committee and the Equal Opportunity Cell — ensure that every student and employee has a safe, fair and responsive campus.</p>', 'align' => 'left', 'width' => 'normal']),
            $b('cta', ['eyebrow' => '', 'title' => 'Have a question for the administration?', 'text' => 'Write to us and the right office will respond within two working days.', 'primary' => ['label' => 'Contact Us', 'url' => '/contact'], 'secondary' => ['label' => '', 'url' => ''], 'image' => '', 'theme' => 'light']),
        ], 'about', 3];
    $pages[] = ['research', 'Research & Innovation', 'Ideas that solve real problems', 'Faculty and student research, innovation projects, consultancy and the GIMT incubation programme.', $img('facility-labs.jpg'),
        '',
        [
            $b('hero', ['eyebrow' => 'Research at GIMT', 'title' => 'Research & Innovation', 'highlight' => 'Ideas that solve real problems.', 'text' => 'From analytics for small businesses to AI for agriculture, our faculty and students work on research that matters to industry and society.',
                'image' => $img('facility-labs.jpg'), 'image_alt' => 'Students working in the GIMT computer laboratory', 'align' => 'left', 'size' => 'md', 'primary_cta' => ['label' => 'Meet Our Faculty', 'url' => '/faculty'], 'secondary_cta' => ['label' => 'Contact the Research Cell', 'url' => '/contact'], 'chips' => []]),
            $b('cards', ['eyebrow' => 'Focus areas', 'heading' => 'Where we do our best work', 'subheading' => 'Interdisciplinary research groups bring together faculty from management, technology and commerce.', 'style' => 'icon', 'columns' => '3', 'items' => [
                ['icon' => 'cpu', 'title' => 'Artificial Intelligence & Data Science', 'text' => 'Machine learning for healthcare, agriculture and education; responsible AI and explainability.'],
                ['icon' => 'chart-column', 'title' => 'Business Analytics', 'text' => 'Consumer behaviour, supply chains and financial analytics for Indian MSMEs.'],
                ['icon' => 'network', 'title' => 'Cyber Security & Networks', 'text' => 'Secure systems, IoT and cloud architectures for campuses and small enterprises.'],
                ['icon' => 'trending-up', 'title' => 'Finance & Economics', 'text' => 'Financial inclusion, fintech adoption and the economics of digital payments.'],
                ['icon' => 'leaf', 'title' => 'Sustainability', 'text' => 'Green operations, ESG reporting and sustainable business models.'],
                ['icon' => 'users', 'title' => 'Entrepreneurship', 'text' => 'Start-up ecosystems, family business and social entrepreneurship.']]],
                ['background' => 'light']),
            $b('text', ['heading' => 'Support for researchers', 'html' => '<p>The Research &amp; Development Cell offers seed grants for faculty and student projects, sponsorship for conference presentations, access to licensed databases and statistical software, and an annual research symposium where projects are reviewed by external experts.</p>'
                . '<h3>Innovation &amp; incubation</h3><p>Student teams can apply to the GIMT incubation programme for mentoring, co-working space and introductions to investors. Hackathons and the annual Innovation Challenge help turn classroom projects into prototypes and start-ups.</p>'
                . '<h3>Consultancy</h3><p>Faculty undertake consultancy and training assignments for industry and government organisations, bringing real problems — and case studies — back into the classroom.</p>', 'align' => 'left', 'width' => 'normal']),
            $b('cta', ['eyebrow' => '', 'title' => 'Collaborate with GIMT', 'text' => 'Industry partners, researchers and alumni are welcome to propose joint projects, internships and guest lectures.', 'primary' => ['label' => 'Get in Touch', 'url' => '/contact'], 'secondary' => ['label' => '', 'url' => ''], 'image' => '', 'theme' => 'orange']),
        ], 'academics', 4];
    $pages[] = ['campus-life', 'Campus Life', 'A Place to Learn, Grow and Thrive', 'Clubs, sports, festivals, hostels and student support — life on the GIMT campus beyond the classroom.', $img('students-lawn.jpg'),
        '',
        [
            $b('hero', ['eyebrow' => 'Student life', 'title' => 'Campus Life at GIMT', 'highlight' => 'Learn, grow and thrive.', 'text' => 'Our green, safe campus is home to clubs, sports, cultural festivals and a close-knit community that makes every student feel at home.',
                'image' => $img('students-lawn.jpg'), 'image_alt' => 'Students relaxing on the GIMT lawn', 'align' => 'left', 'size' => 'md', 'primary_cta' => ['label' => 'Visit the Campus', 'url' => '/contact?type=campus_visit#message'], 'secondary_cta' => ['label' => 'Hostel Facilities', 'url' => '/campus#hostel'],
                'chips' => [['icon' => 'trees', 'label' => 'Green Campus'], ['icon' => 'shield-check', 'label' => 'Safe & Secure'], ['icon' => 'dumbbell', 'label' => 'Sports Complex']]]),
            $b('gallery', ['eyebrow' => 'Facilities', 'heading' => 'Spaces designed for students', 'subheading' => '', 'source' => 'manual', 'gallery_id' => null, 'layout' => 'bento', 'columns' => '3', 'items' => [
                ['image' => $img('facility-classroom.jpg'), 'alt' => 'Smart classroom with interactive panels', 'caption' => 'Smart Classrooms'],
                ['image' => $img('facility-labs.jpg'), 'alt' => 'Students in an advanced computer laboratory', 'caption' => 'Advanced Laboratories'],
                ['image' => $img('facility-library.jpg'), 'alt' => 'Digital library with book stacks', 'caption' => 'Digital Library'],
                ['image' => $img('facility-hostel.jpg'), 'alt' => 'Hostel common room', 'caption' => 'Hostel Facilities'],
                ['image' => $img('facility-sports.jpg'), 'alt' => 'Outdoor sports courts', 'caption' => 'Sports & Recreation'],
                ['image' => $img('facility-green-campus.jpg'), 'alt' => 'Tree-lined green campus', 'caption' => 'Green & Safe Campus']]],
                ['background' => 'light']),
            $b('cards', ['eyebrow' => 'Get involved', 'heading' => 'Clubs & societies', 'subheading' => 'Over twenty student-run clubs organise events, competitions and community projects every semester.', 'style' => 'pastel', 'columns' => '4', 'items' => [
                ['icon' => 'code-xml', 'title' => 'Coding Club', 'text' => 'Hackathons, open-source sprints and competitive programming.', 'tint' => 'lavender'],
                ['icon' => 'briefcase-business', 'title' => 'Entrepreneurship Cell', 'text' => 'Start-up talks, pitch days and the annual business plan contest.', 'tint' => 'sky'],
                ['icon' => 'palette', 'title' => 'Cultural Society', 'text' => 'Music, dance, theatre and the flagship cultural festival.', 'tint' => 'aqua'],
                ['icon' => 'dumbbell', 'title' => 'Sports Council', 'text' => 'Cricket, football, basketball, athletics and indoor games.', 'tint' => 'cream'],
                ['icon' => 'heart', 'title' => 'NSS & Social Outreach', 'text' => 'Literacy drives, blood donation camps and village adoption.', 'tint' => 'sand'],
                ['icon' => 'presentation', 'title' => 'Debating & Literary Club', 'text' => 'Debates, MUNs, quizzes and the campus magazine.', 'tint' => 'lavender'],
                ['icon' => 'image', 'title' => 'Photography Club', 'text' => 'Photo walks, exhibitions and event coverage.', 'tint' => 'sky'],
                ['icon' => 'leaf', 'title' => 'Eco Club', 'text' => 'Tree plantation, waste segregation and green audits.', 'tint' => 'aqua']]]),
            $b('text', ['heading' => 'Support beyond academics', 'html' => '<p>Our student welfare team includes a full-time counsellor, a medical room with a visiting doctor, and an anti-ragging squad that keeps the campus safe. Separate hostels for boys and girls offer wardens on site, Wi-Fi, a mess serving nutritious meals and transport to key points in Delhi NCR.</p>', 'align' => 'left', 'width' => 'normal']),
        ], 'campus', 5];

    $legal = function (string $intro, array $sections) use ($updated): string {
        $html = '<p><em>Last updated: ' . e($updated) . '</em></p><p>' . $intro . '</p>';
        foreach ($sections as $h => $body) {
            $html .= '<h2>' . e($h) . '</h2>' . $body;
        }
        return $html;
    };
    $pages[] = ['privacy-policy', 'Privacy Policy', 'How we collect, use and protect your information', 'How GIMT collects, uses, stores and protects personal information submitted through this website and our admission process.', null,
        $legal('Global Institute of Management &amp; Technology ("GIMT", "we", "us") respects your privacy. This policy explains what personal information we collect through this website, our online application and our enquiry forms, how we use it, and the choices you have.', [
            '1. Information we collect' => '<ul><li><strong>Information you give us</strong> — name, email address, phone number, city, program of interest and messages you send through enquiry, contact, newsletter and event registration forms.</li><li><strong>Application information</strong> — details and documents you submit with an online application, such as academic records, identity proof, category certificates and photographs.</li><li><strong>Technical information</strong> — IP address, browser type, pages visited and referring website, collected through server logs and, where enabled, analytics cookies.</li></ul>',
            '2. How we use your information' => '<ul><li>To respond to enquiries and provide admission counselling.</li><li>To process applications, verify documents, communicate decisions and complete enrolment.</li><li>To send updates you have asked for, such as newsletters and event reminders (you can unsubscribe at any time).</li><li>To keep the website secure, prevent abuse and improve our services.</li><li>To meet legal and regulatory obligations.</li></ul>',
            '3. Sharing of information' => '<p>We do not sell personal information. We share it only with affiliating and regulatory bodies where required by law, with service providers that host our systems or send messages on our behalf under confidentiality obligations, and with recruiters only for students who have registered for placement.</p>',
            '4. Cookies' => '<p>We use essential cookies to keep forms secure and remember your session. Analytics cookies, if enabled, help us understand how visitors use the website. You can block cookies in your browser settings; essential features such as forms may then not work correctly.</p>',
            '5. Data retention & security' => '<p>Enquiry data is kept for up to two years; application and student records are kept for as long as required by academic and legal rules. Information is stored on secured servers with access limited to authorised staff, encrypted connections and regular backups.</p>',
            '6. Your rights' => '<p>You may request access to, correction of or deletion of your personal information, or withdraw consent for marketing communication, by writing to us. We will respond within 30 days.</p>',
            '7. Contact & grievance officer' => '<p>For privacy questions or complaints, contact the Grievance Officer, GIMT, ' . e($address) . ' — email <a href="mailto:' . e($email) . '">' . e($email) . '</a>, phone ' . e($phone) . '.</p>',
        ]), [], null, 20];
    $pages[] = ['terms-and-conditions', 'Terms & Conditions', 'Terms of use of the GIMT website', 'The terms that apply when you use the GIMT website, online application and other online services.', null,
        $legal('By accessing this website or using our online services you agree to these terms. If you do not agree, please do not use the website.', [
            '1. Use of the website' => '<p>The content of this website is provided for general information about GIMT, its programs and activities. You agree to use it lawfully and not to attempt to gain unauthorised access to any part of our systems, upload harmful code or misuse our forms.</p>',
            '2. Accuracy of information' => '<p>We try to keep program details, fees, eligibility criteria and dates accurate and up to date. They may change because of decisions of the institute, the affiliating university or regulators. The admission brochure and official notices issued by GIMT prevail in case of any difference.</p>',
            '3. Online applications' => '<p>You are responsible for the accuracy of the information and documents you submit. Applications with false or misleading information may be rejected, and admission may be cancelled at any stage if such information is discovered later.</p>',
            '4. Intellectual property' => '<p>The GIMT name, logo, text, photographs and design of this website belong to GIMT or its licensors and may not be reproduced without written permission, except for personal, non-commercial use.</p>',
            '5. Links to other websites' => '<p>Links to external websites are provided for convenience. GIMT is not responsible for their content or privacy practices.</p>',
            '6. Limitation of liability' => '<p>GIMT is not liable for any loss arising from the use of, or inability to use, this website, including interruptions, errors or unauthorised access beyond our reasonable control.</p>',
            '7. Governing law' => '<p>These terms are governed by the laws of India. Courts at Gautam Buddh Nagar, Uttar Pradesh, have exclusive jurisdiction.</p>',
        ]), [], null, 21];
    $pages[] = ['refund-policy', 'Refund Policy', 'Fee refunds and cancellation of admission', 'The rules for refund of fees when a student withdraws from a program at GIMT.', null,
        $legal('This policy explains how fees are refunded when an admitted student withdraws from a program. It follows the applicable regulations of the affiliating university and regulators; in case of any conflict, those regulations prevail.', [
            '1. Application fee' => '<p>The application / registration fee is non-refundable, except when the institute cancels the program applied for.</p>',
            '2. Withdrawal of admission' => '<table><thead><tr><th>When the written request is received</th><th>Refund of fees paid</th></tr></thead><tbody>'
                . '<tr><td>15 days or more before the notified start of classes</td><td>100% (less a processing fee of up to ₹1,000)</td></tr>'
                . '<tr><td>Less than 15 days before the start of classes</td><td>90%</td></tr>'
                . '<tr><td>Within 15 days after the start of classes</td><td>80%</td></tr>'
                . '<tr><td>Between 16 and 30 days after the start of classes</td><td>50%</td></tr>'
                . '<tr><td>More than 30 days after the start of classes</td><td>No refund</td></tr></tbody></table>',
            '3. Hostel and transport fees' => '<p>Hostel and transport fees are refunded on a pro-rata monthly basis for the unused period, after deducting any dues. Security deposits are refunded within 30 days of clearing all dues.</p>',
            '4. How to apply for a refund' => '<p>Submit the withdrawal form available at the Accounts Office or write to <a href="mailto:' . e($email) . '">' . e($email) . '</a> with your application number, the reason for withdrawal, a cancelled cheque and the original fee receipts. Original documents deposited with the institute are returned with the refund.</p>',
            '5. Processing time' => '<p>Approved refunds are credited to the bank account of the student or parent within 15 working days. Cash refunds are not made.</p>',
        ]), [], null, 22];
    $pages[] = ['accessibility', 'Accessibility', 'Our commitment to an accessible website and campus', 'GIMT is committed to making its website and campus accessible to everyone, including people with disabilities.', null,
        $legal('GIMT wants everyone — including people with visual, hearing, motor or cognitive disabilities — to be able to use this website and our campus independently.', [
            'Our standard' => '<p>We aim to conform to the Web Content Accessibility Guidelines (WCAG) 2.1 level AA and the Guidelines for Indian Government Websites wherever they apply.</p>',
            'What we have done' => '<ul><li>Pages work with a keyboard alone and show a visible focus indicator.</li><li>Images carry text alternatives, and headings follow a logical order.</li><li>Colour contrast meets AA levels; text can be enlarged to 200% without loss of content.</li><li>Animations respect the "reduce motion" setting of your device, and carousels can be paused.</li><li>Forms have labels, clear error messages and do not rely on colour alone.</li></ul>',
            'On campus' => '<p>Our buildings have ramps, lifts and accessible washrooms. Students with disabilities can request reserved seating, scribes and extra time in examinations as per university rules through the Equal Opportunity Cell.</p>',
            'Known limitations' => '<p>Some older PDF documents may not be fully accessible. Contact us and we will provide the information in an accessible format.</p>',
            'Feedback' => '<p>If you face any difficulty using this website, please email <a href="mailto:' . e($email) . '">' . e($email) . '</a> or call ' . e($phone) . '. We aim to respond within five working days.</p>',
        ]), [], null, 23];

    // Route pages: hero copy + intro read by the dedicated templates
    $routePages = [
        ['programs', 'Academic Programs', 'Future-Ready Programs for a Brighter Tomorrow', 'Explore industry-aligned undergraduate, postgraduate, diploma and certificate programs in management, technology, computer applications, commerce and data science.', $img('students-laptop.jpg'), 10],
        ['admissions', 'Admissions', 'Your Journey to GIMT Starts Here', 'Everything you need to apply — eligibility, the admission process, fees, scholarships, documents and important dates.', $img('hero-campus-students.jpg'), 11],
        ['academics', 'Academics', 'Learning That Works in the Real World', 'Departments, experienced faculty, outcome-based curriculum, academic calendar and research at GIMT.', $img('facility-classroom.jpg'), 12],
        ['campus', 'Campus Life', 'A Place to Learn, Grow and Thrive', 'Smart classrooms, labs, library, hostels, sports and a vibrant student community on a green, safe campus.', $img('students-lawn.jpg'), 13],
        ['placement', 'Placements', 'Building Careers, Not Just Résumés', 'Training, internships and placements with leading recruiters across consulting, IT, banking, analytics and FMCG.', $img('placement-student.jpg'), 14],
        ['alumni', 'Alumni', 'Once a GIMTian, Always a GIMTian', 'Our alumni network connects graduates across industries for mentoring, jobs, events and giving back.', $img('graduation.jpg'), 15],
        ['faculty', 'Our Faculty', 'Experienced Academics & Industry Mentors', 'Meet the qualified, experienced faculty who teach, mentor and research at GIMT.', $img('facility-classroom.jpg'), 16],
        ['contact', 'Contact Us', "We're Here to Help", 'Call, email or visit us — our admissions and student support teams are happy to help.', $img('campus-building.jpg'), 17],
    ];
    foreach ($routePages as [$slug, $title, $subtitle, $excerpt, $banner, $sort]) {
        $pages[] = [$slug, $title, $subtitle, $excerpt, $banner, '<p>' . e($excerpt) . '</p>', [], null, $sort];
    }

    // Upsert by slug: on a fresh install the table is empty here (05 runs before every other page seeder), so the pages get
    // ids 1..n in this order (About GIMT = 1); on a re-run the existing rows are reset in place and keep their ids.
    $ids = [];
    $n = 0;
    foreach ($pages as [$slug, $title, $subtitle, $excerpt, $banner, $content, $blocks, $parent, $sort]) {
        $n++;
        [$cleanBlocks, $errors] = cms_validate_blocks($blocks);
        if ($errors) {
            throw new RuntimeException("Invalid seed blocks for page $slug: " . json_encode($errors));
        }
        $row = [
            'title' => $title, 'template' => 'default', 'subtitle' => $subtitle, 'banner_image' => $banner, 'excerpt' => $excerpt,
            'content' => $content !== '' ? sanitize_html($content) : null, 'blocks' => $cleanBlocks ? json_encode($cleanBlocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'parent_id' => null, 'sort_order' => $sort, 'is_system' => 1, 'status' => 'published', 'published_at' => $published, 'created_by' => $adminId, 'updated_by' => $adminId,
            'created_at' => $published, 'updated_at' => date('Y-m-d H:i:s', strtotime('-' . (40 - $n) . ' days')),
        ];
        $existing = db_value('SELECT id FROM pages WHERE slug = ?', [$slug]);
        if ($existing) {
            db_update('pages', $row, 'id = ?', [$existing]);
            $ids[$slug] = (int) $existing;
        } else {
            $ids[$slug] = db_insert('pages', $row + ['slug' => $slug]);
        }
    }
    foreach ($pages as [$slug, , , , , , , $parent]) {
        if ($parent && isset($ids[$parent])) {
            db_exec('UPDATE pages SET parent_id = ?, updated_at = updated_at WHERE id = ?', [$ids[$parent], $ids[$slug]]); // keep the seeded updated_at
        }
    }

    /* ------------------------------------------------------------------ menus (identical to the website fallbacks) */
    // The Programs item is a mega menu without children: the website auto-fills it from the programs table
    // (program cards with level / department filters). Items whose URL is a CMS page are linked to that page.
    $bySlug = [];
    foreach (db_all('SELECT id, slug, is_system FROM pages') as $p) {
        $bySlug[$p['slug']] = $p;
    }
    $names = ['header' => 'Main Navigation', 'footer_quick' => 'Footer — Institute', 'footer_academics' => 'Footer — Programs', 'footer_admissions' => 'Footer — Admissions',
        'footer_support' => 'Footer — Student Corner', 'legal' => 'Footer — Legal', 'topbar' => 'Top Bar Links', 'mobile' => 'Mobile Drawer'];
    foreach ($names as $location => $name) {
        $items = $location === 'mobile' ? [] : cms_menu_link_pages(cms_menu_fallback($location), $bySlug);
        cms_menu_save_tree($location, $items, ['name' => $name, 'status' => in_array($location, ['topbar', 'mobile'], true) ? 'inactive' : 'active']);
    }

    /* ------------------------------------------------------------------ home page sections */
    foreach (cms_default_sections() as $key => $s) {
        [$clean, $errors] = cms_validate_section($key, $s);
        if ($errors) {
            throw new RuntimeException("Invalid default home section $key: " . json_encode($errors));
        }
        db_insert('homepage_sections', ['section_key' => $key, 'title' => $clean['title'], 'subtitle' => $clean['subtitle'],
            'content' => json_encode($clean['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'is_enabled' => $s['is_enabled'], 'sort_order' => $s['sort_order'],
            'updated_at' => date('Y-m-d H:i:s', strtotime('-' . (30 - $s['sort_order']) . ' days'))]);
    }

    /* ------------------------------------------------------------------ SEO */
    $site = absolute_url('');
    $org = ['@context' => 'https://schema.org', '@type' => 'CollegeOrUniversity', 'name' => institute_name(), 'url' => $site, 'telephone' => $phone, 'email' => $email,
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => $address, 'addressLocality' => 'Greater Noida', 'addressRegion' => 'Uttar Pradesh', 'postalCode' => '201310', 'addressCountry' => 'IN']];
    $seo = [
        // route => [title, description, keywords, og image, robots, priority, changefreq, schema]
        '/' => ['GIMT Greater Noida — Management & Technology Institute', 'Global Institute of Management & Technology (GIMT), Greater Noida: industry-focused BBA, MBA, B.Tech, BCA, MCA and B.Com programs with strong placements.',
            'GIMT, Global IMT, college in Greater Noida, MBA college, B.Tech college, BBA, BCA, MCA', 'hero-campus-students.jpg', 'index,follow', 1.0, 'daily',
            ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => institute_name(), 'url' => $site,
             'potentialAction' => ['@type' => 'SearchAction', 'target' => $site . 'search?q={search_term_string}', 'query-input' => 'required name=search_term_string']]],
        '/about' => ['About GIMT — Our Story, Vision & Leadership', 'Since 2008, GIMT has offered industry-focused education in Greater Noida. Discover our story, vision and mission, leadership, campus and approvals.',
            'about GIMT, GIMT history, vision mission, leadership', 'campus-building.jpg', 'index,follow', 0.8, 'monthly', ['@context' => 'https://schema.org', '@type' => 'AboutPage', 'name' => 'About GIMT', 'url' => $site . 'about']],
        '/programs' => ['Programs & Courses — UG, PG, Diploma | GIMT', 'Explore BBA, MBA, B.Tech (CSE, AI & DS), BCA, MCA, B.Com (Hons), M.Com, B.Sc Data Science, diploma and certificate programs at GIMT Greater Noida.',
            'BBA, MBA, B.Tech CSE, BCA, MCA, B.Com Hons, data science courses, Greater Noida', 'students-laptop.jpg', 'index,follow', 0.9, 'weekly', null],
        '/admissions' => ['Admissions 2026-27 — Apply Online | GIMT', 'Admissions are open for 2026-27. Check eligibility, admission process, fees, scholarships and documents, then apply online to GIMT in minutes.',
            'GIMT admission 2026, apply online, eligibility, fee structure, scholarships', 'hero-campus-students.jpg', 'index,follow', 0.9, 'weekly', null],
        '/academics' => ['Academics, Departments & Faculty | GIMT', 'Six departments, experienced faculty, outcome-based curriculum, academic calendar and research at Global Institute of Management & Technology.',
            'GIMT departments, faculty, academic calendar, research', 'facility-classroom.jpg', 'index,follow', 0.8, 'monthly', null],
        '/campus' => ['Campus Life & Facilities | GIMT Greater Noida', 'Smart classrooms, advanced labs, digital library, hostels, sports and 20+ student clubs on a green, safe campus in Knowledge Park, Greater Noida.',
            'GIMT campus, hostel, library, labs, student clubs', 'students-lawn.jpg', 'index,follow', 0.7, 'monthly', null],
        '/placement' => ['Placements & Recruiters | GIMT', 'Placement statistics, top recruiters, training and internships — see how the GIMT Training & Placement Cell builds careers in consulting, IT, banking and analytics.',
            'GIMT placements, recruiters, highest package, internships', 'placement-student.jpg', 'index,follow', 0.8, 'monthly', null],
        '/alumni' => ['Alumni Network & Success Stories | GIMT', 'Connect with GIMT alumni across industries — success stories, mentoring, alumni events and job opportunities for graduates of Global IMT.',
            'GIMT alumni, alumni network, success stories', 'graduation.jpg', 'index,follow', 0.6, 'monthly', null],
        '/faculty' => ['Our Faculty — Experienced Academics | GIMT', 'Meet the qualified and experienced faculty of GIMT across management, computer science, computer applications, commerce and sciences.',
            'GIMT faculty, professors, teachers', 'facility-classroom.jpg', 'index,follow', 0.6, 'monthly', null],
        '/contact' => ['Contact GIMT — Admissions & Student Support', 'Call +91 99554 46477, email info@gimt.ac.in or visit our campus in Knowledge Park, Greater Noida. Admissions, support and campus visit enquiries.',
            'contact GIMT, GIMT phone number, GIMT address, campus visit', 'campus-building.jpg', 'index,follow', 0.7, 'yearly', ['@context' => 'https://schema.org', '@type' => 'ContactPage', 'name' => 'Contact GIMT', 'url' => $site . 'contact', 'mainEntity' => $org]],
        '/apply' => ['Apply Online for Admission 2026-27 | GIMT', 'Apply online to GIMT in seven simple steps — choose your program, fill in your details, upload documents and track your application.',
            'GIMT application form, apply online', 'students-laptop.jpg', 'index,follow', 0.8, 'monthly', null],
        '/blog' => ['News, Events & Achievements | GIMT Blog', 'The latest news, achievements, events and articles from the students and faculty of Global Institute of Management & Technology.',
            'GIMT news, GIMT blog, campus events', 'news-campus.jpg', 'index,follow', 0.7, 'daily', null],
        '/faq' => ['Frequently Asked Questions | GIMT', 'Answers to common questions about admissions, eligibility, fees, scholarships, hostel, transport and placements at GIMT Greater Noida.',
            'GIMT FAQ, admission questions, fees, hostel', 'students-atrium.jpg', 'index,follow', 0.6, 'monthly', null],
        '/admissions-open' => ['Admissions Open 2026-27 — Free Counselling | GIMT', 'Admissions open for BBA, MBA, B.Tech, BCA, MCA and B.Com at GIMT Greater Noida. Get free career counselling and apply now.',
            'GIMT admissions open, free counselling', 'hero-campus-students.jpg', 'noindex,follow', 0.5, 'weekly', null],
        '/verify-certificate' => ['Verify a GIMT Certificate', 'Verify the authenticity of a certificate issued by Global Institute of Management & Technology using its certificate number or QR code.',
            'GIMT certificate verification', 'campus-building.jpg', 'index,follow', 0.3, 'yearly', null],
    ];
    // reset only the SEO rows this seeder owns (its routes + its pages); other units and editors keep theirs
    $ownRoutes = array_keys($seo);
    db_exec("DELETE FROM seo_settings WHERE entity_type = 'route' AND route IN (" . implode(',', array_fill(0, count($ownRoutes), '?')) . ')', $ownRoutes);
    $ownPageIds = array_values($ids);
    db_exec("DELETE FROM seo_settings WHERE entity_type = 'page' AND entity_id IN (" . implode(',', array_fill(0, count($ownPageIds), '?')) . ')', $ownPageIds);
    foreach ($seo as $route => [$title, $desc, $keywords, $og, $robots, $prio, $freq, $schema]) {
        db_insert('seo_settings', ['entity_type' => 'route', 'entity_id' => null, 'route' => $route, 'meta_title' => $title, 'meta_description' => $desc, 'meta_keywords' => $keywords,
            'og_title' => $title, 'og_description' => $desc, 'og_image' => $img($og), 'robots' => $robots, 'include_in_sitemap' => $robots === 'index,follow' ? 1 : 0,
            'sitemap_priority' => $prio, 'sitemap_changefreq' => $freq, 'schema_json' => $schema ? json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'updated_at' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(3, 60) . ' days'))]);
    }
    $pageSeo = [
        'vision-mission' => ['Vision, Mission & Core Values | GIMT', 'The vision, mission, core values and quality policy that guide teaching, research and student life at Global Institute of Management & Technology.'],
        'leadership' => ['Leadership & Governance | GIMT', 'Meet the Chairman, Director and Academic Dean of GIMT and learn how the Governing Body and Academic Council guide the institute.'],
        'research' => ['Research & Innovation at GIMT', 'Faculty and student research in AI, data science, business analytics, finance and sustainability, plus incubation and consultancy at GIMT.'],
        'campus-life' => ['Student Life, Clubs & Facilities | GIMT', 'Clubs and societies, sports, cultural festivals, hostels and student support — discover life on the GIMT campus beyond the classroom.'],
        'privacy-policy' => ['Privacy Policy | GIMT', 'How Global Institute of Management & Technology collects, uses, stores and protects personal information submitted through its website and forms.'],
        'terms-and-conditions' => ['Terms & Conditions | GIMT', 'Terms of use for the GIMT website, online application and other online services of Global Institute of Management & Technology.'],
        'refund-policy' => ['Fee Refund Policy | GIMT', 'Rules for the refund of admission, tuition, hostel and transport fees when a student withdraws from a program at GIMT Greater Noida.'],
        'accessibility' => ['Accessibility Statement | GIMT', 'Our commitment to an accessible website and campus for everyone, the standards we follow and how to report an accessibility problem.'],
    ];
    foreach ($pageSeo as $slug => [$title, $desc]) {
        db_insert('seo_settings', ['entity_type' => 'page', 'entity_id' => $ids[$slug], 'route' => null, 'meta_title' => $title, 'meta_description' => $desc,
            'og_title' => $title, 'og_description' => $desc, 'og_image' => in_array($slug, ['privacy-policy', 'terms-and-conditions', 'refund-policy', 'accessibility'], true) ? null : $img('campus-main.jpg'),
            'robots' => 'index,follow', 'include_in_sitemap' => 1, 'sitemap_priority' => in_array($slug, ['privacy-policy', 'terms-and-conditions', 'refund-policy', 'accessibility'], true) ? 0.3 : 0.6,
            'sitemap_changefreq' => 'monthly', 'updated_at' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(3, 60) . ' days'))]);
    }
};
