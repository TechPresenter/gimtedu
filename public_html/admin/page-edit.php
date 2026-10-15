<?php
/**
 * Edit a page: tab 1 = page settings (title, address, banner, SEO, publishing), tab 2 = content sections.
 * Section fields are generated from section_schema_<type>() (see includes/cms.php and includes/site.php).
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('pages.manage');

$id = (int) ($_GET['id'] ?? 0);
$page = $id ? db_row('SELECT * FROM pages WHERE id = ?', [$id]) : null;
if (!$page) {
    flash('error', 'Page not found.');
    admin_redirect('pages.php');
}
$tab = ($_GET['tab'] ?? 'settings') === 'sections' ? 'sections' : 'settings';
$self = 'page-edit.php?id=' . $id;
$isSystem = (int) $page['is_system'] === 1;
$pageLabel = '"' . $page['title'] . '" (/' . ($page['slug'] === 'home' ? '' : $page['slug'] . '/') . ')';

// ---------------------------------------------------------------------
// Image upload from the rich-text editor toolbar (JSON)
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_GET['upload'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_valid()) {
        echo json_encode(['error' => 'Your session expired. Please reload the page.']);
        exit;
    }
    $before = count($_SESSION['_flash'] ?? []);
    $path = admin_upload_image('image', 'pages');
    $message = 'The image could not be uploaded.';
    if (count($_SESSION['_flash'] ?? []) > $before) {
        $last = array_pop($_SESSION['_flash']);
        $message = $last['message'] ?? $message;
    }
    if ($path) {
        admin_log('media_uploaded', 'Image uploaded from the editor of page #' . $id . ': ' . $path);
    }
    echo json_encode($path ? ['url' => url($path), 'path' => $path] : ['error' => $message]);
    exit;
}

// ---------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    admin_post_only($self . '&tab=' . ($action === 'save_page' ? 'settings' : 'sections'));
    $sectionsUrl = $self . '&tab=sections';

    if ($action === 'save_page') {
        $back = 'admin/' . $self;
        [$data, $errors] = validate($_POST, [
            'title' => 'required|max:200', 'banner_eyebrow' => 'max:80', 'banner_subtitle' => 'max:300',
            'meta_title' => 'max:200', 'meta_description' => 'text|max:300', 'canonical_url' => 'url|max:255',
            'status' => 'required|in:draft,published,archived', 'sort_order' => 'integer', 'published_at' => 'datetime',
            'parent_id' => 'integer',
        ], ['meta_title' => 'Search title', 'meta_description' => 'Search description', 'canonical_url' => 'Canonical URL']);

        // Address (slug)
        $slug = $page['slug'];
        if (!$isSystem) {
            $slug = strtolower(trim(clean_string($_POST['slug'] ?? ''), '/ '));
            if (($p = site_slug_problem($slug, $id)) !== null) {
                $errors['slug'] = $p;
            }
        }
        // Template (locked for system pages)
        $template = $page['template'];
        if (!$isSystem) {
            $t = (string) ($_POST['template'] ?? $template);
            if (in_array($t, ['default', 'policy', $page['template']], true)) {
                $template = $t;
            }
        }
        // Parent (not itself or one of its own sub-pages)
        $parentId = (int) ($data['parent_id'] ?? 0) ?: null;
        if ($parentId) {
            $guard = 0;
            $walk = $parentId;
            while ($walk && $guard++ < 20) {
                if ($walk === $id) {
                    $errors['parent_id'] = 'A page cannot be placed under itself or one of its own sub-pages.';
                    break;
                }
                $walk = (int) db_value('SELECT parent_id FROM pages WHERE id = ?', [$walk]);
            }
            if (!db_value('SELECT COUNT(*) FROM pages WHERE id = ?', [$parentId])) {
                $parentId = null;
            }
        }
        // Images & buttons
        [$banner, $err] = site_parse_value(['image', 'Banner image'], $_POST['banner_image'] ?? '', site_file_at('banner_image_upload', []));
        if ($err) {
            $errors['banner_image'] = 'Banner image: ' . $err;
        }
        [$ogImage, $err] = site_parse_value(['image', 'Social sharing image'], $_POST['og_image'] ?? '', site_file_at('og_image_upload', []));
        if ($err) {
            $errors['og_image'] = 'Social sharing image: ' . $err;
        }
        [$buttons, $err] = site_parse_value(['buttons', 'Banner buttons'], $_POST['banner_buttons'] ?? []);
        if ($err) {
            $errors['banner_buttons'] = 'Banner buttons: ' . $err;
        }
        // Template settings (JSON object)
        $pageSettings = trim((string) ($_POST['page_settings'] ?? ''));
        if ($pageSettings !== '') {
            $decoded = json_decode($pageSettings, true);
            if (!is_array($decoded) || ($decoded && array_keys($decoded) === range(0, count($decoded) - 1))) {
                $errors['page_settings'] = 'Template settings must be a JSON object, e.g. {"category":"certification"}.';
            } else {
                $pageSettings = json_encode(site_clean_unknown($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
        if ($errors) {
            back_with_errors($errors, $back, $_POST);
        }

        $publishedAt = $data['published_at'] !== '' ? $data['published_at'] : ($page['published_at'] ?: ($data['status'] === 'published' ? date('Y-m-d H:i:s') : null));
        db_update('pages', [
            'title' => $data['title'], 'slug' => $slug, 'template' => $template, 'parent_id' => $parentId,
            'banner_eyebrow' => $data['banner_eyebrow'] ?: null, 'banner_subtitle' => $data['banner_subtitle'] ?: null,
            'banner_image' => $banner, 'banner_buttons' => $buttons ? json_encode($buttons, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'meta_title' => $data['meta_title'] ?: null, 'meta_description' => $data['meta_description'] !== '' ? preg_replace('/\s+/', ' ', $data['meta_description']) : null,
            'canonical_url' => $data['canonical_url'] ?: null, 'og_image' => $ogImage,
            'settings' => $pageSettings !== '' ? $pageSettings : null,
            'status' => $data['status'], 'noindex' => !empty($_POST['noindex']) ? 1 : 0, 'show_in_sitemap' => !empty($_POST['show_in_sitemap']) ? 1 : 0,
            'sort_order' => (int) ($data['sort_order'] ?: 0), 'published_at' => $publishedAt, 'updated_by' => admin_id(),
        ], 'id = :id', ['id' => $id]);
        site_touch_page($id);

        $notes = [];
        if ($slug !== $page['slug']) {
            $newUrl = '/' . $slug . '/';
            $oldUrl = '/' . $page['slug'] . '/';
            // The new address must not be captured by an old redirect, and the old address now forwards to the new one.
            db_query('DELETE FROM redirects WHERE source_path = ?', [$slug]);
            db_query('INSERT INTO redirects (source_path, target_url, status_code) VALUES (?, ?, 301)
                      ON DUPLICATE KEY UPDATE target_url = VALUES(target_url), status_code = 301', [$page['slug'], $newUrl]);
            db_query('UPDATE redirects SET target_url = ? WHERE target_url = ?', [$newUrl, $oldUrl]);
            $menus = db_query('UPDATE menu_items SET url = ? WHERE url = ?', [$newUrl, $oldUrl])->rowCount();
            $notes[] = 'The old address ' . $oldUrl . ' now redirects (301) to ' . $newUrl . ($menus ? ' and ' . $menus . ' menu link(s) were updated' : '') . '.';
            admin_log('redirect_added', 'Automatic 301 redirect /' . $page['slug'] . '/ -> ' . $newUrl . ' after a page address change');
        }
        admin_log('page_updated', 'Page #' . $id . ' "' . $data['title'] . '" settings saved (status ' . $data['status'] . ')');
        flash('success', 'Page settings saved. ' . implode(' ', $notes));
        admin_redirect($self);
    }

    // ----- Section actions -----
    $sectionId = (int) ($_POST['section_id'] ?? 0);
    $section = $sectionId ? db_row('SELECT * FROM page_sections WHERE id = ? AND page_id = ?', [$sectionId, $id]) : null;

    if ($action === 'add_section') {
        $type = preg_replace('/[^a-z0-9_]/', '', (string) ($_POST['section_type'] ?? ''));
        $types = section_types();
        if (!isset($types[$type])) {
            flash('error', 'Please choose a section type.');
            admin_redirect($sectionsUrl);
        }
        $atTop = ($_POST['position'] ?? 'end') === 'top';
        site_renumber_sections($id);
        if ($atTop) {
            db_query('UPDATE page_sections SET sort_order = sort_order + 1 WHERE page_id = ?', [$id]);
        }
        $order = $atTop ? 1 : ((int) db_value('SELECT COALESCE(MAX(sort_order), 0) FROM page_sections WHERE page_id = ?', [$id]) + 1);
        $newId = db_insert('page_sections', [
            'page_id' => $id, 'section_type' => $type, 'title' => null, 'background' => 'white', 'status' => 'hidden',
            'sort_order' => $order, 'updated_by' => admin_id(),
        ]);
        site_touch_page($id);
        admin_log('section_added', 'Page #' . $id . ' ' . $pageLabel . ': added section #' . $newId . ' (' . $type . ')');
        flash('success', 'Section "' . $types[$type] . '" added. It stays hidden until you fill it in and set it to "Visible".');
        admin_redirect($sectionsUrl . '&open=' . $newId . '#section-' . $newId);
    }

    if (!$section) {
        flash('error', 'Section not found.');
        admin_redirect($sectionsUrl);
    }
    $sLabel = 'section #' . $sectionId . ' (' . $section['section_type'] . ($section['title'] ? ': ' . mb_substr($section['title'], 0, 60) : '') . ')';

    if ($action === 'save_section') {
        [$cols, $errors] = site_parse_section_post($section, $_POST);
        if ($errors) {
            $draft = array_merge($section, $cols);
            $draft['items'] = json_field($cols['items']);
            $draft['settings'] = json_field($cols['settings']);
            $_SESSION['site_section_draft'] = ['id' => $sectionId, 'row' => $draft, 'errors' => $errors];
            flash('error', 'The section was not saved. Please correct the problems shown in the section.');
            admin_redirect($sectionsUrl . '&open=' . $sectionId . '#section-' . $sectionId);
        }
        $cols['updated_by'] = admin_id();
        db_update('page_sections', $cols, 'id = :id', ['id' => $sectionId]);
        site_touch_page($id);
        admin_log('section_updated', 'Page #' . $id . ' ' . $pageLabel . ': ' . $sLabel . ' saved (' . $cols['status'] . ')');
        flash('success', 'Section saved.' . ($cols['status'] === 'hidden' ? ' It is hidden - set Visibility to "Visible" to show it on the website.' : ''));
        admin_redirect($sectionsUrl . '&open=' . $sectionId . '#section-' . $sectionId);
    }

    if ($action === 'move_section') {
        site_renumber_sections($id);
        $section = db_row('SELECT * FROM page_sections WHERE id = ?', [$sectionId]);
        $up = ($_POST['dir'] ?? '') === 'up';
        $other = db_row('SELECT id, sort_order FROM page_sections WHERE page_id = ? AND sort_order ' . ($up ? '<' : '>') . ' ? ORDER BY sort_order ' . ($up ? 'DESC' : 'ASC') . ' LIMIT 1',
            [$id, $section['sort_order']]);
        if ($other) {
            db_query('UPDATE page_sections SET sort_order = ? WHERE id = ?', [$other['sort_order'], $sectionId]);
            db_query('UPDATE page_sections SET sort_order = ? WHERE id = ?', [$section['sort_order'], $other['id']]);
            site_touch_page($id);
            admin_log('section_moved', 'Page #' . $id . ' ' . $pageLabel . ': ' . $sLabel . ' moved ' . ($up ? 'up' : 'down'));
        }
        admin_redirect($sectionsUrl . '#section-' . $sectionId);
    }

    if ($action === 'toggle_section') {
        $new = $section['status'] === 'visible' ? 'hidden' : 'visible';
        db_update('page_sections', ['status' => $new, 'updated_by' => admin_id()], 'id = :id', ['id' => $sectionId]);
        site_touch_page($id);
        admin_log('section_' . $new, 'Page #' . $id . ' ' . $pageLabel . ': ' . $sLabel . ' is now ' . $new);
        flash('success', $new === 'visible' ? 'Section is now visible on the website.' : 'Section hidden from the website.');
        admin_redirect($sectionsUrl . '#section-' . $sectionId);
    }

    if ($action === 'duplicate_section') {
        site_renumber_sections($id);
        $section = db_row('SELECT * FROM page_sections WHERE id = ?', [$sectionId]);
        db_query('UPDATE page_sections SET sort_order = sort_order + 1 WHERE page_id = ? AND sort_order > ?', [$id, $section['sort_order']]);
        $copy = $section;
        unset($copy['id'], $copy['created_at'], $copy['updated_at']);
        $copy['sort_order'] = (int) $section['sort_order'] + 1;
        $copy['status'] = 'hidden';
        $copy['anchor'] = null;
        $copy['updated_by'] = admin_id();
        $newId = db_insert('page_sections', $copy);
        site_touch_page($id);
        admin_log('section_duplicated', 'Page #' . $id . ' ' . $pageLabel . ': ' . $sLabel . ' copied to section #' . $newId);
        flash('success', 'Section copied. The copy is hidden until you set it to "Visible".');
        admin_redirect($sectionsUrl . '&open=' . $newId . '#section-' . $newId);
    }

    if ($action === 'delete_section') {
        db_query('DELETE FROM page_sections WHERE id = ? AND page_id = ?', [$sectionId, $id]);
        site_renumber_sections($id);
        site_touch_page($id);
        admin_log('section_deleted', 'Page #' . $id . ' ' . $pageLabel . ': deleted ' . $sLabel);
        flash('success', 'Section deleted.');
        admin_redirect($sectionsUrl);
    }

    admin_redirect($self);
}

// ---------------------------------------------------------------------
// View
// ---------------------------------------------------------------------
$page = db_row('SELECT * FROM pages WHERE id = ?', [$id]);
$viewUrl = page_url($page['slug']);
$previewUrl = $viewUrl . '?preview=1';
$sections = db_rows('SELECT * FROM page_sections WHERE page_id = ? ORDER BY sort_order, id', [$id]);
$draft = $_SESSION['site_section_draft'] ?? null;
unset($_SESSION['site_section_draft']);
$openId = (int) ($_GET['open'] ?? 0);

$actions = [['label' => 'All pages', 'url' => admin_url('pages.php'), 'icon' => 'chevron-left', 'style' => 'ghost']];
$linkless = in_array($page['template'], LINKLESS_TEMPLATES, true);   // opens only from a private donation link
if ($linkless) {
    // no View / Preview button: the bare address shows "not found"
} elseif ($page['status'] === 'published') {
    // a published page looks exactly like its preview, so link the real address (no duplicate ?preview URL)
    $actions[] = ['label' => 'View page', 'url' => $viewUrl, 'icon' => 'external', 'style' => 'primary'];
} else {
    $actions[] = ['label' => 'Preview (staff only)', 'url' => $previewUrl, 'icon' => 'eye', 'style' => 'primary'];
}
admin_page_start('Edit: ' . $page['title'], $actions);

$editor = site_user_name($page['updated_by'] ? (int) $page['updated_by'] : null);
?>
<div class="site-admin">
<div class="site-page-meta">
    <span><?= status_badge($page['status']) ?></span>
    <?php if ($linkless): ?>
        <span><?= icon('link') ?><?= e(abs_page_url($page['slug'])) ?> <small class="muted">(opens only from a donation link)</small></span>
    <?php else: ?>
        <span><?= icon('link') ?><a href="<?= e($page['status'] === 'published' ? $viewUrl : $previewUrl) ?>" target="_blank" rel="noopener"><?= e(abs_page_url($page['slug'])) ?></a></span>
    <?php endif; ?>
    <span><?= icon('layers') ?><?= e(TEMPLATE_LABELS[$page['template']] ?? humanize($page['template'])) ?><?= $isSystem ? ' · built-in page' : '' ?></span>
    <span><?= icon('clock') ?>Last updated <?= e(format_date($page['updated_at'] ?: $page['created_at'], 'd M Y, h:i A')) ?><?= $editor !== '' ? ' by ' . e($editor) : '' ?></span>
</div>

<nav class="admin-tabs" aria-label="Page editor">
    <a href="<?= e(admin_url($self . '&tab=settings')) ?>" class="<?= $tab === 'settings' ? 'active' : '' ?>"<?= $tab === 'settings' ? ' aria-current="page"' : '' ?>><?= icon('settings') ?> Page settings</a>
    <a href="<?= e(admin_url($self . '&tab=sections')) ?>" class="<?= $tab === 'sections' ? 'active' : '' ?>"<?= $tab === 'sections' ? ' aria-current="page"' : '' ?>><?= icon('layers') ?> Sections (<?= count($sections) ?>)</a>
</nav>

<?php if ($tab === 'settings'):
    $allPages = db_rows('SELECT id, parent_id, title, slug FROM pages ORDER BY title');
    $children = [];
    foreach ($allPages as $ap) {
        $children[(int) $ap['parent_id']][] = (int) $ap['id'];
    }
    $exclude = [$id];
    $stack = [$id];
    while ($stack) {
        foreach ($children[array_pop($stack)] ?? [] as $c) {
            $exclude[] = $c;
            $stack[] = $c;
        }
    }
    $buttons = old('banner_buttons', null);
    $buttons = is_array($buttons) ? array_values(array_filter($buttons, 'is_array')) : json_field($page['banner_buttons']);
    $pubAt = old('published_at', $page['published_at'] ? str_replace(' ', 'T', substr($page['published_at'], 0, 16)) : '');
    $tplOptions = $isSystem ? [$page['template'] => TEMPLATE_LABELS[$page['template']] ?? $page['template']]
        : ['default' => TEMPLATE_LABELS['default'], 'policy' => TEMPLATE_LABELS['policy']] + (in_array($page['template'], ['default', 'policy'], true) ? [] : [$page['template'] => TEMPLATE_LABELS[$page['template']] ?? $page['template']]);
?>
<form method="post" action="<?= e(admin_url($self)) ?>" enctype="multipart/form-data" class="site-page-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_page">
    <div class="admin-grid-main">
        <div>
            <section class="panel">
                <div class="panel-head"><h2>Page</h2></div>
                <div class="form-grid">
                    <div class="form-group full"><label for="title">Page title (main heading)<span class="req">*</span></label>
                        <input id="title" name="title" required maxlength="200" value="<?= e(old('title', $page['title'])) ?>"><?= field_error('title') ?></div>
                    <div class="form-group full"><label for="slug">Address (slug)</label>
                        <?php if ($isSystem): ?>
                            <input id="slug" value="<?= e($page['slug']) ?>" disabled>
                            <span class="help">Built-in pages keep their address because the website's features link to it.</span>
                        <?php else: ?>
                            <div class="site-slug"><span><?= e(rtrim(abs_url(''), '/')) ?>/</span><input id="slug" name="slug" required maxlength="190" value="<?= e(old('slug', $page['slug'])) ?>"><span>/</span></div>
                            <span class="help">Lower-case letters, numbers and hyphens. If you change it, the old address automatically redirects (301) to the new one.</span><?= field_error('slug') ?>
                        <?php endif; ?>
                    </div>
                    <div class="form-group"><label for="banner_eyebrow">Banner label (small text above the title)</label>
                        <input id="banner_eyebrow" name="banner_eyebrow" maxlength="80" value="<?= e(old('banner_eyebrow', (string) $page['banner_eyebrow'])) ?>"><?= field_error('banner_eyebrow') ?></div>
                    <div class="form-group"><label for="banner_subtitle">Banner subtitle</label>
                        <input id="banner_subtitle" name="banner_subtitle" maxlength="300" value="<?= e(old('banner_subtitle', (string) $page['banner_subtitle'])) ?>"><?= field_error('banner_subtitle') ?></div>
                    <?= site_field_html('banner_image', 'banner_image', ['image', 'Banner image / illustration', 'Shown on the right of the page banner. Illustrations (SVG) or photos about 960 × 720 px.'], (string) old('banner_image', (string) $page['banner_image']), 'banner_image_upload') ?>
                    <?= field_error('banner_image') ?>
                    <div class="form-group full"><span class="label">Banner buttons (optional)</span>
                        <?= site_repeater_html('banner_buttons', 'banner-buttons', site_button_fields(), $buttons, '', 'Button', $buttons ? 0 : 1) ?>
                        <?= field_error('banner_buttons') ?></div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h2>Search engines &amp; sharing (SEO)</h2></div>
                <div class="form-grid">
                    <div class="form-group full"><label for="meta_title">Search title</label>
                        <input id="meta_title" name="meta_title" maxlength="200" data-counter="60" value="<?= e(old('meta_title', (string) $page['meta_title'])) ?>" placeholder="<?= e($page['title']) ?>">
                        <span class="help">Leave blank to use the page title. Aim for 50-60 characters; "<?= e(trim(setting('seo_title_suffix'))) ?>" is added automatically when it fits.</span><?= field_error('meta_title') ?></div>
                    <div class="form-group full"><label for="meta_description">Search description</label>
                        <textarea id="meta_description" name="meta_description" rows="3" maxlength="300" data-counter="160"><?= e(old('meta_description', (string) $page['meta_description'])) ?></textarea>
                        <span class="help">One or two sentences (120-160 characters) describing the page. Shown in Google results and when shared.</span><?= field_error('meta_description') ?></div>
                    <div class="form-group full"><label for="canonical_url">Canonical URL (advanced)</label>
                        <input id="canonical_url" name="canonical_url" type="url" maxlength="255" placeholder="<?= e(abs_page_url($page['slug'])) ?>" value="<?= e(old('canonical_url', (string) $page['canonical_url'])) ?>">
                        <span class="help">Leave blank - the page's own address is used.</span><?= field_error('canonical_url') ?></div>
                    <?= site_field_html('og_image', 'og_image', ['image', 'Social sharing image (optional)', '1200 × 630 px. Leave blank to use the banner image or the default sharing image.'], (string) old('og_image', (string) $page['og_image']), 'og_image_upload') ?>
                    <?= field_error('og_image') ?>
                    <div class="form-group site-check"><input type="hidden" name="noindex" value="0"><label class="checkbox" for="noindex"><input type="checkbox" id="noindex" name="noindex" value="1"<?= old('noindex', (string) $page['noindex']) === '1' ? ' checked' : '' ?>><span>Hide this page from search engines (noindex)</span></label></div>
                    <div class="form-group site-check"><input type="hidden" name="show_in_sitemap" value="0"><label class="checkbox" for="show_in_sitemap"><input type="checkbox" id="show_in_sitemap" name="show_in_sitemap" value="1"<?= old('show_in_sitemap', (string) $page['show_in_sitemap']) === '1' ? ' checked' : '' ?>><span>List in the sitemap (sitemap.xml and the HTML sitemap)</span></label></div>
                </div>
            </section>

            <details class="panel site-advanced"<?= field_error('page_settings') !== '' ? ' open' : '' ?>>
                <summary><?= icon('settings') ?> Template settings (advanced)</summary>
                <div class="form-group mt-2"><label for="page_settings">Settings for the page template (JSON)</label>
                    <textarea id="page_settings" name="page_settings" class="code-area" rows="4" spellcheck="false"><?= e(old('page_settings', $page['settings'] ? json_encode(json_field($page['settings']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '')) ?></textarea>
                    <span class="help">Some built-in pages read options from here, e.g. {"category":"certification"} for a documents page. Leave unchanged if unsure.</span><?= field_error('page_settings') ?></div>
            </details>
        </div>

        <aside>
            <section class="panel">
                <div class="panel-head"><h2>Publishing</h2></div>
                <div class="form-group mb-2"><label for="status">Status</label>
                    <select id="status" name="status"><?= options_html(['published' => 'Published (visible)', 'draft' => 'Draft (only staff can preview)', 'archived' => 'Archived (hidden)'], (string) old('status', $page['status']), true, '') ?></select>
                    <?php if ($page['slug'] === 'home'): ?><span class="help">This is the homepage.</span><?php endif; ?></div>
                <div class="form-group mb-2"><label for="published_at">Published date</label>
                    <input type="datetime-local" id="published_at" name="published_at" value="<?= e($pubAt) ?>"><?= field_error('published_at') ?></div>
                <div class="form-group mb-2"><label for="parent_id">Parent page (breadcrumbs)</label>
                    <select id="parent_id" name="parent_id"><option value="">- None (top level) -</option>
                        <?php foreach ($allPages as $ap): if (in_array((int) $ap['id'], $exclude, true)) { continue; } ?>
                            <option value="<?= (int) $ap['id'] ?>"<?= (string) old('parent_id', (string) $page['parent_id']) === (string) $ap['id'] ? ' selected' : '' ?>><?= e($ap['title'] . ' (/' . $ap['slug'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select><?= field_error('parent_id') ?></div>
                <div class="form-group mb-2"><label for="template">Template</label>
                    <select id="template" name="template"<?= $isSystem ? ' disabled' : '' ?>><?= options_html($tplOptions, (string) old('template', $page['template']), true, '') ?></select>
                    <?php if ($isSystem): ?><span class="help">Locked: this built-in page provides a website function.</span><?php else: ?><span class="help">"Policy" joins all text sections into one document with a contents list.</span><?php endif; ?></div>
                <div class="form-group mb-2"><label for="sort_order">Sort order</label>
                    <input type="number" id="sort_order" name="sort_order" step="1" value="<?= e((string) old('sort_order', (string) $page['sort_order'])) ?>"><span class="help">Lower numbers come first in the sitemap.</span></div>
                <button class="btn btn-primary btn-block" type="submit"><?= icon('check') ?>Save page settings</button>
                <p class="small muted mt-2 mb-0">Created <?= e(format_date($page['created_at'], 'd M Y')) ?><?= $page['created_by'] ? ' by ' . e(site_user_name((int) $page['created_by'])) : '' ?>.</p>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Content</h2></div>
                <p class="small muted">The page content is made of sections (text, cards, steps, questions, lists…).</p>
                <a class="btn btn-outline btn-block" href="<?= e(admin_url($self . '&tab=sections')) ?>"><?= icon('layers') ?>Edit sections (<?= count($sections) ?>)</a>
            </section>
        </aside>
    </div>
</form>

<?php else: /* ----------------------------- SECTIONS ----------------------------- */
    $types = section_types();
    $hasModule = (bool) array_filter($sections, function ($s) { return $s['section_type'] === 'module'; });
