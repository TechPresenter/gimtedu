<?php
/**
 * Website CMS (unit cms_site) — custom endpoints on top of the generic CRUD modules. Contract: docs/CMS.md.
 *
 *   GET    /api/cms/overview                      KPIs, SEO score, content status, recent content + activity
 *   GET    /api/cms/schema                        section + block definitions, icons, option lists, live stat values
 *   GET    /api/cms/preview-data                  data used by the builder / section previews
 *   GET    /api/cms/pages/{id}/builder            page + normalised blocks
 *   PUT    /api/cms/pages/{id}/builder            {blocks, publish?, updated_at?}  save blocks (validated)
 *   POST   /api/cms/pages/{id}/duplicate          copy as draft
 *   POST   /api/cms/pages/{id}/publish|unpublish
 *   GET    /api/cms/homepage                      all 14 sections (content decoded) + schema keys
 *   PUT    /api/cms/homepage/{key}                {title, subtitle, content, is_enabled?}
 *   POST   /api/cms/homepage/reorder              {keys: []}
 *   POST   /api/cms/homepage/{key}/toggle         {enabled}
 *   POST   /api/cms/homepage/{key}/reset          restore default content
 *   POST   /api/cms/banners/reorder               {ids: []}
 *   POST   /api/cms/announcements/reorder         {ids: []}
 *   GET    /api/cms/media?source=library|stock&q=&page=
 *   POST   /api/cms/upload                        multipart file (+ alt)  → registered in the media library
 */
require_once APP_ROOT . '/app/services/cms.php';

/** Safe query helpers for tables owned by other units (they may be empty or still being built). */
function cms_try_all(string $sql, array $args = []): array
{
    try {
        return db_all($sql, $args);
    } catch (Throwable $e) {
        return [];
    }
}

function cms_try_value(string $sql, array $args = [], $default = 0)
{
    try {
        $v = db_value($sql, $args);
        return $v === null || $v === false ? $default : $v;
    } catch (Throwable $e) {
        return $default;
    }
}

function cms_page_or_404(int $id): array
{
    $page = db_row('SELECT * FROM pages WHERE id = ?', [$id]);
    if (!$page) {
        api_error('Page not found. It may have been deleted.', 404);
    }
    return $page;
}

function cms_page_payload(array $page): array
{
    [$t, $ref] = cms_page_seo_ref($page);
    $updatedBy = $page['updated_by'] ?? $page['created_by'] ?? null;
    return [
        'id' => (int) $page['id'], 'title' => $page['title'], 'slug' => $page['slug'], 'subtitle' => $page['subtitle'], 'excerpt' => $page['excerpt'],
        'banner_image' => $page['banner_image'], 'template' => $page['template'], 'status' => $page['status'], 'published_at' => $page['published_at'],
        'is_system' => (bool) $page['is_system'], 'url' => cms_page_url($page), 'absolute_url' => absolute_url(ltrim(cms_page_url($page), '/')),
        'updated_at' => $page['updated_at'] ?: $page['created_at'], 'updated_by_name' => $updatedBy ? db_value('SELECT name FROM users WHERE id = ?', [$updatedBy]) : null,
        'has_content' => trim(strip_tags((string) $page['content'])) !== '', 'content' => $page['content'],
        'seo_ref' => ['type' => $t, 'id' => $t === 'page' ? $ref : null, 'route' => $t === 'route' ? $ref : null],
    ];
}

/* ------------------------------------------------------------------ Overview */

