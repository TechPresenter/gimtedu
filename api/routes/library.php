<?php
/**
 * Library API (circulation, members, copies, fines, dashboard, print views).
 *
 *   GET    /library/dashboard                         KPIs, monthly issues/returns, categories, overdue, popular, recent
 *   GET    /library/rules                             circulation rules (settings) + definitions
 *   PUT    /library/rules                             save rules                                       (library.manage)
 *
 *   GET    /library/members/search?q=                 member finder for the issue desk (rich rows)
 *   GET    /library/members/candidates?type=&q=       people who are not members yet (Option[])
 *   GET    /library/members/{id}                      member card: profile, limits, loans, fines, history
 *   GET    /library/members/enroll-preview            ?target=section|program|all_students|faculty|staff&section_id&program_id&semester
 *   POST   /library/members/enroll                    bulk enroll                                      (library.create)
 *   POST   /library/members/status                    {ids[], status: active|suspended, remarks}       (library.edit)
 *
 *   GET    /library/copies/lookup?code=               copy by accession no / barcode with book + open loan
 *   GET    /library/copies/available?q=               available copies for the issue desk (Option[])
 *   POST   /library/copies/status                     {ids[], status, remarks}                         (library.edit)
 *   GET    /library/books/{id}                        book detail (copies, stats, current loans, trend)
 *   POST   /library/books/{id}/copies                 {count, condition, acquired_date, price, rack_no, remarks} (library.create)
 *
 *   POST   /library/issue                             {member_id, accession_no, due_date?, remarks?}  (library.create)
 *   GET    /library/loans/{id}                        loan detail
 *   GET    /library/loans/{id}/preview                ?return_date&condition&damage_charge  -> fine preview for return / lost
 *   POST   /library/loans/{id}/return                 {return_date, condition, damage_charge, fine_action, payment_mode, waive_reason, remarks} (library.edit)
 *   POST   /library/loans/{id}/renew                                                                   (library.edit)
 *   POST   /library/loans/{id}/lost                   {amount?, fine_action, payment_mode, waive_reason, remarks} (library.edit)
 *   POST   /library/remind                            {ids[]} | {all_overdue: true}                    (library.edit)
 *
 *   GET    /library/fines/summary                     totals for the fines screen
 *   POST   /library/fines/collect                     {ids[], payment_mode, remarks}                   (library.edit)
 *   POST   /library/fines/waive                       {ids[], reason}                                  (library.manage)
 *
 *   GET    /library/print/cards?ids=1,2               membership cards (A4, 2 per row)
 *   GET    /library/print/receipt?no=|fine_id=        fine receipt (member + library copy)
 *   GET    /library/print/overdue?member_type=        overdue books report (landscape)
 *   GET    /library/print/labels?book_id=|ids=|codes= accession / barcode spine labels
 */
require_once APP_ROOT . '/app/services/library.php';

/* ------------------------------------------------------------------ Dashboard & rules */

route('GET', '/library/dashboard', function () {
    api_require('library', 'view');
    api_ok(lib_dashboard());
});

route('GET', '/library/rules', function () {
    api_require('library', 'view');
    $defs = [];
    foreach (lib_rule_defs() as $key => [$label, $type, $default, $min, $max]) {
        $raw = setting($key, $default);
        $defs[] = ['key' => $key, 'label' => $label, 'type' => $type, 'default' => $default, 'min' => $min, 'max' => $max,
            'value' => $type === 'money' ? (float) $raw : (int) $raw];
    }
    api_ok(['rules' => lib_rules(), 'definitions' => $defs, 'can_manage' => can('library', 'manage')]);
});

