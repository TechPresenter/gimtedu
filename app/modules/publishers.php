<?php
/** Library: publishers. */
require_once APP_ROOT . '/app/services/library.php';

return [
    'table' => 'publishers',
    'title' => 'Publishers',
    'singular' => 'Publisher',
    'permission' => 'library',
    'icon' => 'building',
    'description' => 'Publishing houses of the books in the catalogue.',
    'select' => "t.*, (SELECT COUNT(*) FROM books b WHERE b.publisher_id = t.id) AS titles_count,
                 (SELECT COUNT(*) FROM book_copies c JOIN books b2 ON b2.id = c.book_id WHERE b2.publisher_id = t.id AND c.status NOT IN ('lost','withdrawn')) AS copies_count",
    'search' => ['t.name', 't.city', 't.website'],
    'order' => 't.name ASC',
    'columns' => [
        ['key' => 'name', 'label' => 'Publisher', 'format' => 'title', 'sub' => 'city', 'sortable' => true, 'link' => 'view'],
        ['key' => 'website', 'label' => 'Website', 'format' => 'text'],
        ['key' => 'titles_count', 'label' => 'Titles', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'copies_count', 'label' => 'Copies', 'format' => 'number', 'align' => 'center', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'has_titles', 'label' => 'Titles', 'sql_map' => ['with' => 'EXISTS (SELECT 1 FROM books x WHERE x.publisher_id = t.id)', 'without' => 'NOT EXISTS (SELECT 1 FROM books x WHERE x.publisher_id = t.id)'],
            'options' => ['with' => 'Has titles', 'without' => 'No titles yet']],
    ],
    'fields' => [
        ['name' => 'name', 'label' => 'Publisher name', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 12, 'maxlength' => 150, 'placeholder' => 'e.g. Pearson Education'],
        ['name' => 'city', 'label' => 'City', 'type' => 'text', 'col' => 6, 'maxlength' => 80, 'placeholder' => 'New Delhi'],
        ['name' => 'website', 'label' => 'Website', 'type' => 'url', 'col' => 6, 'maxlength' => 190, 'placeholder' => 'https://'],
    ],
    'bulk' => ['delete' => true],
    'import' => true,
    'form' => ['size' => 'md'],
    'hooks' => [
        'before_delete' => function (int $id, array $row): ?string {
            $n = (int) db_value('SELECT COUNT(*) FROM books WHERE publisher_id = ?', [$id]);
            return $n ? $row['name'] . ' has ' . $n . ' book' . ($n > 1 ? 's' : '') . ' in the catalogue and cannot be deleted.' : null;
        },
    ],
];
