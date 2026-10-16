<?php
/**
 * Information module (M8): NGO details, team & management body, documents & resources,
 * testimonials, FAQs, contact / grievance / fraud-report / feedback forms and the newsletter.
 *
 * Authenticity: registration facts come only from Admin > Settings (blank values are never shown),
 * documents only from Admin > Certificates & Reports, testimonials only when published AND the
 * person's consent is recorded (consent_confirmed = 1).
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

const TEAM_GROUPS = [
    'management' => 'Management body',
    'advisor'    => 'Advisors',
    'team'       => 'Our team',
    'volunteer'  => 'Volunteers',
];

const RESOURCE_CATEGORIES = [
    'certification'       => 'Certificate',
    'annual_report'       => 'Annual report',
    'impact_report'       => 'Impact report',
    'financial_statement' => 'Financial statement',
    'utilization_report'  => 'Utilisation report',
    'digital_resource'    => 'Digital resource',
    'press_kit'           => 'Press kit',
];

const FAQ_CATEGORIES = [
    'general'      => 'About the foundation',
    'donations'    => 'Donations & receipts',
    'volunteering' => 'Volunteering, internships & jobs',
    'support'      => 'Getting support',
    'accounts'     => 'Accounts, privacy & safety',
    'events'       => 'Events',
];

const ENQUIRY_TYPES = [
    'contact'      => 'General enquiry',
    'partnership'  => 'Partnership / CSR',
    'media'        => 'Media',
    'grievance'    => 'Grievance',
    'fraud_report' => 'Fraud report',
];

const FEEDBACK_CATEGORIES = [
    'general'      => 'General feedback',
    'website'      => 'This website',
    'programme'    => 'A programme or event',
    'volunteering' => 'Volunteering experience',
    'donation'     => 'Donation experience',
    'suggestion'   => 'Suggestion / idea',
];

const FRAUD_WHAT_HAPPENED = [
    'money_request'   => 'Someone asked for money or a donation in our name',
    'job_fee'         => 'A job, internship or volunteer offer asked for a fee',
    'otp_bank'        => 'Someone asked for an OTP, password, card or bank details',
    'fake_profile'    => 'A fake website, page or social media account',
    'fake_document'   => 'A fake receipt, certificate or letter',
    'other'           => 'Something else',
];

const FRAUD_CHANNELS = [
    'phone' => 'Phone call', 'whatsapp' => 'WhatsApp / SMS', 'email' => 'Email', 'social_media' => 'Social media',
    'website' => 'Website / link', 'in_person' => 'In person',
];

const GRIEVANCE_NATURES = [
    'service'  => 'Complaint about a service or process',
    'delay'    => 'Delay or no response',
    'conduct'  => 'Behaviour of a volunteer or staff member',
    'privacy'  => 'Privacy or personal data concern',
    'decision' => 'Request to review a decision',
    'other'    => 'Other',
];

const GRIEVANCE_RELATED = [
    'donation' => 'A donation or receipt', 'application' => 'An application (volunteer, job, scholarship, help)',
    'volunteer' => 'Volunteering / a volunteer', 'other' => 'Something else',
];

// ---------------------------------------------------------------------
// NGO details (Admin > Settings). Only non-empty values are returned.
// ---------------------------------------------------------------------

/** Registration facts from settings: [label => value] (only values the foundation has entered). */
function ngo_registration_details(): array
{
    $fields = [
        'registration_type'   => 'Registered as',
        'registration_authority' => 'Registered with',
        'cin_number'          => 'CIN',
        'ngo_id'              => 'NGO ID',
        'registration_number' => 'Registration number',
        'registration_date'   => 'Date of registration',
        'pan_number'          => 'PAN',
        'reg_12a_number'      => '12A registration',
        'reg_80g_number'      => '80G registration',
        'csr1_number'         => 'CSR-1 registration',
        'darpan_id'           => 'NITI Aayog Darpan ID',
        'msme_udyam_number'   => 'MSME (Udyam) registration',
        'fcra_number'         => 'FCRA registration',
    ];
    $out = [];
    foreach ($fields as $key => $label) {
        $value = trim(setting($key));
        if ($value === '') {
            continue;
        }
        if ($key === 'registration_date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value = format_date($value);
        }
        if ($key === 'darpan_id' && $value === trim(setting('ngo_id'))) {
            continue;   // same number already shown as the NGO ID
        }
        $out[$label] = $value;
    }
    return $out;
}

