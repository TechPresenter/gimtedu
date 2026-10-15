<?php
/**
 * CMS core: clean-URL routing, pages & sections, menus, SEO (meta, Open Graph, JSON-LD),
 * first-party analytics and marketing attribution.
 *
 * Every public URL is a row in `pages` (slug "about" => /about/). index.php is the front
 * controller: it resolves the request to a page and includes templates/<template>.php.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** Templates that accept extra path segments, e.g. /campaign/<slug>/ or /dashboard/<section>/. */
const PARAM_TEMPLATES = ['campaign_detail', 'event_detail', 'blog', 'stories', 'member', 'schemes', 'gallery'];

/** Templates whose pages cannot be deleted in the admin (the admin may still edit their content). */
const TEMPLATE_LABELS = [
    'default' => 'Standard content page', 'home' => 'Home page', 'policy' => 'Policy / long text with contents',
    'program' => 'Programme detail', 'programs' => 'Programmes list', 'team' => 'Team / management',
    'ngo_details' => 'NGO details', 'documents' => 'Documents list', 'gallery' => 'Photo / video gallery',
    'volunteer' => 'Volunteer application', 'teacher' => 'Teacher application', 'staff' => 'Staff application',
    'internship' => 'Internship application', 'membership' => 'Membership', 'scholarship' => 'Scholarship application',
    'request_help' => 'Request help', 'application_status' => 'Application status', 'campaigns' => 'Campaign list',
    'campaign_detail' => 'Campaign detail', 'donate' => 'Donation form', 'donation_success' => 'Donation success',
    'donation_failed' => 'Donation failed', 'donation_receipt' => 'Donation receipt', 'fundraiser' => 'Fundraiser proposal',
    'transparency' => 'Financial transparency', 'utilization' => 'Utilisation reports', 'events' => 'Event list',
    'event_detail' => 'Event detail', 'calendar' => 'Awareness calendar', 'blog' => 'Blog / press list',
    'stories' => 'Success stories', 'schemes' => 'Welfare schemes', 'contact' => 'Contact form', 'feedback' => 'Feedback form',
    'faq' => 'FAQ', 'sitemap' => 'HTML sitemap', 'not_found' => '404 page', 'login' => 'Login', 'register' => 'Register',
    'forgot_password' => 'Forgot password', 'reset_password' => 'Reset password', 'verify_email' => 'Verify email',
    'member' => 'Member dashboard', 'logout' => 'Logout',
];

// ---------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------

/** Current request path relative to the site root, without slashes at either end ('' = home). */
function route_path(): string
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $path = rawurldecode($path);
    $base = base_path();
    if ($base !== '' && strpos($path, $base) === 0) {
        $path = substr($path, strlen($base));
    }
    return trim($path, '/');
}

/** Extra path after the page slug (e.g. "bihar-flood-relief" for /campaign/bihar-flood-relief/). */
function route_param(): string
{
    return (string) ($GLOBALS['route']['param'] ?? '');
}

/** route_param() split into segments. */
function route_segments(): array
{
    $p = route_param();
    return $p === '' ? [] : explode('/', $p);
}

/** The page row being rendered. */
function current_page_row(): ?array
{
    return $GLOBALS['route']['page'] ?? null;
}

/** URL of a page by slug: page_url('about') => /about/ ; page_url('home') => / */
function page_url(string $slug = 'home', array $query = []): string
{
    $slug = trim($slug, '/');
    $path = ($slug === '' || $slug === 'home') ? '/' : '/' . $slug . '/';
    return url($path) . ($query ? '?' . http_build_query($query) : '');
}

function abs_page_url(string $slug = 'home', array $query = []): string
{
    $slug = trim($slug, '/');
    return abs_url(($slug === '' || $slug === 'home') ? '' : $slug . '/') . ($query ? '?' . http_build_query($query) : '');
}

function campaign_url(string $slug): string { return page_url('campaign/' . $slug); }
function event_url(string $slug): string    { return page_url('event-details/' . $slug); }
function post_url(string $slug): string     { return page_url('blog/' . $slug); }
function story_url(string $slug): string    { return page_url('success-stories/' . $slug); }
function scheme_url(string $slug): string   { return page_url('schemes/' . $slug); }
function member_url(string $section = ''): string { return page_url('dashboard' . ($section !== '' ? '/' . $section : '')); }
function donate_url(array $query = []): string    { return page_url('donate', $query); }

/** Programme URL: the linked page's slug, or /programs/<slug>/. */
function program_url(array $program): string
{
    if (!empty($program['page_slug'])) {
        return page_url($program['page_slug']);
    }
    return page_url('programs/' . $program['slug']);
}

/** Is this the current page (for active menu states)? */
function is_current_url(string $href): bool
{
    $path = trim((string) parse_url($href, PHP_URL_PATH), '/');
    $base = trim(base_path(), '/');
    if ($base !== '' && strpos($path, $base) === 0) {
        $path = trim(substr($path, strlen($base)), '/');
    }
    $current = route_path();
    return $path === $current || ($path !== '' && strpos($current . '/', $path . '/') === 0);
}

/**
 * Resolve the request to [page, param]. Returns null when nothing matches.
 * Draft pages are only visible to staff with pages.manage (preview).
 */
function resolve_route(string $path): ?array
{
    $slug = $path === '' ? 'home' : $path;
    if (!preg_match('#^[a-z0-9][a-z0-9\-_/]*$#i', $slug) && $slug !== 'home') {
        return null;
    }
    $page = db_row('SELECT * FROM pages WHERE slug = ?', [$slug]);
    $param = '';
    if (!$page) {
        $parts = explode('/', $slug);
        while (count($parts) > 1) {
            array_pop($parts);
            $candidate = db_row('SELECT * FROM pages WHERE slug = ?', [implode('/', $parts)]);
            if ($candidate && in_array($candidate['template'], PARAM_TEMPLATES, true)) {
                $page = $candidate;
                $param = substr($slug, strlen($candidate['slug']) + 1);
                break;
            }
        }
    }
    if (!$page) {
        return null;
    }
    if ($page['status'] !== 'published' && !(isset($_GET['preview']) && user_can('pages.manage'))) {
        return null;
    }
    return ['page' => $page, 'param' => $param];
}