route('PUT', '/library/rules', function () {
    api_require('library', 'manage');
    $in = api_input();
    $errors = [];
    $clean = [];
    foreach (lib_rule_defs() as $key => [$label, $type, $default, $min, $max]) {
        if (!array_key_exists($key, $in)) {
            continue;
        }
        $v = $in[$key];
        if ($type === 'bool') {
            $clean[$key] = in_array($v, [true, 1, '1', 'true', 'on', 'yes'], true) ? '1' : '0';
            continue;
        }
        if ($v === '' || $v === null || !is_numeric($v)) {
            $errors[$key] = $label . ' must be a number.';
            continue;
        }
        $n = $type === 'money' ? round((float) $v, 2) : (int) $v;
        if ($type === 'int' && (string) (int) $v !== (string) $v && (float) $v != (int) $v) {
            $errors[$key] = $label . ' must be a whole number.';
        } elseif ($n < $min || $n > $max) {
            $errors[$key] = $label . " must be between $min and $max.";
        } else {
            $clean[$key] = (string) $n;
        }
    }
    if ($errors) {
        api_error('Please fix the highlighted fields and try again.', 422, $errors);
    }
    if (!$clean) {
        api_error('Nothing to save.', 422);
    }
    $before = lib_rules();
    foreach ($clean as $k => $v) {
        save_setting($k, $v, 'library');
    }
    $changed = [];
    foreach ($clean as $k => $v) {
        $changed[] = lib_rule_defs()[$k][0] . ' = ' . $v;
    }
    log_activity('update', 'library', null, 'Updated library circulation rules: ' . str_limit(implode('; ', $changed), 400), 'success', ['before' => $before]);
    api_ok(['rules' => lib_rules()], 'Circulation rules saved.');
});

/* ------------------------------------------------------------------ Members */