route('GET', '/cms/overview', function () {
    cms_api_require('cms', 'view');
    $pages = db_row("SELECT COUNT(*) total, COALESCE(SUM(status = 'published'), 0) published, COALESCE(SUM(status = 'draft'), 0) draft,
                            COALESCE(SUM(is_system = 1), 0) system_pages, COALESCE(SUM(COALESCE(JSON_LENGTH(blocks), 0) > 0), 0) with_blocks,
                            COALESCE(SUM(status = 'published' AND published_at > NOW()), 0) scheduled FROM pages");
    $posts = ['total' => (int) cms_try_value('SELECT COUNT(*) FROM blog_posts'), 'published' => (int) cms_try_value("SELECT COUNT(*) FROM blog_posts WHERE status = 'published'"),
        'draft' => (int) cms_try_value("SELECT COUNT(*) FROM blog_posts WHERE status = 'draft'"), 'scheduled' => (int) cms_try_value("SELECT COUNT(*) FROM blog_posts WHERE status = 'scheduled'")];
    $media = ['total' => (int) cms_try_value('SELECT COUNT(*) FROM media'), 'bytes' => (int) cms_try_value('SELECT COALESCE(SUM(size_bytes), 0) FROM media'),
        'images' => (int) cms_try_value("SELECT COUNT(*) FROM media WHERE type = 'image'")];
    $live = "status = 'active' AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW())";
    $banners = db_row("SELECT COUNT(*) total, COALESCE(SUM($live), 0) live, COALESCE(SUM(status = 'active' AND starts_at > NOW()), 0) scheduled,
                              COALESCE(SUM(status = 'active' AND ends_at < NOW()), 0) expired, COALESCE(SUM(placement = 'home_hero' AND $live), 0) hero_live FROM banners");
    $menus = ['locations' => count(cms_menu_locations()), 'configured' => (int) db_value("SELECT COUNT(DISTINCT m.id) FROM menus m JOIN menu_items mi ON mi.menu_id = m.id WHERE m.status = 'active'"),
        'items' => (int) db_value("SELECT COUNT(*) FROM menu_items mi JOIN menus m ON m.id = mi.menu_id WHERE mi.status = 'active' AND m.status = 'active'")];
    $sections = db_row('SELECT COUNT(*) total, COALESCE(SUM(is_enabled = 1), 0) enabled FROM homepage_sections');
    $announcements = (int) db_value("SELECT COUNT(*) FROM announcements WHERE $live");
    $seo = cms_seo_overview();

    $recent = [];
    foreach (db_all('SELECT id, title, status, COALESCE(updated_at, created_at) AS ts, is_system FROM pages ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 6') as $r) {
        $recent[] = ['type' => 'page', 'id' => (int) $r['id'], 'title' => $r['title'], 'status' => $r['status'], 'updated_at' => $r['ts'], 'to' => '/cms/pages/' . $r['id'] . '/builder'];
    }
    foreach (cms_try_all('SELECT id, title, status, COALESCE(updated_at, created_at) AS ts FROM blog_posts ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 6') as $r) {
        $recent[] = ['type' => 'post', 'id' => (int) $r['id'], 'title' => $r['title'], 'status' => $r['status'], 'updated_at' => $r['ts'], 'to' => '/cms/blog'];
    }
    foreach (db_all("SELECT id, title, placement, COALESCE(updated_at, created_at) AS ts, $live AS is_live FROM banners ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 4") as $r) {
        $recent[] = ['type' => 'banner', 'id' => (int) $r['id'], 'title' => $r['title'], 'status' => $r['is_live'] ? 'live' : 'inactive', 'updated_at' => $r['ts'], 'to' => '/cms/banners'];
    }
    foreach (db_all('SELECT id, section_key, title, is_enabled, updated_at FROM homepage_sections WHERE updated_at IS NOT NULL ORDER BY updated_at DESC LIMIT 4') as $r) {
        $recent[] = ['type' => 'section', 'id' => (int) $r['id'], 'title' => (cms_section_types()[$r['section_key']]['label'] ?? $r['section_key']) . ($r['title'] ? ' — ' . $r['title'] : ''),
            'status' => $r['is_enabled'] ? 'enabled' : 'disabled', 'updated_at' => $r['updated_at'], 'to' => '/cms/homepage?section=' . $r['section_key']];
    }
    usort($recent, fn ($a, $b) => strcmp((string) $b['updated_at'], (string) $a['updated_at']));

    $activity = db_all("SELECT a.id, a.action, a.module, a.description, a.created_at, u.name AS user_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id
                        WHERE a.module IN ('cms', 'blog', 'media', 'seo') AND a.status = 'success' ORDER BY a.created_at DESC, a.id DESC LIMIT 8");
    // content updates per week (last 8 weeks) from the activity log
    $weeks = [];
    for ($i = 7; $i >= 0; $i--) {
        $start = strtotime('monday this week', strtotime("-$i week"));
        $weeks[date('Y-m-d', $start)] = ['label' => date('d M', $start), 'cms' => 0, 'blog' => 0, 'media' => 0, 'seo' => 0];
    }
    $from = array_key_first($weeks);
    foreach (db_all("SELECT module, DATE(created_at) d, COUNT(*) n FROM activity_logs WHERE module IN ('cms','blog','media','seo') AND action IN ('create','update','delete','publish','unpublish','import')
                     AND created_at >= ? GROUP BY module, DATE(created_at)", [$from]) as $r) {
        $wk = date('Y-m-d', strtotime('monday this week', strtotime($r['d'])));
        if (isset($weeks[$wk])) {
            $weeks[$wk][$r['module']] += (int) $r['n'];
        }
    }
    api_ok([
        'pages' => array_map('intval', $pages), 'posts' => $posts, 'media' => $media, 'banners' => array_map('intval', $banners), 'menus' => $menus,
        'sections' => array_map('intval', $sections), 'announcements_live' => $announcements,
        'seo' => ['score' => $seo['score'], 'coverage' => $seo['coverage'], 'total' => $seo['total'], 'custom' => $seo['custom'], 'issues' => $seo['issues'],
            'issue_labels' => $seo['issue_labels'], 'sitemap_urls' => $seo['sitemap']['urls'], 'noindex' => $seo['noindex']],
        'recent' => array_slice($recent, 0, 10), 'activity' => $activity, 'weekly' => array_values($weeks),
        'site_url' => absolute_url(''), 'can' => ['edit' => can('cms', 'edit'), 'create' => can('cms', 'create'), 'seo' => can('seo', 'view'), 'blog' => can('blog', 'view'), 'media' => can('media', 'view')],
    ]);
});

/* ------------------------------------------------------------------ Schema & preview data */

route('GET', '/cms/schema', function () {
    cms_api_require('cms', 'view');
    $sections = [];
    foreach (cms_section_types() as $k => $d) {
        $sections[] = ['key' => $k] + $d;
    }
    $blocks = [];
    foreach (cms_block_types() as $k => $d) {
        $blocks[] = ['type' => $k] + $d;
    }
    $values = cms_stat_values();
    $sources = [];
    foreach (cms_stat_sources() as $k => $label) {
        $sources[] = ['value' => $k, 'label' => $label, 'live' => $values[$k] ?? null];
    }
    $locations = [];
    foreach (cms_menu_locations() as $k => $l) {
        $locations[] = ['key' => $k] + $l;
    }
    api_ok([
        'sections' => $sections, 'blocks' => $blocks, 'icons' => cms_icon_names(), 'tints' => cms_opts(cms_tints()), 'stat_sources' => $sources,
        'menu_locations' => $locations, 'banner_placements' => cms_opts(cms_banner_placements()), 'announcement_types' => cms_opts(cms_announcement_types()),
        'page_templates' => cms_opts(cms_page_templates()), 'program_categories' => cms_opts(cms_program_category_options()),
        'site_url' => absolute_url(''),
    ]);
});

route('GET', '/cms/preview-data', function () {
    cms_api_require('cms', 'view');
    $programs = cms_try_all("SELECT p.id, p.name, p.short_name, p.slug, p.level, p.category, p.duration_label, p.fee_per_year, p.fee_label, p.image, p.is_featured, p.is_popular,
                                    p.department_id, p.eligibility FROM programs p WHERE p.status = 'active' AND p.show_on_website = 1 ORDER BY p.sort_order, p.name");
    foreach ($programs as &$p) {
        foreach (['id', 'department_id', 'is_featured', 'is_popular'] as $k) {
            $p[$k] = (int) $p[$k];
        }
        $p['fee_per_year'] = $p['fee_per_year'] !== null ? (float) $p['fee_per_year'] : null;
    }
    unset($p);
    $faculty = cms_try_all("SELECT f.id, TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS name, f.designation, f.qualification, f.photo, f.department_id, d.name AS department,
                                   LEFT(f.bio, 160) AS bio FROM faculty f LEFT JOIN departments d ON d.id = f.department_id
                            WHERE f.status = 'active' AND f.show_on_website = 1 ORDER BY f.department_id, f.id LIMIT 60");
    $galleries = cms_try_all("SELECT g.id, g.title, g.cover_image, (SELECT COUNT(*) FROM gallery_items gi WHERE gi.gallery_id = g.id) AS items FROM galleries g ORDER BY g.sort_order, g.title LIMIT 50");
    foreach ($galleries as &$g) {
        $g['id'] = (int) $g['id'];
        $g['items'] = (int) $g['items'];
        $g['photos'] = array_column(cms_try_all('SELECT COALESCE(gi.image, m.path) AS src FROM gallery_items gi LEFT JOIN media m ON m.id = gi.media_id WHERE gi.gallery_id = ? ORDER BY gi.sort_order LIMIT 8', [$g['id']]), 'src');
    }
    unset($g);
    api_ok([
        'programs' => $programs,
        'departments' => cms_try_all("SELECT id, name, code FROM departments WHERE status = 'active' ORDER BY name"),
        'faculty' => $faculty,
        'testimonials' => cms_try_all("SELECT t.id, t.name, t.designation, t.company, t.photo, t.content, t.rating, t.type, p.short_name AS program FROM testimonials t
                                       LEFT JOIN programs p ON p.id = t.program_id WHERE t.status = 'active' ORDER BY t.sort_order, t.id LIMIT 24"),
        'faqs' => cms_try_all("SELECT id, category, question, answer, show_on_home FROM faqs WHERE status = 'active' ORDER BY sort_order, id LIMIT 60"),
        'galleries' => $galleries,
        'recruiters' => cms_try_all("SELECT id, name, logo, industry FROM companies WHERE status = 'active' AND is_recruiter = 1 ORDER BY sort_order, name LIMIT 48"),
        'offers' => cms_try_all("SELECT o.id, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student, s.photo, pr.short_name AS program, c.name AS company, o.job_role, o.package_lpa
                                 FROM placement_offers o JOIN students s ON s.id = o.student_id LEFT JOIN programs pr ON pr.id = s.program_id JOIN companies c ON c.id = o.company_id
                                 WHERE o.status IN ('accepted','joined') ORDER BY o.package_lpa DESC, o.offer_date DESC LIMIT 12"),
        'events' => cms_try_all("SELECT id, title, slug, category, venue, start_datetime, image FROM events WHERE show_on_website = 1 AND status = 'published' AND start_datetime >= NOW() ORDER BY start_datetime LIMIT 6"),
        'news' => cms_try_all("SELECT id, title, slug, excerpt, thumbnail, published_at FROM blog_posts WHERE status = 'published' ORDER BY published_at DESC LIMIT 6"),
        'notices' => cms_try_all("SELECT id, title, slug, category, publish_date FROM notices WHERE status = 'published' AND show_on_website = 1 ORDER BY is_pinned DESC, publish_date DESC LIMIT 10"),
        'stats' => cms_stat_values(),
        'banners' => db_all("SELECT id, title, highlight, eyebrow, subheading, desktop_image, mobile_image, image_alt, cta_text, cta_url, cta2_text, cta2_url FROM banners
                             WHERE placement = 'home_hero' AND status = 'active' AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW()) ORDER BY sort_order, id"),
        'settings' => ['institute_name' => institute_name(), 'short_name' => setting('institute_short_name', 'GIMT'), 'phone' => setting('phone', '+91 9955446477'),
            'email' => setting('email', 'info@gimt.ac.in'), 'tagline' => setting('tagline', 'Education • Innovation • Opportunity'), 'logo' => logo_url(),
            'header_cta_text' => setting('header_cta_text', 'Apply Now'), 'admissions_open_text' => setting('admissions_open_text', 'Admissions Open for 2026-27')],
        'topbar' => array_column(db_all("SELECT title FROM announcements WHERE type = 'topbar' AND status = 'active' AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW()) ORDER BY sort_order, id DESC LIMIT 5"), 'title'),
    ]);
});

/* ------------------------------------------------------------------ Page builder */

route('GET', '/cms/pages/{id:\d+}/builder', function ($p) {
    cms_api_require('cms', 'view');
    $page = cms_page_or_404((int) $p['id']);
    api_ok(['page' => cms_page_payload($page), 'blocks' => cms_normalize_blocks($page['blocks']),
        'can' => ['edit' => can('cms', 'edit'), 'publish' => can('cms', 'publish'), 'seo' => can('seo', 'edit')]]);
});

route('PUT', '/cms/pages/{id:\d+}/builder', function ($p) {
    cms_api_require('cms', 'edit');
    $page = cms_page_or_404((int) $p['id']);
    $in = api_input();
    $publish = !empty($in['publish']);
    if ($publish && !can('cms', 'publish')) {
        api_error('You do not have permission to publish pages. Save as draft and ask a content manager to publish it.', 403);
    }
    $current = $page['updated_at'] ?: $page['created_at'];
    if (!empty($in['updated_at']) && $current && strtotime((string) $in['updated_at']) < strtotime($current) && empty($in['force'])) {
        api_error('This page was changed by someone else after you opened it (' . format_datetime($current) . '). Reload to see their changes, or save again to overwrite them.', 409);
    }
    [$blocks, $errors] = cms_validate_blocks($in['blocks'] ?? []);
    if ($errors) {
        $count = count(array_unique(array_map(fn ($k) => preg_match('/^blocks\.(\d+)/', $k, $m) ? $m[1] : 'x', array_keys($errors))));
        api_error('Please fix ' . count($errors) . ' problem' . (count($errors) === 1 ? '' : 's') . ' in ' . $count . ' block' . ($count === 1 ? '' : 's') . ' before saving.', 422, $errors);
    }
    $data = ['blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_by' => user_id()];
    if ($publish) {
        $data['status'] = 'published';
        if (!$page['published_at']) {
            $data['published_at'] = date('Y-m-d H:i:s');
        }
    }
    db_update('pages', $data, 'id = ?', [$page['id']]);
    // make sure updated_at moves even when only JSON whitespace changed
    db_exec('UPDATE pages SET updated_at = NOW() WHERE id = ?', [$page['id']]);
    log_activity($publish ? 'publish' : 'update', 'cms', $page['id'], ($publish ? 'Published' : 'Updated') . ' page builder blocks of "' . $page['title'] . '" (' . count($blocks) . ' blocks)');
    $fresh = db_row('SELECT * FROM pages WHERE id = ?', [$page['id']]);
    api_ok(['page' => cms_page_payload($fresh), 'blocks' => cms_normalize_blocks($fresh['blocks'])], $publish ? 'Page saved and published.' : 'Page saved.');
});

route('POST', '/cms/pages/{id:\d+}/duplicate', function ($p) {
    cms_api_require('cms', 'create');
    $page = cms_page_or_404((int) $p['id']);
    $title = mb_substr('Copy of ' . $page['title'], 0, 255);
    $slug = cms_unique_page_slug($page['slug'] . '-copy');
    $blocks = cms_normalize_blocks($page['blocks']);
    foreach ($blocks as &$b) {
        $b['id'] = cms_block_id();
    }
    unset($b);
    $id = db_insert('pages', ['title' => $title, 'slug' => $slug, 'template' => $page['template'], 'subtitle' => $page['subtitle'], 'banner_image' => $page['banner_image'],
        'excerpt' => $page['excerpt'], 'content' => $page['content'], 'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'parent_id' => $page['parent_id'], 'sort_order' => (int) $page['sort_order'] + 1, 'is_system' => 0, 'status' => 'draft', 'created_by' => user_id(), 'updated_by' => user_id()]);
    log_activity('create', 'cms', $id, 'Duplicated page "' . $page['title'] . '" as "' . $title . '" (/' . $slug . ')');
    api_ok(['id' => $id, 'slug' => $slug], 'Page duplicated as a draft.', 201);
});

foreach (['publish', 'unpublish'] as $act) {
    route('POST', '/cms/pages/{id:\d+}/' . $act, function ($p) use ($act) {
        cms_api_require('cms', 'publish');
        $page = cms_page_or_404((int) $p['id']);
        if ($act === 'publish') {
            db_exec("UPDATE pages SET status = 'published', published_at = COALESCE(published_at, NOW()), updated_by = ? WHERE id = ?", [user_id(), $page['id']]);
        } else {
            db_exec("UPDATE pages SET status = 'draft', updated_by = ? WHERE id = ?", [user_id(), $page['id']]);
        }
        log_activity($act, 'cms', $page['id'], ($act === 'publish' ? 'Published' : 'Unpublished') . ' page "' . $page['title'] . '"');
        api_ok(['page' => cms_page_payload(db_row('SELECT * FROM pages WHERE id = ?', [$page['id']]))], $act === 'publish' ? '"' . $page['title'] . '" is now live.' : '"' . $page['title'] . '" moved to draft.');
    });
}

/* ------------------------------------------------------------------ Home page sections */

function cms_section_payload(string $key, ?array $row, array $default): array
{
    $def = cms_section_types()[$key];
    return [
        'key' => $key, 'id' => $row ? (int) $row['id'] : null, 'exists' => (bool) $row, 'label' => $def['label'], 'icon' => $def['icon'], 'description' => $def['description'],
        'title' => $row ? $row['title'] : $default['title'], 'subtitle' => $row ? $row['subtitle'] : $default['subtitle'],
        'content' => $row ? (json_decode((string) $row['content'], true) ?: []) : $default['content'],
        'is_enabled' => $row ? (bool) $row['is_enabled'] : false, 'sort_order' => $row ? (int) $row['sort_order'] : 100 + $default['sort_order'],
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function cms_sections_list(): array
{
    $rows = [];
    foreach (db_all('SELECT * FROM homepage_sections') as $r) {
        $rows[$r['section_key']] = $r;
    }
    $defaults = cms_default_sections();
    $out = [];
    foreach (cms_section_types() as $key => $def) {
        $out[] = cms_section_payload($key, $rows[$key] ?? null, $defaults[$key]);
    }
    usort($out, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);
    return $out;
}

function cms_section_key_or_404(string $key): void
{
    if (!isset(cms_section_types()[$key])) {
        api_error('Unknown home page section.', 404);
    }
}

route('GET', '/cms/homepage', function () {
    cms_api_require('cms', 'view');
    api_ok(['sections' => cms_sections_list(), 'can' => ['edit' => can('cms', 'edit')], 'site_url' => absolute_url('')]);
});

route('PUT', '/cms/homepage/{key}', function ($p) {
    cms_api_require('cms', 'edit');
    $key = $p['key'];
    cms_section_key_or_404($key);
    $in = api_input();
    [$clean, $errors] = cms_validate_section($key, $in);
    if ($errors) {
        api_error('Please fix the highlighted fields and try again.', 422, $errors);
    }
    $row = db_row('SELECT * FROM homepage_sections WHERE section_key = ?', [$key]);
    $data = ['title' => $clean['title'], 'subtitle' => $clean['subtitle'], 'content' => json_encode($clean['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    if (array_key_exists('is_enabled', $in)) {
        $data['is_enabled'] = in_array($in['is_enabled'], [true, 1, '1', 'true'], true) ? 1 : 0;
    }
    if ($row) {
        db_update('homepage_sections', $data, 'id = ?', [$row['id']]);
    } else {
        $data += ['section_key' => $key, 'is_enabled' => 1, 'sort_order' => (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM homepage_sections')];
        db_insert('homepage_sections', $data);
    }
    db_exec('UPDATE homepage_sections SET updated_at = NOW() WHERE section_key = ?', [$key]);
    $label = cms_section_types()[$key]['label'];
    log_activity('update', 'cms', $key, 'Updated home page section "' . $label . '"');
    $fresh = db_row('SELECT * FROM homepage_sections WHERE section_key = ?', [$key]);
    api_ok(['section' => cms_section_payload($key, $fresh, cms_default_sections()[$key])], $label . ' section saved.');
});

route('POST', '/cms/homepage/reorder', function () {
    cms_api_require('cms', 'edit');
    $keys = array_values(array_filter((array) (api_input()['keys'] ?? []), fn ($k) => is_string($k) && isset(cms_section_types()[$k])));
    if (count($keys) !== count(array_unique($keys)) || !$keys) {
        api_error('Send every section key once, in the new order.', 422);
    }
    db_transaction(function () use ($keys) {
        foreach ($keys as $i => $k) {
            db_exec('UPDATE homepage_sections SET sort_order = ? WHERE section_key = ?', [$i + 1, $k]);
        }
    });
    log_activity('update', 'cms', null, 'Reordered home page sections: ' . implode(', ', array_slice($keys, 0, 14)));
    api_ok(['sections' => cms_sections_list()], 'Home page order saved.');
});

route('POST', '/cms/homepage/{key}/toggle', function ($p) {
    cms_api_require('cms', 'edit');
    $key = $p['key'];
    cms_section_key_or_404($key);
    $enabled = in_array(api_input()['enabled'] ?? null, [true, 1, '1', 'true'], true) ? 1 : 0;
    $row = db_row('SELECT id FROM homepage_sections WHERE section_key = ?', [$key]);
    if (!$row) {
        $d = cms_default_sections()[$key];
        db_insert('homepage_sections', ['section_key' => $key, 'title' => $d['title'], 'subtitle' => $d['subtitle'], 'content' => json_encode($d['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'is_enabled' => $enabled, 'sort_order' => $d['sort_order']]);
    } else {
        db_exec('UPDATE homepage_sections SET is_enabled = ? WHERE id = ?', [$enabled, $row['id']]);
    }
    $label = cms_section_types()[$key]['label'];
    log_activity('update', 'cms', $key, ($enabled ? 'Enabled' : 'Disabled') . ' home page section "' . $label . '"');
    api_ok(['enabled' => (bool) $enabled], $label . ($enabled ? ' is now shown on the home page.' : ' is hidden from the home page.'));
});

route('POST', '/cms/homepage/{key}/reset', function ($p) {
    cms_api_require('cms', 'edit');
    $key = $p['key'];
    cms_section_key_or_404($key);
    $d = cms_default_sections()[$key];
    $data = ['title' => $d['title'], 'subtitle' => $d['subtitle'], 'content' => json_encode($d['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    if (db_value('SELECT id FROM homepage_sections WHERE section_key = ?', [$key])) {
        db_update('homepage_sections', $data, 'section_key = ?', [$key]);
        db_exec('UPDATE homepage_sections SET updated_at = NOW() WHERE section_key = ?', [$key]);
    } else {
        db_insert('homepage_sections', $data + ['section_key' => $key, 'is_enabled' => 1, 'sort_order' => $d['sort_order']]);
    }
    $label = cms_section_types()[$key]['label'];
    log_activity('update', 'cms', $key, 'Reset home page section "' . $label . '" to the default content');
    api_ok(['section' => cms_section_payload($key, db_row('SELECT * FROM homepage_sections WHERE section_key = ?', [$key]), $d)], $label . ' restored to the default content.');
});

/* ------------------------------------------------------------------ Reordering banners / announcements */

foreach (['banners' => 'banner', 'announcements' => 'announcement'] as $table => $singular) {
    route('POST', '/cms/' . $table . '/reorder', function () use ($table, $singular) {
        cms_api_require('cms', 'edit');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) (api_input()['ids'] ?? [])))));
        if (!$ids) {
            api_error('Nothing to reorder.', 422);
        }
        $found = array_map('intval', db_column('SELECT id FROM ' . $table . ' WHERE id IN (' . implode(',', $ids) . ')'));
        if (count($found) !== count($ids)) {
            api_error('Some items no longer exist. Refresh the list and try again.', 409);
        }
        db_transaction(function () use ($ids, $table) {
            foreach ($ids as $i => $id) {
                db_exec('UPDATE ' . $table . ' SET sort_order = ? WHERE id = ?', [$i + 1, $id]);
            }
        });
        log_activity('update', 'cms', implode(',', array_slice($ids, 0, 20)), 'Reordered ' . count($ids) . ' ' . $singular . 's');
        api_ok(['ids' => $ids], 'New order saved.');
    });
}

/* ------------------------------------------------------------------ Media picker + uploads */

route('GET', '/cms/media', function () {
    if (!can('cms', 'view') && !can('media', 'view')) {
        api_error('You do not have permission to browse media.', 403);
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    if (($_GET['source'] ?? 'library') === 'stock') {
        $items = cms_stock_images();
        if ($q !== '') {
            $items = array_values(array_filter($items, fn ($i) => stripos($i['title'] . ' ' . $i['path'], $q) !== false));
        }
        api_ok(['rows' => $items, 'total' => count($items), 'page' => 1, 'pages' => 1]);
    }
    [$page, $perPage] = api_pagination(24);
    $where = "type = 'image'";
    $args = [];
    if ($q !== '') {
        $where .= ' AND (title LIKE ? OR original_name LIKE ? OR alt_text LIKE ? OR folder LIKE ?)';
        array_push($args, "%$q%", "%$q%", "%$q%", "%$q%");
    }
    $total = (int) cms_try_value("SELECT COUNT(*) FROM media WHERE $where", $args);
    $p = paginate($total, $page, $perPage);
    $rows = cms_try_all("SELECT id, path, COALESCE(title, original_name, filename) AS title, alt_text, width, height, size_bytes AS size, folder, created_at FROM media WHERE $where
                         ORDER BY created_at DESC, id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $args);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['source'] = 'library';
    }
    unset($r);
    api_ok(['rows' => $rows, 'total' => $p['total'], 'page' => $p['page'], 'pages' => $p['pages']]);
});

route('POST', '/cms/upload', function () {
    if (!can('cms', 'edit') && !can('cms', 'create') && !can('media', 'create')) {
        api_error('You do not have permission to upload images.', 403);
    }
    if (!rate_limit('cms-upload:' . user_id(), 120, 3600)) {
        api_error('Too many uploads in a short time. Please wait a few minutes.', 429);
    }
    if (empty($_FILES['file'])) {
        api_error('Choose an image to upload.', 422, ['file' => 'Choose an image to upload.']);
    }
    $res = upload_file($_FILES['file'], 'image', 'cms');
    if (!$res['ok']) {
        api_error($res['error'], 422, ['file' => $res['error']]);
    }
    $alt = trim((string) api_param('alt', ''));
    $id = 0;
    try {
        $id = cms_register_media($res, 'page-builder', $alt !== '' ? $alt : null);
    } catch (Throwable $e) {
        log_system('warning', 'CMS upload could not be added to the media library: ' . $e->getMessage());
    }
    log_activity('create', 'cms', $id ?: null, 'Uploaded image ' . ($res['original_name'] ?? basename($res['path'])) . ' (' . human_filesize((int) $res['size']) . ')');
    api_ok(['id' => $id, 'path' => $res['path'], 'title' => $res['original_name'], 'width' => $res['width'], 'height' => $res['height'], 'size' => $res['size'], 'source' => 'library'], 'Image uploaded.', 201);
});