/** Organisation details for the NGO Details page: legal name, registration facts and contact (non-empty only). */
function ngo_details(): array
{
    $details = [];
    if (trim(setting('legal_name')) !== '') {
        $details['Legal name'] = trim(setting('legal_name'));
    }
    $details += ngo_registration_details();
    foreach (['contact_address' => 'Office address', 'contact_email' => 'Email', 'contact_phone' => 'Phone'] as $key => $label) {
        if (trim(setting($key)) !== '') {
            $details[$label] = trim(setting($key));
        }
    }
    $details['Website'] = preg_replace('#^https?://#', '', rtrim(BASE_URL, '/'));
    return $details;
}

// ---------------------------------------------------------------------
// Documents & resources (table `resources`)
// ---------------------------------------------------------------------

/** Public URL of a resource: the uploaded PDF or the external https link ('' if neither is usable). */
function resource_url(array $r): string
{
    $path = (string) ($r['file_path'] ?? '');
    if ($path !== '' && preg_match('#^uploads/resources/[A-Za-z0-9._\-]+\.pdf$#', $path) && is_file(ROOT_PATH . '/' . $path)) {
        return url($path);
    }
    $ext = (string) ($r['external_url'] ?? '');
    if ($ext !== '' && preg_match('#^https://[^\s<>"]+$#i', $ext)) {
        return $ext;
    }
    return '';
}

/**
 * Published resources of one or more categories (newest first within the editor's sort order).
 * $campaignId limits utilisation reports to one campaign.
 */
function resources_by_category($cats, ?int $campaignId = null, int $limit = 0): array
{
    $cats = array_values(array_intersect(array_map('strval', (array) $cats), array_keys(RESOURCE_CATEGORIES)));
    if (!$cats) {
        return [];
    }
    $params = $cats;
    $sql = "SELECT r.*, c.title AS campaign_title, c.slug AS campaign_slug FROM resources r
            LEFT JOIN campaigns c ON c.id = r.campaign_id
            WHERE r.status = 'published' AND r.category IN (" . implode(',', array_fill(0, count($cats), '?')) . ')';
    if ($campaignId) {
        $sql .= ' AND r.campaign_id = ?';
        $params[] = $campaignId;
    }
    $sql .= ' ORDER BY r.sort_order, COALESCE(r.document_date, DATE(r.created_at)) DESC, r.id DESC';
    $rows = array_values(array_filter(db_rows($sql, $params), function ($r) {
        return resource_url($r) !== '';
    }));
    return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
}

/** Human readable file size: 1.2 MB, 340 KB. */
function info_file_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    return max(1, (int) round($bytes / 1024)) . ' KB';
}

/** List of documents (PDF or external link), each opening in a new tab. */
function document_list(array $rows, bool $showCategory = true): string
{
    if (!$rows) {
        return '';
    }
    $html = '<ul class="doc-list info-docs">';
    foreach ($rows as $i => $d) {
        $href = resource_url($d);
        if ($href === '') {
            continue;
        }
        $isFile = strpos($href, 'http') !== 0;
        $meta = [];
        if ($showCategory) {
            $meta[] = RESOURCE_CATEGORIES[$d['category']] ?? 'Document';
        }
        if (!empty($d['document_date'])) {
            $meta[] = format_date($d['document_date']);
        }
        if ($isFile) {
            $size = @filesize(ROOT_PATH . '/' . $d['file_path']);
            $meta[] = 'PDF' . ($size ? ', ' . info_file_size((int) $size) : '');
        } else {
            $meta[] = 'External link (' . preg_replace('/^www\./', '', (string) parse_url($href, PHP_URL_HOST)) . ')';
        }
        if (!empty($d['campaign_title'])) {
            $meta[] = 'Campaign: ' . $d['campaign_title'];
        }
        $html .= '<li class="reveal" style="--d:' . ($i % 4) . '"><a href="' . e($href) . '" target="_blank" rel="noopener">'
            . '<span class="icon-circle' . ($isFile ? '' : ' blue') . '">' . icon($isFile ? 'file-text' : 'globe') . '</span>'
            . '<span class="doc-text"><strong>' . e($d['title']) . '</strong>'
            . '<small>' . e(implode(' · ', $meta)) . '</small>'
            . (!empty($d['description']) ? '<span class="doc-desc">' . e($d['description']) . '</span>' : '')
            . '</span><span class="doc-go">' . icon($isFile ? 'download' : 'external') . '<span class="sr-only">(opens in a new tab)</span></span></a></li>';
    }
    return $html . '</ul>';
}