route('GET', '/library/members/search', function () {
    api_require('library', 'view');
    $q = trim((string) ($_GET['q'] ?? ''));
    $where = [];
    $args = [];
    if ($q !== '') {
        foreach (preg_split('/\s+/', mb_substr($q, 0, 80)) as $word) {
            $or = [];
            foreach (['lm.membership_no', 's.first_name', 's.last_name', 's.student_uid', 's.mobile', 'f.first_name', 'f.last_name', 'f.employee_id', 'st.first_name', 'st.last_name', 'st.employee_id'] as $col) {
                $or[] = "$col LIKE ?";
                $args[] = '%' . $word . '%';
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
    }
    if (!empty($_GET['type']) && isset(lib_member_types()[$_GET['type']])) {
        $where[] = 'lm.member_type = ?';
        $args[] = $_GET['type'];
    }
    $limit = max(1, min(20, (int) ($_GET['limit'] ?? 8)));
    $rows = db_all('SELECT lm.*, ' . lib_member_cols('lm') . ', md.name AS department_name,
                (SELECT COUNT(*) FROM library_transactions t WHERE t.member_id = lm.id AND t.status = \'issued\') AS loans,
                (SELECT COUNT(*) FROM library_transactions t WHERE t.member_id = lm.id AND t.status = \'issued\' AND t.due_date < CURDATE()) AS overdue,
                (SELECT COALESCE(SUM(fn.amount), 0) FROM library_fines fn WHERE fn.member_id = lm.id AND fn.status = \'unpaid\') AS unpaid_fines
            FROM library_members lm ' . lib_member_joins('lm') . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . "
            ORDER BY (lm.membership_no = ?) DESC, lm.status = 'active' DESC, member_name LIMIT $limit", array_merge($args, [$q]));
    api_ok(array_map('lib_member_public', $rows));
});

route('GET', '/library/members/candidates', function () {
    api_require('library', 'view');
    $type = (string) ($_GET['type'] ?? 'student');
    $q = trim((string) ($_GET['q'] ?? ''));
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));
    $like = '%' . $q . '%';
    if ($type === 'faculty') {
        $sql = "SELECT f.id AS value, CONCAT(TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)), ' (', f.employee_id, ')') AS label, f.designation AS sub
                FROM faculty f WHERE f.status IN ('active','on_leave') AND NOT EXISTS (SELECT 1 FROM library_members lm WHERE lm.faculty_id = f.id)
                AND (? = '' OR f.first_name LIKE ? OR f.last_name LIKE ? OR f.employee_id LIKE ? OR CONCAT(f.first_name, ' ', f.last_name) LIKE ?) ORDER BY f.first_name LIMIT $limit";
    } elseif ($type === 'staff') {
        $sql = "SELECT st.id AS value, CONCAT(TRIM(CONCAT_WS(' ', st.first_name, st.last_name)), ' (', st.employee_id, ')') AS label, st.designation AS sub
                FROM staff st WHERE st.status IN ('active','on_leave') AND NOT EXISTS (SELECT 1 FROM library_members lm WHERE lm.staff_id = st.id)
                AND (? = '' OR st.first_name LIKE ? OR st.last_name LIKE ? OR st.employee_id LIKE ? OR CONCAT(st.first_name, ' ', st.last_name) LIKE ?) ORDER BY st.first_name LIMIT $limit";
    } else {
        $sql = "SELECT s.id AS value, CONCAT(TRIM(CONCAT_WS(' ', s.first_name, s.last_name)), ' (', s.student_uid, ')') AS label, CONCAT(COALESCE(p.short_name, ''), ' · Sem ', s.current_semester) AS sub
                FROM students s LEFT JOIN programs p ON p.id = s.program_id WHERE s.status = 'active' AND NOT EXISTS (SELECT 1 FROM library_members lm WHERE lm.student_id = s.id)
                AND (? = '' OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_uid LIKE ? OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?) ORDER BY s.first_name, s.last_name LIMIT $limit";
    }
    if (!empty($_GET['ids'])) {
        // Labels for already-selected values (Combobox initial options)
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $_GET['ids']))));
        if (!$ids) {
            api_ok([]);
        }
        $in = implode(',', $ids);
        $sql = match ($type) {
            'faculty' => "SELECT f.id AS value, TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS label, f.designation AS sub FROM faculty f WHERE f.id IN ($in)",
            'staff' => "SELECT st.id AS value, TRIM(CONCAT_WS(' ', st.first_name, st.last_name)) AS label, st.designation AS sub FROM staff st WHERE st.id IN ($in)",
            default => "SELECT s.id AS value, CONCAT(TRIM(CONCAT_WS(' ', s.first_name, s.last_name)), ' (', s.student_uid, ')') AS label, NULL AS sub FROM students s WHERE s.id IN ($in)",
        };
        api_ok(array_map(fn ($r) => ['value' => (int) $r['value'], 'label' => $r['label']] + ($r['sub'] ? ['sub' => $r['sub']] : []), db_all($sql)));
    }
    $rows = db_all($sql, [$q, $like, $like, $like, $like]);
    api_ok(array_map(fn ($r) => ['value' => (int) $r['value'], 'label' => $r['label']] + ($r['sub'] ? ['sub' => $r['sub']] : []), $rows));
});

route('GET', '/library/members/enroll-preview', function () {
    api_require('library', 'view');
    $in = $_GET;
    $target = (string) ($in['target'] ?? '');
    if (!in_array($target, ['section', 'program', 'all_students', 'faculty', 'staff'], true)) {
        api_error('Choose who to enroll.', 422, ['target' => 'Choose who to enroll.']);
    }
    $total = lib_enroll_target_total($in);
    $new = count(lib_enroll_candidates($in));
    $type = in_array($target, ['faculty', 'staff'], true) ? $target : 'student';
    api_ok(['total' => $total, 'new' => $new, 'already' => max(0, $total - $new), 'defaults' => lib_rules()['types'][$type] + ['valid_until' => lib_default_valid_until()], 'type' => $type]);
});

