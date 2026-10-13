<?php
/**
 * Library domain logic: circulation rules, members, issue / return / renew / lost, fines, copies,
 * overdue reminders, dashboard analytics and the server-rendered print views (member cards, fine receipts,
 * overdue report, accession labels).
 *
 * Used by api/routes/library.php and the CRUD modules app/modules/{books,book_copies,library_members,
 * library_transactions,library_fines,...}.php. All writes that touch more than one table run inside
 * db_transaction(); business-rule violations throw CrudException / CrudValidationException (HTTP 422).
 */

/* ------------------------------------------------------------------
 * Rules (settings group "library")
 * ------------------------------------------------------------------ */

/** Editable circulation rules: setting key => [label, type, default, min, max]. */
function lib_rule_defs(): array
{
    return [
        'library_fine_per_day'        => ['Fine per overdue day (₹)', 'money', 2, 0, 1000],
        'library_grace_days'          => ['Grace days before fine starts', 'int', 0, 0, 30],
        'library_max_renewals'        => ['Maximum renewals per loan', 'int', 2, 0, 10],
        'library_max_unpaid_fine'     => ['Block issue when unpaid fines exceed (₹)', 'money', 200, 0, 100000],
        'library_block_on_overdue'    => ['Block issue while member has overdue books', 'bool', 1, 0, 1],
        'library_lost_processing_fee' => ['Processing fee added to lost-book fine (₹, optional)', 'money', 0, 0, 10000],
        'library_max_books_student'   => ['Books allowed — students', 'int', 3, 1, 20],
        'library_loan_days_student'   => ['Loan period (days) — students', 'int', 14, 1, 180],
        'library_max_books_faculty'   => ['Books allowed — faculty', 'int', 6, 1, 30],
        'library_loan_days_faculty'   => ['Loan period (days) — faculty', 'int', 30, 1, 365],
        'library_max_books_staff'     => ['Books allowed — staff', 'int', 4, 1, 20],
        'library_loan_days_staff'     => ['Loan period (days) — staff', 'int', 21, 1, 180],
    ];
}

function lib_rules(): array
{
    $v = [];
    foreach (lib_rule_defs() as $key => [$label, $type, $default]) {
        $raw = setting($key, $default);
        $v[$key] = $type === 'money' ? (float) $raw : (int) $raw;
    }
    return [
        'fine_per_day' => $v['library_fine_per_day'],
        'grace_days' => $v['library_grace_days'],
        'max_renewals' => $v['library_max_renewals'],
        'max_unpaid_fine' => $v['library_max_unpaid_fine'],
        'block_on_overdue' => (bool) $v['library_block_on_overdue'],
        'lost_processing_fee' => $v['library_lost_processing_fee'],
        'types' => [
            'student' => ['max_books' => $v['library_max_books_student'], 'loan_days' => $v['library_loan_days_student']],
            'faculty' => ['max_books' => $v['library_max_books_faculty'], 'loan_days' => $v['library_loan_days_faculty']],
            'staff' => ['max_books' => $v['library_max_books_staff'], 'loan_days' => $v['library_loan_days_staff']],
        ],
        'payment_modes' => lib_payment_modes(),
    ];
}

/** Payment modes accepted at the library counter (subset of the institute's enabled modes). */
function lib_payment_modes(): array
{
    $labels = ['cash' => 'Cash', 'upi' => 'UPI', 'card' => 'Card', 'bank_transfer' => 'Bank Transfer', 'online' => 'Online', 'cheque' => 'Cheque', 'dd' => 'Demand Draft'];
    $enabled = array_filter(array_map('trim', explode(',', (string) setting('enabled_payment_modes', 'cash,upi,card,bank_transfer,online'))));
    $out = [];
    foreach ($labels as $k => $l) {
        if (in_array($k, $enabled, true) || $k === 'cash') {
            $out[$k] = $l;
        }
    }
    return $out;
}

function lib_member_types(): array
{
    return ['student' => 'Student', 'faculty' => 'Faculty', 'staff' => 'Staff'];
}

/* ------------------------------------------------------------------
 * SQL fragments for members (student / faculty / staff)
 * ------------------------------------------------------------------ */

function lib_member_joins(string $a = 'lm'): string
{
    return "LEFT JOIN students s ON s.id = $a.student_id
            LEFT JOIN programs sp ON sp.id = s.program_id
            LEFT JOIN sections ssec ON ssec.id = s.section_id
            LEFT JOIN faculty f ON f.id = $a.faculty_id
            LEFT JOIN staff st ON st.id = $a.staff_id
            LEFT JOIN departments md ON md.id = COALESCE(s.department_id, f.department_id, st.department_id)";
}

function lib_member_name_sql(string $a = 'lm'): string
{
    return "(CASE $a.member_type WHEN 'student' THEN TRIM(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name))
              WHEN 'faculty' THEN TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name))
              ELSE TRIM(CONCAT_WS(' ', st.first_name, st.last_name)) END)";
}

/** Common member columns (requires lib_member_joins()). */
function lib_member_cols(string $a = 'lm'): string
{
    return lib_member_name_sql($a) . " AS member_name,
        (CASE $a.member_type WHEN 'student' THEN s.photo WHEN 'faculty' THEN f.photo ELSE st.photo END) AS member_photo,
        (CASE $a.member_type WHEN 'student' THEN s.student_uid WHEN 'faculty' THEN f.employee_id ELSE st.employee_id END) AS member_code,
        (CASE $a.member_type WHEN 'student' THEN CONCAT(COALESCE(sp.short_name, ''), ' · Sem ', s.current_semester, IFNULL(CONCAT(' · Sec ', ssec.name), ''))
              WHEN 'faculty' THEN CONCAT(f.designation, IFNULL(CONCAT(' · ', md.name), ''))
              ELSE CONCAT(st.designation, IFNULL(CONCAT(' · ', md.name), '')) END) AS member_info,
        (CASE $a.member_type WHEN 'student' THEN s.email WHEN 'faculty' THEN f.email ELSE st.email END) AS member_email,
        (CASE $a.member_type WHEN 'student' THEN s.mobile WHEN 'faculty' THEN f.phone ELSE st.phone END) AS member_phone,
        (CASE $a.member_type WHEN 'student' THEN s.user_id WHEN 'faculty' THEN f.user_id ELSE st.user_id END) AS member_user_id,
        (CASE $a.member_type WHEN 'student' THEN s.status WHEN 'faculty' THEN f.status ELSE st.status END) AS person_status";
}

/** Inline lookup source for member comboboxes (any status). */
function lib_member_source(bool $activeOnly = false): array
{
    return [
        'table' => 'library_members lm', 'joins' => lib_member_joins('lm'), 'value' => 'lm.id',
        'label' => "CONCAT(" . lib_member_name_sql('lm') . ", ' (', lm.membership_no, ')')",
        'sub' => "CONCAT(UPPER(LEFT(lm.member_type, 1)), SUBSTRING(lm.member_type, 2), ' · ', lm.status)",
        'where' => $activeOnly ? "lm.status = 'active'" : null, 'order' => 'lm.membership_no',
        'search' => ['lm.membership_no', 's.first_name', 's.last_name', 's.student_uid', 'f.first_name', 'f.last_name', 'f.employee_id', 'st.first_name', 'st.last_name', 'st.employee_id'],
        'async' => true,
    ];
}

/** Authors list for a book as "A, B & C" (SQL subquery). */
function lib_authors_sql(string $bookAlias = 'b'): string
{
    return "(SELECT GROUP_CONCAT(a.name ORDER BY ba.sort_order, a.name SEPARATOR ', ') FROM book_authors ba JOIN authors a ON a.id = ba.author_id WHERE ba.book_id = $bookAlias.id)";
}

/* ------------------------------------------------------------------
 * Members
 * ------------------------------------------------------------------ */

