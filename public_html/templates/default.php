<?php
/**
 * Standard content page: banner + the page's sections (Admin > Pages > Sections).
 * Used by: about, our-mission-vision, achievements, awareness-campaign, beneficiary-support and any page
 * created in the admin with the "Standard content page" template.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
page_header($page);
render_sections($page);
if (!page_sections((int) $page['id'])) {
    echo '<section class="section"><div class="container narrow">' . empty_state('This page is being updated. Please check back soon.', 'Go to homepage', '/') . '</div></section>';
}
page_footer();
