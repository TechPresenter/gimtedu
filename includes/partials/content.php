<?php
/**
 * Content pages (unit "content_pages") — shared data access + render helpers for page.php (CMS page renderer with every
 * page-builder block of docs/CMS.md §2), 404.php, faq.php, search.php, student-portal.php and faculty-portal.php.
 *
 * Every query is read-only, uses prepared statements, is LIMITed and runs through cp_try(), so pages still render (with
 * meaningful empty states) when a table is empty or missing. Links are only rendered when their target exists right now
 * (cp_url_ok()), so pages never link to a route another unit has not built yet.
 *
 * Tailwind classes used dynamically below (kept literal for the scanner):
 *   sm:grid-cols-2 lg:grid-cols-3 lg:grid-cols-4 max-w-2xl max-w-3xl max-w-5xl aspect-video aspect-[4/3] aspect-square
 */
if (!defined('APP_ROOT')) { // opened directly (/includes/partials/content.php) — not a page
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/site_academic.php';
require_once APP_ROOT . '/app/services/cms.php';

/* ============================================================================ Basics */

/** A query-string parameter as a trimmed string (arrays such as ?q[]=x are ignored), cut to $max characters. */
function cp_query(string $key, int $max = 100): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) ? trim(mb_substr($v, 0, $max)) : '';
}

/** Run a DB read and fall back to $default (logged) when it fails. */
function cp_try(callable $fn, $default = null)
{
    try {
        return $fn();
    } catch (Throwable $e) {
        error_log('[content_pages] ' . $e->getMessage());
        return $default;
    }
}

/** Institute contact details from settings (same defaults as the rest of the website). */
function cp_contact(): array
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $wa = preg_replace('/\D+/', '', (string) setting('whatsapp_number', ''));
    return $c = [
        'name' => institute_name(),
        'short' => (string) setting('institute_short_name', 'GIMT'),
        'phone' => (string) setting('phone', '+91 9955446477'),
        'phone_alt' => (string) setting('phone_alt', ''),
        'email' => (string) setting('email', 'info@gimt.ac.in'),
        'admission_email' => (string) setting('admission_email', 'admissions@gimt.ac.in'),
        'support_email' => (string) setting('support_email', 'support@gimt.ac.in'),
        'hours' => (string) setting('office_hours', 'Mon - Sat, 9:00 AM - 6:00 PM'),
        'address' => (string) setting('address', 'Plot No. 123, Knowledge Park, Greater Noida, Uttar Pradesh 201310, India'),
        'whatsapp_url' => $wa !== '' ? 'https://wa.me/' . $wa . '?text=' . rawurlencode('Hello GIMT, I need some help.') : '',
    ];
}

/** tel: href for a display phone number. */
function cp_tel(string $phone): string
{
    return 'tel:' . preg_replace('/[^\d+]/', '', $phone);
}

/* ============================================================================ Link checks */