function lib_member(int $id): ?array
{
    $row = db_row('SELECT lm.*, ' . lib_member_cols('lm') . ', md.name AS department_name, sp.short_name AS program_short,
                (SELECT COUNT(*) FROM library_transactions t WHERE t.member_id = lm.id AND t.status = \'issued\') AS loans,
                (SELECT COUNT(*) FROM library_transactions t WHERE t.member_id = lm.id AND t.status = \'issued\' AND t.due_date < CURDATE()) AS overdue,
                (SELECT COALESCE(SUM(fn.amount), 0) FROM library_fines fn WHERE fn.member_id = lm.id AND fn.status = \'unpaid\') AS unpaid_fines,
                (SELECT COUNT(*) FROM library_transactions t WHERE t.member_id = lm.id) AS total_loans
            FROM library_members lm ' . lib_member_joins('lm') . ' WHERE lm.id = ?', [$id]);
    if (!$row) {
        return null;
    }
    return lib_member_public($row);
}

function lib_member_public(array $r): array
{
    $today = date('Y-m-d');
    $expired = !empty($r['valid_until']) && $r['valid_until'] < $today;
    return [
        'id' => (int) $r['id'], 'membership_no' => $r['membership_no'], 'member_type' => $r['member_type'],
        'name' => $r['member_name'], 'photo' => $r['member_photo'], 'code' => $r['member_code'], 'info' => $r['member_info'],
        'email' => $r['member_email'], 'phone' => $r['member_phone'], 'user_id' => $r['member_user_id'] ? (int) $r['member_user_id'] : null,
        'student_id' => $r['student_id'] ? (int) $r['student_id'] : null, 'faculty_id' => $r['faculty_id'] ? (int) $r['faculty_id'] : null,
        'staff_id' => $r['staff_id'] ? (int) $r['staff_id'] : null, 'department' => $r['department_name'] ?? null,
        'max_books' => (int) $r['max_books'], 'loan_days' => (int) $r['loan_days'], 'valid_until' => $r['valid_until'],
        'status' => $r['status'], 'is_expired' => $expired, 'person_status' => $r['person_status'] ?? null, 'remarks' => $r['remarks'] ?? null,
        'loans' => (int) ($r['loans'] ?? 0), 'overdue' => (int) ($r['overdue'] ?? 0), 'unpaid_fines' => (float) ($r['unpaid_fines'] ?? 0),
        'total_loans' => (int) ($r['total_loans'] ?? 0), 'created_at' => $r['created_at'],
    ];
}

/** Reasons that prevent this member from borrowing right now (empty = can borrow). */
function lib_member_blocks(array $m, ?array $rules = null): array
{
    $rules = $rules ?? lib_rules();
    $blocks = [];
    if ($m['status'] !== 'active') {
        $blocks[] = 'Membership is ' . strtolower(label_from_key($m['status'])) . ($m['remarks'] ? ' (' . $m['remarks'] . ')' : '') . '.';
    } elseif ($m['is_expired']) {
        $blocks[] = 'Membership expired on ' . format_date($m['valid_until']) . '. Renew the membership validity first.';
    }
    if ($m['person_status'] && !in_array($m['person_status'], ['active', 'on_leave'], true)) {
        $blocks[] = 'The ' . $m['member_type'] . ' record is marked ' . strtolower(label_from_key((string) $m['person_status'])) . '.';
    }
    if ($m['loans'] >= $m['max_books']) {
        $blocks[] = 'Borrowing limit reached (' . $m['loans'] . ' of ' . $m['max_books'] . ' books issued).';
    }
    if ($rules['block_on_overdue'] && $m['overdue'] > 0) {
        $blocks[] = $m['overdue'] . ' overdue book' . ($m['overdue'] > 1 ? 's' : '') . ' must be returned first.';
    }
    if ($rules['max_unpaid_fine'] > 0 && $m['unpaid_fines'] > $rules['max_unpaid_fine']) {
        $blocks[] = 'Unpaid fines of ' . money($m['unpaid_fines'], 2) . ' exceed the ' . money($rules['max_unpaid_fine']) . ' limit.';
    }
    return $blocks;
}

/** Member card payload: profile, limits, current loans (with accrued fine), unpaid fines, recent history. */
function lib_member_card(int $id): ?array
{
    $m = lib_member($id);
    if (!$m) {
        return null;
    }
    $rules = lib_rules();
    $loans = array_map(fn ($r) => lib_loan_public($r, $rules), db_all(lib_loan_sql() . " WHERE t.member_id = ? AND t.status = 'issued' ORDER BY t.due_date", [$id]));
    $fines = array_map('lib_fine_public', db_all(lib_fine_sql() . " WHERE fn.member_id = ? AND fn.status = 'unpaid' ORDER BY fn.created_at DESC", [$id]));
    $history = array_map(fn ($r) => lib_loan_public($r, $rules), db_all(lib_loan_sql() . ' WHERE t.member_id = ? ORDER BY t.issue_date DESC, t.id DESC LIMIT 15', [$id]));
    $stats = db_row("SELECT COUNT(*) AS borrowed, SUM(t.status = 'returned' AND t.return_date > t.due_date) AS returned_late, SUM(t.status = 'lost') AS lost,
                     (SELECT COALESCE(SUM(amount), 0) FROM library_fines WHERE member_id = ? AND status = 'paid') AS fines_paid
                     FROM library_transactions t WHERE t.member_id = ?", [$id, $id]);
    return [
        'member' => $m, 'blocks' => lib_member_blocks($m, $rules), 'loans' => $loans, 'fines' => $fines, 'history' => $history,
        'stats' => ['borrowed' => (int) $stats['borrowed'], 'returned_late' => (int) $stats['returned_late'], 'lost' => (int) $stats['lost'], 'fines_paid' => (float) $stats['fines_paid'],
            'accrued' => array_sum(array_column($loans, 'accrued_fine'))],
        'rules' => ['fine_per_day' => $rules['fine_per_day'], 'max_renewals' => $rules['max_renewals'], 'grace_days' => $rules['grace_days']],
    ];
}

function lib_next_membership_no(): string
{
    for ($i = 0; $i < 50; $i++) {
        $no = next_number('library_member', 'LIB{n:5}');
        if (!db_value('SELECT COUNT(*) FROM library_members WHERE membership_no = ?', [$no])) {
            return $no;
        }
    }
    throw new CrudException('Unable to generate a membership number. Please enter one manually.');
}

/** Default membership validity: end of the current academic session (or one year from today). */
function lib_default_valid_until(): string
{
    $s = current_session();
    if (!empty($s['end_date']) && $s['end_date'] > date('Y-m-d')) {
        return $s['end_date'];
    }
    return date('Y-m-d', strtotime('+1 year'));
}

/**
 * Enroll many people as members.
 * $in: target = section|program|all_students|faculty|staff, section_id, program_id, semester, valid_until, max_books, loan_days
 */
function lib_enroll_candidates(array $in): array
{
    $target = (string) ($in['target'] ?? '');
    switch ($target) {
        case 'section':
            return db_column("SELECT s.id FROM students s WHERE s.status = 'active' AND s.section_id = ? AND NOT EXISTS (SELECT 1 FROM library_members lm WHERE lm.student_id = s.id) ORDER BY s.first_name, s.last_name", [(int) ($in['section_id'] ?? 0)]);
        case 'program':
            $args = [(int) ($in['program_id'] ?? 0)];
            $sem = '';
            if (!empty($in['semester'])) {
                $sem = ' AND s.current_semester = ?';
                $args[] = (int) $in['semester'];
            }
            return db_column("SELECT s.id FROM students s WHERE s.status = 'active' AND s.program_id = ?$sem AND NOT EXISTS (SELECT 1 FROM library_members lm WHERE lm.student_id = s.id) ORDER BY s.first_name", $args);
        case 'all_students':
            return db_column("SELECT s.id FROM students s WHERE s.status = 'active' AND NOT EXISTS (SELECT 1 FROM library_members lm WHERE lm.student_id = s.id) ORDER BY s.id");
        case 'faculty':
            return db_column("SELECT f.id FROM faculty f WHERE f.status IN ('active','on_leave') AND NOT EXISTS (SELECT 1 FROM library_members lm WHERE lm.faculty_id = f.id) ORDER BY f.first_name");
        case 'staff':
            return db_column("SELECT st.id FROM staff st WHERE st.status IN ('active','on_leave') AND NOT EXISTS (SELECT 1 FROM library_members lm WHERE lm.staff_id = st.id) ORDER BY st.first_name");
    }
    return [];
}

function lib_enroll_target_total(array $in): int
{
    switch ((string) ($in['target'] ?? '')) {
        case 'section':
            return (int) db_value("SELECT COUNT(*) FROM students WHERE status = 'active' AND section_id = ?", [(int) ($in['section_id'] ?? 0)]);
        case 'program':
            $args = [(int) ($in['program_id'] ?? 0)];
            $sem = '';
            if (!empty($in['semester'])) {
                $sem = ' AND current_semester = ?';
                $args[] = (int) $in['semester'];
            }
            return (int) db_value("SELECT COUNT(*) FROM students WHERE status = 'active' AND program_id = ?$sem", $args);
        case 'all_students':
            return (int) db_value("SELECT COUNT(*) FROM students WHERE status = 'active'");
        case 'faculty':
            return (int) db_value("SELECT COUNT(*) FROM faculty WHERE status IN ('active','on_leave')");
        case 'staff':
            return (int) db_value("SELECT COUNT(*) FROM staff WHERE status IN ('active','on_leave')");
    }
    return 0;
}

function lib_enroll(array $in): array
{
    $target = (string) ($in['target'] ?? '');
    $type = in_array($target, ['faculty', 'staff'], true) ? $target : 'student';
    $rules = lib_rules()['types'][$type];
    $maxBooks = !empty($in['max_books']) ? (int) $in['max_books'] : $rules['max_books'];
    $loanDays = !empty($in['loan_days']) ? (int) $in['loan_days'] : $rules['loan_days'];
    $validUntil = !empty($in['valid_until']) ? (string) $in['valid_until'] : lib_default_valid_until();
    $ids = lib_enroll_candidates($in);
    $total = lib_enroll_target_total($in);
    $col = $type . '_id';
    $created = 0;
    db_transaction(function () use ($ids, $type, $col, $maxBooks, $loanDays, $validUntil, &$created) {
        foreach ($ids as $pid) {
            db_insert('library_members', [
                'membership_no' => lib_next_membership_no(), 'member_type' => $type, $col => (int) $pid, 'max_books' => $maxBooks,
                'loan_days' => $loanDays, 'valid_until' => $validUntil, 'status' => 'active',
            ]);
            $created++;
        }
    });
    return ['created' => $created, 'already' => max(0, $total - $created), 'total' => $total, 'type' => $type];
}

/* ------------------------------------------------------------------
 * Loans (transactions)
 * ------------------------------------------------------------------ */

function lib_loan_sql(): string
{
    return 'SELECT t.*, b.title, b.isbn, b.cover_image, b.price AS book_price, b.type AS book_type, c.accession_no, c.barcode, c.price AS copy_price, c.condition_note,
                   ' . lib_authors_sql('b') . ' AS authors, bc.name AS category_name, lm.membership_no, lm.member_type, ' . lib_member_cols('lm') . ',
                   ui.name AS issued_by_name, ur.name AS returned_to_name
            FROM library_transactions t
            JOIN books b ON b.id = t.book_id
            JOIN book_copies c ON c.id = t.book_copy_id
            LEFT JOIN book_categories bc ON bc.id = b.category_id
            JOIN library_members lm ON lm.id = t.member_id ' . lib_member_joins('lm') . '
            LEFT JOIN users ui ON ui.id = t.issued_by
            LEFT JOIN users ur ON ur.id = t.returned_to';
}

/** Days late (after grace) and the fine for returning on $returnDate. */
function lib_compute_fine(string $dueDate, string $returnDate, ?array $rules = null): array
{
    $rules = $rules ?? lib_rules();
    $late = (int) floor((strtotime($returnDate) - strtotime($dueDate)) / 86400);
    $late = max(0, $late);
    $chargeable = max(0, $late - $rules['grace_days']);
    return ['days_late' => $late, 'days' => $chargeable, 'per_day' => $rules['fine_per_day'], 'amount' => round($chargeable * $rules['fine_per_day'], 2)];
}

function lib_loan_public(array $r, ?array $rules = null): array
{
    $rules = $rules ?? lib_rules();
    $today = date('Y-m-d');
    $issued = $r['status'] === 'issued';
    $fine = $issued ? lib_compute_fine($r['due_date'], $today, $rules) : ['days_late' => 0, 'days' => 0, 'amount' => 0];
    $state = $r['status'];
    if ($issued && $r['due_date'] < $today) {
        $state = 'overdue';
    } elseif ($issued && $r['due_date'] === $today) {
        $state = 'due_today';
    } elseif ($r['status'] === 'returned' && $r['return_date'] && $r['return_date'] > $r['due_date']) {
        $state = 'returned_late';
    }
    return [
        'id' => (int) $r['id'], 'book_id' => (int) $r['book_id'], 'book_copy_id' => (int) $r['book_copy_id'], 'member_id' => (int) $r['member_id'],
        'title' => $r['title'], 'authors' => $r['authors'], 'isbn' => $r['isbn'], 'cover_image' => $r['cover_image'], 'category' => $r['category_name'] ?? null,
        'accession_no' => $r['accession_no'], 'barcode' => $r['barcode'], 'price' => $r['copy_price'] !== null ? (float) $r['copy_price'] : ($r['book_price'] !== null ? (float) $r['book_price'] : null),
        'issue_date' => $r['issue_date'], 'due_date' => $r['due_date'], 'return_date' => $r['return_date'], 'return_condition' => $r['return_condition'] ?? null,
        'renew_count' => (int) $r['renew_count'], 'status' => $r['status'], 'state' => $state,
        'days_overdue' => $issued ? $fine['days_late'] : ($r['return_date'] ? max(0, (int) floor((strtotime($r['return_date']) - strtotime($r['due_date'])) / 86400)) : 0),
        'accrued_fine' => (float) $fine['amount'], 'fine_amount' => (float) $r['fine_amount'],
        'can_renew' => $issued && $r['due_date'] >= $today && (int) $r['renew_count'] < $rules['max_renewals'],
        'reminder_count' => (int) ($r['reminder_count'] ?? 0), 'last_reminded_at' => $r['last_reminded_at'] ?? null, 'remarks' => $r['remarks'],
        'member' => ['id' => (int) $r['member_id'], 'name' => $r['member_name'], 'photo' => $r['member_photo'], 'membership_no' => $r['membership_no'],
            'type' => $r['member_type'], 'info' => $r['member_info'], 'email' => $r['member_email'], 'phone' => $r['member_phone']],
        'issued_by' => $r['issued_by_name'] ?? null, 'returned_to' => $r['returned_to_name'] ?? null,
    ];
}

function lib_loan(int $id): ?array
{
    $r = db_row(lib_loan_sql() . ' WHERE t.id = ?', [$id]);
    return $r ? lib_loan_public($r) : null;
}

/** Find a copy by accession number or barcode (case-insensitive), with its book and the open loan if any. */
function lib_find_copy(string $code): ?array
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }
    $c = db_row('SELECT c.*, b.title, b.subtitle, b.isbn, b.edition, b.publish_year, b.cover_image, b.rack_no AS book_rack, b.shelf_no, b.type AS book_type, b.status AS book_status,
                        b.price AS book_price, b.total_copies, b.available_copies, ' . lib_authors_sql('b') . ' AS authors, bc.name AS category_name, pb.name AS publisher_name
                 FROM book_copies c JOIN books b ON b.id = c.book_id LEFT JOIN book_categories bc ON bc.id = b.category_id LEFT JOIN publishers pb ON pb.id = b.publisher_id
                 WHERE c.accession_no = ? OR c.barcode = ? ORDER BY c.accession_no = ? DESC LIMIT 1', [$code, $code, $code]);
    if (!$c) {
        return null;
    }
    $loan = null;
    if ($c['status'] === 'issued') {
        $l = db_row(lib_loan_sql() . " WHERE t.book_copy_id = ? AND t.status = 'issued' ORDER BY t.id DESC LIMIT 1", [(int) $c['id']]);
        $loan = $l ? lib_loan_public($l) : null;
    }
    return [
        'copy' => ['id' => (int) $c['id'], 'accession_no' => $c['accession_no'], 'barcode' => $c['barcode'], 'condition' => $c['condition_note'], 'status' => $c['status'],
            'rack_no' => $c['rack_no'] ?: $c['book_rack'], 'shelf_no' => $c['shelf_no'], 'price' => $c['price'] !== null ? (float) $c['price'] : ($c['book_price'] !== null ? (float) $c['book_price'] : null),
            'acquired_date' => $c['acquired_date'], 'remarks' => $c['remarks']],
        'book' => ['id' => (int) $c['book_id'], 'title' => $c['title'], 'subtitle' => $c['subtitle'], 'authors' => $c['authors'], 'isbn' => $c['isbn'], 'edition' => $c['edition'],
            'publish_year' => $c['publish_year'], 'cover_image' => $c['cover_image'], 'type' => $c['book_type'], 'status' => $c['book_status'], 'category' => $c['category_name'],
            'publisher' => $c['publisher_name'], 'total_copies' => (int) $c['total_copies'], 'available_copies' => (int) $c['available_copies']],
        'loan' => $loan,
    ];
}

/** Recompute total / available copy counters on a book. */
function lib_recount_book(int $bookId): void
{
    db_exec("UPDATE books b SET
                total_copies = (SELECT COUNT(*) FROM book_copies c WHERE c.book_id = b.id AND c.status NOT IN ('lost','withdrawn')),
                available_copies = (SELECT COUNT(*) FROM book_copies c WHERE c.book_id = b.id AND c.status = 'available')
             WHERE b.id = ?", [$bookId]);
}

/**
 * Issue a copy to a member.
 * $in: member_id, accession_no (or copy_id), due_date (optional), remarks
 */
function lib_issue(array $in): array
{
    $memberId = (int) ($in['member_id'] ?? 0);
    $code = trim((string) ($in['accession_no'] ?? ''));
    $copyId = (int) ($in['copy_id'] ?? 0);
    $errors = [];
    if (!$memberId) {
        $errors['member_id'] = 'Select the member who is borrowing the book.';
    }
    if ($code === '' && !$copyId) {
        $errors['accession_no'] = 'Scan or enter the accession number of the copy.';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $rules = lib_rules();
    $today = date('Y-m-d');
    return db_transaction(function () use ($memberId, $code, $copyId, $in, $rules, $today) {
        $m = lib_member($memberId);
        if (!$m) {
            throw new CrudValidationException(['member_id' => 'Member not found.']);
        }
        $copy = $copyId
            ? db_row('SELECT c.*, b.title, b.type AS book_type, b.status AS book_status FROM book_copies c JOIN books b ON b.id = c.book_id WHERE c.id = ? FOR UPDATE', [$copyId])
            : db_row('SELECT c.*, b.title, b.type AS book_type, b.status AS book_status FROM book_copies c JOIN books b ON b.id = c.book_id WHERE c.accession_no = ? OR c.barcode = ? ORDER BY c.accession_no = ? DESC LIMIT 1 FOR UPDATE', [$code, $code, $code]);
        if (!$copy) {
            throw new CrudValidationException(['accession_no' => 'No copy found with accession number "' . $code . '".']);
        }
        if ($copy['status'] !== 'available') {
            $msg = $copy['status'] === 'issued' ? 'This copy is already issued. Return it first.' : 'This copy is ' . strtolower(label_from_key($copy['status'])) . ' and cannot be issued.';
            throw new CrudValidationException(['accession_no' => $msg]);
        }
        if ($copy['book_type'] === 'reference') {
            throw new CrudValidationException(['accession_no' => 'Reference books are for in-library use only and cannot be issued.']);
        }
        if ($copy['book_status'] !== 'active') {
            throw new CrudValidationException(['accession_no' => 'This title is inactive in the catalogue.']);
        }
        $blocks = lib_member_blocks($m, $rules);
        if ($blocks) {
            throw new CrudException('Cannot issue to ' . $m['name'] . ': ' . implode(' ', $blocks));
        }
        $dup = db_value("SELECT COUNT(*) FROM library_transactions WHERE member_id = ? AND book_id = ? AND status = 'issued'", [$memberId, (int) $copy['book_id']]);
        if ($dup) {
            throw new CrudValidationException(['accession_no' => $m['name'] . ' already has a copy of "' . $copy['title'] . '".']);
        }
        $due = trim((string) ($in['due_date'] ?? ''));
        if ($due === '') {
            $due = date('Y-m-d', strtotime("+{$m['loan_days']} days"));
        } else {
            $d = DateTime::createFromFormat('Y-m-d', $due);
            if (!$d || $d->format('Y-m-d') !== $due) {
                throw new CrudValidationException(['due_date' => 'Due date must be a valid date.']);
            }
            if ($due <= $today) {
                throw new CrudValidationException(['due_date' => 'Due date must be after today.']);
            }
            if ($due > date('Y-m-d', strtotime('+365 days'))) {
                throw new CrudValidationException(['due_date' => 'Due date cannot be more than a year away.']);
            }
        }
        $remarks = mb_substr(trim((string) ($in['remarks'] ?? '')), 0, 255) ?: null;
        $txnId = db_insert('library_transactions', [
            'book_copy_id' => (int) $copy['id'], 'book_id' => (int) $copy['book_id'], 'member_id' => $memberId, 'issue_date' => $today, 'due_date' => $due,
            'status' => 'issued', 'issued_by' => user_id(), 'remarks' => $remarks,
        ]);
        db_update('book_copies', ['status' => 'issued'], 'id = ?', [(int) $copy['id']]);
        lib_recount_book((int) $copy['book_id']);
        log_activity('create', 'library', $txnId, sprintf('Issued "%s" (%s) to %s (%s), due %s', str_limit($copy['title'], 80), $copy['accession_no'], $m['name'], $m['membership_no'], format_date($due)));
        return lib_loan($txnId);
    });
}

function lib_open_loan(int $txnId): array
{
    $t = db_row('SELECT * FROM library_transactions WHERE id = ? FOR UPDATE', [$txnId]);
    if (!$t) {
        throw new CrudException('Transaction not found. It may have been deleted.');
    }
    if ($t['status'] !== 'issued') {
        throw new CrudException('This book has already been ' . ($t['status'] === 'lost' ? 'marked lost' : 'returned') . '.');
    }
    return $t;
}

/** Validate the settle options shared by return / lost. Returns [action, mode, waiveReason]. */
function lib_settle_options(array $in, float $amount): array
{
    $action = (string) ($in['fine_action'] ?? 'collect');
    if (!in_array($action, ['collect', 'waive', 'pending'], true)) {
        throw new CrudValidationException(['fine_action' => 'Choose how to settle the fine.']);
    }
    $mode = (string) ($in['payment_mode'] ?? 'cash');
    $reason = trim((string) ($in['waive_reason'] ?? ''));
    if ($amount > 0 && $action === 'collect' && !array_key_exists($mode, lib_payment_modes())) {
        throw new CrudValidationException(['payment_mode' => 'Select a valid payment mode.']);
    }
    if ($amount > 0 && $action === 'waive') {
        if (!can('library', 'manage')) {
            throw new CrudException('You do not have permission to waive library fines.');
        }
        if (mb_strlen($reason) < 3) {
            throw new CrudValidationException(['waive_reason' => 'Enter the reason for waiving the fine.']);
        }
    }
    return [$action, $mode, mb_substr($reason, 0, 255)];
}

/** Create a fine row and settle it per $action. Returns fine id. */
function lib_create_fine(int $memberId, ?int $txnId, float $amount, string $reason, ?int $days, string $action, string $mode, string $waiveReason, ?string $receiptNo, ?string $remarks = null): int
{
    $row = ['transaction_id' => $txnId, 'member_id' => $memberId, 'amount' => round($amount, 2), 'reason' => $reason, 'days_overdue' => $days, 'status' => 'unpaid', 'remarks' => $remarks];
    if ($action === 'collect') {
        $row['status'] = 'paid';
        $row['paid_at'] = date('Y-m-d H:i:s');
        $row['payment_mode'] = $mode;
        $row['receipt_no'] = $receiptNo;
        $row['collected_by'] = user_id();
    } elseif ($action === 'waive') {
        $row['status'] = 'waived';
        $row['waived_at'] = date('Y-m-d H:i:s');
        $row['waived_by'] = user_id();
        $row['waive_reason'] = $waiveReason;
    }
    return db_insert('library_fines', $row);
}

function lib_next_receipt_no(): string
{
    return next_number('library_fine_receipt', 'LFR/{session}/{n:5}');
}

/**
 * Return a loan.
 * $in: return_date, condition (good|damaged), damage_charge, fine_action (collect|waive|pending), payment_mode, waive_reason, remarks
 */
function lib_return(int $txnId, array $in): array
{
    $today = date('Y-m-d');
    $rules = lib_rules();
    return db_transaction(function () use ($txnId, $in, $today, $rules) {
        $t = lib_open_loan($txnId);
        $returnDate = trim((string) ($in['return_date'] ?? '')) ?: $today;
        $d = DateTime::createFromFormat('Y-m-d', $returnDate);
        if (!$d || $d->format('Y-m-d') !== $returnDate) {
            throw new CrudValidationException(['return_date' => 'Return date must be a valid date.']);
        }
        if ($returnDate > $today) {
            throw new CrudValidationException(['return_date' => 'Return date cannot be in the future.']);
        }
        if ($returnDate < $t['issue_date']) {
            throw new CrudValidationException(['return_date' => 'Return date cannot be before the issue date (' . format_date($t['issue_date']) . ').']);
        }
        $condition = (string) ($in['condition'] ?? 'good');
        if (!in_array($condition, ['good', 'damaged'], true)) {
            throw new CrudValidationException(['condition' => 'Select the condition of the returned copy.']);
        }
        $damage = 0.0;
        if ($condition === 'damaged') {
            $raw = $in['damage_charge'] ?? 0;
            if ($raw !== '' && $raw !== null && !is_numeric($raw)) {
                throw new CrudValidationException(['damage_charge' => 'Damage charge must be a number.']);
            }
            $damage = round(max(0, (float) $raw), 2);
            if ($damage > 100000) {
                throw new CrudValidationException(['damage_charge' => 'Damage charge looks too high.']);
            }
        }
        $fine = lib_compute_fine($t['due_date'], $returnDate, $rules);
        $total = $fine['amount'] + $damage;
        [$action, $mode, $waiveReason] = lib_settle_options($in, $total);
        $receipt = ($total > 0 && $action === 'collect') ? lib_next_receipt_no() : null;
        $remarks = mb_substr(trim((string) ($in['remarks'] ?? '')), 0, 255) ?: null;
        $fineIds = [];
        if ($fine['amount'] > 0) {
            $fineIds[] = lib_create_fine((int) $t['member_id'], $txnId, $fine['amount'], 'late_return', $fine['days_late'], $action, $mode, $waiveReason, $receipt,
                'Returned ' . $fine['days_late'] . ' day(s) late at ' . money($fine['per_day']) . '/day');
        }
        if ($damage > 0) {
            $fineIds[] = lib_create_fine((int) $t['member_id'], $txnId, $damage, 'damage', null, $action, $mode, $waiveReason, $receipt, $remarks ?: 'Copy returned damaged');
        }
        db_update('library_transactions', [
            'status' => 'returned', 'return_date' => $returnDate, 'return_condition' => $condition, 'fine_amount' => $total, 'returned_to' => user_id(),
            'remarks' => $remarks ?? $t['remarks'],
        ], 'id = ?', [$txnId]);
        db_update('book_copies', $condition === 'damaged' ? ['status' => 'damaged', 'condition_note' => 'poor'] : ['status' => 'available'], 'id = ?', [(int) $t['book_copy_id']]);
        lib_recount_book((int) $t['book_id']);
        $loan = lib_loan($txnId);
        $desc = sprintf('Returned "%s" (%s) from %s', str_limit($loan['title'], 80), $loan['accession_no'], $loan['member']['name']);
        if ($total > 0) {
            $desc .= sprintf(' · fine %s %s', money($total, 2), $action === 'collect' ? 'collected (' . $receipt . ')' : ($action === 'waive' ? 'waived' : 'pending'));
        }
        log_activity('update', 'library', $txnId, $desc);
        return ['loan' => $loan, 'fine' => ['late' => $fine, 'damage' => $damage, 'total' => $total, 'action' => $action, 'receipt_no' => $receipt, 'fine_ids' => $fineIds]];
    });
}

function lib_renew(int $txnId): array
{
    $rules = lib_rules();
    $today = date('Y-m-d');
    return db_transaction(function () use ($txnId, $rules, $today) {
        $t = lib_open_loan($txnId);
        if ($t['due_date'] < $today) {
            $fine = lib_compute_fine($t['due_date'], $today, $rules);
            throw new CrudException('This book is overdue by ' . $fine['days_late'] . ' day(s). Return it and settle the fine of ' . money($fine['amount'], 2) . ' before renewing.');
        }
        if ((int) $t['renew_count'] >= $rules['max_renewals']) {
            throw new CrudException('Renewal limit reached (' . $rules['max_renewals'] . ' renewal' . ($rules['max_renewals'] === 1 ? '' : 's') . ' per loan). Please return the book.');
        }
        $m = lib_member((int) $t['member_id']);
        if (!$m || $m['status'] !== 'active' || $m['is_expired']) {
            throw new CrudException('The membership is not active, so the loan cannot be renewed.');
        }
        $newDue = date('Y-m-d', strtotime("+{$m['loan_days']} days"));
        if ($newDue <= $t['due_date']) {
            $newDue = date('Y-m-d', strtotime($t['due_date'] . " +{$m['loan_days']} days"));
        }
        db_update('library_transactions', ['due_date' => $newDue, 'renew_count' => (int) $t['renew_count'] + 1], 'id = ?', [$txnId]);
        $loan = lib_loan($txnId);
        log_activity('update', 'library', $txnId, sprintf('Renewed "%s" (%s) for %s until %s (renewal %d of %d)', str_limit($loan['title'], 80), $loan['accession_no'], $m['name'], format_date($newDue), $loan['renew_count'], $rules['max_renewals']));
        return $loan;
    });
}

/**
 * Mark a loan as lost. Fine defaults to the copy/book price (plus the optional processing fee rule); overdue fine (if any) is added as a separate line.
 * $in: amount, fine_action, payment_mode, waive_reason, remarks
 */
function lib_mark_lost(int $txnId, array $in): array
{
    $rules = lib_rules();
    $today = date('Y-m-d');
    return db_transaction(function () use ($txnId, $in, $rules, $today) {
        $t = lib_open_loan($txnId);
        $price = (float) (db_value('SELECT COALESCE(c.price, b.price, 0) FROM book_copies c JOIN books b ON b.id = c.book_id WHERE c.id = ?', [(int) $t['book_copy_id']]) ?? 0);
        $raw = $in['amount'] ?? null;
        if ($raw === null || $raw === '') {
            $amount = $price + $rules['lost_processing_fee'];
        } elseif (!is_numeric($raw) || (float) $raw < 0) {
            throw new CrudValidationException(['amount' => 'Lost-book fine must be zero or more.']);
        } else {
            $amount = (float) $raw;
        }
        $amount = round($amount, 2);
        $late = lib_compute_fine($t['due_date'], $today, $rules);
        $total = $amount + $late['amount'];
        [$action, $mode, $waiveReason] = lib_settle_options($in, $total);
        $receipt = ($total > 0 && $action === 'collect') ? lib_next_receipt_no() : null;
        $remarks = mb_substr(trim((string) ($in['remarks'] ?? '')), 0, 255) ?: null;
        if ($amount > 0) {
            lib_create_fine((int) $t['member_id'], $txnId, $amount, 'lost', null, $action, $mode, $waiveReason, $receipt, $remarks ?: 'Book reported lost (replacement cost)');
        }
        if ($late['amount'] > 0) {
            lib_create_fine((int) $t['member_id'], $txnId, $late['amount'], 'late_return', $late['days_late'], $action, $mode, $waiveReason, $receipt, 'Overdue ' . $late['days_late'] . ' day(s) before being reported lost');
        }
        db_update('library_transactions', ['status' => 'lost', 'return_date' => $today, 'fine_amount' => $total, 'returned_to' => user_id(), 'remarks' => $remarks ?? $t['remarks']], 'id = ?', [$txnId]);
        db_update('book_copies', ['status' => 'lost', 'remarks' => 'Lost by member on ' . format_date($today)], 'id = ?', [(int) $t['book_copy_id']]);
        lib_recount_book((int) $t['book_id']);
        $loan = lib_loan($txnId);
        log_activity('update', 'library', $txnId, sprintf('Marked "%s" (%s) lost by %s · fine %s %s', str_limit($loan['title'], 80), $loan['accession_no'], $loan['member']['name'], money($total, 2),
            $action === 'collect' ? 'collected (' . $receipt . ')' : ($action === 'waive' ? 'waived' : 'pending')));
        return ['loan' => $loan, 'fine' => ['amount' => $amount, 'late' => $late, 'total' => $total, 'action' => $action, 'receipt_no' => $receipt]];
    });
}

/** Send overdue / due-soon reminders (email + SMS + in-app). */
function lib_remind(array $txnIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $txnIds))));
    if (!$ids) {
        throw new CrudException('Select at least one loan to remind.');
    }
    $rules = lib_rules();
    $rows = db_all(lib_loan_sql() . " WHERE t.status = 'issued' AND t.id IN (" . implode(',', $ids) . ')');
    $sent = 0;
    $noContact = 0;
    foreach ($rows as $r) {
        $loan = lib_loan_public($r, $rules);
        $m = $loan['member'];
        $overdue = $loan['days_overdue'] > 0;
        $subject = $overdue ? 'Library book overdue: ' . str_limit($loan['title'], 60) : 'Library book due on ' . format_date($loan['due_date']);
        $line = $overdue
            ? sprintf('The book "%s" (Acc. %s) issued to you on %s was due on %s and is overdue by %d day(s). A fine of %s has accrued at %s per day.', $loan['title'], $loan['accession_no'], format_date($loan['issue_date']), format_date($loan['due_date']), $loan['days_overdue'], money($loan['accrued_fine'], 2), money($rules['fine_per_day']))
            : sprintf('The book "%s" (Acc. %s) issued to you on %s is due on %s.', $loan['title'], $loan['accession_no'], format_date($loan['issue_date']), format_date($loan['due_date']));
        $html = '<p>Dear ' . e($m['name']) . ',</p><p>' . e($line) . '</p><p>Please return or renew it at the library counter at the earliest.</p><p>Regards,<br>Central Library, ' . e(institute_name()) . '</p>';
        $any = false;
        if (!empty($m['email']) && send_mail($m['email'], $subject, $html, ['related_type' => 'library_transaction', 'related_id' => $loan['id']])) {
            $any = true;
        }
        if (!empty($m['phone'])) {
            send_sms($m['phone'], 'GIMT Library: ' . $line . ' Please return it at the earliest.', ['related_type' => 'library_transaction', 'related_id' => $loan['id']]);
            $any = true;
        }
        if (!empty($r['member_user_id'])) {
            notify((int) $r['member_user_id'], 'system', $subject, $line, null, 'book-open');
            $any = true;
        }
        if ($any) {
            db_exec('UPDATE library_transactions SET reminder_count = reminder_count + 1, last_reminded_at = NOW() WHERE id = ?', [$loan['id']]);
            $sent++;
        } else {
            $noContact++;
        }
    }
    if ($sent) {
        log_activity('update', 'library', implode(',', array_slice($ids, 0, 20)), 'Sent ' . $sent . ' library due/overdue reminder' . ($sent > 1 ? 's' : ''));
    }
    return ['sent' => $sent, 'no_contact' => $noContact, 'skipped' => count($ids) - count($rows)];
}

/* ------------------------------------------------------------------
 * Fines
 * ------------------------------------------------------------------ */

function lib_fine_sql(): string
{
    return 'SELECT fn.*, lm.membership_no, lm.member_type, ' . lib_member_cols('lm') . ', b.title AS book_title, c.accession_no, uc.name AS collected_by_name, uw.name AS waived_by_name
            FROM library_fines fn
            JOIN library_members lm ON lm.id = fn.member_id ' . lib_member_joins('lm') . '
            LEFT JOIN library_transactions t ON t.id = fn.transaction_id
            LEFT JOIN books b ON b.id = t.book_id
            LEFT JOIN book_copies c ON c.id = t.book_copy_id
            LEFT JOIN users uc ON uc.id = fn.collected_by
            LEFT JOIN users uw ON uw.id = fn.waived_by';
}

function lib_fine_public(array $r): array
{
    return [
        'id' => (int) $r['id'], 'transaction_id' => $r['transaction_id'] ? (int) $r['transaction_id'] : null, 'member_id' => (int) $r['member_id'],
        'member_name' => $r['member_name'], 'membership_no' => $r['membership_no'], 'member_type' => $r['member_type'], 'member_photo' => $r['member_photo'],
        'book_title' => $r['book_title'], 'accession_no' => $r['accession_no'], 'amount' => (float) $r['amount'], 'reason' => $r['reason'],
        'days_overdue' => $r['days_overdue'] !== null ? (int) $r['days_overdue'] : null, 'status' => $r['status'], 'paid_at' => $r['paid_at'],
        'payment_mode' => $r['payment_mode'], 'receipt_no' => $r['receipt_no'], 'collected_by' => $r['collected_by_name'], 'waived_at' => $r['waived_at'],
        'waived_by' => $r['waived_by_name'], 'waive_reason' => $r['waive_reason'], 'remarks' => $r['remarks'], 'created_at' => $r['created_at'],
    ];
}

/** Collect unpaid fines; one receipt per member. Returns receipts [{receipt_no, member, amount, count}]. */
function lib_collect_fines(array $ids, string $mode, ?string $remarks = null): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        throw new CrudException('Select at least one fine to collect.');
    }
    if (!array_key_exists($mode, lib_payment_modes())) {
        throw new CrudValidationException(['payment_mode' => 'Select a valid payment mode.']);
    }
    $remarks = $remarks !== null ? (mb_substr(trim($remarks), 0, 255) ?: null) : null;
    return db_transaction(function () use ($ids, $mode, $remarks) {
        $rows = db_all('SELECT fn.id, fn.member_id, fn.amount, fn.status FROM library_fines fn WHERE fn.id IN (' . implode(',', $ids) . ') FOR UPDATE');
        $unpaid = array_filter($rows, fn ($r) => $r['status'] === 'unpaid');
        if (!$unpaid) {
            throw new CrudException('The selected fines are already settled.');
        }
        $byMember = [];
        foreach ($unpaid as $r) {
            $byMember[(int) $r['member_id']][] = $r;
        }
        $receipts = [];
        foreach ($byMember as $memberId => $list) {
            $receipt = lib_next_receipt_no();
            $sum = 0.0;
            foreach ($list as $r) {
                db_update('library_fines', ['status' => 'paid', 'paid_at' => date('Y-m-d H:i:s'), 'payment_mode' => $mode, 'receipt_no' => $receipt, 'collected_by' => user_id()]
                    + ($remarks ? ['remarks' => $remarks] : []), 'id = ?', [(int) $r['id']]);
                $sum += (float) $r['amount'];
            }
            $m = lib_member($memberId);
            $receipts[] = ['receipt_no' => $receipt, 'member' => $m['name'] ?? '', 'membership_no' => $m['membership_no'] ?? '', 'amount' => round($sum, 2), 'count' => count($list), 'fine_id' => (int) $list[0]['id']];
            log_activity('update', 'library', implode(',', array_column($list, 'id')), sprintf('Collected library fine %s from %s (%s) via %s · receipt %s', money($sum, 2), $m['name'] ?? '', $m['membership_no'] ?? '', lib_payment_modes()[$mode], $receipt));
        }
        return ['receipts' => $receipts, 'skipped' => count($ids) - count($unpaid)];
    });
}

