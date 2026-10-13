<?php
/**
 * Page helpers for the admissions, online application and contact pages (unit site_forms):
 * admissions.php, apply.php, contact.php. Business logic lives in app/services/public_forms.php.
 *
 * Content is database-driven (programs, academic_sessions, scholarships, faqs, events, settings) with sensible
 * fallbacks so every page renders on an empty CMS.
 */
require_once APP_ROOT . '/app/services/public_forms.php';

/* ------------------------------------------------------------------ Session, dates, contact data */

function sf_session_name(): string
{
    return (string) (pf_admission_session()['name'] ?? (date('Y') . '-' . substr((string) (date('Y') + 1), 2)));
}

function sf_admissions_open(): bool
{
    $s = pf_admission_session();
    return (int) ($s['admissions_open'] ?? 1) === 1;
}

/** Date from a setting (Y-m-d) or null. */
function sf_setting_date(string $key): ?string
{
    $v = trim((string) setting($key, ''));
    return $v !== '' && strtotime($v) ? date('Y-m-d', strtotime($v)) : null;
}

/** Key facts for the "Admissions Open" banner. */
function sf_key_dates(): array
{
    $s = pf_admission_session();
    $start = $s['start_date'] ?? null;
    $end = $s['end_date'] ?? null;
    $last = sf_setting_date('admission_last_date');
    return [
        ['icon' => 'calendar-check', 'label' => 'Applications', 'value' => sf_admissions_open() ? 'Open Now' : 'Closed', 'note' => 'Session ' . sf_session_name()],
        ['icon' => 'calendar-clock', 'label' => 'Last Date to Apply', 'value' => $last ? format_date($last, 'd M Y') : 'Rolling Admissions', 'note' => $last ? 'Subject to seat availability' : 'Until seats are filled'],
        ['icon' => 'clipboard-check', 'label' => 'Entrance & Interview', 'value' => 'Within 7 Days', 'note' => 'of document verification'],
        ['icon' => 'calendar-range', 'label' => 'Academic Session', 'value' => $start ? date('M Y', strtotime($start)) . ' – ' . date('M Y', strtotime((string) $end)) : sf_session_name(), 'note' => 'Semester system'],
    ];
}

/**
 * Important-dates timeline: admission cycle milestones from the academic sessions + settings, plus upcoming
 * admission events from the events table. Each item: date (Y-m-d|null), when (display), title, text, icon, state.
 */
function sf_important_dates(): array
{
    $s = pf_admission_session();
    $today = date('Y-m-d');
    $items = [];
    if (!empty($s['start_date'])) {
        $open = sf_setting_date('admission_open_date') ?? date('Y-m-01', strtotime($s['start_date'] . ' -6 months'));
        $items[] = ['date' => $open, 'when' => sf_setting_date('admission_open_date') ? format_date($open, 'd M Y') : date('M Y', strtotime($open)), 'title' => 'Online Applications Open',
            'text' => 'Applications for the ' . $s['name'] . ' session opened for all UG, PG, diploma and certificate programs.', 'icon' => 'file-text'];
        $items[] = ['date' => $s['start_date'], 'when' => format_date($s['start_date'], 'd M Y'), 'title' => 'Session ' . $s['name'] . ' Begins',
            'text' => 'Orientation week and induction for the first batch of the session.', 'icon' => 'graduation-cap'];
    }
    $items[] = ['date' => null, 'when' => (string) setting('counselling_schedule', 'Every Saturday · 10:00 AM'), 'title' => 'Counselling & Merit Rounds',
        'text' => 'Rolling merit lists and one-to-one counselling on campus and online for verified applicants.', 'icon' => 'users', 'state' => 'current'];
    $last = sf_setting_date('admission_last_date');
    $items[] = ['date' => $last, 'when' => $last ? format_date($last, 'd M Y') : 'Until seats are filled', 'title' => 'Last Date to Apply',
        'text' => 'Seats are allotted in order of merit and completed applications — apply early to secure your seat.', 'icon' => 'calendar-clock', 'state' => $last ? null : 'upcoming'];
    try {
        foreach (db_all("SELECT title, slug, excerpt, venue, start_datetime FROM events WHERE status = 'published' AND show_on_website = 1 AND category IN ('admission','webinar')
                         AND start_datetime >= ? ORDER BY start_datetime LIMIT 3", [date('Y-m-d 00:00:00')]) as $ev) {
            $items[] = ['date' => substr($ev['start_datetime'], 0, 10), 'when' => format_date($ev['start_datetime'], 'd M Y'), 'title' => $ev['title'],
                'text' => (string) ($ev['excerpt'] ?: $ev['venue']), 'icon' => 'calendar-days', 'url' => sf_page_exists('/events/' . $ev['slug']) ? '/events/' . $ev['slug'] : null];
        }
        $next = db_row("SELECT name, start_date FROM academic_sessions WHERE start_date > ? ORDER BY start_date LIMIT 1", [(string) ($s['start_date'] ?? $today)]);
        if ($next) {
            $nOpen = date('Y-m-01', strtotime($next['start_date'] . ' -6 months'));
            $items[] = ['date' => $nOpen, 'when' => date('M Y', strtotime($nOpen)), 'title' => 'Applications for ' . $next['name'] . ' Open',
                'text' => 'Register your interest now to get early counselling and scholarship updates for the next session.', 'icon' => 'sparkles'];
        }
    } catch (Throwable $e) {
    }
    // undated items: the ongoing one sorts at today, an open-ended deadline right after it
    $key = fn ($it) => $it['date'] ?? (($it['state'] ?? null) === 'current' ? $today : $today . '~');
    usort($items, fn ($a, $b) => strcmp($key($a), $key($b)));
    foreach ($items as &$it) {
        $it['state'] = $it['state'] ?? ($it['date'] && $it['date'] < $today ? 'done' : 'upcoming');
    }
    unset($it);
    return $items;
}

/** Institute contact details from settings (with the brief's defaults). */
function sf_contact(): array
{
    $wa = preg_replace('/\D+/', '', (string) setting('whatsapp_number', ''));
    $lat = (string) setting('latitude', '');
    $lng = (string) setting('longitude', '');
    $address = (string) setting('address', 'Plot No. 123, Knowledge Park, Greater Noida, Uttar Pradesh 201310, India');
    $query = rawurlencode(institute_name() . ', ' . $address);
    return [
        'phone' => (string) setting('phone', '+91 9955446477'),
        'phone_alt' => (string) setting('phone_alt', ''),
        'email' => (string) setting('email', 'info@gimt.ac.in'),
        'admission_email' => (string) setting('admission_email', 'admissions@gimt.ac.in'),
        'support_email' => (string) setting('support_email', 'support@gimt.ac.in'),
        'hours' => (string) setting('office_hours', 'Mon - Sat, 9:00 AM - 6:00 PM'),
        'address' => $address,
        'whatsapp' => $wa,
        'whatsapp_url' => $wa ? 'https://wa.me/' . $wa . '?text=' . rawurlencode('Hello GIMT, I would like to know more about admissions.') : '',
        'map_embed' => (string) setting('map_embed_url', ''),
        'map_url' => 'https://www.google.com/maps/search/?api=1&query=' . ($lat !== '' && $lng !== '' ? rawurlencode($lat . ',' . $lng) : $query),
        'directions_url' => 'https://www.google.com/maps/dir/?api=1&destination=' . ($lat !== '' && $lng !== '' ? rawurlencode($lat . ',' . $lng) : $query),
    ];
}

function sf_tel(string $phone): string
{
    return 'tel:' . preg_replace('/[^\d+]/', '', $phone);
}

/**
 * True when a site path resolves to a real page: a root PHP page (faq.php, campus.php …), a pretty URL whose handler
 * exists (programs/x → program.php, events/x → event.php …) or a published CMS page served by page.php
 * (privacy-policy, terms-and-conditions). Used so these pages never link to a page that does not exist (yet).
 */
function sf_page_exists(string $path): bool
{
    static $cache = [];
    $slug = trim((string) parse_url($path, PHP_URL_PATH), '/');
    if (array_key_exists($slug, $cache)) {
        return $cache[$slug];
    }
    if ($slug === '') {
        return $cache[$slug] = true;
    }
    $handlers = ['programs' => 'program', 'blog' => 'post', 'events' => 'event', 'notices' => 'notice', 'gallery' => 'gallery', 'page' => 'page'];
    if (preg_match('#^([a-z0-9\-]+)/[a-z0-9\-]+$#', $slug, $m)) {
        return $cache[$slug] = isset($handlers[$m[1]]) ? is_file(APP_ROOT . '/' . $handlers[$m[1]] . '.php') : false;
    }
    if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
        return $cache[$slug] = false;
    }
    if (is_file(APP_ROOT . '/' . $slug . '.php')) {
        return $cache[$slug] = true;
    }
    try {
        return $cache[$slug] = is_file(APP_ROOT . '/page.php') && (int) db_value("SELECT COUNT(*) FROM pages WHERE slug = ? AND status = 'published'", [$slug]) > 0;
    } catch (Throwable $e) {
        return $cache[$slug] = false;
    }
}

