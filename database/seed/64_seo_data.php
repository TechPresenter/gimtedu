<?php
/**
 * 64 — SEO data + owner content decisions (2026-11-17). Idempotent, safe on a live database: every statement is
 * exact-string / exact-value based and does nothing when the text it looks for is not there.
 *
 * The same statement list builds the live SQL (storage/private/deploy/aws/seo/build_seo_data_sql.php →
 * storage/private/deploy/aws/seo/seo_data.sql), so dev installs and the live site get identical data:
 *   1. Share image: seo_settings.og_image 'og-gimt.jpg' (404 on live) and the /campus lawn photo → assets/images/og/gimt-share.jpg
 *   2. Owner decisions 2026-11-17 in DB-stored text (pages, home sections, settings, FAQs, menus, programs,
 *      departments, chatbot settings, WhatsApp-bot knowledge, demo media/banners):
 *        (1) rolling admissions — no deadline / countdown   (2) ₹999 application (registration) fee
 *        (3) the one scholarship statement                  (4) no campus facilities that do not fit the office address
 *        (5) no founding year                               (6) placement assistance, not outcomes
 *        (7) no metro-line claim
 *      + programs.total_credits synced to the curriculum (sum of the program's subject credits).
 *   3. Meta title / description / keywords: improved only where the value is still the seeded text.
 *   4. focus_keyword / secondary_keywords per URL and the keyword database (seo_keywords) from the keyword map.
 *   5. AI visibility baseline: the 10 standard test queries run with Claude's web search on 2026-11-17.
 * Every replaced string is listed for the owner in storage/private/deploy/aws/seo/CONTENT_CHANGES.md.
 *
 *   php tools/seed.php 64_seo_data
 */