function lib_waive_fines(array $ids, string $reason): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        throw new CrudException('Select at least one fine to waive.');
    }
    $reason = trim($reason);
    if (mb_strlen($reason) < 3) {
        throw new CrudValidationException(['reason' => 'Enter the reason for waiving the fine.']);
    }
    return db_transaction(function () use ($ids, $reason) {
        $rows = db_all("SELECT fn.id, fn.amount, fn.member_id FROM library_fines fn WHERE fn.status = 'unpaid' AND fn.id IN (" . implode(',', $ids) . ') FOR UPDATE');
        if (!$rows) {
            throw new CrudException('The selected fines are already settled.');
        }
        foreach ($rows as $r) {
            db_update('library_fines', ['status' => 'waived', 'waived_at' => date('Y-m-d H:i:s'), 'waived_by' => user_id(), 'waive_reason' => mb_substr($reason, 0, 255)], 'id = ?', [(int) $r['id']]);
        }
        $sum = array_sum(array_map(fn ($r) => (float) $r['amount'], $rows));
        log_activity('update', 'library', implode(',', array_column($rows, 'id')), sprintf('Waived %d library fine%s totalling %s · %s', count($rows), count($rows) > 1 ? 's' : '', money($sum, 2), str_limit($reason, 120)));
        return ['waived' => count($rows), 'amount' => round($sum, 2), 'skipped' => count($ids) - count($rows)];
    });
}

