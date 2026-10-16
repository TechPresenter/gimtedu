<?php
/**
 * SEO content & metadata (work package WP-8): data-only, guarded migrations 870-879.
 *
 * Applies the keyword mapping (scratchpad v3/seo/keywords/mapping.md, section 11) to the database, honestly and
 * without overwriting anything an administrator has edited:
 *   870  page titles and descriptions: meta_title is filled only while it is empty (or, for the W2 category pages,
 *        while it is still the seeded value), meta_description is replaced only while it is byte-for-byte the
 *        seeded text (database.sql / earlier migrations). Every new value is checked: title <= 60 characters,
 *        description 70-155 characters.
 *   871  home meta_title (only when the WP-1 SEO core, which prints a home meta_title verbatim, is installed)
 *   872  cannibalisation copy fixes C1-C8 (cross-links on feature cards, H1 renames) - only on sections whose
 *        content still has the seeded MD5 (same technique as migration_100) and page titles still seeded
 *   873  help text on /verify-letter/ (and /application-status/ when it has no help sections)
 *   874  footer "Workshops" link: /events/?type=workshop (noindex filter) -> /programs/, only while seeded
 *   875  "Our offices" block (corporate office address from Settings) on Contact and NGO Details
 *   876  /partner-with-us/ "Become a Project Partner" page, its footer link and a link from Contact
 *   877  focus + secondary keywords (English only, C11) into the WP-1 seo_meta table, only into empty fields
 *
 * Nothing here is ever deleted. Each migration is idempotent: run twice, the second run changes nothing.
 * New section type: office_addresses (renders the addresses saved in Settings at request time, so the page
 * never shows a stale copy and never shows an address that is not entered).
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

// =====================================================================
// Section type: office addresses (Settings > contact address, corporate office, optional registered office)
// =====================================================================

/** Addresses entered in Settings, as [[label, address], ...]. The registered office is shown only once a value exists. */
function seo_content_office_list(bool $hideMainOffice = false): array
{
    $list = [];
    $rows = [
        ['contact_address', 'Office'],
        ['corporate_office_address', 'Corporate office'],
        ['registered_office_address', 'Registered office'],   // setting not created here (the owner adds it once confirmed)
    ];
    foreach ($rows as [$key, $label]) {
        if ($hideMainOffice && $key === 'contact_address') {
            continue;
        }
        $value = trim((string) setting($key));
        if ($value !== '') {
            $list[] = [$label, $value];
        }
    }
    return $list;
}

function section_schema_office_addresses(): array
{
    return [
        'label' => 'Office addresses (from Settings)',
        'fields' => ['pill', 'title', 'intro'],
        'settings' => ['hide_main_office' => ['checkbox', 'Hide the main office address (already shown on this page)']],
        'help' => 'Shows the addresses saved in Settings: the contact address, the corporate office (Admin > Authorization letters) and a registered office when one is entered. Empty addresses are not shown.',
    ];
}

function section_office_addresses(array $s, array $items, array $set, array $page): string
{
    $list = seo_content_office_list(!empty($set['hide_main_office']));
    if (!$list) {
        return '';
    }
    $html = '<div class="contact-cards">';
    foreach ($list as $i => [$label, $value]) {
        $html .= '<div class="info-item contact-card reveal" style="--d:' . (int) $i . '"><span class="icon-circle' . ($i % 2 ? ' gold' : '') . '">' . icon('map-pin') . '</span>'
            . '<div><h3>' . e($label) . '</h3><p>' . e($value) . '</p></div></div>';
    }
    return $html . '</div>';
}

// =====================================================================
// Data: seeded values (exact text from database.sql + migrations up to 7xx) and the new values
// =====================================================================

