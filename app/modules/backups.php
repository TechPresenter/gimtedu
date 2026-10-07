<?php
/** Backup catalogue (System > Backup). Create / restore / download / delete go through api/routes/backup.php. */
require_once APP_ROOT . '/app/services/backup.php';

return [
    'table' => 'backups',
    'title' => 'Backups',
    'singular' => 'Backup',
    'permission' => 'backup',
    'icon' => 'database',
    'description' => 'Database and uploaded-file backups stored in storage/backups.',
    'readonly' => true,
    'select' => 't.*, u.name AS created_by_name, ru.name AS restored_by_name',
    'joins' => 'LEFT JOIN users u ON u.id = t.created_by LEFT JOIN users ru ON ru.id = t.restored_by',
    'search' => ['t.filename', 't.notes', 'u.name'],
    'search_placeholder' => 'Search file name or notes…',
    'order' => 't.created_at DESC, t.id DESC',
    'default_sort' => ['key' => 'created_at', 'dir' => 'desc'],
    'per_page' => 10,
    'columns' => [
        ['key' => 'created_at', 'label' => 'Date', 'format' => 'datetime', 'sortable' => true],
        ['key' => 'filename', 'label' => 'File', 'format' => 'code', 'sortable' => true],
        ['key' => 'type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true],
        ['key' => 'source', 'label' => 'Trigger', 'format' => 'badge', 'sortable' => true],
        ['key' => 'size_bytes', 'label' => 'Size (bytes)', 'format' => 'number', 'sortable' => true],
        ['key' => 'created_by_name', 'label' => 'Created by', 'sortable' => 'u.name'],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
        ['key' => 'restored_at', 'label' => 'Last restored', 'format' => 'datetime', 'hidden' => true],
    ],
    'filters' => [
        ['key' => 'type', 'label' => 'Type', 'options' => ['database' => 'Database', 'files' => 'Files']],
        ['key' => 'status', 'label' => 'Status', 'options' => ['completed' => 'Completed', 'failed' => 'Failed', 'running' => 'Running']],
        ['key' => 'source', 'label' => 'Trigger', 'options' => ['manual' => 'Manual', 'auto' => 'Scheduled', 'pre-restore' => 'Safety snapshot', 'seed' => 'Initial']],
    ],
    'permissions' => ['export' => ['backup', 'view']],
    'bulk' => ['delete' => false],
    'hooks' => [
        'transform_row' => fn (array $row): array => backup_public_row($row),
        'describe' => fn (array $row) => (string) ($row['filename'] ?? ''),
    ],
];
