<?php
/**
 * Information module (M8): NGO details, team, documents & resources, testimonials, FAQs,
 * contact / feedback / grievance / fraud-report forms, newsletter.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** Registration details entered by the admin (only non-empty values). */
function ngo_details(): array
{
    $fields = [
        'legal_name' => 'Legal name',
        'registration_type' => 'Registered as',
        'registration_number' => 'Registration number',
        'registration_date' => 'Registration date',
        'pan_number' => 'PAN',
        'reg_12a_number' => '12A registration',
        'reg_80g_number' => '80G registration',
        'csr1_number' => 'CSR-1 registration',
        'darpan_id' => 'NITI Aayog Darpan ID',
        'contact_address' => 'Registered office',
        'contact_email' => 'Email',
    ];
    $details = [];
    foreach ($fields as $key => $label) {
        if (setting($key) !== '') {
            $details[$label] = setting($key);
        }
    }
    return $details;
}

/** Public PDF documents uploaded in Admin > Pages (reports, certificates). */
function public_documents(?string $category = null): array
{
    $docs = json_decode(setting('public_documents', '[]'), true) ?: [];
    $docs = array_filter($docs, function ($d) use ($category) {
        return is_array($d) && !empty($d['path']) && is_file(ROOT_PATH . '/' . $d['path'])
            && ($category === null || ($d['category'] ?? '') === $category);
    });
    usort($docs, function ($a, $b) {
        return strcmp($b['date'] ?? '', $a['date'] ?? '');
    });
    return $docs;
}

const DOCUMENT_CATEGORIES = [
    'impact' => 'Impact / Annual Report',
    'financial' => 'Financial Statement',
    'registration' => 'Registration Certificate',
];

function document_list(array $docs): string
{
    $html = '<ul class="doc-list">';
    foreach ($docs as $d) {
        $html .= '<li><a href="' . e(url($d['path'])) . '" target="_blank" rel="noopener">'
            . '<span class="icon-circle">' . icon('file-text') . '</span><span><strong>' . e($d['title']) . '</strong><br>'
            . '<small class="muted">' . e(DOCUMENT_CATEGORIES[$d['category']] ?? 'Document') . (!empty($d['date']) ? ' · ' . e(format_date($d['date'])) : '') . ' · PDF</small></span></a></li>';
    }
    return $html . '</ul>';
}
