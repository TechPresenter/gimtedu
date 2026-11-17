<?php
/**
 * AI search + answer engines (unit seo-core, 2026-11-17). Functions only — required by app/services/seo.php,
 * llms.php and api/routes/integrations.php.
 *
 *  1. AI crawler policy ... seo_ai_crawler_catalog() (official user-agent tokens + purpose, see docs/SEO.md),
 *                           seo_ai_crawler_policy() (allowed / blocked per token from the settings
 *                           ai_search_crawlers + ai_training_crawlers), seo_robots_ai_block() (the managed robots.txt
 *                           block between "# BEGIN GIMT AI CRAWLERS" / "# END GIMT AI CRAWLERS"),
 *                           seo_robots_with_ai_block() (custom robots.txt + managed block)
 *  2. llms.txt ............ seo_llms_txt(bool $full) — /llms.txt and /llms-full.txt (llmstxt.org format), generated only
 *                           from the database + settings (programs, CMS pages, routes, FAQs, contact settings),
 *                           cached in storage/cache/seo by a content fingerprint.
 *
 * Search engines (Googlebot, Bingbot, YandexBot, Baiduspider, Yeti, DuckDuckBot, Applebot) are never part of the AI
 * policy: they always follow the "User-agent: *" group.
 */

if (!defined('APP_ROOT')) {
    http_response_code(404);
    exit;
}

/* =====================================================================================================================
 * 1. AI crawler policy
 * ===================================================================================================================*/

const SEO_AI_BLOCK_BEGIN = '# BEGIN GIMT AI CRAWLERS';
const SEO_AI_BLOCK_END = '# END GIMT AI CRAWLERS';

/**
 * AI user-agent tokens from the operators' own documentation (checked 2026-11-17). purpose: search = builds an AI
 * search / answer index (citations), user = fetches a page live for a user's prompt, training = model training (or a
 * control token for it). robots: yes = documented to obey robots.txt, partial = may ignore it, unknown = not documented.
 * The setting ai_search_crawlers governs "search" + "user"; ai_training_crawlers governs "training".
 */