?>
<?php if (!in_array($page['template'], ['default', 'policy'], true)): ?>
    <div class="alert alert-info"><?= icon('info') ?><div>This page has a built-in function (<?= e(TEMPLATE_LABELS[$page['template']] ?? $page['template']) ?>). It appears <?= $hasModule ? 'where the "Page function position" section is' : 'after the sections below' ?>. Add or move a <strong>Page function position</strong> section to choose where it appears.</div></div>
<?php elseif ($page['template'] === 'policy'): ?>
    <div class="alert alert-info"><?= icon('info') ?><div>Policy layout: all <strong>Text (rich text)</strong> sections are joined into one document with a contents list (their headings become chapters). Other sections appear below it.</div></div>
<?php endif; ?>

<section class="panel site-add-section">
    <form method="post" action="<?= e(admin_url($self . '&tab=sections')) ?>" class="filter-row mb-0">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_section">
        <label class="sr-only" for="section_type">Section type</label>
        <select id="section_type" name="section_type" required>
            <option value="">Add a section…</option>
            <?php foreach ($types as $tKey => $tLabel): ?><option value="<?= e($tKey) ?>"><?= e($tLabel) ?></option><?php endforeach; ?>
        </select>
        <label class="sr-only" for="position">Position</label>
        <select id="position" name="position"><option value="end">at the end</option><option value="top">at the top</option></select>
        <button class="btn btn-primary btn-sm" type="submit"><?= icon('plus') ?>Add section</button>
    </form>