// ---------------------------------------------------------------------
// Team & management body
// ---------------------------------------------------------------------

/** Published team members of the given groups. */
function team_members(array $groups, int $limit = 0): array
{
    $groups = array_values(array_intersect($groups, array_keys(TEAM_GROUPS)));
    if (!$groups) {
        return [];
    }
    $rows = db_rows("SELECT * FROM team_members WHERE status = 'published' AND member_group IN (" . implode(',', array_fill(0, count($groups), '?')) . ')
                     ORDER BY FIELD(member_group, ' . implode(',', array_fill(0, count($groups), '?')) . '), sort_order, name', array_merge($groups, $groups));
    return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
}

/** Initials for an avatar placeholder. */
function info_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

/** LinkedIn profile link if it is a https://linkedin.com address, else ''. */
function info_linkedin_url(?string $url): string
{
    $url = trim((string) $url);
    return preg_match('#^https://([a-z]{2,3}\.)?(www\.)?linkedin\.com/[^\s<>"]*$#i', $url) ? $url : '';
}

function team_member_card(array $m, int $i = 0): string
{
    $photo = !empty($m['photo']) && is_file(ROOT_PATH . '/' . $m['photo']);
    $html = '<article class="team-card reveal tilt" style="--d:' . ($i % 4) . '">'
        . '<div class="team-photo">' . ($photo
            ? '<img src="' . e(url($m['photo'])) . '" alt="' . e($m['name']) . '" loading="lazy" width="320" height="320">'
            : '<span class="team-initials" aria-hidden="true">' . e(info_initials($m['name'])) . '</span>')
        . '</div><div class="team-body"><h3>' . e($m['name']) . '</h3><p class="team-role">' . e($m['designation']) . '</p>';
    $bio = trim((string) ($m['bio'] ?? ''));
    if ($bio !== '') {
        if (mb_strlen($bio) > 170) {
            // the summary stays visible (and focusable) in both states, so the bio can be collapsed again
            $html .= '<details class="team-bio"><summary><span class="bio-excerpt">' . e(excerpt($bio, 150)) . '</span> <em><span class="bio-more">Read more</span><span class="bio-less">Show less</span></em></summary><p>' . e_nl($bio) . '</p></details>';
        } else {
            $html .= '<p class="team-bio">' . e_nl($bio) . '</p>';
        }
    }
    $li = info_linkedin_url($m['linkedin_url'] ?? '');
    if ($li !== '') {
        $html .= '<a class="team-link" href="' . e($li) . '" target="_blank" rel="noopener" aria-label="' . e($m['name']) . ' on LinkedIn (opens in a new tab)">' . icon('linkedin') . 'LinkedIn</a>';
    }
    return $html . '</div></article>';
}

function team_grid_html(array $members): string
{
    $html = '<div class="team-grid">';
    foreach (array_values($members) as $i => $m) {
        $html .= team_member_card($m, $i);
    }
    return $html . '</div>';
}

// ---------------------------------------------------------------------
// Testimonials (published AND consent recorded)
// ---------------------------------------------------------------------
function published_testimonials(int $limit = 12): array
{
    return db_rows("SELECT * FROM testimonials WHERE status = 'published' AND consent_confirmed = 1 ORDER BY sort_order, id DESC LIMIT " . max(1, min(50, $limit)));
}

function testimonial_card(array $t): string
{
    $photo = !empty($t['photo']) && is_file(ROOT_PATH . '/' . $t['photo']);
    return '<figure class="testimonial info-testimonial">'
        . '<blockquote><p>' . e_nl($t['message']) . '</p></blockquote>'
        . '<figcaption class="testimonial-person">'
        . ($photo ? '<img src="' . e(url($t['photo'])) . '" alt="" loading="lazy" width="48" height="48">' : '<span class="avatar" aria-hidden="true">' . e(info_initials($t['name'])) . '</span>')
        . '<span><strong>' . e($t['name']) . '</strong>' . (!empty($t['designation']) ? '<small>' . e($t['designation']) . '</small>' : '') . '</span>'
        . '</figcaption></figure>';
}

// ---------------------------------------------------------------------
// FAQs
// ---------------------------------------------------------------------
function faqs_list(string $category = '', int $limit = 0): array
{
    $params = [];
    $sql = "SELECT * FROM faqs WHERE status = 'published'";
    if ($category !== '') {
        $sql .= ' AND category = ?';
        $params[] = $category;
    }
    $sql .= ' ORDER BY FIELD(category, ' . implode(',', array_fill(0, count(FAQ_CATEGORIES), '?')) . '), sort_order, id';
    $params = array_merge($params, array_keys(FAQ_CATEGORIES));
    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int) $limit;
    }
    return db_rows($sql, $params);
}