function seo_ai_crawler_catalog(): array
{
    $openai = 'https://developers.openai.com/api/docs/bots';
    $anthropic = 'https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler';
    $perplexity = 'https://docs.perplexity.ai/guides/bots';
    $meta = 'https://developers.facebook.com/docs/sharing/webmasters/web-crawlers';
    $amazon = 'https://developer.amazon.com/amazonbot';
    $google = 'https://developers.google.com/search/docs/crawling-indexing/google-common-crawlers';
    return [
        // AI search / answer indexes — blocking them removes the site from those answers and citations
        ['bot' => 'OAI-SearchBot', 'vendor' => 'OpenAI', 'purpose' => 'search', 'robots' => 'yes', 'doc' => $openai,
            'note' => 'ChatGPT search answers and citations.'],
        ['bot' => 'Claude-SearchBot', 'vendor' => 'Anthropic', 'purpose' => 'search', 'robots' => 'yes', 'doc' => $anthropic,
            'note' => 'Indexing for Claude search results.'],
        ['bot' => 'PerplexityBot', 'vendor' => 'Perplexity', 'purpose' => 'search', 'robots' => 'yes', 'doc' => $perplexity,
            'note' => 'Perplexity search index; not used to train foundation models.'],
        ['bot' => 'meta-webindexer', 'vendor' => 'Meta', 'purpose' => 'search', 'robots' => 'yes', 'doc' => $meta,
            'note' => 'Meta AI search citations.'],
        ['bot' => 'DuckAssistBot', 'vendor' => 'DuckDuckGo', 'purpose' => 'search', 'robots' => 'yes', 'doc' => 'https://duckduckgo.com/duckduckgo-help-pages/results/duckassistbot',
            'note' => 'AI-assisted answers with sources; not used for training.'],
        ['bot' => 'Amzn-SearchBot', 'vendor' => 'Amazon', 'purpose' => 'search', 'robots' => 'yes', 'doc' => $amazon,
            'note' => 'Alexa and other Amazon search features; not used for training.'],
        // Live fetches made for one user's prompt
        ['bot' => 'ChatGPT-User', 'vendor' => 'OpenAI', 'purpose' => 'user', 'robots' => 'partial', 'doc' => $openai,
            'note' => 'Started by a ChatGPT user; OpenAI says robots.txt rules may not apply.'],
        ['bot' => 'Claude-User', 'vendor' => 'Anthropic', 'purpose' => 'user', 'robots' => 'yes', 'doc' => $anthropic,
            'note' => 'Fetches a page when a Claude user asks about it.'],
        ['bot' => 'Perplexity-User', 'vendor' => 'Perplexity', 'purpose' => 'user', 'robots' => 'partial', 'doc' => $perplexity,
            'note' => 'Started by a Perplexity user; generally ignores robots.txt.'],
        ['bot' => 'meta-externalfetcher', 'vendor' => 'Meta', 'purpose' => 'user', 'robots' => 'partial', 'doc' => $meta,
            'note' => 'User-initiated fetches; may bypass robots.txt.'],
        ['bot' => 'Amzn-User', 'vendor' => 'Amazon', 'purpose' => 'user', 'robots' => 'partial', 'doc' => $amazon,
            'note' => 'User-initiated fetches; may not follow every robots.txt rule.'],
        // Model training (blocking these does not remove the site from search or AI answers above)
        ['bot' => 'GPTBot', 'vendor' => 'OpenAI', 'purpose' => 'training', 'robots' => 'yes', 'doc' => $openai,
            'note' => 'Training of OpenAI foundation models. Does not affect ChatGPT search.'],
        ['bot' => 'ClaudeBot', 'vendor' => 'Anthropic', 'purpose' => 'training', 'robots' => 'yes', 'doc' => $anthropic,
            'note' => 'Training of Anthropic models.'],
        ['bot' => 'Google-Extended', 'vendor' => 'Google', 'purpose' => 'training', 'robots' => 'yes', 'doc' => $google,
            'note' => 'Control token (no crawler of its own): Gemini training and grounding in Gemini Apps / Vertex AI. Does not affect Google Search or AI Overviews.'],
        ['bot' => 'Applebot-Extended', 'vendor' => 'Apple', 'purpose' => 'training', 'robots' => 'yes', 'doc' => 'https://support.apple.com/en-us/119829',
            'note' => 'Control token (no crawler of its own): training of Apple foundation models. Pages stay in Apple search.'],
        ['bot' => 'Amazonbot', 'vendor' => 'Amazon', 'purpose' => 'training', 'robots' => 'yes', 'doc' => $amazon,
            'note' => 'May be used to train Amazon AI models.'],
        ['bot' => 'meta-externalagent', 'vendor' => 'Meta', 'purpose' => 'training', 'robots' => 'yes', 'doc' => $meta,
            'note' => 'Crawls for use cases such as training Meta AI models.'],
        ['bot' => 'CCBot', 'vendor' => 'Common Crawl', 'purpose' => 'training', 'robots' => 'yes', 'doc' => 'https://commoncrawl.org/ccbot',
            'note' => 'Open web dataset that many AI models are trained on (Common Crawl does not state a purpose).'],
        ['bot' => 'Bytespider', 'vendor' => 'ByteDance', 'purpose' => 'training', 'robots' => 'unknown', 'doc' => null,
            'note' => 'No first-party documentation found; robots.txt compliance is unverified.'],
    ];
}

/** User-agent tokens named in a robots.txt outside the managed AI block (lower case). */
function seo_robots_named_agents(string $txt): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', seo_robots_strip_ai_block($txt)) as $line) {
        if (preg_match('/^\s*user-agent\s*:\s*(\S+)/i', $line, $m) && $m[1] !== '*') {
            $out[strtolower($m[1])] = true;
        }
    }
    return array_keys($out);
}

/**
 * The policy as rows: [bot, vendor, purpose, allowed, robots, doc, note, overridden]. "overridden" = the custom
 * robots.txt already has its own group for that token (the managed block then leaves it alone).
 */
function seo_ai_crawler_policy(): array
{
    $search = seo_integration_value('ai_search_crawlers', 'allow') !== 'block';
    $training = seo_integration_value('ai_training_crawlers', 'allow') !== 'block';
    $custom = trim((string) setting('robots_txt', ''));
    $named = $custom !== '' ? seo_robots_named_agents($custom) : [];
    $out = [];
    foreach (seo_ai_crawler_catalog() as $c) {
        $out[] = $c + [
            'allowed' => $c['purpose'] === 'training' ? $training : $search,
            'overridden' => in_array(strtolower($c['bot']), $named, true),
        ];
    }
    return $out;
}