/* ------------------------------------------------------------------
 * Books & copies
 * ------------------------------------------------------------------ */

/** Normalise an ISBN (digits + X). */
function lib_clean_isbn(?string $isbn): ?string
{
    $v = strtoupper(preg_replace('/[^0-9Xx]/', '', (string) $isbn));
    return $v === '' ? null : $v;
}

function lib_isbn_valid(string $isbn): bool
{
    if (preg_match('/^\d{13}$/', $isbn)) {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $isbn[$i] * ($i % 2 ? 3 : 1);
        }
        return (10 - $sum % 10) % 10 === (int) $isbn[12];
    }
    if (preg_match('/^\d{9}[\dX]$/', $isbn)) {
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += ($isbn[$i] === 'X' ? 10 : (int) $isbn[$i]) * (10 - $i);
        }
        return $sum % 11 === 0;
    }
    return false;
}

/** Resolve author ids or names (import) into ids, creating missing authors by name. */
function lib_resolve_authors($value, bool $create = true): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : preg_split('/\s*[,;|]\s*/', $value);
    }
    $ids = [];
    foreach ((array) $value as $v) {
        $v = is_string($v) ? trim($v) : $v;
        if ($v === '' || $v === null) {
            continue;
        }
        if (is_int($v) || ctype_digit((string) $v)) {
            if (db_value('SELECT id FROM authors WHERE id = ?', [(int) $v])) {
                $ids[] = (int) $v;
            }
            continue;
        }
        $id = db_value('SELECT id FROM authors WHERE name = ?', [mb_substr((string) $v, 0, 150)]);
        if (!$id && $create) {
            $id = db_insert('authors', ['name' => mb_substr((string) $v, 0, 150)]);
        }
        if ($id) {
            $ids[] = (int) $id;
        }
    }
    return array_values(array_unique($ids));
}