if (!function_exists('seo64_facts')) {
    /** The owner's wording (decisions of 2026-11-17) — reused by every statement below. */
    function seo64_facts(): array
    {
        return [
            'rolling' => 'Rolling admissions — until seats are filled',
            'fee' => '₹999',
            'scholarships' => 'Merit scholarships of up to 50% of the tuition fee, plus sports, single girl child, economically weaker section, defence ward and sibling scholarships',
            'placement' => 'Placement assistance: career guidance, CV and interview preparation, and help applying to recruiters. There is no job guarantee.',
            'share_image' => 'assets/images/og/gimt-share.jpg',
            'address' => 'Wave One, 36th Floor, L-2A, Pocket G, Sector 18, Noida, Uttar Pradesh - 201301',
        ];
    }

    /**
     * Text replacements: [table, column, scope SQL (fixed, no input), old, new, decision, where it shows].
     * Applied as UPDATE t SET c = REPLACE(c, old, new) WHERE scope AND INSTR(c, old) > 0.
     */
    function seo64_text_changes(): array
    {
        $f = seo64_facts();
        $S = $f['scholarships'];
        $c = [];
        $add = function (string $t, string $col, string $scope, string $old, string $new, string $d, string $where) use (&$c) {
            $c[] = compact('t', 'col', 'scope', 'old', 'new', 'd', 'where');
        };

        /* ---------------------------------------------------------------- settings */
        $add('settings', 'value', "`key` = 'announcement_strip_text'", 'Merit scholarships available for eligible students',
            $f['rolling'] . ' | ' . 'Merit scholarships of up to 50% of the tuition fee', '1,3', 'Top announcement strip (every page)');
        $add('settings', 'value', "`key` = 'chatbot_welcome_sub'", 'Ask me about programs, fees, scholarships, eligibility, hostel or placements — in English or Hindi.',
            'Ask me about programs, fees, scholarships, eligibility, admissions or placement assistance — in English or Hindi.', '4,6', 'Website chat window subtitle');
        $add('settings', 'value', "`key` = 'chatbot_quick_replies'", ',{"label":"Hostel & transport","message":"Tell me about hostel and transport facilities."}', '', '4', 'Website chat quick replies');
        $add('settings', 'value', "`key` = 'chatbot_quick_replies'", '{"label":"Placements","message":"How are the placements?"}',
            '{"label":"Placement assistance","message":"Do you provide placement assistance?"}', '6', 'Website chat quick replies');
        $add('settings', 'value', "`key` = 'chatbot_sources'", '"hostel":true,"transport":true', '"hostel":false,"transport":false', '4', 'Chatbot knowledge sources (no hostel / bus answers)');

        /* ---------------------------------------------------------------- home page sections */
        $add('homepage_sections', 'subtitle', "section_key = 'academic_excellence'", 'Since 2008, GIMT has prepared thousands of graduates for leadership roles in business and technology.',
            'Industry-oriented UG, PG, diploma and certificate programs in management, technology, commerce and computer applications — in Sector 18, Noida.', '5,6', 'Home › About GIMT block subtitle');
        $add('homepage_sections', 'content', "section_key = 'academic_excellence'", '"State-of-the-art infrastructure","Strong placement and career support"',
            '"Placement assistance and career guidance"', '4,6', 'Home › About GIMT bullet list');
        $add('homepage_sections', 'content', "section_key = 'why'", 'Digital library, e-journals, LMS and 24×7 Wi-Fi campus.', 'Digital library, e-journals and LMS access.', '4', 'Home › Why Choose GIMT');
        $add('homepage_sections', 'content', "section_key = 'why'", 'Sports, clubs, cultural fests and community service.', 'Clubs, cultural fests and community service.', '4', 'Home › Why Choose GIMT');
        $add('homepage_sections', 'content', "section_key = 'campus_life'", 'Clubs, fests, sports and a green, safe campus that feels like home.', 'Clubs, fests and a safe, welcoming campus that feels like home.', '4', 'Home › Life at GIMT card');
        $add('homepage_sections', 'content', "section_key = 'campus_life'", 'A dedicated placement cell connecting students with leading recruiters.',
            'Placement assistance: career guidance, CV and interview preparation, and help applying to recruiters.', '6', 'Home › Life at GIMT card');
        $add('homepage_sections', 'title', "section_key = 'placements'", 'Placement Highlights', 'Placement Assistance', '6', 'Home › placement block title');
        $add('homepage_sections', 'subtitle', "section_key = 'placements'", 'Our students are hired by leading companies across consulting, IT, banking and FMCG.',
            'Career guidance, CV and interview preparation, and help applying to recruiters — for every student. No job guarantee.', '6', 'Home › placement block subtitle');
        $add('homepage_sections', 'content', "section_key = 'placements'", 'The GIMT Training & Placement Cell prepares every student through aptitude training, mock interviews and industry mentoring, and partners with recruiters for internships and final placements.',
            'The GIMT Training & Placement Cell offers placement assistance — career guidance, aptitude training, CV and interview preparation, and help applying to recruiters for internships and jobs. Placement is not guaranteed.', '6', 'Home › placement block text');
        foreach ([['90', '%', 'Placement rate'], ['25', 'LPA', 'Highest package'], ['6', 'LPA', 'Average package'], ['35', '+', 'Recruiters']] as [$v, $suffix, $label]) {
            $prefix = $suffix === 'LPA' ? '₹' : '';
            $add('homepage_sections', 'content', "section_key = 'placements'", '{"value":"' . $v . '","prefix":"' . $prefix . '","suffix":"' . $suffix . '","label":"' . $label . '"',
                '{"value":"","prefix":"' . $prefix . '","suffix":"' . $suffix . '","label":"' . $label . '"', '6', 'Home › placement stats: seeded fallback number removed (only real placement records are shown)');
        }
        $add('homepage_sections', 'content', "section_key = 'placements'", '"image_alt":"A GIMT graduate after a successful interview"', '"image_alt":"A GIMT student preparing for an interview"', '6', 'Home › placement photo alt text');
        $add('homepage_sections', 'subtitle', "section_key = 'faqs'", 'Quick answers about admissions, fees, hostel and placements.', 'Quick answers about admissions, fees, scholarships and placement assistance.', '4,6', 'Home › FAQ block subtitle');
        $add('homepage_sections', 'content', "section_key = 'hero'", '{"icon":"building-2","label":"Modern Campus"}', '{"icon":"map-pin","label":"Sector 18, Noida"}', '4', 'Home › hero chips');
        $add('homepage_sections', 'content', "section_key = 'hero'", '{"icon":"trending-up","label":"Placement Support"}', '{"icon":"trending-up","label":"Placement Assistance"}', '6', 'Home › hero chips');
        $add('homepage_sections', 'content', "section_key = 'features'", 'Dedicated placement cell, aptitude training and mock interviews.', 'Placement assistance with aptitude training, CV and interview preparation.', '6', 'Home › Why students choose GIMT');

        /* ---------------------------------------------------------------- pages */
        $based = 'Based in Sector 18, Noida, the Global Institute of Management &amp; Technology is built on a simple belief';
        foreach (['Founded in 2008 in Sector 18, Noida, the Global Institute of Management &amp; Technology began with programs in management and commerce and a simple belief',
                  'Founded in 2008 in Knowledge Park, Greater Noida, the Global Institute of Management &amp; Technology began with programs in management and commerce and a simple belief',
                  'Founded in 2008 in Knowledge Park, Greater Noida, the Global Institute of Management &amp; Technology began with a single management program and a simple belief'] as $old) {
            $add('pages', 'content', "slug = 'about'", $old, $based, '5', '/about story');
        }
        $add('pages', 'content', "slug = 'about'", 'We measure our success by the success of our graduates — the managers, engineers, analysts and entrepreneurs who carry the GIMT name into leading organisations across India and abroad.',
            'We measure our success by the success of our students, and we support every one of them with placement assistance: career guidance, CV and interview preparation, and help applying to recruiters.', '6', '/about story');
        $add('pages', 'blocks', "slug = 'about'", ',{"value":"18","prefix":"","suffix":"+","label":"Years of excellence","icon":"award","source":"years"}', '', '5', '/about statistics band');
        // campus (system page text) + campus-life (page builder)
        $add('pages', 'excerpt', "slug = 'campus'", 'Smart classrooms, labs, library, hostels, sports and a vibrant student community on a green, safe campus.',
            'Smart classrooms, computer labs, a library and a vibrant student community in Sector 18, Noida.', '4', '/campus intro');
        $add('pages', 'content', "slug = 'campus'", 'Smart classrooms, labs, library, hostels, sports and a vibrant student community on a green, safe campus.',
            'Smart classrooms, computer labs, a library and a vibrant student community in Sector 18, Noida.', '4', '/campus intro');
        $add('pages', 'excerpt', "slug = 'campus-life'", 'Clubs, sports, festivals, hostels and student support — life on the GIMT campus beyond the classroom.',
            'Clubs, festivals and student support — student life at GIMT beyond the classroom.', '4', '/campus-life intro');
        $add('pages', 'blocks', "slug = 'campus-life'", 'Our green, safe campus is home to clubs, sports, cultural festivals and a close-knit community that makes every student feel at home.',
            'GIMT is home to student clubs, cultural festivals and a close-knit community that makes every student feel at home.', '4', '/campus-life hero');
        $add('pages', 'blocks', "slug = 'campus-life'", '"image_alt":"Students relaxing on the GIMT lawn"', '"image_alt":"Students at GIMT"', '4', '/campus-life hero photo alt');
        $add('pages', 'blocks', "slug = 'campus-life'", '"secondary_cta":{"label":"Hostel Facilities","url":"/campus#hostel"}', '"secondary_cta":{"label":"Explore Programs","url":"/programs"}', '4', '/campus-life hero button');
        $add('pages', 'blocks', "slug = 'campus-life'", '"chips":[{"icon":"trees","label":"Green Campus"},{"icon":"shield-check","label":"Safe & Secure"},{"icon":"dumbbell","label":"Sports Complex"}]',
            '"chips":[{"icon":"shield-check","label":"Safe & Secure"}]', '4', '/campus-life hero chips');
        $add('pages', 'blocks', "slug = 'campus-life'", ',{"image":"assets/images/site/facility-hostel.jpg","alt":"Hostel common room","caption":"Hostel Facilities"},{"image":"assets/images/site/facility-sports.jpg","alt":"Outdoor sports courts","caption":"Sports & Recreation"},{"image":"assets/images/site/facility-green-campus.jpg","alt":"Tree-lined green campus","caption":"Green & Safe Campus"}',
            '', '4', '/campus-life facilities gallery (hostel, sports courts, green campus photos removed)');
        $add('pages', 'blocks', "slug = 'campus-life'", ',{"title":"Sports Council","subtitle":"","icon":"dumbbell","image":"","text":"Cricket, football, basketball, athletics and indoor games.","url":"","link_label":"","tint":"cream"}',
            '', '4', '/campus-life clubs (Sports Council card removed)');
        $add('pages', 'blocks', "slug = 'campus-life'", '<p>Our student welfare team includes a full-time counsellor, a medical room with a visiting doctor, and an anti-ragging squad that keeps the campus safe. Separate hostels for boys and girls offer wardens on site, Wi-Fi, a mess serving nutritious meals and transport to key points in Delhi NCR.</p>',
            '<p>Our student welfare team includes a full-time counsellor and an anti-ragging squad that keeps the campus safe.</p>', '4', '/campus-life support text');
        // placements
        foreach (['excerpt', 'content'] as $col) {
            $add('pages', $col, "slug = 'placement'", 'Training, internships and placements with leading recruiters across consulting, IT, banking, analytics and FMCG.',
                'Placement assistance for every student: career guidance, aptitude training, CV and interview preparation, and help applying to recruiters. No job guarantee.', '6', '/placement intro');
        }
        // mandatory disclosure: approvals sentence (approvals are switched off) + year of establishment row
        $add('pages', 'content', "slug = 'mandatory-disclosure'", '<p>As recorded by the institute: Affiliated to State University · Approved by AICTE.</p>', '', 'approvals', '/mandatory-disclosure');
        $add('pages', 'content', "slug = 'mandatory-disclosure'", '<tr><td>Year of establishment</td><td>2008</td></tr>', '', '5', '/mandatory-disclosure');
        // the site's real address (the seeded 'www.gimt.ac.in' is not the website; settings.website is fixed in seo64_statements())
        $add('pages', 'content', "slug = 'mandatory-disclosure'", '<tr><td>Website</td><td>www.gimt.ac.in</td></tr>', '<tr><td>Website</td><td>https://gimtedu.com</td></tr>', 'data', '/mandatory-disclosure');
        // scholarship policy placeholder → the owner's statement
        $add('pages', 'content', "slug = 'scholarship-policy'", '[To be confirmed by GIMT: scholarship categories, percentages and number of awards for 2026-27]',
            'For 2026-27 these are: ' . lcfirst($S) . '.', '3', '/scholarship-policy');
        // refund / fee / conduct / anti-ragging / grievance policies: no hostel or college transport
        $add('pages', 'content', "slug = 'refund-policy'", '<h2>3. Hostel and transport fees</h2><p>Hostel and transport fees are refunded on a pro-rata basis for the unused period, after deducting any dues. Caution money',
            '<h2>3. Caution money and deposits</h2><p>Caution money', '4', '/refund-policy');
        $add('pages', 'content', "slug = 'refund-and-cancellation-policy'", '<h2>Hostel, transport and other fees</h2><p>Hostel and transport fees are refunded on a pro-rata basis for the unused period after deducting dues. Caution money',
            '<h2>Deposits and other fees</h2><p>Caution money', '4', '/refund-and-cancellation-policy');
        $add('pages', 'content', "slug = 'fee-policy'", ' and optional services such as hostel and transport.</p>', '.</p>', '4', '/fee-policy');
        $add('pages', 'content', "slug = 'code-of-conduct'", 'on campus, in hostels and transport, during internships', 'on campus, during internships', '4', '/code-of-conduct');
        $add('pages', 'content', "slug = 'code-of-conduct'", 'Take care of institute property, laboratories, the library and hostels; damage is recoverable.', 'Take care of institute property, laboratories and the library; damage is recoverable.', '4', '/code-of-conduct');
        $add('pages', 'content', "slug = 'code-of-conduct'", 'Follow the dress code and the rules of the hostel, library, laboratories and transport as notified.', 'Follow the dress code and the rules of the library and laboratories as notified.', '4', '/code-of-conduct');
        $add('pages', 'subtitle', "slug = 'anti-ragging-policy'", 'Zero tolerance for ragging — on campus, in hostels and online', 'Zero tolerance for ragging — on campus and online', '4', '/anti-ragging-policy');
        $add('pages', 'content', "slug = 'anti-ragging-policy'", 'in classrooms, hostels, transport, at events and online.', 'in classrooms, at events and online.', '4', '/anti-ragging-policy');
        $add('pages', 'content', "slug = 'anti-ragging-policy'", 'surprise checks in hostels, transport, canteens and other vulnerable places', 'surprise checks at vulnerable places', '4', '/anti-ragging-policy');
        $add('pages', 'content', "slug = 'anti-ragging-policy'", 'in the prospectus, at prominent places on campus and in every hostel.', 'in the prospectus and at prominent places on campus.', '4', '/anti-ragging-policy');
        $add('pages', 'content', "slug = 'anti-ragging-policy'", 'listed below, a faculty mentor or the hostel warden — at any time.', 'listed below or a faculty mentor — at any time.', '4', '/anti-ragging-policy');
        foreach (['Boys’', 'Girls’'] as $who) {
            $add('pages', 'content', "slug = 'anti-ragging-policy'", '<tr><td>Chief Warden (' . $who . ' hostel)</td><td>[To be confirmed by GIMT: name]</td><td>[To be confirmed by GIMT: phone]</td><td>[To be confirmed by GIMT: email]</td></tr>',
                '', '4', '/anti-ragging-policy contact table (hostel warden row)');
        }
        $add('pages', 'content', "slug = 'anti-ragging-policy'", ', suspension or expulsion from the hostel,', ',', '4', '/anti-ragging-policy');
        $add('pages', 'content', "slug = 'grievance-redressal-policy'", 'library, hostel, transport and other facilities', 'library and other facilities', '4', '/grievance-redressal-policy');

        /* ---------------------------------------------------------------- menus, programs */
        $add('menu_items', 'description', "title = 'Campus & Facilities'", 'Labs, library, hostel and sports', 'Classrooms, labs and library', '4', 'Header mega-menu');
        $add('menu_items', 'description', "title = 'Important Dates'", 'Deadlines for the 2026-27 session', 'Key dates of the 2026-27 session', '1', 'Header mega-menu (no deadline wording)');
        $add('menu_items', 'description', "title = 'Placements'", 'Training, drives and recruiters', 'Placement assistance and career guidance', '6', 'Header mega-menu');
        $add('programs', 'highlights', '1 = 1', 'Dedicated placement and career support', 'Placement assistance and career guidance', '6', 'Program pages › highlights');

        /* ---------------------------------------------------------------- FAQs (admin FAQ table; seeded demo set on dev) */
        $add('faqs', 'answer', "question = 'When do admissions open for the 2026-27 session?'",
            "Applications for most programs open in February and continue until seats are filled. Postgraduate programs follow the entrance and counselling schedule of the university.\n\nWe recommend applying early, as hostel rooms and scholarship funds are allotted on a first-come basis.",
            'Admissions for the 2026-27 session are rolling — applications stay open until seats are filled. We recommend applying early.', '1,4', 'FAQ');
        $add('faqs', 'answer', '1 = 1', 'upload your documents and pay the registration fee.', 'upload your documents and pay the ₹999 registration fee.', '2', 'FAQ');
        $add('faqs', 'answer', "question = 'What scholarships are available?'",
            'Merit scholarships, need-based support, sports and cultural excellence scholarships, and concessions for single girl child and wards of defence personnel.', $S . '.', '3', 'FAQ');
        $add('faqs', 'answer', "question = 'Does GIMT provide placement support?'",
            'Yes. The training and placement cell runs aptitude training, soft-skills workshops, mock interviews and on-campus recruitment drives for every program.',
            'Yes — placement assistance. The training and placement cell offers career guidance, aptitude and soft-skills training, CV and interview preparation, and help applying to recruiters. Placement is not guaranteed.', '6', 'FAQ');
        $add('faqs', 'answer', "question = 'What facilities are available on campus?'",
            'Smart classrooms, computer labs, a central library with e-resources, sports grounds, a gym, cafeteria, medical room, hostels and Wi-Fi across the campus.',
            'Smart classrooms, computer labs and a library with e-resources.', '4', 'FAQ');
        foreach (['The campus is in Knowledge Park, Greater Noida, Uttar Pradesh, well connected by road and the Aqua Line metro.',
                  'The campus is in Sector 18, Noida, Uttar Pradesh, well connected by road and the Aqua Line metro.'] as $old) {
            $add('faqs', 'answer', '1 = 1', $old, 'GIMT is at ' . $f['address'] . '.', '7', 'FAQ › Where is GIMT located?');
        }
        $add('faqs', 'answer', '1 = 1', ' The campus is well connected by road and the Aqua Line metro.', '', '7', 'FAQ › Where is GIMT located?');

        /* ---------------------------------------------------------------- demo media / banners (dev content; harmless on live) */
        $add('media', 'caption', '1 = 1', 'Group study on the central lawn.', 'Group study between classes.', '4', 'Media library caption');
        $add('media', 'alt_text', '1 = 1', 'Students studying together on the campus lawn', 'Students studying together', '4', 'Media library alt text');
        $add('media', 'caption', '1 = 1', 'Program-wise tuition, hostel and transport fees for the 2026-27 session.', 'Program-wise tuition fees for the 2026-27 session.', '4', 'Media library (fee structure PDF)');
        $add('gallery_items', 'caption', '1 = 1', 'Group study on the central lawn.', 'Group study between classes.', '4', 'Gallery caption');
        $add('galleries', 'description', '1 = 1', 'A walk through the GIMT campus — academic blocks, lawns, library and hostels.', 'A walk through GIMT — classrooms, labs and library.', '4', 'Gallery album description');
        $add('banners', 'image_alt', '1 = 1', 'GIMT students relaxing on the campus lawn', 'GIMT students', '4', 'Home slider alt text');
        $add('banners', 'image_alt', '1 = 1', 'Tree-lined green campus', 'GIMT students', '4', 'Home slider alt text');
        $add('banners', 'title', '1 = 1', 'Placements That Speak', 'Placement Assistance', '6', 'Home slider');
        $add('banners', 'highlight', '1 = 1', 'Top recruiters. Real careers.', 'Prepare for your career.', '6', 'Home slider');
        $add('banners', 'subheading', '1 = 1', 'Aptitude training, mock interviews and a dedicated placement cell that connects every final-year student with leading recruiters.',
            'Career guidance, aptitude training, mock interviews and help applying to recruiters. No job guarantee.', '6', 'Home slider');
        $add('banners', 'subheading', '1 = 1', 'Scholarships for meritorious students, single girl children, defence wards and sports achievers — apply along with your admission form.',
            $S . ' — apply along with your admission form.', '3', 'Home slider');
        $add('announcements', 'content', '1 = 1', 'Tour our classrooms, labs, library and hostels,', 'Tour our classrooms, labs and library,', '4', 'Announcement');

        /* ---------------------------------------------------------------- WhatsApp bot knowledge (when the WhatsApp module is installed) */
        $add('wa_bot_knowledge', 'answer_en', "question = 'How do I apply for admission at GIMT?'", "\nApplying online is free.", "\nThe online application (registration) fee is ₹999.", '2', 'WhatsApp bot answer');
        $add('wa_bot_knowledge', 'answer_hi', "question = 'How do I apply for admission at GIMT?'", "\nऑनलाइन आवेदन निःशुल्क है।", "\nऑनलाइन आवेदन (रजिस्ट्रेशन) शुल्क ₹999 है।", '2', 'WhatsApp bot answer (Hindi)');
        $add('wa_bot_knowledge', 'answer_en', "question = 'Is there an application fee?'", 'No — applying online is free.', 'Yes — the application (registration) fee is ₹999.', '2', 'WhatsApp bot answer');
        $add('wa_bot_knowledge', 'answer_hi', "question = 'Is there an application fee?'", 'नहीं — ऑनलाइन आवेदन निःशुल्क है।', 'हाँ — आवेदन (रजिस्ट्रेशन) शुल्क ₹999 है।', '2', 'WhatsApp bot answer (Hindi)');
        $add('wa_bot_knowledge', 'answer_en', "question = 'Tell me about GIMT'", ' (established in {established})', '', '5', 'WhatsApp bot answer');
        $add('wa_bot_knowledge', 'answer_hi', "question = 'Tell me about GIMT'", ' ({established} में स्थापित)', '', '5', 'WhatsApp bot answer (Hindi)');
        return $c;
    }

    /** Old seeded SEO texts (repo seed + the live copy) → improved text; applied only while the old text is unchanged. */
    function seo64_meta_changes(): array
    {
        $m = [];
        $add = function (string $kind, string $ref, array $fields) use (&$m) {
            $m[] = compact('kind', 'ref', 'fields');
        };
        $add('route', '/', [
            'meta_title' => [['GIMT Greater Noida — Management & Technology Institute'], 'GIMT Noida — Management & Technology Institute'],
            'meta_description' => [['Global Institute of Management & Technology (GIMT), Greater Noida: industry-focused BBA, MBA, B.Tech, BCA, MCA and B.Com programs with placement support.',
                'Global Institute of Management & Technology (GIMT), Noida: industry-focused BBA, MBA, B.Tech, BCA, MCA and B.Com programs with placement support.'],
                'GIMT, Sector 18, Noida: BBA, MBA, B.Tech, BCA, MCA, B.Com, diploma and certificate programs. Rolling admissions for 2026-27, with placement assistance.'],
            'meta_keywords' => [['GIMT, Global IMT, college in Greater Noida, MBA college, B.Tech college, BBA, BCA, MCA', 'GIMT, Global IMT, college in Noida, MBA college, B.Tech college, BBA, BCA, MCA'],
                'GIMT Noida, Global Institute of Management and Technology Noida, college in Sector 18 Noida, MBA college in Noida, BBA college in Noida, BCA, MCA'],
        ]);
        $add('route', '/about', [
            'meta_title' => [['About GIMT — Our Story, Vision & Leadership'], 'About GIMT Noida — Our Story, Vision & Mission'],
            'meta_description' => [['Since 2008, GIMT has offered industry-focused education in Greater Noida. Discover our story, vision and mission, leadership, campus and approvals.',
                'Since 2008, GIMT has offered industry-focused education in Noida. Discover our story, vision and mission, leadership, campus and approvals.'],
                'About Global Institute of Management & Technology (GIMT), Sector 18, Noida: our story, vision and mission, and the programs we offer.'],
            'meta_keywords' => [['about GIMT, GIMT history, vision mission, leadership'], 'about GIMT Noida, GIMT vision and mission, Global Institute of Management and Technology'],
        ]);
        $add('route', '/programs', [
            'meta_description' => [['Explore BBA, MBA, B.Tech (CSE, AI & DS), BCA, MCA, B.Com (Hons), M.Com, B.Sc Data Science, diploma and certificate programs at GIMT Greater Noida.'],
                'Explore BBA, MBA, B.Tech (CSE, AI & DS), BCA, MCA, B.Com (Hons), M.Com, B.Sc Data Science, diploma and certificate programs at GIMT Noida.'],
            'meta_keywords' => [['BBA, MBA, B.Tech CSE, BCA, MCA, B.Com Hons, data science courses, Greater Noida'], 'BBA, MBA, B.Tech CSE, BCA, MCA, B.Com Hons, data science courses, Noida'],
        ]);
        $add('route', '/campus', [
            'meta_title' => [['Campus Life & Facilities | GIMT Greater Noida', 'Campus Life & Facilities | GIMT Noida'], 'Campus & Facilities — GIMT, Sector 18, Noida'],
            'meta_description' => [['Smart classrooms, advanced labs, digital library, hostels, sports and student clubs on a green, safe campus in Knowledge Park, Greater Noida.',
                'Smart classrooms, advanced labs, digital library, hostels, sports and student clubs on a green, safe campus in Sector 18, Noida.'],
                'Smart classrooms, computer labs, a digital library and student clubs at GIMT, Wave One, Sector 18, Noida.'],
            'meta_keywords' => [['GIMT campus, hostel, library, labs, student clubs'], 'GIMT Noida campus, library, computer labs, student clubs'],
        ]);
        $add('route', '/placement', [
            'meta_title' => [['Placements & Recruiters | GIMT'], 'Placement Assistance & Career Support | GIMT Noida'],
            'meta_description' => [['Placement statistics, top recruiters, training and internships — see how the GIMT Training & Placement Cell builds careers in consulting, IT, banking and analytics.'],
                'Placement assistance at GIMT Noida: career guidance, aptitude training, CV and interview preparation, and help applying to recruiters. No job guarantee.'],
            'meta_keywords' => [['GIMT placements, recruiters, highest package, internships'], 'GIMT Noida placements, placement assistance, career guidance, internships'],
        ]);
        $add('route', '/faq', [
            'meta_description' => [['Answers to common questions about admissions, eligibility, fees, scholarships, hostel, transport and placements at GIMT Greater Noida.',
                'Answers to common questions about admissions, eligibility, fees, scholarships, hostel, transport and placements at GIMT Noida.'],
                'Common questions about admissions, eligibility, fees, scholarships and programs at GIMT Noida, answered by the admissions team.'],
            'meta_keywords' => [['GIMT FAQ, admission questions, fees, hostel'], 'GIMT FAQ, GIMT admission questions, fees, scholarships'],
        ]);
        $add('route', '/contact', [
            'meta_description' => [['Call +91 99554 46477, email info@gimt.ac.in or visit our campus in Knowledge Park, Greater Noida. Admissions, support and campus visit enquiries.'],
                'Call +91 90600 31540, email admission@gimtedu.com or visit us at Wave One, Sector 18, Noida. Admissions, support and campus visit enquiries.'],
        ]);
        $add('route', '/admissions-open', [
            'meta_description' => [['Admissions open for BBA, MBA, B.Tech, BCA, MCA and B.Com at GIMT Greater Noida. Get free career counselling and apply now.'],
                'Admissions open for BBA, MBA, B.Tech, BCA, MCA and B.Com at GIMT Noida. Get free career counselling and apply now.'],
        ]);
        $add('page', 'campus-life', [
            'meta_description' => [['Clubs and societies, sports, cultural festivals, hostels and student support — discover life on the GIMT campus beyond the classroom.'],
                'Clubs and societies, cultural festivals and student support — discover student life at GIMT Noida beyond the classroom.'],
        ]);
        $add('page', 'leadership', [
            'meta_description' => [['Meet the Chairman, Director and Academic Dean of GIMT and learn how the Governing Body and Academic Council guide the institute.'],
                'How the Governing Body and the Academic Council guide Global Institute of Management & Technology (GIMT), Noida.'],
        ]);
        $add('page', 'refund-policy', [
            'meta_description' => [['Rules for the refund of admission, tuition, hostel and transport fees when a student withdraws from a program at GIMT Greater Noida.',
                'Rules for the refund of admission, tuition, hostel and transport fees when a student withdraws from a program at GIMT Noida.'],
                'Rules for the refund of admission and tuition fees when a student withdraws from a program at GIMT Noida.'],
        ]);
        return $m;
    }

    /** New WhatsApp-bot knowledge answers (added when missing; the bot fills {placeholders} from Settings). */
    function seo64_wa_kb_rows(): array
    {
        $f = seo64_facts();
        return [
            ['scholarship', 'Which scholarships are available?', 'scholarship, scholarships, fee concession, merit scholarship, scholarship kitni hai, scholarship milegi, छात्रवृत्ति, स्कॉलरशिप',
                $f['scholarships'] . ".\nThe admissions office confirms your eligibility: {phone}",
                'मेरिट स्कॉलरशिप ट्यूशन फीस के 50% तक, साथ ही खेल, सिंगल गर्ल चाइल्ड, आर्थिक रूप से कमज़ोर वर्ग, डिफ़ेंस वार्ड और सिबलिंग स्कॉलरशिप।' . "\nआपकी पात्रता एडमिशन ऑफिस कन्फ़र्म करता है: {phone}"],
            ['admission', 'What is the last date to apply?', 'last date, deadline, last date to apply, admission closing, kab tak apply, last date kya hai, अंतिम तिथि, आखिरी तारीख',
                $f['rolling'] . ". Apply online: {apply_url}",
                'एडमिशन रोलिंग आधार पर हैं — सीटें भरने तक आवेदन खुले हैं। ऑनलाइन आवेदन करें: {apply_url}'],
            ['placement', 'Do you provide placement assistance?', 'placement, placements, placement assistance, job, jobs, recruiters, placement milega, नौकरी, प्लेसमेंट',
                $f['placement'],
                'प्लेसमेंट सहायता: करियर गाइडेंस, CV और इंटरव्यू की तैयारी, और रिक्रूटर्स के पास आवेदन करने में मदद। नौकरी की गारंटी नहीं है।'],
        ];
    }

    /** The 10 standard AI-visibility test queries, run with Claude's web search (WebSearch tool) on 2026-11-17. */
    function seo64_ai_tests(): array
    {
        $collide = 'GIMT is answered as Galgotias Institute of Management & Technology (Greater Noida); gimtedu.com is not found or cited.';
        $fix = 'Publish the /faq hub and a one-sentence "What is GIMT" answer; verify Google + Bing; GBP / directory listings with the exact name and address; always pair GIMT with Noida and the full name.';
        return [
            ['What is Global Institute of Management & Technology in Noida?', 'Galgotias Institute of Management & Technology (Greater Noida); Global Institute of Information Technology (Greater Noida); Global Institute of Technology (Noida)',
                'Only a CareerIndia same-name listing (no address or courses) mentions a Noida institute; most results are about other institutes.', $collide, $fix,
                'Search answer: "very little on this exact name"; a careerindia.com listing describes a GIMT Noida in one generic sentence; the rest is about Galgotias IMT and Global Institute of Information Technology.'],
            ['Is GIMT Noida a good college for management and technology courses?', 'Galgotias Institute of Management & Technology (Greater Noida); Mangalmay; GNIOT IMS; Army Institute of Management & Technology',
                null, $collide . ' Reviews, ratings and MBA fees of Galgotias IMT are given as the answer for "GIMT Noida".', $fix,
                'Answer is entirely about Galgotias IMT, Greater Noida (Careers360 / Collegebatch / Getmyuni reviews, MBA only).'],
            ['Which courses does GIMT Noida offer and what are the fees?', 'Galgotias Institute of Management & Technology (Greater Noida); National Institute of Management & Technology (Greater Noida)',
                null, $collide . ' The answer lists only an MBA with Galgotias fees (about ₹1.16 lakh a year).', $fix . ' Program pages need clear "<program> fees in Noida" answers.',
                'Answer: Galgotias IMT MBA, 180 seats, CMAT / CUET PG, fees from Careers360 and Getmyuni.'],
            ['How do I apply for admission to GIMT Noida?', 'Galgotias Institute of Management & Technology (Greater Noida); GNIOT IMS (GIMS); Army Institute of Management & Technology',
                null, $collide . ' The answer describes AKTU counselling and Galgotias application steps.', $fix . ' Keep the 5 published admission steps + ₹999 fee in one answer on /admissions and /faq.',
                'Answer: Galgotias admission portal steps, CUET PG / CMAT, AKTU counselling.'],
            ['Best colleges for BBA in Noida', 'Amity School of Business; IMS Noida; Symbiosis Centre for Management Studies Noida; Jaypee Business School; IILM; Galgotias University; Sharda University; Bennett University',
                null, null, 'Category query — GIMT absent. Needs an indexable, data-rich BBA page, local listings and third-party education directory profiles.',
                'Answer lists Amity, IMS Noida, Symbiosis (SCMS Noida), Jaypee, IILM and Greater Noida universities; GIMT not mentioned.'],
            ['Best colleges for MBA in Noida', 'Amity University Noida; Jaipuria Institute of Management; IMS Noida; BIMTECH; Galgotias University; IMII Noida; IMCI Noida',
                null, null, 'Category query — GIMT absent. Same actions as the BBA query; get listed on the directories these answers cite (Careers360, Collegedunia, Shiksha).',
                'Answer lists Amity, Jaipuria, IMS Noida, BIMTECH, Galgotias University; GIMT not mentioned.'],
            ['Best colleges for BCA and MCA in Noida', 'Amity Institute of Information Technology; Birla Institute of Technology Noida; Galgotias University; Gautam Buddha University; G L Bajaj; NIET; KCC Institute',
                null, null, 'Category query — GIMT absent. A Computer Applications department page and directory listings are needed.',
                'Answer lists Amity AIIT, BIT Noida, Galgotias University, Gautam Buddha University; GIMT not mentioned.'],
            ['Colleges in Noida offering UG and PG management courses', 'Lloyd Business School; Noida International University; Amity; Metropolitan School of Management; Jaipuria; BIMTECH; Galgotias University; Sharda University',
                null, null, 'Category query — GIMT absent. Management department / level pages (UG, PG) with clear program lists are needed.',
                'Answer lists Lloyd, Noida International University, Amity, Metropolitan School of Management and Greater Noida institutes; GIMT not mentioned.'],
            ['Where is Global Institute of Management & Technology located and how do I contact the admissions office?', 'Same-name institutes: GIMT Krishnanagar (Nadia, West Bengal); GIMT Allahabad (Prayagraj); GIMT Hanuman Junction (Andhra Pradesh); a New Delhi listing using the domain gimtedu.in',
                null, 'The answer lists four other same-name institutes with their own addresses, phones and e-mails; the Sector 18, Noida address, +91 90600 31540 and gimtedu.com do not appear. One listing uses the look-alike domain gimtedu.in.',
                'Name collision: verify GBP / Bing Places, add Organization sameAs, put the full address + "Noida" in titles and the /contact answer; check who owns gimtedu.in.',
                'Answer: "could refer to several different institutes" — Nadia (WB), Allahabad, Hanuman Junction (AP), New Delhi; no Noida entry.'],
            ['GIMT Noida placements and recruiters', 'Galgotias Institute of Management & Technology (Greater Noida); GNIOT IMS; NIMT; AIMT Greater Noida; Harlal Institute',
                null, $collide . ' Galgotias placement figures and recruiter names are presented as "GIMT Noida" placements.', 'Publish the placement-assistance answer on /placement and /faq; never publish numbers without records.',
                'Answer: Galgotias IMT placement figures (packages, offers) and recruiter names from Careers360 / Getmyuni / Collegedunia.'],
        ];
    }

    /** Keyword map (117 rows, owner sheet format) — copy of storage/private/seo/keyword_map.csv. */
    function seo64_keyword_csv(): string
    {
        return <<<'CSV'
keyword,category,intent,volume,difficulty,competition,target_url,priority,current_position,status
"GIMT Noida","Brand / primary","navigational","not measured","not measured","low (acronym shared with other Indian institutes - always pair with Noida)","https://gimtedu.com/","high","unknown","pending"
"Global Institute of Management and Technology Noida","Brand / secondary","navigational","not measured","not measured","low","https://gimtedu.com/","high","unknown","pending"
"Global IMT Noida","Brand / secondary","navigational","not measured","not measured","low","https://gimtedu.com/","medium","unknown","pending"
"management and technology college in Noida","Institute / secondary","commercial","not measured","not measured","high","https://gimtedu.com/","medium","unknown","pending"
"college in Sector 18 Noida","Institute local / long-tail","commercial","not measured","not measured","low","https://gimtedu.com/","medium","unknown","pending"
"about GIMT Noida","About / primary","informational","not measured","not measured","low","https://gimtedu.com/about","medium","unknown","pending"
"Global Institute of Management and Technology history","About / secondary","informational","not measured","not measured","low","https://gimtedu.com/about","low","unknown","pending"
"GIMT vision and mission","Vision & Mission / primary","informational","not measured","not measured","low","https://gimtedu.com/vision-mission","low","unknown","pending"
"GIMT Noida director","Leadership / primary (blocked: page shows placeholder names)","navigational","not measured","not measured","low","https://gimtedu.com/leadership","low","unknown","pending"
"GIMT Noida faculty","Faculty / primary (blocked: directory empty)","navigational","not measured","not measured","low","https://gimtedu.com/faculty","low","unknown","pending"
"courses offered at GIMT Noida","Programs hub / primary","informational","not measured","not measured","low","https://gimtedu.com/programs","high","unknown","pending"
"UG and PG courses in Noida","Programs hub / secondary","commercial","not measured","not measured","high","https://gimtedu.com/programs","medium","unknown","pending"
"GIMT courses list","Programs hub / secondary","informational","not measured","not measured","low","https://gimtedu.com/programs","medium","unknown","pending"
"UG courses after 12th in Noida","UG (section missing - new page needed) / primary","commercial","not measured","not measured","high","PROPOSED:https://gimtedu.com/programs/undergraduate","medium","unknown","pending"
"PG courses in Noida","PG (section missing - new page needed) / primary","commercial","not measured","not measured","high","PROPOSED:https://gimtedu.com/programs/postgraduate","medium","unknown","pending"
"management courses in Noida","Dept Management Studies (missing - new page needed) / primary","commercial","not measured","not measured","high","PROPOSED:https://gimtedu.com/departments/management-studies","medium","unknown","pending"
"B.Tech colleges in Noida","Dept CSE (missing - new page needed) / primary","commercial","not measured","not measured","high","PROPOSED:https://gimtedu.com/departments/computer-science-engineering","low","unknown","pending"
"BCA MCA college in Noida","Dept Computer Applications (missing - new page needed) / primary","commercial","not measured","not measured","medium","PROPOSED:https://gimtedu.com/departments/computer-applications","low","unknown","pending"
"commerce college in Noida","Dept Commerce (missing - new page needed) / primary","commercial","not measured","not measured","medium","PROPOSED:https://gimtedu.com/departments/commerce","low","unknown","pending"
"college admission 2026-27 Noida","Admissions / primary","transactional","not measured","not measured","high","https://gimtedu.com/admissions","high","unknown","pending"
"GIMT admission 2026","Admissions / secondary","navigational","not measured","not measured","low","https://gimtedu.com/admissions","high","unknown","pending"
"admission 2027-28 Noida","Admissions next cycle / secondary (refresh copy after 30 Nov 2026)","transactional","not measured","not measured","high","https://gimtedu.com/admissions","high","unknown","pending"
"GIMT admission process","Admission Process / secondary (#process)","informational","not measured","not measured","low","https://gimtedu.com/admissions","high","unknown","pending"
"GIMT fee structure 2026-27","Fees & Scholarships / secondary (#fees)","commercial","not measured","not measured","low","https://gimtedu.com/admissions","high","unknown","pending"
"GIMT scholarship","Fees & Scholarships / secondary (#scholarships)","informational","not measured","not measured","low","https://gimtedu.com/admissions","medium","unknown","pending"
"documents required for college admission","Admissions / long-tail (#documents)","informational","not measured","not measured","medium","https://gimtedu.com/admissions","medium","unknown","pending"
"GIMT apply online","Apply / primary","transactional","not measured","not measured","low","https://gimtedu.com/apply","high","unknown","pending"
"GIMT application form 2026","Apply / secondary","transactional","not measured","not measured","low","https://gimtedu.com/apply","medium","unknown","pending"
"BBA college in Noida","Program BBA / primary","commercial","not measured","not measured","high","https://gimtedu.com/programs/bachelor-of-business-administration","high","unknown","pending"
"BBA admission 2026 Noida","Program BBA / secondary","transactional","not measured","not measured","high","https://gimtedu.com/programs/bachelor-of-business-administration","high","unknown","pending"
"BBA fees in Noida","Program BBA / secondary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/bachelor-of-business-administration","high","unknown","pending"
"BBA Digital Business Noida","Program BBA / long-tail","commercial","not measured","not measured","low","https://gimtedu.com/programs/bachelor-of-business-administration","medium","unknown","pending"
"BBA eligibility 12th percentage","Program BBA / long-tail","informational","not measured","not measured","medium","https://gimtedu.com/programs/bachelor-of-business-administration","medium","unknown","pending"
"MBA college in Noida","Program MBA / primary","commercial","not measured","not measured","high","https://gimtedu.com/programs/master-of-business-administration","high","unknown","pending"
"MBA admission 2026 Noida","Program MBA / secondary","transactional","not measured","not measured","high","https://gimtedu.com/programs/master-of-business-administration","high","unknown","pending"
"MBA fees in Noida","Program MBA / secondary","commercial","not measured","not measured","high","https://gimtedu.com/programs/master-of-business-administration","high","unknown","pending"
"MBA Business Analytics in Noida","Program MBA / secondary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/master-of-business-administration","medium","unknown","pending"
"MBA admission with MAT or CMAT score Noida","Program MBA / long-tail","transactional","not measured","not measured","medium","https://gimtedu.com/programs/master-of-business-administration","medium","unknown","pending"
"MBA Finance HR Marketing specialisation Noida","Program MBA / long-tail","commercial","not measured","not measured","medium","https://gimtedu.com/programs/master-of-business-administration","low","unknown","pending"
"B.Tech CSE college in Noida","Program B.Tech CSE / primary","commercial","not measured","not measured","high","https://gimtedu.com/programs/b-tech-in-computer-science-engineering","high","unknown","pending"
"B.Tech admission 2026 Noida","Program B.Tech CSE / secondary","transactional","not measured","not measured","high","https://gimtedu.com/programs/b-tech-in-computer-science-engineering","high","unknown","pending"
"B.Tech CSE fees Noida","Program B.Tech CSE / secondary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/b-tech-in-computer-science-engineering","medium","unknown","pending"
"B.Tech CSE cyber security specialisation Noida","Program B.Tech CSE / long-tail","commercial","not measured","not measured","medium","https://gimtedu.com/programs/b-tech-in-computer-science-engineering","medium","unknown","pending"
"B.Tech cloud computing specialisation Noida","Program B.Tech CSE / long-tail","commercial","not measured","not measured","low","https://gimtedu.com/programs/b-tech-in-computer-science-engineering","medium","unknown","pending"
"B.Tech AI and Data Science college in Noida","Program B.Tech AI&DS / primary","commercial","not measured","not measured","high","https://gimtedu.com/programs/b-tech-in-artificial-intelligence-data-science","high","unknown","pending"
"B.Tech artificial intelligence admission 2026","Program B.Tech AI&DS / secondary","transactional","not measured","not measured","medium","https://gimtedu.com/programs/b-tech-in-artificial-intelligence-data-science","medium","unknown","pending"
"B.Tech AI and DS fees Noida","Program B.Tech AI&DS / long-tail","commercial","not measured","not measured","medium","https://gimtedu.com/programs/b-tech-in-artificial-intelligence-data-science","medium","unknown","pending"
"BCA college in Noida","Program BCA / primary","commercial","not measured","not measured","high","https://gimtedu.com/programs/bachelor-of-computer-applications","high","unknown","pending"
"BCA admission 2026 Noida","Program BCA / secondary","transactional","not measured","not measured","high","https://gimtedu.com/programs/bachelor-of-computer-applications","high","unknown","pending"
"BCA fees in Noida","Program BCA / secondary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/bachelor-of-computer-applications","high","unknown","pending"
"BCA course after 12th","Program BCA / long-tail","informational","not measured","not measured","medium","https://gimtedu.com/programs/bachelor-of-computer-applications","medium","unknown","pending"
"MCA college in Noida","Program MCA / primary","commercial","not measured","not measured","high","https://gimtedu.com/programs/master-of-computer-applications","high","unknown","pending"
"MCA admission 2026 Noida","Program MCA / secondary","transactional","not measured","not measured","medium","https://gimtedu.com/programs/master-of-computer-applications","medium","unknown","pending"
"MCA full stack development Noida","Program MCA / secondary","commercial","not measured","not measured","low","https://gimtedu.com/programs/master-of-computer-applications","medium","unknown","pending"
"career after MCA","Program MCA / long-tail","informational","not measured","not measured","high","https://gimtedu.com/programs/master-of-computer-applications","low","unknown","pending"
"B.Com Honours college in Noida","Program B.Com (Hons) / primary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/b-com-honours","high","unknown","pending"
"B.Com admission 2026 Noida","Program B.Com (Hons) / secondary","transactional","not measured","not measured","medium","https://gimtedu.com/programs/b-com-honours","medium","unknown","pending"
"B.Com accounting and taxation Noida","Program B.Com (Hons) / long-tail","commercial","not measured","not measured","low","https://gimtedu.com/programs/b-com-honours","medium","unknown","pending"
"M.Com college in Noida","Program M.Com / primary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/master-of-commerce","medium","unknown","pending"
"M.Com admission 2026 Noida","Program M.Com / secondary","transactional","not measured","not measured","low","https://gimtedu.com/programs/master-of-commerce","medium","unknown","pending"
"B.Sc Data Science college in Noida","Program B.Sc Data Science / primary (also covers Sciences dept)","commercial","not measured","not measured","medium","https://gimtedu.com/programs/b-sc-in-data-science","high","unknown","pending"
"B.Sc Data Science after 12th","Program B.Sc Data Science / secondary","informational","not measured","not measured","medium","https://gimtedu.com/programs/b-sc-in-data-science","medium","unknown","pending"
"B.Sc Data Science fees Noida","Program B.Sc Data Science / long-tail","commercial","not measured","not measured","low","https://gimtedu.com/programs/b-sc-in-data-science","medium","unknown","pending"
"DCA course in Noida","Program DCA / primary (also covers Diploma section)","commercial","not measured","not measured","medium","https://gimtedu.com/programs/diploma-in-computer-applications","medium","unknown","pending"
"diploma courses after 12th in Noida","Program DCA / secondary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/diploma-in-computer-applications","medium","unknown","pending"
"one year computer diploma Noida","Program DCA / long-tail","commercial","not measured","not measured","low","https://gimtedu.com/programs/diploma-in-computer-applications","low","unknown","pending"
"certificate courses in Noida","Certificates hub / primary","commercial","not measured","not measured","medium","https://gimtedu.com/certifications","high","unknown","pending"
"short term courses in Noida","Certificates hub / secondary","commercial","not measured","not measured","medium","https://gimtedu.com/certifications","high","unknown","pending"
"skill development courses in Noida","Certificates hub / secondary","commercial","not measured","not measured","medium","https://gimtedu.com/certifications","medium","unknown","pending"
"job oriented courses after 12th in Noida","Certificates hub / long-tail","commercial","not measured","not measured","medium","https://gimtedu.com/certifications","medium","unknown","pending"
"digital marketing course in Noida","Certificate Digital Marketing / primary","commercial","not measured","not measured","high","https://gimtedu.com/programs/certificate-in-digital-marketing","high","unknown","pending"
"digital marketing course fees Noida","Certificate Digital Marketing / secondary","commercial","not measured","not measured","high","https://gimtedu.com/programs/certificate-in-digital-marketing","medium","unknown","pending"
"data analytics course in Noida","Certificate Data Analytics / primary","commercial","not measured","not measured","high","https://gimtedu.com/programs/certificate-in-data-analytics","high","unknown","pending"
"Power BI SQL Python course Noida","Certificate Data Analytics / secondary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/certificate-in-data-analytics","medium","unknown","pending"
"BFSI course in Noida","Certificate BFSI & Soft Skills / primary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/bfsi-soft-skills-professional-training","high","unknown","pending"
"banking course for freshers with placement assistance Noida","Certificate BFSI & Soft Skills / secondary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/bfsi-soft-skills-professional-training","high","unknown","pending"
"90 days banking training course","Certificate BFSI & Soft Skills / long-tail","commercial","not measured","not measured","low","https://gimtedu.com/programs/bfsi-soft-skills-professional-training","medium","unknown","pending"
"advanced Excel course in Noida","Certificate Advanced Excel / primary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/certificate-in-advanced-excel","medium","unknown","pending"
"MS Office course in Noida","Certificate MS Office / primary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/certificate-in-ms-office","medium","unknown","pending"
"basic computer course for beginners Noida","Certificate Computer Fundamentals / primary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/certificate-in-computer-fundamentals","medium","unknown","pending"
"3 month computer course in Noida","Certificate Computer Applications / primary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/certificate-in-computer-applications","medium","unknown","pending"
"data entry course in Noida","Certificate Data Entry & Office Automation / primary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/certificate-in-data-entry-office-automation","low","unknown","pending"
"spoken English course in Noida","Certificate Spoken English / primary","commercial","not measured","not measured","high","https://gimtedu.com/programs/certificate-in-spoken-english","high","unknown","pending"
"communication skills course in Noida","Certificate Communication Skills / primary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/certificate-in-communication-skills","medium","unknown","pending"
"personality development course in Noida","Certificate Personality Development / primary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/certificate-in-personality-development","medium","unknown","pending"
"soft skills training in Noida","Certificate Soft Skills Training / primary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/certificate-in-soft-skills-training","medium","unknown","pending"
"interview preparation course Noida","Certificate Interview Preparation / primary","commercial","not measured","not measured","low","https://gimtedu.com/programs/certificate-in-interview-preparation","medium","unknown","pending"
"job readiness course for freshers","Certificate Job Readiness / primary","commercial","not measured","not measured","low","https://gimtedu.com/programs/certificate-in-job-readiness","low","unknown","pending"
"workplace skills training course","Certificate Workplace Skills / primary","commercial","not measured","not measured","low","https://gimtedu.com/programs/certificate-in-workplace-skills","low","unknown","pending"
"leadership and team management course Noida","Certificate Leadership & Team Management / primary","commercial","not measured","not measured","low","https://gimtedu.com/programs/certificate-in-leadership-team-management","low","unknown","pending"
"generative AI course in Noida","Certificate Generative AI Fundamentals / primary","commercial","not measured","not measured","high","https://gimtedu.com/programs/certificate-in-generative-ai-fundamentals","high","unknown","pending"
"AI tools course in Noida","Certificate AI Tools & Productivity / primary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/certificate-in-ai-tools-productivity","medium","unknown","pending"
"AI course for beginners Noida","Certificate AI Awareness / primary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/certificate-in-artificial-intelligence-ai-awareness","medium","unknown","pending"
"competitive exam preparation course Noida","Certificate Competitive Exam Preparation / primary","commercial","not measured","not measured","high","https://gimtedu.com/programs/certificate-in-competitive-exam-preparation","medium","unknown","pending"
"career development course","Certificate Career Development / primary","commercial","not measured","not measured","low","https://gimtedu.com/programs/certificate-in-career-development","low","unknown","pending"
"professional development course for working professionals Noida","Certificate Professional Development / primary","commercial","not measured","not measured","low","https://gimtedu.com/programs/certificate-in-professional-development","low","unknown","pending"
"HR certificate course in Noida","Certificate Human Resource Management / primary","commercial","not measured","not measured","medium","https://gimtedu.com/programs/certificate-in-human-resource-management","medium","unknown","pending"
"marketing management certificate course Noida","Certificate Marketing Management / primary","commercial","not measured","not measured","low","https://gimtedu.com/programs/certificate-in-marketing-management","low","unknown","pending"
"business management certificate course Noida","Certificate Business Management / primary","commercial","not measured","not measured","low","https://gimtedu.com/programs/certificate-in-business-management","low","unknown","pending"
"entrepreneurship course in Noida","Certificate Entrepreneurship & Business Development / primary","commercial","not measured","not measured","low","https://gimtedu.com/programs/certificate-in-entrepreneurship-business-development","low","unknown","pending"
"office management course in Noida","Certificate Office Management / primary","commercial","not measured","not measured","low","https://gimtedu.com/programs/certificate-in-office-management","low","unknown","pending"
"office administration course Noida","Certificate Office & Business Administration / primary","commercial","not measured","not measured","low","https://gimtedu.com/programs/certificate-in-office-business-administration","low","unknown","pending"
"GIMT Noida placements","Placements / primary","navigational","not measured","not measured","low","https://gimtedu.com/placement","medium","unknown","pending"
"GIMT Noida campus","Campus & Facilities / primary","navigational","not measured","not measured","low","https://gimtedu.com/campus","medium","unknown","pending"
"GIMT student clubs and activities","Campus Life / primary","informational","not measured","not measured","low","https://gimtedu.com/campus-life","low","unknown","pending"
"GIMT academics and departments","Academics / primary","informational","not measured","not measured","low","https://gimtedu.com/academics","low","unknown","pending"
"GIMT academic calendar 2026-27","Academic Calendar / secondary (#calendar - content not published)","informational","not measured","not measured","low","https://gimtedu.com/academics","medium","unknown","pending"
"GIMT research and innovation","Research & Innovation / primary","informational","not measured","not measured","low","https://gimtedu.com/research","low","unknown","pending"
"GIMT Noida news","News & Events / primary (blog empty)","navigational","not measured","not measured","low","https://gimtedu.com/blog","low","unknown","pending"
"GIMT alumni","Alumni / primary","navigational","not measured","not measured","low","https://gimtedu.com/alumni","low","unknown","pending"
"GIMT Noida contact number","Contact / primary","navigational","not measured","not measured","low","https://gimtedu.com/contact","high","unknown","pending"
"GIMT Noida address","Contact / secondary","navigational","not measured","not measured","low","https://gimtedu.com/contact","high","unknown","pending"
"GIMT admission FAQ","FAQs / primary (page currently empty)","informational","not measured","not measured","low","https://gimtedu.com/faq","high","unknown","pending"
"GIMT certificate verification","Verify Certificate / primary","navigational","not measured","not measured","low","https://gimtedu.com/verify-certificate","low","unknown","pending"
"GIMT refund policy","Refund Policy / primary (consolidate the two refund pages)","informational","not measured","not measured","low","https://gimtedu.com/refund-and-cancellation-policy","low","unknown","pending"
"GIMT privacy policy","Privacy Policy / primary","navigational","not measured","not measured","low","https://gimtedu.com/privacy-policy","low","unknown","pending"
"GIMT terms and conditions","Terms / primary","navigational","not measured","not measured","low","https://gimtedu.com/terms-and-conditions","low","unknown","pending"
CSV;
    }

    /** Parsed + normalised keyword rows (seo_keyword_normalize from app/services/seo_presence.php). */
    function seo64_keywords(): array
    {
        require_once APP_ROOT . '/app/services/seo_presence.php';
        $lines = preg_split('/\r?\n/', trim(seo64_keyword_csv()));
        $head = str_getcsv((string) array_shift($lines), ',', '"', '');
        $out = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $r = array_combine($head, array_pad(str_getcsv($line, ',', '"', ''), count($head), ''));
            [$clean, $errors] = seo_keyword_normalize($r + ['monthly_volume' => $r['volume'] ?? '', 'volume_source' => 'not_measured']);
            if (!$errors) {
                $out[] = $clean;
            }
        }
        return $out;
    }

    /**
     * Every statement, in order: ['label', 'sql' (with ? placeholders), 'params', 'guard' => [table, column|null]].
     * The seeder runs them with db_exec(); the live SQL builder inlines the params.
     */
    function seo64_statements(): array
    {
        require_once APP_ROOT . '/app/services/cms.php';
        $f = seo64_facts();
        $st = [];
        $q = function (string $label, string $sql, array $params = [], ?array $guard = null) use (&$st) {
            $st[] = ['label' => $label, 'sql' => $sql, 'params' => $params, 'guard' => $guard];
        };

        // 1. share image
        $q('og_image: og-gimt.jpg (404) → share card', "UPDATE seo_settings SET og_image = ? WHERE og_image IN ('assets/images/site/og-gimt.jpg', 'og-gimt.jpg', '/assets/images/site/og-gimt.jpg')", [$f['share_image']]);
        $q('og_image: /campus lawn photo → share card', "UPDATE seo_settings SET og_image = ? WHERE entity_type = 'route' AND route = '/campus' AND og_image = 'assets/images/site/students-lawn.jpg'", [$f['share_image']]);
        $q('og_image: 2.6:1 banner photos (cropped badly by social cards) → share card', "UPDATE seo_settings SET og_image = ? WHERE og_image IN ('assets/images/site/graduation.jpg', 'assets/images/site/hero-campus-students.jpg')", [$f['share_image']]);

        // 2a. settings values
        $q('settings: admissions_deadline → empty (rolling admissions)', "UPDATE settings SET value = '' WHERE `key` = 'admissions_deadline' AND value <> ''");
        $q('settings: established_year → empty (no founding year)', "UPDATE settings SET value = '' WHERE `key` = 'established_year' AND value <> ''");
        // seeded placeholders (exact value only — an address the institute entered itself is never touched)
        $q('settings: website www.gimt.ac.in (seeded placeholder) → https://gimtedu.com', "UPDATE settings SET value = ? WHERE `key` = 'website' AND value = ?", ['https://gimtedu.com', 'www.gimt.ac.in']);
        $q('settings: phone_alt +91 120 4567890 (seeded placeholder) → empty', "UPDATE settings SET value = '' WHERE `key` = 'phone_alt' AND value = ?", ['+91 120 4567890']);
        // 2b. exact text replacements
        foreach (seo64_text_changes() as $c) {
            $q("{$c['t']}.{$c['col']} ({$c['where']})", "UPDATE `{$c['t']}` SET `{$c['col']}` = REPLACE(`{$c['col']}`, ?, ?) WHERE {$c['scope']} AND INSTR(`{$c['col']}`, ?) > 0",
                [$c['old'], $c['new'], $c['old']], [$c['t'], $c['col']]);
        }
        // 2c. FAQs whose whole subject is removed (hostel / college buses / recruiter claims): switched off, not deleted
        foreach ([
            ['Is hostel accommodation available?', 'Yes, separate hostels for boys and girls are available inside the campus with twin and triple-sharing rooms, mess, Wi-Fi, laundry and 24x7 security.'],
            ['How are hostel rooms allotted?', 'Rooms are allotted on a first-come, first-served basis after the hostel fee is paid. First-year students are usually allotted rooms in the same block.'],
            ['Does the college provide transport?', 'Yes, college buses cover major routes in Greater Noida, Noida, Ghaziabad and Delhi. Transport fees depend on the route and pickup point.'],
            ['What are the hostel in-time rules?', 'The hostel in-time is 9:30 pm. Late entry or night-out requires prior permission from the warden and parents.'],
            ['Which companies visit the campus?', 'Recruiters from IT services, consulting, banking and financial services, e-commerce and manufacturing visit the campus. The placement page lists recent recruiters.'],
        ] as [$question, $answer]) {
            $q('faqs: switch off "' . $question . '"', "UPDATE faqs SET status = 'inactive' WHERE question = ? AND answer = ? AND status = 'active'", [$question, $answer], ['faqs', null]);
        }
        // 2d. department "Est. <year>" labels
        $q('departments: established_year → NULL (no founding years)', 'UPDATE departments SET established_year = NULL WHERE established_year IS NOT NULL');
        // 2e. one credits figure: programs.total_credits = the curriculum on the program page (sum of subject credits)
        $q('programs: total_credits = sum of the curriculum subject credits (degree + diploma)',
            "UPDATE programs p JOIN (SELECT program_id, ROUND(SUM(credits)) s FROM subjects WHERE status = 'active' GROUP BY program_id) x ON x.program_id = p.id
             SET p.total_credits = x.s WHERE LOWER(p.level) <> 'certificate' AND x.s > 0 AND p.total_credits <> x.s");
        // 2f. WhatsApp-bot knowledge: new answers with the owner's wording (added when missing)
        foreach (seo64_wa_kb_rows() as [$cat, $question, $kw, $en, $hi]) {
            $q('wa_bot_knowledge: add "' . $question . '"', "INSERT INTO wa_bot_knowledge (category, question, keywords, answer_en, answer_hi, status, verified_at, created_at)
                SELECT ?, ?, ?, ?, ?, 'active', NOW(), NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM wa_bot_knowledge WHERE question = ?)",
                [$cat, $question, $kw, $en, $hi, $question], ['wa_bot_knowledge', null]);
        }

        // 3. meta improvements (only the untouched seeded text)
        foreach (seo64_meta_changes() as $m) {
            [$scopeSql, $scopeParams] = $m['kind'] === 'route' ? ["entity_type = 'route' AND route = ?", [$m['ref']]]
                : ["entity_type = 'page' AND entity_id = (SELECT id FROM pages WHERE slug = ? LIMIT 1)", [$m['ref']]];
            foreach ($m['fields'] as $field => [$olds, $new]) {
                $cols = [$field];
                if ($field === 'meta_title') {
                    $cols[] = 'og_title';
                } elseif ($field === 'meta_description') {
                    $cols[] = 'og_description';
                }
                foreach ($cols as $col) {
                    $in = implode(', ', array_fill(0, count($olds), '?'));
                    $q("seo_settings.$col {$m['kind']} {$m['ref']}", "UPDATE seo_settings SET `$col` = ? WHERE $scopeSql AND `$col` IN ($in)", array_merge([$new], $scopeParams, $olds));
                }
            }
        }

        // 4. focus + secondary keywords per URL (only where empty) — rows are created with the defaults the sitemap already uses
        $routes = cms_public_routes();
        $byUrl = [];
        foreach (seo64_keywords() as $k) {
            if ($k['status'] === 'planned' || !$k['target_url']) {
                continue; // proposed pages do not exist yet
            }
            $path = seo_keyword_url_key($k['target_url']);
            $byUrl[$path][$k['is_primary'] ? 'primary' : 'secondary'][] = $k['keyword'];
        }
        foreach ($byUrl as $path => $kw) {
            $focus = $kw['primary'][0] ?? null;
            if ($focus === null) {
                continue;
            }
            $secondary = mb_substr(implode(', ', array_merge(array_slice($kw['primary'], 1), $kw['secondary'] ?? [])), 0, 500);
            $set = "UPDATE seo_settings SET focus_keyword = ?, secondary_keywords = ? WHERE %s AND (focus_keyword IS NULL OR focus_keyword = '')";
            if (isset($routes[$path])) {
                $q("seo_settings focus row: route $path", "INSERT IGNORE INTO seo_settings (entity_type, entity_id, route, robots, include_in_sitemap, sitemap_priority, sitemap_changefreq, focus_keyword, secondary_keywords)
                    VALUES ('route', NULL, ?, 'index,follow', 1, ?, ?, ?, ?)", [$path, $routes[$path]['priority'], $routes[$path]['changefreq'], $focus, $secondary ?: null], ['seo_settings', 'focus_keyword']);
                $q("seo_settings focus: route $path", sprintf($set, "entity_type = 'route' AND route = ?"), [$focus, $secondary ?: null, $path], ['seo_settings', 'focus_keyword']);
            } elseif (preg_match('#^/programs/([a-z0-9-]+)$#', $path, $mm)) {
                $q("seo_settings focus row: program {$mm[1]}", "INSERT IGNORE INTO seo_settings (entity_type, entity_id, robots, include_in_sitemap, sitemap_priority, sitemap_changefreq, focus_keyword, secondary_keywords)
                    SELECT 'program', id, 'index,follow', 1, 0.8, 'monthly', ?, ? FROM programs WHERE slug = ?", [$focus, $secondary ?: null, $mm[1]], ['seo_settings', 'focus_keyword']);
                $q("seo_settings focus: program {$mm[1]}", sprintf($set, "entity_type = 'program' AND entity_id = (SELECT id FROM programs WHERE slug = ? LIMIT 1)"), [$focus, $secondary ?: null, $mm[1]], ['seo_settings', 'focus_keyword']);
            } elseif (preg_match('#^/([a-z0-9-]+)$#', $path, $mm)) {
                $q("seo_settings focus row: page {$mm[1]}", "INSERT IGNORE INTO seo_settings (entity_type, entity_id, robots, include_in_sitemap, sitemap_priority, sitemap_changefreq, focus_keyword, secondary_keywords)
                    SELECT 'page', id, 'index,follow', 1, 0.6, 'monthly', ?, ? FROM pages WHERE slug = ?", [$focus, $secondary ?: null, $mm[1]], ['seo_settings', 'focus_keyword']);
                $q("seo_settings focus: page {$mm[1]}", sprintf($set, "entity_type = 'page' AND entity_id = (SELECT id FROM pages WHERE slug = ? LIMIT 1)"), [$focus, $secondary ?: null, $mm[1]], ['seo_settings', 'focus_keyword']);
            }
        }

        // 5. keyword database (INSERT IGNORE on the keyword hash)
        $cols = ['keyword', 'keyword_hash', 'category', 'intent', 'cluster', 'is_primary', 'priority', 'target_url', 'monthly_volume', 'volume_source', 'difficulty', 'competition', 'current_position', 'status', 'notes'];
        foreach (array_chunk(seo64_keywords(), 40) as $i => $chunk) {
            $rows = [];
            $params = [];
            foreach ($chunk as $k) {
                $rows[] = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
                foreach ($cols as $c) {
                    $params[] = $k[$c] ?? null;
                }
            }
            $q('seo_keywords: keyword map part ' . ($i + 1), 'INSERT IGNORE INTO seo_keywords (' . implode(', ', $cols) . ') VALUES ' . implode(",\n  ", $rows), $params, ['seo_keywords', null]);
        }

        // 6. AI visibility baseline (Claude web search, 2026-11-17), once per query + engine + date
        foreach (seo64_ai_tests() as [$query, $competitors, $accuracyNote, $incorrect, $action, $excerpt]) {
            $q('seo_ai_tests: ' . $query, "INSERT INTO seo_ai_tests (engine, query_text, tested_on, mentioned, cited, cited_url, accuracy, competitors, incorrect_info, corrective_action, answer_excerpt)
                SELECT 'claude', ?, '2026-11-17', 0, 0, NULL, 'not_applicable', ?, ?, ?, ? FROM DUAL
                WHERE NOT EXISTS (SELECT 1 FROM seo_ai_tests WHERE engine = 'claude' AND tested_on = '2026-11-17' AND query_text = ?)",
                [$query, mb_substr($competitors, 0, 500), $incorrect !== null || $accuracyNote !== null ? mb_substr(trim(($incorrect ?? '') . ' ' . ($accuracyNote ?? '')), 0, 1000) : null,
                    mb_substr($action, 0, 500), mb_substr('Claude web search (WebSearch tool). ' . $excerpt, 0, 1000), $query], ['seo_ai_tests', null]);
        }
        return $st;
    }
}

return function (array $opts): void {
    $exists = function (array $g): bool {
        [$t, $c] = $g + [null, null];
        $sql = $c === null ? 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
            : 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?';
        return (int) db_value($sql, $c === null ? [$t] : [$t, $c]) > 0;
    };
    $done = 0;
    $skipped = 0;
    $changed = 0;
    foreach (seo64_statements() as $s) {
        if ($s['guard'] && !$exists($s['guard'])) {
            $skipped++;
            continue;
        }
        $changed += (int) db_exec($s['sql'], $s['params']);
        $done++;
    }
    foreach (['chatbot_kb_flush'] as $fn) {
        if (!function_exists($fn) && is_file(APP_ROOT . '/app/services/chatbot/knowledge.php')) {
            @require_once APP_ROOT . '/app/services/chatbot/knowledge.php';
        }
        if (function_exists($fn)) {
            $fn();
        }
    }
    foreach (glob(STORAGE_PATH . '/cache/seo/*') ?: [] as $file) {
        @unlink($file); // sitemap cache
    }
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, sprintf("    %d statements run (%d skipped: table/column missing), %d rows changed\n", $done, $skipped, $changed));
    }
};
