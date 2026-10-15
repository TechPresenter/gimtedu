<?php
/**
 * Programmes overview (/programs/): the page's sections plus the full programme directory
 * (filter chips by focus area + premium cards) at the "module" section position.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$programs = programs_all();

$list = [];
foreach ($programs as $i => $p) {
    $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $p['title'], 'url' => abs_url(ltrim(substr(program_url($p), strlen(base_path())), '/'))];
}
page_header($page, ['schema' => $list ? [[
    '@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => $page['title'], 'itemListElement' => $list,
]] : []]);

render_sections($page, function () use ($page, $programs): void {
    $anchor = 'all-programmes';
    $title = 'Explore our programmes';
    $intro = 'Each programme is run with volunteers and community members. Choose an area to see what we do and how you can help.';
    foreach (page_sections((int) $page['id']) as $s) {
        if ($s['section_type'] === 'module') {
            $anchor = preg_replace('/[^a-z0-9\-_]/i', '', (string) ($s['anchor'] ?: $anchor));
            $title = (string) ($s['title'] ?: $title);
            $intro = (string) ($s['intro'] ?: $intro);
            break;
        }
    }
    echo '<section class="section section-cream program-directory" id="' . e($anchor) . '"><div class="container">' . ui_heading($title, 'Our work', $intro, true);
    if (!$programs) {
        echo empty_state('Details of our programmes will be published here soon.', 'Contact us', '/contact/') . '</div></section>';
        return;
    }
    $groups = program_focus_groups();
    $used = [];
    foreach ($programs as $p) {
        $used[program_focus($p)] = true;
    }
    if (count($used) > 1) {
        echo '<div class="filter-bar program-filter" role="group" aria-label="Filter programmes by focus area">'
            . '<button type="button" class="filter-btn is-active" data-filter="all" aria-pressed="true">All programmes</button>';
        foreach ($groups as $key => $label) {
            if (isset($used[$key])) {
                echo '<button type="button" class="filter-btn" data-filter="' . e($key) . '" aria-pressed="false">' . e($label) . '</button>';
            }
        }
        echo '</div>';
    }
    echo '<div class="program-grid program-grid-all">';
    foreach ($programs as $i => $p) {
        echo program_card($p, ['index' => $i, 'filter' => true]);
    }
    echo '</div></div></section>';
});
page_footer();