function lib_sync_authors(int $bookId, array $authorIds): void
{
    db_exec('DELETE FROM book_authors WHERE book_id = ?', [$bookId]);
    foreach (array_values($authorIds) as $i => $aid) {
        db_insert('book_authors', ['book_id' => $bookId, 'author_id' => (int) $aid, 'sort_order' => $i]);
    }
    db_update('books', ['author_id' => $authorIds[0] ?? null], 'id = ?', [$bookId]);
}

function lib_next_accession(): string
{
    for ($i = 0; $i < 200; $i++) {
        $no = next_number('library_accession', 'ACC{n:6}');
        if (!db_value('SELECT COUNT(*) FROM book_copies WHERE accession_no = ?', [$no])) {
            return $no;
        }
    }
    throw new CrudException('Unable to generate an accession number. Please enter one manually.');
}

/**
 * Add N copies of a book.
 * $opts: condition, acquired_date, price, rack_no, remarks
 */
function lib_add_copies(int $bookId, int $count, array $opts = []): array
{
    $book = db_row('SELECT * FROM books WHERE id = ?', [$bookId]);
    if (!$book) {
        throw new CrudException('Book not found.');
    }
    if ($count < 1 || $count > 100) {
        throw new CrudValidationException(['count' => 'Enter between 1 and 100 copies.']);
    }
    $condition = (string) ($opts['condition'] ?? 'new');
    if (!in_array($condition, ['new', 'good', 'fair', 'poor'], true)) {
        throw new CrudValidationException(['condition' => 'Select a valid condition.']);
    }
    $acquired = trim((string) ($opts['acquired_date'] ?? '')) ?: date('Y-m-d');
    $d = DateTime::createFromFormat('Y-m-d', $acquired);
    if (!$d || $d->format('Y-m-d') !== $acquired || $acquired > date('Y-m-d')) {
        throw new CrudValidationException(['acquired_date' => 'Acquired date must be a valid date, not in the future.']);
    }
    $price = $opts['price'] ?? null;
    if ($price !== null && $price !== '' && (!is_numeric($price) || (float) $price < 0)) {
        throw new CrudValidationException(['price' => 'Price must be zero or more.']);
    }
    $price = ($price === null || $price === '') ? ($book['price'] !== null ? (float) $book['price'] : null) : round((float) $price, 2);
    $rack = trim((string) ($opts['rack_no'] ?? '')) ?: $book['rack_no'];
    $remarks = mb_substr(trim((string) ($opts['remarks'] ?? '')), 0, 255) ?: null;
    $created = db_transaction(function () use ($bookId, $count, $condition, $acquired, $price, $rack, $remarks) {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $acc = lib_next_accession();
            db_insert('book_copies', ['book_id' => $bookId, 'accession_no' => $acc, 'barcode' => $acc, 'rack_no' => $rack, 'condition_note' => $condition,
                'acquired_date' => $acquired, 'price' => $price, 'status' => 'available', 'remarks' => $remarks]);
            $out[] = $acc;
        }
        lib_recount_book($bookId);
        return $out;
    });
    log_activity('create', 'library', $bookId, sprintf('Added %d cop%s of "%s" (%s)', $count, $count === 1 ? 'y' : 'ies', str_limit($book['title'], 80), $created[0] . ($count > 1 ? ' – ' . end($created) : '')));
    return $created;
}