/** slug => [seeded page title, seeded meta_title (null = none), seeded meta_description]. Generated from a fresh install. */
function seo_content_seeded_pages(): array
{
    return [
        'home' => ['Home', null, 'Prerit Welfare Foundation, New Delhi: empowering underprivileged communities through education, healthcare, women empowerment, environment and skills.'],
        'about' => ['About Us', null, 'About Prerit Welfare Foundation, a New Delhi nonprofit working for education, healthcare, women empowerment, environment and skill development.'],
        'our-mission-vision' => ['Mission & Vision', null, 'Mission, vision, values and commitments of Prerit Welfare Foundation - building a fair and inclusive society where everyone lives with dignity.'],
        'achievements' => ['Achievements', null, 'Verified milestones, completed campaigns, events and certificates of Prerit Welfare Foundation, published as our work is documented.'],
        'ngo-details' => ['NGO Details', null, 'Registration, legal and contact details of Prerit Welfare Foundation, New Delhi - shown here as they are verified and entered by the foundation.'],
        'certifications' => ['Certificates', null, 'Registration certificates and recognitions of Prerit Welfare Foundation.'],
        'management-body' => ['Management Body', null, 'The management body of Prerit Welfare Foundation, New Delhi: the people responsible for the governance and direction of the foundation.'],
        'volunteers' => ['Our Team', null, 'Meet the team and volunteers of Prerit Welfare Foundation who carry out our education, health, women empowerment, environment and relief work.'],
        'programs' => ['Our Programmes', null, 'Workshops, seminars, wellness and cultural programmes, hackathons and our core programmes in education, health, women and youth empowerment, environment and relief.'],
        'programs/education-literacy' => ['Education & Literacy', null, 'Education & Literacy at Prerit Welfare Foundation: literacy drives, community learning circles, study material and school support for children and adults.'],
        'programs/healthcare' => ['Healthcare', null, 'Healthcare at Prerit Welfare Foundation: health camps, screenings, awareness on prevention, hygiene and nutrition, and referral support for families.'],
        'programs/women-empowerment' => ['Women Empowerment', null, 'Women Empowerment programme of Prerit Welfare Foundation: skill training, Self-Help Group support, rights and financial literacy awareness for women.'],
        'programs/youth-empowerment' => ['Youth Empowerment', null, 'Youth Empowerment at Prerit Welfare Foundation: career guidance, mentoring, leadership, life and digital skills, and community service for youth.'],
        'programs/rural-development' => ['Rural Development', null, 'Rural Development at Prerit Welfare Foundation: community awareness drives, livelihood activities and awareness of welfare schemes in villages.'],
        'programs/environment-protection' => ['Environment Protection', null, 'Environment Protection programme of Prerit Welfare Foundation: tree plantation drives, waste management, cleanliness drives and sustainable living awareness.'],
        'skill-development' => ['Skill Development & Training', null, 'Skill development and training by Prerit Welfare Foundation - vocational and digital training, career guidance and livelihood support.'],
        'programs/digital-learning' => ['Digital Learning', null, 'Digital Learning programme of Prerit Welfare Foundation: basic computer and smartphone skills, online safety and safe digital payments for every age group.'],
        'programs/scholarship-educational-support' => ['Scholarship & Educational Support', null, 'Scholarship & Educational Support: apply online for scholarship support, study material and guidance for students from economically weaker families.'],
        'programs/food-distribution' => ['Food Distribution', null, 'Food Distribution programme of Prerit Welfare Foundation: ration kits, cooked food in emergencies and essential supplies for families facing hardship.'],
        'programs/disaster-relief' => ['Disaster Relief', null, 'Disaster Relief by Prerit Welfare Foundation: food, essential supplies, hygiene and health support for communities affected by floods and other disasters.'],
        'programs/ai-artificial-intelligence' => ['AI & Artificial Intelligence', 'AI & Artificial Intelligence Workshops', 'AI workshops and awareness sessions by Prerit Welfare Foundation: generative AI, everyday AI tools, responsible use of AI and AI for community development.'],
        'programs/robotics-technology' => ['Robotics & Emerging Technology', 'Robotics & Emerging Technology Workshops', 'Robotics, automation, IoT, sensors and STEM activities for students and young people by Prerit Welfare Foundation. See upcoming workshops and register.'],
        'programs/machine-learning-data-science' => ['Machine Learning & Data Science', 'Machine Learning & Data Science Workshops', 'Learn machine learning fundamentals, data visualisation and Python through practical projects with Prerit Welfare Foundation. See upcoming sessions and register.'],
        'programs/digital-marketing' => ['Digital Marketing', 'Digital Marketing Workshops', 'Digital marketing workshops by Prerit Welfare Foundation: SEO, social media, Google Ads, analytics and personal branding for students, job seekers and small businesses.'],
        'programs/cyber-security' => ['Cyber Security & Digital Awareness', 'Cyber Security & Digital Awareness Sessions', 'Cyber safety awareness by Prerit Welfare Foundation: online safety, phishing and fraud awareness, privacy and cyber hygiene for students, families and senior citizens.'],
        'programs/career-entrepreneurship' => ['Career Development & Entrepreneurship', 'Career Development & Entrepreneurship Programmes', 'Career guidance and entrepreneurship workshops by Prerit Welfare Foundation: resume writing, interview skills, freelancing, startups and career planning.'],
        'programs/fitness-yoga-wellness' => ['Fitness, Yoga & Wellness', 'Fitness, Yoga & Wellness Sessions', 'Community yoga sessions, fitness awareness, meditation and healthy lifestyle programmes by Prerit Welfare Foundation. See upcoming sessions and register.'],
        'programs/music-arts-culture' => ['Music, Arts & Cultural Programs', 'Music, Arts & Cultural Programmes', 'Music workshops, singing, instruments, art and cultural programmes by Prerit Welfare Foundation that encourage creative expression and community celebration.'],
        'programs/hackathons-innovation' => ['Hackathons & Innovation Challenges', 'Hackathons & Innovation Challenges', 'Hackathons, coding challenges and innovation challenges by Prerit Welfare Foundation: team projects, idea pitching and prototypes that solve community problems.'],
        'programs/events-seminars' => ['Events, Seminars & Awareness Campaigns', 'Events, Seminars & Awareness Campaigns', 'Public seminars, webinars, educational events, community outreach and awareness drives by Prerit Welfare Foundation. See upcoming events and register.'],
        'events' => ['Events', null, 'Upcoming and past camps, drives, awareness programmes and community events of Prerit Welfare Foundation, with free registration where it is open.'],
        'awareness-calendar' => ['Awareness Calendar', null, 'A month-by-month calendar of national and international awareness days on education, health, women, environment and more, with ways to take part.'],
        'awareness-campaign' => ['Awareness Campaign', null, 'Awareness campaigns by Prerit Welfare Foundation on health, hygiene, education, environment, digital literacy, welfare schemes and cyber safety.'],
        'become-a-volunteer' => ['Volunteer with Us', null, 'Volunteer with Prerit Welfare Foundation in education, health, women empowerment, environment and skill development.'],
        'volunteer-teacher' => ['Volunteer Teacher Programme', null, 'Become a volunteer teacher with Prerit Welfare Foundation, in person or online.'],
        'volunteer-staff' => ['Volunteer Staff Application', null, 'Apply for staff and volunteer staff positions at Prerit Welfare Foundation.'],
        'internships' => ['Internships', null, 'Internship opportunities at Prerit Welfare Foundation for students and young professionals.'],
        'membership' => ['Membership', null, 'Become a member of Prerit Welfare Foundation: membership types, how to apply, and how every application is reviewed and approved by the foundation.'],
        'donate' => ['Donate Now', null, 'Donate online securely to Prerit Welfare Foundation and receive a receipt for every verified donation.'],
        'campaigns' => ['Donate / Fundraise', null, 'Support fundraising campaigns of Prerit Welfare Foundation, including disaster relief, education and food distribution.'],
        'start-a-fundraiser' => ['Start a Fundraiser', null, 'Propose a fundraiser with Prerit Welfare Foundation for a cause linked to our work. Every proposal is reviewed, and donations go to the foundation.'],
        'financial-transparency' => ['Financial Transparency', null, 'Financial transparency, verified donation totals and registration details of Prerit Welfare Foundation.'],
        'impact-reports' => ['Impact Reports', null, 'Impact and annual reports of Prerit Welfare Foundation on our programmes and how they are run, published here as they become available.'],
        'blog' => ['Blogs & Articles', null, 'Articles and news from Prerit Welfare Foundation about our work in education, healthcare, women empowerment, environment and skill development.'],
        'media' => ['Media / Press Releases', null, 'Press releases and media resources from Prerit Welfare Foundation, New Delhi, about our programmes, campaigns, events and community work.'],
        'digital-resources' => ['Digital Resources', null, 'Free guides, toolkits and learning resources from Prerit Welfare Foundation on education, health, women empowerment, environment and skills.'],
        'gallery' => ['Photo Gallery', null, 'Photos from the programmes, events and campaigns of Prerit Welfare Foundation.'],
        'schemes' => ['Welfare Schemes', null, 'Information on government and welfare schemes for education, health, women, livelihood and social security, with help to apply.'],
        'request-help' => ['Request Help', null, 'Request support from Prerit Welfare Foundation for education, health, livelihood, food or disaster relief.'],
        'beneficiary-support' => ['Beneficiary Support', null, 'How individuals and families can request support from Prerit Welfare Foundation: who can apply, types of help, process and documents.'],
        'scholarship' => ['Scholarship Application', null, 'Apply online for scholarship support from Prerit Welfare Foundation for students who need help to continue their education, and follow your application.'],
        'contact' => ['Contact Us', null, 'Contact Prerit Welfare Foundation in New Delhi by email or our enquiry form about volunteering, partnerships and CSR, donations or support from our programmes.'],
        'faq' => ['Frequently Asked Questions', null, 'Answers to common questions about donating, volunteering and getting support.'],
        'report-fraud' => ['Report Fraud or Misuse', null, 'Report a suspicious call, message, website or account using the name of Prerit Welfare Foundation.'],
        'verify-letter' => ['Verify an Authorization Letter', 'Verify an Authorization Letter', 'Enter the reference number and verification code printed on an authorization letter to check that it was issued by Prerit Welfare Foundation and is still valid.'],
        'child-protection-policy' => ['Child Protection Policy', null, 'Child protection and safeguarding policy of Prerit Welfare Foundation.'],
        'application-status' => ['Application Status', null, 'Check the status of your volunteer, teacher, internship, staff, scholarship or help application.'],
    ];
}

/**
 * New values: slug => [meta_title (null = leave as it is), meta_description (null = leave as it is)].
 * Titles already contain the site name, so no suffix is added (current seo_full_title() and WP-1 seo_title()).
 * A title is set only where it differs from the default "{page title} | Prerit Welfare Foundation".
 * Written from each page's real content (mapping.md section 11); English only (no Hindi/Hinglish targets, C11).
 */
