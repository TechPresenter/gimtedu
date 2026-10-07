<?php
/**
 * Fees & accounts domain logic: invoices (student_fees), installments, concessions/scholarships, late fees,
 * counter collection with receipts, voids, refunds, reminders, dashboards and reports; expenses workflow.
 *
 * Public API for other units — `require_once APP_ROOT . '/app/services/fees.php';`
 *
 *   fees_create_invoice(int $studentId, string $title, array $items, array $opts = []): int
 *       Raise an invoice for a student (e.g. hostel allocation, transport pass, re-exam fee).
 *       $items = [['head' => 'HST', 'amount' => 45000], ['head' => 3, 'amount' => 1500]]   head = fee_heads.code or id
 *       $opts  = [
 *         'due_date' => 'Y-m-d' (default today + 15 days), 'academic_session_id' => int (default current session),
 *         'semester_no' => int|null, 'fee_structure_id' => int|null, 'remarks' => string|null,
 *         'installments' => [['amount' => 30000, 'due_date' => 'Y-m-d', 'label' => '1st installment'], ...] (optional; must sum to the total),
 *         'log' => true (write an activity-log entry),
 *       ]
 *       Inserts student_fees + student_fee_items (+ fee_installments) atomically and returns the invoice id.
 *       Throws CrudException on invalid input (unknown head, non-positive total, unknown student).
 *   fees_cancel_invoice(int $invoiceId, string $reason): void
 *       Cancel an invoice that has no money received against it (throws CrudException otherwise).
 *   fees_refresh_invoice(int $invoiceId): array
 *       Recompute discount/scholarship (from fee_concessions), paid (from payments), net, status and installment
 *       balances. Call after writing payments/concessions yourself. Returns the fresh row.
 *   fees_student_totals(int $studentId, ?int $sessionId = null): array
 *       ['invoices', 'net', 'paid', 'balance', 'overdue', 'concessions'] for open (non-cancelled) invoices.
 *   fees_invoice_no(): string / fees_receipt_no(): string   — next document numbers from Settings › Payments formats.
 *
 * Notes: student_fees.discount_amount / scholarship_amount are derived from fee_concessions and paid_amount from
 * payments (payment_allocations, or payments.student_fee_id for payments recorded without allocations).
 */

require_once APP_ROOT . '/app/mailer.php';

/* ------------------------------------------------------------------
 * Constants & small helpers
 * ------------------------------------------------------------------ */

function fees_modes(): array
{
    return ['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'cheque' => 'Cheque', 'dd' => 'Demand Draft', 'upi' => 'UPI', 'card' => 'Card', 'online' => 'Online Gateway'];
}

/** Payment modes enabled in Settings › Payments (falls back to all). */
function fees_enabled_modes(): array
{
    $all = fees_modes();
    $enabled = array_filter(array_map('trim', explode(',', (string) setting('enabled_payment_modes', implode(',', array_keys($all))))));
    $out = array_intersect_key($all, array_flip($enabled));
    return $out ?: $all;
}

function fees_mode_label(?string $mode): string
{
    return fees_modes()[$mode ?? ''] ?? label_from_key((string) $mode);
}

function fees_head_types(): array
{
    return ['admission' => 'Admission', 'tuition' => 'Tuition', 'examination' => 'Examination', 'hostel' => 'Hostel', 'transport' => 'Transport', 'library' => 'Library', 'miscellaneous' => 'Miscellaneous'];
}

function fees_concession_types(): array
{
    return ['discount' => 'Discount', 'scholarship' => 'Scholarship', 'waiver' => 'Waiver'];
}

function fees_scholarship_categories(): array
{
    return ['scholarship' => 'Scholarship', 'merit' => 'Merit', 'sports' => 'Sports quota', 'sibling' => 'Sibling concession', 'staff_ward' => 'Staff ward', 'discount' => 'Discount', 'waiver' => 'Fee waiver'];
}

function fees_expense_statuses(): array
{
    return ['pending' => 'Pending approval', 'approved' => 'Approved', 'paid' => 'Paid', 'rejected' => 'Rejected'];
}

function fees_today(): string
{
    return date('Y-m-d');
}

function fees_round(float $v): float
{
    return round($v + 0.0, 2);
}

/** [id, name, start_date, end_date] of a session (default: the user's current session). */
function fees_session(?int $sessionId = null): array
{
    $row = $sessionId ? db_row('SELECT id, name, start_date, end_date FROM academic_sessions WHERE id = ?', [$sessionId]) : null;
    if (!$row) {
        $cur = current_session();
        $row = !empty($cur['id']) ? ['id' => $cur['id'], 'name' => $cur['name'], 'start_date' => $cur['start_date'] ?? date('Y') . '-07-01', 'end_date' => $cur['end_date'] ?? (date('Y') + 1) . '-06-30'] : null;
    }
    return $row ?: ['id' => null, 'name' => date('Y') . '-' . substr((string) (date('Y') + 1), 2), 'start_date' => date('Y') . '-07-01', 'end_date' => (date('Y') + 1) . '-06-30'];
}

function fees_invoice_no(): string
{
    $pattern = (string) setting('invoice_prefix', 'INV/{session}/{n:6}');
    do {
        $no = next_number('invoice', $pattern);
    } while (db_value('SELECT COUNT(*) FROM student_fees WHERE invoice_no = ?', [$no]));
    return $no;
}

function fees_receipt_no(): string
{
    $pattern = (string) setting('receipt_prefix', 'RCPT/{session}/{n:6}');
    do {
        $no = next_number('receipt', $pattern);
    } while (db_value('SELECT COUNT(*) FROM payments WHERE receipt_no = ?', [$no]));
    return $no;
}

function fees_refund_no(): string
{
    do {
        $no = next_number('refund', 'RFD/{session}/{n:4}');
    } while (db_value('SELECT COUNT(*) FROM refunds WHERE refund_no = ?', [$no]));
    return $no;
}

function fees_expense_no(): string
{
    do {
        $no = next_number('expense', 'EXP/{session}/{n:5}');
    } while (db_value('SELECT COUNT(*) FROM expenses WHERE expense_no = ?', [$no]));
    return $no;
}

/** Rupees in words (Indian numbering): 125050.5 -> "Rupees One Lakh Twenty Five Thousand Fifty and Fifty Paise Only" */
function fees_amount_in_words(float $amount): string
{
    $amount = round(abs($amount), 2);
    $rupees = (int) floor($amount);
    $paise = (int) round(($amount - $rupees) * 100);
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two = function (int $n) use ($ones, $tens): string {
        return $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
    };
    $three = function (int $n) use ($two, $ones): string {
        $h = intdiv($n, 100);
        $r = $n % 100;
        return trim(($h ? $ones[$h] . ' Hundred' : '') . ($r ? ' ' . $two($r) : ''));
    };
    if ($rupees === 0) {
        $words = 'Zero';
    } else {
        $parts = [];
        $crore = intdiv($rupees, 10000000);
        $rupees %= 10000000;
        $lakh = intdiv($rupees, 100000);
        $rupees %= 100000;
        $thousand = intdiv($rupees, 1000);
        $rupees %= 1000;
        if ($crore) {
            $parts[] = ($crore > 99 ? $three($crore) : $two($crore)) . ' Crore';
        }
        if ($lakh) {
            $parts[] = $two($lakh) . ' Lakh';
        }
        if ($thousand) {
            $parts[] = $two($thousand) . ' Thousand';
        }
        if ($rupees) {
            $parts[] = $three($rupees);
        }
        $words = implode(' ', $parts);
    }
    return 'Rupees ' . $words . ($paise ? ' and ' . $two($paise) . ' Paise' : '') . ' Only';
}

/** Resolve a fee head by code or id. */
function fees_head($head): ?array
{
    if (is_numeric($head)) {
        return db_row('SELECT * FROM fee_heads WHERE id = ?', [(int) $head]);
    }
    return db_row('SELECT * FROM fee_heads WHERE code = ?', [strtoupper((string) $head)]);
}

/** SQL expression (alias t = student_fees) that is true when an open invoice has an overdue part. */
function fees_overdue_sql(string $a = 't'): string
{
    return "($a.status NOT IN ('cancelled','paid','waived') AND $a.balance_amount > 0 AND (
        EXISTS (SELECT 1 FROM fee_installments fio WHERE fio.student_fee_id = $a.id AND fio.due_date < CURDATE() AND fio.paid_amount < fio.amount)
        OR ($a.due_date < CURDATE() AND NOT EXISTS (SELECT 1 FROM fee_installments fin WHERE fin.student_fee_id = $a.id))))";
}

/** SQL expression: overdue balance of an invoice (sum of past-due unpaid installment parts). */
function fees_overdue_amount_sql(string $a = 't'): string
{
    return "(CASE WHEN $a.status IN ('cancelled','paid','waived') OR $a.balance_amount <= 0 THEN 0
        WHEN EXISTS (SELECT 1 FROM fee_installments fx WHERE fx.student_fee_id = $a.id)
          THEN LEAST($a.balance_amount, (SELECT COALESCE(SUM(fy.amount - fy.paid_amount), 0) FROM fee_installments fy WHERE fy.student_fee_id = $a.id AND fy.due_date < CURDATE()))
        WHEN $a.due_date < CURDATE() THEN $a.balance_amount ELSE 0 END)";
}

/**
 * Mark invoices / installments that crossed their due date as overdue (status is otherwise only recomputed on
 * writes). Cheap; runs at most once per request.
 */
