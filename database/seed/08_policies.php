<?php
/**
 * CORE seed (always runs, also without demo data) — unit content_pages: policy, legal and disclosure pages of the
 * website, the "Policies & Disclosures" hub, plus the system content pages vision-mission, leadership, research and
 * campus-life when they are missing. Rendered by page.php (sticky table of contents from the h2s, last updated date,
 * print button, "Policies & Disclosures" list).
 *
 * Ownership (never touches pages it did not create):
 *   - a slug that does not exist yet is INSERTED and remembered in setting `seed_policies_owned` (group system);
 *   - a slug this seeder created earlier is UPDATED — unless an editor has changed it since (pages.updated_by is set by
 *     the admin), so completed placeholders are never overwritten;
 *   - any other existing page with the same slug (e.g. privacy-policy / refund-policy from 05_cms_core) is left alone.
 * /refund-policy keeps working either way: page.php redirects it to /refund-and-cancellation-policy when no
 * published refund-policy page exists (missing, or unpublished by an editor to retire the short version).
 *
 * Content uses verified institutional details from settings (name, address, phone, emails, website, established year)
 * at seed time. Every legal / statutory detail that GIMT still has to verify is written as
 * "[To be confirmed by GIMT: …]" — page.php highlights these so the institute can complete them in CMS → Pages.
 */