function seo_content_new_pages(): array
{
    $p = 'Prerit Welfare Foundation';
    return [
        'about' => ["About $p - Who We Are & How We Work", "$p is a nonprofit based in New Delhi. Learn who we are, why we do this work, our areas of work, how we work and our values."],
        'our-mission-vision' => [null, 'Our mission, vision and values: empowering underprivileged communities through education, healthcare, women empowerment, skills and the environment.'],
        'achievements' => ["Achievements & Milestones | $p", "Milestones, completed campaigns, past events and certificates of $p, added only after they are verified against our records."],
        'ngo-details' => ["NGO Details: Registration & CIN | $p", null],   // description built from Settings (seo_content_dynamic_descriptions)
        'certifications' => ["Registration Certificates | $p", "Registration and recognition certificates of $p, published exactly as issued after verification. Contact us for due diligence."],
        'management-body' => ["Management Body & Governance | $p", "The management body of $p and the governance principles it follows: integrity, financial care, transparency and safeguarding."],
        'volunteers' => [null, "Meet the team and volunteers of $p, and find out how to join us as a volunteer, volunteer teacher, intern or staff member."],
        'programs' => ["Programs, Workshops & Events | $p", 'AI, robotics, digital skills, cyber safety, career, yoga and cultural programmes, hackathons, plus our core work in education, health and relief.'],
        'programs/education-literacy' => ["Education & Literacy Programme | $p", 'Literacy drives, community learning circles, study material and school support for children and adults. All our learning activities are free of charge.'],
        'programs/healthcare' => ["Free Health Camps & Awareness | $p", 'Free health camps and screenings with qualified doctors and health workers, awareness on prevention, hygiene and nutrition, and referral support.'],
        'programs/women-empowerment' => ["Women Empowerment Programme | $p", 'Skill training, Self-Help Group support, rights awareness, financial literacy and safe digital banking sessions that help women learn, earn and lead.'],
        'programs/youth-empowerment' => ["Youth Empowerment Programme | $p", 'Career guidance, mentoring, leadership, life-skills and digital-skills workshops and community service projects for young people from underserved families.'],
        'programs/rural-development' => ["Rural Development Programme | $p", 'Awareness drives, livelihood and skill-building activities and free help with government welfare schemes, planned together with village communities.'],
        'programs/environment-protection' => ["Tree Plantation & Cleanup Drives | $p", 'Join tree plantation and cleanliness drives and sessions on waste segregation and sustainable living in schools and neighbourhoods, with care for saplings.'],
        'skill-development' => [null, 'Free vocational and digital training, career guidance, interview preparation and enterprise guidance for young people and adults. We never charge fees.'],
        'programs/digital-learning' => ["Digital Literacy Programme | $p", 'Learn computer and smartphone basics, online services and safe digital payments in simple language, in patient hands-on sessions for first-time users.'],
        'programs/scholarship-educational-support' => ["Scholarship & Education Support | $p", 'Scholarship support, study material, mentoring and guidance on government scholarships for students from economically weaker families. See how to apply.'],
        'programs/food-distribution' => ["Food Distribution & Ration Kits | $p", 'Ration kits, cooked food in emergencies and essential supplies for families facing hardship, distributed with dignity. Campaigns open only when needed.'],
        'programs/disaster-relief' => ["Disaster & Flood Relief | $p", 'Food, water, essential supplies, hygiene and health support for families hit by floods and other disasters. Volunteer or support a relief campaign.'],
        'programs/ai-artificial-intelligence' => ["AI Workshops for Students | $p", 'Beginner-friendly AI workshops for students, teachers and community workers: generative AI, everyday AI tools and responsible use. No coding needed.'],
        'programs/robotics-technology' => ["Robotics & STEM Workshops | $p", 'Hands-on robotics and STEM workshops: circuits, sensors, microcontrollers, IoT, automation and coding through small supervised projects. See dates.'],
        'programs/machine-learning-data-science' => ["Machine Learning Workshops | $p", 'Learn Python, data analysis, data visualisation and machine learning fundamentals through practical projects with open data. See sessions and register.'],
        'programs/digital-marketing' => [null, 'Practical digital marketing workshops on SEO, social media, online ads, analytics and personal branding for students, job seekers and small businesses.'],
        'programs/cyber-security' => ["Cyber Safety Awareness Sessions | $p", 'Cyber safety sessions on phishing, OTP and UPI fraud, fake job offers, privacy and reporting cyber crime, for students, families and senior citizens.'],
        'programs/career-entrepreneurship' => ["Career Guidance Workshops | $p", 'Workshops on resumes, interviews, career choices, freelancing and starting a small enterprise for students and job seekers. We never promise jobs.'],
        'programs/fitness-yoga-wellness' => ["Yoga, Fitness & Wellness | $p", 'Community yoga, meditation, fitness awareness and healthy-lifestyle sessions for all ages, led by trained instructors or experienced volunteers.'],
        'programs/music-arts-culture' => ["Music, Arts & Culture Programmes | $p", "Music, singing, instrument, art and craft workshops and cultural programmes that celebrate creativity and India's diverse traditions, for every community."],
        'programs/hackathons-innovation' => ["Hackathons for Students | $p", 'Hackathons and innovation challenges where student teams solve real community problems, from problem statement to prototype and pitch. Rules on each page.'],
        'programs/events-seminars' => ["Seminars, Webinars & Events | $p", 'Public seminars, webinars and educational events on education, health, rights, environment, digital safety and welfare schemes. See what is coming up.'],
        'events' => ["Upcoming Events & Workshops | $p", "Upcoming workshops, seminars, camps, drives and awareness events of $p. See dates, venues and how to register."],
        'awareness-calendar' => ["Awareness Days Calendar | $p", 'A month-by-month calendar of national and international awareness days on health, education, women, environment and rights, with simple ways to take part.'],
        'awareness-campaign' => ["Community Awareness Campaigns | $p", 'Community awareness campaigns on health, hygiene, girl child education, environment, digital and financial literacy, welfare schemes and cyber safety.'],
        'become-a-volunteer' => ["NGO Volunteer Opportunities | $p", 'Volunteer with us in education, health, women empowerment, environment and relief work, near you or online. Certificate issued for verified service.'],
        'volunteer-teacher' => ["Become a Volunteer Teacher | $p", 'Teach children and adult learners as a volunteer teacher, in person or online. Apply with your subjects and availability; safeguarding checks apply.'],
        'volunteer-staff' => ["NGO Jobs & Staff Positions | $p", "Apply for staff and volunteer staff positions at $p, or send a general application. We never charge any fee to apply."],
        'internships' => ["NGO Internships for Students | $p", 'Internships for students and graduates in education, health, women empowerment, environment, communication, fundraising and research, online or in person.'],
        'membership' => ["Become a Member | $p", "Become a General, Volunteer, Student or Life Member of $p. See membership types, responsibilities and how applications are reviewed."],
        'donate' => ["Donate to $p Online", "Donate online to $p through a secure payment gateway. Every verified donation gets a receipt; see how your donation is used."],
        'campaigns' => [null, "Fundraising campaigns of $p for relief, education and food. Progress shows verified donations only, and utilisation is published."],
        'start-a-fundraiser' => ["Start a Fundraiser for a Cause | $p", 'Propose a fundraiser for a cause linked to our work - flood relief, school kits or rations. Every proposal is reviewed and donations go to the foundation.'],
        'financial-transparency' => [null, "Verified donation totals, how each online donation is checked and receipted, and the financial practices of $p."],
        'impact-reports' => ["Impact & Annual Reports | $p", "Annual and impact reports of $p, using figures from our own records, published here as each report is prepared."],
        'blog' => ["Blog & Guides | $p", "Articles, guides and news from $p on education, digital literacy, cyber safety, skills, health and volunteering."],
        'media' => ["Media & Press Releases | $p", "Press releases, media resources and contact details for journalists covering the programmes, campaigns and events of $p."],
        'digital-resources' => ["Free Guides & Toolkits | $p", "Free guides, toolkits and learning material from $p on welfare schemes, awareness topics and volunteering, added as they are ready."],
        'gallery' => [null, "Photos from the programmes, events and campaigns of $p, published with consent. Ask us to remove a photo of you at any time."],
        'schemes' => ["Welfare Schemes Guide | $p", 'Simple guides to government welfare schemes for education, health, women and livelihoods, checked against official sources, with free help to apply.'],
        'request-help' => [null, "Ask $p for support with education, health, women's SHGs, skills, food and rations or disaster relief. Requests are confidential."],
        'beneficiary-support' => ["Who Can Get Support | $p", "Who can ask $p for support, the kinds of help available, the documents that help and what happens after you apply."],
        'scholarship' => ["Apply for Scholarship Support | $p", 'Apply online for scholarship support for fees, books and study material if your family cannot afford them. Every application is reviewed in confidence.'],
        'contact' => [null, null],   // description built from Settings (seo_content_dynamic_descriptions)
        'faq' => [null, 'Answers about donating, receipts, volunteering, internships, applications, events, getting support and checking requests made in our name.'],
        'report-fraud' => ["Report Fraud Using Our Name | $p", 'Received a call, message, job offer or donation request using our name? Learn the warning signs, verify it with us and report it. Lost money? Call 1930.'],
        'verify-letter' => [null, "Check an authorization letter issued by $p: enter its reference number and verification code to see whether it is valid."],
        'child-protection-policy' => [null, "Child protection policy of $p: safe recruitment, code of behaviour, consent for photos, online sessions and how to report a concern."],
        'application-status' => [null, 'Check the status of a volunteer, teacher, internship, staff, membership, scholarship or help application with your reference number.'],
        'home' => [null, "$p is a New Delhi nonprofit working for education, skill development, healthcare, women empowerment and a cleaner environment."],
    ];
}

/** Descriptions that depend on facts in Settings (only verified registration facts entered by the foundation). */
function seo_content_dynamic_descriptions(): array
{
    $p = 'Prerit Welfare Foundation';
    $out = [];
    $cin = trim((string) setting('cin_number'));
    $ngoId = trim((string) setting('ngo_id'));
    $udyam = trim((string) setting('msme_udyam_number'));
    if ($cin !== '' && $ngoId !== '') {
        $out['ngo-details'] = "Registration details of $p: CIN $cin, NGO ID $ngoId" . ($udyam !== '' ? ', MSME Udyam registration' : '') . ' and official contacts.';
    } else {
        $out['ngo-details'] = "Registration and legal details of $p, published as they are verified and entered by the foundation, with official contacts.";
    }
    $office = trim((string) setting('contact_address'));
    $hasCorporate = trim((string) setting('corporate_office_address')) !== '';
    if (preg_match('/New Delhi,?\s*(\d{6})/i', $office, $m) && $hasCorporate) {
        $out['contact'] = "Contact $p: New Delhi office ({$m[1]}), Noida corporate office, email and enquiry form for volunteering, partnerships and support.";
    } else {
        $out['contact'] = "Contact $p by email or through the enquiry form about volunteering, partnerships, media, donations or support from our programmes.";
    }
    // the Noida wording is used only while the corporate office setting really is in Noida
    if ($hasCorporate && stripos((string) setting('corporate_office_address'), 'Noida') === false) {
        $out['contact'] = "Contact $p by email or through the enquiry form about volunteering, partnerships, media, donations or support from our programmes.";
    }
    return $out;
}

/** Length rules: title 1-60 characters (no suffix is added to these titles), description 70-155 characters. */
function seo_content_title_ok(?string $title): bool
{
    return $title === null || ($title !== '' && mb_strlen($title) <= 60);
}