/** Change status of copies that are not on loan (available|damaged|lost|withdrawn). */
function lib_set_copy_status(array $ids, string $status, ?string $remarks = null): array
{
    if (!in_array($status, ['available', 'damaged', 'lost', 'withdrawn'], true)) {
        throw new CrudValidationException(['status' => 'Select a valid status.']);
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        throw new CrudException('Select at least one copy.');
    }
    return db_transaction(function () use ($ids, $status, $remarks) {
        $rows = db_all('SELECT id, book_id, status, accession_no FROM book_copies WHERE id IN (' . implode(',', $ids) . ') FOR UPDATE');
        $changed = 0;
        $skipped = 0;
        $books = [];
        foreach ($rows as $r) {
            if ($r['status'] === 'issued') {
                $skipped++;
                continue;
            }
            db_update('book_copies', ['status' => $status] + ($remarks ? ['remarks' => mb_substr($remarks, 0, 255)] : []), 'id = ?', [(int) $r['id']]);
            $books[(int) $r['book_id']] = true;
            $changed++;
        }
        foreach (array_keys($books) as $b) {
            lib_recount_book($b);
        }
        if ($changed) {
            log_activity('update', 'library', implode(',', array_slice($ids, 0, 20)), 'Marked ' . $changed . ' book cop' . ($changed === 1 ? 'y' : 'ies') . ' as ' . $status);
        }
        return ['changed' => $changed, 'skipped_issued' => $skipped];
    });
}