route('POST', '/library/members/enroll', function () {
    api_require('library', 'create');
    $in = api_input();
    $target = (string) ($in['target'] ?? '');
    $errors = [];
    if (!in_array($target, ['section', 'program', 'all_students', 'faculty', 'staff'], true)) {
        $errors['target'] = 'Choose who to enroll.';
    }
    if ($target === 'section' && empty($in['section_id'])) {
        $errors['section_id'] = 'Select a section.';
    }
    if ($target === 'program' && empty($in['program_id'])) {
        $errors['program_id'] = 'Select a program.';
    }
    $errors += validate($in, ['max_books' => 'integer|min:1|max:30', 'loan_days' => 'integer|min:1|max:365', 'valid_until' => 'date', 'semester' => 'integer|min:1|max:12'],
        ['max_books' => 'Books allowed', 'loan_days' => 'Loan period', 'valid_until' => 'Valid until']);
    if (empty($errors['valid_until']) && !empty($in['valid_until']) && $in['valid_until'] <= date('Y-m-d')) {
        $errors['valid_until'] = 'Validity must be a future date.';
    }
    if ($errors) {
        api_error('Please fix the highlighted fields and try again.', 422, $errors);
    }
    $res = lib_enroll($in);
    if (!$res['created']) {
        api_error($res['total'] ? 'Everyone in this group is already a library member.' : 'No active ' . ($res['type'] === 'student' ? 'students' : $res['type']) . ' found for this selection.', 422);
    }
    $label = match ($target) {
        'section' => 'section ' . (db_value("SELECT CONCAT(p.short_name, ' · Sem ', sc.semester_no, ' · Sec ', sc.name) FROM sections sc JOIN programs p ON p.id = sc.program_id WHERE sc.id = ?", [(int) $in['section_id']]) ?: '#' . (int) $in['section_id']),
        'program' => 'program ' . (db_value('SELECT short_name FROM programs WHERE id = ?', [(int) $in['program_id']]) ?: '#' . (int) $in['program_id']) . (!empty($in['semester']) ? ' · Sem ' . (int) $in['semester'] : ''),
        'all_students' => 'all active students', 'faculty' => 'all faculty', default => 'all staff',
    };
    log_activity('create', 'library', null, sprintf('Enrolled %d library member%s from %s', $res['created'], $res['created'] === 1 ? '' : 's', $label));
    api_ok($res, sprintf('%d member%s enrolled.%s', $res['created'], $res['created'] === 1 ? '' : 's', $res['already'] ? ' ' . $res['already'] . ($res['already'] === 1 ? ' was already a member.' : ' were already members.') : ''));
});

route('POST', '/library/members/status', function () {
    api_require('library', 'edit');
    $in = api_input();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['ids'] ?? [])))));
    $status = (string) ($in['status'] ?? '');
    $remarks = trim((string) ($in['remarks'] ?? ''));
    $errors = [];
    if (!$ids) {
        api_error('Select at least one member.', 422);
    }
    if (!in_array($status, ['active', 'suspended'], true)) {
        $errors['status'] = 'Choose activate or suspend.';
    }
    if ($status === 'suspended' && mb_strlen($remarks) < 3) {
        $errors['remarks'] = 'Enter the reason for suspending the membership.';
    }
    $validUntil = trim((string) ($in['valid_until'] ?? ''));
    if ($validUntil !== '' && (!DateTime::createFromFormat('Y-m-d', $validUntil) || $validUntil <= date('Y-m-d'))) {
        $errors['valid_until'] = 'Validity must be a future date.';
    }
    if ($errors) {
        api_error('Please fix the highlighted fields and try again.', 422, $errors);
    }
    $n = db_transaction(function () use ($ids, $status, $remarks, $validUntil) {
        $set = ['status' => $status, 'remarks' => $remarks !== '' ? mb_substr($remarks, 0, 255) : null];
        $count = 0;
        foreach ($ids as $id) {
            $row = db_row('SELECT id, status, valid_until FROM library_members WHERE id = ?', [$id]);
            if (!$row) {
                continue;
            }
            $data = $set;
            if ($status === 'active') {
                if ($validUntil !== '') {
                    $data['valid_until'] = $validUntil;
                } elseif ($row['valid_until'] && $row['valid_until'] < date('Y-m-d')) {
                    $data['valid_until'] = lib_default_valid_until();
                }
            }
            db_update('library_members', $data, 'id = ?', [$id]);
            $count++;
        }
        return $count;
    });
    $nos = db_column('SELECT membership_no FROM library_members WHERE id IN (' . implode(',', $ids) . ') LIMIT 10');
    log_activity('update', 'library', implode(',', array_slice($ids, 0, 20)), ($status === 'suspended' ? 'Suspended ' : 'Activated ') . $n . ' library membership' . ($n === 1 ? '' : 's') . ' (' . implode(', ', $nos) . ($n > 10 ? '…' : '') . ')' . ($remarks ? ' · ' . str_limit($remarks, 120) : ''));
    if ($status === 'suspended') {
        foreach ($ids as $id) {
            $m = lib_member($id);
            if ($m && $m['user_id']) {
                notify($m['user_id'], 'system', 'Library membership suspended', 'Your library membership ' . $m['membership_no'] . ' has been suspended. Reason: ' . $remarks, null, 'ban');
            }
        }
    }
    api_ok(['updated' => $n], $n . ' membership' . ($n === 1 ? '' : 's') . ($status === 'suspended' ? ' suspended.' : ' activated.'));
});

