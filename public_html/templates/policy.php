<?php
/**
 * Policy / long-form page (privacy, terms, donation & refund, volunteer, child protection):
 * all "Text" sections are joined into one document with a sticky table of contents.
 * Other section types (callouts, FAQs, CTA) are rendered below it.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$policySchema = [
    '@context' => 'https://schema.org', '@type' => 'WebPage', '@id' => abs_page_url($page['slug']) . '#webpage',
    'url' => abs_page_url($page['slug']), 'name' => $page['title'], 'inLanguage' => 'en-IN',
    'dateModified' => date('c', strtotime($page['updated_at'] ?: $page['created_at'])),
    'publisher' => ['@id' => abs_url('#organization')],
];
if (!empty($page['meta_description'])) {
    $policySchema['description'] = (string) $page['meta_description'];
}
page_header($page, ['schema' => [$policySchema]]);
render_policy_layout($page, policy_links($page['slug']));
foreach (page_sections((int) $page['id']) as $s) {
    if ($s['section_type'] !== 'richtext') {
        echo render_section($s, $page);
    }
}
page_footer();
