<?php
/**
 * Media library (Website CMS). Files are normally uploaded through POST /api/media/upload (multi-file uploader with
 * per-type validation); this module drives the list view, metadata editing, export and folder moves.
 */
require_once APP_ROOT . '/app/services/media.php';

$folders = media_folders();

return [
    'table' => 'media',
    'title' => 'Media',
    'singular' => 'Media file',
    'permission' => 'media',
    'icon' => 'images',
    'description' => 'Images, videos, PDFs and documents used across the website.',
    'select' => "t.*, u.name AS uploaded_by_name,
                 IF(t.width IS NULL OR t.height IS NULL, NULL, CONCAT(t.width, ' × ', t.height)) AS dimensions",
    'joins' => 'LEFT JOIN users u ON u.id = t.uploaded_by',
    'search' => ['t.title', 't.original_name', 't.alt_text', 't.caption', 't.filename'],
    'search_placeholder' => 'Search by title, file name, alt text…',
    'order' => 't.created_at DESC, t.id DESC',
    'default_sort' => null,
    'per_page' => 25,
    'columns' => [
        ['key' => 'path', 'label' => 'Preview', 'format' => 'image'],
        ['key' => 'title', 'label' => 'Title', 'format' => 'title', 'sub' => 'original_name', 'sortable' => true, 'link' => 'view'],
        ['key' => 'type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true,
            'colors' => ['image' => 'blue', 'video' => 'purple', 'pdf' => 'red', 'document' => 'cyan', 'other' => 'slate']],
        ['key' => 'folder', 'label' => 'Folder', 'format' => 'badge', 'sortable' => true, 'colors' => array_fill_keys(array_keys($folders), 'slate')],
        ['key' => 'size_label', 'label' => 'Size', 'format' => 'text', 'align' => 'right', 'sortable' => 't.size_bytes'],
        ['key' => 'dimensions', 'label' => 'Dimensions', 'format' => 'text', 'sortable' => 't.width', 'hidden' => true],
        ['key' => 'alt_text', 'label' => 'Alt text', 'format' => 'truncate', 'truncate' => 40, 'hidden' => true],
        ['key' => 'mime', 'label' => 'MIME type', 'format' => 'code', 'hidden' => true],
        ['key' => 'uploaded_by_name', 'label' => 'Uploaded by', 'format' => 'text', 'sortable' => 'u.name', 'hidden' => true],
        ['key' => 'created_at', 'label' => 'Uploaded', 'format' => 'datetime', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'title', 'label' => 'Title'], ['key' => 'original_name', 'label' => 'File name'], ['key' => 'type', 'label' => 'Type', 'format' => 'badge'],
        ['key' => 'folder', 'label' => 'Folder', 'format' => 'badge'], ['key' => 'size_label', 'label' => 'Size'], ['key' => 'dimensions', 'label' => 'Dimensions'],
        ['key' => 'alt_text', 'label' => 'Alt text'], ['key' => 'absolute_url', 'label' => 'URL'], ['key' => 'uploaded_by_name', 'label' => 'Uploaded by'],
        ['key' => 'created_at', 'label' => 'Uploaded', 'format' => 'datetime'],
    ],
    'filters' => [
        ['key' => 'type', 'label' => 'Type', 'options' => ['image' => 'Images', 'video' => 'Videos', 'pdf' => 'PDFs', 'document' => 'Documents', 'other' => 'Other']],
        ['key' => 'folder', 'label' => 'Folder', 'options' => $folders],
        ['key' => 'alt', 'label' => 'Alt text', 'sql_map' => ['missing' => "t.type = 'image' AND (t.alt_text IS NULL OR t.alt_text = '')", 'present' => "t.alt_text IS NOT NULL AND t.alt_text <> ''"],
            'options' => ['missing' => 'Missing alt text', 'present' => 'Has alt text']],
        ['key' => 'uploaded_by', 'label' => 'Uploaded by', 'source' => 'users'],
        // Used by the media picker of gallery albums (images + videos only, paginated server-side).
        ['key' => 'kind', 'label' => 'Kind', 'sql_map' => ['visual' => "t.type IN ('image', 'video')", 'files' => "t.type NOT IN ('image', 'video')"],
            'options' => ['visual' => 'Images & videos', 'files' => 'PDFs & documents']],
        ['key' => 'created_at', 'label' => 'Uploaded', 'type' => 'daterange'],
    ],
    'fields' => [
        ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'maxlength' => 190, 'col' => 12],
        ['name' => 'alt_text', 'label' => 'Alt text', 'type' => 'text', 'maxlength' => 255, 'col' => 12, 'help' => 'Describe the image for screen readers and search engines.'],
        ['name' => 'caption', 'label' => 'Caption', 'type' => 'textarea', 'maxlength' => 500, 'rows' => 2],
        ['name' => 'folder', 'label' => 'Folder', 'type' => 'select', 'required' => true, 'default' => 'general', 'options' => $folders],
        // Stored file (uploaded via the API uploader; accepted here too so the generic CRUD create still works).
        ['name' => 'path', 'label' => 'File', 'type' => 'file', 'category' => 'media', 'folder' => 'media/general', 'form' => false],
    ],
    'bulk' => ['delete' => true, 'status' => array_keys($folders), 'status_field' => 'folder'],
    'import' => false,
    'export' => true,
    'form' => ['size' => 'md'],
    'hooks' => [
        'transform_row' => fn (array $row): array => media_out($row),
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            if ($id === null && empty($_FILES['path']) ) {
                return ['path' => 'Upload files with the media library uploader.'];
            }
            return [];
        },
        'before_save' => function (array $data, ?int $id): array {
            if ($id === null && !empty($data['path'])) {
                $abs = APP_ROOT . '/' . $data['path'];
                $ext = strtolower(pathinfo($data['path'], PATHINFO_EXTENSION));
                $dims = is_file($abs) ? @getimagesize($abs) : false;
                $data += [
                    'filename' => basename($data['path']),
                    'original_name' => mb_substr(basename((string) ($_FILES['path']['name'] ?? $data['path'])), 0, 190),
                    'extension' => $ext,
                    'type' => media_type_for_ext($ext),
                    'mime' => is_file($abs) ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($abs) : null,
                    'size_bytes' => is_file($abs) ? filesize($abs) : null,
                    'width' => $dims ? $dims[0] : null,
                    'height' => $dims ? $dims[1] : null,
                    'uploaded_by' => user_id(),
                ];
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $refs = media_usage($row, 3);
            if ($refs) {
                return '"' . ($row['title'] ?: $row['original_name']) . '" is used by ' . implode(', ', array_map(fn ($r) => strtolower($r['label']) . ($r['title'] ? ' "' . $r['title'] . '"' : ''), $refs))
                    . '. Open it in the media library to review and delete it.';
            }
            return null;
        },
        'after_bulk' => function (array $ids, string $field, $value): void {
            log_activity('update', 'media', implode(',', array_slice($ids, 0, 20)), 'Moved ' . count($ids) . ' media file(s) to folder ' . (media_folders()[$value] ?? $value));
        },
        'describe' => fn (array $row): string => '"' . ($row['title'] ?: ($row['original_name'] ?: $row['filename'])) . '"',
    ],
];