/** Remove the managed block (markers included) from a robots.txt. */
function seo_robots_strip_ai_block(string $txt): string
{
    $txt = str_replace("\r\n", "\n", $txt);
    $re = '/\n*^' . preg_quote(SEO_AI_BLOCK_BEGIN, '/') . '.*?^' . preg_quote(SEO_AI_BLOCK_END, '/') . '[^\n]*\n?/ms';
    return rtrim((string) preg_replace($re, "\n", $txt));
}

/**
 * The managed robots.txt block. $baseRules = the "User-agent: *" rules as [[allow(bool), pattern], …]: allowed AI
 * tokens get one group that repeats them (a crawler that matches a named group ignores "*"), blocked tokens one group
 * with "Disallow: /". Tokens the custom robots.txt already names ($skip) are left out.
 */
function seo_robots_ai_block(array $baseRules, array $skip = []): string
{
    $policy = seo_ai_crawler_policy();
    $skip = array_map('strtolower', $skip);
    $allowed = [];
    $blocked = [];
    foreach ($policy as $p) {
        if (in_array(strtolower($p['bot']), $skip, true)) {
            continue;
        }
        if ($p['allowed']) {
            $allowed[] = $p['bot'];
        } else {
            $blocked[] = $p['bot'];
        }
    }
    if (!$allowed && !$blocked) {
        return '';
    }
    $search = seo_integration_value('ai_search_crawlers', 'allow') !== 'block';
    $training = seo_integration_value('ai_training_crawlers', 'allow') !== 'block';
    $lines = [SEO_AI_BLOCK_BEGIN . ' (managed: Settings > Integrations > AI search & llms.txt; edits inside this block are replaced)',
        '# AI search and answer engines: ' . ($search ? 'allowed' : 'blocked') . ' · AI model training: ' . ($training ? 'allowed' : 'blocked'),
        '# Search engines (Googlebot, Bingbot, YandexBot, Baiduspider, Yeti, DuckDuckBot, Applebot) follow the "*" group.'];
    if ($allowed) {
        foreach ($allowed as $bot) {
            $lines[] = 'User-agent: ' . $bot;
        }
        $rules = $baseRules ?: [[true, '/']];
        // "Allow: /" only when the "*" group has no rule for "/" itself: next to a "Disallow: /" it would win the tie
        // (Allow wins equal-length matches) and open a site the "*" group closes
        if (!array_filter($rules, fn ($r) => $r[1] === '/')) {
            $lines[] = 'Allow: /';
        }
        foreach ($rules as [$allow, $pattern]) {
            $lines[] = ($allow ? 'Allow: ' : 'Disallow: ') . $pattern;
        }
        $lines[] = '';
    }
    if ($blocked) {
        foreach ($blocked as $bot) {
            $lines[] = 'User-agent: ' . $bot;
        }
        $lines[] = 'Disallow: /';
        $lines[] = '';
    }
    $lines[] = SEO_AI_BLOCK_END;
    return implode("\n", $lines) . "\n";
}

/** A custom robots.txt with the managed AI block appended (an older managed block inside it is replaced). */
function seo_robots_with_ai_block(string $custom): string
{
    $body = seo_robots_strip_ai_block($custom);
    $block = seo_robots_ai_block(seo_robots_rules($body . "\n"), seo_robots_named_agents($body));
    return rtrim($body) . "\n" . ($block !== '' ? "\n" . $block : '');
}

/* =====================================================================================================================
 * 2. llms.txt + llms-full.txt
 * ===================================================================================================================*/

/** Is /llms.txt served? (off when the setting is off or the whole site is blocked from indexing) */
function seo_llms_enabled(): bool
{
    return seo_integration_value('llms_txt_enabled', '1') === '1' && setting('seo_block_indexing', '0') !== '1';
}

/** Change fingerprint of everything the llms files contain (the sitemap fingerprint + FAQs, settings, fees). */
function seo_llms_fingerprint(): string
{
    $parts = [seo_sitemap_fingerprint()];
    foreach (['faqs' => 'updated_at', 'settings' => 'updated_at', 'fee_structures' => 'updated_at', 'academic_sessions' => 'updated_at'] as $t => $col) {
        try {
            $r = db_row("SELECT COUNT(*) n, MAX($col) m FROM $t");
            $parts[] = $t . ':' . ($r['n'] ?? 0) . ':' . ($r['m'] ?? '');
        } catch (Throwable $e) {
            $parts[] = $t . ':-';
        }
    }
    return md5(implode('|', $parts));
}