function seo_content_description_ok(?string $desc): bool
{
    return $desc === null || (mb_strlen($desc) >= 70 && mb_strlen($desc) <= 155);
}

/** One activity-log line per migration that changed something (staff log). */
function seo_content_log(string $what, array $changes): void
{
    if ($changes && function_exists('log_activity')) {
        log_activity('seo_content', $what . ': ' . mb_substr(implode(', ', $changes), 0, 900), null, true);
    }
}

// =====================================================================
// Guarded writers
// =====================================================================

/**
 * Fill meta_title / replace meta_description of one page, only where the stored values are still the seeded ones.
 * Returns the names of the fields that changed.
 */
function seo_content_apply_page(string $slug, array $seed, ?string $newTitle, ?string $newDesc): array
{
    [$seedTitle, $seedMetaTitle, $seedDesc] = $seed;
    $changed = [];
    if ($newTitle !== null && seo_content_title_ok($newTitle)) {
        // empty meta_title, or (W2 category pages) the seeded meta_title; and the page title itself is still seeded
        $sql = "UPDATE pages SET meta_title = ? WHERE slug = ? AND MD5(title) = BINARY ? AND (meta_title IS NULL OR meta_title = ''"
            . ($seedMetaTitle !== null ? ' OR MD5(meta_title) = BINARY ?' : '') . ')';
        $params = [$newTitle, $slug, md5($seedTitle)];
        if ($seedMetaTitle !== null) {
            $params[] = md5($seedMetaTitle);
        }
        if (db_query($sql, $params)->rowCount() > 0) {
            $changed[] = $slug . ' title';
        }
    }
    if ($newDesc !== null && $seedDesc !== null && $newDesc !== $seedDesc && seo_content_description_ok($newDesc)) {
        // exact (byte) comparison with the seeded text: any admin edit, even a single character, is kept
        if (db_query('UPDATE pages SET meta_description = ? WHERE slug = ? AND MD5(meta_description) = BINARY ?', [$newDesc, $slug, md5($seedDesc)])->rowCount() > 0) {
            $changed[] = $slug . ' description';
        }
    }
    return $changed;
}

/** A page section that still has its seeded content (MD5 over the same columns as migration_100), or null. */
function seo_content_seeded_section(string $slug, string $type, int $sort, string $md5): ?array
{
    $row = db_row("SELECT s.* FROM page_sections s JOIN pages p ON p.id = s.page_id
                   WHERE p.slug = ? AND s.section_type = ? AND s.sort_order = ?
                     AND MD5(CONCAT_WS('|', s.title, s.intro, s.body, s.items, s.settings, s.image, s.pill, s.background)) = BINARY ?
                   ORDER BY s.id LIMIT 1", [$slug, $type, $sort, $md5]);
    return $row ?: null;
}

/**
 * Add links to feature cards of a seeded "features" section: $links = [card title => [url, link text]].
 * Only cards that have no link yet are changed; the card text is kept as it is.
 */
function seo_content_link_cards(string $slug, int $sort, string $md5, array $links): bool
{
    $row = seo_content_seeded_section($slug, 'features', $sort, $md5);
    if (!$row) {
        return false;
    }
    $items = json_decode((string) $row['items'], true);
    if (!is_array($items)) {
        return false;
    }
    $done = false;
    foreach ($items as $i => $it) {
        $title = is_array($it) ? (string) ($it['title'] ?? '') : '';
        if ($title !== '' && isset($links[$title]) && empty($it['link'])) {
            $items[$i]['link'] = $links[$title][0];
            $items[$i]['link_label'] = $links[$title][1];
            $done = true;
        }
    }
    if (!$done) {
        return false;
    }
    db_query('UPDATE page_sections SET items = ? WHERE id = ?', [json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int) $row['id']]);
    return true;
}

/** Insert a section on a page (page_id resolved by slug). */
function seo_content_insert_section(int $pageId, array $s): int
{
    $j = function ($v): string {
        return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };
    return (int) db_insert('page_sections', [
        'page_id' => $pageId, 'section_type' => $s['type'], 'anchor' => $s['anchor'] ?? null, 'pill' => $s['pill'] ?? null,
        'title' => $s['title'] ?? null, 'intro' => $s['intro'] ?? null, 'body' => $s['body'] ?? null, 'image' => $s['image'] ?? null,
        'items' => $j($s['items'] ?? []), 'settings' => $j((object) ($s['settings'] ?? [])),
        'background' => $s['background'] ?? 'white', 'status' => 'visible', 'sort_order' => (int) $s['sort'],
    ]);
}

// =====================================================================
// Migrations 870-879
// =====================================================================

/** Titles and descriptions of the mapped pages, the W2 category pages (G-ON3) and the reviewed utility pages (G-ON2). */
function migration_870_seo_content_page_meta(): void
{
    if (migration_once_done('870_page_meta')) {
        return;   // done once: a title or description the owner cleared later stays empty
    }
    $seeds = seo_content_seeded_pages();
    $dynamic = seo_content_dynamic_descriptions();
    $changes = [];
    foreach (seo_content_new_pages() as $slug => [$title, $desc]) {
        if (!isset($seeds[$slug])) {
            continue;
        }
        if ($slug === 'home') {
            $title = null;   // the home title is migration 871 (needs WP-1)
        }
        $desc = $dynamic[$slug] ?? $desc;
        $changes = array_merge($changes, seo_content_apply_page($slug, $seeds[$slug], $title, $desc));
    }
    migration_once_mark('870_page_meta');
    seo_content_log('Search titles and descriptions updated where they were still the original text', $changes);
}

/**
 * Home <title>: filled only while empty, and only when the WP-1 SEO core is installed - it prints a home meta_title
 * verbatim (C13). Without it, seo_full_title() would append the tagline and produce a 90+ character title.
 */
function migration_871_seo_content_home_title(): void
{
    if (!function_exists('seo_finalize') && !function_exists('seo_title')) {
        return;
    }
    $title = 'Prerit Welfare Foundation | NGO for Education & Skills';
    if (!seo_content_title_ok($title) || migration_once_done('871_home_title')) {
        return;
    }
    $n = db_query("UPDATE pages SET meta_title = ? WHERE slug = 'home' AND template = 'home' AND (meta_title IS NULL OR meta_title = '')", [$title])->rowCount();
    migration_once_mark('871_home_title');
    seo_content_log('Home page search title set', $n ? ['home title'] : []);
}

/**
 * Cannibalisation fixes (mapping.md section 4): each page keeps one job and links to the page that owns a topic.
 *   C1 career guidance -> /programs/career-entrepreneurship/ ; C2 online safety <-> digital literacy ;
 *   C3 formats vs topics vs dates ; C5 AI / ML / robotics by level ; C6 + C7 page titles (H1).
 *   C4 and C8 need no copy change: the scholarship programme page already sends "apply" to /scholarship/, and
 *   Beneficiary Support already uses /request-help/ as its main call to action (their descriptions change in 870).
 */
