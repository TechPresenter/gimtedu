<?php
/** Placement: recruiting companies (with hiring history counters). */
require_once APP_ROOT . '/app/services/placement.php';

$valid = PLACEMENT_VALID_OFFER_SQL;

return [
    'table' => 'companies',
    'title' => 'Companies',
    'singular' => 'Company',
    'permission' => 'placement',
    'icon' => 'building-2',
    'description' => 'Recruiters, their HR contacts, tie-up status and hiring history.',
    'select' => "t.*,
                 (SELECT COUNT(*) FROM placement_drives d WHERE d.company_id = t.id AND d.status <> 'cancelled') AS drives_count,
                 (SELECT COUNT(*) FROM placement_offers o WHERE o.company_id = t.id AND o.status IN $valid) AS offers_count,
                 (SELECT MAX(o.package_lpa) FROM placement_offers o WHERE o.company_id = t.id AND o.status IN $valid) AS highest_lpa,
                 (SELECT MAX(d.drive_date) FROM placement_drives d WHERE d.company_id = t.id AND d.status <> 'cancelled') AS last_drive_date",
    'search' => ['t.name', 't.industry', 't.city', 't.headquarters', 't.contact_person', 't.email'],
    'search_placeholder' => 'Search company, industry, city or HR contact…',
    'order' => 't.name ASC',
    'columns' => [
        ['key' => 'name', 'label' => 'Company', 'format' => 'person', 'image' => 'logo', 'sub' => 'industry', 'sortable' => true],
        ['key' => 'company_type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true, 'hidden' => true,
            'colors' => ['mnc' => 'navy', 'indian_mnc' => 'blue', 'large_indian' => 'blue', 'startup' => 'purple', 'psu' => 'cyan', 'government' => 'cyan', 'sme' => 'slate', 'ngo' => 'slate']],
        ['key' => 'tie_up_status', 'label' => 'Tie-up', 'format' => 'badge', 'sortable' => true, 'colors' => ['prospect' => 'amber', 'active' => 'green', 'mou_signed' => 'navy', 'dormant' => 'slate']],
        ['key' => 'contact_person', 'label' => 'HR contact', 'format' => 'title', 'sub' => 'contact_designation', 'sortable' => true],
        ['key' => 'email', 'label' => 'Email', 'format' => 'email', 'hidden' => true],
        ['key' => 'phone', 'label' => 'Phone', 'format' => 'phone', 'hidden' => true],
        ['key' => 'headquarters', 'label' => 'Headquarters', 'format' => 'text', 'hidden' => true, 'sortable' => true],
        ['key' => 'city', 'label' => 'Office city', 'format' => 'text', 'sortable' => true, 'hidden' => true],
        ['key' => 'drives_count', 'label' => 'Drives', 'format' => 'number', 'align' => 'center', 'sortable' => 'drives_count'],
        ['key' => 'offers_count', 'label' => 'Hires', 'format' => 'number', 'align' => 'center', 'sortable' => 'offers_count'],
        ['key' => 'highest_lpa', 'label' => 'Highest CTC', 'format' => 'number', 'suffix' => ' LPA', 'align' => 'right', 'sortable' => 'highest_lpa', 'hidden' => true],
        ['key' => 'last_drive_date', 'label' => 'Last drive', 'format' => 'date', 'sortable' => 'last_drive_date', 'hidden' => true],
        ['key' => 'website', 'label' => 'Website', 'format' => 'text', 'hidden' => true],
        ['key' => 'is_recruiter', 'label' => 'On website', 'format' => 'boolean', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true, 'hidden' => true],
    ],
    'export_columns' => [
        ['key' => 'name', 'label' => 'Company'], ['key' => 'industry', 'label' => 'Industry'], ['key' => 'company_type', 'label' => 'Type', 'format' => 'badge'],
        ['key' => 'tie_up_status', 'label' => 'Tie-up status', 'format' => 'badge'], ['key' => 'website', 'label' => 'Website'], ['key' => 'headquarters', 'label' => 'Headquarters'],
        ['key' => 'city', 'label' => 'Office city'], ['key' => 'contact_person', 'label' => 'HR contact'], ['key' => 'contact_designation', 'label' => 'Designation'],
        ['key' => 'email', 'label' => 'Email'], ['key' => 'phone', 'label' => 'Phone'], ['key' => 'drives_count', 'label' => 'Drives', 'format' => 'number'],
        ['key' => 'offers_count', 'label' => 'Hires', 'format' => 'number'], ['key' => 'highest_lpa', 'label' => 'Highest CTC (LPA)', 'format' => 'number'],
        ['key' => 'last_drive_date', 'label' => 'Last drive', 'format' => 'date'], ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
    ],
    'filters' => [
        ['key' => 'industry', 'label' => 'Industry', 'options' => placement_industries()],
        ['key' => 'company_type', 'label' => 'Type', 'options' => placement_company_types()],
        ['key' => 'tie_up_status', 'label' => 'Tie-up', 'options' => placement_tie_up_statuses()],
        ['key' => 'hiring', 'label' => 'Hiring history', 'options' => ['hired' => 'Has hired students', 'never' => 'Not hired yet', 'this_session' => 'Visited this session'],
            'sql_map' => [
                'hired' => "EXISTS (SELECT 1 FROM placement_offers o WHERE o.company_id = t.id AND o.status IN $valid)",
                'never' => "NOT EXISTS (SELECT 1 FROM placement_offers o WHERE o.company_id = t.id AND o.status IN $valid)",
                'this_session' => "EXISTS (SELECT 1 FROM placement_drives d JOIN academic_sessions ss ON ss.is_current = 1 WHERE d.company_id = t.id AND d.status <> 'cancelled' AND d.drive_date BETWEEN ss.start_date AND ss.end_date)",
            ]],
        ['key' => 'is_recruiter', 'label' => 'Shown on website', 'type' => 'boolean'],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'fields' => [
        ['name' => 's1', 'type' => 'section', 'label' => 'Company'],
        ['name' => 'name', 'label' => 'Company name', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 8, 'maxlength' => 190, 'placeholder' => 'e.g. Tata Consultancy Services'],
        ['name' => 'industry', 'label' => 'Industry', 'type' => 'select', 'options' => placement_industries(), 'col' => 4],
        ['name' => 'company_type', 'label' => 'Company type', 'type' => 'select', 'options' => placement_company_types(), 'col' => 4],
        ['name' => 'website', 'label' => 'Website', 'type' => 'url', 'col' => 8, 'placeholder' => 'https://'],
        ['name' => 'headquarters', 'label' => 'Headquarters (HQ)', 'type' => 'text', 'maxlength' => 150, 'placeholder' => 'e.g. Mumbai, India'],
        ['name' => 'city', 'label' => 'Nearest office city', 'type' => 'text', 'maxlength' => 80, 'placeholder' => 'e.g. Noida'],
        ['name' => 'address', 'label' => 'Office address', 'type' => 'text', 'col' => 12, 'maxlength' => 255],
        ['name' => 'logo', 'label' => 'Logo', 'type' => 'image', 'folder' => 'companies', 'col' => 12, 'help' => 'Square PNG/WebP works best.'],
        ['name' => 's2', 'type' => 'section', 'label' => 'HR / campus relations contact'],
        ['name' => 'contact_person', 'label' => 'Contact person', 'type' => 'text', 'maxlength' => 150],
        ['name' => 'contact_designation', 'label' => 'Designation', 'type' => 'text', 'maxlength' => 100, 'placeholder' => 'e.g. Talent Acquisition Lead'],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'maxlength' => 190],
        ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel'],
        ['name' => 's3', 'type' => 'section', 'label' => 'Relationship'],
        ['name' => 'tie_up_status', 'label' => 'Tie-up status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => placement_tie_up_statuses()],
        ['name' => 'status', 'label' => 'Record status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
        ['name' => 'is_recruiter', 'label' => 'Show in the website recruiters strip', 'type' => 'toggle', 'default' => true, 'col' => 12],
        ['name' => 'description', 'label' => 'About the company', 'type' => 'textarea', 'rows' => 3],
        ['name' => 'notes', 'label' => 'Internal notes', 'type' => 'textarea', 'rows' => 3, 'help' => 'Visible to the placement cell only (hiring preferences, bond terms, follow-ups…).'],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'import' => true,
    'export' => true,
    'form' => ['size' => 'xl'],
    'hooks' => [
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            $name = $data['name'] ?? ($old['name'] ?? '');
            if ($id === null || (isset($data['name']) && $old && $data['name'] !== $old['name'])) {
                $data['slug'] = placement_unique_slug($name, $id);
            }
            if (array_key_exists('tie_up_status', $data)) {
                $data['is_mou'] = $data['tie_up_status'] === 'mou_signed' ? 1 : 0;
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $drives = (int) db_value('SELECT COUNT(*) FROM placement_drives WHERE company_id = ?', [$id]);
            $offers = (int) db_value('SELECT COUNT(*) FROM placement_offers WHERE company_id = ?', [$id]);
            if ($drives || $offers) {
                return $row['name'] . ' has ' . implode(' and ', array_filter([$drives ? "$drives drive(s)" : null, $offers ? "$offers offer(s)" : null]))
                    . ' on record and cannot be deleted. Mark it inactive instead.';
            }
            return null;
        },
        'describe' => fn (array $row) => '"' . ($row['name'] ?? '#' . ($row['id'] ?? '')) . '"',
    ],
];
