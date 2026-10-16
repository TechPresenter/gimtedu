<?php
/**
 * Photo gallery (/gallery/, page setting {"media_type":"image"}) and video gallery
 * (/video-gallery/, {"media_type":"video"}). Category chips filter on the page (script.js),
 * photos open in the lightbox, videos load the YouTube player only after a click (media.js).
 * Only published items whose consent confirmation is recorded are shown.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
if (route_param() !== '') {
    not_found();
}
$mediaType = page_setting($page, 'media_type', 'image') === 'video' ? 'video' : 'image';
$where = "media_type = ? AND status = 'published' AND consent_confirmed = 1";
$total = (int) db_value("SELECT COUNT(*) FROM gallery_items WHERE $where", [$mediaType]);
$p = paginate($total, $mediaType === 'video' ? 24 : 60);
$items = db_rows("SELECT * FROM gallery_items WHERE $where ORDER BY sort_order, id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", [$mediaType]);
$counts = [];
foreach ($items as $g) {
    $counts[$g['category']] = ($counts[$g['category']] ?? 0) + 1;
}
$otherSlug = $mediaType === 'video' ? 'gallery' : 'video-gallery';
$otherPage = get_page($otherSlug);

page_header($page);
render_sections($page, function () use ($items, $p, $counts, $mediaType, $otherPage, $otherSlug) {
    echo '<section class="section gallery-section" id="gallery"><div class="container">';
    echo '<h2 class="sr-only">' . ($mediaType === 'video' ? 'Videos' : 'Photos') . '</h2>';
    echo '<div class="list-toolbar">';
    if ($otherPage) {
        echo '<nav class="seg-tabs" aria-label="Gallery type">'
            . '<a href="' . e(page_url('gallery')) . '"' . ($mediaType === 'image' ? ' class="is-active" aria-current="page"' : '') . '>' . icon('image') . 'Photos</a>'
            . '<a href="' . e(page_url('video-gallery')) . '"' . ($mediaType === 'video' ? ' class="is-active" aria-current="page"' : '') . '>' . icon('play') . 'Videos</a></nav>';
    }
    if (count($counts) > 1) {
        echo '<div class="filter-bar list-filter" role="group" aria-label="Filter by category">'
            . '<button type="button" class="filter-btn is-active" data-filter="all" aria-pressed="true">All <span class="count">' . array_sum($counts) . '</span></button>';
        foreach (GALLERY_CATEGORIES as $key => $label) {
            if (!empty($counts[$key])) {
                echo '<button type="button" class="filter-btn" data-filter="' . e($key) . '" aria-pressed="false">' . e($label) . ' <span class="count">' . $counts[$key] . '</span></button>';
            }
        }
        echo '</div>';
    }
    echo '</div>';

    $html = '';
    foreach ($items as $i => $g) {
        $html .= $mediaType === 'video' ? video_item_html($g, $i) : gallery_item_html($g, $i);
    }
    if ($html === '') {
        echo empty_state($mediaType === 'video'
            ? 'Videos of our programmes and events will be added here soon.'
            : 'Photos from our programmes and events will be added here soon. We publish photos of people only with their consent.',
            $mediaType === 'video' && $otherPage ? 'See the photo gallery' : 'See our events', $mediaType === 'video' && $otherPage ? '/gallery/' : '/events/');
    } elseif ($mediaType === 'video') {
        echo '<div class="video-grid">' . $html . '</div>';
        echo '<p class="video-note">' . icon('shield') . '<span>Videos play from YouTube in privacy-enhanced mode and load only when you press play.</span></p>';
    } else {
        echo '<div class="media-masonry">' . $html . '</div>' . content_lightbox();
    }
    echo pagination_links($p);
    echo '</div></section>';
}, 'before');
page_footer();
