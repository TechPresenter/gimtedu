<?php
/**
 * Demo data for the Fees & accounts unit (deterministic, re-runnable).
 *   - Fee head + expense category descriptions (adds a "Miscellaneous Fee" head)
 *   - Fee structures for every program of the current session: odd-semester structures (assigned to students) with
 *     fee-head items and installment plans, even-semester structures for January (active, not yet assigned)
 *   - One invoice per active student (items + installments) generated from the matching structure
 *   - Scholarship schemes, scholarship awards and discounts / waivers (fee_concessions)
 *   - Payments from the session start up to today (~70% of the billing collected; partial, overdue and late-fee cases),
 *     payment allocations, receipts, a few dishonoured cheques (voided), refunds in every workflow state, reminder history
 *   - ~150 expenses across categories through the approval workflow
 * Invoices / payments raised by other units (admission-fee postings, hostel and transport allocation invoices) are kept.
 * Owns: fee_heads, fee_structures, fee_structure_items, fee_structure_installments, scholarships, student_fees,
 *       student_fee_items, fee_installments, fee_concessions, payments, payment_allocations, payment_receipts, refunds,
 *       expense_categories, expenses.
 */
if (!function_exists('fees_demo_bill_pdf')) {
    /**
     * Minimal one-page vendor bill (A4 PDF) used as the demo attachment of an expense.
     * $lines = [[label, value], ...]; amounts already formatted.
     */
    function fees_demo_bill_pdf(string $vendor, string $billNo, string $date, string $particulars, array $lines): string
    {
        $esc = fn (string $s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], preg_replace('/[^\x20-\x7E]/', '-', $s));
        $c = "0.15 0.2 0.28 rg 0 772 595 70 re f\n";
        $c .= 'BT 1 1 1 rg /F2 17 Tf 40 812 Td (' . $esc(mb_strimwidth($vendor, 0, 52, '...')) . ") Tj ET\n";
        $c .= 'BT 1 1 1 rg /F1 10 Tf 40 792 Td (' . $esc('TAX INVOICE / BILL OF SUPPLY') . ") Tj ET\n";
        $c .= 'BT 0.15 0.2 0.28 rg /F2 11 Tf 40 735 Td (' . $esc('Bill to: Global Institute of Management & Technology, Greater Noida') . ") Tj ET\n";
        $c .= 'BT 0.3 0.35 0.42 rg /F1 11 Tf 40 712 Td (' . $esc('Bill no: ' . $billNo . '      Date: ' . $date) . ") Tj ET\n";
        $c .= "0.85 0.88 0.92 RG 1 w 40 690 515 0.5 re S\n";
        $c .= 'BT 0.15 0.2 0.28 rg /F2 12 Tf 40 668 Td (' . $esc(mb_strimwidth($particulars, 0, 80, '...')) . ") Tj ET\n";
        $y = 636;
        foreach ($lines as [$label, $value]) {
            $c .= 'BT 0.3 0.35 0.42 rg /F1 11 Tf 40 ' . $y . ' Td (' . $esc($label) . ") Tj ET\n";
            $c .= 'BT 0.15 0.2 0.28 rg /F2 11 Tf 420 ' . $y . ' Td (' . $esc($value) . ") Tj ET\n";
            $y -= 22;
        }
        $c .= "0.85 0.88 0.92 RG 1 w 40 120 515 0.5 re S\n";
        $c .= 'BT 0.45 0.5 0.58 rg /F1 9 Tf 40 100 Td (' . $esc('Demo bill generated for the GIMT SmartCampus demo data. Not a real tax invoice.') . ") Tj ET\n";
        $objs = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
            '<< /Length ' . strlen($c) . " >>\nstream\n" . $c . 'endstream',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $i => $o) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        return $pdf . 'trailer << /Size ' . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }
}

