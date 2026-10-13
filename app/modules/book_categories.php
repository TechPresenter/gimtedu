<?php
/** Library: book categories (subject classes). */
require_once APP_ROOT . '/app/services/library.php';

return [
    'table' => 'book_categories',
    'title' => 'Book Categories',
    'singular' => 'Category',
    'permission' => 'library',
    'icon' => 'folder-tree',
    'description' => 'Subject classes used to organise the catalogue (DDC style).',
    'select' => "t.*, (SELECT COUNT(*) FROM books b WHERE b.category_id = t.id) AS titles_count,
                 (SELECT COUNT(*) FROM book_copies c JOIN books b2 ON b2.id = c.book_id WHERE b2.category_id = t.id AND c.status NOT IN ('lost','withdrawn')) AS copies_count,
                 (SELECT COUNT(*) FROM book_copies c JOIN books b3 ON b3.id = c.book_id WHERE b3.category_id = t.id AND c.status = 'issued') AS issued_count",
    'search' => ['t.name', 't.code', 't.description'],
    'order' => 't.name ASC',
    'columns' => [
        ['key' => 'name', 'label' => 'Category', 'format' => 'title', 'sub' => 'description', 'sortable' => true, 'link' => 'view'],
        ['key' => 'code', 'label' => 'Class code', 'format' => 'code', 'sortable' => true],
        ['key' => 'titles_count', 'label' => 'Titles', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'copies_count', 'label' => 'Copies', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'issued_count', 'label' => 'On loan', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'fields' => [
        ['name' => 'name', 'label' => 'Category name', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 8, 'maxlength' => 120, 'placeholder' => 'e.g. Management & Business'],
        ['name' => 'code', 'label' => 'Class code', 'type' => 'text', 'col' => 4, 'maxlength' => 20, 'placeholder' => '650', 'help' => 'DDC class or shelf code'],
        ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 255],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'import' => true,
    'form' => ['size' => 'md'],
    'hooks' => [
        'before_save' => function (array $data): array {
            if (!empty($data['code'])) {
                $data['code'] = strtoupper($data['code']);
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $n = (int) db_value('SELECT COUNT(*) FROM books WHERE category_id = ?', [$id]);
            return $n ? $row['name'] . ' has ' . $n . ' book' . ($n > 1 ? 's' : '') . '. Move them to another category or mark it inactive instead.' : null;
        },
    ],
];