function migration_872_seo_content_cannibalisation_copy(): void
{
    if (migration_once_done('872_cannibalisation_copy')) {
        return;   // done once: a card link or button the owner removed later is not added again
    }
    $changes = [];
    $fixes = [
        // C1
        ['skill-development', 30, '7d1426cad6291fe1684f1a2b3b2a3f3b', ['Career guidance' => ['/programs/career-entrepreneurship/', 'Career guidance workshops']]],
        ['programs/youth-empowerment', 30, '34bd22887e5c97927a00bdc899666dbd', ['Career guidance' => ['/programs/career-entrepreneurship/', 'Career guidance workshops']]],
        // C2
        ['programs/digital-learning', 30, 'e375363a11936a2447496fb5380ef8b5', ['Online safety' => ['/programs/cyber-security/', 'Cyber safety sessions']]],
        ['programs/cyber-security', 20, 'fbed6b80bfacef5afab8f88347781c55', [
            'Safe mobile and internet use' => ['/programs/digital-learning/', 'Digital literacy sessions'],
            'Reporting and getting help' => ['/report-fraud/', 'Report fraud and get help'],
        ]],
        // C3
        ['programs/events-seminars', 20, 'd7590d4b6b0d1cbdffb4852041b4ae1b', [
            'Awareness drives' => ['/awareness-campaign/', 'Community awareness campaigns'],
            'Awareness days' => ['/awareness-calendar/', 'Awareness days calendar'],
        ]],
        ['awareness-campaign', 2, '2ddbfa9c60f561676e588d0fcf420280', [
            'Cyber safety' => ['/programs/cyber-security/', 'Cyber safety sessions'],
            'Digital & financial literacy' => ['/programs/digital-learning/', 'Digital literacy programme'],
        ]],
        // C5
        ['programs/ai-artificial-intelligence', 20, '90c8f61940709ad74f5b78ef88ae7d41', [
            'Introduction to AI' => ['/programs/machine-learning-data-science/', 'Ready to code? Machine learning workshops'],
            'Responsible AI' => ['/programs/cyber-security/', 'Deepfakes and scams: cyber safety sessions'],
        ]],
        ['programs/machine-learning-data-science', 20, '1fbdcd5a61d4fd79f07ed18209cb12ae', [
            'Machine learning fundamentals' => ['/programs/ai-artificial-intelligence/', 'New to AI? Beginner AI workshops'],
            'Practical projects' => ['/programs/hackathons-innovation/', 'Hackathons and innovation challenges'],
        ]],
        ['programs/robotics-technology', 20, '11313e62c1d3ecef167a9b573f230dd5', [
            'Automation' => ['/programs/ai-artificial-intelligence/', 'AI workshops for students'],
            'STEM challenges' => ['/programs/hackathons-innovation/', 'Hackathons and innovation challenges'],
        ]],
    ];
    foreach ($fixes as [$slug, $sort, $md5, $links]) {
        if (seo_content_link_cards($slug, $sort, $md5, $links)) {
            $changes[] = $slug;
        }
    }

    // C6: "Our Team" page links to Volunteer with Us on its first screen (buttons on the seeded intro block)
    $row = seo_content_seeded_section('volunteers', 'split', 10, '8b59c6042d8da5a66b76906d9760ea4a');
    if ($row) {
        $set = json_decode((string) $row['settings'], true);
        if (is_array($set) && empty($set['buttons'])) {
            $set['buttons'] = [
                ['label' => 'Volunteer with us', 'url' => '/become-a-volunteer/', 'style' => 'primary', 'icon' => 'users'],
                ['label' => 'Become a volunteer teacher', 'url' => '/volunteer-teacher/', 'style' => 'outline', 'icon' => 'book'],
            ];
            db_query('UPDATE page_sections SET settings = ? WHERE id = ?', [json_encode($set, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int) $row['id']]);
            $changes[] = 'volunteers buttons';
        }
    }
    // C6 + C7: page titles (H1, breadcrumbs) only while they are the seeded ones; menu labels are not touched
    foreach ([
        ['volunteers', 'Our Team', 'Our Team & Volunteers'],
        ['volunteer-staff', 'Volunteer Staff Application', 'Work With Us: Staff Positions'],
        ['campaigns', 'Donate / Fundraise', 'Fundraising Campaigns'],
    ] as [$slug, $old, $new]) {
        if (db_query('UPDATE pages SET title = ? WHERE slug = ? AND MD5(title) = BINARY ?', [$new, $slug, md5($old)])->rowCount() > 0) {
            $changes[] = $slug . ' title';
        }
    }
    migration_once_mark('872_cannibalisation_copy');
    seo_content_log('Programme pages cross-linked and page titles clarified (unedited content only)', $changes);
}

/**
 * Help text for /verify-letter/ (G-ON4); /application-status/ only when it has no explanatory sections at all.
 * Each page is handled once (one-time marker): a help section the owner deleted is not added again.
 */
function migration_873_seo_content_utility_help(): void
{
    $changes = [];
    $pageId = (int) db_value("SELECT id FROM pages WHERE slug = 'verify-letter' AND template = 'verify_letter'");
    $verifyDone = !$pageId || migration_once_done('873_verify_letter_help');
    if (!$verifyDone && !(int) db_value("SELECT COUNT(*) FROM page_sections WHERE page_id = ? AND (anchor = 'how-to-check' OR section_type IN ('richtext', 'faqs', 'faq_list', 'steps'))", [$pageId])) {
        $prefix = function_exists('letter_ref_prefix') ? letter_ref_prefix() : 'EIF';
        $example = $prefix . '/' . date('Y') . '/012';
        // a "module" row keeps the verification form first and puts the help text under it
        if (!(int) db_value("SELECT COUNT(*) FROM page_sections WHERE page_id = ? AND section_type = 'module'", [$pageId])) {
            seo_content_insert_section($pageId, ['type' => 'module', 'sort' => 10]);
        }
        seo_content_insert_section($pageId, [
            'type' => 'richtext', 'anchor' => 'how-to-check', 'pill' => 'Help', 'title' => 'How to check a letter', 'sort' => 20, 'background' => 'tint',
            'settings' => ['width' => 'narrow'],
            'body' => '<p>Our coordinators issue authorization letters to volunteer teachers who are placed in schools. Two details are printed in small type at the bottom of every letter: a <strong>reference number</strong> such as ' . e($example) . ' (a prefix, the year and a serial number) and an 8-character <strong>verification code</strong> made of letters and digits, such as ABCD-EF23. Enter both exactly as printed; the hyphen in the code is optional.</p>'
                . '<ul><li><strong>Valid</strong> means the foundation issued the letter and has not withdrawn it. Compare the date, the partly hidden name and the school with the paper letter.</li>'
                . '<li><strong>Revoked</strong> means the letter was withdrawn and must not be relied on.</li>'
                . '<li><strong>No matching letter</strong> means the details did not match. Check for typing mistakes first.</li></ul>'
                . '<p>If a letter still does not match, or someone asks for money for a letter or a placement, do not rely on it and tell us through our <a href="/report-fraud/">Report Fraud</a> page. We never charge any fee for volunteering or for an authorization letter.</p>',
        ]);
        $changes[] = 'verify-letter';
    }
    if (!$verifyDone) {
        migration_once_mark('873_verify_letter_help');
    }
    $statusId = (int) db_value("SELECT id FROM pages WHERE slug = 'application-status' AND template = 'application_status'");
    $statusDone = !$statusId || migration_once_done('873_application_status_help');
    if (!$statusDone && !(int) db_value("SELECT COUNT(*) FROM page_sections WHERE page_id = ? AND section_type IN ('richtext', 'faqs', 'faq_list', 'features', 'steps')", [$statusId])) {
        if (!(int) db_value("SELECT COUNT(*) FROM page_sections WHERE page_id = ? AND section_type = 'module'", [$statusId])) {
            seo_content_insert_section($statusId, ['type' => 'module', 'sort' => 10]);
        }
        seo_content_insert_section($statusId, [
            'type' => 'richtext', 'anchor' => 'how-to-check-status', 'pill' => 'Help', 'title' => 'How to check your status', 'sort' => 20, 'background' => 'tint',
            'settings' => ['width' => 'narrow'],
            'body' => '<p>You can check volunteer, volunteer teacher, internship, staff, membership and scholarship applications and requests for help on this page. Enter the reference number shown on your confirmation screen and in the acknowledgement email (it looks like PWF-VOL-' . date('Y') . '-00012), together with the email address or mobile number you gave when applying.</p>'
                . '<p>For your privacy we never say whether a reference number exists unless the email or mobile number also matches. If nothing is found, check for extra spaces and use the same contact details as in your application. We email you whenever your status changes. If you need to correct something, write to us through the <a href="/contact/">Contact Us</a> page quoting your reference number. We never charge any fee for an application.</p>',
        ]);
        $changes[] = 'application-status';
    }
    if (!$statusDone) {
        migration_once_mark('873_application_status_help');
    }
    seo_content_log('Help text added to utility pages', $changes);
}