/** Make FAQ rows available to the FAQPage JSON-LD printed in the footer (no duplicates). */
function faq_add_to_schema(array $rows): void
{
    $seen = array_column($GLOBALS['seo_faqs'] ?? [], 'q');
    foreach ($rows as $r) {
        if (!in_array($r['question'], $seen, true)) {
            $GLOBALS['seo_faqs'][] = ['q' => $r['question'], 'a' => $r['answer']];
            $seen[] = $r['question'];
        }
    }
}

// ---------------------------------------------------------------------
// Contact details (Admin > Settings > Contact) - only non-empty values
// ---------------------------------------------------------------------
function info_whatsapp_url(string $message = ''): string
{
    $wa = preg_replace('/\D+/', '', setting('whatsapp_number'));
    if (strlen($wa) < 10) {
        return '';
    }
    $wa = strlen($wa) === 10 ? '91' . $wa : $wa;
    $message = $message !== '' ? $message : setting('widget_whatsapp_message');
    return 'https://wa.me/' . $wa . ($message !== '' ? '?text=' . rawurlencode($message) : '');
}

/** Google Maps embed URL from settings, only when it is a google.com/maps/embed address. */
function info_map_embed_url(): string
{
    $map = trim(setting('map_embed_url'));
    if (!preg_match('#^https://(www\.)?google\.com/maps/embed\?[^\s<>"\']+$#i', $map)) {
        return '';
    }
    return preg_replace('#^https://google\.com/#i', 'https://www.google.com/', $map);
}

/** Contact cards (address, email, phone, WhatsApp, office hours). */
function contact_cards_html(bool $compact = false): string
{
    $cards = [];
    if (setting('contact_address') !== '') {
        $cards[] = ['map-pin', '', 'Address', e(setting('contact_address'))];
    }
    if (setting('contact_email') !== '') {
        $cards[] = ['mail', 'gold', 'Email', '<a href="mailto:' . e(setting('contact_email')) . '">' . e(setting('contact_email')) . '</a>'];
    }
    if (setting('contact_phone') !== '') {
        $cards[] = ['phone', 'blue', 'Phone', '<a href="tel:' . e(preg_replace('/[^\d+]/', '', setting('contact_phone'))) . '">' . e(setting('contact_phone')) . '</a>'];
    }
    if (($wa = info_whatsapp_url()) !== '') {
        $cards[] = ['whatsapp', '', 'WhatsApp', '<a href="' . e($wa) . '" target="_blank" rel="noopener">' . e(setting('whatsapp_number')) . '<span class="sr-only"> (opens WhatsApp in a new tab)</span></a>'];
    }
    if (setting('office_hours') !== '') {
        $cards[] = ['clock', 'gold', 'Office hours', e_nl(setting('office_hours'))];
    }
    if (!$cards) {
        return '';
    }
    $html = '<div class="contact-cards' . ($compact ? ' is-compact' : '') . '">';
    foreach ($cards as $i => [$ic, $tone, $label, $value]) {
        $html .= '<div class="info-item contact-card reveal" style="--d:' . $i . '"><span class="icon-circle' . ($tone ? ' ' . $tone : '') . '">' . icon($ic) . '</span>'
            . '<div><h3>' . e($label) . '</h3><p>' . $value . '</p></div></div>';
    }
    return $html . '</div>';
}

