<?php
/**
 * Fees & accounts endpoints (CRUD lists live under /api/crud/{student_fees|payments|fee_structures|fee_heads|scholarships|fee_concessions|refunds}).
 *
 *   GET  /fees/meta                                   payment modes, fee head types, gateway, late fee rule
 *   GET  /fees/dashboard?session_id=                  KPIs, monthly collection vs target, mode split, program dues, recent, overdue
 *   GET  /fees/structures/{id}                        structure with items + installment plan
 *   POST /fees/structures                             create (items[], installments[])                    fees.create
 *   POST /fees/structures/{id}                        update                                              fees.edit
 *   POST /fees/structures/{id}/duplicate              copy (optionally into another session)              fees.create
 *   GET  /fees/structures/{id}/assign                 preview: matched / already invoiced / to create     fees.create
 *   POST /fees/structures/{id}/assign                 generate invoices + items + installments            fees.create
 *   GET  /fees/invoices/{id}                          invoice detail (items, installments, concessions, payments)
 *   POST /fees/invoices                               raise a manual invoice                               fees.create
 *   POST /fees/invoices/{id}/concession               discount / waiver                                    fees.edit
 *   POST /fees/invoices/{id}/late-fee                 charge accrued late fee                              fees.edit
 *   POST /fees/invoices/late-fee                      bulk late fee {ids[]} or {all_overdue: true}         fees.edit
 *   POST /fees/invoices/{id}/cancel                   cancel with reason                                   fees.edit
 *   POST /fees/reminders                              {ids[]} notify + email + SMS                         fees.edit
 *   GET  /fees/students/search?q=                     async student search with balance (collection counter)
 *   GET  /fees/students/{id}/profile                  fee profile: open invoices, installments, concessions
 *   POST /fees/collect                                collect a payment (receipt)                          fees.create
 *   GET  /fees/payments/search?q=                     successful payments (refund requests)
 *   GET  /fees/payments/{id}                          payment detail
 *   POST /fees/payments/{id}/email                    email the receipt to the student                     fees.create
 *   POST /fees/payments/{id}/void                     void with reason, restores balances                  fees.approve
 *   POST /fees/scholarships/{id}/award                {student_ids[], reason, preview?}                    fees.edit
 *   POST /fees/refunds                                {payment_id, amount, reason}                         fees.create
 *   POST /fees/refunds/{id}/{approve|reject|process}  workflow                                             fees.approve
 *   GET  /fees/reports/{collection|outstanding|defaulters|headwise|daybook}?from&to&mode&program_id&semester&collector&q&page&format=csv|xlsx|print
 *   GET  /fees/collectors                             users who have collected payments (report filter)
 */
require_once APP_ROOT . '/app/services/fees.php';

function fees_api_int_list($v): array
{
    if (is_string($v)) {
        $v = explode(',', $v);
    }
    return array_values(array_filter(array_map('intval', (array) $v)));
}

route('GET', '/fees/meta', function () {
    api_require('fees', 'view');
    $ses = fees_session();
    api_ok([
        'modes' => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(fees_modes()), fees_modes()),
        'enabled_modes' => array_keys(fees_enabled_modes()),
        'head_types' => fees_head_types(),
        'concession_types' => fees_concession_types(),
        'gateway' => fees_gateway_info(),
        'late_fee_per_day' => (float) setting('late_fee_per_day', 0),
        'receipt_format' => (string) setting('receipt_prefix', 'RCPT/{session}/{n:6}'),
        'invoice_format' => (string) setting('invoice_prefix', 'INV/{session}/{n:6}'),
        'session' => $ses,
        'today' => fees_today(),
    ]);
});

route('GET', '/fees/dashboard', function () {
    api_require('fees', 'view');
    api_ok(fees_dashboard(!empty($_GET['session_id']) ? (int) $_GET['session_id'] : null));
});

/* ---------------------------------------------------------------- Structures */

route('GET', '/fees/structures/{id:\d+}', function ($p) {
    api_require('fees', 'view');
    $s = fees_structure_detail((int) $p['id']);
    if (!$s) {
        api_error('Fee structure not found.', 404);
    }
    api_ok($s);
});