/** /llms.txt ($full = false) or /llms-full.txt. The admin override (llms_txt_custom) replaces /llms.txt only. */
function seo_llms_txt(bool $full = false): string
{
    $custom = trim(str_replace("\r\n", "\n", (string) seo_integration_value('llms_txt_custom', '')));
    if (!$full && $custom !== '') {
        return $custom . "\n";
    }
    $dir = seo_sitemap_cache_dir();
    $file = $dir . '/llms-' . ($full ? 'full' : 'index') . '.txt';
    $fp = seo_llms_fingerprint();
    if (is_file($file) && is_file($file . '.fp') && trim((string) @file_get_contents($file . '.fp')) === $fp && filemtime($file) > time() - 6 * 3600) {
        return (string) file_get_contents($file);
    }
    $txt = seo_llms_build($full);
    if (is_dir($dir) || @mkdir($dir, 0775, true)) {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $txt) !== false) {
            @rename($tmp, $file);
            @file_put_contents($file . '.fp', $fp);
        }
    }
    return $txt;
}

/** One line of plain text (tags stripped, whitespace collapsed, optional length cap). */
function seo_llms_text(?string $s, int $max = 0): string
{
    $s = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], ' ', (string) $s)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = trim((string) preg_replace('/\s+/u', ' ', $s));
    return $max > 0 ? str_limit($s, $max) : $s;
}

/** Lines of a multi-line text column (highlights, career prospects). */
function seo_llms_lines(?string $s): array
{
    $s = (string) preg_replace('#<(br|/p|/li)\s*/?>#i', "\n", (string) $s);
    return array_values(array_filter(array_map(fn ($l) => seo_llms_text(ltrim($l, "-*•· \t")), preg_split('/\r\n|\r|\n/', $s)), fn ($l) => $l !== ''));
}

/** Markdown link text / note: no brackets or newlines that would break the list item. */
function seo_llms_md(string $s): string
{
    return trim(str_replace(['[', ']', "\n", "\r"], ['(', ')', ' ', ''], $s));
}

/** Program level code → label (UG → Undergraduate …). */
function seo_llms_level(?string $level): string
{
    $l = strtolower(trim((string) $level));
    return ['ug' => 'Undergraduate', 'pg' => 'Postgraduate', 'diploma' => 'Diploma', 'certificate' => 'Certificate', 'doctoral' => 'Doctoral', 'phd' => 'Doctoral'][$l] ?? ucfirst((string) $level);
}

/** Fee exactly as the website shows it on program cards and the program hero ("₹1,20,000 / Year"), or ''. */
function seo_llms_fee(array $p): string
{
    return (float) ($p['fee_per_year'] ?? 0) > 0 ? money($p['fee_per_year']) . ' ' . trim((string) ($p['fee_label'] ?: '/ Year')) : '';
}

/** Duration as stored ("3 Years", "6 Months"). */
function seo_llms_duration(array $p): string
{
    if (trim((string) ($p['duration_label'] ?? '')) !== '') {
        return trim((string) $p['duration_label']);
    }
    $y = (float) ($p['duration_years'] ?? 0);
    if ($y <= 0) {
        return '';
    }
    return $y < 1 ? (int) round($y * 12) . ' months' : rtrim(rtrim(number_format($y, 1), '0'), '.') . ' year' . ($y == 1.0 ? '' : 's');
}