function map_embed_html(): string
{
    $map = info_map_embed_url();
    if ($map === '') {
        return '';
    }
    return '<div class="contact-map reveal"><iframe class="map-embed" src="' . e($map) . '" title="Map showing the location of ' . e(site_name()) . '" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe></div>';
}

function social_links_html(string $label = 'Follow us'): string
{
    $links = social_links();
    if (!$links) {
        return '';
    }
    $html = '<div class="info-social"><span>' . e($label) . '</span>';
    foreach ($links as $key => $link) {
        $html .= '<a href="' . e($link['url']) . '" target="_blank" rel="noopener" aria-label="' . e($link['label']) . ' (opens in a new tab)" data-tip="' . e($link['label']) . '">' . icon($key) . '</a>';
    }
    return $html . '</div>';
}

/** Store an enquiry and give it a reference number. Returns [id, reference_no]. */
function save_enquiry(array $row, string $prefix): array
{
    $id = db_insert('contact_enquiries', $row + ['status' => 'new', 'ip_address' => client_ip(), 'user_id' => current_user_id()]);
    $ref = reference_no($prefix, $id);
    db_update('contact_enquiries', ['reference_no' => $ref], 'id = :id', ['id' => $id]);
    return [$id, $ref];
}

/** Acknowledgement / response timeline wording for grievances (generic unless set in settings). */
function grievance_timeline_text(): string
{
    $ack = (int) setting('grievance_ack_days');
    $res = (int) setting('grievance_resolution_days');
    $text = $ack > 0
        ? 'We aim to acknowledge every grievance within ' . $ack . ' working day' . ($ack > 1 ? 's' : '') . ' of receiving it'
        : 'We aim to acknowledge every grievance as early as possible';
    $text .= $res > 0
        ? ' and to share an outcome within ' . $res . ' working days. If a matter needs more time, we will tell you why and keep you informed.'
        : ' and to keep you informed until it is resolved. If a matter needs more time, we will tell you why.';
    return $text;
}

// ---------------------------------------------------------------------
// Newsletter
// ---------------------------------------------------------------------
function newsletter_enabled(): bool
{
    return setting('newsletter_enabled', '1') === '1';
}

function newsletter_unsubscribe_url(string $token): string
{
    return abs_page_url('newsletter', ['unsubscribe' => $token]);
}

/** Footer added to every newsletter email (personal unsubscribe link). */
function newsletter_email_footer(string $token): string
{
    return '<hr style="border:0;border-top:1px solid #e0e9e3;margin:24px 0 14px">'
        . '<p style="font-size:12px;color:#5b6b62">You are receiving this email because you subscribed to updates from ' . e(site_name())
        . '. <a href="' . e(newsletter_unsubscribe_url($token)) . '">Unsubscribe</a> at any time.</p>';
}

/**
 * Subscribe (or re-subscribe) an email address with recorded consent.
 * Returns ['id' => int, 'status' => 'new'|'resubscribed'|'existing', 'token' => string].
 */
function newsletter_subscribe(string $email, ?string $name, string $source): array
{
    $source = mb_substr(preg_replace('/[^a-z0-9_\-]/', '', strtolower($source)) ?: 'website', 0, 60);
    $consent = mb_substr(setting('newsletter_consent_text', 'I agree to receive email updates.'), 0, 255);
    $row = db_row('SELECT id, status, unsubscribe_token FROM newsletter_subscribers WHERE email = ?', [$email]);
    if ($row && $row['status'] === 'subscribed') {
        return ['id' => (int) $row['id'], 'status' => 'existing', 'token' => $row['unsubscribe_token']];
    }
    $data = ['status' => 'subscribed', 'consent_text' => $consent, 'consented_at' => date('Y-m-d H:i:s'), 'source' => $source, 'ip_address' => client_ip()];
    if ($name) {
        $data['name'] = mb_substr($name, 0, 120);
    }
    if ($row) {
        db_update('newsletter_subscribers', $data, 'id = :id', ['id' => $row['id']]);
        return ['id' => (int) $row['id'], 'status' => 'resubscribed', 'token' => $row['unsubscribe_token']];
    }
    $token = bin2hex(random_bytes(32));
    $id = db_insert('newsletter_subscribers', $data + ['email' => $email, 'unsubscribe_token' => $token]);
    return ['id' => $id, 'status' => 'new', 'token' => $token];
}