route('GET', '/library/members/{id:\d+}', function ($p) {
    api_require('library', 'view');
    $card = lib_member_card((int) $p['id']);
    if (!$card) {
        api_error('Member not found. It may have been deleted.', 404);
    }
    api_ok($card);
});

/* ------------------------------------------------------------------ Copies & books */

route('GET', '/library/copies/lookup', function () {
    api_require('library', 'view');
    $code = trim((string) ($_GET['code'] ?? ''));
    if ($code === '') {
        api_error('Enter an accession number or barcode.', 422, ['code' => 'Enter an accession number or barcode.']);
    }
    $res = lib_find_copy($code);
    if (!$res) {
        api_error('No copy found with accession number or barcode "' . $code . '".', 404, ['code' => 'No copy found with this accession number.']);
    }
    api_ok($res);
});

route('GET', '/library/copies/available', function () {
    api_require('library', 'view');
    $q = trim((string) ($_GET['q'] ?? ''));
    $limit = max(1, min(30, (int) ($_GET['limit'] ?? 15)));
    $args = [];
    $where = "c.status = 'available' AND b.status = 'active' AND b.type <> 'reference'";
    if ($q !== '') {
        $where .= ' AND (b.title LIKE ? OR b.isbn LIKE ? OR c.accession_no LIKE ? OR c.barcode LIKE ? OR EXISTS (SELECT 1 FROM book_authors ba JOIN authors a ON a.id = ba.author_id WHERE ba.book_id = b.id AND a.name LIKE ?))';
        $args = array_fill(0, 5, '%' . $q . '%');
    }
    if (!empty($_GET['ids'])) {
        $codes = array_slice(array_values(array_filter(array_map('trim', explode(',', (string) $_GET['ids'])))), 0, 50);
        if (!$codes) {
            api_ok([]);
        }
        $where = 'c.accession_no IN (' . implode(',', array_fill(0, count($codes), '?')) . ')';
        $args = $codes;
    }
    $rows = db_all("SELECT c.accession_no AS value, b.title, " . lib_authors_sql('b') . " AS authors, COALESCE(NULLIF(c.rack_no, ''), b.rack_no) AS rack
                    FROM book_copies c JOIN books b ON b.id = c.book_id WHERE $where ORDER BY b.title, c.accession_no LIMIT $limit", $args);
    api_ok(array_map(fn ($r) => ['value' => $r['value'], 'label' => $r['value'] . ' — ' . $r['title'], 'sub' => trim(($r['authors'] ?? '') . ($r['rack'] ? ' · Rack ' . $r['rack'] : ''), ' ·')], $rows));
});

route('POST', '/library/copies/status', function () {
    api_require('library', 'edit');
    $in = api_input();
    $res = lib_set_copy_status((array) ($in['ids'] ?? []), (string) ($in['status'] ?? ''), isset($in['remarks']) ? trim((string) $in['remarks']) : null);
    $msg = $res['changed'] . ' cop' . ($res['changed'] === 1 ? 'y' : 'ies') . ' marked ' . label_from_key((string) $in['status']) . '.';
    if ($res['skipped_issued']) {
        $msg .= ' ' . $res['skipped_issued'] . ' on loan skipped.';
    }
    api_ok($res, $msg);
});

route('GET', '/library/books/{id:\d+}', function ($p) {
    api_require('library', 'view');
    $d = lib_book_detail((int) $p['id']);
    if (!$d) {
        api_error('Book not found. It may have been deleted.', 404);
    }
    $d['can'] = ['edit' => can('library', 'edit'), 'create' => can('library', 'create'), 'delete' => can('library', 'delete')];
    api_ok($d);
});

route('POST', '/library/books/{id:\d+}/copies', function ($p) {
    api_require('library', 'create');
    $in = api_input();
    $count = $in['count'] ?? null;
    if ($count === null || $count === '' || !ctype_digit((string) $count)) {
        api_error('Please fix the highlighted fields and try again.', 422, ['count' => 'Enter how many copies to add (1–100).']);
    }
    $created = lib_add_copies((int) $p['id'], (int) $count, $in);
    api_ok(['accession_nos' => $created], count($created) . ' cop' . (count($created) === 1 ? 'y' : 'ies') . ' added (' . $created[0] . (count($created) > 1 ? ' – ' . end($created) : '') . ').');
});

/* ------------------------------------------------------------------ Circulation */

route('POST', '/library/issue', function () {
    api_require('library', 'create');
    $loan = lib_issue(api_input());
    api_ok($loan, sprintf('"%s" issued to %s. Due on %s.', str_limit($loan['title'], 60), $loan['member']['name'], format_date($loan['due_date'])));
});

route('GET', '/library/loans/{id:\d+}', function ($p) {
    api_require('library', 'view');
    $loan = lib_loan((int) $p['id']);
    if (!$loan) {
        api_error('Loan not found.', 404);
    }
    api_ok($loan);
});

route('GET', '/library/loans/{id:\d+}/preview', function ($p) {
    api_require('library', 'view');
    $loan = lib_loan((int) $p['id']);
    if (!$loan) {
        api_error('Loan not found.', 404);
    }
    $rules = lib_rules();
    $date = trim((string) ($_GET['return_date'] ?? '')) ?: date('Y-m-d');
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        $date = date('Y-m-d');
    }
    $late = lib_compute_fine($loan['due_date'], max($date, $loan['issue_date']), $rules);
    $damage = ($_GET['condition'] ?? 'good') === 'damaged' && is_numeric($_GET['damage_charge'] ?? null) ? round(max(0, (float) $_GET['damage_charge']), 2) : 0;
    $lostLate = lib_compute_fine($loan['due_date'], date('Y-m-d'), $rules);
    api_ok([
        'loan' => $loan,
        'return' => ['date' => $date, 'late' => $late, 'damage' => $damage, 'total' => round($late['amount'] + $damage, 2)],
        'lost' => ['price' => (float) ($loan['price'] ?? 0), 'processing_fee' => $rules['lost_processing_fee'], 'default_amount' => round((float) ($loan['price'] ?? 0) + $rules['lost_processing_fee'], 2),
            'late' => $lostLate],
        'rules' => ['fine_per_day' => $rules['fine_per_day'], 'grace_days' => $rules['grace_days'], 'max_renewals' => $rules['max_renewals']],
        'payment_modes' => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(lib_payment_modes()), lib_payment_modes()),
        'can_waive' => can('library', 'manage'),
    ]);
});