route('POST', '/fees/structures', function () {
    api_require('fees', 'create');
    $id = fees_save_structure(api_input());
    api_ok(fees_structure_detail($id), 'Fee structure created successfully.', 201);
});

route('POST', '/fees/structures/{id:\d+}', function ($p) {
    api_require('fees', 'edit');
    $id = fees_save_structure(api_input(), (int) $p['id']);
    $s = fees_structure_detail($id);
    api_ok($s, 'Fee structure updated.' . ((int) $s['invoiced_count'] ? ' Invoices already raised are not changed.' : ''));
});

route('POST', '/fees/structures/{id:\d+}/duplicate', function ($p) {
    api_require('fees', 'create');
    $sessionId = (int) (api_param('academic_session_id') ?? 0) ?: null;
    $id = fees_duplicate_structure((int) $p['id'], $sessionId);
    api_ok(fees_structure_detail($id), 'Structure duplicated. Review it and set it active before assigning.', 201);
});

route('GET', '/fees/structures/{id:\d+}/assign', function ($p) {
    api_require('fees', 'create');
    api_ok(fees_assign_structure((int) $p['id'], $_GET, false));
});

route('POST', '/fees/structures/{id:\d+}/assign', function ($p) {
    api_require('fees', 'create');
    $res = fees_assign_structure((int) $p['id'], api_input(), true);
    if (!$res['created']) {
        api_error($res['matched'] ? 'Every matching student is already invoiced for this structure.' : 'No active students match the selected program / semester / section.', 422);
    }
    api_ok($res, sprintf('%d invoice%s generated (%s).', $res['created'], $res['created'] === 1 ? '' : 's', money($res['total_amount'])));
});

/* ---------------------------------------------------------------- Invoices */

route('GET', '/fees/invoices/{id:\d+}', function ($p) {
    api_require('fees', 'view');
    $inv = fees_invoice_detail((int) $p['id']);
    if (!$inv) {
        api_error('Invoice not found.', 404);
    }
    api_ok($inv);
});

route('POST', '/fees/invoices', function () {
    api_require('fees', 'create');
    $in = api_input();
    $errors = validate($in, ['student_id' => 'required|integer|exists:students,id', 'title' => 'required|max:190', 'due_date' => 'required|date', 'remarks' => 'max:255'],
        ['student_id' => 'Student', 'due_date' => 'Due date']);
    $items = [];
    foreach ((array) ($in['items'] ?? []) as $k => $it) {
        $hid = (int) ($it['fee_head_id'] ?? 0);
        $amt = (float) ($it['amount'] ?? 0);
        if (!$hid && !$amt) {
            continue;
        }
        if (!$hid || !db_value("SELECT COUNT(*) FROM fee_heads WHERE id = ? AND status = 'active'", [$hid])) {
            $errors["items.$k.fee_head_id"] = 'Select a fee head.';
        }
        if ($amt <= 0 || $amt > 10000000) {
            $errors["items.$k.amount"] = 'Enter an amount greater than zero.';
        }
        $items[] = ['head' => $hid, 'amount' => $amt];
    }
    if (!$items) {
        $errors['items'] = 'Add at least one fee head with an amount.';
    }
    if (empty($errors['due_date']) && !empty($in['due_date']) && $in['due_date'] < date('Y-m-d', strtotime('-1 year'))) {
        $errors['due_date'] = 'Due date is too far in the past.';
    }
    if ($errors) {
        api_error('Please fix the highlighted fields and try again.', 422, $errors);
    }
    $sem = !empty($in['semester_no']) ? (int) $in['semester_no'] : (int) db_value('SELECT current_semester FROM students WHERE id = ?', [(int) $in['student_id']]);
    $id = fees_create_invoice((int) $in['student_id'], (string) $in['title'], $items, [
        'due_date' => $in['due_date'], 'semester_no' => $sem ?: null, 'remarks' => $in['remarks'] ?? null,
        'academic_session_id' => !empty($in['academic_session_id']) ? (int) $in['academic_session_id'] : current_session_id(),
    ]);
    api_ok(fees_invoice_detail($id), 'Invoice raised successfully.', 201);
});

