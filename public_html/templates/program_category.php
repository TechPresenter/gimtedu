<?php
/**
 * Programs & Events category page (template "program_category", /programs/<category-slug>/).
 * The category is the program_categories row linked to this page (page_id). Content comes from the page's sections
 * (default: about, topics, upcoming, past, who can participate, benefits, registration, FAQs, partnership, call to action) -
 * live blocks are the section types program_events, program_registration and partnership_enquiry (includes/programhub.php).
 * Hero = page banner (title, subtitle, image, buttons). SEO: page meta + CollectionPage / ItemList JSON-LD; the FAQ
 * section adds FAQPage data through the site-wide FAQ rule (faq_schema()).
 *
 * Forms posted here (field `form`): program_register (registration for one of the open sessions of this category),
 * program_interest (register interest when nothing suitable is open), partnership (partnership enquiry).
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$category = program_category_for_page((int) $page['id']);
$staff = staff_session_can('programs.manage') || staff_session_can('pages.manage');
if (!$category || ($category['status'] !== 'published' && !$staff)) {
    not_found();
}
$self = page_url($page['slug']);

// ---------------------------------------------------------------------
// Forms (POST -> redirect)
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify($self);
    if ($category['status'] !== 'published' || $page['status'] !== 'published') {
        flash('error', 'This page is a preview. Forms work once it is published.');
        redirect($self);
    }
    $form = post_str('form');
    if ($form === 'program_register') {
        $eventId = (int) post_str('event_id');
        $chosen = null;
        foreach (programhub_events(['when' => 'open', 'limit' => 100, 'category_id' => (int) $category['id']]) as $ev) {
            if ((int) $ev['id'] === $eventId) {
                $chosen = $ev;
                break;
            }
        }
        if (!$chosen) {
            if (honeypot_triggered()) {
                flash('success', 'Thank you. Your registration has been received.');
                redirect($self);
            }
            back_with_errors(['event_id' => $eventId ? 'Registration for this session has just closed. Please choose another session.' : 'Please choose a session.'], $self . '#register', $_POST);
        }
        programhub_handle_registration($chosen, 'category_page', $self);
    } elseif ($form === 'program_interest' && (int) $category['interest_enabled'] === 1) {
        programhub_handle_interest($category, $self);
    } elseif ($form === 'partnership' && (int) $category['partnership_enabled'] === 1) {
        programhub_handle_partnership($category, $self);
    }
    redirect($self);
}

// ---------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------
$canonical = $page['canonical_url'] ?: abs_page_url($page['slug']);
$upcoming = programhub_events(['category_id' => (int) $category['id'], 'when' => 'upcoming', 'limit' => 20, 'include_cancelled' => false]);
$list = [];
foreach ($upcoming as $i => $ev) {
    $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => event_abs_url($ev), 'name' => $ev['title']];
}
$org = ['@id' => abs_url('#organization')];
$schema = [[
    '@context' => 'https://schema.org', '@type' => 'CollectionPage', '@id' => $canonical . '#webpage', 'url' => $canonical,
    'name' => $page['meta_title'] ?: $page['title'], 'description' => $page['meta_description'] ?: $category['card_text'],
    'inLanguage' => 'en-IN', 'about' => ['@type' => 'Thing', 'name' => $category['title']], 'publisher' => $org,
    'dateModified' => date('c', strtotime($page['updated_at'] ?: $page['created_at'])),
] + ($list ? ['mainEntity' => ['@type' => 'ItemList', 'name' => 'Upcoming ' . $category['title'] . ' sessions', 'itemListElement' => $list]] : [])];

$opts = [
    'canonical' => $canonical,
    'eyebrow' => $page['banner_eyebrow'] ?: 'Programs & Events',
    'subtitle' => (string) ($page['banner_subtitle'] ?: $category['card_text']),
    'meta_description' => $page['meta_description'] ?: $category['card_text'],
    'schema' => $schema,
];
if (!json_field($page['banner_buttons'] ?? null)) {
    $opts['buttons'] = [
        ['label' => 'Register', 'url' => '#register', 'style' => 'donate', 'icon' => 'check'],
        ['label' => 'Explore events', 'url' => '#upcoming', 'style' => 'light', 'icon' => 'calendar'],
    ];
}
if (empty($page['banner_image'])) {
    $opts['image'] = program_category_art($category);
}
if ($category['status'] !== 'published') {
    $opts['noindex'] = true;
}
page_header($page, $opts);

if ($category['status'] !== 'published') {
    echo '<div class="container"><div class="alert alert-warning mt-2">' . icon('eye') . '<div>Staff preview: this category is a <strong>draft</strong>. Visitors cannot see it.</div></div></div>';
}

// On-page navigation from the section anchors (unless the editors placed a "subnav" section); short labels for the standard anchors
$short = [
    'about' => 'About', 'topics' => 'Topics', 'upcoming' => 'Upcoming', 'past' => 'Past programmes', 'who-can-participate' => 'Who can join',
    'benefits' => 'Benefits', 'register' => 'Register', 'faqs' => 'FAQs', 'partner' => 'Partner with us',
];
$links = [];
$hasSubnav = false;
foreach (page_sections((int) $page['id']) as $s) {
    if ($s['section_type'] === 'subnav') {
        $hasSubnav = true;
    }
    $anchor = preg_replace('/[^a-z0-9\-_]/i', '', (string) $s['anchor']);
    if ($anchor !== '' && !empty($s['title']) && $s['section_type'] !== 'cta') {
        $links[$anchor] = $short[$anchor] ?? (string) $s['title'];
    }
}
if (!$hasSubnav && count($links) > 2) {
    echo ui_subnav($links);
}

render_sections($page);
page_footer();
