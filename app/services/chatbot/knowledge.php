<?php
/**
 * Chatbot knowledge base + retrieval ("grounding") — live data from the ERP/CMS database, no embeddings.
 *
 *   chatbot_facts()                 institute facts from settings (name, contacts, hours, affiliation, deadline …)
 *   chatbot_programs_data()         active website programs with department + specialisations
 *   chatbot_snippets()              every knowledge snippet {id, type, title, text, url, tokens, program_id?}
 *                                   (programs, fee table, departments, scholarships, FAQs, notices, events, hostels,
 *                                   transport, placement statistics, CMS page passages) — cached 5 min in storage/cache
 *   chatbot_retrieve($q, $intents, $programs, $k)   BM25 keyword scoring + intent / program boosts
 *   chatbot_detect_programs($text)  programs mentioned in a question (acronyms, names, "computer science" …)
 *   chatbot_detect_language($text)  en | hi (Devanagari) | hinglish (Hindi in Latin script)
 *   chatbot_tokens($text)           normalised + synonym-mapped tokens (English, Hindi, Hinglish)
 *
 * Only real data is used: missing tables / empty rows simply produce fewer snippets.
 */

const CHATBOT_KB_TTL = 300;

/** Knowledge sources the admin can switch off (Settings → Knowledge sources). */
function chatbot_source_labels(): array
{
    return [
        'programs' => 'Programs, fees & eligibility', 'departments' => 'Departments', 'scholarships' => 'Scholarships',
        'faqs' => 'FAQs', 'notices' => 'Public notices', 'events' => 'Upcoming events', 'hostel' => 'Hostels',
        'transport' => 'Transport routes', 'placements' => 'Placement statistics', 'pages' => 'CMS pages & policies',
    ];
}

function chatbot_enabled_sources(): array
{
    $saved = json_decode((string) setting('chatbot_sources', ''), true);
    $out = [];
    foreach (array_keys(chatbot_source_labels()) as $k) {
        $out[$k] = is_array($saved) && array_key_exists($k, $saved) ? (bool) $saved[$k] : true;
    }
    return $out;
}

/** Path of a public page only when it exists in this installation (so the bot never links to a 404). */
function chatbot_route(string $path): ?string
{
    static $files = null;
    $files ??= [
        '/programs' => 'programs.php', '/apply' => 'apply.php', '/admissions' => 'admissions.php', '/campus' => 'campus.php',
        '/contact' => 'contact.php', '/about' => 'about.php', '/academics' => 'academics.php', '/faculty' => 'faculty.php',
        '/placement' => ['placement.php', 'placements.php', 'page.php'], '/notices' => ['notices.php'], '/events' => ['events.php'],
        '/privacy-policy' => ['privacy-policy.php', 'page.php'], '/blog' => 'blog.php', '/alumni' => ['alumni.php', 'page.php'],
    ];
    $base = '/' . trim(explode('#', explode('?', $path)[0])[0], '/');
    if ($base === '/') {
        return $path;
    }
    if (str_starts_with($base, '/programs/')) {
        return is_file(APP_ROOT . '/program.php') ? $path : null;
    }
    $need = $files[$base] ?? null;
    if ($need === null) {
        return is_file(APP_ROOT . '/page.php') ? $path : null; // CMS page /{slug}
    }
    foreach ((array) $need as $f) {
        if (is_file(APP_ROOT . '/' . $f)) {
            return $path;
        }
    }
    return null;
}

/* ------------------------------------------------------------------ Facts */

function chatbot_facts(): array
{
    static $facts = null;
    if ($facts !== null) {
        return $facts;
    }
    $deadline = (string) setting('admissions_deadline', '');
    $deadlineLabel = '';
    if ($deadline !== '' && ($ts = strtotime($deadline))) {
        $deadlineLabel = date('j M Y', $ts) . (date('H:i', $ts) !== '00:00' && date('H:i', $ts) !== '23:59' ? ', ' . date('g:i A', $ts) : '');
    }
    $session = null;
    try {
        $session = db_row('SELECT name, admissions_open FROM academic_sessions WHERE admissions_open = 1 ORDER BY is_current DESC, start_date LIMIT 1')
            ?: db_row('SELECT name, admissions_open FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1');
    } catch (Throwable $e) {
    }
    $wa = preg_replace('/\D/', '', (string) setting('whatsapp_number', ''));
    $facts = array_filter([
        'name' => (string) setting('institute_name', 'Global Institute of Management & Technology'),
        'short' => (string) setting('institute_short_name', 'GIMT'),
        'tagline' => (string) setting('tagline', ''),
        'established' => (string) setting('established_year', ''),
        'address' => (string) setting('address', ''),
        'city' => (string) setting('city', ''),
        'phone' => (string) setting('phone', ''),
        'phone_alt' => (string) setting('phone_alt', ''),
        'email' => (string) setting('email', ''),
        'admission_email' => (string) setting('admission_email', ''),
        'office_hours' => (string) setting('office_hours', ''),
        'affiliation' => (string) setting('affiliation', ''),
        'website' => (string) setting('website', ''),
        'admissions_open_text' => (string) setting('admissions_open_text', ''),
        'session' => (string) ($session['name'] ?? ''),
        'admissions_open' => $session ? ((int) $session['admissions_open'] ? 'yes' : 'no') : '',
        'deadline' => $deadlineLabel,
        'whatsapp' => $wa,
    ], fn ($v) => $v !== '');
    return $facts;
}

