<?php
/**
 * Home page. Everything below the header is built from the page's sections (Admin > Pages > Home):
 * hero slider, live impact counters, about, programmes, ways to get involved, campaigns, events, stories,
 * gallery, testimonials, articles, trust markers, newsletter and a closing call to action.
 * Sections from other modules render nothing while they have no published data.
 * The WebSite + Organisation JSON-LD is added by the header for the home template.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
page_header($page, ['banner' => false]);

ob_start();
render_sections($page);
$homeSections = ob_get_clean();

// The page always has exactly one main heading: if no hero slider rendered it, show the default hero.
if (empty($GLOBALS['home_h1_done'])) {
    echo home_hero_html(home_default_slides(), [], $page);
}
echo $homeSections;

if (trim($homeSections) === '') {
    echo '<section class="section"><div class="container">' . ui_split([
        'pill' => 'Who we are', 'title' => 'Empowering underprivileged communities',
        'text' => setting('site_description'),
        'image' => 'assets/images/hero/about.svg',
        'actions' => [['label' => 'About us', 'url' => '/about/', 'style' => 'primary'], ['label' => 'Our programs', 'url' => '/programs/', 'style' => 'outline']],
    ]) . '</div></section>';
    echo '<section class="section section-sm"><div class="container">' . ui_cta(setting('site_tagline_en', 'Together, let us build a better tomorrow'),
        'Give your time, share your skills or support a cause.', [
            ['label' => 'Donate Now', 'url' => '/donate/', 'style' => 'donate', 'icon' => 'heart', 'size' => 'lg'],
            ['label' => 'Become a Volunteer', 'url' => '/become-a-volunteer/', 'style' => 'light', 'size' => 'lg'],
        ], true) . '</div></section>';
}

page_footer();
