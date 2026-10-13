<?php
/** Photo / video galleries (albums) for the website. Items are managed via api/routes/gallery.php. */
require_once APP_ROOT . '/app/services/media.php';

$categories = ['campus' => 'Campus', 'events' => 'Events', 'cultural' => 'Cultural', 'sports' => 'Sports', 'convocation' => 'Convocation',
    'labs' => 'Labs & Facilities', 'placements' => 'Placements', 'academics' => 'Academics'];

return [
    'table' => 'galleries',
    'title' => 'Galleries',
    'singular' => 'Album',
    'permission' => 'media',
    'icon' => 'gallery-horizontal',
    'description' => 'Photo and video albums published on the website gallery.',
    'select' => "t.*,
                 (SELECT COUNT(*) FROM gallery_items gi WHERE gi.gallery_id = t.id) AS items_count,
                 (SELECT COUNT(*) FROM gallery_items gi WHERE gi.gallery_id = t.id AND gi.video_url IS NOT NULL AND gi.video_url <> '') AS videos_count,
                 COALESCE(NULLIF(t.cover_image, ''), (SELECT COALESCE(NULLIF(gi.image, ''), m.path) FROM gallery_items gi LEFT JOIN media m ON m.id = gi.media_id
                     WHERE gi.gallery_id = t.id AND (NULLIF(gi.image, '') IS NOT NULL OR m.path IS NOT NULL) ORDER BY gi.sort_order, gi.id LIMIT 1)) AS cover_url",
    'search' => ['t.title', 't.slug', 't.description'],
    'search_placeholder' => 'Search albums…',
    'order' => 't.sort_order ASC, COALESCE(t.event_date, t.created_at) DESC, t.id DESC',
    'columns' => [
        ['key' => 'cover_url', 'label' => 'Cover', 'format' => 'image'],
        ['key' => 'title', 'label' => 'Album', 'format' => 'title', 'sub' => 'slug', 'sortable' => true, 'link' => '/cms/gallery/{id}'],
        ['key' => 'category', 'label' => 'Category', 'format' => 'badge', 'sortable' => true, 'colors' => array_fill_keys(array_keys($categories), 'navy')],
        ['key' => 'items_count', 'label' => 'Items', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'event_date', 'label' => 'Event date', 'format' => 'date', 'sortable' => true],
        ['key' => 'sort_order', 'label' => 'Order', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
        ['key' => 'created_at', 'label' => 'Created', 'format' => 'date', 'sortable' => true, 'hidden' => true],
    ],
    'filters' => [
        ['key' => 'category', 'label' => 'Category', 'options' => $categories],
        ['key' => 'status', 'label' => 'Status', 'options' => ['published' => 'Published', 'draft' => 'Draft']],
        ['key' => 'event_date', 'label' => 'Event date', 'type' => 'daterange'],
    ],
    'fields' => [
        ['name' => 'title', 'label' => 'Album title', 'type' => 'text', 'required' => true, 'maxlength' => 190, 'col' => 12, 'placeholder' => 'e.g. Annual Fest Utkarsh 2026'],
        ['name' => 'slug', 'label' => 'Slug', 'type' => 'slug', 'slug_from' => 'title', 'unique' => true, 'maxlength' => 190, 'col' => 12, 'help' => 'Album URL: /gallery/your-slug — generated from the title when left empty.'],
        ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'required' => true, 'default' => 'campus', 'options' => $categories],
        ['name' => 'event_date', 'label' => 'Event date', 'type' => 'date'],
        ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'maxlength' => 500, 'rows' => 3],
        ['name' => 'cover_image', 'label' => 'Cover image', 'type' => 'text', 'maxlength' => 255, 'col' => 12, 'help' => 'Leave empty to use the first photo of the album.'],
        ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'min' => 0, 'max' => 9999, 'default' => 0],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'published', 'options' => ['published' => 'Published', 'draft' => 'Draft (hidden)']],
    ],
    'bulk' => ['delete' => true, 'status' => ['published', 'draft']],
    'import' => false,
    'export' => true,
    'form' => ['size' => 'lg'],
    'view' => ['type' => 'page', 'url' => '/cms/gallery/{id}'],
    'hooks' => [
        'validate' => function (array $data, ?int $id): array {
            $errors = [];
            if (array_key_exists('cover_image', $data) && !media_valid_ref($data['cover_image'])) {
                $errors['cover_image'] = 'Choose the cover image from the media library.';
            }
            if ($id === null && empty($data['slug']) && !empty($data['title']) && (int) db_value('SELECT COUNT(*) FROM galleries WHERE slug = ?', [slugify($data['title'])])) {
                $errors['slug'] = 'An album with the slug "' . slugify($data['title']) . '" already exists. Enter a different slug.';
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id): array {
            if ($id === null && empty($data['slug']) && !empty($data['title'])) {
                $data['slug'] = slugify($data['title']);
            }
            return $data;
        },
        // Deleting an album cascades to gallery_items (FK); the media files stay in the library.
        'describe' => fn (array $row): string => '"' . ($row['title'] ?? '') . '"',
    ],
];