/** Footer "Workshops": the seeded link points to a noindex, filtered event list; send it to the Programs & Events grid (P1-1). */
function migration_874_seo_content_footer_workshops(): void
{
    if (!table_exists('menu_items') || migration_once_done('874_footer_workshops')) {
        return;
    }
    $n = db_query("UPDATE menu_items i JOIN menus m ON m.id = i.menu_id SET i.url = '/programs/'
                   WHERE m.menu_key = 'footer_programs' AND i.label = 'Workshops' AND i.url = '/events/?type=workshop'")->rowCount();
    migration_once_mark('874_footer_workshops');
    seo_content_log('Footer "Workshops" link now opens the Programs & Events page', $n ? ['footer_programs'] : []);
}

/**
 * Name, address and phone consistency: Contact and NGO Details show the corporate office from Settings (it was
 * printed only on letters). Added once per page, never when the page already shows that address in a section.
 * The registered office is not invented: the block shows it only after a "registered_office_address" setting exists.
 * Each page is handled once (one-time marker): a block the owner deleted is not added again.
 */
function migration_875_seo_content_office_addresses(): void
{
    $corporate = trim((string) setting('corporate_office_address'));
    if ($corporate === '') {
        return;
    }
    $needle = '%' . mb_substr($corporate, 0, 24) . '%';
    $changes = [];
    foreach ([['contact', 'contact'], ['ngo-details', 'ngo_details']] as [$slug, $template]) {
        $pageId = (int) db_value('SELECT id FROM pages WHERE slug = ? AND template = ?', [$slug, $template]);
        $once = '875_offices_' . str_replace('-', '_', $slug);
        if (!$pageId || migration_once_done($once)) {
            continue;
        }
        $exists = (int) db_value("SELECT COUNT(*) FROM page_sections WHERE page_id = ? AND (section_type = 'office_addresses'
                                    OR CONCAT_WS(' ', title, intro, body, items, settings) LIKE ?)", [$pageId, $needle]);
        if (!$exists) {
            // sort 15: right after the page's main block (contact form / registration table)
            seo_content_insert_section($pageId, [
                'type' => 'office_addresses', 'anchor' => 'offices', 'pill' => 'Addresses', 'title' => 'Our offices', 'sort' => 15, 'background' => 'tint',
            ]);
            $changes[] = $slug;
        }
        migration_once_mark($once);
    }
    seo_content_log('Office addresses block added', $changes);
}

/** Sections of the new "Become a Project Partner" page (all editable in Admin > Pages). */
function seo_content_partner_sections(): array
{
    return [
        ['type' => 'split', 'anchor' => 'about-partnerships', 'pill' => 'Partnerships', 'title' => 'Work with us to reach more people', 'sort' => 10,
            'image' => 'assets/images/hero/volunteer.svg',
            'intro' => 'Prerit Welfare Foundation works with schools, colleges, companies, trainers, sponsors and community organisations to bring learning, awareness and support to more people.',
            'body' => '<p>A partnership can be a single workshop at your school, a series of awareness sessions in your community, a hackathon you help to judge or sponsor, or support for one of our core programmes. Every partnership starts with a conversation about what the people you work with need, and is planned together before anything is announced.</p>',
            'items' => ['Workshops, seminars and awareness sessions', 'Hackathons and innovation challenges', 'Community drives and camps', 'Support for our core programmes'],
            'settings' => ['image_alt' => '', 'buttons' => [
                ['label' => 'Send a partnership enquiry', 'url' => '#partner', 'style' => 'primary', 'icon' => 'send'],
                ['label' => 'See all programmes', 'url' => '/programs/', 'style' => 'outline', 'icon' => 'grid'],
            ]]],
        ['type' => 'features', 'anchor' => 'who-can-partner', 'pill' => 'Who can partner', 'title' => 'Ways to partner with us', 'sort' => 20, 'background' => 'tint',
            'intro' => 'Tell us which of these describes you. Many partners combine more than one.',
            'items' => [
                ['icon' => 'graduation', 'title' => 'Schools, colleges & institutions', 'text' => 'Host a workshop, an awareness session or a hackathon for your students, teachers or members.'],
                ['icon' => 'briefcase', 'title' => 'CSR teams & companies', 'text' => 'Support a programme with funds, equipment, a venue or employee volunteering. Please check our registration details first.', 'link' => '/ngo-details/', 'link_label' => 'NGO registration details'],
                ['icon' => 'user', 'title' => 'Trainers, experts & speakers', 'text' => 'Give your time and knowledge as a trainer, mentor, speaker or hackathon judge.', 'link' => '/become-a-volunteer/', 'link_label' => 'Volunteer your skills'],
                ['icon' => 'award', 'title' => 'Sponsors', 'text' => 'Sponsor a workshop, a hackathon or an awareness drive with material, prizes, equipment or a venue.'],
                ['icon' => 'users', 'title' => 'Community organisations', 'text' => 'Bring a programme to your neighbourhood, village, resident welfare association or community centre.'],
            ],
            'settings' => ['cols' => '3', 'style' => 'card']],
        ['type' => 'program_categories_grid', 'anchor' => 'programme-areas', 'pill' => 'Programme areas', 'title' => 'Programmes you can partner on', 'sort' => 30,
            'intro' => 'Workshops, sessions and events in ten areas. Each area page lists its upcoming sessions.',
            'settings' => ['align' => 'center', 'hide_filters' => true]],
        ['type' => 'programs_grid', 'anchor' => 'core-programmes', 'pill' => 'Core programmes', 'title' => 'Our core programmes', 'sort' => 40, 'background' => 'cream',
            'intro' => 'Partners can also support our long-running work in education, health, livelihoods, the environment and relief.',
            'settings' => ['style' => 'compact', 'align' => 'center']],
        ['type' => 'steps', 'anchor' => 'how-it-works', 'pill' => 'Process', 'title' => 'How a partnership starts', 'sort' => 50,
            'items' => [
                ['icon' => 'send', 'title' => 'Send an enquiry', 'text' => 'Use the partnership enquiry on our Contact page. Tell us about your organisation, the programme area and the people you want to reach.'],
                ['icon' => 'message', 'title' => 'Talk it through', 'text' => 'Our team replies by email and discusses the need, the audience, dates, venue or online mode and what each side will do.'],
                ['icon' => 'file-text', 'title' => 'Agree the plan', 'text' => 'Scope, responsibilities, safeguarding arrangements and any costs are agreed in writing before anything is announced.'],
                ['icon' => 'users', 'title' => 'Run it together', 'text' => 'The session, drive or challenge takes place as planned, with registration on this website where it is needed.'],
                ['icon' => 'check-circle', 'title' => 'Review honestly', 'text' => 'We share what took place: verified attendance, photos taken with consent and what to improve next time.'],
            ],
            'settings' => ['align' => 'center']],
        ['type' => 'checklist', 'anchor' => 'what-we-bring', 'pill' => 'What to expect', 'title' => 'What the foundation brings', 'sort' => 60, 'background' => 'tint',
            'intro' => 'What we can offer depends on the programme, the place and our volunteers at the time. We confirm it in writing for each partnership.',
            'items' => [
                'Planning together: topic, audience, dates, venue or online mode and roles are agreed before anything is announced',
                'Briefed volunteers and trainers who follow our child protection and volunteer policies',
                'Registration through this website, with confirmations sent to participants',
                'Certificates only where the event page confirms them, and only for recorded attendance',
                'An honest record afterwards: verified attendance and photos published only with consent',
            ],
            'settings' => ['cols' => '1']],
        ['type' => 'checklist', 'anchor' => 'what-we-ask', 'pill' => 'Our expectations', 'title' => 'What we ask of partners', 'sort' => 70,
            'intro' => 'These rules protect participants and keep every partnership transparent.',
            'items' => [
                'Share accurate information about your organisation and the people you want to reach',
                'Follow our Child Protection Policy whenever children take part',
                'Do not ask participants for money in our name; any participation fee must be stated on the event page',
                'Use our name and logo only with our written approval',
                'Take photos or recordings of participants only with their consent',
            ],
            'settings' => ['cols' => '1']],
        ['type' => 'partnership_enquiry', 'anchor' => 'partner', 'pill' => 'Enquiry', 'title' => 'Send a partnership enquiry', 'sort' => 80, 'background' => 'tint',
            'intro' => 'Institutions, CSR teams, trainers, sponsors and community organisations can write to us. Our team replies by email.'],
        ['type' => 'link_cards', 'anchor' => 'due-diligence', 'pill' => 'Due diligence', 'title' => 'Check our details before you partner', 'sort' => 90,
            'intro' => 'Registrations the foundation holds are listed on the NGO Details page. Please check them before planning CSR funding or expecting a tax benefit.',
            'items' => [
                ['icon' => 'id-card', 'title' => 'NGO details', 'text' => 'Registration and legal details', 'url' => '/ngo-details/'],
                ['icon' => 'award', 'title' => 'Certificates', 'text' => 'Registration certificates as issued', 'url' => '/certifications/'],
                ['icon' => 'shield', 'title' => 'Financial transparency', 'text' => 'Verified donations and practices', 'url' => '/financial-transparency/'],
                ['icon' => 'heart', 'title' => 'Child protection policy', 'text' => 'Safeguarding rules for every activity', 'url' => '/child-protection-policy/'],
            ]],
        ['type' => 'callout', 'title' => 'Asked to pay to become our partner?', 'sort' => 100,
            'intro' => 'Do not pay anyone who offers you a partnership, centre or franchise in our name in exchange for money. Check with us first and report it on our Report Fraud page.',
            'settings' => ['tone' => 'gold', 'icon' => 'alert-triangle', 'width' => 'narrow', 'size' => 'sm']],
    ];
}

/**
 * "Become a Project Partner" page (/partner-with-us/), its footer link and a link from the Contact page. No franchise page.
 * Each of the three steps is done once (one-time markers): a page, footer link or card link the owner deleted later is
 * not added again, with or without a redirect for the old address.
 */
function migration_876_seo_content_partner_page(): void
{
    $changes = [];
    $slug = 'partner-with-us';
    $blocked = (int) db_value('SELECT COUNT(*) FROM redirects WHERE source_path IN (?, ?)', [$slug, $slug . '/']) > 0;
    $pageDone = migration_once_done('876_partner_page');
    if (!$pageDone && !$blocked && !db_value('SELECT id FROM pages WHERE slug = ?', [$slug])) {
        $pageId = (int) db_insert('pages', [
            'parent_id' => null, 'slug' => $slug, 'template' => 'default', 'title' => 'Become a Project Partner',
            'banner_eyebrow' => 'Partner with us',
            'banner_subtitle' => 'Bring workshops, awareness drives and community programmes to more people, together',
            'banner_image' => 'assets/images/hero/community.svg',
            'banner_buttons' => json_encode([['label' => 'Send a partnership enquiry', 'url' => '#partner', 'style' => 'donate', 'icon' => 'send']], JSON_UNESCAPED_SLASHES),
            'meta_title' => null,
            'meta_description' => 'Schools, colleges, trainers, sponsors and community groups can partner with Prerit Welfare Foundation to host workshops, hackathons and awareness drives.',
            'status' => 'published', 'is_system' => 0, 'show_in_sitemap' => 1, 'noindex' => 0, 'sort_order' => 61,
            'published_at' => date('Y-m-d H:i:s'),
        ]);
        foreach (seo_content_partner_sections() as $s) {
            seo_content_insert_section($pageId, $s);
        }
        $changes[] = 'page /partner-with-us/';
    }
    if (!$pageDone) {
        migration_once_mark('876_partner_page');
    }
    $live = (int) db_value("SELECT COUNT(*) FROM pages WHERE slug = ? AND status = 'published'", [$slug]) > 0;
    if ($live && table_exists('menu_items')) {
        $menuId = (int) db_value("SELECT id FROM menus WHERE menu_key = 'footer_involved'");
        if ($menuId && !migration_once_done('876_partner_footer_link')) {
            if (!db_value("SELECT COUNT(*) FROM menu_items i JOIN menus m ON m.id = i.menu_id WHERE m.menu_key LIKE 'footer%' AND i.url IN ('/partner-with-us/', '/partner-with-us')")) {
                $sort = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM menu_items WHERE menu_id = ? AND parent_id IS NULL', [$menuId]);
                db_insert('menu_items', ['menu_id' => $menuId, 'parent_id' => null, 'label' => 'Become a Project Partner', 'url' => '/partner-with-us/', 'status' => 'visible', 'sort_order' => $sort]);
                $changes[] = 'footer link';
            }
            migration_once_mark('876_partner_footer_link');
        }
        // Contact > "Work with us": the partnerships card links to the new page (seeded section only)
        if (!migration_once_done('876_contact_card_link')) {
            if (seo_content_link_cards('contact', 30, '30e344223dcd0d2ed4de29597e3ffdb6', ['Partnerships & CSR' => ['/partner-with-us/', 'Become a project partner']])) {
                $changes[] = 'contact card link';
            }
            migration_once_mark('876_contact_card_link');
        }
    }
    seo_content_log('"Become a Project Partner" page', $changes);
}

/**
 * Focus and secondary keywords per page from the keyword mapping (keywords.csv), for the WP-1/WP-4 seo_meta fields
 * (advisory only: they never change page text). English only - no Hindi or Hinglish terms until real Hindi content
 * exists (CRITIC C11) - and without terms the site cannot honestly serve: "free ... workshop" on pages whose fees are set
 * per event, 80G/12A, unconfirmed offices or placements, draft flood-relief campaigns, "near me" and city variants.
 * slug => [focus keyword, [secondary keywords, max 10]]
 */
function seo_content_keywords(): array
{
    return [
        'home' => ['Prerit Welfare Foundation', ['Prerit Welfare Foundation New Delhi', 'Prerit Welfare Foundation NGO', 'NGO for education and youth empowerment in India', 'NGO for education and skill development']],
        'about' => ['about Prerit Welfare Foundation', ['what does Prerit Welfare Foundation do', 'Prerit Welfare Foundation Noida corporate office', 'nonprofit for education healthcare and women empowerment']],
        'our-mission-vision' => ['Prerit Welfare Foundation mission and vision', ['Prerit Welfare Foundation values', 'NGO mission and vision for education and skill development']],
        'achievements' => ['Prerit Welfare Foundation achievements', ['Prerit Welfare Foundation milestones', 'Prerit Welfare Foundation completed campaigns', 'Prerit Welfare Foundation past events']],
        'ngo-details' => ['Prerit Welfare Foundation registration details', ['U88900BR2025NPL079155', 'BR/2025/0847299', 'Prerit Welfare Foundation CIN', 'Prerit Welfare Foundation MSME Udyam', 'is Prerit Welfare Foundation registered']],
        'certifications' => ['Prerit Welfare Foundation certificates', ['Prerit Welfare Foundation registration certificate', 'Prerit Welfare Foundation certificate of incorporation']],
        'management-body' => ['Prerit Welfare Foundation management', ['Prerit Welfare Foundation directors', 'Prerit Welfare Foundation governance']],
        'volunteers' => ['Prerit Welfare Foundation team', ['Prerit Welfare Foundation volunteers', 'join Prerit Welfare Foundation team']],
        'programs' => ['Prerit Welfare Foundation programs', ['NGO programmes for education health and skills', 'workshops seminars and community events', 'programs and events']],
        'programs/education-literacy' => ['education programme for underprivileged children', ['community learning circles', 'literacy drive NGO', 'adult literacy programme', 'free learning sessions for children', 'donate books and stationery to NGO']],
        'programs/healthcare' => ['free health camp NGO', ['health awareness session', 'health screening camp', 'women and elderly health awareness', 'health scheme awareness']],
        'programs/women-empowerment' => ['women empowerment programme', ['self help group support for women', 'skill training for women', 'financial literacy for women', 'what is a self help group']],
        'programs/youth-empowerment' => ['youth empowerment programme', ['mentoring for young people', 'leadership and life skills for youth', 'community service opportunities for students', 'youth empowerment NGO India']],
        'programs/rural-development' => ['rural development NGO', ['village awareness drive', 'help with government schemes in villages', 'rural livelihood and skill building']],
        'programs/environment-protection' => ['tree plantation drive', ['cleanliness drive volunteer', 'waste segregation awareness', 'school eco club activities', 'environment protection NGO']],
        'skill-development' => ['skill development programme for youth', ['free skill training for youth', 'vocational training NGO', 'digital skills training for jobs', 'fake job offer in NGO name']],
        'programs/digital-learning' => ['digital literacy programme', ['digital literacy for rural communities', 'smartphone training for senior citizens', 'basic computer training for women', 'safe digital payments awareness', 'donate old laptop or phone NGO']],
        'programs/scholarship-educational-support' => ['educational support for students from poor families', ['study material support for students', 'government scholarship guidance', 'mentoring for students']],
        'programs/food-distribution' => ['ration kit distribution', ['food distribution drive', 'donate ration kit', 'food support for needy families']],
        'programs/disaster-relief' => ['flood relief NGO', ['disaster relief volunteer', 'fake flood relief collection']],
        'programs/ai-artificial-intelligence' => ['AI workshop for students', ['generative AI workshop for beginners', 'AI awareness session for teachers', 'responsible AI training']],
        'programs/robotics-technology' => ['robotics workshop for students', ['AI and robotics workshops for students', 'STEM activities for school students', 'IoT workshop for beginners', 'robotics workshop for schools', 'sensors and electronics workshop']],
        'programs/machine-learning-data-science' => ['machine learning workshop for beginners', ['Python workshop for beginners', 'data science workshop for students', 'data visualisation workshop']],
        'programs/digital-marketing' => ['digital marketing workshop', ['SEO workshop for beginners', 'social media marketing for small business', 'personal branding workshop', 'digital marketing for NGOs and community groups']],
        'programs/cyber-security' => ['cyber safety awareness programme', ['cyber awareness workshop for schools', 'phishing and UPI fraud awareness', 'online fraud awareness for senior citizens', 'cyber hygiene training', 'cyberbullying awareness for students']],
        'programs/career-entrepreneurship' => ['career guidance workshop', ['resume writing workshop', 'interview skills training', 'freelancing guidance for beginners', 'entrepreneurship workshop for youth', 'career planning session for students']],
        'programs/fitness-yoga-wellness' => ['community yoga sessions', ['yoga awareness programme', 'meditation and breathing session', 'fitness awareness programme for students', 'wellness workshop for workplaces']],
        'programs/music-arts-culture' => ['music and arts workshops', ['singing workshop', 'cultural programme for community', 'art and craft workshop for children', 'music workshop for children NGO']],
        'programs/hackathons-innovation' => ['hackathon for students', ['innovation challenge for students', 'social impact hackathon', 'coding challenge for college students', 'hackathon for school students', 'sponsor a hackathon']],
        'programs/events-seminars' => ['seminars and webinars on social issues', ['public seminar on education and health', 'host a seminar at your school or college', 'community outreach events']],
        'events' => ['upcoming workshops and events', ['Prerit Welfare Foundation events', 'register for workshop', 'upcoming health camp']],
        'awareness-calendar' => ['awareness days calendar India', ['national and international days list', 'health awareness days list', 'how to celebrate an awareness day in school']],
        'awareness-campaign' => ['community awareness programme', ['awareness campaign on health and hygiene', 'girl child education awareness', 'invite NGO for awareness session in school', 'street play awareness campaign']],
        'become-a-volunteer' => ['NGO volunteer opportunities', ['online volunteering opportunities', 'volunteer certificate NGO']],
        'volunteer-teacher' => ['volunteer teacher', ['teach underprivileged children volunteer', 'online volunteer teaching', 'volunteer teacher NGO India', 'volunteer teacher authorization letter']],
        'volunteer-staff' => ['NGO jobs', ['work with an NGO', 'programme coordinator NGO job', 'NGO job fee fraud']],
        'internships' => ['NGO internship for students', ['online NGO internship', 'social work internship', 'NGO internship with certificate', 'research and documentation internship']],
        'membership' => ['become a member of an NGO', ['NGO membership', 'NGO life membership', 'student membership NGO']],
        'partner-with-us' => ['NGO partnership opportunities', ['partner with an NGO for school workshops', 'CSR partnership with NGO', 'become a trainer or speaker volunteer', 'sponsor a workshop or hackathon', 'host an awareness session in your community', 'NGO collaboration with colleges']],
        'donate' => ['donate to NGO online', ['donate to Prerit Welfare Foundation', 'donate for education of poor children', 'donate via UPI to NGO', 'NGO donation receipt']],
        'campaigns' => ['NGO fundraising campaigns', ['donate to flood relief campaign', 'education support campaign for children', 'Prerit Welfare Foundation campaigns']],
        'start-a-fundraiser' => ['start a fundraiser for a cause', ['fundraise for an NGO', 'crowdfunding for social cause India', 'raise funds for school kits for children']],
        'financial-transparency' => ['Prerit Welfare Foundation financial transparency', ['NGO financial transparency', 'how NGO donations are verified']],
        'utilization-reports' => ['campaign fund utilisation report', ['how NGO donations are used']],
        'impact-reports' => ['Prerit Welfare Foundation annual report', ['Prerit Welfare Foundation impact report']],
        'blog' => ['Prerit Welfare Foundation blog', ['digital safety and digital literacy guides', 'volunteering guide India', 'NGO blog India education and skills']],
        'media' => ['Prerit Welfare Foundation press release', ['Prerit Welfare Foundation media contact']],
        'digital-resources' => ['free awareness guides and toolkits', ['volunteer toolkit']],
        'success-stories' => ['Prerit Welfare Foundation success stories', []],
        'gallery' => ['Prerit Welfare Foundation photos', ['Prerit Welfare Foundation videos']],
        'schemes' => ['help to apply for government welfare schemes', ['government schemes for women', 'welfare scheme eligibility check']],
        'request-help' => ['apply for help from an NGO', ['ration support for poor family', 'education help for poor students', 'NGO help for medical treatment']],
        'beneficiary-support' => ['how to get help from an NGO', ['documents needed to request NGO help', 'who can get help from NGO', 'NGO support request process']],
        'scholarship' => ['apply for scholarship support', ['NGO scholarship for students', 'scholarship for economically weaker students']],
        'contact' => ['Prerit Welfare Foundation contact', ['Prerit Welfare Foundation address', 'Prerit Welfare Foundation email', 'Prerit Welfare Foundation Noida office']],
        'faq' => ['Prerit Welfare Foundation FAQ', ['is Prerit Welfare Foundation genuine']],
        'report-fraud' => ['report fraud using Prerit Welfare Foundation name', ['Prerit Welfare Foundation fake job offer', 'NGO job fee scam', '1930 cyber helpline']],
        'verify-letter' => ['verify Prerit Welfare Foundation authorization letter', ['volunteer teacher authorization letter verification', 'check authorization letter reference number', 'fake NGO authorization letter']],
        'child-protection-policy' => ['NGO child protection policy', ['POCSO reporting duty NGO', 'two adult rule volunteers', 'child safeguarding policy for volunteers']],
        'volunteer-policy' => ['NGO volunteer policy and code of conduct', ['volunteer code of conduct']],
    ];
}

/** Keywords into seo_meta (WP-1 table): a row is created or only its empty keyword fields are filled. */
function migration_877_seo_content_focus_keywords(): void
{
    if (!table_exists('seo_meta') || !column_exists('seo_meta', 'focus_keyword') || !column_exists('seo_meta', 'secondary_keywords')) {
        return;   // WP-1 not installed: nothing to fill (the admin can enter keywords later in Admin > SEO)
    }
    if (migration_once_done('877_focus_keywords')) {
        return;   // filled once: keywords the owner cleared later stay empty
    }
    $changes = [];
    foreach (seo_content_keywords() as $slug => [$focus, $secondary]) {
        $pageId = (int) db_value('SELECT id FROM pages WHERE slug = ?', [$slug]);
        if (!$pageId || preg_match('/[\x{0900}-\x{097F}]/u', $focus . implode(',', $secondary))) {
            continue;
        }
        $focus = mb_substr($focus, 0, 120);
        $list = mb_substr(implode(', ', array_slice($secondary, 0, 10)), 0, 500);
        $n = db_query("INSERT INTO seo_meta (entity_type, entity_id, focus_keyword, secondary_keywords) VALUES ('page', ?, ?, ?)
                       ON DUPLICATE KEY UPDATE
                         focus_keyword = IF(focus_keyword IS NULL OR focus_keyword = '', VALUES(focus_keyword), focus_keyword),
                         secondary_keywords = IF(secondary_keywords IS NULL OR secondary_keywords = '', VALUES(secondary_keywords), secondary_keywords)",
            [$pageId, $focus, $list !== '' ? $list : null])->rowCount();
        if ($n > 0) {
            $changes[] = $slug;
        }
    }
    migration_once_mark('877_focus_keywords');
    seo_content_log('Focus keywords filled for pages without keywords', $changes);
}

/**
 * One-time markers for sites whose content migrations 841 and 870-877 ran before those migrations recorded them
 * (a database upgraded with an earlier build of this release, or a copy of one): every step of a migration that is
 * already in schema_migrations counts as done, so its pages, sections and links are never added again.
 * Nothing to do when any marker exists - the migrations then ran with this code and recorded their own steps.
 */
function migration_878_seo_content_once_markers(): void
{
    if ((int) db_value("SELECT COUNT(*) FROM website_settings WHERE setting_key LIKE 'migration\\_once\\_%'") > 0 || !table_exists('schema_migrations')) {
        return;
    }
    $steps = [
        'migration_841_seo_consent_mode_privacy' => ['841_privacy_consent_mode'],
        'migration_870_seo_content_page_meta' => ['870_page_meta'],
        'migration_871_seo_content_home_title' => ['871_home_title'],
        'migration_872_seo_content_cannibalisation_copy' => ['872_cannibalisation_copy'],
        'migration_873_seo_content_utility_help' => ['873_verify_letter_help', '873_application_status_help'],
        'migration_874_seo_content_footer_workshops' => ['874_footer_workshops'],
        'migration_875_seo_content_office_addresses' => ['875_offices_contact', '875_offices_ngo_details'],
        'migration_876_seo_content_partner_page' => ['876_partner_page', '876_partner_footer_link', '876_contact_card_link'],
        'migration_877_seo_content_focus_keywords' => ['877_focus_keywords'],
    ];
    $applied = array_flip(array_column(db_rows('SELECT name FROM schema_migrations'), 'name'));
    foreach ($steps as $fn => $keys) {
        if (isset($applied[$fn])) {
            foreach ($keys as $key) {
                migration_once_mark($key);
            }
        }
    }
}

/**
 * 879: repeat 875 (and the contact description of 870) for sites where they ran in the same request as migration 700,
 * which created corporate_office_address after the settings cache had been read. Guarded: the office block only where
 * the 875 once-marker is missing, the description only while it is still exactly the generic text 870 wrote.
 */
function migration_879_seo_content_office_addresses_retry(): void
{
    settings_all(true);
    migration_875_seo_content_office_addresses();
    $generic = 'Contact Prerit Welfare Foundation by email or through the enquiry form about volunteering, partnerships, media, donations or support from our programmes.';
    $dynamic = seo_content_dynamic_descriptions()['contact'] ?? '';
    if ($dynamic !== '' && $dynamic !== $generic) {
        db_query("UPDATE pages SET meta_description = ? WHERE slug = 'contact' AND template = 'contact' AND BINARY meta_description = BINARY ?", [$dynamic, $generic]);
    }
}