return function (array $opts): void {
    require_once APP_ROOT . '/app/services/cms.php';

    $s = fn (string $k, string $d = '') => (string) (setting($k, $d) ?: $d);
    $name = $s('institute_name', 'Global Institute of Management & Technology');
    $short = $s('institute_short_name', 'GIMT');
    $address = $s('address', 'Plot No. 123, Knowledge Park, Greater Noida, Uttar Pradesh 201310, India');
    $phone = $s('phone', '+91 9955446477');
    $email = $s('email', 'info@gimt.ac.in');
    $admEmail = $s('admission_email', 'admissions@gimt.ac.in');
    $supEmail = $s('support_email', 'support@gimt.ac.in');
    $website = $s('website', 'www.gimt.ac.in');
    $hours = $s('office_hours', 'Mon - Sat, 9:00 AM - 6:00 PM');
    $established = (int) $s('established_year', '0');
    $affiliation = $s('affiliation', '');

    $N = e($name);
    $S = e($short);
    $ph = fn (string $what) => '[To be confirmed by GIMT: ' . e($what) . ']';
    $p = fn (string ...$paras) => implode('', array_map(fn ($x) => '<p>' . $x . '</p>', $paras));
    $ul = fn (array $items) => '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
    $ol = fn (array $items) => '<ol><li>' . implode('</li><li>', $items) . '</li></ol>';
    $mail = fn (string $m) => '<a href="mailto:' . e($m) . '">' . e($m) . '</a>';
    $tel = fn (string $t) => '<a href="tel:' . e(preg_replace('/[^\d+]/', '', $t)) . '">' . e($t) . '</a>';
    $a = fn (string $url, string $label) => '<a href="' . e($url) . '">' . e($label) . '</a>';
    $table = function (array $head, array $rows): string {
        $h = '<table><thead><tr><th>' . implode('</th><th>', $head) . '</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $h .= '<tr><td>' . implode('</td><td>', $r) . '</td></tr>';
        }
        return $h . '</tbody></table>';
    };
    $doc = function (string $intro, array $sections): string {
        $html = $intro;
        foreach ($sections as $h => $body) {
            $html .= '<h2>' . e($h) . '</h2>' . $body;
        }
        return $html;
    };
    $contactBlock = $p('<strong>' . $N . '</strong><br>' . e($address) . '<br>Phone: ' . $tel($phone) . ' · Email: ' . $mail($email) . '<br>Office hours: ' . e($hours));
    $b = function (string $id, string $type, array $data, array $settings = []) {
        return ['id' => $id, 'type' => $type, 'hidden' => false, 'settings' => $settings + ['anchor' => '', 'background' => 'white', 'padding' => 'normal'], 'data' => $data];
    };

    $pages = [];

    /* ------------------------------------------------------------------ Hub */
    $pages['policies'] = ['Policies & Disclosures', 'Rules, rights and statutory information in one place',
        'The institute policies, student rights, statutory committees and mandatory disclosures of ' . $name . ' — published for students, parents, staff and regulators.',
        $doc($p('This section brings together the policies that govern admission, fees, examinations, conduct and student welfare at ' . $N . ' (' . $S . '), the statutory committees every student can approach, and the information the institute discloses to the public.',
            'Each policy is reviewed periodically. Where a policy refers to the regulations of the affiliating university, the University Grants Commission (UGC), the All India Council for Technical Education (AICTE) or the Government of Uttar Pradesh, those regulations prevail in case of any difference.'), [
            'Raise a concern' => $ul([
                '<strong>Ragging</strong> — contact the Anti-Ragging Squad or the National Anti-Ragging Helpline; see the ' . $a('/anti-ragging-policy', 'Anti-Ragging Policy') . '.',
                '<strong>Sexual harassment</strong> — write to the Internal Complaints Committee; see ' . $a('/internal-complaints-committee', 'Internal Complaints Committee (POSH)') . '.',
                '<strong>Academic, examination, fee or service grievances</strong> — see the ' . $a('/grievance-redressal-policy', 'Grievance Redressal Policy') . '.',
                '<strong>Discrimination or accessibility barriers</strong> — see the ' . $a('/equal-opportunity-policy', 'Equal Opportunity Policy') . ' and the ' . $a('/accessibility', 'Accessibility statement') . '.',
            ]),
            'Questions about a policy' => $contactBlock,
        ]), [], null, 30, 'default'];

    /* ------------------------------------------------------------------ Website & legal */
    $pages['privacy-policy'] = ['Privacy Policy', 'How we collect, use and protect your personal information',
        'How ' . $name . ' collects, uses, shares, stores and protects personal information received through this website, online applications, enquiries and campus services.',
        $doc($p($N . ' ("' . $S . '", "we", "us") respects your privacy. This policy explains what personal information we collect through this website, the online application, enquiry and contact forms, newsletters and event registrations, why we collect it, and the choices and rights you have.',
            'We process personal information in line with applicable Indian law, including the Information Technology Act, 2000 and the rules made under it, and the Digital Personal Data Protection Act, 2023 as its provisions come into force.'), [
            'Information we collect' => $ul([
                '<strong>Details you give us</strong> — name, email address, mobile number, city, program of interest and the messages you send through enquiry, callback, contact, newsletter and event registration forms.',
                '<strong>Application details</strong> — personal, family, academic and category information and the documents you upload with an online application (photographs, marksheets, identity proof, certificates).',
                '<strong>Technical information</strong> — IP address, browser and device type, pages visited, referring website and campaign parameters (such as utm_source), recorded in server logs and, where enabled, by analytics tools.',
            ]),
            'How we use your information' => $ul([
                'To answer enquiries and provide admission counselling by phone, email, SMS or WhatsApp when you have asked us to contact you.',
                'To process applications: verify eligibility and documents, schedule tests or interviews, communicate decisions and complete enrolment.',
                'To send updates you have requested, such as newsletters and event reminders. You can unsubscribe at any time.',
                'To keep the website and our systems secure, prevent spam and abuse, and improve our services.',
                'To meet legal, regulatory and accreditation obligations.',
            ]),
            'Legal basis and consent' => $p('We collect personal information when you submit it to us for a specific purpose (for example, an enquiry or an application) and with your consent where the law requires it. You may withdraw consent for marketing communication at any time; withdrawal does not affect processing that is needed to complete an application you have submitted or to meet legal obligations.'),
            'Sharing of information' => $p('We do not sell or rent personal information. We share it only:') . $ul([
                'with the affiliating university, regulators and government authorities where required for admission, registration, examinations, scholarships or by law;',
                'with service providers that host our systems or deliver email, SMS and messages on our behalf, under confidentiality obligations;',
                'with recruiters, only for students who register for campus placement and only the details needed for recruitment.',
            ]),
            'Cookies and similar technologies' => $p('We use cookies and browser storage to keep forms secure, remember your preferences and, where enabled, measure how the website is used. Details are in our ' . $a('/cookie-policy', 'Cookie Policy') . '.'),
            'Data retention' => $p('Enquiry data is kept only as long as needed to respond and follow up on your interest in admission ' . $ph('retention period for enquiries, e.g. two years') . '. Application and student records are kept for the periods required by academic rules, the affiliating university and law.'),
            'Security' => $p('Personal information is stored on secured servers with access limited to authorised staff, encrypted connections (HTTPS), role-based permissions, activity logs and regular backups. No method of transmission over the internet is completely secure, but we work to protect your information and to respond promptly to any incident.'),
            'Your rights' => $p('You may ask us for a summary of the personal information we hold about you, ask us to correct or update it, ask us to erase information that is no longer needed, withdraw consent, and nominate another person to exercise these rights on your behalf where the law allows. Write to the contact below; we respond within the time required by law ' . $ph('response time committed by the institute') . '.'),
            'Children' => $p('Applicants under 18 years of age should submit applications and enquiries with the knowledge of a parent or guardian, whose details we collect as part of the application.'),
            'Contact and grievance officer' => $p('For privacy questions, requests or complaints, contact the Grievance Officer for data protection: ' . $ph('name and designation of the Grievance Officer') . ', ' . $N . ', ' . e($address) . ' — email ' . $mail($email) . ', phone ' . $tel($phone) . '.'),
            'Changes to this policy' => $p('We may update this policy from time to time. The date at the top of this page shows when it was last updated.'),
        ]), [], 'policies', 31, 'default'];

    $pages['terms-and-conditions'] = ['Terms & Conditions', 'Terms of use of the ' . $short . ' website and online services',
        'The terms that apply when you use the ' . $name . ' website, the online application and other online services.',
        $doc($p('By accessing this website or using the online services of ' . $N . ' you agree to these terms. If you do not agree, please do not use the website.'), [
            'Use of the website' => $p('The website provides information about ' . $S . ', its programs, admissions and activities. You agree to use it lawfully and not to attempt unauthorised access to any part of our systems, upload harmful code, scrape the website at a rate that affects other users, or misuse our forms (for example, by submitting another person’s details without their consent).'),
            'Accuracy of information' => $p('We try to keep program details, fees, eligibility criteria and dates accurate and current. They may change because of decisions of the institute, the affiliating university or regulators. The admission brochure, the offer letter and official notices issued by ' . $S . ' prevail in case of any difference. See also our ' . $a('/disclaimer', 'Disclaimer') . '.'),
            'Online applications and payments' => $p('You are responsible for the accuracy of the information and documents you submit. Applications with false or misleading information may be rejected, and admission may be cancelled at any stage if such information comes to light later. Fees are payable only through the payment modes notified by the institute; refunds follow the ' . $a('/refund-and-cancellation-policy', 'Refund & Cancellation Policy') . '.'),
            'Intellectual property' => $p('The ' . $S . ' name, logo, text, photographs, graphics and design of this website belong to ' . $S . ' or its licensors. You may view and print pages for personal, non-commercial use; any other reproduction requires written permission.'),
            'Links to other websites' => $p('Links to external websites (for example, regulators, scholarship portals or social networks) are provided for convenience. ' . $S . ' is not responsible for their content, availability or privacy practices.'),
            'Limitation of liability' => $p('To the extent permitted by law, ' . $S . ' is not liable for any loss arising from the use of, or inability to use, this website, including interruptions, errors or unauthorised access beyond our reasonable control.'),
            'Governing law and jurisdiction' => $p('These terms are governed by the laws of India. Courts having jurisdiction over the institute’s location have exclusive jurisdiction ' . $ph('jurisdiction, e.g. courts at Gautam Buddh Nagar, Uttar Pradesh') . '.'),
            'Contact' => $contactBlock,
        ]), [], 'policies', 32, 'default'];

    $pages['disclaimer'] = ['Disclaimer', 'About the information published on this website',
        'The information on the ' . $name . ' website is provided in good faith for general guidance; official notices, the prospectus and regulations prevail.',
        $doc($p('The content of this website is published by ' . $N . ' in good faith for general information about the institute, its programs and activities.'), [
            'Information may change' => $p('Program structures, eligibility criteria, intake, fees, scholarships, schedules and other details are subject to change by the institute, the affiliating university, regulators and government authorities. Always check the current prospectus, official notices and your offer letter, or contact the admissions office at ' . $mail($admEmail) . ' before making a decision.'),
            'No guarantee of admission or placement' => $p('Information about placements, recruiters, packages and alumni outcomes describes past results and is not a promise or guarantee of admission, employment or any particular salary. Admission is subject to eligibility, merit and the availability of seats.'),
            'Official documents prevail' => $p('In case of any difference between the website and official documents — the prospectus, admission notices, fee notices, examination notifications, university ordinances or regulator notifications — the official documents prevail.'),
            'Photographs and illustrations' => $p('Some photographs, illustrations and icons on this website are representative and may not depict the exact facilities, people or events described alongside them.'),
            'External links' => $p('Links to third-party websites are provided for convenience only. ' . $S . ' does not endorse and is not responsible for their content, availability, accuracy or privacy practices.'),
            'Beware of fraud' => $p($S . ' does not charge any capitation fee or donation for admission and does not authorise agents to collect fees on its behalf. Fees are paid only through the payment modes notified by the institute against an official receipt. If anyone asks you for money with a promise of admission, report it to ' . $mail($email) . ' or ' . $tel($phone) . '.'),
            'Contact' => $contactBlock,
        ]), [], 'policies', 33, 'default'];

    $pages['cookie-policy'] = ['Cookie Policy', 'Cookies and browser storage on this website',
        'Which cookies and browser storage the ' . $short . ' website uses, why, and how you can control them.',
        $doc($p('Cookies are small text files that a website stores in your browser. This website also uses your browser’s local and session storage for a few preferences. This policy explains what we use and how you can control it.'), [
            'Strictly necessary' => $p('These are needed for the website to work and cannot be switched off in our systems:') . $table(['Name', 'Purpose', 'Duration'], [
                ['<code>GIMTSESSID</code>', 'Keeps your browsing session and protects forms against cross-site request forgery (CSRF).', 'Until you close the browser / session timeout'],
                ['<code>gimt_remember</code>', 'Keeps authorised staff signed in to the administration portal when they choose “Remember me”. Not used for visitors.', 'Up to the period chosen at sign-in'],
            ]),
            'Visitor counter' => $p('The visitor numbers shown at the foot of every page are counted with two first-party cookies. They contain a random identifier only — no name, email or other personal details — and the identifier is stored on our server only in hashed form. They are not shared with anyone and are not set at all when your browser sends a “Do Not Track” or “Global Privacy Control” signal.') . $table(['Name', 'Purpose', 'Duration'], [
                ['<code>gimt_vid</code>', 'Anonymous visitor identifier, so that a returning visitor is counted once as a unique visitor.', '1 year'],
                ['<code>gimt_vs</code>', 'Groups the pages you view into one visit.', '30 minutes after your last page view'],
            ]),
            'Preferences stored in your browser' => $p('These items are kept in your own browser’s storage rather than in cookies, and you can clear them at any time:') . $table(['Item', 'Purpose'], [
                ['<code>gimt.visited</code> (session storage)', 'Shows the short loading screen only on your first page view in a session.'],
                ['<code>gimt.view.*</code> (local storage)', 'Remembers whether you prefer the grid, list, compact or detailed view on listing pages.'],
                ['<code>gimt.bar.*</code> (local storage)', 'Remembers notice bars you have dismissed.'],
                ['<code>gimt.apply.draft.v1</code> (local storage)', 'Saves the text you type in the online application form so you do not lose it; uploaded documents are never stored. Cleared when the application is submitted.'],
                ['<code>gimt-chat:*</code> (session storage)', 'Keeps your conversation with the chat assistant while you move between pages, and remembers that its greeting was shown. Cleared when you close the tab.'],
            ]),
            'Analytics and advertising' => $p('When the institute enables Google Analytics, Google Tag Manager or the Meta Pixel, these services set their own cookies (for example <code>_ga</code> or <code>_fbp</code>) to measure visits and the performance of our admission campaigns. They are used only when enabled and, where required by law, after you give consent. ' . $ph('list of analytics / advertising tools currently enabled and the consent mechanism used')),
            'Embedded content' => $p('Videos from YouTube or Vimeo and maps from Google Maps load only when you open them. Those services may then set their own cookies under their own privacy policies.'),
            'Managing cookies' => $p('You can block or delete cookies and site data in your browser settings. If you block strictly necessary cookies, forms such as enquiries and applications will not work. Clearing site data also removes the preferences listed above.'),
            'Contact' => $p('Questions about this policy: ' . $mail($email) . '. See also our ' . $a('/privacy-policy', 'Privacy Policy') . '.'),
        ]), [], 'policies', 34, 'default'];

    /* ------------------------------------------------------------------ Admissions & money */
    $pages['refund-and-cancellation-policy'] = ['Refund & Cancellation Policy', 'Withdrawal of admission, refund of fees and failed payments',
        'How fees are refunded when a student withdraws or an admission is cancelled at ' . $name . ', and how online payment failures are handled.',
        $doc($p('This policy explains how fees are refunded when an admitted student withdraws from a program or an admission is cancelled, and how failed or duplicate online payments are handled. It follows the fee-refund guidelines of the regulators and the affiliating university applicable to each program; in case of any conflict, those guidelines prevail.'), [
            'Application and registration fee' => $p('The application or registration fee, where charged, is non-refundable, except when the institute withdraws the program applied for.'),
            'Withdrawal of admission by the student' => $p('A student who wishes to withdraw must apply in writing to the admissions office. The refund depends on when the written request is received:') . $table(['Written request received', 'Refund of fees paid'], [
                ['15 days or more before the notified last date of admission', '100%, less a processing fee of up to 5% or ₹5,000, whichever is lower'],
                ['Less than 15 days before the notified last date of admission', '90%'],
                ['Up to 15 days after the notified last date of admission', '80%'],
                ['16 to 30 days after the notified last date of admission', '50%'],
                ['More than 30 days after the notified last date of admission', 'No refund'],
            ]) . $p($ph('confirm the refund schedule adopted for the 2026-27 session as per the applicable UGC / AICTE fee-refund notification and the affiliating university')),
            'Seat vacated and filled' => $p('Where the regulations provide for it, if the seat vacated by a withdrawing student is filled by another candidate by the last date of admission, the fees collected are refunded after deducting the processing fee.'),
            'Cancellation by the institute' => $p('If the institute cancels a program or cannot offer the seat confirmed to a student, all fees paid are refunded in full. Admission cancelled because of false information, ineligibility or disciplinary action is governed by the ' . $a('/admission-policy', 'Admission Policy') . ' and the ' . $a('/code-of-conduct', 'Code of Conduct') . '.'),
            'Hostel, transport and other fees' => $p('Hostel and transport fees are refunded on a pro-rata basis for the unused period after deducting dues. Caution money and security deposits are refunded after all dues are cleared ' . $ph('timeline for refund of caution money / security deposits') . '.'),
            'Original documents' => $p('Original certificates and documents submitted at admission are never withheld to compel payment of fees and are returned on withdrawal.'),
            'Failed, duplicate or excess online payments' => $p('If your account is debited but the payment is not confirmed, or you pay twice, write to the accounts office with the transaction reference. Verified excess or duplicate amounts are refunded to the original payment method ' . $ph('processing time for failed / duplicate online payments') . '.'),
            'How to apply for a refund' => $ol([
                'Submit the withdrawal / refund form at the admissions or accounts office, or email ' . $mail($admEmail) . ' from the email address used in your application.',
                'Mention your name, application or enrolment number, program, the reason for withdrawal and the bank account details of the student or parent (with a cancelled cheque).',
                'Attach the original fee receipts. The office acknowledges your request and informs you of the amount payable under this policy.',
            ]),
            'Processing time' => $p('Approved refunds are credited to the bank account of the student or parent; cash refunds are not made ' . $ph('refund processing time, e.g. within 15 working days of approval') . '. For disputes, see the ' . $a('/grievance-redressal-policy', 'Grievance Redressal Policy') . '.'),
        ]), [], 'policies', 35, 'default'];

    $pages['admission-policy'] = ['Admission Policy', 'Fair, transparent and merit-based admission',
        'The principles and process that govern admission to the programs of ' . $name . ': eligibility, selection, reservation, documents and cancellation.',
        $doc($p('Admission to ' . $N . ' is fair, transparent and based on merit, in accordance with the norms of the affiliating university, the regulators and the Government of Uttar Pradesh as applicable to each program.'), [
            'Eligibility' => $p('Each program has minimum eligibility criteria (qualifying examination, subjects and marks) published on its program page and in the prospectus — see ' . $a('/programs', 'Programs') . ' and ' . $a('/admissions', 'Admissions') . '. Candidates awaiting their results may apply; their admission remains provisional until the final result is submitted by the date notified.'),
            'Selection process' => $p('Selection is made on the basis of the qualifying examination, the entrance test or national / state examination score accepted for the program, and a personal interview where applicable, as notified for the session. ' . $ph('selection criteria and weightage for each program for 2026-27')),
            'Reservation and relaxations' => $p('Reservation of seats and relaxation of eligibility for reserved categories, persons with disabilities and other groups are applied as per the rules of the Government of Uttar Pradesh, the affiliating university and the regulators applicable to each program. ' . $ph('category-wise reservation applicable to the institute')),
            'Documents' => $p('Applicants submit copies of their marksheets and certificates, identity proof, photographs and, where applicable, category, income, disability and migration certificates. Originals are verified at the time of admission and returned immediately.'),
            'No capitation fee' => $p($S . ' does not demand or accept any capitation fee, donation or payment other than the fees notified by the institute, against an official receipt. Report any such demand to ' . $mail($email) . '.'),
            'Anti-ragging undertaking' => $p('Every admitted student and their parent or guardian must submit the anti-ragging undertaking required by the regulations each academic year. See the ' . $a('/anti-ragging-policy', 'Anti-Ragging Policy') . '.'),
            'Cancellation of admission' => $p('Admission may be cancelled at any stage if information or documents submitted are found to be false or incomplete, if the candidate does not meet the eligibility criteria, or for serious misconduct. Refunds, where applicable, follow the ' . $a('/refund-and-cancellation-policy', 'Refund & Cancellation Policy') . '.'),
            'International and NRI applicants' => $p('Foreign nationals and NRI applicants are admitted in accordance with the rules of the affiliating university and the Government of India, including equivalence of qualifications and visa requirements. ' . $ph('availability of NRI / foreign national seats')),
            'Admission enquiries' => $p('Admissions office: ' . $mail($admEmail) . ' · ' . $tel($phone) . ' · ' . e($hours) . '. Apply online at ' . $a('/apply', 'Apply Now') . '.'),
        ]), [], 'policies', 36, 'default'];

    $pages['scholarship-policy'] = ['Scholarship Policy', 'Financial support for deserving students',
        'How scholarships and fee concessions are awarded at ' . $name . ': institute scholarships, government schemes, eligibility, renewal and withdrawal.',
        $doc($p($N . ' supports deserving students through institute scholarships and fee concessions and helps eligible students apply for government scholarship schemes. This policy sets out the principles; the scholarships available for each session and their amounts are published on the ' . $a('/admissions', 'Admissions') . ' page and in the prospectus.'), [
            'Institute scholarships' => $p('Institute scholarships may be awarded for academic merit, sports and co-curricular achievement, economically weaker sections and other categories notified for the session. ' . $ph('scholarship categories, percentages and number of awards for 2026-27')),
            'Government scholarships' => $p('Eligible students can apply for central and state government schemes, for example through the National Scholarship Portal (scholarships.gov.in) and the Government of Uttar Pradesh scholarship and fee-reimbursement portal. The institute verifies applications and forwards them within the deadlines notified by the authorities.'),
            'Eligibility and selection' => $ul([
                'Scholarships are awarded on the basis of documents submitted at admission and verified by the scholarship committee.',
                'Unless stated otherwise, a student may hold only one institute scholarship at a time; the highest applicable one is granted.',
                'Decisions of the scholarship committee are communicated in writing.',
            ]),
            'Renewal' => $p('Merit-based scholarships are renewed each year when the student passes all examinations in the first attempt, maintains the required academic performance and attendance, and has no disciplinary action. ' . $ph('renewal criteria, e.g. minimum CGPA and attendance')),
            'Withdrawal' => $p('A scholarship may be withdrawn if it was obtained on the basis of false information, if the student fails to meet the renewal conditions, discontinues the program or is found guilty of serious misconduct.'),
            'How to apply' => $ol([
                'Indicate the scholarship you are applying for in your admission application.',
                'Submit the supporting documents (marksheets, certificates, income or category certificates) at document verification.',
                'For government schemes, register on the relevant portal within the notified dates and submit the printed application to the scholarship cell.',
            ]),
            'Scholarship cell' => $p('Questions: ' . $mail($admEmail) . ' · ' . $tel($phone) . '. ' . $ph('name and contact of the scholarship coordinator')),
        ]), [], 'policies', 37, 'default'];

    $pages['fee-policy'] = ['Fee Policy', 'Fees, payment schedule, receipts and dues',
        'How fees are fixed, notified, paid and receipted at ' . $name . ', including instalments, late fees and no-dues requirements.',
        $doc($p('This policy explains how fees are notified and paid at ' . $N . '. The fee for each program and session is published on the ' . $a('/admissions', 'Admissions') . ' page and in the fee notice issued by the accounts office.'), [
            'Fee structure' => $p('Fees are fixed for each program and session in accordance with the norms of the regulators, the affiliating university and the fee-fixation authority of the state, where applicable. ' . $ph('authority approving the fee structure and reference of the approval') . ' The fee notice lists tuition and other heads (such as examination, library and development fees) and optional services such as hostel and transport.'),
            'No capitation fee' => $p('No capitation fee or donation is charged. Any amount paid must be against an official receipt issued by the institute.'),
            'Payment schedule' => $p('Fees are payable semester-wise or annually by the due dates in the fee notice. Instalments may be permitted in genuine cases on written request to the accounts office. ' . $ph('instalment rules')),
            'Payment modes' => $p('Fees can be paid at the accounts office or by bank transfer, UPI and other modes notified by the institute. Payments to individuals or third-party agents are not valid.'),
            'Receipts' => $p('An official receipt is issued for every payment. Keep your receipts; they are needed for refunds, scholarships, no-dues and income-tax purposes.'),
            'Late fee and dues' => $p('A late fee may be charged after the due date as notified in the fee notice ' . $ph('late fee amount / rule') . '. Students with outstanding dues may be asked to clear them before examination registration, results or certificates are released, as permitted by the regulations. Original documents deposited at admission are never withheld for dues.'),
            'Scholarships and concessions' => $p('Approved scholarships and concessions are adjusted against the fee as described in the ' . $a('/scholarship-policy', 'Scholarship Policy') . '.'),
            'Refunds' => $p('Refunds on withdrawal follow the ' . $a('/refund-and-cancellation-policy', 'Refund & Cancellation Policy') . '.'),
            'Accounts office' => $p('Questions about fees: ' . $mail($email) . ' · ' . $tel($phone) . ' · ' . e($hours) . '.'),
        ]), [], 'policies', 38, 'default'];

    /* ------------------------------------------------------------------ Academics */
    $pages['examination-policy'] = ['Examination Policy', 'Assessment, conduct of examinations and results',
        'How students are assessed at ' . $name . ': eligibility, internal and end-semester examinations, conduct, unfair means, results, re-evaluation and certificates.',
        $doc($p('Examinations at ' . $N . ' are conducted in accordance with the ordinances and examination rules of the affiliating university. This policy summarises how assessment works; the university rules prevail in case of any difference.'), [
            'Assessment scheme' => $p('Each course is assessed through continuous internal assessment (assignments, quizzes, presentations, projects and mid-semester tests) and an end-semester examination, in the proportion prescribed by the curriculum. Practical, project and internship work is assessed as specified for each course.'),
            'Eligibility to appear' => $p('Students must be registered for the semester, have the minimum attendance required by the university rules and have completed the internal assessment components to appear in the end-semester examination. ' . $ph('minimum attendance requirement, e.g. 75% in each course, and condonation rules')),
            'Examination schedule and admit cards' => $p('Examination schedules are published on the notice board and the website in advance. Admit cards are issued before the examination; students must carry the admit card and the institute identity card to every examination.'),
            'Conduct in the examination hall' => $ul([
                'Report to the examination hall at least 15 minutes before the start ' . $ph('reporting time') . '.',
                'Mobile phones, smart watches and other electronic devices are not permitted unless allowed by the instructions for that paper.',
                'Follow the instructions of the invigilators and do not communicate with other candidates during the examination.',
            ]),
            'Unfair means' => $p('Copying, possession of unauthorised material or devices, impersonation, communication with others and any attempt to influence examiners are treated as unfair means. Cases are examined by the unfair means committee and penalties are imposed as per the university rules, up to cancellation of the examination and debarment.'),
            'Results, re-evaluation and grievances' => $p('Results are declared as per the university schedule and grade cards are issued to students. Students may apply for scrutiny, re-evaluation or a copy of their answer script within the time and fee notified by the university. Other examination grievances can be raised under the ' . $a('/grievance-redressal-policy', 'Grievance Redressal Policy') . '.'),
            'Students with disabilities' => $p('Students with disabilities may request a scribe, compensatory time or other reasonable accommodation as permitted by the regulations. See the ' . $a('/equal-opportunity-policy', 'Equal Opportunity Policy') . '.'),
            'Certificates and verification' => $p('Marksheets and certificates issued by the institute carry a unique number and can be verified online at ' . $a('/verify-certificate', 'Verify a Certificate') . '.'),
            'Examination cell' => $p('Contact: ' . $mail($supEmail) . ' · ' . $tel($phone) . '. ' . $ph('name and contact of the Controller of Examinations / examination cell')),
        ]), [], 'policies', 39, 'default'];

    $pages['code-of-conduct'] = ['Code of Conduct', 'Respect, integrity and responsibility on campus and online',
        'The standards of behaviour expected from students, faculty and staff of ' . $name . ', and how breaches are handled.',
        $doc($p('Our community depends on respect, integrity and responsibility. This code applies to every student, faculty member and staff member of ' . $N . ' on campus, in hostels and transport, during internships, field visits and events, and online when representing the institute.'), [
            'Respect for others' => $ul([
                'Treat every member of the community with dignity, regardless of gender, caste, religion, region, language, disability or background.',
                'Ragging in any form is a criminal offence and strictly prohibited — see the ' . $a('/anti-ragging-policy', 'Anti-Ragging Policy') . '.',
                'Sexual harassment is prohibited — see ' . $a('/internal-complaints-committee', 'Internal Complaints Committee (POSH)') . '.',
            ]),
            'Academic integrity' => $p('Submit your own work, acknowledge sources, and do not plagiarise, fabricate data, cheat in examinations or help others to do so. Breaches are dealt with under the ' . $a('/examination-policy', 'Examination Policy') . ' and the rules of the affiliating university.'),
            'Attendance and punctuality' => $p('Attend classes, laboratories and examinations regularly and on time. Attendance requirements are set by the university rules and the academic regulations of each program.'),
            'Campus life' => $ul([
                'Carry your institute identity card on campus and show it when asked.',
                'The campus is free of tobacco, alcohol and drugs. Possession or use of these substances, weapons or anything dangerous is prohibited.',
                'Take care of institute property, laboratories, the library and hostels; damage is recoverable.',
                'Follow the dress code and the rules of the hostel, library, laboratories and transport as notified. ' . $ph('dress code, if any'),
            ]),
            'Digital conduct' => $p('Use the institute’s network, email and systems responsibly. Do not share your passwords, access other people’s accounts, post offensive or defamatory content about members of the community, or record and share others without their consent.'),
            'Faculty and staff' => $p('Faculty and staff are expected to act fairly and professionally, maintain confidentiality of student records, avoid conflicts of interest and follow the service rules of the institute.'),
            'Disciplinary process' => $p('Complaints of misconduct are inquired into by the disciplinary committee, which gives the person concerned a fair opportunity to be heard. Actions range from a warning, fine or suspension to expulsion, depending on the seriousness of the breach and the applicable regulations. Appeals can be made under the ' . $a('/grievance-redressal-policy', 'Grievance Redressal Policy') . '.'),
            'Reporting a concern' => $p('Report concerns to a faculty mentor, the head of department or ' . $mail($email) . '. Reports are handled confidentially, and no one is penalised for raising a concern in good faith.'),
        ]), [], 'policies', 40, 'default'];

    /* ------------------------------------------------------------------ Student welfare & statutory committees */
    $pages['anti-ragging-policy'] = ['Anti-Ragging Policy', 'Zero tolerance for ragging — on campus, in hostels and online',
        'Ragging is a criminal offence and is completely prohibited at ' . $name . '. How we prevent it, how to report it, and the action taken.',
        $doc($p($N . ' has zero tolerance for ragging. This policy follows the UGC Regulations on Curbing the Menace of Ragging in Higher Educational Institutions, 2009 (as amended), the corresponding AICTE regulations and the directions of the Hon’ble Supreme Court of India. It applies on campus and off campus — in classrooms, hostels, transport, at events and online.'), [
            'What is ragging' => $p('Ragging is any conduct by a student or students — by words spoken or written, or by an act — that teases, treats rudely or handles another student with indiscipline; causes annoyance, hardship, physical or psychological harm, or fear; asks a student to do something they would not ordinarily do and which causes shame or embarrassment; exploits a student’s services for any purpose; or involves financial extortion, physical abuse, sexual abuse, or abuse on the basis of colour, race, religion, caste, ethnicity, gender, sexual orientation, appearance, nationality, region, linguistic identity, place of birth, place of residence or economic background. It includes such conduct in person and through phones, messaging and social media.'),
            'Our commitments' => $ul([
                'An Anti-Ragging Committee and an Anti-Ragging Squad that make surprise checks in hostels, transport, canteens and other vulnerable places, especially at the start of the session.',
                'A mentoring cell in which senior students and faculty mentors support new students.',
                'Information about this policy and the helplines in the prospectus, at prominent places on campus and in every hostel.',
                'Prompt action on every complaint, including anonymous ones, with protection for the complainant and witnesses.',
            ]),
            'Undertaking by students and parents' => $p('Every student and their parent or guardian must submit an online undertaking against ragging each academic year through the national anti-ragging portal (www.antiragging.in) and submit the acknowledgement to the institute at admission.'),
            'How to report ragging' => $ol([
                'Contact any member of the Anti-Ragging Squad or Committee listed below, a faculty mentor or the hostel warden — at any time.',
                'Call the National Anti-Ragging Helpline <strong>1800-180-5522</strong> (toll free, 24×7) or email <a href="mailto:helpline@antiragging.in">helpline@antiragging.in</a>.',
                'Write to ' . $mail($email) . ' or call ' . $tel($phone) . '. You do not need to give your name.',
            ]),
            'Anti-Ragging Committee and Squad' => $table(['Role', 'Name and designation', 'Phone', 'Email'], [
                ['Chairperson, Anti-Ragging Committee', $ph('name'), $ph('phone'), $ph('email')],
                ['Convener, Anti-Ragging Squad', $ph('name'), $ph('phone'), $ph('email')],
                ['Chief Warden (Boys’ hostel)', $ph('name'), $ph('phone'), $ph('email')],
                ['Chief Warden (Girls’ hostel)', $ph('name'), $ph('phone'), $ph('email')],
                ['Local police station / SHO', $ph('police station and phone'), $ph('phone'), '—'],
            ]),
            'Action against ragging' => $p('Depending on the nature and gravity of the incident, the Anti-Ragging Committee may impose one or more of the punishments provided in the regulations, including suspension from classes and academic privileges, withholding of scholarships and other benefits, debarring from examinations or withholding results, suspension or expulsion from the hostel, cancellation of admission, rustication and expulsion, and a fine. Where the incident amounts to a criminal offence, a First Information Report (FIR) is lodged with the police. If the persons responsible cannot be identified, collective punishment may be imposed as permitted by the regulations.'),
            'Support for students' => $p('Students affected by ragging receive counselling and academic support. Any attempt to threaten or victimise a complainant or witness is itself treated as ragging.'),
        ]), [], 'policies', 41, 'default'];

    $pages['grievance-redressal-policy'] = ['Grievance Redressal Policy', 'A fair, time-bound way to resolve your concerns',
        'How students, parents and applicants of ' . $name . ' can raise a grievance — admission, fees, examinations, facilities or conduct — and how it is resolved.',
        $doc($p($N . ' is committed to resolving grievances fairly, confidentially and quickly. This policy follows the UGC (Redressal of Grievances of Students) Regulations, 2023 and the regulations of the AICTE and the affiliating university applicable to each program.'), [
            'Who can raise a grievance' => $p('Students, applicants for admission, and parents or guardians on behalf of a student.'),
            'What you can raise' => $ul([
                'Admission — eligibility decisions, merit, reservation, documents and the withholding of certificates.',
                'Fees — demands contrary to the published fee structure, refunds and scholarships.',
                'Examinations — conduct of examinations, evaluation, results and the issue of marksheets or certificates.',
                'Academics and services — teaching, attendance, internships, library, hostel, transport and other facilities.',
                'Harassment, discrimination or victimisation. Complaints of sexual harassment go to the ' . $a('/internal-complaints-committee', 'Internal Complaints Committee') . ', and complaints of ragging to the Anti-Ragging Committee (' . $a('/anti-ragging-policy', 'Anti-Ragging Policy') . ').',
            ]),
            'How to submit a grievance' => $ol([
                'Write to the grievance cell at ' . $mail($supEmail) . ' or hand in a written grievance at the front office, with your name, program, enrolment or application number and the details of the issue.',
                'Attach any supporting documents (receipts, notices, emails).',
                'You receive an acknowledgement with a reference number. ' . $ph('acknowledgement time, e.g. within 3 working days'),
            ]),
            'How grievances are resolved' => $p('The Students’ Grievance Redressal Committee (SGRC) examines the grievance, gives the student a hearing where needed and communicates its decision in writing within the time prescribed by the regulations ' . $ph('time limit for SGRC decisions') . '. Simple service issues may be resolved directly by the department concerned and confirmed to the student.'),
            'Appeal' => $p('A student who is not satisfied with the decision of the SGRC may appeal to the Ombudsperson appointed by the affiliating university within the period allowed by the regulations. Students may also use the online grievance portals of the regulators, such as UGC e-Samadhaan.'),
            'Grievance Redressal Committee' => $table(['Role', 'Name and designation', 'Contact'], [
                ['Chairperson, SGRC', $ph('name'), $ph('phone / email')],
                ['Member', $ph('name'), $ph('phone / email')],
                ['Member', $ph('name'), $ph('phone / email')],
                ['Student representative (special invitee)', $ph('name'), '—'],
                ['Ombudsperson (affiliating university)', $ph('name and university'), $ph('contact')],
            ]),
            'Confidentiality and protection' => $p('Grievances are handled confidentially. No student is victimised for raising a grievance in good faith. Grievances made with malicious intent may lead to disciplinary action.'),
        ]), [], 'policies', 42, 'default'];

    $pages['internal-complaints-committee'] = ['Internal Complaints Committee (POSH)', 'Prevention, prohibition and redressal of sexual harassment',
        'How ' . $name . ' prevents sexual harassment and how students, faculty and staff can complain to the Internal Complaints Committee.',
        $doc($p($N . ' is committed to a campus free of sexual harassment. In accordance with the Sexual Harassment of Women at Workplace (Prevention, Prohibition and Redressal) Act, 2013 and the UGC (Prevention, Prohibition and Redressal of Sexual Harassment of Women Employees and Students in Higher Educational Institutions) Regulations, 2015, the institute has constituted an Internal Complaints Committee (ICC).'), [
            'What is sexual harassment' => $p('Sexual harassment includes any unwelcome behaviour of a sexual nature, whether directly or by implication — physical contact and advances, a demand or request for sexual favours, sexually coloured remarks, showing pornography, or any other unwelcome physical, verbal or non-verbal conduct of a sexual nature, including through phones, messages and social media. It also includes implied or explicit promises of preferential treatment or threats of detrimental treatment, and creating an intimidating, hostile or offensive environment.'),
            'Who is protected' => $p('Women employees and students, and — as provided in the institute’s policy and the regulations — every member of the campus community, including visitors and those engaged through contractors. ' . $ph('coverage of male and transgender complainants as adopted by the institute')),
            'The committee' => $p('The ICC is constituted as required by the Act and the UGC Regulations, 2015. It is chaired by a Presiding Officer who is a senior woman employee, includes faculty and non-teaching staff members, student representatives for complaints involving students, and an external member familiar with issues relating to sexual harassment. At least one-half of the members are women.') . $table(['Role', 'Name and designation', 'Contact'], [
                ['Presiding Officer', $ph('name'), $ph('phone / email')],
                ['Faculty members', $ph('names'), $ph('contact')],
                ['Non-teaching staff members', $ph('names'), $ph('contact')],
                ['Student representatives', $ph('names'), '—'],
                ['External member', $ph('name and organisation'), $ph('contact')],
            ]),
            'How to make a complaint' => $ol([
                'Submit a written complaint to the Presiding Officer or any member of the ICC, or email ' . $ph('ICC email address') . '. If you cannot write it yourself, any member of the ICC will help you put it in writing.',
                'Complaints should be made within three months of the incident (or of the last incident in a series). The ICC can extend this by up to three more months if circumstances prevented an earlier complaint.',
                'Where the complainant is unable to complain because of physical or mental incapacity or death, a legal heir or other person permitted by law may complain.',
            ]),
            'Inquiry and action' => $p('The ICC may first attempt conciliation at the request of the complainant (no monetary settlement is permitted). Otherwise it conducts an inquiry following the principles of natural justice and completes it within the time limit prescribed by law (90 days). During the inquiry the complainant may be given interim relief, such as a change of working arrangements or of the person who supervises or evaluates them. On the ICC’s recommendation, action is taken under the service rules or the student code of conduct.'),
            'Confidentiality and protection from retaliation' => $p('The identity of the complainant, the respondent and witnesses and the details of the inquiry are kept confidential. Victimisation of a complainant or witness is misconduct. A complaint that cannot be proved is not a false complaint; action is taken only if a complaint is found to be made with malicious intent.'),
            'Awareness' => $p('The institute conducts regular orientation and awareness programmes for students, faculty and staff, and displays the names and contacts of ICC members at prominent places on campus.'),
        ]), [], 'policies', 43, 'default'];

    $pages['equal-opportunity-policy'] = ['Equal Opportunity Policy', 'An inclusive campus for every learner',
        $name . ' does not discriminate on the basis of caste, religion, gender, disability, region, language or economic background in admission, teaching, assessment or employment.',
        $doc($p($N . ' is committed to equal opportunity in admission, teaching, assessment, campus life and employment. No one is discriminated against on the grounds of caste, tribe, religion, gender, gender identity, sexual orientation, disability, region, language, place of birth or economic background.'), [
            'Our commitments' => $ul([
                'Admission and recruitment based on merit, applying the reservation and relaxation rules of the government and the regulators.',
                'Reasonable accommodation for students and staff with disabilities, in line with the Rights of Persons with Disabilities Act, 2016.',
                'Mentoring, bridge courses and remedial support for students who need them.',
                'Information about scholarships and government schemes for students from disadvantaged groups — see the ' . $a('/scholarship-policy', 'Scholarship Policy') . '.',
            ]),
            'Equal Opportunity Cell' => $p('The Equal Opportunity Cell advises students on academic, financial, social and other matters, oversees the implementation of welfare schemes and handles complaints of discrimination. It works with the SC/ST cell and the other student welfare cells constituted as required by the regulations. ' . $ph('name and contact of the Equal Opportunity Cell coordinator and the SC/ST cell liaison officer')),
            'Students with disabilities' => $p('Students can request accessible classrooms, reserved seating, extra time, scribes, accessible learning material and other support. Requests are confidential and are decided with the student. See also our ' . $a('/accessibility', 'Accessibility statement') . '.'),
            'Reporting discrimination' => $p('Complaints of discrimination can be made to the Equal Opportunity Cell or under the ' . $a('/grievance-redressal-policy', 'Grievance Redressal Policy') . '. Complaints are handled confidentially and no one is victimised for raising a concern.'),
        ]), [], 'policies', 44, 'default'];

    $pages['accessibility'] = ['Accessibility', 'Our commitment to an accessible website and campus',
        $name . ' wants everyone, including people with disabilities, to be able to use this website and the campus independently.',
        $doc($p($N . ' wants everyone — including people with visual, hearing, motor, speech and cognitive disabilities — to be able to use this website and our services independently.'), [
            'Our standard' => $p('We aim to meet the Web Content Accessibility Guidelines (WCAG) 2.1 at level AA and the Guidelines for Indian Government Websites wherever they apply.'),
            'What we have done' => $ul([
                'Pages can be used with a keyboard alone and show a visible focus indicator.',
                'Images carry text alternatives, and headings and landmarks follow a logical order.',
                'Text and controls meet AA colour-contrast levels, and text can be enlarged to 200% without loss of content.',
                'Animations respect the “reduce motion” setting of your device, and moving content can be paused.',
                'Forms have visible labels and clear error messages that do not rely on colour alone.',
            ]),
            'On campus' => $p('Students and visitors with disabilities can ask for assistance and reasonable accommodation. See the ' . $a('/equal-opportunity-policy', 'Equal Opportunity Policy') . '. ' . $ph('accessible infrastructure available on campus: ramps, lifts, accessible washrooms')),
            'Known limitations' => $p('Some older PDF documents and third-party content (such as embedded videos and maps) may not be fully accessible. Contact us and we will provide the information in an accessible format.'),
            'Feedback and contact' => $p('If you face any difficulty using this website, email ' . $mail($email) . ' or call ' . $tel($phone) . '. Please tell us the page address and the problem you faced.'),
        ]), [], 'policies', 45, 'default'];

    /* ------------------------------------------------------------------ Mandatory disclosure */
    $pages['mandatory-disclosure'] = ['Mandatory Disclosure', 'Statutory information about the institute',
        'Statutory information about ' . $name . ' published for students, parents and regulators: institute details, approvals, programs, governance and committees.',
        $doc($p('This page publishes the information that the regulators and the affiliating university require the institute to disclose. Details that are being compiled by the institute are marked as to be confirmed.'), [
            'Institute' => $table(['Item', 'Details'], [
                ['Name of the institute', $N],
                ['Address', e($address)],
                ['Phone', $tel($phone)],
                ['Email', $mail($email)],
                ['Website', e($website)],
                ['Year of establishment', $established > 1900 ? (string) $established : $ph('year of establishment')],
                ['Type of institute', $ph('e.g. private, self-financing')],
                ['Name of the society / trust', $ph('name and registration number of the sponsoring society or trust')],
                ['Head of the institute', $ph('name, designation, phone and email of the Director / Principal')],
            ]),
            'Affiliation and approvals' => ($affiliation !== '' ? $p('As recorded by the institute: ' . e($affiliation) . '.') : '') . $table(['Item', 'Details'], [
                ['Affiliating university', $ph('name of the affiliating university and affiliation reference')],
                ['AICTE approval', $ph('approval / extension of approval letter number and date for the current session')],
                ['Other recognitions or accreditations', $ph('e.g. NAAC / NBA status, if any')],
            ]),
            'Programs and intake' => $p('Programs currently offered are listed on the ' . $a('/programs', 'Programs') . ' page with their duration, eligibility and fees. ' . $ph('program-wise approved intake for the current session as per the latest approval')),
            'Admission and fees' => $p('The admission process, eligibility, important dates and fee structure are published on the ' . $a('/admissions', 'Admissions') . ' page. See also the ' . $a('/admission-policy', 'Admission Policy') . ', ' . $a('/fee-policy', 'Fee Policy') . ' and ' . $a('/refund-and-cancellation-policy', 'Refund & Cancellation Policy') . '.'),
            'Faculty and infrastructure' => $p('Faculty profiles are published on the ' . $a('/faculty', 'Faculty') . ' page and facilities on the ' . $a('/campus', 'Campus Life') . ' page. ' . $ph('faculty-student ratio, built-up area, library and laboratory details as required by the regulator')),
            'Statutory committees' => $ul([
                $a('/anti-ragging-policy', 'Anti-Ragging Committee and Squad'),
                $a('/internal-complaints-committee', 'Internal Complaints Committee (POSH)'),
                $a('/grievance-redressal-policy', 'Students’ Grievance Redressal Committee'),
                $a('/equal-opportunity-policy', 'Equal Opportunity Cell and SC/ST cell'),
            ]),
            'Governance and finance' => $p($ph('composition of the governing body, audited financial statements and other documents required to be disclosed')),
            'Contact for disclosure queries' => $contactBlock,
        ]), [], 'policies', 46, 'default'];

    /* ------------------------------------------------------------------ System content pages (only created when missing) */
    $vision = $s('vision', 'To be a globally recognised institution for excellence in education and innovation, creating leaders who make a positive impact on society.');
    $mission = $s('mission', 'To empower students with knowledge, skills and values through industry-relevant education, research and holistic development.');
    $values = $s('core_values', 'Integrity, innovation, excellence, inclusivity and social responsibility are at the core of everything we do.');
    $pages['vision-mission'] = ['Vision & Mission', 'What drives us every day',
        'The vision, mission and values that guide teaching, research and student life at ' . $name . '.',
        null, [
            $b('p08-vm-1', 'cards', ['eyebrow' => 'What we stand for', 'heading' => 'Our vision, mission and values', 'subheading' => '', 'style' => 'pastel', 'columns' => '3', 'items' => [
                ['icon' => 'eye', 'title' => 'Our Vision', 'subtitle' => '', 'image' => '', 'text' => mb_substr($vision, 0, 500), 'url' => '', 'link_label' => '', 'tint' => 'lavender'],
                ['icon' => 'target', 'title' => 'Our Mission', 'subtitle' => '', 'image' => '', 'text' => mb_substr($mission, 0, 500), 'url' => '', 'link_label' => '', 'tint' => 'sky'],
                ['icon' => 'heart', 'title' => 'Our Values', 'subtitle' => '', 'image' => '', 'text' => mb_substr($values, 0, 500), 'url' => '', 'link_label' => '', 'tint' => 'aqua']]], ['anchor' => 'values']),
            $b('p08-vm-2', 'cta', ['eyebrow' => '', 'title' => 'See how we put our mission into practice', 'text' => 'Explore our programs, faculty and campus life.', 'primary' => ['label' => 'Explore Courses', 'url' => '/programs'],
                'secondary' => ['label' => 'About GIMT', 'url' => '/about'], 'image' => '', 'theme' => 'light']),
        ], 'about', 2, 'default'];

    $pages['leadership'] = ['Leadership', 'The people who guide the institute',
        'The leadership and academic heads of ' . $name . '.',
        $p($N . ' is guided by its governing body and leadership team together with the heads of its academic departments. ' . $ph('names, designations, photographs and short profiles of the Chairperson, Director and Deans — add them as person cards in CMS → Pages → Leadership')),
        [
            $b('p08-ld-1', 'faculty_listing', ['eyebrow' => 'Academic leadership', 'heading' => 'Our senior faculty', 'subheading' => 'Directors, deans, heads of department and professors who lead teaching and research.', 'department_id' => null,
                'limit' => 8, 'show_qualification' => true, 'show_bio' => false, 'cta' => ['label' => 'Meet All Faculty', 'url' => '/faculty']], ['background' => 'light']),
        ], 'about', 3, 'default'];

    $pages['research'] = ['Research & Innovation', 'Ideas that solve real problems',
        'Research, consultancy and innovation by the faculty and students of ' . $name . '.',
        $p('Faculty and students at ' . $N . ' take up research, consultancy and innovation projects alongside teaching, and present their work at conferences and in journals. ' . $ph('research centres, funded projects, publications and patents to be listed here')),
        [
            $b('p08-rs-1', 'faculty_listing', ['eyebrow' => 'Our researchers', 'heading' => 'Faculty and their research interests', 'subheading' => '', 'department_id' => null, 'limit' => 8,
                'show_qualification' => true, 'show_bio' => true, 'cta' => ['label' => 'Meet All Faculty', 'url' => '/faculty']], ['background' => 'light']),
            $b('p08-rs-2', 'cta', ['eyebrow' => '', 'title' => 'Collaborate with us', 'text' => 'Industry partners and researchers can write to us to discuss projects, internships and consultancy.',
                'primary' => ['label' => 'Contact Us', 'url' => '/contact'], 'secondary' => ['label' => '', 'url' => ''], 'image' => '', 'theme' => 'light']),
        ], 'academics', 4, 'default'];

    $pages['campus-life'] = ['Campus Life', 'A place to learn, grow and thrive',
        'Clubs, sports, events, hostels and student support on the ' . $name . ' campus.',
        $p('Life at ' . $N . ' goes well beyond the classroom — student clubs, sports, cultural events, community service and a supportive campus community. Find details of facilities, hostels and events on our ' . $a('/campus', 'Campus Life') . ' page.'),
        [
            $b('p08-cl-1', 'gallery', ['eyebrow' => 'Campus in pictures', 'heading' => 'Life on campus', 'subheading' => '', 'source' => 'manual', 'gallery_id' => null, 'layout' => 'grid', 'columns' => '3', 'items' => [
                ['image' => 'assets/images/site/students-lawn.jpg', 'alt' => 'Students studying together on the campus lawn', 'caption' => 'Campus lawns'],
                ['image' => 'assets/images/site/facility-library.jpg', 'alt' => 'Library reading hall', 'caption' => 'Library'],
                ['image' => 'assets/images/site/facility-sports.jpg', 'alt' => 'Outdoor sports courts', 'caption' => 'Sports'],
            ]], ['background' => 'light']),
        ], 'campus', 5, 'default'];

    /* ------------------------------------------------------------------ Write (owned rows only) */
    $owned = json_decode((string) setting('seed_policies_owned', '[]'), true);
    $owned = is_array($owned) ? array_values(array_filter($owned, 'is_string')) : [];
    $adminId = db_value("SELECT id FROM users WHERE username = 'admin' LIMIT 1") ?: null;
    $now = date('Y-m-d H:i:s');
    $ids = [];
    $written = [];
    foreach ($pages as $slug => [$title, $subtitle, $excerpt, $content, $blocks, $parent, $sort, $template]) {
        [$cleanBlocks, $errors] = cms_validate_blocks($blocks);
        if ($errors) {
            throw new RuntimeException("Invalid seed blocks for page $slug: " . json_encode($errors));
        }
        $data = [
            'title' => $title, 'subtitle' => $subtitle, 'excerpt' => mb_substr($excerpt, 0, 500), 'template' => $template,
            'content' => $content !== null && $content !== '' ? sanitize_html($content) : null,
            'blocks' => $cleanBlocks ? json_encode($cleanBlocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'sort_order' => $sort, 'is_system' => 1,
        ];
        $row = db_row('SELECT id, updated_by FROM pages WHERE slug = ?', [$slug]);
        if (!$row) {
            $ids[$slug] = db_insert('pages', $data + ['slug' => $slug, 'status' => 'published', 'published_at' => $now, 'created_by' => $adminId, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now]);
            $owned[] = $slug;
            $written[$slug] = $parent;
        } elseif (in_array($slug, $owned, true)) {
            $ids[$slug] = (int) $row['id'];
            if ($row['updated_by'] === null) { // untouched by editors since this seeder wrote it
                db_update('pages', $data, 'id = ?', [(int) $row['id']]);
                $written[$slug] = $parent;
            }
        }
    }
    // Parents (only for rows written now): policies hub or the existing system page (about, academics, campus).
    foreach ($written as $slug => $parent) {
        if (!$parent) {
            continue;
        }
        $parentId = $ids[$parent] ?? db_value('SELECT id FROM pages WHERE slug = ?', [$parent]);
        if ($parentId && (int) $parentId !== (int) $ids[$slug]) {
            db_exec('UPDATE pages SET parent_id = ?, updated_at = updated_at WHERE id = ?', [(int) $parentId, (int) $ids[$slug]]);
        }
    }
    save_setting('seed_policies_owned', json_encode(array_values(array_unique($owned))), 'system');
};