/** Site URL for $path when that page exists, else null. */
function sf_url_if(string $path): ?string
{
    return sf_page_exists($path) ? site_url(ltrim($path, '/')) : null;
}

/** Link to $path when the page exists, else the plain (escaped) text. */
function sf_link(string $path, string $text, string $class = 'link'): string
{
    $url = sf_url_if($path);
    return $url ? '<a class="' . e($class) . '" href="' . e($url) . '">' . e($text) . '</a>' : e($text);
}

/* ------------------------------------------------------------------ Programs, fees, scholarships, documents */

/** Programs grouped for tabs: ug | pg | other (diploma & certificate). */
function sf_program_groups(array $programs): array
{
    $groups = ['ug' => [], 'pg' => [], 'other' => []];
    foreach ($programs as $p) {
        $groups[$p['level'] === 'UG' ? 'ug' : (in_array($p['level'], ['PG', 'PhD'], true) ? 'pg' : 'other')][] = $p;
    }
    return $groups;
}

function sf_fee_text(array $p): string
{
    return (float) ($p['fee_per_year'] ?? 0) > 0 ? money($p['fee_per_year']) . ' ' . trim((string) ($p['fee_label'] ?: '/ Year')) : 'On request';
}

/** Eligibility / seats / fee table for one group (table on md+, stacked cards on phones). */
function sf_eligibility_table(array $programs): string
{
    if (!$programs) {
        return '<div class="rounded-2xl border border-dashed border-slate-200 bg-white p-8 text-center text-sm text-slate-500">Programs for this level will be announced soon. '
            . '<a class="link" href="' . e(site_url('contact')) . '">Contact the admission office</a> for details.</div>';
    }
    $rows = '';
    $cards = '';
    foreach ($programs as $p) {
        $url = site_url('programs/' . $p['slug']);
        $apply = site_url('apply?program=' . rawurlencode($p['slug']));
        $suffix = pf_program_suffix($p);
        $elig = e((string) ($p['eligibility'] ?: 'As per university norms.'));
        $rows .= '<tr class="group transition-colors hover:bg-brand-50/40">'
            . '<td class="align-top"><a href="' . e($url) . '" class="font-semibold text-brand-900 hover:text-brand-600">' . e($p['name']) . '</a>'
            . ($suffix !== '' ? '<span class="ml-1.5 text-xs font-semibold text-slate-400">' . e($suffix) . '</span>' : '')
            . '<span class="mt-1 flex flex-wrap gap-1.5"><span class="badge badge-blue">' . e(program_level_label($p['level'])) . '</span>' . ($p['category'] ? '<span class="badge badge-slate">' . e($p['category']) . '</span>' : '') . '</span></td>'
            . '<td class="whitespace-nowrap align-top text-slate-700">' . e((string) ($p['duration_label'] ?: rtrim(rtrim((string) $p['duration_years'], '0'), '.') . ' Years')) . '</td>'
            . '<td class="max-w-sm align-top text-[13px] leading-relaxed text-slate-600">' . $elig . '</td>'
            . '<td class="whitespace-nowrap align-top text-slate-700">' . ($p['intake_capacity'] ? e((string) $p['intake_capacity']) . ' seats' : '—') . '</td>'
            . '<td class="whitespace-nowrap align-top font-semibold text-brand-900">' . e(sf_fee_text($p)) . '</td>'
            . '<td class="whitespace-nowrap align-top text-right"><a href="' . e($apply) . '" class="btn btn-accent btn-sm">Apply' . icon('arrow-right', 'h-3.5 w-3.5') . '<span class="sr-only"> for ' . e($p['name']) . '</span></a></td></tr>';
        $cards .= '<li class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">'
            . '<div class="flex items-start justify-between gap-3"><a href="' . e($url) . '" class="font-display font-bold leading-snug text-brand-900">' . e($p['name']) . '</a>'
            . '<span class="badge badge-blue shrink-0">' . e($p['level']) . '</span></div>'
            . '<dl class="mt-3 grid grid-cols-3 gap-2 text-xs">'
            . '<div><dt class="text-slate-500">Duration</dt><dd class="mt-0.5 font-semibold text-slate-800">' . e((string) $p['duration_label']) . '</dd></div>'
            . '<div><dt class="text-slate-500">Intake</dt><dd class="mt-0.5 font-semibold text-slate-800">' . ($p['intake_capacity'] ? e((string) $p['intake_capacity']) . ' seats' : '—') . '</dd></div>'
            . '<div><dt class="text-slate-500">Fee</dt><dd class="mt-0.5 font-semibold text-slate-800">' . e(sf_fee_text($p)) . '</dd></div></dl>'
            . '<p class="mt-3 text-[13px] leading-relaxed text-slate-600"><span class="font-semibold text-slate-700">Eligibility: </span>' . $elig . '</p>'
            . '<div class="mt-4 flex gap-2"><a href="' . e($url) . '" class="btn btn-outline btn-sm flex-1">Details<span class="sr-only"> of ' . e($p['name']) . '</span></a>'
            . '<a href="' . e($apply) . '" class="btn btn-accent btn-sm flex-1">Apply Now' . icon('arrow-right', 'h-3.5 w-3.5') . '</a></div></li>';
    }
    return '<div class="hidden overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm md:block"><div class="overflow-x-auto"><table class="data-table">'
        . '<caption class="sr-only">Programs, eligibility, intake and annual fee</caption>'
        . '<thead><tr><th scope="col">Program</th><th scope="col">Duration</th><th scope="col">Eligibility</th><th scope="col">Intake</th><th scope="col">Fee</th><th scope="col"><span class="sr-only">Apply</span></th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table></div></div>'
        . '<ul class="grid gap-3 md:hidden">' . $cards . '</ul>';
}