function chatbot_wa_link(string $text = ''): ?string
{
    $n = preg_replace('/\D/', '', (string) setting('whatsapp_number', ''));
    if ($n === '') {
        return null;
    }
    $text = $text !== '' ? $text : 'Hello ' . setting('institute_short_name', 'GIMT') . ', I would like to know more about admissions.';
    return 'https://wa.me/' . $n . '?text=' . rawurlencode($text);
}

function chatbot_level_label(?string $level): string
{
    return ['UG' => 'Undergraduate', 'PG' => 'Postgraduate', 'Diploma' => 'Diploma', 'Certificate' => 'Certificate', 'PhD' => 'Doctoral'][(string) $level] ?? (string) $level;
}

function chatbot_fee_label(array $p): string
{
    if ((float) ($p['fee_per_year'] ?? 0) <= 0) {
        return '';
    }
    $suffix = trim((string) ($p['fee_label'] ?? ''));
    $suffix = $suffix === '' ? ' per year' : ' ' . ltrim(str_replace(['/ Year', '/Year', '/ year'], 'per year', str_replace(['/ Course', '/Course'], 'for the course', $suffix)));
    return money($p['fee_per_year']) . $suffix;
}

/** Plain text from a text / JSON-list / HTML column. */
function chatbot_plain($value, int $max = 0): string
{
    $s = (string) ($value ?? '');
    $trim = ltrim($s);
    if ($trim !== '' && $trim[0] === '[') {
        $arr = json_decode($s, true);
        if (is_array($arr)) {
            $s = implode('; ', array_map(fn ($x) => is_array($x) ? implode(' ', array_map('strval', array_filter($x, 'is_scalar'))) : (string) $x, $arr));
        }
    }
    $s = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/li|/h\d|/div)\b[^>]*>#i', "\n", $s)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace("/[ \t]+/", ' ', $s);
    $s = trim(preg_replace("/\n{2,}/", "\n", str_replace("\r", '', $s)));
    if ($max > 0 && mb_strlen($s) > $max) {
        $s = rtrim(mb_substr($s, 0, $max - 1)) . '…';
    }
    return $s;
}

/* ------------------------------------------------------------------ Programs */

function chatbot_programs_data(): array
{
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }
    $rows = [];
    try {
        $rows = db_all("SELECT p.id, p.name, p.short_name, p.code, p.slug, p.level, p.degree, p.category, p.duration_label, p.duration_years, p.total_semesters,
                               p.intake_capacity, p.fee_per_year, p.fee_label, p.eligibility, p.overview, p.highlights, p.career_prospects, p.is_popular,
                               d.name AS department_name
                        FROM programs p LEFT JOIN departments d ON d.id = p.department_id
                        WHERE p.status = 'active' AND p.show_on_website = 1 ORDER BY p.sort_order, p.name");
        $courses = [];
        foreach (db_all("SELECT program_id, name FROM courses WHERE status = 'active' ORDER BY name") as $c) {
            $courses[(int) $c['program_id']][] = $c['name'];
        }
        foreach ($rows as &$p) {
            $p['courses'] = $courses[(int) $p['id']] ?? [];
            $p['url'] = chatbot_route('/programs/' . $p['slug']);
            $p['apply_url'] = chatbot_route('/apply?program=' . $p['slug']);
        }
        unset($p);
    } catch (Throwable $e) {
        $rows = [];
    }
    return $rows;
}

function chatbot_program_line(array $p, bool $withEligibility = true): string
{
    $parts = [chatbot_level_label($p['level']) . ' program'];
    if ($p['duration_label']) {
        $parts[] = $p['duration_label'];
    }
    if ((int) $p['intake_capacity'] > 0) {
        $parts[] = (int) $p['intake_capacity'] . ' seats';
    }
    if ($fee = chatbot_fee_label($p)) {
        $parts[] = 'fee ' . $fee;
    }
    $line = implode(' · ', $parts);
    if ($withEligibility && $p['eligibility']) {
        $line .= '. Eligibility: ' . chatbot_plain($p['eligibility'], 240);
    }
    return $line;
}

/* ------------------------------------------------------------------ Snippets */