route('POST', '/library/loans/{id:\d+}/return', function ($p) {
    api_require('library', 'edit');
    $res = lib_return((int) $p['id'], api_input());
    $f = $res['fine'];
    $msg = sprintf('"%s" returned by %s.', str_limit($res['loan']['title'], 60), $res['loan']['member']['name']);
    if ($f['total'] > 0) {
        $msg .= ' Fine ' . money($f['total'], 2) . ($f['action'] === 'collect' ? ' collected (' . $f['receipt_no'] . ').' : ($f['action'] === 'waive' ? ' waived.' : ' added to dues.'));
    }
    api_ok($res, $msg);
});

route('POST', '/library/loans/{id:\d+}/renew', function ($p) {
    api_require('library', 'edit');
    $loan = lib_renew((int) $p['id']);
    api_ok($loan, sprintf('Renewed until %s (renewal %d).', format_date($loan['due_date']), $loan['renew_count']));
});

route('POST', '/library/loans/{id:\d+}/lost', function ($p) {
    api_require('library', 'edit');
    $res = lib_mark_lost((int) $p['id'], api_input());
    $f = $res['fine'];
    $msg = sprintf('"%s" marked lost.', str_limit($res['loan']['title'], 60));
    if ($f['total'] > 0) {
        $msg .= ' Fine ' . money($f['total'], 2) . ($f['action'] === 'collect' ? ' collected (' . $f['receipt_no'] . ').' : ($f['action'] === 'waive' ? ' waived.' : ' added to dues.'));
    }
    api_ok($res, $msg);
});