/** Subscription confirmation email with the personal unsubscribe link. */
function newsletter_send_confirmation(string $email, string $token): void
{
    $body = '<p>Namaste,</p><p>Thank you for subscribing to email updates from <strong>' . e(site_name()) . '</strong>. '
        . 'We will write occasionally about our programmes, events, campaigns and ways to get involved.</p>'
        . '<p>We will never sell or share your email address, and every email includes a link to unsubscribe.</p>'
        . '<p><a class="btn" href="' . e(abs_url('')) . '">Visit our website</a></p>'
        . '<p>If you did not sign up, you can ignore this email or unsubscribe here:<br><a href="' . e(newsletter_unsubscribe_url($token)) . '">' . e(newsletter_unsubscribe_url($token)) . '</a></p>';
    send_mail($email, 'Subscription confirmed - ' . site_name(), $body);
    newsletter_dev_log($email, $token);
}

/** Local testing aid: with mail disabled in development, write the unsubscribe link to the error log. */
function newsletter_dev_log(string $email, string $token): void
{
    if (!MAIL_ENABLED && APP_ENV === 'development') {
        error_log('[mail disabled] Newsletter link for ' . $email . ': ' . newsletter_unsubscribe_url($token));
    }
}

/** Newsletter sign-up form (posts to /newsletter/). */
function newsletter_form_html(string $source = 'section', string $idPrefix = 'nls', bool $withName = false): string
{
    if (!newsletter_enabled()) {
        return '';
    }
    return '<form class="info-newsletter-form" method="post" action="' . e(page_url('newsletter')) . '" data-validate>'
        . csrf_field() . honeypot_field()
        . '<input type="hidden" name="source" value="' . e($source) . '">'
        . ($withName ? '<div class="form-group"><label for="' . e($idPrefix) . '-name">Your name <span class="muted">(optional)</span></label>'
            . '<input id="' . e($idPrefix) . '-name" type="text" name="name" maxlength="120" autocomplete="name"></div>' : '')
        . '<div class="form-group"><label for="' . e($idPrefix) . '-email">Email address<span class="req">*</span></label>'
        . '<div class="nl-row"><input id="' . e($idPrefix) . '-email" type="email" name="email" required maxlength="190" autocomplete="email" placeholder="you@example.com" value="' . e((string) old('email')) . '">'
        . '<button class="btn btn-donate" type="submit">' . icon('send') . 'Subscribe</button></div></div>'
        . '<div class="form-group"><label class="checkbox"><input type="checkbox" name="consent" value="1" required><span>' . e(setting('newsletter_consent_text')) . '</span></label></div>'
        . '<p class="form-note">' . icon('lock') . '<span>We send occasional updates only. Unsubscribe with one click from any email. See our <a href="' . e(page_url('privacy-policy')) . '">privacy policy</a>.</span></p>'
        . '</form>';
}

/** Add this module's script once (FAQ filter, testimonials carousel). */
function info_require_script(): void
{
    $src = asset('assets/js/modules/info.js');
    if (!in_array($src, $GLOBALS['extraScripts'] ?? [], true)) {
        $GLOBALS['extraScripts'][] = $src;
    }
}

/** Client-side validation script (the server validates again). */
function info_require_validation(): void
{
    $src = asset('assets/js/form-validation.js');
    if (!in_array($src, $GLOBALS['extraScripts'] ?? [], true)) {
        $GLOBALS['extraScripts'][] = $src;
    }
}

