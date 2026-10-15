<?php
/**
 * Saves website content: pages, public documents, homepage, menus, events,
 * gallery items, blog posts, testimonials, FAQs and site settings.
 */
require_once dirname(__DIR__) . '/includes/auth.php';

$type = (string) ($_POST['type'] ?? '');
$action = (string) ($_POST['action'] ?? 'save');
$id = (int) ($_POST['id'] ?? 0);
require_permission($type === 'settings' ? 'settings' : 'content');

$listPages = [
    'page' => 'pages.php', 'document' => 'pages.php#documents', 'homepage' => 'homepage.php', 'menu' => 'menus.php',
    'event' => 'events.php', 'gallery' => 'gallery.php', 'blog' => 'blog.php', 'testimonial' => 'testimonials.php',
    'faq' => 'faqs.php', 'settings' => 'settings.php', 'registration' => 'events.php',
];
if (!isset($listPages[$type])) {
    flash('error', 'Unknown request.');
    admin_redirect('dashboard.php');
}
csrf_verify('admin/' . $listPages[$type]);

/** Re-show the form with errors. */
function content_back(array $errors, string $to): void
{
    back_with_errors($errors, 'admin/' . $to, $_POST);
}

switch ($type) {
    // -----------------------------------------------------------------
    case 'page':
        $page = db_row('SELECT * FROM website_pages WHERE id = ?', [$id]);
        if (!$page) {
            flash('error', 'Page not found.');
            admin_redirect('pages.php');
        }
        [$data, $errors] = validate($_POST, [
            'title'            => 'required|min:2|max:200',
            'subtitle'         => 'max:300',
            'meta_description' => 'max:300',
            'content'          => 'required|text|max:200000',
            'status'           => 'required|in:published,hidden',
        ]);
        $upload = !$errors ? handle_upload('banner_image', 'image', 'blog') : [];
        if (isset($upload['error'])) {
            $errors['banner_image'] = $upload['error'];
        }
        if ($errors) {
            content_back($errors, 'pages.php?edit=' . $id);
        }
        $row = [
            'title' => $data['title'], 'subtitle' => $data['subtitle'] ?: null, 'meta_description' => $data['meta_description'] ?: null,
            'content' => clean_html($data['content']), 'status' => $data['status'], 'updated_by' => admin_id(),
        ];
        if (!empty($upload['path'])) {
            delete_upload($page['banner_image']);
            $row['banner_image'] = $upload['path'];
        } elseif (!empty($_POST['remove_banner'])) {
            delete_upload($page['banner_image']);
            $row['banner_image'] = null;
        }
        db_update('website_pages', $row, 'id = :id', ['id' => $id]);
        admin_log('page_updated', $page['slug']);
        flash('success', 'Page "' . $data['title'] . '" saved.');
        admin_redirect('pages.php?edit=' . $id);

    // -----------------------------------------------------------------
    case 'document':
        $docs = json_decode(setting('public_documents', '[]'), true) ?: [];
        if ($action === 'delete') {
            $docId = (string) ($_POST['doc_id'] ?? '');
            foreach ($docs as $i => $d) {
                if (($d['id'] ?? '') === $docId) {
                    delete_upload($d['path']);
                    unset($docs[$i]);
                    admin_log('document_deleted', $d['title']);
                }
            }
            save_setting('public_documents', json_encode(array_values($docs)));
            flash('success', 'Document removed.');
            admin_redirect('pages.php#documents');
        }
        [$data, $errors] = validate($_POST, [
            'doc_title'    => 'required|min:3|max:150',
            'doc_category' => 'required|in:' . implode(',', array_keys(DOCUMENT_CATEGORIES)),
            'doc_date'     => 'date',
        ], ['doc_title' => 'Title', 'doc_category' => 'Category']);
        $upload = !$errors ? handle_upload('document', 'pdf', 'documents/public', true) : [];
        if (isset($upload['error'])) {
            $errors['document'] = $upload['error'];
        }
        if ($errors) {
            flash('error', reset($errors));
            admin_redirect('pages.php#documents');
        }
        $docs[] = ['id' => bin2hex(random_bytes(6)), 'title' => $data['doc_title'], 'category' => $data['doc_category'], 'date' => $data['doc_date'] ?: date('Y-m-d'), 'path' => $upload['path']];
        save_setting('public_documents', json_encode(array_values($docs)));
        admin_log('document_uploaded', $data['doc_title']);
        flash('success', 'Document published.');
        admin_redirect('pages.php#documents');

    // -----------------------------------------------------------------
    case 'homepage':
        $old = json_decode(setting('hero_slides', '[]'), true) ?: [];
        $slides = [];
        $errors = [];
        foreach ((array) ($_POST['slides'] ?? []) as $i => $s) {
            $i = (int) $i;
            if (!is_array($s) || !empty($s['remove'])) {
                continue;
            }
            $title = mb_substr(clean_string($s['title'] ?? ''), 0, 120);
            if ($title === '') {
                continue;
            }
            $link = mb_substr(clean_string($s['button_link'] ?? ''), 0, 255);
            $image = $old[$i]['image'] ?? '';
            if (isset($_FILES['slide_image']['name'][$i]) && $_FILES['slide_image']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                // Map the multi-file field to a single-file entry for handle_upload()
                $_FILES['_slide'] = [];
                foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $k) {
                    $_FILES['_slide'][$k] = $_FILES['slide_image'][$k][$i];
                }
                $up = handle_upload('_slide', 'image', 'blog');
                if (isset($up['error'])) {
                    $errors[] = 'Slide ' . ($i + 1) . ': ' . $up['error'];
                } elseif (!empty($up['path'])) {
                    $image = $up['path'];
                }
            }
            $slides[] = [
                'eyebrow'     => mb_substr(clean_string($s['eyebrow'] ?? ''), 0, 60),
                'title'       => $title,
                'text'        => mb_substr(clean_string($s['text'] ?? ''), 0, 300),
                'button_text' => mb_substr(clean_string($s['button_text'] ?? ''), 0, 40) ?: 'Donate Now',
                'button_link' => $link !== '' ? $link : 'donate.php',
                'image'       => $image,
            ];
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
            admin_redirect('homepage.php');
        }
        save_setting('hero_slides', json_encode($slides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        save_setting('home_work_title', mb_substr(clean_string($_POST['home_work_title'] ?? ''), 0, 80) ?: 'Our Work');
        save_setting('home_work_intro', mb_substr(clean_string($_POST['home_work_intro'] ?? '', true), 0, 500));
        save_setting('show_impact_counters', !empty($_POST['show_impact_counters']) ? '1' : '0');
        admin_log('homepage_updated', count($slides) . ' slides');
        flash('success', 'Homepage updated.');
        admin_redirect('homepage.php');

    // -----------------------------------------------------------------
    case 'menu':
        if ($action === 'reset') {
            save_setting('main_menu', '');
            admin_log('menu_reset', '');
            flash('success', 'Menu reset to the default navigation.');
            admin_redirect('menus.php');
        }
        $labels = (array) ($_POST['label'] ?? []);
        $urls = (array) ($_POST['url'] ?? []);
        $parents = (array) ($_POST['parent'] ?? []);
        $menu = [];
        $children = [];
        foreach ($labels as $i => $label) {
            $label = mb_substr(clean_string($label), 0, 40);
            $link = mb_substr(clean_string($urls[$i] ?? ''), 0, 255);
            $parent = mb_substr(clean_string($parents[$i] ?? ''), 0, 40);
            if ($label === '' || $link === '') {
                continue;
            }
            if (!preg_match('#^https?://#i', $link) && !preg_match('#^[a-z0-9][a-z0-9\-_/]*\.php([?\#][^\s<>"]*)?$#i', $link)) {
                flash('error', 'Invalid link for "' . $label . '". Use a page like about.php or a full https:// address.');
                admin_redirect('menus.php');
            }
            if ($parent === '') {
                $menu[] = ['label' => $label, 'url' => $link];
            } else {
                $children[$parent][] = ['label' => $label, 'url' => $link];
            }
        }
        foreach ($menu as &$item) {
            if (!empty($children[$item['label']])) {
                $item['children'] = $children[$item['label']];
            }
        }
        unset($item);
        if (!$menu) {
            flash('error', 'The menu needs at least one top-level item.');
            admin_redirect('menus.php');
        }
        save_setting('main_menu', json_encode($menu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        admin_log('menu_updated', count($menu) . ' top-level items');
        flash('success', 'Menu saved.');
        admin_redirect('menus.php');

    // -----------------------------------------------------------------
    case 'event':
        $event = $id ? db_row('SELECT * FROM events WHERE id = ?', [$id]) : null;
        if ($action === 'delete') {
            if ($event) {
                db_query('DELETE FROM events WHERE id = ?', [$id]);
                delete_upload($event['image']);
                admin_log('event_deleted', $event['title']);
                flash('success', 'Event deleted.');
            }
            admin_redirect('events.php');
        }
        [$data, $errors] = validate($_POST, [
            'title'          => 'required|min:3|max:200',
            'category'       => 'max:60',
            'summary'        => 'required|text|min:10|max:300',
            'description'    => 'required|text|max:100000',
            'location'       => 'required|min:2|max:200',
            'start_datetime' => 'required|datetime',
            'end_datetime'   => 'datetime',
            'capacity'       => 'integer|min:1|max:100000',
            'status'         => 'required|in:draft,published,cancelled',
        ], ['start_datetime' => 'Start', 'end_datetime' => 'End']);
        if (!$errors && $data['end_datetime'] && $data['end_datetime'] < $data['start_datetime']) {
            $errors['end_datetime'] = 'End cannot be before the start.';
        }
        $upload = !$errors ? handle_upload('image', 'image', 'blog') : [];
        if (isset($upload['error'])) {
            $errors['image'] = $upload['error'];
        }
        if ($errors) {
            content_back($errors, 'events.php?' . ($id ? 'edit=' . $id : 'action=add'));
        }
        $row = [
            'title' => $data['title'], 'slug' => unique_slug('events', $data['title'], $id), 'category' => $data['category'] ?: 'general',
            'summary' => $data['summary'], 'description' => clean_html($data['description']), 'location' => $data['location'],
            'start_datetime' => $data['start_datetime'], 'end_datetime' => $data['end_datetime'] ?: null,
            'registration_enabled' => !empty($_POST['registration_enabled']) ? 1 : 0,
            'capacity' => $data['capacity'] !== '' ? (int) $data['capacity'] : null, 'status' => $data['status'],
        ];
        if ($event) {
            $row['slug'] = $event['slug'];
        }
        if (!empty($upload['path'])) {
            $row['image'] = $upload['path'];
            if ($event) {
                delete_upload($event['image']);
            }
        }
        if ($event) {
            db_update('events', $row, 'id = :id', ['id' => $id]);
            admin_log('event_updated', $data['title']);
        } else {
            $row['created_by'] = admin_id();
            $id = db_insert('events', $row);
            admin_log('event_created', $data['title']);
        }
        flash('success', 'Event saved.');
        admin_redirect('events.php?edit=' . $id);

    // -----------------------------------------------------------------
    case 'registration':
        $reg = db_row('SELECT * FROM event_registrations WHERE id = ?', [$id]);
        $status = (string) ($_POST['status'] ?? '');
        if ($reg && in_array($status, ['registered', 'attended', 'cancelled'], true)) {
            db_update('event_registrations', ['status' => $status], 'id = :id', ['id' => $id]);
            admin_log('event_registration_updated', 'Registration #' . $id . ' ' . $status);
            flash('success', 'Registration updated.');
        }
        admin_redirect('events.php?registrations=' . (int) ($reg['event_id'] ?? 0));

    // -----------------------------------------------------------------
    case 'gallery':
        $item = db_row('SELECT * FROM gallery WHERE id = ?', [$id]);
        if (!$item) {
            admin_redirect('gallery.php');
        }
        if ($action === 'delete') {
            db_query('DELETE FROM gallery WHERE id = ?', [$id]);
            delete_upload($item['image_path']);
            admin_log('gallery_deleted', $item['title']);
            flash('success', 'Photo deleted.');
        } else {
            [$data, $errors] = validate($_POST, [
                'title' => 'required|min:2|max:150', 'category' => 'required|in:' . implode(',', array_keys(GALLERY_CATEGORIES)),
                'sort_order' => 'integer', 'status' => 'required|in:published,hidden', 'description' => 'max:300',
            ]);
            if ($errors) {
                flash('error', reset($errors));
                admin_redirect('gallery.php');
            }
            db_update('gallery', [
                'title' => $data['title'], 'category' => $data['category'], 'sort_order' => (int) $data['sort_order'],
                'status' => $data['status'], 'description' => $data['description'] ?: null,
            ], 'id = :id', ['id' => $id]);
            admin_log('gallery_updated', $data['title']);
            flash('success', 'Photo updated.');
        }
        admin_redirect(admin_back('gallery.php'));

    // -----------------------------------------------------------------
    case 'blog':
        $post = $id ? db_row('SELECT * FROM blog_posts WHERE id = ?', [$id]) : null;
        if ($action === 'delete') {
            if ($post) {
                db_query('DELETE FROM blog_posts WHERE id = ?', [$id]);
                delete_upload($post['image']);
                admin_log('blog_deleted', $post['title']);
                flash('success', 'Post deleted.');
            }
            admin_redirect('blog.php');
        }
        [$data, $errors] = validate($_POST, [
            'title'        => 'required|min:3|max:220',
            'category'     => 'required|in:' . implode(',', array_keys(BLOG_CATEGORIES)),
            'excerpt'      => 'required|text|min:10|max:400',
            'content'      => 'required|text|max:200000',
            'author_name'  => 'max:120',
            'status'       => 'required|in:draft,published',
            'published_at' => 'datetime',
        ], ['published_at' => 'Publish date']);
        $upload = !$errors ? handle_upload('image', 'image', 'blog') : [];
        if (isset($upload['error'])) {
            $errors['image'] = $upload['error'];
        }
        if ($errors) {
            content_back($errors, 'blog.php?' . ($id ? 'edit=' . $id : 'action=add'));
        }
        $row = [
            'title' => $data['title'], 'category' => $data['category'], 'excerpt' => $data['excerpt'],
            'content' => clean_html($data['content']), 'author_name' => $data['author_name'] ?: null, 'status' => $data['status'],
            'published_at' => $data['published_at'] ?: ($post['published_at'] ?? null) ?: ($data['status'] === 'published' ? date('Y-m-d H:i:s') : null),
        ];
        if (!empty($upload['path'])) {
            $row['image'] = $upload['path'];
            if ($post) {
                delete_upload($post['image']);
            }
        }
        if ($post) {
            db_update('blog_posts', $row, 'id = :id', ['id' => $id]);
            admin_log('blog_updated', $data['title']);
        } else {
            $row['slug'] = unique_slug('blog_posts', $data['title']);
            $row['created_by'] = admin_id();
            $id = db_insert('blog_posts', $row);
            admin_log('blog_created', $data['title']);
        }
        flash('success', 'Post saved.');
        admin_redirect('blog.php?edit=' . $id);

    // -----------------------------------------------------------------
    case 'testimonial':
        $t = $id ? db_row('SELECT * FROM testimonials WHERE id = ?', [$id]) : null;
        if ($action === 'delete') {
            if ($t) {
                db_query('DELETE FROM testimonials WHERE id = ?', [$id]);
                delete_upload($t['photo']);
                admin_log('testimonial_deleted', $t['name']);
                flash('success', 'Testimonial deleted.');
            }
            admin_redirect('testimonials.php');
        }
        [$data, $errors] = validate($_POST, [
            'name' => 'required|min:2|max:120', 'designation' => 'max:150', 'message' => 'required|text|min:10|max:1500',
            'sort_order' => 'integer', 'status' => 'required|in:published,hidden',
        ]);
        $upload = !$errors ? handle_upload('photo', 'image', 'blog') : [];
        if (isset($upload['error'])) {
            $errors['photo'] = $upload['error'];
        }
        if ($errors) {
            content_back($errors, 'testimonials.php?' . ($id ? 'edit=' . $id : 'action=add'));
        }
        $row = ['name' => $data['name'], 'designation' => $data['designation'] ?: null, 'message' => $data['message'], 'sort_order' => (int) $data['sort_order'], 'status' => $data['status']];
        if (!empty($upload['path'])) {
            $row['photo'] = $upload['path'];
            if ($t) {
                delete_upload($t['photo']);
            }
        }
        if ($t) {
            db_update('testimonials', $row, 'id = :id', ['id' => $id]);
        } else {
            db_insert('testimonials', $row);
        }
        admin_log('testimonial_saved', $data['name']);
        flash('success', 'Testimonial saved. Publish only genuine testimonials shared with consent.');
        admin_redirect('testimonials.php');

    // -----------------------------------------------------------------
    case 'faq':
        if ($action === 'delete') {
            db_query('DELETE FROM faqs WHERE id = ?', [$id]);
            admin_log('faq_deleted', 'FAQ #' . $id);
            flash('success', 'FAQ deleted.');
            admin_redirect('faqs.php');
        }
        [$data, $errors] = validate($_POST, [
            'question' => 'required|min:5|max:300', 'answer' => 'required|text|min:5|max:3000',
            'category' => 'required|max:60', 'sort_order' => 'integer', 'status' => 'required|in:published,hidden',
        ]);
        if ($errors) {
            content_back($errors, 'faqs.php?' . ($id ? 'edit=' . $id : 'action=add'));
        }
        $row = ['question' => $data['question'], 'answer' => $data['answer'], 'category' => slugify($data['category']), 'sort_order' => (int) $data['sort_order'], 'status' => $data['status']];
        if ($id) {
            db_update('faqs', $row, 'id = :id', ['id' => $id]);
        } else {
            db_insert('faqs', $row);
        }
        admin_log('faq_saved', $data['question']);
        flash('success', 'FAQ saved.');
        admin_redirect('faqs.php');

    // -----------------------------------------------------------------
    case 'settings':
        $text = [
            'site_name' => 120, 'site_tagline' => 120, 'site_tagline_en' => 160, 'site_description' => 300, 'contact_email' => 190,
            'admin_notify_email' => 190, 'contact_phone' => 20, 'whatsapp_number' => 20, 'contact_address' => 255, 'office_hours' => 120,
            'legal_name' => 150, 'registration_type' => 80, 'registration_number' => 80, 'registration_date' => 40, 'pan_number' => 10,
            'reg_12a_number' => 80, 'reg_80g_number' => 80, 'csr1_number' => 40, 'darpan_id' => 40,
            'bank_account_name' => 150, 'bank_name' => 120, 'bank_account_number' => 30, 'bank_ifsc' => 11, 'bank_branch' => 120, 'upi_id' => 80,
            'receipt_signatory' => 120, 'receipt_footer_note' => 300, 'donation_presets' => 60, 'donation_min_amount' => 7,
        ];
        $errors = [];
        $values = [];
        foreach ($text as $key => $max) {
            $values[$key] = mb_substr(clean_string($_POST[$key] ?? ''), 0, $max);
        }
        foreach (['contact_email', 'admin_notify_email'] as $key) {
            if ($values[$key] !== '' && !filter_var($values[$key], FILTER_VALIDATE_EMAIL)) {
                $errors[$key] = 'Please enter a valid email address.';
            }
        }
        if ($values['pan_number'] !== '' && !preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', strtoupper($values['pan_number']))) {
            $errors['pan_number'] = 'Please enter a valid PAN.';
        }
        $values['pan_number'] = strtoupper($values['pan_number']);
        $values['bank_ifsc'] = strtoupper($values['bank_ifsc']);
        if ($values['bank_ifsc'] !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $values['bank_ifsc'])) {
            $errors['bank_ifsc'] = 'Please enter a valid IFSC code.';
        }
        if (!preg_match('/^\d+(,\d+)*$/', str_replace(' ', '', $values['donation_presets']))) {
            $errors['donation_presets'] = 'Use whole numbers separated by commas, e.g. 500,1000,2500,5000';
        }
        $values['donation_presets'] = str_replace(' ', '', $values['donation_presets']);
        if (!ctype_digit($values['donation_min_amount']) || (int) $values['donation_min_amount'] < 1) {
            $errors['donation_min_amount'] = 'Minimum donation must be a whole number of at least 1.';
        }
        foreach (['social_facebook', 'social_youtube', 'social_twitter', 'social_linkedin', 'social_instagram'] as $key) {
            $values[$key] = mb_substr(clean_string($_POST[$key] ?? ''), 0, 255);
            if ($values[$key] !== '' && !preg_match('#^https://#i', $values[$key])) {
                $errors[$key] = 'Social links must start with https://';
            }
        }
        $values['map_embed_url'] = mb_substr(clean_string($_POST['map_embed_url'] ?? ''), 0, 1000);
        if ($values['map_embed_url'] !== '' && !preg_match('#^https://(www\.)?google\.com/maps/embed#', $values['map_embed_url'])) {
            $errors['map_embed_url'] = 'Paste the src link from Google Maps "Embed a map" (starts with https://www.google.com/maps/embed).';
        }
        $values['staff_positions'] = mb_substr(clean_string($_POST['staff_positions'] ?? '', true), 0, 5000);
        $upload = !$errors ? handle_upload('logo', 'image', 'logo') : [];
        if (isset($upload['error'])) {
            $errors['logo'] = $upload['error'];
        }
        if ($errors) {
            content_back($errors, 'settings.php');
        }
        foreach ($values as $key => $value) {
            save_setting($key, $value);
        }
        if (!empty($upload['path'])) {
            delete_upload(setting('logo_path'));
            save_setting('logo_path', $upload['path']);
        } elseif (!empty($_POST['remove_logo'])) {
            delete_upload(setting('logo_path'));
            save_setting('logo_path', '');
        }
        admin_log('settings_updated', '');
        flash('success', 'Settings saved.');
        admin_redirect('settings.php');
}

admin_redirect('dashboard.php');