/** Fee overview per level group: fee range, admission fee (payable after approval), duration range. */
function sf_fee_overview(array $programs): array
{
    $meta = [
        'ug' => ['title' => 'Undergraduate', 'icon' => 'graduation-cap', 'tint' => 'blue'],
        'pg' => ['title' => 'Postgraduate', 'icon' => 'school', 'tint' => 'green'],
        'other' => ['title' => 'Diploma & Certificate', 'icon' => 'award', 'tint' => 'amber'],
    ];
    $sessionId = !empty(pf_admission_session()['id']) ? (int) pf_admission_session()['id'] : null;
    $out = [];
    foreach (sf_program_groups($programs) as $key => $list) {
        if (!$list) {
            continue;
        }
        $fees = array_filter(array_map(fn ($p) => (float) $p['fee_per_year'], $list));
        $admFee = admission_default_fee((int) $list[0]['id'], $sessionId);
        $out[] = $meta[$key] + [
            'key' => $key, 'count' => count($list),
            'range' => $fees ? (min($fees) === max($fees) ? money(min($fees)) : money(min($fees)) . ' – ' . money(max($fees))) : 'On request',
            'unit' => $key === 'other' ? 'per course / year' : 'per year',
            'admission_fee' => money($admFee),
            'programs' => implode(', ', array_map(fn ($p) => $p['short_name'], $list)),
        ];
    }
    return $out;
}