route('POST', '/fees/invoices/{id:\d+}/concession', function ($p) {
    api_require('fees', 'edit');
    $in = api_validate(['type' => 'required|in:discount,waiver', 'amount' => 'required|numeric|min:1', 'reason' => 'required|min:5|max:255']);
    $id = (int) $p['id'];
    fees_add_concession($id, $in['type'], (float) $in['amount'], $in['reason']);
    $inv = fees_invoice_detail($id);
    log_activity('update', 'fees', $id, sprintf('Added %s of %s on invoice %s (%s) — %s', $in['type'], money((float) $in['amount'], 2), $inv['invoice_no'], $inv['student_name'], $in['reason']));
    api_ok($inv, ucfirst($in['type']) . ' of ' . money((float) $in['amount']) . ' applied to ' . $inv['invoice_no'] . '.');
});

route('POST', '/fees/invoices/{id:\d+}/late-fee', function ($p) {
    api_require('fees', 'edit');
    $fee = fees_apply_late_fee((int) $p['id']);
    if ($fee <= 0) {
        api_error('No late fee is due on this invoice (not overdue, already charged today, or the cap is reached).', 422);
    }
    api_ok(fees_invoice_detail((int) $p['id']), 'Late fee of ' . money($fee) . ' applied.');
});

route('POST', '/fees/invoices/late-fee', function () {
    api_require('fees', 'edit');
    $in = api_input();
    if (!empty($in['all_overdue'])) {
        fees_sync_overdue();
        $ids = db_column('SELECT t.id FROM student_fees t WHERE ' . fees_overdue_sql('t') . ' AND (t.academic_session_id = ? OR ? IS NULL)', [current_session_id(), current_session_id()]);
    } else {
        $ids = fees_api_int_list($in['ids'] ?? []);
    }
    if (!$ids) {
        api_error('Select the overdue invoices to charge.', 422, ['ids' => 'Select at least one invoice.']);
    }
    $count = 0;
    $total = 0.0;
    foreach ($ids as $id) {
        $fee = fees_apply_late_fee((int) $id, false);
        if ($fee > 0) {
            $count++;
            $total += $fee;
        }
    }
    if ($count) {
        log_activity('update', 'fees', null, sprintf('Applied late fee on %d overdue invoice(s), total %s', $count, money($total)));
    }
    api_ok(['applied' => $count, 'total' => fees_round($total), 'skipped' => count($ids) - $count],
        $count ? sprintf('Late fee of %s applied on %d invoice%s.', money($total), $count, $count === 1 ? '' : 's') : 'No late fee was due on the selected invoices.');
});

route('POST', '/fees/invoices/{id:\d+}/cancel', function ($p) {
    api_require('fees', 'edit');
    fees_cancel_invoice((int) $p['id'], (string) (api_param('reason') ?? ''));
    api_ok(fees_invoice_detail((int) $p['id']), 'Invoice cancelled.');
});

route('POST', '/fees/reminders', function () {
    api_require('fees', 'edit');
    $res = fees_send_reminders(fees_api_int_list(api_input()['ids'] ?? []));
    if (!$res['sent']) {
        api_error('No reminders were sent — the selected invoices are settled or the students have no contact details.', 422);
    }
    api_ok($res, sprintf('Reminder sent for %d invoice%s (%d email, %d SMS).', $res['sent'], $res['sent'] === 1 ? '' : 's', $res['emails'], $res['sms']));
});

/* ---------------------------------------------------------------- Collection counter */