</section>

<?php if (!$sections): ?>
    <div class="panel"><?= empty_state('This page has no sections yet. Add the first one above.') ?></div>
<?php endif; ?>

<div class="site-sections">
<?php foreach ($sections as $n => $s):
    $sid = (int) $s['id'];
    $errors = [];
    if ($draft && (int) $draft['id'] === $sid) {
        $s = $draft['row'];
        $errors = $draft['errors'];
    }
    $isOpen = $openId === $sid || $errors;
    $hidden = $s['status'] === 'hidden';
    $exists = function_exists('section_' . preg_replace('/[^a-z0-9_]/', '', (string) $s['section_type']));
?>
    <article class="site-section<?= $hidden ? ' is-hidden' : '' ?>" id="section-<?= $sid ?>">
        <header class="site-section-bar">
            <span class="site-section-num" aria-hidden="true"><?= $n + 1 ?></span>
            <div class="site-section-meta">
                <strong><?= e($s['title'] ?: ($s['section_type'] === 'module' ? 'Page function (form / list)' : '(no heading)')) ?></strong>
                <small><?= e(site_section_summary($s)) ?><?= !$exists ? ' · module not installed' : '' ?></small>
            </div>
            <?= $hidden ? '<span class="badge badge-gray">Hidden</span>' : '<span class="badge badge-green">Visible</span>' ?>
            <div class="site-section-tools">
                <?php foreach ([['move_section', 'up', 'arrow-up', 'Move up', $n === 0], ['move_section', 'down', 'arrow-up', 'Move down', $n === count($sections) - 1]] as [$act, $dir, $ic, $lbl, $disabled]): ?>
                    <form method="post" action="<?= e(admin_url($self . '&tab=sections')) ?>" class="inline-form"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="section_id" value="<?= $sid ?>"><input type="hidden" name="dir" value="<?= $dir ?>">
                        <button class="icon-btn<?= $dir === 'down' ? ' site-down' : '' ?>" type="submit" aria-label="<?= e($lbl) ?>" title="<?= e($lbl) ?>"<?= $disabled ? ' disabled' : '' ?>><?= icon($ic) ?></button></form>
                <?php endforeach; ?>
                <form method="post" action="<?= e(admin_url($self . '&tab=sections')) ?>" class="inline-form"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_section"><input type="hidden" name="section_id" value="<?= $sid ?>">
                    <button class="icon-btn<?= $hidden ? '' : ' site-eye-off' ?>" type="submit" aria-label="<?= $hidden ? 'Show on website' : 'Hide from website' ?>" title="<?= $hidden ? 'Show on website' : 'Hide from website' ?>"><?= icon('eye') ?></button></form>
                <form method="post" action="<?= e(admin_url($self . '&tab=sections')) ?>" class="inline-form"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="duplicate_section"><input type="hidden" name="section_id" value="<?= $sid ?>">
                    <button class="icon-btn" type="submit" aria-label="Duplicate" title="Duplicate"><?= icon('copy') ?></button></form>
                <form method="post" action="<?= e(admin_url($self . '&tab=sections')) ?>" class="inline-form" data-confirm="Delete this section permanently? This cannot be undone."><?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_section"><input type="hidden" name="section_id" value="<?= $sid ?>">
                    <button class="icon-btn danger" type="submit" aria-label="Delete" title="Delete"><?= icon('trash') ?></button></form>
            </div>
        </header>
        <details class="site-section-edit"<?= $isOpen ? ' open' : '' ?>>
            <summary><?= icon('edit') ?> Edit section</summary>
            <?= site_section_form($s, $page, $errors) ?>
        </details>
    </article>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<?= site_page_datalist() ?>
<?= site_media_picker() ?>
<?= site_icon_sprite() ?>
<script src="<?= e(asset('assets/js/modules/admin-site.js')) ?>" defer></script>
<?php
admin_page_end();