/** Success panel shown once after a form was submitted (reference number). */
function info_submitted_panel(string $form): string
{
    $done = $_SESSION['info_submitted'] ?? null;
    if (!$done || ($done['form'] ?? '') !== $form) {
        return '';
    }
    unset($_SESSION['info_submitted']);
    return '<div class="result-card info-result reveal" role="status"><span class="result-icon success">' . icon('check-circle') . '</span>'
        . '<h2>' . e($done['title']) . '</h2><p>' . e($done['text']) . '</p>'
        . (!empty($done['ref']) ? '<p class="info-ref">Reference number <strong>' . e($done['ref']) . '</strong>'
            . '<button type="button" class="btn btn-ghost btn-sm" data-copy="' . e($done['ref']) . '">' . icon('copy') . 'Copy</button></p>' : '')
        . '</div>';
}

// ---------------------------------------------------------------------
// Section types (Admin > Pages > Sections). Each returns inner HTML or '' (= hidden).
// ---------------------------------------------------------------------
function section_schema_team_grid(): array
{
    return ['label' => 'Team members (management, team, advisors, volunteers)', 'fields' => ['pill', 'title', 'intro'],
        'settings' => ['group' => ['select', 'Group', ['management' => 'Management body', 'team' => 'Team', 'advisor' => 'Advisors', 'volunteer' => 'Volunteers']], 'limit' => ['number', 'Maximum (0 = all)']],
        'help' => 'Lists published members from Admin > Team & Management. Hidden when the group has nobody yet.'];
}

function section_team_grid(array $s, array $items, array $set, array $page): string
{
    $group = (string) ($set['group'] ?? 'team');
    $members = team_members([isset(TEAM_GROUPS[$group]) ? $group : 'team'], (int) ($set['limit'] ?? 0));
    return $members ? team_grid_html($members) : '';
}

function section_schema_documents_list(): array
{
    return ['label' => 'Documents list (certificates, reports, resources)', 'fields' => ['pill', 'title', 'intro'],
        'settings' => ['category' => ['select', 'Category', RESOURCE_CATEGORIES], 'limit' => ['number', 'Maximum (0 = all)']],
        'help' => 'Lists published documents from Admin > Certificates & Reports. Hidden when there are none.'];
}

function section_documents_list(array $s, array $items, array $set, array $page): string
{
    $docs = resources_by_category((string) ($set['category'] ?? 'certification'), null, (int) ($set['limit'] ?? 0));
    return $docs ? document_list($docs) : '';
}

function section_schema_testimonials(): array
{
    return ['label' => 'Testimonials (consented only)', 'fields' => ['pill', 'title', 'intro'],
        'settings' => ['limit' => ['number', 'Maximum number'], 'layout' => ['select', 'Layout', ['carousel' => 'Carousel', 'grid' => 'Grid']]],
        'help' => 'Shows only testimonials that are published and whose consent is recorded. Hidden when there are none.'];
}

function section_testimonials(array $s, array $items, array $set, array $page): string
{
    $rows = published_testimonials((int) ($set['limit'] ?? 9) ?: 9);
    if (!$rows) {
        return '';
    }
    if (($set['layout'] ?? 'carousel') === 'grid' || count($rows) < 3) {
        $html = '<div class="testimonial-grid">';
        foreach ($rows as $i => $t) {
            $html .= '<div class="reveal" style="--d:' . ($i % 3) . '">' . testimonial_card($t) . '</div>';
        }
        return $html . '</div>';
    }
    info_require_script();
    $html = '<div class="t-carousel" data-carousel><div class="t-track" data-carousel-track tabindex="0" role="region" aria-label="Testimonials (scroll for more)">';
    foreach ($rows as $t) {
        $html .= '<div class="t-slide">' . testimonial_card($t) . '</div>';
    }
    return $html . '</div><div class="t-nav"><button type="button" class="t-btn" data-carousel-prev aria-label="Previous testimonials">' . icon('chevron-left') . '</button>'
        . '<button type="button" class="t-btn" data-carousel-next aria-label="Next testimonials">' . icon('chevron-right') . '</button></div></div>';
}

function section_schema_faq_list(): array
{
    return ['label' => 'FAQs from the FAQ manager', 'fields' => ['pill', 'title', 'intro'],
        'settings' => ['category' => ['select', 'Category', ['' => 'All categories'] + FAQ_CATEGORIES], 'limit' => ['number', 'Maximum (0 = all)'], 'open_first' => ['checkbox', 'Open the first answer']],
        'help' => 'Published questions from Admin > FAQs. Also added to the page\'s FAQ structured data for search engines.'];
}