/** Total program fee from the current session's fee structures (shown on the program page), or null. */
function seo_llms_structure_total(int $programId): ?array
{
    try {
        $s = db_row('SELECT id, name FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1');
        if (!$s) {
            return null;
        }
        $total = db_value("SELECT SUM(total_amount) FROM fee_structures WHERE program_id = ? AND academic_session_id = ? AND status = 'active'", [$programId, (int) $s['id']]);
        return $total !== null && (float) $total > 0 ? ['session' => (string) $s['name'], 'total' => (float) $total] : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Build the llms.txt / llms-full.txt document (llmstxt.org: H1, blockquote, paragraphs, H2 lists of links). */
function seo_llms_build(bool $full): string
{
    require_once APP_ROOT . '/app/services/cms.php';
    $groups = seo_sitemap_groups();
    $inSitemap = [];
    $lastmod = null;
    foreach ($groups as $list) {
        foreach ($list as $u) {
            $inSitemap[$u['path']] = $u;
            if (!empty($u['lastmod']) && ($lastmod === null || $u['lastmod'] > $lastmod)) {
                $lastmod = $u['lastmod'];
            }
        }
    }
    $seoRows = [];
    try {
        foreach (db_all("SELECT entity_type, entity_id, route, meta_description FROM seo_settings WHERE meta_description IS NOT NULL AND meta_description <> ''") as $r) {
            $seoRows[$r['entity_type'] === 'route' ? 'route:' . $r['route'] : $r['entity_type'] . ':' . (int) $r['entity_id']] = seo_llms_text($r['meta_description'], 300);
        }
    } catch (Throwable $e) {
    }
    $url = fn (string $path) => seo_path_url($path);
    $link = fn (string $label, string $path, string $note = '') => '- [' . seo_llms_md($label) . '](' . $url($path) . ')' . ($note !== '' ? ': ' . seo_llms_md($note) : '');
    $routes = cms_public_routes();
    $has = fn (string $path) => isset($inSitemap[$path]);

    $name = institute_name();
    $short = trim((string) setting('institute_short_name', ''));
    $summary = $seoRows['route:/'] ?? seo_llms_text((string) setting('footer_about', ''), 400);
    $out = ['# ' . $name . ($short !== '' && stripos($name, $short) === false ? ' (' . $short . ')' : ''), ''];
    if ($summary !== '') {
        $out[] = '> ' . $summary;
        $out[] = '';
    }
    // Contact facts — settings only
    $addr = implode(', ', array_filter(array_map(fn ($k) => trim((string) setting($k, '')), ['address'])));
    foreach (['city', 'state', 'pincode'] as $k) {
        $v = trim((string) setting($k, ''));
        if ($v !== '' && stripos($addr, $v) === false) {
            $addr .= ($addr !== '' ? ', ' : '') . $v;
        }
    }
    // Admissions facts confirmed by the owner (2026-11-17): the same helpers the program pages and FAQs use. A real,
    // still-open admissions_deadline replaces the rolling wording (as in the chatbot); empty or past = rolling.
    try {
        require_once APP_ROOT . '/includes/partials/site_academic.php';
    } catch (Throwable $e) {
    }
    $deadline = trim((string) setting('admissions_deadline', ''));
    $deadlineTs = $deadline !== '' ? strtotime($deadline) : false;
    $facts = array_filter([
        $addr !== '' ? 'Address: ' . $addr : null,
        ($v = trim((string) setting('phone', ''))) !== '' ? 'Phone: ' . $v : null,
        ($v = trim((string) (setting('admission_email') ?: setting('email', '')))) !== '' ? 'Email: ' . $v : null,
        ($v = trim((string) setting('established_year', ''))) !== '' ? 'Established: ' . $v : null,
        'Website: ' . seo_site_url(),
        'Admissions: ' . ($deadlineTs && $deadlineTs >= time() ? 'Applications close on ' . date('j M Y', $deadlineTs) : 'Rolling admissions — until seats are filled'),
        function_exists('sa_application_fee') ? 'Application (registration) fee: ' . sa_application_fee() : null,
        function_exists('sa_scholarship_statement') ? 'Scholarships: ' . sa_scholarship_statement() . '.' : null,
        function_exists('sa_placement_statement') ? 'Placements: ' . sa_placement_statement() : null,
        function_exists('sa_disambiguation_statement') && sa_disambiguation_statement() !== '' ? 'Same-name listings: ' . preg_replace('/^No\.\s*/', '', sa_disambiguation_statement()) : null,
    ]);
    foreach ($facts as $f) {
        $out[] = '- ' . $f;
    }
    $out[] = '';
    $out[] = 'Last updated: ' . ($lastmod ?? date('Y-m-d')) . '. Generated from the website database; programmes, fees and eligibility are the same as on the linked pages.';
    $out[] = '';

    // Programs by level
    $programs = [];
    try {
        $programs = db_all("SELECT p.*, d.name AS department_name FROM programs p LEFT JOIN departments d ON d.id = p.department_id
                            WHERE p.status = 'active' AND p.show_on_website = 1 ORDER BY p.sort_order, p.name");
    } catch (Throwable $e) {
    }
    $programs = array_values(array_filter($programs, fn ($p) => $has('/programs/' . $p['slug'])));
    $byLevel = [];
    foreach ($programs as $p) {
        $byLevel[seo_llms_level($p['level'])][] = $p;
    }
    $order = ['Undergraduate', 'Postgraduate', 'Doctoral', 'Diploma', 'Certificate'];
    uksort($byLevel, fn ($a, $b) => (array_search($a, $order, true) === false ? 99 : array_search($a, $order, true)) <=> (array_search($b, $order, true) === false ? 99 : array_search($b, $order, true)));
    $progLabel = function (array $p): string {
        $n = (string) $p['name'];
        $s = trim((string) $p['short_name']);
        return $s !== '' && stripos($n, $s) === false && !str_contains($s, '(') ? "$n ($s)" : $n;
    };
    foreach ($byLevel as $level => $list) {
        $out[] = '## ' . ($level === 'Certificate' ? 'Certificate courses' : $level . ' programmes');
        $out[] = '';
        foreach ($list as $p) {
            $note = implode(' · ', array_filter([
                $level === 'Certificate' ? null : $level,
                seo_llms_duration($p),
                ($fee = seo_llms_fee($p)) !== '' ? 'Fee ' . $fee : null,
                ($el = seo_llms_text($p['eligibility'], $full ? 0 : 160)) !== '' ? 'Eligibility: ' . $el : null,
            ]));
            $out[] = $link($progLabel($p), '/programs/' . $p['slug'], $note);
        }
        $out[] = '';
    }

    // Admissions and student-facing pages
    $pages = [];
    try {
        $pages = db_all("SELECT id, slug, title, is_system, template FROM pages WHERE status = 'published' AND (published_at IS NULL OR published_at <= NOW()) ORDER BY sort_order, title");
    } catch (Throwable $e) {
    }
    $pageBySlug = [];
    foreach ($pages as $p) {
        $pageBySlug[$p['slug']] = $p;
    }
    $routeLink = function (string $path, ?string $label = null) use ($routes, $seoRows, $link, $has): ?string {
        if (!$has($path)) {
            return null;
        }
        return $link($label ?? ($routes[$path]['label'] ?? ucwords(str_replace('-', ' ', ltrim($path, '/')))), $path, $seoRows['route:' . $path] ?? '');
    };
    $pageLink = function (string $slug) use ($pageBySlug, $link, $has, $seoRows): ?string {
        $p = $pageBySlug[$slug] ?? null;
        if (!$p) {
            return null;
        }
        $path = cms_page_url($p);
        if (!$has($path)) {
            return null;
        }
        [$t, $ref] = cms_page_seo_ref($p);
        return $link((string) $p['title'], $path, $seoRows[$t . ':' . $ref] ?? '');
    };
    $used = ['/'];
    $section = function (string $title, array $lines) use (&$out) {
        $lines = array_values(array_filter($lines));
        if ($lines) {
            $out[] = '## ' . $title;
            $out[] = '';
            array_push($out, ...$lines);
            $out[] = '';
        }
    };
    $adm = [];
    foreach (['/admissions', '/apply', '/programs', '/certifications', '/placement', '/faq', '/contact'] as $r) {
        $adm[] = $routeLink($r);
        $used[] = $r;
    }
    foreach (['fee-policy', 'scholarship-policy', 'admission-policy', 'refund-and-cancellation-policy'] as $slug) {
        $adm[] = $pageLink($slug);
        $used[] = isset($pageBySlug[$slug]) ? cms_page_url($pageBySlug[$slug]) : null;
    }
    $section('Admissions, fees and contact', $adm);
    $about = [];
    foreach ($routes as $path => $r) {
        if (!in_array($path, $used, true) && !in_array($path, ['/blog', '/events', '/notices', '/gallery', '/verify-certificate'], true)) {
            $about[] = $routeLink($path);
            $used[] = $path;
        }
    }
    $section('About the institute', $about);
    $policies = [];
    $more = [];
    foreach ($pages as $p) {
        $path = cms_page_url($p);
        if (in_array($path, $used, true) || isset($routes[$path]) || !$has($path)) {
            continue;
        }
        $used[] = $path;
        if (preg_match('/polic|terms|disclaimer|disclosure|conduct|grievance|complaint|ragging|accessib|privacy|refund/i', (string) $p['slug'])) {
            $policies[] = $pageLink((string) $p['slug']);
        } else {
            $more[] = $pageLink((string) $p['slug']);
        }
    }
    $section('Policies and disclosures', $policies);
    $section('More pages', $more);

    if ($full) {
        try {
            require_once APP_ROOT . '/includes/partials/site_academic.php'; // sa_program_credits(): the credits the program pages show
        } catch (Throwable $e) {
        }
        foreach ($byLevel as $level => $list) {
            $out[] = '## ' . ($level === 'Certificate' ? 'Certificate course details' : $level . ' programme details');
            $out[] = '';
            foreach ($list as $p) {
                $out[] = '### ' . $progLabel($p);
                $out[] = '';
                $rows = array_filter([
                    'URL' => $url('/programs/' . $p['slug']),
                    'Level' => $level,
                    'Department' => trim((string) ($p['department_name'] ?? '')),
                    'Qualification' => trim((string) ($p['degree'] ?? '')),
                    'Duration' => seo_llms_duration($p),
                    'Semesters' => (int) $p['total_semesters'] > 0 ? (string) (int) $p['total_semesters'] : '',
                    // same source as the program pages: sa_program_credits() (sum of the curriculum's subject credits,
                    // else programs.total_credits; none for certificates)
                    'Credits' => ($cr = function_exists('sa_program_credits') ? sa_program_credits($p + ['level' => $level]) : (float) $p['total_credits']) > 0
                        ? (function_exists('sa_num') ? sa_num((float) $cr) : (string) (int) $cr) : '',
                    'Annual intake' => (int) $p['intake_capacity'] > 0 ? (int) $p['intake_capacity'] . ' seats' : '',
                    'Fee' => seo_llms_fee($p),
                    'Eligibility' => seo_llms_text($p['eligibility']),
                    'Apply' => $has('/apply') ? $url('/apply?program=' . rawurlencode((string) $p['slug'])) : '',
                ], fn ($v) => $v !== '');
                if ($st = seo_llms_structure_total((int) $p['id'])) {
                    $rows['Total programme fee'] = money($st['total']) . ' (' . $st['session'] . ' fee structure)';
                }
                foreach ($rows as $k => $v) {
                    $out[] = '- ' . $k . ': ' . $v;
                }
                if (($ov = seo_llms_text($p['overview'])) !== '') {
                    $out[] = '';
                    $out[] = $ov;
                }
                if ($hl = seo_llms_lines($p['highlights'])) {
                    $out[] = '';
                    $out[] = 'Highlights:';
                    foreach ($hl as $l) {
                        $out[] = '- ' . $l;
                    }
                }
                if ($cp = seo_llms_lines($p['career_prospects'])) {
                    $out[] = '';
                    $out[] = 'Career options:';
                    foreach ($cp as $l) {
                        $out[] = '- ' . $l;
                    }
                }
                $out[] = '';
            }
        }
        $faqs = [];
        try {
            $faqs = db_all("SELECT category, question, answer FROM faqs WHERE status = 'active' ORDER BY category, sort_order, id");
        } catch (Throwable $e) {
        }
        if ($faqs) {
            $out[] = '## Frequently asked questions';
            $out[] = '';
            foreach ($faqs as $f) {
                $out[] = '### ' . seo_llms_text($f['question']);
                $out[] = '';
                $out[] = seo_llms_text($f['answer']);
                $out[] = '';
            }
        }
    }

    $opt = [];
    foreach (['/blog' => 'News & blog', '/events' => 'Events', '/notices' => 'Notice board', '/gallery' => 'Photo gallery', '/verify-certificate' => null] as $r => $label) {
        $opt[] = $routeLink($r, $label);
    }
    $opt[] = '- [XML sitemap](' . absolute_url('sitemap.xml') . '): every indexable URL with its last-modified date';
    $opt[] = $full ? '- [llms.txt](' . absolute_url('llms.txt') . '): short version of this file'
        : '- [llms-full.txt](' . absolute_url('llms-full.txt') . '): programme details (overview, eligibility, fees, careers) and FAQ answers';
    $section('Optional', $opt);
    return rtrim(implode("\n", $out)) . "\n";
}
