<?php
/** Library: physical copies of a title (accession register). Issue / return happens in circulation, not here. */
require_once APP_ROOT . '/app/services/library.php';

return [
    'table' => 'book_copies',
    'title' => 'Book Copies',
    'singular' => 'Copy',
    'permission' => 'library',
    'icon' => 'library',
    'description' => 'Accession register: every physical copy with barcode, condition and status.',
    'select' => "t.*, b.title AS book_title, b.isbn, bc.name AS category_name, " . lib_authors_sql('b') . " AS authors,
                 COALESCE(NULLIF(t.rack_no, ''), b.rack_no) AS location,
                 (SELECT " . lib_member_name_sql('lm') . " FROM library_transactions lt JOIN library_members lm ON lm.id = lt.member_id " . lib_member_joins('lm') . "
                   WHERE lt.book_copy_id = t.id AND lt.status = 'issued' ORDER BY lt.id DESC LIMIT 1) AS borrower,
                 (SELECT lt2.due_date FROM library_transactions lt2 WHERE lt2.book_copy_id = t.id AND lt2.status = 'issued' ORDER BY lt2.id DESC LIMIT 1) AS due_date,
                 (SELECT COUNT(*) FROM library_transactions lt3 WHERE lt3.book_copy_id = t.id) AS loans_count",
    'joins' => 'JOIN books b ON b.id = t.book_id LEFT JOIN book_categories bc ON bc.id = b.category_id',
    'search' => ['t.accession_no', 't.barcode', 'b.title', 'b.isbn'],
    'search_placeholder' => 'Search accession no, barcode or title…',
    'order' => 't.accession_no ASC',
    'columns' => [
        ['key' => 'accession_no', 'label' => 'Accession', 'format' => 'code', 'sortable' => true],
        ['key' => 'book_title', 'label' => 'Book', 'format' => 'title', 'sub' => 'authors', 'sortable' => 'b.title', 'link' => '/library/books/{book_id}'],
        ['key' => 'barcode', 'label' => 'Barcode', 'format' => 'text', 'hidden' => true],
        ['key' => 'location', 'label' => 'Rack', 'format' => 'text', 'sortable' => 't.rack_no'],
        ['key' => 'condition_note', 'label' => 'Condition', 'format' => 'badge', 'sortable' => true,
            'colors' => ['new' => 'green', 'good' => 'blue', 'fair' => 'amber', 'poor' => 'red']],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true,
            'colors' => ['available' => 'green', 'issued' => 'blue', 'damaged' => 'amber', 'lost' => 'red', 'withdrawn' => 'slate', 'reserved' => 'purple']],
        ['key' => 'borrower', 'label' => 'With / due', 'format' => 'text'],
        ['key' => 'due_date', 'label' => 'Due', 'format' => 'date', 'hidden' => true],
        ['key' => 'acquired_date', 'label' => 'Acquired', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'price', 'label' => 'Price', 'format' => 'money', 'sortable' => true, 'hidden' => true],
        ['key' => 'loans_count', 'label' => 'Loans', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => ['available' => 'Available', 'issued' => 'Issued', 'damaged' => 'Damaged', 'lost' => 'Lost', 'withdrawn' => 'Withdrawn']],
        ['key' => 'condition_note', 'label' => 'Condition', 'options' => ['new' => 'New', 'good' => 'Good', 'fair' => 'Fair', 'poor' => 'Poor']],
        ['key' => 'category', 'label' => 'Category', 'column' => 'b.category_id', 'source' => ['table' => 'book_categories bc', 'value' => 'bc.id', 'label' => 'bc.name', 'order' => 'bc.name', 'search' => ['bc.name']]],
        ['key' => 'acquired_date', 'label' => 'Acquired', 'type' => 'daterange'],
    ],
    'scopes' => ['book_id' => 't.book_id'],
    'fields' => [
        ['name' => 'book_id', 'label' => 'Book', 'type' => 'combobox', 'required' => true, 'col' => 12, 'readonly_on_edit' => true,
            'source' => ['table' => 'books b', 'value' => 'b.id', 'label' => 'b.title', 'sub' => "CONCAT(COALESCE(b.isbn, 'No ISBN'), ' · ', b.total_copies, ' copies')", 'order' => 'b.title', 'search' => ['b.title', 'b.isbn'], 'async' => true],
            'placeholder' => 'Search title or ISBN…'],
        ['name' => 'accession_no', 'label' => 'Accession number', 'type' => 'text', 'unique' => true, 'col' => 6, 'maxlength' => 30, 'readonly_on_edit' => true,
            'placeholder' => 'Leave blank to auto-generate', 'rules' => 'alpha_dash'],
        ['name' => 'barcode', 'label' => 'Barcode', 'type' => 'text', 'col' => 6, 'maxlength' => 60, 'placeholder' => 'Defaults to the accession number'],
        ['name' => 'rack_no', 'label' => 'Rack', 'type' => 'text', 'col' => 4, 'maxlength' => 30, 'placeholder' => 'Same as book'],
        ['name' => 'condition_note', 'label' => 'Condition', 'type' => 'select', 'required' => true, 'col' => 4, 'default' => 'new', 'options' => ['new' => 'New', 'good' => 'Good', 'fair' => 'Fair', 'poor' => 'Poor']],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'col' => 4, 'default' => 'available',
            'options' => ['available' => 'Available', 'issued' => 'Issued (via circulation)', 'damaged' => 'Damaged', 'lost' => 'Lost', 'withdrawn' => 'Withdrawn']],
        ['name' => 'acquired_date', 'label' => 'Acquired on', 'type' => 'date', 'col' => 6],
        ['name' => 'price', 'label' => 'Price', 'type' => 'money', 'col' => 6, 'min' => 0, 'max' => 1000000, 'placeholder' => 'Same as book'],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 255],
    ],
    'bulk' => ['delete' => true],
    'import' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $status = $data['status'] ?? null;
            if ($old && $old['status'] === 'issued' && $status !== null && $status !== 'issued') {
                $errors['status'] = 'This copy is on loan. Return it from Issue / Return to change its status.';
            } elseif ($status === 'issued' && (!$old || $old['status'] !== 'issued')) {
                $errors['status'] = 'Copies can only be issued from the Issue / Return screen.';
            }
            if (!empty($data['acquired_date']) && $data['acquired_date'] > date('Y-m-d')) {
                $errors['acquired_date'] = 'Acquired date cannot be in the future.';
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            if ($id === null) {
                $data['accession_no'] = !empty($data['accession_no']) ? strtoupper($data['accession_no']) : lib_next_accession();
                if (empty($data['barcode'])) {
                    $data['barcode'] = $data['accession_no'];
                }
                if (!array_key_exists('price', $data) || $data['price'] === null) {
                    $data['price'] = db_value('SELECT price FROM books WHERE id = ?', [(int) $data['book_id']]);
                }
            } elseif (array_key_exists('barcode', $data) && empty($data['barcode'])) {
                $data['barcode'] = $old['accession_no'];
            }
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old): void {
            $bookId = (int) db_value('SELECT book_id FROM book_copies WHERE id = ?', [$id]);
            lib_recount_book($bookId);
        },
        'before_delete' => function (int $id, array $row): ?string {
            if ($row['status'] === 'issued') {
                return 'Copy ' . $row['accession_no'] . ' is on loan. Return it before deleting.';
            }
            $n = (int) db_value('SELECT COUNT(*) FROM library_transactions WHERE book_copy_id = ?', [$id]);
            return $n ? 'Copy ' . $row['accession_no'] . ' has circulation history. Mark it withdrawn instead of deleting.' : null;
        },
        'after_delete' => function (int $id, array $row): void {
            lib_recount_book((int) $row['book_id']);
        },
        'describe' => fn (array $row) => (string) ($row['accession_no'] ?? ('#' . ($row['id'] ?? ''))),
    ],
];