route('GET', '/fees/students/search', function () {
    api_require('fees', 'view');
    $q = trim((string) ($_GET['q'] ?? ''));
    $ids = trim((string) ($_GET['ids'] ?? ''));
    $where = ["s.status IN ('active', 'detained', 'suspended')"];
    $args = [];
    if ($ids !== '') {
        $list = fees_api_int_list($ids);
        $where = [$list ? 's.id IN (' . implode(',', $list) . ')' : '1 = 0'];
    } elseif ($q !== '') {
        $or = [];
        foreach (['s.first_name', 's.last_name', 's.student_uid', 's.admission_no', 's.roll_no', 's.mobile', "CONCAT(s.first_name, ' ', s.last_name)"] as $col) {
            $or[] = "$col LIKE ?";
            $args[] = '%' . $q . '%';
        }
        $where[] = '(' . implode(' OR ', $or) . ')';
    }
    $rows = db_all("SELECT s.id, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS name, s.student_uid, pr.short_name, s.current_semester,
                           (SELECT COALESCE(SUM(sf.balance_amount), 0) FROM student_fees sf WHERE sf.student_id = s.id AND sf.status NOT IN ('cancelled', 'paid', 'waived')) AS balance
                    FROM students s LEFT JOIN programs pr ON pr.id = s.program_id WHERE " . implode(' AND ', $where) . ' ORDER BY ' . ($q === '' && $ids === '' ? 'balance DESC, s.first_name' : 's.first_name, s.last_name') . ' LIMIT ' . max(1, min(50, (int) ($_GET['limit'] ?? 20))), $args);
    api_ok(array_map(fn ($r) => [
        'value' => (int) $r['id'], 'label' => $r['name'] . ' (' . $r['student_uid'] . ')',
        'sub' => trim(($r['short_name'] ?? '') . ' · Sem ' . $r['current_semester']) . ' · ' . ((float) $r['balance'] > 0 ? 'Balance ' . money((float) $r['balance']) : 'No dues'),
    ], $rows));
});

route('GET', '/fees/students/{id:\d+}/profile', function ($p) {
    api_require('fees', 'view');
    $profile = fees_student_profile((int) $p['id']);
    if (!$profile) {
        api_error('Student not found.', 404);
    }
    api_ok($profile + ['gateway' => fees_gateway_info(), 'enabled_modes' => array_keys(fees_enabled_modes())]);
});

route('POST', '/fees/collect', function () {
    api_require('fees', 'create');
    $res = fees_collect(api_input());
    api_ok($res, sprintf('Payment of %s received. Receipt %s generated.', money($res['total'], 2), $res['receipt_no']), 201);
});

/* ---------------------------------------------------------------- Payments */

route('GET', '/fees/payments/search', function () {
    api_require('fees', 'view');
    $q = trim((string) ($_GET['q'] ?? ''));
    $ids = fees_api_int_list($_GET['ids'] ?? '');
    $where = ["p.status = 'success'"];
    $args = [];
    if ($ids) {
        $where = ['p.id IN (' . implode(',', $ids) . ')'];
    } elseif ($q !== '') {
        $where[] = "(p.receipt_no LIKE ? OR s.student_uid LIKE ? OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?)";
        array_push($args, "%$q%", "%$q%", "%$q%");
    }
    $rows = db_all("SELECT p.id, p.receipt_no, p.amount + p.fine_amount AS total, p.payment_date, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid,
                           (SELECT COALESCE(SUM(r.amount), 0) FROM refunds r WHERE r.payment_id = p.id AND r.status IN ('pending', 'approved', 'processed')) AS refunded
                    FROM payments p LEFT JOIN students s ON s.id = p.student_id WHERE " . implode(' AND ', $where) . ' ORDER BY p.payment_date DESC, p.id DESC LIMIT 30', $args);
    api_ok(array_map(fn ($r) => [
        'value' => (int) $r['id'], 'label' => $r['receipt_no'] . ' — ' . ($r['student_name'] ?? '—'),
        'sub' => money((float) $r['total']) . ' on ' . format_date($r['payment_date']) . ' · refundable ' . money(max(0, (float) $r['total'] - (float) $r['refunded'])),
    ], $rows));
});

route('GET', '/fees/payments/{id:\d+}', function ($p) {
    api_require('fees', 'view');
    $pay = fees_payment_detail((int) $p['id']);
    if (!$pay) {
        api_error('Payment not found.', 404);
    }
    api_ok($pay);
});

route('POST', '/fees/payments/{id:\d+}/email', function ($p) {
    api_require('fees', 'create');
    $pay = db_row("SELECT p.id, p.receipt_no, p.status, s.email FROM payments p LEFT JOIN students s ON s.id = p.student_id WHERE p.id = ?", [(int) $p['id']]);
    if (!$pay) {
        api_error('Payment not found.', 404);
    }
    if ($pay['status'] !== 'success') {
        api_error('Only successful receipts can be emailed.', 422);
    }
    if (!$pay['email']) {
        api_error('The student has no email address on record. Update the student profile first.', 422);
    }
    if (!fees_email_receipt((int) $pay['id'])) {
        api_error('The email could not be sent. Check Settings › Email and try again.', 422);
    }
    log_activity('update', 'fees', (int) $pay['id'], 'Emailed receipt ' . $pay['receipt_no'] . ' to ' . $pay['email']);
    api_ok(['emailed_to' => $pay['email']], 'Receipt ' . $pay['receipt_no'] . ' emailed to ' . $pay['email'] . '.');
});

route('POST', '/fees/payments/{id:\d+}/void', function ($p) {
    api_require('fees', 'approve');
    $res = fees_void_payment((int) $p['id'], (string) (api_param('reason') ?? ''));
    api_ok(fees_payment_detail((int) $p['id']) + ['invoices_restored' => $res['invoices']], 'Receipt voided and invoice balances restored.');
});

/* ---------------------------------------------------------------- Scholarships */

route('POST', '/fees/scholarships/{id:\d+}/award', function ($p) {
    $in = api_input();
    $preview = !empty($in['preview']);
    api_require('fees', $preview ? 'view' : 'edit');
    $reason = trim((string) ($in['reason'] ?? ''));
    if (mb_strlen($reason) > 255) {
        api_error('Please fix the highlighted fields and try again.', 422, ['reason' => 'Remarks may not be longer than 255 characters.']);
    }
    $sessionId = !empty($in['academic_session_id']) ? (int) $in['academic_session_id'] : current_session_id();
    $res = fees_award_scholarship((int) $p['id'], fees_api_int_list($in['student_ids'] ?? []), $reason, $sessionId, !$preview);
    if ($preview) {
        api_ok($res);
    }
    if (!$res['awarded']) {
        api_error('Nothing was awarded. ' . implode('; ', array_slice($res['skipped'], 0, 3)) . '.', 422);
    }
    api_ok($res, sprintf('Scholarship awarded to %d student%s (%s).', $res['awarded'], $res['awarded'] === 1 ? '' : 's', money($res['amount'])));
});

/* ---------------------------------------------------------------- Refunds */

route('POST', '/fees/refunds', function () {
    api_require('fees', 'create');
    $in = api_input();
    $id = fees_refund_request((int) ($in['payment_id'] ?? 0), (float) ($in['amount'] ?? 0), (string) ($in['reason'] ?? ''));
    api_ok(db_row('SELECT * FROM refunds WHERE id = ?', [$id]), 'Refund request submitted for approval.', 201);
});

route('POST', '/fees/refunds/{id:\d+}/{action:approve|reject|process}', function ($p) {
    api_require('fees', 'approve');
    $row = fees_refund_transition((int) $p['id'], $p['action'], api_input());
    $msg = ['approve' => 'Refund approved.', 'reject' => 'Refund rejected.', 'process' => 'Refund marked as paid.'][$p['action']];
    api_ok($row, $msg);
});

/* ---------------------------------------------------------------- Reports */

route('GET', '/fees/collectors', function () {
    api_require('fees', 'view');
    api_ok(db_all('SELECT u.id AS value, u.name AS label FROM users u WHERE u.id IN (SELECT DISTINCT collected_by FROM payments WHERE collected_by IS NOT NULL) ORDER BY u.name'));
});

route('GET', '/fees/reports/{tab:collection|outstanding|defaulters|headwise|daybook}', function ($p) {
    api_require('fees', 'view');
    $f = fees_report_filters($_GET);
    $format = (string) ($_GET['format'] ?? '');
    if (in_array($format, ['csv', 'xlsx', 'print'], true)) {
        api_require('fees', 'export');
        fees_report_export($p['tab'], $f, $format);
        exit;
    }
    [$page, $perPage] = api_pagination(25);
    $rep = fees_report($p['tab'], $f, $page, $perPage);
    api_ok($rep + ['filters' => ['from' => $f['from'], 'to' => $f['to'], 'session' => $f['session']]]);
});