/** Book detail page payload. */
function lib_book_detail(int $id): ?array
{
    $b = db_row('SELECT b.*, bc.name AS category_name, pb.name AS publisher_name, pb.city AS publisher_city, d.name AS department_name, ' . lib_authors_sql('b') . ' AS authors,
                        (SELECT GROUP_CONCAT(ba.author_id ORDER BY ba.sort_order) FROM book_authors ba WHERE ba.book_id = b.id) AS author_ids
                 FROM books b LEFT JOIN book_categories bc ON bc.id = b.category_id LEFT JOIN publishers pb ON pb.id = b.publisher_id LEFT JOIN departments d ON d.id = b.department_id WHERE b.id = ?', [$id]);
    if (!$b) {
        return null;
    }
    $counts = db_pairs('SELECT status, COUNT(*) FROM book_copies WHERE book_id = ? GROUP BY status', [$id]);
    $stats = db_row("SELECT COUNT(*) AS loans, COUNT(DISTINCT member_id) AS readers, SUM(status = 'issued') AS on_loan,
                     SUM(status = 'issued' AND due_date < CURDATE()) AS overdue, MAX(issue_date) AS last_issued,
                     SUM(issue_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)) AS loans_90
                     FROM library_transactions WHERE book_id = ?", [$id]);
    $current = array_map('lib_loan_public', db_all(lib_loan_sql() . " WHERE t.book_id = ? AND t.status = 'issued' ORDER BY t.due_date", [$id]));
    $monthly = db_pairs("SELECT DATE_FORMAT(issue_date, '%Y-%m') AS ym, COUNT(*) FROM library_transactions WHERE book_id = ? AND issue_date >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH) GROUP BY ym", [$id]);
    $trend = [];
    for ($i = 11; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
        $trend[] = ['month' => $ym, 'label' => date('M y', strtotime($ym . '-01')), 'issues' => (int) ($monthly[$ym] ?? 0)];
    }
    foreach (['id', 'author_id', 'category_id', 'publisher_id', 'department_id', 'total_copies', 'available_copies', 'pages', 'publish_year'] as $k) {
        $b[$k] = $b[$k] !== null ? (int) $b[$k] : null;
    }
    $b['price'] = $b['price'] !== null ? (float) $b['price'] : null;
    return [
        'book' => $b,
        'copies' => ['total' => array_sum(array_map('intval', $counts)), 'available' => (int) ($counts['available'] ?? 0), 'issued' => (int) ($counts['issued'] ?? 0),
            'damaged' => (int) ($counts['damaged'] ?? 0), 'lost' => (int) ($counts['lost'] ?? 0), 'withdrawn' => (int) ($counts['withdrawn'] ?? 0), 'reserved' => (int) ($counts['reserved'] ?? 0)],
        'stats' => ['loans' => (int) $stats['loans'], 'readers' => (int) $stats['readers'], 'on_loan' => (int) $stats['on_loan'], 'overdue' => (int) $stats['overdue'],
            'last_issued' => $stats['last_issued'], 'loans_90' => (int) $stats['loans_90']],
        'current' => $current, 'trend' => $trend,
    ];
}

/* ------------------------------------------------------------------
 * Dashboard
 * ------------------------------------------------------------------ */

function lib_dashboard(): array
{
    $rules = lib_rules();
    $session = current_session();
    $sessionStart = $session['start_date'] ?? date('Y-04-01');
    $k = db_row("SELECT
            (SELECT COUNT(*) FROM books WHERE status = 'active') AS titles,
            (SELECT COUNT(*) FROM book_copies WHERE status NOT IN ('lost','withdrawn')) AS copies,
            (SELECT COUNT(*) FROM book_copies WHERE status = 'issued') AS issued,
            (SELECT COUNT(*) FROM book_copies WHERE status = 'available') AS available,
            (SELECT COUNT(*) FROM book_copies WHERE status IN ('damaged','lost')) AS damaged_lost,
            (SELECT COUNT(*) FROM library_transactions WHERE status = 'issued' AND due_date < CURDATE()) AS overdue,
            (SELECT COUNT(*) FROM library_transactions WHERE status = 'issued' AND due_date = CURDATE()) AS due_today,
            (SELECT COUNT(*) FROM library_transactions WHERE issue_date = CURDATE()) AS issued_today,
            (SELECT COUNT(*) FROM library_transactions WHERE status = 'returned' AND return_date = CURDATE()) AS returned_today,
            (SELECT COUNT(*) FROM library_members WHERE status = 'active' AND (valid_until IS NULL OR valid_until >= CURDATE())) AS members,
            (SELECT COUNT(DISTINCT member_id) FROM library_transactions WHERE status = 'issued') AS active_borrowers,
            (SELECT COALESCE(SUM(amount), 0) FROM library_fines WHERE status = 'paid') AS fine_collected,
            (SELECT COALESCE(SUM(amount), 0) FROM library_fines WHERE status = 'paid' AND paid_at >= ?) AS fine_collected_session,
            (SELECT COALESCE(SUM(amount), 0) FROM library_fines WHERE status = 'paid' AND paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS fine_collected_month,
            (SELECT COALESCE(SUM(amount), 0) FROM library_fines WHERE status = 'unpaid') AS fine_pending,
            (SELECT COALESCE(SUM(GREATEST(DATEDIFF(CURDATE(), due_date) - ?, 0)), 0) FROM library_transactions WHERE status = 'issued' AND due_date < CURDATE()) AS overdue_days", [$sessionStart, $rules['grace_days']]);
    $kpis = array_map(fn ($v) => is_numeric($v) && str_contains((string) $v, '.') ? (float) $v : (int) $v, $k);
    $kpis['accrued_fine'] = round($kpis['overdue_days'] * $rules['fine_per_day'], 2);
    $kpis['utilisation'] = $kpis['copies'] ? round($kpis['issued'] * 100 / $kpis['copies'], 1) : 0;

    // Issues / returns per month (last 8 months)
    $from = date('Y-m-01', strtotime(date('Y-m-01') . ' -7 months'));
    $issues = db_pairs("SELECT DATE_FORMAT(issue_date, '%Y-%m') AS ym, COUNT(*) FROM library_transactions WHERE issue_date >= ? GROUP BY ym", [$from]);
    $returns = db_pairs("SELECT DATE_FORMAT(return_date, '%Y-%m') AS ym, COUNT(*) FROM library_transactions WHERE status = 'returned' AND return_date >= ? GROUP BY ym", [$from]);
    $fines = db_pairs("SELECT DATE_FORMAT(paid_at, '%Y-%m') AS ym, SUM(amount) FROM library_fines WHERE status = 'paid' AND paid_at >= ? GROUP BY ym", [$from]);
    $monthly = [];
    for ($i = 7; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
        $monthly[] = ['month' => $ym, 'label' => date('M y', strtotime($ym . '-01')), 'issues' => (int) ($issues[$ym] ?? 0), 'returns' => (int) ($returns[$ym] ?? 0), 'fines' => (float) ($fines[$ym] ?? 0)];
    }

    $categories = db_all("SELECT bc.id, bc.name, COUNT(DISTINCT b.id) AS titles, COUNT(c.id) AS copies
                          FROM book_categories bc JOIN books b ON b.category_id = bc.id LEFT JOIN book_copies c ON c.book_id = b.id AND c.status NOT IN ('lost','withdrawn')
                          GROUP BY bc.id, bc.name ORDER BY copies DESC");
    $cat = array_slice($categories, 0, 7);
    if (count($categories) > 7) {
        $rest = array_slice($categories, 7);
        $cat[] = ['id' => null, 'name' => 'Others', 'titles' => array_sum(array_column($rest, 'titles')), 'copies' => array_sum(array_column($rest, 'copies'))];
    }
    $cat = array_map(fn ($r) => ['id' => $r['id'] !== null ? (int) $r['id'] : null, 'name' => $r['name'], 'titles' => (int) $r['titles'], 'copies' => (int) $r['copies']], $cat);

    $overdueRows = db_all(lib_loan_sql() . " WHERE t.status = 'issued' AND t.due_date < CURDATE() ORDER BY t.due_date ASC, t.id LIMIT 8");
    $overdue = array_map(fn ($r) => lib_loan_public($r, $rules), $overdueRows);

    $popular = db_all("SELECT b.id, b.title, b.cover_image, b.total_copies, b.available_copies, " . lib_authors_sql('b') . " AS authors, MAX(bc.name) AS category, COUNT(t.id) AS issues
                       FROM library_transactions t JOIN books b ON b.id = t.book_id LEFT JOIN book_categories bc ON bc.id = b.category_id
                       WHERE t.issue_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY b.id ORDER BY issues DESC, b.title LIMIT 8");
    $popular = array_map(fn ($r) => ['id' => (int) $r['id'], 'title' => $r['title'], 'authors' => $r['authors'], 'category' => $r['category'], 'cover_image' => $r['cover_image'],
        'issues' => (int) $r['issues'], 'total_copies' => (int) $r['total_copies'], 'available_copies' => (int) $r['available_copies']], $popular);

    $recent = db_all("SELECT t.id, t.status, t.issue_date, t.return_date, t.due_date, t.created_at, t.updated_at, b.title, c.accession_no, " . lib_member_name_sql('lm') . " AS member_name, lm.membership_no, lm.member_type
                      FROM library_transactions t JOIN books b ON b.id = t.book_id JOIN book_copies c ON c.id = t.book_copy_id JOIN library_members lm ON lm.id = t.member_id " . lib_member_joins('lm') . "
                      ORDER BY COALESCE(t.updated_at, t.created_at) DESC, t.id DESC LIMIT 8");
    $recent = array_map(fn ($r) => ['id' => (int) $r['id'], 'status' => $r['status'], 'title' => $r['title'], 'accession_no' => $r['accession_no'], 'member_name' => $r['member_name'],
        'membership_no' => $r['membership_no'], 'member_type' => $r['member_type'], 'issue_date' => $r['issue_date'], 'return_date' => $r['return_date'], 'due_date' => $r['due_date'],
        'at' => $r['updated_at'] ?: $r['created_at']], $recent);

    $mix = db_all("SELECT lm.member_type, COUNT(*) AS loans FROM library_transactions t JOIN library_members lm ON lm.id = t.member_id
                   WHERE t.issue_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY lm.member_type ORDER BY loans DESC");

    return [
        'kpis' => $kpis, 'monthly' => $monthly, 'categories' => $cat, 'overdue' => $overdue, 'popular' => $popular, 'recent' => $recent,
        'member_mix' => array_map(fn ($r) => ['type' => $r['member_type'], 'loans' => (int) $r['loans']], $mix),
        'rules' => ['fine_per_day' => $rules['fine_per_day'], 'grace_days' => $rules['grace_days'], 'max_renewals' => $rules['max_renewals']],
        'session' => $session['name'] ?? null,
    ];
}

/* ------------------------------------------------------------------
 * Print views (A4, website CSS) — served by api/routes/library.php
 * ------------------------------------------------------------------ */

/** Code 39 barcode as inline SVG (A–Z, 0–9, - . space $ / + %). */
function lib_code39_svg(string $text, int $height = 36, string $class = 'w-full'): string
{
    static $map = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn', '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn',
        '7' => 'nnnwnnwnw', '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw', 'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw',
        'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn', 'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn', 'K' => 'wnnnnnnww',
        'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww', 'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw', 'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn',
        'Z' => 'nwwnwnnnn', '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn', '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn',
        '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
    ];
    $chars = '*' . preg_replace('/[^0-9A-Z\-. $\/+%]/', '', strtoupper($text)) . '*';
    $x = 0;
    $rects = '';
    for ($i = 0, $n = strlen($chars); $i < $n; $i++) {
        $pattern = $map[$chars[$i]];
        for ($j = 0; $j < 9; $j++) {
            $w = $pattern[$j] === 'w' ? 3 : 1;
            if ($j % 2 === 0) {
                $rects .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="' . $height . '"/>';
            }
            $x += $w;
        }
        $x += 1;
    }
    return '<svg class="' . e($class) . '" viewBox="0 0 ' . $x . ' ' . $height . '" preserveAspectRatio="none" style="height:' . $height . 'px" role="img" aria-label="Barcode ' . e($text) . '" fill="#0f172a">' . $rects . '</svg>';
}

function lib_amount_words(float $amount): string
{
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two = fn (int $n) => $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
    $three = fn (int $n) => trim((intdiv($n, 100) ? $ones[intdiv($n, 100)] . ' Hundred ' : '') . ($n % 100 ? $two($n % 100) : ''));
    $r = (int) floor($amount);
    $p = (int) round(($amount - $r) * 100);
    if ($r === 0 && $p === 0) {
        return 'Zero Rupees Only';
    }
    $parts = [];
    foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as [$div, $word]) {
        if ($r >= $div) {
            $q = intdiv($r, $div);
            $parts[] = ($q < 100 ? $two($q) : $three($q)) . ' ' . $word;
            $r %= $div;
        }
    }
    if ($r) {
        $parts[] = $three($r);
    }
    return trim(implode(' ', $parts)) . ' Rupees' . ($p ? ' and ' . $two($p) . ' Paise' : '') . ' Only';
}

/**
 * Guard for the print views. They are served from the API but opened in a browser tab, so an expired session or a
 * missing permission shows a friendly HTML page (with a sign-in link) instead of a JSON error.
 */
function lib_print_guard(string $action = 'view'): void
{
    $loggedIn = session_is_valid() || login_from_remember_cookie();
    if ($loggedIn && can('library', $action)) {
        return;
    }
    if ($loggedIn) {
        log_activity('denied', 'library', null, "Access denied: $action library (print view)", 'failed');
    }
    http_response_code($loggedIn ? 403 : 401);
    header('Content-Type: text/html; charset=utf-8');
    [$title, $text, $btn, $href] = $loggedIn
        ? ['You don\'t have access to this page', 'Your role does not include the "' . $action . '" permission for the library.', 'Back to dashboard', admin_url('')]
        : ['Please sign in to continue', 'Your session has expired. Sign in to the admin panel, then open this print view again.', 'Sign in', admin_url('login')];
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . '</title>'
        . '<link rel="stylesheet" href="' . e(asset('assets/css/style.css')) . '"></head><body class="bg-slate-50">'
        . '<div class="grid min-h-screen place-items-center p-6"><div class="card w-full max-w-md p-8 text-center">'
        . '<p class="font-display text-lg font-bold text-brand-900">' . e($title) . '</p><p class="mt-2 text-sm text-slate-500">' . e($text) . '</p>'
        . '<a href="' . e($href) . '" class="btn btn-primary btn-sm mt-5">' . e($btn) . '</a></div></div></body></html>';
    exit;
}

function lib_print_not_found(string $title, string $message): void
{
    http_response_code(404);
    print_layout_start($title);
    echo '<div class="py-24 text-center"><p class="font-display text-xl font-bold text-brand-900">' . e($title) . '</p><p class="mt-2 text-sm text-slate-500">' . e($message) . '</p>'
        . '<a href="' . e(admin_url('library')) . '" class="btn btn-primary btn-sm mt-5 no-print">Back to library</a></div>';
    print_layout_end();
    exit;
}

/** Library membership cards (2 per row, CR80 size) for one or more members. */
function lib_print_member_cards(array $ids): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $members = array_values(array_filter(array_map('lib_member', array_slice($ids, 0, 200))));
    if (!$members) {
        lib_print_not_found('Member not found', 'The library member does not exist or has been deleted.');
    }
    log_activity('print', 'library', implode(',', array_slice($ids, 0, 20)), 'Printed ' . count($members) . ' library card' . (count($members) > 1 ? 's' : ''));
    print_layout_start(count($members) === 1 ? 'Library Card - ' . $members[0]['membership_no'] : 'Library Cards (' . count($members) . ')', false);
    $short = setting('institute_short_name', 'GIMT');
    echo '<div class="mb-4 flex items-end justify-between border-b border-slate-200 pb-3"><div><h1 class="font-display text-lg font-extrabold text-brand-900">Library Membership Cards</h1>'
        . '<p class="text-[11px] text-slate-500">' . count($members) . ' card' . (count($members) > 1 ? 's' : '') . ' · Cut along the card borders · Generated ' . e(format_datetime(date('Y-m-d H:i:s'))) . '</p></div>'
        . '<img src="' . e(logo_url()) . '" alt="' . e($short) . '" class="h-10 w-auto"></div>';
    echo '<div class="grid grid-cols-2 gap-5">';
    foreach ($members as $m) {
        $photo = $m['photo'] ? '<img src="' . e(upload_url($m['photo'])) . '" alt="" class="h-[22mm] w-[18mm] rounded-md object-cover ring-1 ring-slate-200">'
            : '<div class="flex h-[22mm] w-[18mm] items-center justify-center rounded-md bg-brand-50 font-display text-lg font-bold text-brand-800 ring-1 ring-brand-100">' . e(initials(preg_replace('/^(Dr|Prof|Mr|Mrs|Ms)\.?\s+/i', '', (string) $m['name']))) . '</div>';
        echo '<div class="break-inside-avoid overflow-hidden rounded-xl border border-slate-300" style="width:85.6mm;height:54mm">'
            . '<div class="flex items-center justify-between bg-brand-900 px-3 py-1.5 text-white"><div class="leading-tight"><p class="text-[9px] font-bold uppercase tracking-wide">' . e(institute_name()) . '</p>'
            . '<p class="text-[7px] uppercase tracking-[0.2em] text-accent-300">Central Library · Membership Card</p></div>'
            . '<span class="rounded bg-white/15 px-1.5 py-0.5 text-[8px] font-bold uppercase">' . e(lib_member_types()[$m['member_type']] ?? $m['member_type']) . '</span></div>'
            . '<div class="flex gap-2.5 px-3 pt-2">' . $photo
            . '<div class="min-w-0 flex-1 text-[8.5px] leading-snug text-slate-600"><p class="truncate font-display text-[11px] font-bold text-slate-900">' . e($m['name']) . '</p>'
            . '<p class="truncate">' . e($m['info'] ?? '') . '</p>'
            . '<p>ID: <strong class="text-slate-800">' . e($m['code'] ?? '—') . '</strong></p>'
            . '<p>Borrowing limit: <strong class="text-slate-800">' . (int) $m['max_books'] . ' books · ' . (int) $m['loan_days'] . ' days</strong></p>'
            . '<p>Valid till: <strong class="' . ($m['is_expired'] ? 'text-red-600' : 'text-slate-800') . '">' . e($m['valid_until'] ? format_date($m['valid_until']) : 'Until further notice') . '</strong></p></div>'
            . '<div data-qr="' . e($m['membership_no']) . '" class="h-[15mm] w-[15mm] shrink-0"></div></div>'
            . '<div class="px-3 pt-1.5">' . lib_code39_svg($m['membership_no'], 22) . '<p class="mt-0.5 text-center font-mono text-[9px] font-bold tracking-[0.3em] text-slate-800">' . e($m['membership_no']) . '</p></div>'
            . '</div>';
    }
    echo '</div><p class="mt-6 text-[10px] text-slate-400">This card is the property of ' . e(institute_name()) . '. If found, please return it to the Central Library. Cards are non-transferable.</p>';
    print_layout_end();
}

/** Fine payment receipt (all fines settled under one receipt number). */
function lib_print_fine_receipt(string $receiptNo, int $fineId = 0): void
{
    if ($receiptNo === '' && $fineId) {
        $receiptNo = (string) db_value('SELECT receipt_no FROM library_fines WHERE id = ?', [$fineId]);
    }
    $rows = $receiptNo !== '' ? array_map('lib_fine_public', db_all(lib_fine_sql() . " WHERE fn.receipt_no = ? AND fn.status = 'paid' ORDER BY fn.id", [$receiptNo])) : [];
    if (!$rows) {
        lib_print_not_found('Receipt not found', 'No paid library fine was found for this receipt. Collect the fine first to generate a receipt.');
    }
    $first = $rows[0];
    $m = lib_member($first['member_id']);
    $total = array_sum(array_column($rows, 'amount'));
    log_activity('print', 'library', $first['id'], 'Printed library fine receipt ' . $receiptNo);
    print_layout_start('Library Fine Receipt - ' . $receiptNo, false);
    $copy = function (string $label, bool $letterhead = true) use ($rows, $first, $m, $total, $receiptNo) {
        echo '<section class="break-inside-avoid rounded-xl border border-slate-300 px-5 py-4">' . ($letterhead ? print_letterhead() : '<p class="font-display text-sm font-extrabold uppercase text-brand-900">' . e(institute_name()) . ' · Central Library</p>')
            . '<div class="mt-3 flex items-center justify-between"><h1 class="font-display text-base font-extrabold uppercase tracking-wide text-brand-900">Library Fine Receipt</h1>'
            . '<span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-600">' . e($label) . '</span></div>'
            . '<div class="mt-3 grid grid-cols-3 gap-3 text-[11px] text-slate-600"><div>Receipt No<br><strong class="font-mono text-[12px] text-slate-900">' . e($receiptNo) . '</strong></div>'
            . '<div>Date<br><strong class="text-[12px] text-slate-900">' . e(format_datetime($first['paid_at'])) . '</strong></div>'
            . '<div class="flex justify-end"><div data-qr="' . e($receiptNo) . '" class="h-14 w-14"></div></div></div>'
            . '<table class="mt-3 w-full border-collapse text-[11px]"><tbody>'
            . '<tr><th class="w-[30%] border border-slate-200 bg-slate-50 px-2 py-1.5 text-left font-semibold text-slate-600">Received from</th><td class="border border-slate-200 px-2 py-1.5 font-semibold text-slate-900">' . e($first['member_name']) . ' <span class="font-normal text-slate-500">(' . e($m['info'] ?? '') . ')</span></td></tr>'
            . '<tr><th class="border border-slate-200 bg-slate-50 px-2 py-1.5 text-left font-semibold text-slate-600">Membership No</th><td class="border border-slate-200 px-2 py-1.5 font-mono">' . e($first['membership_no']) . ($m && $m['code'] ? ' · ID ' . e($m['code']) : '') . '</td></tr>'
            . '<tr><th class="border border-slate-200 bg-slate-50 px-2 py-1.5 text-left font-semibold text-slate-600">Payment mode</th><td class="border border-slate-200 px-2 py-1.5">' . e(lib_payment_modes()[$first['payment_mode']] ?? label_from_key((string) $first['payment_mode'])) . '</td></tr>'
            . '</tbody></table>'
            . '<table class="mt-3 w-full border-collapse text-[11px]"><thead><tr>'
            . '<th class="border border-brand-900 bg-brand-900 px-2 py-1.5 text-left font-semibold text-white">Particulars</th>'
            . '<th class="border border-brand-900 bg-brand-900 px-2 py-1.5 text-left font-semibold text-white">Book / Accession</th>'
            . '<th class="border border-brand-900 bg-brand-900 px-2 py-1.5 text-right font-semibold text-white">Amount (₹)</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $part = label_from_key($r['reason'] === 'late_return' ? 'overdue_fine' : $r['reason'] . '_charge') . ($r['days_overdue'] ? ' (' . $r['days_overdue'] . ' days)' : '');
            echo '<tr><td class="border border-slate-200 px-2 py-1.5">' . e($part) . '</td><td class="border border-slate-200 px-2 py-1.5">' . e($r['book_title'] ? str_limit($r['book_title'], 60) : '—') . ($r['accession_no'] ? ' <span class="font-mono text-slate-500">' . e($r['accession_no']) . '</span>' : '') . '</td>'
                . '<td class="border border-slate-200 px-2 py-1.5 text-right tabular-nums">' . e(number_format($r['amount'], 2)) . '</td></tr>';
        }
        echo '<tr><td colspan="2" class="border border-slate-200 px-2 py-1.5 text-right font-semibold">Total received</td><td class="border border-slate-200 px-2 py-1.5 text-right font-bold tabular-nums">' . e(number_format($total, 2)) . '</td></tr>'
            . '</tbody></table><p class="mt-2 text-[11px] text-slate-700">Amount in words: <strong>' . e(lib_amount_words($total)) . '</strong></p>'
            . '<div class="mt-4 flex items-end justify-between text-[11px] text-slate-600"><p class="max-w-xs text-[10px] text-slate-400">' . e(setting('receipt_footer_note', 'This is a computer generated receipt and does not require a signature.')) . '</p>'
            . '<div class="text-right"><div class="w-44 border-t border-slate-400 pt-1">Received by' . ($first['collected_by'] ? ': ' . e($first['collected_by']) : '') . '</div></div></div></section>';
    };
    echo '<div class="space-y-4">';
    $copy('Member copy');
    echo '<div class="border-t border-dashed border-slate-300 text-center text-[9px] uppercase tracking-widest text-slate-400">cut here</div>';
    $copy('Library copy', false);
    echo '</div>';
    print_layout_end();
}

/** Overdue books report (letterhead, sorted by days overdue). */
function lib_print_overdue_report(array $filters = []): void
{
    $rules = lib_rules();
    $where = "t.status = 'issued' AND t.due_date < CURDATE()";
    $args = [];
    if (!empty($filters['member_type']) && isset(lib_member_types()[$filters['member_type']])) {
        $where .= ' AND lm.member_type = ?';
        $args[] = $filters['member_type'];
    }
    $rows = array_map(fn ($r) => lib_loan_public($r, $rules), db_all(lib_loan_sql() . " WHERE $where ORDER BY t.due_date ASC, t.id LIMIT 2000", $args));
    log_activity('print', 'library', null, 'Printed overdue books report (' . count($rows) . ' loans)');
    print_layout_start('Overdue Books Report', true, 'landscape');
    $totalFine = array_sum(array_column($rows, 'accrued_fine'));
    echo '<div class="mt-5 mb-3 flex items-end justify-between"><div><h1 class="font-display text-xl font-bold text-brand-900">Overdue Books Report</h1>'
        . '<p class="text-xs text-slate-500">As on ' . e(format_date(date('Y-m-d'))) . ' · ' . count($rows) . ' overdue loan' . (count($rows) === 1 ? '' : 's') . ' · Fine ' . e(money($rules['fine_per_day'])) . '/day'
        . ($rules['grace_days'] ? ' after ' . $rules['grace_days'] . ' grace day(s)' : '') . (!empty($filters['member_type']) ? ' · ' . e(lib_member_types()[$filters['member_type']] ?? '') . ' members' : '') . '</p></div>'
        . '<div class="text-right text-xs text-slate-500">Accrued fines<br><strong class="font-display text-lg text-red-600">' . e(money($totalFine, 2)) . '</strong></div></div>';
    echo '<table class="w-full border-collapse text-[10.5px]"><thead><tr>';
    foreach (['#', 'Member', 'Membership No', 'Contact', 'Book', 'Accession', 'Issued', 'Due', 'Days', 'Fine (₹)', 'Reminders'] as $h) {
        echo '<th class="border border-brand-900 bg-brand-900 px-2 py-1.5 text-left font-semibold text-white">' . e($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $i => $r) {
        echo '<tr class="' . ($i % 2 ? 'bg-slate-50' : '') . '"><td class="border border-slate-200 px-2 py-1">' . ($i + 1) . '</td>'
            . '<td class="border border-slate-200 px-2 py-1"><strong>' . e($r['member']['name']) . '</strong><br><span class="text-slate-500">' . e($r['member']['info'] ?? '') . '</span></td>'
            . '<td class="border border-slate-200 px-2 py-1 font-mono">' . e($r['member']['membership_no']) . '</td>'
            . '<td class="border border-slate-200 px-2 py-1">' . e($r['member']['phone'] ?? '') . '<br><span class="text-slate-500">' . e($r['member']['email'] ?? '') . '</span></td>'
            . '<td class="border border-slate-200 px-2 py-1">' . e(str_limit($r['title'], 70)) . '</td>'
            . '<td class="border border-slate-200 px-2 py-1 font-mono">' . e($r['accession_no']) . '</td>'
            . '<td class="border border-slate-200 px-2 py-1 whitespace-nowrap">' . e(format_date($r['issue_date'])) . '</td>'
            . '<td class="border border-slate-200 px-2 py-1 whitespace-nowrap">' . e(format_date($r['due_date'])) . '</td>'
            . '<td class="border border-slate-200 px-2 py-1 text-right font-semibold text-red-600">' . (int) $r['days_overdue'] . '</td>'
            . '<td class="border border-slate-200 px-2 py-1 text-right tabular-nums">' . e(number_format($r['accrued_fine'], 2)) . '</td>'
            . '<td class="border border-slate-200 px-2 py-1 text-center">' . (int) $r['reminder_count'] . '</td></tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="11" class="border border-slate-200 px-2 py-6 text-center text-slate-500">No overdue books. Every loan is within its due date.</td></tr>';
    }
    echo '</tbody></table>';
    echo '<div class="mt-8 flex justify-end text-[11px] text-slate-600"><div class="w-48 border-t border-slate-400 pt-1 text-center">Librarian</div></div>';
    print_layout_end();
}

/** Spine / accession labels with Code 39 barcodes for the copies of a book or a list of copies. */
function lib_print_copy_labels(int $bookId, array $copyIds = []): void
{
    $copyIds = array_values(array_filter(array_map('intval', $copyIds)));
    $where = $copyIds ? 'c.id IN (' . implode(',', $copyIds) . ')' : "c.book_id = ? AND c.status NOT IN ('lost','withdrawn')";
    $args = $copyIds ? [] : [$bookId];
    $rows = db_all('SELECT c.accession_no, c.barcode, c.rack_no, b.title, b.rack_no AS book_rack, b.shelf_no, bc.code AS class_code, ' . lib_authors_sql('b') . " AS authors
                    FROM book_copies c JOIN books b ON b.id = c.book_id LEFT JOIN book_categories bc ON bc.id = b.category_id WHERE $where ORDER BY c.accession_no LIMIT 400", $args);
    if (!$rows) {
        lib_print_not_found('No copies to label', 'Add copies to this book first, then print their accession labels.');
    }
    log_activity('print', 'library', $bookId ?: null, 'Printed ' . count($rows) . ' accession label' . (count($rows) > 1 ? 's' : ''));
    print_layout_start('Accession Labels (' . count($rows) . ')', false);
    echo '<div class="mb-4 flex items-end justify-between border-b border-slate-200 pb-3"><div><h1 class="font-display text-lg font-extrabold text-brand-900">Accession &amp; Barcode Labels</h1>'
        . '<p class="text-[11px] text-slate-500">' . count($rows) . ' label' . (count($rows) > 1 ? 's' : '') . ' · ' . e(institute_name()) . ' Central Library</p></div></div>';
    echo '<div class="grid grid-cols-3 gap-3">';
    foreach ($rows as $r) {
        $call = trim(($r['class_code'] ? $r['class_code'] . ' ' : '') . strtoupper(mb_substr((string) ($r['authors'] ?? ''), 0, 3)));
        $loc = trim(($r['rack_no'] ?: $r['book_rack'] ?: '') . ($r['shelf_no'] ? ' / ' . $r['shelf_no'] : ''));
        echo '<div class="break-inside-avoid rounded-lg border border-slate-300 px-2.5 py-2">'
            . '<div class="flex items-start justify-between gap-2"><p class="line-clamp-2 text-[9px] font-semibold leading-tight text-slate-800">' . e(str_limit($r['title'], 70)) . '</p>'
            . '<span class="shrink-0 rounded bg-brand-900 px-1.5 py-0.5 font-mono text-[8px] font-bold text-white">' . e($call ?: 'GEN') . '</span></div>'
            . '<div class="mt-1.5">' . lib_code39_svg((string) ($r['barcode'] ?: $r['accession_no']), 28) . '</div>'
            . '<div class="mt-0.5 flex items-center justify-between font-mono text-[9px]"><strong class="tracking-widest text-slate-900">' . e($r['accession_no']) . '</strong><span class="text-slate-500">' . e($loc ?: '—') . '</span></div></div>';
    }
    echo '</div>';
    print_layout_end();
}