/** Look up a permanent redirect for an old address. */
function find_redirect(string $path): ?array
{
    if ($path === '') {
        return null;
    }
    $row = db_row('SELECT * FROM redirects WHERE source_path = ? OR source_path = ?', [$path, $path . '/']);
    if ($row) {
        db_query('UPDATE redirects SET hits = hits + 1 WHERE id = ?', [$row['id']]);
    }
    return $row;
}

/** Load a page by slug (published only unless $any). */
function get_page(string $slug, bool $any = false): ?array
{
    return db_row('SELECT * FROM pages WHERE slug = ?' . ($any ? '' : " AND status = 'published'"), [$slug]);
}

/** Decode a JSON column to an array. */
function json_field($value): array
{
    if (is_array($value)) {
        return $value;
    }
    $data = json_decode((string) $value, true);
    return is_array($data) ? $data : [];
}

function page_setting(array $page, string $key, $default = null)
{
    $settings = json_field($page['settings'] ?? null);
    return $settings[$key] ?? $default;
}

/** Show the 404 page and stop. Logs the address for Admin > SEO > Broken links. */
function not_found(): void
{
    http_response_code(404);
    $path = route_path();
    if ($path !== '' && strlen($path) <= 255 && !preg_match('/\.(png|jpe?g|gif|webp|svg|ico|css|js|map|woff2?)$/i', $path)) {
        try {
            db_query('INSERT INTO not_found_log (path, referrer_host, last_seen) VALUES (?, ?, NOW())
                      ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = NOW()', [$path, referrer_host()]);
        } catch (Throwable $e) {
            error_log('404 log failed: ' . $e->getMessage());
        }
    }
    $page = get_page('not-found', true) ?: ['id' => 0, 'slug' => 'not-found', 'template' => 'not_found', 'title' => 'Page not found',
        'banner_subtitle' => '', 'meta_title' => null, 'meta_description' => null, 'noindex' => 1, 'settings' => null];
    $GLOBALS['route'] = ['page' => $page, 'param' => ''];
    require ROOT_PATH . '/templates/not_found.php';
    exit;
}

// ---------------------------------------------------------------------
// Page output
// ---------------------------------------------------------------------

/**
 * Start a page: SEO head, header, optional banner.
 * $opts (all optional): title, subtitle, eyebrow, image, buttons (ui_buttons format), crumbs ([[label, url], ...]),
 *   banner (false = none), meta_title, meta_description, canonical, og_image, og_type ('website'|'article'),
 *   schema (array of JSON-LD objects), noindex (bool), body_class, robots
 */
function page_header(array $page, array $opts = []): void
{
    $title = $opts['title'] ?? $page['title'];
    $GLOBALS['seo'] = [
        'title'       => ($opts['meta_title'] ?? ($page['meta_title'] ?: null)) ?? $title,
        'description' => $opts['meta_description'] ?? ($page['meta_description'] ?: setting('seo_default_description')),
        'canonical'   => $opts['canonical'] ?? ($page['canonical_url'] ?: abs_url(route_path() === '' ? '' : route_path() . '/')),
        'og_image'    => $opts['og_image'] ?? ($page['og_image'] ?? '') ?: ($page['banner_image'] ?? ''),
        'og_type'     => $opts['og_type'] ?? 'website',
        'schema'      => $opts['schema'] ?? [],
        'noindex'     => !empty($opts['noindex']) || !empty($page['noindex']) || ($page['status'] ?? 'published') !== 'published' || setting('seo_noindex_site') === '1',
        'is_home'     => ($page['template'] ?? '') === 'home',
        'crumbs'      => $opts['crumbs'] ?? page_crumbs($page),
        'title_plain' => $title,
    ];
    $GLOBALS['bodyClass'] = trim('tpl-' . ($page['template'] ?? 'default') . ' ' . ($opts['body_class'] ?? ''));
    track_page_view($page);
    require ROOT_PATH . '/header.php';

    if (($opts['banner'] ?? true) !== false && ($page['template'] ?? '') !== 'home') {
        $buttons = $opts['buttons'] ?? json_field($page['banner_buttons'] ?? null);
        echo page_banner($title, $opts['subtitle'] ?? (string) ($page['banner_subtitle'] ?? ''), $GLOBALS['seo']['crumbs'], [
            'eyebrow' => $opts['eyebrow'] ?? ($page['banner_eyebrow'] ?? ''),
            'image'   => $opts['image'] ?? ($page['banner_image'] ?? ''),
            'actions' => $buttons,
        ]);
    }
    if (($page['status'] ?? 'published') !== 'published') {
        echo '<div class="container"><div class="alert alert-warning mt-2">' . icon('eye') . '<div>Preview of a <strong>' . e($page['status']) . '</strong> page. Visitors cannot see it until it is published.</div></div></div>';
    }
}

function page_footer(): void
{
    require ROOT_PATH . '/footer.php';
}

/** Breadcrumb trail from the page's parents: [[label, url], ...] (excluding Home and the page itself). */
function page_crumbs(array $page): array
{
    $crumbs = [];
    $parentId = $page['parent_id'] ?? null;
    $guard = 0;
    while ($parentId && $guard++ < 5) {
        $parent = db_row("SELECT id, parent_id, slug, title FROM pages WHERE id = ? AND status = 'published'", [$parentId]);
        if (!$parent) {
            break;
        }
        array_unshift($crumbs, [$parent['title'], page_url($parent['slug'])]);
        $parentId = $parent['parent_id'];
    }
    return $crumbs;
}

/** Visible sections of a page. */
function page_sections(int $pageId): array
{
    return db_rows("SELECT * FROM page_sections WHERE page_id = ? AND status = 'visible' ORDER BY sort_order, id", [$pageId]);
}

/**
 * Render a page's sections. A section of type "module" marks where the template's functional block
 * (form, list, etc.) goes; if there is none, $module is rendered after the sections ($position 'after') or before.
 */
function render_sections(array $page, ?callable $module = null, string $position = 'after'): void
{
    $sections = !empty($page['id']) ? page_sections((int) $page['id']) : [];
    $hasModule = false;
    foreach ($sections as $s) {
        if ($s['section_type'] === 'module') {
            $hasModule = true;
        }
    }
    if ($module && !$hasModule && $position === 'before') {
        $module();
    }
    foreach ($sections as $s) {
        if ($s['section_type'] === 'module') {
            if ($module) {
                $module();
            }
            continue;
        }
        echo render_section($s, $page);
    }
    if ($module && !$hasModule && $position !== 'before') {
        $module();
    }
}

/** One section wrapped in <section>. Section types are functions named section_<type>(). */
function render_section(array $s, array $page = []): string
{
    $type = preg_replace('/[^a-z0-9_]/', '', (string) $s['section_type']);
    $fn = 'section_' . $type;
    if (!function_exists($fn)) {
        return '';
    }
    $settings = json_field($s['settings'] ?? null);
    $items = json_field($s['items'] ?? null);
    $inner = $fn($s, $items, $settings, $page);
    if ($inner === '') {
        return '';
    }
    if (!empty($settings['bare']) || $type === 'subnav') {
        return $inner;   // sticky in-page navigation must not be wrapped
    }
    $bg = in_array($s['background'], ['white', 'tint', 'cream', 'green', 'blue'], true) ? $s['background'] : 'white';
    $width = ($settings['width'] ?? '') === 'narrow' ? ' narrow' : '';
    $head = (!empty($s['title']) && empty($settings['no_heading']) && !in_array($type, ['cta', 'split', 'quote', 'callout', 'subnav'], true))
        ? ui_heading((string) $s['title'], (string) ($s['pill'] ?? ''), (string) ($s['intro'] ?? ''), ($settings['align'] ?? '') === 'center')
        : '';
    $id = $s['anchor'] ? ' id="' . e($s['anchor']) . '"' : '';
    $cls = 'section section-' . $bg . ' sec-' . str_replace('_', '-', $type) . (($settings['size'] ?? '') === 'sm' ? ' section-sm' : '');
    return '<section class="' . $cls . '"' . $id . ' data-section="' . (int) ($s['id'] ?? 0) . '"><div class="container' . $width . '">' . $head . $inner . '</div></section>';
}

/**
 * Section types available in the page editor. A type exists when section_<type>() exists;
 * section_schema_<type>() describes its editable fields for the admin editor:
 * [
 *   'label'    => 'Cards grid',
 *   'fields'   => ['pill', 'title', 'intro', 'body', 'image'],      // standard columns used
 *   'items'    => ['icon' => ['icon', 'Icon'], 'title' => ['text', 'Title']] | 'strings' | null,
 *   'settings' => ['cols' => ['select', 'Columns', ['2' => '2', '3' => '3']], 'reverse' => ['checkbox', 'Image on the right']],
 *   'help'     => 'Short explanation for editors',
 * ]
 * Field types: text, textarea, html, url, icon, image, number, checkbox, select, buttons, strings, date.
 */
function section_types(): array
{
    $types = [];
    foreach (get_defined_functions()['user'] as $fn) {
        if (strpos($fn, 'section_schema_') === 0) {
            $type = substr($fn, 15);
            if (function_exists('section_' . $type)) {
                $schema = call_user_func($fn);
                $types[$type] = $schema['label'] ?? humanize($type);
            }
        }
    }
    asort($types);
    return $types;
}

function section_schema(string $type): array
{
    $fn = 'section_schema_' . preg_replace('/[^a-z0-9_]/', '', $type);
    $schema = function_exists($fn) ? call_user_func($fn) : [];
    return $schema + ['label' => humanize($type), 'fields' => ['pill', 'title', 'intro'], 'items' => null, 'settings' => [], 'help' => ''];
}

/** Settings shared by every section (layout). */
function section_common_settings(): array
{
    return [
        'width' => ['select', 'Content width', ['' => 'Normal', 'narrow' => 'Narrow (reading width)']],
        'align' => ['select', 'Heading alignment', ['' => 'Left', 'center' => 'Centre']],
        'size' => ['select', 'Spacing', ['' => 'Normal', 'sm' => 'Compact']],
        'no_heading' => ['checkbox', 'Hide the section heading'],
    ];
}

function buttons_setting(): array { return ['buttons', 'Buttons']; }

function section_schema_richtext(): array { return ['label' => 'Text (rich text)', 'fields' => ['pill', 'title', 'intro', 'body', 'image'], 'settings' => ['toc' => ['checkbox', 'Show table of contents'], 'columns' => ['checkbox', 'Two text columns'], 'image_alt' => ['text', 'Image description (alt text)']]]; }
function section_schema_split(): array { return ['label' => 'Image + text (two columns)', 'fields' => ['pill', 'title', 'intro', 'body', 'image'], 'items' => 'strings', 'settings' => ['reverse' => ['checkbox', 'Image on the right'], 'image_alt' => ['text', 'Image description (alt text)'], 'buttons' => buttons_setting()], 'help' => 'Items are shown as a tick list under the text.']; }
function section_schema_features(): array { return ['label' => 'Cards grid (icon, title, text)', 'fields' => ['pill', 'title', 'intro'], 'items' => ['icon' => ['icon', 'Icon'], 'title' => ['text', 'Title'], 'text' => ['textarea', 'Text'], 'link' => ['url', 'Link (optional)'], 'link_label' => ['text', 'Link text'], 'tone' => ['select', 'Colour', ['' => 'Auto', 'green' => 'Green', 'blue' => 'Blue', 'gold' => 'Gold', 'orange' => 'Orange', 'teal' => 'Teal']]], 'settings' => ['cols' => ['select', 'Columns', ['' => 'Auto', '2' => '2', '3' => '3', '4' => '4']], 'style' => ['select', 'Card style', ['card' => 'Card', 'soft' => 'Soft', 'numbered' => 'Numbered', 'plain' => 'Plain']]]]; }
function section_schema_steps(): array { return ['label' => 'Numbered steps / process', 'fields' => ['pill', 'title', 'intro'], 'items' => ['icon' => ['icon', 'Icon'], 'title' => ['text', 'Title'], 'text' => ['textarea', 'Text']]]; }
function section_schema_checklist(): array { return ['label' => 'Tick list', 'fields' => ['pill', 'title', 'intro'], 'items' => 'strings', 'settings' => ['cols' => ['select', 'Columns', ['1' => '1', '2' => '2', '3' => '3']]]]; }
function section_schema_highlights(): array { return ['label' => 'Highlights strip', 'fields' => ['pill', 'title', 'intro'], 'items' => ['icon' => ['icon', 'Icon'], 'title' => ['text', 'Title'], 'text' => ['text', 'Text']]]; }
function section_schema_faqs(): array { return ['label' => 'Questions & answers', 'fields' => ['pill', 'title', 'intro'], 'items' => ['q' => ['text', 'Question'], 'a' => ['textarea', 'Answer']], 'settings' => ['open_first' => ['checkbox', 'Open the first answer']]]; }
function section_schema_cta(): array { return ['label' => 'Call to action band', 'fields' => ['title', 'intro'], 'settings' => ['buttons' => buttons_setting(), 'hindi_tagline' => ['checkbox', 'Show the Hindi tagline']]]; }
function section_schema_callout(): array { return ['label' => 'Notice / callout box', 'fields' => ['title', 'intro'], 'settings' => ['tone' => ['select', 'Colour', ['green' => 'Green', 'gold' => 'Gold', 'blue' => 'Blue']], 'icon' => ['icon', 'Icon']]]; }
function section_schema_quote(): array { return ['label' => 'Motto / quote band', 'fields' => ['title'], 'settings' => ['caption' => ['text', 'Caption']], 'help' => 'Use only the foundation\'s own words.']; }
function section_schema_stats(): array { return ['label' => 'Numbers (verified figures only)', 'fields' => ['pill', 'title', 'intro'], 'items' => ['value' => ['text', 'Number (e.g. 1,200+)'], 'label' => ['text', 'Label'], 'icon' => ['icon', 'Icon']], 'settings' => ['note' => ['text', 'Source / note (e.g. As of March 2027, from our records)']], 'help' => 'Publish only figures the foundation has verified from its own records.']; }
function section_schema_facts(): array { return ['label' => 'Key facts table', 'fields' => ['pill', 'title', 'intro'], 'items' => ['label' => ['text', 'Label'], 'value' => ['text', 'Value']], 'help' => 'Only real, verified facts. Empty values are hidden.']; }
function section_schema_link_cards(): array { return ['label' => 'Link cards (to pages)', 'fields' => ['pill', 'title', 'intro'], 'items' => ['icon' => ['icon', 'Icon'], 'title' => ['text', 'Title'], 'text' => ['text', 'Short text'], 'url' => ['url', 'Link']]]; }
function section_schema_image(): array { return ['label' => 'Single image with caption', 'fields' => ['title', 'intro', 'image'], 'settings' => ['image_alt' => ['text', 'Image description (alt text)']], 'help' => 'Publish identifiable photos of children or vulnerable people only with documented consent.']; }
function section_schema_video(): array { return ['label' => 'YouTube video', 'fields' => ['pill', 'title', 'intro'], 'settings' => ['url' => ['url', 'YouTube link']]]; }
function section_schema_subnav(): array { return ['label' => 'On-this-page navigation', 'fields' => [], 'items' => ['anchor' => ['text', 'Section anchor'], 'label' => ['text', 'Label']], 'help' => 'Leave items empty to list every section that has an anchor and a title.']; }
function section_schema_module(): array { return ['label' => 'Page function position (form / list)', 'fields' => [], 'help' => 'Marks where the page\'s built-in form or list appears. Pages without it show the function after all sections.']; }
function section_module(array $s, array $items, array $set, array $page): string { return ''; }

// ----- Core section renderers. Signature: (array $s, array $items, array $settings, array $page): string

function section_richtext(array $s, array $items, array $set, array $page): string
{
    $body = (string) ($s['body'] ?? '');
    if ($body === '') {
        return '';
    }
    if (!empty($set['toc'])) {
        [$body, $toc] = content_with_toc($body);
        if (count($toc) > 1) {
            $nav = '<nav class="toc toc-inline" aria-label="Contents"><strong>On this page</strong><ol>';
            foreach ($toc as $id => $label) {
                $nav .= '<li><a href="#' . e($id) . '">' . e($label) . '</a></li>';
            }
            $body = $nav . '</ol></nav>' . $body;
        }
    }
    $img = !empty($s['image']) ? '<figure class="prose-figure reveal"><img src="' . e(image_url($s['image'])) . '" alt="' . e($set['image_alt'] ?? '') . '" loading="lazy"></figure>' : '';
    return '<div class="prose reveal' . (!empty($set['columns']) ? ' prose-cols' : '') . '">' . $img . $body . '</div>';
}

function section_split(array $s, array $items, array $set, array $page): string
{
    return ui_split([
        'pill' => $s['pill'] ?? '', 'title' => $s['title'] ?? '', 'text' => $s['intro'] ?? '',
        'html' => !empty($s['body']) ? '<div class="prose">' . $s['body'] . '</div>' : '',
        'points' => array_values(array_filter($items, 'is_string')),
        'image' => $s['image'] ?: ($set['fallback_image'] ?? 'assets/images/hero/community.svg'),
        'image_alt' => $set['image_alt'] ?? '', 'reverse' => !empty($set['reverse']),
        'actions' => $set['buttons'] ?? [],
    ]);
}

function section_features(array $s, array $items, array $set, array $page): string
{
    if (!$items) {
        return '';
    }
    $cols = (int) ($set['cols'] ?? (count($items) % 3 === 0 || count($items) > 4 ? 3 : (count($items) >= 4 ? 4 : max(1, count($items)))));
    return ui_features($items, $cols, (string) ($set['style'] ?? 'card'));
}

function section_steps(array $s, array $items, array $set, array $page): string
{
    return $items ? ui_steps($items) : '';
}

function section_checklist(array $s, array $items, array $set, array $page): string
{
    $list = array_values(array_filter(array_map(function ($i) {
        return is_array($i) ? ($i['text'] ?? $i['title'] ?? '') : (string) $i;
    }, $items)));
    return $list ? ui_checklist($list, (int) ($set['cols'] ?? 2)) : '';
}

function section_highlights(array $s, array $items, array $set, array $page): string
{
    return $items ? ui_highlights($items) : '';
}

function section_faqs(array $s, array $items, array $set, array $page): string
{
    if (!$items) {
        return '';
    }
    $GLOBALS['seo_faqs'] = array_merge($GLOBALS['seo_faqs'] ?? [], $items);
    return ui_faqs($items, !empty($set['open_first']));
}

function section_cta(array $s, array $items, array $set, array $page): string
{
    $buttons = $set['buttons'] ?? [['label' => 'Donate Now', 'url' => '/donate/', 'style' => 'donate', 'icon' => 'heart', 'size' => 'lg'],
        ['label' => 'Become a Volunteer', 'url' => '/become-a-volunteer/', 'style' => 'light', 'size' => 'lg']];
    return ui_cta((string) ($s['title'] ?: 'Together, let us build a better tomorrow'), (string) ($s['intro'] ?? ''), $buttons, !empty($set['hindi_tagline']));
}

function section_callout(array $s, array $items, array $set, array $page): string
{
    $text = trim(strip_tags((string) ($s['intro'] ?: $s['body'] ?? '')));
    return ui_callout((string) ($s['title'] ?? ''), $text, (string) ($set['tone'] ?? 'green'), (string) ($set['icon'] ?? 'info'));
}

function section_quote(array $s, array $items, array $set, array $page): string
{
    return ui_quote((string) ($s['title'] ?: $s['intro']), (string) ($set['caption'] ?? ''));
}

/** Numbers band. Only figures entered and verified by the foundation are shown. */
function section_stats(array $s, array $items, array $set, array $page): string
{
    $items = array_values(array_filter($items, function ($i) {
        return is_array($i) && trim((string) ($i['value'] ?? '')) !== '' && trim((string) ($i['label'] ?? '')) !== '';
    }));
    if (!$items) {
        return '';
    }
    $html = '<div class="stats-grid stats-' . min(4, count($items)) . '">';
    foreach ($items as $it) {
        $value = (string) $it['value'];
        $num = preg_replace('/[^\d]/', '', $value);
        $html .= '<div class="stat reveal">' . (!empty($it['icon']) ? '<span class="stat-icon">' . icon($it['icon']) . '</span>' : '')
            . '<strong' . ($num !== '' && strlen($num) < 10 ? ' data-count="' . (int) $num . '" data-format="' . e($value) . '"' : '') . '>' . e($value) . '</strong>'
            . '<span>' . e($it['label']) . '</span></div>';
    }
    $html .= '</div>';
    if (!empty($set['note'])) {
        $html .= '<p class="stats-note">' . e($set['note']) . '</p>';
    }
    return $html;
}

function section_facts(array $s, array $items, array $set, array $page): string
{
    $facts = [];
    foreach ($items as $it) {
        if (is_array($it) && trim((string) ($it['value'] ?? '')) !== '') {
            $facts[(string) ($it['label'] ?? '')] = (string) $it['value'];
        }
    }
    return $facts ? ui_facts($facts) : '';
}

function section_link_cards(array $s, array $items, array $set, array $page): string
{
    if (!$items) {
        return '';
    }
    $html = '<div class="tile-grid">';
    foreach ($items as $it) {
        if (!is_array($it) || empty($it['url'])) {
            continue;
        }
        $html .= '<a class="tile reveal tilt" href="' . e(safe_link((string) $it['url'])) . '"><span class="icon-circle">' . icon($it['icon'] ?? 'arrow-right') . '</span>'
            . '<span><strong>' . e($it['title'] ?? '') . '</strong>' . (!empty($it['text']) ? '<small>' . e($it['text']) . '</small>' : '') . '</span>'
            . icon('chevron-right', 'tile-arrow') . '</a>';
    }
    return $html . '</div>';
}

function section_image(array $s, array $items, array $set, array $page): string
{
    if (empty($s['image'])) {
        return '';
    }
    return '<figure class="wide-figure reveal"><img src="' . e(image_url($s['image'])) . '" alt="' . e($set['image_alt'] ?? $s['title'] ?? '') . '" loading="lazy">'
        . (!empty($s['intro']) ? '<figcaption>' . e($s['intro']) . '</figcaption>' : '') . '</figure>';
}

function section_video(array $s, array $items, array $set, array $page): string
{
    $id = youtube_id((string) ($set['url'] ?? ''));
    if (!$id) {
        return '';
    }
    return '<div class="video-frame reveal"><iframe src="https://www.youtube-nocookie.com/embed/' . e($id) . '" title="' . e($s['title'] ?: 'Video') . '" loading="lazy" allow="accelerometer; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>';
}

function section_subnav(array $s, array $items, array $set, array $page): string
{
    $links = [];
    foreach ($items as $it) {
        if (is_array($it) && !empty($it['anchor'])) {
            $links[preg_replace('/[^a-z0-9\-_]/i', '', $it['anchor'])] = (string) ($it['label'] ?? $it['anchor']);
        }
    }
    if (!$links && !empty($page['id'])) {
        foreach (page_sections((int) $page['id']) as $sec) {
            if ($sec['anchor'] && $sec['title']) {
                $links[$sec['anchor']] = $sec['title'];
            }
        }
    }
    return $links ? ui_subnav($links) : '';
}

/** YouTube video id from a URL, or ''. */
function youtube_id(string $url): string
{
    if (preg_match('~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) {
        return $m[1];
    }
    return '';
}

/** Add id attributes to <h2> headings and return [html, ['id' => 'Heading']] for a table of contents. */
function content_with_toc(string $html): array
{
    $toc = [];
    $used = [];
    $html = preg_replace_callback('#<h2(\s[^>]*)?>(.*?)</h2>#is', function ($m) use (&$toc, &$used) {
        $attrs = $m[1] ?? '';
        $text = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8'));
        if (preg_match('/\sid="([^"]+)"/', $attrs, $idm)) {
            $id = $idm[1];
        } else {
            $id = slugify($text);
            $base = $id;
            $n = 2;
            while (isset($used[$id])) {
                $id = $base . '-' . $n++;
            }
            $attrs .= ' id="' . e($id) . '"';
        }
        $used[$id] = true;
        $toc[$id] = $text;
        return '<h2' . $attrs . '>' . $m[2] . '</h2>';
    }, $html);
    return [$html, $toc];
}

/** Policy / long-form layout: sticky table of contents + help box + content from all richtext sections. */
function render_policy_layout(array $page, array $related = []): void
{
    $html = '';
    foreach (page_sections((int) $page['id']) as $s) {
        if ($s['section_type'] === 'richtext') {
            $html .= ($s['title'] ? '<h2>' . e($s['title']) . '</h2>' : '') . $s['body'];
        }
    }
    [$html, $toc] = content_with_toc($html);
    echo '<section class="section"><div class="container policy-layout"><aside class="policy-aside">';
    if (count($toc) > 1) {
        echo '<nav class="toc" aria-label="Contents"><strong>On this page</strong><ol>';
        foreach ($toc as $id => $label) {
            echo '<li><a href="#' . e($id) . '">' . e($label) . '</a></li>';
        }
        echo '</ol></nav>';
    }
    echo '<div class="aside-card aside-cta"><h3>Questions?</h3><p>Write to us and we will be happy to help.</p>'
        . '<a class="btn btn-primary btn-block" href="' . e(page_url('contact')) . '">' . icon('mail') . 'Contact us</a>'
        . '<a class="btn btn-outline btn-block" href="' . e(page_url('grievance-redressal')) . '">Raise a grievance</a></div>';
    if ($related) {
        echo '<div class="aside-card"><h3>Related policies</h3><ul class="link-list">';
        foreach ($related as $label => $href) {
            echo '<li><a href="' . e($href) . '">' . icon('file-text') . e($label) . '</a></li>';
        }
        echo '</ul></div>';
    }
    $updated = $page['updated_at'] ?: $page['created_at'];
    echo '</aside><article class="prose policy-content">' . $html
        . '<p class="muted small page-updated">Last updated: ' . e(format_date($updated)) . '</p></article></div></section>';
}

/** Links to the other policy pages. */
function policy_links(string $exceptSlug = ''): array
{
    $links = [];
    foreach (db_rows("SELECT slug, title FROM pages WHERE template = 'policy' AND status = 'published' ORDER BY sort_order") as $p) {
        if ($p['slug'] !== $exceptSlug) {
            $links[$p['title']] = page_url($p['slug']);
        }
    }
    if ($exceptSlug !== 'grievance-redressal') {
        $links['Grievance Redressal'] = page_url('grievance-redressal');
    }
    return $links;
}

// ---------------------------------------------------------------------
// Menus (Admin > Menus)
// ---------------------------------------------------------------------

/** Menu tree: [['label','url','icon','description','new_tab','children' => [...]], ...] */
function menu_tree(string $key): array
{
    static $cache = [];
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $rows = db_rows("SELECT mi.* FROM menu_items mi JOIN menus m ON m.id = mi.menu_id
                     WHERE m.menu_key = ? AND mi.status = 'visible' ORDER BY mi.sort_order, mi.id", [$key]);
    $byParent = [];
    foreach ($rows as $r) {
        $byParent[(int) $r['parent_id']][] = $r;
    }
    $build = function (int $parent) use (&$build, $byParent): array {
        $out = [];
        foreach ($byParent[$parent] ?? [] as $r) {
            $out[] = [
                'id' => (int) $r['id'], 'label' => $r['label'], 'url' => safe_link($r['url']), 'raw_url' => $r['url'],
                'icon' => $r['icon'], 'description' => $r['description'], 'new_tab' => (bool) $r['open_new_tab'],
                'children' => $build((int) $r['id']),
            ];
        }
        return $out;
    };
    return $cache[$key] = $build(0);
}

function menu_item_active(array $item): bool
{
    if (is_current_url($item['raw_url'] ?? $item['url'])) {
        return ($item['raw_url'] ?? '') !== '/' || route_path() === '';
    }
    foreach ($item['children'] ?? [] as $child) {
        if (menu_item_active($child)) {
            return true;
        }
    }
    return false;
}

/**
 * Only allow safe link targets: site paths (/about/), https/http URLs, mailto:, tel:, #anchors.
 * Anything else falls back to the homepage so no link is ever broken or unsafe.
 */
function safe_link(string $href): string
{
    $href = trim($href);
    if ($href === '' || $href === '#') {
        return url('/');
    }
    if (preg_match('#^https?://[^\s<>"]+$#i', $href) || preg_match('#^(mailto|tel):[^\s<>"]+$#i', $href)) {
        return $href;
    }
    if (preg_match('#^\#[\w\-]+$#', $href)) {
        return $href;
    }
    if (preg_match('#^/?[a-z0-9\-_/]*/?(\?[^\s<>"\#]*)?(\#[\w\-]+)?$#i', $href)) {
        $path = '/' . ltrim($href, '/');
        return url($path);
    }
    return url('/');
}

// ---------------------------------------------------------------------
// SEO: head tags & structured data
// ---------------------------------------------------------------------
function seo_full_title(): string
{
    $seo = $GLOBALS['seo'] ?? [];
    if (!empty($seo['is_home'])) {
        return ($seo['title'] && $seo['title'] !== 'Home' ? $seo['title'] : site_name()) . ' - ' . setting('site_tagline_en', 'Together, let us build a better tomorrow');
    }
    $title = (string) ($seo['title'] ?? site_name());
    $suffix = setting('seo_title_suffix', ' | ' . site_name());
    return mb_strlen($title . $suffix) <= 70 || stripos($title, site_name()) !== false ? $title . (stripos($title, site_name()) === false ? $suffix : '') : $title;
}

/** Organisation JSON-LD (only facts entered in settings). */
function organization_schema(): array
{
    $org = [
        '@context' => 'https://schema.org', '@type' => 'NGO', '@id' => abs_url('#organization'),
        'name' => site_name(), 'url' => abs_url(''), 'logo' => abs_url('assets/images/logo.png'),
        'description' => setting('site_description'), 'slogan' => setting('site_tagline_en'),
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => setting('contact_address'), 'addressCountry' => 'IN'],
    ];
    if (setting('contact_email')) {
        $org['email'] = setting('contact_email');
    }
    if (setting('contact_phone')) {
        $org['telephone'] = setting('contact_phone');
    }
    $same = array_column(social_links(), 'url');
    if ($same) {
        $org['sameAs'] = array_values($same);
    }
    return $org;
}

function breadcrumb_schema(array $crumbs, string $current): array
{
    $list = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => abs_url('')]];
    $pos = 2;
    foreach ($crumbs as [$label, $href]) {
        $list[] = ['@type' => 'ListItem', 'position' => $pos++, 'name' => $label, 'item' => rtrim(BASE_URL, '/') . substr($href, strlen(base_path()))];
    }
    $list[] = ['@type' => 'ListItem', 'position' => $pos, 'name' => $current, 'item' => $GLOBALS['seo']['canonical'] ?? abs_url(route_path() . '/')];
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
}

