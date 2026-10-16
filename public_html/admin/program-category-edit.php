<?php
/**
 * Create / edit a programme category: card + menu details, landing-page banner, the main texts of the page
 * (about, objectives, topics & activities, who can participate, benefits, FAQs), SEO, event defaults and alerts.
 * The live blocks (upcoming / past programmes, registration, partnership, call to action) and any extra sections
 * are edited in the page's section editor (Admin > Pages > Sections).
 * Changing the address moves the page and adds a 301 redirect from the old address.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('programs.manage');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$cat = $id ? db_row('SELECT * FROM program_categories WHERE id = ?', [$id]) : null;
if ($id && !$cat) {
    flash('error', 'Category not found.');
    admin_redirect('program-categories.php');
}
$page = $cat && $cat['page_id'] ? db_row('SELECT * FROM pages WHERE id = ?', [(int) $cat['page_id']]) : null;
$self = 'program-category-edit.php' . ($cat ? '?id=' . $id : '');
$sections = $page ? programhub_admin_category_sections((int) $page['id']) : [];
$eventTypes = array_diff_key(EVENT_TYPES, ['awareness_day' => 1]);
$cardFields = ['icon' => ['icon', 'Icon'], 'title' => ['text', 'Title'], 'text' => ['textarea', 'Text']];

// ---------------------------------------------------------------------
// Save / delete
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only($self);
    $action = programhub_admin_param($_POST, 'action', 'save');

    if ($action === 'delete' && $cat) {
        $events = (int) db_value('SELECT COUNT(*) FROM events WHERE category_id = ?', [$id]);
        if ($events > 0) {
            flash('error', 'This category still has ' . $events . ' event' . ($events === 1 ? '' : 's') . '. Move them to another category (or hide the category instead).');
            admin_redirect($self);
        }
        $pdo = db();
        $pdo->beginTransaction();
        db_query('DELETE FROM program_categories WHERE id = ?', [$id]);
        if ($page) {
            db_query('DELETE FROM pages WHERE id = ?', [(int) $page['id']]);   // its sections are deleted with it
            if ($page['status'] === 'published') {
                db_query("INSERT INTO redirects (source_path, target_url, status_code) VALUES (?, '/programs/', 301)
                          ON DUPLICATE KEY UPDATE target_url = VALUES(target_url), status_code = 301", [$page['slug']]);
            }
            db_query("UPDATE menu_items SET status = 'hidden' WHERE url IN (?, ?)", ['/' . $page['slug'] . '/', '/' . $page['slug']]);
        }
        $pdo->commit();
        programhub_admin_delete_file($cat['image']);
        program_categories_all(false, true);
        admin_log('program_category_deleted', 'Programme category #' . $id . ' "' . $cat['title'] . '" and its page deleted' . ($page && $page['status'] === 'published' ? ' (old address redirects to /programs/)' : ''));
        flash('success', 'The category "' . $cat['title'] . '" and its page were deleted.');
        admin_redirect('program-categories.php');
    }

    [$data, $errors] = validate($_POST, [
        'title' => 'required|min:3|max:150',
        'slug' => 'max:100',
        'nav_label' => 'max:80',
        'card_text' => 'required|text|min:10|max:300',
        'icon' => 'in:' . implode(',', site_icon_names()),
        'tone' => 'required|in:' . implode(',', array_keys(program_tones())),
        'default_event_type' => 'in:' . implode(',', array_keys($eventTypes)),
        'notify_email' => 'email|max:190',
        'status' => 'required|in:published,draft',
        'sort_order' => 'integer|min:-9999|max:9999',
        'banner_eyebrow' => 'max:80',
        'banner_subtitle' => 'text|max:300',
        'meta_title' => 'max:200',
        'meta_description' => 'text|max:300',
        'about_title' => 'max:150', 'about_intro' => 'text|max:700', 'objectives' => 'text|max:3000',
        'topics_title' => 'max:150', 'topics_intro' => 'text|max:600',
        'who_title' => 'max:150', 'who_intro' => 'text|max:600', 'who' => 'text|max:3000',
        'benefits_title' => 'max:150', 'benefits_intro' => 'text|max:600',
        'faqs_title' => 'max:150',
    ], ['card_text' => 'Card text', 'nav_label' => 'Menu label', 'default_event_type' => 'Default type for new events', 'notify_email' => 'Alert email',
        'banner_subtitle' => 'Banner introduction', 'meta_title' => 'Search title', 'meta_description' => 'Search description']);
    $slug = strtolower($data['slug'] !== '' ? $data['slug'] : slugify($data['title']));
    if (!isset($errors['slug']) && ($problem = programhub_admin_category_slug_problem($slug, $cat))) {
        $errors['slug'] = $problem;
    }
    $icon = $data['icon'] !== '' ? $data['icon'] : 'calendar';
    $aboutBody = clean_html((string) ($_POST['about_body'] ?? ''));
    $topics = programhub_admin_rows($_POST['topics'] ?? [], ['icon' => 40, 'title' => 120, 'text' => 400], 12, ['text']);
    $benefits = programhub_admin_rows($_POST['benefits'] ?? [], ['icon' => 40, 'title' => 120, 'text' => 400], 12, ['text']);
    $faqs = programhub_admin_rows($_POST['faqs'] ?? [], ['q' => 250, 'a' => 1500], 20, ['a']);
    foreach (['topics' => $topics, 'benefits' => $benefits] as $k => $list) {
        foreach ($list as $i => $r) {
            if ($r['title'] === '') {
                $errors[$k] = ucfirst($k) . ': card ' . ($i + 1) . ' needs a title.';
                break;
            }
        }
    }
    foreach ($faqs as $i => $r) {
        if ($r['q'] === '' || $r['a'] === '') {
            $errors['faqs'] = 'FAQ ' . ($i + 1) . ' needs both a question and an answer.';
            break;
        }
    }
    $eeat = [];
    if (function_exists('seo_eeat_validate_post')) {
        [$eeat, $eeatErrors] = seo_eeat_validate_post($_POST);   // reviewer / review date / sources / updated date (SEO module)
        $errors += $eeatErrors;
    }
    if ($errors) {
        back_with_errors($errors, 'admin/' . $self, $_POST);
    }

    // uploads (card image, banner, sharing image)
    $uploadErrors = [];
    [$cardImg, $err] = programhub_admin_upload_image('card_image', PROGRAMHUB_UPLOADS['category'], $data['title']);
    if ($err) {
        $uploadErrors['card_image'] = $err;
    }
    [$bannerImg, $err] = programhub_admin_upload_image('banner_upload', PROGRAMHUB_UPLOADS['category'], $data['title']);
    if ($err) {
        $uploadErrors['banner_upload'] = $err;
    }
    [$ogImg, $err] = programhub_admin_upload_image('og_upload', 'pages', $data['title']);
    if ($err) {
        $uploadErrors['og_upload'] = $err;
    }
    if ($uploadErrors) {
        foreach ([$cardImg, $bannerImg, $ogImg] as $p) {
            programhub_admin_delete_file($p);
        }
        back_with_errors($uploadErrors, 'admin/' . $self, $_POST);
    }

    $catRow = [
        'title' => $data['title'], 'nav_label' => $data['nav_label'] !== '' ? $data['nav_label'] : null, 'card_text' => $data['card_text'],
        'icon' => $icon, 'tone' => $data['tone'], 'default_event_type' => $data['default_event_type'] !== '' ? $data['default_event_type'] : null,
        'interest_enabled' => !empty($_POST['interest_enabled']) ? 1 : 0, 'partnership_enabled' => !empty($_POST['partnership_enabled']) ? 1 : 0,
        'notify_email' => $data['notify_email'] !== '' ? $data['notify_email'] : null, 'show_in_nav' => !empty($_POST['show_in_nav']) ? 1 : 0,
        'sort_order' => (int) ($data['sort_order'] !== '' ? $data['sort_order'] : ($cat['sort_order'] ?? ((int) db_value('SELECT COALESCE(MAX(sort_order), 0) FROM program_categories') + 10))),
    ];
    $notes = [];

    if (!$cat) {
        $newId = programhub_admin_create_category($catRow + [
            'slug' => $slug, 'image' => $cardImg, 'status' => $data['status'],
            'subtitle' => $data['banner_subtitle'], 'meta_title' => $data['meta_title'], 'meta_description' => $data['meta_description'],
        ]);
        $cat = db_row('SELECT * FROM program_categories WHERE id = ?', [$newId]);
        $page = db_row('SELECT * FROM pages WHERE id = ?', [(int) $cat['page_id']]);
        $pageUpdate = ['banner_eyebrow' => $data['banner_eyebrow'] !== '' ? $data['banner_eyebrow'] : 'Programs & Events',
            'og_image' => $ogImg, 'noindex' => !empty($_POST['noindex']) ? 1 : 0];
        if ($bannerImg) {
            $pageUpdate['banner_image'] = $bannerImg;
        }
        db_update('pages', $pageUpdate, 'id = :id', ['id' => (int) $page['id']]);
        $id = $newId;
        $sections = programhub_admin_category_sections((int) $page['id']);
        admin_log('program_category_created', 'Programme category #' . $id . ' "' . $data['title'] . '" created with page /programs/' . $slug . '/ (' . $data['status'] . ')');
    } else {
        if ($cardImg || !empty($_POST['remove_card_image'])) {
            $catRow['image'] = $cardImg ?: null;
        }
        db_update('program_categories', $catRow, 'id = :id', ['id' => $id]);
        if ($cardImg || !empty($_POST['remove_card_image'])) {
            programhub_admin_delete_file($cat['image']);
        }
        if ($slug !== $cat['slug']) {
            $pageMoves = $page && $page['slug'] === 'programs/' . $cat['slug'];
            $notes = array_merge($notes, programhub_admin_change_category_slug($cat, $slug));
            if ($pageMoves && $page['status'] === 'published' && function_exists('seo_slug_changed')) {
                // SEO: also re-point older redirects with fragments / absolute URLs and menu links; report loops
                $seoMove = seo_slug_changed('page', '/programs/' . $cat['slug'] . '/', '/programs/' . $slug . '/');
                if (!empty($seoMove['loops'])) {
                    $notes[] = 'Warning: the redirects list contains a loop - please check Redirects.';
                }
            }
        }
        if ($data['status'] !== $cat['status']) {
            $note = programhub_admin_set_category_status($cat, $data['status']);
            if ($note !== '') {
                $notes[] = trim($note);
            }
        }
        if ($page) {
            $pageUpdate = [
                'title' => $data['title'], 'banner_eyebrow' => $data['banner_eyebrow'] !== '' ? $data['banner_eyebrow'] : null,
                'banner_subtitle' => $data['banner_subtitle'] !== '' ? $data['banner_subtitle'] : null,
                'meta_title' => $data['meta_title'] !== '' ? $data['meta_title'] : null, 'meta_description' => $data['meta_description'] !== '' ? $data['meta_description'] : null,
                'noindex' => !empty($_POST['noindex']) ? 1 : 0, 'updated_by' => admin_id(),
            ];
            if ($bannerImg || !empty($_POST['remove_banner'])) {
                $pageUpdate['banner_image'] = $bannerImg ?: null;
            }
            if ($ogImg || !empty($_POST['remove_og'])) {
                $pageUpdate['og_image'] = $ogImg ?: null;
            }
            db_update('pages', $pageUpdate, 'id = :id', ['id' => (int) $page['id']]);
            if (array_key_exists('banner_image', $pageUpdate)) {
                // the "About" picture followed the banner: keep them together
                if ($pageUpdate['banner_image'] && !empty($sections['about']) && (string) $sections['about']['image'] === (string) $page['banner_image']) {
                    db_update('page_sections', ['image' => $pageUpdate['banner_image']], 'id = :id', ['id' => (int) $sections['about']['id']]);
                }
                content_delete_image($page['banner_image']);
            }
            if (array_key_exists('og_image', $pageUpdate)) {
                content_delete_image($page['og_image']);
            }
        }
        admin_log('program_category_updated', 'Programme category #' . $id . ' "' . $data['title'] . '" saved (' . $data['status'] . ')' . ($slug !== $cat['slug'] ? ', address /programs/' . $cat['slug'] . '/ -> /programs/' . $slug . '/' : ''));
    }

    // page texts: about, topics, who can participate, benefits, FAQs
    if ($page) {
        $pid = (int) $page['id'];
        $old = function (string $anchor) use ($sections): array {
            return json_field($sections[$anchor]['items'] ?? '[]');
        };
        programhub_admin_save_category_section($pid, 'about', ['title' => $data['about_title'], 'intro' => $data['about_intro'], 'body' => $aboutBody,
            'items' => programhub_admin_lines($data['objectives'], 200, 12)], $sections['about'] ?? null);
        programhub_admin_save_category_section($pid, 'topics', ['title' => $data['topics_title'], 'intro' => $data['topics_intro'],
            'items' => programhub_admin_merge_cards($topics, $old('topics'))], $sections['topics'] ?? null);
        programhub_admin_save_category_section($pid, 'who-can-participate', ['title' => $data['who_title'], 'intro' => $data['who_intro'],
            'items' => programhub_admin_lines($data['who'], 200, 16)], $sections['who-can-participate'] ?? null);
        programhub_admin_save_category_section($pid, 'benefits', ['title' => $data['benefits_title'], 'intro' => $data['benefits_intro'],
            'items' => programhub_admin_merge_cards($benefits, $old('benefits'))], $sections['benefits'] ?? null);
        programhub_admin_save_category_section($pid, 'faqs', ['title' => $data['faqs_title'], 'items' => $faqs], $sections['faqs'] ?? null);
        site_touch_page($pid);
        if ($eeat && function_exists('seo_eeat_save')) {
            $eeatChanges = seo_eeat_save('page', $pid, $eeat, admin_id());
            if ($eeatChanges) {
                admin_log('seo_eeat_updated', 'Page #' . $pid . ' (programme category #' . $id . '): ' . implode(', ', array_keys($eeatChanges)));
            }
        }
    }
    program_categories_all(false, true);
    program_categories_all(true, true);
    flash('success', 'Category saved.' . ($notes ? ' ' . implode(' ', $notes) : ''));
    admin_redirect('program-category-edit.php?id=' . $id);
}

// ---------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------
$failed = old('title', null) !== null;
$v = function (string $key, $default = '') use ($cat) {
    return old($key, $cat[$key] ?? $default);
};
$pv = function (string $key, $default = '') use ($page) {
    return old($key, $page[$key] ?? $default);
};
$sec = function (string $anchor, string $col, string $default = '') use ($sections) {
    return (string) ($sections[$anchor][$col] ?? $default);
};
$checked = function (string $key, bool $default) use ($failed, $cat): string {
    if ($failed) {
        return old($key, null) !== null ? ' checked' : '';
    }
    return ($cat ? (int) $cat[$key] === 1 : $default) ? ' checked' : '';
};
$repeaterRows = function (string $key, array $fromDb) {
    $posted = old($key, null);
    return is_array($posted) ? array_values(array_filter($posted, function ($r) { return is_array($r) && empty($r['_remove']); })) : $fromDb;
};
$cardRows = function (string $anchor) use ($sections): array {
    $out = [];
    foreach (json_field($sections[$anchor]['items'] ?? '[]') as $it) {
        if (is_array($it)) {
            $out[] = ['icon' => (string) ($it['icon'] ?? ''), 'title' => (string) ($it['title'] ?? ''), 'text' => (string) ($it['text'] ?? '')];
        }
    }
    return $out;
};
$lines = function (string $anchor) use ($sections): string {
    return implode("\n", array_filter(json_field($sections[$anchor]['items'] ?? '[]'), 'is_string'));
};
$topicsRows = $repeaterRows('topics', $cardRows('topics'));
$benefitRows = $repeaterRows('benefits', $cardRows('benefits'));
$starterFaqs = [];
if (!$cat && is_file(ROOT_PATH . '/includes/upgrade/program-categories.php')) {
    $seed = require ROOT_PATH . '/includes/upgrade/program-categories.php';
    $starterFaqs = is_array($seed['common']['faqs'] ?? null) ? $seed['common']['faqs'] : [];   // shared, generic answers - editable
}
$faqRows = $repeaterRows('faqs', $cat ? array_values(array_filter(json_field($sections['faqs']['items'] ?? '[]'), 'is_array')) : $starterFaqs);
$catFull = $cat ? (program_category_by_id($id, false) ?: $cat) : null;
$isLive = $cat && $page && $cat['status'] === 'published' && $page['status'] === 'published';
$events = $cat ? (int) db_value('SELECT COUNT(*) FROM events WHERE category_id = ?', [$id]) : 0;
$cardImgPath = (string) ($cat['image'] ?? '');
$bannerPath = (string) ($page['banner_image'] ?? '');
$ogPath = (string) ($page['og_image'] ?? '');

admin_page_start($cat ? 'Edit category: ' . $cat['title'] : 'New programme category', array_filter([
    ['label' => 'All categories', 'url' => admin_url('program-categories.php'), 'icon' => 'list', 'style' => 'ghost'],
    $page && admin_can('pages.manage') ? ['label' => 'Section editor', 'url' => admin_url('page-edit.php?id=' . (int) $page['id'] . '&tab=sections'), 'icon' => 'layers', 'style' => 'ghost'] : null,
    $cat ? ['label' => 'New event here', 'url' => admin_url('event-edit.php?category=' . $id), 'icon' => 'plus', 'style' => 'ghost'] : null,
]));
echo site_icon_sprite();
?>
<div class="ph-admin">
<?php if ($cat): ?>
    <div class="ph-status-line">
        <?= status_badge($cat['status'] === 'published' ? 'published' : 'hidden') ?>
        <?php if ($page): ?><span><?= $isLive ? 'Live at' : 'Page (not visible to visitors):' ?> <a href="<?= e(program_category_url($catFull) . ($isLive ? '' : '?preview=1')) ?>" target="_blank" rel="noopener"><?= e(program_category_abs_url($catFull)) ?></a></span><?php else: ?><span class="badge badge-red">No landing page</span><?php endif; ?>
        <span><a href="<?= e(admin_url('events.php?category=' . $id . '&when=all')) ?>"><?= icon('calendar') ?> <?= $events ?> event<?= $events === 1 ? '' : 's' ?></a></span>
    </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" action="<?= e(admin_url($self)) ?>" data-validate>
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_UPLOAD_MB * 1024 * 1024 ?>">
    <div class="admin-grid-main">
        <div>
            <section class="panel">
                <div class="panel-head"><h2><?= icon('grid') ?>Card &amp; menu</h2></div>
                <div class="form-grid">
                    <div class="form-group full"><label for="title">Category name<span class="req">*</span></label>
                        <input type="text" id="title" name="title" required minlength="3" maxlength="150" value="<?= e($v('title')) ?>" data-slug-source="#slug"><?= field_error('title') ?></div>
                    <div class="form-group full"><label for="slug">Web address</label>
                        <div class="site-slug"><span>/programs/</span><input type="text" id="slug" name="slug" maxlength="100" pattern="[a-z0-9]+(-[a-z0-9]+)*" value="<?= e($v('slug')) ?>" <?= $cat ? 'data-touched="1"' : '' ?> aria-describedby="slug-help"></div>
                        <span class="help" id="slug-help"><?= $cat ? 'Changing it moves the page; the old address redirects (301) to the new one and menu links are updated.' : 'Leave empty to create it from the name. Lower-case letters, numbers and hyphens.' ?></span><?= field_error('slug') ?></div>
                    <div class="form-group"><label for="nav_label">Short label for menus</label>
                        <input type="text" id="nav_label" name="nav_label" maxlength="80" value="<?= e($v('nav_label')) ?>" placeholder="Same as the name"><?= field_error('nav_label') ?></div>
                    <div class="form-group"><label for="tone">Colour</label>
                        <select id="tone" name="tone"><?= options_html(program_tones(), (string) $v('tone', 'green'), true, '') ?></select><?= field_error('tone') ?></div>
                    <?= site_field_html('icon', 'icon', ['icon', 'Icon', 'Shown on the card and in the menu.'], (string) $v('icon', 'calendar')) ?>
                    <div class="form-group full"><label for="card_text">Card text<span class="req">*</span></label>
                        <textarea id="card_text" name="card_text" required minlength="10" maxlength="300" rows="3" data-counter="300" style="min-height:80px"><?= e($v('card_text')) ?></textarea>
                        <span class="help">One sentence on the category card, e.g. "Yoga sessions, fitness awareness, meditation and healthy lifestyle."</span><?= field_error('card_text') ?></div>
                    <div class="form-group full"><span class="label">Card image (optional)</span>
                        <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
                            <img src="<?= e(image_url($catFull ? program_category_art($catFull) : 'assets/images/programs/general.svg')) ?>" alt="" width="120" height="80" style="width:120px;height:80px;object-fit:contain;border-radius:10px;background:var(--green-50)">
                            <div><label class="sr-only" for="card_image">Upload a card image</label><input id="card_image" type="file" name="card_image" accept=".jpg,.jpeg,.png,.webp">
                                <?php if ($cardImgPath !== ''): ?><label class="checkbox mt-1"><input type="checkbox" name="remove_card_image" value="1"><span>Remove the card image</span></label><?php endif; ?></div>
                        </div>
                        <span class="help">Without a card image, the page banner is used. Do not use identifiable photos of children without documented consent.</span><?= field_error('card_image') ?></div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h2><?= icon('image') ?>Page banner</h2></div>
                <div class="form-grid">
                    <div class="form-group"><label for="banner_eyebrow">Small label above the title</label>
                        <input type="text" id="banner_eyebrow" name="banner_eyebrow" maxlength="80" value="<?= e($pv('banner_eyebrow', 'Programs & Events')) ?>"><?= field_error('banner_eyebrow') ?></div>
                    <div class="form-group full"><label for="banner_subtitle">Banner introduction</label>
                        <textarea id="banner_subtitle" name="banner_subtitle" maxlength="300" rows="3" data-counter="300" style="min-height:80px"><?= e($pv('banner_subtitle')) ?></textarea>
                        <span class="help">One or two sentences under the title. The banner buttons (Register / Explore events) jump to the sections on the page.</span><?= field_error('banner_subtitle') ?></div>
                    <div class="form-group full"><span class="label">Banner image</span>
                        <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
                            <?php if ($bannerPath !== ''): ?><img src="<?= e(image_url($bannerPath)) ?>" alt="" width="160" height="90" style="width:160px;height:90px;object-fit:contain;border-radius:10px;background:var(--green-50)"><?php endif; ?>
                            <div><label class="sr-only" for="banner_upload">Upload a banner image</label><input id="banner_upload" type="file" name="banner_upload" accept=".jpg,.jpeg,.png,.webp">
                                <?php if ($bannerPath !== ''): ?><label class="checkbox mt-1"><input type="checkbox" name="remove_banner" value="1"><span>Remove the banner image</span></label><?php endif; ?></div>
                        </div><?= field_error('banner_upload') ?></div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h2><?= icon('book') ?>About the programme</h2></div>
                <div class="form-grid">
                    <div class="form-group full"><label for="about_title">Heading</label>
                        <input type="text" id="about_title" name="about_title" maxlength="150" value="<?= e(old('about_title', $sec('about', 'title', $cat ? '' : ''))) ?>" placeholder="e.g. Wellness for every age"><?= field_error('about_title') ?></div>
                    <div class="form-group full"><label for="about_intro">Introduction</label>
                        <textarea id="about_intro" name="about_intro" maxlength="700" rows="3"><?= e(old('about_intro', $sec('about', 'intro'))) ?></textarea><?= field_error('about_intro') ?></div>
                    <div class="form-group full"><label for="about_body">Learning opportunities &amp; community impact</label>
                        <textarea id="about_body" name="about_body" rows="8" data-editor><?= e(old('about_body', $sec('about', 'body'))) ?></textarea></div>
                    <div class="form-group full"><label for="objectives">Objectives</label>
                        <textarea id="objectives" name="objectives" rows="5" maxlength="3000"><?= e(old('objectives', $lines('about'))) ?></textarea>
                        <span class="help">One per line - shown as a tick list next to the text.</span><?= field_error('objectives') ?></div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h2><?= icon('layers') ?>Topics &amp; activities</h2></div>
                <div class="form-grid">
                    <div class="form-group"><label for="topics_title">Heading</label>
                        <input type="text" id="topics_title" name="topics_title" maxlength="150" value="<?= e(old('topics_title', $sec('topics', 'title', 'Topics & activities'))) ?>"></div>
                    <div class="form-group full"><label for="topics_intro">Introduction</label>
                        <textarea id="topics_intro" name="topics_intro" maxlength="600" rows="2"><?= e(old('topics_intro', $sec('topics', 'intro'))) ?></textarea></div>
                    <div class="form-group full"><span class="label">Cards</span>
                        <?= site_repeater_html('topics', 'topics', $cardFields, $topicsRows, '', 'Topic', $topicsRows ? 0 : 1) ?><?= field_error('topics') ?></div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h2><?= icon('users') ?>Who can participate</h2></div>
                <div class="form-grid">
                    <div class="form-group"><label for="who_title">Heading</label>
                        <input type="text" id="who_title" name="who_title" maxlength="150" value="<?= e(old('who_title', $sec('who-can-participate', 'title', 'Who can participate'))) ?>"></div>
                    <div class="form-group full"><label for="who_intro">Introduction</label>
                        <textarea id="who_intro" name="who_intro" maxlength="600" rows="2"><?= e(old('who_intro', $sec('who-can-participate', 'intro'))) ?></textarea></div>
                    <div class="form-group full"><label for="who">Participants</label>
                        <textarea id="who" name="who" rows="5" maxlength="3000"><?= e(old('who', $lines('who-can-participate'))) ?></textarea>
                        <span class="help">One group per line. Each event page states its own exact eligibility.</span><?= field_error('who') ?></div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h2><?= icon('award') ?>Benefits &amp; learning outcomes</h2></div>
                <div class="form-grid">
                    <div class="form-group"><label for="benefits_title">Heading</label>
                        <input type="text" id="benefits_title" name="benefits_title" maxlength="150" value="<?= e(old('benefits_title', $sec('benefits', 'title', 'Benefits & learning outcomes'))) ?>"></div>
                    <div class="form-group full"><label for="benefits_intro">Introduction</label>
                        <textarea id="benefits_intro" name="benefits_intro" maxlength="600" rows="2"><?= e(old('benefits_intro', $sec('benefits', 'intro'))) ?></textarea></div>
                    <div class="form-group full"><span class="label">Cards</span>
                        <?= site_repeater_html('benefits', 'benefits', $cardFields, $benefitRows, '', 'Benefit', $benefitRows ? 0 : 1) ?><?= field_error('benefits') ?>
                        <span class="help">Describe what participants can expect to learn - no promises of jobs, income or results.</span></div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h2><?= icon('help-circle') ?>Frequently asked questions</h2></div>
                <div class="form-grid">
                    <div class="form-group"><label for="faqs_title">Heading</label>
                        <input type="text" id="faqs_title" name="faqs_title" maxlength="150" value="<?= e(old('faqs_title', $sec('faqs', 'title', 'Frequently asked questions'))) ?>"></div>
                    <div class="form-group full"><span class="label">Questions &amp; answers</span>
                        <?= site_repeater_html('faqs', 'faqs', ['q' => ['text', 'Question'], 'a' => ['textarea', 'Answer']], $faqRows, '', 'Question', $faqRows ? 0 : 1) ?><?= field_error('faqs') ?>
                        <span class="help">Fees, eligibility, certificates, schedule, participation - honest and general. Event-specific answers belong on the event.</span></div>
                </div>
            </section>
            <p class="muted small"><?= icon('info') ?> Upcoming and past programmes, the registration and partnership forms and the closing call to action are live blocks filled from your events. Change their headings, order or visibility<?= $page && admin_can('pages.manage') ? ' in the <a href="' . e(admin_url('page-edit.php?id=' . (int) $page['id'] . '&tab=sections')) . '">section editor</a>' : ' in the section editor (Admin &gt; Pages)' ?>.</p>

            <section class="panel">
                <div class="panel-head"><h2><?= icon('search') ?>Search engines &amp; sharing</h2></div>
                <div class="form-grid">
                    <div class="form-group full"><label for="meta_title">Search title</label>
                        <input type="text" id="meta_title" name="meta_title" maxlength="200" data-counter="60" value="<?= e($pv('meta_title')) ?>" placeholder="<?= e($v('title')) ?>"><span class="help">About 50-60 characters. Leave empty to use the category name.</span><?= field_error('meta_title') ?></div>
                    <div class="form-group full"><label for="meta_description">Search description</label>
                        <textarea id="meta_description" name="meta_description" maxlength="300" rows="3" data-counter="160" style="min-height:80px"><?= e($pv('meta_description')) ?></textarea><span class="help">About 150-160 characters describing the programme honestly.</span><?= field_error('meta_description') ?></div>
                    <div class="form-group full"><span class="label">Social sharing image (optional, 1200 × 630 px)</span>
                        <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
                            <?php if ($ogPath !== ''): ?><img src="<?= e(image_url($ogPath)) ?>" alt="" width="120" height="63" style="width:120px;height:63px;object-fit:cover;border-radius:8px"><?php endif; ?>
                            <div><label class="sr-only" for="og_upload">Upload a sharing image</label><input id="og_upload" type="file" name="og_upload" accept=".jpg,.jpeg,.png,.webp">
                                <?php if ($ogPath !== ''): ?><label class="checkbox mt-1"><input type="checkbox" name="remove_og" value="1"><span>Remove the sharing image</span></label><?php endif; ?></div>
                        </div><?= field_error('og_upload') ?></div>
                    <div class="form-group full"><label class="checkbox"><input type="checkbox" name="noindex" value="1"<?= ($failed ? old('noindex', null) !== null : (int) ($page['noindex'] ?? 0) === 1) ? ' checked' : '' ?>><span>Hide this page from search engines (noindex)</span></label></div>
                    <?php if ($page && function_exists('seo_entity_edit_url') && admin_can('seo.manage')): ?>
                        <div class="form-group full"><a class="btn btn-ghost btn-sm" href="<?= e(seo_entity_edit_url('page', (int) $page['id'])) ?>"><?= icon('search') ?>SEO settings</a>
                            <span class="help">Focus keyword, social sharing text, structured data and more for this page.</span></div>
                    <?php endif; ?>
                </div>
            </section>
            <?= function_exists('seo_eeat_admin_panel') ? seo_eeat_admin_panel('page', (int) ($page['id'] ?? 0)) : '' ?>
        </div>

        <div>
            <div class="ph-side-sticky">
                <section class="panel">
                    <div class="panel-head"><h2><?= icon('check-circle') ?>Publish</h2></div>
                    <div class="form-group mb-2"><label for="status">Status</label>
                        <select id="status" name="status"><?= options_html(['published' => 'Published (visible)', 'draft' => 'Hidden (draft)'], (string) $v('status', 'draft'), true, '') ?></select>
                        <span class="help">Also publishes / hides the landing page.</span></div>
                    <div class="form-group mb-2"><label for="sort_order">Display order</label>
                        <input type="number" id="sort_order" name="sort_order" min="-9999" max="9999" step="1" value="<?= e((string) $v('sort_order')) ?>" placeholder="After the others"><?= field_error('sort_order') ?></div>
                    <label class="checkbox mb-2"><input type="checkbox" name="show_in_nav" value="1"<?= $checked('show_in_nav', true) ?>><span>Show in the Programs &amp; Events menu</span></label>
                    <div class="form-actions" style="margin-top:14px">
                        <button class="btn btn-primary" type="submit"><?= icon('check') ?><?= $cat ? 'Save' : 'Create category' ?></button>
                        <?php if ($catFull && $page): ?><a class="btn btn-ghost" href="<?= e(program_category_url($catFull) . ($isLive ? '' : '?preview=1')) ?>" target="_blank" rel="noopener"><?= icon('eye') ?><?= $isLive ? 'View' : 'Preview' ?></a><?php endif; ?>
                    </div>
                </section>
                <section class="panel">
                    <div class="panel-head"><h2><?= icon('settings') ?>Events &amp; forms</h2></div>
                    <div class="form-group mb-2"><label for="default_event_type">Default type for new events</label>
                        <select id="default_event_type" name="default_event_type"><?= options_html($eventTypes, (string) $v('default_event_type'), true, 'None') ?></select><?= field_error('default_event_type') ?></div>
                    <label class="checkbox mb-2"><input type="checkbox" name="interest_enabled" value="1"<?= $checked('interest_enabled', true) ?>><span>Offer "register your interest" when no session is open</span></label>
                    <label class="checkbox mb-2"><input type="checkbox" name="partnership_enabled" value="1"<?= $checked('partnership_enabled', true) ?>><span>Show the partnership enquiry form</span></label>
                    <div class="form-group"><label for="notify_email">Alert email for this category</label>
                        <input type="email" id="notify_email" name="notify_email" maxlength="190" value="<?= e($v('notify_email')) ?>" placeholder="Default event alert address">
                        <span class="help">Receives new-registration and enquiry alerts for this category. Empty = the address in Events &gt; Settings.</span><?= field_error('notify_email') ?></div>
                </section>
            </div>
        </div>
    </div>
</form>

<?php if ($cat): ?>
    <section class="panel ph-danger">
        <div class="panel-head"><h2><?= icon('trash') ?>Delete category</h2></div>
        <?php if ($events): ?>
            <p class="muted small mb-0">This category has <?= $events ?> event<?= $events === 1 ? '' : 's' ?>, so it cannot be deleted. Hide it instead (status "Hidden"), or move its events to another category first.</p>
        <?php else: ?>
            <form method="post" action="<?= e(admin_url($self)) ?>" data-confirm="<?= e('Delete the category "' . $cat['title'] . '" and its landing page with all its sections? This cannot be undone.') ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>">
                <p class="muted small">Deletes the category and its landing page (with its sections). A published page's address then redirects to /programs/. Interest sign-ups are kept without a category.</p>
                <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash') ?>Delete category</button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
</div>
<?php
programhub_admin_assets();
admin_page_end();
