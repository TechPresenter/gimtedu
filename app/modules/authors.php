<?php
/** Library: authors. */
require_once APP_ROOT . '/app/services/library.php';

return [
    'table' => 'authors',
    'title' => 'Authors',
    'singular' => 'Author',
    'permission' => 'library',
    'icon' => 'pen-line',
    'description' => 'Authors and editors linked to catalogue titles.',
    'select' => 't.*, (SELECT COUNT(*) FROM book_authors ba WHERE ba.author_id = t.id) AS titles_count,
                 (SELECT COUNT(*) FROM library_transactions lt JOIN book_authors ba2 ON ba2.book_id = lt.book_id WHERE ba2.author_id = t.id) AS loans_count',
    'search' => ['t.name', 't.bio'],
    'order' => 't.name ASC',
    'columns' => [
        ['key' => 'name', 'label' => 'Author', 'format' => 'person', 'sortable' => true],
        ['key' => 'bio', 'label' => 'About', 'format' => 'truncate', 'truncate' => 80],
        ['key' => 'titles_count', 'label' => 'Titles', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'loans_count', 'label' => 'Times borrowed', 'format' => 'number', 'align' => 'center', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'has_titles', 'label' => 'Titles', 'sql_map' => ['with' => 'EXISTS (SELECT 1 FROM book_authors x WHERE x.author_id = t.id)', 'without' => 'NOT EXISTS (SELECT 1 FROM book_authors x WHERE x.author_id = t.id)'],
            'options' => ['with' => 'Has titles', 'without' => 'No titles yet']],
    ],
    'fields' => [
        ['name' => 'name', 'label' => 'Author name', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 12, 'maxlength' => 150, 'placeholder' => 'e.g. Philip Kotler'],
        ['name' => 'bio', 'label' => 'Short bio', 'type' => 'textarea', 'rows' => 3, 'maxlength' => 2000],
    ],
    'bulk' => ['delete' => true],
    'import' => true,
    'form' => ['size' => 'md'],
    'hooks' => [
        'before_delete' => function (int $id, array $row): ?string {
            $n = (int) db_value('SELECT COUNT(*) FROM book_authors WHERE author_id = ?', [$id]);
            return $n ? $row['name'] . ' is linked to ' . $n . ' title' . ($n > 1 ? 's' : '') . '. Remove the author from those books first.' : null;
        },
    ],
];