/** All JSON-LD blocks for the current page. */
function seo_schemas(): array
{
    $seo = $GLOBALS['seo'] ?? [];
    $out = [organization_schema()];
    if (!empty($seo['is_home'])) {
        $out[] = ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => site_name(), 'url' => abs_url(''), 'inLanguage' => 'en-IN'];
    } else {
        $out[] = breadcrumb_schema($seo['crumbs'] ?? [], (string) ($seo['title_plain'] ?? ''));
    }
    foreach ($seo['schema'] ?? [] as $schema) {
        $out[] = $schema;
    }
    return $out;
}

/** FAQPage JSON-LD from FAQ sections rendered on this page (call in the footer, after content). */
function faq_schema(): ?array
{
    $faqs = $GLOBALS['seo_faqs'] ?? [];
    if (!$faqs) {
        return null;
    }
    $items = [];
    foreach (array_slice($faqs, 0, 30) as $f) {
        if (!empty($f['q']) && !empty($f['a'])) {
            $items[] = ['@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]];
        }
    }
    return $items ? ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $items] : null;
}

function json_ld(array $data): string
{
    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
}

// ---------------------------------------------------------------------
// First-party analytics (privacy friendly) and marketing attribution
// ---------------------------------------------------------------------
function referrer_host(): ?string
{
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $host = $ref !== '' ? strtolower((string) parse_url($ref, PHP_URL_HOST)) : '';
    $own = strtolower((string) parse_url(BASE_URL, PHP_URL_HOST));
    if ($host === '' || $host === $own || $host === ($_SERVER['HTTP_HOST'] ?? '')) {
        return null;
    }
    return mb_substr(preg_replace('/^www\./', '', $host), 0, 190);
}