return function (array $opts): void {
    require_once __DIR__ . '/lib/demo_data.php';
    require_once APP_ROOT . '/app/services/fees.php';
    demo_seed(2227);

    $session = db_row('SELECT * FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1') ?: db_row('SELECT * FROM academic_sessions ORDER BY start_date DESC LIMIT 1');
    if (!$session) {
        return;
    }
    $sid = (int) $session['id'];
    $sname = (string) $session['name'];
    $start = (string) $session['start_date'];
    $y1 = (int) substr($start, 0, 4);
    $y2 = $y1 + 1;
    $today = max(date('Y-m-d'), date('Y-m-d', strtotime($start . ' +45 days')));
    $todayTs = strtotime($today);
    $d = fn (string $md, int $year = 0) => ($year ?: $y1) . '-' . $md;
    $addDays = fn (string $date, int $n) => date('Y-m-d', strtotime("$date $n days"));
    $days = fn (string $a, string $b) => (int) round((strtotime($b) - strtotime($a)) / 86400);
    $time = function (string $date): string {
        $stamp = strtotime($date . sprintf(' %02d:%02d:%02d', mt_rand(9, 16), mt_rand(0, 59), mt_rand(0, 59)));
        if ($date === date('Y-m-d') && $stamp > time()) {   // today's entries are never in the future
            $stamp = max(strtotime($date . ' 00:05:00'), time() - mt_rand(300, 6 * 3600));
        }
        return date('Y-m-d H:i:s', $stamp);
    };

    // ---------------------------------------------------------------- users
    $uid = fn (string $u) => (int) (db_value('SELECT id FROM users WHERE username = ?', [$u]) ?: 0);
    $adminId = $uid('admin') ?: (int) db_value('SELECT MIN(id) FROM users');
    $approverId = $uid('administrator') ?: $adminId;
    $accountsId = $uid('accounts') ?: $adminId;
    $cashierIds = array_values(array_unique(array_filter([$accountsId, $uid('rohit.bansal'), $adminId])));
    $collector = function () use ($cashierIds) {
        $w = [];
        foreach ($cashierIds as $i => $id) {
            $w[$id] = [60, 30, 10][$i] ?? 10;
        }
        return (int) demo_weighted($w);
    };

    // ---------------------------------------------------------------- clear owned rows (keep other units' postings)
    $tableExists = fn (string $t) => (bool) db_value('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]);
    $colExists = fn (string $t, string $c) => (bool) db_value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]);
    $keep = db_column("SELECT id FROM student_fees WHERE remarks LIKE 'Admission fee — %' OR remarks LIKE 'Hostel fee — %' OR remarks LIKE 'Transport allocation #%'");
    foreach (['hostel_allocations', 'transport_allocations'] as $t) {
        if ($tableExists($t) && $colExists($t, 'student_fee_id')) {
            $keep = array_merge($keep, db_column("SELECT student_fee_id FROM $t WHERE student_fee_id IS NOT NULL"));
        }
    }
    $keep = array_merge($keep, db_column('SELECT student_fee_id FROM payments WHERE admission_id IS NOT NULL AND student_fee_id IS NOT NULL'));
    $keepSql = implode(',', array_map('intval', array_unique($keep))) ?: '-1';
    $dropPay = db_column("SELECT p.id FROM payments p WHERE p.admission_id IS NULL AND NOT (COALESCE(p.student_fee_id, -1) IN ($keepSql)
                            AND NOT EXISTS (SELECT 1 FROM payment_allocations pa WHERE pa.payment_id = p.id AND pa.student_fee_id NOT IN ($keepSql)))");
    foreach (array_chunk($dropPay, 500) as $chunk) {
        $in = implode(',', array_map('intval', $chunk));
        db_exec("DELETE FROM refunds WHERE payment_id IN ($in)");
        db_exec("DELETE FROM fee_concessions WHERE payment_id IN ($in)");
        db_exec("DELETE FROM payment_allocations WHERE payment_id IN ($in)");
        db_exec("DELETE FROM payment_receipts WHERE payment_id IN ($in)");
        db_exec("DELETE FROM payments WHERE id IN ($in)");
    }
    db_exec("DELETE FROM fee_concessions WHERE student_fee_id NOT IN ($keepSql)");
    db_exec("DELETE FROM fee_installments WHERE student_fee_id NOT IN ($keepSql)");
    db_exec("DELETE FROM student_fee_items WHERE student_fee_id NOT IN ($keepSql)");
    db_exec("DELETE FROM payment_allocations WHERE student_fee_id NOT IN ($keepSql)");
    db_exec("DELETE FROM student_fees WHERE id NOT IN ($keepSql)");
    db_exec('DELETE FROM fee_structure_installments');
    db_exec('DELETE FROM fee_structure_items');
    db_exec('DELETE FROM fee_structures');
    db_exec('DELETE FROM scholarships');
    db_exec('DELETE FROM expenses');

    // ---------------------------------------------------------------- reference data
    db_exec("INSERT IGNORE INTO fee_heads (name, code, type, is_refundable, sort_order) VALUES ('Miscellaneous Fee', 'MISC', 'miscellaneous', 0, 8)");
    $headDesc = [
        'ADM' => 'One-time admission & registration charge (first semester only).',
        'TUI' => 'Semester tuition fee as approved by the fee regulatory committee.',
        'EXM' => 'University / institute examination and evaluation fee.',
        'HST' => 'Hostel accommodation & mess — billed by the Hostel module on room allocation.',
        'TRN' => 'Transport (bus pass) — billed by the Transport module on route allocation.',
        'LIB' => 'Library, digital resources and e-journal access.',
        'DEV' => 'Campus development & infrastructure fund.',
        'CAU' => 'Refundable caution deposit (adjusted at the end of the program).',
        'MISC' => 'Student activities, ID card, group insurance and alumni registration.',
    ];
    foreach ($headDesc as $code => $desc) {
        db_exec("UPDATE fee_heads SET description = ? WHERE code = ? AND (description IS NULL OR description = '')", [$desc, $code]);
    }
    $heads = db_pairs('SELECT code, id FROM fee_heads');
    $headType = db_pairs('SELECT id, type FROM fee_heads');

    $catDesc = [
        'Infrastructure & Maintenance' => [4500000, 'Civil, electrical and plumbing works, housekeeping and campus upkeep.'],
        'Salaries & Honorarium' => [38000000, 'Teaching and non-teaching payroll, guest and visiting faculty honorarium.'],
        'Utilities (Electricity, Water)' => [1800000, 'Electricity, water, DG set diesel and other utility bills.'],
        'Laboratory & Equipment' => [4000000, 'Lab equipment, consumables and classroom technology.'],
        'Library Books & Journals' => [1200000, 'Textbooks, reference books, journals and e-resource subscriptions.'],
        'Events & Activities' => [1000000, 'Fests, celebrations, guest lectures, sports and industrial visits.'],
        'Marketing & Admissions' => [1500000, 'Advertising, education fairs, prospectus and outreach.'],
        'IT & Software' => [2800000, 'Software licences, internet, hosting and IT hardware.'],
        'Transport & Fuel' => [3000000, 'Bus fleet fuel, maintenance, insurance and permits.'],
        'Office & Stationery' => [900000, 'Stationery, printing, courier and pantry supplies.'],
    ];
    foreach ($catDesc as $name => [$budget, $desc]) {
        db_exec('INSERT IGNORE INTO expense_categories (name, budget_amount) VALUES (?, ?)', [$name, $budget]);
        // Annual budgets sized to the session's demo spend (re-applied on every run so utilisation stays realistic).
        db_exec('UPDATE expense_categories SET budget_amount = ? WHERE name = ?', [$budget, $name]);
        db_exec("UPDATE expense_categories SET description = ? WHERE name = ? AND (description IS NULL OR description = '')", [$desc, $name]);
    }
    $cats = db_pairs('SELECT name, id FROM expense_categories');

    // ---------------------------------------------------------------- fee structures
    $programs = db_all("SELECT id, code, short_name, name, level, total_semesters, fee_per_year FROM programs WHERE status = 'active' ORDER BY sort_order, id");
    $kindOf = function (array $p): string {
        if (in_array($p['level'], ['Diploma', 'Certificate'], true) || (int) $p['total_semesters'] <= 1) {
            return 'single';
        }
        if ($p['code'] === 'MBA') {
            return 'mba';
        }
        return str_starts_with($p['code'], 'BT') ? 'btech' : 'default';
    };
    $plans = [
        'default' => ['odd1' => [[60, '08-20'], [40, '10-05']], 'odd' => [[60, '07-31'], [40, '09-30']], 'even' => [[60, '01-15'], [40, '03-15']]],
        'btech' => ['odd1' => [[50, '08-20'], [50, '10-20']], 'odd' => [[50, '07-25'], [50, '10-20']], 'even' => [[50, '01-20'], [50, '04-10']]],
        'mba' => ['odd1' => [[40, '08-20'], [30, '09-30'], [30, '11-16']], 'odd' => [[40, '07-20'], [30, '09-15'], [30, '11-16']], 'even' => [[40, '01-15'], [30, '02-28'], [30, '04-15']]],
    ];
    $labels = ['1st installment', '2nd installment', '3rd installment'];
    $structures = [];   // [program_id][semester] => structure
    foreach ($programs as $p) {
        $kind = $kindOf($p);
        $semCount = max(1, (int) $p['total_semesters']);
        $semFee = (float) $p['fee_per_year'] / min(2, $semCount);
        $lvl = $p['level'];
        $exam = ['PG' => 4500, 'UG' => 3500, 'Diploma' => 1500][$lvl] ?? 1000;
        $lib = ['PG' => 2000, 'UG' => 1500, 'Diploma' => 750][$lvl] ?? 500;
        $misc = ['PG' => 1500, 'UG' => 1500, 'Diploma' => 1000][$lvl] ?? 500;
        $dev = round($semFee * 0.06 / 500) * 500;
        $tuition = $semFee - $exam - $lib - $misc - $dev;
        $lateRate = in_array($kind, ['btech', 'mba'], true) ? 100 : 50;
        for ($sem = 1; $sem <= $semCount; $sem++) {
            $even = $sem % 2 === 0;
            $items = [['TUI', $tuition, 0], ['EXM', $exam, 0], ['LIB', $lib, 0], ['DEV', $dev, 0], ['MISC', $misc, 0]];
            if ($sem === 1) {
                $items[] = ['ADM', ['PG' => 25000, 'UG' => 15000, 'Diploma' => 5000][$lvl] ?? 2000, 0];
                if (in_array($lvl, ['PG', 'UG', 'Diploma'], true)) {
                    $items[] = ['CAU', $lvl === 'Diploma' ? 5000 : 10000, 0];
                }
            }
            $items = array_values(array_filter($items, fn ($i) => isset($heads[$i[0]]) && $i[1] > 0));
            $total = array_sum(array_column($items, 1));
            $insts = [];
            if ($kind === 'single') {
                $due = $even ? $d('01-31', $y2) : $d('08-25');
            } else {
                $plan = $plans[$kind][$even ? 'even' : ($sem === 1 ? 'odd1' : 'odd')];
                foreach ($plan as $k => [$pct, $md]) {
                    $insts[] = ['installment_no' => $k + 1, 'label' => $labels[$k], 'percentage' => $pct, 'due_date' => $d($md, $even ? $y2 : $y1)];
                }
                $due = end($insts)['due_date'];
            }
            $name = sprintf('%s Semester %d Fee %s', $p['short_name'], $sem, $sname);
            $id = db_insert('fee_structures', [
                'name' => $name, 'academic_session_id' => $sid, 'program_id' => (int) $p['id'], 'semester_no' => $sem, 'year_no' => (int) ceil($sem / 2),
                'total_amount' => $total, 'due_date' => $due, 'late_fee_per_day' => $lateRate, 'late_fee_max' => $lateRate * 30, 'installments_allowed' => max(1, count($insts)),
                'description' => $even ? 'Even semester (January) — assign after the semester promotion.' : ($sem === 1 ? 'First-year intake ' . $sname . ' — includes admission fee and refundable caution deposit.' : 'Odd semester fee for continuing students.'),
                'status' => 'active', 'created_by' => $accountsId, 'created_at' => $even ? $d('09-2' . mt_rand(1, 8)) . ' 11:30:00' : $d('06-1' . mt_rand(0, 9)) . ' 10:15:00',
            ]);
            foreach ($items as [$code, $amt, $opt]) {
                db_insert('fee_structure_items', ['fee_structure_id' => $id, 'fee_head_id' => (int) $heads[$code], 'amount' => $amt, 'is_optional' => $opt]);
            }
            foreach ($insts as $i) {
                db_insert('fee_structure_installments', $i + ['fee_structure_id' => $id]);
            }
            $structures[(int) $p['id']][$sem] = ['id' => $id, 'items' => $items, 'insts' => $insts, 'due' => $due, 'total' => $total, 'late' => $lateRate,
                'title' => sprintf('%s · Semester %d Fee %s', $p['short_name'], $sem, $sname)];
        }
    }

    // ---------------------------------------------------------------- scholarship schemes
    $schemes = [
        ['Merit Scholarship — 90% & above', 'MERIT90', 'percentage', 50, 'merit', 'Students scoring 90% or above in the qualifying examination (Class XII / graduation). Renewed every semester on SGPA ≥ 8.5 with no backlogs.', 60000, 'active', 14],
        ['Merit Scholarship — 80% to 89.9%', 'MERIT80', 'percentage', 25, 'merit', 'Students scoring 80–89.9% in the qualifying examination. Renewed every semester on SGPA ≥ 8.0.', 35000, 'active', 24],
        ['Sports Excellence Scholarship', 'SPORTS', 'percentage', 30, 'sports', 'State / national level representation in recognised sports during the last two years, verified by the sports committee.', 40000, 'active', 8],
        ['Sibling Concession', 'SIBLING', 'percentage', 10, 'sibling', 'Second child of the same parents studying at GIMT; applicable on the tuition fee of the younger sibling.', 20000, 'active', 12],
        ['Staff Ward Fee Waiver', 'STAFFWARD', 'percentage', 50, 'staff_ward', 'Children of permanent GIMT employees with at least two years of continuous service.', null, 'active', 4],
        ['Single Girl Child Scholarship', 'SGC', 'percentage', 15, 'scholarship', 'Single girl child of the family; affidavit and family declaration required at admission.', 25000, 'active', 10],
        ['EWS Fee Support', 'EWS', 'fixed', 20000, 'waiver', 'Annual family income below ₹2.5 lakh (income certificate issued by the Tehsildar, renewed every year).', null, 'active', 6],
        ['Defence & Paramilitary Ward Concession', 'DEFENCE', 'percentage', 20, 'scholarship', 'Wards of serving / retired personnel of the armed forces and CAPF (service certificate required).', 30000, 'active', 6],
        ['Early Bird Admission Discount', 'EARLYBIRD', 'fixed', 5000, 'discount', 'Admissions confirmed with the full first-semester fee before 31 May. Closed for the current intake.', null, 'inactive', 0],
    ];
    $schemeRows = [];
    foreach ($schemes as [$n, $c, $type, $val, $cat, $crit, $max, $status, $awards]) {
        $id = db_insert('scholarships', ['name' => $n, 'code' => $c, 'type' => $type, 'value' => $val, 'category' => $cat, 'criteria' => $crit, 'max_amount' => $max, 'status' => $status, 'created_at' => $d('06-05') . ' 10:00:00']);
        $schemeRows[] = ['id' => $id, 'code' => $c, 'type' => $type, 'value' => (float) $val, 'max' => $max, 'awards' => $awards, 'name' => $n];
    }

    // ---------------------------------------------------------------- invoices
    $students = db_all("SELECT id, first_name, last_name, gender, program_id, current_semester, admission_date FROM students WHERE status = 'active' ORDER BY id");
    $existingInv = array_flip(db_column('SELECT invoice_no FROM student_fees'));
    $invPattern = (string) setting('invoice_prefix', 'INV/{session}/{n:6}');
    $fmtNo = function (string $pattern, int $n) use ($sname): string {
        return preg_replace_callback('/\{(Y|y|m|session|n(?::(\d+))?)\}/', fn ($m) => match ($m[1]) {
            'Y' => date('Y'), 'y' => date('y'), 'm' => date('m'), 'session' => $sname, default => str_pad((string) $n, (int) ($m[2] ?? 1), '0', STR_PAD_LEFT),
        }, $pattern);
    };
    $invSeq = 0;
    $nextInv = function () use (&$invSeq, $fmtNo, $invPattern, $existingInv) {
        do {
            $no = $fmtNo($invPattern, ++$invSeq);
        } while (isset($existingInv[$no]));
        return $no;
    };

    // Scholarship / discount assignment per student (one scheme at most)
    $pool = array_keys($students);
    shuffle($pool);
    $award = [];
    foreach ($schemeRows as $sch) {
        $n = $sch['awards'];
        foreach ($pool as $k => $idx) {
            if ($n <= 0) {
                break;
            }
            if ($sch['code'] === 'SGC' && $students[$idx]['gender'] !== 'female') {
                continue;
            }
            $award[$idx] = ['scheme' => $sch];
            unset($pool[$k]);
            $n--;
        }
    }
    $discountReasons = [
        ['discount', 'Financial hardship — approved by the Director (file note FN/ACC/' . $y1 . '/%d)'],
        ['discount', 'Alumni ward concession — parent is a GIMT alumnus'],
        ['discount', 'Management discretion — single-parent family (approved by the Registrar)'],
        ['discount', 'Ex-serviceman ward concession on tuition'],
        ['waiver', 'Examination fee waived — department topper of the previous semester'],
    ];
    foreach (array_slice($pool, 0, 30) as $k => $idx) {
        $award[$idx] = ['discount' => $discountReasons[$k % count($discountReasons)]];
    }

    $invoices = [];
    foreach ($students as $idx => $s) {
        $st = $structures[(int) $s['program_id']][(int) $s['current_semester']] ?? null;
        if (!$st) {
            continue;
        }
        $gross = (float) $st['total'];
        $tuition = 0.0;
        $examFee = 0.0;
        foreach ($st['items'] as [$code, $amt]) {
            $tuition += $code === 'TUI' ? $amt : 0;
            $examFee += $code === 'EXM' ? $amt : 0;
        }
        $created = max($start, (string) ($s['admission_date'] ?: $start));
        $conc = [];
        if (isset($award[$idx]['scheme'])) {
            $sch = $award[$idx]['scheme'];
            $amt = $sch['type'] === 'fixed' ? $sch['value'] : round($tuition * $sch['value'] / 100);
            if ($sch['max'] !== null) {
                $amt = min($amt, (float) $sch['max']);
            }
            $conc[] = ['type' => 'scholarship', 'scholarship_id' => $sch['id'], 'amount' => $amt, 'reason' => $sch['name'] . ' — ' . $sname, 'created_at' => $time($addDays($created, mt_rand(2, 12)))];
        } elseif (isset($award[$idx]['discount'])) {
            [$type, $reason] = $award[$idx]['discount'];
            $amt = $type === 'waiver' ? $examFee : (float) (mt_rand(4, 20) * 500);
            $reason = sprintf($reason, mt_rand(10, 99));
            $conc[] = ['type' => $type, 'scholarship_id' => null, 'amount' => $amt, 'reason' => $reason, 'created_at' => $time($addDays($created, mt_rand(1, 20)))];
        }
        $sch = array_sum(array_map(fn ($c) => $c['type'] === 'scholarship' ? $c['amount'] : 0, $conc));
        $disc = array_sum(array_map(fn ($c) => $c['type'] !== 'scholarship' ? $c['amount'] : 0, $conc));
        $net0 = $gross - $sch - $disc;
        $insts = [];
        if ($st['insts']) {
            $left = $net0;
            $n = count($st['insts']);
            foreach ($st['insts'] as $k => $i) {
                $amt = $k === $n - 1 ? $left : floor($net0 * $i['percentage'] / 100);
                $left -= $amt;
                $insts[] = ['amount' => $amt, 'due' => $i['due_date'], 'fine' => 0.0];
            }
        } else {
            $insts[] = ['amount' => $net0, 'due' => $st['due'], 'fine' => 0.0];
        }
        $invoices[] = [
            'student' => $s, 'st' => $st, 'invoice_no' => $nextInv(), 'gross' => $gross, 'sch' => $sch, 'disc' => $disc, 'conc' => $conc,
            'insts' => $insts, 'has_plan' => (bool) $st['insts'], 'payments' => [], 'fine' => 0.0, 'late_upto' => null,
            'created_at' => $created . ' 10:00:00', 'min_pay' => max($start, (string) ($s['admission_date'] ?: $start)),
        ];
    }

    // ---------------------------------------------------------------- payment simulation
    $modeFor = function (float $amount) {
        $m = demo_weighted(['upi' => 34, 'bank_transfer' => 20, 'cash' => 16, 'online' => 14, 'cheque' => 8, 'card' => 6, 'dd' => 2]);
        return $m === 'cash' && $amount >= 200000 ? 'bank_transfer' : $m;
    };
    $payOn = function (array $inst, array &$inv, string $date, ?float $amount = null, bool $allowFine = true) use ($todayTs, $days, $modeFor) {
        $date = max($date, $inv['min_pay']);
        if (strtotime($date) > $todayTs) {
            return false;
        }
        $fine = 0.0;
        if ($allowFine && $date > $inst['due'] && mt_rand(1, 100) <= 70) {
            $fine = (float) min($days($inst['due'], $date) * $inv['st']['late'], $inv['st']['late'] * 30);
        }
        $amount = $amount ?? $inst['amount'];
        $inv['payments'][] = ['date' => $date, 'amount' => $amount, 'fine' => $fine, 'mode' => $modeFor($amount + $fine), 'status' => 'success'];
        return $fine;
    };
    foreach ($invoices as &$inv) {
        $profile = demo_weighted(['full' => 58, 'first' => 13, 'partial' => 9, 'none' => 8, 'advance' => 12]);
        if (!$inv['has_plan'] && $profile === 'first') {
            $profile = mt_rand(0, 1) ? 'full' : 'partial';
        }
        $inv['profile'] = $profile;
        foreach ($inv['insts'] as $k => &$inst) {
            $due = $inst['due'];
            $onTime = mt_rand(1, 100) <= 74;
            $date = $onTime ? date('Y-m-d', strtotime("$due -" . mt_rand(0, 18) . ' days')) : date('Y-m-d', strtotime("$due +" . mt_rand(1, 24) . ' days'));
            if ($profile === 'none') {
                break;
            }
            if ($profile === 'partial') {
                $part = max(1000, round($inst['amount'] * mt_rand(30, 70) / 100 / 1000) * 1000);
                $payOn($inst, $inv, $date, min($part, $inst['amount'] - 1000), false);
                break;
            }
            if ($k > 0 && $profile === 'first') {
                break;
            }
            if (strtotime($due) > $todayTs) {
                if ($profile !== 'advance' && !($profile === 'full' && mt_rand(1, 100) <= 30)) {
                    break;
                }
                $date = date('Y-m-d', min($todayTs - mt_rand(0, 24) * 86400, strtotime("$due -" . mt_rand(10, 40) . ' days')));
            }
            $lastDate = $inv['payments'] ? end($inv['payments'])['date'] : $inv['min_pay'];
            $fine = $payOn($inst, $inv, max($date, $lastDate));
            if ($fine === false) {
                break;
            }
            if ($fine > 0) {
                $inst['fine'] += $fine;
                $inv['fine'] += $fine;
                $inv['late_upto'] = end($inv['payments'])['date'];
            }
        }
        unset($inst);
    }
    unset($inv);

    // Walk-ins at the counter today: students clearing an overdue installment.
    $walkIns = 0;
    foreach ($invoices as &$inv) {
        if ($walkIns >= 12 || !in_array($inv['profile'], ['first', 'none'], true) || mt_rand(1, 100) > 12) {
            continue;
        }
        $paidCount = count($inv['payments']);
        $inst = $inv['insts'][$paidCount] ?? null;
        if (!$inst || $inst['due'] >= $today) {
            continue;
        }
        $fine = (float) min($days($inst['due'], $today) * $inv['st']['late'], $inv['st']['late'] * 30);
        $inv['insts'][$paidCount]['fine'] += $fine;
        $inv['fine'] += $fine;
        $inv['late_upto'] = $today;
        $inv['payments'][] = ['date' => $today, 'amount' => $inst['amount'], 'fine' => $fine, 'mode' => demo_weighted(['cash' => 40, 'upi' => 35, 'card' => 15, 'cheque' => 10]), 'status' => 'success'];
        $walkIns++;
    }
    unset($inv);

    // Dishonoured cheques (voided receipts) on a few unpaid / partly paid invoices.
    $bounced = 0;
    foreach ($invoices as &$inv) {
        if ($bounced >= 5 || !in_array($inv['profile'], ['none', 'partial'], true) || mt_rand(1, 100) > 10) {
            continue;
        }
        $inst = $inv['insts'][0];
        $date = max($inv['min_pay'], date('Y-m-d', strtotime($inst['due'] . ' -' . mt_rand(1, 6) . ' days')));
        if ($date > $today) {
            continue;
        }
        $inv['payments'][] = ['date' => $date, 'amount' => $inst['amount'], 'fine' => 0.0, 'mode' => 'cheque', 'status' => 'cancelled'];
        $bounced++;
    }
    unset($inv);

    // ---------------------------------------------------------------- write invoices, items, installments, concessions
    $banks = ['State Bank of India', 'Punjab National Bank', 'HDFC Bank', 'ICICI Bank', 'Bank of Baroda', 'Canara Bank', 'Axis Bank', 'Union Bank of India', 'Kotak Mahindra Bank'];
    $ifsc = ['SBIN', 'PUNB', 'HDFC', 'ICIC', 'BARB', 'CNRB', 'UTIB', 'UBIN', 'KKBK'];
    $rows = [];
    foreach ($invoices as $inv) {
        $s = $inv['student'];
        $rows[] = [
            'invoice_no' => $inv['invoice_no'], 'student_id' => (int) $s['id'], 'fee_structure_id' => $inv['st']['id'], 'academic_session_id' => $sid, 'title' => $inv['st']['title'],
            'semester_no' => (int) $s['current_semester'], 'gross_amount' => $inv['gross'], 'discount_amount' => $inv['disc'], 'scholarship_amount' => $inv['sch'], 'fine_amount' => $inv['fine'],
            'late_fee_upto' => $inv['late_upto'], 'net_amount' => $inv['gross'] - $inv['disc'] - $inv['sch'] + $inv['fine'], 'paid_amount' => 0, 'due_date' => end($inv['insts'])['due'],
            'status' => 'pending', 'created_by' => $accountsId, 'created_at' => $inv['created_at'],
        ];
    }
    demo_bulk_insert('student_fees', $rows);
    $invIds = db_pairs('SELECT invoice_no, id FROM student_fees WHERE academic_session_id = ? AND fee_structure_id IS NOT NULL', [$sid]);
    $itemRows = [];
    $instRows = [];
    $concRows = [];
    foreach ($invoices as &$inv) {
        $fid = (int) $invIds[$inv['invoice_no']];
        $inv['id'] = $fid;
        foreach ($inv['st']['items'] as [$code, $amt]) {
            $itemRows[] = ['student_fee_id' => $fid, 'fee_head_id' => (int) $heads[$code], 'amount' => $amt];
        }
        if ($inv['has_plan']) {
            foreach ($inv['insts'] as $k => $i) {
                $instRows[] = ['student_fee_id' => $fid, 'installment_no' => $k + 1, 'amount' => $i['amount'] + $i['fine'], 'due_date' => $i['due'], 'paid_amount' => 0, 'status' => 'pending'];
            }
        }
        foreach ($inv['conc'] as $c) {
            $concRows[] = ['student_fee_id' => $fid, 'student_id' => (int) $inv['student']['id'], 'scholarship_id' => $c['scholarship_id'], 'payment_id' => null, 'type' => $c['type'],
                'amount' => $c['amount'], 'reason' => $c['reason'], 'status' => 'approved', 'approved_by' => $approverId, 'created_by' => $accountsId, 'created_at' => $c['created_at']];
        }
    }
    unset($inv);
    demo_bulk_insert('student_fee_items', $itemRows);
    demo_bulk_insert('fee_installments', $instRows);
    demo_bulk_insert('fee_concessions', $concRows);

    // ---------------------------------------------------------------- payments (receipt numbers in date order)
    $all = [];
    foreach ($invoices as $k => $inv) {
        foreach ($inv['payments'] as $p) {
            $all[] = $p + ['inv' => $k];
        }
    }
    usort($all, fn ($a, $b) => [$a['date'], $a['inv']] <=> [$b['date'], $b['inv']]);
    $rcptPattern = (string) setting('receipt_prefix', 'RCPT/{session}/{n:6}');
    $existingRcpt = array_flip(db_column('SELECT receipt_no FROM payments'));
    $rcptSeq = 0;
    $payRows = [];
    $meta = [];
    foreach ($all as $p) {
        do {
            $no = $fmtNo($rcptPattern, ++$rcptSeq);
        } while (isset($existingRcpt[$no]));
        $inv = $invoices[$p['inv']];
        $mode = $p['mode'];
        $b = mt_rand(0, count($banks) - 1);
        $ref = match ($mode) {
            'upi' => (string) mt_rand(4000, 4999) . str_pad((string) mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT),
            'bank_transfer' => $ifsc[$b] . 'N' . date('ymd', strtotime($p['date'])) . str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT),
            'cheque', 'dd' => (string) mt_rand(100000, 999999),
            'card' => 'POS' . mt_rand(10000000, 99999999),
            'online' => 'pay_' . substr(strtoupper(md5($no . $p['date'])), 0, 14),
            default => null,
        };
        $created = $time($p['date']);
        $payRows[] = [
            'receipt_no' => $no, 'student_id' => (int) $inv['student']['id'], 'admission_id' => null, 'student_fee_id' => $inv['id'], 'amount' => $p['amount'], 'fine_amount' => $p['fine'],
            'discount_amount' => 0, 'payment_date' => $p['date'], 'mode' => $mode, 'reference_no' => $ref, 'bank_name' => in_array($mode, ['cheque', 'dd'], true) ? $banks[$b] : null,
            'gateway' => $mode === 'online' ? 'razorpay' : null, 'gateway_txn_id' => $mode === 'online' ? $ref : null, 'purpose' => 'fee', 'status' => $p['status'],
            'remarks' => $p['status'] === 'cancelled' ? 'Cheque deposited in ' . $banks[$b] . ' clearing' : ($p['fine'] > 0 ? 'Late fee of ₹' . number_format($p['fine']) . ' collected' : null),
            'collected_by' => $mode === 'online' ? null : $collector(), 'cancelled_by' => $p['status'] === 'cancelled' ? $accountsId : null,
            'cancelled_reason' => $p['status'] === 'cancelled' ? 'Cheque returned unpaid by the bank — "Funds insufficient". Student informed to pay by UPI / cash.' : null,
            'cancelled_at' => $p['status'] === 'cancelled' ? $time(min($today, $addDays($p['date'], mt_rand(3, 6)))) : null,
            'created_at' => $created,
        ];
        $meta[$no] = ['inv' => $p['inv'], 'created' => $created, 'total' => $p['amount'] + $p['fine'], 'fine' => $p['fine'], 'mode' => $mode, 'status' => $p['status'], 'date' => $p['date']];
    }
    demo_bulk_insert('payments', $payRows);
    $payIds = db_pairs('SELECT receipt_no, id FROM payments WHERE admission_id IS NULL');
    $allocRows = [];
    $rcptRows = [];
    foreach ($meta as $no => $m) {
        $pid = (int) $payIds[$no];
        $meta[$no]['id'] = $pid;
        $allocRows[] = ['payment_id' => $pid, 'student_fee_id' => $invoices[$m['inv']]['id'], 'amount' => $m['total'], 'fine_amount' => $m['fine'], 'created_at' => $m['created']];
        $printed = mt_rand(0, 2);
        $rcptRows[] = ['payment_id' => $pid, 'receipt_no' => $no, 'issued_at' => $m['created'], 'printed_count' => $printed, 'last_printed_at' => $printed ? $m['created'] : null,
            'emailed_at' => in_array($m['mode'], ['online', 'upi'], true) && mt_rand(0, 1) ? $m['created'] : null];
    }
    demo_bulk_insert('payment_allocations', $allocRows);
    demo_bulk_insert('payment_receipts', $rcptRows);

    // ---------------------------------------------------------------- refunds (every workflow state)
    $candidates = array_values(array_filter($meta, fn ($m) => $m['status'] === 'success' && $m['total'] >= 15000 && $m['date'] <= $addDays($today, -12)));
    shuffle($candidates);
    $refundPlan = [
        ['processed', 'Excess amount paid — installment paid twice (UPI and cash on the same day).'],
        ['processed', 'Scholarship sanctioned after the fee was paid — refund of the tuition difference.'],
        ['processed', 'Duplicate online payment — gateway debited twice for the same order.'],
        ['approved', 'Program transfer from B.Com (Hons) to BBA — refund of the excess fee paid.'],
        ['approved', 'Withdrawal from the optional certification add-on within the cooling-off period.'],
        ['pending', 'Excess late fee charged — payment was made on the due date at the bank.'],
        ['pending', 'Duplicate payment through UPI — second transaction to be refunded.'],
        ['rejected', 'Request for refund of caution deposit mid-program.'],
    ];
    $rfdSeq = 0;
    $refundRows = [];
    foreach ($refundPlan as $k => [$status, $reason]) {
        $m = $candidates[$k] ?? null;
        if (!$m) {
            break;
        }
        $inv = $invoices[$m['inv']];
        $amount = (float) min($m['total'], mt_rand(4, 16) * 500);
        $requested = $status === 'pending' ? $time($addDays($today, -mt_rand(0, 4))) : ($status === 'approved' ? $time($addDays($today, -mt_rand(5, 9))) : $time($addDays($m['date'], mt_rand(4, 10))));
        $approvedAt = in_array($status, ['approved', 'processed', 'rejected'], true) ? $time(min($today, $addDays(substr($requested, 0, 10), mt_rand(1, 3)))) : null;
        $processedAt = $status === 'processed' ? $time(min($today, $addDays(substr((string) $approvedAt, 0, 10), mt_rand(1, 4)))) : null;
        $b = mt_rand(0, count($ifsc) - 1);
        $refundRows[] = [
            'refund_no' => 'RFD/' . $sname . '/' . str_pad((string) ++$rfdSeq, 4, '0', STR_PAD_LEFT), 'payment_id' => $m['id'], 'student_id' => (int) $inv['student']['id'], 'amount' => $amount, 'reason' => $reason,
            'refund_date' => $processedAt ? substr($processedAt, 0, 10) : null, 'mode' => $processedAt ? 'bank_transfer' : null,
            'reference_no' => $processedAt ? $ifsc[$b] . 'N' . date('ymd', strtotime($processedAt)) . mt_rand(100000, 999999) : null,
            'remarks' => $status === 'rejected' ? 'Not eligible — the caution deposit is refundable only on completion of the program or on withdrawal.' : ($status === 'approved' ? 'Verified with the bank statement. Release through NEFT.' : null),
            'status' => $status, 'requested_by' => $accountsId, 'approved_by' => $approvedAt ? $approverId : null, 'approved_at' => $approvedAt, 'processed_by' => $processedAt ? $accountsId : null,
            'processed_at' => $processedAt, 'created_at' => $requested,
        ];
    }
    usort($refundRows, fn ($a, $b) => strcmp($a['created_at'], $b['created_at']));
    foreach ($refundRows as $i => &$r) {
        $r['refund_no'] = 'RFD/' . $sname . '/' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
    }
    unset($r);
    demo_bulk_insert('refunds', $refundRows);

    // ---------------------------------------------------------------- balances & statuses (service is the source of truth)
    foreach ($invoices as $inv) {
        fees_refresh_invoice((int) $inv['id']);
    }
    // Reminder history on overdue invoices
    $overdue = db_all("SELECT t.id, COALESCE((SELECT MIN(fi.due_date) FROM fee_installments fi WHERE fi.student_fee_id = t.id AND fi.paid_amount < fi.amount AND fi.due_date < ?), t.due_date) AS since
                       FROM student_fees t WHERE t.status = 'overdue' AND t.academic_session_id = ?", [$today, $sid]);
    foreach ($overdue as $o) {
        if (mt_rand(1, 100) > 45) {
            continue;
        }
        $from = $addDays((string) $o['since'], 3);
        $at = $from < $today ? demo_datetime($from . ' 10:00:00', $today . ' 18:00:00') : $today . ' 11:00:00';
        db_exec('UPDATE student_fees SET reminder_count = ?, last_reminder_at = ? WHERE id = ?', [mt_rand(1, 3), $at, (int) $o['id']]);
    }

    // ---------------------------------------------------------------- expenses
    $depts = db_pairs('SELECT name, id FROM departments');
    $dept = function (string $like) use ($depts) {
        foreach ($depts as $n => $id) {
            if (stripos($n, $like) !== false) {
                return (int) $id;
            }
        }
        return null;
    };
    $exp = [];
    $add = function (string $cat, string $title, ?string $vendor, float $amount, float $gstPct, string $date, ?int $deptId = null, ?string $desc = null) use (&$exp, $cats, $today) {
        if ($date > $today || !isset($cats[$cat])) {
            return;
        }
        $amount = round($amount / 10) * 10;
        $exp[] = ['cat' => (int) $cats[$cat], 'title' => $title, 'vendor' => $vendor, 'amount' => $amount, 'tax' => round($amount * $gstPct / 100, 2), 'date' => $date, 'dept' => $deptId, 'desc' => $desc];
    };
    $rand = fn (int $a, int $b) => (float) mt_rand($a, $b);
    $months = [];
    for ($t = strtotime($start); $t <= $todayTs; $t = strtotime('+1 month', $t)) {
        $months[] = date('Y-m', $t);
    }
    foreach ($months as $ym) {
        $mon = date('F Y', strtotime("$ym-01"));
        $next = date('Y-m', strtotime("$ym-01 +1 month"));
        $add('Salaries & Honorarium', "Teaching staff salary — $mon", 'Payroll (bank advice to SBI)', $rand(2210000, 2320000), 0, "$ym-28", null, 'Net salary credited to 52 faculty accounts; statutory deductions remitted separately.');
        $add('Salaries & Honorarium', "Non-teaching staff salary — $mon", 'Payroll (bank advice to SBI)', $rand(680000, 735000), 0, "$ym-28");
        $add('Salaries & Honorarium', "Guest & visiting faculty honorarium — $mon", 'Visiting faculty (12 claims)', $rand(85000, 140000), 0, "$next-03");
        $add('Utilities (Electricity, Water)', "Electricity bill — $mon (UPPCL, 2 connections)", 'UPPCL', $rand(135000, 185000), 0, "$next-10");
        $add('Utilities (Electricity, Water)', "Diesel for DG sets — $mon", 'Bharat Fuel Station, Knowledge Park', $rand(35000, 62000), 0, "$ym-20");
        $add('IT & Software', "Internet leased line 500 Mbps — $mon", 'Airtel Business', 42000, 18, "$ym-05");
        $add('Infrastructure & Maintenance', "Housekeeping & facility services — $mon", 'Shree Balaji Facility Services', $rand(118000, 126000), 18, "$next-01");
        $add('Infrastructure & Maintenance', "Campus security services — $mon", 'Raksha Security Services', $rand(96000, 99000), 18, "$next-01");
        $add('Office & Stationery', "Stationery & consumables — $mon", 'Modern Stationers', $rand(14000, 26000), 12, "$ym-1" . mt_rand(0, 8));
        $add('Office & Stationery', "Pantry & tea supplies — $mon", 'Annapurna Caterers', $rand(15000, 24000), 5, "$ym-2" . mt_rand(0, 5));
        $add('Office & Stationery', "Courier & speed post — $mon", 'Blue Express Couriers', $rand(5500, 11000), 18, "$ym-2" . mt_rand(0, 6));
        $add('Library Books & Journals', "Newspapers & magazines — $mon", 'Gupta News Agency', $rand(3800, 5200), 0, "$ym-0" . mt_rand(3, 7));
        $add('Infrastructure & Maintenance', "Minor repairs & petty maintenance — $mon", 'Petty cash (multiple vouchers)', $rand(18000, 35000), 0, "$ym-2" . mt_rand(2, 7));
        $add('Transport & Fuel', "Bus minor repairs & lubricants — $mon", 'Shiv Shakti Motors', $rand(12000, 26000), 28, "$ym-1" . mt_rand(2, 9));
        $add('Events & Activities', "Club activities & student council — $mon", 'Student council (vouchers)', $rand(10000, 30000), 0, "$ym-2" . mt_rand(0, 6));
        $add('Marketing & Admissions', "Social media management — $mon", 'Digital Reach Media', 35000, 18, "$ym-0" . mt_rand(2, 6));
        $add('IT & Software', "Cloud & SaaS subscriptions — $mon", 'Various (corporate card)', $rand(18000, 30000), 18, "$ym-0" . mt_rand(6, 9));
        $add('Utilities (Electricity, Water)', "Drinking water (RO cans & AMC) — $mon", 'Aqua Pure Services', $rand(8000, 14000), 18, "$ym-1" . mt_rand(0, 5));
        foreach ([['Computer Science', 'CSE labs'], ['Computer Applications', 'BCA / MCA labs'], ['Sciences', 'Data science lab']] as [$dn, $lab]) {
            $add('Laboratory & Equipment', "Lab consumables — $lab ($mon)", 'Om Electronics & Computers', $rand(9000, 24000), 18, "$ym-1" . mt_rand(1, 9), $dept($dn));
        }
    }
    for ($t = strtotime('monday', strtotime($start)); $t <= $todayTs; $t = strtotime('+1 week', $t)) {
        $add('Transport & Fuel', 'Bus fleet diesel — week of ' . date('d M', $t), 'IOCL fuel card (12 buses)', $rand(36000, 52000), 0, date('Y-m-d', $t + 4 * 86400));
        if ((int) date('W', $t) % 2 === 0) {
            $add('Office & Stationery', 'Photocopy & printing — exam cell (fortnight to ' . date('d M', $t + 11 * 86400) . ')', 'Shubham Printers', $rand(6000, 12000), 12, date('Y-m-d', $t + 11 * 86400));
        }
    }
    $add('Utilities (Electricity, Water)', 'Water & sewerage charges — Q2 (Jul–Sep)', 'Greater Noida Authority', $rand(42000, 58000), 0, $d('10-08'));
    $oneOffs = [
        ['Infrastructure & Maintenance', 'Waterproofing of library terrace', 'Shree Ganesh Constructions', 120000, 280000, 18, '07-18', null],
        ['Infrastructure & Maintenance', 'CCTV camera replacement (12 units) — main gate & parking', 'SecureVision Systems', 45000, 90000, 18, '08-06', null],
        ['Infrastructure & Maintenance', 'AC servicing — seminar halls & auditorium', 'CoolTech Services', 22000, 48000, 18, '07-11', null],
        ['Infrastructure & Maintenance', 'Electrical rewiring — Lab block, 2nd floor', 'Kumar Electricals', 60000, 150000, 18, '09-02', null],
        ['Infrastructure & Maintenance', 'Painting of hostel corridors (Block A)', 'Rangoli Painters & Contractors', 80000, 160000, 18, '07-08', null],
        ['Infrastructure & Maintenance', 'Plumbing repairs — girls hostel washrooms', 'Jai Hind Plumbing Works', 15000, 40000, 18, '08-22', null],
        ['Infrastructure & Maintenance', 'Classroom furniture repair — Block B', 'Sharma Furniture Works', 18000, 65000, 18, '09-19', null],
        ['Infrastructure & Maintenance', 'Garden & landscaping — monsoon planting', 'Green Leaf Nursery', 18000, 35000, 5, '07-27', null],
        ['Infrastructure & Maintenance', 'Lift annual maintenance contract', 'Elevate Lifts & Escalators', 85000, 110000, 18, '09-26', null],
        ['Laboratory & Equipment', 'Desktop computers for CSE Lab 3 (30 units)', 'Techno Solutions Pvt Ltd', 950000, 1150000, 18, '07-22', 'Computer Science'],
        ['Laboratory & Equipment', 'Networking lab — managed switches & routers', 'NetEdge Systems', 180000, 260000, 18, '08-12', 'Computer Science'],
        ['Laboratory & Equipment', 'GPU workstation for the data science lab', 'Compuage Infocom', 280000, 340000, 18, '09-09', 'Sciences'],
        ['Laboratory & Equipment', 'Projector replacement — 4 classrooms', 'Visual Edge Technologies', 110000, 160000, 18, '07-15', null],
        ['Laboratory & Equipment', 'UPS batteries replacement — server room', 'PowerSafe Systems', 55000, 85000, 28, '10-03', null],
        ['Library Books & Journals', 'Textbooks — B.Tech CSE (Semesters 3 & 5)', 'Pragati Book Centre', 85000, 160000, 0, '07-29', 'Computer Science'],
        ['Library Books & Journals', 'Management case studies & reference books', 'Universal Book Depot', 40000, 90000, 0, '08-08', 'Management'],
        ['Library Books & Journals', 'IEEE Xplore digital library — annual subscription', 'Informatics India Pvt Ltd', 240000, 310000, 18, '07-10', null],
        ['Library Books & Journals', 'DELNET institutional membership renewal', 'DELNET', 15000, 25000, 18, '08-01', null],
        ['Library Books & Journals', 'Commerce & accounting reference books', 'Kitab Mahal Distributors', 35000, 60000, 0, '09-14', 'Commerce'],
        ['Events & Activities', "Freshers' welcome " . $y1 . ' — stage, sound & lighting', 'Dhwani Events & Sound', 120000, 180000, 18, '08-29', null],
        ['Events & Activities', 'Independence Day celebration', 'Local vendors (5 bills)', 18000, 30000, 0, '08-15', null],
        ['Events & Activities', "Teachers' Day celebration & lunch", 'Annapurna Caterers', 25000, 40000, 5, '09-05', null],
        ['Events & Activities', 'Techno-Vision ' . $y1 . ' tech fest — prizes, trophies & certificates', 'Champion Trophies & Gifts', 150000, 220000, 18, '09-27', null],
        ['Events & Activities', 'Guest lecture — "AI in Industry 4.0" (honorarium & travel)', 'Dr. Ramesh Chandra (industry expert)', 15000, 25000, 0, '08-19', 'Computer Science'],
        ['Events & Activities', 'Guest lecture — "Start-up funding in India" (honorarium)', 'Ms. Priyanka Sethi (VC associate)', 12000, 20000, 0, '09-23', 'Management'],
        ['Events & Activities', 'Sports equipment for the inter-department meet', 'Khel Sports House', 45000, 80000, 12, '09-11', null],
        ['Events & Activities', 'Industrial visit — bus hire (MBA, Manesar plant)', 'Yatra Tours & Travels', 28000, 45000, 5, '10-06', 'Management'],
        ['Marketing & Admissions', 'Search & social ads — Admissions ' . $sname . ' campaign', 'Digital Reach Media', 180000, 320000, 18, '07-05', null],
        ['Marketing & Admissions', 'Print advertisement — Hindi daily (NCR edition)', 'Aadarsh Advertising Agency', 95000, 140000, 5, '07-12', null],
        ['Marketing & Admissions', 'Education fair stall — Lucknow', 'EduExpo India', 45000, 70000, 18, '07-25', null],
        ['Marketing & Admissions', 'Prospectus & brochure printing (5,000 copies)', 'Shubham Printers', 85000, 130000, 12, '07-03', null],
        ['Marketing & Admissions', 'Hoardings — Noida Expressway (1 month)', 'Outdoor Ads Co.', 120000, 180000, 18, '07-01', null],
        ['Marketing & Admissions', 'School outreach — counselling team travel', 'Staff reimbursement (4 claims)', 8000, 18000, 0, '08-28', null],
        ['IT & Software', 'Microsoft 365 A3 licences (annual)', 'Softline Solutions', 320000, 380000, 18, '07-07', null],
        ['IT & Software', 'Firewall AMC & licence renewal', 'CyberShield Networks', 110000, 150000, 18, '08-18', null],
        ['IT & Software', 'Website hosting & domain renewal', 'CloudNest Hosting', 18000, 32000, 18, '09-01', null],
        ['IT & Software', 'SMS gateway credits (1 lakh SMS)', 'MsgPoint Communications', 18000, 25000, 18, '07-16', null],
        ['IT & Software', 'Laptops for newly joined faculty (8 units)', 'Techno Solutions Pvt Ltd', 420000, 510000, 18, '08-25', null],
        ['IT & Software', 'Biometric attendance devices (6 units)', 'BioTime Systems', 54000, 78000, 18, '09-16', null],
        ['Transport & Fuel', 'Bus servicing & new tyres — UP16 AT 4521', 'Shiv Shakti Motors', 38000, 72000, 28, '08-04', null],
        ['Transport & Fuel', 'Vehicle insurance renewal (4 buses)', 'Bharat General Insurance Agency', 160000, 210000, 18, '07-21', null],
        ['Transport & Fuel', 'Driver uniforms & safety kits', 'Shree Ram Garments', 12000, 20000, 5, '07-14', null],
        ['Transport & Fuel', 'Fitness certificate & permit renewal (3 buses)', 'RTO Greater Noida', 18000, 30000, 0, '09-08', null],
        ['Transport & Fuel', 'GPS tracking subscription (12 buses)', 'TrackSure Telematics', 24000, 36000, 18, '08-11', null],
        ['Transport & Fuel', 'Bus servicing & brake overhaul — UP16 BT 7710', 'Shiv Shakti Motors', 32000, 58000, 28, '10-01', null],
        ['Office & Stationery', 'Answer booklets printing (mid-semester exams)', 'Shubham Printers', 90000, 140000, 12, '09-29', null],
        ['Office & Stationery', 'Office furniture — admission cell', 'Comfort Office Furniture', 65000, 110000, 18, '07-04', null],
        ['Office & Stationery', 'Printer toner cartridges (bulk)', 'Image Plus Enterprises', 25000, 48000, 18, '08-21', null],
        ['Office & Stationery', 'ID cards & lanyards — new batch', 'Smart Card Printers', 38000, 55000, 18, '08-17', null],
        ['Infrastructure & Maintenance', 'Solar panel cleaning & inverter service', 'SunPower Energy Solutions', 22000, 38000, 18, '08-14', null],
        ['Infrastructure & Maintenance', 'Fire extinguisher refilling & safety audit', 'Agni Suraksha Services', 28000, 46000, 18, '09-21', null],
        ['Laboratory & Equipment', 'Interactive smart board for the seminar hall', 'Visual Edge Technologies', 140000, 185000, 18, '08-30', null],
        ['Library Books & Journals', 'E-books bundle — engineering & management titles', 'Informatics India Pvt Ltd', 65000, 95000, 18, '09-24', null],
        ['Events & Activities', 'Hindi Diwas & cultural evening', 'Dhwani Events & Sound', 18000, 32000, 18, '09-14', null],
        ['Marketing & Admissions', 'Alumni meet invitations & branding material', 'Shubham Printers', 22000, 38000, 12, '10-04', null],
        ['IT & Software', 'Plagiarism detection software licence (annual)', 'Academic Integrity Solutions', 85000, 120000, 18, '07-30', null],
    ];
    foreach ($oneOffs as [$cat, $title, $vendor, $min, $max, $gst, $md, $dn]) {
        $add($cat, $title, $vendor, $rand($min, $max), $gst, $d($md), $dn ? $dept($dn) : null);
    }
    usort($exp, fn ($a, $b) => [$a['date'], $a['title']] <=> [$b['date'], $b['title']]);
    $expRows = [];
    $entry = array_values(array_unique(array_filter([$accountsId, $uid('rohit.bansal')]))) ?: [$adminId];
    // Demo vendor bills (private files) — rebuilt on every run.
    $billDir = APP_ROOT . '/storage/private/expense-bills/demo';
    if (is_dir($billDir)) {
        foreach (glob($billDir . '/*.pdf') ?: [] as $old) {
            @unlink($old);
        }
    } else {
        @mkdir($billDir, 0775, true);
    }
    foreach ($exp as $i => $e) {
        $age = $days($e['date'], $today);
        $status = $age > 20 ? demo_weighted(['paid' => 86, 'approved' => 6, 'rejected' => 3, 'pending' => 5]) : demo_weighted(['pending' => 42, 'approved' => 28, 'paid' => 30]);
        if (str_contains($e['title'], 'salary') && $age >= 0) {
            $status = 'paid';
        }
        $total = $e['amount'] + $e['tax'];
        $mode = $total < 10000 ? demo_weighted(['cash' => 50, 'upi' => 50]) : ($total >= 200000 ? 'bank_transfer' : demo_weighted(['bank_transfer' => 62, 'cheque' => 18, 'upi' => 12, 'card' => 8]));
        $approvedAt = in_array($status, ['approved', 'paid', 'rejected'], true) ? $time(min($today, $addDays($e['date'], mt_rand(0, 3)))) : null;
        $paidAt = $status === 'paid' ? $time(min($today, $addDays(substr((string) $approvedAt, 0, 10), mt_rand(0, 5)))) : null;
        $b = mt_rand(0, count($ifsc) - 1);
        $payRef = $paidAt && $mode !== 'cash' ? match ($mode) {
            'cheque' => (string) mt_rand(200000, 299999), 'upi' => (string) mt_rand(4000, 4999) . mt_rand(10000000, 99999999), 'card' => 'CORP-' . mt_rand(100000, 999999),
            default => $ifsc[$b] . 'N' . date('ymd', strtotime($paidAt)) . mt_rand(100000, 999999),
        } : null;
        $expenseNo = 'EXP/' . $sname . '/' . str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT);
        $billNo = str_contains((string) $e['vendor'], 'Payroll') ? 'PAY/' . date('Y/m', strtotime($e['date'])) : 'BILL-' . date('ym', strtotime($e['date'])) . '-' . mt_rand(1000, 9999);
        // Vendor bills are attached to most supplier expenses (payroll, petty cash and voucher claims carry none; every 4th is left for the office to upload).
        $attachment = null;
        if ($i % 4 !== 3 && !preg_match('/Payroll|Petty cash|vouchers|reimbursement|claims|Visiting faculty/i', (string) $e['vendor']) && is_dir($billDir)) {
            $attachment = 'storage/private/expense-bills/demo/' . str_replace('/', '-', $expenseNo) . '.pdf';
            $pdf = fees_demo_bill_pdf((string) $e['vendor'], $billNo, date('d M Y', strtotime($e['date'])), $e['title'], [
                ['Taxable value', 'Rs ' . number_format($e['amount'], 2)],
                ['GST', 'Rs ' . number_format($e['tax'], 2)],
                ['Total payable', 'Rs ' . number_format($e['amount'] + $e['tax'], 2)],
            ]);
            if (@file_put_contents(APP_ROOT . '/' . $attachment, $pdf) === false) {
                $attachment = null;
            }
        }
        $expRows[] = [
            'expense_no' => $expenseNo, 'category_id' => $e['cat'], 'title' => $e['title'], 'amount' => $e['amount'], 'tax_amount' => $e['tax'],
            'expense_date' => $e['date'], 'payment_mode' => $mode, 'payment_reference' => $payRef, 'vendor' => $e['vendor'],
            'reference_no' => $billNo,
            'department_id' => $e['dept'], 'attachment' => $attachment, 'description' => $e['desc'], 'status' => $status,
            'remarks' => $status === 'rejected' ? demo_pick(['Duplicate of an earlier bill — already paid.', 'Quotation comparison missing; resubmit with three quotes.', 'Not budgeted for this quarter. Defer to January.']) : ($status === 'approved' ? 'Approved. Release payment after delivery confirmation.' : null),
            'approved_by' => $approvedAt ? $approverId : null, 'approved_at' => $approvedAt, 'paid_at' => $paidAt, 'created_by' => $entry[$i % count($entry)], 'created_at' => $time($e['date']),
        ];
    }
    demo_bulk_insert('expenses', $expRows);

    // ---------------------------------------------------------------- document sequences continue after the demo numbers
    $maxSuffix = function (string $sql, int $floor): int {
        foreach (db_column($sql) as $no) {
            if (preg_match('/(\d+)$/', (string) $no, $mm)) {
                $floor = max($floor, (int) $mm[1]);
            }
        }
        return $floor;
    };
    $seqs = [
        'invoice' => $maxSuffix('SELECT invoice_no FROM student_fees', $invSeq), 'receipt' => $maxSuffix('SELECT receipt_no FROM payments', $rcptSeq),
        'refund' => $maxSuffix('SELECT refund_no FROM refunds', count($refundRows)), 'expense' => count($expRows),
    ];
    foreach ($seqs as $name => $n) {
        db_exec('INSERT INTO sequences (name, current_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE current_value = VALUES(current_value)', [$name, $n]);
    }
};
