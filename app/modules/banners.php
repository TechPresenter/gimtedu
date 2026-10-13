<?php
/** Website CMS › Banners / Sliders (docs/CMS.md §4). */
require_once APP_ROOT . '/app/services/cms.php';

$liveState = "CASE WHEN t.status <> 'active' THEN 'inactive'
                   WHEN t.starts_at IS NOT NULL AND t.starts_at > NOW() THEN 'scheduled'
                   WHEN t.ends_at IS NOT NULL AND t.ends_at < NOW() THEN 'expired'
                   ELSE 'live' END";

return [
    'table' => 'banners',
    'title' => 'Banners',
    'singular' => 'Banner',
    'permission' => 'cms',
    'icon' => 'images',
    'description' => 'Hero slides and promotional banners with schedules.',
    'select' => "t.*, $liveState AS live_state",
    'search' => ['t.title', 't.highlight', 't.subheading', 't.cta_text'],
    'order' => "FIELD(t.placement, 'home_hero', 'page_header', 'popup', 'strip'), t.sort_order, t.id",
    'columns' => [
        ['key' => 'desktop_image', 'label' => 'Image', 'format' => 'image', 'width' => '84px'],
        ['key' => 'title', 'label' => 'Banner', 'format' => 'title', 'sub' => 'highlight', 'sortable' => true, 'link' => 'view'],
        ['key' => 'placement', 'label' => 'Placement', 'format' => 'badge', 'sortable' => true,
            'colors' => ['home_hero' => 'navy', 'page_header' => 'blue', 'popup' => 'purple', 'strip' => 'cyan']],
        ['key' => 'cta_text', 'label' => 'Button', 'format' => 'text', 'hidden' => true],
        ['key' => 'starts_at', 'label' => 'Starts', 'format' => 'datetime', 'sortable' => true],
        ['key' => 'ends_at', 'label' => 'Ends', 'format' => 'datetime', 'sortable' => true],
        ['key' => 'sort_order', 'label' => 'Order', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'live_state', 'label' => 'Schedule', 'format' => 'badge', 'sortable' => 'live_state',
            'colors' => ['live' => 'green', 'scheduled' => 'amber', 'expired' => 'slate', 'inactive' => 'red']],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true, 'hidden' => true],
    ],
    'filters' => [
        ['key' => 'placement', 'label' => 'Placement', 'options' => cms_banner_placements()],
        ['key' => 'live_state', 'label' => 'Schedule', 'sql_map' => [
            'live' => "t.status = 'active' AND (t.starts_at IS NULL OR t.starts_at <= NOW()) AND (t.ends_at IS NULL OR t.ends_at >= NOW())",
            'scheduled' => "t.status = 'active' AND t.starts_at > NOW()",
            'expired' => "t.status = 'active' AND t.ends_at < NOW()",
            'inactive' => "t.status <> 'active'",
        ], 'options' => ['live' => 'Live now', 'scheduled' => 'Scheduled', 'expired' => 'Expired', 'inactive' => 'Inactive']],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'fields' => [
        ['type' => 'section', 'label' => 'Content'],
        ['name' => 'placement', 'label' => 'Placement', 'type' => 'select', 'required' => true, 'default' => 'home_hero', 'col' => 6, 'options' => cms_banner_placements()],
        ['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text', 'maxlength' => 120, 'col' => 6, 'placeholder' => 'e.g. Admissions 2026-27'],
        ['name' => 'title', 'label' => 'Heading', 'type' => 'text', 'required' => true, 'maxlength' => 190, 'col' => 6],
        ['name' => 'highlight', 'label' => 'Highlighted line', 'type' => 'text', 'maxlength' => 190, 'col' => 6, 'help' => 'Second line in the accent colour.'],
        ['name' => 'subheading', 'label' => 'Sub-heading', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 500],
        ['type' => 'section', 'label' => 'Images', 'help' => 'Desktop at least 1600×800 px; mobile (optional) at least 800×1000 px.'],
        // image paths chosen with the CMS image picker (campus photos, media library or a new upload) — see BannersPage
        ['name' => 'desktop_image', 'label' => 'Desktop image', 'type' => 'text', 'required' => true, 'maxlength' => 255, 'col' => 6],
        ['name' => 'mobile_image', 'label' => 'Mobile image', 'type' => 'text', 'maxlength' => 255, 'col' => 6],
        ['name' => 'image_alt', 'label' => 'Image description (alt text)', 'type' => 'text', 'maxlength' => 190, 'help' => 'Describe the photo for screen readers.'],
        ['type' => 'section', 'label' => 'Buttons'],
        ['name' => 'cta_text', 'label' => 'Primary button text', 'type' => 'text', 'maxlength' => 60, 'col' => 6, 'placeholder' => 'Apply Now'],
        ['name' => 'cta_url', 'label' => 'Primary button link', 'type' => 'text', 'maxlength' => 255, 'col' => 6, 'placeholder' => '/apply'],
        ['name' => 'cta2_text', 'label' => 'Secondary button text', 'type' => 'text', 'maxlength' => 60, 'col' => 6, 'placeholder' => 'Explore Programs'],
        ['name' => 'cta2_url', 'label' => 'Secondary button link', 'type' => 'text', 'maxlength' => 255, 'col' => 6, 'placeholder' => '/programs'],
        ['type' => 'section', 'label' => 'Schedule'],
        ['name' => 'starts_at', 'label' => 'Show from', 'type' => 'datetime', 'col' => 4, 'help' => 'Empty = immediately.'],
        ['name' => 'ends_at', 'label' => 'Show until', 'type' => 'datetime', 'col' => 4, 'help' => 'Empty = no end date.'],
        ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 9999, 'col' => 2],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'col' => 2, 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'export' => true,
    'permissions' => ['export' => ['cms', 'view']],
    'form' => ['size' => 'xl'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $v = fn (string $k) => array_key_exists($k, $data) ? $data[$k] : ($old[$k] ?? null);
            foreach ([['cta_text', 'cta_url', 'primary'], ['cta2_text', 'cta2_url', 'secondary']] as [$t, $u, $label]) {
                if ($v($t) && !$v($u)) {
                    $errors[$u] = "Add a link for the $label button.";
                } elseif ($v($u) && !$v($t)) {
                    $errors[$t] = "Add the text of the $label button.";
                } elseif ($v($u) && !cms_valid_url((string) $v($u))) {
                    $errors[$u] = 'Enter a valid link, e.g. /apply or https://example.com.';
                }
            }
            foreach (['desktop_image', 'mobile_image'] as $img) {
                if ($v($img) && !cms_valid_image((string) $v($img))) {
                    $errors[$img] = 'Choose an image from the media library or upload one.';
                }
            }
            if ($v('starts_at') && $v('ends_at') && strtotime((string) $v('ends_at')) <= strtotime((string) $v('starts_at'))) {
                $errors['ends_at'] = 'The end date must be after the start date.';
            }
            return $errors;
        },
        'after_save' => function (int $id, array $data, ?array $old): void {
            if (!$old && empty($data['sort_order'])) {
                $next = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM banners WHERE placement = ? AND id <> ?', [$data['placement'] ?? 'home_hero', $id]);
                db_exec('UPDATE banners SET sort_order = ? WHERE id = ?', [$next, $id]);
            }
        },
    ],
];