route('POST', '/library/remind', function () {
    api_require('library', 'edit');
    $in = api_input();
    $ids = (array) ($in['ids'] ?? []);
    if (!empty($in['all_overdue'])) {
        $ids = db_column("SELECT id FROM library_transactions WHERE status = 'issued' AND due_date < CURDATE()");
        if (!$ids) {
            api_error('There are no overdue books right now.', 422);
        }
    }
    $res = lib_remind($ids);
    $msg = $res['sent'] . ' reminder' . ($res['sent'] === 1 ? '' : 's') . ' sent.';
    if ($res['no_contact']) {
        $msg .= ' ' . $res['no_contact'] . ' member' . ($res['no_contact'] === 1 ? ' has' : 's have') . ' no email, phone or login.';
    }
    if ($res['skipped']) {
        $msg .= ' ' . $res['skipped'] . ' already returned.';
    }
    api_ok($res, $msg);
});

/* ------------------------------------------------------------------ Fines */

route('GET', '/library/fines/summary', function () {
    api_require('library', 'view');
    $session = current_session();
    $start = $session['start_date'] ?? date('Y-01-01');
    $r = db_row("SELECT
            COALESCE(SUM(CASE WHEN status = 'unpaid' THEN amount END), 0) AS unpaid, SUM(status = 'unpaid') AS unpaid_count,
            COUNT(DISTINCT CASE WHEN status = 'unpaid' THEN member_id END) AS unpaid_members,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN amount END), 0) AS paid, SUM(status = 'paid') AS paid_count,
            COALESCE(SUM(CASE WHEN status = 'paid' AND paid_at >= ? THEN amount END), 0) AS paid_session,
            COALESCE(SUM(CASE WHEN status = 'paid' AND paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN amount END), 0) AS paid_month,
            COALESCE(SUM(CASE WHEN status = 'paid' AND DATE(paid_at) = CURDATE() THEN amount END), 0) AS paid_today,
            COALESCE(SUM(CASE WHEN status = 'waived' THEN amount END), 0) AS waived, SUM(status = 'waived') AS waived_count
        FROM library_fines", [$start]) ?? [];
    $byReason = db_all("SELECT reason, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(CASE WHEN status = 'unpaid' THEN amount END), 0) AS unpaid FROM library_fines GROUP BY reason ORDER BY amount DESC");
    $rules = lib_rules();
    $accrued = (float) db_value('SELECT COALESCE(SUM(GREATEST(DATEDIFF(CURDATE(), due_date) - ?, 0)), 0) FROM library_transactions WHERE status = \'issued\' AND due_date < CURDATE()', [$rules['grace_days']]) * $rules['fine_per_day'];
    api_ok([
        'totals' => array_map(fn ($v) => is_numeric($v) && str_contains((string) $v, '.') ? (float) $v : (int) $v, $r),
        'by_reason' => array_map(fn ($x) => ['reason' => $x['reason'], 'count' => (int) $x['count'], 'amount' => (float) $x['amount'], 'unpaid' => (float) $x['unpaid']], $byReason),
        'accrued' => round($accrued, 2),
        'payment_modes' => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(lib_payment_modes()), lib_payment_modes()),
        'can_waive' => can('library', 'manage'),
        'can_collect' => can('library', 'edit'),
        'session' => $session['name'] ?? null,
    ]);
});