/** Scholarships: active rows from the fees module, else the institute's standard schemes. */
function sf_scholarships(): array
{
    $icons = ['merit' => 'trophy', 'sports' => 'medal', 'sibling' => 'users', 'staff_ward' => 'id-card', 'waiver' => 'hand-coins', 'discount' => 'percent', 'scholarship' => 'award'];
    try {
        $rows = db_all("SELECT name, type, value, category, criteria, max_amount FROM scholarships WHERE status = 'active' AND category <> 'staff_ward'
                        ORDER BY CASE WHEN type = 'percentage' THEN 0 ELSE 1 END, value DESC, name LIMIT 6");
    } catch (Throwable $e) {
        $rows = [];
    }
    if ($rows) {
        return array_map(fn ($r) => [
            'title' => $r['name'],
            'value' => $r['type'] === 'percentage' ? 'Up to ' . rtrim(rtrim(number_format((float) $r['value'], 2), '0'), '.') . '%' : money($r['value']),
            'text' => (string) ($r['criteria'] ?: 'Awarded as per the institute scholarship policy.') . ($r['max_amount'] ? ' Maximum ' . money($r['max_amount']) . '.' : ''),
            'icon' => $icons[$r['category']] ?? 'award',
        ], $rows);
    }
    return [
        ['title' => 'Merit Scholarship', 'value' => 'Up to 50%', 'text' => 'On tuition fee for applicants with 85%+ in the qualifying exam or a top entrance-test score.', 'icon' => 'trophy'],
        ['title' => 'Sports Excellence', 'value' => 'Up to 25%', 'text' => 'For state and national level sportspersons, renewed every year on performance.', 'icon' => 'medal'],
        ['title' => 'Single Girl Child', 'value' => '10%', 'text' => 'Tuition fee concession for single girl children across all programs.', 'icon' => 'heart'],
        ['title' => 'Economically Weaker Section', 'value' => 'Up to 30%', 'text' => 'Need-based support for families with annual income below ₹3 lakh (income certificate required).', 'icon' => 'hand-coins'],
        ['title' => 'Defence & Ex-servicemen Ward', 'value' => '15%', 'text' => 'For children of serving and retired armed forces personnel.', 'icon' => 'shield-check'],
        ['title' => 'Sibling & Alumni Ward', 'value' => '10%', 'text' => 'For siblings of current students and children of GIMT alumni.', 'icon' => 'users'],
    ];
}

/** Payment modes enabled in Settings > Payment, as labels. */
function sf_payment_modes(): array
{
    $labels = admission_payment_modes();
    $enabled = array_filter(array_map('trim', explode(',', (string) setting('enabled_payment_modes', 'upi,card,bank_transfer,online'))));
    return array_values(array_filter(array_map(fn ($m) => $labels[$m] ?? null, $enabled)));
}

/** Documents checklist: online upload (from the application rules) + originals at enrollment. */
function sf_documents(): array
{
    $online = [];
    foreach (pf_document_specs() as $key => $spec) {
        $online[] = ['label' => $spec['label'], 'note' => match ($spec['when']) {
            'always' => 'Required for all programs', 'pg' => 'Required for PG programs', 'category' => 'If claiming a reserved category', default => 'Optional',
        }, 'required' => $spec['when'] !== 'category', 'icon' => $key === 'photo' ? 'image' : ($key === 'id_proof' ? 'id-card' : 'file-text')];
    }
    $originals = [
        ['label' => 'Original marksheets & pass certificates', 'note' => 'Class 10, Class 12 / Diploma and graduation (PG)'],
        ['label' => 'Transfer Certificate (TC)', 'note' => 'From the last institution attended'],
        ['label' => 'Migration Certificate', 'note' => 'If your board / university is different'],
        ['label' => 'Character Certificate', 'note' => 'Issued by the last institution'],
        ['label' => 'Category / Income Certificate', 'note' => 'For reservation or scholarship benefits'],
        ['label' => 'Gap Certificate (affidavit)', 'note' => 'Only if there is a gap after the qualifying exam'],
        ['label' => '4 passport-size photographs', 'note' => 'Same as the photo uploaded online'],
    ];
    return ['online' => $online, 'originals' => $originals, 'formats' => 'PDF, JPG or PNG · up to ' . human_filesize(pf_upload_limits()['document']) . ' per file'];
}

/* ------------------------------------------------------------------ FAQs & structured data */

/** FAQs from the CMS (given categories), else $defaults. Items: q, a, category. */
function sf_faqs(array $categories, array $defaults, int $limit = 8): array
{
    try {
        $in = implode(',', array_fill(0, count($categories), '?'));
        $rows = db_all("SELECT question, answer, category FROM faqs WHERE status = 'active' AND category IN ($in) ORDER BY FIELD(category, $in), sort_order, id LIMIT " . (int) $limit, array_merge($categories, $categories));
    } catch (Throwable $e) {
        $rows = [];
    }
    if (!$rows) {
        return array_slice($defaults, 0, $limit);
    }
    return array_map(fn ($r) => ['q' => $r['question'], 'a' => trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", (string) $r['answer'])), ENT_QUOTES)), 'category' => label_from_key($r['category'])], $rows);
}

function sf_admission_faq_defaults(): array
{
    $c = sf_contact();
    $session = sf_session_name();
    return [
        ['q' => 'How do I apply for admission at GIMT?', 'a' => "Choose your program, fill the online application form (it takes about 10 minutes), upload your documents and submit. You receive an application number instantly and an acknowledgement on email.\n\nOur admission team verifies your documents within 3 working days and schedules your entrance test or interview.", 'category' => 'Admissions'],
        ['q' => 'What are the eligibility criteria for UG and PG programs?', 'a' => 'Undergraduate programs require 10+2 or equivalent from a recognised board with at least 50% aggregate marks (45% for reserved categories). Postgraduate programs require a recognised bachelor’s degree with at least 50% marks. Program-wise eligibility is listed in the table above.', 'category' => 'Eligibility'],
        ['q' => 'Is there an entrance test?', 'a' => 'Applicants appear for the GIMT Aptitude Test and a personal interview, scheduled within 7 days of document verification. Valid CUET, JEE Main, CAT, MAT or CMAT scores can be submitted in place of the aptitude test.', 'category' => 'Admissions'],
        ['q' => 'Is there an application fee?', 'a' => 'No — applying online is free. The admission fee (' . money(admission_default_fee(null, null)) . ' for most UG programs) is payable only after your application is approved and the offer letter is issued.', 'category' => 'Fees'],
        ['q' => 'Can I pay the fee in instalments?', 'a' => 'Yes. Tuition fees can be paid semester-wise, and education-loan assistance is available through partner banks. Payments are accepted by UPI, card, net banking and bank transfer.', 'category' => 'Fees'],
        ['q' => 'What scholarships are available?', 'a' => 'Merit scholarships of up to 50% of the tuition fee, plus sports, single girl child, economically weaker section, defence ward and sibling scholarships. Mention your eligibility in the application and submit supporting documents at verification.', 'category' => 'Scholarships'],
        ['q' => 'I am awaiting my Class 12 / final-year results. Can I still apply?', 'a' => 'Yes. Apply with your latest marksheet or admit card. Your admission is provisional until you submit the final marksheet before the session ' . $session . ' cut-off date.', 'category' => 'Eligibility'],
        ['q' => 'How can I track my application?', 'a' => 'Your application number and acknowledgement are emailed to you. For status updates call ' . $c['phone'] . ' or email ' . $c['admission_email'] . ' with your application number.', 'category' => 'Admissions'],
    ];
}

function sf_contact_faq_defaults(): array
{
    $c = sf_contact();
    return [
        ['q' => 'What is the admission process at GIMT?', 'a' => 'Explore programs, submit the online application, upload your documents, complete verification and the entrance test / interview, then confirm your seat by paying the admission fee. Each step is explained on the Admissions page.', 'category' => 'Admissions'],
        ['q' => 'Where is GIMT located?', 'a' => $c['address'] . '. The campus is well connected by road and the Aqua Line metro.', 'category' => 'Campus'],
        ['q' => 'What are the eligibility criteria for UG and PG programs?', 'a' => 'UG programs require 10+2 or equivalent with at least 50% marks (45% for reserved categories). PG programs require a recognised bachelor’s degree with at least 50% marks.', 'category' => 'Admissions'],
        ['q' => 'Do you offer scholarships?', 'a' => 'Yes — merit scholarships of up to 50% of tuition, along with sports, single girl child, economically weaker section and defence ward scholarships.', 'category' => 'Fees'],
        ['q' => 'Can I visit the campus before admission?', 'a' => 'Absolutely. Campus visits run ' . $c['hours'] . '. Choose "Campus Visit" in the message form above and our team will confirm a slot for you.', 'category' => 'Campus'],
        ['q' => 'How can I contact the admission office?', 'a' => 'Call ' . $c['phone'] . ', email ' . $c['admission_email'] . ' or send us a message using the form on this page — a counsellor will respond within 24 hours.', 'category' => 'Admissions'],
    ];
}

function sf_faq_schema(array $faqs): array
{
    return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($f) => [
        '@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
    ], array_values($faqs))];
}

/** BreadcrumbList JSON-LD: ['Home' => '', 'Admissions' => 'admissions', …]. */
function sf_breadcrumb_schema(array $crumbs): array
{
    $i = 0;
    $items = [];
    foreach ($crumbs as $name => $path) {
        $items[] = ['@type' => 'ListItem', 'position' => ++$i, 'name' => $name, 'item' => absolute_url(ltrim($path, '/'))];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

/* ------------------------------------------------------------------ Small render helpers */

/** Result banner for a form posted without JavaScript (see pf_flash()). */
function sf_flash_notice(string $form): string
{
    $f = pf_take_flash($form);
    if (!$f) {
        return '';
    }
    $errors = $f['errors'] ? '<ul class="mt-2 list-disc space-y-0.5 pl-5 text-[13px] font-normal">' . implode('', array_map(fn ($m) => '<li>' . e($m) . '</li>', $f['errors'])) . '</ul>' : '';
    return '<div class="mb-5 rounded-2xl px-4 py-3 text-sm font-medium ' . ($f['ok'] ? 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-600/15' : 'bg-red-50 text-red-700 ring-1 ring-red-600/15') . '" role="' . ($f['ok'] ? 'status' : 'alert') . '">'
        . '<p class="flex items-start gap-2">' . icon($f['ok'] ? 'circle-check' : 'circle-alert', 'mt-0.5 h-4 w-4 shrink-0') . e($f['message']) . '</p>' . $errors . '</div>';
}

/** Program options for EnquiryForm selects (value = id). */
function sf_program_select_options(array $programs): array
{
    return array_map(fn ($p) => ['value' => (string) $p['id'], 'label' => $p['name']], $programs);
}

/* ------------------------------------------------------------------ Online application (apply.php) */

/** Props for the ApplyWizard island. */
function sf_apply_props(array $programs, ?array $initial): array
{
    $courses = pf_program_courses();
    $opt = pf_application_options();
    $c = sf_contact();
    $sessionId = !empty(pf_admission_session()['id']) ? (int) pf_admission_session()['id'] : null;
    $pairs = fn (array $a) => array_map(fn ($k, $v) => ['value' => (string) $k, 'label' => (string) $v], array_keys($a), array_values($a));
    $docs = [];
    foreach (pf_document_specs() as $key => $spec) {
        $docs[] = ['key' => $key, 'label' => $spec['label'], 'hint' => $spec['hint'], 'when' => $spec['when'], 'image' => $spec['image'], 'max' => $spec['max']];
    }
    return [
        'endpoint' => base_url('api/public/apply'),
        'session' => sf_session_name(),
        'programs' => array_map(fn ($p) => [
            'id' => (int) $p['id'], 'name' => $p['name'], 'short' => $p['short_name'], 'slug' => $p['slug'], 'level' => $p['level'], 'levelLabel' => program_level_label($p['level']),
            'category' => (string) $p['category'], 'duration' => (string) $p['duration_label'], 'fee' => sf_fee_text($p), 'eligibility' => (string) $p['eligibility'],
            'image' => site_image($p['image']), 'seats' => $p['intake_capacity'] ? (int) $p['intake_capacity'] : null, 'popular' => (bool) $p['is_popular'],
            'admissionFee' => money(admission_default_fee((int) $p['id'], $sessionId)), 'courses' => $courses[(int) $p['id']] ?? [],
        ], $programs),
        'initialProgram' => $initial ? (int) $initial['id'] : null,
        'options' => [
            'genders' => $pairs($opt['genders']), 'categories' => $pairs($opt['categories']), 'quotas' => $pairs($opt['quotas']),
            'bloodGroups' => $opt['blood_groups'], 'qualifications' => $pairs($opt['qualifications']), 'relations' => $opt['relations'], 'states' => $opt['states'],
        ],
        'documents' => $docs,
        'limits' => ['total' => pf_upload_limits()['total'], 'minYear' => 1980, 'maxYear' => (int) date('Y'), 'maxPassingYear' => (int) date('Y') + 1],
        'help' => ['phone' => $c['phone'], 'email' => $c['admission_email'], 'whatsapp' => $c['whatsapp_url'] ?: null, 'hours' => $c['hours']],
        'links' => ['admissions' => site_url('admissions'), 'programs' => site_url('programs'), 'privacy' => sf_url_if('/privacy-policy'), 'terms' => sf_url_if('/terms-and-conditions'),
            'classic' => site_url('apply?classic=1') . ($initial ? '&program=' . rawurlencode($initial['slug']) : ''), 'contact' => site_url('contact')],
    ];
}

/** Wizard-shaped skeleton shown (with JS) until the ApplyWizard island mounts — same footprint, no layout shift. */
function sf_apply_skeleton(int $programCount = 6): string
{
    $field = '<span class="block"><span class="skeleton mb-2 block h-3 w-24 rounded"></span><span class="skeleton block h-11 w-full rounded-xl"></span></span>';
    $steps = '';
    for ($i = 0; $i < 7; $i++) {
        $steps .= '<span class="flex flex-col items-center gap-2"><span class="skeleton block h-9 w-9 rounded-full"></span><span class="skeleton hidden h-2.5 w-14 rounded lg:block"></span></span>';
    }
    // one placeholder per program card (the wizard's first step lists every program) so the page does not jump on mount
    $cards = '';
    for ($i = 0; $i < max(2, $programCount); $i++) {
        $cards .= '<span class="skeleton block h-[100px] rounded-2xl"></span>';
    }
    $checks = '<span class="grid gap-3 sm:col-span-2 sm:grid-cols-2"><span class="skeleton block h-[66px] rounded-2xl"></span><span class="skeleton block h-[66px] rounded-2xl"></span></span>';
    return '<div class="hidden [.js_&]:block [.js.no-motion_&]:hidden [.js_[data-island-state=error]_&]:hidden" aria-hidden="true">'
        . '<div class="grid gap-6 lg:grid-cols-12">'
        . '<div class="rounded-3xl border border-slate-100 bg-white shadow-soft lg:col-span-8">'
        . '<div class="border-b border-slate-100 px-5 py-5 sm:px-8"><div class="flex justify-between gap-2">' . $steps . '</div></div>'
        . '<div class="space-y-6 px-5 py-6 sm:px-8 sm:py-8"><span class="skeleton block h-7 w-64 max-w-full rounded-lg"></span><span class="skeleton block h-4 w-80 max-w-full rounded"></span>'
        . '<span class="flex gap-2"><span class="skeleton block h-9 w-24 rounded-full"></span><span class="skeleton block h-9 w-28 rounded-full"></span><span class="skeleton block h-9 w-28 rounded-full"></span></span>'
        . '<span class="grid gap-3 sm:grid-cols-2">' . $cards . '</span>'
        . '<span class="grid gap-5 sm:grid-cols-2">' . $field . $field . '<span class="sm:col-span-2">' . $field . '</span>' . $checks . '</span></div>'
        . '<div class="flex justify-between border-t border-slate-100 px-5 py-4 sm:px-8"><span class="skeleton block h-11 w-28 rounded-xl"></span><span class="skeleton block h-11 w-36 rounded-xl"></span></div>'
        . '</div>'
        . '<div class="space-y-5 lg:col-span-4"><span class="skeleton block h-80 rounded-3xl"></span><span class="skeleton block h-56 rounded-3xl"></span></div>'
        . '</div><p class="sr-only" role="status">Loading the application form…</p></div>';
}

/**
 * Complete single-page application form that works without JavaScript (multipart POST to /apply).
 * Shown when JS is off, the bundle never boots, the island fails, or ?classic=1. $old = previous input, $errors = field errors.
 */
function sf_apply_classic_form(array $programs, ?array $initial, array $old = [], array $errors = [], string $message = '', bool $standalone = false): string
{
    $opt = pf_application_options();
    $courses = pf_program_courses();
    $val = fn (string $k, string $d = '') => e((string) ($old[$k] ?? $d));
    $err = fn (string $k) => isset($errors[$k]) ? '<p class="form-error" id="cf-' . e($k) . '-err">' . e($errors[$k]) . '</p>' : '';
    $inv = fn (string $k) => isset($errors[$k]) ? ' is-invalid" aria-invalid="true" aria-describedby="cf-' . e($k) . '-err' : '';
    $label = fn (string $k, string $text, bool $req = false) => '<label for="cf-' . e($k) . '" class="form-label">' . e($text) . ($req ? '<span class="form-required"> *</span>' : '') . '</label>';
    $input = function (string $k, string $text, array $o = []) use ($val, $err, $inv, $label) {
        $req = !empty($o['required']);
        return '<div class="' . ($o['col'] ?? 'sm:col-span-6') . '">' . $label($k, $text, $req)
            . '<input id="cf-' . e($k) . '" name="' . e($k) . '" type="' . e($o['type'] ?? 'text') . '" class="form-input' . $inv($k) . '" value="' . $val($k, (string) ($o['default'] ?? '')) . '"'
            . ($req ? ' required' : '') . (!empty($o['max']) ? ' maxlength="' . (int) $o['max'] . '"' : '') . (!empty($o['auto']) ? ' autocomplete="' . e($o['auto']) . '"' : '')
            . (isset($o['min_num']) ? ' min="' . e((string) $o['min_num']) . '"' : '') . (isset($o['max_num']) ? ' max="' . e((string) $o['max_num']) . '"' : '') . (!empty($o['step']) ? ' step="' . e($o['step']) . '"' : '')
            . (!empty($o['placeholder']) ? ' placeholder="' . e($o['placeholder']) . '"' : '') . (!empty($o['inputmode']) ? ' inputmode="' . e($o['inputmode']) . '"' : '') . '>' . $err($k) . '</div>';
    };
    $select = function (string $k, string $text, array $options, array $o = []) use ($old, $err, $inv, $label) {
        $req = !empty($o['required']);
        $cur = (string) ($old[$k] ?? ($o['default'] ?? ''));
        $html = '<div class="' . ($o['col'] ?? 'sm:col-span-6') . '">' . $label($k, $text, $req) . '<select id="cf-' . e($k) . '" name="' . e($k) . '" class="form-input' . $inv($k) . '"' . ($req ? ' required' : '') . '>'
            . '<option value="">' . e($o['placeholder'] ?? 'Select') . '</option>';
        foreach ($options as $v => $l) {
            if (is_array($l)) {
                $html .= '<optgroup label="' . e($v) . '">';
                foreach ($l as $vv => $ll) {
                    $html .= '<option value="' . e((string) $vv) . '"' . ((string) $vv === $cur ? ' selected' : '') . '>' . e($ll) . '</option>';
                }
                $html .= '</optgroup>';
            } else {
                $html .= '<option value="' . e((string) $v) . '"' . ((string) $v === $cur ? ' selected' : '') . '>' . e($l) . '</option>';
            }
        }
        return $html . '</select>' . $err($k) . '</div>';
    };
    $fieldset = fn (string $n, string $title, string $text, string $body) => '<fieldset class="border-t border-slate-100 pt-8 first-of-type:!mt-0 first-of-type:border-0 first-of-type:pt-0"><legend class="float-left flex w-full items-center gap-3 font-display text-lg font-bold text-brand-900">'
        . '<span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-brand-900 text-sm font-bold text-white">' . $n . '</span>' . e($title) . '</legend>'
        . '<p class="clear-both pt-1 text-sm text-slate-500">' . e($text) . '</p><div class="mt-5 grid gap-5 sm:grid-cols-12">' . $body . '</div></fieldset>';

    $byLevel = [];
    foreach ($programs as $p) {
        $byLevel[program_level_label($p['level'])][(string) $p['id']] = $p['name'] . ' — ' . sf_fee_text($p);
    }
    $courseOpts = [];
    foreach ($programs as $p) {
        foreach ($courses[(int) $p['id']] ?? [] as $c) {
            $courseOpts[$p['short_name']][(string) $c['id']] = $c['name'];
        }
    }
    $years = [];
    for ($y = (int) date('Y') + 1; $y >= 1980; $y--) {
        $years[(string) $y] = (string) $y;
    }
    $default = $initial ? (string) $initial['id'] : '';

    $html = '<form method="post" action="' . e(site_url('apply')) . '#application" enctype="multipart/form-data" class="space-y-8" novalidate>' . csrf_field()
        . '<input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">';
    if ($message !== '') {
        $html .= '<div class="form-alert" role="alert">' . icon('circle-alert', 'h-4 w-4 shrink-0') . e($message) . '</div>';
    }
    $html .= $fieldset('1', 'Program', 'Choose the program and specialisation you are applying for.',
        $select('program_id', 'Program', $byLevel, ['required' => true, 'col' => 'sm:col-span-12', 'placeholder' => 'Select a program', 'default' => $default])
        . $select('course_id', 'Specialisation (if applicable)', $courseOpts, ['placeholder' => 'No specialisation / decide later'])
        . $select('second_program_id', 'Second preference', $byLevel, ['placeholder' => 'None'])
        . $select('category', 'Category', $opt['categories'], ['required' => true, 'col' => 'sm:col-span-4'])
        . $select('quota', 'Admission quota', $opt['quotas'], ['col' => 'sm:col-span-4', 'default' => 'general'])
        . '<div class="flex flex-col justify-end gap-2 sm:col-span-4">'
        . '<label class="flex items-center gap-2.5 text-sm"><input type="checkbox" name="hostel_required" value="1" class="form-check"' . (!empty($old['hostel_required']) ? ' checked' : '') . '>I need hostel accommodation</label>'
        . '<label class="flex items-center gap-2.5 text-sm"><input type="checkbox" name="transport_required" value="1" class="form-check"' . (!empty($old['transport_required']) ? ' checked' : '') . '>I need college transport</label></div>');
    $html .= $fieldset('2', 'Personal details', 'Enter your name exactly as on your Class 10 certificate.',
        $input('first_name', 'First name', ['required' => true, 'max' => 80, 'col' => 'sm:col-span-4', 'auto' => 'given-name'])
        . $input('middle_name', 'Middle name', ['max' => 80, 'col' => 'sm:col-span-4', 'auto' => 'additional-name'])
        . $input('last_name', 'Last name', ['max' => 80, 'col' => 'sm:col-span-4', 'auto' => 'family-name'])
        . $select('gender', 'Gender', $opt['genders'], ['required' => true, 'col' => 'sm:col-span-4'])
        . $input('dob', 'Date of birth', ['required' => true, 'type' => 'date', 'col' => 'sm:col-span-4', 'auto' => 'bday', 'max_num' => date('Y-m-d', strtotime('-14 years'))])
        . $select('blood_group', 'Blood group', array_combine($opt['blood_groups'], $opt['blood_groups']), ['col' => 'sm:col-span-4'])
        . $input('nationality', 'Nationality', ['max' => 60, 'default' => 'Indian'])
        . $input('aadhaar_no', 'Aadhaar number', ['max' => 14, 'placeholder' => '12-digit Aadhaar (optional)', 'inputmode' => 'numeric']));
    $html .= $fieldset('3', 'Contact & address', 'We will send your application number and updates here.',
        $input('phone', 'Mobile number', ['required' => true, 'type' => 'tel', 'col' => 'sm:col-span-4', 'auto' => 'tel', 'placeholder' => '+91 98765 43210'])
        . $input('whatsapp', 'WhatsApp number', ['type' => 'tel', 'col' => 'sm:col-span-4'])
        . $input('email', 'Email address', ['required' => true, 'type' => 'email', 'col' => 'sm:col-span-4', 'auto' => 'email', 'max' => 190])
        . $input('address', 'Address', ['required' => true, 'col' => 'sm:col-span-12', 'max' => 255, 'auto' => 'street-address', 'placeholder' => 'House no., street, locality'])
        . $input('city', 'City', ['required' => true, 'col' => 'sm:col-span-4', 'max' => 80, 'auto' => 'address-level2'])
        . $select('state', 'State', array_combine($opt['states'], $opt['states']), ['required' => true, 'col' => 'sm:col-span-4'])
        . $input('pincode', 'PIN code', ['required' => true, 'col' => 'sm:col-span-2', 'max' => 12, 'auto' => 'postal-code', 'inputmode' => 'numeric'])
        . $input('country', 'Country', ['col' => 'sm:col-span-2', 'max' => 80, 'default' => 'India']));
    $html .= $fieldset('4', 'Parents / guardian', 'Details of your parents and local guardian (if any).',
        $input('father_name', "Father's name", ['required' => true, 'max' => 150, 'col' => 'sm:col-span-4'])
        . $input('father_phone', "Father's mobile", ['type' => 'tel', 'col' => 'sm:col-span-4'])
        . $input('father_occupation', "Father's occupation", ['max' => 100, 'col' => 'sm:col-span-4'])
        . $input('mother_name', "Mother's name", ['required' => true, 'max' => 150, 'col' => 'sm:col-span-4'])
        . $input('mother_phone', "Mother's mobile", ['type' => 'tel', 'col' => 'sm:col-span-4'])
        . $input('mother_occupation', "Mother's occupation", ['max' => 100, 'col' => 'sm:col-span-4'])
        . $input('guardian_name', 'Local guardian (if different)', ['max' => 150, 'col' => 'sm:col-span-4'])
        . $input('guardian_relation', 'Relation', ['max' => 40, 'col' => 'sm:col-span-4'])
        . $input('guardian_phone', 'Guardian mobile', ['type' => 'tel', 'col' => 'sm:col-span-4'])
        . $input('family_income', 'Annual family income (₹)', ['type' => 'number', 'min_num' => 0, 'step' => '1000', 'inputmode' => 'numeric']));
    $html .= $fieldset('5', 'Academic history', 'Class 10 and your qualifying examination (Class 12 for UG, graduation for PG programs).',
        $input('tenth_board', 'Class 10 board', ['required' => true, 'max' => 100, 'placeholder' => 'e.g. CBSE'])
        . $select('tenth_year', 'Class 10 passing year', $years, ['required' => true, 'col' => 'sm:col-span-3'])
        . $input('tenth_percentage', 'Class 10 marks %', ['required' => true, 'type' => 'number', 'step' => '0.01', 'min_num' => 0, 'max_num' => 100, 'col' => 'sm:col-span-3'])
        . $select('previous_qualification', 'Qualifying exam', $opt['qualifications'], ['required' => true, 'col' => 'sm:col-span-4'])
        . $input('previous_board', 'Board / University', ['required' => true, 'max' => 100, 'col' => 'sm:col-span-4', 'placeholder' => 'e.g. CBSE / CCS University'])
        . $input('previous_institution', 'School / College', ['max' => 190, 'col' => 'sm:col-span-4'])
        . $select('passing_year', 'Passing year', $years, ['required' => true, 'col' => 'sm:col-span-4'])
        . $input('previous_percentage', 'Marks % / CGPA %', ['required' => true, 'type' => 'number', 'step' => '0.01', 'min_num' => 0, 'max_num' => 100, 'col' => 'sm:col-span-4'])
        . $input('entrance_exam', 'Entrance exam (optional)', ['max' => 60, 'col' => 'sm:col-span-4', 'placeholder' => 'e.g. CUET 2026, CAT 2025']));
    $docs = '';
    foreach (pf_document_specs() as $key => $spec) {
        $k = 'doc_' . $key;
        $req = $spec['when'] === 'always';
        $docs .= '<div class="sm:col-span-6"><label for="cf-' . e($k) . '" class="form-label">' . e($spec['label']) . ($req ? '<span class="form-required"> *</span>' : ($spec['when'] === 'pg' ? ' <span class="text-xs font-normal text-slate-500">(PG programs)</span>' : ' <span class="text-xs font-normal text-slate-500">(optional)</span>')) . '</label>'
            . '<input id="cf-' . e($k) . '" name="' . e($k) . '" type="file" accept="' . ($spec['image'] ? 'image/jpeg,image/png,image/webp' : 'application/pdf,image/jpeg,image/png,image/webp') . '" class="form-input' . $inv($k) . '"' . ($req ? ' required' : '') . '>'
            . '<p class="form-hint">' . e($spec['hint']) . '</p>' . $err($k) . '</div>';
    }
    if (isset($errors['doc_other'])) {
        $docs .= '<div class="sm:col-span-12">' . $err('doc_other') . '</div>';
    }
    $html .= $fieldset('6', 'Documents', 'Upload clear scans or photos. Files are stored securely and used only for admission verification.', $docs);
    $html .= $fieldset('7', 'Declaration & submit', 'Please review your details before submitting.',
        '<div class="sm:col-span-12"><label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm leading-relaxed text-slate-700"><input type="checkbox" name="declaration" value="1" class="form-check mt-1' . (isset($errors['declaration']) ? ' is-invalid' : '') . '" required>'
        . '<span>I declare that the information provided is true and correct. I understand that my admission is subject to verification of original documents and eligibility, and I agree to the '
        . sf_link('/terms-and-conditions', 'Terms & Conditions') . ' and ' . sf_link('/privacy-policy', 'Privacy Policy') . '.</span></label>' . $err('declaration') . '</div>'
        . '<div class="sm:col-span-12"><button type="submit" class="btn btn-accent btn-lg w-full sm:w-auto">Submit Application' . icon('arrow-right', 'h-4 w-4') . '</button></div>');
    $html .= '</form>';

    $wrapCls = $standalone ? '' : 'hidden [.js_&]:hidden [.js.no-motion_&]:block [.js_[data-island-state=error]_&]:block [html:not(.js)_&]:block';
    return '<div class="' . $wrapCls . '"><div class="rounded-3xl border border-slate-100 bg-white p-5 shadow-soft sm:p-8">' . $html . '</div></div>';
}

/** Printable acknowledgement of a submitted application (apply.php?ack=…&t=…). */
function sf_ack_view(array $a, bool $isNew): string
{
    $c = sf_contact();
    $name = admission_full_name($a);
    $docTypes = admission_doc_types();
    $rows = [
        'Application No.' => $a['application_no'], 'Applicant' => $name, 'Program' => $a['program_name'] . ' (' . $a['program_short'] . ')',
        'Specialisation' => $a['course_name'] ?: '—', 'Academic Session' => $a['session_name'] ?: sf_session_name(), 'Category / Quota' => $a['category'] . ' · ' . (pf_application_options()['quotas'][$a['quota'] ?? 'general'] ?? 'General Merit'),
        'Mobile' => pf_mask_phone($a['phone']), 'Email' => pf_mask_email($a['email']), 'Submitted On' => format_datetime($a['created_at']),
        'Current Status' => admission_stage_label($a['stage']),
    ];
    $dl = '';
    foreach ($rows as $k => $v) {
        $dl .= '<div class="flex flex-col gap-0.5 border-b border-slate-100 py-3 sm:flex-row sm:gap-4"><dt class="w-44 shrink-0 text-sm text-slate-500">' . e($k) . '</dt><dd class="text-sm font-semibold text-slate-900' . ($k === 'Application No.' ? ' font-mono tracking-wide text-brand-900' : '') . '">' . e((string) $v) . '</dd></div>';
    }
    $docs = '';
    foreach ($a['documents'] as $d) {
        $badge = ['verified' => 'badge-green', 'rejected' => 'badge-red'][$d['status']] ?? 'badge-amber';
        $docs .= '<li class="flex items-center justify-between gap-3 py-2.5"><span class="flex min-w-0 items-center gap-2.5 text-sm text-slate-700">' . icon('file-check', 'h-4 w-4 shrink-0 text-accent-600') . '<span class="truncate">' . e($docTypes[$d['doc_type']] ?? label_from_key($d['doc_type'])) . '</span></span><span class="badge ' . $badge . '">' . e(ucfirst($d['status'])) . '</span></li>';
    }
    $steps = [
        ['clipboard-check', 'Document verification', 'Our admission team verifies your documents within 3 working days.'],
        ['calendar-check', 'Entrance test / interview', 'You will receive the schedule by email and SMS.'],
        ['badge-check', 'Offer letter', 'Selected applicants receive an offer letter with the admission fee details.'],
        ['wallet', 'Fee payment & enrollment', 'Pay the admission fee to confirm your seat and receive your student ID.'],
    ];
    $next = '';
    foreach ($steps as $i => [$ic, $t, $d]) {
        $next .= '<li class="flex gap-3"><span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-800">' . icon($ic, 'h-4 w-4') . '</span><span><span class="block text-sm font-semibold text-brand-900">' . ($i + 1) . '. ' . e($t) . '</span><span class="block text-[13px] text-slate-600">' . e($d) . '</span></span></li>';
    }
    $html = '<div class="mx-auto max-w-4xl">';
    if ($isNew) {
        $html .= '<div class="no-print mb-6 flex items-start gap-3 rounded-2xl bg-emerald-50 px-5 py-4 text-sm text-emerald-800 ring-1 ring-emerald-600/15" role="status">' . icon('circle-check', 'mt-0.5 h-5 w-5 shrink-0') . '<p><strong class="font-semibold">Application submitted successfully.</strong> Save or print this acknowledgement for your records' . ($a['email'] ? ' — a confirmation has also been emailed to you' : '') . '.</p></div>';
    }
    $html .= '<article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-soft print:rounded-none print:border-0 print:shadow-none">'
        . '<header class="flex flex-col gap-4 border-b-4 border-accent-600 bg-brand-900 px-6 py-6 text-white sm:flex-row sm:items-center sm:justify-between sm:px-8 print:bg-white print:text-brand-900">'
        . '<div class="flex items-center gap-4"><img src="' . e(logo_url()) . '" alt="" class="h-14 w-auto rounded-xl bg-white p-1.5" width="132" height="56">'
        . '<div><p class="font-display text-sm font-extrabold uppercase leading-tight tracking-wide">' . e(institute_name()) . '</p><p class="text-xs opacity-75">' . e($c['address']) . '</p></div></div>'
        . '<div class="sm:text-right"><p class="text-[11px] font-semibold uppercase tracking-widest text-accent-300 print:text-accent-700">Acknowledgement</p><p class="font-display text-lg font-bold">Online Application</p></div></header>'
        . '<div class="grid gap-8 px-6 py-7 sm:px-8 md:grid-cols-[1fr_auto]">'
        . '<dl>' . $dl . '</dl>'
        . ($a['photo'] ? '<div class="order-first md:order-none"><img src="' . e(upload_url($a['photo'])) . '" alt="Applicant photograph" class="h-40 w-32 rounded-xl object-cover ring-1 ring-slate-200"></div>' : '')
        . '</div>'
        . '<div class="grid gap-8 border-t border-slate-100 px-6 py-7 sm:px-8 md:grid-cols-2">'
        . '<section><h2 class="font-display text-base font-bold text-brand-900">Documents received</h2>' . ($docs ? '<ul class="mt-2 divide-y divide-slate-100">' . $docs . '</ul>' : '<p class="mt-2 text-sm text-slate-500">No documents were uploaded with this application.</p>') . '</section>'
        . '<section><h2 class="font-display text-base font-bold text-brand-900">What happens next</h2><ol class="mt-3 space-y-3">' . $next . '</ol></section></div>'
        . '<footer class="flex flex-col gap-2 border-t border-slate-100 bg-slate-50 px-6 py-5 text-[13px] text-slate-600 sm:flex-row sm:items-center sm:justify-between sm:px-8">'
        . '<p>Quote your application number in all communication. Helpline <a class="font-semibold text-brand-900" href="' . e(sf_tel($c['phone'])) . '">' . e($c['phone']) . '</a> · <a class="font-semibold text-brand-900" href="mailto:' . e($c['admission_email']) . '">' . e($c['admission_email']) . '</a></p>'
        . '<p class="text-xs text-slate-400">Generated ' . e(format_datetime(date('Y-m-d H:i:s'))) . '</p></footer></article>';
    $html .= '<div class="no-print mt-6 flex flex-wrap items-center justify-center gap-3">'
        . '<button type="button" class="btn btn-accent" onclick="window.print()">' . icon('printer', 'h-4 w-4') . 'Print / Save as PDF</button>'
        . button_link('Back to Admissions', '/admissions', 'outline', ['icon' => ''])
        . button_link('Explore Programs', '/programs', 'ghost', ['icon' => 'arrow-right']) . '</div></div>';
    return $html;
}
