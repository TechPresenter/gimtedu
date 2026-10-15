<?php
/**
 * Documents list (certificates, impact/annual reports, digital resources ...).
 * Page settings: {"category":"impact_report","also":["annual_report"]}. Documents come from
 * Admin > Certificates & Reports and are grouped by year (document date).
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$cats = array_merge([(string) page_setting($page, 'category', '')], (array) page_setting($page, 'also', []));
$cats = array_values(array_unique(array_filter(array_map('strval', $cats), function ($c) {
    return isset(RESOURCE_CATEGORIES[$c]);
})));
$docs = $cats ? resources_by_category($cats) : [];

$byYear = [];
foreach ($docs as $d) {
    $year = !empty($d['document_date']) ? substr($d['document_date'], 0, 4) : (!empty($d['created_at']) ? substr($d['created_at'], 0, 4) : 'Other');
    $byYear[$year][] = $d;
}
krsort($byYear, SORT_STRING);

$emptyText = [
    'certification'       => 'Registration and recognition certificates will be published here once available.',
    'impact_report'       => 'Reports will be published here once available.',
    'annual_report'       => 'Reports will be published here once available.',
    'financial_statement' => 'Financial statements will be published here once available.',
    'digital_resource'    => 'Guides and learning resources will be published here once available.',
    'press_kit'           => 'Press resources will be published here once available.',
    'utilization_report'  => 'Utilisation reports will be published here once available.',
][$cats[0] ?? ''] ?? 'Documents will be published here once available.';

page_header($page);
render_sections($page, function () use ($byYear, $docs, $cats, $emptyText) {
    echo '<section class="section docs-section" id="documents"><div class="container narrow">';
    if (!$docs) {
        echo empty_state($emptyText, 'Ask us a question', '/contact/');
    } else {
        $multi = count($cats) > 1;
        echo '<div class="docs-summary"><span>' . icon('file-text') . count($docs) . ' ' . (count($docs) === 1 ? 'document' : 'documents') . '</span>'
            . '<span class="muted small">Documents open in a new tab. PDF files can be saved or printed from your browser.</span></div>';
        foreach ($byYear as $year => $rows) {
            echo '<div class="docs-year"><h2 class="h3 docs-year-title">' . e($year === 'Other' ? 'Other documents' : (string) $year) . '</h2>' . document_list($rows, $multi) . '</div>';
        }
    }
    echo '</div></section>';
});
page_footer();