route('POST', '/library/fines/collect', function () {
    api_require('library', 'edit');
    $in = api_input();
    $res = lib_collect_fines((array) ($in['ids'] ?? []), (string) ($in['payment_mode'] ?? ''), isset($in['remarks']) ? (string) $in['remarks'] : null);
    $total = array_sum(array_column($res['receipts'], 'amount'));
    $msg = 'Collected ' . money($total, 2) . ' · ' . count($res['receipts']) . ' receipt' . (count($res['receipts']) === 1 ? ' (' . $res['receipts'][0]['receipt_no'] . ')' : 's') . '.';
    if ($res['skipped']) {
        $msg .= ' ' . $res['skipped'] . ' already settled.';
    }
    api_ok($res, $msg);
});

route('POST', '/library/fines/waive', function () {
    api_require('library', 'manage');
    $in = api_input();
    $res = lib_waive_fines((array) ($in['ids'] ?? []), (string) ($in['reason'] ?? ''));
    api_ok($res, $res['waived'] . ' fine' . ($res['waived'] === 1 ? '' : 's') . ' waived (' . money($res['amount'], 2) . ').' . ($res['skipped'] ? ' ' . $res['skipped'] . ' already settled.' : ''));
});

/* ------------------------------------------------------------------ Print views (HTML) */

route('GET', '/library/print/cards', function () {
    lib_print_guard();
    $ids = array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? $_GET['id'] ?? ''))));
    if (!$ids && !empty($_GET['section_id'])) {
        $ids = db_column('SELECT lm.id FROM library_members lm JOIN students s ON s.id = lm.student_id WHERE s.section_id = ? ORDER BY s.first_name', [(int) $_GET['section_id']]);
    }
    lib_print_member_cards($ids);
    exit;
}, ['auth' => false]);

route('GET', '/library/print/receipt', function () {
    lib_print_guard();
    lib_print_fine_receipt(trim((string) ($_GET['no'] ?? '')), (int) ($_GET['fine_id'] ?? 0));
    exit;
}, ['auth' => false]);

route('GET', '/library/print/overdue', function () {
    lib_print_guard();
    lib_print_overdue_report(['member_type' => (string) ($_GET['member_type'] ?? '')]);
    exit;
}, ['auth' => false]);

route('GET', '/library/print/labels', function () {
    lib_print_guard();
    $ids = array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))));
    $codes = array_slice(array_values(array_filter(array_map('trim', explode(',', (string) ($_GET['codes'] ?? ''))))), 0, 400);
    if ($codes) {
        $ids = array_merge($ids, db_column('SELECT id FROM book_copies WHERE accession_no IN (' . implode(',', array_fill(0, count($codes), '?')) . ')', $codes));
        if (!$ids) {
            $ids = [0];
        }
    }
    lib_print_copy_labels((int) ($_GET['book_id'] ?? 0), $ids);
    exit;
}, ['auth' => false]);
