<?php
/**
 * Content module (M6): blog / news / press, success stories, events & awareness calendar, photo & video gallery.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

// ---------------------------------------------------------------------
// Cards for events and blog posts
// ---------------------------------------------------------------------
function event_card(array $ev): string
{
    $link = url('event-details.php?slug=' . urlencode($ev['slug']));
    $ts = strtotime($ev['start_datetime']);
    return '<article class="event-card">'
        . '<a class="event-media" href="' . e($link) . '"><img src="' . e(image_url($ev['image'], 'assets/images/programs/general.svg')) . '" alt="' . e($ev['title']) . '" loading="lazy">'
        . '<span class="event-date"><strong>' . date('d', $ts) . '</strong>' . date('M', $ts) . '</span></a>'
        . '<div class="event-body"><h3><a href="' . e($link) . '">' . e($ev['title']) . '</a></h3>'
        . '<ul class="meta-list"><li>' . icon('clock') . e(date('D, d M Y · h:i A', $ts)) . '</li>'
        . '<li>' . icon('map-pin') . e($ev['location']) . '</li></ul>'
        . '<p>' . e(excerpt($ev['summary'], 120)) . '</p>'
        . '<a class="link-arrow" href="' . e($link) . '">View details ' . icon('arrow-right') . '</a></div></article>';
}

function blog_card(array $post): string
{
    $link = url('blog-details.php?slug=' . urlencode($post['slug']));
    return '<article class="blog-card">'
        . '<a class="blog-media" href="' . e($link) . '"><img src="' . e(image_url($post['image'], 'assets/images/programs/general.svg')) . '" alt="' . e($post['title']) . '" loading="lazy"></a>'
        . '<div class="blog-body"><span class="eyebrow">' . e(BLOG_CATEGORIES[$post['category']] ?? 'Blog') . ' · ' . e(format_date($post['published_at'])) . '</span>'
        . '<h3><a href="' . e($link) . '">' . e($post['title']) . '</a></h3>'
        . '<p>' . e(excerpt($post['excerpt'], 140)) . '</p>'
        . '<a class="link-arrow" href="' . e($link) . '">Read more ' . icon('arrow-right') . '</a></div></article>';
}