/** Published CMS pages: slug => row (id, title, slug, is_system, excerpt, parent_id, sort_order, updated_at). Cached. */
function cp_published_pages(): array
{
    static $rows = null;
    if ($rows === null) {
        $rows = [];
        foreach (cp_try(fn () => db_all("SELECT id, title, slug, subtitle, excerpt, is_system, parent_id, sort_order, updated_at, created_at, LEFT(content, 160) AS content_head FROM pages
                WHERE status = 'published' AND (published_at IS NULL OR published_at <= NOW()) ORDER BY sort_order, title LIMIT 1000"), []) as $r) {
            $rows[$r['slug']] = $r;
        }
    }
    return $rows;
}

/** Does an internal path (no query/hash) resolve to an existing page right now? Mirrors tools/router.php + .htaccess. */
function cp_route_ok(string $path): bool
{
    static $cache = [];
    $path = '/' . trim($path, '/');
    if (isset($cache[$path])) {
        return $cache[$path];
    }
    $root = APP_ROOT;
    $handlers = ['programs' => 'program', 'blog' => 'post', 'events' => 'event', 'notices' => 'notice', 'gallery' => 'gallery', 'page' => 'page'];
    $ok = false;
    if ($path === '/') {
        $ok = true;
    } elseif (preg_match('#^/(programs|blog|events|notices|gallery|page)/([a-z0-9-]+)$#', $path, $m)) {
        $ok = is_file($root . '/' . $handlers[$m[1]] . '.php');
        if ($ok && $m[1] === 'page') {
            $ok = isset(cp_published_pages()[$m[2]]);
        }
    } elseif (preg_match('#^/verify-certificate(/.*)?$#', $path)) {
        $ok = is_file($root . '/verify-certificate.php');
    } elseif (preg_match('#^/admin(/.*)?$#', $path)) {
        $ok = is_file($root . '/admin/index.php');
    } elseif (preg_match('#^/([a-z0-9-]+)$#', $path, $m)) {
        $ok = (is_file($root . '/' . $m[1] . '.php') && !in_array($m[1], ['index', 'site-kit', '404', '500', 'page', 'post', 'event', 'notice', 'program'], true))
            || isset(cp_published_pages()[$m[1]]);
    }
    return $cache[$path] = $ok;
}

/** Can this URL be linked? External / mailto: / tel: / #anchor are trusted; internal paths must resolve. */
function cp_url_ok(?string $url): bool
{
    $url = trim((string) $url);
    if ($url === '' || $url === '#') {
        return false;
    }
    if (preg_match('#^(https?:|mailto:|tel:|\#)#i', $url)) {
        return true;
    }
    $path = (string) parse_url($url, PHP_URL_PATH);
    $base = rtrim(base_path(), '/');
    if ($base !== '' && str_starts_with($path, $base . '/')) {
        $path = substr($path, strlen($base));
    }
    return cp_route_ok($path === '' ? '/' : $path);
}

/** CMS link {label, url} → ['label', 'url'] when both are set and the target exists, else null. */
function cp_link($link): ?array
{
    if (!is_array($link)) {
        return null;
    }
    $label = trim((string) ($link['label'] ?? ''));
    $url = trim((string) ($link['url'] ?? ''));
    return $label !== '' && cp_url_ok($url) ? ['label' => $label, 'url' => $url] : null;
}

/* ============================================================================ CMS pages */

/** Page row by slug (any status) with decoded blocks, or null. */
function cp_page_find(string $slug): ?array
{
    $row = cp_try(fn () => db_row('SELECT * FROM pages WHERE slug = ? LIMIT 1', [$slug]));
    return $row ?: null;
}

/** Is the page visible to visitors (published and not scheduled for later)? */
function cp_page_is_public(array $p): bool
{
    return ($p['status'] ?? '') === 'published' && (empty($p['published_at']) || strtotime((string) $p['published_at']) <= time());
}

/** Published ancestors, root first (max 5 levels). */
function cp_page_ancestors(array $page): array
{
    $byId = [];
    foreach (cp_published_pages() as $p) {
        $byId[(int) $p['id']] = $p;
    }
    $out = [];
    $parent = (int) ($page['parent_id'] ?? 0);
    for ($i = 0; $i < 5 && $parent && isset($byId[$parent]); $i++) {
        array_unshift($out, $byId[$parent]);
        $parent = (int) ($byId[$parent]['parent_id'] ?? 0);
    }
    return $out;
}

/** Slugs of the institute's policy / legal / disclosure pages (database/seed/08_policies.php + the core legal pages). */
function cp_policy_slugs(): array
{
    return ['privacy-policy', 'terms-and-conditions', 'disclaimer', 'cookie-policy', 'refund-and-cancellation-policy', 'refund-policy',
        'admission-policy', 'scholarship-policy', 'fee-policy', 'examination-policy', 'anti-ragging-policy', 'grievance-redressal-policy',
        'internal-complaints-committee', 'equal-opportunity-policy', 'accessibility', 'code-of-conduct', 'mandatory-disclosure'];
}

/** Old / short URLs that should land on a policy page when no page with that slug exists. */
function cp_page_aliases(): array
{
    return ['refund-policy' => 'refund-and-cancellation-policy', 'refund' => 'refund-and-cancellation-policy', 'cancellation-policy' => 'refund-and-cancellation-policy',
        'privacy' => 'privacy-policy', 'terms' => 'terms-and-conditions', 'terms-of-use' => 'terms-and-conditions', 'cookies' => 'cookie-policy',
        'anti-ragging' => 'anti-ragging-policy', 'grievance' => 'grievance-redressal-policy', 'grievances' => 'grievance-redressal-policy',
        'posh' => 'internal-complaints-committee', 'icc' => 'internal-complaints-committee', 'disclosure' => 'mandatory-disclosure',
        'mandatory-disclosures' => 'mandatory-disclosure', 'accessibility-statement' => 'accessibility', 'equal-opportunity' => 'equal-opportunity-policy',
        'policy' => 'policies', 'legal' => 'policies'];
}

/** The "Policies & Disclosures" hub page (slug policies) when published. */
function cp_policy_hub(): ?array
{
    return cp_published_pages()['policies'] ?? null;
}

/** Is this a policy / legal page (known slug or a child of the policies hub)? */
function cp_is_policy(array $page): bool
{
    $hub = cp_policy_hub();
    return in_array((string) ($page['slug'] ?? ''), cp_policy_slugs(), true) || ($hub && (int) ($page['parent_id'] ?? 0) === (int) $hub['id']);
}

/** Published policy pages in display order: [{title, url, excerpt, slug, icon}]. */
function cp_policy_pages(): array
{
    static $out = null;
    if ($out !== null) {
        return $out;
    }
    $order = array_flip(cp_policy_slugs());
    $hub = cp_policy_hub();
    $rows = array_filter(cp_published_pages(), fn ($p) => isset($order[$p['slug']]) || ($hub && (int) $p['parent_id'] === (int) $hub['id']));
    uasort($rows, fn ($a, $b) => [$order[$a['slug']] ?? 99, (int) $a['sort_order'], $a['title']] <=> [$order[$b['slug']] ?? 99, (int) $b['sort_order'], $b['title']]);
    // Both refund pages exist? Keep the complete one in the list (the short one stays reachable from the footer).
    if (isset($rows['refund-policy'], $rows['refund-and-cancellation-policy'])) {
        unset($rows['refund-policy']);
    }
    $out = [];
    foreach ($rows as $p) {
        $out[] = ['title' => $p['title'], 'url' => cms_page_url($p), 'excerpt' => (string) ($p['excerpt'] ?? ''), 'slug' => $p['slug'], 'icon' => cp_policy_icon($p['slug']),
            'updated' => ($ts = cp_stated_date((string) ($p['content_head'] ?? ''))) ? date('Y-m-d', $ts) : ($p['updated_at'] ?: $p['created_at'])];
    }
    return $out;
}

/**
 * Editor-stated date at the top of page content ("<p><em>Last updated: 1 July 2026</em></p>") → [timestamp, matched
 * HTML] or null. page.php shows it instead of the database timestamp and removes the duplicate line.
 */
function cp_stated_date_match(string $html): ?array
{
    if (preg_match('#^\s*<p>\s*(?:<(?:em|i|strong|b)>\s*)?(?:last\s+updated|updated\s+on|effective\s+from)\s*:?\s*([^<]{4,40}?)\s*(?:</(?:em|i|strong|b)>\s*)?</p>#i', $html, $m)
        && ($ts = strtotime(trim($m[1], " .\t")))) {
        return [$ts, $m[0]];
    }
    return null;
}

function cp_stated_date(string $html): ?int
{
    return cp_stated_date_match($html)[0] ?? null;
}

/** Lucide icon for a policy page. */
function cp_policy_icon(string $slug): string
{
    return [
        'privacy-policy' => 'lock', 'terms-and-conditions' => 'scroll-text', 'disclaimer' => 'info', 'cookie-policy' => 'layers',
        'refund-and-cancellation-policy' => 'receipt', 'refund-policy' => 'receipt', 'admission-policy' => 'clipboard-check',
        'scholarship-policy' => 'award', 'fee-policy' => 'wallet', 'examination-policy' => 'file-check', 'anti-ragging-policy' => 'shield-alert',
        'grievance-redressal-policy' => 'messages-square', 'internal-complaints-committee' => 'shield-check', 'equal-opportunity-policy' => 'users',
        'accessibility' => 'eye', 'code-of-conduct' => 'badge-check', 'mandatory-disclosure' => 'landmark',
    ][$slug] ?? 'file-text';
}

/** Related pages: parent, siblings (same parent) and children — published only, max $limit. [{title, url, excerpt}] */
function cp_related_pages(array $page, int $limit = 6): array
{
    $out = [];
    $id = (int) $page['id'];
    $parent = (int) ($page['parent_id'] ?? 0);
    $all = cp_published_pages();
    foreach ($all as $p) {
        if ((int) $p['id'] === $id || cp_is_policy($p) || $p['slug'] === 'policies') {
            continue;
        }
        $isChild = (int) $p['parent_id'] === $id;
        $isSibling = $parent && (int) $p['parent_id'] === $parent;
        $isParent = $parent && (int) $p['id'] === $parent;
        if (($isChild || $isSibling || $isParent) && cp_url_ok(cms_page_url($p))) {
            $out[] = ['title' => $p['title'], 'url' => cms_page_url($p), 'excerpt' => (string) ($p['excerpt'] ?: $p['subtitle']), 'rank' => $isParent ? 0 : ($isChild ? 1 : 2), 'sort' => (int) $p['sort_order']];
        }
    }
    usort($out, fn ($a, $b) => [$a['rank'], $a['sort'], $a['title']] <=> [$b['rank'], $b['sort'], $b['title']]);
    return array_slice($out, 0, $limit);
}

/**
 * Prepare sanitised page HTML for display: ids on h2/h3 (for the table of contents and deep links), tables wrapped
 * in a horizontal scroller, "[To be confirmed by GIMT: …]" notes highlighted for editors and visitors alike.
 * @return array{0: string, 1: array<int, array{id: string, text: string}>} [html, toc (h2 only)]
 */
function cp_prepare_html(string $html): array
{
    $html = trim($html);
    if ($html === '') {
        return ['', []];
    }
    $toc = [];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="cp-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $doc->getElementById('cp-root');
    if (!$root) {
        return [$html, []];
    }
    $used = [];
    $xp = new DOMXPath($doc);
    foreach ($xp->query('.//h2|.//h3', $root) as $h) {
        /** @var DOMElement $h */
        $text = trim(preg_replace('/\s+/u', ' ', $h->textContent));
        if ($text === '') {
            continue;
        }
        $base = slugify(preg_replace('/^\d+[.)]\s*/', '', $text));
        $base = substr($base, 0, 60) ?: 'section';
        $slug = $base;
        for ($n = 2; isset($used[$slug]); $n++) {
            $slug = $base . '-' . $n;
        }
        $used[$slug] = true;
        $h->setAttribute('id', $slug);
        $h->setAttribute('class', trim($h->getAttribute('class') . ' scroll-mt-28'));
        if (strtolower($h->tagName) === 'h2') {
            $toc[] = ['id' => $slug, 'text' => $text];
        }
    }
    foreach (iterator_to_array($xp->query('.//table', $root)) as $table) {
        $wrap = $doc->createElement('div');
        $wrap->setAttribute('class', 'cp-table-wrap');
        $wrap->setAttribute('role', 'region');
        $wrap->setAttribute('tabindex', '0');
        $wrap->setAttribute('aria-label', 'Table (scrolls sideways on small screens)');
        $table->parentNode->replaceChild($wrap, $table);
        $wrap->appendChild($table);
    }
    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    $out = (string) preg_replace('/\[(To be confirmed by GIMT:[^\]<]{1,500})\]/u', '<mark class="placeholder-note">[$1]</mark>', $out);
    return [$out, $toc];
}

/** Estimated reading time (minutes) for HTML + builder text. */
function cp_reading_minutes(string $text): int
{
    $words = str_word_count(strip_tags(str_replace('><', '> <', $text)));
    return max(1, (int) round($words / 200));
}

/** Plain-text strings of a block's data (titles, text, html …) — for search, descriptions and reading time. */
function cp_block_text($data): string
{
    $out = [];
    $walk = function ($v, $k = '') use (&$walk, &$out) {
        if (is_array($v)) {
            foreach ($v as $kk => $vv) {
                $walk($vv, is_string($kk) ? $kk : $k);
            }
        } elseif (is_string($v) && in_array($k, ['title', 'heading', 'subheading', 'eyebrow', 'highlight', 'text', 'html', 'caption', 'q', 'a', 'quote', 'subtitle', 'name', 'role', 'label'], true)) {
            $out[] = trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>', '</h2>', '</h3>'], ' ', $v)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
    };
    $walk($data);
    return trim(preg_replace('/\s+/u', ' ', implode(' ', array_filter($out))));
}

/** Meta description for a page: excerpt → subtitle → first words of the content / blocks. */
function cp_page_description(array $page): string
{
    foreach ([$page['excerpt'] ?? '', $page['subtitle'] ?? ''] as $v) {
        if (trim((string) $v) !== '' && mb_strlen(trim((string) $v)) >= 40) {
            return str_limit((string) $v, 160);
        }
    }
    $text = trim(html_entity_decode(strip_tags(str_replace('</p>', ' ', (string) ($page['content'] ?? ''))), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($text === '') {
        $text = cp_block_text(array_map(fn ($b) => $b['data'], cms_page_blocks($page)));
    }
    $text = trim(preg_replace('/^Last updated:[^.]*?\d{4}\s*/i', '', $text));
    return $text !== '' ? str_limit($text, 160) : (string) ($page['title'] ?? '') . ' — ' . institute_name() . '.';
}

/* ============================================================================ Page chrome */

/**
 * Classes for CMS rich text: the shared .prose-site typography + placeholder notes ("[To be confirmed by GIMT: …]",
 * highlighted so the institute can spot and complete them), scrollable tables and section spacing.
 */
function cp_prose_class(): string
{
    return 'prose-site cp-prose max-w-none [&_h2:first-child]:mt-0 [&_h2]:scroll-mt-28 [&_h2]:border-t [&_h2]:border-warm-2 [&_h2]:pt-8 [&_h2:first-child]:border-0 [&_h2:first-child]:pt-0 [&_h3]:scroll-mt-28 [&_li]:pl-1 [&_strong]:text-brand-900'
        . ' [&_.placeholder-note]:rounded-md [&_.placeholder-note]:bg-amber-100 [&_.placeholder-note]:px-1.5 [&_.placeholder-note]:py-0.5 [&_.placeholder-note]:text-[0.92em] [&_.placeholder-note]:font-semibold [&_.placeholder-note]:text-amber-950 [&_.placeholder-note]:ring-1 [&_.placeholder-note]:ring-amber-300 [&_.placeholder-note]:[box-decoration-break:clone] [&_.placeholder-note]:[-webkit-box-decoration-break:clone]'
        . ' [&_.cp-table-wrap]:my-6 [&_.cp-table-wrap]:overflow-x-auto [&_.cp-table-wrap]:rounded-2xl [&_.cp-table-wrap]:border [&_.cp-table-wrap]:border-warm-2 [&_.cp-table-wrap_table]:my-0 [&_.cp-table-wrap_table]:min-w-[520px] [&_.cp-table-wrap_th]:border-warm-2 [&_.cp-table-wrap_th]:bg-warm [&_.cp-table-wrap_td]:border-warm-2 [&_.cp-table-wrap_td]:align-top';
}

/** Table-of-contents list (h2 anchors). */
function cp_toc_list(array $toc, string $class = ''): string
{
    $html = '<ol class="space-y-0.5 text-sm' . ($class !== '' ? ' ' . e($class) : '') . '">';
    foreach ($toc as $t) {
        $html .= '<li><a href="#' . e($t['id']) . '" class="block rounded-xl border-l-2 border-transparent px-3 py-2 leading-snug text-slate-600 transition hover:bg-warm hover:text-brand-900 aria-[current=true]:border-accent-600 aria-[current=true]:bg-accent-50 aria-[current=true]:font-semibold aria-[current=true]:text-brand-900">'
            . e(preg_replace('/^\d+[.)]\s*/', '', $t['text'])) . '</a></li>';
    }
    return $html . '</ol>';
}

/** "Need help?" card with the office contacts. */
function cp_help_card(array $c, bool $policy = false): string
{
    $html = '<div class="rounded-[22px] bg-brand-900 p-6 text-white/80 shadow-card">'
        . '<p class="flex items-center gap-2 font-display text-lg font-extrabold text-white">' . icon('messages-square', 'h-5 w-5 text-accent-300') . ($policy ? 'Questions about a policy?' : 'Need help?') . '</p>'
        . '<p class="mt-2 text-sm">' . ($policy ? 'Write to us or call the office during working hours — we will be glad to help.' : 'Our team is happy to help — ' . e($c['hours']) . '.') . '</p>'
        . '<ul class="mt-4 space-y-2.5 text-sm">'
        . '<li><a href="mailto:' . e($c['email']) . '" class="inline-flex items-center gap-2 font-semibold text-white underline-offset-2 hover:underline">' . icon('mail', 'h-4 w-4 text-accent-300') . e($c['email']) . '</a></li>'
        . '<li><a href="' . e(cp_tel($c['phone'])) . '" class="inline-flex items-center gap-2 font-semibold text-white underline-offset-2 hover:underline">' . icon('phone', 'h-4 w-4 text-accent-300') . e($c['phone']) . '</a></li>'
        . '<li class="inline-flex items-center gap-2">' . icon('clock', 'h-4 w-4 text-accent-300') . e($c['hours']) . '</li></ul></div>';
    return $html;
}

/** Grid of policy cards (Policies & Disclosures hub). */
function cp_policy_grid(array $policies): string
{
    if (!$policies) {
        return '<p class="mt-10 rounded-2xl border border-dashed border-warm-2 p-8 text-center text-slate-600">Policies will be published here shortly.</p>';
    }
    $html = '<h2 class="sr-only">All policies</h2><ul class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3" data-stagger="50">';
    foreach ($policies as $p) {
        $html .= '<li class="group relative flex h-full flex-col rounded-[22px] border border-warm-2 bg-white p-6 shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-card">'
            . '<span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-accent-50 text-accent-600 transition group-hover:bg-accent-600 group-hover:text-white">' . icon($p['icon'], 'h-5 w-5') . '</span>'
            . '<h3 class="mt-4 font-display text-lg font-extrabold text-brand-900"><a href="' . e(site_url(ltrim($p['url'], '/'))) . '" class="stretched-link">' . e($p['title']) . '</a></h3>'
            . ($p['excerpt'] !== '' ? '<p class="mt-2 line-clamp-3 text-sm leading-relaxed text-slate-600">' . e($p['excerpt']) . '</p>' : '')
            . '<span class="mt-auto flex items-center justify-between pt-5 text-xs text-slate-500"><span>Updated ' . e(format_date($p['updated'])) . '</span>' . icon('arrow-right', 'h-4 w-4 text-accent-600 transition group-hover:translate-x-1') . '</span></li>';
    }
    return $html . '</ul>';
}

/* ============================================================================ Blocks */

/** Image size for a local image path (bundled or uploaded) → [w, h] or null. */
function cp_image_size(?string $path): ?array
{
    static $cache = [];
    $path = (string) $path;
    if ($path === '' || preg_match('#^https?://#', $path)) {
        return null;
    }
    if (!array_key_exists($path, $cache)) {
        $file = str_starts_with($path, 'assets/') ? APP_ROOT . '/' . $path : UPLOAD_PATH . '/' . ltrim($path, '/');
        $size = !str_contains($path, '..') && is_file($file) ? @getimagesize($file) : false;
        $cache[$path] = $size ? [(int) $size[0], (int) $size[1]] : null;
    }
    return $cache[$path];
}

/** URL of a bundled (assets/…) or uploaded image when the file exists (or an absolute URL), else null. */
function cp_local_image_url(?string $path): ?string
{
    $path = trim((string) $path);
    if ($path === '' || str_contains($path, '..')) {
        return null;
    }
    if (preg_match('#^https?://#', $path)) {
        return $path;
    }
    $file = str_starts_with($path, 'assets/') ? APP_ROOT . '/' . $path : UPLOAD_PATH . '/' . ltrim($path, '/');
    return is_file($file) ? site_image($path) : null;
}

/** width/height attributes for a local image (prevents layout shift). */
function cp_img_dims(?string $path): string
{
    $s = cp_image_size($path);
    return $s ? ' width="' . $s[0] . '" height="' . $s[1] . '"' : '';
}

/** Open a block section from its settings (anchor, background, padding). */
function cp_section_open(array $settings, string $label = '', string $class = ''): string
{
    $bg = ['white' => 'white', 'light' => 'light', 'cream' => 'cream', 'sand' => 'sand', 'dark' => 'navy'][$settings['background'] ?? 'white'] ?? 'white';
    $pad = in_array($settings['padding'] ?? 'normal', ['normal', 'tight', 'none'], true) ? ($settings['padding'] ?? 'normal') : 'normal';
    $anchor = preg_match('/^[a-z0-9-]{1,60}$/', (string) ($settings['anchor'] ?? '')) ? $settings['anchor'] : '';
    return section_open(['id' => $anchor ?: null, 'bg' => $bg, 'padding' => $pad, 'label' => $label ?: null, 'class' => trim('scroll-mt-24 ' . $class)]);
}

/** Eyebrow + h2 + subheading of a block (nothing when the block has no heading). */
function cp_block_heading(array $d, string $align = 'left', string $action = ''): string
{
    $h = trim((string) ($d['heading'] ?? ''));
    if ($h === '') {
        return '';
    }
    return section_heading($h, trim((string) ($d['subheading'] ?? '')), trim((string) ($d['eyebrow'] ?? '')), $align, $action);
}

/** Button HTML for a CMS link (only when the target exists). */
function cp_button($link, string $variant, array $o = []): string
{
    $l = cp_link($link);
    return $l ? button_link($l['label'], $l['url'], $variant, $o) : '';
}

/**
 * CTA-system variant for a secondary CMS button (Design v3): brochure / download → outline "brochure", callback / call →
 * soft "callback", anything else (Explore Courses, Contact …) → navy "explore".
 */
function cp_secondary_variant($link): string
{
    $label = strtolower((string) (is_array($link) ? ($link['label'] ?? '') : ''));
    if (preg_match('/brochure|download|prospectus/', $label)) {
        return 'brochure';
    }
    return preg_match('/callback|call back|call us/', $label) ? 'callback' : 'explore';
}

/**
 * Render the first hero block of a page as the page hero (h1 + breadcrumbs). Echoes.
 * @param array $crumbs page_hero() breadcrumbs
 */
function cp_hero_from_block(array $d, array $crumbs, string $fallbackTitle): void
{
    $actions = cp_button($d['primary_cta'] ?? null, 'apply', ['magnetic' => true]) . cp_button($d['secondary_cta'] ?? null, cp_secondary_variant($d['secondary_cta'] ?? null));
    $chips = [];
    foreach ((array) ($d['chips'] ?? []) as $c) {
        if (is_array($c) && trim((string) ($c['label'] ?? '')) !== '') {
            $chips[] = ['icon' => (string) ($c['icon'] ?? '') ?: 'check', 'label' => (string) $c['label']];
        }
    }
    page_hero(
        trim((string) ($d['title'] ?? '')) ?: $fallbackTitle,
        trim((string) ($d['highlight'] ?? '')),
        $crumbs,
        trim((string) ($d['image'] ?? '')),
        trim((string) ($d['text'] ?? '')),
        $chips,
        ['eyebrow' => trim((string) ($d['eyebrow'] ?? '')) ?: null, 'actions' => $actions, 'image_alt' => (string) ($d['image_alt'] ?? ''),
         'size' => ($d['size'] ?? 'md') === 'lg' ? 'lg' : 'md', 'align' => ($d['align'] ?? 'left') === 'center' ? 'center' : 'left']
    );
}

/** Live/typed statistic items for stat_counter() (items without a usable number are dropped). */
function cp_stat_items(array $items): array
{
    $out = [];
    foreach ($items as $s) {
        if (!is_array($s) || trim((string) ($s['label'] ?? '')) === '') {
            continue;
        }
        $v = cms_stat_value($s['source'] ?? null, $s['value'] ?? null);
        if ($v === null || $v === '' || !is_numeric(str_replace(',', '', (string) $v))) {
            continue;
        }
        $v = (float) str_replace(',', '', (string) $v);
        $out[] = ['value' => $v, 'decimals' => fmod($v, 1.0) ? 1 : 0, 'prefix' => (string) ($s['prefix'] ?? ''), 'suffix' => (string) ($s['suffix'] ?? ''),
            'label' => (string) $s['label'], 'icon' => (string) ($s['icon'] ?? '') ?: null];
    }
    return $out;
}

/** Active FAQs: [{id, q, a, category, category_key}] optionally for one category. */
function cp_faq_rows(?string $category = null, int $limit = 200): array
{
    $cats = cms_faq_categories();
    $sql = "SELECT id, category, question, answer FROM faqs WHERE status = 'active'" . ($category ? ' AND category = ?' : '')
        . ' ORDER BY FIELD(category, ' . implode(',', array_fill(0, count($cats), '?')) . '), sort_order, id LIMIT ' . max(1, min(500, $limit));
    $args = array_merge($category ? [$category] : [], array_keys($cats));
    $rows = cp_try(fn () => db_all($sql, $args), []);
    return array_map(fn ($r) => ['id' => (int) $r['id'], 'q' => (string) $r['question'],
        'a' => trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", (string) $r['answer'])), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        'category_key' => (string) $r['category'], 'category' => $cats[$r['category']] ?? label_from_key((string) $r['category'])], $rows);
}

/** Lucide icon for an FAQ category. */
function cp_faq_icon(string $category): string
{
    return ['general' => 'info', 'admissions' => 'clipboard-check', 'academics' => 'book-open', 'fees' => 'wallet', 'hostel' => 'building-2',
        'placement' => 'briefcase-business', 'campus' => 'landmark'][$category] ?? 'circle-help';
}

/** FAQPage JSON-LD for [{q, a}]. */
function cp_faq_schema(array $faqs): array
{
    return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_values(array_map(fn ($f) => [
        '@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
    ], $faqs))];
}

/**
 * Render one builder block (normalised {id, type, settings, data}) as HTML. $ctx collects page-level data:
 *   faq (array, FAQ items for the FAQPage JSON-LD), first_hero_used (bool), is_landing (bool).
 */
function cp_render_block(array $b, array &$ctx): string
{
    $d = is_array($b['data'] ?? null) ? $b['data'] : [];
    $s = is_array($b['settings'] ?? null) ? $b['settings'] : [];
    try {
        switch ($b['type']) {
            case 'hero': return cp_block_hero_band($d, $s);
            case 'text': return cp_block_text_html($d, $s);
            case 'image': return cp_block_image($d, $s);
            case 'video': return cp_block_video($d, $s);
            case 'cards': return cp_block_cards($d, $s);
            case 'statistics': return cp_block_statistics($d, $s);
            case 'testimonials': return cp_block_testimonials($d, $s);
            case 'faq': return cp_block_faq($d, $s, $ctx);
            case 'cta': return cp_block_cta($d, $s);
            case 'gallery': return cp_block_gallery($d, $s);
            case 'course_listing': return cp_block_courses($d, $s);
            case 'faculty_listing': return cp_block_faculty($d, $s);
            case 'placement_section': return cp_block_placement($d, $s);
        }
    } catch (Throwable $e) {
        error_log('[content_pages] block ' . ($b['type'] ?? '?') . ' failed: ' . $e->getMessage());
    }
    return '';
}

/** Hero block that is not the page hero: split band with an h2. */
function cp_block_hero_band(array $d, array $s): string
{
    $title = trim((string) ($d['title'] ?? ''));
    if ($title === '') {
        return '';
    }
    $img = trim((string) ($d['image'] ?? ''));
    $center = ($d['align'] ?? 'left') === 'center' || $img === '';
    $actions = cp_button($d['primary_cta'] ?? null, 'apply') . cp_button($d['secondary_cta'] ?? null, cp_secondary_variant($d['secondary_cta'] ?? null));
    $html = cp_section_open($s, $title, 'overflow-hidden');
    $html .= '<div class="grid items-center gap-10' . ($center ? '' : ' lg:grid-cols-12') . '">';
    $html .= '<div class="' . ($center ? 'mx-auto max-w-3xl text-center' : 'lg:col-span-7') . '"' . reveal_attr() . '>';
    if (trim((string) ($d['eyebrow'] ?? '')) !== '') {
        $html .= '<p class="eyebrow mb-3">' . e($d['eyebrow']) . '</p>';
    }
    $html .= '<h2 class="section-title">' . e($title) . (trim((string) ($d['highlight'] ?? '')) !== '' ? ' <span class="block text-accent-600">' . e($d['highlight']) . '</span>' : '') . '</h2>';
    if (trim((string) ($d['text'] ?? '')) !== '') {
        $html .= '<p class="section-lead' . ($center ? ' mx-auto' : '') . '">' . e($d['text']) . '</p>';
    }
    $chips = '';
    foreach ((array) ($d['chips'] ?? []) as $c) {
        if (is_array($c) && trim((string) ($c['label'] ?? '')) !== '') {
            $chips .= '<li class="chip !cursor-default">' . icon((string) ($c['icon'] ?? '') ?: 'check', 'h-4 w-4 text-accent-600') . e($c['label']) . '</li>';
        }
    }
    if ($chips !== '') {
        $html .= '<ul class="mt-6 flex flex-wrap gap-2' . ($center ? ' justify-center' : '') . '">' . $chips . '</ul>';
    }
    if ($actions !== '') {
        $html .= '<div class="mt-8 flex flex-wrap gap-3' . ($center ? ' justify-center' : '') . '">' . $actions . '</div>';
    }
    $html .= '</div>';
    if (!$center) {
        $html .= '<div class="lg:col-span-5"' . reveal_attr('zoom-in', 120) . '><div class="img-zoom overflow-hidden rounded-[28px] shadow-pop"><img src="' . e(site_image($img)) . '" alt="' . e($d['image_alt'] ?? '') . '" loading="lazy" decoding="async"' . cp_img_dims($img) . ' class="aspect-[4/3] h-full w-full object-cover"></div></div>';
    }
    return $html . '</div>' . section_close();
}

/** Rich text block. */
function cp_block_text_html(array $d, array $s): string
{
    [$html] = cp_prepare_html(sanitize_html((string) ($d['html'] ?? '')));
    if ($html === '') {
        return '';
    }
    $width = ['narrow' => 'max-w-2xl', 'normal' => 'max-w-3xl', 'wide' => 'max-w-5xl'][$d['width'] ?? 'normal'] ?? 'max-w-3xl';
    $center = ($d['align'] ?? 'left') === 'center';
    $heading = trim((string) ($d['heading'] ?? ''));
    $dark = ($s['background'] ?? '') === 'dark';
    return cp_section_open($s, $heading)
        . '<div class="mx-auto ' . $width . ($center ? ' text-center' : '') . '"' . reveal_attr() . '>'
        . ($heading !== '' ? '<h2 class="section-title !text-[clamp(1.6rem,1.1rem+1.8vw,2.3rem)]">' . e($heading) . '</h2>' : '')
        . '<div class="prose-site cp-prose' . ($dark ? ' !text-white/80 [&_a]:!text-accent-300 [&_h2]:!text-white [&_h3]:!text-white' : '') . ($heading !== '' ? ' mt-4' : '') . '">' . $html . '</div></div>'
        . section_close();
}

/** Single image block. */
function cp_block_image(array $d, array $s): string
{
    $img = trim((string) ($d['image'] ?? ''));
    if ($img === '') {
        return '';
    }
    $width = ['normal' => 'max-w-3xl', 'wide' => 'max-w-5xl', 'full' => 'max-w-none'][$d['width'] ?? 'normal'] ?? 'max-w-3xl';
    $tag = '<img src="' . e(site_image($img)) . '" alt="' . e($d['alt'] ?? '') . '" loading="lazy" decoding="async"' . cp_img_dims($img) . ' class="h-auto w-full rounded-[24px] object-cover">';
    if (cp_url_ok($d['link'] ?? '')) {
        $tag = '<a href="' . e(cms_link($d['link'])) . '" class="img-zoom block overflow-hidden rounded-[24px]">' . $tag . '</a>';
    }
    $caption = trim((string) ($d['caption'] ?? ''));
    return cp_section_open($s, $caption ?: (string) ($d['alt'] ?? ''))
        . '<figure class="mx-auto ' . $width . '"' . reveal_attr('zoom-in') . '>' . $tag
        . ($caption !== '' ? '<figcaption class="mt-3 text-center text-sm text-slate-500">' . e($caption) . '</figcaption>' : '') . '</figure>'
        . section_close();
}

/** Video block (VideoLightbox island). */
function cp_block_video(array $d, array $s): string
{
    $url = trim((string) ($d['url'] ?? ''));
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return '';
    }
    $title = trim((string) ($d['title'] ?? '')) ?: 'Watch the video';
    $aspect = ['16:9' => 'aspect-video', '4:3' => 'aspect-[4/3]', '1:1' => 'aspect-square'][$d['aspect'] ?? '16:9'] ?? 'aspect-video';
    $poster = trim((string) ($d['poster'] ?? ''));
    $caption = trim((string) ($d['caption'] ?? ''));
    return cp_section_open($s, $title)
        . '<div class="mx-auto max-w-4xl"' . reveal_attr('zoom-in') . '>'
        . video_lightbox(['url' => $url, 'title' => $title, 'label' => $title, 'poster' => $poster !== '' ? site_image($poster) : null, 'variant' => 'thumb', 'aspect' => $aspect, 'className' => 'rounded-[24px] bg-brand-900'])
        . ($caption !== '' ? '<p class="mt-3 text-center text-sm text-slate-500">' . e($caption) . '</p>' : '') . '</div>'
        . section_close();
}

/** Cards block: icon | image | person | pastel. */
function cp_block_cards(array $d, array $s): string
{
    $items = array_values(array_filter((array) ($d['items'] ?? []), fn ($i) => is_array($i) && trim((string) ($i['title'] ?? '')) !== ''));
    if (!$items) {
        return '';
    }
    $style = in_array($d['style'] ?? 'icon', ['icon', 'image', 'person', 'pastel'], true) ? ($d['style'] ?? 'icon') : 'icon';
    $cols = ['2' => 'sm:grid-cols-2', '3' => 'sm:grid-cols-2 lg:grid-cols-3', '4' => 'sm:grid-cols-2 lg:grid-cols-4'][(string) ($d['columns'] ?? '3')] ?? 'sm:grid-cols-2 lg:grid-cols-3';
    $tileTint = ['lavender' => 'lavender', 'sky' => 'sky', 'aqua' => 'aqua', 'cream' => 'sun', 'sand' => 'sand', 'orange' => 'orange', 'ink' => 'navy'];
    $pastelTint = ['lavender' => 'lavender', 'sky' => 'sky', 'aqua' => 'aqua', 'cream' => 'cream', 'sand' => 'sand', 'orange' => 'blush', 'ink' => 'lavender'];
    $cycle = ['lavender', 'sky', 'aqua', 'sand', 'blush', 'pistachio'];
    $html = cp_section_open($s, (string) ($d['heading'] ?? '')) . cp_block_heading($d);
    $html .= '<div class="grid gap-5 ' . $cols . '" data-stagger="80">';
    foreach ($items as $i => $it) {
        $title = (string) $it['title'];
        $text = trim((string) ($it['text'] ?? ''));
        $url = cp_url_ok($it['url'] ?? '') ? (string) $it['url'] : '';
        $linkLabel = trim((string) ($it['link_label'] ?? '')) ?: 'Learn more';
        $tint = (string) ($it['tint'] ?? '');
        if ($style === 'pastel') {
            $html .= pastel_card($title, $text, ['color' => $pastelTint[$tint] ?? $cycle[$i % 6], 'button' => $url !== '' ? ['label' => $linkLabel, 'url' => $url] : null]);
        } elseif ($style === 'icon') {
            $html .= feature_tile((string) ($it['icon'] ?? '') ?: 'sparkles', $title, $text, ['tint' => $tileTint[$tint] ?? ($i % 2 ? 'orange' : 'navy'), 'url' => $url ?: null]);
        } elseif ($style === 'person') {
            $img = trim((string) ($it['image'] ?? ''));
            $html .= '<article class="group flex h-full flex-col overflow-hidden rounded-[22px] border border-warm-2 bg-white shadow-card transition duration-300 hover:-translate-y-1 hover:shadow-soft">'
                . ($img !== '' ? '<div class="img-zoom aspect-[4/3] overflow-hidden bg-warm"><img src="' . e(site_image($img)) . '" alt="' . e($title . (!empty($it['subtitle']) ? ', ' . $it['subtitle'] : '')) . '" loading="lazy" decoding="async"' . cp_img_dims($img) . ' class="h-full w-full object-cover object-top"></div>'
                    : '<div class="flex items-center gap-4 px-6 pt-6">' . sa_avatar(null, $title, $i, 'h-16 w-16 text-lg') . '</div>')
                . '<div class="flex flex-1 flex-col p-6"><h3 class="font-display text-lg font-extrabold text-brand-900">' . e($title) . '</h3>'
                . (trim((string) ($it['subtitle'] ?? '')) !== '' ? '<p class="mt-0.5 text-sm font-semibold text-accent-700">' . e($it['subtitle']) . '</p>' : '')
                . ($text !== '' ? '<p class="mt-3 text-sm leading-relaxed text-slate-600">' . e($text) . '</p>' : '')
                . ($url !== '' ? '<a href="' . e(cms_link($url)) . '" class="link-arrow mt-auto pt-4 text-sm">' . e($linkLabel) . icon('arrow-right', 'h-4 w-4') . '</a>' : '') . '</div></article>';
        } else { // image
            $img = trim((string) ($it['image'] ?? ''));
            $html .= '<article class="group relative flex h-full flex-col overflow-hidden rounded-[22px] border border-warm-2 bg-white shadow-card transition duration-300 hover:-translate-y-1 hover:shadow-soft">'
                . ($img !== '' ? '<div class="img-zoom aspect-[16/10] overflow-hidden bg-warm"><img src="' . e(site_image($img)) . '" alt="" loading="lazy" decoding="async"' . cp_img_dims($img) . ' class="h-full w-full object-cover"></div>' : '')
                . '<div class="flex flex-1 flex-col p-6">'
                . (trim((string) ($it['subtitle'] ?? '')) !== '' ? '<p class="text-xs font-bold uppercase tracking-wider text-accent-700">' . e($it['subtitle']) . '</p>' : '')
                . '<h3 class="mt-1 font-display text-lg font-extrabold text-brand-900">' . ($url !== '' ? '<a href="' . e(cms_link($url)) . '" class="stretched-link">' . e($title) . '</a>' : e($title)) . '</h3>'
                . ($text !== '' ? '<p class="mt-2 text-sm leading-relaxed text-slate-600">' . e($text) . '</p>' : '')
                . ($url !== '' ? '<span class="link-arrow mt-auto pt-4 text-sm" aria-hidden="true">' . e($linkLabel) . icon('arrow-right', 'h-4 w-4') . '</span>' : '') . '</div></article>';
        }
    }
    return $html . '</div>' . section_close();
}

/** Statistics block (live numbers when a source is set). */
function cp_block_statistics(array $d, array $s): string
{
    $items = cp_stat_items((array) ($d['items'] ?? []));
    if (!$items) {
        return '';
    }
    $variant = $d['variant'] ?? 'cards';
    if ($variant === 'band') {
        $s['background'] = 'dark';
    }
    $dark = ($s['background'] ?? '') === 'dark';
    $n = count($items);
    $grid = $n <= 2 ? 'grid-cols-2' : ($n === 3 ? 'grid-cols-1 sm:grid-cols-3' : 'grid-cols-2 lg:grid-cols-' . min(4, $n));
    return cp_section_open($s, (string) ($d['heading'] ?? 'Statistics'))
        . cp_block_heading($d, $variant === 'inline' ? 'left' : 'center')
        . stat_counter($items, ['variant' => $variant === 'band' ? 'divided' : ($variant === 'inline' ? 'inline' : 'cards'), 'theme' => $dark ? 'dark' : 'light', 'gridClass' => $grid])
        . section_close();
}

/** Testimonials block (CMS testimonials or manual quotes). */
function cp_block_testimonials(array $d, array $s): string
{
    $items = [];
    if (($d['source'] ?? 'auto') === 'manual') {
        foreach ((array) ($d['items'] ?? []) as $t) {
            if (is_array($t) && trim((string) ($t['name'] ?? '')) !== '' && trim((string) ($t['quote'] ?? '')) !== '') {
                $items[] = array_filter(['name' => (string) $t['name'], 'quote' => (string) $t['quote'], 'role' => (string) ($t['role'] ?? ''), 'meta' => (string) ($t['role'] ?? ''),
                    'photo' => !empty($t['photo']) ? site_image((string) $t['photo']) : null, 'rating' => (int) ($t['rating'] ?? 0) ?: null], fn ($v) => $v !== null && $v !== '');
            }
        }
    } else {
        $types = array_values(array_intersect((array) ($d['types'] ?? []), array_keys(cms_testimonial_types())));
        $limit = max(1, min(20, (int) ($d['limit'] ?? 6)));
        $where = "t.status = 'active'" . ($types ? ' AND t.type IN (' . implode(',', array_fill(0, count($types), '?')) . ')' : '');
        $rows = cp_try(fn () => db_all("SELECT t.name, t.designation, t.company, t.photo, t.content, t.rating, p.short_name AS program FROM testimonials t
                LEFT JOIN programs p ON p.id = t.program_id WHERE $where ORDER BY t.is_featured DESC, t.sort_order, t.id DESC LIMIT $limit", $types), []);
        foreach ($rows as $t) {
            $items[] = array_filter(['name' => (string) $t['name'], 'quote' => trim(strip_tags((string) $t['content'])), 'role' => (string) ($t['designation'] ?? ''),
                'company' => (string) ($t['company'] ?? ''), 'program' => (string) ($t['program'] ?? ''), 'photo' => cp_local_image_url($t['photo'] ?? null),
                'rating' => (int) $t['rating'] ?: null], fn ($v) => $v !== null && $v !== '');
        }
    }
    $items = array_values(array_filter($items, fn ($t) => ($t['quote'] ?? '') !== ''));
    if (!$items) {
        return '';
    }
    return cp_section_open($s, (string) ($d['heading'] ?? 'Testimonials'), 'overflow-hidden') . cp_block_heading($d)
        . testimonial_slider($items, ['ariaLabel' => (string) ($d['heading'] ?? '') ?: 'Testimonials', 'theme' => ($s['background'] ?? '') === 'dark' ? 'dark' : 'light'])
        . section_close();
}

/** FAQ block (CMS FAQs or manual questions); adds the questions to the page's FAQPage JSON-LD. */
function cp_block_faq(array $d, array $s, array &$ctx): string
{
    if (($d['source'] ?? 'auto') === 'manual') {
        $items = [];
        foreach ((array) ($d['items'] ?? []) as $f) {
            if (is_array($f) && trim((string) ($f['q'] ?? '')) !== '' && trim((string) ($f['a'] ?? '')) !== '') {
                $items[] = ['q' => trim((string) $f['q']), 'a' => trim((string) $f['a'])];
            }
        }
    } else {
        $cat = isset(cms_faq_categories()[$d['category'] ?? '']) ? $d['category'] : null;
        $items = array_map(fn ($f) => ['q' => $f['q'], 'a' => $f['a']], cp_faq_rows($cat, max(1, min(30, (int) ($d['limit'] ?? 8)))));
    }
    if (!$items) {
        return '';
    }
    $ctx['faq'] = array_merge($ctx['faq'] ?? [], $items);
    $cols = (int) ($d['columns'] ?? 1) === 2 ? 2 : 1;
    $action = cp_route_ok('/faq') ? '<a href="' . e(site_url('faq')) . '" class="link-arrow text-sm">All FAQs' . icon('arrow-right', 'h-4 w-4') . '</a>' : '';
    return cp_section_open($s, (string) ($d['heading'] ?? 'Frequently asked questions'))
        . (trim((string) ($d['heading'] ?? '')) !== '' ? cp_block_heading($d, 'left', $action) : '')
        . '<div class="' . ($cols === 2 ? '' : 'mx-auto max-w-3xl') . '">' . faq_accordion($items, ['columns' => $cols, 'defaultOpen' => 0]) . '</div>'
        . section_close();
}

/** Call-to-action block: dark (navy band) | orange | light. */
function cp_block_cta(array $d, array $s): string
{
    $title = trim((string) ($d['title'] ?? ''));
    if ($title === '') {
        return '';
    }
    $theme = in_array($d['theme'] ?? 'dark', ['dark', 'orange', 'light'], true) ? ($d['theme'] ?? 'dark') : 'dark';
    $primary = cp_link($d['primary'] ?? null);
    $secondary = cp_link($d['secondary'] ?? null);
    $s['padding'] = $s['padding'] ?? 'tight';
    $html = cp_section_open(['background' => ($s['background'] ?? 'white') === 'dark' ? 'white' : ($s['background'] ?? 'white')] + $s, $title);
    if ($theme === 'dark') {
        $html .= cta_band(['title' => $title, 'text' => (string) ($d['text'] ?? ''), 'eyebrow' => (string) ($d['eyebrow'] ?? ''), 'image' => trim((string) ($d['image'] ?? '')) ?: null,
            'primary' => $primary, 'secondary' => $secondary ? $secondary + ['icon' => null] : null, 'shell' => false, 'compact' => true]);
    } else {
        $orange = $theme === 'orange';
        $html .= '<div class="relative isolate overflow-hidden rounded-[28px] px-6 py-10 sm:px-12 sm:py-12 ' . ($orange ? 'bg-accent-600 text-white' : 'border border-warm-2 bg-warm') . '"' . reveal_attr() . '>'
            . geo_shapes('card')
            . '<div class="relative flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between"><div class="max-w-2xl">'
            . (trim((string) ($d['eyebrow'] ?? '')) !== '' ? '<p class="eyebrow mb-2' . ($orange ? ' !text-white/85' : '') . '">' . e($d['eyebrow']) . '</p>' : '')
            . '<h2 class="font-display text-[clamp(1.6rem,1.1rem+1.6vw,2.3rem)] font-extrabold leading-tight ' . ($orange ? 'text-white' : 'text-brand-900') . '">' . e($title) . '</h2>'
            . (trim((string) ($d['text'] ?? '')) !== '' ? '<p class="mt-3 ' . ($orange ? 'text-white/85' : 'text-slate-600') . '">' . e($d['text']) . '</p>' : '')
            . '</div><div class="flex shrink-0 flex-wrap gap-3">'
            . ($primary ? button_link($primary['label'], $primary['url'], $orange ? 'white' : 'apply', ['size' => 'lg']) : '')
            . ($secondary ? button_link($secondary['label'], $secondary['url'], $orange ? 'outline-light' : cp_secondary_variant($secondary), ['size' => 'lg']) : '')
            . '</div></div></div>';
    }
    return $html . section_close();
}

/** Gallery block (manual photos or a gallery album). */
function cp_block_gallery(array $d, array $s): string
{
    $items = [];
    if (($d['source'] ?? 'manual') === 'gallery' && (int) ($d['gallery_id'] ?? 0) > 0) {
        $rows = cp_try(fn () => db_all("SELECT COALESCE(NULLIF(gi.image, ''), m.path) AS image, gi.caption, g.title FROM gallery_items gi
                JOIN galleries g ON g.id = gi.gallery_id LEFT JOIN media m ON m.id = gi.media_id
                WHERE g.id = ? AND g.status = 'published' AND (NULLIF(gi.image, '') IS NOT NULL OR m.path IS NOT NULL) ORDER BY gi.sort_order, gi.id LIMIT 60", [(int) $d['gallery_id']]), []);
        foreach ($rows as $r) {
            $items[] = ['src' => site_image($r['image']), 'alt' => (string) ($r['caption'] ?: $r['title']), 'caption' => (string) ($r['caption'] ?? '')];
        }
    } else {
        foreach ((array) ($d['items'] ?? []) as $it) {
            if (is_array($it) && trim((string) ($it['image'] ?? '')) !== '') {
                $items[] = ['src' => site_image((string) $it['image']), 'alt' => (string) ($it['alt'] ?? $it['caption'] ?? ''), 'caption' => (string) ($it['caption'] ?? '')];
            }
        }
    }
    if (!$items) {
        return '';
    }
    $cols = in_array((int) ($d['columns'] ?? 3), [2, 3, 4], true) ? (int) $d['columns'] : 3;
    $layout = in_array($d['layout'] ?? 'grid', ['grid', 'masonry', 'bento'], true) ? ($d['layout'] ?? 'grid') : 'grid';
    return cp_section_open($s, (string) ($d['heading'] ?? 'Gallery')) . cp_block_heading($d)
        . sa_gallery_grid($items, ['columns' => $cols, 'layout' => $layout, 'ariaLabel' => (string) ($d['heading'] ?? '') ?: 'Photo gallery'])
        . section_close();
}

/** Course listing block (programs table). */
function cp_block_courses(array $d, array $s): string
{
    $programs = site_programs();
    $mode = $d['mode'] ?? 'all';
    if ($mode === 'featured') {
        $programs = array_values(array_filter($programs, fn ($p) => !empty($p['is_featured'])));
    } elseif ($mode === 'level') {
        $programs = array_values(array_filter($programs, fn ($p) => ($p['level'] ?? '') === ($d['level'] ?? '')));
    } elseif ($mode === 'department') {
        $programs = array_values(array_filter($programs, fn ($p) => (int) ($p['department_id'] ?? 0) === (int) ($d['department_id'] ?? 0)));
    } elseif ($mode === 'selected') {
        $byId = array_column($programs, null, 'id');
        $programs = [];
        foreach ((array) ($d['program_ids'] ?? []) as $id) {
            if (isset($byId[(int) $id])) {
                $programs[] = $byId[(int) $id];
            }
        }
    }
    $programs = array_slice($programs, 0, max(1, min(24, (int) ($d['limit'] ?? 6))));
    if (!$programs) {
        return '';
    }
    $cta = cp_link($d['cta'] ?? null);
    $html = cp_section_open($s, (string) ($d['heading'] ?? 'Programs')) . cp_block_heading($d, 'left', $cta && empty($d['show_filters']) ? button_link($cta['label'], $cta['url'], 'explore') : '');
    if (!empty($d['show_filters']) && count($programs) > 3) {
        $html .= program_explorer($programs, array_filter(['layout' => 'pills', 'viewAll' => $cta ? ['label' => $cta['label'], 'url' => $cta['url']] : null]));
    } else {
        $html .= '<div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3" data-stagger="80">';
        foreach ($programs as $p) {
            $html .= program_card($p);
        }
        $html .= '</div>';
        if ($cta && !empty($d['show_filters'])) {
            $html .= '<div class="mt-8 text-center">' . button_link($cta['label'], $cta['url'], 'explore') . '</div>';
        }
    }
    return $html . section_close();
}

/** Faculty listing block (public faculty profiles only). */
function cp_block_faculty(array $d, array $s): string
{
    $faculty = sa_faculty(array_filter(['department_id' => (int) ($d['department_id'] ?? 0) ?: null, 'limit' => max(1, min(48, (int) ($d['limit'] ?? 8)))]));
    if (!$faculty) {
        return '';
    }
    $cta = cp_link($d['cta'] ?? null);
    $showQ = ($d['show_qualification'] ?? true) !== false;
    $showBio = !empty($d['show_bio']);
    $html = cp_section_open($s, (string) ($d['heading'] ?? 'Faculty')) . cp_block_heading($d, 'left', $cta ? button_link($cta['label'], $cta['url'], 'outline') : '');
    $html .= '<ul class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4" data-stagger="60">';
    foreach ($faculty as $f) {
        $name = sa_person_name($f);
        $html .= '<li class="flex h-full flex-col items-center rounded-[22px] border border-warm-2 bg-white p-6 text-center shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-card">'
            . sa_avatar($f['photo'] ?? null, $name, (int) $f['id'], 'h-20 w-20 text-xl shadow-soft')
            . '<h3 class="mt-4 font-display text-base font-bold text-brand-900">' . e($name) . '</h3>'
            . '<p class="text-sm font-medium text-accent-700">' . e($f['designation']) . '</p>'
            . (!empty($f['department_name']) ? '<p class="mt-0.5 text-xs text-slate-500">' . e($f['department_name']) . '</p>' : '')
            . ($showQ && !empty($f['qualification']) ? '<p class="mt-3 text-[13px] text-slate-600">' . e($f['qualification']) . '</p>' : '')
            . ($showBio && !empty($f['bio']) ? '<p class="mt-2 text-[13px] leading-relaxed text-slate-500">' . e(str_limit((string) $f['bio'], 150)) . '</p>' : '')
            . '</li>';
    }
    return $html . '</ul>' . section_close();
}

/** Placement section block: live stats, latest offers (first name + initial only) and recruiter wordmarks. */
function cp_block_placement(array $d, array $s): string
{
    $stats = cp_stat_items((array) ($d['stats'] ?? []));
    $offers = [];
    if (($d['show_offers'] ?? true) !== false) {
        $rows = cp_try(fn () => db_all("SELECT o.package_lpa, o.job_role, s.first_name, s.last_name, p.short_name AS program, c.name AS company
            FROM placement_offers o JOIN students s ON s.id = o.student_id JOIN companies c ON c.id = o.company_id LEFT JOIN programs p ON p.id = s.program_id
            WHERE o.status IN ('accepted', 'joined') AND o.package_lpa > 0 ORDER BY o.offer_date DESC, o.package_lpa DESC LIMIT " . max(3, min(12, (int) ($d['offers_limit'] ?? 6)))), []);
        foreach ($rows as $r) {
            $offers[] = $r;
        }
    }
    $recruiters = [];
    if (($d['show_recruiters'] ?? true) !== false) {
        $rows = cp_try(fn () => db_all("SELECT name, logo, industry FROM companies WHERE status = 'active' AND is_recruiter = 1 ORDER BY sort_order, name LIMIT " . max(4, min(48, (int) ($d['recruiters_limit'] ?? 16)))), []);
        foreach ($rows as $r) {
            $recruiters[] = array_filter(['name' => (string) $r['name'], 'logo' => cp_local_image_url($r['logo'] ?? null), 'industry' => (string) ($r['industry'] ?? '')]);
        }
    }
    if (!$stats && !$offers && !$recruiters) {
        return '';
    }
    $cta = cp_link($d['cta'] ?? null);
    $html = cp_section_open($s, (string) ($d['heading'] ?? 'Placements'), 'overflow-hidden') . cp_block_heading($d, 'left', $cta ? button_link($cta['label'], $cta['url'], 'explore') : '');
    if ($stats) {
        $html .= '<div class="mb-10">' . stat_counter($stats, ['variant' => 'cards', 'theme' => ($s['background'] ?? '') === 'dark' ? 'dark' : 'light', 'gridClass' => 'grid-cols-2 lg:grid-cols-' . min(4, max(2, count($stats)))]) . '</div>';
    }
    if ($offers) {
        $html .= '<h3 class="mb-4 font-display text-lg font-extrabold text-brand-900">Recent offers</h3><ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-stagger="60">';
        foreach ($offers as $o) {
            $who = trim((string) $o['first_name'] . ' ' . (trim((string) $o['last_name']) !== '' ? mb_strtoupper(mb_substr(trim((string) $o['last_name']), 0, 1)) . '.' : ''));
            $html .= '<li class="flex items-center gap-4 rounded-[18px] border border-warm-2 bg-white p-4 shadow-sm">'
                . '<span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-accent-50 font-display text-sm font-extrabold text-accent-700">' . e(rtrim(rtrim(number_format((float) $o['package_lpa'], 1), '0'), '.')) . '<span class="ml-0.5 text-[10px]">LPA</span></span>'
                . '<span class="min-w-0"><span class="block truncate font-semibold text-brand-900">' . e($o['company']) . '</span><span class="block truncate text-xs text-slate-500">' . e(trim($who . ($o['program'] ? ' · ' . $o['program'] : '') . ($o['job_role'] ? ' · ' . $o['job_role'] : ''), ' ·')) . '</span></span></li>';
        }
        $html .= '</ul>';
    }
    if ($recruiters) {
        $html .= '<div class="mt-10">' . logo_marquee($recruiters, ['label' => 'Our Recruiters', 'ariaLabel' => 'Recruiting companies']) . '</div>';
    }
    return $html . section_close();
}

/* ============================================================================ Listing helpers */

/**
 * Accessible pagination (server-side). $url: fn(int $page): string.
 * Renders nothing for a single page; rel=prev/next on the neighbour links.
 */
function cp_pagination(array $pg, callable $url, string $label = 'Pagination'): string
{
    if (($pg['pages'] ?? 1) <= 1) {
        return '';
    }
    $cur = (int) $pg['page'];
    $last = (int) $pg['pages'];
    $nums = array_unique(array_filter([1, $cur - 1, $cur, $cur + 1, $last], fn ($n) => $n >= 1 && $n <= $last));
    sort($nums);
    $btn = 'inline-flex h-10 min-w-10 items-center justify-center rounded-full px-3 text-sm font-semibold transition';
    $html = '<nav class="mt-10 flex flex-wrap items-center justify-center gap-2" aria-label="' . e($label) . '">';
    $html .= $cur > 1 ? '<a href="' . e($url($cur - 1)) . '" rel="prev" class="' . $btn . ' border border-warm-2 bg-white text-brand-900 hover:border-brand-900">' . icon('arrow-left', 'h-4 w-4') . '<span class="ml-1 hidden sm:inline">Previous</span></a>' : '';
    $prev = 0;
    foreach ($nums as $n) {
        if ($prev && $n > $prev + 1) {
            $html .= '<span class="px-1 text-slate-400" aria-hidden="true">…</span>';
        }
        $html .= $n === $cur
            ? '<span class="' . $btn . ' bg-brand-900 text-white" aria-current="page">' . $n . '</span>'
            : '<a href="' . e($url($n)) . '" class="' . $btn . ' border border-warm-2 bg-white text-brand-900 hover:border-brand-900"><span class="sr-only">Page </span>' . $n . '</a>';
        $prev = $n;
    }
    $html .= $cur < $last ? '<a href="' . e($url($cur + 1)) . '" rel="next" class="' . $btn . ' border border-warm-2 bg-white text-brand-900 hover:border-brand-900"><span class="mr-1 hidden sm:inline">Next</span>' . icon('arrow-right', 'h-4 w-4') . '</a>' : '';
    return $html . '</nav>';
}

/** Search words from a query: lower-case, 2+ characters, max 6. */
function cp_search_words(string $q): array
{
    $words = preg_split('/[^\p{L}\p{M}\p{N}+#.&-]+/u', mb_strtolower($q)) ?: [];
    $words = array_values(array_unique(array_filter(array_map(fn ($w) => trim($w, '.-&'), $words), fn ($w) => mb_strlen($w) >= 2)));
    return array_slice($words, 0, 6);
}

/** Escape $text and wrap every search word in <mark>. */
function cp_highlight(string $text, array $words): string
{
    $html = e($text);
    if (!$words) {
        return $html;
    }
    $parts = array_map(fn ($w) => preg_quote(e($w), '/'), $words);
    usort($parts, fn ($a, $b) => strlen($b) <=> strlen($a));
    return (string) preg_replace('/(?<![\p{L}\p{N}])(' . implode('|', $parts) . ')/iu', '<mark>$1</mark>', $html);
}

/** Plain-text snippet of $text around the first matching word (max $len chars). */
function cp_snippet(string $text, array $words, int $len = 190): string
{
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>', '</h2>', '</h3>', '</td>'], ' ', $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if (mb_strlen($text) <= $len) {
        return $text;
    }
    $pos = null;
    foreach ($words as $w) {
        $p = mb_stripos($text, $w);
        if ($p !== false && ($pos === null || $p < $pos)) {
            $pos = $p;
        }
    }
    $start = $pos === null ? 0 : max(0, $pos - (int) ($len / 3));
    if ($start > 0) {
        $space = mb_strpos($text, ' ', $start);
        $start = $space !== false && $space < $start + 20 ? $space + 1 : $start;
    }
    $snip = mb_substr($text, $start, $len);
    return ($start > 0 ? '…' : '') . rtrim($snip) . ($start + $len < mb_strlen($text) ? '…' : '');
}

/* ============================================================================ Site search */

/**
 * Searchable content types whose public page exists right now: key => [label, icon]. Types whose detail page has not
 * been built yet (e.g. /blog/{slug} before post.php exists) are left out so results never link to a 404.
 */
function cp_search_types(): array
{
    $types = [
        'programs' => ['Programs', 'graduation-cap', 'program.php'],
        'pages' => ['Pages', 'file-text', 'page.php'],
        'news' => ['News & Blog', 'newspaper', 'post.php'],
        'events' => ['Events', 'calendar-days', 'event.php'],
        'notices' => ['Notices', 'megaphone', 'notice.php'],
        'faculty' => ['Faculty', 'users', 'faculty.php'],
        'faqs' => ['FAQs', 'circle-help', 'faq.php'],
        'galleries' => ['Galleries', 'images', 'gallery.php'],
    ];
    $out = [];
    foreach ($types as $k => [$label, $ic, $file]) {
        if (is_file(APP_ROOT . '/' . $file)) {
            $out[$k] = ['label' => $label, 'icon' => $ic];
        }
    }
    return $out;
}

/**
 * Portable (MariaDB PCRE / MySQL 8 ICU) regular expression matching $w at the start of a word, so "mba" finds "MBA" and
 * "mba-finance" but not "embarrassment". Used as LOWER(haystack) REGEXP ? — search words are already lower-case.
 */
function cp_word_regex(string $w): string
{
    return '(^|[^[:alnum:]])' . preg_quote($w, '/');
}

/**
 * SQL pieces for one search type: [from+where (without the word conditions), haystack expression, title expression,
 * select list, order by]. Word conditions are AND-ed: every word must appear somewhere in the haystack.
 */
function cp_search_sql(string $type): ?array
{
    switch ($type) {
        case 'programs':
            return ["FROM programs p LEFT JOIN departments d ON d.id = p.department_id WHERE p.status = 'active' AND p.show_on_website = 1",
                "CONCAT_WS(' ', p.name, p.short_name, p.code, p.degree, p.category, p.level, d.name, p.overview, p.eligibility, p.highlights, p.career_prospects)", 'CONCAT_WS(\' \', p.name, p.short_name)',
                'p.id, p.name AS title, p.slug, p.short_name, p.level, p.duration_label, p.overview AS body, d.name AS meta', 'p.sort_order, p.name'];
        case 'news':
            return ["FROM blog_posts b LEFT JOIN blog_categories c ON c.id = b.category_id WHERE b.status = 'published' AND (b.published_at IS NULL OR b.published_at <= NOW())",
                "CONCAT_WS(' ', b.title, b.excerpt, b.tags, b.content, c.name)", 'b.title',
                'b.id, b.title, b.slug, b.excerpt, b.content AS body, b.published_at AS date, c.name AS meta', 'b.published_at DESC'];
        case 'events':
            return ["FROM events ev WHERE ev.show_on_website = 1 AND ev.status IN ('published', 'completed')",
                "CONCAT_WS(' ', ev.title, ev.excerpt, ev.description, ev.venue, ev.category, ev.organizer)", 'ev.title',
                'ev.id, ev.title, ev.slug, ev.excerpt, ev.description AS body, ev.start_datetime AS date, ev.venue AS meta', 'ev.start_datetime DESC'];
        case 'notices':
            return ["FROM notices n WHERE n.status = 'published' AND n.show_on_website = 1 AND (n.publish_date IS NULL OR n.publish_date <= NOW()) AND (n.expiry_date IS NULL OR n.expiry_date >= NOW())",
                "CONCAT_WS(' ', n.title, n.category, n.description)", 'n.title',
                'n.id, n.title, n.slug, n.description AS body, n.publish_date AS date, n.category AS meta, n.is_pinned', 'n.is_pinned DESC, n.publish_date DESC'];
        case 'faculty':
            return ["FROM faculty f LEFT JOIN departments d ON d.id = f.department_id WHERE f.status IN ('active', 'on_leave') AND f.show_on_website = 1",
                "CONCAT_WS(' ', f.title, f.first_name, f.last_name, f.designation, f.qualification, f.specialization, f.research_interests, d.name)", "CONCAT_WS(' ', f.first_name, f.last_name)",
                'f.id, f.title AS honorific, f.first_name, f.last_name, f.photo, f.designation, f.qualification, f.specialization, f.research_interests AS body, d.name AS meta',
                "CASE WHEN f.designation LIKE '%Director%' OR f.designation LIKE '%Dean%' THEN 0 WHEN f.designation LIKE 'Professor%' THEN 1 ELSE 2 END, f.first_name"];
        case 'faqs':
            return ["FROM faqs q WHERE q.status = 'active'", "CONCAT_WS(' ', q.question, q.answer, q.category)", 'q.question',
                'q.id, q.question AS title, q.answer AS body, q.category AS meta', 'q.sort_order, q.id'];
        case 'galleries':
            return ["FROM galleries g WHERE g.status = 'published'", "CONCAT_WS(' ', g.title, g.description, g.category)", 'g.title',
                'g.id, g.title, g.slug, g.description AS body, g.cover_image, g.event_date AS date, g.category AS meta', 'g.sort_order, g.title'];
    }
    return null;
}

/**
 * CMS pages matched in PHP on their visible text only (title, subtitle, excerpt, rich text without tags and the text
 * fields of builder blocks) — matching the raw columns would find HTML tags and block JSON keys ("strong", "heading",
 * "items" …). The pages table is small, so one LIMITed read per request is cheap.
 */
function cp_search_pages(array $words, int $limit, int $offset = 0): array
{
    static $docs = null;
    if ($docs === null) {
        $docs = [];
        $rows = cp_try(fn () => db_all("SELECT id, title, slug, is_system, parent_id, excerpt, subtitle, content, blocks, updated_at, sort_order FROM pages
                WHERE status = 'published' AND (published_at IS NULL OR published_at <= NOW()) AND slug <> 'home' ORDER BY sort_order, title LIMIT 500"), []);
        foreach ($rows as $r) {
            $text = trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>', '</h2>', '</h3>', '</td>', '</th>'], ' ', (string) $r['content'])), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                . ' ' . cp_block_text(array_map(fn ($b) => $b['data'], cms_page_blocks($r))));
            $text = trim((string) preg_replace('/\s+/u', ' ', $text));
            $r['body'] = $text;
            $r['_title'] = mb_strtolower($r['title']);
            $r['_hay'] = mb_strtolower($r['title'] . ' ' . $r['subtitle'] . ' ' . $r['excerpt'] . ' ' . $text);
            unset($r['content']);
            $docs[] = $r;
        }
    }
    $re = fn (string $w) => '/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '/u';
    $hits = [];
    foreach ($docs as $d) {
        if (!cp_url_ok(cms_page_url($d))) {
            continue;
        }
        foreach ($words as $w) {
            if (!preg_match($re($w), $d['_hay'])) {
                continue 2;
            }
        }
        $d['_rank'] = count(array_filter($words, fn ($w) => preg_match($re($w), $d['_title'])));
        $hits[] = $d;
    }
    usort($hits, fn ($a, $b) => [$b['_rank'], (int) $a['sort_order'], $a['title']] <=> [$a['_rank'], (int) $b['sort_order'], $b['title']]);
    $rows = [];
    foreach (array_slice($hits, max(0, $offset), max(0, $limit)) as $d) {
        $rows[] = cp_search_row('pages', $d, $words);
    }
    return ['total' => count($hits), 'rows' => array_values(array_filter($rows))];
}

/** Count + rows for one type. Rows normalised to {title, url, snippet, meta, date, icon, image}. */
function cp_search_type(string $type, array $words, int $limit, int $offset = 0): array
{
    if ($type === 'pages') {
        return $words ? cp_search_pages($words, $limit, $offset) : ['total' => 0, 'rows' => []];
    }
    $sql = cp_search_sql($type);
    if (!$sql || !$words) {
        return ['total' => 0, 'rows' => []];
    }
    [$from, $hay, $titleExpr, $select, $order] = $sql;
    // Rich-text columns are matched on their text: tags and entities (<strong>, &nbsp; …) are removed first.
    $hay = "REGEXP_REPLACE($hay, '<[^>]*>|&[#a-zA-Z0-9]+;', ' ')";
    $cond = [];
    $args = [];
    foreach ($words as $w) {
        $cond[] = "LOWER($hay) REGEXP ?";
        $args[] = cp_word_regex($w);
    }
    $where = $from . ' AND ' . implode(' AND ', $cond);
    $total = (int) cp_try(fn () => db_value("SELECT COUNT(*) $where", $args), 0);
    if (!$total || $limit <= 0) {
        return ['total' => $total, 'rows' => []];
    }
    // Title matches first, then the type's natural order.
    $rank = '(' . implode(' + ', array_fill(0, count($words), "(LOWER($titleExpr) REGEXP ?)")) . ') DESC';
    $rows = cp_try(fn () => db_all("SELECT $select $where ORDER BY $rank, $order LIMIT " . (int) $limit . ' OFFSET ' . max(0, (int) $offset), array_merge($args, array_map('cp_word_regex', $words))), []);
    $out = [];
    foreach ($rows as $r) {
        $out[] = cp_search_row($type, $r, $words);
    }
    return ['total' => $total, 'rows' => array_values(array_filter($out))];
}

/** Normalise a search hit to a display row (null when the target cannot be linked). */
function cp_search_row(string $type, array $r, array $words): ?array
{
    switch ($type) {
        case 'programs':
            return ['title' => $r['title'] . ($r['short_name'] && !str_contains($r['title'], (string) $r['short_name']) ? ' (' . $r['short_name'] . ')' : ''),
                'url' => site_url('programs/' . $r['slug']), 'snippet' => cp_snippet((string) $r['body'], $words),
                'meta' => trim(implode(' · ', array_filter([program_level_label($r['level']), $r['duration_label'], $r['meta']]))), 'icon' => 'graduation-cap'];
        case 'pages':
            $url = cms_page_url($r);
            if (!cp_url_ok($url)) {
                return null;
            }
            $body = trim((string) $r['body']) !== '' ? (string) $r['body'] : cp_block_text(array_map(fn ($b) => $b['data'], cms_page_blocks($r)));
            $snippet = cp_snippet(trim(($r['excerpt'] ?? '') . ' ' . $body), $words);
            return ['title' => $r['title'], 'url' => site_url(ltrim($url, '/')), 'snippet' => preg_replace('/^Last updated:\s*\d{1,2} \w+ \d{4}\s*/i', '', $snippet),
                'meta' => cp_is_policy($r) ? 'Policy · Updated ' . format_date($r['updated_at'] ?? null) : 'Page', 'icon' => cp_is_policy($r) ? cp_policy_icon((string) $r['slug']) : 'file-text'];
        case 'news':
            return ['title' => $r['title'], 'url' => site_url('blog/' . $r['slug']), 'snippet' => cp_snippet(trim((string) ($r['excerpt'] ?: $r['body'])), $words),
                'meta' => trim(implode(' · ', array_filter([$r['meta'], $r['date'] ? format_date($r['date']) : '']))), 'icon' => 'newspaper'];
        case 'events':
            return ['title' => $r['title'], 'url' => site_url('events/' . $r['slug']), 'snippet' => cp_snippet(trim((string) ($r['excerpt'] ?: $r['body'])), $words),
                'meta' => trim(implode(' · ', array_filter([$r['date'] ? format_date($r['date'], 'd M Y, h:i A') : '', $r['meta']]))), 'icon' => 'calendar-days'];
        case 'notices':
            return ['title' => $r['title'], 'url' => site_url('notices/' . $r['slug']), 'snippet' => cp_snippet((string) $r['body'], $words),
                'meta' => trim(implode(' · ', array_filter([!empty($r['is_pinned']) ? 'Pinned' : '', label_from_key((string) $r['meta']), $r['date'] ? format_date($r['date']) : '']))), 'icon' => 'megaphone'];
        case 'faculty':
            $name = trim(implode(' ', array_filter([$r['honorific'], $r['first_name'], $r['last_name']])));
            return ['title' => $name, 'url' => site_url('faculty?q=' . rawurlencode(trim($r['first_name'] . ' ' . $r['last_name']))),
                'snippet' => cp_snippet(trim(implode(' · ', array_filter([$r['qualification'], $r['specialization'], $r['body']]))), $words),
                'meta' => trim(implode(' · ', array_filter([$r['designation'], $r['meta']]))), 'icon' => 'user-round'];
        case 'faqs':
            return ['title' => $r['title'], 'url' => site_url('faq') . '#faq-' . (int) $r['id'], 'snippet' => cp_snippet((string) $r['body'], $words),
                'meta' => 'FAQ · ' . (cms_faq_categories()[$r['meta']] ?? label_from_key((string) $r['meta'])), 'icon' => 'circle-help'];
        case 'galleries':
            return ['title' => $r['title'], 'url' => site_url('gallery/' . $r['slug']), 'snippet' => cp_snippet((string) $r['body'], $words),
                'meta' => trim(implode(' · ', array_filter([label_from_key((string) $r['meta']), $r['date'] ? format_date($r['date']) : '']))), 'icon' => 'images'];
    }
    return null;
}

/* ============================================================================ 404 helpers */

/** Popular destinations that exist right now: [[label, url, icon, text]]. */
function cp_popular_links(): array
{
    $c = [
        ['Programs', '/programs', 'graduation-cap', 'UG, PG, diploma and certificate programs'],
        ['Admissions', '/admissions', 'clipboard-check', 'Eligibility, process, fees and dates'],
        ['Apply Online', '/apply', 'file-check', 'Start your application in minutes'],
        ['Notice Board', '/notices', 'megaphone', 'Latest notices and circulars'],
        ['Contact Us', '/contact', 'phone', 'Call, email or visit the campus'],
        ['FAQs', '/faq', 'circle-help', 'Answers to common questions'],
        ['Campus Life', '/campus', 'building-2', 'Facilities, hostel and clubs'],
        ['Academics', '/academics', 'book-open', 'Departments and academic calendar'],
    ];
    return array_values(array_filter($c, fn ($l) => cp_route_ok($l[1])));
}

/**
 * Pages that resemble a requested (missing) path: CMS pages, programs and public routes ranked by slug similarity.
 * @return array<int, array{title: string, url: string, type: string}>
 */
function cp_404_suggestions(string $path, int $limit = 4): array
{
    $path = strtolower(trim(rawurldecode($path), '/'));
    $path = (string) preg_replace('/\.(php|html?|aspx?)$/', '', $path);
    $segments = array_values(array_filter(explode('/', $path)));
    $needle = (string) preg_replace('/[^a-z0-9]+/', '-', end($segments) ?: '');
    if (strlen($needle) < 2) {
        return [];
    }
    $cands = [];
    foreach (cms_public_routes() as $route => $r) {
        if ($route !== '/' && cp_route_ok($route)) {
            $cands[] = ['slug' => ltrim($route, '/'), 'title' => trim((string) preg_replace('/\s*\([^)]*\)$/', '', $r['label'])), 'url' => $route, 'type' => 'Page'];
        }
    }
    foreach (cp_published_pages() as $p) {
        $url = cms_page_url($p);
        if (cp_url_ok($url)) {
            $cands[] = ['slug' => $p['slug'], 'title' => $p['title'], 'url' => $url, 'type' => cp_is_policy($p) ? 'Policy' : 'Page'];
        }
    }
    if (cp_route_ok('/programs/x')) {
        foreach (site_programs() as $p) {
            $cands[] = ['slug' => (string) $p['slug'], 'title' => (string) $p['name'], 'url' => '/programs/' . $p['slug'], 'type' => 'Program', 'alt' => strtolower((string) ($p['short_name'] ?? ''))];
        }
    }
    $words = array_filter(explode('-', $needle), fn ($w) => strlen($w) >= 3);
    $flat = str_replace('-', '', $needle);              // "b-tech" / "btech" → "btech"
    $scored = [];
    foreach ($cands as $c) {
        $slug = strtolower($c['slug']);
        $slugFlat = str_replace('-', '', $slug);
        $alt = preg_replace('/\(.*$/', '', (string) ($c['alt'] ?? ''));    // "B.Tech (CSE)" → "b.tech "
        $alt = (string) preg_replace('/[^a-z0-9]/', '', strtolower($alt)); // → "btech"
        // Typo distance on the whole slug (mistyped / pluralised URLs): /admision → admissions, /placements → placement.
        $score = 1 - levenshtein(substr($needle, 0, 255), substr($slug, 0, 255)) / max(strlen($needle), strlen($slug), 1);
        if (strlen($flat) >= 6) { // long requests: also accept strong character overlap
            similar_text($needle, $slug, $pct);
            $score = max($score, ($pct / 100) - 0.1);
        }
        // Part of the slug / program short name: /btech → B.Tech programs, /mba → MBA, /bca-course → BCA.
        if ((strlen($flat) >= 3 && str_contains($slugFlat, $flat)) || (strlen($slugFlat) >= 4 && str_contains($flat, $slugFlat))
            || ($alt !== '' && strlen($alt) >= 2 && preg_match('/(^|-)' . preg_quote($alt, '/') . '(-|$)/', $needle . '-' . $flat))) {
            $score = max($score, 0.85);
        }
        foreach ($words as $w) {
            if (str_contains($slug, $w) || str_contains(strtolower($c['title']), $w)) {
                $score += 0.15;
            }
        }
        if ($score >= 0.6) {
            $scored[$c['url']] = ['score' => $score] + $c;
        }
    }
    // Topic words (/fees, /hostel, /calendar …) → the popular destination that covers them.
    foreach (cp_popular_links() as [$label, $url, , $text]) {
        foreach ($words ?: [$needle] as $w) {
            if (strlen($w) >= 3 && !in_array($w, ['and', 'the', 'for', 'with', 'our', 'page', 'php', 'html'], true) && !isset($scored[$url]) && preg_match('/\b' . preg_quote($w, '/') . '/i', $text)) {
                $scored[$url] = ['score' => 0.65, 'title' => $label, 'url' => $url, 'type' => 'Page'];
            }
        }
    }
    uasort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
    return array_map(fn ($c) => ['title' => $c['title'], 'url' => $c['url'], 'type' => $c['type']], array_slice(array_values($scored), 0, $limit));
}
