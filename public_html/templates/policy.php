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
page_header($page, ['schema' => [[
    '@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $page['title'],
    'dateModified' => date('c', strtotime($page['updated_at'] ?: $page['created_at'])),
]]]);
render_policy_layout($page, policy_links($page['slug']));
foreach (page_sections((int) $page['id']) as $s) {
    if ($s['section_type'] !== 'richtext') {
        echo render_section($s, $page);
    }
}
page_footer();
