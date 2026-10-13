<?php
/**
 * Homepage (/) — every section is driven by Website CMS › Homepage (table homepage_sections: enabled sections render
 * in sort_order, see docs/CMS.md §1). Section renderers, data queries and JSON-LD live in includes/partials/home.php;
 * the islands they use are documented in docs/HOMEPAGE.md.
 */
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/partials/home.php';

$sections = hm_sections();

site_header([
    'active' => 'home',
    'seo' => ['route', '/'],
    'description' => 'Global Institute of Management & Technology (GIMT), Greater Noida — industry-focused UG, PG, diploma and certificate programs in management, technology, commerce and computer applications, with scholarships, placements and a green campus.',
    'image' => 'assets/images/site/hero-campus-students.jpg',
    'preload' => hm_preload($sections),
    'schema' => hm_schema($sections),
]);

$prev = null;
foreach ($sections as $key => $section) {
    echo hm_render($key, $section, $prev);
    $prev = $key;
}

// the homepage's own CTA section (with the Smart Enquiry form) replaces the footer CTA band
site_footer(['cta' => !isset($sections['cta'])]);