function fees_sync_overdue(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db_exec("UPDATE fee_installments fi JOIN student_fees sf ON sf.id = fi.student_fee_id
                 SET fi.status = 'overdue'
                 WHERE fi.due_date < CURDATE() AND fi.paid_amount < fi.amount AND fi.status <> 'overdue' AND sf.status NOT IN ('cancelled', 'waived')");
        db_exec("UPDATE student_fees t SET t.status = 'overdue' WHERE t.status IN ('pending', 'partial') AND " . fees_overdue_sql('t'));
    } catch (Throwable $e) {
        log_system('warning', 'fees_sync_overdue failed: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------
 * Invoices
 * ------------------------------------------------------------------ */

/** Money received against an invoice (successful / refunded payments). */
function fees_invoice_paid(int $invoiceId): array
{
    $alloc = db_row("SELECT COALESCE(SUM(pa.amount), 0) AS amount, COALESCE(SUM(pa.fine_amount), 0) AS fine FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id
                     WHERE pa.student_fee_id = ? AND p.status IN ('success', 'refunded')", [$invoiceId]);
    $legacy = (float) db_value("SELECT COALESCE(SUM(p.amount + p.fine_amount), 0) FROM payments p WHERE p.student_fee_id = ? AND p.status IN ('success', 'refunded')
                                AND NOT EXISTS (SELECT 1 FROM payment_allocations x WHERE x.payment_id = p.id)", [$invoiceId]);
    return ['paid' => fees_round((float) $alloc['amount'] + $legacy), 'fine_paid' => (float) $alloc['fine']];
}

function fees_refresh_invoice(int $invoiceId): array
{
    $inv = db_row('SELECT * FROM student_fees WHERE id = ?', [$invoiceId]);
    if (!$inv) {
        throw new CrudException('Invoice not found.');
    }
    $conc = db_row("SELECT COALESCE(SUM(CASE WHEN type = 'scholarship' THEN amount ELSE 0 END), 0) AS scholarship,
                           COALESCE(SUM(CASE WHEN type <> 'scholarship' THEN amount ELSE 0 END), 0) AS discount
                    FROM fee_concessions WHERE student_fee_id = ? AND status = 'approved'", [$invoiceId]);
    $discount = fees_round((float) $conc['discount']);
    $scholarship = fees_round((float) $conc['scholarship']);
    $paid = fees_invoice_paid($invoiceId)['paid'];
    $net = fees_round(max(0, (float) $inv['gross_amount'] - $discount - $scholarship + (float) $inv['fine_amount']));
    $today = fees_today();

    // Keep installments summing to the net amount, then fill them first-in-first-out with the paid amount.
    $insts = db_all('SELECT * FROM fee_installments WHERE student_fee_id = ? ORDER BY installment_no', [$invoiceId]);
    $original = array_column($insts, 'amount', 'id');
    $anyOverdue = false;
    if ($insts) {
        $sum = array_sum(array_map(fn ($i) => (float) $i['amount'], $insts));
        $diff = fees_round($net - $sum);
        if ($diff < 0) {
            for ($k = count($insts) - 1; $k >= 0 && $diff < 0; $k--) {
                $cut = min((float) $insts[$k]['amount'], -$diff);
                $insts[$k]['amount'] = fees_round((float) $insts[$k]['amount'] - $cut);
                $diff = fees_round($diff + $cut);
            }
        } elseif ($diff > 0) {
            $target = count($insts) - 1;
            $remainingPaid = $paid;
            foreach ($insts as $k => $i) {
                if ($remainingPaid < (float) $i['amount']) {
                    $target = $k;
                    break;
                }
                $remainingPaid -= (float) $i['amount'];
            }
            $insts[$target]['amount'] = fees_round((float) $insts[$target]['amount'] + $diff);
        }
        $remaining = $paid;
        foreach ($insts as $i) {
            $amt = (float) $i['amount'];
            $p = fees_round(min($amt, max(0, $remaining)));
            $remaining = fees_round($remaining - $p);
            if ($inv['status'] === 'cancelled') {
                $st = $i['status'];
            } elseif ($p >= $amt) {
                $st = 'paid';
            } elseif ($i['due_date'] < $today) {
                $st = 'overdue';
                $anyOverdue = true;
            } else {
                $st = $p > 0 ? 'partial' : 'pending';
            }
            if (fees_round((float) $i['paid_amount']) !== $p || $st !== $i['status'] || fees_round((float) $original[$i['id']]) !== fees_round($amt)) {
                db_update('fee_installments', ['amount' => $amt, 'paid_amount' => $p, 'status' => $st], 'id = ?', [(int) $i['id']]);
            }
        }
    }
    $balance = fees_round($net - $paid);
    if ($inv['status'] === 'cancelled') {
        $status = 'cancelled';
    } elseif ($net <= 0 && ($discount + $scholarship) > 0) {
        $status = 'waived';
    } elseif ($balance <= 0) {
        $status = 'paid';
    } elseif ($insts ? $anyOverdue : ($inv['due_date'] && $inv['due_date'] < $today)) {
        $status = 'overdue';
    } elseif ($paid > 0) {
        $status = 'partial';
    } else {
        $status = 'pending';
    }
    db_update('student_fees', ['discount_amount' => $discount, 'scholarship_amount' => $scholarship, 'net_amount' => $net, 'paid_amount' => $paid, 'status' => $status], 'id = ?', [$invoiceId]);
    return db_row('SELECT * FROM student_fees WHERE id = ?', [$invoiceId]);
}

function fees_create_invoice(int $studentId, string $title, array $items, array $opts = []): int
{
    $student = db_row('SELECT id, first_name, last_name, student_uid, current_semester FROM students WHERE id = ?', [$studentId]);
    if (!$student) {
        throw new CrudException('Student not found.');
    }
    $title = trim($title);
    if ($title === '') {
        throw new CrudException('Invoice title is required.');
    }
    $lines = [];
    $total = 0.0;
    foreach ($items as $it) {
        $head = fees_head($it['head'] ?? ($it['fee_head_id'] ?? null));
        if (!$head) {
            throw new CrudException('Unknown fee head: ' . (string) ($it['head'] ?? ($it['fee_head_id'] ?? '')));
        }
        $amt = fees_round((float) ($it['amount'] ?? 0));
        if ($amt <= 0) {
            continue;
        }
        $lines[] = ['fee_head_id' => (int) $head['id'], 'amount' => $amt];
        $total += $amt;
    }
    $total = fees_round($total);
    if (!$lines || $total <= 0) {
        throw new CrudException('An invoice needs at least one fee head with an amount greater than zero.');
    }
    $installments = [];
    foreach ((array) ($opts['installments'] ?? []) as $n => $i) {
        $amt = fees_round((float) ($i['amount'] ?? 0));
        $due = (string) ($i['due_date'] ?? '');
        if ($amt <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            throw new CrudException('Each installment needs an amount and a due date.');
        }
        $installments[] = ['amount' => $amt, 'due_date' => $due];
    }
    if ($installments && abs(array_sum(array_column($installments, 'amount')) - $total) > 0.5) {
        throw new CrudException('Installment amounts must add up to the invoice total of ' . money($total, 2) . '.');
    }
    usort($installments, fn ($a, $b) => strcmp($a['due_date'], $b['due_date']));
    $due = $opts['due_date'] ?? ($installments ? end($installments)['due_date'] : date('Y-m-d', strtotime('+15 days')));
    $sessionId = $opts['academic_session_id'] ?? current_session_id();

    $id = db_transaction(function () use ($studentId, $title, $lines, $total, $installments, $due, $sessionId, $opts) {
        $invoiceId = db_insert('student_fees', [
            'invoice_no' => fees_invoice_no(), 'student_id' => $studentId, 'fee_structure_id' => $opts['fee_structure_id'] ?? null,
            'academic_session_id' => $sessionId, 'title' => mb_substr($title, 0, 190), 'semester_no' => $opts['semester_no'] ?? null,
            'gross_amount' => $total, 'net_amount' => $total, 'paid_amount' => 0, 'due_date' => $due, 'status' => 'pending',
            'remarks' => isset($opts['remarks']) ? mb_substr((string) $opts['remarks'], 0, 255) : null, 'created_by' => user_id(),
        ]);
        foreach ($lines as $l) {
            db_insert('student_fee_items', ['student_fee_id' => $invoiceId, 'fee_head_id' => $l['fee_head_id'], 'amount' => $l['amount']]);
        }
        foreach ($installments as $n => $i) {
            db_insert('fee_installments', ['student_fee_id' => $invoiceId, 'installment_no' => $n + 1, 'amount' => $i['amount'], 'due_date' => $i['due_date'], 'paid_amount' => 0, 'status' => 'pending']);
        }
        fees_refresh_invoice($invoiceId);
        return $invoiceId;
    });
    if ($opts['log'] ?? true) {
        $inv = db_row('SELECT invoice_no FROM student_fees WHERE id = ?', [$id]);
        log_activity('create', 'fees', $id, sprintf('Raised invoice %s (%s, %s) for %s (%s)', $inv['invoice_no'], $title, money($total), trim($student['first_name'] . ' ' . $student['last_name']), $student['student_uid']));
    }
    return (int) $id;
}

function fees_cancel_invoice(int $invoiceId, string $reason): void
{
    $inv = db_row('SELECT sf.*, s.first_name, s.last_name FROM student_fees sf JOIN students s ON s.id = sf.student_id WHERE sf.id = ?', [$invoiceId]);
    if (!$inv) {
        throw new CrudException('Invoice not found.');
    }
    if ($inv['status'] === 'cancelled') {
        throw new CrudException('This invoice is already cancelled.');
    }
    $reason = trim($reason);
    if (mb_strlen($reason) < 5) {
        throw new CrudValidationException(['reason' => 'Enter the reason for cancelling (at least 5 characters).']);
    }
    $paid = fees_invoice_paid($invoiceId)['paid'];
    if ($paid > 0) {
        throw new CrudException('Money has been received against ' . $inv['invoice_no'] . ' (' . money($paid) . '). Void or refund those payments before cancelling the invoice.');
    }
    db_transaction(function () use ($invoiceId, $reason) {
        db_update('student_fees', ['status' => 'cancelled', 'cancelled_reason' => mb_substr($reason, 0, 255), 'cancelled_by' => user_id(), 'cancelled_at' => date('Y-m-d H:i:s')], 'id = ?', [$invoiceId]);
        db_exec("UPDATE fee_installments SET status = 'pending' WHERE student_fee_id = ?", [$invoiceId]);
    });
    log_activity('update', 'fees', $invoiceId, sprintf('Cancelled invoice %s of %s — %s', $inv['invoice_no'], trim($inv['first_name'] . ' ' . $inv['last_name']), $reason));
}

/** Detail payload of an invoice for the SPA drawer / print view. */
function fees_invoice_detail(int $id): ?array
{
    $inv = db_row("SELECT sf.*, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, s.admission_no, s.roll_no, s.photo, s.mobile, s.email,
                          s.father_name, s.current_semester, p.short_name AS program_short, p.name AS program_name, sec.name AS section_name, ses.name AS session_name,
                          fs.name AS structure_name, cu.name AS created_by_name, xu.name AS cancelled_by_name, " . fees_overdue_amount_sql('sf') . " AS overdue_amount
                   FROM student_fees sf JOIN students s ON s.id = sf.student_id LEFT JOIN programs p ON p.id = s.program_id LEFT JOIN sections sec ON sec.id = s.section_id
                   LEFT JOIN academic_sessions ses ON ses.id = sf.academic_session_id LEFT JOIN fee_structures fs ON fs.id = sf.fee_structure_id
                   LEFT JOIN users cu ON cu.id = sf.created_by LEFT JOIN users xu ON xu.id = sf.cancelled_by WHERE sf.id = ?", [$id]);
    if (!$inv) {
        return null;
    }
    $inv['id'] = (int) $inv['id'];
    $inv['items'] = db_all('SELECT sfi.id, sfi.fee_head_id, sfi.amount, fh.name AS head_name, fh.code AS head_code, fh.type AS head_type FROM student_fee_items sfi
                            JOIN fee_heads fh ON fh.id = sfi.fee_head_id WHERE sfi.student_fee_id = ? ORDER BY fh.sort_order, fh.name', [$id]);
    $inv['installments'] = db_all('SELECT id, installment_no, amount, due_date, paid_amount, status FROM fee_installments WHERE student_fee_id = ? ORDER BY installment_no', [$id]);
    $inv['concessions'] = db_all("SELECT fc.id, fc.type, fc.amount, fc.reason, fc.status, fc.created_at, fc.payment_id, sc.name AS scholarship_name, u.name AS created_by_name
                                  FROM fee_concessions fc LEFT JOIN scholarships sc ON sc.id = fc.scholarship_id LEFT JOIN users u ON u.id = fc.created_by
                                  WHERE fc.student_fee_id = ? ORDER BY fc.created_at, fc.id", [$id]);
    $inv['payments'] = db_all("SELECT p.id, p.receipt_no, p.payment_date, p.mode, p.reference_no, p.status, u.name AS collected_by_name,
                                      COALESCE(pa.amount, p.amount + p.fine_amount) AS applied_amount, COALESCE(pa.fine_amount, p.fine_amount) AS applied_fine
                               FROM payments p LEFT JOIN payment_allocations pa ON pa.payment_id = p.id AND pa.student_fee_id = ? LEFT JOIN users u ON u.id = p.collected_by
                               WHERE pa.id IS NOT NULL OR (p.student_fee_id = ? AND NOT EXISTS (SELECT 1 FROM payment_allocations x WHERE x.payment_id = p.id))
                               ORDER BY p.payment_date, p.id", [$id, $id]);
    $inv['late_fee_due'] = fees_late_fee_due($inv);
    $inv['late_fee_rate'] = fees_late_fee_rate($inv);
    return $inv;
}

/* ------------------------------------------------------------------
 * Late fee
 * ------------------------------------------------------------------ */

function fees_late_fee_rate(array $inv): array
{
    $s = !empty($inv['fee_structure_id']) ? db_row('SELECT late_fee_per_day, late_fee_max FROM fee_structures WHERE id = ?', [(int) $inv['fee_structure_id']]) : null;
    $rate = $s && (float) $s['late_fee_per_day'] > 0 ? (float) $s['late_fee_per_day'] : (float) setting('late_fee_per_day', 0);
    $max = $s && $s['late_fee_max'] !== null && (float) $s['late_fee_max'] > 0 ? (float) $s['late_fee_max'] : null;
    return ['per_day' => $rate, 'max' => $max];
}

/** Late fee accrued since the earliest unpaid due date (or since it was last charged) up to $asOf. */
function fees_late_fee_due(array $inv, ?string $asOf = null): float
{
    $asOf = $asOf ?? fees_today();
    if (in_array($inv['status'], ['cancelled', 'paid', 'waived'], true) || fees_round((float) $inv['net_amount'] - (float) $inv['paid_amount']) <= 0) {
        return 0.0;
    }
    $from = db_value('SELECT MIN(due_date) FROM fee_installments WHERE student_fee_id = ? AND paid_amount < amount AND due_date < ?', [(int) $inv['id'], $asOf]);
    if (!$from && !db_value('SELECT COUNT(*) FROM fee_installments WHERE student_fee_id = ?', [(int) $inv['id']])) {
        $from = $inv['due_date'] && $inv['due_date'] < $asOf ? $inv['due_date'] : null;
    }
    if (!$from) {
        return 0.0;
    }
    if (!empty($inv['late_fee_upto']) && $inv['late_fee_upto'] > $from) {
        $from = $inv['late_fee_upto'];
    }
    $days = (int) floor((strtotime($asOf) - strtotime($from)) / 86400);
    if ($days <= 0) {
        return 0.0;
    }
    $rate = fees_late_fee_rate($inv);
    $fee = $days * $rate['per_day'];
    if ($rate['max'] !== null) {
        $fee = min($fee, max(0, $rate['max'] - (float) $inv['fine_amount']));
    }
    return fees_round(max(0, $fee));
}

/** Charge the accrued late fee to an invoice. Returns the amount added. */
function fees_apply_late_fee(int $invoiceId, bool $log = true): float
{
    $inv = db_row('SELECT * FROM student_fees WHERE id = ?', [$invoiceId]);
    if (!$inv) {
        throw new CrudException('Invoice not found.');
    }
    $fee = fees_late_fee_due($inv);
    if ($fee <= 0) {
        return 0.0;
    }
    db_transaction(function () use ($invoiceId, $inv, $fee) {
        db_update('student_fees', ['fine_amount' => fees_round((float) $inv['fine_amount'] + $fee), 'late_fee_upto' => fees_today()], 'id = ?', [$invoiceId]);
        fees_refresh_invoice($invoiceId);
    });
    if ($log) {
        log_activity('update', 'fees', $invoiceId, sprintf('Applied late fee %s to invoice %s', money($fee), $inv['invoice_no']));
    }
    return $fee;
}

/* ------------------------------------------------------------------
 * Concessions & scholarships
 * ------------------------------------------------------------------ */

function fees_add_concession(int $invoiceId, string $type, float $amount, string $reason, ?int $scholarshipId = null, ?int $paymentId = null): int
{
    $inv = db_row('SELECT * FROM student_fees WHERE id = ?', [$invoiceId]);
    if (!$inv) {
        throw new CrudException('Invoice not found.');
    }
    if (in_array($inv['status'], ['cancelled'], true)) {
        throw new CrudException('Concessions cannot be added to a cancelled invoice.');
    }
    if (!isset(fees_concession_types()[$type])) {
        throw new CrudValidationException(['type' => 'Select a valid concession type.']);
    }
    $amount = fees_round($amount);
    $balance = fees_round((float) $inv['net_amount'] - (float) $inv['paid_amount']);
    if ($amount <= 0) {
        throw new CrudValidationException(['amount' => 'Amount must be greater than zero.']);
    }
    if ($amount > $balance) {
        throw new CrudValidationException(['amount' => 'Concession cannot exceed the outstanding balance of ' . money($balance, 2) . '.']);
    }
    $id = db_transaction(function () use ($invoiceId, $inv, $type, $amount, $reason, $scholarshipId, $paymentId) {
        $cid = db_insert('fee_concessions', [
            'student_fee_id' => $invoiceId, 'student_id' => (int) $inv['student_id'], 'scholarship_id' => $scholarshipId, 'payment_id' => $paymentId, 'type' => $type,
            'amount' => $amount, 'reason' => mb_substr(trim($reason), 0, 255) ?: null, 'status' => 'approved', 'approved_by' => user_id(), 'created_by' => user_id(),
        ]);
        fees_refresh_invoice($invoiceId);
        return $cid;
    });
    return (int) $id;
}

/**
 * Concession a scholarship scheme gives on an invoice (percentage of tuition items, else of gross), before caps.
 */
function fees_scholarship_amount(array $sch, array $inv): float
{
    if ($sch['type'] === 'fixed') {
        return (float) $sch['value'];
    }
    $tuition = (float) db_value("SELECT COALESCE(SUM(sfi.amount), 0) FROM student_fee_items sfi JOIN fee_heads fh ON fh.id = sfi.fee_head_id WHERE sfi.student_fee_id = ? AND fh.type = 'tuition'", [(int) $inv['id']]);
    $base = $tuition > 0 ? $tuition : (float) $inv['gross_amount'];
    return fees_round($base * (float) $sch['value'] / 100);
}

/**
 * Award a scholarship to students: creates 'scholarship' concessions on their open invoices of the session.
 * @return array{awarded:int, amount:float, skipped:array<int,string>, details:array}
 */
function fees_award_scholarship(int $scholarshipId, array $studentIds, string $reason, ?int $sessionId = null, bool $commit = true): array
{
    $sch = db_row('SELECT * FROM scholarships WHERE id = ?', [$scholarshipId]);
    if (!$sch) {
        throw new CrudException('Scholarship not found.');
    }
    if ($sch['status'] !== 'active') {
        throw new CrudException($sch['name'] . ' is inactive. Activate it before awarding.');
    }
    $sessionId = $sessionId ?? current_session_id();
    $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
    if (!$studentIds) {
        throw new CrudValidationException(['student_ids' => 'Select at least one student.']);
    }
    $result = ['awarded' => 0, 'amount' => 0.0, 'skipped' => [], 'details' => []];
    $work = function () use ($sch, $studentIds, $reason, $sessionId, $commit, &$result) {
        foreach ($studentIds as $sid) {
            $st = db_row("SELECT id, TRIM(CONCAT_WS(' ', first_name, last_name)) AS name, student_uid FROM students WHERE id = ?", [$sid]);
            if (!$st) {
                continue;
            }
            $already = (int) db_value("SELECT COUNT(*) FROM fee_concessions fc JOIN student_fees sf ON sf.id = fc.student_fee_id WHERE fc.student_id = ? AND fc.scholarship_id = ? AND fc.status = 'approved' AND (sf.academic_session_id = ? OR ? IS NULL)",
                [$sid, (int) $sch['id'], $sessionId, $sessionId]);
            if ($already) {
                $result['skipped'][] = $st['name'] . ' (' . $st['student_uid'] . ') already holds this scholarship';
                continue;
            }
            $invoices = db_all("SELECT * FROM student_fees WHERE student_id = ? AND status NOT IN ('cancelled', 'paid', 'waived') AND balance_amount > 0 AND (academic_session_id = ? OR ? IS NULL) ORDER BY due_date, id",
                [$sid, $sessionId, $sessionId]);
            if (!$invoices) {
                $result['skipped'][] = $st['name'] . ' (' . $st['student_uid'] . ') has no open invoice this session';
                continue;
            }
            $cap = $sch['max_amount'] !== null && (float) $sch['max_amount'] > 0 ? (float) $sch['max_amount'] : INF;
            $remainingFixed = $sch['type'] === 'fixed' ? (float) $sch['value'] : INF;
            $given = 0.0;
            foreach ($invoices as $inv) {
                $amt = $sch['type'] === 'fixed' ? $remainingFixed : fees_scholarship_amount($sch, $inv);
                $amt = min($amt, $cap - $given, fees_round((float) $inv['net_amount'] - (float) $inv['paid_amount']));
                $amt = fees_round(max(0, $amt));
                if ($amt <= 0) {
                    continue;
                }
                if ($commit) {
                    fees_add_concession((int) $inv['id'], 'scholarship', $amt, $reason !== '' ? $reason : $sch['name'], (int) $sch['id']);
                }
                $given += $amt;
                if ($sch['type'] === 'fixed') {
                    $remainingFixed -= $amt;
                }
                if ($given >= $cap || $remainingFixed <= 0) {
                    break;
                }
            }
            if ($given <= 0) {
                $result['skipped'][] = $st['name'] . ' (' . $st['student_uid'] . ') — nothing left to concede';
                continue;
            }
            $result['awarded']++;
            $result['amount'] += $given;
            $result['details'][] = ['student_id' => $sid, 'name' => $st['name'], 'student_uid' => $st['student_uid'], 'amount' => fees_round($given)];
        }
    };
    $commit ? db_transaction($work) : $work();
    $result['amount'] = fees_round($result['amount']);
    if ($commit && $result['awarded']) {
        log_activity('approve', 'fees', $scholarshipId, sprintf('Awarded %s to %d student(s), total concession %s', $sch['name'], $result['awarded'], money($result['amount'])));
    }
    return $result;
}

/* ------------------------------------------------------------------
 * Student fee profile (collection counter)
 * ------------------------------------------------------------------ */

function fees_student_totals(int $studentId, ?int $sessionId = null): array
{
    $row = db_row("SELECT COUNT(*) AS invoices, COALESCE(SUM(t.net_amount), 0) AS net, COALESCE(SUM(t.paid_amount), 0) AS paid, COALESCE(SUM(t.balance_amount), 0) AS balance,
                          COALESCE(SUM(" . fees_overdue_amount_sql('t') . "), 0) AS overdue, COALESCE(SUM(t.discount_amount + t.scholarship_amount), 0) AS concessions,
                          COALESCE(SUM(t.fine_amount), 0) AS fine
                   FROM student_fees t WHERE t.student_id = ? AND t.status <> 'cancelled'" . ($sessionId ? ' AND t.academic_session_id = ?' : ''),
        $sessionId ? [$studentId, $sessionId] : [$studentId]) ?? [];
    return array_map(fn ($v) => is_numeric($v) ? (float) $v : $v, $row);
}

function fees_student_profile(int $studentId): ?array
{
    $s = db_row("SELECT s.id, s.student_uid, s.admission_no, s.roll_no, TRIM(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name)) AS name, s.photo, s.gender, s.mobile, s.email,
                        s.father_name, s.guardian_phone, s.current_semester, s.status, s.is_hosteller, s.uses_transport, p.short_name AS program_short, p.name AS program_name,
                        sec.name AS section_name, d.name AS department_name
                 FROM students s LEFT JOIN programs p ON p.id = s.program_id LEFT JOIN sections sec ON sec.id = s.section_id LEFT JOIN departments d ON d.id = s.department_id WHERE s.id = ?", [$studentId]);
    if (!$s) {
        return null;
    }
    $s['id'] = (int) $s['id'];
    $open = db_all("SELECT t.id, t.invoice_no, t.title, t.semester_no, t.gross_amount, t.discount_amount, t.scholarship_amount, t.fine_amount, t.net_amount, t.paid_amount, t.balance_amount,
                           t.due_date, t.status, t.fee_structure_id, t.late_fee_upto, ses.name AS session_name, " . fees_overdue_amount_sql('t') . " AS overdue_amount
                    FROM student_fees t LEFT JOIN academic_sessions ses ON ses.id = t.academic_session_id
                    WHERE t.student_id = ? AND t.status NOT IN ('cancelled', 'paid', 'waived') AND t.balance_amount > 0 ORDER BY t.due_date, t.id", [$studentId]);
    $today = fees_today();
    foreach ($open as &$inv) {
        $inv['id'] = (int) $inv['id'];
        $inv['installments'] = db_all('SELECT id, installment_no, amount, paid_amount, due_date, status FROM fee_installments WHERE student_fee_id = ? ORDER BY installment_no', [$inv['id']]);
        $dueNow = 0.0;
        foreach ($inv['installments'] as $i) {
            if ($i['due_date'] <= $today) {
                $dueNow += (float) $i['amount'] - (float) $i['paid_amount'];
            }
        }
        $inv['due_now'] = $inv['installments'] ? fees_round(max(0, $dueNow)) : (float) $inv['balance_amount'];
        $inv['next_due'] = null;
        foreach ($inv['installments'] as $i) {
            if ((float) $i['paid_amount'] < (float) $i['amount']) {
                $inv['next_due'] = $i;
                break;
            }
        }
        $inv['late_fee_due'] = fees_late_fee_due($inv);
        $inv['items'] = db_all('SELECT fh.name AS head_name, sfi.amount FROM student_fee_items sfi JOIN fee_heads fh ON fh.id = sfi.fee_head_id WHERE sfi.student_fee_id = ? ORDER BY fh.sort_order', [$inv['id']]);
    }
    unset($inv);
    $scholarships = db_all("SELECT sc.name, fc.type, SUM(fc.amount) AS amount FROM fee_concessions fc LEFT JOIN scholarships sc ON sc.id = fc.scholarship_id
                            WHERE fc.student_id = ? AND fc.status = 'approved' GROUP BY sc.name, fc.type ORDER BY amount DESC", [$studentId]);
    $recent = db_all("SELECT p.id, p.receipt_no, p.amount + p.fine_amount AS total, p.payment_date, p.mode, p.status FROM payments p WHERE p.student_id = ? ORDER BY p.payment_date DESC, p.id DESC LIMIT 5", [$studentId]);
    return ['student' => $s, 'totals' => fees_student_totals($studentId), 'invoices' => $open, 'concessions' => $scholarships, 'recent_payments' => $recent];
}

/** Online gateway configuration summary (no secrets). */
function fees_gateway_info(): array
{
    $gw = (string) setting('payment_gateway', '');
    $labels = ['razorpay' => 'Razorpay', 'payu' => 'PayU', 'ccavenue' => 'CCAvenue', 'cashfree' => 'Cashfree', 'paytm' => 'Paytm', 'stripe' => 'Stripe'];
    return [
        'gateway' => $gw ?: null, 'label' => $gw ? ($labels[$gw] ?? label_from_key($gw)) : null, 'mode' => (string) setting('gateway_mode', 'test'),
        'configured' => $gw !== '' && (string) setting('gateway_key_id', '') !== '' && (string) setting('gateway_key_secret', '') !== '',
    ];
}

/* ------------------------------------------------------------------
 * Collection
 * ------------------------------------------------------------------ */

/**
 * Collect a payment at the counter.
 * $in: student_id, payment_date, mode, reference_no, bank_name, allocations[{student_fee_id, amount}], apply_late_fee (bool),
 *      discount_amount, discount_reason, remarks, email_receipt (bool)
 * @return array payment summary
 */
function fees_collect(array $in): array
{
    $errors = [];
    $studentId = (int) ($in['student_id'] ?? 0);
    $student = $studentId ? db_row("SELECT id, TRIM(CONCAT_WS(' ', first_name, last_name)) AS name, student_uid, email, mobile FROM students WHERE id = ?", [$studentId]) : null;
    if (!$student) {
        $errors['student_id'] = 'Select the student.';
    }
    $date = trim((string) ($in['payment_date'] ?? ''));
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        $errors['payment_date'] = 'Enter a valid payment date.';
    } elseif ($date > fees_today()) {
        $errors['payment_date'] = 'Payment date cannot be in the future.';
    } elseif ($date < date('Y-m-d', strtotime('-90 days'))) {
        $errors['payment_date'] = 'Back-dated entries older than 90 days are not allowed.';
    }
    $mode = (string) ($in['mode'] ?? '');
    if (!isset(fees_enabled_modes()[$mode])) {
        $errors['mode'] = 'Select an enabled payment mode.';
    }
    $reference = trim((string) ($in['reference_no'] ?? ''));
    $bank = trim((string) ($in['bank_name'] ?? ''));
    if ($mode !== 'cash' && isset(fees_modes()[$mode]) && $reference === '') {
        $errors['reference_no'] = match ($mode) {
            'cheque' => 'Enter the cheque number.', 'dd' => 'Enter the demand draft number.', 'upi' => 'Enter the UPI transaction ID (UTR).',
            'card' => 'Enter the card approval / transaction ID.', 'online' => 'Enter the gateway transaction ID.', default => 'Enter the UTR / transaction reference.',
        };
    } elseif (mb_strlen($reference) > 100) {
        $errors['reference_no'] = 'Reference may not be longer than 100 characters.';
    }
    if (in_array($mode, ['cheque', 'dd'], true) && $bank === '') {
        $errors['bank_name'] = 'Enter the issuing bank.';
    }
    $applyLate = !empty($in['apply_late_fee']) && $in['apply_late_fee'] !== '0';
    $discount = fees_round((float) ($in['discount_amount'] ?? 0));
    $discountReason = trim((string) ($in['discount_reason'] ?? ''));
    if ($discount < 0) {
        $errors['discount_amount'] = 'Discount cannot be negative.';
    } elseif ($discount > 0) {
        if (!can('fees', 'edit')) {
            $errors['discount_amount'] = 'Your role cannot give discounts at the counter.';
        } elseif (mb_strlen($discountReason) < 3) {
            $errors['discount_reason'] = 'Enter the reason / approval reference for the discount.';
        }
    }
    $remarks = trim((string) ($in['remarks'] ?? ''));
    if (mb_strlen($remarks) > 255) {
        $errors['remarks'] = 'Remarks may not be longer than 255 characters.';
    }
    $allocs = [];
    foreach ((array) ($in['allocations'] ?? []) as $a) {
        $fid = (int) ($a['student_fee_id'] ?? 0);
        $amt = fees_round((float) ($a['amount'] ?? 0));
        if ($fid && $amt > 0) {
            $allocs[$fid] = fees_round(($allocs[$fid] ?? 0) + $amt);
        }
    }
    if (!$allocs && !isset($errors['student_id'])) {
        $errors['allocations'] = 'Select at least one invoice and enter the amount being paid.';
    }
    if ($dt && $date !== '' && !isset($errors['payment_date']) && $mode === 'cheque' && !empty($in['cheque_date']) && $in['cheque_date'] > date('Y-m-d', strtotime('+90 days'))) {
        $errors['cheque_date'] = 'Post-dated cheques beyond 90 days are not accepted.';
    }
    // Validate each invoice and the projected balance (late fee + discount) before writing anything.
    $plan = [];
    if ($student && $allocs) {
        $remainingDiscount = $discount;
        foreach ($allocs as $fid => $amt) {
            $inv = db_row('SELECT * FROM student_fees WHERE id = ?', [$fid]);
            if (!$inv || (int) $inv['student_id'] !== $studentId) {
                $errors['allocations'] = 'One of the selected invoices does not belong to this student.';
                break;
            }
            if (in_array($inv['status'], ['cancelled', 'paid', 'waived'], true)) {
                $errors['allocations'] = 'Invoice ' . $inv['invoice_no'] . ' is ' . $inv['status'] . ' and cannot take payments.';
                break;
            }
            $late = $applyLate ? fees_late_fee_due($inv) : 0.0;
            $balance = fees_round((float) $inv['net_amount'] - (float) $inv['paid_amount'] + $late);
            $disc = fees_round(min($remainingDiscount, $balance));
            $remainingDiscount = fees_round($remainingDiscount - $disc);
            $payable = fees_round($balance - $disc);
            if ($amt > $payable + 0.001) {
                $errors['allocations'] = sprintf('Amount for %s (%s) exceeds the payable balance of %s.', $inv['invoice_no'], money($amt, 2), money($payable, 2));
                break;
            }
            $plan[$fid] = ['invoice' => $inv, 'amount' => $amt, 'late' => $late, 'discount' => $disc];
        }
        if (!isset($errors['allocations']) && $remainingDiscount > 0.001) {
            $errors['discount_amount'] = 'Discount is larger than the balance of the selected invoices.';
        }
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }

    $total = fees_round(array_sum(array_column($plan, 'amount')));
    $gateway = fees_gateway_info();
    $paymentId = db_transaction(function () use ($plan, $student, $date, $mode, $reference, $bank, $remarks, $discount, $discountReason, $gateway, $in) {
        // Lock the invoices for the duration of the transaction.
        db_all('SELECT id FROM student_fees WHERE id IN (' . implode(',', array_map('intval', array_keys($plan))) . ') FOR UPDATE');
        $receiptNo = fees_receipt_no();
        $first = array_key_first($plan);
        $pid = db_insert('payments', [
            'receipt_no' => $receiptNo, 'student_id' => (int) $student['id'], 'student_fee_id' => (int) $first, 'amount' => 0, 'fine_amount' => 0, 'discount_amount' => $discount,
            'payment_date' => $date, 'mode' => $mode, 'reference_no' => $reference !== '' ? $reference : null, 'bank_name' => $bank !== '' ? mb_substr($bank, 0, 100) : null,
            'gateway' => $mode === 'online' ? ($gateway['gateway'] ?: null) : null, 'gateway_txn_id' => $mode === 'online' ? $reference : null,
            'purpose' => 'fee', 'status' => 'success', 'remarks' => $remarks !== '' ? $remarks : (in_array($mode, ['cheque', 'dd'], true) && !empty($in['cheque_date']) ? 'Instrument dated ' . format_date($in['cheque_date']) : null),
            'collected_by' => user_id(),
        ]);
        $sumAmount = 0.0;
        $sumFine = 0.0;
        foreach ($plan as $fid => $p) {
            if ($p['late'] > 0) {
                fees_apply_late_fee((int) $fid, false);
            }
            if ($p['discount'] > 0) {
                fees_add_concession((int) $fid, 'discount', $p['discount'], 'Counter discount: ' . $discountReason, null, $pid);
            }
            $inv = db_row('SELECT * FROM student_fees WHERE id = ?', [$fid]);
            $finePaid = fees_invoice_paid((int) $fid)['fine_paid'];
            $fineOutstanding = max(0, (float) $inv['fine_amount'] - $finePaid);
            $finePart = fees_round(min($p['amount'], $fineOutstanding));
            db_insert('payment_allocations', ['payment_id' => $pid, 'student_fee_id' => (int) $fid, 'amount' => $p['amount'], 'fine_amount' => $finePart]);
            fees_refresh_invoice((int) $fid);
            $sumAmount += $p['amount'] - $finePart;
            $sumFine += $finePart;
        }
        db_update('payments', ['amount' => fees_round($sumAmount), 'fine_amount' => fees_round($sumFine)], 'id = ?', [$pid]);
        db_insert('payment_receipts', ['payment_id' => $pid, 'receipt_no' => $receiptNo, 'issued_at' => date('Y-m-d H:i:s')]);
        return $pid;
    });
    $pay = db_row('SELECT * FROM payments WHERE id = ?', [$paymentId]);
    log_activity('create', 'fees', $paymentId, sprintf('Collected %s from %s (%s) via %s — receipt %s', money($total, 2), $student['name'], $student['student_uid'], fees_mode_label($mode), $pay['receipt_no']));
    $emailed = false;
    if (!empty($in['email_receipt']) && $student['email']) {
        $emailed = fees_email_receipt($paymentId);
    }
    return [
        'id' => (int) $paymentId, 'receipt_no' => $pay['receipt_no'], 'total' => $total, 'amount' => (float) $pay['amount'], 'fine_amount' => (float) $pay['fine_amount'],
        'discount_amount' => (float) $pay['discount_amount'], 'mode' => $mode, 'mode_label' => fees_mode_label($mode), 'payment_date' => $date, 'student' => $student,
        'emailed' => $emailed, 'balance_after' => fees_student_totals((int) $student['id'])['balance'] ?? 0,
    ];
}

/** Email a receipt summary to the student. */
function fees_email_receipt(int $paymentId): bool
{
    $p = db_row("SELECT p.*, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.email, s.student_uid FROM payments p JOIN students s ON s.id = p.student_id WHERE p.id = ?", [$paymentId]);
    if (!$p || !$p['email']) {
        return false;
    }
    $total = (float) $p['amount'] + (float) $p['fine_amount'];
    $html = '<p>Dear ' . e($p['student_name']) . ',</p><p>We have received <strong>' . e(money($total, 2)) . '</strong> towards your fees on ' . e(format_date($p['payment_date'])) . ' via ' . e(fees_mode_label($p['mode']))
        . '.</p><table cellpadding="6" style="border-collapse:collapse;font-size:14px"><tr><td style="color:#64748b">Receipt no.</td><td><strong>' . e($p['receipt_no']) . '</strong></td></tr>'
        . '<tr><td style="color:#64748b">Student ID</td><td>' . e($p['student_uid']) . '</td></tr>'
        . ($p['reference_no'] ? '<tr><td style="color:#64748b">Reference</td><td>' . e($p['reference_no']) . '</td></tr>' : '')
        . '</table><p>The printable receipt is available from the accounts office.</p><p>Thank you,<br>Accounts Office, ' . e(institute_name()) . '</p>';
    $ok = send_mail($p['email'], 'Fee receipt ' . $p['receipt_no'], $html, ['related_type' => 'payment', 'related_id' => $paymentId]);
    if ($ok) {
        db_update('payment_receipts', ['emailed_at' => date('Y-m-d H:i:s')], 'payment_id = ?', [$paymentId]);
    }
    return $ok;
}

/** Void / cancel a payment and restore the invoice balances. */
function fees_void_payment(int $paymentId, string $reason): array
{
    $p = db_row("SELECT p.*, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name FROM payments p LEFT JOIN students s ON s.id = p.student_id WHERE p.id = ?", [$paymentId]);
    if (!$p) {
        throw new CrudException('Payment not found.');
    }
    $reason = trim($reason);
    if (mb_strlen($reason) < 5) {
        throw new CrudValidationException(['reason' => 'Enter the reason for voiding this receipt (at least 5 characters).']);
    }
    if ($p['status'] !== 'success') {
        throw new CrudException('Only successful payments can be voided. This receipt is ' . $p['status'] . '.');
    }
    if (!empty($p['admission_id'])) {
        throw new CrudException('Admission-fee receipts are managed from the admission record and cannot be voided here.');
    }
    $refunds = (int) db_value("SELECT COUNT(*) FROM refunds WHERE payment_id = ? AND status IN ('pending', 'approved', 'processed')", [$paymentId]);
    if ($refunds) {
        throw new CrudException('This payment has refund requests. Reject them before voiding the receipt.');
    }
    $invoiceIds = db_column('SELECT DISTINCT student_fee_id FROM payment_allocations WHERE payment_id = ?', [$paymentId]);
    if (!$invoiceIds && $p['student_fee_id']) {
        $invoiceIds = [(int) $p['student_fee_id']];
    }
    db_transaction(function () use ($paymentId, $reason, $invoiceIds) {
        db_update('payments', ['status' => 'cancelled', 'cancelled_by' => user_id(), 'cancelled_reason' => mb_substr($reason, 0, 255), 'cancelled_at' => date('Y-m-d H:i:s')], 'id = ?', [$paymentId]);
        db_exec('DELETE FROM fee_concessions WHERE payment_id = ?', [$paymentId]);
        foreach ($invoiceIds as $fid) {
            fees_refresh_invoice((int) $fid);
        }
    });
    $total = (float) $p['amount'] + (float) $p['fine_amount'];
    log_activity('update', 'fees', $paymentId, sprintf('Voided receipt %s (%s) of %s — %s', $p['receipt_no'], money($total, 2), $p['student_name'] ?? '—', $reason));
    notify('perm:fees', 'fee', 'Receipt voided', sprintf('%s (%s) of %s was voided: %s', $p['receipt_no'], money($total), $p['student_name'] ?? '—', $reason), 'admin/fees/payments', 'receipt');
    return ['invoices' => count($invoiceIds)];
}

/** Payment detail for the drawer / receipt. */
function fees_payment_detail(int $id): ?array
{
    $p = db_row("SELECT p.*, p.amount + p.fine_amount AS total_amount, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, s.admission_no, s.roll_no,
                        s.photo, s.father_name, s.gender, s.mobile, s.email, s.current_semester, pr.short_name AS program_short, pr.name AS program_name, sec.name AS section_name,
                        u.name AS collected_by_name, cu.name AS cancelled_by_name, r.printed_count, r.last_printed_at, r.emailed_at, r.issued_at
                 FROM payments p LEFT JOIN students s ON s.id = p.student_id LEFT JOIN programs pr ON pr.id = s.program_id LEFT JOIN sections sec ON sec.id = s.section_id
                 LEFT JOIN users u ON u.id = p.collected_by LEFT JOIN users cu ON cu.id = p.cancelled_by LEFT JOIN payment_receipts r ON r.payment_id = p.id WHERE p.id = ?", [$id]);
    if (!$p) {
        return null;
    }
    $p['id'] = (int) $p['id'];
    $p['mode_label'] = fees_mode_label($p['mode']);
    $allocs = db_all("SELECT pa.student_fee_id, pa.amount, pa.fine_amount, sf.invoice_no, sf.title, sf.balance_amount, sf.status
                      FROM payment_allocations pa JOIN student_fees sf ON sf.id = pa.student_fee_id WHERE pa.payment_id = ? ORDER BY pa.id", [$id]);
    if (!$allocs && $p['student_fee_id']) {
        $allocs = db_all('SELECT sf.id AS student_fee_id, ? AS amount, ? AS fine_amount, sf.invoice_no, sf.title, sf.balance_amount, sf.status FROM student_fees sf WHERE sf.id = ?',
            [(float) $p['amount'] + (float) $p['fine_amount'], (float) $p['fine_amount'], (int) $p['student_fee_id']]);
    }
    foreach ($allocs as &$a) {
        $a['items'] = db_all('SELECT fh.name AS head_name, sfi.amount FROM student_fee_items sfi JOIN fee_heads fh ON fh.id = sfi.fee_head_id WHERE sfi.student_fee_id = ? ORDER BY fh.sort_order', [(int) $a['student_fee_id']]);
    }
    unset($a);
    $p['allocations'] = $allocs;
    $p['discounts'] = db_all('SELECT fc.amount, fc.reason, sf.invoice_no FROM fee_concessions fc JOIN student_fees sf ON sf.id = fc.student_fee_id WHERE fc.payment_id = ?', [$id]);
    $p['refunds'] = db_all('SELECT id, refund_no, amount, status, reason, created_at FROM refunds WHERE payment_id = ? ORDER BY id', [$id]);
    $p['refundable'] = fees_refundable($p);
    return $p;
}

/* ------------------------------------------------------------------
 * Refunds
 * ------------------------------------------------------------------ */

function fees_refundable(array $payment): float
{
    if (!in_array($payment['status'], ['success', 'refunded'], true)) {
        return 0.0;
    }
    $requested = (float) db_value("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE payment_id = ? AND status IN ('pending', 'approved', 'processed')", [(int) $payment['id']]);
    return fees_round(max(0, (float) $payment['amount'] + (float) $payment['fine_amount'] - $requested));
}

function fees_refund_request(int $paymentId, float $amount, string $reason): int
{
    $p = db_row("SELECT p.*, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name FROM payments p LEFT JOIN students s ON s.id = p.student_id WHERE p.id = ?", [$paymentId]);
    $errors = [];
    if (!$p) {
        $errors['payment_id'] = 'Select the payment to refund.';
    } elseif ($p['status'] !== 'success') {
        $errors['payment_id'] = 'Only successful payments can be refunded (this receipt is ' . $p['status'] . ').';
    }
    $amount = fees_round($amount);
    if ($amount <= 0) {
        $errors['amount'] = 'Amount must be greater than zero.';
    } elseif ($p && empty($errors['payment_id']) && $amount > fees_refundable($p)) {
        $errors['amount'] = 'Amount cannot exceed the refundable balance of ' . money(fees_refundable($p), 2) . '.';
    }
    $reason = trim($reason);
    if (mb_strlen($reason) < 5) {
        $errors['reason'] = 'Describe the reason for the refund (at least 5 characters).';
    } elseif (mb_strlen($reason) > 255) {
        $errors['reason'] = 'Reason may not be longer than 255 characters.';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $id = db_transaction(fn () => db_insert('refunds', [
        'refund_no' => fees_refund_no(), 'payment_id' => $paymentId, 'student_id' => $p['student_id'], 'amount' => $amount, 'reason' => $reason, 'status' => 'pending', 'requested_by' => user_id(),
    ]));
    $no = db_value('SELECT refund_no FROM refunds WHERE id = ?', [$id]);
    log_activity('create', 'fees', $id, sprintf('Requested refund %s of %s against receipt %s (%s)', $no, money($amount, 2), $p['receipt_no'], $p['student_name'] ?? '—'));
    notify('perm:fees', 'fee', 'Refund request awaiting approval', sprintf('%s: %s to %s against %s', $no, money($amount), $p['student_name'] ?? '—', $p['receipt_no']), 'admin/fees/refunds', 'undo-2');
    return (int) $id;
}

/** Move a refund through its workflow: approve | reject | process. */
function fees_refund_transition(int $id, string $action, array $in = []): array
{
    $r = db_row('SELECT r.*, p.receipt_no, p.amount + p.fine_amount AS payment_total FROM refunds r JOIN payments p ON p.id = r.payment_id WHERE r.id = ?', [$id]);
    if (!$r) {
        throw new CrudException('Refund not found.');
    }
    $now = date('Y-m-d H:i:s');
    switch ($action) {
        case 'approve':
            if ($r['status'] !== 'pending') {
                throw new CrudException('Only pending refunds can be approved.');
            }
            db_update('refunds', ['status' => 'approved', 'approved_by' => user_id(), 'approved_at' => $now, 'remarks' => trim((string) ($in['remarks'] ?? '')) ?: $r['remarks']], 'id = ?', [$id]);
            log_activity('approve', 'fees', $id, sprintf('Approved refund %s (%s)', $r['refund_no'], money($r['amount'], 2)));
            break;
        case 'reject':
            if (!in_array($r['status'], ['pending', 'approved'], true)) {
                throw new CrudException('Only pending or approved refunds can be rejected.');
            }
            $remarks = trim((string) ($in['remarks'] ?? ''));
            if (mb_strlen($remarks) < 5) {
                throw new CrudValidationException(['remarks' => 'Enter the reason for rejecting (at least 5 characters).']);
            }
            db_update('refunds', ['status' => 'rejected', 'approved_by' => user_id(), 'approved_at' => $now, 'remarks' => mb_substr($remarks, 0, 255)], 'id = ?', [$id]);
            log_activity('update', 'fees', $id, sprintf('Rejected refund %s — %s', $r['refund_no'], $remarks));
            break;
        case 'process':
            if ($r['status'] !== 'approved') {
                throw new CrudException('Approve the refund before marking it paid.');
            }
            $errors = [];
            $mode = (string) ($in['mode'] ?? '');
            if (!isset(fees_modes()[$mode]) || $mode === 'online') {
                $errors['mode'] = 'Select how the refund was paid.';
            }
            $ref = trim((string) ($in['reference_no'] ?? ''));
            if ($mode !== 'cash' && $ref === '') {
                $errors['reference_no'] = 'Enter the UTR / cheque number of the refund.';
            }
            $date = (string) ($in['refund_date'] ?? '');
            $dt = DateTime::createFromFormat('Y-m-d', $date);
            if (!$dt || $dt->format('Y-m-d') !== $date) {
                $errors['refund_date'] = 'Enter a valid refund date.';
            } elseif ($date > fees_today()) {
                $errors['refund_date'] = 'Refund date cannot be in the future.';
            }
            if ($errors) {
                throw new CrudValidationException($errors);
            }
            db_transaction(function () use ($id, $r, $mode, $ref, $date, $now) {
                db_update('refunds', ['status' => 'processed', 'mode' => $mode, 'reference_no' => $ref !== '' ? mb_substr($ref, 0, 100) : null, 'refund_date' => $date, 'processed_by' => user_id(), 'processed_at' => $now], 'id = ?', [$id]);
                $processed = (float) db_value("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE payment_id = ? AND status = 'processed'", [(int) $r['payment_id']]);
                if ($processed + 0.001 >= (float) $r['payment_total']) {
                    db_update('payments', ['status' => 'refunded'], 'id = ?', [(int) $r['payment_id']]);
                }
            });
            log_activity('update', 'fees', $id, sprintf('Paid refund %s of %s via %s%s', $r['refund_no'], money($r['amount'], 2), fees_mode_label($mode), $ref ? ' (' . $ref . ')' : ''));
            break;
        default:
            throw new InvalidArgumentException('Unknown refund action.');
    }
    return db_row('SELECT * FROM refunds WHERE id = ?', [$id]);
}

/* ------------------------------------------------------------------
 * Reminders
 * ------------------------------------------------------------------ */

/** Send overdue / pending fee reminders (in-app notification + email + SMS) for invoices. */
function fees_send_reminders(array $invoiceIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $invoiceIds))));
    if (!$ids) {
        throw new CrudValidationException(['ids' => 'Select at least one invoice.']);
    }
    if (count($ids) > 500) {
        throw new CrudException('Send reminders to at most 500 invoices at a time.');
    }
    $rows = db_all("SELECT sf.*, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.email, s.mobile, s.guardian_phone, s.user_id, s.student_uid, " . fees_overdue_amount_sql('sf') . " AS overdue_amount
                    FROM student_fees sf JOIN students s ON s.id = sf.student_id WHERE sf.id IN (" . implode(',', $ids) . ')');
    $out = ['sent' => 0, 'emails' => 0, 'sms' => 0, 'notifications' => 0, 'skipped' => 0];
    $inst = institute_name();
    foreach ($rows as $r) {
        $balance = (float) $r['balance_amount'];
        if (in_array($r['status'], ['cancelled', 'paid', 'waived'], true) || $balance <= 0) {
            $out['skipped']++;
            continue;
        }
        $overdue = (float) $r['overdue_amount'];
        $nextDue = db_value('SELECT MIN(due_date) FROM fee_installments WHERE student_fee_id = ? AND paid_amount < amount', [(int) $r['id']]) ?: $r['due_date'];
        $amountText = money($overdue > 0 ? $overdue : $balance);
        $sms = sprintf('Dear %s, fee of %s against invoice %s is %s. Please pay at the GIMT accounts office or online to avoid late fee. - %s',
            $r['student_name'], $amountText, $r['invoice_no'], $overdue > 0 ? 'overdue since ' . format_date($nextDue) : 'due on ' . format_date($nextDue), 'GIMT Accounts');
        $sent = false;
        if ($r['email']) {
            $html = '<p>Dear ' . e($r['student_name']) . ',</p><p>This is a reminder that <strong>' . e($amountText) . '</strong> is ' . ($overdue > 0 ? 'overdue' : 'due') . ' against invoice <strong>'
                . e($r['invoice_no']) . '</strong> (' . e($r['title']) . ').</p><p>Total outstanding: <strong>' . e(money($balance)) . '</strong>' . ($nextDue ? ' · Due date: ' . e(format_date($nextDue)) : '')
                . '</p><p>Please pay at the accounts office or through the online payment link shared by the institute. Late payments attract a late fee as per the fee rules.</p><p>Accounts Office<br>' . e($inst) . '</p>';
            if (send_mail($r['email'], 'Fee reminder — ' . $r['invoice_no'], $html, ['related_type' => 'student_fee', 'related_id' => (int) $r['id']])) {
                $out['emails']++;
                $sent = true;
            }
        }
        $phone = $r['mobile'] ?: $r['guardian_phone'];
        if ($phone) {
            send_sms($phone, $sms, ['related_type' => 'student_fee', 'related_id' => (int) $r['id']]);
            $out['sms']++;
            $sent = true;
        }
        if ($r['user_id']) {
            $out['notifications'] += notify((int) $r['user_id'], 'fee', 'Fee payment reminder', $sms, null, 'indian-rupee');
            $sent = true;
        }
        if ($sent) {
            db_exec('UPDATE student_fees SET reminder_count = reminder_count + 1, last_reminder_at = NOW() WHERE id = ?', [(int) $r['id']]);
            log_activity('remind', 'fees', (int) $r['id'], sprintf('Sent fee reminder for %s (%s) to %s', $r['invoice_no'], $amountText, $r['student_name']));
            $out['sent']++;
        } else {
            $out['skipped']++;
        }
    }
    if ($out['sent']) {
        notify('perm:fees', 'fee', 'Pending fee reminders sent', sprintf('%d reminder(s) sent (%d email, %d SMS) by %s.', $out['sent'], $out['emails'], $out['sms'], current_user()['name'] ?? 'staff'), 'admin/fees/invoices?f.overdue=yes', 'bell-ring');
    }
    return $out;
}

/* ------------------------------------------------------------------
 * Fee structures
 * ------------------------------------------------------------------ */

function fees_structure_detail(int $id): ?array
{
    $s = db_row("SELECT fs.*, p.short_name AS program_short, p.name AS program_name, p.total_semesters, ses.name AS session_name, u.name AS created_by_name,
                        (SELECT COUNT(*) FROM student_fees sf WHERE sf.fee_structure_id = fs.id AND sf.status <> 'cancelled') AS invoiced_count,
                        (SELECT COALESCE(SUM(sf.net_amount), 0) FROM student_fees sf WHERE sf.fee_structure_id = fs.id AND sf.status <> 'cancelled') AS invoiced_amount,
                        (SELECT COALESCE(SUM(sf.paid_amount), 0) FROM student_fees sf WHERE sf.fee_structure_id = fs.id AND sf.status <> 'cancelled') AS collected_amount
                 FROM fee_structures fs JOIN programs p ON p.id = fs.program_id JOIN academic_sessions ses ON ses.id = fs.academic_session_id LEFT JOIN users u ON u.id = fs.created_by WHERE fs.id = ?", [$id]);
    if (!$s) {
        return null;
    }
    $s['id'] = (int) $s['id'];
    $s['items'] = db_all('SELECT fsi.id, fsi.fee_head_id, fsi.amount, fsi.is_optional, fh.name AS head_name, fh.code AS head_code, fh.type AS head_type FROM fee_structure_items fsi
                          JOIN fee_heads fh ON fh.id = fsi.fee_head_id WHERE fsi.fee_structure_id = ? ORDER BY fh.sort_order, fh.name', [$id]);
    $s['installments'] = db_all('SELECT id, installment_no, label, percentage, due_date FROM fee_structure_installments WHERE fee_structure_id = ? ORDER BY installment_no', [$id]);
    return $s;
}

/**
 * Create / update a fee structure with its items and installment plan.
 * $in: name, academic_session_id, program_id, semester_no, year_no, due_date, late_fee_per_day, late_fee_max, description, status,
 *      items[{fee_head_id, amount, is_optional}], installments[{label, percentage, due_date}]
 */
function fees_save_structure(array $in, ?int $id = null): int
{
    $errors = validate($in, [
        'name' => 'required|max:150', 'academic_session_id' => 'required|integer|exists:academic_sessions,id', 'program_id' => 'required|integer|exists:programs,id',
        'semester_no' => 'integer|min:1|max:12', 'year_no' => 'integer|min:1|max:6', 'due_date' => 'date', 'late_fee_per_day' => 'numeric|min:0|max:10000',
        'late_fee_max' => 'numeric|min:0|max:1000000', 'description' => 'max:255', 'status' => 'required|in:active,inactive',
    ], ['academic_session_id' => 'Session', 'program_id' => 'Program', 'semester_no' => 'Semester', 'year_no' => 'Year']);
    if ($id && !db_value('SELECT COUNT(*) FROM fee_structures WHERE id = ?', [$id])) {
        throw new CrudException('Fee structure not found.');
    }
    if (empty($errors['program_id']) && !empty($in['semester_no'])) {
        $sems = (int) db_value('SELECT total_semesters FROM programs WHERE id = ?', [(int) $in['program_id']]);
        if ($sems && (int) $in['semester_no'] > $sems) {
            $errors['semester_no'] = "This program has only $sems semester(s).";
        }
    }
    if (empty($errors['name']) && db_value('SELECT COUNT(*) FROM fee_structures WHERE name = ? AND academic_session_id = ?' . ($id ? ' AND id <> ?' : ''),
        $id ? [trim((string) $in['name']), (int) ($in['academic_session_id'] ?? 0), $id] : [trim((string) $in['name']), (int) ($in['academic_session_id'] ?? 0)])) {
        $errors['name'] = 'A fee structure with this name already exists in the session.';
    }
    if (empty($errors) && !empty($in['semester_no']) && db_value('SELECT COUNT(*) FROM fee_structures WHERE program_id = ? AND academic_session_id = ? AND semester_no = ? AND status = \'active\'' . ($id ? ' AND id <> ?' : ''),
        $id ? [(int) $in['program_id'], (int) $in['academic_session_id'], (int) $in['semester_no'], $id] : [(int) $in['program_id'], (int) $in['academic_session_id'], (int) $in['semester_no']])
        && ($in['status'] ?? 'active') === 'active') {
        $errors['semester_no'] = 'An active structure already exists for this program, session and semester. Deactivate it or edit that one.';
    }
    $items = [];
    $heads = [];
    foreach ((array) ($in['items'] ?? []) as $k => $it) {
        $hid = (int) ($it['fee_head_id'] ?? 0);
        $amt = (float) ($it['amount'] ?? 0);
        if (!$hid && $amt == 0) {
            continue;
        }
        if (!$hid || !db_value('SELECT COUNT(*) FROM fee_heads WHERE id = ?', [$hid])) {
            $errors["items.$k.fee_head_id"] = 'Select a fee head.';
        } elseif (isset($heads[$hid])) {
            $errors["items.$k.fee_head_id"] = 'This fee head is already listed.';
        }
        if ($amt <= 0 || $amt > 10000000) {
            $errors["items.$k.amount"] = 'Enter an amount greater than zero.';
        }
        $heads[$hid] = true;
        $items[] = ['fee_head_id' => $hid, 'amount' => fees_round($amt), 'is_optional' => !empty($it['is_optional']) && $it['is_optional'] !== '0' ? 1 : 0];
    }
    if (!$items) {
        $errors['items'] = 'Add at least one fee head with an amount.';
    }
    $insts = [];
    $pct = 0.0;
    foreach ((array) ($in['installments'] ?? []) as $k => $i) {
        $p = (float) ($i['percentage'] ?? 0);
        $d = (string) ($i['due_date'] ?? '');
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        if ($p <= 0 || $p > 100) {
            $errors["installments.$k.percentage"] = 'Enter a share between 0 and 100%.';
        }
        if (!$dt || $dt->format('Y-m-d') !== $d) {
            $errors["installments.$k.due_date"] = 'Enter a due date.';
        } elseif ($insts && $d <= end($insts)['due_date']) {
            $errors["installments.$k.due_date"] = 'Due dates must be in increasing order.';
        }
        $pct += $p;
        $insts[] = ['label' => mb_substr(trim((string) ($i['label'] ?? '')), 0, 60) ?: null, 'percentage' => round($p, 2), 'due_date' => $d];
    }
    if ($insts && abs($pct - 100) > 0.01) {
        $errors['installments'] = 'Installment shares must add up to 100% (currently ' . rtrim(rtrim(number_format($pct, 2), '0'), '.') . '%).';
    }
    if (!$insts && empty($in['due_date'])) {
        $errors['due_date'] = 'Set a due date, or add an installment plan.';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $total = fees_round(array_sum(array_map(fn ($i) => $i['is_optional'] ? 0 : $i['amount'], $items)));
    $data = [
        'name' => trim((string) $in['name']), 'academic_session_id' => (int) $in['academic_session_id'], 'program_id' => (int) $in['program_id'],
        'semester_no' => !empty($in['semester_no']) ? (int) $in['semester_no'] : null,
        'year_no' => !empty($in['year_no']) ? (int) $in['year_no'] : (!empty($in['semester_no']) ? (int) ceil((int) $in['semester_no'] / 2) : null),
        'total_amount' => $total, 'due_date' => $insts ? end($insts)['due_date'] : $in['due_date'], 'late_fee_per_day' => fees_round((float) ($in['late_fee_per_day'] ?? 0)),
        'late_fee_max' => ($in['late_fee_max'] ?? '') !== '' && $in['late_fee_max'] !== null ? fees_round((float) $in['late_fee_max']) : null,
        'installments_allowed' => max(1, count($insts)), 'description' => trim((string) ($in['description'] ?? '')) ?: null, 'status' => $in['status'],
    ];
    $sid = db_transaction(function () use ($data, $items, $insts, $id) {
        if ($id) {
            db_update('fee_structures', $data, 'id = ?', [$id]);
            db_exec('DELETE FROM fee_structure_items WHERE fee_structure_id = ?', [$id]);
            db_exec('DELETE FROM fee_structure_installments WHERE fee_structure_id = ?', [$id]);
            $sid = $id;
        } else {
            $sid = db_insert('fee_structures', $data + ['created_by' => user_id()]);
        }
        foreach ($items as $it) {
            db_insert('fee_structure_items', $it + ['fee_structure_id' => $sid]);
        }
        foreach ($insts as $n => $i) {
            db_insert('fee_structure_installments', $i + ['fee_structure_id' => $sid, 'installment_no' => $n + 1]);
        }
        return $sid;
    });
    log_activity($id ? 'update' : 'create', 'fees', $sid, ($id ? 'Updated' : 'Created') . sprintf(' fee structure "%s" (%s, %d heads%s)', $data['name'], money($total), count($items), $insts ? ', ' . count($insts) . ' installments' : ''));
    return (int) $sid;
}

function fees_duplicate_structure(int $id, ?int $sessionId = null): int
{
    $s = fees_structure_detail($id);
    if (!$s) {
        throw new CrudException('Fee structure not found.');
    }
    $targetSession = $sessionId ?: (int) $s['academic_session_id'];
    $shiftYears = 0;
    if ($targetSession !== (int) $s['academic_session_id']) {
        $a = db_value('SELECT start_date FROM academic_sessions WHERE id = ?', [(int) $s['academic_session_id']]);
        $b = db_value('SELECT start_date FROM academic_sessions WHERE id = ?', [$targetSession]);
        if (!$b) {
            throw new CrudValidationException(['academic_session_id' => 'Select a valid session.']);
        }
        $shiftYears = (int) substr((string) $b, 0, 4) - (int) substr((string) $a, 0, 4);
    }
    $shift = fn (?string $d) => $d && $shiftYears ? date('Y-m-d', strtotime("$d +$shiftYears years")) : $d;
    $sessionName = db_value('SELECT name FROM academic_sessions WHERE id = ?', [$targetSession]);
    $base = $shiftYears ? str_replace((string) $s['session_name'], (string) $sessionName, $s['name']) : $s['name'] . ' (copy)';
    $name = $base;
    for ($n = 2; db_value('SELECT COUNT(*) FROM fee_structures WHERE name = ? AND academic_session_id = ?', [$name, $targetSession]); $n++) {
        $name = $base . ' ' . $n;
    }
    $newId = db_transaction(function () use ($s, $name, $targetSession, $shift) {
        $nid = db_insert('fee_structures', [
            'name' => mb_substr($name, 0, 150), 'academic_session_id' => $targetSession, 'program_id' => (int) $s['program_id'], 'semester_no' => $s['semester_no'], 'year_no' => $s['year_no'],
            'total_amount' => $s['total_amount'], 'due_date' => $shift($s['due_date']), 'late_fee_per_day' => $s['late_fee_per_day'], 'late_fee_max' => $s['late_fee_max'],
            'installments_allowed' => $s['installments_allowed'], 'description' => $s['description'], 'status' => 'inactive', 'created_by' => user_id(),
        ]);
        foreach ($s['items'] as $it) {
            db_insert('fee_structure_items', ['fee_structure_id' => $nid, 'fee_head_id' => (int) $it['fee_head_id'], 'amount' => $it['amount'], 'is_optional' => (int) $it['is_optional']]);
        }
        foreach ($s['installments'] as $i) {
            db_insert('fee_structure_installments', ['fee_structure_id' => $nid, 'installment_no' => (int) $i['installment_no'], 'label' => $i['label'], 'percentage' => $i['percentage'], 'due_date' => $shift($i['due_date'])]);
        }
        return $nid;
    });
    log_activity('create', 'fees', $newId, sprintf('Duplicated fee structure "%s" as "%s" (inactive until reviewed)', $s['name'], $name));
    return (int) $newId;
}

/**
 * Preview or generate invoices for every matching student of a structure's program/semester.
 * $f: semester (override for yearly structures), section_id, include_optional (bool)
 */
function fees_assign_structure(int $structureId, array $f, bool $commit): array
{
    $s = fees_structure_detail($structureId);
    if (!$s) {
        throw new CrudException('Fee structure not found.');
    }
    if ($commit && $s['status'] !== 'active') {
        throw new CrudException('Activate the fee structure before assigning it to students.');
    }
    if (!$s['items']) {
        throw new CrudException('This fee structure has no fee heads.');
    }
    $where = ["s.status = 'active'", 's.program_id = ?'];
    $args = [(int) $s['program_id']];
    if (!empty($f['semester'])) {
        $where[] = 's.current_semester = ?';
        $args[] = (int) $f['semester'];
    } elseif ($s['semester_no']) {
        $where[] = 's.current_semester = ?';
        $args[] = (int) $s['semester_no'];
    } elseif ($s['year_no']) {
        $where[] = 's.current_semester IN (?, ?)';
        $args[] = (int) $s['year_no'] * 2 - 1;
        $args[] = (int) $s['year_no'] * 2;
    }
    if (!empty($f['section_id'])) {
        $where[] = 's.section_id = ?';
        $args[] = (int) $f['section_id'];
    }
    $students = db_all("SELECT s.id, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS name, s.student_uid, s.is_hosteller, s.uses_transport, s.current_semester,
                               (SELECT COUNT(*) FROM student_fees sf WHERE sf.student_id = s.id AND sf.fee_structure_id = ? AND sf.status <> 'cancelled') AS invoiced
                        FROM students s WHERE " . implode(' AND ', $where) . ' ORDER BY s.first_name, s.last_name', array_merge([$structureId], $args));
    $includeOptional = !empty($f['include_optional']) && $f['include_optional'] !== '0';
    $admHead = (int) db_value("SELECT id FROM fee_heads WHERE code = 'ADM'");
    $toCreate = [];
    $already = 0;
    $total = 0.0;
    foreach ($students as $st) {
        if ((int) $st['invoiced']) {
            $already++;
            continue;
        }
        $hasAdmission = $admHead ? (int) db_value("SELECT COUNT(*) FROM student_fee_items sfi JOIN student_fees sf ON sf.id = sfi.student_fee_id WHERE sf.student_id = ? AND sfi.fee_head_id = ? AND sf.status <> 'cancelled'", [(int) $st['id'], $admHead]) : 0;
        $lines = [];
        foreach ($s['items'] as $it) {
            if ((int) $it['fee_head_id'] === $admHead && $hasAdmission) {
                continue;
            }
            if ((int) $it['is_optional']) {
                $applies = $it['head_type'] === 'hostel' ? (int) $st['is_hosteller'] : ($it['head_type'] === 'transport' ? (int) $st['uses_transport'] : $includeOptional);
                if (!$applies) {
                    continue;
                }
            }
            $lines[] = ['head' => (int) $it['fee_head_id'], 'amount' => (float) $it['amount']];
        }
        if (!$lines) {
            continue;
        }
        $sum = fees_round(array_sum(array_column($lines, 'amount')));
        $total += $sum;
        $toCreate[] = ['student' => $st, 'lines' => $lines, 'total' => $sum];
    }
    $result = [
        'matched' => count($students), 'already_invoiced' => $already, 'to_create' => count($toCreate), 'total_amount' => fees_round($total),
        'sample' => array_map(fn ($x) => ['name' => $x['student']['name'], 'student_uid' => $x['student']['student_uid'], 'amount' => $x['total']], array_slice($toCreate, 0, 8)),
        'created' => 0,
    ];
    if (!$commit || !$toCreate) {
        return $result;
    }
    $sessionName = (string) $s['session_name'];
    db_transaction(function () use ($toCreate, $s, $structureId, $sessionName, &$result) {
        foreach ($toCreate as $x) {
            $inst = [];
            if ($s['installments']) {
                $left = $x['total'];
                $n = count($s['installments']);
                foreach ($s['installments'] as $k => $i) {
                    $amt = $k === $n - 1 ? $left : fees_round(floor($x['total'] * (float) $i['percentage'] / 100));
                    $left = fees_round($left - $amt);
                    $inst[] = ['amount' => $amt, 'due_date' => $i['due_date']];
                }
                $inst = array_values(array_filter($inst, fn ($i) => $i['amount'] > 0));
            }
            $sem = $s['semester_no'] ?: (int) $x['student']['current_semester'];
            fees_create_invoice((int) $x['student']['id'], sprintf('%s · Semester %d Fee %s', $s['program_short'], $sem, $sessionName), $x['lines'], [
                'academic_session_id' => (int) $s['academic_session_id'], 'semester_no' => $sem, 'fee_structure_id' => $structureId,
                'installments' => $inst, 'due_date' => $inst ? null : $s['due_date'], 'log' => false,
            ]);
            $result['created']++;
        }
    });
    log_activity('create', 'fees', $structureId, sprintf('Assigned fee structure "%s" — %d invoice(s) raised, %s billed (%d already invoiced)', $s['name'], $result['created'], money($result['total_amount']), $already));
    return $result;
}

/* ------------------------------------------------------------------
 * Dashboard
 * ------------------------------------------------------------------ */

function fees_dashboard(?int $sessionId = null): array
{
    fees_sync_overdue();
    $ses = fees_session($sessionId);
    $sid = $ses['id'];
    $today = fees_today();
    $monthStart = date('Y-m-01');
    $prevStart = date('Y-m-01', strtotime('first day of last month'));
    $prevEnd = date('Y-m-t', strtotime('last day of last month'));
    $paid = "p.status IN ('success', 'refunded')";
    $sum = fn (string $from, string $to) => (float) db_value("SELECT COALESCE(SUM(p.amount + p.fine_amount), 0) FROM payments p WHERE $paid AND p.payment_date BETWEEN ? AND ?", [$from, $to]);
    $inv = db_row("SELECT COUNT(*) AS invoices, COALESCE(SUM(t.net_amount), 0) AS billed, COALESCE(SUM(t.paid_amount), 0) AS collected, COALESCE(SUM(t.balance_amount), 0) AS pending,
                          COALESCE(SUM(" . fees_overdue_amount_sql('t') . "), 0) AS overdue, SUM(CASE WHEN " . fees_overdue_sql('t') . " THEN 1 ELSE 0 END) AS overdue_count,
                          COUNT(DISTINCT CASE WHEN t.balance_amount > 0 THEN t.student_id END) AS students_due,
                          COALESCE(SUM(t.discount_amount + t.scholarship_amount), 0) AS concessions, COALESCE(SUM(t.fine_amount), 0) AS fines
                   FROM student_fees t WHERE t.status <> 'cancelled' AND (t.academic_session_id = ? OR ? IS NULL)", [$sid, $sid]);
    $sessionCollection = $sum($ses['start_date'], min($ses['end_date'], $today));
    $todayRow = db_row("SELECT COUNT(*) AS n, COALESCE(SUM(p.amount + p.fine_amount), 0) AS amount FROM payments p WHERE $paid AND p.payment_date = ?", [$today]);
    $thisMonth = $sum($monthStart, $today);
    $lastMonth = $sum($prevStart, $prevEnd);
    $lastMonthToDate = $sum($prevStart, min($prevEnd, date('Y-m-d', strtotime($prevStart . ' +' . ((int) date('j') - 1) . ' days'))));

    // Monthly collection vs target (installments falling due in the month)
    $months = [];
    $cursor = strtotime($ses['start_date']);
    $endTs = min(strtotime($ses['end_date']), strtotime(date('Y-m-01', strtotime('+2 months'))));
    while ($cursor <= $endTs) {
        $months[] = date('Y-m', $cursor);
        $cursor = strtotime('+1 month', $cursor);
    }
    $collected = db_pairs("SELECT DATE_FORMAT(p.payment_date, '%Y-%m') AS ym, SUM(p.amount + p.fine_amount) FROM payments p WHERE $paid AND p.payment_date BETWEEN ? AND ? GROUP BY ym",
        [$ses['start_date'], $ses['end_date']]);
    $targetInst = db_pairs("SELECT DATE_FORMAT(fi.due_date, '%Y-%m') AS ym, SUM(fi.amount) FROM fee_installments fi JOIN student_fees t ON t.id = fi.student_fee_id
                            WHERE t.status <> 'cancelled' AND (t.academic_session_id = ? OR ? IS NULL) GROUP BY ym", [$sid, $sid]);
    $targetFlat = db_pairs("SELECT DATE_FORMAT(t.due_date, '%Y-%m') AS ym, SUM(t.net_amount) FROM student_fees t WHERE t.status <> 'cancelled' AND (t.academic_session_id = ? OR ? IS NULL)
                            AND NOT EXISTS (SELECT 1 FROM fee_installments fi WHERE fi.student_fee_id = t.id) GROUP BY ym", [$sid, $sid]);
    $monthly = array_map(fn ($ym) => [
        'month' => $ym, 'label' => date('M y', strtotime($ym . '-01')), 'collected' => (float) ($collected[$ym] ?? 0),
        'target' => (float) ($targetInst[$ym] ?? 0) + (float) ($targetFlat[$ym] ?? 0),
    ], $months);

    $modes = db_all("SELECT p.mode, COUNT(*) AS n, SUM(p.amount + p.fine_amount) AS amount FROM payments p WHERE $paid AND p.payment_date BETWEEN ? AND ? GROUP BY p.mode ORDER BY amount DESC",
        [$ses['start_date'], $ses['end_date']]);
    foreach ($modes as &$m) {
        $m['label'] = fees_mode_label($m['mode']);
        $m['amount'] = (float) $m['amount'];
        $m['n'] = (int) $m['n'];
    }
    unset($m);
    $programs = db_all("SELECT pr.id, pr.short_name AS program, COALESCE(SUM(t.net_amount), 0) AS billed, COALESCE(SUM(t.paid_amount), 0) AS collected, COALESCE(SUM(t.balance_amount), 0) AS outstanding,
                               COALESCE(SUM(" . fees_overdue_amount_sql('t') . "), 0) AS overdue
                        FROM student_fees t JOIN students s ON s.id = t.student_id JOIN programs pr ON pr.id = s.program_id
                        WHERE t.status <> 'cancelled' AND (t.academic_session_id = ? OR ? IS NULL) GROUP BY pr.id, pr.short_name ORDER BY outstanding DESC", [$sid, $sid]);
    $recent = db_all("SELECT p.id, p.receipt_no, p.amount + p.fine_amount AS total, p.mode, p.payment_date, p.created_at, p.status, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name,
                             s.student_uid, s.photo, pr.short_name AS program_short, u.name AS collected_by_name
                      FROM payments p LEFT JOIN students s ON s.id = p.student_id LEFT JOIN programs pr ON pr.id = s.program_id LEFT JOIN users u ON u.id = p.collected_by
                      ORDER BY p.payment_date DESC, p.id DESC LIMIT 8");
    $overdue = db_all("SELECT t.id, t.invoice_no, t.title, t.balance_amount, t.last_reminder_at, t.reminder_count, " . fees_overdue_amount_sql('t') . " AS overdue_amount,
                              COALESCE((SELECT MIN(fi.due_date) FROM fee_installments fi WHERE fi.student_fee_id = t.id AND fi.paid_amount < fi.amount AND fi.due_date < CURDATE()), t.due_date) AS overdue_since,
                              t.student_id, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, s.photo, pr.short_name AS program_short, s.current_semester
                       FROM student_fees t JOIN students s ON s.id = t.student_id LEFT JOIN programs pr ON pr.id = s.program_id
                       WHERE " . fees_overdue_sql('t') . " AND (t.academic_session_id = ? OR ? IS NULL) ORDER BY overdue_amount DESC LIMIT 10", [$sid, $sid]);
    foreach ($overdue as &$o) {
        $o['id'] = (int) $o['id'];
        $o['days_overdue'] = $o['overdue_since'] ? max(0, (int) floor((strtotime($today) - strtotime($o['overdue_since'])) / 86400)) : 0;
    }
    unset($o);
    $refundsPending = db_row("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amount FROM refunds WHERE status IN ('pending', 'approved')");
    $expMonth = (float) db_value("SELECT COALESCE(SUM(amount + tax_amount), 0) FROM expenses WHERE status IN ('approved', 'paid') AND expense_date BETWEEN ? AND ?", [$monthStart, $today]);
    $scholarshipStudents = (int) db_value("SELECT COUNT(DISTINCT fc.student_id) FROM fee_concessions fc JOIN student_fees t ON t.id = fc.student_fee_id WHERE fc.status = 'approved' AND fc.type = 'scholarship' AND (t.academic_session_id = ? OR ? IS NULL)", [$sid, $sid]);
    $pct = fn (float $a, float $b) => $b > 0 ? round(($a - $b) * 100 / $b, 1) : null;
    return [
        'session' => $ses,
        'kpis' => [
            'session_collection' => $sessionCollection, 'billed' => (float) $inv['billed'], 'collected' => (float) $inv['collected'], 'pending' => (float) $inv['pending'],
            'overdue' => (float) $inv['overdue'], 'overdue_count' => (int) $inv['overdue_count'], 'students_due' => (int) $inv['students_due'], 'invoices' => (int) $inv['invoices'],
            'today' => (float) $todayRow['amount'], 'today_count' => (int) $todayRow['n'], 'this_month' => $thisMonth, 'last_month' => $lastMonth,
            'month_change' => $pct($thisMonth, $lastMonthToDate), 'concessions' => (float) $inv['concessions'], 'fines' => (float) $inv['fines'],
            'collection_rate' => (float) $inv['billed'] > 0 ? round((float) $inv['collected'] * 100 / (float) $inv['billed'], 1) : 0,
            'refunds_pending' => (int) $refundsPending['n'], 'refunds_pending_amount' => (float) $refundsPending['amount'], 'expenses_month' => $expMonth,
            'scholarship_students' => $scholarshipStudents,
        ],
        'monthly' => $monthly, 'modes' => $modes, 'programs' => $programs, 'recent_payments' => $recent, 'overdue' => $overdue,
        'gateway' => fees_gateway_info(),
    ];
}

/* ------------------------------------------------------------------
 * Reports
 * ------------------------------------------------------------------ */

/** Normalised report filters from the query string. */
function fees_report_filters(array $q): array
{
    $ses = fees_session(!empty($q['session_id']) ? (int) $q['session_id'] : null);
    $from = (string) ($q['from'] ?? '');
    $to = (string) ($q['to'] ?? '');
    $isDate = fn ($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    return [
        'session' => $ses,
        'from' => $isDate($from) ? $from : $ses['start_date'],
        'to' => $isDate($to) ? $to : min($ses['end_date'], fees_today()),
        'mode' => isset(fees_modes()[$q['mode'] ?? '']) ? $q['mode'] : null,
        'program_id' => !empty($q['program_id']) ? (int) $q['program_id'] : null,
        'semester' => !empty($q['semester']) ? (int) $q['semester'] : null,
        'collector' => !empty($q['collector']) ? (int) $q['collector'] : null,
        'q' => trim((string) ($q['q'] ?? '')),
        'min_days' => isset($q['min_days']) && $q['min_days'] !== '' ? max(0, (int) $q['min_days']) : 0,
    ];
}

/**
 * Build a report. Returns ['columns' => [[key,label,format]], 'rows' => [...], 'total' => n, 'summary' => [...], 'chart' => [...]]
 * $page/$perPage = null returns every row (exports).
 */
function fees_report(string $tab, array $f, ?int $page = 1, ?int $perPage = 25): array
{
    fees_sync_overdue();
    $limit = function (string $sql, array $args, ?int $page, ?int $perPage, int $total): array {
        if ($page === null) {
            return [db_all($sql . ' LIMIT 20000', $args), paginate($total, 1, max(1, $total))];
        }
        $p = paginate($total, $page, $perPage ?? 25);
        return [db_all($sql . ' LIMIT ' . $p['per_page'] . ' OFFSET ' . $p['offset'], $args), $p];
    };
    $sid = $f['session']['id'];
    switch ($tab) {
        case 'collection': {
            $where = ["p.status IN ('success', 'refunded')", 'p.payment_date BETWEEN ? AND ?'];
            $args = [$f['from'], $f['to']];
            if ($f['mode']) {
                $where[] = 'p.mode = ?';
                $args[] = $f['mode'];
            }
            if ($f['program_id']) {
                $where[] = 's.program_id = ?';
                $args[] = $f['program_id'];
            }
            if ($f['collector']) {
                $where[] = 'p.collected_by = ?';
                $args[] = $f['collector'];
            }
            if ($f['q'] !== '') {
                $where[] = "(p.receipt_no LIKE ? OR s.student_uid LIKE ? OR CONCAT(s.first_name, ' ', s.last_name) LIKE ? OR p.reference_no LIKE ?)";
                array_push($args, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%");
            }
            $from = ' FROM payments p LEFT JOIN students s ON s.id = p.student_id LEFT JOIN programs pr ON pr.id = s.program_id LEFT JOIN users u ON u.id = p.collected_by WHERE ' . implode(' AND ', $where);
            $sum = db_row('SELECT COUNT(*) AS n, COALESCE(SUM(p.amount), 0) AS fee, COALESCE(SUM(p.fine_amount), 0) AS fine, COALESCE(SUM(p.amount + p.fine_amount), 0) AS total, COUNT(DISTINCT p.student_id) AS students' . $from, $args);
            $byMode = db_all('SELECT p.mode, COUNT(*) AS n, SUM(p.amount + p.fine_amount) AS amount' . $from . ' GROUP BY p.mode ORDER BY amount DESC', $args);
            foreach ($byMode as &$m) {
                $m['label'] = fees_mode_label($m['mode']);
            }
            unset($m);
            $daily = db_all('SELECT p.payment_date AS d, SUM(p.amount + p.fine_amount) AS amount' . $from . ' GROUP BY p.payment_date ORDER BY p.payment_date', $args);
            [$rows, $p] = $limit("SELECT p.id, p.receipt_no, p.payment_date, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, pr.short_name AS program, p.mode,
                                         p.reference_no, p.amount, p.fine_amount, p.amount + p.fine_amount AS total, u.name AS collected_by" . $from . ' ORDER BY p.payment_date DESC, p.id DESC', $args, $page, $perPage, (int) $sum['n']);
            foreach ($rows as &$r) {
                $r['mode'] = fees_mode_label($r['mode']);
            }
            unset($r);
            return [
                'columns' => [['receipt_no', 'Receipt', 'code'], ['payment_date', 'Date', 'date'], ['student_name', 'Student', 'text'], ['student_uid', 'Student ID', 'text'], ['program', 'Program', 'text'],
                    ['mode', 'Mode', 'text'], ['reference_no', 'Reference', 'text'], ['amount', 'Fee', 'money'], ['fine_amount', 'Late fee', 'money'], ['total', 'Total', 'money'], ['collected_by', 'Collected by', 'text']],
                'rows' => $rows, 'pagination' => $p,
                'summary' => ['receipts' => (int) $sum['n'], 'students' => (int) $sum['students'], 'fee' => (float) $sum['fee'], 'fine' => (float) $sum['fine'], 'total' => (float) $sum['total'],
                    'average' => $sum['n'] ? round((float) $sum['total'] / (int) $sum['n']) : 0, 'by_mode' => $byMode],
                'chart' => ['labels' => array_map(fn ($d) => date('d M', strtotime($d['d'])), $daily), 'values' => array_map(fn ($d) => (float) $d['amount'], $daily)],
            ];
        }
        case 'outstanding':
        case 'defaulters': {
            $where = ["t.status <> 'cancelled'", 't.balance_amount > 0', '(t.academic_session_id = ? OR ? IS NULL)'];
            $args = [$sid, $sid];
            if ($f['program_id']) {
                $where[] = 's.program_id = ?';
                $args[] = $f['program_id'];
            }
            if ($f['semester']) {
                $where[] = 's.current_semester = ?';
                $args[] = $f['semester'];
            }
            if ($f['q'] !== '') {
                $where[] = "(s.student_uid LIKE ? OR CONCAT(s.first_name, ' ', s.last_name) LIKE ? OR t.invoice_no LIKE ?)";
                array_push($args, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%");
            }
            $since = "COALESCE((SELECT MIN(fi.due_date) FROM fee_installments fi WHERE fi.student_fee_id = t.id AND fi.paid_amount < fi.amount AND fi.due_date < CURDATE()), CASE WHEN t.due_date < CURDATE() THEN t.due_date END)";
            if ($tab === 'defaulters') {
                $where[] = fees_overdue_sql('t');
                if ($f['min_days'] > 0) {
                    $where[] = "DATEDIFF(CURDATE(), $since) >= ?";
                    $args[] = $f['min_days'];
                }
                $from = ' FROM student_fees t JOIN students s ON s.id = t.student_id LEFT JOIN programs pr ON pr.id = s.program_id WHERE ' . implode(' AND ', $where);
                $grouped = "SELECT s.id AS student_id, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, pr.short_name AS program, s.current_semester AS semester,
                                   s.mobile, COUNT(*) AS invoices, SUM(t.net_amount) AS net, SUM(t.paid_amount) AS paid, SUM(t.balance_amount) AS balance, SUM(" . fees_overdue_amount_sql('t') . ") AS overdue,
                                   MIN($since) AS overdue_since, DATEDIFF(CURDATE(), MIN($since)) AS days_overdue, MAX(t.last_reminder_at) AS last_reminder_at,
                                   GROUP_CONCAT(t.id) AS invoice_ids" . $from . ' GROUP BY s.id, s.first_name, s.last_name, s.student_uid, pr.short_name, s.current_semester, s.mobile';
                $sum = db_row("SELECT COUNT(*) AS n, COALESCE(SUM(x.overdue), 0) AS overdue, COALESCE(SUM(x.balance), 0) AS balance, COALESCE(AVG(x.days_overdue), 0) AS avg_days FROM ($grouped) x", $args);
                [$rows, $p] = $limit("SELECT * FROM ($grouped) x ORDER BY x.overdue DESC, x.days_overdue DESC", $args, $page, $perPage, (int) $sum['n']);
                $buckets = db_all("SELECT CASE WHEN x.days_overdue <= 15 THEN '0-15 days' WHEN x.days_overdue <= 30 THEN '16-30 days' WHEN x.days_overdue <= 60 THEN '31-60 days' ELSE '60+ days' END AS bucket,
                                          COUNT(*) AS n, SUM(x.overdue) AS amount FROM ($grouped) x GROUP BY bucket ORDER BY MIN(x.days_overdue)", $args);
                return [
                    'columns' => [['student_name', 'Student', 'text'], ['student_uid', 'Student ID', 'text'], ['program', 'Program', 'text'], ['semester', 'Sem', 'number'], ['mobile', 'Mobile', 'text'],
                        ['invoices', 'Invoices', 'number'], ['balance', 'Balance', 'money'], ['overdue', 'Overdue', 'money'], ['overdue_since', 'Overdue since', 'date'], ['days_overdue', 'Days', 'number'],
                        ['last_reminder_at', 'Last reminder', 'datetime']],
                    'rows' => $rows, 'pagination' => $p,
                    'summary' => ['students' => (int) $sum['n'], 'overdue' => (float) $sum['overdue'], 'balance' => (float) $sum['balance'], 'avg_days' => round((float) $sum['avg_days'])],
                    'chart' => ['labels' => array_column($buckets, 'bucket'), 'values' => array_map(fn ($b) => (float) $b['amount'], $buckets), 'counts' => array_map(fn ($b) => (int) $b['n'], $buckets)],
                ];
            }
            $from = ' FROM student_fees t JOIN students s ON s.id = t.student_id LEFT JOIN programs pr ON pr.id = s.program_id WHERE ' . implode(' AND ', $where);
            $sum = db_row('SELECT COUNT(*) AS n, COUNT(DISTINCT t.student_id) AS students, COALESCE(SUM(t.net_amount), 0) AS net, COALESCE(SUM(t.paid_amount), 0) AS paid, COALESCE(SUM(t.balance_amount), 0) AS balance,
                                  COALESCE(SUM(' . fees_overdue_amount_sql('t') . '), 0) AS overdue' . $from, $args);
            $byProgram = db_all('SELECT pr.short_name AS program, SUM(t.balance_amount) AS balance, SUM(' . fees_overdue_amount_sql('t') . ') AS overdue' . $from . ' GROUP BY pr.short_name ORDER BY balance DESC', $args);
            [$rows, $p] = $limit("SELECT t.id, t.invoice_no, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, pr.short_name AS program, t.semester_no, t.title, t.due_date,
                                         t.net_amount, t.paid_amount, t.balance_amount, " . fees_overdue_amount_sql('t') . ' AS overdue_amount, t.status' . $from . ' ORDER BY t.balance_amount DESC, t.id', $args, $page, $perPage, (int) $sum['n']);
            return [
                'columns' => [['invoice_no', 'Invoice', 'code'], ['student_name', 'Student', 'text'], ['student_uid', 'Student ID', 'text'], ['program', 'Program', 'text'], ['semester_no', 'Sem', 'number'],
                    ['due_date', 'Due date', 'date'], ['net_amount', 'Net', 'money'], ['paid_amount', 'Paid', 'money'], ['balance_amount', 'Balance', 'money'], ['overdue_amount', 'Overdue', 'money'], ['status', 'Status', 'badge']],
                'rows' => $rows, 'pagination' => $p,
                'summary' => ['invoices' => (int) $sum['n'], 'students' => (int) $sum['students'], 'net' => (float) $sum['net'], 'paid' => (float) $sum['paid'], 'balance' => (float) $sum['balance'], 'overdue' => (float) $sum['overdue']],
                'chart' => ['labels' => array_column($byProgram, 'program'), 'values' => array_map(fn ($b) => (float) $b['balance'], $byProgram), 'overdue' => array_map(fn ($b) => (float) $b['overdue'], $byProgram)],
            ];
        }
        case 'headwise': {
            // Collected amount per head = invoice paid (excluding late fee) split in proportion to the invoice items.
            $where = ["t.status <> 'cancelled'", '(t.academic_session_id = ? OR ? IS NULL)'];
            $args = [$sid, $sid];
            if ($f['program_id']) {
                $where[] = 's.program_id = ?';
                $args[] = $f['program_id'];
            }
            if ($f['semester']) {
                $where[] = 't.semester_no = ?';
                $args[] = $f['semester'];
            }
            $rows = db_all("SELECT fh.id, fh.name AS head, fh.code, fh.type, COUNT(DISTINCT t.id) AS invoices, SUM(sfi.amount) AS billed,
                                   SUM(sfi.amount * (t.discount_amount + t.scholarship_amount) / NULLIF(t.gross_amount, 0)) AS concession,
                                   SUM(sfi.amount * LEAST(1, GREATEST(0, t.paid_amount - t.fine_amount) / NULLIF(t.gross_amount - t.discount_amount - t.scholarship_amount, 0))) AS collected
                            FROM student_fee_items sfi JOIN student_fees t ON t.id = sfi.student_fee_id JOIN students s ON s.id = t.student_id JOIN fee_heads fh ON fh.id = sfi.fee_head_id
                            WHERE " . implode(' AND ', $where) . ' GROUP BY fh.id, fh.name, fh.code, fh.type, fh.sort_order ORDER BY fh.sort_order', $args);
            $fines = db_row('SELECT COALESCE(SUM(t.fine_amount), 0) AS billed, COALESCE(SUM(LEAST(t.fine_amount, t.paid_amount)), 0) AS collected FROM student_fees t JOIN students s ON s.id = t.student_id WHERE ' . implode(' AND ', $where), $args);
            foreach ($rows as &$r) {
                $r['billed'] = fees_round((float) $r['billed']);
                $r['concession'] = fees_round((float) $r['concession']);
                $r['net'] = fees_round($r['billed'] - $r['concession']);
                $r['collected'] = fees_round((float) $r['collected']);
                $r['outstanding'] = fees_round(max(0, $r['net'] - $r['collected']));
                $r['rate'] = $r['net'] > 0 ? round($r['collected'] * 100 / $r['net'], 1) : 0;
                $r['type'] = fees_head_types()[$r['type']] ?? $r['type'];
            }
            unset($r);
            if ((float) $fines['billed'] > 0) {
                $rows[] = ['id' => 0, 'head' => 'Late fee / fines', 'code' => 'FINE', 'type' => 'Fine', 'invoices' => null, 'billed' => (float) $fines['billed'], 'concession' => 0, 'net' => (float) $fines['billed'],
                    'collected' => (float) $fines['collected'], 'outstanding' => fees_round((float) $fines['billed'] - (float) $fines['collected']), 'rate' => round((float) $fines['collected'] * 100 / (float) $fines['billed'], 1)];
            }
            $tot = ['billed' => 0.0, 'concession' => 0.0, 'net' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0];
            foreach ($rows as $r) {
                foreach ($tot as $k => $v) {
                    $tot[$k] += (float) $r[$k];
                }
            }
            $tot = array_map('fees_round', $tot);
            $tot['rate'] = $tot['net'] > 0 ? round($tot['collected'] * 100 / $tot['net'], 1) : 0;
            return [
                'columns' => [['head', 'Fee head', 'text'], ['code', 'Code', 'code'], ['type', 'Type', 'text'], ['invoices', 'Invoices', 'number'], ['billed', 'Billed', 'money'], ['concession', 'Concession', 'money'],
                    ['net', 'Net', 'money'], ['collected', 'Collected', 'money'], ['outstanding', 'Outstanding', 'money'], ['rate', 'Collected %', 'percent']],
                'rows' => $rows, 'pagination' => paginate(count($rows), 1, max(1, count($rows))), 'summary' => $tot,
                'chart' => ['labels' => array_column($rows, 'head'), 'values' => array_map(fn ($r) => (float) $r['collected'], $rows), 'outstanding' => array_map(fn ($r) => (float) $r['outstanding'], $rows)],
            ];
        }
        case 'daybook': {
            // Daily cash book: receipts in, refunds + expenses out, per day and mode.
            $from = $f['from'];
            $to = $f['to'];
            $in = db_all("SELECT p.payment_date AS d, p.mode, SUM(p.amount + p.fine_amount) AS amount, COUNT(*) AS n FROM payments p WHERE p.status IN ('success', 'refunded') AND p.payment_date BETWEEN ? AND ? GROUP BY p.payment_date, p.mode", [$from, $to]);
            $refunds = db_all("SELECT r.refund_date AS d, r.mode, SUM(r.amount) AS amount, COUNT(*) AS n FROM refunds r WHERE r.status = 'processed' AND r.refund_date BETWEEN ? AND ? GROUP BY r.refund_date, r.mode", [$from, $to]);
            $exp = db_all("SELECT e.expense_date AS d, COALESCE(e.payment_mode, 'bank_transfer') AS mode, SUM(e.amount + e.tax_amount) AS amount, COUNT(*) AS n FROM expenses e WHERE e.status = 'paid' AND e.expense_date BETWEEN ? AND ? GROUP BY e.expense_date, mode", [$from, $to]);
            $days = [];
            $blank = fn ($d) => ['date' => $d, 'receipts' => 0, 'cash_in' => 0.0, 'bank_in' => 0.0, 'total_in' => 0.0, 'refunds_out' => 0.0, 'expenses_out' => 0.0, 'total_out' => 0.0, 'cash_out' => 0.0, 'net' => 0.0, 'cash_balance' => 0.0];
            foreach ($in as $r) {
                $days[$r['d']] = $days[$r['d']] ?? $blank($r['d']);
                $days[$r['d']]['receipts'] += (int) $r['n'];
                $days[$r['d']][$r['mode'] === 'cash' ? 'cash_in' : 'bank_in'] += (float) $r['amount'];
                $days[$r['d']]['total_in'] += (float) $r['amount'];
            }
            foreach ([['refunds_out', $refunds], ['expenses_out', $exp]] as [$key, $list]) {
                foreach ($list as $r) {
                    $days[$r['d']] = $days[$r['d']] ?? $blank($r['d']);
                    $days[$r['d']][$key] += (float) $r['amount'];
                    $days[$r['d']]['total_out'] += (float) $r['amount'];
                    if ($r['mode'] === 'cash') {
                        $days[$r['d']]['cash_out'] += (float) $r['amount'];
                    }
                }
            }
            ksort($days);
            $cash = 0.0;
            foreach ($days as &$d) {
                $d['net'] = fees_round($d['total_in'] - $d['total_out']);
                $cash = fees_round($cash + $d['cash_in'] - $d['cash_out']);
                $d['cash_balance'] = $cash;
            }
            unset($d);
            $list = array_values(array_reverse($days));
            $tot = ['receipts' => 0, 'cash_in' => 0.0, 'bank_in' => 0.0, 'total_in' => 0.0, 'refunds_out' => 0.0, 'expenses_out' => 0.0, 'total_out' => 0.0];
            foreach ($list as $d) {
                foreach ($tot as $k => $v) {
                    $tot[$k] += $d[$k];
                }
            }
            $tot['net'] = fees_round($tot['total_in'] - $tot['total_out']);
            $total = count($list);
            $p = $page === null ? paginate($total, 1, max(1, $total)) : paginate($total, $page, $perPage ?? 25);
            $chartDays = array_slice(array_values($days), -31);
            return [
                'columns' => [['date', 'Date', 'date'], ['receipts', 'Receipts', 'number'], ['cash_in', 'Cash in', 'money'], ['bank_in', 'Bank / digital in', 'money'], ['total_in', 'Total in', 'money'],
                    ['refunds_out', 'Refunds out', 'money'], ['expenses_out', 'Expenses out', 'money'], ['total_out', 'Total out', 'money'], ['net', 'Net', 'money'], ['cash_balance', 'Cumulative cash', 'money']],
                'rows' => $page === null ? $list : array_slice($list, $p['offset'], $p['per_page']), 'pagination' => $p, 'summary' => $tot,
                'chart' => ['labels' => array_map(fn ($d) => date('d M', strtotime($d['date'])), $chartDays), 'values' => array_map(fn ($d) => $d['total_in'], $chartDays), 'out' => array_map(fn ($d) => $d['total_out'], $chartDays)],
            ];
        }
    }
    throw new InvalidArgumentException('Unknown report.');
}

/** Stream a report as CSV / XLSX / print view. */
function fees_report_export(string $tab, array $f, string $format): void
{
    $titles = ['collection' => 'Fee Collection Report', 'outstanding' => 'Outstanding Fees Report', 'defaulters' => 'Fee Defaulters Report', 'headwise' => 'Head-wise Collection Report', 'daybook' => 'Daily Cash Book'];
    $rep = fees_report($tab, $f, null, null);
    $headers = array_map(fn ($c) => $c[1], $rep['columns']);
    $data = [];
    foreach ($rep['rows'] as $r) {
        $line = [];
        foreach ($rep['columns'] as [$key, , $fmt]) {
            $v = $r[$key] ?? null;
            $line[] = $v === null ? '' : match ($fmt) {
                'date' => format_date($v), 'datetime' => format_datetime($v), 'badge' => label_from_key((string) $v), 'percent' => $v . '%', default => (string) $v,
            };
        }
        $data[] = $line;
    }
    $title = $titles[$tab] ?? 'Fee Report';
    $period = format_date($f['from']) . ' – ' . format_date($f['to']);
    log_activity('export', 'fees', null, sprintf('Exported %s (%s, %d rows, %s)', $title, strtoupper($format), count($data), in_array($tab, ['collection', 'daybook'], true) ? $period : 'session ' . $f['session']['name']));
    $file = $tab . '-report-' . date('Y-m-d-His');
    if ($format === 'xlsx') {
        xlsx_download($file . '.xlsx', $headers, $data, mb_substr($title, 0, 30));
    }
    if ($format === 'print') {
        print_layout_start($title, true, count($headers) > 7 ? 'landscape' : 'portrait');
        echo '<div class="mt-5 mb-3"><h1 class="font-display text-xl font-bold text-brand-900">' . e($title) . '</h1><p class="text-xs text-slate-500">'
            . e(in_array($tab, ['collection', 'daybook'], true) ? 'Period ' . $period : 'Academic session ' . $f['session']['name']) . ' · Generated ' . e(format_datetime(date('Y-m-d H:i:s'))) . ' · ' . count($data) . ' rows</p></div>';
        echo '<table class="w-full border-collapse text-[10.5px]"><thead><tr>';
        foreach ($rep['columns'] as [$key, $label, $fmt]) {
            echo '<th class="border border-brand-900 bg-brand-900 px-2 py-1.5 font-semibold text-white ' . (in_array($fmt, ['money', 'number', 'percent'], true) ? 'text-right' : 'text-left') . '">' . e($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rep['rows'] as $i => $r) {
            echo '<tr class="' . ($i % 2 ? 'bg-slate-50' : '') . '">';
            foreach ($rep['columns'] as $ci => [$key, , $fmt]) {
                $v = $r[$key] ?? null;
                $txt = $v === null || $v === '' ? '—' : match ($fmt) {
                    'money' => number_in((float) $v, 2), 'number' => (string) $v, 'percent' => $v . '%', 'date' => format_date($v), 'datetime' => format_datetime($v), 'badge' => label_from_key((string) $v), default => (string) $v,
                };
                echo '<td class="border border-slate-200 px-2 py-1 align-top ' . (in_array($fmt, ['money', 'number', 'percent'], true) ? 'text-right tabular-nums' : '') . '">' . e($txt) . '</td>';
            }
            echo '</tr>';
        }
        if (!$rep['rows']) {
            echo '<tr><td colspan="' . count($headers) . '" class="border border-slate-200 px-2 py-6 text-center text-slate-500">No records for the selected filters.</td></tr>';
        }
        echo '</tbody></table>';
        $s = $rep['summary'];
        $chips = [];
        foreach (['total' => 'Total collected', 'balance' => 'Outstanding', 'overdue' => 'Overdue', 'collected' => 'Collected', 'net' => 'Net', 'total_in' => 'Total in', 'total_out' => 'Total out'] as $k => $label) {
            if (isset($s[$k]) && is_numeric($s[$k])) {
                $chips[] = '<span class="rounded-lg border border-slate-200 px-3 py-1.5"><span class="text-slate-500">' . e($label) . ':</span> <strong>' . e(money((float) $s[$k], 2)) . '</strong></span>';
            }
        }
        if ($chips) {
            echo '<div class="mt-4 flex flex-wrap gap-2 text-[11px]">' . implode('', $chips) . '</div>';
        }
        print_layout_end(true);
        exit;
    }
    csv_download($file . '.csv', $headers, $data);
}

/* ------------------------------------------------------------------
 * Expenses
 * ------------------------------------------------------------------ */

function expenses_transition(int $id, string $action, array $in = []): array
{
    $e = db_row('SELECT * FROM expenses WHERE id = ?', [$id]);
    if (!$e) {
        throw new CrudException('Expense not found.');
    }
    $now = date('Y-m-d H:i:s');
    $total = (float) $e['amount'] + (float) $e['tax_amount'];
    switch ($action) {
        case 'approve':
            if ($e['status'] !== 'pending') {
                throw new CrudException('Only pending expenses can be approved.');
            }
            db_update('expenses', ['status' => 'approved', 'approved_by' => user_id(), 'approved_at' => $now, 'remarks' => trim((string) ($in['remarks'] ?? '')) ?: null], 'id = ?', [$id]);
            log_activity('approve', 'expenses', $id, sprintf('Approved expense %s "%s" (%s)', $e['expense_no'], $e['title'], money($total, 2)));
            if ($e['created_by'] && (int) $e['created_by'] !== user_id()) {
                notify((int) $e['created_by'], 'system', 'Expense approved', sprintf('%s "%s" (%s) was approved.', $e['expense_no'], $e['title'], money($total)), 'admin/expenses', 'circle-check');
            }
            break;
        case 'reject':
            if (!in_array($e['status'], ['pending', 'approved'], true)) {
                throw new CrudException('Only pending or approved expenses can be rejected.');
            }
            $remarks = trim((string) ($in['remarks'] ?? ''));
            if (mb_strlen($remarks) < 5) {
                throw new CrudValidationException(['remarks' => 'Enter the reason for rejecting (at least 5 characters).']);
            }
            db_update('expenses', ['status' => 'rejected', 'approved_by' => user_id(), 'approved_at' => $now, 'remarks' => mb_substr($remarks, 0, 255)], 'id = ?', [$id]);
            log_activity('update', 'expenses', $id, sprintf('Rejected expense %s "%s" — %s', $e['expense_no'], $e['title'], $remarks));
            if ($e['created_by'] && (int) $e['created_by'] !== user_id()) {
                notify((int) $e['created_by'], 'system', 'Expense rejected', sprintf('%s "%s" was rejected: %s', $e['expense_no'], $e['title'], $remarks), 'admin/expenses', 'circle-x');
            }
            break;
        case 'pay':
            if ($e['status'] !== 'approved') {
                throw new CrudException('Approve the expense before marking it paid.');
            }
            $errors = [];
            $mode = (string) ($in['payment_mode'] ?? ($e['payment_mode'] ?? ''));
            if (!isset(fees_modes()[$mode]) || $mode === 'online') {
                $errors['payment_mode'] = 'Select how the vendor was paid.';
            }
            $ref = trim((string) ($in['reference_no'] ?? ($e['reference_no'] ?? '')));
            if ($mode !== 'cash' && $ref === '') {
                $errors['reference_no'] = 'Enter the UTR / cheque number of the payment.';
            }
            if ($errors) {
                throw new CrudValidationException($errors);
            }
            db_update('expenses', ['status' => 'paid', 'payment_mode' => $mode, 'reference_no' => $ref !== '' ? mb_substr($ref, 0, 100) : null, 'paid_at' => $now], 'id = ?', [$id]);
            log_activity('update', 'expenses', $id, sprintf('Marked expense %s "%s" as paid (%s via %s)', $e['expense_no'], $e['title'], money($total, 2), fees_mode_label($mode)));
            break;
        default:
            throw new InvalidArgumentException('Unknown expense action.');
    }
    return db_row('SELECT * FROM expenses WHERE id = ?', [$id]);
}

function expenses_summary(?int $sessionId = null): array
{
    $ses = fees_session($sessionId);
    $from = $ses['start_date'];
    $to = $ses['end_date'];
    $counted = "e.status IN ('approved', 'paid')";
    $tot = db_row("SELECT COALESCE(SUM(CASE WHEN $counted THEN e.amount + e.tax_amount END), 0) AS spent, COALESCE(SUM(CASE WHEN e.status = 'pending' THEN e.amount + e.tax_amount END), 0) AS pending_amount,
                          SUM(e.status = 'pending') AS pending_count, SUM(e.status = 'approved') AS approved_unpaid, COALESCE(SUM(CASE WHEN e.status = 'approved' THEN e.amount + e.tax_amount END), 0) AS approved_unpaid_amount,
                          COALESCE(SUM(CASE WHEN $counted THEN e.tax_amount END), 0) AS tax, COUNT(*) AS n
                   FROM expenses e WHERE e.expense_date BETWEEN ? AND ?", [$from, $to]);
    $month = (float) db_value("SELECT COALESCE(SUM(e.amount + e.tax_amount), 0) FROM expenses e WHERE $counted AND e.expense_date BETWEEN ? AND ?", [date('Y-m-01'), fees_today()]);
    $prev = (float) db_value("SELECT COALESCE(SUM(e.amount + e.tax_amount), 0) FROM expenses e WHERE $counted AND e.expense_date BETWEEN ? AND ?", [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))]);
    $budget = (float) db_value("SELECT COALESCE(SUM(budget_amount), 0) FROM expense_categories WHERE status = 'active'");
    $months = [];
    $cursor = strtotime($from);
    $endTs = min(strtotime($to), strtotime(date('Y-m-01')));
    while ($cursor <= $endTs) {
        $months[] = date('Y-m', $cursor);
        $cursor = strtotime('+1 month', $cursor);
    }
    $byMonth = db_pairs("SELECT DATE_FORMAT(e.expense_date, '%Y-%m') AS ym, SUM(e.amount + e.tax_amount) FROM expenses e WHERE $counted AND e.expense_date BETWEEN ? AND ? GROUP BY ym", [$from, $to]);
    $income = db_pairs("SELECT DATE_FORMAT(p.payment_date, '%Y-%m') AS ym, SUM(p.amount + p.fine_amount) FROM payments p WHERE p.status IN ('success', 'refunded') AND p.payment_date BETWEEN ? AND ? GROUP BY ym", [$from, $to]);
    $categories = db_all("SELECT c.id, c.name, c.budget_amount, COALESCE(SUM(CASE WHEN $counted THEN e.amount + e.tax_amount END), 0) AS spent, COUNT(e.id) AS n
                          FROM expense_categories c LEFT JOIN expenses e ON e.category_id = c.id AND e.expense_date BETWEEN ? AND ?
                          GROUP BY c.id, c.name, c.budget_amount ORDER BY spent DESC", [$from, $to]);
    $vendors = db_all("SELECT e.vendor, COUNT(*) AS n, SUM(e.amount + e.tax_amount) AS amount FROM expenses e WHERE $counted AND e.vendor IS NOT NULL AND e.expense_date BETWEEN ? AND ? GROUP BY e.vendor ORDER BY amount DESC LIMIT 5", [$from, $to]);
    return [
        'session' => $ses,
        'kpis' => ['spent' => (float) $tot['spent'], 'this_month' => $month, 'last_month' => $prev, 'pending_amount' => (float) $tot['pending_amount'], 'pending_count' => (int) $tot['pending_count'],
            'approved_unpaid' => (int) $tot['approved_unpaid'], 'approved_unpaid_amount' => (float) $tot['approved_unpaid_amount'], 'tax' => (float) $tot['tax'], 'count' => (int) $tot['n'],
            'budget' => $budget, 'utilization' => $budget > 0 ? round((float) $tot['spent'] * 100 / $budget, 1) : 0],
        'monthly' => array_map(fn ($ym) => ['month' => $ym, 'label' => date('M y', strtotime($ym . '-01')), 'spent' => (float) ($byMonth[$ym] ?? 0), 'income' => (float) ($income[$ym] ?? 0)], $months),
        'categories' => array_map(fn ($c) => $c + ['utilization' => (float) $c['budget_amount'] > 0 ? round((float) $c['spent'] * 100 / (float) $c['budget_amount'], 1) : null], $categories),
        'vendors' => $vendors,
    ];
}