function section_faq_list(array $s, array $items, array $set, array $page): string
{
    $cat = (string) ($set['category'] ?? '');
    $rows = faqs_list(isset(FAQ_CATEGORIES[$cat]) ? $cat : '', (int) ($set['limit'] ?? 0));
    if (!$rows) {
        return '';
    }
    faq_add_to_schema($rows);
    $html = ui_faqs(array_map(function ($r) {
        return ['q' => $r['question'], 'a' => $r['answer']];
    }, $rows), !empty($set['open_first']));
    if (($page['slug'] ?? '') !== 'faq') {
        $html .= '<p class="faq-more"><a class="link-arrow" href="' . e(page_url('faq')) . '">See all frequently asked questions ' . icon('arrow-right') . '</a></p>';
    }
    return $html;
}

function section_schema_contact_info(): array
{
    return ['label' => 'Contact details (from settings)', 'fields' => ['pill', 'title', 'intro'],
        'settings' => ['hide_map' => ['checkbox', 'Hide the map'], 'hide_social' => ['checkbox', 'Hide social links']],
        'help' => 'Address, email, phone, WhatsApp and office hours from Admin > Settings. Blank values are not shown. The map appears only for a Google Maps embed link.'];
}

function section_contact_info(array $s, array $items, array $set, array $page): string
{
    $cards = contact_cards_html();
    if ($cards === '') {
        return '';
    }
    $map = empty($set['hide_map']) ? map_embed_html() : '';
    $social = empty($set['hide_social']) ? social_links_html() : '';
    return '<div class="contact-info-block' . ($map ? ' has-map' : '') . '"><div>' . $cards . $social . '</div>' . $map . '</div>';
}

function section_schema_newsletter_signup(): array
{
    return ['label' => 'Newsletter sign-up', 'fields' => ['pill', 'title', 'intro'],
        'settings' => ['source' => ['text', 'Source label (for reports, e.g. home)']],
        'help' => 'Email sign-up with a consent checkbox. Hidden automatically when the newsletter is switched off in settings. Tick "Hide the section heading" to show the title inside the band.'];
}

function section_newsletter_signup(array $s, array $items, array $set, array $page): string
{
    if (!newsletter_enabled()) {
        return '';
    }
    $source = (string) ($set['source'] ?? ($page['slug'] ?? 'section'));
    $inside = !empty($set['no_heading']);
    $title = $inside ? (string) ($s['title'] ?: 'Stay in touch') : 'Get occasional updates';
    $intro = $inside ? (string) ($s['intro'] ?? '') : 'Programme news, events and ways to help - straight to your inbox.';
    $GLOBALS['newsletter_section_rendered'] = true;   // the footer then skips its own (identical) sign-up form
    return '<div class="nl-band reveal"><span class="nl-orb" aria-hidden="true"></span><div class="nl-copy"><span class="nl-icon">' . icon('mail-open') . '</span>'
        . '<div>' . ($inside && !empty($s['pill']) ? '<span class="pill">' . e($s['pill']) . '</span>' : '')
        . ($inside ? '<h2>' . e($title) . '</h2>' : '<p class="nl-title">' . e($title) . '</p>') . ($intro !== '' ? '<p>' . e($intro) . '</p>' : '') . '</div></div>'
        . newsletter_form_html($source, 'nl-' . (int) ($s['id'] ?? 0)) . '</div>';
}

function section_schema_ngo_facts(): array
{
    return ['label' => 'Registration facts (from settings)', 'fields' => ['pill', 'title', 'intro'],
        'help' => 'Shows the registration details entered in Admin > Settings (registration number, PAN, 12A, 80G, CSR-1, Darpan ID...). Hidden while none are entered.'];
}

function section_ngo_facts(array $s, array $items, array $set, array $page): string
{
    $facts = ngo_registration_details();
    if (!$facts) {
        return '';
    }
    if (trim(setting('legal_name')) !== '') {
        $facts = ['Legal name' => trim(setting('legal_name'))] + $facts;
    }
    return ui_facts($facts);
}