function device_type(): string
{
    $ua = strtolower(user_agent());
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|monitor|headless|curl|wget|python|httpclient|lighthouse/', $ua)) {
        return 'bot';
    }
    if (preg_match('/ipad|tablet/', $ua)) {
        return 'tablet';
    }
    return preg_match('/mobi|android|iphone/', $ua) ? 'mobile' : 'desktop';
}

/** Remember the first traffic source of the visit (UTM tags or referring site) for conversion reports. */
function capture_attribution(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || !empty($_SESSION['attr'])) {
        return;
    }
    $clean = function ($v, int $len): ?string {
        $v = is_string($v) ? trim(preg_replace('/[^\w\-\.\s]/u', '', $v)) : '';
        return $v === '' ? null : mb_substr(mb_strtolower($v), 0, $len);
    };
    $_SESSION['attr'] = [
        'utm_source'    => $clean($_GET['utm_source'] ?? '', 100),
        'utm_medium'    => $clean($_GET['utm_medium'] ?? '', 100),
        'utm_campaign'  => $clean($_GET['utm_campaign'] ?? '', 150),
        'referrer_host' => referrer_host(),
        'landing_path'  => '/' . route_path(),
    ];
}

function track_page_view(array $page): void
{
    if (setting('analytics_enabled', '1') !== '1' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || http_response_code() >= 400) {
        return;
    }
    if (setting('analytics_respect_dnt', '1') === '1' && (($_SERVER['HTTP_DNT'] ?? '') === '1' || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1')) {
        return;
    }
    $device = device_type();
    if ($device === 'bot' || is_staff()) {
        return;
    }
    capture_attribution();
    $attr = $_SESSION['attr'] ?? [];
    $salt = setting('analytics_salt_' . date('Ymd'));
    if ($salt === '') {
        $salt = bin2hex(random_bytes(16));
        save_setting('analytics_salt_' . date('Ymd'), $salt);
        db_query("DELETE FROM website_settings WHERE setting_key LIKE 'analytics\\_salt\\_%' AND setting_key <> ?", ['analytics_salt_' . date('Ymd')]);
    }
    try {
        db_insert('page_views', [
            'path'          => mb_substr('/' . route_path(), 0, 255),
            'page_id'       => !empty($page['id']) ? (int) $page['id'] : null,
            'referrer_host' => referrer_host(),
            'utm_source'    => $attr['utm_source'] ?? null,
            'utm_medium'    => $attr['utm_medium'] ?? null,
            'utm_campaign'  => $attr['utm_campaign'] ?? null,
            'device'        => $device,
            'visitor_hash'  => hash('sha256', $salt . client_ip() . user_agent()),
            'is_logged_in'  => is_logged_in() ? 1 : 0,
        ]);
        if (random_int(1, 500) === 1) {
            db_query('DELETE FROM page_views WHERE created_at < NOW() - INTERVAL ? DAY', [max(30, (int) setting('analytics_retention_days', '400'))]);
        }
    } catch (Throwable $e) {
        error_log('Page view failed: ' . $e->getMessage());
    }
}

/** Record a conversion (with the visit's traffic source) for marketing reports. */
function track_conversion(string $type, ?int $referenceId = null, ?float $value = null): void
{
    $attr = $_SESSION['attr'] ?? [];
    try {
        db_insert('marketing_events', [
            'event_type' => mb_substr($type, 0, 60), 'reference_id' => $referenceId, 'value' => $value,
            'landing_path' => $attr['landing_path'] ?? null, 'referrer_host' => $attr['referrer_host'] ?? null,
            'utm_source' => $attr['utm_source'] ?? null, 'utm_medium' => $attr['utm_medium'] ?? null, 'utm_campaign' => $attr['utm_campaign'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('Conversion tracking failed: ' . $e->getMessage());
    }
}

/** Share links for a page (with UTM tags so shares show up in marketing reports). */
function share_links(string $absUrl, string $title): array
{
    $u = function (string $source) use ($absUrl): string {
        return $absUrl . (strpos($absUrl, '?') === false ? '?' : '&') . 'utm_source=' . $source . '&utm_medium=social&utm_campaign=share';
    };
    return [
        'whatsapp' => ['WhatsApp', 'https://wa.me/?text=' . rawurlencode($title . ' ' . $u('whatsapp'))],
        'facebook' => ['Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($u('facebook'))],
        'twitter'  => ['X', 'https://twitter.com/intent/tweet?text=' . rawurlencode($title) . '&url=' . rawurlencode($u('twitter'))],
        'linkedin' => ['LinkedIn', 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode($u('linkedin'))],
        'mail'     => ['Email', 'mailto:?subject=' . rawurlencode($title) . '&body=' . rawurlencode($u('email'))],
    ];
}

function share_buttons(string $absUrl, string $title): string
{
    if (setting('share_buttons_enabled', '1') !== '1') {
        return '';
    }
    $html = '<div class="share-row" aria-label="Share"><span>Share:</span>';
    foreach (share_links($absUrl, $title) as $key => [$label, $href]) {
        $html .= '<a href="' . e($href) . '" target="_blank" rel="noopener noreferrer" class="share-' . $key . '" data-tip="' . e($label) . '" aria-label="Share on ' . e($label) . '">'
            . icon($key === 'mail' ? 'mail' : $key) . '</a>';
    }
    return $html . '<button type="button" class="share-copy" data-copy="' . e($absUrl) . '" data-tip="Copy link" aria-label="Copy link">' . icon('link') . '</button></div>';
}

// ---------------------------------------------------------------------
// Sitemap data (XML + HTML)
// ---------------------------------------------------------------------
/** [['loc' => absolute URL, 'lastmod' => date, 'title' => ...], ...] */
function sitemap_entries(): array
{
    $entries = [];
    foreach (db_rows("SELECT slug, title, updated_at, created_at, template FROM pages WHERE status = 'published' AND show_in_sitemap = 1 AND noindex = 0 ORDER BY sort_order, title") as $p) {
        $entries[] = ['loc' => abs_page_url($p['slug']), 'lastmod' => $p['updated_at'] ?: $p['created_at'], 'title' => $p['title'], 'group' => 'Pages'];
    }
    $add = function (string $sql, string $group, callable $urlFn) use (&$entries) {
        try {
            foreach (db_rows($sql) as $r) {
                $entries[] = ['loc' => rtrim(BASE_URL, '/') . substr($urlFn($r['slug']), strlen(base_path())), 'lastmod' => $r['updated_at'] ?: $r['created_at'], 'title' => $r['title'], 'group' => $group];
            }
        } catch (Throwable $e) {
            error_log('Sitemap query failed: ' . $e->getMessage());
        }
    };
    $add("SELECT slug, title, updated_at, created_at FROM campaigns WHERE status IN ('active','completed') ORDER BY created_at DESC", 'Campaigns', 'campaign_url');
    $add("SELECT slug, title, updated_at, created_at FROM events WHERE status = 'published' ORDER BY start_datetime DESC", 'Events', 'event_url');
    $add("SELECT slug, title, updated_at, created_at FROM blog_posts WHERE status = 'published' AND published_at <= NOW() ORDER BY published_at DESC", 'Articles', 'post_url');
    $add("SELECT slug, title, updated_at, created_at FROM success_stories WHERE status = 'published' AND consent_confirmed = 1 ORDER BY published_at DESC", 'Success stories', 'story_url');
    $add("SELECT slug, title, updated_at, created_at FROM schemes WHERE status = 'published' ORDER BY sort_order, title", 'Welfare schemes', 'scheme_url');
    return $entries;
}
