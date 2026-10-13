<?php
/** Library: book titles (catalogue). Copies live in book_copies; authors in book_authors (many-to-many). */
require_once APP_ROOT . '/app/services/library.php';

$authorsSource = ['table' => 'authors a', 'value' => 'a.id', 'label' => 'a.name', 'order' => 'a.name', 'search' => ['a.name'], 'async' => true];

return [
    'table' => 'books',
    'title' => 'Books',
    'singular' => 'Book',
    'permission' => 'library',
    'icon' => 'book-open',
    'description' => 'Library catalogue: titles, authors, publishers and shelf locations.',
    'select' => 't.*, bc.name AS category_name, bc.code AS category_code, pb.name AS publisher_name, d.name AS department_name,
                 ' . lib_authors_sql('t') . ' AS authors,
                 (SELECT GROUP_CONCAT(ba.author_id ORDER BY ba.sort_order) FROM book_authors ba WHERE ba.book_id = t.id) AS author_ids,
                 (SELECT COUNT(*) FROM book_copies c WHERE c.book_id = t.id AND c.status = \'issued\') AS issued_copies,
                 (SELECT COUNT(*) FROM library_transactions lt WHERE lt.book_id = t.id) AS loans_count,
                 CONCAT_WS(\' / \', NULLIF(t.rack_no, \'\'), NULLIF(t.shelf_no, \'\')) AS location',
    'joins' => 'LEFT JOIN book_categories bc ON bc.id = t.category_id LEFT JOIN publishers pb ON pb.id = t.publisher_id LEFT JOIN departments d ON d.id = t.department_id',
    'search' => ['t.title', 't.subtitle', 't.isbn', 'pb.name', '(SELECT GROUP_CONCAT(a.name) FROM book_authors ba JOIN authors a ON a.id = ba.author_id WHERE ba.book_id = t.id)'],
    'search_placeholder' => 'Search title, author, ISBN or publisher…',
    'order' => 't.title ASC',
    'columns' => [
        ['key' => 'title', 'label' => 'Title', 'format' => 'title', 'sub' => 'authors', 'sortable' => true, 'link' => '/library/books/{id}'],
        ['key' => 'category_name', 'label' => 'Category', 'format' => 'text', 'sortable' => 'bc.name'],
        ['key' => 'publisher_name', 'label' => 'Publisher', 'format' => 'text', 'sortable' => 'pb.name', 'hidden' => true],
        ['key' => 'isbn', 'label' => 'ISBN', 'format' => 'code', 'sortable' => true, 'hidden' => true],
        ['key' => 'edition', 'label' => 'Edition', 'format' => 'text', 'hidden' => true],
        ['key' => 'publish_year', 'label' => 'Year', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'language', 'label' => 'Language', 'format' => 'text', 'hidden' => true, 'sortable' => true],
        ['key' => 'location', 'label' => 'Rack / Shelf', 'format' => 'text', 'sortable' => 't.rack_no'],
        ['key' => 'available_copies', 'label' => 'Available', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'total_copies', 'label' => 'Copies', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'loans_count', 'label' => 'Loans', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'price', 'label' => 'Price', 'format' => 'money', 'sortable' => true, 'hidden' => true],
        ['key' => 'type', 'label' => 'Type', 'format' => 'badge', 'hidden' => true, 'sortable' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'category_id', 'label' => 'Category', 'source' => ['table' => 'book_categories bc', 'value' => 'bc.id', 'label' => 'bc.name', 'order' => 'bc.name', 'search' => ['bc.name']]],
        ['key' => 'availability', 'label' => 'Availability', 'sql_map' => [
            'available' => 't.available_copies > 0', 'all_issued' => 't.total_copies > 0 AND t.available_copies = 0', 'no_copies' => 't.total_copies = 0',
        ], 'options' => ['available' => 'Available now', 'all_issued' => 'All copies out', 'no_copies' => 'No copies yet']],
        ['key' => 'type', 'label' => 'Type', 'options' => ['book' => 'Book', 'reference' => 'Reference', 'journal' => 'Journal', 'magazine' => 'Magazine', 'thesis' => 'Thesis', 'ebook' => 'E-book']],
        ['key' => 'language', 'label' => 'Language', 'options' => ['English' => 'English', 'Hindi' => 'Hindi', 'Bilingual' => 'Bilingual', 'Sanskrit' => 'Sanskrit', 'Urdu' => 'Urdu']],
        ['key' => 'publisher_id', 'label' => 'Publisher', 'source' => ['table' => 'publishers pb', 'value' => 'pb.id', 'label' => 'pb.name', 'order' => 'pb.name', 'search' => ['pb.name']]],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'scopes' => ['category_id' => 't.category_id', 'publisher_id' => 't.publisher_id'],
    'fields' => [
        ['type' => 'section', 'label' => 'Title & authorship'],
        ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'col' => 8, 'maxlength' => 255, 'placeholder' => 'e.g. Marketing Management'],
        ['name' => 'edition', 'label' => 'Edition', 'type' => 'text', 'col' => 4, 'maxlength' => 40, 'placeholder' => '16th Edition'],
        ['name' => 'subtitle', 'label' => 'Subtitle', 'type' => 'text', 'col' => 12, 'maxlength' => 255],
        ['name' => 'author_ids', 'label' => 'Authors', 'type' => 'multiselect', 'required' => true, 'col' => 12, 'source' => $authorsSource, 'placeholder' => 'Search authors…',
            'help' => 'The first author is the primary author. Add new authors from the Authors tab.'],
        ['name' => 'category_id', 'label' => 'Category', 'type' => 'combobox', 'required' => true, 'col' => 6,
            'source' => ['table' => 'book_categories bc', 'value' => 'bc.id', 'label' => 'bc.name', 'sub' => 'bc.code', 'order' => 'bc.name', 'search' => ['bc.name', 'bc.code']]],
        ['name' => 'publisher_id', 'label' => 'Publisher', 'type' => 'combobox', 'col' => 6,
            'source' => ['table' => 'publishers pb', 'value' => 'pb.id', 'label' => 'pb.name', 'sub' => 'pb.city', 'order' => 'pb.name', 'search' => ['pb.name']]],
        ['name' => 'isbn', 'label' => 'ISBN', 'type' => 'text', 'unique' => true, 'col' => 4, 'maxlength' => 20, 'placeholder' => '978-93-xxxxx-xx-x', 'help' => 'ISBN-10 or ISBN-13'],
        ['name' => 'publish_year', 'label' => 'Year', 'type' => 'number', 'col' => 4, 'min' => 1800, 'max' => (int) date('Y') + 1],
        ['name' => 'language', 'label' => 'Language', 'type' => 'select', 'col' => 4, 'default' => 'English',
            'options' => ['English' => 'English', 'Hindi' => 'Hindi', 'Bilingual' => 'Bilingual', 'Sanskrit' => 'Sanskrit', 'Urdu' => 'Urdu']],
        ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'col' => 4, 'default' => 'book',
            'options' => ['book' => 'Book (lendable)', 'reference' => 'Reference (in-library only)', 'journal' => 'Journal', 'magazine' => 'Magazine', 'thesis' => 'Thesis', 'ebook' => 'E-book']],
        ['name' => 'pages', 'label' => 'Pages', 'type' => 'number', 'col' => 4, 'min' => 1, 'max' => 20000],
        ['name' => 'department_id', 'label' => 'Department', 'type' => 'combobox', 'col' => 4, 'source' => 'departments', 'placeholder' => 'Any department'],
        ['type' => 'section', 'label' => 'Location, price & copies'],
        ['name' => 'rack_no', 'label' => 'Rack', 'type' => 'text', 'col' => 4, 'maxlength' => 30, 'placeholder' => 'R-12'],
        ['name' => 'shelf_no', 'label' => 'Shelf', 'type' => 'text', 'col' => 4, 'maxlength' => 30, 'placeholder' => 'S-3'],
        ['name' => 'price', 'label' => 'Price', 'type' => 'money', 'col' => 4, 'min' => 0, 'max' => 1000000, 'help' => 'Used as the lost-book fine'],
        ['name' => 'initial_copies', 'label' => 'Copies to add now', 'type' => 'number', 'col' => 6, 'min' => 0, 'max' => 100, 'default' => 1, 'create_only' => true,
            'help' => 'Accession numbers and barcodes are generated automatically.'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'col' => 6, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive (hidden from circulation)']],
        ['name' => 'cover_image', 'label' => 'Cover image', 'type' => 'image', 'folder' => 'books', 'col' => 12],
        ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'rows' => 3, 'maxlength' => 5000],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'import' => true,
    'export_columns' => [
        ['key' => 'title', 'label' => 'Title'], ['key' => 'authors', 'label' => 'Authors'], ['key' => 'category_name', 'label' => 'Category'],
        ['key' => 'publisher_name', 'label' => 'Publisher'], ['key' => 'isbn', 'label' => 'ISBN'], ['key' => 'edition', 'label' => 'Edition'],
        ['key' => 'publish_year', 'label' => 'Year'], ['key' => 'language', 'label' => 'Language'], ['key' => 'rack_no', 'label' => 'Rack'], ['key' => 'shelf_no', 'label' => 'Shelf'],
        ['key' => 'price', 'label' => 'Price', 'format' => 'money'], ['key' => 'total_copies', 'label' => 'Copies', 'format' => 'number'],
        ['key' => 'available_copies', 'label' => 'Available', 'format' => 'number'], ['key' => 'type', 'label' => 'Type', 'format' => 'badge'], ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
    ],
    'form' => ['size' => 'xl'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            $errors = [];
            if (!empty($data['isbn'])) {
                $isbn = lib_clean_isbn($data['isbn']);
                if (!$isbn || !lib_isbn_valid($isbn)) {
                    $errors['isbn'] = 'Enter a valid ISBN-10 or ISBN-13 (check digit does not match).';
                } elseif (db_value('SELECT id FROM books WHERE isbn = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$isbn, $id] : [$isbn])) {
                    $errors['isbn'] = 'Another book already uses this ISBN.';
                }
            }
            if (array_key_exists('author_ids', $input) || $id === null) {
                $raw = $input['author_ids'] ?? [];
                $importing = is_string($raw) && !str_starts_with(trim($raw), '[') && !ctype_digit(str_replace([',', ' '], '', $raw));
                $ids = lib_resolve_authors($raw, false);
                if (!$ids && !$importing) {
                    $errors['author_ids'] = 'Add at least one author.';
                }
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id): array {
            if (array_key_exists('isbn', $data)) {
                $data['isbn'] = lib_clean_isbn($data['isbn']);
            }
            if (isset($data['title'])) {
                $data['title'] = preg_replace('/\s+/', ' ', $data['title']);
            }
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old, array $input): void {
            if (array_key_exists('author_ids', $input)) {
                lib_sync_authors($id, lib_resolve_authors($input['author_ids'], true));
            }
            if ($old === null) {
                $n = isset($input['initial_copies']) && $input['initial_copies'] !== '' ? (int) $input['initial_copies'] : 1;
                $n = max(0, min(100, $n));
                $book = db_row('SELECT price, rack_no FROM books WHERE id = ?', [$id]);
                for ($i = 0; $i < $n; $i++) {
                    $acc = lib_next_accession();
                    db_insert('book_copies', ['book_id' => $id, 'accession_no' => $acc, 'barcode' => $acc, 'rack_no' => $book['rack_no'], 'condition_note' => 'new',
                        'acquired_date' => date('Y-m-d'), 'price' => $book['price'], 'status' => 'available']);
                }
            }
            lib_recount_book($id);
        },
        'before_delete' => function (int $id, array $row): ?string {
            $loans = (int) db_value('SELECT COUNT(*) FROM library_transactions WHERE book_id = ?', [$id]);
            if ($loans) {
                return '"' . str_limit($row['title'], 60) . '" has ' . $loans . ' circulation record' . ($loans > 1 ? 's' : '') . ' and cannot be deleted. Mark it inactive instead.';
            }
            return null;
        },
        'describe' => fn (array $row) => '"' . str_limit((string) ($row['title'] ?? ''), 80) . '"',
    ],
];