/** All knowledge snippets (cached for CHATBOT_KB_TTL seconds; chatbot_kb_flush() clears). */
function chatbot_snippets(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $sources = chatbot_enabled_sources();
    $file = STORAGE_PATH . '/cache/chatbot_kb_' . substr(md5(json_encode($sources) . base_path()), 0, 10) . '.json';
    if (is_file($file) && filemtime($file) > time() - CHATBOT_KB_TTL) {
        $cached = json_decode((string) @file_get_contents($file), true);
        if (is_array($cached)) {
            return $memo = $cached;
        }
    }
    $list = chatbot_build_snippets($sources);
    foreach ($list as &$s) {
        $s['tokens'] = chatbot_tokens($s['title'] . ' ' . $s['title'] . ' ' . ($s['keywords'] ?? '') . ' ' . $s['text']);
    }
    unset($s);
    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0775, true);
    }
    @file_put_contents($file, json_encode($list, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $memo = $list;
}

function chatbot_kb_flush(): void
{
    foreach (glob(STORAGE_PATH . '/cache/chatbot_kb_*.json') ?: [] as $f) {
        @unlink($f);
    }
}

function chatbot_build_snippets(array $on): array
{
    $out = [];
    $add = function (string $id, string $type, string $title, string $text, ?string $url, array $extra = []) use (&$out) {
        if (trim($text) === '') {
            return;
        }
        $out[] = ['id' => $id, 'type' => $type, 'title' => $title, 'text' => mb_substr($text, 0, 1600), 'url' => $url] + $extra;
    };
    $f = chatbot_facts();
    $contact = [];
    foreach (['address' => 'Address', 'phone' => 'Phone', 'phone_alt' => 'Alternate phone', 'email' => 'Email', 'admission_email' => 'Admissions email', 'office_hours' => 'Office hours'] as $k => $label) {
        if (!empty($f[$k])) {
            $contact[] = $label . ': ' . $f[$k];
        }
    }
    $add('facts:contact', 'contact', 'Contact, address and office hours', implode('. ', $contact) . '.', chatbot_route('/contact'), ['keywords' => 'contact phone call email address location where campus reach office hours timing']);
    if (!empty($f['admissions_open_text']) || !empty($f['deadline'])) {
        $add('facts:dates', 'dates', 'Admission dates and deadline', trim(($f['admissions_open_text'] ?? '') . '. ' . (!empty($f['deadline']) ? 'Last date to apply: ' . $f['deadline'] . '.' : '')), chatbot_route('/admissions#dates'),
            ['keywords' => 'deadline last date admission open session apply when']);
    }
    if (!empty($f['affiliation'])) {
        $add('facts:affiliation', 'affiliation', 'Affiliation and approvals', $f['affiliation'] . (!empty($f['established']) ? '. Established in ' . $f['established'] . '.' : '.'), chatbot_route('/about#approvals'),
            ['keywords' => 'affiliation approved aicte ugc university recognised degree valid']);
    }

    try {
        if ($on['programs']) {
            $programs = chatbot_programs_data();
            $fees = [];
            foreach ($programs as $p) {
                $text = $p['name'] . ' (' . $p['short_name'] . ') — ' . chatbot_program_line($p) . '.';
                if ($p['department_name']) {
                    $text .= ' Department: ' . $p['department_name'] . '.';
                }
                if ($p['courses']) {
                    $text .= ' Specialisations: ' . implode(', ', $p['courses']) . '.';
                }
                if ($p['overview']) {
                    $text .= ' ' . chatbot_plain($p['overview'], 360);
                }
                if ($p['highlights']) {
                    $text .= ' Highlights: ' . str_replace("\n", '; ', chatbot_plain($p['highlights'], 260)) . '.';
                }
                if ($p['career_prospects']) {
                    $text .= ' Career options: ' . str_replace("\n", ', ', chatbot_plain($p['career_prospects'], 200)) . '.';
                }
                $add('program:' . $p['id'], 'program', $p['short_name'] . ' — ' . $p['name'], $text, $p['url'], ['program_id' => (int) $p['id'], 'keywords' => 'program course ' . $p['category'] . ' ' . $p['level']]);
                if ($fee = chatbot_fee_label($p)) {
                    $fees[] = $p['short_name'] . ': ' . $fee;
                }
            }
            if ($fees) {
                $add('programs:fees', 'fees', 'Program fees', 'Program fees: ' . implode('; ', $fees) . '. Fees are confirmed by the admissions office; scholarships and instalments may apply.',
                    chatbot_route('/admissions#fees'), ['keywords' => 'fee fees cost tuition per year how much']);
            }
        }
        if ($on['departments']) {
            $deps = db_all("SELECT name, code, description, established_year FROM departments WHERE status = 'active' ORDER BY name");
            if ($deps) {
                $add('departments:all', 'departments', 'Departments', implode(' ', array_map(fn ($d) => $d['name'] . ($d['description'] ? ': ' . chatbot_plain($d['description'], 140) : '') . '.', $deps)),
                    chatbot_route('/academics#departments'), ['keywords' => 'department departments faculty school']);
            }
        }
        if ($on['scholarships']) {
            $rows = db_all("SELECT name, type, value, category, criteria, max_amount FROM scholarships WHERE status = 'active' ORDER BY value DESC, name");
            $all = [];
            foreach ($rows as $i => $s) {
                $value = $s['type'] === 'percentage' ? rtrim(rtrim(number_format((float) $s['value'], 2), '0'), '.') . '% of the tuition fee' : money($s['value']) . ' per year';
                $line = $s['name'] . ': ' . $value . ((float) $s['max_amount'] > 0 ? ' (maximum ' . money($s['max_amount']) . ')' : '') . '. ' . chatbot_plain($s['criteria'], 260);
                $all[] = $line;
                $add('scholarship:' . $i, 'scholarship', $s['name'], $line, chatbot_route('/admissions#scholarships'), ['keywords' => 'scholarship concession waiver discount ' . $s['category']]);
            }
            if ($all) {
                $add('scholarships:all', 'scholarships', 'Scholarships and fee concessions', implode("\n", $all), chatbot_route('/admissions#scholarships'), ['keywords' => 'scholarship scholarships concession waiver discount']);
            }
        }
        if ($on['faqs']) {
            foreach (db_all("SELECT id, category, question, answer FROM faqs WHERE status = 'active' ORDER BY sort_order, id") as $q) {
                $url = in_array($q['category'], ['admissions', 'fees'], true) ? chatbot_route('/admissions#faq') : chatbot_route('/contact#faq');
                $add('faq:' . $q['id'], 'faq', $q['question'], chatbot_plain($q['answer'], 900), $url, ['keywords' => $q['category'], 'question' => $q['question']]);
            }
        }
        if ($on['notices']) {
            $rows = db_all("SELECT title, slug, category, description, publish_date FROM notices
                            WHERE status = 'published' AND show_on_website = 1 AND (publish_date IS NULL OR publish_date <= NOW()) AND (expiry_date IS NULL OR expiry_date >= NOW())
                            ORDER BY is_pinned DESC, publish_date DESC LIMIT 8");
            $lines = [];
            foreach ($rows as $i => $n) {
                $when = $n['publish_date'] ? date('j M Y', strtotime($n['publish_date'])) : '';
                $lines[] = $n['title'] . ($when ? ' (' . $when . ')' : '');
                $add('notice:' . $i, 'notice', 'Notice: ' . $n['title'], ($when ? 'Published ' . $when . '. ' : '') . chatbot_plain($n['description'], 400), chatbot_route('/notices/' . $n['slug']), ['keywords' => 'notice circular announcement ' . $n['category']]);
            }
            if ($lines) {
                $add('notices:latest', 'notices', 'Latest notices', 'Latest notices: ' . implode('; ', $lines) . '.', chatbot_route('/notices'), ['keywords' => 'notice notices circular announcement news update']);
            }
        }
        if ($on['events']) {
            $rows = db_all("SELECT title, slug, excerpt, venue, start_datetime FROM events
                            WHERE status = 'published' AND show_on_website = 1 AND start_datetime >= NOW() ORDER BY start_datetime LIMIT 6");
            $lines = [];
            foreach ($rows as $i => $ev) {
                $when = date('j M Y, g:i A', strtotime($ev['start_datetime']));
                $lines[] = $ev['title'] . ' — ' . $when . ($ev['venue'] ? ' at ' . $ev['venue'] : '');
                $add('event:' . $i, 'event', 'Event: ' . $ev['title'], $when . ($ev['venue'] ? ', ' . $ev['venue'] : '') . '. ' . chatbot_plain($ev['excerpt'], 300), chatbot_route('/events/' . $ev['slug']), ['keywords' => 'event events fest seminar workshop webinar']);
            }
            if ($lines) {
                $add('events:upcoming', 'events', 'Upcoming events', 'Upcoming events: ' . implode('; ', $lines) . '.', chatbot_route('/events'), ['keywords' => 'event events fest seminar workshop webinar upcoming']);
            }
        }
        if ($on['hostel']) {
            $rows = db_all("SELECT name, type, annual_fee, mess_fee, amenities, visiting_hours, description FROM hostels WHERE status = 'active' ORDER BY name");
            if ($rows) {
                $lines = array_map(function ($h) {
                    $am = chatbot_plain($h['amenities'], 200);
                    return $h['name'] . ' (' . ucfirst((string) $h['type']) . ')' . ((float) $h['annual_fee'] > 0 ? ': hostel fee ' . money($h['annual_fee']) . ' per year' : '')
                        . ((float) $h['mess_fee'] > 0 ? ' + mess ' . money($h['mess_fee']) . ' per year' : '') . ($am ? '. Facilities: ' . str_replace('_', ' ', $am) : '') . '.';
                }, $rows);
                $add('hostels:all', 'hostel', 'Hostels and fees', implode("\n", $lines), chatbot_route('/campus#hostel'), ['keywords' => 'hostel hostels accommodation room mess boys girls']);
            }
        }
        if ($on['transport']) {
            $rows = db_all("SELECT name, start_point, annual_fare FROM transport_routes WHERE status = 'active' ORDER BY annual_fare");
            if ($rows) {
                $fares = array_filter(array_map(fn ($r) => (float) $r['annual_fare'], $rows));
                $text = count($rows) . ' bus/van routes with pick-up points at ' . implode('; ', array_slice(array_unique(array_map(fn ($r) => $r['start_point'], $rows)), 0, 14)) . '.';
                if ($fares) {
                    $text .= ' Annual transport fare: ' . money(min($fares)) . ' to ' . money(max($fares)) . ' depending on the route.';
                }
                $add('transport:all', 'transport', 'Transport (college bus routes)', $text, chatbot_route('/campus#transport'), ['keywords' => 'transport bus buses route van fare pick drop']);
            }
        }
        if ($on['placements']) {
            $s = db_row("SELECT COUNT(*) offers, COUNT(DISTINCT CASE WHEN status = 'accepted' THEN student_id END) placed, COUNT(DISTINCT company_id) companies,
                                MAX(package_lpa) max_pkg, AVG(CASE WHEN status = 'accepted' THEN package_lpa END) avg_pkg, MAX(YEAR(offer_date)) yr
                         FROM placement_offers WHERE offer_date >= ?", [date('Y-m-d', strtotime('-18 months'))]);
            if ($s && (int) $s['offers'] > 0) {
                $top = db_column("SELECT c.name FROM placement_offers o JOIN companies c ON c.id = o.company_id WHERE o.offer_date >= ? GROUP BY c.id, c.name ORDER BY COUNT(*) DESC, c.name LIMIT 6",
                    [date('Y-m-d', strtotime('-18 months'))]);
                $text = $s['yr'] . ' placement season: ' . (int) $s['offers'] . ' job offers from ' . (int) $s['companies'] . ' recruiters; ' . (int) $s['placed'] . ' students placed (accepted offers).'
                    . ' Highest package: ' . rtrim(rtrim(number_format((float) $s['max_pkg'], 2), '0'), '.') . ' LPA.'
                    . ($s['avg_pkg'] ? ' Average package (accepted offers): ' . number_format((float) $s['avg_pkg'], 2) . ' LPA.' : '')
                    . ($top ? ' Top recruiters: ' . implode(', ', $top) . '.' : '');
                $add('placements:stats', 'placement', 'Placement statistics', $text, chatbot_route('/placement'), ['keywords' => 'placement placements package salary lpa recruiters companies job jobs highest average',
                    'stats' => ['year' => (string) $s['yr'], 'offers' => (int) $s['offers'], 'companies' => (int) $s['companies'], 'placed' => (int) $s['placed'],
                        'max' => rtrim(rtrim(number_format((float) $s['max_pkg'], 2), '0'), '.'), 'avg' => $s['avg_pkg'] ? number_format((float) $s['avg_pkg'], 2) : null, 'top' => $top]]);
            }
        }
        if ($on['pages']) {
            foreach (db_all("SELECT title, slug, excerpt, content FROM pages WHERE status = 'published' ORDER BY sort_order, id") as $pg) {
                $body = chatbot_plain(($pg['excerpt'] ? $pg['excerpt'] . "\n" : '') . $pg['content']);
                if (mb_strlen($body) < 160) {
                    continue;
                }
                $url = chatbot_route('/' . $pg['slug']);
                // passages of ~700 characters split on paragraph boundaries
                $chunks = [];
                $cur = '';
                foreach (preg_split("/\n+/", $body) as $para) {
                    if ($cur !== '' && mb_strlen($cur) + mb_strlen($para) > 700) {
                        $chunks[] = $cur;
                        $cur = '';
                    }
                    $cur .= ($cur === '' ? '' : ' ') . $para;
                }
                if ($cur !== '') {
                    $chunks[] = $cur;
                }
                foreach (array_slice($chunks, 0, 8) as $i => $c) {
                    $add('page:' . $pg['slug'] . ':' . $i, 'page', $pg['title'], $c, $url, ['keywords' => str_replace('-', ' ', $pg['slug'])]);
                }
            }
        }
    } catch (Throwable $e) {
        log_system('warning', 'Chatbot knowledge base: a source failed to load', ['error' => $e->getMessage()]);
    }
    return $out;
}

/* ------------------------------------------------------------------ Text processing */

/** Lower-case + unify program abbreviations (B.Tech → btech, M.B.A → mba …). */
function chatbot_normalize(string $text): string
{
    $s = mb_strtolower($text, 'UTF-8');
    $s = str_replace(['’', '‘', '`'], "'", $s);
    // program names written in Devanagari
    $s = str_replace(['बी.टेक', 'बी. टेक', 'बीटेक', 'एम.टेक', 'एमटेक', 'एमबीए', 'एम.बी.ए', 'बीबीए', 'बी.बी.ए', 'बीसीए', 'बी.सी.ए', 'एमसीए', 'एम.सी.ए', 'बी.कॉम', 'बीकॉम', 'एम.कॉम', 'एमकॉम', 'बी.एससी', 'बीएससी', 'डीसीए'],
        [' btech ', ' btech ', ' btech ', ' mtech ', ' mtech ', ' mba ', ' mba ', ' bba ', ' bba ', ' bca ', ' bca ', ' mca ', ' mca ', ' bcom ', ' bcom ', ' mcom ', ' mcom ', ' bsc ', ' bsc ', ' dca '], $s);
    $map = [
        '/\bb\.?\s?tech\b/u' => 'btech', '/\bm\.?\s?tech\b/u' => 'mtech', '/\bb\.?\s?com\b/u' => 'bcom', '/\bm\.?\s?com\b/u' => 'mcom',
        '/\bb\.?\s?sc\b/u' => 'bsc', '/\bm\.?\s?sc\b/u' => 'msc', '/\bb\.\s?b\.\s?a\b\.?/u' => 'bba', '/\bm\.\s?b\.\s?a\b\.?/u' => 'mba',
        '/\bb\.\s?c\.\s?a\b\.?/u' => 'bca', '/\bm\.\s?c\.\s?a\b\.?/u' => 'mca', '/\bph\.?\s?d\b/u' => 'phd', '/\bai\s?&\s?ds\b/u' => 'ai ds',
        '/\bc\.?s\.?e\b/u' => 'cse', '/\bwhats\s+app\b/u' => 'whatsapp',
    ];
    return (string) preg_replace(array_keys($map), array_values($map), $s);
}

function chatbot_stopwords(): array
{
    static $set = null;
    return $set ??= array_fill_keys(explode(' ',
        'a an the is are am was were be been being what which who whom whose how do does did i me my we our you your for of in on at to and or about can could please '
        . 'tell give show details detail information info want wanted know need there any it its this that these those with from by will would should shall get have has had '
        . 'gimt college institute institution university uni some much many more most also just like dear sir madam mam maam hello hi hey ok okay yes no not '
        . 'kya hai hain ka ki ke ko mein me se aur ya batao bataiye bataye bata mujhe muje chahiye chaiye kaise kaun kon sa h he ho hota hoti hote karna karni krna liye bhi toh ek wala wali '
        . 'aap apka aapka apke aapke sakta sakte sakti kar kare kariye raha rahi rahe tha thi the hu hoon hun yeh ye woh wo isme iske uske '
        . 'क्या है हैं का की के को में से और या बताइए बताओ बताएं मुझे चाहिए कैसे कौन सा लिए भी तो एक आप आपका आपके सकता सकते हूँ हूं यह वह'), true);
}

/** token => canonical concept (English, Hinglish, Hindi spellings + common misspellings). */
function chatbot_synonyms(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $groups = [
        'fee' => 'fee fees fess feez cost costs price pricing charge charges tuition shulk kharcha kharch amount फीस फ़ीस शुल्क खर्च',
        'scholarship' => 'scholarship scholarships scolarship scholership schlorship scholar concession concessions waiver discount discounts chatravriti छात्रवृत्ति स्कॉलरशिप स्कालरशिप',
        'hostel' => 'hostel hostels hostal accommodation accomodation stay dorm dormitory mess हॉस्टल होस्टल छात्रावास',
        'transport' => 'transport transportation bus buses van route routes pickup conveyance बस ट्रांसपोर्ट',
        'placement' => 'placement placements placed job jobs package packages salary salaries recruiter recruiters recruitment lpa naukri नौकरी प्लेसमेंट',
        'eligibility' => 'eligibility eligible criteria qualification qualifications yogyata patrata योग्यता पात्रता',
        'admission' => 'admission admissions apply applying application applications enrol enroll enrolment enrollment registration dakhila दाखिला प्रवेश एडमिशन आवेदन',
        'program' => 'course courses program programs programme programmes degree degrees branch branches stream कोर्स पाठ्यक्रम प्रोग्राम',
        'document' => 'document documents docs papers certificates certificate kagaz दस्तावेज़ दस्तावेज कागज़ात',
        'contact' => 'contact phone mobile helpline telephone number email mail संपर्क फोन नंबर',
        'counsellor' => 'counsellor counselor counsellors counselors counselling counseling advisor callback',
        'location' => 'location address located where kahan kaha directions map कहाँ कहां पता',
        'deadline' => 'deadline deadlines last closing',
        'campus' => 'campus facility facilities infrastructure library lab labs sports gym wifi canteen cafeteria',
        'entrance' => 'entrance exam exams test interview cuet cat mat xat jee cmat',
        'placementinternship' => 'internship internships intern',
    ];
    $map = [];
    foreach ($groups as $canon => $words) {
        foreach (explode(' ', $words) as $w) {
            $map[$w] = $canon === 'placementinternship' ? 'internship' : $canon;
        }
    }
    return $map;
}

/** Normalised, stop-word-free, synonym-mapped tokens. */
function chatbot_tokens(string $text): array
{
    $words = preg_split('/[^\p{L}\p{M}\p{N}]+/u', chatbot_normalize($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $stop = chatbot_stopwords();
    $syn = chatbot_synonyms();
    $out = [];
    foreach ($words as $w) {
        if (isset($stop[$w]) || (mb_strlen($w) < 2 && !ctype_digit($w))) {
            continue;
        }
        if (isset($syn[$w])) {
            $out[] = $syn[$w];
            continue;
        }
        // light English stemming (programs → program, fees handled above)
        if (preg_match('/^[a-z]{5,}$/', $w)) {
            if (str_ends_with($w, 'ies')) {
                $w = substr($w, 0, -3) . 'y';
            } elseif (str_ends_with($w, 's') && !str_ends_with($w, 'ss') && !str_ends_with($w, 'us')) {
                $w = substr($w, 0, -1);
            }
            $w = $syn[$w] ?? $w;
        }
        $out[] = $w;
    }
    return $out;
}

/** en | hi | hinglish */
function chatbot_detect_language(string $text): string
{
    if (preg_match('/\p{Devanagari}/u', $text)) {
        return 'hi';
    }
    $markers = ['kya', 'hai', 'hain', 'kitni', 'kitna', 'kitne', 'kaise', 'kab', 'kahan', 'kaha', 'mujhe', 'muje', 'chahiye', 'chaiye', 'batao', 'bataiye', 'bataye', 'kaun', 'kon', 'milega', 'milegi',
        'hoga', 'hogi', 'karna', 'karni', 'krna', 'padhai', 'liye', 'mein', 'nahi', 'nahin', 'aap', 'aur', 'ki', 'ke', 'ko', 'bhi', 'btao', 'bta', 'batayein', 'kaisa', 'kaisi', 'kitna', 'chahta', 'chahti', 'apka', 'aapka', 'sakta', 'sakte', 'sakti', 'hoon', 'hu', 'yaar', 'bhai', 'ji', 'kripya', 'dhanyavad', 'shukriya'];
    $words = preg_split('/[^a-z]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $hits = count(array_intersect($words, $markers));
    return $hits >= 2 || ($hits === 1 && count($words) <= 4) ? 'hinglish' : 'en';
}

/* ------------------------------------------------------------------ Program detection */

/** Normalised words without stop words and without synonym mapping (program-name matching). */
function chatbot_words(string $text): array
{
    $words = preg_split('/[^\p{L}\p{M}\p{N}]+/u', chatbot_normalize($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $stop = chatbot_stopwords();
    $out = [];
    foreach ($words as $w) {
        if (isset($stop[$w])) {
            continue;
        }
        if (preg_match('/^[a-z]{5,}s$/', $w) && !str_ends_with($w, 'ss')) {
            $w = substr($w, 0, -1);
        }
        $out[] = $w;
    }
    return $out;
}

/** Programs a question refers to, best first (max 3). */
function chatbot_detect_programs(string $text): array
{
    $n = ' ' . preg_replace('/[^\p{L}\p{N}]+/u', ' ', chatbot_normalize($text)) . ' ';
    $words = array_flip(chatbot_words($text));
    $generic = array_flip(['bachelor', 'master', 'program', 'programme', 'course', 'honour', 'honours', 'hons', 'certificate', 'diploma', 'degree', 'in', 'of', 'and', 'the']);
    $scores = [];
    foreach (chatbot_programs_data() as $p) {
        $score = 0;
        $short = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', chatbot_normalize((string) $p['short_name'])));
        $code = strtolower(trim((string) $p['code']));
        if ($short !== '' && str_contains($n, ' ' . $short . ' ')) {
            $score += 10;
        } elseif ($short !== '' && str_contains($short, ' ')) {
            // "btech cse": degree + branch = strong, degree alone = weak, branch alone ("cse", "ai") = medium
            $parts = explode(' ', $short);
            $degree = str_contains($n, ' ' . $parts[0] . ' ');
            $branch = array_values(array_filter(array_slice($parts, 1), fn ($w) => !isset($generic[$w]) && mb_strlen($w) >= 2));
            $bh = count(array_filter($branch, fn ($w) => str_contains($n, ' ' . $w . ' ')));
            $score += match (true) {
                $degree && $bh === count($branch) => 10,
                $degree && $bh > 0 => 8,
                $degree => 4,
                $bh === count($branch) && $bh > 0 => 6,
                $bh > 0 && count(array_filter($branch, fn ($w) => mb_strlen($w) <= 3 && str_contains($n, ' ' . $w . ' '))) > 0 => 6, // "ai", "cse", "ds"
                default => 0,
            };
        }
        if ($code !== '' && strlen($code) >= 3 && str_contains($n, ' ' . $code . ' ')) {
            $score += 6;
        }
        // distinctive name words: "computer science", "artificial intelligence", "digital marketing", "data analytics"
        $nameWords = array_values(array_unique(array_filter(chatbot_words($p['name']), fn ($w) => !isset($generic[$w]) && mb_strlen($w) > 1)));
        $hits = count(array_filter($nameWords, fn ($w) => isset($words[$w])));
        if ($nameWords && $hits === count($nameWords) && (count($nameWords) >= 2 || mb_strlen($nameWords[0]) >= 6)) {
            $score += 7;
        } elseif ($hits >= 2) {
            $score += 4;
        }
        if ($score > 0) {
            $scores[(int) $p['id']] = ['score' => $score, 'p' => $p];
        }
    }
    if (!$scores) {
        return [];
    }
    uasort($scores, fn ($a, $b) => $b['score'] <=> $a['score']);
    $best = reset($scores)['score'];
    $out = [];
    foreach ($scores as $s) {
        if ($s['score'] >= 4 && $s['score'] >= $best - 3) {
            $out[] = $s['p'];
        }
    }
    return array_slice($out, 0, 3);
}

/* ------------------------------------------------------------------ Retrieval */

/** Intent → snippet ids / types that should always be in the context for that topic. */
function chatbot_intent_snippets(): array
{
    return [
        'fees' => ['programs:fees'], 'scholarship' => ['scholarships:all'], 'hostel' => ['hostels:all'], 'transport' => ['transport:all'],
        'placement' => ['placements:stats'], 'notices' => ['notices:latest'], 'events' => ['events:upcoming'], 'departments' => ['departments:all'],
        'dates' => ['facts:dates'], 'admission' => ['facts:dates'], 'contact' => ['facts:contact'], 'location' => ['facts:contact'], 'counsellor' => ['facts:contact'],
        'affiliation' => ['facts:affiliation'],
    ];
}

/**
 * Most relevant snippets for a question: BM25 over title + text, plus forced snippets for detected intents and the
 * detail snippet of each detected program.
 * @return array<int, array> snippets with a 'score'
 */
function chatbot_retrieve(string $question, array $intents = [], array $programs = [], int $k = 6): array
{
    $docs = chatbot_snippets();
    if (!$docs) {
        return [];
    }
    $q = array_unique(chatbot_tokens($question));
    $byId = [];
    foreach ($docs as $d) {
        $byId[$d['id']] = $d;
    }
    $picked = [];
    foreach ($programs as $p) {
        if (isset($byId['program:' . $p['id']])) {
            $picked['program:' . $p['id']] = $byId['program:' . $p['id']] + ['score' => 100];
        }
    }
    $forced = chatbot_intent_snippets();
    foreach ($intents as $intent) {
        foreach ($forced[$intent] ?? [] as $id) {
            if (isset($byId[$id]) && !isset($picked[$id])) {
                $picked[$id] = $byId[$id] + ['score' => 50];
            }
        }
    }
    if ($q) {
        $n = count($docs);
        $df = [];
        $len = 0;
        foreach ($docs as $d) {
            $len += count($d['tokens']);
            foreach (array_unique($d['tokens']) as $t) {
                $df[$t] = ($df[$t] ?? 0) + 1;
            }
        }
        $avg = max(1, $len / $n);
        $k1 = 1.2;
        $b = 0.75;
        $scored = [];
        foreach ($docs as $d) {
            $tf = array_count_values($d['tokens']);
            $dl = count($d['tokens']);
            $score = 0.0;
            foreach ($q as $t) {
                if (!isset($tf[$t])) {
                    continue;
                }
                $idf = log(1 + ($n - $df[$t] + 0.5) / ($df[$t] + 0.5));
                $score += $idf * ($tf[$t] * ($k1 + 1)) / ($tf[$t] + $k1 * (1 - $b + $b * $dl / $avg));
            }
            if ($score <= 0) {
                continue;
            }
            if ($d['type'] === 'faq') {
                // FAQ questions that share most query words are usually the answer
                $qt = array_unique(chatbot_tokens($d['question'] ?? $d['title']));
                if ($qt) {
                    $score *= 1 + 0.6 * count(array_intersect($qt, $q)) / count($qt);
                }
            }
            foreach ($programs as $p) {
                if (($d['program_id'] ?? null) === (int) $p['id']) {
                    $score += 3;
                }
            }
            $scored[$d['id']] = $d + ['score' => round($score, 3)];
        }
        uasort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        foreach ($scored as $id => $d) {
            if (count($picked) >= $k + count($programs)) {
                break;
            }
            if (!isset($picked[$id])) {
                $picked[$id] = $d;
            }
        }
    }
    return array_values($picked);
}

/** Best FAQ for a question (offline engine) with a confidence 0–1. */
function chatbot_best_faq(string $question, array $snippets): ?array
{
    $q = array_unique(chatbot_tokens($question));
    if (!$q) {
        return null;
    }
    $best = null;
    foreach ($snippets as $s) {
        if ($s['type'] !== 'faq') {
            continue;
        }
        $qt = array_unique(chatbot_tokens($s['question'] ?? $s['title']));
        if (!$qt) {
            continue;
        }
        $overlap = count(array_intersect($q, $qt));
        $conf = $overlap / max(count($q), min(count($qt), 4));
        if ($overlap > 0 && (!$best || $conf > $best['confidence'] || ($conf === $best['confidence'] && ($s['score'] ?? 0) > ($best['snippet']['score'] ?? 0)))) {
            $best = ['snippet' => $s, 'confidence' => round(min(1, $conf), 3)];
        }
    }
    return $best;
}
