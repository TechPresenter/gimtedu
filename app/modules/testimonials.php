<?php
/** Testimonials / success stories from students, alumni, parents and recruiters (website). */
require_once APP_ROOT . '/app/services/media.php';

$types = ['student' => 'Student', 'alumni' => 'Alumni', 'parent' => 'Parent', 'recruiter' => 'Recruiter'];

return [
    'table' => 'testimonials',
    'title' => 'Testimonials',
    'singular' => 'Testimonial',
    'permission' => 'cms',
    'icon' => 'quote',
    'description' => 'Quotes and video stories shown on the home, program and campus pages.',
    'select' => 't.*, p.short_name AS program_short, p.name AS program_name',
    'joins' => 'LEFT JOIN programs p ON p.id = t.program_id',
    'search' => ['t.name', 't.designation', 't.company', 't.content', 't.batch', 'p.short_name', 'p.name'],
    'search_placeholder' => 'Search name, company, quote…',
    'order' => 't.sort_order ASC, t.id DESC',
    'per_page' => 50,
    'columns' => [
        ['key' => 'name', 'label' => 'Name', 'format' => 'person', 'image' => 'photo', 'sub' => 'designation', 'sortable' => true],
        ['key' => 'type', 'label' => 'Role', 'format' => 'badge', 'sortable' => true, 'colors' => ['student' => 'blue', 'alumni' => 'green', 'parent' => 'amber', 'recruiter' => 'purple']],
        ['key' => 'program_short', 'label' => 'Program', 'format' => 'text', 'sortable' => 'p.short_name'],
        ['key' => 'batch', 'label' => 'Batch', 'format' => 'text', 'sortable' => true, 'hidden' => true],
        ['key' => 'company', 'label' => 'Company', 'format' => 'text', 'sortable' => true, 'hidden' => true],
        ['key' => 'content', 'label' => 'Quote', 'format' => 'truncate', 'truncate' => 70, 'hidden' => true],
        ['key' => 'rating', 'label' => 'Rating', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'is_featured', 'label' => 'Featured', 'format' => 'boolean', 'sortable' => true, 'hidden' => true],
        ['key' => 'sort_order', 'label' => 'Order', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'type', 'label' => 'Role', 'options' => $types],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs'],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
        ['key' => 'is_featured', 'label' => 'Featured', 'type' => 'boolean'],
        ['key' => 'media', 'label' => 'Media', 'sql_map' => ['video' => "t.video_url IS NOT NULL AND t.video_url <> ''", 'photo' => "t.photo IS NOT NULL AND t.photo <> ''", 'none' => "(t.photo IS NULL OR t.photo = '')"],
            'options' => ['video' => 'Has video', 'photo' => 'Has photo', 'none' => 'No photo']],
    ],
    'fields' => [
        ['name' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true, 'maxlength' => 150, 'col' => 6],
        ['name' => 'type', 'label' => 'Role', 'type' => 'select', 'required' => true, 'default' => 'student', 'options' => $types, 'col' => 6],
        ['name' => 'program_id', 'label' => 'Program', 'type' => 'select', 'source' => 'programs', 'col' => 6],
        ['name' => 'batch', 'label' => 'Batch', 'type' => 'text', 'maxlength' => 20, 'placeholder' => '2023-26', 'col' => 6],
        ['name' => 'designation', 'label' => 'Designation / role', 'type' => 'text', 'maxlength' => 190, 'placeholder' => 'e.g. Business Analyst', 'col' => 6],
        ['name' => 'company', 'label' => 'Company / organisation', 'type' => 'text', 'maxlength' => 150, 'col' => 6],
        ['name' => 'content', 'label' => 'Quote', 'type' => 'textarea', 'required' => true, 'rows' => 4, 'maxlength' => 1200, 'rules' => 'min:20'],
        ['name' => 'photo', 'label' => 'Photo', 'type' => 'text', 'maxlength' => 255, 'col' => 12],
        ['name' => 'rating', 'label' => 'Rating', 'type' => 'number', 'required' => true, 'default' => 5, 'min' => 1, 'max' => 5, 'col' => 4],
        ['name' => 'video_url', 'label' => 'Video URL', 'type' => 'url', 'maxlength' => 255, 'placeholder' => 'https://www.youtube.com/watch?v=…', 'col' => 8],
        ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'min' => 0, 'max' => 99999, 'col' => 4, 'help' => 'Empty = last.'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'col' => 4],
        ['name' => 'is_featured', 'label' => 'Featured', 'type' => 'toggle', 'default' => false, 'placeholder' => 'Feature on the home page', 'col' => 4],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'import' => true,
    'export' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data): array {
            $errors = [];
            if (array_key_exists('photo', $data) && !media_valid_ref($data['photo'])) {
                $errors['photo'] = 'Choose the photo from the media library.';
            }
            if (!empty($data['video_url']) && !preg_match('#^https?://#i', (string) $data['video_url'])) {
                $errors['video_url'] = 'Video URL must start with https:// (YouTube, Vimeo or an MP4 link).';
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            if (array_key_exists('sort_order', $data) && $data['sort_order'] === null) {
                $data['sort_order'] = $old ? (int) $old['sort_order'] : (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM testimonials');
            }
            return $data;
        },
        'bulk_action' => function (string $action, array $ids, array $input): array {
            switch ($action) {
                case 'reorder':
                    $n = cms_reorder('testimonials', $ids);
                    log_activity('update', 'cms', null, 'Reordered ' . count($ids) . ' testimonials');
                    return ['updated' => $n, 'message' => 'Testimonial order saved.'];
                case 'feature':
                case 'unfeature':
                    $val = $action === 'feature' ? 1 : 0;
                    $n = db_exec('UPDATE testimonials SET is_featured = ? WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')', [$val]);
                    log_activity('update', 'cms', implode(',', array_slice($ids, 0, 20)), ($val ? 'Featured ' : 'Un-featured ') . count($ids) . ' testimonial(s)');
                    return ['updated' => $n, 'message' => count($ids) . ' testimonial' . (count($ids) === 1 ? '' : 's') . ($val ? ' featured.' : ' removed from featured.')];
            }
            throw new CrudException('Unknown bulk action.');
        },
        // Overall counts (independent of the current filters) for the KPI row and role pills.
        'summary' => function (string $fromSql, array $args): array {
            $byType = db_pairs('SELECT type, COUNT(*) FROM testimonials GROUP BY type');
            return [
                'total' => (int) array_sum($byType),
                'active' => (int) db_value("SELECT COUNT(*) FROM testimonials WHERE status = 'active'"),
                'featured' => (int) db_value("SELECT COUNT(*) FROM testimonials WHERE is_featured = 1 AND status = 'active'"),
                'with_video' => (int) db_value("SELECT COUNT(*) FROM testimonials WHERE video_url IS NOT NULL AND video_url <> ''"),
                'avg_rating' => round((float) db_value("SELECT COALESCE(AVG(rating), 0) FROM testimonials WHERE status = 'active'"), 1),
                'by_type' => array_map('intval', $byType),
            ];
        },
        'describe' => fn (array $row): string => '"' . ($row['name'] ?? '') . '"',
    ],
];
