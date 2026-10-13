<?php
/**
 * Items (photos / videos) inside a gallery album. The album screen uses api/routes/gallery.php for add-from-library,
 * captions, reordering and removal; this module provides the scoped list + export.
 */
require_once APP_ROOT . '/app/services/media.php';

return [
    'table' => 'gallery_items',
    'title' => 'Gallery Items',
    'singular' => 'Gallery item',
    'permission' => 'media',
    'icon' => 'image',
    'description' => 'Photos and videos inside an album.',
    'select' => "t.*, g.title AS gallery_title, m.title AS media_title, m.alt_text AS media_alt, m.width, m.height,
                 COALESCE(NULLIF(t.image, ''), m.path) AS image_url,
                 IF(t.video_url IS NOT NULL AND t.video_url <> '', 'video', 'photo') AS kind",
    'joins' => 'JOIN galleries g ON g.id = t.gallery_id LEFT JOIN media m ON m.id = t.media_id',
    'search' => ['t.caption', 'm.title', 'g.title'],
    'order' => 't.gallery_id, t.sort_order ASC, t.id ASC',
    'scopes' => ['gallery_id' => 't.gallery_id'],
    'columns' => [
        ['key' => 'image_url', 'label' => 'Preview', 'format' => 'image'],
        ['key' => 'caption', 'label' => 'Caption', 'format' => 'title', 'sub' => 'media_title', 'sortable' => true],
        ['key' => 'kind', 'label' => 'Type', 'format' => 'badge', 'colors' => ['photo' => 'blue', 'video' => 'purple']],
        ['key' => 'gallery_title', 'label' => 'Album', 'format' => 'text', 'sortable' => 'g.title'],
        ['key' => 'sort_order', 'label' => 'Order', 'format' => 'number', 'align' => 'center', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'gallery_id', 'label' => 'Album', 'source' => 'galleries'],
        ['key' => 'kind', 'label' => 'Type', 'sql_map' => ['photo' => "(t.video_url IS NULL OR t.video_url = '')", 'video' => "t.video_url IS NOT NULL AND t.video_url <> ''"],
            'options' => ['photo' => 'Photos', 'video' => 'Videos']],
    ],
    'fields' => [
        ['name' => 'gallery_id', 'label' => 'Album', 'type' => 'select', 'required' => true, 'source' => 'galleries'],
        ['name' => 'media_id', 'label' => 'Library file', 'type' => 'combobox', 'source' => ['table' => 'media md', 'value' => 'md.id', 'label' => "COALESCE(md.title, md.original_name, md.filename)",
            'sub' => 'md.folder', 'where' => "md.type IN ('image','video')", 'order' => 'md.created_at DESC', 'search' => ['md.title', 'md.original_name'], 'async' => true]],
        ['name' => 'image', 'label' => 'Image path', 'type' => 'text', 'maxlength' => 255, 'help' => 'Only for images that are not in the media library.'],
        ['name' => 'video_url', 'label' => 'Video URL', 'type' => 'url', 'maxlength' => 255, 'placeholder' => 'https://www.youtube.com/watch?v=…'],
        ['name' => 'caption', 'label' => 'Caption', 'type' => 'text', 'maxlength' => 255, 'col' => 12],
        ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'min' => 0, 'max' => 99999, 'default' => 0],
    ],
    'bulk' => ['delete' => true],
    'import' => false,
    'export' => true,
    'form' => ['size' => 'md'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            if (array_key_exists('image', $data) && !media_valid_ref($data['image'])) {
                $errors['image'] = 'Enter a path inside assets/ or a full image URL.';
            }
            if (!empty($data['video_url']) && !preg_match('#^https?://#i', (string) $data['video_url'])) {
                $errors['video_url'] = 'Video URL must start with https://';
            }
            $media = $data['media_id'] ?? ($old['media_id'] ?? null);
            $image = $data['image'] ?? ($old['image'] ?? null);
            $video = $data['video_url'] ?? ($old['video_url'] ?? null);
            if (!$media && !$image && !$video) {
                $errors['media_id'] = 'Choose a library file, an image path or a video URL.';
            }
            return $errors;
        },
        'describe' => fn (array $row): string => $row['caption'] ? '"' . $row['caption'] . '"' : '#' . ($row['id'] ?? ''),
    ],
];
